<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Read-only: exact per-import_source composition of the prior-year baseline
// months touched by the parked-sheet imports, so we can see precisely what
// makes up each store/month total and confirm there's no double-count. Pure
// SUM/COUNT from the live DB grouped by import_source — nothing inferred,
// nothing written.
class BaselineBreakdownController extends Controller
{
    // (location needle, YYYY-MM, label, the source the import added)
    const CELLS = [
        ['hollywood', '2024-11', 'Hollywood — Nov 2024', 'nivessa_backend_sales_hw_november_24_sales'],
        ['hollywood', '2025-01', 'Hollywood — Jan 2025', 'nivessa_backend_sales_hw_sales_jan_25'],
        ['pico',      '2025-04', 'Pico — Apr 2025',      'nivessa_backend_sales_pico_april_25'],
        ['pico',      '2025-05', 'Pico — May 2025',      'nivessa_backend_sales_pico_may_2025'],
    ];

    public function index(Request $request)
    {
        $businessId = $request->session()->get('user.business_id');
        $cells = [];

        foreach (self::CELLS as [$needle, $month, $label, $addedSource]) {
            $loc = DB::table('business_locations')
                ->where('business_id', $businessId)
                ->where('name', 'like', '%' . $needle . '%')
                ->orderBy('id')->first(['id', 'name']);
            if (!$loc) { continue; }

            $rows = DB::table('transactions')
                ->where('business_id', $businessId)
                ->where('location_id', $loc->id)
                ->where('type', 'sell')->where('status', 'final')
                ->where(function ($q) { $q->where('is_whatnot', 0)->orWhereNull('is_whatnot'); })
                ->whereRaw("DATE_FORMAT(transaction_date, '%Y-%m') = ?", [$month])
                ->select(
                    DB::raw("COALESCE(import_source, '(live POS)') as src"),
                    DB::raw('COUNT(*) as cnt'),
                    DB::raw('COALESCE(SUM(final_total),0) as total')
                )
                ->groupBy('src')
                ->orderByRaw('total DESC')
                ->get();

            // Forensics on the pre-existing (live POS / no import_source) rows:
            // when were they CREATED? Contemporaneous (back then) = real register
            // sales, separate from the sheet. All created recently = entered later,
            // possible overlap. Pure facts from the DB.
            $liveBase = DB::table('transactions')
                ->where('business_id', $businessId)
                ->where('location_id', $loc->id)
                ->where('type', 'sell')->where('status', 'final')
                ->whereNull('import_source')
                ->where(function ($q) { $q->where('is_whatnot', 0)->orWhereNull('is_whatnot'); })
                ->whereRaw("DATE_FORMAT(transaction_date, '%Y-%m') = ?", [$month]);
            $liveStats = (clone $liveBase)
                ->selectRaw('MIN(created_at) as min_c, MAX(created_at) as max_c, COUNT(*) as cnt, COALESCE(SUM(final_total),0) as total')
                ->first();
            $liveSamples = (clone $liveBase)
                ->orderBy('transaction_date')
                ->limit(6)
                ->get(['id', 'invoice_no', 'transaction_date', 'created_at', 'final_total']);

            $total = $rows->sum('total');
            $cells[] = [
                'liveStats' => $liveStats,
                'liveSamples' => $liveSamples,
                'label' => $label,
                'store' => $loc->name,
                'month' => $month,
                'addedSource' => $addedSource,
                'rows' => $rows,
                'total' => $total,
            ];
        }

        return view('admin.baseline_breakdown', ['cells' => $cells]);
    }
}
