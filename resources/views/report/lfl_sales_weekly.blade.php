@extends('layouts.app')
@section('title', 'Like-for-Like Sales — Weekly')

@section('content')

{{-- LFL Sales (weekly) — POS-create-style reskin to match the Items Report. --}}
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
    #lflw_table td.num, #lflw_table th.num { text-align: right; white-space: nowrap; }
    #lflw_table td.amt { font-weight: 600; color: #1F1B16; }
    #lflw_table td.pct { font-size: 12px; }
    #lflw_table tr.is-current { background: #FFFBEA; }
    #lflw_table tr.is-current td:first-child::after { content: ' • in progress'; color: #9a8f7d; font-weight: 400; font-size: 11px; }
    #lflw_table tfoot td { font-weight: 700; border-top: 2px solid #ECE3CF; }
    .lfl-store-th { border-left: 2px solid #ECE3CF; }
</style>

<section class="content-header">
    <h1>Like-for-Like Sales — Weekly <small>Each week vs the same week last year, by store.</small></h1>
</section>

<section class="content">

    @php
        $span_opts = [8, 12, 26, 52];
        $linkWeeks = function ($n) {
            return action('ReportController@lflSalesReport') . '?' . http_build_query(['view' => 'weekly', 'weeks' => $n]);
        };
        $exportUrl = action('ReportController@lflSalesReport') . '?' . http_build_query(['view' => 'weekly', 'weeks' => $weeks_back, 'export' => 'csv']);
        $dailyUrl = action('ReportController@lflSalesReport');

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

    {{-- Daily / Weekly toggle --}}
    <div class="row"><div class="col-md-12">
        <div class="lfl-toggle">
            <a href="{{ $dailyUrl }}" class="btn btn-default btn-sm">Daily</a>
            <a href="{{ $linkWeeks($weeks_back) }}" class="btn btn-primary btn-sm">Weekly</a>
        </div>
    </div></div>

    <div class="row">
        <div class="col-md-12">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin:0 0 14px;">
                <div>
                    <span style="color:#5A5045; font-size:13px; margin-right:8px;">Show last:</span>
                    @foreach($span_opts as $n)
                        <a href="{{ $linkWeeks($n) }}"
                           class="btn btn-sm {{ $weeks_back == $n ? 'btn-primary' : 'btn-default' }}">{{ $n }} weeks</a>
                    @endforeach
                </div>
                <a href="{{ $exportUrl }}" class="btn"><i class="fa fa-file-excel"></i> Export (CSV)</a>
            </div>

            @component('components.widget', ['class' => 'box-primary', 'title' => 'Weekly sales by store — vs the same week 52 weeks back'])
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="lflw_table" style="width:100%;">
                        <thead>
                            <tr>
                                <th>Week (Mon – Sun)</th>
                                @foreach($locations as $loc_id => $name)
                                    <th class="num lfl-store-th">{{ $name }}</th>
                                @endforeach
                                <th class="num lfl-store-th">All stores</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($weeks as $wk)
                                <tr class="{{ $wk['is_current'] ? 'is-current' : '' }}">
                                    <td title="vs {{ $wk['ly_label'] }}">{{ $wk['label'] }}</td>
                                    @foreach($locations as $loc_id => $name)
                                        {!! $cell($wk['cells'][$loc_id] ?? null) !!}
                                    @endforeach
                                    {!! $cell($wk['total']) !!}
                                </tr>
                            @empty
                                <tr><td colspan="{{ count($locations) + 2 }}" class="text-center text-muted">No weeks to show.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>TOTAL (last {{ $weeks_back }} wks)</td>
                                @foreach($locations as $loc_id => $name)
                                    {!! $cell($span['cells'][$loc_id] ?? null) !!}
                                @endforeach
                                {!! $cell($span['total']) !!}
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p class="text-muted" style="margin-top:8px;">
                    Each cell: this week's sales (bold) and the % change vs the same week last year — hover for exact figures.
                    Weeks are Monday–Sunday; the current week is in progress and clipped to the same point last year.
                    Finalized in-store sells only; Whatnot and bulk-imported history excluded.
                </p>
            @endcomponent
        </div>
    </div>

</section>
@endsection
