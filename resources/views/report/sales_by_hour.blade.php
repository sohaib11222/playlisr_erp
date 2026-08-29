@extends('layouts.app')
@section('title', 'Sales by Hour')

@section('content')

{{-- POS-create visual language (Inter Tight + cream palette), matching Revenue
     Drivers / Daily Store Dashboard. Hour-of-day x day-of-week revenue grid,
     any date range, toggled per store or all stores combined. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap"></noscript>

<style>
    .content-wrapper, body { background: #FAF6EE !important; }
    .content-header, .content, .content .box, .content .btn, .content input,
    .content select, .content textarea, .content table {
        font-family: "Inter Tight", system-ui, -apple-system, sans-serif !important;
        color: #1F1B16;
    }
    .content-header > h1 { font-weight: 800; font-size: 26px; color: #1F1B16; letter-spacing: -.01em; }
    .content-header > h1 small { color: #8E8273; font-weight: 500; }

    .content .box { background: #FFFFFF !important; border: 1px solid #ECE3CF !important;
        border-top: 1px solid #ECE3CF !important; border-radius: 10px !important;
        box-shadow: 0 1px 2px rgba(31,27,22,.06) !important; }
    .content .box .box-body { padding: 18px 20px !important; }
    .content label { font-size: 11px; font-weight: 600; letter-spacing: .12em;
        text-transform: uppercase; color: #8E8273; }
    .content .form-control { border: 1px solid #DFD2B3 !important; border-radius: 8px !important;
        box-shadow: none !important; color: #1F1B16 !important; background: #FFFFFF !important; }
    .content .form-control:focus { border-color: #E8CF68 !important;
        box-shadow: 0 0 0 3px rgba(232,207,104,.25) !important; }
    .content .btn-primary { background: #1F1B16 !important; border: 1px solid #1F1B16 !important;
        color: #FAF6EE !important; border-radius: 8px !important; font-weight: 700; }
    .content .btn-primary:hover { background: #000 !important; }
    .content .text-muted { color: #8E8273 !important; }

    .sbh-note { color: #5A5045; font-size: 13px; margin: 0 0 14px; }
    .sbh-note strong { color: #1F1B16; }

    .sbh-toggle { display: inline-flex; border: 1px solid #DFD2B3; border-radius: 8px; overflow: hidden; background: #FFF; }
    .sbh-toggle a { padding: 7px 14px; font-size: 13px; font-weight: 600; color: #5A5045; text-decoration: none;
        border-right: 1px solid #DFD2B3; white-space: nowrap; }
    .sbh-toggle a:last-child { border-right: 0; }
    .sbh-toggle a.active { background: #1F1B16; color: #FFF2B3; }
    .sbh-toggle a:hover:not(.active) { background: #FAF6EE; }

    .sbh-summary { display: flex; flex-wrap: wrap; gap: 26px; align-items: baseline; background: #1F1B16;
        color: #FFFDF7; border-radius: 12px; padding: 14px 20px; margin-bottom: 16px; }
    .sbh-summary .kpi { font-size: 13px; color: #D8CEB8; }
    .sbh-summary .kpi b { color: #FFF2B3; font-size: 18px; font-weight: 800; display: block; }

    .sbh-table-wrap { overflow-x: auto; }
    table.sbh-grid { border-collapse: collapse; width: 100%; min-width: 760px; }
    table.sbh-grid th, table.sbh-grid td { padding: 7px 10px; text-align: right; font-size: 12.5px;
        border: 1px solid #F2EBDB; white-space: nowrap; }
    table.sbh-grid thead th { text-align: center; background: #FAF6EE; color: #5A4410; font-weight: 800;
        text-transform: uppercase; letter-spacing: .04em; font-size: 11px; border-bottom: 1px solid #ECE3CF; }
    table.sbh-grid th.sbh-hour-col { text-align: left; background: #FAF6EE; color: #5A4410; font-weight: 700;
        text-transform: none; letter-spacing: 0; font-size: 12.5px; }
    table.sbh-grid td.sbh-cell { color: #1F1B16; font-weight: 600; }
    table.sbh-grid td.sbh-zero { color: #C9BFA9; font-weight: 400; }
    table.sbh-grid td.sbh-total, table.sbh-grid th.sbh-total { background: #FFF6CF !important; font-weight: 800 !important; color: #5A4410 !important; }
    table.sbh-grid tfoot td { border-top: 2px solid #ECE3CF; }
</style>

@php
    $money = function ($n) { return '$' . number_format($n, 0); };
    $storeLabel = $location_id ? ($locations[$location_id] ?? 'Store') : 'All stores';
@endphp

<section class="content-header">
    <h1>Sales by Hour <small>revenue by hour of day x day of week, any date range</small></h1>
</section>

<section class="content">

    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary'])
                {!! Form::open(['url' => action('ReportController@salesByHour'), 'method' => 'get']) !!}
                    <input type="hidden" name="location_id" value="{{ $location_id }}">
                    <input type="hidden" name="metric" value="{{ $metric }}">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group" style="margin-bottom:0;">
                                {!! Form::label('date_range', 'Date range:') !!}
                                {!! Form::text('date_range', $date_range, ['placeholder' => 'Last 90 days', 'class' => 'form-control', 'id' => 'sbh_date_range', 'readonly']); !!}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label style="display:block;">Store</label>
                            <div class="sbh-toggle">
                                <a href="#" class="sbh-store-btn {{ !$location_id ? 'active' : '' }}" data-loc="">All stores</a>
                                @foreach($locations as $loc_id => $loc_name)
                                    <a href="#" class="sbh-store-btn {{ (string) $location_id === (string) $loc_id ? 'active' : '' }}" data-loc="{{ $loc_id }}">{{ $loc_name }}</a>
                                @endforeach
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label style="display:block;">Show</label>
                            <div class="sbh-toggle">
                                <a href="#" class="sbh-metric-btn {{ $metric === 'avg' ? 'active' : '' }}" data-metric="avg">Daily average</a>
                                <a href="#" class="sbh-metric-btn {{ $metric === 'total' ? 'active' : '' }}" data-metric="total">Total in range</a>
                            </div>
                        </div>
                    </div>
                {!! Form::close() !!}
            @endcomponent

            <p class="sbh-note">
                Window: <strong>{{ \Carbon::parse($start_date)->format('M j, Y') }} &rarr; {{ \Carbon::parse($end_date)->format('M j, Y') }}</strong>,
                <strong>{{ $storeLabel }}</strong>.
                Register sales only &mdash; excludes Whatnot, online/marketplace, and bulk-imported/backfilled history, since those don't carry a real time-of-day.
                @if($metric === 'avg')
                    Each cell is the average for that hour on that weekday across the range (e.g. a Saturday column divides by how many Saturdays fall in the window).
                @else
                    Each cell is the sum for that hour on that weekday across the whole range.
                @endif
            </p>

            <div class="sbh-summary">
                <span class="kpi">Total revenue (window) <b>{{ $money($grand_total) }}</b></span>
                <span class="kpi">Peak hour x day <b>
                    @php
                        $peak_h = null; $peak_d = null; $peak_v = 0.0;
                        foreach ($grid as $h => $row) { foreach ($row as $d => $v) { if ($v > $peak_v) { $peak_v = $v; $peak_h = $h; $peak_d = $d; } } }
                    @endphp
                    @if($peak_h !== null)
                        {{ $day_labels[$peak_d] }} {{ \Carbon::createFromTime($peak_h)->format('g A') }} &mdash; {{ $money($peak_v) }}
                    @else
                        &mdash;
                    @endif
                </b></span>
            </div>

            @component('components.widget', ['class' => 'box-primary', 'title' => 'Sales by hour x day of week'])
                <div class="sbh-table-wrap">
                    <table class="sbh-grid">
                        <thead>
                            <tr>
                                <th class="sbh-hour-col">Hour</th>
                                @foreach($day_labels as $d => $lbl)
                                    <th>{{ $lbl }} <span style="font-weight:400;text-transform:none;">({{ $day_counts[$d] }})</span></th>
                                @endforeach
                                <th class="sbh-total">Hour total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @for($h = 0; $h < 24; $h++)
                                <tr>
                                    <th class="sbh-hour-col">{{ \Carbon::createFromTime($h)->format('g A') }}</th>
                                    @foreach($day_labels as $d => $lbl)
                                        @php
                                            $v = $grid[$h][$d];
                                            $ratio = $max_cell > 0 ? min(1, $v / $max_cell) : 0;
                                            $bg = $v > 0 ? 'rgba(197,161,39,' . round($ratio * 0.85, 3) . ')' : 'transparent';
                                        @endphp
                                        <td class="sbh-cell {{ $v <= 0 ? 'sbh-zero' : '' }}" style="background: {{ $bg }};" title="{{ $lbl }} {{ \Carbon::createFromTime($h)->format('g A') }}: {{ $tx_grid[$h][$d] }} tx">
                                            {{ $v > 0 ? $money($v) : '-' }}
                                        </td>
                                    @endforeach
                                    <td class="sbh-cell sbh-total">{{ $money($row_totals[$h]) }}</td>
                                </tr>
                            @endfor
                        </tbody>
                        <tfoot>
                            <tr>
                                <td class="sbh-hour-col" style="font-weight:800;">Day total</td>
                                @foreach($day_labels as $d => $lbl)
                                    <td class="sbh-total">{{ $money($col_totals[$d]) }}</td>
                                @endforeach
                                <td class="sbh-total">{{ $money($grand_total) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endcomponent

        </div>
    </div>

</section>
@endsection

@section('javascript')
    <script>
        $(function () {
            var fmt = (typeof moment_date_format !== 'undefined') ? moment_date_format : 'MM/DD/YYYY';
            $('#sbh_date_range').daterangepicker({
                autoUpdateInput: false,
                locale: {
                    format: fmt,
                    cancelLabel: (typeof LANG !== 'undefined' ? LANG.clear : 'Clear'),
                    applyLabel: (typeof LANG !== 'undefined' ? LANG.apply : 'Apply'),
                },
            });
            $('#sbh_date_range').on('apply.daterangepicker', function (ev, picker) {
                $(this).val(picker.startDate.format(fmt) + ' ~ ' + picker.endDate.format(fmt));
                $(this).closest('form').submit();
            });
            $('#sbh_date_range').on('cancel.daterangepicker', function () {
                $(this).val('');
            });

            $('.sbh-store-btn').on('click', function (e) {
                e.preventDefault();
                $('input[name="location_id"]').val($(this).data('loc'));
                $(this).closest('form').submit();
            });
            $('.sbh-metric-btn').on('click', function (e) {
                e.preventDefault();
                $('input[name="metric"]').val($(this).data('metric'));
                $(this).closest('form').submit();
            });
        });
    </script>
@endsection
