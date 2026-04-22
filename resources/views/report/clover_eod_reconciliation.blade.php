@extends('layouts.app')
@section('title', 'Clover EOD Reconciliation')

@section('content')
<section class="content-header no-print">
    <h1>Clover EOD Reconciliation <small>ERP card sales vs Clover settlements, day by day</small></h1>
</section>

<section class="content no-print">
    {{-- Filters: date range + location --}}
    @component('components.filters', ['title' => __('report.filters')])
        <div class="col-md-4">
            <div class="form-group">
                <label>Date range:</label>
                {!! Form::text('eod_date_range', $start . ' ~ ' . $end, [
                    'class' => 'form-control', 'id' => 'eod_date_range',
                    'placeholder' => 'Select a date range', 'readonly',
                ]) !!}
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-group">
                <label>Location:</label>
                {!! Form::select('location_id', $business_locations, $location_id, [
                    'class' => 'form-control select2', 'id' => 'eod_location_id',
                    'placeholder' => 'All locations', 'style' => 'width:100%'
                ]) !!}
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-group">
                <label>&nbsp;</label><br>
                <button type="button" class="btn btn-primary" id="eod_apply_btn">Apply</button>
            </div>
        </div>
    @endcomponent

    {{-- Sync-now button — Sarah 2026-04-22: Clover column was $0 across
         every day because the scheduled clover:sync-payments wasn't
         running (or credentials aren't set). This button fires the
         same command on demand and prints the raw stdout so a failed
         API call / missing creds / zero-payment day are all visible
         instead of buried in a log file. Admin-only on the backend. --}}
    <div style="margin-bottom:12px; text-align:right;">
        <select id="eod_sync_days" class="form-control" style="display:inline-block; width:auto; vertical-align:middle; margin-right:4px;">
            <option value="2" selected>Last 2 days</option>
            <option value="7">Last 7 days</option>
            <option value="30">Last 30 days (backfill)</option>
            <option value="90">Last 90 days (backfill)</option>
        </select>
        <button type="button" class="btn btn-default" id="eod_sync_now_btn">
            <i class="fa fa-sync"></i> Sync Clover now
        </button>
        <span id="eod_sync_status" style="margin-left:8px; font-size:12px; color:#6b7280;"></span>
    </div>
    <div id="eod_sync_output" style="display:none; margin-bottom:14px;">
        <div style="background:#111827; color:#e5e7eb; padding:12px 14px; border-radius:8px; font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace; font-size:12px; white-space:pre-wrap; max-height:260px; overflow:auto;" id="eod_sync_output_pre"></div>
    </div>

    {{-- Grand totals banner --}}
    @php
        $variance = round($grand['erp'] - $grand['clover'], 2);
        $variance_abs = abs($variance);
        $banner_class = $variance_abs < 1.00 ? 'success' : ($variance_abs < 10.00 ? 'warning' : 'danger');
        $banner_msg = $variance_abs < 1.00
            ? 'Reconciled — ERP and Clover match for this range.'
            : ($variance_abs < 10.00 ? 'Minor variance (< $10).' : 'Material variance — review flagged days below.');
    @endphp
    <div class="alert alert-{{ $banner_class }}" style="margin-bottom:16px;">
        <strong>{{ $banner_msg }}</strong>
        &nbsp;
        ERP card sales: <strong>${{ number_format($grand['erp'], 2) }}</strong>
        &nbsp;·&nbsp;
        Clover settlements: <strong>${{ number_format($grand['clover'], 2) }}</strong>
        &nbsp;·&nbsp;
        Variance: <strong>${{ number_format($variance, 2) }}</strong>
        @if($grand['flagged_days'] > 0)
            &nbsp;·&nbsp; <span>{{ $grand['flagged_days'] }} day(s) flagged</span>
        @endif
    </div>

    {{-- Per-cashier side-by-side breakdown — mirrors Sarah's daily xlsx
         (PICO on the left, HOLLYWOOD on the right, Employee / Clover / ERP /
         Diff per row). Only rendered for single-day selections since that's
         how her reconciliation ritual works. Multi-day ranges still show the
         rollup table below. --}}
    @if(!empty($employee_breakdown))
        <style>
            .eod-loc-wrap { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 18px; }
            .eod-loc-card { flex: 1 1 420px; min-width: 420px; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 14px; }
            .eod-loc-card h3 { margin: 0 0 8px; font-size: 15px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #111827; }
            .eod-loc-card table { width: 100%; font-size: 13px; border-collapse: collapse; }
            .eod-loc-card th { text-align: left; color: #6b7280; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; border-bottom: 1px solid #e5e7eb; padding: 5px 6px; }
            .eod-loc-card td { padding: 6px; border-bottom: 1px solid #f3f4f6; font-variant-numeric: tabular-nums; }
            .eod-loc-card td.num { text-align: right; }
            .eod-loc-card tr.totals td { border-top: 2px solid #d1d5db; border-bottom: none; font-weight: 700; background: #f9fafb; }
            .eod-diff-ok { color: #166534; }
            .eod-diff-warn { color: #b45309; }
            .eod-diff-bad { color: #b91c1c; }
            .eod-loc-empty { color: #9ca3af; font-size: 12px; padding: 8px 0; text-align: center; }
        </style>
        <h4 style="margin: 4px 0 10px; font-size: 13px; color: #6b7280; font-weight: 600;">Per-cashier breakdown for {{ $start }}</h4>
        <div class="eod-loc-wrap">
            @foreach($employee_breakdown as $loc)
                @php
                    $ldiff = $loc['totals']['difference'];
                    $lcls  = abs($ldiff) < 1 ? 'eod-diff-ok' : (abs($ldiff) < 10 ? 'eod-diff-warn' : 'eod-diff-bad');
                @endphp
                <div class="eod-loc-card">
                    <h3>{{ $loc['location_name'] }}</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th class="num">Clover</th>
                                <th class="num">ERP</th>
                                <th class="num">Difference</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($loc['employees'] as $e)
                                @php
                                    $d = $e['difference'];
                                    $cls = abs($d) < 1 ? 'eod-diff-ok' : (abs($d) < 10 ? 'eod-diff-warn' : 'eod-diff-bad');
                                @endphp
                                <tr>
                                    <td>{{ $e['display_name'] }}</td>
                                    <td class="num">${{ number_format($e['clover_total'], 2) }}</td>
                                    <td class="num">${{ number_format($e['erp_total'], 2) }}</td>
                                    <td class="num {{ $cls }}">{{ $d >= 0 ? '+' : '' }}${{ number_format($d, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="eod-loc-empty">No cashier activity for this day.</td></tr>
                            @endforelse
                            <tr class="totals">
                                <td>Total</td>
                                <td class="num">${{ number_format($loc['totals']['clover_total'], 2) }}</td>
                                <td class="num">${{ number_format($loc['totals']['erp_total'], 2) }}</td>
                                <td class="num {{ $lcls }}">{{ $ldiff >= 0 ? '+' : '' }}${{ number_format($ldiff, 2) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            @endforeach
        </div>
        <p class="help-block" style="margin-top:-6px; margin-bottom: 18px;">
            <strong>Difference = Clover − ERP.</strong>
            A positive value means Clover settled more than the ERP recorded
            (usually a transaction was run on Clover but never reached the
            POS), negative means the ERP recorded more than Clover settled
            (often a tender booked as card in POS that didn't actually swipe
            through Clover). Cashier names are matched on first name so
            "luis casanova" on Clover and "Luis" in ERP line up on the same row.
        </p>
    @endif

    @component('components.widget', ['class' => 'box-primary', 'title' => 'Daily reconciliation'])
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Location</th>
                        <th class="text-right">ERP card $</th>
                        <th class="text-right">ERP txns</th>
                        <th class="text-right">Clover $</th>
                        <th class="text-right">Clover txns</th>
                        <th class="text-right">Variance</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                        @php
                            $row_class = $r->status === 'reconciled' ? '' : ($r->status === 'minor' ? 'warning' : 'danger');
                            $status_label = $r->status === 'reconciled' ? '✓ Reconciled'
                                          : ($r->status === 'minor' ? '⚠ Minor' : '⚠ Review');
                        @endphp
                        <tr @if($row_class) class="{{ $row_class }}" @endif>
                            <td>{{ $r->day }}</td>
                            <td>{{ $r->location_name }}</td>
                            <td class="text-right">${{ number_format($r->erp_total, 2) }}</td>
                            <td class="text-right">{{ $r->erp_count }}</td>
                            <td class="text-right">${{ number_format($r->clover_total, 2) }}</td>
                            <td class="text-right">{{ $r->clover_count }}</td>
                            <td class="text-right"><strong>${{ number_format($r->variance, 2) }}</strong></td>
                            <td>{{ $status_label }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted">No matching sales in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="help-block" style="margin-top:10px;">
            <strong>How to read this:</strong> each row pairs one day's ERP card-method payments with the Clover settlements logged for that day.
            Variance = ERP − Clover. Rows &lt; $1 off are green, &lt; $10 off yellow, otherwise flagged red.
            Clover data comes from the <code>clover_payments</code> table populated by the scheduled <code>clover:sync-payments</code> command —
            if it hasn't run recently the Clover column will lag behind.
        </p>
    @endcomponent
</section>
@stop

@section('javascript')
<script>
$(function () {
    $('#eod_date_range').daterangepicker(dateRangeSettings, function (start, end) {
        $('#eod_date_range').val(start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format));
    });
    $('#eod_apply_btn').on('click', function () {
        var val = $('#eod_date_range').val() || '';
        var parts = val.split(' ~ ');
        var dp = $('#eod_date_range').data('daterangepicker');
        var startFmt = dp ? dp.startDate.format('YYYY-MM-DD') : '';
        var endFmt   = dp ? dp.endDate.format('YYYY-MM-DD')   : '';
        var loc = $('#eod_location_id').val() || '';
        var qs = $.param({start_date: startFmt, end_date: endFmt, location_id: loc});
        window.location.href = '/reports/clover-eod-reconciliation?' + qs;
    });

    // Sync-now handler — POSTs to the web-wrapped artisan command and
    // pipes stdout into the black console block. On success we reload
    // so the report picks up the new clover_payments rows.
    $('#eod_sync_now_btn').on('click', function () {
        var $btn = $(this);
        var $status = $('#eod_sync_status');
        var $out = $('#eod_sync_output');
        var $pre = $('#eod_sync_output_pre');
        var days = parseInt($('#eod_sync_days').val(), 10) || 2;
        var original = $btn.html();
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Syncing…');
        var reachMsg = days > 7
            ? 'Backfilling ' + days + ' days, this can take 1–3 minutes…'
            : 'Reaching Clover API, this can take 20–60 seconds…';
        $status.text(reachMsg).css('color', '#6b7280');
        $out.hide();

        $.ajax({
            url: '/reports/clover-eod-reconciliation/sync-now',
            method: 'POST',
            dataType: 'json',
            timeout: 240000,
            data: { _token: $('meta[name="csrf-token"]').attr('content'), days: days }
        }).done(function (r) {
            $pre.text(r.output || '(no output)');
            $out.show();
            var msg = r.success
                ? 'Done · ' + (r.rows_recently_written || 0) + ' rows written (this call) · '
                    + (r.rows_in_window || 0) + ' rows now in the ' + days + '-day window. Reloading…'
                : 'Sync exited with code ' + (r.exit_code || '?') + ' — see output below.';
            $status.text(msg).css('color', r.success ? '#166534' : '#b91c1c');
            if (r.success && (r.rows_recently_written || 0) > 0) {
                setTimeout(function () { window.location.reload(); }, 1500);
            }
        }).fail(function (xhr) {
            var out = '';
            try { out = (xhr.responseJSON && xhr.responseJSON.output) || xhr.responseText; } catch (e) {}
            $pre.text(out || ('HTTP ' + xhr.status + ' — ' + xhr.statusText));
            $out.show();
            $status.text('Sync failed — see output below.').css('color', '#b91c1c');
        }).always(function () {
            $btn.prop('disabled', false).html(original);
        });
    });
});
</script>
@endsection
