<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * One-shot "backfill now" runner. The ICA page writes a request flag
 * (InventoryCheckController@queueSupplierBackfill); this command — scheduled
 * every 5 minutes and runInBackground — notices the flag, deletes it (so it
 * runs exactly once), and kicks off a full-catalog pull for the requested
 * supplier. Lives in the CLI/cron context so there's no web-request timeout.
 * No flag → instant no-op.
 */
class BackfillSupplierIfRequested extends Command
{
    protected $signature = 'supplier-prices:backfill-if-requested';
    protected $description = 'Run a full-catalog supplier pull if the ICA page has queued one.';

    public function handle()
    {
        $flag = storage_path('app/ica-backfill-request.json');
        if (!is_file($flag)) {
            return 0; // nothing queued
        }
        $req = json_decode((string) file_get_contents($flag), true) ?: [];
        @unlink($flag); // one-shot — delete BEFORE running so it can't loop

        $supplier = preg_replace('/[^a-z]/', '', strtolower((string) ($req['supplier'] ?? 'all'))) ?: 'all';
        $businessId = (int) ($req['business_id'] ?? 0);

        $args = ['supplier' => $supplier, '--full' => true];
        if ($businessId > 0) {
            $args['--business-id'] = $businessId;
        }
        $this->info('Backfill requested for ' . $supplier . ' — running full pull.');
        return Artisan::call('supplier-prices:fetch', $args);
    }
}
