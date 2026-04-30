<?php

namespace App\Http\Controllers;

use App\Brands;
use App\BusinessLocation;
use App\CashRegister;
use App\Category;

use App\Charts\CommonChart;
use App\Contact;

use App\CustomerGroup;
use App\ExpenseCategory;
use App\Product;
use App\ProductStockCache;
use App\PurchaseLine;
use App\Restaurant\ResTable;
use App\SellingPriceGroup;
use App\Transaction;
use App\TransactionPayment;
use App\TransactionSellLine;
use App\TransactionSellLinesPurchaseLines;
use App\Unit;
use App\User;
use App\Utils\BusinessUtil;
use App\Utils\ModuleUtil;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use App\Variation;
use App\VariationLocationDetails;

use DateTime;
use Datatables;
use DB;
use Illuminate\Http\Request;
use App\TaxRate;
use Spatie\Activitylog\Models\Activity;

class ReportController extends Controller
{
    /**
     * All Utils instance.
     *
     */
    protected $transactionUtil;
    protected $productUtil;
    protected $moduleUtil;
    protected $businessUtil;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(TransactionUtil $transactionUtil, ProductUtil $productUtil, ModuleUtil $moduleUtil, BusinessUtil $businessUtil)
    {
        $this->transactionUtil = $transactionUtil;
        $this->productUtil = $productUtil;
        $this->moduleUtil = $moduleUtil;
        $this->businessUtil = $businessUtil;
    }


    public function getStockBySellingPrice(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $start_date = $request->get('start_date');
        $end_date = $request->get('end_date');
        $location_id = $request->get('location_id');

        $day_before_start_date = \Carbon::createFromFormat('Y-m-d', $start_date)->subDay()->format('Y-m-d');

        $opening_stock_by_sp = $this->transactionUtil->getOpeningClosingStock($business_id, $day_before_start_date, $location_id, true, true);

        $closing_stock_by_sp = $this->transactionUtil->getOpeningClosingStock( $business_id, $end_date, $location_id, false, true);

        return [
            'opening_stock_by_sp' => $opening_stock_by_sp,
            'closing_stock_by_sp' => $closing_stock_by_sp
        ];
    }

    /**
     * Shows profit\loss of a business
     *
     * @return \Illuminate\Http\Response
     */
    public function getProfitLoss(Request $request)
    {
        // Aggregated revenue report — admin-only (Sarah 2026-04-28).
        $this->ensureAdminOnlyReportAccess();

        $business_id = $request->session()->get('user.business_id');

        //Return the details in ajax call
        if ($request->ajax()) {
            $start_date = $request->get('start_date');
            $end_date = $request->get('end_date');
            $location_id = $request->get('location_id');

            $data = $this->transactionUtil->getProfitLossDetails($business_id, $location_id, $start_date, $end_date);

            // $data['closing_stock'] = $data['closing_stock'] - $data['total_sell_return'];

            return view('report.partials.profit_loss_details', compact('data'))->render();
        }

        $business_locations = BusinessLocation::forDropdown($business_id, true);
        return view('report.profit_loss', compact('business_locations'));
    }

    /**
     * Shows product report of a business
     *
     * @return \Illuminate\Http\Response
     */
    public function getPurchaseSell(Request $request)
    {
        // Aggregated revenue report — admin-only (Sarah 2026-04-28).
        $this->ensureAdminOnlyReportAccess();

        $business_id = $request->session()->get('user.business_id');

        //Return the details in ajax call
        if ($request->ajax()) {
            $start_date = $request->get('start_date');
            $end_date = $request->get('end_date');

            $location_id = $request->get('location_id');

            $purchase_details = $this->transactionUtil->getPurchaseTotals($business_id, $start_date, $end_date, $location_id);

            $sell_details = $this->transactionUtil->getSellTotals(
                $business_id,
                $start_date,
                $end_date,
                $location_id
            );

            $transaction_types = [
                'purchase_return', 'sell_return'
            ];

            $transaction_totals = $this->transactionUtil->getTransactionTotals(
                $business_id,
                $transaction_types,
                $start_date,
                $end_date,
                $location_id
            );

            $total_purchase_return_inc_tax = $transaction_totals['total_purchase_return_inc_tax'];
            $total_sell_return_inc_tax = $transaction_totals['total_sell_return_inc_tax'];

            $difference = [
                'total' => $sell_details['total_sell_inc_tax'] - $total_sell_return_inc_tax - ($purchase_details['total_purchase_inc_tax'] - $total_purchase_return_inc_tax),
                'due' => $sell_details['invoice_due'] - $purchase_details['purchase_due']
            ];

            return ['purchase' => $purchase_details,
                    'sell' => $sell_details,
                    'total_purchase_return' => $total_purchase_return_inc_tax,
                    'total_sell_return' => $total_sell_return_inc_tax,
                    'difference' => $difference
                ];
        }

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('report.purchase_sell')
                    ->with(compact('business_locations'));
    }

    /**
     * Shows report for Supplier
     *
     * @return \Illuminate\Http\Response
     */
    public function getCustomerSuppliers(Request $request)
    {
        // Open to all staff — supplier/customer rollup, no aggregated sales
        // figures (Sarah 2026-04-28).
        $business_id = $request->session()->get('user.business_id');

        //Return the details in ajax call
        if ($request->ajax()) {
            $contacts = Contact::where('contacts.business_id', $business_id)
                ->join('transactions AS t', 'contacts.id', '=', 't.contact_id')
                ->active()
                ->groupBy('contacts.id')
                ->select(
                    DB::raw("SUM(IF(t.type = 'purchase', final_total, 0)) as total_purchase"),
                    DB::raw("SUM(IF(t.type = 'purchase_return', final_total, 0)) as total_purchase_return"),
                    DB::raw("SUM(IF(t.type = 'sell' AND t.status = 'final', final_total, 0)) as total_invoice"),
                    DB::raw("SUM(IF(t.type = 'purchase', (SELECT SUM(amount) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as purchase_paid"),
                    DB::raw("SUM(IF(t.type = 'sell' AND t.status = 'final', (SELECT SUM(IF(is_return = 1,-1*amount,amount)) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as invoice_received"),
                    DB::raw("SUM(IF(t.type = 'sell_return', (SELECT SUM(amount) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as sell_return_paid"),
                    DB::raw("SUM(IF(t.type = 'purchase_return', (SELECT SUM(amount) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as purchase_return_received"),
                    DB::raw("SUM(IF(t.type = 'sell_return', final_total, 0)) as total_sell_return"),
                    DB::raw("SUM(IF(t.type = 'opening_balance', final_total, 0)) as opening_balance"),
                    DB::raw("SUM(IF(t.type = 'opening_balance', (SELECT SUM(IF(is_return = 1,-1*amount,amount)) FROM transaction_payments WHERE transaction_payments.transaction_id=t.id), 0)) as opening_balance_paid"),
                    DB::raw("SUM(IF(t.type = 'ledger_discount', final_total, 0)) as total_ledger_discount"),
                    'contacts.supplier_business_name',
                    'contacts.name',
                    'contacts.id',
                    'contacts.type as contact_type'
                );
            $permitted_locations = auth()->user()->permitted_locations();
            
            if ($permitted_locations != 'all') {
                $contacts->whereIn('t.location_id', $permitted_locations);
            }

            if (!empty($request->input('customer_group_id'))) {
                $contacts->where('contacts.customer_group_id', $request->input('customer_group_id'));
            }

            if (!empty($request->input('location_id'))) {
                $contacts->where('t.location_id', $request->input('location_id'));
            }

            if (!empty($request->input('contact_id'))) {
                $contacts->where('t.contact_id', $request->input('contact_id'));
            }

            if (!empty($request->input('contact_type'))) {
                $contacts->whereIn('contacts.type', [$request->input('contact_type'), 'both']);
            }

            $start_date = $request->get('start_date');
            $end_date = $request->get('end_date');
            if (!empty($start_date) && !empty($end_date)) {
                $contacts->where('t.transaction_date', '>=', $start_date)
                    ->where('t.transaction_date', '<=', $end_date);
            }


            return Datatables::of($contacts)
                ->editColumn('name', function ($row) {
                    $name = $row->name;
                    if (!empty($row->supplier_business_name)) {
                        $name .= ', ' . $row->supplier_business_name;
                    }
                    return '<a href="' . action('ContactController@show', [$row->id]) . '" target="_blank" class="no-print">' .
                            $name .
                        '</a>';
                })
                ->editColumn(
                    'total_purchase',
                    '<span class="total_purchase" data-orig-value="{{$total_purchase}}">@format_currency($total_purchase)</span>'
                )
                ->editColumn(
                    'total_purchase_return',
                    '<span class="total_purchase_return" data-orig-value="{{$total_purchase_return}}">@format_currency($total_purchase_return)</span>'
                )
                ->editColumn(
                    'total_sell_return',
                    '<span class="total_sell_return" data-orig-value="{{$total_sell_return}}">@format_currency($total_sell_return)</span>'
                )
                ->editColumn(
                    'total_invoice',
                    '<span class="total_invoice" data-orig-value="{{$total_invoice}}">@format_currency($total_invoice)</span>'
                )
                
                ->addColumn('due', function ($row) {
                    $due = ($row->total_invoice - $row->invoice_received - $row->total_sell_return + $row->sell_return_paid) - ($row->total_purchase - $row->total_purchase_return + $row->purchase_return_received - $row->purchase_paid - - $row->total_ledger_discount);

                    if ($row->contact_type == 'supplier') {
                        $due -= $row->opening_balance - $row->opening_balance_paid;
                    } else {
                        $due += $row->opening_balance - $row->opening_balance_paid;
                    }

                    $due_formatted = $this->transactionUtil->num_f($due, true);

                    return '<span class="total_due" data-orig-value="' . $due . '">' . $due_formatted .'</span>';
                })
                ->addColumn(
                    'opening_balance_due',
                    '<span class="opening_balance_due" data-orig-value="{{$opening_balance - $opening_balance_paid}}">@format_currency($opening_balance - $opening_balance_paid)</span>'
                )
                ->removeColumn('supplier_business_name')
                ->removeColumn('invoice_received')
                ->removeColumn('purchase_paid')
                ->removeColumn('id')
                ->filterColumn('name', function ($query, $keyword) {
                    $query->where( function($q) use ($keyword){
                        $q->where('contacts.name', 'like', "%{$keyword}%")
                        ->orWhere('contacts.supplier_business_name', 'like', "%{$keyword}%");
                    });
                })
                ->rawColumns(['total_purchase', 'total_invoice', 'due', 'name', 'total_purchase_return', 'total_sell_return', 'opening_balance_due'])
                ->make(true);
        }

        $customer_group = CustomerGroup::forDropdown($business_id, false, true);
        $types = [
            '' => __('lang_v1.all'),
            'customer' => __('report.customer'),
            'supplier' => __('report.supplier')
        ];

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        $contact_dropdown = Contact::contactDropdown($business_id, false, false);

        return view('report.contact')
        ->with(compact('customer_group', 'types', 'business_locations', 'contact_dropdown'));
    }

    public function testingReport(Request $request)
    {
        if (!auth()->user()->can('stock_report.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');
        $show_manufacturing_data = 0;

        $filters = request()->only(['location_id', 'category_id', 'sub_category_id', 'brand_id', 'unit_id', 'tax_id', 'type', 
            'only_mfg_products', 'active_state',  'not_for_selling', 'repair_model_id', 'product_id', 'active_state']);

        $filters['not_for_selling'] = isset($filters['not_for_selling']) && $filters['not_for_selling'] == 'true' ? 1 : 0;

        $filters['show_manufacturing_data'] = $show_manufacturing_data;

        //Return the details in ajax call
        $for = request()->input('for') == 'view_product' ? 'view_product' :'datatables';

        $products = $this->productUtil->getProductStockDetailsTest($business_id, $filters, $for);
        dd([
            'sql' => $products->toSql(),
            'bindings' => $products->getBindings()
        ]);

    }

    /**
     * Shows product stock report
     *
     * @return \Illuminate\Http\Response
     */
    public function getStockReport(Request $request)
    {
        // Open to all staff — inventory data, not aggregated sales
        // (Sarah 2026-04-28).
        $business_id = $request->session()->get('user.business_id');

        $selling_price_groups = SellingPriceGroup::where('business_id', $business_id)
                                                ->get();
        $allowed_selling_price_group = false;
        foreach ($selling_price_groups as $selling_price_group) {
            if (auth()->user()->can('selling_price_group.' . $selling_price_group->id)) {
                $allowed_selling_price_group = true;
                break;
            }
        }
        if ($this->moduleUtil->isModuleInstalled('Manufacturing') && (auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'manufacturing_module'))) {
            $show_manufacturing_data = 1;
        } else {
            $show_manufacturing_data = 0;
        }
        if ($request->ajax()) {

            $filters = request()->only(['location_id', 'category_id', 'sub_category_id', 'brand_id', 'unit_id', 'tax_id', 'type', 
                'only_mfg_products', 'active_state',  'not_for_selling', 'repair_model_id', 'product_id', 'active_state']);

            $filters['not_for_selling'] = isset($filters['not_for_selling']) && $filters['not_for_selling'] == 'true' ? 1 : 0;

            $filters['show_manufacturing_data'] = $show_manufacturing_data;

            //Return the details in ajax call
            $for = request()->input('for') == 'view_product' ? 'view_product' :'datatables';

            $products = $this->productUtil->getProductStockDetailsCache($business_id, $filters, $for);
            //To show stock details on view product modal
            if ($for == 'view_product' && !empty(request()->input('product_id'))) {
                $product_stock_details = $products;

                return view('product.partials.product_stock_details')->with(compact('product_stock_details'));
            }

            $datatable =  Datatables::of($products)
                ->editColumn('stock', function ($row) {
                    if ($row->enable_stock) {
                        $stock = $row->stock ? $row->stock : 0 ;
                        return  '<span class="current_stock" data-orig-value="' . (float)$stock . '" data-unit="' . $row->unit . '"> ' . $this->transactionUtil->num_f($stock, false, null, true) . '</span>' . ' ' . $row->unit ;
                    } else {
                        return '--';
                    }
                })
                ->editColumn('product', function ($row) {
                    $name = $row->product;
                    return $name;
                })
                ->addColumn('variation', function($row){
                    $variation = '';
                    if ($row->type == 'variable') {
                        $variation .= $row->product_variation . '-' . $row->variation_name;
                    }
                    return $variation;
                })
                ->editColumn('total_sold', function ($row) {
                    $total_sold = 0;
                    if ($row->total_sold) {
                        $total_sold =  (float)$row->total_sold;
                    }

                    return '<span data-is_quantity="true" class="total_sold" data-orig-value="' . $total_sold . '" data-unit="' . $row->unit . '" >' . $this->transactionUtil->num_f($total_sold, false, null, true) . '</span> ' . $row->unit;
                })
                ->editColumn('total_transfered', function ($row) {
                    $total_transfered = 0;
                    if ($row->total_transfered) {
                        $total_transfered =  (float)$row->total_transfered;
                    }

                    return '<span class="total_transfered" data-orig-value="' . $total_transfered . '" data-unit="' . $row->unit . '" >' . $this->transactionUtil->num_f($total_transfered, false, null, true) . '</span> ' . $row->unit;
                })
                
                ->editColumn('total_adjusted', function ($row) {
                    $total_adjusted = 0;
                    if ($row->total_adjusted) {
                        $total_adjusted =  (float)$row->total_adjusted;
                    }

                    return '<span class="total_adjusted" data-orig-value="' . $total_adjusted . '" data-unit="' . $row->unit . '" >' . $this->transactionUtil->num_f($total_adjusted, false, null, true) . '</span> ' . $row->unit;
                })
                ->editColumn('unit_price', function ($row) use ($allowed_selling_price_group) {
                    $html = '';
                    if (auth()->user()->can('access_default_selling_price')) {
                        $html .= $this->transactionUtil->num_f($row->unit_price, true);
                    }

                    if ($allowed_selling_price_group) {
                        $html .= ' <button type="button" class="btn btn-primary btn-xs btn-modal no-print" data-container=".view_modal" data-href="' . action('ProductController@viewGroupPrice', [$row->product_id]) .'">' . __('lang_v1.view_group_prices') . '</button>';
                    }

                    return $html;
                })
                ->editColumn('stock_price', function ($row) {
                    $html = '<span class="total_stock_price" data-orig-value="'
                        . $row->stock_price . '">' .
                        $this->transactionUtil->num_f($row->stock_price, true) . '</span>';

                    return $html;
                })
                ->editColumn('stock_value_by_sale_price', function ($row) {
                    $stock = $row->stock ? $row->stock : 0 ;
                    $unit_selling_price = (float)$row->group_price > 0 ? $row->group_price : $row->unit_price;
                    $stock_price = $stock * $unit_selling_price;
                    return  '<span class="stock_value_by_sale_price" data-orig-value="' . (float)$stock_price . '" > ' . $this->transactionUtil->num_f($stock_price, true) . '</span>';
                })
                ->addColumn('potential_profit', function ($row) {
                    $stock = $row->stock ? $row->stock : 0 ;
                    $unit_selling_price = (float)$row->group_price > 0 ? $row->group_price : $row->unit_price;
                    $stock_price_by_sp = $stock * $unit_selling_price;
                    $potential_profit = (float)$stock_price_by_sp - (float)$row->stock_price;

                    return  '<span class="potential_profit" data-orig-value="' . (float)$potential_profit . '" > ' . $this->transactionUtil->num_f($potential_profit, true) . '</span>';
                })
                ->setRowClass(function ($row) {
                    return $row->enable_stock && $row->stock <= $row->alert_quantity ? 'bg-danger' : '';
                })
                ->filterColumn('variation', function ($query, $keyword) {
                    // Updated for flat cache table - search in product_variation and variation_name
                    $query->where(function($q) use ($keyword) {
                        $q->where('product_variation', 'like', "%{$keyword}%")
                          ->orWhere('variation_name', 'like', "%{$keyword}%");
                    });
                })
                ->orderColumn('unit_price', 'unit_price $1')
                ->orderColumn('stock', 'stock $1')
                ->orderColumn('total_sold', 'total_sold $1')
                ->orderColumn('total_transfered', 'total_transfered $1')
                ->orderColumn('total_adjusted', 'total_adjusted $1')
                ->orderColumn('stock_price', 'stock_price $1')
                ->removeColumn('enable_stock')
                ->removeColumn('unit')
                ->removeColumn('id');

            $raw_columns  = ['unit_price', 'total_transfered', 'total_sold',
                    'total_adjusted', 'stock', 'stock_price', 'stock_value_by_sale_price', 'potential_profit'];

            if ($show_manufacturing_data) {
                $datatable->editColumn('total_mfg_stock', function ($row) {
                    $total_mfg_stock = 0;
                    if ($row->total_mfg_stock) {
                        $total_mfg_stock =  (float)$row->total_mfg_stock;
                    }

                    return '<span data-is_quantity="true" class="total_mfg_stock"  data-orig-value="' . $total_mfg_stock . '" data-unit="' . $row->unit . '" >' . $this->transactionUtil->num_f($total_mfg_stock, false, null, true) . '</span> ' . $row->unit;
                });
                $raw_columns[] = 'total_mfg_stock';
            }

            return $datatable->rawColumns($raw_columns)->make(true);
        }

        $categories = Category::forDropdown($business_id, 'product');
        $brands = Brands::forDropdown($business_id);
        $units = Unit::where('business_id', $business_id)
                            ->pluck('short_name', 'id');
        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('report.stock_report')
            ->with(compact('categories', 'brands', 'units', 'business_locations', 'show_manufacturing_data'));
    }

    /**
     * Shows product stock details
     *
     * @return \Illuminate\Http\Response
     */
    public function getStockDetails(Request $request)
    {
        //Return the details in ajax call
        if ($request->ajax()) {
            $business_id = $request->session()->get('user.business_id');
            $product_id = $request->input('product_id');
            $query = Product::leftjoin('units as u', 'products.unit_id', '=', 'u.id')
                ->join('variations as v', 'products.id', '=', 'v.product_id')
                ->join('product_variations as pv', 'pv.id', '=', 'v.product_variation_id')
                ->leftjoin('variation_location_details as vld', 'v.id', '=', 'vld.variation_id')
                ->where('products.business_id', $business_id)
                ->where('products.id', $product_id)
                ->whereNull('v.deleted_at');

            $permitted_locations = auth()->user()->permitted_locations();
            $location_filter = '';
            if ($permitted_locations != 'all') {
                $query->whereIn('vld.location_id', $permitted_locations);
                $locations_imploded = implode(', ', $permitted_locations);
                $location_filter .= "AND transactions.location_id IN ($locations_imploded) ";
            }

            if (!empty($request->input('location_id'))) {
                $location_id = $request->input('location_id');

                $query->where('vld.location_id', $location_id);

                $location_filter .= "AND transactions.location_id=$location_id";
            }

            $product_details =  $query->select(
                'products.name as product',
                'u.short_name as unit',
                'pv.name as product_variation',
                'v.name as variation',
                'v.sub_sku as sub_sku',
                'v.sell_price_inc_tax',
                DB::raw("SUM(vld.qty_available) as stock"),
                DB::raw("(SELECT SUM(IF(transactions.type='sell', TSL.quantity - TSL.quantity_returned, -1* TPL.quantity) ) FROM transactions 
                        LEFT JOIN transaction_sell_lines AS TSL ON transactions.id=TSL.transaction_id

                        LEFT JOIN purchase_lines AS TPL ON transactions.id=TPL.transaction_id

                        WHERE transactions.status='final' AND transactions.type='sell' $location_filter 
                        AND (TSL.variation_id=v.id OR TPL.variation_id=v.id)) as total_sold"),
                DB::raw("(SELECT SUM(IF(transactions.type='sell_transfer', TSL.quantity, 0) ) FROM transactions 
                        LEFT JOIN transaction_sell_lines AS TSL ON transactions.id=TSL.transaction_id
                        WHERE transactions.status='final' AND transactions.type='sell_transfer' $location_filter 
                        AND (TSL.variation_id=v.id)) as total_transfered"),
                DB::raw("(SELECT SUM(IF(transactions.type='stock_adjustment', SAL.quantity, 0) ) FROM transactions 
                        LEFT JOIN stock_adjustment_lines AS SAL ON transactions.id=SAL.transaction_id
                        WHERE transactions.status='received' AND transactions.type='stock_adjustment' $location_filter 
                        AND (SAL.variation_id=v.id)) as total_adjusted")
                // DB::raw("(SELECT SUM(quantity) FROM transaction_sell_lines LEFT JOIN transactions ON transaction_sell_lines.transaction_id=transactions.id WHERE transactions.status='final' $location_filter AND
                //     transaction_sell_lines.variation_id=v.id) as total_sold")
            )
                        ->groupBy('v.id')
                        ->get();

            return view('report.stock_details')
                        ->with(compact('product_details'));
        }
    }

    /**
     * Shows tax report of a business
     *
     * @return \Illuminate\Http\Response
     */
    public function getTaxDetails(Request $request)
    {
        // Tax detail reveals total revenue — admin-only (Sarah 2026-04-28).
        $this->ensureAdminOnlyReportAccess();

        if ($request->ajax()) {

            $business_id = $request->session()->get('user.business_id');
            $taxes = TaxRate::forBusiness($business_id);
            $type = $request->input('type');

            $payment_types = $this->transactionUtil->payment_types(null, true, $business_id);

            $sells = Transaction::leftJoin('tax_rates as tr', 'transactions.tax_id', '=', 'tr.id')
                            ->leftJoin('contacts as c', 'transactions.contact_id', '=', 'c.id')
                ->where('transactions.business_id', $business_id)
                ->with(['payment_lines'])
                ->select('c.name as contact_name', 
                        'c.supplier_business_name',
                        'c.tax_number',
                        'transactions.ref_no',
                        'transactions.invoice_no',
                        'transactions.transaction_date',
                        'transactions.total_before_tax',
                        'transactions.tax_id',
                        'transactions.tax_amount',
                        'transactions.id',
                        'transactions.type',
                        'transactions.discount_type',
                        'transactions.discount_amount'
                    );
                if ($type == 'sell') {
                    $sells->where('transactions.type', 'sell')
                    ->where('transactions.status', 'final')
                    ->where( function($query){
                        $query->whereHas('sell_lines',function($q){
                            $q->whereNotNull('transaction_sell_lines.tax_id');
                        })->orWhereNotNull('transactions.tax_id');
                    })
                    ->with(['sell_lines' => function($q){
                        $q->whereNotNull('transaction_sell_lines.tax_id');
                    }, 'sell_lines.line_tax']);
                }
                if ($type == 'purchase') {
                    $sells->where('transactions.type', 'purchase')
                    ->where('transactions.status', 'received')
                    ->where( function($query){
                        $query->whereHas('purchase_lines', function($q){
                            $q->whereNotNull('purchase_lines.tax_id');
                        })->orWhereNotNull('transactions.tax_id');
                    })
                    ->with(['purchase_lines' => function($q){
                        $q->whereNotNull('purchase_lines.tax_id');
                    }, 'purchase_lines.line_tax']);
                }

                if ($type == 'expense') {
                    $sells->where('transactions.type', 'expense')
                        ->whereNotNull('transactions.tax_id');
                }

                $permitted_locations = auth()->user()->permitted_locations();
                if ($permitted_locations != 'all') {
                    $sells->whereIn('transactions.location_id', $permitted_locations);
                }

                if (request()->has('location_id')) {
                    $location_id = request()->get('location_id');
                    if (!empty($location_id)) {
                        $sells->where('transactions.location_id', $location_id);
                    }
                }

                if (request()->has('contact_id')) {
                    $contact_id = request()->get('contact_id');
                    if (!empty($contact_id)) {
                        $sells->where('transactions.contact_id', $contact_id);
                    }
                }

                if (!empty(request()->start_date) && !empty(request()->end_date)) {
                    $start = request()->start_date;
                    $end =  request()->end_date;
                    $sells->whereDate('transactions.transaction_date', '>=', $start)
                                ->whereDate('transactions.transaction_date', '<=', $end);
                }
                $datatable = Datatables::of($sells);
                $raw_cols = ['total_before_tax', 'discount_amount', 'contact_name', 'payment_methods'];
                $group_taxes_array = TaxRate::groupTaxes($business_id);
                $group_taxes = [];
                foreach ($group_taxes_array as $group_tax) {
                   foreach ($group_tax['sub_taxes'] as $sub_tax) {
                       $group_taxes[$group_tax->id]['sub_taxes'][$sub_tax->id] = $sub_tax;
                   }
                }
                foreach ($taxes as $tax) {
                    $col = 'tax_' . $tax['id'];
                    $raw_cols[] = $col;
                    $datatable->addColumn($col, function($row) use($tax, $type, $col, $group_taxes) {
                        $tax_amount = 0;
                        if ($type == 'sell') {
                            foreach ($row->sell_lines as $sell_line) {
                                if ($sell_line->tax_id == $tax['id']) {
                                    $tax_amount += ($sell_line->item_tax * ($sell_line->quantity - $sell_line->quantity_returned) );
                                }

                                //break group tax
                                if ($sell_line->line_tax->is_tax_group == 1 && array_key_exists($tax['id'], $group_taxes[$sell_line->tax_id]['sub_taxes'])) {

                                    $group_tax_details = $this->transactionUtil->groupTaxDetails($sell_line->line_tax, $sell_line->item_tax);
                                    
                                    $sub_tax_share = 0;
                                    foreach ($group_tax_details as $sub_tax_details) {
                                        if ($sub_tax_details['id'] == $tax['id']) {
                                            $sub_tax_share = $sub_tax_details['calculated_tax'];
                                        }
                                    }

                                    $tax_amount += ($sub_tax_share * ($sell_line->quantity - $sell_line->quantity_returned) );
                                }
                            }
                        } elseif ($type == 'purchase') {
                            foreach ($row->purchase_lines as $purchase_line) {
                                if ($purchase_line->tax_id == $tax['id']) {
                                    $tax_amount += ($purchase_line->item_tax * ($purchase_line->quantity - $purchase_line->quantity_returned));
                                }

                                //break group tax
                                if ($purchase_line->line_tax->is_tax_group == 1 && array_key_exists($tax['id'], $group_taxes[$purchase_line->tax_id]['sub_taxes'])) {

                                    $group_tax_details = $this->transactionUtil->groupTaxDetails($purchase_line->line_tax, $purchase_line->item_tax);
                                    
                                    $sub_tax_share = 0;
                                    foreach ($group_tax_details as $sub_tax_details) {
                                        if ($sub_tax_details['id'] == $tax['id']) {
                                            $sub_tax_share = $sub_tax_details['calculated_tax'];
                                        }
                                    }

                                    $tax_amount += ($sub_tax_share * ($purchase_line->quantity - $purchase_line->quantity_returned) );
                                }
                            }
                        }
                        if ($row->tax_id == $tax['id']) {
                            $tax_amount += $row->tax_amount;
                        }

                        //break group tax
                        if (!empty($group_taxes[$row->tax_id]) && array_key_exists($tax['id'], $group_taxes[$row->tax_id]['sub_taxes'])) {

                            $group_tax_details = $this->transactionUtil->groupTaxDetails($row->tax_id, $row->tax_amount);
                                    
                            $sub_tax_share = 0;
                            foreach ($group_tax_details as $sub_tax_details) {
                                if ($sub_tax_details['id'] == $tax['id']) {
                                    $sub_tax_share = $sub_tax_details['calculated_tax'];
                                }
                            }

                            $tax_amount += $sub_tax_share;
                        }

                        if ($tax_amount > 0) {
                            return '<span class="display_currency ' . $col . '" data-currency_symbol="true" data-orig-value="' . $tax_amount . '">' . $tax_amount . '</span>';
                        } else {
                            return '';
                        }
                    });
                }

                $datatable->editColumn(
                    'total_before_tax',
                    '<span class="display_currency total_before_tax" data-currency_symbol="true" data-orig-value="{{$total_before_tax}}">{{$total_before_tax}}</span>'
                )->editColumn('discount_amount', '@if($discount_amount != 0)<span class="display_currency" data-currency_symbol="true">{{$discount_amount}}</span>@if($discount_type == "percentage")% @endif @endif')
                ->editColumn('transaction_date', '{{@format_datetime($transaction_date)}}')
                ->editColumn('contact_name', '@if(!empty($supplier_business_name)) {{$supplier_business_name}},<br>@endif {{$contact_name}}')
                ->addColumn('payment_methods', function ($row) use ($payment_types) {
                    $methods = array_unique($row->payment_lines->pluck('method')->toArray());
                    $count = count($methods);
                    $payment_method = '';
                    if ($count == 1) {
                        $payment_method = $payment_types[$methods[0]];
                    } elseif ($count > 1) {
                        $payment_method = __('lang_v1.checkout_multi_pay');
                    }

                    $html = !empty($payment_method) ? '<span class="payment-method" data-orig-value="' . $payment_method . '" data-status-name="' . $payment_method . '">' . $payment_method . '</span>' : '';
                    
                    return $html;
                });

                return $datatable->rawColumns($raw_cols)
                            ->make(true);
        }
    }

    /**
     * Shows tax report of a business
     *
     * @return \Illuminate\Http\Response
     */
    public function getTaxReport(Request $request)
    {
        // Tax summary reveals total revenue — admin-only (Sarah 2026-04-28).
        $this->ensureAdminOnlyReportAccess();

        $business_id = $request->session()->get('user.business_id');

        //Return the details in ajax call
        if ($request->ajax()) {
            $start_date = $request->get('start_date');
            $end_date = $request->get('end_date');
            $location_id = $request->get('location_id');
            $contact_id = $request->get('contact_id');

            $input_tax_details = $this->transactionUtil->getInputTax($business_id, $start_date, $end_date, $location_id, $contact_id);

            $output_tax_details = $this->transactionUtil->getOutputTax($business_id, $start_date, $end_date, $location_id, $contact_id);

            $expense_tax_details = $this->transactionUtil->getExpenseTax($business_id, $start_date, $end_date, $location_id, $contact_id);

            $module_output_taxes = $this->moduleUtil->getModuleData('getModuleOutputTax', ['start_date' => $start_date, 'end_date' => $end_date]);

            $total_module_output_tax = 0;
            foreach ($module_output_taxes as $key => $module_output_tax) {
                $total_module_output_tax += $module_output_tax;
            }

            $total_output_tax = $output_tax_details['total_tax'] + $total_module_output_tax;
            
            $tax_diff = $total_output_tax - $input_tax_details['total_tax'] - $expense_tax_details['total_tax'];

            return [
                    'tax_diff' => $tax_diff
                ];
        }

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        $taxes = TaxRate::forBusiness($business_id);

        $tax_report_tabs = $this->moduleUtil->getModuleData('getTaxReportViewTabs');

        $contact_dropdown = Contact::contactDropdown($business_id, false, false);

        return view('report.tax_report')
            ->with(compact('business_locations', 'taxes', 'tax_report_tabs', 'contact_dropdown'));
    }

    /**
     * Shows trending products
     *
     * @return \Illuminate\Http\Response
     */
    public function getTrendingProducts(Request $request)
    {
        // Open to all staff — what's moving helps the floor reorder
        // (Sarah 2026-04-28).
        $business_id = $request->session()->get('user.business_id');

        $filters = request()->only(['category', 'sub_category', 'brand', 'unit', 'limit', 'location_id', 'product_type']);

        $date_range = request()->input('date_range');
        
        if (!empty($date_range)) {
            $date_range_array = explode('~', $date_range);
            $filters['start_date'] = $this->transactionUtil->uf_date(trim($date_range_array[0]));
            $filters['end_date'] = $this->transactionUtil->uf_date(trim($date_range_array[1]));
        }

        $products = $this->productUtil->getTrendingProducts($business_id, $filters);

        $values = [];
        $labels = [];
        foreach ($products as $product) {
            $values[] = (float) $product->total_unit_sold;
            $labels[] = $product->product . ' - ' . $product->sku . ' (' . $product->unit . ')';
        }

        $chart = new CommonChart;
        $chart->labels($labels)
            ->dataset(__('report.total_unit_sold'), 'column', $values);

        $categories = Category::forDropdown($business_id, 'product');
        $brands = Brands::forDropdown($business_id);
        $units = Unit::where('business_id', $business_id)
                            ->pluck('short_name', 'id');
        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('report.trending_products')
                    ->with(compact('chart', 'categories', 'brands', 'units', 'business_locations'));
    }

    public function getTrendingProductsAjax()
    {
        $business_id = request()->session()->get('user.business_id');
    }
    /**
     * Shows expense report of a business
     *
     * @return \Illuminate\Http\Response
     */
    public function getExpenseReport(Request $request)
    {
        // Open to all staff — expense tracking, not aggregated sales
        // (Sarah 2026-04-28).
        $business_id = $request->session()->get('user.business_id');
        $filters = $request->only(['category', 'location_id']);

        $date_range = $request->input('date_range');
        
        if (!empty($date_range)) {
            $date_range_array = explode('~', $date_range);
            $filters['start_date'] = $this->transactionUtil->uf_date(trim($date_range_array[0]));
            $filters['end_date'] = $this->transactionUtil->uf_date(trim($date_range_array[1]));
        } else {
            $filters['start_date'] = \Carbon::now()->startOfMonth()->format('Y-m-d');
            $filters['end_date'] = \Carbon::now()->endOfMonth()->format('Y-m-d');
        }

        $expenses = $this->transactionUtil->getExpenseReport($business_id, $filters);

        $values = [];
        $labels = [];
        foreach ($expenses as $expense) {
            $values[] = (float) $expense->total_expense;
            $labels[] = !empty($expense->category) ? $expense->category : __('report.others');
        }

        $chart = new CommonChart;
        $chart->labels($labels)
            ->title(__('report.expense_report'))
            ->dataset(__('report.total_expense'), 'column', $values);

        $categories = ExpenseCategory::where('business_id', $business_id)
                            ->pluck('name', 'id');
        
        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('report.expense_report')
                    ->with(compact('chart', 'categories', 'business_locations', 'expenses'));
    }

    /**
     * Shows stock adjustment report
     *
     * @return \Illuminate\Http\Response
     */
    public function getStockAdjustmentReport(Request $request)
    {
        // Open to all staff — stock adjustment audit trail
        // (Sarah 2026-04-28).
        $business_id = $request->session()->get('user.business_id');

        //Return the details in ajax call
        if ($request->ajax()) {
            $query =  Transaction::where('business_id', $business_id)
                            ->where('type', 'stock_adjustment');

            //Check for permitted locations of a user
            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $query->whereIn('location_id', $permitted_locations);
            }

            $start_date = $request->get('start_date');
            $end_date = $request->get('end_date');
            if (!empty($start_date) && !empty($end_date)) {
                $query->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
            }
            $location_id = $request->get('location_id');
            if (!empty($location_id)) {
                $query->where('location_id', $location_id);
            }

            $stock_adjustment_details = $query->select(
                DB::raw("SUM(final_total) as total_amount"),
                DB::raw("SUM(total_amount_recovered) as total_recovered"),
                DB::raw("SUM(IF(adjustment_type = 'normal', final_total, 0)) as total_normal"),
                DB::raw("SUM(IF(adjustment_type = 'abnormal', final_total, 0)) as total_abnormal")
            )->first();
            return $stock_adjustment_details;
        }
        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('report.stock_adjustment_report')
                    ->with(compact('business_locations'));
    }

    /**
     * Shows register report of a business
     *
     * @return \Illuminate\Http\Response
     */
    public function getRegisterReport(Request $request)
    {
        // Register close-outs show shift totals — admin-only (Sarah 2026-04-28).
        $this->ensureAdminOnlyReportAccess();
        $business_id = $request->session()->get('user.business_id');

        //Return the details in ajax call
        if ($request->ajax()) {
            $registers = CashRegister::leftjoin(
                'cash_register_transactions as ct',
                'ct.cash_register_id',
                '=',
                'cash_registers.id'
            )->join(
                'users as u',
                'u.id',
                '=',
                'cash_registers.user_id'
                )
                ->leftJoin(
                    'business_locations as bl',
                    'bl.id',
                    '=',
                    'cash_registers.location_id'
                )
                ->where('cash_registers.business_id', $business_id)
                ->select(
                    'cash_registers.*',
                    DB::raw(
                        "CONCAT(COALESCE(surname, ''), ' ', COALESCE(first_name, ''), ' ', COALESCE(last_name, ''), '<br>', COALESCE(u.email, '')) as user_name"
                    ),
                    'bl.name as location_name',
                    DB::raw("SUM(IF(ct.transaction_type='initial', ct.amount, 0)) as opening_balance"),
                    DB::raw("SUM(IF(pay_method='cash', IF(transaction_type='sell', amount, 0), 0)) as total_cash_payment"),
                    DB::raw("SUM(IF(pay_method='cheque', IF(transaction_type='sell', amount, 0), 0)) as total_cheque_payment"),
                    DB::raw("SUM(IF(pay_method='card', IF(transaction_type='sell', amount, 0), 0)) as total_card_payment"),
                    DB::raw("SUM(IF(pay_method='bank_transfer', IF(transaction_type='sell', amount, 0), 0)) as total_bank_transfer_payment"),
                    DB::raw("SUM(IF(pay_method='other', IF(transaction_type='sell', amount, 0), 0)) as total_other_payment"),
                    DB::raw("SUM(IF(pay_method='advance', IF(transaction_type='sell', amount, 0), 0)) as total_advance_payment"),
                    DB::raw("SUM(IF(pay_method='custom_pay_1', IF(transaction_type='sell', amount, 0), 0)) as total_custom_pay_1"),
                    DB::raw("SUM(IF(pay_method='custom_pay_2', IF(transaction_type='sell', amount, 0), 0)) as total_custom_pay_2"),
                    DB::raw("SUM(IF(pay_method='custom_pay_3', IF(transaction_type='sell', amount, 0), 0)) as total_custom_pay_3"),
                    DB::raw("SUM(IF(pay_method='custom_pay_4', IF(transaction_type='sell', amount, 0), 0)) as total_custom_pay_4"),
                    DB::raw("SUM(IF(pay_method='custom_pay_5', IF(transaction_type='sell', amount, 0), 0)) as total_custom_pay_5"),
                    DB::raw("SUM(IF(pay_method='custom_pay_6', IF(transaction_type='sell', amount, 0), 0)) as total_custom_pay_6"),
                    DB::raw("SUM(IF(pay_method='custom_pay_7', IF(transaction_type='sell', amount, 0), 0)) as total_custom_pay_7")
                )->groupBy('cash_registers.id');

            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $registers->whereIn('cash_registers.location_id', $permitted_locations);
            }

            if (!empty($request->input('user_id'))) {
                $registers->where('cash_registers.user_id', $request->input('user_id'));
            }
            if (!empty($request->input('status'))) {
                $registers->where('cash_registers.status', $request->input('status'));
            }
            $start_date = $request->get('start_date');
            $end_date = $request->get('end_date');

            if (!empty($start_date) && !empty($end_date)) {
                $registers->whereDate('cash_registers.created_at', '>=', $start_date)
                        ->whereDate('cash_registers.created_at', '<=', $end_date);
            }
            return Datatables::of($registers)
                ->editColumn('total_card_payment', function ($row) {
                    return '<span data-orig-value="' . $row->total_card_payment . '" >' . $this->transactionUtil->num_f($row->total_card_payment, true) . ' (' . $row->total_card_slips . ')</span>';
                })
                ->editColumn('total_cheque_payment', function ($row) {
                    return '<span data-orig-value="' . $row->total_cheque_payment . '" >' . $this->transactionUtil->num_f($row->total_cheque_payment, true) . ' (' . $row->total_cheques . ')</span>';
                })
                ->editColumn('total_cash_payment', function ($row) {
                    return '<span data-orig-value="' . $row->total_cash_payment . '" >' . $this->transactionUtil->num_f($row->total_cash_payment, true) . '</span>';
                })
                ->editColumn('total_bank_transfer_payment', function ($row) {
                    return '<span data-orig-value="' . $row->total_bank_transfer_payment . '" >' . $this->transactionUtil->num_f($row->total_bank_transfer_payment, true) . '</span>';
                })
                ->editColumn('total_other_payment', function ($row) {
                    return '<span data-orig-value="' . $row->total_other_payment . '" >' . $this->transactionUtil->num_f($row->total_other_payment, true) . '</span>';
                })
                ->editColumn('total_advance_payment', function ($row) {
                    return '<span data-orig-value="' . $row->total_advance_payment . '" >' . $this->transactionUtil->num_f($row->total_advance_payment, true) . '</span>';
                })
                ->editColumn('total_custom_pay_1', function ($row) {
                    return '<span data-orig-value="' . $row->total_custom_pay_1 . '" >' . $this->transactionUtil->num_f($row->total_custom_pay_1, true) . '</span>';
                })
                ->editColumn('total_custom_pay_2', function ($row) {
                    return '<span data-orig-value="' . $row->total_custom_pay_2 . '" >' . $this->transactionUtil->num_f($row->total_custom_pay_2, true) . '</span>';
                })
                ->editColumn('total_custom_pay_3', function ($row) {
                    return '<span data-orig-value="' . $row->total_custom_pay_3 . '" >' . $this->transactionUtil->num_f($row->total_custom_pay_3, true) . '</span>';
                })
                ->editColumn('total_custom_pay_4', function ($row) {
                    return '<span data-orig-value="' . $row->total_custom_pay_4 . '" >' . $this->transactionUtil->num_f($row->total_custom_pay_4, true) . '</span>';
                })
                ->editColumn('total_custom_pay_5', function ($row) {
                    return '<span data-orig-value="' . $row->total_custom_pay_5 . '" >' . $this->transactionUtil->num_f($row->total_custom_pay_5, true) . '</span>';
                })
                ->editColumn('total_custom_pay_6', function ($row) {
                    return '<span data-orig-value="' . $row->total_custom_pay_6 . '" >' . $this->transactionUtil->num_f($row->total_custom_pay_6, true) . '</span>';
                })
                ->editColumn('total_custom_pay_7', function ($row) {
                    return '<span data-orig-value="' . $row->total_custom_pay_7 . '" >' . $this->transactionUtil->num_f($row->total_custom_pay_7, true) . '</span>';
                })
                ->editColumn('closed_at', function ($row) {
                    if ($row->status == 'close') {
                        return $this->productUtil->format_date($row->closed_at, true);
                    } else {
                        return '';
                    }
                })
                ->editColumn('created_at', function ($row) {
                    return $this->productUtil->format_date($row->created_at, true);
                })
                ->editColumn('opening_balance', function ($row) {
                    return '<span data-orig-value="' . ($row->opening_balance ?? 0) . '" >' . $this->transactionUtil->num_f($row->opening_balance ?? 0, true) . '</span>';
                })
                ->editColumn('closing_amount', function ($row) {
                    if ($row->status != 'close') {
                        return '';
                    }
                    $val = $row->closing_amount ?? 0;
                    $html = '<span data-orig-value="' . $val . '" >' . $this->transactionUtil->num_f($val, true) . '</span>';
                    if ($val === null || $val === '' || (float) $val <= 0) {
                        $html .= ' <span class="label label-warning">' . __('report.no_closing_balance_recorded') . '</span>';
                    }
                    return $html;
                })
                ->addColumn('expected_closing', function ($row) {
                    $opening = $row->opening_balance ?? 0;
                    $cash_sales = $row->total_cash_payment ?? 0;
                    $expected = $opening + $cash_sales;
                    return $row->status == 'close' ? '<span data-orig-value="' . $expected . '" >' . $this->transactionUtil->num_f($expected, true) . '</span>' : '';
                })
                ->addColumn('reconciliation_difference', function ($row) {
                    if ($row->status != 'close') {
                        return '';
                    }
                    $opening = $row->opening_balance ?? 0;
                    $cash_sales = $row->total_cash_payment ?? 0;
                    $expected = $opening + $cash_sales;
                    $actual = $row->closing_amount ?? 0;
                    $diff = $actual - $expected;
                    $cls = abs($diff) < 0.01 ? 'text-success' : 'text-danger';
                    return '<span class="' . $cls . '" data-orig-value="' . $diff . '" >' . $this->transactionUtil->num_f($diff, true) . '</span>';
                })
                ->addColumn('total', function ($row) {
                    $total = $row->total_card_payment + $row->total_cheque_payment + $row->total_cash_payment + $row->total_bank_transfer_payment + $row->total_other_payment + $row->total_advance_payment + $row->total_custom_pay_1 + $row->total_custom_pay_2 + $row->total_custom_pay_3 + $row->total_custom_pay_4 + $row->total_custom_pay_5 + $row->total_custom_pay_6 + $row->total_custom_pay_7;
                    
                    return '<span data-orig-value="' . $total . '" >' . $this->transactionUtil->num_f($total, true) . '</span>';
                })
                ->addColumn('action', '<button type="button" data-href="{{action(\'CashRegisterController@show\', [$id])}}" class="btn btn-xs btn-info btn-modal" 
                    data-container=".view_register"><i class="fas fa-eye" aria-hidden="true"></i> @lang("messages.view")</button> @if($status != "close" && auth()->user()->can("close_cash_register"))<button type="button" data-href="{{action(\'CashRegisterController@getCloseRegister\', [$id])}}" class="btn btn-xs btn-danger btn-modal" 
                        data-container=".view_register"><i class="fas fa-window-close"></i> @lang("messages.close")</button> @endif')
                ->filterColumn('user_name', function ($query, $keyword) {
                    $query->whereRaw("CONCAT(COALESCE(surname, ''), ' ', COALESCE(first_name, ''), ' ', COALESCE(last_name, ''), '<br>', COALESCE(u.email, '')) like ?", ["%{$keyword}%"]);
                })
                ->rawColumns(['action', 'user_name', 'opening_balance', 'closing_amount', 'expected_closing', 'reconciliation_difference', 'total_card_payment', 'total_cheque_payment', 'total_cash_payment', 'total_bank_transfer_payment', 'total_other_payment', 'total_advance_payment', 'total_custom_pay_1', 'total_custom_pay_2', 'total_custom_pay_3', 'total_custom_pay_4', 'total_custom_pay_5', 'total_custom_pay_6', 'total_custom_pay_7', 'total'])
                ->make(true);
        }

        $users = User::forDropdown($business_id, false);
        $payment_types = $this->transactionUtil->payment_types(null, true, $business_id);

        return view('report.register_report')
                    ->with(compact('users', 'payment_types'));
    }

    /**
     * Shows sales representative report
     *
     * @return \Illuminate\Http\Response
     */
    public function getSalesRepresentativeReport(Request $request)
    {
        // Sales by rep is an aggregated revenue report — admin-only (Sarah 2026-04-28).
        $this->ensureAdminOnlyReportAccess();

        $business_id = $request->session()->get('user.business_id');

        $users = User::allUsersDropdown($business_id, false);
        $business_locations = BusinessLocation::forDropdown($business_id, true);

        $business_details = $this->businessUtil->getDetails($business_id);
        $pos_settings = empty($business_details->pos_settings) ? $this->businessUtil->defaultPosSettings() : json_decode($business_details->pos_settings, true);

        return view('report.sales_representative')
                ->with(compact('users', 'business_locations', 'pos_settings'));
    }

    /**
     * Shows sales representative total expense
     *
     * @return json
     */
    public function getSalesRepresentativeTotalExpense(Request $request)
    {
        if (!auth()->user()->can('sales_representative.view')) {
            abort(403, 'Unauthorized action.');
        }

        if ($request->ajax()) {
            $business_id = $request->session()->get('user.business_id');

            $filters = $request->only(['expense_for', 'location_id', 'start_date', 'end_date']);

            $total_expense = $this->transactionUtil->getExpenseReport($business_id, $filters, 'total');

            return $total_expense;
        }
    }

    /**
     * Shows sales representative total sales
     *
     * @return json
     */
    public function getSalesRepresentativeTotalSell(Request $request)
    {
        if (!auth()->user()->can('sales_representative.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');

        //Return the details in ajax call
        if ($request->ajax()) {
            $start_date = $request->get('start_date');
            $end_date = $request->get('end_date');

            $location_id = $request->get('location_id');
            $created_by = $request->get('created_by');

            $sell_details = $this->transactionUtil->getSellTotals($business_id, $start_date, $end_date, $location_id, $created_by);

            //Get Sell Return details
            $transaction_types = [
                'sell_return'
            ];
            $sell_return_details = $this->transactionUtil->getTransactionTotals(
                $business_id,
                $transaction_types,
                $start_date,
                $end_date,
                $location_id,
                $created_by
            );

            $total_sell_return = !empty($sell_return_details['total_sell_return_exc_tax']) ? $sell_return_details['total_sell_return_exc_tax'] : 0;
            $total_sell = $sell_details['total_sell_exc_tax'] - $total_sell_return;

            return [
                'total_sell_exc_tax' => $sell_details['total_sell_exc_tax'],
                'total_sell_return_exc_tax' => $total_sell_return,
                'total_sell' => $total_sell
            ];
        }
    }

    /**
     * Shows sales representative total commission
     *
     * @return json
     */
    public function getSalesRepresentativeTotalCommission(Request $request)
    {
        if (!auth()->user()->can('sales_representative.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');

        //Return the details in ajax call
        if ($request->ajax()) {
            $start_date = $request->get('start_date');
            $end_date = $request->get('end_date');

            $location_id = $request->get('location_id');
            $commission_agent = $request->get('commission_agent');

            $business_details = $this->businessUtil->getDetails($business_id);
            $pos_settings = empty($business_details->pos_settings) ? $this->businessUtil->defaultPosSettings() : json_decode($business_details->pos_settings, true);

            $commsn_calculation_type = empty($pos_settings['cmmsn_calculation_type']) || $pos_settings['cmmsn_calculation_type'] == 'invoice_value' ? 'invoice_value' : $pos_settings['cmmsn_calculation_type'];

            $commission_percentage = User::find($commission_agent)->cmmsn_percent;

            if ($commsn_calculation_type == 'payment_received') {
                $payment_details = $this->transactionUtil->getTotalPaymentWithCommission($business_id, $start_date, $end_date, $location_id, $commission_agent);

                //Get Commision
                $total_commission = $commission_percentage * $payment_details['total_payment_with_commission'] / 100;

                return ['total_payment_with_commission' =>
                        $payment_details['total_payment_with_commission'] ?? 0,
                    'total_commission' => $total_commission,
                    'commission_percentage' => $commission_percentage
                ];
            }

            $sell_details = $this->transactionUtil->getTotalSellCommission($business_id, $start_date, $end_date, $location_id, $commission_agent);

            //Get Commision
            $total_commission = $commission_percentage * $sell_details['total_sales_with_commission'] / 100;

            return ['total_sales_with_commission' =>
                        $sell_details['total_sales_with_commission'],
                    'total_commission' => $total_commission,
                    'commission_percentage' => $commission_percentage
                ];
        }
    }

    /**
     * Shows product stock expiry report
     *
     * @return \Illuminate\Http\Response
     */
    public function getStockExpiryReport(Request $request)
    {
        // Open to all staff — expiry tracking is operational
        // (Sarah 2026-04-28).
        $business_id = $request->session()->get('user.business_id');
        
        //TODO:: Need to display reference number and edit expiry date button

        //Return the details in ajax call
        if ($request->ajax()) {
            $query = PurchaseLine::leftjoin(
                'transactions as t',
                'purchase_lines.transaction_id',
                '=',
                't.id'
            )
                            ->leftjoin(
                                'products as p',
                                'purchase_lines.product_id',
                                '=',
                                'p.id'
                            )
                            ->leftjoin(
                                'variations as v',
                                'purchase_lines.variation_id',
                                '=',
                                'v.id'
                            )
                            ->leftjoin(
                                'product_variations as pv',
                                'v.product_variation_id',
                                '=',
                                'pv.id'
                            )
                            ->leftjoin('business_locations as l', 't.location_id', '=', 'l.id')
                            ->leftjoin('units as u', 'p.unit_id', '=', 'u.id')
                            ->where('t.business_id', $business_id)
                            //->whereNotNull('p.expiry_period')
                            //->whereNotNull('p.expiry_period_type')
                            //->whereNotNull('exp_date')
                            ->where('p.enable_stock', 1);
            // ->whereRaw('purchase_lines.quantity > purchase_lines.quantity_sold + quantity_adjusted + quantity_returned');
                            
            $permitted_locations = auth()->user()->permitted_locations();

            if ($permitted_locations != 'all') {
                $query->whereIn('t.location_id', $permitted_locations);
            }

            if (!empty($request->input('location_id'))) {
                $location_id = $request->input('location_id');
                $query->where('t.location_id', $location_id)
                        //If filter by location then hide products not available in that location
                        ->join('product_locations as pl', 'pl.product_id', '=', 'p.id')
                        ->where(function ($q) use ($location_id) {
                            $q->where('pl.location_id', $location_id);
                        });
            }

            if (!empty($request->input('category_id'))) {
                $query->where('p.category_id', $request->input('category_id'));
            }
            if (!empty($request->input('sub_category_id'))) {
                $query->where('p.sub_category_id', $request->input('sub_category_id'));
            }
            if (!empty($request->input('brand_id'))) {
                $query->where('p.brand_id', $request->input('brand_id'));
            }
            if (!empty($request->input('unit_id'))) {
                $query->where('p.unit_id', $request->input('unit_id'));
            }
            if (!empty($request->input('exp_date_filter'))) {
                $query->whereDate('exp_date', '<=', $request->input('exp_date_filter'));
            }

            $only_mfg_products = request()->get('only_mfg_products', 0);
            if (!empty($only_mfg_products)) {
                $query->where('t.type', 'production_purchase');
            }

            $report = $query->select(
                'p.name as product',
                'p.sku',
                'p.type as product_type',
                'v.name as variation',
                'v.sub_sku',
                'pv.name as product_variation',
                'l.name as location',
                'mfg_date',
                'exp_date',
                'u.short_name as unit',
                DB::raw("SUM(COALESCE(quantity, 0) - COALESCE(quantity_sold, 0) - COALESCE(quantity_adjusted, 0) - COALESCE(quantity_returned, 0)) as stock_left"),
                't.ref_no',
                't.id as transaction_id',
                'purchase_lines.id as purchase_line_id',
                'purchase_lines.lot_number'
            )
            ->having('stock_left', '>', 0)
            ->groupBy('purchase_lines.variation_id')
            ->groupBy('purchase_lines.exp_date')
            ->groupBy('purchase_lines.lot_number');

            return Datatables::of($report)
                ->editColumn('product', function ($row) {
                    if ($row->product_type == 'variable') {
                        return $row->product . ' - ' .
                        $row->product_variation . ' - ' . $row->variation . ' (' . $row->sub_sku . ')';
                    } else {
                        return $row->product . ' (' . $row->sku . ')';
                    }
                })
                ->editColumn('mfg_date', function ($row) {
                    if (!empty($row->mfg_date)) {
                        return $this->productUtil->format_date($row->mfg_date);
                    } else {
                        return '--';
                    }
                })
                // ->editColumn('exp_date', function ($row) {
                //     if (!empty($row->exp_date)) {
                //         $carbon_exp = \Carbon::createFromFormat('Y-m-d', $row->exp_date);
                //         $carbon_now = \Carbon::now();
                //         if ($carbon_now->diffInDays($carbon_exp, false) >= 0) {
                //             return $this->productUtil->format_date($row->exp_date) . '<br><small>( <span class="time-to-now">' . $row->exp_date . '</span> )</small>';
                //         } else {
                //             return $this->productUtil->format_date($row->exp_date) . ' &nbsp; <span class="label label-danger no-print">' . __('report.expired') . '</span><span class="print_section">' . __('report.expired') . '</span><br><small>( <span class="time-from-now">' . $row->exp_date . '</span> )</small>';
                //         }
                //     } else {
                //         return '--';
                //     }
                // })
                ->editColumn('ref_no', function ($row) {
                    return '<button type="button" data-href="' . action('PurchaseController@show', [$row->transaction_id])
                            . '" class="btn btn-link btn-modal" data-container=".view_modal"  >' . $row->ref_no . '</button>';
                })
                ->editColumn('stock_left', function ($row) {
                    return '<span data-is_quantity="true" class="display_currency stock_left" data-currency_symbol=false data-orig-value="' . $row->stock_left . '" data-unit="' . $row->unit . '" >' . $row->stock_left . '</span> ' . $row->unit;
                })
                ->addColumn('edit', function ($row) {
                    $html =  '<button type="button" class="btn btn-primary btn-xs stock_expiry_edit_btn" data-transaction_id="' . $row->transaction_id . '" data-purchase_line_id="' . $row->purchase_line_id . '"> <i class="fa fa-edit"></i> ' . __("messages.edit") .
                    '</button>';

                    if (!empty($row->exp_date)) {
                        $carbon_exp = \Carbon::createFromFormat('Y-m-d', $row->exp_date);
                        $carbon_now = \Carbon::now();
                        if ($carbon_now->diffInDays($carbon_exp, false) < 0) {
                            $html .=  ' <button type="button" class="btn btn-warning btn-xs remove_from_stock_btn" data-href="' . action('StockAdjustmentController@removeExpiredStock', [$row->purchase_line_id]) . '"> <i class="fa fa-trash"></i> ' . __("lang_v1.remove_from_stock") .
                            '</button>';
                        }
                    }

                    return $html;
                })
                ->rawColumns(['exp_date', 'ref_no', 'edit', 'stock_left'])
                ->make(true);
        }

        $categories = Category::forDropdown($business_id, 'product');
        $brands = Brands::forDropdown($business_id);
        $units = Unit::where('business_id', $business_id)
                            ->pluck('short_name', 'id');
        $business_locations = BusinessLocation::forDropdown($business_id, true);
        $view_stock_filter = [
            \Carbon::now()->subDay()->format('Y-m-d') => __('report.expired'),
            \Carbon::now()->addWeek()->format('Y-m-d') => __('report.expiring_in_1_week'),
            \Carbon::now()->addDays(15)->format('Y-m-d') => __('report.expiring_in_15_days'),
            \Carbon::now()->addMonth()->format('Y-m-d') => __('report.expiring_in_1_month'),
            \Carbon::now()->addMonths(3)->format('Y-m-d') => __('report.expiring_in_3_months'),
            \Carbon::now()->addMonths(6)->format('Y-m-d') => __('report.expiring_in_6_months'),
            \Carbon::now()->addYear()->format('Y-m-d') => __('report.expiring_in_1_year')
        ];

        return view('report.stock_expiry_report')
                ->with(compact('categories', 'brands', 'units', 'business_locations', 'view_stock_filter'));
    }

    /**
     * Shows product stock expiry report
     *
     * @return \Illuminate\Http\Response
     */
    public function getStockExpiryReportEditModal(Request $request, $purchase_line_id)
    {
        if (!auth()->user()->can('stock_report.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');

        //Return the details in ajax call
        if ($request->ajax()) {
            $purchase_line = PurchaseLine::join(
                'transactions as t',
                'purchase_lines.transaction_id',
                '=',
                't.id'
            )
                                ->join(
                                    'products as p',
                                    'purchase_lines.product_id',
                                    '=',
                                    'p.id'
                                )
                                ->where('purchase_lines.id', $purchase_line_id)
                                ->where('t.business_id', $business_id)
                                ->select(['purchase_lines.*', 'p.name', 't.ref_no'])
                                ->first();

            if (!empty($purchase_line)) {
                if (!empty($purchase_line->exp_date)) {
                    $purchase_line->exp_date = date('m/d/Y', strtotime($purchase_line->exp_date));
                }
            }

            return view('report.partials.stock_expiry_edit_modal')
                ->with(compact('purchase_line'));
        }
    }

    /**
     * Update product stock expiry report
     *
     * @return \Illuminate\Http\Response
     */
    public function updateStockExpiryReport(Request $request)
    {
        if (!auth()->user()->can('stock_report.view')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = $request->session()->get('user.business_id');

            //Return the details in ajax call
            if ($request->ajax()) {
                DB::beginTransaction();

                $input = $request->only(['purchase_line_id', 'exp_date']);

                $purchase_line = PurchaseLine::join(
                    'transactions as t',
                    'purchase_lines.transaction_id',
                    '=',
                    't.id'
                )
                                    ->join(
                                        'products as p',
                                        'purchase_lines.product_id',
                                        '=',
                                        'p.id'
                                    )
                                    ->where('purchase_lines.id', $input['purchase_line_id'])
                                    ->where('t.business_id', $business_id)
                                    ->select(['purchase_lines.*', 'p.name', 't.ref_no'])
                                    ->first();

                if (!empty($purchase_line) && !empty($input['exp_date'])) {
                    $purchase_line->exp_date = $this->productUtil->uf_date($input['exp_date']);
                    $purchase_line->save();
                }

                DB::commit();

                $output = ['success' => 1,
                            'msg' => __('lang_v1.updated_succesfully')
                        ];
            }
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
            $output = ['success' => 0,
                            'msg' => __('messages.something_went_wrong')
                        ];
        }

        return $output;
    }

    /**
     * Shows product stock expiry report
     *
     * @return \Illuminate\Http\Response
     */
    public function getCustomerGroup(Request $request)
    {
        // Sales-by-customer-group is an aggregated revenue report — admin-only
        // (Sarah 2026-04-28).
        $this->ensureAdminOnlyReportAccess();

        $business_id = $request->session()->get('user.business_id');

        if ($request->ajax()) {
            $query = Transaction::leftjoin('customer_groups AS CG', 'transactions.customer_group_id', '=', 'CG.id')
                        ->where('transactions.business_id', $business_id)
                        ->where('transactions.type', 'sell')
                        ->where('transactions.status', 'final')
                        ->groupBy('transactions.customer_group_id')
                        ->select(DB::raw("SUM(final_total) as total_sell"), 'CG.name');

            $group_id = $request->get('customer_group_id', null);
            if (!empty($group_id)) {
                $query->where('transactions.customer_group_id', $group_id);
            }

            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $query->whereIn('transactions.location_id', $permitted_locations);
            }

            $location_id = $request->get('location_id', null);
            if (!empty($location_id)) {
                $query->where('transactions.location_id', $location_id);
            }

            $start_date = $request->get('start_date');
            $end_date = $request->get('end_date');
            
            if (!empty($start_date) && !empty($end_date)) {
                $query->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
            }
            

            return Datatables::of($query)
                ->editColumn('total_sell', function ($row) {
                    return '<span class="display_currency" data-currency_symbol = true>' . $row->total_sell . '</span>';
                })
                ->rawColumns(['total_sell'])
                ->make(true);
        }

        $customer_group = CustomerGroup::forDropdown($business_id, false, true);
        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('report.customer_group')
            ->with(compact('customer_group', 'business_locations'));
    }

    /**
     * Shows product purchase report
     *
     * @return \Illuminate\Http\Response
     */
    public function getproductPurchaseReport(Request $request)
    {
        // Open to all staff — purchase history per product
        // (Sarah 2026-04-28).
        $business_id = $request->session()->get('user.business_id');
        if ($request->ajax()) {
            $variation_id = $request->get('variation_id', null);
            $query = PurchaseLine::join(
                'transactions as t',
                'purchase_lines.transaction_id',
                '=',
                't.id'
                    )
                    ->join(
                        'variations as v',
                        'purchase_lines.variation_id',
                        '=',
                        'v.id'
                    )
                    ->join('product_variations as pv', 'v.product_variation_id', '=', 'pv.id')
                    ->join('contacts as c', 't.contact_id', '=', 'c.id')
                    ->join('products as p', 'pv.product_id', '=', 'p.id')
                    ->leftjoin('units as u', 'p.unit_id', '=', 'u.id')
                    ->where('t.business_id', $business_id)
                    ->where('t.type', 'purchase')
                    ->select(
                        'p.name as product_name',
                        'p.type as product_type',
                        'pv.name as product_variation',
                        'v.name as variation_name',
                        'v.sub_sku',
                        'c.name as supplier',
                        'c.supplier_business_name',
                        't.id as transaction_id',
                        't.ref_no',
                        't.transaction_date as transaction_date',
                        'purchase_lines.purchase_price_inc_tax as unit_purchase_price',
                        DB::raw('(purchase_lines.quantity - purchase_lines.quantity_returned) as purchase_qty'),
                        'purchase_lines.quantity_adjusted',
                        'u.short_name as unit',
                        DB::raw('((purchase_lines.quantity - purchase_lines.quantity_returned - purchase_lines.quantity_adjusted) * purchase_lines.purchase_price_inc_tax) as subtotal')
                    )
                    ->groupBy('purchase_lines.id');
            if (!empty($variation_id)) {
                $query->where('purchase_lines.variation_id', $variation_id);
            }
            $start_date = $request->get('start_date');
            $end_date = $request->get('end_date');
            if (!empty($start_date) && !empty($end_date)) {
                $query->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
            }

            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $query->whereIn('t.location_id', $permitted_locations);
            }

            $location_id = $request->get('location_id', null);
            if (!empty($location_id)) {
                $query->where('t.location_id', $location_id);
            }

            $supplier_id = $request->get('supplier_id', null);
            if (!empty($supplier_id)) {
                $query->where('t.contact_id', $supplier_id);
            }

            $brand_id = $request->get('brand_id', null);
            if (!empty($brand_id)) {
                $query->where('p.brand_id', $brand_id);
            }

            return Datatables::of($query)
                ->editColumn('product_name', function ($row) {
                    $product_name = $row->product_name;
                    if ($row->product_type == 'variable') {
                        $product_name .= ' - ' . $row->product_variation . ' - ' . $row->variation_name;
                    }

                    return $product_name;
                })
                 ->editColumn('ref_no', function ($row) {
                     return '<a data-href="' . action('PurchaseController@show', [$row->transaction_id])
                            . '" href="#" data-container=".view_modal" class="btn-modal">' . $row->ref_no . '</a>';
                 })
                 ->editColumn('purchase_qty', function ($row) {
                     return '<span data-is_quantity="true" class="display_currency purchase_qty" data-currency_symbol=false data-orig-value="' . (float)$row->purchase_qty . '" data-unit="' . $row->unit . '" >' . (float) $row->purchase_qty . '</span> ' . $row->unit;
                 })
                 ->editColumn('quantity_adjusted', function ($row) {
                     return '<span data-is_quantity="true" class="display_currency quantity_adjusted" data-currency_symbol=false data-orig-value="' . (float)$row->quantity_adjusted . '" data-unit="' . $row->unit . '" >' . (float) $row->quantity_adjusted . '</span> ' . $row->unit;
                 })
                 ->editColumn('subtotal', function ($row) {
                     return '<span class="display_currency row_subtotal" data-currency_symbol=true data-orig-value="' . $row->subtotal . '">' . $row->subtotal . '</span>';
                 })
                ->editColumn('transaction_date', '{{@format_date($transaction_date)}}')
                ->editColumn('unit_purchase_price', function ($row) {
                    return '<span class="display_currency" data-currency_symbol = true>' . $row->unit_purchase_price . '</span>';
                })
                ->editColumn('supplier', '@if(!empty($supplier_business_name)) {{$supplier_business_name}},<br>@endif {{$supplier}}')
                ->rawColumns(['ref_no', 'unit_purchase_price', 'subtotal', 'purchase_qty', 'quantity_adjusted', 'supplier'])
                ->make(true);
        }

        $business_locations = BusinessLocation::forDropdown($business_id);
        $suppliers = Contact::suppliersDropdown($business_id);
        $brands = Brands::forDropdown($business_id);

        return view('report.product_purchase_report')
            ->with(compact('business_locations', 'suppliers', 'brands'));
    }

    /**
     * Shows product purchase report
     *
     * @return \Illuminate\Http\Response
     */
    public function getproductSellReport(Request $request)
    {
        if (!auth()->user()->can('purchase_n_sell_report.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');
        if ($request->ajax()) {
            $variation_id = $request->get('variation_id', null);
            $query = TransactionSellLine::join(
                'transactions as t',
                'transaction_sell_lines.transaction_id',
                '=',
                't.id'
            )
                ->join(
                    'variations as v',
                    'transaction_sell_lines.variation_id',
                    '=',
                    'v.id'
                )
                ->join('product_variations as pv', 'v.product_variation_id', '=', 'pv.id')
                ->join('contacts as c', 't.contact_id', '=', 'c.id')
                ->join('products as p', 'pv.product_id', '=', 'p.id')
                ->leftjoin('tax_rates', 'transaction_sell_lines.tax_id', '=', 'tax_rates.id')
                ->leftjoin('units as u', 'p.unit_id', '=', 'u.id')
                ->where('t.business_id', $business_id)
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->select(
                    'p.name as product_name',
                    'p.type as product_type',
                    'pv.name as product_variation',
                    'v.name as variation_name',
                    'v.sub_sku',
                    'c.name as customer',
                    'c.supplier_business_name',
                    'c.contact_id',
                    't.id as transaction_id',
                    't.invoice_no',
                    't.transaction_date as transaction_date',
                    'transaction_sell_lines.unit_price_before_discount as unit_price',
                    'transaction_sell_lines.unit_price_inc_tax as unit_sale_price',
                    DB::raw('(transaction_sell_lines.quantity - transaction_sell_lines.quantity_returned) as sell_qty'),
                    'transaction_sell_lines.line_discount_type as discount_type',
                    'transaction_sell_lines.line_discount_amount as discount_amount',
                    'transaction_sell_lines.item_tax',
                    'tax_rates.name as tax',
                    'u.short_name as unit',
                    'transaction_sell_lines.parent_sell_line_id',
                    DB::raw('((transaction_sell_lines.quantity - transaction_sell_lines.quantity_returned) * transaction_sell_lines.unit_price_inc_tax) as subtotal')
                )
                ->groupBy('transaction_sell_lines.id');

            if (!empty($variation_id)) {
                $query->where('transaction_sell_lines.variation_id', $variation_id);
            }
            $start_date = $request->get('start_date');
            $end_date = $request->get('end_date');
            if (!empty($start_date) && !empty($end_date)) {
                $query->where('t.transaction_date', '>=', $start_date)
                    ->where('t.transaction_date', '<=', $end_date);
            }

            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $query->whereIn('t.location_id', $permitted_locations);
            }

            $location_id = $request->get('location_id', null);
            if (!empty($location_id)) {
                $query->where('t.location_id', $location_id);
            }

            $customer_id = $request->get('customer_id', null);
            if (!empty($customer_id)) {
                $query->where('t.contact_id', $customer_id);
            }

            $customer_group_id = $request->get('customer_group_id', null);
            if (!empty($customer_group_id)) {
                $query->leftjoin('customer_groups AS CG', 'c.customer_group_id', '=', 'CG.id')
                ->where('CG.id', $customer_group_id);
            }

            $category_id = $request->get('category_id', null);
            if (!empty($category_id)) {
                $query->where('p.category_id', $category_id);
            }

            $brand_id = $request->get('brand_id', null);
            if (!empty($brand_id)) {
                $query->where('p.brand_id', $brand_id);
            }

            return Datatables::of($query)
                ->editColumn('product_name', function ($row) {
                    $product_name = $row->product_name;
                    if ($row->product_type == 'variable') {
                        $product_name .= ' - ' . $row->product_variation . ' - ' . $row->variation_name;
                    }

                    return $product_name;
                })
                 ->editColumn('invoice_no', function ($row) {
                     return '<a data-href="' . action('SellController@show', [$row->transaction_id])
                            . '" href="#" data-container=".view_modal" class="btn-modal">' . $row->invoice_no . '</a>';
                 })
                ->editColumn('transaction_date', '{{@format_datetime($transaction_date)}}')
                ->editColumn('unit_sale_price', function ($row) {
                    return '<span class="display_currency" data-currency_symbol = true>' . $row->unit_sale_price . '</span>';
                })
                ->editColumn('sell_qty', function ($row) {
                    //ignore child sell line of combo product
                    $class = is_null($row->parent_sell_line_id) ? 'sell_qty' : '';

                    return '<span data-is_quantity="true" class="display_currency ' . $class . '" data-currency_symbol=false data-orig-value="' . (float)$row->sell_qty . '" data-unit="' . $row->unit . '" >' . (float) $row->sell_qty . '</span> ' .$row->unit;
                })
                 ->editColumn('subtotal', function ($row) {
                    //ignore child sell line of combo product
                    $class = is_null($row->parent_sell_line_id) ? 'row_subtotal' : '';
                    return '<span class="display_currency ' . $class . '" data-currency_symbol = true data-orig-value="' . $row->subtotal . '">' . $row->subtotal . '</span>';
                 })
                ->editColumn('unit_price', function ($row) {
                    return '<span class="display_currency" data-currency_symbol = true>' . $row->unit_price . '</span>';
                })
                ->editColumn('discount_amount', '
                    @if($discount_type == "percentage")
                        {{@num_format($discount_amount)}} %
                    @elseif($discount_type == "fixed")
                        {{@num_format($discount_amount)}}
                    @endif
                    ')
                ->editColumn('tax', function ($row) {
                    return '<span class="display_currency" data-currency_symbol = true>'.
                            $row->item_tax.
                       '</span>'.'<br>'.'<span class="tax" data-orig-value="'.(float)$row->item_tax.'" data-unit="'.$row->tax.'"><small>('.$row->tax.')</small></span>';
                })
                ->editColumn('customer', '@if(!empty($supplier_business_name)) {{$supplier_business_name}},<br>@endif {{$customer}}')
                ->rawColumns(['invoice_no', 'unit_sale_price', 'subtotal', 'sell_qty', 'discount_amount', 'unit_price', 'tax', 'customer'])
                ->make(true);
        }

        $business_locations = BusinessLocation::forDropdown($business_id);
        $customers = Contact::customersDropdown($business_id);
        $categories = Category::forDropdown($business_id, 'product');
        $brands = Brands::forDropdown($business_id);
        $customer_group = CustomerGroup::forDropdown($business_id, false, true);

        return view('report.product_sell_report')
            ->with(compact('business_locations', 'customers', 'categories', 'brands', 
                'customer_group'));
    }

    /**
     * Shows product purchase report with purchase details
     *
     * @return \Illuminate\Http\Response
     */
    public function getproductSellReportWithPurchase(Request $request)
    {
        if (!auth()->user()->can('purchase_n_sell_report.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');
        if ($request->ajax()) {
            $variation_id = $request->get('variation_id', null);
            $query = TransactionSellLine::join(
                'transactions as t',
                'transaction_sell_lines.transaction_id',
                '=',
                't.id'
            )
                ->join(
                    'transaction_sell_lines_purchase_lines as tspl',
                    'transaction_sell_lines.id',
                    '=',
                    'tspl.sell_line_id'
                )
                ->join(
                    'purchase_lines as pl',
                    'tspl.purchase_line_id',
                    '=',
                    'pl.id'
                )
                ->join(
                    'transactions as purchase',
                    'pl.transaction_id',
                    '=',
                    'purchase.id'
                )
                ->leftjoin('contacts as supplier', 'purchase.contact_id', '=', 'supplier.id')
                ->join(
                    'variations as v',
                    'transaction_sell_lines.variation_id',
                    '=',
                    'v.id'
                )
                ->join('product_variations as pv', 'v.product_variation_id', '=', 'pv.id')
                ->leftjoin('contacts as c', 't.contact_id', '=', 'c.id')
                ->join('products as p', 'pv.product_id', '=', 'p.id')
                ->leftjoin('units as u', 'p.unit_id', '=', 'u.id')
                ->where('t.business_id', $business_id)
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->select(
                    'p.name as product_name',
                    'p.type as product_type',
                    'pv.name as product_variation',
                    'v.name as variation_name',
                    'v.sub_sku',
                    'c.name as customer',
                    'c.supplier_business_name',
                    't.id as transaction_id',
                    't.invoice_no',
                    't.transaction_date as transaction_date',
                    'tspl.quantity as purchase_quantity',
                    'u.short_name as unit',
                    'supplier.name as supplier_name',
                    'purchase.ref_no as ref_no',
                    'purchase.type as purchase_type',
                    'pl.lot_number'
                );

            if (!empty($variation_id)) {
                $query->where('transaction_sell_lines.variation_id', $variation_id);
            }
            $start_date = $request->get('start_date');
            $end_date = $request->get('end_date');
            if (!empty($start_date) && !empty($end_date)) {
                $query->where('t.transaction_date', '>=', $start_date)
                    ->where('t.transaction_date', '<=', $end_date);
            }

            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $query->whereIn('t.location_id', $permitted_locations);
            }

            $location_id = $request->get('location_id', null);
            if (!empty($location_id)) {
                $query->where('t.location_id', $location_id);
            }

            $customer_id = $request->get('customer_id', null);
            if (!empty($customer_id)) {
                $query->where('t.contact_id', $customer_id);
            }
            $customer_group_id = $request->get('customer_group_id', null);
            if (!empty($customer_group_id)) {
                $query->leftjoin('customer_groups AS CG', 'c.customer_group_id', '=', 'CG.id')
                ->where('CG.id', $customer_group_id);
            }

            $category_id = $request->get('category_id', null);
            if (!empty($category_id)) {
                $query->where('p.category_id', $category_id);
            }

            $brand_id = $request->get('brand_id', null);
            if (!empty($brand_id)) {
                $query->where('p.brand_id', $brand_id);
            }

            return Datatables::of($query)
                ->editColumn('product_name', function ($row) {
                    $product_name = $row->product_name;
                    if ($row->product_type == 'variable') {
                        $product_name .= ' - ' . $row->product_variation . ' - ' . $row->variation_name;
                    }

                    return $product_name;
                })
                 ->editColumn('invoice_no', function ($row) {
                     return '<a data-href="' . action('SellController@show', [$row->transaction_id])
                            . '" href="#" data-container=".view_modal" class="btn-modal">' . $row->invoice_no . '</a>';
                 })
                ->editColumn('transaction_date', '{{@format_datetime($transaction_date)}}')
                ->editColumn('unit_sale_price', function ($row) {
                    return '<span class="display_currency" data-currency_symbol = true>' . $row->unit_sale_price . '</span>';
                })
                ->editColumn('purchase_quantity', function ($row) {
                    return '<span data-is_quantity="true" class="display_currency purchase_quantity" data-currency_symbol=false data-orig-value="' . (float)$row->purchase_quantity . '" data-unit="' . $row->unit . '" >' . (float) $row->purchase_quantity . '</span> ' .$row->unit;
                })
                ->editColumn('ref_no', '
                    @if($purchase_type == "opening_stock")
                        <i><small class="help-block">(@lang("lang_v1.opening_stock"))</small></i>
                    @else
                        {{$ref_no}}
                    @endif
                    ')
                ->editColumn('customer', '@if(!empty($supplier_business_name)) {{$supplier_business_name}},<br>@endif {{$customer}}')
                ->rawColumns(['invoice_no', 'purchase_quantity', 'ref_no', 'customer'])
                ->make(true);
        }
    }

    /**
     * Shows product lot report
     *
     * @return \Illuminate\Http\Response
     */
    public function getLotReport(Request $request)
    {
        if (!auth()->user()->can('stock_report.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');

        //Return the details in ajax call
        if ($request->ajax()) {
            $query = Product::where('products.business_id', $business_id)
                    ->leftjoin('units', 'products.unit_id', '=', 'units.id')
                    ->join('variations as v', 'products.id', '=', 'v.product_id')
                    ->join('purchase_lines as pl', 'v.id', '=', 'pl.variation_id')
                    ->leftjoin(
                        'transaction_sell_lines_purchase_lines as tspl',
                        'pl.id',
                        '=',
                        'tspl.purchase_line_id'
                    )
                    ->join('transactions as t', 'pl.transaction_id', '=', 't.id');

            $permitted_locations = auth()->user()->permitted_locations();
            $location_filter = 'WHERE ';

            if ($permitted_locations != 'all') {
                $query->whereIn('t.location_id', $permitted_locations);

                $locations_imploded = implode(', ', $permitted_locations);
                $location_filter = " LEFT JOIN transactions as t2 on pls.transaction_id=t2.id WHERE t2.location_id IN ($locations_imploded) AND ";
            }

            if (!empty($request->input('location_id'))) {
                $location_id = $request->input('location_id');
                $query->where('t.location_id', $location_id)
                    //If filter by location then hide products not available in that location
                    ->ForLocation($location_id);

                $location_filter = "LEFT JOIN transactions as t2 on pls.transaction_id=t2.id WHERE t2.location_id=$location_id AND ";
            }

            if (!empty($request->input('category_id'))) {
                $query->where('products.category_id', $request->input('category_id'));
            }

            if (!empty($request->input('sub_category_id'))) {
                $query->where('products.sub_category_id', $request->input('sub_category_id'));
            }

            if (!empty($request->input('brand_id'))) {
                $query->where('products.brand_id', $request->input('brand_id'));
            }

            if (!empty($request->input('unit_id'))) {
                $query->where('products.unit_id', $request->input('unit_id'));
            }

            $only_mfg_products = request()->get('only_mfg_products', 0);
            if (!empty($only_mfg_products)) {
                $query->where('t.type', 'production_purchase');
            }

            $products = $query->select(
                'products.name as product',
                'v.name as variation_name',
                'sub_sku',
                'pl.lot_number',
                'pl.exp_date as exp_date',
                DB::raw("( COALESCE((SELECT SUM(quantity - quantity_returned) from purchase_lines as pls $location_filter variation_id = v.id AND lot_number = pl.lot_number), 0) - 
                    SUM(COALESCE((tspl.quantity - tspl.qty_returned), 0))) as stock"),
                // DB::raw("(SELECT SUM(IF(transactions.type='sell', TSL.quantity, -1* TPL.quantity) ) FROM transactions
                //         LEFT JOIN transaction_sell_lines AS TSL ON transactions.id=TSL.transaction_id

                //         LEFT JOIN purchase_lines AS TPL ON transactions.id=TPL.transaction_id

                //         WHERE transactions.status='final' AND transactions.type IN ('sell', 'sell_return') $location_filter
                //         AND (TSL.product_id=products.id OR TPL.product_id=products.id)) as total_sold"),

                DB::raw("COALESCE(SUM(IF(tspl.sell_line_id IS NULL, 0, (tspl.quantity - tspl.qty_returned)) ), 0) as total_sold"),
                DB::raw("COALESCE(SUM(IF(tspl.stock_adjustment_line_id IS NULL, 0, tspl.quantity ) ), 0) as total_adjusted"),
                'products.type',
                'units.short_name as unit'
            )
            ->whereNotNull('pl.lot_number')
            ->groupBy('v.id')
            ->groupBy('pl.lot_number');

            return Datatables::of($products)
                ->editColumn('stock', function ($row) {
                    $stock = $row->stock ? $row->stock : 0 ;
                    return '<span data-is_quantity="true" class="display_currency total_stock" data-currency_symbol=false data-orig-value="' . (float)$stock . '" data-unit="' . $row->unit . '" >' . (float)$stock . '</span> ' . $row->unit;
                })
                ->editColumn('product', function ($row) {
                    if ($row->variation_name != 'DUMMY') {
                        return $row->product . ' (' . $row->variation_name . ')';
                    } else {
                        return $row->product;
                    }
                })
                ->editColumn('total_sold', function ($row) {
                    if ($row->total_sold) {
                        return '<span data-is_quantity="true" class="display_currency total_sold" data-currency_symbol=false data-orig-value="' . (float)$row->total_sold . '" data-unit="' . $row->unit . '" >' . (float)$row->total_sold . '</span> ' . $row->unit;
                    } else {
                        return '0' . ' ' . $row->unit;
                    }
                })
                ->editColumn('total_adjusted', function ($row) {
                    if ($row->total_adjusted) {
                        return '<span data-is_quantity="true" class="display_currency total_adjusted" data-currency_symbol=false data-orig-value="' . (float)$row->total_adjusted . '" data-unit="' . $row->unit . '" >' . (float)$row->total_adjusted . '</span> ' . $row->unit;
                    } else {
                        return '0' . ' ' . $row->unit;
                    }
                })
                ->editColumn('exp_date', function ($row) {
                    if (!empty($row->exp_date)) {
                        $carbon_exp = \Carbon::createFromFormat('Y-m-d', $row->exp_date);
                        $carbon_now = \Carbon::now();
                        if ($carbon_now->diffInDays($carbon_exp, false) >= 0) {
                            return $this->productUtil->format_date($row->exp_date) . '<br><small>( <span class="time-to-now">' . $row->exp_date . '</span> )</small>';
                        } else {
                            return $this->productUtil->format_date($row->exp_date) . ' &nbsp; <span class="label label-danger no-print">' . __('report.expired') . '</span><span class="print_section">' . __('report.expired') . '</span><br><small>( <span class="time-from-now">' . $row->exp_date . '</span> )</small>';
                        }
                    } else {
                        return '--';
                    }
                })
                ->removeColumn('unit')
                ->removeColumn('id')
                ->removeColumn('variation_name')
                ->rawColumns(['exp_date', 'stock', 'total_sold', 'total_adjusted'])
                ->make(true);
        }

        $categories = Category::forDropdown($business_id, 'product');
        $brands = Brands::forDropdown($business_id);
        $units = Unit::where('business_id', $business_id)
                            ->pluck('short_name', 'id');
        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('report.lot_report')
            ->with(compact('categories', 'brands', 'units', 'business_locations'));
    }

    /**
     * Shows purchase payment report
     *
     * @return \Illuminate\Http\Response
     */
    public function purchasePaymentReport(Request $request)
    {
        // Open to all staff — payments to suppliers, not aggregated sales
        // (Sarah 2026-04-28).
        $business_id = $request->session()->get('user.business_id');
        if ($request->ajax()) {
            $supplier_id = $request->get('supplier_id', null);
            $contact_filter1 = !empty($supplier_id) ? "AND t.contact_id=$supplier_id" : '';
            $contact_filter2 = !empty($supplier_id) ? "AND transactions.contact_id=$supplier_id" : '';

            $location_id = $request->get('location_id', null);

            $parent_payment_query_part = empty($location_id) ? "AND transaction_payments.parent_id IS NULL" : "";

            $query = TransactionPayment::leftjoin('transactions as t', function ($join) use ($business_id) {
                $join->on('transaction_payments.transaction_id', '=', 't.id')
                    ->where('t.business_id', $business_id)
                    ->whereIn('t.type', ['purchase', 'opening_balance']);
            })
                ->where('transaction_payments.business_id', $business_id)
                ->where(function ($q) use ($business_id, $contact_filter1, $contact_filter2, $parent_payment_query_part) {
                    $q->whereRaw("(transaction_payments.transaction_id IS NOT NULL AND t.type IN ('purchase', 'opening_balance')  $parent_payment_query_part $contact_filter1)")
                        ->orWhereRaw("EXISTS(SELECT * FROM transaction_payments as tp JOIN transactions ON tp.transaction_id = transactions.id WHERE transactions.type IN ('purchase', 'opening_balance') AND transactions.business_id = $business_id AND tp.parent_id=transaction_payments.id $contact_filter2)");
                })
                              
                ->select(
                    DB::raw("IF(transaction_payments.transaction_id IS NULL, 
                                (SELECT c.name FROM transactions as ts
                                JOIN contacts as c ON ts.contact_id=c.id 
                                WHERE ts.id=(
                                        SELECT tps.transaction_id FROM transaction_payments as tps
                                        WHERE tps.parent_id=transaction_payments.id LIMIT 1
                                    )
                                ),
                                (SELECT CONCAT(COALESCE(c.supplier_business_name, ''), '<br>', c.name) FROM transactions as ts JOIN
                                    contacts as c ON ts.contact_id=c.id
                                    WHERE ts.id=t.id 
                                )
                            ) as supplier"),
                    'transaction_payments.amount',
                    'method',
                    'paid_on',
                    'transaction_payments.payment_ref_no',
                    'transaction_payments.document',
                    't.ref_no',
                    't.id as transaction_id',
                    'cheque_number',
                    'card_transaction_number',
                    'bank_account_number',
                    'transaction_no',
                    'transaction_payments.id as DT_RowId'
                )
                ->groupBy('transaction_payments.id');

            $start_date = $request->get('start_date');
            $end_date = $request->get('end_date');
            if (!empty($start_date) && !empty($end_date)) {
                $query->whereBetween(DB::raw('date(paid_on)'), [$start_date, $end_date]);
            }

            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $query->whereIn('t.location_id', $permitted_locations);
            }

            if (!empty($location_id)) {
                $query->where('t.location_id', $location_id);
            }

            $payment_types = $this->transactionUtil->payment_types(null, true, $business_id);
            
            return Datatables::of($query)
                 ->editColumn('ref_no', function ($row) {
                     if (!empty($row->ref_no)) {
                         return '<a data-href="' . action('PurchaseController@show', [$row->transaction_id])
                            . '" href="#" data-container=".view_modal" class="btn-modal">' . $row->ref_no . '</a>';
                     } else {
                         return '';
                     }
                 })
                ->editColumn('paid_on', '{{@format_datetime($paid_on)}}')
                ->editColumn('method', function ($row) use ($payment_types) {
                    $method = !empty($payment_types[$row->method]) ? $payment_types[$row->method] : '';
                    if ($row->method == 'cheque') {
                        $method .= '<br>(' . __('lang_v1.cheque_no') . ': ' . $row->cheque_number . ')';
                    } elseif ($row->method == 'card') {
                        $method .= '<br>(' . __('lang_v1.card_transaction_no') . ': ' . $row->card_transaction_number . ')';
                    } elseif ($row->method == 'bank_transfer') {
                        $method .= '<br>(' . __('lang_v1.bank_account_no') . ': ' . $row->bank_account_number . ')';
                    } elseif ($row->method == 'custom_pay_1') {
                        $method .= '<br>(' . __('lang_v1.transaction_no') . ': ' . $row->transaction_no . ')';
                    } elseif ($row->method == 'custom_pay_2') {
                        $method .= '<br>(' . __('lang_v1.transaction_no') . ': ' . $row->transaction_no . ')';
                    } elseif ($row->method == 'custom_pay_3') {
                        $method .= '<br>(' . __('lang_v1.transaction_no') . ': ' . $row->transaction_no . ')';
                    }
                    return $method;
                })
                ->editColumn('amount', function ($row) {
                    return '<span class="display_currency paid-amount" data-currency_symbol = true data-orig-value="' . $row->amount . '">' . $row->amount . '</span>';
                })
                ->addColumn('action', '<button type="button" class="btn btn-primary btn-xs view_payment" data-href="{{ action("TransactionPaymentController@viewPayment", [$DT_RowId]) }}">@lang("messages.view")
                    </button> @if(!empty($document))<a href="{{asset("/uploads/documents/" . $document)}}" class="btn btn-success btn-xs" download=""><i class="fa fa-download"></i> @lang("purchase.download_document")</a>@endif')
                ->rawColumns(['ref_no', 'amount', 'method', 'action', 'supplier'])
                ->make(true);
        }
        $business_locations = BusinessLocation::forDropdown($business_id);
        $suppliers = Contact::suppliersDropdown($business_id, false);

        return view('report.purchase_payment_report')
            ->with(compact('business_locations', 'suppliers'));
    }

    /**
     * Shows sell payment report
     *
     * @return \Illuminate\Http\Response
     */
    public function sellPaymentReport(Request $request)
    {
        // Sell-payment totals reveal revenue — admin-only (Sarah 2026-04-28).
        $this->ensureAdminOnlyReportAccess();

        $business_id = $request->session()->get('user.business_id');

        $payment_types = $this->transactionUtil->payment_types(null, true, $business_id);
        if ($request->ajax()) {
            $customer_id = $request->get('supplier_id', null);
            $contact_filter1 = !empty($customer_id) ? "AND t.contact_id=$customer_id" : '';
            $contact_filter2 = !empty($customer_id) ? "AND transactions.contact_id=$customer_id" : '';

            $location_id = $request->get('location_id', null);
            $parent_payment_query_part = empty($location_id) ? "AND transaction_payments.parent_id IS NULL" : "";

            $query = TransactionPayment::leftjoin('transactions as t', function ($join) use ($business_id) {
                $join->on('transaction_payments.transaction_id', '=', 't.id')
                    ->where('t.business_id', $business_id)
                    ->whereIn('t.type', ['sell', 'opening_balance']);
            })
                ->leftjoin('contacts as c', 't.contact_id', '=', 'c.id')
                ->leftjoin('customer_groups AS CG', 'c.customer_group_id', '=', 'CG.id')
                ->where('transaction_payments.business_id', $business_id)
                ->where(function ($q) use ($business_id, $contact_filter1, $contact_filter2, $parent_payment_query_part) {
                    $q->whereRaw("(transaction_payments.transaction_id IS NOT NULL AND t.type IN ('sell', 'opening_balance') $parent_payment_query_part $contact_filter1)")
                        ->orWhereRaw("EXISTS(SELECT * FROM transaction_payments as tp JOIN transactions ON tp.transaction_id = transactions.id WHERE transactions.type IN ('sell', 'opening_balance') AND transactions.business_id = $business_id AND tp.parent_id=transaction_payments.id $contact_filter2)");
                })
                ->select(
                    DB::raw("IF(transaction_payments.transaction_id IS NULL, 
                                (SELECT c.name FROM transactions as ts
                                JOIN contacts as c ON ts.contact_id=c.id 
                                WHERE ts.id=(
                                        SELECT tps.transaction_id FROM transaction_payments as tps
                                        WHERE tps.parent_id=transaction_payments.id LIMIT 1
                                    )
                                ),
                                (SELECT CONCAT(COALESCE(CONCAT(c.supplier_business_name, '<br>'), ''), c.name) FROM transactions as ts JOIN
                                    contacts as c ON ts.contact_id=c.id
                                    WHERE ts.id=t.id 
                                )
                            ) as customer"),
                    'transaction_payments.amount',
                    'transaction_payments.is_return',
                    'method',
                    'paid_on',
                    'transaction_payments.payment_ref_no',
                    'transaction_payments.document',
                    'transaction_payments.transaction_no',
                    't.invoice_no',
                    't.id as transaction_id',
                    'cheque_number',
                    'card_transaction_number',
                    'bank_account_number',
                    'transaction_payments.id as DT_RowId',
                    'CG.name as customer_group'
                )
                ->groupBy('transaction_payments.id');

            $start_date = $request->get('start_date');
            $end_date = $request->get('end_date');
            if (!empty($start_date) && !empty($end_date)) {
                $query->whereBetween(DB::raw('date(paid_on)'), [$start_date, $end_date]);
            }

            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $query->whereIn('t.location_id', $permitted_locations);
            }
            
            if (!empty($request->get('customer_group_id'))) {
                $query->where('CG.id', $request->get('customer_group_id'));
            }

            if (!empty($location_id)) {
                $query->where('t.location_id', $location_id);
            }
            if (!empty($request->has('commission_agent'))) {
                $query->where('t.commission_agent', $request->get('commission_agent'));
            }

            if (!empty($request->get('payment_types'))) {
                $query->where('transaction_payments.method', $request->get('payment_types'));
            }

            return Datatables::of($query)
                 ->editColumn('invoice_no', function ($row) {
                     if (!empty($row->transaction_id)) {
                         return '<a data-href="' . action('SellController@show', [$row->transaction_id])
                            . '" href="#" data-container=".view_modal" class="btn-modal">' . $row->invoice_no . '</a>';
                     } else {
                         return '';
                     }
                 })
                ->editColumn('paid_on', '{{@format_datetime($paid_on)}}')
                ->editColumn('method', function ($row) use ($payment_types) {
                    $method = !empty($payment_types[$row->method]) ? $payment_types[$row->method] : '';
                    if ($row->method == 'cheque') {
                        $method .= '<br>(' . __('lang_v1.cheque_no') . ': ' . $row->cheque_number . ')';
                    } elseif ($row->method == 'card') {
                        $method .= '<br>(' . __('lang_v1.card_transaction_no') . ': ' . $row->card_transaction_number . ')';
                    } elseif ($row->method == 'bank_transfer') {
                        $method .= '<br>(' . __('lang_v1.bank_account_no') . ': ' . $row->bank_account_number . ')';
                    } elseif ($row->method == 'custom_pay_1') {
                        $method .= '<br>(' . __('lang_v1.transaction_no') . ': ' . $row->transaction_no . ')';
                    } elseif ($row->method == 'custom_pay_2') {
                        $method .= '<br>(' . __('lang_v1.transaction_no') . ': ' . $row->transaction_no . ')';
                    } elseif ($row->method == 'custom_pay_3') {
                        $method .= '<br>(' . __('lang_v1.transaction_no') . ': ' . $row->transaction_no . ')';
                    }
                    if ($row->is_return == 1) {
                        $method .= '<br><small>(' . __('lang_v1.change_return') . ')</small>';
                    }
                    return $method;
                })
                ->editColumn('amount', function ($row) {
                    $amount = $row->is_return == 1 ? -1 * $row->amount : $row->amount;
                    return '<span class="display_currency paid-amount" data-orig-value="' . $amount . '" data-currency_symbol = true>' . $amount . '</span>';
                })
                ->addColumn('action', '<button type="button" class="btn btn-primary btn-xs view_payment" data-href="{{ action("TransactionPaymentController@viewPayment", [$DT_RowId]) }}">@lang("messages.view")
                    </button> @if(!empty($document))<a href="{{asset("/uploads/documents/" . $document)}}" class="btn btn-success btn-xs" download=""><i class="fa fa-download"></i> @lang("purchase.download_document")</a>@endif')
                ->rawColumns(['invoice_no', 'amount', 'method', 'action', 'customer'])
                ->make(true);
        }
        $business_locations = BusinessLocation::forDropdown($business_id);
        $customers = Contact::customersDropdown($business_id, false);
        $customer_groups = CustomerGroup::forDropdown($business_id, false, true);

        return view('report.sell_payment_report')
            ->with(compact('business_locations', 'customers', 'payment_types', 'customer_groups'));
    }


    /**
     * Shows tables report
     *
     * @return \Illuminate\Http\Response
     */
    public function getTableReport(Request $request)
    {
        if (!auth()->user()->can('purchase_n_sell_report.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');

        if ($request->ajax()) {
            $query = ResTable::leftjoin('transactions AS T', 'T.res_table_id', '=', 'res_tables.id')
                        ->where('T.business_id', $business_id)
                        ->where('T.type', 'sell')
                        ->where('T.status', 'final')
                        ->groupBy('res_tables.id')
                        ->select(DB::raw("SUM(final_total) as total_sell"), 'res_tables.name as table');

            $location_id = $request->get('location_id', null);
            if (!empty($location_id)) {
                $query->where('T.location_id', $location_id);
            }

            $start_date = $request->get('start_date');
            $end_date = $request->get('end_date');
            
            if (!empty($start_date) && !empty($end_date)) {
                $query->whereBetween(DB::raw('date(transaction_date)'), [$start_date, $end_date]);
            }

            return Datatables::of($query)
                ->editColumn('total_sell', function ($row) {
                    return '<span class="display_currency" data-currency_symbol="true">' . $row->total_sell . '</span>';
                })
                ->rawColumns(['total_sell'])
                ->make(true);
        }

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('report.table_report')
            ->with(compact('business_locations'));
    }

    /**
     * Shows service staff report
     *
     * @return \Illuminate\Http\Response
     */
    public function getServiceStaffReport(Request $request)
    {
        if (!auth()->user()->can('sales_representative.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        $waiters = $this->transactionUtil->serviceStaffDropdown($business_id);

        return view('report.service_staff_report')
            ->with(compact('business_locations', 'waiters'));
    }

    /**
     * Shows product sell report grouped by date
     *
     * @return \Illuminate\Http\Response
     */
    public function getproductSellGroupedReport(Request $request)
    {
        if (!auth()->user()->can('purchase_n_sell_report.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');
        $location_id = $request->get('location_id', null);

        $vld_str = '';
        if (!empty($location_id)) {
            $vld_str = "AND vld.location_id=$location_id";
        }

        if ($request->ajax()) {
            $variation_id = $request->get('variation_id', null);
            $query = TransactionSellLine::join(
                'transactions as t',
                'transaction_sell_lines.transaction_id',
                '=',
                't.id'
            )
                ->join(
                    'variations as v',
                    'transaction_sell_lines.variation_id',
                    '=',
                    'v.id'
                )
                ->join('product_variations as pv', 'v.product_variation_id', '=', 'pv.id')
                ->join('products as p', 'pv.product_id', '=', 'p.id')
                ->leftjoin('units as u', 'p.unit_id', '=', 'u.id')
                ->where('t.business_id', $business_id)
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->select(
                    'p.name as product_name',
                    'p.enable_stock',
                    'p.type as product_type',
                    'pv.name as product_variation',
                    'v.name as variation_name',
                    'v.sub_sku',
                    't.id as transaction_id',
                    't.transaction_date as transaction_date',
                    'transaction_sell_lines.parent_sell_line_id',
                    DB::raw('DATE_FORMAT(t.transaction_date, "%Y-%m-%d") as formated_date'),
                    DB::raw("(SELECT SUM(vld.qty_available) FROM variation_location_details as vld WHERE vld.variation_id=v.id $vld_str) as current_stock"),
                    DB::raw('SUM(transaction_sell_lines.quantity - transaction_sell_lines.quantity_returned) as total_qty_sold'),
                    'u.short_name as unit',
                    DB::raw('SUM((transaction_sell_lines.quantity - transaction_sell_lines.quantity_returned) * transaction_sell_lines.unit_price_inc_tax) as subtotal')
                )
                ->groupBy('v.id')
                ->groupBy('formated_date');

            if (!empty($variation_id)) {
                $query->where('transaction_sell_lines.variation_id', $variation_id);
            }
            $start_date = $request->get('start_date');
            $end_date = $request->get('end_date');
            if (!empty($start_date) && !empty($end_date)) {
                $query->where('t.transaction_date', '>=', $start_date)
                    ->where('t.transaction_date', '<=', $end_date);
            }

            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $query->whereIn('t.location_id', $permitted_locations);
            }

            if (!empty($location_id)) {
                $query->where('t.location_id', $location_id);
            }

            $customer_id = $request->get('customer_id', null);
            if (!empty($customer_id)) {
                $query->where('t.contact_id', $customer_id);
            }

            $customer_group_id = $request->get('customer_group_id', null);
            if (!empty($customer_group_id)) {
                $query->leftjoin('contacts AS c', 't.contact_id', '=', 'c.id')
                    ->leftjoin('customer_groups AS CG', 'c.customer_group_id', '=', 'CG.id')
                ->where('CG.id', $customer_group_id);
            }

            $category_id = $request->get('category_id', null);
            if (!empty($category_id)) {
                $query->where('p.category_id', $category_id);
            }

            $brand_id = $request->get('brand_id', null);
            if (!empty($brand_id)) {
                $query->where('p.brand_id', $brand_id);
            }

            return Datatables::of($query)
                ->editColumn('product_name', function ($row) {
                    $product_name = $row->product_name;
                    if ($row->product_type == 'variable') {
                        $product_name .= ' - ' . $row->product_variation . ' - ' . $row->variation_name;
                    }

                    return $product_name;
                })
                ->editColumn('transaction_date', '{{@format_date($formated_date)}}')
                ->editColumn('total_qty_sold', function ($row) {
                    return '<span data-is_quantity="true" class="display_currency sell_qty" data-currency_symbol=false data-orig-value="' . (float)$row->total_qty_sold . '" data-unit="' . $row->unit . '" >' . (float) $row->total_qty_sold . '</span> ' .$row->unit;
                })
                ->editColumn('current_stock', function ($row) {
                    if ($row->enable_stock) {
                        return '<span data-is_quantity="true" class="display_currency current_stock" data-currency_symbol=false data-orig-value="' . (float)$row->current_stock . '" data-unit="' . $row->unit . '" >' . (float) $row->current_stock . '</span> ' .$row->unit;
                    } else {
                        return '';
                    }
                })
                 ->editColumn('subtotal', function ($row) {
                    $class = is_null($row->parent_sell_line_id) ? 'row_subtotal' : '';
                     return '<span class="display_currency ' . $class . '" data-currency_symbol = true data-orig-value="' . $row->subtotal . '">' . $row->subtotal . '</span>';
                 })
                
                ->rawColumns(['current_stock', 'subtotal', 'total_qty_sold'])
                ->make(true);
        }
    }

    /**
     * Shows product sell report grouped by date
     *
     * @return \Illuminate\Http\Response
     */
    public function productSellReportBy(Request $request)
    {
        if (!auth()->user()->can('purchase_n_sell_report.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');
        $location_id = $request->get('location_id', null);
        $group_by = $request->get('group_by', null);

        $vld_str = '';
        if (!empty($location_id)) {
            $vld_str = "AND vld.location_id=$location_id";
        }

        if ($request->ajax()) {
            $query = TransactionSellLine::join(
                'transactions as t',
                'transaction_sell_lines.transaction_id',
                '=',
                't.id'
            )
                ->leftjoin(
                    'products as p',
                    'transaction_sell_lines.product_id',
                    '=',
                    'p.id'
                )
                ->leftjoin('categories as cat', 'p.category_id', '=', 'cat.id')
                ->leftjoin('brands as b', 'p.brand_id', '=', 'b.id')
                ->where('t.business_id', $business_id)
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->select(
                    'b.name as brand_name',
                    'cat.name as category_name',
                    DB::raw("(SELECT SUM(vld.qty_available) FROM variation_location_details as vld WHERE vld.variation_id=transaction_sell_lines.variation_id $vld_str) as current_stock"),
                    DB::raw('SUM(transaction_sell_lines.quantity - transaction_sell_lines.quantity_returned) as total_qty_sold'),
                    DB::raw('SUM((transaction_sell_lines.quantity - transaction_sell_lines.quantity_returned) * transaction_sell_lines.unit_price_inc_tax) as subtotal'),
                    'transaction_sell_lines.parent_sell_line_id'
                );

            if ($group_by == 'category') {
                $query->groupBy('cat.id');
            } elseif ($group_by == 'brand') {
                $query->groupBy('b.id');
            }

            $start_date = $request->get('start_date');
            $end_date = $request->get('end_date');
            if (!empty($start_date) && !empty($end_date)) {
                $query->where('t.transaction_date', '>=', $start_date)
                    ->where('t.transaction_date', '<=', $end_date);
            }

            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $query->whereIn('t.location_id', $permitted_locations);
            }

            if (!empty($location_id)) {
                $query->where('t.location_id', $location_id);
            }

            $customer_id = $request->get('customer_id', null);
            if (!empty($customer_id)) {
                $query->where('t.contact_id', $customer_id);
            }

            $customer_group_id = $request->get('customer_group_id', null);
            if (!empty($customer_group_id)) {
                $query->leftjoin('contacts AS c', 't.contact_id', '=', 'c.id')
                    ->leftjoin('customer_groups AS CG', 'c.customer_group_id', '=', 'CG.id')
                ->where('CG.id', $customer_group_id);
            }

            $category_id = $request->get('category_id', null);
            if (!empty($category_id)) {
                $query->where('p.category_id', $category_id);
            }

            $brand_id = $request->get('brand_id', null);
            if (!empty($brand_id)) {
                $query->where('p.brand_id', $brand_id);
            }

            return Datatables::of($query)
                ->editColumn('category_name', '{{$category_name ?? __("lang_v1.uncategorized")}}')
                ->editColumn('brand_name', '{{$brand_name ?? __("lang_v1.no_brand")}}')
                ->editColumn('total_qty_sold', function ($row) {
                    return '<span data-is_quantity="true" class="display_currency sell_qty" data-currency_symbol=false data-orig-value="' . (float)$row->total_qty_sold . '" data-unit="" >' . (float) $row->total_qty_sold . '</span> ' .$row->unit;
                })
                ->editColumn('current_stock', function ($row) {
                    return '<span data-is_quantity="true" class="display_currency current_stock" data-currency_symbol=false data-orig-value="' . (float)$row->current_stock . '" data-unit="">' . (float) $row->current_stock . '</span> ';
                })
                 ->editColumn('subtotal', function ($row) {
                    $class = is_null($row->parent_sell_line_id) ? 'row_subtotal' : '';
                    return '<span class="display_currency ' . $class . '" data-currency_symbol = true data-orig-value="' . $row->subtotal . '">' . $row->subtotal . '</span>';
                 })
                
                ->rawColumns(['current_stock', 'subtotal', 'total_qty_sold', 'category_name'])
                ->make(true);
        }
    }

    /**
     * Shows product stock details and allows to adjust mismatch
     *
     * @return \Illuminate\Http\Response
     */
    public function productStockDetails()
    {
        if (!auth()->user()->can('report.stock_details')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        $variation_id = request()->get('variation_id', null);
        $location_id = request()->input('location_id');

        $location = null;
        $stock_details = [];

        if (!empty(request()->input('location_id'))) {
            $location = BusinessLocation::where('business_id', $business_id)
                                        ->where('id', $location_id)
                                        ->first();
            $stock_details = $this->productUtil->getVariationStockMisMatch($business_id, $variation_id, $location_id);
        }

        $business_locations = BusinessLocation::forDropdown($business_id);

        return view('report.product_stock_details')
            ->with(compact('stock_details', 'business_locations', 'location'));
    }

    /**
     * Adjusts stock availability mismatch if found
     *
     * @return \Illuminate\Http\Response
     */
    public function adjustProductStock()
    {
        if (!auth()->user()->can('report.stock_details')) {
            abort(403, 'Unauthorized action.');
        }

        if (!empty(request()->input('variation_id'))
            && !empty(request()->input('location_id'))
            && request()->has('stock')) {


            $business_id = request()->session()->get('user.business_id');

            $this->productUtil->fixVariationStockMisMatch($business_id, request()->input('variation_id'), request()->input('location_id'), request()->input('stock'));
        }

        return redirect()->back()->with(['status' => [
                'success' => 1,
                'msg' => __('lang_v1.updated_succesfully')
            ]]);
    }

    /**
     * Retrieves line orders/sales
     *
     * @return obj
     */
    public function serviceStaffLineOrders()
    {
        $business_id = request()->session()->get('user.business_id');

        $query = TransactionSellLine::leftJoin('transactions as t', 't.id', '=', 'transaction_sell_lines.transaction_id')
                ->leftJoin('variations as v', 'transaction_sell_lines.variation_id', '=', 'v.id')
                ->leftJoin('products as p', 'v.product_id', '=', 'p.id')
                ->leftJoin('units as u', 'p.unit_id', '=', 'u.id')
                ->leftJoin('product_variations as pv', 'v.product_variation_id', '=', 'pv.id')
                ->leftJoin('users as ss', 'ss.id', '=', 'transaction_sell_lines.res_service_staff_id')
                ->leftjoin(
                    'business_locations AS bl',
                    't.location_id',
                    '=',
                    'bl.id'
                )
                ->where('t.business_id', $business_id)
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->whereNotNull('transaction_sell_lines.res_service_staff_id');


        if (!empty(request()->service_staff_id)) {
            $query->where('transaction_sell_lines.res_service_staff_id', request()->service_staff_id);
        }

        if (request()->has('location_id')) {
            $location_id = request()->get('location_id');
            if (!empty($location_id)) {
                $query->where('t.location_id', $location_id);
            }
        }

        if (!empty(request()->start_date) && !empty(request()->end_date)) {
            $start = request()->start_date;
            $end =  request()->end_date;
            $query->whereDate('t.transaction_date', '>=', $start)
                        ->whereDate('t.transaction_date', '<=', $end);
        }
                
        $query->select(
            'p.name as product_name',
            'p.type as product_type',
            'v.name as variation_name',
            'pv.name as product_variation_name',
            'u.short_name as unit',
            't.id as transaction_id',
            'bl.name as business_location',
            't.transaction_date',
            't.invoice_no',
            'transaction_sell_lines.quantity',
            'transaction_sell_lines.unit_price_before_discount',
            'transaction_sell_lines.line_discount_type',
            'transaction_sell_lines.line_discount_amount',
            'transaction_sell_lines.item_tax',
            'transaction_sell_lines.unit_price_inc_tax',
            DB::raw('CONCAT(COALESCE(ss.first_name, ""), COALESCE(ss.last_name, "")) as service_staff')
        );

        $datatable = Datatables::of($query)
            ->editColumn('product_name', function ($row) {
                $name = $row->product_name;
                if ($row->product_type == 'variable') {
                    $name .= ' - ' . $row->product_variation_name . ' - ' . $row->variation_name;
                }
                return $name;
            })
            ->editColumn(
                'unit_price_inc_tax',
                '<span class="display_currency unit_price_inc_tax" data-currency_symbol="true" data-orig-value="{{$unit_price_inc_tax}}">{{$unit_price_inc_tax}}</span>'
            )
            ->editColumn(
                'item_tax',
                '<span class="display_currency item_tax" data-currency_symbol="true" data-orig-value="{{$item_tax}}">{{$item_tax}}</span>'
            )
            ->editColumn(
                'quantity',
                '<span class="display_currency quantity" data-unit="{{$unit}}" data-currency_symbol="false" data-orig-value="{{$quantity}}">{{$quantity}}</span> {{$unit}}'
            )
            ->editColumn(
                'unit_price_before_discount',
                '<span class="display_currency unit_price_before_discount" data-currency_symbol="true" data-orig-value="{{$unit_price_before_discount}}">{{$unit_price_before_discount}}</span>'
            )
            ->addColumn(
                'total',
                '<span class="display_currency total" data-currency_symbol="true" data-orig-value="{{$unit_price_inc_tax * $quantity}}">{{$unit_price_inc_tax * $quantity}}</span>'
            )
            ->editColumn(
                'line_discount_amount',
                function ($row) {
                    $discount = !empty($row->line_discount_amount) ? $row->line_discount_amount : 0;

                    if (!empty($discount) && $row->line_discount_type == 'percentage') {
                        $discount = $row->unit_price_before_discount * ($discount / 100);
                    }

                    return '<span class="display_currency total-discount" data-currency_symbol="true" data-orig-value="' . $discount . '">' . $discount . '</span>';
                }
            )
            ->editColumn('transaction_date', '{{@format_date($transaction_date)}}')

            ->rawColumns(['line_discount_amount', 'unit_price_before_discount', 'item_tax', 'unit_price_inc_tax', 'item_tax', 'quantity', 'total'])
                  ->make(true);
                
        return $datatable;
    }

    /**
     * Lists profit by product, category, brand, location, invoice and date
     *
     * @return string $by = null
     */
    public function getProfit($by = null)
    {
        $business_id = request()->session()->get('user.business_id');

        $query = TransactionSellLine
            ::join('transactions as sale', 'transaction_sell_lines.transaction_id', '=', 'sale.id')
            ->leftjoin('transaction_sell_lines_purchase_lines as TSPL', 'transaction_sell_lines.id', '=', 'TSPL.sell_line_id')
            ->leftjoin(
                'purchase_lines as PL',
                'TSPL.purchase_line_id',
                '=',
                'PL.id'
            )
            ->where('sale.type', 'sell')
            ->where('sale.status', 'final')
            ->join('products as P', 'transaction_sell_lines.product_id', '=', 'P.id')
            ->where('sale.business_id', $business_id)
            ->where('transaction_sell_lines.children_type', '!=', 'combo');
        //If type combo: find childrens, sale price parent - get PP of childrens
        $query->select(DB::raw('SUM(IF (TSPL.id IS NULL AND P.type="combo", ( 
            SELECT Sum((tspl2.quantity - tspl2.qty_returned) * (tsl.unit_price_inc_tax - pl2.purchase_price_inc_tax)) AS total
                FROM transaction_sell_lines AS tsl
                    JOIN transaction_sell_lines_purchase_lines AS tspl2
                ON tsl.id=tspl2.sell_line_id 
                JOIN purchase_lines AS pl2 
                ON tspl2.purchase_line_id = pl2.id 
                WHERE tsl.parent_sell_line_id = transaction_sell_lines.id), IF(P.enable_stock=0,(transaction_sell_lines.quantity - transaction_sell_lines.quantity_returned) * transaction_sell_lines.unit_price_inc_tax,   
                (TSPL.quantity - TSPL.qty_returned) * (transaction_sell_lines.unit_price_inc_tax - PL.purchase_price_inc_tax)) )) AS gross_profit')
            );

        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('sale.location_id', $permitted_locations);
        }

        if (!empty(request()->location_id)) {
            $query->where('sale.location_id', request()->location_id);
        }

        if (!empty(request()->start_date) && !empty(request()->end_date)) {
            $start = request()->start_date;
            $end =  request()->end_date;
            $query->whereDate('sale.transaction_date', '>=', $start)
                        ->whereDate('sale.transaction_date', '<=', $end);
        }

        if ($by == 'product') {
            $query->join('variations as V', 'transaction_sell_lines.variation_id', '=', 'V.id')
                ->leftJoin('product_variations as PV', 'PV.id', '=', 'V.product_variation_id')
                ->addSelect(DB::raw("IF(P.type='variable', CONCAT(P.name, ' - ', PV.name, ' - ', V.name, ' (', V.sub_sku, ')'), CONCAT(P.name, ' (', P.sku, ')')) as product"))
                ->groupBy('V.id');
        }

        if ($by == 'category') {
            $query->join('variations as V', 'transaction_sell_lines.variation_id', '=', 'V.id')
                ->leftJoin('categories as C', 'C.id', '=', 'P.category_id')
                ->addSelect("C.name as category")
                ->groupBy('C.id');
        }

        if ($by == 'brand') {
            $query->join('variations as V', 'transaction_sell_lines.variation_id', '=', 'V.id')
                ->leftJoin('brands as B', 'B.id', '=', 'P.brand_id')
                ->addSelect("B.name as brand")
                ->groupBy('B.id');
        }

        if ($by == 'location') {
            $query->join('business_locations as L', 'sale.location_id', '=', 'L.id')
                ->addSelect("L.name as location")
                ->groupBy('L.id');
        }

        if ($by == 'invoice') {
            $query->addSelect(
                'sale.invoice_no', 
                'sale.id as transaction_id',
                'sale.discount_type',
                'sale.discount_amount',
                'sale.total_before_tax'
            )
                ->groupBy('sale.invoice_no');
        }

        if ($by == 'date') {
            $query->addSelect("sale.transaction_date")
                ->groupBy(DB::raw('DATE(sale.transaction_date)'));
        }

        if ($by == 'day') {
            $results = $query->addSelect(DB::raw("DAYNAME(sale.transaction_date) as day"))
                ->groupBy(DB::raw('DAYOFWEEK(sale.transaction_date)'))
                ->get();

            $profits = [];
            foreach ($results as $result) {
                $profits[strtolower($result->day)] = $result->gross_profit;
            }
            $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

            return view('report.partials.profit_by_day')->with(compact('profits', 'days'));
        }

        if ($by == 'customer') {
            $query->join('contacts as CU', 'sale.contact_id', '=', 'CU.id')
            ->addSelect("CU.name as customer" , "CU.supplier_business_name")
                ->groupBy('sale.contact_id');
        }

        $datatable = Datatables::of($query);

        if (in_array($by, ['invoice'])) {
            $datatable->editColumn( 'gross_profit', function($row) {
                $discount = $row->discount_amount;
                if ($row->discount_type == 'percentage') {
                   $discount = ($row->discount_amount * $row->total_before_tax) / 100;
                }

                $profit = $row->gross_profit - $discount;
                $html = '<span class="gross-profit" data-orig-value="' . $profit . '" >' .  $this->transactionUtil->num_f($profit, true) . '</span>';
                return $html;
            });
        } else {
            $datatable->editColumn(
                'gross_profit',
                function($row) {
                    return '<span class="gross-profit" data-orig-value="' . $row->gross_profit . '">' .  $this->transactionUtil->num_f($row->gross_profit, true) . '</span>';
                });
        }

        if ($by == 'category') {
            $datatable->editColumn(
                'category',
                '{{$category ?? __("lang_v1.uncategorized")}}'
            );
        }
        if ($by == 'brand') {
            $datatable->editColumn(
                'brand',
                '{{$brand ?? __("report.others")}}'
            );
        }

        if ($by == 'date') {
            $datatable->editColumn('transaction_date', '{{@format_date($transaction_date)}}');
        }

        if ($by == 'product') {

            $datatable->filterColumn(
                 'product',
                 function($query, $keyword){
                    $query->whereRaw("IF(P.type='variable', CONCAT(P.name, ' - ', PV.name, ' - ', V.name, ' (', V.sub_sku, ')'), CONCAT(P.name, ' (', P.sku, ')')) LIKE '%{$keyword}%'");
                 });
        }
        $raw_columns = ['gross_profit'];

        if ($by == 'customer') {
            $datatable->editColumn('customer', '@if(!empty($supplier_business_name)) {{$supplier_business_name}}, <br> @endif {{$customer}}');
            $raw_columns[] = 'customer';
        }
        
        if ($by == 'invoice') {
            $datatable->editColumn('invoice_no', function ($row) {
                return '<a data-href="' . action('SellController@show', [$row->transaction_id])
                            . '" href="#" data-container=".view_modal" class="btn-modal">' . $row->invoice_no . '</a>';
            });
            $raw_columns[] = 'invoice_no';
        }
        return $datatable->rawColumns($raw_columns)
                  ->make(true);
    }

    /**
     * Category-name → default cost lookup, sourced from CostPriceRulesController::RULES.
     * Used by the items report so manual items (and purchased items with no cost
     * recorded) show a sensible fallback price instead of N/A / 0. Match is case-
     * and whitespace-insensitive against every alias in the rule.
     */
    private static function categoryDefaultCost($categoryName)
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            foreach (\App\Http\Controllers\CostPriceRulesController::RULES as $rule) {
                foreach ($rule['match'] as $alias) {
                    $map[$alias] = (float) $rule['cost'];
                }
            }
        }
        if (empty($categoryName)) {
            return null;
        }
        $key = strtolower(trim($categoryName));
        return $map[$key] ?? null;
    }

    /**
     * Shows items report from sell purchase mapping table
     *
     * @return \Illuminate\Http\Response
     */
    public function itemsReport()
    {
        $business_id = request()->session()->get('user.business_id');

        if (request()->ajax()) {
            // Query for items with purchase-sell mappings (existing query)
            $purchased_items_query = TransactionSellLinesPurchaseLines::join('purchase_lines as PL', 'PL.id', '=', 'transaction_sell_lines_purchase_lines.purchase_line_id')
                ->join('transactions as purchase', 'PL.transaction_id', '=', 'purchase.id')
                ->leftJoin('transaction_sell_lines as SL', 'SL.id', '=', 'transaction_sell_lines_purchase_lines.sell_line_id')
                ->leftJoin('stock_adjustment_lines as SAL', 'SAL.id', '=', 'transaction_sell_lines_purchase_lines.stock_adjustment_line_id')
                ->leftJoin('transactions as sale', 'SL.transaction_id', '=', 'sale.id')
                ->leftJoin('transactions as stock_adjustment', 'SAL.transaction_id', '=', 'stock_adjustment.id')
                ->join('business_locations as bl', 'purchase.location_id', '=', 'bl.id')
                ->join('variations as v', 'PL.variation_id', '=', 'v.id')
                ->join('product_variations as pv', 'v.product_variation_id', '=', 'pv.id')
                ->join('products as p', 'PL.product_id', '=', 'p.id')
                ->join('units as u', 'p.unit_id', '=', 'u.id')
                ->leftJoin('categories as cat', 'p.category_id', '=', 'cat.id')
                ->leftJoin('categories as sub_cat', 'p.sub_category_id', '=', 'sub_cat.id')
                ->leftJoin('contacts as suppliers', 'purchase.contact_id', '=', 'suppliers.id')
                ->leftJoin('contacts as customers', 'sale.contact_id', '=', 'customers.id')
                ->where('purchase.business_id', $business_id)
                ->select(
                    'v.sub_sku as sku',
                    'p.type as product_type',
                    'p.name as product_name',
                    'p.format as format',
                    'v.name as variation_name',
                    'pv.name as product_variation',
                    'u.short_name as unit',
                    'cat.name as category',
                    'sub_cat.name as sub_category',
                    'purchase.transaction_date as purchase_date',
                    'purchase.ref_no as purchase_ref_no',
                    'purchase.type as purchase_type',
                    'purchase.id as purchase_id',
                    'suppliers.name as supplier',
                    'suppliers.supplier_business_name',
                    'PL.purchase_price_inc_tax as purchase_price',
                    'sale.transaction_date as sell_date',
                    'stock_adjustment.transaction_date as stock_adjustment_date',
                    'sale.invoice_no as sale_invoice_no',
                    'stock_adjustment.ref_no as stock_adjustment_ref_no',
                    'customers.name as customer',
                    'customers.supplier_business_name as customer_business_name',
                    'transaction_sell_lines_purchase_lines.quantity as quantity',
                    'SL.unit_price_inc_tax as selling_price',
                    'SAL.unit_price as stock_adjustment_price',
                    'transaction_sell_lines_purchase_lines.stock_adjustment_line_id',
                    'transaction_sell_lines_purchase_lines.sell_line_id',
                    'transaction_sell_lines_purchase_lines.purchase_line_id',
                    'transaction_sell_lines_purchase_lines.qty_returned',
                    'bl.name as location',
                    'SL.sell_line_note',
                    'PL.lot_number'
                );

            // Query for manual items (items without product_id)
            $manual_items_query = TransactionSellLine::join('transactions as sale', 'transaction_sell_lines.transaction_id', '=', 'sale.id')
                ->join('business_locations as bl', 'sale.location_id', '=', 'bl.id')
                ->leftJoin('categories as cat', 'transaction_sell_lines.category_id', '=', 'cat.id')
                ->leftJoin('categories as sub_cat', 'transaction_sell_lines.sub_category_id', '=', 'sub_cat.id')
                ->leftJoin('contacts as customers', 'sale.contact_id', '=', 'customers.id')
                ->where('sale.business_id', $business_id)
                ->where('sale.type', 'sell')
                ->where('sale.status', 'final')
                ->where(function($q) {
                    $q->whereNull('transaction_sell_lines.product_id')
                      ->orWhere('transaction_sell_lines.product_id', 0);
                })
                ->whereNotNull('transaction_sell_lines.product_name')
                ->select(
                    DB::raw('NULL as sku'),
                    DB::raw("'single' as product_type"),
                    'transaction_sell_lines.product_name as product_name',
                    DB::raw('NULL as format'),
                    DB::raw('NULL as variation_name'),
                    DB::raw('NULL as product_variation'),
                    DB::raw("'pcs' as unit"), // Default unit for manual items
                    'cat.name as category',
                    'sub_cat.name as sub_category',
                    DB::raw('NULL as purchase_date'),
                    DB::raw("'Manual Item' as purchase_ref_no"),
                    DB::raw("'manual' as purchase_type"),
                    DB::raw('NULL as purchase_id'),
                    DB::raw('NULL as supplier'),
                    DB::raw('NULL as supplier_business_name'),
                    DB::raw('0 as purchase_price'),
                    'sale.transaction_date as sell_date',
                    DB::raw('NULL as stock_adjustment_date'),
                    'sale.invoice_no as sale_invoice_no',
                    DB::raw('NULL as stock_adjustment_ref_no'),
                    'customers.name as customer',
                    'customers.supplier_business_name as customer_business_name',
                    'transaction_sell_lines.quantity as quantity',
                    'transaction_sell_lines.unit_price_inc_tax as selling_price',
                    DB::raw('NULL as stock_adjustment_price'),
                    DB::raw('NULL as stock_adjustment_line_id'),
                    'transaction_sell_lines.id as sell_line_id',
                    DB::raw('NULL as purchase_line_id'),
                    DB::raw('0 as qty_returned'),
                    'bl.name as location',
                    'transaction_sell_lines.sell_line_note',
                    DB::raw('NULL as lot_number')
                );

            // Apply filters to purchased items query
            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $purchased_items_query->whereIn('purchase.location_id', $permitted_locations);
                $manual_items_query->whereIn('sale.location_id', $permitted_locations);
            }

            // Apply purchase date filter
            if (!empty(request()->purchase_start) && !empty(request()->purchase_end)) {
                $start = request()->purchase_start;
                $end = request()->purchase_end;
                $purchased_items_query->whereBetween(DB::raw('DATE(purchase.transaction_date)'), [$start, $end]);
                // Manual items don't have purchase dates, so skip this filter for them
            }

            // Apply sale date filter
            if (!empty(request()->sale_start) && !empty(request()->sale_end)) {
                $start = request()->sale_start;
                $end = request()->sale_end;
                $purchased_items_query->where(function ($q) use ($start, $end) {
                    $q->where(function ($qr) use ($start, $end) {
                        $qr->whereNotNull('sale.transaction_date')
                           ->whereBetween(DB::raw('DATE(sale.transaction_date)'), [$start, $end]);
                    })->orWhere(function ($qr) use ($start, $end) {
                        $qr->whereNotNull('stock_adjustment.transaction_date')
                           ->whereBetween(DB::raw('DATE(stock_adjustment.transaction_date)'), [$start, $end]);
                    });
                });
                $manual_items_query->whereBetween(DB::raw('DATE(sale.transaction_date)'), [$start, $end]);
            }

            $supplier_id = request()->get('supplier_id', null);
            if (!empty($supplier_id)) {
                $purchased_items_query->where('suppliers.id', $supplier_id);
                // Manual items don't have suppliers, so skip this filter
            }

            $customer_id = request()->get('customer_id', null);
            if (!empty($customer_id)) {
                $purchased_items_query->where('customers.id', $customer_id);
                $manual_items_query->where('customers.id', $customer_id);
            }

            $location_id = request()->get('location_id', null);
            if (!empty($location_id)) {
                $purchased_items_query->where('purchase.location_id', $location_id);
                $manual_items_query->where('sale.location_id', $location_id);
            }

            $only_mfg_products = request()->get('only_mfg_products', 0);
            if (!empty($only_mfg_products)) {
                $purchased_items_query->where('purchase.type', 'production_purchase');
                // Manual items don't have purchase types, so skip this filter
            }

            $category_id = request()->get('category_id', null);
            if (!empty($category_id)) {
                $purchased_items_query->where('p.category_id', $category_id);
                $manual_items_query->where('transaction_sell_lines.category_id', $category_id);
            }

            $sub_category_id = request()->get('sub_category_id', null);
            if (!empty($sub_category_id)) {
                $purchased_items_query->where('p.sub_category_id', $sub_category_id);
                $manual_items_query->where('transaction_sell_lines.sub_category_id', $sub_category_id);
            }

            $only_manual_items = request()->get('only_manual_items', 0);
            
            // Union the queries or use only manual items query
            if (!empty($only_manual_items) && ($only_manual_items == 1 || $only_manual_items == '1')) {
                // Only show manual items - use only the manual items query
                $query = DB::table(DB::raw("({$manual_items_query->toSql()}) as unioned_query"))
                    ->mergeBindings($manual_items_query->getQuery());
            } else {
                // Show both purchased and manual items - use UNION
                $union_query = $purchased_items_query->union($manual_items_query);
                // Wrap UNION in subquery for Datatables compatibility
                $query = DB::table(DB::raw("({$union_query->toSql()}) as unioned_query"))
                    ->mergeBindings($purchased_items_query->getQuery())
                    ->mergeBindings($manual_items_query->getQuery());
            }

            return Datatables::of($query)
                // Map original column names to aliased columns in unioned_query
                // Use backticks and proper table reference for SQL compatibility
                ->orderColumn('p.name', DB::raw('`unioned_query`.`product_name` $1'))
                ->orderColumn('p.format', DB::raw('`unioned_query`.`format` $1'))
                ->orderColumn('v.sub_sku', DB::raw('`unioned_query`.`sku` $1'))
                ->orderColumn('cat.name', DB::raw('`unioned_query`.`category` $1'))
                ->orderColumn('sub_cat.name', DB::raw('`unioned_query`.`sub_category` $1'))
                ->orderColumn('SL.sell_line_note', DB::raw('`unioned_query`.`sell_line_note` $1'))
                ->orderColumn('purchase.transaction_date', DB::raw('`unioned_query`.`purchase_date` $1'))
                ->orderColumn('purchase.ref_no', DB::raw('`unioned_query`.`purchase_ref_no` $1'))
                ->orderColumn('PL.lot_number', DB::raw('`unioned_query`.`lot_number` $1'))
                ->orderColumn('suppliers.name', DB::raw('`unioned_query`.`supplier` $1'))
                ->orderColumn('PL.purchase_price_inc_tax', DB::raw('`unioned_query`.`purchase_price` $1'))
                ->orderColumn('bl.name', DB::raw('`unioned_query`.`location` $1'))
                ->orderColumn('sale_invoice_no', DB::raw('`unioned_query`.`sale_invoice_no` $1'))
                ->orderColumn('product_name', DB::raw('`unioned_query`.`product_name` $1'))
                ->orderColumn('sell_date', DB::raw('`unioned_query`.`sell_date` $1'))
                ->orderColumn('purchase_date', DB::raw('`unioned_query`.`purchase_date` $1'))
                ->orderColumn('quantity', DB::raw('`unioned_query`.`quantity` $1'))
                ->orderColumn('selling_price', DB::raw('`unioned_query`.`selling_price` $1'))
                ->orderColumn('purchase_price', DB::raw('`unioned_query`.`purchase_price` $1'))
                ->orderColumn('category', DB::raw('`unioned_query`.`category` $1'))
                ->orderColumn('sub_category', DB::raw('`unioned_query`.`sub_category` $1'))
                ->orderColumn('sku', DB::raw('`unioned_query`.`sku` $1'))
                ->orderColumn('location', DB::raw('`unioned_query`.`location` $1'))
                ->orderColumn('supplier', DB::raw('`unioned_query`.`supplier` $1'))
                ->orderColumn('customer', DB::raw('`unioned_query`.`customer` $1'))
                ->orderColumn('purchase_ref_no', DB::raw('`unioned_query`.`purchase_ref_no` $1'))
                ->editColumn('product_name', function ($row) {
                    $product_name = $row->product_name;
                    if ($row->product_type == 'variable' && !empty($row->product_variation)) {
                        $product_name .= ' - ' . $row->product_variation . ' - ' . $row->variation_name;
                    }
                    // Add indicator for manual items
                    if ($row->purchase_type == 'manual') {
                        $product_name .= ' <span class="label label-info">(Manual)</span>';
                    }

                    return $product_name;
                })
                ->editColumn('purchase_date', function ($row) {
                    if ($row->purchase_type == 'manual' || empty($row->purchase_date)) {
                        return '<span class="text-muted">N/A</span>';
                    }
                    $time_format = session('business.time_format') == 24 ? 'H:i' : 'h:i A';
                    return \Carbon::createFromTimestamp(strtotime($row->purchase_date))->format(session('business.date_format') . ' ' . $time_format);
                })
                ->editColumn('purchase_ref_no', function ($row) {
                    if ($row->purchase_type == 'manual') {
                        return $row->purchase_ref_no; // "Manual Item"
                    }
                    $html = $row->purchase_type == 'purchase' ? '<a data-href="' . action('PurchaseController@show', [$row->purchase_id])
                            . '" href="#" data-container=".view_modal" class="btn-modal">' . $row->purchase_ref_no . '</a>' : $row->purchase_ref_no;
                    if ($row->purchase_type == 'opening_stock') {
                        $html .= '(' . __('lang_v1.opening_stock') . ')';
                    }
                    return $html;
                })
                ->editColumn('purchase_price', function ($row) {
                    $price = (float) $row->purchase_price;
                    $is_default = false;
                    if ($price <= 0) {
                        $default = self::categoryDefaultCost($row->category);
                        if ($default !== null) {
                            $price = $default;
                            $is_default = true;
                        }
                    }
                    if ($price <= 0 && $row->purchase_type == 'manual') {
                        return '<span class="text-muted">N/A</span>';
                    }
                    $html = '<span class="display_currency purchase_price" data-currency_symbol=true data-orig-value="' . $price . '">' . $price . '</span>';
                    if ($is_default) {
                        $html .= ' <small class="text-muted" title="Category default cost (no purchase price recorded)">(default)</small>';
                    }
                    return $html;
                })
                ->editColumn('sell_date', function ($row) {
                    $time_format = session('business.time_format') == 24 ? 'H:i' : 'h:i A';
                    if (!empty($row->sell_date)) {
                        return \Carbon::createFromTimestamp(strtotime($row->sell_date))->format(session('business.date_format') . ' ' . $time_format);
                    } elseif (!empty($row->stock_adjustment_date)) {
                        return \Carbon::createFromTimestamp(strtotime($row->stock_adjustment_date))->format(session('business.date_format') . ' ' . $time_format);
                    }
                    return '';
                })

                ->editColumn('sale_invoice_no', function ($row) {
                    $invoice_no = !empty($row->sell_line_id) ? $row->sale_invoice_no : $row->stock_adjustment_ref_no . '<br><small>(' . __('stock_adjustment.stock_adjustment') . '</small)>' ;

                    return $invoice_no;
                })
                ->editColumn('quantity', function ($row) {
                    $html = '<span data-is_quantity="true" class="display_currency quantity" data-currency_symbol=false data-orig-value="' . (float)$row->quantity . '" data-unit="' . ($row->unit ?? 'pcs') . '" >' . (float) $row->quantity . '</span> ' . ($row->unit ?? 'pcs');

                    if ($row->purchase_type != 'manual' && empty($row->sell_line_id)) {
                        $html .= '<br><small>(' . __('stock_adjustment.stock_adjustment') . '</small)>';
                    }
                    if ($row->purchase_type != 'manual' && $row->qty_returned > 0) {
                        $html .= '<small><i>(<span data-is_quantity="true" class="display_currency" data-currency_symbol=false>' . (float) $row->quantity . '</span> ' . ($row->unit ?? 'pcs') . ' ' . __('lang_v1.returned') . ')</i></small>';
                    }

                    return $html;
                })
                 ->editColumn('selling_price', function ($row) {
                     if ($row->purchase_type == 'manual') {
                         $selling_price = $row->selling_price;
                     } else {
                     $selling_price = !empty($row->sell_line_id) ? $row->selling_price : $row->stock_adjustment_price;
                     }

                     return '<span class="display_currency row_selling_price" data-currency_symbol=true data-orig-value="' . $selling_price . '">' . $selling_price . '</span>';
                 })

                 ->addColumn('subtotal', function ($row) {
                     if ($row->purchase_type == 'manual') {
                         $selling_price = $row->selling_price;
                     } else {
                     $selling_price = !empty($row->sell_line_id) ? $row->selling_price : $row->stock_adjustment_price;
                     }
                     $subtotal = $selling_price * $row->quantity;
                     return '<span class="display_currency row_subtotal" data-currency_symbol=true data-orig-value="' . $subtotal . '">' . $subtotal . '</span>';
                 })
                 ->editColumn('supplier', '@if(!empty($supplier_business_name))
                 {{$supplier_business_name}},<br> @endif {{$supplier}}')
                 ->editColumn('customer', '@if(!empty($customer_business_name))
                 {{$customer_business_name}},<br> @endif {{$customer}}')
                ->filterColumn('sale_invoice_no', function ($query, $keyword) {
                    $query->where('sale.invoice_no', 'like', ["%{$keyword}%"])
                          ->orWhere('stock_adjustment.ref_no', 'like', ["%{$keyword}%"]);
                })
                
                ->rawColumns(['subtotal', 'selling_price', 'quantity', 'purchase_price', 'sale_invoice_no', 'purchase_ref_no', 'supplier', 'customer', 'product_name', 'purchase_date', 'sell_date'])
                ->make(true);
        }

        $suppliers = Contact::suppliersDropdown($business_id, false);
        $customers = Contact::customersDropdown($business_id, false);
        $business_locations = BusinessLocation::forDropdown($business_id);
        $categories = Category::forDropdown($business_id, 'product');
        return view('report.items_report')->with(compact('suppliers', 'customers', 'business_locations', 'categories'));
    }

    /**
     * Full CSV export of the Items Report — bypasses DataTables pagination
     * so Sarah gets every matching row, not just the current page. Mirrors
     * the filter logic from itemsReport() (purchased + manual items, dates,
     * supplier, customer, location, category, sub-category).
     */
    public function itemsReportExport()
    {
        $business_id = request()->session()->get('user.business_id');

        $purchased_items_query = TransactionSellLinesPurchaseLines::join('purchase_lines as PL', 'PL.id', '=', 'transaction_sell_lines_purchase_lines.purchase_line_id')
            ->join('transactions as purchase', 'PL.transaction_id', '=', 'purchase.id')
            ->leftJoin('transaction_sell_lines as SL', 'SL.id', '=', 'transaction_sell_lines_purchase_lines.sell_line_id')
            ->leftJoin('stock_adjustment_lines as SAL', 'SAL.id', '=', 'transaction_sell_lines_purchase_lines.stock_adjustment_line_id')
            ->leftJoin('transactions as sale', 'SL.transaction_id', '=', 'sale.id')
            ->leftJoin('transactions as stock_adjustment', 'SAL.transaction_id', '=', 'stock_adjustment.id')
            ->join('business_locations as bl', 'purchase.location_id', '=', 'bl.id')
            ->join('variations as v', 'PL.variation_id', '=', 'v.id')
            ->join('product_variations as pv', 'v.product_variation_id', '=', 'pv.id')
            ->join('products as p', 'PL.product_id', '=', 'p.id')
            ->join('units as u', 'p.unit_id', '=', 'u.id')
            ->leftJoin('categories as cat', 'p.category_id', '=', 'cat.id')
            ->leftJoin('categories as sub_cat', 'p.sub_category_id', '=', 'sub_cat.id')
            ->leftJoin('contacts as suppliers', 'purchase.contact_id', '=', 'suppliers.id')
            ->leftJoin('contacts as customers', 'sale.contact_id', '=', 'customers.id')
            ->where('purchase.business_id', $business_id)
            ->select(
                'v.sub_sku as sku',
                'p.type as product_type',
                'p.name as product_name',
                'p.format as format',
                'v.name as variation_name',
                'pv.name as product_variation',
                'u.short_name as unit',
                'cat.name as category',
                'sub_cat.name as sub_category',
                'purchase.transaction_date as purchase_date',
                'purchase.ref_no as purchase_ref_no',
                'purchase.type as purchase_type',
                'suppliers.name as supplier',
                'suppliers.supplier_business_name',
                'PL.purchase_price_inc_tax as purchase_price',
                'sale.transaction_date as sell_date',
                'stock_adjustment.transaction_date as stock_adjustment_date',
                'sale.invoice_no as sale_invoice_no',
                'stock_adjustment.ref_no as stock_adjustment_ref_no',
                'customers.name as customer',
                'customers.supplier_business_name as customer_business_name',
                'transaction_sell_lines_purchase_lines.quantity as quantity',
                'SL.unit_price_inc_tax as selling_price',
                'SAL.unit_price as stock_adjustment_price',
                'transaction_sell_lines_purchase_lines.sell_line_id',
                'bl.name as location',
                'SL.sell_line_note',
                'PL.lot_number'
            );

        $manual_items_query = TransactionSellLine::join('transactions as sale', 'transaction_sell_lines.transaction_id', '=', 'sale.id')
            ->join('business_locations as bl', 'sale.location_id', '=', 'bl.id')
            ->leftJoin('categories as cat', 'transaction_sell_lines.category_id', '=', 'cat.id')
            ->leftJoin('categories as sub_cat', 'transaction_sell_lines.sub_category_id', '=', 'sub_cat.id')
            ->leftJoin('contacts as customers', 'sale.contact_id', '=', 'customers.id')
            ->where('sale.business_id', $business_id)
            ->where('sale.type', 'sell')
            ->where('sale.status', 'final')
            ->where(function ($q) {
                $q->whereNull('transaction_sell_lines.product_id')
                    ->orWhere('transaction_sell_lines.product_id', 0);
            })
            ->whereNotNull('transaction_sell_lines.product_name')
            ->select(
                DB::raw('NULL as sku'),
                DB::raw("'single' as product_type"),
                'transaction_sell_lines.product_name as product_name',
                DB::raw('NULL as format'),
                DB::raw('NULL as variation_name'),
                DB::raw('NULL as product_variation'),
                DB::raw("'pcs' as unit"),
                'cat.name as category',
                'sub_cat.name as sub_category',
                DB::raw('NULL as purchase_date'),
                DB::raw("'Manual Item' as purchase_ref_no"),
                DB::raw("'manual' as purchase_type"),
                DB::raw('NULL as supplier'),
                DB::raw('NULL as supplier_business_name'),
                DB::raw('0 as purchase_price'),
                'sale.transaction_date as sell_date',
                DB::raw('NULL as stock_adjustment_date'),
                'sale.invoice_no as sale_invoice_no',
                DB::raw('NULL as stock_adjustment_ref_no'),
                'customers.name as customer',
                'customers.supplier_business_name as customer_business_name',
                'transaction_sell_lines.quantity as quantity',
                'transaction_sell_lines.unit_price_inc_tax as selling_price',
                DB::raw('NULL as stock_adjustment_price'),
                'transaction_sell_lines.id as sell_line_id',
                'bl.name as location',
                'transaction_sell_lines.sell_line_note',
                DB::raw('NULL as lot_number')
            );

        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $purchased_items_query->whereIn('purchase.location_id', $permitted_locations);
            $manual_items_query->whereIn('sale.location_id', $permitted_locations);
        }

        if (!empty(request()->purchase_start) && !empty(request()->purchase_end)) {
            $start = request()->purchase_start;
            $end = request()->purchase_end;
            $purchased_items_query->whereBetween(DB::raw('DATE(purchase.transaction_date)'), [$start, $end]);
        }

        if (!empty(request()->sale_start) && !empty(request()->sale_end)) {
            $start = request()->sale_start;
            $end = request()->sale_end;
            $purchased_items_query->where(function ($q) use ($start, $end) {
                $q->where(function ($qr) use ($start, $end) {
                    $qr->whereNotNull('sale.transaction_date')
                        ->whereBetween(DB::raw('DATE(sale.transaction_date)'), [$start, $end]);
                })->orWhere(function ($qr) use ($start, $end) {
                    $qr->whereNotNull('stock_adjustment.transaction_date')
                        ->whereBetween(DB::raw('DATE(stock_adjustment.transaction_date)'), [$start, $end]);
                });
            });
            $manual_items_query->whereBetween(DB::raw('DATE(sale.transaction_date)'), [$start, $end]);
        }

        if (!empty(request()->supplier_id)) {
            $purchased_items_query->where('suppliers.id', request()->supplier_id);
        }
        if (!empty(request()->customer_id)) {
            $purchased_items_query->where('customers.id', request()->customer_id);
            $manual_items_query->where('customers.id', request()->customer_id);
        }
        if (!empty(request()->location_id)) {
            $purchased_items_query->where('purchase.location_id', request()->location_id);
            $manual_items_query->where('sale.location_id', request()->location_id);
        }
        if (!empty(request()->only_mfg_products)) {
            $purchased_items_query->where('purchase.type', 'production_purchase');
        }
        if (!empty(request()->category_id)) {
            $purchased_items_query->where('p.category_id', request()->category_id);
            $manual_items_query->where('transaction_sell_lines.category_id', request()->category_id);
        }
        if (!empty(request()->sub_category_id)) {
            $purchased_items_query->where('p.sub_category_id', request()->sub_category_id);
            $manual_items_query->where('transaction_sell_lines.sub_category_id', request()->sub_category_id);
        }

        $only_manual_items = request()->get('only_manual_items', 0);
        if (!empty($only_manual_items) && ($only_manual_items == 1 || $only_manual_items == '1')) {
            $rows = $manual_items_query->orderByDesc('sale.transaction_date')->get();
        } else {
            // Run each query and merge — UNION + Eloquent ordering doesn't play
            // nicely across heterogeneous selects, and the export is one-shot
            // so we don't need DB-level pagination.
            $purchased = $purchased_items_query->get();
            $manual = $manual_items_query->get();
            $rows = $purchased->concat($manual)->sortByDesc(function ($r) {
                return $r->sell_date ?: ($r->stock_adjustment_date ?: $r->purchase_date);
            })->values();
        }

        $filename = 'items-report-' . now()->format('Y-m-d-Hi') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Product', 'Format', 'SKU', 'Category', 'Sub-category', 'Description',
                'Purchase Date', 'Purchase Ref', 'Lot Number', 'Supplier', 'Purchase Price',
                'Sell Date', 'Sale Invoice', 'Customer', 'Location',
                'Quantity', 'Unit', 'Selling Price', 'Subtotal',
            ]);
            foreach ($rows as $r) {
                $product_name = $r->product_name;
                if ($r->product_type == 'variable' && !empty($r->product_variation)) {
                    $product_name .= ' - ' . $r->product_variation . ' - ' . $r->variation_name;
                }
                if ($r->purchase_type == 'manual') {
                    $product_name .= ' (Manual)';
                }

                $supplier = trim(($r->supplier_business_name ? $r->supplier_business_name . ', ' : '') . ($r->supplier ?? ''), ', ');
                $customer = trim(($r->customer_business_name ? $r->customer_business_name . ', ' : '') . ($r->customer ?? ''), ', ');

                $sell_date = $r->sell_date ?: $r->stock_adjustment_date;
                $sale_invoice = !empty($r->sell_line_id) ? $r->sale_invoice_no : (($r->stock_adjustment_ref_no ?? '') . ' (stock adjustment)');

                $selling_price = ($r->purchase_type == 'manual')
                    ? $r->selling_price
                    : (!empty($r->sell_line_id) ? $r->selling_price : $r->stock_adjustment_price);
                $subtotal = (float) $selling_price * (float) $r->quantity;

                $purchase_price_out = (float) $r->purchase_price;
                if ($purchase_price_out <= 0) {
                    $default = self::categoryDefaultCost($r->category);
                    if ($default !== null) {
                        $purchase_price_out = $default;
                    }
                }
                $purchase_price_csv = $purchase_price_out > 0
                    ? $purchase_price_out
                    : ($r->purchase_type == 'manual' ? '' : $r->purchase_price);

                fputcsv($out, [
                    $product_name,
                    $r->format,
                    $r->sku,
                    $r->category,
                    $r->sub_category,
                    $r->sell_line_note,
                    $r->purchase_type == 'manual' ? '' : $r->purchase_date,
                    $r->purchase_ref_no,
                    $r->lot_number,
                    $supplier,
                    $purchase_price_csv,
                    $sell_date,
                    $sale_invoice,
                    $customer,
                    $r->location,
                    (float) $r->quantity,
                    $r->unit ?? 'pcs',
                    $selling_price,
                    $subtotal,
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Shows purchase report
     *
     * @return \Illuminate\Http\Response
     */
    public function purchaseReport()
    {
        if ((!auth()->user()->can('purchase.view') && !auth()->user()->can('purchase.create') && !auth()->user()->can('view_own_purchase')) || empty(config('constants.show_report_606'))) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = request()->session()->get('user.business_id');
        if (request()->ajax()) {
            $payment_types = $this->transactionUtil->payment_types(null, true, $business_id);
            $purchases = Transaction::leftJoin('contacts', 'transactions.contact_id', '=', 'contacts.id')
                    ->join(
                        'business_locations AS BS',
                        'transactions.location_id',
                        '=',
                        'BS.id'
                    )
                    ->leftJoin(
                        'transaction_payments AS TP',
                        'transactions.id',
                        '=',
                        'TP.transaction_id'
                    )
                    ->where('transactions.business_id', $business_id)
                    ->where('transactions.type', 'purchase')
                    ->with(['payment_lines'])
                    ->select(
                        'transactions.id',
                        'transactions.ref_no',
                        'contacts.name',
                        'contacts.contact_id',
                        'BS.name as location_name',
                        'final_total',
                        'total_before_tax',
                        'discount_amount',
                        'discount_type',
                        'tax_amount',
                        DB::raw('DATE_FORMAT(transaction_date, "%Y/%m") as purchase_year_month'),
                        DB::raw('DATE_FORMAT(transaction_date, "%d") as purchase_day')
                    )
                    ->groupBy('transactions.id');

            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $purchases->whereIn('transactions.location_id', $permitted_locations);
            }

            if (!empty(request()->supplier_id)) {
                $purchases->where('contacts.id', request()->supplier_id);
            }
            if (!empty(request()->location_id)) {
                $purchases->where('transactions.location_id', request()->location_id);
            }
            if (!empty(request()->input('payment_status')) && request()->input('payment_status') != 'overdue') {
                $purchases->where('transactions.payment_status', request()->input('payment_status'));
            } elseif (request()->input('payment_status') == 'overdue') {
                $purchases->whereIn('transactions.payment_status', ['due', 'partial'])
                    ->whereNotNull('transactions.pay_term_number')
                    ->whereNotNull('transactions.pay_term_type')
                    ->whereRaw("IF(transactions.pay_term_type='days', DATE_ADD(transactions.transaction_date, INTERVAL transactions.pay_term_number DAY) < CURDATE(), DATE_ADD(transactions.transaction_date, INTERVAL transactions.pay_term_number MONTH) < CURDATE())");
            }

            if (!empty(request()->status)) {
                $purchases->where('transactions.status', request()->status);
            }
            
            if (!empty(request()->start_date) && !empty(request()->end_date)) {
                $start = request()->start_date;
                $end =  request()->end_date;
                $purchases->whereDate('transactions.transaction_date', '>=', $start)
                            ->whereDate('transactions.transaction_date', '<=', $end);
            }

            if (!auth()->user()->can('purchase.view') && auth()->user()->can('view_own_purchase')) {
                $purchases->where('transactions.created_by', request()->session()->get('user.id'));
            }

            return Datatables::of($purchases)
                ->removeColumn('id')
                ->editColumn(
                    'final_total',
                    '<span class="display_currency final_total" data-currency_symbol="true" data-orig-value="{{$final_total}}">{{$final_total}}</span>'
                )
                ->editColumn(
                    'total_before_tax',
                    '<span class="display_currency total_before_tax" data-currency_symbol="true" data-orig-value="{{$total_before_tax}}">{{$total_before_tax}}</span>'
                )
                ->editColumn(
                    'tax_amount',
                    '<span class="display_currency tax_amount" data-currency_symbol="true" data-orig-value="{{$tax_amount}}">{{$tax_amount}}</span>'
                )
                ->editColumn(
                    'discount_amount',
                    function ($row) {
                        $discount = !empty($row->discount_amount) ? $row->discount_amount : 0;

                        if (!empty($discount) && $row->discount_type == 'percentage') {
                            $discount = $row->total_before_tax * ($discount / 100);
                        }

                        return '<span class="display_currency total-discount" data-currency_symbol="true" data-orig-value="' . $discount . '">' . $discount . '</span>';
                    }
                )
                ->addColumn('payment_year_month', function ($row) {
                    $year_month = '';
                    if (!empty($row->payment_lines->first())) {
                        $year_month = \Carbon::parse($row->payment_lines->first()->paid_on)->format('Y/m');
                    }
                    return $year_month;
                })
                ->addColumn('payment_day', function ($row) {
                    $payment_day = '';
                    if (!empty($row->payment_lines->first())) {
                        $payment_day = \Carbon::parse($row->payment_lines->first()->paid_on)->format('d');
                    }
                    return $payment_day;
                })
                ->addColumn('payment_method', function ($row) use ($payment_types) {
                    $methods = array_unique($row->payment_lines->pluck('method')->toArray());
                    $count = count($methods);
                    $payment_method = '';
                    if ($count == 1) {
                        $payment_method = $payment_types[$methods[0]];
                    } elseif ($count > 1) {
                        $payment_method = __('lang_v1.checkout_multi_pay');
                    }

                    $html = !empty($payment_method) ? '<span class="payment-method" data-orig-value="' . $payment_method . '" data-status-name="' . $payment_method . '">' . $payment_method . '</span>' : '';
                    
                    return $html;
                })
                ->setRowAttr([
                    'data-href' => function ($row) {
                        if (auth()->user()->can("purchase.view")) {
                            return  action('PurchaseController@show', [$row->id]) ;
                        } else {
                            return '';
                        }
                    }])
                ->rawColumns(['final_total', 'total_before_tax', 'tax_amount', 'discount_amount', 'payment_method'])
                ->make(true);
        }

        $business_locations = BusinessLocation::forDropdown($business_id);
        $suppliers = Contact::suppliersDropdown($business_id, false);
        $orderStatuses = $this->productUtil->orderStatuses();

        return view('report.purchase_report')
            ->with(compact('business_locations', 'suppliers', 'orderStatuses'));
    }

    /**
     * Full CSV export of the Purchase Report — bypasses DataTables pagination
     * so Sabina gets every matching row, not just the current 100-row page
     * (2026-04-21 ask). Same filter logic as purchaseReport() above, minus
     * pagination, with all purchase_lines expanded (one item per row).
     */
    public function purchaseReportExport()
    {
        if ((!auth()->user()->can('purchase.view') && !auth()->user()->can('purchase.create') && !auth()->user()->can('view_own_purchase')) || empty(config('constants.show_report_606'))) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = request()->session()->get('user.business_id');

        $q = \DB::table('transactions as t')
            ->join('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->leftJoin('contacts as c', 't.contact_id', '=', 'c.id')
            ->leftJoin('purchase_lines as pl', 'pl.transaction_id', '=', 't.id')
            ->leftJoin('products as p', 'pl.product_id', '=', 'p.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'purchase');

        $permitted = auth()->user()->permitted_locations();
        if ($permitted !== 'all') {
            $q->whereIn('t.location_id', $permitted);
        }
        if (!empty(request()->supplier_id)) $q->where('c.id', request()->supplier_id);
        if (!empty(request()->location_id)) $q->where('t.location_id', request()->location_id);
        if (!empty(request()->status)) $q->where('t.status', request()->status);
        if (!empty(request()->payment_status) && request()->payment_status !== 'overdue') {
            $q->where('t.payment_status', request()->payment_status);
        }
        if (!empty(request()->start_date) && !empty(request()->end_date)) {
            $q->whereDate('t.transaction_date', '>=', request()->start_date)
              ->whereDate('t.transaction_date', '<=', request()->end_date);
        }
        if (!auth()->user()->can('purchase.view') && auth()->user()->can('view_own_purchase')) {
            $q->where('t.created_by', request()->session()->get('user.id'));
        }

        $rows = $q->orderByDesc('t.transaction_date')
            ->select(
                't.transaction_date', 't.ref_no', 't.status', 't.payment_status',
                't.total_before_tax', 't.discount_amount', 't.tax_amount', 't.final_total',
                'bl.name as location_name',
                'c.name as supplier_name', 'c.contact_id as supplier_contact_id',
                'p.name as product_name', 'p.artist as product_artist', 'p.sku as product_sku',
                'pl.quantity as line_quantity', 'pl.purchase_price as line_purchase_price'
            )
            ->get();

        $filename = 'purchase-report-' . now()->format('Y-m-d-Hi') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'Date', 'Ref No', 'Location', 'Supplier', 'Supplier Contact ID',
                'Status', 'Payment Status',
                'Product', 'Artist', 'SKU',
                'Line Qty', 'Line Unit Cost', 'Line Subtotal',
                'Purchase Total Before Tax', 'Purchase Discount', 'Purchase Tax', 'Purchase Final Total',
            ]);
            foreach ($rows as $r) {
                $lineSubtotal = ($r->line_quantity !== null && $r->line_purchase_price !== null)
                    ? ($r->line_quantity * $r->line_purchase_price) : null;
                fputcsv($out, [
                    $r->transaction_date,
                    $r->ref_no,
                    $r->location_name,
                    $r->supplier_name,
                    $r->supplier_contact_id,
                    $r->status,
                    $r->payment_status,
                    $r->product_name,
                    $r->product_artist,
                    $r->product_sku,
                    $r->line_quantity,
                    $r->line_purchase_price,
                    $lineSubtotal,
                    $r->total_before_tax,
                    $r->discount_amount,
                    $r->tax_amount,
                    $r->final_total,
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * JSON side-by-side summary for the purchase report — $ spent + purchase
     * count + top 5 products per location. Driven by the same filters as the
     * main DataTable so cashiers can compare Hollywood vs Pico at a glance
     * (Sarah / Sabina's 2026-04-21 request).
     */
    public function purchaseReportSummary()
    {
        if ((!auth()->user()->can('purchase.view') && !auth()->user()->can('purchase.create') && !auth()->user()->can('view_own_purchase')) || empty(config('constants.show_report_606'))) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = request()->session()->get('user.business_id');

        // Mirror the filter logic from purchaseReport() so the summary stays
        // consistent with the DataTable shown below it.
        $baseQuery = \DB::table('transactions as t')
            ->join('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->leftJoin('contacts as c', 't.contact_id', '=', 'c.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'purchase');

        $permitted = auth()->user()->permitted_locations();
        if ($permitted !== 'all') {
            $baseQuery->whereIn('t.location_id', $permitted);
        }
        if (!empty(request()->supplier_id)) {
            $baseQuery->where('c.id', request()->supplier_id);
        }
        if (!empty(request()->location_id)) {
            $baseQuery->where('t.location_id', request()->location_id);
        }
        if (!empty(request()->status)) {
            $baseQuery->where('t.status', request()->status);
        }
        if (!empty(request()->payment_status) && request()->payment_status !== 'overdue') {
            $baseQuery->where('t.payment_status', request()->payment_status);
        }
        if (!empty(request()->start_date) && !empty(request()->end_date)) {
            $baseQuery->whereDate('t.transaction_date', '>=', request()->start_date)
                      ->whereDate('t.transaction_date', '<=', request()->end_date);
        }
        if (!auth()->user()->can('purchase.view') && auth()->user()->can('view_own_purchase')) {
            $baseQuery->where('t.created_by', request()->session()->get('user.id'));
        }

        // Per-location totals.
        $byLocation = (clone $baseQuery)
            ->select(
                'bl.id as location_id',
                'bl.name as location_name',
                \DB::raw('COUNT(DISTINCT t.id) as purchase_count'),
                \DB::raw('COALESCE(SUM(t.final_total), 0) as total_spent'),
                \DB::raw('COALESCE(SUM(t.total_before_tax), 0) as total_before_tax')
            )
            ->groupBy('bl.id', 'bl.name')
            ->orderByDesc('total_spent')
            ->get();

        // Bulk-bin / clearance SKUs that flood the top-products list with
        // useless rows ("DISCOUNT BIN ($1)", "Various Artists — Hip Hop
        // Clearance", etc.) — Sarah 2026-04-22 called the old output "not
        // helping" because these drowned out the actual interesting
        // purchases (real artists, real albums). We pull them out into a
        // separate "bulk bin" bucket and surface real purchases by $ spent
        // instead of by qty.
        $binFilters = [
            'p.name LIKE ?', 'p.name LIKE ?', 'p.name LIKE ?',
            'p.artist LIKE ?', 'p.artist LIKE ?',
        ];
        $binBindings = [
            '%DISCOUNT BIN%',
            '%Clearance%',
            '%Discount Bin%',
            'Various Artists%',
            'VARIOUS%',
        ];

        foreach ($byLocation as $loc) {
            $lineQuery = \DB::table('transactions as t')
                ->join('purchase_lines as pl', 'pl.transaction_id', '=', 't.id')
                ->join('products as p', 'pl.product_id', '=', 'p.id')
                ->where('t.business_id', $business_id)
                ->where('t.type', 'purchase')
                ->where('t.location_id', $loc->location_id)
                ->when(!empty(request()->start_date) && !empty(request()->end_date), function ($q) {
                    $q->whereDate('t.transaction_date', '>=', request()->start_date)
                      ->whereDate('t.transaction_date', '<=', request()->end_date);
                })
                ->when(!empty(request()->supplier_id), function ($q) {
                    $q->where('t.contact_id', request()->supplier_id);
                });

            // Top REAL products — exclude the bin filters, sort by $ spent
            // so a $1k Thriller order floats above a 1000-unit clearance
            // pallet. This is the list Sabina actually wants to eyeball.
            $loc->top_products = (clone $lineQuery)
                ->where(function ($q) use ($binFilters, $binBindings) {
                    foreach ($binFilters as $i => $clause) {
                        $q->where(function ($qq) use ($clause, $binBindings, $i) {
                            $qq->whereRaw('NOT (' . $clause . ')', [$binBindings[$i]]);
                        });
                    }
                })
                ->groupBy('p.id', 'p.name', 'p.artist')
                ->select(
                    'p.name',
                    'p.artist',
                    \DB::raw('SUM(pl.quantity) as qty'),
                    \DB::raw('SUM(pl.quantity * pl.purchase_price) as spent')
                )
                ->orderByDesc('spent')
                ->limit(8)
                ->get();

            // Bulk-bin totals — one summary row so the bin volume is still
            // visible (Sarah still needs to know "we dropped $X on clearance
            // bins this month") without hogging the top-products slot.
            $bin = (clone $lineQuery)
                ->where(function ($q) use ($binFilters, $binBindings) {
                    $q->where(function ($inner) use ($binFilters, $binBindings) {
                        foreach ($binFilters as $i => $clause) {
                            $inner->orWhereRaw($clause, [$binBindings[$i]]);
                        }
                    });
                })
                ->selectRaw('COALESCE(SUM(pl.quantity), 0) as qty,
                             COALESCE(SUM(pl.quantity * pl.purchase_price), 0) as spent')
                ->first();
            $loc->bin_summary = $bin ? [
                'qty' => (int) ($bin->qty ?? 0),
                'spent' => (float) ($bin->spent ?? 0),
            ] : ['qty' => 0, 'spent' => 0];

            // Top suppliers by $ spent — usually the more actionable cut
            // for Sabina ("who we bought from" > "what we bought") because
            // purchasing reviews start with vendor relationships.
            $loc->top_suppliers = \DB::table('transactions as t')
                ->leftJoin('contacts as c', 't.contact_id', '=', 'c.id')
                ->where('t.business_id', $business_id)
                ->where('t.type', 'purchase')
                ->where('t.location_id', $loc->location_id)
                ->when(!empty(request()->start_date) && !empty(request()->end_date), function ($q) {
                    $q->whereDate('t.transaction_date', '>=', request()->start_date)
                      ->whereDate('t.transaction_date', '<=', request()->end_date);
                })
                ->groupBy('c.id', 'c.name', 'c.supplier_business_name')
                ->select(
                    'c.name',
                    'c.supplier_business_name',
                    \DB::raw('COUNT(t.id) as purchase_count'),
                    \DB::raw('COALESCE(SUM(t.final_total), 0) as spent')
                )
                ->orderByDesc('spent')
                ->limit(5)
                ->get();

            // Distinct-products count so "bought 47 unique albums" is
            // visible alongside "1413 purchases" — tells very different
            // stories.
            $distinct = (clone $lineQuery)
                ->select(\DB::raw('COUNT(DISTINCT p.id) as n'))
                ->first();
            $loc->distinct_products = (int) ($distinct->n ?? 0);

            // Walk-in / collection-buy split — Sarah 2026-04-22 asked to
            // separate in-store buy-from-customer transactions from
            // distributor invoices so she can see "how much we spent
            // buying used records off walk-in customers" without it
            // being buried under supplier totals. BuyFromCustomerController
            // stamps "Buy from customer" into additional_notes when
            // creating the purchase txn; we count matches there + the
            // generic walk-in / customer contact names that legacy
            // workflows use.
            $walkinQ = \DB::table('transactions as t')
                ->leftJoin('contacts as c', 't.contact_id', '=', 'c.id')
                ->where('t.business_id', $business_id)
                ->where('t.type', 'purchase')
                ->where('t.location_id', $loc->location_id)
                ->when(!empty(request()->start_date) && !empty(request()->end_date), function ($q) {
                    $q->whereDate('t.transaction_date', '>=', request()->start_date)
                      ->whereDate('t.transaction_date', '<=', request()->end_date);
                })
                ->where(function ($q) {
                    $q->where('t.additional_notes', 'like', 'Buy from customer%')
                      ->orWhereRaw("LOWER(COALESCE(c.name,'')) IN ('walk-in', 'walkin customer', 'walk in customer', 'customer')")
                      ->orWhere('c.name', 'like', 'Walk-In%');
                });
            $walkin = (clone $walkinQ)
                ->selectRaw('COUNT(DISTINCT t.id) as cnt, COALESCE(SUM(t.final_total), 0) as spent')
                ->first();
            $loc->walkin_summary = [
                'count' => (int) ($walkin->cnt ?? 0),
                'spent' => (float) ($walkin->spent ?? 0),
            ];

            // Distributor = everything in this location's purchase total
            // that ISN'T a walk-in. Derive by subtraction so the two
            // channel chips always add up to the card's Total spent.
            $distributorCount = max(0, ((int) $loc->purchase_count) - (int) ($walkin->cnt ?? 0));
            $distributorSpent = max(0, ((float) $loc->total_spent) - (float) ($walkin->spent ?? 0));
            $loc->distributor_summary = [
                'count' => $distributorCount,
                'spent' => $distributorSpent,
            ];
        }

        return response()->json(['locations' => $byLocation]);
    }

    /**
     * Shows sale report
     *
     * @return \Illuminate\Http\Response
     */
    public function saleReport()
    {
        // Product Sell Report aggregates revenue by product — admin-only
        // (Sarah 2026-04-28). Note: this is the report-page entry, not the
        // sell-listing screens used by cashiers.
        $this->ensureAdminOnlyReportAccess();
        if (empty(config('constants.show_report_607'))) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $business_locations = BusinessLocation::forDropdown($business_id, false);
        $customers = Contact::customersDropdown($business_id, false);

        return view('report.sale_report')
            ->with(compact('business_locations', 'customers'));
    }

    /**
     * Calculates stock values
     *
     * @return array
     */
    public function getStockValue()
    {
        $business_id = request()->session()->get('user.business_id');
        $end_date = \Carbon::now()->format('Y-m-d');
        $location_id = request()->input('location_id');
        $filters = request()->only(['category_id', 'sub_category_id', 'brand_id', 'unit_id']);
        //Get Closing stock
        $closing_stock_by_pp = $this->transactionUtil->getOpeningClosingStock(
            $business_id,
            $end_date,
            $location_id,
            false,
            false,
            $filters
        );
        $closing_stock_by_sp = $this->transactionUtil->getOpeningClosingStock(
            $business_id,
            $end_date,
            $location_id,
            false,
            true,
            $filters
        );
        $potential_profit = $closing_stock_by_sp - $closing_stock_by_pp;
        $profit_margin = empty($closing_stock_by_sp) ? 0 : ($potential_profit / $closing_stock_by_sp) * 100;

        return [
            'closing_stock_by_pp' => $closing_stock_by_pp,
            'closing_stock_by_sp' => $closing_stock_by_sp,
            'potential_profit' => $potential_profit,
            'profit_margin' => $profit_margin
        ];
    }

    public function activityLog()
    {
        $business_id = request()->session()->get('user.business_id');
        $transaction_types = [
            'contact' => __('report.contact'),
            'user' => __('report.user'),
            'sell' => __('sale.sale'),
            'purchase' => __('lang_v1.purchase'),
            'sales_order' => __('lang_v1.sales_order'),
            'purchase_order' => __('lang_v1.purchase_order'),
            'sell_return' => __('lang_v1.sell_return'),
            'purchase_return' => __('lang_v1.purchase_return'),
            'sell_transfer' => __('lang_v1.stock_transfer'),
            'stock_adjustment' => __('stock_adjustment.stock_adjustment'),
            'expense' => __('lang_v1.expense')
        ];

        if (request()->ajax()) {
            $activities = Activity::with(['subject'])
                                ->leftjoin('users as u', 'u.id', '=', 'activity_log.causer_id')
                                ->where('activity_log.business_id', $business_id)
                                ->select(
                                    'activity_log.*',
                                    DB::raw("CONCAT(COALESCE(u.surname, ''), ' ', COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as created_by")
                                );

            if (!empty(request()->start_date) && !empty(request()->end_date)) {
                $start = request()->start_date;
                $end =  request()->end_date;
                $activities->whereDate('activity_log.created_at', '>=', $start)
                            ->whereDate('activity_log.created_at', '<=', $end);
            }

            if (!empty(request()->user_id)) {
                $activities->where('causer_id', request()->user_id);
            }

            $subject_type = request()->subject_type;
            if (!empty($subject_type)) {
                if ($subject_type == 'contact') {
                    $activities->where('subject_type', 'App\Contact');
                } else if($subject_type == 'user') {
                    $activities->where('subject_type', 'App\User');
                } else if(in_array($subject_type, ['sell', 'purchase', 
                    'sales_order', 'purchase_order', 'sell_return', 'purchase_return', 'sell_transfer', 'expense', 'purchase_order'])) {
                    $activities->where('subject_type', 'App\Transaction');
                    $activities->whereHasMorph('subject', Transaction::class, function($q) use($subject_type){
                        $q->where('type', $subject_type);
                    });
                }
            }

            $sell_statuses = Transaction::sell_statuses();
            $sales_order_statuses = Transaction::sales_order_statuses(true);
            $purchase_statuses = $this->transactionUtil->orderStatuses();
            $shipping_statuses = $this->transactionUtil->shipping_statuses();

            $statuses = array_merge($sell_statuses, $sales_order_statuses, $purchase_statuses);
            return Datatables::of($activities)
                            ->editColumn('created_at', '{{@format_datetime($created_at)}}')
                            ->addColumn('subject_type', function($row) use($transaction_types) {
                                    $subject_type = '';
                                    if ($row->subject_type == 'App\Contact') {
                                        $subject_type = __('contact.contact');
                                    } else if ($row->subject_type == 'App\User') {
                                        $subject_type = __('report.user');
                                    } else if ($row->subject_type == 'App\Transaction' && !empty($row->subject->type)) {
                                        $subject_type = isset($transaction_types[$row->subject->type]) ? $transaction_types[$row->subject->type] : '';
                                    } elseif (($row->subject_type == 'App\TransactionPayment')) {
                                       $subject_type = __('lang_v1.payment');
                                    }
                                return $subject_type;
                            })
                            ->addColumn('note', function($row) use ($statuses, $shipping_statuses){
                                $html = '';
                                if (!empty($row->subject->ref_no)) {
                                    $html .= __('purchase.ref_no') . ': ' . $row->subject->ref_no . '<br>';
                                }
                                if (!empty($row->subject->invoice_no)) {
                                    $html .= __('sale.invoice_no') . ': ' . $row->subject->invoice_no . '<br>';
                                }
                                if($row->subject_type == 'App\Transaction' && !empty($row->subject) && in_array($row->subject->type, ['sell', 'purchase'])) {
                                    $html .= view('sale_pos.partials.activity_row', ['activity' => $row, 'statuses' => $statuses, 'shipping_statuses' => $shipping_statuses])->render();
                                } else {
                                    $update_note = $row->getExtraProperty('update_note');
                                    if(!empty($update_note) && !is_array($update_note)) {
                                        $html .= $update_note;
                                    }
                                }

                                if ($row->description == 'contact_deleted') {
                                    $html .= $row->getExtraProperty('supplier_business_name') ?? ''; 
                                    $html .= '<br>'; 
                                }

                                if (!empty($row->getExtraProperty('name'))) {
                                    $html .= __('user.name') . ': ' . $row->getExtraProperty('name') . '<br>';
                                }

                                if (!empty($row->getExtraProperty('id'))) {
                                    $html .= 'id: ' . $row->getExtraProperty('id') . '<br>';
                                }
                                if (!empty($row->getExtraProperty('invoice_no'))) {
                                    $html .= __('sale.invoice_no') . ': ' . $row->getExtraProperty('invoice_no');
                                }

                                if (!empty($row->getExtraProperty('ref_no'))) {
                                    $html .= __('purchase.ref_no') . ': ' . $row->getExtraProperty('ref_no');
                                }

                                return $html;
                            })
                            ->filterColumn('created_by', function ($query, $keyword) {
                                $query->whereRaw("CONCAT(COALESCE(surname, ''), ' ', COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) like ?", ["%{$keyword}%"]);
                            })
                            ->editColumn('description', function($row) {
                                return __('lang_v1.' . $row->description);
                            })
                            ->rawColumns(['note'])
                            ->make(true);
        }

        $users = User::allUsersDropdown($business_id, false);

        return view('report.activity_log')->with(compact('users', 'transaction_types'));

                           
    }
    
    public function categorySalesReport(Request $request){
        // Sales-by-category is an aggregated revenue report — admin-only
        // (Sarah 2026-04-28).
        $this->ensureAdminOnlyReportAccess();

        
        if($request->ajax()){

            $start_date = $request->start_date;
        $end_date = $request->end_date;
        // Convert to DateTime objects
        $startDateTime = DateTime::createFromFormat('Y-m-d', $start_date);
        $endDateTime = DateTime::createFromFormat('Y-m-d', $end_date);

        // Calculate the duration of the given period
        $interval = $startDateTime->diff($endDateTime);

        // Find the end date of the previous period (1 day before the given start date)
        $previousPeriodEndDateTime = (clone $startDateTime)->modify('-1 day');

        // Calculate the start date of the previous period by subtracting the interval
        $previousPeriodStartDateTime = (clone $previousPeriodEndDateTime)->sub($interval);

        // Format the dates back to strings
        $previousPeriodStartDate = $previousPeriodStartDateTime->format('Y-m-d');
        $previousPeriodEndDate = $previousPeriodEndDateTime->format('Y-m-d');
        $taxonomy = $request->taxonomy;
        $location = $request->location;
//--------------------------------------------Current Sales Start---------------------------------------------------------------------

            if($taxonomy == 1){
                $transactions = Category::join('products', 'products.category_id', '=', 'categories.id');
            }elseif($taxonomy == 2){
                $transactions = Category::join('products', 'products.sub_category_id', '=', 'categories.id');
            }else{
                $transactions = DB::table('brands as categories')->join('products', 'products.brand_id', '=', 'categories.id');
            }

         $transactions = $transactions->select(
                                'categories.id as category_id',
                                'categories.name',
                                'categories.parent_id',
                                DB::raw('SUM(CASE WHEN DATE(transactions.transaction_date) >= "'.$start_date.'" AND DATE(transactions.transaction_date) <=  "'.$end_date.'" AND transactions.type = "sell" THEN transaction_sell_lines.quantity * variations.dpp_inc_tax ELSE 0 END) AS total_cost_available'),
                                DB::raw('SUM(CASE WHEN DATE(transactions.transaction_date) >=  "'.$start_date.'" AND DATE(transactions.transaction_date) <= "'.$end_date.'" AND transactions.type = "sell" THEN transaction_sell_lines.quantity ELSE 0 END) AS total_quantity_sold'),
                                DB::raw('SUM(CASE WHEN DATE(transactions.transaction_date) >=  "'.$start_date.'" AND DATE(transactions.transaction_date) <= "'.$end_date.'" AND transactions.type = "sell" THEN (transaction_sell_lines.quantity * transaction_sell_lines.unit_price_inc_tax) ELSE 0 END) AS total_net_sales_rps'),
                                DB::raw('SUM(CASE WHEN DATE(transactions.transaction_date) >=  "'.$start_date.'" AND DATE(transactions.transaction_date) <= "'.$end_date.'" AND transactions.type = "sell" THEN transaction_sell_lines.quantity ELSE 0 END) - SUM(CASE WHEN DATE(transactions.transaction_date) >= "'.$start_date.'" AND DATE(transactions.transaction_date) <= "'.$end_date.'" AND transactions.type = "sell_return" THEN transaction_sell_lines.quantity ELSE 0 END) AS net_sales_quantity')
                            )
                            ->join('variations', 'variations.product_id', '=', 'products.id')
                            ->leftJoin('transaction_sell_lines', 'transaction_sell_lines.product_id', '=', 'products.id')
                            ->leftJoin('transactions', function($join) use($start_date, $end_date) {
                                $join->on('transaction_sell_lines.transaction_id', '=', 'transactions.id')
                                    ->where('transactions.type', '=', 'sell')
                                   ->where('transactions.status', '=', 'final')
                                    ->whereBetween(DB::raw('DATE(transactions.transaction_date)'), [$start_date, $end_date]);
                            });

                            if(isset($location) && $location != '' && $location != null){
                                $transactions = $transactions->where('transactions.location_id', $location);
                            }

                         $transactions = $transactions->groupBy('categories.id')->orderBy('categories.name')->get();

           
//--------------------------------------------Current Sales End---------------------------------------------------------------------

//--------------------------------------------Current Sales Return Start---------------------------------------------------------------------
                            if($taxonomy == 1){
                                $totalQuantityReturned = Category::join('products', 'products.category_id', '=', 'categories.id');
                            }elseif($taxonomy == 2){
                                $totalQuantityReturned = Category::join('products', 'products.sub_category_id', '=', 'categories.id');
                            }else{
                                $totalQuantityReturned = DB::table('brands as categories')->join('products', 'products.brand_id', '=', 'categories.id');
                            }
                            $totalQuantityReturned = $totalQuantityReturned->join('transaction_sell_lines', 'transaction_sell_lines.product_id', '=', 'products.id')
                                                        ->join('transactions', function ($join) use($start_date, $end_date) {
                                                            $join->on('transaction_sell_lines.transaction_id', '=', 'transactions.id')
                                                                ->where('transactions.type', '=', 'sell_return')
                                                                ->whereBetween(DB::raw('DATE(transactions.transaction_date)'), [$start_date, $end_date]);
                                                        })
                                                        ->select('categories.id',
                                                        DB::raw('SUM(transaction_sell_lines.quantity) AS total_quantity_returned'),
                                                        DB::raw('SUM(transaction_sell_lines.quantity * transaction_sell_lines.unit_price_inc_tax) AS total_net_sales_returned'),
                                                    );
                                if(isset($location) && $location != '' && $location != null){
                                    $totalQuantityReturned = $totalQuantityReturned->where('transactions.location_id', $location);
                                }
                                $totalQuantityReturned = $totalQuantityReturned->groupBy('categories.id')->get();

 //--------------------------------------------Current Sales Return End---------------------------------------------------------------------


 //--------------------------------------------Location Stock---------------------------------------------------------------------
            
                      
                    if($taxonomy == 1){
                            $totalQuantityAvailable = Category::join('products', 'products.category_id', '=', 'categories.id');
                        }elseif($taxonomy == 2){
                            $totalQuantityAvailable = Category::join('products', 'products.sub_category_id', '=', 'categories.id');
                        }else{
                            $totalQuantityAvailable = DB::table('brands as categories')->join('products', 'products.brand_id', '=', 'categories.id');
                        }
                        
                        $totalQuantityAvailable = $totalQuantityAvailable->join('variation_location_details', 'variation_location_details.product_id', '=', 'products.id')
                                                ->join('variations', 'variations.product_id', 'products.id')
                                                ->select('categories.id',DB::raw('SUM(variation_location_details.qty_available) AS total_quantity_available'), DB::raw('SUM(variation_location_details.qty_available * variations.dpp_inc_tax) AS total_cost_available'));
                        if(isset($location) && $location != '' && $location != null){
                            $totalQuantityAvailable = $totalQuantityAvailable->where('variation_location_details.location_id', $location);
                        }                                                
                        $totalQuantityAvailable = $totalQuantityAvailable->groupBy('categories.id')
                                                ->get();
 //--------------------------------------------Location Stock End---------------------------------------------------------------------


                   $start_date = $previousPeriodStartDate;  
                   $end_date =   $previousPeriodEndDate;            
//-----------------------------------------Preview Sales Start------------------------------------------------------------------------
                    if($taxonomy == 1){
                        $previoustransactions = Category::join('products', 'products.category_id', '=', 'categories.id');
                    }elseif($taxonomy == 2){
                        $previoustransactions = Category::join('products', 'products.sub_category_id', '=', 'categories.id');
                    }else{
                        $previoustransactions = DB::table('brands as categories')->join('products', 'products.brand_id', '=', 'categories.id');
                    }

                    $previoustransactions = $previoustransactions->select(
                    'categories.id',
                    'categories.name',
                    DB::raw('SUM(CASE WHEN DATE(transactions.transaction_date) >= "'.$start_date.'" AND DATE(transactions.transaction_date) <=  "'.$end_date.'" AND transactions.type = "sell" THEN transaction_sell_lines.quantity * variations.dpp_inc_tax ELSE 0 END) AS total_cost_available'),
                    DB::raw('SUM(CASE WHEN DATE(transactions.transaction_date) >=  "'.$start_date.'" AND DATE(transactions.transaction_date) <= "'.$end_date.'" AND transactions.type = "sell" THEN transaction_sell_lines.quantity ELSE 0 END) AS total_quantity_sold'),
                    DB::raw('SUM(CASE WHEN DATE(transactions.transaction_date) >=  "'.$start_date.'" AND DATE(transactions.transaction_date) <= "'.$end_date.'" AND transactions.type = "sell" THEN (transaction_sell_lines.quantity * transaction_sell_lines.unit_price_inc_tax) ELSE 0 END) AS total_net_sales_rps'),
                    DB::raw('SUM(CASE WHEN DATE(transactions.transaction_date) >=  "'.$start_date.'" AND DATE(transactions.transaction_date) <= "'.$end_date.'" AND transactions.type = "sell" THEN transaction_sell_lines.quantity ELSE 0 END) - SUM(CASE WHEN DATE(transactions.transaction_date) >= "'.$start_date.'" AND DATE(transactions.transaction_date) <= "'.$end_date.'" AND transactions.type = "sell_return" THEN transaction_sell_lines.quantity ELSE 0 END) AS net_sales_quantity')
                    )
                    ->join('variations', 'variations.product_id', '=', 'products.id')
                    ->leftJoin('transaction_sell_lines', 'transaction_sell_lines.product_id', '=', 'products.id')
                    ->leftJoin('transactions', function($join) use($start_date, $end_date) {
                    $join->on('transaction_sell_lines.transaction_id', '=', 'transactions.id')
                        ->where('transactions.type', '=', 'sell')
                        ->whereIn('transactions.shipping_status', ['Packed', 'Dispatched', 'Sale Return', 'Delivery Failed'])
                        ->whereBetween(DB::raw('DATE(transactions.transaction_date)'), [$start_date, $end_date]);
                    });

                    if(isset($location) && $location != '' && $location != null){
                    $previoustransactions = $previoustransactions->where('transactions.location_id', $location);
                    }

                    $previoustransactions = $previoustransactions->groupBy('categories.id')->get();
//--------------------------------------------Previous Sales End---------------------------------------------------------------------

//--------------------------------------------Previous Sales Return Start---------------------------------------------------------------------

                if($taxonomy == 1){
                    $totalQuantityReturnedPrevious = Category::join('products', 'products.category_id', '=', 'categories.id');
                }elseif($taxonomy == 2){
                    $totalQuantityReturnedPrevious = Category::join('products', 'products.sub_category_id', '=', 'categories.id');
                }else{
                    $totalQuantityReturnedPrevious = DB::table('brands as categories')->join('products', 'products.brand_id', '=', 'categories.id');
                }
                $totalQuantityReturnedPrevious = $totalQuantityReturnedPrevious->join('transaction_sell_lines', 'transaction_sell_lines.product_id', '=', 'products.id')
                                            ->join('transactions', function ($join) use($start_date, $end_date) {
                                                $join->on('transaction_sell_lines.transaction_id', '=', 'transactions.id')
                                                    ->where('transactions.type', '=', 'sell_return')
                                                    ->whereBetween(DB::raw('DATE(transactions.transaction_date)'), [$start_date, $end_date]);
                                            })
                                            ->select('categories.id',
                                            DB::raw('SUM(transaction_sell_lines.quantity) AS total_quantity_returned'),
                                            DB::raw('SUM(transaction_sell_lines.quantity * transaction_sell_lines.unit_price_inc_tax) AS total_net_sales_returned'),
                                        );
                    if(isset($location) && $location != '' && $location != null){
                        $totalQuantityReturnedPrevious = $totalQuantityReturnedPrevious->where('transactions.location_id', $location);
                    }
                    $totalQuantityReturnedPrevious = $totalQuantityReturnedPrevious->groupBy('categories.id')->get();
//--------------------------------------------Previous Sales Return End---------------------------------------------------------------------
        $sumTotalOrders = $totalQuantityAvailable->sum('total_quantity_available');
        $totalCost = $totalQuantityAvailable->sum('total_cost_available');
        $total_qty_sold = $transactions->sum('total_quantity_sold');
        $total_qty_net_sold = $transactions->sum('total_quantity_sold') - $totalQuantityReturned->sum('total_quantity_returned');
        $total_net_sales_rps = $transactions->sum('total_net_sales_rps');
        $total_net_sales_rps_final = $transactions->sum('total_net_sales_rps') - $totalQuantityReturned->sum('total_net_sales_returned');

        return Datatables::of($transactions)
            ->addColumn(
                'name',
                function ($row){
                    if($row->parent_id){
                        $cat = \App\Category::find($row->parent_id);
                        if($cat)
                        return $row->name." ( Parent Cat: ".$cat->name." ) ";
                    }
                    return $row->name;
                }
            )
            ->addColumn(
              'total_quantity_available',
              function ($row) use($totalQuantityAvailable) {
                if(isset($totalQuantityAvailable->where('id', $row->category_id)->first()->total_quantity_available)){
                return number_format($totalQuantityAvailable->where('id', $row->category_id)->first()->total_quantity_available, '0', '.', ',');
                }else{
                    return number_format(0, '2', '.', ',');
                }
            }
            )
            ->addColumn(
                'total_cost_available',
                function ($row) use($totalQuantityAvailable) {
                    if(isset($totalQuantityAvailable->where('id', $row->category_id)->first()->total_cost_available)){
                    return number_format($totalQuantityAvailable->where('id', $row->category_id)->first()->total_cost_available, '2', '.', ',');
                    }else{
                        return number_format(0, '2', '.', ',');
                    }
                }
              )
              ->addColumn(
                'total_quantity_sold',
                function ($row) {
                    return number_format($row->total_quantity_sold, '2', '.', ',');
                }
              )
              ->addColumn(
                'total_net_sales_rps',
                function ($row) {
                    return number_format($row->total_net_sales_rps, '2', '.', ',');
                }
              )
              
            
            
              ->with('footer', [
                'sumTotalOrders' => number_format($sumTotalOrders, '0', '.', ','),
                'totalCost' => number_format($totalCost, '0', '.', ','),
                
                           ])
            ->make(true);
    
          }
          
        $business_locations = BusinessLocation::forDropdown(auth()->user()->business_id, true);

        return view('report.categoryreport', compact('business_locations'));
    }

    /**
     * Admin-only: Inventory valuation summary.
     */
    public function inventoryValuationSummary(Request $request)
    {
        // Open to all staff — inventory valuation is operational, not
        // aggregated sales (Sarah 2026-04-28).
        $business_id = $request->session()->get('user.business_id');

        if ($request->ajax()) {
            $query = ProductStockCache::where('business_id', $business_id)
                ->where('enable_stock', 1)
                ->select(
                    'product_stock_cache.id',
                    'product',
                    'sku',
                    'location_name',
                    'unit',
                    'stock',
                    'stock_price',
                    'unit_price',
                    'calculated_at'
                );

            if (!empty($request->input('location_id'))) {
                $query->where('location_id', $request->input('location_id'));
            }
            if (!empty($request->input('category_id'))) {
                $query->where('category_id', $request->input('category_id'));
            }
            if (!empty($request->input('brand_id'))) {
                $query->where('brand_id', $request->input('brand_id'));
            }

            return Datatables::of($query)
                ->addColumn('cost_per_unit', function ($row) {
                    $stock = (float) $row->stock;
                    if ($stock <= 0) {
                        return 0;
                    }

                    return (float) $row->stock_price / $stock;
                })
                ->make(true);
        }

        $business_locations = BusinessLocation::forDropdown($business_id, true);
        $categories = Category::forDropdown($business_id, 'product');
        $brands = Brands::forDropdown($business_id);

        return view('report.inventory_valuation_summary')
            ->with(compact('business_locations', 'categories', 'brands'));
    }

    /**
     * Admin-only: Inventory valuation detail (cost layers).
     */
    public function inventoryValuationDetail(Request $request)
    {
        // Open to all staff — inventory line-level valuation is operational
        // (Sarah 2026-04-28).
        $business_id = $request->session()->get('user.business_id');

        if ($request->ajax()) {
            $query = DB::table('purchase_lines as pl')
                ->join('transactions as t', 'pl.transaction_id', '=', 't.id')
                ->join('variations as v', 'pl.variation_id', '=', 'v.id')
                ->join('products as p', 'v.product_id', '=', 'p.id')
                ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
                ->leftJoin('contacts as c', 't.contact_id', '=', 'c.id')
                ->where('t.business_id', $business_id)
                ->whereIn('t.type', ['purchase', 'opening_stock', 'purchase_transfer'])
                ->select(
                    'pl.id',
                    't.transaction_date',
                    't.ref_no',
                    'p.name as product',
                    'v.sub_sku as sku',
                    'bl.name as location_name',
                    'c.name as vendor_name',
                    'pl.lot_number',
                    'pl.quantity',
                    'pl.quantity_sold',
                    'pl.quantity_adjusted',
                    'pl.quantity_returned',
                    'pl.purchase_price_inc_tax as unit_cost',
                    DB::raw('GREATEST((pl.quantity - COALESCE(pl.quantity_sold, 0) - COALESCE(pl.quantity_adjusted, 0) - COALESCE(pl.quantity_returned, 0)), 0) as remaining_qty'),
                    DB::raw('(GREATEST((pl.quantity - COALESCE(pl.quantity_sold, 0) - COALESCE(pl.quantity_adjusted, 0) - COALESCE(pl.quantity_returned, 0)), 0) * pl.purchase_price_inc_tax) as remaining_value')
                );

            if (!empty($request->input('location_id'))) {
                $query->where('t.location_id', $request->input('location_id'));
            }
            if (!empty($request->input('start_date'))) {
                $query->whereDate('t.transaction_date', '>=', $request->input('start_date'));
            }
            if (!empty($request->input('end_date'))) {
                $query->whereDate('t.transaction_date', '<=', $request->input('end_date'));
            }

            return Datatables::of($query)->make(true);
        }

        $business_locations = BusinessLocation::forDropdown($business_id, true);
        return view('report.inventory_valuation_detail')->with(compact('business_locations'));
    }

    /**
     * Admin-only: Sales by item with cost & margin.
     */
    public function salesByItemCostMargin(Request $request)
    {
        $this->ensureAccountantReportAdminAccess();
        $business_id = $request->session()->get('user.business_id');

        if ($request->ajax()) {
            // Build the cost-per-unit SQL expression once. When the Nivessa
            // COGS fallback is enabled, missing/0 purchase prices are filled
            // from the category-based assumption map (config/nivessa_cogs.php)
            // so rows for products with N/A purchase price still contribute
            // meaningfully to COGS, gross margin, and margin %. Without this,
            // Lashyn's accountant saw wrong COGS because the system was
            // silently dropping thousands of N/A rows.
            $costExpr = \App\Helpers\CogsFallback::isEnabled()
                ? \App\Helpers\CogsFallback::costWithFallback('pl.purchase_price_inc_tax', 'sc.name', 'c.name')
                : 'COALESCE(pl.purchase_price_inc_tax, 0)';

            $query = DB::table('transaction_sell_lines as tsl')
                ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
                ->join('variations as v', 'tsl.variation_id', '=', 'v.id')
                ->join('products as p', 'v.product_id', '=', 'p.id')
                ->leftJoin('product_variations as pv', 'v.product_variation_id', '=', 'pv.id')
                ->leftJoin('transaction_sell_lines_purchase_lines as tspl', 'tsl.id', '=', 'tspl.sell_line_id')
                ->leftJoin('purchase_lines as pl', 'tspl.purchase_line_id', '=', 'pl.id')
                // Category joins so the COGS fallback CASE can inspect the
                // sub-category (primary) and main category (fallback) names.
                ->leftJoin('categories as sc', 'p.sub_category_id', '=', 'sc.id')
                ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
                ->where('t.business_id', $business_id)
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->whereNull('t.return_parent_id')
                ->select(
                    'p.id as product_id',
                    'v.id as variation_id',
                    'p.name as product',
                    'v.sub_sku as sku',
                    DB::raw("CONCAT(COALESCE(pv.name, ''), CASE WHEN v.name IS NULL OR v.name = 'DUMMY' THEN '' ELSE CONCAT(' - ', v.name) END) as variation"),
                    DB::raw('SUM(tsl.quantity - tsl.quantity_returned) as qty_sold'),
                    DB::raw('SUM((tsl.quantity - tsl.quantity_returned) * tsl.unit_price_inc_tax) as revenue'),
                    DB::raw("SUM(COALESCE(tspl.quantity, 0) * COALESCE({$costExpr}, 0)) as cost"),
                    DB::raw("(SUM((tsl.quantity - tsl.quantity_returned) * tsl.unit_price_inc_tax) - SUM(COALESCE(tspl.quantity, 0) * COALESCE({$costExpr}, 0))) as gross_margin"),
                    DB::raw("IF(SUM((tsl.quantity - tsl.quantity_returned) * tsl.unit_price_inc_tax) > 0, ((SUM((tsl.quantity - tsl.quantity_returned) * tsl.unit_price_inc_tax) - SUM(COALESCE(tspl.quantity, 0) * COALESCE({$costExpr}, 0))) / SUM((tsl.quantity - tsl.quantity_returned) * tsl.unit_price_inc_tax)) * 100, 0) as margin_percent"),
                    // Flag so the UI can distinguish "real" COGS rows from
                    // assumption-based ones. 1 = at least one sold line had
                    // no purchase price and the fallback kicked in.
                    DB::raw('MAX(CASE WHEN pl.purchase_price_inc_tax IS NULL OR pl.purchase_price_inc_tax = 0 THEN 1 ELSE 0 END) as cost_is_assumed')
                )
                ->groupBy('p.id', 'v.id', 'p.name', 'v.sub_sku', 'pv.name', 'v.name');

            if (!empty($request->input('location_id'))) {
                $query->where('t.location_id', $request->input('location_id'));
            }
            if (!empty($request->input('start_date'))) {
                $query->whereDate('t.transaction_date', '>=', $request->input('start_date'));
            }
            if (!empty($request->input('end_date'))) {
                $query->whereDate('t.transaction_date', '<=', $request->input('end_date'));
            }

            return Datatables::of($query)->make(true);
        }

        $business_locations = BusinessLocation::forDropdown($business_id, true);
        return view('report.sales_item_cost_margin')->with(compact('business_locations'));
    }

    /**
     * Admin-only: Purchases by item/vendor.
     */
    public function purchasesByItemVendor(Request $request)
    {
        // Open to all staff — vendor purchase history (Sarah 2026-04-28).
        $business_id = $request->session()->get('user.business_id');

        if ($request->ajax()) {
            $query = DB::table('purchase_lines as pl')
                ->join('transactions as t', 'pl.transaction_id', '=', 't.id')
                ->join('variations as v', 'pl.variation_id', '=', 'v.id')
                ->join('products as p', 'v.product_id', '=', 'p.id')
                ->leftJoin('contacts as c', 't.contact_id', '=', 'c.id')
                ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
                ->where('t.business_id', $business_id)
                ->whereIn('t.type', ['purchase', 'opening_stock', 'purchase_transfer'])
                ->select(
                    'pl.id',
                    't.transaction_date',
                    't.ref_no',
                    'p.name as product',
                    'v.sub_sku as sku',
                    'c.name as vendor_name',
                    'bl.name as location_name',
                    'pl.quantity',
                    'pl.purchase_price_inc_tax as unit_cost',
                    DB::raw('(pl.quantity * pl.purchase_price_inc_tax) as total_cost')
                );

            if (!empty($request->input('supplier_id'))) {
                $query->where('t.contact_id', $request->input('supplier_id'));
            }
            if (!empty($request->input('location_id'))) {
                $query->where('t.location_id', $request->input('location_id'));
            }
            if (!empty($request->input('start_date'))) {
                $query->whereDate('t.transaction_date', '>=', $request->input('start_date'));
            }
            if (!empty($request->input('end_date'))) {
                $query->whereDate('t.transaction_date', '<=', $request->input('end_date'));
            }

            return Datatables::of($query)->make(true);
        }

        $business_locations = BusinessLocation::forDropdown($business_id, true);
        $suppliers = Contact::suppliersDropdown($business_id);
        return view('report.purchases_item_vendor')->with(compact('business_locations', 'suppliers'));
    }

    /**
     * Admin-only: ABC inventory classification.
     */
    public function abcInventoryClassification(Request $request)
    {
        // Open to all staff — inventory classification (Sarah 2026-04-28).
        $business_id = $request->session()->get('user.business_id');

        if ($request->ajax()) {
            $inventory_rows = DB::table('product_stock_cache as psc')
                ->where('psc.business_id', $business_id)
                ->where('psc.enable_stock', 1)
                ->select(
                    'psc.product_id',
                    DB::raw('MAX(psc.product) as product'),
                    DB::raw('MAX(psc.sku) as sku'),
                    DB::raw('SUM(psc.stock) as qty_on_hand'),
                    DB::raw('SUM(psc.stock_price) as inventory_value')
                )
                ->groupBy('psc.product_id')
                ->get();

            $sales_query = DB::table('transaction_sell_lines as tsl')
                ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
                ->join('variations as v', 'tsl.variation_id', '=', 'v.id')
                ->where('t.business_id', $business_id)
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->whereNull('t.return_parent_id')
                ->select(
                    'v.product_id',
                    DB::raw('SUM(tsl.quantity - tsl.quantity_returned) as qty_sold')
                )
                ->groupBy('v.product_id');

            if (!empty($request->input('start_date'))) {
                $sales_query->whereDate('t.transaction_date', '>=', $request->input('start_date'));
            }
            if (!empty($request->input('end_date'))) {
                $sales_query->whereDate('t.transaction_date', '<=', $request->input('end_date'));
            }

            $sales_map = $sales_query->pluck('qty_sold', 'product_id')->toArray();
            $rows = $inventory_rows->map(function ($row) use ($sales_map) {
                $row->qty_sold = isset($sales_map[$row->product_id]) ? (float) $sales_map[$row->product_id] : 0;
                return $row;
            })->sortByDesc('inventory_value')->values();

            $total_value = (float) $rows->sum('inventory_value');
            $running = 0;
            $classified = [];
            foreach ($rows as $row) {
                $value = (float) $row->inventory_value;
                $running += $value;
                $cumulative_pct = $total_value > 0 ? ($running / $total_value) * 100 : 0;

                if ($cumulative_pct <= 80) {
                    $class = 'A';
                } elseif ($cumulative_pct <= 95) {
                    $class = 'B';
                } else {
                    $class = 'C';
                }

                $classified[] = [
                    'product' => $row->product,
                    'sku' => $row->sku,
                    'qty_on_hand' => (float) $row->qty_on_hand,
                    'qty_sold' => (float) $row->qty_sold,
                    'inventory_value' => $value,
                    'cumulative_value_pct' => round($cumulative_pct, 2),
                    'abc_class' => $class,
                ];
            }

            return Datatables::of(collect($classified))->make(true);
        }

        return view('report.abc_inventory_classification');
    }

    /**
     * Admin-only: Inventory aging summary.
     */
    public function inventoryAgingSummary(Request $request)
    {
        // Open to all staff — inventory aging is operational
        // (Sarah 2026-04-28).
        $business_id = $request->session()->get('user.business_id');

        if ($request->ajax()) {
            $as_of = !empty($request->input('as_of_date')) ? $request->input('as_of_date') : date('Y-m-d');

            $query = DB::table('purchase_lines as pl')
                ->join('transactions as t', 'pl.transaction_id', '=', 't.id')
                ->join('variations as v', 'pl.variation_id', '=', 'v.id')
                ->join('products as p', 'v.product_id', '=', 'p.id')
                ->where('t.business_id', $business_id)
                ->whereIn('t.type', ['purchase', 'opening_stock', 'purchase_transfer'])
                ->select(
                    'p.id as product_id',
                    'p.name as product',
                    'v.sub_sku as sku',
                    DB::raw("SUM(CASE WHEN DATEDIFF('{$as_of}', DATE(t.transaction_date)) BETWEEN 0 AND 30 THEN GREATEST((pl.quantity - COALESCE(pl.quantity_sold, 0) - COALESCE(pl.quantity_adjusted, 0) - COALESCE(pl.quantity_returned, 0)), 0) ELSE 0 END) as qty_0_30"),
                    DB::raw("SUM(CASE WHEN DATEDIFF('{$as_of}', DATE(t.transaction_date)) BETWEEN 31 AND 60 THEN GREATEST((pl.quantity - COALESCE(pl.quantity_sold, 0) - COALESCE(pl.quantity_adjusted, 0) - COALESCE(pl.quantity_returned, 0)), 0) ELSE 0 END) as qty_31_60"),
                    DB::raw("SUM(CASE WHEN DATEDIFF('{$as_of}', DATE(t.transaction_date)) BETWEEN 61 AND 90 THEN GREATEST((pl.quantity - COALESCE(pl.quantity_sold, 0) - COALESCE(pl.quantity_adjusted, 0) - COALESCE(pl.quantity_returned, 0)), 0) ELSE 0 END) as qty_61_90"),
                    DB::raw("SUM(CASE WHEN DATEDIFF('{$as_of}', DATE(t.transaction_date)) > 90 THEN GREATEST((pl.quantity - COALESCE(pl.quantity_sold, 0) - COALESCE(pl.quantity_adjusted, 0) - COALESCE(pl.quantity_returned, 0)), 0) ELSE 0 END) as qty_90_plus"),
                    DB::raw("SUM(GREATEST((pl.quantity - COALESCE(pl.quantity_sold, 0) - COALESCE(pl.quantity_adjusted, 0) - COALESCE(pl.quantity_returned, 0)), 0) * pl.purchase_price_inc_tax) as total_value")
                )
                ->groupBy('p.id', 'p.name', 'v.sub_sku');

            if (!empty($request->input('location_id'))) {
                $query->where('t.location_id', $request->input('location_id'));
            }

            return Datatables::of($query)->make(true);
        }

        $business_locations = BusinessLocation::forDropdown($business_id, true);
        return view('report.inventory_aging_summary')->with(compact('business_locations'));
    }

    /**
     * Admin-only: Landed cost summary.
     */
    public function landedCostSummary(Request $request)
    {
        // Open to all staff — landed cost rollup, no aggregated sales
        // (Sarah 2026-04-28).
        $business_id = $request->session()->get('user.business_id');

        if ($request->ajax()) {
            $query = DB::table('transactions as t')
                ->join('contacts as c', 't.contact_id', '=', 'c.id')
                ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
                ->where('t.business_id', $business_id)
                ->whereIn('t.type', ['purchase', 'opening_stock'])
                ->select(
                    't.id',
                    't.transaction_date',
                    't.ref_no',
                    'c.name as supplier_name',
                    'bl.name as location_name',
                    't.total_before_tax',
                    't.final_total',
                    't.tax_amount',
                    't.shipping_charges',
                    't.additional_expense_key_1',
                    't.additional_expense_key_2',
                    't.additional_expense_key_3',
                    't.additional_expense_key_4',
                    't.additional_expense_value_1',
                    't.additional_expense_value_2',
                    't.additional_expense_value_3',
                    't.additional_expense_value_4',
                    DB::raw('(COALESCE(t.shipping_charges,0) + COALESCE(t.additional_expense_value_1,0) + COALESCE(t.additional_expense_value_2,0) + COALESCE(t.additional_expense_value_3,0) + COALESCE(t.additional_expense_value_4,0)) as landed_addons'),
                    DB::raw('(COALESCE(t.final_total,0) + COALESCE(t.shipping_charges,0) + COALESCE(t.additional_expense_value_1,0) + COALESCE(t.additional_expense_value_2,0) + COALESCE(t.additional_expense_value_3,0) + COALESCE(t.additional_expense_value_4,0)) as landed_total')
                );

            if (!empty($request->input('supplier_id'))) {
                $query->where('t.contact_id', $request->input('supplier_id'));
            }
            if (!empty($request->input('location_id'))) {
                $query->where('t.location_id', $request->input('location_id'));
            }
            if (!empty($request->input('start_date'))) {
                $query->whereDate('t.transaction_date', '>=', $request->input('start_date'));
            }
            if (!empty($request->input('end_date'))) {
                $query->whereDate('t.transaction_date', '<=', $request->input('end_date'));
            }

            return Datatables::of($query)
                ->addColumn('addons_pct', function ($row) {
                    $base = (float) $row->final_total;
                    $addons = (float) $row->landed_addons;
                    if ($base <= 0) {
                        return 0;
                    }

                    return round(($addons / $base) * 100, 2);
                })
                ->make(true);
        }

        $business_locations = BusinessLocation::forDropdown($business_id, true);
        $suppliers = Contact::suppliersDropdown($business_id);
        return view('report.landed_cost_summary')->with(compact('business_locations', 'suppliers'));
    }

    /**
     * Admin-only: Purchase order vs received.
     */
    public function purchaseOrderVsReceived(Request $request)
    {
        // Open to all staff — PO tracking (Sarah 2026-04-28).
        $business_id = $request->session()->get('user.business_id');

        if ($request->ajax()) {
            $query = DB::table('transactions as t')
                ->join('purchase_lines as pl', 't.id', '=', 'pl.transaction_id')
                ->join('variations as v', 'pl.variation_id', '=', 'v.id')
                ->join('products as p', 'v.product_id', '=', 'p.id')
                ->leftJoin('contacts as c', 't.contact_id', '=', 'c.id')
                ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
                ->where('t.business_id', $business_id)
                ->where('t.type', 'purchase_order')
                ->select(
                    'pl.id',
                    't.transaction_date',
                    't.ref_no',
                    'c.name as supplier_name',
                    'bl.name as location_name',
                    'p.name as product',
                    'v.sub_sku as sku',
                    'pl.quantity as ordered_qty',
                    'pl.po_quantity_purchased as received_qty',
                    DB::raw('GREATEST((pl.quantity - COALESCE(pl.po_quantity_purchased, 0)), 0) as pending_qty'),
                    't.status'
                );

            if (!empty($request->input('supplier_id'))) {
                $query->where('t.contact_id', $request->input('supplier_id'));
            }
            if (!empty($request->input('location_id'))) {
                $query->where('t.location_id', $request->input('location_id'));
            }
            if (!empty($request->input('start_date'))) {
                $query->whereDate('t.transaction_date', '>=', $request->input('start_date'));
            }
            if (!empty($request->input('end_date'))) {
                $query->whereDate('t.transaction_date', '<=', $request->input('end_date'));
            }

            return Datatables::of($query)->make(true);
        }

        $business_locations = BusinessLocation::forDropdown($business_id, true);
        $suppliers = Contact::suppliersDropdown($business_id);
        return view('report.purchase_order_vs_received')->with(compact('business_locations', 'suppliers'));
    }

    /**
     * Admin-only: Item transaction history.
     */
    public function itemTransactionHistory(Request $request)
    {
        // Open to all staff — single-item movement audit (Sarah 2026-04-28).
        $business_id = $request->session()->get('user.business_id');

        if ($request->ajax()) {
            $product_id = $request->input('product_id');
            $location_id = $request->input('location_id');

            $purchases = DB::table('purchase_lines as pl')
                ->join('transactions as t', 'pl.transaction_id', '=', 't.id')
                ->join('variations as v', 'pl.variation_id', '=', 'v.id')
                ->join('products as p', 'v.product_id', '=', 'p.id')
                ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
                ->where('t.business_id', $business_id)
                ->whereIn('t.type', ['purchase', 'opening_stock', 'purchase_transfer'])
                ->select(
                    't.transaction_date',
                    't.ref_no',
                    'p.name as product',
                    'v.sub_sku as sku',
                    'bl.name as location_name',
                    DB::raw("'purchase' as txn_type"),
                    'pl.quantity as qty_in',
                    DB::raw('0 as qty_out'),
                    'pl.purchase_price_inc_tax as unit_cost'
                );

            $sales = DB::table('transaction_sell_lines as tsl')
                ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
                ->join('variations as v', 'tsl.variation_id', '=', 'v.id')
                ->join('products as p', 'v.product_id', '=', 'p.id')
                ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
                ->where('t.business_id', $business_id)
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->select(
                    't.transaction_date',
                    't.ref_no',
                    'p.name as product',
                    'v.sub_sku as sku',
                    'bl.name as location_name',
                    DB::raw("'sell' as txn_type"),
                    DB::raw('0 as qty_in'),
                    DB::raw('(tsl.quantity - tsl.quantity_returned) as qty_out'),
                    'tsl.unit_price_inc_tax as unit_cost'
                );

            if (!empty($product_id)) {
                $purchases->where('p.id', $product_id);
                $sales->where('p.id', $product_id);
            }
            if (!empty($location_id)) {
                $purchases->where('t.location_id', $location_id);
                $sales->where('t.location_id', $location_id);
            }
            if (!empty($request->input('start_date'))) {
                $purchases->whereDate('t.transaction_date', '>=', $request->input('start_date'));
                $sales->whereDate('t.transaction_date', '>=', $request->input('start_date'));
            }
            if (!empty($request->input('end_date'))) {
                $purchases->whereDate('t.transaction_date', '<=', $request->input('end_date'));
                $sales->whereDate('t.transaction_date', '<=', $request->input('end_date'));
            }

            $rows = $purchases->get()->merge($sales->get())->sortByDesc('transaction_date')->values();
            return Datatables::of($rows)->make(true);
        }

        $products = Product::where('business_id', $business_id)->pluck('name', 'id');
        $business_locations = BusinessLocation::forDropdown($business_id, true);
        return view('report.item_transaction_history')->with(compact('products', 'business_locations'));
    }

    /**
     * Employee productivity report for product additions.
     */
    public function productEntryProductivity(Request $request)
    {
        // Open to all staff — counts of products priced + purchases entered,
        // no $ figures (Sarah 2026-04-28).
        $business_id = $request->session()->get('user.business_id');
        $start_date = $request->input('start_date');
        $end_date = $request->input('end_date');
        if (empty($start_date) || empty($end_date)) {
            $today = \Carbon::today()->format('Y-m-d');
            $start_date = $start_date ?: $today;
            $end_date = $end_date ?: $today;
        }

        // Only include users who are allowed to log in and whose status is active.
        // Hides disabled / inactive / terminated accounts from the productivity report.
        $users = User::where('business_id', $business_id)
            ->where('allow_login', 1)
            ->where('status', 'active')
            ->select('id', DB::raw("CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) as full_name"))
            ->orderBy('first_name')
            ->get();

        $productsTableHasAddedVia = \Schema::hasColumn('products', 'added_via');
        $massAddQuery = Product::where('business_id', $business_id)
            ->whereDate('created_at', '>=', $start_date)
            ->whereDate('created_at', '<=', $end_date);
        if ($productsTableHasAddedVia) {
            $massAddQuery->where('added_via', 'mass_add');
        } else {
            // Backward compatibility: avoid SQL error before migration is applied.
            // Without added_via metadata, count is shown as 0 instead of failing.
            $massAddQuery->whereRaw('1 = 0');
        }
        $mass_add = $massAddQuery
            ->select('created_by', DB::raw('COUNT(*) as total'))
            ->groupBy('created_by')
            ->pluck('total', 'created_by');

        $purchase_add = DB::table('purchase_lines as pl')
            ->join('transactions as t', 'pl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->whereIn('t.type', ['purchase', 'opening_stock', 'purchase_transfer'])
            ->whereDate('t.transaction_date', '>=', $start_date)
            ->whereDate('t.transaction_date', '<=', $end_date)
            ->select('t.created_by', DB::raw('COUNT(pl.id) as total'))
            ->groupBy('t.created_by')
            ->pluck('total', 't.created_by');

        $rows = $users->map(function ($u) use ($mass_add, $purchase_add) {
            $m = (int) ($mass_add[$u->id] ?? 0);
            $p = (int) ($purchase_add[$u->id] ?? 0);
            return (object) [
                'user_id' => $u->id,
                'employee' => trim((string) $u->full_name),
                'mass_add_count' => $m,
                'purchase_add_count' => $p,
                'total_count' => $m + $p,
            ];
        })->sortByDesc('total_count')->values();

        // Daily summary cards for current day.
        $today = \Carbon::today()->format('Y-m-d');
        $todayMassAddQuery = Product::where('business_id', $business_id)
            ->whereDate('created_at', $today);
        if ($productsTableHasAddedVia) {
            $todayMassAddQuery->where('added_via', 'mass_add');
        } else {
            $todayMassAddQuery->whereRaw('1 = 0');
        }
        $today_mass_add = (int) $todayMassAddQuery->count();
        $today_purchase_add = (int) DB::table('purchase_lines as pl')
            ->join('transactions as t', 'pl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->whereIn('t.type', ['purchase', 'opening_stock', 'purchase_transfer'])
            ->whereDate('t.transaction_date', $today)
            ->count();

        return view('report.product_entry_productivity')->with(compact(
            'rows',
            'start_date',
            'end_date',
            'today_mass_add',
            'today_purchase_add'
        ));
    }

    /**
     * Dead Stock Report
     *
     * Shows variations that currently have stock on hand but haven't been sold
     * (or haven't been sold within the user-selected window). Helps identify
     * capital tied up in slow/dead inventory.
     */
    public function deadStockReport(Request $request)
    {
        // Open to all staff — flagging dead inventory is operational
        // (Sarah 2026-04-28).
        $business_id = $request->session()->get('user.business_id');

        // User-selectable: 90, 180, 365 days (default 180)
        $days = (int) $request->input('days', 180);
        if (!in_array($days, [30, 60, 90, 180, 365, 730])) {
            $days = 180;
        }

        $location_id = $request->input('location_id');
        $business_locations = BusinessLocation::forDropdown($business_id);

        $cutoff = \Carbon::now()->subDays($days)->toDateTimeString();

        // Last-sold subquery: MAX(transaction_date) per variation across finalized sells
        $lastSaleSub = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->select('tsl.variation_id', DB::raw('MAX(t.transaction_date) as last_sold'))
            ->groupBy('tsl.variation_id');

        // Date-acquired subquery: MIN(transaction_date) per variation across purchases
        // (purchase, opening_stock, purchase_transfer). Falls back to product created_at
        // later in the view when no purchase record exists.
        $acquiredSub = DB::table('purchase_lines as pl')
            ->join('transactions as t', 'pl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->whereIn('t.type', ['purchase', 'opening_stock', 'purchase_transfer'])
            ->select('pl.variation_id', DB::raw('MIN(t.transaction_date) as first_acquired'))
            ->groupBy('pl.variation_id');

        $query = DB::table('variations as v')
            ->join('products as p', 'v.product_id', '=', 'p.id')
            ->join('variation_location_details as vld', 'v.id', '=', 'vld.variation_id')
            ->leftJoin('units as u', 'p.unit_id', '=', 'u.id')
            ->leftJoinSub($lastSaleSub, 'ls', function ($join) {
                $join->on('v.id', '=', 'ls.variation_id');
            })
            ->leftJoinSub($acquiredSub, 'ac', function ($join) {
                $join->on('v.id', '=', 'ac.variation_id');
            })
            ->where('p.business_id', $business_id)
            ->where('p.type', '!=', 'modifier')
            ->where('vld.qty_available', '>', 0)
            ->whereNull('v.deleted_at')
            ->where(function ($q) use ($cutoff) {
                $q->whereNull('ls.last_sold')
                  ->orWhere('ls.last_sold', '<', $cutoff);
            });

        if (!empty($location_id)) {
            $query->where('vld.location_id', $location_id);
        }

        $query->select(
            'v.id as variation_id',
            'p.id as product_id',
            'p.artist',
            'p.name',
            'p.format',
            'p.created_at as product_created_at',
            'v.sub_sku',
            'vld.qty_available',
            'vld.location_id',
            'v.sell_price_inc_tax as selling_price',
            'ls.last_sold',
            'ac.first_acquired as date_acquired',
            DB::raw('DATEDIFF(NOW(), ls.last_sold) as days_since_sold'),
            DB::raw('DATEDIFF(NOW(), COALESCE(ac.first_acquired, p.created_at)) as days_on_hand'),
            DB::raw('(vld.qty_available * v.sell_price_inc_tax) as tied_up_value'),
            'u.short_name as unit'
        );

        // Totals across the full filtered set (before pagination + sort)
        $totals_base = (clone $query);
        $totals = DB::query()
            ->fromSub($totals_base, 'x')
            ->selectRaw('COUNT(*) as total_variations, COALESCE(SUM(qty_available), 0) as total_qty, COALESCE(SUM(tied_up_value), 0) as total_value')
            ->first();

        // Column sort: whitelist columns to prevent SQL injection
        $sort = $request->input('sort', 'tied_up_value');
        $dir  = strtolower($request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sort_map = [
            'artist'          => 'p.artist',
            'title'           => 'p.name',
            'format'          => 'p.format',
            'sku'             => 'v.sub_sku',
            'qty'             => 'vld.qty_available',
            'price'           => 'v.sell_price_inc_tax',
            'last_sold'       => 'ls.last_sold',
            'days_since'      => 'days_since_sold',
            'date_acquired'   => 'ac.first_acquired',
            'days_on_hand'    => 'days_on_hand',
            'tied_up_value'   => 'tied_up_value',
        ];
        $sort_col = $sort_map[$sort] ?? 'tied_up_value';
        if (in_array($sort_col, ['days_since_sold', 'days_on_hand', 'tied_up_value'])) {
            $query->orderByRaw($sort_col . ' ' . $dir);
        } else {
            $query->orderBy($sort_col, $dir);
        }

        $rows = $query->paginate(50)->appends($request->except('page'));

        return view('report.dead_stock_report')->with(compact(
            'rows', 'business_locations', 'days', 'location_id', 'totals', 'sort', 'dir'
        ));
    }

    /**
     * Whatnot Sales Report
     *
     * Compares Whatnot transactions vs non-Whatnot transactions for a given
     * date range + optional location. Shows totals, counts, and a daily
     * breakdown so the team can see live-auction revenue at a glance.
     */
    public function whatnotReport(Request $request)
    {
        // Channel-level sales rollup — admin-only (Sarah 2026-04-28).
        $this->ensureAdminOnlyReportAccess();

        $business_id = $request->session()->get('user.business_id');
        $start_date = $request->input('start_date');
        $end_date = $request->input('end_date');
        if (empty($start_date) || empty($end_date)) {
            $start_date = $start_date ?: \Carbon::now()->startOfMonth()->format('Y-m-d');
            $end_date = $end_date ?: \Carbon::now()->format('Y-m-d');
        }
        $location_id = $request->input('location_id');
        $business_locations = BusinessLocation::forDropdown($business_id);

        $base = DB::table('transactions as t')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereDate('t.transaction_date', '>=', $start_date)
            ->whereDate('t.transaction_date', '<=', $end_date);

        if (!empty($location_id)) {
            $base->where('t.location_id', $location_id);
        }

        // Summary rollups
        $whatnot = (clone $base)
            ->where('t.is_whatnot', 1)
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(t.final_total), 0) as total')
            ->first();
        $non = (clone $base)
            ->where(function ($q) { $q->where('t.is_whatnot', 0)->orWhereNull('t.is_whatnot'); })
            ->selectRaw('COUNT(*) as cnt, COALESCE(SUM(t.final_total), 0) as total')
            ->first();

        $overall_total = ((float)($whatnot->total ?? 0)) + ((float)($non->total ?? 0));
        $whatnot_pct = $overall_total > 0 ? ((float)$whatnot->total / $overall_total) * 100 : 0;

        // Column sort (whitelisted) — applies to whichever table has `sort_table` matching
        $sort = $request->input('sort');
        $dir  = strtolower($request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sort_table = $request->input('sort_table'); // 'daily' or 'top'

        // Daily breakdown
        $daily_q = (clone $base)
            ->selectRaw("DATE(t.transaction_date) as day,
                SUM(CASE WHEN t.is_whatnot = 1 THEN 1 ELSE 0 END) as whatnot_cnt,
                COALESCE(SUM(CASE WHEN t.is_whatnot = 1 THEN t.final_total ELSE 0 END), 0) as whatnot_total,
                SUM(CASE WHEN t.is_whatnot = 1 THEN 0 ELSE 1 END) as non_cnt,
                COALESCE(SUM(CASE WHEN t.is_whatnot = 1 THEN 0 ELSE t.final_total END), 0) as non_total")
            ->groupBy(DB::raw('DATE(t.transaction_date)'));

        $daily_sort_map = ['day' => 'day', 'whatnot_cnt' => 'whatnot_cnt', 'whatnot_total' => 'whatnot_total', 'non_cnt' => 'non_cnt', 'non_total' => 'non_total'];
        if ($sort_table === 'daily' && isset($daily_sort_map[$sort])) {
            $daily_q->orderByRaw($daily_sort_map[$sort] . ' ' . $dir);
        } else {
            $daily_q->orderByDesc('day');
        }
        $daily = $daily_q->get();

        // Top Whatnot sellers (by employee who created the transaction)
        $top_q = (clone $base)
            ->where('t.is_whatnot', 1)
            ->leftJoin('users as u', 't.created_by', '=', 'u.id')
            ->selectRaw("t.created_by, CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as employee,
                COUNT(*) as cnt, COALESCE(SUM(t.final_total), 0) as total")
            ->groupBy('t.created_by', 'u.first_name', 'u.last_name')
            ->limit(20);

        $top_sort_map = ['employee' => 'employee', 'cnt' => 'cnt', 'total' => 'total'];
        if ($sort_table === 'top' && isset($top_sort_map[$sort])) {
            $top_q->orderByRaw($top_sort_map[$sort] . ' ' . $dir);
        } else {
            $top_q->orderByDesc('total');
        }
        $top_sellers = $top_q->get();

        return view('report.whatnot_report')->with(compact(
            'whatnot', 'non', 'overall_total', 'whatnot_pct',
            'daily', 'top_sellers',
            'start_date', 'end_date', 'location_id', 'business_locations',
            'sort', 'dir', 'sort_table'
        ));
    }


    /**
     * Sales by Channel — date-range rollup of revenue + gross profit per
     * (location, channel) combination. Mirrors slide 3 of the monthly
     * business review deck so Sabina doesn't have to compile it by hand.
     *
     * One row per (location, channel) pair. Display label is the location
     * name plus a channel suffix when the channel is something other than
     * the in-store register (e.g. "Hollywood — Whatnot", "Pico — Whatnot").
     * Online channels (Discogs, eBay) are not tied to a physical store, so
     * they collapse to a single row labelled by channel only.
     *
     * Columns: revenue (final_total incl tax), share % of period, txn count,
     * gross profit (mirrors TransactionUtil::getGrossProfit math but grouped
     * per location+channel), and gross margin %.
     *
     * Operating profit and net profit per channel are intentionally NOT
     * computed here — they require expense-allocation rules (rent share,
     * payroll split, etc.) that we have not codified. Those columns are
     * surfaced as "—" with a footnote pointing at /reports/profit-loss.
     */
    public function salesByChannel(Request $request)
    {
        $this->ensureAdminOnlyReportAccess();

        $business_id = $request->session()->get('user.business_id');
        $start_date = $request->input('start_date');
        $end_date = $request->input('end_date');
        if (empty($start_date) || empty($end_date)) {
            $start_date = $start_date ?: \Carbon::now()->startOfMonth()->format('Y-m-d');
            $end_date = $end_date ?: \Carbon::now()->format('Y-m-d');
        }

        $business_locations = BusinessLocation::forDropdown($business_id);

        // Revenue + transaction count per (location, channel).
        $rev = DB::table('transactions as t')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereDate('t.transaction_date', '>=', $start_date)
            ->whereDate('t.transaction_date', '<=', $end_date)
            ->selectRaw("t.location_id, t.channel,
                COUNT(*) as cnt,
                COALESCE(SUM(t.final_total), 0) as revenue,
                COALESCE(SUM(t.total_before_tax), 0) as revenue_exc_tax")
            ->groupBy('t.location_id', 't.channel')
            ->get();

        // Gross profit per (location, channel). Mirrors getGrossProfit but
        // skips combo recursion (Nivessa's catalog isn't combo-based) so the
        // query stays cheap enough to group.
        $gp_rows = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as sale', 'tsl.transaction_id', '=', 'sale.id')
            ->leftJoin('transaction_sell_lines_purchase_lines as TSPL', 'tsl.id', '=', 'TSPL.sell_line_id')
            ->leftJoin('purchase_lines as PL', 'TSPL.purchase_line_id', '=', 'PL.id')
            ->where('sale.business_id', $business_id)
            ->where('sale.type', 'sell')
            ->where('sale.status', 'final')
            ->whereDate('sale.transaction_date', '>=', $start_date)
            ->whereDate('sale.transaction_date', '<=', $end_date)
            ->where('tsl.children_type', '!=', 'combo')
            ->selectRaw("sale.location_id, sale.channel,
                COALESCE(SUM((TSPL.quantity - TSPL.qty_returned) *
                    (tsl.unit_price_inc_tax - PL.purchase_price_inc_tax)), 0) as gross_profit")
            ->groupBy('sale.location_id', 'sale.channel')
            ->get()
            ->keyBy(function ($r) { return $r->location_id . '|' . $r->channel; });

        // Channel display rules. Online channels collapse to one row;
        // in-store and Whatnot get friendly per-store labels (Sarah
        // 2026-04-30 — kill the "(BL0001)" location codes Sabina sees).
        $online_channels = ['discogs', 'ebay'];
        $online_label = [
            'discogs' => 'Discogs',
            'ebay'    => 'eBay & Other',
        ];

        // Build display rows.
        $rows = [];
        $overall_revenue = 0.0;
        foreach ($rev as $r) {
            $channel = $r->channel ?: 'in_store';
            $is_online = in_array($channel, $online_channels, true);
            $loc_name_raw = $business_locations[$r->location_id] ?? 'Unknown';
            // Strip a trailing " (code)" suffix so labels read as plain names.
            $loc_name = trim(preg_replace('/\s*\([^)]*\)\s*$/', '', $loc_name_raw));
            $loc_lower = strtolower($loc_name);
            $is_hollywood = strpos($loc_lower, 'hollywood') !== false;
            $is_pico = strpos($loc_lower, 'pico') !== false;

            if ($is_online) {
                $key = 'online|' . $channel;
                $label = $online_label[$channel];
                $loc_id_display = null;
            } elseif ($channel === 'whatnot') {
                $key = $r->location_id . '|whatnot';
                if ($is_hollywood) {
                    $label = 'Whatnot Hollywood';
                } elseif ($is_pico) {
                    $label = 'Whatnot - Pico';
                } else {
                    $label = 'Whatnot - ' . ucwords($loc_name);
                }
                $loc_id_display = $r->location_id;
            } else { // in_store
                $key = $r->location_id . '|in_store';
                if ($is_hollywood) {
                    $label = 'Hollywood Store';
                } elseif ($is_pico) {
                    $label = 'Pico Store';
                } else {
                    $label = ucwords($loc_name) . ' Store';
                }
                $loc_id_display = $r->location_id;
            }

            $gp_key = $r->location_id . '|' . $channel;
            $gp = isset($gp_rows[$gp_key]) ? (float)$gp_rows[$gp_key]->gross_profit : 0.0;

            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'label'           => $label,
                    'channel'         => $channel,
                    'location_id'     => $loc_id_display,
                    'revenue'         => 0.0,
                    'revenue_exc_tax' => 0.0,
                    'cnt'             => 0,
                    'gross_profit'    => 0.0,
                ];
            }
            $rows[$key]['revenue']         += (float)$r->revenue;
            $rows[$key]['revenue_exc_tax'] += (float)$r->revenue_exc_tax;
            $rows[$key]['cnt']             += (int)$r->cnt;
            $rows[$key]['gross_profit']    += $gp;

            $overall_revenue += (float)$r->revenue;
        }

        // Pull website-side channels from nivessa.com (Space Rentals + web
        // sales — shipping & pickup). These don't live in the ERP DB, so
        // we fetch live each render. Failures are swallowed: the rest of
        // the report keeps working if the website is down.
        $website_rows = $this->fetchWebsiteChannelTotals($start_date, $end_date);
        foreach ($website_rows as $wr) {
            $rows[$wr['key']] = $wr['row'];
            $overall_revenue += $wr['row']['revenue'];
        }

        // Discogs marketplace orders — live-fetched from Discogs's API
        // each render (no separate sync step). Same fail-quiet pattern as
        // the website fetch above: any error → row simply omitted.
        $dgs = $this->fetchDiscogsChannelTotals($business_id, $start_date, $end_date);
        if ($dgs !== null && ($dgs['revenue'] > 0 || $dgs['cnt'] > 0)) {
            $key = 'online|discogs';
            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'label'           => 'Discogs',
                    'channel'         => 'discogs',
                    'location_id'     => null,
                    'revenue'         => 0.0,
                    'revenue_exc_tax' => 0.0,
                    'cnt'             => 0,
                    'gross_profit'    => 0.0,
                    'cost_unknown'    => true, // No COGS until release_id ↔ SKU mapping ships
                ];
            }
            $rows[$key]['revenue']         += $dgs['revenue'];
            $rows[$key]['revenue_exc_tax'] += $dgs['revenue'];
            $rows[$key]['cnt']             += $dgs['cnt'];
            $rows[$key]['cost_unknown'] = true;
            $overall_revenue += $dgs['revenue'];
        }

        // Compute share % and gross margin %, then sort by revenue desc.
        // Default cost_unknown=false for local rows so the view doesn't have
        // to null-check.
        $rows = array_map(function ($row) use ($overall_revenue) {
            if (!isset($row['cost_unknown'])) {
                $row['cost_unknown'] = false;
            }
            $row['share_pct']    = $overall_revenue > 0 ? ($row['revenue'] / $overall_revenue) * 100 : 0;
            $row['gross_margin'] = (!$row['cost_unknown'] && $row['revenue'] > 0)
                ? ($row['gross_profit'] / $row['revenue']) * 100 : 0;
            return $row;
        }, $rows);
        usort($rows, function ($a, $b) { return $b['revenue'] <=> $a['revenue']; });

        // Totals exclude rows with unknown cost from the gross-profit roll
        // (would otherwise pull the consolidated margin down to zero).
        $known_gp_rows = array_filter($rows, function ($r) { return empty($r['cost_unknown']); });
        $known_gp_revenue = array_sum(array_column($known_gp_rows, 'revenue'));
        $totals = [
            'revenue'      => $overall_revenue,
            'cnt'          => array_sum(array_column($rows, 'cnt')),
            'gross_profit' => array_sum(array_column($known_gp_rows, 'gross_profit')),
        ];
        $totals['gross_margin'] = $known_gp_revenue > 0
            ? ($totals['gross_profit'] / $known_gp_revenue) * 100 : 0;

        // CSV export — same data, no view chrome.
        if ($request->input('export') === 'csv') {
            $filename = 'sales-by-channel_' . $start_date . '_to_' . $end_date . '.csv';
            $headers = [
                'Content-Type'        => 'text/csv',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ];
            $callback = function () use ($rows, $totals) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['Channel', 'Revenue', 'Share %', 'Transactions', 'Gross Profit', 'Gross Margin %']);
                foreach ($rows as $r) {
                    fputcsv($out, [
                        $r['label'],
                        number_format($r['revenue'], 2, '.', ''),
                        number_format($r['share_pct'], 2, '.', ''),
                        $r['cnt'],
                        $r['cost_unknown'] ? '' : number_format($r['gross_profit'], 2, '.', ''),
                        $r['cost_unknown'] ? '' : number_format($r['gross_margin'], 2, '.', ''),
                    ]);
                }
                fputcsv($out, [
                    'TOTAL',
                    number_format($totals['revenue'], 2, '.', ''),
                    '100.00',
                    $totals['cnt'],
                    number_format($totals['gross_profit'], 2, '.', ''),
                    number_format($totals['gross_margin'], 2, '.', ''),
                ]);
                fclose($out);
            };
            return response()->stream($callback, 200, $headers);
        }

        return view('report.sales_by_channel')->with(compact(
            'rows', 'totals', 'start_date', 'end_date', 'business_locations'
        ));
    }

    /**
     * Fetch revenue from the nivessa.com backend for the channels that
     * don't live in the ERP DB: Space Rentals (venue bookings) and web
     * sales (shipping + pickup).
     *
     * Returns an array of row entries shaped like the local rows in
     * salesByChannel() — `[ ['key' => ..., 'row' => [...]] , ... ]`.
     *
     * Failure modes are intentionally quiet: any HTTP error, missing
     * config, or malformed response just yields an empty array. The
     * Sales-by-Channel report must keep rendering even if the website
     * backend is down.
     *
     * Config (env):
     *   NIVESSA_WEBSITE_API_URL   default: https://nivessa.com
     *   NIVESSA_WEBSITE_API_KEY   the X-API-Key header (BLOG_API_KEY on
     *                              the server side); without it we skip
     *                              the fetch entirely.
     */
    protected function fetchWebsiteChannelTotals($start_date, $end_date)
    {
        $base = rtrim(env('NIVESSA_WEBSITE_API_URL', 'https://nivessa.com'), '/');
        $key  = env('NIVESSA_WEBSITE_API_KEY', '');
        if (empty($key)) {
            return []; // Not configured — nothing to add.
        }

        $rows = [];

        // Space Rentals — venue bookings.
        $bookings = $this->httpGetJson(
            $base . '/api/v1/bookings/sales-totals?start_date=' . urlencode($start_date) . '&end_date=' . urlencode($end_date),
            $key
        );
        if (!empty($bookings) && !empty($bookings['success'])) {
            $rev = (float)($bookings['totalRevenue'] ?? 0);
            $cnt = (int)($bookings['count'] ?? 0);
            $rows[] = [
                'key' => 'web|space_rental',
                'row' => [
                    'label'           => 'Space Rentals',
                    'channel'         => 'space_rental',
                    'location_id'     => null,
                    'revenue'         => $rev,
                    'revenue_exc_tax' => $rev,
                    'cnt'             => $cnt,
                    'gross_profit'    => $rev, // No COGS on rentals.
                ],
            ];
        }

        // Web sales — shipping + pickup. One call returns both buckets.
        $orders = $this->httpGetJson(
            $base . '/api/v1/order/sales-totals?start_date=' . urlencode($start_date) . '&end_date=' . urlencode($end_date),
            $key
        );
        if (!empty($orders) && !empty($orders['success']) && !empty($orders['byMethod'])) {
            $bm = $orders['byMethod'];
            $shipping = $bm['shipping'] ?? ['totalRevenue' => 0, 'count' => 0];
            $pickup   = $bm['pickup']   ?? ['totalRevenue' => 0, 'count' => 0];

            $rows[] = [
                'key' => 'web|web_ship',
                'row' => [
                    'label'           => 'nivessa.com — Shipping',
                    'channel'         => 'web_ship',
                    'location_id'     => null,
                    'revenue'         => (float)$shipping['totalRevenue'],
                    'revenue_exc_tax' => (float)$shipping['totalRevenue'],
                    'cnt'             => (int)$shipping['count'],
                    // Cost basis lives in the website backend's order line items.
                    // Not surfaced yet — view renders "—" for these.
                    'gross_profit'    => 0.0,
                    'cost_unknown'    => true,
                ],
            ];
            $rows[] = [
                'key' => 'web|web_pickup',
                'row' => [
                    'label'           => 'nivessa.com — Pickup',
                    'channel'         => 'web_pickup',
                    'location_id'     => null,
                    'revenue'         => (float)$pickup['totalRevenue'],
                    'revenue_exc_tax' => (float)$pickup['totalRevenue'],
                    'cnt'             => (int)$pickup['count'],
                    'gross_profit'    => 0.0,
                    'cost_unknown'    => true,
                ],
            ];
        }

        return $rows;
    }

    /**
     * Add a Discogs day row into the existing $daily collection, summed
     * per-day. Returned as a collection sorted desc by day (matching the
     * shape the view expects).
     */
    protected function mergeDiscogsDaily($daily, $business_id, $start_date, $end_date)
    {
        $orders = $this->fetchDiscogsOrdersRaw($business_id, $start_date, $end_date);
        if ($orders === null) {
            return $daily;
        }
        $by_day = [];
        foreach ($daily as $d) { $by_day[$d->day] = $d; }
        foreach ($orders as $o) {
            $day = substr($o['created'] ?? '', 0, 10);
            if ($day === '') continue;
            $rev = isset($o['total']['value']) ? (float)$o['total']['value'] : 0.0;
            if (isset($by_day[$day])) {
                $by_day[$day]->cnt = (int)$by_day[$day]->cnt + 1;
                $by_day[$day]->revenue = (float)$by_day[$day]->revenue + $rev;
            } else {
                $by_day[$day] = (object)['day' => $day, 'cnt' => 1, 'revenue' => $rev];
            }
        }
        krsort($by_day);
        return collect(array_values($by_day));
    }

    /**
     * Raw fetch of Discogs orders for a date range — used both by the
     * channel-totals helper and the daily-merge helper. Filters to
     * revenue statuses. Returns array of order rows, or null on error.
     */
    protected function fetchDiscogsOrdersRaw($business_id, $start_date, $end_date)
    {
        try {
            $service = new \App\Services\DiscogsService($business_id);
            if (!$service->isConfigured()) {
                return null;
            }
            $revenue_statuses = [
                'Payment Received', 'In Progress', 'Shipped',
                'Refund Sent', 'Refund Pending', 'Merged',
            ];
            $created_after = $start_date . 'T00:00:00Z';
            $created_before = $end_date . 'T23:59:59Z';
            $out = [];
            $page = 1;
            $max_pages = 20;
            do {
                $resp = $service->fetchOrders($created_after, $created_before, $page, 100);
                if (!empty($resp['error'])) {
                    return null;
                }
                $orders = $resp['orders'] ?? [];
                foreach ($orders as $o) {
                    if (!in_array($o['status'] ?? '', $revenue_statuses, true)) continue;
                    $out[] = $o;
                }
                $has_more = !empty($resp['pagination']['urls']['next']);
                $page++;
            } while ($has_more && $page <= $max_pages);
            return $out;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Pull Discogs marketplace order totals for a date range, live from
     * Discogs's API. Returns ['revenue' => float, 'cnt' => int] on
     * success, or null on any error / missing config.
     */
    protected function fetchDiscogsChannelTotals($business_id, $start_date, $end_date)
    {
        $orders = $this->fetchDiscogsOrdersRaw($business_id, $start_date, $end_date);
        if ($orders === null) {
            return null;
        }
        $revenue = 0.0;
        foreach ($orders as $o) {
            $revenue += isset($o['total']['value']) ? (float)$o['total']['value'] : 0.0;
        }
        return ['revenue' => $revenue, 'cnt' => count($orders)];
    }

    /**
     * GET a JSON endpoint with a 5-second timeout and decode the body.
     * Returns null on any error — caller is expected to skip silently.
     */
    protected function httpGetJson($url, $api_key)
    {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-API-Key: ' . $api_key,
                'Accept: application/json',
                'User-Agent: NivessaERP/1.0 +https://playlist.nivessa.com',
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code !== 200 || empty($body)) {
                return null;
            }
            $data = json_decode($body, true);
            return is_array($data) ? $data : null;
        } catch (\Exception $e) {
            return null;
        }
    }


    /**
     * Discogs Sales Report — date-range rollup for the Discogs channel
     * (transactions.channel = 'discogs'). Slide 7 of the March business
     * review flagged that "Online channel reports (Whatnot, Discogs) do
     * not reconcile with P&L" and "lack cost prices" — with cost prices
     * now backfilled, this is the page that closes the Discogs half.
     *
     * Thin wrapper around onlineChannelReport().
     */
    public function discogsReport(Request $request)
    {
        return $this->onlineChannelReport($request, 'discogs', 'Discogs');
    }

    /**
     * eBay Sales Report — same shape as Discogs, scoped to channel='ebay'.
     */
    public function ebayReport(Request $request)
    {
        return $this->onlineChannelReport($request, 'ebay', 'eBay');
    }

    /**
     * Shared implementation for online single-channel sales reports
     * (Discogs, eBay). Surfaces revenue, gross profit, margin, txn count,
     * a daily breakdown, and a top-50 items table. CSV export included.
     *
     * One report per channel because each one has different ops concerns
     * (Discogs fees, eBay shipping etc.); the page itself is identical.
     *
     * @param string $channel       Value to filter transactions.channel by
     *                              (must be present in the channel enum).
     * @param string $channel_name  Human label for headers / filenames.
     */
    protected function onlineChannelReport(Request $request, $channel, $channel_name)
    {
        $this->ensureAdminOnlyReportAccess();

        $business_id = $request->session()->get('user.business_id');
        $start_date = $request->input('start_date');
        $end_date = $request->input('end_date');
        if (empty($start_date) || empty($end_date)) {
            $start_date = $start_date ?: \Carbon::now()->startOfMonth()->format('Y-m-d');
            $end_date = $end_date ?: \Carbon::now()->format('Y-m-d');
        }

        $base = DB::table('transactions as t')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.channel', $channel)
            ->whereDate('t.transaction_date', '>=', $start_date)
            ->whereDate('t.transaction_date', '<=', $end_date);

        $summary = (clone $base)
            ->selectRaw('COUNT(*) as cnt,
                COALESCE(SUM(t.final_total), 0) as revenue,
                COALESCE(SUM(t.total_before_tax), 0) as revenue_exc_tax')
            ->first();

        // Gross profit (mirrors getGrossProfit, channel-scoped, no combos).
        $gp_obj = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as sale', 'tsl.transaction_id', '=', 'sale.id')
            ->leftJoin('transaction_sell_lines_purchase_lines as TSPL', 'tsl.id', '=', 'TSPL.sell_line_id')
            ->leftJoin('purchase_lines as PL', 'TSPL.purchase_line_id', '=', 'PL.id')
            ->where('sale.business_id', $business_id)
            ->where('sale.type', 'sell')
            ->where('sale.status', 'final')
            ->where('sale.channel', $channel)
            ->whereDate('sale.transaction_date', '>=', $start_date)
            ->whereDate('sale.transaction_date', '<=', $end_date)
            ->where('tsl.children_type', '!=', 'combo')
            ->selectRaw('COALESCE(SUM((TSPL.quantity - TSPL.qty_returned) *
                (tsl.unit_price_inc_tax - PL.purchase_price_inc_tax)), 0) as gross_profit')
            ->first();

        $revenue      = (float)($summary->revenue ?? 0);
        $cnt          = (int)($summary->cnt ?? 0);
        $gross_profit = (float)($gp_obj->gross_profit ?? 0);

        // For Discogs, also live-fetch from Discogs's marketplace API.
        // Header-level only (no line items mapped to local SKUs) so adds
        // to revenue + count but not gross profit.
        $external_revenue = 0.0;
        $external_cnt = 0;
        if ($channel === 'discogs') {
            $dgs = $this->fetchDiscogsChannelTotals($business_id, $start_date, $end_date);
            if ($dgs !== null) {
                $external_revenue = $dgs['revenue'];
                $external_cnt = $dgs['cnt'];
                $revenue += $external_revenue;
                $cnt += $external_cnt;
            }
        }

        // Margin only reflects POS-side rows (where we have cost basis).
        $pos_revenue = $revenue - $external_revenue;
        $gross_margin = $pos_revenue > 0 ? ($gross_profit / $pos_revenue) * 100 : 0;

        $daily = (clone $base)
            ->selectRaw('DATE(t.transaction_date) as day,
                COUNT(*) as cnt,
                COALESCE(SUM(t.final_total), 0) as revenue')
            ->groupBy(DB::raw('DATE(t.transaction_date)'))
            ->orderByDesc('day')
            ->get();

        // Merge in live-fetched Discogs orders into the daily breakdown.
        if ($channel === 'discogs') {
            $daily = $this->mergeDiscogsDaily($daily, $business_id, $start_date, $end_date);
        }

        $top_items = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as sale', 'tsl.transaction_id', '=', 'sale.id')
            ->join('products as P', 'tsl.product_id', '=', 'P.id')
            ->leftJoin('transaction_sell_lines_purchase_lines as TSPL', 'tsl.id', '=', 'TSPL.sell_line_id')
            ->leftJoin('purchase_lines as PL', 'TSPL.purchase_line_id', '=', 'PL.id')
            ->where('sale.business_id', $business_id)
            ->where('sale.type', 'sell')
            ->where('sale.status', 'final')
            ->where('sale.channel', $channel)
            ->whereDate('sale.transaction_date', '>=', $start_date)
            ->whereDate('sale.transaction_date', '<=', $end_date)
            ->where('tsl.children_type', '!=', 'combo')
            ->selectRaw("P.id as product_id, P.name as product_name, P.sku,
                SUM(tsl.quantity - tsl.quantity_returned) as qty,
                COALESCE(SUM((tsl.quantity - tsl.quantity_returned) * tsl.unit_price_inc_tax), 0) as revenue,
                COALESCE(SUM((TSPL.quantity - TSPL.qty_returned) *
                    (tsl.unit_price_inc_tax - PL.purchase_price_inc_tax)), 0) as gross_profit")
            ->groupBy('P.id', 'P.name', 'P.sku')
            ->orderByDesc('revenue')
            ->limit(50)
            ->get();

        if ($request->input('export') === 'csv') {
            $filename = strtolower($channel) . '_' . $start_date . '_to_' . $end_date . '.csv';
            $headers = [
                'Content-Type'        => 'text/csv',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ];
            $callback = function () use ($daily, $top_items, $revenue, $cnt, $gross_profit, $gross_margin, $channel_name) {
                $out = fopen('php://output', 'w');
                fputcsv($out, [$channel_name . ' Sales Report']);
                fputcsv($out, ['Revenue', number_format($revenue, 2, '.', '')]);
                fputcsv($out, ['Transactions', $cnt]);
                fputcsv($out, ['Gross profit', number_format($gross_profit, 2, '.', '')]);
                fputcsv($out, ['Gross margin %', number_format($gross_margin, 2, '.', '')]);
                fputcsv($out, []);
                fputcsv($out, ['Daily breakdown']);
                fputcsv($out, ['Date', 'Transactions', 'Revenue']);
                foreach ($daily as $d) {
                    fputcsv($out, [$d->day, $d->cnt, number_format($d->revenue, 2, '.', '')]);
                }
                fputcsv($out, []);
                fputcsv($out, ['Top items (up to 50)']);
                fputcsv($out, ['SKU', 'Product', 'Qty', 'Revenue', 'Gross Profit', 'Gross Margin %']);
                foreach ($top_items as $it) {
                    $margin = $it->revenue > 0 ? ($it->gross_profit / $it->revenue) * 100 : 0;
                    fputcsv($out, [
                        $it->sku,
                        $it->product_name,
                        (int)$it->qty,
                        number_format($it->revenue, 2, '.', ''),
                        number_format($it->gross_profit, 2, '.', ''),
                        number_format($margin, 2, '.', ''),
                    ]);
                }
                fclose($out);
            };
            return response()->stream($callback, 200, $headers);
        }

        $action = $channel === 'discogs'
            ? 'ReportController@discogsReport'
            : 'ReportController@ebayReport';

        return view('report.online_channel_report')->with(compact(
            'channel', 'channel_name', 'action',
            'revenue', 'cnt', 'gross_profit', 'gross_margin',
            'daily', 'top_items',
            'start_date', 'end_date'
        ));
    }


    /**
     * End-of-Day Clover Reconciliation — date-range view that compares ERP
     * card payments against Clover's settled payments per day per location.
     * Flags days whose variance exceeds \$1 so Sarah / Sabina don't have to
     * open 30 single-day reports during weekly review.
     *
     * Data sources:
     *   ERP side:    transaction_payments joined to transactions (type=sell,
     *                status=final). Payment methods considered 'card-like':
     *                clover, card, credit_card, credit_sale, custom_pay_1..7
     *                (matches what the existing single-day cloverVsErpReport
     *                already normalises for this install).
     *   Clover side: clover_payments rows with result SUCCESS / APPROVED
     *                (populated by the scheduled clover:sync-payments
     *                command, which pulls from /v3/merchants/{mid}/payments).
     *
     * Status traffic-light:
     *   |variance| < \$1   → reconciled (green)
     *   |variance| < \$10  → minor      (yellow)
     *   otherwise          → review     (red)
     */
    public function cloverEodReconciliation(Request $request)
    {
        // Per-shift drawer totals + ERP-vs-Clover audit — admin-only
        // (Sarah 2026-04-28).
        $this->ensureAdminOnlyReportAccess();

        $business_id = $request->session()->get('user.business_id');
        $business_locations = BusinessLocation::forDropdown($business_id);

        // Default the view to today (single-day mode) — Fatteen's daily
        // reconciliation flow wants one day at a time, with a prev/next
        // nav. A historical range is still available via the date-range
        // picker; when start != end we fall back to the multi-day render.
        $start = $request->input('start_date') ?: \Carbon::today()->format('Y-m-d');
        $end   = $request->input('end_date')   ?: \Carbon::today()->format('Y-m-d');
        $location_id = $request->input('location_id');

        $is_single_day = ($start === $end);
        $prev_day = \Carbon::parse($start)->subDay()->format('Y-m-d');
        $next_day = \Carbon::parse($start)->addDay()->format('Y-m-d');
        $today_str = \Carbon::today()->format('Y-m-d');

        $card_methods = [
            'clover', 'card', 'credit_card', 'credit_sale',
            'custom_pay_1', 'custom_pay_2', 'custom_pay_3', 'custom_pay_4',
            'custom_pay_5', 'custom_pay_6', 'custom_pay_7',
        ];

        // Peek at what methods actually exist in this range so we can auto-
        // fallback to 'all methods' if this install stores Clover payments
        // under a method name none of the defaults recognise (same issue the
        // single-day cloverVsErpReport solves with its auto-fallback —
        // mirrored here so the EOD view isn't blank when the single-day
        // view is populated).
        $peekQuery = \DB::table('transaction_payments as tp')
            ->join('transactions as t', 'tp.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereDate('t.transaction_date', '>=', $start)
            ->whereDate('t.transaction_date', '<=', $end);
        if (!empty($location_id)) {
            $peekQuery->where('t.location_id', $location_id);
        }
        $day_methods = $peekQuery->distinct()->pluck('tp.method')->map(fn($m) => (string) $m)->all();
        $has_overlap = !empty(array_intersect($day_methods, $card_methods));
        $used_all_methods = false;
        if (!$has_overlap && !empty($day_methods)) {
            $used_all_methods = true;
        }

        // ERP-side per (date, location) rollup. Using transaction_date rather
        // than tp.paid_on since paid_on is occasionally NULL on older rows.
        $erpQuery = \DB::table('transaction_payments as tp')
            ->join('transactions as t', 'tp.transaction_id', '=', 't.id')
            ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereDate('t.transaction_date', '>=', $start)
            ->whereDate('t.transaction_date', '<=', $end);
        if (!$used_all_methods) {
            $erpQuery->whereIn('tp.method', $card_methods);
        }
        if (!empty($location_id)) {
            $erpQuery->where('t.location_id', $location_id);
        }
        $erp_rows = $erpQuery
            ->selectRaw("DATE(t.transaction_date) as day,
                t.location_id, bl.name as location_name,
                COUNT(tp.id) as erp_count,
                COALESCE(SUM(tp.amount), 0) as erp_total")
            ->groupBy(DB::raw('DATE(t.transaction_date)'), 't.location_id', 'bl.name')
            ->get();

        // Clover payment-side per (date, location) rollup. Rows pulled via per-location
        // Clover creds get their ERP location_id stamped at sync time, so we
        // can match Hollywood-Clover ↔ Hollywood-ERP on the same day. Legacy
        // rows synced before that (and any rows from a top-level single-
        // merchant scope) have location_id=NULL; we bucket those under
        // loc_key=0 and fall back to them when no per-location match exists
        // so historical data doesn't disappear.
        $cloverQuery = \DB::table('clover_payments')
            ->where('business_id', $business_id)
            ->where(function ($q) {
                $q->whereNull('result')->orWhere('result', 'SUCCESS')->orWhere('result', 'APPROVED');
            })
            ->whereDate('paid_on', '>=', $start)
            ->whereDate('paid_on', '<=', $end);
        if (!empty($location_id)) {
            $cloverQuery->where(function ($q) use ($location_id) {
                $q->where('location_id', $location_id)->orWhereNull('location_id');
            });
        }
        $clover_rows_raw = $cloverQuery
            ->selectRaw("DATE(paid_on) as day, COALESCE(location_id, 0) as loc_key,
                COUNT(*) as clover_count,
                COALESCE(SUM(amount), 0) as clover_total")
            ->groupBy(DB::raw('DATE(paid_on)'), DB::raw('COALESCE(location_id, 0)'))
            ->get();

        // Clover batch/deposit side per (date, location) rollup.
        $batchQuery = \DB::table('clover_batches')
            ->where('business_id', $business_id)
            ->whereDate('batch_on', '>=', $start)
            ->whereDate('batch_on', '<=', $end);
        if (!empty($location_id)) {
            $batchQuery->where(function ($q) use ($location_id) {
                $q->where('location_id', $location_id)->orWhereNull('location_id');
            });
        }
        $batch_rows_raw = $batchQuery
            ->selectRaw("DATE(batch_on) as day, COALESCE(location_id, 0) as loc_key,
                COUNT(*) as batch_count,
                COALESCE(SUM(amount), 0) as batch_total,
                COALESCE(SUM(COALESCE(deposit_total, amount)), 0) as deposit_total")
            ->groupBy(DB::raw('DATE(batch_on)'), DB::raw('COALESCE(location_id, 0)'))
            ->get();

        // Index by [day][loc_key]. loc_key = 0 is the NULL-location bucket.
        $clover_by_day_loc = [];
        foreach ($clover_rows_raw as $cr) {
            $clover_by_day_loc[$cr->day][(int) $cr->loc_key] = $cr;
        }
        $batch_by_day_loc = [];
        foreach ($batch_rows_raw as $br) {
            $batch_by_day_loc[$br->day][(int) $br->loc_key] = $br;
        }

        // Merge: one row per (day, location) with ERP data + the matching
        // per-location Clover bucket attached. Falls back to the NULL-
        // location bucket when a per-location match isn't available. Each
        // bucket is claimed at most once per day so we don't double-count
        // when ERP has multiple locations.
        $rows = [];
        $grand = [
            'erp' => 0.0,
            'clover' => 0.0,
            'batch' => 0.0,
            'deposit' => 0.0,
            'variance' => 0.0,
            'deposit_variance' => 0.0,
            'flagged_days' => 0,
            'deposit_flagged_days' => 0,
        ];
        $claimed = []; // [day => [loc_key => true]]
        foreach ($erp_rows as $r) {
            $day = $r->day;
            $locId = (int) $r->location_id;
            $clover = null;
            if (isset($clover_by_day_loc[$day][$locId]) && empty($claimed[$day][$locId])) {
                $clover = $clover_by_day_loc[$day][$locId];
                $claimed[$day][$locId] = true;
            } elseif (isset($clover_by_day_loc[$day][0]) && empty($claimed[$day][0])) {
                $clover = $clover_by_day_loc[$day][0];
                $claimed[$day][0] = true;
            }
            $cloverTotal = (float) ($clover->clover_total ?? 0);
            $cloverCount = (int) ($clover->clover_count ?? 0);
            $batch = null;
            if (isset($batch_by_day_loc[$day][$locId]) && empty($claimed[$day]['b' . $locId])) {
                $batch = $batch_by_day_loc[$day][$locId];
                $claimed[$day]['b' . $locId] = true;
            } elseif (isset($batch_by_day_loc[$day][0]) && empty($claimed[$day]['b0'])) {
                $batch = $batch_by_day_loc[$day][0];
                $claimed[$day]['b0'] = true;
            }
            $batchTotal = (float) ($batch->batch_total ?? 0);
            $depositTotal = (float) ($batch->deposit_total ?? 0);
            $batchCount = (int) ($batch->batch_count ?? 0);
            $erpTotal = (float) $r->erp_total;
            $variance = round($erpTotal - $cloverTotal, 2);
            $depositVariance = round($erpTotal - $depositTotal, 2);
            $status = $this->reconciliationStatus($variance);
            $depositStatus = $this->reconciliationStatus($depositVariance);
            $rows[] = (object) [
                'day' => $day,
                'location_name' => $r->location_name ?: '(no location)',
                'erp_count' => (int) $r->erp_count,
                'erp_total' => $erpTotal,
                'clover_count' => $cloverCount,
                'clover_total' => $cloverTotal,
                'batch_count' => $batchCount,
                'batch_total' => $batchTotal,
                'deposit_total' => $depositTotal,
                'variance' => $variance,
                'deposit_variance' => $depositVariance,
                'status' => $status,
                'deposit_status' => $depositStatus,
            ];
            $grand['erp'] += $erpTotal;
            $grand['clover'] += $cloverTotal;
            $grand['batch'] += $batchTotal;
            $grand['deposit'] += $depositTotal;
            $grand['variance'] += $variance;
            $grand['deposit_variance'] += $depositVariance;
            if ($status !== 'reconciled') $grand['flagged_days']++;
            if ($depositStatus !== 'reconciled') $grand['deposit_flagged_days']++;
        }
        // Unclaimed Clover buckets — Clover recorded sales but no ERP card
        // sales matched. Surface these so discrepancies aren't swallowed.
        foreach ($clover_by_day_loc as $day => $buckets) {
            foreach ($buckets as $locKey => $c) {
                if (!empty($claimed[$day][$locKey])) continue;
                $cloverTotal = (float) $c->clover_total;
                $variance = round(0 - $cloverTotal, 2);
                $locLabel = $locKey === 0
                    ? '(Clover only — no ERP card sales)'
                    : (optional(BusinessLocation::find($locKey))->name
                        ? optional(BusinessLocation::find($locKey))->name . ' (Clover only)'
                        : '(Clover only — no ERP card sales)');
                $batch = $batch_by_day_loc[$day][$locKey] ?? ($batch_by_day_loc[$day][0] ?? null);
                $batchTotal = (float) ($batch->batch_total ?? 0);
                $depositTotal = (float) ($batch->deposit_total ?? 0);
                $batchCount = (int) ($batch->batch_count ?? 0);
                $depositVariance = round(0 - $depositTotal, 2);
                $rows[] = (object) [
                    'day' => $day,
                    'location_name' => $locLabel,
                    'erp_count' => 0,
                    'erp_total' => 0,
                    'clover_count' => (int) $c->clover_count,
                    'clover_total' => $cloverTotal,
                    'batch_count' => $batchCount,
                    'batch_total' => $batchTotal,
                    'deposit_total' => $depositTotal,
                    'variance' => $variance,
                    'deposit_variance' => $depositVariance,
                    'status' => $this->reconciliationStatus($variance),
                    'deposit_status' => $this->reconciliationStatus($depositVariance),
                ];
                $grand['clover'] += $cloverTotal;
                $grand['batch'] += $batchTotal;
                $grand['deposit'] += $depositTotal;
                $grand['variance'] += $variance;
                $grand['deposit_variance'] += $depositVariance;
                $grand['flagged_days']++;
                if ($this->reconciliationStatus($depositVariance) !== 'reconciled') $grand['deposit_flagged_days']++;
            }
        }

        // Most recent first.
        usort($rows, fn($a, $b) => strcmp($b->day, $a->day) ?: strcmp($a->location_name, $b->location_name));

        // Per-cashier breakdown, split by location — matches the format Sarah
        // has been using in her daily "clover vs erp" spreadsheet (one tab
        // per day, two side-by-side panels: Pico cashiers | Hollywood
        // cashiers, each with Employee / Clover / ERP / Difference). Works
        // across any date range: single day renders one block, multi-day
        // renders one block per day with the most recent at top.
        $employee_breakdown_by_day = $this->cloverEodEmployeeBreakdownRange(
            $business_id, $start, $end, $location_id, $card_methods, $used_all_methods
        );

        // Drill-down data for the "Why Unknown?" panel — the raw rows that
        // bucketed as Unknown on either side, with the underlying cause so
        // Sarah can tell walk-ins / online orders from data issues.
        $unknown_rows = $this->cloverEodUnknownRows(
            $business_id, $start, $end, $location_id, $card_methods, $used_all_methods
        );

        // Load per-location reconciliation state (✓ + notes + audit stamp)
        // for every (day, location) on screen, keyed so the blade can look
        // each cell up in O(1). Multi-day ranges still get this so Sarah
        // can see at a glance which days are already signed off on.
        $reconciliations = $this->loadReconciliations($business_id, $start, $end);

        // Per-shift theft-prevention audit — the PRIMARY view Sarah wants
        // for daily reconciliation. One card per cash_registers row, with
        // SALES CHECK (Clover ↔ ERP during shift) and CASH CHECK (drawer
        // math). Replaces the previous xlsx / match / shift-breakdown
        // experiments which we were told made the page worse.
        $shift_audit = $this->cloverEodShiftAudit(
            $business_id, $start, $end, $location_id, $card_methods, $used_all_methods
        );

        return view('report.clover_eod_reconciliation')->with(compact(
            'rows', 'grand', 'start', 'end', 'location_id', 'business_locations',
            'employee_breakdown_by_day', 'unknown_rows',
            'is_single_day', 'prev_day', 'next_day', 'today_str',
            'reconciliations', 'shift_audit'
        ));
    }

    /**
     * Per-shift theft-prevention audit — one card per cash_registers row
     * in the window. Two plain-language checks on each card:
     *
     *   SALES CHECK  — did Clover and ERP agree on card sales during
     *                   this cashier's shift? If not, there's a keying
     *                   error at the terminal (or a skimmed sale).
     *   CASH CHECK   — opening cash + cash sales − cash paid out
     *                   should equal reported closing cash. If not,
     *                   the drawer is short/over.
     *
     * Each card's drill-in carries the raw Clover + ERP payment lists
     * constrained to the shift window so Fatteen can eyeball which
     * specific sale carries a typo when the SALES CHECK fails.
     *
     * Returns an ordered array (most recent shift first), each element:
     *   [
     *     'register_id' => 123,
     *     'user_name' => 'Zakary', 'user_first' => 'zak',
     *     'location_id' => 2, 'location_name' => 'PICO',
     *     'opened_at' => Carbon, 'closed_at' => ?Carbon, 'is_open' => bool,
     *     'opening_cash' => 100.00, 'cash_sales' => 400.00,
     *     'cash_buys' => 0.00, 'cash_refunds' => 0.00,
     *     'expected_closing_cash' => 500.00, 'reported_closing_cash' => 500.00,
     *     'cash_variance' => 0.00,
     *     'clover_card_total' => 500.00, 'erp_card_total' => 500.00,
     *     'sales_diff' => 0.00,                    // clover − erp
     *     'clover_payments' => [...], 'erp_payments' => [...],  // within window
     *   ]
     */
    private function cloverEodShiftAudit($business_id, $start, $end, $location_id, array $card_methods, $used_all_methods): array
    {
        $firstName = function ($full) {
            $full = trim((string) $full);
            if ($full === '') return '';
            $parts = preg_split('/\s+/', $full);
            return strtolower($parts[0] ?? '');
        };

        // All cash registers that overlapped this window — opened in-window
        // OR opened earlier and still open (closed_at in-window or null).
        //
        // Scope to active cashiers only (status=active AND allow_login=1).
        // Sarah's offboarding signal is flipping allow_login=0 → their old
        // cash-register rows shouldn't clutter today's shift view.
        $regQ = \DB::table('cash_registers as cr')
            ->join('users as u', 'cr.user_id', '=', 'u.id')
            ->leftJoin('business_locations as bl', 'cr.location_id', '=', 'bl.id')
            ->where('cr.business_id', $business_id)
            ->where('u.status', 'active')
            ->where('u.allow_login', 1)
            ->where(function ($q) use ($start, $end) {
                // Shift opened in window
                $q->where(function ($q2) use ($start, $end) {
                    $q2->whereDate('cr.created_at', '>=', $start)
                       ->whereDate('cr.created_at', '<=', $end);
                })->orWhere(function ($q2) use ($start, $end) {
                    // Shift closed in window (covers shifts that opened
                    // before the window but ended inside it)
                    $q2->whereNotNull('cr.closed_at')
                       ->whereDate('cr.closed_at', '>=', $start)
                       ->whereDate('cr.closed_at', '<=', $end);
                });
                // Note: we deliberately do NOT include stale open shifts
                // from prior days. If a register was opened 3 days ago and
                // never closed, it's a forgotten drawer — it clutters today's
                // reconciliation. Sarah wants open shifts to only surface
                // when they were opened in the current window.
            });
        if (!empty($location_id)) {
            $regQ->where('cr.location_id', $location_id);
        }
        $registers = $regQ->selectRaw("
                cr.id as register_id,
                cr.user_id,
                cr.location_id,
                bl.name as location_name,
                cr.created_at as opened_at,
                cr.closed_at,
                cr.closing_amount as reported_closing_cash,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, 'Unknown') as user_name
            ")
            ->orderByDesc('cr.created_at')
            ->get();

        if ($registers->isEmpty()) {
            return [];
        }

        // Per-register opening / cash-flow totals from cash_register_transactions.
        $registerIds = $registers->pluck('register_id')->all();
        $crt = \DB::table('cash_register_transactions')
            ->selectRaw("
                cash_register_id,
                SUM(CASE WHEN pay_method='cash' AND transaction_type='initial' THEN amount ELSE 0 END) as opening_cash,
                SUM(CASE WHEN pay_method='cash' AND transaction_type='sell' AND type='credit' THEN amount ELSE 0 END) as cash_sales,
                SUM(CASE WHEN pay_method='cash' AND transaction_type='purchase' AND type='debit' THEN amount ELSE 0 END) as cash_buys,
                SUM(CASE WHEN pay_method='cash' AND transaction_type='refund' AND type='debit' THEN amount ELSE 0 END) as cash_refunds,
                SUM(CASE WHEN pay_method='cash' THEN CASE WHEN type='credit' THEN amount ELSE -amount END ELSE 0 END) as cash_net
            ")
            ->whereIn('cash_register_id', $registerIds)
            ->groupBy('cash_register_id')
            ->get()
            ->keyBy('cash_register_id');

        // For each register, compute the window + pull the ERP and Clover
        // card sales that fall inside it.
        $cards = [];
        foreach ($registers as $reg) {
            $openedAt = \Carbon::parse($reg->opened_at);
            $closedAt = $reg->closed_at ? \Carbon::parse($reg->closed_at) : null;
            $effectiveEnd = $closedAt ?: \Carbon::now();

            // ERP card payments by this user at this location during the shift.
            $erpQ = \DB::table('transaction_payments as tp')
                ->join('transactions as t', 'tp.transaction_id', '=', 't.id')
                ->where('t.business_id', $business_id)
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->where('t.created_by', $reg->user_id)
                ->whereBetween('t.transaction_date', [$openedAt, $effectiveEnd]);
            if (!$used_all_methods) {
                $erpQ->whereIn('tp.method', $card_methods);
            }
            if ($reg->location_id) {
                $erpQ->where('t.location_id', $reg->location_id);
            }
            $erpRows = $erpQ->selectRaw("
                    t.id as transaction_id, t.invoice_no,
                    t.transaction_date as ts,
                    tp.amount, tp.method
                ")
                ->orderBy('t.transaction_date')
                ->get();

            // Clover payments at this location during the shift. Attribution
            // to THIS register uses (first-name match on Clover pin) OR
            // (blank Clover pin AND no other register open at same location
            // at the time — handled later).
            $cpQ = \DB::table('clover_payments as cp')
                ->where('cp.business_id', $business_id)
                ->where(function ($q) {
                    $q->whereNull('cp.result')->orWhere('cp.result', 'SUCCESS')->orWhere('cp.result', 'APPROVED');
                })
                ->whereBetween('cp.paid_at', [$openedAt, $effectiveEnd]);
            if ($reg->location_id) {
                $cpQ->where('cp.location_id', $reg->location_id);
            }
            $cpRows = $cpQ->selectRaw("
                    cp.clover_payment_id,
                    cp.paid_at as ts,
                    cp.amount,
                    COALESCE(NULLIF(TRIM(cp.employee_name), ''), '') as employee_name,
                    cp.tender_type, cp.card_type, cp.card_last4
                ")
                ->orderBy('cp.paid_at')
                ->get();

            // Filter Clover rows down to this cashier: either pin matches
            // first-name, or pin is blank AND this register is the only
            // one open at its location during the payment's moment.
            $userFirst = $firstName($reg->user_name);
            $mineClover = [];
            foreach ($cpRows as $cp) {
                $pin = $firstName($cp->employee_name);
                if ($pin !== '' && $pin === $userFirst) { $mineClover[] = $cp; continue; }
                if ($pin === '') {
                    // Lone-register attribution: if no OTHER register was
                    // open at this location at this moment, the sale is
                    // ours by default.
                    $cpTs = strtotime((string) $cp->ts);
                    $otherOpen = false;
                    foreach ($registers as $other) {
                        if ($other->register_id === $reg->register_id) continue;
                        if ((int) $other->location_id !== (int) $reg->location_id) continue;
                        $oOpen  = strtotime((string) $other->opened_at);
                        $oClose = $other->closed_at ? strtotime((string) $other->closed_at) : PHP_INT_MAX;
                        if ($cpTs >= $oOpen && $cpTs <= $oClose) { $otherOpen = true; break; }
                    }
                    if (!$otherOpen) $mineClover[] = $cp;
                }
            }

            $cashRow = $crt->get($reg->register_id);
            $openingCash = (float) ($cashRow->opening_cash ?? 0);
            $cashSales   = (float) ($cashRow->cash_sales ?? 0);
            $cashBuys    = (float) ($cashRow->cash_buys ?? 0);
            $cashRefunds = (float) ($cashRow->cash_refunds ?? 0);
            $cashNet     = (float) ($cashRow->cash_net ?? 0);
            $expectedClosing = $openingCash + $cashNet;
            $reportedClosing = $closedAt ? (float) ($reg->reported_closing_cash ?? 0) : null;
            $cashVariance = ($closedAt && $reportedClosing !== null)
                ? round($reportedClosing - $expectedClosing, 2)
                : null;

            $cloverTotal = array_sum(array_map(fn($r) => (float) $r->amount, $mineClover));
            $erpTotal    = array_sum(array_map(fn($r) => (float) $r->amount, $erpRows->all()));

            // Skip shifts with zero sales activity on every channel — these
            // are usually admin/test registers (cashier opened a drawer but
            // never rang anything). They add noise without helping reconcile.
            // A real shift will have at least one of: cash sale, cash buy,
            // Clover card sale, or ERP card sale.
            $hasActivity = $cashSales > 0.01
                || $cashBuys > 0.01
                || $cashRefunds > 0.01
                || $cloverTotal > 0.01
                || $erpTotal > 0.01;
            if (!$hasActivity) {
                continue;
            }

            $cards[] = [
                'register_id' => $reg->register_id,
                'user_name' => $reg->user_name,
                'user_first' => $userFirst,
                'location_id' => $reg->location_id,
                'location_name' => $reg->location_name ?: '(no location)',
                'opened_at' => $openedAt,
                'closed_at' => $closedAt,
                'is_open' => $closedAt === null,
                'opening_cash' => round($openingCash, 2),
                'cash_sales' => round($cashSales, 2),
                'cash_buys' => round($cashBuys, 2),
                'cash_refunds' => round($cashRefunds, 2),
                'expected_closing_cash' => round($expectedClosing, 2),
                'reported_closing_cash' => $reportedClosing !== null ? round($reportedClosing, 2) : null,
                'cash_variance' => $cashVariance,
                'clover_card_total' => round($cloverTotal, 2),
                'erp_card_total' => round($erpTotal, 2),
                'sales_diff' => round($cloverTotal - $erpTotal, 2),
                'clover_payments' => $mineClover,
                'erp_payments' => $erpRows->all(),
            ];
        }

        return $cards;
    }

    /**
     * Returns the shape Sarah's daily "clover vs erp" xlsx uses — the
     * layout Fatteen already knows how to read:
     *
     *   [
     *     'employee_summary' => [
     *        ['name' => 'Nick', 'clover' => 39.51, 'erp' => 0, 'diff' => 39.51],
     *        ...   // one row per first-name, aggregated across both stores,
     *              //   sorted by |diff| desc so biggest mismatches float up.
     *     ],
     *     'by_day' => [
     *        [
     *          'day' => '2026-04-22',
     *          'locations' => [
     *            [
     *              'location_id' => 2, 'location_name' => 'PICO',
     *              'clover_payments' => [ (obj) ts, amount, employee_first, ... ],
     *              'erp_payments'    => [ (obj) ts, amount, added_by_full, invoice_no, ... ],
     *              'clover_total' => 363.67, 'erp_total' => 201.60,
     *            ],
     *            { ...HOLLYWOOD... },
     *          ],
     *        ], ... (most recent day first)
     *     ],
     *   ]
     *
     * Fatteen scans top summary for "anyone looking off?" and then
     * eyeball-matches the two side-by-side lists for that employee. No
     * auto-pairing — we just present the raw data in the same format the
     * xlsx uses.
     */
    private function cloverEodXlsxLayout($business_id, $start, $end, $location_id, array $card_methods, $used_all_methods): array
    {
        $firstName = function ($full) {
            $full = trim((string) $full);
            if ($full === '') return '';
            $parts = preg_split('/\s+/', $full);
            return strtolower($parts[0] ?? '');
        };

        // Raw ERP card payments — one row per transaction_payment.
        $erpQ = \DB::table('transaction_payments as tp')
            ->join('transactions as t', 'tp.transaction_id', '=', 't.id')
            ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->leftJoin('users as u', 't.created_by', '=', 'u.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereDate('t.transaction_date', '>=', $start)
            ->whereDate('t.transaction_date', '<=', $end);
        if (!$used_all_methods) {
            $erpQ->whereIn('tp.method', $card_methods);
        }
        if (!empty($location_id)) {
            $erpQ->where('t.location_id', $location_id);
        }
        $erpRows = $erpQ->selectRaw("
                DATE(t.transaction_date) as day,
                t.id as transaction_id,
                t.invoice_no,
                t.transaction_date as ts,
                tp.amount,
                t.location_id,
                bl.name as location_name,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, 'Unknown') as added_by
            ")
            ->orderBy('t.transaction_date')
            ->get();

        // Raw Clover payments.
        $cpQ = \DB::table('clover_payments as cp')
            ->leftJoin('business_locations as bl', 'cp.location_id', '=', 'bl.id')
            ->where('cp.business_id', $business_id)
            ->where(function ($q) {
                $q->whereNull('cp.result')->orWhere('cp.result', 'SUCCESS')->orWhere('cp.result', 'APPROVED');
            })
            ->whereDate('cp.paid_on', '>=', $start)
            ->whereDate('cp.paid_on', '<=', $end);
        if (!empty($location_id)) {
            $cpQ->where('cp.location_id', $location_id);
        }
        $cpRows = $cpQ->selectRaw("
                cp.paid_on as day,
                cp.clover_payment_id,
                cp.paid_at as ts,
                cp.amount,
                cp.location_id,
                bl.name as location_name,
                COALESCE(NULLIF(TRIM(cp.employee_name), ''), '') as employee_name
            ")
            ->orderBy('cp.paid_at')
            ->get();

        // ---- Shift-based attribution for pinless Clover payments ----
        // Sarah 2026-04-23: "there is no online shop — if zak is in 12 4 all
        // transactions at that time are zak." So when Clover didn't capture
        // the employee pin, attribute the sale to whichever cashier's
        // register was open at (paid_at, location_id). We only override
        // when Clover's own employee_name is blank — if Clover already told
        // us who rang it, we trust that.
        $registerQ = \DB::table('cash_registers as cr')
            ->leftJoin('users as u', 'cr.user_id', '=', 'u.id')
            ->where('cr.business_id', $business_id)
            ->whereDate('cr.created_at', '>=', \Carbon::parse($start)->subDay())
            ->whereDate('cr.created_at', '<=', \Carbon::parse($end)->addDay());
        if (!empty($location_id)) {
            $registerQ->where('cr.location_id', $location_id);
        }
        $registers = $registerQ->selectRaw("
                cr.location_id,
                cr.created_at as opened_at,
                cr.closed_at,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, '') as user_name
            ")
            ->orderBy('cr.created_at')
            ->get();

        foreach ($cpRows as $r) {
            if (trim((string) $r->employee_name) !== '') continue;
            $cpTs = strtotime((string) $r->ts);
            $match = null;
            foreach ($registers as $reg) {
                if ((int) ($reg->location_id ?? 0) !== (int) ($r->location_id ?? 0)) continue;
                $open = strtotime((string) $reg->opened_at);
                $close = $reg->closed_at ? strtotime((string) $reg->closed_at) : PHP_INT_MAX;
                if ($cpTs >= $open && $cpTs <= $close) {
                    $match = $reg;
                    break;
                }
            }
            if ($match && $match->user_name !== '') {
                $r->employee_name = $match->user_name;
            }
        }

        // ---- Build employee summary (aggregated across stores) ----
        $summary = [];
        foreach ($cpRows as $r) {
            // Fall back to 'unattributed' only if shift attribution also
            // couldn't find a cashier for this timestamp+location. In a
            // normal day this bucket stays empty.
            $k = $firstName($r->employee_name) ?: 'unattributed';
            $summary[$k] = $summary[$k] ?? [
                'name' => $k === 'unattributed' ? 'Unattributed (no shift open)' : ucfirst($k),
                'clover' => 0.0, 'erp' => 0.0,
            ];
            $summary[$k]['clover'] += (float) $r->amount;
        }
        foreach ($erpRows as $r) {
            $k = $firstName($r->added_by) ?: 'unknown';
            $summary[$k] = $summary[$k] ?? ['name' => ucfirst($k), 'clover' => 0.0, 'erp' => 0.0];
            $summary[$k]['erp'] += (float) $r->amount;
        }
        foreach ($summary as &$row) {
            $row['clover'] = round($row['clover'], 2);
            $row['erp']    = round($row['erp'], 2);
            $row['diff']   = round($row['clover'] - $row['erp'], 2);
        }
        unset($row);
        uasort($summary, fn($a, $b) => abs($b['diff']) <=> abs($a['diff']));
        $employee_summary = array_values($summary);

        // ---- Group raw lists by day, then by location ----
        $by_day = [];
        foreach ($erpRows as $r) {
            $day = $r->day instanceof \DateTimeInterface ? $r->day->format('Y-m-d') : (string) $r->day;
            $loc = $r->location_id ?: 0;
            $by_day[$day][$loc]['location_id'] = $loc ?: null;
            $by_day[$day][$loc]['location_name'] = $r->location_name ?: '(no location)';
            $by_day[$day][$loc]['erp_payments'][] = (object) [
                'ts' => $r->ts,
                'amount' => round((float) $r->amount, 2),
                'added_by' => $r->added_by,
                'invoice_no' => $r->invoice_no,
                'transaction_id' => $r->transaction_id,
            ];
            $by_day[$day][$loc]['erp_total']
                = ($by_day[$day][$loc]['erp_total'] ?? 0) + (float) $r->amount;
        }
        foreach ($cpRows as $r) {
            $day = $r->day instanceof \DateTimeInterface ? $r->day->format('Y-m-d') : (string) $r->day;
            $loc = $r->location_id ?: 0;
            $by_day[$day][$loc]['location_id'] = $loc ?: null;
            $by_day[$day][$loc]['location_name'] = $by_day[$day][$loc]['location_name'] ?? ($r->location_name ?: '(no location)');
            $by_day[$day][$loc]['clover_payments'][] = (object) [
                'ts' => $r->ts,
                'amount' => round((float) $r->amount, 2),
                // Clover's own employee_name wins when present; otherwise
                // the shift-attribution loop above has filled it in with
                // whoever's register was open at (ts, location). If both
                // fail → '(unattributed)' meaning the sale happened
                // outside any open shift window.
                'employee' => $r->employee_name ?: '(unattributed)',
                'clover_payment_id' => $r->clover_payment_id,
            ];
            $by_day[$day][$loc]['clover_total']
                = ($by_day[$day][$loc]['clover_total'] ?? 0) + (float) $r->amount;
        }

        // Normalize → ordered array, most recent day first, locations alpha.
        $by_day_list = [];
        krsort($by_day);
        foreach ($by_day as $day => $locs) {
            $block = ['day' => $day, 'locations' => []];
            foreach ($locs as $loc) {
                $block['locations'][] = [
                    'location_id'     => $loc['location_id'] ?? null,
                    'location_name'   => $loc['location_name'] ?? '(no location)',
                    'clover_payments' => $loc['clover_payments'] ?? [],
                    'erp_payments'    => $loc['erp_payments'] ?? [],
                    'clover_total'    => round($loc['clover_total'] ?? 0, 2),
                    'erp_total'       => round($loc['erp_total'] ?? 0, 2),
                ];
            }
            usort($block['locations'], fn($a, $b) => strcmp($a['location_name'], $b['location_name']));
            $by_day_list[] = $block;
        }

        return [
            'employee_summary' => $employee_summary,
            'by_day' => $by_day_list,
        ];
    }

    /**
     * Transaction-level match — pair each Clover payment to its ERP
     * counterpart by (first_name of cashier, amount, within ±60s). Then
     * group everything per cashier so Fatteen can see, per person:
     *
     *   ✓ Matched    — Clover swipe lines up with an ERP sale record
     *   ❌ Clover-only — card ran on Clover, no ERP record
     *   ❌ ERP-only    — ERP booked a card payment, no Clover settlement
     *
     * Unmatched Clover payments with no cashier attached (online / self-
     * checkout / card-on-file) are bucketed separately under a synthetic
     * "Online / automated" cashier so the per-cashier cards stay clean.
     *
     * Returns:
     *   [
     *     'by_cashier' => [
     *        'zak' => [
     *           'display_name' => 'Zak',
     *           'matched' => [...rows...],
     *           'clover_only' => [...rows...],
     *           'erp_only' => [...rows...],
     *           'location_id' => int|null,
     *           'location_name' => string,
     *           'totals' => ['matched' => $sum, 'clover_only' => $sum, 'erp_only' => $sum],
     *        ], ...
     *     ],
     *     'online' => ['clover_only' => [...], 'total' => $sum],
     *     'totals' => ['matched' => ..., 'clover_only' => ..., 'erp_only' => ..., 'online' => ...],
     *   ]
     */
    private function cloverEodTransactionMatch($business_id, $start, $end, $location_id, array $card_methods, $used_all_methods): array
    {
        $firstName = function ($full) {
            $full = trim((string) $full);
            if ($full === '') return '';
            $parts = preg_split('/\s+/', $full);
            return strtolower($parts[0] ?? '');
        };

        // ---- Load ERP card payments (one row per transaction_payment) ----
        $erpQ = \DB::table('transaction_payments as tp')
            ->join('transactions as t', 'tp.transaction_id', '=', 't.id')
            ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->leftJoin('users as u', 't.created_by', '=', 'u.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereDate('t.transaction_date', '>=', $start)
            ->whereDate('t.transaction_date', '<=', $end);
        if (!$used_all_methods) {
            $erpQ->whereIn('tp.method', $card_methods);
        }
        if (!empty($location_id)) {
            $erpQ->where('t.location_id', $location_id);
        }
        $erpRows = $erpQ->selectRaw("
                tp.id as payment_id,
                t.id as transaction_id,
                t.invoice_no,
                t.transaction_date as ts,
                tp.amount,
                tp.method,
                t.location_id,
                bl.name as location_name,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, '') as employee_name
            ")
            ->orderBy('t.transaction_date')
            ->get();

        // ---- Load Clover payments ----
        $cpQ = \DB::table('clover_payments as cp')
            ->leftJoin('business_locations as bl', 'cp.location_id', '=', 'bl.id')
            ->where('cp.business_id', $business_id)
            ->where(function ($q) {
                $q->whereNull('cp.result')->orWhere('cp.result', 'SUCCESS')->orWhere('cp.result', 'APPROVED');
            })
            ->whereDate('cp.paid_on', '>=', $start)
            ->whereDate('cp.paid_on', '<=', $end);
        if (!empty($location_id)) {
            $cpQ->where('cp.location_id', $location_id);
        }
        $cpRows = $cpQ->selectRaw("
                cp.id as row_id,
                cp.clover_payment_id,
                cp.clover_order_id,
                cp.paid_at as ts,
                cp.amount,
                cp.tender_type,
                cp.card_type,
                cp.card_last4,
                cp.location_id,
                bl.name as location_name,
                cp.employee_name
            ")
            ->orderBy('cp.paid_at')
            ->get();

        // ---- Greedy 1-to-1 match: for each Clover payment, find the
        // ----  nearest-time ERP payment with same first-name + same amount
        // ----  within ±60s that isn't already claimed.
        $claimedErp = [];
        $matched = [];
        $cloverOnly = [];

        foreach ($cpRows as $cp) {
            $cpName = $firstName($cp->employee_name);
            $cpAmt = round((float) $cp->amount, 2);
            $cpTs  = strtotime((string) $cp->ts);

            $bestIdx = null;
            $bestDelta = PHP_INT_MAX;
            foreach ($erpRows as $i => $er) {
                if (isset($claimedErp[$i])) continue;
                if (round((float) $er->amount, 2) !== $cpAmt) continue;
                if ($cpName !== '' && $firstName($er->employee_name) !== $cpName) continue;
                $delta = abs(strtotime((string) $er->ts) - $cpTs);
                if ($delta > 60) continue;
                if ($delta < $bestDelta) {
                    $bestDelta = $delta;
                    $bestIdx = $i;
                }
            }

            if ($bestIdx !== null) {
                $claimedErp[$bestIdx] = true;
                $matched[] = (object) [
                    'ts' => $cp->ts,
                    'amount' => $cpAmt,
                    'cashier' => $cpName ?: $firstName($erpRows[$bestIdx]->employee_name),
                    'location_id' => $cp->location_id ?: $erpRows[$bestIdx]->location_id,
                    'location_name' => $cp->location_name ?: $erpRows[$bestIdx]->location_name,
                    'clover_payment_id' => $cp->clover_payment_id,
                    'erp_invoice_no' => $erpRows[$bestIdx]->invoice_no,
                    'erp_transaction_id' => $erpRows[$bestIdx]->transaction_id,
                    'delta_sec' => $bestDelta,
                ];
            } else {
                $cloverOnly[] = (object) [
                    'ts' => $cp->ts,
                    'amount' => $cpAmt,
                    'cashier' => $cpName,
                    'location_id' => $cp->location_id,
                    'location_name' => $cp->location_name,
                    'clover_payment_id' => $cp->clover_payment_id,
                    'tender_type' => $cp->tender_type,
                    'card' => trim(($cp->card_type ?? '') . ($cp->card_last4 ? ' ****' . $cp->card_last4 : '')),
                ];
            }
        }

        $erpOnly = [];
        foreach ($erpRows as $i => $er) {
            if (isset($claimedErp[$i])) continue;
            $erpOnly[] = (object) [
                'ts' => $er->ts,
                'amount' => round((float) $er->amount, 2),
                'cashier' => $firstName($er->employee_name),
                'location_id' => $er->location_id,
                'location_name' => $er->location_name ?: '(no location)',
                'erp_invoice_no' => $er->invoice_no,
                'erp_transaction_id' => $er->transaction_id,
                'method' => $er->method,
            ];
        }

        // ---- Group into per-cashier buckets + "Online / automated" bucket for
        // ----  pinless Clover rows.
        $byCashier = [];
        $ensure = function (&$byCashier, $key, $displayName, $locationId, $locationName) {
            if (!isset($byCashier[$key])) {
                $byCashier[$key] = [
                    'display_name' => $displayName,
                    'matched' => [], 'clover_only' => [], 'erp_only' => [],
                    'location_id' => $locationId,
                    'location_name' => $locationName ?: '(no location)',
                    'totals' => ['matched' => 0.0, 'clover_only' => 0.0, 'erp_only' => 0.0],
                ];
            }
        };

        foreach ($matched as $m) {
            $key = ($m->location_id ?: 0) . '|' . ($m->cashier ?: 'unknown');
            $ensure($byCashier, $key, ucfirst($m->cashier ?: 'Unknown'), $m->location_id, $m->location_name);
            $byCashier[$key]['matched'][] = $m;
            $byCashier[$key]['totals']['matched'] += $m->amount;
        }

        $online = ['clover_only' => [], 'total' => 0.0];
        foreach ($cloverOnly as $c) {
            if ($c->cashier === '') {
                $online['clover_only'][] = $c;
                $online['total'] += $c->amount;
                continue;
            }
            $key = ($c->location_id ?: 0) . '|' . $c->cashier;
            $ensure($byCashier, $key, ucfirst($c->cashier), $c->location_id, $c->location_name);
            $byCashier[$key]['clover_only'][] = $c;
            $byCashier[$key]['totals']['clover_only'] += $c->amount;
        }
        foreach ($erpOnly as $e) {
            $key = ($e->location_id ?: 0) . '|' . ($e->cashier ?: 'unknown');
            $ensure($byCashier, $key, ucfirst($e->cashier ?: 'Unknown'), $e->location_id, $e->location_name);
            $byCashier[$key]['erp_only'][] = $e;
            $byCashier[$key]['totals']['erp_only'] += $e->amount;
        }

        // Sort cashiers by location, then by name, stable.
        uasort($byCashier, function ($a, $b) {
            return strcmp($a['location_name'], $b['location_name'])
                ?: strcmp($a['display_name'], $b['display_name']);
        });

        $totals = [
            'matched'     => array_sum(array_column(array_values($byCashier), 'totals.matched')) ?: 0.0,
            'clover_only' => array_sum(array_map(fn($c) => $c['totals']['clover_only'], $byCashier)),
            'erp_only'    => array_sum(array_map(fn($c) => $c['totals']['erp_only'], $byCashier)),
            'online'      => $online['total'],
            'matched_count' => array_sum(array_map(fn($c) => count($c['matched']), $byCashier)),
            'clover_only_count' => array_sum(array_map(fn($c) => count($c['clover_only']), $byCashier))
                                + count($online['clover_only']),
            'erp_only_count' => array_sum(array_map(fn($c) => count($c['erp_only']), $byCashier)),
        ];
        // Recompute matched total since the array_column trick above
        // doesn't traverse dot paths.
        $totals['matched'] = array_sum(array_map(fn($c) => $c['totals']['matched'], $byCashier));

        return [
            'by_cashier' => $byCashier,
            'online' => $online,
            'totals' => $totals,
        ];
    }

    /**
     * Keyed map of CloverReconciliation rows for (day, location) pairs
     * inside the window. Key = "YYYY-MM-DD|locId" with locId=0 used for
     * the null/no-location bucket — matches the cloverEodEmployeeBreakdown
     * bucket key shape so the blade can look up by $day . '|' . $locKey.
     */
    private function loadReconciliations($business_id, $start, $end): array
    {
        $rows = \App\CloverReconciliation::where('business_id', $business_id)
            ->whereBetween('day', [$start, $end])
            ->with('user:id,first_name,last_name,username')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $loc = $r->location_id === null ? 0 : (int) $r->location_id;
            $key = $r->day->format('Y-m-d') . '|' . $loc;
            $out[$key] = $r;
        }
        return $out;
    }

    /**
     * Toggle the ✓ reconciled status for one (location, day). First click
     * stamps reconciled_by + reconciled_at; re-click clears them (undo).
     * Notes are preserved across the toggle.
     *
     * Route: POST /reports/clover-eod/mark-reconciled
     */
    public function cloverEodMarkReconciled(Request $request)
    {
        if (!$this->businessUtil->is_admin(auth()->user()) && !auth()->user()->can('purchase_n_sell_report.view')) {
            return response()->json(['success' => false, 'msg' => 'Unauthorized.'], 403);
        }
        $business_id = (int) $request->session()->get('user.business_id');
        $day = $request->input('day');
        $locationId = $request->input('location_id'); // may be '' / '0' for no-location bucket
        if (!$day) return response()->json(['success' => false, 'msg' => 'day required'], 422);

        $row = \App\CloverReconciliation::findOrCreateFor($business_id, $locationId, $day);
        if ($row->reconciled_at) {
            $row->reconciled_by_user_id = null;
            $row->reconciled_at = null;
        } else {
            $row->reconciled_by_user_id = optional(auth()->user())->id;
            $row->reconciled_at = now();
        }
        $row->save();
        $row->load('user:id,first_name,last_name,username');

        return response()->json([
            'success' => true,
            'reconciled' => (bool) $row->reconciled_at,
            'reconciled_at' => $row->reconciled_at ? $row->reconciled_at->format('M j, g:i a') : null,
            'reconciled_by' => $row->user
                ? trim(($row->user->first_name ?? '') . ' ' . ($row->user->last_name ?? '')) ?: $row->user->username
                : null,
        ]);
    }

    /**
     * Save the notes textarea for one (location, day). Called on blur /
     * debounced input from the blade.
     *
     * Route: POST /reports/clover-eod/save-notes
     */
    public function cloverEodSaveNotes(Request $request)
    {
        if (!$this->businessUtil->is_admin(auth()->user()) && !auth()->user()->can('purchase_n_sell_report.view')) {
            return response()->json(['success' => false, 'msg' => 'Unauthorized.'], 403);
        }
        $business_id = (int) $request->session()->get('user.business_id');
        $day = $request->input('day');
        $locationId = $request->input('location_id');
        $notes = (string) $request->input('notes', '');
        if (!$day) return response()->json(['success' => false, 'msg' => 'day required'], 422);

        $row = \App\CloverReconciliation::findOrCreateFor($business_id, $locationId, $day);
        $row->notes = $notes !== '' ? $notes : null;
        $row->save();

        return response()->json(['success' => true, 'saved_at' => now()->format('g:i:s a')]);
    }

    /**
     * List every ERP card sale and every Clover payment in the window whose
     * employee_name resolves to Unknown, with the underlying cause. Feeds
     * the "Why Unknown?" drill-down on the reconciliation report so Sarah
     * can eyeball whether Unknowns are benign (walk-in / online checkout)
     * or a real data problem (deleted user, broken import).
     *
     * @return array ['erp' => [...], 'clover' => [...]]
     */
    private function cloverEodUnknownRows($business_id, $start, $end, $location_id, array $card_methods, $used_all_methods)
    {
        // ERP side — a payment is "Unknown" when the joined users row is
        // missing (deleted user or null created_by on the transaction).
        $erpQ = \DB::table('transaction_payments as tp')
            ->join('transactions as t', 'tp.transaction_id', '=', 't.id')
            ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->leftJoin('users as u', 't.created_by', '=', 'u.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereDate('t.transaction_date', '>=', $start)
            ->whereDate('t.transaction_date', '<=', $end)
            ->whereNull('u.id');  // the join failed → Unknown on the report
        if (!$used_all_methods) {
            $erpQ->whereIn('tp.method', $card_methods);
        }
        if (!empty($location_id)) {
            $erpQ->where('t.location_id', $location_id);
        }
        $erpRows = $erpQ->selectRaw("
                DATE(t.transaction_date) as day,
                t.id as transaction_id,
                t.invoice_no,
                t.created_by,
                tp.method,
                tp.amount,
                t.location_id,
                bl.name as location_name")
            ->orderByDesc('t.transaction_date')
            ->limit(500)
            ->get()
            ->map(function ($r) {
                $r->cause = $r->created_by === null
                    ? 'transactions.created_by is null (no cashier attached)'
                    : ('users row #' . $r->created_by . ' deleted or missing');
                return $r;
            });

        // Clover side — employee_name empty at sync time. Usually a Clover
        // online order, self-checkout, or a payment run without a staff pin.
        $cloverQ = \DB::table('clover_payments as cp')
            ->leftJoin('business_locations as bl', 'cp.location_id', '=', 'bl.id')
            ->where('cp.business_id', $business_id)
            ->whereDate('cp.paid_on', '>=', $start)
            ->whereDate('cp.paid_on', '<=', $end)
            ->where(function ($q) {
                $q->whereNull('cp.employee_name')
                  ->orWhereRaw("TRIM(cp.employee_name) = ''");
            })
            ->where(function ($q) {
                $q->whereNull('cp.result')->orWhere('cp.result', 'SUCCESS')->orWhere('cp.result', 'APPROVED');
            });
        if (!empty($location_id)) {
            $cloverQ->where('cp.location_id', $location_id);
        }
        $cloverRows = $cloverQ->selectRaw("
                cp.paid_on as day,
                cp.clover_payment_id,
                cp.clover_order_id,
                cp.tender_type,
                cp.card_type,
                cp.card_last4,
                cp.amount,
                cp.location_id,
                bl.name as location_name")
            ->orderByDesc('cp.paid_at')
            ->limit(500)
            ->get()
            ->map(function ($r) {
                $r->cause = 'Clover payment had no employee pin (likely online / self-checkout / card-on-file)';
                return $r;
            });

        // Clover field-quality diagnostics — rows where key metadata that
        // ops relies on is blank/manual/unknown, even when employee name exists.
        $fieldQ = \DB::table('clover_payments as cp')
            ->leftJoin('business_locations as bl', 'cp.location_id', '=', 'bl.id')
            ->where('cp.business_id', $business_id)
            ->whereDate('cp.paid_on', '>=', $start)
            ->whereDate('cp.paid_on', '<=', $end)
            ->where(function ($q) {
                $q->whereNull('cp.result')->orWhere('cp.result', 'SUCCESS')->orWhere('cp.result', 'APPROVED');
            })
            ->where(function ($q) {
                $q->whereNull('cp.tender_type')->orWhereRaw("TRIM(cp.tender_type) = ''")
                  ->orWhereNull('cp.card_type')->orWhereRaw("TRIM(cp.card_type) = ''")
                  ->orWhereNull('cp.card_last4')->orWhereRaw("TRIM(cp.card_last4) = ''")
                  ->orWhereNull('cp.clover_order_id')->orWhereRaw("TRIM(cp.clover_order_id) = ''");
            });
        if (!empty($location_id)) {
            $fieldQ->where('cp.location_id', $location_id);
        }
        $fieldRows = $fieldQ
            ->selectRaw("
                cp.paid_on as day,
                cp.clover_payment_id,
                cp.clover_order_id,
                cp.employee_name,
                cp.tender_type,
                cp.card_type,
                cp.card_last4,
                cp.amount,
                cp.location_id,
                bl.name as location_name")
            ->orderByDesc('cp.paid_at')
            ->limit(500)
            ->get()
            ->map(function ($r) {
                $missing = [];
                if (empty(trim((string) $r->clover_order_id))) $missing[] = 'missing order id';
                if (empty(trim((string) $r->tender_type))) $missing[] = 'missing tender type';
                if (empty(trim((string) $r->card_type))) $missing[] = 'missing card type';
                if (empty(trim((string) $r->card_last4))) $missing[] = 'missing card last4';
                $r->cause = implode(', ', $missing);
                return $r;
            });

        return [
            'erp' => $erpRows,
            'clover' => $cloverRows,
            'clover_fields' => $fieldRows,
        ];
    }

    /**
     * Per-cashier cash-register shift data for a date range, keyed by
     * (day, location, employee first-name) so it can be joined with the
     * Clover/ERP breakdown below. One shift = one cash_registers row; a
     * cashier opening & closing twice in a day is aggregated (earliest
     * open, latest close, summed cash flows).
     *
     * Returns [ day => [ locKey => [ empKey => [shift_start, shift_end,
     * opening_cash, cash_sales, cash_buys, cash_refunds,
     * expected_ending_cash, reported_ending_cash] ] ] ].
     */
    private function cloverEodShiftData($business_id, $start, $end, $location_id)
    {
        $q = \DB::table('cash_registers as cr')
            ->leftJoin('users as u', 'cr.user_id', '=', 'u.id')
            ->leftJoin('cash_register_transactions as crt', 'cr.id', '=', 'crt.cash_register_id')
            ->where('cr.business_id', $business_id)
            ->whereDate('cr.created_at', '>=', $start)
            ->whereDate('cr.created_at', '<=', $end);
        if (!empty($location_id)) {
            $q->where('cr.location_id', $location_id);
        }
        $rows = $q->selectRaw("
                DATE(cr.created_at) as day,
                cr.location_id,
                cr.id as register_id,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, 'Unknown') as employee_name,
                cr.created_at as opened_at,
                cr.closed_at as closed_at,
                cr.closing_amount as reported_ending_cash,
                SUM(CASE WHEN crt.pay_method='cash' AND crt.transaction_type='initial' THEN crt.amount ELSE 0 END) as opening_cash,
                SUM(CASE WHEN crt.pay_method='cash' AND crt.transaction_type='sell' AND crt.type='credit' THEN crt.amount ELSE 0 END) as cash_sales,
                SUM(CASE WHEN crt.transaction_type='purchase' AND crt.type='debit' THEN crt.amount ELSE 0 END) as collection_buys_all,
                SUM(CASE WHEN crt.pay_method='cash' AND crt.transaction_type='purchase' AND crt.type='debit' THEN crt.amount ELSE 0 END) as cash_buys,
                SUM(CASE WHEN crt.pay_method='cash' AND crt.transaction_type='refund' AND crt.type='debit' THEN crt.amount ELSE 0 END) as cash_refunds,
                SUM(CASE WHEN crt.pay_method='cash' THEN CASE WHEN crt.type='credit' THEN crt.amount ELSE -crt.amount END ELSE 0 END) as cash_net
            ")
            ->groupBy('cr.id', DB::raw('DATE(cr.created_at)'), 'cr.location_id',
                'employee_name', 'cr.created_at', 'cr.closed_at', 'cr.closing_amount')
            ->get();

        $firstName = function ($full) {
            $full = trim((string) $full);
            if ($full === '') return 'unknown';
            $parts = preg_split('/\s+/', $full);
            return strtolower($parts[0] ?? 'unknown');
        };

        $out = [];
        foreach ($rows as $s) {
            $day = $s->day;
            $locKey = $s->location_id ?: 0;
            $empKey = $firstName($s->employee_name);
            if (!isset($out[$day][$locKey][$empKey])) {
                $out[$day][$locKey][$empKey] = [
                    'shift_start' => null, 'shift_end' => null,
                    'shift_status' => 'closed',
                    'opening_cash' => 0.0, 'cash_sales' => 0.0,
                    'cash_buys' => 0.0, 'cash_refunds' => 0.0,
                    'collection_buys_all' => 0.0,
                    'expected_ending_cash' => 0.0, 'reported_ending_cash' => 0.0,
                ];
            }
            $row = &$out[$day][$locKey][$empKey];
            if (!$row['shift_start'] || $s->opened_at < $row['shift_start']) $row['shift_start'] = $s->opened_at;
            if ($s->closed_at && (!$row['shift_end'] || $s->closed_at > $row['shift_end'])) $row['shift_end'] = $s->closed_at;
            if (empty($s->closed_at)) $row['shift_status'] = 'open';
            $row['opening_cash'] += (float) $s->opening_cash;
            $row['cash_sales'] += (float) $s->cash_sales;
            $row['cash_buys'] += (float) $s->cash_buys;
            $row['cash_refunds'] += (float) $s->cash_refunds;
            $row['collection_buys_all'] += (float) $s->collection_buys_all;
            $row['expected_ending_cash'] += (float) $s->cash_net;
            $row['reported_ending_cash'] += (float) $s->reported_ending_cash;
            unset($row);
        }
        return $out;
    }

    /**
     * Per-cashier Clover vs ERP totals for a date range, grouped by day and
     * then by location. Returns an ordered array (most recent day first) of
     * [ 'day' => 'YYYY-MM-DD', 'locations' => [...] ] entries, where each
     * location entry is the same shape as cloverEodEmployeeBreakdown returns
     * for a single day. Pulls ERP + Clover each in a single grouped query
     * across the range (not per-day) so a 30-day backfill is 2 queries
     * instead of 60.
     */
    private function cloverEodEmployeeBreakdownRange($business_id, $start, $end, $location_id, array $card_methods, $used_all_methods)
    {
        $erpQ = \DB::table('transaction_payments as tp')
            ->join('transactions as t', 'tp.transaction_id', '=', 't.id')
            ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->leftJoin('users as u', 't.created_by', '=', 'u.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereDate('t.transaction_date', '>=', $start)
            ->whereDate('t.transaction_date', '<=', $end);
        if (!$used_all_methods) {
            $erpQ->whereIn('tp.method', $card_methods);
        }
        if (!empty($location_id)) {
            $erpQ->where('t.location_id', $location_id);
        }
        $erpRows = $erpQ->selectRaw("DATE(t.transaction_date) as day,
                t.location_id, bl.name as location_name,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, 'Unknown') as employee_name,
                COUNT(tp.id) as erp_count,
                COALESCE(SUM(tp.amount), 0) as erp_total")
            ->groupBy(DB::raw('DATE(t.transaction_date)'), 't.location_id', 'bl.name', 'employee_name')
            ->get();

        $cloverQ = \DB::table('clover_payments as cp')
            ->leftJoin('business_locations as bl', 'cp.location_id', '=', 'bl.id')
            ->where('cp.business_id', $business_id)
            ->where(function ($q) {
                $q->whereNull('cp.result')->orWhere('cp.result', 'SUCCESS')->orWhere('cp.result', 'APPROVED');
            })
            ->whereDate('cp.paid_on', '>=', $start)
            ->whereDate('cp.paid_on', '<=', $end);
        if (!empty($location_id)) {
            $cloverQ->where(function ($q) use ($location_id) {
                $q->where('cp.location_id', $location_id)->orWhereNull('cp.location_id');
            });
        }
        $cloverRows = $cloverQ->selectRaw("DATE(cp.paid_on) as day,
                cp.location_id, bl.name as location_name,
                COALESCE(NULLIF(TRIM(cp.employee_name), ''), 'Unknown') as employee_name,
                COUNT(*) as clover_count,
                COALESCE(SUM(cp.amount), 0) as clover_total")
            ->groupBy(DB::raw('DATE(cp.paid_on)'), 'cp.location_id', 'bl.name', 'employee_name')
            ->get();

        $firstName = function ($full) {
            $full = trim((string) $full);
            if ($full === '') return 'unknown';
            $parts = preg_split('/\s+/', $full);
            return strtolower($parts[0] ?? 'unknown');
        };

        // Bucket into [day][locKey][empKey] => running totals.
        $buckets = [];
        foreach ($erpRows as $r) {
            $day = $r->day;
            $locKey = $r->location_id ?: 0;
            $empKey = $firstName($r->employee_name);
            $buckets[$day][$locKey]['location_name'] = $r->location_name ?: '(no location)';
            if (!isset($buckets[$day][$locKey]['employees'][$empKey])) {
                $buckets[$day][$locKey]['employees'][$empKey] = [
                    'display_name' => ucfirst($empKey),
                    'erp_total' => 0.0, 'erp_count' => 0,
                    'clover_total' => 0.0, 'clover_count' => 0,
                    'shift_start' => null, 'shift_end' => null, 'shift_status' => null,
                    'opening_cash' => null, 'cash_sales' => 0.0,
                    'cash_buys' => 0.0, 'collection_buys_all' => 0.0,
                    'expected_ending_cash' => null, 'reported_ending_cash' => null,
                    'cash_variance' => null, 'has_shift' => false,
                ];
            }
            $buckets[$day][$locKey]['employees'][$empKey]['erp_total'] += (float) $r->erp_total;
            $buckets[$day][$locKey]['employees'][$empKey]['erp_count'] += (int) $r->erp_count;
        }
        foreach ($cloverRows as $r) {
            $day = $r->day;
            $locKey = $r->location_id ?: 0;
            $empKey = $firstName($r->employee_name);
            $buckets[$day][$locKey]['location_name'] = $buckets[$day][$locKey]['location_name']
                ?? ($r->location_name ?: '(unlinked Clover MID)');
            if (!isset($buckets[$day][$locKey]['employees'][$empKey])) {
                $buckets[$day][$locKey]['employees'][$empKey] = [
                    'display_name' => ucfirst($empKey),
                    'erp_total' => 0.0, 'erp_count' => 0,
                    'clover_total' => 0.0, 'clover_count' => 0,
                    'shift_start' => null, 'shift_end' => null, 'shift_status' => null,
                    'opening_cash' => null, 'cash_sales' => 0.0,
                    'cash_buys' => 0.0, 'collection_buys_all' => 0.0,
                    'expected_ending_cash' => null, 'reported_ending_cash' => null,
                    'cash_variance' => null, 'has_shift' => false,
                ];
            }
            $buckets[$day][$locKey]['employees'][$empKey]['clover_total'] += (float) $r->clover_total;
            $buckets[$day][$locKey]['employees'][$empKey]['clover_count'] += (int) $r->clover_count;
        }

        // Overlay cash-register shift data so each employee row gets
        // shift_start/end, opening + expected + reported cash, and
        // collection-buy totals alongside the Clover/ERP numbers. Missing
        // shifts stay null (shown as "—" in the UI) so a cashier who
        // rang sales on someone else's open register is still visible,
        // just without their own drawer audit.
        $shiftData = $this->cloverEodShiftData($business_id, $start, $end, $location_id);
        foreach ($shiftData as $day => $locs) {
            foreach ($locs as $locKey => $emps) {
                foreach ($emps as $empKey => $shift) {
                    if (!isset($buckets[$day][$locKey]['employees'][$empKey])) {
                        $buckets[$day][$locKey]['employees'][$empKey] = [
                            'display_name' => ucfirst($empKey),
                            'erp_total' => 0.0, 'erp_count' => 0,
                            'clover_total' => 0.0, 'clover_count' => 0,
                            'shift_start' => null, 'shift_end' => null, 'shift_status' => null,
                            'opening_cash' => null, 'cash_sales' => 0.0,
                            'cash_buys' => 0.0, 'collection_buys_all' => 0.0,
                            'expected_ending_cash' => null, 'reported_ending_cash' => null,
                            'cash_variance' => null, 'has_shift' => false,
                        ];
                        $buckets[$day][$locKey]['location_name'] = $buckets[$day][$locKey]['location_name']
                            ?? '(no location)';
                    }
                    $e = &$buckets[$day][$locKey]['employees'][$empKey];
                    $e['has_shift'] = true;
                    $e['shift_start'] = $shift['shift_start'];
                    $e['shift_end'] = $shift['shift_end'];
                    $e['shift_status'] = $shift['shift_status'];
                    $e['opening_cash'] = (float) $shift['opening_cash'];
                    $e['cash_sales'] = (float) $shift['cash_sales'];
                    $e['cash_buys'] = (float) $shift['cash_buys'];
                    $e['collection_buys_all'] = (float) $shift['collection_buys_all'];
                    $e['expected_ending_cash'] = (float) $shift['expected_ending_cash'];
                    $e['reported_ending_cash'] = (float) $shift['reported_ending_cash'];
                    // Variance only meaningful for CLOSED shifts — open shifts
                    // haven't had a reported count yet so the diff is noise.
                    if ($shift['shift_status'] === 'closed') {
                        $e['cash_variance'] = round(
                            (float) $shift['reported_ending_cash'] - (float) $shift['expected_ending_cash'],
                            2
                        );
                    }
                    unset($e);
                }
            }
        }

        // Finalize: compute differences, sort employees by abs-diff desc,
        // sort locations alphabetically, sort days most-recent first.
        $out = [];
        foreach ($buckets as $day => $locs) {
            $dayLocs = [];
            foreach ($locs as $locKey => $loc) {
                $emps = $loc['employees'] ?? [];
                $emps = array_map(function ($e) {
                    $e['difference'] = round($e['clover_total'] - $e['erp_total'], 2);
                    return $e;
                }, $emps);
                uasort($emps, fn($a, $b) => abs($b['difference']) <=> abs($a['difference']));
                $totals = [
                    'clover_total' => array_sum(array_column($emps, 'clover_total')),
                    'erp_total'    => array_sum(array_column($emps, 'erp_total')),
                ];
                $totals['difference'] = round($totals['clover_total'] - $totals['erp_total'], 2);
                $dayLocs[] = [
                    'location_id' => $locKey,
                    'location_name' => $loc['location_name'],
                    'employees' => array_values($emps),
                    'totals' => $totals,
                ];
            }
            usort($dayLocs, fn($a, $b) => strcmp($a['location_name'], $b['location_name']));
            $out[] = ['day' => $day, 'locations' => $dayLocs];
        }
        usort($out, fn($a, $b) => strcmp($b['day'], $a['day']));
        return $out;
    }

    /**
     * Legacy single-day helper kept in place for any callers that still want
     * the old shape. New code should use cloverEodEmployeeBreakdownRange.
     */
    private function cloverEodEmployeeBreakdown($business_id, $day, $location_id, array $card_methods, $used_all_methods)
    {
        // ERP side — one row per (location_id, created_by user) with their
        // card-method payment totals for the day.
        $erpQ = \DB::table('transaction_payments as tp')
            ->join('transactions as t', 'tp.transaction_id', '=', 't.id')
            ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->leftJoin('users as u', 't.created_by', '=', 'u.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereDate('t.transaction_date', $day);
        if (!$used_all_methods) {
            $erpQ->whereIn('tp.method', $card_methods);
        }
        if (!empty($location_id)) {
            $erpQ->where('t.location_id', $location_id);
        }
        $erpRows = $erpQ->selectRaw("t.location_id, bl.name as location_name,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, 'Unknown') as employee_name,
                COUNT(tp.id) as erp_count,
                COALESCE(SUM(tp.amount), 0) as erp_total")
            ->groupBy('t.location_id', 'bl.name', 'employee_name')
            ->get();

        // Clover side — one row per (location_id, employee_name).
        $cloverQ = \DB::table('clover_payments as cp')
            ->leftJoin('business_locations as bl', 'cp.location_id', '=', 'bl.id')
            ->where('cp.business_id', $business_id)
            ->where(function ($q) {
                $q->whereNull('cp.result')->orWhere('cp.result', 'SUCCESS')->orWhere('cp.result', 'APPROVED');
            })
            ->whereDate('cp.paid_on', $day);
        if (!empty($location_id)) {
            $cloverQ->where('cp.location_id', $location_id);
        }
        $cloverRows = $cloverQ->selectRaw("cp.location_id, bl.name as location_name,
                COALESCE(NULLIF(TRIM(cp.employee_name), ''), 'Unknown') as employee_name,
                COUNT(*) as clover_count,
                COALESCE(SUM(cp.amount), 0) as clover_total")
            ->groupBy('cp.location_id', 'bl.name', 'employee_name')
            ->get();

        // Normalize + merge. Key = location_id + '|' + first-name-lowercased.
        $firstName = function ($full) {
            $full = trim((string) $full);
            if ($full === '') return 'unknown';
            $parts = preg_split('/\s+/', $full);
            return strtolower($parts[0] ?? 'unknown');
        };

        // Default employee-row skeleton — must match the shape the view
        // reads ($e['opening_cash'], $e['cash_sales'], etc.). Keeping every
        // key initialized to null/0 here means the single-day code path
        // renders even when there's no matching cash-register shift to
        // overlay. (Previously those keys were missing and the blade blew
        // up with "Undefined index: opening_cash".)
        $blankEmp = function (string $empKey) {
            return [
                'display_name' => ucfirst($empKey),
                'erp_total' => 0.0, 'erp_count' => 0,
                'clover_total' => 0.0, 'clover_count' => 0,
                'shift_start' => null, 'shift_end' => null, 'shift_status' => null,
                'opening_cash' => null, 'cash_sales' => 0.0,
                'cash_buys' => 0.0, 'collection_buys_all' => 0.0,
                'expected_ending_cash' => null, 'reported_ending_cash' => null,
                'cash_variance' => null, 'has_shift' => false,
            ];
        };

        $byLoc = [];
        foreach ($erpRows as $r) {
            $locKey = $r->location_id ?: 0;
            $empKey = $firstName($r->employee_name);
            $byLoc[$locKey]['location_name'] = $r->location_name ?: '(no location)';
            if (!isset($byLoc[$locKey]['employees'][$empKey])) {
                $byLoc[$locKey]['employees'][$empKey] = $blankEmp($empKey);
            }
            $byLoc[$locKey]['employees'][$empKey]['erp_total']  += (float) $r->erp_total;
            $byLoc[$locKey]['employees'][$empKey]['erp_count']  += (int) $r->erp_count;
        }
        foreach ($cloverRows as $r) {
            $locKey = $r->location_id ?: 0;
            $empKey = $firstName($r->employee_name);
            $byLoc[$locKey]['location_name'] = $byLoc[$locKey]['location_name'] ?? ($r->location_name ?: '(unlinked Clover MID)');
            if (!isset($byLoc[$locKey]['employees'][$empKey])) {
                $byLoc[$locKey]['employees'][$empKey] = $blankEmp($empKey);
            }
            $byLoc[$locKey]['employees'][$empKey]['clover_total'] += (float) $r->clover_total;
            $byLoc[$locKey]['employees'][$empKey]['clover_count'] += (int) $r->clover_count;
        }

        // Overlay shift data for the single day so the new cash columns
        // (opening / expected / reported / variance) light up for cashiers
        // who had an actual register open. Same helper the range variant
        // uses — called with start=end=$day so it returns a single-day map.
        $shiftData = $this->cloverEodShiftData($business_id, $day, $day, $location_id);
        $dayShifts = $shiftData[$day] ?? [];
        foreach ($dayShifts as $locKey => $emps) {
            foreach ($emps as $empKey => $shift) {
                if (!isset($byLoc[$locKey]['employees'][$empKey])) {
                    $byLoc[$locKey]['employees'][$empKey] = $blankEmp($empKey);
                    $byLoc[$locKey]['location_name'] = $byLoc[$locKey]['location_name'] ?? '(no location)';
                }
                $e = &$byLoc[$locKey]['employees'][$empKey];
                $e['has_shift'] = true;
                $e['shift_start'] = $shift['shift_start'];
                $e['shift_end'] = $shift['shift_end'];
                $e['shift_status'] = $shift['shift_status'];
                $e['opening_cash'] = (float) $shift['opening_cash'];
                $e['cash_sales'] = (float) $shift['cash_sales'];
                $e['cash_buys'] = (float) $shift['cash_buys'];
                $e['collection_buys_all'] = (float) $shift['collection_buys_all'];
                $e['expected_ending_cash'] = (float) $shift['expected_ending_cash'];
                $e['reported_ending_cash'] = (float) $shift['reported_ending_cash'];
                if ($shift['shift_status'] === 'closed') {
                    $e['cash_variance'] = round(
                        (float) $shift['reported_ending_cash'] - (float) $shift['expected_ending_cash'],
                        2
                    );
                }
                unset($e);
            }
        }

        // Finalize: sort employees by abs-difference desc so biggest mismatches
        // float to top of each card.
        $result = [];
        foreach ($byLoc as $locKey => $loc) {
            $emps = $loc['employees'] ?? [];
            $emps = array_map(function ($e) {
                $e['difference'] = round($e['clover_total'] - $e['erp_total'], 2);
                return $e;
            }, $emps);
            uasort($emps, fn($a, $b) => abs($b['difference']) <=> abs($a['difference']));
            $totals = [
                'clover_total' => array_sum(array_column($emps, 'clover_total')),
                'erp_total'    => array_sum(array_column($emps, 'erp_total')),
            ];
            $totals['difference'] = round($totals['clover_total'] - $totals['erp_total'], 2);
            $result[] = [
                'location_id' => $locKey,
                'location_name' => $loc['location_name'],
                'employees' => array_values($emps),
                'totals' => $totals,
            ];
        }
        // Sort locations alphabetically so Hollywood + Pico show in predictable order.
        usort($result, fn($a, $b) => strcmp($a['location_name'], $b['location_name']));
        return $result;
    }

    /**
     * Walk-in buy history — drill-down for the "Walk-in buys" chip on the
     * Purchase Report's per-location cards (Sarah 2026-04-22 "let me click
     * on walk in buys to see a history of the collections we bought").
     *
     * Same filter surface as purchaseReportSummary() so dates / location
     * chips in the UI seamlessly drive what the modal shows. Qualifies as
     * a walk-in buy when EITHER:
     *   (a) additional_notes starts with "Buy from customer" — current
     *       BuyFromCustomerController stamps this format; OR
     *   (b) contact name is one of the generic walk-in / customer
     *       labels the legacy in-store flow used.
     *
     * Returns purchase txns + their purchase_lines (product name, artist,
     * qty, unit cost) so the modal can show what was actually in each
     * collection, not just a dollar total.
     */
    public function purchaseReportWalkinHistory(Request $request)
    {
        if ((!auth()->user()->can('purchase.view') && !auth()->user()->can('purchase.create') && !auth()->user()->can('view_own_purchase')) || empty(config('constants.show_report_606'))) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $request->session()->get('user.business_id');

        $q = \DB::table('transactions as t')
            ->leftJoin('contacts as c', 't.contact_id', '=', 'c.id')
            ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->leftJoin('users as u', 't.created_by', '=', 'u.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'purchase')
            ->where(function ($q) {
                $q->where('t.additional_notes', 'like', 'Buy from customer%')
                  ->orWhereRaw("LOWER(COALESCE(c.name,'')) IN ('walk-in', 'walkin customer', 'walk in customer', 'customer')")
                  ->orWhere('c.name', 'like', 'Walk-In%');
            });

        $permitted = auth()->user()->permitted_locations();
        if ($permitted !== 'all') {
            $q->whereIn('t.location_id', $permitted);
        }
        if (!empty($request->location_id)) {
            $q->where('t.location_id', $request->location_id);
        }
        if (!empty($request->start_date) && !empty($request->end_date)) {
            $q->whereDate('t.transaction_date', '>=', $request->start_date)
              ->whereDate('t.transaction_date', '<=', $request->end_date);
        }

        $txns = $q->orderByDesc('t.transaction_date')
            ->limit(200)
            ->select(
                't.id',
                't.transaction_date',
                't.final_total',
                't.total_before_tax',
                't.additional_notes',
                't.status',
                't.payment_status',
                'bl.name as location_name',
                'c.name as seller_name',
                'c.mobile as seller_mobile',
                \DB::raw("TRIM(CONCAT_WS(' ', COALESCE(u.first_name,''), COALESCE(u.last_name,''))) as cashier_name")
            )
            ->get();

        // Pull all the purchase_lines for the resulting set in one shot,
        // then attach to the owning txn — avoids N+1.
        $ids = $txns->pluck('id')->all();
        $lines = collect([]);
        if (!empty($ids)) {
            $hasLegacyArtist = \Illuminate\Support\Facades\Schema::hasColumn('purchase_lines', 'legacy_artist');
            $hasLegacyTitle  = \Illuminate\Support\Facades\Schema::hasColumn('purchase_lines', 'legacy_title');
            $selectCols = [
                'pl.transaction_id',
                'pl.quantity',
                'pl.purchase_price',
                'p.name as product_name',
                'p.artist as product_artist',
                'p.sku as product_sku',
            ];
            if ($hasLegacyArtist) $selectCols[] = 'pl.legacy_artist';
            if ($hasLegacyTitle)  $selectCols[] = 'pl.legacy_title';

            $lines = \DB::table('purchase_lines as pl')
                ->leftJoin('products as p', 'pl.product_id', '=', 'p.id')
                ->whereIn('pl.transaction_id', $ids)
                ->select($selectCols)
                ->get()
                ->groupBy('transaction_id');
        }

        // Parse the "Buy from customer {offer_id} | payout: {X} | payment:
        // {Y} | record: {Z}" notes format — the numbers / labels in there
        // are the most reliable way to show payout-type + buy record
        // number on the modal.
        $payload = $txns->map(function ($t) use ($lines) {
            $offer_id = null; $payout = null; $pm = null; $record = null;
            if ($t->additional_notes && preg_match('/Buy from customer (\d+)/', $t->additional_notes, $m)) $offer_id = $m[1];
            if ($t->additional_notes && preg_match('/payout: ([^|]+)/', $t->additional_notes, $m)) $payout = trim($m[1]);
            if ($t->additional_notes && preg_match('/payment: ([^|]+)/', $t->additional_notes, $m)) $pm = trim($m[1]);
            if ($t->additional_notes && preg_match('/record: (\S+)/', $t->additional_notes, $m)) $record = trim($m[1]);

            return [
                'id' => $t->id,
                'date' => $t->transaction_date,
                'total' => (float) $t->final_total,
                'location_name' => $t->location_name,
                'seller_name' => $t->seller_name ?: '(walk-in, no contact)',
                'seller_mobile' => $t->seller_mobile,
                'cashier_name' => $t->cashier_name ?: 'unknown',
                'status' => $t->status,
                'payment_status' => $t->payment_status,
                'offer_id' => $offer_id,
                'payout_type' => $payout,
                'payment_method' => $pm,
                'buy_record' => $record,
                'lines' => ($lines->get($t->id) ?? collect([]))->map(function ($l) {
                    $name = $l->product_name;
                    $artist = $l->product_artist;
                    if (empty($name)  && !empty($l->legacy_title))  $name  = $l->legacy_title;
                    if (empty($artist) && !empty($l->legacy_artist)) $artist = $l->legacy_artist;
                    return [
                        'artist' => $artist,
                        'name'   => $name,
                        'sku'    => $l->product_sku,
                        'qty'    => (float) $l->quantity,
                        'unit'   => (float) $l->purchase_price,
                        'subtotal' => (float) $l->quantity * (float) $l->purchase_price,
                    ];
                })->values(),
            ];
        });

        return response()->json([
            'count' => $payload->count(),
            'limit' => 200,
            'txns' => $payload->values(),
        ]);
    }

    private function reconciliationStatus($variance)
    {
        $abs = abs($variance);
        if ($abs < 1.00) return 'reconciled';
        if ($abs < 10.00) return 'minor';
        return 'review';
    }

    /**
     * Web-triggerable wrapper around the `clover:sync-payments` artisan
     * command so Sarah can kick the sync from the reconciliation page
     * (2026-04-22: "Clover data is not pulling in yet"). Captures stdout
     * and returns it verbatim so a failed API call, missing credentials,
     * or a zero-payment day is all visible in the UI instead of buried
     * in /storage/logs/laravel.log.
     *
     * Admin-only; always runs with --days=2 so it matches the scheduled
     * overnight job.
     */
    public function cloverEodSyncNow(Request $request)
    {
        if (!$this->businessUtil->is_admin(auth()->user())) {
            return response()->json(['success' => false, 'output' => 'Unauthorized.'], 403);
        }

        // Upper bound at 90 days so a Backfill click can cover a quarter of
        // history in one shot (enough to catch up a fresh install) without
        // letting an accidental "all time" request hammer Clover's API.
        $days = max(1, min(90, (int) $request->input('days', 2)));

        $buffer = new \Symfony\Component\Console\Output\BufferedOutput();
        try {
            $exitCode = \Illuminate\Support\Facades\Artisan::call(
                'clover:sync-payments',
                ['--days' => $days],
                $buffer
            );
            $output = $buffer->fetch();

            // Count how many rows actually landed in the last `$days`
            // window so we can tell the caller whether the sync produced
            // data — helps distinguish "sync ran, Clover returned 0
            // payments" from "sync errored out".
            $business_id = $request->session()->get('user.business_id');
            $since = \Carbon::now()->subDays($days)->startOfDay();
            $rowsCreatedInWindow = \DB::table('clover_payments')
                ->where('business_id', $business_id)
                ->where('created_at', '>=', $since)
                ->count();
            $rowsInWindow = \DB::table('clover_payments')
                ->where('business_id', $business_id)
                ->where('paid_on', '>=', $since->toDateString())
                ->count();

            return response()->json([
                'success' => $exitCode === 0,
                'exit_code' => $exitCode,
                'days' => $days,
                'output' => $output ?: '(no output — check logs)',
                'rows_recently_written' => $rowsCreatedInWindow,
                'rows_in_window' => $rowsInWindow,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'exit_code' => 1,
                'output' => 'Sync threw an exception: ' . $e->getMessage()
                    . "\nFile: " . $e->getFile() . ':' . $e->getLine()
                    . "\n\nPartial output:\n" . $buffer->fetch(),
            ], 500);
        }
    }

    /**
     * Employee Sales Leaderboard
     *
     * Ranks employees by sales revenue for a selected window. Also surfaces
     * items rung, items priced, avg $/transaction, and revenue driven by
     * items the employee personally barcoded. Used on /reports/employee-leaderboard
     * and as a small "top 3" widget on the home dashboard.
     */
    public function employeeLeaderboard(Request $request)
    {
        // $/hour comparison across staff — admin-only (Sarah 2026-04-28).
        $this->ensureAdminOnlyReportAccess();
        $business_id = $request->session()->get('user.business_id');
        $period = $request->input('period', 'this_month');

        // Resolve the window
        $now = \Carbon::now();
        switch ($period) {
            case 'today':
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
            case 'yesterday':
                $start = $now->copy()->subDay()->startOfDay();
                $end = $now->copy()->subDay()->endOfDay();
                break;
            case 'this_week':
                $start = $now->copy()->startOfWeek();
                $end = $now->copy()->endOfDay();
                break;
            case 'last_7':
                $start = $now->copy()->subDays(6)->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
            case 'last_30':
                $start = $now->copy()->subDays(29)->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
            case 'this_quarter':
                $start = $now->copy()->startOfQuarter();
                $end = $now->copy()->endOfDay();
                break;
            case 'this_month':
            default:
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfDay();
                $period = 'this_month';
                break;
        }
        $start_str = $start->toDateTimeString();
        $end_str = $end->toDateTimeString();

        $rows = $this->buildLeaderboardRows($business_id, $start_str, $end_str);

        // Optional column sort (URL params). Default is the builder's own order
        // (revenue per hour desc, nulls last) — which preserves gold/silver/bronze.
        $sort = $request->input('sort');
        $dir  = strtolower($request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sort_keys = ['employee','revenue','tx_count','items_rung','avg_tx','priced_count','priced_revenue','hours_worked','revenue_per_hour','items_per_hour','tx_per_hour'];
        if (in_array($sort, $sort_keys)) {
            $rows = $rows->sortBy(function ($r) use ($sort) {
                $v = $r->$sort ?? null;
                // Null values sort after real values regardless of direction
                return $v;
            }, SORT_REGULAR, $dir === 'desc')->values();
        }

        return view('report.employee_leaderboard')->with(compact(
            'rows', 'period', 'start', 'end', 'sort', 'dir'
        ));
    }

    /**
     * Internal: build the employee leaderboard rows for a business + window.
     * Ranked by revenue per hour — hours come from cash_registers open/close.
     * Returned as a keyed-by-user Collection so it can power both the full
     * page and the dashboard top-3 widget.
     */
    public function buildLeaderboardRows($business_id, $start, $end, $limit = null)
    {
        // Hours worked per user in this window, derived from cash_registers.
        // A register's "shift" is created_at -> closed_at (or NOW() if still open).
        // We clip each shift to the [start, end] window so partial overlaps are
        // counted correctly when the window itself is short.
        $hours_raw = \DB::table('cash_registers')
            ->where('business_id', $business_id)
            ->whereNotNull('user_id')
            ->where(function ($q) use ($start, $end) {
                $q->where('created_at', '<=', $end)
                  ->where(function ($q2) use ($start) {
                      $q2->where('closed_at', '>=', $start)
                         ->orWhereNull('closed_at');
                  });
            })
            ->selectRaw("user_id,
                SUM(
                    TIMESTAMPDIFF(
                        SECOND,
                        GREATEST(created_at, ?),
                        LEAST(COALESCE(closed_at, NOW()), ?)
                    )
                ) / 3600.0 as hours")
            ->addBinding($start, 'select')
            ->addBinding($end, 'select')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');
        // Sales side — revenue + tx count + line count per employee (created_by)
        $sales = \DB::table('transactions as t')
            ->leftJoin('transaction_sell_lines as tsl', 'tsl.transaction_id', '=', 't.id')
            ->leftJoin('users as u', 't.created_by', '=', 'u.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('t.import_source')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->selectRaw("t.created_by,
                CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as employee,
                COUNT(DISTINCT t.id) as tx_count,
                COALESCE(SUM(tsl.quantity), 0) as items_rung,
                COALESCE(SUM(t.final_total) / GREATEST(COUNT(DISTINCT t.id), 1), 0) as avg_tx,
                COALESCE((SELECT SUM(t2.final_total) FROM transactions t2
                    WHERE t2.business_id = t.business_id
                      AND t2.type = 'sell' AND t2.status = 'final'
                      AND t2.import_source IS NULL
                      AND t2.created_by = t.created_by
                      AND t2.transaction_date BETWEEN ? AND ?), 0) as revenue")
            ->addBinding($start, 'select')
            ->addBinding($end, 'select')
            ->groupBy('t.created_by', 'u.first_name', 'u.last_name')
            ->get()
            ->keyBy('created_by');

        // Items priced in the window per user
        $priced = \DB::table('products')
            ->where('business_id', $business_id)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('created_by, COUNT(*) as priced_count')
            ->groupBy('created_by')
            ->get()
            ->keyBy('created_by');

        // Revenue from items priced by the user, sold in this window
        $priced_rev = \DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('products as p', 'tsl.product_id', '=', 'p.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('t.import_source')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->selectRaw('p.created_by, COALESCE(SUM(tsl.quantity * tsl.unit_price_inc_tax), 0) as priced_revenue')
            ->groupBy('p.created_by')
            ->get()
            ->keyBy('created_by');

        // Merge keys from both sides (someone may have priced items but not sold any, and vice versa)
        $user_ids = collect($sales->keys())->merge($priced->keys())->merge($priced_rev->keys())->unique()->values();

        // Lookup user names, filtered to ACTIVE employees only — Sarah asked
        // to stop showing terminated / inactive accounts on the leaderboard
        // (they clutter the rankings and are sometimes historical data from
        // people who aren't with the company anymore). SoftDeletes on the
        // model handles deleted_at automatically.
        $users = \App\User::whereIn('id', $user_ids)
            ->where('status', 'active')
            ->get()
            ->keyBy('id');

        // Drop any user_ids that aren't active. Their sales/pricing still
        // happened and still count toward business-wide totals elsewhere —
        // but they don't deserve a row on the per-employee leaderboard.
        $user_ids = $user_ids->filter(fn ($uid) => $users->has($uid))->values();

        $rows = $user_ids->map(function ($uid) use ($sales, $priced, $priced_rev, $users, $hours_raw) {
            $u = $users->get($uid);
            $s = $sales->get($uid);
            $name = $s->employee ?? trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
            $hours = (float) (optional($hours_raw->get($uid))->hours ?? 0);
            $revenue = (float) ($s->revenue ?? 0);
            $items_rung = (int) ($s->items_rung ?? 0);
            $tx_count = (int) ($s->tx_count ?? 0);
            $priced_count = (int) optional($priced->get($uid))->priced_count ?? 0;

            // Use a minimum of 0.25h (15 min) when normalizing so very short
            // shifts don't produce absurd per-hour numbers. Users with no
            // register activity return null per-hour metrics (UI renders "—").
            $hr_eff = $hours >= 0.25 ? $hours : null;

            return (object) [
                'user_id' => $uid,
                'employee' => trim($name) ?: '(unknown)',
                'tx_count' => $tx_count,
                'items_rung' => $items_rung,
                'revenue' => $revenue,
                'avg_tx' => (float) ($s->avg_tx ?? 0),
                'priced_count' => $priced_count,
                'priced_revenue' => (float) optional($priced_rev->get($uid))->priced_revenue ?? 0,
                'hours_worked' => $hours,
                'revenue_per_hour' => $hr_eff ? $revenue / $hr_eff : null,
                'items_per_hour'   => $hr_eff ? $items_rung / $hr_eff : null,
                'tx_per_hour'      => $hr_eff ? $tx_count / $hr_eff : null,
                'priced_per_hour'  => $hr_eff ? $priced_count / $hr_eff : null,
            ];
        })
        // Primary sort: revenue per hour (null goes last). Secondary: raw revenue.
        ->sortBy(function ($r) { return $r->revenue_per_hour === null ? -1 : $r->revenue_per_hour; }, SORT_REGULAR, true)
        ->values();

        if ($limit) {
            $rows = $rows->take($limit);
        }

        return $rows;
    }

    /**
     * Restrict accountant reports to admin users only.
     */
    protected function ensureAccountantReportAdminAccess()
    {
        $this->ensureAdminOnlyReportAccess();
    }

    /**
     * Restrict any report that surfaces aggregated sales / revenue totals to
     * admins only. Sarah 2026-04-28: "everyone needs access to all reports
     * EXCEPT for aggregated sales that is admin only." Used by Profit/Loss,
     * Tax Report/Details, Sales Rep, Sell Payment, Purchase & Sale, Category
     * Sales, Customer Groups, Whatnot, Clover EOD, Register, Employee
     * Leaderboard, Product Sell, Sales-by-Item, and the accountant-only
     * cost/margin reports.
     */
    protected function ensureAdminOnlyReportAccess()
    {
        if (!$this->businessUtil->is_admin(auth()->user())) {
            abort(403, 'This report is admin-only.');
        }
    }
}
