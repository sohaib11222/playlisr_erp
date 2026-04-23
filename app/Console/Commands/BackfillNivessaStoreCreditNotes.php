<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Backfill contacts.balance_notes for every contact that was imported from
 * the Nivessa Backend "Store Credit" sheet, using the free-text notes that
 * were scattered across columns D–W of the sheet.
 *
 * ImportNivessaStoreCredit created the contacts and wrote a pending-credits
 * CSV, but it never persisted the "how they got the credit" notes onto the
 * contact record itself — so if you open a contact in the ERP you can't see
 * why the legacy credit exists. This command reads the sheet a second time,
 * re-builds the same import_external_id used during import
 * ("Store Credit::row{N}"), and appends a single legacy audit line to
 * balance_notes for the matching contact. The line format matches the style
 * used by ContactController::adjustStoreCredit() so balance_notes reads as
 * one coherent history:
 *
 *     [legacy 2023-05-25] imported store-credit $15.00 — source: returned · used 12/22
 *
 * The command is idempotent: it tags each appended line with an "[legacy …]"
 * marker keyed by the import_external_id and skips contacts whose
 * balance_notes already contains that marker. Re-runs are no-ops.
 *
 * Usage:
 *   php artisan nivessa:backfill-store-credit-notes <path.xlsx>
 *   php artisan nivessa:backfill-store-credit-notes <path.xlsx> --commit
 *   php artisan nivessa:backfill-store-credit-notes <path.xlsx> --commit --overwrite
 */
class BackfillNivessaStoreCreditNotes extends Command
{
    protected $signature = 'nivessa:backfill-store-credit-notes
                            {file : Path to the Nivessa Backend xlsx}
                            {--sheet=Store Credit : Sheet name}
                            {--business=1 : business_id}
                            {--commit : Actually write (default: dry-run)}
                            {--overwrite : Replace balance_notes instead of appending}
                            {--limit=0 : Cap rows processed (0 = all)}';

    protected $description = 'Backfill balance_notes on store-credit contacts with how they got the credit, from the Nivessa xlsx.';

    const IMPORT_SOURCE = 'nivessa_backend_store_credit';

    public function handle()
    {
        $path = $this->argument('file');
        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return 1;
        }
        $sheetName = $this->option('sheet');
        $businessId = (int) $this->option('business');
        $commit = (bool) $this->option('commit');
        $overwrite = (bool) $this->option('overwrite');
        $limit = (int) $this->option('limit');

        $this->info("Loading {$path} (sheet: {$sheetName})…");
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName($sheetName);
        if (!$sheet) {
            $this->error("Sheet '{$sheetName}' not found.");
            return 1;
        }
        $rows = $sheet->toArray(null, true, false, false);
        if (empty($rows)) {
            $this->warn('Empty sheet.');
            return 0;
        }

        // Column layout matches ImportNivessaStoreCredit.
        //   A=name, B=date, C=amount, D=contact info (phone *or* stray note),
        //   E..W=free-text notes (most volume lives in E, a long tail in F+).
        $COL_NAME = 0;
        $COL_DATE = 1;
        $COL_AMOUNT = 2;
        $COL_CONTACT_INFO = 3;
        $COL_NOTES_START = 4;       // column E
        $COL_NOTES_END_INCLUSIVE = 22;  // column W — longest row in the sample

        $summary = [
            'read' => 0, 'no_notes' => 0, 'no_match' => 0,
            'already_backfilled' => 0, 'updated' => 0,
        ];
        $samples = [];
        $unmatched = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                if ($i === 0) continue;  // header
                if ($limit > 0 && $summary['read'] >= $limit) break;

                $rawName = trim((string) ($row[$COL_NAME] ?? ''));
                $rawAmount = trim((string) ($row[$COL_AMOUNT] ?? ''));
                if ($rawName === '' && $rawAmount === '') continue;  // empty row

                $summary['read']++;

                $rawDate = $row[$COL_DATE] ?? null;
                $rawContact = trim((string) ($row[$COL_CONTACT_INFO] ?? ''));

                // Collect free-text note fragments from every column E..W,
                // plus the contact-info cell when it's *not* a phone (real
                // sheet does this: "used credit hollywood 10/09" lives in D).
                $fragments = [];
                if ($rawContact !== '' && !$this->looksLikePhone($rawContact)) {
                    $fragments[] = $rawContact;
                }
                for ($c = $COL_NOTES_START; $c <= $COL_NOTES_END_INCLUSIVE; $c++) {
                    $v = trim((string) ($row[$c] ?? ''));
                    if ($v !== '') $fragments[] = $v;
                }

                if (empty($fragments)) {
                    $summary['no_notes']++;
                    continue;
                }

                $externalId = $sheetName . '::row' . ($i + 1);

                $contact = DB::table('contacts')
                    ->where('business_id', $businessId)
                    ->where('import_source', self::IMPORT_SOURCE)
                    ->where('import_external_id', $externalId)
                    ->whereNull('deleted_at')
                    ->first();

