@extends('layouts.app')
@section('title', 'Store Manager Bonus')

@section('content')
<section class="content-header">
    <h1>Store Manager Bonus</h1>
    <p style="color:#222;">Each month the store manager earns {{ rtrim(rtrim(number_format($rate, 2), '0'), '.') }}% of the store's sales (before tax) above the same month last year, as long as the store stayed within its labor-hour budget. Starts 12/01/26, paid at the month-end payroll.</p>
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

    @foreach ($rows as $r)
        <div class="mb-card">
            <h3 style="margin-top:0;">{{ $r['store'] }}: {{ $r['manager'] }}</h3>
            @if (!$r['through'])
                <p>This month hasn't started yet.</p>
            @else
                <table>
                    <tr><th>{{ $r['complete'] ? 'Whole month' : 'Month so far (through ' . $r['through'] . ')' }}</th><th>Amount</th></tr>
                    <tr><td>Store sales this year</td><td>${{ number_format($r['sales'], 2) }}</td></tr>
                    <tr><td>Same days last year</td><td>${{ number_format($r['ly_sales'], 2) }}</td></tr>
                    <tr><td>Growth</td><td>${{ number_format($r['growth'], 2) }}@if($r['growth_pct'] !== null) ({{ $r['growth_pct'] > 0 ? '+' : '' }}{{ $r['growth_pct'] }}%)@endif</td></tr>
                    <tr><td>Labor hours (time clock) vs budget</td><td>{{ number_format($r['hours'], 1) }} of {{ number_format($r['budget']) }} hrs
                        @if ($r['within_budget'])<span class="mb-ok">within budget</span>@else<span class="mb-no">over budget, no bonus</span>@endif</td></tr>
                    <tr><td><b>Bonus earned</b></td><td class="mb-big">${{ number_format($r['earned'], 2) }}</td></tr>
                    @if ($full)
                        <tr><td>Paid</td><td>${{ number_format($r['paid'], 2) }}</td></tr>
                    @endif
                </table>
                @if ($full && $r['owed'] > 0)
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

            <h4 style="margin-top:18px;">Examples for {{ \Carbon::parse($month . '-01')->format('F') }} (last year this month: ${{ number_format($r['ly_full'], 0) }})</h4>
            <table>
                <tr><th>If {{ $r['store'] }} sells</th><th>Growth</th><th>Bonus (within labor budget)</th></tr>
                @foreach ([0, 5, 10, 15, 20, 30] as $pct)
                    @php $s = $r['ly_full'] * (1 + $pct / 100); $g = $s - $r['ly_full']; @endphp
                    <tr><td>${{ number_format($s, 0) }}</td><td>{{ $pct === 0 ? 'same as last year' : '+' . $pct . '% ($' . number_format($g, 0) . ')' }}</td><td>${{ number_format($g * $rate / 100, 2) }}</td></tr>
                @endforeach
                <tr><td>Below last year</td><td>any drop</td><td>$0.00</td></tr>
            </table>
        </div>
    @endforeach

    @if ($full && count($payouts))
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
