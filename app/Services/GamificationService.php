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
            $peerPerHour = $this->peerPacePerHour($def['key'], $businessId, $shift);
            $peerTopPerHour = $this->peerTopPerHour($def['key'], $businessId, $shift);
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
     * Rolling 30-day peer pace at this store, restricted to the current
     * hour-of-day bucket. Returns units-per-hour or null if no data.
     */
    public function peerPacePerHour(string $taskKey, int $businessId, array $shift): ?float
    {
        $now = Carbon::now();
        $hour = (int) $shift['started_at']->format('G');
        $rangeStart = $now->copy()->subDays(self::PEER_LOOKBACK_DAYS)->startOfDay()->toDateTimeString();
        $rangeEnd = $now->copy()->subDay()->endOfDay()->toDateTimeString();

        if ($taskKey === 'sales_total') {
            $q = DB::table('transactions')
                ->where('business_id', $businessId)
                ->where('type', 'sell')
                ->where('status', 'final')
                ->whereNull('import_source')
                ->whereBetween('transaction_date', [$rangeStart, $rangeEnd])
                ->whereRaw('HOUR(transaction_date) = ?', [$hour]);
            if (!empty($shift['location_id'])) {
                $q->where('location_id', $shift['location_id']);
            }
            $row = $q->selectRaw('SUM(final_total) as total, COUNT(DISTINCT created_by, DATE(transaction_date)) as cashier_hours')
                ->first();
            $hours = (float) ($row->cashier_hours ?? 0);
            return $hours > 0 ? ((float) $row->total) / $hours : null;
        }

        if ($taskKey === 'products_added') {
            $q = DB::table('products')
                ->where('business_id', $businessId)
                ->whereBetween('created_at', [$rangeStart, $rangeEnd])
                ->whereRaw('HOUR(created_at) = ?', [$hour]);
            $row = $q->selectRaw('COUNT(*) as cnt, COUNT(DISTINCT created_by, DATE(created_at)) as user_hours')
                ->first();
            $hours = (float) ($row->user_hours ?? 0);
            return $hours > 0 ? ((float) $row->cnt) / $hours : null;
        }

        if ($taskKey === 'orders_shipped') {
            $q = DB::table('transactions')
                ->where('business_id', $businessId)
                ->where('type', 'sell')
                ->whereIn('shipping_status', ['delivered', 'shipped'])
                ->whereBetween('updated_at', [$rangeStart, $rangeEnd])
                ->whereRaw('HOUR(updated_at) = ?', [$hour]);
            if (!empty($shift['location_id'])) {
                $q->where('location_id', $shift['location_id']);
            }
            $row = $q->selectRaw('COUNT(*) as cnt, COUNT(DISTINCT DATE(updated_at)) as days')
                ->first();
            $days = (float) ($row->days ?? 0);
            return $days > 0 ? ((float) $row->cnt) / $days : null;
        }

        return null;
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

    /**
     * Best individual per-hour rate at this store + hour-of-day over the last
     * 30 days. Used so the dashboard can show "top performer pace" and the
     * shop owner can see whether the auto-goal exceeds even the best.
     */
    public function peerTopPerHour(string $taskKey, int $businessId, array $shift): ?float
    {
        $now = Carbon::now();
        $hour = (int) $shift['started_at']->format('G');
        $rangeStart = $now->copy()->subDays(self::PEER_LOOKBACK_DAYS)->startOfDay()->toDateTimeString();
        $rangeEnd = $now->copy()->subDay()->endOfDay()->toDateTimeString();

        if ($taskKey === 'sales_total') {
            $sub = DB::table('transactions')
                ->where('business_id', $businessId)
                ->where('type', 'sell')
                ->where('status', 'final')
                ->whereNull('import_source')
                ->whereBetween('transaction_date', [$rangeStart, $rangeEnd])
                ->whereRaw('HOUR(transaction_date) = ?', [$hour]);
            if (!empty($shift['location_id'])) {
                $sub->where('location_id', $shift['location_id']);
            }
            $rows = $sub->selectRaw('created_by, DATE(transaction_date) as d, SUM(final_total) as total')
                ->groupBy('created_by', 'd')
                ->get();
        } elseif ($taskKey === 'products_added') {
            $rows = DB::table('products')
                ->where('business_id', $businessId)
                ->whereBetween('created_at', [$rangeStart, $rangeEnd])
                ->whereRaw('HOUR(created_at) = ?', [$hour])
                ->selectRaw('created_by, DATE(created_at) as d, COUNT(*) as total')
                ->groupBy('created_by', 'd')
                ->get();
        } elseif ($taskKey === 'orders_shipped') {
            $sub = DB::table('transactions')
                ->where('business_id', $businessId)
                ->where('type', 'sell')
                ->whereIn('shipping_status', ['delivered', 'shipped'])
                ->whereBetween('updated_at', [$rangeStart, $rangeEnd])
                ->whereRaw('HOUR(updated_at) = ?', [$hour]);
            if (!empty($shift['location_id'])) {
                $sub->where('location_id', $shift['location_id']);
            }
            $rows = $sub->selectRaw('DATE(updated_at) as d, COUNT(*) as total')
                ->groupBy('d')
                ->get();
        } else {
            return null;
        }

        if ($rows->isEmpty()) {
            return null;
        }
        return (float) $rows->max('total');
    }

    public function locationName(?int $locationId): ?string
    {
        if (!$locationId) {
            return null;
        }
        return BusinessLocation::where('id', $locationId)->value('name');
    }
}
