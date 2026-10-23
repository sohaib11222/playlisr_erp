<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * After-save checks for a new purchase (Jon 10/23).
 *
 * 1. Listers don't realise a saved purchase puts the items on nivessa.com right
 *    away, so the page they land on reminds them to put the items out on the
 *    floor (not behind the desk) so web orders can be pulled.
 * 2. Someone who only meant to change a price sometimes adds a brand-new
 *    product instead (Andy 10/19 listed "invinceable / m jackson" at $10 while
 *    "Michael Jackson - Invincible" was already on the shelf at Pico). New
 *    products in the purchase that look like an existing listing are
 *    flagged so the lister tells a manager (Sarah 10/23: no undo/delete).
 *
 * Read-only; callers flash the result into the session for the popup partial
 * (layouts/partials/listing_check_modal). Any failure returns null.
 */
class ListingCheck
{
    // A new product only counts as "added in this purchase" when it was
    // created this recently, by the person saving the purchase.
    const NEW_PRODUCT_HOURS = 12;
    const MAX_PAIRS = 10;

    // Titles that say nothing on their own ("self", "greatest hits"): still
    // allowed, but only with an exact artist match.
    const GENERIC_TITLES = ['self', 's t', 'st', 'self titled', 'greatest hits', 'best of', 'the best of', 'hits', 'live', 'untitled'];

    public static function forPurchase($transaction, $userId): ?array
    {
        try {
            $lines = DB::table('purchase_lines as l')
                ->join('products as p', 'p.id', '=', 'l.product_id')
                ->where('l.transaction_id', $transaction->id)
                ->get(['l.product_id', 'l.variation_id', 'l.quantity', 'p.created_by', 'p.created_at']);
            if ($lines->isEmpty()) return null;

            $store = DB::table('business_locations')->where('id', $transaction->location_id)->value('name');
            $cutoff = now()->subHours(self::NEW_PRODUCT_HOURS);
            $pairs = [];
            foreach ($lines as $l) {
                if (count($pairs) >= self::MAX_PAIRS) break;
                if ((int) $l->created_by !== (int) $userId || $l->created_at < $cutoff) continue;
                $match = self::existingMatch((int) $l->product_id, (int) $transaction->location_id, (int) $transaction->business_id);
                if ($match) $pairs[] = $match;
            }

            return [
                'purchase_id' => (int) $transaction->id,
                'items' => (int) round($lines->sum('quantity')),
                'store' => $store ? ucfirst($store) : null,
                'online' => in_array($transaction->status, ['received'], true),
                'pairs' => $pairs,
            ];
        } catch (\Throwable $e) {
            \Log::warning('ListingCheck failed: ' . $e->getMessage());
            return null;
        }
    }

    /** Best older listing at the same store and format that looks like the same record. */
    public static function existingMatch(int $productId, int $locationId, int $businessId): ?array
    {
        $new = self::product($productId);
        if (!$new) return null;
        $artist = self::artistKey($new->artist ?: self::artistFromName($new->name));
        $title = self::titleKey($new->name);
        if ($artist === '' || $title === '') return null;

        // Longest artist word anchors the SQL search; the PHP pass decides.
        $words = array_filter(explode(' ', $artist), function ($w) { return strlen($w) >= 3 && $w !== 'the'; });
        usort($words, function ($a, $b) { return strlen($b) - strlen($a); });
        $anchor = $words[0] ?? null;
        if (!$anchor) return null;

        $candidates = DB::table('products as p')
            ->join('variation_location_details as v', 'v.product_id', '=', 'p.id')
            ->where('p.business_id', $businessId)
            ->where('p.id', '<', $productId)
            ->where('p.is_inactive', 0)
            ->where('p.category_id', $new->category_id)
            ->where('v.location_id', $locationId)
            ->where(function ($q) use ($anchor) {
                $q->where('p.artist', 'like', '%' . $anchor . '%')->orWhere('p.name', 'like', '%' . $anchor . '%');
            })
            ->orderBy('p.id', 'desc')
            ->limit(300)
            ->get(['p.id', 'p.name', 'p.artist', 'p.sku']);

        $best = null;
        $bestScore = 0;
        foreach ($candidates as $c) {
            // Two different real barcodes are two different pressings.
            if (self::isBarcode($new->sku) && self::isBarcode($c->sku) && ltrim($new->sku, '0') !== ltrim($c->sku, '0')) continue;
            $ca = self::artistKey($c->artist ?: self::artistFromName($c->name));
            $ct = self::titleKey($c->name);
            if ($ct === '') continue;
            $generic = in_array($title, self::GENERIC_TITLES, true) || in_array($ct, self::GENERIC_TITLES, true);
            $aScore = $generic ? ($ca === $artist ? 1.0 : 0) : self::artistScore($artist, $ca);
            $tScore = self::similar($title, $ct);
            if ($aScore < 0.8 || $tScore < 0.8) continue;
            $score = $aScore + $tScore;
            if ($score > $bestScore) { $best = $c; $bestScore = $score; }
        }
        if (!$best) return null;

        $old = self::product((int) $best->id);
        $oldQty = (float) DB::table('variation_location_details')->where('product_id', $best->id)->where('location_id', $locationId)->sum('qty_available');
        return [
            'new' => self::summary($new),
            'old' => self::summary($old) + ['qty' => (int) round($oldQty)],
        ];
    }

