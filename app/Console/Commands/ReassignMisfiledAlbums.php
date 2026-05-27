<?php

namespace App\Console\Commands;

use App\Category;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Per-album sub-category cleanup. Each rule in self::CHANGES targets ONE
 * album (artist + album title) and moves matching products from one
 * sub-category to another. Multiple physical variants of the same album
 * (LP, CD, picture disc, etc.) all get moved together.
 *
 * Sourced from the May 2026 misfiled-genre sheet. The previous pass
 * tried to match by artist only, which over-applied (e.g., Michael
 * Jackson "Blues" had two albums that needed two different targets).
 * Album-level matching resolves those naturally.
 *
 * Fuzzy matching:
 *  - Artist: normalized exact match (handles diacritics, "The", LAST,FIRST,
 *    punctuation, &). Sade only matches "Sade", not "Sadeen".
 *  - Album: normalized substring match either direction. Parentheticals
 *    are stripped (so "(Picture Disc)", "(Deluxe Edition)", "(X)" don't
 *    matter), and the source's truncated titles like "...FABULOU" still
 *    match the catalog's full "...Fabulous Killjoys".
 *
 * Dry-run by default. Re-run with --commit to write.
 */
class ReassignMisfiledAlbums extends Command
{
    protected $signature = 'nivessa:reassign-misfiled-albums
                            {--business=1 : business_id to scope to}
                            {--commit : Actually write (default: dry-run)}';

    protected $description = 'Per-album sub-category fixes for the misfiled-genre cleanup pass.';

    /** [artist, album, from_subcategory, to_subcategory] */
    const CHANGES = [
        ['JACKSON,MICHAEL',     'BAD (PICTURE DISC)',                                'Electronic/Dance',  'Pop'],
        ['KATSEYE',             'BEAUTIFUL CHAOS',                                   'R&B, Soul & Funk',  'K-Pop'],
        ['KATSEYE',             'SIS',                                               'Rock',              'K-Pop'],
        ['DREAM THEATER',       'IMAGES & WORDS',                                    'Rock',              'Metal'],
        ['BJORK',               'HOMOGENIC',                                         'Alt /Indie Rock',   'Electronic'],
        ['SWIFT,TAYLOR',        'FEARLESS',                                          'Country',           'Pop'],
        ['PARAMORE',            'ALL WE KNOW IS FALLING',                            'Rock',              'Alt Rock'],
        ['JACKSON,MICHAEL',     "MICHAEL JACKSON'S THIS IS IT",                      'Blues',             'Pop'],
        ['CURE',                'WISH (30TH ANNIVERSARY REMASTER)',                  'Rock',              'New Wave/Post Punk'],
        ['SWIFT,TAYLOR',        'REPUTATION',                                        'Alt /Indie Rock',   'Pop'],
        ['CURE',                'DISINTEGRATION (REMASTERED)',                       'Rock',              'New Wave/Post Punk'],
        ['CARPENTER,SABRINA',   'SHORT N SWEET (X) (DELUXE EDITION)',                'Rock',              'Pop'],
        ['PIERCE THE VEIL',     'SELFISH MACHINES',                                  'Rock',              'Punk'],
        ['ILLIT',               'SUPER REAL ME: 1ST MINI ALBUM',                     'Electronic/Dance',  'K-Pop'],
        ['TWENTY ONE PILOTS',   'MTV UNPLUGGED',                                     'Rock',              'Punk'],
        ['NOTORIOUS B.I.G',     'GREATEST HITS',                                     'Pop',               'Hip-Hop'],
        ['PIERCE THE VEIL',     'FLAIR FOR THE DRAMATIC',                            'Rock',              'Punk'],
        ['STYLES,HARRY',        'KISS ALL THE TIME. DISCO, OCCASIONALLY',            'Electronic/Dance',  'Pop'],
        ['SADE',                'BEST OF SADE',                                      'Jazz',              'R&B'],
        ['GRANDE,ARIANA',       'THANK U NEXT',                                      'R&B',               'Pop'],
        // duplicate of previous row in the source sheet — kept for transparency; second pass is a no-op
        ['GRANDE,ARIANA',       'THANK U NEXT',                                      'R&B',               'Pop'],
        ['EAZY-E',              'STR8 OFF THE STREETS OF MUTHAPHUKKIN',              'Electronic/Dance',  'Hip Hop'],
        ['DEAD KENNEDYS',       'GIVE ME CONVENIENCE',                               'Rock',              'Punk'],
        ['WEEKND',              'KISS LAND',                                         'R&B, Soul & Funk',  'Pop'],
        ['OUTKAST',             'SPEAKERBOXXX/THE LOVE BELOW',                       'R&B, Soul & Funk',  'Hip Hop'],
        ['CARPENTER,SABRINA',   'SINGULAR: ACT I',                                   'Rock',              'Pop'],
        ['EILISH,BILLIE',       'HAPPIER THAN EVER (X)',                             'Jazz',              'Pop'],
        ['KAROL G',             'TROPICOQUETA',                                      'Rock',              'Latin'],
        ['RADIOHEAD',           'PABLO HONEY',                                       'Rock',              'Alt/Indie Rock'],
        ['GREEN DAY',           'NIMROD',                                            'Alt /Indie Rock',   'Punk'],
        ['MY CHEMICAL ROMANCE', 'DANGER DAYS: TRUE LIVES OF THE FABULOU',            'Rock',              'Punk'],
        ['KATSEYE',             'BEAUTIFUL CHAOS',                                   'Blues',             'K-Pop'],
        ['CYRUS,MILEY',         'BANGERZ',                                           'R&B, Soul & Funk',  'Pop'],
        ['DEPECHE MODE',        'VIOLATOR',                                          'Electronic/Dance',  'New Wave/Post Punk'],
        ['IGGY & THE STOOGES',  'RAW POWER',                                         'Rock',              'Punk'],
        ['MY CHEMICAL ROMANCE', 'BLACK PARADE',                                      'Rock',              'Punk'],
        ['CLAIRO',              'CHARM',                                             'Jazz',              'Pop'],
        ['JACKSON,MICHAEL',     'OFF THE WALL',                                      'Blues',             'R&B'],
    ];

