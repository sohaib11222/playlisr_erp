<?php

namespace App\Console\Commands;

use App\Category;
use App\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Bulk-reassign products by artist out of one sub-category and into another.
 * Data lives in self::CHANGES below — one tuple per (artist, from, to).
 *
 * Sourced from change.xlsx (May 2026 cleanup pass): the source sheet listed
 * one album per row with the wrong + right genre; the request was to match
 * by ARTIST only, not by album. Multiple rows for the same artist with
 * different "from" sub-categories are kept as separate entries because each
 * triggers a separate UPDATE.
 *
 * Artist matching is fuzzy on purpose (the source uses "WEEKND" where the DB
 * has "The Weeknd", "BJORK" where DB might have "Björk", "JACKSON,MICHAEL"
 * where DB has "Michael Jackson"). See normalizeArtist() for the rules.
 *
 * Sub-category matching is exact-after-normalization (whitespace + punct
 * stripped, lowercased) so "Alt /Indie Rock" vs "Alt/Indie Rock" and
 * "Hip-Hop" vs "Hip Hop" both resolve the same way.
 *
 * Dry-run by default. Re-run with --commit to write.
 *
 * Usage:
 *   php artisan nivessa:reassign-misfiled-artists
 *   php artisan nivessa:reassign-misfiled-artists --commit
 */
class ReassignMisfiledArtists extends Command
{
    protected $signature = 'nivessa:reassign-misfiled-artists
                            {--business=1 : business_id to scope to}
                            {--commit : Actually write (default: dry-run)}';

    protected $description = 'Bulk reassign products by artist from one sub-category to another (data inline).';

    /** [artist, from_subcategory, to_subcategory] */
    const CHANGES = [
        ['Michael Jackson',     'Electronic/Dance',  'Pop'],
        ['KATSEYE',             'R&B, Soul & Funk',  'K-Pop'],
        ['KATSEYE',             'Rock',              'K-Pop'],
        ['KATSEYE',             'Blues',             'K-Pop'],
        ['Dream Theater',       'Rock',              'Metal'],
        ['Bjork',               'Alt /Indie Rock',   'Electronic'],
        ['Taylor Swift',        'Country',           'Pop'],
        ['Taylor Swift',        'Alt /Indie Rock',   'Pop'],
        ['Paramore',            'Rock',              'Alt Rock'],
        // Source sheet had two MJ "Blues → ?" rows (THIS IS IT → Pop,
        // OFF THE WALL → R&B). Operator chose Pop for the whole bucket.
        ['Michael Jackson',     'Blues',             'Pop'],
        ['The Cure',            'Rock',              'New Wave/Post Punk'],
        ['Sabrina Carpenter',   'Rock',              'Pop'],
        ['Pierce The Veil',     'Rock',              'Punk'],
        ['ILLIT',               'Electronic/Dance',  'K-Pop'],
        ['Twenty One Pilots',   'Rock',              'Punk'],
        ['Notorious B.I.G',     'Pop',               'Hip-Hop'],
        ['Harry Styles',        'Electronic/Dance',  'Pop'],
        ['Sade',                'Jazz',              'R&B'],
        ['Ariana Grande',       'R&B',               'Pop'],
        ['Eazy-E',              'Electronic/Dance',  'Hip Hop'],
        ['Dead Kennedys',       'Rock',              'Punk'],
        ['The Weeknd',          'R&B, Soul & Funk',  'Pop'],
        ['Outkast',             'R&B, Soul & Funk',  'Hip Hop'],
        ['Billie Eilish',       'Jazz',              'Pop'],
        ['Karol G',             'Rock',              'Latin'],
        ['Radiohead',           'Rock',              'Alt/Indie Rock'],
        ['Green Day',           'Alt /Indie Rock',   'Punk'],
        ['My Chemical Romance', 'Rock',              'Punk'],
        ['Miley Cyrus',         'R&B, Soul & Funk',  'Pop'],
        ['Depeche Mode',        'Electronic/Dance',  'New Wave/Post Punk'],
        ['Iggy & The Stooges',  'Rock',              'Punk'],
        ['Clairo',              'Jazz',              'Pop'],
    ];

