<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ArtistGenreLookup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Genre overrides for the nivessa.com /genre-bible page (Sarah 2026-11-06:
 * "override the genre with the genre that's more in our database if they
 * differ"). For every artist in the curated artist->bin sheet, count how our
 * music products by that artist are actually filed (genre subcategory mapped
 * to a sheet bin). When the most-used bin differs from the sheet and clearly
 * wins, the website shows that bin instead.
 */
class NivessaGenreBibleController extends Controller
{
    /** The DB bin needs at least this many products to override the sheet. */
    const MIN_PRODUCTS = 3;

    /** Parent categories that are music shelves (skip merch, cards, VHS, magazines). */
    const MUSIC_PARENT_REGEXP = 'vinyl|cd|cassette|record|lp|45|7"|10"|12"|reel';

    const CACHE_KEY = 'genrebible5';

    /**
     * Subcategory name -> sheet bin, first match wins. Order matters:
     * "Folk Rock" is Folk, "Pop Punk" is Punk, "Alt Rock" is Alternative.
     */
    const BIN_PATTERNS = [
        'Soundtrack'          => '/soundtrack|\bscore\b|\bost\b/',
        'Gospel'              => '/gospel|christian/',
        'New Wave'            => '/new wave|synth|post[\s-]?punk/',
        'Hip-Hop / Rap'       => '/hip[\s-]?hop|\brap\b/',
        'R&B/Funk/Soul'       => '/r\s?&\s?b|\brnb\b|soul|funk|disco/',
        'Alternative / Indie' => '/\balt\b|alternative|indie|grunge|\bemo\b/',
        'Punk'                => '/punk|hardcore/',
        'Metal'               => '/metal/',
        'Electronic / Dance'  => '/electronic|dance|house|techno|\bedm\b/',
        'Jazz'                => '/jazz/',
        'Blues'               => '/blues/',
        'Country'             => '/country|americana|bluegrass/',
        'Classical'           => '/classical|opera/',
        'Latin'               => '/latin|salsa|reggaeton|spanish|mexican/',
        'Reggae'              => '/reggae|\bska\b|\bdub\b/',
        'Folk'                => '/folk/',
        'World'               => '/world|international|african/',
        'Pop'                 => '/\bpop\b|k-pop|j-pop/',
        'Rock'                => '/rock/',
    ];

    public function publicFeed(ArtistGenreLookup $lookup)
    {
        $data = self::build($lookup);
        unset($data['counts']); // staff page only

        return response()->json($data, 200, [], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * Cached (6h). Also used by the staff Genre Bible page in the ERP.
     * 'counts' = normalized artist => bin => product count (most first), for
     * every sheet artist we have music products for, plus the 'extra' artists.
     */
    public static function build(ArtistGenreLookup $lookup): array
    {
        return Cache::remember(self::CACHE_KEY, 21600, function () use ($lookup) {
            $businessId = (int) (config('services.nivessa_web.business_id') ?: 1);
            $sheet = $lookup->allArtists();
            if (!$sheet) {
                return ['updated' => now()->toDateTimeString(), 'overrides' => (object) [], 'counts' => []];
            }

            // artist (normalized) => bin => product count, for sheet artists
            // and for artists we file that aren't on the sheet yet.
            $counts = [];
            $unmapped = [];
            $mapped = [];
            $spellings = []; // extra artist => raw spelling => product count
            $rows = DB::table('products as p')
                ->join('categories as c', 'c.id', '=', 'p.sub_category_id')
                ->join('categories as pc', 'pc.id', '=', 'p.category_id')
                ->where('p.business_id', $businessId)
                ->whereNotNull('p.artist')->where('p.artist', '!=', '')
                ->whereRaw('LOWER(pc.name) REGEXP ?', [self::MUSIC_PARENT_REGEXP])
                // Group by exact bytes: grouping the collated name makes MySQL's
                // temp table hit "Duplicate entry" on near-identical spellings.
                // Case/space variants are merged in PHP below.
                ->groupBy(DB::raw('BINARY p.artist'), 'p.sub_category_id')
                ->get([DB::raw('MAX(p.artist) as artist'), DB::raw('MAX(c.name) as genre'), DB::raw('COUNT(*) as n')]);
            foreach ($rows as $r) {
                $raw = trim((string) $r->artist);
                $artist = self::sheetKey($lookup->normalizeArtist($raw), $sheet);
                if ($artist === null) {
                    continue;
                }
                $bin = self::binForSubcategory((string) $r->genre);
                if ($bin === null) {
                    if (isset($sheet[$artist])) {
                        $g = mb_strtolower(trim((string) $r->genre));
                        $unmapped[$g] = ($unmapped[$g] ?? 0) + (int) $r->n;
                    }
                    continue;
                }
                $mapped[mb_strtolower(trim((string) $r->genre))] = $bin;
                $counts[$artist][$bin] = ($counts[$artist][$bin] ?? 0) + (int) $r->n;
                if (!isset($sheet[$artist])) {
                    $spellings[$artist][$raw] = ($spellings[$artist][$raw] ?? 0) + (int) $r->n;
                }
            }

            // Not on the sheet: keep artists with enough products to trust
            // their genre (one stray LP or a typo'd name doesn't make the list).
            $extra = [];
            foreach ($counts as $artist => $bins) {
                if (isset($sheet[$artist])) {
                    continue;
                }
                if (array_sum($bins) < self::MIN_PRODUCTS) {
                    unset($counts[$artist]);
                    continue;
                }
                arsort($bins);
                $names = $spellings[$artist];
                arsort($names);
                $extra[$artist] = ['name' => (string) array_key_first($names), 'genre' => array_key_first($bins)];
            }
            ksort($extra);

            $overrides = [];
            foreach ($counts as $artist => &$bins) {
                arsort($bins);
            }
            unset($bins);
            $added = $lookup->added();
            foreach ($counts as $artist => $bins) {
                // Staff-added genres are a decision, not a guess: never override.
                if (!isset($sheet[$artist]) || isset($added[$artist])) {
                    continue;
                }
                $top = array_key_first($bins);
                $sheetBin = $sheet[$artist];
                if ($top === $sheetBin || $bins[$top] < self::MIN_PRODUCTS || $bins[$top] <= ($bins[$sheetBin] ?? 0)) {
                    continue;
                }
                $overrides[$artist] = ['genre' => $top, 'sheet' => $sheetBin, 'counts' => $bins];
            }
            ksort($overrides);
            arsort($unmapped);

            return [
                'updated' => now()->toDateTimeString(),
                'min_products' => self::MIN_PRODUCTS,
                'overrides' => (object) $overrides,
                // Artists we file that aren't on the sheet: normalized => {name, genre}.
                'extra' => (object) $extra,
                // Added or re-genred by staff on the ERP page; wins over everything.
                'added' => (object) $added,
                'unmapped_subcategories' => (object) $unmapped,
                'subcategory_bins' => (object) $mapped,
                'counts' => $counts,
            ];
        });
    }

    /** Placeholder "artists" that span every genre. */
    const PLACEHOLDER_ARTIST = '/^(various|various artists|v\/a|va|unknown|unknown artist|no artist|n\/a|none|soundtrack|original soundtrack|original cast|cast|-+|\d+)$/u';

    /**
     * Key to count a product artist under: the sheet artist (allowing a
     * missing/extra leading "The"), else the artist itself when it's a real
     * single artist name, else null (placeholders, "A, B" splits).
     */
    private static function sheetKey(string $artist, array $sheet): ?string
    {
        if ($artist === '') {
            return null;
        }
        if (isset($sheet[$artist])) {
            return $artist;
        }
        if (isset($sheet['the ' . $artist])) {
            return 'the ' . $artist;
        }
        if (strpos($artist, 'the ') === 0 && isset($sheet[substr($artist, 4)])) {
            return substr($artist, 4);
        }
        if (preg_match(self::PLACEHOLDER_ARTIST, $artist) || strpos($artist, ',') !== false
            || preg_match('/\b(feat\.?|featuring|with)\b/u', $artist)) {
            return null;
        }
        return $artist;
    }

    public static function binForSubcategory(string $name): ?string
    {
        $n = mb_strtolower(trim($name));
        foreach (self::BIN_PATTERNS as $bin => $re) {
            if (preg_match($re, $n)) {
                return $bin;
            }
        }
        return null;
    }
}
