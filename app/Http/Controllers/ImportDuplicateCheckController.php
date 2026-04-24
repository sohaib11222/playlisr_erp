<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class ImportDuplicateCheckController extends Controller
{
    const IMPORT_SOURCE_PREFIX = 'nivessa_backend_sales_';

    public function index()
    {
        $businessId = request()->session()->get('user.business_id');

        // Per-day per-location: count ERP-native sells vs imported sells.
        // ERP-native = import_source IS NULL (or empty). Imported = our prefix.
        $rows = DB::table('transactions as t')
            ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->selectRaw(
                'DATE(t.transaction_date) as sale_date,
                 bl.name as location,
                 SUM(CASE WHEN t.import_source IS NULL OR t.import_source = "" THEN 1 ELSE 0 END) as erp_rows,
                 SUM(CASE WHEN t.import_source LIKE "' . self::IMPORT_SOURCE_PREFIX . '%" THEN 1 ELSE 0 END) as imported_rows,
                 SUM(CASE WHEN t.import_source IS NULL OR t.import_source = "" THEN t.final_total ELSE 0 END) as erp_total,
                 SUM(CASE WHEN t.import_source LIKE "' . self::IMPORT_SOURCE_PREFIX . '%" THEN t.final_total ELSE 0 END) as imported_total'
            )
            ->groupBy(DB::raw('DATE(t.transaction_date)'), 'bl.name')
            ->havingRaw('erp_rows > 0 AND imported_rows > 0')
            ->orderByDesc('sale_date')
            ->limit(500)
            ->get();

        // Also: min/max dates of ERP-native sells — so Sarah can spot the
        // ERP go-live date by eye (first ERP-native transaction).
        $erpRange = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->where(function ($q) {
                $q->whereNull('import_source')->orWhere('import_source', '');
            })
            ->selectRaw('MIN(transaction_date) as first_erp_tx, MAX(transaction_date) as last_erp_tx, COUNT(*) as erp_total_rows')
            ->first();

        $importRange = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('import_source', 'like', self::IMPORT_SOURCE_PREFIX . '%')
            ->selectRaw('MIN(transaction_date) as first_import_tx, MAX(transaction_date) as last_import_tx, COUNT(*) as import_total_rows')
            ->first();

        return view('admin.import_duplicate_check', [
            'overlaps' => $rows,
            'erpRange' => $erpRange,
            'importRange' => $importRange,
        ]);
    }
}
