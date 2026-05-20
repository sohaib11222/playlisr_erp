<?php

namespace App\Services;

use App\BusinessLocation;
use App\Category;
use App\ChartPick;
use App\Contact;
use App\CustomerWant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Cache-bust: deploy 2026-04-29 to ensure FPM OPcache reloads chartPickReason
// signature change (?array $match). Sarah saw stale "must be of the type array"
// errors after the fix landed because OPcache held the pre-fix bytecode.

class InventoryCheckService
{
    /** @var NivessaEventsFetcher */
    protected $eventsFetcher;

    public function __construct(NivessaEventsFetcher $eventsFetcher)
    {
        $this->eventsFetcher = $eventsFetcher;
    }

    /**
     * Resolve preset into filter defaults (location_id, category_ids, dates, supplier_id).
     */
    public function resolvePreset(int $business_id, string $presetKey): array
    {
        $presets = config('inventory_check.presets', []);
        if (!isset($presets[$presetKey])) {
            return [];
        }
        $p = $presets[$presetKey];
        $out = [
            'preset_key' => $presetKey,
            'sale_days' => $p['sale_days'] ?? 90,
        ];

        $locPattern = $p['location_name_pattern'] ?? '';
        if ($locPattern !== '') {
            $loc = BusinessLocation::where('business_id', $business_id)
                ->where('name', 'like', '%' . $locPattern . '%')
                ->orderBy('id')
                ->first();
            if ($loc) {
                $out['location_id'] = $loc->id;
            }
        }

        $catPattern = $p['category_name_pattern'] ?? '';
        if ($catPattern !== '') {
            $ids = Category::where('business_id', $business_id)
                ->where('category_type', 'product')
                ->where('name', 'like', '%' . $catPattern . '%')
                ->pluck('id')
                ->all();
            $out['category_ids'] = $ids;
        }

        $days = (int) ($p['sale_days'] ?? 90);
        $out['sale_end'] = Carbon::now()->format('Y-m-d');
        $out['sale_start'] = Carbon::now()->subDays($days)->format('Y-m-d');

        $supplierPattern = config('inventory_check.default_supplier_name_pattern', 'AMS');
        if ($supplierPattern) {
            $sup = Contact::where('business_id', $business_id)
                ->where(function ($q) {
                    $q->where('type', 'supplier')->orWhere('type', 'both');
                })
                ->where(function ($q) use ($supplierPattern) {
                    $q->where('name', 'like', '%' . $supplierPattern . '%')
                        ->orWhere('supplier_business_name', 'like', '%' . $supplierPattern . '%');
                })
                ->orderBy('id')
                ->first();
            if ($sup) {
                $out['supplier_id'] = $sup->id;
            }
        }

        return $out;
    }

    /**
     * Build the bucketed "Order for this week" view.
     *
     * @param  array<string,mixed>  $input
     * @return array{buckets: array<string,array>, meta: array<string,mixed>}
     */
    /**
     * Current week's purchase budget + actual spend, mirroring the schedule
     * that the product purchase report uses. Returns null outside the
     * 13-week window. Pulled in here so the ICA page can show "you have
     * $X left this week" right next to the reorder list — buying decisions
     * stay anchored to the cash plan instead of running the export blind.
     */
    public function currentPurchaseBudget(int $business_id, $permittedLocations): ?array
    {
        $schedule = $this->purchaseBudgetSchedule();
        $today = Carbon::now()->format('Y-m-d');
        $week = null;
        foreach ($schedule as $w) {
            if ($today >= $w['start'] && $today <= $w['end']) {
                $week = $w;
                break;
            }
        }
        if (!$week) {
            return null;
        }

        $q = DB::table('transactions as t')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'purchase')
            ->whereBetween(DB::raw('date(t.transaction_date)'), [$week['start'], $week['end']]);
        if ($permittedLocations !== 'all') {
            $q->whereIn('t.location_id', $permittedLocations);
        }
        $spent = (float) $q->sum('t.final_total');
        $budget = (float) $week['budget'];
        $remaining = $budget - $spent;
        $pct = $budget > 0 ? min(100, ($spent / $budget) * 100) : 0;

