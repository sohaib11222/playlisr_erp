<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Look up historical sales of a Discogs release (or its artist/title) inside
 * the local transactions database, grouped by sales channel — in-store at
 * each business_location (Pico, Hollywood, ...) vs. Discogs online vs.
 * Whatnot / eBay.
 *
 * Used by:
 *   - the Bulk Discogs IDs flow on /product/mass-create (per-row preview), and
 *   - the `discogs:lookup` artisan command (ad-hoc CLI lookup).
 */
class DiscogsSalesLookupService
{
    /**
     * @param  int|null $releaseId  Discogs release id (exact-match path on products.discogs_release_id).
     * @param  string|null $artist  Artist name from Discogs (fuzzy LIKE %artist%).
     * @param  string|null $title   Release title from Discogs (fuzzy LIKE %title%).
     * @param  int $businessId      Scope all queries to this business.
     * @param  bool $allStatus      If true, include non-final transactions too.
     * @param  string $mode         Which match conditions to apply:
     *                              - 'any'     → any of release_id / artist / title (legacy OR, broadest)
     *                              - 'artist'  → artist fields only
     *                              - 'title'   → title fields only
     *                              - 'release' → exact products.discogs_release_id only
     */
    public function lookup(
        ?int $releaseId,
        ?string $artist,
        ?string $title,
        int $businessId,
        bool $allStatus = false,
        string $mode = 'any'
    ): array {
        $artist = trim((string) $artist);
        $title  = trim((string) $title);

        if (!$releaseId && $artist === '' && $title === '') {
            return $this->emptyResult();
        }

        $hasChannel = Schema::hasColumn('transactions', 'channel');

        $likeArtist = $artist !== '' ? '%' . $this->escLike($artist) . '%' : null;
        $likeTitle  = $title  !== '' ? '%' . $this->escLike($title)  . '%' : null;

        // Decide which condition groups to apply based on mode.
        $useRelease = $releaseId && in_array($mode, ['any', 'release'], true);
        $useArtist  = $likeArtist !== null && in_array($mode, ['any', 'artist'], true);
        $useTitle   = $likeTitle  !== null && in_array($mode, ['any', 'title'],  true);

        $hasSource = Schema::hasColumn('transactions', 'source');

        $select = [
            't.id as transaction_id',
            't.transaction_date',
            't.status',
            't.location_id',
            'bl.name as location_name',
            'tsl.quantity',
            'tsl.unit_price_inc_tax',
            'p.id as product_id',
            'p.artist as product_artist',
            'p.name as product_name',
            'tsl.legacy_artist',
            'tsl.legacy_title',
        ];
        if ($hasChannel) {
            $select[] = 't.channel';
        }
        if ($hasSource) {
            $select[] = 't.source';
        }

        $q = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->leftJoin('products as p', 'p.id', '=', 'tsl.product_id')
            ->leftJoin('business_locations as bl', 'bl.id', '=', 't.location_id')
            ->where('t.business_id', $businessId)
            ->where('t.type', 'sell');

        if (!$allStatus) {
            $q->where('t.status', 'final');
        }

        $q->where(function ($w) use ($releaseId, $likeArtist, $likeTitle, $useRelease, $useArtist, $useTitle) {
            $any = false;
            if ($useRelease) {
                $w->orWhere('p.discogs_release_id', $releaseId);
                $any = true;
            }
            if ($useArtist) {
                $w->orWhere('p.artist',          'like', $likeArtist)
                  ->orWhere('p.name',            'like', $likeArtist)
                  ->orWhere('tsl.legacy_artist', 'like', $likeArtist);
                $any = true;
            }
            if ($useTitle) {
                $w->orWhere('p.name',          'like', $likeTitle)
                  ->orWhere('tsl.legacy_title', 'like', $likeTitle);
                $any = true;
            }
            // Guard: if somehow no criteria, force a no-match.
            if (!$any) {
                $w->whereRaw('1 = 0');
            }
        });

        $rows = $q->select($select)
            ->orderByDesc('t.transaction_date')
            ->get();

        // Guarantee every row has a `channel` attribute even when the column
        // doesn't exist yet (older installs pre-2026_04_22 migration).
        if (!$hasChannel) {
            foreach ($rows as $r) {
                $r->channel = 'in_store';
            }
        }

        if ($rows->isEmpty()) {
            return $this->emptyResult();
        }

        $totalLines = 0;
        $totalQty = 0;
        $totalRevenue = 0.0;
        $firstSold = null;
        $lastSold  = null;
        $buckets = [];

        foreach ($rows as $r) {
            // Effective channel: legacy Discogs sales (pre-2026-04-22 migration)
            // have channel='in_store' (the default backfill) but source='discogs',
            // so promote those into the Discogs bucket. Doesn't double-count —
            // post-migration rows already carry channel='discogs'.
            $rawChannel = $hasChannel ? ($r->channel ?: 'in_store') : 'in_store';
            $isDiscogsBySource = isset($r->source) && $r->source === 'discogs';
            $channel = ($isDiscogsBySource || $rawChannel === 'discogs') ? 'discogs' : $rawChannel;
            $r->channel = $channel; // canonicalize for downstream consumers
            $bucket  = $this->bucketKey($channel, $r);

            if (!isset($buckets[$bucket])) {
                $buckets[$bucket] = [
                    'key'     => $bucket,
                    'channel' => $channel,
                    'label'   => $this->bucketLabel($channel, $r),
                    'location_id' => $channel === 'in_store' ? (int) $r->location_id : null,
                    'lines'   => 0,
                    'qty'     => 0,
                    'revenue' => 0.0,
                    'first'   => null,
                    'last'    => null,
                ];
            }

            $qty = (int) $r->quantity;
            $rev = (float) $r->unit_price_inc_tax * $qty;
            $d   = (string) $r->transaction_date;

            $buckets[$bucket]['lines']++;
            $buckets[$bucket]['qty']     += $qty;
            $buckets[$bucket]['revenue'] += $rev;
            if (!$buckets[$bucket]['first'] || $d < $buckets[$bucket]['first']) {
                $buckets[$bucket]['first'] = $d;
            }
            if (!$buckets[$bucket]['last']  || $d > $buckets[$bucket]['last']) {
                $buckets[$bucket]['last']  = $d;
            }

            $totalLines++;
            $totalQty     += $qty;
            $totalRevenue += $rev;
            if (!$firstSold || $d < $firstSold) $firstSold = $d;
            if (!$lastSold  || $d > $lastSold)  $lastSold  = $d;
        }

        // Stable sort: in-store locations first (alpha), then online channels.
        uasort($buckets, function ($a, $b) {
            $aIs = $a['channel'] === 'in_store' ? 0 : 1;
            $bIs = $b['channel'] === 'in_store' ? 0 : 1;
            if ($aIs !== $bIs) return $aIs <=> $bIs;
            return strcmp($a['label'], $b['label']);
        });

        return [
            'total_lines'    => $totalLines,
            'total_qty'      => $totalQty,
            'total_revenue'  => round($totalRevenue, 2),
            'first_sold'     => $firstSold,
            'last_sold'      => $lastSold,
            'by_channel'     => array_values($buckets),
            'rows'           => $rows->all(),
        ];
    }

