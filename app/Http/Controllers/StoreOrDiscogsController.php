<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * "Store or Discogs?" (Sarah 2026-11-01: searching the products page one
 * album at a time is too slow when deciding where a big stack should go).
 *
 * Paste many items, one per line, pick what they are, and get per line what
 * we've sold / have of it and a verdict: Pico, Hollywood, Discogs (music) or
 * eBay (everything else), Never sold, or Never had for sale.
 *
 * Music ("Artist - Title", matched on the artist field):
 *   sold this title in the last 3 years     → store that sold it most
 *   sold the artist recently, and it moves  → store that sold the artist most
 *   artist sells too slowly / overstocked   → Discogs
 *   had the artist listed, never sold one   → Never sold
 *   never had the artist at all             → Never had for sale
 *
 * Everything else (posters, books, DVDs, VHS…): those often have no artist,
 * so a line matches products in that category whose name has all its words.
 *   sold it in the last 3 years → store; sold it before → eBay;
 *   listed but never sold → Never sold; never listed → Never had for sale.
 *
 * Music counts include the imported pre-ERP sales sheets (legacy_artist /
 * legacy_title). One batch = a handful of queries, not one per line.
 * Read-only.
 */
class StoreOrDiscogsController extends Controller
{
    const MAX_LINES = 60;
    /** Artist copies on hand per copy sold in the last 12 months before we call it overstocked. */
    const OVERSTOCK_RATIO = 3;
    /** Sales older than this many years don't count as "it sells here". */
    const RECENT_YEARS = 3;
    /** Artist sell-through (sold ÷ (sold + on hand)) below this = it doesn't move here. */
    const MIN_SELL_THROUGH = 0.2;
    /** Default "too cheap to list online" cutoff; editable on the page. */
    const MIN_DISCOGS_VALUE = 10;
    /** Lines whose artist has less history than this (copies sold + on hand) also get tried as Title - Artist. */
    const SWAP_CHECK_BELOW = 10;
    /** Category names that are music formats (artist-based matching, Discogs). */
    const MUSIC_CATEGORY = '/vinyl|\bcds?\b|cassette|45 rpm|8 track|\brecord/i';
    /** Words that say what the thing is rather than which one it is; ignored when matching item names. */
    const ITEM_NOISE = ['the', 'a', 'an', 'of', 'and', 'vhs', 'dvd', 'blu', 'ray', 'bluray', 'book', 'books', 'poster', 'sealed', 'new', 'used', 'edition', 'hardcover', 'paperback'];

    protected function allowed()
    {
        return auth()->user()->can('product.view') || auth()->user()->can('product.update');
    }

    public function index(Request $request)
    {
        if (!$this->allowed()) { abort(403); }
        $business_id = $request->session()->get('user.business_id');
        $categories = \DB::table('categories as c')
            ->where('c.business_id', $business_id)->where('c.parent_id', 0)->where('c.category_type', 'product')->whereNull('c.deleted_at')
            ->whereExists(function ($q) { $q->select(\DB::raw(1))->from('products as p')->whereColumn('p.category_id', 'c.id'); })
            ->orderBy('c.name')->get(['c.id', 'c.name'])
            ->map(function ($c) { return ['id' => (int) $c->id, 'name' => $c->name, 'music' => (bool) preg_match(self::MUSIC_CATEGORY, $c->name)]; })
            ->values();
        return view('products.store_or_discogs', [
            'overstockRatio' => self::OVERSTOCK_RATIO,
            'maxLines' => self::MAX_LINES,
            'recentYears' => self::RECENT_YEARS,
            'minSellThrough' => (int) round(self::MIN_SELL_THROUGH * 100),
            'minDiscogsValue' => self::MIN_DISCOGS_VALUE,
            'categories' => $categories,
        ]);
    }

