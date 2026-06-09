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

                // The UMe email body + xlsx TAB #10 carry a calendar of artist
                // moments (tour launches, album anniversaries, birthdays,
                // biopics). Capture them so they surface in the ICA Events
                // section alongside concerts. This is the half the auto-import
                // used to drop — only the manual xlsx upload saved them before.
                $moments = $this->collectUniversalMoments($email, $universal);
                if (!empty($moments)) {
                    $this->info('  parsed ' . count($moments) . ' artist moments (anniversaries / activity)');
                    if (!$dryRun) {
                        $this->saveUniversalAnniversaries($businessId, $moments);
                    } else {
                        $this->line('  (dry-run) not writing artist moments');
                    }
                }
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

    /**
     * Gather the week's artist moments for the Events section from a UMe
     * email: the xlsx "Key Anniversaries + Birthdays" tab plus the body's
     * "Media & Artist Activity" and "Key Anniversaries | Artist Birthdays |
     * Cultural Moments" sections. Records match the shape
     * loadUniversalAnniversaryEvents() reads (artist / event_date /
     * album_or_track / years / moment).
     *
     * @return array<int,array<string,mixed>>
     */
    protected function collectUniversalMoments(array $email, UniversalChartParser $parser): array
    {
        $records = [];

        // (1) xlsx TAB #10 — structured anniversaries
        foreach ($email['attachments'] as $att) {
            if (!preg_match('/\.xlsx?$/i', $att['filename'])) {
                continue;
            }
            try {
                $parsed = $parser->parse($att['storage_path']);
            } catch (\Throwable $e) {
                $this->warn('  could not parse xlsx for anniversaries: ' . $e->getMessage());
                continue;
            }
            foreach (($parsed['anniversaries'] ?? []) as $a) {
                if (!empty($a['artist']) && !empty($a['event_date'])) {
                    $records[] = [
                        'artist' => trim((string) $a['artist']),
                        'album_or_track' => trim((string) ($a['album_or_track'] ?? '')),
                        'years' => $a['years'] ?? null,
                        'event_date' => (string) $a['event_date'],
                        'moment' => trim((string) ($a['moment'] ?? '')),
                        'origin' => 'xlsx',
                    ];
                }
            }
        }

        // (2) body calendar sections
        foreach ($this->parseBodyMoments((string) ($email['body'] ?? '')) as $r) {
            $records[] = $r;
        }

        // Dedupe on artist + date + moment so the xlsx tab and the body text
        // (which overlap on anniversaries) don't double up.
        $seen = [];
        $out = [];
        foreach ($records as $r) {
            $key = mb_strtolower($r['artist'] . '|' . $r['event_date'] . '|' . $r['moment']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $r;
        }

        return $out;
    }

    /**
     * Parse the two dated calendar blocks in the UMe email body into artist
     * moments. Walks the body line-by-line, tracking which section we're in,
     * and only treats "Month Day | …" lines under the activity / anniversaries
     * headers (the "New Releases" block is handled separately as chart picks).
     *
     * @return array<int,array<string,mixed>>
     */
    protected function parseBodyMoments(string $body): array
    {
        if (trim($body) === '') {
            return [];
        }

        $lines = preg_split('/\r?\n/', $body);
        $section = null; // 'activity' | 'anniversaries' | 'new_releases'
        $out = [];

        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '') {
                continue;
            }
            $lower = mb_strtolower($t);

            // Section headers switch which block we're in.
            if (strpos($lower, 'media') !== false && strpos($lower, 'artist activity') !== false) {
                $section = 'activity';
                continue;
            }
            if (strpos($lower, 'key anniversaries') !== false || strpos($lower, 'artist birthdays') !== false) {
                $section = 'anniversaries';
                continue;
            }
            if (strpos($lower, 'new releases') !== false) {
                $section = 'new_releases';
                continue;
            }
            if ($section === null || $section === 'new_releases') {
                continue;
            }

            // Dated line: "Month Day | description"
            if (!preg_match('/^([A-Za-z]{3,9}\.?\s+\d{1,2})\s*\|\s*(.+)$/u', $t, $m)) {
                continue;
            }
            $date = $this->parseMomentDate($m[1]);
            if (!$date) {
                continue;
            }
            $desc = trim($m[2]);
            $rec = $this->classifyMoment($desc, $date, $section);
            if ($rec) {
                $out[] = $rec;
            }
        }

        return $out;
    }

    /**
     * Turn one calendar description into an artist-moment record. Album
     * anniversaries and birthdays parse cleanly; "activity" lines (tours,
     * documentaries, etc.) get a best-effort artist guess — a polluted guess
     * just fails the stock match and is silently hidden, which is fine.
     *
     * @return array<string,mixed>|null
     */
    protected function classifyMoment(string $desc, string $date, string $section): ?array
    {
        // "Artist - Album - 25th Album Anniversary". Separators must have
        // surrounding whitespace so a hyphenated act ("blink-182") stays whole.
        if (preg_match('/^(.+?)\s+[-–—]\s+(.+?)\s+[-–—]\s+(\d{1,3})(?:st|nd|rd|th)\s+(.+)$/u', $desc, $m)) {
            return [
                'artist' => trim($m[1]),
                'album_or_track' => trim($m[2]),
                'years' => (int) $m[3],
                'event_date' => $date,
                'moment' => trim($m[4]),
                'origin' => 'body',
            ];
        }
        // "Artist 65th Birthday"
        if (preg_match('/^(.+?)\s+(\d{1,3})(?:st|nd|rd|th)\s+(Birthday)\b/iu', $desc, $m)) {
            return [
                'artist' => trim($m[1]),
                'album_or_track' => '',
                'years' => (int) $m[2],
                'event_date' => $date,
                'moment' => 'Birthday',
                'origin' => 'body',
            ];
        }

        // Activity / cultural moments — guess the artist as the text before the
        // first descriptor keyword, keep the rest as the moment label.
        $artist = $desc;
        $moment = $desc;
        if (preg_match('/\b(\d{1,3}(?:st|nd|rd|th)|tour|documentary|musical|opens?|launch(?:es)?|co-?headline|biopic|day)\b/iu', $desc, $mm, PREG_OFFSET_CAPTURE)) {
            $cut = $mm[0][1];
            if ($cut > 0) {
                $artist = trim(substr($desc, 0, $cut));
                $moment = trim(substr($desc, $cut));
            }
        }
        // Strip wrapping quotes UMe sometimes adds around titles.
        $artist = trim($artist, " \t\"'“”");
        if ($artist === '') {
            return null;
        }

        return [
            'artist' => $artist,
            'album_or_track' => '',
            'years' => null,
            'event_date' => $date,
            'moment' => $moment !== '' ? $moment : 'artist moment',
            'origin' => 'body',
        ];
    }

    /**
     * Parse a bare "Month Day" (no year in the email) into Y-m-d. Assumes the
     * current year, rolling to next year when the date is well in the past so
     * a late-December email referencing January dates lands correctly.
     */
    protected function parseMomentDate(string $monthDay): ?string
    {
        $monthDay = trim($monthDay);
        if ($monthDay === '') {
            return null;
        }
        try {
            $today = Carbon::today();
            $d = Carbon::parse($monthDay . ' ' . $today->year);
            if ($d->lt($today->copy()->subDays(14))) {
                $d = Carbon::parse($monthDay . ' ' . ($today->year + 1));
            }
            return $d->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Persist artist moments to storage/app/universal-anniversaries-{id}.json
     * — the same file the manual xlsx upload writes and the Events section
     * reads. Atomic write; no DB / migration.
     *
     * @param array<int,array<string,mixed>> $records
     */
    protected function saveUniversalAnniversaries(int $businessId, array $records): void
    {
        if (empty($records)) {
            return;
        }
        $path = storage_path('app/universal-anniversaries-' . $businessId . '.json');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $payload = [
            'business_id' => $businessId,
            'updated_at' => Carbon::now()->toIso8601String(),
            'source' => 'universal_email',
            'anniversaries' => array_values($records),
        ];
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $path);
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
