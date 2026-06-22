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
use App\LoginActivity;
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
     * Date the sales-goal bonus starts being real pay (Sarah 2026-06-02 — still
     * solidifying targets until then). Before this date the leaderboard shows
     * the bonus as a projection only and excludes it from total commission.
     */
    const SALES_BONUS_LIVE_DATE = '2026-06-15 00:00:00';

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
        // Open to all staff — inventory valuation, not aggregated sales.
        $business_id = $request->session()->get('user.business_id');

        //Return the details in ajax call
        if ($request->ajax()) {
            $start_date = $request->get('start_date');
            $end_date = $request->get('end_date');
            $location_id = $request->get('location_id');

            $day_before_start_date = \Carbon::createFromFormat('Y-m-d', $start_date)->subDay()->format('Y-m-d');

            $opening_stock_by_sp = $this->transactionUtil->getOpeningClosingStock($business_id, $day_before_start_date, $location_id, true, true);

            $closing_stock_by_sp = $this->transactionUtil->getOpeningClosingStock($business_id, $end_date, $location_id, false, true);

            return [
                'opening_stock_by_sp' => $opening_stock_by_sp,
                'closing_stock_by_sp' => $closing_stock_by_sp
            ];
        }

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        return view('report.stock_by_selling_price', compact('business_locations'));
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
                ->editColumn('first_purchase_date', function ($row) {
                    return !empty($row->first_purchase_date)
                        ? \Carbon::parse($row->first_purchase_date)->format(session('business.date_format'))
                        : '';
                })
                ->editColumn('last_purchase_date', function ($row) {
                    return !empty($row->last_purchase_date)
                        ? \Carbon::parse($row->last_purchase_date)->format(session('business.date_format'))
                        : '';
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
                ->orderColumn('first_purchase_date', 'first_purchase_date $1')
                ->orderColumn('last_purchase_date', 'last_purchase_date $1')
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

        // Summary rollup by category (keeps the same shape the old view used).
        $expenses = $this->transactionUtil->getExpenseReport($business_id, $filters);

        // Transaction-detail list — QB-like columns. Filtered by the same
        // category/location/date pickers; Sabina clicks a category in the
        // summary table to focus the detail rows.
        $detail = $this->getExpenseDetailRows($business_id, $filters);

        $categories = ExpenseCategory::where('business_id', $business_id)
                            ->pluck('name', 'id');

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        // If the request is asking for CSV export, return that instead of HTML.
        if ($request->input('export') === 'csv') {
            return $this->streamExpenseReportCsv($detail, $filters);
        }

        return view('report.expense_report')
                    ->with(compact('categories', 'business_locations', 'expenses', 'detail', 'filters'));
    }

    /**
     * Per-transaction detail rows for the expense report. QB-style columns:
     * Date, Transaction type (from QB type tag), Num (ref_no tail), Vendor
     * (parsed from additional_notes), Memo, Category, Location, Amount.
     */
    protected function getExpenseDetailRows($business_id, array $filters)
    {
        $q = DB::table('transactions as t')
            ->leftJoin('expense_categories as ec', 't.expense_category_id', '=', 'ec.id')
            ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->where('t.business_id', $business_id)
            ->whereIn('t.type', ['expense', 'expense_refund'])
            ->orderByDesc('t.transaction_date')
            ->orderByDesc('t.id')
            ->select(
                't.id', 't.type', 't.transaction_date', 't.ref_no', 't.final_total',
                't.additional_notes', 't.expense_category_id',
                'ec.name as category',
                'bl.name as location_name'
            );

        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations !== 'all') {
            $q->whereIn('t.location_id', $permitted_locations);
        }
        if (!empty($filters['location_id'])) {
            $q->where('t.location_id', $filters['location_id']);
        }
        if (!empty($filters['category'])) {
            $q->where('t.expense_category_id', $filters['category']);
        }
        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $q->whereBetween(DB::raw('date(t.transaction_date)'), [$filters['start_date'], $filters['end_date']]);
        }

        return $q->limit(5000)->get();
    }

    protected function streamExpenseReportCsv($rows, array $filters)
    {
        $filename = 'expense-report_' . ($filters['start_date'] ?? 'all') . '_to_' . ($filters['end_date'] ?? 'all') . '.csv';
        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];
        return response()->stream(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Date', 'Transaction type', 'Num', 'Vendor', 'Memo', 'Category', 'Location', 'Amount']);
            foreach ($rows as $r) {
                $signed = $r->type === 'expense_refund' ? (float) $r->final_total : -1 * (float) $r->final_total;
                $vendor = '';
                $memo = '';
                $tx_type = $r->type === 'expense_refund' ? 'Expense refund' : 'Expense';
                if (!empty($r->additional_notes)) {
                    // additional_notes is "Type · Vendor: X · Memo text"
                    $parts = array_map('trim', explode(' · ', $r->additional_notes));
                    foreach ($parts as $p) {
                        if (stripos($p, 'Vendor:') === 0) {
                            $vendor = trim(substr($p, strlen('Vendor:')));
                        } elseif ($p !== '' && stripos($p, 'Vendor:') !== 0) {
                            // First non-vendor part that looks like a QB type goes to tx_type;
                            // others become memo.
                            if ($memo === '' && $tx_type === ($r->type === 'expense_refund' ? 'Expense refund' : 'Expense')) {
                                $tx_type = $p;
                            } else {
                                $memo = $memo === '' ? $p : ($memo . ' · ' . $p);
                            }
                        }
                    }
                }
                fputcsv($out, [
                    $r->transaction_date,
                    $tx_type,
                    $r->ref_no,
                    $vendor,
                    $memo,
                    $r->category ?: '(uncategorized)',
                    $r->location_name,
                    number_format($signed, 2, '.', ''),
                ]);
            }
            fclose($out);
        }, 200, $headers);
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
                ->addColumn('safe_drop_amount', function ($row) {
                    // Tolerate the column not yet existing (migration may
                    // not have run on every environment) and old shifts
                    // that closed before this feature shipped.
                    $val = isset($row->safe_drop_amount) ? (float) $row->safe_drop_amount : 0;
                    return '<span data-orig-value="' . $val . '" >' . $this->transactionUtil->num_f($val, true) . '</span>';
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
                ->rawColumns(['action', 'user_name', 'opening_balance', 'closing_amount', 'expected_closing', 'reconciliation_difference', 'safe_drop_amount', 'total_card_payment', 'total_cheque_payment', 'total_cash_payment', 'total_bank_transfer_payment', 'total_other_payment', 'total_advance_payment', 'total_custom_pay_1', 'total_custom_pay_2', 'total_custom_pay_3', 'total_custom_pay_4', 'total_custom_pay_5', 'total_custom_pay_6', 'total_custom_pay_7', 'total'])
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
                    ->leftJoin('categories as cat', 'p.category_id', '=', 'cat.id')
                    ->leftJoin('categories as subcat', 'p.sub_category_id', '=', 'subcat.id')
                    ->leftJoin('buy_customer_offers as bfc_o', 'bfc_o.accepted_purchase_id', '=', 't.id')
                    ->leftJoin('users as added_by_u', 'added_by_u.id', '=', 't.created_by')
                    ->where('t.business_id', $business_id)
                    ->where('t.type', 'purchase')
                    ->whereNull('bfc_o.id')
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
                        DB::raw('((purchase_lines.quantity - purchase_lines.quantity_returned - purchase_lines.quantity_adjusted) * purchase_lines.purchase_price_inc_tax) as subtotal'),
                        DB::raw("TRIM(CONCAT(COALESCE(added_by_u.first_name, ''), ' ', COALESCE(added_by_u.last_name, ''))) as added_by"),
                        DB::raw('(SELECT GROUP_CONCAT(DISTINCT tp.method ORDER BY tp.method SEPARATOR ",") FROM transaction_payments tp WHERE tp.transaction_id = t.id) as payment_methods'),
                        'cat.name as category_name',
                        'subcat.name as sub_category_name'
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

            $created_by = $request->get('created_by', null);
            if (!empty($created_by)) {
                $query->where('t.created_by', $created_by);
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
                ->addColumn('added_by', function ($row) {
                    return $row->added_by ?: '—';
                })
                ->addColumn('category_label', function ($row) {
                    if (empty($row->category_name) && empty($row->sub_category_name)) {
                        return '—';
                    }
                    $cat = $row->category_name ?: '—';
                    $sub = $row->sub_category_name ? ' › ' . $row->sub_category_name : '';
                    return e($cat) . e($sub);
                })
                ->addColumn('payment_methods_label', function ($row) {
                    if (empty($row->payment_methods)) {
                        // AMS bills are always paid via ACH/bank — when no
                        // transaction_payments row exists, show the default
                        // Bank Account pill instead of a blank dash.
                        $supplier_blob = strtolower(($row->supplier ?? '') . ' ' . ($row->supplier_business_name ?? ''));
                        if (strpos($supplier_blob, 'ams') !== false || strpos($supplier_blob, 'all media supply') !== false) {
                            return $this->purchasePaymentPill('bank_transfer') . ' <span style="font-size:10px;color:#94a3b8;font-style:italic;">default</span>';
                        }
                        return '—';
                    }
                    $methods = array_filter(array_map('trim', explode(',', $row->payment_methods)));
                    $pills = [];
                    foreach ($methods as $m) {
                        $pills[] = $this->purchasePaymentPill($m);
                    }
                    return implode(' ', $pills);
                })
                ->rawColumns(['ref_no', 'unit_purchase_price', 'subtotal', 'purchase_qty', 'quantity_adjusted', 'supplier', 'payment_methods_label'])
                ->make(true);
        }

        $business_locations = BusinessLocation::forDropdown($business_id);
        $suppliers = Contact::suppliersDropdown($business_id);
        $brands = Brands::forDropdown($business_id);
        $users = User::forDropdown($business_id, false);

        $permitted_locations = auth()->user()->permitted_locations();
        $today = \Carbon::now()->format('Y-m-d');
        $mtd_start = \Carbon::now()->startOfMonth()->format('Y-m-d');
        $ytd_start = \Carbon::now()->startOfYear()->format('Y-m-d');
        $summary_mtd = $this->productPurchaseSummaryBuckets($business_id, $mtd_start, $today, $permitted_locations);
        $summary_ytd = $this->productPurchaseSummaryBuckets($business_id, $ytd_start, $today, $permitted_locations);

        $current_week_budget = $this->currentWeekPurchaseBudget($today);
        $current_week_actual = null;
        if ($current_week_budget) {
            $current_week_actual = $this->weeklyPurchaseActual(
                $business_id,
                $current_week_budget['start'],
                $current_week_budget['end'],
                $permitted_locations
            );
        }

        $total_mtd = $this->weeklyPurchaseActual($business_id, $mtd_start, $today, $permitted_locations);
        $total_ytd = $this->weeklyPurchaseActual($business_id, $ytd_start, $today, $permitted_locations);

        return view('report.product_purchase_report')
            ->with(compact(
                'business_locations',
                'suppliers',
                'brands',
                'users',
                'summary_mtd',
                'summary_ytd',
                'current_week_budget',
                'current_week_actual',
                'total_mtd',
                'total_ytd'
            ));
    }

    /**
     * Weekly purchase spending budget from the 13-week cash flow plan
     * (1st scenario). Past actuals on the spreadsheet are not budgets so
     * they're not represented here — only the forward-looking 13 weeks.
     */
    private function purchaseBudgetSchedule()
    {
        return [
            ['week_no' => 1,  'start' => '2026-05-18', 'end' => '2026-05-24', 'budget' => 10954],
            ['week_no' => 2,  'start' => '2026-05-25', 'end' => '2026-05-31', 'budget' => 10954],
            ['week_no' => 3,  'start' => '2026-06-01', 'end' => '2026-06-07', 'budget' => 11238],
            ['week_no' => 4,  'start' => '2026-06-08', 'end' => '2026-06-14', 'budget' => 11238],
            ['week_no' => 5,  'start' => '2026-06-15', 'end' => '2026-06-21', 'budget' => 11238],
            ['week_no' => 6,  'start' => '2026-06-22', 'end' => '2026-06-28', 'budget' => 11238],
            ['week_no' => 7,  'start' => '2026-06-29', 'end' => '2026-07-05', 'budget' => 10954],
            ['week_no' => 8,  'start' => '2026-07-06', 'end' => '2026-07-12', 'budget' => 10954],
            ['week_no' => 9,  'start' => '2026-07-13', 'end' => '2026-07-19', 'budget' => 10954],
            ['week_no' => 10, 'start' => '2026-07-20', 'end' => '2026-07-26', 'budget' => 10954],
            ['week_no' => 11, 'start' => '2026-07-27', 'end' => '2026-08-02', 'budget' => 15000],
            ['week_no' => 12, 'start' => '2026-08-03', 'end' => '2026-08-09', 'budget' => 15000],
            ['week_no' => 13, 'start' => '2026-08-10', 'end' => '2026-08-16', 'budget' => 15000],
        ];
    }

    private function currentWeekPurchaseBudget($today)
    {
        foreach ($this->purchaseBudgetSchedule() as $week) {
            if ($today >= $week['start'] && $today <= $week['end']) {
                return $week;
            }
        }
        return null;
    }

    /**
     * DataTable feed for the In-store buys section of the product purchase
     * report. Sources from buy_customer_offers joined to transactions so that
     * offers WITHOUT materialized purchase_lines (the Slack backfill and
     * no-title BFC lines) still appear — the existing report only joins
     * purchase_lines so those orphan-purchase transactions get hidden.
     */
    public function getInStoreBuysData(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        if (!$request->ajax()) {
            abort(404);
        }

        $query = DB::table('buy_customer_offers as o')
            ->join('transactions as t', 't.id', '=', 'o.accepted_purchase_id')
            ->leftJoin('contacts as c', 't.contact_id', '=', 'c.id')
            ->leftJoin('users as u', 'o.created_by', '=', 'u.id')
            ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'purchase')
            ->select(
                't.id as transaction_id',
                't.transaction_date',
                't.final_total',
                't.location_id',
                'bl.name as location_name',
                'o.buy_record_number',
                'o.payout_type',
                'o.payment_method',
                'o.seller_name',
                'c.name as contact_name',
                DB::raw("CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as cashier"),
                DB::raw('(SELECT COUNT(*) FROM buy_customer_offer_lines bol WHERE bol.offer_id = o.id) as line_count')
            );

        $permitted_locations = auth()->user()->permitted_locations();
        if ($permitted_locations != 'all') {
            $query->whereIn('t.location_id', $permitted_locations);
        }

        $start_date = $request->get('start_date');
        $end_date = $request->get('end_date');
        if (!empty($start_date) && !empty($end_date)) {
            $query->whereBetween(DB::raw('date(t.transaction_date)'), [$start_date, $end_date]);
        }

        $location_id = $request->get('location_id');
        if (!empty($location_id)) {
            $query->where('t.location_id', $location_id);
        }

        return Datatables::of($query)
            ->editColumn('transaction_date', '{{@format_date($transaction_date)}}')
            ->editColumn('buy_record_number', function ($row) {
                $label = $row->buy_record_number ?: ('BFC-' . $row->transaction_id);
                return '<span style="display:inline-block;padding:2px 8px;background:#FFF2B3;color:#5A4410;border:1px solid #F0DC7A;border-radius:999px;font-size:10px;font-weight:800;letter-spacing:.08em;margin-right:6px;">IN-STORE BUY</span> <strong>' . e($label) . '</strong>';
            })
            ->editColumn('contact_name', function ($row) {
                return e($row->seller_name ?: ($row->contact_name ?: '—'));
            })
            ->editColumn('payout_type', function ($row) {
                return $row->payout_type === 'store_credit' ? 'Store credit' : 'Cash';
            })
            ->addColumn('payment_method_label', function ($row) {
                // Allowed values from BuyFromCustomerController validation:
                // cash_in_store | store_credit | zelle_venmo
                if (empty($row->payment_method)) {
                    return '—';
                }
                return $this->purchasePaymentPill($row->payment_method);
            })
            ->editColumn('line_count', function ($row) {
                return (int) $row->line_count;
            })
            ->editColumn('final_total', function ($row) {
                return '<span class="display_currency" data-currency_symbol="true">' . $row->final_total . '</span>';
            })
            ->editColumn('location_name', function ($row) {
                return e($row->location_name ?: '—');
            })
            ->rawColumns(['buy_record_number', 'final_total', 'payment_method_label'])
            ->make(true);
    }

    /**
     * Color-coded payment-method pill used by both the main product purchase
     * report (sourced from transaction_payments.method — cash/card/cheque/
     * bank_transfer/other) and the In-store buys table (sourced from
     * buy_customer_offers.payment_method — cash_in_store/store_credit/
     * zelle_venmo). Keeps the visual language consistent across the report.
     */
    private function purchasePaymentPill($method)
    {
        $method = strtolower((string) $method);
        $map = [
            'cash'           => ['Cash',          '#DCFCE7', '#166534', '#BBF7D0'],
            'cash_in_store'  => ['Cash',          '#DCFCE7', '#166534', '#BBF7D0'],
            'card'           => ['Card',          '#E0E7FF', '#3730A3', '#C7D2FE'],
            'cheque'         => ['Check',         '#FEF3C7', '#92400E', '#FDE68A'],
            'bank_transfer'  => ['Bank Account',  '#DBEAFE', '#1E3A8A', '#BFDBFE'],
            'store_credit'   => ['Store credit',  '#FFF2B3', '#5A4410', '#F0DC7A'],
            'zelle_venmo'    => ['Zelle / Venmo', '#EDE9FE', '#5B21B6', '#DDD6FE'],
            'zelle'          => ['Zelle',         '#EDE9FE', '#5B21B6', '#DDD6FE'],
            'venmo'          => ['Venmo',         '#DBEAFE', '#1E40AF', '#BFDBFE'],
            'other'          => ['Other',         '#F1F5F9', '#334155', '#CBD5E1'],
        ];
        if (isset($map[$method])) {
            [$label, $bg, $fg, $border] = $map[$method];
        } else {
            $label = ucwords(str_replace('_', ' ', $method));
            $bg = '#F1F5F9'; $fg = '#334155'; $border = '#CBD5E1';
        }
        return '<span style="display:inline-block;padding:2px 9px;background:' . $bg . ';color:' . $fg . ';border:1px solid ' . $border . ';border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.04em;">' . e($label) . '</span>';
    }

    private function weeklyPurchaseActual($business_id, $start, $end, $permitted_locations)
    {
        $q = DB::table('transactions as t')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'purchase')
            ->whereBetween(DB::raw('date(t.transaction_date)'), [$start, $end]);
        if ($permitted_locations !== 'all') {
            $q->whereIn('t.location_id', $permitted_locations);
        }
        return (float) $q->sum('t.final_total');
    }

    /**
     * Buckets purchase totals into AMS / Alliance / Audio Technica / in-store
     * buys for the product-purchase-report top summary. Supplier buckets match
     * by contact name LIKE (same convention as InventoryCheckService). In-store
     * buys are purchases linked via buy_customer_offers.accepted_purchase_id —
     * those are buy-from-customer offers (/buy-from-customer) that staff have
     * accepted.
     */
    private function productPurchaseSummaryBuckets($business_id, $start, $end, $permitted_locations)
    {
        $baseQuery = function () use ($business_id, $start, $end, $permitted_locations) {
            $q = DB::table('transactions as t')
                ->where('t.business_id', $business_id)
                ->where('t.type', 'purchase')
                ->whereBetween(DB::raw('date(t.transaction_date)'), [$start, $end]);
            if ($permitted_locations !== 'all') {
                $q->whereIn('t.location_id', $permitted_locations);
            }
            return $q;
        };

        $bySupplier = function (array $patterns) use ($baseQuery) {
            return (float) $baseQuery()
                ->join('contacts as c', 't.contact_id', '=', 'c.id')
                ->where(function ($q2) use ($patterns) {
                    foreach ($patterns as $p) {
                        $q2->orWhere('c.name', 'like', '%' . $p . '%')
                           ->orWhere('c.supplier_business_name', 'like', '%' . $p . '%');
                    }
                })
                ->sum('t.final_total');
        };

        $bfc = (float) $baseQuery()
            ->join('buy_customer_offers as o', 'o.accepted_purchase_id', '=', 't.id')
            ->sum('t.final_total');

        return [
            ['key' => 'ams',      'label' => 'AMS',            'total' => $bySupplier(['AMS', 'All Media Supply'])],
            ['key' => 'alliance', 'label' => 'Alliance',       'total' => $bySupplier(['Alliance'])],
            ['key' => 'at',       'label' => 'Audio Technica', 'total' => $bySupplier(['Audio Technica', 'Audio-Technica'])],
            ['key' => 'bfc',      'label' => 'In-store buys',  'total' => $bfc],
        ];
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
                ->leftJoin('users as sale_user', 'sale_user.id', '=', 'sale.created_by')
                ->leftJoin('users as adj_user', 'adj_user.id', '=', 'stock_adjustment.created_by')
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
                    'p.artist as artist',
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
                    'PL.lot_number',
                    DB::raw("TRIM(CONCAT_WS(' ', COALESCE(sale_user.first_name, adj_user.first_name), COALESCE(sale_user.last_name, adj_user.last_name))) as created_by")
                );

            // Query for manual items (items without product_id)
            $manual_items_query = TransactionSellLine::join('transactions as sale', 'transaction_sell_lines.transaction_id', '=', 'sale.id')
                ->join('business_locations as bl', 'sale.location_id', '=', 'bl.id')
                ->leftJoin('categories as cat', 'transaction_sell_lines.category_id', '=', 'cat.id')
                ->leftJoin('categories as sub_cat', 'transaction_sell_lines.sub_category_id', '=', 'sub_cat.id')
                ->leftJoin('contacts as customers', 'sale.contact_id', '=', 'customers.id')
                ->leftJoin('users as sale_user', 'sale_user.id', '=', 'sale.created_by')
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
                    'transaction_sell_lines.product_artist as artist',
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
                    DB::raw('NULL as lot_number'),
                    DB::raw("TRIM(CONCAT_WS(' ', sale_user.first_name, sale_user.last_name)) as created_by")
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
                ->orderColumn('p.artist', DB::raw('`unioned_query`.`artist` $1'))
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
                ->orderColumn('created_by', DB::raw('`unioned_query`.`created_by` $1'))
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
            ->leftJoin('users as sale_user', 'sale_user.id', '=', 'sale.created_by')
            ->leftJoin('users as adj_user', 'adj_user.id', '=', 'stock_adjustment.created_by')
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
                'p.artist as artist',
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
                'PL.lot_number',
                DB::raw("TRIM(CONCAT_WS(' ', COALESCE(sale_user.first_name, adj_user.first_name), COALESCE(sale_user.last_name, adj_user.last_name))) as created_by")
            );

        $manual_items_query = TransactionSellLine::join('transactions as sale', 'transaction_sell_lines.transaction_id', '=', 'sale.id')
            ->join('business_locations as bl', 'sale.location_id', '=', 'bl.id')
            ->leftJoin('categories as cat', 'transaction_sell_lines.category_id', '=', 'cat.id')
            ->leftJoin('categories as sub_cat', 'transaction_sell_lines.sub_category_id', '=', 'sub_cat.id')
            ->leftJoin('contacts as customers', 'sale.contact_id', '=', 'customers.id')
            ->leftJoin('users as sale_user', 'sale_user.id', '=', 'sale.created_by')
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
                'transaction_sell_lines.product_artist as artist',
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
                DB::raw('NULL as lot_number'),
                DB::raw("TRIM(CONCAT_WS(' ', sale_user.first_name, sale_user.last_name)) as created_by")
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
                'Product', 'Artist', 'Format', 'SKU', 'Category', 'Sub-category', 'Description',
                'Purchase Date', 'Purchase Ref', 'Lot Number', 'Supplier', 'Purchase Price',
                'Sell Date', 'Sale Invoice', 'Customer', 'Created By', 'Location',
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
                    $r->artist,
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
                    $r->created_by,
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
    
    /**
     * Smallest cumulative coverage of staff logins that counts as "the store".
     * The store IPs are learned from history: rank the IPs non-admin staff log
     * in from, and take the top ones until they cover this share of all staff
     * logins. Anything outside that set is treated as off-site.
     */
    const OUTSIDE_LOGIN_COVERAGE = 0.90;

    /** Don't flag anything until we've seen at least this many staff logins. */
    const OUTSIDE_LOGIN_MIN_SAMPLE = 20;

    /**
     * Build the set of "store" IPs for a business from login history.
     * Returns [ip => login_count] for the trusted IPs, plus totals, so the
     * report can both filter and explain itself.
     */
    private function storeIpProfile($business_id)
    {
        // Migration not run yet — behave as "learning" rather than 500.
        if (!\Schema::hasTable('login_activities')) {
            return ['trusted' => [], 'total' => 0, 'learning' => true];
        }

        $counts = LoginActivity::where('business_id', $business_id)
            ->where('successful', 1)
            ->where('is_admin', 0)
            ->whereNotNull('ip_address')
            ->select('ip_address', DB::raw('COUNT(*) as cnt'))
            ->groupBy('ip_address')
            ->orderBy('cnt', 'desc')
            ->pluck('cnt', 'ip_address')
            ->toArray();

        $total = array_sum($counts);
        $trusted = [];

        // Not enough data yet — "learning" mode, trust everything so we don't
        // drown the admin in false positives on a fresh table.
        if ($total < self::OUTSIDE_LOGIN_MIN_SAMPLE) {
            return [
                'trusted'  => $counts,
                'total'    => $total,
                'learning' => true,
            ];
        }

        $cumulative = 0;
        foreach ($counts as $ip => $cnt) {
            $trusted[$ip] = $cnt;
            $cumulative += $cnt;
            if ($cumulative / $total >= self::OUTSIDE_LOGIN_COVERAGE) {
                break;
            }
        }

        return [
            'trusted'  => $trusted,
            'total'    => $total,
            'learning' => false,
        ];
    }

    /**
     * Outside-Store Logins report — flags login attempts from IPs the stores
     * don't normally use. Admin only. The "store" IPs are derived from staff
     * login history (see storeIpProfile), so no manual IP config is needed.
     */
    public function outsideLoginsReport(Request $request)
    {
        $this->ensureAdminOnlyReportAccess();

        $business_id = $request->session()->get('user.business_id');
        $profile = $this->storeIpProfile($business_id);
        $trusted_ips = array_keys($profile['trusted']);

        if ($request->ajax()) {
            $query = LoginActivity::leftjoin('users as u', 'u.id', '=', 'login_activities.user_id')
                ->where('login_activities.business_id', $business_id)
                ->select(
                    'login_activities.*',
                    DB::raw("CONCAT(COALESCE(u.surname, ''), ' ', COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as employee_name")
                );

            // While learning we have no trusted set yet — show nothing rather
            // than flag every row. Once enough history exists, exclude the
            // store IPs so only the off-site logins remain.
            if ($profile['learning']) {
                $query->whereRaw('1 = 0');
            } elseif (!empty($trusted_ips)) {
                $query->whereNotIn('login_activities.ip_address', $trusted_ips);
            }

            if (!empty($request->start_date) && !empty($request->end_date)) {
                $query->whereDate('login_activities.created_at', '>=', $request->start_date)
                    ->whereDate('login_activities.created_at', '<=', $request->end_date);
            }

            if (!empty($request->user_id)) {
                $query->where('login_activities.user_id', $request->user_id);
            }

            if ($request->result === 'success') {
                $query->where('login_activities.successful', 1);
            } elseif ($request->result === 'failed') {
                $query->where('login_activities.successful', 0);
            }

            return Datatables::of($query)
                ->editColumn('created_at', '{{@format_datetime($created_at)}}')
                ->editColumn('employee_name', function ($row) {
                    $name = trim($row->employee_name);
                    return $name !== '' ? e($name)
                        : '<span class="text-muted">No matching user</span>';
                })
                ->editColumn('username', function ($row) {
                    return e($row->username);
                })
                ->editColumn('ip_address', function ($row) {
                    return e($row->ip_address);
                })
                ->addColumn('device', function ($row) {
                    return '<span title="' . e($row->user_agent) . '">'
                        . e($this->shortDevice($row->user_agent)) . '</span>';
                })
                ->addColumn('result', function ($row) {
                    return $row->successful
                        ? '<span class="label label-success">' . __('lang_v1.success') . '</span>'
                        : '<span class="label label-danger">Failed</span>';
                })
                ->filterColumn('employee_name', function ($q, $keyword) {
                    $q->whereRaw("CONCAT(COALESCE(u.surname, ''), ' ', COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) like ?", ["%{$keyword}%"]);
                })
                ->rawColumns(['employee_name', 'device', 'result'])
                ->make(true);
        }

        $users = User::allUsersDropdown($business_id, false);

        return view('report.outside_logins')->with([
            'users'       => $users,
            'trusted_ips' => $profile['trusted'],
            'total_staff_logins' => $profile['total'],
            'learning'    => $profile['learning'],
            'min_sample'  => self::OUTSIDE_LOGIN_MIN_SAMPLE,
        ]);
    }

    /** Compact, human-readable device label from a raw user-agent string. */
    private function shortDevice($ua)
    {
        if (empty($ua)) {
            return '—';
        }

        $os = 'Unknown OS';
        foreach ([
            'Windows' => 'Windows', 'iPhone' => 'iPhone', 'iPad' => 'iPad',
            'Android' => 'Android', 'Mac OS X' => 'Mac', 'Macintosh' => 'Mac',
            'Linux' => 'Linux',
        ] as $needle => $label) {
            if (stripos($ua, $needle) !== false) { $os = $label; break; }
        }

        $browser = 'Unknown';
        foreach ([
            'Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome',
            'Firefox' => 'Firefox', 'Safari' => 'Safari',
        ] as $needle => $label) {
            if (stripos($ua, $needle) !== false) { $browser = $label; break; }
        }

        return $browser . ' / ' . $os;
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

        // When an imported ABC file is active, classes come from
        // /admin/abc-import (sales-based). Otherwise fall back to the live
        // inventory-value Pareto computation.
        $abcSvc = new \App\Services\AbcImportService();
        $imported = $abcSvc->loadGlobalMap();
        $importedAbcXyz = $abcSvc->loadAbcXyzMap();
        $importedMeta = $abcSvc->load();

        // Per-store class maps from the import (location_id => [pid => class]).
        // When a store filter is active and the import has that store, classes
        // come from its per-location map so the breakdown is store-specific.
        $locationMaps = [];
        if (!empty($importedMeta['location_map']) && is_array($importedMeta['location_map'])) {
            foreach ($importedMeta['location_map'] as $loc => $map) {
                $lm = [];
                foreach ((array) $map as $pid => $cls) {
                    $lm[(int) $pid] = (string) $cls;
                }
                $locationMaps[(int) $loc] = $lm;
            }
        }

        if ($request->ajax()) {
            $location_id = $request->input('location_id');
            $category = $request->input('category');
            $genre = $request->input('format');
            $class_filter = strtoupper(trim((string) $request->input('class', '')));

            $inventory_query = DB::table('product_stock_cache as psc')
                ->leftJoin('categories as sc', 'psc.sub_category_id', '=', 'sc.id')
                ->where('psc.business_id', $business_id)
                ->where('psc.enable_stock', 1);

            if (!empty($location_id)) {
                $inventory_query->where('psc.location_id', $location_id);
            }
            if ($category !== null && $category !== '') {
                $inventory_query->where('psc.category_name', $category);
            }
            if ($genre !== null && $genre !== '') {
                $inventory_query->where('sc.name', $genre);
            }

            $inventory_rows = $inventory_query
                ->select(
                    'psc.product_id',
                    DB::raw('MAX(psc.product) as product'),
                    DB::raw('MAX(psc.sku) as sku'),
                    DB::raw('MAX(psc.category_name) as category'),
                    DB::raw('MAX(sc.name) as genre'),
                    DB::raw('SUM(psc.stock) as qty_on_hand'),
                    DB::raw('SUM(psc.stock_price) as inventory_value'),
                    DB::raw('MAX(psc.unit_price) as current_price'),
                    // Lifetime units sold straight from the stock cache — avoids
                    // a full scan of transaction_sell_lines on every load.
                    DB::raw('SUM(psc.total_sold) as qty_sold')
                )
                ->groupBy('psc.product_id')
                // Only items actually on hand — you can't mark down what you
                // don't have, and it keeps the classification loop fast.
                ->havingRaw('SUM(psc.stock) > 0')
                ->get();

            // Use the store's own class map when a single store is selected and
            // the import covers it; otherwise the global (best-class) map.
            $classMap = $imported;
            if (!empty($location_id) && isset($locationMaps[(int) $location_id])) {
                $classMap = $locationMaps[(int) $location_id];
            }

            // For the live fallback (no import), rank by inventory value so the
            // bottom slice can be flagged C. With an import, class comes straight
            // from the map and order doesn't matter here.
            $rows = $inventory_rows->sortByDesc('inventory_value')->values();
            $total_value = (float) $rows->sum('inventory_value');
            $running = 0;

            // This report IS the markdown list: only slow movers (class C) that
            // are still on hand. Each gets 20% off the current sticker.
            $markdown = [];
            foreach ($rows as $row) {
                $value = (float) $row->inventory_value;
                $running += $value;
                $cumulative_pct = $total_value > 0 ? ($running / $total_value) * 100 : 0;

                if (!empty($imported)) {
                    $class = $classMap[(int) $row->product_id] ?? '';
                } else {
                    $class = $cumulative_pct <= 95 ? 'AB' : 'C';
                }

                if ($class === '') {
                    continue;
                }

                // Class filter (defaults to C — the markdown list). Pick A/B/All
                // to inspect other tiers; only C rows actually get a markdown.
                if ($class_filter !== '' && $class !== $class_filter) {
                    continue;
                }

                $current_price = (float) $row->current_price;
                if ($current_price <= 0) {
                    continue;
                }

                // Per-unit cost: stock_price is SUM(qty * purchase_price_inc_tax),
                // so inventory_value / qty_on_hand is the average cost we paid.
                $qty_on_hand = (float) $row->qty_on_hand;
                $unit_cost = $qty_on_hand > 0 ? ((float) $row->inventory_value) / $qty_on_hand : 0.0;

                $markdown_price = null;
                if ($class === 'C') {
                    $markdown_price = round($current_price * 0.80, 2);
                    // Never mark below what we paid. If 20%-off lands under cost,
                    // floor at cost — but don't raise above the current sticker
                    // (items already at/below cost just keep their price). (Sarah 2026-06-18)
                    if ($unit_cost > 0 && $markdown_price < $unit_cost) {
                        $markdown_price = min($current_price, round($unit_cost, 2));
                    }
                }

                $markdown[] = [
                    'abc_class' => $class,
                    'abc_xyz' => $importedAbcXyz[(int) $row->product_id] ?? '',
                    'category' => $row->category ?: '— Other —',
                    'genre' => $row->genre ?: '—',
                    'product' => $row->product,
                    'sku' => $row->sku,
                    'qty_on_hand' => $qty_on_hand,
                    'current_price' => $current_price,
                    'markdown_price' => $markdown_price,
                ];
            }

            // Group by category then genre (A→Z), most-overstocked first within.
            usort($markdown, function ($a, $b) {
                return [$a['category'], $a['genre'], -$a['qty_on_hand']]
                    <=> [$b['category'], $b['genre'], -$b['qty_on_hand']];
            });

            return Datatables::of(collect($markdown))->make(true);
        }

        $business_locations = BusinessLocation::forDropdown($business_id, true);

        $categories = DB::table('product_stock_cache as psc')
            ->where('psc.business_id', $business_id)
            ->where('psc.enable_stock', 1)
            ->whereNotNull('psc.category_name')
            ->whereRaw("TRIM(psc.category_name) <> ''")
            ->select('psc.category_name as name')
            ->distinct()
            ->orderBy('name')
            ->pluck('name', 'name')
            ->toArray();

        $genres = DB::table('product_stock_cache as psc')
            ->join('categories as sc', 'psc.sub_category_id', '=', 'sc.id')
            ->where('psc.business_id', $business_id)
            ->where('psc.enable_stock', 1)
            ->whereNotNull('sc.name')
            ->whereRaw("TRIM(sc.name) <> ''")
            ->select('sc.name as name')
            ->distinct()
            ->orderBy('name')
            ->pluck('name', 'name')
            ->toArray();

        return view('report.abc_inventory_classification', [
            'imported_meta' => $importedMeta,
            'business_locations' => $business_locations,
            'categories' => $categories,
            'genres' => $genres,
        ]);
    }

    /**
     * Full ABC report — every row from the uploaded analyzer file, including
     * the unmatched / Manual items that never reach the markdown report or the
     * reorder tools (those only see products tied to an ERP record). Lets staff
     * read reorder priorities straight off the analyzer's own ABC-XYZ codes.
     */
    public function abcFullReport(Request $request)
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $abcSvc = new \App\Services\AbcImportService();

        if ($request->ajax()) {
            $class = strtoupper(trim((string) $request->input('class', '')));
            $xyz = strtoupper(trim((string) $request->input('xyz', '')));
            $combo = strtoupper(preg_replace('/\s+/', '', (string) $request->input('abc_xyz', '')));
            $scope = (string) $request->input('scope', '');

            $rows = collect($abcSvc->loadReportRows())->filter(function ($r) use ($class, $xyz, $combo, $scope) {
                if ($class !== '' && strtoupper((string) ($r['class'] ?? '')) !== $class) {
                    return false;
                }
                if ($xyz !== '' && strtoupper((string) ($r['xyz'] ?? '')) !== $xyz) {
                    return false;
                }
                if ($combo !== '' && strtoupper((string) ($r['abc_xyz'] ?? '')) !== $combo) {
                    return false;
                }
                if ($scope === 'manual' && empty($r['manual'])) {
                    return false;
                }
                if ($scope === 'matched' && empty($r['in_erp'])) {
                    return false;
                }
                if ($scope === 'unmatched' && !empty($r['in_erp'])) {
                    return false;
                }
                // Manual reorder picks: no-SKU items that are steady sellers.
                if ($scope === 'reorder_manual') {
                    if (empty($r['manual'])) {
                        return false;
                    }
                    if (!in_array(strtoupper((string) ($r['class'] ?? '')), ['A', 'B'], true)) {
                        return false;
                    }
                    if (strtoupper((string) ($r['xyz'] ?? '')) !== 'X') {
                        return false;
                    }
                }
                return true;
            })->values();

            return Datatables::of($rows)->make(true);
        }

        return view('report.abc_full_report', [
            'imported_meta' => $abcSvc->load(),
            'has_rows' => count($abcSvc->loadReportRows()) > 0,
        ]);
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

        // Labels printed from the Print Labels tab. LabelsController@preview
        // writes one activity_log row per print run with qty in properties.
        $labels_by_user = [];
        $label_rows = DB::table('activity_log')
            ->where('description', 'labels_printed')
            ->where('business_id', $business_id)
            ->whereBetween('created_at', [$start_date . ' 00:00:00', $end_date . ' 23:59:59'])
            ->whereNotNull('causer_id')
            ->select('causer_id', 'properties')
            ->get();
        foreach ($label_rows as $row) {
            $props = json_decode($row->properties, true) ?: [];
            $qty = (int) ($props['qty'] ?? 0);
            $labels_by_user[$row->causer_id] = ($labels_by_user[$row->causer_id] ?? 0) + $qty;
        }
        $labels_printed = collect($labels_by_user);

        $hours_raw = $this->getHoursWorkedByUser($users, $start_date, $end_date, $business_id);

        // Packages picked / shipped: each time a user changes a transaction's
        // shipping_status to "packed" or "shipped" via /sells edit-shipping,
        // an activity_log row is written with the old + new values. We count
        // distinct transactions per user that transitioned INTO each state.
        // (Nick is the one Sarah is tracking, but column applies to everyone.)
        $picked_by_user = [];
        $shipped_by_user = [];
        $shipping_edits = DB::table('activity_log')
            ->where('description', 'shipping_edited')
            ->where('business_id', $business_id)
            ->where('subject_type', 'App\\Transaction')
            ->whereBetween('created_at', [$start_date . ' 00:00:00', $end_date . ' 23:59:59'])
            ->whereNotNull('causer_id')
            ->select('causer_id', 'subject_id', 'properties')
            ->get();
        foreach ($shipping_edits as $edit) {
            $props = json_decode($edit->properties, true) ?: [];
            $new = $props['attributes']['shipping_status'] ?? null;
            $old = $props['old']['shipping_status'] ?? null;
            if (!$new || $new === $old) {
                continue;
            }
            if ($new === 'packed') {
                $picked_by_user[$edit->causer_id][$edit->subject_id] = true;
            } elseif ($new === 'shipped') {
                $shipped_by_user[$edit->causer_id][$edit->subject_id] = true;
            }
        }

        // Shipping happens on nivessa.com (not the ERP), so the activity_log
        // rows above are always empty. Pull totals from the website backend
        // and credit them to Nick's row — he owns shipping. Failures (network
        // / missing API key) just leave the columns at 0.
        $nivessa_picked = 0;
        $nivessa_shipped = 0;
        try {
            $base = rtrim((string) config('nivessa.website_api_url', 'https://nivessa.com'), '/');
            $key = trim((string) config('nivessa.website_api_key', ''));
            if ($key !== '') {
                $url = $base . '/api/v1/admin/fulfillment-counts'
                    . '?start_date=' . urlencode($start_date)
                    . '&end_date=' . urlencode($end_date);
                $det = $this->httpGetJsonDetailed($url, $key, 10);
                $body = $det['decoded'] ?? null;
                if (!empty($body) && !empty($body['success'])) {
                    $nivessa_picked = (int) ($body['picked_items'] ?? 0)
                        + (int) ($body['packed_items'] ?? 0);
                    $nivessa_shipped = (int) ($body['shipped_orders'] ?? 0);
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('fulfillment-counts fetch failed: ' . $e->getMessage());
        }

        $rows = $users->map(function ($u) use ($mass_add, $purchase_add, $labels_printed, $hours_raw, $picked_by_user, $shipped_by_user, $nivessa_picked, $nivessa_shipped) {
            $m = (int) ($mass_add[$u->id] ?? 0);
            $p = (int) ($purchase_add[$u->id] ?? 0);
            $l = (int) ($labels_printed[$u->id] ?? 0);
            $h = (float) ($hours_raw[$u->id] ?? 0);
            $picked = isset($picked_by_user[$u->id]) ? count($picked_by_user[$u->id]) : 0;
            $shipped = isset($shipped_by_user[$u->id]) ? count($shipped_by_user[$u->id]) : 0;
            // Nick owns nivessa.com shipping — credit the website totals to him.
            if (strtolower(trim(explode(' ', (string) $u->full_name)[0] ?? '')) === 'nick') {
                $picked += $nivessa_picked;
                $shipped += $nivessa_shipped;
            }
            return (object) [
                'user_id' => $u->id,
                'employee' => trim((string) $u->full_name),
                'mass_add_count' => $m,
                'purchase_add_count' => $p,
                'labels_printed_count' => $l,
                'packages_picked_count' => $picked,
                'packages_shipped_count' => $shipped,
                'hours_worked' => $h,
            ];
        })->filter(function ($r) {
            // Hide users with zero activity in the window so admins / inactive
            // accounts don't clutter the ranking. Admins who DO pick/ship/print
            // still show up because they have non-zero counts.
            return ($r->mass_add_count
                + $r->purchase_add_count
                + $r->labels_printed_count
                + $r->packages_picked_count
                + $r->packages_shipped_count) > 0;
        })->sortByDesc(function ($r) {
            // Most productive = total activity across every column. Treats one
            // priced item, one purchase line, one printed label, and one
            // package handled as equal units of work.
            return $r->mass_add_count
                + $r->purchase_add_count
                + $r->labels_printed_count
                + $r->packages_picked_count
                + $r->packages_shipped_count;
        })->values();

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
     * Revenue by Employee — Barcoded Items
     *
     * Per-employee rollup tying every product an employee created
     * (products.created_by) to the revenue those items produced. Date range
     * filters the SALES window; the barcoded count is lifetime so employees
     * see the full denominator behind the conversion. No filter on
     * added_via — counts items added through any flow (Mass Add, Add
     * Product, quick-add, CSV import, buy-from-customer, etc.) since most
     * creation paths don't tag added_via and "anything I added" is the
     * natural employee-facing definition of "items I barcoded".
     */
    public function revenueByEmployeeBarcoding(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $start_date = $request->input('start_date');
        $end_date = $request->input('end_date');
        if (empty($start_date) || empty($end_date)) {
            $start_date = $start_date ?: \Carbon::today()->startOfMonth()->format('Y-m-d');
            $end_date = $end_date ?: \Carbon::today()->format('Y-m-d');
        }

        // Non-admin staff see only their own row; admins see everyone.
        // Treats this as personal performance data — same boundary the
        // user agreed for the Revenue by Employee report (2026-05-09).
        $is_admin = $this->businessUtil->is_admin(auth()->user());
        $current_user_id = auth()->user()->id;

        $users = User::where('business_id', $business_id)
            ->where('allow_login', 1)
            ->where('status', 'active')
            ->when(!$is_admin, function ($q) use ($current_user_id) {
                $q->where('id', $current_user_id);
            })
            ->select('id', DB::raw("CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) as full_name"))
            ->orderBy('first_name')
            ->get();

        $barcoded_total = Product::where('business_id', $business_id)
            ->whereNotNull('created_by')
            ->select('created_by', DB::raw('COUNT(*) as total'))
            ->groupBy('created_by')
            ->pluck('total', 'created_by');

        $sales = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('products as p', 'tsl.product_id', '=', 'p.id')
            ->where('t.business_id', $business_id)
            ->where('p.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereDate('t.transaction_date', '>=', $start_date)
            ->whereDate('t.transaction_date', '<=', $end_date)
            ->whereNotNull('p.created_by')
            ->select(
                'p.created_by',
                DB::raw('COUNT(DISTINCT p.id) as items_sold'),
                DB::raw('SUM((tsl.quantity - tsl.quantity_returned) * tsl.unit_price_inc_tax) as revenue')
            )
            ->groupBy('p.created_by')
            ->get()
            ->keyBy('created_by');

        // Lifetime sold count per employee — used for sell-through % which is
        // a window-independent quality metric ("of everything I ever barcoded,
        // what fraction has eventually sold?").
        $lifetime_sold = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('products as p', 'tsl.product_id', '=', 'p.id')
            ->where('t.business_id', $business_id)
            ->where('p.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNotNull('p.created_by')
            ->select('p.created_by', DB::raw('COUNT(DISTINCT p.id) as items_sold_lifetime'))
            ->groupBy('p.created_by')
            ->pluck('items_sold_lifetime', 'p.created_by');

        // Hours worked in window (Sling first, cash_registers fallback) so
        // the report can show $-per-hour-equivalent context next to revenue.
        $hours_raw = $this->getHoursWorkedByUser($users, $start_date, $end_date, $business_id);

        $rows = $users->map(function ($u) use ($barcoded_total, $sales, $lifetime_sold, $hours_raw) {
            $barcoded = (int) ($barcoded_total[$u->id] ?? 0);
            $row = $sales[$u->id] ?? null;
            $items_sold = $row ? (int) $row->items_sold : 0;
            $revenue = $row ? (float) $row->revenue : 0.0;
            $sold_lifetime = (int) ($lifetime_sold[$u->id] ?? 0);
            $hours = (float) ($hours_raw[$u->id] ?? 0);
            return (object) [
                'user_id' => $u->id,
                'employee' => trim((string) $u->full_name),
                'barcoded_count' => $barcoded,
                'items_sold' => $items_sold,
                'revenue_per_item' => $items_sold > 0 ? $revenue / $items_sold : 0.0,
                'revenue_per_listed_item' => $barcoded > 0 ? $revenue / $barcoded : 0.0,
                'total_revenue' => $revenue,
                'lifetime_items_sold' => $sold_lifetime,
                'sell_through_pct' => $barcoded > 0 ? ($sold_lifetime / $barcoded) * 100 : 0.0,
                'hours_worked' => $hours,
            ];
        })->filter(function ($r) {
            return $r->barcoded_count > 0 || $r->items_sold > 0;
        })->sortByDesc('total_revenue')->values();

        return view('report.revenue_by_employee_barcoding')->with(compact(
            'rows', 'start_date', 'end_date'
        ));
    }

    /**
     * Drill-down for Revenue by Employee — Barcoded Items.
     *
     * Lists each barcoded product the employee added that sold within the
     * window, with qty sold and revenue. Unsold items are omitted — this
     * report's question is "what led to sales?", so zero-sale rows aren't
     * useful here.
     */
    public function revenueByEmployeeBarcodingDetail(Request $request, $user_id)
    {
        $business_id = $request->session()->get('user.business_id');
        $start_date = $request->input('start_date');
        $end_date = $request->input('end_date');
        if (empty($start_date) || empty($end_date)) {
            $start_date = $start_date ?: \Carbon::today()->startOfMonth()->format('Y-m-d');
            $end_date = $end_date ?: \Carbon::today()->format('Y-m-d');
        }

        // Non-admins can only drill into their own row.
        if (!$this->businessUtil->is_admin(auth()->user()) && (int) $user_id !== (int) auth()->user()->id) {
            abort(403, 'You can only view your own revenue detail.');
        }

        $user = User::where('business_id', $business_id)->findOrFail($user_id);
        $employee = trim((string) ($user->first_name . ' ' . $user->last_name));

        $items = DB::table('products as p')
            ->join('transaction_sell_lines as tsl', 'tsl.product_id', '=', 'p.id')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->where('p.business_id', $business_id)
            ->where('p.created_by', $user_id)
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereDate('t.transaction_date', '>=', $start_date)
            ->whereDate('t.transaction_date', '<=', $end_date)
            ->select(
                'p.id',
                'p.name',
                'p.sku',
                'p.created_at',
                DB::raw('SUM(tsl.quantity - tsl.quantity_returned) as qty_sold'),
                DB::raw('SUM((tsl.quantity - tsl.quantity_returned) * tsl.unit_price_inc_tax) as revenue')
            )
            ->groupBy('p.id', 'p.name', 'p.sku', 'p.created_at')
            ->orderByDesc('revenue')
            ->get();

        $barcoded_lifetime = (int) Product::where('business_id', $business_id)
            ->where('created_by', $user_id)
            ->count();

        // Per-category breakdown: lifetime barcoded counts joined with
        // sales-in-window, grouped by (category, subcategory). LEFT JOINs to
        // categories so uncategorized rows still show up as "—".
        $barcoded_by_cat = DB::table('products as p')
            ->leftJoin('categories as cat', 'p.category_id', '=', 'cat.id')
            ->leftJoin('categories as subcat', 'p.sub_category_id', '=', 'subcat.id')
            ->where('p.business_id', $business_id)
            ->where('p.created_by', $user_id)
            ->select(
                DB::raw('COALESCE(p.category_id, 0) as category_id'),
                DB::raw('COALESCE(p.sub_category_id, 0) as sub_category_id'),
                'cat.name as category_name',
                'subcat.name as subcategory_name',
                DB::raw('COUNT(*) as barcoded_count')
            )
            ->groupBy('p.category_id', 'p.sub_category_id', 'cat.name', 'subcat.name')
            ->get()
            ->keyBy(function ($r) { return $r->category_id . ':' . $r->sub_category_id; });

        $sales_by_cat = DB::table('products as p')
            ->join('transaction_sell_lines as tsl', 'tsl.product_id', '=', 'p.id')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->where('p.business_id', $business_id)
            ->where('p.created_by', $user_id)
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereDate('t.transaction_date', '>=', $start_date)
            ->whereDate('t.transaction_date', '<=', $end_date)
            ->select(
                DB::raw('COALESCE(p.category_id, 0) as category_id'),
                DB::raw('COALESCE(p.sub_category_id, 0) as sub_category_id'),
                DB::raw('COUNT(DISTINCT p.id) as items_sold'),
                DB::raw('SUM((tsl.quantity - tsl.quantity_returned) * tsl.unit_price_inc_tax) as revenue')
            )
            ->groupBy('p.category_id', 'p.sub_category_id')
            ->get()
            ->keyBy(function ($r) { return $r->category_id . ':' . $r->sub_category_id; });

        // Lifetime sold counts per (category, subcategory) — same purpose as
        // $lifetime_sold on the summary view: gives a window-independent
        // sell-through %.
        $lifetime_sold_by_cat = DB::table('products as p')
            ->join('transaction_sell_lines as tsl', 'tsl.product_id', '=', 'p.id')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->where('p.business_id', $business_id)
            ->where('p.created_by', $user_id)
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->select(
                DB::raw('COALESCE(p.category_id, 0) as category_id'),
                DB::raw('COALESCE(p.sub_category_id, 0) as sub_category_id'),
                DB::raw('COUNT(DISTINCT p.id) as items_sold_lifetime')
            )
            ->groupBy('p.category_id', 'p.sub_category_id')
            ->get()
            ->keyBy(function ($r) { return $r->category_id . ':' . $r->sub_category_id; });

        $by_category = $barcoded_by_cat->map(function ($b) use ($sales_by_cat, $lifetime_sold_by_cat) {
            $key = $b->category_id . ':' . $b->sub_category_id;
            $s = $sales_by_cat[$key] ?? null;
            $life = $lifetime_sold_by_cat[$key] ?? null;
            $sold_lifetime = $life ? (int) $life->items_sold_lifetime : 0;
            $barcoded = (int) $b->barcoded_count;
            return (object) [
                'category_name' => $b->category_name ?: '— Uncategorized —',
                'subcategory_name' => $b->subcategory_name ?: '',
                'barcoded_count' => $barcoded,
                'items_sold' => $s ? (int) $s->items_sold : 0,
                'total_revenue' => $s ? (float) $s->revenue : 0.0,
                'lifetime_items_sold' => $sold_lifetime,
                'sell_through_pct' => $barcoded > 0 ? ($sold_lifetime / $barcoded) * 100 : 0.0,
            ];
        })->sortByDesc('total_revenue')->values();

        $total_revenue = (float) $items->sum('revenue');
        $items_sold = $items->count();
        $totals = (object) [
            'barcoded_lifetime' => $barcoded_lifetime,
            'items_sold' => $items_sold,
            'total_revenue' => $total_revenue,
            'revenue_per_item' => $items_sold > 0 ? $total_revenue / $items_sold : 0.0,
        ];

        // Per-store units sold by category/subcategory over the trailing 30 days.
        // Business-wide (all sales, regardless of who barcoded the item), pivoted
        // with stores as columns. Independent of the date filter above.
        $stores = BusinessLocation::forDropdown($business_id);
        $store_window_start = \Carbon::today()->subDays(30)->format('Y-m-d');
        $store_window_end = \Carbon::today()->format('Y-m-d');

        $store_cat_rows = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('products as p', 'tsl.product_id', '=', 'p.id')
            ->leftJoin('categories as cat', 'p.category_id', '=', 'cat.id')
            ->leftJoin('categories as subcat', 'p.sub_category_id', '=', 'subcat.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereDate('t.transaction_date', '>=', $store_window_start)
            ->whereDate('t.transaction_date', '<=', $store_window_end)
            ->select(
                DB::raw('COALESCE(p.category_id, 0) as category_id'),
                DB::raw('COALESCE(p.sub_category_id, 0) as sub_category_id'),
                'cat.name as category_name',
                'subcat.name as subcategory_name',
                't.location_id',
                DB::raw('SUM(tsl.quantity - tsl.quantity_returned) as units_sold')
            )
            ->groupBy('p.category_id', 'p.sub_category_id', 'cat.name', 'subcat.name', 't.location_id')
            ->get();

        $store_pivot = [];
        foreach ($store_cat_rows as $r) {
            // Only count units sold at stores the viewer is permitted to see.
            if (!$stores->has($r->location_id)) {
                continue;
            }
            $key = $r->category_id . ':' . $r->sub_category_id;
            if (!isset($store_pivot[$key])) {
                $store_pivot[$key] = (object) [
                    'category_name' => $r->category_name ?: '— Uncategorized —',
                    'subcategory_name' => $r->subcategory_name ?: '',
                    'units_by_store' => [],
                    'total_units' => 0,
                ];
            }
            $units = (int) $r->units_sold;
            $store_pivot[$key]->units_by_store[$r->location_id] =
                ($store_pivot[$key]->units_by_store[$r->location_id] ?? 0) + $units;
            $store_pivot[$key]->total_units += $units;
        }
        $store_pivot = collect($store_pivot)->sortByDesc('total_units')->values();

        return view('report.revenue_by_employee_barcoding_detail')->with(compact(
            'items', 'totals', 'employee', 'user', 'start_date', 'end_date', 'by_category',
            'stores', 'store_pivot', 'store_window_start', 'store_window_end'
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
     * Selling Below Cost report.
     *
     * Lists variations whose current selling price (sell_price_inc_tax — the
     * canonical sticker the rest of the ERP's reports read) is below what we
     * currently paid for them (dpp_inc_tax). i.e. items priced to sell at a
     * loss. Both columns are inc-tax so it's an apples-to-apples comparison
     * (and Nivessa's resale cert means exc == inc anyway).
     *
     * Item-level pricing hygiene, not aggregated sales — open to all staff,
     * same as Dead Stock / Inventory Valuation Detail.
     */
    public function sellingBelowCostReport(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');

        $location_id = $request->input('location_id');
        $business_locations = BusinessLocation::forDropdown($business_id);

        // Qty on hand per variation, optionally scoped to one location. Summed
        // so a variation stocked at several stores stays a single row.
        $stockSub = DB::table('variation_location_details as vld')
            ->select('vld.variation_id', DB::raw('SUM(vld.qty_available) as qty_available'))
            ->groupBy('vld.variation_id');
        if (!empty($location_id)) {
            $stockSub->where('vld.location_id', $location_id);
        }

        $query = DB::table('variations as v')
            ->join('products as p', 'v.product_id', '=', 'p.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoinSub($stockSub, 'st', function ($join) {
                $join->on('v.id', '=', 'st.variation_id');
            })
            ->where('p.business_id', $business_id)
            ->where('p.type', '!=', 'modifier')
            ->whereNull('v.deleted_at')
            ->where('v.sell_price_inc_tax', '>', 0)
            ->where('v.dpp_inc_tax', '>', 0)
            // Below cost: what we charge is under what we paid. 0.01 guard so
            // rounding noise on break-even items doesn't get flagged.
            ->whereRaw('ROUND(v.dpp_inc_tax, 2) > ROUND(v.sell_price_inc_tax, 2) + 0.01');

        // Default to in-stock only — you can only sell-at-a-loss what you hold.
        // Untick to audit the whole catalog's pricing regardless of stock.
        $in_stock_only = $request->input('in_stock_only', '1') === '1';
        if ($in_stock_only) {
            $query->where('st.qty_available', '>', 0);
        }

        $query->select(
            'v.id as variation_id',
            'p.id as product_id',
            'p.artist',
            'p.name',
            'p.format',
            'v.sub_sku',
            'c.name as category',
            DB::raw('COALESCE(st.qty_available, 0) as qty_available'),
            'v.dpp_inc_tax as cost',
            'v.sell_price_inc_tax as selling_price',
            DB::raw('(v.dpp_inc_tax - v.sell_price_inc_tax) as loss_per_unit'),
            DB::raw('(v.dpp_inc_tax - v.sell_price_inc_tax) * COALESCE(st.qty_available, 0) as exposure')
        );

        // Totals across the full filtered set (before pagination + sort)
        $totals_base = (clone $query);
        $totals = DB::query()
            ->fromSub($totals_base, 'x')
            ->selectRaw('COUNT(*) as total_variations, COALESCE(SUM(qty_available), 0) as total_qty, COALESCE(SUM(exposure), 0) as total_exposure')
            ->first();

        // Column sort: whitelist columns to prevent SQL injection
        $sort = $request->input('sort', 'loss_per_unit');
        $dir  = strtolower($request->input('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sort_map = [
            'artist'        => 'p.artist',
            'title'         => 'p.name',
            'format'        => 'p.format',
            'sku'           => 'v.sub_sku',
            'category'      => 'c.name',
            'qty'           => 'qty_available',
            'cost'          => 'v.dpp_inc_tax',
            'price'         => 'v.sell_price_inc_tax',
            'loss_per_unit' => 'loss_per_unit',
            'exposure'      => 'exposure',
        ];
        $sort_col = $sort_map[$sort] ?? 'loss_per_unit';
        if (in_array($sort_col, ['loss_per_unit', 'exposure', 'qty_available'])) {
            $query->orderByRaw($sort_col . ' ' . $dir);
        } else {
            $query->orderBy($sort_col, $dir);
        }

        $rows = $query->paginate(50)->appends($request->except('page'));

        return view('report.selling_below_cost')->with(compact(
            'rows', 'business_locations', 'location_id', 'totals', 'sort', 'dir', 'in_stock_only'
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

        // Gross profit for Whatnot-flagged sales (mirrors sales-by-channel
        // math — TSPL/PL join, ignore combos). Whatnot sales ring as real
        // POS transactions with line items, so cost basis is available.
        $whatnot_gp_obj = DB::table('transaction_sell_lines as tsl')
            ->join('transactions as sale', 'tsl.transaction_id', '=', 'sale.id')
            ->leftJoin('transaction_sell_lines_purchase_lines as TSPL', 'tsl.id', '=', 'TSPL.sell_line_id')
            ->leftJoin('purchase_lines as PL', 'TSPL.purchase_line_id', '=', 'PL.id')
            ->where('sale.business_id', $business_id)
            ->where('sale.type', 'sell')
            ->where('sale.status', 'final')
            ->where('sale.is_whatnot', 1)
            ->whereDate('sale.transaction_date', '>=', $start_date)
            ->whereDate('sale.transaction_date', '<=', $end_date)
            ->when(!empty($location_id), function ($q) use ($location_id) {
                return $q->where('sale.location_id', $location_id);
            })
            ->where('tsl.children_type', '!=', 'combo')
            ->selectRaw('COALESCE(SUM((TSPL.quantity - TSPL.qty_returned) *
                (tsl.unit_price_inc_tax - PL.purchase_price_inc_tax)), 0) as gross_profit,
                COALESCE(SUM((TSPL.quantity - TSPL.qty_returned) * PL.purchase_price_inc_tax), 0) as cogs')
            ->first();
        $whatnot_gp = (float)($whatnot_gp_obj->gross_profit ?? 0);
        $whatnot_cogs = (float)($whatnot_gp_obj->cogs ?? 0);
        $whatnot_revenue = (float)($whatnot->total ?? 0);
        $whatnot_margin_pct = $whatnot_revenue > 0 ? ($whatnot_gp / $whatnot_revenue) * 100 : 0;

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
            'whatnot_gp', 'whatnot_cogs', 'whatnot_margin_pct',
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

        // Reset diagnostics — populated by the per-channel fetchers below.
        $this->channelDiagnostics = [];

        // Channels that are also live-fetched below from their source API
        // (Discogs orders, nivessa.com web orders + space rentals). The
        // Channel Sales Sync now stores these as ERP transactions too, so we
        // exclude them from this DB group-by to avoid double-counting them
        // against the live fetch — this one report stays a live dashboard for
        // them. They remain source-of-truth for every other report/ledger.
        $live_fetched_channels = ['discogs', 'web', 'space_rental'];

        // Revenue + transaction count per (location, channel).
        $rev = DB::table('transactions as t')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNotIn('t.channel', $live_fetched_channels)
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
            ->whereNotIn('sale.channel', $live_fetched_channels)
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
        // each render (no separate sync step). Non-null = API succeeded; we
        // always add a row (even $0 / 0 txns) so the channel shows as live.
        $dgs = $this->fetchDiscogsChannelTotals($business_id, $start_date, $end_date);
        if ($dgs !== null) {
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
            // We now match Discogs order items → local listings → variation
            // cost. Margin is computed only against matched-item revenue, so
            // unmatched items inflate revenue without inflating COGS — flag
            // the row only if EVERY item was unmatched.
            $rows[$key]['gross_profit']    += (float) ($dgs['gross_profit'] ?? 0);
            $matched = (int) ($dgs['matched_items'] ?? 0);
            $unmatched = (int) ($dgs['unmatched_items'] ?? 0);
            $rows[$key]['cost_unknown'] = ($matched === 0);
            $rows[$key]['cost_partial'] = ($matched > 0 && $unmatched > 0);
            $rows[$key]['discogs_match_summary'] = "{$matched}/" . ($matched + $unmatched) . ' line items matched to local cost';
            $overall_revenue += $dgs['revenue'];
        }

        // eBay orders — live-fetched via Sell Fulfillment API. Requires the
        // seller to have connected their account at /admin/ebay-seller.
        $eby = $this->fetchEbayChannelTotals($business_id, $start_date, $end_date);
        if ($eby !== null) {
            $key = 'online|ebay';
            if (!isset($rows[$key])) {
                $rows[$key] = [
                    'label'           => 'eBay & Other',
                    'channel'         => 'ebay',
                    'location_id'     => null,
                    'revenue'         => 0.0,
                    'revenue_exc_tax' => 0.0,
                    'cnt'             => 0,
                    'gross_profit'    => 0.0,
                    'cost_unknown'    => true,
                ];
            }
            $rows[$key]['revenue']         += $eby['revenue'];
            $rows[$key]['revenue_exc_tax'] += $eby['revenue'];
            $rows[$key]['cnt']             += $eby['cnt'];
            $rows[$key]['cost_unknown'] = true;
            $overall_revenue += $eby['revenue'];
        }

        // $0 placeholder rows when a channel never appeared (not configured,
        // not connected, or API error) so the table matches the expected
        // channel set and labels point to the fix.
        $this->mergeSalesByChannelPlaceholderRows($business_id, $rows, $dgs, $eby);

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
        // Group rows by channel family so the two Whatnot rows always sit
        // adjacent (Sarah 2026-05-05 — was getting separated by Discogs /
        // web rows when sorted purely by revenue). Within each group, sort
        // by revenue desc so the dominant location/channel still bubbles up.
        $family = function ($channel) {
            if ($channel === 'in_store') return 1;
            if ($channel === 'whatnot')  return 2;
            return 3; // discogs, ebay, web_ship, web_pickup, space_rental
        };
        usort($rows, function ($a, $b) use ($family) {
            $fa = $family($a['channel']);
            $fb = $family($b['channel']);
            if ($fa !== $fb) return $fa <=> $fb;
            return $b['revenue'] <=> $a['revenue'];
        });

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

        // Sales targets per channel (Sarah 2026-05-18). Weekly target kicks
        // in for ranges ≤ 10 days, monthly otherwise — so the "this month"
        // default and a "last 7 days" filter both make sense without
        // needing a separate period switcher.
        // Bucket targets — three groups (HW Store, Pico Store, Online).
        // Whatnot Hollywood rolls into HW, Whatnot - Pico into Pico, and
        // every other non-store channel (Discogs, eBay, web ship/pickup,
        // space rentals) lands in Online (Sarah 2026-05-18).
        $bucket_targets = [
            'hollywood' => ['label' => 'Hollywood Store', 'weekly' => 14000, 'monthly' => 60000],
            'pico'      => ['label' => 'Pico Store',      'weekly' => 6400,  'monthly' => 27500],
            'online'    => ['label' => 'Online',          'weekly' => 2200,  'monthly' => 9500],
        ];
        $row_to_bucket = function ($row) {
            $label_lower = strtolower($row['label']);
            if (strpos($label_lower, 'hollywood') !== false) return 'hollywood';
            if (strpos($label_lower, 'pico') !== false)      return 'pico';
            // Everything else that isn't a physical store is "Online".
            if (!in_array($row['channel'], ['in_store', 'whatnot'], true)) return 'online';
            return null;
        };
        $days_in_range = \Carbon::parse($start_date)->diffInDays(\Carbon::parse($end_date)) + 1;
        $target_period = $days_in_range <= 10 ? 'weekly' : 'monthly';
        $target_period_label = $target_period === 'weekly' ? 'weekly target' : 'monthly target';

        $buckets = [];
        foreach ($bucket_targets as $key => $cfg) {
            $buckets[$key] = [
                'key'      => $key,
                'label'    => $cfg['label'],
                'target'   => $cfg[$target_period],
                'revenue'  => 0.0,
                'channels' => [],
            ];
        }
        foreach ($rows as $row) {
            $b = $row_to_bucket($row);
            if ($b === null) continue;
            $buckets[$b]['revenue']    += (float) $row['revenue'];
            $buckets[$b]['channels'][]  = $row['label'];
        }
        foreach ($buckets as &$b) {
            $b['target_pct'] = $b['target'] > 0 ? ($b['revenue'] / $b['target']) * 100 : null;
        }
        unset($b);

        $total_target = array_sum(array_column($buckets, 'target'));
        $totals['target']     = $total_target;
        $totals['target_pct'] = $total_target > 0 ? ($overall_revenue / $total_target) * 100 : null;
        $totals['target_period_label'] = $target_period_label;

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

        $diagnostics = $this->channelDiagnostics ?? [];

        return view('report.sales_by_channel')->with(compact(
            'rows', 'totals', 'start_date', 'end_date', 'business_locations', 'diagnostics', 'target_period_label', 'buckets'
        ));
    }

    /**
     * Where the uploaded weekly budget (parsed from Sarah's "Weekly v2"
     * sheet) lives. JSON on disk — no migration, same pattern as the
     * clover-manual-matches / return-approvals stores.
     */
    protected function cashFlowBudgetPath($business_id)
    {
        return storage_path('app/cashflow-budget-' . $business_id . '.json');
    }

    /**
     * Read the saved budget JSON, or null if nothing's been uploaded.
     */
    protected function loadCashFlowBudget($business_id)
    {
        $path = $this->cashFlowBudgetPath($business_id);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Handle the budget-sheet upload. Parses the "Weekly v2" tab into the
     * weeks + line-item structure the report renders, saves it as JSON.
     */
    public function uploadCashFlowBudget(Request $request)
    {
        $this->ensureAdminOnlyReportAccess();
        $business_id = $request->session()->get('user.business_id');

        $request->validate([
            'budget_file' => 'required|file|mimes:xlsx,xls',
        ]);

        try {
            $budget = $this->parseCashFlowBudgetFile($request->file('budget_file')->getRealPath());
        } catch (\Exception $e) {
            return redirect()->back()->with(['status' => [
                'success' => 0,
                'msg' => 'Could not read the budget sheet: ' . $e->getMessage(),
            ]]);
        }

        if (empty($budget['weeks']) || empty($budget['sections'])) {
            return redirect()->back()->with(['status' => [
                'success' => 0,
                'msg' => 'No weekly budget found. Make sure the file has a "Weekly v2" tab laid out like your cash-flow sheet.',
            ]]);
        }

        $budget['uploaded_at'] = \Carbon::now()->format('Y-m-d H:i:s');
        $budget['uploaded_filename'] = $request->file('budget_file')->getClientOriginalName();
        file_put_contents(
            $this->cashFlowBudgetPath($business_id),
            json_encode($budget, JSON_PRETTY_PRINT)
        );

        return redirect()->back()->with(['status' => [
            'success' => 1,
            'msg' => 'Budget loaded: ' . count($budget['weeks']) . ' weeks from "' . $budget['source_sheet'] . '".',
        ]]);
    }

    /**
     * Parse the "Weekly v2" sheet into:
     *   weeks[]    — {label, range, start, end} (dates inferred, see below)
     *   opening_seed — week-1 "Money at the begining" value
     *   sections[] — revenue / cogs / opex, each with line items carrying a
     *                13-long budget[] array (sheet signs preserved: revenue
     *                positive, cogs/opex negative)
     *
     * Robust to row shuffling: we anchor on the header row ("Line Item") and
     * on the known UPPERCASE section titles rather than fixed row numbers,
     * and skip computed total rows (Revenue / Purchase / Cash flow).
     */
    protected function parseCashFlowBudgetFile($path)
    {
        // The full workbook has ~20 sheets (pivot caches, 70k-row history
        // tabs). Load ONLY the budget tab, data-only, so we don't blow memory
        // or time. Pick the sheet by name case-insensitively; fall back to the
        // first sheet if "Weekly v2" isn't found.
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        $names = $reader->listWorksheetNames($path);
        $target = null;
        foreach ($names as $n) {
            if (strcasecmp(trim($n), 'Weekly v2') === 0) {
                $target = $n;
                break;
            }
        }
        if ($target === null) {
            $target = $names[0] ?? null;
        }
        if ($target === null) {
            throw new \Exception('The workbook has no sheets.');
        }
        $reader->setLoadSheetsOnly([$target]);
        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName($target) ?? $spreadsheet->getActiveSheet();

        $rows = $sheet->toArray(null, true, false, false); // 0-indexed, raw values

        // 1. Find the header row (col A == "Line Item") and read week columns.
        $headerIdx = null;
        foreach ($rows as $i => $row) {
            if (isset($row[0]) && strcasecmp(trim((string) $row[0]), 'Line Item') === 0) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null) {
            throw new \Exception('Could not find the "Line Item" header row.');
        }

        $weeks = [];
        $weekCols = [];
        foreach ($rows[$headerIdx] as $col => $val) {
            if ($col === 0 || $val === null || trim((string) $val) === '') {
                continue;
            }
            $parsed = $this->parseBudgetWeekHeader((string) $val);
            $weekCols[] = $col;
            $weeks[] = [
                'label' => $parsed['label'],
                'range' => $parsed['range'],
                'start' => null, // filled after year inference
                'end'   => null,
            ];
        }
        if (empty($weeks)) {
            throw new \Exception('No week columns found in the header row.');
        }
        $this->inferBudgetWeekDates($weeks);

        // 2. Walk the rows, tracking the current section.
        $sectionMap = [
            'REVENUE'            => 'revenue',
            'COST OF GOODS'      => 'cogs',
            'OPERATING EXPENSES' => 'opex',
            'OPENING BALANCE'    => 'opening',
            'NET CASH FLOW'      => 'net',
            'CLOSING BALANCE'    => 'closing',
        ];
        // Computed totals we never want as line items (they'd double count).
        $skipLabels = ['revenue', 'purchase', 'cash flow'];

        $sections = [
            'revenue' => ['key' => 'revenue', 'title' => 'REVENUE', 'items' => []],
            'cogs'    => ['key' => 'cogs', 'title' => 'COST OF GOODS', 'items' => []],
            'opex'    => ['key' => 'opex', 'title' => 'OPERATING EXPENSES', 'items' => []],
        ];
        $opening_seed = 0.0;
        // Sarah's own opening / net / closing rows. We keep them verbatim
        // for the budget side because her "Cash flow" and "Money at the end"
        // rows don't always equal the visible line items — recomputing would
        // contradict the numbers she actually planned against.
        $opening_row = null;
        $net_row = null;
        $closing_row = null;

        $current = null;
        for ($i = $headerIdx + 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $label = isset($row[0]) ? trim((string) $row[0]) : '';
            if ($label === '') {
                continue;
            }

            $upper = strtoupper($label);
            if (isset($sectionMap[$upper])) {
                $current = $sectionMap[$upper];
                // OPERATING EXPENSES carries its own total on the header row;
                // that's fine, we just switch section and read items below.
                continue;
            }

            $values = $this->readBudgetRowValues($row, $weekCols);

            if ($current === 'opening') {
                if ($opening_row === null) {
                    $opening_row = $values;
                    $opening_seed = $values[0] ?? 0.0;
                }
                continue;
            }
            if ($current === 'net') {
                if ($net_row === null) $net_row = $values;
                continue;
            }
            if ($current === 'closing') {
                if ($closing_row === null) $closing_row = $values;
                continue;
            }
            if (in_array(strtolower($label), $skipLabels, true)) {
                continue; // computed "Revenue" / "Purchase" totals
            }
            if (!isset($sections[$current])) {
                continue;
            }
            // Skip empty rows (all zero/blank).
            if (count(array_filter($values, function ($v) { return $v != 0; })) === 0) {
                continue;
            }

            $sections[$current]['items'][] = [
                'label'  => $label,
                'budget' => $values,
            ];
        }

        return [
            'source_sheet' => $sheet->getTitle(),
            'opening_seed' => $opening_seed,
            'opening_row'  => $opening_row,
            'net_row'      => $net_row,
            'closing_row'  => $closing_row,
            'weeks'        => $weeks,
            'sections'     => array_values($sections),
        ];
    }

    /**
     * Read the per-week numeric values for a row, in week order. Sheet uses
     * "-" and blanks for zero; both become 0.0.
     */
    protected function readBudgetRowValues($row, $weekCols)
    {
        $values = [];
        foreach ($weekCols as $col) {
            $raw = $row[$col] ?? null;
            if ($raw === null || $raw === '-' || trim((string) $raw) === '') {
                $values[] = 0.0;
            } else {
                $values[] = (float) str_replace([',', '$'], '', (string) $raw);
            }
        }
        return $values;
    }

    /**
     * Turn a header cell like "Week 1\n18-24.05" or "Week 7\n29.06-5.07" into
     * a label + day/month range. Year is resolved later in
     * inferBudgetWeekDates() since the sheet doesn't carry one.
     */
    protected function parseBudgetWeekHeader($header)
    {
        $parts = preg_split('/\r\n|\r|\n/', trim($header));
        $label = trim($parts[0]);
        $range = isset($parts[1]) ? trim($parts[1]) : '';
        return ['label' => $label, 'range' => $range];
    }

    /**
     * The sheet's week headers carry day/month but no year. Resolve a concrete
     * start/end date for each week: pick the year that lands week-1's start
     * nearest to today (within a year either way), then carry the year forward,
     * bumping it whenever the month rolls back (Dec -> Jan).
     */
    protected function inferBudgetWeekDates(array &$weeks)
    {
        $today = \Carbon::now();
        $sides = [];
        foreach ($weeks as $w) {
            $sides[] = $this->parseWeekRangeSides($w['range']);
        }

        // First week's start month is our anchor.
        $anchorMonth = $sides[0]['start_m'] ?? $today->month;
        $anchorDay   = $sides[0]['start_d'] ?? 1;

        $bestYear = $today->year;
        $bestDiff = PHP_INT_MAX;
        foreach ([$today->year - 1, $today->year, $today->year + 1] as $y) {
            if (!checkdate($anchorMonth, $anchorDay, $y)) {
                continue;
            }
            $d = \Carbon::create($y, $anchorMonth, $anchorDay);
            $diff = abs($d->diffInDays($today));
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $bestYear = $y;
            }
        }

        $year = $bestYear;
        $prevMonth = null;
        foreach ($weeks as $idx => &$w) {
            $s = $sides[$idx];
            if ($s === null) {
                continue;
            }
            if ($prevMonth !== null && $s['start_m'] < $prevMonth) {
                $year++;
            }
            $startYear = $year;
            $endYear = $year;
            // Range that crosses into a lower month (e.g. 29.12-04.01) ends next year.
            if ($s['end_m'] < $s['start_m']) {
                $endYear = $year + 1;
            }
            if (checkdate($s['start_m'], $s['start_d'], $startYear)) {
                $w['start'] = \Carbon::create($startYear, $s['start_m'], $s['start_d'])->format('Y-m-d');
            }
            if (checkdate($s['end_m'], $s['end_d'], $endYear)) {
                $w['end'] = \Carbon::create($endYear, $s['end_m'], $s['end_d'])->format('Y-m-d');
            }
            $prevMonth = $s['start_m'];
        }
        unset($w);
    }

    /**
     * Parse a "18-24.05" / "29.06-5.07" / "27.07-02.08" range into
     * start/end day+month. A side without its own month borrows the other's.
     */
    protected function parseWeekRangeSides($range)
    {
        if (trim($range) === '' || strpos($range, '-') === false) {
            return null;
        }
        [$left, $right] = explode('-', $range, 2);
        $parseSide = function ($s) {
            $s = trim($s);
            $bits = explode('.', $s);
            $day = isset($bits[0]) && $bits[0] !== '' ? (int) $bits[0] : null;
            $month = isset($bits[1]) && $bits[1] !== '' ? (int) $bits[1] : null;
            return ['d' => $day, 'm' => $month];
        };
        $l = $parseSide($left);
        $r = $parseSide($right);
        if ($l['m'] === null) $l['m'] = $r['m'];
        if ($r['m'] === null) $r['m'] = $l['m'];
        if ($l['d'] === null || $l['m'] === null || $r['d'] === null || $r['m'] === null) {
            return null;
        }
        return ['start_d' => $l['d'], 'start_m' => $l['m'], 'end_d' => $r['d'], 'end_m' => $r['m']];
    }

    /**
     * Cash Flow report — Sarah's weekly model, not QB's accounting statement.
     * Shows, per week: opening balance, revenue / COGS / operating-expense
     * line items, and closing balance — each as BUDGET (from the uploaded
     * "Weekly v2" sheet) vs ACTUAL (reformatted from QuickBooks). Falls back
     * gracefully: the budget grid renders even if QB is down, and the report
     * explains itself if no budget has been uploaded yet.
     */
    public function cashFlowReport(Request $request)
    {
        $this->ensureAdminOnlyReportAccess();

        $business_id = $request->session()->get('user.business_id');
        $budget = $this->loadCashFlowBudget($business_id);

        $qb = new \App\Services\QuickBooksService($business_id);
        $configured = $qb->isConfigured();

        // Live bank position (reference figure shown at the top).
        $accounts = [];
        $accounts_error = null;
        if ($configured) {
            $bank = $qb->getBankAccounts();
            if (!empty($bank['success'])) {
                $accounts = $bank['accounts'] ?? [];
            } else {
                $accounts_error = $bank['msg'] ?? 'Could not fetch bank accounts.';
            }
        }
        $bank_total = 0.0;
        foreach ($accounts as $a) {
            if (strtolower($a['type']) === 'credit card') {
                $bank_total -= (float) $a['balance'];
            } else {
                $bank_total += (float) $a['balance'];
            }
        }

        // No budget uploaded yet — render the empty state (view shows the
        // upload form + explanation).
        if (empty($budget)) {
            return view('report.cash_flow')->with([
                'configured' => $configured,
                'accounts' => $accounts,
                'accounts_error' => $accounts_error,
                'bank_total' => $bank_total,
                'budget' => null,
                'grid' => null,
                'actuals_error' => null,
            ]);
        }

        $weeks = $budget['weeks'];
        $weekCount = count($weeks);
        $span_start = $weeks[0]['start'] ?? null;
        $span_end = $weeks[$weekCount - 1]['end'] ?? null;

        // Pull weekly actuals from QB across the budget's date span and bucket
        // each account into one of Sarah's line items.
        $actuals = null;
        $actuals_error = null;
        $unmapped = [];
        if ($configured && $span_start && $span_end) {
            $pl = $qb->getProfitLossByWeek($span_start, $span_end, 'Cash');
            if (!empty($pl['success'])) {
                [$actuals, $unmapped] = $this->mapQbActualsToBudget($pl['report'] ?? null, $weeks, $budget['sections']);
            } else {
                $actuals_error = $pl['msg'] ?? 'Could not fetch weekly actuals from QuickBooks.';
            }
        } elseif (!$configured) {
            $actuals_error = 'QuickBooks is not connected, so only the budget is shown.';
        }

        $grid = $this->buildCashFlowGrid($budget, $actuals, $unmapped, $weekCount);

        return view('report.cash_flow')->with([
            'configured' => $configured,
            'accounts' => $accounts,
            'accounts_error' => $accounts_error,
            'bank_total' => $bank_total,
            'budget' => $budget,
            'grid' => $grid,
            'actuals_error' => $actuals_error,
        ]);
    }

    /**
     * Default mapping from QuickBooks account names to Sarah's line items.
     * Keys are the line-item labels from her sheet; values are lowercase
     * substrings — if a QB account name contains any of them, its amounts
     * roll into that line. Anything unmatched lands in an "Other (QB)" line
     * for its section, so no money silently disappears.
     */
    protected function cashFlowQbMapping()
    {
        return [
            'revenue' => [
                'Nivessa Hollywood' => ['hollywood', 'nivessa hw', 'hw sales'],
                'Nivessa Pico'      => ['pico'],
                'Discogs'           => ['discogs'],
                'Whatnot'           => ['whatnot'],
                'nivessa.com'       => ['nivessa.com', 'website', 'online', 'shopify', 'web sales'],
            ],
            'cogs' => [
                'Purchase' => ['purchase', 'cost of goods', 'cogs', 'inventory'],
            ],
            'opex' => [
                'Rent'                    => ['rent', 'lease'],
                'Clover fees'             => ['clover', 'merchant fee', 'processing fee', 'card fee'],
                'Nick payroll'            => ['nick'],
                'Shipping'                => ['shipping', 'postage', 'usps', 'fedex', 'ups'],
                'Whatnot Host Commission' => ['host commission', 'whatnot host', 'host'],
                'Payroll'                 => ['payroll', 'wages', 'salary', 'salaries'],
                'Freelance Work'          => ['freelance', 'contractor', 'contract labor'],
                'Payroll taxes'           => ['payroll tax', 'employer tax'],
                'Meals'                   => ['meal', 'food', 'restaurant'],
                'Insurance'               => ['insurance'],
                'Electricity HW'          => ['electric', 'power', 'utility', 'utilities'],
                'Supplies'                => ['supply', 'supplies'],
                'Internet'                => ['internet', 'wifi'],
                'Phone service'           => ['phone', 'mobile', 'cell'],
                'Subscriptions'           => ['subscription', 'software', 'saas'],
                'Vehicle gas & fuel'      => ['gas', 'fuel', 'vehicle', 'mileage'],
                "Owner's Draw"            => ['owner', 'draw', 'distribution'],
            ],
        ];
    }

    /**
     * Walk QB's weekly P&L tree and produce, for each of Sarah's line items, a
     * per-week actual array aligned to the budget's weeks. Returns
     * [actuals, unmapped] where:
     *   actuals  = ['revenue'=>['Label'=>[w0..wN]], 'cogs'=>..., 'opex'=>...]
     *   unmapped = ['revenue'=>[w0..wN], 'opex'=>[...]] catch-all per section
     *
     * QB groups rows under Income / COGS / Expenses; we map Income->revenue,
     * COGS->cogs, Expenses->opex. Revenue keeps QB's positive sign; costs are
     * stored negative to match the sheet.
     */
    protected function mapQbActualsToBudget($report, $weeks, $budgetSections)
    {
        $weekCount = count($weeks);
        $zero = array_fill(0, $weekCount, 0.0);

        $actuals = ['revenue' => [], 'cogs' => [], 'opex' => []];
        $unmapped = ['revenue' => $zero, 'cogs' => $zero, 'opex' => $zero];

        // Seed line items from the budget so order matches and zero-actual
        // lines still render.
        foreach ($budgetSections as $sec) {
            foreach ($sec['items'] as $item) {
                $actuals[$sec['key']][$item['label']] = $zero;
            }
        }
        if (empty($report) || empty($report['Columns']['Column']) || empty($report['Rows']['Row'])) {
            return [$actuals, $unmapped];
        }

        // Map each QB column index -> our week index by matching the column's
        // StartDate to the week it falls in.
        $colWeek = $this->mapQbColumnsToWeeks($report['Columns']['Column'], $weeks);
        $mapping = $this->cashFlowQbMapping();

        // Resolve a QB account name + section to one of our line items.
        $resolve = function ($sectionKey, $name) use ($mapping) {
            $name = strtolower($name);
            foreach (($mapping[$sectionKey] ?? []) as $label => $needles) {
                foreach ($needles as $needle) {
                    if (strpos($name, strtolower($needle)) !== false) {
                        return $label;
                    }
                }
            }
            return null;
        };

        $walk = function ($rows, $sectionKey) use (
            &$walk, &$actuals, &$unmapped, $colWeek, $resolve, $weekCount
        ) {
            foreach ($rows as $r) {
                // Section grouping: QB tags top groups with a "group" key.
                $group = $r['group'] ?? null;
                $childSection = $sectionKey;
                if ($group === 'Income') $childSection = 'revenue';
                elseif ($group === 'COGS') $childSection = 'cogs';
                elseif ($group === 'Expenses') $childSection = 'opex';

                if (($r['type'] ?? '') === 'Data' && $childSection !== null && !empty($r['ColData'])) {
                    $name = $r['ColData'][0]['value'] ?? '';
                    $label = $resolve($childSection, $name);
                    $sign = $childSection === 'revenue' ? 1.0 : -1.0;
                    foreach ($r['ColData'] as $ci => $cd) {
                        if ($ci === 0 || !isset($colWeek[$ci])) {
                            continue; // account-name col or the Total col
                        }
                        $wi = $colWeek[$ci];
                        if ($wi < 0 || $wi >= $weekCount) {
                            continue;
                        }
                        $val = isset($cd['value']) && $cd['value'] !== ''
                            ? (float) str_replace(',', '', $cd['value']) : 0.0;
                        if ($val == 0) {
                            continue;
                        }
                        $amt = $sign * abs($val);
                        if ($label !== null) {
                            $actuals[$childSection][$label][$wi] += $amt;
                        } else {
                            $unmapped[$childSection][$wi] += $amt;
                        }
                    }
                }

                if (!empty($r['Rows']['Row'])) {
                    $walk($r['Rows']['Row'], $childSection);
                }
            }
        };
        $walk($report['Rows']['Row'], null);

        return [$actuals, $unmapped];
    }

    /**
     * Match each QB report column to a budget week index. QB columns carry
     * MetaData with ColKey "StartDate"/"EndDate"; we place a column in the
     * week whose [start,end] contains its start date. Columns without dates
     * (account-name col, Total col) map to -1 and are skipped by callers.
     */
    protected function mapQbColumnsToWeeks($columns, $weeks)
    {
        $colWeek = [];
        foreach ($columns as $ci => $col) {
            $start = null;
            foreach (($col['MetaData'] ?? []) as $meta) {
                if (($meta['Name'] ?? '') === 'StartDate') {
                    $start = $meta['Value'] ?? null;
                }
            }
            if ($start === null) {
                $colWeek[$ci] = -1;
                continue;
            }
            $colWeek[$ci] = $this->weekIndexForDate($start, $weeks);
        }
        return $colWeek;
    }

    /**
     * Index of the week whose [start,end] contains $date, else nearest by
     * start date (QB week boundaries can drift a day from the sheet's).
     */
    protected function weekIndexForDate($date, $weeks)
    {
        $d = strtotime($date);
        $best = -1;
        $bestDiff = PHP_INT_MAX;
        foreach ($weeks as $i => $w) {
            if (empty($w['start'])) {
                continue;
            }
            $ws = strtotime($w['start']);
            $we = !empty($w['end']) ? strtotime($w['end']) : $ws + 6 * 86400;
            if ($d >= $ws && $d <= $we) {
                return $i;
            }
            $diff = abs($d - $ws);
            if ($diff < $bestDiff) {
                $bestDiff = $diff;
                $best = $i;
            }
        }
        return $best;
    }

    /**
     * Assemble the render-ready grid: per-section line items with budget +
     * actual arrays, section subtotals, and the running opening/closing
     * balance rows (budget and actual roll forward week to week from the
     * same opening seed). Net = revenue + cogs + opex (costs already negative).
     */
    protected function buildCashFlowGrid($budget, $actuals, $unmapped, $weekCount)
    {
        $zero = array_fill(0, $weekCount, 0.0);
        $hasActuals = $actuals !== null;

        $out = ['sections' => [], 'has_actuals' => $hasActuals];

        $sectionSubtotalActual = [];

        foreach ($budget['sections'] as $sec) {
            $key = $sec['key'];
            $items = [];
            $subB = $zero;
            $subA = $zero;
            foreach ($sec['items'] as $item) {
                $b = array_pad(array_slice($item['budget'], 0, $weekCount), $weekCount, 0.0);
                $a = $hasActuals && isset($actuals[$key][$item['label']])
                    ? $actuals[$key][$item['label']] : $zero;
                for ($w = 0; $w < $weekCount; $w++) {
                    $subB[$w] += $b[$w];
                    $subA[$w] += $a[$w];
                }
                $items[] = ['label' => $item['label'], 'budget' => $b, 'actual' => $a];
            }
            // Catch-all for QB accounts that didn't match any line item.
            if ($hasActuals && !empty($unmapped[$key]) && array_sum($unmapped[$key]) != 0) {
                $u = $unmapped[$key];
                for ($w = 0; $w < $weekCount; $w++) {
                    $subA[$w] += $u[$w];
                }
                $items[] = ['label' => 'Other (QuickBooks, unmapped)', 'budget' => $zero, 'actual' => $u, 'is_unmapped' => true];
            }
            $sectionSubtotalActual[$key] = $subA;
            $out['sections'][] = [
                'key' => $key,
                'title' => $sec['title'],
                'items' => $items,
                'subtotal_budget' => $subB,
                'subtotal_actual' => $subA,
            ];
        }

        // Budget net + opening/closing balances come straight from Sarah's
        // sheet rows — never recomputed (see parser note).
        $pad = function ($arr) use ($weekCount) {
            $arr = is_array($arr) ? $arr : [];
            return array_pad(array_slice($arr, 0, $weekCount), $weekCount, 0.0);
        };
        $netB   = $pad($budget['net_row'] ?? null);
        $openB  = $pad($budget['opening_row'] ?? null);
        $closeB = $pad($budget['closing_row'] ?? null);

        // Actual net = sum of section actuals; actual balances roll forward
        // from the same week-1 opening seed.
        $netA = $zero;
        foreach (['revenue', 'cogs', 'opex'] as $key) {
            for ($w = 0; $w < $weekCount; $w++) {
                $netA[$w] += $sectionSubtotalActual[$key][$w] ?? 0;
            }
        }
        $seed = (float) ($budget['opening_seed'] ?? ($openB[0] ?? 0));
        $openA = $zero; $closeA = $zero;
        $runA = $seed;
        for ($w = 0; $w < $weekCount; $w++) {
            $openA[$w] = $runA;
            $runA += $netA[$w];
            $closeA[$w] = $runA;
        }

        $out['net_budget'] = $netB;
        $out['net_actual'] = $netA;
        $out['opening_budget'] = $openB;
        $out['opening_actual'] = $openA;
        $out['closing_budget'] = $closeB;
        $out['closing_actual'] = $closeA;
        $out['opening_seed'] = $seed;

        return $out;
    }

    /**
     * Add $0 rows for Discogs, eBay, and nivessa.com channels when no live
     * data row exists yet, with labels that point to Business Settings,
     * /admin/ebay-seller, or .env / API status.
     */
    protected function mergeSalesByChannelPlaceholderRows($business_id, array &$rows, $dgs, $eby)
    {
        if (!isset($rows['online|discogs'])) {
            try {
                $svc = new \App\Services\DiscogsService($business_id);
                if (!$svc->isConfigured()) {
                    $rows['online|discogs'] = [
                        'label'           => 'Discogs — add API token (Business Settings → Integrations)',
                        'channel'         => 'discogs',
                        'location_id'     => null,
                        'revenue'         => 0.0,
                        'revenue_exc_tax' => 0.0,
                        'cnt'             => 0,
                        'gross_profit'    => 0.0,
                        'cost_unknown'    => true,
                        'integration_placeholder' => true,
                    ];
                } else {
                    $rows['online|discogs'] = [
                        'label'           => 'Discogs — API error (see Channel fetch status)',
                        'channel'         => 'discogs',
                        'location_id'     => null,
                        'revenue'         => 0.0,
                        'revenue_exc_tax' => 0.0,
                        'cnt'             => 0,
                        'gross_profit'    => 0.0,
                        'cost_unknown'    => true,
                        'integration_placeholder' => true,
                    ];
                }
            } catch (\Exception $e) {
                // leave row absent
            }
        }

        if (!isset($rows['online|ebay'])) {
            try {
                $svc = new \App\Services\EbayService($business_id);
                if (!$svc->isConfigured()) {
                    $rows['online|ebay'] = [
                        'label'           => 'eBay — add App ID, Cert ID, Dev ID (Business Settings)',
                        'channel'         => 'ebay',
                        'location_id'     => null,
                        'revenue'         => 0.0,
                        'revenue_exc_tax' => 0.0,
                        'cnt'             => 0,
                        'gross_profit'    => 0.0,
                        'cost_unknown'    => true,
                        'integration_placeholder' => true,
                    ];
                } elseif (!$svc->isSellerConnected()) {
                    $rows['online|ebay'] = [
                        'label'           => 'eBay — connect seller (/admin/ebay-seller)',
                        'channel'         => 'ebay',
                        'location_id'     => null,
                        'revenue'         => 0.0,
                        'revenue_exc_tax' => 0.0,
                        'cnt'             => 0,
                        'gross_profit'    => 0.0,
                        'cost_unknown'    => true,
                        'integration_placeholder' => true,
                    ];
                } else {
                    $rows['online|ebay'] = [
                        'label'           => 'eBay — API error (see Channel fetch status)',
                        'channel'         => 'ebay',
                        'location_id'     => null,
                        'revenue'         => 0.0,
                        'revenue_exc_tax' => 0.0,
                        'cnt'             => 0,
                        'gross_profit'    => 0.0,
                        'cost_unknown'    => true,
                        'integration_placeholder' => true,
                    ];
                }
            } catch (\Exception $e) {
            }
        }

        $webKey = trim((string) config('nivessa.website_api_key', ''));
        if (!isset($rows['web|space_rental'])) {
            $rows['web|space_rental'] = [
                'label'           => $webKey === ''
                    ? 'Space Rentals — set NIVESSA_WEBSITE_API_KEY on the ERP server'
                    : 'Space Rentals — nivessa.com API failed (see Channel fetch status)',
                'channel'         => 'space_rental',
                'location_id'     => null,
                'revenue'         => 0.0,
                'revenue_exc_tax' => 0.0,
                'cnt'             => 0,
                'gross_profit'    => 0.0,
                'cost_unknown'    => true,
                'integration_placeholder' => true,
            ];
        }
        if (!isset($rows['web|web_ship'])) {
            $rows['web|web_ship'] = [
                'label'           => $webKey === ''
                    ? 'Website Shipping Orders (set NIVESSA_WEBSITE_API_KEY on the ERP server)'
                    : 'Website Shipping Orders (API failed — see Channel fetch status)',
                'channel'         => 'web_ship',
                'location_id'     => null,
                'revenue'         => 0.0,
                'revenue_exc_tax' => 0.0,
                'cnt'             => 0,
                'gross_profit'    => 0.0,
                'cost_unknown'    => true,
                'integration_placeholder' => true,
            ];
        }
        if (!isset($rows['web|web_pickup'])) {
            $rows['web|web_pickup'] = [
                'label'           => $webKey === ''
                    ? 'Website Pickup Orders (set NIVESSA_WEBSITE_API_KEY on the ERP server)'
                    : 'Website Pickup Orders (API failed — see Channel fetch status)',
                'channel'         => 'web_pickup',
                'location_id'     => null,
                'revenue'         => 0.0,
                'revenue_exc_tax' => 0.0,
                'cnt'             => 0,
                'gross_profit'    => 0.0,
                'cost_unknown'    => true,
                'integration_placeholder' => true,
            ];
        }
    }

    /**
     * Masked one-liner so admins can confirm the loaded key matches .env
     * (start/end only — never log the full secret).
     */
    protected function describeNivessaWebsiteApiKeyForDiagnostics(string $key, string $baseUrl): string
    {
        $len = strlen($key);
        if ($len < 8) {
            return 'nivessa: ERP sends X-API-Key to ' . $baseUrl . ' (length ' . $len . ' — check NIVESSA_WEBSITE_API_KEY in .env).';
        }

        $edgeLen = $len >= 32 ? 8 : 4;
        $starts = substr($key, 0, $edgeLen);
        $ends = substr($key, -$edgeLen);

        return 'nivessa key — ERP sends X-API-Key on GET ' . $baseUrl . ' (length ' . $len . '). '
            . 'Compare to .env: should start ' . $starts . ' and end ' . $ends . '. '
            . 'If mismatch vs .env, run php artisan config:clear. HTTP 401 = nivessa rejected the key or expects different auth.';
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
     * Config: `config/nivessa.php` (from NIVESSA_WEBSITE_API_* in .env).
     * Use config(), not env() in app code, so values survive
     * `php artisan config:cache` — after editing .env run config:clear or
     * re-cache. Header sent: X-API-Key.
     */
    protected function fetchWebsiteChannelTotals($start_date, $end_date)
    {
        $base = rtrim((string) config('nivessa.website_api_url', 'https://nivessa.com'), '/');
        $rawKey = config('nivessa.website_api_key');
        $key = trim((string) ($rawKey ?? ''));
        if ($key === '') {
            $this->setDiag('website', 'NIVESSA_WEBSITE_API_KEY empty — set in .env and config/nivessa.php, then php artisan config:clear if cached config hides it.');
            return [];
        }
        if (is_string($rawKey) && $rawKey !== '' && trim($rawKey) !== $rawKey) {
            $this->setDiag('website_key_trim', 'NIVESSA_WEBSITE_API_KEY had leading/trailing whitespace; it was trimmed before sending X-API-Key.');
        }
        $this->setDiag('website_key', $this->describeNivessaWebsiteApiKeyForDiagnostics($key, $base));

        $rows = [];

        // Space Rentals — venue bookings.
        $bookingsUrl = $base . '/api/v1/bookings/sales-totals?start_date=' . urlencode($start_date) . '&end_date=' . urlencode($end_date);
        $bookingsDet = $this->httpGetJsonDetailed($bookingsUrl, $key, 10);
        $bookings = $bookingsDet['decoded'];
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
            $this->setDiag('website_bookings', "Space Rentals: pulled {$cnt} booking(s) from nivessa.com.");
        } else {
            $this->setDiag(
                'website_bookings',
                'Space Rentals /api/v1/bookings/sales-totals — ' . $this->formatNivessaWebsiteApiFailure($bookingsDet)
            );
        }

        // Web sales — shipping + pickup. One call returns both buckets.
        $ordersUrl = $base . '/api/v1/order/sales-totals?start_date=' . urlencode($start_date) . '&end_date=' . urlencode($end_date);
        $ordersDet = $this->httpGetJsonDetailed($ordersUrl, $key, 10);
        $orders = $ordersDet['decoded'];
        if (!empty($orders) && !empty($orders['success'])) {
            $bm = (isset($orders['byMethod']) && is_array($orders['byMethod']))
                ? $orders['byMethod']
                : [];
            if (empty($bm)) {
                $this->setDiag('website_orders', 'nivessa.com orders: API OK but no byMethod breakdown — showing $0 for shipping & pickup.');
            }
            $shipping = $bm['shipping'] ?? ['totalRevenue' => 0, 'count' => 0];
            $pickup   = $bm['pickup']   ?? ['totalRevenue' => 0, 'count' => 0];
            $orderCnt = (int)($shipping['count'] ?? 0) + (int)($pickup['count'] ?? 0);
            if (!empty($bm)) {
                $this->setDiag('website_orders', "nivessa.com orders: pulled {$orderCnt} order(s).");
            }

            $rows[] = [
                'key' => 'web|web_ship',
                'row' => [
                    'label'           => 'Website Shipping Orders',
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
                    'label'           => 'Website Pickup Orders',
                    'channel'         => 'web_pickup',
                    'location_id'     => null,
                    'revenue'         => (float)$pickup['totalRevenue'],
                    'revenue_exc_tax' => (float)$pickup['totalRevenue'],
                    'cnt'             => (int)$pickup['count'],
                    'gross_profit'    => 0.0,
                    'cost_unknown'    => true,
                ],
            ];
        } else {
            $this->setDiag(
                'website_orders',
                'nivessa.com orders /api/v1/order/sales-totals — ' . $this->formatNivessaWebsiteApiFailure($ordersDet)
            );
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
     *
     * Also writes a one-line diagnostic to $this->channelDiagnostics
     * (initialised by the caller) so the view can surface why a fetch
     * came back empty without the user having to grep server logs.
     */
    protected function fetchDiscogsChannelTotals($business_id, $start_date, $end_date)
    {
        try {
            $service = new \App\Services\DiscogsService($business_id);
            if (!$service->isConfigured()) {
                $this->setDiag('discogs', 'Discogs API token not configured. Add it in Business Settings → Integrations.');
                return null;
            }
        } catch (\Exception $e) {
            $this->setDiag('discogs', 'Discogs service error: ' . $e->getMessage());
            return null;
        }

        $orders = $this->fetchDiscogsOrdersRaw($business_id, $start_date, $end_date);
        if ($orders === null) {
            $this->setDiag('discogs', 'Discogs API call failed (token rejected or network error).');
            return null;
        }
        $revenue = 0.0;
        foreach ($orders as $o) {
            $revenue += isset($o['total']['value']) ? (float)$o['total']['value'] : 0.0;
        }
        $cnt = count($orders);

        // No per-business fallback config — when an item doesn't match local
        // inventory, fall back to the existing CostPriceRules table by the
        // Discogs release format (Vinyl → Used Vinyl, CD → Used CD, etc.).
        // Reuses the rules already configured at /admin/cost-price-rules so
        // there's a single source of truth for fallback costs.

        // Cost-of-goods: walk order line items and match against local cost.
        // Two-tier match:
        //   1. Discogs listing_id → products.discogs_listing_id
        //      (works for items listed to Discogs through the ERP).
        //   2. Discogs release_id → products.discogs_release_id
        //      (catches items where we own the release in local inventory
        //      even though they were listed directly on Discogs).
        // First match wins; tier-2 is the fallback. Items missing both get
        // a flat estimated cost if discogs.fallback_item_cost is configured,
        // otherwise they contribute revenue without COGS.
        $cogs = 0.0;
        $matched_items = 0;
        $unmatched_items = 0;
        $matched_revenue = 0.0;
        $matched_by_listing = 0;
        $matched_by_release = 0;
        $matched_by_fallback = 0;
        $listing_ids = [];
        $release_ids = [];
        foreach ($orders as $o) {
            foreach (($o['items'] ?? []) as $it) {
                if (!empty($it['id'])) $listing_ids[] = (string) $it['id'];
                if (!empty($it['release']['id'])) $release_ids[] = (int) $it['release']['id'];
            }
        }
        $cost_by_listing = [];
        if (!empty($listing_ids)) {
            $cost_by_listing = DB::table('products as p')
                ->join('variations as v', 'v.product_id', '=', 'p.id')
                ->where('p.business_id', $business_id)
                ->whereIn('p.discogs_listing_id', array_unique($listing_ids))
                ->selectRaw('p.discogs_listing_id, MIN(v.default_purchase_price) as cost')
                ->groupBy('p.discogs_listing_id')
                ->pluck('cost', 'discogs_listing_id')
                ->toArray();
        }
        // Release-id match is gated on the migration being run. If the
        // column isn't there yet, silently skip the fallback so the page
        // renders (listing-id match still works on its own).
        $cost_by_release = [];
        if (!empty($release_ids) && \Schema::hasColumn('products', 'discogs_release_id')) {
            $cost_by_release = DB::table('products as p')
                ->join('variations as v', 'v.product_id', '=', 'p.id')
                ->where('p.business_id', $business_id)
                ->whereIn('p.discogs_release_id', array_unique($release_ids))
                ->selectRaw('p.discogs_release_id, MIN(v.default_purchase_price) as cost')
                ->groupBy('p.discogs_release_id')
                ->pluck('cost', 'discogs_release_id')
                ->toArray();
        }
        foreach ($orders as $o) {
            foreach (($o['items'] ?? []) as $it) {
                $lid = isset($it['id']) ? (string) $it['id'] : '';
                $rid = isset($it['release']['id']) ? (int) $it['release']['id'] : 0;
                $price = isset($it['price']['value']) ? (float) $it['price']['value'] : 0.0;
                if ($lid !== '' && isset($cost_by_listing[$lid])) {
                    $cogs += (float) $cost_by_listing[$lid];
                    $matched_revenue += $price;
                    $matched_items++;
                    $matched_by_listing++;
                } elseif ($rid > 0 && isset($cost_by_release[$rid])) {
                    $cogs += (float) $cost_by_release[$rid];
                    $matched_revenue += $price;
                    $matched_items++;
                    $matched_by_release++;
                } else {
                    // Format-based fallback from /admin/cost-price-rules.
                    $format_str = '';
                    if (!empty($it['release']['format'])) {
                        $format_str = is_array($it['release']['format'])
                            ? implode(' ', $it['release']['format'])
                            : (string) $it['release']['format'];
                    }
                    $fallback = $this->discogsFormatFallbackCost($format_str);
                    if ($fallback > 0) {
                        $cogs += $fallback;
                        $matched_revenue += $price;
                        $matched_items++;
                        $matched_by_fallback++;
                    } else {
                        $unmatched_items++;
                    }
                }
            }
        }
        $gross_profit = $matched_revenue - $cogs;

        $this->setDiag('discogs', $cnt > 0
            ? "Discogs: pulled {$cnt} order(s) from API. Items matched: {$matched_items} ({$matched_by_listing} via listing, {$matched_by_release} via release, {$matched_by_fallback} via fallback); unmatched: {$unmatched_items}."
            : 'Discogs: API reachable but no orders in revenue statuses for this date range.');
        return [
            'revenue' => $revenue,
            'cnt' => $cnt,
            'cogs' => $cogs,
            'gross_profit' => $gross_profit,
            'matched_items' => $matched_items,
            'unmatched_items' => $unmatched_items,
            'matched_by_listing' => $matched_by_listing,
            'matched_by_release' => $matched_by_release,
            'matched_by_fallback' => $matched_by_fallback,
            'matched_revenue' => $matched_revenue,
        ];
    }

    /** Append a diagnostic line, keyed so callers can replace prior state. */
    protected function setDiag($key, $msg)
    {
        if (!isset($this->channelDiagnostics) || !is_array($this->channelDiagnostics)) {
            $this->channelDiagnostics = [];
        }
        $this->channelDiagnostics[$key] = $msg;
    }

    /**
     * Pull eBay order totals via Sell Fulfillment API. Requires a seller
     * refresh token (set up at /admin/ebay-seller). Returns null on any
     * config / auth / transport error; caller will simply omit the row.
     */
    protected function fetchEbayChannelTotals($business_id, $start_date, $end_date)
    {
        try {
            $service = new \App\Services\EbayService($business_id);
            if (!$service->isConfigured()) {
                $this->setDiag('ebay', 'eBay app credentials not set in Business Settings.');
                return null;
            }
            if (!$service->isSellerConnected()) {
                $this->setDiag('ebay', 'eBay seller account not connected. Visit /admin/ebay-seller to authorise.');
                return null;
            }

            // eBay's filter format: ISO-8601 with .000Z millis required.
            $created_after  = $start_date . 'T00:00:00.000Z';
            $created_before = $end_date . 'T23:59:59.999Z';
            $revenue = 0.0;
            $cnt = 0;
            $offset = 0;
            $limit = 200;
            $max_iterations = 25; // 5000-order safety cap per render
            for ($i = 0; $i < $max_iterations; $i++) {
                $resp = $service->fetchOrders($created_after, $created_before, $offset, $limit);
                if (!empty($resp['error'])) {
                    $this->setDiag('ebay', 'eBay API call failed: ' . $resp['error']);
                    return null;
                }
                $orders = $resp['orders'] ?? [];
                foreach ($orders as $o) {
                    // Revenue recognition: anything where buyer paid.
                    // eBay's orderPaymentStatus enum: PAID, PARTIALLY_REFUNDED,
                    // FULLY_REFUNDED, PENDING, FAILED. PAID + PARTIALLY_REFUNDED
                    // count as revenue; FULLY_REFUNDED nets to zero anyway.
                    $pay_status = $o['orderPaymentStatus'] ?? '';
                    if (!in_array($pay_status, ['PAID', 'PARTIALLY_REFUNDED'], true)) {
                        continue;
                    }
                    $revenue += isset($o['pricingSummary']['total']['value'])
                        ? (float)$o['pricingSummary']['total']['value'] : 0.0;
                    $cnt++;
                }
                $total = (int)($resp['total'] ?? 0);
                $offset += $limit;
                if ($offset >= $total) break;
            }

            $this->setDiag('ebay', $cnt > 0
                ? "eBay: pulled {$cnt} order(s) from Sell API."
                : 'eBay: API reachable but no PAID orders for this date range.');
            return ['revenue' => $revenue, 'cnt' => $cnt];
        } catch (\Exception $e) {
            $this->setDiag('ebay', 'eBay service error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * GET JSON from the nivessa website API and return transport + parse
     * metadata for diagnostics (HTTP status, cURL error, decoded JSON,
     * parse errors, raw body for short preview).
     *
     * @return array{http_code:int,curl_error:string,body:string,decoded:?array,json_error:?string}
     */
    protected function httpGetJsonDetailed($url, $api_key, $timeoutSeconds = 8): array
    {
        $det = [
            'http_code' => 0,
            'curl_error' => '',
            'body' => '',
            'decoded' => null,
            'json_error' => null,
        ];
        try {
            $timeoutSeconds = max(3, min(30, (int) $timeoutSeconds));
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeoutSeconds));
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'X-API-Key: ' . $api_key,
                'Accept: application/json',
                'User-Agent: NivessaERP/1.0 +https://playlist.nivessa.com',
            ]);
            $body = curl_exec($ch);
            $det['http_code'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $det['curl_error'] = (string) curl_error($ch);
            curl_close($ch);

            $det['body'] = is_string($body) ? $body : '';
            if ($det['curl_error'] !== '') {
                return $det;
            }

            if ($det['body'] === '') {
                return $det;
            }

            $decoded = json_decode($det['body'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $det['json_error'] = json_last_error_msg();

                return $det;
            }
            $det['decoded'] = is_array($decoded) ? $decoded : null;
            if ($det['decoded'] === null) {
                $det['json_error'] = 'JSON root is not an object or array';
            }

            return $det;
        } catch (\Exception $e) {
            $det['curl_error'] = $e->getMessage();

            return $det;
        }
    }

    /**
     * One-line explanation for Sales-by-Channel when a nivessa.com API
     * call did not yield success=true (includes HTTP code, API message
     * fields, JSON parse errors, and a short raw body preview).
     */
    protected function formatNivessaWebsiteApiFailure(array $det): string
    {
        $curl = isset($det['curl_error']) ? trim((string) $det['curl_error']) : '';
        if ($curl !== '') {
            return 'cURL: ' . $curl;
        }

        $code = (int) ($det['http_code'] ?? 0);
        $dec = isset($det['decoded']) && is_array($det['decoded']) ? $det['decoded'] : null;

        $apiMsg = '';
        if ($dec !== null) {
            foreach (['message', 'error', 'msg', 'detail'] as $k) {
                if (!empty($dec[$k]) && is_string($dec[$k])) {
                    $apiMsg = trim($dec[$k]);
                    break;
                }
            }
            if ($apiMsg === '' && !empty($dec['errors']) && is_array($dec['errors'])) {
                $flat = [];
                foreach ($dec['errors'] as $e) {
                    if (is_string($e)) {
                        $flat[] = $e;
                    } elseif (is_array($e) && isset($e['message'])) {
                        $flat[] = (string) $e['message'];
                    }
                    if (count($flat) >= 4) {
                        break;
                    }
                }
                $apiMsg = implode('; ', $flat);
            }
            if ($apiMsg === '' && array_key_exists('success', $dec) && $dec['success'] === false) {
                $apiMsg = 'success=false (no message field in JSON)';
            }
            if ($apiMsg === '' && !array_key_exists('success', $dec)) {
                $apiMsg = 'response JSON has no success field';
            }
        }

        $parts = [];
        if ($code > 0) {
            $parts[] = "HTTP {$code}";
        }
        if ($apiMsg !== '') {
            $parts[] = $apiMsg;
        }
        if (!empty($det['json_error'])) {
            $parts[] = 'parse: ' . $det['json_error'];
        }

        $out = implode(' — ', array_filter($parts));
        if ($out === '') {
            $out = 'HTTP ' . ($code > 0 ? (string) $code : '(unknown)');
        }

        $preview = $this->truncateDiagnosticText((string) ($det['body'] ?? ''), 300);
        $appendBody = $preview !== ''
            && ($code !== 200 || !empty($det['json_error']) || $dec === null
                || (is_array($dec) && array_key_exists('success', $dec) && $dec['success'] === false && $apiMsg === ''));
        if ($appendBody) {
            $out .= ' — body: ' . $preview;
        }

        return $out;
    }

    /**
     * Collapse whitespace and cap length for safe display in admin UI.
     */
    protected function truncateDiagnosticText(string $s, int $maxLen): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));
        if ($s === '') {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($s) > $maxLen) {
                return mb_substr($s, 0, $maxLen) . '…';
            }

            return $s;
        }
        if (strlen($s) > $maxLen) {
            return substr($s, 0, $maxLen) . '…';
        }

        return $s;
    }

    /**
     * GET a JSON endpoint with a bounded timeout and decode the body.
     * Returns null on any error — caller is expected to skip silently.
     *
     * @param int $timeoutSeconds Total cURL timeout (connect uses min(5, timeout)).
     */
    protected function httpGetJson($url, $api_key, $timeoutSeconds = 8)
    {
        $det = $this->httpGetJsonDetailed($url, $api_key, $timeoutSeconds);
        if ($det['http_code'] === 200 && $det['decoded'] !== null && $det['curl_error'] === '') {
            return $det['decoded'];
        }

        return null;
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
     * Map a Discogs release format string to a fallback cost, sourced from
     * /admin/cost-price-rules. Vinyl → Used Vinyl, CD → Used CD, etc. Used
     * for items sold on Discogs that aren't in local inventory.
     */
    protected function discogsFormatFallbackCost($formatStr)
    {
        $s = strtolower((string) $formatStr);
        if ($s === '') return (float) (\App\Http\Controllers\CostPriceRulesController::RULES[1]['cost'] ?? 0); // default Used Vinyl
        if (strpos($s, 'cd') !== false)        return 0.10;
        if (strpos($s, 'cassette') !== false)  return 0.30;
        if (strpos($s, 'vhs') !== false)       return 0.10;
        if (strpos($s, '8 track') !== false || strpos($s, '8-track') !== false) return 0.25;
        if (strpos($s, '7"') !== false || strpos($s, '45 rpm') !== false) return 0.15;
        // Vinyl or anything else falls through to Used Vinyl.
        return 0.35;
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

        // For Discogs / eBay, also live-fetch from their respective APIs.
        // Discogs: line items now matched to local cost via discogs_listing_id
        // so external orders contribute to gross_profit when matched.
        // eBay: still header-level only (no line-item match yet).
        $external_revenue = 0.0;
        $external_cnt = 0;
        $external_cogs_revenue = 0.0; // revenue that has cost matched
        $discogs_match_info = null;
        if ($channel === 'discogs') {
            $dgs = $this->fetchDiscogsChannelTotals($business_id, $start_date, $end_date);
            if ($dgs !== null) {
                $external_revenue = $dgs['revenue'];
                $external_cnt = $dgs['cnt'];
                $revenue += $external_revenue;
                $cnt += $external_cnt;
                $gross_profit += (float) ($dgs['gross_profit'] ?? 0);
                $external_cogs_revenue = (float) ($dgs['matched_revenue'] ?? 0);
                // Per-tier match info for the view's diagnostic panel.
                $discogs_match_info = [
                    'matched_items'       => (int) ($dgs['matched_items'] ?? 0),
                    'unmatched_items'     => (int) ($dgs['unmatched_items'] ?? 0),
                    'matched_by_listing'  => (int) ($dgs['matched_by_listing'] ?? 0),
                    'matched_by_release'  => (int) ($dgs['matched_by_release'] ?? 0),
                    'matched_by_fallback' => (int) ($dgs['matched_by_fallback'] ?? 0),
                    // Inventory coverage so it's obvious why match rate is what it is.
                    'inv_with_listing_id' => (int) \DB::table('products')
                        ->where('business_id', $business_id)
                        ->whereNotNull('discogs_listing_id')
                        ->count(),
                    'inv_with_release_id' => \Schema::hasColumn('products', 'discogs_release_id')
                        ? (int) \DB::table('products')
                            ->where('business_id', $business_id)
                            ->whereNotNull('discogs_release_id')
                            ->count()
                        : null,
                ];
            }
        } elseif ($channel === 'ebay') {
            $eby = $this->fetchEbayChannelTotals($business_id, $start_date, $end_date);
            if ($eby !== null) {
                $external_revenue = $eby['revenue'];
                $external_cnt = $eby['cnt'];
                $revenue += $external_revenue;
                $cnt += $external_cnt;
            }
        }

        // Margin reflects revenue where we have cost basis (POS-side rows +
        // matched API-side items). Unmatched API-side revenue is excluded
        // from the denominator so the margin % isn't artificially dragged
        // toward 100%.
        $cost_known_revenue = ($revenue - $external_revenue) + $external_cogs_revenue;
        $gross_margin = $cost_known_revenue > 0 ? ($gross_profit / $cost_known_revenue) * 100 : 0;

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
            'start_date', 'end_date',
            'discogs_match_info'
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
    /**
     * Window helpers for clover_payments queries when paid_at storage
     * is mixed-TZ. The cleanest filter is on Clover's own createdTime
     * (UTC unix-ms in raw_payload — always correct), so this returns
     * start_ms / end_ms in addition to the legacy IST + LA paid_at
     * windows. Use the createdTime predicate in SQL via JSON_EXTRACT;
     * fall back to OR-of-windows on paid_at when JSON_EXTRACT is
     * unavailable.
     *
     * shift_sec stays as the IST→LA offset for the date-grouping
     * fallback. la_shift_sec=0 since LA-stored values need no shift.
     */
    private function cloverPaidAtIstWindow(string $startLa, string $endLa): array
    {
        $startCarbonLa = \Carbon\Carbon::parse($startLa, 'America/Los_Angeles')->startOfDay();
        $endCarbonLa   = \Carbon\Carbon::parse($endLa, 'America/Los_Angeles')->endOfDay();
        return [
            'start_ist'    => $startCarbonLa->copy()->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
            'end_ist'      => $endCarbonLa->copy()->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s'),
            'start_la'     => $startCarbonLa->format('Y-m-d H:i:s'),
            'end_la'       => $endCarbonLa->format('Y-m-d H:i:s'),
            'start_ms'     => $startCarbonLa->valueOf(),
            'end_ms'       => $endCarbonLa->valueOf(),
            'la_offset_sec'=> $startCarbonLa->offset,
            'shift_sec'    => 19800 - $startCarbonLa->offset,
            'la_shift_sec' => 0,
        ];
    }

    /**
     * SQL predicate string for "this clover_payments row was created
     * within $win's LA date range". Uses JSON_EXTRACT on raw_payload
     * for TZ-unambiguous filtering by Clover's createdTime; falls
     * back to OR-of-paid_at-windows when createdTime is missing.
     */
    private function cloverCreatedInWindowSql(array $win, string $cpAlias = ''): string
    {
        $p = $cpAlias ? "{$cpAlias}." : '';
        $startMs = (int) $win['start_ms'];
        $endMs   = (int) $win['end_ms'];
        // "\$.createdTime" — escape the $ so PHP doesn't try to interpolate;
        // we want the literal JSON path $.createdTime sent to MySQL.
        $path = "\$.createdTime";
        return "(
            JSON_EXTRACT({$p}raw_payload, '{$path}') IS NOT NULL
            AND CAST(JSON_UNQUOTE(JSON_EXTRACT({$p}raw_payload, '{$path}')) AS UNSIGNED)
                BETWEEN {$startMs} AND {$endMs}
        )";
    }

    /**
     * SQL expression that yields the LA date for a row — uses
     * createdTime when present, otherwise the IST-vs-LA shift
     * heuristic. Suitable for use in SELECT / GROUP BY.
     */
    private function cloverLaDateSql(array $win, string $cpAlias = ''): string
    {
        $p = $cpAlias ? "{$cpAlias}." : '';
        $laOff = (int) $win['la_offset_sec'];
        $path = "\$.createdTime";
        // FROM_UNIXTIME interprets in server TZ; UTC_TIMESTAMP() − NOW()
        // gives that offset. The trick: take createdTime/1000 (UTC sec),
        // add LA offset (negative for PT), subtract the server's own
        // UTC offset. The result is a unix-sec that FROM_UNIXTIME will
        // render as the LA-local datetime regardless of server TZ.
        return "DATE(FROM_UNIXTIME(
            CAST(JSON_UNQUOTE(JSON_EXTRACT({$p}raw_payload, '{$path}')) AS UNSIGNED) / 1000
            + {$laOff}
            - (UNIX_TIMESTAMP() - UNIX_TIMESTAMP(UTC_TIMESTAMP()))
        ))";
    }

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
        // Force single-day mode (Sarah 2026-05-05: this is a daily-cash
        // reconciliation flow; multi-day rollups blur the picture). The
        // prev/next/today nav handles moving between days. URL is still
        // honored — a deep link with explicit start/end gets coerced to
        // a single day rather than rejected.
        $start = $request->input('start_date') ?: \Carbon::today()->format('Y-m-d');
        $end   = $start;
        $location_id = $request->input('location_id');

        $is_single_day = true;
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
        // Filter on createdTime (TZ-unambiguous) and derive LA day the
        // same way. OR-of-paid_at-windows was double-counting yesterday-
        // evening rows whose IST strings literally fell in today's LA
        // window range.
        $payWin = $this->cloverPaidAtIstWindow($start, $end);
        $cloverQuery = \DB::table('clover_payments')
            ->where('business_id', $business_id)
            ->where(function ($q) {
                $q->whereNull('result')->orWhere('result', 'SUCCESS')->orWhere('result', 'APPROVED');
            })
            ->whereRaw($this->cloverCreatedInWindowSql($payWin));
        if (!empty($location_id)) {
            $cloverQuery->where(function ($q) use ($location_id) {
                $q->where('location_id', $location_id)->orWhereNull('location_id');
            });
        }
        $laDayExpr = $this->cloverLaDateSql($payWin);
        $clover_rows_raw = $cloverQuery
            ->selectRaw("{$laDayExpr} as day, COALESCE(location_id, 0) as loc_key,
                COUNT(*) as clover_count,
                COALESCE(SUM(amount), 0) as clover_total")
            ->groupBy(DB::raw($laDayExpr), DB::raw('COALESCE(location_id, 0)'))
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

        // Safe drops per (day, location). Sums what cashiers reported moving
        // to the safe at close, grouped by the close day so the EOD row
        // shows what the safe should have received that day. Tolerant of
        // the column not yet existing — falls back to an empty map so the
        // page renders cleanly before the install-safe-drop-column step has
        // been run.
        $safe_drop_by_day_loc = [];
        if (\Schema::hasColumn('cash_registers', 'safe_drop_amount')) {
            $safeQuery = \DB::table('cash_registers')
                ->where('business_id', $business_id)
                ->where('status', 'close')
                ->whereNotNull('closed_at')
                ->whereDate('closed_at', '>=', $start)
                ->whereDate('closed_at', '<=', $end);
            if (!empty($location_id)) {
                $safeQuery->where('location_id', $location_id);
            }
            $safe_rows_raw = $safeQuery
                ->selectRaw("DATE(closed_at) as day, COALESCE(location_id, 0) as loc_key,
                    COALESCE(SUM(safe_drop_amount), 0) as safe_drop_total,
                    COUNT(*) as drop_count")
                ->groupBy(DB::raw('DATE(closed_at)'), DB::raw('COALESCE(location_id, 0)'))
                ->get();
            foreach ($safe_rows_raw as $sr) {
                $safe_drop_by_day_loc[$sr->day][(int) $sr->loc_key] = $sr;
            }
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
            'safe_drop' => 0.0,
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

            // Pull the matching cash-register safe-drop total. Same NULL-
            // location fallback the Clover/Batch sides use, claimed at most
            // once per day so multi-location days don't double-count.
            $safeDrop = null;
            if (isset($safe_drop_by_day_loc[$day][$locId]) && empty($claimed[$day]['s' . $locId])) {
                $safeDrop = $safe_drop_by_day_loc[$day][$locId];
                $claimed[$day]['s' . $locId] = true;
            } elseif (isset($safe_drop_by_day_loc[$day][0]) && empty($claimed[$day]['s0'])) {
                $safeDrop = $safe_drop_by_day_loc[$day][0];
                $claimed[$day]['s0'] = true;
            }
            $safeDropTotal = (float) ($safeDrop->safe_drop_total ?? 0);
            $safeDropCount = (int) ($safeDrop->drop_count ?? 0);

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
                'safe_drop_total' => $safeDropTotal,
                'safe_drop_count' => $safeDropCount,
            ];
            $grand['erp'] += $erpTotal;
            $grand['clover'] += $cloverTotal;
            $grand['batch'] += $batchTotal;
            $grand['deposit'] += $depositTotal;
            $grand['variance'] += $variance;
            $grand['deposit_variance'] += $depositVariance;
            $grand['safe_drop'] += $safeDropTotal;
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
                    'safe_drop_total' => 0.0,
                    'safe_drop_count' => 0,
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

        // Per-shift theft-prevention audit — kept around in case Sarah
        // wants it back later, but the blade currently hides it.
        $shift_audit = $this->cloverEodShiftAudit(
            $business_id, $start, $end, $location_id, $card_methods, $used_all_methods
        );

        // Sarah 2026-05-05: shift-audit cards are unusable for daily cash
        // reconciliation. She does it from a spreadsheet with one
        // per-employee summary at top and a side-by-side Clover↔ERP
        // payment list per store. cloverEodXlsxLayout produces exactly
        // that — wire it through and the blade renders her layout.
        $xlsx_layout = $this->cloverEodXlsxLayout(
            $business_id, $start, $end, $location_id, $card_methods, $used_all_methods
        );

        // Day totals banner — Sarah 2026-05-09: combine cash + card into a
        // single ERP Net Sales total and compare against Clover Net Sales.
        // ERP net = SUM(total_before_tax) at the transaction level; Clover
        // net = SUM(amount − tax_cents/100). Both are pre-tax, matching
        // Clover dashboard's "Net Sales" definition. No tender split —
        // the prior proportional allocation broke on discounted lines.
        //
        // Sarah 2026-05-11: exclude is_whatnot=1 from ERP totals. Whatnot
        // livestream sales ring in ERP for inventory but are paid through
        // Whatnot, not Clover — including them inflated Pico's ERP side
        // and made the Diff line look like a real discrepancy when it
        // wasn't. They're broken out on a separate line below for
        // transparency.
        // Sarah 2026-05-13: source of truth is /pos/recent-feed. Both
        // pages now call into the shared SellPosController::dayByStoreTotals()
        // helper so the per-store ERP / Clover / Whatnot / Diff numbers
        // are guaranteed to match. Gross-vs-gross (final_total vs Clover
        // amount); Whatnot is broken out so the in-store Diff isn't
        // polluted by inventory-only sales. Field names erp_net / clover
        // are kept for back-compat; they now carry gross values.
        $totalsByStore = \App\Http\Controllers\SellPosController::dayByStoreTotals(
            (int) $business_id,
            $start,
            $end,
            !empty($location_id) ? (int) $location_id : null,
            $business_locations
        );
        $erp_net_total      = $totalsByStore['erp_total'];
        $erp_count          = $totalsByStore['erp_count'];
        $whatnot_net_total  = $totalsByStore['whatnot_total'];
        $whatnot_count      = $totalsByStore['whatnot_count'];
        $clover_day_total   = $totalsByStore['clover_total'];
        $clover_day_count   = $totalsByStore['clover_count'];
        $day_by_store       = $totalsByStore['by_store'];

        // Unmatched-Clover drill-in: lists each Clover charge that didn't
        // pair to an ERP sale in the day. Explains the Diff column in
        // concrete terms (e.g. "one $37.71 charge with no matching ring").
        $matchedTxIds = array_keys($rows ?? []);
        // Sarah 2026-05-13: reuse the same LA-tightened Clover rows the
        // helper used to build the per-store Diff — so the per-charge
        // list and the totals are sourced from the same dataset.
        $cloverRowsForDiff = $totalsByStore['clover_rows_raw'];

        // Pull ERP sales for the day (matching pool — channel='in_store'
        // since Whatnot doesn't hit Clover).
        $erpSalesQ = \DB::table('transactions')
            ->where('business_id', $business_id)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->whereNull('import_source')
            ->where(function ($q) { $q->where('is_whatnot', 0)->orWhereNull('is_whatnot'); })
            ->whereDate('transaction_date', '>=', \Carbon\Carbon::parse($start)->subDay()->toDateString())
            ->whereDate('transaction_date', '<=', \Carbon\Carbon::parse($end)->addDay()->toDateString());
        if (!empty($location_id)) {
            $erpSalesQ->where('location_id', $location_id);
        }
        if (\Schema::hasColumn('transactions', 'channel')) {
            $erpSalesQ->where(function ($q) { $q->where('channel', 'in_store')->orWhereNull('channel'); });
        }
        $erpSales = $erpSalesQ->select('id', 'invoice_no', 'final_total', 'transaction_date', 'location_id')->get();

        $toCents = function ($x) { return (int) round(((float) $x) * 100); };

        // Sarah 2026-05-11: Nivessa doesn't accept tips, so the only
        // legitimate Clover-vs-ERP delta is tax rounding / bag-fee
        // mismatch (a few cents). Anything beyond ±$0.50 is a real
        // reconciliation issue and shouldn't auto-pair. Tight symmetric
        // tolerance, ±30 min.
        $candidates = [];
        foreach ($cloverRowsForDiff as $cIdx => $c) {
            $cCents = $toCents($c->amount);
            $cTs = \App\Http\Controllers\SellPosController::parseCloverPaidAtLa($c)->getTimestamp();
            $cLoc = $c->location_id !== null ? (int) $c->location_id : null;
            foreach ($erpSales as $s) {
                if ($cLoc !== null && (int) $s->location_id !== $cLoc) continue;
                $sCents = $toCents($s->final_total);
                $delta = abs($cCents - $sCents);
                if ($delta > 50) continue;
                $sTs = strtotime((string) $s->transaction_date);
                $td = abs($sTs - $cTs);
                if ($td > 1800) continue;
                $candidates[] = ['c' => $cIdx, 's' => $s->id, 'score' => $delta * 1000 + $td];
            }
        }
        usort($candidates, fn($a, $b) => $a['score'] <=> $b['score']);
        $matchedCBySale = [];
        $matchedSByC = [];
        $erpById = $erpSales->keyBy('id');
        foreach ($candidates as $cand) {
            if (isset($matchedSByC[$cand['c']])) continue;
            if (isset($matchedCBySale[$cand['s']])) continue;
            $matchedSByC[$cand['c']] = $cand['s'];
            $matchedCBySale[$cand['s']] = $cand['c'];
        }

        // Second pass — same-time obvious pairs regardless of amount
        // (Sarah 2026-05-11). Catches keying-error cases like the
        // 12:29pm Hollywood \$177.06 Clover / \$161.33 ERP pair that the
        // ±\$0.50 strict matcher rejects but are obviously the same sale.
        $sameTimeWindow = 120;
        foreach ($cloverRowsForDiff as $cIdx => $c) {
            if (isset($matchedSByC[$cIdx])) continue;
            $cTs = \App\Http\Controllers\SellPosController::parseCloverPaidAtLa($c)->getTimestamp();
            $cLoc = $c->location_id !== null ? (int) $c->location_id : null;
            $bestSid = null; $bestTd = PHP_INT_MAX;
            foreach ($erpSales as $s) {
                if (isset($matchedCBySale[$s->id])) continue;
                if ($cLoc !== null && (int) $s->location_id !== $cLoc) continue;
                $td = abs(strtotime((string) $s->transaction_date) - $cTs);
                if ($td > $sameTimeWindow) continue;
                if ($td < $bestTd) { $bestTd = $td; $bestSid = $s->id; }
            }
            if ($bestSid !== null) {
                $matchedSByC[$cIdx] = $bestSid;
                $matchedCBySale[$bestSid] = $cIdx;
            }
        }

        $cloverChargesForDiff = $cloverRowsForDiff->map(function ($r, $idx) use ($business_locations, $matchedSByC, $erpById) {
            $laTs = \App\Http\Controllers\SellPosController::parseCloverPaidAtLa($r);
            $matchedSaleId = $matchedSByC[$idx] ?? null;
            $matchedSale = $matchedSaleId ? $erpById->get($matchedSaleId) : null;
            return (object) [
                'clover_payment_id' => $r->clover_payment_id,
                'amount'    => (float) $r->amount,
                'net'       => round((float) $r->amount - ((float) ($r->tax_cents ?? 0)) / 100.0, 2),
                'paid_at'   => $laTs->format('Y-m-d g:i a'),
                'loc_name'  => $r->location_id && isset($business_locations[$r->location_id]) ? $business_locations[$r->location_id] : '(no loc)',
                'employee'  => $r->employee_name ?: null,
                'card'      => trim(strtoupper((string) $r->card_type) . ' ' . ($r->card_last4 ? '••' . $r->card_last4 : '')),
                'matched_erp_id'         => $matchedSaleId,
                'matched_erp_invoice_no' => $matchedSale ? $matchedSale->invoice_no : null,
                'matched_erp_amount'     => $matchedSale ? round((float) $matchedSale->final_total, 2) : null,
            ];
        });

        // Also surface ERP sales that didn't match any Clover charge —
        // the OTHER side of the reconciliation gap (sale rung but no
        // card swipe found, e.g. real cash sale that should have been on
        // Clover, or a sync miss).
        $erpSalesInRange = $erpSales->filter(function ($s) use ($start, $end) {
            $d = substr((string) $s->transaction_date, 0, 10);
            return $d >= $start && $d <= $end;
        });
        $unmatchedErp = $erpSalesInRange->reject(function ($s) use ($matchedCBySale) {
            return isset($matchedCBySale[$s->id]);
        })->map(function ($s) use ($business_locations) {
            return (object) [
                'id'         => $s->id,
                'invoice_no' => $s->invoice_no,
                'amount'     => round((float) $s->final_total, 2),
                'ts'         => (string) $s->transaction_date,
                'loc_name'   => $s->location_id && isset($business_locations[$s->location_id]) ? $business_locations[$s->location_id] : '(no loc)',
            ];
        })->values();

        $day_totals = [
            'erp_net'        => round($erp_net_total, 2),
            'erp_count'      => $erp_count,
            'clover'         => round($clover_day_total, 2),
            'clover_count'   => $clover_day_count,
            'diff'           => round($clover_day_total - $erp_net_total, 2),
            'whatnot_net'    => round($whatnot_net_total, 2),
            'whatnot_count'  => $whatnot_count,
            'by_store'           => array_values($day_by_store),
            'clover_charges'     => $cloverChargesForDiff,
            'unmatched_erp'      => $unmatchedErp,
        ];

        return view('report.clover_eod_reconciliation')->with(compact(
            'rows', 'grand', 'start', 'end', 'location_id', 'business_locations',
            'employee_breakdown_by_day', 'unknown_rows',
            'is_single_day', 'prev_day', 'next_day', 'today_str',
            'reconciliations', 'shift_audit', 'xlsx_layout', 'day_totals'
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
            // at the time — handled later). Mixed-TZ tolerant: paid_at
            // may be IST (cron-written) or LA (refresh-button-written).
            $shiftStartLaC = \Carbon\Carbon::parse($openedAt);
            $shiftEndLaC   = \Carbon\Carbon::parse($effectiveEnd);
            $shiftStartIst = $shiftStartLaC->copy()->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s');
            $shiftEndIst   = $shiftEndLaC->copy()->setTimezone('Asia/Kolkata')->format('Y-m-d H:i:s');
            $shiftStartLa  = $shiftStartLaC->format('Y-m-d H:i:s');
            $shiftEndLa    = $shiftEndLaC->format('Y-m-d H:i:s');
            // Filter via createdTime (TZ-unambiguous) — unix-ms range for
            // the shift window in LA. Falls back to OR-of-paid_at-windows
            // when raw_payload.createdTime is missing.
            $shiftStartMs = $shiftStartLaC->valueOf();
            $shiftEndMs   = $shiftEndLaC->valueOf();
            $cpQ = \DB::table('clover_payments as cp')
                ->where('cp.business_id', $business_id)
                ->where(function ($q) {
                    $q->whereNull('cp.result')->orWhere('cp.result', 'SUCCESS')->orWhere('cp.result', 'APPROVED');
                })
                ->whereRaw("(
                    JSON_EXTRACT(cp.raw_payload, '\$.createdTime') IS NOT NULL
                    AND CAST(JSON_UNQUOTE(JSON_EXTRACT(cp.raw_payload, '\$.createdTime')) AS UNSIGNED)
                        BETWEEN {$shiftStartMs} AND {$shiftEndMs}
                )");
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

        // Raw Clover payments. Mixed-TZ tolerant — OR both windows.
        $empWin = $this->cloverPaidAtIstWindow($start, $end);
        $cpQ = \DB::table('clover_payments as cp')
            ->leftJoin('business_locations as bl', 'cp.location_id', '=', 'bl.id')
            ->where('cp.business_id', $business_id)
            ->where(function ($q) {
                $q->whereNull('cp.result')->orWhere('cp.result', 'SUCCESS')->orWhere('cp.result', 'APPROVED');
            })
            ->whereRaw($this->cloverCreatedInWindowSql($empWin, 'cp'));
        if (!empty($location_id)) {
            $cpQ->where('cp.location_id', $location_id);
        }
        $empDayExpr = $this->cloverLaDateSql($empWin, 'cp');
        $cpRows = $cpQ->selectRaw("
                {$empDayExpr} as day,
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

        // Safe drops per (day, location) — sums what cashiers reported
        // moving to the safe at close. Tolerant of the column not existing
        // yet (the install-safe-drop-column installer might not have run).
        $safe_drops = [];
        if (\Schema::hasColumn('cash_registers', 'safe_drop_amount')) {
            $sdQ = \DB::table('cash_registers')
                ->where('business_id', $business_id)
                ->where('status', 'close')
                ->whereNotNull('closed_at')
                ->whereDate('closed_at', '>=', $start)
                ->whereDate('closed_at', '<=', $end);
            if (!empty($location_id)) {
                $sdQ->where('location_id', $location_id);
            }
            $sdRows = $sdQ
                ->selectRaw("DATE(closed_at) as day, COALESCE(location_id, 0) as loc_key,
                    COALESCE(SUM(safe_drop_amount), 0) as safe_drop_total,
                    COUNT(*) as drop_count")
                ->groupBy(DB::raw('DATE(closed_at)'), DB::raw('COALESCE(location_id, 0)'))
                ->get();
            foreach ($sdRows as $sd) {
                $safe_drops[(string) $sd->day][(int) $sd->loc_key] = $sd;
            }
        }

        // Normalize → ordered array, most recent day first, locations alpha.
        $by_day_list = [];
        krsort($by_day);
        foreach ($by_day as $day => $locs) {
            $block = ['day' => $day, 'locations' => []];
            foreach ($locs as $loc) {
                $loc_id_for_safe = (int) ($loc['location_id'] ?? 0);
                $sd = $safe_drops[$day][$loc_id_for_safe]
                    ?? $safe_drops[$day][0]
                    ?? null;
                $block['locations'][] = [
                    'location_id'     => $loc['location_id'] ?? null,
                    'location_name'   => $loc['location_name'] ?? '(no location)',
                    'clover_payments' => $loc['clover_payments'] ?? [],
                    'erp_payments'    => $loc['erp_payments'] ?? [],
                    'clover_total'    => round($loc['clover_total'] ?? 0, 2),
                    'erp_total'       => round($loc['erp_total'] ?? 0, 2),
                    'safe_drop_total' => round((float) ($sd->safe_drop_total ?? 0), 2),
                    'safe_drop_count' => (int) ($sd->drop_count ?? 0),
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
     * counterpart by (location, amount within ±1¢, closest timestamp
     * within 72h). Per-cashier grouping uses the ERP user who created the
     * sale, not Clover's time-clock name. Then group so Fatteen can see:
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

        // ---- Load Clover payments ---- mixed-TZ tolerant.
        $unkWin = $this->cloverPaidAtIstWindow($start, $end);
        $cpQ = \DB::table('clover_payments as cp')
            ->leftJoin('business_locations as bl', 'cp.location_id', '=', 'bl.id')
            ->where('cp.business_id', $business_id)
            ->where(function ($q) {
                $q->whereNull('cp.result')->orWhere('cp.result', 'SUCCESS')->orWhere('cp.result', 'APPROVED');
            })
            ->whereRaw($this->cloverCreatedInWindowSql($unkWin, 'cp'));
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

        // ---- Greedy 1-to-1 match: for each Clover payment, find the best
        // ----  unmatched ERP card payment with same store + same amount
        // ----  (±1¢) + closest timestamp. Clover time-clock names are NOT
        // ----  used — they often differ from who rang the sale in the ERP
        // ----  (Sarah 2026-05). Batch-settled Clover rows can land many
        // ----  hours after the ERP sale, so the time window is wide; we
        // ----  still pick the single closest ERP row per Clover charge.
        $claimedErp = [];
        $matched = [];
        $cloverOnly = [];
        $toCentsEod = function ($x) {
            return (int) round(((float) $x) * 100);
        };
        $maxDeltaSec = 72 * 3600;

        foreach ($cpRows as $cp) {
            $cpAmtCents = $toCentsEod($cp->amount);
            $cpAmt = round((float) $cp->amount, 2);
            $cpTs = strtotime((string) $cp->ts);

            $bestIdx = null;
            $bestDelta = PHP_INT_MAX;
            foreach ($erpRows as $i => $er) {
                if (isset($claimedErp[$i])) {
                    continue;
                }
                if (abs($toCentsEod($er->amount) - $cpAmtCents) > 1) {
                    continue;
                }
                if ($cp->location_id !== null && (int) $cp->location_id !== 0) {
                    if ((int) $cp->location_id !== (int) ($er->location_id ?? 0)) {
                        continue;
                    }
                }
                $delta = abs(strtotime((string) $er->ts) - $cpTs);
                if ($delta > $maxDeltaSec) {
                    continue;
                }
                if ($delta < $bestDelta) {
                    $bestDelta = $delta;
                    $bestIdx = $i;
                }
            }

            if ($bestIdx !== null) {
                $claimedErp[$bestIdx] = true;
                $erpCashierKey = $firstName($erpRows[$bestIdx]->employee_name);
                $matched[] = (object) [
                    'ts' => $cp->ts,
                    'amount' => $cpAmt,
                    'cashier' => $erpCashierKey !== '' ? $erpCashierKey : ($firstName($cp->employee_name) ?: 'unknown'),
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
                    'cashier' => $firstName($cp->employee_name),
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
    public function loadReconciliations($business_id, $start, $end): array
    {
        $rows = \App\CloverReconciliation::where('business_id', $business_id)
            ->whereBetween('day', [$start, $end])
            ->with('user:id,first_name,last_name,username')
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $loc = $r->location_id === null ? 0 : (int) $r->location_id;
            $emp = $r->employee_key === null || $r->employee_key === ''
                ? '' : strtolower($r->employee_key);
            // Legacy store-level rows still index under "day|loc" (empty
            // employee suffix); per-cashier rows append "|<empKey>" so the
            // blade can look up either granularity in O(1).
            $key = $r->day->format('Y-m-d') . '|' . $loc . '|' . $emp;
            $out[$key] = $r;
        }
        return $out;
    }

    /**
     * Read-only admin diagnostic — returns channel + is_whatnot + a few
     * other fields for one transaction so we can spot why it bucketed
     * the way it did. Sarah 2026-05-07: Zakary's #18242 looked Whatnot
     * in the report but is actually a regular walk-in. We need to see
     * the row to know whether it's a data tag bug or a code bug.
     *
     * Route: GET /reports/clover-eod/debug-transaction/{id}
     */
    /**
     * Recategorize one transaction's channel — used by the "→ in-store"
     * button on the Other-channels rows. Sarah 2026-05-07: the POS
     * Whatnot chip gets flipped accidentally and tags a regular walk-in
     * as Whatnot, which then drops out of the cashier's drawer math
     * downstream. This lets her flip a single sale back without
     * round-tripping through Edit Sale UI. Admin-only, logs every flip.
     *
     * Route: POST /reports/clover-eod/recategorize-channel
     */
    public function cloverEodRecategorizeChannel(Request $request)
    {
        if (!$this->businessUtil->is_admin(auth()->user())) {
            return response()->json(['success' => false], 403);
        }
        $business_id = (int) $request->session()->get('user.business_id');
        $txnId = (int) $request->input('transaction_id');
        $newChannel = $request->input('channel');
        if (!$txnId || !in_array($newChannel, ['in_store','whatnot','discogs','ebay'], true)) {
            return response()->json(['success' => false, 'msg' => 'invalid params'], 422);
        }
        $existing = \DB::table('transactions')
            ->where('id', $txnId)
            ->where('business_id', $business_id)
            ->first(['id', 'channel', 'is_whatnot']);
        if (!$existing) return response()->json(['success' => false, 'msg' => 'not found'], 404);
        \DB::table('transactions')
            ->where('id', $txnId)
            ->where('business_id', $business_id)
            ->update([
                'channel'     => $newChannel,
                'is_whatnot'  => $newChannel === 'whatnot' ? 1 : 0,
                'updated_at'  => now(),
            ]);
        \Log::info(sprintf(
            'EOD recategorize: txn=%d %s→%s by user=%d',
            $txnId, $existing->channel, $newChannel, (int) auth()->id()
        ));
        return response()->json(['success' => true, 'from' => $existing->channel, 'to' => $newChannel]);
    }

    public function cloverEodDebugTransaction(Request $request, $id)
    {
        if (!$this->businessUtil->is_admin(auth()->user())) {
            return response()->json(['success' => false], 403);
        }
        $business_id = (int) $request->session()->get('user.business_id');
        // The "#NNNN" in the UI is invoice_no, not transactions.id, so
        // accept either: try as id first, then fall back to invoice_no.
        $cols = [
            'id', 'invoice_no', 'type', 'status', 'channel', 'is_whatnot',
            'location_id', 'created_by', 'final_total',
            'transaction_date', 'additional_notes', 'staff_note',
            'source', 'sub_type', 'is_direct_sale',
        ];
        $rows = \DB::table('transactions')
            ->where('business_id', $business_id)
            ->where(function ($q) use ($id) {
                $q->where('id', (int) $id)->orWhere('invoice_no', (string) $id);
            })
            ->limit(5)
            ->get($cols);
        if ($rows->isEmpty()) return response()->json(['success' => false, 'msg' => 'not found'], 404);
        return response()->json(['success' => true, 'transactions' => $rows]);
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
        $employeeKey = $request->input('employee_key'); // null/empty = store-level
        if (!$day) return response()->json(['success' => false, 'msg' => 'day required'], 422);

        $row = \App\CloverReconciliation::findOrCreateFor($business_id, $locationId, $day, $employeeKey);
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
        $employeeKey = $request->input('employee_key');
        $notes = (string) $request->input('notes', '');
        if (!$day) return response()->json(['success' => false, 'msg' => 'day required'], 422);

        $row = \App\CloverReconciliation::findOrCreateFor($business_id, $locationId, $day, $employeeKey);
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
        $diagWin = $this->cloverPaidAtIstWindow($start, $end);
        $diagDayExpr = $this->cloverLaDateSql($diagWin, 'cp');
        $cloverQ = \DB::table('clover_payments as cp')
            ->leftJoin('business_locations as bl', 'cp.location_id', '=', 'bl.id')
            ->where('cp.business_id', $business_id)
            ->whereRaw($this->cloverCreatedInWindowSql($diagWin, 'cp'))
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
                {$diagDayExpr} as day,
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
            ->whereRaw($this->cloverCreatedInWindowSql($diagWin, 'cp'))
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
                {$diagDayExpr} as day,
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
        $hasSafeDrop = \Schema::hasColumn('cash_registers', 'safe_drop_amount');
        $q = \DB::table('cash_registers as cr')
            ->leftJoin('users as u', 'cr.user_id', '=', 'u.id')
            ->leftJoin('cash_register_transactions as crt', 'cr.id', '=', 'crt.cash_register_id')
            ->where('cr.business_id', $business_id)
            ->whereDate('cr.created_at', '>=', $start)
            ->whereDate('cr.created_at', '<=', $end);
        if (!empty($location_id)) {
            $q->where('cr.location_id', $location_id);
        }
        // Wrapped in MAX() so the column is aggregate-safe under strict
        // ONLY_FULL_GROUP_BY (cr.id is in GROUP BY so MAX picks the one
        // value per register without needing GROUP BY widening).
        $safeDropSelect = $hasSafeDrop ? 'MAX(COALESCE(cr.safe_drop_amount, 0))' : '0';
        $rows = $q->selectRaw("
                DATE(cr.created_at) as day,
                cr.location_id,
                cr.id as register_id,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, 'Unknown') as employee_name,
                cr.created_at as opened_at,
                cr.closed_at as closed_at,
                cr.closing_amount as reported_ending_cash,
                {$safeDropSelect} as safe_drop_amount,
                SUM(CASE WHEN crt.pay_method='cash' AND crt.transaction_type='initial' THEN crt.amount ELSE 0 END) as opening_cash,
                SUM(CASE WHEN crt.pay_method='cash' AND crt.transaction_type='sell' AND crt.type='credit' THEN crt.amount ELSE 0 END) as cash_sales,
                SUM(CASE WHEN crt.transaction_type='purchase' AND crt.type='debit' THEN crt.amount ELSE 0 END) as collection_buys_all,
                SUM(CASE WHEN crt.pay_method='cash' AND crt.transaction_type='purchase' AND crt.type='debit' THEN crt.amount ELSE 0 END) as cash_buys,
                SUM(CASE WHEN crt.pay_method='cash' AND crt.transaction_type='refund' AND crt.type='debit' THEN crt.amount ELSE 0 END) as cash_refunds,
                SUM(CASE WHEN crt.pay_method='cash' AND crt.transaction_type='expense' AND crt.type='debit' THEN crt.amount ELSE 0 END) as cash_expenses,
                SUM(CASE WHEN crt.pay_method='cash' AND crt.transaction_type='transfer' AND crt.type='debit' THEN crt.amount ELSE 0 END) as cash_transfers_out,
                SUM(CASE WHEN crt.pay_method='cash' AND crt.transaction_type='transfer' AND crt.type='credit' THEN crt.amount ELSE 0 END) as cash_transfers_in,
                SUM(CASE WHEN crt.pay_method='cash'
                         AND crt.transaction_type NOT IN ('initial','sell','purchase','refund','expense','transfer')
                    THEN CASE WHEN crt.type='credit' THEN crt.amount ELSE -crt.amount END
                    ELSE 0 END) as cash_other_net,
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
                    'cash_expenses' => 0.0,
                    'cash_transfers_out' => 0.0, 'cash_transfers_in' => 0.0,
                    'cash_other_net' => 0.0,
                    'collection_buys_all' => 0.0,
                    'expected_ending_cash' => 0.0, 'reported_ending_cash' => 0.0,
                    'safe_drop_amount' => 0.0,
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
            $row['cash_expenses'] += (float) $s->cash_expenses;
            $row['cash_transfers_out'] += (float) $s->cash_transfers_out;
            $row['cash_transfers_in'] += (float) $s->cash_transfers_in;
            $row['cash_other_net'] += (float) $s->cash_other_net;
            $row['collection_buys_all'] += (float) $s->collection_buys_all;
            $row['expected_ending_cash'] += (float) $s->cash_net;
            $row['reported_ending_cash'] += (float) $s->reported_ending_cash;
            $row['safe_drop_amount'] += (float) ($s->safe_drop_amount ?? 0);
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
    public function cloverEodEmployeeBreakdownRange($business_id, $start, $end, $location_id, array $card_methods, $used_all_methods)
    {
        // Sarah 2026-05-11: exclude is_whatnot=1 from per-cashier rows
        // so Whatnot livestream sales don't make a cashier's "matched
        // on Clover" check look like they pocketed money. Whatnot is
        // already surfaced separately in the day-totals card.
        $erpQ = \DB::table('transaction_payments as tp')
            ->join('transactions as t', 'tp.transaction_id', '=', 't.id')
            ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->leftJoin('users as u', 't.created_by', '=', 'u.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('t.import_source')
            ->where(function ($q) { $q->where('t.is_whatnot', 0)->orWhereNull('t.is_whatnot'); })
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

        // Cash-rung-as-cash per (day, location, employee). Sarah 2026-05-11:
        // cash IS recorded on Clover at Nivessa, so the previous "implied
        // cash = total − clover" proxy is wrong — that gap is a real
        // reconciliation miss, not cash income. tp.method='cash' is the
        // trustworthy source (Sarah's memory 2026-05-06). This feeds the
        // cash drawer "+ Cash sales" line and the expected_ending_cash
        // calculation downstream.
        $cashQ = \DB::table('transaction_payments as tp')
            ->join('transactions as t', 'tp.transaction_id', '=', 't.id')
            ->leftJoin('users as u', 't.created_by', '=', 'u.id')
            ->whereNull('t.import_source')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where(function ($q) { $q->where('t.is_whatnot', 0)->orWhereNull('t.is_whatnot'); })
            ->where('tp.method', 'cash')
            ->whereDate('t.transaction_date', '>=', $start)
            ->whereDate('t.transaction_date', '<=', $end);
        if (!empty($location_id)) {
            $cashQ->where('t.location_id', $location_id);
        }
        $cashRows = $cashQ->selectRaw("DATE(t.transaction_date) as day,
                t.location_id,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, 'Unknown') as employee_name,
                COALESCE(SUM(tp.amount), 0) as cash_total")
            ->groupBy(DB::raw('DATE(t.transaction_date)'), 't.location_id', 'employee_name')
            ->get();
        $cashByKey = [];
        foreach ($cashRows as $cr) {
            $k = $cr->day . '|' . ($cr->location_id ?: 0) . '|' . strtolower(trim(explode(' ', $cr->employee_name)[0] ?? ''));
            $cashByKey[$k] = (float) $cr->cash_total;
        }

        // total_sales aggregation now happens in PHP from $txns below
        // (so we can re-attribute admin-rung sales to the on-shift
        // cashier). $totalQ used to do the SUM in SQL but lost that
        // ability when we needed per-row admin awareness.

        // Pull RAW Clover payments (one row each) so we can do shift-window
        // attribution before aggregating. Sarah 2026-05-05: at Nivessa most
        // Clover swipes don't carry an employee pin, so a naive GROUP BY
        // employee_name dumps every swipe into "Unknown" and the per-cashier
        // breakdown shows $0 paid-by-card for everyone. We fix that here by
        // looking up which cashier's register was open at (paid_at, location)
        // for each pin-less swipe and attributing it to them — same logic
        // already used in cloverEodXlsxLayout.
        // Sarah 2026-05-13: honor manual Clover↔ERP cross-day pairings.
        // When a cashier rings a card on Clover but doesn't enter the
        // ERP sale until the next morning (Henry pattern), Sarah pairs
        // them by hand on /pos/recent-feed. For the per-cashier Diff
        // (Clover − ERP) to actually balance after pairing, the Clover
        // swipe needs to attribute to the ERP txn's day + cashier
        // rather than the swipe's natural day. Without this, today's
        // card shows ERP up by the catch-up amount and yesterday's
        // card shows Clover up by the same amount — exactly the
        // opposite of what "matched" should mean.
        $manualClvMatches = \App\Http\Controllers\SellPosController::loadCloverManualMatches((int) $business_id);
        $manualClvToTxn = [];
        $crossDayClvPullIds = [];
        if (!empty($manualClvMatches)) {
            $manualClvToTxn = \DB::table('transactions as t')
                ->leftJoin('users as u', 't.created_by', '=', 'u.id')
                ->whereIn('t.id', array_values($manualClvMatches))
                ->where('t.business_id', $business_id)
                ->selectRaw("t.id, DATE(t.transaction_date) as day, t.location_id,
                    COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, '') as cashier_name")
                ->get()
                ->keyBy('id')
                ->all();
            // Find Clover IDs that pair to an ERP txn inside the viewed
            // window — pull them into cpRaw even if their own LA-day
            // falls outside the default window. Otherwise the day-
            // scoped cpQ silently drops them and the re-attribution
            // below has nothing to act on (today's view never sees
            // yesterday's Clover orphan that pairs to today's ERP).
            foreach ($manualClvMatches as $cpId => $txId) {
                if (!isset($manualClvToTxn[(int) $txId])) continue;
                $mm = $manualClvToTxn[(int) $txId];
                if ($mm->day >= $start && $mm->day <= $end) {
                    $crossDayClvPullIds[] = (int) $cpId;
                }
            }
        }

        $xlsxWin = $this->cloverPaidAtIstWindow($start, $end);
        $xlsxDayExpr = $this->cloverLaDateSql($xlsxWin, 'cp');
        $windowSql = $this->cloverCreatedInWindowSql($xlsxWin, 'cp');
        $cpQ = \DB::table('clover_payments as cp')
            ->leftJoin('business_locations as bl', 'cp.location_id', '=', 'bl.id')
            ->where('cp.business_id', $business_id)
            ->where(function ($q) {
                $q->whereNull('cp.result')->orWhere('cp.result', 'SUCCESS')->orWhere('cp.result', 'APPROVED');
            })
            ->where(function ($q) use ($windowSql, $crossDayClvPullIds) {
                $q->whereRaw($windowSql);
                if (!empty($crossDayClvPullIds)) {
                    $q->orWhereIn('cp.id', $crossDayClvPullIds);
                }
            });
        if (!empty($location_id)) {
            $cpQ->where(function ($q) use ($location_id) {
                $q->where('cp.location_id', $location_id)->orWhereNull('cp.location_id');
            });
        }
        // Sarah 2026-05-15: select cp_created_ms (the actual swipe instant
        // from raw_payload.createdTime) so the matcher can compute the
        // ERP↔Clover time delta from the real terminal swipe time, not
        // cp.paid_at — which Clover overwrites with the next-morning
        // batch-settlement timestamp for sales rung late in the day.
        // Without this, Jacob's 2:10pm $13.17 swipe (paid_at batched to
        // 4am the next morning) sat 14h off from its ERP txn and never
        // paired in the per-cashier aggregator, even though the recent
        // feed (which uses createdTime via parseCloverPaidAtLa) showed
        // it correctly. Fallback to UNIX_TIMESTAMP(paid_at) for the rare
        // legacy row without createdTime; that path is the same TZ as
        // the strtotime path it replaces, so behavior is unchanged.
        $cpRaw = $cpQ->selectRaw("{$xlsxDayExpr} as day,
                cp.id as cp_id,
                cp.location_id, bl.name as location_name,
                cp.paid_at as ts,
                COALESCE(
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(cp.raw_payload, '$.createdTime')) AS UNSIGNED) / 1000,
                    UNIX_TIMESTAMP(cp.paid_at)
                ) as cp_ts_epoch,
                cp.amount as amount,
                COALESCE(cp.tax_cents, 0) as tax_cents,
                COALESCE(NULLIF(TRIM(cp.employee_name), ''), '') as employee_name")
            ->orderBy('cp.paid_at')
            ->get();

        // Pull cash_registers (with a 1-day buffer on each side) so we can
        // resolve "whose register was open at the moment of this swipe?".
        $regQ = \DB::table('cash_registers as cr')
            ->leftJoin('users as u', 'cr.user_id', '=', 'u.id')
            ->where('cr.business_id', $business_id)
            ->whereDate('cr.created_at', '>=', \Carbon::parse($start)->subDay())
            ->whereDate('cr.created_at', '<=', \Carbon::parse($end)->addDay());
        if (!empty($location_id)) {
            $regQ->where('cr.location_id', $location_id);
        }
        $registers = $regQ->selectRaw("
                cr.location_id,
                cr.user_id,
                cr.created_at as opened_at,
                cr.closed_at,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, '') as user_name
            ")
            ->orderBy('cr.created_at')
            ->get();

        // Sarah 2026-05-13: Sling clock-out is the truth about when a
        // cashier physically left, not cash_registers.closed_at — they
        // forget to close the drawer. Without this, a register opened in
        // the morning and never closed absorbs evening swipes from the
        // next cashier. Henry's $5.49 at 6:31pm landed on Manolo because
        // Manolo's drawer row had closed_at=NULL; Sling shows him out
        // at 2:30pm.
        // Map: erp_user_id => max dtend per LA-day (latest punch-out).
        $slingEndByUserDay = [];
        if (\Schema::hasTable('sling_shifts') && $registers->isNotEmpty()) {
            $userIds = $registers->pluck('user_id')->filter()->unique()->values()->all();
            if (!empty($userIds)) {
                $slingRows = \DB::table('sling_shifts')
                    ->where('event_type', 'shift')
                    ->whereIn('erp_user_id', $userIds)
                    ->whereDate('dtstart', '>=', \Carbon::parse($start)->subDay())
                    ->whereDate('dtstart', '<=', \Carbon::parse($end)->addDay())
                    ->whereNotNull('dtend')
                    ->select('erp_user_id', 'dtstart', 'dtend')
                    ->get();
                foreach ($slingRows as $sr) {
                    $endTs = @strtotime((string) $sr->dtend) ?: 0;
                    if ($endTs <= 0) continue;
                    // Bucket by the LA-day the shift STARTED on so an
                    // evening shift that crosses midnight stays attached
                    // to its opening day (matches the register's day).
                    $dayKey = (new \DateTime((string) $sr->dtstart, new \DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
                    $key = (int) $sr->erp_user_id . '|' . $dayKey;
                    if (!isset($slingEndByUserDay[$key]) || $endTs > $slingEndByUserDay[$key]) {
                        $slingEndByUserDay[$key] = $endTs;
                    }
                }
            }
        }

        // Pull raw ERP transactions for amount-based matching (same logic
        // as recentSalesFeed). Each Clover swipe is paired to the ERP
        // transaction with matching final_total at the same location,
        // closest in time. The ERP txn's created_by is the source of truth
        // for who rang the sale — beats shift-window attribution because
        // it doesn't depend on slack at the edges of two cashiers' shifts.
        $txnQ = \DB::table('transactions as t')
            ->leftJoin('users as u', 't.created_by', '=', 'u.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('t.import_source')
            ->whereDate('t.transaction_date', '>=', \Carbon::parse($start)->subDay())
            ->whereDate('t.transaction_date', '<=', \Carbon::parse($end)->addDay());
        if (!empty($location_id)) {
            $txnQ->where('t.location_id', $location_id);
        }
        // Sarah 2026-05-07 looking at Zakary's $228.28 ERP-only at 3:19pm:
        // "is it possible that was a Whatnot order?". Yes — Whatnot /
        // Discogs / eBay sales live in transactions but never touch the
        // drawer or Clover, so they were turning up as fake "missed
        // swipes" in the drilldown. Limit the daily cash reconciliation
        // to in-store sales; other channels are reconciled in the
        // sales-by-channel report. Guarded so a fresh install without
        // the channel column doesn't 500.
        if (\Schema::hasColumn('transactions', 'channel')) {
            $txnQ->where('t.channel', 'in_store');
        }
        $txns = $txnQ->selectRaw("
                t.id, t.location_id, t.final_total, t.total_before_tax,
                COALESCE(t.tax_amount, 0) as tax_amount, t.transaction_date,
                t.created_by as cashier_user_id,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, '') as cashier_name
            ")
            ->get();

        // Admin/owner detection — Sarah 2026-05-05: "Jonathan rings up
        // customers sometimes but the sale should attribute to the
        // cashier on shift, not him." We pull the admin first-name set
        // once and use it on both sides:
        //   - Clover amount-match: if the matched ERP txn was rung by
        //     an admin, fall back to shift-window attribution.
        //   - ERP aggregation: admin-rung sales get re-attributed to
        //     whoever's register was open at the time, before bucketing.
        //   - Bucket pruning: drop any cashier card whose first name is
        //     in this set so Jonathan/Sarah never appear as a cashier.
        // Sarah 2026-05-15: was using User::permission('Admin#X'), but
        // 'Admin#X' is a Spatie ROLE not a permission (see BusinessUtil
        // ::newBusinessDefaultResources where Role::create('Admin#X')
        // happens). The permission scope was returning zero users so
        // adminSet was empty — Jonathan's card showed up on /pos/recent
        // -feed with phantom Clover totals because nothing was dropping
        // him. Switched to User::role() which is what the rest of the
        // codebase uses (Util::is_admin, ManageUserController, etc.).
        try {
            $adminFirstNames = \App\User::role('Admin#' . $business_id)
                ->where('users.business_id', $business_id)
                ->pluck('first_name')
                ->map(function ($n) { $n = trim((string) $n); $parts = $n === '' ? [] : preg_split('/\s+/', $n); return strtolower($parts[0] ?? ''); })
                ->filter()->unique()->values()->all();
        } catch (\Throwable $ex) {
            // Role name may not exist on a fresh install — fall back
            // to no admins rather than crash the whole report.
            $adminFirstNames = [];
        }
        $adminSet = array_flip($adminFirstNames);

        // Helper: which non-admin cashier had a register open at (ts, loc)?
        // Returns full user_name string or null. Excludes admins so an
        // admin-rung sale doesn't re-attribute to another admin who
        // happened to also have a register open. Also caps how stale a
        // still-"open" register can be — 18h — so yesterday's register
        // that was never closed doesn't absorb today's swipes.
        $findShiftCashier = function ($ts, $locId) use ($registers, $adminSet, $slingEndByUserDay) {
            $cpTs = strtotime((string) $ts);
            // 1) Strict pass: a register actually open at $cpTs. When two
            //    registers at the same store both cover $cpTs, prefer the
            //    one opened later — the older one is almost always
            //    someone who forgot to close before leaving.
            $bestName = null; $bestOpenTs = -1;
            foreach ($registers as $reg) {
                if ((int) ($reg->location_id ?? 0) !== (int) ($locId ?? 0)) continue;
                if ($reg->user_name === '') continue;
                $rfn = strtolower(preg_split('/\s+/', trim($reg->user_name))[0] ?? '');
                if (isset($adminSet[$rfn])) continue;
                $openTs  = strtotime((string) $reg->opened_at);
                $open    = $openTs - 60;
                // Effective close = min(register.closed_at, sling dtend).
                // Sling dtend is the cashier's actual punch-out and beats
                // a forgotten-to-close drawer. +5min slack on Sling so a
                // swipe rung right at clock-out still attributes.
                $regCloseTs = $reg->closed_at ? (strtotime((string) $reg->closed_at) + 60) : PHP_INT_MAX;
                $slingKey = ((int) ($reg->user_id ?? 0)) . '|' . date('Y-m-d', $openTs);
                $slingCloseTs = isset($slingEndByUserDay[$slingKey])
                    ? ($slingEndByUserDay[$slingKey] + 300)
                    : PHP_INT_MAX;
                $close = min($regCloseTs, $slingCloseTs);
                $stale = !$reg->closed_at && !isset($slingEndByUserDay[$slingKey]) && ($cpTs - $openTs > 18 * 3600);
                if (!$stale && $cpTs >= $open && $cpTs <= $close) {
                    if ($openTs > $bestOpenTs) {
                        $bestOpenTs = $openTs;
                        $bestName   = $reg->user_name;
                    }
                }
            }
            if ($bestName !== null) {
                return $bestName;
            }
            // 2) Sarah 2026-05-11: Zak's first Pico swipe of the day was
            //    at 12:05pm but he opened his register at 12:13pm — strict
            //    pass missed him so the $5 charge had no card. Fall back
            //    to the cashier whose shift START is nearest to the swipe
            //    on the same calendar day at the same location.
            $cpDay = date('Y-m-d', $cpTs);
            $bestName = null; $bestDelta = PHP_INT_MAX;
            foreach ($registers as $reg) {
                if ((int) ($reg->location_id ?? 0) !== (int) ($locId ?? 0)) continue;
                if ($reg->user_name === '') continue;
                $rfn = strtolower(preg_split('/\s+/', trim($reg->user_name))[0] ?? '');
                if (isset($adminSet[$rfn])) continue;
                $openTs = strtotime((string) $reg->opened_at);
                if (date('Y-m-d', $openTs) !== $cpDay) continue;
                $delta = abs($openTs - $cpTs);
                if ($delta < $bestDelta) { $bestDelta = $delta; $bestName = $reg->user_name; }
            }
            return $bestName;
        };

        $toCents = function ($x) { return (int) round(((float) $x) * 100); };
        $claimedTxns = [];

        // Attribution pass: for each Clover swipe (newest-first to keep
        // the latest sales paired first), try to find an unclaimed ERP
        // sale at the same location with a near-matching amount and
        // close-in-time. Sarah 2026-05-06 looking at Manolo's then
        // Henry's drilldowns: $6.59 Clover at 2:07 vs $6.71 ERP at
        // 2:08 is the same sale undercharged by 12¢ on Clover. Tax-
        // rounding gaps of up to ~25¢ are real here. Match within
        // ±25¢ AND within 12 hours.
        // Score = amount-cents × 1000 + time-seconds so exact matches
        // still beat off-by-cents matches even at longer time gaps.
        // Pairs with >5¢ drift surface as "amount mismatch" rather
        // than hidden as clean matches.
        // Sarah 2026-05-15: time window matches the recent-feed matcher
        // (12hr, not 30min). Clover batch-settles overnight so an
        // afternoon sale can carry a paid_at hours later; the 30-min
        // window was rejecting those pairs, leaving the swipes to fall
        // back to shift-window attribution. When the fallback resolved
        // to a different cashier (or "Unknown" and got dropped), the
        // cashier's clover_total undercounted by the missed ticket
        // amount — Jacob's card showed −$128.36 even though every
        // ticket on the per-sale feed reconciled cleanly.
        $cpForMatch = $cpRaw->sortByDesc('ts')->values();
        $matchAmountCents     = 25;
        $cleanMatchCents      = 5;
        $matchTimeWindow      = 43200; // seconds (12 hr)
        foreach ($cpForMatch as $r) {
            // cp_ts_epoch is built from raw_payload.createdTime (UTC ms,
            // unambiguous) — see selectRaw above. Falls back to
            // UNIX_TIMESTAMP(paid_at) only when createdTime is missing.
            $cpTs    = (int) ($r->cp_ts_epoch ?? strtotime((string) $r->ts));
            $cpCents = $toCents($r->amount);
            $cpLoc   = $r->location_id;

            $bestId = null;
            $bestScore = PHP_INT_MAX;
            $bestAmtDelta = 0;
            $bestSignedDelta = 0;
            foreach ($txns as $t) {
                if (isset($claimedTxns[$t->id])) continue;
                $signedDelta = $cpCents - $toCents($t->final_total); // + means Clover > ERP
                $amtDelta = abs($signedDelta);
                if ($amtDelta > $matchAmountCents) continue;
                // Sarah 2026-05-11: strict same-store. Allowing
                // null-Clover-location to pair across stores was
                // false-pairing Pico orphans to Hollywood cash tests.
                if ((int) ($cpLoc ?? 0) !== (int) ($t->location_id ?? 0)) continue;
                $timeDelta = abs(strtotime((string) $t->transaction_date) - $cpTs);
                if ($timeDelta > $matchTimeWindow) continue;
                // Score: amount gap weighted heavier than time gap so an
                // exact-cent match within 10min beats a 5¢-off match in
                // the same minute. amount-cents × 1000 + time-seconds.
                $score = $amtDelta * 1000 + $timeDelta;
                if ($score < $bestScore) {
                    $bestScore = $score;
                    $bestId = $t->id;
                    $bestAmtDelta = $amtDelta;
                    $bestSignedDelta = $signedDelta;
                }
            }
            if ($bestId !== null) {
                $claimedTxns[$bestId] = true;
                $r->matched_txn_id = $bestId;
                $r->matched_diff_cents = $bestSignedDelta; // signed: + = Clover over, − = under
                $matchedCashier = '';
                foreach ($txns as $t) {
                    if ($t->id === $bestId) { $matchedCashier = $t->cashier_name; break; }
                }
                $matchedFirst = strtolower(preg_split('/\s+/', trim($matchedCashier))[0] ?? '');
                if ($matchedCashier !== '' && !isset($adminSet[$matchedFirst])) {
                    $r->employee_name = $matchedCashier;
                    continue;
                }
                // Matched ERP txn was rung by an admin (Jon) — fall through
                // to shift-window attribution so the swipe credits to the
                // actual cashier on shift, not the owner.
            }

            // Fallback: shift-window attribution for swipes that don't
            // have a matching ERP sale OR matched to an admin-rung sale.
            // Only override pin-less rows here so a Clover-pinned swipe
            // with no ERP match still keeps Clover's pin.
            // Use the createdTime-derived epoch so batch-settled swipes
            // (paid_at = next-morning) attribute to the cashier on shift
            // at the real swipe time, not whoever happens to be there
            // when Clover batched.
            if ($r->employee_name !== '') continue;
            $cpTsForShift = (int) ($r->cp_ts_epoch ?? strtotime((string) $r->ts));
            $sw = $findShiftCashier(date('Y-m-d H:i:s', $cpTsForShift), $r->location_id);
            if ($sw) {
                $r->employee_name = $sw;
            }
        }

        // Second pass — same-time obvious pairs (Sarah 2026-05-11):
        // if an unpaired Clover charge and an unpaired ERP sale happen
        // at the same store within ±2 minutes, they're almost certainly
        // the same transaction with a keying error. Pair them anyway
        // and tag with the (potentially large) signed amount delta so
        // the breakdown shows it as KEYING ERROR (large) instead of
        // leaving both as separate orphans. This catches the 12:29pm
        // Hollywood $177.06 Clover / $161.33 ERP case where the matcher
        // rejected the pair on amount but they're obviously linked.
        $sameTimeWindow = 120; // seconds (±2 min)
        foreach ($cpForMatch as $r) {
            if (!empty($r->matched_txn_id)) continue;
            $cpTs    = (int) ($r->cp_ts_epoch ?? strtotime((string) $r->ts));
            $cpCents = $toCents($r->amount);
            $cpLoc   = $r->location_id;
            $bestId = null; $bestTd = PHP_INT_MAX; $bestSignedDelta = 0;
            foreach ($txns as $t) {
                if (isset($claimedTxns[$t->id])) continue;
                // Sarah 2026-05-11: strict same-store. Allowing
                // null-Clover-location to pair across stores was
                // false-pairing Pico orphans to Hollywood cash tests.
                if ((int) ($cpLoc ?? 0) !== (int) ($t->location_id ?? 0)) continue;
                $td = abs(strtotime((string) $t->transaction_date) - $cpTs);
                if ($td > $sameTimeWindow) continue;
                if ($td < $bestTd) {
                    $bestTd = $td;
                    $bestId = $t->id;
                    $bestSignedDelta = $cpCents - $toCents($t->final_total);
                }
            }
            if ($bestId !== null) {
                $claimedTxns[$bestId] = true;
                $r->matched_txn_id = $bestId;
                $r->matched_diff_cents = $bestSignedDelta;
                $r->matched_same_time = true; // flag for the breakdown UI
                $matchedCashier = '';
                foreach ($txns as $t) {
                    if ($t->id === $bestId) { $matchedCashier = $t->cashier_name; break; }
                }
                $matchedFirst = strtolower(preg_split('/\s+/', trim($matchedCashier))[0] ?? '');
                if ($matchedCashier !== '' && !isset($adminSet[$matchedFirst])) {
                    $r->employee_name = $matchedCashier;
                }
            }
        }

        // Aggregate post-attribution. Pin-less swipes that still have no
        // matching open shift fall through to 'Unknown' and get skipped by
        // the card render — usually means a register was closed when a
        // card-on-file / online charge ran.
        $cloverAgg = [];
        foreach ($cpRaw as $r) {
            $effDay = $r->day;
            $effLoc = $r->location_id;
            $effEmp = $r->employee_name;
            $effLocName = $r->location_name;
            // Manual cross-day pair: re-attribute the swipe to the
            // matched ERP txn's (day, location, cashier). Skips when
            // the matched txn was deleted or admin-rung without a
            // clean shift-window fallback.
            if (isset($r->cp_id) && isset($manualClvMatches[(int) $r->cp_id])) {
                $matchedTxId = (int) $manualClvMatches[(int) $r->cp_id];
                if (isset($manualClvToTxn[$matchedTxId])) {
                    $mm = $manualClvToTxn[$matchedTxId];
                    $matchedFirst = strtolower(preg_split('/\s+/', trim((string) $mm->cashier_name))[0] ?? '');
                    if (isset($adminSet[$matchedFirst])) {
                        $cpTsForShift = (int) ($r->cp_ts_epoch ?? strtotime((string) $r->ts));
                        $sw = $findShiftCashier(date('Y-m-d H:i:s', $cpTsForShift), $mm->location_id);
                        if ($sw !== null) {
                            $effEmp = $sw;
                            $effDay = $mm->day;
                            $effLoc = (int) $mm->location_id;
                        }
                    } else {
                        $effEmp = (string) $mm->cashier_name;
                        $effDay = $mm->day;
                        $effLoc = (int) $mm->location_id;
                    }
                }
            }
            $emp = $effEmp !== '' ? $effEmp : 'Unknown';
            $key = $effDay . '|' . ($effLoc ?: 0) . '|' . strtolower($emp);
            if (!isset($cloverAgg[$key])) {
                $cloverAgg[$key] = (object) [
                    'day' => $effDay,
                    'location_id' => $effLoc,
                    'location_name' => $effLocName,
                    'employee_name' => $emp,
                    'clover_count' => 0,
                    'clover_total' => 0.0,
                    'clover_net'   => 0.0,
                ];
            }
            $cloverAgg[$key]->clover_count += 1;
            $cloverAgg[$key]->clover_total += (float) $r->amount;
            $cloverAgg[$key]->clover_net   += (float) $r->amount - ((float) ($r->tax_cents ?? 0)) / 100.0;
        }
        $cloverRows = collect(array_values($cloverAgg));

        $firstName = function ($full) {
            $full = trim((string) $full);
            if ($full === '') return 'unknown';
            $parts = preg_split('/\s+/', $full);
            return strtolower($parts[0] ?? 'unknown');
        };

        // Bucket into [day][locKey][empKey] => running totals.
        $buckets = [];
        $emptyEmployee = [
            'display_name' => '',
            'erp_total' => 0.0, 'erp_count' => 0,
            'clover_total' => 0.0, 'clover_net' => 0.0, 'clover_count' => 0,
            'total_sales' => 0.0, 'net_sales' => 0.0, 'txn_count' => 0,
            'shift_start' => null, 'shift_end' => null, 'shift_status' => null,
            'opening_cash' => null, 'cash_sales' => 0.0,
            'cash_rung' => 0.0,
            'cash_buys' => 0.0, 'collection_buys_all' => 0.0,
            'expected_ending_cash' => null, 'reported_ending_cash' => null,
            'cash_variance' => null, 'has_shift' => false,
            'safe_drop_amount' => 0.0,
        ];
        foreach ($erpRows as $r) {
            $day = $r->day;
            $locKey = $r->location_id ?: 0;
            $empKey = $firstName($r->employee_name);
            $buckets[$day][$locKey]['location_name'] = $r->location_name ?: '(no location)';
            if (!isset($buckets[$day][$locKey]['employees'][$empKey])) {
                $buckets[$day][$locKey]['employees'][$empKey] = ['display_name' => ucfirst($empKey)] + $emptyEmployee;
            }
            $buckets[$day][$locKey]['employees'][$empKey]['erp_total'] += (float) $r->erp_total;
            $buckets[$day][$locKey]['employees'][$empKey]['erp_count'] += (int) $r->erp_count;
        }
        // total_sales aggregation runs over the raw $txns rather than the
        // pre-aggregated $totalRows so we can re-attribute admin-rung
        // sales to the on-shift cashier (Sarah 2026-05-05: "Jon rings up
        // people sometimes, those sales should attribute to the cashier
        // assigned"). Admin-rung sales with no on-shift non-admin cashier
        // at the moment fall through and get dropped — the staff list
        // shouldn't include "owner did one sale" cards.
        $userIdByFirstName = [];
        foreach ($txns as $t) {
            $day = substr((string) $t->transaction_date, 0, 10);
            if ($day < $start || $day > $end) continue; // matching pool only
            $rangBy = $t->cashier_name;
            $rangFn = $firstName($rangBy);
            if (isset($adminSet[$rangFn])) {
                $reassigned = $findShiftCashier($t->transaction_date, $t->location_id);
                if ($reassigned === null) continue;
                $rangBy = $reassigned;
                $rangFn = $firstName($rangBy);
            }
            $locKey = $t->location_id ?: 0;
            if (!isset($buckets[$day][$locKey]['employees'][$rangFn])) {
                $buckets[$day][$locKey]['employees'][$rangFn] = ['display_name' => ucfirst($rangFn)] + $emptyEmployee;
                $buckets[$day][$locKey]['location_name'] = $buckets[$day][$locKey]['location_name'] ?? '(no location)';
            }
            $buckets[$day][$locKey]['employees'][$rangFn]['total_sales']    += (float) $t->final_total;
            // ERP Net = final_total − tax_amount (matches Clover dashboard
            // formula: amount − tax_cents). Sarah 2026-05-11.
            $buckets[$day][$locKey]['employees'][$rangFn]['net_sales']      += (float) $t->final_total - (float) ($t->tax_amount ?? 0);
            $buckets[$day][$locKey]['employees'][$rangFn]['txn_count']      += 1;
            // Capture the first non-admin user_id we see for each first
            // name. Used by the "View {cashier}'s sales" deep link so
            // the recent-sells feed opens already filtered to that user.
            if (!isset($userIdByFirstName[$rangFn]) && $t->cashier_user_id && !isset($adminSet[$firstName($t->cashier_name)])) {
                $userIdByFirstName[$rangFn] = (int) $t->cashier_user_id;
            }
        }

        // Sarah 2026-05-07: "I want to show the whatnot entries we put
        // in the pos". These are sales the cashier rang into the ERP
        // but that don't touch the drawer or Clover (Whatnot stream
        // sales, Discogs, eBay). Pulled separately so they stay out of
        // the drawer-math totals (in_store filter on $txns above) but
        // are visible per cashier so Sarah can verify each cashier
        // entered their channel orders correctly.
        $otherChannelByEmp = [];
        if (\Schema::hasColumn('transactions', 'channel')) {
            $ocQ = \DB::table('transactions as t')
                ->leftJoin('users as u', 't.created_by', '=', 'u.id')
                ->where('t.business_id', $business_id)
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->where('t.channel', '!=', 'in_store')
                ->whereDate('t.transaction_date', '>=', $start)
                ->whereDate('t.transaction_date', '<=', $end);
            if (!empty($location_id)) {
                $ocQ->where('t.location_id', $location_id);
            }
            $ocRows = $ocQ->selectRaw("
                    DATE(t.transaction_date) as day,
                    t.id, t.location_id, t.final_total, t.transaction_date,
                    t.channel,
                    COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, '') as cashier_name
                ")->get();
            foreach ($ocRows as $r) {
                $rangFn = $firstName($r->cashier_name);
                if (isset($adminSet[$rangFn])) {
                    $reassigned = $findShiftCashier($r->transaction_date, $r->location_id);
                    if ($reassigned === null) continue;
                    $rangFn = $firstName($reassigned);
                }
                $key = $r->day . '|' . $rangFn;
                if (!isset($otherChannelByEmp[$key])) $otherChannelByEmp[$key] = [];
                $otherChannelByEmp[$key][] = (object) [
                    'ts' => $r->transaction_date,
                    'amount' => round((float) $r->final_total, 2),
                    'channel' => (string) $r->channel,
                    'transaction_id' => (int) $r->id,
                ];
            }
        }
        foreach ($cloverRows as $r) {
            $day = $r->day;
            $locKey = $r->location_id ?: 0;
            $empKey = $firstName($r->employee_name);
            $buckets[$day][$locKey]['location_name'] = $buckets[$day][$locKey]['location_name']
                ?? ($r->location_name ?: '(unlinked Clover MID)');
            if (!isset($buckets[$day][$locKey]['employees'][$empKey])) {
                $buckets[$day][$locKey]['employees'][$empKey] = ['display_name' => ucfirst($empKey)] + $emptyEmployee;
            }
            $buckets[$day][$locKey]['employees'][$empKey]['clover_total'] += (float) $r->clover_total;
            $buckets[$day][$locKey]['employees'][$empKey]['clover_net']   += (float) ($r->clover_net ?? 0);
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
                        $buckets[$day][$locKey]['employees'][$empKey] = ['display_name' => ucfirst($empKey)] + $emptyEmployee;
                        $buckets[$day][$locKey]['location_name'] = $buckets[$day][$locKey]['location_name']
                            ?? '(no location)';
                    }
                    $e = &$buckets[$day][$locKey]['employees'][$empKey];
                    $e['has_shift'] = true;
                    $e['shift_start'] = $shift['shift_start'];
                    $e['shift_end'] = $shift['shift_end'];
                    $e['shift_status'] = $shift['shift_status'];
                    $e['opening_cash'] = (float) $shift['opening_cash'];
                    $e['cash_buys'] = (float) $shift['cash_buys'];
                    $e['cash_refunds'] = (float) ($shift['cash_refunds'] ?? 0);
                    $e['cash_expenses'] = (float) ($shift['cash_expenses'] ?? 0);
                    $e['cash_transfers_out'] = (float) ($shift['cash_transfers_out'] ?? 0);
                    $e['cash_transfers_in']  = (float) ($shift['cash_transfers_in'] ?? 0);
                    $e['cash_other_net'] = (float) ($shift['cash_other_net'] ?? 0);
                    $e['collection_buys_all'] = (float) $shift['collection_buys_all'];
                    $e['reported_ending_cash'] = (float) $shift['reported_ending_cash'];
                    $e['safe_drop_amount'] = (float) ($shift['safe_drop_amount'] ?? 0);
                    // cash_sales is intentionally derived from total_sales −
                    // clover_total below, NOT from crt.cash_sales: cashiers
                    // ring every sale as 'cash' regardless of how the
                    // customer paid, so crt.cash_sales overstates real cash
                    // by the Clover-paid portion. Same reason expected_
                    // ending_cash is recomputed downstream.
                    unset($e);
                }
            }
        }

        // Customer collection buys — Sarah 2026-05-06: "why are there 0
        // buys for yesterday, we had buys?". Buy-from-customer offers
        // create a transactions row with type='purchase' but never write
        // a cash_register_transactions row, so the CRT-based cash_buys
        // we'd been pulling was always $0. Pull the cash-paid offers
        // directly from buy_customer_offers, re-attribute admin-rung
        // ones to the on-shift cashier, and use them to OVERRIDE
        // cash_buys / collection_buys_all for each bucket.
        $buyQ = \DB::table('transactions as t')
            ->join('buy_customer_offers as o', 'o.accepted_purchase_id', '=', 't.id')
            ->leftJoin('users as u', 't.created_by', '=', 'u.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'purchase')
            ->where('o.payment_method', 'cash_in_store')
            ->whereDate('t.transaction_date', '>=', $start)
            ->whereDate('t.transaction_date', '<=', $end);
        if (!empty($location_id)) {
            $buyQ->where('t.location_id', $location_id);
        }
        $buyRows = $buyQ->selectRaw("
                DATE(t.transaction_date) as day,
                t.location_id,
                t.transaction_date as ts,
                t.final_total as amount,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ', u.first_name, u.last_name)), ''), u.username, '') as cashier_name
            ")->get();

        // Reset CRT-derived cash_buys before applying the real offer-based
        // numbers. collection_buys_all also gets rebuilt because it was
        // previously the "all-method" purchase total from CRT (i.e. zero
        // for these offers).
        foreach ($buckets as $day => &$locsR) {
            foreach ($locsR as $locKey => &$locR) {
                if (empty($locR['employees'])) continue;
                foreach ($locR['employees'] as &$eR) {
                    $eR['cash_buys'] = 0.0;
                    $eR['collection_buys_all'] = 0.0;
                }
                unset($eR);
            }
            unset($locR);
        }
        unset($locsR);

        foreach ($buyRows as $r) {
            $day = substr((string) $r->ts, 0, 10);
            if ($day < $start || $day > $end) continue;
            $cn = $r->cashier_name;
            $cFn = $firstName($cn);
            if (isset($adminSet[$cFn])) {
                $reassigned = $findShiftCashier($r->ts, $r->location_id);
                if ($reassigned === null) continue; // can't attribute
                $cn = $reassigned;
                $cFn = $firstName($reassigned);
            }
            $locKey = $r->location_id ?: 0;
            if (!isset($buckets[$day][$locKey]['employees'][$cFn])) {
                $buckets[$day][$locKey]['employees'][$cFn] = ['display_name' => ucfirst($cFn)] + $emptyEmployee;
                $buckets[$day][$locKey]['location_name'] = $buckets[$day][$locKey]['location_name'] ?? '(no location)';
            }
            $buckets[$day][$locKey]['employees'][$cFn]['cash_buys']           += (float) $r->amount;
            $buckets[$day][$locKey]['employees'][$cFn]['collection_buys_all'] += (float) $r->amount;
        }

        // Drop admin buckets — admin-rung sales were re-attributed to the
        // on-shift cashier above, so any admin first-name still in the
        // bucket got there via Clover-side attribution (a swipe whose
        // employee_name on Clover happened to be an admin) and isn't a
        // real cashier card.
        if (!empty($adminSet)) {
            foreach ($buckets as $day => $locs2) {
                foreach ($locs2 as $locKey => $loc2) {
                    foreach (array_keys($loc2['employees'] ?? []) as $k) {
                        if (isset($adminSet[strtolower($k)])) {
                            unset($buckets[$day][$locKey]['employees'][$k]);
                        }
                    }
                }
            }
        }

        // (no-location merge moved below — must run after details
        //  populate so the lists fold in too)

        // Drop "ghost" cashier buckets — Sarah 2026-05-05: "when I go back
        // to previous day and back to today all new cashiers get added to
        // my screen from yesterday." Cause: a register opened yesterday
        // and never closed will look "open" today, and shift-window
        // attribution credits today's pin-less swipes to that yesterday
        // cashier. They show up here without a real shift today (no float
        // opened, no sales rung) so the card reads as $0/$0. We drop any
        // bucket that has no real activity today.
        foreach ($buckets as $day => $locs2) {
            foreach ($locs2 as $locKey => $loc2) {
                foreach (array_keys($loc2['employees'] ?? []) as $k) {
                    $e = $loc2['employees'][$k];
                    $hasFloat   = !is_null($e['opening_cash']) && (float) $e['opening_cash'] > 0;
                    $rangSales  = ((float) ($e['total_sales']  ?? 0)) > 0;
                    $hasCounted = !is_null($e['reported_ending_cash']) && (float) $e['reported_ending_cash'] > 0;
                    // Also keep buckets that exist only because the cashier
                    // entered Whatnot/Discogs/eBay orders today — Sarah
                    // wants to verify those entries even if the same
                    // cashier didn't open a drawer.
                    $hasOther   = !empty($otherChannelByEmp[$day . '|' . $k] ?? []);
                    if (!$hasFloat && !$rangSales && !$hasCounted && !$hasOther) {
                        unset($buckets[$day][$locKey]['employees'][$k]);
                    }
                }
            }
        }

        // Attach user_id to surviving buckets so the blade can deep-link
        // "View {cashier}'s sales" to the recent-feed page filtered to
        // that user + that day.
        foreach ($buckets as $day => &$locs2) {
            foreach ($locs2 as $locKey => &$loc2) {
                foreach ($loc2['employees'] as $k => &$e) {
                    if (!isset($e['user_id']) && isset($userIdByFirstName[$k])) {
                        $e['user_id'] = $userIdByFirstName[$k];
                    }
                    // Initialize the variance-investigation drill-down
                    // lists. Sarah 2026-05-06: "I need to figure out all
                    // these variances daily." Each card gets a "Show
                    // breakdown" panel listing the unmatched Clover
                    // (over-swipe), unmatched ERP (likely cash), and
                    // cash-paid customer buys, in chronological order.
                    $e['details'] = [
                        'clover_unmatched' => [],
                        'erp_unmatched'    => [],
                        'amount_mismatch'  => [],
                        'matched_clean'    => [],
                        'buys'             => [],
                        'other_channels'   => $otherChannelByEmp[$day . '|' . $k] ?? [],
                    ];
                }
                unset($e);
            }
            unset($loc2);
        }
        unset($locs2);

        // Populate the drill-down: unmatched Clover swipes attributed to
        // this cashier. These are the over-swipe / theft tells — Clover
        // collected the money but no ERP sale of that exact amount
        // exists for this day at this store. Also surface MATCHED pairs
        // whose amounts drift > 5¢ — same sale, but the cashier typed
        // the wrong amount on Clover, so this is the "we charged too
        // little / too much on Clover" tell (Sarah 2026-05-06).
        foreach ($cpRaw as $r) {
            if ($r->employee_name === '') continue;
            $emp = $firstName($r->employee_name);
            if (isset($adminSet[$emp])) continue;
            $day = $r->day instanceof \DateTimeInterface ? $r->day->format('Y-m-d') : (string) $r->day;
            $locKey = $r->location_id ?: 0;
            if (!isset($buckets[$day][$locKey]['employees'][$emp])) continue;
            if (empty($r->matched_txn_id)) {
                $buckets[$day][$locKey]['employees'][$emp]['details']['clover_unmatched'][] = (object) [
                    'ts' => $r->ts,
                    'amount' => round((float) $r->amount, 2),
                ];
            } elseif (abs((int) ($r->matched_diff_cents ?? 0)) > $cleanMatchCents) {
                // Look up the matched ERP txn's amount + id for display.
                $erpAmount = null;
                $erpTxnId = (int) $r->matched_txn_id;
                foreach ($txns as $t) {
                    if ($t->id === $r->matched_txn_id) { $erpAmount = (float) $t->final_total; break; }
                }
                if ($erpAmount !== null) {
                    $buckets[$day][$locKey]['employees'][$emp]['details']['amount_mismatch'][] = (object) [
                        'ts' => $r->ts,
                        'clover_amount' => round((float) $r->amount, 2),
                        'erp_amount'    => round($erpAmount, 2),
                        'diff'          => round(((float) $r->amount) - $erpAmount, 2), // + = Clover over, − = under
                        'transaction_id' => $erpTxnId,
                    ];
                }
            } else {
                // Cleanly-matched pair — same store, same amount, within 5¢.
                // Listed in the drill-down so Sarah can audit every swipe
                // that's contributing to a cashier's Clover total and spot
                // phantoms (e.g. a swipe credited via Clover terminal pin
                // that doesn't actually belong to this cashier).
                $erpTxnId = (int) $r->matched_txn_id;
                $buckets[$day][$locKey]['employees'][$emp]['details']['matched_clean'][] = (object) [
                    'ts' => $r->ts,
                    'amount' => round((float) $r->amount, 2),
                    'transaction_id' => $erpTxnId,
                    'source' => 'amount-match',
                ];
            }
        }

        // Unmatched ERP sales — sales rung but no Clover swipe found.
        // Could be true cash (legitimate) or a missed swipe (suspect).
        // Sarah eyeballs these against her gut feel for which sales
        // were actually card-paid vs cash.
        foreach ($txns as $t) {
            if (isset($claimedTxns[$t->id])) continue;
            $day = substr((string) $t->transaction_date, 0, 10);
            if ($day < $start || $day > $end) continue;
            $emp = $firstName($t->cashier_name);
            if (isset($adminSet[$emp])) {
                $sw = $findShiftCashier($t->transaction_date, $t->location_id);
                if ($sw === null) continue;
                $emp = $firstName($sw);
            }
            $locKey = $t->location_id ?: 0;
            if (!isset($buckets[$day][$locKey]['employees'][$emp])) continue;
            $buckets[$day][$locKey]['employees'][$emp]['details']['erp_unmatched'][] = (object) [
                'ts' => $t->transaction_date,
                'amount' => round((float) $t->final_total, 2),
                'transaction_id' => (int) $t->id,
            ];
        }

        // Cash-paid customer buys — attributed to the on-shift cashier
        // when an admin keyed them.
        foreach ($buyRows as $r) {
            $day = substr((string) $r->ts, 0, 10);
            if ($day < $start || $day > $end) continue;
            $emp = $firstName($r->cashier_name);
            if (isset($adminSet[$emp])) {
                $sw = $findShiftCashier($r->ts, $r->location_id);
                if ($sw === null) continue;
                $emp = $firstName($sw);
            }
            $locKey = $r->location_id ?: 0;
            if (!isset($buckets[$day][$locKey]['employees'][$emp])) continue;
            $buckets[$day][$locKey]['employees'][$emp]['details']['buys'][] = (object) [
                'ts' => $r->ts,
                'amount' => round((float) $r->amount, 2),
            ];
        }

        // Merge no-location (locKey=0) buckets into the same-cashier real-
        // location bucket on the same day — Sarah 2026-05-07: "why is
        // andy showing twice today?". A no-location bucket usually means
        // Clover sync didn't stamp the swipe with a store, but the
        // cashier definitely worked one specific store today (per their
        // cash_register row), so we fold the no-location numbers AND
        // the unmatched-list details into that real-location bucket
        // rather than rendering a phantom second card. Only merges when
        // there's exactly ONE real-location bucket for that cashier —
        // if they genuinely worked at both stores today, both stay.
        foreach ($buckets as $day => $locsM) {
            if (!isset($locsM[0]['employees'])) continue;
            foreach (array_keys($locsM[0]['employees']) as $emp) {
                $targets = [];
                foreach ($locsM as $lk => $loc) {
                    if ($lk === 0) continue;
                    if (isset($loc['employees'][$emp])) $targets[] = $lk;
                }
                if (count($targets) !== 1) continue;
                $tgt = $targets[0];
                $src = $buckets[$day][0]['employees'][$emp];
                $dst = &$buckets[$day][$tgt]['employees'][$emp];
                foreach (['total_sales','clover_total','clover_count','txn_count',
                         'cash_buys','cash_refunds','cash_expenses',
                         'cash_transfers_out','cash_transfers_in','cash_other_net',
                         'collection_buys_all'] as $k) {
                    $dst[$k] = ((float) ($dst[$k] ?? 0)) + ((float) ($src[$k] ?? 0));
                }
                foreach (['clover_unmatched','erp_unmatched','amount_mismatch','buys','other_channels'] as $k) {
                    $dst['details'][$k] = array_merge(
                        $dst['details'][$k] ?? [],
                        $src['details'][$k] ?? []
                    );
                }
                unset($dst);
                unset($buckets[$day][0]['employees'][$emp]);
            }
            if (empty($buckets[$day][0]['employees'])) {
                unset($buckets[$day][0]);
            }
        }

        // Order each list by time so the panel reads top-to-bottom.
        foreach ($buckets as $day => &$locsD) {
            foreach ($locsD as $locKey => &$locD) {
                foreach ($locD['employees'] as &$eD) {
                    foreach (['clover_unmatched', 'erp_unmatched', 'amount_mismatch', 'buys', 'other_channels'] as $key) {
                        $list = $eD['details'][$key] ?? [];
                        usort($list, function ($a, $b) {
                            return strcmp((string) $a->ts, (string) $b->ts);
                        });
                        $eD['details'][$key] = $list;
                    }
                }
                unset($eD);
            }
            unset($locD);
        }
        unset($locsD);

        // Derived numbers. Sarah 2026-05-11: cash drawer math now uses
        // cash_rung (real tp.method='cash' sum) instead of the implied
        // gap, since cash is rung on Clover too at Nivessa and the gap
        // isn't cash income. The gap is still surfaced separately as a
        // reconciliation anomaly indicator on the "What they sold" line.
        foreach ($buckets as $day => &$locs) {
            foreach ($locs as $locKey => &$loc) {
                if (!isset($loc['employees'])) continue;
                foreach ($loc['employees'] as &$e) {
                    $empKey = strtolower(trim(explode(' ', $e['display_name'] ?? '')[0] ?? ''));
                    $cashKey = $day . '|' . ($locKey ?: 0) . '|' . $empKey;
                    $cashRung = (float) ($cashByKey[$cashKey] ?? 0);
                    $e['cash_rung'] = round($cashRung, 2);
                    $impliedCash = max(0.0, round(((float) $e['total_sales']) - ((float) $e['clover_total']), 2));
                    $e['cash_sales'] = $impliedCash; // legacy alias = the gap
                    if ($e['has_shift'] && !is_null($e['opening_cash'])) {
                        $cashOut = ((float) ($e['cash_buys']           ?? 0))
                                 + ((float) ($e['cash_refunds']        ?? 0))
                                 + ((float) ($e['cash_expenses']       ?? 0))
                                 + ((float) ($e['cash_transfers_out']  ?? 0))
                                 + ((float) ($e['safe_drop_amount']    ?? 0));
                        $cashIn  = ((float) ($e['cash_transfers_in']   ?? 0))
                                 + ((float) ($e['cash_other_net']      ?? 0));
                        $e['expected_ending_cash'] = round(
                            ((float) $e['opening_cash']) + $cashRung - $cashOut + $cashIn,
                            2
                        );
                        if ($e['shift_status'] === 'closed' && !is_null($e['reported_ending_cash'])) {
                            $e['cash_variance'] = round(
                                ((float) $e['reported_ending_cash']) - ((float) $e['expected_ending_cash']),
                                2
                            );
                        } else {
                            $e['cash_variance'] = null;
                        }
                    }
                }
                unset($e);
            }
            unset($loc);
        }
        unset($locs);

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
        // Mixed-TZ tolerant for the LA $day.
        $dayWin = $this->cloverPaidAtIstWindow($day, $day);
        $cloverQ = \DB::table('clover_payments as cp')
            ->leftJoin('business_locations as bl', 'cp.location_id', '=', 'bl.id')
            ->where('cp.business_id', $business_id)
            ->where(function ($q) {
                $q->whereNull('cp.result')->orWhere('cp.result', 'SUCCESS')->orWhere('cp.result', 'APPROVED');
            })
            ->whereRaw($this->cloverCreatedInWindowSql($dayWin, 'cp'));
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
                'clover_total' => 0.0, 'clover_net' => 0.0, 'clover_count' => 0,
                'total_sales' => 0.0, 'net_sales' => 0.0, 'txn_count' => 0,
                'cash_rung' => 0.0,
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
            $byLoc[$locKey]['employees'][$empKey]['clover_net']   += (float) ($r->clover_net ?? 0);
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
            case 'last_week':
                // Previous full calendar week (Mon–Sun) — the Week-2 baseline window.
                $start = $now->copy()->subWeek()->startOfWeek();
                $end = $now->copy()->subWeek()->endOfWeek();
                break;
            case 'custom':
                // Manual date range. Falls back to this-month if either date is
                // missing or unparseable.
                try {
                    $sd = $request->input('start_date');
                    $ed = $request->input('end_date');
                    if (!empty($sd) && !empty($ed)) {
                        $start = \Carbon::parse($sd)->startOfDay();
                        $end   = \Carbon::parse($ed)->endOfDay();
                        if ($end->lt($start)) { $tmp = $start; $start = $end->copy()->startOfDay(); $end = $tmp->copy()->endOfDay(); }
                    } else {
                        $start = $now->copy()->startOfMonth();
                        $end   = $now->copy()->endOfDay();
                        $period = 'this_month';
                    }
                } catch (\Throwable $e) {
                    $start = $now->copy()->startOfMonth();
                    $end   = $now->copy()->endOfDay();
                    $period = 'this_month';
                }
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

        // Goal auto-adjusts per person from their own recent trajectory (see
        // buildLeaderboardRows). Deliberately not an editable knob — the target
        // is always realistic and motivating without manual upkeep (Sarah
        // 2026-06-02).
        $opts = ['with_commission' => true, 'exclude_owners' => true];

        // Both stores side by side: one ranked table per active location.
        // Whatnot is excluded from every revenue total here (Sarah 2026-06-02).
        $locations = \DB::table('business_locations')
            ->where('business_id', $business_id)
            ->where('is_active', 1)
            ->orderBy('id')
            ->pluck('name', 'id');

        // Live trading-day KPIs per store, embedded above each ranking so the
        // leaderboard doubles as the leadership screen — today's pace and last
        // week's recognition in one place. Reuses StorePerformanceController so
        // the two screens never drift. Page is already admin-only. Built
        // defensively: a KPI failure just hides the strip, never breaks the
        // leaderboard.
        $sp = null;
        try {
            $sp = app()->make(\App\Http\Controllers\StorePerformanceController::class);
        } catch (\Throwable $e) {
            \Log::warning('leaderboard live-KPI controller resolve failed: ' . $e->getMessage());
        }

        $stores = [];
        foreach ($locations as $lid => $lname) {
            $live = null;
            if ($sp) {
                try {
                    $live = $sp->computeForLocation($business_id, $lid);
                } catch (\Throwable $e) {
                    \Log::warning('leaderboard live-KPI compute failed: ' . $e->getMessage());
                }
            }

            $rows = $this->buildLeaderboardRows($business_id, $start_str, $end_str, null, $lid, $opts);
            $rows = $this->applyStoreRoster($rows, $lname);
            // Per-person hour-based target from this store's own historical
            // hourly curve (this is what the 2% sales bonus pays on).
            $rows = $this->attachHourTargets($rows, $business_id, $lid, $start_str, $end_str);

            $stores[] = [
                'id'   => $lid,
                'name' => $lname,
                'rows' => $rows,
                'live' => $live,
            ];
        }

        $live_data_url = action('StorePerformanceController@data');
        $listed_items_url = action('ReportController@employeeLeaderboardListedItems');

        return view('report.employee_leaderboard')->with(compact(
            'stores', 'period', 'start', 'end', 'live_data_url', 'listed_items_url'
        ));
    }

    /**
     * Per-store roster overrides. The board files people by where their sales
     * rang, which can misplace cross-location strays; these keep each store's
     * list to who actually works there. First-name match, case-insensitive, as
     * a prefix so stored variants still hit (e.g. 'zak' catches "Zakary"). The
     * store key just needs to appear in the location name. '*' hides someone
     * from every store floor; a store_only whitelist wins outright. Roster per
     * Sarah 2026-06-02; edit freely. Shared by the leaderboard + targets list.
     */
    private function applyStoreRoster($rows, $lname)
    {
        $store_hidden = [
            '*'         => ['nerdy', 'viper', 'henry', 'nick'],      // not store-floor staff (Nick = fulfillment, Henry left, nerdy/viper = non-floor accounts)
            'hollywood' => ['zak', 'alec', 'clark', 'andy', 'davis'], // these work Pico — drop 'andy' here if he's reassigned to HW
            'pico'      => ['clyde', 'jennifer', 'manolo', 'luis', 'jacob'], // Clyde/Jennifer work HW; manolo/luis/jacob old/remote
        ];
        $store_only = [
            'discogs'   => ['nick'],        // online fulfillment is Nick only
            'warehouse' => ['nick'],
        ];

        $lkey = strtolower((string) $lname);
        $hide = $store_hidden['*'] ?? [];
        $only = null;
        foreach ($store_hidden as $k => $names) {
            if ($k === '*') { continue; }
            if (strpos($lkey, $k) !== false) { $hide = array_merge($hide, $names); }
        }
        foreach ($store_only as $k => $names) {
            if (strpos($lkey, $k) !== false) { $only = array_merge($only ?? [], $names); }
        }
        $nameMatches = function ($first, $list) {
            foreach ($list as $tok) {
                if ($first === $tok || strpos($first, $tok) === 0) { return true; }
            }
            return false;
        };
        return $rows->filter(function ($r) use ($hide, $only, $nameMatches) {
            $first = strtolower(trim(explode(' ', trim($r->employee))[0] ?? ''));
            if ($only !== null) { return $nameMatches($first, $only); }
            return !$nameMatches($first, $hide);
        })->values();
    }

    /**
     * Clean, printable per-employee shift-targets list (Sarah 2026-06-02).
     * Same data as the leaderboard's Hour target — each active person's target
     * for the selected period, per store — but stripped to just what you'd post
     * or hand out: name, hours, peak/off split, target, sales so far, pace, and
     * the bonus they're on track for. Reuses the exact same builder + roster +
     * hour-target math so the numbers match the board. Admin-only.
     */
    public function shiftTargets(Request $request)
    {
        $this->ensureAdminOnlyReportAccess();
        $business_id = $request->session()->get('user.business_id');
        $period = $request->input('period', 'this_month');

        $now = \Carbon::now();
        switch ($period) {
            case 'today':      $start = $now->copy()->startOfDay();              $end = $now->copy()->endOfDay(); break;
            case 'yesterday':  $start = $now->copy()->subDay()->startOfDay();    $end = $now->copy()->subDay()->endOfDay(); break;
            case 'this_week':  $start = $now->copy()->startOfWeek();             $end = $now->copy()->endOfDay(); break;
            case 'last_week':  $start = $now->copy()->subWeek()->startOfWeek();  $end = $now->copy()->subWeek()->endOfWeek(); break;
            case 'last_7':     $start = $now->copy()->subDays(6)->startOfDay();  $end = $now->copy()->endOfDay(); break;
            case 'last_30':    $start = $now->copy()->subDays(29)->startOfDay(); $end = $now->copy()->endOfDay(); break;
            default:           $start = $now->copy()->startOfMonth();            $end = $now->copy()->endOfDay(); $period = 'this_month'; break;
        }
        $start_str = $start->toDateTimeString();
        $end_str   = $end->toDateTimeString();
        $opts = ['with_commission' => true, 'exclude_owners' => true];

        $locations = \DB::table('business_locations')
            ->where('business_id', $business_id)
            ->where('is_active', 1)
            ->orderBy('id')
            ->pluck('name', 'id');

        $stores = [];
        foreach ($locations as $lid => $lname) {
            $rows = $this->buildLeaderboardRows($business_id, $start_str, $end_str, null, $lid, $opts);
            $rows = $this->applyStoreRoster($rows, $lname);
            $rows = $this->attachHourTargets($rows, $business_id, $lid, $start_str, $end_str);
            // Only people who actually worked the period have a target to post.
            $rows = $rows->filter(function ($r) { return $r->hours_worked > 0; })
                ->sortByDesc(function ($r) { return $r->hour_target ?? 0; })
                ->values();
            $stores[] = ['id' => $lid, 'name' => $lname, 'rows' => $rows];
        }

        return view('report.shift_targets')->with(compact('stores', 'period', 'start', 'end'));
    }

    /**
     * Why one person's goal is what it is: for each hour they were clocked in,
     * show what the store HISTORICALLY rings in that weekday+hour slot (the same
     * 12-week rate that drives the target), their fair share of it, and how the
     * day's target + bonus fall out. Answers "what do cashiers usually do at that
     * time?" with the actual per-slot numbers. Admin-only. Reuses the exact
     * profile + slot math from attachHourTargets so it reconciles with the
     * Shift Targets list.
     */
    public function shiftTargetBreakdown(Request $request)
    {
        $this->ensureAdminOnlyReportAccess();
        $business_id = $request->session()->get('user.business_id');
        $userId = (int) $request->input('user_id');
        $locationId = (int) $request->input('location_id');
        $period = $request->input('period', 'this_month');

        $now = \Carbon::now();
        switch ($period) {
            case 'today':      $start = $now->copy()->startOfDay();              $end = $now->copy()->endOfDay(); break;
            case 'yesterday':  $start = $now->copy()->subDay()->startOfDay();    $end = $now->copy()->subDay()->endOfDay(); break;
            case 'this_week':  $start = $now->copy()->startOfWeek();             $end = $now->copy()->endOfDay(); break;
            case 'last_week':  $start = $now->copy()->subWeek()->startOfWeek();  $end = $now->copy()->subWeek()->endOfWeek(); break;
            case 'last_7':     $start = $now->copy()->subDays(6)->startOfDay();  $end = $now->copy()->endOfDay(); break;
            case 'last_30':    $start = $now->copy()->subDays(29)->startOfDay(); $end = $now->copy()->endOfDay(); break;
            default:           $start = $now->copy()->startOfMonth();            $end = $now->copy()->endOfDay(); $period = 'this_month'; break;
        }
        $startC = $start; $endC = $end;
        $start_str = $start->toDateTimeString();
        $end_str   = $end->toDateTimeString();

        $user = \DB::table('users')->where('id', $userId)->first();
        $userName = $user ? (trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: ($user->username ?? "User #{$userId}")) : "User #{$userId}";
        $locName = \DB::table('business_locations')->where('id', $locationId)->value('name') ?: ('Location #' . $locationId);

        $profile = $this->storeHourlyProfile($business_id, $locationId, 12);
        $rate = $profile['rate'];
        $stretch = 0.10;

        // All sessions at this location (every user) so we can fair-share each
        // hour by how many staff were on the floor — same as attachHourTargets.
        $sessions = \DB::table('cash_registers')
            ->where('business_id', $business_id)
            ->where('location_id', $locationId)
            ->whereNotNull('user_id')
            ->where('created_at', '<=', $end_str)
            ->where(function ($q) use ($start_str) {
                $q->where('closed_at', '>=', $start_str)->orWhereNull('closed_at');
            })
            ->select('user_id', 'created_at', 'closed_at')
            ->get();

        $slotStaff = [];   // 'Y-m-d H' => [user_id => true]
        $myCov = [];
        foreach ($sessions as $s) {
            $ss = \Carbon::parse($s->created_at);
            if ($ss->lt($startC)) { $ss = $startC->copy(); }
            $se = $s->closed_at ? \Carbon::parse($s->closed_at) : $now->copy();
            if ($se->gt($endC)) { $se = $endC->copy(); }
            $cap = $ss->copy()->addSeconds(21600);
            if ($se->gt($cap)) { $se = $cap; }
            if ($se->lte($ss)) { continue; }
            $cursor = $ss->copy();
            while ($cursor->lt($se)) {
                $slotEnd = $cursor->copy()->startOfHour()->addHour();
                $chunkEnd = $slotEnd->lt($se) ? $slotEnd : $se;
                $frac = $cursor->diffInSeconds($chunkEnd) / 3600.0;
                $key = ($cursor->dayOfWeek + 1) . '-' . $cursor->hour;
                $inst = $cursor->format('Y-m-d H');
                $slotStaff[$inst][$s->user_id] = true;
                if ((int) $s->user_id === $userId) {
                    $myCov[] = ['key' => $key, 'frac' => $frac, 'inst' => $inst, 'date' => substr($inst, 0, 10), 'hour' => $cursor->hour];
                }
                $cursor = $chunkEnd;
            }
        }

        // Per-day, per-slot breakdown for this person.
        $days = [];
        foreach ($myCov as $c) {
            $head = max(1, count($slotStaff[$c['inst']] ?? []));
            $storeRate = (float) ($rate[$c['key']] ?? 0);
            $share = $storeRate / $head;
            $expected = $share * $c['frac'];
            $d = $c['date'];
            if (!isset($days[$d])) { $days[$d] = ['date' => $d, 'slots' => [], 'expected' => 0.0]; }
            $days[$d]['slots'][] = [
                'hour' => $c['hour'], 'frac' => $c['frac'], 'head' => $head,
                'store_rate' => $storeRate, 'share' => $share, 'expected' => $expected,
            ];
            $days[$d]['expected'] += $expected;
        }

        // Actual non-whatnot sales per day for this person at this location.
        $net_pretax = '(tsl.quantity - COALESCE(tsl.quantity_returned, 0)) * (tsl.unit_price_inc_tax - COALESCE(tsl.item_tax, 0))';
        $daySales = [];
        foreach (\DB::table('transactions as t')
            ->join('transaction_sell_lines as tsl', 'tsl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->where('t.location_id', $locationId)
            ->where('t.created_by', $userId)
            ->where('t.type', 'sell')->where('t.status', 'final')->whereNull('t.import_source')
            ->where(function ($q) { $q->where('t.is_whatnot', 0)->orWhereNull('t.is_whatnot'); })
            ->whereBetween('t.transaction_date', [$start_str, $end_str])
            ->selectRaw("DATE(t.transaction_date) as d, COALESCE(SUM($net_pretax), 0) as rev")
            ->groupBy(\DB::raw('DATE(t.transaction_date)'))
            ->get() as $row) {
            $daySales[$row->d] = (float) $row->rev;
        }

        ksort($days);
        $rows = [];
        foreach ($days as $d => $day) {
            $target = $day['expected'] * (1 + $stretch);
            $sold = (float) ($daySales[$d] ?? 0);
            $bonus = $sold > $target ? ($sold - $target) * 0.02 : 0.0;
            $rows[] = (object) [
                'date'     => $d,
                'slots'    => $day['slots'],
                'expected' => round($day['expected'], 2),
                'target'   => round($target, 2),
                'sold'     => round($sold, 2),
                'bonus'    => round($bonus, 2),
            ];
        }

        return view('report.shift_target_breakdown', [
            'user_name' => $userName,
            'loc_name'  => $locName,
            'period'    => $period,
            'start'     => $start,
            'end'       => $end,
            'stretch_pct' => $stretch * 100,
            'rows'      => $rows,
            'total_expected' => round(array_sum(array_map(function ($r) { return $r->expected; }, $rows)), 2),
            'total_target'   => round(array_sum(array_map(function ($r) { return $r->target; }, $rows)), 2),
            'total_sold'     => round(array_sum(array_map(function ($r) { return $r->sold; }, $rows)), 2),
            'total_bonus'    => round(array_sum(array_map(function ($r) { return $r->bonus; }, $rows)), 2),
        ]);
    }

    /**
     * Drill-down for the leaderboard: the individual products a person listed
     * (products.created_by) that sold in the window, optionally at one store,
     * best-sellers first. Powers the "items listed / sales from listed"
     * click-through so staff can see what of theirs actually sells. Mirrors the
     * priced_revenue query on the board so the totals reconcile. Admin-only JSON.
     */
    public function employeeLeaderboardListedItems(Request $request)
    {
        $this->ensureAdminOnlyReportAccess();
        $business_id = $request->session()->get('user.business_id');

        $user_id     = (int) $request->input('user_id');
        $location_id = (int) $request->input('location_id', 0);

        // Window resolved on the board and passed through as explicit dates.
        try {
            $start = \Carbon::parse($request->input('start_date'))->startOfDay();
            $end   = \Carbon::parse($request->input('end_date'))->endOfDay();
        } catch (\Throwable $e) {
            $start = \Carbon::now()->startOfMonth();
            $end   = \Carbon::now()->endOfDay();
        }

        $q = \DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('products as p', 'tsl.product_id', '=', 'p.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('categories as sc', 'p.sub_category_id', '=', 'sc.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('t.import_source')
            ->where('p.created_by', $user_id)
            // Same May-15 rollout gate as barcodingCommissionByUser, so this
            // drill only shows commission-ELIGIBLE items and its revenue × 2%
            // reconciles with the commission on the board. Without it the list
            // also showed pre-rollout listings that don't earn (Sarah 2026-06-21).
            ->where('p.created_at', '>=', '2026-05-15 00:00:00')
            ->whereBetween('t.transaction_date', [$start->toDateTimeString(), $end->toDateTimeString()]);
        // USED items only, mirroring the board's priced_revenue query so totals reconcile.
        $this->applyUsedItemCategoryFilter($q);
        if (!empty($location_id)) {
            $q->where('t.location_id', $location_id);
        }

        $items = $q
            ->selectRaw('p.name as product, COALESCE(SUM(tsl.quantity - COALESCE(tsl.quantity_returned, 0)), 0) as units, COALESCE(SUM((tsl.quantity - COALESCE(tsl.quantity_returned, 0)) * (tsl.unit_price_inc_tax - COALESCE(tsl.item_tax, 0))), 0) as revenue')
            ->groupBy('p.id', 'p.name')
            ->orderByDesc('revenue')
            ->limit(200)
            ->get()
            ->map(function ($r) {
                $revenue = round((float) $r->revenue, 2);
                return [
                    'product'    => $r->product,
                    'units'      => (int) $r->units,
                    'revenue'    => $revenue,
                    'commission' => round($revenue * 0.02, 2),
                ];
            });

        $totalRevenue = round($items->sum('revenue'), 2);
        return response()->json([
            'items'            => $items,
            'total_units'      => (int) $items->sum('units'),
            'total_revenue'    => $totalRevenue,
            'total_commission' => round($totalRevenue * 0.02, 2),
        ]);
    }

    /**
     * Internal: build the employee leaderboard rows for a business + window.
     * Ranked by revenue per hour — hours come from cash_registers open/close.
     * Returned as a keyed-by-user Collection so it can power both the full
     * page and the dashboard top-3 widget.
     */
    public function buildLeaderboardRows($business_id, $start, $end, $limit = null, $location_id = null, array $opts = [])
    {
        $with_commission = !empty($opts['with_commission']);
        $exclude_owners  = !empty($opts['exclude_owners']);

        // Hours worked per user in this window, derived from cash_registers.
        // A register's "shift" is created_at -> closed_at (or NOW() if still open),
        // clipped to [start, end]. Optionally scoped to a single location.
        $hours_q = \DB::table('cash_registers')
            ->where('business_id', $business_id)
            ->whereNotNull('user_id')
            ->where(function ($q) use ($start, $end) {
                $q->where('created_at', '<=', $end)
                  ->where(function ($q2) use ($start) {
                      $q2->where('closed_at', '>=', $start)
                         ->orWhereNull('closed_at');
                  });
            });
        if (!empty($location_id)) {
            $hours_q->where('location_id', $location_id);
        }
        $hours_raw = $hours_q
            ->selectRaw("user_id,
                SUM(
                    LEAST(
                        TIMESTAMPDIFF(
                            SECOND,
                            GREATEST(created_at, ?),
                            LEAST(COALESCE(closed_at, NOW()), ?)
                        ),
                        21600
                    )
                ) / 3600.0 as hours")
            ->addBinding($start, 'select')
            ->addBinding($end, 'select')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        // Revenue basis for the whole board, since these figures drive
        // commission (Sarah 2026-06-02): per line, PRE-TAX and NET OF RETURNS —
        //   (qty sold - qty returned) * (unit price inc tax - per-unit tax).
        // Pre-tax so we never pay commission on sales tax we remit; net of
        // returns so refunded items don't count. Line-level (not final_total)
        // because that's the only grain where returns net cleanly. Order-level
        // invoice discounts / shipping are not in this figure — fine for floor
        // sales, which are item-priced.
        $net_pretax = '(tsl.quantity - COALESCE(tsl.quantity_returned, 0)) * (tsl.unit_price_inc_tax - COALESCE(tsl.item_tax, 0))';

        // Transaction aggregates per employee (created_by). Whatnot is kept
        // separate so every "total" can exclude it (Sarah 2026-06-02).
        $tx_q = \DB::table('transactions as t')
            ->join('transaction_sell_lines as tsl', 'tsl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('t.import_source')
            ->whereBetween('t.transaction_date', [$start, $end]);
        if (!empty($location_id)) {
            $tx_q->where('t.location_id', $location_id);
        }
        $tx_agg = $tx_q
            ->selectRaw("t.created_by,
                COALESCE(SUM($net_pretax), 0) as revenue,
                COALESCE(SUM(CASE WHEN t.is_whatnot = 1 THEN $net_pretax ELSE 0 END), 0) as whatnot_revenue,
                COUNT(DISTINCT CASE WHEN t.is_whatnot = 1 THEN NULL ELSE t.id END) as nw_tx_count")
            ->groupBy('t.created_by')
            ->get()
            ->keyBy('created_by');

        // Items rung per employee — non-whatnot lines only.
        $items_q = \DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('t.import_source')
            ->where(function ($q) { $q->where('t.is_whatnot', 0)->orWhereNull('t.is_whatnot'); })
            ->whereBetween('t.transaction_date', [$start, $end]);
        if (!empty($location_id)) {
            $items_q->where('t.location_id', $location_id);
        }
        $items_agg = $items_q
            ->selectRaw('t.created_by, COALESCE(SUM(tsl.quantity), 0) as items_rung')
            ->groupBy('t.created_by')
            ->get()
            ->keyBy('created_by');

        // Revenue from items priced by the user, sold in this window. USED items
        // only — sealed/new vinyl/CD/cassette and non-record categories are
        // excluded so the board counts used listings, not new (Sarah 2026-06-03).
        $priced_rev_q = \DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('products as p', 'tsl.product_id', '=', 'p.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('categories as sc', 'p.sub_category_id', '=', 'sc.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('t.import_source')
            ->whereNotNull('p.created_by')
            // Match barcodingCommissionByUser's May-15 rollout gate so the
            // board's "Items listed" / "Sales from listed" describe the same
            // commission-eligible set as "Listing pay" and reconcile with it
            // (without this, sales-from-listed showed pre-rollout listings that
            // earn no commission — Sarah 2026-06-21).
            ->where('p.created_at', '>=', '2026-05-15 00:00:00')
            ->whereBetween('t.transaction_date', [$start, $end]);
        $this->applyUsedItemCategoryFilter($priced_rev_q);
        if (!empty($location_id)) {
            $priced_rev_q->where('t.location_id', $location_id);
        }
        $priced_rev = $priced_rev_q
            ->selectRaw("p.created_by, COALESCE(SUM($net_pretax), 0) as priced_revenue, COALESCE(SUM(tsl.quantity - COALESCE(tsl.quantity_returned, 0)), 0) as priced_sold_count")
            ->groupBy('p.created_by')
            ->get()
            ->keyBy('created_by');

        // Barcoding commission (2% of used items each person barcoded that sold)
        // is only computed for the report, not the lightweight home widget. The
        // sales-goal bonus now pays on each person's hour-based target (the
        // store's historical rate for the exact hours they worked) — that is
        // layered on in attachHourTargets, not here, because it needs the
        // per-store hourly curve and clocked-in headcount (Sarah 2026-06-02).
        $commission = collect();
        $listingSummary = collect();
        if ($with_commission) {
            $commission = $this->barcodingCommissionByUser($business_id, $start, $end, $location_id);
            // Cumulative since-rollout earned/paid/owed, identical source to
            // /admin/listing-commissions, so the board reconciles with the
            // payables page (Sarah 2026-06-21). Global (all stores) — payouts
            // aren't location-tagged — so it matches the single owed page.
            $listingSummary = app(\App\Http\Controllers\ListingCommissionController::class)
                ->summaryByUser($business_id);
        }

        // Merge keys from every side.
        $user_ids = collect($tx_agg->keys())
            ->merge($items_agg->keys())
            ->merge($priced_rev->keys())
            ->merge($commission->keys())
            ->unique()
            ->values();

        // CURRENT staff only. Per the offboarding convention an ex-employee is
        // status='active' but allow_login=0, so "current" requires BOTH
        // status=active AND allow_login=1 (Sarah 2026-06-02) — otherwise people
        // who've been let go still show on the board. When requested, also drop
        // owners/back-office (Jon/Sarah, Sohaib, Fatteen) so the report shows
        // the sales floor only.
        $users_q = \App\User::whereIn('id', $user_ids)
            ->where('status', 'active')
            ->where('allow_login', 1);
        if ($exclude_owners) {
            $excluded_first = ['jon', 'jonathan', 'sarah', 'sohaib', 'fatteen'];
            $users_q->whereNotIn(\DB::raw('LOWER(first_name)'), $excluded_first)
                    // Fatteen's account is named "Nerdy Solutions", so first_name
                    // alone misses it — also drop any name containing "nerdy".
                    ->whereRaw("LOWER(TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''),' ',COALESCE(surname,'')))) NOT LIKE '%nerdy%'")
                    ->where(function ($q) {
                        $q->whereNull('email')->orWhere('email', '!=', 'sarah@nivessa.com');
                    });
        }
        $users = $users_q->get()->keyBy('id');

        $user_ids = $user_ids->filter(fn ($uid) => $users->has($uid))->values();

        $rows = $user_ids->map(function ($uid) use ($tx_agg, $items_agg, $priced_rev, $users, $hours_raw, $commission, $listingSummary, $with_commission) {
            $u = $users->get($uid);
            $t = $tx_agg->get($uid);
            $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
            $hours = (float) (optional($hours_raw->get($uid))->hours ?? 0);
            $revenue = (float) ($t->revenue ?? 0);
            $whatnot_revenue = (float) ($t->whatnot_revenue ?? 0);
            $non_whatnot_revenue = max($revenue - $whatnot_revenue, 0);
            $items_rung = (int) optional($items_agg->get($uid))->items_rung ?? 0;
            $tx_count = (int) ($t->nw_tx_count ?? 0); // non-whatnot transactions
            // Units from items this person listed that SOLD in the window — pairs
            // with priced_revenue so the count and the dollars describe the same set.
            $priced_count = (int) optional($priced_rev->get($uid))->priced_sold_count ?? 0;

            // Minimum 0.25h when normalizing so very short shifts don't make
            // absurd per-hour numbers; no register activity => null (UI "—").
            $hr_eff = $hours >= 0.25 ? $hours : null;

            $barcoding_commission = (float) optional($commission->get($uid))->commission ?? 0;

            // Cumulative listing pay since rollout (matches /admin/listing-commissions).
            $ls = $listingSummary->get($uid);
            $listing_earned = (float) optional($ls)->earned ?? 0;
            $listing_paid   = (float) optional($ls)->paid ?? 0;
            $listing_owed   = (float) optional($ls)->owed ?? 0;

            // Sales-goal target + bonus are hour-based now and filled in by
            // attachHourTargets (it owns the store hourly curve + headcount).
            // Seed them here so the row shape is stable for the home widget,
            // which never calls attachHourTargets.
            $goal = null;
            $goal_hit = false;
            $goal_bonus = 0.0;
            $goal_stretch_pct = null;
            $total_commission = round($barcoding_commission, 2);

            return (object) [
                'user_id' => $uid,
                'employee' => trim($name) ?: '(unknown)',
                'tx_count' => $tx_count,
                'items_rung' => $items_rung,
                'revenue' => $revenue,
                'whatnot_revenue' => $whatnot_revenue,
                'non_whatnot_revenue' => $non_whatnot_revenue,
                'avg_tx' => $tx_count > 0 ? $non_whatnot_revenue / $tx_count : 0.0,
                'priced_count' => $priced_count,
                'priced_revenue' => (float) optional($priced_rev->get($uid))->priced_revenue ?? 0,
                'hours_worked' => $hours,
                // Per-hour metrics rank on non-whatnot revenue (whatnot excluded).
                'revenue_per_hour' => $hr_eff ? $non_whatnot_revenue / $hr_eff : null,
                'items_per_hour'   => $hr_eff ? $items_rung / $hr_eff : null,
                'tx_per_hour'      => $hr_eff ? $tx_count / $hr_eff : null,
                'priced_per_hour'  => $hr_eff ? $priced_count / $hr_eff : null,
                'barcoding_commission' => $barcoding_commission,
                'listing_earned' => $listing_earned,
                'listing_paid' => $listing_paid,
                'listing_owed' => $listing_owed,
                'goal' => $goal,
                'goal_hit' => $goal_hit,
                'goal_bonus' => $goal_bonus,
                'goal_stretch_pct' => $goal_stretch_pct,
                'total_commission' => $total_commission,
            ];
        })
        // Primary sort: revenue per hour (null goes last).
        ->sortBy(function ($r) { return $r->revenue_per_hour === null ? -1 : $r->revenue_per_hour; }, SORT_REGULAR, true)
        ->values();

        if ($limit) {
            $rows = $rows->take($limit);
        }

        return $rows;
    }

    /**
     * Store hourly profile: average non-whatnot, pre-tax/net revenue earned in
     * every (weekday x hour) slot over the last $weeks weeks. This curve is the
     * store's own peak map — slots at/above the store's median hourly rate are
     * "peak", below are "off-peak". Used to set per-person targets from the
     * exact hours they worked (Sarah 2026-06-02). Same revenue basis as the
     * rest of the board so the numbers reconcile.
     *
     * Returns ['rate' => ['{dow}-{hr}' => $/slot], 'median' => x,
     *          'peak' => ['{dow}-{hr}' => true]] where dow is MySQL DAYOFWEEK
     * (Sun=1..Sat=7) so it lines up with Carbon's dayOfWeek+1.
     */
    private function storeHourlyProfile($business_id, $location_id, $weeks = 12)
    {
        $start = \Carbon::now()->subWeeks($weeks)->startOfDay()->toDateTimeString();
        $end   = \Carbon::now()->subDay()->endOfDay()->toDateTimeString();
        $net_pretax = '(tsl.quantity - COALESCE(tsl.quantity_returned, 0)) * (tsl.unit_price_inc_tax - COALESCE(tsl.item_tax, 0))';

        $rows = \DB::table('transactions as t')
            ->join('transaction_sell_lines as tsl', 'tsl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->where('t.location_id', $location_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('t.import_source')
            ->where(function ($q) { $q->where('t.is_whatnot', 0)->orWhereNull('t.is_whatnot'); })
            ->whereBetween('t.transaction_date', [$start, $end])
            ->selectRaw("DAYOFWEEK(t.transaction_date) as dow, HOUR(t.transaction_date) as hr,
                SUM($net_pretax) as rev, COUNT(DISTINCT DATE(t.transaction_date)) as days")
            ->groupBy('dow', 'hr')
            ->get();

        $rate = [];
        $vals = [];
        foreach ($rows as $r) {
            $days = max(1, (int) $r->days);          // how many of that weekday actually traded that hour
            $rateVal = (float) $r->rev / $days;      // avg $ the store takes in that exact slot
            $rate[$r->dow . '-' . $r->hr] = $rateVal;
            if ($rateVal > 0) { $vals[] = $rateVal; }
        }

        sort($vals);
        $n = count($vals);
        $median = 0.0;
        if ($n > 0) {
            $mid = intdiv($n, 2);
            $median = $n % 2 ? $vals[$mid] : ($vals[$mid - 1] + $vals[$mid]) / 2;
        }

        $peak = [];
        if ($median > 0) {
            foreach ($rate as $k => $v) {
                if ($v >= $median) { $peak[$k] = true; }
            }
        }

        return ['rate' => $rate, 'median' => $median, 'peak' => $peak];
    }

    /**
     * Attach a per-person hour-based target to leaderboard rows (informational
     * only — does NOT drive commission; Sarah 2026-06-02). For each person we
     * take the exact hours they were clocked in (cash_registers, clipped to the
     * window, capped 6h/shift to match the Hours column), look up the store's
     * historical rate for those exact (weekday x hour) slots, and divide each
     * slot by how many staff were on the floor that hour so the target is the
     * person's FAIR SHARE of the store's expected take — not the whole store's.
     * Sum = "expected for the hours you worked"; target = expected + a stretch.
     * Also reports how many of their hours fell in peak vs off-peak slots.
     */
    private function attachHourTargets($rows, $business_id, $location_id, $start, $end)
    {
        if ($rows->isEmpty()) { return $rows; }

        $profile = $this->storeHourlyProfile($business_id, $location_id, 12);
        $rate = $profile['rate'];
        $peak = $profile['peak'];

        $startC = \Carbon::parse($start);
        $endC   = \Carbon::parse($end);

        $sessions = \DB::table('cash_registers')
            ->where('business_id', $business_id)
            ->where('location_id', $location_id)
            ->whereNotNull('user_id')
            ->where('created_at', '<=', $end)
            ->where(function ($q) use ($start) {
                $q->where('closed_at', '>=', $start)->orWhereNull('closed_at');
            })
            ->select('user_id', 'created_at', 'closed_at')
            ->get();

        // Expand each register session into hour-slot coverage fractions, and
        // tally distinct staff present per actual calendar hour (for fair-share
        // division). 6h cap mirrors the Hours figure on the board.
        $userCov = [];      // user_id => [ ['key'=>'dow-hr','frac'=>f,'inst'=>'Y-m-d H','peak'=>bool], ... ]
        $slotStaff = [];    // 'Y-m-d H' => [user_id => true]
        $now = \Carbon::now();
        foreach ($sessions as $s) {
            $ss = \Carbon::parse($s->created_at);
            if ($ss->lt($startC)) { $ss = $startC->copy(); }
            $se = $s->closed_at ? \Carbon::parse($s->closed_at) : $now->copy();
            if ($se->gt($endC)) { $se = $endC->copy(); }
            $cap = $ss->copy()->addSeconds(21600); // 6h
            if ($se->gt($cap)) { $se = $cap; }
            if ($se->lte($ss)) { continue; }

            $cursor = $ss->copy();
            while ($cursor->lt($se)) {
                $slotEnd = $cursor->copy()->startOfHour()->addHour();
                $chunkEnd = $slotEnd->lt($se) ? $slotEnd : $se;
                $frac = $cursor->diffInSeconds($chunkEnd) / 3600.0;
                $key = ($cursor->dayOfWeek + 1) . '-' . $cursor->hour; // dayOfWeek 0=Sun -> +1 = MySQL DAYOFWEEK
                $inst = $cursor->format('Y-m-d H');
                $userCov[$s->user_id][] = ['key' => $key, 'frac' => $frac, 'inst' => $inst, 'peak' => isset($peak[$key])];
                $slotStaff[$inst][$s->user_id] = true;
                $cursor = $chunkEnd;
            }
        }

        $stretch = 0.10; // gentle, fixed nudge above the store's historical rate
        // Sales bonus goes live 2026-06-15 (Sarah is still solidifying targets).
        // Until then the bonus is shown as a PROJECTION only and is NOT added to
        // anyone's total commission — no sales-bonus money is owed before then.
        $sales_bonus_live = \Carbon::now()->gte(\Carbon::parse(self::SALES_BONUS_LIVE_DATE));

        // The bonus is a PER-DAY bar (Sarah 2026-06-02): each day a person works
        // gets its own target from that day's hours, and they earn 2% of every
        // non-whatnot dollar rung above THAT day's target, summed across days.
        // A short day doesn't cancel a strong day (which a period total would).
        // So we need each person's non-whatnot sales split by calendar date,
        // same pre-tax / net-of-returns basis as the rest of the board.
        $net_pretax = '(tsl.quantity - COALESCE(tsl.quantity_returned, 0)) * (tsl.unit_price_inc_tax - COALESCE(tsl.item_tax, 0))';
        $daySalesQ = \DB::table('transactions as t')
            ->join('transaction_sell_lines as tsl', 'tsl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('t.import_source')
            ->where(function ($q) { $q->where('t.is_whatnot', 0)->orWhereNull('t.is_whatnot'); })
            ->whereBetween('t.transaction_date', [$start, $end]);
        if (!empty($location_id)) {
            $daySalesQ->where('t.location_id', $location_id);
        }
        $daySales = []; // [user_id][Y-m-d] => non-whatnot $ rung that day
        foreach ($daySalesQ
            ->selectRaw("t.created_by, DATE(t.transaction_date) as d, COALESCE(SUM($net_pretax), 0) as rev")
            ->groupBy('t.created_by', \DB::raw('DATE(t.transaction_date)'))
            ->get() as $row) {
            $daySales[$row->created_by][$row->d] = (float) $row->rev;
        }

        return $rows->map(function ($r) use ($userCov, $slotStaff, $rate, $stretch, $sales_bonus_live, $daySales) {
            $cov = $userCov[$r->user_id] ?? [];
            $peakH = 0.0; $offH = 0.0;
            $dayExpected = []; // Y-m-d => expected store-rate $ for hours worked that day
            foreach ($cov as $c) {
                $head = max(1, count($slotStaff[$c['inst']] ?? []));
                $exp = ($rate[$c['key']] ?? 0) * $c['frac'] / $head;
                $date = substr($c['inst'], 0, 10);
                $dayExpected[$date] = ($dayExpected[$date] ?? 0) + $exp;
                if ($c['peak']) { $peakH += $c['frac']; } else { $offH += $c['frac']; }
            }
            $expected = array_sum($dayExpected);
            $r->hour_expected = round($expected, 2);
            // Period "Target" shown = sum of the daily targets (same number as
            // expected*stretch since targets add up across days).
            $r->hour_target = $expected > 0 ? round($expected * (1 + $stretch), 2) : null;
            $r->hour_target_stretch_pct = $expected > 0 ? round($stretch * 100, 1) : null;
            $r->hour_pace_pct = ($r->hour_target && $r->hour_target > 0)
                ? round($r->non_whatnot_revenue / $r->hour_target * 100, 0)
                : null;
            $r->hour_peak = round($peakH, 1);
            $r->hour_offpeak = round($offH, 1);

            // Per-day bonus: 2% of each day's sales above that day's target,
            // summed. No target on a day (sparse store history / no clocked
            // hours) => that day earns nothing. goal_bonus is the PROJECTED
            // amount (always computed so targets can be solidified before
            // launch); sales_bonus_live says whether it's actually paid yet.
            $bonus = 0.0; $anyTarget = false;
            foreach ($dayExpected as $date => $exp) {
                if ($exp <= 0) { continue; }
                $anyTarget = true;
                $dayTarget = $exp * (1 + $stretch);
                $sold = (float) ($daySales[$r->user_id][$date] ?? 0);
                if ($sold > $dayTarget) { $bonus += ($sold - $dayTarget) * 0.02; }
            }
            $r->goal = $r->hour_target;
            $r->goal_stretch_pct = $r->hour_target_stretch_pct;
            $r->goal_bonus = round($bonus, 2);
            $r->goal_hit = $anyTarget && $bonus > 0;
            $r->sales_bonus_live = $sales_bonus_live;
            // Only money actually owed today counts toward total commission. The
            // sales bonus is excluded until it goes live on 2026-06-15.
            $r->total_commission = round((float) $r->barcoding_commission + ($sales_bonus_live ? $r->goal_bonus : 0.0), 2);
            return $r;
        });
    }

    /**
     * One employee's sales goal bonus (the 2%-above-daily-target pay), summed
     * across the active locations they worked, for a date range. Reuses the
     * EXACT leaderboard math (buildLeaderboardRows + attachHourTargets) so the
     * self-service /my-earnings page agrees with the leaderboard to the penny.
     *
     * Scoped to a single user_id; the CALLER is responsible for authorization
     * (this does NOT call ensureAdminOnlyReportAccess, so an employee can see
     * their own). Returns bonus, non-whatnot revenue, the live flag, and a
     * per-location breakdown.
     */
    public function userSalesBonus($business_id, $user_id, $start, $end)
    {
        $bonus = 0.0;
        $revenue = 0.0;
        $live = \Carbon::now()->gte(\Carbon::parse(self::SALES_BONUS_LIVE_DATE));
        $perLoc = [];

        $locations = \DB::table('business_locations')
            ->where('business_id', $business_id)
            ->where('is_active', 1)
            ->orderBy('id')
            ->pluck('name', 'id');

        foreach ($locations as $lid => $lname) {
            $rows = $this->buildLeaderboardRows($business_id, $start, $end, null, $lid, ['with_commission' => true, 'exclude_owners' => false]);
            $rows = $this->attachHourTargets($rows, $business_id, $lid, $start, $end);
            $mine = $rows->first(function ($r) use ($user_id) { return (int) $r->user_id === (int) $user_id; });
            if (!$mine) { continue; }

            $b = (float) ($mine->goal_bonus ?? 0);
            $rev = (float) ($mine->non_whatnot_revenue ?? 0);
            $bonus += $b;
            $revenue += $rev;
            if ($b != 0 || $rev != 0) {
                $perLoc[] = [
                    'location' => $lname,
                    'bonus'    => round($b, 2),
                    'revenue'  => round($rev, 2),
                    'target'   => isset($mine->hour_target) ? round((float) $mine->hour_target, 2) : null,
                ];
            }
        }

        return [
            'bonus'        => round($bonus, 2),
            'revenue'      => round($revenue, 2),
            'live'         => $live,
            'per_location' => $perLoc,
        ];
    }

    /**
     * Restrict a sell-line query to USED items. Requires the products table to
     * be joined as `p` and its category/sub_category leftJoined as `c`/`sc`.
     * "Used" = not sealed/new vinyl/CD/cassette and not a non-record category —
     * the single definition shared by items-listed, sales-from-listed, the
     * listed-items drill-down, and listing commission so the numbers reconcile.
     */
    private function applyUsedItemCategoryFilter($q)
    {
        $excludedCategoryPatterns = ['%sealed%', '%new vinyl%', '%new cd%', '%new cassette%'];
        $excludedCategoryNames = [
            'audio gear', 'record players', 'record player',
            'trading cards', 'apparel', 'clothing', 'video games',
            'gift items', 'toys', 'accessories & novelties',
            'acessories & novelties', 'pictures & posters',
        ];
        return $q->where(function ($qq) use ($excludedCategoryPatterns, $excludedCategoryNames) {
            foreach ($excludedCategoryPatterns as $pat) {
                $qq->where(\DB::raw('LOWER(c.name)'), 'NOT LIKE', $pat)
                   ->where(\DB::raw('LOWER(COALESCE(sc.name, \'\'))'), 'NOT LIKE', $pat);
            }
            $qq->whereNotIn(\DB::raw('LOWER(TRIM(c.name))'), $excludedCategoryNames)
               ->whereNotIn(\DB::raw('LOWER(TRIM(COALESCE(sc.name, \'\')))'), $excludedCategoryNames);
        });
    }

    /**
     * Barcoding commission per user: 2% of the gross on USED items the user
     * barcoded (products.created_by) that sold in the window. Mirrors the
     * established earnings rule — rollout-gated to 2026-05-15 with the same
     * category exclusions as the /home earnings widget. Optionally scoped to
     * the location where the sale happened.
     */
    private function barcodingCommissionByUser($business_id, $start, $end, $location_id = null)
    {
        $rollout = '2026-05-15 00:00:00';

        $q = \DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('products as p', 'tsl.product_id', '=', 'p.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('categories as sc', 'p.sub_category_id', '=', 'sc.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('t.import_source')
            ->whereBetween('t.transaction_date', [$start, $end])
            ->whereNotNull('p.created_by')
            ->where('p.created_at', '>=', $rollout);
        $this->applyUsedItemCategoryFilter($q);
        if (!empty($location_id)) {
            $q->where('t.location_id', $location_id);
        }

        // Pre-tax and net of returns (Sarah 2026-06-02): 2% of the item's price
        // before sales tax, less any returned quantity. Whatnot sales DO earn
        // listing pay — the lister did the work and the item sold.
        return $q
            ->selectRaw('p.created_by, ROUND(SUM((tsl.quantity - COALESCE(tsl.quantity_returned, 0)) * (tsl.unit_price_inc_tax - COALESCE(tsl.item_tax, 0))) * 0.02, 2) as commission')
            ->groupBy('p.created_by')
            ->get()
            ->keyBy('created_by');
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

    /**
     * Hours worked per user in the given window. Tries Sling (scheduled
     * shifts via SlingClient, matched by email) first since it covers
     * staff (e.g. Nick) who never open a cash register; falls back to
     * cash_registers (open/close times clipped to the window AND capped
     * at 6h per shift to absorb registers left open overnight). Returns
     * a Collection keyed by user_id with hours as float.
     */
    private function getHoursWorkedByUser($users, $start_date, $end_date, $business_id)
    {
        $window_start = $start_date . ' 00:00:00';
        $window_end = $end_date . ' 23:59:59';
        $hours_raw = collect();

        try {
            $sling = new \App\Services\SlingClient();
            if ($sling->isConfigured()) {
                $erpUserIdByEmail = $users->mapWithKeys(function ($u) {
                    $email = strtolower(trim((string) (\App\User::find($u->id)->email ?? '')));
                    return $email !== '' ? [$email => $u->id] : [];
                })->all();
                $slingHours = $sling->hoursByErpUser($start_date, $end_date, $erpUserIdByEmail);
                if (!empty($slingHours)) {
                    $hours_raw = collect($slingHours);
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Sling hours fetch failed, falling back to cash_registers: ' . $e->getMessage());
        }

        if ($hours_raw->isEmpty()) {
            $hours_raw = DB::table('cash_registers')
                ->where('business_id', $business_id)
                ->whereNotNull('user_id')
                ->where('created_at', '<=', $window_end)
                ->where(function ($q) use ($window_start) {
                    $q->where('closed_at', '>=', $window_start)
                      ->orWhereNull('closed_at');
                })
                ->selectRaw("user_id,
                    SUM(
                        LEAST(
                            TIMESTAMPDIFF(
                                SECOND,
                                GREATEST(created_at, ?),
                                LEAST(COALESCE(closed_at, NOW()), ?)
                            ),
                            21600
                        )
                    ) / 3600.0 as hours")
                ->addBinding($window_start, 'select')
                ->addBinding($window_end, 'select')
                ->groupBy('user_id')
                ->pluck('hours', 'user_id');
        }

        return $hours_raw;
    }
}
