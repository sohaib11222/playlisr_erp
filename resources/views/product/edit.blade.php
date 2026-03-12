@extends('layouts.app')
@section('title', __('product.edit_product'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>@lang('product.edit_product')</h1>
    <!-- <ol class="breadcrumb">
        <li><a href="#"><i class="fa fa-dashboard"></i> Level</a></li>
        <li class="active">Here</li>
    </ol> -->
</section>

<!-- Main content -->
<section class="content">
{!! Form::open(['url' => action('ProductController@update' , [$product->id] ), 'method' => 'PUT', 'id' => 'product_add_form',
        'class' => 'product_form', 'files' => true, 'data-product-edit' => '1' ]) !!}
    <input type="hidden" id="product_id" value="{{ $product->id }}">

    @component('components.widget', ['class' => 'box-primary'])
        <div class="row">
            <div class="col-sm-4">
              <div class="form-group">
                {!! Form::label('name', __('product.product_name') . ':*') !!}
                  {!! Form::text('name', $product->name, ['class' => 'form-control', 'required',
                  'placeholder' => __('product.product_name')]); !!}
              </div>
            </div>

            <div class="col-sm-4">
              <div class="form-group">
                {!! Form::label('sku', __('product.sku')  . ':*') !!} @show_tooltip(__('tooltip.sku'))
                {!! Form::text('sku', $product->sku, ['class' => 'form-control',
                'placeholder' => __('product.sku'), 'required']); !!}
              </div>
            </div>

            <div class="col-sm-4" style="display:none;">
              <div class="form-group">
                {!! Form::label('barcode_type', __('product.barcode_type') . ':*') !!}
                  {!! Form::select('barcode_type', $barcode_types, $product->barcode_type, ['placeholder' => __('messages.please_select'), 'class' => 'form-control select2', 'required']); !!}
              </div>
            </div>

            <div class="clearfix"></div>
            
            <div class="col-sm-4" style="display: none">
              <div class="form-group">
                {!! Form::label('unit_id', __('product.unit') . ':*') !!}
                <div class="input-group">
                  {!! Form::select('unit_id', $units, $product->unit_id, ['placeholder' => __('messages.please_select'), 'class' => 'form-control select2', 'required']); !!}
                  <span class="input-group-btn">
                    <button type="button" @if(!auth()->user()->can('unit.create')) disabled @endif class="btn btn-default bg-white btn-flat quick_add_unit btn-modal" data-href="{{action('UnitController@create', ['quick_add' => true])}}" title="@lang('unit.add_unit')" data-container=".view_modal"><i class="fa fa-plus-circle text-primary fa-lg"></i></button>
                  </span>
                </div>
              </div>
            </div>


            <div class="col-sm-4 @if(!session('business.enable_brand')) hide @endif">
              <div class="form-group">
                {!! Form::label('brand_id', __('product.brand') . ':') !!}
                <div class="input-group">
                  {!! Form::select('brand_id', $brands, $product->brand_id, ['placeholder' => __('messages.please_select'), 'class' => 'form-control select2']); !!}
                  <span class="input-group-btn">
                    <button type="button" @if(!auth()->user()->can('brand.create')) disabled @endif class="btn btn-default bg-white btn-flat btn-modal" data-href="{{action('BrandController@create', ['quick_add' => true])}}" title="@lang('brand.add_brand')" data-container=".view_modal"><i class="fa fa-plus-circle text-primary fa-lg"></i></button>
                  </span>
                </div>
              </div>
            </div>
            
            <div class="col-sm-4">
                <div class="form-group">
                    {!! Form::label('artist', 'Artist' . ':') !!}
                    {!! Form::text('artist', !empty($product->artist) ? $product->artist : null, ['class' => 'form-control',
                    'placeholder' => 'Artist']); !!}
                </div>
            </div>

            <div class="col-sm-4">
                <div class="form-group">
                    {!! Form::label('bin_position', 'Bin Position' . ':') !!}
                    {!! Form::text('bin_position', !empty($product->bin_position) ? $product->bin_position : null, ['class' => 'form-control',
                    'placeholder' => 'e.g., A-12, B-5']); !!}
                </div>
            </div>

            <div class="col-sm-4">
                <div class="form-group">
                    {!! Form::label('listing_location', 'Listing Location' . ':') !!}
                    {!! Form::text('listing_location', !empty($product->listing_location) ? $product->listing_location : null, ['class' => 'form-control',
                    'placeholder' => 'e.g., Warehouse A, Storage B']); !!}
                    <p class="help-block">Location for eBay/Discogs listings</p>
                </div>
            </div>

            <div class="col-sm-4 @if(!session('business.enable_category')) hide @endif">
              <div class="form-group">
                {!! Form::label('category_id', __('product.category') . ' *:') !!}
                  {!! Form::select('category_id', $categories, $product->category_id, ['placeholder' => __('messages.please_select'), 'class' => 'form-control select2', 'required']); !!}
              </div>
            </div>

            <div class="col-sm-4 @if(!(session('business.enable_category') && session('business.enable_sub_category'))) hide @endif">
              <div class="form-group">
                {!! Form::label('sub_category_id', __('product.sub_category') . ' *:') !!}
                  {!! Form::select('sub_category_id', $sub_categories, $product->sub_category_id, ['placeholder' => __('messages.please_select'), 'class' => 'form-control select2', 'required']); !!}
              </div>
            </div>

            <div class="col-sm-4">
                <div class="form-group">
                    {!! Form::label('product_custom_field1', 'Image Url') !!}
                    {!! Form::text('product_custom_field1', !empty($product->product_custom_field1) ? $product->product_custom_field1 : null, ['class' => 'form-control',
                    'placeholder' => 'Image Url']); !!}
                </div>
            </div>

            <div class="col-sm-4">
              <div class="form-group">
                {!! Form::label('product_locations', __('business.business_locations') . ':') !!} @show_tooltip(__('lang_v1.product_location_help'))
                  {!! Form::select('product_locations[]', $business_locations, $product->product_locations->pluck('id'), ['class' => 'form-control select2', 'multiple', 'id' => 'product_locations']); !!}
              </div>
            </div>

            <div class="clearfix"></div>
            
            <div class="col-sm-4">
              <div class="form-group">
              <br>
                <label>
                  {!! Form::checkbox('enable_stock', 1, $product->enable_stock, ['class' => 'input-icheck', 'id' => 'enable_stock']); !!} <strong>@lang('product.manage_stock')</strong>
                </label>@show_tooltip(__('tooltip.enable_stock')) <p class="help-block"><i>@lang('product.enable_stock_help')</i></p>
              </div>
            </div>

            <!-- include module fields -->

            <div class="clearfix"></div>
            <div class="col-sm-8">
              <div class="form-group">
                {!! Form::label('product_description', __('lang_v1.product_description') . ':') !!}
                  {!! Form::textarea('product_description', $product->product_description, ['class' => 'form-control']); !!}
              </div>
            </div>
            <div class="col-sm-4">
              <div class="form-group">
                {!! Form::label('image', __('lang_v1.product_image') . ':') !!}
                {!! Form::file('image', ['id' => 'upload_image', 'accept' => 'image/*']); !!}
                <small><p class="help-block">@lang('purchase.max_file_size', ['size' => (config('constants.document_size_limit') / 1000000)]). @lang('lang_v1.aspect_ratio_should_be_1_1') @if(!empty($product->image)) <br> @lang('lang_v1.previous_image_will_be_replaced') @endif</p></small>
              </div>
            </div>
            </div>

    @endcomponent


    @component('components.widget', ['class' => 'box-primary'])
        <div class="row">

            <div class="col-sm-4 @if(!session('business.enable_price_tax')) hide @endif">
              <div class="form-group">
                {!! Form::label('tax_type', __('product.selling_price_tax_type') . ':*') !!}
                  {!! Form::select('tax_type',['inclusive' => __('product.inclusive'), 'exclusive' => __('product.exclusive')], $product->tax_type,
                  ['class' => 'form-control select2', 'required']); !!}
              </div>
            </div>
            
            <div class="col-sm-4 @if(!session('business.enable_price_tax')) hide @endif">
              <div class="form-group">
                <br>
                <label>
                    {!! Form::checkbox('tax_exempt', 1, !empty($product->tax_exempt) ? $product->tax_exempt : false, ['class' => 'input-icheck']); !!} 
                    <strong>Tax Exempt</strong>
                </label>
                <p class="help-block">Check if this product is exempt from sales tax</p>
              </div>
            </div>

            <div class="clearfix"></div>
            <div class="col-sm-4">
              <div class="form-group">
                {!! Form::label('type', __('product.product_type') . ':*') !!} @show_tooltip(__('tooltip.product_type'))
                {!! Form::select('type', $product_types, $product->type, ['class' => 'form-control select2',
                  'required','disabled', 'data-action' => 'edit', 'data-product_id' => $product->id ]); !!}
              </div>
            </div>

            <div class="form-group col-sm-12" id="product_form_part"></div>
            <input type="hidden" id="variation_counter" value="0">
            <input type="hidden" id="default_profit_percent" value="{{ $default_profit_percent }}">
            </div>
    @endcomponent

  <div class="row">
    <input type="hidden" name="submit_type" id="submit_type">
        <div class="col-sm-12">
          <div class="text-center">
            <div class="btn-group">
              @if($selling_price_group_count)
                <button type="submit" value="submit_n_add_selling_prices" class="btn btn-warning submit_product_form">@lang('lang_v1.save_n_add_selling_price_group_prices')</button>
              @endif

              @can('product.opening_stock')
              <button type="submit" @if(empty($product->enable_stock)) disabled="true" @endif id="opening_stock_button"  value="update_n_edit_opening_stock" class="btn bg-purple submit_product_form">@lang('lang_v1.update_n_edit_opening_stock')</button>
              @endif

              <button type="submit" value="save_n_add_another" class="btn bg-maroon submit_product_form">@lang('lang_v1.update_n_add_another')</button>

              <button type="submit" value="submit" class="btn btn-primary submit_product_form">@lang('messages.update')</button>
            </div>
          </div>
        </div>
  </div>
{!! Form::close() !!}

    @if(!empty($product->enable_stock))
    @component('components.widget', ['class' => 'box-primary'])
        <div class="row">
            <div class="col-sm-12">
                <h4>@lang('product.set_current_stock')</h4>
                <p class="help-block">@lang('product.set_current_stock_help')</p>
            </div>
        </div>
        <div class="row">
            <div class="col-sm-12">
                <form id="set_current_stock_form" action="{{ url('products/' . $product->id . '/set-current-stock') }}" method="post">
                    @csrf
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>@lang('business.location')</th>
                                @if($product->type == 'variable')
                                    <th>@lang('product.variation_name')</th>
                                @endif
                                <th>@lang('report.current_stock')</th>
                                <th>@lang('product.set_to_quantity')</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $product_locations = $product->product_locations;
                                $all_variations = $product->product_variations->flatMap(function ($pv) { return $pv->variations; });
                            @endphp
                            @if($product_locations->isEmpty() || $all_variations->isEmpty())
                                <tr><td colspan="{{ $product->type == 'variable' ? 4 : 3 }}" class="text-center text-muted">@lang('product.set_current_stock') — @lang('business.business_locations') / @lang('product.variations') required.</td></tr>
                            @else
                            @foreach($product_locations as $loc)
                                @foreach($all_variations as $var)
                                    @php
                                        $vld = $var->variation_location_details->where('location_id', $loc->id)->first();
                                        $current_qty = $vld ? (float) $vld->qty_available : 0;
                                        $current_qty_int = (int) round($current_qty);
                                    @endphp
                                    <tr>
                                        @if($loop->first)
                                            <td rowspan="{{ $all_variations->count() }}">{{ $loc->name }}</td>
                                        @endif
                                        @if($product->type == 'variable')
                                            <td>{{ $var->name ?? $var->sub_sku }}</td>
                                        @endif
                                        <td>{{ $current_qty_int }}</td>
                                        <td>
                                            <input type="number" min="0" step="1" name="current_stock[{{ $loc->id }}][{{ $var->id }}]" value="{{ $current_qty_int }}" class="form-control input-sm" style="width: 100px;">
                                        </td>
                                    </tr>
                                @endforeach
                            @endforeach
                            @endif
                        </tbody>
                    </table>
                    <button type="button" class="btn btn-primary btn-sm" id="btn_set_current_stock">@lang('messages.update') @lang('product.set_current_stock')</button>
                </form>
            </div>
        </div>
    @endcomponent
    @endif
</section>
<!-- /.content -->

@endsection

@section('javascript')
  <script src="{{ asset('js/product.js?v=' . $asset_v) }}"></script>
  <script type="text/javascript">
    $(document).ready( function(){
      __page_leave_confirmation('#product_add_form');

      $('#set_current_stock_form').on('submit', function (e) {
        e.preventDefault();
        runSetCurrentStock();
      });

      $('#btn_set_current_stock').on('click', function (e) {
        e.preventDefault();
        runSetCurrentStock();
      });

      function runSetCurrentStock() {
        var $form = $('#set_current_stock_form');
        var $btn = $('#btn_set_current_stock');
        var currentStock = {};
        $form.find('input[name^="current_stock["]').each(function () {
          var name = $(this).attr('name');
          var match = name.match(/current_stock\[(\d+)\]\[(\d+)\]/);
          if (match) {
            var locId = match[1], varId = match[2], val = $(this).val();
            if (typeof currentStock[locId] === 'undefined') currentStock[locId] = {};
            currentStock[locId][varId] = val;
          }
        });
        var payload = {
          _token: $form.find('input[name="_token"]').val(),
          current_stock: currentStock
        };
        $btn.prop('disabled', true);
        $.ajax({
          url: $form.attr('action'),
          type: 'POST',
          contentType: 'application/json',
          data: JSON.stringify(payload),
          success: function (data) {
            if (data.success) {
              toastr.success(data.msg + (data.updated_count ? ' (' + data.updated_count + ')' : ''));
              var stockToApply = data.saved_stock || currentStock;
              $.each(stockToApply, function (locId, vars) {
                $.each(vars, function (varId, val) {
                  var $input = $form.find('input[name="current_stock[' + locId + '][' + varId + ']"]');
                  if ($input.length) {
                    $input.val(val);
                    $input.closest('tr').find('td').eq(-2).text(val);
                  }
                });
              });
            } else {
              toastr.error(data.msg || '{{ __("messages.something_went_wrong") }}');
            }
          },
          error: function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.msg) ? xhr.responseJSON.msg : '{{ __("messages.something_went_wrong") }}';
            toastr.error(msg);
          },
          complete: function () {
            $btn.prop('disabled', false);
          }
        });
      }
    });
  </script>
@endsection