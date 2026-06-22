@extends('layouts.app')
@section('title', 'Goal Breakdown')

@section('content')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<style>
    .content-wrapper, body { background: #FAF6EE !important; }
    .content-header, .content { font-family: "Inter Tight", system-ui, sans-serif !important; color: #1F1B16; }
    .content-header > h1 { font-weight: 800; font-size: 24px; color: #1F1B16; }
    .content-header > h1 small { color: #8E8273; font-weight: 500; }
    .gb-wrap { max-width: 1000px; }
    .gb-card { background:#fff; border:1px solid #ECE3CF; border-radius:12px; box-shadow:0 1px 2px rgba(31,27,22,.06); padding:16px 18px; margin-bottom:14px; }
    .gb-explain { background:#FFF9DB; border:1px solid #E8CF68; border-left:4px solid #E8CF68; color:#5A4410; }
    .gb-day-head { font-weight:800; font-size:14px; margin:0 0 8px; }
    table.gb-table { width:100%; border-collapse:collapse; }
    table.gb-table th, table.gb-table td { padding:7px 10px; font-size:13px; border-bottom:1px solid #F1E9D5; text-align:right; }
    table.gb-table th { color:#8E8273; font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:.4px; background:#F7F1E3; text-align:right; }
    table.gb-table th:first-child, table.gb-table td:first-child { text-align:left; }
    .gb-foot td { font-weight:700; border-top:2px solid #ECE3CF; background:#FBF6E6; }
    .gb-day-totals { display:flex; gap:18px; flex-wrap:wrap; margin-top:10px; font-size:13px; }
    .gb-day-totals .t { color:#8E8273; }
    .gb-hit { color:#1b7a32; font-weight:700; }
    .gb-miss { color:#9aa0a6; font-weight:700; }
    a.gb-back { color:#5A5045; font-size:13px; text-decoration:none; }
</style>

<section class="content-header">
    <h1>{{ $user_name }} — goal breakdown <small>{{ $loc_name }} · {{ $start->format('M j') }}–{{ $end->format('M j, Y') }}</small></h1>
    <p style="margin-top:6px;"><a class="gb-back" href="{{ url('/reports/shift-targets') }}?period={{ $period }}">&larr; Back to Shift Targets</a></p>
</section>

<section class="content">
    <div class="gb-wrap">
        <div class="gb-card gb-explain">
            <strong>How each hour's number is set:</strong> the store rate is what the floor has <em>historically rung</em> in that weekday+hour (last 12 weeks). If more than one person is on the clock that hour, it's split between them (your share). Your day's goal = the sum of your hours' shares, +{{ rtrim(rtrim(number_format($stretch_pct,1),'0'),'.') }}%. You earn 2% of sales above that day's goal.
        </div>

        @forelse($rows as $r)
            <div class="gb-card">
                <div class="gb-day-head">{{ \Carbon::parse($r->date)->format('l, M j') }}</div>
                <table class="gb-table">
                    <thead>
                        <tr><th>Hour</th><th>Store usually rings</th><th>People on</th><th>Your share</th></tr>
                    </thead>
                    <tbody>
                        @foreach($r->slots as $s)
                            <tr>
                                <td>{{ \Carbon::createFromTime($s['hour'])->format('g a') }}–{{ \Carbon::createFromTime($s['hour'])->addHour()->format('g a') }}@if($s['frac'] < 0.99) <span style="color:#9aa0a6;">({{ round($s['frac']*60) }} min)</span>@endif</td>
                                <td>${{ number_format($s['store_rate'], 0) }}/hr</td>
                                <td>{{ $s['head'] }}</td>
                                <td>${{ number_format($s['expected'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="gb-foot"><td colspan="3">Expected for the hours worked</td><td>${{ number_format($r->expected, 2) }}</td></tr>
                    </tfoot>
                </table>
                <div class="gb-day-totals">
                    <span><span class="t">Goal (+{{ rtrim(rtrim(number_format($stretch_pct,1),'0'),'.') }}%):</span> <strong>${{ number_format($r->target, 2) }}</strong></span>
                    <span><span class="t">Sold:</span> <strong>${{ number_format($r->sold, 2) }}</strong></span>
                    <span><span class="t">Over/under:</span> @if($r->sold >= $r->target)<span class="gb-hit">+${{ number_format($r->sold - $r->target, 2) }}</span>@else<span class="gb-miss">-${{ number_format($r->target - $r->sold, 2) }}</span>@endif</span>
                    <span><span class="t">Bonus:</span> <strong>${{ number_format($r->bonus, 2) }}</strong></span>
                </div>
            </div>
        @empty
            <div class="gb-card"><p style="margin:0; color:#8E8273;">No clocked-in hours for {{ $user_name }} at {{ $loc_name }} in this period.</p></div>
        @endforelse

        @if(count($rows) > 0)
            <div class="gb-card" style="background:#1F1B16; color:#FAF6EE;">
                <div class="gb-day-totals" style="margin:0;">
                    <span><span style="color:#C9BE9E;">Total expected:</span> <strong>${{ number_format($total_expected, 2) }}</strong></span>
                    <span><span style="color:#C9BE9E;">Total goal:</span> <strong>${{ number_format($total_target, 2) }}</strong></span>
                    <span><span style="color:#C9BE9E;">Total sold:</span> <strong>${{ number_format($total_sold, 2) }}</strong></span>
                    <span><span style="color:#C9BE9E;">Total bonus:</span> <strong>${{ number_format($total_bonus, 2) }}</strong></span>
                </div>
            </div>
        @endif
    </div>
</section>
@endsection
