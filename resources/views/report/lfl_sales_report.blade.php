@extends('layouts.app')
@section('title', 'Like-for-Like Sales')

@section('content')

{{-- LFL Sales — POS-create-style reskin to match the Items Report.
     Inter Tight font + items-report-layout.css + items-report-v2 body class. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap"></noscript>
<link rel="stylesheet" href="{{ asset('css/items-report-layout.css?v=' . $asset_v) }}">
<script>document.body.classList.add('items-report-v2');</script>

<style>
    .lfl-up   { color: #1a7f37; font-weight: 600; }
    .lfl-down { color: #c0392b; font-weight: 600; }
    .lfl-na   { color: #5A5045; }
    .lfl-compare-note { color: #5A5045; font-size: 13px; margin: 0 0 14px; }
    .lfl-compare-note strong { color: #1F1B16; }
    #lfl_table td.num, #lfl_table th.num { text-align: right; white-space: nowrap; }
    #lfl_table tfoot td { font-weight: 700; border-top: 2px solid #ECE3CF; }
</style>

<section class="content-header">
    <h1>Like-for-Like Sales <small>Today (or any date) vs the same weekday last year — store trading-day sales.</small></h1>
</section>

<section class="content">

    @php
        $exportUrl = action('ReportController@lflSalesReport') . '?' . http_build_query(array_merge(request()->all(), ['export' => 'csv']));
        $fmtPct = function ($pct) {
            if ($pct === null) return '<span class="lfl-na">n/a</span>';
            $cls = $pct >= 0 ? 'lfl-up' : 'lfl-down';
            $sign = $pct >= 0 ? '+' : '';
            return '<span class="' . $cls . '">' . $sign . number_format($pct, 1) . '%</span>';
        };
        $fmtDelta = function ($d) {
            $cls = $d >= 0 ? 'lfl-up' : 'lfl-down';
            $sign = $d >= 0 ? '+' : '−';
            return '<span class="' . $cls . '">' . ($d >= 0 ? '+' : '−') . '$' . number_format(abs($d), 2) . '</span>';
        };
    @endphp

    <div class="row">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])
                {!! Form::open(['url' => action('ReportController@lflSalesReport'), 'method' => 'get']) !!}
                    <div class="col-md-4">
                        <div class="form-group">
                            {!! Form::label('date_range', 'Trading day / range:') !!}
                            {!! Form::text('date_range', request('date_range'), ['placeholder' => 'Today', 'class' => 'form-control', 'id' => 'lfl_date_range', 'readonly']); !!}
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label style="display:block;">&nbsp;</label>
                        <button type="submit" class="btn btn-primary">@lang('report.apply_filters')</button>
                    </div>
                {!! Form::close() !!}
            @endcomponent

            <p class="lfl-compare-note">
                Comparing <strong>{{ $meta['this_label'] }}</strong>@if($meta['as_of']) <em>(as of {{ $meta['as_of'] }})</em>@endif
                against <strong>{{ $meta['ly_label'] }}</strong> — same weekday, 52 weeks back.
                @if($meta['end_is_today']) Today is in progress, so last year is clipped to the same time of day. @endif
            </p>

            <div style="margin: 0 0 14px; display: flex; justify-content: flex-end;">
                <a href="{{ $exportUrl }}" class="btn">
                    <i class="fa fa-file-excel"></i> Export (CSV)
                </a>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary', 'title' => 'Sales by store — this period vs same weekday last year'])
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="lfl_table" style="width:100%;">
                        <thead>
                            <tr>
                                <th>Store</th>
                                <th class="num">This period</th>
                                <th class="num">Last year (LFL)</th>
                                <th class="num">Change $</th>
                                <th class="num">Change %</th>
                                <th class="num">Txns (this / LY)</th>
                                <th class="num">Avg ticket (this / LY)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($rows as $r)
                                <tr>
                                    <td>{{ $r['location'] }}</td>
                                    <td class="num"><span class="display_currency" data-currency_symbol="true">{{ number_format($r['this_rev'], 2, '.', '') }}</span></td>
                                    <td class="num"><span class="display_currency" data-currency_symbol="true">{{ number_format($r['ly_rev'], 2, '.', '') }}</span></td>
                                    <td class="num">{!! $fmtDelta($r['delta']) !!}</td>
                                    <td class="num">{!! $fmtPct($r['pct']) !!}</td>
                                    <td class="num">{{ number_format($r['this_tx']) }} / {{ number_format($r['ly_tx']) }}</td>
                                    <td class="num">${{ number_format($r['this_avg'], 2) }} / ${{ number_format($r['ly_avg'], 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted">No active stores found.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>TOTAL</td>
                                <td class="num"><span class="display_currency" data-currency_symbol="true">{{ number_format($totals['this_rev'], 2, '.', '') }}</span></td>
                                <td class="num"><span class="display_currency" data-currency_symbol="true">{{ number_format($totals['ly_rev'], 2, '.', '') }}</span></td>
                                <td class="num">{!! $fmtDelta($totals['delta']) !!}</td>
                                <td class="num">{!! $fmtPct($totals['pct']) !!}</td>
                                <td class="num">{{ number_format($totals['this_tx']) }} / {{ number_format($totals['ly_tx']) }}</td>
                                <td class="num">${{ number_format($totals['this_avg'], 2) }} / ${{ number_format($totals['ly_avg'], 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p class="text-muted" style="margin-top:8px;">
                    Sales = finalized in-store sells (final_total). Whatnot livestream and bulk-imported historical sales are excluded, matching the live Store Performance dashboard.
                </p>
            @endcomponent
        </div>
    </div>

</section>
@endsection

@section('javascript')
    <script>
        $(function () {
            var fmt = (typeof moment_date_format !== 'undefined') ? moment_date_format : 'MM/DD/YYYY';
            $('#lfl_date_range').daterangepicker({
                autoUpdateInput: false,
                locale: {
                    format: fmt,
                    cancelLabel: (typeof LANG !== 'undefined' ? LANG.clear : 'Clear'),
                    applyLabel: (typeof LANG !== 'undefined' ? LANG.apply : 'Apply'),
                },
            });
            $('#lfl_date_range').on('apply.daterangepicker', function (ev, picker) {
                $(this).val(picker.startDate.format(fmt) + ' ~ ' + picker.endDate.format(fmt));
            });
            $('#lfl_date_range').on('cancel.daterangepicker', function () {
                $(this).val('');
            });
        });
    </script>
@endsection
