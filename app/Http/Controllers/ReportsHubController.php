<?php

namespace App\Http\Controllers;

use App\Utils\BusinessUtil;
use Illuminate\Http\Request;
use DB;

class ReportsHubController extends Controller
{
    protected $businessUtil;

    public function __construct(BusinessUtil $businessUtil)
    {
        $this->businessUtil = $businessUtil;
    }

    /**
     * Canonical catalog of every report surfaced in the ERP, grouped into
     * functional sections. Each entry: key (unique, stable), name, icon,
     * description, action (Controller@method), and visible-to-whom guard.
     *
     * Keeping this as a static array for now — later could be moved to a
     * DB table so admins can hide/reorder without a code push.
     */
    public static function catalog()
    {
        return [
            'sales' => [
                'title' => 'Sales & Revenue',
                'icon'  => 'fa-chart-line',
                'reports' => [
                    ['key' => 'profit-loss',           'name' => 'Profit / Loss Report',  'icon' => 'fa-balance-scale',      'action' => 'ReportController@getProfitLoss',              'desc' => 'Full P&L by date range.'],
                    ['key' => 'sale-report',           'name' => 'Product Sell Report',   'icon' => 'fa-receipt',            'action' => 'ReportController@saleReport',                 'desc' => 'Revenue by product.'],
                    ['key' => 'sales-by-item',         'name' => 'Sales by Item (Cost & Margin)', 'icon' => 'fa-tags',       'action' => 'ReportController@getSellReportByItem',        'desc' => 'Sell report with cost + margin.'],
                    ['key' => 'sales-rep',             'name' => 'Sales Representative Report', 'icon' => 'fa-user-tie',     'action' => 'ReportController@getSalesRepresentativeReport','desc' => 'Sales by sales rep.'],
                    ['key' => 'sell-payment',          'name' => 'Sell Payment Report',   'icon' => 'fa-money-check',        'action' => 'ReportController@getSellPaymentReport',       'desc' => 'Payments against sells.'],
                    ['key' => 'purchase-sell',         'name' => 'Purchase & Sale',       'icon' => 'fa-exchange-alt',       'action' => 'ReportController@getPurchaseSell',            'desc' => 'Purchase vs sell summary.'],
                ],
            ],
            'inventory' => [
                'title' => 'Inventory',
                'icon'  => 'fa-warehouse',
                'reports' => [
                    ['key' => 'stock-report',          'name' => 'Stock Report',          'icon' => 'fa-boxes',              'action' => 'ReportController@getStockReport',             'desc' => 'Stock on hand by location.'],
                    ['key' => 'stock-details',         'name' => 'Stock Details',         'icon' => 'fa-box-open',           'action' => 'ReportController@getStockDetails',            'desc' => 'Line-level stock details.'],
                    ['key' => 'stock-adjustment',      'name' => 'Stock Adjustment Report', 'icon' => 'fa-sliders-h',        'action' => 'ReportController@getStockAdjustmentReport',   'desc' => 'Stock adjustments history.'],
                    ['key' => 'stock-by-sell-price',   'name' => 'Stock by Sell Price',   'icon' => 'fa-dollar-sign',        'action' => 'ReportController@getStockBySellingPrice',     'desc' => 'Stock valued at selling price.'],
                    ['key' => 'inventory-aging',       'name' => 'Inventory Aging Summary','icon' => 'fa-hourglass-half',    'action' => 'ReportController@inventoryAgingSummary',      'desc' => 'Aging buckets of inventory.'],
                    ['key' => 'inventory-valuation-detail',  'name' => 'Inventory Valuation Detail',  'icon' => 'fa-coins', 'action' => 'ReportController@inventoryValuationDetail',  'desc' => 'Line-level valuation.'],
                    ['key' => 'inventory-valuation-summary', 'name' => 'Inventory Valuation Summary', 'icon' => 'fa-chart-pie','action' => 'ReportController@inventoryValuationSummary', 'desc' => 'Totals by category/brand.'],
                    ['key' => 'abc-inventory',         'name' => 'ABC Inventory Classification', 'icon' => 'fa-layer-group',  'action' => 'ReportController@abcInventoryClassification', 'desc' => 'ABC analysis by revenue.'],
                    ['key' => 'landed-cost-summary',   'name' => 'Landed Cost Summary',   'icon' => 'fa-shipping-fast',      'action' => 'ReportController@landedCostSummary',          'desc' => 'Landed cost rollup.'],
                    ['key' => 'opening-stock',         'name' => 'Opening Stock',         'icon' => 'fa-archive',            'action' => 'ReportController@getOpeningStock',            'desc' => 'Opening stock records.'],
                    ['key' => 'stock-expiry',          'name' => 'Stock Expiry',          'icon' => 'fa-calendar-times',     'action' => 'ReportController@getStockExpiryReport',       'desc' => 'Items nearing expiry.'],
                    ['key' => 'dead-stock',            'name' => 'Dead Stock',            'icon' => 'fa-snowflake',          'action' => 'ReportController@deadStockReport',            'desc' => 'Items on hand that haven\'t sold in X days.'],
                ],
            ],
            'products' => [
                'title' => 'Products',
                'icon'  => 'fa-compact-disc',
                'reports' => [
                    ['key' => 'item-transaction-history', 'name' => 'Item Transaction History', 'icon' => 'fa-history',       'action' => 'ReportController@itemTransactionHistory',     'desc' => 'All movement for a single item.'],
                    ['key' => 'items-report',          'name' => 'Items Report',          'icon' => 'fa-list-alt',           'action' => 'ReportController@itemsReport',                'desc' => 'Item-level report.'],
                    ['key' => 'trending-products',     'name' => 'Trending Products',     'icon' => 'fa-fire',               'action' => 'ReportController@getTrendingProducts',        'desc' => 'What\'s moving.'],
                    ['key' => 'product-purchase',      'name' => 'Product Purchase Report','icon' => 'fa-shopping-cart',     'action' => 'ReportController@getproductPurchaseReport',   'desc' => 'Purchase by product.'],
                    ['key' => 'purchases-by-item-vendor', 'name' => 'Purchases by Item/Vendor', 'icon' => 'fa-truck-loading',  'action' => 'ReportController@purchasesByItemVendor',      'desc' => 'Purchases grouped by vendor.'],
                    ['key' => 'po-vs-received',        'name' => 'PO vs Received',        'icon' => 'fa-check-double',       'action' => 'ReportController@purchaseOrderVsReceived',    'desc' => 'POs against received quantities.'],
                    ['key' => 'category-report',       'name' => 'Category Sales Report', 'icon' => 'fa-tags',               'action' => 'ReportController@categorySalesReport',        'desc' => 'Sales by category.'],
                ],
            ],
            'reconciliation' => [
                'title' => 'Reconciliation & Channels',
                'icon'  => 'fa-balance-scale',
                'reports' => [
                    ['key' => 'clover-vs-erp',         'name' => 'Clover vs ERP Recon',   'icon' => 'fa-balance-scale',      'action' => 'ReportController@cloverVsErpReport',          'desc' => 'Daily card/Clover recon.'],
                    ['key' => 'whatnot-sales',         'name' => 'Whatnot Sales',         'icon' => 'fa-tv',                 'action' => 'ReportController@whatnotReport',              'desc' => 'Live-auction vs in-store/online.'],
                    ['key' => 'register-report',       'name' => 'Register Report',       'icon' => 'fa-cash-register',      'action' => 'ReportController@getRegisterReport',          'desc' => 'Register activity + close-outs.'],
                    ['key' => 'tax-report',            'name' => 'Tax Report',            'icon' => 'fa-percent',            'action' => 'ReportController@getTaxReport',               'desc' => 'Tax collected summary.'],
                    ['key' => 'tax-details',           'name' => 'Tax Details',           'icon' => 'fa-file-invoice',       'action' => 'ReportController@getTaxDetails',              'desc' => 'Tax line-level detail.'],
                ],
            ],
            'people' => [
                'title' => 'People',
                'icon'  => 'fa-users',
                'reports' => [
                    ['key' => 'employee-leaderboard',  'name' => 'Employee Leaderboard',  'icon' => 'fa-trophy',             'action' => 'ReportController@employeeLeaderboard',        'desc' => 'Ranked by $ / hour.'],
                    ['key' => 'employee-productivity', 'name' => 'Employee Productivity', 'icon' => 'fa-user-clock',         'action' => 'ReportController@productEntryProductivity',   'desc' => 'Products priced + purchases entered.'],
                    ['key' => 'customer-wants',        'name' => 'Customer Wants',        'icon' => 'fa-heart',              'action' => 'CustomerWantController@index',                'desc' => 'Call-me-when-it-comes-in list.'],
                    ['key' => 'customer-groups',       'name' => 'Customer Groups Report','icon' => 'fa-users-cog',          'action' => 'ReportController@getCustomerGroupsReport',    'desc' => 'Sales by customer group.'],
                    ['key' => 'supplier-customer',     'name' => 'Supplier & Customer Report', 'icon' => 'fa-handshake',     'action' => 'ReportController@getCustomerSuppliers',       'desc' => 'Supplier + customer rollup.'],
                ],
            ],
            'operations' => [
                'title' => 'Operations',
                'icon'  => 'fa-tools',
                'reports' => [
                    ['key' => 'expense-report',        'name' => 'Expense Report',        'icon' => 'fa-receipt',            'action' => 'ReportController@getExpenseReport',           'desc' => 'Expenses by category.'],
                    ['key' => 'purchase-payment',      'name' => 'Purchase Payment Report', 'icon' => 'fa-hand-holding-usd', 'action' => 'ReportController@getPurchasePaymentReport',   'desc' => 'Payments against purchases.'],
                    ['key' => 'activity-log',          'name' => 'Activity Log',          'icon' => 'fa-stream',             'action' => 'ReportController@activityLog',                'desc' => 'System activity audit trail.'],
                ],
            ],
        ];
    }