    /** [category row|null, is music] for the picked "What are these?" value ('' = all music). */
    protected function pickedCategory($business_id, $id)
    {
        if (empty($id)) return [null, true];
        $c = \DB::table('categories')->where('business_id', $business_id)->where('id', (int) $id)->first(['id', 'name', 'ebay_category_ids']);
        if (!$c) return [null, true];
        return [$c, (bool) preg_match(self::MUSIC_CATEGORY, $c->name)];
    }

    /** Unpriced Buy-from-Customer records (of the picked type), as lines to check in one click. */
    public function unpricedBuys(Request $request)
    {
        if (!$this->allowed()) { abort(403); }
        $business_id = $request->session()->get('user.business_id');
        [$cat, $music] = $this->pickedCategory($business_id, $request->query('category_id'));
        $q = \DB::table('products as p')
            ->where('p.business_id', $business_id)->where('p.is_inactive', 0)->where('p.not_for_selling', 1);
        if (\Schema::hasColumn('products', 'added_via')) {
            $q->where(function ($w) {
                $w->where('p.added_via', 'buy_from_customer')->orWhere('p.product_description', 'like', 'Bought from customer%');
            });
        } else {
            $q->where('p.product_description', 'like', 'Bought from customer%');
        }
        if ($cat) $q->where('p.category_id', $cat->id);
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
        [$cat, $music] = $this->pickedCategory($business_id, $request->input('category_id'));

        $raws = [];
        foreach (array_slice((array) $request->input('lines', []), 0, self::MAX_LINES) as $raw) {
            $raw = trim((string) $raw);
            if ($raw !== '') $raws[] = $raw;
        }
        if (!$raws) return response()->json(['rows' => [], 'mode' => $music ? 'music' : 'item']);

        // ---- Store name → pico / hollywood
        $storeOf = [];
        foreach (\DB::table('business_locations')->where('business_id', $business_id)->pluck('name', 'id') as $id => $name) {
            $n = mb_strtolower((string) $name);
            $storeOf[(int) $id] = strpos($n, 'hollywood') !== false ? 'hollywood' : (strpos($n, 'pico') !== false ? 'pico' : 'other');
        }

        $rows = $music
            ? $this->checkMusic($business_id, $raws, $storeOf, $cat)
            : $this->checkItems($business_id, $raws, $storeOf, $cat);
        return response()->json(['rows' => $rows, 'mode' => $music ? 'music' : 'item']);
    }

    // ===================================================================
    // Music: artist-based
    // ===================================================================

