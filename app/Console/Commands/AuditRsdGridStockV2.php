<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Product;

/**
 * READ-ONLY — follow-up to nivessa:audit-rsd-grid-stock (playlisr_erp #100)
 * after nivessa:audit-rsd-purchase-day found titles the original exact-sku
 * pass missed: e.g. grid UPC 711297923612 (Cowboy Junkies / More Acoustic
 * Junk) vs ERP sku 0711297923612 — a UPC-A (12-digit) vs EAN-13 (13-digit,
 * zero-padded) mismatch. String equality on sku never catches that.
 *
 * This pass compares UPCs after stripping ALL leading zeros on both sides,
 * so 12-digit and zero-padded 13-digit codes match. Strictly a superset of
 * the original exact-match pass — anything found there is found here too.
 *
 * No writes.
 *
 * Usage:
 *   php artisan nivessa:audit-rsd-grid-stock-v2
 */
class AuditRsdGridStockV2 extends Command
{
    protected $signature = 'nivessa:audit-rsd-grid-stock-v2';

    protected $description = 'Read-only: RSD grid vs ERP stock, matching UPC/sku with leading zeros stripped.';

    public function handle()
    {
        $path = resource_path('data/rsd-grid-titles.json');
        $grid = json_decode(file_get_contents($path), true);
        $this->info('🎯 RSD grid audit v2 (leading-zero-normalized) — ' . count($grid) . ' grid rows loaded');

        // normalized upc -> grid row (first one wins for display)
        $gridByNorm = [];
        foreach ($grid as $row) {
            $norm = ltrim($row['upc'], '0');
            if ($norm === '') $norm = '0';
            if (!isset($gridByNorm[$norm])) $gridByNorm[$norm] = $row;
        }
        $this->line('   ' . count($gridByNorm) . " distinct normalized UPCs\n");

        // Pull all products with a plausible barcode-shaped sku (all digits,
        // 8-14 chars) — cheaper than pulling the whole catalog.
        $products = DB::table('products')
            ->whereRaw("sku REGEXP '^[0-9]{8,14}$'")
            ->select('id', 'sku', 'name')
            ->get();
        $this->line("Scanning {$products->count()} numeric-sku products…\n");

        $matches = [];
        foreach ($products as $p) {
            $norm = ltrim($p->sku, '0');
            if ($norm === '') $norm = '0';
            if (isset($gridByNorm[$norm])) {
                $matches[] = ['product' => $p, 'grid' => $gridByNorm[$norm]];
            }
        }

        $this->info('Matched ' . count($matches) . " product(s) by leading-zero-normalized UPC.\n");

        $nonzero = [];
        foreach ($matches as $m) {
            $p = $m['product'];
            $g = $m['grid'];
            $total = DB::table('variation_location_details as vld')
                ->join('variations as v', 'v.id', '=', 'vld.variation_id')
                ->where('v.product_id', $p->id)
                ->sum('vld.qty_available');

            $flag = $total > 0 ? '❌ STILL SHOWING STOCK' : '✅ zeroed';
            $this->line("  product #{$p->id}  sku={$p->sku}  total_qty={$total}  {$flag}");
            $this->line("      erp:   \"{$p->name}\"");
            $this->line("      grid:  {$g['artist']} / {$g['title']}  [{$g['source']}]");

            if ($total > 0) $nonzero[] = ['sku' => $p->sku, 'id' => $p->id, 'qty' => $total, 'name' => $p->name, 'grid' => $g];
        }

        $this->line("\n" . str_repeat('=', 70));
        $this->info(count($nonzero) . ' product(s) still showing real ERP stock:');
        foreach ($nonzero as $n) {
            $this->line("  '{$n['sku']}', // qty={$n['qty']} — {$n['name']} — {$n['grid']['artist']} / {$n['grid']['title']} [{$n['grid']['source']}]");
        }

        $this->line("\n(Read-only audit — no changes made.)");
        return 0;
    }
}
