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
        $user = auth()->user();
        $userId = (int) $user->id;
        $businessId = $request->session()->get('user.business_id');
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

        // Productivity context (NOT pay): items listed + items put out (labeled).
        $listedCount = $this->listedCount($businessId, $userId, $start);
        $labeledCount = $this->labeledCount($businessId, $userId, $start, $end);

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

        $perPage = 200;
        $page = max(1, (int) $request->input('page', 1));
        $offset = ($page - 1) * $perPage;

        $base = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('categories as sc', 'sc.id', '=', 'p.sub_category_id')
            ->where('p.business_id', $businessId)
            ->where('p.created_by', $targetId)
            ->where('p.created_at', '>=', $start);

        $totalListed = (clone $base)->count();

        $products = (clone $base)
            ->orderByDesc('p.created_at')
            ->offset($offset)->limit($perPage)
            ->get(['p.id', 'p.name', 'p.sku', 'p.created_at', 'c.name as cat', 'sc.name as subcat']);

        // Sold rollup for just this page of products.
        $sold = collect();
        $ids = $products->pluck('id')->all();
        if (!empty($ids)) {
            $sold = DB::table('transaction_sell_lines as tsl')
                ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
                ->where('t.type', 'sell')->where('t.status', 'final')
                ->whereNull('t.import_source')
                ->whereBetween('t.transaction_date', [$start, $end])
                ->whereIn('tsl.product_id', $ids)
                ->groupBy('tsl.product_id')
                ->selectRaw('tsl.product_id, SUM(tsl.quantity) as units, SUM(tsl.quantity * tsl.unit_price_inc_tax) as sale_value')
                ->get()->keyBy('product_id');
        }

        $rows = $products->map(function ($p) use ($sold) {
            $eligible = $this->isEligibleCategory($p->cat, $p->subcat);
            $s = $sold[$p->id] ?? null;
            $units = $s ? (float) $s->units : 0;
            $saleValue = $s ? (float) $s->sale_value : 0.0;
            $commission = ($eligible && $saleValue > 0) ? round($saleValue * self::RATE, 2) : 0.0;
            return (object) [
                'name' => $p->name, 'sku' => $p->sku,
                'listed_at' => $p->created_at,
                'category' => trim(($p->cat ?? '') . ($p->subcat ? ' › ' . $p->subcat : '')) ?: '—',
                'eligible' => $eligible,
                'units' => $units, 'sale_value' => $saleValue, 'commission' => $commission,
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
            'has_more'    => ($offset + $products->count()) < $totalListed,
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
                DB::raw('(tsl.quantity * tsl.unit_price_inc_tax) as sale_amount')
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
