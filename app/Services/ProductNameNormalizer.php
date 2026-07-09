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

        // Leave anything tagged "retired" alone until Jon clarifies what it means.
        if (stripos($name, 'retired') !== false || stripos($artist, 'retired') !== false) {
            return ['name' => $name, 'compliant' => null, 'confident' => false, 'reason' => 'contains "retired" — left alone'];
        }

        if (!self::isRealArtist($artist)) {
            return ['name' => $name, 'compliant' => null, 'confident' => false, 'reason' => 'no real artist'];
        }

        $title = self::deriveTitle($artist, $name);
        if ($title === '') {
            return ['name' => $name, 'compliant' => false, 'confident' => false, 'reason' => 'cannot derive a title'];
        }
        $title = self::titleCase($title);

        $canonical = $artist . ' - ' . $title;
        return [
            'name' => $canonical,
            'compliant' => ($canonical === $name),
            'confident' => true,
            'reason' => '',
        ];
    }

    /**
     * Title Case for the title portion: capitalizes each word (and each piece
     * of a hyphen/slash/parenthesised/dotted compound, so "wu-tang" -> "Wu-Tang"
     * and "damn." -> "Damn."), and lowercases minor words unless they're first
     * or last.
     */
    /** Public wrapper so the Discogs rebuild can title-case a release title. */
    public static function properTitle($s)
    {
        return self::titleCase(trim(preg_replace('/\s+/', ' ', (string) $s)));
    }

    protected static function titleCase($s)
    {
        static $minor = ['a', 'an', 'and', 'the', 'of', 'to', 'in', 'on', 'at', 'for', 'but', 'or', 'nor', 'as', 'by', 'from', 'with', 'vs', 'via', 'feat', 'x'];
        $words = preg_split('/\s+/', trim($s));
        $n = count($words);
        foreach ($words as $i => $w) {
            if ($w === '') { continue; }
            $lw = mb_strtolower($w);
            if ($i !== 0 && $i !== $n - 1 && in_array($lw, $minor, true)) {
                $words[$i] = $lw;
                continue;
            }
            // Capitalize the first letter, and any letter after - / ( . or ,
            $words[$i] = preg_replace_callback('/(^|[\-\/(.,])(\p{L})/u', function ($m) {
                return $m[1] . mb_strtoupper($m[2]);
            }, $lw);
        }
        return implode(' ', $words);
    }

    /** Normalized lookup key for an artist string (alphanumeric, lowercased). */
    public static function artistKey($s)
    {
        return self::key($s);
    }

    /**
     * Reverse of canonical(): guess the ARTIST out of a name when the artist
     * column is blank. A name splits on a spaced "/" or " - " into exactly two
     * segments — but the catalog is inconsistent about order ("BURZUM / HVIS
     * LYSET TAR OSS" is artist-first, "American Idiot / Green Day" is artist-
     * last), so position alone can't be trusted.
     *
     * Disambiguation uses $knownKeys — a set (assoc array keyed by artistKey())
     * of artists that already exist elsewhere in the catalog:
     *   - exactly one segment is a known artist -> that segment is the artist.
     *   - both or neither known -> flagged for manual review (no guess).
     * If $knownKeys is null, falls back to first-segment = artist.
     *
     * Only a spaced separator counts, so "AC/DC - Back In Black" splits on the
     * dash. 3+ segments, no separator, or a format/catalog-looking value are
     * returned confident=false so the caller flags them instead of guessing.
     *
     * Returns ['artist' => string, 'title' => string, 'source' => string,
     *          'confident' => bool, 'reason' => string].
     */
    public static function artistFromName($name, $knownKeys = null)
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) $name));
        if ($name === '') {
            return ['artist' => '', 'title' => '', 'source' => '', 'confident' => false, 'reason' => 'empty name'];
        }
        if (stripos($name, 'retired') !== false) {
            return ['artist' => '', 'title' => $name, 'source' => '', 'confident' => false, 'reason' => 'contains "retired" — left alone'];
        }

        // Spaced slash first, then spaced hyphen. Only an exact two-part split
        // is workable; 3+ segments (trailing edition, etc.) are flagged.
        foreach ([['/', '/'], ['-', '-']] as $sep) {
            $label = $sep[1];
            if (!preg_match('/\s' . preg_quote($sep[0], '/') . '\s/', $name)) { continue; }
            $parts = preg_split('/\s+' . preg_quote($sep[0], '/') . '\s+/', $name);
            $parts = array_values(array_filter(array_map('trim', $parts), function ($p) { return $p !== ''; }));
            if (count($parts) !== 2) {
                return ['artist' => '', 'title' => $name, 'source' => '', 'confident' => false, 'reason' => 'multiple "' . $label . '" segments — manual'];
            }
            return self::pickArtist($parts[0], $parts[1], $knownKeys, $label);
        }

        return ['artist' => '', 'title' => $name, 'source' => '', 'confident' => false, 'reason' => 'no separator'];
    }

    /**
     * Given the two segments of a split name, decide which is the artist using
     * the known-artist set (preferred) or first-segment position (fallback).
     */
    protected static function pickArtist($first, $second, $knownKeys, $label)
    {
        if (is_array($knownKeys)) {
            $firstKnown = isset($knownKeys[self::key($first)]);
            $secondKnown = isset($knownKeys[self::key($second)]);
            if ($firstKnown && !$secondKnown) {
                return self::validateParsedArtist($first, $second, 'Artist ' . $label . ' Title');
            }
            if ($secondKnown && !$firstKnown) {
                return self::validateParsedArtist($second, $first, 'Title ' . $label . ' Artist');
            }
            $reason = ($firstKnown && $secondKnown)
                ? 'both sides are known artists — manual'
                : 'neither side is a known artist — manual';
            return ['artist' => '', 'title' => trim($first . ' ' . $label . ' ' . $second), 'source' => '', 'confident' => false, 'reason' => $reason];
        }

        // No known-artist set: assume artist-first.
        return self::validateParsedArtist($first, $second, 'Artist ' . $label . ' Title');
    }

    /**
     * Sanity-gate a parsed artist string. Rejects blanks, non-artist words
     * (unknown/various/n a), lone format/condition tokens, and bare catalog
     * numbers so those get flagged rather than written.
     */
    protected static function validateParsedArtist($artist, $title, $source)
    {
        $fail = function ($reason) use ($title, $source) {
            return ['artist' => '', 'title' => $title, 'source' => $source, 'confident' => false, 'reason' => $reason];
        };

        $artist = trim($artist);
        if ($artist === '') { return $fail('empty artist segment'); }
        if (mb_strlen($artist) > 120) { return $fail('artist segment too long'); }
        if (!self::isRealArtist($artist)) { return $fail('not a real artist (unknown/various/n a)'); }

        $lc = mb_strtolower($artist);
        static $tokens = [
            'lp', 'lps', 'cd', 'cds', 'cassette', 'cassettes', 'vinyl', 'ep',
            '7"', '10"', '12"', '45', '45 rpm', '33 rpm', 'box set', 'boxset',
            'single', 'sealed', 'used', 'new', 'reissue', 'promo', 'test pressing',
            'soundtrack', 'ost', 'compilation', 'various', 'various artists',
        ];
        if (in_array($lc, $tokens, true)) { return $fail('looks like a format/condition token'); }
        // Bare catalog number, e.g. "B0034289-01" or "12345".
        if (preg_match('/^[a-z]{0,4}[-\s]?\d{3,}[a-z0-9\-]*$/i', $artist)) {
            return $fail('looks like a catalog number');
        }

        return ['artist' => $artist, 'title' => $title, 'source' => $source, 'confident' => true, 'reason' => ''];
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
