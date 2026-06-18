<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Post the legacy "Store Credit" balances onto contacts.
 *
 * ImportNivessaStoreCredit created/matched a contact for every row in the
 * Store Credit sheet and tagged it with import_source + import_external_id,
 * but it deliberately did NOT touch contacts.balance — posting real money
 * from a messy sheet was left for a separate, reviewed step. This is that
 * step.
 *
 * Input is the cleaned CSV (legacy_store_credit_WITH_DATES.csv) whose
 * columns are:  external_id, date, name, amount, contact_info
 * where external_id is exactly the import_external_id written during import
 * ("Store Credit::row{N}").
 *
 * Behaviour (per the store owner's instructions):
 *   - SET balance = amount (overwrite, not add). Idempotent — safe to re-run.
 *   - Apply every row's amount as-is; duplicates (e.g. solomon dow entered
 *     twice) resolve naturally because it's a set, not an add.
 *   - FLAG any contact whose current balance is already non-zero, so the
 *     owner can review before a value gets overwritten. In --commit mode
 *     these are still applied (a set is intentional) but listed loudly.
 *   - ERP only: does NOT push to the Nivessa backend. A legacy backfill of
 *     credits that originated in that backend must not be mirrored back.
 *
 * Matching, strongest signal first:
 *   1. import_external_id (+ import_source)  — exact, set during import.
 *   2. exact case-insensitive name           — only if unique.
 *   3. parsed 10-digit phone vs mobile        — only if unique.
 * Rows that match nothing are reported as unmatched (run the import first).
 *
 * Usage:
 *   php artisan nivessa:apply-store-credit <path.csv>
 *   php artisan nivessa:apply-store-credit <path.csv> --commit
 *   php artisan nivessa:apply-store-credit <path.csv> --commit --csv=/tmp/applied.csv
 */
class ApplyNivessaStoreCredit extends Command
{
    protected $signature = 'nivessa:apply-store-credit
                            {file : Path to the cleaned store-credit CSV}
                            {--business=1 : business_id}
                            {--commit : Actually write balances (default: dry-run)}
                            {--csv= : Optional path for the applied-credits report CSV}
                            {--limit=0 : Cap rows processed (0 = all)}';

    protected $description = 'Set contacts.balance from the legacy Store Credit CSV (overwrite). Dry-run by default. ERP only, no backend sync.';

    const IMPORT_SOURCE = 'nivessa_backend_store_credit';

    public function handle()
    {
        $path = $this->argument('file');
        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return 1;
        }
        $businessId = (int) $this->option('business');
        $commit = (bool) $this->option('commit');
        $limit = (int) $this->option('limit');
        $csvPath = $this->option('csv');

        $fh = fopen($path, 'r');
        if (!$fh) {
            $this->error("Could not open: {$path}");
            return 1;
        }

        $header = fgetcsv($fh);
        if (!$header) {
            $this->error('Empty CSV.');
            fclose($fh);
            return 1;
        }
        // Map header names → index, tolerant of order/whitespace/case.
        $idx = [];
        foreach ($header as $j => $h) {
            $idx[strtolower(trim((string) $h))] = $j;
        }
        foreach (['external_id', 'name', 'amount'] as $req) {
            if (!array_key_exists($req, $idx)) {
                $this->error("CSV missing required column '{$req}'. Found: " . implode(', ', array_keys($idx)));
                fclose($fh);
                return 1;
            }
        }

        $s = [
            'read' => 0, 'skip_no_amount' => 0, 'skip_nonpositive' => 0,
            'skip_no_name' => 0,
            'matched_tag' => 0, 'matched_name' => 0, 'matched_phone' => 0,
            'unmatched' => 0, 'applied' => 0, 'total_credit' => 0.0,
        ];
        $skippedNoName = [];
        $reportRows = [[
            'external_id', 'name', 'matched_by', 'contact_id', 'contact_name',
            'old_balance', 'new_balance', 'flag',
        ]];
        $unmatched = [];
        $nonZeroFlags = [];

