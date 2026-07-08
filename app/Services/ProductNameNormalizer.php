<?php

namespace App\Services;

/**
 * Canonical product-name convention: "ARTIST - TITLE".
 *
 *   - Artist comes from the products.artist column (Discogs-clean), never
 *     parsed out of the name.
 *   - Separator is always " - " (space-hyphen-space).
 *   - Title is whatever is left of the current name once the artist is
 *     removed; if the name has no artist in it, the whole name is the title.
 *   - Format / condition / store / genre / catalog number never go in the name.
 *
 * canonical() returns:
 *   ['name' => proposed, 'compliant' => bool|null, 'confident' => bool, 'reason' => string]
 * confident=false means "leave it, a human should look" (no real artist, or
 * the title can't be worked out) — the cleanup report buckets these as flagged
 * and never rewrites them.
 */
class ProductNameNormalizer
{
    /** Alphanumeric-only, lowercased. */
    protected static function key($s)
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) $s));
    }

    /** Order-insensitive key (sorted letters) so "LAMAR,KENDRICK" == "Kendrick Lamar". */
    protected static function sortedKey($s)
    {
        $k = self::key($s);
        $chars = preg_split('//u', $k, -1, PREG_SPLIT_NO_EMPTY);
        sort($chars);
        return implode('', $chars);
    }

    protected static function isRealArtist($artist)
    {
        $a = trim((string) $artist);
        if ($a === '') { return false; }
        if (preg_match('/^(unknown|various|n\/?a|none|no artist)/i', $a)) { return false; }
        return true;
    }

    public static function canonical($artist, $name)
    {
        $artist = trim(preg_replace('/\s+/', ' ', (string) $artist));
        $name = trim(preg_replace('/\s+/', ' ', (string) $name));

        if (!self::isRealArtist($artist)) {
            return ['name' => $name, 'compliant' => null, 'confident' => false, 'reason' => 'no real artist'];
        }

        $title = self::deriveTitle($artist, $name);
        if ($title === '') {
            return ['name' => $name, 'compliant' => false, 'confident' => false, 'reason' => 'cannot derive a title'];
        }

        $canonical = $artist . ' - ' . $title;
        return [
            'name' => $canonical,
            'compliant' => ($canonical === $name),
            'confident' => true,
            'reason' => '',
        ];
    }

    /**
     * Pull the title out of the current name given the known artist. Splits on
     * "/" or " - ", drops the segment that IS the artist (order-insensitive),
     * and returns the rest.
     */
    protected static function deriveTitle($artist, $name)
    {
        $aKey = self::sortedKey($artist);

        $parts = preg_split('/\s*\/\s*|\s+-\s+/', $name);
        $parts = array_values(array_filter(array_map('trim', $parts), function ($p) { return $p !== ''; }));

        if (count($parts) <= 1) {
            // No separator. If the whole name is just the artist, there's no
            // title to keep; otherwise the name already is the title.
            return self::sortedKey($name) === $aKey ? '' : $name;
        }

        $titleParts = [];
        $matched = false;
        foreach ($parts as $p) {
            if (!$matched && self::sortedKey($p) === $aKey) { $matched = true; continue; }
            $titleParts[] = $p;
        }

        // Artist not found among the parts — don't guess which piece is the
        // artist; keep the name as-is (joined with the canonical separator).
        if (!$matched) {
            return implode(' - ', $parts);
        }

        return implode(' - ', $titleParts);
    }
}
