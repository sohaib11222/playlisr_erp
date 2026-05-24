<?php

namespace App\Console\Commands;

use App\Services\InventoryCheckService;
use App\Services\SupplierFetchers\AmsFetcher;
use App\Services\SupplierFetchers\BeggarsFetcher;
use App\Services\SupplierFetchers\RedeyeFetcher;
use App\Services\SupplierFetchers\SecretlyFetcher;
use App\Services\SupplierFetchers\SupplierFetcherContract;
use App\Services\SupplierFetchers\VpFetcher;
use App\Business;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Pull supplier price catalogs from their portals on a cron and persist
 * each as a supplier feed (same JSON store the manual "+ add one"
 * form writes to). The fetched rows merge with any manual entries for
 * that supplier, deduped by (artist|title|format) — latest cost wins.
 *
 * Sarah 2026-05-21 asked for this to be fully automatic. Per-supplier
 * fetcher classes live under app/Services/SupplierFetchers/ and need to
 * be wired up with the real portal URLs + parsers. The runner + cron
 * are in place so each one becomes a "fill-in-the-blanks" job.
 *
 * Usage:
 *   php artisan supplier-prices:fetch all          # all 5 suppliers
 *   php artisan supplier-prices:fetch ams          # just one
 *   php artisan supplier-prices:fetch all --dry    # don't persist
 */
class FetchSupplierPrices extends Command
{
    protected $signature = 'supplier-prices:fetch {supplier=all} {--business-id=} {--dry}';
    protected $description = 'Pull supplier price catalogs from their portals and update the per-supplier feed JSON.';

    /** Map of supplier key → fetcher class. */
    protected array $fetchers = [
        'ams' => AmsFetcher::class,
        'secretly' => SecretlyFetcher::class,
        'beggars' => BeggarsFetcher::class,
        'redeye' => RedeyeFetcher::class,
        'vp' => VpFetcher::class,
    ];

    public function handle(InventoryCheckService $ica)
    {
        $key = (string) $this->argument('supplier');
        $businessId = (int) ($this->option('business-id') ?: $this->resolveDefaultBusinessId());
        if (!$businessId) {
            $this->error('Could not resolve business_id. Pass --business-id=N.');
            return 1;
        }
        $dry = (bool) $this->option('dry');

        $targets = $key === 'all' ? array_keys($this->fetchers) : [$key];
        $exit = 0;
        foreach ($targets as $k) {
            if (!isset($this->fetchers[$k])) {
                $this->error("Unknown supplier: $k");
                $exit = 1;
                continue;
            }
            $this->info('[' . $k . '] starting fetch…');
            /** @var SupplierFetcherContract $fetcher */
            $fetcher = app($this->fetchers[$k]);
            try {
                $rows = $fetcher->fetch();
                $count = count($rows);
                $this->info('[' . $k . '] fetched ' . $count . ' rows.');
                if ($dry) {
                    $this->info('[' . $k . '] --dry passed; not persisting.');
                    $this->writeStatus($businessId, $k, true, "dry-run · $count rows");
                    continue;
                }
                $existing = $ica->loadSupplierFeed($businessId, $k);
                $existingRows = is_array($existing['rows'] ?? null) ? $existing['rows'] : [];
                $byKey = [];
                foreach ($existingRows as $r) {
                    $kk = mb_strtolower(($r['artist'] ?? '') . '|' . ($r['title'] ?? '') . '|' . ($r['format'] ?? ''));
                    $byKey[$kk] = $r;
                }
                foreach ($rows as $r) {
                    $kk = mb_strtolower(($r['artist'] ?? '') . '|' . ($r['title'] ?? '') . '|' . ($r['format'] ?? ''));
                    $byKey[$kk] = $r;
                }
                $merged = array_values($byKey);
                $ica->saveSupplierFeed($businessId, $k, [
                    'business_id' => $businessId,
                    'supplier_key' => $k,
                    'source_file' => 'auto-fetch ' . Carbon::now()->format('Y-m-d H:i'),
                    'imported_at' => Carbon::now()->toIso8601String(),
                    'imported_by' => 'cron',
                    'rows' => $merged,
                ]);
                $this->writeStatus($businessId, $k, true, $count . ' rows fetched; ' . count($merged) . ' total after dedupe');
            } catch (\Throwable $e) {
                $this->error('[' . $k . '] ' . $e->getMessage());
                $this->writeStatus($businessId, $k, false, $e->getMessage());
                $exit = 1;
            }
        }
        return $exit;
    }

    /**
     * Persist a tiny status sidecar so the ICA page can show last-run
     * info + success/failure per supplier without re-running the fetch.
     */
    protected function writeStatus(int $businessId, string $key, bool $ok, string $msg): void
    {
        $path = storage_path('app/supplier-fetch-status-' . $businessId . '.json');
        $existing = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        $existing[$key] = [
            'ok' => $ok,
            'message' => $msg,
            'at' => Carbon::now()->toIso8601String(),
        ];
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $path);
    }

    protected function resolveDefaultBusinessId(): ?int
    {
        try {
            $first = Business::orderBy('id')->first();
            return $first ? (int) $first->id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
