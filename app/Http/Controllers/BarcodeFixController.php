<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Fix made-up SKUs (Sarah 2026-10-08, root cause #2 in the inventory
 * clean-up): sealed listings whose SKU is not the real barcode can't be
 * scanned, matched to distributor prices, or matched to their duplicate.
 *
 * For each one this page shows what it could really be:
 *   - the same album already in the ERP under its real barcode -> Merge
 *     (ProductMergeController::mergeSelected, keeps stock + sales, undoable),
 *   - distributor listings of the same album + format -> Use this barcode,
 *   - or type/scan the barcode by hand.
 * Barcode saves are snapshotted ('set-real-barcode', undo at Admin Action
 * History) and pushed to nivessa.com. Owner-only.
 */
class BarcodeFixController extends Controller
{
    const PAGE = 25;

    protected function isOwner()
    {
        $u = auth()->user();
        return $u && strtolower(trim((string) $u->first_name)) === 'jonathan'
            && strtolower(trim((string) $u->last_name)) === 'hedvat';
    }

    protected function skippedPath(int $biz): string
    {
        return storage_path('app/barcode-fix-skipped-' . $biz . '.json');
    }

    protected function skipped(int $biz): array
    {
        $j = json_decode((string) @file_get_contents($this->skippedPath($biz)), true);
        return is_array($j) ? $j : [];
    }

    public function index()
    {
        if (!$this->isOwner()) abort(403);
        return view('products.fix_barcodes');
    }

    /** Active sealed listings with a made-up SKU, in-stock first, with candidates. */
    public function data(Request $request)
    {
        if (!$this->isOwner()) abort(403);
        @set_time_limit(120);
        @ini_set('memory_limit', '768M');
        $biz = (int) $request->session()->get('user.business_id');
        $offset = max(0, (int) $request->query('offset', 0));
        $skipped = $this->skipped($biz);
        $locNames = \DB::table('business_locations')->where('business_id', $biz)->pluck('name', 'id')->all();

        $base = \DB::table('products as p')
            ->join('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.business_id', $biz)->where('p.is_inactive', 0)
            ->whereRaw("LOWER(c.name) LIKE '%sealed%'")
            ->whereRaw("REPLACE(REPLACE(COALESCE(p.sku,''),' ',''),'-','') NOT REGEXP '^[0-9]{8,14}$'");
        if ($skipped) $base->whereNotIn('p.id', array_map('intval', array_keys($skipped)));
        $total = (clone $base)->count();
        $inStock = (clone $base)->whereRaw('(select coalesce(sum(qty_available),0) from variation_location_details v where v.product_id = p.id) > 0')->count();

        $rows = $base
            ->select('p.id', 'p.name', 'p.artist', 'p.sku', 'c.name as cat',
                \DB::raw('(select coalesce(sum(qty_available),0) from variation_location_details v where v.product_id = p.id) as stock'),
                \DB::raw("(select group_concat(concat(v.location_id, ':', v.qty_available) separator '|') from variation_location_details v where v.product_id = p.id) as locs"),
                \DB::raw('(select max(sell_price_inc_tax) from variations va where va.product_id = p.id and va.deleted_at is null) as price'))
            ->orderByDesc('stock')->orderByDesc('p.id')
            ->offset($offset)->limit(self::PAGE)->get();

        $ica = app(\App\Services\InventoryCheckService::class);
        $split = function ($locs) use ($locNames) {
            $hw = 0; $pico = 0;
            foreach (array_filter(explode('|', (string) $locs)) as $part) {
                [$lid, $q] = array_pad(explode(':', $part, 2), 2, 0);
                $n = mb_strtolower((string) ($locNames[(int) $lid] ?? ''));
                if (strpos($n, 'hollywood') !== false) $hw += max(0, (float) $q);
                elseif (strpos($n, 'pico') !== false) $pico += max(0, (float) $q);
            }
            return [$hw, $pico];
        };

        // Real-barcode twins in the ERP: look up active barcode listings that
        // share a title word with this page's rows, then confirm with the same
        // album matcher the distributor prices use.
        $out = [];
        foreach ($rows as $r) {
            [$hw, $pico] = $split($r->locs);
            $fam = $ica->formatFamily($r->cat);
            $keys = $ica->albumKeys($r->artist, $r->name);
            $twins = [];
            $word = '';
            foreach ($keys as [$a, $t]) {
                foreach (explode(' ', $t) as $w) { if (mb_strlen($w) > mb_strlen($word)) $word = $w; }
            }
            if (mb_strlen($word) >= 3) {
                $cands = \DB::table('products as p')
                    ->join('categories as c', 'c.id', '=', 'p.category_id')
                    ->where('p.business_id', $biz)->where('p.is_inactive', 0)->where('p.id', '!=', $r->id)
                    ->whereRaw("REPLACE(REPLACE(COALESCE(p.sku,''),' ',''),'-','') REGEXP '^[0-9]{8,14}$'")
                    ->whereRaw('LOWER(p.name) LIKE ?', ['%' . $word . '%'])
                    ->select('p.id', 'p.name', 'p.artist', 'p.sku', 'c.name as cat',
                        \DB::raw("(select group_concat(concat(v.location_id, ':', v.qty_available) separator '|') from variation_location_details v where v.product_id = p.id) as locs"),
                        \DB::raw('(select max(sell_price_inc_tax) from variations va where va.product_id = p.id and va.deleted_at is null) as price'))
                    ->limit(200)->get();
                $mine = [];
                foreach ($keys as [$a, $t]) $mine[$t][] = $a;
                foreach ($cands as $c) {
                    if ($ica->formatFamily($c->cat) !== $fam) continue;
                    $hit = false;
                    foreach ($ica->albumKeys($c->artist, $c->name) as [$ca, $ct]) {
                        foreach ($mine[$ct] ?? [] as $a) { if ($ica->artistsCompatible($a, $ca)) { $hit = true; break 2; } }
                    }
                    if (!$hit) continue;
                    [$chw, $cpico] = $split($c->locs);
                    $twins[] = ['id' => (int) $c->id, 'name' => $c->name, 'sku' => $c->sku, 'cat' => $c->cat, 'hw' => $chw, 'pico' => $cpico, 'price' => round((float) $c->price, 2)];
                }
            }
            $twinUpcs = [];
            foreach ($twins as $t) $twinUpcs[$ica->upcKey($t['sku'])] = true;
            $dist = array_values(array_filter($ica->albumListings($biz, $r->artist, $r->name, $r->cat), function ($d) use ($twinUpcs, $ica) {
                return !isset($twinUpcs[$ica->upcKey($d['upc'])]);
            }));
            $out[] = [
                'id' => (int) $r->id, 'name' => $r->name, 'artist' => $r->artist, 'sku' => $r->sku, 'cat' => $r->cat,
                'hw' => $hw, 'pico' => $pico, 'price' => round((float) $r->price, 2),
                'twins' => array_slice($twins, 0, 6), 'distributors' => array_slice($dist, 0, 8),
            ];
        }
        return response()->json(['total' => $total, 'in_stock' => $inStock, 'offset' => $offset, 'per_page' => self::PAGE, 'rows' => $out]);
    }

