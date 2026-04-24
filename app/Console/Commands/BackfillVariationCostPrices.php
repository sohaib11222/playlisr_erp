<?php

namespace App\Console\Commands;

use App\Variation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillVariationCostPrices extends Command
{
    /**
     * Fill missing cost price on variations from the most recent purchase line
     * for the same variation_id. "Missing" = default_purchase_price is NULL or 0.
     *
     * Cost basis = most recent recorded purchase (highest purchase_lines.id),
     * which matches what the product edit form would have populated had the
     * purchase been entered through the normal flow.
     *
     * Updates both default_purchase_price (ex-tax) and dpp_inc_tax (inc-tax),
     * pulled from purchase_lines.purchase_price and purchase_price_inc_tax.
     *
     * Dry-run by default; pass --commit to write. Accountant punch list — fixes
     * the "missing cost prices on all items" finding that makes every margin
     * report unreliable.
     */
    protected $signature = 'variations:backfill-cost-prices
                            {--commit : Actually write updates (default is dry-run)}
                            {--limit=0 : Process at most N variations (0 = all)}
                            {--sample=0 : Print N sample rows that would be changed}';

    protected $description = 'Backfill variations.default_purchase_price from most recent purchase_lines entry.';

    public function handle()
    {
        $commit = (bool) $this->option('commit');
        $limit  = (int) $this->option('limit');
        $sample = (int) $this->option('sample');

        $this->info($commit
            ? '** COMMIT mode — changes WILL be written **'
            : '** DRY-RUN mode — no changes written. Pass --commit to apply. **');

        $missingFilter = function ($q) {
            $q->whereNull('default_purchase_price')
              ->orWhere('default_purchase_price', 0);
        };

        $total = Variation::where($missingFilter)->count();
        $this->line("Variations with missing cost price: {$total}");
        if ($total === 0) {
            $this->info('Nothing to do.');
            return 0;
        }

        $query = Variation::where($missingFilter)
            ->orderBy('id')
            ->select(['id', 'product_id', 'sub_sku', 'name', 'default_purchase_price', 'dpp_inc_tax']);

        if ($limit > 0) {
            $query->limit($limit);
        }

        $stats = [
            'found_in_purchases' => 0,
            'no_purchase_history' => 0,
        ];
        $changes = [];

        $query->chunkById(500, function ($variations) use ($commit, &$stats, &$changes) {
            $ids = $variations->pluck('id')->all();

            // Pull the most-recent purchase line per variation_id in one query.
            // MAX(id) groups to the latest; join back to get the prices.
            $latestIds = DB::table('purchase_lines')
                ->select(DB::raw('MAX(id) as id'))
                ->whereIn('variation_id', $ids)
                ->where('quantity', '>', 0)
                ->groupBy('variation_id')
                ->pluck('id');

            $latestByVariation = DB::table('purchase_lines')
                ->whereIn('id', $latestIds)
                ->get(['variation_id', 'purchase_price', 'purchase_price_inc_tax', 'transaction_id'])
                ->keyBy('variation_id');

            foreach ($variations as $v) {
                $row = $latestByVariation->get($v->id);
                if (!$row || (float) $row->purchase_price <= 0) {
                    $stats['no_purchase_history']++;
                    continue;
                }

                $changes[] = [
                    'id'       => $v->id,
                    'sku'      => $v->sub_sku,
                    'to_ex'    => (float) $row->purchase_price,
                    'to_inc'   => (float) $row->purchase_price_inc_tax,
                    'from_tx'  => $row->transaction_id,
                ];
                $stats['found_in_purchases']++;

                if ($commit) {
                    DB::table('variations')
                        ->where('id', $v->id)
                        ->update([
                            'default_purchase_price' => $row->purchase_price,
                            'dpp_inc_tax'            => $row->purchase_price_inc_tax,
                            'updated_at'             => now(),
                        ]);
                }
            }
        });

        $this->line('');
        $this->info("Would update: {$stats['found_in_purchases']}");
        $this->line("  - found a purchase line   : {$stats['found_in_purchases']}");
        $this->line("  - no purchase history     : {$stats['no_purchase_history']} (still missing — needs manual fill or Discogs pass)");

        if ($sample > 0 && !empty($changes)) {
            $this->line('');
            $this->info('Sample of ' . min($sample, count($changes)) . ' proposed changes:');
            $picks = collect($changes)->shuffle()->take($sample);
            foreach ($picks as $c) {
                $this->line(sprintf(
                    '  var#%d  sku=%s  cost(ex)=%.2f  cost(inc)=%.2f  (from tx#%d)',
                    $c['id'], $c['sku'] ?? '-', $c['to_ex'], $c['to_inc'], $c['from_tx']
                ));
            }
        }

        if (!$commit) {
            $this->warn("\nDry-run only. Re-run with --commit to apply.");
        } else {
            $this->info("\nDone.");
        }
        return 0;
    }
}
