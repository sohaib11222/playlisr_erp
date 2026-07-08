<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Owner-only tool to merge duplicate products into the one you keep — either
 * one pair at a time, or the whole catalog in one sweep.
 *
 * Merging moves ALL history off each duplicate onto the survivor so nothing
 * is lost, and the survivor shows the COMBINED totals:
 *   - transaction_sell_lines      (Units Sold  -> summed)
 *   - purchase_lines              (cost history)
 *   - stock_adjustment_lines      (manual stock changes)
 *   - variation_location_details  (on-hand stock -> summed per location)
 * then deactivates the duplicate (is_inactive) so it drops out of POS and the
 * product list. The duplicate row is kept (Product has no SoftDeletes, so it
 * is never hard-deleted) so the merge can be reversed.
 *
 * Every write is snapshotted to storage/app/admin-snapshots BEFORE it happens
 * and is reversible at /admin/admin-action-history — same pattern as the
 * category merge (TaxonomyController@merge).
 *
 *   Single merge  -> one "merge-products" snapshot.
 *   Bulk sweep    -> one "merge-products-bulk" snapshot per batch (a list of
 *                    individual merges), reversed as a batch.
 *
 * Duplicates are matched by SKU with leading zeros ignored (so 0197190162899
 * and 197190162899 are the same barcode). Single-variation products only
 * (all vinyl / CDs); groups with any multi-variation product are skipped and
 * reported for manual review.
 */
class ProductMergeController extends Controller
{
    /** Owner-only, mirroring the category-merge gate (Jonathan Hedvat). */
    protected function isOwner()
    {
        $u = auth()->user();
        return $u
            && strtolower(trim((string) $u->first_name)) === 'jonathan'
            && strtolower(trim((string) $u->last_name)) === 'hedvat';
    }

    /**
     * Barcode key with leading zeros ignored — or '' if the SKU isn't a real
     * barcode. Only all-digit SKUs of 8+ chars (UPC-E/EAN-8 up to EAN-13)
     * count; junk placeholders like "3", "003", "0004" are rejected so they
     * never anchor a duplicate group. Returning '' makes the scan skip it.
     */
    protected function skuKey($sku)
    {
        $s = trim((string) $sku);
        if ($s === '' || !ctype_digit($s) || strlen($s) < 8) {
            return '';
        }
        $stripped = ltrim($s, '0');
        return $stripped === '' ? $s : $stripped;
    }

