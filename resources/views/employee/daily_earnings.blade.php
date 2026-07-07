@extends('layouts.app')
@section('title', 'Daily Earnings — All Employees')

@section('content')
<script>document.body.classList.add('role-picker');</script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">

<style>
body.role-picker { background: #FAF6EE; font-family: "Inter Tight", system-ui, sans-serif; -webkit-font-smoothing: antialiased; color: #1F1B16; }
body.role-picker .content-wrapper { background: #FAF6EE !important; }
body.role-picker .content-header { background: transparent; padding: 28px 16px 8px; }
body.role-picker .content-header h1 { font-size: 26px; font-weight: 700; letter-spacing: -0.2px; color: #1F1B16; margin: 0 0 6px; }
body.role-picker .content-header p { color: #5A5045; margin: 0; font-size: 14px; max-width: 760px; }
body.role-picker .de-wrap { max-width: 1040px; padding: 0 16px 60px; }
body.role-picker .de-card { background: #FFFFFF; border: 1px solid #ECE3CF; border-radius: 12px; padding: 16px 18px; margin-bottom: 14px; box-shadow: 0 1px 2px rgba(31,27,22,.06); }
body.role-picker .de-stats { display:flex; gap:14px; flex-wrap:wrap; margin-bottom:16px; }
body.role-picker .de-stat { flex:1; min-width:180px; background:#FFFFFF; border:1px solid #ECE3CF; border-radius:12px; padding:16px 18px; box-shadow:0 1px 2px rgba(31,27,22,.06); }
body.role-picker .de-stat .lbl { font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.4px; color:#8E8273; margin-bottom:6px; }
body.role-picker .de-stat .val { font-size:28px; font-weight:800; letter-spacing:-0.5px; }
body.role-picker .de-stat.comm .val { color:#2F6B3E; }
body.role-picker .de-day { display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin:2px 0 10px; flex-wrap:wrap; }
body.role-picker .de-day h3 { margin:0; font-size:17px; font-weight:700; }
body.role-picker .de-day .sub { font-size:13px; color:#6B5E2E; font-weight:600; }
body.role-picker table.de-table { width: 100%; border-collapse: collapse; }
body.role-picker table.de-table th, body.role-picker table.de-table td { padding: 9px 10px; border-bottom: 1px solid #ECE3CF; font-size: 14px; }
body.role-picker table.de-table th { color: #8E8273; font-weight: 600; font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.4px; background: #F7F1E3; text-align:right; }
body.role-picker table.de-table th:first-child, body.role-picker table.de-table td:first-child { text-align:left; }
body.role-picker table.de-table td { text-align:right; }
body.role-picker table.de-table tfoot td { font-weight:700; border-top:2px solid #ECE3CF; border-bottom:0; }
body.role-picker .de-muted { color:#5A5045; font-size:13px; }
body.role-picker .de-pills { display:flex; gap:8px; flex-wrap:wrap; margin:0 0 18px; }
body.role-picker .de-pill { display:inline-flex; align-items:center; min-height:34px; padding:6px 14px; border-radius:999px; border:1px solid #ECE3CF; background:#FFFFFF; color:#5A5045; font-size:13px; font-weight:600; text-decoration:none; }
body.role-picker .de-pill.on { background:#1F1B16; color:#FAF6EE; border-color:#1F1B16; }
body.role-picker .de-note { background:#FBF6E6; border:1px solid #EADFBE; border-radius:10px; padding:12px 16px; font-size:13.5px; color:#5A5045; }
body.role-picker .de-pos { color:#2F6B3E; font-weight:600; }
body.role-picker .de-zero { color:#8E8273; }
body.role-picker .de-filter { display:flex; align-items:center; gap:10px; margin:0 0 18px; }
body.role-picker .de-filter label { font-size:12px; font-weight:600; text-transform:uppercase; letter-spacing:.4px; color:#8E8273; }
body.role-picker .de-filter select { min-height:38px; padding:6px 34px 6px 14px; border-radius:999px; border:1px solid #ECE3CF; background:#FFFFFF; color:#1F1B16; font-family:inherit; font-size:14px; font-weight:600; cursor:pointer; -webkit-appearance:none; appearance:none; background-image:url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%238E8273' d='M6 8 0 0h12z'/%3E%3C/svg%3E"); background-repeat:no-repeat; background-position:right 14px center; }
body.role-picker .de-filter select:focus { outline:none; border-color:#C9BE9C; box-shadow:0 0 0 3px rgba(255,242,179,.6); }
body.role-picker .de-filter input[type=date] { min-height:38px; padding:6px 12px; border-radius:999px; border:1px solid #ECE3CF; background:#FFFFFF; color:#1F1B16; font-family:inherit; font-size:14px; font-weight:600; cursor:pointer; }
body.role-picker .de-filter input[type=date]:focus { outline:none; border-color:#C9BE9C; box-shadow:0 0 0 3px rgba(255,242,179,.6); }
body.role-picker .de-clear { font-size:13px; font-weight:600; color:#6B5E2E; text-decoration:none; }
body.role-picker .de-filter { flex-wrap:wrap; }
</style>

<section class="content">
    <div class="content-header" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;">
        <div>
            <h1>Daily Earnings — All Employees</h1>
            <p>Every employee's sales and the commission they earned, broken out day by day. {{ $range_from }} – {{ $range_to }}. Sales floor only; Whatnot excluded from sales totals.</p>
        </div>
        @include('partials.pin_button', ['pinUrl' => url('/my-earnings/daily'), 'pinLabel' => 'Daily Earnings (All Staff)'])
    </div>

    <div class="de-wrap">
        @php
            $dayGroups = $data['days'];
            $live = $data['live'];
            $g_rang = 0; $g_listed = 0; $g_lpay = 0; $g_bonus = 0; $g_total = 0;
            foreach ($dayGroups as $list) {
                foreach ($list as $r) {
                    $g_rang += $r['register_sales']; $g_listed += $r['listed_sales'];
                    $g_lpay += $r['listing_comm']; $g_bonus += $r['sales_bonus']; $g_total += $r['total_comm'];
                }
            }
        @endphp

        <div class="de-pills">
            <a class="de-pill {{ $pinned_date === $today ? 'on' : '' }}" href="{{ url('/my-earnings/daily') }}?date={{ $today }}{{ $selected_user ? '&user='.$selected_user : '' }}">Today</a>
            @foreach([7 => '7 days', 14 => '14 days', 30 => '30 days', 60 => '60 days'] as $d => $lbl)
                <a class="de-pill {{ (!$pinned_date && (int)$days === $d) ? 'on' : '' }}" href="{{ url('/my-earnings/daily') }}?days={{ $d }}{{ $selected_user ? '&user='.$selected_user : '' }}">{{ $lbl }}</a>
            @endforeach
        </div>

        @php $employees = $data['employees']; asort($employees); @endphp
        <form method="GET" action="{{ url('/my-earnings/daily') }}" class="de-filter" onchange="this.submit()">
            @unless($pinned_date)<input type="hidden" name="days" value="{{ (int) $days }}">@endunless
            <label for="de-emp">Employee</label>
            <select id="de-emp" name="user">
                <option value="0">All employees</option>
                @foreach($employees as $uid => $nm)
                    <option value="{{ $uid }}" {{ (int)$selected_user === (int)$uid ? 'selected' : '' }}>{{ $nm }}</option>
                @endforeach
            </select>
            <label for="de-date">Day</label>
            <input type="date" id="de-date" name="date" value="{{ $pinned_date }}" max="{{ $today }}">
            @if($pinned_date)<a class="de-clear" href="{{ url('/my-earnings/daily') }}{{ $selected_user ? '?user='.$selected_user : '' }}">clear day</a>@endif
            <noscript><button type="submit">Go</button></noscript>
        </form>

        <div class="de-stats">
            <div class="de-stat">
                <div class="lbl">Sales rung</div>
                <div class="val" style="font-size:24px;">${{ number_format($g_rang, 2) }}</div>
            </div>
            <div class="de-stat">
                <div class="lbl">Listed items sold</div>
                <div class="val" style="font-size:24px;">${{ number_format($g_listed, 2) }}</div>
            </div>
            <div class="de-stat comm">
                <div class="lbl">Listing pay</div>
                <div class="val">${{ number_format($g_lpay, 2) }}</div>
            </div>
            <div class="de-stat comm">
                <div class="lbl">Sales bonus{{ $live ? '' : ' (proj.)' }}</div>
                <div class="val">${{ number_format($g_bonus, 2) }}</div>
            </div>
            <div class="de-stat comm">
                <div class="lbl">Total commission</div>
                <div class="val">${{ number_format($g_total, 2) }}</div>
            </div>
        </div>

        @if(empty($dayGroups))
            <div class="de-card"><p class="de-muted">No sales in this window yet.</p></div>
        @else
            @foreach($dayGroups as $date => $list)
                @php
                    $d_rang = 0; $d_listed = 0; $d_lpay = 0; $d_bonus = 0; $d_total = 0;
                    foreach ($list as $r) {
                        $d_rang += $r['register_sales']; $d_listed += $r['listed_sales'];
                        $d_lpay += $r['listing_comm']; $d_bonus += $r['sales_bonus']; $d_total += $r['total_comm'];
                    }
                @endphp
                <div class="de-card">
                    <div class="de-day">
                        <h3>{{ \Carbon::parse($date)->format('l, M j') }}</h3>
                        <span class="sub">${{ number_format($d_rang, 2) }} rung · ${{ number_format($d_total, 2) }} commission</span>
                    </div>
                    <div style="overflow-x:auto;">
                    <table class="de-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Rang</th>
                                <th>Listed sold</th>
                                <th>Listing pay</th>
                                <th>Sales bonus{{ $live ? '' : '*' }}</th>
                                <th>Total earned</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($list as $r)
                                <tr>
                                    <td>{{ $r['employee'] }}</td>
                                    <td>${{ number_format($r['register_sales'], 2) }}</td>
                                    <td>${{ number_format($r['listed_sales'], 2) }}</td>
                                    <td>${{ number_format($r['listing_comm'], 2) }}</td>
                                    <td class="{{ $r['sales_bonus'] > 0 ? 'de-pos' : 'de-zero' }}">${{ number_format($r['sales_bonus'], 2) }}</td>
                                    <td style="font-weight:700;">${{ number_format($r['total_comm'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td>Day total</td>
                                <td>${{ number_format($d_rang, 2) }}</td>
                                <td>${{ number_format($d_listed, 2) }}</td>
                                <td>${{ number_format($d_lpay, 2) }}</td>
                                <td>${{ number_format($d_bonus, 2) }}</td>
                                <td>${{ number_format($d_total, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                    </div>
                </div>
            @endforeach
        @endif

        <div class="de-note">
            Listing pay = 2% of the pre-tax sale value of items each person listed that sold that day (used items, since May 15).
            Sales bonus = 2% of dollars rung over that day's target, 4% on the peak-hour share.
            @unless($live) *Sales bonus isn't live yet — those figures are projections and aren't included in total commission. @endunless
        </div>
    </div>
</section>
@endsection
