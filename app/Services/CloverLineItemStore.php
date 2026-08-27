<?php

namespace App\Services;

/**
 * JSON sidecar for itemized Clover line items — one file per Clover order,
 * under storage/app/clover-line-items/{business_id}/{clover_order_id}.json.
 * Same reasoning as cloverManualMatchPath in SellPosController: playlisr_erp
 * doesn't run prod migrations, so this stays a file instead of a table.
 */
class CloverLineItemStore
{
    protected static function path(int $businessId, string $cloverOrderId): string
    {
        $dir = storage_path('app/clover-line-items/' . $businessId);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        return $dir . '/' . $cloverOrderId . '.json';
    }

    /** Full record for one order (order meta + items), or [] if never synced. */
    public static function load(int $businessId, string $cloverOrderId): array
    {
        $path = static::path($businessId, $cloverOrderId);
        if (!is_file($path)) return [];
        $json = json_decode((string) file_get_contents($path), true);
        return is_array($json) ? $json : [];
    }

    /** Overwrite the record for one order — idempotent, safe to re-run. */
    public static function save(int $businessId, string $cloverOrderId, array $record): void
    {
        $path = static::path($businessId, $cloverOrderId);
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $path);
    }

    /** All synced order records for a business, newest file first. */
    public static function allForBusiness(int $businessId): array
    {
        $dir = storage_path('app/clover-line-items/' . $businessId);
        if (!is_dir($dir)) return [];
        $files = glob($dir . '/*.json') ?: [];
        rsort($files);
        $out = [];
        foreach ($files as $f) {
            $json = json_decode((string) file_get_contents($f), true);
            if (is_array($json)) $out[] = $json;
        }
        return $out;
    }
}
