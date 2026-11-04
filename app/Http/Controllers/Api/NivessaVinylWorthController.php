<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Public used-vinyl price list for the nivessa.com /vinyl-worth value
 * database (Sarah 2026-11-04 "include 1000s more items from our ERP").
 * One row per artist + album we've actually sold used on vinyl, with the
 * typical price it went for. Names and prices only, no counts or stores.
 */
class NivessaVinylWorthController extends Controller
{
    const WINDOW_YEARS = 3;
    const MIN_PRICE = 2;
    const MAX_ROWS = 15000;
    const JUNK_TITLE = '/^(lp|vinyl|record|records|untitled|unknown|n\/?a|-+|\d+)$/i';

    public function publicFeed()
    {
        $data = Cache::remember('vinylworth1', 86400, function () {
            $businessId = (int) (config('services.nivessa_web.business_id') ?: 1);
            ini_set('memory_limit', '1024M');

            $usedVinylCats = DB::table('categories')
                ->where('business_id', $businessId)
                ->whereNull('deleted_at')
                ->where('name', 'like', '%used%')
                ->where(function ($q) {
                    $q->where('name', 'like', '%vinyl%')->orWhere('name', 'like', '%LP%');
                })
                ->pluck('id')->all();
            if (!$usedVinylCats) {
                return ['updated' => now()->toDateTimeString(), 'records' => []];
            }

            // Per product: every sale price, so a title's range reflects
            // what copies really went for.
            $rows = DB::table('transaction_sell_lines as tsl')
                ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
                ->join('products as p', 'p.id', '=', 'tsl.product_id')
                ->where('t.business_id', $businessId)
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->where('t.transaction_date', '>=', now()->subYears(self::WINDOW_YEARS))
                ->whereNull('tsl.parent_sell_line_id')
                ->whereIn('p.category_id', $usedVinylCats)
                ->whereNotNull('p.artist')->where('p.artist', '!=', '')
                ->where('tsl.unit_price_inc_tax', '>=', self::MIN_PRICE)
                ->whereRaw('tsl.quantity - COALESCE(tsl.quantity_returned, 0) > 0')
                ->groupBy('p.id', 'p.name', 'p.artist')
                ->selectRaw('p.name, p.artist, GROUP_CONCAT(ROUND(tsl.unit_price_inc_tax) SEPARATOR ",") as prices')
                ->get();

            $titles = [];
            foreach ($rows as $r) {
                $artistRaw = trim((string) $r->artist);
                if (preg_match('/^(various|various artists|v\/a|va|unknown|unknown artist|n\/a|none|-+)$/i', $artistRaw)) {
                    continue;
                }
                $full = NivessaBuyPremiumsController::cleanTitle((string) $r->name, $artistRaw);
                if ($full === null) {
                    continue;
                }
                $artist = NivessaBuyPremiumsController::cleanArtist($artistRaw);
                $album = mb_stripos($full, $artist . ' - ') === 0
                    ? mb_substr($full, mb_strlen($artist) + 3)
                    : (preg_split('/\s+-\s+/u', $full, 2)[1] ?? '');
                // Trailing format words: "Rumours LP", "Thriller 180g Vinyl".
                $album = trim(preg_replace('/(\s+(-\s*)?(\d?x?LP|vinyl|180\s?g(ram)?|lp record|reissue|used))+\s*$/i', '', $album));
                $album = trim($album, " \t-–—/:,");
                if ($album === '' || mb_strlen($album) > 120 || preg_match(self::JUNK_TITLE, $album)) {
                    continue;
                }
                $k = mb_strtolower($artist . '|' . $album);
                if (!isset($titles[$k])) {
                    $titles[$k] = ['artist' => $artist, 'album' => $album, 'prices' => []];
                }
                foreach (explode(',', (string) $r->prices) as $p) {
                    if ((float) $p >= self::MIN_PRICE) {
                        $titles[$k]['prices'][] = (float) $p;
                    }
                }
            }

            // Most-sold titles first, so the cap keeps the ones people own.
            uasort($titles, function ($a, $b) {
                return count($b['prices']) <=> count($a['prices']);
            });
            $titles = array_slice($titles, 0, self::MAX_ROWS);

            $out = [];
            foreach ($titles as $t) {
                $p = $t['prices'];
                if (!$p) {
                    continue;
                }
                sort($p);
                $n = count($p);
                if ($n >= 4) {
                    // Middle half, so one odd sale doesn't stretch the range.
                    $lo = $p[(int) floor(($n - 1) * 0.25)];
                    $hi = $p[(int) ceil(($n - 1) * 0.75)];
                } else {
                    $lo = $p[0];
                    $hi = $p[$n - 1];
                }
                $lo = (int) round($lo);
                $hi = (int) round($hi);
                $out[] = [
                    'artist' => mb_convert_encoding($t['artist'], 'UTF-8', 'UTF-8'),
                    'album' => mb_convert_encoding($t['album'], 'UTF-8', 'UTF-8'),
                    'worth' => $lo === $hi ? '$' . $lo : '$' . $lo . '-$' . $hi,
                ];
            }
            usort($out, function ($a, $b) {
                return strcasecmp($a['artist'], $b['artist']) ?: strcasecmp($a['album'], $b['album']);
            });

            return ['updated' => now()->toDateTimeString(), 'records' => $out];
        });

        // Some catalog names carry stray Latin-1 bytes.
        return response()->json($data, 200, [], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', 'public, max-age=3600');
    }
}
