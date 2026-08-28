@extends('layouts.app')
@section('title', __('product.add_new_product'))

@section('content')

<div class="product-add-v2">

<style>
/* ============ PRODUCT ADD V2 ============
   Deliberately near-monochrome: one neutral palette, one accent color
   (maroon) reserved for the single primary action. Flat surfaces, no
   shadows, no per-cell table borders, no decorative icons — the goal
   is calm, not "polished dashboard." */
.product-add-v2 {
    --pe-bg:          #FAFAF8;
    --pe-surface:     #FFFFFF;
    --pe-surface-2:   #F4F3F1;
    --pe-ink:         #1A1A1A;
    --pe-ink-2:       #5C5C5C;
    --pe-ink-3:       #8A8A8A;
    --pe-line:        #E4E2DE;
    --pe-line-2:      #D6D3CD;
    --pe-accent-deep: #8A3A2E;
    --pe-accent-soft: #F5E9E6;
    --pe-radius:      8px;
    --pe-radius-sm:   6px;
    --pe-form-width:  100%;

    background: var(--pe-bg);
    font-family: "Inter Tight", system-ui, sans-serif;
    color: var(--pe-ink);
    -webkit-font-smoothing: antialiased;
    padding: 20px 24px 32px;
    min-height: calc(100vh - 50px);
}
/* Decorative-only icons (tooltip "i" bubbles) add visual noise without
   adding information a plain label + placeholder doesn't already give. */
.product-add-v2 .fa-info-circle.hover-q { display: none !important; }
/* Bootstrap's float grid lets columns drift to different heights when one
   field has an extra tooltip icon or help line — the next row then starts
   lower on one side than the other, leaving ragged gaps. Flex + wrap keeps
   each visual row's columns the same height so rows stay level. */
.product-add-v2 .row { display: flex; flex-wrap: wrap; margin-left: -6px; margin-right: -6px; }
.product-add-v2 [class*="col-"] { padding-left: 6px; padding-right: 6px; }

.product-add-v2 .pe-breadcrumb {
    max-width: var(--pe-form-width);
    font-size: 12.5px;
    color: var(--pe-ink-3);
    margin: 0 0 8px;
}
.product-add-v2 .pe-breadcrumb a { color: var(--pe-ink-2); text-decoration: none; }
.product-add-v2 .pe-breadcrumb a:hover { color: var(--pe-ink); text-decoration: underline; }
.product-add-v2 .pe-breadcrumb span.sep { margin: 0 6px; }
.product-add-v2 .pe-breadcrumb span.current { color: var(--pe-ink); font-weight: 600; }

.product-add-v2 .pe-topbar {
    max-width: var(--pe-form-width);
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin: 0 0 20px;
    flex-wrap: wrap;
}
.product-add-v2 .pe-topbar h1 {
    font-family: inherit;
    font-size: 26px;
    font-weight: 800;
    letter-spacing: -0.02em;
    margin: 0;
    color: var(--pe-ink);
}
.product-add-v2 .pe-subtitle {
    margin: 4px 0 0;
    font-size: 13.5px;
    color: var(--pe-ink-2);
}
.product-add-v2 .pe-topbar-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.product-add-v2 .pe-card {
    background: var(--pe-surface);
    border: 1px solid var(--pe-line);
    border-radius: var(--pe-radius);
    padding: 18px 20px;
    margin-bottom: 12px;
}
.product-add-v2 .pe-card-wide { max-width: var(--pe-form-width); }
.product-add-v2 .pe-card-row {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    max-width: var(--pe-form-width);
}
.product-add-v2 .pe-card-row .pe-card { margin-bottom: 0; }
.product-add-v2 .pe-card-row .pe-card-pricing { flex: 1 1 62%; }
.product-add-v2 .pe-card-row .pe-card-inventory { flex: 1 1 34%; }

