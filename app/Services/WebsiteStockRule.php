<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Which store stock the website is allowed to sell (Sarah + Jon 10/20, to stop
 * web order cancellations from copies that are no longer on the shelf).
 *
 * Hollywood and Pico copies only reach nivessa.com when the product was
 * shelf-counted at that store or bought since TRUSTED_FROM (when purchases
 * started being tracked). Opening stock doesn't count: the Jan 2026 import
 * loaded old inventory that way. Older uncounted store stock reads 0 on the website
 * until someone counts it. Warehouse locations and comics/magazines (counted
 * by series, not by title) are left as they are.
 *
 * Applied in the Connector ProductResource, the website's only stock feed.
 */
class WebsiteStockRule
{
    // Purchases jump from ~70/month to 318 in Dec 2025 and keep climbing:
    // that's when buys started being entered.
    const TRUSTED_FROM = '2025-12-01 00:00:00';

    // Off (Sarah 10/20): /products/website-stock-rule/check showed item age
    // doesn't predict which web orders fail, so the rule blocked about as many
    // good sales as bad ones. Counting is the fix; the reports stay.
    const ENABLED = false;

    private static $counts = [];
    private static $from = [];
    private static $storeLocations = [];

    /** Quantity the website may sell for one product at one location. */
    public static function qty(int $businessId, int $locationId, int $productId, $qty, $categoryName = null)
    {
        if (!self::ENABLED || (float) $qty <= 0 || !self::isStore($businessId, $locationId)) return $qty;
        if ($categoryName && preg_match('/comic|magazine/i', (string) $categoryName)) return $qty;
        return self::trusted($businessId, $locationId, $productId) ? $qty : 0;
    }

    public static function trusted(int $businessId, int $locationId, int $productId): bool
    {
        $from = self::from($businessId);
        $c = self::$counts[$businessId][$locationId . ':' . $productId] ?? null;
        if ($c && ($c['at'] ?? '') >= $from) return true;
        return DB::table('purchase_lines as l')->join('transactions as t', 't.id', '=', 'l.transaction_id')
            ->where('l.product_id', $productId)->where('t.business_id', $businessId)->where('t.type', 'purchase')
            ->where('t.transaction_date', '>=', $from)->exists();
    }

    /** Cutoff date: website_trusted_from in the reorder state, else TRUSTED_FROM. */
    public static function from(int $businessId): string
    {
        if (!isset(self::$from[$businessId])) {
            $state = app(ReorderService::class)->loadState($businessId);
            self::$counts[$businessId] = $state['counts'] ?? [];
            self::$from[$businessId] = $state['website_trusted_from'] ?? self::TRUSTED_FROM;
        }
        return self::$from[$businessId];
    }

    /** Hollywood / Pico: any location whose name doesn't say "warehouse". */
    private static function isStore(int $businessId, int $locationId): bool
    {
        if (!isset(self::$storeLocations[$businessId])) {
            self::$storeLocations[$businessId] = DB::table('business_locations')->where('business_id', $businessId)
                ->where('name', 'not like', '%warehouse%')->pluck('id')->map(function ($id) { return (int) $id; })->all();
        }
        return in_array($locationId, self::$storeLocations[$businessId], true);
    }
}
