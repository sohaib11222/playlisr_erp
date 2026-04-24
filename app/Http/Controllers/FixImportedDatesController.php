<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FixImportedDatesController extends Controller
{
    // Any row imported via the Nivessa Backend xlsx with a transaction_date
    // in the future is bad data (xlsx is from April 2026; nothing later than
    // Oct 2025 is real). We rewrite those dates using the sheet-name slug
    // embedded in import_source — e.g. nivessa_backend_sales_pico_oct_25 →
    // 2025-10-01.
    const CUTOFF = '2025-12-31';
    const IMPORT_SOURCE_PREFIX = 'nivessa_backend_sales_';

    public function index()
    {
        return view('admin.fix_imported_dates', [
            'cutoff' => self::CUTOFF,
            'breakdown' => $this->buildBreakdown(),
            'samples' => $this->buildSamples(),
            'mode' => null,
            'updated' => null,
        ]);
    }

    public function run(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $commit = filter_var($request->input('commit'), FILTER_VALIDATE_BOOLEAN);
        $businessId = $request->session()->get('user.business_id');

        $breakdown = $this->buildBreakdown($businessId);
        $updatedTotal = 0;
        $updatedByImport = [];

        foreach ($breakdown as $row) {
            if (!$row['derived_date'] || $row['bad_rows'] === 0) continue;
            if (!$commit) {
                $updatedByImport[$row['import_source']] = 0;
                continue;
            }

            $count = DB::table('transactions')
                ->where('business_id', $businessId)
                ->where('import_source', $row['import_source'])
                ->where('transaction_date', '>', self::CUTOFF . ' 23:59:59')
                ->update([
                    'transaction_date' => $row['derived_date'] . ' 12:00:00',
                    'updated_at' => now(),
                ]);
            $updatedTotal += $count;
            $updatedByImport[$row['import_source']] = $count;
        }

        return view('admin.fix_imported_dates', [
            'cutoff' => self::CUTOFF,
            'breakdown' => $this->buildBreakdown($businessId),
            'samples' => $this->buildSamples($businessId),
            'mode' => $commit ? 'commit' : 'preview',
            'updated' => $updatedByImport,
            'updated_total' => $updatedTotal,
        ]);
    }

    /**
     * Per-import_source counts of bad rows + derived target date.
     */
    private function buildBreakdown($businessId = null)
    {
        $businessId = $businessId ?: request()->session()->get('user.business_id');

        $rows = DB::table('transactions')
            ->select(
                'import_source',
                DB::raw('COUNT(*) as bad_rows'),
                DB::raw('MIN(transaction_date) as min_bad_date'),
                DB::raw('MAX(transaction_date) as max_bad_date')
            )
            ->where('business_id', $businessId)
            ->where('import_source', 'like', self::IMPORT_SOURCE_PREFIX . '%')
            ->where('transaction_date', '>', self::CUTOFF . ' 23:59:59')
            ->groupBy('import_source')
            ->orderByDesc('bad_rows')
            ->get();

        return $rows->map(function ($r) {
            return [
                'import_source' => $r->import_source,
                'sheet_label'   => $this->humanSheetLabel($r->import_source),
                'bad_rows'      => (int) $r->bad_rows,
                'min_bad_date'  => $r->min_bad_date,
                'max_bad_date'  => $r->max_bad_date,
                'derived_date'  => $this->deriveDateFromImportSource($r->import_source),
            ];
        })->all();
    }

    /**
     * 10-row sample of what would change — gives Sarah eyes on before Apply.
     */
    private function buildSamples($businessId = null)
    {
        $businessId = $businessId ?: request()->session()->get('user.business_id');

        $rows = DB::table('transactions')
            ->select('id', 'import_source', 'transaction_date', 'final_total')
            ->where('business_id', $businessId)
            ->where('import_source', 'like', self::IMPORT_SOURCE_PREFIX . '%')
            ->where('transaction_date', '>', self::CUTOFF . ' 23:59:59')
            ->orderBy('id')
            ->limit(10)
            ->get();

        return $rows->map(function ($r) {
            return [
                'id' => $r->id,
                'sheet_label' => $this->humanSheetLabel($r->import_source),
                'current_date' => $r->transaction_date,
                'target_date' => $this->deriveDateFromImportSource($r->import_source),
                'amount' => $r->final_total,
            ];
        })->all();
    }

    private function humanSheetLabel($importSource)
    {
        $label = substr($importSource, strlen(self::IMPORT_SOURCE_PREFIX));
        return strtoupper(str_replace('_', ' ', $label));
    }

    /**
     * Mirror of ImportNivessaHistoricalSales::sheetNameToDate — extract
     * month + year from the sheet-name slug baked into import_source.
     * Returns 'YYYY-MM-01' on success, null otherwise.
     */
    private function deriveDateFromImportSource($importSource)
    {
        $slug = substr($importSource, strlen(self::IMPORT_SOURCE_PREFIX));
        $lower = str_replace('_', ' ', $slug);

        $months = [
            'jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2, 'mar' => 3, 'march' => 3,
            'apr' => 4, 'april' => 4, 'may' => 5, 'jun' => 6, 'june' => 6, 'jul' => 7, 'july' => 7,
            'aug' => 8, 'august' => 8, 'sep' => 9, 'sept' => 9, 'september' => 9,
            'oct' => 10, 'october' => 10, 'nov' => 11, 'november' => 11, 'dec' => 12, 'december' => 12,
        ];

        $month = null;
        foreach ($months as $token => $num) {
            if (preg_match('/\b' . preg_quote($token, '/') . '\b/', $lower)) {
                $month = $num;
                break;
            }
        }

        $year = null;
        if (preg_match('/\b(20\d{2}|2[3-5])\b/', $lower, $m)) {
            $y = (int) $m[1];
            $year = $y < 100 ? 2000 + $y : $y;
        }

        if (!$month || !$year) return null;
        // Safety: refuse to derive a date that is itself in the future.
        $derived = sprintf('%04d-%02d-01', $year, $month);
        if ($derived > self::CUTOFF) return null;
        return $derived;
    }
}
