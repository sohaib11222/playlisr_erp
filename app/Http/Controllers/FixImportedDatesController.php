<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class FixImportedDatesController extends Controller
{
    // Historical xlsx imports populated transactions.transaction_date by
    // walking date-separator rows top-to-bottom. Source typos (e.g. a
    // "9/17/14" entered as "9/17/2014" or "10/7/26" instead of "10/7/24")
    // got carried forward to every item below — so a single typo can drag
    // hundreds of rows to the wrong year.
    //
    // We classify a row as "bad" two ways:
    //   1. Sheet name encodes a year (HW SEP 25 → 2025), or Sarah typed an
    //      override year — any row in that sheet whose YEAR doesn't match
    //      is bad. This catches both 2014-style past strays and 2026-style
    //      future strays.
    //   2. Sheet name has no year and no override (e.g. IN STORE NEW USED
    //      SALES) — fall back to "future-dated" only (> CUTOFF). The page
    //      shows a text input so Sarah can supply a YYYY-MM-DD override
    //      that promotes the sheet into rule 1.
    const CUTOFF = '2025-12-31';
    const IMPORT_SOURCE_PREFIX = 'nivessa_backend_sales_';

    public function index()
    {
        return $this->renderResult(null, [], null, []);
    }

    public function run(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $commit = filter_var($request->input('commit'), FILTER_VALIDATE_BOOLEAN);
        $businessId = $request->session()->get('user.business_id');
        $overrides = $this->normalizeOverrides($request->input('override', []));
        $now = now();

        $breakdown = $this->buildBreakdown($businessId, $overrides);

        $updatedTotal = 0;
        $updatedByImport = [];
        $snapshotKey = null;

        if ($commit) {
            // Snapshot every row we're about to mutate BEFORE any UPDATE so
            // /admin/admin-action-history can roll the rewrite back.
            $snapshotRows = [];
            foreach ($breakdown as $row) {
                if (!$row['target_date'] || $row['bad_rows'] === 0) continue;
                $rows = $this->badRowQuery($businessId, $row)
                    ->get(['id', 'import_source', 'transaction_date']);
                foreach ($rows as $r) {
                    $snapshotRows[] = [
                        'id' => $r->id,
                        'import_source' => $r->import_source,
                        'transaction_date' => (string) $r->transaction_date,
                    ];
                }
            }

            if (!empty($snapshotRows)) {
                $snapshotKey = 'fix-imported-dates-' . $now->format('Y-m-d_His');
                Storage::disk('local')->put(
                    "admin-snapshots/{$snapshotKey}.json",
                    json_encode([
                        'timestamp' => $now->toDateTimeString(),
                        'action' => 'fix-imported-dates',
                        'business_id' => $businessId,
                        'rows' => $snapshotRows,
                    ], JSON_PRETTY_PRINT)
                );
            }

            foreach ($breakdown as $row) {
                if (!$row['target_date'] || $row['bad_rows'] === 0) continue;
                $targetYear = (int) substr($row['target_date'], 0, 4);
                // Year-shift each row independently: preserves the original
                // month/day/time the importer pulled from the xlsx (which is
                // the exact transaction date, just typo'd to the wrong year).
                // 11/11/14 → 11/11/24, 9/13/23 → 9/13/25, etc.
                $count = $this->badRowQuery($businessId, $row)
                    ->update([
                        'transaction_date' => DB::raw("DATE_ADD(transaction_date, INTERVAL ($targetYear - YEAR(transaction_date)) YEAR)"),
                        'updated_at' => $now,
                    ]);
                $updatedTotal += $count;
                $updatedByImport[$row['import_source']] = $count;
            }
        }

        return $this->renderResult(
            $commit ? 'commit' : 'preview',
            ['updated' => $updatedByImport, 'updated_total' => $updatedTotal],
            $snapshotKey,
            $overrides
        );
    }

    private function renderResult($mode, array $extras, $snapshotKey, array $overrides)
    {
        $businessId = request()->session()->get('user.business_id');
        $breakdown = $this->buildBreakdown($businessId, $overrides);

        return view('admin.fix_imported_dates', [
            'cutoff' => self::CUTOFF,
            'breakdown' => $breakdown,
            'samples' => $this->buildSamples($businessId, $breakdown),
            'mode' => $mode,
            'updated' => $extras['updated'] ?? null,
            'updated_total' => $extras['updated_total'] ?? null,
            'snapshot_key' => $snapshotKey,
            'overrides' => $overrides,
        ]);
    }

    /**
     * One row per import_source with at least 1 bad row. Each row carries the
     * derived target (from sheet name) + Sarah's override + the final
     * target_date (override wins). bad_rows is computed using the right
     * predicate for that source's target state.
     */
    private function buildBreakdown($businessId, array $overrides = [])
    {
        $sources = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('import_source', 'like', self::IMPORT_SOURCE_PREFIX . '%')
            ->groupBy('import_source')
            ->pluck('import_source');

        $out = [];
        foreach ($sources as $importSource) {
            $derived = $this->deriveDateFromImportSource($importSource);
            $override = $overrides[$importSource] ?? null;
            $target = $override ?: $derived;

            $rowMeta = [
                'import_source' => $importSource,
                'sheet_label'   => $this->humanSheetLabel($importSource),
                'derived_date'  => $derived,
                'override'      => $override,
                'target_date'   => $target,
                'has_target'    => (bool) $target,
            ];

            $stats = $this->badRowQuery($businessId, $rowMeta)
                ->selectRaw('COUNT(*) as bad_rows, MIN(transaction_date) as min_bad_date, MAX(transaction_date) as max_bad_date')
                ->first();

            $badRows = (int) ($stats->bad_rows ?? 0);
            if ($badRows === 0 && !$override) continue; // hide clean sheets unless Sarah is mid-override

            $out[] = $rowMeta + [
                'bad_rows'     => $badRows,
                'min_bad_date' => $stats->min_bad_date,
                'max_bad_date' => $stats->max_bad_date,
            ];
        }

        usort($out, function ($a, $b) { return $b['bad_rows'] <=> $a['bad_rows']; });
        return $out;
    }

    /**
     * Up to 10 affected rows across all bad sheets — gives Sarah eyes on
     * specific transactions before Apply.
     */
    private function buildSamples($businessId, array $breakdown)
    {
        $samples = [];
        foreach ($breakdown as $row) {
            if (count($samples) >= 10) break;
            if (!$row['has_target'] || $row['bad_rows'] === 0) continue;

            $rows = $this->badRowQuery($businessId, $row)
                ->select('id', 'import_source', 'transaction_date', 'final_total')
                ->orderBy('id')
                ->limit(10 - count($samples))
                ->get();

            $targetYear = (int) substr($row['target_date'], 0, 4);
            foreach ($rows as $r) {
                // Mirror the SQL year-shift for preview: keep month/day/time,
                // replace year only.
                $shifted = null;
                if ($r->transaction_date) {
                    $ts = strtotime($r->transaction_date);
                    if ($ts !== false) {
                        $month = date('m', $ts);
                        $day   = date('d', $ts);
                        $time  = date('H:i:s', $ts);
                        $shifted = sprintf('%04d-%02d-%02d %s', $targetYear, $month, $day, $time);
                    }
                }
                $samples[] = [
                    'id'           => $r->id,
                    'sheet_label'  => $this->humanSheetLabel($r->import_source),
                    'current_date' => $r->transaction_date,
                    'target_date'  => $shifted,
                    'target_year'  => $targetYear,
                    'amount'       => $r->final_total,
                ];
            }
        }
        return $samples;
    }

    /**
     * Bad-row predicate for one import_source:
     *   - has_target: rows whose YEAR(transaction_date) != target_year
     *   - no target: rows dated past CUTOFF (future-only fallback)
     */
    private function badRowQuery($businessId, array $row)
    {
        $q = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('import_source', $row['import_source']);

        if (!empty($row['has_target'])) {
            $year = (int) substr($row['target_date'], 0, 4);
            $start = sprintf('%04d-01-01 00:00:00', $year);
            $end   = sprintf('%04d-01-01 00:00:00', $year + 1);
            $q->where(function ($qq) use ($start, $end) {
                $qq->where('transaction_date', '<', $start)
                   ->orWhere('transaction_date', '>=', $end);
            });
        } else {
            $q->where('transaction_date', '>', self::CUTOFF . ' 23:59:59');
        }

        return $q;
    }

    /**
     * Accept YYYY-MM-DD or YYYY-MM (treat as YYYY-MM-01). Drop garbage and
     * out-of-range entries silently — bad input shouldn't break the page.
     */
    private function normalizeOverrides($input)
    {
        if (!is_array($input)) return [];
        $out = [];
        foreach ($input as $importSource => $value) {
            $value = trim((string) $value);
            if ($value === '') continue;
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                $date = $value;
            } elseif (preg_match('/^\d{4}-\d{2}$/', $value)) {
                $date = $value . '-01';
            } else {
                continue;
            }
            if (strtotime($date) === false) continue;
            // Refuse a future override — same safety as deriveDateFromImportSource.
            if ($date > self::CUTOFF) continue;
            $out[(string) $importSource] = $date;
        }
        return $out;
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
        $derived = sprintf('%04d-%02d-01', $year, $month);
        if ($derived > self::CUTOFF) return null;
        return $derived;
    }
}
