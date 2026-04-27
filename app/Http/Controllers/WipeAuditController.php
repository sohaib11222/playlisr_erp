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

        // Cover the ENTIRE day 2026-04-27 in any reasonable timezone — and
        // also a 24-hour bracket on either side, since MySQL might store in
        // UTC or in server-local Phoenix time. Anything updated today with
        // both purchase columns at 0 is a victim of the backfill.
        $windows = [
            ['2026-04-26 12:00:00', '2026-04-28 12:00:00'],
        ];

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
            })
            ->where(function ($q) use ($windows) {
                foreach ($windows as $w) {
                    $q->orWhereBetween('v.updated_at', $w);
                }
            });

        $count = (clone $wipeQuery)->count();

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
        ]);
    }

    public function csv()
    {
        $businessId = request()->session()->get('user.business_id');

        $windows = [
            ['2026-04-27 11:00:00', '2026-04-27 12:00:00'],
            ['2026-04-27 18:00:00', '2026-04-27 19:00:00'],
        ];

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
            ->where(function ($q) use ($windows) {
                foreach ($windows as $w) {
                    $q->orWhereBetween('v.updated_at', $w);
                }
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
