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

        // Top categories per store (last 30 days, by revenue). Split by
        // genre (sub_category) × condition/format (category) so rows read like
        // "Rock · Sealed Vinyl" and "Rock · Used Vinyl" rather than just "Sealed Vinyl".
        $top_categories_by_location = [];
        $cat_rows = \DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('products as p', 'tsl.product_id', '=', 'p.id')
            ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
            ->leftJoin('categories as sc', 'p.sub_category_id', '=', 'sc.id')
            ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('t.import_source')
            ->where('t.transaction_date', '>=', $since_30)
            ->selectRaw("t.location_id, bl.name as location_name,
                COALESCE(c.name, '(uncategorized)') as category,
                COALESCE(sc.name, '') as genre,
                SUM(tsl.quantity) as qty,
                SUM(tsl.quantity * tsl.unit_price_inc_tax) as revenue")
            ->groupBy('t.location_id', 'bl.name', 'c.name', 'sc.name')
            ->orderByDesc('revenue')
            ->get();
        foreach ($cat_rows as $r) {
            $loc = $r->location_name ?: 'Unknown';
            // Build a display label combining genre + category
            $r->category = $r->genre !== ''
                ? $r->genre . ' · ' . $r->category
                : $r->category;
            if (!isset($top_categories_by_location[$loc])) {
                $top_categories_by_location[$loc] = [];
            }
            if (count($top_categories_by_location[$loc]) < 8) {
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
            ->whereNull('t.import_source')
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
            ->whereNull('t.import_source')
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
        // Exclude contacts created by imports (store-credit xlsx, etc.) — they
        // shouldn't count toward today's in-store rewards-signup goal.
        $rewards_today = \DB::table('contacts as c')
            ->leftJoin('users as u', 'c.created_by', '=', 'u.id')
            ->where('c.business_id', $business_id)
            ->whereIn('c.type', ['customer', 'both'])
            ->whereNull('c.import_source')
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
            ->whereNull('t.import_source')
            ->where('t.created_by', $me_id)
            ->whereBetween('t.transaction_date', [$today_start, $today_end])
            ->count();

        $my_pos_tx_today = (int) \DB::table('transactions')
            ->where('business_id', $business_id)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->whereNull('import_source')
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

        // Resolve Hollywood / Pico location IDs so the All-Stores MTD/YTD cards
        // can toggle between combined, Hollywood-only, and Pico-only views
        // (Sarah 2026-04-22: wanted to see per-store breakdown).
        $sales_locs = \DB::table('business_locations')
            ->where('business_id', $business_id)->get();
        $sales_findLoc = function ($needle) use ($sales_locs) {
            foreach ($sales_locs as $l) {
                if (stripos($l->name, $needle) !== false) return $l;
            }
            return null;
        };
        $sales_loc_hollywood = $sales_findLoc('hollywood');
        $sales_loc_pico      = $sales_findLoc('pico');

        $sumSells = function ($start, $end, $location_id = null) use ($business_id) {
            $q = \DB::table('transactions')
                ->where('business_id', $business_id)
                ->where('type', 'sell')
                ->where('status', 'final')
                ->whereNull('import_source')
                ->whereDate('transaction_date', '>=', $start)
                ->whereDate('transaction_date', '<=', $end);
            if (!is_null($location_id)) {
                $q->where('location_id', $location_id);
            }
            return (float) $q->sum('final_total');
        };

        $sales_scope_defs = [
            ['key' => 'all',       'label' => 'Hollywood + Pico', 'loc_id' => null],
        ];
        if ($sales_loc_hollywood) {
            $sales_scope_defs[] = ['key' => 'hollywood', 'label' => $sales_loc_hollywood->name, 'loc_id' => $sales_loc_hollywood->id];
        }
        if ($sales_loc_pico) {
            $sales_scope_defs[] = ['key' => 'pico', 'label' => $sales_loc_pico->name, 'loc_id' => $sales_loc_pico->id];
        }

        $sales_scope = [];
        foreach ($sales_scope_defs as $def) {
            $mtd = $sumSells($mtd_start, $mtd_end, $def['loc_id']);
            $lm  = $sumSells($last_month_same_start, $last_month_same_end, $def['loc_id']);
            $ytd = $sumSells($ytd_start, $mtd_end, $def['loc_id']);
            $ly  = $sumSells($last_year_same_start, $last_year_same_end, $def['loc_id']);
            $sales_scope[$def['key']] = [
                'label'   => $def['label'],
                'mtd'     => $mtd,
                'lm'      => $lm,
                'ytd'     => $ytd,
                'ly'      => $ly,
                'mom_pct' => $lm > 0 ? (($mtd - $lm) / $lm) * 100 : null,
                'yoy_pct' => $ly > 0 ? (($ytd - $ly) / $ly) * 100 : null,
            ];
        }
        $sales_scope_keys = array_column($sales_scope_defs, 'key');

        // ---- Personal month-over-month achievement ----
        $my_countSellLines = function ($start, $end) use ($business_id, $me_id) {
            return (int) \DB::table('transaction_sell_lines as tsl')
                ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
                ->where('t.business_id', $business_id)
                ->where('t.type', 'sell')
                ->whereNull('t.import_source')
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

        // ==========================================================
        // Top Sellers by Store (genres / artists / records) module
        // Windowed rollup with trend vs previous window.
        // ==========================================================
        $ts_now = \Carbon::now();
        $ts_curr_start = $ts_now->copy()->subDays(29)->startOfDay()->toDateTimeString();
        $ts_curr_end   = $ts_now->copy()->endOfDay()->toDateTimeString();
        $ts_prev_start = $ts_now->copy()->subDays(59)->startOfDay()->toDateTimeString();
        $ts_prev_end   = $ts_now->copy()->subDays(30)->endOfDay()->toDateTimeString();

        // "Genre" = sub_category (Rock, Pop, Hip Hop…); parent category is condition+format
        // (Sealed Vinyl, Used Vinyl, CD…). Render as "Rock · Sealed Vinyl" so the user can
        // distinguish Rock Sealed vs Rock Used.
        $ts_dim_select = [
            'genres'  => "CASE
                WHEN sc.name IS NOT NULL AND c.name IS NOT NULL THEN CONCAT(sc.name, ' · ', c.name)
                WHEN c.name IS NOT NULL THEN c.name
                ELSE '(uncategorized)'
            END as label",
            'artists' => "COALESCE(NULLIF(p.artist, ''), '(unknown artist)') as label",
            'records' => "CONCAT(COALESCE(NULLIF(p.artist, ''), ''), CASE WHEN p.artist IS NOT NULL AND p.artist != '' THEN ' — ' ELSE '' END, p.name) as label",
        ];
        $ts_dim_group = [
            'genres'  => ['sc.name', 'c.name'],
            'artists' => ['p.artist'],
            'records' => ['p.artist', 'p.name'],
        ];

        $tsRollup = function ($location_filter, $dim, $start, $end) use ($business_id, $ts_dim_select, $ts_dim_group) {
            $q = \DB::table('transaction_sell_lines as tsl')
                ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
                ->join('products as p', 'tsl.product_id', '=', 'p.id')
                ->leftJoin('categories as c', 'p.category_id', '=', 'c.id')
                ->leftJoin('categories as sc', 'p.sub_category_id', '=', 'sc.id')
                ->where('t.business_id', $business_id)
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->whereNull('t.import_source')
                ->whereBetween('t.transaction_date', [$start, $end]);
            if ($location_filter === 'online') {
                $q->where('t.is_whatnot', 1);
            } elseif (!empty($location_filter)) {
                $q->where('t.location_id', $location_filter)
                  ->where(function ($w) { $w->where('t.is_whatnot', 0)->orWhereNull('t.is_whatnot'); });
            }
            $q->selectRaw($ts_dim_select[$dim] . ", SUM(tsl.quantity) as units, SUM(tsl.quantity * tsl.unit_price_inc_tax) as revenue")
              ->groupBy($ts_dim_group[$dim])
              ->orderByDesc('revenue')
              ->limit(8);
            return $q->get()->keyBy('label');
        };

        // Physical locations were resolved earlier (see $sales_loc_* / $sales_locs
        // above); reuse them here so the ts tabs stay in sync with the MTD/YTD
        // cards.
        $locs = $sales_locs;
        $loc_hollywood = $sales_loc_hollywood;
        $loc_pico      = $sales_loc_pico;
        $ts_stores = [];
        if ($loc_hollywood) $ts_stores[] = ['key' => 'hollywood', 'label' => $loc_hollywood->name, 'filter' => $loc_hollywood->id];
        if ($loc_pico)      $ts_stores[] = ['key' => 'pico',      'label' => $loc_pico->name,      'filter' => $loc_pico->id];
        // Any other in-store locations we didn't match
        foreach ($locs as $l) {
            if (($loc_hollywood && $l->id === $loc_hollywood->id) || ($loc_pico && $l->id === $loc_pico->id)) continue;
            $ts_stores[] = ['key' => 'loc'.$l->id, 'label' => $l->name, 'filter' => $l->id];
        }
        // Online channels. Whatnot rides the existing is_whatnot=1 flag.
        // Discogs + nivessa.com shown as disabled placeholders until each
        // channel has a way to tag its sales in the transactions table
        // (is_discogs / is_nivessa_online or similar) — wiring those up
        // is a prereq migration + import change, tracked as a follow-up.
        $ts_stores[] = ['key' => 'whatnot', 'label' => 'Whatnot',      'filter' => 'online'];
        $ts_stores[] = ['key' => 'discogs', 'label' => 'Discogs',      'filter' => '__placeholder__'];
        $ts_stores[] = ['key' => 'nivessa', 'label' => 'nivessa.com',  'filter' => '__placeholder__'];

        // Build the rollups for every [store × dimension]
        $ts_data = [];
        foreach ($ts_stores as $s) {
            foreach (['genres', 'artists', 'records'] as $dim) {
                $curr = $tsRollup($s['filter'], $dim, $ts_curr_start, $ts_curr_end);
                $prev = $tsRollup($s['filter'], $dim, $ts_prev_start, $ts_prev_end);
                $top_rev = (float) ($curr->values()->first()->revenue ?? 1);
                $rows = $curr->take(5)->values()->map(function ($r) use ($prev, $top_rev) {
                    $prev_row = $prev->get($r->label);
                    $prev_rev = $prev_row ? (float) $prev_row->revenue : 0;
                    $pct = $prev_rev > 0 ? (((float)$r->revenue - $prev_rev) / $prev_rev) * 100 : null;
                    $tag = 'steady'; $tag_emoji = '';
                    if (!is_null($pct)) {
                        if ($pct >= 20) { $tag = 'hot'; $tag_emoji = '🔥'; }
                        elseif ($pct >= 5) { $tag = 'rising'; }
                        elseif ($pct <= -5) { $tag = 'cooling'; }
                    } elseif (!$prev_row && $r->revenue > 0) {
                        $tag = 'new'; $tag_emoji = '✨';
                    }
                    return (object) [
                        'label' => $r->label,
                        'units' => (int) $r->units,
                        'revenue' => (float) $r->revenue,
                        'bar_pct' => $top_rev > 0 ? max(4, min(100, ((float)$r->revenue / $top_rev) * 100)) : 0,
                        'trend_pct' => $pct,
                        'tag' => $tag,
                        'tag_emoji' => $tag_emoji,
                    ];
                });
                $ts_data[$s['key']][$dim] = $rows;
            }
        }

        // Auto-insight: biggest riser in the first store's genres list
        $ts_insight = null;
        if (!empty($ts_stores) && isset($ts_data[$ts_stores[0]['key']]['genres'])) {
            $first_store_genres = $ts_data[$ts_stores[0]['key']]['genres'];
            $risers = $first_store_genres->filter(function ($r) { return !is_null($r->trend_pct) && $r->trend_pct >= 10; })->sortByDesc('trend_pct')->values();
            if ($risers->count() >= 2) {
                $ts_insight = $risers[0]->label . ' and ' . $risers[1]->label . ' are heating up — good time to face those sections out.';
            } elseif ($risers->count() === 1) {
                $ts_insight = $risers[0]->label . ' is heating up (+' . number_format($risers[0]->trend_pct, 0) . '%) — good time to face it out.';
            }
        }

        // ==========================================================
        // Nick-style personal progress dashboard
        // Per-employee $/hr today, vs 30-day avg, 7-day streak, goals
        // ==========================================================
        $now2 = \Carbon::now();
        $tz  = null; // use server tz
        $today_start_p = $now2->copy()->startOfDay();
        $today_end_p   = $now2->copy()->endOfDay();

        // Hours worked in a window for the logged-in user
        $hoursForMe = function ($start, $end) use ($business_id, $me_id) {
            $row = \DB::table('cash_registers')
                ->where('business_id', $business_id)
                ->where('user_id', $me_id)
                ->where('created_at', '<=', $end)
                ->where(function ($q) use ($start) {
                    $q->where('closed_at', '>=', $start)->orWhereNull('closed_at');
                })
                ->selectRaw("COALESCE(SUM(
                    TIMESTAMPDIFF(SECOND, GREATEST(created_at, ?), LEAST(COALESCE(closed_at, NOW()), ?))
                ) / 3600.0, 0) as hrs")
                ->addBinding([$start, $end], 'select')
                ->value('hrs');
            return (float) ($row ?? 0);
        };
        $revForMe = function ($start, $end) use ($business_id, $me_id) {
            return (float) \DB::table('transactions')
                ->where('business_id', $business_id)
                ->where('type', 'sell')
                ->where('status', 'final')
                ->whereNull('import_source')
                ->where('created_by', $me_id)
                ->whereBetween('transaction_date', [$start, $end])
                ->sum('final_total');
        };

        // Today
        $my_today_hrs = $hoursForMe($today_start_p->toDateTimeString(), $today_end_p->toDateTimeString());
        $my_today_rev = $revForMe($today_start_p->toDateTimeString(), $today_end_p->toDateTimeString());
        $my_today_rph = $my_today_hrs >= 0.25 ? $my_today_rev / $my_today_hrs : null;

        // Last 30 days (rolling) average $/hr
        $p30_start = $now2->copy()->subDays(29)->startOfDay()->toDateTimeString();
        $p30_end   = $now2->copy()->endOfDay()->toDateTimeString();
        $p30_hrs = $hoursForMe($p30_start, $p30_end);
        $p30_rev = $revForMe($p30_start, $p30_end);
        $my_30d_rph_avg = $p30_hrs >= 0.25 ? $p30_rev / $p30_hrs : null;

        $my_vs_30d_pct = (!is_null($my_today_rph) && !is_null($my_30d_rph_avg) && $my_30d_rph_avg > 0)
            ? (($my_today_rph - $my_30d_rph_avg) / $my_30d_rph_avg) * 100
            : null;

        // 7-day streak — compute $/hr per day for the last 7 days ending today
        $my_7day = [];
        $my_7day_best_rph = 0;
        $my_7day_best_day = null;
        for ($i = 6; $i >= 0; $i--) {
            $d = $now2->copy()->subDays($i);
            $ds = $d->copy()->startOfDay()->toDateTimeString();
            $de = $d->copy()->endOfDay()->toDateTimeString();
            $h  = $hoursForMe($ds, $de);
            $r  = $revForMe($ds, $de);
            $rph = $h >= 0.25 ? $r / $h : null;
            $entry = (object) [
                'day'     => $d->format('D'),
                'date'    => $d->format('Y-m-d'),
                'is_today' => $i === 0,
                'rph'     => $rph,
                'above_avg' => !is_null($rph) && !is_null($my_30d_rph_avg) && $rph > $my_30d_rph_avg,
            ];
            $my_7day[] = $entry;
            if (!is_null($rph) && $rph > $my_7day_best_rph) {
                $my_7day_best_rph = $rph;
                $my_7day_best_day = $d->format('D');
            }
        }
        $my_streak_above = collect($my_7day)->filter(function ($e) { return $e->above_avg; })->count();
        // Normalized bar heights
        $my_7day_max_rph = max(1, collect($my_7day)->max(function ($e) { return $e->rph ?? 0; }));
        foreach ($my_7day as $e) {
            $e->bar_pct = $e->rph ? max(20, min(100, ($e->rph / $my_7day_max_rph) * 100)) : 18;
        }
        $my_beat_gap = !is_null($my_today_rph) ? max(0, $my_7day_best_rph - $my_today_rph) : $my_7day_best_rph;

        // Daily goals (hardcoded for MVP — could become configurable later)
        $goal_priced_today = 10;
        $goal_rewards_today = 3;
        $rewards_me_today = (int) \DB::table('contacts')
            ->where('business_id', $business_id)
            ->whereIn('type', ['customer', 'both'])
            ->whereNull('import_source')
            ->where('created_by', $me_id)
            ->whereBetween('created_at', [$today_start, $today_end])
            ->count();

        // "Nice sales today" — your top 4 line items today
        $my_top_today = \DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('products as p', 'tsl.product_id', '=', 'p.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('t.import_source')
            ->where('t.created_by', $me_id)
            ->whereBetween('t.transaction_date', [$today_start, $today_end])
            ->selectRaw("p.artist, p.name, p.format, tsl.unit_price_inc_tax as price")
            ->orderByDesc('tsl.unit_price_inc_tax')
            ->limit(4)
            ->get();
        $my_today_items_total = (int) \DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('t.import_source')
            ->where('t.created_by', $me_id)
            ->whereBetween('t.transaction_date', [$today_start, $today_end])
            ->count();

        // Team goal for my current location today — average same-day-of-week revenue over the last 12 weeks
        $my_default_loc_id = \DB::table('business_locations')
            ->where('business_id', $business_id)
            ->orderBy('id')
            ->value('id');
        $team_location_name = \DB::table('business_locations')
            ->where('id', $my_default_loc_id)->value('name');
        $team_today_rev = (float) \DB::table('transactions')
            ->where('business_id', $business_id)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->whereNull('import_source')
            ->where('location_id', $my_default_loc_id)
            ->whereBetween('transaction_date', [$today_start, $today_end])
            ->sum('final_total');

        // Same day of week (1=Sunday..7=Saturday in MySQL DAYOFWEEK) over the last 12 weeks,
        // excluding today, to set a realistic per-store daily goal.
        $dow_lookback_start = \Carbon::now()->subWeeks(12)->startOfDay()->toDateTimeString();
        $dow_lookback_end   = \Carbon::now()->subDay()->endOfDay()->toDateTimeString();
        $dow_today          = (int) \Carbon::now()->dayOfWeekIso; // 1=Mon..7=Sun
        // Convert ISO (Mon=1..Sun=7) to MySQL DAYOFWEEK (Sun=1..Sat=7)
        $dow_mysql = $dow_today === 7 ? 1 : $dow_today + 1;

        $dow_daily_totals = \DB::table('transactions')
            ->where('business_id', $business_id)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->whereNull('import_source')
            ->where('location_id', $my_default_loc_id)
            ->whereBetween('transaction_date', [$dow_lookback_start, $dow_lookback_end])
            ->whereRaw('DAYOFWEEK(transaction_date) = ?', [$dow_mysql])
            ->selectRaw('DATE(transaction_date) as d, SUM(final_total) as rev')
            ->groupBy('d')
            ->pluck('rev')
            ->map(fn($v) => (float) $v)
            ->filter(fn($v) => $v > 0) // ignore closed days
            ->values();

        if ($dow_daily_totals->count() > 0) {
            $avg = $dow_daily_totals->avg();
            // Round to nearest $100 for a clean display
            $team_goal = max(100, (int) (round($avg / 100) * 100));
        } else {
            $team_goal = 5000; // fallback for stores with no history on this DOW
        }

        // Pace: compare today's rev to where same-DOW history says we "should" be by now,
        // so the progress bar isn't measured against a 10-hour goal with 5 hours left.
        $hourly_rev = \DB::table('transactions')
            ->where('business_id', $business_id)
            ->where('type', 'sell')
            ->where('status', 'final')
            ->whereNull('import_source')
            ->where('location_id', $my_default_loc_id)
            ->whereBetween('transaction_date', [$dow_lookback_start, $dow_lookback_end])
            ->whereRaw('DAYOFWEEK(transaction_date) = ?', [$dow_mysql])
            ->selectRaw('HOUR(transaction_date) as h, SUM(final_total) as rev')
            ->groupBy('h')
            ->pluck('rev', 'h')
            ->toArray();

        $hourly_total = array_sum($hourly_rev);
        $now = \Carbon::now();
        if ($hourly_total > 0) {
            $cum = 0.0;
            for ($h = 0; $h < $now->hour; $h++) {
                $cum += (float) ($hourly_rev[$h] ?? 0);
            }
            // Partial credit for the current hour based on minutes elapsed
            $cum += (float) ($hourly_rev[$now->hour] ?? 0) * ($now->minute / 60.0);
            $pace_fraction = min(1.0, $cum / $hourly_total);
        } else {
            // No hourly history → fall back to linear fraction of the day
            $pace_fraction = ($now->hour + $now->minute / 60.0) / 24.0;
        }

        $team_goal_so_far = (int) round($team_goal * $pace_fraction);
        // % against pace-so-far (uncapped so overachievement shows), with a separate capped bar width
        $team_pct = $team_goal_so_far > 0
            ? ($team_today_rev / $team_goal_so_far) * 100
            : ($team_today_rev > 0 ? 100 : 0);
        $team_bar_width = max(0, min(100, $team_pct));

        $me_first_name = auth()->user()->first_name ?? 'there';

        // ---- Leaderboard top 3 this week (reuses ReportController logic) ----
        $week_start = \Carbon::now()->startOfWeek()->toDateTimeString();
        $week_end = \Carbon::now()->endOfDay()->toDateTimeString();
        $leaderboard_top3 = app(\App\Http\Controllers\ReportController::class)
            ->buildLeaderboardRows($business_id, $week_start, $week_end, 3);

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
            ->whereNull('t.import_source')
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
            ->whereNull('t.import_source')
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

        // Most expensive items sold in the last 7 days (by unit price)
        $top_expensive_items = \DB::table('transaction_sell_lines as tsl')
            ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
            ->join('products as p', 'tsl.product_id', '=', 'p.id')
            ->leftJoin('business_locations as bl', 't.location_id', '=', 'bl.id')
            ->where('t.business_id', $business_id)
            ->where('t.type', 'sell')
            ->where('t.status', 'final')
            ->whereNull('t.import_source')
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
            'sales_scope', 'sales_scope_keys',
            'my_mtd_rung', 'my_lm_rung', 'my_rung_pct',
            'my_mtd_priced', 'my_lm_priced', 'my_priced_pct',
            'avg_per_employee',
            'my_priced_rev_mtd', 'my_priced_rev_lm', 'my_priced_rev_lifetime', 'my_priced_rev_pct',
            'active_wants', 'active_wants_count',
            'leaderboard_top3',
            // Personal progress dashboard
            'me_first_name',
            'my_today_hrs', 'my_today_rev', 'my_today_rph',
            'my_30d_rph_avg', 'my_vs_30d_pct',
            'my_7day', 'my_streak_above', 'my_7day_best_rph', 'my_7day_best_day', 'my_beat_gap',
            'goal_priced_today', 'goal_rewards_today', 'rewards_me_today',
            'my_top_today', 'my_today_items_total',
            'team_location_name', 'team_today_rev', 'team_goal', 'team_pct',
            'team_goal_so_far', 'team_bar_width',
            // Top sellers by store module
            'ts_stores', 'ts_data', 'ts_insight'
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
