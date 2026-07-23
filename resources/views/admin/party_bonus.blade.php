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

<form method="GET" action="{{ url('/admin/party-bonus') }}" class="pb-card">
    <div class="pb-grid">
        <div class="pb-field"><label>Party date</label><input type="date" name="date" value="{{ $date }}"></div>
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
            <select name="location_id">
                <option value="">- pick store -</option>
                @foreach ($locations as $lid => $lname)
                    <option value="{{ $lid }}" {{ (int) $location_id === (int) $lid ? 'selected' : '' }}>{{ $lname }}</option>
                @endforeach
            </select>
        </div>
        <div class="pb-field"><label>% of window sales</label><input type="number" step="0.1" min="0" name="percent" value="{{ $percent }}" placeholder="e.g. 4"></div>
    </div>
    <div class="pb-field" style="margin-top:14px;">
        <label>Who worked the party</label>
        <div class="pb-staff">
            @foreach ($staff as $s)
                <label><input type="checkbox" name="staff[]" value="{{ $s->id }}" {{ in_array((int) $s->id, $selected, true) ? 'checked' : '' }}><span>{{ $s->label }}</span></label>
            @endforeach
        </div>
    </div>
    <div style="margin-top:16px;"><button type="submit" class="pb-btn">Calculate</button></div>
</form>

@if ($result)
    <div class="pb-card pb-result">
        <div>Sales rung at <strong>{{ $result['location_name'] }}</strong> on <strong>{{ $date }}</strong>, {{ $result['window'] }}:</div>
        <div class="big">${{ number_format($result['sales'], 2) }}</div>
        <div style="margin-top:6px;">Bonus pool = <strong>{{ rtrim(rtrim(number_format($result['percent'], 2), '0'), '.') }}%</strong> of that = <strong>${{ number_format($result['pool'], 2) }}</strong>@if(count($result['people']) > 0), split {{ count($result['people']) }} ways = <strong>${{ number_format($result['per'], 2) }}</strong> each @endif.</div>

        @if (count($result['people']) > 0)
        <form method="POST" action="{{ url('/admin/party-bonus/pay') }}"
              onsubmit="return confirm('Record these party bonus payments?');">
            @csrf
            <input type="hidden" name="date" value="{{ $date }}">
            <input type="hidden" name="location_name" value="{{ $result['location_name'] }}">
            <table class="pb-table">
                <thead><tr><th>Staff</th><th style="text-align:right;">Amount to pay</th></tr></thead>
                <tbody>
                    @foreach ($result['people'] as $p)
                        <tr>
                            <td>{{ $p['name'] }}<input type="hidden" name="user_id[]" value="{{ $p['user_id'] }}"></td>
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
