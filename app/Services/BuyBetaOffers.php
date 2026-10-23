<?php

namespace App\Services;

/**
 * Sidecar store of offers priced on the Beta buy form (demand bonus), keyed
 * by buy_customer_offers.id:
 *   { "<offer_id>": { bonus_cash, updated_at, lines: [{line_order, title, item_type, multiplier, level, reason, genre}] } }
 *
 * JSON, no DB column (we don't run migrations on this box). Mirrors the
 * AmsPurchaseOrders sidecar pattern. Also lets accept reuse the multipliers
 * the cashier was quoted, so the overpay guard compares against that number.
 */
class BuyBetaOffers
{
    protected static function path(int $business_id): string
    {
        return storage_path('app/buy-beta-' . $business_id . '.json');
    }

    public static function load(int $business_id): array
    {
        $path = self::path($business_id);
        if (!is_file($path)) {
            return [];
        }
        try {
            $json = json_decode((string) file_get_contents($path), true);
            return is_array($json) ? $json : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected static function save(int $business_id, array $data): void
    {
        $path = self::path($business_id);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $path);
    }

    public static function get(int $business_id, int $offerId): ?array
    {
        $all = self::load($business_id);
        $val = $all[(string) $offerId] ?? null;
        return is_array($val) ? $val : null;
    }

    /** Record (or refresh) the demand bonus for an offer from its calculation. */
    public static function put(int $business_id, int $offerId, array $calculation): void
    {
        $lines = [];
        foreach ((array) ($calculation['lines'] ?? []) as $l) {
            $lines[] = [
                'line_order' => $l['line_order'] ?? null,
                'title' => $l['title'] ?? null,
                'item_type' => $l['item_type'] ?? null,
                'multiplier' => (float) ($l['demand_multiplier'] ?? 1),
                'level' => $l['demand_level'] ?? 'normal',
                'reason' => $l['demand_reason'] ?? null,
                'genre' => $l['demand_genre'] ?? null,
            ];
        }
        $all = self::load($business_id);
        $all[(string) $offerId] = [
            'bonus_cash' => round((float) ($calculation['demand_bonus_cash'] ?? 0), 2),
            'updated_at' => now()->toDateTimeString(),
            'lines' => $lines,
        ];
        self::save($business_id, $all);
    }
}
