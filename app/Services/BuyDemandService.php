<?php

namespace App\Services;

use App\Http\Controllers\GenreFixController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Beta buy form (Jon 2026-10-23): "if we're low on something and it sells
 * fast, pay a little more." For one buy line this looks at how fast that kind
 * of item sells at the buying store vs how many we have on the shelf there,
 * and returns a pay multiplier (1.0 / 1.1 / 1.2) with a plain-English reason.
 *
 * Two levels:
 *  - Title: an individual vinyl line with a typed title is matched to our own
 *    products by its significant words; sold in last 90 days vs on hand.
 *  - Group: the item type's format + genres (e.g. used vinyl Punk/Metal/Hip Hop)
 *    sold in last 60 days vs on hand, compared with the whole format there.
 * Title wins when it applies. Anything unmapped (gear, apparel, cards...) or
 * any failure returns 1.0, so this can never break or raise a normal buy.
 *
 * Genre pick (10/23): a line can name one genre (canon key, e.g. 'rnb') from
 * genreChoices(). Then only that genre is compared with its format, so R&B
 * isn't averaged in with Jazz. Broad buckets (individual vinyl, "general" /
 * "anything else") only get a bonus once a genre is picked.
 */
class BuyDemandService
{
    // Tuning knobs (Jon). Group = format + genre at the store.
    const GROUP_WINDOW_DAYS = 60;
    // Speed = (sold / on hand) for the genre over the same for its whole format.
    const HOT_MIN_SPEED = 1.5;
    const HOT_MIN_SOLD = 10;
    const WARM_MIN_SPEED = 1.2;
    const WARM_MIN_SOLD = 5;

    // Title = one release matched by name at the store.
    const TITLE_WINDOW_DAYS = 90;
    const TITLE_HOT_MIN_SOLD = 2;
    const TITLE_HOT_MAX_ON_HAND = 1;
    const TITLE_WARM_MIN_SOLD = 1;
    const TITLE_WARM_MAX_ON_HAND = 0;
    const TITLE_MAX_MATCHES = 50;

    // eBay-value lines: a category selling slower than this vs its format (or
    // the whole store) gets the "slow" margin.
    const SLOW_MAX_SPEED = 0.8;
    const EBAY_SLOW_MIN_ON_HAND = 20;
    const EBAY_TYPE = 'ebay_value';

    // Overstock (Jon 10/23): a lot on the shelf that sells slower than its
    // format pays less. Needs a real pile, so small categories never get cut.
    const OVER_MIN_ON_HAND = 200;
    const COOL_MAX_SPEED = 0.8;
    const COLD_MAX_SPEED = 0.5;
    const COOL_MULTIPLIER = 0.90;
    const COLD_MULTIPLIER = 0.80;

    // Cuts only (Jon 2026-10-23): Beta never pays more than the Normal form on
    // bulk. Hot / warm still show on the line as info, but pay the base rate.
    const HOT_MULTIPLIER = 1.0;
    const WARM_MULTIPLIER = 1.0;

    const CACHE_SECONDS = 21600; // 6 hours

