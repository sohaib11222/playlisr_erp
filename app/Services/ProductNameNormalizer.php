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

    /**
     * Reverse of canonical(): guess the ARTIST out of a name when the artist
     * column is blank. Two known name shapes carry the artist:
     *
     *   - Legacy imports: "Title / Artist"  -> artist is AFTER the spaced slash.
     *   - Typed / canonical: "Artist - Title" -> artist is BEFORE the first
     *     spaced " - ".
     *
     * Only a spaced separator counts, so "AC/DC - Back In Black" is read as the
     * dash shape (artist "AC/DC"), not the slash shape. A plain title with no
     * separator, or a parsed value that looks like a format/catalog token, is
     * returned confident=false so the caller flags it for manual review rather
     * than stamping a wrong artist.
     *
     * Returns ['artist' => string, 'title' => string, 'source' => string,
     *          'confident' => bool, 'reason' => string].
     */
    public static function artistFromName($name)
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) $name));
        if ($name === '') {
            return ['artist' => '', 'title' => '', 'source' => '', 'confident' => false, 'reason' => 'empty name'];
        }
        if (stripos($name, 'retired') !== false) {
            return ['artist' => '', 'title' => $name, 'source' => '', 'confident' => false, 'reason' => 'contains "retired" — left alone'];
        }

        // Legacy "Title / Artist" (spaced slash): artist is the second half.
        // Only trust an exact two-part split — 3+ segments (e.g. a trailing
        // edition) are ambiguous, so flag them for manual review.
        if (preg_match('/\s\/\s/', $name)) {
            $parts = preg_split('/\s+\/\s+/', $name);
            $parts = array_values(array_filter(array_map('trim', $parts), function ($p) { return $p !== ''; }));
            if (count($parts) === 2) {
                return self::validateParsedArtist($parts[1], $parts[0], 'Title / Artist');
            }
            return ['artist' => '', 'title' => $name, 'source' => '', 'confident' => false, 'reason' => 'multiple " / " segments — manual'];
        }

        // "Artist - Title" (spaced hyphen): artist is the first half. Same
        // two-part-only rule so "Title - Artist - Edition" style rows are
        // flagged rather than mis-parsed.
        if (preg_match('/\s-\s/', $name)) {
            $parts = preg_split('/\s+-\s+/', $name);
            $parts = array_values(array_filter(array_map('trim', $parts), function ($p) { return $p !== ''; }));
            if (count($parts) === 2) {
                return self::validateParsedArtist($parts[0], $parts[1], 'Artist - Title');
            }
            return ['artist' => '', 'title' => $name, 'source' => '', 'confident' => false, 'reason' => 'multiple " - " segments — manual'];
        }

        return ['artist' => '', 'title' => $name, 'source' => '', 'confident' => false, 'reason' => 'no separator'];
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
