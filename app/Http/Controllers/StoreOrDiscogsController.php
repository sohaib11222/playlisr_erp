<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * "Store or Discogs?" (Sarah 2026-11-01: searching the products page one
 * album at a time is too slow when deciding where a big stack should go).
 *
 * Paste many albums (one "Artist - Title" per line) and get, per line:
 * how many of that exact title we've sold, how many of the artist we've
 * sold (Pico vs Hollywood), how many of the artist are on our shelves now,
 * and a verdict — Pico, Hollywood, or Discogs.
 *
 *   sold this title before                 → store that sold it most
 *   sold the artist, not overstocked       → store that sold the artist most
 *   sold the artist, shelves already full  → Discogs (overstocked)
 *   never sold the artist, have 3+ already → Discogs
 *   never sold the artist, have few        → lean Discogs (no history)
 *
 * Counts include the imported pre-ERP sales sheets (legacy_artist /
 * legacy_title). One batch = a handful of queries, not one per album.
 * Read-only.
 */
class StoreOrDiscogsController extends Controller
{
    const MAX_LINES = 60;
    /** Artist copies on hand per copy sold in the last 12 months before we call it overstocked. */
    const OVERSTOCK_RATIO = 3;
    /** Never-sold artist with this many copies already on hand = clearly a Discogs artist. */
    const NEVER_SOLD_HAVE_LOTS = 3;
    /** Sales older than this many years don't count as "it sells here". */
    const RECENT_YEARS = 3;
    /** Artist sell-through (sold ÷ (sold + on hand)) below this = it doesn't move here. */
    const MIN_SELL_THROUGH = 0.2;
    /** Default "too cheap to list on Discogs" cutoff; editable on the page. */
    const MIN_DISCOGS_VALUE = 10;

    protected function allowed()
    {
        return auth()->user()->can('product.view') || auth()->user()->can('product.update');
    }

    public function index()
    {
        if (!$this->allowed()) { abort(403); }
        return view('products.store_or_discogs', [
            'overstockRatio' => self::OVERSTOCK_RATIO,
            'neverSoldLots' => self::NEVER_SOLD_HAVE_LOTS,
            'maxLines' => self::MAX_LINES,
            'recentYears' => self::RECENT_YEARS,
            'minSellThrough' => (int) round(self::MIN_SELL_THROUGH * 100),
            'minDiscogsValue' => self::MIN_DISCOGS_VALUE,
        ]);
    }

    /** Unpriced Buy-from-Customer records, as "Artist - Title" lines, to check in one click. */
    public function unpricedBuys(Request $request)
    {
        if (!$this->allowed()) { abort(403); }
        $business_id = $request->session()->get('user.business_id');
        $q = \DB::table('products as p')
            ->where('p.business_id', $business_id)->where('p.is_inactive', 0)->where('p.not_for_selling', 1);
        if (\Schema::hasColumn('products', 'added_via')) {
            $q->where(function ($w) {
                $w->where('p.added_via', 'buy_from_customer')->orWhere('p.product_description', 'like', 'Bought from customer%');
            });
        } else {
            $q->where('p.product_description', 'like', 'Bought from customer%');
        }
        $lines = [];
        foreach ($q->orderByDesc('p.id')->limit(500)->get(['p.artist', 'p.name']) as $r) {
            $artist = trim((string) $r->artist);
            $title = trim((string) $r->name);
            $lines[] = $artist !== '' ? ($artist . ' - ' . $this->stripArtistPrefix($title, $artist)) : $title;
        }
        return response()->json(['lines' => $lines]);
    }

