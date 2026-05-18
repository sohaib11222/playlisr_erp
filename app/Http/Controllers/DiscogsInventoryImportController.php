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

        // Live counts so Sarah can see how complete the import is without
        // grepping logs. DG-{listing_id} sub_sku is unique per Discogs
        // listing across every snapshot run. Soft-deleted products are
        // excluded so the cleanup action immediately reflects in the count.
        $importedCount = DB::table('variations')
            ->join('products', 'products.id', '=', 'variations.product_id')
            ->where('products.business_id', $business_id)
->where('variations.sub_sku', 'like', 'DG-%')
            ->count();

        $byLocation = DB::table('product_locations')
            ->join('products', 'products.id', '=', 'product_locations.product_id')
            ->join('business_locations', 'business_locations.id', '=', 'product_locations.location_id')
            ->where('products.business_id', $business_id)
->where('products.added_via', 'discogs_inventory_import')
            ->select('business_locations.name', DB::raw('COUNT(*) as cnt'))
            ->groupBy('business_locations.name')
            ->get();

        // How many DG-{id} sub_skus appear more than once → concurrent-
        // apply dupes from before the listing_id dedup fix landed.
        $dupeReport = DB::table('variations')
            ->join('products', 'products.id', '=', 'variations.product_id')
            ->where('products.business_id', $business_id)
