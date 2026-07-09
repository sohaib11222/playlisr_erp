@extends('layouts.app')
@section('title', __('sale.products'))

@section('content')

{{-- /products redesign v2 (2026-04-27): scoped styles + body.products-v2
     hook so this only applies on the products list. POS is untouched. --}}
<link rel="stylesheet" href="{{ asset('css/products-list-layout.css?v=' . $asset_v) }}">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<noscript>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap">
</noscript>
<script>document.body.classList.add('products-v2');</script>

<!-- Content Header (Page header) -->
<section class="content-header">
    <div class="ph-head-row">
        <h1>@lang('sale.products')
            <small>@lang('lang_v1.manage_products')</small>
        </h1>
        <div class="ph-head-actions">
            <div class="btn-group">
                <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <i class="fa fa-ellipsis-h"></i> Actions <span class="caret"></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-right">
                    <li><a href="javascript:void(0)" id="pin_products_sidebar_btn" title="Pin this page to your sidebar Favorites"><i class="fa fa-star-o"></i> <span>Pin this page to sidebar</span></a></li>
                    <li class="divider"></li>
                    @if($is_admin)
                        <li><a href="{{action('ProductController@downloadExcel')}}"><i class="fa fa-download"></i> Export all to Excel</a></li>
                        <li><a href="javascript:void(0)" id="sync_discogs_listings_btn" title="Pull your Discogs For Sale inventory so listed items show as 'Listed'"><i class="fa fa-refresh"></i> Sync Discogs listings</a></li>
                        <li class="divider"></li>
                        <li><a href="#" id="bulk_action_bulk_category_update">Bulk update categories</a></li>
                        <li><a href="{{action('ProductController@importSoldItems')}}">Import sold items as products</a></li>
                        <li><a href="{{url('import-products')}}">Import products</a></li>
                    @endif
                    @if(config('constants.enable_product_bulk_edit') && ($is_admin || auth()->user()->can('product.update')))
                        <li><a href="#" id="bulk_action_bulk_edit">Bulk edit</a></li>
                    @endif
                    <li><a href="#" id="bulk_action_download_barcodes">Download barcodes</a></li>
                </ul>
            </div>
            @can('product.create')
                <a class="btn btn-primary" href="{{action('ProductController@create')}}">
                    <i class="fa fa-plus"></i> @lang('messages.add')
                </a>
            @endcan
        </div>
    </div>
</section>

<!-- Main content -->
<section class="content">

<style>
    #product_filters_bar {
        position: sticky;
        top: 55px;
        z-index: 999;
        background: #ffffff;
        padding: 10px 0 5px 0;
        border-bottom: 1px solid #eee;
        margin-bottom: 10px;
    }
    /* products-v2 overrides this — see public/css/products-list-layout.css */
    body:not(.products-v2) #product_filters_bar .product-search-input {
        max-width: 520px;
        width: 100%;
    }
</style>

<div id="product_filters_bar">
    <div class="row" style="margin-bottom: 5px;">
        <div class="col-md-12 col-sm-12">
            <div class="form-group" style="position: relative;">
                <input type="text"
                       id="product_search_main"
                       class="form-control product-search-input"
                       autocomplete="off"
                       placeholder="Search products by artist, title, SKU, or barcode…">
                <ul id="product_search_recent" class="dropdown-menu" style="display:none; width:100%; max-height:280px; overflow-y:auto;"></ul>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
        @component('components.filters', ['title' => __('report.filters')])
            <div class="col-md-2">
                <div class="form-group">
                    {!! Form::label('category_id', __('product.category') . ':') !!}
                    {!! Form::select('category_id', $categories, null, ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'product_list_filter_category_id', 'placeholder' => __('lang_v1.all')]) !!}
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    {!! Form::label('sub_category_id', __('product.sub_category') . ':') !!}
                    <select name="sub_category_id" id="product_list_filter_sub_category_id" class="form-control select2" style="width:100%;">
                        <option value="">{{ __('lang_v1.all') }}</option>
                    </select>
                </div>
            </div>
            <div class="col-md-2" id="location_filter">
                <div class="form-group">
                    <label for="location_id">Store Location:</label>
                    {!! Form::select('location_id', $business_locations, null, ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'location_id', 'placeholder' => __('lang_v1.all')]) !!}
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    {!! Form::label('created_by', __('business.created_by') . ':') !!}
                    {!! Form::select('created_by', $users_who_created_products, null, ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'product_list_filter_created_by', 'placeholder' => __('lang_v1.all')]) !!}
                </div>
            </div>
            <div class="col-md-2">
                <div class="form-group">
                    <label for="product_list_filter_active_state">Status:</label>
                    {!! Form::select('active_state', ['active' => 'Active only', 'inactive' => 'Archived / merged', '' => 'All'], 'active', ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'product_list_filter_active_state']) !!}
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-group">
                    {!! Form::label('created_date_range', __('lang_v1.created_date_range') . ':') !!}
                    <div class="input-group">
                        {!! Form::text('created_date_range', null, ['class' => 'form-control', 'id' => 'product_list_filter_created_date_range', 'placeholder' => __('lang_v1.select_a_date_range'), 'readonly']) !!}
                        {{-- Legacy "All" checkbox kept in DOM (hidden via products-v2 CSS)
                             so existing change handlers still fire. The new preset
                             buttons below toggle this checkbox programmatically. --}}
                        <span class="input-group-addon" id="product_list_filter_all_time_wrap">
                            <label style="margin:0; font-weight:400;">
                                <input type="checkbox" id="product_list_filter_all_time" checked> @lang('lang_v1.all')
                            </label>
                        </span>
                    </div>
                    <div class="date-presets" id="product_list_date_presets" role="group" aria-label="Date range presets">
                        <button type="button" class="date-preset-btn" data-preset="today">Today</button>
                        <button type="button" class="date-preset-btn" data-preset="7">7 days</button>
                        <button type="button" class="date-preset-btn" data-preset="30">30 days</button>
                        <button type="button" class="date-preset-btn" data-preset="ytd">This Year</button>
                        <button type="button" class="date-preset-btn is-active" data-preset="all" title="Slow on large catalogs — searches the entire database">
                            <i class="fa fa-globe" aria-hidden="true"></i> All Time
                        </button>
                    </div>
                    <div class="date-preset-hint">
                        Tip: click <strong>All Time</strong> to search the full catalog (slower).
                    </div>
                </div>
            </div>

            <!-- include module filter (if any custom filters exist) -->
            @if(!empty($pos_module_data))
                @foreach($pos_module_data as $key => $value)
                    @if(!empty($value['view_path']))
                        @includeIf($value['view_path'], ['view_data' => $value['view_data']])
                    @endif
                @endforeach
            @endif
        @endcomponent
        </div>
    </div>
</div>
@can('product.view')
    <div class="row">
        <div class="col-md-12">
           <!-- Custom Tabs -->
            <div class="nav-tabs-custom">
                <ul class="nav nav-tabs">

                    <li class="active">
                        <a href="#product_list_tab" data-toggle="tab" aria-expanded="true"><i class="fa fa-cubes" aria-hidden="true"></i> @lang('lang_v1.all_products')</a>
                    </li>
                    @can('stock_report.view')
                    <li>
                        <a href="#product_stock_report" data-toggle="tab" aria-expanded="true"><i class="fa fa-hourglass-half" aria-hidden="true"></i> @lang('report.stock_report')</a>
                    </li>
                    @endcan
                </ul>

                <div class="tab-content">

                    <div class="tab-pane active" id="product_list_tab">

                        @if($is_admin && empty($ebay_listing_ready))
                        <div id="ebay_listing_status_banner" class="alert @if(!empty($ebay_configured)) alert-warning @else alert-danger @endif" style="margin-bottom:12px;">
                            @if(!empty($ebay_configured))
                                <i class="fa fa-exclamation-triangle"></i>
                                <strong>eBay credentials saved but seller not connected.</strong>
                                <a href="{{ url('/admin/ebay-seller') }}">Connect your eBay seller account</a> before listing.
                            @else
                                <i class="fa fa-times-circle"></i>
                                <strong>eBay not configured.</strong>
                                Add App ID and Cert ID in
                                <a href="{{ action('BusinessController@getBusinessSettings') }}#integrations">Business Settings → Integrations</a>.
                            @endif
                        </div>
                        @endif

                        <button class="btn btn-success pull-right margin-left-10 downloadbarcodes" style="display:none;">Download Barcodes</button>
                        @if(config('constants.enable_product_bulk_edit') && ($is_admin || auth()->user()->can('product.update')))
                            <button type="button" class="btn btn-primary pull-right margin-left-10" id="edit-selected-top" style="display:none;">
                                <i class="fa fa-edit"></i> {{ __('lang_v1.bulk_edit') }}
                            </button>
                        @endif
                        @if($is_admin)
                            <a class="btn btn-success pull-right margin-left-10" href="{{url('import-products')}}" id="import_products_top" style="display:none;"><i class="fa fa-download"></i>Import Products</a>
                            <a class="btn btn-primary pull-right margin-left-10" href="{{action('ProductController@importSoldItems')}}" id="import_sold_items_top" style="display:none;"><i class="fa fa-upload"></i> Import Sold Items as Products</a>
                            <a class="btn btn-success pull-right margin-left-10" href="{{action('ProductController@downloadExcel')}}" id="download_excel_top" style="display:none;"><i class="fa fa-download"></i> @lang('lang_v1.download_excel')</a>
                            <a href="{{ action('ProductController@bulkCategoryUpdatePage') }}" class="btn btn-info pull-right margin-left-10" id="bulk_category_update_btn" style="display:none;">
                                <i class="fa fa-tags"></i> Bulk Update Categories
                            </a>
                            <button type="button" class="btn btn-warning pull-right margin-left-10" id="export_uncategorized_btn" style="display: none;">
                                <i class="fa fa-download"></i> Export Uncategorized
                            </button>
                            @php
                                $discogsService = app(\App\Services\DiscogsService::class);
                            @endphp
                            @if(!empty($ebay_listing_ready))
                                <button type="button" class="btn btn-primary pull-right margin-left-10" id="bulk_list_ebay_btn">
                                    <i class="fa fa-shopping-cart"></i> List Selected to eBay
                                </button>
                            @endif
                            @if($discogsService->isConfigured())
                                <button type="button" class="btn btn-primary pull-right margin-left-10" id="bulk_list_discogs_btn">
                                    <i class="fa fa-music"></i> List Selected to Discogs
                                </button>
                            @endif
                        @endif
                        @include('product.partials.product_list')
                    </div>
                    @can('stock_report.view')
                    <div class="tab-pane" id="product_stock_report">
                        @include('report.partials.stock_report_table')
                    </div>
                    @endcan
                </div>
            </div>
        </div>
    </div>
