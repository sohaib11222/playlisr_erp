<?php

namespace App\Services;

use App\BusinessLocation;
use App\Category;
use App\ChartPick;
use App\Contact;
use App\CustomerWant;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
    public function buildBuckets(int $business_id, array $input, $permittedLocations): array
    {
        $locationId = !empty($input['location_id']) ? (int) $input['location_id'] : null;
        if (!$locationId) {
            return ['buckets' => [], 'meta' => ['error' => 'location_required']];
        }

        $categoryIds = $this->resolveCategoryIds($input);
        $saleStart = $input['sale_start'] ?? Carbon::now()->subDays(90)->format('Y-m-d');
        $saleEnd = $input['sale_end'] ?? Carbon::now()->format('Y-m-d');

        $topArtists = $this->getTopArtists($business_id, $locationId, $permittedLocations);

        $buckets = [
            'fast_oos' => $this->bucketFastOos($business_id, $locationId, $permittedLocations),
            'street_pulse' => $this->bucketChartPicks($business_id, $locationId, 'street_pulse', $topArtists, $permittedLocations),
            'universal_top' => $this->bucketChartPicks($business_id, $locationId, 'universal_top', $topArtists, $permittedLocations),
            'top_artist_new_releases' => $this->bucketTopArtistNewReleases($business_id, $locationId, $topArtists, $permittedLocations),
            'events_upcoming' => $this->bucketEventsUpcoming($business_id, $locationId, $permittedLocations),
            'long_oos_essentials' => $this->bucketLongOosEssentials($business_id, $locationId, $permittedLocations),
            'customer_wants' => $this->bucketCustomerWants($business_id, $locationId),
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
                'top_artists' => $topArtists,
                'generated_at' => Carbon::now()->toIso8601String(),
            ],
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
            $sold = $this->getSoldQtyByVariation($business_id, $locationId, $saleStart, $saleEnd, $permittedLocations);

            $rows = $this->queryPscRows($business_id, $locationId, $catIds, $permittedLocations);
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

        // Fast sellers (any category) — avg sell days ≤ threshold
        $rules = $cfg['fast_seller'] ?? null;
        if ($rules) {
            $saleStart = Carbon::now()->subDays((int) ($rules['sale_days'] ?? 90))->format('Y-m-d');
            $saleEnd = Carbon::now()->format('Y-m-d');
            $fast = $this->getAvgSellDaysByVariation($business_id, $locationId, $saleStart, $saleEnd, null, true, $permittedLocations);

            if (!empty($fast)) {
                $rows = $this->queryPscRows($business_id, $locationId, [], $permittedLocations);
                foreach ($rows as $row) {
                    $vid = (int) $row->variation_id;
                    if (!isset($fast[$vid])) {
                        continue;
                    }
                    $stock = (float) ($row->stock ?? 0);
                    if ($stock > ($rules['max_stock'] ?? 2)) {
                        continue;
                    }
                    if ($fast[$vid]['avg_days'] > ($rules['max_avg_sell_days'] ?? 21)) {
                        continue;
                    }

                    $items[] = $this->rowToCandidate($row, $stock, 0, $rules['target_stock'] ?? 3, [
                        'bucket' => 'fast_oos',
                        'reason' => 'avg sell speed ' . round($fast[$vid]['avg_days'], 1) . 'd',
                    ]);
                }
            }
        }

        $items = $this->dedupeByVariation($items);
        usort($items, fn ($a, $b) => $b['sold_qty_window'] <=> $a['sold_qty_window']);

        return [
            'label' => '🔥 Fast-moving, out of stock',
            'why' => 'Sold fast in the last 60-90 days; we have zero or near-zero on shelf.',
            'items' => $items,
            'count' => count($items),
        ];
    }

    // ── Chart picks (Street Pulse / Universal Top) ────────────────────

    protected function bucketChartPicks(int $business_id, int $locationId, string $source, array $topArtists, $permittedLocations): array
    {
        $week = ChartPick::where('business_id', $business_id)
            ->where('source', $source)
            ->max('week_of');

        if (!$week) {
            return [
                'label' => $source === 'street_pulse' ? '📬 Street Pulse picks' : '🌍 Universal top',
                'why' => 'Paste this week\'s email to populate.',
                'items' => [],
                'count' => 0,
                'empty_reason' => 'not_imported',
            ];
        }

        $picks = ChartPick::where('business_id', $business_id)
            ->where('source', $source)
            ->whereDate('week_of', $week)
            ->orderBy('chart_rank')
            ->limit(500)
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
            'label' => $source === 'street_pulse' ? '📬 Street Pulse picks' : '🌍 Universal top',
            'why' => 'From this week\'s ' . ($source === 'street_pulse' ? 'Street Pulse' : 'Universal top') . ' chart (imported ' . $week . '). Rows tagged "top_artist" are artists already popular in-store.',
            'items' => $items,
            'count' => count($items),
            'week_of' => (string) $week,
        ];
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

    protected function chartPickReason($pick, bool $isTopArtist, array $match): string
    {
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
            ->where('psc.business_id', $business_id)
            ->where('psc.product', 'like', '%' . $title . '%')
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
        ];
    }

    // ── New releases from top artists (cross-reference) ───────────────

    protected function bucketTopArtistNewReleases(int $business_id, int $locationId, array $topArtists, $permittedLocations): array
    {
        $latestWeeks = ChartPick::where('business_id', $business_id)
            ->selectRaw('source, MAX(week_of) as w')
            ->groupBy('source')
            ->pluck('w', 'source');

        if ($latestWeeks->isEmpty() || empty($topArtists)) {
            return [
                'label' => '🎵 New releases from your top artists',
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
            'label' => '🎵 New releases from your top artists',
            'why' => 'Artists popular in-store who have a new release (or a title we don\'t yet carry) on this week\'s charts.',
            'items' => $deduped,
            'count' => count($deduped),
        ];
    }

    // ── Upcoming events → stock-up ────────────────────────────────────

    protected function bucketEventsUpcoming(int $business_id, int $locationId, $permittedLocations): array
    {
        $lookahead = (int) config('inventory_check.events_lookahead_days', 30);
        $events = $this->eventsFetcher->upcoming($lookahead);

        if (empty($events)) {
            return [
                'label' => '🎤 Upcoming events — stock up',
                'why' => 'Pulled from nivessa.com/events. Set NIVESSA_EVENTS_API_URL in .env to enable.',
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
                        'suggested_qty' => max(1, 3 - (int) $stock),
                        'reason' => 'event ' . $event['name'] . ' on ' . $event['date'],
                        'tags' => ['event'],
                    ];
                }
            }
        }

        return [
            'label' => '🎤 Upcoming events — stock up',
            'why' => 'Artists performing at listening parties & local events in the next ' . $lookahead . ' days.',
            'items' => $items,
            'count' => count($items),
            'events_loaded' => count($events),
        ];
    }

    protected function findProductsByArtist(int $business_id, string $artist, int $limit = 5)
    {
        if (trim($artist) === '') {
            return collect([]);
        }
        return DB::table('product_stock_cache as psc')
            ->leftJoin('products as p', 'p.id', '=', 'psc.product_id')
            ->where('psc.business_id', $business_id)
            ->where(function ($q) use ($artist) {
                $q->where('psc.product_custom_field1', 'like', '%' . $artist . '%')
                    ->orWhere('psc.product', 'like', '%' . $artist . '%');
            })
            ->select([
                'psc.variation_id', 'psc.product_id', 'psc.stock', 'psc.sku', 'psc.product',
                'psc.location_name', 'psc.category_name', 'psc.total_sold',
                'p.format as product_format',
            ])
            ->orderByDesc('psc.total_sold')
            ->limit($limit)
            ->get();
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
            ->where('psc.business_id', $business_id)
            ->where('psc.location_id', $locationId)
            ->whereIn('psc.variation_id', $variationIds)
            ->where('psc.stock', '<=', 0)
            ->select([
                'psc.variation_id', 'psc.product_id', 'psc.location_id', 'psc.stock', 'psc.sku',
                'psc.product', 'psc.product_variation', 'psc.variation_name', 'psc.location_name',
                'psc.category_name', 'psc.product_custom_field1', 'psc.total_sold', 'psc.type',
                'psc.category_id',
                'p.format as product_format',
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

        return $q->pluck('artist')->filter()->map(fn ($a) => trim($a))->unique()->values()->all();
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

    public function getAvgSellDaysByVariation(
        int $business_id,
        int $locationId,
        string $saleStart,
        string $saleEnd,
        ?int $supplierId,
        bool $excludeZeroDay,
        $permittedLocations
    ): array {
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
        $q = DB::table('product_stock_cache as psc')
            ->leftJoin('products as p', 'p.id', '=', 'psc.product_id')
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
            'psc.product_custom_field1', 'psc.total_sold',
            'p.format as product_format',
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
            'location_name' => $row->location_name,
            'stock' => $stock,
            'sold_qty_window' => round($soldWindow, 2),
            'suggested_qty' => (int) $suggested,
            'variation_label' => ($row->type ?? '') === 'variable'
                ? trim(($row->product_variation ?? '') . ' — ' . ($row->variation_name ?? ''), ' —')
                : '',
            'tags' => [],
        ], $extra);
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