        return [
            'week_no' => $week['week_no'],
            'start' => $week['start'],
            'end' => $week['end'],
            'budget' => $budget,
            'spent' => $spent,
            'remaining' => $remaining,
            'pct_spent' => round($pct, 1),
            'over_budget' => $spent > $budget,
        ];
    }

    /**
     * 13-week purchase budget. Source of truth lives in ReportController
     * (product purchase report); copied here so the ICA page doesn't need
     * to reach into the reports controller. Keep in sync when the cash
     * flow plan rolls forward.
     */
    private function purchaseBudgetSchedule(): array
    {
        return [
            ['week_no' => 1,  'start' => '2026-05-18', 'end' => '2026-05-24', 'budget' => 10954],
            ['week_no' => 2,  'start' => '2026-05-25', 'end' => '2026-05-31', 'budget' => 10954],
            ['week_no' => 3,  'start' => '2026-06-01', 'end' => '2026-06-07', 'budget' => 11238],
            ['week_no' => 4,  'start' => '2026-06-08', 'end' => '2026-06-14', 'budget' => 11238],
            ['week_no' => 5,  'start' => '2026-06-15', 'end' => '2026-06-21', 'budget' => 11238],
            ['week_no' => 6,  'start' => '2026-06-22', 'end' => '2026-06-28', 'budget' => 11238],
            ['week_no' => 7,  'start' => '2026-06-29', 'end' => '2026-07-05', 'budget' => 10954],
            ['week_no' => 8,  'start' => '2026-07-06', 'end' => '2026-07-12', 'budget' => 10954],
            ['week_no' => 9,  'start' => '2026-07-13', 'end' => '2026-07-19', 'budget' => 10954],
            ['week_no' => 10, 'start' => '2026-07-20', 'end' => '2026-07-26', 'budget' => 10954],
            ['week_no' => 11, 'start' => '2026-07-27', 'end' => '2026-08-02', 'budget' => 15000],
            ['week_no' => 12, 'start' => '2026-08-03', 'end' => '2026-08-09', 'budget' => 15000],
            ['week_no' => 13, 'start' => '2026-08-10', 'end' => '2026-08-16', 'budget' => 15000],
        ];
    }

    public function buildBuckets(int $business_id, array $input, $permittedLocations): array
    {
        $locationId = !empty($input['location_id']) ? (int) $input['location_id'] : null;
        if (!$locationId) {
            return ['buckets' => [], 'meta' => ['error' => 'location_required']];
        }

        $categoryIds = $this->resolveCategoryIds($input);
        $saleStart = $input['sale_start'] ?? Carbon::now()->subDays(90)->format('Y-m-d');
        $saleEnd = $input['sale_end'] ?? Carbon::now()->format('Y-m-d');

        // Sarah 2026-05-20: page kept hanging on "Building…". Root cause:
        // every secondary bucket ran sync inside buildBuckets, and many do
        // multi-second queries (long_oos_essentials = 365-day scan,
        // top_artists = 90-day scan + 4-way join, 3 chart_picks buckets
        // each iterate the week's picks doing product lookups). Now ONLY
        // fast_oos + customer_wants run sync — both are cheap. Everything
        // else is a lazy placeholder fetched by JS after the page paints.
        $buckets = [
            'fast_oos' => $this->bucketFastOos($business_id, $locationId, $permittedLocations),
            'customer_wants' => $this->bucketCustomerWants($business_id, $locationId),
            'street_pulse' => $this->lazyPlaceholder('Street Pulse picks'),
            'universal_top' => $this->lazyPlaceholder('Universal top'),
            'apple_music_top' => $this->lazyPlaceholder('Apple Music top 100'),
            'top_artist_new_releases' => $this->lazyPlaceholder('New releases from your top artists'),
            'events_upcoming' => $this->lazyPlaceholder('Upcoming events — stock up'),
            'long_oos_essentials' => $this->lazyPlaceholder('Long out-of-stock essentials'),
            'hot_used_oos' => $this->lazyPlaceholder('Hot used, currently out'),
            'manager_picks' => $this->lazyPlaceholder('Manager picks'),
            'ume_spotlights' => $this->lazyPlaceholder('UMe Update — release spotlights'),
            'abc_a_restock' => $this->lazyPlaceholder('A-class items — restock priority'),
            'frozen_inventory' => $this->lazyPlaceholder('Frozen inventory — DO NOT reorder'),
        ];

        // Optionally filter buckets to categories if the user passed category_ids
        if (!empty($categoryIds)) {
            foreach ($buckets as $key => $bucket) {
                $buckets[$key]['items'] = array_values(array_filter($bucket['items'], function ($it) use ($categoryIds) {
                    return empty($it['category_id']) || in_array((int) $it['category_id'], $categoryIds, true);
                }));
                $buckets[$key]['count'] = count($buckets[$key]['items']);
            }
        }

        return [
            'buckets' => $buckets,
            'meta' => [
                'location_id' => $locationId,
                'category_ids' => $categoryIds,
                'sale_start' => $saleStart,
                'sale_end' => $saleEnd,
                'generated_at' => Carbon::now()->toIso8601String(),
            ],
        ];
    }

    protected function lazyPlaceholder(string $label): array
    {
        return [
            'label' => $label,
            'why' => 'Loading…',
            'items' => [],
            'count' => 0,
            'lazy' => true,
        ];
    }

    /**
     * The slow buckets, computed in one server-side pass so JS only has
     * to fire one extra request after the initial fast_oos render.
     * Returns the same key/shape as buildBuckets so the caller can splice
     * results directly into lastResult.buckets[*].
     */
    public function buildSecondaryBuckets(int $business_id, int $locationId, $permittedLocations): array
    {
        // Cache for 5 min per (business, location) — same pattern as
        // fast_oos. Chart picks alone runs ~1000 per-row SQL lookups
        // and long_oos does a 365-day aggregation, so first build is
        // 20-40s but a re-click is instant.
        $cacheKey = 'ica_secondary_' . $business_id . '_' . $locationId;
        if (filter_var(request()->input('nocache'), FILTER_VALIDATE_BOOLEAN)) {
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
        }
        try {
            return \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addMinutes(5), function () use ($business_id, $locationId, $permittedLocations) {
                return $this->buildSecondaryBucketsUncached($business_id, $locationId, $permittedLocations);
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ICA secondary cache failed', ['err' => $e->getMessage()]);
            return $this->buildSecondaryBucketsUncached($business_id, $locationId, $permittedLocations);
        }
    }

    protected function buildSecondaryBucketsUncached(int $business_id, int $locationId, $permittedLocations): array
    {
        // Wrap each bucket independently so a single failure doesn't take
        // out the rest — Sarah hit a "all stuck on Loading…" 2026-05-20
        // where one bucket exception cascaded.
        $topArtists = [];
        try {
            $topArtists = $this->getTopArtists($business_id, $locationId, $permittedLocations);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ICA getTopArtists failed', ['err' => $e->getMessage()]);
        }

        $safe = function (string $key, string $label, callable $fn) {
            try {
                return $fn();
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('ICA bucket failed: ' . $key, [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile() . ':' . $e->getLine(),
                ]);
                return [
                    'label' => $label,
                    'why' => 'Failed to load: ' . $e->getMessage(),
                    'items' => [], 'count' => 0,
                    'empty_reason' => 'fetch_error',
                ];
            }
        };

        return [
            'street_pulse' => $safe('street_pulse', 'Street Pulse picks', fn () => $this->bucketChartPicks($business_id, $locationId, 'street_pulse', $topArtists, $permittedLocations)),
            'universal_top' => $safe('universal_top', 'Universal top', fn () => $this->bucketChartPicks($business_id, $locationId, 'universal_top', $topArtists, $permittedLocations)),
            'apple_music_top' => $safe('apple_music_top', 'Apple Music top 100', fn () => $this->bucketChartPicks($business_id, $locationId, 'apple_music_top', $topArtists, $permittedLocations)),
            'top_artist_new_releases' => $safe('top_artist_new_releases', 'New releases from your top artists', fn () => $this->bucketTopArtistNewReleases($business_id, $locationId, $topArtists, $permittedLocations)),
            'long_oos_essentials' => $safe('long_oos_essentials', 'Long out-of-stock essentials', fn () => $this->bucketLongOosEssentials($business_id, $locationId, $permittedLocations)),
            'hot_used_oos' => $safe('hot_used_oos', 'Hot used, currently out', fn () => $this->bucketHotUsedOos($business_id, $locationId, $permittedLocations)),
        ];
    }

    /** Public alias for the lazy ABC-restock endpoint. */
    public function bucketAbcARestockPublic(int $business_id, int $locationId, $permittedLocations): array
    {
        $abcMap = $this->computeAbcMap($business_id);
        return $this->bucketAbcARestock($business_id, $locationId, $abcMap, $permittedLocations);
    }

    /** Public alias for the lazy frozen-inventory endpoint. */
    public function bucketFrozenInventoryPublic(int $business_id, int $locationId, $permittedLocations): array
    {
        return $this->bucketFrozenInventory($business_id, $locationId, $permittedLocations);
    }

    public function loadFrozenCorrections(int $business_id): array
    {
        $path = storage_path('app/ica-frozen-corrections-' . $business_id . '.json');
        if (!is_file($path)) return [];
        try {
            $json = json_decode((string) file_get_contents($path), true);
            return is_array($json) ? $json : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Public alias for the lazy manager-picks endpoint. */
    public function bucketManagerPicksPublic(int $business_id, int $locationId, $permittedLocations): array
    {
        return $this->bucketManagerPicks($business_id, $locationId, $permittedLocations);
    }

    /** Public alias for the lazy UMe spotlights endpoint. */
    public function bucketUmeSpotlightsPublic(int $business_id, int $locationId, $permittedLocations): array
    {
        return $this->bucketUmeSpotlights($business_id, $locationId, $permittedLocations);
    }

    /**
     * UMe Update spotlight releases. Reads
     * storage/app/ume-spotlights-{business_id}.json (curated each week
     * from the PDF). Each spotlight: artist, title, release date, format,
     * genre tag, overview. Cross-references psc by artist match to add a
     * "you already carry N" badge + a "Bin: <pos>" hint if in stock.
     */
    protected function bucketUmeSpotlights(int $business_id, int $locationId, $permittedLocations): array
    {
        // Prefer the per-business spotlights file (uploaded each week);
        // fall back to the seed shipped in database/seed_data/ so the
        // bucket isn't empty until Sarah uploads the next PDF.
        $path = storage_path('app/ume-spotlights-' . $business_id . '.json');
        $sourcePath = is_file($path) ? $path : base_path('database/seed_data/ume-spotlights-seed.json');
        if (!is_file($sourcePath)) {
            return [
                'label' => 'UMe Update — release spotlights',
                'why' => 'Upload the UMe Update PDF in More options to populate this. Curated weekly highlights from UMe.',
                'items' => [], 'count' => 0,
                'empty_reason' => 'not_imported',
            ];
        }
        try {
            $json = json_decode((string) file_get_contents($sourcePath), true);
        } catch (\Throwable $e) {
            return [
                'label' => 'UMe Update — release spotlights',
                'why' => 'Failed to read spotlights file: ' . $e->getMessage(),
                'items' => [], 'count' => 0, 'empty_reason' => 'read_error',
            ];
        }
        $spotlights = is_array($json) ? ($json['spotlights'] ?? []) : [];
        if (empty($spotlights)) {
            return [
                'label' => 'UMe Update — release spotlights',
                'why' => 'No spotlights in the current file.',
                'items' => [], 'count' => 0, 'empty_reason' => 'empty',
            ];
        }

        $items = [];
        foreach ($spotlights as $s) {
            if (!is_array($s)) continue;
            $artist = trim((string) ($s['artist'] ?? ''));
            $title = trim((string) ($s['title'] ?? ''));
            if ($artist === '' && $title === '') continue;

            // Cross-reference: do we already carry this artist's titles
            // at this location? If so attach the top match's stock + bin.
            $stock = null; $bin = null; $variation_id = null; $product_id = null; $sku = null; $cost = null;
            if ($artist !== '') {
                $matches = $this->findProductsByArtist($business_id, $artist, 1);
                if ($matches->isNotEmpty()) {
                    $m = $matches->first();
                    $stock = (float) ($m->stock ?? 0);
                    $bin = $m->bin_position ?? null;
                    $variation_id = (int) ($m->variation_id ?? 0);
                    $product_id = (int) ($m->product_id ?? 0);
                    $sku = $m->sku ?? null;
                    $cost = isset($m->cost_price) ? (float) $m->cost_price : null;
                }
            }

            $items[] = [
                'bucket' => 'ume_spotlights',
                'variation_id' => $variation_id,
                'product_id' => $product_id,
                'sku' => $sku,
                'artist' => $artist,
                'product' => $title,
                'format' => $s['formats'] ?? null,
                'genre' => $s['genre_tag'] ?? null,
                'category_name' => null,
                'bin_position' => $bin,
                'stock' => $stock,
                'sold_qty_window' => null,
                'cost_price' => $cost,
                'suggested_qty' => 1,
                'reason' => 'release ' . ($s['release_date_label'] ?? $s['release_date'] ?? '') . ' · ' . mb_strimwidth((string) ($s['overview'] ?? ''), 0, 240, '…'),
                'release_date' => $s['release_date'] ?? null,
                'release_date_label' => $s['release_date_label'] ?? null,
                'overview' => $s['overview'] ?? '',
                'tags' => ['ume_spotlight'],
            ];
        }

        usort($items, fn ($a, $b) => strcmp($a['release_date'] ?? '', $b['release_date'] ?? ''));

        $sourceFile = is_array($json) ? ($json['source_file'] ?? '') : '';
        $updated = is_array($json) ? ($json['updated_at'] ?? '') : '';
        return [
            'label' => 'UMe Update — release spotlights',
            'why' => 'Curated upcoming releases from ' . ($sourceFile ?: 'UMe') . ($updated ? ' · loaded ' . substr($updated, 0, 10) : ''),
            'items' => $items,
            'count' => count($items),
        ];
    }

    // ── Manager picks (Lashyn's suggestions, etc.) ────────────────────

    /**
     * Path to the JSON store for manager picks. Same JSON-on-disk pattern
     * as clover manual matches + universal anniversaries — no migration.
     */
    protected function managerPicksPath(int $business_id): string
    {
        return storage_path('app/ica-manager-picks-' . $business_id . '.json');
    }

    /**
     * Read manager picks. On first read seeds with Lashyn's standing
     * "get more sealed electronic" suggestion (Sarah 2026-05-20) so the
     * page surfaces something useful immediately.
     */
    public function loadManagerPicks(int $business_id): array
    {
        $path = $this->managerPicksPath($business_id);
        if (!is_file($path)) {
            $seed = [[
                'id' => $this->newPickId(),
                'note' => 'Get more sealed electronic',
                'category_pattern' => 'Sealed Electronic',
                'suggested_by' => 'Lashyn',
                'created_at' => Carbon::now()->toIso8601String(),
                'dismissed' => false,
                'dismissed_at' => null,
                'dismissed_by' => null,
            ]];
            $this->saveManagerPicks($business_id, $seed);
            return $seed;
        }
        try {
            $json = json_decode((string) file_get_contents($path), true);
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($json)) return [];
        return $json;
    }

    public function saveManagerPicks(int $business_id, array $picks): void
    {
        $path = $this->managerPicksPath($business_id);
        $dir = dirname($path);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode(array_values($picks), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $path);
    }

    public function newPickId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /**
     * Bucket: for each active manager pick, find a handful of low-stock
     * candidates matching the suggested category. Reason text credits
     * the manager so cashiers know who flagged it.
     */
    protected function bucketManagerPicks(int $business_id, int $locationId, $permittedLocations): array
    {
        $picks = array_values(array_filter($this->loadManagerPicks($business_id), function ($p) {
            return is_array($p) && empty($p['dismissed']);
        }));

        if (empty($picks)) {
            return [
                'label' => 'Manager picks',
                'why' => 'No active manager picks. Managers can add one in More options.',
                'items' => [], 'count' => 0, 'empty_reason' => 'no_active_picks',
            ];
        }

        $perPickLimit = (int) config('inventory_check.buckets.manager_picks.per_pick_limit', 12);
        $maxStock = (int) config('inventory_check.buckets.manager_picks.max_stock', 1);
        $targetStock = (int) config('inventory_check.buckets.manager_picks.target_stock', 3);

        $items = [];
        $pickSummaries = [];
        foreach ($picks as $pick) {
            $by = trim((string) ($pick['suggested_by'] ?? 'Manager'));
            $note = trim((string) ($pick['note'] ?? ''));
            $pattern = trim((string) ($pick['category_pattern'] ?? ''));
            $pickId = (string) ($pick['id'] ?? '');
            $pickSummaries[] = $by . ': "' . $note . '"' . ($pattern ? ' [' . $pattern . ']' : '');

            // No category pattern → can't surface candidates automatically,
            // but the pick still shows in the summary banner above the
            // bucket so it's not invisible.
            if ($pattern === '') {
                continue;
            }

            $catIds = $this->categoryIdsMatching($business_id, $pattern);
            if (empty($catIds)) {
                continue;
            }

            $rows = $this->queryPscRows($business_id, $locationId, $catIds, $permittedLocations);
            $added = 0;
            foreach ($rows as $row) {
                if ($added >= $perPickLimit) break;
                $stock = (float) ($row->stock ?? 0);
                if ($stock > $maxStock) continue;

                $items[] = $this->rowToCandidate($row, $stock, (float) ($row->total_sold ?? 0), $targetStock, [
                    'bucket' => 'manager_picks',
                    'reason' => $by . ': ' . $note,
                    'pick_id' => $pickId,
                    'suggested_by' => $by,
                    'tags' => ['manager_pick'],
                ]);
                $added++;
            }
        }

        $items = $this->dedupeByVariation($items);

        return [
            'label' => 'Manager picks',
            'why' => count($picks) . ' active pick' . (count($picks) === 1 ? '' : 's') . ' · ' . implode(' · ', $pickSummaries),
            'items' => $items,
            'count' => count($items),
            'active_picks' => $picks,
        ];
    }

    protected function resolveCategoryIds(array $input): array
    {
        if (!empty($input['category_ids']) && is_array($input['category_ids'])) {
            return array_map('intval', $input['category_ids']);
        }
        if (!empty($input['category_id'])) {
            return [(int) $input['category_id']];
        }
        return [];
    }

    // ── Fast-moving, out of stock ─────────────────────────────────────

    protected function bucketFastOos(int $business_id, int $locationId, $permittedLocations): array
    {
        // Cached 5 min per (business, location). The 3 avg-sell-days +
        // 2 sold-qty queries cross the full 90-day transaction window
        // (70k+ historical txs) so re-clicking the same store within a
        // few minutes shouldn't repay that cost. Cache is invalidated on
        // sale/purchase via the existing PSC refresh job; if Sarah needs
        // it now, the cache-bust ?nofocache=1 param skips it.
        $cacheKey = 'ica_fast_oos_' . $business_id . '_' . $locationId;
        // Request::boolean() doesn't exist on this Laravel version — use
        // filter_var. Without this, ?nocache=1 500s before the cache code
        // even runs (Sarah hit this 2026-05-20).
        if (filter_var(request()->input('nocache'), FILTER_VALIDATE_BOOLEAN)) {
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
        }
        try {
            return \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addMinutes(5), function () use ($business_id, $locationId, $permittedLocations) {
                return $this->buildFastOosUncached($business_id, $locationId, $permittedLocations);
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ICA fast_oos cache failed', ['err' => $e->getMessage()]);
            return $this->buildFastOosUncached($business_id, $locationId, $permittedLocations);
        }
    }

    protected function buildFastOosUncached(int $business_id, int $locationId, $permittedLocations): array
    {
        $cfg = config('inventory_check.buckets', []);
        $items = [];

        foreach (['fast_oos_vinyl', 'fast_oos_cd'] as $key) {
            $rules = $cfg[$key] ?? null;
            if (!$rules) {
                continue;
            }
            $catIds = $this->categoryIdsMatching($business_id, $rules['category_pattern'] ?? '');
            if (empty($catIds)) {
                continue;
            }

            $saleStart = Carbon::now()->subDays((int) ($rules['sale_days'] ?? 60))->format('Y-m-d');
            $saleEnd = Carbon::now()->format('Y-m-d');

            // Drop the avg-sell-days query entirely (Sarah 2026-05-20).
            // Even scoped to ≤2000 variation IDs it was the bottleneck
            // making the page "take forever". The Sell Speed column was
            // Clyde's preference; current users (Sarah/Jon) just need
            // sold-qty + stock to decide a reorder.
            $rows = $this->queryPscRows($business_id, $locationId, $catIds, $permittedLocations);
            $sold = $this->getSoldQtyByVariation($business_id, $locationId, $saleStart, $saleEnd, $permittedLocations);

            foreach ($rows as $row) {
                $vid = (int) $row->variation_id;
                $stock = (float) ($row->stock ?? 0);
                $sold_in_window = $sold[$vid] ?? 0.0;

                if ($stock > ($rules['max_stock'] ?? 0)) {
                    continue;
                }
                if ($sold_in_window < ($rules['min_sold'] ?? 1)) {
                    continue;
                }

                $items[] = $this->rowToCandidate($row, $stock, $sold_in_window, $rules['target_stock'] ?? 3, [
                    'bucket' => 'fast_oos',
                    'reason' => 'sold ' . (int) $sold_in_window . ' in last ' . ($rules['sale_days'] ?? 60) . 'd, stock ' . (int) $stock,
                ]);
            }
        }

        // The old "fast_seller (any category)" sub-bucket was dropped
        // 2026-05-20 — it queried avg-sell-days across 2000 PSC rows in
        // any category and was the page's biggest single perf cost. The
        // vinyl/CD sub-buckets above cover the actual reorder candidates.

        $items = $this->dedupeByVariation($items);
        // Sort by recent sold-qty descending — items that moved the most
        // in the window land at the top. Simple, fast, no avg-days math.
        usort($items, function ($a, $b) {
            return ($b['sold_qty_window'] ?? 0) <=> ($a['sold_qty_window'] ?? 0);
        });

        // The "last ordered" enrichment was dropped 2026-05-20 — the
        // implementation did a per-item query (N+1) and was the new
        // bottleneck after avg-sell-days went away. Page must load first;
        // the previous-order feedback feature is parked until it can be
        // batched into one query or moved to a lazy endpoint.

        return [
            'label' => 'Fast-moving, out of stock',
            'why' => 'Sold fast in the last 60-90 days; we have zero or near-zero on shelf.',
            'items' => $items,
            'count' => count($items),
        ];
    }

    /**
     * Last purchase per variation at this location: returns
     * [variation_id => ['qty' => N, 'date' => 'YYYY-MM-DD']]
     * Scoped to a passed variation_id set so it stays cheap — no full
     * purchase_lines scan.
     */
    protected function getLastPurchaseByVariation(int $business_id, int $locationId, array $variationIds, $permittedLocations): array
    {
        if (empty($variationIds)) {
            return [];
        }
        $q = DB::table('purchase_lines as pl')
            ->join('transactions as t', 'pl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->where('t.location_id', $locationId)
            ->whereIn('t.type', ['purchase', 'opening_stock', 'purchase_transfer'])
            ->whereIn('pl.variation_id', $variationIds)
            ->select(
                'pl.variation_id',
                DB::raw('MAX(t.transaction_date) as last_date')
            )
            ->groupBy('pl.variation_id');
        if ($permittedLocations !== 'all') {
            $q->whereIn('t.location_id', $permittedLocations);
        }
        $latest = $q->get()->keyBy('variation_id');

        if ($latest->isEmpty()) {
            return [];
        }

        // For each (variation, last_date) pair, pull the qty on that day.
        $datePairs = $latest->map(fn ($r) => ['variation_id' => (int) $r->variation_id, 'last_date' => $r->last_date]);
        $out = [];
        foreach ($datePairs as $p) {
            $row = DB::table('purchase_lines as pl')
                ->join('transactions as t', 'pl.transaction_id', '=', 't.id')
                ->where('t.business_id', $business_id)
                ->where('t.location_id', $locationId)
                ->whereIn('t.type', ['purchase', 'opening_stock', 'purchase_transfer'])
                ->where('pl.variation_id', $p['variation_id'])
                ->where('t.transaction_date', $p['last_date'])
                ->selectRaw('SUM(pl.quantity) as qty')
                ->first();
            $out[$p['variation_id']] = [
                'qty' => $row ? (float) $row->qty : 0.0,
                'date' => Carbon::parse($p['last_date'])->format('Y-m-d'),
            ];
        }
        return $out;
    }

    // ── Chart picks (Street Pulse / Universal Top) ────────────────────

    protected function bucketChartPicks(int $business_id, int $locationId, string $source, array $topArtists, $permittedLocations): array
    {
        $label = $this->chartSourceLabel($source);
        if (!Schema::hasTable('chart_picks')) {
            return [
                'label' => $label,
                'why' => 'chart_picks table not yet migrated — run php artisan migrate.',
                'items' => [],
                'count' => 0,
                'empty_reason' => 'migrations_missing',
            ];
        }

        $week = ChartPick::where('business_id', $business_id)
            ->where('source', $source)
            ->max('week_of');

        if (!$week) {
            $emptyMsg = $source === 'apple_music_top'
                ? 'Daily cron populates this at 09:00 PST. Or click "Run Apple Music pull" above.'
                : 'Paste this week\'s email to populate.';
            return [
                'label' => $label,
                'why' => $emptyMsg,
                'items' => [],
                'count' => 0,
                'empty_reason' => 'not_imported',
            ];
        }

        // Capped at 100 (was 500) 2026-05-20 — each pick fires a fuzzy
        // LIKE lookup in tryMatchChartPickToVariation, and 3 sources ×
        // 500 picks = 1500 sequential queries was the dominant cost in
        // secondaryBuckets. Top 100 covers everything cashiers care
        // about; raise via config if needed.
        $picks = ChartPick::where('business_id', $business_id)
            ->where('source', $source)
            ->whereDate('week_of', $week)
            ->orderBy('chart_rank')
            ->limit((int) config('inventory_check.chart_picks_per_source', 100))
            ->get();

        $topArtistsLower = array_map('mb_strtolower', $topArtists);
        $items = [];

        foreach ($picks as $pick) {
            $artistLower = mb_strtolower((string) $pick->artist);
            $isTopArtist = $this->isTopArtistMatch($artistLower, $topArtistsLower);

            $match = $this->tryMatchChartPickToVariation($business_id, $pick->artist, $pick->title);
            $stock = $match['stock'] ?? null;
            $items[] = [
                'bucket' => $source,
                'chart_rank' => $pick->chart_rank,
                'artist' => $pick->artist,
                'product' => $pick->title,
                'format' => $pick->format,
                'is_new_release' => (bool) $pick->is_new_release,
                'is_top_artist' => $isTopArtist,
                'variation_id' => $match['variation_id'] ?? null,
                'product_id' => $match['product_id'] ?? null,
                'sku' => $match['sku'] ?? null,
                'stock' => $stock,
                'sold_qty_window' => $match['sold_qty_window'] ?? 0,
                'location_name' => $match['location_name'] ?? null,
                'category_name' => $match['category_name'] ?? null,
                'category_id' => $match['category_id'] ?? null,
                'genre' => $match['genre'] ?? null,
                'bin_position' => $match['bin_position'] ?? null,
                'is_rsd' => $this->isRsdTitle((string) ($pick->title ?? '')),
                'suggested_qty' => $this->suggestedQtyForChartPick($pick, $stock, $isTopArtist),
                'reason' => $this->chartPickReason($pick, $isTopArtist, $match),
                'tags' => array_values(array_filter([
                    $source,
                    $pick->is_new_release ? 'new_release' : null,
                    $isTopArtist ? 'top_artist' : null,
                ])),
            ];
        }

        // Sort: top-artist + new release first, then top-artist, then new release, then rank
        usort($items, function ($a, $b) {
            $aScore = ($a['is_top_artist'] ? 2 : 0) + ($a['is_new_release'] ? 1 : 0);
            $bScore = ($b['is_top_artist'] ? 2 : 0) + ($b['is_new_release'] ? 1 : 0);
            if ($aScore !== $bScore) {
                return $bScore <=> $aScore;
            }
            return ($a['chart_rank'] ?? PHP_INT_MAX) <=> ($b['chart_rank'] ?? PHP_INT_MAX);
        });

        return [
            'label' => $label,
            'why' => 'From the most recent ' . $this->chartSourceFriendlyName($source) . ' chart (imported ' . $week . '). Rows tagged "top_artist" are artists already popular in-store.',
            'items' => $items,
            'count' => count($items),
            'week_of' => (string) $week,
        ];
    }

    protected function chartSourceLabel(string $source): string
    {
        switch ($source) {
            case 'street_pulse': return 'Street Pulse picks';
            case 'universal_top': return 'Universal top';
            case 'apple_music_top': return 'Apple Music top 100';
            default: return ucwords(str_replace('_', ' ', $source));
        }
    }

    protected function chartSourceFriendlyName(string $source): string
    {
        switch ($source) {
            case 'street_pulse': return 'Street Pulse';
            case 'universal_top': return 'Universal top';
            case 'apple_music_top': return 'Apple Music top 100';
            default: return str_replace('_', ' ', $source);
        }
    }

    protected function isTopArtistMatch(string $artistLower, array $topArtistsLower): bool
    {
        if ($artistLower === '') {
            return false;
        }
        foreach ($topArtistsLower as $top) {
            if ($top === '' || $artistLower === '') {
                continue;
            }
            if ($artistLower === $top) {
                return true;
            }
            // fuzzy: starts with, or Levenshtein ≤ 2 for short names
            if (mb_strlen($top) > 3 && (mb_strpos($artistLower, $top) !== false || mb_strpos($top, $artistLower) !== false)) {
                return true;
            }
        }
        return false;
    }

    protected function suggestedQtyForChartPick($pick, ?float $stock, bool $isTopArtist): int
    {
        $base = $isTopArtist ? 2 : 1;
        if ($pick->is_new_release) {
            $base = max($base, 2);
        }
        if ($stock !== null && $stock >= 3) {
            return 0; // already well-stocked
        }
        return $base;
    }

    protected function chartPickReason($pick, bool $isTopArtist, ?array $match): string
    {
        // Accept null match — tryMatchChartPickToVariation returns null
        // when nothing in the catalog matches the chart pick, and this
        // method gets called with that null directly. Treating it as []
        // keeps the rest of the logic happy (the empty checks all pass).
        $match = $match ?? [];
        $bits = [];
        if ($isTopArtist) {
            $bits[] = 'popular in-store';
        }
        if ($pick->is_new_release) {
            $bits[] = 'new release';
        }
        if (!empty($match['variation_id']) && ($match['stock'] ?? 0) <= 0) {
            $bits[] = 'out of stock';
        } elseif (empty($match['variation_id'])) {
            $bits[] = 'not yet in catalog';
        }
        if (empty($bits)) {
            $bits[] = 'chart pick';
        }
        return implode('; ', $bits);
    }

    protected function tryMatchChartPickToVariation(int $business_id, ?string $artist, ?string $title): ?array
    {
        if (!$title) {
            return null;
        }
        $q = DB::table('product_stock_cache as psc')
            ->leftJoin('products as p', 'p.id', '=', 'psc.product_id')
            ->leftJoin('categories as subcat', 'subcat.id', '=', 'psc.sub_category_id')
            ->leftJoin('variations as v', 'v.id', '=', 'psc.variation_id')
            ->where('psc.business_id', $business_id)
            ->where('psc.product', 'like', '%' . $title . '%')
            ->select([
                'psc.variation_id', 'psc.product_id', 'psc.stock', 'psc.sku',
                'psc.location_name', 'psc.category_name', 'psc.category_id',
                'psc.total_sold', 'subcat.name as genre', 'p.bin_position',
                'v.default_purchase_price as cost_price',
            ])
            ->limit(10);

        if ($artist) {
            $q->where(function ($w) use ($artist) {
                $w->where('psc.product_custom_field1', 'like', '%' . $artist . '%')
                    ->orWhere('psc.product', 'like', '%' . $artist . '%');
            });
        }

        $rows = $q->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $row = $rows->first();

        return [
            'variation_id' => (int) $row->variation_id,
            'product_id' => (int) $row->product_id,
            'sku' => $row->sku,
            'stock' => (float) ($row->stock ?? 0),
            'sold_qty_window' => (float) ($row->total_sold ?? 0),
            'location_name' => $row->location_name,
            'category_name' => $row->category_name,
            'category_id' => $row->category_id ?? null,
            'genre' => $row->genre ?? null,
            'bin_position' => $row->bin_position ?? null,
            'cost_price' => isset($row->cost_price) ? (float) $row->cost_price : null,
        ];
    }

    // ── New releases from top artists (cross-reference) ───────────────

    protected function bucketTopArtistNewReleases(int $business_id, int $locationId, array $topArtists, $permittedLocations): array
    {
        if (!Schema::hasTable('chart_picks')) {
            return [
                'label' => 'New releases from your top artists',
                'why' => 'chart_picks table not yet migrated — run php artisan migrate.',
                'items' => [],
                'count' => 0,
                'empty_reason' => 'migrations_missing',
            ];
        }

        $latestWeeks = ChartPick::where('business_id', $business_id)
            ->selectRaw('source, MAX(week_of) as w')
            ->groupBy('source')
            ->pluck('w', 'source');

        if ($latestWeeks->isEmpty() || empty($topArtists)) {
            return [
                'label' => 'New releases from your top artists',
                'why' => 'Cross-references your top-selling artists with the week\'s charts. Populates once a chart is pasted.',
                'items' => [],
                'count' => 0,
                'empty_reason' => 'need_charts_and_sales',
            ];
        }

        $topLower = array_map('mb_strtolower', $topArtists);
        $items = [];
        foreach ($latestWeeks as $source => $week) {
            $picks = ChartPick::where('business_id', $business_id)
                ->where('source', $source)
                ->whereDate('week_of', $week)
                ->get();

            foreach ($picks as $pick) {
                $artistLower = mb_strtolower((string) $pick->artist);
                if (!$this->isTopArtistMatch($artistLower, $topLower)) {
                    continue;
                }
                if (!$pick->is_new_release) {
                    // Still include if we don't already carry this specific title
                    $match = $this->tryMatchChartPickToVariation($business_id, $pick->artist, $pick->title);
                    if ($match !== null) {
                        continue; // Already in catalog; Street Pulse section handles it
                    }
                }

                $match = $this->tryMatchChartPickToVariation($business_id, $pick->artist, $pick->title);
                $items[] = [
                    'bucket' => 'top_artist_new_releases',
                    'artist' => $pick->artist,
                    'product' => $pick->title,
                    'format' => $pick->format,
                    'is_new_release' => (bool) $pick->is_new_release,
                    'chart_source' => $source,
                    'chart_rank' => $pick->chart_rank,
                    'variation_id' => $match['variation_id'] ?? null,
                    'product_id' => $match['product_id'] ?? null,
                    'sku' => $match['sku'] ?? null,
                    'stock' => $match['stock'] ?? null,
                    'suggested_qty' => $pick->is_new_release ? 3 : 2,
                    'reason' => $pick->is_new_release
                        ? 'new release from top-selling artist'
                        : 'top artist; we don\'t carry this title',
                    'tags' => ['top_artist', $pick->is_new_release ? 'new_release' : 'missing_title'],
                ];
            }
        }

        // De-dupe by (artist,title) pair
        $seen = [];
        $deduped = [];
        foreach ($items as $it) {
            $key = mb_strtolower(($it['artist'] ?? '') . '|' . ($it['product'] ?? ''));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $deduped[] = $it;
        }

        return [
            'label' => 'New releases from your top artists',
            'why' => 'Artists popular in-store who have a new release (or a title we don\'t yet carry) on this week\'s charts.',
            'items' => $deduped,
            'count' => count($deduped),
        ];
    }

    // ── Upcoming events → stock-up ────────────────────────────────────

    /** Public alias used by the lazy-load endpoint (controller can't call protected). */
    public function bucketEventsUpcomingPublic(int $business_id, int $locationId, $permittedLocations): array
    {
        return $this->bucketEventsUpcoming($business_id, $locationId, $permittedLocations);
    }

    protected function bucketEventsUpcoming(int $business_id, int $locationId, $permittedLocations): array
    {
        $lookahead = (int) config('inventory_check.events_lookahead_days', 30);
        $events = $this->eventsFetcher->upcoming($lookahead);

        // Universal's "Key Anniversaries + Birthdays" tab — biopics, milestone
        // anniversaries, artist birthdays. Persisted on UMe xlsx import; we
        // surface them as synthetic events so a Michael Jackson biopic release
        // or a Drake milestone shows up alongside concerts.
        $annivEvents = $this->loadUniversalAnniversaryEvents($business_id, $lookahead);
        $events = array_merge($events, $annivEvents);

        if (empty($events)) {
            return [
                'label' => 'Upcoming events — stock up',
                'why' => 'Pulled from nivessa.com/events + UMe anniversaries. Set NIVESSA_EVENTS_API_URL in .env or import a UMe xlsx to enable.',
                'items' => [],
                'count' => 0,
                'empty_reason' => 'no_events',
            ];
        }

        $items = [];
        foreach ($events as $event) {
            foreach ($event['artists'] as $artistName) {
                $matches = $this->findProductsByArtist($business_id, $artistName, 3);
                foreach ($matches as $match) {
                    $stock = (float) ($match->stock ?? 0);
                    $isAnniversary = !empty($event['is_anniversary']);
                    $reason = $isAnniversary
                        ? ($event['name'] . ' — ' . $event['date'])
                        : ('event ' . $event['name'] . ' on ' . $event['date']);
                    $tags = $isAnniversary ? ['anniversary'] : ['event'];
                    $items[] = [
                        'bucket' => 'events_upcoming',
                        'event_name' => $event['name'],
                        'event_date' => $event['date'],
                        'event_location' => $event['location'],
                        'artist' => $artistName,
                        'product' => $match->product,
                        'sku' => $match->sku,
                        'format' => $match->product_format ?? null,
                        'variation_id' => (int) $match->variation_id,
                        'product_id' => (int) $match->product_id,
                        'stock' => $stock,
                        'sold_qty_window' => (float) ($match->total_sold ?? 0),
                        'location_name' => $match->location_name,
                        'category_name' => $match->category_name,
                        'genre' => $match->genre ?? null,
                        'bin_position' => $match->bin_position ?? null,
                        'cost_price' => isset($match->cost_price) ? (float) $match->cost_price : null,
                        'is_rsd' => $this->isRsdTitle((string) ($match->product ?? '')),
                        'suggested_qty' => max(1, 3 - (int) $stock),
                        'reason' => $reason,
                        'tags' => $tags,
                    ];
                }
            }
        }

        $concertCount = count($events) - count($annivEvents);

        return [
            'label' => 'Upcoming events — stock up',
            'why' => 'LA concerts + listening parties + UMe artist moments (biopics, anniversaries, birthdays) in the next ' . $lookahead . ' days.',
            'items' => $items,
            'count' => count($items),
            'events_loaded' => count($events),
            'concert_events' => $concertCount,
            'anniversary_events' => count($annivEvents),
        ];
    }

    /**
     * Read storage/app/universal-anniversaries-{business_id}.json (written by
     * the UMe xlsx import) and return rows within the lookahead window in the
     * same shape NivessaEventsFetcher returns, so the events bucket can fold
     * them into its product-matching loop.
     */
    protected function loadUniversalAnniversaryEvents(int $business_id, int $lookaheadDays): array
    {
        $path = storage_path('app/universal-anniversaries-' . $business_id . '.json');
        if (!is_file($path)) {
            return [];
        }

        try {
            $json = json_decode((string) file_get_contents($path), true);
        } catch (\Throwable $e) {
            return [];
        }
        if (!is_array($json) || empty($json['anniversaries']) || !is_array($json['anniversaries'])) {
            return [];
        }

        $today = Carbon::today();
        $cutoff = $today->copy()->addDays($lookaheadDays);
        $out = [];

        foreach ($json['anniversaries'] as $a) {
            if (!is_array($a)) {
                continue;
            }
            $artist = trim((string) ($a['artist'] ?? ''));
            $dateStr = (string) ($a['event_date'] ?? '');
            if ($artist === '' || $dateStr === '') {
                continue;
            }
            try {
                $d = Carbon::parse($dateStr);
            } catch (\Throwable $ignore) {
                continue;
            }
            if ($d->lt($today) || $d->gt($cutoff)) {
                continue;
            }

            // Build a human label: "Michael Jackson — Thriller 45th biopic"
            $album = trim((string) ($a['album_or_track'] ?? ''));
            $moment = trim((string) ($a['moment'] ?? ''));
            $years = $a['years'] ?? null;
            $parts = [$artist];
            if ($album !== '') {
                $parts[] = $album;
            }
            if ($years) {
                $parts[] = $years . 'th';
            }
            if ($moment !== '') {
                $parts[] = $moment;
            }
            $name = implode(' — ', $parts);

            $out[] = [
                'name' => $name,
                'date' => $d->format('Y-m-d'),
                'location' => null,
                'artists' => [$artist],
                'is_anniversary' => true,
                'raw' => $a,
            ];
        }

        usort($out, fn ($a, $b) => strcmp($a['date'], $b['date']));
        return $out;
    }

    protected function findProductsByArtist(int $business_id, string $artist, int $limit = 5)
    {
        if (trim($artist) === '') {
            return collect([]);
        }
        return DB::table('product_stock_cache as psc')
            ->leftJoin('products as p', 'p.id', '=', 'psc.product_id')
            ->leftJoin('categories as subcat', 'subcat.id', '=', 'psc.sub_category_id')
            ->leftJoin('variations as v', 'v.id', '=', 'psc.variation_id')
            ->where('psc.business_id', $business_id)
            ->where(function ($q) use ($artist) {
                $q->where('psc.product_custom_field1', 'like', '%' . $artist . '%')
                    ->orWhere('psc.product', 'like', '%' . $artist . '%');
            })
            ->select([
                'psc.variation_id', 'psc.product_id', 'psc.stock', 'psc.sku', 'psc.product',
                'psc.location_name', 'psc.category_name', 'psc.total_sold',
                'subcat.name as genre',
                'p.format as product_format', 'p.bin_position',
                'v.default_purchase_price as cost_price',
            ])
            ->orderByDesc('psc.total_sold')
            ->limit($limit)
            ->get();
    }

    // ── ABC analysis (by inventory value) ─────────────────────────────

    /**
     * Build a [product_id => 'A'|'B'|'C'] map using the same Pareto-style
     * classification as /reports/abc-inventory-classification:
     *   A = cumulative top 80% of inventory value
     *   B = next 15%
     *   C = bottom 5%
     * Cached for 15 min — values change slowly and the underlying scan
     * touches every stocked product.
     */
    protected function computeAbcMap(int $business_id): array
    {
        $cacheKey = 'ica_abc_map_' . $business_id;
        try {
            return \Illuminate\Support\Facades\Cache::remember($cacheKey, now()->addMinutes(15), function () use ($business_id) {
                return $this->computeAbcMapUncached($business_id);
            });
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('ICA computeAbcMap failed', ['err' => $e->getMessage()]);
            return [];
        }
    }

    protected function computeAbcMapUncached(int $business_id): array
    {
        $rows = DB::table('product_stock_cache as psc')
            ->where('psc.business_id', $business_id)
            ->where('psc.enable_stock', 1)
            ->select(
                'psc.product_id',
                DB::raw('SUM(psc.stock_price) as inventory_value')
            )
            ->groupBy('psc.product_id')
            ->orderByDesc('inventory_value')
            ->get();

        $total = 0.0;
        foreach ($rows as $r) {
            $total += (float) ($r->inventory_value ?? 0);
        }
        if ($total <= 0) {
            return [];
        }

        $map = [];
        $running = 0.0;
        foreach ($rows as $r) {
            $running += (float) ($r->inventory_value ?? 0);
            $pct = ($running / $total) * 100;
            $map[(int) $r->product_id] = $pct <= 80 ? 'A' : ($pct <= 95 ? 'B' : 'C');
        }
        return $map;
    }

    /**
     * A-class items running low. These are the inventory dollars that drive
     * most of the store's value — being out of stock on them is the biggest
     * miss. Stock ≤ 1 with the A label.
     */
    protected function bucketAbcARestock(int $business_id, int $locationId, array $abcMap, $permittedLocations): array
    {
        if (empty($abcMap)) {
            return [
                'label' => 'A-class items — restock priority',
                'why' => 'ABC classification empty — no stocked products with value.',
                'items' => [],
                'count' => 0,
                'empty_reason' => 'no_abc',
            ];
        }

        // Hash-set lookup — in_array on a 1000+ element array against 2000
        // PSC rows was the other reason "Building…" hung.
        $aPidsSet = [];
        foreach ($abcMap as $pid => $cls) {
            if ($cls === 'A') {
                $aPidsSet[(int) $pid] = true;
            }
        }
        if (empty($aPidsSet)) {
            return [
                'label' => 'A-class items — restock priority',
                'why' => 'No A-class products at this location.',
                'items' => [],
                'count' => 0,
                'empty_reason' => 'no_a_class',
            ];
        }

        $maxStock = (int) config('inventory_check.buckets.abc_a_restock.max_stock', 1);
        $targetStock = (int) config('inventory_check.buckets.abc_a_restock.target_stock', 3);

        $rows = $this->queryPscRows($business_id, $locationId, [], $permittedLocations);
        $items = [];
        foreach ($rows as $row) {
            $pid = (int) $row->product_id;
            if (!isset($aPidsSet[$pid])) {
                continue;
            }
            $stock = (float) ($row->stock ?? 0);
            if ($stock > $maxStock) {
                continue;
            }
            $items[] = $this->rowToCandidate($row, $stock, 0, $targetStock, [
                'bucket' => 'abc_a_restock',
                'reason' => 'A-class (top 80% of inventory value), stock ' . (int) $stock,
                'tags' => ['abc_A'],
            ]);
        }

        $items = $this->dedupeByVariation($items);

        return [
            'label' => 'A-class items — restock priority',
            'why' => 'Items in the top 80% of inventory value (ABC class A) that are low or out of stock here. These drive most of the store\'s value — being out hurts the most.',
            'items' => $items,
            'count' => count($items),
            // Full A/B/C map by product_id so JS can paint the ABC pill
            // on rows in OTHER buckets (fast sellers, chart picks, etc.)
            // — Sarah 2026-05-20: "add A, B, or C product for the fast
            // sellers".
            'abc_map' => $abcMap,
        ];
    }

    // ── Frozen inventory (DO NOT REORDER) ─────────────────────────────

    /**
     * Items at this location with stock > 0 but no sale in the configured
     * window (default 180 days). Mirrors /reports/dead-stock but scoped to
     * the current location so Sarah can see "what's already sitting here
     * that I shouldn't reorder more of." suggested_qty is forced to 0 so
     * accidentally checking the row + exporting can't bulk-reorder dead
     * stock.
     */
    protected function bucketFrozenInventory(int $business_id, int $locationId, $permittedLocations): array
    {
        $frozenDays = (int) config('inventory_check.buckets.frozen_inventory.frozen_days', 180);
        $limit = (int) config('inventory_check.buckets.frozen_inventory.max_items', 200);
        $cutoff = Carbon::now()->subDays($frozenDays)->format('Y-m-d H:i:s');

        // Two-step query to avoid scanning the entire transaction history:
        //   1) Pull stocked variations at this location (small set — ≤ a few
        //      thousand rows at most).
        //   2) Look up last_sold for ONLY those variations.
        // Doing it inline as a leftJoinSub forced MySQL to compute the
        // MAX(transaction_date) over every variation in the business (70k+
        // historical txs imported 2026-04-23), which was the spinner
        // Sarah saw stuck on "Building…".
        $pscQuery = DB::table('product_stock_cache as psc')
            ->leftJoin('products as p', 'p.id', '=', 'psc.product_id')
            ->leftJoin('categories as subcat', 'subcat.id', '=', 'psc.sub_category_id')
            ->where('psc.business_id', $business_id)
            ->where('psc.location_id', $locationId)
            ->where('psc.stock', '>', 0);
        if ($permittedLocations !== 'all') {
            $pscQuery->whereIn('psc.location_id', $permittedLocations);
        }
        $stocked = $pscQuery->select([
            'psc.variation_id', 'psc.product_id', 'psc.location_id', 'psc.stock', 'psc.sku',
            'psc.product', 'psc.type', 'psc.product_variation', 'psc.variation_name',
            'psc.location_name', 'psc.category_name', 'psc.category_id',
            'psc.sub_category_id', 'subcat.name as genre',
            'psc.product_custom_field1', 'psc.total_sold', 'psc.stock_price',
            'p.format as product_format', 'p.bin_position',
        ])->orderByDesc('psc.stock_price')->get();

        if ($stocked->isEmpty()) {
            return [
                'label' => 'Frozen inventory — DO NOT reorder',
                'why' => 'No stocked items at this location.',
                'items' => [], 'count' => 0, 'frozen_days' => $frozenDays,
            ];
        }

        $variationIds = $stocked->pluck('variation_id')->map(fn ($v) => (int) $v)->all();
        $lastSold = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereIn('tsl.variation_id', $variationIds)
            ->select('tsl.variation_id', DB::raw('MAX(t.transaction_date) as last_sold'))
            ->groupBy('tsl.variation_id')
            ->pluck('last_sold', 'variation_id');

        $rows = $stocked->filter(function ($r) use ($lastSold, $cutoff) {
            $ls = $lastSold[$r->variation_id] ?? null;
            return $ls === null || $ls < $cutoff;
        })->take($limit)->map(function ($r) use ($lastSold) {
            $r->last_sold = $lastSold[$r->variation_id] ?? null;
            return $r;
        });

        // Load any prior in-place stock corrections done from this page —
        // Sarah wants the most recent "updated by who, when" on each row.
        $corrections = $this->loadFrozenCorrections($business_id);
        $lastCorrectionByVid = [];
        foreach ($corrections as $c) {
            if (!is_array($c) || empty($c['variation_id'])) continue;
            $vid = (int) $c['variation_id'];
            if (!isset($lastCorrectionByVid[$vid]) || $c['when'] > $lastCorrectionByVid[$vid]['when']) {
                $lastCorrectionByVid[$vid] = $c;
            }
        }

        $items = [];
        foreach ($rows as $row) {
            $stock = (float) ($row->stock ?? 0);
            $tiedUp = (float) ($row->stock_price ?? 0);
            $lastSold = $row->last_sold ? Carbon::parse($row->last_sold)->format('Y-m-d') : null;
            $daysSince = $lastSold ? Carbon::parse($lastSold)->diffInDays(Carbon::now()) : null;

            $candidate = $this->rowToCandidate($row, $stock, 0, 0, [
                'bucket' => 'frozen_inventory',
                'reason' => $lastSold
                    ? ('last sold ' . $lastSold . ' (' . $daysSince . 'd ago) · $' . number_format($tiedUp, 0) . ' tied up')
                    : ('never sold · $' . number_format($tiedUp, 0) . ' tied up'),
                'last_sold' => $lastSold,
                'days_since_sold' => $daysSince,
                'tied_up_value' => $tiedUp,
                'tags' => ['frozen', 'do_not_reorder'],
            ]);

            // Annotate with the most recent in-place correction (if any).
            $vid = (int) ($candidate['variation_id'] ?? 0);
            if ($vid && !empty($lastCorrectionByVid[$vid])) {
                $c = $lastCorrectionByVid[$vid];
                $candidate['last_correction'] = [
                    'when' => $c['when'] ?? null,
                    'by' => $c['user_name'] ?? '',
                    'before' => $c['before'] ?? null,
                    'after' => $c['after'] ?? null,
                ];
            }

            // Force suggested_qty to 0 — this bucket is a warning list, not
            // a reorder list. rowToCandidate may have nudged it to 1 if a
            // small sold-window was passed in some future call path.
            $candidate['suggested_qty'] = 0;
            $items[] = $candidate;
        }

        $totalTied = 0.0;
        foreach ($items as $it) {
            $totalTied += (float) ($it['tied_up_value'] ?? 0);
        }

        return [
            'label' => 'Frozen inventory — DO NOT reorder',
            'why' => 'Stock-on-shelf with no sale in ' . $frozenDays . '+ days. Total $' . number_format($totalTied, 0) . ' tied up here. Cross-reference: rows in other buckets that match these are tagged "frozen_dupe".',
            'items' => $items,
            'count' => count($items),
            'frozen_days' => $frozenDays,
            'tied_up_value_total' => round($totalTied, 2),
        ];
    }

    // ── Long OOS essentials (auto-detected) ───────────────────────────

    protected function bucketLongOosEssentials(int $business_id, int $locationId, $permittedLocations): array
    {
        $cfg = config('inventory_check.buckets.long_oos_essentials', [
            'lookback_days' => 365,
            'min_lifetime_sold' => 12,
            'min_oos_days' => 14,
            'target_stock' => 2,
        ]);

        $lookbackStart = Carbon::now()->subDays((int) $cfg['lookback_days'])->format('Y-m-d');
        $today = Carbon::now()->format('Y-m-d');

        // Sum sales per variation at location over lookback
        $sold = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.location_id', $locationId)
            ->whereBetween(DB::raw('DATE(t.transaction_date)'), [$lookbackStart, $today])
            ->groupBy('tsl.variation_id')
            ->havingRaw('SUM(tsl.quantity - tsl.quantity_returned) >= ?', [(float) $cfg['min_lifetime_sold']])
            ->select('tsl.variation_id', DB::raw('SUM(tsl.quantity - tsl.quantity_returned) as sold_qty'),
                DB::raw('MAX(t.transaction_date) as last_sold_at'))
            ->get();

        if ($sold->isEmpty()) {
            return [
                'label' => '⚠️ Long out-of-stock essentials',
                'why' => 'Sold ' . $cfg['min_lifetime_sold'] . '+ in the last ' . $cfg['lookback_days'] . 'd; currently OOS for ' . $cfg['min_oos_days'] . '+ days.',
                'items' => [],
                'count' => 0,
            ];
        }

        $soldMap = [];
        $lastSoldMap = [];
        foreach ($sold as $row) {
            $soldMap[(int) $row->variation_id] = (float) $row->sold_qty;
            $lastSoldMap[(int) $row->variation_id] = $row->last_sold_at;
        }

        // Pull stock cache for these variations, filter by stock=0
        $variationIds = array_keys($soldMap);
        $minOosDate = Carbon::now()->subDays((int) $cfg['min_oos_days'])->format('Y-m-d H:i:s');

        $rows = DB::table('product_stock_cache as psc')
            ->leftJoin('products as p', 'p.id', '=', 'psc.product_id')
            ->leftJoin('categories as subcat', 'subcat.id', '=', 'psc.sub_category_id')
            ->where('psc.business_id', $business_id)
            ->where('psc.location_id', $locationId)
            ->whereIn('psc.variation_id', $variationIds)
            ->where('psc.stock', '<=', 0)
            ->select([
                'psc.variation_id', 'psc.product_id', 'psc.location_id', 'psc.stock', 'psc.sku',
                'psc.product', 'psc.product_variation', 'psc.variation_name', 'psc.location_name',
                'psc.category_name', 'psc.product_custom_field1', 'psc.total_sold', 'psc.type',
                'psc.category_id', 'psc.sub_category_id', 'subcat.name as genre',
                'p.format as product_format', 'p.bin_position',
            ])
            ->limit(500)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $vid = (int) $row->variation_id;
            $lastSold = $lastSoldMap[$vid] ?? null;
            if ($lastSold && Carbon::parse($lastSold)->gt(Carbon::parse($minOosDate))) {
                // sold within the OOS window — not "long out of stock"
                continue;
            }

            $items[] = $this->rowToCandidate(
                $row,
                (float) $row->stock,
                $soldMap[$vid] ?? 0,
                (int) $cfg['target_stock'],
                [
                    'bucket' => 'long_oos_essentials',
                    'reason' => 'sold ' . (int) $soldMap[$vid] . ' in last ' . $cfg['lookback_days'] . 'd; OOS since ~' . ($lastSold ? substr($lastSold, 0, 10) : 'unknown'),
                ]
            );
        }

        usort($items, fn ($a, $b) => $b['sold_qty_window'] <=> $a['sold_qty_window']);

        return [
            'label' => '⚠️ Long out-of-stock essentials',
            'why' => 'Core titles: sold ' . $cfg['min_lifetime_sold'] . '+ in the last ' . $cfg['lookback_days'] . 'd, currently OOS for ' . $cfg['min_oos_days'] . '+ days.',
            'items' => $items,
            'count' => count($items),
        ];
    }

    // ── Hot used, currently out (watchlist, not reorderable) ──────

    /**
     * Used titles that have sold N+ copies in the last 90 days but we
     * have 0 on hand. Unlike sealed, you can't order a used copy from
     * AMS — these come from customer trade-ins / Discogs. The bucket
     * is advisory: "when a copy walks in, prioritize it".
     */
    protected function bucketHotUsedOos(int $business_id, int $locationId, $permittedLocations): array
    {
        $cfg = config('inventory_check.buckets.hot_used_oos', [
            'category_patterns' => ['Used Vinyl', 'Used CD'],
            'sale_days' => 90,
            'min_sold' => 3,
            'max_stock' => 0,
        ]);

        $catIds = [];
        foreach ((array) ($cfg['category_patterns'] ?? []) as $pattern) {
            foreach ($this->categoryIdsMatching($business_id, $pattern) as $id) {
                $catIds[] = $id;
            }
        }
        $catIds = array_values(array_unique($catIds));

        if (empty($catIds)) {
            return [
                'label' => 'Hot used, currently out',
                'why' => 'No categories matched "Used Vinyl" or "Used CD" — check your ERP category names in config/inventory_check.php.',
                'items' => [],
                'count' => 0,
                'empty_reason' => 'no_used_categories',
            ];
        }

        $saleDays = (int) ($cfg['sale_days'] ?? 90);
        $minSold = (float) ($cfg['min_sold'] ?? 2);
        $maxStock = (float) ($cfg['max_stock'] ?? 0);
        $saleStart = Carbon::now()->subDays($saleDays)->format('Y-m-d');
        $saleEnd = Carbon::now()->format('Y-m-d');

        // Aggregate sold qty by PRODUCT (title), not variation. Used
        // items are typically one variation per physical copy, so a
        // single title sells across many variations (different grades,
        // copies, etc). Summing at the product level is the right
        // semantic for "did we move 2+ copies of this album used?".
        $soldByProduct = $this->getSoldQtyByProduct($business_id, $locationId, $catIds, $saleStart, $saleEnd, $permittedLocations);
        if (empty($soldByProduct)) {
            return [
                'label' => 'Hot used, currently out',
                'why' => 'No used sales in the last ' . $saleDays . ' days at this location.',
                'items' => [],
                'count' => 0,
            ];
        }

        // Pull current stock aggregated by product for the same categories
        $stockByProduct = $this->getCurrentStockByProduct($business_id, $locationId, $catIds, $permittedLocations);
        $productMeta = $this->getProductMeta($business_id, array_keys($soldByProduct));

        $items = [];
        foreach ($soldByProduct as $productId => $soldWindow) {
            if ($soldWindow < $minSold) {
                continue;
            }
            $stock = (float) ($stockByProduct[$productId] ?? 0);
            if ($stock > $maxStock) {
                continue;
            }
            $meta = $productMeta[$productId] ?? null;
            if (!$meta) {
                continue;
            }
            $items[] = [
                'bucket' => 'hot_used_oos',
                'variation_id' => null,
                'product_id' => (int) $productId,
                'location_id' => $locationId,
                'sku' => $meta->sku ?? null,
                'product' => $meta->name ?? '—',
                'artist' => $meta->product_custom_field1 ?? '',
                'format' => $meta->format ?? null,
                'category_name' => $meta->category_name ?? null,
                'category_id' => $meta->category_id ?? null,
                'genre' => $meta->genre ?? null,
                'bin_position' => $meta->bin_position ?? null,
                'is_rsd' => $this->isRsdTitle((string) ($meta->name ?? '')),
                'location_name' => null,
                'stock' => $stock,
                'sold_qty_window' => round($soldWindow, 2),
                'suggested_qty' => 0,
                'reason' => 'sold ' . (int) $soldWindow . ' used in last ' . $saleDays . 'd; none in stock',
                'tags' => ['used', 'watchlist'],
            ];
        }

        usort($items, fn ($a, $b) => $b['sold_qty_window'] <=> $a['sold_qty_window']);

        return [
            'label' => 'Hot used, currently out',
            'why' => 'Used titles that sold ' . (int) $minSold . '+ copies in the last ' . $saleDays . 'd but are now gone. Watch for these on customer trade-ins and Discogs — no AMS order needed.',
            'items' => $items,
            'count' => count($items),
            'advisory_only' => true,
        ];
    }

    // ── Customer wants ────────────────────────────────────────────────

    protected function bucketCustomerWants(int $business_id, int $locationId): array
    {
        $wants = CustomerWant::where('business_id', $business_id)
            ->where('status', 'active')
            ->where(function ($q) use ($locationId) {
                $q->where('location_id', $locationId)->orWhereNull('location_id');
            })
            ->orderByDesc('priority')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $items = [];
        foreach ($wants as $w) {
            $match = $this->tryMatchChartPickToVariation($business_id, $w->artist, $w->title);
            $items[] = [
                'bucket' => 'customer_wants',
                'customer_want_id' => $w->id,
                'artist' => $w->artist,
                'product' => $w->title,
                'format' => $w->format,
                'priority' => $w->priority,
                'notes' => $w->notes,
                'variation_id' => $match['variation_id'] ?? null,
                'product_id' => $match['product_id'] ?? null,
                'sku' => $match['sku'] ?? null,
                'stock' => $match['stock'] ?? null,
                'suggested_qty' => $w->priority === 'high' ? 2 : 1,
                'reason' => 'customer request' . ($w->priority === 'high' ? ' (high priority)' : ''),
                'tags' => ['customer_request', 'priority_' . $w->priority],
            ];
        }

        return [
            'label' => '💚 Customer wants',
            'why' => 'Active "call-me-when-it-comes-in" requests from customers.',
            'items' => $items,
            'count' => count($items),
        ];
    }

    // ── Top artists (for cross-referencing chart data) ────────────────

    /** @return array<int,string> artist names */
    public function getTopArtists(int $business_id, int $locationId, $permittedLocations): array
    {
        $cfg = config('inventory_check.buckets.top_artists', ['lookback_days' => 90, 'top_n' => 50]);
        $saleStart = Carbon::now()->subDays((int) $cfg['lookback_days'])->format('Y-m-d');
        $saleEnd = Carbon::now()->format('Y-m-d');

        $q = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('variations as v', 'v.id', '=', 'tsl.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.location_id', $locationId)
            ->whereBetween(DB::raw('DATE(t.transaction_date)'), [$saleStart, $saleEnd])
            ->whereNotNull('p.product_custom_field1')
            ->where('p.product_custom_field1', '!=', '')
            ->groupBy('p.product_custom_field1')
            ->orderByRaw('SUM(tsl.quantity - tsl.quantity_returned) DESC')
            ->limit((int) $cfg['top_n'])
            ->select('p.product_custom_field1 as artist');

        if ($permittedLocations !== 'all') {
            $q->whereIn('t.location_id', $permittedLocations);
        }

        $dataDriven = $q->pluck('artist')->filter()->map(fn ($a) => trim($a))->all();

        // Overlay Sarah's must-have display lists for the matching location.
        // These guarantee chart picks for store-wall artists tag as
        // "popular in-store" even during a slow month.
        $mustHave = $this->getMustHaveArtistsForLocation($locationId);

        $merged = array_merge($dataDriven, $mustHave);
        return collect($merged)
            ->filter()
            ->map(fn ($a) => trim($a))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    protected function getMustHaveArtistsForLocation(int $locationId): array
    {
        $byLocation = config('inventory_check.must_have_artists_by_location', []);
        if (empty($byLocation)) {
            return [];
        }

        $loc = BusinessLocation::find($locationId);
        if (!$loc) {
            return [];
        }
        $name = mb_strtolower((string) $loc->name);

        foreach ($byLocation as $pattern => $artists) {
            // mb_strpos for PHP 7.x compat — str_contains is PHP 8.0+ and
            // this Laravel pairs with older PHP on the prod server.
            if ($pattern !== '' && mb_strpos($name, mb_strtolower($pattern)) !== false) {
                return is_array($artists) ? $artists : [];
            }
        }
        return [];
    }

    // ── Shared helpers (sold qty, sell speed, category lookup, row mapper) ───

    public function getSoldQtyByVariation(int $business_id, int $locationId, string $saleStart, string $saleEnd, $permittedLocations): array
    {
        $q = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.location_id', $locationId)
            ->whereBetween(DB::raw('DATE(t.transaction_date)'), [$saleStart, $saleEnd])
            ->groupBy('tsl.variation_id')
            ->select(
                'tsl.variation_id',
                DB::raw('SUM(tsl.quantity - tsl.quantity_returned) as sold_qty')
            );

        if ($permittedLocations !== 'all') {
            $q->whereIn('t.location_id', $permittedLocations);
        }

        $rows = $q->get();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->variation_id] = (float) $row->sold_qty;
        }

        return $map;
    }

    /**
     * Aggregate sold qty by product (across all variations) for a
     * specific set of categories at a location/window. Used by the
     * "Hot used OOS" bucket where each physical copy is its own
     * variation but we care about title-level movement.
     *
     * @return array<int,float> product_id => qty sold in window
     */
    public function getSoldQtyByProduct(int $business_id, int $locationId, array $categoryIds, string $saleStart, string $saleEnd, $permittedLocations): array
    {
        if (empty($categoryIds)) {
            return [];
        }
        $q = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('variations as v', 'v.id', '=', 'tsl.variation_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.location_id', $locationId)
            ->whereIn('p.category_id', $categoryIds)
            ->whereBetween(DB::raw('DATE(t.transaction_date)'), [$saleStart, $saleEnd])
            ->groupBy('p.id')
            ->select('p.id as product_id', DB::raw('SUM(tsl.quantity - tsl.quantity_returned) as sold_qty'));

        if ($permittedLocations !== 'all') {
            $q->whereIn('t.location_id', $permittedLocations);
        }

        $out = [];
        foreach ($q->get() as $row) {
            $out[(int) $row->product_id] = (float) $row->sold_qty;
        }
        return $out;
    }

    /**
     * Current on-hand stock aggregated by product (across variations)
     * at a single location for a set of categories.
     *
     * @return array<int,float> product_id => current stock
     */
    public function getCurrentStockByProduct(int $business_id, int $locationId, array $categoryIds, $permittedLocations): array
    {
        if (empty($categoryIds)) {
            return [];
        }
        $q = DB::table('product_stock_cache as psc')
            ->where('psc.business_id', $business_id)
            ->where('psc.location_id', $locationId)
            ->whereIn('psc.category_id', $categoryIds)
            ->groupBy('psc.product_id')
            ->select('psc.product_id', DB::raw('SUM(psc.stock) as stock'));

        if ($permittedLocations !== 'all') {
            $q->whereIn('psc.location_id', $permittedLocations);
        }

        $out = [];
        foreach ($q->get() as $row) {
            $out[(int) $row->product_id] = (float) $row->stock;
        }
        return $out;
    }

    /**
     * Fetch display metadata (name, sku, artist, format, category) for
     * a list of product IDs. Used to dress up Hot Used rows for the UI.
     */
    public function getProductMeta(int $business_id, array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        $rows = DB::table('products as p')
            ->leftJoin('variations as v', function ($j) {
                $j->on('v.product_id', '=', 'p.id')->where('v.deleted_at', null);
            })
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('categories as subcat', 'subcat.id', '=', 'p.sub_category_id')
            ->where('p.business_id', $business_id)
            ->whereIn('p.id', $productIds)
            ->groupBy('p.id')
            ->select([
                'p.id', 'p.name', 'p.format', 'p.product_custom_field1',
                'p.category_id', 'c.name as category_name',
                'p.sub_category_id', 'subcat.name as genre',
                'p.bin_position',
                DB::raw('MIN(v.sub_sku) as sku'),
            ])
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->id] = $r;
        }
        return $out;
    }

    public function getAvgSellDaysByVariation(
        int $business_id,
        int $locationId,
        string $saleStart,
        string $saleEnd,
        ?int $supplierId,
        bool $excludeZeroDay,
        $permittedLocations,
        ?array $variationIds = null
    ): array {
        // If caller passes variationIds, scope the join to only those.
        // bucketFastOos was the slowest call on the page because this
        // query joined the full 90-day sell-lines × purchase-lines set
        // (millions of row pairs) and built avg_sell_days for variations
        // that weren't even in the candidate PSC list. Scoping with
        // whereIn drops the work by an order of magnitude (Sarah hit a
        // 30s+ "Loading…" 2026-05-20).
        if ($variationIds !== null && empty($variationIds)) {
            return [];
        }

        $q = DB::table('transaction_sell_lines_purchase_lines as tslp')
            ->join('purchase_lines as pl', 'pl.id', '=', 'tslp.purchase_line_id')
            ->join('transactions as purchase', 'purchase.id', '=', 'pl.transaction_id')
            ->leftJoin('transaction_sell_lines as sl', 'sl.id', '=', 'tslp.sell_line_id')
            ->leftJoin('transactions as sale', 'sale.id', '=', 'sl.transaction_id')
            ->where('purchase.business_id', $business_id)
            ->where('purchase.location_id', $locationId)
            ->whereNotNull('purchase.transaction_date')
            ->whereNotNull('sale.transaction_date')
            ->whereBetween(DB::raw('DATE(sale.transaction_date)'), [$saleStart, $saleEnd]);

        if ($variationIds !== null) {
            $q->whereIn('pl.variation_id', $variationIds);
        }
        if ($supplierId) {
            $q->where('purchase.contact_id', $supplierId);
        }
        if ($permittedLocations !== 'all') {
            $q->whereIn('purchase.location_id', $permittedLocations);
        }

        $rows = $q->select(
            'pl.variation_id',
            DB::raw('DATEDIFF(sale.transaction_date, purchase.transaction_date) as sell_days')
        )->get();

        $sums = [];
        foreach ($rows as $row) {
            $days = max(0.0, (float) $row->sell_days);
            if ($excludeZeroDay && $days <= 0) {
                continue;
            }
            $vid = (int) $row->variation_id;
            if (!isset($sums[$vid])) {
                $sums[$vid] = ['sum' => 0.0, 'count' => 0];
            }
            $sums[$vid]['sum'] += $days;
            $sums[$vid]['count']++;
        }

        $out = [];
        foreach ($sums as $vid => $agg) {
            if ($agg['count'] > 0) {
                $out[$vid] = [
                    'avg_days' => $agg['sum'] / $agg['count'],
                    'count' => $agg['count'],
                ];
            }
        }

        return $out;
    }

    protected function categoryIdsMatching(int $business_id, string $pattern): array
    {
        if ($pattern === '') {
            return [];
        }
        return Category::where('business_id', $business_id)
            ->where('category_type', 'product')
            ->where('name', 'like', '%' . $pattern . '%')
            ->pluck('id')
            ->all();
    }

    protected function queryPscRows(int $business_id, int $locationId, array $categoryIds, $permittedLocations)
    {
        // Sarah 2026-05-20: pull sub-category as `genre` so the buckets
        // can be filtered by genre. PSC has sub_category_id but no name —
        // LEFT JOIN categories to get the label.
        $q = DB::table('product_stock_cache as psc')
            ->leftJoin('products as p', 'p.id', '=', 'psc.product_id')
            ->leftJoin('categories as subcat', 'subcat.id', '=', 'psc.sub_category_id')
            ->leftJoin('variations as v', 'v.id', '=', 'psc.variation_id')
            ->where('psc.business_id', $business_id)
            ->where('psc.location_id', $locationId);

        if ($permittedLocations !== 'all') {
            $q->whereIn('psc.location_id', $permittedLocations);
        }
        if (!empty($categoryIds)) {
            $q->whereIn('psc.category_id', $categoryIds);
        }

        return $q->select([
            'psc.variation_id', 'psc.product_id', 'psc.location_id', 'psc.stock', 'psc.sku',
            'psc.product', 'psc.type', 'psc.product_variation', 'psc.variation_name',
            'psc.location_name', 'psc.category_name', 'psc.category_id',
            'psc.sub_category_id', 'subcat.name as genre',
            'psc.product_custom_field1', 'psc.total_sold',
            'p.format as product_format',
            'p.bin_position',
            'v.default_purchase_price as cost_price',
        ])
            ->orderByDesc('psc.total_sold')
            ->limit((int) config('inventory_check.max_candidate_rows', 2000))
            ->get();
    }

    protected function rowToCandidate($row, float $stock, float $soldWindow, int $targetStock, array $extra = []): array
    {
        $maxLine = (int) config('inventory_check.max_order_line_qty', 25);
        $suggested = max(0, $targetStock - (int) $stock);
        $suggested = min($maxLine, $suggested);
        if ($suggested < 1 && $soldWindow > 0) {
            $suggested = 1;
        }

        $artist = $row->product_custom_field1 ?? '';

        return array_merge([
            'variation_id' => (int) $row->variation_id,
            'product_id' => (int) $row->product_id,
            'location_id' => (int) $row->location_id,
            'sku' => $row->sku,
            'product' => $row->product,
            'artist' => $artist,
            'format' => $row->product_format ?? null,
            'category_name' => $row->category_name,
            'category_id' => $row->category_id ?? null,
            'genre' => $row->genre ?? null,
            'sub_category_id' => $row->sub_category_id ?? null,
            'location_name' => $row->location_name,
            'bin_position' => $row->bin_position ?? null,
            'cost_price' => isset($row->cost_price) ? (float) $row->cost_price : null,
            'is_rsd' => $this->isRsdTitle($row->product ?? ''),
            'stock' => $stock,
            'sold_qty_window' => round($soldWindow, 2),
            'suggested_qty' => (int) $suggested,
            'variation_label' => ($row->type ?? '') === 'variable'
                ? trim(($row->product_variation ?? '') . ' — ' . ($row->variation_name ?? ''), ' —')
                : '',
            'tags' => [],
        ], $extra);
    }

    /**
     * Detect Record Store Day titles by name. No structured RSD flag
     * exists in the schema (Sarah 2026-05-20) so we look for the
     * common markers cashiers + AMS put in the title.
     */
    protected function isRsdTitle(string $name): bool
    {
        if ($name === '') return false;
        $lower = mb_strtolower($name);
        if (mb_strpos($lower, 'rsd') !== false) return true;
        if (mb_strpos($lower, 'record store day') !== false) return true;
        if (mb_strpos($lower, 'black friday rsd') !== false) return true;
        return false;
    }

    protected function dedupeByVariation(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $it) {
            $key = (int) ($it['variation_id'] ?? 0);
            if ($key === 0) {
                $out[] = $it;
                continue;
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $it;
        }
        return $out;
    }
}
