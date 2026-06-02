<?php

namespace App\Http\Controllers;

use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
use Carbon\Carbon;
use DB;

/**
 * Store Lead daily performance dashboard.
 *
 * A single always-on trading-day screen: today's revenue vs daily target,
 * LFL vs the same day last year (matched to the current time of day so the
 * comparison is apples-to-apples), transaction count, and average
 * transaction value. Green = ahead, red = behind — no interpretation needed.
 *
 * Whatnot livestream sales are excluded everywhere here: they belong to the
 * livestream, not the store's trading day (mirrors the /home team card).
 */
class StorePerformanceController extends Controller
{
    protected $businessUtil;

    // Non-admin role names (sans the #business_id suffix) allowed to view the
    // store dashboard. The Week-2 spec puts this in front of the Store Lead;
    // admins (owner + Sarah) always have access. Extend this list if Store
    // Leads use a differently-named role.
    private $allowed_roles = ['Store Lead'];

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    private function authorizeView()
    {
        $user = auth()->user();
        if ($this->businessUtil->is_admin($user)) {
            return;
        }
        $role = $this->businessUtil->getUserRoleName($user->id); // e.g. "Store Lead#1" -> "Store Lead"
        if (in_array($role, $this->allowed_roles, true)) {
            return;
        }
        abort(403, 'The store performance dashboard is for Store Leads and admins.');
    }

    /** Locations the user is allowed to see, keyed by id => name. */
    private function locations($business_id)
    {
        return DB::table('business_locations')
            ->where('business_id', $business_id)
            ->where('is_active', 1)
            ->orderBy('id')
            ->pluck('name', 'id');
    }

    public function index(Request $request)
    {
        $this->authorizeView();
        $business_id = $request->session()->get('user.business_id');
        $locations = $this->locations($business_id);

        $location_id = (int) $request->input('location_id', 0);
        if (!$locations->has($location_id)) {
            $location_id = (int) $locations->keys()->first();
        }

        $data = $this->compute($business_id, $location_id);

        // Last week's leaderboard, embedded for the leadership view. Admin-only
        // (the $/hour cross-staff comparison is gated to admins per Sarah
        // 2026-04-28) — Store Leads still get the KPI tiles above, just not the
        // ranking. Previous full calendar week (Mon–Sun): the settled,
        // recognition-ready number, not a mid-week partial. Static through the
        // 60s tile refresh. Wrapped so a leaderboard hiccup can never take the
        // dashboard down.
        $show_leaderboard = false;
        $leaderboard_rows = collect();
        $leaderboard_label = '';
        if ($this->businessUtil->is_admin(auth()->user())) {
            try {
                $lb_start = Carbon::now()->subWeek()->startOfWeek();
                $lb_end   = Carbon::now()->subWeek()->endOfWeek();
                $report = app()->make(\App\Http\Controllers\ReportController::class);
                $leaderboard_rows = $report->buildLeaderboardRows(
                    $business_id,
                    $lb_start->toDateTimeString(),
                    $lb_end->toDateTimeString(),
                    null,
                    $location_id,
                    ['exclude_owners' => true]
                );
                $leaderboard_label = $lb_start->format('M j') . ' – ' . $lb_end->format('M j');
                $show_leaderboard = true;
            } catch (\Throwable $e) {
                \Log::warning('store-performance leaderboard build failed: ' . $e->getMessage());
                $show_leaderboard = false;
            }
        }

        return view('home.store_performance')->with([
            'locations'    => $locations,
            'location_id'  => $location_id,
            'location_name' => $locations->get($location_id),
            'data'         => $data,
            'show_leaderboard'  => $show_leaderboard,
            'leaderboard_rows'  => $leaderboard_rows,
            'leaderboard_label' => $leaderboard_label,
        ]);
    }