    public function check(Request $request)
    {
        if (!$this->allowed()) { abort(403); }
        $business_id = $request->session()->get('user.business_id');

        $items = [];
        foreach (array_slice((array) $request->input('lines', []), 0, self::MAX_LINES) as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') continue;
            [$artist, $title] = $this->splitLine($raw);
            $items[] = ['line' => $raw, 'artist' => $artist, 'title' => $title,
                'akey' => $this->norm($artist), 'tkey' => $this->normTitle($title)];
        }
        if (!$items) return response()->json(['rows' => []]);

        // ---- Store name → pico / hollywood
        $storeOf = [];
        foreach (\DB::table('business_locations')->where('business_id', $business_id)->pluck('name', 'id') as $id => $name) {
            $n = mb_strtolower((string) $name);
            $storeOf[(int) $id] = strpos($n, 'hollywood') !== false ? 'hollywood' : (strpos($n, 'pico') !== false ? 'pico' : 'other');
        }

        [$soldBy, $haveBy] = $this->gather($business_id, $items, $storeOf);

        // Lines pasted the other way round ("Rumours - Fleetwood Mac"): if the
        // artist side found nothing, try the title side as the artist.
        $flipped = [];
        foreach ($items as $i => $it) {
            if ($it['title'] === '' || isset($soldBy[$it['akey']]) || isset($haveBy[$it['akey']])) continue;
            $flipped[$i] = ['line' => $it['line'], 'artist' => $it['title'], 'title' => $it['artist'],
                'akey' => $this->norm($it['title']), 'tkey' => $this->normTitle($it['artist'])];
        }
        if ($flipped) {
            [$soldBy2, $haveBy2] = $this->gather($business_id, array_values($flipped), $storeOf);
            foreach ($flipped as $i => $f) {
                if (!isset($soldBy2[$f['akey']]) && !isset($haveBy2[$f['akey']])) continue;
                $items[$i] = $f + ['swapped' => true];
                if (isset($soldBy2[$f['akey']])) $soldBy[$f['akey']] = $soldBy2[$f['akey']];
                if (isset($haveBy2[$f['akey']])) $haveBy[$f['akey']] = $haveBy2[$f['akey']];
            }
        }

        $yearAgo = now()->subYear()->toDateTimeString();
        $recentFrom = now()->subYears(self::RECENT_YEARS)->toDateTimeString();
        $rows = [];
        foreach ($items as $it) {
            $r = ['line' => $it['line'], 'artist' => $it['artist'], 'title' => $it['title'],
                'title_sold' => ['pico' => 0, 'hollywood' => 0, 'other' => 0], 'title_last_price' => null, 'title_last_at' => null,
                'artist_sold' => ['pico' => 0, 'hollywood' => 0, 'other' => 0], 'artist_sold_12m' => 0, 'artist_sold_recent' => 0, 'artist_last_at' => null, 'artist_avg_price' => null,
                'artist_have' => ['pico' => 0, 'hollywood' => 0, 'other' => 0], 'title_have' => 0];
            $priceSum = 0;
            foreach ($soldBy[$it['akey']] ?? [] as $s) {
                $r['artist_sold'][$s['store']] += $s['qty'];
                if ($s['at'] >= $yearAgo) $r['artist_sold_12m'] += $s['qty'];
                if ($s['at'] >= $recentFrom) $r['artist_sold_recent'] += $s['qty'];
                $priceSum += $s['price'] * $s['qty'];
                if ($s['at'] > (string) $r['artist_last_at']) $r['artist_last_at'] = $s['at'];
                if ($it['tkey'] !== '' && $this->sameTitle($it['tkey'], $s['t'])) {
                    $r['title_sold'][$s['store']] += $s['qty'];
                    if ($s['at'] > (string) $r['title_last_at']) { $r['title_last_at'] = $s['at']; $r['title_last_price'] = round($s['price'], 2); }
                }
            }
            $artistQty = array_sum($r['artist_sold']);
            if ($artistQty > 0 && $priceSum > 0) $r['artist_avg_price'] = round($priceSum / $artistQty, 2);
            foreach ($haveBy[$it['akey']] ?? [] as $h) {
                $r['artist_have'][$h['store']] += $h['qty'];
                if ($it['tkey'] !== '' && $this->sameTitle($it['tkey'], $h['t'])) $r['title_have'] += $h['qty'];
            }
            [$r['verdict'], $r['where'], $r['why']] = $this->verdict($it, $r, $recentFrom);
            if (!empty($it['swapped'])) {
                $r['swapped'] = true;
                $r['why'] = 'Read as Title - Artist. ' . $r['why'];
            }
            foreach (['title_last_at', 'artist_last_at'] as $k) { if ($r[$k]) $r[$k] = substr($r[$k], 0, 10); }
            $rows[] = $r;
        }
        return response()->json(['rows' => $rows]);
    }

