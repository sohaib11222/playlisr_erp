<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Buy form "Look up eBay value" for signed lines (Jon 2026-11-09).
 *
 * eBay won't give us SOLD prices (Marketplace Insights is gated, and eBay
 * 403s our server for page scrapes), so this uses the Browse API's current
 * Buy It Now listings for "<title> signed <format>", keeps only listings that
 * actually say signed/autographed (drops reprints/facsimiles/unsigned), and
 * suggests ASKING_TO_VALUE of the median asking price as the signed copy's
 * value. Staff can always overwrite the number.
 */
class SignedValueLookupService
{
    /** Asking prices run above what things sell for. */
    const ASKING_TO_VALUE = 0.70;

    /** Fewer matching listings than this = "not enough data". */
    const MIN_LISTINGS = 3;

    const FORMAT_WORDS = [
        'signed_vinyl' => 'vinyl',
        'signed_cd' => 'cd',
        'signed_poster' => 'poster',
        'signed_item' => '',
    ];

    const NOISE = '/\b(un-?signed|not signed|no signature|reprint|re-print|facsimile|pre-?print(ed)?|printed signature|print signed|stamped|replica|rp\b|copy of|lithograph)\b/i';

    const SIGNED = '/\b(signed|autograph(ed)?|auto\b|inscribed)\b/i';

    public function lookup(int $businessId, string $title, string $type): array
    {
        $clean = $this->cleanTitle($title);
        $out = [
            'ok' => false,
            'query' => '',
            'count' => 0,
            'listings' => [],
            'bible' => $this->bibleArtist($title),
            'search_url' => '',
        ];
        if (mb_strlen($clean) < 3) {
            $out['msg'] = 'Type the artist (and title) first.';
            return $out;
        }

        $fmt = self::FORMAT_WORDS[$type] ?? '';
        $q = trim($clean . ' signed ' . $fmt);
        $out['query'] = $q;
        $out['search_url'] = 'https://www.ebay.com/sch/i.html?_nkw=' . rawurlencode($q) . '&LH_Sold=1&LH_Complete=1';

        try {
            $items = Cache::remember('bfc_signed_ebay:' . md5(mb_strtolower($q)), 6 * 3600, function () use ($businessId, $q) {
                $res = (new EbayService($businessId))->browseSearch([
                    'q' => $q,
                    'limit' => 100,
                    'filter' => 'buyingOptions:{FIXED_PRICE},priceCurrency:USD,price:[5..]',
                ]);
                $rows = [];
                foreach ((array) ($res['itemSummaries'] ?? []) as $it) {
                    $rows[] = [
                        'title' => (string) ($it['title'] ?? ''),
                        'price' => (float) ($it['price']['value'] ?? 0),
                        'url' => (string) ($it['itemWebUrl'] ?? ''),
                    ];
                }
                return $rows;
            });
        } catch (\Throwable $e) {
            \Log::warning('Signed eBay lookup failed: ' . $e->getMessage());
            $out['msg'] = 'eBay lookup failed. Use "Search eBay sold items" by hand.';
            return $out;
        }

        $tokens = $this->tokens($clean);
        $need = max(1, (int) ceil(count($tokens) * 0.6));
        $keep = [];
        foreach ($items as $it) {
            $t = $it['title'];
            if ($it['price'] <= 0 || !preg_match(self::SIGNED, $t) || preg_match(self::NOISE, $t)) {
                continue;
            }
            $lt = mb_strtolower($t);
            $hit = 0;
            foreach ($tokens as $tok) {
                if (mb_strpos($lt, $tok) !== false) {
                    $hit++;
                }
            }
            if ($hit < $need) {
                continue;
            }
            $keep[] = $it;
        }

        $out['count'] = count($keep);
        if (count($keep) < self::MIN_LISTINGS) {
            $out['msg'] = count($keep) === 0
                ? 'No signed listings found on eBay. Look it up by hand.'
                : 'Only ' . count($keep) . ' signed listing' . (count($keep) === 1 ? '' : 's') . ' on eBay. Not enough data, look it up by hand.';
            $out['listings'] = $keep;
            return $out;
        }

        $prices = array_column($keep, 'price');
        sort($prices);
        $n = count($prices);
        $median = $n % 2 ? $prices[intdiv($n, 2)] : ($prices[$n / 2 - 1] + $prices[$n / 2]) / 2;

        usort($keep, function ($a, $b) use ($median) {
            return abs($a['price'] - $median) <=> abs($b['price'] - $median);
        });

        $out['ok'] = true;
        $out['median'] = round($median, 2);
        $out['low'] = round($prices[0], 2);
        $out['high'] = round($prices[$n - 1], 2);
        $out['suggested'] = (float) round($median * self::ASKING_TO_VALUE);
        $out['pct'] = (int) round(self::ASKING_TO_VALUE * 100);
        $out['listings'] = array_slice($keep, 0, 5);
        return $out;
    }

    /** Drop "signed"/"autographed" etc. the associate typed; we add our own. */
    protected function cleanTitle(string $title): string
    {
        $t = preg_replace('/\b(signed|autograph(ed)?|inscribed|\(signed\))\b/iu', ' ', $title);
        $t = preg_replace('/[^\p{L}\p{N}&\'\s-]+/u', ' ', $t);
        $t = preg_replace('/\s+-\s+/u', ' ', $t);
        return trim(preg_replace('/\s+/u', ' ', $t));
    }

    protected function tokens(string $clean): array
    {
        $stop = ['the', 'and', 'lp', 'cd', 'vinyl', 'record', 'poster', 'album', 'with', 'for'];
        $out = [];
        foreach (preg_split('/\s+/u', mb_strtolower($clean)) as $w) {
            $w = trim($w, "-'");
            if (mb_strlen($w) >= 2 && !in_array($w, $stop, true)) {
                $out[$w] = true;
            }
        }
        return array_keys($out);
    }

    /** Genre Bible artist for "Artist - Title" (or the longest bible name the title starts with). */
    protected function bibleArtist(string $title): ?array
    {
        $lookup = app(ArtistGenreLookup::class);
        $parts = preg_split('/\s+-\s+/u', trim($title), 2);
        $first = $parts[0] ?? '';
        $bin = $lookup->binForArtist($first);
        if ($bin !== null) {
            return ['name' => $lookup->displayName($lookup->normalizeArtist($first)), 'genre' => $bin];
        }
        $norm = $lookup->normalizeArtist(preg_replace('/\b(signed|autographed)\b/iu', '', $title));
        $best = null;
        foreach ($lookup->allArtists() + array_map(function ($a) { return $a['genre']; }, $lookup->added()) as $key => $genre) {
            $key = (string) $key;
            if (mb_strlen($key) >= 3 && mb_strpos($norm . ' ', $key . ' ') === 0 && ($best === null || mb_strlen($key) > mb_strlen($best[0]))) {
                $best = [$key, $genre];
            }
        }
        return $best ? ['name' => $lookup->displayName($best[0]), 'genre' => $best[1]] : null;
    }
}