    /**
     * item_type => [format, condition, genre canon keys]. Format is one of
     * vinyl / cd / cassette / 45; condition used / sealed / any. Genre keys
     * are GenreFixController::canon() names, so "Hip Hop LPs", "Hip-Hop" and
     * "Rap" all match 'hip hop'. Types not listed get no bonus.
     * The broad "general / anything else" buckets are left out on purpose:
     * a whole format is never "low", so they would only cost queries.
     */
    const TYPE_MAP = [
        'bulk_vinyl_punk_metal_hiphop' => ['vinyl', 'used', ['punk', 'metal', 'nu metal', 'hip hop']],
        'bulk_vinyl_rock_alt_reggae_electronic' => ['vinyl', 'used', ['rock', 'alt', 'reggae', 'electronic', 'new wave']],
        'bulk_vinyl_rb_jazz' => ['vinyl', 'used', ['rnb', 'jazz']],
        'bulk_vinyl_country' => ['vinyl', 'used', ['country', 'folk country']],
        'bulk_vinyl_folk' => ['vinyl', 'used', ['folk', 'folk country']],
        'bulk_vinyl_pop_old' => ['vinyl', 'used', ['pop']],
        'bulk_vinyl_classical' => ['vinyl', 'used', ['classical']],
        'bulk_vinyl_soundtracks' => ['vinyl', 'used', ['soundtracks', 'musicals']],

        'cd_punk_metal_hiphop_used' => ['cd', 'used', ['punk', 'metal', 'nu metal', 'hip hop']],
        'cd_punk_metal_hiphop_sealed' => ['cd', 'sealed', ['punk', 'metal', 'nu metal', 'hip hop']],
        'cd_hiphop_used' => ['cd', 'used', ['hip hop']],
        'cd_metal_used' => ['cd', 'used', ['metal', 'nu metal']],
        'cd_punk_used' => ['cd', 'used', ['punk']],
        'cd_hiphop_sealed' => ['cd', 'sealed', ['hip hop']],
        'cd_metal_sealed' => ['cd', 'sealed', ['metal', 'nu metal']],
        'cd_punk_sealed' => ['cd', 'sealed', ['punk']],
        'cd_rock_alt_newwave_used' => ['cd', 'used', ['rock', 'alt', 'new wave']],
        'cd_rock_alt_newwave_sealed' => ['cd', 'sealed', ['rock', 'alt', 'new wave']],
        'cd_rb_jazz_used' => ['cd', 'used', ['rnb', 'jazz']],
        'cd_rb_jazz_sealed' => ['cd', 'sealed', ['rnb', 'jazz']],
        'cd_soundtracks_used' => ['cd', 'used', ['soundtracks', 'musicals']],
        'cd_soundtracks_sealed' => ['cd', 'sealed', ['soundtracks', 'musicals']],
        'cd_classical_opera_used' => ['cd', 'used', ['classical', 'opera']],
        'cd_classical_opera_sealed' => ['cd', 'sealed', ['classical', 'opera']],

        'cassette_hh_metal_punk' => ['cassette', 'any', ['hip hop', 'metal', 'punk']],
        'cassette_rb_jazz_rock' => ['cassette', 'any', ['rnb', 'jazz', 'rock']],
        'cassette_hiphop_used' => ['cassette', 'used', ['hip hop']],
        'cassette_metal_used' => ['cassette', 'used', ['metal']],
        'cassette_punk_used' => ['cassette', 'used', ['punk']],
        'cassette_rnb_used' => ['cassette', 'used', ['rnb']],
        'cassette_rock_used' => ['cassette', 'used', ['rock']],
        'cassette_jazz_used' => ['cassette', 'used', ['jazz']],

        'rpm45_hh_metal_punk' => ['45', 'any', ['hip hop', 'metal', 'punk']],
        'rpm45_newwave_rock_alt' => ['45', 'any', ['new wave', 'rock', 'alt']],
    ];

    /**
     * Broad buckets: item_type => [format, condition]. Any genre of the format
     * can be picked; without a pick there is no group bonus.
     */
    const OPEN_TYPES = [
        'individual_vinyl' => ['vinyl', 'used'],
        'bulk_vinyl_anything_else' => ['vinyl', 'used'],
        'cd_general_used' => ['cd', 'used'],
        'cd_general_sealed' => ['cd', 'sealed'],
        'cassette_everything_else' => ['cassette', 'any'],
        'rpm45_anything_else' => ['45', 'any'],
    ];

    // Words that don't identify a release.
    const STOPWORDS = ['the', 'and', 'a', 'an', 'of', 'lp', 'lps', 'vinyl', 'record', 'records', 'album',
        'used', 'sealed', 'new', 'original', 'press', 'pressing', 'reissue', 'edition', 'deluxe', 'remastered',
        'with', 'from', 'for', 'vol', 'volume', 'ost', 'soundtrack', 'various', 'artists', 'feat', 'ft'];

    /** Per-request memo so a 20-line haul doesn't repeat the same group query. */
    protected $memo = [];
    protected $taxonomy = [];

    /** "Other genres" / "General" buckets: every OPEN_TYPES entry except individual vinyl. */
    public static function isCatchAll($itemType)
    {
        return isset(self::OPEN_TYPES[$itemType]) && $itemType !== 'individual_vinyl';
    }

    public static function normal()
    {
        return ['multiplier' => 1.0, 'level' => 'normal', 'reason' => null];
    }