    protected function checkMusic($business_id, $raws, $storeOf, $cat)
    {
        $items = [];
        foreach ($raws as $raw) {
            [$artist, $title] = $this->splitLine($raw);
            $items[] = ['line' => $raw, 'artist' => $artist, 'title' => $title];
        }
        $items = $this->splitNoDashLines($business_id, $items);
        foreach ($items as &$it) {
            $it['akey'] = $this->norm($it['artist']);
            $it['tkey'] = $this->normTitle($it['title']);
        }
        unset($it);

        [$soldBy, $haveBy, $listedBy] = $this->gather($business_id, $items, $storeOf, $cat);

        // Lines pasted the other way round ("Rumours - Fleetwood Mac"): when the
        // artist side has little history, also try the title side as the artist
        // and keep whichever reading has clearly more. (Some products have the
        // album typed in the artist field, so a weak hit isn't proof.)
        $activity = function ($soldBy, $haveBy, $key) {
            $n = 0;
            foreach ($soldBy[$key] ?? [] as $x) $n += $x['qty'];
            foreach ($haveBy[$key] ?? [] as $x) $n += $x['qty'];
            return $n;
        };
        $flipped = [];
        foreach ($items as $i => $it) {
            if ($it['title'] === '' || $activity($soldBy, $haveBy, $it['akey']) >= self::SWAP_CHECK_BELOW) continue;
            $flipped[$i] = ['line' => $it['line'], 'artist' => $it['title'], 'title' => $it['artist'],
                'akey' => $this->norm($it['title']), 'tkey' => $this->normTitle($it['artist'])];
        }
        if ($flipped) {
            [$soldBy2, $haveBy2, $listedBy2] = $this->gather($business_id, array_values($flipped), $storeOf, $cat);
            foreach ($flipped as $i => $f) {
                $was = $activity($soldBy, $haveBy, $items[$i]['akey']);
                $now = $activity($soldBy2, $haveBy2, $f['akey']);
                if ($now < 3 || $now < $was * 3) continue;
                $items[$i] = $f + ['swapped' => true];
                if (isset($soldBy2[$f['akey']])) $soldBy[$f['akey']] = $soldBy2[$f['akey']];
                if (isset($haveBy2[$f['akey']])) $haveBy[$f['akey']] = $haveBy2[$f['akey']];
                if (isset($listedBy2[$f['akey']])) $listedBy[$f['akey']] = $listedBy2[$f['akey']];
            }
        }

        $yearAgo = now()->subYear()->toDateTimeString();
        $recentFrom = now()->subYears(self::RECENT_YEARS)->toDateTimeString();
        $rows = [];
        foreach ($items as $it) {
            $r = $this->blankRow($it['line'], $it['artist'], $it['title']);
            $r['artist_listed'] = (int) ($listedBy[$it['akey']] ?? 0);
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
            [$r['verdict'], $r['where'], $r['why']] = $this->musicVerdict($it, $r, $recentFrom);
            if (!empty($it['swapped'])) {
                $r['swapped'] = true;
                $r['why'] = 'Read as Title - Artist. ' . $r['why'];
            }
            $rows[] = $this->finishRow($r);
        }
        return $rows;
    }

    /**
     * "paul simon still crazy after all these years" (no dash): find the
     * longest run of leading words that is an artist we've carried, and
     * split there. One query for the whole batch.
     */
    protected function splitNoDashLines($business_id, $items)
    {
        $cands = [];
        foreach ($items as $it) {
            if ($it['title'] !== '') continue;
            $words = preg_split('/\s+/u', mb_strtolower($it['artist']));
            for ($k = 1; $k < count($words); $k++) {
                $p = implode(' ', array_slice($words, 0, $k));
                $cands[$p] = true;
                $cands['the ' . $p] = true;
            }
        }
        if (!$cands) return $items;
        $known = array_flip(\DB::table('products')->where('business_id', $business_id)
            ->whereRaw('LOWER(TRIM(artist)) IN (' . implode(',', array_fill(0, count($cands), '?')) . ')', array_keys($cands))
            ->selectRaw('DISTINCT LOWER(TRIM(artist)) as a')->pluck('a')->all());
        foreach ($items as $i => $it) {
            if ($it['title'] !== '') continue;
            $words = preg_split('/\s+/u', $it['artist']);
            for ($k = count($words) - 1; $k >= 1; $k--) {
                $p = mb_strtolower(implode(' ', array_slice($words, 0, $k)));
                if (isset($known[$p]) || isset($known['the ' . $p])) {
                    $items[$i]['artist'] = implode(' ', array_slice($words, 0, $k));
                    $items[$i]['title'] = implode(' ', array_slice($words, $k));
                    break;
                }
            }
        }
        return $items;
    }

