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

    /** Category ids (for this business) that denote a music format. */
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

    /** Category ids that are sealed vinyl ("Vinyl - Sealed") — surfaced first. */
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

    /**
     * Set of artists that already exist somewhere in this business's catalog,
     * keyed by normalized artistKey(). Used to disambiguate which side of a
     * "A / B" name is the artist.
     */
    /**
     * Normalized keys of common non-artist TITLE phrases that must never count
     * as an artist — even if a bad earlier fill wrote one into the artist column
     * (which would otherwise poison the recognized-artist set).
     */
    protected function titleStopKeys()
    {
        return ['greatesthits' => 1, 'bestof' => 1, 'thebest' => 1, 'live' => 1,
            'singles' => 1, 'thesingles' => 1, 'selftitled' => 1, 'hits' => 1, 'anthology' => 1,
            'collection' => 1, 'compilation' => 1, 'various' => 1, 'variousartists' => 1,
            'soundtrack' => 1, 'ost' => 1, 'deluxe' => 1, 'remastered' => 1, 'acoustic' => 1,
            'demos' => 1, 'demo' => 1, 'ep' => 1, 'lp' => 1, 'untitled' => 1,
            'instrumentals' => 1, 'instrumental' => 1];
    }

    protected function knownArtistKeys($business_id)
    {
        $stop = $this->titleStopKeys();
        $keys = [];
        \DB::table('products')
            ->where('business_id', $business_id)
            ->whereNotNull('artist')
            ->whereRaw("TRIM(artist) <> ''")
            ->distinct()
            ->pluck('artist')
            ->each(function ($a) use (&$keys, $stop) {
                $a = trim((string) $a);
                // Skip non-artist placeholders so "N/A"/"Various"/compilations
                // never count as a known artist.
                if ($a === '' || preg_match('/^(n\/?a|unknown|various|v\/?a|compilation|soundtrack|o\.?s\.?t\.?|misc|none|no artist)\b/i', $a)) { return; }
                $k = ProductNameNormalizer::artistKey($a);
                if ($k === '' || isset($stop[$k])) { return; }
                // Keep one canonical spelling per artist; prefer a mixed-case
                // one ("Burzum") over an ALL-CAPS duplicate ("BURZUM").
                if (!isset($keys[$k]) || (!preg_match('/\p{Ll}/u', $keys[$k]) && preg_match('/\p{Ll}/u', $a))) {
                    $keys[$k] = $a;
                }
            });
        return $keys;
    }

    /**
     * Artists recognizable from the catalog, for disambiguating "A / B" names.
     *
     * NOTE: we deliberately do NOT seed this from the products.artist column.
     * That column is unreliable and frequently holds the TITLE, so using it as a
     * known-artist source poisoned the parser — album titles ("Seven Year Ache",
     * "Wake Up Again", "Watch The Throne") got recognized as artists and beat the
     * real name. Instead we recognize an artist only from a stronger structural
     * signal: a name segment that pairs with >=2 DISTINCT other segments across
     * music products (a real artist appears with several different album titles;
     * a recurring TITLE pairs with only one artist). Stop-listed phrases never
     * count, and curated artists are always trusted. A one-off artist that can't
     * be corroborated this way is left for manual review rather than mis-filled.
     * Keyed by artistKey(), value = a representative spelling.
     */
    protected function artistSignalKeys($business_id, $catIds)
    {
        $keys = [];
        if (empty($catIds)) {
            foreach (ProductNameNormalizer::curatedArtists() as $k => $spelling) { $keys[$k] = $spelling; }
            return $keys;
        }

        $partners = [];   // key => [partnerKey => 1]
        $spell = [];
        \DB::table('products')
            ->where('business_id', $business_id)
            ->whereIn('category_id', $catIds)
            ->select('name')
            ->orderBy('id')
            ->chunk(3000, function ($rows) use (&$partners, &$spell) {
                foreach ($rows as $r) {
                    $seg = ProductNameNormalizer::nameSegments($r->name);
                    if ($seg === null) { continue; }
                    $k0 = ProductNameNormalizer::artistKey($seg[0]);
                    $k1 = ProductNameNormalizer::artistKey($seg[1]);
                    if ($k0 !== '') {
                        if (!isset($spell[$k0])) { $spell[$k0] = $seg[0]; }
                        if ($k1 !== '') { $partners[$k0][$k1] = 1; }
                    }
                    if ($k1 !== '') {
                        if (!isset($spell[$k1])) { $spell[$k1] = $seg[1]; }
                        if ($k0 !== '') { $partners[$k1][$k0] = 1; }
                    }
                }
            });

        // Repeated non-artist title phrases to never treat as an artist.
        $stop = $this->titleStopKeys();

        foreach ($partners as $k => $set) {
            if (count($set) >= 2 && !isset($keys[$k]) && !isset($stop[$k])) {
                $keys[$k] = $spell[$k];
            }
        }

        // Hand-fixed artists are always recognized (and spelled canonically),
        // so a one-off like Willie Colón is never flagged.
        foreach (ProductNameNormalizer::curatedArtists() as $k => $spelling) {
            $keys[$k] = $spelling;
        }
        return $keys;
    }

    /**
     * Diagnostic for the scan: how many blank/"N/A"-artist products sit in each
     * category across the WHOLE catalog (not just the music list), so we can see
     * where missing artists actually are and which categories are in scope.
     */
    protected function artistlessByCategory($business_id, $musicCatIds)
    {
        $music = array_flip(array_map('intval', $musicCatIds));
        $out = [];
        \DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->where('p.business_id', $business_id)
            ->where(function ($q) {
                $q->whereNull('p.artist')
                  ->orWhereRaw("TRIM(p.artist) = ''")
                  ->orWhereRaw("LOWER(TRIM(p.artist)) REGEXP '^(n/?a|unknown|various|none|no artist)$'");
            })
            ->select('p.category_id', \DB::raw('COALESCE(c.name, "(no category)") as cat'), \DB::raw('COUNT(*) as n'))
            ->groupBy('p.category_id', 'cat')
            ->orderByDesc('n')
            ->get()
            ->each(function ($r) use (&$out, $music) {
                $out[] = [
                    'category' => $r->cat,
                    'count' => (int) $r->n,
                    'in_scope' => isset($music[(int) $r->category_id]),
                ];
            });
        return $out;
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
    /**
     * Parse the artist for one product row. A product already tagged "Various"
     * (or "V/A" / "Compilation") is a compilation, so its artist is normalized
     * to "Various Artists" rather than guessed out of the title — "Hardcore -
     * Zoo Rave II" is not by the artist "Hardcore". A truly blank / "N/A" /
     * "unknown" artist is still parsed from the name as before.
     */
    protected function parseArtistFromRow($name, $currentArtist, $knownKeys)
    {
        $ca = trim((string) $currentArtist);
        if ($ca !== '' && preg_match('/^(various|v\/?a|compilation)\b/i', $ca)) {
            return ['artist' => 'Various Artists', 'title' => $name, 'source' => 'Compilation (Various)', 'confident' => true, 'reason' => '', 'trust' => 'high'];
        }
        return ProductNameNormalizer::artistFromName($name, $knownKeys);
    }

    protected function computeArtistBackfill($business_id, $collectFixes = true, $limit = null, $filter = '')
    {
        $catIds = $this->musicCategoryIds($business_id);
        if (empty($catIds)) {
            return ['fixes' => [], 'flagged_rows' => [], 'to_fill' => 0, 'total_to_fill' => 0, 'flagged' => 0, 'cat_ids' => []];
        }

        $filter = mb_strtolower(trim((string) $filter));
        $knownKeys = $this->artistSignalKeys($business_id, $catIds);
        $sealedIds = array_flip($this->sealedVinylCategoryIds($business_id));
        $fixes = [];
        $flaggedRows = [];
        $toFill = 0;         // confident parses matching the filter
        $totalToFill = 0;    // all confident parses (ignores the filter)
        $flagged = 0;

        $this->artistlessMusicQuery($business_id, $catIds)
            ->select('id', 'name', 'artist', 'category_id', 'sku')
            ->orderBy('id')
            ->chunk(2000, function ($rows) use (&$fixes, &$flaggedRows, &$toFill, &$totalToFill, &$flagged, $collectFixes, $limit, $knownKeys, $filter, $sealedIds) {
                foreach ($rows as $r) {
                    $res = $this->parseArtistFromRow($r->name, $r->artist, $knownKeys);
                    if (!$res['confident']) {
                        $flagged++;
                        if ($collectFixes && ($limit === null || count($flaggedRows) < $limit)) {
                            // Candidate artists (each cleaned side of the name) so
                            // the UI can offer one-click buttons to hand-fill.
                            $cands = [];
                            $seg = ProductNameNormalizer::nameSegments($r->name);
                            if ($seg !== null) {
                                foreach ([$seg[0], $seg[1]] as $s) {
                                    $c = trim((string) ProductNameNormalizer::cleanArtistValue($s));
                                    if ($c !== '') { $cands[] = $c; }
                                }
                                $cands = array_values(array_unique($cands));
                            }
                            $flaggedRows[] = [
                                'id' => (int) $r->id,
                                'name' => $r->name,
                                'reason' => $res['reason'],
                                'sku' => trim((string) ($r->sku ?? '')),
                                'candidates' => $cands,
                            ];
                        }
                        continue;
                    }
                    $totalToFill++;
                    // Filter by the PARSED artist (prefix match) so the caller can
                    // work alphabetically or search a name.
                    if ($filter !== '' && strpos(mb_strtolower($res['artist']), $filter) !== 0) {
                        continue;
                    }
                    $toFill++;
                    // Collect all matches (a letter/prefix subset is small); we
                    // sort by parsed artist and slice to `limit` after the walk so
                    // same-name artists group together and the page is alphabetical.
                    if ($collectFixes) {
                        $fixes[] = [
                            'id' => (int) $r->id,
                            'name' => $r->name,
                            'old' => (string) ($r->artist ?? ''),
                            'new' => $res['artist'],
                            'source' => $res['source'],
                            'trust' => $res['trust'] ?? 'ok',
                            'sealed' => isset($sealedIds[(int) $r->category_id]),
                            'sku' => trim((string) ($r->sku ?? '')),
                        ];
                    }
                }
            });

        if ($collectFixes) {
            // Group ALL same-artist rows together, alphabetical, so a whole artist
            // can be selected in one shot. Sealed vinyl floats to the top WITHIN
            // each artist (kept together, not split off).
            usort($fixes, function ($a, $b) {
                $c = strcasecmp($a['new'], $b['new']);
                if ($c !== 0) { return $c; }
                if ($a['sealed'] !== $b['sealed']) { return $a['sealed'] ? -1 : 1; }
                return strcasecmp($a['name'], $b['name']);
            });
            if ($limit !== null) { $fixes = array_slice($fixes, 0, $limit); }
        }

        return ['fixes' => $fixes, 'flagged_rows' => $flaggedRows, 'to_fill' => $toFill, 'total_to_fill' => $totalToFill, 'flagged' => $flagged, 'cat_ids' => $catIds];
    }

    public function artistScan(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        if (!$this->isOwner()) {
            return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        }
        $business_id = $request->session()->get('user.business_id');
        $filter = (string) $request->input('filter', '');
        try {
            // Show a big page so whole artists group on screen and can be
            // bulk-selected in one shot (grouped alphabetically by parsed artist).
            $data = $this->computeArtistBackfill($business_id, true, 300, $filter);
            $byCategory = $this->artistlessByCategory($business_id, $data['cat_ids']);
        } catch (\Throwable $e) {
            \Log::error('artist-backfill scan failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'msg' => 'Scan failed: ' . $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'to_fill' => $data['to_fill'],
            'total_to_fill' => $data['total_to_fill'],
            'flagged' => $data['flagged'],
            'preview' => $data['fixes'],
            'flagged_preview' => $data['flagged_rows'],
            'by_category' => $byCategory,
            'filter' => $filter,
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

        $knownKeys = $this->artistSignalKeys($business_id, $catIds);

        // If the UI sent a specific set of product ids (checked rows), fill only
        // those; otherwise fall back to filling up to `max` confident parses.
        $ids = $request->input('ids');
        $selected = is_array($ids)
            ? array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, 5000)
            : null;

        // only=high fills ONLY the structural parses (surname-first "LAST,FIRST"
        // and compilations) — the ones that don't need a human eye — across the
        // whole catalog in batches, so they never have to be reviewed by hand.
        $onlyHigh = $request->input('only') === 'high';

        $batch = [];
        $query = $this->artistlessMusicQuery($business_id, $catIds)
            ->select('id', 'name', 'artist')
            ->orderBy('id');
        if ($selected !== null) { $query->whereIn('id', $selected); }
        $cap = $selected !== null ? count($selected) : $max;
        if ($cap < 1) { $cap = 1; }
        $query->chunk(2000, function ($rows) use (&$batch, $cap, $knownKeys, $onlyHigh) {
            foreach ($rows as $r) {
                $res = $this->parseArtistFromRow($r->name, $r->artist, $knownKeys);
                if (!$res['confident']) { continue; }
                if ($onlyHigh && ($res['trust'] ?? 'ok') !== 'high') { continue; }
                $batch[] = [
                    'id' => (int) $r->id,
                    'old' => (string) ($r->artist ?? ''),
                    'new' => $res['artist'],
                ];
                if (count($batch) >= $cap) { return false; }
            }
        });

        if (empty($batch)) {
            return response()->json(['success' => true, 'filled' => 0, 'remaining' => 0, 'msg' => 'Nothing left to fill.']);
        }

        $timestamp = now()->format('Y-m-d_His');
        $filled = 0;
        $filledIds = [];

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
                if ($affected) { $filled++; $filledIds[] = $b['id']; }
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

        // The full remaining recount walks the whole catalog — skip it during the
        // auto-fill loop (it calls back many times and only cares about `filled`).
        $remaining = $onlyHigh ? null : $this->computeArtistBackfill($business_id, false)['to_fill'];

        return response()->json([
            'success' => true,
            'filled' => $filled,
            'filled_ids' => $filledIds,
            'remaining' => $remaining,
            'msg' => $remaining === null ? "Filled {$filled}." : "Filled {$filled}. {$remaining} remaining.",
        ]);
    }

    /**
     * Write EXPLICIT artist values the user typed/picked for flagged products
     * (the ones the parser couldn't do). Body: rows = [{id, artist}, ...]. Only
     * fills rows whose artist is still blank/"N/A"-ish; snapshotted + undoable
     * via the same backfill-artist-from-name path.
     */
    public function artistManualApply(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        if (!$this->isOwner()) {
            return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        }
        $business_id = $request->session()->get('user.business_id');

        $rows = $request->input('rows');
        if (!is_array($rows)) {
            return response()->json(['success' => false, 'msg' => 'No rows sent.']);
        }
        // Clean + cap the incoming edits.
        $edits = [];
        foreach (array_slice($rows, 0, 2000) as $row) {
            $id = (int) ($row['id'] ?? 0);
            $artist = trim((string) ($row['artist'] ?? ''));
            if ($id < 1 || $artist === '' || mb_strlen($artist) > 120) { continue; }
            $edits[$id] = $artist;
        }
        if (empty($edits)) {
            return response()->json(['success' => true, 'filled' => 0, 'msg' => 'Nothing to fill.']);
        }

        // Snapshot current (blank) values for undo.
        $current = \DB::table('products')->where('business_id', $business_id)
            ->whereIn('id', array_keys($edits))->pluck('artist', 'id')->toArray();

        $timestamp = now()->format('Y-m-d_His');
        $filled = 0;
        $snapshotRows = [];
        \DB::beginTransaction();
        try {
            foreach ($edits as $id => $artist) {
                $affected = \DB::table('products')
                    ->where('id', $id)
                    ->where('business_id', $business_id)
                    ->where(function ($q) {
                        $q->whereNull('artist')
                          ->orWhereRaw("TRIM(artist) = ''")
                          ->orWhereRaw("LOWER(TRIM(artist)) REGEXP '^(n/?a|unknown|various|none|no artist)$'");
                    })
                    ->update(['artist' => $artist]);
                if ($affected) {
                    $filled++;
                    $snapshotRows[] = ['id' => $id, 'old' => (string) ($current[$id] ?? ''), 'new' => $artist];
                }
            }
            if (!empty($snapshotRows)) {
                \Storage::disk('local')->put(
                    "admin-snapshots/backfill-artist-from-name-{$timestamp}.json",
                    json_encode([
                        'timestamp' => $timestamp,
                        'action' => 'backfill-artist-from-name',
                        'user_id' => auth()->id(),
                        'business_id' => $business_id,
                        'source_name' => $filled . ' artist(s) entered by hand',
                        'target_name' => 'products.artist',
                        'rows' => $snapshotRows,
                    ], JSON_PRETTY_PRINT)
                );
            }
            \DB::commit();
        } catch (\Throwable $e) {
            \DB::rollBack();
            \Log::emergency('artist-manual-apply failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'msg' => 'Fill failed — nothing changed.']);
        }

        return response()->json([
            'success' => true,
            'filled' => $filled,
            'filled_ids' => array_column($snapshotRows, 'id'),
            'msg' => "Filled {$filled} by hand.",
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

    /** True artist string from a Discogs release object (null if none). */
    protected function artistFromRelease($data)
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
        return $artist === '' ? null : $artist;
    }

    /** True artist + title from a Discogs release object -> "Artist - Title". */
    protected function nameFromRelease($data)
    {
        $artist = $this->artistFromRelease($data);
        $title = $data ? trim((string) ((object) $data)->title ?? '') : '';
        if (!$artist || $title === '') { return null; }

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

        // Two passes so sealed vinyl (the most valuable stock) gets rebuilt first,
        // then everything else. The client runs 'sealed' to completion, then 'rest'.
        $phase = $request->input('phase') === 'rest' ? 'rest' : 'sealed';
        $sealedIds = $this->sealedVinylCategoryIds($business_id);
        // No sealed-vinyl categories at all -> skip straight to the rest.
        if ($phase === 'sealed' && empty($sealedIds)) {
            return response()->json(['success' => true, 'renamed' => 0, 'failed' => 0, 'rate_limited' => false, 'done' => true, 'after_id' => 0, 'remaining' => 0, 'phase' => 'sealed']);
        }
        $scope = function ($q) use ($phase, $sealedIds) {
            if (empty($sealedIds)) { return $q; }
            return $phase === 'sealed' ? $q->whereIn('category_id', $sealedIds) : $q->whereNotIn('category_id', $sealedIds);
        };

        $rows = $scope($this->candidateQuery($business_id))
            ->where('id', '>', $afterId)
            ->select('id', 'name', 'discogs_release_id')
            ->orderBy('id')->limit($max)->get();

        if ($rows->isEmpty()) {
            return response()->json(['success' => true, 'renamed' => 0, 'failed' => 0, 'rate_limited' => false, 'done' => true, 'after_id' => $afterId, 'remaining' => 0, 'phase' => $phase]);
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

        $remaining = $scope($this->candidateQuery($business_id))->where('id', '>', $lastId)->count();

        return response()->json([
            'success' => true,
            'renamed' => $renamed,
            'failed' => $failed,
            'rate_limited' => $rateLimited,
            'after_id' => $lastId,
            'remaining' => $remaining,
            'done' => ($remaining === 0 && !$rateLimited),
            'phase' => $phase,
        ]);
    }

    /**
     * Fill the ARTIST COLUMN (not the name) from Discogs for music products that
     * have a blank/"N/A" artist AND a discogs_release_id — the accurate, no-guess
     * version of the name-parse backfill. Two passes, sealed vinyl first, cursor-
     * paged by id, rate-limited, undoable via the same backfill-artist-from-name
     * snapshot (restores products.artist).
     */
    public function discogsArtistFill(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        if (!$this->isOwner()) {
            return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        }
        if (!\Schema::hasColumn('products', 'discogs_release_id')) {
            return response()->json(['success' => false, 'msg' => 'No discogs_release_id column on products.']);
        }
        $business_id = $request->session()->get('user.business_id');
        $afterId = (int) $request->input('after_id', 0);
        $max = (int) $request->input('max', 20);
        if ($max < 1 || $max > 40) { $max = 20; }

        $svc = new DiscogsService($business_id);
        if (!$svc->isConfigured()) {
            return response()->json(['success' => false, 'msg' => 'Discogs API token not configured (Business Settings > Integrations).']);
        }

        $catIds = $this->musicCategoryIds($business_id);
        if (empty($catIds)) {
            return response()->json(['success' => true, 'filled' => 0, 'failed' => 0, 'rate_limited' => false, 'done' => true, 'after_id' => 0, 'remaining' => 0, 'phase' => 'sealed']);
        }

        $phase = $request->input('phase') === 'rest' ? 'rest' : 'sealed';
        $sealedIds = $this->sealedVinylCategoryIds($business_id);
        if ($phase === 'sealed' && empty($sealedIds)) {
            return response()->json(['success' => true, 'filled' => 0, 'failed' => 0, 'rate_limited' => false, 'done' => true, 'after_id' => 0, 'remaining' => 0, 'phase' => 'sealed']);
        }
        $scope = function ($q) use ($phase, $sealedIds) {
            if (empty($sealedIds)) { return $q; }
            return $phase === 'sealed' ? $q->whereIn('category_id', $sealedIds) : $q->whereNotIn('category_id', $sealedIds);
        };

        // Blank/N-A artist music products that have a Discogs release id.
        $base = function () use ($business_id, $catIds, $scope) {
            return $scope($this->artistlessMusicQuery($business_id, $catIds)
                ->whereNotNull('discogs_release_id')
                ->where('discogs_release_id', '>', 0));
        };

        $rows = $base()
            ->where('id', '>', $afterId)
            ->select('id', 'name', 'artist', 'discogs_release_id')
            ->orderBy('id')->limit($max)->get();

        if ($rows->isEmpty()) {
            return response()->json(['success' => true, 'filled' => 0, 'failed' => 0, 'rate_limited' => false, 'done' => true, 'after_id' => $afterId, 'remaining' => 0, 'phase' => $phase]);
        }

        $timestamp = now()->format('Y-m-d_His');
        $changes = [];
        $failed = 0;
        $rateLimited = false;
        $lastId = $afterId;

        foreach ($rows as $r) {
            $res = $svc->getReleaseById($r->discogs_release_id);
            if (!empty($res['error'])) {
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
                $artist = $this->artistFromRelease($res['data'] ?? null);
                // Only write a REAL single artist. Skip Discogs' "Various" —
                // filling comps with "Various Artists" isn't useful and just
                // clutters; leave those blank.
                if ($artist !== null && !preg_match('/^various\b/i', trim($artist))) {
                    $changes[] = ['id' => (int) $r->id, 'old' => (string) ($r->artist ?? ''), 'new' => $artist];
                }
            }
            usleep(1100000); // ~55 calls/min, under Discogs' 60/min ceiling
        }

        $filled = 0;
        if (!empty($changes)) {
            \DB::beginTransaction();
            try {
                foreach ($changes as $c) {
                    // Only write if still blank/"N/A"-ish, so a concurrent edit isn't clobbered.
                    $affected = \DB::table('products')
                        ->where('id', $c['id'])
                        ->where(function ($q) {
                            $q->whereNull('artist')
                              ->orWhereRaw("TRIM(artist) = ''")
                              ->orWhereRaw("LOWER(TRIM(artist)) REGEXP '^(n/?a|unknown|various|none|no artist)$'");
                        })
                        ->update(['artist' => $c['new']]);
                    if ($affected) { $filled++; }
                }
                \Storage::disk('local')->put(
                    "admin-snapshots/backfill-artist-from-name-{$timestamp}.json",
                    json_encode([
                        'timestamp' => $timestamp,
                        'action' => 'backfill-artist-from-name',
                        'user_id' => auth()->id(),
                        'business_id' => $business_id,
                        'source_name' => $filled . ' artist(s) from Discogs',
                        'target_name' => 'products.artist',
                        'rows' => $changes,
                    ], JSON_PRETTY_PRINT)
                );
                \DB::commit();
            } catch (\Throwable $e) {
                \DB::rollBack();
                if (\Storage::disk('local')->exists("admin-snapshots/backfill-artist-from-name-{$timestamp}.json")) {
                    \Storage::disk('local')->delete("admin-snapshots/backfill-artist-from-name-{$timestamp}.json");
                }
                return response()->json(['success' => false, 'msg' => 'Fill failed — nothing changed this batch.']);
            }
        }

        $remaining = $base()->where('id', '>', $lastId)->count();

        return response()->json([
            'success' => true,
            'filled' => $filled,
            'failed' => $failed,
            'rate_limited' => $rateLimited,
            'after_id' => $lastId,
            'remaining' => $remaining,
            'done' => ($remaining === 0 && !$rateLimited),
            'phase' => $phase,
        ]);
    }

    /**
     * Preview for discogsArtistFill: how many blank-artist products have a Discogs
     * id, plus a small live sample (sealed vinyl first) of "name -> Discogs artist"
     * so Sarah can see what it will write before committing.
     */
    public function discogsArtistScan(Request $request)
    {
        @set_time_limit(0);
        if (!$this->isOwner()) {
            return response()->json(['success' => false, 'msg' => 'Owner-only.'], 403);
        }
        if (!\Schema::hasColumn('products', 'discogs_release_id')) {
            return response()->json(['success' => false, 'msg' => 'No discogs_release_id column on products.']);
        }
        $business_id = $request->session()->get('user.business_id');
        $catIds = $this->musicCategoryIds($business_id);
        if (empty($catIds)) {
            return response()->json(['success' => true, 'total' => 0, 'sample' => []]);
        }
        $svc = new DiscogsService($business_id);
        if (!$svc->isConfigured()) {
            return response()->json(['success' => false, 'msg' => 'Discogs API token not configured (Business Settings > Integrations).']);
        }

        $sealedIds = $this->sealedVinylCategoryIds($business_id);
        $base = function () use ($business_id, $catIds) {
            return $this->artistlessMusicQuery($business_id, $catIds)
                ->whereNotNull('discogs_release_id')->where('discogs_release_id', '>', 0);
        };
        $total = $base()->count();

        // Sample sealed vinyl first, top up from the rest if fewer than 10.
        $q = empty($sealedIds) ? $base() : $base()->whereIn('category_id', $sealedIds);
        $rows = $q->select('id', 'name', 'discogs_release_id')->orderBy('id')->limit(10)->get();
        if ($rows->count() < 10 && !empty($sealedIds)) {
            $more = $base()->whereNotIn('category_id', $sealedIds)
                ->select('id', 'name', 'discogs_release_id')->orderBy('id')->limit(10 - $rows->count())->get();
            $rows = $rows->concat($more);
        }

        $sample = [];
        foreach ($rows as $r) {
            if (stripos($r->name, 'retired') !== false) { continue; }
            $res = $svc->getReleaseById($r->discogs_release_id);
            if (!empty($res['error'])) { continue; }
            $artist = $this->artistFromRelease($res['data'] ?? null);
            if ($artist !== null && !preg_match('/^various\b/i', trim($artist))) {
                $sample[] = ['name' => $r->name, 'artist' => $artist];
            }
            usleep(1100000);
        }

        return response()->json(['success' => true, 'total' => $total, 'sample' => $sample]);
    }
}
