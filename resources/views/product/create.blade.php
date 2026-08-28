@extends('layouts.app')
@section('title', __('product.add_new_product'))

@section('content')

<div class="product-add-v2">

<style>
/* ============ PRODUCT ADD V2 — matches /pos/create + product/edit theme ============ */
.product-add-v2 {
    --pe-bg:          #FAF6EE;
    --pe-surface:     #FFFFFF;
    --pe-surface-2:   #F7F1E3;
    --pe-ink:         #1F1B16;
    --pe-ink-2:       #5A5045;
    --pe-ink-3:       #8E8273;
    --pe-line:        #ECE3CF;
    --pe-line-2:      #DFD2B3;
    --pe-accent:      #FFF2B3;
    --pe-accent-deep: #E8CF68;
    --pe-accent-soft: #FFF9DB;
    --pe-accent-text: #5A4410;
    --pe-radius:      10px;
    --pe-radius-sm:   8px;
    --pe-shadow-sm:   0 1px 2px rgba(31,27,22,.06);
    --pe-shadow-md:   0 4px 14px rgba(31,27,22,.08);

    background: var(--pe-bg);
    font-family: "Inter Tight", system-ui, sans-serif;
    color: var(--pe-ink);
    -webkit-font-smoothing: antialiased;
    margin: -15px;
    padding: 10px 14px 32px;
    min-height: calc(100vh - 50px);
}
.product-add-v2 .row { margin-left: -6px; margin-right: -6px; }
.product-add-v2 [class*="col-"] { padding-left: 6px; padding-right: 6px; }
.product-add-v2 .pe-header {
    display: flex; align-items: center; justify-content: space-between;
    margin: 0 0 20px;
}
.product-add-v2 .pe-header h1 {
    font-family: inherit;
    font-size: 26px;
    font-weight: 800;
    letter-spacing: -0.02em;
    margin: 0;
    color: var(--pe-ink);
}
.product-add-v2 .pe-card {
    background: var(--pe-surface);
    border: 1px solid var(--pe-line);
    border-radius: var(--pe-radius);
    box-shadow: var(--pe-shadow-sm);
    padding: 12px 14px;
    margin-bottom: 10px;
}
.product-add-v2 .pe-card-title {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .08em;
    font-weight: 700;
    color: var(--pe-ink-3);
    margin: 0 0 8px;
    padding-bottom: 6px;
    border-bottom: 1px solid var(--pe-line);
}
.product-add-v2 .form-group { margin-bottom: 8px; }
.product-add-v2 label,
.product-add-v2 .form-group label {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: .04em;
    font-weight: 700;
    color: var(--pe-ink-3);
    margin-bottom: 2px;
}
.product-add-v2 .form-control,
.product-add-v2 .select2-selection--single,
.product-add-v2 .select2-selection--multiple {
    border: 1px solid var(--pe-line-2) !important;
    border-radius: var(--pe-radius-sm) !important;
    background: var(--pe-surface) !important;
    color: var(--pe-ink) !important;
    min-height: 30px !important;
    height: 30px !important;
    padding: 4px 9px !important;
    font-family: inherit !important;
    font-size: 13px !important;
    box-shadow: none !important;
}
.product-add-v2 textarea.form-control { height: auto !important; min-height: 60px !important; }
.product-add-v2 .select2-selection--single .select2-selection__rendered { line-height: 22px !important; padding: 0 !important; }
.product-add-v2 .select2-selection--single .select2-selection__arrow { height: 28px !important; }
.product-add-v2 .help-block { font-size: 12px; color: var(--pe-ink-3); margin-top: 4px; }
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
.product-add-v2 .bg-purple { background: var(--pe-accent-soft) !important; color: var(--pe-accent-text) !important; border-color: var(--pe-accent-deep) !important; }
.product-add-v2 .bg-maroon { background: #8A3A2E !important; color: #fff !important; border-color: #8A3A2E !important; }
.product-add-v2 .btn-warning { background: var(--pe-accent) !important; color: var(--pe-accent-text) !important; border-color: var(--pe-accent-deep) !important; }

.product-add-v2 .add-product-price-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
}
.product-add-v2 .add-product-price-table > tbody > tr > th,
.product-add-v2 .add-product-price-table > thead > tr > th {
    background: var(--pe-accent-soft);
    color: var(--pe-accent-text);
    border: 1px solid var(--pe-line-2);
    text-transform: uppercase;
    letter-spacing: .04em;
    font-size: 11px;
    font-weight: 700;
    padding: 10px 12px;
}
.product-add-v2 .add-product-price-table > tbody > tr > td {
    border: 1px solid var(--pe-line);
    padding: 12px;
    background: var(--pe-surface);
}