.product-add-v2 .pe-card-title {
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: .06em;
    font-weight: 700;
    color: var(--pe-ink);
    margin: 0 0 10px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--pe-line);
}
.product-add-v2 .form-group { margin-bottom: 14px; }
.product-add-v2 .pe-more-toggle {
    background: none;
    border: none;
    padding: 0;
    font-family: inherit;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--pe-ink-2);
    cursor: pointer;
    margin: -6px 0 4px;
}
.product-add-v2 .pe-more-toggle:hover { color: var(--pe-ink); text-decoration: underline; }
.product-add-v2 .select2-container {
    width: 100% !important;
}
/* Card 1's fields sit in a wide column on a big monitor — keep each input a
   normal reading width instead of stretching it edge to edge. Full-width
   controls (textarea, the multi-select, table inputs) opt back out below. */
.product-add-v2 .form-control,
.product-add-v2 .select2-container {
    max-width: 460px;
}
.product-add-v2 textarea.form-control,
.product-add-v2 select[id="product_locations"] + .select2-container,
.product-add-v2 .add-product-price-table .form-control,
.product-add-v2 .pe-image-panel .form-control {
    max-width: none;
}
.product-add-v2 label,
.product-add-v2 .form-group label {
    display: block;
    font-size: 13px;
    text-transform: none;
    letter-spacing: normal;
    font-weight: 600;
    color: var(--pe-ink);
    margin-bottom: 4px;
}
.product-add-v2 .form-control,
.product-add-v2 .select2-selection--single {
    border: 1px solid var(--pe-line-2) !important;
    border-radius: var(--pe-radius-sm) !important;
    background: var(--pe-surface) !important;
    color: var(--pe-ink) !important;
    min-height: 38px !important;
    height: 38px !important;
    padding: 8px 12px !important;
    font-family: inherit !important;
    font-size: 14px !important;
    box-shadow: none !important;
}
.product-add-v2 .select2-selection--multiple {
    border: 1px solid var(--pe-line-2) !important;
    border-radius: var(--pe-radius-sm) !important;
    background: var(--pe-surface) !important;
    color: var(--pe-ink) !important;
    min-height: 38px !important;
    height: auto !important;
    padding: 4px 6px !important;
    font-family: inherit !important;
    font-size: 14px !important;
    box-shadow: none !important;
}
/* Selected-location chips default to Bootstrap blue — keep them in the
   same neutral palette as everything else. */
