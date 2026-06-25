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

    // Owners / back-office + departed non-floor accounts that don't get paid
    // listing commission (mirrors the Employee Leaderboard roster). Owner
    // accounts are also the creator-of-record for bulk/imported listings, so
    // leaving them in inflates counts (e.g. Jon showed 48k listed items).
    // 'henry' left the company (Sarah 2026-06-02). Excluded by first name.
    private $excludedOwnerFirstNames = ['jon', 'jonathan', 'sarah', 'sohaib', 'fatteen', 'henry'];

    // Fatteen's ERP account is named "Nerdy Solutions", so the first-name list
    // above misses it. Also drop any account whose full name contains these.
    private $excludedNameContains = ['nerdy'];

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
        foreach ($byId as $uid => $p) {
            $s = $salesSummary->get($uid);
            $p->sales_earned   = $s ? (float) $s->earned   : 0.0;
            $p->sales_paid     = $s ? (float) $s->paid     : 0.0;
            $p->sales_owed     = $s ? (float) $s->owed     : 0.0;
            $p->sales_goal     = $s ? (float) $s->goal     : 0.0;
            $p->sales_achieved = $s ? (float) $s->achieved : 0.0;
            // Combined cumulative commission across both types.
            $p->total_comm     = round($p->earned + $p->sales_earned, 2);
            $p->total_paid_all = round($p->paid + $p->sales_paid, 2);
            // What you still owe this person right now = unpaid listing + unpaid sales.
            $p->total_owed_now = round($p->owed + $p->sales_owed, 2);

            // Plain-English payroll memo so whoever pays them knows what the
            // money is for (and the person can see it on their pay stub).
            $memo = [];
            if ($p->owed > 0)       { $memo[] = 'Listing commission $' . number_format($p->owed, 2) . ' (' . number_format($p->count) . ' sold item' . ($p->count == 1 ? '' : 's') . ')'; }
            if ($p->sales_owed > 0) { $memo[] = 'Sales bonus $' . number_format($p->sales_owed, 2); }
            $p->payroll_memo = implode('  +  ', $memo);
        }

        $people = collect($byId)->values()->sortByDesc('total_owed_now')->values();

        $history = collect($paid)->sortByDesc('marked_at')->values();
        $salesHistory = collect($this->loadSalesPayouts())->sortByDesc('marked_at')->values();

        return view('admin.listing_commissions', [
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
        ]);
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

        $paidByUser = [];
        foreach ($this->loadSalesPayouts() as $p) {
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

        $parts = [];
        $total = 0.0;
        $name = null;

        // Listing commission.
        $paid = $this->loadPayouts();
        $lines = $this->ownedSoldLines($businessId, $from, $this->paidLineIds($paid))
            ->where('user_id', $userId)->values();
        if ($lines->isNotEmpty()) {
            $amount = 0.0; $lineIds = [];
            foreach ($lines as $row) { $amount += (float) $row->sale_amount * self::RATE; $lineIds[] = (int) $row->line_id; }
            $name = $this->personName($lines->first());
            $paid[] = [
                'id'        => bin2hex(random_bytes(8)),
                'user_id'   => $userId,
                'name'      => $name,
                'count'     => count($lineIds),
                'amount'    => round($amount, 2),
                'line_ids'  => $lineIds,
                'from_date' => $from,
                'to_date'   => now()->toDateString(),
                'marked_by' => $request->session()->get('user.id'),
                'marked_at' => now()->toDateTimeString(),
            ];
            $this->savePayouts($paid);
            $parts[] = 'listing $' . number_format($amount, 2);
            $total += $amount;
        }

        // Sales commission.
        $summary = $this->salesSummaryByUser($businessId)->get($userId);
        $owedSales = $summary ? (float) $summary->owed : 0.0;
        if ($owedSales > 0) {
            $u = DB::table('users')->where('id', $userId)->first();
            $name = $name ?: ($u ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->surname ?? ('User #' . $userId))) : ('User #' . $userId));
            $sp = $this->loadSalesPayouts();
            $sp[] = [
                'id'        => bin2hex(random_bytes(8)),
                'user_id'   => $userId,
                'name'      => $name,
                'amount'    => round($owedSales, 2),
                'from_date' => self::SALES_BONUS_FROM,
                'to_date'   => now()->toDateString(),
                'marked_by' => $request->session()->get('user.id'),
                'marked_at' => now()->toDateTimeString(),
            ];
            $this->saveSalesPayouts($sp);
            $parts[] = 'sales $' . number_format($owedSales, 2);
            $total += $owedSales;
        }

        if (empty($parts)) {
            return redirect('/admin/listing-commissions')
                ->with('status', ['success' => 0, 'msg' => 'Nothing outstanding for that person.']);
        }

        return redirect('/admin/listing-commissions')->with('status', [
            'success' => 1,
            'msg'     => 'Marked paid for ' . ($name ?: 'that person') . ': ' . implode(' + ', $parts) . ' = $' . number_format($total, 2) . '.',
        ]);
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

        return $rows;
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
