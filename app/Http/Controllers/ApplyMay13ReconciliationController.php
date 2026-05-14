<?php

namespace App\Http\Controllers;

use App\Transaction;
use App\TransactionPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One-off action: apply Sarah's 2026-05-13 register reconciliation
 * findings to the actual ERP rows.
 *
 *   #18694 (Hollywood, luis)        — flip payment method CARD → CASH
 *                                      (customer paid $40 cash, miskeyed)
 *   #18696 ↔ Clover VN2H4Y21M170M    — manual match (post-midnight ERP ring
 *                                      for Interpol pair, $43.90)
 *   #18680 (Pico, Clark)             — save register-reconciliation note
 *                                      explaining the exchange (RAM full
 *                                      price rung, only $4 difference
 *                                      actually collected on Clover NKV34)
 *   Bonnie Raitt orphan QN6AFFVTSP6VR — save note (Clark used Sarah's POS
 *                                       session; needs to be backdated-rung
 *                                       through /pos as a normal sale)
 *
 * Preview screen lists each action with its current state. Apply writes a
 * single combined snapshot to admin-snapshots/may-13-reconciliation-*.json
 * so the whole thing is undoable via /admin/admin-action-history.
 */
class ApplyMay13ReconciliationController extends Controller
{
    public function index(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $plan = $this->buildPlan($business_id);

        return view('admin.apply_may_13_reconciliation', [
            'plan' => $plan,
            'mode' => 'preview',
            'snapshot_key' => null,
            'applied' => null,
        ]);
    }

    public function apply(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $now = \Carbon\Carbon::now();
        $plan = $this->buildPlan($business_id);

        // --- Snapshot BEFORE state ---------------------------------------
        $snapshotKey = 'may-13-reconciliation-' . $now->format('Y-m-d_His');
        $snapshot = [
            'timestamp' => $now->toDateTimeString(),
            'action' => 'may-13-reconciliation',
            'business_id' => $business_id,
            'rows' => [],
        ];

        // --- 1. #18694 payment method override ---------------------------
        $applied = ['payment_overrides' => [], 'matches' => [], 'notes' => []];

        if ($plan['p1_payment_override']['tx_id']) {
            $payments = TransactionPayment::where('transaction_id', $plan['p1_payment_override']['tx_id'])
                ->get(['id', 'method', 'amount', 'card_transaction_number']);
            foreach ($payments as $p) {
                $snapshot['rows'][] = [
                    'kind' => 'transaction_payment_method',
                    'transaction_payment_id' => $p->id,
                    'transaction_id' => $plan['p1_payment_override']['tx_id'],
                    'old_method' => $p->method,
                    'new_method' => 'cash',
                    'amount' => (float) $p->amount,
                    'card_transaction_number' => $p->card_transaction_number,
                ];
            }
        }

        // --- 2. Manual match #18696 ↔ Clover VN2H4Y21M170M --------------
        $manualMatchBefore = SellPosController::loadCloverManualMatches($business_id);
        if ($plan['p2_manual_match']['cp_db_id'] && $plan['p2_manual_match']['tx_id']) {
            $snapshot['rows'][] = [
                'kind' => 'clover_manual_match',
                'clover_payment_db_id' => $plan['p2_manual_match']['cp_db_id'],
                'clover_payment_id' => $plan['p2_manual_match']['cp_payment_id'],
                'old_transaction_id' => $manualMatchBefore[$plan['p2_manual_match']['cp_db_id']] ?? null,
                'new_transaction_id' => $plan['p2_manual_match']['tx_id'],
            ];
        }

        // Write the snapshot before doing ANY mutation.
        Storage::disk('local')->put(
            "admin-snapshots/{$snapshotKey}.json",
            json_encode($snapshot, JSON_PRETTY_PRINT)
        );

        // --- Mutations ---------------------------------------------------
        DB::transaction(function () use ($plan, $business_id, $now, &$applied) {

            // 1) Flip payment method to cash on #18694.
            if ($plan['p1_payment_override']['tx_id']) {
                $txId = $plan['p1_payment_override']['tx_id'];
                $count = TransactionPayment::where('transaction_id', $txId)
                    ->update([
                        'method' => 'cash',
                        'card_transaction_number' => null,
                        'updated_at' => $now,
                    ]);
                $applied['payment_overrides'][] = [
                    'tx_id' => $txId,
                    'invoice_no' => $plan['p1_payment_override']['invoice_no'],
                    'rows_updated' => $count,
                ];
            }

            // 2) Manual match #18696 ↔ Clover.
            if ($plan['p2_manual_match']['cp_db_id'] && $plan['p2_manual_match']['tx_id']) {
                $map = SellPosController::loadCloverManualMatches($business_id);
                $map[$plan['p2_manual_match']['cp_db_id']] = $plan['p2_manual_match']['tx_id'];
                SellPosController::saveCloverManualMatches($business_id, $map);
                $applied['matches'][] = [
                    'cp_payment_id' => $plan['p2_manual_match']['cp_payment_id'],
                    'tx_id' => $plan['p2_manual_match']['tx_id'],
                    'invoice_no' => $plan['p2_manual_match']['invoice_no'],
                ];
            }
        });

        // 3 + 4) Reconciliation notes (JSON, outside the DB txn).
        foreach ($plan['p3_notes'] as $note) {
            SellPosController::appendCloverExplanation($business_id, [
                'business_id' => $business_id,
                'transaction_id' => $note['tx_id'] ?? null,
                'clover_payment_id' => $note['cp_db_id'] ?? null,
                'discrepancy_type' => $note['discrepancy_type'],
                'reason' => $note['reason'],
                'source' => 'register_reconciliation',
                'explained_by' => (int) auth()->id() ?: null,
                'created_at' => $now->toDateTimeString(),
            ]);
            $applied['notes'][] = $note;
        }

        return view('admin.apply_may_13_reconciliation', [
            'plan' => $this->buildPlan($business_id),
            'mode' => 'commit',
            'snapshot_key' => $snapshotKey,
            'applied' => $applied,
        ]);
    }

