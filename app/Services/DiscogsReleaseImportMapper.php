<?php

namespace App\Services;

use App\Category;

class DiscogsReleaseImportMapper
{
    /** @var ArtistGenreLookup */
    private $artistGenreLookup;

    public function __construct(?ArtistGenreLookup $artistGenreLookup = null)
    {
        $this->artistGenreLookup = $artistGenreLookup ?? new ArtistGenreLookup();
    }

    /**
     * Map Discogs GET /releases/{id} JSON payload to ERP product fields.
     *
     * @param  object  $payload  json_decode object from Discogs API
     * @return array{name: string, artist: string, format: string, product_description: string|null, category_id: int|null, sub_category_id: int|null, image_url: string|null, discogs_release_id: int, warnings: string[]}
     */
    public function mapFromApiPayload(int $businessId, object $payload, int $releaseId): array
    {
        $warnings = [];

        $artistStr = $this->formatArtists($payload->artists ?? []);
        $title = isset($payload->title) ? trim((string) $payload->title) : '';
        if ($title === '') {
            $warnings[] = 'Release has no title.';
        }

        // Jon 2026-05-21: when Discogs genres is solely "Electronic",
        // prefix the title with the first style (e.g. "Techno - Title")
        // so the row name surfaces what kind of electronic music it is.
        $genresList = [];
        if (!empty($payload->genres) && is_array($payload->genres)) {
            foreach ($payload->genres as $g) {
                $g = trim((string) $g);
                if ($g !== '') {
                    $genresList[] = $g;
                }
            }
        }
        $firstStyle = null;
        if (!empty($payload->styles) && is_array($payload->styles)) {
            foreach ($payload->styles as $s) {
                $s = trim((string) $s);
                if ($s !== '') {
                    $firstStyle = $s;
                    break;
                }
            }
        }
        if ($title !== '' && count($genresList) === 1 && $genresList[0] === 'Electronic' && $firstStyle !== null) {
            $title = $firstStyle . ' - ' . $title;
        }

        $name = $this->buildProductName($artistStr, $title);

        $formatStr = $this->formatFormats($payload->formats ?? []);
        if ($formatStr === '') {
            $warnings[] = 'No format information on release.';
        }

        $genreParts = [];
        if (!empty($payload->genres) && is_array($payload->genres)) {
            foreach ($payload->genres as $g) {
                $g = trim((string) $g);
                if ($g !== '') {
                    $genreParts[] = $g;
                }
            }
        }
        if (!empty($payload->styles) && is_array($payload->styles)) {
            foreach ($payload->styles as $s) {
                $s = trim((string) $s);
                if ($s !== '') {
                    $genreParts[] = $s;
                }
            }
        }
        $genreLine = $genreParts !== [] ? implode(', ', array_unique($genreParts)) : null;
        $productDescription = $genreLine !== null ? 'Genres/styles: ' . $genreLine : null;

        // Sarah 2026-05-06: also feed format details (7", LP, CD, 45 RPM…)
        // into category resolution so a "Pop" Discogs genre can pick the
        // right ERP category — e.g. `7", 45 RPM > Pop` for a 45 single
        // vs `Used Vinyl > Pop` for an LP.
        $formatTokens = $this->extractFormatTokens($payload->formats ?? []);

        // Jon 2026-06-12: the store's curated Artist→Bin sheet is more correct
        // than Discogs' genres for how records get binned, so when the artist
        // is listed its bin overrides Discogs entirely. We still run the same
        // format-aware resolver (Used Vinyl > Genre vs 7" > Genre etc.) on the
        // bin's terms. Fall back to Discogs genres only when the artist isn't
        // in the sheet, or its bin has no matching ERP category for this
        // business.
        // Sarah 2026-09-03: 2024+ releases are new stock we sell sealed, not
        // the vintage-used vinyl the mass-add default assumes — flip the
        // Used/Sealed bias below for those so e.g. a 2024 reissue resolves
        // to "Sealed Vinyl > Genre" instead of "Used Vinyl > Genre".
        $releaseYear = $this->extractReleaseYear($payload);

        $overrideTerms = $this->artistGenreLookup->termsForArtist($artistStr !== '' ? $artistStr : null);
        $resolved = null;
        if ($overrideTerms !== []) {
            $override = $this->resolveCategoryFromGenres($businessId, $overrideTerms, [], $formatTokens, [], $releaseYear);
            if ($override['category_id'] !== null) {
                $resolved = $override;
                $warnings[] = 'Category from store artist-genre list (overrode Discogs genres).';
            } else {
                $warnings[] = 'Artist in store genre list but its bin has no matching ERP category — used Discogs genres.';
            }
        }
        if ($resolved === null) {
            $resolved = $this->resolveCategoryFromGenres(
                $businessId,
                $payload->genres ?? [],
                $payload->styles ?? [],
                $formatTokens,
                $this->deriveTitlePriorityTerms($title, $payload->labels ?? []),
                $releaseYear
            );
        }
        $categoryId = $resolved['category_id'];
        $subCategoryId = $resolved['sub_category_id'];
        foreach ($resolved['warnings'] as $w) {
            $warnings[] = $w;
        }

        if ($categoryId === null) {
            $warnings[] = 'No matching ERP category/subcategory for Discogs genres/styles — pick manually.';
        }

        // Sarah 2026-05-06: pull SKU from the release's Label section.
        // Prefer the catalog number (e.g. "SKAO-391"), fall back to the
        // label name if catno is missing/placeholder. Either way, if the
        // Label section has *any* item or code, surface it as the SKU.
        // Discogs uses literal "none" / "n/a" for releases without a catno.
        $sku = null;
        $skuFallback = null; // label name, used only if no real catno found
        if (!empty($payload->labels) && is_array($payload->labels)) {
            foreach ($payload->labels as $label) {
                if (!is_object($label)) {
                    continue;
                }
                $catno = trim((string) ($label->catno ?? ''));
                $catnoLower = mb_strtolower($catno);
                $isRealCatno = ($catno !== '' && $catnoLower !== 'none' && $catnoLower !== 'n/a' && $catnoLower !== 'na');
                if ($isRealCatno) {
                    $sku = $catno;
                    break;
                }
                if ($skuFallback === null) {
                    $labelName = trim((string) ($label->name ?? ''));
                    if ($labelName !== '') {
                        $skuFallback = $labelName;
                    }
                }
            }
        }
        if ($sku === null && $skuFallback !== null) {
            $sku = $skuFallback;
        }

        return [
            'name' => $name,
            'title' => $title !== '' ? $title : null,
            'artist' => $artistStr !== '' ? $artistStr : null,
            'format' => $formatStr !== '' ? $formatStr : null,
            'product_description' => $productDescription,
            'category_id' => $categoryId,
            'sub_category_id' => $subCategoryId,
            'sku' => $sku,
            'image_url' => $this->primaryImageUri($payload->images ?? null),
            'discogs_release_id' => $releaseId,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * Pull the release's primary cover-art URL from a Discogs payload. Discogs
     * flags one image with type == "primary"; fall back to the first image
     * with a usable uri when none is flagged. Returns the full-size `uri`
     * (not the uri150 thumbnail), or null when the release has no images.
     *
     * @param  mixed  $images  Discogs images array (objects with ->type, ->uri)
     */
    private function primaryImageUri($images): ?string
    {
        if (!is_array($images)) {
            return null;
        }
        $first = null;
        foreach ($images as $img) {
            if (!is_object($img)) {
                continue;
            }
            $uri = trim((string) ($img->uri ?? ''));
            if ($uri === '') {
                continue;
            }
            if ($first === null) {
                $first = $uri;
            }
            if (isset($img->type) && $img->type === 'primary') {
                return $uri;
            }
        }
        return $first;
    }

    /**
     * Sarah 2026-09-03: pull the release year the same way the street-date
     * backfill does — prefer the precise `released` date over the bare
     * `year` field — so mass-add category resolution knows how recent a
     * release is.
     *
     * @param  object  $payload  json_decode object from Discogs API
     */
    private function extractReleaseYear(object $payload): ?int
    {
        $released = trim((string) ($payload->released ?? ''));
        if (preg_match('/^(\d{4})/', $released, $m)) {
            return (int) $m[1];
        }
        $year = (int) ($payload->year ?? 0);
        return $year > 0 ? $year : null;
    }

    /**
     * Resolve a category/subcategory for a bare artist name using only the
     * store's curated Artist→Bin sheet (no Discogs payload). Powers the Mass
     * Add auto-fill: typing a known artist pre-selects its bin's category.
     * Returns matched=false when the artist isn't in the sheet or its bin has
     * no matching ERP category for this business.
     *
     * @param  string[]  $formatTokens  optional lowercase format tokens (see extractFormatTokens)
     * @return array{matched: bool, bin: string|null, category_id: int|null, sub_category_id: int|null}
     */
    public function resolveCategoryForArtist(int $businessId, ?string $artist, array $formatTokens = []): array
    {
        $bin = $this->artistGenreLookup->binForArtist($artist);
        $terms = $this->artistGenreLookup->termsForArtist($artist);
        if ($terms === []) {
            return ['matched' => false, 'bin' => null, 'category_id' => null, 'sub_category_id' => null];
        }
        $resolved = $this->resolveCategoryFromGenres($businessId, $terms, [], $formatTokens, []);
        return [
            'matched' => $resolved['category_id'] !== null,
            'bin' => $bin,
            'category_id' => $resolved['category_id'],
            'sub_category_id' => $resolved['sub_category_id'],
        ];
    }

    /**
     * Pull a normalized set of format tokens from a Discogs formats array
     * so we can match against ERP category names that include the format,
     * e.g. ["7\"", "45 rpm", "vinyl"] for a 7" 45 single.
     *
     * @param  mixed  $formats
     * @return string[] lowercase tokens
     */
    private function extractFormatTokens($formats): array
    {
        if (!is_array($formats)) {
            return [];
        }
        $tokens = [];
        foreach ($formats as $f) {
            if (!is_object($f)) {
                continue;
            }
            $name = mb_strtolower(trim((string) ($f->name ?? '')));
            if ($name !== '') {
                $tokens[] = $name; // "vinyl", "cd", "cassette", "file"
            }
            if (!empty($f->descriptions) && is_array($f->descriptions)) {
                foreach ($f->descriptions as $d) {
                    $d = mb_strtolower(trim((string) $d));
                    if ($d === '') {
                        continue;
                    }
                    $tokens[] = $d; // "7\"", "12\"", "lp", "ep", "single", "45 rpm", "33 ⅓ rpm", "album"
                    // Pull the bare size (7", 10", 12") since some category
                    // names use it as a column.
                    if (preg_match('/(\d{1,2})\s*"/u', $d, $m)) {
                        $tokens[] = $m[1] . '"';
                    }
                    // Pull RPM number alone (45, 33, 78).
                    if (preg_match('/(\d{2,3})\s*rpm/u', $d, $m)) {
                        $tokens[] = $m[1] . ' rpm';
                    }
                }
            }
        }
        return array_values(array_unique($tokens));
    }

    private function formatArtists($artists): string
    {
        if (!is_array($artists) && !($artists instanceof \Traversable)) {
            return '';
        }
        $names = [];
        foreach ($artists as $a) {
            if (is_object($a) && isset($a->name)) {
                $n = trim((string) $a->name);
                if ($n !== '') {
                    $names[] = $n;
                }
            }
        }

        return implode(', ', array_unique($names));
    }

    private function buildProductName(string $artistStr, string $title): string
    {
        if ($artistStr !== '' && $title !== '') {
            return $artistStr . ' - ' . $title;
        }

        return $title !== '' ? $title : ($artistStr !== '' ? $artistStr : 'Unknown');
    }

    /**
     * @param  mixed  $formats  array of objects from Discogs
     */
    private function formatFormats($formats): string
    {
        if (!is_array($formats)) {
            return '';
        }
        $parts = [];
        foreach ($formats as $f) {
            if (!is_object($f)) {
                continue;
            }
            $name = isset($f->name) ? trim((string) $f->name) : '';
            $qty = isset($f->qty) ? trim((string) $f->qty) : '';
            $desc = '';
            if (!empty($f->descriptions) && is_array($f->descriptions)) {
                $desc = implode(', ', array_map('strval', $f->descriptions));
            }
            $chunk = $name;
            if ($qty !== '' && $qty !== '0') {
                $chunk = ($chunk !== '' ? $chunk . ' ×' : '') . $qty;
            }
            if ($desc !== '') {
                $chunk = ($chunk !== '' ? $chunk . ' (' : '') . $desc . ($chunk !== '' ? ')' : $desc);
            }
            if ($chunk !== '') {
                $parts[] = $chunk;
            }
        }

        return implode(' | ', array_unique($parts));
    }

    /**
     * Jon 2026-06-09: Discogs genre→style category overrides. For a handful
     * of broad Discogs genres the matching ERP category is named after the
     * *style* rather than the genre, and Discogs' style names don't always
     * match ours (e.g. "Alternative Rock" → our "Alt Rock"). Each rule below
     * maps to our category name. Returns [] when no rule fired, so the caller
     * falls back to the raw genre+style terms.
     *
     * @param  string[]  $genreTerms  lowercased Discogs genres
     * @param  string[]  $styleTerms  lowercased Discogs styles
     * @return string[]  preferred ERP-aligned terms
     */
    private function derivePreferredTerms(array $genreTerms, array $styleTerms): array
    {
        $has = static function (array $haystacks, string $needle): bool {
            foreach ($haystacks as $h) {
                if (mb_strpos($h, $needle) !== false) {
                    return true;
                }
            }
            return false;
        };

        $preferred = [];

        // Rock → prefer the sub-style category.
        if ($has($genreTerms, 'rock')) {
            if ($has($styleTerms, 'metal')) {
                $preferred[] = 'metal';
            }
            if ($has($styleTerms, 'punk')) {
                $preferred[] = 'punk';
            }
            if ($has($styleTerms, 'new wave')) {
                $preferred[] = 'new wave';
            }
            if ($has($styleTerms, 'alternative rock')
                || $has($styleTerms, 'alt rock')
                || $has($styleTerms, 'indie rock')) {
                $preferred[] = 'alt rock';
            }
            if ($has($styleTerms, 'blues')) {
                $preferred[] = 'blues';
            }
        }

        // Folk, World, & Country → Folk or Country by style.
        if ($has($genreTerms, 'folk') && $has($genreTerms, 'country')) {
            if ($has($styleTerms, 'folk')) {
                $preferred[] = 'folk';
            }
            if ($has($styleTerms, 'country')) {
                $preferred[] = 'country';
            }
        }

        // Stage & Screen → Musicals, otherwise Soundtracks.
        if ($has($genreTerms, 'stage') && $has($genreTerms, 'screen')) {
            $preferred[] = $has($styleTerms, 'musical') ? 'musicals' : 'soundtracks';
        }

        // Funk / Soul → only steer Gospel out to its own category; R&B etc.
        // are left to fall through to the default term matching.
        if ($has($genreTerms, 'funk') || $has($genreTerms, 'soul')) {
            if ($has($styleTerms, 'gospel')) {
                $preferred[] = 'gospel';
            }
        }

        return array_values(array_unique($preferred));
    }

    /**
     * Jon 2026-06-12: title/label-derived category steers that Discogs genres
     * miss. "Motown" in the title OR the release's label is a strong R&B
     * signal — Discogs routinely tags these as Pop / Jazz / Funk-Soul. Returned
     * terms are prepended ahead of the genre/style terms in
     * resolveCategoryFromGenres so R&B wins category ties over those, while
     * still falling back if a business has no R&B sub.
     *
     * @param  mixed  $labels  Discogs labels array (objects with ->name)
     * @return string[] lowercase priority terms
     */
    private function deriveTitlePriorityTerms(string $title, $labels = []): array
    {
        $haystack = mb_strtolower($title);
        if (is_array($labels)) {
            foreach ($labels as $label) {
                if (is_object($label) && isset($label->name)) {
                    $haystack .= ' ' . mb_strtolower(trim((string) $label->name));
                }
            }
        }
        $terms = [];
        if (mb_strpos($haystack, 'motown') !== false) {
            $terms[] = 'r&b';
        }
        return $terms;
    }

    /**
     * Match a Discogs release's genre/style + format against the ERP's
     * product categories. When several subcategories match the same
     * genre, prefer the one whose parent category name overlaps with the
     * release's format tokens (so a 7" Pop single picks `7" > Pop`, an
     * LP picks `Used Vinyl > Pop`, etc.).
     *
     * @param  string[]  $formatTokens  lowercase format tokens from extractFormatTokens()
     * @param  string[]  $priorityTerms lowercase terms tried before genre/style (win ties)
     * @return array{category_id: int|null, sub_category_id: int|null, warnings: string[]}
     */
    private function resolveCategoryFromGenres(int $businessId, $genres, $styles, array $formatTokens = [], array $priorityTerms = [], ?int $releaseYear = null): array
    {
        // Sarah 2026-09-03: 2024+ is new/current stock we shelve sealed, not
        // the used vintage vinyl the scoring below defaults to.
        $preferSealed = $releaseYear !== null && $releaseYear >= 2024;

        $warnings = [];
        $genreTerms = [];
        foreach (is_array($genres) ? $genres : [] as $g) {
            $t = mb_strtolower(trim((string) $g));
            if ($t !== '') {
                $genreTerms[] = $t;
            }
        }
        $styleTerms = [];
        foreach (is_array($styles) ? $styles : [] as $s) {
            $t = mb_strtolower(trim((string) $s));
            if ($t !== '') {
                $styleTerms[] = $t;
            }
        }

        // Jon 2026-06-09: for a few broad Discogs genres the ERP category
        // lives under the *style* (e.g. Rock > Punk, Stage & Screen >
        // Soundtracks), so steer resolution to the style first. When a rule
        // fires we match on its terms alone; otherwise fall back to the full
        // genre+style list.
        $preferred = $this->derivePreferredTerms($genreTerms, $styleTerms);
        $terms = $preferred !== []
            ? $preferred
            : array_values(array_unique(array_merge($genreTerms, $styleTerms)));

        // Title-derived steers (e.g. "Motown" → R&B) go first so they win
        // ties in the format-ranked match below, while genre/style terms
        // remain as fallback when the priority term has no matching sub.
        if ($priorityTerms !== []) {
            $terms = array_values(array_unique(array_merge($priorityTerms, $terms)));
        }

        if ($terms === []) {
            return ['category_id' => null, 'sub_category_id' => null, 'warnings' => []];
        }

        $categories = Category::where('business_id', $businessId)
            ->where('category_type', 'product')
            ->get(['id', 'name', 'parent_id']);

        $subs = $categories->where('parent_id', '>', 0)->values();
        $parents = $categories->where('parent_id', 0)->keyBy('id');

        // Collect every (sub, term) pair that matches genre, then rank by
        // how well the parent category name overlaps the format tokens.
        $matches = [];
        foreach ($terms as $term) {
            foreach ($subs as $sub) {
                $subName = mb_strtolower(trim($sub->name));
                if ($subName === '') {
                    continue;
                }
                $isMatch = ($subName === $term)
                    || mb_strpos($subName, $term) !== false
                    || mb_strpos($term, $subName) !== false;
                if (!$isMatch) {
                    continue;
                }
                $parent = $parents->get((int) $sub->parent_id);
                $parentName = $parent ? mb_strtolower(trim($parent->name)) : '';
                $matches[] = [
                    'sub' => $sub,
                    'parent_name' => $parentName,
                    'sub_exact' => ($subName === $term),
                ];
            }
        }

        if (!empty($matches)) {
            // Sarah 2026-05-06: most mass-add work is used LPs, so default
            // to "Used Vinyl > Genre" unless the release's format clearly
            // points elsewhere (specific size like 7"/12", RPM, cd, cassette).
            //   - +5  baseline bonus for any parent containing "used vinyl"
            //   - +10 when a SPECIFIC format token (size/RPM/medium) matches
            //         the parent name
            //   - +2  for generic tokens that overlap (e.g. "vinyl", "album")
            // Specific format wins decisively when applicable, but Used
            // Vinyl wins ties / vague matches.
            $specificTokens = ['7"', '10"', '12"', '33 rpm', '45 rpm', '78 rpm', 'cd', 'cassette', 'reel', 'box set'];
            $sizeTokens = ['7"', '10"', '12"'];
            $releaseSizes = array_values(array_intersect($formatTokens, $sizeTokens));

            // Start unset so the first candidate always wins, even when every
            // candidate's score is negative (a sealed-only genre match, see
            // the penalty below). A hardcoded -1 floor used to drop those to
            // "no category" instead of selecting the lone sealed match.
            $best = null;
            $bestScore = null;
            foreach ($matches as $m) {
                $score = $m['sub_exact'] ? 1 : 0;
                $pn = $m['parent_name'];

                // If the parent is keyed to a specific size (7"/10"/12"),
                // the release must call out the same size. Otherwise drop
                // format-token credit so a 12"/45rpm or size-less release
                // falls through to Used Vinyl instead of `7", 45 RPM`.
                $parentSizes = [];
                foreach ($sizeTokens as $sz) {
                    if ($pn !== '' && mb_strpos($pn, $sz) !== false) {
                        $parentSizes[] = $sz;
                    }
                }
                $sizeConflict = !empty($parentSizes)
                    && empty(array_intersect($releaseSizes, $parentSizes));

                $isUsedParent = ($pn !== '') && (
                    mb_strpos($pn, 'used vinyl') !== false
                    || mb_strpos($pn, 'used cd') !== false
                );
                $isSealedParent = ($pn !== '') && (
                    mb_strpos($pn, 'sealed') !== false
                    || preg_match('/\bnew (vinyl|cd|cds|cassette|cassettes|lp|45s)\b/u', $pn) === 1
                );

                if ($preferSealed) {
                    // Sarah 2026-09-03: a 2024+ release is new stock we shelve
                    // sealed, not the used vintage vinyl the store normally
                    // buys via bulk-Discogs — invert the used/sealed bias
                    // below for these so e.g. "Sealed Vinyl > Genre" wins over
                    // "Used Vinyl > Genre". A used sibling stays selectable as
                    // a last resort when it's the ONLY genre match.
                    if ($isSealedParent) {
                        $score += 5;
                    }
                    if ($isUsedParent) {
                        $score -= 100;
                    }
                } else {
                    // Jon 2026-05-24: same baseline bias for CDs — most CD
                    // releases coming in via bulk-Discogs are used stock, so
                    // tie-break toward "Used CD" over "Sealed CD / CD (Sealed)"
                    // when both have a matching genre subcategory.
                    if ($isUsedParent) {
                        $score += 5;
                    }
                    // Sarah 2026-06-24: bulk-Discogs fetches are USED stock by
                    // store convention — the shop buys/lists used records and
                    // Discogs never flags an item as sealed. Actively disfavor any
                    // "Sealed …" / "New <medium>" parent so a used sibling that
                    // matches the same genre always wins (the +5 used bonuses
                    // alone weren't enough when only the sealed parent carried the
                    // matching genre subcategory, or when the used parent isn't
                    // literally named "Used Vinyl"/"Used CD"). The penalty is large
                    // but relative: a sealed parent stays selectable as a last
                    // resort when it's the ONLY genre match.
                    if ($isSealedParent) {
                        $score -= 100;
                    }
                }
                if (!$sizeConflict) {
                    foreach ($formatTokens as $tok) {
                        if ($tok === '' || $pn === '') continue;
                        if (mb_strpos($pn, $tok) !== false) {
                            $score += in_array($tok, $specificTokens, true) ? 10 : 2;
                        }
                    }
                }
                if ($bestScore === null || $score > $bestScore) {
                    $bestScore = $score;
                    $best = $m;
                }
            }
            return [
                'category_id' => (int) $best['sub']->parent_id,
                'sub_category_id' => (int) $best['sub']->id,
                'warnings' => $warnings,
            ];
        }

        foreach ($terms as $term) {
            foreach ($parents as $parent) {
                $pName = mb_strtolower(trim($parent->name));
                if ($pName === '') {
                    continue;
                }
                if ($pName === $term || mb_strpos($pName, $term) !== false || mb_strpos($term, $pName) !== false) {
                    return [
                        'category_id' => (int) $parent->id,
                        'sub_category_id' => null,
                        'warnings' => $warnings,
                    ];
                }
            }
        }

        return ['category_id' => null, 'sub_category_id' => null, 'warnings' => $warnings];
    }
}
