<?php

namespace App\Console\Commands;

use App\Services\DiscogsStreetDateBackfillService;
use Illuminate\Console\Command;

/**
 * Scheduled counterpart to the "Backfill from Discogs" button at
 * /admin/discogs-street-dates.
 *
 * Runs continuously for --minutes (not just one batch) so it actually uses
 * the Discogs rate-limit budget instead of idling most of each cron window —
 * at ~54 req/min pacing, a 13-minute run clears ~700 products, vs. ~200
 * before. Stops early if it runs out of eligible products.
 *
 * Usage: php artisan discogs:backfill-street-dates [--minutes=13] [--commit]
 */
class BackfillStreetDatesFromDiscogs extends Command
{
    protected $signature = 'discogs:backfill-street-dates
                            {--business=1 : business_id}
                            {--minutes=13 : keep running for up to this many minutes}
                            {--batch=250 : products per internal batch}
                            {--commit : Actually write (default: dry-run)}';

    protected $description = 'Fill blank street dates from the linked Discogs release, continuously for --minutes (dry-run by default).';

    public function handle()
    {
        @set_time_limit(0);

        $businessId = (int) $this->option('business');
        $minutes = max(1, (int) $this->option('minutes'));
        $batch = max(1, (int) $this->option('batch'));
        $commit = (bool) $this->option('commit');

        $svc = new DiscogsStreetDateBackfillService();
        $deadline = time() + ($minutes * 60);

        $totalChecked = 0;
        $totalUpdated = 0;
        $totalNoDate = 0;
        $totalFailed = 0;
        $rounds = 0;

        while (time() < $deadline) {
            $result = $svc->run($businessId, $batch, $commit);
            if (empty($result['ok'])) {
                $this->error($result['error'] ?? 'Failed.');
                return 1;
            }

            $rounds++;
            $totalChecked += $result['checked'];
            $totalUpdated += $result['updated'];
            $totalNoDate += $result['no_date'];
            $totalFailed += $result['failed'];

            // Nothing left to check — stop instead of spinning on empty batches.
            if ($result['checked'] === 0) {
                break;
            }

            // A round dominated by failures means Discogs is rate-limiting
            // (verified 2026-08-27: it 429s for the full ~60s window with no
            // Retry-After, and DiscogsService's own retry only waits up to
            // 10s) — almost certainly from the site's OTHER Discogs traffic
            // (order sync, stock sync, image backfill) sharing the same
            // ~60 req/min account budget, not from this job's own pacing.
            // Burning straight into the next round just wastes eligible rows
            // as instant failures, so wait out the window once instead.
            if ($result['failed'] > 0 && $result['failed'] >= $result['checked'] * 0.5) {
                $this->info("round {$rounds}: {$result['failed']}/{$result['checked']} failed — cooling down 60s before retrying");
                sleep(60);
            }
        }

        $this->info(($commit ? 'COMMIT' : 'DRY RUN') . " — {$rounds} round(s), checked {$totalChecked}, "
            . ($commit ? 'updated' : 'would update') . " {$totalUpdated}, "
            . "no date on Discogs {$totalNoDate}, failed {$totalFailed}.");
        return 0;
    }
}
