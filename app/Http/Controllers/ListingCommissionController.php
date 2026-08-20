<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Listing (barcoding) commissions owed to staff.
//
// Mirrors the EXACT commission employees already see on the Employee
// Leaderboard (ReportController::barcodingCommissionByUser): a person earns
// 2% of the sale value (quantity × unit_price_inc_tax) of every item THEY
// listed/barcoded (products.created_by) that has since SOLD on a final sell,
// as long as the product was listed on/after the 2026-05-15 rollout and isn't
// in an excluded category (sealed/new stock, gear, apparel, etc.). Keeping the
// formula identical here means this payables view agrees with what each
// employee was shown they earned.
//
// "Paid" is tracked here, not in the DB (Sarah doesn't run migrations): each
// payout snapshots the exact sell-line ids it covered to
// storage/app/listing-commission-payouts.json. Owed = qualifying sold lines not
// in any payout, so marking paid never double-counts and a payout can be undone.
class ListingCommissionController extends Controller
{
    const PAYOUTS_FILE = 'listing-commission-payouts.json';
    const SALES_PAYOUTS_FILE = 'sales-commission-payouts.json';
    const FREEZE_FILE = 'commission-freeze.json';
    const DEFAULT_FROM = '2026-05-15';
    const SALES_BONUS_FROM = '2026-06-15'; // sales-goal bonus go-live (matches leaderboard)
    const RATE = 0.02; // flat 2%, matches barcodingCommissionByUser

    // Category exclusions copied verbatim from barcodingCommissionByUser so the
    // owed numbers match the leaderboard exactly.
    private $excludedCategoryPatterns = ['%sealed%', '%new vinyl%', '%new cd%', '%new cassette%'];
    private $excludedCategoryNames = [
        'audio gear', 'record players', 'record player',
        'trading cards', 'apparel', 'clothing', 'video games',
        'gift items', 'toys', 'accessories & novelties',
        'acessories & novelties', 'pictures & posters',
    ];

    // Staff temporarily away and told not to accrue commission while gone
    // (Sarah 2026-08-20, confirmed multiple times): sales after this date
    // don't count until she says they're back. Keyed by lowercase first name,
    // same matching style as the exclusion lists below. Update/remove an entry
    // when someone returns.
    private $commissionCutoffByFirstName = [
        'clark' => '2026-07-16', // traveling, out until further notice
        'clyde' => '2026-08-12', // left last minute for a month
        'mica'  => '2026-08-12', // last day
    ];

    // Owners / back-office + departed non-floor accounts that don't get paid
    // listing commission (mirrors the Employee Leaderboard roster). Owner
    // accounts are also the creator-of-record for bulk/imported listings, so
    // leaving them in inflates counts (e.g. Jon showed 48k listed items).
    // 'henry' left the company (Sarah 2026-06-02). Excluded by first name.
    // 'insha' does not get listing commission (Sarah 2026-07-09).
    // 'jennifer' and 'ece' are not working here right now, so they're hidden and
    // earn no commission while out (Sarah 2026-07-09). Ece is expected back —
    // when she returns, remove 'ece' from this list to re-enable her.
    private $excludedOwnerFirstNames = ['jon', 'jonathan', 'sarah', 'sohaib', 'fatteen', 'henry', 'insha', 'jennifer', 'ece'];

    // Fatteen's ERP account is named "Nerdy Solutions", so the first-name list
    // above misses it. Also drop any account whose full name contains these.
    private $excludedNameContains = ['nerdy'];

    // True if a display name belongs to an excluded person (owner/back-office or
    // someone not currently working here). Matches the same first-name and
    // name-contains rules the SQL uses, so a person dropped from the queries also
    // can't slip back in through the sales-bonus / payout-ledger merge.
    private function isExcludedName($name)
    {
        $name = strtolower(trim((string) $name));
        if ($name === '') { return false; }
        $first = explode(' ', $name)[0];
        if (in_array($first, $this->excludedOwnerFirstNames, true)) { return true; }
        foreach ($this->excludedNameContains as $needle) {
            if (strpos($name, $needle) !== false) { return true; }
        }
        return false;
    }

