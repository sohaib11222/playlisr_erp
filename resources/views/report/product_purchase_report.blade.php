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
            <style>
                /* Purchase report — POS-styled summary panel. Cream/amber palette
                   matches /pos/create so reports feel like part of the same family. */
                .ppr-panel {
                    background: #FAF6EE;
                    border: 1px solid #DFD2B3;
                    border-radius: 12px;
                    padding: 18px;
                    margin-bottom: 18px;
                    box-shadow: 0 1px 0 rgba(31, 27, 22, 0.04);
                }
                .ppr-panel-head {
                    display: flex; align-items: baseline; justify-content: space-between;
                    margin-bottom: 12px; gap: 12px; flex-wrap: wrap;
                }
                .ppr-panel-title {
                    font-weight: 800; color: #1F1B16; font-size: 13px;
                    text-transform: uppercase; letter-spacing: 0.14em;
                }
                .ppr-panel-meta {
                    font-size: 12px; color: #5A4410; opacity: 0.85;
                }

                /* Hero mustard budget bar — same family as POS Pre-Tax → Clover. */
                .ppr-budget-bar {
                    position: relative;
                    background: #FFF2B3;
                    border: 2px solid #F0DC7A;
                    border-radius: 10px;
                    padding: 16px 18px 14px;
                    margin-bottom: 16px;
                    box-shadow: 0 0 0 3px rgba(255, 242, 179, 0.4);
                }
                .ppr-budget-bar::before {
                    content: "THIS WEEK'S BUDGET";
                    position: absolute; top: -9px; left: 14px;
                    background: #1F1B16; color: #FFF2B3;
                    font-size: 9px; font-weight: 800;
                    letter-spacing: 0.14em; padding: 3px 9px;
                    border-radius: 999px; line-height: 1.2;
                }
                .ppr-budget-row {
                    display: flex; align-items: center; justify-content: space-between;
                    gap: 14px; flex-wrap: wrap; margin-bottom: 8px;
                }
                .ppr-budget-left {
                    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
                }
                .ppr-status-pill {
                    display: inline-flex; align-items: center; gap: 6px;
                    padding: 4px 12px; border-radius: 999px;
                    font-weight: 700; font-size: 11px;
                    text-transform: uppercase; letter-spacing: 0.1em;
                }
                .ppr-status-pill::before {
                    content: ""; width: 7px; height: 7px; border-radius: 50%;
                    background: currentColor;
                }
                .ppr-status-ok    { background: #DCFCE7; color: #166534; border: 1px solid #BBF7D0; }
                .ppr-status-warn  { background: #FEF3C7; color: #92400E; border: 1px solid #FDE68A; }
                .ppr-status-over  { background: #FEE2E2; color: #991B1B; border: 1px solid #FECACA; }
                .ppr-budget-week {
                    color: #5A4410; font-weight: 700;
                    font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em;
                }
                .ppr-budget-week small { font-weight: 500; opacity: 0.75; }
                .ppr-budget-amounts {
                    color: #5A4410; font-weight: 800;
                    font-size: 22px; line-height: 1.1;
                    font-variant-numeric: tabular-nums; letter-spacing: -0.01em;
                    white-space: nowrap;
                }
                .ppr-budget-amounts .of {
                    color: #8B6914; font-size: 14px; font-weight: 600;
                    padding: 0 4px;
                }
                .ppr-budget-amounts .target {
                    color: #5A4410; font-size: 18px; font-weight: 700;
                }
                .ppr-budget-amounts .pct {
                    color: #8B6914; font-size: 13px; font-weight: 600;
                    margin-left: 4px;
                }
                .ppr-budget-remaining {
                    font-size: 13px; font-weight: 600;
                    font-variant-numeric: tabular-nums;
                }
                .ppr-budget-remaining.left { color: #5A4410; }
                .ppr-budget-remaining.over { color: #991B1B; }
                .ppr-progress {
                    height: 12px; background: rgba(31, 27, 22, 0.08);
                    border-radius: 999px; overflow: hidden;
                }
                .ppr-progress > div {
                    height: 100%; border-radius: 999px;
                    transition: width 0.3s ease;
                }
                .ppr-bar-ok   { background: linear-gradient(90deg, #4ADE80, #16A34A); }
                .ppr-bar-warn { background: linear-gradient(90deg, #FBBF24, #D97706); }
                .ppr-bar-over { background: linear-gradient(90deg, #F87171, #DC2626); }

                /* Total spending — receipt-style grand total. */
                .ppr-total {
                    display: flex; align-items: baseline; justify-content: space-between;
                    gap: 12px; flex-wrap: wrap;
                    padding: 14px 0;
                    border-top: 2px dashed #DFD2B3;
                    border-bottom: 2px dashed #DFD2B3;
                    margin-bottom: 14px;
                }
                .ppr-total-label {
                    font-weight: 800; color: #1F1B16;
                    font-size: 13px; text-transform: uppercase; letter-spacing: 0.14em;
                }
                .ppr-total-figs {
                    display: flex; gap: 22px; flex-wrap: wrap;
                }
                .ppr-total-fig {
                    display: flex; flex-direction: column; align-items: flex-end;
                }
                .ppr-total-fig .amt {
                    color: #1F1B16; font-weight: 800;
                    font-size: 24px; line-height: 1.1;
                    font-variant-numeric: tabular-nums; letter-spacing: -0.01em;
                }
                .ppr-total-fig .lbl {
                    font-size: 10px; font-weight: 700;
                    text-transform: uppercase; letter-spacing: 0.12em;
                    color: #8B6914; margin-top: 2px;
                }

                /* Source breakdown tiles — same cream family. */
                .ppr-sources {
                    display: grid;
                    grid-template-columns: repeat(4, 1fr);
                    gap: 10px;
                }
                @media (max-width: 900px) {
                    .ppr-sources { grid-template-columns: repeat(2, 1fr); }
                }
                .ppr-source {
                    background: #fff;
                    border: 1px solid #DFD2B3;
                    border-radius: 8px;
                    padding: 12px 14px;
                }
                .ppr-source-label {
                    font-size: 11px; font-weight: 700; color: #5A4410;
                    text-transform: uppercase; letter-spacing: 0.1em;
                    margin-bottom: 6px;
                }
                .ppr-source-amt {
                    color: #1F1B16; font-weight: 800;
                    font-size: 18px; line-height: 1.1;
                    font-variant-numeric: tabular-nums; letter-spacing: -0.01em;
                }
                .ppr-source-sub {
                    font-size: 10px; font-weight: 600;
                    text-transform: uppercase; letter-spacing: 0.1em;
                    color: #8B6914; margin-top: 2px;
                }
                .ppr-source-divider {
                    border-top: 1px dashed #E8DCBF; margin: 8px 0;
                }
                .ppr-source-ytd {
                    color: #3F2F12; font-weight: 700;
                    font-size: 14px; font-variant-numeric: tabular-nums;
                }
            </style>
            <div class="ppr-panel">
                <div class="ppr-panel-head">
                    <span class="ppr-panel-title">Purchasing summary</span>
                    <span class="ppr-panel-meta">As of {{ \Carbon::now()->format('M j, Y') }}</span>
                </div>

                @if($current_week_budget)
                    @php
                        $budget = (float) $current_week_budget['budget'];
                        $actual = (float) $current_week_actual;
                        $remaining = $budget - $actual;
                        $pct = $budget > 0 ? ($actual / $budget) * 100 : 0;
                        $bar_w = min(100, max(0, $pct));
                        $over = $actual > $budget;
                        if ($over)          { $bar_class = 'ppr-bar-over'; $pill_class = 'ppr-status-over'; $pill_label = 'Over budget'; }
                        elseif ($pct >= 90) { $bar_class = 'ppr-bar-warn'; $pill_class = 'ppr-status-warn'; $pill_label = 'Near budget'; }
                        else                { $bar_class = 'ppr-bar-ok';   $pill_class = 'ppr-status-ok';   $pill_label = 'Within budget'; }
                    @endphp
                    <div class="ppr-budget-bar">
                        <div class="ppr-budget-row">
                            <div class="ppr-budget-left">
                                <span class="ppr-status-pill {{ $pill_class }}">{{ $pill_label }}</span>
                                <span class="ppr-budget-week">
                                    Week {{ $current_week_budget['week_no'] }} of 13
                                    <small>· {{ \Carbon::parse($current_week_budget['start'])->format('M j') }}–{{ \Carbon::parse($current_week_budget['end'])->format('M j') }}</small>
                                </span>
                            </div>
                            <span class="ppr-budget-remaining {{ $over ? 'over' : 'left' }}">
                                {{ $over ? '$' . number_format(abs($remaining), 2) . ' over' : '$' . number_format($remaining, 2) . ' left' }}
                            </span>
                        </div>
                        <div class="ppr-budget-amounts">
                            ${{ number_format($actual, 2) }}
                            <span class="of">of</span>
                            <span class="target">${{ number_format($budget, 2) }}</span>
                            <span class="pct">({{ number_format($pct, 0) }}%)</span>
                        </div>
                        <div class="ppr-progress" style="margin-top:10px;">
                            <div class="{{ $bar_class }}" style="width: {{ $bar_w }}%;"></div>
                        </div>
                    </div>
                @endif

                <div class="ppr-total">
                    <span class="ppr-total-label">Total spending · all sources</span>
                    <div class="ppr-total-figs">
                        <div class="ppr-total-fig">
                            <span class="amt">${{ number_format($total_mtd, 2) }}</span>
                            <span class="lbl">Month-to-date</span>
                        </div>
                        <div class="ppr-total-fig">
                            <span class="amt">${{ number_format($total_ytd, 2) }}</span>
                            <span class="lbl">Year-to-date</span>
                        </div>
                    </div>
                </div>

                <div class="ppr-sources">
                    @foreach($summary_mtd as $i => $b)
                        @php $ytd = $summary_ytd[$i]['total']; @endphp
                        <div class="ppr-source">
                            <div class="ppr-source-label">{{ $b['label'] }}</div>
                            <div class="ppr-source-amt">${{ number_format($b['total'], 2) }}</div>
                            <div class="ppr-source-sub">This month</div>
                            <div class="ppr-source-divider"></div>
                            <div class="ppr-source-ytd">${{ number_format($ytd, 2) }}</div>
                            <div class="ppr-source-sub">Year-to-date</div>
                        </div>
                    @endforeach
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
            <div class="col-md-3">
                <div class="form-group">
                    {!! Form::label('ppr_created_by', 'Added by:') !!}
                    <div class="input-group">
                        <span class="input-group-addon">
                            <i class="fa fa-user-circle"></i>
                        </span>
                        {!! Form::select('ppr_created_by', $users, null, ['class' => 'form-control select2', 'id' => 'ppr_created_by', 'placeholder' => __('lang_v1.all')]); !!}
                    </div>
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
                                <th>@lang('product.category')</th>
                                <th>@lang('purchase.supplier')</th>
                                <th>@lang('purchase.ref_no')</th>
                                <th>@lang('messages.date')</th>
                                <th>Added by</th>
                                <th>Paid with</th>
                                <th>@lang('sale.qty')</th>
                                <th>@lang('lang_v1.total_unit_adjusted')</th>
                                <th>@lang('lang_v1.unit_perchase_price')</th>
                                <th>@lang('sale.subtotal')</th>
                            </tr>
                        </thead>
                        <tfoot>
                            <tr class="bg-gray font-17 footer-total text-center">
                                <td colspan="8"><strong>@lang('sale.total'):</strong></td>
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

    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary'])
                @slot('title')
                    In-store buys
                    <small class="text-muted">— individual transactions from /buy-from-customer</small>
                @endslot
                <p class="text-muted" style="margin: 0 0 10px; font-size: 12px;">
                    Sourced from accepted buy-from-customer offers. These are excluded from the main report above to avoid double-counting.
                </p>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="in_store_buys_table">
                        <thead>
                            <tr>
                                <th>@lang('messages.date')</th>
                                <th>Record</th>
                                <th>Seller</th>
                                <th>Location</th>
                                <th>Cashier</th>
                                <th class="text-right">Items</th>
                                <th>Payout</th>
                                <th>Paid with</th>
                                <th class="text-right">@lang('lang_v1.total')</th>
                            </tr>
                        </thead>
                        <tfoot>
                            <tr class="bg-gray font-17 footer-total text-center">
                                <td colspan="5"><strong>@lang('sale.total'):</strong></td>
                                <td id="in_store_buys_footer_items"></td>
                                <td colspan="2"></td>
                                <td><span class="display_currency" id="in_store_buys_footer_total" data-currency_symbol="true"></span></td>
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
    <script>
        $(function () {
            // In-store buys table — feeds from /reports/in-store-buys-data,
            // mirrors product_purchase_report's date/location filter so the
            // two tables stay in sync as Sarah filters.
            var inStoreBuysTable = $('table#in_store_buys_table').DataTable({
                processing: true,
                serverSide: true,
                aaSorting: [[0, 'desc']],
                ajax: {
                    url: '/reports/in-store-buys-data',
                    data: function (d) {
                        var start = '';
                        var end = '';
                        if ($('#product_pr_date_filter').val()) {
                            start = $('input#product_pr_date_filter').data('daterangepicker').startDate.format('YYYY-MM-DD');
                            end = $('input#product_pr_date_filter').data('daterangepicker').endDate.format('YYYY-MM-DD');
                        }
                        d.start_date = start;
                        d.end_date = end;
                        d.location_id = $('select#location_id').val();
                    },
                },
                columns: [
                    { data: 'transaction_date', name: 't.transaction_date' },
                    { data: 'buy_record_number', name: 'o.buy_record_number' },
                    { data: 'contact_name', name: 'c.name' },
                    { data: 'location_name', name: 'bl.name' },
                    { data: 'cashier', name: 'u.first_name', orderable: false },
                    { data: 'line_count', name: 'line_count', searchable: false, orderable: false, className: 'text-right' },
                    { data: 'payout_type', name: 'o.payout_type' },
                    { data: 'payment_method_label', name: 'o.payment_method', orderable: false, searchable: false },
                    { data: 'final_total', name: 't.final_total', className: 'text-right' },
                ],
                fnDrawCallback: function () {
                    var totalItems = 0;
                    var totalAmount = 0;
                    $('#in_store_buys_table tbody tr').each(function () {
                        var $cells = $(this).find('td');
                        if ($cells.length < 9) return;
                        totalItems += parseFloat($cells.eq(5).text()) || 0;
                        var $amt = $cells.eq(8).find('.display_currency');
                        totalAmount += parseFloat($amt.data('orig-value') ?? $amt.text().replace(/[^0-9.\-]/g, '')) || 0;
                    });
                    $('#in_store_buys_footer_items').text(totalItems);
                    $('#in_store_buys_footer_total').attr('data-orig-value', totalAmount).text(totalAmount.toFixed(2));
                    __currency_convert_recursively($('#in_store_buys_table'));
                },
            });

            // Re-sync when the page-wide filters change.
            $(document).on('change',
                '#product_purchase_report_form #location_id, #product_purchase_report_form #product_pr_date_filter',
                function () {
                    inStoreBuysTable.ajax.reload();
                }
            );
            $(document).on('apply.daterangepicker', '#product_pr_date_filter', function () {
                inStoreBuysTable.ajax.reload();
            });
        });
    </script>
@endsection