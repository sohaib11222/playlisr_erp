@extends('layouts.app')
@section('title', 'Confirm Handover Close')

@section('content')
<style>
.hc-shell { max-width: 720px; margin: 24px auto; padding: 0 16px; font-family: "Inter Tight", system-ui, sans-serif; color: #1F1B16; }
.hc-card  { background: #FFF; border: 2px solid #E0A93A; border-radius: 14px; padding: 24px 28px; box-shadow: 0 4px 14px rgba(31,27,22,.08); }
.hc-tag   { display: inline-block; font-size: 11px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; color: #7A4E0A; background: #FFF2B3; padding: 4px 10px; border-radius: 6px; margin-bottom: 14px; }
.hc-h     { font-size: 22px; font-weight: 800; margin: 0 0 10px; letter-spacing: -.01em; }
.hc-meta  { font-size: 14px; color: #5A5045; margin: 0 0 18px; line-height: 1.55; }
.hc-meta strong { color: #1F1B16; }
.hc-amount { background: #F7F1E3; border: 1px solid #DFD2B3; border-radius: 10px; padding: 14px 18px; margin: 16px 0; }
.hc-amount .label { font-size: 12px; font-weight: 800; letter-spacing: .08em; text-transform: uppercase; color: #5A4410; }
.hc-amount .value { font-size: 32px; font-weight: 800; color: #1F1B16; font-variant-numeric: tabular-nums; margin-top: 4px; }
.hc-amount .note  { font-size: 12px; color: #8E8273; margin-top: 6px; }
.hc-field { margin: 18px 0 10px; }
.hc-field label { font-size: 13px; font-weight: 700; color: #1F1B16; display: block; margin-bottom: 6px; }
.hc-field textarea { width: 100%; min-height: 90px; padding: 10px 12px; border: 1.5px solid #DFD2B3; border-radius: 8px; font-family: inherit; font-size: 14px; }
.hc-btn { display: inline-block; background: #1F1B16; color: #FAF6EE; padding: 12px 22px; border: none; border-radius: 8px; font-size: 15px; font-weight: 700; cursor: pointer; }
.hc-btn:hover { background: #3a342c; }
</style>

<div class="hc-shell">
<div class="hc-card">
    <span class="hc-tag">⚠ Confirm handover close</span>
    <h1 class="hc-h">Your shift was closed when the next cashier took over.</h1>

    @if(session('status'))
        @php $s = session('status'); @endphp
        <div style="background:{{ ($s['success'] ?? 0) ? '#E8F3EA' : '#FDF1F1' }}; color:{{ ($s['success'] ?? 0) ? '#1F5A2E' : '#8B2C2C' }}; border:1px solid {{ ($s['success'] ?? 0) ? '#B5DCB8' : '#E6B5B5' }}; padding:10px 14px; border-radius:8px; font-size:13.5px; margin-bottom:14px;">
            {{ $s['msg'] ?? '' }}
        </div>
    @endif

    <p class="hc-meta">
        You opened this shift at <strong>{{ $location_name }}</strong> on
        <strong>{{ $opened_at }}</strong> but didn't close it before leaving.
        @if($closed_at)
            When the next cashier opened a new shift on <strong>{{ $closed_at }}</strong>,
            the system closed yours automatically using their count of the drawer at that moment.
        @endif
    </p>

    <div class="hc-amount">
        <div class="label">Drawer count at handover</div>
        <div class="value">${{ number_format((float) $register->closing_amount, 2) }}</div>
        <div class="note">Counted by the next cashier as their cash-in-hand at open. You can't change this — only acknowledge it.</div>
    </div>

    <form method="POST" action="{{ url('/cash-register/handover-confirm/' . $register->id) }}">
        {!! csrf_field() !!}
        <div class="hc-field">
            <label for="reason">Why didn't you close your shift?</label>
            <textarea name="reason" id="reason" required placeholder="e.g. emergency, forgot, drawer was busy at handoff…"></textarea>
        </div>
        <button type="submit" class="hc-btn">Confirm + continue to POS</button>
    </form>
</div>
</div>
@endsection