    private static function product(int $id)
    {
        return DB::table('products as p')
            ->leftJoin('variations as v', function ($j) { $j->on('v.product_id', '=', 'p.id')->whereNull('v.deleted_at'); })
            ->where('p.id', $id)
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoin('categories as sc', 'sc.id', '=', 'p.sub_category_id')
            ->first(['p.id', 'p.name', 'p.artist', 'p.sku', 'p.category_id', 'v.sell_price_inc_tax as price', 'c.name as category', 'sc.name as sub_category']);
    }

    private static function summary($p): array
    {
        $name = (string) $p->name;
        $artist = trim((string) $p->artist);
        $label = ($artist !== '' && stripos($name, $artist) === false) ? $artist . ' - ' . $name : $name;
        $category = implode(' - ', array_filter([trim((string) $p->category), trim((string) $p->sub_category)]));
        return ['id' => (int) $p->id, 'label' => $label, 'category' => $category, 'sku' => (string) $p->sku, 'price' => round((float) $p->price, 2)];
    }

    private static function norm($s): string
    {
        $s = html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8');
        $s = strtolower(str_replace('&', ' and ', $s));
        $s = preg_replace('/[\(\[].*?[\)\]]/', ' ', $s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** "JACKSON,MICHAEL" -> "michael jackson"; drops a leading "the". */
    public static function artistKey($artist): string
    {
        $a = html_entity_decode((string) $artist, ENT_QUOTES, 'UTF-8');
        if (substr_count($a, ',') === 1 && strpos($a, ' / ') === false) {
            [$last, $first] = array_map('trim', explode(',', $a));
            if ($first !== '') $a = $first . ' ' . $last;
        }
        return preg_replace('/^the /', '', self::norm($a));
    }

    private static function artistFromName($name): string
    {
        $parts = preg_split('/\s+(?:-|\/)\s+/', (string) $name);
        return count($parts) > 1 ? $parts[0] : '';
    }

    /** Last " - " or " / " segment: names are "Artist - Genre - Title" or "ARTIST / TITLE". */
    public static function titleKey($name): string
    {
        $parts = preg_split('/\s+(?:-|\/)\s+/', (string) $name);
        $t = self::norm(end($parts));
        $s = trim(preg_replace('/\s+/', ' ', preg_replace('/\b(lp|2lp|3lp|vinyl|cd)\b/', '', $t)));
        return $s !== '' ? $s : $t;
    }

    private static function artistScore(string $a, string $b): float
    {
        if ($a === '' || $b === '') return 0;
        if ($a === $b) return 1.0;
        if (strlen($a) >= 4 && strlen($b) >= 4 && (strpos($a, $b) !== false || strpos($b, $a) !== false)) return 0.9;
        // "m jackson" vs "michael jackson": same surname, same first initial.
        $ta = explode(' ', $a);
        $tb = explode(' ', $b);
        if (count($ta) > 1 && count($tb) > 1 && end($ta) === end($tb) && $ta[0][0] === $tb[0][0]) return 0.85;
        return self::similar($a, $b);
    }

    /** 1 - edit distance / longer length; catches typos like invinceable/invincible. */
    private static function similar(string $a, string $b): float
    {
        if ($a === $b) return 1.0;
        $len = max(strlen($a), strlen($b));
        if ($len < 4 || $len > 255) return 0;
        return 1 - levenshtein($a, $b) / $len;
    }

    private static function isBarcode($sku): bool
    {
        $s = trim((string) $sku);
        return ctype_digit($s) && strlen($s) >= 10;
    }
}