    /**
     * Current unsold stock per business_location for products matching the
     * given artist / title / release. Reads the denormalized
     * `product_stock_cache` table so a stale cache is acceptable — the cache
     * is refreshed periodically by the RefreshProductStockCache command.
     *
     * @return array{
     *   total_qty: float,
     *   by_location: array<int, array{location_id: int, location_name: string, qty: float, lines: int}>
     * }
     */
    public function stockOnHand(
        ?int $releaseId,
        ?string $artist,
        ?string $title,
        int $businessId,
        string $mode = 'any'
    ): array {
        $artist = trim((string) $artist);
        $title  = trim((string) $title);

        if (!$releaseId && $artist === '' && $title === '') {
            return ['total_qty' => 0.0, 'by_location' => []];
        }

        $likeArtist = $artist !== '' ? '%' . $this->escLike($artist) . '%' : null;
        $likeTitle  = $title  !== '' ? '%' . $this->escLike($title)  . '%' : null;

        $useRelease = $releaseId && in_array($mode, ['any', 'release'], true);
        $useArtist  = $likeArtist !== null && in_array($mode, ['any', 'artist'], true);
        $useTitle   = $likeTitle  !== null && in_array($mode, ['any', 'title'],  true);

        $q = DB::table('product_stock_cache as psc')
            ->leftJoin('products as p', 'p.id', '=', 'psc.product_id')
            ->where('psc.business_id', $businessId)
            // Positive stock only — negative usually means oversold/data drift.
            ->where('psc.stock', '>', 0)
            // "For sale" means sellable stock, not just on-hand: exclude
            // deactivated products and ones flagged not-for-selling, so the
            // count matches what a cashier could actually ring up.
            ->where('psc.is_inactive', 0)
            ->where('psc.not_for_selling', 0);

        $q->where(function ($w) use ($releaseId, $likeArtist, $likeTitle, $useRelease, $useArtist, $useTitle) {
            $any = false;
            if ($useRelease) {
                $w->orWhere('p.discogs_release_id', $releaseId);
                $any = true;
            }
            if ($useArtist) {
                $w->orWhere('p.artist', 'like', $likeArtist)
                  ->orWhere('p.name',   'like', $likeArtist);
                $any = true;
            }
            if ($useTitle) {
                $w->orWhere('p.name', 'like', $likeTitle);
                $any = true;
            }
            if (!$any) {
                $w->whereRaw('1 = 0');
            }
        });

        $rows = $q->select([
                'psc.location_id',
                'psc.location_name',
                DB::raw('SUM(psc.stock) as qty'),
                DB::raw('COUNT(*) as lines'),
            ])
            ->groupBy('psc.location_id', 'psc.location_name')
            ->orderBy('psc.location_name')
            ->get();

        $total = 0.0;
        $byLocation = [];
        foreach ($rows as $r) {
            $qty = (float) $r->qty;
            if ($qty <= 0) continue;
            $byLocation[] = [
                'location_id'   => (int) $r->location_id,
                'location_name' => (string) ($r->location_name ?: 'Unknown'),
                'qty'           => $qty,
                'lines'         => (int) $r->lines,
            ];
            $total += $qty;
        }

        // Sort: physical stores first (alpha), then online/virtual warehouses
        // (Discogs Warehouse, eBay Warehouse, …) so the shop-floor counts read
        // before the marketplace counts.
        usort($byLocation, function ($a, $b) {
            $aOnline = $this->isOnlineLocationName($a['location_name']) ? 1 : 0;
            $bOnline = $this->isOnlineLocationName($b['location_name']) ? 1 : 0;
            if ($aOnline !== $bOnline) return $aOnline <=> $bOnline;
            return strcmp($a['location_name'], $b['location_name']);
        });

        return ['total_qty' => $total, 'by_location' => $byLocation];
    }

