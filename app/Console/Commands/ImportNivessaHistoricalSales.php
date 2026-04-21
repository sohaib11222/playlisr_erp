<?php

namespace App\Console\Commands;

use App\BusinessLocation;
use App\Contact;
use App\Product;
use App\Transaction;
use App\Variation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as PhpDate;

/**
 * Import historical POS sales from the Nivessa Backend xlsx into
 * transactions + transaction_sell_lines (Option C hybrid per Sarah
 * 2026-04-21: accurate over fast).
 *
 *   ┌────────────────────────────────────────────────────────────────┐
 *   │ How it parses a sales sheet                                     │
 *   │                                                                 │
 *   │ The sheets (e.g. "HW SEP 25", "PICO SEP 25") interleave date    │
 *   │ separator rows, summary rows, and item rows. We:                │
 *   │   1. Locate the header row by looking for the first row that    │
 *   │      contains 'PRICE' + 'ARTIST' + 'TITLE' cells.               │
 *   │   2. For every row below the header:                            │
 *   │        • If col A looks like an Excel serial or a date string,  │
 *   │          set it as the "current date" for subsequent items.     │
 *   │        • If we can extract a numeric price + artist or title,   │
 *   │          treat it as an item row and build a transaction.       │
 *   │        • Otherwise skip (summary lines like "CASH: $849",       │
 *   │          blank rows, headers, etc.).                            │
 *   │   3. If no date has been seen yet, fall back to the 1st-of-month│
 *   │      parsed from the sheet name ("HW SEP 25" → 2025-09-01).     │
 *   │                                                                 │
 *   │ How it builds a transaction + sell line                         │
 *   │                                                                 │
 *   │   • type=sell, status=final, payment_status=paid (historical).  │
 *   │   • contact_id = business's default walk-in customer.           │
 *   │   • location_id = store resolved from sheet name prefix.        │
 *   │   • total_before_tax = price; tax_amount = 9.75% of price       │
 *   │     (California standard); final_total = price × 1.0975.        │
 *   │     (Assumption: prices on the sheet are pre-tax ring-ups.)     │
 *   │   • transaction_sell_lines gets one row per transaction with    │
 *   │     product_id linked to the placeholder                        │
 *   │     "Legacy Historical Item" product (auto-created on first     │
 *   │     run). legacy_artist / legacy_title / legacy_format /        │
 *   │     legacy_genre / legacy_condition preserve the sheet text.    │
 *   │                                                                 │
 *   │ Idempotency: (import_source, import_external_id) on             │
 *   │ transactions is unique per run — safe to re-run.                │
 *   │                                                                 │
 *   │ Usage                                                           │
 *   │   # Dry-run a single sheet                                      │
 *   │   php artisan nivessa:import-historical-sales <xlsx> \          │
 *   │     --only-sheet="HW SEP 25"                                    │
 *   │                                                                 │
 *   │   # Dry-run all HW + PICO monthly sheets                        │
 *   │   php artisan nivessa:import-historical-sales <xlsx>            │
 *   │                                                                 │
 *   │   # Commit the run                                              │
 *   │   php artisan nivessa:import-historical-sales <xlsx> --commit   │
 *   └────────────────────────────────────────────────────────────────┘
 */
class ImportNivessaHistoricalSales extends Command
{
    protected $signature = 'nivessa:import-historical-sales
                            {file : Path to the Nivessa Backend xlsx}
                            {--business=1 : business_id for imports}
                            {--user=1 : created_by user_id}
                            {--only-sheet= : Import only this one sheet name}
                            {--commit : Actually write (default: dry-run)}
                            {--tax-rate=0.0975 : Sales tax rate to back-out (default 9.75%)}
                            {--max-per-sheet=0 : Cap rows per sheet (0 = all)}';

    protected $description = 'Dry-run/commit import of historical monthly sales sheets into transactions + sell_lines.';

    /** Substring → location name heuristics. Ordered — first match wins. */
    const STORE_HINTS = [
        'hollywood' => ['hw', 'hollywood'],
        'pico'      => ['pico'],
    ];

