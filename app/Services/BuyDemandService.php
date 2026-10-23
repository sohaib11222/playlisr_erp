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
 *    sold in last 60 days vs on hand, turned into days of stock.
 * Title wins when it applies. Anything unmapped (gear, apparel, cards...) or
 * any failure returns 1.0, so this can never break or raise a normal buy.
 */
class BuyDemandService
{
    // Tuning knobs (Jon). Group = format + genre at the store.
    const GROUP_WINDOW_DAYS = 60;
    const HOT_MAX_DAYS_OF_STOCK = 30;
    const HOT_MIN_SOLD = 10;
    const WARM_MAX_DAYS_OF_STOCK = 60;
    const WARM_MIN_SOLD = 5;

    // Title = one release matched by name at the store.
    const TITLE_WINDOW_DAYS = 90;
    const TITLE_HOT_MIN_SOLD = 2;
    const TITLE_HOT_MAX_ON_HAND = 1;
    const TITLE_WARM_MIN_SOLD = 1;
    const TITLE_WARM_MAX_ON_HAND = 0;
    const TITLE_MAX_MATCHES = 50;

    const HOT_MULTIPLIER = 1.20;
    const WARM_MULTIPLIER = 1.10;

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

        'rpm45_hh_metal_punk' => ['45', 'any', ['hip hop', 'metal', 'punk']],
        'rpm45_newwave_rock_alt' => ['45', 'any', ['new wave', 'rock', 'alt']],
    ];

    // Words that don't identify a release.
    const STOPWORDS = ['the', 'and', 'a', 'an', 'of', 'lp', 'lps', 'vinyl', 'record', 'records', 'album',
        'used', 'sealed', 'new', 'original', 'press', 'pressing', 'reissue', 'edition', 'deluxe', 'remastered',
        'with', 'from', 'for', 'vol', 'volume', 'ost', 'soundtrack', 'various', 'artists', 'feat', 'ft'];

    /** Per-request memo so a 20-line haul doesn't repeat the same group query. */
    protected $memo = [];
    protected $taxonomy = [];

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

            if ($itemType === 'individual_vinyl') {
                $title = trim((string) ($line['title'] ?? ''));
                return $title !== '' ? $this->titleDemand($businessId, $locationId, $title) : self::normal();
            }

            if (!isset(self::TYPE_MAP[$itemType])) {
                return self::normal();
            }
            $key = 'g:' . $businessId . ':' . $locationId . ':' . $itemType;
            if (!isset($this->memo[$key])) {
                $this->memo[$key] = Cache::remember('buydemand:' . $key, self::CACHE_SECONDS, function () use ($businessId, $locationId, $itemType) {
                    return $this->groupDemand($businessId, $locationId, $itemType);
                });
            }
            return $this->memo[$key];
        } catch (\Throwable $e) {
            Log::warning('BuyDemandService failed', ['item_type' => $line['item_type'] ?? null, 'err' => $e->getMessage()]);
            return self::normal();
        }
    }

    protected function groupDemand($businessId, $locationId, $itemType)
    {
        list($format, $condition, $genres) = self::TYPE_MAP[$itemType];
        list($formatIds, $genreIds) = $this->groupIds($businessId, $format, $condition, $genres);
        if (empty($formatIds) || empty($genreIds)) {
            return self::normal();
        }

        $since = now()->subDays(self::GROUP_WINDOW_DAYS);
        $sold = (float) DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 't.id', '=', 'tsl.transaction_id')
            ->join('products as p', 'p.id', '=', 'tsl.product_id')
            ->where('t.business_id', $businessId)
            ->where('t.location_id', $locationId)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.transaction_date', '>=', $since)
            ->whereNull('tsl.parent_sell_line_id')
            ->whereIn('p.category_id', $formatIds)
            ->whereIn('p.sub_category_id', $genreIds)
            ->sum(DB::raw('tsl.quantity - COALESCE(tsl.quantity_returned, 0)'));

        $onHand = (float) DB::table('variation_location_details as vld')
            ->join('products as p', 'p.id', '=', 'vld.product_id')
            ->where('p.business_id', $businessId)
            ->where('vld.location_id', $locationId)
            ->where('vld.qty_available', '>', 0)
            ->whereIn('p.category_id', $formatIds)
            ->whereIn('p.sub_category_id', $genreIds)
            ->sum('vld.qty_available');

        $sold = max(0, round($sold));
        $onHand = max(0, round($onHand));
        $perDay = $sold / self::GROUP_WINDOW_DAYS;
        $days = $perDay > 0 ? $onHand / $perDay : null;

        $level = 'normal';
        if ($days !== null && $sold >= self::HOT_MIN_SOLD && $days < self::HOT_MAX_DAYS_OF_STOCK) {
            $level = 'hot';
        } elseif ($days !== null && $sold >= self::WARM_MIN_SOLD && $days < self::WARM_MAX_DAYS_OF_STOCK) {
            $level = 'warm';
        }

        $reason = sprintf('%d sold in last %d days, %d in stock', $sold, self::GROUP_WINDOW_DAYS, $onHand);
        if ($days !== null) {
            $reason .= sprintf(' (about %d days of stock)', (int) round($days));
        }
        return $this->result($level, $reason);
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
        return $this->memo[$key] = Cache::remember('buydemand:' . $key, self::CACHE_SECONDS, function () use ($businessId, $locationId, $words) {
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
        $mult = $level === 'hot' ? self::HOT_MULTIPLIER : ($level === 'warm' ? self::WARM_MULTIPLIER : 1.0);
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
