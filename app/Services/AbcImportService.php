<?php

namespace App\Services;

use DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

/**
 * Externally-computed ABC classification (sales-based, per location).
 * Sarah uploads a CSV from her analyzer each month; the live inventory-value
 * ABC in InventoryCheckService::computeAbcMap defers to this when present.
 *
 * Storage: storage/app/abc-import/latest.json
 *   {
 *     "uploaded_at": "...",
 *     "period_label": "April 2026",
 *     "source_file": "ABC - Nivessa - Apr.csv",
 *     "stats": { "rows": 3689, "matched": 3120, "unmatched": 569 },
 *     "global_map": { "<product_id>": "A"|"B"|"C" },   // best class across locations
 *     "location_map": { "<location_id>": { "<product_id>": "A" } },
 *     "unmatched": [ { "product": "...", "format": "...", "location": "...", "class": "A" } ]
 *   }
 */
class AbcImportService
{
    const STORAGE_DIR = 'abc-import';
    const STORAGE_FILE = 'abc-import/latest.json';

    /**
     * Parse a CSV file path. Returns rows: [ {product, format, location, sales, qty, class}, ... ]
     */
    public function parseCsv(string $path): array
    {
        $rows = [];
        $fh = fopen($path, 'r');
        if (!$fh) {
            return $rows;
        }

        $header = null;
        while (($cols = fgetcsv($fh)) !== false) {
            if ($header === null) {
                $header = array_map(function ($c) {
                    return strtolower(trim($c));
                }, $cols);
                continue;
            }
            // Skip blank tail rows.
            if (count($cols) < 4) {
                continue;
            }

            $row = [];
            foreach ($header as $i => $key) {
                $row[$key] = isset($cols[$i]) ? trim($cols[$i]) : '';
            }
            $product = $row['product'] ?? '';
            $class = strtoupper($row['abc'] ?? '');
            if ($product === '' || !in_array($class, ['A', 'B', 'C'], true)) {
                continue;
            }
            $rows[] = [
                'product' => $product,
                'format' => $row['format'] ?? '',
                'location' => strtolower($row['location'] ?? ''),
                'sales' => $this->parseEuroNumber($row['sales'] ?? '0'),
                'qty' => (int) ($row['q-ty'] ?? 0),
                'class' => $class,
            ];
        }
        fclose($fh);
        return $rows;
    }

    /**
     * Build product_id maps from parsed rows. Returns
     *   ['global_map' => [pid => class], 'location_map' => [loc_id => [pid => class]],
     *    'unmatched' => [rows], 'matched_count' => int, 'total' => int]
     *
     * Matching strategy:
     *  1. Build a normalized-name index over products in this business.
     *  2. For each CSV row, normalize the product name and look up. If multiple
     *     products share that normalized name, narrow by category name matching
     *     the CSV "Format" column.
     *  3. Best class wins per product (A > B > C) in the global map.
     */
    public function match(array $rows, int $business_id): array
    {
        // products has no deleted_at column in this build; categories does.
        $products = DB::table('products as p')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('categories as sc', 'p.sub_category_id', '=', 'sc.id')
            ->where('p.business_id', $business_id)
            ->select('p.id', 'p.name', 'c.name as category_name', 'sc.name as sub_category_name')
            ->get();

        // norm_name => [ {id, category_name, sub_category_name}, ... ]
        $index = [];
        foreach ($products as $p) {
            $norm = $this->normalizeName($p->name);
            if ($norm === '') {
                continue;
            }
            $index[$norm][] = $p;
        }

        // Location lookup: stripos(name, hollywood/pico/...) — same convention as HomeController.
        $locations = DB::table('business_locations')
            ->where('business_id', $business_id)
            ->get();

        $location_map = [];
        $global_map = [];
        $unmatched = [];
        $matched_count = 0;

        $classRank = ['A' => 3, 'B' => 2, 'C' => 1];

        foreach ($rows as $row) {
            $norm = $this->normalizeName($row['product']);
            if ($norm === '' || empty($index[$norm])) {
                $unmatched[] = $row;
                continue;
            }
            $candidates = $index[$norm];

            // Narrow by format/category when possible and more than one candidate.
            if (count($candidates) > 1 && $row['format'] !== '') {
                $fmt = $this->normalizeName($row['format']);
                $narrowed = [];
                foreach ($candidates as $cand) {
                    $catNorm = $this->normalizeName($cand->category_name ?? '');
                    $subNorm = $this->normalizeName($cand->sub_category_name ?? '');
                    if ($this->fmtMatches($fmt, $catNorm) || $this->fmtMatches($fmt, $subNorm)) {
                        $narrowed[] = $cand;
                    }
                }
                if (!empty($narrowed)) {
                    $candidates = $narrowed;
                }
            }

            $pid = (int) $candidates[0]->id;
            $matched_count++;

            // Best class wins globally.
            $existing = $global_map[$pid] ?? null;
            if ($existing === null || $classRank[$row['class']] > $classRank[$existing]) {
                $global_map[$pid] = $row['class'];
            }

            // Per-location.
            $loc_id = $this->resolveLocationId($row['location'], $locations);
            if ($loc_id !== null) {
                $existingLoc = $location_map[$loc_id][$pid] ?? null;
                if ($existingLoc === null || $classRank[$row['class']] > $classRank[$existingLoc]) {
                    $location_map[$loc_id][$pid] = $row['class'];
                }
            }
        }

        return [
            'global_map' => $global_map,
            'location_map' => $location_map,
            'unmatched' => $unmatched,
            'matched_count' => $matched_count,
            'total' => count($rows),
        ];
    }

