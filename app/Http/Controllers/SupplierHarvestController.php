<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Browser-run supplier price pull (Sarah 2026-10-08).
 *
 * Alliance's WebAMI login blocks server logins, but when Sarah is logged in
 * on webami.aent.com the site itself prices any barcode batch via
 * POST /ajax/priceavail. A bookmarklet clicked on WebAMI:
 *   1) GETs our barcodes from upcs() (CORS for webami.aent.com, token auth),
 *   2) asks WebAMI for their prices 50 at a time,
 *   3) POSTs the results to upload(), which merges them into the Alliance
 *      price feed the Distributor prices column reads.
 * The token is a per-business secret shown only on the ICA page (owner).
 */
class SupplierHarvestController extends Controller
{
    const ORIGIN = 'https://webami.aent.com';

    protected function tokenPath(int $business_id): string
    {
        return storage_path('app/supplier-harvest-token-' . $business_id . '.txt');
    }

    /** Current token for a business (created on first ask). */
    public static function tokenFor(int $business_id): string
    {
        $p = storage_path('app/supplier-harvest-token-' . $business_id . '.txt');
        $t = is_file($p) ? trim((string) @file_get_contents($p)) : '';
        if (strlen($t) < 32) {
            $t = bin2hex(random_bytes(24));
            @file_put_contents($p, $t);
        }
        return $t;
    }

