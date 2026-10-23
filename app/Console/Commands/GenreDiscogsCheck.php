<?php

namespace App\Console\Commands;

use App\Http\Controllers\GenreFixController as G;
use Illuminate\Console\Command;

/**
 * Check every Discogs-linked music record's genre against its Discogs release
 * and fix only the ones that are definitely wrong (Sarah, 2026-10-21). Blank
 * genres get filled the same way. Runs in the background a few minutes at a
 * time; replaces discogs:backfill-genres in its workflow slot, so it uses the
 * same share of the Discogs rate limit.
 *
 * "Definitely wrong" = none of the release's genres or styles is our genre
 * (same genre under any name, see GenreFixController::canon, and no style even
 * contains the word, so "Pop Rock" keeps a Pop record), our genre isn't one of
 * the ones a record can legitimately be (Soundtracks, Children's...), and the
 * release's artist matches ours, so a wrongly linked release changes nothing.
 *
 * State (checked ids, totals, recent fixes) in storage/app/genre-discogs-check.json,
 * shown on /products/genre-fix. Each run's writes are one undoable snapshot
 * ('backfill-genre-from-discogs').
 *
 * Usage: php artisan genres:discogs-check [--minutes=8] [--commit]
 */
class GenreDiscogsCheck extends Command
{
    protected $signature = 'genres:discogs-check {--business=1} {--minutes=8} {--commit : write (default: dry run)} {--limit=0 : stop after this many records} {--skip-blank : test the wrong-genre check only}';
    protected $description = 'Fix definitely-wrong and blank genres from each record\'s Discogs release.';

    const STATE = 'genre-discogs-check.json';

    // Our genre (canon) => the Discogs top-level genres it fits under. A record
    // is only "definitely wrong" when Discogs lists none of them (Cat Stevens
    // under Folk with Discogs "Rock" stays; Miami Vice Theme under Jazz with
    // Discogs "Electronic, Pop, Stage & Screen" gets fixed). Genres not listed
    // here are never changed.
    const FAMILY = [
        'rock' => ['Rock'], 'alt' => ['Rock'], 'punk' => ['Rock'], 'metal' => ['Rock'], 'nu metal' => ['Rock'],
        'new wave' => ['Rock', 'Electronic', 'Pop'], 'synth' => ['Electronic', 'Rock', 'Pop'],
        'pop' => ['Pop', 'Rock', 'Electronic', 'Funk / Soul'], 'k pop' => ['Pop', 'Electronic', 'Hip Hop'],
        'folk' => ['Folk, World, & Country', 'Rock', 'Pop'], 'country' => ['Folk, World, & Country'],
        'folk country' => ['Folk, World, & Country', 'Rock'],
        'rnb' => ['Funk / Soul'], 'disco' => ['Funk / Soul', 'Electronic', 'Pop'], 'blues' => ['Blues', 'Rock', 'Funk / Soul'],
        'jazz' => ['Jazz'], 'electronic' => ['Electronic'], 'hip hop' => ['Hip Hop'], 'classical' => ['Classical'],
        'latin' => ['Latin'], 'reggae' => ['Reggae'], 'world' => ['Folk, World, & Country', 'Latin', 'Reggae'],
    ];

    public static function state()
    {
        try {
            $s = json_decode((string) \Storage::disk('local')->get(self::STATE), true);
        } catch (\Throwable $e) {
            $s = null;
        }
        $empty = ['checked' => [], 'fixed' => 0, 'filled' => 0, 'recent' => [], 'fmt' => []];
        return is_array($s) ? $s + $empty : $empty;
    }

    /** Eligible ids: active music records with a Discogs release, blanks first, then newest. */
    public static function queue($biz, array $formatIds)
    {
        $base = fn() => \DB::table('products')->where('business_id', $biz)->where('is_inactive', 0)
            ->whereIn('category_id', $formatIds)->where('discogs_release_id', '>', 0);
        $blank = $base()->where(function ($q) { $q->whereNull('sub_category_id')->orWhere('sub_category_id', 0); })->orderBy('id')->pluck('id')->all();
        $rest = $base()->where('sub_category_id', '>', 0)->orderByDesc('id')->pluck('id')->all();
        return array_merge($blank, $rest);
    }

