<?php

namespace App\Http\Controllers;

use App\Brands;
use App\Business;
use App\BusinessLocation;
use App\Category;
use App\Media;
use App\ManualItemPriceRule;
use App\Product;
use App\ProductVariation;
use App\PurchaseLine;
use App\SellingPriceGroup;
use App\TaxRate;
use App\TransactionSellLine;
use App\Unit;
use App\Utils\ModuleUtil;
use App\Utils\ProductUtil;
use App\Variation;
use App\VariationGroupPrice;
use App\VariationLocationDetails;
use App\VariationTemplate;
use App\Warranty;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Milon\Barcode\DNS1D;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Yajra\DataTables\Facades\DataTables;
use App\Exports\ProductsExport;
use App\Services\DiscogsService;
use App\Services\ArtistTitleAutocompleteService;
use App\Transaction;
use Excel;
use ZipArchive;
use Illuminate\Support\Facades\Validator;
use App\Services\EbayService;

class ProductController extends Controller
{
    /**
     * All Utils instance.
     *
     */
    protected $productUtil;
    protected $moduleUtil;
    protected $transactionUtil;

    private $barcode_types;

    private EbayService $ebayService;
    private DiscogsService $discogsService;

    /**
     * Constructor
     *
     * @param ProductUtils $product
     * @return void
     */
    public function __construct(ProductUtil $productUtil, ModuleUtil $moduleUtil, EbayService $ebayService, DiscogsService $discogsService)
    {
        $this->productUtil = $productUtil;
        $this->moduleUtil = $moduleUtil;
        $this->ebayService = $ebayService;
        $this->discogsService = $discogsService;

        //barcode types
        $this->barcode_types = $this->productUtil->barcode_types();
    }
    
    /**
     * Get business_id safely from session or user
     *
     * @return int
     */
    protected function getBusinessId()
    {
        // Use same pattern as other controllers (HomeController, SellPosController, etc.)
        return request()->session()->get('user.business_id');
    }
    
