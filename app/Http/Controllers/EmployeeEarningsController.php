<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// Employee-facing "My Earnings" page. Any logged-in staff member sees ONLY
// their own listing commission — what they've earned and what's been paid out
// — so they can check their pay themselves instead of asking a manager.
//
// Reuses the EXACT formula and payout ledger from ListingCommissionController
// (2% of the sale value of items they listed that have since sold, since the
// 2026-05-15 rollout, same category exclusions), so the numbers here match the
// admin payables view and the Employee Leaderboard to the penny.
//
// Note: the "labeled / put out" count is shown as a productivity stat only —
// it is NOT paid (confirmed with Sarah 2026-06-20). Pay = listing commission
// (+ a separately-handled sales goal bonus).
class EmployeeEarningsController extends Controller
{
    const PAYOUTS_FILE = 'listing-commission-payouts.json';
    const DEFAULT_FROM = '2026-05-15';
    const RATE = 0.02;

    private $excludedCategoryPatterns = ['%sealed%', '%new vinyl%', '%new cd%', '%new cassette%'];
    private $excludedCategoryNames = [
        'audio gear', 'record players', 'record player',
        'trading cards', 'apparel', 'clothing', 'video games',
        'gift items', 'toys', 'accessories & novelties',
        'acessories & novelties', 'pictures & posters',
    ];

