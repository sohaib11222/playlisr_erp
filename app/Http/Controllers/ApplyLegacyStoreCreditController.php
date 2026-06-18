<?php

namespace App\Http\Controllers;

use App\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Review + guarded bulk-apply for the 136 legacy store-credit contacts imported
// from the Nivessa Backend xlsx (import_source = nivessa_backend_store_credit).
// Credits were deliberately NOT auto-applied because the source sheet was messy.
//
// "Safe" = a tagged contact that currently sits at $0 balance, has never been
// credited, has a known amount, AND has no DUPLICATE contact (same phone) that
// already holds a balance. The duplicate check matters because the import made
// brand-new contact rows — the same human may already exist under another id
// with their credit, and applying to the new row would double-issue.
//
// Endpoints:
//   GET  /admin/store-credit-review        JSON review (people + amounts + dup flags)
//   GET  /admin/store-credit-review.csv    same, as a CSV to eyeball offline
//   GET  /admin/apply-legacy-store-credit  preview the safe list
//   POST /admin/apply-legacy-store-credit/run   snapshot -> apply (undoable)
class ApplyLegacyStoreCreditController extends Controller
{
    const IMPORT_SOURCE = 'nivessa_backend_store_credit';
    const APPLIED_MARKER = 'store-credit +'; // substring written by adjustStoreCredit

    public function review()
    {
        [$rows, $perFile] = $this->buildRows();
        $totals = $this->summarize($rows);
        return response()->json([
            'per_file' => $perFile,
            'totals' => $totals,
            'rows' => $this->reviewFirst($rows),
        ], 200, [], JSON_PRETTY_PRINT);
    }