    /**
     * Sales (ERP + pre-ERP sheets), on-hand stock and listing counts for the
     * items' artists, as [soldBy, haveBy, listedBy], keyed by normalized artist.
     * With a picked music category only that format counts.
     */
    protected function gather($business_id, $items, $storeOf, $cat)
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
            if ($cat) $pq->where('p.category_id', $cat->id);
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
                $formatRe = $cat ? $this->legacyFormatPattern($cat->name) : null;
                $legacy = $lq->get(['tsl.legacy_artist as artist', 'tsl.legacy_title as title', 'tsl.legacy_format as format', 't.location_id',
                        't.transaction_date as sold_at', 'tsl.unit_price_inc_tax as price',
                        \DB::raw('tsl.quantity - COALESCE(tsl.quantity_returned, 0) as qty')])
                    ->filter(function ($l) use ($wanted, $formatRe) {
                        if ($formatRe && !preg_match($formatRe, (string) $l->format)) return false;
                        return isset($wanted[$this->norm($l->artist)]);
                    });
            }
        }

        [$sales, $stock] = $this->salesAndStock($business_id, $products->keys()->all());

        // ---- Fold everything into per-artist buckets of {title key, store, qty, price, date}
        $soldBy = [];
        $haveBy = [];
        $listedBy = [];
        foreach ($products as $p) {
            $k = $this->norm($p->artist);
            $listedBy[$k] = ($listedBy[$k] ?? 0) + 1;
        }
        foreach ($sales as $s) {
            $p = $products[$s->product_id];
            if ((float) $s->qty <= 0) continue;
            $soldBy[$this->norm($p->artist)][] = ['t' => $this->normTitle($this->stripArtistPrefix($p->name, $p->artist)),
                'store' => $storeOf[(int) $s->location_id] ?? 'other', 'qty' => (float) $s->qty,
                'price' => (float) $s->price, 'at' => (string) $s->sold_at];
        }
        foreach ($legacy as $l) {
            if ((float) $l->qty <= 0) continue;
            $k = $this->norm($l->artist);
            $listedBy[$k] = ($listedBy[$k] ?? 0) + 1;
            $soldBy[$k][] = ['t' => $this->normTitle((string) $l->title),
                'store' => $storeOf[(int) $l->location_id] ?? 'other', 'qty' => (float) $l->qty,
                'price' => (float) $l->price, 'at' => (string) $l->sold_at];
        }
        foreach ($stock as $v) {
            $p = $products[$v->product_id];
            $haveBy[$this->norm($p->artist)][] = ['t' => $this->normTitle($this->stripArtistPrefix($p->name, $p->artist)),
                'store' => $storeOf[(int) $v->location_id] ?? 'other', 'qty' => (float) $v->qty_available];
        }

        return [$soldBy, $haveBy, $listedBy];
    }

    /** Old sheets wrote formats as free text ("Vinyl 33", "CD", "Cassette"). */
    protected function legacyFormatPattern($categoryName)
    {
        $n = mb_strtolower($categoryName);
        if (strpos($n, 'vinyl') !== false) return '/vinyl|\blp\b|33|45|7"/i';
        if (strpos($n, '45') !== false) return '/45|7"/i';
        if (strpos($n, 'cassette') !== false) return '/cass|tape/i';
        if (preg_match('/\bcds?\b/', $n)) return '/\bcd/i';
        return '/^$/'; // no old-sheet sales for anything else
    }

    /** [verdict: store|discogs|never_sold|never_had|unknown, where, why] */
    protected function musicVerdict($it, $r, $recentFrom)
    {
        if ($it['akey'] === '') return ['unknown', '', 'No artist on this line'];
        $titleSold = array_sum($r['title_sold']);
        $artistSold = array_sum($r['artist_sold']);
        $have = array_sum($r['artist_have']);
        $overstocked = $have > max(1, $r['artist_sold_12m']) * self::OVERSTOCK_RATIO;
        $sellThrough = $artistSold > 0 ? $artistSold / ($artistSold + $have) : 0;

        $note = '';
        if ($titleSold > 0) {
            if ((string) $r['title_last_at'] >= $recentFrom) {
                $why = 'Sold this title ' . $this->n($titleSold) . 'x';
                if ($overstocked) $why .= ' (but we have ' . $this->n($have) . ' of the artist)';
                return ['store', $this->bestStore($r['title_sold']), $why];
            }
            $note = 'Title last sold ' . $this->year($r['title_last_at']) . '. ';
        }
        if ($artistSold > 0) {
            if ($r['artist_sold_recent'] <= 0) {
                return ['discogs', 'Discogs', $note . 'Artist last sold ' . $this->year($r['artist_last_at']) . ', nothing since'];
            }
            if ($overstocked) {
                return ['discogs', 'Discogs', $note . 'Artist sells (' . $this->n($r['artist_sold_12m']) . ' in 12 mo) but we already have ' . $this->n($have)];
            }
            if ($sellThrough < self::MIN_SELL_THROUGH) {
                return ['discogs', 'Discogs', $note . 'Only ' . round($sellThrough * 100) . '% of this artist we stocked has sold (' . $this->n($artistSold) . ' sold, ' . $this->n($have) . ' left)'];
            }
            return ['store', $this->bestStore($r['artist_sold']), $note . 'Sold this artist ' . $this->n($artistSold) . 'x' . ($titleSold > 0 ? '' : ', not this title')];
        }
        // No sales = no call either way; the page shows the Discogs price next to it.
        if ($r['artist_listed'] > 0) {
            return ['never_sold', 'Never sold', 'Had this artist (' . $r['artist_listed'] . ' listing' . ($r['artist_listed'] > 1 ? 's' : '') . '), never sold one'
                . ($have > 0 ? ', ' . $this->n($have) . ' on hand' : '')];
        }
        return ['never_had', 'Never had for sale', 'We have never carried this artist'];
    }

    // ===================================================================
    // Everything else: name-based, within the picked category
    // ===================================================================

    protected function checkItems($business_id, $raws, $storeOf, $cat)
    {
        $items = [];
        foreach ($raws as $raw) {
            [$a, $t] = $this->splitLine($raw);
            $items[] = ['line' => $raw, 'artist' => $t !== '' ? $a : '', 'title' => $t !== '' ? $t : $a, 'words' => $this->itemWords($raw)];
        }

        // Categories outside music are small (thousands), so load the category once and match in PHP.
        $products = \DB::table('products')->where('business_id', $business_id)->where('category_id', $cat->id)
            ->get(['id', 'artist', 'name'])
            ->map(function ($p) { $p->words = array_flip($this->itemWords($p->artist . ' ' . $p->name)); return $p; });

        $matchIds = [];
        $allIds = [];
        foreach ($items as $i => $it) {
            $matchIds[$i] = [];
            if (!$it['words']) continue;
            foreach ($products as $p) {
                $all = true;
                foreach ($it['words'] as $w) { if (!isset($p->words[$w])) { $all = false; break; } }
                if ($all) { $matchIds[$i][] = $p->id; $allIds[$p->id] = true; }
            }
        }
        [$sales, $stock] = $this->salesAndStock($business_id, array_keys($allIds));
        $salesBy = $sales->groupBy('product_id');
        $stockBy = $stock->groupBy('product_id');

        $yearAgo = now()->subYear()->toDateTimeString();
        $recentFrom = now()->subYears(self::RECENT_YEARS)->toDateTimeString();
        $rows = [];
        foreach ($items as $i => $it) {
            $r = $this->blankRow($it['line'], $it['artist'], $it['title']);
            $r['title_listed'] = count($matchIds[$i]);
            $sold12m = 0;
            $soldRecent = 0;
            foreach ($matchIds[$i] as $pid) {
                foreach ($salesBy[$pid] ?? [] as $s) {
                    if ((float) $s->qty <= 0) continue;
                    $store = $storeOf[(int) $s->location_id] ?? 'other';
                    $r['title_sold'][$store] += (float) $s->qty;
                    if ($s->sold_at >= $yearAgo) $sold12m += (float) $s->qty;
                    if ($s->sold_at >= $recentFrom) $soldRecent += (float) $s->qty;
                    if ($s->sold_at > (string) $r['title_last_at']) { $r['title_last_at'] = $s->sold_at; $r['title_last_price'] = round((float) $s->price, 2); }
                }
                foreach ($stockBy[$pid] ?? [] as $v) $r['title_have'] += (float) $v->qty_available;
            }
            $r['title_sold_12m'] = $sold12m;
            $sold = array_sum($r['title_sold']);
            if (!$it['words']) {
                $v = ['unknown', '', 'Nothing to match on this line'];
            } elseif ($sold > 0 && $soldRecent > 0 && $r['title_have'] <= max(1, $sold12m) * self::OVERSTOCK_RATIO) {
                $v = ['store', $this->bestStore($r['title_sold']), 'Sold ' . $this->n($sold) . 'x'];
            } elseif ($sold > 0 && $soldRecent > 0) {
                $v = ['ebay', 'eBay', 'Sells (' . $this->n($sold12m) . ' in 12 mo) but we already have ' . $this->n($r['title_have'])];
            } elseif ($sold > 0) {
                $v = ['ebay', 'eBay', 'Last sold ' . $this->year($r['title_last_at']) . ', nothing since'];
            } elseif ($r['title_listed'] > 0) {
                $v = ['never_sold', 'Never sold', 'Had it (' . $r['title_listed'] . ' listing' . ($r['title_listed'] > 1 ? 's' : '') . '), never sold one'
                    . ($r['title_have'] > 0 ? ', ' . $this->n($r['title_have']) . ' on hand' : '')];
            } else {
                $v = ['never_had', 'Never had for sale', 'We have never carried this in ' . $cat->name];
            }
            [$r['verdict'], $r['where'], $r['why']] = $v;
            $rows[] = $this->finishRow($r);
        }
        return $rows;
    }

    /** Distinct significant words of an item name ("VHS - Pulp Fiction (Sealed)" → pulp, fiction). */
    protected function itemWords($s)
    {
        $words = array_filter(explode(' ', $this->norm($s)), function ($w) {
            return $w !== '' && !in_array($w, self::ITEM_NOISE, true) && (mb_strlen($w) >= 2 || ctype_digit($w));
        });
        return array_values(array_unique($words));
    }

    // ===================================================================
    // Shared
    // ===================================================================

    /** ERP sales and current stock for these product ids. */
    protected function salesAndStock($business_id, $ids)
    {
        $sales = collect();
        $stock = collect();
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
        return [$sales, $stock];
    }

    protected function blankRow($line, $artist, $title)
    {
        $z = ['pico' => 0, 'hollywood' => 0, 'other' => 0];
        return ['line' => $line, 'artist' => $artist, 'title' => $title,
            'title_sold' => $z, 'title_last_price' => null, 'title_last_at' => null, 'title_have' => 0, 'title_listed' => 0, 'title_sold_12m' => 0,
            'artist_sold' => $z, 'artist_sold_12m' => 0, 'artist_sold_recent' => 0, 'artist_last_at' => null, 'artist_avg_price' => null,
            'artist_have' => $z, 'artist_listed' => 0];
    }

    protected function finishRow($r)
    {
        foreach (['title_last_at', 'artist_last_at'] as $k) { if ($r[$k]) $r[$k] = substr($r[$k], 0, 10); }
        return $r;
    }

    protected function bestStore($counts)
    {
        if ($counts['pico'] == $counts['hollywood']) return 'Pico or Hollywood';
        return $counts['pico'] > $counts['hollywood'] ? 'Pico' : 'Hollywood';
    }

    protected function year($at)
    {
        return substr((string) $at, 0, 4);
    }

    /**
     * What it goes for online, cached per line.
     *  - music: Discogs top release match for artist + title → marketplace
     *    stats (lowest asking, copies for sale) + community want/have.
     *    Discogs allows ~60 calls a minute and this is 2, so the page asks
     *    one line at a time.
     *  - anything else (category_id of a non-music category): eBay active
     *    listings in that category's eBay category → median asking price.
     * 'price' is the number the page compares to the "too cheap" cutoff.
     */
    public function value(Request $request)
    {
        if (!$this->allowed()) { abort(403); }
        $business_id = $request->session()->get('user.business_id');
        [$artist, $title] = [trim((string) $request->input('artist')), trim((string) $request->input('title'))];
        if ($artist === '' && $title === '') return response()->json(['found' => false]);
        [$cat, $music] = $this->pickedCategory($business_id, $request->input('category_id'));
        return $music ? $this->discogsValue($business_id, $artist, $title) : $this->ebayValue($business_id, $artist, $title, $cat);
    }

    protected function discogsValue($business_id, $artist, $title)
    {
        $key = 'sod-value:' . md5($this->norm($artist) . '|' . $this->normTitle($title));
        if ($hit = \Cache::get($key)) {
            if (!array_key_exists('price', $hit)) $hit['price'] = $hit['lowest'] ?? null; // cached before 'price' existed
            return response()->json($hit + ['cached' => true]);
        }

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
            $out = ['found' => false, 'source' => 'discogs'];
            \Cache::put($key, $out, now()->addDays(30));
            return response()->json($out);
        }
        $stats = $discogs->callApi('https://api.discogs.com/marketplace/stats/' . (int) $hit->id . '?curr_abbr=USD&token=' . urlencode($this->discogsToken($business_id)));
        if (!empty($stats['error']) && strpos((string) $stats['message'], '429') !== false) {
            return response()->json(['found' => false, 'rate_limited' => true], 429);
        }
        $st = $stats['data'] ?? null;
        $lowest = isset($st->lowest_price->value) ? round((float) $st->lowest_price->value, 2) : null;
        $out = [
            'found' => true,
            'source' => 'discogs',
            'release_id' => (int) $hit->id,
            'uri' => 'https://www.discogs.com/release/' . (int) $hit->id,
            'lowest' => $lowest,
            'price' => $lowest,
            'for_sale' => isset($st->num_for_sale) ? (int) $st->num_for_sale : null,
            'want' => (int) ($hit->community->want ?? 0),
            'have' => (int) ($hit->community->have ?? 0),
        ];
        \Cache::put($key, $out, now()->addDays(30));
        return response()->json($out);
    }

    protected function ebayValue($business_id, $artist, $title, $cat)
    {
        $q = trim($artist . ' ' . $title);
        $key = 'sod-ebay:' . ($cat->id ?? 0) . ':' . md5($this->norm($q));
        if ($hit = \Cache::get($key)) return response()->json($hit + ['cached' => true]);
        $ebay = new \App\Services\EbayService($business_id);
        if (!$ebay->isConfigured()) return response()->json(['found' => false, 'error' => 'eBay not connected']);
        $ebayCat = trim(explode(',', (string) ($cat->ebay_category_ids ?? ''))[0]);
        try {
            $items = $ebay->getProductPrice($q, null, $ebayCat !== '' ? $ebayCat : null);
        } catch (\Throwable $e) {
            return response()->json(['found' => false, 'error' => 'eBay: ' . substr($e->getMessage(), 0, 80)]);
        }
        $prices = array_values(array_filter(array_map(function ($i) { return (float) $i['value']; }, $items)));
        sort($prices);
        $n = count($prices);
        $out = ['found' => $n > 0, 'source' => 'ebay', 'uri' => 'https://www.ebay.com/sch/i.html?_nkw=' . urlencode($q)];
        if ($n) {
            $median = $n % 2 ? $prices[intdiv($n, 2)] : ($prices[$n / 2 - 1] + $prices[$n / 2]) / 2;
            $out += ['median' => round($median, 2), 'lowest' => round($prices[0], 2), 'price' => round($median, 2), 'for_sale' => $n];
        }
        \Cache::put($key, $out, now()->addDays(7));
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