    /** JSON endpoint polled by the dashboard for live updates. */
    public function data(Request $request)
    {
        $this->authorizeView();
        $business_id = $request->session()->get('user.business_id');
        $locations = $this->locations($business_id);

        $location_id = (int) $request->input('location_id', 0);
        if (!$locations->has($location_id)) {
            $location_id = (int) $locations->keys()->first();
        }

        return response()->json($this->compute($business_id, $location_id))
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    /**
     * Compute the four KPIs for one location, "as of now" today.
     */
    private function compute($business_id, $location_id)
    {
        $now = Carbon::now();
        $today_start = $now->copy()->startOfDay()->toDateTimeString();
        $now_str     = $now->toDateTimeString();

        // ---- Today's revenue + transaction count (store, non-whatnot) ----
        $today = $this->revAndCount($business_id, $location_id, $today_start, $now_str);
        $revenue_today = (float) $today->revenue;
        $tx_count      = (int) $today->tx_count;
        $avg_tx        = $tx_count > 0 ? $revenue_today / $tx_count : 0.0;

        // ---- Daily target: avg same-weekday revenue over the last 12 weeks ----
        $target = $this->dailyTarget($business_id, $location_id, $now);
        $target_full   = $target['goal'];
        $pace_fraction = $target['pace_fraction'];
        $target_so_far = (int) round($target_full * $pace_fraction);
        $target_pct = $target_so_far > 0
            ? ($revenue_today / $target_so_far) * 100
            : ($revenue_today > 0 ? 100 : 0);

        // ---- LFL: same weekday last year, clipped to the current time of day ----
        // 52 weeks back lands on the same day-of-week (proper retail LFL).
        $ly_date = $now->copy()->subWeeks(52);
        $ly_start = $ly_date->copy()->startOfDay()->toDateTimeString();
        // Same wall-clock time of day last year, so we compare like-for-like pace.
        $ly_as_of = $ly_date->copy()
            ->setTime($now->hour, $now->minute, $now->second)
            ->toDateTimeString();
        $ly_full_end = $ly_date->copy()->endOfDay()->toDateTimeString();

        $ly_so_far = (float) $this->revAndCount($business_id, $location_id, $ly_start, $ly_as_of)->revenue;
        $ly_full   = (float) $this->revAndCount($business_id, $location_id, $ly_start, $ly_full_end)->revenue;
        $lfl_pct = $ly_so_far > 0 ? (($revenue_today - $ly_so_far) / $ly_so_far) * 100 : null;

        return [
            'as_of' => $now->format('g:i A'),
            'as_of_iso' => $now->toIso8601String(),

            'revenue_today'  => round($revenue_today, 2),
            'target_full'    => $target_full,
            'target_so_far'  => $target_so_far,
            'target_pct'     => round($target_pct, 1),
            'target_state'   => $revenue_today >= $target_so_far ? 'ahead' : 'behind',

            'lfl_today'      => round($revenue_today, 2),
            'lfl_last_year'  => round($ly_so_far, 2),
            'lfl_last_year_full' => round($ly_full, 2),
            'lfl_date'       => $ly_date->format('D, M j, Y'),
            'lfl_pct'        => $lfl_pct === null ? null : round($lfl_pct, 1),
            'lfl_state'      => $lfl_pct === null ? 'na' : ($revenue_today >= $ly_so_far ? 'ahead' : 'behind'),

            'tx_count'       => $tx_count,
            'avg_tx'         => round($avg_tx, 2),
        ];
    }

    /** Revenue + final tx count for a window, store-scoped, whatnot excluded. */
    private function revAndCount($business_id, $location_id, $start, $end)
    {
        return DB::table('transactions')
            ->where('business_id', $business_id)
            ->where('location_id', $location_id)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->whereNull('import_source')
            ->where(function ($q) {
                $q->where('is_whatnot', 0)->orWhereNull('is_whatnot');
            })
            ->whereBetween('transaction_date', [$start, $end])
            ->selectRaw('COALESCE(SUM(final_total), 0) as revenue, COUNT(*) as tx_count')
            ->first();
    }

    /**
     * Daily revenue target + how far through that target we should be by now,
     * derived from same-weekday history over the last 12 weeks. Mirrors the
     * existing /home team-goal logic so the two screens agree.
     */
    private function dailyTarget($business_id, $location_id, Carbon $now)
    {
        $lookback_start = $now->copy()->subWeeks(12)->startOfDay()->toDateTimeString();
        $lookback_end   = $now->copy()->subDay()->endOfDay()->toDateTimeString();
        $dow_iso = (int) $now->dayOfWeekIso;            // 1=Mon..7=Sun
        $dow_mysql = $dow_iso === 7 ? 1 : $dow_iso + 1; // MySQL DAYOFWEEK: Sun=1..Sat=7

        $base = DB::table('transactions')
            ->where('business_id', $business_id)
            ->where('location_id', $location_id)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->whereNull('import_source')
            ->where(function ($q) {
                $q->where('is_whatnot', 0)->orWhereNull('is_whatnot');
            })
            ->whereBetween('transaction_date', [$lookback_start, $lookback_end])
            ->whereRaw('DAYOFWEEK(transaction_date) = ?', [$dow_mysql]);

        $daily_totals = (clone $base)
            ->selectRaw('DATE(transaction_date) as d, SUM(final_total) as rev')
            ->groupBy('d')
            ->pluck('rev')
            ->map(fn ($v) => (float) $v)
            ->filter(fn ($v) => $v > 0) // ignore closed days
            ->values();

        if ($daily_totals->count() > 0) {
            $goal = max(100, (int) (round($daily_totals->avg() / 100) * 100));
        } else {
            $goal = 5000; // fallback for stores with no history on this weekday
        }

        // Pace: where same-weekday history says we "should" be by this hour.
        $hourly = (clone $base)
            ->selectRaw('HOUR(transaction_date) as h, SUM(final_total) as rev')
            ->groupBy('h')
            ->pluck('rev', 'h')
            ->toArray();

        $hourly_total = array_sum($hourly);
        if ($hourly_total > 0) {
            $cum = 0.0;
            for ($h = 0; $h < $now->hour; $h++) {
                $cum += (float) ($hourly[$h] ?? 0);
            }
            $cum += (float) ($hourly[$now->hour] ?? 0) * ($now->minute / 60.0);
            $pace_fraction = min(1.0, $cum / $hourly_total);
        } else {
            $pace_fraction = ($now->hour + $now->minute / 60.0) / 24.0;
        }

        return ['goal' => $goal, 'pace_fraction' => $pace_fraction];
    }
}
