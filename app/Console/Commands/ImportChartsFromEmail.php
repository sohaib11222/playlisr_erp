<?php

namespace App\Console\Commands;

use App\Business;
use App\ChartPick;
use App\ChartPickImport;
use App\Services\ChartEmailFetcher;
use App\Services\ChartPickParser;
use App\Services\UniversalChartParser;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly auto-import of Street Pulse + Universal charts from the
 * sarah@nivessa.com inbox.
 *
 * Usage:
 *   php artisan charts:import-from-email                # last 7 days
 *   php artisan charts:import-from-email --since=14     # last 14 days
 *   php artisan charts:import-from-email --dry-run      # no DB writes
 *
 * Scheduled weekly via Console\Kernel.
 */
class ImportChartsFromEmail extends Command
{
    protected $signature = 'charts:import-from-email {--since=7} {--dry-run} {--business-id=}';

    protected $description = 'Pull weekly Street Pulse / Universal chart emails from Gmail and import chart_picks rows';

    public function handle(
        ChartEmailFetcher $fetcher,
        UniversalChartParser $universal,
        ChartPickParser $textParser
    ) {
        if (!Schema::hasTable('chart_picks') || !Schema::hasTable('chart_pick_imports')) {
            $this->error('chart_picks tables missing — run php artisan migrate first.');
            return 1;
        }

        $since = max(1, (int) $this->option('since'));
        $dryRun = (bool) $this->option('dry-run');

        // Pick the business. If --business-id wasn't passed, default to the
        // first business that has ICA users. This is deliberately simple; if
        // this ever runs in a multi-tenant setup we'll need to thread tenant
        // through properly.
        $businessId = (int) ($this->option('business-id') ?: $this->resolveDefaultBusinessId());
        if (!$businessId) {
            $this->error('Could not resolve business_id. Pass --business-id=N.');
            return 1;
        }

        $this->info("Fetching chart emails since {$since} days ago (business {$businessId}, dry-run=" . ($dryRun ? 'yes' : 'no') . ')');

        $emails = $fetcher->fetchRecent($since);
        if (empty($emails)) {
            $this->warn('No matching emails found (or IMAP not configured). Check config/inventory_check.php email section and INVENTORY_CHECK_IMAP_* env vars.');
            return 0;
        }

        $totalRowsInserted = 0;

        foreach ($emails as $email) {
            $this->line("→ {$email['source']}: {$email['subject']} ({$email['from']}, {$email['date']})");

            $weekOf = $email['date'];
            $rows = [];

            if ($email['source'] === 'universal_top') {
                $rows = $this->parseUniversalEmail($email, $universal);
            } elseif ($email['source'] === 'street_pulse') {
                $rows = $this->parseStreetPulseEmail($email, $textParser);
            }

            if (empty($rows)) {
                $this->warn("  no rows extracted — skipping");
                continue;
            }

            $this->info("  parsed " . count($rows) . ' rows');
            $totalRowsInserted += count($rows);

            if ($dryRun) {
                $this->line('  (dry-run) not writing to DB');
                continue;
            }

            DB::transaction(function () use ($email, $rows, $businessId, $weekOf) {
                $import = ChartPickImport::create([
                    'business_id' => $businessId,
                    'source' => $email['source'],
                    'week_of' => $weekOf,
                    'imported_by' => 0, // system user / cron
                    'row_count' => count($rows),
                    'raw_body' => mb_substr($email['body'], 0, 65535),
                ]);

                ChartPick::where('business_id', $businessId)
                    ->where('source', $email['source'])
                    ->whereDate('week_of', $weekOf)
                    ->delete();

                foreach ($rows as $row) {
                    ChartPick::create([
                        'import_id' => $import->id,
                        'business_id' => $businessId,
                        'source' => $email['source'],
                        'week_of' => $weekOf,
                        'chart_rank' => $row['rank'] ?? null,
                        'artist' => $row['artist'] ?? null,
                        'title' => $row['title'] ?? null,
                        'format' => $row['format'] ?? null,
                        'is_new_release' => !empty($row['is_new_release']),
                    ]);
                }
            });
        }

        $this->info("Done. Inserted {$totalRowsInserted} rows across " . count($emails) . ' emails.');

        return 0;
    }

