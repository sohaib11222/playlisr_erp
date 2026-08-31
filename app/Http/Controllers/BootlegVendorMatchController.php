<?php

namespace App\Http\Controllers;

use App\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Matches Adam Mayes's bootleg-vendor catalog (a 2024-06-23 price list Sarah
 * uploaded — 402 titles across Rock Imports / Hip Hop-Pop / 7" / Cassettes /
 * Slipmats — every music title sampled was confirmed on Discogs as an
 * "Unofficial Release": unauthorized live/demo/rarity comps or unauthorized
 * colored-vinyl represses of already-released albums) against real ERP
 * inventory by fuzzy artist+title word overlap, then lets Sarah check off
 * which matched products are ACTUALLY the bootleg copy before zeroing their
 * stock. Nothing is written until she checks boxes and clicks Apply — the
 * matcher only ever produces candidates to review.
 *
 * One confirmed exception in the catalog: the C418 "Minecraft Volume Alpha"
 * cassette is a legitimate 2025 Ghostly International official release, not
 * a bootleg — still shown as a candidate (matching is name-based, not a
 * verdict), so don't check it unless her copy is actually Adam's.
 *
 * Snapshot + undo via /admin/admin-action-history ('zero-bootleg-stock'),
 * same variation_location_details {id, qty_available} row schema as
 * ZeroStockRulesController's 'zero-retired-stock'.
 */
class BootlegVendorMatchController extends Controller
{
    const CATALOG_FILE = 'adam_mayes_catalog_2024_06_23.json';

    // Vinyl-color/format/packaging jargon and generic filler — stripped so
    // it can't itself count as a "shared word" between two unrelated titles.
    const STOPWORDS = [
        'the', 'a', 'an', 'and', 'or', 'of', 'with', 'w', 'from', 'in', 'on', 'at', 'to', 'for', 'by',
        'vinyl', 'colored', 'color', 'col', 'wax', 'lp', 'ep', 'cd', 'cassette', 'rpm',
        'album', 'version', 'edition', 'reissue', 'repress', 'import', 'gatefold', 'sleeve',
        'panel', 'card', 'jcard', 'insert', 'poster', 'glasses', 'box', 'set', 'disc', 'side',
        'first', 'second', 'third', 'self', 'titled', 's', 'og', 'art', 'classic', 'full',
        'special', 'limited', 'ltd', 'deluxe', 'remastered', 'mono', 'stereo', 'pic',
        'picture', 'splatter', 'translucent', 'clear', 'opaque',
    ];

    protected function catalog()
    {
        $path = app_path('Services/data/' . self::CATALOG_FILE);
        if (!file_exists($path)) {
            return [];
        }
        return json_decode(file_get_contents($path), true) ?: [];
    }