    public function reviewCsv()
    {
        [$rows] = $this->buildRows();
        $rows = $this->reviewFirst($rows);

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['contact_id', 'name', 'phone', 'csv_credit', 'current_balance', 'status', 'flags', 'duplicate_contacts']);
        foreach ($rows as $r) {
            $dupes = array_map(function ($d) {
                return "#{$d['id']} {$d['name']} (\$" . number_format($d['balance'], 2) . ")";
            }, $r['duplicates']);
            fputcsv($out, [
                $r['contact_id'], $r['name'], $r['phone'],
                $r['csv_credit'], $r['current_balance'], $r['status'],
                implode('|', $r['flags']), implode('; ', $dupes),
            ]);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="legacy_store_credit_review_' . now()->format('Ymd_His') . '.csv"',
        ]);
    }

    public function preview()
    {
        [$rows] = $this->buildRows();
        $safe = array_values(array_filter($rows, function ($r) { return $r['status'] === 'safe_to_apply'; }));
        $total = array_reduce($safe, function ($c, $r) { return $c + $r['csv_credit']; }, 0.0);

        return view('admin.apply_legacy_store_credit', [
            'rows' => $safe,
            'total' => round($total, 2),
        ]);
    }

    public function apply(Request $request)
    {
        @set_time_limit(0);

        $businessId = $request->session()->get('user.business_id');
        [$rows] = $this->buildRows();
        $safe = array_values(array_filter($rows, function ($r) { return $r['status'] === 'safe_to_apply'; }));
        if (empty($safe)) {
            return redirect('/admin/apply-legacy-store-credit')
                ->with('status', ['success' => 0, 'msg' => 'Nothing to apply — no contacts are currently safe to credit.']);
        }

        $timestamp = now()->format('Y-m-d_His');
        $snapshotKey = "apply-legacy-store-credit-{$timestamp}";
        $reason = "Legacy store credit apply (batch {$snapshotKey})";

        $snapshotRows = [];
        $applied = 0;
        $skipped = 0;
        $totalApplied = 0.0;

        DB::beginTransaction();
        try {
            foreach ($safe as $r) {
                // Re-check INSIDE the txn against live state, so a credit that
                // got applied/spent since the preview is skipped.
                $contact = Contact::where('business_id', $businessId)
                    ->where('import_source', self::IMPORT_SOURCE)
                    ->find($r['contact_id']);
                if (!$contact) { $skipped++; continue; }

                $currentBalance = (float) $contact->balance;
                $alreadyApplied = strpos((string) $contact->balance_notes, self::APPLIED_MARKER) !== false;
                if ($currentBalance > 0.001 || $alreadyApplied) { $skipped++; continue; }

                $delta = round((float) $r['csv_credit'], 2);
                if ($delta < 0.01) { $skipped++; continue; }

                $snapshotRows[] = [
                    'contact_id'    => $contact->id,
                    'balance'       => (string) $contact->balance,
                    'balance_notes' => $contact->balance_notes,
                    'email'         => $contact->email,
                    'applied_delta' => $delta,
                ];

                $newBalance = round($currentBalance + $delta, 2);
                $stamp = now()->format('Y-m-d H:i');
                $who = auth()->user()->first_name ?? 'admin';
                $line = sprintf(
                    '[%s] store-credit +$%s by %s → new balance $%s. Reason: %s',
                    $stamp, number_format($delta, 2), $who, number_format($newBalance, 2), $reason
                );

                $contact->balance = $newBalance;
                $contact->balance_notes = trim(($contact->balance_notes ?? '') . "\n" . $line);
                $contact->save();

                if (in_array($contact->type, ['customer', 'both']) && !empty($contact->email)) {
                    app(\App\Services\NivessaBackendCreditSyncService::class)->syncDeltaByEmail(
                        (string) $contact->email,
                        $delta,
                        $reason,
                        ['contact_id' => (int) $contact->id, 'action' => 'apply-legacy-store-credit', 'batch' => $snapshotKey]
                    );
                }

                $applied++;
                $totalApplied += $delta;
            }

            if (!empty($snapshotRows)) {
                Storage::disk('local')->put(
                    "admin-snapshots/{$snapshotKey}.json",
                    json_encode([
                        'timestamp'   => $timestamp,
                        'action'      => 'apply-legacy-store-credit',
                        'user_id'     => auth()->id(),
                        'business_id' => $businessId,
                        'total'       => round($totalApplied, 2),
                        'rows'        => $snapshotRows,
                    ], JSON_PRETTY_PRINT)
                );
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            \Log::emergency('apply-legacy-store-credit failed: ' . $e->getMessage());
            return redirect('/admin/apply-legacy-store-credit')
                ->with('status', ['success' => 0, 'msg' => 'Aborted, nothing applied: ' . $e->getMessage()]);
        }

        $msg = "Applied \$" . number_format($totalApplied, 2) . " to {$applied} contact(s).";
        if ($skipped > 0) {
            $msg .= " Skipped {$skipped} that changed since preview.";
        }
        if (!empty($snapshotRows)) {
            $msg .= " Undo at /admin/admin-action-history (snapshot {$snapshotKey}).";
        }
        return redirect('/admin/apply-legacy-store-credit')
            ->with('status', ['success' => 1, 'msg' => $msg]);
    }

    // ---- shared analysis -------------------------------------------------

    // Returns [rows, perFile]. Each row carries the contact, its CSV amount,
    // current balance, duplicate contacts (same phone, other id), flags, and a
    // computed status (safe_to_apply | review).
    private function buildRows(): array
    {
        [$amountByContact, $perFile] = $this->loadAmounts();

        $contacts = DB::table('contacts')
            ->where('import_source', self::IMPORT_SOURCE)
            ->select('id', 'name', 'mobile', 'balance', 'balance_notes')
            ->get();

        $taggedIds = $contacts->pluck('id')->all();
        $phones = $contacts->pluck('mobile')->filter(function ($p) {
            return trim((string) $p) !== '';
        })->unique()->values()->all();

        // Other contacts (NOT in the tagged set) that share a phone number —
        // these are likely the customer's pre-existing record.
        $dupByPhone = [];
        if (!empty($phones)) {
            $others = DB::table('contacts')
                ->whereIn('mobile', $phones)
                ->whereNotIn('id', $taggedIds)
                ->select('id', 'name', 'mobile', 'balance')
                ->get();
            foreach ($others as $o) {
                $dupByPhone[$o->mobile][] = [
                    'id' => $o->id,
                    'name' => $o->name,
                    'balance' => round((float) $o->balance, 2),
                ];
            }
        }

        $rows = [];
        foreach ($contacts as $c) {
            $amount = isset($amountByContact[$c->id]) ? round($amountByContact[$c->id], 2) : null;
            $currentBalance = round((float) $c->balance, 2);
            $alreadyApplied = strpos((string) $c->balance_notes, self::APPLIED_MARKER) !== false;
            $hasBalance = $currentBalance > 0.001;

            $phone = trim((string) $c->mobile);
            $dupes = ($phone !== '' && isset($dupByPhone[$c->mobile])) ? $dupByPhone[$c->mobile] : [];
            $dupHasCredit = false;
            foreach ($dupes as $d) {
                if ($d['balance'] > 0.001) { $dupHasCredit = true; break; }
            }

            $flags = [];
            if ($alreadyApplied) $flags[] = 'already_applied';
            if ($hasBalance) $flags[] = 'CAUTION_already_has_balance';
            if ($amount === null) $flags[] = 'amount_unknown';
            if (!empty($dupes)) $flags[] = 'possible_duplicate_contact';
            if ($dupHasCredit) $flags[] = 'CAUTION_duplicate_already_has_credit';

            $safe = !$alreadyApplied && !$hasBalance && $amount !== null && !$dupHasCredit;

            $rows[] = [
                'contact_id' => $c->id,
                'name' => $c->name,
                'phone' => $phone,
                'csv_credit' => $amount,
                'current_balance' => $currentBalance,
                'status' => $safe ? 'safe_to_apply' : 'review',
                'flags' => $flags,
                'duplicates' => $dupes,
            ];
        }
        return [$rows, $perFile];
    }

    // Sum each contact's rows WITHIN a CSV file, then take the value from the
    // newest file that contains them (the folder holds several re-runs; summing
    // across files would multi-count). Returns [byContact, perFileSummary].
    private function loadAmounts(): array
    {
        $files = glob(storage_path('app/imports/nivessa_pending_store_credits_*.csv')) ?: [];
        rsort($files); // newest first

        $byContact = [];
        $perFile = [];
        foreach ($files as $f) {
            if (($fh = fopen($f, 'r')) === false) continue;
            $header = fgetcsv($fh);
            $withinFile = [];
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) !== count($header)) continue;
                $r = array_combine($header, $row);
                $cid = (int) ($r['contact_id'] ?? 0);
                if (!$cid) continue;
                $withinFile[$cid] = ($withinFile[$cid] ?? 0.0) + (float) ($r['credit_amount'] ?? 0);
            }
            fclose($fh);

            $fileTotal = 0.0;
            foreach ($withinFile as $cid => $amt) {
                $fileTotal += $amt;
                if (!isset($byContact[$cid])) $byContact[$cid] = $amt; // newest wins
            }
            $perFile[] = ['file' => basename($f), 'contacts' => count($withinFile), 'total' => round($fileTotal, 2)];
        }
        return [$byContact, $perFile];
    }

    private function summarize(array $rows): array
    {
        $t = ['tagged' => count($rows), 'safe_to_apply' => 0, 'already_applied' => 0,
              'already_has_balance' => 0, 'amount_unknown' => 0,
              'possible_duplicate_contact' => 0, 'duplicate_already_has_credit' => 0,
              'csv_total_known' => 0.0, 'safe_total' => 0.0];
        foreach ($rows as $r) {
            if ($r['csv_credit'] !== null) $t['csv_total_known'] += $r['csv_credit'];
            if (in_array('already_applied', $r['flags'], true)) $t['already_applied']++;
            if (in_array('CAUTION_already_has_balance', $r['flags'], true)) $t['already_has_balance']++;
            if (in_array('amount_unknown', $r['flags'], true)) $t['amount_unknown']++;
            if (in_array('possible_duplicate_contact', $r['flags'], true)) $t['possible_duplicate_contact']++;
            if (in_array('CAUTION_duplicate_already_has_credit', $r['flags'], true)) $t['duplicate_already_has_credit']++;
            if ($r['status'] === 'safe_to_apply') { $t['safe_to_apply']++; $t['safe_total'] += $r['csv_credit']; }
        }
        $t['csv_total_known'] = round($t['csv_total_known'], 2);
        $t['safe_total'] = round($t['safe_total'], 2);
        return $t;
    }

    // Sort needs-review rows to the top so the risky ones are seen first.
    private function reviewFirst(array $rows): array
    {
        usort($rows, function ($a, $b) {
            return ($a['status'] === 'safe_to_apply' ? 1 : 0) <=> ($b['status'] === 'safe_to_apply' ? 1 : 0);
        });
        return $rows;
    }
}