    public function index(Request $request)
    {
        // Fixed to the program start. "Owed" = everything unpaid since 2026-05-15,
        // which is exactly what each employee sees on My Earnings (paid items
        // already drop off, so there's no need to filter by "since last
        // payment" — the unpaid list IS what's left). An adjustable date only
        // made this page disagree with the employee view (Sarah 2026-06-21).
        $from = self::DEFAULT_FROM;
        $businessId = $request->session()->get('user.business_id');

        $paid = $this->loadPayouts();
        $paidLineIds = $this->paidLineIds($paid);
        $paidSet = array_flip($paidLineIds);

        // Actual dollars paid to each person (from the payout ledger) — this is
        // the real money paid, NOT scoped to the listing date, so it always
        // reflects what you actually handed them.
        $paidByUser = [];
        foreach ($paid as $pp) {
            $uid = (int) ($pp['user_id'] ?? 0);
            if ($uid > 0) { $paidByUser[$uid] = ($paidByUser[$uid] ?? 0) + (float) ($pp['amount'] ?? 0); }
        }

        // ALL qualifying sold lines (paid + unpaid) for the window so Earned
        // and Owed both move with the date. At the default May-15 window,
        // Earned = Paid + Owed reconciles with the Leaderboard / My Earnings.
        $lines = $this->ownedSoldLines($businessId, $from, []);
        $listedTotals = $this->listedTotalsByUser($businessId, $from);

        $people = [];
        foreach ($lines as $row) {
            $uid = $row->user_id;
            if (!isset($people[$uid])) {
                $lt = $listedTotals->get($uid);
                $people[$uid] = (object) [
                    'user_id'      => $uid,
                    'name'         => $this->personName($row),
                    'listed_count' => (int) ($lt->listed_count ?? 0),
                    'listed_value' => (float) ($lt->listed_value ?? 0),
                    'sold_count'   => 0,
                    'sale_total'   => 0.0,
                    'earned'       => 0.0,
                    'paid'         => round($paidByUser[$uid] ?? 0, 2),
                    'owed'         => 0.0,
                    'count'        => 0, // unpaid sold lines (what Mark paid covers)
                ];
            }
            $amt = (float) $row->sale_amount;
            $comm = $amt * self::RATE;
            $people[$uid]->sold_count++;
            $people[$uid]->sale_total += $amt;
            $people[$uid]->earned += $comm;
            if (!isset($paidSet[(int) $row->line_id])) {
                $people[$uid]->owed += $comm;
                $people[$uid]->count++;
            }
        }
        // Sales-goal bonus per employee (cumulative since it went live
        // 2026-06-15), now with its own payout ledger so sales commission can be
        // marked paid just like listing. Earned reuses the exact leaderboard
        // math, so it reconciles with the Employee Leaderboard to the penny.
        $salesSummary = $this->salesSummaryByUser($businessId);

        // Make sure people who earned a sales bonus but have no listing lines
        // still appear on the page.
        $byId = [];
        foreach ($people as $p) { $byId[(int) $p->user_id] = $p; }
        foreach ($salesSummary as $uid => $s) {
            $uid = (int) $uid;
            if (!isset($byId[$uid])) {
                $u = DB::table('users')->where('id', $uid)->first();
                $byId[$uid] = (object) [
                    'user_id' => $uid,
                    'name'    => $u ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->surname ?? ('User #' . $uid))) : ('User #' . $uid),
                    'listed_count' => 0, 'listed_value' => 0.0, 'sold_count' => 0,
                    'sale_total' => 0.0, 'earned' => 0.0, 'paid' => 0.0, 'owed' => 0.0, 'count' => 0,
                ];
            }
        }
        $stores = $this->primaryStoreByUser($businessId);
        $partyAdj = $this->partySplitAdjustmentsByUser();
        $partyEarned = $this->partyEarnedByUser();
        // Also fold in listening-party bonuses paid by hand via /admin/party-bonus
        // (previously computed but never wired in) so the Listening party column
        // shows them too, not just unpaid auto-splits.
        foreach ($this->manualPartyEarnedByUser() as $uid => $amt) {
            $partyEarned[(int) $uid] = ($partyEarned[(int) $uid] ?? 0) + $amt;
        }
        // Make sure a floor helper who only shows up via a party split (no listing
        // and no raw sales bonus of their own) still appears on the page.
        foreach ($partyAdj as $uid => $amt) {
            $uid = (int) $uid;
            if (!isset($byId[$uid])) {
                $u = DB::table('users')->where('id', $uid)->first();
                $byId[$uid] = (object) [
                    'user_id' => $uid,
                    'name'    => $u ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->surname ?? ('User #' . $uid))) : ('User #' . $uid),
                    'listed_count' => 0, 'listed_value' => 0.0, 'sold_count' => 0,
                    'sale_total' => 0.0, 'earned' => 0.0, 'paid' => 0.0, 'owed' => 0.0, 'count' => 0,
                ];
            }
        }
        foreach ($byId as $uid => $p) {
            $p->store = $stores[(int) $uid] ?? '';
            $s = $salesSummary->get($uid);
            $p->sales_earned   = $s ? (float) $s->earned   : 0.0;
            // Listening-party split: shift the sales bonus by the applied
            // redistribution (helpers +, cashiers -). Sums to zero store-wide, so
            // total payout is unchanged — it just moves each party's bonus onto
            // the floor helper who earned it.
            $p->party_split = round($partyAdj[(int) $uid] ?? 0, 2);
            // Listening party column shows only what's still OWED for a party. Hand-paid
            // party bonuses are earned == paid so they net to $0 and drop off once paid,
            // same as the sales/listing owed columns; the paid ones live in the weekly
            // statement instead. (Only an un-paid auto split would show here.)
            $p->party_earned = round($partyEarned[(int) $uid] ?? 0, 2);
            $p->sales_earned = round($p->sales_earned + $p->party_split, 2);
            $p->sales_paid     = $s ? (float) $s->paid     : 0.0;
            $p->sales_owed     = $s ? (float) $s->owed     : 0.0;
            $p->sales_goal     = $s ? (float) $s->goal     : 0.0;
            $p->sales_achieved = $s ? (float) $s->achieved : 0.0;
            // NET per type = earned minus paid (can go NEGATIVE = a credit from
            // an overpayment). Unlike the "owed" columns (which floor at 0), these
            // two always add up to Pay now, so every row reconciles.
            $p->sales_net   = round($p->sales_earned - $p->sales_paid, 2);
            $p->listing_net = round($p->earned - $p->paid, 2);
            // Combined cumulative commission across both types.
            $p->total_comm     = round($p->earned + $p->sales_earned, 2);
            $p->total_paid_all = round($p->paid + $p->sales_paid, 2);
            // What you still owe = everything earned minus everything paid
            // (manual payments included). Goes NEGATIVE when you've overpaid —
            // that's a credit against their next commission.
            $p->total_owed_now = round($p->total_comm - $p->total_paid_all, 2);

            // Cross-cancel overpayment across buckets for DISPLAY (Sarah
            // 2026-08-06). A payment logged to the wrong bucket — e.g. listing
            // commission recorded as a sales payout — otherwise shows one column
            // deep negative and the other positive (Manolo: -$45 sales / +$54
            // listing). Net them so a RED number only appears when the person is
            // GENUINELY net-overpaid (total_owed_now < 0). Pay now is unchanged;
            // this only moves the split between the two columns.
            $sd = $p->sales_net; $ld = $p->listing_net;
            if ($sd < 0 && $ld > 0)     { $t = min(-$sd, $ld); $sd += $t; $ld -= $t; }
            elseif ($ld < 0 && $sd > 0) { $t = min(-$ld, $sd); $ld += $t; $sd -= $t; }
            $p->sales_disp   = round($sd, 2);
            $p->listing_disp = round($ld, 2);

            // Plain-English payroll memo, from the reallocated display split so it
            // matches the columns and always adds up to Pay now.
            $memo = [];
            if ($p->sales_disp > 0.004)      { $memo[] = 'Sales bonus $' . number_format($p->sales_disp, 2); }
            elseif ($p->sales_disp < -0.004) { $memo[] = 'Sales credit -$' . number_format(abs($p->sales_disp), 2); }
            if ($p->listing_disp > 0.004)      { $memo[] = 'Listing $' . number_format($p->listing_disp, 2); }
            elseif ($p->listing_disp < -0.004) { $memo[] = 'Listing credit -$' . number_format(abs($p->listing_disp), 2); }
            $p->payroll_memo = implode(' + ', $memo);
            if (count($memo) > 1 || $p->total_owed_now < -0.004) {
                $p->payroll_memo .= ' = ' . ($p->total_owed_now < -0.004
                    ? '-$' . number_format(abs($p->total_owed_now), 2) . ' credit'
                    : '$' . number_format($p->total_owed_now, 2));
            }
        }

        // Final safety net: drop excluded people no matter which path added them.
        // The listing query already excludes them, but the sales-bonus / payout
        // ledger merge above can re-add someone (e.g. Ece kept showing via an old
        // sales payout). Filtering by name here guarantees they never render.
        $people = collect($byId)->values()
            ->reject(function ($p) { return $this->isExcludedName($p->name); })
            ->sortByDesc('total_owed_now')->values();

        $history = collect($paid)->sortByDesc('marked_at')->values();
        $salesHistory = collect($this->loadSalesPayouts())->sortByDesc('marked_at')->values();

        // Sales-bonus-by-day lookup: pick ONE day (default today) plus an optional
        // person, to see just that day's sales-goal bonus per employee. Read-only —
        // reuses the exact leaderboard math (salesBonusByUser) scoped to a single
        // day, and never touches the cumulative owed/payout ledger above. Answers
        // "what's Andy's sales bonus for today" without paging through the roll-up.
        $bonusDay = $request->input('day');
        $bonusDay = (is_string($bonusDay) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $bonusDay))
            ? $bonusDay : now()->toDateString();
        $bonusPerson = trim((string) $request->input('person', ''));

        $dayRows = collect();
        try {
            $daily = app(\App\Http\Controllers\ReportController::class)
                ->salesBonusByUser($businessId, $bonusDay, $bonusDay);
            $rows = [];
            foreach ($daily as $uid => $s) {
                $uid = (int) $uid;
                $u = DB::table('users')->where('id', $uid)->first();
                $name = $u
                    ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->surname ?? ('User #' . $uid)))
                    : ('User #' . $uid);
                $rows[] = (object) [
                    'user_id'  => $uid,
                    'name'     => $name,
                    'goal'     => (float) $s->goal,
                    'achieved' => (float) $s->achieved,
                    'bonus'    => (float) $s->bonus,
                ];
            }
            $dayRows = collect($rows);
        } catch (\Throwable $e) {
            \Log::warning('daily sales-bonus lookup failed: ' . $e->getMessage());
        }
        if ($bonusPerson !== '') {
            $needle = strtolower($bonusPerson);
            $dayRows = $dayRows->filter(function ($r) use ($needle) {
                return strpos(strtolower($r->name), $needle) !== false;
            })->values();
        }
        $dayRows = $dayRows->sortByDesc('bonus')->values();

        // Never let a proxy/browser serve a stale copy of this page — the owed
        // numbers must always reflect the latest payouts, or a just-paid person
        // can appear to still owe money.
        return response()->view('admin.listing_commissions', [
            'bonus_day'    => $bonusDay,
            'bonus_person' => $bonusPerson,
            'day_rows'     => $dayRows,
            'from'        => $from,
            'rate_pct'    => self::RATE * 100,
            'people'      => $people,
            'history'     => $history,
            'sales_history' => $salesHistory,
            'total_owed'  => $people->sum('owed'),
            'total_earned'=> $people->sum('earned'),
            'total_paid_window' => $people->sum('paid'),
            'total_paid'  => $history->sum('amount'),
            'total_sales_earned' => $people->sum('sales_earned'),
            'total_sales_paid'   => $people->sum('sales_paid'),
            'total_sales_owed'   => $people->sum('sales_owed'),
            'total_sales_paid_all' => $salesHistory->sum('amount'),
            'total_commission'   => $people->sum('total_comm'),
            'total_paid_all'     => $people->sum('total_paid_all'),
            'total_owed_now'     => $people->sum('total_owed_now'),
            'sales_bonus_from'   => self::SALES_BONUS_FROM,
            'freeze'             => $this->loadFreeze(),
            'paid_groups'        => $this->groupPaidHistory($history, $salesHistory),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
          ->header('Pragma', 'no-cache');
    }

    // Sales-goal bonus per user: earned (cumulative since go-live, from the
    // leaderboard math), paid (from the sales payout ledger), owed = earned −
    // paid. Keyed by user_id. Mirrors summaryByUser() for listing so both
    // commission types behave the same and reconcile across the two reports.
    public function salesSummaryByUser($businessId)
    {
        $earned = collect();
        try {
            $earned = app(\App\Http\Controllers\ReportController::class)
                ->salesBonusByUser($businessId, self::SALES_BONUS_FROM, now()->toDateString());
        } catch (\Throwable $e) {
            \Log::warning('salesSummaryByUser earned pull failed: ' . $e->getMessage());
        }

        // Listening-party bonuses are their own bucket (shown in the Listening
        // party column via manualPartyEarnedByUser) — excluded here entirely so
        // they never touch the goal-based sales earned/paid/owed math. Counting
        // them as "paid" without a matching "earned" caused a false overpaid
        // credit; then also adding them to earned double-counted against paid
        // and inflated owed by the same amount instead (both wrong — Sarah
        // 2026-08-20: "why is Andy negative now" / "luis amount changed").
        $paidByUser = [];
        foreach ($this->loadSalesPayouts() as $p) {
            if (stripos((string) ($p['note'] ?? ''), 'Listening party') === 0) { continue; }
            $uid = (int) ($p['user_id'] ?? 0);
            if ($uid > 0) { $paidByUser[$uid] = ($paidByUser[$uid] ?? 0) + (float) ($p['amount'] ?? 0); }
        }

        $out = [];
        foreach (array_unique(array_merge(array_map('intval', $earned->keys()->all()), array_keys($paidByUser))) as $uid) {
            $uid = (int) $uid;
            $en = $earned->get($uid); // object {bonus, goal, achieved} or null
            $e  = round((float) ($en->bonus ?? 0), 2);
            $pd = round((float) ($paidByUser[$uid] ?? 0), 2);
            $out[$uid] = (object) [
                'earned'   => $e,
                'paid'     => $pd,
                'owed'     => round(max(0, $e - $pd), 2),
                'goal'     => round((float) ($en->goal ?? 0), 2),
                'achieved' => round((float) ($en->achieved ?? 0), 2),
            ];
        }
        return collect($out);
    }

    // One-click payout: marks BOTH the person's unpaid listing commission and
    // their unpaid sales commission paid in a single action (each still lands in
    // its own ledger so the histories/undo stay separate). Powers the single
    // "Mark paid" button on the page.
    public function markAllPaid(Request $request)
    {
        $from = $this->normalizeFrom($request->input('from'));
        $userId = (int) $request->input('user_id');
        $businessId = $request->session()->get('user.business_id');

        if ($userId <= 0) {
            return redirect('/admin/listing-commissions')
                ->with('status', ['success' => 0, 'msg' => 'Missing person.']);
        }

        $u = DB::table('users')->where('id', $userId)->first();
        $name = $u ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->surname ?? ('User #' . $userId))) : ('User #' . $userId);

        // Record EXACTLY the net owed the page shows in Pay now (earned minus
        // paid, per type, with the listening-party split included). That way
        // paid == what was owed and the person settles to $0 — instead of the old
        // behaviour (line-based listing + floored sales, ignoring party/credits)
        // which recorded the wrong amount and drifted to a phantom overpayment.
        $listingEarned = 0.0;
        foreach ($this->ownedSoldLines($businessId, $from, [])->where('user_id', $userId) as $row) {
            $listingEarned += (float) $row->sale_amount * self::RATE;
        }
        $listingPaid = 0.0;
        foreach ($this->loadPayouts() as $p) {
            if ((int) ($p['user_id'] ?? 0) === $userId) { $listingPaid += (float) ($p['amount'] ?? 0); }
        }
        $listingNet = round($listingEarned - $listingPaid, 2);

        $sales = $this->salesSummaryByUser($businessId)->get($userId);
        $partyAdj = $this->partySplitAdjustmentsByUser();
        $salesEarned = ($sales ? (float) $sales->earned : 0.0) + (float) ($partyAdj[$userId] ?? 0);
        $salesPaid   = $sales ? (float) $sales->paid : 0.0;
        $salesNet    = round($salesEarned - $salesPaid, 2);

        if (round($listingNet + $salesNet, 2) <= 0.005) {
            return redirect('/admin/listing-commissions')
                ->with('status', ['success' => 0, 'msg' => 'Nothing outstanding for that person (they\'re settled or in credit).']);
        }

        $now = now()->toDateTimeString();
        $today = now()->toDateString();
        $parts = []; $total = 0.0;

        // Record each side at its net (a negative side is a credit being cleared
        // by this run, so the two together always equal Pay now).
        if (abs($listingNet) > 0.005) {
            $lp = $this->loadPayouts();
            $lp[] = [
                'id' => bin2hex(random_bytes(8)), 'user_id' => $userId, 'name' => $name,
                'count' => 0, 'amount' => $listingNet, 'line_ids' => [], 'from_date' => $today, 'to_date' => $today,
                'manual' => true, 'note' => 'Payroll — settle listing', 'marked_by' => $request->session()->get('user.id'), 'marked_at' => $now,
            ];
            $this->savePayouts($lp);
            $parts[] = 'listing $' . number_format($listingNet, 2);
            $total += $listingNet;
        }
        if (abs($salesNet) > 0.005) {
            $sp = $this->loadSalesPayouts();
            $sp[] = [
                'id' => bin2hex(random_bytes(8)), 'user_id' => $userId, 'name' => $name,
                'amount' => $salesNet, 'from_date' => $today, 'to_date' => $today,
                'manual' => true, 'note' => 'Payroll — settle sales', 'marked_by' => $request->session()->get('user.id'), 'marked_at' => $now,
            ];
            $this->saveSalesPayouts($sp);
            $parts[] = 'sales $' . number_format($salesNet, 2);
            $total += $salesNet;
        }

        return redirect('/admin/listing-commissions')->with('status', [
            'success' => 1,
            'msg'     => 'Marked paid for ' . $name . ': ' . implode(' + ', $parts) . ' = $' . number_format($total, 2) . '.',
        ]);
    }

    // Record a payment made by hand (e.g. cash) so the ledger matches what was
    // actually disbursed, even when the calculated figure had drifted by the time
    // you paid. Writes to the sales payout ledger (amount-based), so it reduces
    // what the person is shown as owed and appears in the paid history. Undoable
    // like any payout. Use it to true-up a payout to the real amount paid.
    public function recordPayment(Request $request)
    {
        $userId  = (int) $request->input('user_id');
        $note    = trim((string) $request->input('note', ''));
        // Listing + sales entered separately so split payments (e.g. Manolo's
        // 6/25) land in the right ledger. Legacy single "amount" falls into sales.
        $listing = round((float) $request->input('listing', 0), 2);
        $sales   = round((float) $request->input('sales', $request->input('amount', 0)), 2);

        // Optional "date paid" so past payrolls can be logged on the day they
        // actually happened; blank = today.
        $paidOn = trim((string) $request->input('paid_on', ''));
        $isPastDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $paidOn);
        $dateField = $isPastDate ? $paidOn : now()->toDateString();
        $markedAt  = $isPastDate ? ($paidOn . ' 12:00:00') : now()->toDateTimeString();

        if ($userId <= 0 || ($listing == 0.0 && $sales == 0.0)) {
            return redirect('/admin/listing-commissions')
                ->with('status', ['success' => 0, 'msg' => 'Pick a person and enter a listing or sales amount.']);
        }

        $u = DB::table('users')->where('id', $userId)->first();
        $name = $u
            ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->surname ?? ('User #' . $userId)))
            : ('User #' . $userId);
        $noteVal = $note !== '' ? $note : 'Manual payment recorded';

        if ($sales != 0.0) {
            $sp = $this->loadSalesPayouts();
            $sp[] = [
                'id' => bin2hex(random_bytes(8)), 'user_id' => $userId, 'name' => $name,
                'amount' => $sales, 'from_date' => $dateField, 'to_date' => $dateField,
                'manual' => true, 'note' => $noteVal,
                'marked_by' => $request->session()->get('user.id'), 'marked_at' => $markedAt,
            ];
            $this->saveSalesPayouts($sp);
        }
        if ($listing != 0.0) {
            $lp = $this->loadPayouts();
            $lp[] = [
                'id' => bin2hex(random_bytes(8)), 'user_id' => $userId, 'name' => $name,
                'count' => 0, 'amount' => $listing, 'line_ids' => [],
                'from_date' => $dateField, 'to_date' => $dateField,
                'manual' => true, 'note' => $noteVal,
                'marked_by' => $request->session()->get('user.id'), 'marked_at' => $markedAt,
            ];
            $this->savePayouts($lp);
        }

        $parts = [];
        if ($listing != 0.0) { $parts[] = 'listing $' . number_format($listing, 2); }
        if ($sales != 0.0)   { $parts[] = 'sales $' . number_format($sales, 2); }

        return redirect('/admin/listing-commissions')->with('status', [
            'success' => 1,
            'msg'     => 'Recorded ' . implode(' + ', $parts) . ' paid to ' . $name . '.',
        ]);
    }

    // Listening Party Bonus calculator. Pick a date + time window + store + a
    // %, and it pulls the ACTUAL sales rung at that store during the window
    // (non-whatnot, final sells, pre-tax net of returns — the same revenue basis
    // as commissions), then splits (% of those sales) evenly among the staff who
    // worked the party. Purpose-built for the "2 people work a party, split a %
    // of what the store rang during it" model, which the hourly overage pool
    // handles badly. Read-only until you hit Pay; paying logs to the same
    // undoable sales payout ledger the Commissions page uses.
    public function partyBonus(Request $request)
    {
        $businessId = $request->session()->get('user.business_id');

        $locations = DB::table('business_locations')
            ->where('business_id', $businessId)->where('is_active', 1)
            ->orderBy('name')->pluck('name', 'id');

        // Current staff only: active AND able to log in (the ERP's definition of
        // a current employee), then drop owners/back-office/system/departed
        // accounts by name — the same exclusion the Commissions page uses, plus
        // known non-floor accounts (Nick=fulfillment, Viper, Guest, Fahrul left).
        $extraNonFloor = ['nick', 'viper', 'guest', 'fahrul', 'fatten'];
        $staff = DB::table('users')
            ->where('business_id', $businessId)
            ->where('status', 'active')
            ->where('allow_login', 1)
            ->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'surname'])
            ->map(function ($u) {
                $u->label = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->surname ?: ('User #' . $u->id));
                return $u;
            })
            ->reject(function ($u) use ($extraNonFloor) {
                if ($this->isExcludedName($u->label)) { return true; }
                $first = strtolower(trim(explode(' ', trim((string) $u->label))[0] ?? ''));
                return in_array($first, $extraNonFloor, true);
            })
            ->values();

        // Times come in as 12-hour parts (hour 1-12 / minute / AM-PM) so the UI is
        // always 12h; assemble them into 24h "HH:MM" for the query. Default the
        // window to 6:00 PM - 8:00 PM (the listening-party slot).
        $build12 = function ($h, $m, $ap) {
            if ($h === null || $h === '') { return ''; }
            $h = (int) $h; $m = (int) $m; $ap = strtoupper(trim((string) $ap));
            if ($h < 1 || $h > 12) { return ''; }
            $h24 = $ap === 'PM' ? ($h % 12) + 12 : ($h % 12);
            return sprintf('%02d:%02d', $h24, $m);
        };
        $submitted = $request->has('date');
        $fromH  = $submitted ? $request->input('from_h')  : 6;
        $fromM  = $submitted ? $request->input('from_m', '00') : '00';
        $fromAp = $submitted ? $request->input('from_ap', 'PM') : 'PM';
        $toH    = $submitted ? $request->input('to_h')    : 8;
        $toM    = $submitted ? $request->input('to_m', '00')   : '00';
        $toAp   = $submitted ? $request->input('to_ap', 'PM')  : 'PM';

        $date       = trim((string) $request->input('date', ''));
        $fromTime   = $build12($fromH, $fromM, $fromAp);
        $toTime     = $build12($toH, $toM, $toAp);
        $locationId = (int) $request->input('location_id');
        $percent    = (float) $request->input('percent', 0);
        $selected   = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('staff', [])))));

        $result = null;
        $error  = null;
        $validWindow = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            && preg_match('/^\d{1,2}:\d{2}$/', $fromTime)
            && preg_match('/^\d{1,2}:\d{2}$/', $toTime)
            && $locationId > 0;

        if ($request->has('date') && !$validWindow) {
            $error = 'Enter a valid date, start time, end time (HH:MM), and store.';
        } elseif ($validWindow) {
            $startC = \Carbon::parse($date . ' ' . $fromTime . ':00');
            $endC   = \Carbon::parse($date . ' ' . $toTime . ':59');
            if ($endC->lte($startC)) {
                $error = 'End time must be after start time.';
            } else {
                // Sales rung at this store during the window. transaction_date on
                // in-store POS sales is stored in store-local (LA) time, so a
                // local-clock window matches directly. Same revenue basis as the
                // leaderboard/commissions (pre-tax, net of returns, no Whatnot).
                $net_pretax = '(tsl.quantity - COALESCE(tsl.quantity_returned, 0)) * (tsl.unit_price_inc_tax - COALESCE(tsl.item_tax, 0))';
                $sales = (float) DB::table('transactions as t')
                    ->join('transaction_sell_lines as tsl', 'tsl.transaction_id', '=', 't.id')
                    ->where('t.business_id', $businessId)
                    ->where('t.location_id', $locationId)
                    ->where('t.type', 'sell')->where('t.status', 'final')->whereNull('t.import_source')
                    ->where(function ($q) { $q->where('t.is_whatnot', 0)->orWhereNull('t.is_whatnot'); })
                    ->whereBetween('t.transaction_date', [$startC->toDateTimeString(), $endC->toDateTimeString()])
                    ->sum(DB::raw($net_pretax));
                $sales = round((float) $sales, 2);

                // The actual sales that make up that total — one row per receipt,
                // so the number is fully auditable (time, cashier, amount).
                $txns = DB::table('transactions as t')
                    ->join('transaction_sell_lines as tsl', 'tsl.transaction_id', '=', 't.id')
                    ->leftJoin('users as u', 'u.id', '=', 't.created_by')
                    ->where('t.business_id', $businessId)
                    ->where('t.location_id', $locationId)
                    ->where('t.type', 'sell')->where('t.status', 'final')->whereNull('t.import_source')
                    ->where(function ($q) { $q->where('t.is_whatnot', 0)->orWhereNull('t.is_whatnot'); })
                    ->whereBetween('t.transaction_date', [$startC->toDateTimeString(), $endC->toDateTimeString()])
                    ->groupBy('t.id', 't.invoice_no', 't.transaction_date', 'u.first_name', 'u.last_name')
                    ->orderBy('t.transaction_date')
                    ->selectRaw('t.invoice_no, t.transaction_date, u.first_name, u.last_name, COALESCE(SUM(' . $net_pretax . '), 0) as net')
                    ->get();

                $pool = round($sales * ($percent / 100), 2);
                $n = max(1, count($selected));
                $per = round($pool / $n, 2);

                $names = [];
                if (!empty($selected)) {
                    foreach (DB::table('users')->whereIn('id', $selected)->get(['id', 'first_name', 'last_name', 'surname']) as $u) {
                        $names[(int) $u->id] = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->surname ?: ('User #' . $u->id));
                    }
                }
                $people = [];
                foreach ($selected as $uid) {
                    $people[] = ['user_id' => $uid, 'name' => $names[$uid] ?? ('User #' . $uid), 'amount' => $per];
                }

                $result = [
                    'sales'         => $sales,
                    'percent'       => $percent,
                    'pool'          => $pool,
                    'per'           => $per,
                    'people'        => $people,
                    'location_name' => $locations[$locationId] ?? ('Store #' . $locationId),
                    'window'        => $startC->format('g:i A') . ' - ' . $endC->format('g:i A'),
                ];
            }
        }

        return response()->view('admin.party_bonus', [
            'locations'   => $locations,
            'staff'       => $staff,
            'date'        => $date,
            'from_h'      => $fromH, 'from_m' => str_pad((string) (int) $fromM, 2, '0', STR_PAD_LEFT), 'from_ap' => strtoupper((string) $fromAp),
            'to_h'        => $toH,   'to_m'   => str_pad((string) (int) $toM,   2, '0', STR_PAD_LEFT), 'to_ap'   => strtoupper((string) $toAp),
            'location_id' => $locationId,
            'percent'     => $percent ?: '',
            'selected'    => $selected,
            'result'      => $result,
            'error'       => $error,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
          ->header('Pragma', 'no-cache');
    }

    // Log each party worker's share to the sales payout ledger, backdated to the
    // party date, so it shows as paid on the Commissions page and is undoable
    // there. Skips an entry already on the ledger for the same person+date+amount
    // so re-submitting the same party can't double-pay.
    public function partyBonusPay(Request $request)
    {
        $date  = trim((string) $request->input('date', ''));
        $store = trim((string) $request->input('location_name', ''));
        $uids  = (array) $request->input('user_id', []);
        $amts  = (array) $request->input('amount', []);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return redirect('/admin/party-bonus')
                ->with('status', ['success' => 0, 'msg' => 'Missing party date.']);
        }

        $note = 'Listening party ' . $date . ($store !== '' ? ' (' . $store . ')' : '');
        $sales = $this->loadSalesPayouts();

        $exists = function ($uid, $amount) use ($sales, $date) {
            foreach ($sales as $p) {
                $pdate = substr((string) ($p['from_date'] ?? $p['marked_at'] ?? ''), 0, 10);
                if ((int) ($p['user_id'] ?? 0) === $uid && $pdate === $date
                    && abs((float) ($p['amount'] ?? 0) - $amount) < 0.005) {
                    return true;
                }
            }
            return false;
        };

        $paid = 0; $skipped = 0; $total = 0.0;
        foreach ($uids as $i => $uidRaw) {
            $uid = (int) $uidRaw;
            $amount = round((float) ($amts[$i] ?? 0), 2);
            if ($uid <= 0 || $amount == 0.0) { continue; }
            if ($exists($uid, $amount)) { $skipped++; continue; }

            $u = DB::table('users')->where('id', $uid)->first();
            $name = $u ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->surname ?? ('User #' . $uid))) : ('User #' . $uid);
            $sales[] = [
                'id' => bin2hex(random_bytes(8)), 'user_id' => $uid, 'name' => $name,
                'amount' => $amount, 'from_date' => $date, 'to_date' => $date,
                'manual' => true, 'note' => $note,
                'marked_by' => $request->session()->get('user.id'), 'marked_at' => $date . ' 12:00:00',
            ];
            $paid++; $total += $amount;
        }

        $this->saveSalesPayouts($sales);

        if ($paid === 0 && $skipped === 0) {
            return redirect('/admin/party-bonus')
                ->with('status', ['success' => 0, 'msg' => 'Nothing to pay — pick staff and an amount first.']);
        }
        $msg = 'Paid ' . $paid . ' ' . ($paid === 1 ? 'person' : 'people') . ' $' . number_format($total, 2) . ' for the ' . $note . '.';
        if ($skipped > 0) { $msg .= ' Skipped ' . $skipped . ' already on the ledger (no double-pay).'; }
        return redirect('/admin/party-bonus')->with('status', ['success' => 1, 'msg' => $msg]);
    }

    // Shift Commission report (read-only). Implements the exact store rule:
    //   * 2% of sales — the base, SPLIT among the floor staff sharing the one
    //     register (Front Desk / Event Lead / Sales Floor Lead) hour by hour.
    //   * +2% (=> 4%) on the amount each INDIVIDUAL rang OVER their own per-shift
    //     goal — individual, NOT split. Uses the ERP's own goal/achieved numbers
    //     (salesBonusByUser) so it agrees with what each employee sees.
    // Shows every input so it can be verified before anyone is paid.
    const SHIFT_BASE_RATE = 0.02;
    const SHIFT_GOAL_BONUS_RATE = 0.02;
    const PARTY_SPLIT_FROM = '2026-07-10';
    const PARTY_SPLIT_FILE = 'party-split-adjustments.json';
    private $floorPositions = ['front desk', 'event lead', 'floor sales', 'sales floor lead'];

    public function shiftCommission(Request $request)
    {
        $businessId = $request->session()->get('user.business_id');
        $locations = DB::table('business_locations')
            ->where('business_id', $businessId)->where('is_active', 1)
            ->orderBy('name')->pluck('name', 'id');

        $date = trim((string) $request->input('date', ''));
        $locationId = (int) $request->input('location_id');
        $result = null; $error = null;

        if ($request->has('date')) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $locationId <= 0) {
                $error = 'Pick a valid date and store.';
            } else {
                try {
                    $result = $this->computeShiftCommission($businessId, $date, $locationId, $locations[$locationId] ?? ('Store #' . $locationId));
                } catch (\Throwable $e) {
                    \Log::warning('shiftCommission failed: ' . $e->getMessage());
                    $error = 'Could not compute: ' . $e->getMessage();
                }
            }
        }

        return response()->view('admin.shift_commission', [
            'locations' => $locations, 'date' => $date, 'location_id' => $locationId,
            'result' => $result, 'error' => $error,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
          ->header('Pragma', 'no-cache');
    }

    private function computeShiftCommission($businessId, $date, $locationId, $lname)
    {
        $start = $date . ' 00:00:00';
        $end   = $date . ' 23:59:59';
        $net = '(tsl.quantity - COALESCE(tsl.quantity_returned, 0)) * (tsl.unit_price_inc_tax - COALESCE(tsl.item_tax, 0))';

        // Every sell receipt at this store for the day, with its EXACT time and
        // who rang it — so sales are split by who was actually on the floor at
        // that minute (not rounded to the hour).
        $txns = DB::table('transactions as t')
            ->join('transaction_sell_lines as tsl', 'tsl.transaction_id', '=', 't.id')
            ->leftJoin('users as u', 'u.id', '=', 't.created_by')
            ->where('t.business_id', $businessId)->where('t.location_id', $locationId)
            ->where('t.type', 'sell')->where('t.status', 'final')->whereNull('t.import_source')
            ->where(function ($q) { $q->where('t.is_whatnot', 0)->orWhereNull('t.is_whatnot'); })
            ->whereBetween('t.transaction_date', [$start, $end])
            ->groupBy('t.id', 't.transaction_date', 't.created_by', 'u.first_name', 'u.last_name')
            ->orderBy('t.transaction_date')
            ->selectRaw("t.id, t.transaction_date, t.created_by as uid, u.first_name, u.last_name, COALESCE(SUM($net), 0) as net")
            ->get();

        $ownRung = [];  // uid => total rung that day
        $nameOf = [];
        foreach ($txns as $r) {
            $uid = (int) $r->uid;
            $ownRung[$uid] = ($ownRung[$uid] ?? 0) + (float) $r->net;
            if ($uid > 0) { $nameOf[$uid] = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: ('User #' . $uid); }
        }

        // Whitelisted floor shifts (Front Desk / Event Lead / Sales Floor Lead)
        // published in Sling for this store on this day, as exact time intervals.
        $shifts = \App\SlingShift::where('event_type', \App\SlingShift::TYPE_SHIFT)
            ->where('published', 1)->whereNotNull('erp_user_id')
            ->whereDate('dtstart', $date)->get();

        $matchAll = DB::table('business_locations')->where('business_id', $businessId)->where('is_active', 1)->count() <= 1;
        $lkey = strtolower(trim((string) $lname));
        $lfirst = strtolower(trim(explode(' ', $lkey)[0] ?? ''));

        $intervals = [];  // uid => [[startTs, endTs], ...]
        $shiftSpan = [];  // uid => ['from'=>, 'to'=>, 'pos'=>[]]
        foreach ($shifts as $s) {
            $pos = strtolower(trim((string) ($s->position_name ?? '')));
            $isFloor = false;
            foreach ($this->floorPositions as $fp) { if ($pos !== '' && strpos($pos, $fp) !== false) { $isFloor = true; break; } }
            if (!$isFloor) { continue; }
            if (!$matchAll) {
                $sl = strtolower(trim((string) ($s->location_name ?? '')));
                $lm = ($sl !== '' && ($sl === $lkey || strpos($sl, $lkey) !== false || strpos($lkey, $sl) !== false || ($lfirst !== '' && strpos($sl, $lfirst) !== false)));
                if (!$lm) { continue; }
            }
            $uid = (int) $s->erp_user_id;
            $nameOf[$uid] = ($nameOf[$uid] ?? '') ?: ($s->user_name ?: ('User #' . $uid));
            $ss = \Carbon::parse($s->dtstart);
            $se = $s->dtend ? \Carbon::parse($s->dtend) : $ss->copy()->endOfDay();
            $intervals[$uid][] = [$ss->timestamp, $se->timestamp];
            if (!isset($shiftSpan[$uid])) { $shiftSpan[$uid] = ['from' => $ss->copy(), 'to' => $se->copy(), 'pos' => []]; }
            if ($ss->lt($shiftSpan[$uid]['from'])) { $shiftSpan[$uid]['from'] = $ss->copy(); }
            if ($se->gt($shiftSpan[$uid]['to'])) { $shiftSpan[$uid]['to'] = $se->copy(); }
            $shiftSpan[$uid]['pos'][ucwords($pos)] = true;
        }

        // Goals + each cashier's ACTUAL per-cashier sales bonus, straight from the
        // ERP. We REDISTRIBUTE this exact bonus — never add to it — so the store
        // total is unchanged; the helper's share comes OUT of the cashier's.
        $goals = collect();
        $goalNote = null;
        try {
            $goals = app(\App\Http\Controllers\ReportController::class)->salesBonusByUser($businessId, $date, $date);
        } catch (\Throwable $e) {
            $goalNote = 'Bonus/goal numbers unavailable (' . $e->getMessage() . ').';
        }

        $presentAt = function ($ts) use ($intervals) {
            $on = [];
            foreach ($intervals as $uid => $ivs) {
                foreach ($ivs as $iv) { if ($ts >= $iv[0] && $ts < $iv[1]) { $on[] = $uid; break; } }
            }
            return $on;
        };

        // Walk each cashier's receipts in time order. The part of each sale ABOVE
        // their running goal is "overage" — the only thing the bonus is paid on.
        // Overage rung while 2+ share the floor is SPLIT among them; solo overage
        // stays the cashier's. Weights are in overage dollars.
        $byCashier = [];
        foreach ($txns as $r) { $byCashier[(int) $r->uid][] = $r; }

        $wSolo = []; $wParty = []; $cashierOverage = [];
        foreach ($byCashier as $cuid => $rows) {
            $g = $goals->get($cuid);
            $goal = $g ? (float) $g->goal : 0.0;
            $cum = 0.0;
            foreach ($rows as $r) {
                $net = (float) $r->net;
                if ($net <= 0) { $cum += $net; continue; }
                $cum += $net;
                $overage = $cum > $goal ? min($net, $cum - $goal) : 0.0;
                if ($overage <= 0) { continue; }
                $cashierOverage[$cuid] = ($cashierOverage[$cuid] ?? 0) + $overage;
                $present = $presentAt(\Carbon::parse($r->transaction_date)->timestamp);
                $ringerOn = in_array($cuid, $present, true);
                if ($ringerOn && count($present) > 1) {
                    // The person who rang it is on a floor shift alongside others —
                    // this is genuine shared-floor selling, so split it.
                    $each = $overage / count($present);
                    foreach ($present as $uid) { $wParty[$cuid][$uid] = ($wParty[$cuid][$uid] ?? 0) + $each; }
                } else {
                    // Ringer working solo — or still ringing after their own floor
                    // shift ended (a hand-off). Keep it theirs; never hand it to
                    // whoever else happens to be clocked in at that moment.
                    $wSolo[$cuid][$cuid] = ($wSolo[$cuid][$cuid] ?? 0) + $overage;
                }
            }
        }

        // Hand out each cashier's real bonus in those proportions. Sum over
        // everyone == the cashier's own bonus, so nothing is created or lost.
        $ownBonus = []; $partyBonus = [];
        foreach ($cashierOverage as $cuid => $tot) {
            $g = $goals->get($cuid);
            $B = $g ? (float) $g->bonus : 0.0;
            if ($tot <= 0 || $B <= 0) { continue; }
            foreach (($wSolo[$cuid] ?? []) as $uid => $wt)  { $ownBonus[$uid]   = ($ownBonus[$uid] ?? 0)   + $B * ($wt / $tot); }
            foreach (($wParty[$cuid] ?? []) as $uid => $wt) { $partyBonus[$uid] = ($partyBonus[$uid] ?? 0) + $B * ($wt / $tot); }
        }

        $people = [];
        $uids = array_unique(array_merge(array_keys($ownBonus), array_keys($partyBonus), array_keys($shiftSpan)));
        foreach ($uids as $uid) {
            $uid = (int) $uid;
            $own    = round($ownBonus[$uid] ?? 0, 2);
            $partyC = round($partyBonus[$uid] ?? 0, 2);
            $total  = round($own + $partyC, 2);
            if ($total <= 0 && !isset($shiftSpan[$uid])) { continue; }
            $g = $goals->get($uid);
            $span = $shiftSpan[$uid] ?? null;
            $people[] = [
                'uid' => $uid,
                'name' => $nameOf[$uid] ?? ('User #' . $uid),
                'shift' => $span ? ($span['from']->format('g:i A') . ' - ' . $span['to']->format('g:i A')) : '(no floor shift)',
                'positions' => $span ? implode(', ', array_keys($span['pos'])) : '',
                'own_rung' => round($ownRung[$uid] ?? 0, 2),
                'goal' => $g ? round((float) $g->goal, 2) : 0.0,
                'raw_bonus' => $g ? round((float) $g->bonus, 2) : 0.0, // their bonus BEFORE the party split
                'own_bonus' => $own,      // their solo-hours bonus (kept)
                'party_bonus' => $partyC, // their share of shared-hours bonus
                'total' => $total,        // what they actually get after the split
            ];
        }
        usort($people, function ($a, $b) { return $b['total'] <=> $a['total']; });

        return [
            'date' => $date, 'store' => $lname,
            'people' => $people, 'names' => $nameOf,
            'goal_note' => $goalNote,
            'store_sales' => round(array_sum($ownRung), 2),
            'total_bonus' => round(array_sum(array_map(function ($p) { return $p['total']; }, $people)), 2),
        ];
    }

    // Apply a day + store's listening-party split to payroll. Snapshots each
    // person's adjustment (their redistributed bonus minus their raw per-cashier
    // bonus) so the Commissions page shifts everyone's sales bonus: the cashier
    // drops by the helper's share, the helper gains it, the store total is
    // unchanged. Re-applying the same date+store replaces the prior snapshot.
    public function partySplitApply(Request $request)
    {
        $businessId = $request->session()->get('user.business_id');
        $date = trim((string) $request->input('date', ''));
        $locationId = (int) $request->input('location_id');
        $back = '/admin/shift-commission?date=' . urlencode($date) . '&location_id=' . $locationId;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $locationId <= 0) {
            return redirect('/admin/shift-commission')->with('status', ['success' => 0, 'msg' => 'Pick a date and store first.']);
        }
        if (strcmp($date, self::PARTY_SPLIT_FROM) < 0) {
            return redirect($back)->with('status', ['success' => 0, 'msg' => 'The party split only applies from ' . self::PARTY_SPLIT_FROM . ' on.']);
        }

        $lname = DB::table('business_locations')->where('id', $locationId)->value('name') ?: ('Store #' . $locationId);
        try {
            $res = $this->computeShiftCommission($businessId, $date, $locationId, $lname);
        } catch (\Throwable $e) {
            return redirect($back)->with('status', ['success' => 0, 'msg' => 'Could not compute the split: ' . $e->getMessage()]);
        }

        $adj = []; $party = []; $detail = [];
        foreach ($res['people'] as $p) {
            $uid = (int) $p['uid'];
            $a = round($p['total'] - $p['raw_bonus'], 2);
            $pc = round($p['party_bonus'] ?? 0, 2);   // what they EARNED for the party (equal split)
            if (abs($a) < 0.005 && abs($pc) < 0.005) { continue; }
            if (abs($a) >= 0.005) { $adj[$uid] = $a; }
            if (abs($pc) >= 0.005) { $party[$uid] = $pc; }
            $detail[] = ['uid' => $uid, 'name' => $p['name'], 'raw' => $p['raw_bonus'], 'new' => $p['total'], 'adj' => $a, 'party_earned' => $pc];
        }

        $all = $this->loadPartySplits();
        $all[$date . '|' . $locationId] = [
            'date' => $date, 'location_id' => $locationId, 'store' => $lname,
            'applied_at' => now()->toDateTimeString(), 'applied_by' => $request->session()->get('user.id'),
            'adj' => $adj, 'party' => $party, 'detail' => $detail,
        ];
        $this->savePartySplits($all);

        return redirect($back)->with('status', [
            'success' => 1,
            'msg' => 'Applied the ' . $date . ' ' . $lname . ' party split to payroll — the Commissions page now reflects it (cashier down, helper up, total unchanged).',
        ]);
    }

    public function partySplitUndo(Request $request)
    {
        $date = trim((string) $request->input('date', ''));
        $locationId = (int) $request->input('location_id');
        $all = $this->loadPartySplits();
        $key = $date . '|' . $locationId;
        // Leave a tombstone so the nightly auto-apply won't just re-add it.
        $all[$key] = ['date' => $date, 'location_id' => $locationId, 'removed' => true,
            'removed_at' => now()->toDateTimeString(), 'adj' => [], 'party' => []];
        $this->savePartySplits($all);
        return redirect('/admin/shift-commission?date=' . urlencode($date) . '&location_id=' . $locationId)
            ->with('status', ['success' => 1, 'msg' => 'Removed that party split from payroll (it won\'t auto-re-add).']);
    }

    // Nightly automation: scan recent days for any store where two whitelisted
    // floor staff shared the register (a party / double-staffed peak), and apply
    // the split automatically — so nobody has to Calculate + Apply by hand.
    // Only PRE-FILLS the split; paying still requires a human "Mark paid". Skips
    // days already applied by hand and days removed by hand (tombstones).
    public function autoApplyPartySplits($businessId, $days = 10)
    {
        $start = \Carbon::parse(self::PARTY_SPLIT_FROM);
        $earliest = \Carbon::today()->subDays(max(1, (int) $days));
        if ($start->lt($earliest)) { $start = $earliest; }

        $locations = DB::table('business_locations')
            ->where('business_id', $businessId)->where('is_active', 1)->pluck('name', 'id');

        $partyDates = $this->partyDates();
        $all = $this->loadPartySplits();

        // Prune phantom auto-splits: a split may ONLY exist on a real party date.
        // Drop any auto entry on a non-party day (ordinary shared-floor selling
        // that was miscredited as a "listening party"); leave manual apply/removal
        // alone.
        foreach (array_keys($all) as $k) {
            $entry = $all[$k];
            if (!empty($entry['removed']) || empty($entry['auto'])) { continue; }
            $ed = $entry['date'] ?? (explode('|', (string) $k)[0]);
            if (!in_array($ed, $partyDates, true)) { unset($all[$k]); }
        }

        $applied = 0;
        for ($d = $start->copy(); $d->lte(\Carbon::today()); $d->addDay()) {
            $date = $d->toDateString();
            if (!in_array($date, $partyDates, true)) { continue; } // real party days only
            foreach ($locations as $lid => $lname) {
                $key = $date . '|' . (int) $lid;
                if (isset($all[$key]) && (!empty($all[$key]['removed']) || empty($all[$key]['auto']))) {
                    continue; // respect manual apply / manual removal
                }
                try {
                    $res = $this->computeShiftCommission($businessId, $date, (int) $lid, $lname);
                } catch (\Throwable $e) {
                    continue;
                }
                $adj = []; $party = [];
                foreach ($res['people'] as $p) {
                    $a = round($p['total'] - $p['raw_bonus'], 2);
                    $pc = round($p['party_bonus'] ?? 0, 2);
                    if (abs($a) >= 0.005) { $adj[(int) $p['uid']] = $a; }
                    if (abs($pc) >= 0.005) { $party[(int) $p['uid']] = $pc; }
                }
                if (empty($party)) {
                    // No shared-floor bonus that day — drop a stale auto entry.
                    if (isset($all[$key])) { unset($all[$key]); }
                    continue;
                }
                $all[$key] = [
                    'date' => $date, 'location_id' => (int) $lid, 'store' => $lname, 'auto' => true,
                    'applied_at' => \Carbon::now()->toDateTimeString(), 'adj' => $adj, 'party' => $party,
                ];
                $applied++;
            }
        }
        $this->savePartySplits($all);
        return $applied;
    }

    // Sum of all applied party-split adjustments per user (positive for helpers,
    // negative for cashiers; the total across everyone is zero). Read from a small
    // sidecar, so the Commissions page stays fast — no per-day recompute.
    // Auto listening-party split is DISABLED (Sarah 2026-08-06). It split ALL
    // shared-floor overage across the WHOLE day among everyone on shift, so it
    // credited people who never worked the party (Mica $1, Jacob $2, Luis,
    // Clyde). It has no notion of the party's time window or who attended, so it
    // can't be trusted. Listening-party bonuses are now paid DELIBERATELY through
    // /admin/party-bonus (pick the date, the party window, and exactly who worked
    // it). An empty list here means no auto split counts anywhere: the read
    // filters skip every entry and the nightly job prunes the file to empty.
    public function partyDates()
    {
        // Held EMPTY on purpose. Re-enabling the July dates (7/10, 7/18) restored the
        // auto-split EARNED side, but the actual payments for those parties don't
        // match the split (helpers came up owed, cashiers overpaid) - and some of
        // those same people were just paid at the no-split amounts in today's run.
        // Reconciling that needs the real payment records traced per party, done
        // deliberately, NOT a live toggle. Leave empty until that's worked through
        // with Sarah (2026-08-06).
        return [];
    }

    private function partySplitAdjustmentsByUser()
    {
        $partyDates = $this->partyDates();
        $out = [];
        foreach ($this->loadPartySplits() as $entry) {
            if (!in_array($entry['date'] ?? '', $partyDates, true)) { continue; }
            foreach (($entry['adj'] ?? []) as $uid => $amt) {
                $out[(int) $uid] = ($out[(int) $uid] ?? 0) + (float) $amt;
            }
        }
        return $out;
    }

    // What each person EARNED for the party (their equal 50/50 share) — shown as
    // the "Listening party" column so both the cashier and the helper read the
    // same amount, separate from the +/- movement in the pay adjustment above.
    private function partyEarnedByUser()
    {
        $partyDates = $this->partyDates();
        $out = [];
        foreach ($this->loadPartySplits() as $entry) {
            if (!in_array($entry['date'] ?? '', $partyDates, true)) { continue; }
            foreach (($entry['party'] ?? []) as $uid => $amt) {
                $out[(int) $uid] = ($out[(int) $uid] ?? 0) + (float) $amt;
            }
        }
        return $out;
    }

    // Listening-party bonuses paid by hand via /admin/party-bonus are written to the
    // SALES payout ledger with a note starting "Listening party ...". Sum them per
    // user so the Commissions page can SHOW them in the Listening party column even
    // once they're paid (the column otherwise reads $0 owed and goes blank). Display
    // only - does not touch anyone's Pay now.
    private function manualPartyEarnedByUser()
    {
        // Only the last 14 days (one pay period) — this column is meant to read
        // as "this payroll cycle's party money", not a growing all-time total
        // that drifts further from Pay now with every past party (Sarah 2026-08-20).
        $cutoff = \Carbon::now()->subDays(14)->toDateString();
        $out = [];
        foreach ($this->loadSalesPayouts() as $p) {
            if (stripos((string) ($p['note'] ?? ''), 'Listening party') !== 0) { continue; }
            $eventDate = (string) ($p['from_date'] ?? ($p['marked_at'] ?? ''));
            if ($eventDate !== '' && $eventDate < $cutoff) { continue; }
            $uid = (int) ($p['user_id'] ?? 0);
            if ($uid > 0) { $out[$uid] = ($out[$uid] ?? 0) + (float) ($p['amount'] ?? 0); }
        }
        return $out;
    }

    private function loadPartySplits()
    {
        if (!Storage::disk('local')->exists(self::PARTY_SPLIT_FILE)) { return []; }
        $d = json_decode(Storage::disk('local')->get(self::PARTY_SPLIT_FILE), true);
        return is_array($d) ? $d : [];
    }

    private function savePartySplits(array $d)
    {
        Storage::disk('local')->put(self::PARTY_SPLIT_FILE, json_encode($d, JSON_PRETTY_PRINT));
    }

    // Freeze a payroll run: snapshot everyone's CURRENT owed (listing + sales)
    // into a locked list you pay against, so the sales bonus can't drift under
    // you mid-payroll. Read-only reference — it does not touch the payout ledgers.
    public function freeze(Request $request)
    {
        $businessId = $request->session()->get('user.business_id');
        $from = self::DEFAULT_FROM;

        $paid = $this->loadPayouts();
        $paidSet = array_flip($this->paidLineIds($paid));

        $listing = [];
        $names = [];
        foreach ($this->ownedSoldLines($businessId, $from, []) as $row) {
            if (isset($paidSet[(int) $row->line_id])) { continue; }
            $uid = (int) $row->user_id;
            $listing[$uid] = ($listing[$uid] ?? 0) + (float) $row->sale_amount * self::RATE;
            $names[$uid] = $this->personName($row);
        }

        $sales = $this->salesSummaryByUser($businessId);

        $uids = array_unique(array_merge(array_keys($listing), array_map('intval', $sales->keys()->all())));
        $people = [];
        foreach ($uids as $uid) {
            $uid = (int) $uid;
            $name = $names[$uid] ?? null;
            if ($name === null) {
                $u = DB::table('users')->where('id', $uid)->first();
                $name = $u ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->surname ?? ('User #' . $uid))) : ('User #' . $uid);
            }
            if ($this->isExcludedName($name)) { continue; }
            $lo = round($listing[$uid] ?? 0, 2);
            $s = $sales->get($uid);
            $so = $s ? round((float) $s->owed, 2) : 0.0;
            if ($lo <= 0 && $so <= 0) { continue; }
            $people[] = [
                'user_id' => $uid, 'name' => $name,
                'listing_owed' => $lo, 'sales_owed' => $so, 'total' => round($lo + $so, 2),
            ];
        }
        usort($people, function ($a, $b) { return $b['total'] <=> $a['total']; });

        $this->saveFreeze([
            'frozen_at' => now()->toDateTimeString(),
            'frozen_by' => $request->session()->get('user.id'),
            'people'    => $people,
        ]);

        return redirect('/admin/listing-commissions')->with('status', [
            'success' => 1,
            'msg'     => 'Payroll amounts frozen. Pay against the locked list — it will not move.',
        ]);
    }

    public function unfreeze(Request $request)
    {
        $this->clearFreeze();
        return redirect('/admin/listing-commissions')->with('status', [
            'success' => 1,
            'msg'     => 'Freeze cleared — the page is back to live amounts.',
        ]);
    }

    // Bulk-record a past payroll. Paste one person per line:
    //   Name, Listing $, Sales $   (comma OR tab separated)
    // Everything is stamped with the chosen date. Entries that already exist for
    // that person+date+amount are SKIPPED, so pasting a payroll that's partly on
    // the ledger can't double-count. Unmatched names are reported, never guessed.
    public function bulkRecord(Request $request)
    {
        $businessId = $request->session()->get('user.business_id');
        $date = trim((string) $request->input('date', ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return redirect('/admin/listing-commissions')
                ->with('status', ['success' => 0, 'msg' => 'Pick a valid payroll date first.']);
        }
        $raw = (string) $request->input('rows', '');
        $note = trim((string) $request->input('note', '')) ?: ('Payroll ' . $date);

        $candidates = DB::table('users')->where('business_id', $businessId)
            ->select('id', 'first_name', 'last_name', 'surname')->get();

        $matchUser = function ($name) use ($candidates) {
            $name = strtolower(trim($name));
            if ($name === '') { return null; }
            $tokens = preg_split('/\s+/', $name);
            $first = $tokens[0];
            $lastInit = isset($tokens[1]) ? substr($tokens[1], 0, 1) : null;
            $hits = [];
            foreach ($candidates as $c) {
                $cf = strtolower(trim($c->first_name ?? ''));
                if ($cf === '') { continue; }
                if ($cf === $first || strpos($cf, $first) === 0 || strpos($first, $cf) === 0) { $hits[] = $c; }
            }
            if (count($hits) === 1) { return $hits[0]; }
            if (count($hits) > 1 && $lastInit) {
                $narrow = array_values(array_filter($hits, function ($c) use ($lastInit) {
                    return strtolower(substr(trim($c->last_name ?? ''), 0, 1)) === $lastInit;
                }));
                if (count($narrow) === 1) { return $narrow[0]; }
            }
            return null;
        };

        $paid  = $this->loadPayouts();
        $sales = $this->loadSalesPayouts();

        $exists = function ($ledger, $uid, $amount) use ($date) {
            foreach ($ledger as $p) {
                $pdate = substr((string) ($p['from_date'] ?? $p['marked_at'] ?? ''), 0, 10);
                if ((int) ($p['user_id'] ?? 0) === $uid && $pdate === $date
                    && abs((float) ($p['amount'] ?? 0) - $amount) < 0.005) {
                    return true;
                }
            }
            return false;
        };

        $recorded = 0; $skipped = 0; $unmatched = [];
        $markedBy = $request->session()->get('user.id');
        $markedAt = $date . ' 12:00:00';

        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            $parts = preg_split('/\t|,/', $line);
            $parts = array_map('trim', $parts);
            $name = $parts[0] ?? '';
            if ($name === '') { continue; }
            $listing = isset($parts[1]) ? (float) preg_replace('/[^0-9.\-]/', '', $parts[1]) : 0.0;
            $salesAmt = isset($parts[2]) ? (float) preg_replace('/[^0-9.\-]/', '', $parts[2]) : 0.0;

            $u = $matchUser($name);
            if (!$u) { $unmatched[] = $name; continue; }
            $uid = (int) $u->id;
            $full = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->surname ?? ('User #' . $uid));

            if ($listing > 0) {
                if ($exists($paid, $uid, round($listing, 2))) { $skipped++; }
                else {
                    $paid[] = [
                        'id' => bin2hex(random_bytes(8)), 'user_id' => $uid, 'name' => $full,
                        'count' => 0, 'amount' => round($listing, 2), 'line_ids' => [],
                        'from_date' => $date, 'to_date' => $date, 'manual' => true, 'note' => $note,
                        'marked_by' => $markedBy, 'marked_at' => $markedAt,
                    ];
                    $recorded++;
                }
            }
            if ($salesAmt > 0) {
                if ($exists($sales, $uid, round($salesAmt, 2))) { $skipped++; }
                else {
                    $sales[] = [
                        'id' => bin2hex(random_bytes(8)), 'user_id' => $uid, 'name' => $full,
                        'amount' => round($salesAmt, 2), 'from_date' => $date, 'to_date' => $date,
                        'manual' => true, 'note' => $note, 'marked_by' => $markedBy, 'marked_at' => $markedAt,
                    ];
                    $recorded++;
                }
            }
        }

        $this->savePayouts($paid);
        $this->saveSalesPayouts($sales);

        $msg = "Recorded {$recorded} entr" . ($recorded === 1 ? 'y' : 'ies') . " for {$date}.";
        if ($skipped > 0) { $msg .= " Skipped {$skipped} already on file (no double-count)."; }
        if (!empty($unmatched)) { $msg .= ' Could not match (nothing recorded for these — fix the name and re-paste): ' . implode(', ', array_unique($unmatched)) . '.'; }

        return redirect('/admin/listing-commissions')
            ->with('status', ['success' => empty($unmatched) ? 1 : 0, 'msg' => $msg]);
    }

    private function loadFreeze()
    {
        if (!Storage::disk('local')->exists(self::FREEZE_FILE)) { return null; }
        $data = json_decode(Storage::disk('local')->get(self::FREEZE_FILE), true);
        return is_array($data) ? $data : null;
    }

    private function saveFreeze(array $data)
    {
        Storage::disk('local')->put(self::FREEZE_FILE, json_encode($data, JSON_PRETTY_PRINT));
    }

    private function clearFreeze()
    {
        if (Storage::disk('local')->exists(self::FREEZE_FILE)) {
            Storage::disk('local')->delete(self::FREEZE_FILE);
        }
    }

    // Group both payout ledgers by payroll date, then by person, so the paid
    // history reads like the payroll sheet: one row per person per run with
    // Listing + Sales + Total (instead of a separate row per payout per type).
    private function groupPaidHistory($history, $salesHistory)
    {
        $rows = [];
        foreach ($history as $h) {
            $rows[] = ['kind' => 'listing', 'date' => substr((string) ($h['marked_at'] ?? ''), 0, 10),
                'uid' => (int) ($h['user_id'] ?? 0), 'name' => $h['name'] ?? ('User #' . ($h['user_id'] ?? '?')),
                'amount' => (float) ($h['amount'] ?? 0), 'items' => (int) ($h['count'] ?? 0),
                'id' => $h['id'] ?? '', 'route' => 'undo-payout', 'note' => ''];
        }
        foreach ($salesHistory as $h) {
            $manual = !empty($h['manual']);
            $rows[] = ['kind' => $manual ? 'manual' : 'sales', 'date' => substr((string) ($h['marked_at'] ?? ''), 0, 10),
                'uid' => (int) ($h['user_id'] ?? 0), 'name' => $h['name'] ?? ('User #' . ($h['user_id'] ?? '?')),
                'amount' => (float) ($h['amount'] ?? 0), 'items' => 0,
                'id' => $h['id'] ?? '', 'route' => 'undo-sales-payout', 'note' => $h['note'] ?? ''];
        }

        $byDate = [];
        foreach ($rows as $r) { $byDate[$r['date']][] = $r; }
        krsort($byDate);

        $groups = [];
        foreach ($byDate as $date => $drows) {
            $byUser = [];
            foreach ($drows as $r) {
                $uid = $r['uid'];
                if (!isset($byUser[$uid])) {
                    $byUser[$uid] = ['uid' => $uid, 'name' => $r['name'], 'listing' => 0.0, 'sales' => 0.0,
                        'party' => 0.0, 'items' => 0, 'undos' => [], 'notes' => []];
                }
                if ($r['kind'] === 'listing') {
                    $byUser[$uid]['listing'] += $r['amount'];
                    $byUser[$uid]['items'] += $r['items'];
                } elseif (stripos((string) ($r['note'] ?? ''), 'Listening party') === 0) {
                    // Party bonuses live in the sales ledger with a "Listening party" note;
                    // break them out into their own column so the payment record is clear.
                    $byUser[$uid]['party'] += $r['amount'];
                    if ($r['note'] !== '') { $byUser[$uid]['notes'][] = $r['note']; }
                } else {
                    $byUser[$uid]['sales'] += $r['amount'];
                    if ($r['note'] !== '') { $byUser[$uid]['notes'][] = $r['note']; }
                }
                if ($r['id'] !== '') {
                    $byUser[$uid]['undos'][] = ['id' => $r['id'], 'route' => $r['route'], 'label' => ucfirst($r['kind'])];
                }
            }
            $out = [];
            $dtotal = 0.0;
            foreach ($byUser as $u) {
                $u['listing'] = round($u['listing'], 2);
                $u['sales']   = round($u['sales'], 2);
                $u['party']   = round($u['party'], 2);
                $u['total']   = round($u['listing'] + $u['sales'] + $u['party'], 2);
                $dtotal += $u['total'];
                $out[] = $u;
            }
            usort($out, function ($a, $b) { return strcmp($a['name'], $b['name']); });
            $groups[] = ['date' => $date, 'total' => round($dtotal, 2), 'rows' => $out];
        }
        return $groups;
    }

    // Snapshot the person's currently-owed sales commission as a payout. Running
    // balance: owed always = cumulative earned − total paid, so marking paid
    // again only covers what's accrued since the last payout.
    public function markSalesPaid(Request $request)
    {
        $userId = (int) $request->input('user_id');
        $businessId = $request->session()->get('user.business_id');

        if ($userId <= 0) {
            return redirect('/admin/listing-commissions')
                ->with('status', ['success' => 0, 'msg' => 'Missing person.']);
        }

        $summary = $this->salesSummaryByUser($businessId)->get($userId);
        $owed = $summary ? (float) $summary->owed : 0.0;
        if ($owed <= 0) {
            return redirect('/admin/listing-commissions')
                ->with('status', ['success' => 0, 'msg' => 'No sales commission outstanding for that person.']);
        }

        $u = DB::table('users')->where('id', $userId)->first();
        $payouts = $this->loadSalesPayouts();
        $payouts[] = [
            'id'        => bin2hex(random_bytes(8)),
            'user_id'   => $userId,
            'name'      => $u ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->surname ?? ('User #' . $userId))) : ('User #' . $userId),
            'amount'    => round($owed, 2),
            'from_date' => self::SALES_BONUS_FROM,
            'to_date'   => now()->toDateString(),
            'marked_by' => $request->session()->get('user.id'),
            'marked_at' => now()->toDateTimeString(),
        ];
        $this->saveSalesPayouts($payouts);

        return redirect('/admin/listing-commissions')->with('status', [
            'success' => 1,
            'msg'     => 'Marked $' . number_format($owed, 2) . ' sales commission paid for ' . ($u->first_name ?? 'that person') . '.',
        ]);
    }

    public function undoSalesPayout(Request $request)
    {
        $id = preg_replace('/[^a-f0-9]/', '', (string) $request->input('id'));
        $payouts = $this->loadSalesPayouts();
        $before = count($payouts);
        $payouts = array_values(array_filter($payouts, function ($p) use ($id) {
            return ($p['id'] ?? '') !== $id;
        }));
        if (count($payouts) === $before) {
            return redirect('/admin/listing-commissions')
                ->with('status', ['success' => 0, 'msg' => 'Sales payout not found.']);
        }
        $this->saveSalesPayouts($payouts);
        return redirect('/admin/listing-commissions')
            ->with('status', ['success' => 1, 'msg' => 'Sales payout undone — that commission is owed again.']);
    }

    private function loadSalesPayouts()
    {
        if (!Storage::disk('local')->exists(self::SALES_PAYOUTS_FILE)) {
            return [];
        }
        $data = json_decode(Storage::disk('local')->get(self::SALES_PAYOUTS_FILE), true);
        return is_array($data) ? $data : [];
    }

    private function saveSalesPayouts(array $payouts)
    {
        Storage::disk('local')->put(self::SALES_PAYOUTS_FILE, json_encode(array_values($payouts), JSON_PRETTY_PRINT));
    }

    public function markPaid(Request $request)
    {
        $from = $this->normalizeFrom($request->input('from'));
        $userId = (int) $request->input('user_id');
        $businessId = $request->session()->get('user.business_id');

        if ($userId <= 0) {
            return redirect($this->backUrl($from))
                ->with('status', ['success' => 0, 'msg' => 'Missing person.']);
        }

        $paid = $this->loadPayouts();
        $paidLineIds = $this->paidLineIds($paid);
        $lines = $this->ownedSoldLines($businessId, $from, $paidLineIds)
            ->where('user_id', $userId)
            ->values();

        if ($lines->isEmpty()) {
            return redirect($this->backUrl($from))
                ->with('status', ['success' => 0, 'msg' => 'Nothing outstanding for that person.']);
        }

        $amount = 0.0;
        $lineIds = [];
        foreach ($lines as $row) {
            $amount += (float) $row->sale_amount * self::RATE;
            $lineIds[] = (int) $row->line_id;
        }

        $paid[] = [
            'id'         => bin2hex(random_bytes(8)),
            'user_id'    => $userId,
            'name'       => $this->personName($lines->first()),
            'count'      => count($lineIds),
            'amount'     => round($amount, 2),
            'line_ids'   => $lineIds,
            'from_date'  => $from,
            'to_date'    => now()->toDateString(),
            'marked_by'  => $request->session()->get('user.id'),
            'marked_at'  => now()->toDateTimeString(),
        ];

        $this->savePayouts($paid);

        return redirect($this->backUrl($from))->with('status', [
            'success' => 1,
            'msg'     => 'Marked ' . count($lineIds) . ' sold item(s) paid — $' . number_format($amount, 2) . '.',
        ]);
    }

    public function undoPayout(Request $request)
    {
        $from = $this->normalizeFrom($request->input('from'));
        $id = preg_replace('/[^a-f0-9]/', '', (string) $request->input('id'));

        $paid = $this->loadPayouts();
        $before = count($paid);
        $paid = array_values(array_filter($paid, function ($p) use ($id) {
            return ($p['id'] ?? '') !== $id;
        }));

        if (count($paid) === $before) {
            return redirect($this->backUrl($from))
                ->with('status', ['success' => 0, 'msg' => 'Payout not found.']);
        }

        $this->savePayouts($paid);

        return redirect($this->backUrl($from))
            ->with('status', ['success' => 1, 'msg' => 'Payout undone — those sales are owed again.']);
    }

    // Per-user listing-commission summary since $from, keyed by user_id:
    // owed (unpaid, current formula), paid (actual dollars from the payout
    // ledger), and earned = owed + paid. Reuses the exact same owed/paid
    // sources as the page above, so anything that renders this reconciles with
    // /admin/listing-commissions to the penny. Used by the Employee Leaderboard
    // so its listing numbers match this page (Sarah 2026-06-21).
    public function summaryByUser($businessId, $from = self::DEFAULT_FROM)
    {
        $from = $this->normalizeFrom($from);
        $paid = $this->loadPayouts();
        $paidLineIds = $this->paidLineIds($paid);

        $owedByUser = [];
        foreach ($this->ownedSoldLines($businessId, $from, $paidLineIds) as $row) {
            $uid = (int) $row->user_id;
            $owedByUser[$uid] = ($owedByUser[$uid] ?? 0) + (float) $row->sale_amount * self::RATE;
        }

        $paidByUser = [];
        foreach ($paid as $p) {
            $uid = (int) ($p['user_id'] ?? 0);
            if ($uid <= 0) { continue; }
            $paidByUser[$uid] = ($paidByUser[$uid] ?? 0) + (float) ($p['amount'] ?? 0);
        }

        $out = [];
        foreach (array_unique(array_merge(array_keys($owedByUser), array_keys($paidByUser))) as $uid) {
            $owed = round($owedByUser[$uid] ?? 0, 2);
            $pd   = round($paidByUser[$uid] ?? 0, 2);
            $out[$uid] = (object) ['owed' => $owed, 'paid' => $pd, 'earned' => round($owed + $pd, 2)];
        }
        return collect($out);
    }

    // Unpaid sold lines: one row per item sold (final sell) whose product was
    // listed on/after $from by the lister (products.created_by), excluding
    // sealed/new stock + non-vinyl categories, with the realized sale value and
    // excluding lines already covered by a payout. Filters mirror
    // ReportController::barcodingCommissionByUser exactly.
    private function ownedSoldLines($businessId, $from, array $paidLineIds)
    {
        $start = $from . ' 00:00:00';
        $end = now()->toDateTimeString();

        $q = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('products as p', 'tsl.product_id', '=', 'p.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('categories as sc', 'p.sub_category_id', '=', 'sc.id')
            ->join('users as u', 'u.id', '=', 'p.created_by')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('t.import_source')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->whereNotNull('p.created_by')
            ->where('p.created_at', '>=', $start)
            ->where(function ($qq) {
                foreach ($this->excludedCategoryPatterns as $pat) {
                    $qq->where(DB::raw('LOWER(c.name)'), 'NOT LIKE', $pat)
                       ->where(DB::raw('LOWER(COALESCE(sc.name, \'\'))'), 'NOT LIKE', $pat);
                }
                $qq->whereNotIn(DB::raw('LOWER(TRIM(c.name))'), $this->excludedCategoryNames)
                   ->whereNotIn(DB::raw('LOWER(TRIM(COALESCE(sc.name, \'\')))'), $this->excludedCategoryNames);
            });
        $this->excludeOwners($q);

        $rows = $q->select(
                'tsl.id as line_id',
                'p.created_by as user_id',
                'u.first_name',
                'u.last_name',
                'u.surname',
                't.transaction_date',
                // PRE-TAX, net of returns — the exact expression
                // barcodingCommissionByUser uses, so this page's owed matches
                // the Employee Leaderboard's listing pay to the penny. (Earlier
                // this used the tax-INCLUDED price, which over-stated the owed.)
                DB::raw('((tsl.quantity - COALESCE(tsl.quantity_returned, 0)) * (tsl.unit_price_inc_tax - COALESCE(tsl.item_tax, 0))) as sale_amount')
            )
            ->get();

        if (!empty($paidLineIds)) {
            $paidSet = array_flip($paidLineIds);
            $rows = $rows->reject(function ($r) use ($paidSet) {
                return isset($paidSet[$r->line_id]);
            })->values();
        }

        // Drop sales after a person's commission cutoff (someone away who
        // shouldn't accrue while gone) — the sale, not the original listing
        // date, is what matters since that's when the commission is earned.
        if (!empty($this->commissionCutoffByFirstName)) {
            $rows = $rows->reject(function ($r) {
                $first = strtolower(trim((string) ($r->first_name ?? '')));
                if (!isset($this->commissionCutoffByFirstName[$first])) { return false; }
                return substr((string) $r->transaction_date, 0, 10) > $this->commissionCutoffByFirstName[$first];
            })->values();
        }

        return $rows;
    }

    // Each person's home store = the business location where they've rung the
    // most final sell transactions (transactions.created_by). Purely a
    // display/sort aid on this page, keyed by user_id => store name. A back-room
    // lister who never rings sales shows no store (blank), which sorts last.
    private function primaryStoreByUser($businessId)
    {
        $rows = DB::table('transactions as t')
            ->join('business_locations as bl', 'bl.id', '=', 't.location_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('t.import_source')
            ->whereNotNull('t.created_by')
            ->groupBy('t.created_by', 'bl.id', 'bl.name')
            ->selectRaw('t.created_by as user_id, bl.name as store, COUNT(*) as cnt')
            ->get();

        $best = [];
        foreach ($rows as $r) {
            $uid = (int) $r->user_id;
            $cnt = (int) $r->cnt;
            if (!isset($best[$uid]) || $cnt > $best[$uid]['cnt']) {
                $best[$uid] = ['store' => $r->store, 'cnt' => $cnt];
            }
        }
        $out = [];
        foreach ($best as $uid => $b) { $out[$uid] = $b['store']; }
        return $out;
    }

    // Items each person LISTED on/after $from (regardless of whether they've
    // sold), keyed by user_id. Same product + category filters as the
    // commission query so "listed" and "sold" describe the same eligible
    // universe; just no transaction join.
    private function listedTotalsByUser($businessId, $from)
    {
        $start = $from . ' 00:00:00';

        $q = DB::table('products as p')
            ->join('users as u', 'u.id', '=', 'p.created_by')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('categories as sc', 'p.sub_category_id', '=', 'sc.id')
            ->where('p.business_id', $businessId)
            ->whereNotNull('p.created_by')
            ->where('p.created_at', '>=', $start)
            ->where(function ($qq) {
                foreach ($this->excludedCategoryPatterns as $pat) {
                    $qq->where(DB::raw('LOWER(c.name)'), 'NOT LIKE', $pat)
                       ->where(DB::raw('LOWER(COALESCE(sc.name, \'\'))'), 'NOT LIKE', $pat);
                }
                $qq->whereNotIn(DB::raw('LOWER(TRIM(c.name))'), $this->excludedCategoryNames)
                   ->whereNotIn(DB::raw('LOWER(TRIM(COALESCE(sc.name, \'\')))'), $this->excludedCategoryNames);
            });
        $this->excludeOwners($q);

        return $q->selectRaw(
                'p.created_by as user_id, COUNT(*) as listed_count, '
                . 'COALESCE(SUM((SELECT MAX(v.sell_price_inc_tax) FROM variations v '
                . 'WHERE v.product_id = p.id AND v.deleted_at IS NULL)), 0) as listed_value'
            )
            ->groupBy('p.created_by')
            ->get()
            ->keyBy('user_id');
    }

    // Drop owner/back-office accounts from a query that has joined `users as u`.
    private function excludeOwners($q)
    {
        $q->whereNotIn(DB::raw('LOWER(u.first_name)'), $this->excludedOwnerFirstNames);
        foreach ($this->excludedNameContains as $needle) {
            $q->whereRaw(
                "LOWER(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''),' ',COALESCE(u.surname,'')))) NOT LIKE ?",
                ['%' . $needle . '%']
            );
        }
        return $q;
    }

    private function personName($row)
    {
        $name = trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? ''));
        if ($name === '') {
            $name = trim($row->surname ?? '') ?: ('User #' . $row->user_id);
        }
        return $name;
    }

    private function paidLineIds(array $paid)
    {
        $ids = [];
        foreach ($paid as $p) {
            foreach (($p['line_ids'] ?? []) as $lid) {
                $ids[] = (int) $lid;
            }
        }
        return $ids;
    }

    private function loadPayouts()
    {
        if (!Storage::disk('local')->exists(self::PAYOUTS_FILE)) {
            return [];
        }
        $data = json_decode(Storage::disk('local')->get(self::PAYOUTS_FILE), true);
        return is_array($data) ? $data : [];
    }

    private function savePayouts(array $paid)
    {
        Storage::disk('local')->put(self::PAYOUTS_FILE, json_encode(array_values($paid), JSON_PRETTY_PRINT));
    }

    private function normalizeFrom($input)
    {
        $input = is_string($input) ? trim($input) : '';
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $input) ? $input : self::DEFAULT_FROM;
        // Commission doesn't exist before the 2026-05-15 rollout, and the
        // Leaderboard / My Earnings pages are fixed to it. Clamp so a date
        // earlier than the rollout can't pull in pre-rollout listings and make
        // this page disagree with the others (Sarah 2026-06-21).
        return strcmp($from, self::DEFAULT_FROM) < 0 ? self::DEFAULT_FROM : $from;
    }

    private function backUrl($from)
    {
        return '/admin/listing-commissions?from=' . urlencode($from);
    }
}