    protected function tokens($text)
    {
        $text = strtolower((string) $text);
        $text = preg_replace('/\(.*?\)/', ' ', $text); // drop parenthetical notes
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);
        $words = preg_split('/\s+/', trim($text));
        $words = array_filter($words, function ($w) {
            return $w !== '' && strlen($w) > 1 && !in_array($w, self::STOPWORDS, true);
        });
        return array_values(array_unique($words));
    }

    public function index()
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $businessId = request()->session()->get('user.business_id');
        $catalog = $this->catalog();

        $products = Product::where('business_id', $businessId)
            ->where('enable_stock', 1)
            ->get(['id', 'name', 'artist']);

        // Inverted index: token -> [product_id, ...]. Lets us only score
        // products that share at least one word with a given catalog title
        // instead of comparing every catalog entry against every product.
        $tokenIndex = [];
        $productTokens = [];
        foreach ($products as $p) {
            $toks = $this->tokens($p->name . ' ' . ($p->artist ?? ''));
            $productTokens[$p->id] = $toks;
            foreach ($toks as $t) {
                $tokenIndex[$t][] = $p->id;
            }
        }

        $byProduct = [];
        foreach ($catalog as $entry) {
            $catTokens = $this->tokens($entry['title']);
            if (count($catTokens) < 2) { continue; }

            $candidateIds = [];
            foreach ($catTokens as $t) {
                if (!empty($tokenIndex[$t])) {
                    $candidateIds = array_merge($candidateIds, $tokenIndex[$t]);
                }
            }
            $candidateIds = array_unique($candidateIds);

            // Require at least 3 shared significant words, or 2 when the
            // catalog title itself only has 2-3 tokens (a short title can't
            // produce more overlap even on a genuine match).
            $minNeeded = count($catTokens) <= 3 ? 2 : 3;

            foreach ($candidateIds as $pid) {
                $overlap = count(array_intersect($catTokens, $productTokens[$pid]));
                if ($overlap < $minNeeded) { continue; }
                if (!isset($byProduct[$pid]) || $overlap > $byProduct[$pid]['overlap']) {
                    $byProduct[$pid] = [
                        'catalog_title'          => $entry['title'],
                        'section'                => $entry['section'],
                        'likely_bootleg_keyword' => $entry['likely_bootleg_keyword'],
                        'overlap'                => $overlap,
                    ];
                }
            }
        }

        $productNames = $products->pluck('name', 'id');

        $stockByProduct = empty($byProduct) ? collect() : DB::table('variation_location_details as vld')
            ->join('variations as v', 'v.id', '=', 'vld.variation_id')
            ->whereIn('v.product_id', array_keys($byProduct))
            ->select('v.product_id', DB::raw('SUM(vld.qty_available) as stock'))
            ->groupBy('v.product_id')
            ->pluck('stock', 'product_id');

        $rows = [];
        foreach ($byProduct as $pid => $m) {
            $rows[] = [
                'product_id'             => $pid,
                'product_name'           => $productNames[$pid] ?? '(unknown)',
                'catalog_title'          => $m['catalog_title'],
                'section'                => $m['section'],
                'likely_bootleg_keyword' => $m['likely_bootleg_keyword'],
                'stock'                  => (float) ($stockByProduct[$pid] ?? 0),
            ];
        }

        usort($rows, function ($a, $b) { return strcmp($a['product_name'], $b['product_name']); });

        return view('admin.bootleg_vendor_match', [
            'rows'         => $rows,
            'catalogCount' => count($catalog),
        ]);
    }

    public function apply(Request $request)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }

        $businessId = $request->session()->get('user.business_id');
        $productIds = array_filter(array_map('intval', $request->input('product_ids', [])));

        if (empty($productIds)) {
            return redirect('/admin/bootleg-vendor-match')
                ->with('status', ['success' => 0, 'msg' => 'No products selected.']);
        }

        // Re-scope to this business so a crafted id can't slip through.
        $validIds = Product::where('business_id', $businessId)->whereIn('id', $productIds)->pluck('id')->all();

        $rows = DB::table('variation_location_details as vld')
            ->join('variations as v', 'v.id', '=', 'vld.variation_id')
            ->whereIn('v.product_id', $validIds)
            ->where('vld.qty_available', '>', 0)
            ->select('vld.id', 'vld.qty_available')
            ->get();

        if ($rows->isEmpty()) {
            return redirect('/admin/bootleg-vendor-match')
                ->with('status', ['success' => 0, 'msg' => 'Nothing to zero — selected products already have 0 stock.']);
        }

        $snapshotRows = $rows->map(function ($r) { return ['id' => $r->id, 'qty_available' => $r->qty_available]; })->all();

        $zeroed = 0;
        foreach ($rows->pluck('id')->chunk(500) as $chunk) {
            $zeroed += DB::table('variation_location_details')->whereIn('id', $chunk->all())->update(['qty_available' => 0, 'updated_at' => now()]);
        }

        $timestamp = now()->format('Y-m-d_His');
        $snapshotKey = "zero-bootleg-stock-{$timestamp}";
        Storage::disk('local')->put(
            "admin-snapshots/{$snapshotKey}.json",
            json_encode([
                'timestamp'   => now()->toDateTimeString(),
                'action'      => 'zero-bootleg-stock',
                'business_id' => $businessId,
                'rows'        => $snapshotRows,
            ], JSON_PRETTY_PRINT)
        );

        return redirect('/admin/bootleg-vendor-match')
            ->with('status', ['success' => 1, 'msg' => "Zeroed {$zeroed} stock row(s) across " . count($validIds) . " product(s). Snapshot {$snapshotKey} — undo at Admin Action History."]);
    }
}