    private static function norm($s)
    {
        $s = mb_strtolower((string) $s);
        $s = preg_replace('/\s*\(\d+\)\s*$/', '', $s); // Discogs "Prince (2)"
        $s = preg_replace('/^the\s+|,\s*the$/', '', trim($s));
        return trim(preg_replace('/[^a-z0-9]+/', ' ', $s));
    }

    public function handle()
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');
        $biz = (int) $this->option('business');
        $commit = (bool) $this->option('commit');
        $deadline = time() + 60 * max(1, (int) $this->option('minutes'));
        $limit = (int) $this->option('limit');
        $svc = new \App\Services\DiscogsService($biz);
        if (!$svc->isConfigured()) { $this->error('Discogs not configured.'); return 1; }

        $all = \DB::table('categories')->where('business_id', $biz)->whereNull('deleted_at')->select('id', 'name', 'parent_id')->get();
        $formats = [];
        foreach ($all->where('parent_id', 0) as $c) if (\App\Http\Controllers\ProductController::isMusicCategoryName($c->name)) $formats[(int) $c->id] = $c->name;
        $subs = [];
        $byCanon = [];
        foreach ($all->where('parent_id', '>', 0) as $c) {
            $subs[(int) $c->id] = ['name' => $c->name, 'parent' => (int) $c->parent_id];
            if (isset($formats[(int) $c->parent_id])) {
                $k = G::canon($c->name);
                if (!isset($byCanon[(int) $c->parent_id][$k])) $byCanon[(int) $c->parent_id][$k] = (int) $c->id;
            }
        }

        $state = self::state();
        // Records checked before the format check (10/23) are read once more for their format.
        $queue = array_values(array_filter(self::queue($biz, array_keys($formats)), fn($id) => !isset($state['checked'][$id]) || !isset($state['fmt'][$id])));
        if ($this->option('skip-blank')) {
            $blank = array_flip(\DB::table('products')->where('business_id', $biz)->where(function ($q) { $q->whereNull('sub_category_id')->orWhere('sub_category_id', 0); })->pluck('id')->all());
            $queue = array_values(array_filter($queue, fn($id) => !isset($blank[$id])));
        }
        $done = []; $n = 0; $errors = 0; $stats = ['ok' => 0, 'fixed' => 0, 'filled' => 0, 'artist_mismatch' => 0, 'no_match' => 0, 'error' => 0];

