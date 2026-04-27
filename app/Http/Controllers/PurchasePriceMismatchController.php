<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Nivessa has a resale certificate, so purchase prices have no sales tax.
// dpp_inc_tax should equal default_purchase_price. The product form auto-
// inflates one from the other via 9.75% tax math, leaving phantom mismatches.
// This page lists those and one-click backfills both columns to a single value.
class PurchasePriceMismatchController extends Controller
{
    public function index()
    {
        $businessId = request()->session()->get('user.business_id');

        $rows = DB::table('variations as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.business_id', $businessId)
            ->whereNull('v.deleted_at')
            ->whereNull('p.deleted_at')
            ->whereRaw('ROUND(v.default_purchase_price, 2) <> ROUND(v.dpp_inc_tax, 2)')
            ->select(
                'v.id as variation_id',
                'p.id as product_id',
                'p.name',
                'p.sku',
                'c.name as category',
                'v.default_purchase_price as exc_tax',
                'v.dpp_inc_tax as inc_tax'
            )
            ->orderBy('p.name')
            ->limit(2000)
            ->get();

        $totalMismatched = DB::table('variations as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.business_id', $businessId)
            ->whereNull('v.deleted_at')
            ->whereNull('p.deleted_at')
            ->whereRaw('ROUND(v.default_purchase_price, 2) <> ROUND(v.dpp_inc_tax, 2)')
            ->count();

        $totalProducts = DB::table('products')
            ->where('business_id', $businessId)
            ->whereNull('deleted_at')
            ->count();

        return view('admin.purchase_price_mismatch', [
            'rows' => $rows,
            'totalMismatched' => $totalMismatched,
            'totalProducts' => $totalProducts,
        ]);
    }

    public function run(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $businessId = $request->session()->get('user.business_id');
        $direction = $request->input('direction', 'use_exc'); // use_exc | use_inc

        $sourceCol = $direction === 'use_inc' ? 'dpp_inc_tax' : 'default_purchase_price';
        $targetCol = $direction === 'use_inc' ? 'default_purchase_price' : 'dpp_inc_tax';

        $variationIds = DB::table('variations as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.business_id', $businessId)
            ->whereNull('v.deleted_at')
            ->whereNull('p.deleted_at')
            ->whereRaw('ROUND(v.default_purchase_price, 2) <> ROUND(v.dpp_inc_tax, 2)')
            ->pluck('v.id')
            ->all();

        $updated = 0;
        foreach (array_chunk($variationIds, 500) as $chunk) {
            $updated += DB::table('variations')
                ->whereIn('id', $chunk)
                ->update([
                    $targetCol => DB::raw($sourceCol),
                    'updated_at' => now(),
                ]);
        }

        return redirect('/admin/purchase-price-mismatch')
            ->with('status', ['success' => 1, 'msg' => "Aligned $updated variations (used $sourceCol)."]);
    }
}
