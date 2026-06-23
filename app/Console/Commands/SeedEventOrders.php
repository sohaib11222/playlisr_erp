<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\EventsController;

/**
 * One-time load of the 6/23 Hollywood + Pico distributor order quantities into
 * each event's per-store ordered matrix. Run from deploy (guarded by a flag
 * file) so the data lands without anyone clicking the in-app button.
 */
class SeedEventOrders extends Command
{
    protected $signature = 'events:seed-orders';
    protected $description = 'Load 6/23 distributor orders into event ordered fields';

    public function handle()
    {
        $total = 0;
        foreach (glob(storage_path('app/events-*.json')) ?: [] as $file) {
            if (preg_match('/events-(\d+)\.json$/', $file, $m)) {
                $bid = (int) $m[1];
                $n = EventsController::applyOrderSeed($bid);
                $this->info("business {$bid}: {$n} event(s) updated");
                $total += $n;
            }
        }
        $this->info("events:seed-orders done — {$total} event(s) updated");
        return 0;
    }
}
