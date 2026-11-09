<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill sales that lost a cart row to the POS products[N] index collision
 * (fixed in PR #343, 2026-11-08). On those sales the customer paid the full
 * screen total, but one or more items never saved, so the saved lines add up
 * to less than final_total. This adds one manual line, "Unrecorded item
 * (register glitch)", for the missing pre-tax amount so the sale's items +
 * tax match what was charged. No product / stock is touched.
 *
 * Only plain sales qualify: type=sell, status=final, no discount, no
 * returns, no line-level tax, not imported/API, and the saved
 * total_before_tax equals the saved lines (otherwise something else is off).
 *
 * Dry-run default; --commit writes and saves an undo log
 * (storage/app/backfill-unrecorded-undo-*.json); --undo=<file> reverts.
 */
class BackfillUnrecordedSellLines extends Command
{
    protected $signature = 'nivessa:backfill-unrecorded-lines
                            {--since=2026-01-01 : Only sales on/after this date}
                            {--until= : Only sales before this datetime (blank = now)}
                            {--min=0.50 : Minimum missing pre-tax amount}
                            {--commit : Actually write (default: dry-run)}
                            {--undo= : Undo log file to restore from}
                            {--show=60 : Max rows to print}';

    protected $description = 'Add "Unrecorded item" lines to sales whose saved items are short of what was charged (dry-run default).';

    const LINE_NAME = 'Unrecorded item (register glitch)';

    public function handle()
    {
        if ($this->option('undo')) {
            return $this->undo($this->option('undo'));
        }

        $since = $this->option('since');
        $until = $this->option('until') ?: now()->format('Y-m-d H:i:s');
        $min = (float) $this->option('min');
        $commit = (bool) $this->option('commit');

        $rows = DB::select("
            SELECT t.id, t.invoice_no, t.transaction_date, t.location_id, bl.name AS store,
                   u.first_name AS cashier, t.final_total, t.total_before_tax, t.tax_amount,
                   COALESCE(t.shipping_charges,0) AS shipping, COALESCE(t.packing_charge,0) AS packing,
                   COALESCE(t.round_off_amount,0) AS round_off, COALESCE(tr.amount,0) AS rate_pct,
                   x.lines_pre, x.has_returns, x.has_line_tax, x.has_unrecorded
            FROM transactions t
            JOIN (SELECT transaction_id,
                         SUM(unit_price * quantity) AS lines_pre,
                         MAX(quantity_returned > 0) AS has_returns,
                         MAX(item_tax > 0) AS has_line_tax,
                         MAX(product_name = ?) AS has_unrecorded
                  FROM transaction_sell_lines GROUP BY transaction_id) x ON x.transaction_id = t.id
            LEFT JOIN tax_rates tr ON tr.id = t.tax_id
            LEFT JOIN business_locations bl ON bl.id = t.location_id
            LEFT JOIN users u ON u.id = t.created_by
            WHERE t.type = 'sell' AND t.status = 'final'
              AND t.transaction_date >= ? AND t.transaction_date < ?
              AND (t.discount_amount IS NULL OR t.discount_amount = 0)
              AND COALESCE(t.rp_redeemed_amount,0) = 0
              AND COALESCE(t.is_created_from_api,0) = 0
              AND t.import_source IS NULL
            ORDER BY t.transaction_date", [self::LINE_NAME, $since, $until]);

        $picks = [];
        foreach ($rows as $r) {
            if ($r->has_returns || $r->has_line_tax || $r->has_unrecorded) { continue; }
            if (abs((float) $r->total_before_tax - (float) $r->lines_pre) > 0.01) { continue; }
            $rate = ((float) $r->tax_amount > 0) ? ((float) $r->rate_pct / 100) : 0.0;
            $chargedForItems = (float) $r->final_total - (float) $r->shipping - (float) $r->packing - (float) $r->round_off;
            $missing = round($chargedForItems / (1 + $rate) - (float) $r->lines_pre, 2);
            if ($missing < $min) { continue; }
            $r->missing = $missing;
            $r->rate = $rate;
            $r->charged_for_items = $chargedForItems;
            $picks[] = $r;
        }

        $total = array_sum(array_map(function ($p) { return $p->missing; }, $picks));
        $byMonth = []; $byStore = [];
        foreach ($picks as $p) {
            $m = substr($p->transaction_date, 0, 7);
            $byMonth[$m] = ($byMonth[$m] ?? 0) + 1;
            $byStore[$p->store ?? '?'] = ($byStore[$p->store ?? '?'] ?? 0) + 1;
        }
        $this->line(($commit ? 'COMMIT' : 'DRY RUN') . " since={$since} until={$until} min={$min}");
        $this->line('SALES_TO_FIX: ' . count($picks) . '  MISSING_PRE_TAX_TOTAL: $' . number_format($total, 2));
        foreach ($byMonth as $m => $n) { $this->line("MONTH {$m}: {$n}"); }
        foreach ($byStore as $s => $n) { $this->line("STORE {$s}: {$n}"); }
        $show = (int) $this->option('show');
        foreach (array_slice($picks, 0, $show) as $p) {
            $this->line("  #{$p->invoice_no} {$p->transaction_date} {$p->store} {$p->cashier} | items={$p->lines_pre} charged={$p->final_total} rate=" . ($p->rate * 100) . "% -> add \${$p->missing}");
        }

        if (!$commit) {
            $this->line('Dry run only. Re-run with --commit to write.');
            return 0;
        }

        $undo = [];
        $now = now();
        foreach ($picks as $p) {
            DB::transaction(function () use ($p, &$undo, $now) {
                $lineId = DB::table('transaction_sell_lines')->insertGetId([
                    'transaction_id' => $p->id,
                    'product_id' => null,
                    'variation_id' => null,
                    'product_name' => self::LINE_NAME,
                    'quantity' => 1,
                    'unit_price_before_discount' => $p->missing,
                    'unit_price' => $p->missing,
                    'line_discount_type' => null,
                    'line_discount_amount' => 0,
                    'unit_price_inc_tax' => $p->missing,
                    'item_tax' => 0,
                    'sell_line_note' => 'Backfilled 2026-11-08: item lost by POS row-index bug (PR #343)',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $newTbt = round((float) $p->lines_pre + $p->missing, 4);
                $newTax = round($p->charged_for_items - $newTbt, 4);
                DB::table('transactions')->where('id', $p->id)->update([
                    'total_before_tax' => $newTbt,
                    'tax_amount' => $newTax,
                ]);
                $undo[] = [
                    'transaction_id' => $p->id,
                    'line_id' => $lineId,
                    'old_total_before_tax' => (float) $p->total_before_tax,
                    'old_tax_amount' => (float) $p->tax_amount,
                ];
            });
        }
        $file = 'storage/app/backfill-unrecorded-undo-' . date('Ymd-His') . '.json';
        file_put_contents(base_path($file), json_encode($undo));
        $this->line('WROTE ' . count($undo) . " sales. UNDO_LOG: {$file}");
        return 0;
    }

    private function undo($file)
    {
        $path = file_exists($file) ? $file : base_path($file);
        $undo = json_decode((string) @file_get_contents($path), true);
        if (!is_array($undo)) { $this->error("Can't read undo log {$file}"); return 1; }
        foreach ($undo as $u) {
            DB::transaction(function () use ($u) {
                DB::table('transaction_sell_lines')->where('id', $u['line_id'])
                    ->where('transaction_id', $u['transaction_id'])
                    ->where('product_name', self::LINE_NAME)->delete();
                DB::table('transactions')->where('id', $u['transaction_id'])->update([
                    'total_before_tax' => $u['old_total_before_tax'],
                    'tax_amount' => $u['old_tax_amount'],
                ]);
            });
        }
        $this->line('UNDID ' . count($undo) . ' sales.');
        return 0;
    }
}