    /**
     * Persist a matched payload to storage. Replaces latest.json atomically.
     */
    public function save(array $payload): void
    {
        if (!Storage::disk('local')->exists(self::STORAGE_DIR)) {
            Storage::disk('local')->makeDirectory(self::STORAGE_DIR);
        }
        // Keep dated backup so an upload can be rolled back if needed.
        $stamp = date('Y-m-d_His');
        Storage::disk('local')->put(self::STORAGE_DIR . '/snapshot_' . $stamp . '.json', json_encode($payload, JSON_PRETTY_PRINT));
        Storage::disk('local')->put(self::STORAGE_FILE, json_encode($payload, JSON_PRETTY_PRINT));
    }

    /**
     * Load the active payload, or null if none uploaded.
     */
    public function load(): ?array
    {
        if (!Storage::disk('local')->exists(self::STORAGE_FILE)) {
            return null;
        }
        $raw = Storage::disk('local')->get(self::STORAGE_FILE);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Convenience: the [product_id => class] map used by InventoryCheckService
     * and the ABC report. Keys are cast to int.
     */
    public function loadGlobalMap(): array
    {
        $data = $this->load();
        if (!$data || empty($data['global_map'])) {
            return [];
        }
        $out = [];
        foreach ($data['global_map'] as $pid => $cls) {
            $out[(int) $pid] = (string) $cls;
        }
        return $out;
    }

    /**
     * "0,64%" / "223,4" / "12.5" → float. The analyzer exports European format.
     */
    protected function parseEuroNumber(string $value): float
    {
        $v = trim($value);
        if ($v === '') {
            return 0.0;
        }
        $v = str_replace(['%', ' ', "\u{00a0}"], '', $v);
        // If it has both . and , the comma is the decimal mark in this export.
        if (strpos($v, ',') !== false && strpos($v, '.') === false) {
            $v = str_replace(',', '.', $v);
        } elseif (strpos($v, ',') !== false && strpos($v, '.') !== false) {
            // Comma is decimal, period is thousands.
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        }
        return (float) $v;
    }

    /**
     * Substring match guarded against empty strings — strpos errors with
     * "Empty needle" if either side is empty (common when a product has no
     * category and category_name is null).
     */
    protected function fmtMatches(string $fmt, string $candidate): bool
    {
        if ($fmt === '' || $candidate === '') {
            return false;
        }
        if ($fmt === $candidate) {
            return true;
        }
        return strpos($candidate, $fmt) !== false || strpos($fmt, $candidate) !== false;
    }

    /**
     * Lowercase, strip "(Manual)" tag, drop quotes/punctuation, collapse spaces.
     * Sarah's analyzer slugs differ from product names in trivial casing/whitespace
     * ways — this is intentionally aggressive to maximize match rate.
     */
    public function normalizeName($name): string
    {
        $name = (string) ($name ?? '');
        if ($name === '') {
            return '';
        }
        $n = mb_strtolower($name);
        // Strip "(Manual)" / "(MANUAL)" — analyzer tag for manually entered items.
        $n = preg_replace('/\(manual\)/i', '', $n);
        // Strip quoted suffix junk like (CLEAR VINYL/2LP) — keep alphanumerics + slash.
        $n = str_replace(['"', "'", '`', '“', '”', '‘', '’'], '', $n);
        // Drop everything except letters, digits, whitespace, slash, and ampersand.
        $n = preg_replace('/[^\p{L}\p{N}\s\/&]/u', ' ', $n);
        // Collapse whitespace.
        $n = preg_replace('/\s+/u', ' ', $n);
        return trim($n);
    }

    /**
     * CSV location strings: "hollywood", "pico" (lowercase, no spaces).
     * Mirror HomeController's stripos convention against BusinessLocation.name.
     */
    protected function resolveLocationId(string $needle, $locations): ?int
    {
        if ($needle === '') {
            return null;
        }
        foreach ($locations as $l) {
            $name = (string) ($l->name ?? '');
            if ($name === '') {
                continue;
            }
            if (stripos($name, $needle) !== false) {
                return (int) $l->id;
            }
        }
        return null;
    }
}