    /** Skip sheets whose names match any of these regexes. */
    const SKIP_PATTERNS = [
        '/^ebay sales/i',
        '/sealed sales/i',
        '/totals?$/i',
        '/pivot table/i',
        '/^sheet\d+$/i',
        '/dashboard/i',
        '/stats?$/i',
        '/^raw$/i',
        '/overview$/i',
        '/data tables?$/i',
        '/kallax/i',
        '/inventory$/i',
        '/store credit/i',
        '/customer asks/i',
        '/to be listed/i',
        '/distributors/i',
        '/vendors/i',
        '/discogs data/i',
        '/whatnot data/i',
        '/index$/i',
        '/loginspasswords/i',
        '/artist labels/i',
        '/top artists/i',
        '/hierarchy of needs/i',
        '/genre totals/i',
        '/want list analysis/i',
        '/pricing guide/i',
        '/smorgasburg/i',
        '/market list/i',
        '/dj class/i',
        '/requests?$/i',
        '/repairs?$/i',
        '/subscription/i',
        '/consignment/i',
        '/events?$/i',
        '/pickup/i',
        '/labels to make/i',
        '/job application/i',
        '/lent out/i',
        '/shipping (returns|orders)/i',
        '/supplies inventory/i',
        '/collection leads/i',
        '/detail1-3/i',
        '/newused/i',
        '/erick sanchez/i',
        '/brand new reissues/i',
        '/genreartist inventory/i',
        '/team achievements/i',
        '/yoon/i',
        '/vsions/i',
        '/\bul hw\b/i',
    ];

    public function handle()
    {
        $path = $this->argument('file');
        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return 1;
        }
        $businessId = (int) $this->option('business');
        $userId = (int) $this->option('user');
        $commit = (bool) $this->option('commit');
        $onlySheet = $this->option('only-sheet');
        $taxRate = (float) $this->option('tax-rate');
        $maxPerSheet = (int) $this->option('max-per-sheet');

        $this->info("Loading {$path}…");
        $spreadsheet = IOFactory::load($path);

        $placeholderProductId = $commit ? $this->ensurePlaceholderProduct($businessId, $userId) : 0;
        $placeholderVariationId = $commit ? $this->ensurePlaceholderVariation($placeholderProductId) : 0;
        $walkInContactId = $this->resolveWalkInContact($businessId);

        $locationCache = $this->buildLocationCache($businessId);
        $this->line('Location cache: ' . json_encode($locationCache));
        $this->line('Walk-in contact id: ' . $walkInContactId);
        if ($commit) {
            $this->line("Placeholder product: id={$placeholderProductId} variation={$placeholderVariationId}");
        }

        $totals = [
            'sheets_scanned' => 0, 'sheets_skipped' => 0,
            'rows_read' => 0, 'tx_created' => 0, 'tx_duplicate' => 0,
            'rows_skipped_empty' => 0, 'rows_skipped_no_price' => 0,
            'revenue_cents' => 0,
        ];

        foreach ($spreadsheet->getSheetNames() as $sheetName) {
            if ($onlySheet && $sheetName !== $onlySheet) continue;
            if ($this->shouldSkip($sheetName)) {
                $totals['sheets_skipped']++;
                continue;
            }
            $locationId = $this->resolveLocationFromSheetName($sheetName, $locationCache);
            if (!$locationId) {
                $this->line("  · skip '{$sheetName}' (no store match)");
                $totals['sheets_skipped']++;
                continue;
            }
            $totals['sheets_scanned']++;
            $sheetTotals = $this->importSheet(
                $spreadsheet->getSheetByName($sheetName), $sheetName, $locationId,
                $businessId, $userId, $walkInContactId,
                $placeholderProductId, $placeholderVariationId,
                $taxRate, $maxPerSheet, $commit
            );
            foreach ($sheetTotals as $k => $v) {
                $totals[$k] = ($totals[$k] ?? 0) + $v;
            }
        }