    protected function parseUniversalEmail(array $email, UniversalChartParser $parser): array
    {
        $rows = [];

        // Universal's meaningful data is in the xlsx attachment(s)
        foreach ($email['attachments'] as $att) {
            if (!preg_match('/\.xlsx?$/i', $att['filename'])) {
                continue;
            }
            $parsed = $parser->parse($att['storage_path']);
            foreach ($parsed['top_200_vinyl'] as $r) {
                $rows[] = array_merge($r, ['is_new_release' => false]);
            }
            foreach ($parsed['top_200_cd'] as $r) {
                $rows[] = array_merge($r, ['is_new_release' => false]);
            }
            foreach ($parsed['deliveries_vinyl'] as $r) {
                $rows[] = array_merge($r, ['is_new_release' => true]);
            }
            foreach ($parsed['deliveries_cd'] as $r) {
                $rows[] = array_merge($r, ['is_new_release' => true]);
            }
        }

        // Supplement with the "New Releases" block from the body text
        $bodyRows = $this->parseUniversalNewReleasesBlock($email['body']);
        foreach ($bodyRows as $r) {
            $rows[] = $r;
        }

        return $rows;
    }

    /**
     * The UMe email body always has a "New Releases – (dates subject to change)"
     * section with lines like: "May 15 | Peter Frampton - Carry The Light".
     * Extract these as rank-less new-release rows for the chart_picks table.
     */
    protected function parseUniversalNewReleasesBlock(string $body): array
    {
        if ($body === '') {
            return [];
        }

        // Locate the block
        $start = stripos($body, 'New Releases');
        if ($start === false) {
            return [];
        }
        $section = substr($body, $start);
        // Stop at next obvious section header or "Thank you" / signature
        $endMarkers = ['Thank you', 'Sincerely', 'Regards'];
        foreach ($endMarkers as $m) {
            $p = stripos($section, $m);
            if ($p !== false) {
                $section = substr($section, 0, $p);
                break;
            }
        }

        $rows = [];
        $lines = preg_split('/\r?\n/', $section);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Pattern: "Month Day | Artist - Title..."
            if (preg_match('/^(?:[A-Za-z]{3,9}\.?\s+\d{1,2})\s*\|\s*(.+?)\s*[-–—]\s*(.+?)$/u', $line, $m)) {
                $rows[] = [
                    'rank' => null,
                    'artist' => trim($m[1]),
                    'title' => trim($m[2]),
                    'format' => null,
                    'is_new_release' => true,
                ];
            }
        }

        return $rows;
    }

    protected function parseStreetPulseEmail(array $email, ChartPickParser $textParser): array
    {
        // Prefer xlsx attachment if present (Street Pulse often attaches)
        foreach ($email['attachments'] as $att) {
            if (preg_match('/\.xlsx?$/i', $att['filename'])) {
                // TODO: wire a StreetPulseXlsxParser once we see the layout.
                // For now we fall through to text parsing on the body.
            }
        }

        $rows = $textParser->parse($email['body'], 'street_pulse');
        return array_map(function ($r) {
            return [
                'rank' => $r['rank'] ?? null,
                'artist' => $r['artist'] ?? null,
                'title' => $r['title'] ?? null,
                'format' => $r['format'] ?? null,
                'is_new_release' => !empty($r['is_new_release']),
            ];
        }, $rows);
    }

    protected function resolveDefaultBusinessId(): int
    {
        try {
            $b = Business::orderBy('id')->first();
            return $b ? (int) $b->id : 0;
        } catch (\Throwable $ignore) {
            return 0;
        }
    }
}
