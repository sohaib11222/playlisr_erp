<?php

namespace App\Services;

/**
 * Sidecar store mapping a Purchase (transaction id) -> its AMS order number.
 *
 * Lets staff record which AMS order a purchase came from, so that when the
 * purchase is marked received we can match it to the customer special-order
 * pickups tagged with the same number and notify them automatically.
 *
 * JSON, no DB column (we don't run migrations on this box). Mirrors the
 * AmsPickupOrders / consignment sidecar pattern.
 */
class AmsPurchaseOrders
{
    protected static function path(int $business_id): string
    {
        return storage_path('app/ams-purchase-orders-' . $business_id . '.json');
    }

    /** Full map { "<transaction_id>": "<ams_order_number>" }. */
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

    /** The AMS order number recorded for a purchase, or null. */
    public static function get(int $business_id, int $transactionId): ?string
    {
        $all = self::load($business_id);
        $val = $all[(string) $transactionId] ?? null;
        return ($val !== null && trim((string) $val) !== '') ? (string) $val : null;
    }

    /** Set / clear the AMS order number on a purchase. Blank clears it. */
    public static function put(int $business_id, int $transactionId, ?string $amsNumber): void
    {
        $all = self::load($business_id);
        $key = (string) $transactionId;
        $clean = trim((string) $amsNumber);
        if ($clean === '') {
            unset($all[$key]);
        } else {
            $all[$key] = $clean;
        }
        self::save($business_id, $all);
    }
}
