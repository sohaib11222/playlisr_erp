<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Owner-only tool to merge a duplicate product into the one you keep.
 *
 * It moves ALL history off the duplicate onto the product you keep so
 * nothing is lost:
 *   - transaction_sell_lines  (Units Sold)
 *   - purchase_lines          (what was received / cost history)
 *   - stock_adjustment_lines  (manual stock changes)
 *   - variation_location_details (on-hand stock, summed per location)
 * then deactivates the duplicate (is_inactive) so it drops out of POS and
 * the product list but the row stays for undo.
 *
 * Everything is snapshotted to storage/app/admin-snapshots BEFORE any write,
 * so it can be reversed at /admin/admin-action-history — same pattern as the
 * category merge (TaxonomyController@merge).
 *
 * Scope guard: single-variation products only (all vinyl / CDs are single).
 * A product with multiple variations is refused rather than risk scrambling
 * its attribute/stock matrix.
 */
class ProductMergeController extends Controller
{
    /** Owner-only, mirroring the category-merge gate (Jonathan Hedvat). */
    protected function ensureOwner()
    {
        $u = auth()->user();
        $isJon = $u
            && strtolower(trim((string) $u->first_name)) === 'jonathan'
            && strtolower(trim((string) $u->last_name)) === 'hedvat';
        return $isJon;
    }

    public function index()
    {
        if (!$this->ensureOwner()) {
            abort(403, 'Product merge is owner-only.');
        }
        return view('products.merge');
    }

