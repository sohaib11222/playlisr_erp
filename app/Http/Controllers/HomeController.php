<?php

namespace App\Http\Controllers;

use App\BusinessLocation;

use App\Charts\CommonChart;
use App\Currency;
use App\Transaction;
use App\Utils\BusinessUtil;

use App\Utils\ModuleUtil;
use App\Utils\TransactionUtil;
use App\VariationLocationDetails;
use Datatables;
use DB;
use Illuminate\Http\Request;
use App\Utils\Util;
use App\Utils\RestaurantUtil;
use App\User;
use Illuminate\Notifications\DatabaseNotification;
use App\Media;

class HomeController extends Controller
{
    /**
     * All Utils instance.
     *
     */
    protected $businessUtil;
    protected $transactionUtil;
    protected $moduleUtil;
    protected $commonUtil;
    protected $restUtil;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        BusinessUtil $businessUtil,
        TransactionUtil $transactionUtil,
        ModuleUtil $moduleUtil,
        Util $commonUtil,
        RestaurantUtil $restUtil
    ) {
        $this->businessUtil = $businessUtil;
        $this->transactionUtil = $transactionUtil;
        $this->moduleUtil = $moduleUtil;
        $this->commonUtil = $commonUtil;
        $this->restUtil = $restUtil;
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $business_id = request()->session()->get('user.business_id');

        $is_admin = $this->businessUtil->is_admin(auth()->user());

        if (!auth()->user()->can('dashboard.data')) {
            return view('home.index');
        }

        $fy = $this->businessUtil->getCurrentFinancialYear($business_id);

        $currency = Currency::where('id', request()->session()->get('business.currency_id'))->first();
        //ensure start date starts from at least 30 days before to get sells last 30 days
        $least_30_days = \Carbon::parse($fy['start'])->subDays(30)->format('Y-m-d');

        //get all sells
        $sells_this_fy = $this->transactionUtil->getSellsCurrentFy($business_id, $least_30_days, $fy['end']);
        
        $all_locations = BusinessLocation::forDropdown($business_id)->toArray();

        //Chart for sells last 30 days
        $labels = [];
        $all_sell_values = [];
        $dates = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = \Carbon::now()->subDays($i)->format('Y-m-d');
            $dates[] = $date;

            $labels[] = date('j M Y', strtotime($date));

            $total_sell_on_date = $sells_this_fy->where('date', $date)->sum('total_sells');

            if (!empty($total_sell_on_date)) {
                $all_sell_values[] = (float) $total_sell_on_date;
            } else {
                $all_sell_values[] = 0;
            }
        }

        //Group sells by location
        $location_sells = [];
        foreach ($all_locations as $loc_id => $loc_name) {
            $values = [];
            foreach ($dates as $date) {
                $total_sell_on_date_location = $sells_this_fy->where('date', $date)->where('location_id', $loc_id)->sum('total_sells');
                
                if (!empty($total_sell_on_date_location)) {
                    $values[] = (float) $total_sell_on_date_location;
                } else {
                    $values[] = 0;
                }
            }
            $location_sells[$loc_id]['loc_label'] = $loc_name;
            $location_sells[$loc_id]['values'] = $values;
        }

        $sells_chart_1 = new CommonChart;

        $sells_chart_1->labels($labels)
                        ->options($this->__chartOptions(__(
                            'home.total_sells',
                            ['currency' => $currency->code]
                            )));

        if (!empty($location_sells)) {
            foreach ($location_sells as $location_sell) {
                $sells_chart_1->dataset($location_sell['loc_label'], 'line', $location_sell['values']);
            }
        }

        if (count($all_locations) > 1) {
            $sells_chart_1->dataset(__('report.all_locations'), 'line', $all_sell_values);
        }

        $labels = [];
        $values = [];
        $date = strtotime($fy['start']);
        $last   = date('m-Y', strtotime($fy['end']));
        $fy_months = [];
        do {
            $month_year = date('m-Y', $date);
            $fy_months[] = $month_year;

            $labels[] = \Carbon::createFromFormat('m-Y', $month_year)
                            ->format('M-Y');
            $date = strtotime('+1 month', $date);

            $total_sell_in_month_year = $sells_this_fy->where('yearmonth', $month_year)->sum('total_sells');

            if (!empty($total_sell_in_month_year)) {
                $values[] = (float) $total_sell_in_month_year;
            } else {
                $values[] = 0;
            }
        } while ($month_year != $last);

        $fy_sells_by_location_data = [];

        foreach ($all_locations as $loc_id => $loc_name) {
            $values_data = [];
            foreach ($fy_months as $month) {
                $total_sell_in_month_year_location = $sells_this_fy->where('yearmonth', $month)->where('location_id', $loc_id)->sum('total_sells');
                
                if (!empty($total_sell_in_month_year_location)) {
                    $values_data[] = (float) $total_sell_in_month_year_location;
                } else {
                    $values_data[] = 0;
                }
            }
            $fy_sells_by_location_data[$loc_id]['loc_label'] = $loc_name;
            $fy_sells_by_location_data[$loc_id]['values'] = $values_data;
        }

        $sells_chart_2 = new CommonChart;
        $sells_chart_2->labels($labels)
                    ->options($this->__chartOptions(__(
                        'home.total_sells',
                        ['currency' => $currency->code]
                            )));
        if (!empty($fy_sells_by_location_data)) {
            foreach ($fy_sells_by_location_data as $location_sell) {
                $sells_chart_2->dataset($location_sell['loc_label'], 'line', $location_sell['values']);
            }
        }
        if (count($all_locations) > 1) {
            $sells_chart_2->dataset(__('report.all_locations'), 'line', $values);
        }

        //Get Dashboard widgets from module
        $module_widgets = $this->moduleUtil->getModuleData('dashboard_widget');

        $widgets = [];

        foreach ($module_widgets as $widget_array) {
            if (!empty($widget_array['position'])) {
                $widgets[$widget_array['position']][] = $widget_array['widget'];
            }
        }

        $common_settings = !empty(session('business.common_settings')) ? session('business.common_settings') : [];

        // ==========================================================
        // Nivessa employee dashboard cards
        // ==========================================================
        $since_30 = \Carbon::now()->subDays(30)->toDateTimeString();
        $since_7  = \Carbon::now()->subDays(7)->toDateTimeString();

        // Top categories per store (last 30 days, by revenue)
        $top_categories_by_location = [];
        $cat_rows = \DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('products as p', 'tsl.product_id', '=', 'p.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.transaction_date', '>=', $since_30)
            ->selectRaw("t.location_id, bl.name as location_name,
                COALESCE(c.name, '(uncategorized)') as category,
                SUM(tsl.quantity) as qty,
                SUM(tsl.quantity * tsl.unit_price_inc_tax) as revenue")
            ->groupBy('t.location_id', 'bl.name', 'c.name')
            ->orderByDesc('revenue')
            ->get();
        foreach ($cat_rows as $r) {
            $loc = $r->location_name ?: 'Unknown';
            if (!isset($top_categories_by_location[$loc])) {
                $top_categories_by_location[$loc] = [];
            }
            if (count($top_categories_by_location[$loc]) < 5) {
                $top_categories_by_location[$loc][] = $r;
            }
        }

        // What's selling — by format (LP, CD, cassette, DVD, magazine, etc.) last 30 days
        $top_formats = \DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('products as p', 'tsl.product_id', '=', 'p.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.transaction_date', '>=', $since_30)
            ->whereNotNull('p.format')
            ->where('p.format', '!=', '')
            ->selectRaw("p.format,
                SUM(tsl.quantity) as qty,
                SUM(tsl.quantity * tsl.unit_price_inc_tax) as revenue")
            ->groupBy('p.format')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get();

        // Collections bought / what we bought — recent purchase transactions
        $recent_purchases = \DB::table('transactions as t')
            ->leftJoin('contacts as c', 't.contact_id', '=', 'c.id')
            ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->leftJoin('users as u', 't.created_by', '=', 'u.id')
            ->where('t.business_id', $business_id)
            ->whereIn('t.type', ['purchase', 'opening_stock', 'purchase_transfer'])
            ->where('t.transaction_date', '>=', $since_30)
            ->selectRaw("t.id, t.ref_no, t.transaction_date, t.final_total, t.type,
                bl.name as location_name,
                COALESCE(CONCAT(c.first_name, ' ', COALESCE(c.last_name, '')), c.name, c.supplier_business_name, '(unknown)') as supplier,
                CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as employee")
            ->orderByDesc('t.transaction_date')
            ->limit(10)
            ->get();

        // Last 15 items sold
        $last_sold_items = \DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('products as p', 'tsl.product_id', '=', 'p.id')
            ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->leftJoin('users as u', 't.created_by', '=', 'u.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->selectRaw("t.transaction_date, t.invoice_no,
                p.name, p.artist, p.format,
                tsl.quantity, tsl.unit_price_inc_tax,
                bl.name as location_name,
                CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as employee")
            ->orderByDesc('t.transaction_date')
            ->limit(15)
            ->get();

        // Rewards / customer accounts created today (per creator / employee).
        // NOTE: contacts table has no location_id column, so grouping is
        // per-employee (created_by). True per-store attribution would need a
        // schema change to track which store a customer was created at.
        $today_start = \Carbon::today()->toDateTimeString();
        $today_end = \Carbon::today()->endOfDay()->toDateTimeString();
        $rewards_today = \DB::table('contacts as c')
            ->leftJoin('users as u', 'c.created_by', '=', 'u.id')
            ->where('c.business_id', $business_id)
            ->whereIn('c.type', ['customer', 'both'])
            ->whereBetween('c.created_at', [$today_start, $today_end])
            ->selectRaw("c.created_by,
                CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as employee,
                COUNT(*) as cnt")
            ->groupBy('c.created_by', 'u.first_name', 'u.last_name')
            ->orderByDesc('cnt')
            ->get();
        $rewards_today_total = (int) $rewards_today->sum('cnt');

        // ---- Personal productivity for the logged-in user (today) ----
        $me_id = auth()->user()->id;
        $my_priced_today = (int) \DB::table('products')
            ->where('business_id', $business_id)
            ->where('created_by', $me_id)
            ->whereBetween('created_at', [$today_start, $today_end])
            ->count();

        $my_pos_items_today = (int) \DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.created_by', $me_id)
            ->whereBetween('t.transaction_date', [$today_start, $today_end])
            ->count();

        $my_pos_tx_today = (int) \DB::table('transactions')
            ->where('business_id', $business_id)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->where('created_by', $me_id)
            ->whereBetween('transaction_date', [$today_start, $today_end])
            ->count();

        // ---- YoY + MoM progress stats (business-wide) ----
        $now = \Carbon::now();
        $mtd_start = $now->copy()->startOfMonth()->toDateString();
        $mtd_end = $now->copy()->toDateString();
        $last_month_same_start = $now->copy()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $last_month_same_end = $now->copy()->subMonthNoOverflow()->toDateString();
        $ytd_start = $now->copy()->startOfYear()->toDateString();
        $last_year_same_start = $now->copy()->subYear()->startOfYear()->toDateString();
        $last_year_same_end = $now->copy()->subYear()->toDateString();

        $sumSells = function ($start, $end) use ($business_id) {
            return (float) \DB::table('transactions')
                ->where('business_id', $business_id)
                ->where('type', 'sell')
                ->where('status', 'final')
                ->whereDate('transaction_date', '>=', $start)
                ->whereDate('transaction_date', '<=', $end)
                ->sum('final_total');
        };
        $sales_mtd = $sumSells($mtd_start, $mtd_end);
        $sales_lm_same = $sumSells($last_month_same_start, $last_month_same_end);
        $sales_ytd = $sumSells($ytd_start, $mtd_end);
        $sales_ly_same = $sumSells($last_year_same_start, $last_year_same_end);
        $mom_pct = $sales_lm_same > 0 ? (($sales_mtd - $sales_lm_same) / $sales_lm_same) * 100 : null;
        $yoy_pct = $sales_ly_same > 0 ? (($sales_ytd - $sales_ly_same) / $sales_ly_same) * 100 : null;

        // ---- Personal month-over-month achievement ----
        $my_countSellLines = function ($start, $end) use ($business_id, $me_id) {
            return (int) \DB::table('transaction_sell_lines as tsl')
                ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
                ->where('t.business_id', $business_id)
                ->where('t.type', 'sell')
                ->where('t.created_by', $me_id)
                ->whereDate('t.transaction_date', '>=', $start)
                ->whereDate('t.transaction_date', '<=', $end)
                ->count();
        };
        $my_countPriced = function ($start, $end) use ($business_id, $me_id) {
            return (int) \DB::table('products')
                ->where('business_id', $business_id)
                ->where('created_by', $me_id)
                ->whereDate('created_at', '>=', $start)
                ->whereDate('created_at', '<=', $end)
                ->count();
        };
        $my_mtd_rung = $my_countSellLines($mtd_start, $mtd_end);
        $my_lm_rung = $my_countSellLines($last_month_same_start, $last_month_same_end);
        $my_mtd_priced = $my_countPriced($mtd_start, $mtd_end);
        $my_lm_priced = $my_countPriced($last_month_same_start, $last_month_same_end);
        $my_rung_pct = $my_lm_rung > 0 ? (($my_mtd_rung - $my_lm_rung) / $my_lm_rung) * 100 : null;
        $my_priced_pct = $my_lm_priced > 0 ? (($my_mtd_priced - $my_lm_priced) / $my_lm_priced) * 100 : null;

        // ---- Active high-priority customer wants (from customer_wants table) ----
        $active_wants = [];
        $active_wants_count = 0;
        if (\Schema::hasTable('customer_wants')) {
            $active_wants = \DB::table('customer_wants as cw')
                ->leftJoin('contacts as c', 'cw.contact_id', '=', 'c.id')
                ->leftJoin('business_locations as bl', 'cw.location_id', '=', 'bl.id')
                ->where('cw.business_id', $business_id)
                ->where('cw.status', 'active')
                ->selectRaw("cw.id, cw.artist, cw.title, cw.format, cw.priority, cw.phone, cw.notes,
                    CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) as customer,
                    bl.name as location_name, cw.created_at")
                ->orderByRaw("FIELD(cw.priority, 'high', 'normal', 'low')")
                ->orderByDesc('cw.created_at')
                ->limit(10)
                ->get();
            $active_wants_count = (int) \DB::table('customer_wants')
                ->where('business_id', $business_id)
                ->where('status', 'active')
                ->count();
        }

        // ---- Revenue generated from items YOU barcoded/priced ----
        // Sums the sell-line revenue on products created by the logged-in user.
        $my_priced_revenue_q = \DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('products as p', 'tsl.product_id', '=', 'p.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('p.created_by', $me_id);

        $my_priced_rev_mtd = (float) (clone $my_priced_revenue_q)
            ->whereDate('t.transaction_date', '>=', $mtd_start)
            ->whereDate('t.transaction_date', '<=', $mtd_end)
            ->selectRaw('COALESCE(SUM(tsl.quantity * tsl.unit_price_inc_tax), 0) as rev')
            ->value('rev');

        $my_priced_rev_lm = (float) (clone $my_priced_revenue_q)
            ->whereDate('t.transaction_date', '>=', $last_month_same_start)
            ->whereDate('t.transaction_date', '<=', $last_month_same_end)
            ->selectRaw('COALESCE(SUM(tsl.quantity * tsl.unit_price_inc_tax), 0) as rev')
            ->value('rev');

        $my_priced_rev_lifetime = (float) (clone $my_priced_revenue_q)
            ->selectRaw('COALESCE(SUM(tsl.quantity * tsl.unit_price_inc_tax), 0) as rev')
            ->value('rev');

        $my_priced_rev_pct = $my_priced_rev_lm > 0 ? (($my_priced_rev_mtd - $my_priced_rev_lm) / $my_priced_rev_lm) * 100 : null;

        // ---- Average $ per transaction per employee (this month) ----
        $avg_per_employee = \DB::table('transactions as t')
            ->leftJoin('users as u', 't.created_by', '=', 'u.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereDate('t.transaction_date', '>=', $mtd_start)
            ->whereDate('t.transaction_date', '<=', $mtd_end)
            ->selectRaw("t.created_by,
                CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as employee,
                COUNT(*) as tx_count,
                COALESCE(SUM(t.final_total), 0) as total,
                COALESCE(AVG(t.final_total), 0) as avg_tx")
            ->groupBy('t.created_by', 'u.first_name', 'u.last_name')
            ->havingRaw('COUNT(*) >= 3')
            ->orderByDesc('avg_tx')
            ->limit(15)
            ->get();

        // ---- Active customer wants (peek for dashboard) ----
        $active_wants = [];
        if (\Schema::hasTable('customer_wants')) {
            $active_wants = \DB::table('customer_wants as cw')
                ->leftJoin('contacts as c', 'cw.contact_id', '=', 'c.id')
                ->leftJoin('business_locations as bl', 'cw.location_id', '=', 'bl.id')
                ->where('cw.business_id', $business_id)
                ->where('cw.status', 'active')
                ->selectRaw("cw.id, cw.artist, cw.title, cw.format, cw.priority, cw.phone, cw.created_at,
                    bl.name as location_name,
                    CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, '')) as customer")
                ->orderByRaw("FIELD(cw.priority, 'high', 'normal', 'low')")
                ->orderByDesc('cw.created_at')
                ->limit(10)
                ->get();
        }

        // Most expensive items sold in the last 7 days (by unit price)
        $top_expensive_items = \DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('products as p', 'tsl.product_id', '=', 'p.id')
            ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->where('t.transaction_date', '>=', $since_7)
            ->selectRaw("t.transaction_date,
                p.name, p.artist, p.format,
                tsl.quantity, tsl.unit_price_inc_tax,
                (tsl.quantity * tsl.unit_price_inc_tax) as line_total,
                bl.name as location_name")
            ->orderByDesc('tsl.unit_price_inc_tax')
            ->limit(10)
            ->get();

        return view('home.index', compact(
            'sells_chart_1', 'sells_chart_2', 'widgets', 'all_locations', 'common_settings', 'is_admin',
            'top_categories_by_location', 'top_formats', 'recent_purchases',
            'last_sold_items', 'top_expensive_items',
            'rewards_today', 'rewards_today_total',
            'my_priced_today', 'my_pos_items_today', 'my_pos_tx_today',
            'sales_mtd', 'sales_lm_same', 'mom_pct',
            'sales_ytd', 'sales_ly_same', 'yoy_pct',
            'my_mtd_rung', 'my_lm_rung', 'my_rung_pct',
            'my_mtd_priced', 'my_lm_priced', 'my_priced_pct',
            'avg_per_employee',
            'my_priced_rev_mtd', 'my_priced_rev_lm', 'my_priced_rev_lifetime', 'my_priced_rev_pct',
            'active_wants', 'active_wants_count'
        ));
    }

    /**
     * Retrieves purchase and sell details for a given time period.
     *
     * @return \Illuminate\Http\Response
     */
    public function getTotals()
    {
        if (request()->ajax()) {
            $start = request()->start;
            $end = request()->end;
            $location_id = request()->location_id;
            $business_id = request()->session()->get('user.business_id');

            $purchase_details = $this->transactionUtil->getPurchaseTotals($business_id, $start, $end, $location_id);

            $sell_details = $this->transactionUtil->getSellTotals($business_id, $start, $end, $location_id);

            $total_ledger_discount = $this->transactionUtil->getTotalLedgerDiscount($business_id, $start, $end);

            $purchase_details['purchase_due'] = $purchase_details['purchase_due'] - $total_ledger_discount['total_purchase_discount'];

            $transaction_types = [
                'purchase_return', 'sell_return', 'expense'
            ];

            $transaction_totals = $this->transactionUtil->getTransactionTotals(
                $business_id,
                $transaction_types,
                $start,
                $end,
                $location_id
            );

            $total_purchase_inc_tax = !empty($purchase_details['total_purchase_inc_tax']) ? $purchase_details['total_purchase_inc_tax'] : 0;
            $total_purchase_return_inc_tax = $transaction_totals['total_purchase_return_inc_tax'];

            $output = $purchase_details;
            $output['total_purchase'] = $total_purchase_inc_tax;
            $output['total_purchase_return'] = $total_purchase_return_inc_tax;

            $total_sell_inc_tax = !empty($sell_details['total_sell_inc_tax']) ? $sell_details['total_sell_inc_tax'] : 0;
            $total_sell_return_inc_tax = !empty($transaction_totals['total_sell_return_inc_tax']) ? $transaction_totals['total_sell_return_inc_tax'] : 0;

            $output['total_sell'] = $total_sell_inc_tax;
            $output['total_sell_return'] = $total_sell_return_inc_tax;

            $output['invoice_due'] = $sell_details['invoice_due'] - $total_ledger_discount['total_sell_discount'];
            $output['total_expense'] = $transaction_totals['total_expense'];

            //NET = TOTAL SALES - INVOICE DUE - EXPENSE
            $output['net'] = $output['total_sell'] - $output['invoice_due'] - $output['total_expense'];
            
            return $output;
        }
    }

    /**
     * Retrieves sell products whose available quntity is less than alert quntity.
     *
     * @return \Illuminate\Http\Response
     */
    public function getProductStockAlert()
    {
        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');

            $query = VariationLocationDetails::join(
                'product_variations as pv',
                'variation_location_details.product_variation_id',
                '=',
                'pv.id'
            )
                    ->join(
                        'variations as v',
                        'variation_location_details.variation_id',
                        '=',
                        'v.id'
                    )
                    ->join(
                        'products as p',
                        'variation_location_details.product_id',
                        '=',
                        'p.id'
                    )
                    ->leftjoin(
                        'business_locations as l',
                        'variation_location_details.location_id',
                        '=',
                        'l.id'
                    )
                    ->leftjoin('units as u', 'p.unit_id', '=', 'u.id')
                    ->where('p.business_id', $business_id)
                    ->where('p.enable_stock', 1)
                    ->where('p.is_inactive', 0)
                    ->whereNull('v.deleted_at')
                    ->whereNotNull('p.alert_quantity')
                    ->whereRaw('variation_location_details.qty_available <= p.alert_quantity');

            //Check for permitted locations of a user
            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $query->whereIn('variation_location_details.location_id', $permitted_locations);
            }

            if (!empty(request()->input('location_id'))) {
                $query->where('variation_location_details.location_id', request()->input('location_id'));
            }

            $products = $query->select(
                'p.name as product',
                'p.type',
                'p.sku',
                'pv.name as product_variation',
                'v.name as variation',
                'v.sub_sku',
                'l.name as location',
                'variation_location_details.qty_available as stock',
                'u.short_name as unit'
            )
                    ->groupBy('variation_location_details.id')
                    ->orderBy('stock', 'asc');

            return Datatables::of($products)
                ->editColumn('product', function ($row) {
                    if ($row->type == 'single') {
                        return $row->product . ' (' . $row->sku . ')';
                    } else {
                        return $row->product . ' - ' . $row->product_variation . ' - ' . $row->variation . ' (' . $row->sub_sku . ')';
                    }
                })
                ->editColumn('stock', function ($row) {
                    $stock = $row->stock ? $row->stock : 0 ;
                    return '<span data-is_quantity="true" class="display_currency" data-currency_symbol=false>'. (float)$stock . '</span> ' . $row->unit;
                })
                ->removeColumn('sku')
                ->removeColumn('sub_sku')
                ->removeColumn('unit')
                ->removeColumn('type')
                ->removeColumn('product_variation')
                ->removeColumn('variation')
                ->rawColumns([2])
                ->make(false);
        }
    }

    /**
     * Retrieves payment dues for the purchases.
     *
     * @return \Illuminate\Http\Response
     */
    public function getPurchasePaymentDues()
    {
        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');
            $today = \Carbon::now()->format("Y-m-d H:i:s");

            $query = Transaction::join(
                'contacts as c',
                'transactions.contact_id',
                '=',
                'c.id'
            )
                    ->leftJoin(
                        'transaction_payments as tp',
                        'transactions.id',
                        '=',
                        'tp.transaction_id'
                    )
                    ->where('transactions.business_id', $business_id)
                    ->where('transactions.type', 'purchase')
                    ->where('transactions.payment_status', '!=', 'paid')
                    ->whereRaw("DATEDIFF( DATE_ADD( transaction_date, INTERVAL IF(transactions.pay_term_type = 'days', transactions.pay_term_number, 30 * transactions.pay_term_number) DAY), '$today') <= 7");

            //Check for permitted locations of a user
            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $query->whereIn('transactions.location_id', $permitted_locations);
            }

            if (!empty(request()->input('location_id'))) {
                $query->where('transactions.location_id', request()->input('location_id'));
            }

            $dues =  $query->select(
                'transactions.id as id',
                'c.name as supplier',
                'c.supplier_business_name',
                'ref_no',
                'final_total',
                DB::raw('SUM(tp.amount) as total_paid')
            )
                        ->groupBy('transactions.id');

            return Datatables::of($dues)
                ->addColumn('due', function ($row) {
                    $total_paid = !empty($row->total_paid) ? $row->total_paid : 0;
                    $due = $row->final_total - $total_paid;
                    return '<span class="display_currency" data-currency_symbol="true">' .
                    $due . '</span>';
                })
                ->addColumn('action', '@can("purchase.create") <a href="{{action("TransactionPaymentController@addPayment", [$id])}}" class="btn btn-xs btn-success add_payment_modal"><i class="fas fa-money-bill-alt"></i> @lang("purchase.add_payment")</a> @endcan')
                ->removeColumn('supplier_business_name')
                ->editColumn('supplier', '@if(!empty($supplier_business_name)) {{$supplier_business_name}}, <br> @endif {{$supplier}}')
                ->editColumn('ref_no', function ($row) {
                    if (auth()->user()->can('purchase.view')) {
                        return  '<a href="#" data-href="' . action('PurchaseController@show', [$row->id]) . '"
                                    class="btn-modal" data-container=".view_modal">' . $row->ref_no . '</a>';
                    }
                    return $row->ref_no;
                })
                ->removeColumn('id')
                ->removeColumn('final_total')
                ->removeColumn('total_paid')
                ->rawColumns([0, 1, 2, 3])
                ->make(false);
        }
    }

    /**
     * Retrieves payment dues for the purchases.
     *
     * @return \Illuminate\Http\Response
     */
    public function getSalesPaymentDues()
    {
        if (request()->ajax()) {
            $business_id = request()->session()->get('user.business_id');
            $today = \Carbon::now()->format("Y-m-d H:i:s");

            $query = Transaction::join(
                'contacts as c',
                'transactions.contact_id',
                '=',
                'c.id'
            )
                    ->leftJoin(
                        'transaction_payments as tp',
                        'transactions.id',
                        '=',
                        'tp.transaction_id'
                    )
                    ->where('transactions.business_id', $business_id)
                    ->where('transactions.type', 'sell')
                    ->where('transactions.payment_status', '!=', 'paid')
                    ->whereNotNull('transactions.pay_term_number')
                    ->whereNotNull('transactions.pay_term_type')
                    ->whereRaw("DATEDIFF( DATE_ADD( transaction_date, INTERVAL IF(transactions.pay_term_type = 'days', transactions.pay_term_number, 30 * transactions.pay_term_number) DAY), '$today') <= 7");

            //Check for permitted locations of a user
            $permitted_locations = auth()->user()->permitted_locations();
            if ($permitted_locations != 'all') {
                $query->whereIn('transactions.location_id', $permitted_locations);
            }

            if (!empty(request()->input('location_id'))) {
                $query->where('transactions.location_id', request()->input('location_id'));
            }

            $dues =  $query->select(
                'transactions.id as id',
                'c.name as customer',
                'c.supplier_business_name',
                'transactions.invoice_no',
                'final_total',
                DB::raw('SUM(tp.amount) as total_paid')
            )
                        ->groupBy('transactions.id');

            return Datatables::of($dues)
                ->addColumn('due', function ($row) {
                    $total_paid = !empty($row->total_paid) ? $row->total_paid : 0;
                    $due = $row->final_total - $total_paid;
                    return '<span class="display_currency" data-currency_symbol="true">' .
                    $due . '</span>';
                })
                ->editColumn('invoice_no', function ($row) {
                    if (auth()->user()->can('sell.view')) {
                        return  '<a href="#" data-href="' . action('SellController@show', [$row->id]) . '"
                                    class="btn-modal" data-container=".view_modal">' . $row->invoice_no . '</a>';
                    }
                    return $row->invoice_no;
                })
                ->addColumn('action', '@if(auth()->user()->can("sell.create") || auth()->user()->can("direct_sell.access")) <a href="{{action("TransactionPaymentController@addPayment", [$id])}}" class="btn btn-xs btn-success add_payment_modal"><i class="fas fa-money-bill-alt"></i> @lang("purchase.add_payment")</a> @endif')
                ->editColumn('customer', '@if(!empty($supplier_business_name)) {{$supplier_business_name}}, <br> @endif {{$customer}}')
                ->removeColumn('supplier_business_name')
                ->removeColumn('id')
                ->removeColumn('final_total')
                ->removeColumn('total_paid')
                ->rawColumns([0, 1, 2, 3])
                ->make(false);
        }
    }

    public function loadMoreNotifications()
    {
        $notifications = auth()->user()->notifications()->orderBy('created_at', 'DESC')->paginate(10);

        if (request()->input('page') == 1) {
            auth()->user()->unreadNotifications->markAsRead();
        }
        $notifications_data = $this->commonUtil->parseNotifications($notifications);

        return view('layouts.partials.notification_list', compact('notifications_data'));
    }

    /**
     * Function to count total number of unread notifications
     *
     * @return json
     */
    public function getTotalUnreadNotifications()
    {
        $unread_notifications = auth()->user()->unreadNotifications;
        $total_unread = $unread_notifications->count();

        $notification_html = '';
        $modal_notifications = [];
        foreach ($unread_notifications as $unread_notification) {
            if (isset($data['show_popup'])) {
                $modal_notifications[] = $unread_notification;
                $unread_notification->markAsRead();
            }
        }
        if (!empty($modal_notifications)) {
            $notification_html = view('home.notification_modal')->with(['notifications' => $modal_notifications])->render();
        }

        return [
            'total_unread' => $total_unread,
            'notification_html' => $notification_html
        ];
    }

    private function __chartOptions($title)
    {
        return [
            'yAxis' => [
                    'title' => [
                        'text' => $title
                    ]
                ],
            'legend' => [
                'align' => 'right',
                'verticalAlign' => 'top',
                'floating' => true,
                'layout' => 'vertical'
            ],
        ];
    }

    public function getCalendar()
    {
        $business_id = request()->session()->get('user.business_id');
        $is_admin = $this->restUtil->is_admin(auth()->user(), $business_id);
        $is_superadmin = auth()->user()->can('superadmin');
        if (request()->ajax()) {
            $data = [
                'start_date' => request()->start,
                'end_date' => request()->end,
                'user_id' => ($is_admin || $is_superadmin) && !empty(request()->user_id) ? request()->user_id : auth()->user()->id,
                'location_id' => !empty(request()->location_id) ? request()->location_id : null,
                'business_id' => $business_id,
                'events' => request()->events ?? [],
                'color' => '#007FFF'
            ];
            $events = [];

            if (in_array('bookings', $data['events'])) {
                $events = $this->restUtil->getBookingsForCalendar($data);
            }
            
            $module_events = $this->moduleUtil->getModuleData('calendarEvents', $data);

            foreach ($module_events as $module_event) {
                $events = array_merge($events, $module_event);
            }  

            return $events;
        }

        $all_locations = BusinessLocation::forDropdown($business_id)->toArray();
        $users = [];
        if ($is_admin) {
            $users = User::forDropdown($business_id, false);
        }

        $event_types = [
            'bookings' => [
                'label' => __('restaurant.bookings'),
                'color' => '#007FFF'
            ]
        ];
        $module_event_types = $this->moduleUtil->getModuleData('eventTypes');
        foreach ($module_event_types as $module_event_type) {
            $event_types = array_merge($event_types, $module_event_type);
        }
        
        return view('home.calendar')->with(compact('all_locations', 'users', 'event_types'));
    }

    public function showNotification($id)
    {
        $notification = DatabaseNotification::find($id);

        $data = $notification->data;

        $notification->markAsRead();

        return view('home.notification_modal')->with([
                'notifications' => [$notification]
            ]);
    }

    public function attachMediasToGivenModel(Request $request)
    {   
        if ($request->ajax()) {
            try {
                
                $business_id = request()->session()->get('user.business_id');

                $model_id = $request->input('model_id');
                $model = $request->input('model_type');
                $model_media_type = $request->input('model_media_type');

                DB::beginTransaction();

                //find model to which medias are to be attached
                $model_to_be_attached = $model::where('business_id', $business_id)
                                        ->findOrFail($model_id);

                Media::uploadMedia($business_id, $model_to_be_attached, $request, 'file', false, $model_media_type);

                DB::commit();

                $output = [
                    'success' => true,
                    'msg' => __('lang_v1.success')
                ];
            } catch (Exception $e) {

                DB::rollBack();

                \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

                $output = [
                    'success' => false,
                    'msg' => __('messages.something_went_wrong')
                ];
            }

            return $output;
        }
    }

    public function getUserLocation($latlng)
    {
        $latlng_array = explode(',', $latlng);

        $response = $this->moduleUtil->getLocationFromCoordinates($latlng_array[0], $latlng_array[1]);

        return ['address' => $response];
    }
}
