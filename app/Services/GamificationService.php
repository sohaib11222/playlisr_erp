<?php

namespace App\Services;

use App\BusinessLocation;
use App\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-shift gamification: detects the active POS duty shift for a user, and
 * computes role-specific task progress against a target derived from the
 * peer-pace at this store + hour-of-day over the last 30 days.
 *
 * Shift start is read from the latest activity_log entry where
 * description='pos_duty' for the user, on the current day.
 */
class GamificationService
{
    public const SHIFT_MAX_HOURS = 10.0;
    public const PEER_LOOKBACK_DAYS = 30;
    public const SALES_GOAL_MULTIPLIER = 1.05;
    public const PRODUCTS_ADDED_BUSY_DAMPER = 0.85;
    public const BUSY_HOUR_THRESHOLD_PER_HOUR = 200.0;
    /**
     * Floor for products-added per-hour goal (Jon 2026-05-07: cashiers should
     * hit ~75-100 items in a 4hr shift). The auto-computed peer pace is used
     * if it exceeds this floor; otherwise this floor wins so goals stay
     * ambitious even when peer history is sparse.
     */
    public const PRODUCTS_ADDED_FLOOR_PER_HOUR = 20.0;

    /**
     * Fixed tier thresholds for products_added shift progress, in ascending
     * order. The shift-strip bar fills toward the top tier (elite) so all
     * three milestones are visible from the start of the shift; the dynamic
     * peer-pace target is still shown as the "current goal" number.
     */
    public const PRODUCTS_ADDED_TIERS = [
        ['count' => 80,  'key' => 'baseline', 'label' => 'Baseline'],
        ['count' => 100, 'key' => 'great',    'label' => 'Great shift'],
        ['count' => 120, 'key' => 'elite',    'label' => 'Elite shift'],
    ];

    /**
     * Relax the products_added goal by POS workload this shift. Every sale a
     * cashier rings is time off the barcode gun, so the flat peer/floor
     * target (~80 for a 4hr shift) is unreachable for someone stuck on the
     * register. Caps the goal by the number of final sales rung this shift,
     * in descending order so the busiest threshold wins:
     *
     *   20+ transactions → 40 items    15+ → 50    10+ → 60
     *
     * Below 10 transactions there's no cap and the normal peer/floor target
     * stands. The cap only ever LOWERS the goal (min), never raises it, so a
     * short or quiet shift keeps its already-smaller computed target.
     *
     * Jon 2026-06-22: makes quotas reachable instead of a static goal a
     * cashier can't hit while running the till.
     */
    public const PRODUCTS_ADDED_TX_GOAL_CAPS = [
        ['min_tx' => 20, 'goal' => 40],
        ['min_tx' => 15, 'goal' => 50],
        ['min_tx' => 10, 'goal' => 60],
    ];

    /**
     * Informational tier markers for the retail $ value of items listed
     * during the shift (sum of variations.sell_price_inc_tax for products
     * created by the user). NOT a goal — surfaced so employees can see
     * where their listed-value sits; chip stays empty until a tier is
     * crossed.
     */
    public const VALUE_CREATED_TIERS = [
        ['count' => 800,  'key' => 'baseline', 'label' => 'Baseline'],
        ['count' => 1200, 'key' => 'great',    'label' => 'Great'],
        ['count' => 1800, 'key' => 'elite',    'label' => 'Elite'],
    ];

    /**
     * Per-store opening hours, keyed by lowercase substring matched against
     * business_locations.name (same style as ImportNivessaCustomerAsks). Each
     * day maps to [open_hour, close_hour) — close is exclusive, so
     * [12, 19] covers hour buckets 12, 13, 14, 15, 16, 17, 18.
     *
     * Jon 2026-05-07: Pico hours from operator. Locations without an entry
     * fall back to auto-detect (any hour with sales in the last 30 days
     * counts as a "store was open" hour).
     */
    public const STORE_HOURS = [
        'pico' => [
            'Monday'    => [12, 19],
            'Tuesday'   => [12, 19],
            'Wednesday' => [12, 19],
            'Thursday'  => [12, 20],
            'Friday'    => [12, 20],
            'Saturday'  => [10, 20],
            'Sunday'    => [10, 20],
        ],
    ];

    /**
     * Shifts per open day, keyed by lowercase substring matched against
     * business_locations.name. Used as the divisor when sizing the per-
     * shift goal: a Pico Friday is 12-8 = 8 hours / 2 shifts = 4 hours
     * of expected shift length per cashier.
     *
     * Locations not listed default to DEFAULT_SHIFTS_PER_DAY.
     */
    public const STORE_SHIFTS_PER_DAY = [
        'pico'      => 2,
        'hollywood' => 3,
    ];

    public const DEFAULT_SHIFTS_PER_DAY = 2;

