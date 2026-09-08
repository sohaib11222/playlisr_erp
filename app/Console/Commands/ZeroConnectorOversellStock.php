<?php

namespace App\Console\Commands;

use App\VariationLocationDetails;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One-off backfill for the pre-existing oversell gap in the website-order
 * connector path (Modules/Connector/Http/Controllers/Api/SellController):
 * unlike SellPosController, it never floored stock at 0 or notified
 * nivessa.com, so a handful of for-sale products were left sitting at a
 * strictly negative qty_available with the website still showing whatever
 * it cached before the oversell (see e.g. product #54144, the Madonna
 * Confessions Tour listing, stuck at "1 available" while ERP read -1).
 *
 * Deliberately narrow: only touches rows with qty_available < 0 (a real
 * anomaly — nothing sells to negative under normal operation). Products
 * correctly sitting at exactly 0 are untouched; they're already correctly
 * reflected as out of stock and need no correction.
 *
 * Dry-run by default; nothing is written until --commit is passed.
 *
 *   php artisan stock:zero-connector-oversell              # dry run
 *   php artisan stock:zero-connector-oversell --commit     # actually floor + push
 */
class ZeroConnectorOversellStock extends Command
{
    protected $signature = 'stock:zero-connector-oversell
                            {--business=1 : business_id}
                            {--commit : Actually floor the stock and push to the website (default: dry-run)}';

    protected $description = 'Floor variation_location_details.qty_available to 0 for for-sale products currently sitting strictly negative, and push the affected products to nivessa.com. Dry-run by default.';

    public function handle()
    {
        $businessId = (int) $this->option('business');
        $commit = (bool) $this->option('commit');

        $this->line('Mode:      ' . ($commit ? 'COMMIT (flooring stock + pushing to website)' : 'DRY RUN (no changes)'));
        $this->line("Business:  #{$businessId}");
        $this->line(str_repeat('-', 64));

        $vlds = VariationLocationDetails::query()
            ->join('variations as v', function ($j) {
                $j->on('v.id', '=', 'variation_location_details.variation_id')->whereNull('v.deleted_at');
            })
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('p.business_id', $businessId)
            ->where('p.is_inactive', 0)
            ->where('p.not_for_selling', 0)
            ->where('variation_location_details.qty_available', '<', 0)
            ->select('variation_location_details.*', 'p.sku as p_sku', 'p.name as p_name')
            ->get();

        if ($vlds->isEmpty()) {
            $this->info('No for-sale products with strictly negative stock — nothing to do.');
            return 0;
        }

        $this->line('Negative stock rows found: ' . $vlds->count());

        $rows = [];
        $snapshotRows = [];
        $logRows = [];
        $touchedProductIds = [];
        $totalOverBy = 0.0;

        DB::beginTransaction();
        try {
            foreach ($vlds as $vld) {
                $qty = (float) $vld->qty_available;
                $overBy = abs($qty);
                $totalOverBy += $overBy;
                $touchedProductIds[(int) $vld->product_id] = true;

                $rows[] = [
                    'product_id'   => $vld->product_id,
                    'sku'          => $vld->p_sku,
                    'name'         => $vld->p_name,
                    'variation_id' => $vld->variation_id,
                    'location_id'  => $vld->location_id,
                    'qty_before'   => $qty,
                ];

                if ($commit) {
                    $snapshotRows[] = ['id' => $vld->id, 'qty_available' => $qty];
                    $logRows[] = [
                        'at'           => now()->toDateTimeString(),
                        'product_id'   => $vld->product_id,
                        'variation_id' => $vld->variation_id,
                        'location_id'  => $vld->location_id,
                        'sold_over_by' => $overBy,
                        'source'       => 'bulk_connector_oversell_backfill',
                    ];
                    $vld->qty_available = 0;
                    $vld->save();
                }
            }

            if ($commit) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Failed: ' . $e->getMessage());
            return 1;
        }

        if ($commit && !empty($logRows)) {
            $file = 'oversell-adjustments-' . now()->format('Y-m') . '.jsonl';
            foreach ($logRows as $logRow) {
                Storage::disk('local')->append($file, json_encode($logRow));
            }
        }

        if ($commit && !empty($snapshotRows)) {
            $timestamp = now()->format('Y-m-d_His');
            $snapshotKey = "zero-connector-oversell-stock-{$timestamp}";
            Storage::disk('local')->put(
                "admin-snapshots/{$snapshotKey}.json",
                json_encode([
                    'timestamp'   => now()->toDateTimeString(),
                    'action'      => 'zero-connector-oversell-stock',
                    'business_id' => $businessId,
                    'rows'        => $snapshotRows,
                ], JSON_PRETTY_PRINT)
            );
            $this->line("Snapshot: {$snapshotKey} — undo at /admin/admin-action-history.");
        }

        if ($commit && !empty($touchedProductIds)) {
            try {
                $notifier = new \App\Services\NivessaStockNotifier();
                // force=true: this is a deliberate human correction of stock stuck
                // negative from a real bug, not a routine sale push — same case the
                // NivessaStockNotifier::push() docblock calls out force for (mirrors
                // "Zero Stock (Instant)"). Without it, any of these products with a
                // website sale in the last 48h would have this push silently
                // swallowed by the website's own Website Order guard.
                foreach (array_chunk(array_keys($touchedProductIds), 100) as $chunk) {
                    $notifier->push($chunk, true);
                }
                $this->line('Pushed ' . count($touchedProductIds) . ' product(s) to the website (forced).');
            } catch (\Throwable $pushEx) {
                $this->error('Website push failed: ' . $pushEx->getMessage());
            }
        }

        $this->line(str_repeat('-', 64));
        $this->line('Products touched: ' . count($touchedProductIds));
        $this->line('Total qty ' . ($commit ? 'floored' : 'that WOULD be floored') . ': ' . rtrim(rtrim(number_format($totalOverBy, 4), '0'), '.'));
        $this->line(str_repeat('-', 64));

        foreach ($rows as $r) {
            $this->line(sprintf(
                '  product #%-6d sku=%-16s qty %s  "%s"',
                $r['product_id'],
                $r['sku'],
                rtrim(rtrim(number_format($r['qty_before'], 4), '0'), '.'),
                mb_strimwidth((string) $r['name'], 0, 50, '...')
            ));
        }

        $this->line('');
        if ($commit) {
            $this->info('COMMIT complete — negative stock floored to 0 and pushed to nivessa.com.');
        } else {
            $this->info('DRY RUN complete — nothing was written. Re-run with --commit to apply.');
        }

        return 0;
    }
}
