<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as PhpDate;

// Re-parses the "In Store New & Used Sales" sheet from the Nivessa Backend
// xlsx and rewrites each transaction's transaction_date to the actual Sold
// Date (col P) per row — falling back to Bought Date (col F) if Sold Date
// is blank. The original historical-sales importer didn't read either
// column for this sheet, so 728 rows ended up with a placeholder date
// (04/26/26 in this run). This pulls the real per-row date from the xlsx.
//
// Reuses /admin/nivessa-backend-import/chunk for the upload, since the file
// is ~23 MB and needs chunked transfer. The chunk dir is shared.
class FixInStoreSoldDatesController extends Controller
{
    const SHEET_NAME      = 'In Store New & Used Sales';
    const IMPORT_SOURCE   = 'nivessa_backend_sales_in_store_new_used_sales';
    const COL_SOLD_DATE   = 15; // col P, 0-indexed
    const COL_BOUGHT_DATE = 5;  // col F, fallback

    public function index()
    {
        return view('admin.fix_in_store_sold_dates', [
            'mode'              => null,
            'session_id'        => null,
            'sheet_row_count'   => 0,
            'tx_total'          => 0,
            'matched_count'     => 0,
            'unmatched_count'   => 0,
            'updated'           => 0,
            'snapshot_key'      => null,
            'samples'           => [],
            'unmatched_samples' => [],
            'row_date_samples'  => [],
        ]);
    }

    public function run(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '2048M');

        $request->validate(['session_id' => 'required|string']);
        $sessionId  = $this->safeSession($request->input('session_id'));
        $commit     = filter_var($request->input('commit'), FILTER_VALIDATE_BOOLEAN);
        $businessId = $request->session()->get('user.business_id');
        $now        = now();

        $filePath = storage_path('app/nivessa_backend/' . $sessionId . '.xlsx');
        if (!is_file($filePath)) {
            return response('No uploaded file for session ' . $sessionId . '. Upload first.', 400)
                ->header('Content-Type', 'text/plain');
        }

        $rowDateMap = $this->buildRowDateMap($filePath);

        $existing = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('import_source', self::IMPORT_SOURCE)
            ->select('id', 'import_external_id', 'transaction_date')
            ->orderBy('id')
            ->get();

        $matched = [];   // tx_id => 'YYYY-MM-DD'
        $unmatched = []; // tx rows we couldn't match
        foreach ($existing as $tx) {
            if (!preg_match('/^row(\d+)$/', (string) $tx->import_external_id, $m)) {
                $unmatched[] = $tx;
                continue;
            }
            $rowNum = (int) $m[1];
            if (!isset($rowDateMap[$rowNum])) {
                $unmatched[] = $tx;
                continue;
            }
            $matched[$tx->id] = $rowDateMap[$rowNum];
        }

        $snapshotKey = null;
        $updated = 0;

        if ($commit && !empty($matched)) {
            $snapshotRows = [];
            foreach ($existing as $tx) {
                if (!isset($matched[$tx->id])) continue;
                $snapshotRows[] = [
                    'id' => $tx->id,
                    'import_source' => self::IMPORT_SOURCE,
                    'transaction_date' => (string) $tx->transaction_date,
                ];
            }

            $snapshotKey = 'fix-in-store-sold-dates-' . $now->format('Y-m-d_His');
            Storage::disk('local')->put(
                "admin-snapshots/{$snapshotKey}.json",
                json_encode([
                    'timestamp' => $now->toDateTimeString(),
                    'action' => 'fix-in-store-sold-dates',
                    'business_id' => $businessId,
                    'rows' => $snapshotRows,
                ], JSON_PRETTY_PRINT)
            );

            foreach ($matched as $txId => $newDate) {
                DB::table('transactions')
                    ->where('id', $txId)
                    ->update([
                        'transaction_date' => $newDate . ' 12:00:00',
                        'updated_at' => $now,
                    ]);
                $updated++;
            }
        }