->where('variations.sub_sku', 'like', 'DG-%')
            ->select('variations.sub_sku', DB::raw('COUNT(*) as cnt'))
            ->groupBy('variations.sub_sku')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        $dupeSubSkus = $dupeReport->count();
        $extraRows = $dupeReport->sum(function ($r) { return $r->cnt - 1; });

        return view('admin.discogs_inventory_import', [
            'snapshots' => $snapshots,
            'locations' => $locations,
            'default_location_name' => self::DEFAULT_LOCATION_NAME,
            'imported_count' => $importedCount,
            'by_location' => $byLocation,
            'dupe_sub_skus' => $dupeSubSkus,
            'extra_rows' => $extraRows,
        ]);
    }

    /**
     * Categorize Discogs imports AND set their variation cost in one
     * pass. Always re-categorizes DG-* products (since the categorize
     * heuristic improves over time — e.g., the Sealed Vinyl detection
     * shipped after the first runs). Cost only updates when the
     * current variation cost is 0/NULL so manual edits survive.
     *
     * Snapshot captures pre-change category_id + cost for undo.
     */
    public function backfillCategories(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $confirm = filter_var($request->input('confirm', false), FILTER_VALIDATE_BOOLEAN);

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        // Format key → [ERP category name, cost per /admin/cost-price-rules].
        // Sealed paths trigger when Discogs Media condition = "Mint (M)".
        $formatRules = [
            '7" / 45 RPM'       => ['name' => '7", 45 RPM',              'cost' => 0.15],
            '8 track'           => ['name' => '8 track',                 'cost' => 0.25],
            'CD'                => ['name' => 'Used CD',                 'cost' => 0.10],
            'Sealed CD'         => ['name' => 'Sealed CD / CD (Sealed)', 'cost' => 6.00],
            'Cassette'          => ['name' => 'Cassettes',               'cost' => 0.30],
            'Sealed Cassette'   => ['name' => 'Cassettes - Sealed',      'cost' => 6.00],
            'VHS'               => ['name' => 'VHS',                     'cost' => 0.10],
            'Sealed LP / Vinyl' => ['name' => 'Sealed Vinyl',            'cost' => 17.00],
            'LP / Vinyl'        => ['name' => 'Used Vinyl',              'cost' => 0.35],
        ];

        $categoryIdByName = DB::table('categories')
            ->where('business_id', $business_id)
            ->where('parent_id', 0)
            ->whereIn('name', array_column($formatRules, 'name'))
            ->pluck('id', 'name')
            ->toArray();

        if (empty($categoryIdByName['Used Vinyl'])) {
            return response()->json([
                'ok' => false,
                'error' => 'No "Used Vinyl" category in this business — create one first (or rename an existing parent).',
            ], 422);
        }

        // Walk every Discogs import — no NULL category_id filter, so a
        // re-run will re-evaluate them all (esp. moving Mint (M) rows
        // out of Used Vinyl into Sealed Vinyl after the detection
        // upgrade landed).
        $bucket = [];        // [category_id => [product_id, ...]]
        $costByCategory = []; // [category_id => cost]
        $perCategory = [];   // [name => count]
        DB::table('products')
            ->where('business_id', $business_id)
            ->where('added_via', 'discogs_inventory_import')
            ->select('id', 'product_description')
            ->orderBy('id')
            ->chunk(5000, function ($rows) use (&$bucket, &$costByCategory, &$perCategory, $formatRules, $categoryIdByName) {
                foreach ($rows as $r) {
                    $key = $this->detectFormatKey((string) $r->product_description);
                    $rule = $formatRules[$key] ?? $formatRules['LP / Vinyl'];
                    $cid = $categoryIdByName[$rule['name']] ?? $categoryIdByName['Used Vinyl'];
                    $bucket[$cid][] = (int) $r->id;
                    $costByCategory[$cid] = (float) $rule['cost'];
                    $perCategory[$rule['name']] = ($perCategory[$rule['name']] ?? 0) + 1;
                }
            });

        $total = array_sum($perCategory);

        if (!$confirm) {
            return response()->json([
                'ok' => true,
                'preview' => true,
                'total' => $total,
                'breakdown' => $perCategory,
            ]);
        }

        $snapDir = storage_path('app/admin-snapshots');
        if (!is_dir($snapDir)) {
            @mkdir($snapDir, 0775, true);
        }
        $allIds = [];
        foreach ($bucket as $ids) {
            foreach ($ids as $id) $allIds[] = $id;
        }
        $beforeRows = ['products' => [], 'variations' => []];
        foreach (array_chunk($allIds, 5000) as $chunk) {
            $rows = DB::table('products')
                ->whereIn('id', $chunk)
                ->select('id', 'category_id', 'sub_category_id')
                ->get();
            foreach ($rows as $r) {
                $beforeRows['products'][] = ['id' => (int) $r->id, 'category_id' => $r->category_id, 'sub_category_id' => $r->sub_category_id];
            }
            $vrows = DB::table('variations')
                ->whereIn('product_id', $chunk)
                ->select('id', 'product_id', 'default_purchase_price', 'dpp_inc_tax')
                ->get();
            foreach ($vrows as $vr) {
                $beforeRows['variations'][] = ['id' => (int) $vr->id, 'product_id' => (int) $vr->product_id, 'default_purchase_price' => $vr->default_purchase_price, 'dpp_inc_tax' => $vr->dpp_inc_tax];
            }
        }
        $snapPath = $snapDir . '/' . date('Ymd_His') . '_discogs_categorize.json';
        @file_put_contents($snapPath, json_encode([
            'business_id' => $business_id,
            'at' => date('c'),
            'rows' => $beforeRows,
        ]));

        $productsUpdated = 0;
        $variationsUpdated = 0;
        foreach ($bucket as $categoryId => $ids) {
            $cost = $costByCategory[$categoryId];
            foreach (array_chunk($ids, 1000) as $chunk) {
                $productsUpdated += DB::table('products')
                    ->whereIn('id', $chunk)
                    ->update([
                        'category_id' => $categoryId,
                        'updated_at' => now(),
                    ]);
                // Only stamp cost where it's still the import-time
                // placeholder (0/NULL). Skipping non-zero rows preserves
                // any manual cost adjustments Sarah's made later.
                $variationsUpdated += DB::table('variations')
                    ->whereIn('product_id', $chunk)
                    ->where(function ($q) {
                        $q->whereNull('default_purchase_price')->orWhere('default_purchase_price', 0);
                    })
                    ->update([
                        'default_purchase_price' => $cost,
                        'dpp_inc_tax' => $cost,
                        'updated_at' => now(),
                    ]);
            }
        }

        return response()->json([
            'ok' => true,
            'preview' => false,
            'products_updated' => $productsUpdated,
            'variations_updated' => $variationsUpdated,
            'breakdown' => $perCategory,
            'snapshot' => str_replace(storage_path('app') . '/', '', $snapPath),
        ]);
    }

    /**
     * Quick format detector — looks at the "Format: ..." line we wrote
     * to product_description during import and returns a stable key
     * matching the formatToCategoryName map.
     */
    private function detectFormatKey(string $description): string
    {
        $formatLine = '';
        if (preg_match('/^Format:\s*(.+)$/mi', $description, $m)) {
            $formatLine = mb_strtolower($m[1]);
        } else {
            $formatLine = mb_strtolower($description);
        }

        // Discogs media condition "Mint (M)" = sealed/never played (Sarah's
        // convention 2026-05-18). Pulled from the "Media: ..." line we
        // wrote during import. Anything else (NM, VG+, VG, G, etc.) is used.
        $isSealed = false;
        if (preg_match('/^Media:\s*Mint\s*\(M\)/mi', $description)) {
            $isSealed = true;
        }

        if (mb_strpos($formatLine, '8 track') !== false || mb_strpos($formatLine, '8-track') !== false) {
            return '8 track';
        }
        if (mb_strpos($formatLine, 'cassette') !== false) {
            return $isSealed ? 'Sealed Cassette' : 'Cassette';
        }
        if (mb_strpos($formatLine, 'vhs') !== false) {
            return 'VHS';
        }
        if (mb_strpos($formatLine, 'cd') !== false && mb_strpos($formatLine, 'vinyl') === false) {
            // Disambiguate: skip if vinyl also present — multi-format release.
            return $isSealed ? 'Sealed CD' : 'CD';
        }
        // 7" detection — also catches "7 inch" or "45 rpm"
        if (preg_match('/(^|[^0-9])7\s*"|7\s*inch|45\s*rpm/u', $formatLine)) {
            return '7" / 45 RPM';
        }
        return $isSealed ? 'Sealed LP / Vinyl' : 'LP / Vinyl';
    }

    /**
     * List all roles for this business with a flag showing whether they
     * currently have permission to see the Discogs Warehouse location at
     * POS (i.e., whether they hold the location.{id} Spatie permission).
     */
    public function rolesForLocation(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');

        $loc = BusinessLocation::where('business_id', $business_id)
            ->where('name', self::DEFAULT_LOCATION_NAME)
            ->first();
        if (!$loc) {
            return response()->json(['ok' => false, 'error' => 'Discogs Warehouse location not found yet — apply at least once first.'], 422);
        }
        $permName = 'location.' . $loc->id;

        $roles = DB::table('roles')->where('business_id', $business_id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $grantedRoleIds = DB::table('role_has_permissions as rhp')
            ->join('permissions as p', 'p.id', '=', 'rhp.permission_id')
            ->where('p.name', $permName)
            ->pluck('rhp.role_id')
            ->all();
        $grantedSet = array_flip(array_map('intval', $grantedRoleIds));

        $out = $roles->map(function ($r) use ($grantedSet) {
            return [
                'id' => (int) $r->id,
                'name' => $r->name,
                'has_access' => isset($grantedSet[(int) $r->id]),
            ];
        });

        return response()->json([
            'ok' => true,
            'location_id' => (int) $loc->id,
            'permission_name' => $permName,
            'roles' => $out,
        ]);
    }

    /**
     * Grant or revoke the Discogs Warehouse location permission for a
     * given role. Creates the permission row if it doesn't yet exist.
     * Cashiers in that role then see Discogs Warehouse stock in POS.
     */
    public function setPosAccess(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $roleId = (int) $request->input('role_id', 0);
        $grant = filter_var($request->input('grant', true), FILTER_VALIDATE_BOOLEAN);

        $loc = BusinessLocation::where('business_id', $business_id)
            ->where('name', self::DEFAULT_LOCATION_NAME)
            ->first();
        if (!$loc) {
            return response()->json(['ok' => false, 'error' => 'Discogs Warehouse location not found.'], 422);
        }
        if ($roleId <= 0) {
            return response()->json(['ok' => false, 'error' => 'role_id required'], 422);
        }
        $role = DB::table('roles')->where('id', $roleId)->where('business_id', $business_id)->first();
        if (!$role) {
            return response()->json(['ok' => false, 'error' => 'role not found in this business'], 404);
        }

        $permName = 'location.' . $loc->id;
        $perm = DB::table('permissions')->where('name', $permName)->first();
        if (!$perm) {
            $permId = DB::table('permissions')->insertGetId([
                'name' => $permName,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $permId = (int) $perm->id;
        }

        if ($grant) {
            $exists = DB::table('role_has_permissions')
                ->where('permission_id', $permId)
                ->where('role_id', $roleId)
                ->exists();
            if (!$exists) {
                DB::table('role_has_permissions')->insert([
                    'permission_id' => $permId,
                    'role_id' => $roleId,
                ]);
            }
        } else {
            DB::table('role_has_permissions')
                ->where('permission_id', $permId)
                ->where('role_id', $roleId)
                ->delete();
        }

        return response()->json([
            'ok' => true,
            'role_id' => $roleId,
            'role_name' => $role->name,
            'access' => $grant ? 'granted' : 'revoked',
        ]);
    }

    /**
     * Bulk toggle is_inactive on every Discogs-import product so they
     * show (or hide) in /products. POS is unaffected because Discogs
     * Warehouse isn't in any cashier role's location permission set —
     * the register's product search filters by location join.
     */
    public function toggleVisibility(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $show = filter_var($request->input('show', true), FILTER_VALIDATE_BOOLEAN);

        $updated = DB::table('products')
            ->where('business_id', $business_id)
            ->where('added_via', 'discogs_inventory_import')
            ->update(['is_inactive' => $show ? 0 : 1]);

        return response()->json([
            'ok' => true,
            'updated' => $updated,
            'now' => $show ? 'visible' : 'hidden',
        ]);
    }

    /**
     * Reconcile ERP against an uploaded Discogs inventory CSV (Sarah's
     * direct export from the Discogs seller dashboard). Any ERP product
     * with sub_sku DG-{listing_id} whose listing_id is NOT in the CSV
     * gets deleted (it's no longer for sale on Discogs).
     *
     * Two-phase: dry-run by default, deletes only when confirm=true.
     * Snapshots the deletion set first.
     */
    public function reconcileCsv(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $confirm = filter_var($request->input('confirm', false), FILTER_VALIDATE_BOOLEAN);

        if (!$request->hasFile('csv')) {
            return response()->json(['ok' => false, 'error' => 'CSV file required (field name: csv)'], 422);
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $file = $request->file('csv');
        $path = $file->getRealPath();
        $fh = fopen($path, 'rb');
        if (!$fh) {
            return response()->json(['ok' => false, 'error' => 'Could not open CSV'], 500);
        }

        // Pick the column that holds the Discogs listing id. Discogs
        // export uses "listing_id" but defensively match other variants.
        $header = fgetcsv($fh);
        if (!$header) {
            fclose($fh);
            return response()->json(['ok' => false, 'error' => 'Empty CSV'], 422);
        }
        $listingCol = null;
        foreach ($header as $i => $col) {
            $c = mb_strtolower(trim((string) $col));
            if ($c === 'listing_id' || $c === 'discogs listing id' || $c === 'id' || $c === 'listingid') {
                $listingCol = $i;
                break;
            }
        }
        if ($listingCol === null) {
            fclose($fh);
            return response()->json([
                'ok' => false,
                'error' => 'CSV has no listing_id column. Header was: ' . implode(', ', $header),
            ], 422);
        }

        $csvListingIds = [];
        while (($row = fgetcsv($fh)) !== false) {
            $val = isset($row[$listingCol]) ? trim((string) $row[$listingCol]) : '';
            if ($val === '') continue;
            $id = (int) $val;
            if ($id > 0) $csvListingIds[$id] = true;
        }
        fclose($fh);
        $csvCount = count($csvListingIds);

        // Walk every live DG-{id} variation and bucket as keep / delete.
        $erpListingIds = [];
        $variationToProduct = [];
        DB::table('variations')
            ->join('products', 'products.id', '=', 'variations.product_id')
            ->where('products.business_id', $business_id)
->where('variations.sub_sku', 'like', 'DG-%')
            ->select('variations.id as variation_id', 'variations.product_id', 'variations.sub_sku')
            ->orderBy('variations.id')
            ->chunk(5000, function ($rows) use (&$erpListingIds, &$variationToProduct) {
                foreach ($rows as $r) {
                    if (preg_match('/^DG-(\d+)$/', (string) $r->sub_sku, $m)) {
                        $lid = (int) $m[1];
                        $erpListingIds[$lid][] = (int) $r->product_id;
                        $variationToProduct[(int) $r->variation_id] = (int) $r->product_id;
                    }
                }
            });

        $productIdsToDelete = [];
        $missingFromErp = [];
        foreach ($erpListingIds as $lid => $productIds) {
            if (!isset($csvListingIds[$lid])) {
                foreach ($productIds as $pid) {
                    $productIdsToDelete[] = $pid;
                }
            }
        }
        foreach ($csvListingIds as $lid => $_) {
            if (!isset($erpListingIds[$lid])) {
                $missingFromErp[] = $lid;
            }
        }
        $productIdsToDelete = array_values(array_unique($productIdsToDelete));

        if (!$confirm) {
            return response()->json([
                'ok' => true,
                'preview' => true,
                'csv_listings' => $csvCount,
                'erp_listings' => count($erpListingIds),
                'to_delete' => count($productIdsToDelete),
                'missing_from_erp' => count($missingFromErp),
                'sample_missing' => array_slice($missingFromErp, 0, 10),
            ]);
        }

        // Snapshot before mutation (Sarah's never-wipe-without-snapshot rule).
        $snapDir = storage_path('app/admin-snapshots');
        if (!is_dir($snapDir)) {
            @mkdir($snapDir, 0775, true);
        }
        $snapPath = $snapDir . '/' . date('Ymd_His') . '_discogs_csv_reconcile.json';
        @file_put_contents($snapPath, json_encode([
            'business_id' => $business_id,
            'at' => date('c'),
            'csv_count' => $csvCount,
            'erp_count' => count($erpListingIds),
            'deleted_product_ids' => $productIdsToDelete,
        ]));

        $deleted = $this->cascadeDeleteProducts($productIdsToDelete);

        return response()->json([
            'ok' => true,
            'preview' => false,
            'csv_listings' => $csvCount,
            'erp_listings' => count($erpListingIds),
            'deleted' => $deleted,
            'missing_from_erp' => count($missingFromErp),
            'snapshot' => str_replace(storage_path('app') . '/', '', $snapPath),
        ]);
    }

    /**
     * Delete duplicate DG-{listing_id} products created by overlapping
     * apply runs (concurrent snapshots before the listing_id dedup fix
     * landed). Keeps the OLDEST variation per sub_sku, snapshots the
     * deleted set first, then cascades through variation_location_details,
     * variations, product_variations, product_locations, products.
     */
    public function cleanupDuplicates(Request $request)
    {
        $business_id = (int) $request->session()->get('user.business_id');
        $confirm = filter_var($request->input('confirm', false), FILTER_VALIDATE_BOOLEAN);

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        // For each duplicate sub_sku, list every variation EXCEPT the
        // oldest one. The corresponding product_ids are what we'll soft-
        // delete.
        $rowsToDelete = DB::select(
            "SELECT v.id AS variation_id, v.product_id, v.sub_sku
             FROM variations v
             JOIN products p ON p.id = v.product_id
             WHERE p.business_id = ?
AND v.sub_sku LIKE 'DG-%'
               AND v.id > (
                   SELECT MIN(v2.id)
                   FROM variations v2
                   JOIN products p2 ON p2.id = v2.product_id
                   WHERE p2.business_id = ?
AND v2.sub_sku = v.sub_sku
               )",
            [$business_id, $business_id]
        );

        $productIds = array_values(array_unique(array_map(function ($r) {
            return (int) $r->product_id;
        }, $rowsToDelete)));
        $count = count($productIds);

        if (!$confirm) {
            return response()->json([
                'ok' => true,
                'preview' => true,
                'product_ids_to_delete' => $count,
                'sample' => array_slice($rowsToDelete, 0, 10),
            ]);
        }

        // Snapshot before mutation (per the never-wipe-without-snapshot rule).
        $snapDir = storage_path('app/admin-snapshots');
        if (!is_dir($snapDir)) {
            @mkdir($snapDir, 0775, true);
        }
        $snapPath = $snapDir . '/' . date('Ymd_His') . '_discogs_dedup_cleanup.json';
        @file_put_contents($snapPath, json_encode([
            'business_id' => $business_id,
            'at' => date('c'),
            'rows' => $rowsToDelete,
        ]));

        $deleted = $this->cascadeDeleteProducts($productIds);

        return response()->json([
            'ok' => true,
            'preview' => false,
            'deleted' => $deleted,
            'snapshot' => str_replace(storage_path('app') . '/', '', $snapPath),
        ]);
    }

    /**
     * Hard-delete products + every dependent row in the variation tree.
     * Used for both duplicate-cleanup and CSV-reconcile flows. Caller
     * is responsible for writing a snapshot file first.
     *
     * Order matters because there are FK constraints:
     *   variation_location_details → variations → product_variations
     *   product_locations → products
     */
    private function cascadeDeleteProducts(array $productIds): int
    {
        if (empty($productIds)) return 0;

        $totalDeleted = 0;
        foreach (array_chunk($productIds, 1000) as $chunk) {
            DB::transaction(function () use ($chunk, &$totalDeleted) {
                // Pull variation IDs for this chunk so we can clean up
                // their dependent rows before the FK trips.
                $variationIds = DB::table('variations')
                    ->whereIn('product_id', $chunk)
                    ->pluck('id')
                    ->all();

                if ($variationIds) {
                    DB::table('variation_location_details')
                        ->whereIn('variation_id', $variationIds)
                        ->delete();
                    DB::table('variations')
                        ->whereIn('id', $variationIds)
                        ->delete();
                }
                DB::table('product_variations')
                    ->whereIn('product_id', $chunk)
                    ->delete();
                DB::table('product_locations')
                    ->whereIn('product_id', $chunk)
                    ->delete();
                $totalDeleted += DB::table('products')
                    ->whereIn('id', $chunk)
                    ->delete();
            });
        }
        return $totalDeleted;
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
        // keep the IN clause under MySQL's max_allowed_packet. Exclude
        // products this snapshot's previous apply runs already created
        // — without that, multi-copies of the same release would self-
        // report as dupes once the first copy lands in the DB.
        $appliedProductIds = array_map('intval', array_values($this->loadAppliedListingIds($dir)));
        $existingReleaseIds = [];
        $chunks = array_chunk(array_keys($releaseIds), 1000);
        foreach ($chunks as $chunk) {
            $q = DB::table('products')
                ->where('business_id', $business_id)
                ->whereNotNull('discogs_release_id')
                ->whereIn('discogs_release_id', $chunk);
            if ($appliedProductIds) {
                $q->whereNotIn('id', $appliedProductIds);
            }
            $rows = $q->pluck('discogs_release_id')->all();
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

        // Sarah 2026-05-15: snapshot the baseline release_id set so apply()
        // doesn't drift — once apply starts inserting new products with
        // release_ids, a "live" DB query would treat the second/third
        // copies of the same release as dupes of the first. The point of
        // the import is to create one ERP product per Discogs listing,
        // multi-copies included.
        // Always overwrite so re-running preview after a partial apply
        // refreshes the baseline correctly (we already excluded
        // applied product IDs above).
        $dedupPath = $dir . '/dedup_release_ids.json';
        @file_put_contents($dedupPath, json_encode(array_keys($existingReleaseIds)));

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

    /**
     * Return current snapshot meta (pages_fetched, total_pages, status).
     * Browser uses this on Resume so it picks up at pages_fetched+1
     * instead of refetching from page 2.
     */
    public function status(Request $request)
    {
        $snapshotId = $this->safeSnapshotId($request->input('snapshot_id'));
        $meta = $this->readMeta($this->snapshotDir($snapshotId));
        if (!$meta) {
            return response()->json(['ok' => false, 'error' => 'snapshot not found'], 404);
        }
        return response()->json(['ok' => true, 'meta' => $meta]);
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
        // Cross-snapshot dedup: every variation we create gets sub_sku
        // 'DG-{listing_id}', so a single DB scan tells us every Discogs
        // listing already imported by ANY snapshot. This replaces the
        // brittle release_id dedup, which kept treating legitimate
        // multi-copies of the same release as duplicates.
        $importedListingIds = $this->loadImportedListingIdsFromDb();

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
            // Cross-snapshot: was this listing already imported under a
            // different snapshot's apply? sub_sku check catches that.
            if ($listingId > 0 && isset($importedListingIds[$listingId])) { $skipped++; continue; }

            try {
                $newProductId = $this->createProductFromListing($business_id, $userId, $locationId, $row, $mapper, $hideFromPos);
                if ($newProductId) {
                    $created++;
                    $newAppliedIds[$listingId] = $newProductId;
                    // Don't add to existingReleaseIds — multi-copies of the
                    // same release in this snapshot should each become
                    // their own ERP product.
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

    /**
     * Pull every Discogs listing_id that's already been imported as an
     * ERP variation (sub_sku 'DG-{listing_id}'). Re-queried per HTTP
     * batch so concurrent fixes/retries can't get out of sync. With an
     * index on variations.sub_sku this is fast even at 50k rows.
     */
    private function loadImportedListingIdsFromDb(): array
    {
        $set = [];
        DB::table('variations')
            ->where('sub_sku', 'like', 'DG-%')
            ->orderBy('id')
            ->select('sub_sku')
            ->chunk(5000, function ($rows) use (&$set) {
                foreach ($rows as $r) {
                    if (preg_match('/^DG-(\d+)$/', (string) $r->sub_sku, $m)) {
                        $set[(int) $m[1]] = true;
                    }
                }
            });
        return $set;
    }

    /**
     * Build the dedup set fresh from DB on every apply batch — file-cached
     * versions kept biting us when stale state from earlier preview/fix
     * iterations leaked in. Excludes products already created by THIS
     * snapshot's apply runs (so multi-copies of the same release each
     * become their own ERP product on subsequent batches).
     *
     * Performance: at 50k snapshot items this query runs ~500 times. We
     * only fetch release_ids that ALSO appear in the snapshot via the
     * cached snapshot-release-id set (read once per HTTP request).
     */
    private function loadFrozenDedupSet(int $businessId, string $dir, array $appliedListingIds): array
    {
        $appliedProductIds = array_map('intval', array_values($appliedListingIds));
        $snapshotReleaseIds = $this->loadSnapshotReleaseIds($dir);

        if (empty($snapshotReleaseIds)) {
            return [];
        }

        $set = [];
        $chunks = array_chunk(array_keys($snapshotReleaseIds), 1000);
        foreach ($chunks as $chunk) {
            $q = DB::table('products')
                ->where('business_id', $businessId)
                ->whereNotNull('discogs_release_id')
                ->whereIn('discogs_release_id', $chunk);
            if ($appliedProductIds) {
                $q->whereNotIn('id', $appliedProductIds);
            }
            $rows = $q->pluck('discogs_release_id')->all();
            foreach ($rows as $r) {
                $set[(int) $r] = true;
            }
        }
        return $set;
    }

    /**
     * Cache snapshot release_ids in memory (once per HTTP request) so
     * apply doesn't re-scan the 50k-line NDJSON every batch just to
     * build the dedup query's IN clause.
     */
    private function loadSnapshotReleaseIds(string $dir): array
    {
        static $cache = [];
        if (isset($cache[$dir])) return $cache[$dir];

        $set = [];
        $path = $dir . '/listings.ndjson';
        if (!is_file($path)) {
            $cache[$dir] = $set;
            return $set;
        }
        $fh = fopen($path, 'rb');
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') continue;
            $row = json_decode($line, true);
            if (!is_array($row)) continue;
            $rid = (int) ($row['release']['id'] ?? 0);
            if ($rid > 0) $set[$rid] = true;
        }
        fclose($fh);
        $cache[$dir] = $set;
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
