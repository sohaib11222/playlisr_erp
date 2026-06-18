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
    // Full per-row dataset (all rows incl. unmatched/Manual). Kept out of
    // latest.json so the hot report/ICA pages don't decode ~13k rows on load.
    const REPORT_ROWS_FILE = 'abc-import/report_rows.json';

    /**
     * Parse a CSV file path. Returns rows:
     *   [ {product, sku, format, location, sales, qty, class, xyz, abc_xyz}, ... ]
     *
     * The analyzer's "All" export carries a banner row above the real header
     * (",,,Sales,,,Q-ty,,,") and repeats column labels (two "Sum"/"1..5"
     * blocks for Sales then Q-ty). We therefore scan for the header row that
     * actually contains "product" + "abc" instead of assuming row 1, and we
     * read by column LABEL — later duplicate labels just overwrite earlier
     * ones, which is fine because we only consume the unique labels
     * (product, sku, format, abc, xyz, abc-xyz). Files with a Location column
     * (older per-store exports) still work: the label is simply present.
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
            $lower = array_map(function ($c) {
                return strtolower(trim((string) $c));
            }, $cols);

            // Lock onto the first row that looks like the real header.
            if ($header === null) {
                if (in_array('product', $lower, true) && in_array('abc', $lower, true)) {
                    $header = $lower;
                }
                continue;
            }

            // Skip blank tail rows.
            if (count($cols) < 4) {
                continue;
            }

            $row = [];
            foreach ($header as $i => $key) {
                if ($key === '') {
                    continue;
                }
                $row[$key] = isset($cols[$i]) ? trim($cols[$i]) : '';
            }
            $product = $row['product'] ?? '';
            $class = strtoupper($row['abc'] ?? '');
            if ($product === '' || !in_array($class, ['A', 'B', 'C'], true)) {
                continue;
            }
            $abcXyz = strtoupper(preg_replace('/\s+/', '', $row['abc-xyz'] ?? ''));
            $rows[] = [
                'product' => $product,
                'sku' => $row['sku'] ?? '',
                'format' => $row['format'] ?? '',
                'location' => strtolower($row['location'] ?? ''),
                'sales' => $this->parseEuroNumber($row['sales'] ?? '0'),
                'qty' => (int) ($row['q-ty'] ?? 0),
                'class' => $class,
                'xyz' => strtoupper($row['xyz'] ?? ''),
                'abc_xyz' => $abcXyz,
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

        // id => product row (for SKU hits, which skip the name index).
        // norm_name => [ {id, category_name, sub_category_name}, ... ]
        $byId = [];
        $index = [];
        foreach ($products as $p) {
            $byId[(int) $p->id] = $p;
            $norm = $this->normalizeName($p->name);
            if ($norm === '') {
                continue;
            }
            $index[$norm][] = $p;
        }

        // SKU index: the analyzer export now carries a SKU column, so an exact
        // SKU hit beats fuzzy name matching outright. Variation sub_sku is the
        // canonical UltimatePOS SKU; products.sku is the fallback. First writer
        // wins so a variation sub_sku isn't clobbered by a product sku.
        $skuIndex = [];
        $varSkus = DB::table('variations as v')
            ->join('products as p2', 'v.product_id', '=', 'p2.id')
            ->where('p2.business_id', $business_id)
            ->whereNotNull('v.sub_sku')
            ->select('v.sub_sku', 'v.product_id')
            ->get();
        foreach ($varSkus as $s) {
            $k = $this->normalizeSku($s->sub_sku);
            if ($k !== '' && !isset($skuIndex[$k])) {
                $skuIndex[$k] = (int) $s->product_id;
            }
        }
        $prodSkus = DB::table('products')
            ->where('business_id', $business_id)
            ->whereNotNull('sku')
            ->select('id', 'sku')
            ->get();
        foreach ($prodSkus as $s) {
            $k = $this->normalizeSku($s->sku);
            if ($k !== '' && !isset($skuIndex[$k])) {
                $skuIndex[$k] = (int) $s->id;
            }
        }

        // Location lookup: stripos(name, hollywood/pico/...) — same convention as HomeController.
        $locations = DB::table('business_locations')
            ->where('business_id', $business_id)
            ->get();

        $location_map = [];
        $global_map = [];
        $global_abcxyz = []; // pid => "AX".."CZ", tied to whichever row set the global class
        $unmatched = [];
        $matched_trace = []; // [{csv_*, matched_id, matched_name, matched_category, candidates_count}, ...]
        $matched_count = 0;
        $sku_matched = 0;
        $report_rows = []; // every CSV row, matched or not — powers the full-report page

        $classRank = ['A' => 3, 'B' => 2, 'C' => 1];

        foreach ($rows as $row) {
            $pick = null;
            $method = 'name';
            $initial_count = 0;
            $final_count = 0;

            // 1. Exact SKU match first.
            $skuKey = $this->normalizeSku($row['sku'] ?? '');
            if ($skuKey !== '' && isset($skuIndex[$skuKey]) && isset($byId[$skuIndex[$skuKey]])) {
                $pick = $byId[$skuIndex[$skuKey]];
                $method = 'sku';
                $initial_count = 1;
                $final_count = 1;
                $sku_matched++;
            }

            // 2. Fall back to normalized name + format narrowing.
            if ($pick === null) {
                $norm = $this->normalizeName($row['product']);
                if ($norm === '' || empty($index[$norm])) {
                    $unmatched[] = $row;
                    $report_rows[] = $this->reportRow($row, false, null, '');
                    continue;
                }
                $candidates = $index[$norm];
                $initial_count = count($candidates);

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

                $pick = $candidates[0];
                $final_count = count($candidates);
            }

            $pid = (int) $pick->id;
            $matched_count++;
            $report_rows[] = $this->reportRow($row, true, $pid, $method);

            $matched_trace[] = [
                'csv_product' => $row['product'],
                'csv_sku' => $row['sku'] ?? '',
                'csv_format' => $row['format'],
                'csv_location' => $row['location'],
                'csv_class' => $row['class'],
                'csv_abc_xyz' => $row['abc_xyz'] ?? '',
                'match_method' => $method,
                'matched_id' => $pid,
                'matched_name' => $pick->name,
                'matched_category' => $pick->category_name ?? '',
                'matched_sub_category' => $pick->sub_category_name ?? '',
                'initial_candidates' => $initial_count,
                'final_candidates' => $final_count,
            ];

            // Best class wins globally; the ABC-XYZ combo follows the same row
            // that set the winning class so the two stay consistent.
            $existing = $global_map[$pid] ?? null;
            if ($existing === null || $classRank[$row['class']] > $classRank[$existing]) {
                $global_map[$pid] = $row['class'];
                if (!empty($row['abc_xyz'])) {
                    $global_abcxyz[$pid] = $row['abc_xyz'];
                }
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
            'abcxyz_map' => $global_abcxyz,
            'location_map' => $location_map,
            'unmatched' => $unmatched,
            'matched_trace' => $matched_trace,
            'matched_count' => $matched_count,
            'sku_matched' => $sku_matched,
            'report_rows' => $report_rows,
            'total' => count($rows),
        ];
    }

    /**
     * One row of the full-report dataset: the analyzer's own data plus whether
     * we could tie it to an ERP product. "manual" = the analyzer's no-SKU /
     * "(Manual)" items, which are the ones the reorder tools can't see.
     */
    protected function reportRow(array $row, bool $inErp, ?int $pid, string $method): array
    {
        return [
            'product' => $row['product'],
            'sku' => $row['sku'] ?? '',
            'format' => $row['format'] ?? '',
            'class' => $row['class'],
            'xyz' => $row['xyz'] ?? '',
            'abc_xyz' => $row['abc_xyz'] ?? '',
            'in_erp' => $inErp ? 1 : 0,
            'matched_id' => $pid,
            'method' => $method,
            'manual' => (trim((string) ($row['sku'] ?? '')) === '') ? 1 : 0,
        ];
    }

    /**
     * Normalize a SKU/barcode for exact matching: lowercase, trim, drop
     * surrounding whitespace. Kept deliberately simple — SKUs are opaque IDs,
     * not names, so we don't strip internal punctuation.
     */
    protected function normalizeSku($sku): string
    {
        return strtolower(trim((string) ($sku ?? '')));
    }

    /**
     * Persist a matched payload to storage. Replaces latest.json atomically.
     */
    public function save(array $payload): void
    {
        if (!Storage::disk('local')->exists(self::STORAGE_DIR)) {
            Storage::disk('local')->makeDirectory(self::STORAGE_DIR);
        }
        // Keep dated backup (with the full row set) so an upload can be rolled back.
        $stamp = date('Y-m-d_His');
        Storage::disk('local')->put(self::STORAGE_DIR . '/snapshot_' . $stamp . '.json', json_encode($payload, JSON_PRETTY_PRINT));

        // The full per-row dataset goes to its own file; latest.json stays lean
        // (maps only) because it's decoded on every report/ICA page load.
        $reportRows = $payload['report_rows'] ?? null;
        unset($payload['report_rows']);
        Storage::disk('local')->put(self::STORAGE_FILE, json_encode($payload, JSON_PRETTY_PRINT));
        if (is_array($reportRows)) {
            Storage::disk('local')->put(self::REPORT_ROWS_FILE, json_encode($reportRows));
        } else {
            Storage::disk('local')->delete(self::REPORT_ROWS_FILE);
        }
    }

    /**
     * The full per-row report (all uploaded rows incl. unmatched/Manual), or
     * [] when none is stored. Read only by the full-report page.
     */
    public function loadReportRows(): array
    {
        if (!Storage::disk('local')->exists(self::REPORT_ROWS_FILE)) {
            return [];
        }
        $data = json_decode(Storage::disk('local')->get(self::REPORT_ROWS_FILE), true);
        return is_array($data) ? $data : [];
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
     * [product_id => "AX".."CZ"] combined ABC-XYZ map. Empty when the active
     * import predates ABC-XYZ capture (older files had no col U).
     */
    public function loadAbcXyzMap(): array
    {
        $data = $this->load();
        if (!$data || empty($data['abcxyz_map'])) {
            return [];
        }
        $out = [];
        foreach ($data['abcxyz_map'] as $pid => $combo) {
            $out[(int) $pid] = (string) $combo;
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
     * Compare CSV format to ERP category name as token SETS, not substrings.
     * Order-independent and survives wording variations:
     *   "CD (Sealed)" CSV  ≈  "Sealed CD" ERP   → match (tokens {cd, sealed})
     *   "Cassettes - Sealed" ≈ "Sealed Cassettes" → match
     *   "Used Vinyl" vs "Sealed Vinyl"          → reject (different specific tokens)
     *
     * Match rule: one side's tokens must be a subset of (or equal to) the other.
     * Substring fallback handles partial cat names ("CD" vs "Sealed CD").
     */
    protected function fmtMatches(string $fmt, string $candidate): bool
    {
        if ($fmt === '' || $candidate === '') {
            return false;
        }
        if ($fmt === $candidate) {
            return true;
        }
        $a = $this->tokenize($fmt);
        $b = $this->tokenize($candidate);
        if (empty($a) || empty($b)) {
            return false;
        }
        $aInB = array_diff($a, $b);
        $bInA = array_diff($b, $a);
        if (empty($aInB) || empty($bInA)) {
            return true; // one is a subset of the other
        }
        // Substring fallback for partial labels.
        return strpos($candidate, $fmt) !== false || strpos($fmt, $candidate) !== false;
    }

    /**
     * Split on whitespace/punctuation, drop very short tokens, normalize "&"→"and".
     */
    protected function tokenize(string $s): array
    {
        $s = str_replace('&', ' and ', $s);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($parts as $p) {
            if (mb_strlen($p) >= 2) {
                $out[] = mb_strtolower($p);
            }
        }
        return array_values(array_unique($out));
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
