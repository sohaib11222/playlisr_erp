<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as PhpDate;

// Re-parses the "In Store New & Used Sales" sheet from the Nivessa Backend
// xlsx and rewrites each transaction's transaction_date to the actual sale
// day from the sheet. Source priority:
//   1. Col A running date — the sheet uses date-separator rows (e.g. row
//      7071 holds the date 2023-10-01) and every item below inherits that
//      date until the next separator. This is the "sale day" per the sheet.
//   2. Col P (Sold Date) — only used as a fallback for rows above the first
//      separator, where there's no running date to inherit.
//
// 1,300 transactions are tagged with this import_source. ~572 already got
// correct col-A dates from the original importer; ~728 got a placeholder
// (04/26/26 in this run). This rewrites both — but skips updates where the
// existing date already matches the target so we don't churn updated_at.
//
// Reuses /admin/nivessa-backend-import/chunk for the upload (xlsx is ~23 MB
// and needs chunked transfer). Snapshot + undo via admin-action-history.
class FixInStoreSoldDatesController extends Controller
{
    const SHEET_NAME    = 'In Store New & Used Sales';
    const IMPORT_SOURCE = 'nivessa_backend_sales_in_store_new_used_sales';
    const COL_SOLD_DATE = 15; // col P, 0-indexed

    public function index()
    {
        return view('admin.fix_in_store_sold_dates', [
            'mode'                => null,
            'session_id'          => null,
            'sheet_row_count'     => 0,
            'tx_total'            => 0,
            'matched_count'       => 0,
            'already_ok'          => 0,
            'unmatched_count'     => 0,
            'updated'             => 0,
            'snapshot_key'        => null,
            'samples'             => [],
            'unmatched_samples'   => [],
            'already_ok_samples'  => [],
            'row_date_samples'    => [],
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

        $matched = [];     // tx_id => 'YYYY-MM-DD' — only when target differs from current
        $alreadyOk = 0;    // ext_id mapped, but tx already has the right date
        $unmatched = [];   // ext_id couldn't be parsed or row had no date
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
            $target = $rowDateMap[$rowNum];
            $currentDateOnly = substr((string) $tx->transaction_date, 0, 10);
            if ($currentDateOnly === $target) {
                $alreadyOk++;
                continue;
            }
            $matched[$tx->id] = $target;
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
                $reason = "xlsx row $rn has no usable date in map";
            }
            $unmatchedSamples[] = [
                'id' => $tx->id,
                'external_id' => $extId,
                'current_date' => $tx->transaction_date,
                'reason' => $reason,
            ];
        }

        // Debug: alreadyOk samples — lets us verify that "already correct"
        // really means current matches a sane target (and not e.g. both being
        // a garbage 1900s date or both being 04/26/26).
        $alreadyOkSamples = [];
        foreach ($existing as $tx) {
            if (count($alreadyOkSamples) >= 10) break;
            if (!preg_match('/^row(\d+)$/', (string) $tx->import_external_id, $m)) continue;
            $rn = (int) $m[1];
            if (!isset($rowDateMap[$rn])) continue;
            $target = $rowDateMap[$rn];
            $cur = substr((string) $tx->transaction_date, 0, 10);
            if ($cur !== $target) continue;
            $alreadyOkSamples[] = [
                'id' => $tx->id,
                'external_id' => $tx->import_external_id,
                'current_date' => $tx->transaction_date,
                'target' => $target,
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
            'mode'                => $commit ? 'commit' : 'preview',
            'session_id'          => $sessionId,
            'sheet_row_count'     => count($rowDateMap),
            'tx_total'            => $existing->count(),
            'matched_count'       => count($matched),
            'already_ok'          => $alreadyOk,
            'unmatched_count'     => count($unmatched),
            'updated'             => $updated,
            'snapshot_key'        => $snapshotKey,
            'samples'             => $samples,
            'unmatched_samples'   => $unmatchedSamples,
            'already_ok_samples'  => $alreadyOkSamples,
            'row_date_samples'    => $rowDateSamples,
        ]);
    }

    /**
     * Walk the In Store sheet and build [xlsx_row_number => 'YYYY-MM-DD'].
     * Mirrors the original importer's currentDate logic: col A separator
     * rows set the running date; every row below inherits it until the
     * next separator. Rows above the first separator fall back to col P
     * (Sold Date) if it has a usable value.
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
        $currentDate = null;
        foreach ($rows as $i => $row) {
            $rowNum = $i + 1; // xlsx is 1-indexed
            $aDate = $this->coerceDate($row[0] ?? null);
            if ($aDate) {
                // Update the running date AND map this row to it. The
                // original importer treated some col-A-date rows as items
                // (when they had item content), giving them the previous
                // day's date by accident. The correct date for those rows
                // is the col-A date itself.
                $currentDate = $aDate;
                $map[$rowNum] = $currentDate;
                continue;
            }
            if ($currentDate) {
                $map[$rowNum] = $currentDate;
            } else {
                // Above first separator — try Sold Date as fallback.
                $sold = $this->coerceDate($row[self::COL_SOLD_DATE] ?? null);
                if ($sold) $map[$rowNum] = $sold;
            }
        }
        return $map;
    }

    /**
     * Only treat a value as a date if it falls in a plausible Nivessa-era
     * range (2020-2030). Without this guard, prices and qty values in col A
     * (like 0.24, 192, 74) get misread as Excel serials → nonsense dates
     * (1900-XX-XX, 1899-12-30) that pollute the running date for hundreds
     * of rows below them.
     */
    private function coerceDate($value)
    {
        if ($value === null || $value === '') return null;
        if (is_object($value) && method_exists($value, 'format')) {
            $iso = $value->format('Y-m-d');
            return $this->withinPlausibleRange($iso) ? $iso : null;
        }
        if (is_numeric($value)) {
            $n = (float) $value;
            // Excel serials: 2020-01-01 ≈ 43831, 2030-12-31 ≈ 47848. Anything
            // outside that band is almost certainly not a real date cell.
            if ($n < 43000 || $n > 48000) return null;
            try {
                $dt = PhpDate::excelToDateTimeObject($n);
                $iso = $dt->format('Y-m-d');
                return $this->withinPlausibleRange($iso) ? $iso : null;
            } catch (\Throwable $e) {
                return null;
            }
        }
        if (is_string($value)) {
            $ts = strtotime($value);
            if ($ts !== false) {
                $iso = date('Y-m-d', $ts);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso) && $this->withinPlausibleRange($iso)) {
                    return $iso;
                }
            }
        }
        return null;
    }

    private function withinPlausibleRange($iso)
    {
        $year = (int) substr($iso, 0, 4);
        return $year >= 2020 && $year <= 2030;
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
