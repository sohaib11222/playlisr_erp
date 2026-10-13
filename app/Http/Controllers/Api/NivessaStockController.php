<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Website bridge: set a product's ERP stock to 0 when an order is cancelled
 * because the item is gone (Discogs "Item Unavailable", or an ERP cancel
 * for sold in store / damaged). Same steps as ProductController@zeroStock
 * (snapshot for undo, activity log, instant website push). Zeroing an
 * already-zero product changes nothing, so retries are safe.
 */
class NivessaStockController extends Controller
{
    private function businessId(): int
    {
        return (int) config('services.nivessa_web.business_id');
    }

    /** POST /api/v1/nivessa-web/products/zero-stock  { product_id, reason } */
    public function zero(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_id' => 'required|integer',
            'reason'     => 'required|string|max:255',
        ]);
        $product = Product::where('business_id', $this->businessId())->where('id', $data['product_id'])->first();
        if (!$product) {
            return response()->json(['success' => false, 'error' => 'not_found'], 404);
        }
        if (empty($product->enable_stock)) {
            return response()->json(['success' => false, 'error' => 'stock_not_managed', 'name' => $product->name], 422);
        }

        $rows = DB::table('variation_location_details as vld')
            ->join('variations as v', 'v.id', '=', 'vld.variation_id')
            ->where('v.product_id', $product->id)
            ->where('vld.qty_available', '>', 0)
            ->select('vld.id', 'vld.qty_available')
            ->get();
        $before = (float) $rows->sum('qty_available');

        if ($rows->isNotEmpty()) {
            try {
                DB::beginTransaction();
                foreach ($rows->pluck('id')->chunk(500) as $chunk) {
                    DB::table('variation_location_details')->whereIn('id', $chunk->all())
                        ->update(['qty_available' => 0, 'updated_at' => now()]);
                }
                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                Log::error('nivessa_web zero stock failed for product ' . $product->id . ': ' . $e->getMessage());
                return response()->json(['success' => false, 'error' => 'server_error'], 500);
            }
            Storage::disk('local')->put(
                'admin-snapshots/zero-single-product-stock-' . now()->format('Y-m-d_His') . '-p' . $product->id . '.json',
                json_encode([
                    'timestamp' => now()->toDateTimeString(),
                    'action' => 'zero-single-product-stock',
                    'business_id' => $product->business_id,
                    'product_id' => (int) $product->id,
                    'product_name' => $product->name,
                    'reason' => $data['reason'],
                    'rows' => $rows->map(function ($r) { return ['id' => $r->id, 'qty_available' => $r->qty_available]; })->all(),
                ], JSON_PRETTY_PRINT)
            );
            try {
                $log = activity()->performedOn($product)
                    ->withProperties(['update_note' => $product->name . ' - Zero Stock: ' . $before . ' -> 0 (' . $data['reason'] . ')'])
                    ->log('stock_changed');
                $log->business_id = $product->business_id;
                $log->save();
            } catch (\Throwable $e) {
                Log::warning('zero stock activity log failed: ' . $e->getMessage());
            }
        }
        try {
            (new \App\Services\NivessaStockNotifier())->push([(int) $product->id], true);
        } catch (\Throwable $e) {
            Log::warning('zero stock website push failed for product ' . $product->id . ': ' . $e->getMessage());
        }
        return response()->json([
            'success' => true,
            'product_id' => (int) $product->id,
            'name' => $product->name,
            'sku' => $product->sku,
            'before' => $before,
            'after' => 0,
            'already_zero' => $rows->isEmpty(),
        ]);
    }

    /** POST /api/v1/nivessa-web/products/stock  { product_ids: [] } -> current ERP stock (read-only) */
    public function stock(Request $request): JsonResponse
    {
        $ids = array_slice(array_map('intval', (array) $request->input('product_ids', [])), 0, 50);
        $rows = DB::table('variation_location_details as vld')
            ->join('variations as v', 'v.id', '=', 'vld.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.business_id', $this->businessId())
            ->whereIn('v.product_id', $ids)
            ->groupBy('v.product_id')
            ->select('v.product_id', DB::raw('SUM(vld.qty_available) as qty'))
            ->pluck('qty', 'v.product_id');
        return response()->json(['success' => true, 'stock' => $rows]);
    }
}
