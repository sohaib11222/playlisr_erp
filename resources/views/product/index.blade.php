@extends('layouts.app')
@section('title', __('sale.products'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>@lang('sale.products')
        <small>@lang('lang_v1.manage_products')</small>
    </h1>
    <!-- <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Level</a></li>
        <li class="active">Here</li>
    </ol> -->
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
    #product_filters_bar .product-search-input {
        max-width: 520px;
        width: 100%;
    }
</style>

<div id="product_filters_bar">
    <div class="row" style="margin-bottom: 5px;">
        <div class="col-md-7 col-sm-12">
            <div class="form-group">
                <input type="text"
                       id="product_search_main"
                       class="form-control product-search-input"
                       placeholder="Search products (artist / title / SKU / barcode)">
            </div>
        </div>
        <div class="col-md-5 col-sm-12 text-right">
            <div class="btn-toolbar" style="justify-content: flex-end; display: flex; gap: 5px;">
                @if($is_admin)
                    <a class="btn btn-default" href="{{action('ProductController@downloadExcel')}}">
                        <i class="fa fa-download"></i> Export
                    </a>
                @endif
                <div class="btn-group">
                    <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        Bulk Actions <span class="caret"></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-right">
                        @if($is_admin)
                            <li><a href="#" id="bulk_action_bulk_category_update">Bulk Update Categories</a></li>
                            <li><a href="{{action('ProductController@importSoldItems')}}">Import Sold Items as Products</a></li>
                            <li><a href="{{url('import-products')}}">Import Products</a></li>
                        @endif
                        @if(config('constants.enable_product_bulk_edit') && ($is_admin || auth()->user()->can('product.update')))
                            <li><a href="#" id="bulk_action_bulk_edit">Bulk Edit</a></li>
                        @endif
                        <li><a href="#" id="bulk_action_download_barcodes">Download Barcodes</a></li>
                    </ul>
                </div>
                @can('product.create')                            
                    <a class="btn btn-primary" href="{{action('ProductController@create')}}">
                        <i class="fa fa-plus"></i> @lang('messages.add')
                    </a>
                @endcan
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
            <div class="col-md-4">
                <div class="form-group">
                    {!! Form::label('created_date_range', __('lang_v1.created_date_range') . ':') !!}
                    <div class="input-group">
                        {!! Form::text('created_date_range', null, ['class' => 'form-control', 'id' => 'product_list_filter_created_date_range', 'placeholder' => __('lang_v1.select_a_date_range'), 'readonly']) !!}
                        <span class="input-group-addon">
                            <label style="margin:0; font-weight:400;">
                                <input type="checkbox" id="product_list_filter_all_time"> @lang('lang_v1.all')
                            </label>
                        </span>
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
                                $ebayService = app(\App\Services\EbayService::class);
                                $discogsService = app(\App\Services\DiscogsService::class);
                            @endphp
                            @if($ebayService->isConfigured())
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
            
            var updatedAtColIndex = $('#product_table thead th').filter(function() {
                return $(this).text().trim() === 'Last updated at';
            }).index();

            product_table = $('#product_table').DataTable({
                processing: true,
                serverSide: true,
                aaSorting: [[updatedAtColIndex >= 0 ? updatedAtColIndex : 11, 'desc']],
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
                        { data: 'product', name: 'products.name'  },
                        { data: 'artist', name: 'products.artist'},
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
                        { data: 'updated_at', name: 'updated_at'},
                        { data: 'created_by_name', name: 'u.first_name' }
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

            $('.add-stock').on( 'click',  function () {
                alert($(this).data('pr'))
            })

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

            // Hook up main search bar to DataTables
            if ($('#product_search_main').length) {
                $('#product_search_main').on('keyup change', function() {
                    if (typeof product_table !== 'undefined') {
                        product_table.search($(this).val()).draw();
                    }
                });
            }

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

            $(document).on('change', '#product_list_filter_category_id, #product_list_filter_sub_category_id, #location_id, #repair_model_id, #product_list_filter_created_by', 
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

        // Per-row "List on Discogs" / "List on eBay" actions. Both endpoints take just the
        // product ID and pull price/stock/name off the product record; no modal needed.
        // The dropdown items are only rendered server-side when the respective service
        // reports isConfigured(), so reaching this handler means credentials exist — any
        // failure after that is a real marketplace-side error, surfaced via toastr.
        $(document).on('click', '.list-to-discogs, .list-to-ebay', function(e) {
            e.preventDefault();
            var $link = $(this);
            var productId = $link.data('id');
            var isEbay = $link.hasClass('list-to-ebay');
            var platform = isEbay ? 'ebay' : 'discogs';
            var platformLabel = isEbay ? 'eBay' : 'Discogs';
            var originalIcon = isEbay ? 'fa-shopping-cart' : 'fa-music';

            if (!confirm('List this product on ' + platformLabel + '? This creates a real, live listing.')) {
                return;
            }

            var $icon = $link.find('i');
            $icon.removeClass('fa-shopping-cart fa-music').addClass('fa-spinner fa-spin');
            $link.css('pointer-events', 'none');

            $.ajax({
                url: '/products/' + productId + '/list-to-' + platform,
                method: 'POST',
                data: {},
                dataType: 'json'
            }).done(function(result) {
                if (result && result.success) {
                    toastr.success(result.msg || 'Listed on ' + platformLabel + '.');
                } else {
                    toastr.error((result && result.msg) || 'Failed to list on ' + platformLabel + '.');
                }
            }).fail(function(xhr) {
                toastr.error('Request failed: ' + (xhr.statusText || xhr.status));
            }).always(function() {
                $icon.removeClass('fa-spinner fa-spin').addClass(originalIcon);
                $link.css('pointer-events', '');
            });
        });

    </script>
@endsection
