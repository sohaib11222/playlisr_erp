@extends('layouts.app')
@section('title', __('product.add_new_product'))

@section('content')

    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1>@lang('product.add_new_product')</h1>
        <!-- <ol class="breadcrumb">
            <li><a href="#"><i class="fa fa-dashboard"></i> Level</a></li>
            <li class="active">Here</li>
        </ol> -->
    </section>

    <!-- Main content -->
    <section class="content">
        @php
            $form_class = empty($duplicate_product) ? 'create' : '';
        @endphp
        {!! Form::open(['url' => action('ProductController@store'), 'method' => 'post',
            'id' => 'product_add_form','class' => 'product_form ' . $form_class, 'files' => true ]) !!}
        @component('components.widget', ['class' => 'box-primary'])
            <div class="row">
                <div class="col-sm-12">
                    <div class="form-group">
                        {!! Form::label('name', __('product.product_name') . ':*') !!}
                        {!! Form::text('name', !empty($duplicate_product->name) ? $duplicate_product->name : null, ['class' => 'form-control', 'required',
                        'placeholder' => __('product.product_name')]); !!}
                    </div>
                </div>

                <div class="col-sm-12">
                    <div class="form-group">
                        {!! Form::label('sku', __('product.sku') . ':') !!} @show_tooltip(__('tooltip.sku'))
                        {!! Form::text('sku', null, ['class' => 'form-control',
                          'placeholder' => __('product.sku')]); !!}
                    </div>
                </div>
                <div class="col-sm-12" style="display: none">
                    <div class="form-group">
                        {!! Form::label('barcode_type', __('product.barcode_type') . ':*') !!}
                        {!! Form::select('barcode_type', $barcode_types, !empty($duplicate_product->barcode_type) ? $duplicate_product->barcode_type : $barcode_default, ['class' => 'form-control select2', 'required']); !!}
                    </div>
                </div>

                <div class="clearfix"></div>
                <div class="col-sm-12" style="display: none">
                    <div class="form-group">
                        <label for="unit_id">{{ __('product.unit') }}:*</label>
                        <div class="input-group">
                            <select name="unit_id" id="unit_id" class="form-control select2" required>
                                @foreach($units as $key => $unit)
                                    <option value="1" selected>
                                        {{ $unit }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>

                <div class="col-sm-12 @if(!session('business.enable_brand')) hide @endif">
                    <div class="form-group">
                        {!! Form::label('brand_id', __('product.brand') . ':') !!}
                        <div class="input-group">
                            {!! Form::select('brand_id', $brands, !empty($duplicate_product->brand_id) ? $duplicate_product->brand_id : null, ['placeholder' => __('messages.please_select'), 'class' => 'form-control select2']); !!}
                            <span class="input-group-btn">
                                <button
                                        type="button" @if(!auth()->user()->can('brand.create')) disabled @endif
                                        class="btn btn-default bg-white btn-flat btn-modal"
                                        data-href="{{action('BrandController@create', ['quick_add' => true])}}"
                                        title="@lang('brand.add_brand')"
                                        data-container=".view_modal">
                                    <i class="fa fa-plus-circle text-primary fa-lg"></i>
                                </button>
                            </span>
                        </div>
                    </div>
                </div>

                <div class="col-sm-12">
                    <div class="form-group">
                        {!! Form::label('artist', 'Artist' . ':') !!}
                        {!! Form::text('artist', !empty($duplicate_product->artist) ? $duplicate_product->artist : null, ['class' => 'form-control',
                        'placeholder' => 'Artist']); !!}
                    </div>
                </div>

                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('bin_position', 'Bin Position' . ':') !!}
                        {!! Form::text('bin_position', !empty($duplicate_product->bin_position) ? $duplicate_product->bin_position : null, ['class' => 'form-control',
                        'placeholder' => 'e.g., A-12, B-5']); !!}
                    </div>
                </div>

                <div class="col-sm-6">
                    <div class="form-group">
                        {!! Form::label('listing_location', 'Listing Location' . ':') !!}
                        {!! Form::text('listing_location', !empty($duplicate_product->listing_location) ? $duplicate_product->listing_location : null, ['class' => 'form-control',
                        'placeholder' => 'e.g., Warehouse A, Storage B']); !!}
                        <p class="help-block">Location for eBay/Discogs listings (separate from store locations)</p>
                    </div>
                </div>

                <div class="col-sm-12 @if(!session('business.enable_category')) hide @endif">
                    <div class="form-group">
                        {!! Form::label('category_combo', __('product.category') . ' / ' . __('product.sub_category') . ' *:') !!}
                        @php
                            $selectedCategoryId = !empty($duplicate_product) ? $duplicate_product->category_id : null;
                            $selectedSubCategoryId = !empty($duplicate_product) ? $duplicate_product->sub_category_id : null;
                            $categoryCombos = isset($category_combos) ? $category_combos : [];
                            $selectedComboId = null;
                            if (!empty($selectedCategoryId)) {
                                $subIdForCombo = !empty($selectedSubCategoryId) ? (int)$selectedSubCategoryId : 0;
                                $selectedComboId = $selectedCategoryId . '_' . $subIdForCombo;
                            }
                        @endphp
                        <select name="category_combo" id="category_combo" class="form-control select2">
                            <option value="">{{ __('messages.please_select') }}</option>
                            @foreach($categoryCombos as $combo)
                                @php
                                    $isSelected = !empty($selectedComboId) && $combo['id'] == $selectedComboId;
                                @endphp
                                <option value="{{ $combo['id'] }}"
                                        data-category-id="{{ $combo['category_id'] }}"
                                        data-sub-category-id="{{ isset($combo['sub_category_id']) ? $combo['sub_category_id'] : '' }}"
                                        @if($isSelected) selected @endif>
                                    {{ $combo['label'] }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    {{-- Hidden fields actually submitted to backend --}}
                    {!! Form::hidden('category_id', !empty($duplicate_product->category_id) ? $duplicate_product->category_id : null, ['id' => 'category_id']) !!}
                    {!! Form::hidden('sub_category_id', !empty($duplicate_product->sub_category_id) ? $duplicate_product->sub_category_id : null, ['id' => 'sub_category_id']) !!}
                </div>

                @php
                    $default_location = null;
                    if(count($business_locations) == 1){
                      $default_location = array_key_first($business_locations->toArray());
                    }
                @endphp
                <div class="col-sm-12">
                    <div class="form-group">
                        {!! Form::label('product_locations', __('business.business_locations') . ':') !!} @show_tooltip(__('lang_v1.product_location_help'))
                        {!! Form::select('product_locations[]', $business_locations, $default_location, ['class' => 'form-control select2', 'multiple', 'id' => 'product_locations']); !!}
                    </div>
                </div>


                <div class="col-sm-12">
                    <div class="form-group">
                        {!! Form::label('product_custom_field1', 'Image Url') !!}
                        {!! Form::text('product_custom_field1', !empty($duplicate_product->product_custom_field1) ? $duplicate_product->product_custom_field1 : null, ['class' => 'form-control',
                        'placeholder' => 'Image Url']); !!}
                    </div>
                </div>


                <div class="clearfix"></div>

                <div class="col-sm-12">
                    <div class="form-group">
                        <br>
                        <label>
                            {!! Form::checkbox('enable_stock', 1, !empty($duplicate_product) ? $duplicate_product->enable_stock : true, ['class' => 'input-icheck', 'id' => 'enable_stock']); !!} <strong>@lang('product.manage_stock')</strong>
                        </label>@show_tooltip(__('tooltip.enable_stock')) <p class="help-block"><i>@lang('product.enable_stock_help')</i></p>
                    </div>
                </div>

                <!-- include module fields -->
                @if(!empty($pos_module_data))
                    @foreach($pos_module_data as $key => $value)
                        @if(!empty($value['view_path']))
                            @includeIf($value['view_path'], ['view_data' => $value['view_data']])
                        @endif
                    @endforeach
                @endif
                <div class="clearfix"></div>
                <div class="col-sm-12">
                    <div class="form-group">
                        {!! Form::label('product_description', __('lang_v1.product_description') . ':') !!}
                        {!! Form::textarea('product_description', !empty($duplicate_product->product_description) ? $duplicate_product->product_description : null, ['class' => 'form-control']); !!}
                    </div>
                </div>
                <div class="col-sm-12">
                    <div class="form-group">
                        {!! Form::label('image', __('lang_v1.product_image') . ':') !!}
                        {!! Form::file('image', ['id' => 'upload_image', 'accept' => 'image/*']); !!}
                        <small><p class="help-block">@lang('purchase.max_file_size', ['size' => (config('constants.document_size_limit') / 1000000)]) <br> @lang('lang_v1.aspect_ratio_should_be_1_1')</p></small>
                    </div>
                </div>
            </div>

        @endcomponent



        @component('components.widget', ['class' => 'box-primary'])
            <div class="row">

                <div class="col-sm-4 @if(!session('business.enable_price_tax')) hide @endif">
                    <div class="form-group">
                        <label for="tax_type">{{ __('product.selling_price_tax_type') }}:*</label>
                        <select name="tax_type" id="tax_type" class="form-control select2" required>
                            <option value="inclusive" {{ (!empty($duplicate_product->tax_type) && $duplicate_product->tax_type == 'inclusive') ? 'selected' : '' }}>
                                {{ __('product.inclusive') }}
                            </option>
                            <option value="exclusive" {{ (empty($duplicate_product->tax_type) || $duplicate_product->tax_type == 'exclusive') ? 'selected' : '' }}>
                                {{ __('product.exclusive') }}
                            </option>
                        </select>
                    </div>
                </div>
                
                <div class="col-sm-4 @if(!session('business.enable_price_tax')) hide @endif">
                    <div class="form-group">
                        <br>
                        <label>
                            {!! Form::checkbox('tax_exempt', 1, !empty($duplicate_product) && !empty($duplicate_product->tax_exempt) ? $duplicate_product->tax_exempt : false, ['class' => 'input-icheck']); !!} 
                            <strong>Tax Exempt</strong>
                        </label>
                        <p class="help-block">Check if this product is exempt from sales tax</p>
                    </div>
                </div>


                <div class="clearfix"></div>

                <div class="col-sm-4">
                    <div class="form-group">
                        {!! Form::label('type', __('product.product_type') . ':*') !!} @show_tooltip(__('tooltip.product_type'))
                        {!! Form::select('type', $product_types, !empty($duplicate_product->type) ? $duplicate_product->type : null, ['class' => 'form-control select2',
                        'required', 'data-action' => !empty($duplicate_product) ? 'duplicate' : 'add', 'data-product_id' => !empty($duplicate_product) ? $duplicate_product->id : '0']); !!}
                    </div>
                </div>

                <div class="form-group col-sm-12" id="product_form_part">
                    @include('product.partials.single_product_form_part', ['profit_percent' => $default_profit_percent])
                </div>

                <input type="hidden" id="variation_counter" value="1">
                <input type="hidden" id="default_profit_percent"
                       value="{{ $default_profit_percent }}">

            </div>
        @endcomponent
        <div class="row">
            <div class="col-sm-12">
                <input type="hidden" name="submit_type" id="submit_type">
                <div class="text-center">
                    <div class="btn-group">
                        @if($selling_price_group_count)
                            <button type="submit" value="submit_n_add_selling_prices" class="btn btn-warning submit_product_form">@lang('lang_v1.save_n_add_selling_price_group_prices')</button>
                        @endif

                        @can('product.opening_stock')
                            <button id="opening_stock_button" @if(!empty($duplicate_product) && $duplicate_product->enable_stock == 0) disabled @endif type="submit" value="submit_n_add_opening_stock" class="btn bg-purple submit_product_form">@lang('lang_v1.save_n_add_opening_stock')</button>
                        @endcan

                        <button type="submit" value="save_n_add_another" class="btn bg-maroon submit_product_form">@lang('lang_v1.save_n_add_another')</button>

                        <button type="submit" value="submit" class="btn btn-primary submit_product_form">@lang('messages.save')</button>
                    </div>

                </div>
            </div>
        </div>
        {!! Form::close() !!}

    </section>
    <!-- /.content -->

@endsection

@section('javascript')
    @php $asset_v = env('APP_VERSION'); @endphp
    <script src="{{ asset('js/product.js?v=' . $asset_v) }}"></script>

    <script type="text/javascript">
        $(document).ready(function(){
            function tokenizeCategoryComboQuery(text) {
                if (text === undefined || text === null) return [];
                return String(text).toLowerCase().trim().split(/[^a-z0-9]+/g).filter(Boolean);
            }

            function categoryComboMatcher(params, data) {
                if (!data || !data.text) return data;
                var term = params && params.term ? String(params.term).toLowerCase().trim() : '';
                if (!term) return data;

                var label = String(data.text || '').toLowerCase();
                var words = label.match(/[a-z0-9]+/g) || [];
                var tokens = tokenizeCategoryComboQuery(term);
                if (!tokens.length) return data;

                var ok = tokens.every(function(tok) {
                    return label.indexOf(tok) !== -1 || words.some(function(w) { return w.indexOf(tok) === 0; });
                });
                return ok ? data : null;
            }

            function ensureCategoryComboMatcher() {
                var $combo = $('#category_combo');
                if (!$combo.length) return;
                var current = $combo.val();
                try {
                    if ($combo.data('select2')) {
                        $combo.select2('destroy');
                    }
                } catch (e) {}
                $combo.select2({ matcher: categoryComboMatcher });
                if (current !== undefined && current !== null && current !== '') {
                    $combo.val(current).trigger('change.select2');
                }
            }

            // Apply now and once more after other initializers run.
            ensureCategoryComboMatcher();
            setTimeout(ensureCategoryComboMatcher, 0);

            __page_leave_confirmation('#product_add_form');
            onScan.attachTo(document, {
                suffixKeyCodes: [13], // enter-key expected at the end of a scan
                reactToPaste: true, // Compatibility to built-in scanners in paste-mode (as opposed to keyboard-mode)
                onScan: function(sCode, iQty) {
                    $('input#sku').val(sCode);
                },
                onScanError: function(oDebug) {
                    console.log(oDebug);
                },
                minLength: 2,
                ignoreIfFocusOn: ['input', '.form-control']
                // onKeyDetect: function(iKeyCode){ // output all potentially relevant key events - great for debugging!
                //     console.log('Pressed: ' + iKeyCode);
                // }
            });
        });
    </script>
@endsection