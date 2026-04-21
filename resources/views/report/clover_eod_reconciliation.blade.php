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
});
</script>
@endsection
