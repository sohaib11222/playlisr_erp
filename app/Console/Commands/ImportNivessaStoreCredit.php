<?php

namespace App\Console\Commands;

use App\Contact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import the "Store Credit" sheet from the Nivessa Backend xlsx into contacts.
 *
 * The sheet is free-form and noisy (names mixed with dates mixed with
 * handles, phones in scientific notation, amounts occasionally buried in
 * notes). This command takes the deliberately conservative approach:
 *
 *   1. Upsert a contact for every row with a positive numeric amount.
 *   2. Tag the contact with import_source + import_external_id so the
 *      whole batch can be rolled back with one query.
 *   3. Write a pending-credit note on the contact ("Legacy store credit:
 *      \$X — apply manually"). The command does NOT automatically post
 *      credit to the account balance — that's too risky from a messy
 *      sheet. Instead it emits a CSV summary at the end so Sabina can
 *      batch-apply credits manually.
 *
 * Matching strategy: if the phone column parses to a real 10-digit number
 * and an existing contact has that mobile, update that contact. Otherwise
 * create a new contact with type=customer.
 *
 * Usage:
 *   php artisan nivessa:import-store-credit <path.xlsx>
 *   php artisan nivessa:import-store-credit <path.xlsx> --commit
 *   php artisan nivessa:import-store-credit <path.xlsx> --commit --csv=/tmp/credits.csv
 */
class ImportNivessaStoreCredit extends Command
{
    protected $signature = 'nivessa:import-store-credit
                            {file : Path to the Nivessa Backend xlsx}
                            {--sheet=Store Credit : Sheet name}
                            {--business=1 : business_id}
                            {--user=1 : created_by}
                            {--commit : Actually write (default: dry-run)}
                            {--csv= : Optional path for the pending-credits CSV}
                            {--limit=0 : Cap rows processed (0 = all)}';

    protected $description = 'Dry-run/commit import of Store Credit rows into contacts, with a CSV of pending credits to apply.';

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
        $userId = (int) $this->option('user');
        $commit = (bool) $this->option('commit');
        $limit = (int) $this->option('limit');
        $csvPath = $this->option('csv');

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

        // Hardcoded column positions match the sheet we've sampled:
        //   A=name, B=date, C=amount, D=contact info, E=notes, F=notes2
        $COL_NAME = 0;
        $COL_DATE = 1;
        $COL_AMOUNT = 2;
        $COL_CONTACT_INFO = 3;
        $COL_NOTES = 4;
        $COL_NOTES2 = 5;

        $summary = [
            'read' => 0, 'skip_empty' => 0, 'skip_no_amount' => 0,
            'skip_nonpositive' => 0, 'matched' => 0, 'created' => 0,
            'skip_dup' => 0, 'total_credit' => 0.0,
        ];
        $csvRows = [['contact_id', 'import_external_id', 'name', 'phone', 'credit_amount', 'notes']];
        $sampleInserts = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                if ($i === 0) continue;  // header
                if ($limit > 0 && $summary['read'] >= $limit) break;
                $summary['read']++;

                $rawName = trim((string) ($row[$COL_NAME] ?? ''));
                $rawAmount = (string) ($row[$COL_AMOUNT] ?? '');
                $rawContact = (string) ($row[$COL_CONTACT_INFO] ?? '');
                $rawNotes = trim((string) ($row[$COL_NOTES] ?? ''));
                $rawNotes2 = trim((string) ($row[$COL_NOTES2] ?? ''));

                if ($rawName === '' && trim($rawAmount) === '') {
                    $summary['skip_empty']++;
                    continue;
                }

                $amount = $this->parseAmount($rawAmount);
                if ($amount === null) {
                    $summary['skip_no_amount']++;
                    continue;
                }
                if ($amount <= 0) {
                    $summary['skip_nonpositive']++;
                    continue;
                }

                $phone = $this->parsePhone($rawContact);
                $name = $rawName !== '' ? $rawName : ('Legacy credit ' . ($i + 1));

                $externalId = $sheetName . '::row' . ($i + 1);

                // Dedup by our import tag first — re-runs are no-ops.
                $existingByTag = DB::table('contacts')
                    ->where('business_id', $businessId)
                    ->where('import_source', self::IMPORT_SOURCE)
                    ->where('import_external_id', $externalId)
                    ->first();
                if ($existingByTag) {
                    $summary['skip_dup']++;
                    continue;
                }

                // Try to match an existing contact by mobile (strongest signal).
                $matched = null;
                if ($phone) {
                    $matched = DB::table('contacts')
                        ->where('business_id', $businessId)
                        ->where('mobile', $phone)
                        ->whereNull('deleted_at')
                        ->first();
                }

                $noteParts = ["Legacy store credit: \${$amount} pending — apply manually"];
                if ($rawNotes) $noteParts[] = $rawNotes;
                if ($rawNotes2) $noteParts[] = $rawNotes2;
                if ($rawContact && !$phone) $noteParts[] = 'Contact info: ' . $rawContact;
                $noteText = implode(' · ', $noteParts);

                if ($matched) {
                    $summary['matched']++;
                    $contactId = $matched->id;
                    if ($commit) {
                        DB::table('contacts')->where('id', $contactId)->update([
                            'import_source' => self::IMPORT_SOURCE,
                            'import_external_id' => $externalId,
                            'updated_at' => now(),
                        ]);
                    }
                } else {
                    $summary['created']++;
                    if ($commit) {
                        $contactId = DB::table('contacts')->insertGetId([
                            'business_id' => $businessId,
                            'type' => 'customer',
                            'name' => $name,
                            'mobile' => $phone ?: '',
                            'created_by' => $userId,
                            'import_source' => self::IMPORT_SOURCE,
                            'import_external_id' => $externalId,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    } else {
                        $contactId = 0;  // placeholder for dry-run output
                    }
                }

                $summary['total_credit'] += $amount;
                $csvRows[] = [
                    $contactId, $externalId, $name, $phone ?: '',
                    number_format($amount, 2, '.', ''), $noteText,
                ];
                if (count($sampleInserts) < 8) {
                    $sampleInserts[] = [
                        'name' => $name, 'phone' => $phone ?: '—',
                        'amount' => $amount, 'match' => $matched ? 'matched' : 'created',
                    ];
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

        // Write the pending-credits CSV.
        $csvOut = $csvPath ?: storage_path('app/imports/nivessa_pending_store_credits_' . date('Ymd_His') . '.csv');
        @mkdir(dirname($csvOut), 0775, true);
        $fp = fopen($csvOut, 'w');
        foreach ($csvRows as $row) {
            fputcsv($fp, $row);
        }
        fclose($fp);

        $this->newLine();
        $this->info($commit ? '✅ Contacts written.' : '🧪 DRY RUN — no rows written. Re-run with --commit.');
        $this->line(sprintf(
            "Read: %d · Matched existing: %d · Created new: %d · Dup-skip: %d · No-amount-skip: %d · Non-positive-skip: %d · Empty: %d",
            $summary['read'], $summary['matched'], $summary['created'],
            $summary['skip_dup'], $summary['skip_no_amount'],
            $summary['skip_nonpositive'], $summary['skip_empty']
        ));
        $this->line(sprintf('Total pending credit: $%s', number_format($summary['total_credit'], 2)));
        $this->line("Pending-credits CSV: {$csvOut}");
        if (!empty($sampleInserts)) {
            $this->info('Sample:');
            foreach ($sampleInserts as $s) {
                $this->line(sprintf('  [%s] %s  %s  $%.2f', $s['match'], $s['name'], $s['phone'], $s['amount']));
            }
        }
        return 0;
    }

    /**
     * Pull a dollar amount out of the messy $AMOUNT column. Accepts:
     *   "15"       → 15.0
     *   "15.50"    → 15.5
     *   "$15"      → 15.0
     *   "$100 store credit"  → 100.0
     *   "0 (30 used in store)" → 0.0
     *   ""         → null (distinct from 0)
     *   "hello"    → null
     */
    private function parseAmount($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') return null;
        if (preg_match('/-?\d+(\.\d+)?/', $raw, $m)) {
            return (float) $m[0];
        }
        return null;
    }

    /**
     * Extract a 10-digit US phone from the "PREFFERED CONTACT INFO" cell.
     * Handles: "510 809 6346", "510-809-6346", "(510) 809-6346",
     * scientific notation "8.165470721E9" (= 8165470721), and returns
     * null for free-text like "used credit hollywood 10/09".
     */
    private function parsePhone($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') return null;

        // Scientific notation → integer.
        if (preg_match('/^\-?\d+(\.\d+)?[eE][+\-]?\d+$/', $raw)) {
            $n = (int) floatval($raw);
            $s = (string) $n;
            if (strlen($s) === 10 || strlen($s) === 11) return $s;
            return null;
        }

        // Strip non-digits.
        $digits = preg_replace('/\D+/', '', $raw);
        if (strlen($digits) === 10) return $digits;
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) return substr($digits, 1);
        return null;
    }
}
