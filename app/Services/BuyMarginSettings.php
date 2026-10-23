<?php

namespace App\Services;

use App\Business;

/**
 * Beta buy form (Jon 2026-10-23): target margins for "Valuable item (eBay sold
 * value)" lines, set in Settings -> Buy Margins (business.common_settings, no
 * migration). The margin is on cash: we pay (100 - margin)% of the eBay sold
 * value, and the margin depends on how fast the item's category sells at the
 * store. Never below the floor, whatever the tiers say.
 */
class BuyMarginSettings
{
    const DEFAULTS = ['fast' => 55, 'normal' => 65, 'slow' => 75, 'floor' => 50];

    protected static $memo = [];

    /** @return array{fast: float, normal: float, slow: float, floor: float} percents */
    public static function get($businessId)
    {
        $businessId = (int) $businessId;
        if (isset(self::$memo[$businessId])) {
            return self::$memo[$businessId];
        }
        $common = [];
        try {
            $b = Business::find($businessId);
            $common = $b && is_array($b->common_settings) ? $b->common_settings : [];
        } catch (\Throwable $e) {
            $common = [];
        }
        $out = [];
        foreach (self::DEFAULTS as $k => $def) {
            $v = $common['buy_margin_' . $k] ?? null;
            $v = is_numeric($v) ? (float) $v : $def;
            $out[$k] = max(0.0, min(95.0, $v));
        }
        return self::$memo[$businessId] = $out;
    }

    /** Margin % for a speed tier (fast / normal / slow), floored. */
    public static function marginFor($businessId, $tier)
    {
        $m = self::get($businessId);
        $tierMargin = $m[$tier] ?? $m['normal'];
        return max($m['floor'], $tierMargin);
    }
}
