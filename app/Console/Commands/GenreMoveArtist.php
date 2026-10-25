<?php

namespace App\Console\Commands;

use App\Http\Controllers\GenreFixController as G;
use Illuminate\Console\Command;

/**
 * Move every music record by one artist into a genre, keeping its format
 * (Sarah 2026-10-25: J. Cole -> Hip Hop, Ariana Grande -> Pop; they were
 * showing as "artists we want" under Rock on nivessa.com). Matches the artist
 * field, or a name starting "Artist - " / "Artist / ", ignoring case and
 * punctuation. Dry run unless --commit; writes an undoable snapshot like
 * genres:discogs-check and pushes the change to the website.
 *
 * Usage: php artisan genres:move-artist "J. Cole" "hip hop" [--commit]
 */
class GenreMoveArtist extends Command
{
    protected $signature = 'genres:move-artist {artist} {genre} {--business=1} {--except= : product ids to leave alone, comma-separated} {--commit : write (default: dry run)}';
    protected $description = 'Move all records by one artist into a genre (same format).';

    public function handle()
    {
        $biz = (int) $this->option('business');
        $commit = (bool) $this->option('commit');
        $artistKey = self::keys($this->argument('artist'))[0];
        $target = G::canon($this->argument('genre'));
        if (strlen($artistKey) < 3 || $target === '') {
            $this->error('Need an artist and a genre.');
            return 1;
        }

        $all = \DB::table('categories')->where('business_id', $biz)->whereNull('deleted_at')->select('id', 'name', 'parent_id')->get();
        $formats = [];
        foreach ($all->where('parent_id', 0) as $c) if (\App\Http\Controllers\ProductController::isMusicCategoryName($c->name)) $formats[(int) $c->id] = $c->name;
        $subs = [];
        $byCanon = [];
        foreach ($all->where('parent_id', '>', 0) as $c) {
            $subs[(int) $c->id] = $c->name;
            if (isset($formats[(int) $c->parent_id])) {
                $k = G::canon($c->name);
                if (!isset($byCanon[(int) $c->parent_id][$k])) $byCanon[(int) $c->parent_id][$k] = (int) $c->id;
            }
        }

        // Narrow in SQL on the longest word, then match exactly in PHP.
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($this->argument('artist')), -1, PREG_SPLIT_NO_EMPTY);
        usort($words, function ($a, $b) { return strlen($b) - strlen($a); });
        $like = '%' . $words[0] . '%';
        $rows = \DB::table('products')->where('business_id', $biz)
            ->whereIn('category_id', array_keys($formats))
            ->where(function ($q) use ($like) { $q->where('artist', 'like', $like)->orWhere('name', 'like', $like); })
            ->select('id', 'name', 'artist', 'category_id', 'sub_category_id')->get();

        $except = array_flip(array_filter(array_map('intval', explode(',', (string) $this->option('except')))));
        $done = [];
        $stats = ['matched' => 0, 'already' => 0, 'no_target_genre' => 0, 'moved' => 0];
        foreach ($rows as $p) {
            $byArtist = in_array($artistKey, self::keys((string) $p->artist), true);
            $parts = preg_split('/\s+[-–—\/]\s+/u', (string) $p->name, 2);
            $byName = count($parts) === 2 && in_array($artistKey, self::keys($parts[0]), true);
            if (!$byArtist && !$byName) continue;
            if (isset($except[(int) $p->id])) { $this->line("  skipped #{$p->id} {$p->name}"); continue; }
            $stats['matched']++;
            $fmt = (int) $p->category_id;
            $new = G::subFor($byCanon, $fmt, $target);
            $cur = (int) $p->sub_category_id;
            if (!$new) { $stats['no_target_genre']++; $this->line("  no '{$target}' genre under {$formats[$fmt]}: #{$p->id} {$p->name}"); continue; }
            if ($cur === $new) { $stats['already']++; continue; }
            $this->line(sprintf('  #%d %s | %s: %s -> %s', $p->id, $p->name, $formats[$fmt], $subs[$cur] ?? '(none)', $subs[$new]));
            if ($commit) {
                $w = \DB::table('products')->where('id', $p->id)->where(function ($q) use ($cur) {
                    $cur ? $q->where('sub_category_id', $cur) : $q->whereNull('sub_category_id')->orWhere('sub_category_id', 0);
                })->update(['sub_category_id' => $new]);
                if (!$w) continue;
            }
            $stats['moved']++;
            $done[] = ['id' => (int) $p->id, 'old' => $cur ?: null, 'new' => $new];
        }

        if ($commit && $done) {
            $ts = now()->format('Y-m-d_His');
            \Storage::disk('local')->put("admin-snapshots/genre-move-artist-{$ts}.json", json_encode([
                'timestamp' => $ts, 'action' => 'genre-move-artist', 'user_id' => null, 'business_id' => $biz,
                'source_name' => $this->argument('artist') . ' -> ' . $target, 'target_name' => 'products.sub_category_id', 'rows' => $done,
            ], JSON_PRETTY_PRINT));
            foreach (array_chunk(array_column($done, 'id'), 300) as $chunk) {
                try { (new \App\Services\NivessaStockNotifier())->pushProductChanged($chunk); } catch (\Throwable $e) {}
            }
            // The nivessa.com buy-sell-trade card reads these.
            \Cache::forget('buypremiums4');
        }
        $this->info(($commit ? 'COMMIT ' : 'DRY RUN ') . $this->argument('artist') . " -> {$target}: " . json_encode($stats));
        return 0;
    }

    /** Letters-and-digits forms of a name: as written, and "Cole, J." flipped. */
    private static function keys($s)
    {
        $s = mb_strtolower(trim(preg_replace('/\s*\(\d+\)\s*$/', '', (string) $s)));
        $out = [preg_replace('/[^a-z0-9]+/', '', $s)];
        if (preg_match('/^([^,]+),\s*([^,]+)$/', $s, $m) && !preg_match('/&|\band\b|,\s*the\b/', $s)) {
            $out[] = preg_replace('/[^a-z0-9]+/', '', $m[2] . $m[1]);
        }
        return $out;
    }
}
