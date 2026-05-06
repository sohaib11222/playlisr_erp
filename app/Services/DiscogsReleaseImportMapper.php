<?php

namespace App\Services;

use App\Category;

class DiscogsReleaseImportMapper
{
    /**
     * Map Discogs GET /releases/{id} JSON payload to ERP product fields.
     *
     * @param  object  $payload  json_decode object from Discogs API
     * @return array{name: string, artist: string, format: string, product_description: string|null, category_id: int|null, sub_category_id: int|null, discogs_release_id: int, warnings: string[]}
     */
    public function mapFromApiPayload(int $businessId, object $payload, int $releaseId): array
    {
        $warnings = [];

        $artistStr = $this->formatArtists($payload->artists ?? []);
        $title = isset($payload->title) ? trim((string) $payload->title) : '';
        if ($title === '') {
            $warnings[] = 'Release has no title.';
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
        $resolved = $this->resolveCategoryFromGenres(
            $businessId,
            $payload->genres ?? [],
            $payload->styles ?? [],
            $formatTokens
        );
        $categoryId = $resolved['category_id'];
        $subCategoryId = $resolved['sub_category_id'];
        foreach ($resolved['warnings'] as $w) {
            $warnings[] = $w;
        }

        if ($categoryId === null) {
            $warnings[] = 'No matching ERP category/subcategory for Discogs genres/styles — pick manually.';
        }

        // Sarah 2026-05-06: pull catalog number off the first label as SKU.
        // Discogs uses 'none' literally for releases without a catno —
        // ignore those and "n/a"-ish placeholders.
        $sku = null;
        if (!empty($payload->labels) && is_array($payload->labels)) {
            foreach ($payload->labels as $label) {
                if (!is_object($label)) {
                    continue;
                }
                $catno = trim((string) ($label->catno ?? ''));
                $catnoLower = mb_strtolower($catno);
                if ($catno !== '' && $catnoLower !== 'none' && $catnoLower !== 'n/a' && $catnoLower !== 'na') {
                    $sku = $catno;
                    break;
                }
            }
        }

        return [
            'name' => $name,
            'artist' => $artistStr !== '' ? $artistStr : null,
            'format' => $formatStr !== '' ? $formatStr : null,
            'product_description' => $productDescription,
            'category_id' => $categoryId,
            'sub_category_id' => $subCategoryId,
            'sku' => $sku,
            'discogs_release_id' => $releaseId,
            'warnings' => array_values(array_unique($warnings)),
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
     * Match a Discogs release's genre/style + format against the ERP's
     * product categories. When several subcategories match the same
     * genre, prefer the one whose parent category name overlaps with the
     * release's format tokens (so a 7" Pop single picks `7" > Pop`, an
     * LP picks `Used Vinyl > Pop`, etc.).
     *
     * @param  string[]  $formatTokens  lowercase format tokens from extractFormatTokens()
     * @return array{category_id: int|null, sub_category_id: int|null, warnings: string[]}
     */
    private function resolveCategoryFromGenres(int $businessId, $genres, $styles, array $formatTokens = []): array
    {
        $warnings = [];
        $terms = [];
        foreach (is_array($genres) ? $genres : [] as $g) {
            $t = mb_strtolower(trim((string) $g));
            if ($t !== '') {
                $terms[] = $t;
            }
        }
        foreach (is_array($styles) ? $styles : [] as $s) {
            $t = mb_strtolower(trim((string) $s));
            if ($t !== '') {
                $terms[] = $t;
            }
        }
        $terms = array_unique($terms);

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
            $best = null;
            $bestScore = -1;
            foreach ($matches as $m) {
                $score = $m['sub_exact'] ? 1 : 0;
                foreach ($formatTokens as $tok) {
                    if ($tok === '') continue;
                    if ($m['parent_name'] !== '' && mb_strpos($m['parent_name'], $tok) !== false) {
                        // Reward longer, more specific format matches more.
                        $score += 2 + mb_strlen($tok);
                    }
                }
                if ($score > $bestScore) {
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
