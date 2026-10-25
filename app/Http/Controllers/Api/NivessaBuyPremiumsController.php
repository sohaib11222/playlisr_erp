<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BuyDemandService;
use App\Services\BuyMarginSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Public "what pays more / less right now" list per buying store, for the
 * nivessa.com /buy-sell-trade pages (Sarah 2026-10-25). Same per-genre levels
 * the Beta buy form uses (BuyDemandService::levelsForStore): hot / warm pay
 * more, overstocked (cool / cold) pays less. Only names and percentages go
 * out, never sold / on-hand counts.
 *
 * Also per genre (Sarah 2026-10-25): artists we want (selling faster than we
 * stock them) and recent big-ticket sales, names only, no prices.
 */
class NivessaBuyPremiumsController extends Controller
{
    // Formats the Beta form pays bonuses on; everything else is cuts-only.
    const MUSIC_FORMAT = '/vinyl|\bcds?\b|compact disc|cassette|tape|\b45s?\b|7"/i';
    const WINDOW_DAYS = 60;
    const ARTIST_MIN_SOLD = 3;
    const ARTISTS_PER_GENRE = 8;
    const RARE_WINDOW_DAYS = 180;
    const RARE_MIN_PRICE = 50;
    const RARE_PER_GENRE = 5;
    const NOT_AN_ARTIST = '/^(various|various artists|v\/a|va|unknown|unknown artist|n\/a|none|-+)$/i';

    public function publicFeed()
    {
        $data = Cache::remember('buypremiums4', 3600, function () {
            $businessId = (int) (config('services.nivessa_web.business_id') ?: 1);
            $s = BuyMarginSettings::get($businessId);
            $pct = [
                'hot' => (int) $s['bonus_hot'],
                'warm' => (int) $s['bonus_warm'],
                'cool' => -10,
                'cold' => -20,
            ];
            $cats = DB::table('categories')->where('business_id', $businessId)->whereNull('deleted_at')
                ->pluck('name', 'id')->all();
            $parents = DB::table('categories')->where('business_id', $businessId)->whereNull('deleted_at')
                ->pluck('parent_id', 'id')->all();
            $stores = DB::table('business_locations')->where('business_id', $businessId)
                ->where(function ($q) {
                    $q->where('name', 'like', '%pico%')->orWhere('name', 'like', '%hollywood%');
                })->select('id', 'name')->get();

            $svc = new BuyDemandService();
            $out = [];
            foreach ($stores as $store) {
                $key = stripos($store->name, 'pico') !== false ? 'pico' : 'hollywood';
                $more = [];
                $less = [];
                foreach ($svc->levelsForStore($businessId, $store->id) as $subId => $row) {
                    $level = $row['level'];
                    if (!isset($pct[$level]) || !isset($cats[$subId])) {
                        continue;
                    }
                    $format = (string) ($cats[(int) ($parents[$subId] ?? 0)] ?? '');
                    $genre = trim((string) $cats[$subId]);
                    if ($format === '' || $genre === '' || preg_match('/^(other|misc|general|n\/?a)\b/i', $genre)) {
                        continue;
                    }
                    $item = ['format' => $format, 'genre' => $genre, 'level' => $level, 'pct' => $pct[$level]];
                    if ($pct[$level] > 0) {
                        if (preg_match(self::MUSIC_FORMAT, $format)) {
                            $more[] = $item;
                        }
                    } else {
                        $less[] = $item;
                    }
                }
                $sort = function ($a, $b) {
                    return abs($b['pct']) <=> abs($a['pct']) ?: strcasecmp($a['format'] . $a['genre'], $b['format'] . $b['genre']);
                };
                usort($more, $sort);
                usort($less, $sort);
                $genres = [];
                foreach ($this->wanted($businessId, (int) $store->id) as $subId => $g) {
                    if (!isset($cats[$subId])) {
                        continue;
                    }
                    $format = (string) ($cats[(int) ($parents[$subId] ?? 0)] ?? '');
                    if ($format === '' || !preg_match(self::MUSIC_FORMAT, $format)) {
                        continue;
                    }
                    $genres[] = ['format' => $format, 'genre' => trim((string) $cats[$subId])] + $g;
                }
                $out[$key] = [
                    'name' => $key === 'pico' ? 'Pico' : 'Hollywood',
                    'more' => $more,
                    'less' => $less,
                    'genres' => $genres,
                ];
            }
            return ['updated' => now()->toDateTimeString(), 'stores' => $out];
        });

        return response()->json($data)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', 'public, max-age=900');
    }

    /**
     * [sub_category_id => ['artists' => [...], 'rare' => [...]]] for one store.
     * Artists: ARTIST_MIN_SOLD+ sold in WINDOW_DAYS and no more on the shelf
     * than we sold, most sold first. Rare: the priciest single sales
     * (RARE_MIN_PRICE+) in RARE_WINDOW_DAYS, one per title.
     */
    /** "JACKSON, MICHAEL" / "Squeeze (2)" / "THE POLICE" -> "Michael Jackson" / "Squeeze" / "The Police". */
    public static function cleanArtist($a)
    {
        $a = trim(preg_replace('/\s*\(\d+\)\s*$/', '', trim($a)));
        if ($a === mb_strtoupper($a) && !preg_match('/&|\band\b|,\s*the\b/i', $a) && preg_match('/^([^,]+),\s*([^,]+)$/', $a, $m) && str_word_count($m[1]) <= 2 && str_word_count($m[2]) <= 2) {
            $a = trim($m[2]) . ' ' . trim($m[1]);
        }
        if ($a === mb_strtoupper($a) || $a === mb_strtolower($a)) {
            $a = mb_convert_case(mb_strtolower($a), MB_CASE_TITLE);
        }
        return $a;
    }

