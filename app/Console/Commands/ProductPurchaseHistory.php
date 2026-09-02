<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sarah 2026-09-02: read-only — for given product ids, show every purchase
 * line ever recorded (quantity, supplier, date) plus current
 * variation_location_details rows. Used to find a real, defensible restock
 * quantity for products wrongly zeroed by the bootleg-supplier sweep whose
 * pre-zero qty wasn't captured in that run's console log.
 *
 *   php artisan stock:purchase-history 5147,9199,7203
 */
class ProductPurchaseHistory extends Command
{
    protected $signature = 'stock:purchase-history {ids : Comma-separated product ids}';
    protected $description = 'Read-only: purchase line history + current stock rows for given product ids.';

    public function handle()
    {
        $ids = array_filter(array_map('intval', explode(',', $this->argument('ids'))));

        foreach ($ids as $pid) {
            $this->line(str_repeat('=', 64));
            $this->line("Product #$pid");

            $lines = DB::table('purchase_lines as pl')
                ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
                ->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
                ->where('pl.product_id', $pid)
                ->select('pl.variation_id', 'pl.quantity', 't.transaction_date', 'c.name as supplier', 't.id as tx_id')
                ->orderBy('t.transaction_date')
                ->get();

            $this->line('Purchase lines: ' . $lines->count());
            foreach ($lines as $l) {
                $this->line("  tx#{$l->tx_id}  variation #{$l->variation_id}  qty {$l->quantity}  supplier=[{$l->supplier}]  date={$l->transaction_date}");
            }

            $vlds = DB::table('variation_location_details')
                ->where('product_id', $pid)
                ->get(['id', 'variation_id', 'location_id', 'qty_available']);
            $this->line('Current stock rows: ' . $vlds->count());
            foreach ($vlds as $v) {
                $this->line("  vld#{$v->id}  variation #{$v->variation_id}  location #{$v->location_id}  qty_available {$v->qty_available}");
            }
        }

        return 0;
    }
}