    /**
     * Get session value safely
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    protected function getSessionValue($key, $default = null)
    {
        // Use same pattern as other controllers
        return request()->session()->get($key, $default);
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        if (!auth()->user()->can('product.view') && !auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = $this->getBusinessId();
        $selling_price_group_count = SellingPriceGroup::countSellingPriceGroups($business_id);
        $is_woocommerce = $this->moduleUtil->isModuleInstalled('Woocommerce');

        if (request()->ajax()) {
            //Filter by location
            $location_id = request()->get('location_id', null);
            $permitted_locations = auth()->user()->permitted_locations();
            $soldTotalsSubquery = DB::table('transaction_sell_lines as tsl')
                ->join('transactions as t', 'tsl.transaction_id', '=', 't.id')
                ->where('t.type', 'sell')
                ->where('t.status', 'final')
                ->select('tsl.product_id', DB::raw('SUM(tsl.quantity) as total_sold_qty'))
                ->groupBy('tsl.product_id');

            $query = Product::with(['media'])
                ->leftJoin('brands', 'products.brand_id', '=', 'brands.id')
                ->join('units', 'products.unit_id', '=', 'units.id')
                ->leftJoin('categories as c1', 'products.category_id', '=', 'c1.id')
                ->leftJoin('categories as c2', 'products.sub_category_id', '=', 'c2.id')
                ->leftJoin('tax_rates', 'products.tax', '=', 'tax_rates.id')
                ->leftJoin('users as u', 'products.created_by', '=', 'u.id')
                ->join('variations as v', 'v.product_id', '=', 'products.id')
                ->leftJoinSub($soldTotalsSubquery, 'sold_totals', function ($join) {
                    $join->on('sold_totals.product_id', '=', 'products.id');
                })
                ->leftJoin('variation_location_details as vld', function($join) use ($permitted_locations, $location_id){
                    $join->on('vld.variation_id', '=', 'v.id');
                    if ($permitted_locations != 'all') {
                        $join->whereIn('vld.location_id', $permitted_locations);
                    }
                    // When list is filtered by location, show current_stock for that location only
                    if (!empty($location_id) && $location_id != 'none') {
                        $join->where('vld.location_id', '=', $location_id);
                    }
                })
                ->whereNull('v.deleted_at')
                ->where('products.business_id', $business_id)
                ->where('products.type', '!=', 'modifier');

            if (!empty($location_id) && $location_id != 'none') {
                if ($permitted_locations == 'all' || in_array($location_id, $permitted_locations)) {
                    $query->whereHas('product_locations', function ($query) use ($location_id) {
                        $query->where('product_locations.location_id', '=', $location_id);
                    });
                }
            } elseif ($location_id == 'none') {
                $query->doesntHave('product_locations');
            } else {
                if ($permitted_locations != 'all') {
                    $query->whereHas('product_locations', function ($query) use ($permitted_locations) {
                        $query->whereIn('product_locations.location_id', $permitted_locations);
                    });
                } else {
                    $query->with('product_locations');
                }
            }
            $query->with('sales_person');

            $products = $query->select(
                'products.id',
                'products.name as product',
                'products.type',
                'c1.name as category',
                'c2.name as sub_category',
                'units.actual_name as unit',
                'brands.name as brand',
                'products.artist',
                'tax_rates.name as tax',
                'products.sku',
                'products.image',
                'products.enable_stock',
                'products.is_inactive',
                'products.not_for_selling',
                'products.product_custom_field1',
                'products.product_custom_field2',
                'products.product_custom_field3',
                'products.created_by',
                'products.created_at',
                'products.updated_at',
                'products.product_custom_field4',
                'v.id as vid',
                'products.alert_quantity',
                DB::raw("CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) as created_by_name"),
                DB::raw('COALESCE(MAX(sold_totals.total_sold_qty), 0) as total_sold_qty'),
                DB::raw('SUM(vld.qty_available) as current_stock'),
                DB::raw('MAX(v.sell_price_inc_tax) as max_price'),
                DB::raw('MIN(v.sell_price_inc_tax) as min_price'),
                DB::raw('MAX(v.dpp_inc_tax) as max_purchase_price'),
                DB::raw('MIN(v.dpp_inc_tax) as min_purchase_price'),
                // Real "last updated" — newest of products.updated_at and any
                // variation update for this product, ignoring values in the
                // future (corrupt rows from a sync with bad server time).
                DB::raw('GREATEST(
                    COALESCE(IF(products.updated_at > NOW(), NULL, products.updated_at), "1970-01-01"),
                    COALESCE(MAX(IF(v.updated_at > NOW(), NULL, v.updated_at)), "1970-01-01")
                ) as real_updated_at')
                );

            //if woocomerce enabled add field to query
            if ($is_woocommerce) {
                $products->addSelect('woocommerce_disable_sync');
            }
            
            $products->groupBy('products.id');

            $type = request()->get('type', null);
            if (!empty($type)) {
                $products->where('products.type', $type);
            }

            $category_id = request()->get('category_id', null);
            if (!empty($category_id)) {
                $products->where('products.category_id', $category_id);
            }
            
            $sub_category_id = request()->get('sub_category_id', null);
            if (!empty($sub_category_id)) {
                $products->where('products.sub_category_id', $sub_category_id);
            }
            
            // Filter for uncategorized products
            $uncategorized_only = request()->get('uncategorized_only', 0);
            if ($uncategorized_only == 1 || $uncategorized_only === '1' || $uncategorized_only === true) {
                $products->whereNull('products.category_id');
            }

            $brand_id = request()->get('brand_id', null);
            if (!empty($brand_id)) {
                $products->where('products.brand_id', $brand_id);
            }

            $unit_id = request()->get('unit_id', null);
            if (!empty($unit_id)) {
                $products->where('products.unit_id', $unit_id);
            }

            $tax_id = request()->get('tax_id', null);
            if (!empty($tax_id)) {
                $products->where('products.tax', $tax_id);
            }

            $active_state = request()->get('active_state', null);
            if ($active_state == 'active') {
                $products->Active();
            }
            if ($active_state == 'inactive') {
                $products->Inactive();
            }
            $not_for_selling = request()->get('not_for_selling', null);
            if ($not_for_selling == 'true') {
                $products->ProductNotForSales();
            }

            $woocommerce_enabled = request()->get('woocommerce_enabled', 0);
            if ($woocommerce_enabled == 1) {
                $products->where('products.woocommerce_disable_sync', 0);
            }

            if (!empty(request()->get('repair_model_id'))) {
                $products->where('products.repair_model_id', request()->get('repair_model_id'));
            }

            // Filter by created_by
            $created_by = request()->get('created_by', null);
            if (!empty($created_by)) {
                $products->where('products.created_by', $created_by);
            }

            // Filter by date range
            $start_date = request()->get('start_date', null);
            $end_date = request()->get('end_date', null);
            if (!empty($start_date)) {
                $products->whereDate('products.created_at', '>=', $start_date);
            }
            if (!empty($end_date)) {
                $products->whereDate('products.created_at', '<=', $end_date);
            }

            $ebayConfigured = $this->ebayService->isConfigured();
            $discogsConfigured = $this->discogsService->isConfigured();

            return Datatables::of($products)
                ->addColumn(
                    'product_locations',
                    function ($row) {
                        return $row->product_locations->implode('name', ', ');
                    }
                )
                ->addColumn('total_sold', function ($q) {
                    return number_format((int) round((float) $q->total_sold_qty), 0);
                })
                ->editColumn('category', '{{$category}}')
                ->addColumn('subcategory', function ($row) {
                    return $row->sub_category ?? '';
                })
                ->editColumn('artist', function ($row) {
                    return $row->artist ?? 'N/A';
                })
                ->addColumn(
                    'action',
                    function ($row) use ($selling_price_group_count, $ebayConfigured, $discogsConfigured) {
                        // Compact grey actions group: Labels button + dropdown
                        $html = '<div class="btn-group btn-group-xs">';

                        // Label icon (always visible)
                        $html .= '<a href="' . action('LabelsController@show') . '?product_id=' . $row->id . '" class="btn btn-default" data-toggle="tooltip" title="' . __('barcode.labels') . '"><i class="fa fa-tag"></i></a>';

                        // More dropdown (contains Edit, Delete, stock actions, etc.)
                        $html .= '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    <i class="fa fa-ellipsis-v"></i>
                                  </button>';
                        $html .= '<ul class="dropdown-menu" role="menu">';
                        
                        // Edit product entry
                        if (
                            auth()->user()->can('product.update') ||
                            auth()->user()->can('product.opening_stock') ||
                            $this->productUtil->is_admin(auth()->user())
                        ) {
                            $html .= '<li><a href="' . action('ProductController@edit', [$row->id]) . '"><i class="fa fa-pencil"></i> ' . __("messages.edit") . '</a></li>';
                        }

                        // Delete and status actions
                        if (auth()->user()->can('product.delete')) {
                            $html .= '<li><a href="' . action('ProductController@destroy', [$row->id]) . '" class="delete-product"><i class="fa fa-trash"></i> ' . __("messages.delete") . '</a></li>';
                        }

                        if ($row->is_inactive == 1) {
                            $html .= '<li><a href="' . action('ProductController@activate', [$row->id]) . '" class="activate-product"><i class="fas fa-check-circle"></i> ' . __("lang_v1.reactivate") . '</a></li>';
                        }

                        // Marketplace listings — push this product as a live listing on Discogs / eBay.
                        // Endpoints are POST /products/{id}/list-to-{platform}; each takes the product's
                        // current price/stock/name and hands off to the platform-specific service.
                        if ($ebayConfigured || $discogsConfigured) {
                            $html .= '<li class="divider"></li>';
                        }
                        if ($discogsConfigured) {
                            $html .= '<li><a href="#" data-id="' . $row->id . '" class="list-to-discogs"><i class="fa fa-music"></i> List on Discogs</a></li>';
                        }
                        if ($ebayConfigured) {
                            $html .= '<li><a href="#" data-id="' . $row->id . '" class="list-to-ebay"><i class="fa fa-shopping-cart"></i> List on eBay</a></li>';
                        }

                        if ($row->enable_stock == 1 && auth()->user()->can('product.opening_stock')) {
                            $html .= '<li class="divider"></li>';
                            $html .= '<li><a href="#" data-href="' . action('OpeningStockController@add', ['product_id' => $row->id]) . '" class="add-opening-stock"><i class="fa fa-database"></i> ' . __("lang_v1.add_edit_opening_stock") . '</a></li>';
                            $html .= '<li><a href="#" onclick="openAddStock(this)" data-pr="'.$row->id.'"  data-stock="1" data-vr="'.$row->vid.'" class="add-stock"><i class="fa fa-database"></i>  Add 1 Stock and Print Label</a></li>';
                            $html .= '<li><a href="#" onclick="openAddStock(this)" data-pr="'.$row->id.'" data-vr="'.$row->vid.'" data-stock="2" class="add-stock"><i class="fa fa-database"></i>  Add 2 Stock and Print Label</a></li>';
                            $html .= '<li><a href="#" onclick="openAddStock(this)" data-pr="'.$row->id.'" data-vr="'.$row->vid.'"  data-stock="3" class="add-stock"><i class="fa fa-database"></i>  Add 3 Stock and Print Label</a></li>';
                            // Quick access: Set current stock (small dedicated page)
                            $html .= '<li class="divider"></li>';
                            $html .= '<li><a href="' . action('ProductController@setCurrentStockQuickPage', [$row->id]) . '"><i class="fa fa-balance-scale"></i> ' . __('product.set_current_stock') . '</a></li>';
                        }

                        $html .= '</ul></div>';
                        return $html;
                    }
                )
                ->editColumn('product', function ($row) use ($is_woocommerce) {
                    $product = $row->is_inactive == 1 ? $row->product . ' <span class="label bg-gray">' . __("lang_v1.inactive") .'</span>' : $row->product;

                    $product = $row->not_for_selling == 1 ? $product . ' <span class="label bg-gray">' . __("lang_v1.not_for_selling") .
                        '</span>' : $product;
                    
                    if ($is_woocommerce && !$row->woocommerce_disable_sync) {
                        $product = $product .'<br><i class="fab fa-wordpress"></i>';
                    }

                    return $product;
                })
                ->editColumn('image', function ($row) {
                    return '<div style="display: flex; width: 50px; height: 50px;"><img src="' . $row->image_url . '" alt="Product image" class="product-thumbnail-small"></div>';
                })
                ->editColumn('type', '@lang("lang_v1." . $type)')
                ->addColumn('mass_delete', function ($row) {
                    return  '<input type="checkbox" class="row-select" value="' . $row->id .'">' ;
                })
                ->editColumn('current_stock', function($row){
                    $qty = (float) $row->current_stock;
                    if ($qty !== 0.0 || $row->current_stock !== null) {
                        return number_format((int) round($qty), 0) . ' ' . $row->unit;
                    }
                    return '--';
                })
                ->addColumn(
                    'purchase_price',
                    '<div style="white-space: nowrap;">@format_currency($min_purchase_price) @if($max_purchase_price != $min_purchase_price && $type == "variable") -  @format_currency($max_purchase_price)@endif </div>'
                )
                ->addColumn(
                    'selling_price',
                    '<div style="white-space: nowrap;">@format_currency($min_price) @if($max_price != $min_price && $type == "variable") -  @format_currency($max_price)@endif </div>'
                )
                ->editColumn('updated_at', function($row) {
                    // Use the derived real_updated_at (newest of products + any
                    // variation update, future values discarded). Falls back to
                    // products.updated_at if real_updated_at is empty/sentinel.
                    $candidate = $row->real_updated_at ?? null;
                    $ts = $candidate ? strtotime($candidate) : 0;
                    if (!$ts || $ts <= strtotime('1971-01-01') || $ts > time()) {
                        // Last resort: products.updated_at if it's not future
                        $alt = strtotime($row->updated_at);
                        if ($alt && $alt <= time()) return date('m/d/Y h:i A', $alt);
                        return '—';
                    }
                    return date('m/d/Y h:i A', $ts);
                })
                ->addColumn('created_at', function ($row) {
                    $ts = strtotime($row->created_at);
                    if (!$ts || $ts > time()) {
                        return '—';
                    }
                    return date('m/d/Y h:i A', $ts);
                })
                ->addColumn('created_by_name', function ($row) {
                    return $row->created_by_name ?? '';
                })
                ->filterColumn('products.sku', function ($query, $keyword) {
                    $query->whereHas('variations', function($q) use($keyword){
                            $q->where('sub_sku', 'like', "%{$keyword}%");
                        })
                    ->orWhere('products.sku', 'like', "%{$keyword}%");
                })
                ->setRowAttr([
                    'data-href' => function ($row) {
                        if (auth()->user()->can("product.view")) {
                            return  action('ProductController@view', [$row->id]) ;
                        } else {
                            return '';
                        }
                    }])
                ->rawColumns(['action' , 'product_url', 'image', 'mass_delete', 'product', 'selling_price', 'purchase_price', 'category', 'subcategory', 'current_stock'])
                ->make(true);
        }

        // Get rack settings safely from session
        $rack_enabled = $this->getSessionValue('business.enable_racks', false) || 
                        $this->getSessionValue('business.enable_row', false) || 
                        $this->getSessionValue('business.enable_position', false);

        $categories = Category::forDropdown($business_id, 'product');

        $brands = Brands::forDropdown($business_id);

        $units = Unit::forDropdown($business_id);

        $tax_dropdown = TaxRate::forBusinessDropdown($business_id, false);
        $taxes = $tax_dropdown['tax_rates'];

        $business_locations = BusinessLocation::forDropdown($business_id);
        $business_locations->prepend(__('lang_v1.none'), 'none');

        // Get users who have created products for the "Created By" filter
        $created_by_ids = Product::where('business_id', $business_id)
            ->whereNotNull('created_by')
            ->distinct()
            ->pluck('created_by')
            ->toArray();
        
        $users_who_created_products = \App\User::where('business_id', $business_id)
            ->whereIn('id', $created_by_ids)
            ->select('id', DB::raw("CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')) as full_name"))
            ->orderBy('first_name')
            ->pluck('full_name', 'id');
        $users_who_created_products->prepend(__('lang_v1.all'), '');

        if ($this->moduleUtil->isModuleInstalled('Manufacturing') && (auth()->user()->can('superadmin') || $this->moduleUtil->hasThePermissionInSubscription($business_id, 'manufacturing_module'))) {
            $show_manufacturing_data = true;
        } else {
            $show_manufacturing_data = false;
        }

        //list product screen filter from module
        $pos_module_data = $this->moduleUtil->getModuleData('get_filters_for_list_product_screen');

        $is_admin = $this->productUtil->is_admin(auth()->user());

        return view('product.index')
            ->with(compact(
                'rack_enabled',
                'categories',
                'brands',
                'units',
                'taxes',
                'business_locations',
                'show_manufacturing_data',
                'pos_module_data',
                'is_woocommerce',
                'is_admin',
                'users_who_created_products'
            ));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $this->getBusinessId();

        //Check if subscribed or not, then check for products quota
        if (!$this->moduleUtil->isSubscribed($business_id)) {
            return $this->moduleUtil->expiredResponse();
        } elseif (!$this->moduleUtil->isQuotaAvailable('products', $business_id)) {
            return $this->moduleUtil->quotaExpiredResponse('products', $business_id, action('ProductController@index'));
        }

        $categories = Category::forDropdown($business_id, 'product');
        $category_combos = Category::flattenedProductCategoryCombos($business_id);

        $brands = Brands::forDropdown($business_id);
        $units = Unit::forDropdown($business_id, true);

        $tax_dropdown = TaxRate::forBusinessDropdown($business_id, true, true);
        $taxes = $tax_dropdown['tax_rates'];
        $tax_attributes = $tax_dropdown['attributes'];

        $barcode_types = $this->barcode_types;
        $barcode_default =  $this->productUtil->barcode_default();

        $default_profit_percent = $this->getSessionValue('business.default_profit_percent', 0);

        //Get all business locations
        $business_locations = BusinessLocation::forDropdown($business_id);

        //Duplicate product
        $duplicate_product = null;
        $rack_details = null;

        $sub_categories = [];
        if (!empty(request()->input('d'))) {
            $duplicate_product = Product::where('business_id', $business_id)->find(request()->input('d'));
            $duplicate_product->name .= ' (copy)';

            if (!empty($duplicate_product->category_id)) {
                $sub_categories = Category::where('business_id', $business_id)
                        ->where('parent_id', $duplicate_product->category_id)
                        ->pluck('name', 'id')
                        ->toArray();
            }

            //Rack details
            if (!empty($duplicate_product->id)) {
                $rack_details = $this->productUtil->getRackDetails($business_id, $duplicate_product->id);
            }
        }

        $selling_price_group_count = SellingPriceGroup::countSellingPriceGroups($business_id);

        $module_form_parts = $this->moduleUtil->getModuleData('product_form_part');
        $product_types = $this->product_types();

        $common_settings = $this->getSessionValue('business.common_settings', []);
        $warranties = Warranty::forDropdown($business_id);

        //product screen view from module
        $pos_module_data = $this->moduleUtil->getModuleData('get_product_screen_top_view');

        return view('product.create')
            ->with(compact(
                'categories',
                'category_combos',
                'brands',
                'units',
                'taxes',
                'barcode_types',
                'default_profit_percent',
                'tax_attributes',
                'barcode_default',
                'business_locations',
                'duplicate_product',
                'sub_categories',
                'rack_details',
                'selling_price_group_count',
                'module_form_parts',
                'product_types',
                'common_settings',
                'warranties',
                'pos_module_data'
            ));
    }

    private function product_types()
    {
        //Product types also includes modifier.
        return ['single' => __('lang_v1.single'),
                'variable' => __('lang_v1.variable'),
                'combo' => __('lang_v1.combo')
            ];
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return Response
     */
    public function store(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        // Validate required fields
        $request->validate([
            'category_id' => 'required|integer|exists:categories,id',
            'sub_category_id' => 'required|integer|exists:categories,id',
            'single_dpp_inc_tax' => 'required|numeric|min:0.01',
        ], [
            'category_id.required' => __('product.category') . ' is required',
            'category_id.exists' => __('product.category') . ' is invalid',
            'sub_category_id.required' => __('product.sub_category') . ' is required',
            'sub_category_id.exists' => __('product.sub_category') . ' is invalid',
            'single_dpp_inc_tax.required' => 'Cost (what you paid) is required — type the amount you paid the supplier',
            'single_dpp_inc_tax.numeric' => 'Cost (what you paid) must be a number',
            'single_dpp_inc_tax.min' => 'Cost (what you paid) must be greater than $0',
        ]);

        try {
            $business_id = $this->getBusinessId();
            $form_fields = ['name', 'brand_id', 'artist', 'unit_id', 'category_id', 'tax', 'type', 'barcode_type', 'sku', 'alert_quantity', 'tax_type', 'tax_exempt', 'weight', 'product_custom_field1', 'product_custom_field2', 'product_custom_field3', 'product_custom_field4', 'product_description', 'sub_unit_ids', 'bin_position', 'listing_location', 'format'];

            $module_form_fields = $this->moduleUtil->getModuleFormField('product_form_fields');
            if (!empty($module_form_fields)) {
                $form_fields = array_merge($form_fields, $module_form_fields);
            }
            
            $product_details = $request->only($form_fields);
            $product_details['business_id'] = $business_id;
            $product_details['created_by'] = auth()->user()->id;

            $product_details['enable_stock'] = (!empty($request->input('enable_stock')) &&  $request->input('enable_stock') == 1) ? 1 : 0;
            $product_details['not_for_selling'] = (!empty($request->input('not_for_selling')) &&  $request->input('not_for_selling') == 1) ? 1 : 0;
            $product_details['tax_exempt'] = (!empty($request->input('tax_exempt')) &&  $request->input('tax_exempt') == 1) ? 1 : 0;

            // sub_category_id is now required, so it will always be set
            $product_details['sub_category_id'] = $request->input('sub_category_id');

            if (!empty($request->input('secondary_unit_id'))) {
                $product_details['secondary_unit_id'] = $request->input('secondary_unit_id') ;
            }

            if (empty($product_details['sku'])) {
                $product_details['sku'] = ' ';
            }

            if (!empty($product_details['alert_quantity'])) {
                $product_details['alert_quantity'] = $this->productUtil->num_uf($product_details['alert_quantity']);
            }

            $expiry_enabled = $request->session()->get('business.enable_product_expiry');
            if (!empty($request->input('expiry_period_type')) && !empty($request->input('expiry_period')) && !empty($expiry_enabled) && ($product_details['enable_stock'] == 1)) {
                $product_details['expiry_period_type'] = $request->input('expiry_period_type');
                $product_details['expiry_period'] = $this->productUtil->num_uf($request->input('expiry_period'));
            }

            if (!empty($request->input('enable_sr_no')) &&  $request->input('enable_sr_no') == 1) {
                $product_details['enable_sr_no'] = 1 ;
            }



            //upload document
            $product_details['image'] = $this->productUtil->uploadFile($request, 'image', config('constants.product_img_path'), 'image');
            $common_settings = $this->getSessionValue('business.common_settings', []);



            $product_details['warranty_id'] = !empty($request->input('warranty_id')) ? $request->input('warranty_id') : null;

            DB::beginTransaction();



            $product = Product::create($product_details);

            if (empty(trim($request->input('sku')))) {
                $sku = $this->productUtil->generateProductSku($product->id);
                $product->sku = $sku;
                $product->save();
            }

            $product->product_custom_field1 = $request->product_custom_field1;
            $product->save();

            //Add product locations
            $product_locations = $request->input('product_locations');
            if (!empty($product_locations)) {
                $product->product_locations()->sync($product_locations);
            }
            
            if ($product->type == 'single') {
                $this->productUtil->createSingleProductVariation($product->id, $product->sku, $request->input('single_dpp'), $request->input('single_dpp_inc_tax'), $request->input('profit_percent'), $request->input('single_dsp'), $request->input('single_dsp_inc_tax'));
            } elseif ($product->type == 'variable') {
                if (!empty($request->input('product_variation'))) {
                    $input_variations = $request->input('product_variation');
                    $this->productUtil->createVariableProductVariations($product->id, $input_variations);
                }
            } elseif ($product->type == 'combo') {

                //Create combo_variations array by combining variation_id and quantity.
                $combo_variations = [];
                if (!empty($request->input('composition_variation_id'))) {
                    $composition_variation_id = $request->input('composition_variation_id');
                    $quantity = $request->input('quantity');
                    $unit = $request->input('unit');

                    foreach ($composition_variation_id as $key => $value) {
                        $combo_variations[] = [
                                'variation_id' => $value,
                                'quantity' => $this->productUtil->num_uf($quantity[$key]),
                                'unit_id' => $unit[$key]
                            ];
                    }
                }

                $this->productUtil->createSingleProductVariation($product->id, $product->sku, $request->input('item_level_purchase_price_total'), $request->input('purchase_price_inc_tax'), $request->input('profit_percent'), $request->input('selling_price'), $request->input('selling_price_inc_tax'), $combo_variations);
            }

            //Add product racks details.
            $product_racks = $request->get('product_racks', null);
            if (!empty($product_racks)) {
                $this->productUtil->addRackDetails($business_id, $product->id, $product_racks);
            }

            //Set Module fields
            if (!empty($request->input('has_module_data'))) {
                $this->moduleUtil->getModuleData('after_product_saved', ['product' => $product, 'request' => $request]);
            }

            Media::uploadMedia($product->business_id, $product, $request, 'product_brochure', true);


            DB::commit();
            $output = ['success' => 1,
                            'msg' => __('product.product_added_success')
                        ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
            $output = ['success' => 0,
                            'msg' => __("messages.something_went_wrong")
                        ];
            return redirect('products')->with('status', $output);
        }

        if ($request->input('submit_type') == 'submit_n_add_opening_stock') {
            return redirect()->action(
                'OpeningStockController@add',
                ['product_id' => $product->id]
            );
        } elseif ($request->input('submit_type') == 'submit_n_add_selling_prices') {
            return redirect()->action(
                'ProductController@addSellingPrices',
                [$product->id]
            );
        } elseif ($request->input('submit_type') == 'save_n_add_another') {
            return redirect()->action(
                'ProductController@create'
            )->with('status', $output);
        }

        return redirect('products')->with('status', $output);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Product  $product
     * @return Response
     */
    public function show($id)
    {
        if (!auth()->user()->can('product.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $this->getBusinessId();
        $details = $this->productUtil->getRackDetails($business_id, $id, true);

        return view('product.show')->with(compact('details'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function edit($id)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $this->getBusinessId();
        $categories = Category::forDropdown($business_id, 'product');
        $category_combos = Category::flattenedProductCategoryCombos($business_id);
        $brands = Brands::forDropdown($business_id);
        
        $tax_dropdown = TaxRate::forBusinessDropdown($business_id, true, true);
        $taxes = $tax_dropdown['tax_rates'];
        $tax_attributes = $tax_dropdown['attributes'];

        $barcode_types = $this->barcode_types;
        
        $product = Product::where('business_id', $business_id)
                            ->with([
                                'product_locations',
                                'product_variations.variations.variation_location_details',
                            ])
                            ->where('id', $id)
                            ->firstOrFail();

        //Sub-category
        $sub_categories = [];
        $sub_categories = Category::where('business_id', $business_id)
                        ->where('parent_id', $product->category_id)
                        ->pluck('name', 'id')
                        ->toArray();
        $sub_categories = [ "" => "None"] + $sub_categories;
        
        $default_profit_percent = request()->session()->get('business.default_profit_percent');

        //Get units.
        $units = Unit::forDropdown($business_id, true);
        $sub_units = $this->productUtil->getSubUnits($business_id, $product->unit_id, true);
        
        //Get all business locations
        $business_locations = BusinessLocation::forDropdown($business_id);
        //Rack details
        $rack_details = $this->productUtil->getRackDetails($business_id, $id);

        $selling_price_group_count = SellingPriceGroup::countSellingPriceGroups($business_id);

        $module_form_parts = $this->moduleUtil->getModuleData('product_form_part');
        $product_types = $this->product_types();
        $common_settings = $this->getSessionValue('business.common_settings', []);
        $warranties = Warranty::forDropdown($business_id);

        //product screen view from module
        $pos_module_data = $this->moduleUtil->getModuleData('get_product_screen_top_view');

        $alert_quantity = !is_null($product->alert_quantity) ? $this->productUtil->num_f($product->alert_quantity, false, null, true) : null;

        return view('product.edit')
                ->with(compact(
                    'categories',
                    'category_combos',
                    'brands',
                    'units',
                    'sub_units',
                    'taxes',
                    'tax_attributes',
                    'barcode_types',
                    'product',
                    'sub_categories',
                    'default_profit_percent',
                    'business_locations',
                    'rack_details',
                    'selling_price_group_count',
                    'module_form_parts',
                    'product_types',
                    'common_settings',
                    'warranties',
                    'pos_module_data',
                    'alert_quantity'
                ));
    }

    /**
     * Set current stock (quantity on hand) for a product. This overwrites qty_available
     * per location/variation instead of adding like opening stock.
     *
     * @param  \Illuminate\Http\Request  $request  expects current_stock[location_id][variation_id] = quantity
     * @param  int  $id  product id
     * @return \Illuminate\Http\JsonResponse
     */
    public function setCurrentStock(Request $request, $id)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');
        $product = Product::where('business_id', $business_id)
            ->where('id', $id)
            ->with(['product_variations.variations', 'product_locations'])
            ->first();

        if (!$product) {
            return response()->json(['success' => false, 'msg' => __('product.product_not_found')], 404);
        }

        if (empty($product->enable_stock)) {
            return response()->json(['success' => false, 'msg' => __('product.manage_stock') . ' is disabled for this product.'], 422);
        }

        $valid_location_ids = $product->product_locations->pluck('id')->toArray();
        $valid_variation_ids = Variation::where('product_id', $product->id)->pluck('id')->toArray();

        $current_stock = $request->input('current_stock', []);
        if (!is_array($current_stock)) {
            return response()->json(['success' => false, 'msg' => 'Invalid request.'], 422);
        }

        try {
            DB::beginTransaction();
            $updated_count = 0;

            foreach ($current_stock as $location_id => $variations) {
                $location_id = (int) $location_id;
                if (!in_array($location_id, $valid_location_ids)) {
                    continue;
                }
                if (!is_array($variations)) {
                    continue;
                }
                foreach ($variations as $variation_id => $qty) {
                    $variation_id = (int) $variation_id;
                    if (!in_array($variation_id, $valid_variation_ids)) {
                        continue;
                    }
                    $quantity = $this->productUtil->num_uf($qty);
                    if ($quantity < 0) {
                        $quantity = 0;
                    }

                    $variation = Variation::find($variation_id);
                    if (!$variation || (int) $variation->product_id !== (int) $product->id) {
                        continue;
                    }

                    VariationLocationDetails::updateOrCreate(
                        [
                            'variation_id'  => $variation_id,
                            'location_id'   => $location_id,
                        ],
                        [
                            'product_id'            => $product->id,
                            'product_variation_id'   => $variation->product_variation_id,
                            'qty_available'         => $quantity,
                        ]
                    );
                    $updated_count++;
                }
            }

            if ($updated_count === 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'msg'     => __('product.set_current_stock_no_values'),
                ], 422);
            }

            DB::commit();

            // Return saved values so frontend can update UI and user can verify response
            $saved_stock = [];
            foreach ($current_stock as $location_id => $variations) {
                $location_id = (int) $location_id;
                if (!in_array($location_id, $valid_location_ids) || !is_array($variations)) {
                    continue;
                }
                foreach ($variations as $variation_id => $qty) {
                    $variation_id = (int) $variation_id;
                    if (in_array($variation_id, $valid_variation_ids)) {
                        $saved_stock[$location_id][$variation_id] = max(0, $this->productUtil->num_uf($qty));
                    }
                }
            }

            return response()->json([
                'success' => true,
                'msg'     => __('product.current_stock_updated'),
                'updated_count' => $updated_count,
                'saved_stock' => $saved_stock,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Set current stock failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'msg'     => __('messages.something_went_wrong'),
            ], 500);
        }
    }

    /**
     * Small standalone page to quickly set current stock from product list.
     *
     * @param \Illuminate\Http\Request $request
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function setCurrentStockQuickPage(Request $request, $id)
    {
        if (
            !auth()->user()->can('product.update') &&
            !auth()->user()->can('product.opening_stock') &&
            !$this->productUtil->is_admin(auth()->user())
        ) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');

        $product = Product::where('business_id', $business_id)
            ->where('id', $id)
            ->with([
                'product_locations',
                'product_variations.variations.variation_location_details',
            ])
            ->firstOrFail();

        if (empty($product->enable_stock)) {
            abort(404);
        }

        return view('product.set_current_stock_quick')
            ->with(compact('product'));
    }


    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return Response
     */
    public function update(Request $request, $id)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }

        // Validate required fields
        $request->validate([
            'category_id' => 'required|integer|exists:categories,id',
            'sub_category_id' => 'required|integer|exists:categories,id',
            'single_dpp_inc_tax' => 'required|numeric|min:0.01',
        ], [
            'category_id.required' => __('product.category') . ' is required',
            'category_id.exists' => __('product.category') . ' is invalid',
            'sub_category_id.required' => __('product.sub_category') . ' is required',
            'sub_category_id.exists' => __('product.sub_category') . ' is invalid',
            'single_dpp_inc_tax.required' => 'Cost (what you paid) is required — type the amount you paid the supplier',
            'single_dpp_inc_tax.numeric' => 'Cost (what you paid) must be a number',
            'single_dpp_inc_tax.min' => 'Cost (what you paid) must be greater than $0',
        ]);

        try {
            $business_id = $request->session()->get('user.business_id');
            $product_details = $request->only(['name', 'brand_id', 'artist', 'unit_id', 'category_id', 'sub_category_id', 'tax', 'barcode_type', 'sku', 'alert_quantity', 'tax_type', 'weight', 'product_custom_field1', 'product_custom_field2', 'product_custom_field3', 'product_custom_field4', 'product_description', 'sub_unit_ids']);

            DB::beginTransaction();
            
            $product = Product::where('business_id', $business_id)
                                ->where('id', $id)
                                ->with(['product_variations'])
                                ->first();

            $module_form_fields = $this->moduleUtil->getModuleFormField('product_form_fields');
            if (!empty($module_form_fields)) {
                foreach ($module_form_fields as $column) {
                    $product->$column = $request->input($column);
                }
            }

            $product->name = $product_details['name'];
            $product->brand_id = $product_details['brand_id'];
            $product->artist = $product_details['artist'];
            $product->unit_id = $product_details['unit_id'];
            $product->category_id = $product_details['category_id'];
            $product->sub_category_id = $request->input('sub_category_id');
            $product->tax = 1;
            $product->barcode_type = $product_details['barcode_type'];
            $product->sku = $product_details['sku'];
            $product->alert_quantity = null;
            $product->tax_type = $product_details['tax_type'];
            $product->tax_exempt = (!empty($request->input('tax_exempt')) && $request->input('tax_exempt') == 1) ? 1 : 0;
            $product->weight = $product_details['weight']??0;
            $product->product_description = $product_details['product_description'];

            if (!empty($request->input('enable_stock')) &&  $request->input('enable_stock') == 1) {
                $product->enable_stock = 1;
            } else {
                $product->enable_stock = 0;
            }


            $product->not_for_selling = (!empty($request->input('not_for_selling')) &&  $request->input('not_for_selling') == 1) ? 1 : 0;

            if (!empty($request->input('sub_category_id'))) {
                $product->sub_category_id = $request->input('sub_category_id');
            } else {
                $product->sub_category_id = null;
            }
            
            $expiry_enabled = $request->session()->get('business.enable_product_expiry');
            if (!empty($expiry_enabled)) {
                if (!empty($request->input('expiry_period_type')) && !empty($request->input('expiry_period')) && ($product->enable_stock == 1)) {
                    $product->expiry_period_type = $request->input('expiry_period_type');
                    $product->expiry_period = $this->productUtil->num_uf($request->input('expiry_period'));
                } else {
                    $product->expiry_period_type = null;
                    $product->expiry_period = null;
                }
            }

            if (!empty($request->input('enable_sr_no')) &&  $request->input('enable_sr_no') == 1) {
                $product->enable_sr_no = 1;
            } else {
                $product->enable_sr_no = 0;
            }

            //upload document
            $file_name = $this->productUtil->uploadFile($request, 'image', config('constants.product_img_path'), 'image');
            if (!empty($file_name)) {

                //If previous image found then remove
                if (!empty($product->image_path) && file_exists($product->image_path)) {
                    unlink($product->image_path);
                }
                
                $product->image = $file_name;
                //If product image is updated update woocommerce media id
                if (!empty($product->woocommerce_media_id)) {
                    $product->woocommerce_media_id = null;
                }
            }

            $product->product_custom_field1 = $request->input('product_custom_field1');
            $product->product_custom_field2 = $request->input('product_custom_field2');
            $product->product_custom_field3 = $request->input('product_custom_field3');
            $product->product_custom_field4 = $request->input('product_custom_field4');
            
            // Save bin_position, listing_location, and format
            $product->bin_position = $request->input('bin_position');
            $product->listing_location = $request->input('listing_location');
            $product->format = $request->input('format');

            $product->save();
            $product->touch();

            //Add product locations
            $product_locations = !empty($request->input('product_locations')) ?
                                $request->input('product_locations') : [];

            $permitted_locations = auth()->user()->permitted_locations();
            //If not assigned location exists don't remove it
            if ($permitted_locations != 'all') {
                $existing_product_locations = $product->product_locations()->pluck('id');

                foreach($existing_product_locations as $pl) {
                    if(!in_array($pl, $permitted_locations)) {
                        $product_locations[] = $pl;
                    }
                }
            }

            $product->product_locations()->sync($product_locations);
            
            if ($product->type == 'single') {
                $single_data = $request->only(['single_variation_id', 'single_dpp', 'single_dpp_inc_tax', 'single_dsp_inc_tax', 'profit_percent', 'single_dsp']);
                $variation = Variation::find($single_data['single_variation_id']);

                $variation->sub_sku = $product->sku;
                $variation->default_purchase_price = $this->productUtil->num_uf($single_data['single_dpp']);
                $variation->dpp_inc_tax = $this->productUtil->num_uf($single_data['single_dpp_inc_tax']);
                $variation->profit_percent = $this->productUtil->num_uf($single_data['profit_percent']);
                $variation->default_sell_price = $this->productUtil->num_uf($single_data['single_dsp']);
                $variation->sell_price_inc_tax = $this->productUtil->num_uf($single_data['single_dsp_inc_tax']);
                $variation->save();



                Media::uploadMedia($product->business_id, $variation, $request, 'variation_images');
            } elseif ($product->type == 'variable') {
                //Update existing variations
                $input_variations_edit = $request->get('product_variation_edit');
                if (!empty($input_variations_edit)) {
                    $this->productUtil->updateVariableProductVariations($product->id, $input_variations_edit);
                }

                //Add new variations created.
                $input_variations = $request->input('product_variation');
                if (!empty($input_variations)) {
                    $this->productUtil->createVariableProductVariations($product->id, $input_variations);
                }
            } elseif ($product->type == 'combo') {

                //Create combo_variations array by combining variation_id and quantity.
                $combo_variations = [];
                if (!empty($request->input('composition_variation_id'))) {
                    $composition_variation_id = $request->input('composition_variation_id');
                    $quantity = $request->input('quantity');
                    $unit = $request->input('unit');

                    foreach ($composition_variation_id as $key => $value) {
                        $combo_variations[] = [
                                'variation_id' => $value,
                                'quantity' => $quantity[$key],
                                'unit_id' => $unit[$key]
                            ];
                    }
                }

                $variation = Variation::find($request->input('combo_variation_id'));
                $variation->sub_sku = $product->sku;
                $variation->default_purchase_price = $this->productUtil->num_uf($request->input('item_level_purchase_price_total'));
                $variation->dpp_inc_tax = $this->productUtil->num_uf($request->input('purchase_price_inc_tax'));
                $variation->profit_percent = $this->productUtil->num_uf($request->input('profit_percent'));
                $variation->default_sell_price = $this->productUtil->num_uf($request->input('selling_price'));
                $variation->sell_price_inc_tax = $this->productUtil->num_uf($request->input('selling_price_inc_tax'));
                $variation->combo_variations = $combo_variations;
                $variation->save();
            }

            //Add product racks details.
            $product_racks = $request->get('product_racks', null);
            if (!empty($product_racks)) {
                $this->productUtil->addRackDetails($business_id, $product->id, $product_racks);
            }

            $product_racks_update = $request->get('product_racks_update', null);
            if (!empty($product_racks_update)) {
                $this->productUtil->updateRackDetails($business_id, $product->id, $product_racks_update);
            }

            //Set Module fields
            if (!empty($request->input('has_module_data'))) {
                $this->moduleUtil->getModuleData('after_product_saved', ['product' => $product, 'request' => $request]);
            }
            
            Media::uploadMedia($product->business_id, $product, $request, 'product_brochure', true);
            
            DB::commit();
            $output = ['success' => 1,
                            'msg' => __('product.product_updated_success')
                        ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
            $output = ['success' => 0,
                            'msg' => $e->getMessage()
                        ];
        }

        if ($request->input('submit_type') == 'update_n_edit_opening_stock') {
            return redirect()->action(
                'OpeningStockController@add',
                ['product_id' => $product->id]
            );
        } elseif ($request->input('submit_type') == 'submit_n_add_selling_prices') {
            return redirect()->action(
                'ProductController@addSellingPrices',
                [$product->id]
            );
        } elseif ($request->input('submit_type') == 'save_n_add_another') {
            return redirect()->action(
                'ProductController@create'
            )->with('status', $output);
        }

        return redirect('products')->with('status', $output);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Product  $product
     * @return Response
     */
    public function destroy($id)
    {
        if (!auth()->user()->can('product.delete')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                $business_id = request()->session()->get('user.business_id');

                $can_be_deleted = true;
                $error_msg = '';

                //Check if any purchase or transfer exists
                $count = PurchaseLine::join(
                    'transactions as T',
                    'purchase_lines.transaction_id',
                    '=',
                    'T.id'
                )
                                    ->whereIn('T.type', ['purchase'])
                                    ->where('T.business_id', $business_id)
                                    ->where('purchase_lines.product_id', $id)
                                    ->count();
                if ($count > 0) {
                    $can_be_deleted = false;
                    $error_msg = __('lang_v1.purchase_already_exist');
                } else {
                    //Check if any opening stock sold
                    $count = PurchaseLine::join(
                        'transactions as T',
                        'purchase_lines.transaction_id',
                        '=',
                        'T.id'
                     )
                                    ->where('T.type', 'opening_stock')
                                    ->where('T.business_id', $business_id)
                                    ->where('purchase_lines.product_id', $id)
                                    ->where('purchase_lines.quantity_sold', '>', 0)
                                    ->count();
                    if ($count > 0) {
                        $can_be_deleted = false;
                        $error_msg = __('lang_v1.opening_stock_sold');
                    } else {
                        //Check if any stock is adjusted
                        $count = PurchaseLine::join(
                            'transactions as T',
                            'purchase_lines.transaction_id',
                            '=',
                            'T.id'
                        )
                                    ->where('T.business_id', $business_id)
                                    ->where('purchase_lines.product_id', $id)
                                    ->where('purchase_lines.quantity_adjusted', '>', 0)
                                    ->count();
                        if ($count > 0) {
                            $can_be_deleted = false;
                            $error_msg = __('lang_v1.stock_adjusted');
                        }
                    }
                }

                $product = Product::where('id', $id)
                                ->where('business_id', $business_id)
                                ->with('variations')
                                ->first();
        
                //Check if product is added as an ingredient of any recipe
                if ($this->moduleUtil->isModuleInstalled('Manufacturing')) {
                    $variation_ids = $product->variations->pluck('id');

                    $exists_as_ingredient = \Modules\Manufacturing\Entities\MfgRecipeIngredient::whereIn('variation_id', $variation_ids)
                        ->exists();
                        if ($exists_as_ingredient) {
                            $can_be_deleted = false;
                            $error_msg = __('manufacturing::lang.added_as_ingredient');
                        }
                }

                if ($can_be_deleted) {
                    if (!empty($product)) {
                        DB::beginTransaction();
                        //Delete variation location details
                        VariationLocationDetails::where('product_id', $id)
                                                ->delete();
                        $product->delete();

                        DB::commit();
                    }

                    $output = ['success' => true,
                                'msg' => __("lang_v1.product_delete_success")
                            ];
                } else {
                    $output = ['success' => false,
                                'msg' => $error_msg
                            ];
                }
            } catch (\Exception $e) {
                DB::rollBack();
                \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
                
                $output = ['success' => false,
                                'msg' => __("messages.something_went_wrong")
                            ];
            }

            return $output;
        }
    }
    
    /**
     * Get subcategories list for a category.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return Response
     */
    public function getSubCategories(Request $request)
    {
        if (!empty($request->input('cat_id'))) {
            $category_id = $request->input('cat_id');
            $business_id = $request->session()->get('user.business_id');
            $sub_categories = Category::where('business_id', $business_id)
                        ->where('parent_id', $category_id)
                        ->select(['name', 'id'])
                        ->get();

            $html = '<option value="">None</option>';
            if (!empty($sub_categories)) {
                foreach ($sub_categories as $sub_category) {
                    $html .= '<option value="' . $sub_category->id .'">' .$sub_category->name . '</option>';
                }
            }
            echo $html;
            exit;
        }
    }

    /**
     * Get product form parts.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return Response
     */
    public function getProductVariationFormPart(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $business = Business::findorfail($business_id);
        $profit_percent = $business->default_profit_percent;

        $action = $request->input('action');
        if ($request->input('action') == "add") {
            if ($request->input('type') == 'single') {
                return view('product.partials.single_product_form_part')
                        ->with(['profit_percent' => $profit_percent]);
            } elseif ($request->input('type') == 'variable') {
                $variation_templates = VariationTemplate::where('business_id', $business_id)->pluck('name', 'id')->toArray();
                $variation_templates = [ "" => __('messages.please_select')] + $variation_templates;

                return view('product.partials.variable_product_form_part')
                        ->with(compact('variation_templates', 'profit_percent', 'action'));
            } elseif ($request->input('type') == 'combo') {
                return view('product.partials.combo_product_form_part')
                ->with(compact('profit_percent', 'action'));
            }
        } elseif ($request->input('action') == "edit" || $request->input('action') == "duplicate") {
            $product_id = $request->input('product_id');
            $action = $request->input('action');
            if ($request->input('type') == 'single') {
                $product_deatails = ProductVariation::where('product_id', $product_id)
                    ->with(['variations', 'variations.media'])
                    ->first();

                return view('product.partials.edit_single_product_form_part')
                            ->with(compact('product_deatails', 'action'));
            } elseif ($request->input('type') == 'variable') {
                $product_variations = ProductVariation::where('product_id', $product_id)
                        ->with(['variations', 'variations.media'])
                        ->get();
                return view('product.partials.variable_product_form_part')
                        ->with(compact('product_variations', 'profit_percent', 'action'));
            } elseif ($request->input('type') == 'combo') {
                $product_deatails = ProductVariation::where('product_id', $product_id)
                    ->with(['variations', 'variations.media'])
                    ->first();
                $combo_variations = $this->productUtil->__getComboProductDetails($product_deatails['variations'][0]->combo_variations, $business_id);

                $variation_id = $product_deatails['variations'][0]->id;
                $profit_percent = $product_deatails['variations'][0]->profit_percent;
                return view('product.partials.combo_product_form_part')
                ->with(compact('combo_variations', 'profit_percent', 'action', 'variation_id'));
            }
        }
    }
    
    /**
     * Get product form parts.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return Response
     */
    public function getVariationValueRow(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $business = Business::findorfail($business_id);
        $profit_percent = $business->default_profit_percent;

        $variation_index = $request->input('variation_row_index');
        $value_index = $request->input('value_index') + 1;

        $row_type = $request->input('row_type', 'add');

        return view('product.partials.variation_value_row')
                ->with(compact('profit_percent', 'variation_index', 'value_index', 'row_type'));
    }

    /**
     * Get product form parts.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return Response
     */
    public function getProductVariationRow(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $business = Business::findorfail($business_id);
        $profit_percent = $business->default_profit_percent;

        $variation_templates = VariationTemplate::where('business_id', $business_id)
                                                ->pluck('name', 'id')->toArray();
        $variation_templates = [ "" => __('messages.please_select')] + $variation_templates;

        $row_index = $request->input('row_index', 0);
        $action = $request->input('action');

        return view('product.partials.product_variation_row')
                    ->with(compact('variation_templates', 'row_index', 'action', 'profit_percent'));
    }

    /**
     * Get product form parts.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return Response
     */
    public function getVariationTemplate(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $business = Business::findorfail($business_id);
        $profit_percent = $business->default_profit_percent;

        $template = VariationTemplate::where('id', $request->input('template_id'))
                                                ->with(['values'])
                                                ->first();
        $row_index = $request->input('row_index');

        return view('product.partials.product_variation_template')
                    ->with(compact('template', 'row_index', 'profit_percent'));
    }

    /**
     * Return the view for combo product row
     *
     * @param  \Illuminate\Http\Request  $request
     * @return Response
     */
    public function getComboProductEntryRow(Request $request)
    {
        if (request()->ajax()) {
            $product_id = $request->input('product_id');
            $variation_id = $request->input('variation_id');
            $business_id = $request->session()->get('user.business_id');

            if (!empty($product_id)) {
                $product = Product::where('id', $product_id)
                        ->with(['unit'])
                        ->first();

                $query = Variation::where('product_id', $product_id)
                        ->with(['product_variation']);

                if ($variation_id !== '0') {
                    $query->where('id', $variation_id);
                }
                $variations =  $query->get();

                $sub_units = $this->productUtil->getSubUnits($business_id, $product['unit']->id);

                return view('product.partials.combo_product_entry_row')
                ->with(compact('product', 'variations', 'sub_units'));
            }
        }
    }

    /**
     * Retrieves products list.
     *
     * @param  string  $q
     * @param  boolean  $check_qty
     *
     * @return JSON
     */
    public function getProducts()
    {
        if (request()->ajax()) {
            $search_term = request()->input('term', '');
            $location_id = request()->input('location_id', null);
            $check_qty = request()->input('check_qty', false);
            $price_group_id = request()->input('price_group', null);
            $business_id = $this->getBusinessId();
            $not_for_selling = request()->get('not_for_selling', null);
            $price_group_id = request()->input('price_group', '');
            $product_types = request()->get('product_types', []);

            $search_fields = request()->get('search_fields', ['name', 'sku']);
            if (in_array('sku', $search_fields)) {
                $search_fields[] = 'sub_sku';
            }
            $search_fields[] = 'artist';

            $result = $this->productUtil->filterProduct($business_id, $search_term, $location_id, $not_for_selling, $price_group_id, $product_types, $search_fields, $check_qty);

            return json_encode($result);
        }
    }

    /**
     * Retrieves products list without variation list
     *
     * @param  string  $q
     * @param  boolean  $check_qty
     *
     * @return JSON
     */
    public function getProductsWithoutVariations()
    {
        if (request()->ajax()) {
            $term = request()->input('term', '');
            //$location_id = request()->input('location_id', '');

            //$check_qty = request()->input('check_qty', false);

            $business_id = $this->getBusinessId();

            $products = Product::join('variations', 'products.id', '=', 'variations.product_id')
                ->where('products.business_id', $business_id)
                ->where('products.type', '!=', 'modifier');
                
            //Include search
            if (!empty($term)) {
                $products->where(function ($query) use ($term) {
                    $query->where('products.name', 'like', '%' . $term .'%');
                    $query->orWhere('sku', 'like', '%' . $term .'%');
                    $query->orWhere('sub_sku', 'like', '%' . $term .'%');
                });
            }

            //Include check for quantity
            // if($check_qty){
            //     $products->where('VLD.qty_available', '>', 0);
            // }
            
            $products = $products->groupBy('products.id')
                ->select(
                    'products.id as product_id',
                    'products.name',
                    'products.type',
                    'products.enable_stock',
                    'products.sku',
                    'products.id as id',
                    DB::raw('CONCAT(products.name, " - ", products.sku) as text')
                )
                    ->orderBy('products.name')
                    ->get();
            return json_encode($products);
        }
    }

    /**
     * Checks if product sku already exists.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return Response
     */
    public function checkProductSku(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $sku = $request->input('sku');
        $product_id = $request->input('product_id');

        //check in products table
        $query = Product::where('business_id', $business_id)
                        ->where('sku', $sku);
        if (!empty($product_id)) {
            $query->where('id', '!=', $product_id);
        }
        $count = $query->count();
        
        //check in variation table if $count = 0
        if ($count == 0) {
            $query2 = Variation::where('sub_sku', $sku)
                            ->join('products', 'variations.product_id', '=', 'products.id')
                            ->where('business_id', $business_id);

            if (!empty($product_id)) {
                $query2->where('product_id', '!=', $product_id);
            }

            if (!empty($request->input('variation_id'))) {
                $query2->where('variations.id', '!=', $request->input('variation_id'));
            }
            $count = $query2->count();
        }
        if ($count == 0) {
            echo "true";
            exit;
        } else {
            echo "false";
            exit;
        }
    }

    /**
     * Validates multiple variation skus 
     *
     */
    public function validateVaritionSkus(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');
        $all_skus = $request->input('skus');

        $skus = [];
        foreach ($all_skus as $key => $value) {
            $skus[] = $value['sku'];
        }

        //check product table is sku present
        $product = Product::where('business_id', $business_id)
                        ->whereIn('sku', $skus)
                        ->first();

        if (!empty($product)) {
            return ['success' => 0, 'sku' => $product->sku];
        }

        foreach ($all_skus as $key => $value) {
            $query = Variation::where('sub_sku', $value['sku'])
                            ->join('products', 'variations.product_id', '=', 'products.id')
                            ->where('business_id', $business_id);

            if (!empty($value['variation_id'])) {
                $query->where('variations.id', '!=', $value['variation_id']);
            }
            $variation = $query->first();

            if (!empty($variation)) {
                return ['success' => 0, 'sku' => $variation->sub_sku];
            }
        }

        return ['success' => 1];
    }

    /**
     * Loads quick add product modal.
     *
     * @return Response
     */
    public function quickAdd()
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $product_name = !empty(request()->input('product_name'))? request()->input('product_name') : '';

        $product_for = !empty(request()->input('product_for'))? request()->input('product_for') : null;

        $business_id = request()->session()->get('user.business_id');
        $categories = Category::forDropdown($business_id, 'product');
        $brands = Brands::forDropdown($business_id);
        $units = Unit::forDropdown($business_id, true);

        $tax_dropdown = TaxRate::forBusinessDropdown($business_id, true, true);
        $taxes = $tax_dropdown['tax_rates'];
        $tax_attributes = $tax_dropdown['attributes'];

        $barcode_types = $this->barcode_types;

        $default_profit_percent = Business::where('id', $business_id)->value('default_profit_percent');

        $locations = BusinessLocation::forDropdown($business_id);

        $enable_expiry = request()->session()->get('business.enable_product_expiry');
        $enable_lot = request()->session()->get('business.enable_lot_number');

        $module_form_parts = $this->moduleUtil->getModuleData('product_form_part');

        //Get all business locations
        $business_locations = BusinessLocation::forDropdown($business_id);

        $common_settings = $this->getSessionValue('business.common_settings', []);
        $warranties = Warranty::forDropdown($business_id);

        return view('product.partials.quick_add_product')
                ->with(compact('categories', 'brands', 'units', 'taxes', 'barcode_types', 'default_profit_percent', 'tax_attributes', 'product_name', 'locations', 'product_for', 'enable_expiry', 'enable_lot', 'module_form_parts', 'business_locations', 'common_settings', 'warranties'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return Response
     */
    public function saveQuickProduct(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }
        
        try {
            $business_id = $request->session()->get('user.business_id');
            $form_fields = ['name', 'brand_id', 'unit_id', 'category_id', 'tax', 'barcode_type','tax_type', 'sku',
                'alert_quantity', 'type', 'sub_unit_ids', 'sub_category_id', 'weight', 'product_custom_field1', 'product_custom_field2', 'product_custom_field3', 'product_custom_field4', 'product_description'];

            $module_form_fields = $this->moduleUtil->getModuleData('product_form_fields');
            if (!empty($module_form_fields)) {
                foreach ($module_form_fields as $key => $value) {
                    if (!empty($value) && is_array($value)) {
                        $form_fields = array_merge($form_fields, $value);
                    }
                }
            }
            $product_details = $request->only($form_fields);
            
            $product_details['type'] = empty($product_details['type']) ? 'single' : $product_details['type'];
            $product_details['business_id'] = $business_id;
            $product_details['created_by'] = $request->session()->get('user.id');
            if (!empty($request->input('enable_stock')) &&  $request->input('enable_stock') == 1) {
                $product_details['enable_stock'] = 1 ;
                //TODO: Save total qty
                //$product_details['total_qty_available'] = 0;
            }
            if (!empty($request->input('not_for_selling')) &&  $request->input('not_for_selling') == 1) {
                $product_details['not_for_selling'] = 1 ;
            }
            if (empty($product_details['sku'])) {
                $product_details['sku'] = ' ';
            }

            if (!empty($product_details['alert_quantity'])) {
                $product_details['alert_quantity'] = $this->productUtil->num_uf($product_details['alert_quantity']);
            }

            $expiry_enabled = $request->session()->get('business.enable_product_expiry');
            if (!empty($request->input('expiry_period_type')) && !empty($request->input('expiry_period')) && !empty($expiry_enabled)) {
                $product_details['expiry_period_type'] = $request->input('expiry_period_type');
                $product_details['expiry_period'] = $this->productUtil->num_uf($request->input('expiry_period'));
            }
            
            if (!empty($request->input('enable_sr_no')) &&  $request->input('enable_sr_no') == 1) {
                $product_details['enable_sr_no'] = 1 ;
            }

            $product_details['warranty_id'] = !empty($request->input('warranty_id')) ? $request->input('warranty_id') : null;
            
            DB::beginTransaction();

            $product = Product::create($product_details);

            if (empty(trim($request->input('sku')))) {
                $sku = $this->productUtil->generateProductSku($product->id);
                $product->sku = $sku;
                $product->save();
            }
            
            $this->productUtil->createSingleProductVariation(
                $product->id,
                $product->sku,
                $request->input('single_dpp'),
                $request->input('single_dpp_inc_tax'),
                $request->input('profit_percent'),
                $request->input('single_dsp'),
                $request->input('single_dsp_inc_tax')
            );

            if ($product->enable_stock == 1 && !empty($request->input('opening_stock'))) {
                $user_id = $request->session()->get('user.id');

                $transaction_date = $request->session()->get("financial_year.start");
                $transaction_date = \Carbon::createFromFormat('Y-m-d', $transaction_date)->toDateTimeString();

                $this->productUtil->addSingleProductOpeningStock($business_id, $product, $request->input('opening_stock'), $transaction_date, $user_id);
            }

            //Add product locations
            $product_locations = $request->input('product_locations');
            if (!empty($product_locations)) {
                $product->product_locations()->sync($product_locations);
            }

            DB::commit();

            $output = ['success' => 1,
                            'msg' => __('product.product_added_success'),
                            'product' => $product,
                            'variation' => $product->variations->first(),
                            'locations' => $product_locations
                        ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
            $output = ['success' => 0,
                            'msg' => __("messages.something_went_wrong")
                        ];
        }

        return $output;
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Product  $product
     * @return Response
     */
    public function view($id)
    {
        if (!auth()->user()->can('product.view')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = request()->session()->get('user.business_id');

            $product = Product::where('business_id', $business_id)
                        ->with(['brand', 'unit', 'category', 'sub_category', 'product_tax', 'variations', 'variations.product_variation', 'variations.group_prices', 'variations.media', 'product_locations', 'warranty', 'media'])
                        ->findOrFail($id);

            $price_groups = SellingPriceGroup::where('business_id', $business_id)->active()->pluck('name', 'id');

            $allowed_group_prices = [];
            foreach ($price_groups as $key => $value) {
                if (auth()->user()->can('selling_price_group.' . $key)) {
                    $allowed_group_prices[$key] = $value;
                }
            }

            $group_price_details = [];

            foreach ($product->variations as $variation) {
                foreach ($variation->group_prices as $group_price) {
                    $group_price_details[$variation->id][$group_price->price_group_id] = $group_price->price_inc_tax;
                }
            }

            $rack_details = $this->productUtil->getRackDetails($business_id, $id, true);

            $combo_variations = [];
            if ($product->type == 'combo') {
                $combo_variations = $this->productUtil->__getComboProductDetails($product['variations'][0]->combo_variations, $business_id);
            }

            return view('product.view-modal')->with(compact(
                'product',
                'rack_details',
                'allowed_group_prices',
                'group_price_details',
                'combo_variations'
            ));
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
        }
    }

    /**
     * Mass deletes products.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return Response
     */
    public function massDestroy(Request $request)
    {
        if (!auth()->user()->can('product.delete')) {
            abort(403, 'Unauthorized action.');
        }
        try {
            $purchase_exist = false;

            if (!empty($request->input('selected_rows'))) {
                $business_id = $request->session()->get('user.business_id');

                $selected_rows = explode(',', $request->input('selected_rows'));

                $products = Product::where('business_id', $business_id)
                                    ->whereIn('id', $selected_rows)
                                    ->with(['purchase_lines', 'variations'])
                                    ->get();
                $deletable_products = [];

                $is_mfg_installed = $this->moduleUtil->isModuleInstalled('Manufacturing');

                DB::beginTransaction();

                foreach ($products as $product) {
                    $can_be_deleted = true;
                    //Check if product is added as an ingredient of any recipe
                    if ($is_mfg_installed) {
                        $variation_ids = $product->variations->pluck('id');

                        $exists_as_ingredient = \Modules\Manufacturing\Entities\MfgRecipeIngredient::whereIn('variation_id', $variation_ids)
                            ->exists();
                        $can_be_deleted = !$exists_as_ingredient;
                    }

                    //Delete if no purchase found
                    if (empty($product->purchase_lines->toArray()) && $can_be_deleted) {
                        //Delete variation location details
                        VariationLocationDetails::where('product_id', $product->id)
                                                    ->delete();
                        $product->delete();
                    } else {
                        $purchase_exist = true;
                    }
                }

                DB::commit();
            }

            if (!$purchase_exist) {
                $output = ['success' => 1,
                            'msg' => __('lang_v1.deleted_success')
                        ];
            } else {
                $output = ['success' => 0,
                            'msg' => __('lang_v1.products_could_not_be_deleted')
                        ];
            }
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
            $output = ['success' => 0,
                            'msg' => __("messages.something_went_wrong")
                        ];
        }

        return redirect()->back()->with(['status' => $output]);
    }

    /**
     * Bulk-send selected products to the Add Purchase form.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function bulkSendToPurchase(Request $request)
    {
        if (!auth()->user()->can('product.view') || !auth()->user()->can('purchase.create')) {
            abort(403, 'Unauthorized action.');
        }

        $selected = $request->input('selected_products_for_purchase', '');
        if (empty($selected)) {
            return redirect()->back()->with([
                'status' => [
                    'success' => 0,
                    'msg' => __('lang_v1.no_row_selected'),
                ],
            ]);
        }

        $ids = array_filter(array_map('intval', explode(',', $selected)));
        if (empty($ids)) {
            return redirect()->back()->with([
                'status' => [
                    'success' => 0,
                    'msg' => __('lang_v1.no_row_selected'),
                ],
            ]);
        }

        // Build a simple comma-separated list for the purchase screen.
        $idString = implode(',', $ids);

        return redirect()->action('PurchaseController@create', ['from_products' => $idString]);
    }

    /**
     * Shows form to add selling price group prices for a product.
     *
     * @param  int  $id
     * @return Response
     */
    public function addSellingPrices($id)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $product = Product::where('business_id', $business_id)
                    ->with(['variations', 'variations.group_prices', 'variations.product_variation'])
                            ->findOrFail($id);

        $price_groups = SellingPriceGroup::where('business_id', $business_id)
                                            ->active()
                                            ->get();
        $variation_prices = [];
        foreach ($product->variations as $variation) {
            foreach ($variation->group_prices as $group_price) {
                $variation_prices[$variation->id][$group_price->price_group_id] = $group_price->price_inc_tax;
            }
        }
        return view('product.add-selling-prices')->with(compact('product', 'price_groups', 'variation_prices'));
    }

    /**
     * Saves selling price group prices for a product.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return Response
     */
    public function saveSellingPrices(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = $request->session()->get('user.business_id');
            $product = Product::where('business_id', $business_id)
                            ->with(['variations'])
                            ->findOrFail($request->input('product_id'));
            DB::beginTransaction();
            foreach ($product->variations as $variation) {
                $variation_group_prices = [];
                foreach ($request->input('group_prices') as $key => $value) {
                    if (isset($value[$variation->id])) {
                        $variation_group_price =
                        VariationGroupPrice::where('variation_id', $variation->id)
                                            ->where('price_group_id', $key)
                                            ->first();
                        if (empty($variation_group_price)) {
                            $variation_group_price = new VariationGroupPrice([
                                    'variation_id' => $variation->id,
                                    'price_group_id' => $key
                                ]);
                        }

                        $variation_group_price->price_inc_tax = $this->productUtil->num_uf($value[$variation->id]);
                        $variation_group_prices[] = $variation_group_price;
                    }
                }

                if (!empty($variation_group_prices)) {
                    $variation->group_prices()->saveMany($variation_group_prices);
                }
            }
            //Update product updated_at timestamp
            $product->touch();
            
            DB::commit();
            $output = ['success' => 1,
                            'msg' => __("lang_v1.updated_success")
                        ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
            $output = ['success' => 0,
                            'msg' => __("messages.something_went_wrong")
                        ];
        }

        if ($request->input('submit_type') == 'submit_n_add_opening_stock') {
            return redirect()->action(
                'OpeningStockController@add',
                ['product_id' => $product->id]
            );
        } elseif ($request->input('submit_type') == 'save_n_add_another') {
            return redirect()->action(
                'ProductController@create'
            )->with('status', $output);
        }

        return redirect('products')->with('status', $output);
    }

    public function viewGroupPrice($id)
    {
        if (!auth()->user()->can('product.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $this->getBusinessId();

        $product = Product::where('business_id', $business_id)
                            ->where('id', $id)
                            ->with(['variations', 'variations.product_variation', 'variations.group_prices'])
                            ->first();

        $price_groups = SellingPriceGroup::where('business_id', $business_id)->active()->pluck('name', 'id');

        $allowed_group_prices = [];
        foreach ($price_groups as $key => $value) {
            if (auth()->user()->can('selling_price_group.' . $key)) {
                $allowed_group_prices[$key] = $value;
            }
        }

        $group_price_details = [];

        foreach ($product->variations as $variation) {
            foreach ($variation->group_prices as $group_price) {
                $group_price_details[$variation->id][$group_price->price_group_id] = $group_price->price_inc_tax;
            }
        }

        return view('product.view-product-group-prices')->with(compact('product', 'allowed_group_prices', 'group_price_details'));
    }

    /**
     * Mass deactivates products.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return Response
     */
    public function massDeactivate(Request $request)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }
        try {
            if (!empty($request->input('selected_products'))) {
                $business_id = $request->session()->get('user.business_id');

                $selected_products = explode(',', $request->input('selected_products'));

                DB::beginTransaction();

                $products = Product::where('business_id', $business_id)
                                    ->whereIn('id', $selected_products)
                                    ->update(['is_inactive' => 1]);

                DB::commit();
            }

            $output = ['success' => 1,
                            'msg' => __('lang_v1.products_deactivated_success')
                        ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
            $output = ['success' => 0,
                            'msg' => __("messages.something_went_wrong")
                        ];
        }

        return $output;
    }

    /**
     * Activates the specified resource from storage.
     *
     * @param  \App\Product  $product
     * @return Response
     */
    public function activate($id)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                $business_id = request()->session()->get('user.business_id');
                $product = Product::where('id', $id)
                                ->where('business_id', $business_id)
                                ->update(['is_inactive' => 0]);

                $output = ['success' => true,
                                'msg' => __("lang_v1.updated_success")
                            ];
            } catch (\Exception $e) {
                \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
                
                $output = ['success' => false,
                                'msg' => __("messages.something_went_wrong")
                            ];
            }

            return $output;
        }
    }

    /**
     * Deletes a media file from storage and database.
     *
     * @param  int  $media_id
     * @return json
     */
    public function deleteMedia($media_id)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }

        if (request()->ajax()) {
            try {
                $business_id = request()->session()->get('user.business_id');
                
                Media::deleteMedia($business_id, $media_id);

                $output = ['success' => true,
                                'msg' => __("lang_v1.file_deleted_successfully")
                            ];
            } catch (\Exception $e) {
                \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
                
                $output = ['success' => false,
                                'msg' => __("messages.something_went_wrong")
                            ];
            }

            return $output;
        }
    }

