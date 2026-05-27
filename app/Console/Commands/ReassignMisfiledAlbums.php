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
 * Sub-category names in CHANGES come from the source spreadsheet and are
 * NOT assumed to match the catalog verbatim — they're resolved against
 * the actual sub-categories in the DB via:
 *   1. exact normalized match (whitespace/punct stripped, lowercased)
 *   2. source-name is a substring of a DB sub-category name (sheet is
 *      shorter — e.g. sheet "Hip-Hop", DB "Hip Hop/Rap")
 *   3. DB sub-category name is a substring of the source name (sheet is
 *      longer — e.g. sheet "Electronic/Dance", DB "Electronic")
 * Ambiguous matches (multiple hits at the same tier) are flagged and
 * those rows are skipped — never silently picked. Dry-run prints the
 * full resolution table at the top so the operator can verify before
 * --commit.
 *
 * Use --dump-subcats to just list every product sub-category and exit
 * (handy for sanity-checking which DB names exist).
 *
 * Album matching: parentheticals stripped, "the" dropped, substring
 * either direction so source truncations like "...FABULOU" still match
 * the catalog's full "...Fabulous Killjoys".
 */
class ReassignMisfiledAlbums extends Command
{
    protected $signature = 'nivessa:reassign-misfiled-albums
                            {--business=1 : business_id to scope to}
                            {--dump-subcats : List all product sub-categories and exit}
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

        // Pull ALL product sub-categories once. Used by the resolver below
        // and by --dump-subcats.
        $allSubs = Category::where('business_id', $businessId)
            ->where('category_type', 'product')
            ->where('parent_id', '!=', 0)
            ->get(['id', 'name', 'parent_id']);

        if ($this->option('dump-subcats')) {
            $this->line("All product sub-categories for business {$businessId}:");
            $parents = Category::where('business_id', $businessId)
                ->where('category_type', 'product')
                ->where('parent_id', 0)
                ->pluck('name', 'id');
            foreach ($allSubs->sortBy('name') as $s) {
                $parent = $parents[$s->parent_id] ?? "#{$s->parent_id}";
                $this->line(sprintf('  id %-5d  %-35s  (parent: %s)', $s->id, $s->name, $parent));
            }
            $this->line('Total: ' . $allSubs->count());
            return 0;
        }

        $this->info($commit
            ? '** COMMIT mode — changes WILL be written **'
            : '** DRY-RUN mode — no changes written. Pass --commit to apply. **');
        $this->newLine();

        // --- Resolve every unique sub-cat name used in CHANGES against the DB. ---
        $namesUsed = collect(self::CHANGES)
            ->flatMap(fn ($r) => [$r[2], $r[3]])
            ->unique()
            ->values();

        $resolution = []; // source name => ['category' => Category|null, 'tier' => 1|2|3|null, 'candidates' => [...]]
        foreach ($namesUsed as $name) {
            $resolution[$name] = $this->resolveSubCategory($name, $allSubs);
        }

        // Print resolution table.
        $this->line('Sub-category resolution (source → DB):');
        $this->line(str_pad('source', 24) . str_pad('→ DB name', 30) . str_pad('id', 8) . 'how');
        $this->line(str_repeat('-', 80));
        $hasUnresolved = false;
        foreach ($resolution as $src => $res) {
            if ($res['category'] instanceof Category) {
                $how = ['exact', 'sheet-in-DB', 'DB-in-sheet'][$res['tier'] - 1] ?? '?';
                $this->line(
                    str_pad($this->trunc($src, 22), 24)
                    . str_pad($this->trunc($res['category']->name, 28), 30)
                    . str_pad((string) $res['category']->id, 8)
                    . $how
                );
            } else {
                $hasUnresolved = true;
                $note = $res['tier'] === 'ambiguous'
                    ? 'AMBIGUOUS: ' . implode(', ', array_map(fn ($c) => "{$c->name}(#{$c->id})", $res['candidates']))
                    : 'NOT FOUND';
                $this->line(str_pad($this->trunc($src, 22), 24) . str_pad('—', 30) . str_pad('—', 8) . $note);
            }
        }
        $this->newLine();
        if ($hasUnresolved) {
            $this->warn('Some sub-categories did not resolve. Those rows will be skipped. Run with --dump-subcats to see all DB names, then update CHANGES or rename in the DB.');
            $this->newLine();
        }

        // --- Group rows by from-subcategory so we only pull candidates once per group. ---
        $groups = [];
        foreach (self::CHANGES as $row) {
            $groups[$row[2]][] = $row;
        }

        $totals = ['rows' => 0, 'no_from' => 0, 'no_to' => 0, 'no_match' => 0, 'matched_products' => 0, 'updated' => 0];
        $report = [];

