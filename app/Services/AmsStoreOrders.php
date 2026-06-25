<?php

namespace App\Services;

/**
 * Sidecar store for store-level AMS restock orders — "what we ordered from
 * AMS and what's still coming."
 *
 * This is NOT a customer special order (that's AmsPickupOrders, keyed to a
 * Customer Pickup). This is the buyer's own log: Sarah emails AMS a list of
 * titles to restock Hollywood / Pico, records it here, and can see at a
 * glance what's still inbound so she doesn't accidentally order it twice.
 *
 * Each order: { id, store, ordered_date, status, ams_ref, expected_date,
 * items (free text — paste the list as sent), notes, created_by,
 * created_by_name, created_at, updated_at, arrived_at }.
 *
 * JSON, no DB column (we don't run migrations on this box). Mirrors the
 * AmsPickupOrders / AmsPurchaseOrders / consignment sidecar pattern
 * (storage/app/*.json, atomic temp-then-rename write).
 */
class AmsStoreOrders
{
    /** Open = still expecting product in. */
    const OPEN_STATUSES = ['placed', 'partial'];

    public static function statuses(): array
    {
        return [
            'placed'    => 'Ordered (on the way)',
            'partial'   => 'Partially arrived',
            'arrived'   => 'Arrived (all in)',
            'cancelled' => 'Cancelled',
        ];
    }

    protected static function path(int $business_id): string
    {
        return storage_path('app/ams-store-orders-' . $business_id . '.json');
    }

    /** Full list of orders, newest ordered_date first. */
    public static function all(int $business_id): array
    {
        $path = self::path($business_id);
        if (!is_file($path)) {
            return [];
        }
        try {
            $json = json_decode((string) file_get_contents($path), true);
            $rows = is_array($json) ? array_values($json) : [];
        } catch (\Throwable $e) {
            return [];
        }

        usort($rows, function ($a, $b) {
            $da = (string) ($a['ordered_date'] ?? '');
            $db = (string) ($b['ordered_date'] ?? '');
            if ($da === $db) {
                return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
            }
            return strcmp($db, $da);
        });

        return $rows;
    }

    protected static function save(int $business_id, array $rows): void
    {
        $path = self::path($business_id);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $path);
    }

    public static function find(int $business_id, int $id): ?array
    {
        foreach (self::all($business_id) as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return $row;
            }
        }
        return null;
    }

    /** Orders still inbound (placed / partial), newest first. */
    public static function open(int $business_id): array
    {
        return array_values(array_filter(self::all($business_id), function ($row) {
            return in_array($row['status'] ?? '', self::OPEN_STATUSES, true);
        }));
    }

    /** Create a new order. Returns the stored row (with its new id). */
    public static function create(int $business_id, array $fields): array
    {
        $rows = self::all($business_id);
        $nextId = 1;
        foreach ($rows as $row) {
            $nextId = max($nextId, (int) ($row['id'] ?? 0) + 1);
        }

        $now = now()->toDateTimeString();
        $order = array_merge([
            'id'              => $nextId,
            'store'           => '',
            'ordered_date'    => substr($now, 0, 10),
            'status'          => 'placed',
            'ams_ref'         => null,
            'expected_date'   => null,
            'items'           => '',
            'notes'           => null,
            'created_by'      => null,
            'created_by_name' => null,
            'created_at'      => $now,
            'updated_at'      => $now,
            'arrived_at'      => null,
        ], $fields, ['id' => $nextId, 'created_at' => $now, 'updated_at' => $now]);

        $rows[] = $order;
        self::save($business_id, $rows);
        return $order;
    }

    /** Merge updates into an existing order. */
    public static function update(int $business_id, int $id, array $fields): ?array
    {
        $rows = self::all($business_id);
        $updated = null;
        foreach ($rows as &$row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                unset($fields['id'], $fields['created_at'], $fields['created_by'], $fields['created_by_name']);
                $row = array_merge($row, $fields, [
                    'id'         => $id,
                    'updated_at' => now()->toDateTimeString(),
                ]);
                $updated = $row;
            }
        }
        unset($row);

        if ($updated !== null) {
            self::save($business_id, $rows);
        }
        return $updated;
    }

    public static function delete(int $business_id, int $id): void
    {
        $rows = array_values(array_filter(self::all($business_id), function ($row) use ($id) {
            return (int) ($row['id'] ?? 0) !== $id;
        }));
        self::save($business_id, $rows);
    }
}
