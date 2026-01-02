<?php

namespace App\Exports;

use App\Transaction;
use App\TransactionSellLine;
use App\Utils\TransactionUtil;
use Maatwebsite\Excel\Concerns\FromArray;
use Illuminate\Support\Facades\DB;

class PosSalesExport implements FromArray
{
    protected $filters;

    public function __construct($filters = [])
    {
        $this->filters = $filters;
    }

    public function array(): array
    {
        \Log::info('PosSalesExport::array() called');
        try {
            // Set headers first
            $sales_array = [[
                'Date',
                'Invoice No',
                'Customer Name',
                'Location',
                'Product Name',
                'SKU',
                'Artist',
                'Quantity',
                'Unit Price',
                'Line Total',
                'Tax',
                'Discount',
                'Payment Method',
                'Payment Status',
                'Total Amount'
            ]];
            
            $business_id = request()->session()->get('user.business_id');
            if (!$business_id) {
                $sales_array[] = ['Error: No business ID found'];
                return $sales_array;
            }
            
            if (!auth()->check()) {
                $sales_array[] = ['Error: User not authenticated'];
                return $sales_array;
            }
            
            $transactionUtil = new TransactionUtil();

            // Build query similar to getListSells
            $sells = $transactionUtil->getListSells($business_id, 'sell');

            // IMPORTANT: Filter by status = 'final' - this is what the list view does
            $sells->where('transactions.status', 'final');

            // Apply filters
            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $sells->whereIn('transactions.location_id', $permitted_locations);
            }

            if (!empty($this->filters['start_date']) && !empty($this->filters['end_date'])) {
                $sells->whereDate('transactions.transaction_date', '>=', $this->filters['start_date'])
                      ->whereDate('transactions.transaction_date', '<=', $this->filters['end_date']);
            }

            if (!empty($this->filters['location_id'])) {
                $sells->where('transactions.location_id', $this->filters['location_id']);
            }

            if (!empty($this->filters['customer_id'])) {
                $sells->where('contacts.id', $this->filters['customer_id']);
            }

            if (!empty($this->filters['payment_status'])) {
                if ($this->filters['payment_status'] != 'overdue') {
                    $sells->where('transactions.payment_status', $this->filters['payment_status']);
                } else {
                    $sells->whereIn('transactions.payment_status', ['due', 'partial'])
                          ->whereNotNull('transactions.pay_term_number')
                          ->whereNotNull('transactions.pay_term_type')
                          ->whereRaw("IF(transactions.pay_term_type='days', DATE_ADD(transactions.transaction_date, INTERVAL transactions.pay_term_number DAY) < CURDATE(), DATE_ADD(transactions.transaction_date, INTERVAL transactions.pay_term_number MONTH) < CURDATE())");
                }
            }

            // Filter by is_direct_sale if specified
            // POS sales have is_direct_sale = 0, direct sales have is_direct_sale = 1
            if (isset($this->filters['is_direct_sale'])) {
                // Convert string "0" to integer 0 for comparison
                $is_direct_sale = $this->filters['is_direct_sale'];
                if ($is_direct_sale == '0' || $is_direct_sale === 0) {
                    // POS sales: is_direct_sale = 0 and sub_type is null (not a quotation or proforma)
                    $sells->where('transactions.is_direct_sale', 0)
                          ->whereNull('transactions.sub_type');
                } else {
                    // Direct sales: is_direct_sale = 1
                    $sells->where('transactions.is_direct_sale', 1);
                }
            }

        // Group by transaction ID (getListSells uses groupBy)
        $sells->groupBy('transactions.id');
        
        // Get transactions (these are the sales transactions)
        $transactions = $sells->get();
        
        \Log::info('PosSalesExport: Found ' . $transactions->count() . ' transactions');
        \Log::info('PosSalesExport: Filters applied: ' . json_encode($this->filters));
        if ($transactions->count() > 0) {
            \Log::info('PosSalesExport: First transaction ID: ' . $transactions->first()->id);
            \Log::info('PosSalesExport: First transaction status: ' . $transactions->first()->status);
            \Log::info('PosSalesExport: First transaction is_direct_sale: ' . $transactions->first()->is_direct_sale);
            \Log::info('PosSalesExport: First transaction sub_type: ' . ($transactions->first()->sub_type ?? 'null'));
        } else {
            // Log the SQL query to see what's being executed
            \Log::info('PosSalesExport: No transactions found. SQL: ' . $sells->toSql());
            \Log::info('PosSalesExport: Bindings: ' . json_encode($sells->getBindings()));
        }

        // Get all line items for these transactions (these are the actual sold items)
        $transaction_ids = $transactions->pluck('id')->toArray();
        
        if (empty($transaction_ids)) {
            \Log::info('PosSalesExport: No transactions found for filters');
            return $sales_array;
        }
        
        \Log::info('PosSalesExport: Querying sell lines for transaction IDs: ' . json_encode($transaction_ids));
        
        // Use Eloquent to get sell lines with relationships - more reliable
        // This matches how the system typically accesses sell lines
        $sell_lines = TransactionSellLine::whereIn('transaction_id', $transaction_ids)
            ->whereNull('parent_sell_line_id') // Only get main line items, not modifiers
            ->with([
                'transaction' => function($q) {
                    $q->select('id', 'transaction_date', 'invoice_no', 'payment_status', 'final_total', 'contact_id', 'location_id');
                },
                'transaction.contact' => function($q) {
                    $q->select('id', 'name');
                },
                'transaction.location' => function($q) {
                    $q->select('id', 'name');
                },
                'product' => function($q) {
                    $q->select('id', 'name', 'sku', 'artist');
                },
                'variations' => function($q) {
                    $q->select('id', 'sub_sku');
                }
            ])
            ->get();
        
        \Log::info('PosSalesExport: Found ' . $sell_lines->count() . ' sell lines using Eloquent');
        
        if ($sell_lines->isEmpty()) {
            // Return headers even if no data
            \Log::info('PosSalesExport: No sell lines found - returning headers only');
            \Log::info('PosSalesExport: Transaction IDs that were queried: ' . json_encode($transaction_ids));
            return $sales_array;
        }

        // Group payment methods by transaction
        $payment_methods_by_transaction = DB::table('transaction_payments')
            ->whereIn('transaction_id', $transaction_ids)
            ->select('transaction_id', 'method')
            ->get()
            ->groupBy('transaction_id')
            ->map(function($payments) {
                return $payments->pluck('method')->unique()->implode(', ');
            });

        foreach ($sell_lines as $line) {
            $transaction = $line->transaction;
            $product = $line->product;
            $variation = $line->variations; // This is a belongsTo relationship, returns single object
            
            // Ensure transaction, product, and variation are not null before accessing properties
            if (!$transaction || !$product) {
                \Log::warning('PosSalesExport: Skipping sell line due to missing transaction or product data. Sell line ID: ' . ($line->id ?? 'N/A'));
                continue; // Skip this line if essential data is missing
            }

            $transaction_id = $transaction->id;
            $payment_methods = $payment_methods_by_transaction->get($transaction_id, '');
            
            $product_name = $product->name ?? '';
            $sku = ($variation && $variation->sub_sku) ? $variation->sub_sku : ($product->sku ?? '');
            $artist = $product->artist ?? '';
            
            $line_total = ($line->unit_price ?? 0) * ($line->quantity ?? 0);

            $sales_array[] = [
                $transaction->transaction_date ? date('Y-m-d', strtotime($transaction->transaction_date)) : '',
                $transaction->invoice_no ?? '',
                $transaction->contact->name ?? '',
                $transaction->location->name ?? '',
                $product_name,
                $sku,
                $artist,
                $line->quantity ?? 0,
                $line->unit_price ?? 0,
                $line_total,
                $line->item_tax ?? 0,
                $line->line_discount_amount ?? 0,
                $payment_methods,
                $transaction->payment_status ?? '',
                $transaction->final_total ?? 0
            ];
        }

        \Log::info('PosSalesExport::array() returning ' . count($sales_array) . ' rows');
        return $sales_array;
        } catch (\Throwable $e) {
            \Log::error('PosSalesExport Error: ' . $e->getMessage());
            \Log::error('Stack trace: ' . $e->getTraceAsString());
            \Log::error('File: ' . $e->getFile() . ' Line: ' . $e->getLine());
            // Return error in export format
            return [[
                'Error',
                $e->getMessage(),
                'File: ' . basename($e->getFile()),
                'Line: ' . $e->getLine()
            ]];
        }
    }
}

