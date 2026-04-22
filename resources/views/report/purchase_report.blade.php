@extends('layouts.app')
@section('title', 'Purchases by Store')

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header no-print">
    <h1>Purchases by Store
        <small style="font-size:13px; color:#6b7280;">— how much each location spent on inventory, and what they bought</small>
    </h1>
</section>

<!-- Main content -->
<section class="content no-print">
    {{-- Side-by-side store comparison (Sarah / Sabina's ask 2026-04-21):
         a card per business location showing $ spent + purchase count +
         top products in the selected date range. Respects every filter
         above — whenever the DataTable reloads, so does this summary. --}}
    <style>
        .pr-summary-wrap { display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 18px; }
        .pr-summary-card {
            flex: 1 1 300px; min-width: 300px;
            background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
            box-shadow: 0 1px 2px rgba(0,0,0,.04);
            padding: 14px 16px;
        }
        .pr-summary-card h4 {
            margin: 0 0 8px; font-size: 14px; font-weight: 700;
            letter-spacing: .02em; color: #1f2937;
            display: flex; align-items: baseline; justify-content: space-between;
        }
        .pr-summary-card .pr-loc-sub { font-size: 11px; color: #6b7280; font-weight: 500; }
        .pr-summary-stats { display: flex; gap: 14px; margin-bottom: 10px; }
        .pr-summary-stat {
            flex: 1; padding: 8px 10px;
            background: #f9fafb; border-radius: 6px;
        }
        .pr-summary-stat .pr-label {
            font-size: 10px; text-transform: uppercase; letter-spacing: .08em;
            color: #6b7280; font-weight: 600;
        }
        .pr-summary-stat .pr-value {
            font-size: 18px; font-weight: 700; color: #111827;
            font-variant-numeric: tabular-nums;
        }
        .pr-summary-card .pr-top-label {
            font-size: 10px; text-transform: uppercase; letter-spacing: .08em;
            color: #6b7280; font-weight: 600; margin-top: 4px; margin-bottom: 4px;
        }
        .pr-top-item {
            display: flex; justify-content: space-between; gap: 10px;
            padding: 4px 0; font-size: 12px;
            border-bottom: 1px solid #f3f4f6;
        }
        .pr-top-item:last-child { border-bottom: none; }
        .pr-top-item .pr-name { color: #1f2937; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pr-top-item .pr-qty { color: #6b7280; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .pr-summary-empty { padding: 10px 0; font-size: 12px; color: #9ca3af; text-align: center; }
    </style>
    <div class="pr-summary-wrap" id="pr-summary-wrap">
        <div class="pr-summary-empty" style="width:100%;">Loading side-by-side summary…</div>
    </div>

    @component('components.filters', ['title' => __('report.filters')])
        <div class="col-md-3">
            <div class="form-group">
                {!! Form::label('purchase_list_filter_location_id',  __('purchase.business_location') . ':') !!}
                {!! Form::select('purchase_list_filter_location_id', $business_locations, null, ['class' => 'form-control select2', 'style' => 'width:100%', 'placeholder' => __('lang_v1.all')]); !!}
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                {!! Form::label('purchase_list_filter_supplier_id',  __('purchase.supplier') . ':') !!}
                {!! Form::select('purchase_list_filter_supplier_id', $suppliers, null, ['class' => 'form-control select2', 'style' => 'width:100%', 'placeholder' => __('lang_v1.all')]); !!}
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                {!! Form::label('purchase_list_filter_status',  __('purchase.purchase_status') . ':') !!}
                {!! Form::select('purchase_list_filter_status', $orderStatuses, null, ['class' => 'form-control select2', 'style' => 'width:100%', 'placeholder' => __('lang_v1.all')]); !!}
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                {!! Form::label('purchase_list_filter_payment_status',  __('purchase.payment_status') . ':') !!}
                {!! Form::select('purchase_list_filter_payment_status', ['paid' => __('lang_v1.paid'), 'due' => __('lang_v1.due'), 'partial' => __('lang_v1.partial'), 'overdue' => __('lang_v1.overdue')], null, ['class' => 'form-control select2', 'style' => 'width:100%', 'placeholder' => __('lang_v1.all')]); !!}
            </div>
        </div>
        <div class="col-md-3">
            <div class="form-group">
                {!! Form::label('purchase_list_filter_date_range', __('report.date_range') . ':') !!}
                {!! Form::text('purchase_list_filter_date_range', null, ['placeholder' => __('lang_v1.select_a_date_range'), 'class' => 'form-control', 'readonly']); !!}
            </div>
        </div>
    @endcomponent

    {{-- Full export button — bypasses DataTables' 100-row page limit so
         Sabina gets every matching row in one CSV, not a slice. --}}
    <div style="margin-bottom:10px; text-align:right;">
        <button type="button" class="btn btn-success" id="pr-export-all-btn">
            <i class="fa fa-file-excel"></i> Export all (CSV)
        </button>
        <span id="pr-export-hint" style="margin-left:8px; font-size:12px; color:#6b7280;"></span>
    </div>

    @component('components.widget', ['class' => 'box-primary'])
        <div class="table-responsive">
    <table class="table table-bordered table-striped ajax_view" id="purchase_report_table">
        <thead>
            <tr>
                <th>Store</th>
                <th>@lang('lang_v1.contact_id')</th>
                <th>@lang('purchase.supplier')</th>
                <th>@lang('purchase.ref_no')</th>
                <th>@lang('purchase.purchase_date') (@lang('lang_v1.year_month'))</th>
                <th>@lang('purchase.purchase_date') (@lang('lang_v1.day'))</th>
                <th>@lang('lang_v1.payment_date') (@lang('lang_v1.year_month'))</th>
                <th>@lang('lang_v1.payment_date') (@lang('lang_v1.day'))</th>
                <th>@lang('sale.total') (@lang('product.exc_of_tax'))</th>
                <th>@lang('sale.tax')</th>
                <th>@lang('sale.total') (@lang('product.inc_of_tax'))</th>
            </tr>
        </thead>
    </table>
</div>
    @endcomponent

</section>

<section id="receipt_section" class="print_section"></section>

<!-- /.content -->
@stop
@section('javascript')

<script type="text/javascript">
    $(document).ready(function() {
        //Purchase report table
        purchase_report_table = $('#purchase_report_table').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '/reports/purchase-report',
                data: function(d) {
                    if ($('#purchase_list_filter_location_id').length) {
                        d.location_id = $('#purchase_list_filter_location_id').val();
                    }
                    if ($('#purchase_list_filter_supplier_id').length) {
                        d.supplier_id = $('#purchase_list_filter_supplier_id').val();
                    }
                    if ($('#purchase_list_filter_payment_status').length) {
                        d.payment_status = $('#purchase_list_filter_payment_status').val();
                    }
                    if ($('#purchase_list_filter_status').length) {
                        d.status = $('#purchase_list_filter_status').val();
                    }

                    var start = '';
                    var end = '';
                    if ($('#purchase_list_filter_date_range').val()) {
                        start = $('input#purchase_list_filter_date_range')
                            .data('daterangepicker')
                            .startDate.format('YYYY-MM-DD');
                        end = $('input#purchase_list_filter_date_range')
                            .data('daterangepicker')
                            .endDate.format('YYYY-MM-DD');
                    }
                    d.start_date = start;
                    d.end_date = end;

                    d = __datatable_ajax_callback(d);
                },
            },
            columns: [
                { data: 'location_name', name: 'BS.name' },
                { data: 'contact_id', name: 'contacts.contact_id' },
                { data: 'name', name: 'contacts.name' },
                { data: 'ref_no', name: 'ref_no' },
                { data: 'purchase_year_month', name: 'transaction_date' },
                { data: 'purchase_day', name: 'transaction_date' },
                { data: 'payment_year_month', searching: false },
                { data: 'payment_day', searching: false },
                { data: 'total_before_tax', name: 'total_before_tax' },
                { data: 'tax_amount', name: 'tax_amount' },
                { data: 'final_total', name: 'final_total' },
            ],
            fnDrawCallback: function(oSettings) {
                __currency_convert_recursively($('#purchase_report_table'));
            }
        });

        $(document).on(
            'change',
            '#purchase_list_filter_location_id, \
                        #purchase_list_filter_supplier_id, #purchase_list_filter_payment_status,\
                         #purchase_list_filter_status',
            function() {
                purchase_report_table.ajax.reload();
                refreshPurchaseSummary();
            }
        );

        // Side-by-side store summary — refreshes whenever filters change so
        // Sabina / Sarah can flip between date ranges and see HW vs Pico
        // updated in place.
        function currentFilterParams() {
            var p = {
                location_id: $('#purchase_list_filter_location_id').val() || '',
                supplier_id: $('#purchase_list_filter_supplier_id').val() || '',
                payment_status: $('#purchase_list_filter_payment_status').val() || '',
                status: $('#purchase_list_filter_status').val() || '',
                start_date: '',
                end_date: '',
            };
            if ($('#purchase_list_filter_date_range').val() && $('#purchase_list_filter_date_range').data('daterangepicker')) {
                p.start_date = $('#purchase_list_filter_date_range').data('daterangepicker').startDate.format('YYYY-MM-DD');
                p.end_date   = $('#purchase_list_filter_date_range').data('daterangepicker').endDate.format('YYYY-MM-DD');
            }
            return p;
        }
        function refreshPurchaseSummary() {
            var $wrap = $('#pr-summary-wrap');
            $wrap.html('<div class="pr-summary-empty" style="width:100%;">Loading side-by-side summary…</div>');
            $.get('/reports/purchase-report/summary', currentFilterParams()).done(function (resp) {
                var locs = (resp && resp.locations) || [];
                if (!locs.length) {
                    $wrap.html('<div class="pr-summary-empty" style="width:100%;">No purchases in this range.</div>');
                    return;
                }
                var html = '';
                locs.forEach(function (loc) {
                    var total = '$' + parseFloat(loc.total_spent || 0).toFixed(2);
                    var beforeTax = '$' + parseFloat(loc.total_before_tax || 0).toFixed(2);
                    var count = parseInt(loc.purchase_count || 0, 10);
                    var top = (loc.top_products || []).map(function (p) {
                        var name = (p.artist ? (p.artist + ' — ') : '') + (p.name || '');
                        var qty = 'qty ' + parseFloat(p.qty || 0).toFixed(0);
                        var spent = '$' + parseFloat(p.spent || 0).toFixed(2);
                        return '<div class="pr-top-item">'
                            + '<span class="pr-name" title="' + $('<div>').text(name).html() + '">' + $('<div>').text(name).html() + '</span>'
                            + '<span class="pr-qty">' + qty + ' · ' + spent + '</span>'
                            + '</div>';
                    }).join('');
                    if (!top) top = '<div class="pr-summary-empty">No line-item products (manual purchases only?)</div>';

                    html += ''
                        + '<div class="pr-summary-card">'
                        + '  <h4><span>' + $('<div>').text(loc.location_name || '—').html() + '</span>'
                        + '      <span class="pr-loc-sub">' + count + ' purchase' + (count === 1 ? '' : 's') + '</span></h4>'
                        + '  <div class="pr-summary-stats">'
                        + '    <div class="pr-summary-stat"><div class="pr-label">Total spent</div><div class="pr-value">' + total + '</div></div>'
                        + '    <div class="pr-summary-stat"><div class="pr-label">Before tax</div><div class="pr-value">' + beforeTax + '</div></div>'
                        + '  </div>'
                        + '  <div class="pr-top-label">Top products bought</div>'
                        + top
                        + '</div>';
                });
                $wrap.html(html);
            }).fail(function () {
                $wrap.html('<div class="pr-summary-empty" style="width:100%; color:#b91c1c;">Failed to load summary. Check your filters and retry.</div>');
            });
        }
        refreshPurchaseSummary();

        // Export-all button — streams a full CSV of every matching row
        // (not just the current 100-row DataTable page). Uses the same
        // filter params the table + summary use.
        $(document).on('click', '#pr-export-all-btn', function () {
            var $btn = $(this);
            var $hint = $('#pr-export-hint');
            var original = $btn.html();
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Preparing CSV…');
            $hint.text('Large date ranges can take a minute.');
            var qs = $.param(currentFilterParams());
            window.location.href = '/reports/purchase-report/export?' + qs;
            setTimeout(function () {
                $btn.prop('disabled', false).html(original);
                $hint.text('');
            }, 4000);
        });

        $('#purchase_list_filter_date_range').daterangepicker(
            dateRangeSettings,
            function (start, end) {
                $('#purchase_list_filter_date_range').val(start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format));
               purchase_report_table.ajax.reload();
               refreshPurchaseSummary();
            }
        );
        $('#purchase_list_filter_date_range').on('cancel.daterangepicker', function(ev, picker) {
            $('#purchase_list_filter_date_range').val('');
            purchase_report_table.ajax.reload();
            refreshPurchaseSummary();
        });
    });
</script>
	
@endsection