@endcan
<input type="hidden" id="is_rack_enabled" value="{{$rack_enabled}}">

<div class="modal fade product_modal" tabindex="-1" role="dialog" 
    aria-labelledby="gridSystemModalLabel">
</div>

<div class="modal fade" id="view_product_modal" tabindex="-1" role="dialog" 
    aria-labelledby="gridSystemModalLabel">
</div>

<div class="modal fade" id="opening_stock_modal" tabindex="-1" role="dialog" 
    aria-labelledby="gridSystemModalLabel">
</div>

@if($is_woocommerce)
    @include('product.partials.toggle_woocommerce_sync_modal')
@endif
@include('product.partials.edit_product_location_modal')

<!-- Bulk Category Update Modal -->
<div class="modal fade" id="bulk_category_update_modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title">Bulk Update Categories</h4>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Select Category:</label>
                    {!! Form::select('bulk_category_id', $categories, null, ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'bulk_category_id', 'placeholder' => 'Select Category']) !!}
                </div>
                <div class="form-group">
                    <label>Select Subcategory (Optional):</label>
                    <select class="form-control select2" style="width:100%" id="bulk_subcategory_id">
                        <option value="">Select Subcategory</option>
                    </select>
                </div>
                <div class="alert alert-info" id="bulk_update_info">
                    <strong>Note:</strong> <span id="bulk_update_note">Select products using checkboxes, or update all visible products.</span>
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" id="update_all_visible" checked> 
                        <strong>Update all visible products</strong>
                    </label>
                    <br>
                    <small class="text-muted">Uncheck to update only selected products (use checkboxes in table)</small>
                </div>
                <div class="alert alert-warning" id="selected_count_alert" style="display: none;">
                    <strong><span id="selected_products_count">0</span> product(s) selected</strong>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirm_bulk_category_update">Update Categories</button>
            </div>
        </div>
    </div>
</div>

<!-- Set / change a product's Discogs release id (inline from the list) -->
<div class="modal fade" id="discogs_id_modal" tabindex="-1" role="dialog" aria-labelledby="discogs_id_modal_label">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="discogs_id_modal_label"><i class="fa fa-music"></i> Discogs Release ID</h4>
            </div>
            <div class="modal-body">
                <input type="hidden" id="discogs_id_product_id">
                <div class="form-group">
                    <label for="discogs_id_input">Release ID</label>
                    <input type="number" min="1" step="1" class="form-control" id="discogs_id_input" placeholder="e.g. 249504">
                    <p class="help-block">
                        Saving downloads the release's cover art and fills any <strong>blank</strong> category / description.
                        It never overwrites values you've already set. Leave empty to clear the link.
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="discogs_id_save_btn">Save &amp; import</button>
            </div>
        </div>
    </div>
</div>

</section>
<!-- /.content -->

@endsection

