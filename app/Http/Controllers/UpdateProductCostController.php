<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Direct-edit admin path for purchase price (Nivessa cost). Bypasses the
// product edit form, which has been silently dropping cost updates for some
// users (Fatteen, 2026-05-14) despite multiple JS/validation fixes — root
// cause still unconfirmed, so this is the working alternative in the
// meantime.
//
// Search by SKU or name, see current cost + sticker, type the new cost,
// submit. Snapshots the BEFORE row to storage/admin-snapshots/ so any
// mistake is undoable via /admin/admin-action-history (same pattern as
// purchase-price-mismatch — required after the 2026-04-27 wipe).
//
// Per Nivessa's resale-cert convention, default_purchase_price and
// dpp_inc_tax are always mirrored to the same value.
class UpdateProductCostController extends Controller
{
    public function index(Request $request)
    {
        $businessId = $request->session()->get('user.business_id');
        $q = trim((string) $request->input('q', ''));
        $rows = collect();

        if ($q !== '') {
            $rows = DB::table('variations as v')
                ->join('products as p', 'p.id', '=', 'v.product_id')
                ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->where('p.business_id', $businessId)
                ->whereNull('v.deleted_at')
                ->where(function ($w) use ($q) {
                    $w->where('p.sku', 'like', $q . '%')
                      ->orWhere('v.sub_sku', 'like', $q . '%')
                      ->orWhere('p.name', 'like', '%' . $q . '%');
                })
                ->orderBy('p.name')
                ->limit(50)
                ->get([
                    'v.id as variation_id',
                    'p.id as product_id',
                    'p.name',
                    'p.sku',
                    'v.sub_sku',
                    'c.name as category',
                    'v.default_purchase_price as exc_tax',
                    'v.dpp_inc_tax as inc_tax',
                    'v.default_sell_price as sell',
                ]);
        }

        return view('admin.update_product_cost', [
            'q' => $q,
            'rows' => $rows,
        ]);
    }

    public function run(Request $request)
    {
        $businessId = $request->session()->get('user.business_id');
        $variationId = (int) $request->input('variation_id');
        $newCost = $request->input('new_cost');

        if (!$variationId) {
            return back()->with('status', ['success' => 0, 'msg' => 'Missing variation id.']);
        }

        if (!is_numeric($newCost) || (float) $newCost <= 0) {
            return back()->with('status', ['success' => 0, 'msg' => 'New cost must be a positive number.']);
        }
        $newCost = round((float) $newCost, 2);

        // Resolve the row and confirm it belongs to this business — so a URL
        // tamper can't reprice another tenant's products.
        $row = DB::table('variations as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('v.id', $variationId)
            ->where('p.business_id', $businessId)
            ->whereNull('v.deleted_at')
            ->select('v.id', 'v.default_purchase_price', 'v.dpp_inc_tax', 'v.updated_at', 'p.sku', 'p.name')
            ->first();

        if (!$row) {
            return back()->with('status', ['success' => 0, 'msg' => 'Variation not found for this business.']);
        }

        $timestamp = now()->format('Y-m-d_His');
        $snapshotKey = "update-product-cost-{$timestamp}-v{$variationId}";
        Storage::disk('local')->put(
            "admin-snapshots/{$snapshotKey}.json",
            json_encode([
                'timestamp' => $timestamp,
                'action'    => 'update-product-cost',
                'user_id'   => auth()->id(),
                'business_id' => $businessId,
                'new_cost'  => $newCost,
                'rows'      => [[
                    'id' => $row->id,
                    'default_purchase_price' => $row->default_purchase_price,
                    'dpp_inc_tax' => $row->dpp_inc_tax,
                    'updated_at' => (string) $row->updated_at,
                ]],
            ], JSON_PRETTY_PRINT)
        );

        DB::table('variations')
            ->where('id', $variationId)
            ->update([
                'default_purchase_price' => $newCost,
                'dpp_inc_tax' => $newCost,
                'updated_at' => now(),
            ]);

        return redirect('/admin/update-product-cost?q=' . urlencode($row->sku))
            ->with('status', [
                'success' => 1,
                'msg' => "Updated cost on {$row->name} ({$row->sku}) to \${$newCost}. Snapshot: {$snapshotKey}",
            ]);
    }
}
