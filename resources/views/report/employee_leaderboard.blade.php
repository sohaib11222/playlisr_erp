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

    <div class="box box-solid">
        <div class="box-body table-responsive">
            @php
                $top_revenue = $rows->isNotEmpty() ? max((float) $rows->first()->revenue, 1) : 1;
            @endphp
            <table class="table lb-table">
                <thead>
                    <tr style="color:#6b7280; text-transform:uppercase; font-size:11px; letter-spacing:.5px;">
                        <th class="text-center">Rank</th>
                        <th>Employee</th>
                        <th class="text-right">Revenue</th>
                        <th class="text-right"># tx</th>
                        <th class="text-right">Items rung</th>
                        <th class="text-right">Avg $/tx</th>
                        <th class="text-right">Items priced</th>
                        <th class="text-right">$ from priced items</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $i => $r)
                        @php
                            $rank = $i + 1;
                            $rank_cls = $rank === 1 ? 'lb-rank-1' : ($rank === 2 ? 'lb-rank-2' : ($rank === 3 ? 'lb-rank-3' : ''));
                            $row_cls = $r->user_id == $me ? 'lb-me' : '';
                            $pct = $top_revenue > 0 ? min(($r->revenue / $top_revenue) * 100, 100) : 0;
                        @endphp
                        <tr class="{{ $row_cls }}">
                            <td class="lb-rank {{ $rank_cls }}">
                                @if($rank===1) 🥇 @elseif($rank===2) 🥈 @elseif($rank===3) 🥉 @else {{ $rank }} @endif
                            </td>
                            <td>
                                <strong>{{ $r->employee }}</strong>
                                @if($r->user_id == $me)<span class="label label-primary" style="margin-left:6px;">you</span>@endif
                                <div class="lb-bar"><div class="lb-bar-fill" style="width:{{ $pct }}%;"></div></div>
                            </td>
                            <td class="text-right"><strong style="font-size:15px; color:#065f46;">${{ number_format($r->revenue, 0) }}</strong></td>
                            <td class="text-right">{{ number_format($r->tx_count) }}</td>
                            <td class="text-right">{{ number_format($r->items_rung) }}</td>
                            <td class="text-right">${{ number_format($r->avg_tx, 2) }}</td>
                            <td class="text-right">{{ number_format($r->priced_count) }}</td>
                            <td class="text-right">${{ number_format($r->priced_revenue, 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted">No sales in this window.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</section>
@stop