@section('javascript')
    <script src="{{ asset('js/product.js?v=' . $asset_v) }}"></script>
    <script src="{{ asset('js/opening_stock.js?v=' . $asset_v) }}"></script>
    <script type="text/javascript">
        // Test if jQuery and script is loading
        console.log('Product index script loading...');
        console.log('jQuery version:', typeof jQuery !== 'undefined' ? jQuery.fn.jquery : 'NOT LOADED');
        
        // Define helper functions globally BEFORE document.ready
        // This ensures they're available when event handlers are attached
        
        // Function to get selected product IDs (using checkbox values)
        window.getSelectedProductIds = function() {
            const selectedIds = [];
            // Use the same approach as getSelectedRows() - get from checkbox values
            // Make sure we're only getting checkboxes from the product table
            $('#product_table tbody input.row-select:checked').each(function() {
                const productId = $(this).val();
                if (productId && productId !== '') {
                    // Convert to integer to ensure proper type
                    const id = parseInt(productId);
                    if (!isNaN(id) && id > 0) {
                        selectedIds.push(id);
                    }
                }
            });
            return selectedIds;
        };
        
        // Function to get all visible product IDs (from all checkboxes in visible rows)
        window.getAllVisibleProductIds = function() {
            const productIds = [];
            // Get all checkboxes in visible/filtered rows
            // Use DataTable API to iterate through visible rows
            if (typeof product_table !== 'undefined' && product_table) {
                product_table.rows({ search: 'applied' }).every(function() {
                    const row = this.node();
                    const checkbox = $(row).find('input.row-select');
                    if (checkbox.length > 0) {
                        const productId = checkbox.val();
                        if (productId && productId !== '') {
                            // Convert to integer to ensure proper type
                            const id = parseInt(productId);
                            if (!isNaN(id) && id > 0) {
                                productIds.push(id);
                            }
                        }
                    }
                });
            } else {
                // Fallback: get all checkboxes if DataTable not available
                $('#product_table tbody input.row-select').each(function() {
                    const productId = $(this).val();
                    if (productId && productId !== '') {
                        const id = parseInt(productId);
                        if (!isNaN(id) && id > 0) {
                            productIds.push(id);
                        }
                    }
                });
            }
            return productIds;
        };
        
        $(document).ready( function(){
            console.log('Document ready - setting up handlers');

            // Re-init the Store Location select2 with allowClear so users can
            // return to "All" after picking a specific store. The global
            // __select2() init in app.js does not pass allowClear.
            if ($('#location_id').length) {
                if ($('#location_id').data('select2')) {
                    $('#location_id').select2('destroy');
                }
                $('#location_id').select2({
                    placeholder: @json(__('lang_v1.all')),
                    allowClear: true
                });
            }

            // Check if table exists before initializing
            if ($('#product_table').length === 0) {
                console.error('Product table not found!');
                return;
            }
            
            // Check if table has thead
            if ($('#product_table thead').length === 0) {
                console.error('Product table thead not found!');
                return;
            }
            
            var createdAtColIndex = $('#product_table thead th').filter(function() {
                return $(this).text().trim() === 'Created at';
            }).index();

            product_table = $('#product_table').DataTable({
                processing: true,
                serverSide: true,
                aaSorting: [[createdAtColIndex >= 0 ? createdAtColIndex : 12, 'desc']],
                scrollY:        "75vh",
                scrollX:        true,
                scrollCollapse: true,
                "ajax": {
                    "url": "/products",
                    "data": function ( d ) {
                        d.category_id = $('#product_list_filter_category_id').val();
                        d.sub_category_id = $('#product_list_filter_sub_category_id').val();
                        d.location_id = $('#location_id').val();
                        d.created_by = $('#product_list_filter_created_by').val();
                        d.active_state = $('#product_list_filter_active_state').val();

                        // Handle date range filter (skipped when All time checked)
                        var all_time = $('#product_list_filter_all_time').is(':checked');
                        var $dateRangeInput = $('#product_list_filter_created_date_range');
                        var date_range = $dateRangeInput.val();
                        if (!all_time && date_range) {
                            // Prefer daterangepicker state (most reliable for custom ranges)
                            var drp = $dateRangeInput.data('daterangepicker');
                            if (drp && drp.startDate && drp.endDate) {
                                d.start_date = drp.startDate.format('YYYY-MM-DD');
                                d.end_date = drp.endDate.format('YYYY-MM-DD');
                            } else {
                                // Fallback: support both separators used in UI/history
                                var dates = date_range.indexOf(' ~ ') > -1
                                    ? date_range.split(' ~ ')
                                    : date_range.split(' - ');
                                if (dates.length == 2) {
                                    var start_moment = moment(dates[0].trim(), moment_date_format);
                                    var end_moment = moment(dates[1].trim(), moment_date_format);
                                    if (start_moment.isValid() && end_moment.isValid()) {
                                        d.start_date = start_moment.format('YYYY-MM-DD');
                                        d.end_date = end_moment.format('YYYY-MM-DD');
                                    }
                                }
                            }
                        }
                        
                        if ($('#repair_model_id').length == 1) {
                            d.repair_model_id = $('#repair_model_id').val();
                        }

                        if ($('#woocommerce_enabled').length == 1 && $('#woocommerce_enabled').is(':checked')) {
                            d.woocommerce_enabled = 1;
                        }

                        d = __datatable_ajax_callback(d);
                    }
                },
                columnDefs: [ {
                    "targets": [0, 1],
                    "orderable": false,
                    "searchable": false
                } ],
                columns: [
                        { data: 'mass_delete'  },
                        { data: 'action', name: 'action'},
                        { data: 'product_locations', name: 'product_locations'  },
                        { data: 'artist', name: 'products.artist'},
                        { data: 'product', name: 'products.name'  },
                        { data: 'category', name: 'c1.name'},
                        { data: 'subcategory', name: 'c2.name'},
                        @can('view_purchase_price')
                            { data: 'purchase_price', name: 'min_purchase_price', searchable: false},
                        @endcan
                        @can('access_default_selling_price')
                            { data: 'selling_price', name: 'max_price', searchable: false},
                        @endcan
                        { data: 'current_stock', searchable: false},
                        { data: 'total_sold', searchable: false},
                        { data: 'sku', name: 'products.sku'},
                        { data: 'created_at', name: 'products.created_at'},
                        { data: 'created_by_name', name: 'u.first_name' },
                        // real_updated_at is a SELECT alias (GREATEST(...)), not a real
                        // column — MySQL rejects aliases in WHERE, so leaving this
                        // searchable made global search 500 and return zero rows.
                        { data: 'updated_at', name: 'real_updated_at', orderable: true, searchable: false},
                        { data: 'updated_by_name', name: 'updated_by_name', orderable: false, searchable: false},
                        { data: 'discogs_id', name: 'discogs_id', orderable: false, searchable: false },
                        { data: 'list_discogs', name: 'list_discogs', orderable: false, searchable: false },
                        { data: 'list_ebay', name: 'list_ebay', orderable: false, searchable: false },
                        { data: 'nivessa_url', name: 'nivessa_url', orderable: false, searchable: false }
                    ],
                    createdRow: function( row, data, dataIndex ) {
                        if($('input#is_rack_enabled').val() == 1){
                            var target_col = 0;
                            @can('product.delete')
                                target_col = 1;
                            @endcan
                            $( row ).find('td:eq('+target_col+') div').prepend('<i style="margin:auto;" class="fa fa-plus-circle text-success cursor-pointer no-print rack-details" title="' + LANG.details + '"></i>&nbsp;&nbsp;');
                        }
                        $( row ).find('td:eq(0)').attr('class', 'selectable_td');
                    },
                    fnDrawCallback: function(oSettings) {
                        __currency_convert_recursively($('#product_table'));
                    },
            });
            // Array to track the ids of the details displayed rows
            var detailRows = [];


            // Pin this page to the sidebar Favorites. Reuses the same toggle the
            // sidebar's hover-star uses, with the exact same URL the "List
            // Products" menu link registers, so the star state stays in sync.
            var PRODUCTS_FAV_URL = @json(action('ProductController@index'));
            function refreshPinLabel() {
                var pinned = window.NivessaSidebarFav && window.NivessaSidebarFav.isPinned(PRODUCTS_FAV_URL);
                $('#pin_products_sidebar_btn i').attr('class', pinned ? 'fa fa-star' : 'fa fa-star-o');
                $('#pin_products_sidebar_btn span').text(pinned ? 'Unpin from sidebar' : 'Pin this page to sidebar');
            }
            refreshPinLabel();
            $(document).on('click', '#pin_products_sidebar_btn', function(e) {
                e.preventDefault();
                if (!window.NivessaSidebarFav) { return; }
                window.NivessaSidebarFav.toggle(PRODUCTS_FAV_URL, 'Products');
                setTimeout(refreshPinLabel, 400);
                if (window.toastr) { toastr.success('Updated your sidebar Favorites.'); }
            });

            // Bulk Actions dropdown triggers
            $(document).on('click', '#bulk_action_bulk_category_update', function(e) {
                e.preventDefault();
                $('#bulk_category_update_btn').trigger('click');
            });

            $(document).on('click', '#bulk_action_bulk_edit', function(e) {
                e.preventDefault();
                $('#edit-selected-top').trigger('click');
            });

            $(document).on('click', '#bulk_action_download_barcodes', function(e) {
                e.preventDefault();
                $('.downloadbarcodes').trigger('click');
            });

            $('#product_table tbody').on( 'click', 'tr i.rack-details', function () {
                var i = $(this);
                var tr = $(this).closest('tr');
                var row = product_table.row( tr );
                var idx = $.inArray( tr.attr('id'), detailRows );

                if ( row.child.isShown() ) {
                    i.addClass( 'fa-plus-circle text-success' );
                    i.removeClass( 'fa-minus-circle text-danger' );

                    row.child.hide();
         
                    // Remove from the 'open' array
                    detailRows.splice( idx, 1 );
                } else {
                    i.removeClass( 'fa-plus-circle text-success' );
                    i.addClass( 'fa-minus-circle text-danger' );

                    row.child( get_product_details( row.data() ) ).show();
         
                    // Add to the 'open' array
                    if ( idx === -1 ) {
                        detailRows.push( tr.attr('id') );
                    }
                }
            });

            // Hook up main search bar to DataTables — debounced + min-length
            // so each keystroke doesn't fire its own server-side query (the old
            // behavior made the bar feel sluggish on large catalogs because
            // requests piled up faster than they could complete).
            if ($('#product_search_main').length) {
                var __search_timer = null;
                var __search_xhr   = null;
                var __search_last  = '';

                // Recent searches, kept in this browser only (localStorage). Starts
                // recording from first use — it can't recover terms typed before this
                // was deployed.
                var RECENT_KEY = 'product_recent_searches';
                var RECENT_MAX = 10;
                var loadRecent = function() {
                    try { return JSON.parse(localStorage.getItem(RECENT_KEY)) || []; }
                    catch (e) { return []; }
                };
                var saveRecentTerm = function(term) {
                    term = $.trim(term);
                    if (term.length < 2) { return; }
                    var list = loadRecent().filter(function(t) {
                        return t.toLowerCase() !== term.toLowerCase();
                    });
                    list.unshift(term);
                    list = list.slice(0, RECENT_MAX);
                    try { localStorage.setItem(RECENT_KEY, JSON.stringify(list)); } catch (e) {}
                };
                var renderRecent = function() {
                    var list = loadRecent();
                    var $menu = $('#product_search_recent');
                    if (!list.length) { $menu.hide().empty(); return; }
                    var html = '<li class="dropdown-header">Recent searches</li>';
                    list.forEach(function(t) {
                        var esc = $('<div>').text(t).html();
                        html += '<li><a href="#" class="recent-search-item" data-term="' + esc + '">' + esc + '</a></li>';
                    });
                    html += '<li class="divider"></li>';
                    html += '<li><a href="#" id="recent-search-clear" style="color:#a94442;">Clear recent searches</a></li>';
                    $menu.html(html).show();
                };

                var runProductSearch = function(term) {
                    if (typeof product_table === 'undefined') { return; }
                    saveRecentTerm(term);
                    if (term === __search_last) { return; }
                    __search_last = term;
                    // Cancel any in-flight AJAX so old responses don't overwrite new ones
                    if (__search_xhr && __search_xhr.readyState !== 4) {
                        try { __search_xhr.abort(); } catch (e) {}
                    }
                    var settings = product_table.settings()[0];
                    if (settings && settings.jqXHR) {
                        try { settings.jqXHR.abort(); } catch (e) {}
                    }
                    __search_xhr = product_table.search(term).draw();
                };
                $('#product_search_main').on('input', function() {
                    var term = $(this).val();
                    clearTimeout(__search_timer);
                    // Empty -> reset immediately. 1 char -> wait (too broad). 2+ -> 350ms debounce.
                    if (term.length === 0) {
                        renderRecent();
                        runProductSearch('');
                    } else if (term.length === 1) {
                        // Skip — single-character search rarely useful and is the worst case
                        return;
                    } else {
                        $('#product_search_recent').hide();
                        __search_timer = setTimeout(function() { runProductSearch(term); }, 350);
                    }
                });
                // Enter forces the search immediately (skips debounce)
                $('#product_search_main').on('keydown', function(e) {
                    if (e.which === 13) {
                        e.preventDefault();
                        clearTimeout(__search_timer);
                        $('#product_search_recent').hide();
                        runProductSearch($(this).val());
                    }
                });
                // Show recent searches when focusing the (empty) box
                $('#product_search_main').on('focus', function() {
                    if ($.trim($(this).val()) === '') { renderRecent(); }
                });
                // Pick a recent search
                $(document).on('mousedown', '.recent-search-item', function(e) {
                    e.preventDefault();
                    var term = $(this).data('term');
                    $('#product_search_main').val(term);
                    $('#product_search_recent').hide();
                    clearTimeout(__search_timer);
                    runProductSearch(term);
                });
                $(document).on('mousedown', '#recent-search-clear', function(e) {
                    e.preventDefault();
                    try { localStorage.removeItem(RECENT_KEY); } catch (e) {}
                    $('#product_search_recent').hide().empty();
                });
                // Hide the dropdown when clicking away
                $(document).on('click', function(e) {
                    if (!$(e.target).closest('#product_search_main, #product_search_recent').length) {
                        $('#product_search_recent').hide();
                    }
                });
            }

            // Sync the seller's live Discogs "For Sale" inventory so already-listed
            // products show a "Listed" badge instead of offering to list (and
            // duplicate) them. Paged + resumable on the server; we keep calling
            // until done.
            $(document).on('click', '#sync_discogs_listings_btn', function() {
                var $btn = $(this);
                var stored = '';
                try { stored = localStorage.getItem('discogs_seller_username') || ''; } catch (e) {}
                var username = prompt('Your Discogs seller username:', stored);
                if (username === null) { return; }
                username = $.trim(username);
                if (username === '') { toastr.warning('Discogs username is required.'); return; }
                try { localStorage.setItem('discogs_seller_username', username); } catch (e) {}

                $btn.prop('disabled', true);
                var $icon = $btn.find('i');
                $icon.addClass('fa-spin');

                var runPage = function(restart) {
                    $.ajax({
                        url: '/products/sync-discogs-listings',
                        method: 'POST',
                        data: { username: username, restart: restart ? 1 : 0 },
                        dataType: 'json'
                    }).done(function(res) {
                        if (!res || !res.ok) {
                            $icon.removeClass('fa-spin');
                            $btn.prop('disabled', false);
                            toastr.error((res && res.msg) || 'Discogs sync failed.');
                            return;
                        }
                        if (!res.done) {
                            toastr.info('Syncing Discogs… page ' + res.last_page + ' of ' + res.total_pages + ' (' + res.total + ' so far)');
                            runPage(false);
                        } else {
                            $icon.removeClass('fa-spin');
                            $btn.prop('disabled', false);
                            toastr.success('Discogs sync complete — ' + res.total + ' listings found. Refreshing…');
                            if (typeof product_table !== 'undefined') { product_table.ajax.reload(null, false); }
                        }
                    }).fail(function(xhr) {
                        $icon.removeClass('fa-spin');
                        $btn.prop('disabled', false);
                        toastr.error('Request failed: ' + (xhr.statusText || xhr.status));
                    });
                };
                runPage(true);
            });

            // All time toggle for created date range
            $(document).on('change', '#product_list_filter_all_time', function() {
                if ($(this).is(':checked')) {
                    $('#product_list_filter_created_date_range').val('');
                    if ($("#product_list_tab").hasClass('active')) {
                        product_table.ajax.reload();
                    }
                    if ($("#product_stock_report").hasClass('active')) {
                        stock_report_table.ajax.reload();
                    }
                }
            });

            $('#product_list_filter_created_date_range').on('change', function() {
                if ($(this).val()) {
                    $('#product_list_filter_all_time').prop('checked', false);
                    $('#product_list_date_presets .date-preset-btn').removeClass('is-active');
                }
            });

            // Date range preset buttons — drive the existing daterangepicker / All-time
            // checkbox so the AJAX payload (start_date/end_date) doesn't change shape.
            $(document).on('click', '#product_list_date_presets .date-preset-btn', function() {
                var $btn = $(this);
                var preset = $btn.data('preset');
                var $input = $('#product_list_filter_created_date_range');
                var $allChk = $('#product_list_filter_all_time');
                var fmt = (typeof moment_date_format !== 'undefined') ? moment_date_format : 'MM/DD/YYYY';

                $('#product_list_date_presets .date-preset-btn').removeClass('is-active');
                $btn.addClass('is-active');

                if (preset === 'all') {
                    // All Time: clear range, check the legacy box, fire its change handler
                    $input.val('');
                    var drp = $input.data('daterangepicker');
                    if (drp) { drp.setStartDate(moment()); drp.setEndDate(moment()); }
                    $allChk.prop('checked', true).trigger('change');
                    return;
                }

                var start, end = moment();
                if (preset === 'today')      { start = moment(); }
                else if (preset === '7')     { start = moment().subtract(6, 'days'); }
                else if (preset === '30')    { start = moment().subtract(29, 'days'); }
                else /* ytd */               { start = moment().startOf('year'); end = moment().endOf('year'); }

                $allChk.prop('checked', false);
                var drp2 = $input.data('daterangepicker');
                if (drp2) { drp2.setStartDate(start); drp2.setEndDate(end); }
                $input.val(start.format(fmt) + ' ~ ' + end.format(fmt));
                if (typeof product_table !== 'undefined' && $('#product_list_tab').hasClass('active')) {
                    product_table.ajax.reload();
                }
                if (typeof stock_report_table !== 'undefined' && $('#product_stock_report').hasClass('active')) {
                    stock_report_table.ajax.reload();
                }
            });

            // Subcategory options based on selected category
            $(document).on('change', '#product_list_filter_category_id', function() {
                var category_id = $(this).val();
                var $subSelect = $('#product_list_filter_sub_category_id');
                $subSelect.empty().append('<option value="">' + LANG.all + '</option>');

                if (category_id) {
                    $.ajax({
                        url: "{{ url('/products/get_sub_categories') }}",
                        method: 'POST',
                        data: { cat_id: category_id, _token: $('meta[name="csrf-token"]').attr('content') },
                        headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                        success: function(data) {
                            $subSelect.html('<option value="">' + LANG.all + '</option>' + data);
                            $subSelect.trigger('change.select2');
                        },
                    });
                } else {
                    $subSelect.trigger('change.select2');
                }
            });

            $('#opening_stock_modal').on('hidden.bs.modal', function(e) {
                product_table.ajax.reload();
            });

            $('table#product_table tbody').on('click', 'a.delete-product', function(e){
                e.preventDefault();
                swal({
                  title: LANG.sure,
                  icon: "warning",
                  buttons: true,
                  dangerMode: true,
                }).then((willDelete) => {
                    if (willDelete) {
                        var href = $(this).attr('href');
                        $.ajax({
                            method: "DELETE",
                            url: href,
                            dataType: "json",
                            success: function(result){
                                if(result.success == true){
                                    toastr.success(result.msg);
                                    product_table.ajax.reload();
                                } else {
                                    toastr.error(result.msg);
                                }
                            }
                        });
                    }
                });
            });

            $(document).on('click', '#delete-selected', function(e){
                e.preventDefault();
                var selected_rows = getSelectedRows();
                
                if(selected_rows.length > 0){
                    $('input#selected_rows').val(selected_rows);
                    swal({
                        title: LANG.sure,
                        icon: "warning",
                        buttons: true,
                        dangerMode: true,
                    }).then((willDelete) => {
                        if (willDelete) {
                            $('form#mass_delete_form').submit();
                        }
                    });
                } else{
                    $('input#selected_rows').val('');
                    swal('@lang("lang_v1.no_row_selected")');
                }    
            });

            $(document).on('click', '#deactivate-selected', function(e){
                e.preventDefault();
                var selected_rows = getSelectedRows();
                
                if(selected_rows.length > 0){
                    $('input#selected_products').val(selected_rows);
                    swal({
                        title: LANG.sure,
                        icon: "warning",
                        buttons: true,
                        dangerMode: true,
                    }).then((willDelete) => {
                        if (willDelete) {
                            var form = $('form#mass_deactivate_form')

                            var data = form.serialize();
                                $.ajax({
                                    method: form.attr('method'),
                                    url: form.attr('action'),
                                    dataType: 'json',
                                    data: data,
                                    success: function(result) {
                                        if (result.success == true) {
                                            toastr.success(result.msg);
                                            product_table.ajax.reload();
                                            form
                                            .find('#selected_products')
                                            .val('');
                                        } else {
                                            toastr.error(result.msg);
                                        }
                                    },
                                });
                        }
                    });
                } else{
                    $('input#selected_products').val('');
                    swal('@lang("lang_v1.no_row_selected")');
                }
            })

            $(document).on('click', '#merge-selected', function(e){
                e.preventDefault();
                var selected = getSelectedProductIds();
                if (!selected || selected.length < 2) {
                    swal('Select at least 2 products (the duplicates) to merge.');
                    return;
                }
                swal({
                    title: 'Merge ' + selected.length + ' products?',
                    text: 'Keeps the best copy and combines stock + sales onto it. Fully undoable from Admin Action History.',
                    icon: 'warning',
                    buttons: true,
                    dangerMode: true,
                }).then((ok) => {
                    if (!ok) return;
                    $.ajax({
                        method: 'POST',
                        url: '{{ route('products.merge.selected') }}',
                        dataType: 'json',
                        data: { _token: '{{ csrf_token() }}', product_ids: selected },
                        success: function(result){
                            if (result && result.success) {
                                toastr.success(result.msg);
                                product_table.ajax.reload();
                            } else {
                                toastr.error(result && result.msg ? result.msg : 'Merge failed.');
                            }
                        },
                        error: function(){ toastr.error('Merge failed.'); }
                    });
                });
            });

            $(document).on('click', '#send-to-purchase-selected', function(e){
                e.preventDefault();
                var selected_rows = getSelectedRows();

                if (selected_rows.length > 0) {
                    $('input#selected_products_for_purchase').val(selected_rows.join(','));
                    $('form#bulk_send_to_purchase_form').submit();
                } else {
                    $('input#selected_products_for_purchase').val('');
                    swal('@lang("lang_v1.no_row_selected")');
                }
            });

            $(document).on('click', '#edit-selected, #edit-selected-top', function(e){
                e.preventDefault();
                var selected_rows = getSelectedRows();
                
                if(selected_rows.length > 0){
                    $('input#selected_products_for_edit').val(selected_rows);
                    $('form#bulk_edit_form').submit();
                } else{
                    $('input#selected_products_for_edit').val('');
                    swal('@lang("lang_v1.no_row_selected")');
                }    
            })

            $('table#product_table tbody').on('click', 'a.activate-product', function(e){
                e.preventDefault();
                var href = $(this).attr('href');
                $.ajax({
                    method: "get",
                    url: href,
                    dataType: "json",
                    success: function(result){
                        if(result.success == true){
                            toastr.success(result.msg);
                            product_table.ajax.reload();
                        } else {
                            toastr.error(result.msg);
                        }
                    }
                });
            });

            // Initialize date range picker for created date
            if ($('#product_list_filter_created_date_range').length == 1) {
                $('#product_list_filter_created_date_range').daterangepicker(
                    dateRangeSettings,
                    function(start, end) {
                        $('#product_list_filter_created_date_range').val(
                            start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format)
                        );
                        if ($("#product_list_tab").hasClass('active')) {
                            product_table.ajax.reload();
                        }
                    }
                );
                $('#product_list_filter_created_date_range').on('cancel.daterangepicker', function(ev, picker) {
                    $('#product_list_filter_created_date_range').val('');
                    if ($("#product_list_tab").hasClass('active')) {
                        product_table.ajax.reload();
                    }
                });
                // Default to All Time: the picker auto-fills the financial-year range on
                // init, so clear it back to empty so no created-date filter is applied.
                $('#product_list_filter_created_date_range').val('');
            }

            // Show/hide bulk update buttons based on uncategorized filter
            // Use iCheck events for iCheck checkboxes
            $(document).on('ifChecked', '#uncategorized_only', function() {
                $('#export_uncategorized_btn').show();
                if ($("#product_list_tab").hasClass('active')) {
                    product_table.ajax.reload();
                }
            });

            $(document).on('ifUnchecked', '#uncategorized_only', function() {
                $('#export_uncategorized_btn').hide();
                if ($("#product_list_tab").hasClass('active')) {
                    product_table.ajax.reload();
                }
            });

            $(document).on('change', '#product_list_filter_category_id, #product_list_filter_sub_category_id, #location_id, #repair_model_id, #product_list_filter_created_by, #product_list_filter_active_state',
                function() {
                    if ($("#product_list_tab").hasClass('active')) {
                        product_table.ajax.reload();
                    }

                    if ($("#product_stock_report").hasClass('active')) {
                        stock_report_table.ajax.reload();
                    }
            });


            $(document).on('ifChanged', '#not_for_selling, #woocommerce_enabled', function(){
                if ($("#product_list_tab").hasClass('active')) {
                    product_table.ajax.reload();
                }

                if ($("#product_stock_report").hasClass('active')) {
                    stock_report_table.ajax.reload();
                }
            });

            $('#product_location').select2({dropdownParent: $('#product_location').closest('.modal')});

            @if($is_woocommerce)
                $(document).on('click', '.toggle_woocomerce_sync', function(e){
                    e.preventDefault();
                    var selected_rows = getSelectedRows();
                    if(selected_rows.length > 0){
                        $('#woocommerce_sync_modal').modal('show');
                        $("input#woocommerce_products_sync").val(selected_rows);
                    } else{
                        $('input#selected_products').val('');
                        swal('@lang("lang_v1.no_row_selected")');
                    }    
                });

                $(document).on('submit', 'form#toggle_woocommerce_sync_form', function(e){
                    e.preventDefault();
                    var url = $('form#toggle_woocommerce_sync_form').attr('action');
                    var method = $('form#toggle_woocommerce_sync_form').attr('method');
                    var data = $('form#toggle_woocommerce_sync_form').serialize();
                    var ladda = Ladda.create(document.querySelector('.ladda-button'));
                    ladda.start();
                    $.ajax({
                        method: method,
                        dataType: "json",
                        url: url,
                        data:data,
                        success: function(result){
                            ladda.stop();
                            if (result.success) {
                                $("input#woocommerce_products_sync").val('');
                                $('#woocommerce_sync_modal').modal('hide');
                                toastr.success(result.msg);
                                product_table.ajax.reload();
                            } else {
                                toastr.error(result.msg);
                            }
                        }
                    });
                });
            @endif
        });

        $(document).on('shown.bs.modal', 'div.view_product_modal, div.view_modal, #view_product_modal', 
            function(){
                var div = $(this).find('#view_product_stock_details');
            if (div.length) {
                $.ajax({
                    url: "{{action('ReportController@getStockReport')}}"  + '?for=view_product&product_id=' + div.data('product_id'),
                    dataType: 'html',
                    success: function(result) {
                        div.html(result);
                        __currency_convert_recursively(div);
                    },
                });
            }
            __currency_convert_recursively($(this));
        });
        var data_table_initailized = false;
        $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
            if ($(e.target).attr('href') == '#product_stock_report') {
                if (!data_table_initailized) {
                    //Stock report table
                    var stock_report_cols = [
                        { data: 'sku', name: 'sku' },
                        { data: 'product', name: 'product' },
                        { data: 'variation', name: 'variation' },
                        { data: 'category_name', name: 'category_name' },
                        { data: 'location_name', name: 'location_name' },
                        { data: 'unit_price', name: 'unit_price' },
                        { data: 'stock', name: 'stock', searchable: false },
                    ];
                    if ($('th.stock_price').length) {
                        stock_report_cols.push({ data: 'stock_price', name: 'stock_price', searchable: false });
                        stock_report_cols.push({ data: 'stock_value_by_sale_price', name: 'stock_value_by_sale_price', searchable: false, orderable: false });
                        stock_report_cols.push({ data: 'potential_profit', name: 'potential_profit', searchable: false, orderable: false });
                    }

                    stock_report_cols.push({ data: 'total_sold', name: 'total_sold', searchable: false });
                    stock_report_cols.push({ data: 'total_transfered', name: 'total_transfered', searchable: false });
                    stock_report_cols.push({ data: 'total_adjusted', name: 'total_adjusted', searchable: false });
                    stock_report_cols.push({ data: 'product_custom_field1', name: 'product_custom_field1'});
                    stock_report_cols.push({ data: 'product_custom_field2', name: 'product_custom_field2'});
                    stock_report_cols.push({ data: 'product_custom_field3', name: 'product_custom_field3'});
                    stock_report_cols.push({ data: 'product_custom_field4', name: 'product_custom_field4'});

                    if ($('th.current_stock_mfg').length) {
                        stock_report_cols.push({ data: 'total_mfg_stock', name: 'total_mfg_stock', searchable: false });
                    }
                    stock_report_table = $('#stock_report_table').DataTable({
                        processing: true,
                        serverSide: true,
                        scrollY: "75vh",
                        scrollX:        true,
                        scrollCollapse: true,
                        ajax: {
                            url: '/reports/stock-report',
                            data: function(d) {
                                d.location_id = $('#location_id').val();
                                d.category_id = $('#product_list_filter_category_id').val();
                                d.sub_category_id = $('#product_list_filter_sub_category_id').val();
                                if ($('#repair_model_id').length == 1) {
                                    d.repair_model_id = $('#repair_model_id').val();
                                }
                            }
                        },
                        columns: stock_report_cols,
                        fnDrawCallback: function(oSettings) {
                            __currency_convert_recursively($('#stock_report_table'));
                        },
                        "footerCallback": function ( row, data, start, end, display ) {
                            var footer_total_stock = 0;
                            var footer_total_sold = 0;
                            var footer_total_transfered = 0;
                            var total_adjusted = 0;
                            var total_stock_price = 0;
                            var footer_stock_value_by_sale_price = 0;
                            var total_potential_profit = 0;
                            var footer_total_mfg_stock = 0;
                            for (var r in data){
                                footer_total_stock += $(data[r].stock).data('orig-value') ? 
                                parseFloat($(data[r].stock).data('orig-value')) : 0;

                                footer_total_sold += $(data[r].total_sold).data('orig-value') ? 
                                parseFloat($(data[r].total_sold).data('orig-value')) : 0;

                                footer_total_transfered += $(data[r].total_transfered).data('orig-value') ? 
                                parseFloat($(data[r].total_transfered).data('orig-value')) : 0;

                                total_adjusted += $(data[r].total_adjusted).data('orig-value') ? 
                                parseFloat($(data[r].total_adjusted).data('orig-value')) : 0;

                                total_stock_price += $(data[r].stock_price).data('orig-value') ? 
                                parseFloat($(data[r].stock_price).data('orig-value')) : 0;

                                footer_stock_value_by_sale_price += $(data[r].stock_value_by_sale_price).data('orig-value') ? 
                                parseFloat($(data[r].stock_value_by_sale_price).data('orig-value')) : 0;

                                total_potential_profit += $(data[r].potential_profit).data('orig-value') ? 
                                parseFloat($(data[r].potential_profit).data('orig-value')) : 0;

                                footer_total_mfg_stock += $(data[r].total_mfg_stock).data('orig-value') ? 
                                parseFloat($(data[r].total_mfg_stock).data('orig-value')) : 0;
                            }

                            $('.footer_total_stock').html(__currency_trans_from_en(footer_total_stock, false));
                            $('.footer_total_stock_price').html(__currency_trans_from_en(total_stock_price));
                            $('.footer_total_sold').html(__currency_trans_from_en(footer_total_sold, false));
                            $('.footer_total_transfered').html(__currency_trans_from_en(footer_total_transfered, false));
                            $('.footer_total_adjusted').html(__currency_trans_from_en(total_adjusted, false));
                            $('.footer_stock_value_by_sale_price').html(__currency_trans_from_en(footer_stock_value_by_sale_price));
                            $('.footer_potential_profit').html(__currency_trans_from_en(total_potential_profit));
                            if ($('th.current_stock_mfg').length) {
                                $('.footer_total_mfg_stock').html(__currency_trans_from_en(footer_total_mfg_stock, false));
                            }
                        },
                                    });
                    data_table_initailized = true;
                } else {
                    stock_report_table.ajax.reload();
                }
            } else {
                product_table.ajax.reload();
            }
        });

        $(document).on('click', '.update_product_location', function(e){
            e.preventDefault();
            var selected_rows = getSelectedRows();
            
            if(selected_rows.length > 0){
                $('input#selected_products').val(selected_rows);
                var type = $(this).data('type');
                var modal = $('#edit_product_location_modal');
                if(type == 'add') {
                    modal.find('.remove_from_location_title').addClass('hide');
                    modal.find('.add_to_location_title').removeClass('hide');
                } else if(type == 'remove') {
                    modal.find('.add_to_location_title').addClass('hide');
                    modal.find('.remove_from_location_title').removeClass('hide');
                }

                modal.modal('show');
                modal.find('#product_location').select2({ dropdownParent: modal });
                modal.find('#product_location').val('').change();
                modal.find('#update_type').val(type);
                modal.find('#products_to_update_location').val(selected_rows);
            } else{
                $('input#selected_products').val('');
                swal('@lang("lang_v1.no_row_selected")');
            }    
        });
        
         $(document).on('click', '.downloadbarcodes', function(e) {
            e.preventDefault();

            // Get the array of selected rows (IDs)
            var selected_rows = getSelectedRows();

            console.log(selected_rows, 'selected_rows');  // Just to confirm the selected rows are being fetched correctly

            if (selected_rows.length > 0) {
                // Convert the array of selected rows into a comma-separated string of IDs
                var ids = selected_rows.join(',');

                // Create the URL with the selected IDs
                var url = "{{url('download-barcode')}}"+'?ids=' + ids;

                // Redirect to the URL, initiating the download
                window.location.href = url;
            } else {
                alert('Please select at least one product to download barcodes.');
            }
        });

    $(document).on('submit', 'form#edit_product_location_form', function(e) {
        e.preventDefault();
        var form = $(this);
        var data = form.serialize();

        $.ajax({
            method: $(this).attr('method'),
            url: $(this).attr('action'),
            dataType: 'json',
            data: data,
            beforeSend: function(xhr) {
                __disable_submit_button(form.find('button[type="submit"]'));
            },
            success: function(result) {
                if (result.success == true) {
                    $('div#edit_product_location_modal').modal('hide');
                    toastr.success(result.msg);
                    product_table.ajax.reload();
                    $('form#edit_product_location_form')
                    .find('button[type="submit"]')
                    .attr('disabled', false);
                } else {
                    toastr.error(result.msg);
                }
            },
        });

        // Bulk category update handlers - use event delegation
        // Test if button exists
        console.log('Setting up bulk category update handler');
        console.log('Button exists:', $('#bulk_category_update_btn').length > 0);
        
        // Function to load subcategories (defined globally to be accessible from console)
        window.loadSubcategories = function(categoryId, subCategorySelect) {
            console.log('=== loadSubcategories CALLED ===');
            console.log('categoryId:', categoryId);
            console.log('subCategorySelect exists:', subCategorySelect && subCategorySelect.length > 0);
            
            if (!subCategorySelect || subCategorySelect.length === 0) {
                subCategorySelect = $('#bulk_subcategory_id');
                console.log('Using default subCategorySelect element');
            }
            
            if (categoryId) {
                    // Show loading state
                    subCategorySelect.prop('disabled', true);
                    
                    // Destroy Select2 if initialized
                    if (subCategorySelect.hasClass('select2-hidden-accessible')) {
                        subCategorySelect.select2('destroy');
                    }
                    subCategorySelect.html('<option value="">Loading...</option>');
                    
                    // Re-initialize Select2 with loading state
                    subCategorySelect.select2({
                        dropdownParent: $('#bulk_category_update_modal'),
                        placeholder: 'Loading...',
                        disabled: true
                    });
                    
                    $.ajax({
                        url: "{{ route('product.get_sub_categories') }}",
                        type: 'POST',
                        data: { cat_id: categoryId },
                        headers: { 
                            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        beforeSend: function() {
                            console.log('Loading subcategories for category:', categoryId);
                        },
                        success: function (data) {
                            console.log('Subcategories received:', data); // Debug
                            
                            // Destroy Select2 before updating HTML
                            if (subCategorySelect.hasClass('select2-hidden-accessible')) {
                                subCategorySelect.select2('destroy');
                            }
                            
                            subCategorySelect.html(data);
                            subCategorySelect.prop('disabled', false);
                            
                            // Re-initialize Select2
                            subCategorySelect.select2({
                                dropdownParent: $('#bulk_category_update_modal'),
                                placeholder: 'Select Subcategory',
                                allowClear: true
                            });
                            
                            console.log('Subcategories loaded successfully');
                        },
                        error: function (xhr, status, error) {
                            console.error('Error loading subcategories:', {
                                status: status,
                                error: error,
                                response: xhr.responseText,
                                statusCode: xhr.status
                            });
                            toastr.error('Failed to fetch subcategories. Please check console for details.');
                            
                            // Destroy Select2 before updating HTML
                            if (subCategorySelect.hasClass('select2-hidden-accessible')) {
                                subCategorySelect.select2('destroy');
                            }
                            
                            subCategorySelect.html('<option value="">Select Subcategory</option>');
                            subCategorySelect.prop('disabled', false);
                            
                            // Re-initialize Select2 even on error
                            subCategorySelect.select2({
                                dropdownParent: $('#bulk_category_update_modal'),
                                placeholder: 'Select Subcategory',
                                allowClear: true
                            });
                        }
                    });
                } else {
                    // Destroy Select2 if initialized
                    if (subCategorySelect.hasClass('select2-hidden-accessible')) {
                        subCategorySelect.select2('destroy');
                    }
                    subCategorySelect.html('<option value="">Select Subcategory</option>');
                    
                    // Re-initialize Select2
                    subCategorySelect.select2({
                        dropdownParent: $('#bulk_category_update_modal'),
                        placeholder: 'Select Subcategory',
                        allowClear: true
                    });
                }
        };

        // Initialize Select2 and bind events when modal is shown
        $('#bulk_category_update_modal').on('shown.bs.modal', function () {
            console.log('Modal shown, initializing Select2...');
            
            // Destroy existing Select2 instances if any
            if ($('#bulk_category_id').hasClass('select2-hidden-accessible')) {
                $('#bulk_category_id').select2('destroy');
            }
            if ($('#bulk_subcategory_id').hasClass('select2-hidden-accessible')) {
                $('#bulk_subcategory_id').select2('destroy');
            }
            
            // Initialize category Select2
            $('#bulk_category_id').select2({
                dropdownParent: $('#bulk_category_update_modal'),
                placeholder: 'Select Category',
                allowClear: true
            });
            
            // Initialize subcategory Select2
            $('#bulk_subcategory_id').select2({
                dropdownParent: $('#bulk_category_update_modal'),
                placeholder: 'Select Subcategory',
                allowClear: true
            });
            
            // Remove any existing event handlers and bind new one
            // Use Select2 specific events - trigger change event which will handle loading
            $('#bulk_category_id').off('select2:select select2:unselect change').on('select2:select', function (e) {
                const categoryId = $(this).val();
                console.log('Select2 select event fired! Category ID:', categoryId);
                // Trigger change event which will handle the subcategory loading
                setTimeout(() => {
                    $(this).trigger('change');
                }, 50);
            });
            
            // Handle change event - this is where we actually load subcategories
            $('#bulk_category_id').on('change', function() {
            const categoryId = $(this).val();
            const subCategorySelect = $('#bulk_subcategory_id');
            
                console.log('Change event fired! Category ID:', categoryId);
            
            if (categoryId) {
                    // Only load if not already loading (avoid duplicate calls)
                    if (!subCategorySelect.prop('disabled')) {
                        window.loadSubcategories(categoryId, subCategorySelect);
                    }
                } else {
                    // Clear subcategory if no category selected
                if (subCategorySelect.hasClass('select2-hidden-accessible')) {
                    subCategorySelect.select2('destroy');
                }
                    subCategorySelect.html('<option value="">Select Subcategory</option>');
                subCategorySelect.select2({
                    dropdownParent: $('#bulk_category_update_modal'),
                        placeholder: 'Select Subcategory',
                        allowClear: true
                });
                }
            });
            
            console.log('Select2 initialized and events bound');
            console.log('Test: Try manually calling window.loadSubcategories(1, $("#bulk_subcategory_id")) in console');
        });

        // Handle category change to load subcategories - use event delegation as fallback
        // This ensures it works even if modal initialization fails
        // Use Select2 specific events
        $(document).on('select2:select select2:unselect change', '#bulk_category_id', function(e) {
            const categoryId = $(this).val();
            const subCategorySelect = $('#bulk_subcategory_id');
            
            console.log('Fallback handler fired! Event:', e.type, 'Category ID:', categoryId);
            
            if (categoryId) {
                // Use the loadSubcategories function
                window.loadSubcategories(categoryId, subCategorySelect);
            } else {
                // Clear subcategory if no category selected
                if (subCategorySelect.hasClass('select2-hidden-accessible')) {
                    subCategorySelect.select2('destroy');
                }
                subCategorySelect.html('<option value="">Select Subcategory</option>');
                if ($('#bulk_category_update_modal').is(':visible')) {
                subCategorySelect.select2({
                    dropdownParent: $('#bulk_category_update_modal'),
                        placeholder: 'Select Subcategory',
                        allowClear: true
                    });
                    }
                }
            });

        // Functions are now defined globally above, before document.ready - no need to redefine here

        // Update selected count when modal opens
        $('#bulk_category_update_modal').on('show.bs.modal', function() {
            const updateAll = $('#update_all_visible').is(':checked');
            if (updateAll) {
                const allIds = window.getAllVisibleProductIds();
                $('#bulk_update_note').text(`This will update all ${allIds.length} visible uncategorized products.`);
                $('#selected_count_alert').hide();
            } else {
                const selectedIds = window.getSelectedProductIds();
                $('#bulk_update_note').text(`This will update only selected products.`);
                if (selectedIds.length > 0) {
                    $('#selected_products_count').text(selectedIds.length);
                    $('#selected_count_alert').show();
                } else {
                    $('#selected_count_alert').hide();
                }
            }
        });

        // Update info when checkbox changes
        $('#update_all_visible').on('change', function() {
            const updateAll = $(this).is(':checked');
            if (updateAll) {
                const allIds = window.getAllVisibleProductIds();
                $('#bulk_update_note').text(`This will update all ${allIds.length} visible uncategorized products.`);
                $('#selected_count_alert').hide();
            } else {
                const selectedIds = window.getSelectedProductIds();
                $('#bulk_update_note').text(`This will update only selected products.`);
                if (selectedIds.length > 0) {
                    $('#selected_products_count').text(selectedIds.length);
                    $('#selected_count_alert').show();
                } else {
                    $('#selected_count_alert').hide();
                    $('#bulk_update_note').html(`<span class="text-warning">No products selected. Please select products using checkboxes in the table.</span>`);
                }
            }
        });

        // Update count when checkboxes change (listen to table checkbox changes)
        $(document).on('change', '#product_table input[type="checkbox"]:not(#select-all-row)', function() {
            if ($('#bulk_category_update_modal').hasClass('in') || $('#bulk_category_update_modal').is(':visible')) {
                const updateAll = $('#update_all_visible').is(':checked');
                if (!updateAll) {
                    const selectedIds = window.getSelectedProductIds();
                    if (selectedIds.length > 0) {
                        $('#selected_products_count').text(selectedIds.length);
                        $('#selected_count_alert').show();
                        $('#bulk_update_note').text(`This will update only selected products.`);
                    } else {
                        $('#selected_count_alert').hide();
                        $('#bulk_update_note').html(`<span class="text-warning">No products selected. Please select products using checkboxes in the table.</span>`);
                    }
                }
            }
        });

        // Confirm bulk category update - use event delegation
        $(document).on('click', '#confirm_bulk_category_update', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            console.log('Update Categories button clicked!');
            const categoryId = $('#bulk_category_id').val();
            const subCategoryId = $('#bulk_subcategory_id').val();
            
            console.log('Category ID:', categoryId);
            console.log('Subcategory ID:', subCategoryId);
            
            if (!categoryId) {
                toastr.error('Please select a category.');
                return false;
            }

            // Get product IDs based on selection mode
            const updateAll = $('#update_all_visible').is(':checked');
            let productIds = [];
            
            if (updateAll) {
                productIds = window.getAllVisibleProductIds();
            } else {
                productIds = window.getSelectedProductIds();
            }

            if (productIds.length === 0) {
                toastr.error('No products found to update. Please select products or check "Update all visible" option.');
                return;
            }

            const actionText = updateAll ? 'all visible' : 'selected';
            if (!confirm(`Are you sure you want to update ${productIds.length} ${actionText} product(s)?`)) {
                return;
            }

            $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Updating...');

            console.log('Sending bulk update request:', {
                product_ids: productIds,
                category_id: categoryId,
                sub_category_id: subCategoryId
            });

            $.ajax({
                url: "{{ url('products/bulk-update-categories') }}",
                type: 'POST',
                data: {
                    product_ids: productIds,
                    category_id: categoryId,
                    sub_category_id: subCategoryId || null
                },
                headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                success: function(response) {
                    console.log('Bulk update response:', response);
                    if (response.success) {
                        toastr.success(response.msg || `Successfully updated ${productIds.length} products.`);
                        $('#bulk_category_update_modal').modal('hide');
                        // Reset form
                        $('#bulk_category_id').val(null).trigger('change');
                        $('#bulk_subcategory_id').html('<option value="">Select Subcategory</option>');
                        $('#update_all_visible').prop('checked', true);
                        // Reload table after a short delay to ensure update is visible
                        setTimeout(function() {
                            product_table.ajax.reload(null, false); // false = don't reset paging
                        }, 500);
                    } else {
                        toastr.error(response.msg || 'Failed to update products.');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Bulk update error:', {
                        status: status,
                        error: error,
                        response: xhr.responseText,
                        statusCode: xhr.status
                    });
                    let errorMsg = 'An error occurred while updating products.';
                    if (xhr.responseJSON && xhr.responseJSON.msg) {
                        errorMsg = xhr.responseJSON.msg;
                    } else if (xhr.responseText) {
                        try {
                            const errorData = JSON.parse(xhr.responseText);
                            if (errorData.msg) {
                                errorMsg = errorData.msg;
                            }
                        } catch (e) {
                            // If not JSON, use default message
                            console.error('Failed to parse error response:', e);
                        }
                    }
                    toastr.error(errorMsg);
                },
                complete: function() {
                    $('#confirm_bulk_category_update').prop('disabled', false).html('Update Categories');
                }
            });
        });

        // Export uncategorized products - use event delegation
        $(document).on('click', '#export_uncategorized_btn', function(e) {
            e.preventDefault();
            window.location.href = "{{ action('ProductController@exportUncategorized') }}";
        });
    });

        function openAddStock(elem) {
            // Get the location value
            var locationId = $('#location').val();

            // Check if location is selected
            if (!locationId) {
                alert('Please select a location first.');
                return;
            }

            // Get the product and variation IDs from the clicked element's data attributes
            var productId = $(elem).data('pr');
            var variationId = $(elem).data('vr');
            var stockToAdd = $(elem).data('stock');



            // Perform the AJAX request
            $.ajax({
                url: '/updateStock',  // Laravel route URL
                type: 'POST',
                data: {
                    _token: '{{ csrf_token() }}',  // Ensure CSRF token is included
                    location_id: locationId,
                    product_id: productId,
                    variation_id: variationId,
                    stock: stockToAdd
                },
                success: function(response) {
                    // Handle success response
                    alert('Stock updated successfully!');
                    let url = "{{url("labels/show?product_id=:id")}}";
                    url = url.replace(":id" , productId)
                    location = url
                },
                error: function(xhr) {
                    // Handle error
                    alert('An error occurred while updating stock.');
                }
            });
        }

        // Bulk category update button click handler - redirect to dedicated page
        $(document).on('click', '#bulk_category_update_btn', function(e) {
            e.preventDefault();
            
            console.log('Bulk category update button clicked');
            
            // Get selected product IDs (checked checkboxes)
            const selectedIds = window.getSelectedProductIds();
            console.log('Selected product IDs:', selectedIds);
            
            // If no products selected, get all visible products
            let productIds = selectedIds;
            if (productIds.length === 0) {
                productIds = window.getAllVisibleProductIds();
                console.log('No products selected, using all visible products:', productIds);
            }
            
            if (productIds.length === 0) {
                toastr.warning('No products found. Please select products using checkboxes or make sure products are visible in the table.');
                return false;
            }
            
            // Build URL with product IDs
            const baseUrl = $(this).attr('href') || "{{ action('ProductController@bulkCategoryUpdatePage') }}";
            const url = baseUrl + '?product_ids=' + productIds.join(',');
            
            console.log('Redirecting to:', url);
            window.location.href = url;

            return false;
        });

        function runEbayList(productId, $link) {
            var $icon = $link.find('i');
            var originalIcon = 'fa-shopping-cart';
            $icon.removeClass('fa-shopping-cart').addClass('fa-spinner fa-spin');
            $link.css('pointer-events', 'none');

            $.ajax({
                url: '/products/' + productId + '/list-to-ebay',
                method: 'POST',
                data: {},
                dataType: 'json'
            }).done(function(result) {
                if (result && result.success) {
                    toastr.success(result.msg || 'Listed on eBay.');
                    if (typeof product_table !== 'undefined') {
                        product_table.ajax.reload(null, false);
                    }
                } else {
                    toastr.error((result && result.msg) || 'Failed to list on eBay.');
                }
            }).fail(function(xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.msg) ? xhr.responseJSON.msg : (xhr.statusText || xhr.status);
                toastr.error('Request failed: ' + msg);
            }).always(function() {
                $icon.removeClass('fa-spinner fa-spin').addClass(originalIcon);
                $link.css('pointer-events', '');
            });
        }

        $(document).on('click', '.list-to-ebay', function(e) {
            e.preventDefault();
            var $link = $(this);
            var productId = $link.data('id');

            $.getJSON('/products/' + productId + '/ebay-preflight').done(function(preflight) {
                if (!preflight || !preflight.ok) {
                    var errs = (preflight && preflight.errors) ? preflight.errors.join(' ') : 'This product cannot be listed on eBay.';
                    toastr.error(errs);
                    return;
                }
                var confirmMsg = 'List this product on eBay? This creates a real, live listing.';
                if (preflight.warnings && preflight.warnings.length) {
                    confirmMsg += '\n\n' + preflight.warnings.join('\n');
                }
                if (!confirm(confirmMsg)) {
                    return;
                }
                runEbayList(productId, $link);
            }).fail(function(xhr) {
                toastr.error('Preflight check failed: ' + (xhr.statusText || xhr.status));
            });
        });

        $(document).on('click', '.list-to-discogs', function(e) {
            e.preventDefault();
            var $link = $(this);
            var productId = $link.data('id');
            var originalIcon = 'fa-music';

            if (!confirm('List this product on Discogs? This creates a real, live listing.')) {
                return;
            }

            var $icon = $link.find('i');
            $icon.removeClass('fa-music').addClass('fa-spinner fa-spin');
            $link.css('pointer-events', 'none');

            $.ajax({
                url: '/products/' + productId + '/list-to-discogs',
                method: 'POST',
                data: {},
                dataType: 'json'
            }).done(function(result) {
                if (result && result.success) {
                    toastr.success(result.msg || 'Listed on Discogs.');
                } else {
                    toastr.error((result && result.msg) || 'Failed to list on Discogs.');
                }
            }).fail(function(xhr) {
                toastr.error('Request failed: ' + (xhr.statusText || xhr.status));
            }).always(function() {
                $icon.removeClass('fa-spinner fa-spin').addClass(originalIcon);
                $link.css('pointer-events', '');
            });
        });

        $(document).on('click', '#bulk_list_ebay_btn', function(e) {
            e.preventDefault();
            var selectedIds = window.getSelectedProductIds ? window.getSelectedProductIds() : [];
            if (!selectedIds.length) {
                toastr.warning('Select at least one product to list on eBay.');
                return;
            }
            if (!confirm('List ' + selectedIds.length + ' selected product(s) on eBay? This creates real, live listings.')) {
                return;
            }
            var $btn = $(this);
            $btn.prop('disabled', true);
            $.ajax({
                url: '/products/bulk-list-to-ebay',
                method: 'POST',
                data: { product_ids: selectedIds, _token: $('meta[name="csrf-token"]').attr('content') },
                dataType: 'json'
            }).done(function(result) {
                toastr.info(result.msg || 'Bulk listing finished.');
                if (result.results && result.results.length) {
                    result.results.forEach(function(r) {
                        if (!r.success) {
                            toastr.error('Product #' + r.product_id + ': ' + (r.msg || 'failed'));
                        }
                    });
                }
                if (typeof product_table !== 'undefined') {
                    product_table.ajax.reload(null, false);
                }
            }).fail(function(xhr) {
                toastr.error('Bulk list failed: ' + (xhr.statusText || xhr.status));
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });

        // --- Discogs release id: inline set / change + fill-blanks import ---
        // Open the modal from the "Discogs ID" column. stopPropagation keeps the
        // row-click (which navigates to the product view) from firing.
        $(document).on('click', '#product_table tbody a.edit-discogs-id', function(e) {
            e.preventDefault();
            e.stopPropagation();
            var $a = $(this);
            $('#discogs_id_product_id').val($a.data('id'));
            $('#discogs_id_input').val($a.data('release-id') || '');
            $('#discogs_id_modal').modal('show');
        });

        $(document).on('click', '#discogs_id_save_btn', function() {
            var productId = $('#discogs_id_product_id').val();
            var releaseId = $.trim(String($('#discogs_id_input').val() || ''));
            if (!productId) { return; }
            var $btn = $(this);
            $btn.prop('disabled', true);
            $.ajax({
                url: '/product/' + productId + '/discogs-release-id',
                type: 'POST',
                data: { _token: '{{ csrf_token() }}', release_id: releaseId },
                success: function(resp) {
                    if (resp && resp.success) {
                        var msg = resp.msg || 'Saved.';
                        if (resp.release_label) {
                            msg = 'Imported: ' + resp.release_label;
                            if (resp.imported && resp.imported.length) {
                                msg += ' (' + resp.imported.join(', ') + ')';
                            }
                        }
                        toastr.success(msg);
                        $('#discogs_id_modal').modal('hide');
                        if (typeof product_table !== 'undefined' && product_table) {
                            product_table.ajax.reload(null, false);
                        }
                    } else {
                        toastr.error((resp && resp.msg) || 'Could not save the release id.');
                    }
                },
                error: function(xhr) {
                    toastr.error('Could not save the release id: ' + (xhr.statusText || xhr.status));
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        });

    </script>
@endsection