    /**
     * Catalog names come as "Artist - Title", "ARTIST / TITLE (180G/2LP...",
     * or "ARTIST - LAST,FIRST / TITLE". Returns "Artist - Title" or null when
     * there's no usable title.
     */
    public static function cleanTitle($name, $artist)
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        // Drop bracketed edition notes, including ones cut off mid-way.
        $name = trim(preg_replace('/\s*[\(\[][^\)\]]*([\)\]]|$)/', '', $name));
        if (preg_match(self::NOT_AN_ARTIST, $artist)) {
            $artist = '';
        }
        $sep = '/\s+[-–—\/]\s+/u';
        if ($artist !== '') {
            // "Artist - Title": cut the artist off the front; otherwise the
            // whole name is the title.
            $title = mb_stripos($name, $artist) === 0 ? mb_substr($name, mb_strlen($artist)) : $name;
            $title = preg_replace('/^\s*[-–—\/:]\s*/u', '', $title);
            $who = $artist;
        } else {
            $parts = preg_split($sep, $name, 2);
            if (count($parts) < 2) {
                return null;
            }
            list($who, $title) = $parts;
        }
        // Distributor style "LATEEF,YUSEF / INTO SOMETHING": drop the name chunk.
        $title = trim(preg_replace('/^[^\/]+,[^\/]+\s\/\s/u', '', trim($title)));
        if ($title === '' || mb_strtolower($title) === mb_strtolower($who)) {
            return null;
        }
        if ($title === mb_strtoupper($title)) {
            $title = mb_convert_case(mb_strtolower($title), MB_CASE_TITLE);
        }
        return self::cleanArtist($who) . ' - ' . $title;
    }

    protected function wanted($businessId, $locationId)
    {
        $sold = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->where('t.business_id', $businessId)
            ->where('t.location_id', $locationId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.transaction_date', '>=', now()->subDays(self::WINDOW_DAYS))
            ->whereNull('tsl.parent_sell_line_id')
            ->where('p.sub_category_id', '>', 0)
            ->whereNotNull('p.artist')->where('p.artist', '!=', '')
            ->groupBy('p.sub_category_id', 'p.artist')
            ->havingRaw('q >= ?', [self::ARTIST_MIN_SOLD])
            ->selectRaw('p.sub_category_id as sub, p.artist as artist, SUM(tsl.quantity - COALESCE(tsl.quantity_returned, 0)) as q')
            ->get();
        $stock = [];
        DB::table('variation_location_details as vld')
            ->join('products as p', 'p.id', '=', 'vld.product_id')
            ->where('p.business_id', $businessId)
            ->where('vld.location_id', $locationId)
            ->where('vld.qty_available', '>', 0)
            ->where('p.sub_category_id', '>', 0)
            ->whereNotNull('p.artist')->where('p.artist', '!=', '')
            ->groupBy('p.sub_category_id', 'p.artist')
            ->selectRaw('p.sub_category_id as sub, p.artist as artist, SUM(vld.qty_available) as q')
            ->get()
            ->each(function ($r) use (&$stock) {
                $stock[(int) $r->sub . '|' . mb_strtolower(trim($r->artist))] = (float) $r->q;
            });

        $out = [];
        $rows = $sold->sortByDesc(function ($r) { return (float) $r->q; });
        foreach ($rows as $r) {
            $raw = trim((string) $r->artist);
            if (preg_match(self::NOT_AN_ARTIST, $raw)) {
                continue;
            }
            $sub = (int) $r->sub;
            if (($stock[$sub . '|' . mb_strtolower($raw)] ?? 0) > (float) $r->q) {
                continue;
            }
            $artist = self::cleanArtist($raw);
            $out[$sub]['artists'] = $out[$sub]['artists'] ?? [];
            if (count($out[$sub]['artists']) < self::ARTISTS_PER_GENRE
                && !in_array(mb_strtolower($artist), array_map('mb_strtolower', $out[$sub]['artists']), true)) {
                $out[$sub]['artists'][] = $artist;
            }
        }

        $rare = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->where('t.business_id', $businessId)
            ->where('t.location_id', $locationId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.transaction_date', '>=', now()->subDays(self::RARE_WINDOW_DAYS))
            ->whereNull('tsl.parent_sell_line_id')
            ->where('p.sub_category_id', '>', 0)
            ->where('tsl.unit_price_inc_tax', '>=', self::RARE_MIN_PRICE)
            ->orderByDesc('tsl.unit_price_inc_tax')
            ->limit(2000)
            ->select('p.sub_category_id as sub', 'p.name', 'p.artist')
            ->get();
        // New sealed stock is retail, not something people bring in to sell.
        $sealedSubs = DB::table('categories as c')
            ->join('categories as f', 'f.id', '=', 'c.parent_id')
            ->where('c.business_id', $businessId)
            ->where(function ($q) {
                $q->where('f.name', 'like', '%sealed%')->orWhere('f.name', 'like', '%new%');
            })->pluck('c.id')->map(function ($v) { return (int) $v; })->flip()->all();
        $seen = [];
        foreach ($rare as $r) {
            $sub = (int) $r->sub;
            if (isset($sealedSubs[$sub])) {
                continue;
            }
            $name = self::cleanTitle((string) $r->name, trim((string) $r->artist));
            if ($name === null) {
                continue;
            }
            $k = $sub . '|' . mb_strtolower($name);
            if ($name === '' || isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[$sub]['rare'] = $out[$sub]['rare'] ?? [];
            if (count($out[$sub]['rare']) < self::RARE_PER_GENRE) {
                $out[$sub]['rare'][] = $name;
            }
        }
        foreach ($out as $sub => $g) {
            $out[$sub] += ['artists' => [], 'rare' => []];
        }
        return $out;
    }
}