    /**
     * Sales (ERP + pre-ERP sheets) and on-hand stock for the items' artists,
     * as [soldBy, haveBy], each keyed by normalized artist.
     */
    protected function gather($business_id, $items, $storeOf)
    {
        // ---- One LIKE per artist on its longest word, then exact match on the normalized key in PHP.
        // Very short names ("M", "U2") match too much as a LIKE, so those go by exact name.
        $needles = [];
        $exact = [];
        foreach ($items as $it) {
            if ($it['akey'] === '') continue;
            $words = explode(' ', $it['akey']);
            usort($words, function ($a, $b) { return mb_strlen($b) <=> mb_strlen($a); });
            if (mb_strlen($words[0]) >= 3) {
                $needles[$words[0]] = true;
            } else {
                $exact[mb_strtolower($it['artist'])] = true;
                $exact['the ' . $it['akey']] = true;
                $exact[$it['akey']] = true;
            }
        }
        $needles = array_keys($needles);
        $exact = array_keys($exact);
        $wanted = array_flip(array_filter(array_column($items, 'akey')));

        $products = collect();
        $legacy = collect();
        if ($needles || $exact) {
            $likeAny = function ($q, $col) use ($needles, $exact) {
                $q->where(function ($w) use ($needles, $exact, $col) {
                    foreach ($needles as $n) { $w->orWhereRaw("LOWER($col) LIKE ?", ['%' . $n . '%']); }
                    if ($exact) {
                        $w->orWhereRaw("LOWER(TRIM($col)) IN (" . implode(',', array_fill(0, count($exact), '?')) . ')', $exact);
                    }
                });
            };
            $pq = \DB::table('products as p')->where('p.business_id', $business_id)->where('p.sku', '!=', 'NIV-LEGACY-HIST');
            $likeAny($pq, 'p.artist');
            $products = $pq->get(['p.id', 'p.artist', 'p.name'])->filter(function ($p) use ($wanted) {
                return isset($wanted[$this->norm($p->artist)]);
            })->keyBy('id');

            // Imported pre-ERP sales hang off one placeholder product; the artist/title live on the line.
            $placeholderIds = \DB::table('products')->where('business_id', $business_id)
                ->where('sku', 'NIV-LEGACY-HIST')->pluck('id')->all();
            if ($placeholderIds) {
                $lq = \DB::table('transaction_sell_lines as tsl')
                    ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
                    ->whereIn('tsl.product_id', $placeholderIds)
                    ->where('t.business_id', $business_id)->where('t.type', 'sell')->where('t.status', 'final');
                $likeAny($lq, 'tsl.legacy_artist');
                $legacy = $lq->get(['tsl.legacy_artist as artist', 'tsl.legacy_title as title', 't.location_id',
                        't.transaction_date as sold_at', 'tsl.unit_price_inc_tax as price',
                        \DB::raw('tsl.quantity - COALESCE(tsl.quantity_returned, 0) as qty')])
                    ->filter(function ($l) use ($wanted) { return isset($wanted[$this->norm($l->artist)]); });
            }
        }

        // ---- ERP sales + current stock for the matched products
        $sales = collect();
        $stock = collect();
        $ids = $products->keys()->all();
        foreach (array_chunk($ids, 2000) as $chunk) {
            $sales = $sales->merge(\DB::table('transaction_sell_lines as tsl')
                ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
                ->whereIn('tsl.product_id', $chunk)
                ->where('t.business_id', $business_id)->where('t.type', 'sell')->where('t.status', 'final')
                ->get(['tsl.product_id', 't.location_id', 't.transaction_date as sold_at', 'tsl.unit_price_inc_tax as price',
                    \DB::raw('tsl.quantity - COALESCE(tsl.quantity_returned, 0) as qty')]));
            $stock = $stock->merge(\DB::table('variation_location_details')
                ->whereIn('product_id', $chunk)->where('qty_available', '>', 0)
                ->get(['product_id', 'location_id', 'qty_available']));
        }

        // ---- Fold everything into per-artist buckets of {artist key, title key, store, qty, price, date}
        $soldBy = [];
        $haveBy = [];
        foreach ($sales as $s) {
            $p = $products[$s->product_id];
            if ((float) $s->qty <= 0) continue;
            $soldBy[$this->norm($p->artist)][] = ['t' => $this->normTitle($this->stripArtistPrefix($p->name, $p->artist)),
                'store' => $storeOf[(int) $s->location_id] ?? 'other', 'qty' => (float) $s->qty,
                'price' => (float) $s->price, 'at' => (string) $s->sold_at];
        }
        foreach ($legacy as $l) {
            if ((float) $l->qty <= 0) continue;
            $soldBy[$this->norm($l->artist)][] = ['t' => $this->normTitle((string) $l->title),
                'store' => $storeOf[(int) $l->location_id] ?? 'other', 'qty' => (float) $l->qty,
                'price' => (float) $l->price, 'at' => (string) $l->sold_at];
        }
        foreach ($stock as $v) {
            $p = $products[$v->product_id];
            $haveBy[$this->norm($p->artist)][] = ['t' => $this->normTitle($this->stripArtistPrefix($p->name, $p->artist)),
                'store' => $storeOf[(int) $v->location_id] ?? 'other', 'qty' => (float) $v->qty_available];
        }

        return [$soldBy, $haveBy];
    }