    public function index(Request $request)
    {
        $me = auth()->user();
        $businessId = $request->session()->get('user.business_id');

        // Admins can view any employee's earnings via ?user_id (to preview what
        // a given person sees); everyone else only ever sees themselves.
        $isAdmin = app(\App\Utils\BusinessUtil::class)->is_admin($me);
        $userId = (int) $request->input('user_id', $me->id);
        if ($userId !== (int) $me->id && !$isAdmin) {
            $userId = (int) $me->id;
        }
        $user = ($userId === (int) $me->id) ? $me : (DB::table('users')->where('id', $userId)->first() ?: $me);
        $viewingOther = $isAdmin && $userId !== (int) $me->id;

        $from = self::DEFAULT_FROM;
        $start = $from . ' 00:00:00';
        $end = now()->toDateTimeString();

        // Every qualifying sold line this person listed (paid + unpaid).
        $lines = $this->ownedSoldLines($businessId, $userId, $start, $end);

        // Which of those have already been paid (by line id, from the ledger).
        $payoutsAll = $this->loadPayouts();
        $myPayouts = collect($payoutsAll)
            ->where('user_id', $userId)
            ->sortByDesc('marked_at')
            ->values();
        $paidLineIds = [];
        foreach ($payoutsAll as $p) {
            foreach (($p['line_ids'] ?? []) as $lid) {
                $paidLineIds[(int) $lid] = true;
            }
        }

        $earnedSales = 0.0;
        $owedSales = 0.0;
        $soldCount = 0;
        $owedCount = 0;
        foreach ($lines as $row) {
            $amt = (float) $row->sale_amount;
            $earnedSales += $amt;
            $soldCount++;
            if (!isset($paidLineIds[(int) $row->line_id])) {
                $owedSales += $amt;
                $owedCount++;
            }
        }

        $earned = round($earnedSales * self::RATE, 2);
        $owed   = round($owedSales * self::RATE, 2);
        $paidOut = round($myPayouts->sum('amount'), 2);

        // Sales goal bonus — reuse the leaderboard's exact per-day target math.
        // Only paid since it went live (2026-06-15), so the bonus window starts
        // there, separate from the listing-commission rollout (2026-05-15).
        $bonusFrom = '2026-06-15';
        $salesBonus = ['bonus' => 0.0, 'revenue' => 0.0, 'live' => false, 'per_location' => []];
        try {
            $salesBonus = app(\App\Http\Controllers\ReportController::class)
                ->userSalesBonus($businessId, $userId, $bonusFrom . ' 00:00:00', $end);
        } catch (\Throwable $e) {
            \Log::warning('my-earnings sales bonus failed: ' . $e->getMessage());
        }

        // Productivity context (NOT pay): items listed + items put out (labeled).
        $listedCount = $this->listedCount($businessId, $userId, $start);
        $labeledCount = $this->labeledCount($businessId, $userId, $start, $end);

        // Day-by-day breakdown (recent window) — register sales + listed-sold
        // sales, with listing pay and the sales bonus broken out per day. Reuses
        // the leaderboard engine so each day reconciles with the totals above.
        $dailyDays = 30;
        $dailyStart = \Carbon::parse($end)->subDays($dailyDays - 1)->startOfDay()->toDateTimeString();
        $daily = [];
        try {
            $de = app(\App\Http\Controllers\ReportController::class)
                ->buildDailyEarnings($businessId, $dailyStart, $end, $userId);
            foreach ($de['days'] as $date => $list) {
                foreach ($list as $r) {
                    if ((int) $r['user_id'] === (int) $userId) {
                        $daily[] = ['date' => $date] + $r;
                    }
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('my-earnings daily breakdown failed: ' . $e->getMessage());
        }

        // Admin convenience: a roster of current staff so an admin can jump to
        // any employee's view (?user_id=) without hunting for an id.
        $staff = collect();
        if ($isAdmin) {
            $staff = DB::table('users')
                ->where('business_id', $businessId)
                ->where('status', 'active')
                ->where('allow_login', 1)
                ->orderBy('first_name')->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name', 'username'])
                ->map(function ($u) {
                    return (object) [
                        'id'   => (int) $u->id,
                        'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ($u->username ?? ('User #' . $u->id)),
                    ];
                });
        }

        return view('employee.my_earnings', [
            'name'         => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->username ?? 'You'),
            'from'         => $from,
            'rate_pct'     => self::RATE * 100,
            'earned'       => $earned,
            'paid_out'     => $paidOut,
            'owed'         => $owed,
            'sold_count'   => $soldCount,
            'owed_count'   => $owedCount,
            'listed_count' => $listedCount,
            'labeled_count'=> $labeledCount,
            'payouts'      => $myPayouts,
            'sales_bonus'  => $salesBonus,
            'bonus_from'   => $bonusFrom,
            'viewing_other'=> $viewingOther,
            'target_id'    => $userId,
            'is_admin'     => $isAdmin,
            'staff'        => $staff,
            'daily'        => $daily,
            'daily_days'   => $dailyDays,
            'sales_bonus_live' => $salesBonus['live'] ?? false,
        ]);
    }

    // Admin overview: a row per employee per day showing that day's sales and
    // the commission earned (listing pay + sales bonus). Sales-floor only, gated
    // to admins since it surfaces aggregated sales for everyone.
    public function daily(Request $request)
    {
        $me = auth()->user();
        if (!app(\App\Utils\BusinessUtil::class)->is_admin($me)) {
            abort(403, 'This page is admin-only.');
        }
        $businessId = $request->session()->get('user.business_id');

        // Single-day mode: ?date=YYYY-MM-DD pins the report to one day (defaults
        // to that day's 00:00–23:59). Otherwise fall back to the rolling window.
        $pinnedDate = null;
        $rawDate = trim((string) $request->input('date', ''));
        if ($rawDate !== '') {
            try {
                $pinnedDate = \Carbon::createFromFormat('Y-m-d', $rawDate)->startOfDay();
            } catch (\Exception $e) {
                $pinnedDate = null; // ignore an unparseable date, show the window
            }
        }

        $days = (int) $request->input('days', 14);
        if ($days < 1) { $days = 14; }
        if ($days > 92) { $days = 92; }

        if ($pinnedDate) {
            $start = $pinnedDate->copy()->startOfDay()->toDateTimeString();
            $end   = $pinnedDate->copy()->endOfDay()->toDateTimeString();
        } else {
            $end = now()->toDateTimeString();
            $start = \Carbon::parse($end)->subDays($days - 1)->startOfDay()->toDateTimeString();
        }

        $data = app(\App\Http\Controllers\ReportController::class)
            ->buildDailyEarnings($businessId, $start, $end, null, true);

        // Optional single-employee filter. We always build the full dataset so
        // the dropdown lists everyone; when a valid ?user= is passed we narrow
        // the day rows to that person so the stat cards + day totals reflect
        // just them. user=0 (or unset/unknown) keeps the all-employees view.
        $selectedUser = (int) $request->input('user', 0);
        if ($selectedUser > 0 && isset($data['employees'][$selectedUser])) {
            $filtered = [];
            foreach ($data['days'] as $date => $list) {
                $rows = array_values(array_filter($list, function ($r) use ($selectedUser) {
                    return (int) $r['user_id'] === $selectedUser;
                }));
                if (!empty($rows)) { $filtered[$date] = $rows; }
            }
            $data['days'] = $filtered;
        } else {
            $selectedUser = 0;
        }

        return view('employee.daily_earnings', [
            'data'          => $data,
            'days'          => $days,
            'selected_user' => $selectedUser,
            'pinned_date'   => $pinnedDate ? $pinnedDate->format('Y-m-d') : '',
            'today'         => now()->format('Y-m-d'),
            'range_from'    => \Carbon::parse($start)->format('M j'),
            'range_to'      => \Carbon::parse($end)->format('M j, Y'),
        ]);
    }

    // Itemized drill-down: every product a person listed (since rollout) with
    // its sold status + the commission it earned. The employee sees their own;
    // an admin can pass ?user_id= to view anyone's (same gate the leaderboard
    // listed-items drill uses).
    public function items(Request $request)
    {
        $me = auth()->user();
        $businessId = $request->session()->get('user.business_id');
        $isAdmin = app(\App\Utils\BusinessUtil::class)->is_admin($me);

        $targetId = (int) $request->input('user_id', $me->id);
        if ($targetId !== (int) $me->id && !$isAdmin) {
            $targetId = (int) $me->id; // non-admins only ever see themselves
        }

        $target = DB::table('users')->where('id', $targetId)->first();
        $targetName = $target
            ? (trim(($target->first_name ?? '') . ' ' . ($target->last_name ?? '')) ?: ($target->username ?? "User #{$targetId}"))
            : "User #{$targetId}";

        $from = self::DEFAULT_FROM;
        $start = $from . ' 00:00:00';
        $end = now()->toDateTimeString();
        $bizId = (int) $businessId;

        $perPage = 200;
        $page = max(1, (int) $request->input('page', 1));
        $offset = ($page - 1) * $perPage;

        // Whitelisted sort columns -> the SQL they map to (so the sort param
        // can't inject). Sold/Sale/Commission sort on the computed aggregates,
        // so it orders the WHOLE dataset, not just the visible page.
        $sortable = [
            'listed'     => 'p.created_at',
            'item'       => 'p.name',
            'sku'        => 'p.sku',
            'category'   => 'c.name',
            'list'       => 'list_price',
            'sold'       => 'units_val',
            'sold_date'  => 'sold_at',
            'sale'       => 'sale_val',
            'commission' => 'comm_val',
        ];
        $sort = $request->input('sort', 'listed');
        if (!isset($sortable[$sort])) { $sort = 'listed'; }
        $dir = strtolower($request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';

        // Ineligible-category test (same rule as the commission calc), built
        // from static lists only — safe to inline into raw SQL.
        $patConds = [];
        foreach (['%sealed%', '%new vinyl%', '%new cd%', '%new cassette%'] as $pat) {
            $patConds[] = "LOWER(c.name) LIKE '{$pat}'";
            $patConds[] = "LOWER(COALESCE(sc.name,'')) LIKE '{$pat}'";
        }
        $nameList = implode(',', array_map(function ($n) { return "'" . $n . "'"; }, $this->excludedCategoryNames));
        $ineligible = '(' . implode(' OR ', $patConds)
            . " OR LOWER(TRIM(c.name)) IN ({$nameList})"
            . " OR LOWER(TRIM(COALESCE(sc.name,''))) IN ({$nameList}))";

        $saleExpr  = 'COALESCE(s.sale_value, 0)';
        $unitsExpr = 'COALESCE(s.units, 0)';
        $commExpr  = "CASE WHEN NOT {$ineligible} AND {$saleExpr} > 0 THEN {$saleExpr} * " . self::RATE . " ELSE 0 END";

        $soldSub = "(SELECT tsl.product_id, SUM(tsl.quantity - COALESCE(tsl.quantity_returned, 0)) units, MAX(t.transaction_date) last_sold, SUM((tsl.quantity - COALESCE(tsl.quantity_returned, 0)) * (tsl.unit_price_inc_tax - COALESCE(tsl.item_tax, 0))) sale_value"
            . " FROM transaction_sell_lines tsl JOIN transactions t ON t.id = tsl.transaction_id"
            . " WHERE t.type = 'sell' AND t.status = 'final' AND t.import_source IS NULL AND t.business_id = {$bizId}"
            . " AND t.transaction_date >= '{$start}' AND t.transaction_date <= '{$end}' GROUP BY tsl.product_id) s";

        // Filter: 'sold' = items that have sold (units > 0); 'eligible' =
        // commission-eligible category only; 'sold_eligible' = both (the items
        // that actually earn commission); 'all' (default) = everything listed.
        $allowedFilters = ['all', 'sold', 'eligible', 'sold_eligible'];
        $filter = in_array($request->input('filter'), $allowedFilters, true)
            ? $request->input('filter') : 'all';

        $base = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('categories as sc', 'sc.id', '=', 'p.sub_category_id')
            ->where('p.business_id', $bizId)
            ->where('p.created_by', $targetId)
            ->where('p.created_at', '>=', $start);

        $totalListed = (clone $base)->count();

        // Everything past here joins the per-product sold aggregate.
        $withSold = (clone $base)->leftJoin(DB::raw($soldSub), 's.product_id', '=', 'p.id');
        if ($filter === 'sold' || $filter === 'sold_eligible') {
            $withSold->whereRaw("{$unitsExpr} > 0");
        }
        if ($filter === 'eligible' || $filter === 'sold_eligible') {
            $withSold->whereRaw("NOT {$ineligible}");
        }

        // Totals across the WHOLE filtered set (not just the visible page), so
        // the commission/sale totals are real, not page sums.
        $tot = (clone $withSold)->selectRaw(
            "COUNT(*) as cnt,"
            . " COALESCE(SUM({$saleExpr}), 0) as tot_sale,"
            . " COALESCE(SUM({$commExpr}), 0) as tot_comm,"
            . " SUM(CASE WHEN {$unitsExpr} > 0 THEN 1 ELSE 0 END) as sold_cnt"
        )->first();
        $filteredCount = (int) ($tot->cnt ?? 0);

        // Insight: top genres among items this person LISTED that SOLD (always
        // over the full sold set, independent of the page filter). Genre = the
        // sub-category (Pop, Jazz, Hip-Hop…) when present, else the category.
        $genreExpr = "COALESCE(NULLIF(TRIM(sc.name), ''), NULLIF(TRIM(c.name), ''), 'Uncategorized')";
        $soldBase = (clone $base)
            ->leftJoin(DB::raw($soldSub), 's.product_id', '=', 'p.id')
            ->whereRaw("{$unitsExpr} > 0");
        $soldTotals = (clone $soldBase)->selectRaw("COALESCE(SUM({$unitsExpr}), 0) as units")->first();
        $genreTotalUnits = (float) ($soldTotals->units ?? 0);
        $topGenres = (clone $soldBase)
            ->selectRaw("{$genreExpr} as genre, SUM({$unitsExpr}) as units, COUNT(*) as items, COALESCE(SUM({$commExpr}), 0) as comm")
            ->groupBy(DB::raw($genreExpr))
            ->orderByDesc('units')
            ->limit(3)
            ->get()
            ->map(function ($g) use ($genreTotalUnits) {
                return (object) [
                    'genre' => $g->genre,
                    'units' => (float) $g->units,
                    'items' => (int) $g->items,
                    'comm'  => round((float) $g->comm, 2),
                    'pct'   => $genreTotalUnits > 0 ? round($g->units / $genreTotalUnits * 100) : 0,
                ];
            });

        $products = (clone $withSold)
            ->selectRaw("p.name, p.sku, p.created_at, s.last_sold as sold_at, c.name as cat, sc.name as subcat,"
                . " (SELECT MAX(v.sell_price_inc_tax) FROM variations v WHERE v.product_id = p.id AND v.deleted_at IS NULL) as list_price,"
                . " {$unitsExpr} as units_val, {$saleExpr} as sale_val, {$commExpr} as comm_val,"
                . " CASE WHEN {$ineligible} THEN 0 ELSE 1 END as elig")
            ->orderByRaw($sortable[$sort] . ' ' . $dir)
            ->orderBy('p.id', 'desc')
            ->offset($offset)->limit($perPage)
            ->get();

        $rows = $products->map(function ($p) {
            return (object) [
                'name' => $p->name, 'sku' => $p->sku,
                'listed_at' => $p->created_at,
                'sold_at' => $p->sold_at,
                'category' => trim(($p->cat ?? '') . ($p->subcat ? ' › ' . $p->subcat : '')) ?: '—',
                'list_price' => $p->list_price !== null ? (float) $p->list_price : null,
                'eligible' => (int) $p->elig === 1,
                'units' => (float) $p->units_val,
                'sale_value' => (float) $p->sale_val,
                'commission' => round((float) $p->comm_val, 2),
            ];
        });

        return view('employee.my_listed_items', [
            'target_name' => $targetName,
            'is_self'     => $targetId === (int) $me->id,
            'is_admin'    => $isAdmin,
            'user_id'     => $targetId,
            'from'        => $from,
            'rate_pct'    => self::RATE * 100,
            'rows'        => $rows,
            'total'       => $totalListed,
            'page'        => $page,
            'per_page'    => $perPage,
            'has_more'    => ($offset + $products->count()) < $filteredCount,
            'sort'        => $sort,
            'dir'         => $dir,
            'filter'      => $filter,
            'shown_count' => $filteredCount,
            'tot_sale'    => (float) ($tot->tot_sale ?? 0),
            'tot_comm'    => round((float) ($tot->tot_comm ?? 0), 2),
            'sold_count'  => (int) ($tot->sold_cnt ?? 0),
            'top_genres'  => $topGenres,
        ]);
    }

    private function isEligibleCategory($cat, $subcat)
    {
        foreach ([$cat, $subcat] as $name) {
            $n = mb_strtolower(trim((string) $name));
            if ($n === '') { continue; }
            foreach (['sealed', 'new vinyl', 'new cd', 'new cassette'] as $pat) {
                if (mb_strpos($n, $pat) !== false) { return false; }
            }
            if (in_array($n, $this->excludedCategoryNames, true)) { return false; }
        }
        return true;
    }

    // Qualifying sold lines for ONE lister. Filters mirror
    // ListingCommissionController::ownedSoldLines exactly (so the math agrees),
    // but scoped to a single user_id and without the owner-name exclusion
    // (irrelevant when we've already pinned it to the logged-in person).
    private function ownedSoldLines($businessId, $userId, $start, $end)
    {
        return DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('products as p', 'tsl.product_id', '=', 'p.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('categories as sc', 'p.sub_category_id', '=', 'sc.id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('t.import_source')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->where('p.created_by', $userId)
            ->where('p.created_at', '>=', $start)
            ->where(function ($qq) {
                foreach ($this->excludedCategoryPatterns as $pat) {
                    $qq->where(DB::raw('LOWER(c.name)'), 'NOT LIKE', $pat)
                       ->where(DB::raw('LOWER(COALESCE(sc.name, \'\'))'), 'NOT LIKE', $pat);
                }
                $qq->whereNotIn(DB::raw('LOWER(TRIM(c.name))'), $this->excludedCategoryNames)
                   ->whereNotIn(DB::raw('LOWER(TRIM(COALESCE(sc.name, \'\')))'), $this->excludedCategoryNames);
            })
            ->select(
                'tsl.id as line_id',
                // PRE-TAX, net of returns — identical to the leaderboard's
                // barcodingCommissionByUser, so /my-earnings matches the board
                // and /admin/listing-commissions to the penny.
                DB::raw('((tsl.quantity - COALESCE(tsl.quantity_returned, 0)) * (tsl.unit_price_inc_tax - COALESCE(tsl.item_tax, 0))) as sale_amount')
            )
            ->get();
    }

    private function listedCount($businessId, $userId, $start)
    {
        return (int) DB::table('products as p')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('categories as sc', 'p.sub_category_id', '=', 'sc.id')
            ->where('p.business_id', $businessId)
            ->where('p.created_by', $userId)
            ->where('p.created_at', '>=', $start)
            ->where(function ($qq) {
                foreach ($this->excludedCategoryPatterns as $pat) {
                    $qq->where(DB::raw('LOWER(c.name)'), 'NOT LIKE', $pat)
                       ->where(DB::raw('LOWER(COALESCE(sc.name, \'\'))'), 'NOT LIKE', $pat);
                }
                $qq->whereNotIn(DB::raw('LOWER(TRIM(c.name))'), $this->excludedCategoryNames)
                   ->whereNotIn(DB::raw('LOWER(TRIM(COALESCE(sc.name, \'\')))'), $this->excludedCategoryNames);
            })
            ->count();
    }

    private function labeledCount($businessId, $userId, $start, $end)
    {
        $rows = DB::table('activity_log')
            ->where('description', 'labels_printed')
            ->where('business_id', $businessId)
            ->where('causer_id', $userId)
            ->whereBetween('created_at', [$start, $end])
            ->pluck('properties');
        $n = 0;
        foreach ($rows as $p) {
            $d = json_decode($p, true) ?: [];
            $n += (int) ($d['qty'] ?? 0);
        }
        return $n;
    }

    private function loadPayouts()
    {
        if (!Storage::disk('local')->exists(self::PAYOUTS_FILE)) {
            return [];
        }
        $data = json_decode(Storage::disk('local')->get(self::PAYOUTS_FILE), true);
        return is_array($data) ? $data : [];
    }
}
