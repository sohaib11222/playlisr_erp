<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\ProductNameNormalizer;
use App\Services\DiscogsService;

/**
 * Owner-only tool to bring product names onto the "ARTIST - TITLE" standard.
 *
 * Scan is read-only: it proposes a normalized name for every product whose
 * name is off-standard and can be fixed confidently (real artist column + a
 * derivable title). Products with no real artist ("Unknown Artist"/blank) or
 * an underivable title are flagged for manual review and never auto-changed.
 *
 * Apply runs in batches, snapshots each old name to admin-snapshots first, and
 * is reversible at /admin/admin-action-history (action product-name-cleanup).
 */
class ProductNameController extends Controller
{
    protected function isOwner()
    {
        $u = auth()->user();
        return $u
            && strtolower(trim((string) $u->first_name)) === 'jonathan'
            && strtolower(trim((string) $u->last_name)) === 'hedvat';
    }

    public function index()
    {
        if (!$this->isOwner()) {
            abort(403, 'Product name cleanup is owner-only.');
        }
        return view('products.name_cleanup');
    }

    // ===================== ARTIST BACKFILL (from name) =====================
    // Music formats (vinyl/CD/cassette/45s) require an artist, but a lot of
    // legacy/imported rows have a blank or "N/A" artist column with the artist
    // still sitting inside the name ("Title / Artist" or "Artist - Title").
    // This parses it back out — Preview-first, confident-only, undoable via the
    // backfill-artist-from-name snapshot.

    /** Main-category ids (for this business) that require an artist. */
    protected function musicCategoryIds($business_id)
    {
        $music = \App\Http\Controllers\ProductController::musicArtistCategories();
        $ids = [];
        foreach (\DB::table('categories')->where('business_id', $business_id)->select('id', 'name')->get() as $c) {
            if (in_array(strtolower(trim((string) $c->name)), $music, true)) {
                $ids[] = (int) $c->id;
            }
        }
        return $ids;
    }

    /** Products in a music category whose artist column is blank / "N/A"-ish. */
    protected function artistlessMusicQuery($business_id, $catIds)
    {
        return \DB::table('products')
            ->where('business_id', $business_id)
            ->whereIn('category_id', $catIds)
            ->where(function ($q) {
                $q->whereNull('artist')
                  ->orWhereRaw("TRIM(artist) = ''")
                  ->orWhereRaw("LOWER(TRIM(artist)) REGEXP '^(n/?a|unknown|various|none|no artist)$'");
            });
    }

    /**
     * Split artist-less music products into: fillable (confident parse) and
     * flagged (needs manual). Returns counts + a capped preview of fillable.
     */
    protected function computeArtistBackfill($business_id, $collectFixes = true, $limit = null)
    {
        $catIds = $this->musicCategoryIds($business_id);
        if (empty($catIds)) {
            return ['fixes' => [], 'to_fill' => 0, 'flagged' => 0, 'cat_ids' => []];
        }

        $fixes = [];
        $toFill = 0;
        $flagged = 0;

        $this->artistlessMusicQuery($business_id, $catIds)
            ->select('id', 'name', 'artist')
            ->orderBy('id')
            ->chunk(2000, function ($rows) use (&$fixes, &$toFill, &$flagged, $collectFixes, $limit) {
                foreach ($rows as $r) {
                    $res = ProductNameNormalizer::artistFromName($r->name);
                    if (!$res['confident']) { $flagged++; continue; }
                    $toFill++;
                    if ($collectFixes && ($limit === null || count($fixes) < $limit)) {
                        $fixes[] = [
                            'id' => (int) $r->id,
                            'name' => $r->name,
                            'old' => (string) ($r->artist ?? ''),
                            'new' => $res['artist'],
                            'source' => $res['source'],
                        ];
                    }
                }
            });

        return ['fixes' => $fixes, 'to_fill' => $toFill, 'flagged' => $flagged, 'cat_ids' => $catIds];
    }