        DB::beginTransaction();
        try {
            $lineNo = 1;
            while (($row = fgetcsv($fh)) !== false) {
                $lineNo++;
                if ($limit > 0 && $s['read'] >= $limit) break;

                $externalId = trim((string) ($row[$idx['external_id']] ?? ''));
                $name = trim((string) ($row[$idx['name']] ?? ''));
                $rawAmount = (string) ($row[$idx['amount']] ?? '');
                $rawContact = isset($idx['contact_info']) ? (string) ($row[$idx['contact_info']] ?? '') : '';
                $rawDate = isset($idx['date']) ? trim((string) ($row[$idx['date']] ?? '')) : '';

                if ($externalId === '' && $name === '' && trim($rawAmount) === '') {
                    continue; // truly blank line
                }
                $s['read']++;

                // Per owner instruction: never apply credit to a row with no
                // name or a single-character name — too ambiguous to trust.
                if (mb_strlen($name) < 2) {
                    $s['skip_no_name']++;
                    $skippedNoName[] = [
                        'external_id' => $externalId,
                        'name' => $name,
                        'amount' => $this->parseAmount($rawAmount),
                    ];
                    continue;
                }

                $amount = $this->parseAmount($rawAmount);
                if ($amount === null) {
                    $s['skip_no_amount']++;
                    continue;
                }
                if ($amount <= 0) {
                    $s['skip_nonpositive']++;
                    continue;
                }

                // --- match, strongest signal first ---
                $contact = null;
                $matchedBy = null;

                if ($externalId !== '') {
                    $contact = DB::table('contacts')
                        ->where('business_id', $businessId)
                        ->where('import_source', self::IMPORT_SOURCE)
                        ->where('import_external_id', $externalId)
                        ->whereNull('deleted_at')
                        ->first();
                    if ($contact) $matchedBy = 'tag';
                }

                if (!$contact && $name !== '') {
                    $byName = DB::table('contacts')
                        ->where('business_id', $businessId)
                        ->whereNull('deleted_at')
                        ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower($name)])
                        ->limit(2)->get();
                    if ($byName->count() === 1) {
                        $contact = $byName->first();
                        $matchedBy = 'name';
                    }
                }

                if (!$contact) {
                    $phone = $this->parsePhone($rawContact);
                    if ($phone) {
                        $byPhone = DB::table('contacts')
                            ->where('business_id', $businessId)
                            ->where('mobile', $phone)
                            ->whereNull('deleted_at')
                            ->limit(2)->get();
                        if ($byPhone->count() === 1) {
                            $contact = $byPhone->first();
                            $matchedBy = 'phone';
                        }
                    }
                }

                if (!$contact) {
                    $s['unmatched']++;
                    $unmatched[] = ['external_id' => $externalId, 'name' => $name, 'amount' => $amount];
                    $reportRows[] = [$externalId, $name, 'UNMATCHED', '', '', '', number_format($amount, 2, '.', ''), 'no contact'];
                    continue;
                }

                $s['matched_' . $matchedBy]++;

                $oldBalance = round((float) ($contact->balance ?? 0), 2);
                $newBalance = round((float) $amount, 2);
                $flag = '';
                if (abs($oldBalance) > 0.009) {
                    $flag = 'NON-ZERO existing balance';
                    $nonZeroFlags[] = [
                        'contact_id' => (int) $contact->id,
                        'name' => (string) $contact->name,
                        'old' => $oldBalance,
                        'new' => $newBalance,
                    ];
                }

                if ($commit) {
                    $stamp = now()->format('Y-m-d H:i');
                    $line = sprintf(
                        '[%s] legacy store-credit set to $%s (was $%s) from spreadsheet%s%s',
                        $stamp,
                        number_format($newBalance, 2),
                        number_format($oldBalance, 2),
                        $externalId !== '' ? ' [' . $externalId . ']' : '',
                        $rawDate !== '' ? ' · sheet date ' . $rawDate : ''
                    );
                    $existingNotes = (string) ($contact->balance_notes ?? '');
                    DB::table('contacts')->where('id', $contact->id)->update([
                        'balance' => $newBalance,
                        'balance_notes' => trim($existingNotes . "\n" . $line),
                        'updated_at' => now(),
                    ]);
                }

