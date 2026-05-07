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

        $startedAt = Carbon::parse($row->created_at);
        $hours = max(0, min(self::SHIFT_MAX_HOURS, $startedAt->diffInMinutes(Carbon::now()) / 60.0));

        return [
            'started_at' => $startedAt,
            'duty' => $duty,
            'location_id' => isset($props['location_id']) ? (int) $props['location_id'] : null,
            'hours' => round($hours, 2),
        ];
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

        foreach ($defs as $def) {
            $current = $this->measureCurrent($def['key'], $user->id, $businessId, $shift);
            $stats = $this->peerStats($def['key'], $businessId, $shift);
            $peerPerHour = $stats['avg'];
            $peerTopPerHour = $stats['top'];
            $target = $this->computeTarget($def['key'], $peerPerHour, $shift);
            $myPerHour = $shift['hours'] >= 0.25 ? $current / $shift['hours'] : null;
            $percent = $target > 0 ? min(100, ($current / $target) * 100) : 0;

            $tasks[] = [
                'key' => $def['key'],
                'label' => $def['label'],
                'unit' => $def['unit'],
                'current' => round($current, $def['decimals']),
                'target' => round($target, $def['decimals']),
                'percent' => round($percent, 1),
                'peer_per_hour' => $peerPerHour !== null ? round($peerPerHour, $def['decimals']) : null,
                'peer_top_per_hour' => $peerTopPerHour !== null ? round($peerTopPerHour, $def['decimals']) : null,
                'my_per_hour' => $myPerHour !== null ? round($myPerHour, $def['decimals']) : null,
                'complete' => $current >= $target && $target > 0,
            ];
        }

        return $tasks;
    }

    /**
     * @return array<int, array{key: string, label: string, unit: string, decimals: int}>
     */
    protected function taskDefinitions(string $duty): array
    {
        if ($duty === 'cashier') {
            return [
                ['key' => 'sales_total', 'label' => 'Shift sales', 'unit' => '$', 'decimals' => 0],
                ['key' => 'products_added', 'label' => 'Products added & priced', 'unit' => 'items', 'decimals' => 0],
            ];
        }
        if ($duty === 'shipping') {
            return [
                ['key' => 'orders_shipped', 'label' => 'Orders shipped', 'unit' => 'orders', 'decimals' => 0],
            ];
        }
        if ($duty === 'inventory') {
            return [
                ['key' => 'products_added', 'label' => 'Products added & priced', 'unit' => 'items', 'decimals' => 0],
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

        return 0.0;
    }

    /**
     * Rolling 30-day peer-pace stats for this task: average and top per-hour
     * rate at this store, restricted to (a) the same hour-of-day as the
     * shift start and (b) the same day-type (weekday vs weekend) as today.
     *
     * For sales the denominator is actual hours-worked from cash_registers
     * (Jon 2026-05-07: COUNT(DISTINCT cashier, day) overcounted because
     * registers cover only fractions of an hour and NULL-created_by rows
     * inflated SUMs — Pico was reading $363/hr when reality was ~$95-175).
     *
     * For products_added and orders_shipped we keep the simpler
     * (rows / distinct active-user-days) model because inventory/shipping
     * staff may not open a cash_register, so cash_registers under-counts
     * their actual hours.
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
        $revQ = DB::table('transactions')
            ->where('business_id', $businessId)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->whereNull('import_source')
            ->whereNotNull('created_by')
            ->whereBetween('transaction_date', [$rangeStart, $rangeEnd])
            ->whereRaw('HOUR(transaction_date) = ?', [$hour])
            ->whereRaw('DAYOFWEEK(transaction_date) IN ('.$this->dowList($dowBucket).')');
        if (!empty($locationId)) {
            $revQ->where('location_id', $locationId);
        }
        $revRows = $revQ->selectRaw('created_by as uid, DATE(transaction_date) as d, SUM(final_total) as rev')
            ->groupBy('created_by', 'd')
            ->get();

        if ($revRows->isEmpty()) {
            return ['avg' => null, 'top' => null];
        }

        $hoursPerKey = $this->cashRegisterHoursAtBucket($businessId, $locationId, $hour, $dowBucket, $rangeStart, $rangeEnd);

        $totalRev = 0.0;
        $totalHrs = 0.0;
        $topRate = 0.0;
        foreach ($revRows as $row) {
            $secs = $hoursPerKey[$row->uid . '|' . $row->d] ?? 0;
            if ($secs <= 0) {
                continue;
            }
            $hrs = $secs / 3600.0;
            $rev = (float) $row->rev;
            $totalRev += $rev;
            $totalHrs += $hrs;
            if ($hrs >= 0.25) {
                $rate = $rev / $hrs;
                if ($rate > $topRate) {
                    $topRate = $rate;
                }
            }
        }

        return [
            'avg' => $totalHrs > 0 ? $totalRev / $totalHrs : null,
            'top' => $topRate > 0 ? $topRate : null,
        ];
    }

    /**
     * Returns map of "user_id|YYYY-MM-DD" → seconds worked in hour H of
     * that day, summed across all cash_register sessions belonging to that
     * user that overlap [day H:00, day H+1:00). Restricted to the DOW
     * bucket and (optionally) location.
     *
     * @return array<string, int>
     */
    protected function cashRegisterHoursAtBucket(int $businessId, ?int $locationId, int $hour, array $dowBucket, string $rangeStart, string $rangeEnd): array
    {
        $q = DB::table('cash_registers')
            ->where('business_id', $businessId)
            ->whereNotNull('user_id')
            ->where('created_at', '<=', $rangeEnd)
            ->where(function ($qq) use ($rangeStart) {
                $qq->where('closed_at', '>=', $rangeStart)->orWhereNull('closed_at');
            });
        if (!empty($locationId)) {
            $q->where('location_id', $locationId);
        }
        $sessions = $q->select(['user_id', 'created_at', 'closed_at'])->get();
        if ($sessions->isEmpty()) {
            return [];
        }

        $rangeStartTs = strtotime($rangeStart);
        $rangeEndTs = strtotime($rangeEnd);
        $now = time();
        $hoursPerKey = [];

        foreach ($sessions as $sess) {
            $sStart = max(strtotime($sess->created_at), $rangeStartTs);
            $sEnd = min($sess->closed_at ? strtotime($sess->closed_at) : $now, $rangeEndTs);
            if ($sEnd <= $sStart) {
                continue;
            }

            $cursor = Carbon::createFromTimestamp($sStart)->startOfDay();
            $endCarbon = Carbon::createFromTimestamp($sEnd);

            while ($cursor->lessThanOrEqualTo($endCarbon)) {
                $dowMysql = ((int) $cursor->dayOfWeek) + 1; // Carbon: 0=Sun → MySQL: 1=Sun
                if (in_array($dowMysql, $dowBucket, true)) {
                    $hourStartTs = $cursor->copy()->setTime($hour, 0, 0)->timestamp;
                    $hourEndTs = $hourStartTs + 3600;
                    $oStart = max($sStart, $hourStartTs);
                    $oEnd = min($sEnd, $hourEndTs);
                    if ($oEnd > $oStart) {
                        $key = $sess->user_id . '|' . $cursor->format('Y-m-d');
                        $hoursPerKey[$key] = ($hoursPerKey[$key] ?? 0) + ($oEnd - $oStart);
                    }
                }
                $cursor->addDay();
            }
        }

        return $hoursPerKey;
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
        if ($shift['hours'] < 0.25) {
            return 0.0;
        }

        $hours = max(0.5, $shift['hours']);

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
            return max($peerComponent, self::PRODUCTS_ADDED_FLOOR_PER_HOUR * $hours);
        }

        if ($peerPerHour === null) {
            return 0.0;
        }
        return $peerPerHour * $hours;
    }

    public function locationName(?int $locationId): ?string
    {
        if (!$locationId) {
            return null;
        }
        return BusinessLocation::where('id', $locationId)->value('name');
    }
}
