<?php

namespace App\Services;

/**
 * Fills products.sub_category_id (genre) for blank-genre music products that
 * have a discogs_release_id. Same logic as the "Fill blank genres" button on
 * /products/name-cleanup (ProductNameController::discogsGenreFill) — this is
 * the scheduled/CLI counterpart, same pattern as
 * DiscogsStreetDateBackfillService feeding both a button and a cron job, so
 * this can run to completion on the server without needing a browser tab
 * open (2026-09-10: Sarah's browser-driven run stalled out at ~22,584 of
 * 50,077 remaining once the tab closed).
 *
 * Genre is NOT a free-text column — it's a category assignment
 * (products.sub_category_id, a child row in `categories` under the
 * product's format category_id). This NEVER creates a new category: it only
 * assigns a genre that already exists in the catalog's own taxonomy, tried
 * in this order:
 *   1. The most common genre already used, in THIS business's catalog, by
 *      another product with the same artist under the same format — no
 *      Discogs call needed.
 *   2. Discogs' style/genre tags for the release, matched against the
 *      product's existing sub-categories. Discogs' own arrays come back
 *      alphabetically, NOT by relevance (confirmed live 2026-09-08: Prince
 *      "Thieves in the Temple" -> ['Electronic','Funk / Soul','Pop'],
 *      really Funk/Soul; Carly Simon "Torch" -> ['Electronic','Jazz','Pop'],
 *      really Jazz/Pop) — so a raw "first genre" pick is wrong more often
 *      than not, hence the exact-match-against-existing-categories
 *      requirement rather than trusting Discogs' ordering.
 * No match anywhere = left blank. Every write is snapshotted to
 * storage/admin-snapshots for undo via Admin Action History
 * (action=backfill-genre-from-discogs, same as the button).
 */
class DiscogsGenreBackfillService
{
    /** One batch: process up to $limit eligible products. */
    public function run(int $businessId, int $limit = 20, bool $commit = false): array
    {
        $svc = new \App\Services\DiscogsService($businessId);
        if (!$svc->isConfigured()) {
            return ['ok' => false, 'error' => 'Discogs API token not configured.'];
        }

        $catIds = $this->musicCategoryIds($businessId);
        if (empty($catIds)) {
            return ['ok' => true, 'checked' => 0, 'filled' => 0, 'failed' => 0, 'remaining' => 0];
        }
        $sealedIds = $this->sealedVinylCategoryIds($businessId);

        $query = \DB::table('products')
            ->where('business_id', $businessId)
            ->whereIn('category_id', $catIds)
            ->where(function ($q) { $q->whereNull('sub_category_id')->orWhere('sub_category_id', 0); })
            ->whereNotNull('discogs_release_id')
            ->where('discogs_release_id', '>', 0);

        // Sealed vinyl first within a single query, rather than two phases —
        // simpler for a scheduled batch with no client-side state to track.
        if (!empty($sealedIds)) {
            $sealedList = implode(',', array_map('intval', $sealedIds));
            $query->orderByRaw("CASE WHEN category_id IN ({$sealedList}) THEN 0 ELSE 1 END");
        }
        $rows = $query->select('id', 'name', 'artist', 'category_id', 'sub_category_id', 'discogs_release_id')
            ->orderBy('id')
            ->limit($limit)->get();

        $timestamp = now()->format('Y-m-d_His');
        $changes = [];
        $filled = 0;
        $failed = 0;

        foreach ($rows as $r) {
            if (stripos($r->name, 'retired') !== false || !$r->category_id) { continue; }

            $ownGenreName = $this->mostCommonGenreNameForArtist($businessId, $r->category_id, $r->artist);
            $subCatId = $ownGenreName !== null
                ? $this->matchExistingSubCategory($businessId, $r->category_id, [$ownGenreName])
                : null;

            if (!$subCatId) {
                $res = $svc->getReleaseById($r->discogs_release_id);
                usleep(1100000); // ~55 calls/min, under Discogs' 60/min ceiling
                if (!empty($res['error'])) {
                    $failed++;
                    continue;
                }
                $candidates = $this->genreCandidatesFromRelease($res['data'] ?? null);
                $subCatId = $this->matchExistingSubCategory($businessId, $r->category_id, $candidates);
            }

            if ($subCatId) {
                $changes[] = ['id' => (int) $r->id, 'old' => $r->sub_category_id ? (int) $r->sub_category_id : null, 'new' => $subCatId];
            }
        }

        if ($commit && !empty($changes)) {
            \DB::beginTransaction();
            try {
                foreach ($changes as $c) {
                    $affected = \DB::table('products')->where('id', $c['id'])
                        ->where(function ($q) { $q->whereNull('sub_category_id')->orWhere('sub_category_id', 0); })
                        ->update(['sub_category_id' => $c['new']]);
                    if ($affected) { $filled++; }
                }
                \Storage::disk('local')->put(
                    "admin-snapshots/backfill-genre-from-discogs-{$timestamp}.json",
                    json_encode([
                        'timestamp' => $timestamp,
                        'action' => 'backfill-genre-from-discogs',
                        'user_id' => null,
                        'business_id' => $businessId,
                        'source_name' => $filled . ' genre(s) from Discogs (scheduled)',
                        'target_name' => 'products.sub_category_id',
                        'rows' => $changes,
                    ], JSON_PRETTY_PRINT)
                );
                \DB::commit();
            } catch (\Throwable $e) {
                \DB::rollBack();
                return ['ok' => false, 'error' => 'Write failed: ' . $e->getMessage()];
            }
        } elseif (!$commit) {
            $filled = count($changes); // dry-run: report what WOULD be filled
        }

        $remaining = (clone $query)->count();

        return [
            'ok' => true,
            'checked' => $rows->count(),
            'filled' => $filled,
            'failed' => $failed,
            'remaining' => $remaining,
        ];
    }

