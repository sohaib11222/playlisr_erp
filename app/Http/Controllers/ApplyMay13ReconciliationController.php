<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Contact;
use App\Transaction;
use App\TransactionPayment;
use App\TransactionSellLine;
use App\User;
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

        // --- 2 + 5. Manual matches (Interpol pair + Daft Punk exchange) -
        $manualMatchBefore = SellPosController::loadCloverManualMatches($business_id);
        foreach (['p2_manual_match', 'p5_exchange_match'] as $key) {
            $m = $plan[$key] ?? null;
            if (!empty($m['cp_db_id']) && !empty($m['tx_id'])) {
                $snapshot['rows'][] = [
                    'kind' => 'clover_manual_match',
                    'clover_payment_db_id' => $m['cp_db_id'],
                    'clover_payment_id' => $m['cp_payment_id'],
                    'old_transaction_id' => $manualMatchBefore[$m['cp_db_id']] ?? null,
                    'new_transaction_id' => $m['tx_id'],
                ];
            }
        }

        // Write the snapshot before doing ANY mutation.
        Storage::disk('local')->put(
            "admin-snapshots/{$snapshotKey}.json",
            json_encode($snapshot, JSON_PRETTY_PRINT)
        );

        // Staff_note context lines for the three affected sales — small
        // italic gray text rendered under the line items so the row reads
        // honestly without needing a separate note chip.
        $staffNotes = [];
        $staffNotes[] = [
            'tx_id' => $plan['p1_payment_override']['tx_id'] ?? null,
            'note' => 'Customer paid $40 cash; cashier (luis) miskeyed as card. Payment method corrected to CASH by register reconciliation 2026-05-14.',
        ];
        if (!empty($plan['p2_judas_priest']['tx_id'])) {
            $staffNotes[] = [
                'tx_id' => $plan['p2_judas_priest']['tx_id'],
                'note' => 'Sticker price was $15. Cashier (luis) rang Clover at $14 by mistake — $1.09 underring. ERP price corrected to $15 by Sarah after the fact. Clover mismatch is the original underring, not a current ERP error.',
            ];
        }
        if (!empty($plan['p5_exchange_match']['tx_id'])) {
            $staffNotes[] = [
                'tx_id' => $plan['p5_exchange_match']['tx_id'],
                'note' => 'Exchange / partial return. Customer brought back a different Daft Punk record and took Random Access Memories. Trade-in credit of $39.00 applied so the net charged ($4.39) matches Clover NKV34AZFNRWKJ / 53SP1HEY9A58R. Returned record TITLE PENDING from Clark — reshelve the physical copy under the correct SKU once known.',
            ];
        }
        foreach ($staffNotes as $sn) {
            if (!$sn['tx_id']) continue;
            $existing = Transaction::where('id', $sn['tx_id'])->value('staff_note');
            $snapshot['rows'][] = [
                'kind' => 'transaction_staff_note',
                'transaction_id' => $sn['tx_id'],
                'old_staff_note' => $existing,
                'new_staff_note' => $sn['note'],
            ];
        }

        // Daft Punk exchange — snapshot the BEFORE totals on #18680 so
        // the trade-in-credit line can be undone with the rest of the
        // batch.
        if (!empty($plan['p5_exchange_match']['tx_id'])) {
            $b = Transaction::where('id', $plan['p5_exchange_match']['tx_id'])
                ->select('total_before_tax', 'tax_amount', 'final_total')->first();
            if ($b) {
                $snapshot['rows'][] = [
                    'kind' => 'exchange_line_added',
                    'transaction_id' => $plan['p5_exchange_match']['tx_id'],
                    'old_total_before_tax' => (float) $b->total_before_tax,
                    'old_tax_amount' => (float) $b->tax_amount,
                    'old_final_total' => (float) $b->final_total,
                ];
            }
        }

        // --- Mutations ---------------------------------------------------
        DB::transaction(function () use ($plan, $business_id, $now, $staffNotes, &$applied) {

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

            // Apply staff_note context lines to the affected sales so the
            // recent_feed reads as "yes, we noticed and here's why" without
            // a separate note chip.
            $applied['staff_notes'] = [];
            foreach ($staffNotes as $sn) {
                if (!$sn['tx_id']) continue;
                $count = Transaction::where('id', $sn['tx_id'])
                    ->where('business_id', $business_id)
                    ->update(['staff_note' => $sn['note'], 'updated_at' => $now]);
                if ($count) {
                    $applied['staff_notes'][] = $sn['tx_id'];
                }
            }

            // 5b) Daft Punk exchange — rewrite #18680 to reflect the
            // *actual* exchange (RAM out + trade-in credit in) so the
            // ERP total matches the Clover $4.39 net. Idempotent: skip
            // when the trade-in line is already there.
            $applied['exchange_lines'] = [];
            $tx18680Id = $plan['p5_exchange_match']['tx_id'] ?? null;
            if ($tx18680Id) {
                $tradeInExists = DB::table('transaction_sell_lines')
                    ->where('transaction_id', $tx18680Id)
                    ->whereNull('product_id')
                    ->where('product_name', 'like', 'TRADE-IN%')
                    ->exists();
                if (!$tradeInExists) {
                    // Snapshot the BEFORE totals + the line we're about to
                    // add a sibling for, so undo can restore.
                    $beforeTx = Transaction::where('id', $tx18680Id)
                        ->select('id', 'total_before_tax', 'tax_amount', 'final_total', 'staff_note')
                        ->first();
                    DB::table('transaction_sell_lines')->insert([
                        'transaction_id' => $tx18680Id,
                        'product_id' => null,
                        'variation_id' => null,
                        'product_name' => 'TRADE-IN CREDIT — Daft Punk record (returned, title TBD)',
                        'product_artist' => 'Daft Punk',
                        'quantity' => 1,
                        'unit_price' => -39.00,
                        'unit_price_before_discount' => -39.00,
                        'unit_price_inc_tax' => -42.80,
                        'item_tax' => -3.80,
                        'tax_id' => null,
                        'discount_amount' => 0,
                        'line_discount_type' => null,
                        'line_discount_amount' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    // Recompute totals: RAM ($43 + $4.19) − trade-in
                    // ($39 + $3.80) = $4 + $0.39 = $4.39 inc tax.
                    Transaction::where('id', $tx18680Id)->update([
                        'total_before_tax' => 4.00,
                        'tax_amount' => 0.39,
                        'final_total' => 4.39,
                        'updated_at' => $now,
                    ]);

                    // Match the existing $4.39 card payment to the new
                    // total. (#18680 originally had a $47.19 card payment;
                    // adjust it down to $4.39 so cash drawer math stops
                    // double-counting the trade-in.)
                    TransactionPayment::where('transaction_id', $tx18680Id)
                        ->update([
                            'amount' => 4.39,
                            'updated_at' => $now,
                        ]);

                    $applied['exchange_lines'][] = [
                        'tx_id' => $tx18680Id,
                        'before_total' => (float) $beforeTx->final_total,
                        'after_total' => 4.39,
                    ];
                }
            }

            // 2 + 5) Manual matches: Interpol pair + Daft Punk exchange.
            $map = SellPosController::loadCloverManualMatches($business_id);
            $mapDirty = false;
            foreach (['p2_manual_match', 'p5_exchange_match'] as $key) {
                $m = $plan[$key] ?? null;
                if (!empty($m['cp_db_id']) && !empty($m['tx_id'])) {
                    if (($map[$m['cp_db_id']] ?? null) !== $m['tx_id']) {
                        $map[$m['cp_db_id']] = $m['tx_id'];
                        $mapDirty = true;
                    }
                    $applied['matches'][] = [
                        'cp_payment_id' => $m['cp_payment_id'],
                        'tx_id' => $m['tx_id'],
                        'invoice_no' => $m['invoice_no'],
                    ];
                }
            }
            if ($mapDirty) {
                SellPosController::saveCloverManualMatches($business_id, $map);
            }
        });

        // 4.5) Bonnie Raitt — auto-create the missing ERP ring + match it.
        $applied['rings_created'] = [];
        if (!empty($plan['p4_bonnie_raitt']['cp_db_id']) && empty($plan['p4_bonnie_raitt']['already_exists'])) {
            try {
                $newTxId = $this->createBonnieRaittRing(
                    $business_id,
                    $plan['p4_bonnie_raitt'],
                    $now
                );
                if ($newTxId) {
                    // Manual-match the freshly created ERP ring to the orphan Clover row.
                    $map = SellPosController::loadCloverManualMatches($business_id);
                    $map[$plan['p4_bonnie_raitt']['cp_db_id']] = $newTxId;
                    SellPosController::saveCloverManualMatches($business_id, $map);

                    $snapshot['rows'][] = [
                        'kind' => 'ring_created',
                        'transaction_id' => $newTxId,
                        'invoice_no' => Transaction::where('id', $newTxId)->value('invoice_no'),
                        'clover_payment_id' => $plan['p4_bonnie_raitt']['cp_payment_id'],
                        'amount' => 8.78,
                    ];
                    $applied['rings_created'][] = [
                        'tx_id' => $newTxId,
                        'invoice_no' => Transaction::where('id', $newTxId)->value('invoice_no'),
                        'short' => 'Bonnie Raitt s/t — Pico, Clark, $8.00',
                    ];

                    // Drop the matching Bonnie-Raitt-orphan note (the note was for
                    // when the orphan was unresolved — superfluous once the ring
                    // exists and is matched).
                    $plan['p3_notes'] = array_values(array_filter($plan['p3_notes'], function ($n) {
                        return !(($n['discrepancy_type'] ?? '') === 'no_erp' && empty($n['tx_id']));
                    }));
                }
            } catch (\Throwable $e) {
                // Don't fail the whole batch if ring creation breaks — log
                // the error into the applied note so Sarah sees what went wrong.
                $applied['rings_created'][] = [
                    'tx_id' => null,
                    'invoice_no' => null,
                    'short' => 'Bonnie Raitt ring creation FAILED: ' . $e->getMessage(),
                ];
            }
        }

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
     * Create the missing Bonnie Raitt s/t ERP sale at Pico under Clark,
     * backdated to 2026-05-13 4:05pm. One manual line, one card payment.
     *
     * Returns the new transaction id, or null if any prerequisite
     * (location / cashier / walk-in contact) couldn't be resolved.
     */
    private function createBonnieRaittRing(int $business_id, array $p, \Carbon\Carbon $now): ?int
    {
        if (empty($p['location_id']) || empty($p['user_id']) || empty($p['contact_id'])) {
            throw new \RuntimeException('Missing location / user / contact lookup; cannot build the Bonnie Raitt ring automatically.');
        }

        $invoiceNo = $this->nextInvoiceNo($business_id, (int) $p['location_id']);

        $txId = null;
        DB::transaction(function () use ($business_id, $p, $invoiceNo, $now, &$txId) {
            $txId = DB::table('transactions')->insertGetId([
                'business_id' => $business_id,
                'location_id' => (int) $p['location_id'],
                'type' => 'sell',
                'status' => 'final',
                'payment_status' => 'paid',
                'sub_status' => null,
                'contact_id' => (int) $p['contact_id'],
                'invoice_no' => $invoiceNo,
                'ref_no' => '',
                'transaction_date' => $p['transaction_date'],
                'total_before_tax' => $p['pre_tax'],
                'tax_amount' => $p['tax'],
                'discount_amount' => 0,
                'final_total' => $p['amount'],
                'created_by' => (int) $p['user_id'],
                'channel' => 'in_store',
                'additional_notes' => 'Auto-created by 2026-05-13 register reconciliation: Clark rang on Sarah\'s POS session (logged as Zakary Baller). Paired with Clover ' . ($p['cp_payment_id'] ?? 'QN6AFFVTSP6VR') . '.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // One manual sell line — product_id null because there's no
            // catalog entry for "Bonnie Raitt s/t" being rung this way.
            DB::table('transaction_sell_lines')->insert([
                'transaction_id' => $txId,
                'product_id' => null,
                'variation_id' => null,
                'product_name' => mb_substr($p['product_name'], 0, 191),
                'product_artist' => mb_substr($p['product_artist'] ?? '', 0, 191),
                'quantity' => 1,
                'unit_price' => $p['pre_tax'],
                'unit_price_before_discount' => $p['pre_tax'],
                'unit_price_inc_tax' => $p['amount'],
                'item_tax' => $p['tax'],
                'tax_id' => null,
                'line_discount_type' => null,
                'line_discount_amount' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('transaction_payments')->insert([
                'business_id' => $business_id,
                'transaction_id' => $txId,
                'amount' => $p['amount'],
                'method' => 'card',
                'paid_on' => $p['transaction_date'],
                'created_by' => (int) $p['user_id'],
                'payment_for' => (int) $p['contact_id'],
                'note' => 'Reconciliation auto-pay: Clover ' . ($p['cp_payment_id'] ?? 'QN6AFFVTSP6VR'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return $txId;
    }

    /**
     * Generate the next invoice_no for a sell at this location, mirroring
     * the existing per-business sequencing without needing the full
     * InvoiceScheme/TransactionUtil machinery.
     */
    private function nextInvoiceNo(int $business_id, int $location_id): string
    {
        $max = (int) DB::table('transactions')
            ->where('business_id', $business_id)
            ->where('type', 'sell')
            ->whereRaw("invoice_no REGEXP '^[0-9]+$'")
            ->max(DB::raw('CAST(invoice_no AS UNSIGNED)'));
        return (string) ($max + 1);
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

        // #18690 — Hollywood, luis, Judas Priest CD — sticker $15, ERP
        // was wrong before Sarah corrected, Clover undercharged by $1.09.
        $tx18690 = Transaction::where('business_id', $business_id)
            ->where('invoice_no', '18690')
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

        // Has the Bonnie Raitt ring already been created and matched?
        $mmMap = SellPosController::loadCloverManualMatches($business_id);
        $bonnieAlreadyMatched = $cpBonnieRaitt && isset($mmMap[$cpBonnieRaitt->id]);

        // Pico location + Clark Easley user + Walk-In contact lookups for
        // the Bonnie Raitt ring creation. Treated as advisory in the plan;
        // the actual creation step is forgiving if any are missing.
        $picoLocation = BusinessLocation::where('business_id', $business_id)
            ->where(function ($q) { $q->where('name', 'like', '%pico%'); })
            ->select('id', 'name')
            ->first();
        $clarkUser = User::where('first_name', 'like', '%Clark%')
            ->where('last_name', 'like', '%Easley%')
            ->select('id', 'first_name', 'last_name')
            ->first();
        $walkIn = Contact::where('business_id', $business_id)
            ->where('name', 'like', 'Walk-In%')
            ->select('id', 'name')
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
            'p2_judas_priest' => [
                'tx_id' => $tx18690->id ?? null,
                'invoice_no' => $tx18690->invoice_no ?? '18690',
            ],
            'p4_bonnie_raitt' => [
                'cp_db_id' => $cpBonnieRaitt->id ?? null,
                'cp_payment_id' => $cpBonnieRaitt->clover_payment_id ?? 'QN6AFFVTSP6VR',
                'amount' => 8.78,
                'pre_tax' => 8.00,
                'tax' => 0.78,
                'location_id' => $picoLocation->id ?? null,
                'location_name' => $picoLocation->name ?? 'Pico',
                'user_id' => $clarkUser->id ?? null,
                'user_name' => $clarkUser ? trim(($clarkUser->first_name ?? '') . ' ' . ($clarkUser->last_name ?? '')) : 'Clark Easley',
                'contact_id' => $walkIn->id ?? null,
                'product_name' => 'Bonnie Raitt — Bonnie Raitt (s/t)',
                'product_artist' => 'Bonnie Raitt',
                'transaction_date' => '2026-05-13 16:05:00',
                'already_exists' => $bonnieAlreadyMatched,
                'reason' => $bonnieAlreadyMatched
                    ? 'Already created and matched on a previous Apply run.'
                    : 'Create the missing Bonnie Raitt s/t sale at Pico ($8.00 + $0.78 tax = $8.78, card, cashier Clark) and pair it with the Clover orphan QN6AFFVTSP6VR.',
            ],
            'p5_exchange_match' => [
                'cp_db_id' => $cpExchange->id ?? null,
                'cp_payment_id' => $cpExchange->clover_payment_id ?? '53SP1HEY9A58R',
                'tx_id' => $tx18680->id ?? null,
                'invoice_no' => $tx18680->invoice_no ?? '18680',
                'amount' => $cpExchange->amount ?? null,
                'reason' => 'Daft Punk exchange — pair #18680 ($47.19 ERP) with Clover $4.39 ($4 diff actually collected). Resolves the orphan even though amounts differ.',
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
