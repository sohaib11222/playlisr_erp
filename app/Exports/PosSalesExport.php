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
                'Cost',
                'Margin',
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
                    // POS sales: is_direct_sale = 0
                    // Exclude only quotations and proforma invoices, but include all other POS transactions
                    $sells->where('transactions.is_direct_sale', 0)
                          ->where(function($q) {
                              $q->whereNull('transactions.sub_type')
                                ->orWhereNotIn('transactions.sub_type', ['quotation', 'proforma']);
                          });
                } else {
                    // Direct sales: is_direct_sale = 1
                    $sells->where('transactions.is_direct_sale', 1);
                }
            } else {
                // If no filter specified, exclude only quotations and proforma invoices
                $sells->where(function($q) {
                    $q->whereNull('transactions.sub_type')
                      ->orWhereNotIn('transactions.sub_type', ['quotation', 'proforma']);
                });
            }

            // Group by transaction ID (getListSells uses groupBy)
            $sells->groupBy('transactions.id');
            
            // Get transactions (these are the sales transactions)
            $transactions = $sells->get();
            
            // Get all line items for these transactions (these are the actual sold items)
            $transaction_ids = $transactions->pluck('id')->toArray();
            
            if (empty($transaction_ids)) {
                return $sales_array;
            }
            
            // Use Eloquent to get sell lines with relationships - more reliable
            // This matches how the system typically accesses sell lines
            // IMPORTANT: Include all sell lines, even if product is deleted (use product_name from line)
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
                        $q->select('id', 'sub_sku', 'default_purchase_price');
                    }
                ])
                ->get();
        
            if ($sell_lines->isEmpty()) {
                // Return headers even if no data
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
                
                // Skip only if transaction is missing (this is critical)
                if (!$transaction) {
                    continue;
                }

                $transaction_id = $transaction->id;
                $payment_methods = $payment_methods_by_transaction->get($transaction_id, '');
                
                // Use product name from line if product is deleted (product_name is stored in transaction_sell_lines)
                // This ensures we export all items even if products were deleted
                $product = $line->product;
                $product_name = $product ? ($product->name ?? '') : ($line->product_name ?? '');
                
                // Use SKU from line if product is deleted
                $variation = $line->variations; // This is a belongsTo relationship, returns single object
                $sku = '';
                if ($variation && $variation->sub_sku) {
                    $sku = $variation->sub_sku;
                } elseif ($product && $product->sku) {
                    $sku = $product->sku;
                } else {
                    // Fallback to sub_sku from transaction_sell_lines if product is deleted
                    $sku = $line->sub_sku ?? '';
                }
                
                // Use artist from product if available, otherwise from line
                $artist = '';
                if ($product && $product->artist) {
                    $artist = $product->artist;
                } else {
                    $artist = $line->product_artist ?? '';
                }
                
                $qty = (float) ($line->quantity ?? 0);
                $unit_price = (float) ($line->unit_price ?? 0);
                $line_total = $unit_price * $qty;

                // Cost uses purchase_price captured on the sell line at time of
                // sale if present; otherwise the variation's current cost price
                // (accurate post-backfill). Total cost = cost * qty. Margin =
                // line_total - total_cost.
                $unit_cost = (float) ($line->purchase_price ?? 0);
                if ($unit_cost <= 0 && $variation && isset($variation->default_purchase_price)) {
                    $unit_cost = (float) $variation->default_purchase_price;
                }
                $total_cost = $unit_cost * $qty;
                $margin = $line_total - $total_cost;

                $sales_array[] = [
                    $transaction->transaction_date ? date('Y-m-d', strtotime($transaction->transaction_date)) : '',
                    $transaction->invoice_no ?? '',
                    $transaction->contact ? ($transaction->contact->name ?? '') : 'Walk-In Customer',
                    $transaction->location ? ($transaction->location->name ?? '') : '',
                    $product_name,
                    $sku,
                    $artist,
                    $qty,
                    $unit_price,
                    $line_total,
                    round($unit_cost, 2),
                    round($margin, 2),
                    $line->item_tax ?? 0,
                    $line->line_discount_amount ?? 0,
                    $payment_methods,
                    $transaction->payment_status ?? '',
                    $transaction->final_total ?? 0
                ];
            }

            return $sales_array;
        } catch (\Throwable $e) {
            // Return error in export format without logging (to avoid permission issues)
            return [[
                'Error',
                'Export failed: ' . $e->getMessage(),
                'Please contact support if this error persists.'
            ]];
        }
    }
}

