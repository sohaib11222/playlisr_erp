@extends('layouts.app')
@section('title', 'Employee Leaderboard')

@section('content')
<section class="content-header">
    <h1><i class="fa fa-trophy"></i> Employee Leaderboard <small>sales floor performance &amp; commission</small></h1>
</section>

<section class="content">

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Window &amp; Goal</h3></div>
        <div class="box-body">
            <div class="row">
                <div class="col-md-6">
                    <form method="GET" action="{{ action('ReportController@employeeLeaderboard') }}">
                        <label>Period</label>
                        <select name="period" class="form-control" onchange="this.form.submit()">
                            <option value="today" @if($period==='today') selected @endif>Today</option>
                            <option value="yesterday" @if($period==='yesterday') selected @endif>Yesterday</option>
                            <option value="this_week" @if($period==='this_week') selected @endif>This week</option>
                            <option value="last_week" @if($period==='last_week') selected @endif>Previous week</option>
                            <option value="last_7" @if($period==='last_7') selected @endif>Last 7 days</option>
                            <option value="this_month" @if($period==='this_month') selected @endif>This month</option>
                            <option value="last_30" @if($period==='last_30') selected @endif>Last 30 days</option>
                            <option value="this_quarter" @if($period==='this_quarter') selected @endif>This quarter</option>
                        </select>
                        <p class="text-muted" style="margin-top:8px;">Window: <strong>{{ $start->format('M j, Y') }}</strong> &rarr; <strong>{{ $end->format('M j, Y') }}</strong></p>
                    </form>
                </div>
                <div class="col-md-6">
                    <form method="POST" action="{{ route('reports.employee-leaderboard.settings') }}">
                        {{ csrf_field() }}
                        <input type="hidden" name="period" value="{{ $period }}">
                        <label>Goal uplift % (month-over-month push)</label>
                        <div class="input-group">
                            <input type="number" step="0.5" min="0" max="1000" name="uplift_pct" class="form-control" value="{{ rtrim(rtrim(number_format($uplift_pct, 2), '0'), '.') }}">
                            <span class="input-group-btn"><button class="btn btn-primary" type="submit">Save</button></span>
                        </div>
                        <p class="text-muted" style="margin-top:8px;">Each person's goal = their sales in the same window one month ago, raised by this %. Hit the goal and they earn <strong>2% of every dollar above it</strong>.</p>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @php $me = auth()->user()->id; @endphp

    <style>
        .lb-rank { font-size:16px; font-weight:800; text-align:center; width:44px; }
        .lb-rank-1 { background:#f6c244; color:#5b3b00; }
        .lb-rank-2 { background:#d4d8de; color:#1f2937; }
        .lb-rank-3 { background:#e8a06a; color:#5a2200; }
        .lb-me { background:#eef2ff; }
        .lb-table td, .lb-table th { vertical-align:middle; padding:6px 8px; }
        .lb-hit { color:#1b7a32; font-weight:700; }
        .lb-miss { color:#9aa0a6; }
        .lb-comm { background:#f1faf3; font-weight:700; color:#1b5e20; }
        .lb-store-head { font-size:16px; font-weight:700; margin:0 0 8px; }
        .lb-sub { font-size:11px; color:#9aa0a6; }
    </style>

    <div class="alert alert-info" style="border-left:4px solid #3c8dbc;">
        <strong>Both stores, side by side.</strong> Ranked by non-Whatnot $ per hour (Whatnot is excluded from every total). Hours come from cash-register open/close, clipped to the window. Commission = 2% of used items each person barcoded that sold (since 2026-05-15) + 2% of sales above their goal.
    </div>

    <div class="row">
        @forelse($stores as $store)
            <div class="col-md-6">
                <div class="box box-solid">
                    <div class="box-body table-responsive">
                        <p class="lb-store-head">{{ $store['name'] }}</p>
                        <table class="table table-condensed lb-table">
                            <thead>
                                <tr style="color:#6b7280; text-transform:uppercase; font-size:10px; letter-spacing:.5px;">
                                    <th class="text-center">#</th>
                                    <th>Employee</th>
                                    <th class="text-right">$ / hr</th>
                                    <th class="text-right">Hours</th>
                                    <th class="text-right">Sales</th>
                                    <th class="text-right">Goal</th>
                                    <th class="text-right">Bonus</th>
                                    <th class="text-right lb-comm">Commission</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($store['rows'] as $i => $r)
                                    @php
                                        $rank = $i + 1;
                                        $rank_cls = $rank === 1 ? 'lb-rank-1' : ($rank === 2 ? 'lb-rank-2' : ($rank === 3 ? 'lb-rank-3' : ''));
                                        $no_hours = is_null($r->revenue_per_hour);
                                    @endphp
                                    <tr class="{{ $r->user_id == $me ? 'lb-me' : '' }}">
                                        <td class="lb-rank {{ $no_hours ? '' : $rank_cls }}">{{ $no_hours ? '—' : $rank }}</td>
                                        <td>
                                            <strong>{{ $r->employee }}</strong>
                                            @if($r->whatnot_revenue > 0)<div class="lb-sub">Whatnot ${{ number_format($r->whatnot_revenue, 0) }} (excluded)</div>@endif
                                        </td>
                                        <td class="text-right">@if(!$no_hours)<strong style="color:#065f46;">${{ number_format($r->revenue_per_hour, 0) }}</strong>@else — @endif</td>
                                        <td class="text-right">@if($r->hours_worked > 0){{ number_format($r->hours_worked, 1) }}h @else <span class="text-muted">—</span>@endif</td>
                                        <td class="text-right">${{ number_format($r->non_whatnot_revenue, 0) }}</td>
                                        <td class="text-right">
                                            @if(!is_null($r->goal))
                                                ${{ number_format($r->goal, 0) }}
                                                @if($r->goal_hit)<div class="lb-hit">hit</div>@else<div class="lb-miss">{{ $r->goal > 0 ? number_format(($r->non_whatnot_revenue / $r->goal) * 100, 0) : 0 }}%</div>@endif
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-right">@if($r->goal_bonus > 0)${{ number_format($r->goal_bonus, 2) }}@else <span class="text-muted">—</span>@endif</td>
                                        <td class="text-right lb-comm">
                                            ${{ number_format($r->total_commission, 2) }}
                                            @if($r->barcoding_commission > 0)<div class="lb-sub">barcode ${{ number_format($r->barcoding_commission, 2) }}</div>@endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="8" class="text-center text-muted">No activity in this window.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-md-12"><div class="alert alert-warning">No active store locations found.</div></div>
        @endforelse
    </div>

</section>
@stop