    /**
     * @param  array  $line  a buy line (item_type, title, ...)
     * @return array{multiplier: float, level: string, reason: ?string}
     */
    public function forLine(array $line, $businessId, $locationId)
    {
        try {
            $businessId = (int) $businessId;
            $locationId = (int) $locationId;
            $itemType = (string) ($line['item_type'] ?? '');
            if ($businessId <= 0 || $locationId <= 0 || $itemType === '') {
                return self::normal();
            }

            if ($itemType === self::EBAY_TYPE) {
                return $this->ebayMargin($businessId, $locationId, (string) ($line['genre'] ?? ''));
            }

            // Catch-all buckets (mixed lots) always pay their flat rate: no
            // speed adjustment, so a box prices the same whoever rings it up.
            if (self::isCatchAll($itemType)) {
                return self::normal();
            }

            $choices = $this->genreChoices($businessId, $itemType);
            $genre = (string) ($line['genre'] ?? '');
            $genre = isset($choices[$genre]) ? $genre : '';

            if ($itemType === 'individual_vinyl') {
                $title = trim((string) ($line['title'] ?? ''));
                $byTitle = $title !== '' ? $this->titleDemand($businessId, $locationId, $title) : self::normal();
                if ($byTitle['level'] !== 'normal' || $genre === '') {
                    return $byTitle;
                }
                return $this->cachedGroup($businessId, $locationId, $itemType, $genre, $choices[$genre]);
            }

            if (isset(self::TYPE_MAP[$itemType]) || $genre !== '') {
                return $this->cachedGroup($businessId, $locationId, $itemType, $genre, $genre !== '' ? $choices[$genre] : null);
            }
            return self::normal();
        } catch (\Throwable $e) {
            Log::warning('BuyDemandService failed', ['item_type' => $line['item_type'] ?? null, 'err' => $e->getMessage()]);
            return self::normal();
        }
    }

    /** [format, condition, genre canon keys or null = any] for a type, null if unmapped. */
    protected function typeSpec($itemType)
    {
        if (isset(self::TYPE_MAP[$itemType])) {
            return self::TYPE_MAP[$itemType];
        }
        if (isset(self::OPEN_TYPES[$itemType])) {
            return [self::OPEN_TYPES[$itemType][0], self::OPEN_TYPES[$itemType][1], null];
        }
        return null;
    }

    /**
     * Genres a line of this type can be narrowed to: [canon key => label],
     * from the live sub-categories under the type's format. Group types only
     * offer their own genres (R&B/Jazz CDs: R&B or Jazz); broad buckets offer
     * every genre of the format. Empty = no dropdown.
     */
    public function genreChoices($businessId, $itemType)
    {
        $spec = $this->typeSpec($itemType);
        if ($spec === null || self::isCatchAll($itemType)) {
            return [];
        }
        list($format, $condition, $genres) = $spec;
        $formatIds = $this->formatIds((int) $businessId, $format, $condition);
        list(, $subs) = $this->taxonomy((int) $businessId);
        $parents = array_flip($formatIds);
        $out = [];
        foreach ($subs as $s) {
            if (!isset($parents[$s['parent']])) {
                continue;
            }
            $canon = GenreFixController::canon($s['name']);
            if ($canon === '' || ($genres !== null && !in_array($canon, $genres, true))) {
                continue;
            }
            if (!isset($out[$canon])) {
                $out[$canon] = trim($s['name']);
            }
        }
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return count($out) >= 2 ? $out : [];
    }

    /**
     * eBay-value lines can be any category: [c:<id> => "Format" or "Format > Genre"].
     * Gear/apparel/etc. are top-level categories here too, so they can be picked.
     */
    public function categoryChoices($businessId)
    {
        list($formats, $subs) = $this->taxonomy((int) $businessId);
        $out = [];
        foreach ($formats as $id => $name) {
            $out['c:' . $id] = trim($name);
        }
        foreach ($subs as $id => $sub) {
            if (isset($formats[$sub['parent']])) {
                $out['c:' . $id] = trim($formats[$sub['parent']]) . ' › ' . trim($sub['name']);
            }
        }
        asort($out, SORT_NATURAL | SORT_FLAG_CASE);
        return $out;
    }

