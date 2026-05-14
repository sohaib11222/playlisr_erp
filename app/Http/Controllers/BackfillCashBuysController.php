<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Contact;
use App\Transaction;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// One-time admin backfill for cash buys that were recorded in the
// #collections-hollywood Slack channel but never filed through
// /buy-from-customer. The per-cashier reconciliation card on
// /pos/recent-feed pulls "Collection buys (cash)" from
// buy_customer_offers, so an unfiled buy shows $0 even when the
// cashier physically pulled cash from the drawer.
//
// Inserts a minimal accepted offer + draft purchase row per Slack
// entry. No inventory lines are created — these are cash-drawer
// reconciliation entries only. Snapshot + undo via
// /admin/admin-action-history (action='backfill-cash-buys').
class BackfillCashBuysController extends Controller
{
    /**
     * Hardcoded list of today's (2026-05-13) Slack-only buys.
     * Hollywood store. Times are LA local.
     */
    protected function entries(): array
    {
        return [
            ['cashier' => 'Manolo', 'time' => '13:29', 'amount' => 30.00, 'note' => 'One Piece mag Japanese, 50 Pokémon cards, 50 Pokémon sleeves (Slack)'],
            ['cashier' => 'Manolo', 'time' => '13:30', 'amount' => 8.00,  'note' => '66 CDs (Slack)'],
            ['cashier' => 'Henry',  'time' => '14:24', 'amount' => 3.00,  'note' => '5 VHS + rock CDs (Slack)'],
            ['cashier' => 'Henry',  'time' => '15:10', 'amount' => 5.00,  'note' => '8 jazz LPs (Slack)'],
            ['cashier' => 'Henry',  'time' => '16:35', 'amount' => 6.00,  'note' => 'Sealed comic + 3 rock 45s (Slack)'],
            ['cashier' => 'Luis',   'time' => '20:36', 'amount' => 12.00, 'note' => '~500 trading cards (Slack)'],
        ];
    }

    public function index(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $location = BusinessLocation::where('business_id', $business_id)
            ->whereRaw('LOWER(name) LIKE ?', ['%hollywood%'])
            ->first();
        $resolved = [];
        foreach ($this->entries() as $e) {
            $user = User::where('business_id', $business_id)
                ->whereRaw('LOWER(first_name) = ?', [strtolower($e['cashier'])])
                ->first();
            $resolved[] = $e + [
                'location_id' => $location->id ?? null,
                'location_name' => $location->name ?? '(Hollywood not found)',
                'user_id' => $user->id ?? null,
                'user_label' => $user
                    ? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: $user->username
                    : '(' . $e['cashier'] . ' not found)',
            ];
        }
        return view('admin.backfill_cash_buys', [
            'entries' => $resolved,
            'today' => Carbon::today('America/Los_Angeles')->format('Y-m-d'),
        ]);
    }