    /**
     * Returns the active shift for the user today, or null if none.
     *
     * @return array{started_at: Carbon, duty: string, location_id: ?int, hours: float}|null
     */
    public function currentShift(User $user, ?int $businessId = null): ?array
    {
        $todayStart = Carbon::today()->toDateTimeString();
        $q = DB::table(config('activitylog.table_name'))
            ->where('description', 'pos_duty')
            ->where('causer_id', $user->id)
            ->where('created_at', '>=', $todayStart)
            ->orderByDesc('created_at');
        if ($businessId !== null) {
            $q->where('business_id', $businessId);
        }
        $row = $q->first();

        if (!$row) {
            return null;
        }

        $props = json_decode($row->properties ?? '{}', true) ?: [];
        $duty = $props['duty'] ?? null;
        if (!in_array($duty, ['cashier', 'shipping', 'inventory'], true)) {
            return null;
        }

        // activity_log.created_at is written in the BUSINESS timezone —
        // Util::activityLog() calls date_default_timezone_set($business->time_zone)
        // before logging — whereas sales' transaction_date and Carbon::now() are in
        // the app timezone (config('app.timezone')). If we parse the shift start as
        // app-tz (the old behavior), a business tz ahead of the app tz (e.g. the
        // stock 'Asia/Kolkata' default, +12.5h vs America/Los_Angeles) pushes the
        // start hours into the "future", the [start, now] sales window inverts, and
        // shift sales stick at $0 even while the register is busy. Parse it in the
        // tz it was written in, then convert to app-tz so the window lines up with
        // transaction_date.
        $appTz = config('app.timezone');
        $bizTz = $this->businessTimezone($businessId ?? $user->business_id) ?: $appTz;
        $startedAt = Carbon::parse($row->created_at, $bizTz)->setTimezone($appTz);
        // Keep the window within today and never in the future, so it can't invert
        // or bleed into another day if a clock/tz skew remains.
        $startOfDay = Carbon::today();
        if ($startedAt->lt($startOfDay) || $startedAt->isFuture()) {
            $startedAt = $startOfDay;
        }
        $hours = max(0, min(self::SHIFT_MAX_HOURS, $startedAt->diffInMinutes(Carbon::now()) / 60.0));

        return [
            'started_at' => $startedAt,
            'duty' => $duty,
            'location_id' => isset($props['location_id']) ? (int) $props['location_id'] : null,
            'hours' => round($hours, 2),
        ];
    }

    /**
     * The business's configured timezone (column on the `business` table),
     * used to interpret activity_log timestamps that were written in it.
     * Null when unknown so the caller can fall back to the app timezone.
     */
    protected function businessTimezone(?int $businessId): ?string
    {
        if (!$businessId) {
            return null;
        }
        $tz = DB::table('business')->where('id', $businessId)->value('time_zone');
        return is_string($tz) && $tz !== '' ? $tz : null;
    }

    /**
     * Compute progress for every task that applies to this shift's role.
     *
     * @return array<int, array{
     *   key: string, label: string, unit: string,
     *   current: float, target: float, percent: float,
     *   peer_per_hour: ?float, my_per_hour: ?float, complete: bool
     * }>
     */
    public function shiftTasks(User $user, array $shift, int $businessId): array
    {
        $duty = $shift['duty'];
        $defs = $this->taskDefinitions($duty);
        $tasks = [];
        // Goal scales to the whole expected shift (start → today's store
        // close), not just hours elapsed so far. Avoids the "0/15 then
        // creeping up" UX where the target slid up over the day.
        $shift['expected_hours'] = $this->expectedShiftHours($shift);
        // Final sales this user has rung this shift — used to relax the
        // products_added goal by POS workload (see PRODUCTS_ADDED_TX_GOAL_CAPS).
        $shift['pos_tx_count'] = $this->shiftSalesAggregate($user->id, $businessId, $shift)['count'];

        foreach ($defs as $def) {
            $current = $this->measureCurrent($def['key'], $user->id, $businessId, $shift);
            $stats = $this->peerStats($def['key'], $businessId, $shift);
            $peerPerHour = $stats['avg'];
            $peerTopPerHour = $stats['top'];
            $target = $this->computeTarget($def['key'], $peerPerHour, $shift);
            $myPerHour = $shift['hours'] >= 0.25 ? $current / $shift['hours'] : null;
            $percent = $target > 0 ? min(100, ($current / $target) * 100) : 0;

            $task = [
                'key' => $def['key'],
                'label' => $def['label'],
                'unit' => $def['unit'],
                'scope' => $def['scope'] ?? 'shift',
                'current' => round($current, $def['decimals']),
                'target' => round($target, $def['decimals']),
                'percent' => round($percent, 1),
                'bar_percent' => round($percent, 1),
                'peer_per_hour' => $peerPerHour !== null ? round($peerPerHour, $def['decimals']) : null,
                'peer_top_per_hour' => $peerTopPerHour !== null ? round($peerTopPerHour, $def['decimals']) : null,
                'my_per_hour' => $myPerHour !== null ? round($myPerHour, $def['decimals']) : null,
                'complete' => $current >= $target && $target > 0,
                'pace_status' => $this->paceStatus($current, $target, $shift, $def['scope'] ?? 'shift'),
            ];

            if ($def['key'] === 'products_added') {
                $tiers = self::PRODUCTS_ADDED_TIERS;
                $top = end($tiers)['count'];
                $task['bar_percent'] = round(min(100, ($current / $top) * 100), 1);
                $task['tier_max'] = $top;
                $task['tier_ticks'] = $this->tierTicks($tiers, $top);
                $task['tier_chip'] = $this->productsTierChip($current);
                $task['personal_best'] = $this->personalBestProductsShift($user->id, $businessId);
            }

            if ($def['key'] === 'avg_ticket') {
                $agg = $this->shiftSalesAggregate($user->id, $businessId, $shift);
                $delta = ($current > 0 && $peerPerHour) ? (($current - $peerPerHour) / $peerPerHour) * 100 : null;
                $task['paired_with'] = $def['paired_with'] ?? null;
                $task['hide_bar'] = true;
                $task['comparison_chip'] = $this->atvComparisonChip($delta);
                $task['personal_best'] = $this->personalBestAvgTicket($user->id, $businessId);
                $task['atv_tickets_today'] = $agg['count'];
                $task['tooltip_override'] = $this->atvTooltipLines($current, $agg['count'], $peerPerHour, $task['personal_best']);
                // Comparison metric, not a goal.
                $task['complete'] = false;
                $task['pace_status'] = null;
            }

            if ($def['key'] === 'value_created') {
                $tiers = self::VALUE_CREATED_TIERS;
                $top = end($tiers)['count'];
                $bar = round(min(100, ($current / $top) * 100), 1);
                $task['bar_percent'] = $bar;
                // Reuse the percent slot for "% toward elite tier" so the
                // existing "X%" status text reads as progress toward the
                // top tier rather than the unused peer-pace target (0%).
                $task['percent'] = $bar;
                $task['tier_max'] = $top;
                $task['tier_ticks'] = $this->tierTicks($tiers, $top);
                $task['tier_chip'] = $this->valueTierChip($current);
                $task['personal_best'] = $this->personalBestValueShift($user->id, $businessId);
                $task['paired_with'] = $def['paired_with'] ?? null;
                // Informational only — don't trip the "Goal hit" confetti.
                $task['complete'] = false;
                $task['pace_status'] = null;
            }

            $tasks[] = $task;
        }

        return $tasks;
    }

