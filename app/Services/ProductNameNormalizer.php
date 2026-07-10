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

    /** Is this whole segment a "Various Artists" compilation marker? */
    public static function isVariousMarker($s)
    {
        $k = self::key($s);
        return in_array($k, ['various', 'variousartists', 'variousartist', 'va', 'compilation', 'comp'], true);
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
     * Drop Discogs disambiguation markers and trailing parenthetical notes:
     *   "Peanuts*" -> "Peanuts", "Nirvana (2)" -> "Nirvana",
     *   "YUSUF (CAT STEVENS)" -> "YUSUF", "Album (2LP)" -> "Album".
     * Keeps the original if stripping would empty the value.
     */
    public static function stripMarkers($s)
    {
        $s = trim((string) $s);
        $s = preg_replace('/\s*\*+$/', '', $s);
        $stripped = preg_replace('/(?:\s*\([^)]*\))+\s*$/u', '', $s);
        if (trim((string) $stripped) !== '') { $s = $stripped; }
        return trim($s);
    }

    /** Strip wrapping quotes and stray edge punctuation from a name segment. */
    protected static function cleanSegment($s)
    {
        $s = trim((string) $s);
        $s = trim($s, "\"\u{201C}\u{201D}\u{2018}\u{2019}");
        $s = self::stripMarkers($s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /**
     * Non-Latin (mostly Japanese katakana) artist spellings mapped to their
     * canonical English name. General transliteration is unreliable for artist
     * names (プリンス romanizes to "purinsu", not "Prince"), so this is a curated
     * lookup — extend it as more turn up. Keyed by the raw trimmed string.
     */
    protected static function artistAliasMap()
    {
        return [
            'プリンス' => 'Prince',
        ];
    }

    /** True if the string carries CJK / kana / full-width characters. */
    public static function hasNonLatinScript($s)
    {
        return (bool) preg_match('/[\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{FF00}-\x{FFEF}\x{AC00}-\x{D7AF}]/u', (string) $s);
    }

    /**
     * Full clean-up for a parsed artist: map a known non-Latin alias to English,
     * else flip "LASTNAME,FIRST" order and proper-case. This is what the backfill
     * writes. Unmapped non-Latin names are left as-is here and rejected later by
     * validateParsedArtist so they get flagged for manual review.
     */
    /**
     * Hand-fixed canonical artist spellings, keyed by normalized key() of any
     * form the catalog stores them in (both name orders where relevant). These
     * both (a) override the parsed output here and (b) are seeded into the
     * recognized-artist set so the artist is never flagged. Extend as they come.
     */
    public static function curatedArtists()
    {
        return [
            'smiths' => 'The Smiths',
            // Willie Colón — catalogued as "Willie Colon" or "Colon,Willie".
            'williecolon' => 'Willie Colón',
            'colonwillie' => 'Willie Colón',
            // Billie Eilish — "Billie Eilish" or "Eilish,Billie".
            'billieeilish' => 'Billie Eilish',
            'eilishbillie' => 'Billie Eilish',
            // Stylized casing.
            'mfdoom' => 'MF Doom',
            'xxxtentacion' => 'XXXTENTACION',
            'maroon5' => 'Maroon 5',
            'neworder' => 'New Order',
            'notoriousbig' => 'Notorious B.I.G.',
            'fkatwigs' => 'FKA twigs',
            'darylhalljohnoates' => 'Daryl Hall & John Oates',
            'kinggizzardthelizardwizard' => 'King Gizzard & The Lizard Wizard',
            'florencethemachine' => 'Florence & The Machine',
            'pushat' => 'Pusha T',
            'rem' => 'R.E.M.',
            'eazye' => 'Eazy-E',
            'gbh' => 'G.B.H.',
            // Yusuf / Yusuf Islam is Cat Stevens.
            'yusuf' => 'Cat Stevens',
            'yusufislam' => 'Cat Stevens',
            'yusufcatstevens' => 'Cat Stevens',
            '2chainz' => '2 Chainz',
            '100gecs' => '100 Gecs',
            'e40' => 'E-40',
            'falloutboy' => 'Fall Out Boy',
            // ZZ Top — stylized all-caps, must not become "Zz Top".
            'zztop' => 'ZZ Top',
            // Zac Brown Band — mis-sorted as "BROWN,ZAC BAND" (surname-first),
            // which the last/first flip mangles into "Zac Band Brown".
            'zacbrownband' => 'Zac Brown Band',
            'brownzacband' => 'Zac Brown Band',
            // Frank Zappa & The Mothers of Invention — catalogued surname-first
            // with a "&", so the flip bails and leaves the raw "Zappa,Frank ...".
            'frankzappathemothersofinvention' => 'Frank Zappa & The Mothers of Invention',
            'zappafrankthemothersofinvention' => 'Frank Zappa & The Mothers of Invention',
            // Bad catalog spelling in the artist column leaks through: "A-Kon"
            // (should be Akon), "3 lw" (should be 3LW).
            'akon' => 'Akon',
            '3lw' => '3LW',
            // Stylized casing the auto title-caser gets wrong.
            'afi' => 'AFI',
            'abba' => 'ABBA',
            'aha' => 'A-HA',
            '50cent' => '50 Cent',
            // Punctuation dropped by a catalog spelling that omits the "!".
            'againstme' => 'Against Me!',
            // Bob Weir — so "Ace / Bob Weir" (Ace is his album, and also a band
            // name) stops resolving to "Ace" and is recognized as the artist.
            'bobweir' => 'Bob Weir',
            // A mangled "kanYeWest" in one artist column poisoned every Kanye row.
            'kanyewest' => 'Kanye West',
            // Bad catalog spellings (extra/missing spaces).
            'buffalospringfield' => 'Buffalo Springfield',
            'bloodhoundgang' => 'Bloodhound Gang',
        ];
    }

    public static function cleanArtistValue($s)
    {
        // Strip Discogs markers (matched catalog spellings can carry "*"/"(2)").
        $s = self::stripMarkers(trim((string) $s));
        // Double-quotes never belong in an artist value ('"Mott The Hoople',
        // '"Weird Al" Yankovic'); drop them all. Leading apostrophes are part of
        // the name ("'Til Tuesday") and stay.
        if (strpos($s, '"') !== false) { $s = trim(str_replace('"', '', $s)); }
        $map = self::artistAliasMap();
        if (isset($map[$s])) { return $map[$s]; }
        $curated = self::curatedArtists();
        // Match the curated key on the raw value AND after flipping order.
        foreach ([$s, self::flipLastFirst($s)] as $cand) {
            $ck = self::key($cand);
            if (isset($curated[$ck])) { return $curated[$ck]; }
        }
        $out = self::properArtistCase(self::flipLastFirst($s));
        // Possessive/contraction "'S" is always lowercase ("Herman'S Hermits" ->
        // "Herman's Hermits"). Only touches a capital S at a word boundary, so
        // "O'Brien"/"D'Angelo" are untouched.
        $out = preg_replace("/'S\\b/u", "'s", $out);
        $k = self::key($out);
        if (isset($curated[$k])) { return $curated[$k]; }
        return $out;
    }

    /**
     * Un-flip record-store cataloguing order on single-comma names:
     *   - "DAVIS,MILES" / "Jackson, Michael" -> "Michael Jackson" (with or
     *     without a space after the comma),
     *   - "Cure, The" / "BEATLES, THE" -> "The Cure" / "The Beatles".
     * Left alone: collaborations ("Earth, Wind & Fire", "... and ..."), a real
     * name whose second part starts with "The" ("Tyler, The Creator"), and
     * anything with 0 or 2+ commas.
     */
    public static function flipLastFirst($s)
    {
        $s = trim((string) $s);
        if (substr_count($s, ',') !== 1) { return $s; }
        // "LAST,FIRST & Collaborator" — flip just the leading tight "Last,First"
        // and keep the rest: "Cruz,Celia & Johnny Pacheco" -> "Celia Cruz &
        // Johnny Pacheco", "Baker,Chet & Art Pepper" -> "Chet Baker & Art Pepper".
        // The TIGHT comma (no space after) is required, so a comma-list like
        // "Earth, Wind & Fire" (space after comma) is left untouched.
        if (preg_match('/^([^\s,]+),([^\s,]+)(\s+(?:&|\+|and)\s+.*)$/i', $s, $m)) {
            return $m[2] . ' ' . $m[1] . $m[3];
        }
        if (preg_match('/&|\+|\band\b/i', $s)) { return $s; }
        list($a, $b) = array_map('trim', explode(',', $s));
        if ($a === '' || $b === '') { return $s; }
        if (strcasecmp($b, 'the') === 0) { return $b . ' ' . $a; }  // "Cure, The" -> "The Cure" (case fixed later)
        if (preg_match('/^the\b/i', $b)) { return $s; }            // "Tyler, The Creator"
        // A band sorted under a member's surname ends in a group word: the
        // surname slots in just before that word, not at the very end —
        // "SMITH,PATTI GROUP" -> "Patti Smith Group", "BROWN,ZAC BAND" -> "Zac
        // Brown Band". A person (with or without a middle name) keeps the
        // surname last: "PRESLEY,ELVIS AARON" -> "Elvis Aaron Presley".
        $bWords = preg_split('/\s+/', $b);
        static $groupWords = ['group', 'band', 'orchestra', 'ensemble', 'trio', 'quartet', 'quintet', 'sextet', 'septet', 'project', 'experience', 'singers', 'choir'];
        if (count($bWords) >= 2 && in_array(mb_strtolower(end($bWords)), $groupWords, true)) {
            $suffix = array_pop($bWords);
            return implode(' ', $bWords) . ' ' . $a . ' ' . $suffix;
        }
        return $b . ' ' . $a;                                       // "Jackson, Michael" -> "Michael Jackson"
    }

    /**
     * Does this segment look like a record-store "LASTNAME,FIRST" catalog entry
     * (a tight comma with no space after: "WINTER,CAMERON", "DAVIS,MILES")? That
     * shape is a reliable artist signal, so the parser can trust it over a genre
     * or common word matching on the other side of the name.
     *
     * Kept deliberately narrow to avoid mangling non-person values:
     *   - exactly one comma, no "&"/"and" (those are bands/lists: "Earth,Wind &
     *     Fire", "Hamilton, Joe Frank & Reynolds"),
     *   - no space after the comma (a spaced "Last, First" is left to the normal
     *     known-artist logic),
     *   - both sides start with a letter (not catalog numbers).
     * A multi-word tail is fine — "WINTER,CAMERON" (a person) and "SMITH,PATTI
     * GROUP" / "BROWN,ZAC BAND" (a band sorted under a member's surname) are all
     * the artist side; flipLastFirst puts the surname in the right place.
     */
    /**
     * Choose between the catalog's stored artist spelling and the raw name
     * segment. Normally the catalog spelling wins (it's usually Discogs-clean),
     * but if it's a camelCase run with no spaces ("kanYeWest" — one product's
     * mangled artist column that poisons the recognized set) and the name segment
     * is a normal multi-word name ("Kanye West"), trust the segment instead.
     */
    protected static function pickBetterSpelling($known, $segment)
    {
        $k = trim((string) $known);
        $seg = trim((string) $segment);
        $knownJunk = $k !== '' && !preg_match('/\s/u', $k) && preg_match('/\p{Ll}\p{Lu}/u', $k);
        if ($knownJunk && $seg !== '' && preg_match('/\s/u', $seg)) { return $seg; }
        return $k;
    }

    protected static function looksSurnameFirst($seg)
    {
        $seg = trim((string) $seg);
        if (substr_count($seg, ',') !== 1) { return false; }
        if (preg_match('/&|\+|\band\b/i', $seg)) { return false; }
        if (!preg_match('/\S,\S/u', $seg)) { return false; }
        list($last, $first) = array_map('trim', explode(',', $seg));
        if ($last === '' || $first === '') { return false; }
        if (!preg_match('/^\p{L}/u', $last) || !preg_match('/^\p{L}/u', $first)) { return false; }
        // Real "Last,First" names have multi-letter parts; a single-letter side
        // ("I,I" — an album title) is not a surname-first person.
        if (mb_strlen($last) < 2 || mb_strlen($first) < 2) { return false; }
        return true;
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
        // Preserve stylizations that Title Case would corrupt: a slash ("AC/DC")
        // or a digit ("deadmau5", "Blink-182"). Names with only "." "&" "-" ","
        // are Title Cased (titleCase re-capitalizes after those), so
        // "NOTORIOUS B.I.G." -> "Notorious B.I.G." and
        // "KING GIZZARD & THE LIZARD WIZARD" -> "King Gizzard & The Lizard Wizard".
        if (preg_match('#[/\\\\\d]#u', $s)) { return $s; }
        if (self::keepUpperCase(mb_strtolower($s))) { return $s; }
        // "Sentence case" — only the FIRST letter capitalised across a multi-word
        // value ("Daft punk", "Deep purple") — is almost always bad catalog data,
        // so Title Case it.
        if (preg_match('/\s/u', $s) && preg_match('/^\p{Lu}\P{Lu}*$/u', $s)) { return self::titleCase($s); }
        // Otherwise mixed-case (two+ capitals: "Green Day", "Toro Y Moi") is
        // assumed deliberate. Only all-one-case ("TOOL"/"burzum") gets title-cased.
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
    /**
     * Split a product name into its two cleaned segments on a spaced "/" or
     * " - ". Returns [first, second, separatorLabel] for an exact two-part
     * split, or null (no separator, or 3+ segments). Shared by the parser and
     * the frequency counter so they see names the same way.
     */
    public static function nameSegments($name)
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) $name));
        $name = trim($name, "\"\u{201C}\u{201D}");
        $name = trim(preg_replace('/\s+/', ' ', $name));
        if ($name === '') { return null; }

        foreach (['/', '-'] as $sep) {
            if (!preg_match('/\s' . preg_quote($sep, '/') . '\s/', $name)) { continue; }
            $parts = preg_split('/\s+' . preg_quote($sep, '/') . '\s+/', $name);
            $parts = array_values(array_filter(array_map(function ($p) { return self::cleanSegment($p); }, $parts), function ($p) { return $p !== ''; }));
            if (count($parts) !== 2) { return null; }
            return [$parts[0], $parts[1], $sep];
        }
        return null;
    }

    public static function artistFromName($name, $knownKeys = null)
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) $name));
        $clean = trim($name, "\"\u{201C}\u{201D}");
        if (trim($clean) === '') {
            return ['artist' => '', 'title' => '', 'source' => '', 'confident' => false, 'reason' => 'empty name'];
        }
        if (stripos($name, 'retired') !== false) {
            return ['artist' => '', 'title' => $name, 'source' => '', 'confident' => false, 'reason' => 'contains "retired" — left alone'];
        }

        $seg = self::nameSegments($name);
        if ($seg === null) {
            // Either no separator or an ambiguous 3+ segment name.
            return ['artist' => '', 'title' => trim($clean), 'source' => '', 'confident' => false, 'reason' => 'no clean two-part split'];
        }
        return self::pickArtist($seg[0], $seg[1], $knownKeys, $seg[2]);
    }

    /**
     * Given the two segments of a split name, decide which is the artist using
     * the known-artist set (preferred) or first-segment position (fallback).
     */
    protected static function pickArtist($first, $second, $knownKeys, $label)
    {
        // Compilations: if exactly one side is a "Various" marker, the artist is
        // "Various Artists" (that's the correct value for a comp).
        $fv = self::isVariousMarker($first);
        $sv = self::isVariousMarker($second);
        if ($fv !== $sv) {
            $title = $fv ? $second : $first;
            return ['artist' => 'Various Artists', 'title' => $title, 'source' => 'Compilation', 'confident' => true, 'reason' => '', 'trust' => 'high'];
        }
        if ($fv && $sv) {
            return ['artist' => '', 'title' => trim($first . ' ' . $label . ' ' . $second), 'source' => '', 'confident' => false, 'reason' => 'both sides are "Various" — manual'];
        }

        // Record-store "LASTNAME,FIRST" order (tight comma, no space after) is a
        // strong artist signal — stronger than a genre/common word matching the
        // known-artist set on the other side ("WINTER,CAMERON / HEAVY METAL" is
        // Cameron Winter, not "Heavy Metal"). If exactly one side is shaped that
        // way, it's the artist; ties fall through to the known-artist logic.
        $fs = self::looksSurnameFirst($first);
        $ss = self::looksSurnameFirst($second);
        if ($fs !== $ss) {
            if ($fs) {
                return self::validateParsedArtist(self::cleanArtistValue($first), $second, 'Artist ' . $label . ' Title', 'high');
            }
            return self::validateParsedArtist(self::cleanArtistValue($second), $first, 'Title ' . $label . ' Artist', 'high');
        }

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
                $artist = self::pickBetterSpelling(is_string($knownKeys[$fk]) ? $knownKeys[$fk] : $first, $first);
                return self::validateParsedArtist(self::cleanArtistValue($artist), $second, 'Artist ' . $label . ' Title');
            }
            if ($secondKnown && !$firstKnown) {
                $artist = self::pickBetterSpelling(is_string($knownKeys[$sk]) ? $knownKeys[$sk] : $second, $second);
                return self::validateParsedArtist(self::cleanArtistValue($artist), $first, 'Title ' . $label . ' Artist');
            }
            // Both sides are known artists (e.g. a split/collab) — genuinely
            // ambiguous, leave it for a human.
            if ($firstKnown && $secondKnown) {
                return ['artist' => '', 'title' => trim($first . ' ' . $label . ' ' . $second), 'source' => '', 'confident' => false, 'reason' => 'both sides are known artists — manual'];
            }
            // Neither full segment is recognized. Try collab recognition: a
            // side shaped like "X & Y" / "X and Y" / "X feat Y" whose one of its
            // members IS a known artist is the artist side ("... / David Bowie
            // and Mick Jagger"). Only fires when exactly one side qualifies.
            $firstCollab = self::hasKnownCollaborator($first, $knownKeys);
            $secondCollab = self::hasKnownCollaborator($second, $knownKeys);
            if ($firstCollab && !$secondCollab) {
                return self::validateParsedArtist(self::cleanArtistValue($first), $second, 'Artist ' . $label . ' Title');
            }
            if ($secondCollab && !$firstCollab) {
                return self::validateParsedArtist(self::cleanArtistValue($second), $first, 'Title ' . $label . ' Artist');
            }
            // The catalog mixes BOTH "Artist / Title" (GLAIVE / ...) and
            // "Title / Artist" (... / SHABOOZEY), so position can't tell us which
            // is the artist — flag it rather than guess wrong.
            return ['artist' => '', 'title' => trim($first . ' ' . $label . ' ' . $second), 'source' => '', 'confident' => false, 'reason' => 'artist not recognized — manual'];
        }

        // No known-artist set given (shouldn't happen from the backfill): fall
        // back to first-segment.
        return self::validateParsedArtist(self::cleanArtistValue($first), $second, 'Artist ' . $label . ' Title');
    }

    /**
     * Is $seg a collaboration ("X & Y", "X and Y", "X feat. Y", "X, Y") whose
     * one of its members is a known artist? Only collab-shaped segments qualify,
     * so an ordinary title that merely contains an artist word isn't mistaken
     * for the artist side.
     */
    protected static function hasKnownCollaborator($seg, $knownKeys)
    {
        if (!is_array($knownKeys)) { return false; }
        if (!preg_match('/\s(?:&|and|feat\.?|ft\.?|featuring|with|vs\.?)\s|,/i', $seg)) { return false; }
        $parts = preg_split('/\s+(?:&|and|feat\.?|ft\.?|featuring|with|vs\.?)\s+|\s*,\s*/i', $seg);
        foreach ($parts as $p) {
            $k = self::key(self::cleanSegment($p));
            if ($k !== '' && isset($knownKeys[$k])) { return true; }
        }
        return false;
    }

    /**
     * Sanity-gate a parsed artist string. Rejects blanks, non-artist words
     * (unknown/various/n a), lone format/condition tokens, and bare catalog
     * numbers so those get flagged rather than written.
     */
    protected static function validateParsedArtist($artist, $title, $source, $trust = 'ok')
    {
        $fail = function ($reason) use ($title, $source) {
            return ['artist' => '', 'title' => $title, 'source' => $source, 'confident' => false, 'reason' => $reason];
        };

        $artist = trim($artist);
        if ($artist === '') { return $fail('empty artist segment'); }
        if (mb_strlen($artist) > 120) { return $fail('artist segment too long'); }
        if (!self::isRealArtist($artist)) { return $fail('not a real artist (unknown/various/n a)'); }
        // Multi-artist collabs joined by ";" (e.g. "Colon,Willie; Hector Lavoe")
        // don't flip cleanly — flag them for a human rather than mangle.
        if (strpos($artist, ';') !== false) { return $fail('multiple artists (;) — manual'); }
        // Non-Latin (Japanese, etc.) with no English alias mapped yet — don't
        // write katakana into the artist field; flag it so it can be mapped.
        if (self::hasNonLatinScript($artist)) { return $fail('non-Latin name — needs an English artist (manual)'); }

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
        // A pure number (optionally with trailing punctuation) is never an
        // artist — a year off a compilation ("1987)") or a track no. ("27").
        if (preg_match('/^\d+\W*$/u', $artist)) {
            return $fail('looks like a number, not an artist');
        }

        return ['artist' => $artist, 'title' => $title, 'source' => $source, 'confident' => true, 'reason' => '', 'trust' => $trust];
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