                $s['applied']++;
                $s['total_credit'] += $newBalance;
                $reportRows[] = [
                    $externalId, $name, $matchedBy, (int) $contact->id,
                    (string) $contact->name,
                    number_format($oldBalance, 2, '.', ''),
                    number_format($newBalance, 2, '.', ''),
                    $flag,
                ];
            }

            if ($commit) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            fclose($fh);
            $this->error('Aborted (nothing written): ' . $e->getMessage());
            return 1;
        }
        fclose($fh);

        // Write the applied-credits report CSV.
        $csvOut = $csvPath ?: storage_path('app/imports/nivessa_applied_store_credits_' . date('Ymd_His') . '.csv');
        @mkdir(dirname($csvOut), 0775, true);
        $fp = fopen($csvOut, 'w');
        foreach ($reportRows as $r) {
            fputcsv($fp, $r);
        }
        fclose($fp);

        $this->line('');
        $this->info($commit ? '✅ Balances written.' : '🧪 DRY RUN — no balances written. Re-run with --commit.');
        $this->line(sprintf(
            'Read: %d · Applied: %d (tag %d / name %d / phone %d) · Unmatched: %d · No-name-skip: %d · No-amount: %d · Non-positive: %d',
            $s['read'], $s['applied'], $s['matched_tag'], $s['matched_name'],
            $s['matched_phone'], $s['unmatched'], $s['skip_no_name'],
            $s['skip_no_amount'], $s['skip_nonpositive']
        ));
        $this->line(sprintf('Total credit set: $%s', number_format($s['total_credit'], 2)));
        $this->line("Report CSV: {$csvOut}");

        if (!empty($nonZeroFlags)) {
            $this->line('');
            $this->warn('⚠️  ' . count($nonZeroFlags) . ' contact(s) already had a NON-ZERO balance that ' . ($commit ? 'was' : 'would be') . ' overwritten:');
            foreach ($nonZeroFlags as $f) {
                $this->line(sprintf('   #%d %s: $%s → $%s', $f['contact_id'], $f['name'], number_format($f['old'], 2), number_format($f['new'], 2)));
            }
        }

        if (!empty($skippedNoName)) {
            $this->line('');
            $this->warn('⏭️  ' . count($skippedNoName) . ' row(s) skipped — no name or single-character name (not added to DB):');
            foreach ($skippedNoName as $u) {
                $this->line(sprintf('   %s  "%s"  $%s', $u['external_id'], $u['name'], $u['amount'] === null ? '?' : number_format($u['amount'], 2)));
            }
        }

        if (!empty($unmatched)) {
            $this->line('');
            $this->warn('⚠️  ' . count($unmatched) . ' row(s) matched no contact (run nivessa:import-store-credit --commit first, or check name/phone):');
            foreach ($unmatched as $u) {
                $this->line(sprintf('   %s  "%s"  $%.2f', $u['external_id'], $u['name'], $u['amount']));
            }
        }

        return 0;
    }

    /** Pull a positive dollar amount out of the amount cell. "" → null, "hello" → null. */
    private function parseAmount($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') return null;
        if (preg_match('/-?\d+(\.\d+)?/', $raw, $m)) {
            return (float) $m[0];
        }
        return null;
    }

    /** Extract a 10-digit US phone from the contact-info cell; null for free text. */
    private function parsePhone($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') return null;

        if (preg_match('/^\-?\d+(\.\d+)?[eE][+\-]?\d+$/', $raw)) {
            $n = (int) floatval($raw);
            $sn = (string) $n;
            if (strlen($sn) === 10) return $sn;
            if (strlen($sn) === 11 && str_starts_with($sn, '1')) return substr($sn, 1);
            return null;
        }

        // Trailing ".0" from spreadsheet export, then strip non-digits.
        $raw = preg_replace('/\.0+$/', '', $raw);
        $digits = preg_replace('/\D+/', '', $raw);
        if (strlen($digits) === 10) return $digits;
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) return substr($digits, 1);
        return null;
    }
}
