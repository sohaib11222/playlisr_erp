@extends('layouts.app')
@section('title', __('lang_v1.product_purchase_report'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>{{ __('lang_v1.product_purchase_report')}}</h1>
</section>

<!-- Main content -->
<section class="content">
    <div class="row">
        <div class="col-md-12">
            @if($current_week_budget)
                @php
                    $budget = (float) $current_week_budget['budget'];
                    $actual = (float) $current_week_actual;
                    $remaining = $budget - $actual;
                    $pct = $budget > 0 ? ($actual / $budget) * 100 : 0;
                    $bar_w = min(100, max(0, $pct));
                    $over = $actual > $budget;
                    if ($over)            { $bar_class = 'progress-bar-danger';  $status_color = '#b91c1c'; $status_bg = '#fef2f2'; $status_border = '#fecaca'; $status_label = 'Over budget'; }
                    elseif ($pct >= 90)   { $bar_class = 'progress-bar-warning'; $status_color = '#a16207'; $status_bg = '#fefce8'; $status_border = '#fde68a'; $status_label = 'Near budget'; }
                    else                  { $bar_class = 'progress-bar-success'; $status_color = '#15803d'; $status_bg = '#f0fdf4'; $status_border = '#bbf7d0'; $status_label = 'Within budget'; }
                @endphp
                <div class="box box-solid">
                    <div class="box-header with-border">
                        <h3 class="box-title">This week's purchase budget
                            <small class="text-muted">(Week {{ $current_week_budget['week_no'] }} of 13 · {{ \Carbon::parse($current_week_budget['start'])->format('M j') }}–{{ \Carbon::parse($current_week_budget['end'])->format('M j') }})</small>
                        </h3>
                    </div>
                    <div class="box-body">
                        <div style="display:flex; flex-wrap:wrap; align-items:center; gap:16px; margin-bottom:10px;">
                            <span style="display:inline-block; padding:4px 12px; background:{{ $status_bg }}; color:{{ $status_color }}; border:1px solid {{ $status_border }}; border-radius:999px; font-weight:600; font-size:13px;">
                                {{ $status_label }}
                            </span>
                            <span style="font-size:13px; color:#475569;">
                                <strong style="font-size:18px; color:#0f172a;">${{ number_format($actual, 2) }}</strong>
                                spent of
                                <strong>${{ number_format($budget, 2) }}</strong>
                                ({{ number_format($pct, 0) }}%)
                            </span>
                            <span style="font-size:13px; color:{{ $over ? '#b91c1c' : '#475569' }};">
                                {{ $over ? '$' . number_format(abs($remaining), 2) . ' over' : '$' . number_format($remaining, 2) . ' left' }}
                            </span>
                        </div>
                        <div class="progress" style="height:18px; margin-bottom:0;">
                            <div class="progress-bar {{ $bar_class }}" role="progressbar" style="width: {{ $bar_w }}%;" aria-valuenow="{{ $bar_w }}" aria-valuemin="0" aria-valuemax="100"></div>
                        </div>
                    </div>
                </div>
            @endif
            <div class="box box-solid">
                <div class="box-header with-border">
                    <h3 class="box-title">Spending by source</h3>
                </div>
                <div class="box-body">
                    <div class="row">
                        @foreach($summary_mtd as $i => $b)
                            @php $ytd = $summary_ytd[$i]['total']; @endphp
                            <div class="col-md-3 col-sm-6">
                                <div style="padding:12px 14px; background:#f8fafc; border:1px solid #e5e7eb; border-radius:6px; margin-bottom:10px;">
                                    <div style="font-size:13px; color:#475569; margin-bottom:4px;">
                                        <strong>{{ $b['label'] }}</strong>
                                    </div>
                                    <div style="font-size:20px; font-weight:600; color:#0f172a;">
                                        ${{ number_format($b['total'], 2) }}
                                    </div>
                                    <div style="font-size:11px; color:#94a3b8; margin-top:2px;">
                                        this month
                                    </div>
                                    <div style="border-top:1px dashed #e5e7eb; margin:8px 0;"></div>
                                    <div style="font-size:14px; color:#334155;">
                                        ${{ number_format($ytd, 2) }}
                                    </div>
                                    <div style="font-size:11px; color:#94a3b8;">
                                        year-to-date
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            @component('components.filters', ['title' => __('report.filters')])
          {!! Form::open(['url' => action('ReportController@getStockReport'), 'method' => 'get', 'id' => 'product_purchase_report_form' ]) !!}
            <div class="col-md-3">
                <div class="form-group">
                {!! Form::label('search_product', __('lang_v1.search_product') . ':') !!}
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="fa fa-search"></i>
                        </span>
                        <input type="hidden" value="" id="variation_id">
                        {!! Form::text('search_product', null, ['class' => 'form-control', 'id' => 'search_product', 'placeholder' => __('lang_v1.search_product_placeholder'), 'autofocus']); !!}
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('supplier_id', __('purchase.supplier') . ':') !!}
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="fa fa-user"></i>
                        </span>
                        {!! Form::select('supplier_id', $suppliers, null, ['class' => 'form-control select2', 'placeholder' => __('messages.please_select'), 'required']); !!}
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('location_id', __('purchase.business_location').':') !!}
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="fa fa-map-marker"></i>
                        </span>
                        {!! Form::select('location_id', $business_locations, null, ['class' => 'form-control select2', 'placeholder' => __('messages.please_select'), 'required']); !!}
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="form-group">

                    {!! Form::label('product_pr_date_filter', __('report.date_range') . ':') !!}
                    {!! Form::text('date_range', null, ['placeholder' => __('lang_v1.select_a_date_range'), 'class' => 'form-control', 'id' => 'product_pr_date_filter', 'readonly']); !!}
                </div>
            </div>

            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('ppr_brand_id', __('product.brand').':') !!}
                    {!! Form::select('ppr_brand_id', $brands, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.all')]); !!}
                </div>
            </div>
            {!! Form::close() !!}
            @endcomponent
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary'])
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" 
                    id="product_purchase_report_table">
                        <thead>
                            <tr>
                                <th>@lang('sale.product')</th>
                                <th>@lang('product.sku')</th>
                                <th>@lang('purchase.supplier')</th>
                                <th>@lang('purchase.ref_no')</th>
                                <th>@lang('messages.date')</th>
                                <th>@lang('sale.qty')</th>
                                <th>@lang('lang_v1.total_unit_adjusted')</th>
                                <th>@lang('lang_v1.unit_perchase_price')</th>
                                <th>@lang('sale.subtotal')</th>
                            </tr>
                        </thead>
                        <tfoot>
                            <tr class="bg-gray font-17 footer-total text-center">
                                <td colspan="5"><strong>@lang('sale.total'):</strong></td>
                                <td id="footer_total_purchase"></td>
                                <td id="footer_total_adjusted"></td>
                                <td></td>
                                <td><span class="display_currency" id="footer_subtotal" data-currency_symbol ="true"></span></td>
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