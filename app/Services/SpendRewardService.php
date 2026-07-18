<?php

namespace App\Services;

use App\Business;
use App\Contact;
use App\StoreCreditLog;
use App\Transaction;
use App\TransactionPayment;
use App\Utils\TransactionUtil;
use Illuminate\Support\Facades\Schema;

/**
 * Sarah 2026-07-17: cumulative store-credit spend reward.
 *
 * A customer accrues qualifying pre-tax spend across all their sales since the
 * program start date and earns one reward (default $5) per full bracket
 * (default $100). Qualifying spend excludes any store credit used to pay, so
 * credit can't be earned by spending credit. Sales before the start date do
 * not count.
 *
 * This service is the single source of truth used by BOTH the live checkout
 * hook (SellPosController::grantSpendReward) and the one-time catch-up command
 * (rewards:backfill-spend), so they can never diverge. It recomputes the
 * customer's cumulative total from the transactions table each time, which
 * makes it naturally idempotent — re-saving a sale or re-running the backfill
 * can't double-pay.
 */
class SpendRewardService
{
    /**
     * Resolve the reward config for a business. Returns null when the feature
     * is off / mis-configured (caller should then grant nothing).
     *
     * @return array{amount: float, per: float, start: ?string}|null
     */
    public function config($business)
    {
        if (!$business) {
            return null;
        }
        $enabled = $business->enable_spend_credit_reward ?? 1; // null (pre-migration) = on
        if (!$enabled) {
            return null;
        }
        $amount = ($business->spend_credit_reward_amount !== null) ? (float) $business->spend_credit_reward_amount : 5.0;
        $per = ((float) $business->spend_credit_reward_per > 0) ? (float) $business->spend_credit_reward_per : 100.0;
        if ($amount <= 0) {
            return null;
        }
        $start = !empty($business->spend_reward_start_date) ? (string) $business->spend_reward_start_date : null;

        return ['amount' => $amount, 'per' => $per, 'start' => $start];
    }

    /**
     * Cumulative qualifying pre-tax spend for a contact since $start.
     * = SUM(total_before_tax) − store credit used, over final sell transactions.
     */
    public function qualifyingSpend($businessId, $contactId, $start)
    {
        $sales = Transaction::where('business_id', $businessId)
            ->where('contact_id', $contactId)
            ->where('type', 'sell')
            ->where('status', 'final');
        if ($start) {
            $sales->whereDate('transaction_date', '>=', $start);
        }
        $pretax = (float) $sales->sum('total_before_tax');

        // Store credit used via the 'advance' payment method on those sales.
        $advance = (float) TransactionPayment::where('method', 'advance')
            ->where('is_return', 0)
            ->whereHas('transaction', function ($t) use ($businessId, $contactId, $start) {
                $t->where('business_id', $businessId)
                    ->where('contact_id', $contactId)
                    ->where('type', 'sell')
                    ->where('status', 'final');
                if ($start) {
                    $t->whereDate('transaction_date', '>=', $start);
                }
            })
            ->sum('amount');

        // Plus the "Use Store Credit then Cash" path, recorded as redeem rows
        // (mutually exclusive with the advance path per sale, so summing both
        // does not double-count).
        $redeemQ = StoreCreditLog::where('contact_id', $contactId)->where('source', 'redeem');
        if ($start) {
            $redeemQ->whereDate('created_at', '>=', $start);
        }
        $redeem = abs((float) $redeemQ->sum('amount'));

        $qualifying = $pretax - $advance - $redeem;

        return $qualifying > 0 ? $qualifying : 0.0;
    }

    /**
     * Brackets this contact has ALREADY been credited for — the stored counter,
     * but never less than what the existing spend_reward ledger implies. This
     * is what stops the per-sale → cumulative switch from double-paying a
     * customer who already earned a reward under the old rule.
     */
    public function bracketsAlreadyPaid($contact, $amount)
    {
        $stored = (int) ($contact->spend_reward_brackets_paid ?? 0);
        $derived = 0;
        if ($amount > 0 && Schema::hasTable('store_credit_logs')) {
            $paidCredit = (float) StoreCreditLog::where('contact_id', $contact->id)
                ->where('source', 'spend_reward')
                ->sum('amount');
            $derived = (int) floor(($paidCredit / $amount) + 1e-6);
        }

        return max($stored, $derived);
    }

