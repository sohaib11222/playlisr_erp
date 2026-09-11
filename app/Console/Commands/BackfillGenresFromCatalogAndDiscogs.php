<?php

namespace App\Console\Commands;

use App\Services\DiscogsGenreBackfillService;
use Illuminate\Console\Command;

/**
 * Scheduled/CLI counterpart to the "Fill blank genres" button at
 * /products/name-cleanup. Runs continuously for --minutes so it doesn't need
 * a browser tab open — see DiscogsGenreBackfillService for the matching
 * rules (own-catalog same-artist check first, then Discogs, never invents a
 * category, only matches existing ones).
 *
 * Usage: php artisan discogs:backfill-genres [--minutes=13] [--commit]
 */
class BackfillGenresFromCatalogAndDiscogs extends Command
{
    protected $signature = 'discogs:backfill-genres
                            {--business=1 : business_id}
                            {--minutes=13 : keep running for up to this many minutes}
                            {--batch=20 : products per internal batch}
                            {--commit : Actually write (default: dry-run)}';

    protected $description = 'Fill blank genres (products.sub_category_id) from your own catalog or Discogs, continuously for --minutes (dry-run by default).';

    public function handle()
    {
        @set_time_limit(0);

        $businessId = (int) $this->option('business');
        $minutes = max(1, (int) $this->option('minutes'));
        $batch = max(1, (int) $this->option('batch'));
        $commit = (bool) $this->option('commit');

        $svc = new DiscogsGenreBackfillService();
        $deadline = time() + ($minutes * 60);

        $totalChecked = 0;
        $totalFilled = 0;
        $totalFailed = 0;
        $rounds = 0;
        $remaining = null;

        while (time() < $deadline) {
            $result = $svc->run($businessId, $batch, $commit);
            if (empty($result['ok'])) {
                $this->error($result['error'] ?? 'Failed.');
                return 1;
            }

            $rounds++;
            $totalChecked += $result['checked'];
            $totalFilled += $result['filled'];
            $totalFailed += $result['failed'];
            $remaining = $result['remaining'];

            if ($result['checked'] === 0) {
                break; // nothing left eligible
            }

            // Same cooldown heuristic as discogs:backfill-street-dates — a
            // round dominated by failures means Discogs is rate-limiting
            // from some OTHER traffic sharing the same account budget.
            if ($result['failed'] > 0 && $result['failed'] >= $result['checked'] * 0.5) {
                $this->info("round {$rounds}: {$result['failed']}/{$result['checked']} failed — cooling down 60s");
                sleep(60);
            }
        }

        $this->info(($commit ? 'COMMIT' : 'DRY RUN') . " — {$rounds} round(s), checked {$totalChecked}, "
            . ($commit ? 'filled' : 'would fill') . " {$totalFilled}, failed {$totalFailed}"
            . ($remaining !== null ? ", {$remaining} still remaining." : '.'));
        return 0;
    }
}
