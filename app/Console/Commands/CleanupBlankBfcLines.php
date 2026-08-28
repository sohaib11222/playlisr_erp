<?php

namespace App\Console\Commands;

use App\BuyCustomerOfferLine;
use Illuminate\Console\Command;

/**
 * Sarah 2026-08-27: the buy-from-customer form pre-renders 7 blank
 * individual_vinyl rows (quantity 1, no title/link/price) so cashiers don't
 * have to click "Add line" for a typical haul. Before the BuyOfferCalculatorService
 * fix, any of those 7 left untouched still got saved as a real line — so an
 * offer with, say, 3 real items showed "7" everywhere lines are counted
 * (e.g. the Items column on /buy-from-customer/storage-locations).
 *
 * This is a one-time cleanup for offers saved before that fix. It only
 * deletes lines matching the exact untouched-default shape; anything with a
 * title, Discogs link, or median price is left alone regardless of type.
 *
 * DRY RUN BY DEFAULT.
 *
 *   php artisan bfc:cleanup-blank-lines            # dry run
 *   php artisan bfc:cleanup-blank-lines --commit   # actually delete
 */
class CleanupBlankBfcLines extends Command
{
    protected $signature = 'bfc:cleanup-blank-lines
                            {--commit : Actually delete the blank lines (default: dry-run)}';

    protected $description = 'Delete never-filled-in default rows persisted as real buy_customer_offer_lines. Dry-run by default.';

    public function handle()
    {
        $commit = (bool) $this->option('commit');

        $blank = BuyCustomerOfferLine::query()
            ->where('item_type', 'individual_vinyl')
            ->where('quantity', 1)
            ->where(function ($q) {
                $q->whereNull('title')->orWhere('title', '');
            })
            ->where(function ($q) {
                $q->whereNull('discogs_link')->orWhere('discogs_link', '');
            })
            ->where(function ($q) {
                $q->whereNull('discogs_median_price')->orWhere('discogs_median_price', 0);
            });

        $count = $blank->count();
        $offerCount = (clone $blank)->distinct()->count('offer_id');

        $this->info("Found {$count} blank line(s) across {$offerCount} offer(s).");

        if (!$count) {
            return 0;
        }

        if (!$commit) {
            $this->info('Dry run — pass --commit to delete these.');
            return 0;
        }

        $blank->delete();
        $this->info("Deleted {$count} blank line(s).");
        return 0;
    }
}