.product-add-v2 .pe-action-bar {
    background: var(--pe-surface);
    border: 1px solid var(--pe-line);
    border-radius: var(--pe-radius);
    box-shadow: var(--pe-shadow-md);
    padding: 14px 20px;
    margin: 20px 0 0;
    display: flex; justify-content: flex-end; gap: 10px;
    position: sticky; bottom: 12px; z-index: 50;
}
.product-add-v2 .pe-action-bar .btn { padding: 11px 22px; font-size: 15px; }
</style>

<div class="pe-header">
    <h1>@lang('product.add_new_product')</h1>
</div>

@php
    $form_class = empty($duplicate_product) ? 'create' : '';
@endphp
{!! Form::open(['url' => action('ProductController@store'), 'method' => 'post',
    'id' => 'product_add_form','class' => 'product_form ' . $form_class, 'files' => true ]) !!}

    {{-- ─── Card 1: Identity ──────────────────────────────────────────── --}}
    <div class="pe-card">
        <h3 class="pe-card-title">Product Info</h3>
        <div class="row">
            <div class="col-sm-6">
                <div class="form-group">
                    {!! Form::label('name', __('product.product_name') . ':*') !!}
                    {!! Form::text('name', old('name', !empty($duplicate_product->name) ? $duplicate_product->name : null), ['class' => 'form-control title-autocomplete-input', 'required',
                    'placeholder' => __('product.product_name')]) !!}
                </div>
            </div>
            <div class="col-sm-3">
                <div class="form-group">
                    {!! Form::label('sku', __('product.sku') . ':') !!} @show_tooltip(__('tooltip.sku'))
                    {!! Form::text('sku', old('sku'), ['class' => 'form-control',
                      'placeholder' => __('product.sku')]) !!}
                </div>
            </div>
            <div class="col-sm-3">
                <div class="form-group">
                    {!! Form::label('artist', 'Artist' . ':') !!}
                    {!! Form::text('artist', old('artist', !empty($duplicate_product->artist) ? $duplicate_product->artist : null), ['class' => 'form-control artist-autocomplete-input',
                    'placeholder' => 'Artist']) !!}
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

            <div class="col-sm-4 @if(!session('business.enable_brand')) hide @endif">
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

            <div class="col-sm-4 @if(!session('business.enable_category')) hide @endif">
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

            <div class="col-sm-2">
                <div class="form-group">
                    {!! Form::label('bin_position', 'Bin Position' . ':') !!}
                    {!! Form::text('bin_position', !empty($duplicate_product->bin_position) ? $duplicate_product->bin_position : null, ['class' => 'form-control',
                    'placeholder' => 'A-12, B-5']) !!}
                </div>
            </div>

            <div class="col-sm-3">
                <div class="form-group">
                    {!! Form::label('listing_location', 'Listing Location' . ':') !!}
                    {!! Form::text('listing_location', !empty($duplicate_product->listing_location) ? $duplicate_product->listing_location : null, ['class' => 'form-control',
                    'placeholder' => 'Warehouse A, Storage B']) !!}
                    <p class="help-block">For eBay/Discogs listings</p>
                </div>
            </div>

            <div class="col-sm-3">
                <div class="form-group">
                    {!! Form::label('product_custom_field1', 'Image URL') !!}
                    {!! Form::text('product_custom_field1', !empty($duplicate_product->product_custom_field1) ? $duplicate_product->product_custom_field1 : null, ['class' => 'form-control',
                    'placeholder' => 'Image URL']) !!}
                </div>
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
                    {!! Form::select('product_locations[]', $business_locations, $default_location, ['class' => 'form-control select2', 'multiple', 'id' => 'product_locations']) !!}
                </div>
            </div>

            <div class="col-sm-6">
                <div class="form-group">
                    <label style="display:block; margin-bottom:6px;">
                        {!! Form::checkbox('enable_stock', 1, !empty($duplicate_product) ? $duplicate_product->enable_stock : true, ['class' => 'input-icheck', 'id' => 'enable_stock']) !!}
                        <strong style="text-transform:none; letter-spacing:normal; font-size:13px;">@lang('product.manage_stock')</strong>
                    </label>@show_tooltip(__('tooltip.enable_stock'))
                    <p class="help-block"><i>@lang('product.enable_stock_help')</i></p>
                </div>
            </div>

            <div class="col-sm-6">
                <div class="form-group">
                    {!! Form::label('image', __('lang_v1.product_image') . ':') !!}
                    {!! Form::file('image', ['id' => 'upload_image', 'accept' => 'image/*']) !!}
                    <p class="help-block">@lang('purchase.max_file_size', ['size' => (config('constants.document_size_limit') / 1000000)]) @lang('lang_v1.aspect_ratio_should_be_1_1')</p>
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

    {{-- ─── Card 2: Cost & Selling Price ──────────────────────────────── --}}
    <div class="pe-card">
        <h3 class="pe-card-title">Cost &amp; Selling Price</h3>
        <div class="row">
            <div class="col-sm-3 @if(!session('business.enable_price_tax')) hide @endif">
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

            <div class="col-sm-3 @if(!session('business.enable_price_tax')) hide @endif">
                <div class="form-group">
                    <label style="display:block; margin-bottom:6px;">
                        {!! Form::checkbox('tax_exempt', 1, !empty($duplicate_product) && !empty($duplicate_product->tax_exempt) ? $duplicate_product->tax_exempt : false, ['class' => 'input-icheck']) !!}
                        <strong style="text-transform:none; letter-spacing:normal; font-size:13px;">Tax Exempt</strong>
                    </label>
                    <p class="help-block">Check if this product is exempt from sales tax</p>
                </div>
            </div>

            <div class="col-sm-3">
                <div class="form-group">
                    {!! Form::label('type', __('product.product_type') . ':*') !!} @show_tooltip(__('tooltip.product_type'))
                    {!! Form::select('type', $product_types, !empty($duplicate_product->type) ? $duplicate_product->type : null, ['class' => 'form-control select2',
                    'required', 'data-action' => !empty($duplicate_product) ? 'duplicate' : 'add', 'data-product_id' => !empty($duplicate_product) ? $duplicate_product->id : '0']) !!}
                </div>
            </div>

            <div class="form-group col-sm-12" id="product_form_part">
                @include('product.partials.single_product_form_part', ['profit_percent' => $default_profit_percent])
            </div>

            <input type="hidden" id="variation_counter" value="1">
            <input type="hidden" id="default_profit_percent"
                   value="{{ $default_profit_percent }}">
        </div>
    </div>

