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
     * 'counts' = normalized artist => bin => product count, for every sheet
     * artist we have music products for.
     */
    public static function build(ArtistGenreLookup $lookup): array
    {
        return Cache::remember('genrebible4', 21600, function () use ($lookup) {
            $businessId = (int) (config('services.nivessa_web.business_id') ?: 1);
            $sheet = $lookup->allArtists();
            if (!$sheet) {
                return ['updated' => now()->toDateTimeString(), 'overrides' => (object) [], 'counts' => []];
            }

            // artist (normalized) => bin => product count
            $counts = [];
            $unmapped = [];
            $mapped = [];
            foreach (array_chunk(array_keys($sheet), 1000) as $chunk) {
                $rows = DB::table('products as p')
                    ->join('categories as c', 'c.id', '=', 'p.sub_category_id')
                    ->join('categories as pc', 'pc.id', '=', 'p.category_id')
                    ->where('p.business_id', $businessId)
                    ->whereIn('p.artist', $chunk) // case-insensitive collation
                    // Only music shelves: skip merch, cards, VHS, magazines.
                    ->whereRaw("LOWER(pc.name) REGEXP 'vinyl|cd|cassette|record|lp|45|7\"|10\"|12\"|reel'")
                    ->groupBy('p.artist', 'c.name')
                    ->get(['p.artist', 'c.name as genre', DB::raw('COUNT(*) as n')]);
                foreach ($rows as $r) {
                    $artist = $lookup->normalizeArtist((string) $r->artist);
                    if (!isset($sheet[$artist])) {
                        continue;
                    }
                    $bin = self::binForSubcategory((string) $r->genre);
                    if ($bin !== null) {
                        $mapped[mb_strtolower(trim((string) $r->genre))] = $bin;
                    }
                    if ($bin === null) {
                        $g = mb_strtolower(trim((string) $r->genre));
                        $unmapped[$g] = ($unmapped[$g] ?? 0) + (int) $r->n;
                        continue;
                    }
                    $counts[$artist][$bin] = ($counts[$artist][$bin] ?? 0) + (int) $r->n;
                }
            }

            $overrides = [];
            foreach ($counts as $artist => &$bins) {
                arsort($bins);
            }
            unset($bins);
            foreach ($counts as $artist => $bins) {
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
                'unmapped_subcategories' => (object) $unmapped,
                'subcategory_bins' => (object) $mapped,
                'counts' => $counts,
            ];
        });
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
