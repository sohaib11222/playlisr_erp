<?php

namespace App\Console\Commands;

use App\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillArtistNames extends Command
{
    /**
     * Fill missing artist names on products by two strategies, in order:
     *
     *   1. Parse "Artist - Title" / "Artist — Title" from the product name.
     *      This is how most vinyl records are named in the catalog, so it
     *      catches the majority cleanly.
     *
     *   2. Fall back to a title-match lookup: if another product in the
     *      same sub-category shares the exact name and already has an
     *      artist, copy it.
     *
     * Runs in dry-mode by default — prints what *would* change. Add --commit
     * to actually write. Batches updates to avoid locking the products table.
     *
     * Resolves Asana task 1213364156153704 (40 days overdue).
     */
    protected $signature = 'products:backfill-artists
                            {--commit : Actually write updates (default is dry-run)}
                            {--limit=0 : Process at most N products (0 = all)}
                            {--sample=0 : Print N random sample rows that would be changed}';

    protected $description = 'Fill missing artist on products by parsing the product name + cross-referencing siblings in the same sub-category.';

    // Values that count as "no artist" for the purpose of this backfill.
    const UNKNOWN_VALUES = [
        '', 'unknown', 'unknown artist', '(unknown)', '(unknown artist)',
        'n/a', 'none', '-', '--',
    ];

    public function handle()
    {
        $commit = (bool) $this->option('commit');
        $limit  = (int) $this->option('limit');
        $sample = (int) $this->option('sample');

        $this->info($commit
            ? '** COMMIT mode — changes WILL be written **'
            : '** DRY-RUN mode — no changes written. Pass --commit to apply. **');

        // Build the "missing artist" filter. Using whereRaw so we catch the
        // full set of sentinel values in one shot regardless of whitespace or
        // case. NULLIF short-circuits the empty-string case.
        $unknownIn = array_map(fn ($s) => "'" . $s . "'", self::UNKNOWN_VALUES);
        $whereFragment = "(LOWER(TRIM(COALESCE(artist, ''))) IN (" . implode(',', $unknownIn) . "))";

        $countQuery = Product::whereRaw($whereFragment);
        $total = (clone $countQuery)->count();

        $this->line("Products with missing artist: {$total}");
        if ($total === 0) {
            $this->info('Nothing to do.');
            return 0;
        }

        $query = Product::whereRaw($whereFragment)
            ->orderBy('id')
            ->select(['id', 'name', 'artist', 'sub_category_id', 'business_id']);

        if ($limit > 0) {
            $query->limit($limit);
        }

        $stats = [
            'parsed_from_name' => 0,
            'matched_sibling'  => 0,
            'no_match'         => 0,
            'skipped'          => 0,
        ];
        $changes = [];

        $query->chunkById(500, function ($products) use ($commit, &$stats, &$changes) {
            foreach ($products as $p) {
                $extracted = $this->parseArtistFromName($p->name);
                $source = 'parsed_from_name';

                if (!$extracted) {
                    $extracted = $this->findSiblingArtist($p);
                    $source = $extracted ? 'matched_sibling' : null;
                }

                if (!$extracted) {
                    $stats['no_match']++;
                    continue;
                }

                // Basic sanity: no obvious garbage, reasonable length, not the
                // same as the whole product name (that means parse misfired).
                if (mb_strlen($extracted) < 2 || mb_strlen($extracted) > 120) {
                    $stats['skipped']++;
                    continue;
                }
                if (mb_strtolower($extracted) === mb_strtolower(trim($p->name))) {
                    $stats['skipped']++;
                    continue;
                }

                $changes[] = [
                    'id'     => $p->id,
                    'from'   => $p->artist,
                    'to'     => $extracted,
                    'name'   => $p->name,
                    'source' => $source,
                ];
                $stats[$source]++;

                if ($commit) {
                    DB::table('products')
                        ->where('id', $p->id)
                        ->update([
                            'artist'     => $extracted,
                            'updated_at' => now(),
                        ]);
                }
            }
        });

        $this->line('');
        $this->info("Would update: " . array_sum([$stats['parsed_from_name'], $stats['matched_sibling']]));
        $this->line("  - parsed from product name : {$stats['parsed_from_name']}");
        $this->line("  - matched a sibling        : {$stats['matched_sibling']}");
        $this->line("  - no match (left blank)    : {$stats['no_match']}");
        $this->line("  - skipped (sanity check)   : {$stats['skipped']}");

        if ($sample > 0 && !empty($changes)) {
            $this->line('');
            $this->info('Random sample of ' . min($sample, count($changes)) . ' proposed changes:');
            $picks = collect($changes)->shuffle()->take($sample);
            foreach ($picks as $c) {
                $this->line(sprintf(
                    '  #%d [%s] "%s"  →  artist "%s"',
                    $c['id'], $c['source'], mb_strimwidth($c['name'], 0, 60, '…'), $c['to']
                ));
            }
        }

        if (!$commit) {
            $this->warn("\nDry-run only. Re-run with --commit to apply.");
        } else {
            $this->info("\nDone.");
        }
        return 0;
    }

    /**
     * Parse an artist out of the product name. Handles formats like:
     *   "Rolling Stones - Some Girls"
     *   "Miles Davis — Kind of Blue"
     *   "The Smiths – The Queen is Dead"
     *   "Beatles: Abbey Road"
     *   "Talking Heads (Remain in Light)"
     *
     * Returns the artist string on success, null on failure.
     */
    public function parseArtistFromName(?string $name): ?string
    {
        if (!$name) return null;
        $name = trim(preg_replace('/\s+/', ' ', $name));

        // Common separators, ordered by specificity. Em/en dashes first (rarer
        // in titles) before hyphens (which can appear inside titles themselves).
        foreach ([' — ', ' – ', ' - ', ': '] as $sep) {
            $pos = mb_strpos($name, $sep);
            if ($pos === false) continue;
            $left = trim(mb_substr($name, 0, $pos));
            $right = trim(mb_substr($name, $pos + mb_strlen($sep)));
            // Guard: both sides should be non-trivial (at least one real word).
            if (mb_strlen($left) >= 2 && mb_strlen($right) >= 2 && str_word_count($left) <= 8) {
                return $left;
            }
        }

        // Parenthesized title: "Artist (Title)" — artist = everything before "("
        if (preg_match('/^(.+?)\s*\(([^)]+)\)\s*$/u', $name, $m)) {
            $left = trim($m[1]);
            if (mb_strlen($left) >= 2 && str_word_count($left) <= 8) {
                return $left;
            }
        }

        return null;
    }

    /**
     * Find another product in the same sub-category with the same exact name
     * that DOES have an artist set. Case-insensitive match. Returns the
     * artist string or null.
     */
    private function findSiblingArtist(Product $p): ?string
    {
        if (!$p->sub_category_id) return null;

        $unknownIn = array_map(fn ($s) => "'" . $s . "'", self::UNKNOWN_VALUES);
        $notUnknown = "LOWER(TRIM(COALESCE(artist, ''))) NOT IN (" . implode(',', $unknownIn) . ")";

        $sibling = DB::table('products')
            ->where('business_id', $p->business_id)
            ->where('sub_category_id', $p->sub_category_id)
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($p->name))])
            ->whereRaw($notUnknown)
            ->value('artist');

        return $sibling ?: null;
    }
}
