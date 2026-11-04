<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * "Store or Online?" (was "Store or Discogs?"; Sarah 2026-11-01: searching the products page one
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
    const MIN_DISCOGS_VALUE = 6;
    /** Never-had title + artist selling at least this many in 12 mo (~1 every 4 months) → try it in store. */
    const NEW_TITLE_ARTIST_12M = 3;
    /** Lines whose artist has less history than this (copies sold + on hand) also get tried as Title - Artist. */
    const SWAP_CHECK_BELOW = 10;
    /** Category names that are music formats (artist-based matching, Discogs). */
    // Discogs VG+ price suggestion is capped at this many times the lowest copy for sale.
    const DISCOGS_CAP_X_LOWEST = 3;
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

        // ---- Store name → pico / hollywood / other (everything else, named for the page's third column)
        $storeOf = [];
        $otherNames = [];
        foreach (\DB::table('business_locations')->where('business_id', $business_id)->pluck('name', 'id') as $id => $name) {
            $n = mb_strtolower((string) $name);
            $storeOf[(int) $id] = strpos($n, 'hollywood') !== false ? 'hollywood' : (strpos($n, 'pico') !== false ? 'pico' : 'other');
            if ($storeOf[(int) $id] === 'other') $otherNames[] = trim((string) $name);
        }

        $rows = $music
            ? $this->checkMusic($business_id, $raws, $storeOf, $cat)
            : $this->checkItems($business_id, $raws, $storeOf, $cat);
        return response()->json(['rows' => $rows, 'mode' => $music ? 'music' : 'item', 'other_store' => implode(' / ', $otherNames)]);
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

        [$soldBy, $haveBy, $listedBy, $hadAt, $idsBy] = $this->gather($business_id, $items, $storeOf, $cat);

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
            [$soldBy2, $haveBy2, $listedBy2, $hadAt2, $idsBy2] = $this->gather($business_id, array_values($flipped), $storeOf, $cat);
            foreach ($flipped as $i => $f) {
                $was = $activity($soldBy, $haveBy, $items[$i]['akey']);
                $now = $activity($soldBy2, $haveBy2, $f['akey']);
                if ($now < 3 || $now < $was * 3) continue;
                $items[$i] = $f + ['swapped' => true];
                if (isset($soldBy2[$f['akey']])) $soldBy[$f['akey']] = $soldBy2[$f['akey']];
                if (isset($haveBy2[$f['akey']])) $haveBy[$f['akey']] = $haveBy2[$f['akey']];
                if (isset($listedBy2[$f['akey']])) $listedBy[$f['akey']] = $listedBy2[$f['akey']];
                if (isset($hadAt2[$f['akey']])) $hadAt[$f['akey']] = $hadAt2[$f['akey']];
                if (isset($idsBy2[$f['akey']])) $idsBy[$f['akey']] = $idsBy2[$f['akey']];
            }
        }

        $yearAgo = now()->subYear()->toDateTimeString();
        $recentFrom = now()->subYears(self::RECENT_YEARS)->toDateTimeString();
        $rows = [];
        foreach ($items as $it) {
            $r = $this->blankRow($it['line'], $it['artist'], $it['title']);
            $r['artist_listed'] = (int) ($listedBy[$it['akey']] ?? 0);
            $r['had_at'] = $hadAt[$it['akey']] ?? [];
            $priceSum = 0;
            $soldPrices = [];
            $shelfPrices = [];
            foreach ($soldBy[$it['akey']] ?? [] as $s) {
                $r['artist_sold'][$s['store']] += $s['qty'];
                $this->addFmt($r['artist_fmt'], $s['fmt'], $s['qty']);
                if ($s['at'] >= $yearAgo) $r['artist_sold_12m'] += $s['qty'];
                if ($s['at'] >= $recentFrom) $r['artist_sold_recent'] += $s['qty'];
                $priceSum += $s['price'] * $s['qty'];
                if ($s['at'] > (string) $r['artist_last_at']) $r['artist_last_at'] = $s['at'];
                if ($it['tkey'] !== '' && $this->sameTitle($it['tkey'], $s['t'])) {
                    $r['title_sold'][$s['store']] += $s['qty'];
                    $this->addFmt($r['title_fmt'], $s['fmt'], $s['qty']);
                    if ($s['at'] > (string) $r['title_last_at']) { $r['title_last_at'] = $s['at']; $r['title_last_price'] = round($s['price'], 2); }
                    $soldPrices[] = [$s['price'], $s['at']];
                }
            }
            $artistQty = array_sum($r['artist_sold']);
            if ($artistQty > 0 && $priceSum > 0) $r['artist_avg_price'] = round($priceSum / $artistQty, 2);
            foreach ($haveBy[$it['akey']] ?? [] as $h) {
                $r['artist_have'][$h['store']] += $h['qty'];
                if ($it['tkey'] !== '' && $this->sameTitle($it['tkey'], $h['t'])) {
                    $r['title_have'] += $h['qty'];
                    $this->addFmt($r['title_have_fmt'], $h['fmt'], $h['qty']);
                    if ($h['for_sale']) {
                        $r['for_sale'][$h['store']] += $h['qty'];
                        $shelfPrices[] = $h['price'];
                    }
                }
            }
            $this->setPriceHints($r, $soldPrices, $shelfPrices, $recentFrom);
            [$r['verdict'], $r['where'], $r['why']] = $this->musicVerdict($it, $r, $recentFrom);
            $r['never_had_at'] = $this->neverHadAt($r);
            $r['new_title_artist_sells'] = $it['tkey'] !== '' && array_sum($r['title_sold']) <= 0 && $r['title_have'] <= 0 && array_sum($r['artist_sold']) > 0;
            $r['_ids'] = $idsBy[$it['akey']] ?? [];
            if (!empty($it['swapped'])) {
                $r['swapped'] = true;
                $r['why'] = 'Read as Title - Artist. ' . $r['why'];
            }
            $rows[] = $r;
        }
        return array_map([$this, 'finishRow'], $this->addShelfDays($rows, $storeOf));
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
            $pq = \DB::table('products as p')->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->where('p.business_id', $business_id)->where('p.sku', '!=', 'NIV-LEGACY-HIST');
            if ($cat) $pq->where('p.category_id', $cat->id);
            $likeAny($pq, 'p.artist');
            $products = $pq->get(['p.id', 'p.artist', 'p.name', 'c.name as cat', 'p.not_for_selling', 'p.is_inactive'])->filter(function ($p) use ($wanted) {
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

        [$sales, $stock, $everAt] = $this->salesAndStock($business_id, $products->keys()->all());

        // ---- Fold everything into per-artist buckets of {title key, store, qty, price, date}
        $soldBy = [];
        $haveBy = [];
        $listedBy = [];
        $hadAt = [];
        $idsBy = [];
        foreach ($products as $p) {
            $k = $this->norm($p->artist);
            $listedBy[$k] = ($listedBy[$k] ?? 0) + 1;
            $idsBy[$k][] = $p->id;
        }
        foreach ($everAt as $v) {
            $hadAt[$this->norm($products[$v->product_id]->artist)][$storeOf[(int) $v->location_id] ?? 'other'] = true;
        }
        foreach ($sales as $s) {
            $p = $products[$s->product_id];
            if ((float) $s->qty <= 0) continue;
            $soldBy[$this->norm($p->artist)][] = ['t' => $this->normTitle($this->stripArtistPrefix($p->name, $p->artist)),
                'fmt' => $this->formatOf($p->cat),
                'store' => $storeOf[(int) $s->location_id] ?? 'other', 'qty' => (float) $s->qty,
                'price' => (float) $s->price, 'at' => (string) $s->sold_at];
        }
        foreach ($legacy as $l) {
            if ((float) $l->qty <= 0) continue;
            $k = $this->norm($l->artist);
            $listedBy[$k] = ($listedBy[$k] ?? 0) + 1;
            $soldBy[$k][] = ['t' => $this->normTitle((string) $l->title),
                'fmt' => $this->formatOf($l->format),
                'store' => $storeOf[(int) $l->location_id] ?? 'other', 'qty' => (float) $l->qty,
                'price' => (float) $l->price, 'at' => (string) $l->sold_at];
        }
        foreach ($stock as $v) {
            $p = $products[$v->product_id];
            $haveBy[$this->norm($p->artist)][] = ['t' => $this->normTitle($this->stripArtistPrefix($p->name, $p->artist)),
                'fmt' => $this->formatOf($p->cat), 'for_sale' => $this->forSale($p), 'price' => (float) $v->price,
                'store' => $storeOf[(int) $v->location_id] ?? 'other', 'qty' => (float) $v->qty_available];
        }

        return [$soldBy, $haveBy, $listedBy, $hadAt, $idsBy];
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
        // Never carried this title, but the artist sells: say so (Sarah 2026-11-02).
        $titleNeverHad = $it['tkey'] !== '' && $titleSold <= 0 && $r['title_have'] <= 0;
        if ($titleNeverHad && $artistSold > 0) {
            $note = 'Never had this title, but sold this artist ' . $this->n($artistSold) . 'x'
                . ($r['artist_sold_12m'] > 0 ? ' (' . $this->n($r['artist_sold_12m']) . ' in 12 mo)' : '') . '. ';
        }
        if ($titleSold > 0) {
            if ((string) $r['title_last_at'] >= $recentFrom) {
                $why = 'Sold this title ' . $this->n($titleSold) . 'x';
                if ($overstocked) $why .= ' (but we have ' . $this->n($have) . ' of the artist)';
                return ['store', $this->bestStore($r['title_sold']), $why];
            }
            $note = 'Title last sold ' . $this->year($r['title_last_at']) . '. ';
        }
        if ($artistSold > 0) {
            // Never had this title and the artist moves every ~4 months or faster: try it (Sarah 2026-11-04).
            if ($titleNeverHad && $r['artist_sold_12m'] >= self::NEW_TITLE_ARTIST_12M) {
                $days = (int) round(365 / $r['artist_sold_12m']);
                return ['store', $this->bestStore($r['artist_sold']), 'Never had this title; artist sells about 1 every ' . $days . ' days ('
                    . $this->n($r['artist_sold_12m']) . ' in 12 mo), worth trying' . ($overstocked ? ' (we have ' . $this->n($have) . ' of the artist)' : '')];
            }
            if ($r['artist_sold_recent'] <= 0) {
                return ['discogs', 'Discogs', $note . 'Artist last sold ' . $this->year($r['artist_last_at']) . ', nothing since'];
            }
            if ($overstocked) {
                return ['discogs', 'Discogs', $note . 'Artist sells (' . $this->n($r['artist_sold_12m']) . ' in 12 mo) but we already have ' . $this->n($have)];
            }
            if ($sellThrough < self::MIN_SELL_THROUGH) {
                return ['discogs', 'Discogs', $note . 'Only ' . round($sellThrough * 100) . '% of this artist we stocked has sold (' . $this->n($artistSold) . ' sold, ' . $this->n($have) . ' left)'];
            }
            if ($titleNeverHad) return ['store', $this->bestStore($r['artist_sold']), rtrim($note, '. ')];
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
            ->get(['id', 'artist', 'name', 'not_for_selling', 'is_inactive'])
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
        [$sales, $stock, $everAt] = $this->salesAndStock($business_id, array_keys($allIds));
        $everAtBy = $everAt->groupBy('product_id');
        $salesBy = $sales->groupBy('product_id');
        $stockBy = $stock->groupBy('product_id');
        $byId = $products->keyBy('id');

        $yearAgo = now()->subYear()->toDateTimeString();
        $recentFrom = now()->subYears(self::RECENT_YEARS)->toDateTimeString();
        $rows = [];
        foreach ($items as $i => $it) {
            $r = $this->blankRow($it['line'], $it['artist'], $it['title']);
            $r['title_listed'] = count($matchIds[$i]);
            $sold12m = 0;
            $soldRecent = 0;
            $soldPrices = [];
            $shelfPrices = [];
            foreach ($matchIds[$i] as $pid) {
                foreach ($salesBy[$pid] ?? [] as $s) {
                    if ((float) $s->qty <= 0) continue;
                    $store = $storeOf[(int) $s->location_id] ?? 'other';
                    $r['title_sold'][$store] += (float) $s->qty;
                    if ($s->sold_at >= $yearAgo) $sold12m += (float) $s->qty;
                    if ($s->sold_at >= $recentFrom) $soldRecent += (float) $s->qty;
                    if ($s->sold_at > (string) $r['title_last_at']) { $r['title_last_at'] = $s->sold_at; $r['title_last_price'] = round((float) $s->price, 2); }
                    $soldPrices[] = [(float) $s->price, (string) $s->sold_at];
                }
                foreach ($everAtBy[$pid] ?? [] as $v) $r['had_at'][$storeOf[(int) $v->location_id] ?? 'other'] = true;
                foreach ($stockBy[$pid] ?? [] as $v) {
                    $r['title_have'] += (float) $v->qty_available;
                    if ($this->forSale($byId[$pid])) {
                        $r['for_sale'][$storeOf[(int) $v->location_id] ?? 'other'] += (float) $v->qty_available;
                        $shelfPrices[] = (float) $v->price;
                    }
                }
            }
            $r['title_sold_12m'] = $sold12m;
            $this->setPriceHints($r, $soldPrices, $shelfPrices, $recentFrom);
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
            $r['never_had_at'] = $this->neverHadAt($r);
            $r['_ids'] = $matchIds[$i];
            $rows[] = $r;
        }
        return array_map([$this, 'finishRow'], $this->addShelfDays($rows, $storeOf));
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
        $everAt = collect();
        foreach (array_chunk($ids, 2000) as $chunk) {
            $sales = $sales->merge(\DB::table('transaction_sell_lines as tsl')
                ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
                ->whereIn('tsl.product_id', $chunk)
                ->where('t.business_id', $business_id)->where('t.type', 'sell')->where('t.status', 'final')
                ->get(['tsl.product_id', 't.location_id', 't.transaction_date as sold_at', 'tsl.unit_price_inc_tax as price',
                    \DB::raw('tsl.quantity - COALESCE(tsl.quantity_returned, 0) as qty')]));
            // Every stock row, sold-out ones too: a row at a location = it was stocked there at some point.
            $rows = \DB::table('variation_location_details as vld')
                ->leftJoin('variations as v', 'v.id', '=', 'vld.variation_id')
                ->whereIn('vld.product_id', $chunk)
                ->get(['vld.product_id', 'vld.location_id', 'vld.qty_available', 'v.sell_price_inc_tax as price']);
            $stock = $stock->merge($rows->filter(function ($v) { return (float) $v->qty_available > 0; }));
            $everAt = $everAt->merge($rows);
        }
        return [$sales, $stock, $everAt];
    }

    protected function blankRow($line, $artist, $title)
    {
        $z = ['pico' => 0, 'hollywood' => 0, 'other' => 0];
        return ['line' => $line, 'artist' => $artist, 'title' => $title,
            'title_sold' => $z, 'title_last_price' => null, 'title_last_at' => null, 'title_have' => 0, 'title_listed' => 0, 'title_sold_12m' => 0,
            'artist_sold' => $z, 'artist_sold_12m' => 0, 'artist_sold_recent' => 0, 'artist_last_at' => null, 'artist_avg_price' => null,
            'artist_have' => $z, 'artist_listed' => 0,
            // This exact title/item in stock and actually for sale (priced, active), per store.
            'for_sale' => $z,
            // Price hints for the page's rough estimate: median this title sold for
            // (recent sales if any) and median shelf price of copies for sale now.
            'sold_median' => null, 'sold_median_n' => 0, 'shelf_price' => null,
            // Stores that ever stocked it (any stock row, sold out or not); Never sold rows
            // list the stores that never did.
            'had_at' => [], 'never_had_at' => [],
            // Never sold rows: per store, days the oldest copy still there has sat
            // (shelf_days), else when it was first stocked there (first_in).
            'shelf' => [],
            // Music only: copies by format (Vinyl / CD / Cassette / Other), most first.
            'title_fmt' => [], 'artist_fmt' => [], 'title_have_fmt' => []];
    }

    protected function finishRow($r)
    {
        unset($r['_ids']);
        foreach (['title_fmt', 'artist_fmt', 'title_have_fmt'] as $k) arsort($r[$k]);
        foreach (['title_last_at', 'artist_last_at'] as $k) { if ($r[$k]) $r[$k] = substr($r[$k], 0, 10); }
        return $r;
    }

    /** Vinyl / CD / Cassette / Other from a category name or an old-sheet format ("Vinyl 33", "CD"); null if unknown. */
    protected function formatOf($s)
    {
        $s = mb_strtolower(trim((string) $s));
        if ($s === '') return null;
        if (preg_match('/vinyl|\blp\b|\b33\b|45|7"|12"|record/', $s)) return 'Vinyl';
        if (preg_match('/\bcds?\b|compact disc/', $s)) return 'CD';
        if (preg_match('/cass|tape|8 track/', $s)) return 'Cassette';
        return 'Other';
    }

    /**
     * For Never sold rows: per store, from purchase / opening stock / transfer-in
     * lots, how long the oldest copy still on that shelf has sat, or (none left
     * there) when it first came in. One query for the batch.
     */
    protected function addShelfDays($rows, $storeOf)
    {
        $ids = [];
        foreach ($rows as $r) { if ($r['verdict'] === 'never_sold') foreach ($r['_ids'] as $id) $ids[$id] = true; }
        if (!$ids) return $rows;
        $lots = collect();
        foreach (array_chunk(array_keys($ids), 2000) as $chunk) {
            $lots = $lots->merge(\DB::table('purchase_lines as pl')
                ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
                ->whereIn('pl.product_id', $chunk)
                ->whereIn('t.type', ['purchase', 'opening_stock', 'purchase_transfer'])
                ->groupBy('pl.product_id', 't.location_id')
                ->selectRaw('pl.product_id, t.location_id, MIN(t.transaction_date) as first_in,
                    MIN(CASE WHEN (pl.quantity - pl.quantity_sold - pl.quantity_adjusted - pl.quantity_returned) > 0 THEN t.transaction_date END) as on_shelf_since')
                ->get());
        }
        $byProduct = $lots->groupBy('product_id');
        $now = now();
        foreach ($rows as &$r) {
            if ($r['verdict'] !== 'never_sold') continue;
            $per = [];
            foreach ($r['_ids'] as $id) {
                foreach ($byProduct[$id] ?? [] as $l) {
                    $st = $storeOf[(int) $l->location_id] ?? 'other';
                    $cur = $per[$st] ?? ['since' => null, 'first' => null];
                    if ($l->on_shelf_since && (!$cur['since'] || $l->on_shelf_since < $cur['since'])) $cur['since'] = $l->on_shelf_since;
                    if ($l->first_in && (!$cur['first'] || $l->first_in < $cur['first'])) $cur['first'] = $l->first_in;
                    $per[$st] = $cur;
                }
            }
            foreach ($per as $st => $x) {
                $r['shelf'][$st] = [
                    'shelf_days' => $x['since'] ? \Carbon::parse($x['since'])->diffInDays($now) : null,
                    'first_in' => $x['first'] ? substr($x['first'], 0, 10) : null,
                ];
            }
        }
        unset($r);
        return $rows;
    }

    protected function setPriceHints(&$r, $soldPrices, $shelfPrices, $recentFrom)
    {
        $recent = array_filter($soldPrices, function ($x) use ($recentFrom) { return $x[1] >= $recentFrom && $x[0] > 0; });
        $use = $recent ?: array_filter($soldPrices, function ($x) { return $x[0] > 0; });
        $vals = array_map(function ($x) { return $x[0]; }, $use);
        $r['sold_median'] = $this->median($vals);
        $r['sold_median_n'] = count($vals);
        $r['shelf_price'] = $this->median(array_filter($shelfPrices, function ($p) { return $p > 0; }));
    }

    protected function median($vals)
    {
        $vals = array_values($vals);
        if (!$vals) return null;
        sort($vals);
        $n = count($vals);
        return round($n % 2 ? $vals[intdiv($n, 2)] : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2, 2);
    }

    /** For a Never sold row: the stores (Pico / Hollywood) that never even had it for sale. */
    protected function neverHadAt($r)
    {
        if ($r['verdict'] !== 'never_sold') return [];
        $out = [];
        foreach (['pico' => 'Pico', 'hollywood' => 'Hollywood'] as $k => $name) {
            if (empty($r['had_at'][$k])) $out[] = $name;
        }
        return $out;
    }

    /** Active and priced: not an unpriced Buy-from-Customer copy or a retired listing. */
    protected function forSale($p)
    {
        return empty($p->not_for_selling) && empty($p->is_inactive);
    }

    protected function addFmt(&$bucket, $fmt, $qty)
    {
        if ($fmt === null) return;
        $bucket[$fmt] = ($bucket[$fmt] ?? 0) + $qty;
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
     *    stats (lowest asking, copies for sale), sales-based VG+ price
     *    (price_suggestions) + community want/have. 'price' is the VG+ price
     *    (Sarah 2026-11-02: the lowest asking is a junk copy, use a typical
     *    sale), falling back to lowest. Discogs allows ~60 calls a minute and
     *    this is 3, so the page asks one line at a time.
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
        return $music ? $this->discogsValue($business_id, $artist, $title, $this->discogsFormat($cat)) : $this->ebayValue($business_id, $artist, $title, $cat);
    }

    /** Discogs search format for the picked category (blank = any). */
    protected function discogsFormat($cat)
    {
        $n = strtolower((string) ($cat->name ?? ''));
        if (preg_match('/vinyl|45 rpm|\brecord/', $n)) return 'Vinyl';
        if (preg_match('/\bcds?\b/', $n)) return 'CD';
        if (strpos($n, 'cassette') !== false) return 'Cassette';
        if (strpos($n, '8 track') !== false) return '8-Track Cartridge';
        return '';
    }

    /**
     * Search Discogs and pick the most-owned pressing (highest community
     * "have") in the picked format, not the first hit: the first hit is often
     * a rare pressing with one copy for sale (Rumours matched a $48 one-off).
     * Retries without the format, then as a plain query, if nothing matches.
     */
    protected function discogsSearch($discogs, $artist, $title, $format)
    {
        $tries = [];
        if ($format !== '') $tries[] = [['artist' => $artist, 'title' => $title], $format];
        $tries[] = [['artist' => $artist, 'title' => $title], ''];
        // "Beatles, The" style names miss the artist field; a plain query is looser.
        $tries[] = [['query' => trim($artist . ' ' . $title)], $format];
        foreach ($tries as [$f, $fmt]) {
            $url = $discogs->getReleaseApiUrl($f) . '&per_page=50' . ($fmt !== '' ? '&format=' . urlencode($fmt) : '');
            $res = $discogs->callApi($url);
            if (!empty($res['error'])) return $res;
            $results = $res['data']->results ?? [];
            if (!$results) continue;
            usort($results, function ($a, $b) {
                return ($b->community->have ?? 0) <=> ($a->community->have ?? 0);
            });
            return ['error' => false, 'hit' => $results[0]];
        }
        return ['error' => false, 'hit' => null];
    }

    protected function discogsValue($business_id, $artist, $title, $format = '')
    {
        $key = 'sod-value5:' . md5($this->norm($artist) . '|' . $this->normTitle($title) . '|' . $format);
        if ($hit = \Cache::get($key)) {
            if (!array_key_exists('price', $hit)) $hit['price'] = $hit['lowest'] ?? null; // cached before 'price' existed
            return response()->json($hit + ['cached' => true]);
        }

        $discogs = new \App\Services\DiscogsService($business_id);
        if (!$discogs->isConfigured()) return response()->json(['found' => false, 'error' => 'Discogs not connected']);
        $search = $this->discogsSearch($discogs, $artist, $title, $format);
        if (!empty($search['error'])) {
            $limited = strpos((string) $search['message'], '429') !== false;
            return response()->json(['found' => false, 'error' => $search['message'], 'rate_limited' => $limited], $limited ? 429 : 200);
        }
        $hit = $search['hit'];
        if (!$hit) {
            $out = ['found' => false, 'source' => 'discogs'];
            \Cache::put($key, $out, now()->addDays(30));
            return response()->json($out);
        }
        $out = $this->releasePrices($business_id, $discogs, (int) $hit->id, (int) ($hit->community->have ?? 0), (int) ($hit->community->want ?? 0));
        if ($out === null) return response()->json(['found' => false, 'rate_limited' => true], 429);
        \Cache::put($key, $out, now()->addDays(30));
        return response()->json($out);
    }

    /**
     * Marketplace stats + sales-based per-grade price for one release, in the
     * shape the page expects. null = Discogs rate limit.
     */
    protected function releasePrices($business_id, $discogs, $id, $have, $want)
    {
        $token = urlencode($this->discogsToken($business_id));
        $stats = $discogs->callApi('https://api.discogs.com/marketplace/stats/' . $id . '?curr_abbr=USD&token=' . $token);
        if (!empty($stats['error']) && strpos((string) $stats['message'], '429') !== false) return null;
        $st = $stats['data'] ?? null;
        $lowest = isset($st->lowest_price->value) ? round((float) $st->lowest_price->value, 2) : null;
        // Sales-based suggestion per condition; needs the seller token. VG+ is
        // the usual used grade. Missing/failed = fall back to lowest asking.
        $sugg = $discogs->callApi('https://api.discogs.com/marketplace/price_suggestions/' . $id . '?token=' . $token);
        if (!empty($sugg['error']) && strpos((string) $sugg['message'], '429') !== false) return null;
        $vgp = $sugg['data']->{'Very Good Plus (VG+)'}->value ?? null;
        $median = $vgp ? round((float) $vgp, 2) : null;
        // The suggestion runs high on common titles (Rumours VG+ $97.50 with
        // copies from $9), so cap it at 3x the cheapest copy for sale.
        if ($median !== null && $lowest) $median = min($median, round($lowest * self::DISCOGS_CAP_X_LOWEST, 2));
        // This release's price per grade relative to VG+, so the page can scale
        // its suggested price when staff pick a condition.
        $condRatio = [];
        if ($vgp > 0) {
            $grades = ['M' => 'Mint (M)', 'NM' => 'Near Mint (NM or M-)', 'VG+' => 'Very Good Plus (VG+)', 'VG' => 'Very Good (VG)', 'G+' => 'Good Plus (G+)', 'G' => 'Good (G)', 'F/P' => 'Fair (F)'];
            foreach ($grades as $k => $label) {
                $v = $sugg['data']->{$label}->value ?? null;
                if ($v > 0) $condRatio[$k] = round((float) $v / (float) $vgp, 3);
            }
        }
        return [
            'cond_ratio' => $condRatio ?: null,
            'found' => true,
            'source' => 'discogs',
            'release_id' => $id,
            'uri' => 'https://www.discogs.com/release/' . $id,
            'lowest' => $lowest,
            'median' => $median,
            'price' => $median ?? $lowest,
            'for_sale' => isset($st->num_for_sale) ? (int) $st->num_for_sale : null,
            'want' => $want,
            'have' => $have,
        ];
    }

    /**
     * Release picker: Discogs releases for a search (artist + title, or free
     * text), most-owned first, so staff can choose the exact pressing.
     */
    public function releases(Request $request)
    {
        if (!$this->allowed()) { abort(403); }
        $business_id = $request->session()->get('user.business_id');
        $q = trim((string) $request->input('q'));
        if ($q === '') return response()->json(['results' => []]);
        [$cat] = $this->pickedCategory($business_id, $request->input('category_id'));
        $format = $this->discogsFormat($cat);
        $key = 'sod-releases:' . md5(mb_strtolower($q) . '|' . $format);
        if ($hit = \Cache::get($key)) return response()->json($hit);
        $discogs = new \App\Services\DiscogsService($business_id);
        if (!$discogs->isConfigured()) return response()->json(['results' => [], 'error' => 'Discogs not connected']);
        $results = [];
        foreach (array_unique([$format, '']) as $fmt) {
            $res = $discogs->callApi($discogs->getReleaseApiUrl(['query' => $q]) . '&per_page=50' . ($fmt !== '' ? '&format=' . urlencode($fmt) : ''));
            if (!empty($res['error'])) {
                $limited = strpos((string) $res['message'], '429') !== false;
                return response()->json(['results' => [], 'error' => $limited ? 'Discogs says slow down; try again in a minute.' : $res['message']], $limited ? 429 : 200);
            }
            $results = $res['data']->results ?? [];
            if ($results) break;
        }
        usort($results, function ($a, $b) { return ($b->community->have ?? 0) <=> ($a->community->have ?? 0); });
        $out = ['results' => array_map(function ($r) {
            return [
                'id' => (int) $r->id,
                'title' => (string) ($r->title ?? ''),
                'year' => (string) ($r->year ?? ''),
                'country' => (string) ($r->country ?? ''),
                'label' => implode(', ', array_slice(array_unique((array) ($r->label ?? [])), 0, 2)),
                'catno' => (string) ($r->catno ?? ''),
                'format' => implode(', ', array_unique((array) ($r->format ?? []))),
                'thumb' => (string) ($r->thumb ?? ''),
                'have' => (int) ($r->community->have ?? 0),
                'want' => (int) ($r->community->want ?? 0),
            ];
        }, array_slice($results, 0, 25))];
        \Cache::put($key, $out, now()->addDay());
        return response()->json($out);
    }

    /** Prices for a release staff picked (or typed) for a row. */
    public function releaseValue(Request $request)
    {
        if (!$this->allowed()) { abort(403); }
        $business_id = $request->session()->get('user.business_id');
        $id = (int) $request->input('release_id');
        if ($id <= 0) return response()->json(['found' => false]);
        $key = 'sod-release-value:' . $id;
        if ($hit = \Cache::get($key)) return response()->json($hit + ['cached' => true]);
        $discogs = new \App\Services\DiscogsService($business_id);
        if (!$discogs->isConfigured()) return response()->json(['found' => false, 'error' => 'Discogs not connected']);
        $have = (int) $request->input('have');
        $want = (int) $request->input('want');
        if (!$request->has('have')) {
            // Typed ID: look the release up for its have/want counts.
            $rel = $discogs->callApi('https://api.discogs.com/releases/' . $id . '?token=' . urlencode($this->discogsToken($business_id)));
            if (!empty($rel['error'])) {
                if (strpos((string) $rel['message'], '429') !== false) return response()->json(['found' => false, 'rate_limited' => true], 429);
                return response()->json(['found' => false, 'error' => 'No Discogs release ' . $id]);
            }
            $have = (int) ($rel['data']->community->have ?? 0);
            $want = (int) ($rel['data']->community->want ?? 0);
        }
        $out = $this->releasePrices($business_id, $discogs, $id, $have, $want);
        if ($out === null) return response()->json(['found' => false, 'rate_limited' => true], 429);
        \Cache::put($key, $out, now()->addDays(7));
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
            // Some categories' eBay ids are too narrow (or stale); try all of eBay.
            if (!$items && $ebayCat !== '') $items = $ebay->getProductPrice($q);
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
        if (mb_strlen($s) >= 4 && strpos($l . ' ', $s . ' ') === 0) return true;
        // Spelling slips ("rumors" / "rumours", "theese"): 1 letter off per 6, for titles of 5+ letters.
        // Never across different numbers ("vol 1" / "vol 2") or roman numerals ("ii" / "iii").
        $marks = function ($t) { return preg_replace('/[^0-9]/', '', $t) . '|' . implode(' ', preg_grep('/^[ivx]+$/', explode(' ', $t))); };
        if ($marks($a) !== $marks($b)) return false;
        return mb_strlen($s) >= 5 && abs(strlen($a) - strlen($b)) <= 2
            && levenshtein($a, $b) <= max(1, intdiv(strlen($s), 6));
    }
}
