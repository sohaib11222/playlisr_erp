<?php

namespace App\Services\SupplierFetchers;

/**
 * Each per-supplier fetcher implements this. The cron command iterates
 * configured suppliers and calls fetch() on each, then hands the
 * normalized row list to InventoryCheckService::saveSupplierFeed().
 *
 * Rows must be shaped like the manual entries the ICA already accepts:
 *   [{artist:?string, title:?string, format:?string, cost:?float, upc:?string}, ...]
 *
 * Throw \RuntimeException with a clear message on failure — the command
 * catches + logs it + records the failure on the supplier's last-run
 * status so the UI can show "Auto-fetch failed: …".
 */
interface SupplierFetcherContract
{
    /**
     * The canonical supplier key — matches keys in
     * config('inventory_check.buckets.supplier_feeds').
     */
    public function supplierKey(): string;

    /**
     * Read credentials from .env (or wherever) and return them.
     * Throw if any required value is missing so the command logs why
     * the fetcher is skipped (vs silently failing).
     *
     * @return array<string,string>
     */
    public function readCredentials(): array;

    /**
     * Log in (if needed), pull the current price list, parse, return.
     *
     * @return array<int, array{artist:?string, title:?string, format:?string, cost:?float, upc:?string}>
     */
    public function fetch(): array;
}
