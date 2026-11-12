@extends('layouts.app')
@section('title', 'Store Manager Bonus')

@section('content')
<section class="content-header">
    <h1>Store Manager Bonus</h1>
    <p style="color:#222;">Each month you and Jon set a realistic sales plan for each store (Clover net sales, after tax). The manager earns a flat bonus for hitting 100%, 110% or 120% of plan, and only if the store stayed within its labor-hour budget. Starts 12/01/26, paid once a month at the payroll closest to month end.</p>
</section>

<style>
.mb-wrap { max-width: 960px; }
.mb-card { background: #fff; border: 1px solid #ddd; border-radius: 12px; padding: 18px 20px; margin-bottom: 18px; }
.mb-card table { width: 100%; border-collapse: collapse; }
.mb-card th, .mb-card td { padding: 7px 8px; border-bottom: 1px solid #eee; text-align: right; color: #222; }
.mb-card th:first-child, .mb-card td:first-child { text-align: left; }
.mb-big { font-size: 22px; font-weight: 700; }
.mb-ok { color: #1a7f37; font-weight: 700; }
.mb-no { color: #b42318; font-weight: 700; }
</style>

<section class="content mb-wrap">
    <form method="GET" action="{{ url('/admin/manager-bonus') }}" class="mb-card" style="display:flex; gap:10px; align-items:center;">
        <label style="margin:0;">Month</label>
        <select name="month" onchange="this.form.submit()" style="padding:6px 10px; border-radius:8px;">
            @foreach ($months as $m)
                <option value="{{ $m }}" {{ $m === $month ? 'selected' : '' }}>{{ \Carbon::parse($m . '-01')->format('F Y') }}</option>
            @endforeach
            @if (!in_array($month, $months, true))
                <option value="{{ $month }}" selected>{{ \Carbon::parse($month . '-01')->format('F Y') }}</option>
            @endif
        </select>
        @if ($before_start)
            <span style="color:#b42318;">Before the 12/01/26 start, shown for reference only, nothing is owed.</span>
        @endif
    </form>

    <form method="POST" action="{{ url('/admin/manager-bonus/plan') }}" class="mb-card" style="display:flex; gap:14px; align-items:flex-end; flex-wrap:wrap;">
        @csrf
        <input type="hidden" name="month" value="{{ $month }}">
        <b style="width:100%;">Set the {{ \Carbon::parse($month . '-01')->format('F Y') }} plan</b>
        @foreach ($rows as $r)
            <label style="margin:0;">{{ $r['store'] }}<br><input type="text" name="plan_{{ $r['location_id'] }}" value="{{ $r['plan_set'] ? number_format($r['plan'], 0, '.', '') : '' }}" placeholder="{{ number_format($r['suggested'], 0, '.', '') }}" style="padding:6px 10px; border-radius:8px; border:1px solid #ccc; width:140px;"></label>
        @endforeach
        <button class="btn btn-primary">Save plan</button>
    </form>

    @if (!empty($check))
        <div class="mb-card">
            <h4 style="margin-top:0;">Clover check</h4>
            <table>
                <tr><th>Store</th><th>Month</th><th>Pulled from Clover</th><th>ERP Clover sync</th></tr>
                @foreach ($check as $c)
                    <tr><td>{{ $c['store'] }}</td><td>{{ $c['month'] }}</td><td>{{ $c['pulled'] !== null ? '$' . number_format($c['pulled'], 2) : 'n/a' }}</td><td>${{ number_format($c['synced'], 2) }}</td></tr>
                @endforeach
            </table>
        </div>
    @endif

    @foreach ($rows as $r)
        <div class="mb-card">
            <h3 style="margin-top:0;">{{ $r['store'] }}: {{ $r['manager'] }}</h3>
            <table>
                <tr><th>Setting the plan</th><th>Clover net sales</th></tr>
                <tr><td>Same month last year ({{ \Carbon::parse($r['ly_month'] . '-01')->format('M Y') }})</td><td>${{ number_format($r['ly_full'], 2) }}</td></tr>
                <tr><td>Recent pace (last 3 finished months vs a year earlier)</td><td>{{ $r['pace'] > 0 ? '+' : '' }}{{ $r['pace'] }}%</td></tr>
                <tr><td>Suggested plan (last year + half the recent growth)</td><td>${{ number_format($r['suggested'], 0) }}</td></tr>
                <tr><td><b>Plan for {{ \Carbon::parse($month . '-01')->format('F') }}</b></td><td><b>@if($r['plan_set'])${{ number_format($r['plan'], 0) }}@else<span class="mb-no">not set yet, no bonus until it is</span>@endif</b></td></tr>
            </table>

            <h4 style="margin-top:18px;">Last 5 months (Clover net sales)</h4>
            <table>
                <tr><th>Month</th><th>Sales</th><th>Same month last year</th><th>Change</th></tr>
                @foreach (array_reverse($r['hist'], true) as $hm => $hv)
                    <tr><td>{{ \Carbon::parse($hm . '-01')->format('F Y') }}</td><td>${{ number_format($hv[0], 0) }}</td><td>${{ number_format($hv[1], 0) }}</td><td>@if($hv[1] > 0){{ $hv[0] >= $hv[1] ? '+' : '' }}{{ round(($hv[0] / $hv[1] - 1) * 100) }}%@endif</td></tr>
                @endforeach
            </table>

            <h4 style="margin-top:18px;">Bonus</h4>
            <table>
                <tr><th>Hit</th><th>{{ $r['store'] }} sales (Clover net)</th><th>Bonus</th></tr>
                @foreach ($r['tiers'] as $pct => $amt)
                    <tr><td>{{ $pct }}% of plan</td><td>${{ number_format($r['plan'] * $pct / 100, 0) }}</td><td>${{ number_format($amt, 0) }}</td></tr>
                @endforeach
                <tr><td>Under 100%</td><td>below ${{ number_format($r['plan'], 0) }}</td><td>$0</td></tr>
            </table>

            @if (!$r['through'])
                <p style="margin-top:12px;">This month hasn't started yet.</p>
            @else
                <table style="margin-top:14px;">
                    <tr><th>{{ $r['complete'] ? 'Whole month' : 'Month so far (through ' . $r['through'] . ')' }}</th><th>Amount</th></tr>
                    <tr><td>Clover net sales</td><td>${{ number_format($r['sales'], 2) }}</td></tr>
                    <tr><td>Plan{{ $r['complete'] ? '' : ' for these days' }}</td><td>${{ number_format($r['plan_to_date'], 2) }}</td></tr>
                    <tr><td>% of plan</td><td>{{ $r['pct_of_plan'] }}%</td></tr>
                    @if (!$r['complete'] && $r['next_tier'])
                        <tr><td>To reach {{ $r['next_tier']['pct'] }}% (${{ number_format($r['next_tier']['amount'], 0) }} bonus)</td><td>${{ number_format($r['next_tier']['sales'], 0) }} for the month</td></tr>
                    @endif
                    <tr><td>Labor hours (time clock) vs budget</td><td>{{ number_format($r['hours'], 1) }} of {{ number_format($r['budget']) }} hrs
                        @if ($r['within_budget'])<span class="mb-ok">within budget</span>@else<span class="mb-no">over budget, no bonus</span>@endif</td></tr>
                    <tr><td><b>Bonus earned</b>{{ $r['complete'] ? '' : ' so far' }}</td><td class="mb-big">${{ number_format($r['earned'], 2) }}</td></tr>
                    <tr><td>Paid</td><td>${{ number_format($r['paid'], 2) }}</td></tr>
                </table>
                @if ($r['owed'] > 0)
                    <form method="POST" action="{{ url('/admin/manager-bonus/mark-paid') }}" style="margin-top:10px; text-align:right;">
                        @csrf
                        <input type="hidden" name="location_id" value="{{ $r['location_id'] }}">
                        <input type="hidden" name="month" value="{{ $month }}">
                        <button class="btn btn-primary">Mark ${{ number_format($r['owed'], 2) }} paid</button>
                    </form>
                @elseif (!$r['complete'])
                    <p style="margin-top:8px;">Not final until the month ends.</p>
                @endif
            @endif
        </div>
    @endforeach

    @if (count($payouts))
        <div class="mb-card">
            <h4 style="margin-top:0;">Payments recorded</h4>
            <table>
                <tr><th>Month</th><th>Store</th><th>Manager</th><th>Amount</th><th>Recorded</th><th></th></tr>
                @foreach ($payouts as $p)
                    <tr>
                        <td>{{ $p['month'] }}</td><td>{{ $p['store'] }}</td><td>{{ $p['name'] }}</td>
                        <td>${{ number_format($p['amount'], 2) }}</td>
                        <td>{{ \Carbon::parse($p['marked_at'])->format('m/d/y') }}</td>
                        <td><form method="POST" action="{{ url('/admin/manager-bonus/undo') }}">@csrf<input type="hidden" name="id" value="{{ $p['id'] }}"><button class="btn btn-default btn-xs">Undo</button></form></td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endif
</section>
@endsection
