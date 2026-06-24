@extends('layouts.app')
@section('title', 'Like-for-Like Sales — Monthly')

@section('content')

{{-- LFL Sales (monthly) — POS-create-style reskin to match the Items Report. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap"></noscript>
<link rel="stylesheet" href="{{ asset('css/items-report-layout.css?v=' . $asset_v) }}">
<script>document.body.classList.add('items-report-v2');</script>

<style>
    .lfl-up   { color: #1a7f37; font-weight: 600; }
    .lfl-down { color: #c0392b; font-weight: 600; }
    .lfl-na   { color: #9a8f7d; }
    .lfl-toggle { margin: 0 0 14px; }
    .lfl-toggle .btn { border-radius: 999px; }
    #lflm_table td.num, #lflm_table th.num { text-align: right; white-space: nowrap; }
    #lflm_table td.amt { font-weight: 600; color: #1F1B16; }
    #lflm_table td.pct { font-size: 12px; }
    #lflm_table tr.is-current { background: #FFFBEA; }
    #lflm_table tr.is-current td:first-child::after { content: ' • in progress'; color: #9a8f7d; font-weight: 400; font-size: 11px; }
    #lflm_table tfoot td { font-weight: 700; border-top: 2px solid #ECE3CF; }
    .lfl-store-th { border-left: 2px solid #ECE3CF; }
</style>

<section class="content-header">
    <h1>Like-for-Like Sales — Monthly <small>Each month vs the same month last year, by store.</small></h1>
</section>

<section class="content">

    @php
        $span_opts = [6, 12, 24];
        $linkMonths = function ($n) {
            return action('ReportController@lflSalesReport') . '?' . http_build_query(['view' => 'monthly', 'months' => $n]);
        };
        $exportUrl = action('ReportController@lflSalesReport') . '?' . http_build_query(['view' => 'monthly', 'months' => $months_back, 'export' => 'csv']);
        $dailyUrl = action('ReportController@lflSalesReport');
        $weeklyUrl = action('ReportController@lflSalesReport') . '?view=weekly';

        $cell = function ($c) {
            if (!$c) return '<td class="num"><span class="lfl-na">—</span></td>';
            $amt = '$' . number_format($c['this_rev'], 0);
            if ($c['pct'] === null) {
                $pct = '<span class="lfl-na">n/a</span>';
            } else {
                $cls = $c['pct'] >= 0 ? 'lfl-up' : 'lfl-down';
                $sign = $c['pct'] >= 0 ? '+' : '';
                $pct = '<span class="' . $cls . '">' . $sign . number_format($c['pct'], 1) . '%</span>';
            }
            $title = 'This: $' . number_format($c['this_rev'], 2) . ' · Last year: $' . number_format($c['ly_rev'], 2);
            return '<td class="num" title="' . $title . '"><span class="amt">' . $amt . '</span><br><span class="pct">' . $pct . '</span></td>';
        };
    @endphp

    {{-- Daily / Weekly / Monthly toggle --}}
    <div class="row"><div class="col-md-12">
        <div class="lfl-toggle">
            <a href="{{ $dailyUrl }}" class="btn btn-default btn-sm">Daily</a>
            <a href="{{ $weeklyUrl }}" class="btn btn-default btn-sm">Weekly</a>
            <a href="{{ $linkMonths($months_back) }}" class="btn btn-primary btn-sm">Monthly</a>
        </div>
    </div></div>

    <div class="row">
        <div class="col-md-12">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin:0 0 14px;">
                <div>
                    <span style="color:#5A5045; font-size:13px; margin-right:8px;">Show last:</span>
                    @foreach($span_opts as $n)
                        <a href="{{ $linkMonths($n) }}"
                           class="btn btn-sm {{ $months_back == $n ? 'btn-primary' : 'btn-default' }}">{{ $n }} months</a>
                    @endforeach
                </div>
                <a href="{{ $exportUrl }}" class="btn"><i class="fa fa-file-excel"></i> Export (CSV)</a>
            </div>

            @component('components.widget', ['class' => 'box-primary', 'title' => 'Monthly sales by store — vs the same month last year'])
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="lflm_table" style="width:100%;">
                        <thead>
                            <tr>
                                <th>Month</th>
                                @foreach($locations as $loc_id => $name)
                                    <th class="num lfl-store-th">{{ $name }}</th>
                                @endforeach
                                <th class="num lfl-store-th">All stores</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($months as $mo)
                                <tr class="{{ $mo['is_current'] ? 'is-current' : '' }}">
                                    <td title="vs {{ $mo['ly_label'] }}">{{ $mo['label'] }}</td>
                                    @foreach($locations as $loc_id => $name)
                                        {!! $cell($mo['cells'][$loc_id] ?? null) !!}
                                    @endforeach
                                    {!! $cell($mo['total']) !!}
                                </tr>
                            @empty
                                <tr><td colspan="{{ count($locations) + 2 }}" class="text-center text-muted">No months to show.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>TOTAL (last {{ $months_back }} mo)</td>
                                @foreach($locations as $loc_id => $name)
                                    {!! $cell($span['cells'][$loc_id] ?? null) !!}
                                @endforeach
                                {!! $cell($span['total']) !!}
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p class="text-muted" style="margin-top:8px;">
                    Each cell: this month's sales (bold) and the % change vs the same month last year — hover for exact figures.
                    The current month is in progress and compares month-to-date through yesterday on both years (a fair, full-days compare).
                    Finalized in-store sells, including bulk-imported history (last year's sales live there); Whatnot excluded.
                </p>
            @endcomponent
        </div>
    </div>

</section>
@endsection
