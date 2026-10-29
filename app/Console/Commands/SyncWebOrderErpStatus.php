<?php

namespace App\Console\Commands;

use App\Services\WebOrderErpStatus;
use Illuminate\Console\Command;

/**
 * Copy cancelled / refunded / refund-pending from /website-orders (website
 * and Discogs orders) onto the ERP sales those orders created.
 */
class SyncWebOrderErpStatus extends Command
{
    protected $signature = 'website-orders:sync-erp-status {--discogs-pages=3 : Discogs pages of 200 orders to check}';

    protected $description = 'Mark ERP sales of cancelled / refunded / refund-pending website and Discogs orders.';

    public function handle()
    {
        if (!WebOrderErpStatus::enabled()) {
            $this->error('transactions.web_order_status is missing: run the migration first.');
            return 1;
        }
        [$web, $dg] = WebOrderErpStatus::syncRecent(max(1, (int) $this->option('discogs-pages')));
        $this->info("Updated {$web} website sale(s) and {$dg} Discogs sale(s).");
        return 0;
    }
}
