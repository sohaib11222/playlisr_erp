<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\NivessaStockNotifier;

/**
 * Zero out qty_available (all locations) for RSD-grid titles found by
 * matching UPC/sku with leading zeros stripped (grid stores UPC-A
 * 12-digit, ERP stores zero-padded EAN-13 in some cases) — the
 * additional matches nivessa:audit-rsd-grid-stock-v2 (#103) found
 * beyond the original exact-string-sku pass (#100/#101).
 *
 * Re-derives the match set live from the DB the same way the audit
 * command does (regex scan + normalize + compare), rather than
 * matching against a hardcoded SKU list copy-pasted from that audit's
 * log output — a prior version of this command hardcoded the list and
 * silently missed 2 of 94 SKUs whose sku values apparently don't
 * byte-compare equal to what was printed in the log (invisible
 * character or encoding quirk). Deriving live avoids that class of bug
 * entirely.
 *
 * Excludes normalized UPC 75678602399: collides between two different
 * Alliance RSD 2025 grid rows (OhGeesy / Geezyworld 2 vs. the ERP
 * product, which is actually Don Toliver / Heaven or Hell) — looks
 * like a duplicate/typo UPC in Alliance's own sheet, not a real match.
 * Left alone pending manual review.
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

    protected $description = 'Zero qty_available for RSD grid titles matched via leading-zero-normalized UPC, and push to the website.';

    const EXCLUDE_NORMALIZED_UPC = '75678602399'; // collision — see class doc

    public function handle()
    {
        $commit = (bool) $this->option('commit');

        $grid = json_decode(file_get_contents(resource_path('data/rsd-grid-titles.json')), true);
        $gridByNorm = [];
        foreach ($grid as $row) {
            $norm = ltrim($row['upc'], '0');
            if ($norm === '') $norm = '0';
            if (!isset($gridByNorm[$norm])) $gridByNorm[$norm] = $row;
        }
        unset($gridByNorm[self::EXCLUDE_NORMALIZED_UPC]);

        $products = DB::table('products')
            ->whereRaw("sku REGEXP '^[0-9]{8,14}$'")
            ->select('id', 'sku', 'name')
            ->get();

        $targets = [];
        foreach ($products as $p) {
            $norm = ltrim($p->sku, '0');
            if ($norm === '') $norm = '0';
            if (isset($gridByNorm[$norm])) {
                $targets[] = $p;
            }
        }

        $this->info($commit ? '🟢 COMMIT — writing changes' : '🔵 DRY RUN — no writes');
        $this->line('Matched ' . count($targets) . " product(s) by leading-zero-normalized UPC (excluding the known collision).\n");

        $pushIds = [];
        $zeroedAlready = 0;

        foreach ($targets as $product) {
            $rows = DB::table('variation_location_details as vld')
                ->join('variations as v', 'v.id', '=', 'vld.variation_id')
                ->where('v.product_id', $product->id)
                ->select('vld.id', 'vld.location_id', 'vld.qty_available')
                ->get();

            $total = $rows->sum('qty_available');
            if ($total <= 0) { $zeroedAlready++; continue; }

            $this->line("  SKU {$product->sku} — product #{$product->id} \"{$product->name}\"  total_qty={$total}");
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

        $this->line("\n({$zeroedAlready} matched product(s) already at 0 — skipped.)");

        if ($commit && !empty($pushIds)) {
            (new NivessaStockNotifier())->push($pushIds);
            $this->info("\n✅ Zeroed " . count($pushIds) . ' product row(s) and pushed to the website.');
        } elseif (!$commit) {
            $this->info("\nRe-run with --commit to write and push.");
        } else {
            $this->info("\nNothing to commit — all matches already zeroed.");
        }

        return 0;
    }
}
