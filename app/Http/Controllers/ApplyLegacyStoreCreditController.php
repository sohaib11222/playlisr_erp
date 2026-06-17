<?php

namespace App\Http\Controllers;

use App\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Guarded bulk-apply for the 136 legacy store-credit contacts imported from the
// Nivessa Backend xlsx (import_source = nivessa_backend_store_credit). Credits
// were deliberately NOT auto-applied because the source sheet was messy; this
// applies ONLY the "safe" rows — contacts that currently sit at $0 balance and
// have never been credited — so we never double-credit someone who already has
// money on their account.
//
// Flow: Preview (read-only worklist) -> Apply (snapshot BEFORE -> mutate). The
// snapshot lands in storage/admin-snapshots/ with action
// 'apply-legacy-store-credit' so it's reversible at /admin/admin-action-history.
// Apply recomputes the safe list server-side and ignores any posted rows, so a
// stale page can't push a wrong amount.
class ApplyLegacyStoreCreditController extends Controller
{
    const IMPORT_SOURCE = 'nivessa_backend_store_credit';
    const APPLIED_MARKER = 'store-credit +'; // substring written by adjustStoreCredit

    public function preview()
    {
        $safe = $this->buildSafeList();
        $total = array_reduce($safe, function ($c, $r) { return $c + $r['amount']; }, 0.0);

        return view('admin.apply_legacy_store_credit', [
            'rows' => $safe,
            'total' => round($total, 2),
        ]);
    }

    public function apply(Request $request)
    {
        @set_time_limit(0);

        $businessId = $request->session()->get('user.business_id');
        $safe = $this->buildSafeList();
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
                // findOrFail-style re-check INSIDE the txn against live state, so
                // a credit that got applied/spent since the preview is skipped.
                $contact = Contact::where('business_id', $businessId)
                    ->where('import_source', self::IMPORT_SOURCE)
                    ->find($r['contact_id']);
                if (!$contact) { $skipped++; continue; }

                $currentBalance = (float) $contact->balance;
                $alreadyApplied = strpos((string) $contact->balance_notes, self::APPLIED_MARKER) !== false;
                if ($currentBalance > 0.001 || $alreadyApplied) { $skipped++; continue; }

                $delta = round((float) $r['amount'], 2);
                if ($delta < 0.01) { $skipped++; continue; }

                // Snapshot BEFORE state for undo.
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

                // Mirror onto the website backend, same as adjustStoreCredit.
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

            // Write the snapshot only if we actually changed rows.
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

    // Mirror of /admin/store-credit-review's safe-list logic: drive off the
    // tagged contacts, pull each one's amount from the newest CSV that contains
    // them (summed within a file, never across the re-run files), and keep only
    // rows that are safe to credit right now.
    private function buildSafeList(): array
    {
        $files = glob(storage_path('app/imports/nivessa_pending_store_credits_*.csv')) ?: [];
        rsort($files); // newest first

        $amountByContact = [];
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
            foreach ($withinFile as $cid => $amt) {
                if (!isset($amountByContact[$cid])) $amountByContact[$cid] = $amt; // newest wins
            }
        }

        $contacts = DB::table('contacts')
            ->where('import_source', self::IMPORT_SOURCE)
            ->select('id', 'name', 'mobile', 'balance', 'balance_notes')
            ->get();

        $safe = [];
        foreach ($contacts as $c) {
            if (!isset($amountByContact[$c->id])) continue;          // amount unknown
            if ((float) $c->balance > 0.001) continue;               // already has a balance
            if (strpos((string) $c->balance_notes, self::APPLIED_MARKER) !== false) continue; // already applied
            $amount = round($amountByContact[$c->id], 2);
            if ($amount < 0.01) continue;
            $safe[] = [
                'contact_id' => $c->id,
                'name' => $c->name,
                'phone' => $c->mobile,
                'amount' => $amount,
            ];
        }
        return $safe;
    }
}