    /**
     * An eBay-value line's margin: how fast its category sells at the store
     * (a genre vs its format, a top-level category vs the whole store) picks
     * the fast / normal / slow margin from Settings -> Buy Margins. No
     * category picked = normal. Never a demand multiplier: the margin is the
     * whole price.
     */
    protected function ebayMargin($businessId, $locationId, $pick)
    {
        $tier = 'normal';
        $superFast = false;
        $reason = null;
        list($formats, $subs) = $this->taxonomy($businessId);
        $id = strpos($pick, 'c:') === 0 ? (int) substr($pick, 2) : 0;
        if ($id > 0 && (isset($formats[$id]) || isset($subs[$id]))) {
            $key = 'e:' . $businessId . ':' . $locationId . ':' . $id;
            if (!isset($this->memo[$key])) {
                $this->memo[$key] = Cache::remember('buydemand6:' . $key, self::CACHE_SECONDS, function () use ($businessId, $locationId, $id, $formats, $subs) {
                    $measure = function ($locs) use ($businessId, $id, $formats, $subs) {
                        if (isset($subs[$id])) {
                            list($sold, $onHand) = $this->soldAndOnHand($businessId, $locs, [$subs[$id]['parent']], [$id]);
                            list($bSold, $bOnHand) = $this->soldAndOnHand($businessId, $locs, [$subs[$id]['parent']], null);
                            $vs = 'the rest of ' . trim($formats[$subs[$id]['parent']] ?? 'this format');
                        } else {
                            list($sold, $onHand) = $this->soldAndOnHand($businessId, $locs, [$id], null);
                            list($bSold, $bOnHand) = $this->soldAndOnHand($businessId, $locs, null, null);
                            $vs = 'the store';
                        }
                        return [$sold, $onHand, $bSold, $bOnHand, $vs];
                    };
                    $label = trim(isset($subs[$id]) ? $subs[$id]['name'] : $formats[$id]);
                    list($sold, $onHand, $bSold, $bOnHand, $vs) = $measure($locationId);
                    // Nothing at this store (Pico sells no cards): use both stores.
                    $both = false;
                    if ($sold == 0 && $onHand == 0) {
                        $storeIds = $this->buyingStoreIds($businessId);
                        if (count($storeIds) > 1) {
                            list($sold, $onHand, $bSold, $bOnHand, $vs) = $measure($storeIds);
                            $both = true;
                            $vs = str_replace('the store', 'both stores', $vs);
                        }
                    }
                    $turn = $onHand > 0 ? $sold / $onHand : ($sold > 0 ? INF : 0);
                    $baseTurn = $bOnHand > 0 ? $bSold / $bOnHand : 0;
                    $speed = $baseTurn > 0 ? $turn / $baseTurn : 0;
                    // Needs real numbers either way: 5+ sold to be fast, 20+ on
                    // the shelf to be slow (1 Dragon Ball card isn't a trend).
                    $tier = 'normal';
                    if ($sold >= self::WARM_MIN_SOLD && $speed >= self::WARM_MIN_SPEED) {
                        $tier = 'fast';
                    } elseif ($onHand >= self::EBAY_SLOW_MIN_ON_HAND && $speed < self::SLOW_MAX_SPEED) {
                        $tier = 'slow';
                    }
                    // Super fast = the bonus form's "hot" bar (10+ sold, 1.5x+).
                    $superFast = $sold >= self::HOT_MIN_SOLD && $speed >= self::HOT_MIN_SPEED;
                    $reason = ($both ? 'Both stores, ' : '') . sprintf('%s: %d sold in last %d days, %d in stock', $label, $sold, self::GROUP_WINDOW_DAYS, $onHand);
                    if ($speed > 0 && is_finite($speed)) {
                        $reason .= sprintf(' (sells %.1fx as fast as %s)', $speed, $vs);
                    }
                    if ($superFast) {
                        $reason .= ' · super fast';
                    }
                    if (!$both) {
                        $reason .= isset($subs[$id])
                            ? $this->homeDaysNote($businessId, $locationId, [$subs[$id]['parent']], [$id])
                            : $this->homeDaysNote($businessId, $locationId, [$id], null);
                    }
                    return ['tier' => $tier, 'super_fast' => $superFast, 'reason' => $reason];
                });
            }
            $tier = $this->memo[$key]['tier'];
            $superFast = !empty($this->memo[$key]['super_fast']);
            $reason = $this->memo[$key]['reason'];
        }
        return [
            'multiplier' => 1.0,
            'level' => 'margin',
            'tier' => $tier,
            'super_fast' => $superFast,
            'margin' => BuyMarginSettings::marginFor($businessId, $tier),
            'hv' => BuyMarginSettings::get($businessId),
            'reason' => $reason,
        ];
    }