    public function getProductsApi($id = null)
    {
        try {
            $api_token = request()->header('API-TOKEN');
            $filter_string = request()->header('FILTERS');
            $order_by = request()->header('ORDER-BY');

            parse_str($filter_string, $filters);

            $api_settings = $this->moduleUtil->getApiSettings($api_token);
            
            $limit = !empty(request()->input('limit')) ? request()->input('limit') : 10;

            $location_id = $api_settings->location_id;
            
            $query = Product::where('business_id', $api_settings->business_id)
                            ->active()
                            ->with(['brand', 'unit', 'category', 'sub_category',
                                'product_variations', 'product_variations.variations', 'product_variations.variations.media',
                                'product_variations.variations.variation_location_details' => function ($q) use ($location_id) {
                                    $q->where('location_id', $location_id);
                                }]);

            if (!empty($filters['categories'])) {
                $query->whereIn('category_id', $filters['categories']);
            }

            if (!empty($filters['brands'])) {
                $query->whereIn('brand_id', $filters['brands']);
            }

            if (!empty($filters['category'])) {
                $query->where('category_id', $filters['category']);
            }

            if (!empty($filters['sub_category'])) {
                $query->where('sub_category_id', $filters['sub_category']);
            }

            if ($order_by == 'name') {
                $query->orderBy('name', 'asc');
            } elseif ($order_by == 'date') {
                $query->orderBy('created_at', 'desc');
            }

            if (empty($id)) {
                $products = $query->paginate($limit);
            } else {
                $products = $query->find($id);
            }
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
            return $this->respondWentWrong($e);
        }

        return $this->respond($products);
    }