    /** Save a real barcode as the SKU (product + its single variation). */
    public function save(Request $request, $id)
    {
        if (!$this->isOwner()) return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        $biz = (int) $request->session()->get('user.business_id');
        $upc = preg_replace('/\D+/', '', (string) $request->input('barcode'));
        if (strlen($upc) < 8 || strlen($upc) > 14) {
            return response()->json(['success' => false, 'msg' => 'That is not a barcode (needs 8 to 14 digits).']);
        }
        $p = \DB::table('products')->where('business_id', $biz)->where('id', (int) $id)->first();
        if (!$p) return response()->json(['success' => false, 'msg' => 'Product not found.']);

        // Same barcode already on another listing = it's a duplicate; merge instead.
        $ica = app(\App\Services\InventoryCheckService::class);
        $key = $ica->upcKey($upc);
        $other = \DB::table('products')->where('business_id', $biz)->where('id', '!=', $p->id)->where('is_inactive', 0)
            ->whereRaw("TRIM(LEADING '0' FROM REPLACE(REPLACE(sku,' ',''),'-','')) = ?", [$key])
            ->select('id', 'name')->first();
        if ($other) {
            return response()->json(['success' => false, 'twin' => ['id' => (int) $other->id, 'name' => $other->name],
                'msg' => 'Another listing already has this barcode: "' . $other->name . '". Merge into it instead.']);
        }

        $vars = \DB::table('variations')->where('product_id', $p->id)->whereNull('deleted_at')->select('id', 'sub_sku')->get();
        $ts = now()->format('Y-m-d_His') . '-' . $p->id;
        \DB::beginTransaction();
        try {
            \Storage::disk('local')->put("admin-snapshots/set-real-barcode-{$ts}.json", json_encode([
                'timestamp' => $ts, 'action' => 'set-real-barcode', 'user_id' => auth()->id(), 'business_id' => $biz,
                'source_name' => $p->name, 'target_name' => $p->sku . ' -> ' . $upc,
                'rows' => [['id' => (int) $p->id, 'old_sku' => $p->sku, 'new_sku' => $upc,
                    'variations' => $vars->map(function ($v) { return ['id' => (int) $v->id, 'old_sub_sku' => $v->sub_sku]; })->all()]],
            ], JSON_PRETTY_PRINT));
            \DB::table('products')->where('id', $p->id)->update(['sku' => $upc, 'updated_at' => now()]);
            // Single-variation listings scan by sub_sku, so it gets the barcode too.
            if (count($vars) === 1) \DB::table('variations')->where('id', $vars[0]->id)->update(['sub_sku' => $upc]);
            \DB::commit();
        } catch (\Throwable $e) {
            \DB::rollBack();
            \Log::error('set-real-barcode failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'msg' => 'Save failed, nothing was changed.']);
        }
        try { (new \App\Services\NivessaStockNotifier())->pushProductChanged([(int) $p->id]); } catch (\Throwable $e) {}
        return response()->json(['success' => true, 'msg' => 'Saved barcode ' . $upc . '.']);
    }

    /** Merge this listing into its real-barcode twin (stock + sales move over). */
    public function merge(Request $request, $id)
    {
        if (!$this->isOwner()) return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        $keep = (int) $request->input('keep_id');
        $sub = Request::create('/products/merge-selected', 'POST', ['product_ids' => [(int) $id, $keep], 'keep_id' => $keep]);
        $sub->setLaravelSession($request->session());
        $sub->setUserResolver($request->getUserResolver());
        return app(ProductMergeController::class)->mergeSelected($sub);
    }

    /** Hide a listing from this page (can't tell / not worth it). */
    public function skip(Request $request, $id)
    {
        if (!$this->isOwner()) return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        $biz = (int) $request->session()->get('user.business_id');
        $s = $this->skipped($biz);
        $s[(int) $id] = now()->toDateString();
        @file_put_contents($this->skippedPath($biz), json_encode($s));
        return response()->json(['success' => true]);
    }
}
