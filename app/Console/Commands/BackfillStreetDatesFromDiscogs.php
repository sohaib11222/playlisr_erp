<?php

namespace App\Console\Commands;

use App\Services\DiscogsStreetDateBackfillService;
use Illuminate\Console\Command;

/**
 * Scheduled counterpart to the "Backfill from Discogs" button at
 * /admin/discogs-street-dates. Small daily batch so newly-added,
 * Discogs-linked products pick up a street date on their own — no one has
 * to remember to click the button for every new release.
 *
 * Usage: php artisan discogs:backfill-street-dates [--limit=50] [--commit]
 */
class BackfillStreetDatesFromDiscogs extends Command
{
    protected $signature = 'discogs:backfill-street-dates {--business=1 : business_id} {--limit=50 : max products per run} {--commit : Actually write (default: dry-run)}';

    protected $description = 'Fill blank street dates from the linked Discogs release (dry-run by default).';

    public function handle()
    {
        @set_time_limit(0);

        $businessId = (int) $this->option('business');
        $limit = (int) $this->option('limit');
        $commit = (bool) $this->option('commit');

        $svc = new DiscogsStreetDateBackfillService();
        $result = $svc->run($businessId, $limit, $commit);

        if (empty($result['ok'])) {
            $this->error($result['error'] ?? 'Failed.');
            return 1;
        }

        $this->info(($commit ? 'COMMIT' : 'DRY RUN') . " — checked {$result['checked']}, "
            . ($commit ? 'updated' : 'would update') . " {$result['updated']}, "
            . "no date on Discogs {$result['no_date']}, failed {$result['failed']}.");
        return 0;
    }
}