    /**
     * Build the concrete plan (resolve invoice numbers + Clover payment
     * IDs to DB row IDs). Anything that can't be resolved gets null and
     * the apply step skips it gracefully.
     */
    private function buildPlan(int $business_id): array
    {
        // #18694 — Hollywood, luis casanova, Harry Nilsson — was cash $40.
        $tx18694 = Transaction::where('business_id', $business_id)
            ->where('invoice_no', '18694')
            ->select('id', 'invoice_no', 'final_total', 'transaction_date')
            ->first();

        // #18696 — Hollywood, luis, Interpol pair, $43.90, post-midnight.
        $tx18696 = Transaction::where('business_id', $business_id)
            ->where('invoice_no', '18696')
            ->select('id', 'invoice_no', 'final_total', 'transaction_date')
            ->first();

        // Clover VN2H4Y21M170M — $43.90 Hollywood swipe at 10:00pm 5/13.
        $cpInterpol = \App\CloverPayment::where('business_id', $business_id)
            ->where('clover_payment_id', 'VN2H4Y21M170M')
            ->select('id', 'clover_payment_id', 'amount', 'paid_at')
            ->first();

        // #18680 — Pico, Clark, Daft Punk RAM, exchange ring.
        $tx18680 = Transaction::where('business_id', $business_id)
            ->where('invoice_no', '18680')
            ->select('id', 'invoice_no', 'final_total', 'transaction_date')
            ->first();

        // Clover NKV34AZFNRWKJ order / 53SP1HEY9A58R payment — the $4.39
        // exchange-difference charge that pairs with #18680.
        $cpExchange = \App\CloverPayment::where('business_id', $business_id)
            ->where('clover_payment_id', '53SP1HEY9A58R')
            ->select('id', 'clover_payment_id', 'amount', 'paid_at')
            ->first();

        // Clover QN6AFFVTSP6VR — Bonnie Raitt $8.78 Clover-only at Pico
        // 4:05pm — Clark rang on Sarah's session, never made it to ERP.
        $cpBonnieRaitt = \App\CloverPayment::where('business_id', $business_id)
            ->where(function ($q) {
                $q->where('clover_order_id', 'QN6AFFVTSP6VR')
                  ->orWhere('clover_payment_id', 'QN6AFFVTSP6VR');
            })
            ->select('id', 'clover_payment_id', 'clover_order_id', 'amount', 'paid_at')
            ->first();

        $plan = [
            'p1_payment_override' => [
                'tx_id' => $tx18694->id ?? null,
                'invoice_no' => $tx18694->invoice_no ?? '18694',
                'amount' => $tx18694->final_total ?? null,
                'reason' => 'Customer paid $40 cash; cashier (luis) miskeyed as card.',
            ],
            'p2_manual_match' => [
                'cp_db_id' => $cpInterpol->id ?? null,
                'cp_payment_id' => $cpInterpol->clover_payment_id ?? 'VN2H4Y21M170M',
                'tx_id' => $tx18696->id ?? null,
                'invoice_no' => $tx18696->invoice_no ?? '18696',
                'amount' => $cpInterpol->amount ?? null,
                'reason' => 'luis rang Interpol pair after midnight (#18696, 12:05am); manual match across the date boundary.',
            ],
            'p3_notes' => array_values(array_filter([
                $tx18680 ? [
                    'tx_id' => $tx18680->id,
                    'cp_db_id' => $cpExchange->id ?? null,
                    'discrepancy_type' => 'no_clover',
                    'invoice_no' => $tx18680->invoice_no,
                    'short' => 'Daft Punk RAM exchange (Clark)',
                    'reason' => 'Exchange. Customer returned a different Daft Punk record and took Random Access Memories. Clark collected the $4 difference on Clover (NKV34AZFNRWKJ / 53SP1HEY9A58R, $4.39 inc tax). Clark wasn\'t trained on refunds and Jon was out, so he rang #18680 at full $47.19 in ERP. Not a real mismatch — actual money collected was $4.39. Train Clark on refund/exchange workflow.',
                ] : null,
                $cpBonnieRaitt ? [
                    'cp_db_id' => $cpBonnieRaitt->id,
                    'discrepancy_type' => 'no_erp',
                    'invoice_no' => null,
                    'short' => 'Bonnie Raitt s/t (Clark on Sarah\'s session)',
                    'reason' => 'Bonnie Raitt s/t, $8.00 + $0.78 tax = $8.78 charged on Clover (QN6AFFVTSP6VR). Clark rang on Sarah\'s POS session because he hadn\'t logged out yet (shows as cashier "Zakary Baller"). TODO: ring this through /pos backdated to 2026-05-13 4:05pm, cashier Clark, to bring inventory in line.',
                ] : null,
            ])),
        ];

        return $plan;
    }
}