    public function handle()
    {
        $businessId = (int) $this->option('business');
        $commit     = (bool) $this->option('commit');

        $this->info($commit
            ? '** COMMIT mode — changes WILL be written **'
            : '** DRY-RUN mode — no changes written. Pass --commit to apply. **');
        $this->newLine();

        // Cache: sub-category name → Category model (or null/AMBIGUOUS).
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

        // Group rows by from-subcategory so we only pull candidates once per group.
        $groups = [];
        foreach (self::CHANGES as $row) {
            $groups[$row[2]][] = $row;
        }

        $totals = ['rows' => 0, 'no_from' => 0, 'no_to' => 0, 'no_match' => 0, 'matched_products' => 0, 'updated' => 0];
        $report = [];

        foreach ($groups as $fromName => $rows) {
            $fromCat = $resolveSub($fromName);

            $candidates = null;
            if ($fromCat instanceof Category) {
                $candidates = DB::table('products')
                    ->where('business_id', $businessId)
                    ->where('sub_category_id', $fromCat->id)
                    ->whereNotNull('artist')
                    ->where('artist', '!=', '')
                    ->get(['id', 'artist', 'name'])
                    ->all();
            }

            foreach ($rows as [$artistRaw, $albumRaw, $from, $toName]) {
                $totals['rows']++;

                if ($fromCat === null || $fromCat === 'AMBIGUOUS') {
                    $totals['no_from']++;
                    $report[] = ['artist' => $artistRaw, 'album' => $albumRaw, 'from' => $from, 'to' => $toName, 'count' => 0, 'status' => 'SKIP: from sub-cat not found'];
                    continue;
                }

                $toCat = $resolveSub($toName);
                if ($toCat === null || $toCat === 'AMBIGUOUS') {
                    $totals['no_to']++;
                    $report[] = ['artist' => $artistRaw, 'album' => $albumRaw, 'from' => $from, 'to' => $toName, 'count' => 0, 'status' => 'SKIP: to sub-cat not found'];
                    continue;
                }
                if ($fromCat->id === $toCat->id) {
                    $report[] = ['artist' => $artistRaw, 'album' => $albumRaw, 'from' => $from, 'to' => $toName, 'count' => 0, 'status' => 'SKIP: from == to'];
                    continue;
                }

                $artistN = $this->normalizeArtist($artistRaw);
                $albumN  = $this->normalizeAlbum($albumRaw);

                $matches = [];
                foreach ($candidates as $p) {
                    if ($this->normalizeArtist($p->artist) !== $artistN) continue;
                    if (!$this->albumMatches($albumN, $p->name)) continue;
                    $matches[] = $p;
                }

                if (empty($matches)) {
                    $totals['no_match']++;
                    $report[] = ['artist' => $artistRaw, 'album' => $albumRaw, 'from' => $from, 'to' => $toName, 'count' => 0, 'status' => 'no products'];
                    continue;
                }

                $totals['matched_products'] += count($matches);
                $samples = array_slice(array_map(fn ($p) => "#{$p->id} {$p->name}", $matches), 0, 3);
                $report[] = [
                    'artist'  => $artistRaw, 'album' => $albumRaw, 'from' => $from, 'to' => $toName,
                    'count'   => count($matches),
                    'status'  => 'match',
                    'samples' => $samples,
                ];

                if ($commit) {
                    $ids = array_map(fn ($p) => $p->id, $matches);
                    $n = DB::table('products')
                        ->whereIn('id', $ids)
                        ->update(['sub_category_id' => $toCat->id, 'updated_at' => now()]);
                    $totals['updated'] += $n;
                    // Remove updated products from the in-memory candidates list
                    // so a later row targeting the same from-subcat doesn't re-match.
                    $movedIds = array_flip($ids);
                    $candidates = array_values(array_filter($candidates, fn ($p) => !isset($movedIds[$p->id])));
                }
            }
        }

        // Per-row report
        $this->line(
            str_pad('artist', 20)
            . str_pad('album', 38)
            . str_pad('from', 18)
            . str_pad('to', 18)
            . str_pad('#', 4)
            . 'status'
        );
        $this->line(str_repeat('-', 120));
        foreach ($report as $r) {
            $this->line(
                str_pad($this->trunc($r['artist'], 18), 20)
                . str_pad($this->trunc($r['album'], 36), 38)
                . str_pad($this->trunc($r['from'], 16), 18)
                . str_pad($this->trunc($r['to'], 16), 18)
                . str_pad((string) $r['count'], 4)
                . $r['status']
            );
            if (!empty($r['samples'])) {
                foreach ($r['samples'] as $s) {
                    $this->line('    · ' . $this->trunc($s, 110));
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            "Rules: %d  |  matched products: %d  |  updated: %d  |  skipped — no from: %d / no to: %d / no match: %d",
            $totals['rows'], $totals['matched_products'], $totals['updated'],
            $totals['no_from'], $totals['no_to'], $totals['no_match']
        ));

        if (!$commit) {
            $this->warn('DRY RUN — no rows written. Re-run with --commit to apply.');
        }
        return 0;
    }

    /** lowercase + strip non-alphanumerics — for sub-category name comparison. */
    private function normalizeName(string $s): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($s));
    }

    /**
     * Artist normalization:
     *   - swap "LAST,FIRST" → "FIRST LAST"
     *   - lowercase, drop diacritics, tokenize on non-alphanumeric
     *   - drop the stopword "the"
     *   - rejoin with single spaces
     */
    private function normalizeArtist(string $s): string
    {
        $s = trim((string) $s);
        if ($s === '') return '';
        if (substr_count($s, ',') === 1) {
            [$last, $first] = array_map('trim', explode(',', $s, 2));
            if ($last !== '' && $first !== '' && !preg_match('/\s/', $last)) {
                $s = $first . ' ' . $last;
            }
        }
        $s = $this->stripDiacritics(mb_strtolower($s));
        $tokens = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array_values(array_filter($tokens, fn ($t) => $t !== 'the'));
        return implode(' ', $tokens);
    }

    /**
     * Album/title normalization:
     *   - strip parentheticals (REMASTERED, DELUXE EDITION, X, PICTURE DISC…)
     *   - lowercase, drop diacritics
     *   - tokenize, drop "the"
     *   - join with no separator (so substring match survives word-boundary noise)
     */
    private function normalizeAlbum(string $s): string
    {
        $s = (string) $s;
        $s = preg_replace('/\([^)]*\)/', ' ', $s);
        $s = $this->stripDiacritics(mb_strtolower($s));
        $tokens = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
        $tokens = array_values(array_filter($tokens, fn ($t) => $t !== 'the'));
        return implode('', $tokens);
    }

    /** Substring match either direction on normalized album strings. */
    private function albumMatches(string $needleNormalized, string $productName): bool
    {
        if ($needleNormalized === '') return false;
        $name = $this->normalizeAlbum($productName);
        if ($name === '') return false;
        return str_contains($name, $needleNormalized) || str_contains($needleNormalized, $name);
    }

    private function stripDiacritics(string $s): string
    {
        $out = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        return $out !== false ? $out : $s;
    }

    private function trunc(string $s, int $n): string
    {
        return mb_strlen($s) <= $n ? $s : (mb_substr($s, 0, $n - 1) . '…');
    }
}
