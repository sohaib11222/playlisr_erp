@extends('layouts.app')
@section('title', 'Listening Party Bonus')

@section('content')
<section class="content-header">
    <h1>Listening Party Bonus</h1>
    <p class="text-muted">Pick the party's date, time window, store, and a %. It pulls the sales rung at that store during the window and splits the % evenly among the staff who worked it.</p>
</section>

<style>
.pb-wrap { max-width: 920px; }
.pb-card { background: #FFFDF5; border: 1px solid #E6CE5A; border-radius: 12px; padding: 18px 20px; margin-bottom: 18px; }
.pb-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; }
.pb-field label { display: block; font-size: 12px; font-weight: 700; color: #5b6470; margin-bottom: 4px; }
.pb-field input, .pb-field select { width: 100%; padding: 8px 10px; border: 1px solid #d9d2b8; border-radius: 8px; font-size: 14px; background: #fff; }
.pb-staff { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
.pb-staff label { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border: 1px solid #d9d2b8; border-radius: 999px; font-size: 13px; cursor: pointer; background: #fff; }
.pb-staff input:checked + span { font-weight: 700; }
.pb-staff label:has(input:checked) { background: #FFF2B3; border-color: #E6CE5A; }
.pb-shift { color: #8a8a8a; font-weight: 400 !important; font-size: 12px; margin-left: 2px; }
.pb-shift.overlap { color: #2F6B3E; }
.pb-staff label:has(input:checked) .pb-shift.overlap { color: #2F6B3E; }
.pb-btn { background: #FFF2B3; border: 1px solid #E6CE5A; border-radius: 8px; padding: 9px 18px; font-size: 14px; font-weight: 700; color: #6b5a00; cursor: pointer; }
.pb-btn:hover { background: #FFE9A8; }
.pb-result { font-size: 15px; }
.pb-result .big { font-size: 22px; font-weight: 800; color: #23303d; }
.pb-table { width: 100%; border-collapse: collapse; margin-top: 12px; }
.pb-table th, .pb-table td { padding: 9px 12px; text-align: left; border-bottom: 1px solid #eee; font-size: 14px; }
.pb-table th { font-size: 12px; color: #5b6470; text-transform: none; }
.pb-amt { width: 120px; padding: 7px 9px; border: 1px solid #d9d2b8; border-radius: 8px; font-size: 14px; text-align: right; }
.text-muted { color: #8a8a8a; }
</style>

<section class="content pb-wrap">

@if (session('status'))
    <div class="alert {{ session('status')['success'] ? 'alert-success' : 'alert-danger' }}">
        {{ session('status')['msg'] }}
    </div>
@endif
@if ($error)
    <div class="alert alert-danger">{{ $error }}</div>
@endif

<div class="pb-card">
    <div style="font-weight:700; margin-bottom:10px;">Recent party payouts</div>
    <form method="GET" action="{{ url('/admin/party-bonus') }}" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
        <div class="pb-field"><label>From</label><input type="date" name="p_start" value="{{ $p_start }}"></div>
        <div class="pb-field"><label>To</label><input type="date" name="p_end" value="{{ $p_end }}"></div>
        <button type="submit" class="pb-btn">Show payouts</button>
        <div style="font-size:13px; color:#5b6470;">{{ count($recent_party) }} payment{{ count($recent_party) === 1 ? '' : 's' }} logged, totaling <strong>${{ number_format($recent_party_total, 2) }}</strong></div>
    </form>
    @if (count($recent_party) > 0)
        <table class="pb-table" style="margin-top:12px;">
            <thead><tr><th>Staff</th><th>Total this period</th></tr></thead>
            <tbody>
                @foreach ($recent_party_by_person as $name => $total)
                    <tr><td>{{ $name }}</td><td style="text-align:right;">${{ number_format($total, 2) }}</td></tr>
                @endforeach
            </tbody>
        </table>
        <details style="margin-top:10px;">
            <summary style="font-size:13px;">Every payment ({{ count($recent_party) }})</summary>
            <table class="pb-table">
                <thead><tr><th>Date</th><th>Staff</th><th>Paid for</th><th style="text-align:right;">Amount</th></tr></thead>
                <tbody>
                    @foreach ($recent_party as $r)
                        <tr>
                            <td>{{ \Carbon::parse($r['date'])->format('M j, Y') }}</td>
                            <td>{{ $r['name'] }}</td>
                            <td style="color:#5b6470;">{{ $r['note'] }}</td>
                            <td style="text-align:right;">${{ number_format($r['amount'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </details>
    @else
        <p class="text-muted" style="margin-top:10px;">No listening-party payments logged {{ $p_start }} to {{ $p_end }}.</p>
    @endif

    @if (count($unpaid_parties) > 0)
        <div style="margin-top:16px; border-top:1px solid #eee; padding-top:12px;">
            <div style="font-weight:700; color:#b3402e;">{{ count($unpaid_parties) }} {{ count($unpaid_parties) === 1 ? 'party' : 'parties' }} with nothing paid out yet</div>
            <p class="text-muted" style="font-size:12px; margin:4px 0 8px;">Auto-estimated at {{ rtrim(rtrim(number_format(\App\Http\Controllers\ListingCommissionController::PARTY_DEFAULT_PERCENT, 2), '0'), '.') }}% of the event's window, split among whoever had a live Sling floor shift (Cashier/Event Lead/Floor Sales) during it. Hit Pay to record it as shown, or Adjust to change staff/amounts first.</p>
            <table class="pb-table">
                <thead><tr><th>Date</th><th>Party</th><th>Store</th><th>Estimated split</th><th></th></tr></thead>
                <tbody>
                    @foreach ($unpaid_parties as $u)
                        @php
                            $est = $u['estimate'];
                            $qs = ['date' => $u['date'], 'event_name' => $u['name']];
                            if ($u['location_id']) { $qs['location_id'] = $u['location_id']; }
                            if ($est) {
                                $qs['percent'] = rtrim(rtrim(number_format($est['percent'], 2), '0'), '.');
                                $qs['from_h'] = $est['from_h']; $qs['from_m'] = $est['from_m']; $qs['from_ap'] = $est['from_ap'];
                                $qs['to_h'] = $est['to_h']; $qs['to_m'] = $est['to_m']; $qs['to_ap'] = $est['to_ap'];
                                $qs['staff'] = array_column($est['staff'], 'uid');
                            }
                        @endphp
                        <tr>
                            <td style="vertical-align:top;">{{ \Carbon::parse($u['date'])->format('M j, Y') }}</td>
                            <td style="vertical-align:top;">{{ $u['name'] }}</td>
                            <td style="vertical-align:top;">{{ $u['location_name'] ?: '?' }}</td>
                            <td>
                                @if (!$est)
                                    <span class="text-muted">Pick a store to estimate</span>
                                @elseif (count($est['staff']) === 0)
                                    <span class="text-muted">${{ number_format($est['sales'], 2) }} rung {{ $est['window'] }}, but nobody had a floor shift on file then - nothing to estimate</span>
                                @elseif ($est['solo'])
                                    <span class="text-muted">Only {{ $est['staff'][0]['name'] }} was on the floor {{ $est['window'] }} - no pool, they're already covered by their normal sales commission</span>
                                @else
                                    <div class="text-muted" style="font-size:11px; margin-bottom:2px;">{{ $est['window'] }} &middot; ${{ number_format($est['sales'], 2) }} sales (goal ${{ number_format($est['sales_goal'] ?? 0, 2) }}) &middot; ${{ number_format($est['pool'], 2) }} pool</div>
                                    @foreach ($est['staff'] as $s)
                                        {{ $s['name'] }}: ${{ number_format($s['amount'], 2) }}@if(!$loop->last), @endif
                                    @endforeach
                                @endif
                            </td>
                            <td style="vertical-align:top; white-space:nowrap;">
                                @if ($est && !$est['solo'] && count($est['staff']) > 0)
                                    <form method="POST" action="{{ url('/admin/party-bonus/pay') }}" style="display:inline;"
                                          onsubmit="return confirm('Pay {{ count($est['staff']) }} {{ count($est['staff']) === 1 ? 'person' : 'people' }} ${{ number_format($est['pool'], 2) }} total for the {{ $u['name'] }}?');">
                                        @csrf
                                        <input type="hidden" name="date" value="{{ $u['date'] }}">
                                        <input type="hidden" name="location_name" value="{{ $u['location_name'] }}">
                                        <input type="hidden" name="event_name" value="{{ $u['name'] }}">
                                        @foreach ($est['staff'] as $s)
                                            <input type="hidden" name="user_id[]" value="{{ $s['uid'] }}">
                                            <input type="hidden" name="amount[]" value="{{ number_format($s['amount'], 2, '.', '') }}">
                                        @endforeach
                                        <button type="submit" class="pb-btn" style="padding:4px 12px; font-size:12px;">Pay ${{ number_format($est['pool'], 2) }}</button>
                                    </form>
                                @endif
                                <a class="pb-btn" style="padding:4px 12px; font-size:12px; background:#fff;" href="{{ url('/admin/party-bonus') }}?{{ http_build_query($qs) }}">Adjust</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

<form method="GET" action="{{ url('/admin/party-bonus') }}" class="pb-card">
    <div class="pb-grid">
        <div class="pb-field"><label>Party date</label><input type="date" name="date" value="{{ $date }}" onchange="this.form.submit()"></div>
        <div class="pb-field"><label>Start time</label>
            <div style="display:flex; gap:6px;">
                <select name="from_h">@for($h=1;$h<=12;$h++)<option value="{{ $h }}" {{ (int) $from_h === $h ? 'selected' : '' }}>{{ $h }}</option>@endfor</select>
                <select name="from_m">@foreach(['00','15','30','45'] as $m)<option value="{{ $m }}" {{ $from_m === $m ? 'selected' : '' }}>{{ $m }}</option>@endforeach</select>
                <select name="from_ap">@foreach(['AM','PM'] as $ap)<option value="{{ $ap }}" {{ $from_ap === $ap ? 'selected' : '' }}>{{ $ap }}</option>@endforeach</select>
            </div>
        </div>
        <div class="pb-field"><label>End time</label>
            <div style="display:flex; gap:6px;">
                <select name="to_h">@for($h=1;$h<=12;$h++)<option value="{{ $h }}" {{ (int) $to_h === $h ? 'selected' : '' }}>{{ $h }}</option>@endfor</select>
                <select name="to_m">@foreach(['00','15','30','45'] as $m)<option value="{{ $m }}" {{ $to_m === $m ? 'selected' : '' }}>{{ $m }}</option>@endforeach</select>
                <select name="to_ap">@foreach(['AM','PM'] as $ap)<option value="{{ $ap }}" {{ $to_ap === $ap ? 'selected' : '' }}>{{ $ap }}</option>@endforeach</select>
            </div>
        </div>
        <div class="pb-field"><label>Store</label>
            <select name="location_id" onchange="this.form.submit()">
                <option value="">- pick store -</option>
                @foreach ($locations as $lid => $lname)
                    <option value="{{ $lid }}" {{ (int) $location_id === (int) $lid ? 'selected' : '' }}>{{ $lname }}</option>
                @endforeach
            </select>
        </div>
        <div class="pb-field"><label>% of window sales</label><input type="number" step="0.1" min="0" name="percent" value="{{ $percent }}" placeholder="e.g. 4"></div>
        <div class="pb-field">
            <label>Which party</label>
            <input type="text" name="event_name" list="pb-events" value="{{ $event_name }}" placeholder="e.g. Kendrick Lamar Listening Party">
            <datalist id="pb-events">
                @foreach ($day_events as $ev)
                    <option value="{{ $ev }}">
                @endforeach
            </datalist>
        </div>
    </div>
    @if ($date && count($day_events) > 1)
        <p class="text-muted" style="margin-top:6px; font-size:12px;">More than one event is on the books for {{ $date }} - pick the right one above.</p>
    @endif
    <div class="pb-field" style="margin-top:14px;">
        <label>Who worked the party</label>
        @if ($location_id && $date)
            <p class="text-muted" style="margin:2px 0 6px; font-size:12px;">Shift times are from Sling. <span style="color:#2F6B3E; font-weight:700;">Green</span> = clocked in during the party window below.</p>
        @else
            <p class="text-muted" style="margin:2px 0 6px; font-size:12px;">Pick a date and store above to see everyone's shift time.</p>
        @endif
        <div class="pb-staff">
            @foreach ($staff as $s)
                @php $st = $shift_times[$s->id] ?? null; @endphp
                <label><input type="checkbox" name="staff[]" value="{{ $s->id }}" {{ in_array((int) $s->id, $selected, true) ? 'checked' : '' }}><span>{{ $s->label }}@if($st)<span class="pb-shift {{ $st['overlaps'] ? 'overlap' : '' }}"> &middot; {{ $st['label'] }}</span>@elseif($location_id && $date)<span class="pb-shift"> &middot; no shift on record</span>@endif</span></label>
            @endforeach
        </div>
    </div>
    <div style="margin-top:16px;"><button type="submit" class="pb-btn">Calculate</button></div>
</form>

@if ($result)
    <div class="pb-card pb-result">
        <div>Sales rung at <strong>{{ $result['location_name'] }}</strong> on <strong>{{ $date }}</strong>, {{ $result['window'] }}@if($event_name) - <strong>{{ $event_name }}</strong>@endif:</div>
        <div class="big">${{ number_format($result['sales'], 2) }}</div>
        @if ($result['solo'])
            <div style="margin-top:6px;" class="text-muted">Only one person checked - no pool. They're already covered by their normal sales commission; a party bonus is for splitting the extra with whoever else shared the floor.</div>
        @else
            <div style="margin-top:6px;">Bonus pool = <strong>{{ rtrim(rtrim(number_format($result['percent'], 2), '0'), '.') }}%</strong> of that = <strong>${{ number_format($result['pool'], 2) }}</strong>@if(count($result['people']) > 0), split {{ count($result['people']) }} ways = <strong>${{ number_format($result['per'], 2) }}</strong> each @endif.</div>
        @endif

        @if (count($result['people']) > 0)
        <form method="POST" action="{{ url('/admin/party-bonus/pay') }}"
              onsubmit="return confirm('Record these party bonus payments?');">
            @csrf
            <input type="hidden" name="date" value="{{ $date }}">
            <input type="hidden" name="location_name" value="{{ $result['location_name'] }}">
            <input type="hidden" name="event_name" value="{{ $event_name }}">
            <table class="pb-table">
                <thead><tr><th>Staff</th><th>Came in</th><th style="text-align:right;">Amount to pay</th></tr></thead>
                <tbody>
                    @foreach ($result['people'] as $p)
                        <tr>
                            <td>{{ $p['name'] }}<input type="hidden" name="user_id[]" value="{{ $p['user_id'] }}"></td>
                            <td style="{{ $p['overlaps'] ? 'color:#2F6B3E;' : 'color:#8a8a8a;' }}">{{ $p['shift'] }}</td>
                            <td style="text-align:right;">$<input type="number" step="0.01" min="0" class="pb-amt" name="amount[]" value="{{ number_format($p['amount'], 2, '.', '') }}"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div style="margin-top:14px;"><button type="submit" class="pb-btn">Record payments</button></div>
            <p class="text-muted" style="margin-top:8px;">Logs to the sales payout ledger, dated {{ $date }}. Undo any time on the <a href="{{ url('/admin/listing-commissions') }}">Commissions page</a>.</p>
        </form>
        @else
            <p class="text-muted" style="margin-top:10px;">Tick who worked the party above to split it.</p>
        @endif
    </div>
@endif

</section>
@endsection
