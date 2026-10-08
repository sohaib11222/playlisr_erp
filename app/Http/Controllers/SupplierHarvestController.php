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
        return $response->header('Access-Control-Allow-Origin', self::ORIGIN)
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
        $biz = $this->businessForToken($request->query('token'));
        if (!$biz) return $this->cors(response()->json(['success' => false, 'msg' => 'Bad token'], 403));
        if (!in_array($supplier, ['alliance'], true)) {
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
                ->select('p.sku', 'p.name', 'p.artist', 'c.name as cat')
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
            $fam = $p ? $ica->formatFamily($p->cat) : null;
            $clean[] = [
                'artist' => $p && $p->artist && !preg_match('/^(n\/?a|-)$/i', $p->artist) ? $p->artist : null,
                'title' => $p ? $p->name : null,
                'format' => $fam === 'lp' ? 'LP' : ($fam === 'cd' ? 'CD' : ($fam === 'cassette' ? 'Cassette' : null)),
                'cost' => round($cost, 2),
                'qty' => isset($r['qty']) ? (int) $r['qty'] : null,
                'upc' => $k,
                'url' => self::ORIGIN . '/search?q=' . $k,
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
}
