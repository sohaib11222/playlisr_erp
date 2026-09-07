<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\NivessaStockNotifier;

/**
 * Zero out qty_available (all locations) for 4 high-confidence RSD
 * titles found by nivessa:scan-rsd-batch-day-purchases (playlisr_erp
 * #114): each was purchased on the SAME transaction (PO) as a
 * confirmed RSD order (a transaction with >= 3 distinct RSD-grid
 * titles), with a tight extra-to-grid ratio — i.e. bundled into an
 * almost-entirely-RSD purchase order, not a big general restock that
 * happened to include a few grid matches (that pattern — tx#2566,
 * 82 extras vs 5 grid titles — was excluded as noise, not a dedicated
 * RSD order).
 *
 *   0075678611575  OHGEESY / GEEZYWORLD 2 — same PO (tx#6405, 2025-03-27)
 *                  as 29 confirmed Alliance RSD 2025 titles.
 *   0075678602399  TOLIVER,DON / HEAVEN OR HELL — same PO as above.
 *                  Previously excluded from the v2 zero-out (#108) as
 *                  a suspected UPC collision with the grid's OhGeesy
 *                  "Geezyworld 2" row; being purchased on the same
 *                  dedicated RSD PO is strong evidence it's a real RSD
 *                  title in its own right, not a data-entry error.
 *   50611108051191 "WE ARE NOT LIVE" — a duplicate ERP product record
 *                  for 13th Floor Elevators' RSD 2026 title (real sku
 *                  5061110805119, already zeroed in #101) with a stray
 *                  extra digit. Same underlying title, just a second
 *                  row.
 *   199957257114   ELIZABETH TAYLOR (CRY MY EYES, VIOLET GLITTER 7",
 *                  COLLECTIBLE) — bundled into two separate confirmed
 *                  RSD 2026 orders (tx#56681, tx#56690, both 2026-04-16).
 *
 * Dry-run by default. --commit writes qty_available = 0 and pushes to
 * the website via NivessaStockNotifier.
 *
 * Usage:
 *   php artisan nivessa:zero-rsd-window-candidates
 *   php artisan nivessa:zero-rsd-window-candidates --commit
 */
class ZeroOutRsdWindowCandidates extends Command
{
    protected $signature = 'nivessa:zero-rsd-window-candidates {--commit : Actually write (default: dry-run)}';

    protected $description = 'Zero qty_available for 4 high-confidence RSD titles found bundled into confirmed RSD purchase orders.';

    const SKUS = [
        '0075678611575',
        '0075678602399',
        '50611108051191',
        '199957257114',
    ];

    public function handle()
    {
        $commit = (bool) $this->option('commit');
        $this->info($commit ? '🟢 COMMIT — writing changes' : '🔵 DRY RUN — no writes');
        $this->line('Targeting ' . count(self::SKUS) . " SKU(s).\n");

        $products = DB::table('products')->whereIn('sku', self::SKUS)->get(['id', 'sku', 'name']);
        $foundSkus = $products->pluck('sku')->unique();
        $missing = collect(self::SKUS)->diff($foundSkus);

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
