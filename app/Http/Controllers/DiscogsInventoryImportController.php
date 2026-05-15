<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Services\DiscogsReleaseImportMapper;
use App\Services\DiscogsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sarah 2026-05-15 — Discogs Inventory bulk import.
 *
 * Pull the seller's "For Sale" listings from Discogs and create one ERP
 * product per listing in a dedicated business location (default name:
 * "Discogs Warehouse"). The Discogs `location` string (Sarah's bin number)
 * is stored in products.listing_location.
 *
 * Flow (all driven by the admin page, no SSH/artisan):
 *   1. Snapshot — AJAX-paged fetch through /users/{u}/inventory, NDJSON
 *      to storage/app/discogs-inventory-snapshots/{id}/listings.ndjson.
 *      Stays under the 60-req/min rate limit by serializing pages from
 *      the browser.
 *   2. Preview — count totals, count dedups against existing products
 *      (matched by discogs_release_id), download dupes as CSV.
 *   3. Apply — chunked batch insert into products / product_variations /
 *      variations / product_locations / variation_location_details.
 *      Idempotent: a listing.id already in applied.json is skipped.
 */
class DiscogsInventoryImportController extends Controller
{
    private const DEFAULT_LOCATION_NAME = 'Discogs Warehouse';
    private const SNAPSHOT_DIR = 'discogs-inventory-snapshots';

    public function index(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');

        $snapshots = $this->listSnapshots();
        $locations = BusinessLocation::where('business_id', $business_id)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();

        return view('admin.discogs_inventory_import', [
            'snapshots' => $snapshots,
            'locations' => $locations,
            'default_location_name' => self::DEFAULT_LOCATION_NAME,
        ]);
    }

    /**
     * Start a new snapshot session. Returns the snapshot_id the browser
     * will pass back on each /fetch-page call.
     */
    public function snapshotStart(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $username = trim((string) $request->input('username', ''));
        if ($username === '') {
            return response()->json(['ok' => false, 'error' => 'username required'], 422);
        }

        $svc = new DiscogsService($business_id);
        if (!$svc->isConfigured()) {
            return response()->json([
                'ok' => false,
                'error' => 'Discogs API token not configured in Business Settings > Integrations.',
            ], 422);
        }

        $first = $svc->fetchInventoryPage($username, 1, 100, 'For Sale');
        if (!empty($first['error'])) {
            return response()->json(['ok' => false, 'error' => $first['error']], 422);
        }

        $pagination = $first['pagination'] ?? [];
        $totalItems = (int) ($pagination['items'] ?? 0);
        $totalPages = (int) ($pagination['pages'] ?? 1);

        $snapshotId = 'snap_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
        $dir = $this->snapshotDir($snapshotId);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        file_put_contents($dir . '/meta.json', json_encode([
            'snapshot_id' => $snapshotId,
            'business_id' => $business_id,
            'username' => $username,
            'started_at' => date('c'),
            'status' => 'fetching',
            'total_items' => $totalItems,
            'total_pages' => $totalPages,
            'pages_fetched' => 0,
            'last_error' => null,
        ], JSON_PRETTY_PRINT));

        $count = $this->appendListings($dir, $first['listings'] ?? []);
        $this->updateMeta($dir, ['pages_fetched' => 1, 'rows_written' => $count]);

        return response()->json([
            'ok' => true,
            'snapshot_id' => $snapshotId,
            'total_items' => $totalItems,
            'total_pages' => $totalPages,
            'pages_fetched' => 1,
            'rows_written' => $count,
        ]);
    }

    /**
     * Fetch a single page and append to the snapshot. Browser drives this
     * in a loop so we naturally respect the rate limit + show progress.
     */
    public function snapshotPage(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $snapshotId = $this->safeSnapshotId($request->input('snapshot_id'));
        $page = max(2, (int) $request->input('page', 2));

        $dir = $this->snapshotDir($snapshotId);
        $meta = $this->readMeta($dir);
        if (!$meta) {
            return response()->json(['ok' => false, 'error' => 'snapshot not found'], 404);
        }

        $svc = new DiscogsService($business_id);
        $resp = $svc->fetchInventoryPage($meta['username'], $page, 100, 'For Sale');
        if (!empty($resp['error'])) {
            $this->updateMeta($dir, ['last_error' => $resp['error']]);
            $status = !empty($resp['retry']) ? 429 : 502;
            return response()->json(['ok' => false, 'error' => $resp['error']], $status);
        }

        $count = $this->appendListings($dir, $resp['listings'] ?? []);
        $rowsWritten = (int) ($meta['rows_written'] ?? 0) + $count;
        $pagesFetched = max((int) ($meta['pages_fetched'] ?? 0), $page);
        $totalPages = (int) ($resp['pagination']['pages'] ?? $meta['total_pages']);
        $done = $pagesFetched >= $totalPages;

        $this->updateMeta($dir, [
            'pages_fetched' => $pagesFetched,
            'rows_written' => $rowsWritten,
            'total_pages' => $totalPages,
            'last_error' => null,
            'status' => $done ? 'fetched' : 'fetching',
        ]);

        return response()->json([
            'ok' => true,
            'snapshot_id' => $snapshotId,
            'page' => $page,
            'pages_fetched' => $pagesFetched,
            'total_pages' => $totalPages,
            'rows_written' => $rowsWritten,
            'done' => $done,
        ]);
    }

    /**
     * Scan the snapshot file and count dedups vs existing products. Also
     * writes dupes.csv for download. Skips re-scanning when meta has a
     * fresh preview cached.
     */
    public function preview(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $snapshotId = $this->safeSnapshotId($request->input('snapshot_id'));
        $dir = $this->snapshotDir($snapshotId);
        $meta = $this->readMeta($dir);
        if (!$meta) {
            return response()->json(['ok' => false, 'error' => 'snapshot not found'], 404);
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $ndjsonPath = $dir . '/listings.ndjson';
        if (!is_file($ndjsonPath)) {
            return response()->json(['ok' => false, 'error' => 'no listings file'], 422);
        }

        // Build sets in one pass: release_ids in this snapshot, listing_ids.
        $releaseIds = [];
        $listingIds = [];
        $total = 0;
        $fh = fopen($ndjsonPath, 'rb');
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') continue;
            $row = json_decode($line, true);
            if (!is_array($row)) continue;
            $total++;
            $rid = (int) ($row['release']['id'] ?? 0);
            $lid = (int) ($row['id'] ?? 0);
            if ($rid > 0) $releaseIds[$rid] = true;
            if ($lid > 0) $listingIds[$lid] = true;
        }
        fclose($fh);

        // Find which release_ids already exist as ERP products. Chunk to
        // keep the IN clause under MySQL's max_allowed_packet.
        $existingReleaseIds = [];
        $chunks = array_chunk(array_keys($releaseIds), 1000);
        foreach ($chunks as $chunk) {
            $rows = DB::table('products')
                ->where('business_id', $business_id)
                ->whereNotNull('discogs_release_id')
                ->whereIn('discogs_release_id', $chunk)
                ->pluck('discogs_release_id')
                ->all();
            foreach ($rows as $r) {
                $existingReleaseIds[(int) $r] = true;
            }
        }

        // Write dupes.csv: every listing whose release_id is already
        // present. Sarah uses this to pick legit-second-copies for manual
        // re-add after the bulk import.
        $appliedListingIds = $this->loadAppliedListingIds($dir);
        $dupesCsv = $dir . '/dupes.csv';
        $dupesCount = 0;
        $newCount = 0;
        $alreadyAppliedCount = 0;

        $fh = fopen($ndjsonPath, 'rb');
        $cf = fopen($dupesCsv, 'wb');
        fputcsv($cf, ['discogs_listing_id', 'discogs_release_id', 'artist', 'title', 'condition', 'sleeve_condition', 'price', 'bin_location', 'listed_at']);
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') continue;
            $row = json_decode($line, true);
            if (!is_array($row)) continue;
            $lid = (int) ($row['id'] ?? 0);
            $rid = (int) ($row['release']['id'] ?? 0);
            if ($lid > 0 && isset($appliedListingIds[$lid])) {
                $alreadyAppliedCount++;
                continue;
            }
            if ($rid > 0 && isset($existingReleaseIds[$rid])) {
                $dupesCount++;
                fputcsv($cf, [
                    $lid,
                    $rid,
                    $row['release']['artist'] ?? ($row['release']['description'] ?? ''),
                    $row['release']['title'] ?? '',
                    $row['condition'] ?? '',
                    $row['sleeve_condition'] ?? '',
                    is_array($row['price'] ?? null) ? (string) ($row['price']['value'] ?? '') : '',
                    $row['location'] ?? '',
                    $row['posted'] ?? '',
                ]);
            } else {
                $newCount++;
            }
        }
        fclose($cf);
        fclose($fh);

        $this->updateMeta($dir, [
            'preview_at' => date('c'),
            'preview_total' => $total,
            'preview_new' => $newCount,
            'preview_dupes' => $dupesCount,
            'preview_already_applied' => $alreadyAppliedCount,
        ]);

        return response()->json([
            'ok' => true,
            'total' => $total,
            'new' => $newCount,
            'dupes' => $dupesCount,
            'already_applied' => $alreadyAppliedCount,
            'dupes_csv_url' => url('/admin/discogs-import-inventory/dupes/' . $snapshotId),
        ]);
    }

    public function downloadDupes(Request $request, $snapshotId)
    {
        $snapshotId = $this->safeSnapshotId($snapshotId);
        $path = $this->snapshotDir($snapshotId) . '/dupes.csv';
        if (!is_file($path)) {
            abort(404);
        }
        return response()->download($path, 'discogs_dupes_' . $snapshotId . '.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    /**
     * Apply a chunk of new listings to the DB. Browser loops until done.
     * Each call processes up to ?batch_size listings starting at ?offset.
     */
    public function apply(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $userId = (int) auth()->id();
        $snapshotId = $this->safeSnapshotId($request->input('snapshot_id'));
        $offset = max(0, (int) $request->input('offset', 0));
        $batchSize = min(500, max(10, (int) $request->input('batch_size', 100)));
        $locationName = trim((string) $request->input('location_name', self::DEFAULT_LOCATION_NAME));
        $explicitLocationId = (int) $request->input('location_id', 0);
        // Sarah 2026-05-15: default-hide from POS so the bulk import can
        // run during open hours without 50k Discogs-warehouse rows polluting
        // cashier product search. Cashiers shouldn't be ringing Discogs
        // stock at the register anyway. Flip later via /products if needed.
        $hideFromPos = filter_var($request->input('hide_from_pos', true), FILTER_VALIDATE_BOOLEAN);

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        @ignore_user_abort(true);

        $dir = $this->snapshotDir($snapshotId);
        $meta = $this->readMeta($dir);
        if (!$meta) {
            return response()->json(['ok' => false, 'error' => 'snapshot not found'], 404);
        }

        $locationId = $explicitLocationId > 0
            ? $explicitLocationId
            : $this->ensureLocationByName($business_id, $locationName, $userId);

        $location = BusinessLocation::where('business_id', $business_id)
            ->where('id', $locationId)
            ->first();
        if (!$location) {
            return response()->json(['ok' => false, 'error' => 'invalid location_id'], 422);
        }

        $ndjsonPath = $dir . '/listings.ndjson';
        if (!is_file($ndjsonPath)) {
            return response()->json(['ok' => false, 'error' => 'no listings file'], 422);
        }

        $appliedListingIds = $this->loadAppliedListingIds($dir);
        $existingReleaseIds = $this->loadCachedExistingReleaseIds($business_id, $dir);

        $mapper = new DiscogsReleaseImportMapper();
        $created = 0;
        $skipped = 0;
        $errors = [];
        $processed = 0;
        $lineIndex = -1;
        $newAppliedIds = [];

        $fh = fopen($ndjsonPath, 'rb');
        while (($line = fgets($fh)) !== false) {
            $lineIndex++;
            if ($lineIndex < $offset) continue;
            if ($processed >= $batchSize) break;
            $processed++;

            $line = trim($line);
            if ($line === '') { $skipped++; continue; }

            $row = json_decode($line, true);
            if (!is_array($row)) { $skipped++; continue; }

            $listingId = (int) ($row['id'] ?? 0);
            $releaseId = (int) ($row['release']['id'] ?? 0);

            if ($listingId > 0 && isset($appliedListingIds[$listingId])) { $skipped++; continue; }
            if ($releaseId > 0 && isset($existingReleaseIds[$releaseId])) { $skipped++; continue; }

            try {
                $newProductId = $this->createProductFromListing($business_id, $userId, $locationId, $row, $mapper, $hideFromPos);
                if ($newProductId) {
                    $created++;
                    $newAppliedIds[$listingId] = $newProductId;
                    if ($releaseId > 0) {
                        $existingReleaseIds[$releaseId] = true;
                    }
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['listing_id' => $listingId, 'error' => $e->getMessage()];
                Log::warning('Discogs inventory import row failed', [
                    'listing_id' => $listingId,
                    'release_id' => $releaseId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        // Did we read past the end?
        $done = feof($fh);
        fclose($fh);

        if (!empty($newAppliedIds)) {
            $this->appendApplied($dir, $newAppliedIds);
        }

        $nextOffset = $offset + $processed;
        $this->updateMeta($dir, [
            'apply_status' => $done ? 'applied' : 'applying',
            'apply_offset' => $nextOffset,
            'apply_location_id' => $locationId,
            'apply_location_name' => $location->name,
        ]);

        return response()->json([
            'ok' => true,
            'snapshot_id' => $snapshotId,
            'processed' => $processed,
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
            'next_offset' => $nextOffset,
            'done' => $done,
            'location_id' => $locationId,
            'location_name' => $location->name,
        ]);
    }

    private function createProductFromListing(int $businessId, int $userId, int $locationId, array $row, DiscogsReleaseImportMapper $mapper, bool $hideFromPos = true): ?int
    {
        $listingId = (int) ($row['id'] ?? 0);
        $releaseId = (int) ($row['release']['id'] ?? 0);
        if ($listingId === 0) {
            return null;
        }

        $release = $row['release'] ?? [];
        $title = trim((string) ($release['title'] ?? ''));
        $artist = trim((string) ($release['artist'] ?? ($release['description'] ?? '')));
        $format = trim((string) ($release['format'] ?? ''));

        $name = $title;
        if ($artist !== '' && $title !== '') {
            $name = $artist . ' - ' . $title;
        } elseif ($artist !== '' && $title === '') {
            $name = $artist;
        } elseif ($name === '') {
            $name = 'Discogs Listing ' . $listingId;
        }

        // Bin / shelf location string Sarah enters on Discogs (e.g. "A14").
        $bin = trim((string) ($row['location'] ?? ''));

        // Price comes through as an associative array on the inventory
        // endpoint: {"value": 19.99, "currency": "USD"}.
        $price = 0.0;
        if (isset($row['price']) && is_array($row['price']) && isset($row['price']['value'])) {
            $price = (float) $row['price']['value'];
        }

        $condition = trim((string) ($row['condition'] ?? ''));
        $sleeve = trim((string) ($row['sleeve_condition'] ?? ''));
        $comments = trim((string) ($row['comments'] ?? ''));

        // Build a description that captures the per-listing condition +
        // sleeve so the ERP keeps the same context a Discogs buyer sees.
        $descParts = [];
        if ($format !== '') {
            $descParts[] = 'Format: ' . $format;
        }
        if ($condition !== '') {
            $descParts[] = 'Media: ' . $condition;
        }
        if ($sleeve !== '') {
            $descParts[] = 'Sleeve: ' . $sleeve;
        }
        if ($comments !== '') {
            $descParts[] = 'Notes: ' . $comments;
        }
        $description = $descParts ? implode("\n", $descParts) : null;

        $categoryId = null;
        $subCategoryId = null;
        // The inventory payload includes a slim release object — full catno
        // is only available via /releases/{id}. Skip that extra round-trip
        // (would 60x the API budget) and use catalog_number when present.
        $sku = trim((string) ($release['catalog_number'] ?? ''));

        return DB::transaction(function () use (
            $businessId, $userId, $locationId, $listingId, $releaseId,
            $name, $artist, $title, $bin, $price, $description, $categoryId, $subCategoryId, $sku, $hideFromPos
        ) {
            // products row
            $now = now();
            $productId = DB::table('products')->insertGetId([
                'business_id' => $businessId,
                'name' => mb_substr($name, 0, 191),
                'artist' => $artist !== '' ? mb_substr($artist, 0, 191) : null,
                'sku' => $sku !== '' && $sku !== null ? mb_substr($sku, 0, 191) : ('DG-' . $listingId),
                'type' => 'single',
                'category_id' => $categoryId,
                'sub_category_id' => $subCategoryId,
                'unit_id' => 1,
                'tax' => null,
                'tax_type' => 'exclusive',
                'enable_stock' => 1,
                'alert_quantity' => 0,
                'product_description' => $description,
                'bin_position' => null,
                'listing_location' => $bin !== '' ? mb_substr($bin, 0, 255) : null,
                'discogs_release_id' => $releaseId > 0 ? $releaseId : null,
                'is_inactive' => $hideFromPos ? 1 : 0,
                'created_by' => $userId,
                'added_via' => 'discogs_inventory_import',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // product_variations + variations (single-type product)
            $productVariationId = DB::table('product_variations')->insertGetId([
                'product_id' => $productId,
                'name' => 'DUMMY',
                'is_dummy' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $variationId = DB::table('variations')->insertGetId([
                'product_id' => $productId,
                'product_variation_id' => $productVariationId,
                'name' => 'DUMMY',
                'sub_sku' => 'DG-' . $listingId,
                'default_purchase_price' => 0,
                'dpp_inc_tax' => 0,
                'profit_percent' => 0,
                'default_sell_price' => $price,
                'sell_price_inc_tax' => $price,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('product_locations')->insert([
                'product_id' => $productId,
                'location_id' => $locationId,
            ]);

            DB::table('variation_location_details')->insert([
                'product_id' => $productId,
                'product_variation_id' => $productVariationId,
                'variation_id' => $variationId,
                'location_id' => $locationId,
                'qty_available' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $productId;
        });
    }

    /**
     * Find the seller's BusinessLocation by name, creating it if missing.
     * Sarah's preferred default is "Discogs Warehouse".
     */
    private function ensureLocationByName(int $businessId, string $name, int $userId): int
    {
        $name = $name !== '' ? $name : self::DEFAULT_LOCATION_NAME;
        $existing = BusinessLocation::where('business_id', $businessId)
            ->where('name', $name)
            ->first();
        if ($existing) {
            return (int) $existing->id;
        }

        // Generate a location_id reference matching BusinessLocationController
        $ref = 'BL' . str_pad((string) (BusinessLocation::where('business_id', $businessId)->count() + 1), 4, '0', STR_PAD_LEFT);

        $loc = BusinessLocation::create([
            'business_id' => $businessId,
            'location_id' => $ref,
            'name' => $name,
            'landmark' => null,
            'country' => 'USA',
            'state' => 'CA',
            'city' => 'Los Angeles',
            'zip_code' => '90028',
            'invoice_scheme_id' => 1,
            'invoice_layout_id' => 1,
        ]);

        try {
            \Spatie\Permission\Models\Permission::create(['name' => 'location.' . $loc->id]);
        } catch (\Throwable $e) {
            // permission may already exist or model may differ — non-fatal
        }

        return (int) $loc->id;
    }

    // ─────────────────────── helpers ───────────────────────

    private function snapshotDir(string $snapshotId): string
    {
        return storage_path('app/' . self::SNAPSHOT_DIR . '/' . $snapshotId);
    }

    private function safeSnapshotId($id): string
    {
        $id = (string) $id;
        if (!preg_match('/^snap_[A-Za-z0-9_]{4,80}$/', $id)) {
            abort(400, 'invalid snapshot_id');
        }
        return $id;
    }

    private function readMeta(string $dir): ?array
    {
        $path = $dir . '/meta.json';
        if (!is_file($path)) return null;
        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    private function updateMeta(string $dir, array $patch): void
    {
        $path = $dir . '/meta.json';
        $current = $this->readMeta($dir) ?: [];
        $merged = array_merge($current, $patch);
        @file_put_contents($path, json_encode($merged, JSON_PRETTY_PRINT));
    }

    private function appendListings(string $dir, array $listings): int
    {
        if (!$listings) return 0;
        $path = $dir . '/listings.ndjson';
        $fh = fopen($path, 'ab');
        $n = 0;
        foreach ($listings as $listing) {
            $json = json_encode($listing, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json !== false) {
                fwrite($fh, $json . "\n");
                $n++;
            }
        }
        fclose($fh);
        return $n;
    }

    /**
     * applied.json is a JSON object: {listing_id: product_id}.
     * Re-runs key off this file so we don't recreate already-imported rows.
     */
    private function loadAppliedListingIds(string $dir): array
    {
        $path = $dir . '/applied.json';
        if (!is_file($path)) return [];
        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function appendApplied(string $dir, array $newPairs): void
    {
        $path = $dir . '/applied.json';
        $current = $this->loadAppliedListingIds($dir);
        $merged = $current + $newPairs;
        @file_put_contents($path, json_encode($merged));
    }

    private function loadCachedExistingReleaseIds(int $businessId, string $dir): array
    {
        // Re-query each apply call would be slow at 55k rows; cache the
        // set in-memory per request. The dedup is best-effort — once we
        // start inserting new products with release_ids, the cache picks
        // up rows we created in this same apply pass.
        static $cache = [];
        $key = $businessId . ':' . $dir;
        if (isset($cache[$key])) return $cache[$key];

        $set = [];
        DB::table('products')
            ->where('business_id', $businessId)
            ->whereNotNull('discogs_release_id')
            ->orderBy('discogs_release_id')
            ->chunk(5000, function ($rows) use (&$set) {
                foreach ($rows as $r) {
                    $set[(int) $r->discogs_release_id] = true;
                }
            });

        $cache[$key] = $set;
        return $set;
    }

    private function listSnapshots(): array
    {
        $root = storage_path('app/' . self::SNAPSHOT_DIR);
        if (!is_dir($root)) return [];
        $out = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $meta = $this->readMeta($root . '/' . $entry);
            if ($meta) {
                $out[] = $meta;
            }
        }
        usort($out, function ($a, $b) {
            return strcmp($b['started_at'] ?? '', $a['started_at'] ?? '');
        });
        return $out;
    }
}
