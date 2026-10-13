<?php

namespace App\Console\Commands;

use App\BusinessLocation;
use App\Services\Reorder\AmsOrderWatcher;
use App\Services\ReorderService;
use Illuminate\Console\Command;

/**
 * Marks AMS orders as ordered on the Weekly Reorder page automatically
 * (Sarah 10/13: "can we automate Mark as ordered?"). Reads My Web Orders
 * with the price-pull login; each new order is recorded once.
 */
class ReorderSyncAmsOrders extends Command
{
    protected $signature = 'reorder:sync-ams-orders {--days=14}';
    protected $description = 'Record new AMS web orders as ordered on the Weekly Reorder page';

    // AMS account -> store (131715 = Store #2 = Hollywood, Sarah 9/24).
    const ACCOUNTS = ['131715' => 'hollywood', '131507' => 'pico'];

    public function handle(ReorderService $svc)
    {
        $business_id = (int) \App\Business::orderBy('id')->value('id');
        $orders = (new AmsOrderWatcher())->recentOrders((int) $this->option('days'));
        $recorded = 0;
        foreach ($orders as $o) {
            // Started 10/13; earlier orders were recorded by hand (or arrived already).
            if ($o['at'] < '2026-10-13 00:00:00') continue;
            $store = self::ACCOUNTS[$o['account']] ?? null;
            $loc = $store ? BusinessLocation::where('business_id', $business_id)->where('name', 'like', '%' . $store . '%')->value('id') : null;
            if (!$loc) continue;
            $lines = array_map(function ($l) { return ['upc' => $l['ean'], 'qty' => $l['qty'], 'title' => trim($l['artist'] . ' / ' . $l['title'], ' /')]; }, $o['lines']);
            $recorded += $svc->recordAutoOrder($business_id, (int) $loc, 'ams:' . $o['id'], $o['at'],
                'AMS order "' . $o['ref'] . '" #' . $o['id'], $lines);
        }
        $this->info(count($orders) . ' AMS orders seen, ' . $recorded . ' recorded.');
    }
}
