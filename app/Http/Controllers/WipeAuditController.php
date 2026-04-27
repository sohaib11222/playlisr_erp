<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

// Pinpoints exactly which variation rows were zeroed by the 2026-04-27
// purchase-price-mismatch backfill: rows whose default_purchase_price AND
// dpp_inc_tax are both 0 NOW *and* whose updated_at falls in the backfill
// window. This is the precise restore target — narrows Sohaib's surgical
// recovery from "1,892 products with \$0 cost" down to just the actual
// victims of the bug.
class WipeAuditController extends Controller
{
    public function index()
    {
        $businessId = request()->session()->get('user.business_id');

        // Just find ALL variations with both purchase columns at 0, grouped
        // by creator — the timestamp filter was missing rows because the ERP
        // displays updated_at with weird AM/PM quirks. The actual signature
        // is "both columns are 0" — that's what we need to fix regardless of
        // when it happened or whether it was today's backfill.
        $wipeQuery = DB::table('variations as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.created_by')
            ->where('p.business_id', $businessId)
            ->whereNull('v.deleted_at')
            ->where(function ($q) {
                $q->whereNull('v.default_purchase_price')->orWhere('v.default_purchase_price', 0);
            })
            ->where(function ($q) {
                $q->whereNull('v.dpp_inc_tax')->orWhere('v.dpp_inc_tax', 0);
            });

        $count = (clone $wipeQuery)->count();

        // Per-creator summary so Sarah can see who has the most affected.
        $byCreator = (clone $wipeQuery)
            ->select(
                DB::raw("CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) as created_by"),
                DB::raw("COUNT(*) as cnt")
            )
            ->groupBy('created_by')
            ->orderBy('cnt', 'desc')
            ->get();

        $rows = $wipeQuery
            ->select(
                'v.id as variation_id',
                'p.id as product_id',
                'v.updated_at',
                'p.name',
                'p.sku',
                'v.default_purchase_price',
                'v.dpp_inc_tax',
                'v.default_sell_price',
                'v.sell_price_inc_tax',
                DB::raw("CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) as created_by")
            )
            ->orderBy('v.updated_at', 'desc')
            ->limit(5000)
            ->get();

        return view('admin.wipe_audit', [
            'rows' => $rows,
            'count' => $count,
            'byCreator' => $byCreator,
        ]);
    }

    public function csv()
    {
        $businessId = request()->session()->get('user.business_id');

        $rows = DB::table('variations as v')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.created_by')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.business_id', $businessId)
            ->whereNull('v.deleted_at')
            ->where(function ($q) {
                $q->whereNull('v.default_purchase_price')->orWhere('v.default_purchase_price', 0);
            })
            ->where(function ($q) {
                $q->whereNull('v.dpp_inc_tax')->orWhere('v.dpp_inc_tax', 0);
            })
            ->select(
                'v.id as variation_id',
                'p.id as product_id',
                'p.sku',
                'p.name',
                'c.name as category',
                'v.default_sell_price',
                'v.sell_price_inc_tax',
                'v.updated_at',
                DB::raw("CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) as created_by")
            )
            ->orderBy('p.name')
            ->get();

        $filename = 'wipe-audit-2026-04-27.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->stream(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'variation_id', 'product_id', 'sku', 'product', 'category',
                'created_by', 'wiped_at', 'selling_price_ex_tax', 'selling_price_inc_tax',
                'purchase_price_to_re_enter',
            ]);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->variation_id,
                    $r->product_id,
                    $r->sku,
                    $r->name,
                    $r->category,
                    trim($r->created_by),
                    $r->updated_at,
                    number_format((float) $r->default_sell_price, 2, '.', ''),
                    number_format((float) $r->sell_price_inc_tax, 2, '.', ''),
                    '', // empty column for re-entry
                ]);
            }
            fclose($out);
        }, 200, $headers);
    }
}
