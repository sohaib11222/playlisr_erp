@extends('layouts.app')
@section('title', 'Shift Targets')

@section('content')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<style>
    .content-wrapper, body { background: #FAF6EE !important; }
    .content-header, .content, .content .box, .content .btn, .content input,
    .content select, .content table {
        font-family: "Inter Tight", system-ui, -apple-system, sans-serif !important;
        color: #1F1B16;
    }
    .content-header > h1 { font-weight: 800; font-size: 26px; color: #1F1B16; letter-spacing: -.01em; }
    .content-header > h1 small { color: #8E8273; font-weight: 500; }
    .content .box { background: #FFFFFF !important; border: 1px solid #ECE3CF !important;
        border-radius: 10px !important; box-shadow: 0 1px 2px rgba(31,27,22,.06) !important; }
    .content .box .box-header { border-bottom: 1px solid #ECE3CF !important; }
    .content .box .box-title { font-weight: 700; color: #1F1B16; }
    .content label { font-size: 11px; font-weight: 600; letter-spacing: .12em;
        text-transform: uppercase; color: #8E8273; }
    .content .form-control { border: 1px solid #DFD2B3 !important; border-radius: 8px !important;
        box-shadow: none !important; color: #1F1B16 !important; background: #FFFFFF !important; }
    .content .btn-primary { background: #1F1B16 !important; border: 1px solid #1F1B16 !important;
        color: #FAF6EE !important; border-radius: 8px !important; font-weight: 700; }
    .content .text-muted { color: #8E8273 !important; }

    .st-store-head { font-weight: 800; color: #1F1B16; font-size: 15px;
        text-transform: uppercase; letter-spacing: .05em; margin: 0 0 4px; }
    .st-table { width: 100%; }
    .st-table thead tr { color: #8E8273; text-transform: uppercase; font-size: 10px; letter-spacing: .5px; }
    .st-table th { border-bottom: 1px solid #ECE3CF; padding: 6px 10px; }
    .st-table td { border-top: 1px solid #F4ECD9; padding: 8px 10px; vertical-align: top; }
    .st-sub { font-size: 11px; color: #9aa0a6; }
    .st-hit { color: #1b7a32; font-weight: 700; }
    .st-miss { color: #9aa0a6; }
    .st-target { font-weight: 700; }
    .st-explain { background: #FFF9DB !important; border: 1px solid #E8CF68 !important;
        border-left: 4px solid #E8CF68 !important; color: #5A4410 !important; }
    .st-explain li { margin-bottom: 4px; }

    @media print {
        .st-noprint { display: none !important; }
        .content .box { box-shadow: none !important; border: none !important; }
        body, .content-wrapper { background: #fff !important; }
        a[href]:after { content: ""; }
    }
</style>

<section class="content-header">
    <h1>Shift Targets <small>per-person target for the hours they work</small></h1>
</section>

<section class="content">
    <div class="box box-primary st-noprint">
        <div class="box-body">
            <div class="row">
                <div class="col-md-6">
                    <form method="GET" action="{{ action('ReportController@shiftTargets') }}">
                        <label>Period</label>
                        <select name="period" class="form-control" onchange="this.form.submit()">
                            <option value="today" @if($period==='today') selected @endif>Today</option>
                            <option value="yesterday" @if($period==='yesterday') selected @endif>Yesterday</option>
                            <option value="this_week" @if($period==='this_week') selected @endif>This week</option>
                            <option value="last_week" @if($period==='last_week') selected @endif>Previous week</option>
                            <option value="last_7" @if($period==='last_7') selected @endif>Last 7 days</option>
                            <option value="this_month" @if($period==='this_month') selected @endif>This month</option>
                            <option value="last_30" @if($period==='last_30') selected @endif>Last 30 days</option>
                        </select>
                        <p class="text-muted" style="margin-top:8px;">Showing <strong>{{ $start->format('M j, Y') }}</strong> &rarr; <strong>{{ $end->format('M j, Y') }}</strong></p>
                    </form>
                </div>
                <div class="col-md-6 text-right">
                    <button type="button" class="btn btn-primary" onclick="window.print()" style="margin-top:22px;">Print this list</button>
                </div>
            </div>
        </div>
    </div>

    <div class="box st-explain">
        <div class="box-body">
            <p style="margin:0 0 6px; font-weight:700;">How to read this</p>
            <ul style="margin:0; padding-left:18px; line-height:1.6;">
                <li><strong>Target</strong> is set by <em>when</em> someone works, not a flat number. For the exact hours they were on the clock, we look at what the store normally takes in those time slots (last 12 weeks), split it fairly between everyone working those hours, and add a 10% stretch. Busy Friday night → higher bar; slow Tuesday morning → lower bar.</li>
                <li><strong>Pace</strong> is their sales vs. that target. 100%+ (green) means they beat it.</li>
                <li><strong>Sales bonus</strong> is 2% of every dollar they ring <em>above</em> target. <strong>This starts June 15</strong> — the figures here are projections so you can solidify the targets first. No bonus is paid yet.</li>
            </ul>
        </div>
    </div>

    @foreach($stores as $store)
        <div class="box box-primary">
            <div class="box-header with-border"><h3 class="box-title st-store-head">{{ $store['name'] }}</h3></div>
            <div class="box-body">
                <table class="st-table">
                    <thead>
                        <tr>
                            <th style="text-align:left;">Employee</th>
                            <th class="text-right">Hours</th>
                            <th class="text-right">Sales so far</th>
                            <th class="text-right">Target</th>
                            <th class="text-right">Pace</th>
                            <th class="text-right">Sales bonus<div class="st-sub" style="text-transform:none; letter-spacing:0;">projected · from Jun 15</div></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($store['rows'] as $r)
                            <tr>
                                <td style="text-align:left;"><strong>{{ $r->employee }}</strong></td>
                                <td class="text-right">
                                    {{ number_format($r->hours_worked, 1) }}h
                                    @if(($r->hour_peak ?? 0) > 0 || ($r->hour_offpeak ?? 0) > 0)<div class="st-sub">{{ number_format($r->hour_peak, 1) }}h peak · {{ number_format($r->hour_offpeak, 1) }}h off</div>@endif
                                </td>
                                <td class="text-right">${{ number_format($r->non_whatnot_revenue, 0) }}</td>
                                <td class="text-right">
                                    @if(!is_null($r->hour_target))
                                        <span class="st-target">${{ number_format($r->hour_target, 0) }}</span>
                                        <div class="st-sub">+{{ rtrim(rtrim(number_format($r->hour_target_stretch_pct, 1), '0'), '.') }}% vs store rate</div>
                                    @else
                                        <span class="text-muted">—</span>
                                        <div class="st-sub">no store history</div>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if(!is_null($r->hour_pace_pct))
                                        @if($r->hour_pace_pct >= 100)<span class="st-hit">{{ number_format($r->hour_pace_pct, 0) }}%</span>@else<span class="st-miss">{{ number_format($r->hour_pace_pct, 0) }}%</span>@endif
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-right">
                                    @if($r->goal_bonus > 0)
                                        @if($r->sales_bonus_live)<strong>${{ number_format($r->goal_bonus, 2) }}</strong>@else<span class="text-muted">${{ number_format($r->goal_bonus, 2) }}</span>@endif
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted">No one clocked in at this store for the selected period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
</section>
@endsection