    /**
     * Two Michael Jackson "Blues → ..." rows in the source point to two
     * different targets (Pop on row 8, R&B on row 38). Same artist + same
     * "from" can't fan out to two targets in a single pass, so when we hit
     * an ambiguous (artist, from) we ask the operator to pick — listed
     * here so the choice is explicit and reviewable.
     */
    const AMBIGUITY_RESOLUTIONS = [
        // 'artist|from' => 'chosen to'
        // (leave empty; if a collision occurs the command bails with a hint)
    ];

    public function handle()
    {
        $businessId = (int) $this->option('business');
        $commit     = (bool) $this->option('commit');

        $this->info($commit
            ? '** COMMIT mode — changes WILL be written **'
            : '** DRY-RUN mode — no changes written. Pass --commit to apply. **');
        $this->newLine();

        // Detect any (artist, from) that maps to multiple distinct targets.
        $byArtistFrom = [];
        foreach (self::CHANGES as [$artist, $from, $to]) {
            $key = mb_strtolower(trim($artist)) . '|' . $this->normalizeName($from);
            $byArtistFrom[$key][] = $to;
        }
        $conflicts = [];
        foreach ($byArtistFrom as $key => $targets) {
            $distinct = array_unique(array_map(fn ($t) => $this->normalizeName($t), $targets));
            if (count($distinct) > 1) {
                $conflicts[$key] = $targets;
            }
        }
        if (!empty($conflicts)) {
            $this->error('Conflicting target sub-categories for the same (artist, from) pair:');
            foreach ($conflicts as $key => $targets) {
                $this->line("  {$key} → " . implode(' / ', array_unique($targets)));
            }
            $this->line('Pick one per pair (edit CHANGES) and re-run.');
            return 1;
        }

        // Cache: sub-category name → Category (or false if unresolved/ambiguous).
        $subCatCache = [];
        $resolveSub = function (string $name) use (&$subCatCache, $businessId) {
            $key = $this->normalizeName($name);
            if (array_key_exists($key, $subCatCache)) return $subCatCache[$key];
            $matches = Category::where('business_id', $businessId)
                ->where('category_type', 'product')
                ->where('parent_id', '!=', 0)
                ->get(['id', 'name', 'parent_id'])
                ->filter(fn ($c) => $this->normalizeName($c->name) === $key);
            if ($matches->isEmpty())  return $subCatCache[$key] = null;
            if ($matches->count() > 1) return $subCatCache[$key] = 'AMBIGUOUS';
            return $subCatCache[$key] = $matches->first();
        };

        // Cache: distinct DB artists by from-subcat, so we don't re-query.
        // Key: from_subcat_id → [ ['id'=>..., 'artist'=>...], ... ].
        $productsByFrom = [];

        $totals = ['rows' => 0, 'no_from' => 0, 'no_to' => 0, 'no_match' => 0, 'matched' => 0, 'updated' => 0];
        $perRow = [];

        foreach (self::CHANGES as [$artistRaw, $fromName, $toName]) {
            $totals['rows']++;
            $from = $resolveSub($fromName);
            $to   = $resolveSub($toName);

            if ($from === null || $from === 'AMBIGUOUS') {
                $totals['no_from']++;
                $perRow[] = compact('artistRaw', 'fromName', 'toName') + ['status' => 'SKIP: from not found', 'count' => 0];
                continue;
            }
            if ($to === null || $to === 'AMBIGUOUS') {
                $totals['no_to']++;
                $perRow[] = compact('artistRaw', 'fromName', 'toName') + ['status' => 'SKIP: to not found', 'count' => 0];
                continue;
            }
            if ($from->id === $to->id) {
                $perRow[] = compact('artistRaw', 'fromName', 'toName') + ['status' => 'SKIP: from == to', 'count' => 0];
                continue;
            }

            // Pull all products in this from-subcat (memoized).
            if (!isset($productsByFrom[$from->id])) {
                $productsByFrom[$from->id] = DB::table('products')
                    ->where('business_id', $businessId)
                    ->where('sub_category_id', $from->id)
                    ->whereNotNull('artist')
                    ->where('artist', '!=', '')
                    ->get(['id', 'artist', 'name'])
                    ->all();
            }
            $candidates = $productsByFrom[$from->id];

            $needle = $this->normalizeArtist($artistRaw);
            $matches = [];
            foreach ($candidates as $p) {
                if ($this->artistMatches($needle, $p->artist)) {
                    $matches[] = $p;
                }
            }

            if (empty($matches)) {
                $totals['no_match']++;
                $perRow[] = compact('artistRaw', 'fromName', 'toName') + ['status' => 'no products', 'count' => 0];
                continue;
            }

            $totals['matched'] += count($matches);
            $perRow[] = compact('artistRaw', 'fromName', 'toName') + [
                'status'  => 'match',
                'count'   => count($matches),
                'from_id' => $from->id,
                'to_id'   => $to->id,
                'samples' => array_slice(array_map(fn ($p) => "#{$p->id} {$p->artist} — {$p->name}", $matches), 0, 3),
            ];

            if ($commit) {
                $ids = array_map(fn ($p) => $p->id, $matches);
                $n = DB::table('products')
                    ->whereIn('id', $ids)
                    ->update(['sub_category_id' => $to->id, 'updated_at' => now()]);
                $totals['updated'] += $n;

                // Invalidate the cache for both sub-cats so subsequent rows see
                // the moved products in their new home.
                unset($productsByFrom[$from->id], $productsByFrom[$to->id]);
            }
        }

        // Per-row report
        $this->line(str_pad('artist', 22) . str_pad('from', 22) . str_pad('to', 22) . str_pad('count', 7) . 'status');
        $this->line(str_repeat('-', 100));
        foreach ($perRow as $r) {
            $this->line(
                str_pad($this->trunc($r['artistRaw'], 20), 22)
                . str_pad($this->trunc($r['fromName'], 20), 22)
                . str_pad($this->trunc($r['toName'], 20), 22)
                . str_pad((string) $r['count'], 7)
                . $r['status']
            );
            if (!empty($r['samples'])) {
                foreach ($r['samples'] as $s) {
                    $this->line('    · ' . $this->trunc($s, 90));
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            "Rows: %d  |  matched products: %d  |  updated: %d  |  skipped (no from): %d / (no to): %d / (no match): %d",
            $totals['rows'], $totals['matched'], $totals['updated'],
            $totals['no_from'], $totals['no_to'], $totals['no_match']
        ));

        if (!$commit) {
            $this->warn('DRY RUN — no rows written. Re-run with --commit to apply.');
        }
        return 0;
    }

    /** lowercase + strip non-alphanumerics — for sub-category name compare. */
    private function normalizeName(string $s): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s));
    }

    /**
     * Artist normalization for fuzzy match:
     *   - swap "LAST,FIRST" → "FIRST LAST"
     *   - lowercase, strip diacritics, drop punctuation
     *   - drop a leading "the "
     *   - collapse whitespace
     */
    private function normalizeArtist(string $s): string
    {
        $s = trim($s);
        // LAST,FIRST → FIRST LAST (only when there's exactly one comma and no space-after-comma weirdness like "JR.")
        if (substr_count($s, ',') === 1) {
            [$last, $first] = array_map('trim', explode(',', $s, 2));
            if ($last !== '' && $first !== '' && !preg_match('/\s/', $last)) {
                $s = $first . ' ' . $last;
            }
        }
        $s = mb_strtolower($s);
        // Strip diacritics (Björk → bjork)
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
        $s = preg_replace('/\s+/', ' ', trim($s));
        // Drop leading "the "
        if (str_starts_with($s, 'the ')) {
            $s = substr($s, 4);
        }
        return $s;
    }

    /** Fuzzy artist match: equal after normalize, or one is whole-word substring of the other. */
    private function artistMatches(string $needleNormalized, string $candidate): bool
    {
        $cand = $this->normalizeArtist($candidate);
        if ($cand === '') return false;
        if ($cand === $needleNormalized) return true;
        // Whole-word substring either direction (e.g. "weeknd" ↔ "the weeknd" already
        // both reduce to "weeknd", but this catches "iggy stooges" ↔ "iggy the stooges").
        $pat = '/(^|\s)' . preg_quote($needleNormalized, '/') . '(\s|$)/';
        if (preg_match($pat, $cand)) return true;
        $pat = '/(^|\s)' . preg_quote($cand, '/') . '(\s|$)/';
        if (preg_match($pat, $needleNormalized)) return true;
        return false;
    }

    private function trunc(string $s, int $n): string
    {
        return mb_strlen($s) <= $n ? $s : (mb_substr($s, 0, $n - 1) . '…');
    }
}
