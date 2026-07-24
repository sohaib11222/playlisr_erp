<?php

namespace App\Console\Commands;

use App\Http\Controllers\ListingCommissionController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sarah 2026-07-23: auto-apply the listening-party / double-staffed-floor
 * commission split so nobody has to Calculate + Apply it by hand.
 *
 * For each business it scans the last N days for any store where two whitelisted
 * floor staff (Front Desk / Event Lead / Sales Floor Lead) shared the register
 * and applies the 50/50 split to the Commissions page. It only PRE-FILLS the
 * split — paying still needs a human "Mark paid" — and it never touches a day
 * you applied or removed by hand.
 *
 *   php artisan commissions:apply-party-splits            # last 10 days
 *   php artisan commissions:apply-party-splits --days=45  # catch up further
 */
class ApplyPartySplits extends Command
{
    protected $signature = 'commissions:apply-party-splits {--days=10 : how many days back to scan}';
    protected $description = 'Auto-apply the listening-party / shared-floor commission split (pre-fill only; pay is still manual).';

    public function handle()
    {
        $days = (int) $this->option('days');
        $ctrl = app(ListingCommissionController::class);

        $businessIds = DB::table('business_locations')->where('is_active', 1)
            ->distinct()->pluck('business_id');

        $total = 0;
        foreach ($businessIds as $bid) {
            try {
                $n = $ctrl->autoApplyPartySplits((int) $bid, $days);
                $total += $n;
                $this->info("business {$bid}: applied {$n} party split(s)");
            } catch (\Throwable $e) {
                $this->error("business {$bid}: " . $e->getMessage());
                \Log::warning('commissions:apply-party-splits failed for business ' . $bid . ': ' . $e->getMessage());
            }
        }
        $this->info("Done — {$total} party split(s) applied across " . $businessIds->count() . ' business(es).');
        return 0;
    }
}
