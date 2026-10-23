<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer / employee holds (Jon, 2026-10-23).
 *
 * A held copy sits in the store's hold bin. ERP stock is NOT changed; the hold
 * only takes the copy off sale: the website feed subtracts held qty, and the
 * POS warns the cashier when a held copy is rung up. Holds never expire on
 * their own: past-due holds stay active until a person pulls the copy from
 * the bin and clears it on /holds.
 *
 * Everything here degrades to "no holds" if the product_holds migration has
 * not been run yet, so the website feed and the POS can't break on it.
 */
class ProductHolds
{
    // Longest a hold may run, counted from today. Change here only.
    const MAX_DAYS_CUSTOMER = 2; // 48 hours (Sarah 10/23)
    const MAX_DAYS_EMPLOYEE = 7;

    // Default hold length when placing a new hold.
    const DEFAULT_DAYS_CUSTOMER = 2;
    const DEFAULT_DAYS_EMPLOYEE = 3;

    private static $tableExists = null;
    // business_id => ['variation_id:location_id' => held qty]
    private static $held = [];
    // business_id => [product_id => earliest active hold_until]
    private static $until = [];

    public static function enabled(): bool
    {
        if (self::$tableExists === null) {
            try {
                self::$tableExists = Schema::hasTable('product_holds');
            } catch (\Throwable $e) {
                self::$tableExists = false;
            }
        }
        return self::$tableExists;
    }

    public static function maxDays(string $type): int
    {
        return $type === 'employee' ? self::MAX_DAYS_EMPLOYEE : self::MAX_DAYS_CUSTOMER;
    }

    public static function defaultUntil(string $type): Carbon
    {
        $days = $type === 'employee' ? self::DEFAULT_DAYS_EMPLOYEE : self::DEFAULT_DAYS_CUSTOMER;
        return Carbon::today()->addDays($days);
    }

    public static function latestUntil(string $type): Carbon
    {
        return Carbon::today()->addDays(self::maxDays($type));
    }

    /** Error message for a bad hold_until, or null when it's allowed. */
    public static function untilError(string $type, $date): ?string
    {
        try {
            $until = Carbon::parse($date)->startOfDay();
        } catch (\Throwable $e) {
            return 'Pick a valid hold until date.';
        }
        if ($until->lt(Carbon::today())) return 'Hold until can not be in the past.';
        if ($until->gt(self::latestUntil($type))) {
            return ucfirst($type) . ' holds can be at most ' . ($type === 'customer' ? (self::maxDays($type) * 24) . ' hours' : self::maxDays($type) . ' days') . ' (until '
                . self::latestUntil($type)->format('m/d/y') . ').';
        }
        return null;
    }

    /** Active held quantity for one variation at one store (cached per request). */
    public static function heldQty(int $businessId, int $variationId, int $locationId): float
    {
        if (!self::enabled()) return 0;
        if (!isset(self::$held[$businessId])) {
            self::$held[$businessId] = [];
            try {
                // Active holds are a handful of rows, so one grouped read per
                // request covers every product on a paginated feed page.
                $rows = DB::table('product_holds')->where('business_id', $businessId)->where('status', 'active')
                    ->groupBy('variation_id', 'location_id')
                    ->select('variation_id', 'location_id', DB::raw('SUM(quantity) as qty'))->get();
                foreach ($rows as $r) {
                    self::$held[$businessId][$r->variation_id . ':' . $r->location_id] = (float) $r->qty;
                }
            } catch (\Throwable $e) {
                // leave empty: no holds
            }
        }
        return self::$held[$businessId][$variationId . ':' . $locationId] ?? 0;
    }

    /** Website quantity after taking held copies off sale (never below 0). */
    public static function websiteQty(int $businessId, int $variationId, int $locationId, $qty)
    {
        $held = self::heldQty($businessId, $variationId, $locationId);
        if ($held <= 0) return $qty;
        return max(0, (float) $qty - $held);
    }

    /** First active hold on this copy at this store, for the POS warning. */
    public static function activeHold(int $businessId, int $variationId, int $locationId)
    {
        if (!self::enabled()) return null;
        try {
            return DB::table('product_holds')->where('business_id', $businessId)->where('variation_id', $variationId)
                ->where('location_id', $locationId)->where('status', 'active')->orderBy('hold_until')->first();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Earliest hold-until date of the product's active holds (Y-m-d), or null. */
    public static function holdUntil(int $businessId, int $productId): ?string
    {
        if (!self::enabled()) return null;
        if (!isset(self::$until[$businessId])) {
            self::$until[$businessId] = [];
            try {
                foreach (DB::table('product_holds')->where('business_id', $businessId)->where('status', 'active')
                    ->groupBy('product_id')->select('product_id', DB::raw('MIN(hold_until) as until'))->get() as $r) {
                    self::$until[$businessId][(int) $r->product_id] = (string) $r->until;
                }
            } catch (\Throwable $e) {
                // leave empty: no holds
            }
        }
        return self::$until[$businessId][$productId] ?? null;
    }

    /** Active holds past their hold until date (optionally at one store). */
    public static function overdueCount(int $businessId, $locationId = null): int
    {
        if (!self::enabled()) return 0;
        try {
            $q = DB::table('product_holds')->where('business_id', $businessId)->where('status', 'active')
                ->where('hold_until', '<', Carbon::today()->toDateString());
            if ($locationId) $q->where('location_id', $locationId);
            return (int) $q->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Re-push products so the website picks up the new held qty right away. */
    public static function pushToWebsite(array $productIds): void
    {
        self::$held = [];
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        if (empty($productIds)) return;
        try {
            (new NivessaStockNotifier())->pushProductChanged($productIds);
        } catch (\Throwable $e) {
            // the nightly sync still reconciles it
        }
    }
}
