<?php

namespace App\Services;

use App\Business;

/**
 * Beta buy form (Jon 2026-10-23): target margins for "Valuable item (eBay sold
 * value)" lines, set in Settings -> Buy Margins (business.common_settings, no
 * migration). The margin is on cash: we pay (100 - margin)% of the eBay sold
 * value, and the margin depends on how fast the item's category sells at the
 * store. Never below the floor, whatever the tiers say.
 *
 * Items worth hv_min ($20) or more pay at most hv_pay (20%) of the eBay value,
 * or hv_fast_pay (25%) when the category sells super fast (Jon 2026-10-23).
 */
class BuyMarginSettings
{
    const DEFAULTS = ['fast' => 55, 'normal' => 65, 'slow' => 75, 'floor' => 50, 'hv_min' => 20, 'hv_pay' => 20, 'hv_fast_pay' => 25];

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
            $out[$k] = $k === 'hv_min' ? max(0.0, $v) : max(0.0, min(95.0, $v));
        }
        return self::$memo[$businessId] = $out;
    }

    /**
     * Fraction of the eBay value we pay on one line: (100 - margin)%, but for
     * items worth hv_min+ at most hv_pay% (hv_fast_pay% if super fast).
     * Shared by the calculator; the Beta form's JS mirrors it.
     */
    public static function payFraction(array $s, $margin, $superFast, $value)
    {
        $pay = (100 - (float) $margin) / 100;
        if ((float) $value >= (float) $s['hv_min']) {
            $pay = $superFast ? (float) $s['hv_fast_pay'] / 100 : min($pay, (float) $s['hv_pay'] / 100);
        }
        return round(max(0, $pay), 4);
    }

    /** Margin % for a speed tier (fast / normal / slow), floored. */
    public static function marginFor($businessId, $tier)
    {
        $m = self::get($businessId);
        $tierMargin = $m[$tier] ?? $m['normal'];
        return max($m['floor'], $tierMargin);
    }
}