    private function isOnlineLocationName(string $name): bool
    {
        return (bool) preg_match('/\b(discogs|ebay|whatnot|reverb|shopify|online|warehouse)\b/i', $name);
    }

    /**
     * Convenience: stock split into artist / title lenses, matching the
     * structure of lookupSplit().
     *
     * @return array{by_artist: ?array, by_title: ?array}
     */
    public function stockSplit(
        ?int $releaseId,
        ?string $artist,
        ?string $title,
        int $businessId
    ): array {
        $artistTrim = trim((string) $artist);
        $titleTrim  = trim((string) $title);

        return [
            'by_artist' => $artistTrim !== ''
                ? $this->stockOnHand(null, $artistTrim, null, $businessId, 'artist')
                : null,
            'by_title'  => $titleTrim !== ''
                ? $this->stockOnHand(null, null, $titleTrim, $businessId, 'title')
                : null,
        ];
    }

    /**
     * Run two separate lookups so the caller can show artist-level vs.
     * title-level counts as distinct lenses ("all by artist" ⊇ "this title").
     * Each entry is null when the corresponding input is empty / missing.
     *
     * @return array{by_artist: ?array, by_title: ?array}
     */
    public function lookupSplit(
        ?int $releaseId,
        ?string $artist,
        ?string $title,
        int $businessId,
        bool $allStatus = false
    ): array {
        $artistTrim = trim((string) $artist);
        $titleTrim  = trim((string) $title);

        return [
            'by_artist' => $artistTrim !== ''
                ? $this->lookup(null, $artistTrim, null, $businessId, $allStatus, 'artist')
                : null,
            'by_title'  => $titleTrim !== ''
                ? $this->lookup(null, null, $titleTrim, $businessId, $allStatus, 'title')
                : null,
        ];
    }

    /**
     * Build a one-line summary string suitable for inline display in the UI.
     * Example: "Sold before: Pico ×3 ($45.00), Hollywood ×2 ($30.00), Discogs ×1 ($18.00) — last 2025-11-04"
     */
    public function summarize(array $result): string
    {
        if (($result['total_lines'] ?? 0) === 0) {
            return 'No prior sales found for this artist/title.';
        }
        $parts = [];
        foreach ($result['by_channel'] as $b) {
            $parts[] = sprintf('%s ×%d ($%s)',
                $b['label'],
                $b['qty'],
                number_format($b['revenue'], 2)
            );
        }
        return sprintf('Sold before: %s — last %s',
            implode(', ', $parts),
            $result['last_sold'] ?? '—'
        );
    }

    private function emptyResult(): array
    {
        return [
            'total_lines'   => 0,
            'total_qty'     => 0,
            'total_revenue' => 0.0,
            'first_sold'    => null,
            'last_sold'     => null,
            'by_channel'    => [],
            'rows'          => [],
        ];
    }

    private function bucketKey(string $channel, $row): string
    {
        if ($channel === 'in_store') {
            return 'in_store:' . (int) $row->location_id;
        }
        return $channel;
    }

    private function bucketLabel(string $channel, $row): string
    {
        if ($channel === 'discogs') return 'Discogs';
        if ($channel === 'whatnot') return 'Whatnot';
        if ($channel === 'ebay')    return 'eBay';
        return $row->location_name ?: 'Unknown location';
    }

    private function escLike(string $s): string
    {
        return addcslashes($s, '%_\\');
    }
}
