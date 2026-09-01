<?php

namespace App\Console\Commands;

use App\Contact;
use App\Transaction;
use App\VariationLocationDetails;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sarah 2026-09-01: zero out on-hand stock for every product ever purchased
 * from a given supplier.
 *
 * Products aren't directly linked to a supplier in this schema (stock
 * UltimatePOS behavior) — the only link is historical, via purchase_lines on
 * purchase transactions. So "products from supplier X" here means: any
 * product with at least one purchase line on a 'purchase' transaction whose
 * contact is X. That's deliberately broad — a product restocked from a
 * different supplier since is still included.
 *
 * Dry-run by default; nothing is written until --commit is passed.
 *
 *   php artisan stock:zero-supplier "Adam Evan"              # dry run
 *   php artisan stock:zero-supplier "Adam Evan" --commit     # actually zero
 */
class ZeroSupplierStock extends Command
{
    protected $signature = 'stock:zero-supplier
                            {name : Supplier name (or supplier_business_name) to match, e.g. "Adam Evan"}
                            {--business=1 : business_id}
                            {--commit : Actually zero the stock (default: dry-run)}';

    protected $description = 'Zero variation_location_details.qty_available for every product ever purchased from the given supplier. Dry-run by default.';

    public function handle()
    {
        $businessId = (int) $this->option('business');
        $commit = (bool) $this->option('commit');
        $name = trim((string) $this->argument('name'));

        $suppliers = Contact::where('business_id', $businessId)
            ->whereIn('type', ['supplier', 'both'])
            ->where(function ($q) use ($name) {
                $q->where('name', 'like', "%{$name}%")
                    ->orWhere('supplier_business_name', 'like', "%{$name}%");
            })
            ->get();

        if ($suppliers->count() === 0) {
            $this->error("No supplier contact matching \"{$name}\" found for business {$businessId}.");
            return 1;
        }
        if ($suppliers->count() > 1) {
            $this->error("Multiple supplier contacts match \"{$name}\" — be more specific:");
            foreach ($suppliers as $s) {
                $this->line("  #{$s->id}  name=[{$s->name}]  supplier_business_name=[{$s->supplier_business_name}]");
            }
            return 1;
        }

        $supplier = $suppliers->first();
        $this->line('Mode:      ' . ($commit ? 'COMMIT (zeroing stock)' : 'DRY RUN (no changes)'));
        $this->line("Supplier:  #{$supplier->id} {$supplier->name}" . ($supplier->supplier_business_name ? " ({$supplier->supplier_business_name})" : ''));
        $this->line(str_repeat('-', 64));

        $purchaseTxIds = Transaction::where('business_id', $businessId)
            ->where('type', 'purchase')
            ->where('contact_id', $supplier->id)
            ->pluck('id');

        if ($purchaseTxIds->isEmpty()) {
            $this->info('No purchase transactions found for this supplier — nothing to do.');
            return 0;
        }

        $pairs = DB::table('purchase_lines')
            ->whereIn('transaction_id', $purchaseTxIds)
            ->select('product_id', 'variation_id')
            ->distinct()
            ->get();

        if ($pairs->isEmpty()) {
            $this->info('Purchase transactions found, but no purchase lines on them — nothing to do.');
            return 0;
        }

        $this->line('Purchase transactions: ' . $purchaseTxIds->count());
        $this->line('Distinct product/variation pairs: ' . $pairs->count());

        $rows = [];
        $totalQtyBefore = 0.0;
        $vldRowsTouched = 0;

        DB::beginTransaction();
        try {
            foreach ($pairs as $pair) {
                $vlds = VariationLocationDetails::where('product_id', $pair->product_id)
                    ->where('variation_id', $pair->variation_id)
                    ->get();

                foreach ($vlds as $vld) {
                    $qty = (float) $vld->qty_available;
                    if ($qty == 0.0) {
                        continue;
                    }
                    $totalQtyBefore += $qty;
                    $vldRowsTouched++;
                    $rows[] = [
                        'product_id' => $pair->product_id,
                        'variation_id' => $pair->variation_id,
                        'location_id' => $vld->location_id,
                        'qty_before' => $qty,
                    ];
                    if ($commit) {
                        $vld->qty_available = 0;
                        $vld->save();
                    }
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

        usort($rows, function ($a, $b) {
            return $b['qty_before'] <=> $a['qty_before'];
        });

        $this->line(str_repeat('-', 64));
        $this->line('Stock rows with nonzero qty: ' . $vldRowsTouched);
        $this->line('Total qty ' . ($commit ? 'zeroed' : 'that WOULD be zeroed') . ': ' . rtrim(rtrim(number_format($totalQtyBefore, 4), '0'), '.'));
        $this->line(str_repeat('-', 64));

        foreach (array_slice($rows, 0, 50) as $r) {
            $this->line(sprintf(
                '  product #%-6d variation #%-6d location #%-3d  qty %s',
                $r['product_id'],
                $r['variation_id'],
                $r['location_id'],
                rtrim(rtrim(number_format($r['qty_before'], 4), '0'), '.')
            ));
        }
        if (count($rows) > 50) {
            $this->line('  ... and ' . (count($rows) - 50) . ' more');
        }

        $this->line('');
        if ($commit) {
            $this->info('COMMIT complete — stock zeroed.');
        } else {
            $this->info('DRY RUN complete — nothing was written. Re-run with --commit to apply.');
        }

        return 0;
    }
}