    /**
     * Convert a tier list to the {count,key,label,position%} shape the
     * shift-strip Blade/JS consume to render tick marks.
     *
     * @param  array<int, array{count:int,key:string,label:string}>  $tiers
     * @return array<int, array{count:int,key:string,label:string,position:float}>
     */
    protected function tierTicks(array $tiers, int $top): array
    {
        return array_map(function ($t) use ($top) {
            return [
                'count'    => $t['count'],
                'key'      => $t['key'],
                'label'    => $t['label'],
                'position' => round(($t['count'] / $top) * 100, 2),
            ];
        }, $tiers);
    }

    /**
     * Status chip for the products_added row: which tier was just crossed
     * and what comes next. Replaces the pace label for tiered rows.
     *
     * @return array{label: string, status: string}
     */
    protected function productsTierChip(float $current): array
    {
        $reached = null;
        $next = null;
        foreach (self::PRODUCTS_ADDED_TIERS as $tier) {
            if ($current >= $tier['count']) {
                $reached = $tier;
            } elseif ($next === null) {
                $next = $tier;
            }
        }

        if ($reached === null) {
            return [
                'label' => 'Next: ' . $next['label'] . ' @ ' . $next['count'],
                'status' => 'pending',
            ];
        }
        if ($next === null) {
            return [
                'label' => $reached['label'] . ' 🏆',
                'status' => $reached['key'],
            ];
        }
        return [
            'label' => $reached['label'] . ' ✓ · next ' . $next['label'] . ' @ ' . $next['count'],
            'status' => $reached['key'],
        ];
    }

    /**
     * User's best single-day products_added count across all history.
     * Groups by DATE(created_at), so a 2-shift day inflates — accepted
     * simplification since employees almost always work one shift per day
     * and the alternative (joining each row against the next pos_duty
     * activity_log entry) is O(shifts) queries.
     *
     * @return array{count: int, date: string, is_today: bool}|null
     */
    public function personalBestProductsShift(int $userId, int $businessId): ?array
    {
        $row = DB::table('products')
            ->where('business_id', $businessId)
            ->where('created_by', $userId)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as cnt')
            ->groupBy('d')
            ->orderByDesc('cnt')
            ->limit(1)
            ->first();

        if (!$row || (int) $row->cnt <= 0) {
            return null;
        }
        $date = Carbon::parse($row->d);
        return [
            'count'    => (int) $row->cnt,
            'date'     => $date->format('M j'),
            'is_today' => $date->isToday(),
        ];
    }

    /**
     * Informational tier chip for value_created. Stays empty below the
     * first tier (so it doesn't feel like a goal employees are failing);
     * shows "Baseline ✓ / Great ✓ / Elite 🏆" once each tier is crossed.
     *
     * @return array{label: string, status: string}
     */
    protected function valueTierChip(float $current): array
    {
        $reached = null;
        foreach (self::VALUE_CREATED_TIERS as $tier) {
            if ($current >= $tier['count']) {
                $reached = $tier;
            }
        }
        if ($reached === null) {
            return ['label' => '', 'status' => 'pending'];
        }
        if ($reached['key'] === 'elite') {
            return ['label' => 'Elite 🏆', 'status' => 'elite'];
        }
        return ['label' => $reached['label'] . ' ✓', 'status' => $reached['key']];
    }

    /**
     * User's best single-day total listing value (sum of
     * variations.sell_price_inc_tax for products they created that day).
     * Groups by DATE(products.created_at) — same caveat as
     * personalBestProductsShift.
     *
     * @return array{count: int, date: string, is_today: bool}|null
     */
    public function personalBestValueShift(int $userId, int $businessId): ?array
    {
        $row = DB::table('variations')
            ->join('products', 'products.id', '=', 'variations.product_id')
            ->where('products.business_id', $businessId)
            ->where('products.created_by', $userId)
            ->whereNull('variations.deleted_at')
            ->selectRaw('DATE(products.created_at) as d, SUM(variations.sell_price_inc_tax) as total')
            ->groupBy('d')
            ->orderByDesc('total')
            ->limit(1)
            ->first();

        if (!$row || (float) $row->total <= 0) {
            return null;
        }
        $date = Carbon::parse($row->d);
        return [
            'count'    => (int) round((float) $row->total),
            'date'     => $date->format('M j'),
            'is_today' => $date->isToday(),
        ];
    }