                if (!$contact) {
                    $summary['no_match']++;
                    if (count($unmatched) < 10) {
                        $unmatched[] = "row " . ($i + 1) . ": " . ($rawName !== '' ? $rawName : '(no name)');
                    }
                    continue;
                }

                // Idempotency key: the unique externalId ("Store Credit::row5")
                // appears verbatim in every backfilled line, so we can detect
                // re-runs without a separate audit column.
                $existingNotes = (string) ($contact->balance_notes ?? '');
                if (!$overwrite && strpos($existingNotes, $externalId) !== false) {
                    $summary['already_backfilled']++;
                    continue;
                }

                $datePart = $this->formatDate($rawDate);
                $amountPart = $this->formatAmount($rawAmount);
                $sourceText = $this->cleanFragments($fragments);

                $line = sprintf(
                    '[legacy %s · %s] imported store-credit%s — source: %s',
                    $datePart,
                    $externalId,
                    $amountPart !== '' ? ' ' . $amountPart : '',
                    $sourceText
                );

                $newNotes = $overwrite
                    ? $line
                    : trim($existingNotes === '' ? $line : $existingNotes . "\n" . $line);

                $summary['updated']++;
                if (count($samples) < 8) {
                    $samples[] = [
                        'row' => $i + 1,
                        'name' => $rawName,
                        'line' => $line,
                    ];
                }

                if ($commit) {
                    DB::table('contacts')
                        ->where('id', $contact->id)
                        ->update([
                            'balance_notes' => $newNotes,
                            'updated_at' => now(),
                        ]);
                }
            }

            if ($commit) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Aborted: ' . $e->getMessage());
            return 1;
        }

        $this->newLine();
        $this->info($commit ? '✅ balance_notes backfilled.' : '🧪 DRY RUN — no rows written. Re-run with --commit.');
        $this->line(sprintf(
            'Read: %d · Updated: %d · Already-backfilled: %d · No-notes: %d · No-contact-match: %d',
            $summary['read'], $summary['updated'], $summary['already_backfilled'],
            $summary['no_notes'], $summary['no_match']
        ));

        if (!empty($samples)) {
            $this->info('Sample lines:');
            foreach ($samples as $s) {
                $this->line(sprintf('  row %d · %s', $s['row'], $s['name']));
                $this->line('    ' . $s['line']);
            }
        }
        if (!empty($unmatched)) {
            $this->warn('Unmatched rows (no contact with that import_external_id):');
            foreach ($unmatched as $u) {
                $this->line('  ' . $u);
            }
            if ($summary['no_match'] > count($unmatched)) {
                $this->line('  …' . ($summary['no_match'] - count($unmatched)) . ' more');
            }
        }
        return 0;
    }

    /**
     * Return true when the D-column cell is plausibly a phone number rather
     * than a free-text note. Mirrors ImportNivessaStoreCredit::parsePhone()
     * so fragments end up in the same place they did during the original
     * import (phones → contact record, anything else → notes).
     */
    private function looksLikePhone(string $raw): bool
    {
        if (preg_match('/^\-?\d+(\.\d+)?[eE][+\-]?\d+$/', $raw)) {
            return true;
        }
        $digits = preg_replace('/\D+/', '', $raw);
        if (strlen($digits) === 10) return true;
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) return true;
        return false;
    }

    /**
     * The DATE column is a mix of Excel serials (parsed by phpspreadsheet as
     * numbers), date strings, and free-text like "8/14 raines". Return the
     * best ISO-ish form we can, or '—' when it's unusable.
     */
    private function formatDate($raw): string
    {
        if ($raw === null || $raw === '') return '—';
        if (is_numeric($raw)) {
            try {
                $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $raw);
                return $dt->format('Y-m-d');
            } catch (\Throwable $e) {
                // fall through to string path
            }
        }
        $raw = trim((string) $raw);
        if ($raw === '') return '—';
        $ts = strtotime($raw);
        if ($ts !== false) return date('Y-m-d', $ts);
        return $raw;  // keep user-typed text as-is (e.g., "8/14 raines")
    }

    /**
     * Best-effort "$X.XX" rendering of the amount cell. The cell is often
     * noisy ("0 (30 used in store)", "$100 store credit") so we extract the
     * first decimal and present it as currency. Returns '' when there's no
     * number at all, letting the caller omit that clause entirely.
     */
    private function formatAmount($raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '') return '';
        if (preg_match('/-?\d+(\.\d+)?/', $raw, $m)) {
            return '$' . number_format((float) $m[0], 2);
        }
        return '';
    }

    /**
     * Collapse whitespace/newlines in each fragment and join with " · " so
     * the line renders as one legible sentence in the contact drawer.
     */
    private function cleanFragments(array $fragments): string
    {
        $out = [];
        foreach ($fragments as $f) {
            $f = preg_replace('/\s+/', ' ', (string) $f);
            $f = trim($f);
            // Strip the "//" marker Sarah uses as a shorthand for "spent/used".
            $f = ltrim($f, '/');
            $f = trim($f);
            if ($f !== '') $out[] = $f;
        }
        return implode(' · ', $out);
    }
}
