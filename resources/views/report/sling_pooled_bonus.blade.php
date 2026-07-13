@extends('layouts.app')
@section('title', 'Sling-Pooled Bonus (projection)')

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
    .content .text-muted { color: #8E8273 !important; }

    .st-store-head { font-weight: 800; color: #1F1B16; font-size: 15px;
        text-transform: uppercase; letter-spacing: .05em; margin: 0 0 4px; }
    .st-table { width: 100%; }
    .st-table thead tr { color: #8E8273; text-transform: uppercase; font-size: 10px; letter-spacing: .5px; }
    .st-table th { border-bottom: 1px solid #ECE3CF; padding: 6px 10px; }
    .st-table td { border-top: 1px solid #F4ECD9; padding: 8px 10px; vertical-align: top; }
    .st-table tfoot td { border-top: 2px solid #ECE3CF; font-weight: 800; }
    .st-sub { font-size: 11px; color: #9aa0a6; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }
    .up { color: #1b7a32; font-weight: 700; }
    .down { color: #b23b3b; font-weight: 700; }
    .flat { color: #9aa0a6; }
    .tag { display: inline-block; font-size: 10px; font-weight: 700; text-transform: uppercase;
        letter-spacing: .04em; padding: 1px 6px; border-radius: 6px; margin-left: 6px; }
    .tag-lead { background: #EAF3EA; color: #1b7a32; }
    .tag-nosale { background: #FBEEDD; color: #9a5b12; }
    .st-explain { background: #FFF9DB !important; border: 1px solid #E8CF68 !important;
        border-left: 4px solid #E8CF68 !important; color: #5A4410 !important; }
    .st-explain li { margin-bottom: 4px; }
    .st-days { width: 100%; margin-top: 10px; }
    .st-days th, .st-days td { padding: 5px 8px; font-size: 12px; }
    .st-days thead tr { color: #8E8273; text-transform: uppercase; font-size: 10px; letter-spacing: .5px; }
    .st-days th { border-bottom: 1px solid #ECE3CF; }
    .st-days td { border-top: 1px solid #F4ECD9; }

    @media print {
        .st-noprint { display: none !important; }
        .content .box { box-shadow: none !important; border: none !important; }
        body, .content-wrapper { background: #fff !important; }
        a[href]:after { content: ""; }
    }
</style>

<section class="content-header">
    <h1>Sling-Pooled Bonus <small>projection only — pays no one</small></h1>
</section>

<section class="content">
    <div class="box box-primary st-noprint">
        <div class="box-body">
            <div class="row">
                <div class="col-md-6">
                    <form method="GET" action="{{ action('ReportController@slingPooledBonus') }}">
                        <label>Period</label>
                        <select name="period" class="form-control" onchange="this.form.submit()">
                            <option value="today" @if($period==='today') selected @endif>Today</option>
                            <option value="yesterday" @if($period==='yesterday') selected @endif>Yesterday</option>
                            <option value="this_week" @if($period==='this_week') selected @endif>This week</option>
                            <option value="last_week" @if($period==='last_week') selected @endif>Previous week</option>
                            <option value="last_7" @if($period==='last_7') selected @endif>Last 7 days</option>
                            <option value="this_month" @if($period==='this_month') selected @endif>This month</option>
                            <option value="last_30" @if($period==='last_30') selected @endif>Last 30 days</option>
                            <option value="last_month" @if($period==='last_month') selected @endif>Previous month</option>
                        </select>
                    </form>
                    <p class="st-sub" style="margin-top:6px;">
                        {{ $start->format('M j, Y') }} &ndash; {{ $end->format('M j, Y') }}.
                        Compare with the live <a href="{{ url('/reports/shift-targets') }}">Shift Targets</a> page.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <div class="box st-explain">
        <div class="box-body">
            <ul style="margin-bottom:0; padding-left:18px;">
                <li><strong>This is a what-if, not pay.</strong> Nobody is paid from this page. It shows what each person <em>would</em> earn under a pooled model next to what the current per-cashier bonus gives them.</li>
                <li><strong>Current</strong> = today's live bonus: each person earns 4%/2% on the sales <em>they personally rang</em> above their own hour-based target. A product lead who rings nothing earns nothing.</li>
                <li><strong>Pooled</strong> = the proposal: each day the whole store's overage (all non-whatnot sales &minus; the store's historical target for its staffed hours, +10% stretch) pays a flat <strong>4%</strong> into a pot, split among everyone with a <strong>published Sling shift</strong> that day, weighted by their Sling hours. No register login or "who rang it" needed.</li>
                <li><strong>&Delta;</strong> is pooled &minus; current. Green = they'd earn more under pooling (usually the product lead); red = less (usually the person who rang everything).</li>
                <li><strong>Non-floor accounts are left out</strong> of the pool (online fulfillment like Nick, system accounts, departed staff) &mdash; a Sling shift alone doesn't put them in. Per-store cross-assignments are <em>not</em> applied: whoever Sling shows on this store's floor that day shares, so a floor lead who rang nothing still gets a cut.</li>
            </ul>
        </div>
    </div>

    @foreach($stores as $store)
    <div class="box">
        <div class="box-body">
            <div class="st-store-head">{{ $store['name'] }}</div>
            <p class="st-sub" style="margin:0 0 10px;">
                Period totals &mdash; Current: <strong>${{ number_format($store['tot_current'], 2) }}</strong>
                &nbsp;&rarr;&nbsp; Pooled: <strong>${{ number_format($store['tot_pooled'], 2) }}</strong>
                @if(!empty($store['no_sling']))
                    <span class="tag tag-nosale">no published Sling shifts matched this store</span>
                @endif
            </p>

            <table class="st-table">
                <thead>
                    <tr>
                        <th style="text-align:left;">Employee</th>
                        <th class="num">Sling hrs</th>
                        <th class="num">Current bonus</th>
                        <th class="num">Pooled bonus</th>
                        <th class="num">&Delta;</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($store['rows'] as $r)
                    <tr>
                        <td style="text-align:left;">
                            <strong>{{ $r->name }}</strong>
                            @if($r->in_sling && !$r->has_sales)
                                <span class="tag tag-lead">no sales rung</span>
                            @elseif(!$r->in_sling && $r->has_sales)
                                <span class="tag tag-nosale">not in Sling</span>
                            @endif
                        </td>
                        <td class="num">{{ $r->in_sling ? number_format($r->sling_hours, 1) : '—' }}</td>
                        <td class="num">${{ number_format($r->current, 2) }}</td>
                        <td class="num">${{ number_format($r->pooled, 2) }}</td>
                        <td class="num @if(round($r->delta,2) > 0) up @elseif(round($r->delta,2) < 0) down @else flat @endif">
                            @if(round($r->delta,2) > 0)+@endif${{ number_format($r->delta, 2) }}
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="text-center text-muted">No employees with sales or Sling shifts for this period.</td></tr>
                    @endforelse
                </tbody>
                @if(count($store['rows']))
                <tfoot>
                    <tr>
                        <td style="text-align:left;">Total</td>
                        <td class="num">{{ number_format(array_sum(array_map(function($r){ return $r->in_sling ? $r->sling_hours : 0; }, $store['rows'])), 1) }}</td>
                        <td class="num">${{ number_format($store['tot_current'], 2) }}</td>
                        <td class="num">${{ number_format($store['tot_pooled'], 2) }}</td>
                        <td class="num">&nbsp;</td>
                    </tr>
                </tfoot>
                @endif
            </table>

            @if(count($store['days']))
            <details style="margin-top:12px;">
                <summary class="st-sub" style="cursor:pointer;">Show the daily store math (how each day's pool is built)</summary>
                <table class="st-days">
                    <thead>
                        <tr>
                            <th style="text-align:left;">Date</th>
                            <th class="num">Staffed target</th>
                            <th class="num">Actual sales</th>
                            <th class="num">Overage</th>
                            <th class="num">Pool (4%)</th>
                            <th class="num">Staff on</th>
                            <th class="num">Pool hrs</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($store['days'] as $d)
                        <tr>
                            <td style="text-align:left;">{{ \Carbon::parse($d['date'])->format('D M j') }}</td>
                            <td class="num">${{ number_format($d['target'], 0) }}</td>
                            <td class="num">${{ number_format($d['actual'], 0) }}</td>
                            <td class="num @if($d['overage'] > 0) up @else flat @endif">${{ number_format($d['overage'], 0) }}</td>
                            <td class="num">${{ number_format($d['pool'], 2) }}</td>
                            <td class="num">{{ $d['participants'] }}</td>
                            <td class="num">{{ number_format($d['part_hours'], 1) }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </details>
            @endif
        </div>
    </div>
    @endforeach
</section>
@endsection