{!! Form::close() !!}

    {{-- ─── Card 3: Description ───────────────────────────────────────── --}}
    <div class="pe-card">
        <h3 class="pe-card-title">@lang('lang_v1.product_description')</h3>
        {{-- Sits outside the main form but submits with it via the HTML5 form attr. --}}
        {!! Form::textarea('product_description', !empty($duplicate_product->product_description) ? $duplicate_product->product_description : null, ['class' => 'form-control', 'form' => 'product_add_form']) !!}
    </div>

    {{-- ─── Action bar (sticky, submits the main form) ───────────────── --}}
    <div class="pe-action-bar">
        <input type="hidden" name="submit_type" id="submit_type" form="product_add_form">

        @if($selling_price_group_count)
            <button type="submit" form="product_add_form" value="submit_n_add_selling_prices" class="btn btn-warning submit_product_form">@lang('lang_v1.save_n_add_selling_price_group_prices')</button>
        @endif

        @can('product.opening_stock')
            <button id="opening_stock_button" form="product_add_form" @if(!empty($duplicate_product) && $duplicate_product->enable_stock == 0) disabled @endif type="submit" value="submit_n_add_opening_stock" class="btn bg-purple submit_product_form">@lang('lang_v1.save_n_add_opening_stock')</button>
        @endcan

        <button type="submit" form="product_add_form" value="save_n_add_another" class="btn bg-maroon submit_product_form">@lang('lang_v1.save_n_add_another')</button>

        <button type="submit" form="product_add_form" value="submit" class="btn btn-primary submit_product_form">@lang('messages.save')</button>
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
