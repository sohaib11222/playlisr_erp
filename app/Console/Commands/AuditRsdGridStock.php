<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Product;

/**
 * READ-ONLY audit — cross-reference three distributor RSD order grids
 * (AMS RSD 2026, AMS RSD Black Friday 2024, Alliance RSD 2025 — 846 rows,
 * resources/data/rsd-grid-titles.json) against ERP stock by exact
 * UPC/sku match. Companion to scripts/audit-rsd-grid-titles.js on the
 * website side — this is the ERP source of truth for qty_available;
 * NivessaStockNotifier pushes from here to the website, so any zeroing
 * needs to happen here (not just on the website copy) to stick.
 *
 * No writes. See ZeroOutRsdSoldStock.php for the write pattern.
 *
 * Usage:
 *   php artisan nivessa:audit-rsd-grid-stock
 */
class AuditRsdGridStock extends Command
{
    protected $signature = 'nivessa:audit-rsd-grid-stock';

    protected $description = 'Read-only: cross-reference distributor RSD grids against ERP stock by UPC/sku.';

    public function handle()
    {
        $path = resource_path('data/rsd-grid-titles.json');
        $grid = json_decode(file_get_contents($path), true);
        $this->info('🎯 RSD grid audit (read-only, ERP) — ' . count($grid) . ' grid rows loaded');

        $bySource = [];
        foreach ($grid as $row) {
            $bySource[$row['source']] = ($bySource[$row['source']] ?? 0) + 1;
        }
        foreach ($bySource as $src => $count) {
            $this->line("   {$src}: {$count}");
        }
        $this->line('');

        $upcs = array_values(array_unique(array_column($grid, 'upc')));
        $gridByUpc = [];
        foreach ($grid as $row) {
            if (!isset($gridByUpc[$row['upc']])) $gridByUpc[$row['upc']] = $row;
        }

        $products = Product::whereIn('sku', $upcs)->get(['id', 'sku', 'name']);

        $this->info("Matched " . $products->count() . " product(s) by exact UPC/sku.\n");

        foreach ($products as $product) {
            $rows = DB::table('variation_location_details as vld')
                ->join('variations as v', 'v.id', '=', 'vld.variation_id')
                ->where('v.product_id', $product->id)
                ->select('vld.location_id', 'vld.qty_available')
                ->get();

            $total = $rows->sum('qty_available');
            $g = $gridByUpc[$product->sku] ?? null;

            $this->line("  SKU {$product->sku}  product #{$product->id}  total_qty={$total}");
            $this->line("      erp:   \"{$product->name}\"");
            if ($g) {
                $this->line("      grid:  {$g['artist']} / {$g['title']}  [{$g['source']}]");
            }
            foreach ($rows as $row) {
                $this->line("         location {$row->location_id}: qty_available {$row->qty_available}");
            }
        }

        if ($products->isEmpty()) {
            $this->info('No exact UPC/sku matches found in ERP.');
        }

        $this->line("\n(Read-only audit — no changes made.)");

        return 0;
    }
}