    /**
     * Sum and count of the user's final sales during the active shift.
     * Used by both avg_ticket's "current" value and its tooltip's ticket
     * count.
     *
     * @return array{total: float, count: int}
     */
    protected function shiftSalesAggregate(int $userId, int $businessId, array $shift): array
    {
        $start = $shift['started_at']->toDateTimeString();
        $now = Carbon::now()->toDateTimeString();
        $q = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->whereNull('import_source')
            ->where('created_by', $userId)
            ->whereBetween('transaction_date', [$start, $now]);
        if (!empty($shift['location_id'])) {
            $q->where('location_id', $shift['location_id']);
        }
        $row = $q->selectRaw('COALESCE(SUM(final_total),0) as total, COUNT(*) as cnt')->first();
        return [
            'total' => $row ? (float) $row->total : 0.0,
            'count' => $row ? (int) $row->cnt : 0,
        ];
    }

    /**
     * User's best single-day average ticket value. Requires ≥3 tickets
     * that day so a one-off high-priced sale doesn't read as their best
     * "shift" average. DATE() grouping matches the other personal-best
     * queries.
     *
     * @return array{count: int, date: string, is_today: bool, tickets: int}|null
     */
    public function personalBestAvgTicket(int $userId, int $businessId): ?array
    {
        $row = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->whereNull('import_source')
            ->where('created_by', $userId)
            ->selectRaw('DATE(transaction_date) as d, SUM(final_total) / COUNT(*) as atv, COUNT(*) as cnt')
            ->groupBy('d')
            ->havingRaw('COUNT(*) >= 3')
            ->orderByDesc('atv')
            ->limit(1)
            ->first();

        if (!$row || (float) $row->atv <= 0) {
            return null;
        }
        $date = Carbon::parse($row->d);
        return [
            'count'    => (int) round((float) $row->atv),
            'date'     => $date->format('M j'),
            'is_today' => $date->isToday(),
            'tickets'  => (int) $row->cnt,
        ];
    }

    /**
     * Status chip comparing today's ATV vs the peer ATV at this store/
     * hour/DOW. Soft-zoning ±5% as "matches peer" so tiny noise doesn't
     * flicker red/green.
     *
     * @return array{label: string, status: string}
     */
    protected function atvComparisonChip(?float $deltaPct): array
    {
        if ($deltaPct === null) {
            return ['label' => '', 'status' => 'pending'];
        }
        if ($deltaPct >= 20) {
            return ['label' => '+' . (int) round($deltaPct) . '% vs peer 🔥', 'status' => 'ahead'];
        }
        if ($deltaPct >= 5) {
            return ['label' => '+' . (int) round($deltaPct) . '% vs peer', 'status' => 'ahead'];
        }
        if ($deltaPct <= -5) {
            return ['label' => (int) round($deltaPct) . '% vs peer', 'status' => 'behind'];
        }
        return ['label' => '≈ peer', 'status' => 'on'];
    }

    /**
     * Tooltip lines for the avg_ticket row: today, peer, personal best.
     *
     * @return array<int, string>
     */
    protected function atvTooltipLines(float $current, int $tickets, ?float $peer, ?array $pb): array
    {
        $lines = [];
        if ($tickets > 0) {
            $lines[] = 'Today: $' . number_format($current, 2) . ' across ' . $tickets . ' ticket' . ($tickets === 1 ? '' : 's');
        } else {
            $lines[] = 'No sales yet this shift';
        }
        if ($peer !== null) {
            $lines[] = 'Peer ATV (this store, this hour): $' . number_format($peer, 2);
        }
        if ($pb) {
            $lines[] = 'Your best shift ATV: $' . number_format($pb['count']) . ' (' . ($pb['is_today'] ? 'today' : $pb['date']) . ', ' . $pb['tickets'] . ' tickets)';
        }
        return $lines;
    }

    /**
     * @return array<int, array{key: string, label: string, unit: string, decimals: int}>
     */
    protected function taskDefinitions(string $duty): array
    {
        if ($duty === 'cashier') {
            return [
                ['key' => 'sales_total', 'label' => 'Your shift sales', 'unit' => '$', 'decimals' => 0, 'scope' => 'shift'],
                ['key' => 'avg_ticket', 'label' => 'Avg ticket', 'unit' => '$', 'decimals' => 0, 'scope' => 'shift', 'paired_with' => 'sales_total'],
                ['key' => 'products_added', 'label' => 'Products added & priced', 'unit' => 'items', 'decimals' => 0, 'scope' => 'shift'],
                ['key' => 'value_created', 'label' => 'Value listed', 'unit' => '$', 'decimals' => 0, 'scope' => 'shift', 'paired_with' => 'products_added'],
                ['key' => 'store_sales_today', 'label' => 'Store today (all cashiers)', 'unit' => '$', 'decimals' => 0, 'scope' => 'day_store'],
            ];
        }
        if ($duty === 'shipping') {
            return [
                ['key' => 'orders_shipped', 'label' => 'Orders shipped', 'unit' => 'orders', 'decimals' => 0, 'scope' => 'shift'],
                ['key' => 'store_sales_today', 'label' => 'Store today (all cashiers)', 'unit' => '$', 'decimals' => 0, 'scope' => 'day_store'],
            ];
        }
        if ($duty === 'inventory') {
            return [
                ['key' => 'products_added', 'label' => 'Products added & priced', 'unit' => 'items', 'decimals' => 0, 'scope' => 'shift'],
                ['key' => 'value_created', 'label' => 'Value listed', 'unit' => '$', 'decimals' => 0, 'scope' => 'shift', 'paired_with' => 'products_added'],
                ['key' => 'store_sales_today', 'label' => 'Store today (all cashiers)', 'unit' => '$', 'decimals' => 0, 'scope' => 'day_store'],
            ];
        }
        return [];
    }