    /**
     * Compute and (unless $dryRun) grant any newly-earned cumulative reward for
     * one contact.
     *
     * @param  \App\Contact  $contact
     * @param  \App\Transaction|null  $transaction  triggering sale (nullable for backfill)
     * @return array{granted: float, new_brackets: int, cumulative: float, total_brackets: int, skipped: ?string}
     */
    public function applyForContact($contact, $transaction = null, $dryRun = false)
    {
        $out = ['granted' => 0.0, 'new_brackets' => 0, 'cumulative' => 0.0, 'total_brackets' => 0, 'skipped' => null];

        if (!$contact || $contact->is_default == 1) {
            $out['skipped'] = 'walk_in';
            return $out;
        }

        $cfg = $this->config(Business::find($contact->business_id));
        if (!$cfg) {
            $out['skipped'] = 'disabled';
            return $out;
        }

        $cumulative = $this->qualifyingSpend($contact->business_id, $contact->id, $cfg['start']);
        $totalBrackets = (int) floor(($cumulative / $cfg['per']) + 1e-6);
        $paid = $this->bracketsAlreadyPaid($contact, $cfg['amount']);
        $newBrackets = $totalBrackets - $paid;

        $out['cumulative'] = $cumulative;
        $out['total_brackets'] = $totalBrackets;
        $out['new_brackets'] = $newBrackets > 0 ? $newBrackets : 0;

        if ($newBrackets < 1) {
            // Keep the counter current, but never claw back (e.g. after refunds).
            if (!$dryRun && (int) ($contact->spend_reward_brackets_paid ?? 0) < $totalBrackets) {
                $contact->spend_reward_brackets_paid = $totalBrackets;
                $contact->save();
            }
            return $out;
        }

        $reward = $newBrackets * $cfg['amount'];
        $out['granted'] = $reward;
        if ($dryRun) {
            return $out;
        }

        // Grant the credit (also syncs to the Nivessa backend by email).
        app(TransactionUtil::class)->updateContactBalance($contact, $reward, 'add');

        $ref = $transaction
            ? ('sale ' . ($transaction->invoice_no ?? ('#' . $transaction->id)))
            : 'cumulative catch-up';

        try {
            if (Schema::hasTable('store_credit_logs')) {
                StoreCreditLog::create([
                    'business_id' => (int) $contact->business_id,
                    'contact_id' => (int) $contact->id,
                    'user_id' => auth()->id(),
                    'source' => 'spend_reward',
                    'amount' => (float) $reward,
                    'balance_after' => (float) $contact->balance,
                    'reason' => '$' . number_format($reward, 2) . ' loyalty reward — reached $'
                        . number_format($totalBrackets * $cfg['per'], 2) . ' cumulative qualifying spend (' . $ref . ')',
                    'transaction_id' => $transaction ? (int) $transaction->id : null,
                ]);
            }
        } catch (\Exception $e) {
            \Log::warning('store_credit_logs spend_reward write failed: ' . $e->getMessage());
        }

        try {
            if (Schema::hasColumn('contacts', 'balance_notes')) {
                $line = '[' . now()->toDateTimeString() . '] store-credit +$' . number_format($reward, 2)
                    . ' cumulative spend reward (' . $ref . ') → new balance $' . number_format($contact->balance, 2);
                $contact->balance_notes = trim(($contact->balance_notes ?? '') . "\n" . $line);
            }
        } catch (\Exception $e) {
            \Log::warning('balance_notes spend_reward write failed: ' . $e->getMessage());
        }

        $contact->spend_reward_brackets_paid = $totalBrackets;
        $contact->save();

        return $out;
    }
}
