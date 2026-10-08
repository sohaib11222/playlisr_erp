<?php

namespace App\Services;

/**
 * ERP-only "Waiting on Stock" flag for website pickup orders (e.g. Imminence
 * CDs not in yet). Lives here, never on nivessa.com, so setting it can't
 * email or text the customer (Sarah, 2026-10-08).
 *
 * Sidecar JSON list of website order ids — same pattern as AmsPickupOrders
 * (no migrations on this box; atomic temp-then-rename write).
 */
class WaitingStockOrders
{
    protected static function path(int $business_id): string
    {
        return storage_path('app/pickup-waiting-stock-' . $business_id . '.json');
    }

    /** Flagged website order ids. */
    public static function ids(int $business_id): array
    {
        $path = self::path($business_id);
        if (!is_file($path)) {
            return [];
        }
        try {
            $json = json_decode((string) file_get_contents($path), true);
            return is_array($json) ? array_values(array_map('strval', $json)) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function set(int $business_id, string $orderId, bool $waiting): void
    {
        $ids = array_values(array_diff(self::ids($business_id), [$orderId]));
        if ($waiting) {
            $ids[] = $orderId;
        }
        $path = self::path($business_id);
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($ids));
        @rename($tmp, $path);
    }
}
