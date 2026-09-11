<?php

namespace App\Console\Commands;

use App\Services\DiscogsGenreBackfillService;
use Illuminate\Console\Command;

/**
 * One-shot CLI report: which Discogs genre/style names don't match any of
 * Sarah's existing sub-categories, ranked by how often they come up. Same
 * read-only check as the "See what's missing from your genre list" button
 * on /products/name-cleanup, just able to cover a much bigger sample in one
 * run than a browser tab reasonably can. Writes nothing.
 *
 * Usage: php artisan discogs:genre-unmatched-report [--minutes=10]
 */
class ReportUnmatchedGenres extends Command
{
    protected $signature = 'discogs:genre-unmatched-report
                            {--business=1 : business_id}
                            {--minutes=10 : keep scanning for up to this many minutes}
                            {--batch=20 : products per internal batch}';

    protected $description = 'Read-only: tally which Discogs genres/styles are missing from the sub-category taxonomy.';

    public function handle()
    {
        @set_time_limit(0);

        $businessId = (int) $this->option('business');
        $minutes = max(1, (int) $this->option('minutes'));
        $batch = max(1, (int) $this->option('batch'));

        $svc = new DiscogsGenreBackfillService();
        $deadline = time() + ($minutes * 60);

        $afterId = 0;
        $tally = [];
        $totalScanned = 0;
        $totalUnmatched = 0;

        while (time() < $deadline) {
            $result = $svc->tallyUnmatched($businessId, $batch, $afterId);
            if (empty($result['ok'])) {
                $this->error($result['error'] ?? 'Failed.');
                return 1;
            }
            if ($result['scanned'] === 0) {
                break; // reached the end of the id range
            }
            foreach ($result['tally'] as $name => $count) {
                $tally[$name] = ($tally[$name] ?? 0) + $count;
            }
            $totalScanned += $result['scanned'];
            $totalUnmatched += $result['unmatched'];
            $afterId = $result['after_id'];
        }

        arsort($tally);
        $this->info("Scanned {$totalScanned}, {$totalUnmatched} unmatched. Top missing genres:");
        foreach ($tally as $name => $count) {
            $this->line(sprintf('%6d  %s', $count, $name));
        }
        return 0;
    }
}
