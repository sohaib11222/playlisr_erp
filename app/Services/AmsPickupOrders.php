<?php

namespace App\Services;

/**
 * Sidecar store for the "AMS special order" overlay on Customer Pickups.
 *
 * A customer can order something we don't stock; staff order it from the
 * AMS distributor and log a Customer Pickup *in advance*. While it's still
 * on the truck the pickup is flagged "on order" here, with the AMS order
 * number + expected arrival date. When it lands, staff hit "Arrived",
 * which clears the on-order flag (so the row becomes a normal Ready pickup)
 * and fires the customer alert.
 *
 * Stored as JSON keyed by pickup id — NOT a DB column — because we don't
 * run migrations on this box. Mirrors the consignment / clover-match
 * sidecar pattern (storage/app/*.json, atomic temp-then-rename write).
 */
class AmsPickupOrders
{
    protected static function path(int $business_id): string
    {
        return storage_path('app/ams-pickups-' . $business_id . '.json');
    }

    /** Full map { "<pickup_id>": {ams_order_number, expected_date, on_order, arrived_at, notified} }. */
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

    /** One pickup's AMS overlay, or null if it isn't an AMS order. */
    public static function get(int $business_id, int $pickupId): ?array
    {
        $all = self::load($business_id);
        return $all[(string) $pickupId] ?? null;
    }

    /**
     * Attach / update AMS data on a pickup. Pass on_order=true while the
     * order is still inbound. No-ops cleanly on a blank order number when
     * not on order (i.e. a plain pickup with nothing AMS about it).
     */
    public static function put(int $business_id, int $pickupId, array $fields): void
    {
        $all = self::load($business_id);
        $key = (string) $pickupId;
        $existing = $all[$key] ?? [];
        $all[$key] = array_merge([
            'ams_order_number' => null,
            'expected_date'    => null,
            'on_order'         => false,
            'arrived_at'       => null,
            'notified'         => false,
        ], $existing, $fields);
        self::save($business_id, $all);
    }

    /** Drop a pickup from the sidecar (on delete). */
    public static function forget(int $business_id, int $pickupId): void
    {
        $all = self::load($business_id);
        unset($all[(string) $pickupId]);
        self::save($business_id, $all);
    }

    /** Pickup ids still flagged on-order (inbound, not yet arrived). */
    public static function onOrderIds(int $business_id): array
    {
        $ids = [];
        foreach (self::load($business_id) as $id => $row) {
            if (!empty($row['on_order'])) {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }

    /**
     * Still-inbound pickup ids whose AMS order number matches $amsNumber
     * (trimmed, case-insensitive). Used to fan a received purchase out to
     * every customer waiting on that order.
     */
    public static function onOrderIdsByAmsNumber(int $business_id, string $amsNumber): array
    {
        $needle = strtolower(trim($amsNumber));
        if ($needle === '') {
            return [];
        }
        $ids = [];
        foreach (self::load($business_id) as $id => $row) {
            if (empty($row['on_order'])) {
                continue;
            }
            $have = strtolower(trim((string) ($row['ams_order_number'] ?? '')));
            if ($have !== '' && $have === $needle) {
                $ids[] = (int) $id;
            }
        }
        return $ids;
    }
}
