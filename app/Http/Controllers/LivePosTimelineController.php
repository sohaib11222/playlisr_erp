<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Read-only: monthly count + $ of real register sales (finalized sells with NO
// import_source) per store, across all history — shows exactly WHEN the ERP
// register started being used and how it ramped. Pure DB facts.
class LivePosTimelineController extends Controller
{
    public function index(Request $request)
    {
        $businessId = $request->session()->get('user.business_id');

        $locations = DB::table('business_locations')
            ->where('business_id', $businessId)
            ->where('is_active', 1)
            ->where('name', 'not like', '%warehouse%')
            ->orderBy('id')->pluck('name', 'id');

        $rows = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('type', 'sell')->where('status', 'final')
            ->whereNull('import_source')
            ->where(function ($q) { $q->where('is_whatnot', 0)->orWhereNull('is_whatnot'); })
            ->select(
                DB::raw("DATE_FORMAT(transaction_date, '%Y-%m') as ym"),
                'location_id',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('COALESCE(SUM(final_total),0) as total')
            )
            ->groupBy('ym', 'location_id')
            ->orderBy('ym')
            ->get();

        $months = [];
        foreach ($rows as $r) {
            $months[$r->ym][$r->location_id] = ['cnt' => (int) $r->cnt, 'total' => (float) $r->total];
        }
        krsort($months);

        // First ever register sale per store
        $firsts = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('type', 'sell')->where('status', 'final')
            ->whereNull('import_source')
            ->select('location_id', DB::raw('MIN(transaction_date) as first_sale'))
            ->groupBy('location_id')->get()->keyBy('location_id');

        return view('admin.live_pos_timeline', compact('locations', 'months', 'firsts'));
    }
}
