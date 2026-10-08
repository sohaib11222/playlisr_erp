<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Old setup listings cleanup (Sarah 2026-10-07).
 *
 * The Oct 2024 catalog load left listings with a made-up SKU (a short
 * in-house number, no barcode) and a selling price that is exactly cost
 * x 1.25 — UltimatePOS's default 25% markup applied to whatever was typed as
 * cost. Sarah: those with no sales history can go. They're retired
 * (is_inactive = 1), never hard-deleted, with a snapshot + undo.
 *
 * Only listings with NO stock are retired here. The ones still showing stock
 * may be real copies, so they're listed for a shelf check instead.
 */
class LegacyListingController extends Controller
{
    protected function isOwner()
    {
        $u = auth()->user();
        return $u
            && strtolower(trim((string) $u->first_name)) === 'jonathan'
            && strtolower(trim((string) $u->last_name)) === 'hedvat';
    }

    /** Active, single-variation, made-up SKU, price = cost x 1.25, never sold. */
    protected function candidates($business_id)
    {
        return \DB::table('products as p')
            ->join('variations as v', function ($j) {
                $j->on('v.product_id', '=', 'p.id')->whereNull('v.deleted_at');
            })
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.created_by')
            ->where('p.business_id', $business_id)
            ->where('p.is_inactive', 0)
            // The 2024 setup only; a recent listing that happens to be priced
            // at cost + 25% is a real one.
            ->where('p.created_at', '<', '2025-01-01 00:00:00')
            ->where('v.dpp_inc_tax', '>', 0)
            ->whereRaw('ABS(v.sell_price_inc_tax - v.dpp_inc_tax * 1.25) < 0.011')
            ->whereRaw("p.sku REGEXP '^[0-9]{1,8}$'")
            ->whereRaw('(select count(*) from variations v3 where v3.product_id = p.id and v3.deleted_at is null) = 1')
            ->whereRaw('not exists (select 1 from transaction_sell_lines tsl where tsl.product_id = p.id)')
            ->select(
                'p.id', 'p.name', 'p.sku', 'p.created_at', 'c.name as category',
                'v.dpp_inc_tax as cost', 'v.sell_price_inc_tax as price',
                \DB::raw("CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) as created_by_name"),
                \DB::raw('(select coalesce(sum(vld.qty_available),0) from variation_location_details vld where vld.product_id = p.id) as stock')
            )
            ->orderBy('p.id');
    }