    protected function measureCurrent(string $taskKey, int $userId, int $businessId, array $shift): float
    {
        $start = $shift['started_at']->toDateTimeString();
        $now = Carbon::now()->toDateTimeString();

        if ($taskKey === 'sales_total') {
            $q = DB::table('transactions')
                ->where('business_id', $businessId)
                ->where('type', 'sell')
                ->where('status', 'final')
                ->whereNull('import_source')
                ->where('created_by', $userId)
                ->whereBetween('transaction_date', [$start, $now]);
            if (!empty($shift['location_id'])) {
                $q->where('location_id', $shift['location_id']);
            }
            return (float) $q->sum('final_total');
        }

        if ($taskKey === 'products_added') {
            $q = DB::table('products')
                ->where('business_id', $businessId)
                ->where('created_by', $userId)
                ->whereBetween('created_at', [$start, $now]);
            return (float) $q->count();
        }

        if ($taskKey === 'avg_ticket') {
            $row = $this->shiftSalesAggregate($userId, $businessId, $shift);
            return $row['count'] > 0 ? (float) $row['total'] / $row['count'] : 0.0;
        }

        if ($taskKey === 'value_created') {
            // Retail value of products this user listed during the shift.
            // Joins products → variations; sums sell_price_inc_tax (the
            // sticker price the cashier set when listing). Multi-variation
            // products are rare for vinyl/CD/DVD inventory, so a flat sum
            // is the right semantic here.
            return (float) DB::table('variations')
                ->join('products', 'products.id', '=', 'variations.product_id')
                ->where('products.business_id', $businessId)
                ->where('products.created_by', $userId)
                ->whereBetween('products.created_at', [$start, $now])
                ->whereNull('variations.deleted_at')
                ->sum('variations.sell_price_inc_tax');
        }

        if ($taskKey === 'orders_shipped') {
            $q = DB::table('transactions')
                ->where('business_id', $businessId)
                ->where('type', 'sell')
                ->whereIn('shipping_status', ['delivered', 'shipped'])
                ->whereBetween('updated_at', [$start, $now]);
            if (!empty($shift['location_id'])) {
                $q->where('location_id', $shift['location_id']);
            }
            return (float) $q->count();
        }

        if ($taskKey === 'store_sales_today') {
            // Whole-store revenue today, all cashiers — not user-scoped.
            $todayStart = Carbon::today()->toDateTimeString();
            $q = DB::table('transactions')
                ->where('business_id', $businessId)
                ->where('type', 'sell')
                ->where('status', 'final')
                ->whereNull('import_source')
                ->whereNotNull('created_by')
                ->whereBetween('transaction_date', [$todayStart, $now]);
            if (!empty($shift['location_id'])) {
                $q->where('location_id', $shift['location_id']);
            }
            return (float) $q->sum('final_total');
        }

        return 0.0;
    }

    /**
     * Rolling 30-day peer-pace stats for this task: average and top per-hour
     * rate at this store, restricted to (a) the same hour-of-day as the
     * shift start and (b) the same day-type (weekday vs weekend) as today.
     *
     * For sales, denominator is store-open-hours (whole-store metric — what
     * the operator would describe as "we do $X/hr at this location"). For
     * count-based metrics (products added, orders shipped) we keep the
     * (user × day) active-hour proxy because those tasks scale with staff
     * count, not store-open hours.
     *
     * @return array{avg: ?float, top: ?float}
     */
    public function peerStats(string $taskKey, int $businessId, array $shift): array
    {
        $now = Carbon::now();
        $hour = (int) $shift['started_at']->format('G');
        $rangeStart = $now->copy()->subDays(self::PEER_LOOKBACK_DAYS)->startOfDay()->toDateTimeString();
        $rangeEnd = $now->copy()->subDay()->endOfDay()->toDateTimeString();
        $dowBucket = $this->dowBucketForToday();

        if ($taskKey === 'sales_total') {
            return $this->salesPeerStats($businessId, $shift['location_id'] ?? null, $hour, $dowBucket, $rangeStart, $rangeEnd);
        }

        if ($taskKey === 'avg_ticket') {
            // Average ticket value (sale final_total) across the matching
            // store/hour/DOW window. AVG() rather than SUM/COUNT to keep
            // each ticket equally weighted regardless of cashier mix.
            $q = DB::table('transactions')
                ->where('business_id', $businessId)
                ->where('type', 'sell')
                ->where('status', 'final')
                ->whereNull('import_source')
                ->whereNotNull('created_by')
                ->whereBetween('transaction_date', [$rangeStart, $rangeEnd])
                ->whereRaw('HOUR(transaction_date) = ?', [$hour])
                ->whereRaw('DAYOFWEEK(transaction_date) IN ('.$this->dowList($dowBucket).')');
            if (!empty($shift['location_id'])) {
                $q->where('location_id', $shift['location_id']);
            }
            $row = $q->selectRaw('AVG(final_total) as avg_atv')->first();
            return [
                'avg' => $row && $row->avg_atv !== null ? (float) $row->avg_atv : null,
                'top' => null,
            ];
        }

        if ($taskKey === 'store_sales_today') {
            // Daily store totals on matching DOW. Returns avg/top in
            // dollars-per-day (semantics differ from per-hour above; the
            // computeTarget branch knows not to multiply by hours again).
            return $this->storeDailyPeerStats($businessId, $shift['location_id'] ?? null, $dowBucket, $rangeStart, $rangeEnd);
        }

        if ($taskKey === 'products_added') {
            return $this->countBasedPeerStats(
                DB::table('products')
                    ->where('business_id', $businessId)
                    ->whereNotNull('created_by')
                    ->whereBetween('created_at', [$rangeStart, $rangeEnd])
                    ->whereRaw('HOUR(created_at) = ?', [$hour])
                    ->whereRaw('DAYOFWEEK(created_at) IN ('.$this->dowList($dowBucket).')'),
                'created_by',
                'created_at'
            );
        }

        if ($taskKey === 'orders_shipped') {
            $q = DB::table('transactions')
                ->where('business_id', $businessId)
                ->where('type', 'sell')
                ->whereIn('shipping_status', ['delivered', 'shipped'])
                ->whereBetween('updated_at', [$rangeStart, $rangeEnd])
                ->whereRaw('HOUR(updated_at) = ?', [$hour])
                ->whereRaw('DAYOFWEEK(updated_at) IN ('.$this->dowList($dowBucket).')');
            if (!empty($shift['location_id'])) {
                $q->where('location_id', $shift['location_id']);
            }
            // Orders shipped: per-day rate (not per-cashier) since shipping
            // is usually a single station; "top" is the busiest single day.
            $rows = $q->selectRaw('DATE(updated_at) as d, COUNT(*) as cnt')
                ->groupBy('d')
                ->get();
            if ($rows->isEmpty()) {
                return ['avg' => null, 'top' => null];
            }
            $total = (int) $rows->sum('cnt');
            $days = $rows->count();
            return [
                'avg' => $days > 0 ? $total / $days : null,
                'top' => (float) $rows->max('cnt'),
            ];
        }

        return ['avg' => null, 'top' => null];
    }