        $this->newLine();
        $this->info($commit ? '✅ Import complete.' : '🧪 DRY RUN — no rows written. Re-run with --commit.');
        foreach ($totals as $k => $v) {
            if ($k === 'revenue_cents') {
                $this->line(sprintf('%-22s %s', 'Revenue:', '$' . number_format($v / 100, 2)));
            } else {
                $this->line(sprintf('%-22s %d', $k, $v));
            }
        }
        return 0;
    }

    /* =========================================================================
     * Sheet import
     * ========================================================================= */

    private function importSheet($sheet, $sheetName, $locationId, $businessId, $userId,
                                 $walkInContactId, $placeholderProductId, $placeholderVariationId,
                                 $taxRate, $maxPerSheet, $commit)
    {
        $stats = ['rows_read' => 0, 'tx_created' => 0, 'tx_duplicate' => 0,
                  'rows_skipped_empty' => 0, 'rows_skipped_no_price' => 0,
                  'revenue_cents' => 0];

        $rows = $sheet->toArray(null, true, false, false);
        if (empty($rows)) return $stats;

        // Locate the header row (first row containing PRICE + ARTIST + TITLE).
        $headerIdx = $this->findHeaderRow($rows);
        if ($headerIdx === null) {
            $this->line("  · '{$sheetName}': no header row (PRICE+ARTIST+TITLE) — skipped");
            return $stats;
        }
        $cols = $this->mapHeaderColumns($rows[$headerIdx]);
        $this->line(sprintf(
            "  · '%s' → header@row %d, total rows %d, cols: %s",
            $sheetName, $headerIdx + 1, count($rows),
            implode(',', array_keys($cols))
        ));

        $importSource = 'nivessa_backend_sales_' . Str::slug($sheetName, '_');
        $fallbackDate = $this->sheetNameToDate($sheetName);
        $currentDate = null;

        DB::beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                if ($i <= $headerIdx) continue;
                if ($maxPerSheet > 0 && $stats['rows_read'] >= $maxPerSheet) break;

                // Date-separator row: col A is an Excel serial or parseable date.
                $maybeDate = $this->parseDate($row[0] ?? null);
                if ($maybeDate && $this->isDateLikelyRow($row, $cols)) {
                    $currentDate = $maybeDate;
                    continue;
                }

                $price = $this->readPrice($row, $cols);
                $artist = trim((string) ($row[$cols['artist']] ?? ''));
                $title = trim((string) ($row[$cols['title']] ?? ''));
                if ($price === null) {
                    if (($artist === '' && $title === '')) {
                        $stats['rows_skipped_empty']++;
                    } else {
                        $stats['rows_skipped_no_price']++;
                    }
                    continue;
                }
                if ($price <= 0) {
                    $stats['rows_skipped_no_price']++;
                    continue;
                }
                $stats['rows_read']++;

                // Resolve transaction date: row-inline date > running date > sheet-name fallback.
                $txDate = $currentDate ?: $fallbackDate;
                if (!$txDate) continue;  // no usable date — skip

                $externalId = 'row' . ($i + 1);
                $exists = DB::table('transactions')
                    ->where('business_id', $businessId)
                    ->where('import_source', $importSource)
                    ->where('import_external_id', $externalId)
                    ->exists();
                if ($exists) {
                    $stats['tx_duplicate']++;
                    continue;
                }

                $totalBeforeTax = round($price, 4);
                $taxAmount = round($price * $taxRate, 4);
                $finalTotal = round($totalBeforeTax + $taxAmount, 4);

                $format = isset($cols['format']) ? trim((string) ($row[$cols['format']] ?? '')) : null;
                $genre = isset($cols['genre']) ? trim((string) ($row[$cols['genre']] ?? '')) : null;
                $condition = isset($cols['condition']) ? trim((string) ($row[$cols['condition']] ?? '')) : null;
                $notes = isset($cols['notes']) ? trim((string) ($row[$cols['notes']] ?? '')) : null;

                $additional = trim(implode(' · ', array_filter([
                    $artist !== '' ? ('Artist: ' . $artist) : null,
                    $title !== '' ? ('Title: ' . $title) : null,
                    $format ? ('Format: ' . $format) : null,
                    $genre ? ('Genre: ' . $genre) : null,
                    $condition ? ('Condition: ' . $condition) : null,
                    $notes ? ('Notes: ' . $notes) : null,
                ])));

                if ($commit) {
                    $txId = DB::table('transactions')->insertGetId([
                        'business_id' => $businessId,
                        'type' => 'sell', 'status' => 'final', 'payment_status' => 'paid',
                        'contact_id' => $walkInContactId,
                        'location_id' => $locationId,
                        'transaction_date' => $txDate,
                        'total_before_tax' => $totalBeforeTax,
                        'tax_amount' => $taxAmount,
                        'final_total' => $finalTotal,
                        'discount_amount' => 0,
                        'additional_notes' => $additional ?: null,
                        'created_by' => $userId,
                        'import_source' => $importSource,
                        'import_external_id' => $externalId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    DB::table('transaction_sell_lines')->insert([
                        'transaction_id' => $txId,
                        'product_id' => $placeholderProductId,
                        'variation_id' => $placeholderVariationId,
                        'quantity' => 1,
                        'unit_price' => $totalBeforeTax,
                        'unit_price_inc_tax' => $finalTotal,
                        'item_tax' => $taxAmount,
                        'import_source' => $importSource,
                        'import_external_id' => $externalId,
                        'legacy_artist' => $artist ?: null,
                        'legacy_title' => $title ?: null,
                        'legacy_format' => $format ?: null,
                        'legacy_genre' => $genre ?: null,
                        'legacy_condition' => $condition ?: null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $stats['tx_created']++;
                $stats['revenue_cents'] += (int) round($finalTotal * 100);
            }

            if ($commit) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error("  · '{$sheetName}' aborted: " . $e->getMessage());
        }

        $this->line(sprintf(
            "  · '%s' → created=%d, dup=%d, skipped_no_price=%d, skipped_empty=%d, revenue=$%s",
            $sheetName, $stats['tx_created'], $stats['tx_duplicate'],
            $stats['rows_skipped_no_price'], $stats['rows_skipped_empty'],
            number_format($stats['revenue_cents'] / 100, 2)
        ));
        return $stats;
    }

    /* =========================================================================
     * Header + column parsing
     * ========================================================================= */

    private function findHeaderRow(array $rows)
    {
        foreach ($rows as $i => $row) {
            if ($i > 20) break;  // header should be near the top
            $lowered = array_map(fn($c) => strtolower(trim((string) $c)), $row);
            $hasPrice = in_array('price', $lowered, true);
            $hasArtist = in_array('artist', $lowered, true) || in_array('artist ', $lowered, true);
            $hasTitle = in_array('title', $lowered, true) || in_array('title ', $lowered, true);
            if ($hasPrice && $hasArtist && $hasTitle) {
                return $i;
            }
        }
        return null;
    }

    private function mapHeaderColumns(array $header)
    {
        $map = [];
        foreach ($header as $idx => $cell) {
            $h = strtolower(trim((string) $cell));
            if ($h === '') continue;
            if ($h === 'price') $map['price'] = $idx;
            elseif ($h === 'artist') $map['artist'] = $idx;
            elseif ($h === 'title') $map['title'] = $idx;
            elseif (in_array($h, ['format', 'media type'], true)) $map['format'] = $idx;
            elseif ($h === 'genre' && !isset($map['genre'])) $map['genre'] = $idx;
            elseif (in_array($h, ['condition', 'condtion'], true)) $map['condition'] = $idx;  // sheet has a typo
            elseif ($h === 'notes') $map['notes'] = $idx;
            elseif ($h === 'time') $map['time'] = $idx;
        }
        return $map;
    }

    private function readPrice(array $row, array $cols)
    {
        if (!isset($cols['price'])) return null;
        $raw = $row[$cols['price']] ?? null;
        if ($raw === null || $raw === '') return null;
        if (is_numeric($raw)) return (float) $raw;
        if (preg_match('/-?\d+(\.\d+)?/', (string) $raw, $m)) {
            return (float) $m[0];
        }
        return null;
    }

    /** A row is a "date separator" when col A is a parseable date and most other cells are empty. */
    private function isDateLikelyRow(array $row, array $cols): bool
    {
        // If the price column is populated, it's an item row, not a date row.
        if (isset($cols['price']) && isset($row[$cols['price']])) {
            $p = $row[$cols['price']];
            if (is_numeric($p) && (float) $p > 0) return false;
        }
        // Otherwise treat leading-cell dates as date separators.
        return true;
    }

    /* =========================================================================
     * Date / location parsing
     * ========================================================================= */

    private function parseDate($raw)
    {
        if ($raw === null || $raw === '') return null;
        if (is_numeric($raw)) {
            $f = (float) $raw;
            // Excel serial dates are roughly 20000-80000. Anything bigger than
            // that is probably a weird number misinterpreted (e.g. phone).
            if ($f > 20000 && $f < 80000) {
                try {
                    return PhpDate::excelToDateTimeObject($f)->format('Y-m-d H:i:s');
                } catch (\Throwable $e) { return null; }
            }
            return null;
        }
        $ts = strtotime((string) $raw);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }

    private function sheetNameToDate($sheetName)
    {
        $months = [
            'jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2, 'mar' => 3, 'march' => 3,
            'apr' => 4, 'april' => 4, 'may' => 5, 'jun' => 6, 'june' => 6, 'jul' => 7, 'july' => 7,
            'aug' => 8, 'august' => 8, 'sep' => 9, 'sept' => 9, 'september' => 9,
            'oct' => 10, 'october' => 10, 'nov' => 11, 'november' => 11, 'dec' => 12, 'december' => 12,
        ];
        $lower = strtolower($sheetName);
        $month = null; $year = null;
        foreach ($months as $token => $num) {
            if (preg_match('/\b' . preg_quote($token, '/') . '\b/', $lower)) {
                $month = $num; break;
            }
        }
        if (preg_match('/\b(20\d{2}|2[3-6])\b/', $lower, $m)) {
            $y = (int) $m[1];
            $year = $y < 100 ? 2000 + $y : $y;
        }
        if (!$month || !$year) return null;
        return sprintf('%04d-%02d-01 12:00:00', $year, $month);
    }

    private function shouldSkip($sheetName)
    {
        foreach (self::SKIP_PATTERNS as $re) {
            if (preg_match($re, $sheetName)) return true;
        }
        return false;
    }

    private function resolveLocationFromSheetName($sheetName, array $cache)
    {
        $lower = strtolower($sheetName);
        foreach (self::STORE_HINTS as $hintKey => $tokens) {
            foreach ($tokens as $tok) {
                if (preg_match('/\b' . preg_quote($tok, '/') . '\b/', $lower)) {
                    foreach ($cache as $locName => $locId) {
                        if (str_contains($locName, $hintKey)) return $locId;
                    }
                    // If we know the store but can't find a matching location,
                    // caller will treat this as "skip".
                    return null;
                }
            }
        }
        // "InstoreSALES.JAN2024" / "In Store Sales Feb 2024" don't say which store —
        // default to Hollywood (the original in-store location pre-Pico).
        if (str_contains($lower, 'in store') || str_contains($lower, 'instore')) {
            foreach ($cache as $locName => $locId) {
                if (str_contains($locName, 'hollywood')) return $locId;
            }
        }
        return null;
    }

    private function buildLocationCache($businessId)
    {
        $out = [];
        foreach (BusinessLocation::where('business_id', $businessId)->get() as $loc) {
            $out[strtolower($loc->name)] = $loc->id;
        }
        return $out;
    }

    /* =========================================================================
     * Walk-in contact + placeholder product bootstrap
     * ========================================================================= */

    private function resolveWalkInContact($businessId)
    {
        // Most setups have a contact tagged is_default=1 for walk-ins.
        $c = DB::table('contacts')
            ->where('business_id', $businessId)
            ->where('is_default', 1)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first();
        if ($c) return $c->id;
        // Fall back to any customer contact — historical rows need SOMETHING
        // to satisfy the NOT NULL FK.
        $c = DB::table('contacts')
            ->where('business_id', $businessId)
            ->whereIn('type', ['customer', 'both'])
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first();
        return $c ? $c->id : null;
    }

    /** Ensure a single 'Legacy Historical Item' product exists + return its id. */
    private function ensurePlaceholderProduct($businessId, $userId)
    {
        $name = 'Legacy Historical Item';
        $existing = DB::table('products')
            ->where('business_id', $businessId)
            ->where('name', $name)
            ->first();
        if ($existing) return $existing->id;

        // Create a minimal product. Most columns are nullable or have defaults.
        return DB::table('products')->insertGetId([
            'business_id' => $businessId,
            'name' => $name,
            'type' => 'single',
            'sku' => 'NIV-LEGACY-HIST',
            'created_by' => $userId,
            'enable_stock' => 0,
            'is_inactive' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Ensure the placeholder product has a default variation. */
    private function ensurePlaceholderVariation($productId)
    {
        $existing = DB::table('variations')
            ->where('product_id', $productId)
            ->orderBy('id')
            ->first();
        if ($existing) return $existing->id;

        // Most ERPs require a variation_location_details row too, but sell_lines
        // only need variation_id. Products with enable_stock=0 don't track VLDs.
        return DB::table('variations')->insertGetId([
            'product_id' => $productId,
            'name' => 'Default',
            'sub_sku' => 'NIV-LEGACY-HIST-0',
            'default_purchase_price' => 0,
            'default_sell_price' => 0,
            'sell_price_inc_tax' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