    public function getVariationsApi()
    {
        try {
            $api_token = request()->header('API-TOKEN');
            $variations_string = request()->header('VARIATIONS');

            if (is_numeric($variations_string)) {
                $variation_ids = intval($variations_string);
            } else {
                parse_str($variations_string, $variation_ids);
            }

            $api_settings = $this->moduleUtil->getApiSettings($api_token);
            $location_id = $api_settings->location_id;
            $business_id = $api_settings->business_id;

            $query = Variation::with([
                                'product_variation',
                                'product' => function ($q) use ($business_id) {
                                    $q->where('business_id', $business_id);
                                },
                                'product.unit',
                                'variation_location_details' => function ($q) use ($location_id) {
                                    $q->where('location_id', $location_id);
                                }
                            ]);

            $variations = is_array($variation_ids) ? $query->whereIn('id', $variation_ids)->get() : $query->where('id', $variation_ids)->first();
        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
            return $this->respondWentWrong($e);
        }

        return $this->respond($variations);
    }

    /**
     * Shows form to edit multiple products at once.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return Response
     */
    public function bulkEdit(Request $request)
    {
        if (!auth()->user()->can('product.update') && !$this->productUtil->is_admin(auth()->user())) {
            abort(403, 'Unauthorized action.');
        }

        $selected_products_string = $request->input('selected_products');
        if (!empty($selected_products_string)) {
            $selected_products = explode(',', $selected_products_string);
            $business_id = $request->session()->get('user.business_id');
           
            $products = Product::where('business_id', $business_id)
                                ->whereIn('id', $selected_products)
                                ->with(['variations', 'variations.product_variation', 'variations.group_prices', 'product_locations'])
                                ->get();

            $all_categories = Category::catAndSubCategories($business_id);

            $categories = [];
            $sub_categories = [];
            foreach ($all_categories as $category) {
                $categories[$category['id']] = $category['name'];

                if (!empty($category['sub_categories'])) {
                    foreach ($category['sub_categories'] as $sub_category) {
                        $sub_categories[$category['id']][$sub_category['id']] = $sub_category['name'];
                    }
                }
            }

            $brands = Brands::forDropdown($business_id);

            $tax_dropdown = TaxRate::forBusinessDropdown($business_id, true, true);
            $taxes = $tax_dropdown['tax_rates'];
            $tax_attributes = $tax_dropdown['attributes'];

            $price_groups = SellingPriceGroup::where('business_id', $business_id)->active()->pluck('name', 'id');
            $business_locations = BusinessLocation::forDropdown($business_id);

            return view('product.bulk-edit')->with(compact(
                'products',
                'categories',
                'brands',
                'taxes',
                'tax_attributes',
                'sub_categories',
                'price_groups',
                'business_locations'
            ));
        }
    }