    /**
     * Sales peer stats using cash_registers for actual hours-worked. Each
     * (cashier × day) pair contributes its share of hour H based on register
     * overlap; SUM(rev)/SUM(hrs) is the peer avg, MAX(rev/hrs) the top.
     *
     * @return array{avg: ?float, top: ?float}
     */
    protected function salesPeerStats(int $businessId, ?int $locationId, int $hour, array $dowBucket, string $rangeStart, string $rangeEnd): array
    {
        // Per-day store revenue at hour H (sum across all cashiers on that day).
        $q = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->whereNull('import_source')
            ->whereNotNull('created_by')
            ->whereBetween('transaction_date', [$rangeStart, $rangeEnd])
            ->whereRaw('HOUR(transaction_date) = ?', [$hour])
            ->whereRaw('DAYOFWEEK(transaction_date) IN ('.$this->dowList($dowBucket).')');
        if (!empty($locationId)) {
            $q->where('location_id', $locationId);
        }
        $rows = $q->selectRaw('DATE(transaction_date) as d, SUM(final_total) as rev')
            ->groupBy('d')
            ->get();

        if ($rows->isEmpty()) {
            return ['avg' => null, 'top' => null];
        }

        // Denominator: how many store-open-hours at this hour-bucket exist
        // in the matching DOW window. Avoids the dilution from counting
        // "incidental cashiers" (Jon 2026-05-07: 2 cashiers each ringing 1
        // sale halved the per-cashier rate when reality is 1 store, 1 hour).
        $openHours = $this->storeOpenHoursAtBucket($locationId, $hour, $dowBucket, $rangeStart, $rangeEnd);
        if ($openHours <= 0) {
            return ['avg' => null, 'top' => null];
        }

        $totalRev = (float) $rows->sum('rev');
        return [
            'avg' => $totalRev / $openHours,
            'top' => (float) $rows->max('rev'),
        ];
    }

    /**
     * Whole-day store revenue stats: avg and best single-day total at this
     * location across the matching DOW bucket over the lookback window.
     *
     * @return array{avg: ?float, top: ?float}
     */
    protected function storeDailyPeerStats(int $businessId, ?int $locationId, array $dowBucket, string $rangeStart, string $rangeEnd): array
    {
        $q = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->whereNull('import_source')
            ->whereNotNull('created_by')
            ->whereBetween('transaction_date', [$rangeStart, $rangeEnd])
            ->whereRaw('DAYOFWEEK(transaction_date) IN ('.$this->dowList($dowBucket).')');
        if (!empty($locationId)) {
            $q->where('location_id', $locationId);
        }
        $rows = $q->selectRaw('DATE(transaction_date) as d, SUM(final_total) as rev')
            ->groupBy('d')
            ->get();

        if ($rows->isEmpty()) {
            return ['avg' => null, 'top' => null];
        }
        return [
            'avg' => (float) $rows->avg('rev'),
            'top' => (float) $rows->max('rev'),
        ];
    }

    /**
     * Number of store-open-hours at hour H matching the DOW bucket within
     * [rangeStart, rangeEnd]. Uses STORE_HOURS config when the location
     * matches; otherwise auto-detects from historical sales.
     */
    protected function storeOpenHoursAtBucket(?int $locationId, int $hour, array $dowBucket, string $rangeStart, string $rangeEnd): float
    {
        $hours = $this->getStoreHours($locationId);
        if ($hours === null) {
            return $this->autoDetectStoreOpenDays($locationId, $hour, $dowBucket, $rangeStart, $rangeEnd);
        }

        $cursor = Carbon::parse($rangeStart)->startOfDay();
        $end = Carbon::parse($rangeEnd)->startOfDay();
        $count = 0;
        while ($cursor->lessThanOrEqualTo($end)) {
            $dowMysql = ((int) $cursor->dayOfWeek) + 1;
            if (in_array($dowMysql, $dowBucket, true)) {
                $dayName = $cursor->format('l');
                if (isset($hours[$dayName])) {
                    [$openHour, $closeHour] = $hours[$dayName];
                    if ($hour >= $openHour && $hour < $closeHour) {
                        $count++;
                    }
                }
            }
            $cursor->addDay();
        }
        return (float) $count;
    }

