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

        $todayWindow = ['2026-04-26 12:00:00', '2026-04-28 12:00:00'];

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

        // True wipe victims = existed BEFORE today AND were updated today.
        // Products CREATED today with no cost (e.g. Manolo's 12:54 PM batch)
        // weren't wiped — they just never had a cost entered. Same for older
        // products with old updated_at: long-standing missing data.
        $wipedTodayCount = (clone $wipeQuery)
            ->whereBetween('v.updated_at', $todayWindow)
            ->where('p.created_at', '<', $todayWindow[0])
            ->count();

        $count = (clone $wipeQuery)->count();
        $longStandingCount = $count - $wipedTodayCount;

        // Per-creator: wiped-today (existed before, updated today) only.
        $byCreatorToday = (clone $wipeQuery)
            ->whereBetween('v.updated_at', $todayWindow)
            ->where('p.created_at', '<', $todayWindow[0])
            ->select(
                DB::raw("CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) as created_by"),
                DB::raw("COUNT(*) as cnt")
            )
            ->groupBy('created_by')
            ->orderBy('cnt', 'desc')
            ->get();

        // Per-creator: everything that wasn't wiped today = pre-existing $0
        // (created today with no cost, OR old product that never had cost).
        $byCreatorOld = (clone $wipeQuery)
            ->where(function ($q) use ($todayWindow) {
                $q->whereNotBetween('v.updated_at', $todayWindow)
                  ->orWhere('p.created_at', '>=', $todayWindow[0]);
            })
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
            'wipedTodayCount' => $wipedTodayCount,
            'longStandingCount' => $longStandingCount,
            'byCreatorToday' => $byCreatorToday,
            'byCreatorOld' => $byCreatorOld,
            'todayWindow' => $todayWindow,
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

        $todayStart = strtotime('2026-04-26 12:00:00');
        $todayEnd = strtotime('2026-04-28 12:00:00');

        return response()->stream(function () use ($rows, $todayStart, $todayEnd) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'variation_id', 'product_id', 'sku', 'product', 'category',
                'created_by', 'updated_at', 'wiped_today',
                'selling_price_ex_tax', 'selling_price_inc_tax',
                'purchase_price_to_re_enter',
            ]);
            foreach ($rows as $r) {
                $ts = strtotime($r->updated_at);
                $wipedToday = ($ts >= $todayStart && $ts <= $todayEnd) ? 'YES' : 'no';
                fputcsv($out, [
                    $r->variation_id,
                    $r->product_id,
                    $r->sku,
                    $r->name,
                    $r->category,
                    trim($r->created_by),
                    $r->updated_at,
                    $wipedToday,
                    number_format((float) $r->default_sell_price, 2, '.', ''),
                    number_format((float) $r->sell_price_inc_tax, 2, '.', ''),
                    '', // empty column for re-entry
                ]);
            }
            fclose($out);
        }, 200, $headers);
    }
}