    /** Order-insensitive name key: "Diamond Eyes / Deftones" == "DEFTONES - DIAMOND EYES". */
    protected function nameKey($name)
    {
        $s = mb_strtolower(html_entity_decode((string) $name, ENT_QUOTES));
        $s = preg_replace('/\([^)]*\)|\[[^\]]*\]/u', ' ', $s);
        $s = str_replace('&', ' and ', $s);
        $words = preg_split('/[^a-z0-9]+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
        $drop = ['the' => 1, 'lp' => 1, 'vinyl' => 1, 'cd' => 1, 'sealed' => 1, 'x' => 1, 'and' => 1];
        $words = array_values(array_filter($words, function ($w) use ($drop) { return !isset($drop[$w]); }));
        sort($words);
        return implode(' ', $words);
    }

    protected function formatFamily($cat)
    {
        $c = mb_strtolower((string) $cat);
        if (strpos($c, 'vinyl') !== false || strpos($c, '45') !== false) return 'lp';
        if (strpos($c, 'cd') !== false) return 'cd';
        if (strpos($c, 'cassette') !== false) return 'cassette';
        return $c;
    }

    /**
     * Other active listings of the same album + format, keyed by
     * nameKey|format => [{id,name,sku,price,cost,stock}], excluding $skipIds.
     */
    protected function twinIndex($business_id, array $wantKeys, array $skipIds)
    {
        $idx = [];
        \DB::table('products as p')
            ->join('variations as v', function ($j) { $j->on('v.product_id', '=', 'p.id')->whereNull('v.deleted_at'); })
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.business_id', $business_id)->where('p.is_inactive', 0)
            ->select('p.id', 'p.name', 'p.sku', 'c.name as category', 'v.sell_price_inc_tax as price', 'v.dpp_inc_tax as cost')
            ->orderBy('p.id')
            ->chunk(10000, function ($rows) use (&$idx, $wantKeys, $skipIds) {
                foreach ($rows as $r) {
                    if (isset($skipIds[(int) $r->id])) continue;
                    $k = $this->nameKey($r->name) . '|' . $this->formatFamily($r->category);
                    if (!isset($wantKeys[$k])) continue;
                    $idx[$k][] = ['id' => (int) $r->id, 'name' => $r->name, 'sku' => $r->sku, 'price' => (float) $r->price, 'cost' => (float) $r->cost];
                }
            });
        return $idx;
    }

    public function index()
    {
        if (!$this->isOwner()) { abort(403, 'Owner-only.'); }
        return view('products.legacy_cleanup');
    }

    public function scan(Request $request)
    {
        @set_time_limit(0);
        if (!$this->isOwner()) {
            return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        }
        $business_id = $request->session()->get('user.business_id');
        $retire = []; $check = [];
        $nRetire = 0; $nCheck = 0; $checkUnits = 0; $withTwin = 0;
        $all = $this->candidates($business_id)->get();
        $wantKeys = []; $skip = [];
        foreach ($all as $r) {
            $wantKeys[$this->nameKey($r->name) . '|' . $this->formatFamily($r->category)] = true;
            $skip[(int) $r->id] = true;
        }
        $twins = $this->twinIndex($business_id, $wantKeys, $skip);
        foreach ($all as $r) {
            $tw = $twins[$this->nameKey($r->name) . '|' . $this->formatFamily($r->category)] ?? [];
            if ($tw) { $withTwin++; }
            $row = [
                'id' => (int) $r->id, 'name' => $r->name, 'sku' => $r->sku,
                'category' => $r->category, 'cost' => (float) $r->cost, 'price' => (float) $r->price,
                'stock' => (float) $r->stock, 'created' => substr((string) $r->created_at, 0, 10),
                'by' => trim((string) $r->created_by_name),
                'twins' => array_slice($tw, 0, 3),
            ];
            if ((float) $r->stock > 0) {
                $nCheck++; $checkUnits += (float) $r->stock;
                if (count($check) < 500) { $check[] = $row; }
            } else {
                $nRetire++;
                if (count($retire) < 500) { $retire[] = $row; }
            }
        }
        return response()->json([
            'success' => true,
            'retire_count' => $nRetire, 'retire' => $retire, 'with_twin' => $withTwin, 'total' => count($all),
            'check_count' => $nCheck, 'check_units' => $checkUnits, 'check' => $check,
        ]);
    }

    public function apply(Request $request)
    {
        @set_time_limit(0);
        if (!$this->isOwner()) {
            return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        }
        $business_id = $request->session()->get('user.business_id');
        // Re-run the same rules at apply time; only no-stock rows are retired.
        $ids = [];
        foreach ($this->candidates($business_id)->get() as $r) {
            if ((float) $r->stock <= 0) { $ids[] = (int) $r->id; }
        }
        if (empty($ids)) {
            return response()->json(['success' => true, 'retired' => 0]);
        }

        $timestamp = now()->format('Y-m-d_His');
        \DB::beginTransaction();
        try {
            $done = 0;
            foreach (array_chunk($ids, 1000) as $chunk) {
                $done += \DB::table('products')->whereIn('id', $chunk)->where('is_inactive', 0)
                    ->update(['is_inactive' => 1, 'updated_at' => now()]);
            }
            \Storage::disk('local')->put(
                "admin-snapshots/legacy-listing-retire-{$timestamp}.json",
                json_encode([
                    'timestamp' => $timestamp,
                    'action' => 'legacy-listing-retire',
                    'user_id' => auth()->id(),
                    'business_id' => $business_id,
                    'source_name' => $done . ' old setup listing(s) with cost x1.25 pricing, made-up SKU, no sales, no stock',
                    'target_name' => 'retired (is_inactive = 1)',
                    'rows' => array_map(function ($id) { return ['id' => $id]; }, $ids),
                ], JSON_PRETTY_PRINT)
            );
            \DB::commit();
        } catch (\Throwable $e) {
            \DB::rollBack();
            \Log::emergency('legacy-listing-retire failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'msg' => 'Retire failed, nothing changed.']);
        }

        // These have no stock, so the site already shows them out of stock;
        // the nightly sync unpublishes inactive products. Push small batches now.
        if (count($ids) <= 200) {
            try { (new \App\Services\NivessaStockNotifier())->push($ids); } catch (\Throwable $e) {}
        }
        return response()->json(['success' => true, 'retired' => $done]);
    }
}
