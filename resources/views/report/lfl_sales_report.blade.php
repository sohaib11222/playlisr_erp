@extends('layouts.app')
@section('title', 'Like-for-Like Sales')

@section('content')

{{-- LFL Sales (daily weekday grid) — POS-create-style reskin. --}}
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
    .lfl-toggle .btn, .lfl-nav .btn { border-radius: 999px; }
    .lfl-grid { width: 100%; margin: 0 0 6px; }
    .lfl-grid th, .lfl-grid td { text-align: right; white-space: nowrap; vertical-align: middle; }
    .lfl-grid thead th { background: #FBF7EC; color: #5A5045; font-weight: 600; }
    .lfl-grid thead th .d { display: block; font-size: 11px; color: #9a8f7d; font-weight: 400; }
    .lfl-grid th.yr { text-align: left; font-weight: 700; color: #1F1B16; width: 90px; }
    .lfl-grid td.amt { font-weight: 600; color: #1F1B16; }
    .lfl-grid td.wk  { border-left: 2px solid #ECE3CF; font-weight: 700; }
    .lfl-grid th.wk-h { border-left: 2px solid #ECE3CF; }
    .lfl-grid tr.row-pct td { font-size: 12px; border-bottom: 2px solid #ECE3CF; }
    .lfl-grid col.today-col, .lfl-grid td.today, .lfl-grid th.today { background: #FFFBEA; }
    .lfl-store-h { margin: 18px 0 6px; font-size: 15px; font-weight: 700; color: #1F1B16; }
</style>

<section class="content-header">
    <h1>Like-for-Like Sales <small>This week by weekday — each day vs the same day last year, per store.</small></h1>
</section>

<section class="content">

    @php
        $linkDaily = action('ReportController@lflSalesReport');
        $cur = function () { return action('ReportController@lflSalesReport'); };
        $weekUrl = function ($w) { return action('ReportController@lflSalesReport') . '?' . http_build_query(['week' => $w]); };
        $exportUrl = action('ReportController@lflSalesReport') . '?' . http_build_query(array_merge(request()->only('week'), ['export' => 'csv']));

        $amt = function ($v) {
            if ($v === null) return '<span class="lfl-na">—</span>';
            return '$' . number_format($v, 0);
        };
        $pctCell = function ($p) {
            if ($p === null) return '<span class="lfl-na">n/a</span>';
            $cls = $p >= 0 ? 'lfl-up' : 'lfl-down';
            $sign = $p >= 0 ? '+' : '';
            return '<span class="' . $cls . '">' . $sign . number_format($p, 1) . '%</span>';
        };

        // Header for the Week total column — week-to-date for the live week.
        $wkHead = $nav['week_to_date']
            ? 'Wk-to-date' . ($nav['through_label'] ? '<span class="d">thru ' . $nav['through_label'] . '</span>' : '<span class="d">no full days yet</span>')
            : 'Week';

        // Render one table for a store (or all-stores).
        $renderTable = function ($t) use ($days, $ty, $ly, $amt, $pctCell, $wkHead) {
            $h = '<table class="table table-bordered lfl-grid"><colgroup><col><col>';
            foreach ($days as $d) { $h .= '<col class="' . ($d['is_today'] ? 'today-col' : '') . '">'; }
            $h .= '<col></colgroup><thead><tr><th class="yr"></th>';
            foreach ($days as $d) {
                $lbl = $d['wd'] . '<span class="d">' . $d['date'] . ($d['is_today'] ? ' • today' : '') . '</span>';
                $h .= '<th class="' . ($d['is_today'] ? 'today' : '') . '">' . $lbl . '</th>';
            }
            $h .= '<th class="wk-h">' . $wkHead . '</th></tr></thead><tbody>';
            // Last year row (top, matching the sketch), then this year, then Δ%.
            $h .= '<tr><th class="yr">' . $ly . '</th>';
            foreach ($t['ly'] as $i => $v) { $h .= '<td class="amt ' . ($days[$i]['is_today'] ? 'today' : '') . '">' . $amt($v) . '</td>'; }
            $h .= '<td class="wk amt">' . $amt($t['ly_total']) . '</td></tr>';
            $h .= '<tr><th class="yr">' . $ty . '</th>';
            foreach ($t['this'] as $i => $v) {
                $mark = ($days[$i]['is_today'] && $v !== null) ? '<span class="d">so far</span>' : '';
                $h .= '<td class="amt ' . ($days[$i]['is_today'] ? 'today' : '') . '">' . $amt($v) . $mark . '</td>';
            }
            $h .= '<td class="wk amt">' . $amt($t['this_total']) . '</td></tr>';
            $h .= '<tr class="row-pct"><th class="yr">Δ vs ' . $ly . '</th>';
            foreach ($t['pct'] as $i => $p) {
                $cellTitle = $days[$i]['is_today'] ? ' title="Today is partial vs a full day last year — not comparable"' : '';
                $h .= '<td class="' . ($days[$i]['is_today'] ? 'today' : '') . '"' . $cellTitle . '>' . $pctCell($p) . '</td>';
            }
            $h .= '<td class="wk">' . $pctCell($t['total_pct']) . '</td></tr>';
            $h .= '</tbody></table>';
            return $h;
        };
    @endphp

    {{-- Daily / Weekly toggle --}}
    <div class="row"><div class="col-md-12">
        <div class="lfl-toggle" style="margin:0 0 14px;">
            <a href="{{ $linkDaily }}" class="btn btn-primary btn-sm">Daily</a>
            <a href="{{ $linkDaily . '?view=weekly' }}" class="btn btn-default btn-sm">Weekly</a>
        </div>
    </div></div>

    {{-- Week nav --}}
    <div class="row"><div class="col-md-12">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin:0 0 12px;">
            <div class="lfl-nav">
                <a href="{{ $weekUrl($nav['prev']) }}" class="btn btn-default btn-sm"><i class="fa fa-chevron-left"></i> Prev week</a>
                @if(!$nav['is_current'])
                    <a href="{{ $cur() }}" class="btn btn-default btn-sm">This week</a>
                @endif
                @if($nav['next'])
                    <a href="{{ $weekUrl($nav['next']) }}" class="btn btn-default btn-sm">Next week <i class="fa fa-chevron-right"></i></a>
                @endif
                <span style="margin-left:10px; font-weight:700; color:#1F1B16;">{{ $nav['week_label'] }}</span>
                <span style="color:#9a8f7d;"> vs {{ $nav['ly_label'] }}</span>
            </div>
            <a href="{{ $exportUrl }}" class="btn"><i class="fa fa-file-excel"></i> Export (CSV)</a>
        </div>
    </div></div>

    <div class="row"><div class="col-md-12">
        @component('components.widget', ['class' => 'box-primary', 'title' => 'All stores — ' . $ty . ' vs ' . $ly])
            <div class="table-responsive">{!! $renderTable($all_table) !!}</div>
            <p class="text-muted" style="margin-top:6px;">
                Each weekday this week vs the same weekday last year (52 weeks back). Today is in progress
                (sales so far, as of {{ $nav['as_of'] }}); last year's imported history has no usable time-of-day,
                so today can't be compared hour-for-hour — its % shows n/a and it's left out of the
                {{ $nav['week_to_date'] ? 'week-to-date total (completed days only' . ($nav['through_label'] ? ', through ' . $nav['through_label'] : '') . ')' : 'week total' }}.
                Finalized in-store sells, including bulk-imported history; Whatnot excluded.
            </p>
        @endcomponent

        @foreach($store_tables as $t)
            @component('components.widget', ['class' => 'box-primary', 'title' => $t['name'] . ' — ' . $ty . ' vs ' . $ly])
                <div class="table-responsive">{!! $renderTable($t) !!}</div>
            @endcomponent
        @endforeach
    </div></div>

</section>
@endsection