    /**
     * Lookup per-store opening hours by location name substring.
     *
     * @return array<string, array{0:int,1:int}>|null
     */
    protected function getStoreHours(?int $locationId): ?array
    {
        if (!$locationId) {
            return null;
        }
        $name = strtolower((string) BusinessLocation::where('id', $locationId)->value('name'));
        foreach (self::STORE_HOURS as $needle => $hours) {
            if (str_contains($name, $needle)) {
                return $hours;
            }
        }
        return null;
    }

    /**
     * Fallback when STORE_HOURS isn't configured for this location: count
     * distinct dates in the DOW window where the store had at least one
     * sale at hour H (proxy for "store was open in this hour bucket").
     */
    protected function autoDetectStoreOpenDays(?int $locationId, int $hour, array $dowBucket, string $rangeStart, string $rangeEnd): float
    {
        $q = DB::table('transactions')
            ->where('type', 'sell')
            ->whereNull('import_source')
            ->whereBetween('transaction_date', [$rangeStart, $rangeEnd])
            ->whereRaw('HOUR(transaction_date) = ?', [$hour])
            ->whereRaw('DAYOFWEEK(transaction_date) IN ('.$this->dowList($dowBucket).')');
        if (!empty($locationId)) {
            $q->where('location_id', $locationId);
        }
        return (float) $q->distinct()->count(DB::raw('DATE(transaction_date)'));
    }

    /**
     * Generic peer stats for count-based metrics (e.g. products added).
     * Treats each (user × day) pair with at least one event as "1 hour of
     * activity" — coarse but works when cash_registers don't cover the
     * relevant role.
     *
     * @param  \Illuminate\Database\Query\Builder  $base  must already filter
     *     business, time window, hour-of-day, DOW bucket, NOT NULL user.
     * @return array{avg: ?float, top: ?float}
     */
    protected function countBasedPeerStats($base, string $userColumn, string $timeColumn): array
    {
        $rows = (clone $base)
            ->selectRaw("$userColumn as uid, DATE($timeColumn) as d, COUNT(*) as cnt")
            ->groupBy($userColumn, 'd')
            ->get();
        if ($rows->isEmpty()) {
            return ['avg' => null, 'top' => null];
        }
        $total = (int) $rows->sum('cnt');
        $pairs = $rows->count();
        return [
            'avg' => $pairs > 0 ? $total / $pairs : null,
            'top' => (float) $rows->max('cnt'),
        ];
    }

    /**
     * MySQL DAYOFWEEK values matching today's day-type. Weekend = Sat/Sun
     * (1, 7); weekday = Mon-Fri (2-6).
     *
     * @return array<int, int>
     */
    protected function dowBucketForToday(): array
    {
        return Carbon::now()->isWeekend() ? [1, 7] : [2, 3, 4, 5, 6];
    }

    protected function dowList(array $bucket): string
    {
        return implode(',', array_map('intval', $bucket));
    }

    /**
     * Goal = peer-rate × shift-hours × multiplier. Sales pushes ~5% above
     * peer pace; products-added eases ~15% during historically busy sales
     * windows so cashiers don't fight the rush, but never below
     * PRODUCTS_ADDED_FLOOR_PER_HOUR × hours so the bar stays meaningful.
     */
    protected function computeTarget(string $taskKey, ?float $peerPerHour, array $shift): float
    {
        $hours = max(1.0, $shift['expected_hours'] ?? $shift['hours']);

        if ($taskKey === 'store_sales_today') {
            // peerPerHour here is actually peer-per-day from
            // storeDailyPeerStats, so don't multiply by hours.
            if ($peerPerHour === null) {
                return 0.0;
            }
            return $peerPerHour * self::SALES_GOAL_MULTIPLIER;
        }

        if ($taskKey === 'sales_total') {
            if ($peerPerHour === null) {
                return 0.0;
            }
            return $peerPerHour * $hours * self::SALES_GOAL_MULTIPLIER;
        }

        if ($taskKey === 'products_added') {
            $peerComponent = 0.0;
            if ($peerPerHour !== null) {
                $multiplier = $peerPerHour >= self::BUSY_HOUR_THRESHOLD_PER_HOUR
                    ? self::PRODUCTS_ADDED_BUSY_DAMPER
                    : 1.0;
                $peerComponent = $peerPerHour * $hours * $multiplier;
            }
            $target = max($peerComponent, self::PRODUCTS_ADDED_FLOOR_PER_HOUR * $hours);

            // Relax the goal by how many sales this cashier rang this shift —
            // register time is barcode time they didn't have. Only lowers it.
            $txCap = $this->productsTxGoalCap((int) ($shift['pos_tx_count'] ?? 0));
            if ($txCap !== null) {
                $target = min($target, (float) $txCap);
            }
            return $target;
        }

        if ($peerPerHour === null) {
            return 0.0;
        }
        return $peerPerHour * $hours;
    }

