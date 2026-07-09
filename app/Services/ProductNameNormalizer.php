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
        // Drop leading quotes/punctuation so a stray wrapping quote can't sneak
        // "Various" past the check (e.g. '"Various').
        $a = preg_replace('/^[^\p{L}\p{N}]+/u', '', $a);
        if ($a === '') { return false; }
        if (preg_match('/^(unknown|various|v\/?a|compilation|soundtrack|o\.?s\.?t\.?|misc|n\/?a|none|no artist)\b/i', $a)) { return false; }
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

    /** Strip wrapping quotes and stray edge punctuation from a name segment. */
    protected static function cleanSegment($s)
    {
        $s = trim((string) $s);
        $s = trim($s, "\"\u{201C}\u{201D}\u{2018}\u{2019}");
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /**
     * Full clean-up for a parsed artist: flip "LASTNAME,FIRST" cataloguing order
     * into natural order, then proper-case. This is what the backfill writes.
     */
    public static function cleanArtistValue($s)
    {
        return self::properArtistCase(self::flipLastFirst($s));
    }

    /**
     * Record-store cataloguing often stores "Lastname,First" with NO space after
     * the comma ("DAVIS,MILES" -> "Miles Davis", "COLE,NAT KING" -> "Nat King
     * Cole"). Only flips a single-comma value where the comma has no trailing
     * space, so natural names like "Earth, Wind & Fire" and "Tyler, The Creator"
     * are left alone.
     */
    public static function flipLastFirst($s)
    {
        $s = trim((string) $s);
        if (preg_match('/^([^,]+),(\S[^,]*)$/u', $s, $m)) {
            return trim($m[2]) . ' ' . trim($m[1]);
        }
        return $s;
    }

    /**
     * All-caps band names that are genuine stylizations and should NOT be
     * title-cased. Most short band names (TOOL, KORN, RUSH, MUSE, DIO) are just
     * upper-cased catalog data and DO want title-casing, so we keep only a
     * curated allowlist rather than blanket-preserving short tokens.
     */
    protected static function keepUpperCase($lower)
    {
        static $keep = [
            'kiss', 'gwar', 'elo', 'abba', 'mgmt', 'nofx', 'devo', 'xtc', 'inxs',
            'omd', 'ufo', 'dmx', 'sza', 'mia', 'asap', 'jpegmafia', 'mstrkrft',
            'sbtrkt', 'mndr', 'hyukoh', 'idles', 'health', 'sophie', 'clipping',
            'girl', 'ho99o9', 'sault', 'brockhampton', 'bts', 'nct', 'exo',
            'wu', 'rtj', 'nin', 'gza', 'rza', 'mf', 'dj', 'mc', 'lcd', 'kmfdm',
        ];
        return in_array($lower, $keep, true);
    }

    /**
     * Proper-case an artist so the field reads clean ("BURZUM"/"burzum" ->
     * "Burzum", "TOOL" -> "Tool", "SUNNY DAY REAL ESTATE" -> "Sunny Day Real
     * Estate"). Left alone:
     *   - anything containing punctuation or digits ("AC/DC", "R.E.M.",
     *     "deadmau5", "Blink-182", "2Pac", "Godspeed You! Black Emperor"),
     *   - a curated allowlist of all-caps stylizations (KISS, GWAR, ...),
     *   - values that already carry a lowercase letter (assumed correct).
     */
    public static function properArtistCase($s)
    {
        $s = trim(preg_replace('/\s+/', ' ', (string) $s));
        if ($s === '') { return $s; }
        if (preg_match('/[^\p{L}\s]/u', $s)) { return $s; }
        if (self::keepUpperCase(mb_strtolower($s))) { return $s; }
        // Already mixed-case (has both an upper and a lower letter) -> assume it
        // was deliberately cased ("Green Day", "iPhone"), leave it. Only all-one-
        // case values (all-caps "TOOL" / all-lower "burzum") get title-cased.
        if (preg_match('/\p{Lu}/u', $s) && preg_match('/\p{Ll}/u', $s)) { return $s; }
        return self::titleCase($s);
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
        // Drop wrapping double-quotes (straight or curly) — a "Various - ..."
        // style quoted title otherwise leaves a stray quote on the segment.
        $name = trim($name, "\"\u{201C}\u{201D}");
        $name = trim(preg_replace('/\s+/', ' ', $name));
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
            $parts = array_values(array_filter(array_map(function ($p) { return self::cleanSegment($p); }, $parts), function ($p) { return $p !== ''; }));
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
            $fk = self::key($first);
            $sk = self::key($second);
            $firstKnown = isset($knownKeys[$fk]);
            $secondKnown = isset($knownKeys[$sk]);
            // When matched, use the catalog's own spelling of that artist so the
            // value stays consistent with the rest of the catalog (already
            // Discogs-clean / proper-cased) instead of the raw name segment. If
            // the catalog only has an ALL-CAPS spelling, proper-case that.
            if ($firstKnown && !$secondKnown) {
                $artist = is_string($knownKeys[$fk]) ? $knownKeys[$fk] : $first;
                return self::validateParsedArtist(self::cleanArtistValue($artist), $second, 'Artist ' . $label . ' Title');
            }
            if ($secondKnown && !$firstKnown) {
                $artist = is_string($knownKeys[$sk]) ? $knownKeys[$sk] : $second;
                return self::validateParsedArtist(self::cleanArtistValue($artist), $first, 'Title ' . $label . ' Artist');
            }
            // Both sides are known artists (e.g. a split/collab) — genuinely
            // ambiguous, leave it for a human.
            if ($firstKnown && $secondKnown) {
                return ['artist' => '', 'title' => trim($first . ' ' . $label . ' ' . $second), 'source' => '', 'confident' => false, 'reason' => 'both sides are known artists — manual'];
            }
            // Neither side is a known artist. The catalog is overwhelmingly
            // "ARTIST / TITLE", so a spaced SLASH reliably means artist-first —
            // fill it. A spaced DASH is unreliable (often a hyphenated title or
            // "Title - Subtitle", e.g. "New Tales To Tell - A Tribute ..."), so
            // without a known-artist match, flag it for manual review.
            if ($label !== '/') {
                return ['artist' => '', 'title' => trim($first . ' - ' . $second), 'source' => '', 'confident' => false, 'reason' => 'dash name, artist not recognized — manual'];
            }
        }

        // Default: artist is the first segment ("Artist / Title").
        return self::validateParsedArtist(self::cleanArtistValue($first), $second, 'Artist ' . $label . ' Title');
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