    /** [verdict: store|discogs|lean_discogs|unknown, where: Pico|Hollywood|Discogs, why] */
    protected function verdict($it, $r, $recentFrom)
    {
        if ($it['akey'] === '') return ['unknown', '', 'No artist on this line'];
        $titleSold = array_sum($r['title_sold']);
        $artistSold = array_sum($r['artist_sold']);
        $have = array_sum($r['artist_have']);
        $overstocked = $have > max(1, $r['artist_sold_12m']) * self::OVERSTOCK_RATIO;
        $sellThrough = $artistSold > 0 ? $artistSold / ($artistSold + $have) : 0;
        $year = function ($at) { return substr((string) $at, 0, 4); };
        $best = function ($counts) {
            if ($counts['pico'] == $counts['hollywood']) return 'Pico or Hollywood';
            return $counts['pico'] > $counts['hollywood'] ? 'Pico' : 'Hollywood';
        };

        $note = '';
        if ($titleSold > 0) {
            if ((string) $r['title_last_at'] >= $recentFrom) {
                $why = 'Sold this title ' . $this->n($titleSold) . 'x';
                if ($overstocked) $why .= ' (but we have ' . $this->n($have) . ' of the artist)';
                return ['store', $best($r['title_sold']), $why];
            }
            $note = 'Title last sold ' . $year($r['title_last_at']) . '. ';
        }
        if ($artistSold > 0) {
            if ($r['artist_sold_recent'] <= 0) {
                return ['discogs', 'Discogs', $note . 'Artist last sold ' . $year($r['artist_last_at']) . ', nothing since'];
            }
            if ($overstocked) {
                return ['discogs', 'Discogs', $note . 'Artist sells (' . $this->n($r['artist_sold_12m']) . ' in 12 mo) but we already have ' . $this->n($have)];
            }
            if ($sellThrough < self::MIN_SELL_THROUGH) {
                return ['discogs', 'Discogs', $note . 'Only ' . round($sellThrough * 100) . '% of this artist we stocked has sold (' . $this->n($artistSold) . ' sold, ' . $this->n($have) . ' left)'];
            }
            return ['store', $best($r['artist_sold']), $note . 'Sold this artist ' . $this->n($artistSold) . 'x' . ($titleSold > 0 ? '' : ', not this title')];
        }
        if ($have >= self::NEVER_SOLD_HAVE_LOTS) {
            return ['discogs', 'Discogs', 'Never sold this artist, already have ' . $this->n($have)];
        }
        return ['lean_discogs', 'Discogs', 'Never sold this artist'];
    }