        foreach ($groups as $fromName => $rows) {
            $fromRes = $resolution[$fromName];
            $fromCat = $fromRes['category'];

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
                $toRes = $resolution[$toName];
                $toCat = $toRes['category'];

                // Use the DB name (not the literal source name) in the report.
                $fromLabel = $fromCat instanceof Category ? $fromCat->name : $from;
                $toLabel   = $toCat   instanceof Category ? $toCat->name   : $toName;

                if (!$fromCat instanceof Category) {
                    $totals['no_from']++;
                    $report[] = ['artist' => $artistRaw, 'album' => $albumRaw, 'from' => $fromLabel, 'to' => $toLabel, 'count' => 0, 'status' => 'SKIP: from sub-cat unresolved'];
                    continue;
                }
                if (!$toCat instanceof Category) {
                    $totals['no_to']++;
                    $report[] = ['artist' => $artistRaw, 'album' => $albumRaw, 'from' => $fromLabel, 'to' => $toLabel, 'count' => 0, 'status' => 'SKIP: to sub-cat unresolved'];
                    continue;
                }
                if ($fromCat->id === $toCat->id) {
                    $report[] = ['artist' => $artistRaw, 'album' => $albumRaw, 'from' => $fromLabel, 'to' => $toLabel, 'count' => 0, 'status' => 'SKIP: from == to'];
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
                    $report[] = ['artist' => $artistRaw, 'album' => $albumRaw, 'from' => $fromLabel, 'to' => $toLabel, 'count' => 0, 'status' => 'no products'];
                    continue;
                }

                $totals['matched_products'] += count($matches);
                $samples = array_slice(array_map(fn ($p) => "#{$p->id} {$p->name}", $matches), 0, 3);
                $report[] = [
                    'artist'  => $artistRaw, 'album' => $albumRaw, 'from' => $fromLabel, 'to' => $toLabel,
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
                    // Drop updated products from the in-memory list so a later
                    // row targeting the same from-subcat doesn't re-match them.
                    $movedIds = array_flip($ids);
                    $candidates = array_values(array_filter($candidates, fn ($p) => !isset($movedIds[$p->id])));
                }
            }
        }

        // Per-row report
        $this->line(
            str_pad('artist', 20)
            . str_pad('album', 38)
            . str_pad('from (DB)', 22)
            . str_pad('to (DB)', 22)
            . str_pad('#', 4)
            . 'status'
        );
        $this->line(str_repeat('-', 130));
        foreach ($report as $r) {
            $this->line(
                str_pad($this->trunc($r['artist'], 18), 20)
                . str_pad($this->trunc($r['album'], 36), 38)
                . str_pad($this->trunc($r['from'], 20), 22)
                . str_pad($this->trunc($r['to'], 20), 22)
                . str_pad((string) $r['count'], 4)
                . $r['status']
            );
            if (!empty($r['samples'])) {
                foreach ($r['samples'] as $s) {
                    $this->line('    · ' . $this->trunc($s, 120));
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

    /**
     * Resolve a sub-category name against the DB. Tries three tiers in order;
     * each tier requires exactly one match.
     *
     * Returns ['category' => Category|null, 'tier' => 1|2|3|'ambiguous'|null, 'candidates' => Category[]]
     */
    private function resolveSubCategory(string $name, $allSubs): array
    {
        $key = $this->normalizeName($name);

        $exact = $allSubs->filter(fn ($c) => $this->normalizeName($c->name) === $key)->values();
        if ($exact->count() === 1) return ['category' => $exact->first(), 'tier' => 1, 'candidates' => $exact->all()];
        if ($exact->count() > 1)   return ['category' => null,            'tier' => 'ambiguous', 'candidates' => $exact->all()];

        $srcInDb = $allSubs->filter(fn ($c) => $key !== '' && str_contains($this->normalizeName($c->name), $key))->values();
        if ($srcInDb->count() === 1) return ['category' => $srcInDb->first(), 'tier' => 2, 'candidates' => $srcInDb->all()];
        if ($srcInDb->count() > 1)   return ['category' => null,              'tier' => 'ambiguous', 'candidates' => $srcInDb->all()];

        $dbInSrc = $allSubs->filter(function ($c) use ($key) {
            $n = $this->normalizeName($c->name);
            return $n !== '' && str_contains($key, $n);
        })->values();
        if ($dbInSrc->count() === 1) return ['category' => $dbInSrc->first(), 'tier' => 3, 'candidates' => $dbInSrc->all()];
        if ($dbInSrc->count() > 1)   return ['category' => null,              'tier' => 'ambiguous', 'candidates' => $dbInSrc->all()];

        return ['category' => null, 'tier' => null, 'candidates' => []];
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