    /**
     * Goal cap for products_added based on final sales rung this shift, or
     * null when below the lowest threshold (no cap). Tiers are checked in
     * descending order so the busiest matching threshold wins.
     *
     * @see self::PRODUCTS_ADDED_TX_GOAL_CAPS
     */
    protected function productsTxGoalCap(int $txCount): ?int
    {
        foreach (self::PRODUCTS_ADDED_TX_GOAL_CAPS as $tier) {
            if ($txCount >= $tier['min_tx']) {
                return (int) $tier['goal'];
            }
        }
        return null;
    }

    public function locationName(?int $locationId): ?string
    {
        if (!$locationId) {
            return null;
        }
        return BusinessLocation::where('id', $locationId)->value('name');
    }

    /**
     * Length of a single shift at this store today, in hours. Computed as
     * (today's store-open span) / (shifts per day). Used as the goal
     * denominator so targets are stable across the whole shift instead of
     * creeping up with elapsed time.
     *
     * Pico Friday 12-8pm / 2 shifts = 4h; Sat-Sun 10-8 / 2 = 5h.
     * Hollywood splits its day into 3 shifts.
     *
     * Falls back to 4h when the location has no configured hours or the
     * shift started outside open hours.
     */
    /**
     * Compares fraction-of-goal-done vs fraction-of-shift-elapsed and
     * returns one of: 'ahead', 'on', 'behind', or null when there isn't
     * enough info (no target, or shift just started so the comparison is
     * meaningless). Tolerance ±5% so small wiggle reads as "on pace".
     */
    protected function paceStatus(float $current, float $target, array $shift, string $scope = 'shift'): ?string
    {
        if ($target <= 0) {
            return null;
        }
        $progressFrac = $current / $target;

        if ($scope === 'day_store') {
            $elapsedFrac = $this->storeDayElapsedFraction($shift);
            if ($elapsedFrac === null) {
                return null;
            }
        } else {
            if ($shift['hours'] < 0.25) {
                return null;
            }
            $expected = max(1.0, $shift['expected_hours'] ?? $shift['hours']);
            $elapsedFrac = min(1.0, $shift['hours'] / $expected);
        }

        if ($progressFrac >= $elapsedFrac + 0.05) {
            return 'ahead';
        }
        if ($progressFrac <= $elapsedFrac - 0.05) {
            return 'behind';
        }
        return 'on';
    }

    /**
     * How far through today's open hours we are at this moment, 0–1. Uses
     * STORE_HOURS for the location; null when we don't know the schedule.
     */
    protected function storeDayElapsedFraction(array $shift): ?float
    {
        $hours = $this->getStoreHours($shift['location_id'] ?? null);
        if (!$hours) {
            return null;
        }
        $now = Carbon::now();
        $dayName = $now->format('l');
        if (!isset($hours[$dayName])) {
            return null;
        }
        [$openHour, $closeHour] = $hours[$dayName];
        $todayOpen = $now->copy()->setTime($openHour, 0, 0);
        $todayClose = $now->copy()->setTime($closeHour, 0, 0);
        $totalSeconds = max(1, $todayClose->timestamp - $todayOpen->timestamp);
        $elapsedSeconds = max(0, min($totalSeconds, $now->timestamp - $todayOpen->timestamp));
        return $elapsedSeconds / $totalSeconds;
    }

    public function expectedShiftHours(array $shift): float
    {
        $defaultHours = 4.0;
        $hours = $this->getStoreHours($shift['location_id'] ?? null);
        if (!$hours) {
            return $defaultHours;
        }
        $startedAt = $shift['started_at'];
        $dayName = $startedAt->format('l');
        if (!isset($hours[$dayName])) {
            return $defaultHours;
        }
        [$openHour, $closeHour] = $hours[$dayName];
        $openSpan = max(0, $closeHour - $openHour);
        if ($openSpan < 1) {
            return $defaultHours;
        }
        $shifts = $this->getShiftsPerDay($shift['location_id'] ?? null);
        $perShift = $openSpan / max(1, $shifts);
        return min((float) self::SHIFT_MAX_HOURS, max(1.0, $perShift));
    }

    protected function getShiftsPerDay(?int $locationId): int
    {
        if (!$locationId) {
            return self::DEFAULT_SHIFTS_PER_DAY;
        }
        $name = strtolower((string) BusinessLocation::where('id', $locationId)->value('name'));
        foreach (self::STORE_SHIFTS_PER_DAY as $needle => $count) {
            if (str_contains($name, $needle)) {
                return $count;
            }
        }
        return self::DEFAULT_SHIFTS_PER_DAY;
    }

    /**
     * Convenience: bundle current shift + tasks into the shape consumed by
     * the shift-strip partial. Used by the dashboard controller and by the
     * view composer that injects the strip into the global layout.
     *
     * @return array{
     *   active: bool, duty: ?string, duty_label: ?string,
     *   location_name: ?string, started_at: ?string, hours: float,
     *   tasks: array
     * }
     */
    public function buildPanel(User $user, int $businessId): array
    {
        $shift = $this->currentShift($user, $businessId);
        if (!$shift) {
            return [
                'active' => false,
                'duty' => null,
                'duty_label' => null,
                'location_name' => null,
                'started_at' => null,
                'hours' => 0.0,
                'expected_hours' => 0.0,
                'tasks' => [],
            ];
        }
        return [
            'active' => true,
            'duty' => $shift['duty'],
            'duty_label' => ucfirst($shift['duty']),
            'location_name' => $this->locationName($shift['location_id']),
            'started_at' => $shift['started_at']->format('g:i a'),
            'hours' => $shift['hours'],
            'expected_hours' => round($this->expectedShiftHours($shift), 1),
            'tasks' => $this->shiftTasks($user, $shift, $businessId),
        ];
    }
}
