<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records, CDs, tapes and movies rung up as "Item With No Barcode" (Sarah,
 * 2026-10-23). "Has a barcode, did not scan" rows are on top: those are labels
 * or listings to fix. Rows the ERP already had in stock are flagged too.
 */
class BarcodeMissController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        if (!$user || !($user->can('sell.create') || $user->can('product.create'))) {
            abort(403, 'Unauthorized action.');
        }
        $businessId = (int) request()->session()->get('user.business_id');
        $days = max(1, min(365, (int) request()->get('days', 30)));
        $rows = collect();
        if (Schema::hasTable('barcode_scan_misses')) {
            $rows = DB::table('barcode_scan_misses as m')
                ->leftJoin('business_locations as l', 'l.id', '=', 'm.location_id')
                ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
                ->where('m.business_id', $businessId)
                ->where('m.created_at', '>=', now()->subDays($days))
                ->orderByRaw("CASE WHEN m.reason LIKE 'Has a barcode%' THEN 0 WHEN m.erp_matches IS NOT NULL THEN 1 ELSE 2 END")
                ->orderBy('m.created_at', 'desc')
                ->limit(1000)
                ->select('m.*', 'l.name as location_name', DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as cashier"))
                ->get();
        }
        return view('reports.barcode_misses', compact('rows', 'days'));
    }
}
