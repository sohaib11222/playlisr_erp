@extends('layouts.app')
@section('title', 'Employee Leaderboard')

@section('content')
<section class="content-header">
    <h1>🏆 Employee Leaderboard <small>who's lighting it up</small></h1>
</section>

<section class="content">

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Window</h3></div>
        <div class="box-body">
            <form method="GET" action="{{ action('ReportController@employeeLeaderboard') }}" class="row">
                <div class="col-md-4">
                    <label>Period</label>
                    <select name="period" class="form-control" onchange="this.form.submit()">
                        <option value="today" @if($period==='today') selected @endif>Today</option>
                        <option value="yesterday" @if($period==='yesterday') selected @endif>Yesterday</option>
                        <option value="this_week" @if($period==='this_week') selected @endif>This week</option>
                        <option value="last_7" @if($period==='last_7') selected @endif>Last 7 days</option>
                        <option value="this_month" @if($period==='this_month') selected @endif>This month</option>
                        <option value="last_30" @if($period==='last_30') selected @endif>Last 30 days</option>
                        <option value="this_quarter" @if($period==='this_quarter') selected @endif>This quarter</option>
                    </select>
                </div>
                <div class="col-md-8">
                    <label style="display:block;">&nbsp;</label>
                    <span class="text-muted">Window: <strong>{{ $start->format('M j, Y') }}</strong> → <strong>{{ $end->format('M j, Y') }}</strong></span>
                </div>
            </form>
        </div>
    </div>

    @php
        $me = auth()->user()->id;
    @endphp

    <style>
        .lb-rank { font-size:18px; font-weight:800; text-align:center; width:56px; }
        .lb-rank-1 { background:linear-gradient(135deg,#fde68a,#f59e0b); color:#78350f; }
        .lb-rank-2 { background:linear-gradient(135deg,#e5e7eb,#cbd5e1); color:#1f2937; }
        .lb-rank-3 { background:linear-gradient(135deg,#fed7aa,#fb923c); color:#7c2d12; }
        .lb-me { background:#eef2ff; border-left:4px solid #6366f1; }
        .lb-table td { vertical-align:middle; }
        .lb-bar { background:#e5e7eb; height:6px; border-radius:999px; overflow:hidden; margin-top:4px; }
        .lb-bar-fill { background:linear-gradient(90deg,#10b981,#22c55e); height:100%; }
    </style>

    <div class="alert alert-info" style="border-left: 4px solid #3c8dbc;">
        <strong>Ranked by $ per hour.</strong> Hours worked come from each employee's cash-register open/close times, clipped to the selected window. Employees without register activity in this window are still shown but without a per-hour ranking (marked "—").
    </div>

    <div class="box box-solid">
        <div class="box-body table-responsive">
            @php
                $top_rph = $rows->isNotEmpty() ? max((float) ($rows->first()->revenue_per_hour ?? 0), 1) : 1;
            @endphp
            <style>
                .sortable-col a { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
                .sortable-col a:hover { color: #1b6ca8; }
                .sortable-col .sort-arrow { opacity: 0.4; font-size: 11px; }
                .sortable-col.active a { color: #1b6ca8; font-weight: 700; }
                .sortable-col.active .sort-arrow { opacity: 1; }
            </style>
            @php
                $other = request()->except(['sort', 'dir']);
                $sortUrl = function ($col) use ($sort, $dir, $other) {
                    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
                    return action('ReportController@employeeLeaderboard') . '?' . http_build_query(array_merge($other, ['sort' => $col, 'dir' => $newDir]));
                };
                $arrow = function ($col) use ($sort, $dir) {
                    if ($sort !== $col) return '<i class="fa fa-sort sort-arrow"></i>';
                    return $dir === 'asc' ? '<i class="fa fa-sort-up sort-arrow"></i>' : '<i class="fa fa-sort-down sort-arrow"></i>';
                };
                $colHead = function ($col, $label, $class = '', $extra_style = '') use ($sort, $sortUrl, $arrow) {
                    $active = $sort === $col ? 'active' : '';
                    return '<th class="sortable-col ' . $class . ' ' . $active . '" style="' . $extra_style . '"><a href="' . $sortUrl($col) . '">' . $label . ' ' . $arrow($col) . '</a></th>';
                };
            @endphp
            <table class="table lb-table">
                <thead>
                    <tr style="color:#6b7280; text-transform:uppercase; font-size:11px; letter-spacing:.5px;">
                        <th class="text-center">Rank</th>
                        {!! $colHead('employee', 'Employee') !!}
                        {!! $colHead('revenue_per_hour', '$ / hr', 'text-right', 'background:#ecfdf5;') !!}
                        {!! $colHead('items_per_hour', 'Items / hr', 'text-right', 'background:#ecfdf5;') !!}
                        {!! $colHead('tx_per_hour', 'Tx / hr', 'text-right', 'background:#ecfdf5;') !!}
                        {!! $colHead('hours_worked', 'Hours', 'text-right') !!}
                        {!! $colHead('revenue', 'Total $', 'text-right') !!}
                        {!! $colHead('items_rung', 'Items rung', 'text-right') !!}
                        {!! $colHead('avg_tx', 'Avg $/tx', 'text-right') !!}
                        {!! $colHead('priced_count', 'Items priced', 'text-right') !!}
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $i => $r)
                        @php
                            $rank = $i + 1;
                            $rank_cls = $rank === 1 ? 'lb-rank-1' : ($rank === 2 ? 'lb-rank-2' : ($rank === 3 ? 'lb-rank-3' : ''));
                            $row_cls = $r->user_id == $me ? 'lb-me' : '';
                            $pct = (!is_null($r->revenue_per_hour) && $top_rph > 0) ? min(($r->revenue_per_hour / $top_rph) * 100, 100) : 0;
                            $no_hours = is_null($r->revenue_per_hour);
                        @endphp
                        <tr class="{{ $row_cls }}">
                            <td class="lb-rank {{ $no_hours ? '' : $rank_cls }}">
                                @if($no_hours) —
                                @elseif($rank===1) 🥇 @elseif($rank===2) 🥈 @elseif($rank===3) 🥉 @else {{ $rank }} @endif
                            </td>
                            <td>
                                <strong>{{ $r->employee }}</strong>
                                @if($r->user_id == $me)<span class="label label-primary" style="margin-left:6px;">you</span>@endif
                                @if(!$no_hours)<div class="lb-bar"><div class="lb-bar-fill" style="width:{{ $pct }}%;"></div></div>@endif
                            </td>
                            <td class="text-right" style="background:#ecfdf5;">
                                @if(!is_null($r->revenue_per_hour))
                                    <strong style="font-size:16px; color:#065f46;">${{ number_format($r->revenue_per_hour, 0) }}</strong>
                                @else — @endif
                            </td>
                            <td class="text-right" style="background:#ecfdf5;">
                                @if(!is_null($r->items_per_hour)) {{ number_format($r->items_per_hour, 1) }} @else — @endif
                            </td>
                            <td class="text-right" style="background:#ecfdf5;">
                                @if(!is_null($r->tx_per_hour)) {{ number_format($r->tx_per_hour, 1) }} @else — @endif
                            </td>
                            <td class="text-right">
                                @if($r->hours_worked > 0) {{ number_format($r->hours_worked, 1) }}h @else <span class="text-muted">—</span> @endif
                            </td>
                            <td class="text-right">${{ number_format($r->revenue, 0) }}</td>
                            <td class="text-right">{{ number_format($r->items_rung) }}</td>
                            <td class="text-right">${{ number_format($r->avg_tx, 2) }}</td>
                            <td class="text-right">{{ number_format($r->priced_count) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-muted">No activity in this window.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</section>
@stop