    protected function musicCategoryIds($business_id)
    {
        $ids = [];
        foreach (\DB::table('categories')->where('business_id', $business_id)->select('id', 'name')->get() as $c) {
            if (\App\Http\Controllers\ProductController::isMusicCategoryName($c->name)) {
                $ids[] = (int) $c->id;
            }
        }
        return $ids;
    }

    protected function sealedVinylCategoryIds($business_id)
    {
        $ids = [];
        foreach (\DB::table('categories')->where('business_id', $business_id)->select('id', 'name')->get() as $c) {
            if (preg_match('/vinyl/i', $c->name) && preg_match('/seal/i', $c->name)) {
                $ids[] = (int) $c->id;
            }
        }
        return $ids;
    }

    protected function matchExistingSubCategory($business_id, $parentCategoryId, array $candidateNames)
    {
        if (!$parentCategoryId) { return null; }
        $existingByLower = \DB::table('categories')
            ->where('business_id', $business_id)
            ->where('parent_id', $parentCategoryId)
            ->where('category_type', 'product')
            ->whereNull('deleted_at')
            ->pluck('id', 'name')
            ->mapWithKeys(function ($id, $name) { return [mb_strtolower(trim($name)) => (int) $id]; });

        foreach ($candidateNames as $name) {
            $key = mb_strtolower(trim((string) $name));
            if ($key !== '' && $existingByLower->has($key)) {
                return $existingByLower->get($key);
            }
        }
        return null;
    }

    protected function mostCommonGenreNameForArtist($business_id, $categoryId, $artist)
    {
        $artist = trim((string) $artist);
        if ($artist === '' || preg_match('/^(n\/?a|unknown|various|none|no artist)$/i', $artist)) {
            return null;
        }
        $row = \DB::table('products')
            ->join('categories', 'products.sub_category_id', '=', 'categories.id')
            ->where('products.business_id', $business_id)
            ->where('products.category_id', $categoryId)
            ->whereRaw('LOWER(TRIM(products.artist)) = ?', [mb_strtolower($artist)])
            ->whereNotNull('products.sub_category_id')
            ->select('categories.name', \DB::raw('COUNT(*) as cnt'))
            ->groupBy('categories.name')
            ->orderByDesc('cnt')
            ->first();
        return $row ? $row->name : null;
    }

    protected function genreCandidatesFromRelease($data)
    {
        if (!$data) { return []; }
        $data = (object) $data;
        $styles = is_array($data->styles ?? null) ? $data->styles : [];
        $genres = is_array($data->genres ?? null) ? $data->genres : [];
        return array_values(array_filter(array_map('trim', array_merge($styles, $genres))));
    }
}