    /**
     * Resolve a product by SKU or numeric id within the business, and load
     * its single variation. Returns [product, variation] or [null, null].
     */
    protected function resolveProduct($ref, $business_id)
    {
        $ref = trim((string) $ref);
        if ($ref === '') {
            return [null, null];
        }

        $query = \DB::table('products')->where('business_id', $business_id);
        if (ctype_digit($ref) && strlen($ref) < 12) {
            // Short all-digit strings are ambiguous (could be an id or a short
            // sku). Try id first, fall back to sku below if not found.
            $product = (clone $query)->where('id', (int) $ref)->first();
            if (!$product) {
                $product = (clone $query)->where('sku', $ref)->first();
            }
        } else {
            $product = (clone $query)->where('sku', $ref)->first();
            if (!$product && ctype_digit($ref)) {
                $product = (clone $query)->where('id', (int) $ref)->first();
            }
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

    /** Units sold (final sells) + on-hand stock for a product. */
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

    /**
     * Validate a source/target pair and return a normalized bundle, or an
     * error string. Shared by preview() and merge() so both agree.
     */
    protected function validatePair(Request $request, $business_id)
    {
        $keepRef  = $request->input('keep');   // target — the product you keep
        $mergeRef = $request->input('merge');  // source — the duplicate

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
            return ['error' => 'This tool merges single-variation products only (vinyl / CDs). One of these has multiple variations — merge it manually.'];
        }

        return [
            'source' => $source,
            'target' => $target,
            'source_variation_id' => (int) $sourceVars[0]->id,
            'target_variation_id' => (int) $targetVars[0]->id,
            'target_product_variation_id' => (int) $targetVars[0]->product_variation_id,
        ];
    }

    /** Show what the merge will do — no writes. */
    public function preview(Request $request)
    {
        if (!$this->ensureOwner()) {
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

        $sellLines = \DB::table('transaction_sell_lines')->where('product_id', $source->id)->count();
        $purchaseLines = \DB::table('purchase_lines')->where('product_id', $source->id)->count();

        return response()->json([
            'success' => true,
            'source' => [
                'id' => (int) $source->id, 'name' => $source->name, 'sku' => $source->sku,
                'units_sold' => $s['units_sold'], 'current_stock' => $s['current_stock'],
                'inactive' => (int) $source->is_inactive === 1,
            ],
            'target' => [
                'id' => (int) $target->id, 'name' => $target->name, 'sku' => $target->sku,
                'units_sold' => $t['units_sold'], 'current_stock' => $t['current_stock'],
            ],
            'after' => [
                'units_sold' => $s['units_sold'] + $t['units_sold'],
                'current_stock' => $s['current_stock'] + $t['current_stock'],
            ],
            'moves' => [
                'sell_lines' => $sellLines,
                'purchase_lines' => $purchaseLines,
            ],
        ]);
    }

    /** Do the merge, transactionally, after snapshotting. */
    public function merge(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        if (!$this->ensureOwner()) {
            return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        }
        $business_id = $request->session()->get('user.business_id');
        $pair = $this->validatePair($request, $business_id);
        if (isset($pair['error'])) {
            return response()->json(['success' => false, 'msg' => $pair['error']]);
        }

        $source = $pair['source'];
        $target = $pair['target'];
        $sourceId = (int) $source->id;
        $targetId = (int) $target->id;
        $sourceVarId = $pair['source_variation_id'];
        $targetVarId = $pair['target_variation_id'];
        $targetPvId = $pair['target_product_variation_id'];

        // ---- Snapshot BEFORE any write ---------------------------------
        $sellLineIds = \DB::table('transaction_sell_lines')->where('product_id', $sourceId)->pluck('id')->all();
        $purchaseLineIds = \DB::table('purchase_lines')->where('product_id', $sourceId)->pluck('id')->all();
        $adjLineIds = \DB::table('stock_adjustment_lines')->where('product_id', $sourceId)->pluck('id')->all();

        $sourceVld = \DB::table('variation_location_details')
            ->where('product_id', $sourceId)
            ->select('id', 'location_id', 'qty_available')
            ->get()
            ->map(function ($r) { return ['id' => (int) $r->id, 'location_id' => (int) $r->location_id, 'qty_available' => (string) $r->qty_available]; })
            ->all();

        // Target stock rows that already exist for the locations the source
        // has stock in — snapshot their BEFORE qty so undo can restore them.
        $sourceLocs = array_values(array_unique(array_map(function ($r) { return $r['location_id']; }, $sourceVld)));
        $targetVldBefore = [];
        if (!empty($sourceLocs)) {
            $targetVldBefore = \DB::table('variation_location_details')
                ->where('product_id', $targetId)
                ->whereIn('location_id', $sourceLocs)
                ->select('id', 'location_id', 'qty_available')
                ->get()
                ->map(function ($r) { return ['id' => (int) $r->id, 'location_id' => (int) $r->location_id, 'qty_available' => (string) $r->qty_available]; })
                ->all();
        }

        $timestamp = now()->format('Y-m-d_His');
        $snapshotKey = "merge-products-{$timestamp}";
        $createdTargetVldIds = [];

        \DB::beginTransaction();
        try {
            // 1) Move sales history (Units Sold).
            \DB::table('transaction_sell_lines')->where('product_id', $sourceId)
                ->update(['product_id' => $targetId, 'variation_id' => $targetVarId]);

            // 2) Move purchase history.
            \DB::table('purchase_lines')->where('product_id', $sourceId)
                ->update(['product_id' => $targetId, 'variation_id' => $targetVarId]);

            // 3) Move manual stock adjustments.
            \DB::table('stock_adjustment_lines')->where('product_id', $sourceId)
                ->update(['product_id' => $targetId, 'variation_id' => $targetVarId]);

            // 4) Merge on-hand stock per location: add source qty into the
            //    target's row for that location, creating it if missing. Then
            //    delete the source's stock rows.
            $targetVldByLoc = [];
            foreach ($targetVldBefore as $tr) {
                $targetVldByLoc[$tr['location_id']] = $tr['id'];
            }
            foreach ($sourceVld as $sr) {
                $loc = $sr['location_id'];
                if (isset($targetVldByLoc[$loc])) {
                    \DB::table('variation_location_details')
                        ->where('id', $targetVldByLoc[$loc])
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

            // ---- Write the snapshot (inside the txn is fine; it's a file). --
            \Storage::disk('local')->put(
                "admin-snapshots/{$snapshotKey}.json",
                json_encode([
                    'timestamp'   => $timestamp,
                    'action'      => 'merge-products',
                    'user_id'     => auth()->id(),
                    'business_id' => $business_id,
                    'source_id'   => $sourceId,
                    'target_id'   => $targetId,
                    'source_name' => $source->name,
                    'target_name' => $target->name,
                    'source_variation_id' => $sourceVarId,
                    'target_variation_id' => $targetVarId,
                    'target_product_variation_id' => $targetPvId,
                    'source_was_inactive' => (int) $source->is_inactive,
                    'source_was_not_for_selling' => (int) $source->not_for_selling,
                    'sell_line_ids' => $sellLineIds,
                    'purchase_line_ids' => $purchaseLineIds,
                    'adj_line_ids' => $adjLineIds,
                    'source_vld' => $sourceVld,
                    'target_vld_before' => $targetVldBefore,
                    'created_target_vld_ids' => $createdTargetVldIds,
                    // 'rows' drives the count column on the history page.
                    'rows' => array_map(function ($id) { return ['id' => $id]; }, $sellLineIds),
                ], JSON_PRETTY_PRINT)
            );

            // 5) Deactivate the duplicate (keep the row for undo). Product has
            //    no SoftDeletes, so we never hard-delete it.
            \DB::table('products')->where('id', $sourceId)->update([
                'is_inactive' => 1,
                'not_for_selling' => 1,
            ]);

            // 6) Bust the denormalized stock cache + the products-list sold
            //    aggregate so both products show fresh numbers. The cache table
            //    is a later migration that may not be deployed everywhere.
            if (\Schema::hasTable('product_stock_cache')) {
                \DB::table('product_stock_cache')->whereIn('product_id', [$sourceId, $targetId])->delete();
            }

            \DB::commit();
        } catch (\Throwable $e) {
            \DB::rollBack();
            // The snapshot file may have been written before a later failure;
            // remove it so it can't be "undone" against un-changed data.
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
                . count($sellLineIds) . ' sale line(s) and combined stock. The duplicate is now deactivated. Undo at /admin/admin-action-history.',
        ]);
    }
}