    /** Flat list of all reports keyed by key, for quick lookups. */
    public static function catalogFlat()
    {
        $flat = [];
        foreach (self::catalog() as $section_key => $section) {
            foreach ($section['reports'] as $r) {
                $r['section'] = $section_key;
                $r['section_title'] = $section['title'];
                $flat[$r['key']] = $r;
            }
        }
        return $flat;
    }

    public function index(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $user_id = auth()->user()->id;

        $favorite_keys = [];
        if (\Schema::hasTable('user_report_favorites')) {
            $favorite_keys = DB::table('user_report_favorites')
                ->where('user_id', $user_id)
                ->where('business_id', $business_id)
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('report_key')
                ->toArray();
        }

        $catalog = self::catalog();
        $flat = self::catalogFlat();

        // Build favorites list (preserving user's order), filter out unknown keys
        $favorites = [];
        foreach ($favorite_keys as $k) {
            if (isset($flat[$k])) {
                $favorites[] = $flat[$k];
            }
        }

        return view('report.reports_hub')->with(compact('catalog', 'favorites', 'favorite_keys'));
    }

    public function toggleFavorite(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $user_id = auth()->user()->id;
        $report_key = $request->input('report_key');

        if (empty($report_key) || !isset(self::catalogFlat()[$report_key])) {
            return response()->json(['ok' => false, 'msg' => 'Unknown report key'], 400);
        }
        if (!\Schema::hasTable('user_report_favorites')) {
            return response()->json(['ok' => false, 'msg' => 'Migration not yet run'], 500);
        }

        $existing = DB::table('user_report_favorites')
            ->where('user_id', $user_id)
            ->where('report_key', $report_key)
            ->first();

        if ($existing) {
            DB::table('user_report_favorites')->where('id', $existing->id)->delete();
            return response()->json(['ok' => true, 'favorited' => false]);
        }

        DB::table('user_report_favorites')->insert([
            'user_id' => $user_id,
            'business_id' => $business_id,
            'report_key' => $report_key,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['ok' => true, 'favorited' => true]);
    }
}