        foreach ($queue as $id) {
            if (time() >= $deadline || ($limit && $n >= $limit)) break;
            $p = \DB::table('products')->where('id', $id)->select('id', 'name', 'artist', 'sku', 'category_id', 'sub_category_id', 'discogs_release_id')->first();
            if (!$p) continue;
            $n++;
            $res = $svc->getReleaseById($p->discogs_release_id);
            usleep(1100000); // ~55/min, under Discogs' 60/min
            if (!empty($res['error'])) {
                $stats['error']++;
                // Discogs 429s last ~60s with no Retry-After; wait it out after a few.
                if (++$errors >= 3) { sleep(65); $errors = 0; }
                continue;
            }
            $errors = 0;
            $d = json_decode(json_encode($res['data'] ?? []), true) ?: [];
            $styles = array_values(array_filter(array_map('trim', (array) ($d['styles'] ?? []))));
            $genres = array_values(array_filter(array_map('trim', (array) ($d['genres'] ?? []))));
            $fmt = (int) $p->category_id;
            $state['checked'][$id] = date('Y-m-d');

            // Release artist must be our artist (or in our name), else the link is suspect.
            $ours = self::norm($p->artist) . ' | ' . self::norm($p->name);
            $match = false; $various = false;
            foreach ((array) ($d['artists'] ?? []) as $a) {
                $an = self::norm($a['name'] ?? '');
                if ($an === 'various') $various = true;
                if ($an !== '' && preg_match('/\b' . preg_quote($an, '/') . '\b/', $ours)) $match = true;
            }
            if (!$match && !$various) { $stats['artist_mismatch']++; $state['fmt'][$id] = 'x'; continue; }

            // Release format for /products/format-check: vinyl / cd / cassette, "!" when the
            // release's barcode is our SKU (same record for sure), "x" when it's unclear.
            $family = \App\Http\Controllers\FormatCheckController::familyOfRelease((array) ($d['formats'] ?? []));
            $sku = ltrim(preg_replace('/\D/', '', (string) $p->sku), '0');
            $sameBarcode = false;
            foreach ((array) ($d['identifiers'] ?? []) as $ident) {
                if (($ident['type'] ?? '') !== 'Barcode') continue;
                $b = ltrim(preg_replace('/\D/', '', (string) ($ident['value'] ?? '')), '0');
                if (strlen($sku) >= 8 && $b === $sku) $sameBarcode = true;
            }
            $state['fmt'][$id] = $family ? $family . ($sameBarcode ? '!' : '') : 'x';

            $theirs = []; // our genre ids the release supports, most specific first
            foreach (array_merge($styles, $genres) as $g) {
                $sid = G::subFor($byCanon, $fmt, G::canon($g));
                if ($sid && !in_array($sid, $theirs, true)) $theirs[] = $sid;
            }
            $cur = (int) $p->sub_category_id;
            $curOk = $cur && isset($subs[$cur]) && $subs[$cur]['parent'] === $fmt;
            if (!$theirs) { $stats['no_match']++; continue; }
            if ($curOk) {
                if (in_array($cur, $theirs, true)) { $stats['ok']++; continue; }
                $c = G::canon($subs[$cur]['name']);
                if (in_array($c, G::KEEP, true)) { $stats['ok']++; continue; }
                // Wrong only if the release isn't in any Discogs genre our genre can live under.
                $fam = self::FAMILY[$c] ?? null;
                if ($fam === null || array_intersect($fam, $genres)) { $stats['ok']++; continue; }
            }
            $new = $theirs[0];
            $kind = $curOk ? 'fixed' : 'filled';
            $stats[$kind]++;
            if ($commit) {
                $w = \DB::table('products')->where('id', $id)->where(function ($q) use ($p) {
                    $p->sub_category_id ? $q->where('sub_category_id', $p->sub_category_id) : $q->whereNull('sub_category_id')->orWhere('sub_category_id', 0);
                })->update(['sub_category_id' => $new]);
                if (!$w) continue;
                $state[$kind]++;
            }
            $done[] = ['id' => (int) $id, 'old' => $cur ?: null, 'new' => $new];
            array_unshift($state['recent'], ['id' => (int) $id, 'name' => $p->name, 'old' => $curOk ? $subs[$cur]['name'] : '', 'new' => $subs[$new]['name'],
                'discogs' => implode(', ', array_merge($genres, $styles)), 'date' => date('m/d/y'), 'dry' => !$commit]);
            $state['recent'] = array_slice($state['recent'], 0, 300);
        }

        if ($commit) {
            if ($done) {
                $ts = now()->format('Y-m-d_His');
                \Storage::disk('local')->put("admin-snapshots/backfill-genre-from-discogs-{$ts}.json", json_encode([
                    'timestamp' => $ts, 'action' => 'backfill-genre-from-discogs', 'user_id' => null, 'business_id' => $biz,
                    'source_name' => count($done) . ' genre(s) checked against Discogs', 'target_name' => 'products.sub_category_id', 'rows' => $done,
                ], JSON_PRETTY_PRINT));
                foreach (array_chunk(array_column($done, 'id'), 300) as $chunk) {
                    try { (new \App\Services\NivessaStockNotifier())->pushProductChanged($chunk); } catch (\Throwable $e) {}
                }
            }
            \Storage::disk('local')->put(self::STATE, json_encode($state));
        }
        $left = count($queue) - $n;
        $this->info(($commit ? 'COMMIT' : 'DRY RUN') . " checked {$n}: " . json_encode($stats) . ", {$left} left in queue");
        if (!$commit) foreach (array_slice($state['recent'], 0, 60) as $r) if (!empty($r['dry'])) $this->line("  {$r['name']}: " . ($r['old'] ?: 'none') . " -> {$r['new']}  [Discogs: {$r['discogs']}]");
        return 0;
    }
}
