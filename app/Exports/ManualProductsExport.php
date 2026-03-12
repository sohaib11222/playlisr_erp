<?php

namespace App\Exports;

use App\TransactionSellLine;
use Maatwebsite\Excel\Concerns\FromArray;
use Illuminate\Support\Facades\DB;

class ManualProductsExport implements FromArray
{
    public function array(): array
    {
        $business_id = request()->session()->get('user.business_id');
        
        // Get manually added products (where product_id is NULL or 0)
        $manual_products = TransactionSellLine::join('transactions', 'transaction_sell_lines.transaction_id', '=', 'transactions.id')
            ->leftJoin('categories', 'transaction_sell_lines.category_id', '=', 'categories.id')
            ->leftJoin('categories as sub_categories', 'transaction_sell_lines.sub_category_id', '=', 'sub_categories.id')
            ->leftJoin('users', 'transactions.created_by', '=', 'users.id')
            ->where('transactions.business_id', $business_id)
            ->where('transactions.type', 'sell')
            ->where('transactions.status', 'final')
            ->where(function($query) {
                $query->whereNull('transaction_sell_lines.product_id')
                      ->orWhere('transaction_sell_lines.product_id', 0);
            })
            ->whereNotNull('transaction_sell_lines.product_name')
            ->select(
                'transaction_sell_lines.product_name',
                'transaction_sell_lines.product_artist',
                'categories.name as category_name',
                'sub_categories.name as sub_category_name',
                'transaction_sell_lines.quantity',
                'transaction_sell_lines.unit_price',
                'transaction_sell_lines.unit_price_inc_tax',
                'transaction_sell_lines.item_tax',
                'transactions.transaction_date',
                'transactions.invoice_no',
                DB::raw("CONCAT(COALESCE(users.first_name, ''), ' ', COALESCE(users.last_name, '')) as created_by_name")
            )
            ->orderBy('transactions.transaction_date', 'desc')
            ->get();

        // Set headers
        $data = [[
            'Product Name',
            'Artist',
            'Category',
            'Sub Category',
            'Quantity',
            'Unit Price',
            'Unit Price (Inc Tax)',
            'Tax',
            'Sale Date',
            'Invoice No',
            'Created By'
        ]];

        // Add data rows
        foreach ($manual_products as $product) {
            $data[] = [
                $product->product_name,
                $product->product_artist ?? '',
                $product->category_name ?? '',
                $product->sub_category_name ?? '',
                $product->quantity,
                $product->unit_price,
                $product->unit_price_inc_tax,
                $product->item_tax,
                $product->transaction_date,
                $product->invoice_no,
                trim($product->created_by_name ?? '') ?: 'N/A',
            ];
        }

        return $data;
    }
}

