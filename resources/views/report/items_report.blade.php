@extends('layouts.app')
@section('title', __('lang_v1.items_report'))

@section('content')

{{-- Items Report — POS-create-style reskin (2026-04-28). Inter Tight font
     loaded non-blocking, scoped CSS in items-report-layout.css, body hook
     class = items-report-v2. Functionality untouched. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap"></noscript>
<link rel="stylesheet" href="{{ asset('css/items-report-layout.css?v=' . $asset_v) }}">
<script>document.body.classList.add('items-report-v2');</script>

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>{{ __('lang_v1.items_report')}} <small>Items sold + manual line items, with full filter export.</small></h1>
</section>

<!-- Main content -->
<section class="content">
    <div class="row">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('ir_supplier_id', __('purchase.supplier') . ':') !!}
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="fa fa-user"></i>
                        </span>
                        {!! Form::select('ir_supplier_id', $suppliers, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.all')]); !!}
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('ir_purchase_date_filter', __('purchase.purchase_date') . ':') !!}
                    {!! Form::text('ir_purchase_date_filter', null, ['placeholder' => __('lang_v1.select_a_date_range'), 'class' => 'form-control', 'readonly']); !!}
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('ir_customer_id', __('contact.customer') . ':') !!}
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="fa fa-user"></i>
                        </span>
                        {!! Form::select('ir_customer_id', $customers, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.all')]); !!}
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('ir_sale_date_filter', __('lang_v1.sell_date') . ':') !!}
                    {!! Form::text('ir_sale_date_filter', null, ['placeholder' => __('lang_v1.select_a_date_range'), 'class' => 'form-control', 'readonly']); !!}
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('ir_location_id', __('purchase.business_location').':') !!}
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="fa fa-map-marker"></i>
                        </span>
                        {!! Form::select('ir_location_id', $business_locations, null, ['class' => 'form-control select2', 'placeholder' => __('messages.please_select'), 'required']); !!}
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('ir_category_id', __('category.category') . ':') !!}
                    {!! Form::select('ir_category_id', $categories, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.all'), 'id' => 'ir_category_id', 'style' => 'width:100%']); !!}
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('ir_sub_category_id', __('product.sub_category') . ':') !!}
                    {!! Form::select('ir_sub_category_id', [], null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.all'), 'id' => 'ir_sub_category_id', 'style' => 'width:100%']); !!}
                </div>
            </div>
            @if(Module::has('Manufacturing'))
                <div class="col-md-3">
                    <div class="form-group">
                        <br>
                        <div class="checkbox">
                            <label>
                              {!! Form::checkbox('only_mfg', 1, false, 
                              [ 'class' => 'input-icheck', 'id' => 'only_mfg_products']); !!} {{ __('manufacturing::lang.only_mfg_products') }}
                            </label>
                        </div>
                    </div>
                </div>
            @endif
            <div class="col-md-3">
                <div class="form-group">
                    <br>
                    <div class="checkbox">
                        <label>
                          {!! Form::checkbox('only_manual_items', 1, false, 
                          [ 'class' => 'input-icheck', 'id' => 'only_manual_items']); !!} Only Manual Items
                        </label>
                    </div>
                </div>
            </div>
            @endcomponent

            <div style="margin: 0 0 14px; display: flex; justify-content: flex-end; align-items: center; gap: 10px;">
                <span id="ir-export-hint" style="font-size:12px;"></span>
                <button type="button" class="btn" id="ir-export-all-btn">
                    <i class="fa fa-file-excel"></i> Export filtered (CSV)
                </button>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary'])
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" 
                    id="items_report_table">
                        <thead>
                            <tr>
                                <th>@lang('sale.product')</th>
                                <th>Format</th>
                                <th>@lang('product.sku')</th>
                                <th>@lang('product.category')</th>
                                <th>@lang('product.sub_category')</th>
                                <th>@lang('lang_v1.description')</th>
                                <th>@lang('purchase.purchase_date')</th>
                                <th>@lang('lang_v1.purchase')</th>
                                <th>@lang('lang_v1.lot_number')</th>
                                <th>@lang('purchase.supplier')</th>
                                <th>@lang('lang_v1.purchase_price')</th>
                                <th>@lang('lang_v1.sell_date')</th>
                                <th>@lang('business.sale')</th>
                                <th>@lang('contact.customer')</th>
                                <th>@lang('sale.location')</th>
                                <th>@lang('lang_v1.sell_quantity')</th>
                                <th>@lang('lang_v1.selling_price')</th>
                                <th>@lang('sale.subtotal')</th>
                            </tr>
                        </thead>
                        <tfoot>
                            <tr class="bg-gray font-17 text-center footer-total">
                                <td colspan="10"><strong>@lang('sale.total'):</strong></td>
                                <td id="footer_total_pp" 
                                    class="display_currency" data-currency_symbol="true"></td>
                                <td colspan="4"></td>
                                <td id="footer_total_qty"></td>
                                <td id="footer_total_sp"
                                    class="display_currency" data-currency_symbol="true"></td>
                                <td id="footer_total_subtotal"
                                    class="display_currency" data-currency_symbol="true"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endcomponent
        </div>
    </div>
</section>
<!-- /.content -->
<div class="modal fade view_register" tabindex="-1" role="dialog" 
    aria-labelledby="gridSystemModalLabel">
</div>

@endsection

@section('javascript')
    <script src="{{ asset('js/report.js?v=' . $asset_v) }}"></script>
@endsection