<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill for contacts.shop_locations / contacts.visit_count from
 * existing sell history. Going forward these are kept in sync incrementally
 * by SellPosController@updateCustomerLoyalty on each finalized sale — this
 * command only needs to run once (or after a bulk historical-data import).
 *
 * Batches the aggregate query itself (not just the update loop) so a store
 * with a very large "Walk-In"/anonymous-customer transaction history
 * doesn't have to hold the whole GROUP_CONCAT result in memory at once —
 * each batch is its own bounded query.
 *
 * Usage:
 *   php artisan contacts:backfill-shop-visits
 *   php artisan contacts:backfill-shop-visits --business=1
 */
class BackfillContactShopVisits extends Command
{
    protected $signature = 'contacts:backfill-shop-visits {--business= : business_id (defaults to the first business)}';
    protected $description = 'Backfill contacts.shop_locations and contacts.visit_count from sell transaction history.';

    public function handle()
    {
        $businessId = (int) $this->option('business');
        if ($businessId <= 0) {
            $businessId = (int) DB::table('business')->orderBy('id')->value('id');
        }
        if ($businessId <= 0) {
            $this->error('No business found.');
            return 1;
        }

        $this->info("Aggregating sell history for business {$businessId}...");

        // Exclude default/walk-in contacts — same guard as the live per-sale
        // hook (SellPosController::updateCustomerLoyalty). Without it, every
        // anonymous walk-in sale piles onto one contact row: caught this in
        // testing as a customer showing "115,473 visits".
        $rows = DB::select(
            "SELECT t2.contact_id,
                    GROUP_CONCAT(DISTINCT bl.name ORDER BY bl.name SEPARATOR ', ') as shop_locations,
                    COUNT(DISTINCT t2.id) as visit_count
             FROM transactions t2
             INNER JOIN contacts c ON c.id = t2.contact_id AND c.is_default = 0
             LEFT JOIN business_locations bl ON bl.id = t2.location_id
             WHERE t2.business_id = ? AND t2.type = 'sell' AND t2.status = 'final'
             GROUP BY t2.contact_id",
            [$businessId]
        );

        // Clear any default/walk-in contact that a previous (buggy) run of
        // this command already wrote an inflated value onto.
        DB::table('contacts')->where('business_id', $businessId)->where('is_default', 1)
            ->update(['shop_locations' => null, 'visit_count' => 0]);

        $this->info(count($rows) . ' contacts have sell history. Writing...');

        $updated = 0;
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::transaction(function () use ($chunk, &$updated) {
                foreach ($chunk as $row) {
                    DB::table('contacts')->where('id', $row->contact_id)->update([
                        'shop_locations' => $row->shop_locations,
                        'visit_count' => (int) $row->visit_count,
                    ]);
                    $updated++;
                }
            });
        }

        $this->info("Done. Updated {$updated} contacts.");
        return 0;
    }
}
