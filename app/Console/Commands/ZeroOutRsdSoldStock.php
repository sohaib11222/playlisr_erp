<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Product;
use App\Services\NivessaStockNotifier;

/**
 * ONE-OFF: zero out qty_available (all locations) for four RSD titles Sarah
 * confirmed are physically sold out, even though the ERP still showed real
 * stock for them. Also pushes the change to the website immediately via
 * NivessaStockNotifier, instead of waiting for the next scheduled sync.
 *
 * SKUs (business_id inferred per-product, not hardcoded):
 *   5060257965168   BEABADOOBEE / LIVE & ACOUSTIC IN LONDON
 *   0075678589683   BRUNO MARS / COLLABORATIONS (RSD)
 *   0840381601607   CAAMP / CAAMP (2LP/REMASTERED/COKE BOTTLE CLEAR VINYL) (RSD)
 *   0602465233087   POST MALONE / POST MALONE TRIBUTE TO NIRVANA
 *
 * Dry-run by default (reports current stock per SKU/location). --commit
 * writes qty_available = 0 and pushes to the website.
 *
 * Usage:
 *   php artisan nivessa:zero-rsd-sold-stock
 *   php artisan nivessa:zero-rsd-sold-stock --commit
 */
class ZeroOutRsdSoldStock extends Command
{
    protected $signature = 'nivessa:zero-rsd-sold-stock {--commit : Actually write (default: dry-run)}';

    protected $description = 'One-off: zero qty_available for 4 confirmed-sold-out RSD SKUs and push to the website.';

    const SKUS = [
        '5060257965168',
        '0075678589683',
        '0840381601607',
        '0602465233087',
    ];

    public function handle()
    {
        $commit = (bool) $this->option('commit');
        $this->info($commit ? '🟢 COMMIT — writing changes' : '🔵 DRY RUN — no writes');

        $pushIds = [];

        foreach (self::SKUS as $sku) {
            $product = Product::where('sku', $sku)->first();
            if (!$product) {
                $this->error("  SKU {$sku}: no product found — skipping.");
                continue;
            }

            $rows = DB::table('variation_location_details as vld')
                ->join('variations as v', 'v.id', '=', 'vld.variation_id')
                ->where('v.product_id', $product->id)
                ->select('vld.id', 'vld.location_id', 'vld.qty_available')
                ->get();

            $this->line("  SKU {$sku} — product #{$product->id} \"{$product->name}\"");
            foreach ($rows as $row) {
                $this->line("      location {$row->location_id}: qty_available {$row->qty_available} → 0");
            }

            if ($commit) {
                DB::table('variation_location_details')
                    ->join('variations as v', 'variation_location_details.variation_id', '=', 'v.id')
                    ->where('v.product_id', $product->id)
                    ->update(['variation_location_details.qty_available' => 0]);
                $pushIds[] = (int) $product->id;
            }
        }

        if ($commit && !empty($pushIds)) {
            (new NivessaStockNotifier())->push($pushIds);
            $this->info('✅ Zeroed ' . count($pushIds) . ' product(s) and pushed to the website.');
        } elseif (!$commit) {
            $this->info('Re-run with --commit to write and push.');
        } else {
            $this->info('Nothing to commit — no matching products found.');
        }

        return 0;
    }
}
