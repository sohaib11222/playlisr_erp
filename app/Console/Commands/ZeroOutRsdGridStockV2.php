<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Product;
use App\Services\NivessaStockNotifier;

/**
 * Zero out qty_available (all locations) for the 94 additional RSD-grid
 * titles found by nivessa:audit-rsd-grid-stock-v2 (playlisr_erp #103) —
 * matches missed by the original exact-string-sku pass (#100/#101)
 * because the grid stores UPC-A (12-digit) and ERP stores the
 * zero-padded EAN-13 form (e.g. grid 711297923612 vs ERP sku
 * 0711297923612). SKU list loaded from
 * resources/data/rsd-grid-zero-v2-skus.json (the exact ERP-stored sku
 * strings, so no re-padding needed here).
 *
 * Excludes sku 0075678602399: the grid's normalized UPC for this SKU
 * collides between two different Alliance RSD 2025 rows (OhGeesy /
 * Geezyworld 2 vs. the ERP product which is actually Don Toliver /
 * Heaven or Hell) — looks like a duplicate/typo UPC in Alliance's own
 * sheet, not a real match. Left alone pending manual review.
 *
 * Matches by whereIn()->get() (not first()), since some SKUs are shared
 * by more than one product row (see ZeroOutRsdGridStock).
 *
 * Dry-run by default. --commit writes qty_available = 0 and pushes to
 * the website via NivessaStockNotifier.
 *
 * Usage:
 *   php artisan nivessa:zero-rsd-grid-stock-v2
 *   php artisan nivessa:zero-rsd-grid-stock-v2 --commit
 */
class ZeroOutRsdGridStockV2 extends Command
{
    protected $signature = 'nivessa:zero-rsd-grid-stock-v2 {--commit : Actually write (default: dry-run)}';

    protected $description = 'Zero qty_available for the 94 additional RSD grid titles found by the zero-padding-normalized audit, and push to the website.';

    public function handle()
    {
        $commit = (bool) $this->option('commit');
        $skus = json_decode(file_get_contents(resource_path('data/rsd-grid-zero-v2-skus.json')), true);

        $this->info($commit ? '🟢 COMMIT — writing changes' : '🔵 DRY RUN — no writes');
        $this->line('Targeting ' . count($skus) . " SKU(s).\n");

        $products = Product::whereIn('sku', $skus)->get();
        $foundSkus = $products->pluck('sku')->unique();
        $missing = collect($skus)->diff($foundSkus);

        $pushIds = [];

        foreach ($products as $product) {
            $rows = DB::table('variation_location_details as vld')
                ->join('variations as v', 'v.id', '=', 'vld.variation_id')
                ->where('v.product_id', $product->id)
                ->select('vld.id', 'vld.location_id', 'vld.qty_available')
                ->get();

            $this->line("  SKU {$product->sku} — product #{$product->id} \"{$product->name}\"");
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

        if ($missing->isNotEmpty()) {
            $this->error("\n  " . $missing->count() . ' SKU(s) not found in ERP: ' . $missing->implode(', '));
        }

        if ($commit && !empty($pushIds)) {
            (new NivessaStockNotifier())->push($pushIds);
            $this->info("\n✅ Zeroed " . count($pushIds) . ' product row(s) and pushed to the website.');
        } elseif (!$commit) {
            $this->info("\nRe-run with --commit to write and push.");
        } else {
            $this->info("\nNothing to commit — no matching products found.");
        }

        return 0;
    }
}
