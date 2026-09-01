<?php

namespace App\Console\Commands;

use App\Contact;
use App\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sarah 2026-09-01: read-only lookup — for a list of product ids, report
 * whether each one has ANY purchase_line on a purchase transaction from a
 * supplier matching a name pattern (e.g. "Evan" to catch contact #13 "Adam
 * Evan", or a misspelling). Used to settle bootleg-vs-real-copy ambiguity
 * for products a text-matcher flagged as maybe-the-same-title as something
 * in a bootleg vendor's price list: the purchase-history link is ground
 * truth, matched title text is not. Never writes anything.
 *
 *   php artisan stock:check-supplier-link "Evan" 7155,10362,9904,...
 */
class CheckSupplierLink extends Command
{
    protected $signature = 'stock:check-supplier-link
                            {name : Supplier name pattern to match, e.g. "Evan"}
                            {ids : Comma-separated product ids to check}
                            {--business=1 : business_id}';

    protected $description = 'Read-only: report which of the given product ids have a purchase_line from a supplier matching the name pattern.';

    public function handle()
    {
        $businessId = (int) $this->option('business');
        $name = trim((string) $this->argument('name'));
        $ids = array_filter(array_map('intval', explode(',', $this->argument('ids'))));

        $suppliers = Contact::where('business_id', $businessId)
            ->whereIn('type', ['supplier', 'both'])
            ->where(function ($q) use ($name) {
                $q->where('name', 'like', "%{$name}%")
                    ->orWhere('supplier_business_name', 'like', "%{$name}%");
            })
            ->get(['id', 'name', 'supplier_business_name']);

        $this->line('Matched supplier contact(s): ' . $suppliers->map(function ($s) {
            return "#{$s->id} {$s->name}";
        })->implode(', '));

        if ($suppliers->isEmpty()) {
            $this->error('No supplier matched — nothing to check.');
            return 1;
        }

        $supplierIds = $suppliers->pluck('id')->all();

        $purchaseTxIds = Transaction::where('business_id', $businessId)
            ->where('type', 'purchase')
            ->whereIn('contact_id', $supplierIds)
            ->pluck('id');

        $linkedProductIds = DB::table('purchase_lines')
            ->whereIn('transaction_id', $purchaseTxIds)
            ->whereIn('product_id', $ids)
            ->distinct()
            ->pluck('product_id')
            ->map(function ($v) { return (int) $v; })
            ->all();

        $this->line(str_repeat('-', 64));
        $this->line('Checked ' . count($ids) . ' product id(s).');
        $this->line('LINKED to this supplier (real bootleg per purchase history): ' . count($linkedProductIds));
        foreach ($linkedProductIds as $pid) {
            $this->line('  LINKED  #' . $pid);
        }
        $notLinked = array_values(array_diff($ids, $linkedProductIds));
        $this->line('NOT linked to this supplier (' . count($notLinked) . '):');
        foreach ($notLinked as $pid) {
            $this->line('  not-linked  #' . $pid);
        }

        return 0;
    }
}