    /**
     * Title signature for duplicate matching: lowercased, accents flattened,
     * punctuation dropped, words sorted. Word-order-insensitive so an ERP
     * "Artist / Title" and its "Title / Artist" twin collapse to the same
     * signature — but genuinely different titles never will. Two products are
     * only treated as duplicates when their barcode AND this signature match,
     * so unrelated records that happen to share a junk SKU (e.g. "555") are
     * NOT merged.
     */
    protected function nameSig($name)
    {
        $n = mb_strtolower(trim((string) $name));
        if ($n === '') { return ''; }
        $n = strtr($n, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss', 'æ' => 'ae',
        ]);
        $n = preg_replace('/[^a-z0-9]+/', ' ', $n);
        $words = array_values(array_filter(explode(' ', trim($n)), function ($w) { return $w !== ''; }));
        sort($words);
        return implode(' ', $words);
    }

    public function index()
    {
        if (!$this->isOwner()) {
            abort(403, 'Product merge is owner-only.');
        }
        return view('products.merge');
    }

    /**
     * Resolve a product by SKU or numeric id within the business, plus its
     * (single) live variation rows. Returns [product, variationsCollection].
     */
    protected function resolveProduct($ref, $business_id)
    {
        $ref = trim((string) $ref);
        if ($ref === '') {
            return [null, null];
        }

        $base = \DB::table('products')->where('business_id', $business_id);
        $product = (clone $base)->where('sku', $ref)->first();
        if (!$product && ctype_digit($ref)) {
            $product = (clone $base)->where('id', (int) $ref)->first();
        }
        if (!$product) {
            return [null, null];
        }

        $variations = \DB::table('variations')
            ->where('product_id', $product->id)
            ->whereNull('deleted_at')
            ->get();

        return [$product, $variations];
    }

    /** Units sold (final sells) + on-hand stock for one product. */
    protected function stats($product_id, $business_id)
    {
        $unitsSold = (float) \DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('tsl.product_id', $product_id)
            ->sum('tsl.quantity');

        $stock = (float) \DB::table('variation_location_details')
            ->where('product_id', $product_id)
            ->sum('qty_available');

        return ['units_sold' => $unitsSold, 'current_stock' => $stock];
    }

    // ================= SINGLE-PAIR FLOW =================

    protected function validatePair(Request $request, $business_id)
    {
        $keepRef  = $request->input('keep');
        $mergeRef = $request->input('merge');

        [$target, $targetVars] = $this->resolveProduct($keepRef, $business_id);
        [$source, $sourceVars] = $this->resolveProduct($mergeRef, $business_id);

        if (!$target) {
            return ['error' => 'Could not find the product to keep (SKU or id "' . e($keepRef) . '").'];
        }
        if (!$source) {
            return ['error' => 'Could not find the duplicate to merge (SKU or id "' . e($mergeRef) . '").'];
        }
        if ((int) $source->id === (int) $target->id) {
            return ['error' => 'Those are the same product — pick two different rows.'];
        }
        if (count($targetVars) !== 1 || count($sourceVars) !== 1) {
            return ['error' => 'This tool merges single-variation products only. One of these has multiple variations — merge it manually.'];
        }

        return [
            'source' => $source,
            'target' => $target,
            'source_variation_id' => (int) $sourceVars[0]->id,
            'target_variation_id' => (int) $targetVars[0]->id,
            'target_product_variation_id' => (int) $targetVars[0]->product_variation_id,
        ];
    }

    public function preview(Request $request)
    {
        if (!$this->isOwner()) {
            return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        }
        $business_id = $request->session()->get('user.business_id');
        $pair = $this->validatePair($request, $business_id);
        if (isset($pair['error'])) {
            return response()->json(['success' => false, 'msg' => $pair['error']]);
        }

        $source = $pair['source'];
        $target = $pair['target'];
        $s = $this->stats($source->id, $business_id);
        $t = $this->stats($target->id, $business_id);

        return response()->json([
            'success' => true,
            'source' => ['id' => (int) $source->id, 'name' => $source->name, 'sku' => $source->sku, 'units_sold' => $s['units_sold'], 'current_stock' => $s['current_stock']],
            'target' => ['id' => (int) $target->id, 'name' => $target->name, 'sku' => $target->sku, 'units_sold' => $t['units_sold'], 'current_stock' => $t['current_stock']],
            'after' => ['units_sold' => $s['units_sold'] + $t['units_sold'], 'current_stock' => $s['current_stock'] + $t['current_stock']],
            'moves' => [
                'sell_lines' => \DB::table('transaction_sell_lines')->where('product_id', $source->id)->count(),
                'purchase_lines' => \DB::table('purchase_lines')->where('product_id', $source->id)->count(),
            ],
        ]);
    }

    public function merge(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        if (!$this->isOwner()) {
            return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        }
        $business_id = $request->session()->get('user.business_id');
        $pair = $this->validatePair($request, $business_id);
        if (isset($pair['error'])) {
            return response()->json(['success' => false, 'msg' => $pair['error']]);
        }

        $source = $pair['source'];
        $target = $pair['target'];
        $timestamp = now()->format('Y-m-d_His');
        $snapshotKey = "merge-products-{$timestamp}";

        \DB::beginTransaction();
        try {
            $payload = $this->performMerge(
                (int) $source->id, (int) $target->id,
                $pair['source_variation_id'], $pair['target_variation_id'], $pair['target_product_variation_id'],
                (int) $source->is_inactive, (int) $source->not_for_selling,
                $source->name, $target->name, $business_id
            );

            \Storage::disk('local')->put(
                "admin-snapshots/{$snapshotKey}.json",
                json_encode(array_merge([
                    'timestamp' => $timestamp,
                    'action' => 'merge-products',
                    'user_id' => auth()->id(),
                    'business_id' => $business_id,
                    'rows' => array_map(function ($id) { return ['id' => $id]; }, $payload['sell_line_ids']),
                ], $payload), JSON_PRETTY_PRINT)
            );
            \DB::commit();
        } catch (\Throwable $e) {
            \DB::rollBack();
            if (\Storage::disk('local')->exists("admin-snapshots/{$snapshotKey}.json")) {
                \Storage::disk('local')->delete("admin-snapshots/{$snapshotKey}.json");
            }
            \Log::emergency('merge-products failed: File:' . $e->getFile() . ' Line:' . $e->getLine() . ' Message:' . $e->getMessage());
            return response()->json(['success' => false, 'msg' => 'Merge failed — nothing was changed.']);
        }

        \Cache::forget('products_index_sold_totals:' . $business_id);

        return response()->json([
            'success' => true,
            'msg' => 'Merged "' . $source->name . '" into "' . $target->name . '" — moved '
                . count($payload['sell_line_ids']) . ' sale line(s) and combined stock. The duplicate is now deactivated. Undo at /admin/admin-action-history.',
        ]);
    }

    // ================= SHARED MERGE CORE =================

    /**
     * Move all history + stock from source onto target and deactivate source.
     * MUST be called inside a DB transaction. Returns the reversal payload
     * (all the ids/quantities undo needs). Does NOT write a snapshot file or
     * manage the transaction — the caller does both.
     */
    protected function performMerge($sourceId, $targetId, $sourceVarId, $targetVarId, $targetPvId, $sourceWasInactive, $sourceWasNfs, $sourceName, $targetName, $business_id)
    {
        $sellLineIds = \DB::table('transaction_sell_lines')->where('product_id', $sourceId)->pluck('id')->all();
        $purchaseLineIds = \DB::table('purchase_lines')->where('product_id', $sourceId)->pluck('id')->all();
        $adjLineIds = \DB::table('stock_adjustment_lines')->where('product_id', $sourceId)->pluck('id')->all();

        $sourceVld = \DB::table('variation_location_details')->where('product_id', $sourceId)
            ->select('id', 'location_id', 'qty_available')->get()
            ->map(function ($r) { return ['id' => (int) $r->id, 'location_id' => (int) $r->location_id, 'qty_available' => (string) $r->qty_available]; })
            ->all();

        $sourceLocs = array_values(array_unique(array_map(function ($r) { return $r['location_id']; }, $sourceVld)));
        $targetVldBefore = [];
        if (!empty($sourceLocs)) {
            $targetVldBefore = \DB::table('variation_location_details')->where('product_id', $targetId)
                ->whereIn('location_id', $sourceLocs)
                ->select('id', 'location_id', 'qty_available')->get()
                ->map(function ($r) { return ['id' => (int) $r->id, 'location_id' => (int) $r->location_id, 'qty_available' => (string) $r->qty_available]; })
                ->all();
        }

        // 1-3) Move sales / purchase / adjustment history.
        \DB::table('transaction_sell_lines')->where('product_id', $sourceId)
            ->update(['product_id' => $targetId, 'variation_id' => $targetVarId]);
        \DB::table('purchase_lines')->where('product_id', $sourceId)
            ->update(['product_id' => $targetId, 'variation_id' => $targetVarId]);
        \DB::table('stock_adjustment_lines')->where('product_id', $sourceId)
            ->update(['product_id' => $targetId, 'variation_id' => $targetVarId]);

        // 4) Merge on-hand stock per location; then drop source stock rows.
        $targetVldByLoc = [];
        foreach ($targetVldBefore as $tr) {
            $targetVldByLoc[$tr['location_id']] = $tr['id'];
        }
        $createdTargetVldIds = [];
        foreach ($sourceVld as $sr) {
            $loc = $sr['location_id'];
            if (isset($targetVldByLoc[$loc])) {
                \DB::table('variation_location_details')->where('id', $targetVldByLoc[$loc])
                    ->increment('qty_available', (float) $sr['qty_available']);
            } else {
                $newId = \DB::table('variation_location_details')->insertGetId([
                    'product_id' => $targetId,
                    'product_variation_id' => $targetPvId,
                    'variation_id' => $targetVarId,
                    'location_id' => $loc,
                    'qty_available' => $sr['qty_available'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $createdTargetVldIds[] = (int) $newId;
            }
        }
        \DB::table('variation_location_details')->where('product_id', $sourceId)->delete();

        // 5) Deactivate the duplicate.
        \DB::table('products')->where('id', $sourceId)->update([
            'is_inactive' => 1,
            'not_for_selling' => 1,
        ]);

        // 6) Bust denormalized stock cache (later migration; may be absent).
        if (\Schema::hasTable('product_stock_cache')) {
            \DB::table('product_stock_cache')->whereIn('product_id', [$sourceId, $targetId])->delete();
        }

        return [
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'source_name' => $sourceName,
            'target_name' => $targetName,
            'source_variation_id' => $sourceVarId,
            'target_variation_id' => $targetVarId,
            'target_product_variation_id' => $targetPvId,
            'source_was_inactive' => (int) $sourceWasInactive,
            'source_was_not_for_selling' => (int) $sourceWasNfs,
            'sell_line_ids' => $sellLineIds,
            'purchase_line_ids' => $purchaseLineIds,
            'adj_line_ids' => $adjLineIds,
            'source_vld' => $sourceVld,
            'target_vld_before' => $targetVldBefore,
            'created_target_vld_ids' => $createdTargetVldIds,
        ];
    }

    // ================= WHOLE-CATALOG FLOW =================

    /**
     * Find every duplicate group across the catalog, matched by SKU with
     * leading zeros ignored. Returns groups with per-product stock/sold and
     * the chosen survivor, plus a count of groups skipped for manual review
     * (any product in the group has multiple variations).
     *
     * The survivor is the active copy with the most stock, tie-broken by most
     * units sold, then oldest (lowest id). Combined totals land on it either
     * way, so this only decides which record's name/image stays.
     */
    protected function scanData($business_id)
    {
        $products = \DB::table('products')
            ->where('business_id', $business_id)
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->select('id', 'name', 'sku', 'is_inactive', 'category_id', 'sub_category_id')
            ->get();

        // Group by normalized SKU + title signature — BOTH must match, so
        // records that only share a junk SKU aren't grouped.
        $byKey = [];
        foreach ($products as $p) {
            $sk = $this->skuKey($p->sku);
            if ($sk === '') { continue; }
            $sig = $this->nameSig($p->name);
            if ($sig === '') { continue; }
            $byKey[$sk . '|' . $sig][] = $p;
        }
        $dupeKeys = [];
        $dupeIds = [];
        foreach ($byKey as $key => $rows) {
            if (count($rows) < 2) { continue; }
            $dupeKeys[$key] = $rows;
            foreach ($rows as $r) { $dupeIds[] = (int) $r->id; }
        }

        if (empty($dupeIds)) {
            return ['groups' => [], 'skipped' => 0, 'total_groups' => 0, 'total_merges' => 0];
        }

        // Bulk stats for every product in a dupe group.
        $soldMap = \DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)->where('t.type', 'sell')->where('t.status', 'final')
            ->whereIn('tsl.product_id', $dupeIds)
            ->groupBy('tsl.product_id')
            ->select('tsl.product_id', \DB::raw('SUM(tsl.quantity) as qty'))
            ->pluck('qty', 'product_id');

        // Stock per (product, location) — NOT summed across locations. A product
        // often carries a Discogs Warehouse row on top of its store row, so a
        // flat sum overcounts the store's real on-hand. We scope to the group's
        // store locations below.
        $vldMap = [];
        foreach (\DB::table('variation_location_details')->whereIn('product_id', $dupeIds)
            ->select('product_id', 'location_id', \DB::raw('SUM(qty_available) as qty'))
            ->groupBy('product_id', 'location_id')->get() as $r) {
            $vldMap[(int) $r->product_id][(int) $r->location_id] = (float) $r->qty;
        }

        // Live variations per product (id + product_variation_id). Groups where
        // any product isn't exactly single-variation are skipped.
        $varsByProduct = \DB::table('variations')->whereIn('product_id', $dupeIds)->whereNull('deleted_at')
            ->select('id', 'product_id', 'product_variation_id')->get()->groupBy('product_id');

        // Store per product (product_locations is many-to-many). We only merge
        // within the SAME store, so the store signature = the sorted set of a
        // product's location ids. Products at different stores stay separate.
        $locNames = \DB::table('business_locations')->where('business_id', $business_id)
            ->pluck('name', 'id');
        $storeSig = [];      // product_id => "id,id"
        $storeLabel = [];    // product_id => "Hollywood, Pico"
        $plRows = \DB::table('product_locations')->whereIn('product_id', $dupeIds)
            ->select('product_id', 'location_id')->get()->groupBy('product_id');
        foreach ($dupeIds as $pid) {
            $locs = $plRows->get($pid);
            $ids = $locs ? $locs->pluck('location_id')->map(function ($v) { return (int) $v; })->sort()->values()->all() : [];
            $storeSig[$pid] = implode(',', $ids);
            $storeLabel[$pid] = empty($ids)
                ? 'No store'
                : implode(', ', array_map(function ($id) use ($locNames) { return $locNames[$id] ?? ('#' . $id); }, $ids));
        }

        // Category names for labelling. Grouping also requires the same
        // category + sub-category, so different categories never merge.
        $catNames = \DB::table('categories')->where('business_id', $business_id)->pluck('name', 'id');

        $groups = [];
        $skipped = 0;
        $totalMerges = 0;
        foreach ($dupeKeys as $key => $rows) {
            // Split this barcode+title group by store AND category, so copies
            // at different stores or in different categories never merge.
            $byBucket = [];
            foreach ($rows as $r) {
                $bucket = ($storeSig[(int) $r->id] ?? '') . '|cat|' . ((int) $r->category_id) . '-' . ((int) $r->sub_category_id);
                $byBucket[$bucket][] = $r;
            }

            foreach ($byBucket as $bucketKey => $storeRows) {
                if (count($storeRows) < 2) { continue; }

                // Store location ids for this bucket (from the store part of the
                // key). Stock is counted only at these locations, so warehouse
                // copies don't inflate the store total. Empty = no store set →
                // fall back to all locations.
                $storeLocs = array_values(array_filter(array_map('intval', explode(',', explode('|cat|', $bucketKey)[0]))));

                $singleOk = true;
                foreach ($storeRows as $r) {
                    $vs = $varsByProduct->get($r->id);
                    if (!$vs || count($vs) !== 1) { $singleOk = false; break; }
                }
                if (!$singleOk) { $skipped++; continue; }

                $items = [];
                $groupCatId = 0;
                $groupSubId = 0;
                foreach ($storeRows as $r) {
                    $groupCatId = (int) $r->category_id;
                    $groupSubId = (int) $r->sub_category_id;
                    $perLoc = $vldMap[(int) $r->id] ?? [];
                    $stock = empty($storeLocs)
                        ? array_sum($perLoc)
                        : array_sum(array_map(function ($l) use ($perLoc) { return $perLoc[$l] ?? 0; }, $storeLocs));
                    $items[] = [
                        'id' => (int) $r->id,
                        'name' => $r->name,
                        'sku' => $r->sku,
                        'is_inactive' => (int) $r->is_inactive,
                        'units_sold' => (float) ($soldMap[$r->id] ?? 0),
                        'current_stock' => (float) $stock,
                    ];
                }
                $catLabel = $groupSubId && isset($catNames[$groupSubId])
                    ? $catNames[$groupSubId]
                    : ($groupCatId && isset($catNames[$groupCatId]) ? $catNames[$groupCatId] : 'Uncategorized');
                // Survivor: active first, then most stock, most sold, oldest id.
                usort($items, function ($a, $b) {
                    if ($a['is_inactive'] !== $b['is_inactive']) { return $a['is_inactive'] <=> $b['is_inactive']; }
                    if ($a['current_stock'] !== $b['current_stock']) { return $b['current_stock'] <=> $a['current_stock']; }
                    if ($a['units_sold'] !== $b['units_sold']) { return $b['units_sold'] <=> $a['units_sold']; }
                    return $a['id'] <=> $b['id'];
                });

                $keep = $items[0];
                $mergeIn = array_slice($items, 1);
                $totalMerges += count($mergeIn);
                $groups[] = [
                    'key' => $key . '#' . $bucketKey,
                    'store' => $storeLabel[$keep['id']] ?? 'No store',
                    'category' => $catLabel,
                    'keep' => $keep,
                    'merge_in' => $mergeIn,
                    'combined_stock' => array_sum(array_map(function ($i) { return $i['current_stock']; }, $items)),
                    'combined_sold' => array_sum(array_map(function ($i) { return $i['units_sold']; }, $items)),
                ];
            }
        }

        return ['groups' => $groups, 'skipped' => $skipped, 'total_groups' => count($groups), 'total_merges' => $totalMerges];
    }

    /** Read-only whole-catalog scan. Caps the returned list for display. */
    public function scan(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        if (!$this->isOwner()) {
            return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        }
        $business_id = $request->session()->get('user.business_id');
        $data = $this->scanData($business_id);

        return response()->json([
            'success' => true,
            'total_groups' => $data['total_groups'],
            'total_merges' => $data['total_merges'],
            'skipped' => $data['skipped'],
            'preview' => array_slice($data['groups'], 0, 300),
        ]);
    }

    /**
     * Process one batch of the catalog sweep: merge up to `max` duplicates
     * (default 150) and report how many remain. The UI calls this in a loop
     * until remaining hits 0. Each call writes one "merge-products-bulk"
     * snapshot so the batch can be undone as a unit.
     */
    public function bulk(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        if (!$this->isOwner()) {
            return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        }
        $business_id = $request->session()->get('user.business_id');
        $max = (int) $request->input('max', 150);
        if ($max < 1) { $max = 150; }

        $data = $this->scanData($business_id);
        $remainingBefore = $data['total_merges'];

        $merges = [];
        $done = 0;
        $failed = 0;
        foreach ($data['groups'] as $group) {
            if ($done >= $max) { break; }
            $keep = $group['keep'];
            $targetVars = \DB::table('variations')->where('product_id', $keep['id'])->whereNull('deleted_at')->first();
            if (!$targetVars) { $failed++; continue; }

            foreach ($group['merge_in'] as $src) {
                if ($done >= $max) { break; }
                $srcVar = \DB::table('variations')->where('product_id', $src['id'])->whereNull('deleted_at')->first();
                $srcProduct = \DB::table('products')->where('id', $src['id'])->first();
                if (!$srcVar || !$srcProduct) { $failed++; continue; }

                \DB::beginTransaction();
                try {
                    $payload = $this->performMerge(
                        (int) $src['id'], (int) $keep['id'],
                        (int) $srcVar->id, (int) $targetVars->id, (int) $targetVars->product_variation_id,
                        (int) $srcProduct->is_inactive, (int) $srcProduct->not_for_selling,
                        $src['name'], $keep['name'], $business_id
                    );
                    \DB::commit();
                    $merges[] = $payload;
                    $done++;
                } catch (\Throwable $e) {
                    \DB::rollBack();
                    \Log::emergency('merge-products-bulk pair failed (src=' . $src['id'] . ' -> keep=' . $keep['id'] . '): ' . $e->getMessage());
                    $failed++;
                }
            }
        }

        if (!empty($merges)) {
            $timestamp = now()->format('Y-m-d_His');
            \Storage::disk('local')->put(
                "admin-snapshots/merge-products-bulk-{$timestamp}.json",
                json_encode([
                    'timestamp' => $timestamp,
                    'action' => 'merge-products-bulk',
                    'user_id' => auth()->id(),
                    'business_id' => $business_id,
                    'source_name' => count($merges) . ' duplicate(s)',
                    'target_name' => 'their survivors',
                    'merges' => $merges,
                    'rows' => array_map(function ($m) { return ['id' => $m['source_id']]; }, $merges),
                ], JSON_PRETTY_PRINT)
            );
            \Cache::forget('products_index_sold_totals:' . $business_id);
        }

        $remaining = max(0, $remainingBefore - $done);

        return response()->json([
            'success' => true,
            'merged' => $done,
            'failed' => $failed,
            'remaining' => $remaining,
            'skipped_groups' => $data['skipped'],
            'msg' => "Merged {$done} duplicate(s) this batch. {$remaining} remaining.",
        ]);
    }
}