    public function artistScan(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        if (!$this->isOwner()) {
            return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        }
        $business_id = $request->session()->get('user.business_id');
        try {
            $data = $this->computeArtistBackfill($business_id, true, 300);
        } catch (\Throwable $e) {
            \Log::error('artist-backfill scan failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'msg' => 'Scan failed: ' . $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'to_fill' => $data['to_fill'],
            'flagged' => $data['flagged'],
            'preview' => $data['fixes'],
        ]);
    }

    /**
     * Fill one batch of artists (up to `max`, default 500) from the name and
     * report how many remain. Writes one backfill-artist-from-name snapshot per
     * batch; undoable at /admin/admin-action-history.
     */
    public function artistApply(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        if (!$this->isOwner()) {
            return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        }
        $business_id = $request->session()->get('user.business_id');
        $max = (int) $request->input('max', 500);
        if ($max < 1) { $max = 500; }

        $catIds = $this->musicCategoryIds($business_id);
        if (empty($catIds)) {
            return response()->json(['success' => true, 'filled' => 0, 'remaining' => 0, 'msg' => 'No music categories found.']);
        }

        $batch = [];
        $this->artistlessMusicQuery($business_id, $catIds)
            ->select('id', 'name', 'artist')
            ->orderBy('id')
            ->chunk(2000, function ($rows) use (&$batch, $max) {
                foreach ($rows as $r) {
                    $res = ProductNameNormalizer::artistFromName($r->name);
                    if (!$res['confident']) { continue; }
                    $batch[] = [
                        'id' => (int) $r->id,
                        'old' => (string) ($r->artist ?? ''),
                        'new' => $res['artist'],
                    ];
                    if (count($batch) >= $max) { return false; }
                }
            });

        if (empty($batch)) {
            return response()->json(['success' => true, 'filled' => 0, 'remaining' => 0, 'msg' => 'Nothing left to fill.']);
        }

        $timestamp = now()->format('Y-m-d_His');
        $filled = 0;

        \DB::beginTransaction();
        try {
            foreach ($batch as $b) {
                // Only fill if the artist is still blank/"N/A"-ish, so a concurrent
                // edit isn't clobbered.
                $affected = \DB::table('products')
                    ->where('id', $b['id'])
                    ->where(function ($q) {
                        $q->whereNull('artist')
                          ->orWhereRaw("TRIM(artist) = ''")
                          ->orWhereRaw("LOWER(TRIM(artist)) REGEXP '^(n/?a|unknown|various|none|no artist)$'");
                    })
                    ->update(['artist' => $b['new']]);
                if ($affected) { $filled++; }
            }

            \Storage::disk('local')->put(
                "admin-snapshots/backfill-artist-from-name-{$timestamp}.json",
                json_encode([
                    'timestamp' => $timestamp,
                    'action' => 'backfill-artist-from-name',
                    'user_id' => auth()->id(),
                    'business_id' => $business_id,
                    'source_name' => $filled . ' artist(s) parsed from name',
                    'target_name' => 'products.artist',
                    'rows' => array_map(function ($b) {
                        return ['id' => $b['id'], 'old' => $b['old'], 'new' => $b['new']];
                    }, $batch),
                ], JSON_PRETTY_PRINT)
            );
            \DB::commit();
        } catch (\Throwable $e) {
            \DB::rollBack();
            if (\Storage::disk('local')->exists("admin-snapshots/backfill-artist-from-name-{$timestamp}.json")) {
                \Storage::disk('local')->delete("admin-snapshots/backfill-artist-from-name-{$timestamp}.json");
            }
            \Log::emergency('artist-backfill failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'msg' => 'Fill failed — nothing was changed.']);
        }

        $remaining = $this->computeArtistBackfill($business_id, false)['to_fill'];

        return response()->json([
            'success' => true,
            'filled' => $filled,
            'remaining' => $remaining,
            'msg' => "Filled {$filled}. {$remaining} remaining.",
        ]);
    }

    /**
     * Walk the catalog and split into: to-fix (confident, off-standard) and
     * flagged (needs manual). Returns counts + a capped preview of to-fix.
     */
    protected function computeChanges($business_id, $collectFixes = true, $limit = null)
    {
        $fixes = [];
        $toFix = 0;
        $flagged = 0;
        $compliant = 0;

        \DB::table('products')
            ->where('business_id', $business_id)
            ->select('id', 'name', 'artist')
            ->orderBy('id')
            ->chunk(2000, function ($rows) use (&$fixes, &$toFix, &$flagged, &$compliant, $collectFixes, $limit) {
                foreach ($rows as $r) {
                    $res = ProductNameNormalizer::canonical($r->artist, $r->name);
                    if (!$res['confident']) { $flagged++; continue; }
                    if ($res['compliant']) { $compliant++; continue; }
                    $toFix++;
                    if ($collectFixes && ($limit === null || count($fixes) < $limit)) {
                        $fixes[] = ['id' => (int) $r->id, 'old' => $r->name, 'new' => $res['name']];
                    }
                }
            });

        return ['fixes' => $fixes, 'to_fix' => $toFix, 'flagged' => $flagged, 'compliant' => $compliant];
    }

    public function scan(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        if (!$this->isOwner()) {
            return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        }
        $business_id = $request->session()->get('user.business_id');
        try {
            $data = $this->computeChanges($business_id, true, 300);
        } catch (\Throwable $e) {
            \Log::error('product-name-cleanup scan failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'msg' => 'Scan failed: ' . $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'to_fix' => $data['to_fix'],
            'flagged' => $data['flagged'],
            'compliant' => $data['compliant'],
            'preview' => $data['fixes'],
        ]);
    }

    /**
     * Rename one batch of off-standard products (up to `max`, default 500) and
     * report how many remain. The UI loops until remaining hits 0. Each call
     * writes one product-name-cleanup snapshot.
     */
    public function apply(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        if (!$this->isOwner()) {
            return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        }
        $business_id = $request->session()->get('user.business_id');
        $max = (int) $request->input('max', 500);
        if ($max < 1) { $max = 500; }

        // Gather this batch of confident, off-standard products.
        $batch = [];
        \DB::table('products')
            ->where('business_id', $business_id)
            ->select('id', 'name', 'artist')
            ->orderBy('id')
            ->chunk(2000, function ($rows) use (&$batch, $max) {
                foreach ($rows as $r) {
                    $res = ProductNameNormalizer::canonical($r->artist, $r->name);
                    if ($res['confident'] && !$res['compliant']) {
                        $batch[] = ['id' => (int) $r->id, 'old' => $r->name, 'new' => $res['name']];
                        if (count($batch) >= $max) { return false; } // stop chunking
                    }
                }
            });

        if (empty($batch)) {
            return response()->json(['success' => true, 'renamed' => 0, 'remaining' => 0, 'msg' => 'Nothing left to fix.']);
        }

        $timestamp = now()->format('Y-m-d_His');
        $renamed = 0;

        \DB::beginTransaction();
        try {
            foreach ($batch as $b) {
                // Guard against a concurrent edit: only rename if the name is
                // still exactly what we snapshotted.
                $affected = \DB::table('products')->where('id', $b['id'])->where('name', $b['old'])
                    ->update(['name' => $b['new']]);
                if ($affected) { $renamed++; }
            }

            \Storage::disk('local')->put(
                "admin-snapshots/product-name-cleanup-{$timestamp}.json",
                json_encode([
                    'timestamp' => $timestamp,
                    'action' => 'product-name-cleanup',
                    'user_id' => auth()->id(),
                    'business_id' => $business_id,
                    'source_name' => $renamed . ' product name(s)',
                    'target_name' => 'ARTIST - TITLE',
                    'rows' => array_map(function ($b) { return ['id' => $b['id'], 'old' => $b['old'], 'new' => $b['new']]; }, $batch),
                ], JSON_PRETTY_PRINT)
            );
            \DB::commit();
        } catch (\Throwable $e) {
            \DB::rollBack();
            if (\Storage::disk('local')->exists("admin-snapshots/product-name-cleanup-{$timestamp}.json")) {
                \Storage::disk('local')->delete("admin-snapshots/product-name-cleanup-{$timestamp}.json");
            }
            \Log::emergency('product-name-cleanup failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'msg' => 'Rename failed — nothing was changed.']);
        }

        $remaining = $this->computeChanges($business_id, false)['to_fix'];

        return response()->json([
            'success' => true,
            'renamed' => $renamed,
            'remaining' => $remaining,
            'msg' => "Renamed {$renamed}. {$remaining} remaining.",
        ]);
    }

    // ===================== DISCOGS-SOURCED REBUILD =====================
    // The artist column is unreliable (often holds the title), so the accurate
    // fix is to rebuild "ARTIST - TITLE" from the Discogs release itself, using
    // products.discogs_release_id. Rate-limited, cursor-batched, undoable via
    // the same product-name-cleanup snapshot.

    /** True artist + title from a Discogs release object -> "Artist - Title". */
    protected function nameFromRelease($data)
    {
        if (!$data) { return null; }
        $data = (object) $data;

        $artist = trim((string) ($data->artists_sort ?? ''));
        if ($artist === '' && !empty($data->artists) && is_array($data->artists)) {
            $parts = [];
            foreach ($data->artists as $a) {
                $a = (object) $a;
                $parts[] = trim((string) ($a->anv ?? '') ?: (string) ($a->name ?? ''));
            }
            $artist = trim(implode(' ', array_filter($parts)));
        }
        // Strip Discogs disambiguation suffixes like "Nirvana (2)".
        $artist = trim(preg_replace('/\s*\(\d+\)/', '', $artist));

        $title = trim((string) ($data->title ?? ''));
        if ($artist === '' || $title === '') { return null; }

        return $artist . ' - ' . ProductNameNormalizer::properTitle($title);
    }

    protected function candidateQuery($business_id)
    {
        return \DB::table('products')
            ->where('business_id', $business_id)
            ->whereNotNull('discogs_release_id')
            ->where('discogs_release_id', '>', 0);
    }

    /** Count Discogs-backed products + a small live sample of proposed names. */
    public function discogsScan(Request $request)
    {
        @set_time_limit(0);
        if (!$this->isOwner()) {
            return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        }
        if (!\Schema::hasColumn('products', 'discogs_release_id')) {
            return response()->json(['success' => false, 'msg' => 'No discogs_release_id column on products.']);
        }
        $business_id = $request->session()->get('user.business_id');
        $total = $this->candidateQuery($business_id)->count();

        // Live sample so she sees real Discogs-sourced names before committing.
        $svc = new DiscogsService($business_id);
        if (!$svc->isConfigured()) {
            return response()->json(['success' => false, 'msg' => 'Discogs API token not configured (Business Settings > Integrations).']);
        }
        $sample = [];
        $rows = $this->candidateQuery($business_id)->select('id', 'name', 'discogs_release_id')->orderBy('id')->limit(10)->get();
        foreach ($rows as $r) {
            if (stripos($r->name, 'retired') !== false) { continue; }
            $res = $svc->getReleaseById($r->discogs_release_id);
            if (!empty($res['error'])) { continue; }
            $proposed = $this->nameFromRelease($res['data'] ?? null);
            if ($proposed && $proposed !== $r->name) {
                $sample[] = ['old' => $r->name, 'new' => $proposed];
            }
            usleep(1100000);
        }

        return response()->json([
            'success' => true,
            'total' => $total,
            'sample' => $sample,
        ]);
    }

    /**
     * Rebuild one batch from Discogs, cursor-paged by product id. `after_id`
     * starts at 0. Fetches up to `max` (default 20) releases, throttled to stay
     * under Discogs' ~60/min limit. Returns the new cursor so the UI can loop.
     */
    public function discogsRebuild(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        if (!$this->isOwner()) {
            return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        }
        $business_id = $request->session()->get('user.business_id');
        $afterId = (int) $request->input('after_id', 0);
        $max = (int) $request->input('max', 20);
        if ($max < 1 || $max > 40) { $max = 20; }

        $svc = new DiscogsService($business_id);
        if (!$svc->isConfigured()) {
            return response()->json(['success' => false, 'msg' => 'Discogs API token not configured.']);
        }

        $rows = $this->candidateQuery($business_id)
            ->where('id', '>', $afterId)
            ->select('id', 'name', 'discogs_release_id')
            ->orderBy('id')->limit($max)->get();

        if ($rows->isEmpty()) {
            return response()->json(['success' => true, 'renamed' => 0, 'failed' => 0, 'done' => true, 'after_id' => $afterId, 'remaining' => 0]);
        }

        $timestamp = now()->format('Y-m-d_His');
        $changes = [];
        $failed = 0;
        $rateLimited = false;
        $lastId = $afterId;

        foreach ($rows as $r) {
            $res = $svc->getReleaseById($r->discogs_release_id);
            if (!empty($res['error'])) {
                // Stop the batch on a rate-limit hit so the UI can pause; the
                // cursor stays before this row so we retry it next round.
                if (stripos((string) $res['message'], '429') !== false || stripos((string) $res['message'], 'rate') !== false) {
                    $rateLimited = true;
                    break;
                }
                $failed++;
                $lastId = (int) $r->id;
                usleep(1100000);
                continue;
            }
            $lastId = (int) $r->id;

            if (stripos($r->name, 'retired') === false) {
                $proposed = $this->nameFromRelease($res['data'] ?? null);
                if ($proposed && $proposed !== $r->name) {
                    $changes[] = ['id' => (int) $r->id, 'old' => $r->name, 'new' => $proposed];
                }
            }
            usleep(1100000); // ~55 calls/min, under Discogs' 60/min ceiling
        }

        $renamed = 0;
        if (!empty($changes)) {
            \DB::beginTransaction();
            try {
                foreach ($changes as $c) {
                    $affected = \DB::table('products')->where('id', $c['id'])->where('name', $c['old'])
                        ->update(['name' => $c['new']]);
                    if ($affected) { $renamed++; }
                }
                \Storage::disk('local')->put(
                    "admin-snapshots/product-name-cleanup-{$timestamp}.json",
                    json_encode([
                        'timestamp' => $timestamp,
                        'action' => 'product-name-cleanup',
                        'user_id' => auth()->id(),
                        'business_id' => $business_id,
                        'source_name' => $renamed . ' product name(s) (Discogs)',
                        'target_name' => 'ARTIST - TITLE',
                        'rows' => $changes,
                    ], JSON_PRETTY_PRINT)
                );
                \DB::commit();
            } catch (\Throwable $e) {
                \DB::rollBack();
                if (\Storage::disk('local')->exists("admin-snapshots/product-name-cleanup-{$timestamp}.json")) {
                    \Storage::disk('local')->delete("admin-snapshots/product-name-cleanup-{$timestamp}.json");
                }
                return response()->json(['success' => false, 'msg' => 'Rename failed — nothing changed this batch.']);
            }
            \Cache::forget('products_index_sold_totals:' . $business_id);
        }

        $remaining = $this->candidateQuery($business_id)->where('id', '>', $lastId)->count();

        return response()->json([
            'success' => true,
            'renamed' => $renamed,
            'failed' => $failed,
            'rate_limited' => $rateLimited,
            'after_id' => $lastId,
            'remaining' => $remaining,
            'done' => ($remaining === 0 && !$rateLimited),
        ]);
    }
}
