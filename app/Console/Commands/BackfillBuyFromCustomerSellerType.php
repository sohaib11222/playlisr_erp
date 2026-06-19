<?php

namespace App\Console\Commands;

use App\Contact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillBuyFromCustomerSellerType extends Command
{
    /**
     * Walk-in sellers entered on the buy-from-customer form used to be created
     * as type 'supplier'. Every customer-facing search filters
     * type IN (customer, both), so those sellers were invisible to customer
     * lookup — yet the "mobile already registered" check (no type filter) still
     * flagged them, producing the "can't find them but says they're registered"
     * bug. The controller now creates/upgrades them as 'both'; this backfills
     * the ones already stuck as 'supplier'.
     *
     * Scope is deliberately narrow: only contacts that are BOTH type 'supplier'
     * AND referenced as a seller on a buy_customer_offers row. That excludes
     * real vendors/distributors who were never part of a buy-from-customer.
     *
     * Dry-run by default; pass --commit to write.
     */
    protected $signature = 'contacts:backfill-bfc-seller-type
                            {--commit : Actually write updates (default is dry-run)}
                            {--sample=20 : Print N sample rows that would be changed}';

    protected $description = "Upgrade buy-from-customer sellers stuck as type 'supplier' to 'both' so they show up in customer search.";

    public function handle()
    {
        $commit = (bool) $this->option('commit');
        $sample = (int) $this->option('sample');

        $this->info($commit
            ? '** COMMIT mode — changes WILL be written **'
            : '** DRY-RUN mode — no changes written. Pass --commit to apply. **');

        // Sellers = contacts referenced on a buy_customer_offers row that are
        // still type 'supplier'. Distinct contact_ids keep the count honest
        // when one seller appears across several offers.
        $sellerContactIds = DB::table('buy_customer_offers')
            ->whereNotNull('contact_id')
            ->distinct()
            ->pluck('contact_id');

        if ($sellerContactIds->isEmpty()) {
            $this->info('No buy-from-customer offers reference a contact. Nothing to do.');
            return 0;
        }

        $query = Contact::whereIn('id', $sellerContactIds)
            ->where('type', 'supplier')
            ->orderBy('id');

        $total = (clone $query)->count();
        $this->line("Buy-from-customer sellers still stuck as 'supplier': {$total}");
        if ($total === 0) {
            $this->info('Nothing to do — all sellers are already customer/both.');
            return 0;
        }

        if ($sample > 0) {
            $this->line('');
            $this->info('Sample of ' . min($sample, $total) . ' that would become type=both:');
            foreach ((clone $query)->limit($sample)->get(['id', 'name', 'mobile', 'email', 'business_id']) as $c) {
                $this->line(sprintf(
                    '  contact#%d  %s  mobile=%s  email=%s  (business %d)',
                    $c->id, $c->name ?: '-', $c->mobile ?: '-', $c->email ?: '-', $c->business_id
                ));
            }
        }

        if (!$commit) {
            $this->warn("\nDry-run only. Re-run with --commit to apply.");
            return 0;
        }

        $updated = $query->update(['type' => 'both', 'updated_at' => now()]);
        $this->info("\nUpdated {$updated} contact(s) to type=both. Done.");
        return 0;
    }
}
