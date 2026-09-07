<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY — Sarah asked: beyond the 2025-03-28 batch found by
 * nivessa:audit-rsd-purchase-day, are there other dates where a lot of
 * RSD-grid titles were purchased into ERP together (i.e. other RSD
 * order-grid deliveries), across 2024/2025/2026?
 *
 * 1. Matches every product against the RSD grid (resources/data/
 *    rsd-grid-titles.json, 846 rows — AMS RSD 2026, AMS RSD Black
 *    Friday 2024, Alliance RSD 2025) by leading-zero-normalized UPC
 *    (same method as nivessa:audit-rsd-grid-stock-v2 — the original
 *    exact-string match missed ~3.5x of the real matches).
 * 2. Joins to purchase_lines/transactions to get each match's
 *    purchase date(s).
 * 3. Groups by purchase date, so any date with several RSD-grid
 *    titles purchased together stands out as a likely order-grid
 *    delivery day, not a coincidence.
 * 4. For each such date (min --threshold matches, default 3), lists
 *    the titles and current total qty_available, flagging whether
 *    each is already zeroed.
 *
 * No writes.
 *
 * Usage:
 *   php artisan nivessa:audit-rsd-purchase-batches
 *   php artisan nivessa:audit-rsd-purchase-batches --threshold=5
 */
class AuditRsdPurchaseBatches extends Command
{
    protected $signature = 'nivessa:audit-rsd-purchase-batches {--threshold=3 : Minimum distinct RSD-grid titles purchased on a date to report it}';

    protected $description = 'Read-only: find purchase dates where several RSD-grid titles were purchased together, and current zeroed status.';

    const EXCLUDE_NORMALIZED_UPC = '75678602399'; // known collision, see AuditRsdGridStockV2

    public function handle()
    {
        $threshold = (int) $this->option('threshold');

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

        $matched = [];
        foreach ($products as $p) {
            $norm = ltrim($p->sku, '0');
            if ($norm === '') $norm = '0';
            if (isset($gridByNorm[$norm])) {
                $matched[$p->id] = ['product' => $p, 'grid' => $gridByNorm[$norm]];
            }
        }

        $this->info('Matched ' . count($matched) . ' product(s) to the RSD grid (leading-zero-normalized).');

        if (empty($matched)) {
            $this->error('No matches — nothing to group.');
            return 1;
        }

        $ids = array_keys($matched);
        $lines = DB::table('purchase_lines as pl')
            ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
            ->whereIn('pl.product_id', $ids)
            ->selectRaw('DATE(t.transaction_date) as d, pl.product_id, pl.quantity, t.id as tx_id')
            ->get();

        $this->line("Found {$lines->count()} purchase_lines row(s) for matched products.\n");

        // Group by date -> distinct product_ids purchased that day
        $byDate = [];
        foreach ($lines as $l) {
            $byDate[$l->d][$l->product_id] = true;
        }

        $dateCounts = [];
        foreach ($byDate as $d => $pids) {
            $dateCounts[$d] = count($pids);
        }
        arsort($dateCounts);

        $this->line(str_repeat('=', 70));
        $this->info("Purchase dates with >= {$threshold} distinct RSD-grid titles:\n");

        foreach ($dateCounts as $date => $count) {
            if ($count < $threshold) continue;

            $pids = array_keys($byDate[$date]);
            $this->line(str_repeat('-', 70));
            $this->line("{$date} — {$count} distinct RSD-grid title(s) purchased");

            $nonzeroCount = 0;
            foreach ($pids as $pid) {
                $m = $matched[$pid];
                $p = $m['product'];
                $g = $m['grid'];

                $total = DB::table('variation_location_details as vld')
                    ->join('variations as v', 'v.id', '=', 'vld.variation_id')
                    ->where('v.product_id', $p->id)
                    ->sum('vld.qty_available');

                if ($total > 0) {
                    $nonzeroCount++;
                    $this->line("    ❌ sku={$p->sku}  qty={$total}  \"{$p->name}\"  [{$g['source']}]");
                }
            }
            if ($nonzeroCount === 0) {
                $this->line('    ✅ all zeroed already');
            } else {
                $this->line("    → {$nonzeroCount} of {$count} still showing real stock");
            }
        }

        $this->line("\n(Read-only audit — no changes made.)");
        return 0;
    }
}