    /**
     * What the album goes for on Discogs: top release match for artist + title,
     * then its marketplace stats (lowest asking price, copies for sale) and
     * community want/have. Cached 30 days per album. Discogs allows ~60 calls
     * a minute and this is 2, so the page asks one album at a time.
     */
    public function value(Request $request)
    {
        if (!$this->allowed()) { abort(403); }
        $business_id = $request->session()->get('user.business_id');
        [$artist, $title] = [trim((string) $request->input('artist')), trim((string) $request->input('title'))];
        if ($artist === '' && $title === '') return response()->json(['found' => false]);
        $key = 'sod-value:' . md5($this->norm($artist) . '|' . $this->normTitle($title));
        if ($hit = \Cache::get($key)) return response()->json($hit + ['cached' => true]);

        $discogs = new \App\Services\DiscogsService($business_id);
        if (!$discogs->isConfigured()) return response()->json(['found' => false, 'error' => 'Discogs not connected']);
        $search = $discogs->getRelease(null, $artist, $title);
        if (empty($search['error']) && empty($search['data']->results)) {
            // "Beatles, The" style names miss the artist field; a plain query is looser.
            $search = $discogs->getRelease(trim($artist . ' ' . $title));
        }
        if (!empty($search['error'])) {
            $limited = strpos((string) $search['message'], '429') !== false;
            return response()->json(['found' => false, 'error' => $search['message'], 'rate_limited' => $limited], $limited ? 429 : 200);
        }
        $hit = $search['data']->results[0] ?? null;
        if (!$hit) {
            $out = ['found' => false];
            \Cache::put($key, $out, now()->addDays(30));
            return response()->json($out);
        }
        $stats = $discogs->callApi('https://api.discogs.com/marketplace/stats/' . (int) $hit->id . '?curr_abbr=USD&token=' . urlencode($this->discogsToken($business_id)));
        if (!empty($stats['error']) && strpos((string) $stats['message'], '429') !== false) {
            return response()->json(['found' => false, 'rate_limited' => true], 429);
        }
        $st = $stats['data'] ?? null;
        $out = [
            'found' => true,
            'release_id' => (int) $hit->id,
            'uri' => 'https://www.discogs.com/release/' . (int) $hit->id,
            'lowest' => isset($st->lowest_price->value) ? round((float) $st->lowest_price->value, 2) : null,
            'for_sale' => isset($st->num_for_sale) ? (int) $st->num_for_sale : null,
            'want' => (int) ($hit->community->want ?? 0),
            'have' => (int) ($hit->community->have ?? 0),
        ];
        \Cache::put($key, $out, now()->addDays(30));
        return response()->json($out);
    }

    protected function discogsToken($business_id)
    {
        $d = (new \App\Utils\BusinessUtil())->getApiSettings($business_id)['discogs'] ?? [];
        $t = trim((string) ($d['token'] ?? ''));
        return $t !== '' ? $t : trim((string) ($d['user_token'] ?? ''));
    }

    protected function n($x)
    {
        return rtrim(rtrim(number_format((float) $x, 2, '.', ''), '0'), '.');
    }

    /** "Artist - Title" (also tab, en/em dash). No separator = the whole line is the artist. */
    protected function splitLine($raw)
    {
        // Discogs copy/paste carries invisible direction marks ("Artist \u{200E}– Title").
        $raw = trim(preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u', '', (string) $raw));
        $raw = preg_replace('/\s+/u', ' ', str_replace("\t", ' - ', $raw));
        foreach (["\t", ' - ', ' – ', ' — ', ' -- '] as $sep) {
            $pos = strpos($raw, $sep);
            if ($pos !== false) {
                return [trim(substr($raw, 0, $pos)), trim(substr($raw, $pos + strlen($sep)), " \t-–—")];
            }
        }
        return [$raw, ''];
    }

    /** Lowercase, no accents/punctuation, "The Beatles" = "Beatles, The" = "Beatles", Discogs "(2)" dropped. */
    protected function norm($s)
    {
        $s = mb_strtolower(trim((string) $s));
        $s = preg_replace('/\s*\(\d+\)\s*$/u', '', $s);
        $s = preg_replace('/,\s*the$/u', '', $s);
        $s = preg_replace('/^the\s+/u', '', $s);
        $s = str_replace('&', ' and ', $s);
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
        if ($t !== false && $t !== '') $s = $t;
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** Title key: also drops (Remastered) / [Deluxe] style tails. */
    protected function normTitle($s)
    {
        $s = preg_replace('/[\(\[][^\)\]]*[\)\]]/u', ' ', (string) $s);
        return $this->norm($s);
    }

    /** Some product names are "Artist - Title"; strip the artist part. */
    protected function stripArtistPrefix($name, $artist)
    {
        $name = trim((string) $name);
        [$a, $t] = $this->splitLine($name);
        if ($t !== '' && $this->norm($a) === $this->norm($artist)) return $t;
        return $name;
    }

    protected function sameTitle($a, $b)
    {
        if ($a === '' || $b === '') return false;
        if ($a === $b) return true;
        // "abbey road" vs "abbey road 2019 remaster": shorter is a whole-word prefix of the longer.
        [$s, $l] = mb_strlen($a) <= mb_strlen($b) ? [$a, $b] : [$b, $a];
        return mb_strlen($s) >= 4 && strpos($l . ' ', $s . ' ') === 0;
    }
}