.product-add-v2 .select2-selection__choice {
    background: var(--pe-surface-2) !important;
    border: 1px solid var(--pe-line-2) !important;
    color: var(--pe-ink) !important;
    border-radius: 4px !important;
}
.product-add-v2 .select2-selection__choice__remove { color: var(--pe-ink-2) !important; }
.product-add-v2 textarea.form-control { height: auto !important; min-height: 90px !important; max-width: none; }
.product-add-v2 .select2-selection--single .select2-selection__rendered { line-height: 22px !important; padding: 0 !important; color: var(--pe-ink) !important; }
.product-add-v2 .select2-selection--single .select2-selection__arrow { height: 36px !important; }
.product-add-v2 ::placeholder { color: #9A9084 !important; opacity: 1 !important; }
.product-add-v2 .help-block { font-size: 12.5px; color: var(--pe-ink-2); margin-top: 4px; }
.product-add-v2 .form-control:focus,
.product-add-v2 .select2-selection--single:focus,
.product-add-v2 .select2-container--focus .select2-selection {
    border-color: var(--pe-accent-deep) !important;
    box-shadow: 0 0 0 3px var(--pe-accent-soft) !important;
    outline: none !important;
}

.product-add-v2 .btn {
    font-family: inherit;
    font-weight: 600;
    border-radius: var(--pe-radius-sm);
    padding: 9px 16px;
    border: 1px solid var(--pe-line-2);
    background: var(--pe-surface);
    color: var(--pe-ink);
    transition: background .15s, border-color .15s, transform .05s;
}
.product-add-v2 .btn:hover { background: var(--pe-surface-2); }
.product-add-v2 .btn:active { transform: translateY(1px); }
.product-add-v2 .btn-primary {
    background: var(--pe-ink);
    border-color: var(--pe-ink);
    color: var(--pe-bg);
}
.product-add-v2 .btn-primary:hover { background: #2C2620; border-color: #2C2620; color: var(--pe-bg); }
/* One accent color total, reserved for the single primary "Save" action.
   Every other button (including the other two save variants) stays plain
   outline so nothing else competes for attention. */
.product-add-v2 .bg-purple,
.product-add-v2 .btn-warning {
    background: var(--pe-surface) !important;
    color: var(--pe-ink) !important;
    border-color: var(--pe-line-2) !important;
}
.product-add-v2 .bg-maroon {
    background: var(--pe-accent-deep) !important;
    color: #fff !important;
    border-color: var(--pe-accent-deep) !important;
}

/* ---- Product image panel (right column of card 1) ---- */
/* No dropzone box or icon badge — just a plain upload control, same
   visual weight as any other field. */
.product-add-v2 .pe-image-panel {
    padding: 0;
}
.product-add-v2 .pe-image-panel .pe-image-hint { font-size: 12px; color: var(--pe-ink-3); margin: 0 0 8px; }
/* Hide the krajee caption text box ("No file chosen") — redundant with
   the plain "Browse.." button beneath it. */
.product-add-v2 .pe-image-panel .kv-fileinput-caption { display: none !important; }
.product-add-v2 .pe-image-panel .file-caption-main { display: block !important; }
.product-add-v2 .pe-image-panel .input-group-btn { display: block !important; width: 100%; }
.product-add-v2 .pe-image-panel .btn-file {
    display: block !important;
    width: 100%;
    max-width: 200px;
    text-align: center;
    background: var(--pe-surface) !important;
    color: var(--pe-ink) !important;
    border: 1px solid var(--pe-line-2) !important;
    border-radius: var(--pe-radius-sm) !important;
    font-weight: 600;
    padding: 8px 14px;
}
.product-add-v2 .pe-image-panel .file-preview { margin-top: 10px; }

/* Price "table" reads as a plain row of labeled fields, not a grid —
   one rule under the header, no cell borders, no filled header band. */
.product-add-v2 .add-product-price-table {
    width: 100%;
    border-collapse: collapse;
}
.product-add-v2 .add-product-price-table > tbody > tr > th,
.product-add-v2 .add-product-price-table > thead > tr > th {
    background: transparent;
    color: var(--pe-ink-2);
    border: none;
    border-bottom: 1px solid var(--pe-line);
    text-transform: none;
    letter-spacing: normal;
    font-size: 12.5px;
    font-weight: 600;
    padding: 0 12px 8px 0;
}
.product-add-v2 .add-product-price-table > tbody > tr > td {
    border: none;
    padding: 10px 12px 0 0;
    background: transparent;
}
.product-add-v2 #margin_display {
    margin-top: 10px;
    font-size: 13px;
    color: var(--pe-ink-2);
}
.product-add-v2 #margin_display strong { color: var(--pe-ink); }
</style>

@php
    $form_class = empty($duplicate_product) ? 'create' : '';
@endphp

<div class="pe-breadcrumb">
    <a href="{{ route('products.index') }}">Products</a>
    <span class="sep">/</span>
    <span class="current">Add Product</span>
</div>

<div class="pe-topbar">
    <div>
        <h1>@lang('product.add_new_product')</h1>
        <p class="pe-subtitle">Create a new product and set pricing, tax, and inventory details.</p>
    </div>
    <div class="pe-topbar-actions">
        <input type="hidden" name="submit_type" id="submit_type" form="product_add_form">

        <button type="submit" form="product_add_form" value="save_n_add_another" class="btn submit_product_form">@lang('lang_v1.save_n_add_another')</button>

        @if($selling_price_group_count)
            <button type="submit" form="product_add_form" value="submit_n_add_selling_prices" class="btn btn-warning submit_product_form">@lang('lang_v1.save_n_add_selling_price_group_prices')</button>
        @endif

        @can('product.opening_stock')
            <button id="opening_stock_button" form="product_add_form" @if(!empty($duplicate_product) && $duplicate_product->enable_stock == 0) disabled @endif type="submit" value="submit_n_add_opening_stock" class="btn bg-purple submit_product_form">@lang('lang_v1.save_n_add_opening_stock')</button>
        @endcan

        <button type="submit" form="product_add_form" value="submit" class="btn bg-maroon submit_product_form">@lang('messages.save')</button>
    </div>
</div>

{!! Form::open(['url' => action('ProductController@store'), 'method' => 'post',
    'id' => 'product_add_form','class' => 'product_form ' . $form_class, 'files' => true ]) !!}

    {{-- ─── Card 1: Identity ──────────────────────────────────────────── --}}
    <div class="pe-card pe-card-wide">
        <h3 class="pe-card-title">Product Information</h3>
        <div class="row">
            <div class="col-sm-8">
                <div class="row">
                    <div class="col-sm-12">
                        <div class="form-group">
                            {!! Form::label('name', __('product.product_name') . ':*') !!}
                            {!! Form::text('name', old('name', !empty($duplicate_product->name) ? $duplicate_product->name : null), ['class' => 'form-control title-autocomplete-input', 'required',
                            'placeholder' => __('product.product_name')]) !!}
                        </div>
                    </div>

                    <div class="col-sm-12">
                        {{-- Real-time duplicate warning: populated by the live check in the script below. --}}
                        <div id="duplicate_warning" class="alert alert-warning" style="display: none; border-left: 4px solid #f0ad4e;">
                            <strong><i class="fa fa-exclamation-triangle"></i> Possible duplicate.</strong>
                            <span id="duplicate_warning_msg"></span>
                            <a id="duplicate_warning_link" href="#" target="_blank" class="btn btn-xs btn-primary" style="margin-left:6px;">Open existing product</a>
                        </div>
                    </div>

                    <div class="col-sm-12" style="display: none">
                        {!! Form::label('barcode_type', __('product.barcode_type') . ':*') !!}
                        {!! Form::select('barcode_type', $barcode_types, !empty($duplicate_product->barcode_type) ? $duplicate_product->barcode_type : $barcode_default, ['class' => 'form-control select2', 'required']) !!}
                    </div>

                    <div class="col-sm-12" style="display: none">
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

                    <div class="col-sm-6">
                        <div class="form-group">
                            {!! Form::label('sku', __('product.sku') . ':') !!} @show_tooltip(__('tooltip.sku'))
                            {!! Form::text('sku', old('sku'), ['class' => 'form-control',
                              'placeholder' => __('product.sku')]) !!}
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            {!! Form::label('artist', 'Artist' . ':') !!}
                            {!! Form::text('artist', old('artist', !empty($duplicate_product->artist) ? $duplicate_product->artist : null), ['class' => 'form-control artist-autocomplete-input',
                            'placeholder' => 'Artist']) !!}
                        </div>
                    </div>

                    <div class="col-sm-6 @if(!session('business.enable_category')) hide @endif">
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

                    <div class="col-sm-6 @if(!session('business.enable_brand')) hide @endif">
                        <div class="form-group">
                            {!! Form::label('brand_id', __('product.brand') . ':') !!}
                            <div class="input-group">
                                {!! Form::select('brand_id', $brands, !empty($duplicate_product->brand_id) ? $duplicate_product->brand_id : null, ['placeholder' => __('messages.please_select'), 'class' => 'form-control select2']) !!}
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

                    @php
                        $default_location = null;
                        if(count($business_locations) == 1){
                          $default_location = array_key_first($business_locations->toArray());
                        }
                    @endphp
                    <div class="col-sm-6">
                        <div class="form-group">
                            {!! Form::label('product_locations', __('business.business_locations') . ':') !!}
                            {!! Form::select('product_locations[]', $business_locations, $default_location, ['class' => 'form-control select2', 'multiple', 'id' => 'product_locations', 'data-placeholder' => 'Select location(s)']) !!}
                        </div>
                    </div>

                    <div class="col-sm-6">
                        <div class="form-group">
                            {!! Form::label('product_custom_field2', 'Street / Release Date') !!}
                            {!! Form::date('product_custom_field2', !empty($duplicate_product->product_custom_field2) ? $duplicate_product->product_custom_field2 : null, ['class' => 'form-control']) !!}
                            <p class="help-block">A future date makes this a preorder on the website until then. Leave blank for a regular in-stock item.</p>
                        </div>
                    </div>

                    <div class="col-sm-12">
                        <button type="button" id="more_fields_toggle" class="pe-more-toggle">+ Bin position, listing location, image URL</button>
                    </div>

                    <div class="col-sm-6 pe-more-field" style="display:none">
                        <div class="form-group">
                            {!! Form::label('bin_position', 'Bin Position' . ':') !!}
                            {!! Form::text('bin_position', !empty($duplicate_product->bin_position) ? $duplicate_product->bin_position : null, ['class' => 'form-control',
                            'placeholder' => 'A-12, B-5']) !!}
                        </div>
                    </div>

                    <div class="col-sm-6 pe-more-field" style="display:none">
                        <div class="form-group">
                            {!! Form::label('listing_location', 'Listing Location' . ':') !!}
                            {!! Form::text('listing_location', !empty($duplicate_product->listing_location) ? $duplicate_product->listing_location : null, ['class' => 'form-control',
                            'placeholder' => 'Warehouse A, Storage B']) !!}
                            <p class="help-block">For eBay/Discogs listings</p>
                        </div>
                    </div>

                    <div class="col-sm-6 pe-more-field" style="display:none">
                        <div class="form-group">
                            {!! Form::label('product_custom_field1', 'Image URL') !!}
                            {!! Form::text('product_custom_field1', !empty($duplicate_product->product_custom_field1) ? $duplicate_product->product_custom_field1 : null, ['class' => 'form-control',
                            'placeholder' => 'Image URL']) !!}
                        </div>
                    </div>

                    {{-- include module fields --}}
                    @if(!empty($pos_module_data))
                        <div class="col-sm-12">
                            @foreach($pos_module_data as $key => $value)
                                @if(!empty($value['view_path']))
                                    @includeIf($value['view_path'], ['view_data' => $value['view_data']])
                                @endif
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            <div class="col-sm-4">
                <label>@lang('lang_v1.product_image'):</label>
                <div class="pe-image-panel">
                    <p class="pe-image-hint">@lang('purchase.max_file_size', ['size' => (config('constants.document_size_limit') / 1000000)]) &middot; @lang('lang_v1.aspect_ratio_should_be_1_1')</p>
                    {!! Form::file('image', ['id' => 'upload_image', 'accept' => 'image/*']) !!}
                </div>
            </div>
        </div>
    </div>

    {{-- ─── Card 2: Pricing & Tax + Inventory Options (side by side) ──── --}}
    <div class="pe-card-row">
        <div class="pe-card pe-card-pricing">
            <h3 class="pe-card-title">Pricing &amp; Tax</h3>
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
                        <label style="display:block; margin-bottom:6px;">
                            {!! Form::checkbox('tax_exempt', 1, !empty($duplicate_product) && !empty($duplicate_product->tax_exempt) ? $duplicate_product->tax_exempt : false, ['class' => 'input-icheck']) !!}
                            <strong style="text-transform:none; letter-spacing:normal; font-size:13px;">Tax Exempt</strong>
                        </label>
                        <p class="help-block">Check if this product is exempt from sales tax</p>
                    </div>
                </div>

                <div class="col-sm-4">
                    <div class="form-group">
                        {!! Form::label('type', __('product.product_type') . ':*') !!} @show_tooltip(__('tooltip.product_type'))
                        {!! Form::select('type', $product_types, !empty($duplicate_product->type) ? $duplicate_product->type : null, ['class' => 'form-control select2',
                        'required', 'data-action' => !empty($duplicate_product) ? 'duplicate' : 'add', 'data-product_id' => !empty($duplicate_product) ? $duplicate_product->id : '0']) !!}
                    </div>
                </div>

                <div class="form-group col-sm-12" id="product_form_part">
                    @include('product.partials.single_product_form_part', ['profit_percent' => $default_profit_percent])
                </div>

                <div class="col-sm-12">
                    <p id="margin_display">Margin: <strong id="margin_display_value">&mdash;</strong></p>
                </div>

                <input type="hidden" id="variation_counter" value="1">
                <input type="hidden" id="default_profit_percent"
                       value="{{ $default_profit_percent }}">
            </div>
        </div>

        <div class="pe-card pe-card-inventory">
            <h3 class="pe-card-title">Inventory Options</h3>
            <div class="form-group">
                <label style="display:block; margin-bottom:6px;">
                    {!! Form::checkbox('enable_stock', 1, !empty($duplicate_product) ? $duplicate_product->enable_stock : true, ['class' => 'input-icheck', 'id' => 'enable_stock']) !!}
                    <strong style="text-transform:none; letter-spacing:normal; font-size:13px;">@lang('product.manage_stock')</strong>
                </label>@show_tooltip(__('tooltip.enable_stock'))
                <p class="help-block"><i>@lang('product.enable_stock_help')</i></p>
            </div>

            <div class="form-group">
                {!! Form::label('alert_quantity', __('product.alert_quantity') . ':') !!} @show_tooltip(__('tooltip.alert_quantity'))
                {!! Form::text('alert_quantity', !empty($duplicate_product->alert_quantity) ? $duplicate_product->alert_quantity : null, ['class' => 'form-control input_number',
                'placeholder' => 'e.g. 5']) !!}
                <p class="help-block">Shows on the dashboard's Product Stock Alert list when stock is at or below this.</p>
            </div>
        </div>
    </div>

{!! Form::close() !!}

    {{-- ─── Card 3: Description ───────────────────────────────────────── --}}
    <div class="pe-card pe-card-wide">
        <h3 class="pe-card-title">@lang('lang_v1.product_description')</h3>
        {{-- Sits outside the main form but submits with it via the HTML5 form attr. --}}
        {!! Form::textarea('product_description', !empty($duplicate_product->product_description) ? $duplicate_product->product_description : null, ['class' => 'form-control', 'form' => 'product_add_form']) !!}
        <p class="help-block">Also shown as the product description on nivessa.com.</p>
    </div>

</div>{{-- /.product-add-v2 --}}

@endsection

@section('javascript')
    @php $asset_v = env('APP_VERSION'); @endphp
    <script src="{{ asset('js/product.js?v=' . $asset_v) }}"></script>

    <script type="text/javascript">
        $(document).ready(function(){
            // ---- Duplicate-product guard -------------------------------------
            // Warn (and block save) the moment the name/artist/barcode being
            // entered matches an existing active product. Server-side store()
            // has the same check as a backstop; this just catches it early.
            (function initDuplicateGuard() {
                var $form = $('#product_add_form');
                if (!$form.length) return;
                var $warning = $('#duplicate_warning');
                var $msg = $('#duplicate_warning_msg');
                var $link = $('#duplicate_warning_link');
                var hasDuplicate = false;
                var timer = null;

                function clearWarning() {
                    hasDuplicate = false;
                    $warning.hide();
                }

                function runCheck() {
                    var sku = $.trim($('#sku').val() || '');
                    if (sku === '') { clearWarning(); return; }

                    $.ajax({
                        url: '{{ route('products.checkDuplicate') }}',
                        type: 'POST',
                        data: {
                            _token: '{{ csrf_token() }}',
                            sku: sku
                        },
                        dataType: 'json'
                    }).done(function(res) {
                        if (res && res.duplicate) {
                            hasDuplicate = true;
                            $msg.text(' ' + res.msg);
                            if (res.product && res.product.url) {
                                $link.attr('href', res.product.url).show();
                            } else {
                                $link.hide();
                            }
                            $warning.show();
                        } else {
                            clearWarning();
                        }
                    }).fail(function() { clearWarning(); });
                }

                function schedule() {
                    if (timer) clearTimeout(timer);
                    timer = setTimeout(runCheck, 400);
                }

                $('#sku').on('input change blur', schedule);

                // Block save while a duplicate is flagged. Users should add stock
                // to the existing product instead of creating a second record.
                $form.on('submit', function(e) {
                    if (hasDuplicate) {
                        e.preventDefault();
                        e.stopImmediatePropagation();
                        if (typeof toastr !== 'undefined') {
                            toastr.error('This looks like a duplicate. Open the existing product and add stock instead.');
                        }
                        $('html, body').animate({ scrollTop: $warning.offset().top - 120 }, 250);
                        return false;
                    }
                });
            })();

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

            // ---- Relabel the price table's image column -----------------------
            // single_product_form_part.blade.php (shared with mass-add, so not
            // edited directly) labels its 4th column "Product image" too — same
            // wording as the real upload panel above, reading as a duplicate.
            // It's actually a separate field (variation_images[], extra photos
            // attached to the variation) — this page is the only place in the
            // app that exposes it, so relabel rather than remove it.
            (function relabelVariationImagesColumn() {
                var $th = $('#product_form_part .add-product-price-table thead th, #product_form_part .add-product-price-table tr th').filter(function () {
                    return $.trim($(this).text()) === 'Product image';
                });
                $th.text('Additional Images');

                var $label = $('label[for="variation_images"]');
                if ($label.length) {
                    $label.text('Additional Images:');
                    $label.closest('.form-group').find('.help-block').first()
                        .html('Extra photos for this listing, beyond the main cover image above.');
                }
            })();

            // ---- Secondary fields toggle ---------------------------------------
            // Bin position / listing location / image URL are optional metadata,
            // not needed for most products — keep them off-screen until asked for.
            $('#more_fields_toggle').on('click', function () {
                var $fields = $('.pe-more-field');
                var opening = $fields.is(':hidden');
                $fields.toggle(opening);
                $(this).text(opening ? '− Hide extra fields' : '+ Bin position, listing location, image URL');
            });
            // If the user already filled one in (e.g. browser back/forward, or a
            // duplicate-product prefill), show the group open instead of hiding
            // data they can't see.
            var hasPrefilledMoreField = false;
            $('.pe-more-field').each(function () {
                if ($(this).find('input').val()) { hasPrefilledMoreField = true; }
            });
            if (hasPrefilledMoreField) { $('#more_fields_toggle').trigger('click'); }

            // ---- Live "Margin" readout ----------------------------------------
            // Purely a display helper — reads the same cost/markup/price fields
            // product.js already keeps in sync, does not add a new form field.
            (function initMarginDisplay() {
                var $out = $('#margin_display_value');
                if (!$out.length) return;

                function currentSellingPrice() {
                    var $dspIncTax = $('#single_dsp_inc_tax');
                    var $dsp = $('#single_dsp');
                    if ($dspIncTax.length && !$dspIncTax.hasClass('hide')) return parseFloat($dspIncTax.val());
                    if ($dsp.length && !$dsp.hasClass('hide')) return parseFloat($dsp.val());
                    return NaN;
                }

                function update() {
                    var cost = parseFloat($('#product_form_part .dpp_inc_tax').val());
                    var pct = parseFloat($('#profit_percent').val());
                    var price = currentSellingPrice();

                    if (isNaN(pct)) { $out.text('—'); return; }

                    var text = pct.toFixed(2) + '%';
                    if (!isNaN(cost) && !isNaN(price) && price > 0) {
                        text += ' · ~$' + (price - cost).toFixed(2) + ' profit per unit';
                    }
                    $out.text(text);
                }

                $(document).on('input change keyup', '#product_form_part .dpp_inc_tax, #profit_percent, #single_dsp, #single_dsp_inc_tax, #tax_type', update);
                update();
            })();

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