    /** business_id for a token, or 0. */
    protected function businessForToken(?string $token): int
    {
        $token = (string) $token;
        if (strlen($token) < 32) return 0;
        foreach (glob(storage_path('app/supplier-harvest-token-*.txt')) ?: [] as $f) {
            if (hash_equals(trim((string) @file_get_contents($f)), $token)
                && preg_match('/token-(\d+)\.txt$/', $f, $m)) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    protected function cors($response)
    {
        // Each supplier portal the pull runs from.
        $allowed = [self::ORIGIN, 'https://b2b.secretlydistribution.com', 'https://b2b.redeyeworldwide.com', 'https://newb2b.monostereo1stop.com'];
        $origin = request()->headers->get('Origin');
        return $response->header('Access-Control-Allow-Origin', in_array($origin, $allowed, true) ? $origin : self::ORIGIN)
            ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->header('Access-Control-Allow-Headers', 'Content-Type')
            ->header('Vary', 'Origin');
    }

    public function preflight()
    {
        return $this->cors(response('', 204));
    }

    /** Every new (non-used) product barcode we carry, for the supplier to price. */
    public function upcs(Request $request)
    {
        $biz = $this->businessForToken($request->query('token'));
        if (!$biz) return $this->cors(response()->json(['success' => false, 'msg' => 'Bad token'], 403));
        $seen = [];
        \DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.business_id', $biz)->where('p.is_inactive', 0)
            ->whereRaw("p.sku REGEXP '^[0-9 -]{11,16}$'")
            ->where(function ($q) { $q->whereNull('c.name')->orWhereRaw("LOWER(c.name) NOT LIKE '%used%'"); })
            ->orderByDesc('p.id')
            ->select('p.sku')
            ->chunk(5000, function ($rows) use (&$seen) {
                foreach ($rows as $r) {
                    $d = preg_replace('/\D+/', '', (string) $r->sku);
                    if (strlen($d) < 11 || strlen($d) > 14) continue;
                    // WebAMI keys 12-digit UPCs; pad 11-digit (leading zero lost) back to 12.
                    if (strlen($d) === 11) $d = '0' . $d;
                    if (strlen($d) === 13 && $d[0] === '0') $d = substr($d, 1);
                    $seen[$d] = true;
                }
            });
        return $this->cors(response()->json(['success' => true, 'upcs' => array_keys($seen)]));
    }

    /** Merge priced rows [{upc, cost, qty}] into the supplier feed. */
    public function upload(Request $request, $supplier)
    {
        try {
            return $this->doUpload($request, $supplier);
        } catch (\Throwable $e) {
            \Log::error('supplier-harvest upload failed: ' . $e->getMessage());
            return $this->cors(response()->json(['success' => false, 'msg' => 'Server error: ' . $e->getMessage()], 500));
        }
    }

    protected function doUpload(Request $request, $supplier)
    {
        $biz = $this->businessForToken($request->query('token'));
        if (!$biz) return $this->cors(response()->json(['success' => false, 'msg' => 'Bad token'], 403));
        if (!in_array($supplier, ['alliance', 'secretly', 'redeye', 'monostereo'], true)) {
            return $this->cors(response()->json(['success' => false, 'msg' => 'Unknown supplier'], 400));
        }
        $rows = json_decode((string) $request->getContent(), true);
        if (!is_array($rows)) return $this->cors(response()->json(['success' => false, 'msg' => 'Bad body'], 400));

        // Name + format from our own product, so the column/ICA show something
        // readable and the format check works.
        $norm = function ($u) { return ltrim((string) preg_replace('/\D+/', '', (string) $u), '0'); };
        $want = [];
        foreach ($rows as $r) { if (is_array($r) && !empty($r['upc'])) $want[$norm($r['upc'])] = $r; }
        $info = [];
        if ($want) {
            \DB::table('products as p')->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->where('p.business_id', $biz)->whereRaw("p.sku REGEXP '^[0-9 -]{11,16}$'")
                ->select('p.id', 'p.sku', 'p.name', 'p.artist', 'c.name as cat')
                ->orderBy('p.id')
                ->chunk(5000, function ($ps) use (&$info, $want, $norm) {
                    foreach ($ps as $p) {
                        $k = $norm($p->sku);
                        if (isset($want[$k]) && !isset($info[$k])) $info[$k] = $p;
                    }
                });
        }
        $ica = app(\App\Services\InventoryCheckService::class);
        $clean = [];
        foreach ($want as $k => $r) {
            $cost = (float) preg_replace('/[^\d.]/', '', (string) ($r['cost'] ?? ''));
            if ($cost <= 0) continue;
            $p = $info[$k] ?? null;
            // The supplier's own artist/title/format when the pull sends them
            // (Secretly's catalog does); otherwise ours.
            $fam = !empty($r['format']) ? $ica->formatFamily((string) $r['format']) : ($p ? $ica->formatFamily($p->cat) : null);
            $clean[] = [
                'artist' => !empty($r['artist']) ? (string) $r['artist'] : ($p && $p->artist && !preg_match('/^(n\/?a|-)$/i', $p->artist) ? $p->artist : null),
                'title' => !empty($r['title']) ? (string) $r['title'] : ($p ? $p->name : null),
                'format' => $fam === 'lp' ? 'LP' : ($fam === 'cd' ? 'CD' : ($fam === 'cassette' ? 'Cassette' : null)),
                'cost' => round($cost, 2),
                'qty' => isset($r['qty']) ? (int) $r['qty'] : null,
                'upc' => $k,
                'url' => $supplier === 'alliance' ? self::ORIGIN . '/search?q=' . $k : (!empty($r['url']) ? (string) $r['url'] : null),
                'checked_at' => date('c'),
            ];
        }

        $existing = $ica->loadSupplierFeed($biz, $supplier);
        $byKey = [];
        foreach ((array) ($existing['rows'] ?? []) as $r) {
            $u = $norm($r['upc'] ?? '');
            $byKey[$u !== '' ? 'upc:' . $u : 'nt:' . mb_strtolower(($r['artist'] ?? '') . '|' . ($r['title'] ?? '') . '|' . ($r['format'] ?? ''))] = $r;
        }
        foreach ($clean as $r) { $byKey['upc:' . $r['upc']] = $r; }
        $ica->saveSupplierFeed($biz, $supplier, [
            'business_id' => $biz,
            'supplier_key' => $supplier,
            'source_file' => 'WebAMI browser pull ' . now()->format('Y-m-d H:i'),
            'imported_at' => now()->toIso8601String(),
            'imported_by' => 'webami-bookmarklet',
            'rows' => array_values($byKey),
        ]);
        return $this->cors(response()->json(['success' => true, 'saved' => count($clean), 'total' => count($byKey)]));
    }

    /**
     * Redeye product-page ids for the barcodes we carry, taken from the last
     * Redeye feed (their catalog lists no prices; each page has "Your cost").
     * The browser pull re-reads those pages for today's price.
     */
    public function redeyeIds(Request $request)
    {
        $biz = $this->businessForToken($request->query('token'));
        if (!$biz) return $this->cors(response()->json(['success' => false, 'msg' => 'Bad token'], 403));
        $norm = function ($u) { return ltrim((string) preg_replace('/\D+/', '', (string) $u), '0'); };
        $ours = [];
        \DB::table('products')->where('business_id', $biz)->where('is_inactive', 0)
            ->whereRaw("sku REGEXP '^[0-9 -]{11,16}$'")->orderBy('id')->select('id', 'sku')
            ->chunk(10000, function ($rows) use (&$ours, $norm) { foreach ($rows as $r) { $ours[$norm($r->sku)] = true; } });
        $feed = app(\App\Services\InventoryCheckService::class)->loadSupplierFeed($biz, 'redeye');
        $ids = [];
        foreach ((array) ($feed['rows'] ?? []) as $r) {
            if (!isset($ours[$norm($r['upc'] ?? '')])) continue;
            if (preg_match('#/products/details/(\d+)#', (string) ($r['url'] ?? ''), $m)) { $ids[$m[1]] = true; }
        }
        return $this->cors(response()->json(['success' => true, 'ids' => array_map('strval', array_keys($ids))]));
    }
}