    /** Pico + Hollywood (the stores that buy), for the both-stores fallback. */
    protected function buyingStoreIds($businessId)
    {
        return DB::table('business_locations')->where('business_id', $businessId)
            ->where(function ($q) {
                $q->where('name', 'like', '%pico%')->orWhere('name', 'like', '%hollywood%');
            })->pluck('id')->map(function ($v) { return (int) $v; })->all();
    }

    /** genreChoices() for every type that has any, for the Beta form's dropdowns. */
    public function allGenreChoices($businessId)
    {
        $out = [self::EBAY_TYPE => $this->categoryChoices($businessId)];
        foreach (array_merge(array_keys(self::TYPE_MAP), array_keys(self::OPEN_TYPES)) as $t) {
            $c = $this->genreChoices($businessId, $t);
            if (!empty($c)) {
                $out[$t] = $c;
            }
        }
        return $out;
    }

    protected function cachedGroup($businessId, $locationId, $itemType, $genre, $genreLabel)
    {
        $key = 'g:' . $businessId . ':' . $locationId . ':' . $itemType . ':' . $genre;
        if (!isset($this->memo[$key])) {
            $this->memo[$key] = Cache::remember('buydemand7:' . $key, self::CACHE_SECONDS, function () use ($businessId, $locationId, $itemType, $genre, $genreLabel) {
                return $this->groupDemand($businessId, $locationId, $itemType, $genre, $genreLabel);
            });
        }
        return $this->memo[$key];
    }

    /** $genre '' = the type's whole genre group; a canon key = just that genre. */
    protected function groupDemand($businessId, $locationId, $itemType, $genre = '', $genreLabel = null)
    {
        $spec = $this->typeSpec($itemType);
        if ($spec === null) {
            return self::normal();
        }
        list($format, $condition, $genres) = $spec;
        if ($genre !== '') {
            $genres = [$genre];
        } elseif ($genres === null) {
            return self::normal();
        }
        list($formatIds, $genreIds) = $this->groupIds($businessId, $format, $condition, $genres);
        if (empty($formatIds) || empty($genreIds)) {
            return self::normal();
        }

        // Every genre has hundreds of days of stock (10/23 check: 500 to 2,700),
        // so "low" means low compared with the rest of that format at the store:
        // a genre that turns over faster than the format average gets the bump.
        list($sold, $onHand) = $this->soldAndOnHand($businessId, $locationId, $formatIds, $genreIds);
        list($fmtSold, $fmtOnHand) = $this->soldAndOnHand($businessId, $locationId, $formatIds, null);
        $turn = $onHand > 0 ? $sold / $onHand : ($sold > 0 ? INF : 0);
        $fmtTurn = $fmtOnHand > 0 ? $fmtSold / $fmtOnHand : 0;
        $speed = $fmtTurn > 0 ? $turn / $fmtTurn : 0;

        $level = self::levelFor($sold, $onHand, $speed, $fmtTurn);

        $reason = ($genreLabel ? $genreLabel . ': ' : '') . sprintf('%d sold in last %d days, %d in stock', $sold, self::GROUP_WINDOW_DAYS, $onHand);
        if ($speed > 0 && is_finite($speed)) {
            $reason .= sprintf(' (sells %.1fx as fast as the rest of this format here)', $speed);
        }
        $reason .= $this->homeDaysNote($businessId, $locationId, $formatIds, $genreIds);
        return $this->result($level, $reason);
    }

    /**
     * Bonus / cut level from a genre's numbers at a store. One place, so the
     * buy form and the Home "Fastest selling genres" Buy form column agree.
     */
    public static function levelFor($sold, $onHand, $speed, $fmtTurn)
    {
        if ($sold >= self::HOT_MIN_SOLD && $speed >= self::HOT_MIN_SPEED) {
            return 'hot';
        }
        if ($sold >= self::WARM_MIN_SOLD && $speed >= self::WARM_MIN_SPEED) {
            return 'warm';
        }
        if ($onHand >= self::OVER_MIN_ON_HAND && $fmtTurn > 0 && $speed < self::COLD_MAX_SPEED) {
            return 'cold';
        }
        if ($onHand >= self::OVER_MIN_ON_HAND && $fmtTurn > 0 && $speed < self::COOL_MAX_SPEED) {
            return 'cool';
        }
        return 'normal';
    }

    /** Short label for a level: "Hot (no extra pay)", "Overstocked −10%", "No change". */
    public static function levelLabel($level)
    {
        $labels = ['hot' => 'Hot (no extra pay)', 'warm' => 'Warm (no extra pay)', 'cool' => 'Overstocked −10%', 'cold' => 'Way overstocked −20%'];
        return $labels[$level] ?? 'No change';
    }

