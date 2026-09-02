<?php

namespace App\Console\Commands;

use App\Contact;
use App\Product;
use App\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sarah 2026-09-01: read-only — list every product ever purchased from a
 * given supplier (id + name), for building a "here's what we zeroed" report.
 * Never writes anything.
 *
 *   php artisan stock:list-supplier-products "Adam Evan"
 */
class ListSupplierProducts extends Command
{
    protected $signature = 'stock:list-supplier-products
                            {name : Supplier name (or supplier_business_name) to match}
                            {--business=1 : business_id}';

    protected $description = 'Read-only: list product id + name for every product ever purchased from the given supplier.';

    public function handle()
    {
        $businessId = (int) $this->option('business');
        $name = trim((string) $this->argument('name'));

        $suppliers = Contact::where('business_id', $businessId)
            ->whereIn('type', ['supplier', 'both'])
            ->where(function ($q) use ($name) {
                $q->where('name', 'like', "%{$name}%")
                    ->orWhere('supplier_business_name', 'like', "%{$name}%");
            })
            ->get();

        if ($suppliers->count() !== 1) {
            $this->error('Expected exactly 1 supplier match, got ' . $suppliers->count());
            foreach ($suppliers as $s) {
                $this->line("  #{$s->id}  {$s->name}");
            }
            return 1;
        }

        $supplier = $suppliers->first();
        $purchaseTxIds = Transaction::where('business_id', $businessId)
            ->where('type', 'purchase')
            ->where('contact_id', $supplier->id)
            ->pluck('id');

        $productIds = DB::table('purchase_lines')
            ->whereIn('transaction_id', $purchaseTxIds)
            ->distinct()
            ->pluck('product_id');

        $products = Product::whereIn('id', $productIds)->orderBy('name')->get(['id', 'name']);

        $this->line('Supplier: #' . $supplier->id . ' ' . $supplier->name);
        $this->line('Total products: ' . $products->count());
        $this->line(str_repeat('-', 64));
        foreach ($products as $p) {
            $this->line($p->id . '|' . $p->name);
        }

        return 0;
    }
}
