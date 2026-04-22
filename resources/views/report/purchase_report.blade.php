@extends('layouts.app')
@section('title', 'Purchases by Store')

@section('content')
{{-- Nivessa cream/brown palette — matches POS checkout v2 (Sarah 2026-04-22
     "redo the UI to look like create pos"). Scoped under body.pr-v2 so the
     reskin doesn't bleed to other reports. --}}
<script>document.body.classList.add('pr-v2');</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    body.pr-v2 {
        --pr-bg: #FAF6EE;
        --pr-surface: #FFFFFF;
        --pr-surface-2: #F7F1E3;
        --pr-ink: #1F1B16;
        --pr-ink-2: #5A5045;
        --pr-ink-3: #8E8273;
        --pr-line: #ECE3CF;
        --pr-line-2: #DFD2B3;
        --pr-accent: #FFF2B3;
        --pr-accent-deep: #E8CF68;
        --pr-accent-text: #5A4410;
        --pr-success: #2F6B3E;
        --pr-danger:  #8A3A2E;
        background: var(--pr-bg);
        font-family: "Inter Tight", system-ui, sans-serif;
        color: var(--pr-ink);
    }
    body.pr-v2 .content-header h1,
    body.pr-v2 .content h1,
    body.pr-v2 .content h3,
    body.pr-v2 .content h4,
    body.pr-v2 .content label,
    body.pr-v2 .content .control-label { color: var(--pr-ink); font-family: inherit; }
    body.pr-v2 .content-header h1 small { color: var(--pr-ink-3) !important; }

    body.pr-v2 .box { background: var(--pr-surface) !important; border: 1px solid var(--pr-line) !important; border-radius: 10px !important; box-shadow: 0 1px 2px rgba(31,27,22,.06) !important; }
    body.pr-v2 .box-header { background: var(--pr-surface-2) !important; border-bottom: 1px solid var(--pr-line) !important; border-radius: 10px 10px 0 0 !important; color: var(--pr-ink-2) !important; }

    body.pr-v2 .form-control { border: 1px solid var(--pr-line-2); background: #fff; color: var(--pr-ink); border-radius: 8px; }
    body.pr-v2 .form-control:focus { border-color: var(--pr-accent-deep); box-shadow: 0 0 0 3px rgba(232,207,104,.25); outline: none; }
    body.pr-v2 .select2-container--default .select2-selection--single { border: 1px solid var(--pr-line-2); border-radius: 8px; height: 36px; }

    body.pr-v2 .btn-primary { background: var(--pr-ink); border-color: var(--pr-ink); color: #fff; border-radius: 8px; font-weight: 600; }
    body.pr-v2 .btn-primary:hover { background: var(--pr-ink-2); border-color: var(--pr-ink-2); }
    body.pr-v2 .btn-success { background: var(--pr-success); border-color: var(--pr-success); color: #fff; border-radius: 8px; font-weight: 600; }
    body.pr-v2 .btn-default { background: #fff; border: 1px solid var(--pr-line-2); color: var(--pr-ink-2); border-radius: 8px; }

    body.pr-v2 table.table thead th { background: var(--pr-surface-2); color: var(--pr-ink-2); border-bottom: 1px solid var(--pr-line-2); font-weight: 700; text-transform: uppercase; font-size: 11px; letter-spacing: .04em; }
    body.pr-v2 table.table tbody td { color: var(--pr-ink); border-top: 1px solid var(--pr-line); font-variant-numeric: tabular-nums; }
    body.pr-v2 table.table tbody tr:nth-child(odd) td { background: #FDF9EF; }

    <!-- Summary cards (Pico / Hollywood) — cream palette -->
    .pr-summary-wrap { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 22px; }
    body.pr-v2 .pr-summary-card {
        flex: 1 1 380px; min-width: 320px;
        background: var(--pr-surface);
        border: 1px solid var(--pr-line);
        border-radius: 14px;
        box-shadow: 0 2px 6px rgba(31,27,22,.05);
        padding: 18px 20px;
        position: relative;
    }
    body.pr-v2 .pr-summary-card::before {
        content: '';
        position: absolute; top: 0; left: 0; right: 0; height: 4px;
        background: var(--pr-accent); border-radius: 14px 14px 0 0;
    }
    body.pr-v2 .pr-summary-card h4 {
        margin: 6px 0 12px; font-size: 15px; font-weight: 800;
        letter-spacing: .06em; text-transform: uppercase; color: var(--pr-ink);
        display: flex; align-items: baseline; justify-content: space-between; gap: 10px;
    }
    body.pr-v2 .pr-summary-card .pr-loc-sub { font-size: 11px; color: var(--pr-ink-3); font-weight: 500; text-transform: none; letter-spacing: 0; }
    .pr-summary-stats { display: flex; gap: 12px; margin-bottom: 14px; }
    body.pr-v2 .pr-summary-stat {
        flex: 1; padding: 10px 12px;
        background: var(--pr-surface-2); border: 1px solid var(--pr-line);
        border-radius: 10px;
    }
    body.pr-v2 .pr-summary-stat .pr-label {
        font-size: 10px; text-transform: uppercase; letter-spacing: .08em;
        color: var(--pr-ink-3); font-weight: 700;
    }
    body.pr-v2 .pr-summary-stat .pr-value {
        font-size: 22px; font-weight: 800; color: var(--pr-ink);
        font-variant-numeric: tabular-nums; margin-top: 2px;
    }
    body.pr-v2 .pr-summary-card .pr-top-label {
        font-size: 10px; text-transform: uppercase; letter-spacing: .08em;
        color: var(--pr-ink-3); font-weight: 700; margin: 14px 0 6px;
        padding-bottom: 4px; border-bottom: 1px solid var(--pr-line);
    }
    body.pr-v2 .pr-top-item {
        display: flex; justify-content: space-between; gap: 12px;
        padding: 6px 0; font-size: 13px;
        border-bottom: 1px solid var(--pr-line);
    }
    body.pr-v2 .pr-top-item:last-child { border-bottom: none; }
    body.pr-v2 .pr-top-item .pr-name { color: var(--pr-ink); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 500; }
    body.pr-v2 .pr-top-item .pr-qty { color: var(--pr-ink-2); font-variant-numeric: tabular-nums; white-space: nowrap; font-weight: 600; }
    body.pr-v2 .pr-summary-empty { padding: 12px 0; font-size: 12px; color: var(--pr-ink-3); text-align: center; font-style: italic; }

    <!-- Channel chips — distributor / walk-in / bins summary row -->
    body.pr-v2 .pr-channels { display: flex; gap: 8px; flex-wrap: wrap; margin: 10px 0 14px; }
    body.pr-v2 .pr-channel-chip {
        background: var(--pr-accent-soft, #FFF9DB); border: 1px solid var(--pr-accent-deep);
        border-radius: 999px; padding: 5px 11px;
        font-size: 11px; font-weight: 600; color: var(--pr-accent-text);
        font-variant-numeric: tabular-nums;
        display: inline-flex; align-items: center; gap: 6px;
    }
    body.pr-v2 .pr-channel-chip .pr-chip-label { font-weight: 700; text-transform: uppercase; letter-spacing: .04em; font-size: 10px; }
    body.pr-v2 .pr-channel-chip .pr-chip-dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%; background: var(--pr-ink); }
    body.pr-v2 .pr-channel-chip.pr-chip-walkin .pr-chip-dot { background: #2F6B3E; }
    body.pr-v2 .pr-channel-chip.pr-chip-bin .pr-chip-dot { background: #8E8273; }
</style>

<!-- Content Header (Page header) -->
<section class="content-header no-print">
    <h1>Purchases by Store
        <small style="font-size:13px; color:#8E8273;">— how much each location spent on inventory, and what they bought</small>
    </h1>
</section>

<!-- Main content -->
<section class="content no-print">
    {{-- Side-by-side store comparison (Sarah / Sabina's ask 2026-04-21):
         a card per business location showing $ spent + purchase count +
         top products in the selected date range. Respects every filter
         above — whenever the DataTable reloads, so does this summary. --}}
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
                @php
                    // Default to this month so the cards don't show lifetime totals
                    // (Sarah 2026-04-22 — "this is not helping"; $376K lifetime
                    // isn't actionable, $X for April is). Cashier can clear
                    // via the daterangepicker's Cancel button to see lifetime.
                    $defaultStart = \Carbon::now()->startOfMonth()->format('m/d/Y');
                    $defaultEnd   = \Carbon::now()->format('m/d/Y');
                    $defaultRange = $defaultStart . ' ~ ' . $defaultEnd;
                @endphp
                {!! Form::text('purchase_list_filter_date_range', $defaultRange, ['placeholder' => __('lang_v1.select_a_date_range'), 'class' => 'form-control', 'readonly', 'id' => 'purchase_list_filter_date_range']) !!}
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
                    // Prefer the daterangepicker data if initialized;
                    // otherwise parse the prefilled MM/DD/YYYY range so the
                    // initial table load respects this-month default.
                    var raw = ($('#purchase_list_filter_date_range').val() || '').trim();
                    var dp = $('input#purchase_list_filter_date_range').data('daterangepicker');
                    if (dp) {
                        start = dp.startDate.format('YYYY-MM-DD');
                        end = dp.endDate.format('YYYY-MM-DD');
                    } else if (raw.indexOf(' ~ ') !== -1) {
                        var parts = raw.split(' ~ ');
                        var toIso = function (s) {
                            var m = s.trim().split('/');
                            if (m.length === 3) return m[2] + '-' + m[0].padStart(2,'0') + '-' + m[1].padStart(2,'0');
                            return '';
                        };
                        start = toIso(parts[0]);
                        end = toIso(parts[1]);
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
            } else {
                // Daterangepicker hasn't initialized yet (first paint) — parse
                // the default value out of the text input so the initial summary
                // call uses this-month instead of falling back to lifetime.
                var raw = ($('#purchase_list_filter_date_range').val() || '').trim();
                if (raw.indexOf(' ~ ') !== -1) {
                    var parts = raw.split(' ~ ');
                    // Input format is MM/DD/YYYY, API wants YYYY-MM-DD.
                    var toIso = function (s) {
                        var m = s.trim().split('/');
                        if (m.length === 3) return m[2] + '-' + m[0].padStart(2,'0') + '-' + m[1].padStart(2,'0');
                        return '';
                    };
                    p.start_date = toIso(parts[0]);
                    p.end_date = toIso(parts[1]);
                }
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
                // Helper: HTML-escape arbitrary text before injection.
                var esc = function (s) { return $('<div>').text(s == null ? '' : String(s)).html(); };
                // Always format with thousand separators — Sarah 2026-04-22:
                // "commas by the numbers, had to read it all". $45303.37 is
                // hard to scan; $45,303.37 is not.
                var money = function (n) {
                    var num = parseFloat(n || 0);
                    return '$' + num.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                };
                var intFmt = function (n) {
                    return parseInt(n || 0, 10).toLocaleString('en-US');
                };

                var html = '';
                locs.forEach(function (loc) {
                    var total = money(loc.total_spent);
                    var beforeTax = money(loc.total_before_tax);
                    var count = parseInt(loc.purchase_count || 0, 10);
                    var distinct = parseInt(loc.distinct_products || 0, 10);

                    // Top real products — ordered by $ spent server-side, with
                    // bulk-bin SKUs stripped out so Thriller / SZA / Kanye
                    // actually show instead of "DISCOUNT BIN ($1)" flooding.
                    var top = (loc.top_products || []).map(function (p) {
                        var name = (p.artist ? (p.artist + ' — ') : '') + (p.name || '');
                        return '<div class="pr-top-item">'
                            + '<span class="pr-name" title="' + esc(name) + '">' + esc(name) + '</span>'
                            + '<span class="pr-qty">' + money(p.spent) + ' · qty ' + intFmt(p.qty) + '</span>'
                            + '</div>';
                    }).join('');
                    if (!top) top = '<div class="pr-summary-empty">No line-item products in this range.</div>';

                    // Bulk-bin summary line — keeps bin spend visible without
                    // hogging the top-products slot.
                    var bin = loc.bin_summary || {qty: 0, spent: 0};
                    var binRow = (bin.qty > 0 || bin.spent > 0) ? (
                        '<div class="pr-top-item" style="margin-top:4px;">'
                        + '<span class="pr-name" style="font-style:italic;color:#8E8273;">Bulk / clearance bins</span>'
                        + '<span class="pr-qty">' + money(bin.spent) + ' · qty ' + intFmt(bin.qty) + '</span>'
                        + '</div>'
                    ) : '';

                    // Top suppliers — usually the more useful cut.
                    var suppliers = (loc.top_suppliers || []).map(function (s) {
                        var nm = s.supplier_business_name || s.name || '—';
                        var cnt = parseInt(s.purchase_count || 0, 10);
                        return '<div class="pr-top-item">'
                            + '<span class="pr-name" title="' + esc(nm) + '">' + esc(nm) + '</span>'
                            + '<span class="pr-qty">' + money(s.spent) + ' · ' + cnt + ' PO' + (cnt === 1 ? '' : 's') + '</span>'
                            + '</div>';
                    }).join('');
                    if (!suppliers) suppliers = '<div class="pr-summary-empty">No supplier activity.</div>';

                    // Channel chips — distributor vs walk-in vs bins. Sarah
                    // 2026-04-22 asked to surface in-store collection buys
                    // separately from distributor invoices. Walk-in rollups
                    // come from the server under loc.walkin_summary.
                    var walkin = loc.walkin_summary || {count: 0, spent: 0};
                    var distrib = loc.distributor_summary || {count: 0, spent: 0};
                    var chips = ''
                        + '<div class="pr-channels">'
                        + '  <span class="pr-channel-chip"><span class="pr-chip-dot"></span><span class="pr-chip-label">Distributors</span>' + money(distrib.spent) + ' · ' + intFmt(distrib.count) + ' PO' + (distrib.count === 1 ? '' : 's') + '</span>'
                        + '  <span class="pr-channel-chip pr-chip-walkin"><span class="pr-chip-dot"></span><span class="pr-chip-label">Walk-in buys</span>' + money(walkin.spent) + ' · ' + intFmt(walkin.count) + ' buy' + (walkin.count === 1 ? '' : 's') + '</span>'
                        + '  <span class="pr-channel-chip pr-chip-bin"><span class="pr-chip-dot"></span><span class="pr-chip-label">Bulk bins</span>' + money(bin.spent) + ' · qty ' + intFmt(bin.qty) + '</span>'
                        + '</div>';

                    html += ''
                        + '<div class="pr-summary-card">'
                        + '  <h4><span>' + esc(loc.location_name || '—') + '</span>'
                        + '      <span class="pr-loc-sub">' + intFmt(count) + ' purchase' + (count === 1 ? '' : 's')
                        + '        · ' + intFmt(distinct) + ' unique product' + (distinct === 1 ? '' : 's') + '</span></h4>'
                        + '  <div class="pr-summary-stats">'
                        + '    <div class="pr-summary-stat"><div class="pr-label">Total spent</div><div class="pr-value">' + total + '</div></div>'
                        + '    <div class="pr-summary-stat"><div class="pr-label">Before tax</div><div class="pr-value">' + beforeTax + '</div></div>'
                        + '  </div>'
                        + chips
                        + '  <div class="pr-top-label">Top suppliers</div>'
                        + suppliers
                        + '  <div class="pr-top-label" style="margin-top:10px;">Top products bought ($-ranked, bins excluded)</div>'
                        + top
                        + binRow
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

        // Override the global dateRangeSettings (which defaults to
        // financial_year = 01/01 – 12/31) so this report opens with the
        // current month — the useful default for a purchasing review.
        // Keep all the preset ranges + locale from the global, just
        // swap the startDate/endDate.
        var purchaseReportDateRangeSettings = $.extend({}, dateRangeSettings, {
            startDate: moment().startOf('month'),
            endDate: moment(),
        });
        $('#purchase_list_filter_date_range').daterangepicker(
            purchaseReportDateRangeSettings,
            function (start, end) {
                $('#purchase_list_filter_date_range').val(start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format));
               purchase_report_table.ajax.reload();
               refreshPurchaseSummary();
            }
        );
        // Ensure the input text actually shows the this-month default
        // immediately on page load (the widget doesn't fire the callback
        // for its initial value).
        (function () {
            var dp = $('#purchase_list_filter_date_range').data('daterangepicker');
            if (dp) {
                $('#purchase_list_filter_date_range').val(
                    dp.startDate.format(moment_date_format) + ' ~ ' + dp.endDate.format(moment_date_format)
                );
            }
        })();
        $('#purchase_list_filter_date_range').on('cancel.daterangepicker', function(ev, picker) {
            $('#purchase_list_filter_date_range').val('');
            purchase_report_table.ajax.reload();
            refreshPurchaseSummary();
        });
    });
</script>
	
@endsection