    /**
     * Updates multiple products at once.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return Response
     */
    public function bulkUpdate(Request $request)
    {
        if (!auth()->user()->can('product.update') && !$this->productUtil->is_admin(auth()->user())) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $products = $request->input('products');
            $business_id = $request->session()->get('user.business_id');

            DB::beginTransaction();
            foreach ($products as $id => $product_data) {
                $update_data = [
                    'category_id' => $product_data['category_id'],
                    'sub_category_id' => $product_data['sub_category_id'],
                    'brand_id' => $product_data['brand_id'],
                    'tax' => $product_data['tax'],
                ];

                //Update product
                $product = Product::where('business_id', $business_id)
                                ->findOrFail($id);

                $product->update($update_data);

                //Add product locations
                $product_locations = !empty($product_data['product_locations']) ?
                                    $product_data['product_locations'] : [];
                $product->product_locations()->sync($product_locations);

                $variations_data = [];

                //Format variations data
                foreach ($product_data['variations'] as $key => $value) {
                    $variation = Variation::where('product_id', $product->id)->findOrFail($key);
                    $variation->default_purchase_price = $this->productUtil->num_uf($value['default_purchase_price']);
                    $variation->dpp_inc_tax = $this->productUtil->num_uf($value['dpp_inc_tax']);
                    $variation->profit_percent = $this->productUtil->num_uf($value['profit_percent']);
                    $variation->default_sell_price = $this->productUtil->num_uf($value['default_sell_price']);
                    $variation->sell_price_inc_tax = $this->productUtil->num_uf($value['sell_price_inc_tax']);
                    $variations_data[] = $variation;

                    //Update price groups
                    if (!empty($value['group_prices'])) {
                        foreach ($value['group_prices'] as $k => $v) {
                            VariationGroupPrice::updateOrCreate(
                                ['price_group_id' => $k, 'variation_id' => $variation->id],
                                ['price_inc_tax' => $this->productUtil->num_uf($v)]
                            );
                        }
                    }
                }
                $product->variations()->saveMany($variations_data);
            }
            DB::commit();

            $output = ['success' => 1,
                            'msg' => __("lang_v1.updated_success")
                        ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
            $output = ['success' => 0,
                            'msg' => __("messages.something_went_wrong")
                        ];
        }

        return redirect('products')->with('status', $output);
    }