    /**
     * Buy-form level for every genre (sub-category) at one store, in two
     * grouped queries: [sub_category_id => ['level', 'speed', 'sold', 'on_hand']].
     * Same 60-day window and math as a buy line with that genre picked.
     */
    public function levelsForStore($businessId, $locationId)
    {
        $businessId = (int) $businessId;
        $locationId = (int) $locationId;
        return Cache::remember('buydemand4:store:' . $businessId . ':' . $locationId, self::CACHE_SECONDS, function () use ($businessId, $locationId) {
            $sold = DB::table('transaction_sell_lines as tsl')
                ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
                ->join('products as p', 'p.id', '=', 'tsl.product_id')
                ->where('t.business_id', $businessId)
                ->where('t.location_id', $locationId)
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->where('t.transaction_date', '>=', now()->subDays(self::GROUP_WINDOW_DAYS))
                ->whereNull('tsl.parent_sell_line_id')
                ->groupBy('p.category_id', 'p.sub_category_id')
                ->selectRaw('p.category_id as cat, p.sub_category_id as sub, SUM(tsl.quantity - COALESCE(tsl.quantity_returned, 0)) as q')
                ->get();
            $stock = DB::table('variation_location_details as vld')
                ->join('products as p', 'p.id', '=', 'vld.product_id')
                ->where('p.business_id', $businessId)
                ->where('vld.location_id', $locationId)
                ->where('vld.qty_available', '>', 0)
                ->groupBy('p.category_id', 'p.sub_category_id')
                ->selectRaw('p.category_id as cat, p.sub_category_id as sub, SUM(vld.qty_available) as q')
                ->get();
            $subs = [];
            $fmt = [];
            foreach ([[$sold, 0], [$stock, 1]] as $pair) {
                foreach ($pair[0] as $r) {
                    $cat = (int) $r->cat;
                    $q = max(0, (float) $r->q);
                    if (!isset($fmt[$cat])) $fmt[$cat] = [0, 0];
                    $fmt[$cat][$pair[1]] += $q;
                    if ((int) $r->sub > 0) {
                        $k = (int) $r->sub;
                        if (!isset($subs[$k])) $subs[$k] = [$cat, 0, 0];
                        $subs[$k][$pair[1] + 1] += $q;
                    }
                }
            }
            $out = [];
            foreach ($subs as $subId => $v) {
                list($cat, $s, $o) = $v;
                $s = (int) round($s);
                $o = (int) round($o);
                $fmtTurn = ($fmt[$cat][1] ?? 0) > 0 ? $fmt[$cat][0] / $fmt[$cat][1] : 0;
                $turn = $o > 0 ? $s / $o : ($s > 0 ? INF : 0);
                $speed = $fmtTurn > 0 ? $turn / $fmtTurn : 0;
                $out[$subId] = [
                    'level' => self::levelFor($s, $o, $speed, $fmtTurn),
                    'speed' => is_finite($speed) ? round($speed, 2) : null,
                    'sold' => $s,
                    'on_hand' => $o,
                ];
            }
            return $out;
        });
    }

