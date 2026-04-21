<?php

namespace App\Console\Commands;

use App\BusinessLocation;
use App\CustomerWant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Import "Customer Asks" from the Nivessa Backend xlsx into customer_wants.
 *
 * Dry-run by default (prints what would change). Pass --commit to actually
 * write. Each imported row gets:
 *
 *   import_source        = 'nivessa_backend_customer_asks'
 *   import_external_id   = '<sheet>::row<N>'          (dedup key)
 *   notes                = "Imported from Nivessa Backend xlsx · <Requested>"
 *   status               = 'active' unless the 'Ordered?' column indicates
 *                          the ask is already handled ('ordered', 'not
 *                          available', 'yes', etc.), in which case
 *                          'fulfilled' or 'cancelled' as appropriate.
 *
 * Re-running is safe: rows whose (import_source, import_external_id) already
 * exist are skipped.
 *
 * Usage:
 *   php artisan nivessa:import-customer-asks /path/to/Nivessa-Backend.xlsx
 *   php artisan nivessa:import-customer-asks /path/to/Nivessa-Backend.xlsx --commit
 *   php artisan nivessa:import-customer-asks ... --business=1 --sheet='Customer Asks'
 */
class ImportNivessaCustomerAsks extends Command
{
    protected $signature = 'nivessa:import-customer-asks
                            {file : Path to the Nivessa Backend xlsx}
                            {--sheet=Customer Asks : Sheet name to read}
                            {--business=1 : business_id to attach the imports to}
                            {--user=1 : created_by user_id on imported rows}
                            {--commit : Actually write (default is dry-run preview)}
                            {--limit=0 : Cap rows processed (0 = all)}';

    protected $description = 'Dry-run/commit import of the "Customer Asks" sheet into customer_wants.';

    const IMPORT_SOURCE = 'nivessa_backend_customer_asks';

    public function handle()
    {
        $path = $this->argument('file');
        if (!is_file($path)) {
            $this->error("File not found: {$path}");
            return 1;
        }
        $sheetName = $this->option('sheet');
        $businessId = (int) $this->option('business');
        $userId = (int) $this->option('user');
        $commit = (bool) $this->option('commit');
        $limit = (int) $this->option('limit');

        $this->info("Loading {$path} (sheet: {$sheetName})…");
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName($sheetName);
        if (!$sheet) {
            $this->error("Sheet '{$sheetName}' not found. Available: "
                . implode(', ', $spreadsheet->getSheetNames()));
            return 1;
        }

        // Map location abbreviations in the sheet → real location_id.
        // Sheet uses "HW" for Hollywood, "Pico" or "PICO" for Pico.
        $locationMap = $this->buildLocationMap($businessId);
        $this->line("Location map: " . json_encode($locationMap));

        $rows = $sheet->toArray(null, true, false, false);
        if (empty($rows)) {
            $this->warn('Sheet is empty.');
            return 0;
        }

        // Header row → column index.
        $headerRow = $rows[0];
        $cols = [];
        foreach ($headerRow as $idx => $header) {
            $cols[strtolower(trim((string) $header))] = $idx;
        }
        $required = ['artist ', 'album name'];
        // 'artist ' has a trailing space in the source sheet. Normalize.
        $colArtist = $cols['artist'] ?? $cols['artist '] ?? null;
        $colTitle = $cols['album name'] ?? null;
        $colStore = $cols['store location'] ?? null;
        $colRequested = $cols['requested'] ?? null;
        $colOrdered = $cols['ordered?'] ?? $cols['ordered'] ?? null;
        if ($colArtist === null || $colTitle === null) {
            $this->error('Could not find Artist / Album Name columns. Headers: ' . implode(' | ', $headerRow));
            return 1;
        }

        $summary = ['read' => 0, 'skip_empty' => 0, 'skip_dup' => 0, 'insert' => 0, 'by_status' => []];
        $sampleInserts = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $i => $row) {
                if ($i === 0) continue;  // header
                if ($limit > 0 && $summary['read'] >= $limit) break;
                $summary['read']++;

                $artist = trim((string) ($row[$colArtist] ?? ''));
                $title = trim((string) ($row[$colTitle] ?? ''));
                if ($title === '' && $artist === '') {
                    $summary['skip_empty']++;
                    continue;
                }
                if ($title === '') {
                    // customer_wants.title is required; skip rows with no title
                    // rather than inventing one.
                    $summary['skip_empty']++;
                    continue;
                }

                $storeRaw = $colStore !== null ? strtolower(trim((string) $row[$colStore])) : '';
                $locationId = $locationMap[$storeRaw] ?? null;

                $requested = $colRequested !== null ? trim((string) $row[$colRequested]) : '';
                $ordered = $colOrdered !== null ? trim((string) $row[$colOrdered]) : '';
                $status = $this->resolveStatus($ordered);
                $summary['by_status'][$status] = ($summary['by_status'][$status] ?? 0) + 1;

                $externalId = $sheetName . '::row' . ($i + 1);

                // Idempotency check.
                $existing = DB::table('customer_wants')
                    ->where('business_id', $businessId)
                    ->where('import_source', self::IMPORT_SOURCE)
                    ->where('import_external_id', $externalId)
                    ->first();
                if ($existing) {
                    $summary['skip_dup']++;
                    continue;
                }

                $notesParts = ['Imported from Nivessa Backend xlsx (' . $sheetName . ')'];
                if ($requested) $notesParts[] = 'Requested: ' . $requested;
                if ($ordered) $notesParts[] = 'Original status: ' . $ordered;
                $notes = implode(' · ', $notesParts);

                $insertRow = [
                    'business_id' => $businessId,
                    'contact_id' => null,   // no contact info in this sheet
                    'location_id' => $locationId,
                    'artist' => $artist ?: null,
                    'title' => $title,
                    'format' => 'LP',        // sheet doesn't specify; default to LP
                    'phone' => null,
                    'notes' => $notes,
                    'priority' => 'normal',
                    'status' => $status,
                    'created_by' => $userId,
                    'import_source' => self::IMPORT_SOURCE,
                    'import_external_id' => $externalId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if ($commit) {
                    DB::table('customer_wants')->insert($insertRow);
                }
                $summary['insert']++;
                if (count($sampleInserts) < 8) {
                    $sampleInserts[] = $insertRow;
                }
            }

            if ($commit) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Aborted: ' . $e->getMessage());
            return 1;
        }

        $this->newLine();
        $this->info($commit ? '✅ Import complete.' : '🧪 DRY RUN — no rows written. Re-run with --commit to import.');
        $this->line(sprintf(
            "Read: %d · Would insert: %d · Dup-skip: %d · Empty-skip: %d",
            $summary['read'], $summary['insert'], $summary['skip_dup'], $summary['skip_empty']
        ));
        $this->line("Status breakdown: " . json_encode($summary['by_status']));
        if (!empty($sampleInserts)) {
            $this->newLine();
            $this->info('Sample of ' . count($sampleInserts) . ' rows:');
            foreach ($sampleInserts as $r) {
                $this->line(sprintf(
                    "  [%s] %s — %s (%s) @%s · %s",
                    $r['status'], $r['artist'] ?? '—', $r['title'], $r['format'],
                    $r['location_id'] ?? 'no-loc',
                    $r['import_external_id']
                ));
            }
        }
        return 0;
    }

    /** Map sheet abbreviations ('hw', 'pico', etc) → business_locations.id. */
    private function buildLocationMap($businessId)
    {
        $locations = BusinessLocation::where('business_id', $businessId)->get();
        $map = [];
        foreach ($locations as $loc) {
            $name = strtolower($loc->name);
            $map[$name] = $loc->id;
            // Friendly aliases the sheet uses.
            if (str_contains($name, 'hollywood')) {
                $map['hw'] = $loc->id;
                $map['hollywood'] = $loc->id;
            }
            if (str_contains($name, 'pico')) {
                $map['pico'] = $loc->id;
            }
        }
        return $map;
    }

    /**
     * Interpret the "Ordered?" column text → customer_wants.status.
     *   Numeric (e.g. "1") or "yes" / "ordered" → fulfilled
     *   "not available" / "na" / "cancelled" / "discontinued" → cancelled
     *   Anything else (empty, "Approx. 9/13", etc.) → active
     */
    private function resolveStatus($orderedText)
    {
        $t = strtolower(trim($orderedText));
        if ($t === '') return 'active';
        if (in_array($t, ['1', 'yes', 'ordered', 'received', 'fulfilled'], true)) {
            return 'fulfilled';
        }
        if (is_numeric($t) && (float) $t > 0) return 'fulfilled';
        if (str_contains($t, 'not available') || str_contains($t, 'cancel')
            || str_contains($t, 'discontin') || $t === 'n/a' || $t === 'na') {
            return 'cancelled';
        }
        return 'active';
    }
}
