@extends('layouts.app')
@section('title', 'Shift Commission')

@section('content')
<section class="content-header">
    <h1>Shift Commission</h1>
    <p class="text-muted">Per shift: <strong>2% of sales</strong> (split among the floor staff sharing the one register), plus each person's individual <strong>2% over their goal</strong> (not split). Goal numbers come from the ERP, so they match what each employee sees. Read-only — verify here, then pay.</p>
</section>

<style>
.sc-wrap { max-width: 1040px; }
.sc-card { background: #FFFDF5; border: 1px solid #E6CE5A; border-radius: 12px; padding: 16px 18px; margin-bottom: 18px; }
.sc-field label { display:block; font-size:12px; font-weight:700; color:#5b6470; margin-bottom:4px; }
.sc-field input, .sc-field select { padding:8px 10px; border:1px solid #d9d2b8; border-radius:8px; font-size:14px; background:#fff; }
.sc-btn { background:#FFF2B3; border:1px solid #E6CE5A; border-radius:8px; padding:9px 18px; font-size:14px; font-weight:700; color:#6b5a00; cursor:pointer; }
.sc-btn:hover { background:#FFE9A8; }
table.sc { width:100%; border-collapse:collapse; }
table.sc th, table.sc td { padding:8px 10px; font-size:13px; border-bottom:1px solid #eee; text-align:right; }
table.sc th:first-child, table.sc td:first-child { text-align:left; }
table.sc th { font-size:11px; color:#5b6470; text-transform:none; background:#faf7ea; }
table.sc td.total { font-weight:800; background:#FFF3C4; }
.muted { color:#9aa4b0; }
details > summary { cursor:pointer; font-weight:600; color:#23303d; margin:10px 0; }
</style>

<section class="content sc-wrap">

@if ($error)<div class="alert alert-danger">{{ $error }}</div>@endif

<form method="GET" action="{{ url('/admin/shift-commission') }}" class="sc-card" style="display:flex; gap:14px; align-items:flex-end; flex-wrap:wrap;">
    <div class="sc-field"><label>Date</label><input type="date" name="date" value="{{ $date }}"></div>
    <div class="sc-field"><label>Store</label>
        <select name="location_id">
            <option value="">- pick store -</option>
            @foreach ($locations as $lid => $lname)
                <option value="{{ $lid }}" {{ (int) $location_id === (int) $lid ? 'selected' : '' }}>{{ $lname }}</option>
            @endforeach
        </select>
    </div>
    <button type="submit" class="sc-btn">Calculate</button>
</form>

@if ($result)
    <div class="sc-card">
        <div style="font-size:15px; margin-bottom:6px;"><strong>{{ $result['store'] }}</strong> — {{ \Carbon::parse($result['date'])->format('l, M j, Y') }} · store sales ${{ number_format($result['store_sales'], 2) }}</div>
        @if ($result['goal_note'])<div class="alert alert-warning" style="margin:8px 0;">{{ $result['goal_note'] }}</div>@endif

        @if (count($result['people']) === 0)
            <p class="muted">No floor shifts / sales found for that store and day.</p>
        @else
        <table class="sc">
            <thead>
                <tr>
                    <th>Person</th>
                    <th>Floor shift</th>
                    <th title="What this person personally rang">Rang</th>
                    <th title="Their personal goal for the day (from the ERP)">Goal</th>
                    <th title="Amount they rang over their goal">Over</th>
                    <th title="Their solo-floor 2% + their individual 2% over-goal bonus (not split)">Sales comm</th>
                    <th title="2% of the sales rung while they shared the floor, split evenly — the listening-party portion">Party comm</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($result['people'] as $p)
                    <tr>
                        <td>{{ $p['name'] }}<br><span class="muted" style="font-size:11px;">{{ $p['positions'] }}</span></td>
                        <td style="text-align:left;">{{ $p['shift'] }}</td>
                        <td>${{ number_format($p['own_rung'], 2) }}</td>
                        <td>@if($p['goal'] > 0)${{ number_format($p['goal'], 2) }}@else<span class="muted">—</span>@endif</td>
                        <td>@if($p['over'] > 0)${{ number_format($p['over'], 2) }}@else<span class="muted">—</span>@endif</td>
                        <td>${{ number_format($p['sales_comm'], 2) }}<br><span class="muted" style="font-size:11px;">base ${{ number_format($p['solo_base'], 2) }} + goal ${{ number_format($p['bonus'], 2) }}</span></td>
                        <td>@if($p['party_comm'] > 0)${{ number_format($p['party_comm'], 2) }}@else<span class="muted">—</span>@endif</td>
                        <td class="total">${{ number_format($p['total'], 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="muted" style="font-size:12px; margin-top:8px;"><strong>Sales comm</strong> = their solo-floor 2% + their individual over-goal bonus (2% on what they rang above their goal — not split). <strong>Party comm</strong> = 2% of the sales rung while they shared the one register with another floor person, split evenly. Goal &amp; Rang come from the ERP's own sales-commission calc.</p>
        @endif
    </div>
@endif

</section>
@endsection
