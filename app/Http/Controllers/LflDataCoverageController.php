<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

// Read-only diagnostic for the Like-for-Like report's n/a cells.
//
// The LFL report (ReportController@lflSalesMonthly) counts ALL finalized
// in-store sells, imports included — so a prior-year "n/a" means the dollars
// genuinely aren't on that store/month in `transactions`. The historical
// importer (ImportNivessaHistoricalSales) defaults store-agnostic "in-store
// sales" sheets to Hollywood, so pre-May-2024 Pico sales likely landed on
// Hollywood instead. This page proves exactly where the money sits: finalized
// sell revenue by store x month x import_source. Nothing is mutated.
class LflDataCoverageController extends Controller
{
    public function index()
    {
        $businessId = request()->session()->get('user.business_id');

        $locations = DB::table('business_locations')
            ->where('business_id', $businessId)
            ->where('is_active', 1)
            ->where('name', 'not like', '%warehouse%')
            ->orderBy('id')
            ->pluck('name', 'id');

        $since = \Carbon::today()->subMonths(36)->startOfMonth()->toDateString();

        // finalized in-store sells (same definition as the LFL report:
        // is_whatnot excluded), grouped by month x location x import_source.
        $rows = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->where(function ($q) {
                $q->where('is_whatnot', 0)->orWhereNull('is_whatnot');
            })
            ->where('transaction_date', '>=', $since)
            ->select(
                DB::raw("DATE_FORMAT(transaction_date, '%Y-%m') as ym"),
                'location_id',
                DB::raw("COALESCE(import_source, '(live)') as src"),
                DB::raw('COUNT(*) as cnt'),
                DB::raw('COALESCE(SUM(final_total),0) as revenue')
            )
            ->groupBy('ym', 'location_id', 'src')
            ->orderBy('ym', 'desc')
            ->get();

        // Pivot: months[ym][location_id] = ['live'=>x, 'import'=>y, 'srcs'=>[...]]
        $months = [];
        $srcTotals = [];
        foreach ($rows as $r) {
            $isImport = $r->src !== '(live)';
            $bucket = $isImport ? 'import' : 'live';
            $months[$r->ym][$r->location_id]['live']   = $months[$r->ym][$r->location_id]['live']   ?? 0.0;
            $months[$r->ym][$r->location_id]['import'] = $months[$r->ym][$r->location_id]['import'] ?? 0.0;
            $months[$r->ym][$r->location_id][$bucket] += (float) $r->revenue;
            $months[$r->ym][$r->location_id]['srcs'][$r->src] = ($months[$r->ym][$r->location_id]['srcs'][$r->src] ?? 0) + (int) $r->cnt;

            $srcTotals[$r->src]['cnt']     = ($srcTotals[$r->src]['cnt'] ?? 0) + (int) $r->cnt;
            $srcTotals[$r->src]['revenue'] = ($srcTotals[$r->src]['revenue'] ?? 0.0) + (float) $r->revenue;
        }
        krsort($months);
        arsort($srcTotals);

        return view('admin.lfl_data_coverage', [
            'locations' => $locations,
            'months'    => $months,
            'srcTotals' => $srcTotals,
            'since'     => $since,
        ]);
    }
}
