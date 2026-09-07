<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * READ-ONLY — follow-up to nivessa:scan-rsd-window-purchases (#111),
 * which found 322 "plausible" (limited-pressing-keyword) candidates
 * across the 5 RSD date windows — too noisy to hand-check (most are
 * just ordinary "Live"/reissue stock that happens to land near an RSD
 * date).
 *
 * Sharper signal: first find which purchase DATES near each RSD window
 * are themselves confirmed RSD delivery days (>= 3 distinct RSD-grid
 * titles purchased that day — the same test nivessa:audit-rsd-
 * purchase-batches uses). Then, for products NOT grid-covered and NOT
 * RSD-named, only flag ones purchased on one of those CONFIRMED batch
 * dates — i.e. literally bundled into the same delivery as known RSD
 * titles, not just "somewhere in a 5-week window."
 *
 * No writes.
 *
 * Usage:
 *   php artisan nivessa:scan-rsd-batch-day-purchases
 */
class ScanRsdBatchDayPurchases extends Command
{
    protected $signature = 'nivessa:scan-rsd-batch-day-purchases {--min-batch=3 : Minimum distinct RSD-grid titles on a date to count it as a confirmed batch day}';

    protected $description = 'Read-only: products bundled into confirmed RSD batch-delivery days, not already grid-covered or RSD-named.';

    const EXCLUDE_NORMALIZED_UPC = '75678602399';

    const WINDOWS = [
        ['label' => 'RSD Spring 2024', 'date' => '2024-04-20'],
        ['label' => 'RSD Black Friday 2024', 'date' => '2024-11-29'],
        ['label' => 'RSD Spring 2025', 'date' => '2025-04-12'],
        ['label' => 'RSD Black Friday 2025', 'date' => '2025-11-28'],
        ['label' => 'RSD Spring 2026', 'date' => '2026-04-18'],
    ];

    public function handle()
    {
        $minBatch = (int) $this->option('min-batch');

        $grid = json_decode(file_get_contents(resource_path('data/rsd-grid-titles.json')), true);
        $gridByNorm = [];
        foreach ($grid as $row) {
            $norm = ltrim($row['upc'], '0');
            if ($norm === '') $norm = '0';
            if (!isset($gridByNorm[$norm])) $gridByNorm[$norm] = $row;
        }
        unset($gridByNorm[self::EXCLUDE_NORMALIZED_UPC]);

        // All numeric-sku products, for grid matching.
        $numericProducts = DB::table('products')
            ->whereRaw("sku REGEXP '^[0-9]{8,14}$'")
            ->select('id', 'sku')
            ->get();
        $gridProductIds = [];
        foreach ($numericProducts as $p) {
            $norm = ltrim($p->sku, '0');
            if ($norm === '') $norm = '0';
            if (isset($gridByNorm[$norm])) $gridProductIds[$p->id] = true;
        }

        $today = Carbon::now();

        foreach (self::WINDOWS as $w) {
            $center = Carbon::parse($w['date']);
            if ($center->gt($today)) {
                $this->line("Skipping {$w['label']} ({$w['date']}) — in the future.");
                continue;
            }
            $start = $center->copy()->subDays(28)->toDateString();
            $end = $center->copy()->addDays(7)->toDateString();

            $this->line(str_repeat('=', 70));
            $this->info("{$w['label']} — window {$start} to {$end}");

            $lines = DB::table('purchase_lines as pl')
                ->join('transactions as t', 't.id', '=', 'pl.transaction_id')
                ->select('pl.product_id', DB::raw('DATE(t.transaction_date) as d'))
                ->whereBetween(DB::raw('DATE(t.transaction_date)'), [$start, $end])
                ->get();

            // Count distinct grid-matched product ids per date.
            $gridCountByDate = [];
            $allProductIdsByDate = [];
            foreach ($lines as $l) {
                $allProductIdsByDate[$l->d][$l->product_id] = true;
                if (isset($gridProductIds[$l->product_id])) {
                    $gridCountByDate[$l->d][$l->product_id] = true;
                }
            }

            $batchDates = [];
            foreach ($gridCountByDate as $d => $ids) {
                if (count($ids) >= $minBatch) $batchDates[$d] = count($ids);
            }

            if (empty($batchDates)) {
                $this->line('  No confirmed RSD batch dates (>= ' . $minBatch . ' grid titles) in this window.');
                continue;
            }

            $this->line('  Confirmed batch date(s): ' . implode(', ', array_map(fn ($d, $c) => "{$d} ({$c} grid titles)", array_keys($batchDates), $batchDates)));

            foreach ($batchDates as $date => $gridCount) {
                $allIds = array_keys($allProductIdsByDate[$date]);
                $bundled = array_diff($allIds, array_keys($gridProductIds));

                if (empty($bundled)) continue;

                $products = DB::table('products')->whereIn('id', $bundled)->select('id', 'sku', 'name')->get();
                $notRsdNamed = $products->filter(fn ($p) => !preg_match('/RSD|RECORD STORE DAY/i', $p->name));

                if ($notRsdNamed->isEmpty()) continue;

                $this->line("\n  {$date}: " . $notRsdNamed->count() . ' non-grid, non-RSD-named product(s) bundled into this confirmed batch delivery:');
                foreach ($notRsdNamed as $p) {
                    $total = DB::table('variation_location_details as vld')
                        ->join('variations as v', 'v.id', '=', 'vld.variation_id')
                        ->where('v.product_id', $p->id)
                        ->sum('vld.qty_available');
                    $flag = $total > 0 ? '❌ qty=' . $total : '✅ zeroed';
                    $this->line("    sku={$p->sku}  \"{$p->name}\"  {$flag}");
                }
            }
        }

        $this->line("\n(Read-only — no changes made.)");
        return 0;
    }
}