    /**
     * " · Home report: N days avg to sell" for these categories at the store:
     * the same number the Home "Fastest selling genres" row shows (avg days
     * from intake to sale, last 3 months, needs 5+ sold). Empty when thin.
     */
    protected function homeDaysNote($businessId, $locationId, $formatIds, $genreIds)
    {
        try {
            $q = DB::table('transaction_sell_lines_purchase_lines as tslp')
                ->join('purchase_lines as pl', 'pl.id', '=', 'tslp.purchase_line_id')
                ->join('transactions as purchase', 'purchase.id', '=', 'pl.transaction_id')
                ->join('transaction_sell_lines as tsl', 'tsl.id', '=', 'tslp.sell_line_id')
                ->join('transactions as sale', 'sale.id', '=', 'tsl.transaction_id')
                ->join('products as p', 'p.id', '=', 'tsl.product_id')
                ->where('sale.business_id', $businessId)
                ->where('sale.location_id', $locationId)
                ->where('sale.type', 'sell')
                ->where('sale.status', 'final')
                ->whereNull('sale.import_source')
                ->whereBetween('sale.transaction_date', [now()->subMonths(3)->startOfDay(), now()->endOfDay()])
                ->whereNotNull('purchase.transaction_date')
                ->whereRaw('DATEDIFF(sale.transaction_date, purchase.transaction_date) >= 0');
            if ($formatIds !== null) {
                $q->whereIn('p.category_id', $formatIds);
            }
            if ($genreIds !== null) {
                $q->whereIn('p.sub_category_id', $genreIds);
            }
            $r = $q->selectRaw('SUM(DATEDIFF(sale.transaction_date, purchase.transaction_date) * tslp.quantity) / NULLIF(SUM(tslp.quantity), 0) as d, SUM(tslp.quantity) as u')->first();
            if (!$r || (float) $r->u < 5 || $r->d === null) {
                return '';
            }
            return sprintf(' · Home report: %d days avg to sell', (int) round((float) $r->d));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** Units sold in the window and on hand now at the store; $genreIds null = whole format, $formatIds null = whole store. */
    protected function soldAndOnHand($businessId, $locationId, $formatIds, $genreIds)
    {
        $sold = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->where('t.business_id', $businessId)
            ->whereIn('t.location_id', (array) $locationId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.transaction_date', '>=', now()->subDays(self::GROUP_WINDOW_DAYS))
            ->whereNull('tsl.parent_sell_line_id');
        $onHand = DB::table('variation_location_details as vld')
            ->join('products as p', 'p.id', '=', 'vld.product_id')
            ->where('p.business_id', $businessId)
            ->whereIn('vld.location_id', (array) $locationId)
            ->where('vld.qty_available', '>', 0);
        if ($formatIds !== null) {
            $sold->whereIn('p.category_id', $formatIds);
            $onHand->whereIn('p.category_id', $formatIds);
        }
        if ($genreIds !== null) {
            $sold->whereIn('p.sub_category_id', $genreIds);
            $onHand->whereIn('p.sub_category_id', $genreIds);
        }
        return [
            max(0, (int) round((float) $sold->sum(DB::raw('tsl.quantity - COALESCE(tsl.quantity_returned, 0)')))),
            max(0, (int) round((float) $onHand->sum('vld.qty_available'))),
        ];
    }

    protected function titleDemand($businessId, $locationId, $title)
    {
        $words = $this->significantWords($title);
        // One word ("Thriller") matches too much of the catalog to trust.
        if (count($words) < 2) {
            return self::normal();
        }
        $key = 't:' . $businessId . ':' . $locationId . ':' . md5(implode(' ', $words));
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        return $this->memo[$key] = Cache::remember('buydemand7t:' . $key, self::CACHE_SECONDS, function () use ($businessId, $locationId, $words) {
            $vinylIds = $this->formatIds($businessId, 'vinyl', 'any');
            if (empty($vinylIds)) {
                return self::normal();
            }
            // Artist lives in products.artist on Discogs imports but only in
            // name ("Title / Artist") on legacy rows, so match across both.
            $q = DB::table('products')
                ->where('business_id', $businessId)
                ->whereIn('category_id', $vinylIds);
            foreach ($words as $w) {
                $q->whereRaw("CONCAT(COALESCE(artist, ''), ' ', name) LIKE ?", ['%' . $w . '%']);
            }
            $ids = $q->limit(self::TITLE_MAX_MATCHES)->pluck('id')->all();
            if (empty($ids)) {
                return self::normal();
            }

            $sold = (float) DB::table('transaction_sell_lines as tsl')
                ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
                ->where('t.business_id', $businessId)
                ->where('t.location_id', $locationId)
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->where('t.transaction_date', '>=', now()->subDays(self::TITLE_WINDOW_DAYS))
                ->whereNull('tsl.parent_sell_line_id')
                ->whereIn('tsl.product_id', $ids)
                ->sum(DB::raw('tsl.quantity - COALESCE(tsl.quantity_returned, 0)'));
            $onHand = (float) DB::table('variation_location_details')
                ->where('location_id', $locationId)
                ->where('qty_available', '>', 0)
                ->whereIn('product_id', $ids)
                ->sum('qty_available');
            $sold = max(0, round($sold));
            $onHand = max(0, round($onHand));

            $level = 'normal';
            if ($sold >= self::TITLE_HOT_MIN_SOLD && $onHand <= self::TITLE_HOT_MAX_ON_HAND) {
                $level = 'hot';
            } elseif ($sold >= self::TITLE_WARM_MIN_SOLD && $onHand <= self::TITLE_WARM_MAX_ON_HAND) {
                $level = 'warm';
            }
            $reason = sprintf('This title: %d sold in last %d days, %d in stock', $sold, self::TITLE_WINDOW_DAYS, $onHand);
            return $this->result($level, $reason);
        });
    }

    protected function result($level, $reason)
    {
        $mults = ['hot' => self::HOT_MULTIPLIER, 'warm' => self::WARM_MULTIPLIER, 'cool' => self::COOL_MULTIPLIER, 'cold' => self::COLD_MULTIPLIER];
        $mult = $mults[$level] ?? 1.0;
        return ['multiplier' => $mult, 'level' => $level, 'reason' => $reason];
    }

    /** Lowercased title words worth matching on, longest first, max 4. */
    protected function significantWords($title)
    {
        $s = mb_strtolower($title);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        $out = [];
        foreach (preg_split('/\s+/', trim($s)) as $w) {
            if (mb_strlen($w) < 3 || in_array($w, self::STOPWORDS, true)) {
                continue;
            }
            $out[$w] = true;
        }
        $out = array_keys($out);
        usort($out, function ($a, $b) { return mb_strlen($b) - mb_strlen($a); });
        return array_slice($out, 0, 4);
    }

    /** Live categories for the business: [formats id=>name, subs id=>[name, parent]]. */
    protected function taxonomy($businessId)
    {
        if (!isset($this->taxonomy[$businessId])) {
            $rows = DB::table('categories')->where('business_id', $businessId)->whereNull('deleted_at')
                ->select('id', 'name', 'parent_id')->get();
            $formats = [];
            $subs = [];
            foreach ($rows as $r) {
                if ((int) $r->parent_id === 0) {
                    $formats[(int) $r->id] = (string) $r->name;
                } else {
                    $subs[(int) $r->id] = ['name' => (string) $r->name, 'parent' => (int) $r->parent_id];
                }
            }
            $this->taxonomy[$businessId] = [$formats, $subs];
        }
        return $this->taxonomy[$businessId];
    }

    /**
     * Format (parent) category ids matching a format + condition by name, e.g.
     * "Used Vinyl", "Vinyl - Used", "Sealed CD / CD (Sealed)". A format name with
     * no condition word counts for both used and sealed.
     */
    protected function formatIds($businessId, $format, $condition)
    {
        list($formats) = $this->taxonomy($businessId);
        $ids = [];
        foreach ($formats as $id => $name) {
            $n = mb_strtolower($name);
            if (preg_match('/dvd|blu|vhs|laser|player|turntable|cleaner|sleeve|accessor/', $n)) {
                continue;
            }
            $is45 = strpos($n, '45 rpm') !== false || preg_match('/(^|[^0-9])45s?\b/', $n) || preg_match('/\b7\s*(?:inch|")/', $n);
            if ($format === '45') {
                $ok = $is45;
            } elseif ($format === 'vinyl') {
                $ok = !$is45 && (strpos($n, 'vinyl') !== false || preg_match('/\blps?\b/', $n));
            } elseif ($format === 'cd') {
                $ok = preg_match('/\bcds?\b/', $n) || strpos($n, 'compact disc') !== false;
            } elseif ($format === 'cassette') {
                $ok = strpos($n, 'cassette') !== false || preg_match('/\btapes?\b/', $n);
            } else {
                $ok = false;
            }
            if (!$ok) {
                continue;
            }
            $isSealed = (bool) preg_match('/sealed|\bnew\b/', $n);
            $isUsed = strpos($n, 'used') !== false;
            if ($condition === 'used' && $isSealed && !$isUsed) {
                continue;
            }
            if ($condition === 'sealed' && $isUsed && !$isSealed) {
                continue;
            }
            $ids[] = $id;
        }
        return $ids;
    }

    /** [format ids, genre sub-category ids under them whose canon name is in $genres]. */
    protected function groupIds($businessId, $format, $condition, array $genres)
    {
        $formatIds = $this->formatIds($businessId, $format, $condition);
        if (empty($formatIds)) {
            return [[], []];
        }
        list(, $subs) = $this->taxonomy($businessId);
        $parents = array_flip($formatIds);
        $genreIds = [];
        foreach ($subs as $id => $s) {
            if (isset($parents[$s['parent']]) && in_array(GenreFixController::canon($s['name']), $genres, true)) {
                $genreIds[] = $id;
            }
        }
        return [$formatIds, $genreIds];
    }
}