    /**
     * Adds product row to edit in bulk edit product form
     *
     * @param  int  $product_id
     * @return Response
     */
    public function getProductToEdit($product_id)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }
        $business_id = request()->session()->get('user.business_id');
       
        $product = Product::where('business_id', $business_id)
                            ->with(['variations', 'variations.product_variation', 'variations.group_prices'])
                            ->findOrFail($product_id);
        
        // If AJAX request, return JSON with variations (for preorder form)
        if (request()->ajax() || request()->wantsJson()) {
            $variations = [];
            foreach ($product->variations as $variation) {
                $variation_name = $variation->name;
                if ($variation->sub_sku) {
                    $variation_name .= ' (' . $variation->sub_sku . ')';
                }
                $variations[$variation->id] = $variation_name;
            }
            
            return response()->json([
                'variations' => $variations
            ]);
        }
        
        // Otherwise, return view for bulk edit
        $all_categories = Category::catAndSubCategories($business_id);

        $categories = [];
        $sub_categories = [];
        foreach ($all_categories as $category) {
            $categories[$category['id']] = $category['name'];

            if (!empty($category['sub_categories'])) {
                foreach ($category['sub_categories'] as $sub_category) {
                    $sub_categories[$category['id']][$sub_category['id']] = $sub_category['name'];
                }
            }
        }

        $brands = Brands::forDropdown($business_id);

        $tax_dropdown = TaxRate::forBusinessDropdown($business_id, true, true);
        $taxes = $tax_dropdown['tax_rates'];
        $tax_attributes = $tax_dropdown['attributes'];

        $price_groups = SellingPriceGroup::where('business_id', $business_id)->active()->pluck('name', 'id');
        $business_locations = BusinessLocation::forDropdown($business_id);

        return view('product.partials.bulk_edit_product_row')->with(compact(
            'product',
            'categories',
            'brands',
            'taxes',
            'tax_attributes',
            'sub_categories',
            'price_groups',
            'business_locations'
        ));
    }

    /**
     * Gets the sub units for the given unit.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $unit_id
     * @return Response
     */
    public function getSubUnits(Request $request)
    {
        if (!empty($request->input('unit_id'))) {
            $unit_id = $request->input('unit_id');
            $business_id = $request->session()->get('user.business_id');
            $sub_units = $this->productUtil->getSubUnits($business_id, $unit_id, true);

            //$html = '<option value="">' . __('lang_v1.all') . '</option>';
            $html = '';
            if (!empty($sub_units)) {
                foreach ($sub_units as $id => $sub_unit) {
                    $html .= '<option value="' . $id .'">' .$sub_unit['name'] . '</option>';
                }
            }

            return $html;
        }
    }

    public function updateProductLocation(Request $request)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $selected_products = $request->input('products');
            $update_type = $request->input('update_type');
            $location_ids = $request->input('product_location');

            $business_id = $request->session()->get('user.business_id');

            $product_ids = explode(',', $selected_products);
           
            $products = Product::where('business_id', $business_id)
                                ->whereIn('id', $product_ids)
                                ->with(['product_locations'])
                                ->get();
            DB::beginTransaction();
            foreach ($products as $product) {
                $product_locations = $product->product_locations->pluck('id')->toArray();

                if ($update_type == 'add') {
                    $product_locations = array_unique(array_merge($location_ids, $product_locations));
                    $product->product_locations()->sync($product_locations);
                } elseif ($update_type == 'remove') {
                    foreach ($product_locations as $key => $value) {
                        if (in_array($value, $location_ids)) {
                            unset($product_locations[$key]);
                        }
                    }
                    $product->product_locations()->sync($product_locations);
                }
            }
            DB::commit();
            $output = ['success' => 1,
                            'msg' => __("lang_v1.updated_success")
                        ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
            $output = ['success' => 0,
                            'msg' => __("messages.something_went_wrong")
                        ];
        }

        return $output;
    }

    public function productStockHistory($id)
    {
        if (!auth()->user()->can('product.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');

        if (request()->ajax()) {

            //for ajax call $id is variation id else it is product id
            $stock_details = $this->productUtil->getVariationStockDetails($business_id, $id, request()->input('location_id'));
            $stock_history = $this->productUtil->getVariationStockHistory($business_id, $id, request()->input('location_id'));

            //if mismach found update stock in variation location details
            if (isset($stock_history[0]) && (float)$stock_details['current_stock'] != (float)$stock_history[0]['stock']) {
                VariationLocationDetails::where('variation_id', 
                                            $id)
                                    ->where('location_id', request()->input('location_id'))
                                    ->update(['qty_available'=>$stock_history[0]['stock']]);
                $stock_details['current_stock'] = $stock_history[0]['stock'];
            }

            return view('product.stock_history_details')
                ->with(compact('stock_details', 'stock_history'));
        }
        
        $product = Product::where('business_id', $business_id)
                            ->with(['variations', 'variations.product_variation'])
                            ->findOrFail($id);
        
        //Get all business locations
        $business_locations = BusinessLocation::forDropdown($business_id);
        

        return view('product.stock_history')
                ->with(compact('product', 'business_locations'));
    }

    /**
     * Toggle WooComerce sync
     *
     * @param  \Illuminate\Http\Request  $request
     * @return Response
     */
    public function toggleWooCommerceSync(Request $request)
    {
        
        try {
            $selected_products = $request->input('woocommerce_products_sync');
            $woocommerce_disable_sync = $request->input('woocommerce_disable_sync');

            $business_id = $request->session()->get('user.business_id');
            $product_ids = explode(',', $selected_products);
            
            DB::beginTransaction();
                if ($this->moduleUtil->isModuleInstalled('Woocommerce')) {   
                    Product::where('business_id', $business_id)
                        ->whereIn('id', $product_ids)
                        ->update(['woocommerce_disable_sync' => $woocommerce_disable_sync]);
                }
            DB::commit();
            $output = [
                'success' => 1,
                'msg' => __("lang_v1.success")
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            
            $output = [
                'success' => 0,
                    'msg' => __("messages.something_went_wrong")
            ];
        }

        return $output;
    }

    /**
     * Function to download all products in xlsx format
     *
     */
    public function downloadExcel()
    {
        $is_admin = $this->productUtil->is_admin(auth()->user());
        if (!$is_admin) {
            abort(403, 'Unauthorized action.');
        }

        $filename = 'products-export-' . \Carbon::now()->format('Y-m-d') . '.xlsx';

        return Excel::download(new ProductsExport, $filename);
    }
    
    public function downloadBarCode(Request $request)
    {
        $ids = explode(',', $request->ids);
        $products = Product::whereIn('id', $ids)
            ->with('product_variations.variations')
            ->get();

        // Create a ZipArchive instance
        $zip = new ZipArchive();

        // Unique name for the zip file
        $zipFileName = 'barcodes_' . time() . '.zip';
        $zipFilePath = storage_path('app/public/' . $zipFileName);

        // Open the ZIP file for writing
        if ($zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {

            // Instance of DNS1D for barcode generation
            $barcodeGenerator = new DNS1D();

            // Path to the logo (make sure you've placed the logo image correctly)
            $logoPath = storage_path('app/public/logo.webp');  // Path to your logo

            // Loop through each product
            foreach ($products as $product) {

                // Loop through each product variation
                foreach ($product->product_variations as $productVariation) {
                    foreach ($productVariation->variations as $variation) {

                        // Create a new instance of Mpdf with the correct size from the provided PDF
                        $mpdf = new Mpdf([
                            'format' => [152.4, 101.6],  // Size in millimeters based on the provided PDF
                            'margin_left' => 5,
                            'margin_right' => 5,
                            'margin_top' => 5,
                            'margin_bottom' => 5,
                        ]);

                        // Start building the HTML content for the PDF
                        $html = '<div style="text-align:center;">';

                        // Add the logo
                        if (file_exists($logoPath)) {
                            $html .= '<img src="' . $logoPath . '" alt="logo" style="width:auto; height:60px; margin-bottom:10px;" /><br>';
                        }

                        // Add product name in larger, bold text to fit the space
                        $html .= '<h3 style="margin:0; font-size:24px; font-weight:bold;">' . htmlspecialchars($product->name) . '</h3>';
                        $html .= '<h3 style="margin:0; font-size:24px; font-weight:bold;">' . htmlspecialchars($product->price) . '</h3>';

                        // Add SKU and variation details in larger, centered text
                        $html .= '<h2 style="margin:5px 0 15px 0; font-size:18px;">Price: ' . htmlspecialchars($variation->sell_price_inc_tax) . '</h2>';
                        $html .= '<p style="margin:5px 0 15px 0; font-size:18px;">SKU: ' . htmlspecialchars($variation->sub_sku) . '</p>';
                        
                        // Add bin position if available
                        if (!empty($product->bin_position)) {
                            $html .= '<p style="margin:5px 0 15px 0; font-size:16px; font-weight:bold;">Bin: ' . htmlspecialchars($product->bin_position) . '</p>';
                        }

                        // Generate the barcode and scale it to fit the page proportionally
                        $barcode = $barcodeGenerator->getBarcodePNG($variation->sub_sku, 'C39', 3, 50);

                        // Add the barcode to the PDF using base64 encoding, stretched to fit better
                        $html .= '<img src="data:image/png;base64,' . $barcode . '" alt="barcode" style="width:auto; height:80px;" />';
                        $html .= '</div>';

                        // Write the HTML to the PDF
                        $mpdf->WriteHTML($html);

                        // Generate unique PDF file name for each variation
                        $pdfFileName = 'barcode_' . Str::slug($product->name . '_' . $variation->sub_sku) . '.pdf';

                        // Save the PDF to a temporary location
                        $pdfFilePath = storage_path('app/public/' . $pdfFileName);
                        $mpdf->Output($pdfFilePath, \Mpdf\Output\Destination::FILE);

                        // Add the PDF to the ZIP file
                        $zip->addFile($pdfFilePath, $pdfFileName);
                    }
                }
            }

            // Close the ZIP file
            $zip->close();

            // Return the ZIP file as a download
            return response()->download($zipFilePath)->deleteFileAfterSend(true);
        }

        // If the ZIP file could not be created, return an error response
        return response()->json(['error' => 'Could not create ZIP file'], 500);
    }

    public function updateStock(Request $request)
    {
        $this->productUtil->updateProductQuantity($request->location_id, $request->product_id, $request->variation_id, $request->stock);
    }

    public function massCreate(Request $request)
    {
        $business_id = $request->session()->get('user.business_id');

        $categories = Category::forDropdown($business_id, 'product');
        $category_combos = Category::flattenedProductCategoryCombos($business_id);
        $brands = Brands::forDropdown($business_id);
        $taxes = TaxRate::forBusinessDropdown($business_id, false)['tax_rates'];
        $business_locations = BusinessLocation::forDropdown($business_id);
        $units = Unit::forDropdown($business_id);

        $manual_item_price_rules = ManualItemPriceRule::where('business_id', $business_id)
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['label', 'keywords', 'price', 'category_id', 'sub_category_id', 'artist']);

        return view('product.mass-create')->with(compact(
            'categories',
            'category_combos',
            'brands',
            'taxes',
            'business_locations',
            'units',
            'manual_item_price_rules'
        ));
    }


    public function massStore(Request $request)
    {
        // Валидация входящих данных
        $validator = Validator::make($request->all(), [
            'products' => 'required|array|min:1',
            'products.*.name' => 'required_without:products.*.id|string|max:255',
            'products.*.category_id' => 'nullable|integer|exists:categories,id',
            'products.*.single_dsp_inc_tax' => 'nullable|numeric|min:0',
            'products.*.business_locations' => 'required|array|min:1',
            'products.*.stock_locations.*.stock' => 'nullable|integer|min:0',
            'products.*.artist' => [
                'required_if:products.*.category_id,11,15,55,104,111,174,175',
            ],
        ], [
            'products.required' => 'At least one product is required',
            'products.array' => 'Products must be an array',
            'products.min' => 'At least one product is required',
            
            'products.*.name.required_without' => 'Product name is required when ID is not provided',
            
            'products.*.category_id.integer' => 'Category ID must be an integer',
            'products.*.category_id.exists' => 'Selected category does not exist',
            
            'products.*.single_dsp_inc_tax.numeric' => 'Selling Price must be a number',
            'products.*.single_dsp_inc_tax.min' => 'Selling Price cannot be negative',
            
            'products.*.business_locations.required' => 'Business locations are required',
            'products.*.business_locations.array' => 'Business locations must be an array',
            'products.*.business_locations.min' => 'At least one business location is required',
            
            'products.*.stock_locations.*.stock.integer' => 'Stock quantity must be an integer',
            'products.*.stock_locations.*.stock.min' => 'Stock quantity cannot be negative',

            'products.*.artist.required_if' => 'Artist is required for this category',
        ]);

        $validator->after(function ($validator) use ($request) {
            $productsInput = $request->input('products', []);

            foreach ($productsInput as $index => $productInput) {
                $isExistingProduct = !empty($productInput['id']);
                $sellingPrice = $productInput['single_dsp_inc_tax'] ?? null;

                if (!$isExistingProduct && ($sellingPrice === null || $sellingPrice === '')) {
                    $validator->errors()->add(
                        "products.$index.single_dsp_inc_tax",
                        'Selling Price is required for new products'
                    );
                }
            }
        });

        if ($validator->fails()) {
            // Return field-specific validation errors in JSON format
            return response()->json([
                'success' => 0,
                'errors' => $validator->errors()->toArray()
            ], 422);
        }


        $products = $request->input('products');

        DB::beginTransaction();

        $businessId = $request->session()->get('user.business_id');
        $userId = $request->session()->get('user.id');
                
        $transactionDate = $request->session()->get("financial_year.start");
        $transactionDate = \Carbon::createFromFormat('Y-m-d', $transactionDate)->toDateTimeString();

        $createdProducts = [];
        try {
            foreach ($products as $productData) {
                // reset product
                $product = null;
                $productId = $productData['id'] ?? '';
                $variationId = $productData['variation_id'] ?? '';

                if (!empty($productData['id'])) {
                    $product = Product::where('id', $productId)->first();
                }
                // Обработка загрузки изображения
                $image = null;
                if (isset($productData['image']) && $request->hasFile("products.{$productData['image']}")) {
                    $image = $this->productUtil->uploadFile(
                        $request,
                        "products.{$productData['image']}",
                        config('constants.product_img_path'),
                        'image'
                    );
                }

                $sku = !empty($productData['sku']) ? $productData['sku'] : null;

                // create product if no product id provided
                if (empty($product)) {
                    // Создание нового продукта с учётом новых полей
                    $product = Product::create([
                        'name'                => $productData['name'],
                        'artist'              => $productData['artist'] ?? null,
                        'sku'                 => (!empty($productData['sku']) ? $productData['sku'] : 111),
                        'brand_id'            => null,
                        'category_id'         => $productData['category_id'] ?? null,
                        'sub_category_id'     => $productData['sub_category_id'] ?? null,
                        'tax'                 => 1,
                        'tax_type'            => 'exclusive',
                        'alert_quantity'      => 1,
                        'business_id'         => $businessId,
                        'created_by'          => auth()->user()->id,
                        'added_via'           => 'mass_add',
                        'product_custom_field1' => $productData['image_url'] ?? null,
                        'image'               => $image,
                        'enable_stock'        => 1,
                        'product_description' => $productData['description'] ?? null,
                        'unit_id' => $productData['unit_id'] ?? 1,
                        'secondary_unit_id' => $productData['secondary_unit_id'] ?? 1,
                        'type' => $productData['type'] ?? 'single',
                    ]);

                    // Генерация SKU, если поле пустое
                    if (empty(trim($productData['sku']))) {
                        $generatedSku = $this->productUtil->generateProductSku($product->id);
                        $product->sku = $generatedSku;
                        $product->save();
                    }

                    // Используйте SKU для создания вариации:
                    $sku = $product->sku;  // Теперь SKU определё

                    // Создание вариации для одиночного продукта
                    $this->productUtil->createSingleProductVariation(
                        $product->id,
                        $sku,
                        $productData['single_dpp_inc_tax'],
                        $productData['single_dpp_inc_tax'],
                        $productData['profit_percent'] ?? 0,
                        $productData['single_dsp_inc_tax'],
                        $productData['single_dsp_inc_tax']
                    );
                }

                $product->product_locations()->sync($productData['business_locations'] ?? []);

                foreach($product->product_locations as $loc) {
                    $openingStockInput = [];

                    $stock = $productData['stock_locations'][$loc->id]['stock'] ?? 0;

                    $transaction = Transaction::where('opening_stock_product_id', $product->id)
                                    ->where('type', 'opening_stock')
                                    ->where('business_id', $businessId)
                                    ->where('location_id', $loc->id)
                                    ->first();

                    if (empty($transaction)) {
                        if (empty($stock)) {
                            continue;
                        }
                        // No opening transaction exist, we create new one 
                        $openingStockInput[$loc->id] = [
                            'purchase_price' => $productData['single_dpp_inc_tax'],
                            'quantity' => $stock,
                            'exp_date' => '',
                            'lot_number' => ''
                        ];

                        $this->productUtil->addSingleProductOpeningStock($businessId, $product, $openingStockInput, $transactionDate, $userId);
                    } else {
                        // There is existing opening stock, update the stock
                        $pcLine = $transaction->purchase_lines()->where('product_id', $productId)->where('variation_id', $variationId)->first();
                        if (!empty($pcLine)) {
                            $difference = $stock - $pcLine->quantity;

                            $pcLine->quantity = $stock;
                            $pcLine->save();

                            $transaction->total_before_tax = $pcLine->purchase_price * $stock;
                            $transaction->final_total = $pcLine->purchase_price * $stock;
                            $transaction->save();

                            $this->productUtil->updateProductQuantity($loc->id, $productId, $variationId, $difference);
                        }
                    }
                }

                $createdProducts[] = $product->id;
            }

            DB::commit();

            $output = [
                'success' => 1,
                'msg' => 'Products were created successfully!',
                'product_ids' => $createdProducts
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error($e->getMessage());
            $output = ['success' => 0, 'msg' => 'Something went wrong during creating the products', 'error' => $e->getMessage()." ".$e->getLine()];
        }

        // Проверяем, если запрос AJAX, возвращаем JSON-ответ без редиректа
    if ($request->ajax()) {
        return response()->json($output);
    }

    // Для обычных запросов выполняем редирект как и было ранее
    if ($request->input('submit_type') == 'submit_n_add_opening_stock') {
        return redirect()->action(
            'OpeningStockController@add',
            ['product_id' => $product->id]
        );
    } elseif ($request->input('submit_type') == 'submit_n_add_selling_prices') {
        return redirect()->action(
            'ProductController@addSellingPrices',
            [$product->id]
        );
    } elseif ($request->input('submit_type') == 'save_n_add_another') {
        return redirect()->action(
            'ProductController@create'
        )->with('status', $output);
    }

    return redirect('products')->with('status', $output);

    }


    public function getMassProductRow(Request $request)
    {
        $index = $request->get('index', 0);

        $business_id = $request->session()->get('user.business_id');

        $categories = Category::forDropdown($business_id, 'product');
        $category_combos = Category::flattenedProductCategoryCombos($business_id);

        $brands = Brands::forDropdown($business_id);

        $taxes = TaxRate::forBusinessDropdown($business_id, false)['tax_rates'];

        $business_locations = BusinessLocation::forDropdown($business_id);

        return view('product.partials.mass_product_row')
            ->with(compact('index', 'categories', 'category_combos', 'brands', 'taxes', 'business_locations'))
            ->render();
    }



    /**
     * Get Product on auto complete
     *
     * @return \Illuminate\Http\Response
     */
    public function massProductGetProducts()
    {
        if (request()->ajax()) {
            $term = request()->term;

            $check_enable_stock = true;
            if (isset(request()->check_enable_stock)) {
                $check_enable_stock = filter_var(request()->check_enable_stock, FILTER_VALIDATE_BOOLEAN);
            }

            $only_variations = false;
            if (isset(request()->only_variations)) {
                $only_variations = filter_var(request()->only_variations, FILTER_VALIDATE_BOOLEAN);
            }

            $term = trim((string) $term);
            if (empty($term) || strlen($term) < 3) {
                return json_encode([]);
            }

            $business_id = request()->session()->get('user.business_id');
            $q = Product::leftJoin(
                'variations',
                'products.id',
                '=',
                'variations.product_id'
            )->leftJoin(
                'categories',
                'products.category_id',
                '=',
                'categories.id'
            )
                ->where(function ($query) use ($term) {
                    // Split search term into words
                    $searchTerms = explode(' ', $term);
                    
                    $query->where(function($q) use ($searchTerms) {
                        foreach($searchTerms as $term) {
                            $q->where('products.name', 'like', '%' . $term . '%');
                        }
                    });
                    $query->orWhere('sku', 'like', '%' . $term .'%');
                    $query->orWhere('sub_sku', 'like', '%' . $term .'%');
                })
                ->active()
                ->where('products.business_id', $business_id)
                ->whereNull('variations.deleted_at')
                ->select(
                    DB::raw('MAX(products.id) as product_id'),
                    DB::raw('MAX(products.name) as name'),
                    DB::raw('MAX(products.type) as type'),
                    DB::raw('MAX(products.category_id) as category_id'),
                    DB::raw('MAX(products.sub_category_id) as sub_category_id'),
                    DB::raw('MAX(products.artist) as artist'),
                    'variations.id as variation_id',
                    'variations.name as variation',
                    'variations.sell_price_inc_tax as price',
                    'variations.dpp_inc_tax',
                    DB::raw('MAX(categories.name) as catname'),
                    'variations.sub_sku as sub_sku'
                )
                ->groupBy('variation_id');

            if ($check_enable_stock) {
                $q->where('enable_stock', 1);
            }
            if (!empty(request()->location_id)) {
                $q->ForLocation(request()->location_id);
            }
            // Keep autocomplete responses fast on large catalogs.
            $products = $q->limit(100)->get();
                
            $products_array = [];
            foreach ($products as $product) {
                $products_array[$product->product_id]['name'] = $product->name;
                $products_array[$product->product_id]['sku'] = $product->sub_sku;
                $products_array[$product->product_id]['type'] = $product->type;
                $products_array[$product->product_id]['price'] = $product->price;
                $products_array[$product->product_id]['catname'] = $product->catname;
                $products_array[$product->product_id]['category_id'] = $product->category_id;
                $products_array[$product->product_id]['sub_category_id'] = $product->sub_category_id;
                $products_array[$product->product_id]['artist'] = $product->artist ?? '';
                $products_array[$product->product_id]['variations'][] = [
                    'variation_id' => $product->variation_id,
                    'variation_name' => $product->variation,
                    'sub_sku' => $product->sub_sku,
                    'price' => $product->price,
                    'dpp_inc_tax' => $product->dpp_inc_tax,
                ];
            }

            $result = [];
            $i = 1;
            $no_of_records = $products->count();

            // Preload opening stock data in one query to avoid N+1 timeouts.
            $openingStockByProductVariation = [];
            $productIds = array_keys($products_array);
            if (!empty($productIds)) {
                $variationIds = [];
                foreach ($products_array as $productData) {
                    foreach ($productData['variations'] as $variationData) {
                        $variationIds[] = $variationData['variation_id'];
                    }
                }
                $variationIds = array_values(array_unique($variationIds));

                if (!empty($variationIds)) {
                    $openingStockRows = DB::table('transactions as t')
                        ->join('purchase_lines as pl', 't.id', '=', 'pl.transaction_id')
                        ->where('t.type', 'opening_stock')
                        ->where('t.business_id', $business_id)
                        ->whereIn('pl.product_id', $productIds)
                        ->whereIn('pl.variation_id', $variationIds)
                        ->select(
                            'pl.product_id',
                            'pl.variation_id',
                            't.location_id',
                            DB::raw('SUM(pl.quantity) as opening_stock_qty')
                        )
                        ->groupBy('pl.product_id', 'pl.variation_id', 't.location_id')
                        ->get();

                    foreach ($openingStockRows as $stockRow) {
                        $openingStockByProductVariation[$stockRow->product_id][$stockRow->variation_id][] = [
                            'id' => (int) $stockRow->location_id,
                            'opening_stock' => (float) $stockRow->opening_stock_qty,
                        ];
                    }
                }
            }
            if (!empty($products_array)) {
                foreach ($products_array as $key => $value) {
                    $product_id = $key;

                    if ($no_of_records > 1 && $value['type'] != 'single' && !$only_variations) {
                        $result[] = [ 'id' => $i,
                            'text' => $value['name'] . ' - ' . $value['sku']. ' - '.$value['price'].' - '. $value['catname'],
                            'variation_id' => 0,
                            'product_id' => $key
                        ];
                    }

                    $name = $value['name'];

                    foreach ($value['variations'] as $variation) {
                        $openingLocations = $openingStockByProductVariation[$product_id][$variation['variation_id']] ?? [];

                        $text = $name;

                        if ($value['type'] == 'variable') {
                            $text = $text . ' (' . $variation['variation_name'] . ')';
                        }

                        $i++;
                        $result[] = [ 'id' => $i,
                            'text' => $text . ' - ' . $variation['sub_sku']. ' - '.($variation['price'] ?? $value['price'] ?? '').' - '. $value['catname'],
                            'product_id' => $key,
                            'variation_id' => $variation['variation_id'],
                            'opening_locations' => $openingLocations,
                            'category_id' => $value['category_id'] ?? null,
                            'sub_category_id' => $value['sub_category_id'] ?? null,
                            'artist' => $value['artist'] ?? '',
                            'sub_sku' => $variation['sub_sku'] ?? '',
                            'sell_price_inc_tax' => $variation['price'] ?? null,
                            'dpp_inc_tax' => $variation['dpp_inc_tax'] ?? null,
                        ];
                    }
                    $i++;
                }
            }
            
            return json_encode($result);
        }
    }


    public function getProductPriceRecommendation(Request $request)
    {
        $query = $request->input('query') ?? '';
        $categoryId = $request->input('category_id') ?? '';
        $gtin = $request->input('gtin') ?? '';
        $rowIndex = $request->input('row_index') ?? '';

        if (empty($query)) {
            return response()->json([
                'error' => true,
                'message' => 'Query is required'
            ], 200);
        }

        $category = Category::find($categoryId);
        if (!empty($categoryId) && empty($category)) {
            return response()->json([
                'error' => true,
                'message' => 'Category not found'
            ], 200);
        }
        
        $ebayCategoryIds = $this->ebayService->getEbayCategoryIds($categoryId);
        $priceRecommendation = $this->ebayService->getPriceRecommendations($query, $gtin, implode(',', $ebayCategoryIds));

        if (!empty($category) && $category->use_discogs_api) {
            $discogsReleases = $this->discogsService->getRelease($query);

            $priceRecommendationDiscogs = $this->discogsService->searchProductPrice($query);
        }

        return response()->json([
            'error' => false,
            'price_recommendation' => $priceRecommendation,
            'discogs_release_nn' => $discogsReleases ?? [],
            'discogs_releases' => $discogsReleases['data'] ?? [],
            'discogs_price_recommendation_sub_categories' => $priceRecommendationDiscogs['sub_categories'] ?? [],
            'discogs_price_recommendation' => $priceRecommendationDiscogs['prices'] ?? [],
            'row_index' => $rowIndex
        ]);
    }

    /**
     * Mass-create helper: fetch a Discogs release by ID and return mapped
     * product fields (name, artist, category_id, sub_category_id, ...) for
     * the bulk-Discogs-IDs entry mode on /products/mass-create.
     *
     * Sarah 2026-05-06 — paste a list of Discogs release IDs, the frontend
     * calls this once per ID, then prepends a row with the returned data.
     */
    public function fetchDiscogsReleaseForMassCreate(Request $request, $releaseId)
    {
        $business_id = $request->session()->get('user.business_id');
        $id = (int) $releaseId;

        if ($id < 1) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Discogs release id.',
            ]);
        }

        // Sarah 2026-05-06: instantiate DiscogsService with the explicit
        // business_id instead of relying on the injected (request-scoped)
        // instance. The injected one resolves at controller construction
        // time, before the session-business binding is reliable for AJAX
        // calls — leading to a spurious "Discogs API token not configured"
        // even when the token IS set in Business Settings.
        $svc = new \App\Services\DiscogsService($business_id);
        $release = $svc->getReleaseById($id);
        if (!empty($release['error'])) {
            return response()->json([
                'success' => false,
                'message' => $release['message'] ?? 'Discogs lookup failed.',
            ]);
        }

        $payload = $release['data'] ?? null;
        if (!$payload || !is_object($payload)) {
            return response()->json([
                'success' => false,
                'message' => 'Discogs returned no data for release ' . $id . '.',
            ]);
        }

        try {
            $mapper = new \App\Services\DiscogsReleaseImportMapper();
            $mapped = $mapper->mapFromApiPayload($business_id, $payload, $id);
        } catch (\Throwable $e) {
            \Log::error('mass-create Discogs map error for ' . $id . ': ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Mapper error: ' . $e->getMessage(),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'name' => $mapped['name'] ?? null,
                'artist' => $mapped['artist'] ?? null,
                'category_id' => $mapped['category_id'] ?? null,
                'sub_category_id' => $mapped['sub_category_id'] ?? null,
                'sku' => $mapped['sku'] ?? null,
                'product_description' => $mapped['product_description'] ?? null,
                'discogs_release_id' => $mapped['discogs_release_id'] ?? $id,
                'warnings' => $mapped['warnings'] ?? [],
            ],
        ]);
    }

    public function getDiscogsPrices(Request $request)
    {
        $releaseId = $request->input('release_id');
        $discogsPrices = $this->discogsService->getPriceSuggesions($releaseId);

        if($discogsPrices['error']) {
            return response()->json([
                'success' => false,
                'message' => $discogsPrices['message']
            ]);
        }

        $prices = [];
        foreach ($discogsPrices['data'] as $key => $price) {
            $prices[] = [
                'condition' => $key,
                'value' => number_format($price->value, 2),
                'currency' => $price->currency,
            ];
        }

        return response()->json([
            'success' => true,
            'prices' => $prices
        ]); 
    }

    public function searchDiscogsProductPrice(Request $request)
    {
        $query = $request->input('query');
        $discogsPrices = $this->discogsService->searchProductPrice($query);


        $discogsReleases = $this->discogsService->getRelease($query);
        dd($discogsPrices ?? [], $discogsReleases, 'testing');
        
    }

    public function searchDiscogsProductPrice2(Request $request)
    {
        $query = $request->input('query');
        $releases = $this->discogsService->getRelease($query);
        dd($releases);
    }

    /**
     * Bulk update categories for multiple products
     */
    public function bulkUpdateCategories(Request $request)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = request()->session()->get('user.business_id');
            $product_ids = $request->input('product_ids', []);
            $category_id = $request->input('category_id');
            $sub_category_id = $request->input('sub_category_id');

            // Validate product_ids
            if (empty($product_ids) || !is_array($product_ids)) {
                return response()->json([
                    'success' => false,
                    'msg' => 'No products selected.'
                ]);
            }
            
            // Filter out any invalid IDs and convert to integers
            $product_ids = array_filter(array_map('intval', $product_ids), function($id) {
                return $id > 0;
            });
            
            if (empty($product_ids)) {
                return response()->json([
                    'success' => false,
                    'msg' => 'No valid product IDs provided.'
                ]);
            }

            // Validate category_id
            if (empty($category_id)) {
                return response()->json([
                    'success' => false,
                    'msg' => 'Please select a category.'
                ]);
            }
            
            // Convert to integer
            $category_id = (int)$category_id;
            
            // Validate sub_category_id if provided
            if (!empty($sub_category_id)) {
                $sub_category_id = (int)$sub_category_id;
            } else {
                $sub_category_id = null;
            }

            // Verify products belong to the business
            $products = Product::where('business_id', $business_id)
                ->whereIn('id', $product_ids)
                ->get();

            if ($products->count() === 0) {
                return response()->json([
                    'success' => false,
                    'msg' => 'No valid products found.'
                ]);
            }

            // Prepare update data
            $updateData = [
                'category_id' => $category_id
            ];
            
            // Only update sub_category_id if provided and not empty
            if (!empty($sub_category_id)) {
                $updateData['sub_category_id'] = $sub_category_id;
            } else {
                $updateData['sub_category_id'] = null;
            }
            
            // Update categories
            $updated = Product::where('business_id', $business_id)
                ->whereIn('id', $product_ids)
                ->update($updateData);

            return response()->json([
                'success' => true,
                'msg' => "Successfully updated {$updated} product(s)."
            ]);

        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            return response()->json([
                'success' => false,
                'msg' => 'An error occurred while updating products.'
            ]);
        }
    }

    /**
     * Show bulk category update page
     */
    public function bulkCategoryUpdatePage(Request $request)
    {
        if (!auth()->user()->can('product.update')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = $request->session()->get('user.business_id');
        $categories = Category::forDropdown($business_id, 'product');
        
        // Get product IDs from query string if provided
        $product_ids = $request->input('product_ids', []);
        if (is_string($product_ids)) {
            $product_ids = explode(',', $product_ids);
        }
        $product_ids = array_filter(array_map('intval', $product_ids), function($id) {
            return $id > 0;
        });

        return view('product.bulk-category-update')->with(compact('categories', 'product_ids'));
    }

    /**
     * Export uncategorized products to CSV
     */
    public function exportUncategorized()
    {
        if (!auth()->user()->can('product.view')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $business_id = request()->session()->get('user.business_id');
            
            $products = Product::where('business_id', $business_id)
                ->whereNull('category_id')
                ->select('id', 'name', 'sku', 'artist', 'format', 'bin_position')
                ->orderBy('name')
                ->get();

            $filename = 'uncategorized_products_' . date('Y-m-d_His') . '.csv';
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ];

            $callback = function() use ($products) {
                $file = fopen('php://output', 'w');
                
                // Headers
                fputcsv($file, ['ID', 'Product Name', 'SKU', 'Artist', 'Format', 'Bin Position', 'Category', 'Subcategory']);
                
                // Data
                foreach ($products as $product) {
                    fputcsv($file, [
                        $product->id,
                        $product->name,
                        $product->sku,
                        $product->artist ?? '',
                        $product->format ?? '',
                        $product->bin_position ?? '',
                        '', // Category - to be filled
                        ''  // Subcategory - to be filled
                    ]);
                }
                
                fclose($file);
            };

            return response()->stream($callback, 200, $headers);

        } catch (\Exception $e) {
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to export uncategorized products.');
        }
    }

    /**
     * Show the import sold items form
     */
    public function importSoldItems()
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        
        // Get statistics about sold items
        $total_sold_items = TransactionSellLine::join('transactions', 'transaction_sell_lines.transaction_id', '=', 'transactions.id')
            ->where('transactions.business_id', $business_id)
            ->where('transactions.status', 'final')
            ->whereNull('transaction_sell_lines.parent_sell_line_id')
            ->count();

        $items_with_products = TransactionSellLine::join('transactions', 'transaction_sell_lines.transaction_id', '=', 'transactions.id')
            ->where('transactions.business_id', $business_id)
            ->where('transactions.status', 'final')
            ->whereNotNull('transaction_sell_lines.product_id')
            ->whereNull('transaction_sell_lines.parent_sell_line_id')
            ->distinct('transaction_sell_lines.product_id')
            ->count('transaction_sell_lines.product_id');

        $unique_products_count = Product::where('business_id', $business_id)->count();

        return view('product.import_sold_items', compact('total_sold_items', 'items_with_products', 'unique_products_count'));
    }

    /**
     * Process import of sold items as products
     */
    public function processImportSoldItems(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $notAllowed = $this->productUtil->notAllowedInDemo();
            if (!empty($notAllowed)) {
                return $notAllowed;
            }

            // Set maximum execution time for large imports
            ini_set('max_execution_time', 0);
            ini_set('memory_limit', '512M');

            $business_id = $request->session()->get('user.business_id');
            $user_id = $request->session()->get('user.id');

            // Get filter options
            $limit = $request->input('limit', 50000); // Default to 50,000
            $min_sales_count = $request->input('min_sales_count', 1); // Only products sold at least X times
            $create_duplicates = $request->input('create_duplicates', false); // Whether to create products even if they exist

            DB::beginTransaction();

            // Get default category/subcategory (use first available if none specified)
            $default_category = Category::where('business_id', $business_id)
                ->where('parent_id', 0)
                ->first();
            
            $default_sub_category = Category::where('business_id', $business_id)
                ->where('parent_id', '!=', 0)
                ->first();

            if (!$default_category || !$default_sub_category) {
                return response()->json([
                    'success' => false,
                    'msg' => 'Please create at least one category and sub-category before importing products.'
                ]);
            }

            // Extract unique products from transaction_sell_lines that have been sold
            // This extracts products that exist in the products table and creates new entries
            // for autocomplete suggestions (useful for ensuring they appear in purchase autocomplete)
            $sold_items_query = TransactionSellLine::join('transactions', 'transaction_sell_lines.transaction_id', '=', 'transactions.id')
                ->join('products', 'transaction_sell_lines.product_id', '=', 'products.id')
                ->where('transactions.business_id', $business_id)
                ->where('transactions.status', 'final')
                ->whereNotNull('transaction_sell_lines.product_id')
                ->whereNull('transaction_sell_lines.parent_sell_line_id')
                ->where('products.business_id', $business_id)
                ->select(
                    'products.id as original_product_id',
                    'products.name',
                    'products.sku',
                    'products.artist',
                    'products.category_id',
                    'products.sub_category_id',
                    'products.unit_id',
                    'products.format',
                    'products.bin_position',
                    DB::raw('COUNT(DISTINCT transaction_sell_lines.transaction_id) as sale_count'),
                    DB::raw('AVG(transaction_sell_lines.unit_price) as avg_price'),
                    DB::raw('MAX(transactions.transaction_date) as last_sale_date')
                )
                ->groupBy('products.id', 'products.name', 'products.sku', 'products.artist', 
                         'products.category_id', 'products.sub_category_id', 'products.unit_id',
                         'products.format', 'products.bin_position')
                ->havingRaw('COUNT(DISTINCT transaction_sell_lines.transaction_id) >= ?', [$min_sales_count])
                ->orderBy('sale_count', 'desc')
                ->limit($limit);

            $sold_items = $sold_items_query->get();

            $stats = [
                'total_found' => $sold_items->count(),
                'created' => 0,
                'skipped' => 0,
                'errors' => 0,
                'errors_list' => []
            ];

            // Default unit if product doesn't have one
            $default_unit = Unit::where('business_id', $business_id)->first();
            if (!$default_unit) {
                return response()->json([
                    'success' => false,
                    'msg' => 'Please create at least one unit before importing products.'
                ]);
            }

            // Process in batches for better performance
            $batch_size = 500;
            $processed = 0;

            foreach ($sold_items->chunk($batch_size) as $batch) {
                foreach ($batch as $item) {
                    try {
                        // Check if product already exists (unless create_duplicates is true)
                        if (!$create_duplicates) {
                            $query = Product::where('business_id', $business_id)
                                ->where('name', $item->name);
                            
                            // Match by SKU if available, otherwise match by artist
                            if (!empty($item->sku) && trim($item->sku) != ' ') {
                                $query->where('sku', $item->sku);
                            } elseif (!empty($item->artist)) {
                                $query->where('artist', $item->artist);
                            }
                            
                            $existing_product = $query->first();

                            if ($existing_product) {
                                $stats['skipped']++;
                                continue;
                            }
                        }

                        // Create new product
                        $product_data = [
                            'business_id' => $business_id,
                            'name' => $item->name,
                            'sku' => !empty($item->sku) ? $item->sku : ' ',
                            'artist' => $item->artist ?? null,
                            'category_id' => $item->category_id ?? $default_category->id,
                            'sub_category_id' => $item->sub_category_id ?? $default_sub_category->id,
                            'unit_id' => $item->unit_id ?? $default_unit->id,
                            'type' => 'single',
                            'enable_stock' => 0, // Disable stock tracking for imported sold items
                            'not_for_selling' => 0,
                            'tax' => 1, // Default tax
                            'tax_type' => 'exclusive',
                            'created_by' => $user_id,
                            'format' => $item->format ?? null,
                            'bin_position' => $item->bin_position ?? null,
                        ];

                        $product = Product::create($product_data);

                        // Generate SKU if empty
                        if (empty(trim($product->sku)) || $product->sku == ' ') {
                            $sku = $this->productUtil->generateProductSku($product->id);
                            $product->sku = $sku;
                            $product->save();
                        }

                        // Create default variation
                        $this->productUtil->createSingleProductVariation(
                            $product->id, 
                            $product->sku, 
                            $item->avg_price ?? 0, // Purchase price
                            $item->avg_price ?? 0, // Purchase price inc tax
                            0, // Profit percent
                            $item->avg_price ?? 0, // Selling price
                            $item->avg_price ?? 0  // Selling price inc tax
                        );

                        $stats['created']++;
                        $processed++;

                    } catch (\Exception $e) {
                        $stats['errors']++;
                        $stats['errors_list'][] = "Error processing '{$item->name}': " . $e->getMessage();
                        \Log::error("Import Sold Items Error: " . $e->getMessage());
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'msg' => "Import completed successfully! Created: {$stats['created']}, Skipped: {$stats['skipped']}, Errors: {$stats['errors']}",
                'stats' => $stats
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::emergency("File:" . $e->getFile(). "Line:" . $e->getLine(). "Message:" . $e->getMessage());

            return response()->json([
                'success' => false,
                'msg' => 'Import failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Process import of sold items from uploaded CSV/Excel file
     */
    public function processImportSoldItemsFromFile(Request $request)
    {
        if (!auth()->user()->can('product.create')) {
            abort(403, 'Unauthorized action.');
        }

        try {
            $notAllowed = $this->productUtil->notAllowedInDemo();
            if (!empty($notAllowed)) {
                return $notAllowed;
            }

            // Validate file
            $request->validate([
                'import_file' => 'required|file|mimes:csv,xlsx,xls|max:51200', // 50MB max
            ]);

            // Set maximum execution time for large imports
            ini_set('max_execution_time', 0);
            ini_set('memory_limit', '512M');

            $business_id = $request->session()->get('user.business_id');
            $user_id = $request->session()->get('user.id');

            $min_sales_count = $request->input('file_min_sales_count', 1);
            $create_duplicates = $request->input('file_create_duplicates', false);

            $file = $request->file('import_file');
            $extension = $file->getClientOriginalExtension();

            DB::beginTransaction();

            // Get defaults
            $default_category = Category::where('business_id', $business_id)
                ->where('parent_id', 0)
                ->first();
            
            $default_sub_category = Category::where('business_id', $business_id)
                ->where('parent_id', '!=', 0)
                ->first();

            if (!$default_category || !$default_sub_category) {
                return response()->json([
                    'success' => false,
                    'msg' => 'Please create at least one category and sub-category before importing products.'
                ]);
            }

            $default_unit = Unit::where('business_id', $business_id)->first();
            if (!$default_unit) {
                return response()->json([
                    'success' => false,
                    'msg' => 'Please create at least one unit before importing products.'
                ]);
            }

            // Parse file
            $data = [];
            if ($extension == 'csv') {
                $data = $this->parseCsvFile($file);
            } else {
                $data = Excel::toArray([], $file);
                $data = $data[0] ?? []; // Get first sheet
            }

            if (empty($data) || count($data) < 2) {
                return response()->json([
                    'success' => false,
                    'msg' => 'File is empty or invalid. Please check the file format.'
                ]);
            }

            // Get header row
            $headers = array_map('strtolower', array_map('trim', $data[0]));
            $name_col = $this->findColumnIndex($headers, ['name', 'product name', 'title']);
            $sku_col = $this->findColumnIndex($headers, ['sku', 'sub_sku', 'product sku']);
            $artist_col = $this->findColumnIndex($headers, ['artist', 'artist name']);
            $category_col = $this->findColumnIndex($headers, ['category', 'cat']);
            $price_col = $this->findColumnIndex($headers, ['price', 'selling price', 'unit price']);

            if ($name_col === false) {
                return response()->json([
                    'success' => false,
                    'msg' => 'Could not find "Name" column in file. Please ensure your file has a "Name" column.'
                ]);
            }

            $stats = [
                'total_found' => count($data) - 1, // Exclude header
                'created' => 0,
                'skipped' => 0,
                'errors' => 0,
                'errors_list' => []
            ];

            // Process rows (skip header)
            $batch_size = 500;
            $processed = 0;

            foreach (array_chunk(array_slice($data, 1), $batch_size) as $batch) {
                foreach ($batch as $row) {
                    try {
                        $name = trim($row[$name_col] ?? '');
                        if (empty($name)) {
                            $stats['skipped']++;
                            continue;
                        }

                        $sku = isset($sku_col) && isset($row[$sku_col]) ? trim($row[$sku_col]) : '';
                        $artist = isset($artist_col) && isset($row[$artist_col]) ? trim($row[$artist_col]) : '';
                        $price = isset($price_col) && isset($row[$price_col]) ? $this->transactionUtil->num_uf($row[$price_col]) : 0;

                        // Check for duplicates
                        if (!$create_duplicates) {
                            $query = Product::where('business_id', $business_id)
                                ->where('name', $name);
                            
                            if (!empty($sku)) {
                                $query->where('sku', $sku);
                            } elseif (!empty($artist)) {
                                $query->where('artist', $artist);
                            }
                            
                            $existing = $query->first();
                            if ($existing) {
                                $stats['skipped']++;
                                continue;
                            }
                        }

                        // Create product
                        $product_data = [
                            'business_id' => $business_id,
                            'name' => $name,
                            'sku' => !empty($sku) ? $sku : ' ',
                            'artist' => $artist ?: null,
                            'category_id' => $default_category->id,
                            'sub_category_id' => $default_sub_category->id,
                            'unit_id' => $default_unit->id,
                            'type' => 'single',
                            'enable_stock' => 0,
                            'not_for_selling' => 0,
                            'tax' => 1,
                            'tax_type' => 'exclusive',
                            'created_by' => $user_id,
                        ];

                        $product = Product::create($product_data);

                        // Generate SKU if empty
                        if (empty(trim($product->sku)) || $product->sku == ' ') {
                            $sku = $this->productUtil->generateProductSku($product->id);
                            $product->sku = $sku;
                            $product->save();
                        }

                        // Create variation with price
                        $this->productUtil->createSingleProductVariation(
                            $product->id,
                            $product->sku,
                            $price,
                            $price,
                            0,
                            $price,
                            $price
                        );

                        $stats['created']++;
                        $processed++;

                    } catch (\Exception $e) {
                        $stats['errors']++;
                        $stats['errors_list'][] = "Row " . ($processed + 1) . ": " . $e->getMessage();
                        \Log::error("Import File Error: " . $e->getMessage());
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'msg' => "Import completed successfully. Created {$stats['created']} products, skipped {$stats['skipped']} duplicates.",
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Import File Error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'msg' => 'Import error: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Parse CSV file
     */
    private function parseCsvFile($file)
    {
        $data = [];
        $handle = fopen($file->getRealPath(), 'r');
        
        if ($handle !== false) {
            while (($row = fgetcsv($handle, 1000, ',')) !== false) {
                $data[] = $row;
            }
            fclose($handle);
        }
        
        return $data;
    }

    /**
     * Find column index by possible names
     */
    private function findColumnIndex($headers, $possible_names)
    {
        foreach ($possible_names as $name) {
            $index = array_search($name, $headers);
            if ($index !== false) {
                return $index;
            }
        }
        return false;
    }

    /**
     * List a single product to eBay
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function listToEbay($id)
    {
        if (!$this->ebayService->isConfigured()) {
            return response()->json([
                'success' => false,
                'msg' => 'eBay API credentials not configured. Please configure in Business Settings > Integrations.'
            ]);
        }

        try {
            $product = Product::findOrFail($id);
            $business_id = request()->session()->get('user.business_id');

            // Get product category for eBay category mapping
            $ebayCategoryIds = $this->ebayService->getEbayCategoryIds($product->category_id);
            
            $productData = [
                'title' => $product->name,
                'description' => $product->product_description ?? '',
                'price' => $product->sell_price_inc_tax ?? $product->sell_price_exc_tax ?? 0,
                'currency' => 'USD', // TODO: Get from business currency
                'category_id' => $ebayCategoryIds[0] ?? '',
                'quantity' => $product->stock_quantity ?? 1,
                'condition' => 'NEW',
                'format' => 'FIXED_PRICE',
                'listing_duration' => 'GTC',
                'location' => $product->listing_location ?? null
            ];

            $result = $this->ebayService->createListing($productData);

            if ($result['success']) {
                // Update product with listing information
                $product->ebay_listing_id = $result['listing_id'] ?? null;
                $product->listing_status = !empty($result['listing_id']) ? 'listed' : 'error';
                $product->save();

                return response()->json([
                    'success' => true,
                    'msg' => 'Product listed to eBay successfully!',
                    'listing_id' => $result['listing_id'] ?? null
                ]);
            } else {
                $product->listing_status = 'error';
                $product->save();

                return response()->json([
                    'success' => false,
                    'msg' => $result['msg'] ?? 'Failed to list product to eBay'
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('eBay Listing Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'msg' => 'Error: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * List a single product to Discogs
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function listToDiscogs($id)
    {
        if (!$this->discogsService->isConfigured()) {
            return response()->json([
                'success' => false,
                'msg' => 'Discogs API token not configured. Please configure in Business Settings > Integrations.'
            ]);
        }

        try {
            $product = Product::findOrFail($id);

            // Try to find release ID from product name/artist
            $release = $this->discogsService->getRelease($product->name, $product->sku, $product->name);
            $releaseId = null;
            if (!empty($release['data']->results[0]->id)) {
                $releaseId = $release['data']->results[0]->id;
            }

            $productData = [
                'release_id' => $releaseId,
                'price' => $product->sell_price_inc_tax ?? $product->sell_price_exc_tax ?? 0,
                'status' => 'For Sale',
                'condition' => 'Mint (M)',
                'sleeve_condition' => 'Mint (M)',
                'comments' => $product->product_description ?? '',
                'allow_offers' => true,
                'external_id' => $product->sku,
                'location' => $product->listing_location ?? null
            ];

            $result = $this->discogsService->createListing($productData);

            if ($result['success']) {
                // Update product with listing information
                $product->discogs_listing_id = $result['listing_id'] ?? null;
                $product->listing_status = !empty($result['listing_id']) ? 'listed' : 'error';
                $product->save();

                return response()->json([
                    'success' => true,
                    'msg' => 'Product listed to Discogs successfully!',
                    'listing_id' => $result['listing_id'] ?? null
                ]);
            } else {
                $product->listing_status = 'error';
                $product->save();

                return response()->json([
                    'success' => false,
                    'msg' => $result['msg'] ?? 'Failed to list product to Discogs'
                ]);
            }
        } catch (\Exception $e) {
            \Log::error('Discogs Listing Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'msg' => 'Error: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Bulk list products to eBay
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function bulkListToEbay(Request $request)
    {
        if (!$this->ebayService->isConfigured()) {
            return response()->json([
                'success' => false,
                'msg' => 'eBay API credentials not configured.'
            ]);
        }

        $productIds = $request->input('product_ids', []);
        if (empty($productIds)) {
            return response()->json([
                'success' => false,
                'msg' => 'No products selected'
            ]);
        }

        $results = [];
        $successCount = 0;
        $errorCount = 0;

        foreach ($productIds as $productId) {
            $result = $this->listToEbay($productId);
            $results[] = [
                'product_id' => $productId,
                'success' => $result->getData()->success ?? false,
                'msg' => $result->getData()->msg ?? ''
            ];
            
            if ($result->getData()->success ?? false) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        return response()->json([
            'success' => true,
            'msg' => "Listed {$successCount} products successfully. {$errorCount} failed.",
            'results' => $results
        ]);
    }

    /**
     * Bulk list products to Discogs
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function bulkListToDiscogs(Request $request)
    {
        if (!$this->discogsService->isConfigured()) {
            return response()->json([
                'success' => false,
                'msg' => 'Discogs API token not configured.'
            ]);
        }

        $productIds = $request->input('product_ids', []);
        if (empty($productIds)) {
            return response()->json([
                'success' => false,
                'msg' => 'No products selected'
            ]);
        }

        $results = [];
        $successCount = 0;
        $errorCount = 0;

        foreach ($productIds as $productId) {
            $result = $this->listToDiscogs($productId);
            $results[] = [
                'product_id' => $productId,
                'success' => $result->getData()->success ?? false,
                'msg' => $result->getData()->msg ?? ''
            ];
            
            if ($result->getData()->success ?? false) {
                $successCount++;
            } else {
                $errorCount++;
            }
        }

        return response()->json([
            'success' => true,
            'msg' => "Listed {$successCount} products successfully. {$errorCount} failed.",
            'results' => $results
        ]);
    }

    /**
     * Autocomplete suggestions for artist/title fields.
     */
    public function autocompleteSuggestions(Request $request, ArtistTitleAutocompleteService $autocompleteService)
    {
        $business_id = request()->session()->get('user.business_id');
        $type = $request->input('type', 'artist');
        $q = $request->input('q', $request->input('term', ''));
        $limit = (int) $request->input('limit', 20);

        $suggestions = $autocompleteService->search($business_id, $type, $q, $limit);

        // jQuery UI autocomplete compatible format
        $response = array_map(function ($value) {
            return ['label' => $value, 'value' => $value];
        }, $suggestions);

        return response()->json($response);
    }

    /**
     * One-time export of distinct artists and titles from live DB.
     */
    public function exportArtistsAndTitles(ArtistTitleAutocompleteService $autocompleteService)
    {
        if (!auth()->user()->can('product.view')) {
            abort(403, 'Unauthorized action.');
        }

        $business_id = request()->session()->get('user.business_id');
        $data = $autocompleteService->exportDistinctValues($business_id);

        $filename = 'artists_titles_export_' . date('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function () use ($data) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM
            fputcsv($out, ['type', 'value']);

            foreach ($data['artists'] as $artist) {
                fputcsv($out, ['artist', $artist]);
            }
            foreach ($data['titles'] as $title) {
                fputcsv($out, ['title', $title]);
            }
            fclose($out);
        };

        return response()->stream($callback, 200, $headers);
    }

}