    public function run(Request $request)
    {
        @set_time_limit(0);
        $business_id = (int) $request->session()->get('user.business_id');

        $location = BusinessLocation::where('business_id', $business_id)
            ->whereRaw('LOWER(name) LIKE ?', ['%hollywood%'])
            ->first();
        if (!$location) {
            return redirect('/admin/backfill-cash-buys')
                ->with('status', ['success' => 0, 'msg' => 'Could not find a Hollywood business_location for this business.']);
        }

        $supplierId = Contact::where('business_id', $business_id)
            ->whereIn('type', ['supplier', 'both'])
            ->value('id');

        $today = Carbon::today('America/Los_Angeles');
        $inserted = [];
        $skipped = [];

        DB::beginTransaction();
        try {
            foreach ($this->entries() as $e) {
                $user = User::where('business_id', $business_id)
                    ->whereRaw('LOWER(first_name) = ?', [strtolower($e['cashier'])])
                    ->first();
                if (!$user) {
                    $skipped[] = $e + ['reason' => 'no user matches first_name'];
                    continue;
                }
                [$hh, $mm] = explode(':', $e['time']);
                $tsLa = $today->copy()->setTime((int) $hh, (int) $mm, 0);
                $tsUtc = $tsLa->copy()->setTimezone('UTC')->format('Y-m-d H:i:s');

                // Idempotency: if an offer already exists for this
                // (user, day, amount, payment_method=cash_in_store)
                // skip it. Lets Sarah re-run the page safely if she
                // already filed one of the six through the regular
                // /buy-from-customer form.
                $existing = DB::table('buy_customer_offers as o')
                    ->join('transactions as t', 't.id', '=', 'o.accepted_purchase_id')
                    ->where('o.business_id', $business_id)
                    ->where('o.payment_method', 'cash_in_store')
                    ->where('t.created_by', $user->id)
                    ->whereDate('t.transaction_date', $today->format('Y-m-d'))
                    ->whereRaw('ABS(o.final_offer_cash - ?) < 0.005', [(float) $e['amount']])
                    ->exists();
                if ($existing) {
                    $skipped[] = $e + ['reason' => 'already filed (matches existing offer)'];
                    continue;
                }

                $purchase = new Transaction();
                $purchase->business_id = $business_id;
                $purchase->location_id = $location->id;
                $purchase->type = 'purchase';
                $purchase->status = 'draft';
                $purchase->payment_status = 'paid';
                $purchase->contact_id = $supplierId;
                $purchase->transaction_date = $tsUtc;
                $purchase->total_before_tax = (float) $e['amount'];
                $purchase->tax_amount = 0;
                $purchase->discount_amount = 0;
                $purchase->shipping_charges = 0;
                $purchase->final_total = (float) $e['amount'];
                $purchase->created_by = $user->id;
                $purchase->additional_notes = '[Slack backfill 2026-05-13] ' . $e['note'];
                $purchase->save();

                $offerId = DB::table('buy_customer_offers')->insertGetId([
                    'business_id' => $business_id,
                    'location_id' => $location->id,
                    'created_by'  => $user->id,
                    'contact_id'  => $supplierId,
                    'seller_name' => 'Backfill (Slack)',
                    'seller_mode' => 'phone',
                    'status'      => 'accepted',
                    'payout_type' => 'cash',
                    'payment_method' => 'cash_in_store',
                    'calculated_cash_total' => (float) $e['amount'],
                    'calculated_credit_total' => 0,
                    'starting_offer_cash' => (float) $e['amount'],
                    'starting_offer_credit' => 0,
                    'second_offer_cash' => (float) $e['amount'],
                    'second_offer_credit' => 0,
                    'final_offer_cash' => (float) $e['amount'],
                    'final_offer_credit' => 0,
                    'accepted_purchase_id' => $purchase->id,
                    'accepted_at' => $tsUtc,
                    'notes' => '[Slack backfill 2026-05-13] ' . $e['note'],
                    'created_at' => $tsUtc,
                    'updated_at' => $tsUtc,
                ]);

                $inserted[] = [
                    'tx_id' => $purchase->id,
                    'offer_id' => $offerId,
                    'cashier' => $e['cashier'],
                    'user_id' => $user->id,
                    'amount' => (float) $e['amount'],
                    'time' => $e['time'],
                ];
            }
            DB::commit();
        } catch (\Throwable $ex) {
            DB::rollBack();
            return redirect('/admin/backfill-cash-buys')
                ->with('status', ['success' => 0, 'msg' => 'Insert failed; nothing committed. ' . $ex->getMessage()]);
        }

        // Snapshot for undo via /admin/admin-action-history.
        $timestamp = now()->format('Y-m-d_His');
        $snapshotKey = "backfill-cash-buys-{$timestamp}";
        Storage::disk('local')->put(
            "admin-snapshots/{$snapshotKey}.json",
            json_encode([
                'timestamp' => now()->toDateTimeString(),
                'action' => 'backfill-cash-buys',
                'business_id' => $business_id,
                'rows' => $inserted,
            ], JSON_PRETTY_PRINT)
        );

        $msg = 'Backfilled ' . count($inserted) . ' cash buy(s).';
        if (!empty($skipped)) {
            $skipNames = array_map(fn($s) => "{$s['cashier']} \${$s['amount']} ({$s['reason']})", $skipped);
            $msg .= ' Skipped ' . count($skipped) . ': ' . implode('; ', $skipNames) . '.';
        }
        $msg .= " Snapshot {$snapshotKey} — undo at /admin/admin-action-history.";

        return redirect('/admin/backfill-cash-buys')
            ->with('status', ['success' => 1, 'msg' => $msg]);
    }
}
