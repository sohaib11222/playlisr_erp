@extends('layouts.app')
@section('title', __('product.edit_product'))

@section('content')

<div class="product-edit-v2">

<style>
/* ============ PRODUCT EDIT V2 ============
   Mirrors product/create.blade.php's design system exactly — same
   tokens, same card/flow patterns — so add and edit read as one
   product, not two different tools. Near-monochrome: one neutral
   palette, one accent color (maroon) reserved for the single primary
   action. Flat surfaces, no shadows, no per-cell table borders, no
   decorative icons. */
.product-edit-v2 {
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
.product-edit-v2 .fa-info-circle.hover-q { display: none !important; }
.product-edit-v2 .row { display: flex; flex-wrap: wrap; margin-left: -6px; margin-right: -6px; }
.product-edit-v2 [class*="col-"] { padding-left: 6px; padding-right: 6px; }

.product-edit-v2 .pe-breadcrumb {
    max-width: var(--pe-form-width);
    font-size: 12.5px;
    color: var(--pe-ink-3);
    margin: 0 0 8px;
}
.product-edit-v2 .pe-breadcrumb a { color: var(--pe-ink-2); text-decoration: none; }
.product-edit-v2 .pe-breadcrumb a:hover { color: var(--pe-ink); text-decoration: underline; }
.product-edit-v2 .pe-breadcrumb span.sep { margin: 0 6px; }
.product-edit-v2 .pe-breadcrumb span.current { color: var(--pe-ink); font-weight: 600; }

.product-edit-v2 .pe-topbar {
    max-width: var(--pe-form-width);
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin: 0 0 20px;
    flex-wrap: wrap;
}
.product-edit-v2 .pe-topbar h1 {
    font-family: inherit;
    font-size: 26px;
    font-weight: 800;
    letter-spacing: -0.02em;
    margin: 0;
    color: var(--pe-ink);
}
.product-edit-v2 .pe-subtitle {
    margin: 4px 0 0;
    font-size: 13.5px;
    color: var(--pe-ink-2);
}
.product-edit-v2 .pe-topbar-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.product-edit-v2 .pe-card {
    background: var(--pe-surface);
    border: 1px solid var(--pe-line);
    border-radius: var(--pe-radius);
    padding: 18px 20px;
    margin-bottom: 12px;
}
.product-edit-v2 .pe-card-wide { max-width: var(--pe-form-width); }
.product-edit-v2 .pe-card-row {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    max-width: var(--pe-form-width);
}
.product-edit-v2 .pe-card-row .pe-card { margin-bottom: 0; }
.product-edit-v2 .pe-card-row .pe-card-pricing { flex: 1 1 62%; }
.product-edit-v2 .pe-card-row .pe-card-description { flex: 1 1 34%; }

.product-edit-v2 .pe-card-title {
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: .06em;
    font-weight: 700;
    color: var(--pe-ink);
    margin: 0 0 10px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--pe-line);
}
.product-edit-v2 .form-group { margin-bottom: 14px; }
.product-edit-v2 .pe-more-toggle {
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
.product-edit-v2 .pe-more-toggle:hover { color: var(--pe-ink); text-decoration: underline; }
.product-edit-v2 .select2-container {
    width: 100% !important;
}
.product-edit-v2 .form-control,
.product-edit-v2 .select2-container {
    max-width: 460px;
}
.product-edit-v2 textarea.form-control,
.product-edit-v2 select[id="product_locations"] + .select2-container,
.product-edit-v2 .add-product-price-table .form-control,
.product-edit-v2 .pe-image-panel .form-control {
    max-width: none;
}
.product-edit-v2 label,
.product-edit-v2 .form-group label {
    display: block;
    font-size: 13px;
    text-transform: none;
    letter-spacing: normal;
    font-weight: 600;
    color: var(--pe-ink);
    margin-bottom: 4px;
}
.product-edit-v2 .form-control,
.product-edit-v2 .select2-selection--single {
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
.product-edit-v2 .select2-selection--multiple {
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
.product-edit-v2 .select2-selection__choice {
    background: var(--pe-surface-2) !important;
    border: 1px solid var(--pe-line-2) !important;
    color: var(--pe-ink) !important;
    border-radius: 4px !important;
}
.product-edit-v2 .select2-selection__choice__remove { color: var(--pe-ink-2) !important; }
.product-edit-v2 textarea.form-control { height: auto !important; }
.product-edit-v2 .select2-selection--single .select2-selection__rendered { line-height: 22px !important; padding: 0 !important; color: var(--pe-ink) !important; }
.product-edit-v2 .select2-selection--single .select2-selection__arrow { height: 36px !important; }
.product-edit-v2 ::placeholder { color: #9A9084 !important; opacity: 1 !important; }
.product-edit-v2 .help-block { font-size: 12.5px; color: var(--pe-ink-2); margin-top: 4px; }
.product-edit-v2 .form-control:focus,
.product-edit-v2 .select2-selection--single:focus,
.product-edit-v2 .select2-container--focus .select2-selection {
    border-color: var(--pe-accent-deep) !important;
    box-shadow: 0 0 0 3px var(--pe-accent-soft) !important;
    outline: none !important;
}

.product-edit-v2 .btn {
    font-family: inherit;
    font-weight: 600;
    border-radius: var(--pe-radius-sm);
    padding: 9px 16px;
    border: 1px solid var(--pe-line-2);
    background: var(--pe-surface);
    color: var(--pe-ink);
    transition: background .15s, border-color .15s, transform .05s;
}
.product-edit-v2 .btn:hover { background: var(--pe-surface-2); }
.product-edit-v2 .btn:active { transform: translateY(1px); }
.product-edit-v2 .btn-primary {
    background: var(--pe-ink);
    border-color: var(--pe-ink);
    color: var(--pe-bg);
}
.product-edit-v2 .btn-primary:hover { background: #2C2620; border-color: #2C2620; color: var(--pe-bg); }
.product-edit-v2 .bg-purple,
.product-edit-v2 .btn-warning {
    background: var(--pe-surface) !important;
    color: var(--pe-ink) !important;
    border-color: var(--pe-line-2) !important;
}
.product-edit-v2 .bg-maroon {
    background: var(--pe-accent-deep) !important;
    color: #fff !important;
    border-color: var(--pe-accent-deep) !important;
}

/* ---- Product image panel (right column of card 1) ---- */
.product-edit-v2 .pe-image-panel { padding: 0; }
.product-edit-v2 .pe-image-panel .pe-image-hint { font-size: 12px; color: var(--pe-ink-3); margin: 0 0 8px; }
.product-edit-v2 .pe-image-panel .kv-fileinput-caption { display: none !important; }
.product-edit-v2 .pe-image-panel .file-caption-main { display: block !important; }
.product-edit-v2 .pe-image-panel .input-group-btn { display: block !important; width: 100%; }
.product-edit-v2 .pe-image-panel .btn-file {
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
.product-edit-v2 .pe-image-panel .file-preview { margin-top: 10px; }
.product-edit-v2 .pe-current-image { margin-bottom: 10px; }
.product-edit-v2 .pe-current-image img {
    max-height: 120px; max-width: 120px;
    border: 1px solid var(--pe-line-2); border-radius: var(--pe-radius-sm); padding: 2px;
}

/* Price "table" reads as a plain row of labeled fields, not a grid. */
.product-edit-v2 .add-product-price-table {
    width: 100%;
    border-collapse: collapse;
}
.product-edit-v2 .add-product-price-table > tbody > tr > th,
.product-edit-v2 .add-product-price-table > thead > tr > th {
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
.product-edit-v2 .add-product-price-table > tbody > tr > td {
    border: none;
    padding: 10px 12px 0 0;
    background: transparent;
}

.product-edit-v2 .table { font-size: 14px; background: var(--pe-surface); }
.product-edit-v2 .table > thead > tr > th {
    background: var(--pe-surface-2);
    border-bottom: 1px solid var(--pe-line-2) !important;
    font-size: 12px; font-weight: 600; color: var(--pe-ink-2); padding: 10px 14px;
}
.product-edit-v2 .table > tbody > tr > td {
    padding: 10px 14px; border-color: var(--pe-line); vertical-align: middle;
}
</style>

<div class="pe-breadcrumb">
    <a href="{{ route('products.index') }}">Products</a>
    <span class="sep">/</span>
    <span class="current">Edit Product</span>
</div>

<div class="pe-topbar">
    <div>
        <h1>@lang('product.edit_product')</h1>
        <p class="pe-subtitle">{{ $product->name }}</p>
    </div>
    <div class="pe-topbar-actions">
        <input type="hidden" name="submit_type" id="submit_type" form="product_add_form">

        <button type="submit" form="product_add_form" value="save_n_add_another" class="btn submit_product_form">@lang('lang_v1.update_n_add_another')</button>

        @if($selling_price_group_count)
            <button type="submit" form="product_add_form" value="submit_n_add_selling_prices" class="btn btn-warning submit_product_form">@lang('lang_v1.save_n_add_selling_price_group_prices')</button>
        @endif

        @can('product.opening_stock')
            <button type="submit" form="product_add_form" @if(empty($product->enable_stock)) disabled="true" @endif id="opening_stock_button" value="update_n_edit_opening_stock" class="btn bg-purple submit_product_form">@lang('lang_v1.update_n_edit_opening_stock')</button>
        @endcan

        <button type="submit" form="product_add_form" value="submit" class="btn bg-maroon submit_product_form">@lang('messages.update')</button>
    </div>
</div>

{!! Form::open(['url' => action('ProductController@update' , [$product->id] ), 'method' => 'PUT', 'id' => 'product_add_form',
        'class' => 'product_form', 'files' => true, 'data-product-edit' => '1' ]) !!}
    <input type="hidden" id="product_id" value="{{ $product->id }}">

    {{-- Hidden fields kept so existing values are preserved on save --}}
    {!! Form::hidden('barcode_type', $product->barcode_type) !!}
    {!! Form::hidden('unit_id', $product->unit_id) !!}
    {!! Form::hidden('brand_id', $product->brand_id) !!}

    {{-- ─── Card 1: Product Information ─────────────────────────────────── --}}
    <div class="pe-card pe-card-wide">
        <h3 class="pe-card-title">Product Information</h3>
        <div class="row">
            <div class="col-sm-8">
                <div class="row">
                    <div class="col-sm-12">
                        <div class="form-group">
                            {!! Form::label('name', __('product.product_name') . ':*') !!}
                            {!! Form::text('name', $product->name, ['class' => 'form-control title-autocomplete-input', 'required',
                            'placeholder' => __('product.product_name')]) !!}
                        </div>
                    </div>

                    <div class="col-sm-6">
                        <div class="form-group">
                            {!! Form::label('sku', __('product.sku') . ':*') !!}
                            {!! Form::text('sku', $product->sku, ['class' => 'form-control', 'placeholder' => __('product.sku'), 'required']) !!}
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            {!! Form::label('artist', 'Artist:') !!}
                            {!! Form::text('artist', !empty($product->artist) ? $product->artist : null, ['class' => 'form-control artist-autocomplete-input', 'placeholder' => 'Artist']) !!}
                        </div>
                    </div>

                    <div class="col-sm-6 @if(!session('business.enable_category')) hide @endif">
                        <div class="form-group">
                            {!! Form::label('category_combo', __('product.category') . ' / ' . __('product.sub_category') . ' *:') !!}
                            @php
                                $categoryCombos = isset($category_combos) ? $category_combos : [];
                                $selectedComboId = null;
                                if (!empty($product->category_id)) {
                                    $subIdForCombo = !empty($product->sub_category_id) ? (int)$product->sub_category_id : 0;
                                    $selectedComboId = $product->category_id . '_' . $subIdForCombo;
                                }
                            @endphp
                            <select name="category_combo" id="category_combo" class="form-control select2">
                                <option value="">{{ __('messages.please_select') }}</option>
                                @foreach($categoryCombos as $combo)
                                    @php $isSelected = !empty($selectedComboId) && $combo['id'] == $selectedComboId; @endphp
                                    <option value="{{ $combo['id'] }}"
                                            data-category-id="{{ $combo['category_id'] }}"
                                            data-sub-category-id="{{ isset($combo['sub_category_id']) ? $combo['sub_category_id'] : '' }}"
                                            @if($isSelected) selected @endif>
                                        {{ $combo['label'] }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        {!! Form::hidden('category_id', $product->category_id, ['id' => 'category_id']) !!}
                        {!! Form::hidden('sub_category_id', $product->sub_category_id, ['id' => 'sub_category_id']) !!}
                    </div>

                    <div class="col-sm-6">
                        <div class="form-group">
                            {!! Form::label('product_locations', __('business.business_locations') . ':') !!}
                            {!! Form::select('product_locations[]', $business_locations, $product->product_locations->pluck('id'), ['class' => 'form-control select2', 'multiple', 'id' => 'product_locations', 'data-placeholder' => 'Select location(s)']) !!}
                        </div>
                    </div>

                    <div class="col-sm-6">
                        <div class="form-group">
                            {!! Form::label('product_custom_field2', 'Street / Release Date') !!}
                            {!! Form::date('product_custom_field2', !empty($product->product_custom_field2) ? $product->product_custom_field2 : null, ['class' => 'form-control']) !!}
                            <p class="help-block">A future date makes this a preorder on the website until then. Leave blank for a regular in-stock item.</p>
                        </div>
                    </div>

                    <div class="col-sm-12">
                        <button type="button" id="more_fields_toggle" class="pe-more-toggle">+ Bin position, listing location, image URL</button>
                    </div>

                    <div class="col-sm-6 pe-more-field" style="display:none">
                        <div class="form-group">
                            {!! Form::label('bin_position', 'Bin Position:') !!}
                            {!! Form::text('bin_position', !empty($product->bin_position) ? $product->bin_position : null, ['class' => 'form-control', 'placeholder' => 'A-12, B-5']) !!}
                        </div>
                    </div>

                    <div class="col-sm-6 pe-more-field" style="display:none">
                        <div class="form-group">
                            {!! Form::label('listing_location', 'Listing Location:') !!}
                            {!! Form::text('listing_location', !empty($product->listing_location) ? $product->listing_location : null, ['class' => 'form-control', 'placeholder' => 'Warehouse A, Storage B']) !!}
                            <p class="help-block">For eBay/Discogs listings</p>
                        </div>
                    </div>

                    <div class="col-sm-6 pe-more-field" style="display:none">
                        <div class="form-group">
                            {!! Form::label('product_custom_field1', 'Image URL:') !!}
                            {!! Form::text('product_custom_field1', !empty($product->product_custom_field1) ? $product->product_custom_field1 : null, ['class' => 'form-control', 'placeholder' => 'Image URL']) !!}
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-sm-4">
                <label>@lang('lang_v1.product_image'):</label>
                <div class="pe-image-panel">
                    @if(!empty($product->image))
                        {{-- Show the currently stored image (e.g. the Discogs cover
                             pulled in via Mass Add) so staff can see what's on file
                             before choosing to replace it. --}}
                        <div class="pe-current-image">
                            <img src="{{ $product->image_url }}" alt="Product image">
                        </div>
                    @endif
                    <p class="pe-image-hint">@lang('purchase.max_file_size', ['size' => (config('constants.document_size_limit') / 1000000)]) &middot; @lang('lang_v1.aspect_ratio_should_be_1_1') @if(!empty($product->image)) &middot; @lang('lang_v1.previous_image_will_be_replaced') @endif</p>
                    {!! Form::file('image', ['id' => 'upload_image', 'accept' => 'image/*']) !!}
                </div>
            </div>
        </div>
    </div>

    {{-- ─── Card 2: Inventory Options ───────────────────────────────────── --}}
    <div class="pe-card pe-card-wide">
        <h3 class="pe-card-title">Inventory Options</h3>
        <div class="row">
            <div class="col-sm-6">
                <div class="form-group">
                    <label style="display:block; margin-bottom:6px;">
                        {!! Form::checkbox('enable_stock', 1, $product->enable_stock, ['class' => 'input-icheck', 'id' => 'enable_stock']) !!}
                        <strong style="text-transform:none; letter-spacing:normal; font-size:13px;">@lang('product.manage_stock')</strong>
                    </label>
                    <p class="help-block"><i>@lang('product.enable_stock_help')</i></p>
                </div>
            </div>

            <div class="col-sm-6">
                <div class="form-group">
                    {!! Form::label('alert_quantity', __('product.alert_quantity') . ':') !!}
                    {!! Form::text('alert_quantity', !empty($product->alert_quantity) ? $product->alert_quantity : null, ['class' => 'form-control input_number',
                    'placeholder' => 'e.g. 5']) !!}
                    <p class="help-block">Shows on the dashboard's Product Stock Alert list when stock is at or below this.</p>
                </div>
            </div>
        </div>
    </div>

{!! Form::close() !!}

    {{-- ─── Card 3: Set Current Stock (own form, AJAX) ──────────────────── --}}
    @if(!empty($product->enable_stock))
    <div class="pe-card pe-card-wide">
        <h3 class="pe-card-title">Current Stock</h3>
        <p class="help-block" style="margin-bottom:12px;">@lang('product.set_current_stock_help')</p>
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
                                    <input type="number" min="0" step="1" name="current_stock[{{ $loc->id }}][{{ $var->id }}]" value="{{ $current_qty_int }}" class="form-control input-sm" style="width: 120px;">
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                    @endif
                </tbody>
            </table>
            <button type="button" class="btn btn-primary" id="btn_set_current_stock">@lang('messages.update') @lang('product.set_current_stock')</button>
        </form>
    </div>
    @endif

    {{-- ─── Card 4: Pricing & Tax + Description (side by side) ──────────── --}}
    <div class="pe-card-row">
        <div class="pe-card pe-card-pricing">
            <h3 class="pe-card-title">Pricing &amp; Tax</h3>
            <div class="row">
                <div class="col-sm-4 @if(!session('business.enable_price_tax')) hide @endif">
                    <div class="form-group">
                        {!! Form::label('tax_type', __('product.selling_price_tax_type') . ':*') !!}
                        {!! Form::select('tax_type', ['inclusive' => __('product.inclusive'), 'exclusive' => __('product.exclusive')], $product->tax_type, ['class' => 'form-control select2', 'required']) !!}
                    </div>
                </div>

                <div class="col-sm-4 @if(!session('business.enable_price_tax')) hide @endif">
                    <div class="form-group">
                        <label style="display:block; margin-bottom:6px;">
                            {!! Form::checkbox('tax_exempt', 1, !empty($product->tax_exempt) ? $product->tax_exempt : false, ['class' => 'input-icheck']) !!}
                            <strong style="text-transform:none; letter-spacing:normal; font-size:13px;">Tax Exempt</strong>
                        </label>
                        <p class="help-block">Check if this product is exempt from sales tax</p>
                    </div>
                </div>

                <div class="col-sm-4">
                    <div class="form-group">
                        {!! Form::label('type', __('product.product_type') . ':*') !!}
                        {!! Form::select('type', $product_types, $product->type, ['class' => 'form-control select2', 'required', 'disabled', 'data-action' => 'edit', 'data-product_id' => $product->id]) !!}
                    </div>
                </div>

                <div class="form-group col-sm-12" id="product_form_part"></div>
                <input type="hidden" id="variation_counter" value="0">
                <input type="hidden" id="default_profit_percent" value="{{ $default_profit_percent }}">
            </div>
        </div>

        <div class="pe-card pe-card-description">
            <h3 class="pe-card-title">@lang('lang_v1.product_description')</h3>
            {!! Form::textarea('product_description', $product->product_description, ['class' => 'form-control', 'rows' => 4, 'form' => 'product_add_form']) !!}
            <p class="help-block">Also shown as the product description on nivessa.com.</p>
        </div>
    </div>

</div>{{-- /.product-edit-v2 --}}

@endsection

@section('javascript')
  <script src="{{ asset('js/product.js?v=' . $asset_v) }}"></script>
  <script type="text/javascript">
    $(document).ready( function(){
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

      ensureCategoryComboMatcher();
      setTimeout(ensureCategoryComboMatcher, 0);

      // ---- Secondary fields toggle ---------------------------------------
      // Bin position / listing location / image URL are optional metadata —
      // open the group automatically if any of them already has a value so
      // existing data isn't hidden from view.
      var hasPrefilledMoreField = false;
      $('.pe-more-field').each(function () {
        if ($(this).find('input').val()) { hasPrefilledMoreField = true; }
      });
      function setMoreFieldsOpen(open) {
        $('.pe-more-field').toggle(open);
        $('#more_fields_toggle').text(open ? '− Hide extra fields' : '+ Bin position, listing location, image URL');
      }
      $('#more_fields_toggle').on('click', function () {
        setMoreFieldsOpen($('.pe-more-field').is(':hidden'));
      });
      setMoreFieldsOpen(hasPrefilledMoreField);

      __page_leave_confirmation('#product_add_form');

      // Snapshot initial stock values so we can detect changes the user made
      // before submitting the main product form.
      var __initialStock = {};
      $('#set_current_stock_form').find('input[name^="current_stock["]').each(function () {
        __initialStock[$(this).attr('name')] = $(this).val();
      });

      function __stockChanged() {
        var changed = false;
        $('#set_current_stock_form').find('input[name^="current_stock["]').each(function () {
          if ($(this).val() !== __initialStock[$(this).attr('name')]) {
            changed = true;
          }
        });
        return changed;
      }

      $('#set_current_stock_form').on('submit', function (e) {
        e.preventDefault();
        runSetCurrentStock();
      });

      $('#btn_set_current_stock').on('click', function (e) {
        e.preventDefault();
        runSetCurrentStock();
      });

      // The Current Stock inputs live in a separate form, so the main
      // "Update" button at the bottom would otherwise drop them. The
      // button click is intercepted by public/js/product.js (delegated on
      // document) which AJAX-submits the main form directly — no form
      // `submit` event fires. Attach our own click handler directly on
      // the button so it runs before the delegated one; stop the click
      // until the stock AJAX returns, then re-trigger it.
      $('.submit_product_form').on('click', function (e) {
        if (e.currentTarget.__stockSavedForThisClick) return;
        if (!$('#set_current_stock_form').length || !__stockChanged()) return;
        e.stopImmediatePropagation();
        e.preventDefault();
        var btn = e.currentTarget;
        runSetCurrentStock(function (ok) {
          if (!ok) return; // toastr already surfaced the error
          btn.__stockSavedForThisClick = true;
          $(btn).trigger('click');
        });
      });

      function runSetCurrentStock(done) {
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
                    __initialStock[$input.attr('name')] = String(val);
                    $input.closest('tr').find('td').eq(-2).text(val);
                  }
                });
              });
              if (typeof done === 'function') done(true);
            } else {
              toastr.error(data.msg || '{{ __("messages.something_went_wrong") }}');
              if (typeof done === 'function') done(false);
            }
          },
          error: function (xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.msg) ? xhr.responseJSON.msg : '{{ __("messages.something_went_wrong") }}';
            toastr.error(msg);
            if (typeof done === 'function') done(false);
          },
          complete: function () {
            $btn.prop('disabled', false);
          }
        });
      }
    });
  </script>
@endsection