        $samples = [];
        foreach ($existing as $tx) {
            if (count($samples) >= 15) break;
            if (!isset($matched[$tx->id])) continue;
            $samples[] = [
                'id' => $tx->id,
                'current_date' => $tx->transaction_date,
                'new_date' => $matched[$tx->id],
            ];
        }

        // Debug: capture sample unmatched external_ids so we can see why the
        // 'row<N>' regex is failing or which rowNums have no xlsx date.
        $unmatchedSamples = [];
        foreach ($unmatched as $tx) {
            if (count($unmatchedSamples) >= 10) break;
            $extId = (string) $tx->import_external_id;
            $reason = '?';
            if (!preg_match('/^row(\d+)$/', $extId)) {
                $reason = 'external_id format unexpected';
            } else {
                preg_match('/^row(\d+)$/', $extId, $m);
                $rn = (int) $m[1];
                $reason = "xlsx row $rn has no Sold/Bought date";
            }
            $unmatchedSamples[] = [
                'id' => $tx->id,
                'external_id' => $extId,
                'current_date' => $tx->transaction_date,
                'reason' => $reason,
            ];
        }

        // Also: show what xlsx rows DO have dates (first 5) so we can compare
        // ranges and spot whether the row-number space is just non-overlapping.
        $rowDateSamples = [];
        $rowKeys = array_keys($rowDateMap);
        sort($rowKeys);
        foreach (array_slice($rowKeys, 0, 5) as $rk) {
            $rowDateSamples[] = ['row' => $rk, 'date' => $rowDateMap[$rk]];
        }
        foreach (array_slice($rowKeys, -5) as $rk) {
            $rowDateSamples[] = ['row' => $rk, 'date' => $rowDateMap[$rk]];
        }

        return view('admin.fix_in_store_sold_dates', [
            'mode'             => $commit ? 'commit' : 'preview',
            'session_id'       => $sessionId,
            'sheet_row_count'  => count($rowDateMap),
            'tx_total'         => $existing->count(),
            'matched_count'    => count($matched),
            'unmatched_count'  => count($unmatched),
            'updated'          => $updated,
            'snapshot_key'     => $snapshotKey,
            'samples'          => $samples,
            'unmatched_samples' => $unmatchedSamples,
            'row_date_samples'  => $rowDateSamples,
        ]);
    }

    /**
     * Walk the In Store sheet and build [xlsx_row_number => 'YYYY-MM-DD'].
     * Sold Date wins; falls back to Bought Date when sold is empty.
     */
    private function buildRowDateMap($filePath)
    {
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([self::SHEET_NAME]);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getSheetByName(self::SHEET_NAME);
        if (!$sheet) return [];

        $rows = $sheet->toArray(null, true, true, false);
        $map = [];
        foreach ($rows as $i => $row) {
            // toArray() with last param false → 0-indexed columns; rows are
            // also 0-indexed. xlsx row number (1-indexed) = $i + 1.
            $rowNum = $i + 1;
            $sold   = $row[self::COL_SOLD_DATE]   ?? null;
            $bought = $row[self::COL_BOUGHT_DATE] ?? null;
            $date = $this->coerceDate($sold) ?: $this->coerceDate($bought);
            if ($date) {
                $map[$rowNum] = $date;
            }
        }
        return $map;
    }

    private function coerceDate($value)
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) {
            try {
                $dt = PhpDate::excelToDateTimeObject((float) $value);
                return $dt->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }
        if (is_object($value) && method_exists($value, 'format')) {
            return $value->format('Y-m-d');
        }
        if (is_string($value)) {
            $ts = strtotime($value);
            if ($ts !== false) {
                $iso = date('Y-m-d', $ts);
                // Reject implausible parses (e.g. random strings).
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso)) return $iso;
            }
        }
        return null;
    }

    private function safeSession($id): string
    {
        $id = (string) $id;
        if (!preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $id)) {
            abort(400, 'invalid session_id');
        }
        return $id;
    }
}
