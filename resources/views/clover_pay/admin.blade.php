@extends('layouts.app')
@section('title', 'Pay on Clover')

@section('content')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<style>
.cp-shell { font-family: "Inter Tight", system-ui, sans-serif; color: #1F1B16; max-width: 860px; margin: 12px auto 48px; padding: 0 16px; }
.cp-shell h1 { font-size: 26px; font-weight: 800; margin: 12px 0 4px; }
.cp-shell .lead { font-size: 14.5px; color: #2B2620; margin: 0 0 16px; line-height: 1.5; }
.cp-card { background: #fff; border: 1px solid #ECE3CF; border-radius: 12px; padding: 18px 20px; margin-bottom: 16px; }
.cp-card h2 { font-size: 17px; font-weight: 800; margin: 0 0 10px; }
.cp-card label { display: block; font-weight: 700; font-size: 13.5px; margin: 10px 0 4px; }
.cp-card input[type=text], .cp-card input[type=password], .cp-card select { width: 100%; font: inherit; font-size: 14.5px; border: 1px solid #DFD2B3; border-radius: 10px; padding: 9px 11px; }
.cp-btn { background: #1F1B16; color: #fff; border: 0; border-radius: 999px; padding: 9px 18px; font: inherit; font-weight: 800; font-size: 14px; cursor: pointer; text-decoration: none; display: inline-block; }
.cp-btn.light { background: #E8F0F8; color: #13507a !important; border: 1px solid #1b6ca8; }
.cp-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 12px; }
.cp-store { border-top: 1px solid #ECE3CF; padding: 14px 0; }
.cp-store:first-of-type { border-top: 0; padding-top: 0; }
.cp-store .name { font-weight: 800; font-size: 16px; }
.cp-pill { display: inline-block; font-size: 12px; font-weight: 800; border-radius: 999px; padding: 2px 10px; margin-left: 6px; }
.cp-pill.ok { background: #E6F2E7; color: #2E7D32; border: 1px solid #9CCB9F; }
.cp-pill.no { background: #FFF9DB; color: #5A4410; border: 1px solid #E8CF68; }
.cp-pill.off { background: #F3EEE4; color: #2B2620; border: 1px solid #DFD2B3; }
.cp-small { font-size: 13px; color: #2B2620; }
.cp-flash { border-radius: 10px; padding: 12px 16px; margin-bottom: 16px; font-weight: 600; font-size: 14px; }
.cp-flash.ok { background: #E6F2E7; border: 1px solid #9CCB9F; }
.cp-flash.warn { background: #FBEAE5; border: 1px solid #E0A99B; }
.cp-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.cp-table td, .cp-table th { text-align: left; padding: 6px 8px; border-top: 1px solid #ECE3CF; }
.cp-steps { margin: 0; padding-left: 20px; font-size: 14px; line-height: 1.6; }
</style>
@php
    $statusFlash = session('status');
    $hasApp = !empty($cfg['app_id']) && !empty($cfg['app_secret']) && !empty($cfg['raid']);
@endphp
<div class="cp-shell">
    <h1>Pay on Clover</h1>
    <p class="lead">When this is on for a store, the card popup on the POS gets a "Send to Clover" button. The sale total goes straight to that store's Clover Flex, the customer taps their card, and the sale finishes by itself. Nobody keys the amount twice. If anything goes wrong, cashiers can still key it in by hand like today.</p>

    @if($statusFlash && !empty($statusFlash['msg']))
        <div class="cp-flash {{ !empty($statusFlash['success']) ? 'ok' : 'warn' }}">{{ $statusFlash['msg'] }}</div>
    @endif

    <div class="cp-card">
        <h2>1. Clover app @if($hasApp)<span class="cp-pill ok">Saved</span>@else<span class="cp-pill no">Needed</span>@endif</h2>
        <p class="cp-small">From the "Nivessa ERP" app in the Clover developer dashboard (App Settings).</p>
        <form method="POST" action="{{ url('/admin/clover-pay/app') }}">
            {{ csrf_field() }}
            <label>App ID</label>
            <input type="text" name="app_id" value="{{ $cfg['app_id'] ?? '' }}" autocomplete="off">
            <label>App Secret @if(!empty($cfg['app_secret']))<span class="cp-small">(saved, paste only to replace it)</span>@endif</label>
            <input type="password" name="app_secret" value="" autocomplete="new-password">
            <label>RAID (Remote App ID)</label>
            <input type="text" name="raid" value="{{ $cfg['raid'] ?? '' }}" autocomplete="off">
            <label>Environment</label>
            <select name="environment">
                <option value="production" {{ ($cfg['environment'] ?? 'production') !== 'sandbox' ? 'selected' : '' }}>Production (real stores)</option>
                <option value="sandbox" {{ ($cfg['environment'] ?? '') === 'sandbox' ? 'selected' : '' }}>Sandbox (testing)</option>
            </select>
            <div class="cp-row"><button type="submit" class="cp-btn">Save app</button></div>
        </form>
    </div>

    <div class="cp-card">
        <h2>2. Stores</h2>
        <ol class="cp-steps">
            <li>Connect the store to Clover (sign in as that store's Clover owner when asked).</li>
            <li>On that store's Flex, open the Cloud Pay Display app and tap Start.</li>
            <li>Pick the Flex below, tap Test the Flex, then turn Pay on Clover on.</li>
        </ol>
        @foreach($locations as $l)
            @php $lc = $svc->locationConfig($l->id); $ready = $svc->isReady($l->id); @endphp
            <div class="cp-store" style="margin-top:12px;">
                <div class="name">{{ $l->name }}
                    @if($ready)<span class="cp-pill ok">On</span>
                    @elseif(!empty($lc['access_token']))<span class="cp-pill no">Connected, not on</span>
                    @else<span class="cp-pill off">Not connected</span>@endif
                </div>
                <div class="cp-small">
                    @if(!empty($lc['connected_at']))Connected {{ date('m/d/y', strtotime($lc['connected_at'])) }}@if(!empty($lc['merchant_id'])) to merchant {{ $lc['merchant_id'] }}@endif.@endif
                    @if(!empty($lc['device_serial'])) Flex serial ending {{ substr($lc['device_serial'], -4) }}.@endif
                </div>
                <div class="cp-row">
                    @if($hasApp)
                        <a class="cp-btn light" href="{{ url('/admin/clover-pay/connect') }}?location_id={{ $l->id }}">{{ !empty($lc['access_token']) ? 'Connect again' : 'Connect to Clover' }}</a>
                    @endif
                    @if(!empty($lc['access_token']))
                        <a class="cp-btn light" href="{{ url('/admin/clover-pay') }}?devices={{ $l->id }}">Find its Flex</a>
                        <form method="POST" action="{{ url('/admin/clover-pay/ping') }}" style="display:inline;">
                            {{ csrf_field() }}<input type="hidden" name="location_id" value="{{ $l->id }}">
                            <button type="submit" class="cp-btn light">Test the Flex</button>
                        </form>
                    @endif
                </div>
                @if(!empty($lc['access_token']))
                    <form method="POST" action="{{ url('/admin/clover-pay/location') }}" class="cp-row">
                        {{ csrf_field() }}
                        <input type="hidden" name="location_id" value="{{ $l->id }}">
                        @if(isset($devices[$l->id]))
                            @if(!empty($devices[$l->id]['success']))
                                <select name="device_serial" style="max-width:360px;">
                                    <option value="">Pick the Flex</option>
                                    @foreach($devices[$l->id]['devices'] as $d)
                                        <option value="{{ $d['serial'] }}" {{ ($lc['device_serial'] ?? '') === $d['serial'] ? 'selected' : '' }}>{{ $d['model'] ?: 'Device' }} ending {{ substr($d['serial'], -4) }}{{ $d['name'] ? ' ('.$d['name'].')' : '' }}</option>
                                    @endforeach
                                </select>
                            @else
                                <span class="cp-small">Could not list devices: {{ $devices[$l->id]['msg'] ?? '' }}</span>
                            @endif
                        @endif
                        <label style="display:flex;gap:8px;align-items:center;margin:0;font-weight:700;">
                            <input type="checkbox" name="enabled" value="1" {{ !empty($lc['enabled']) ? 'checked' : '' }}> Pay on Clover is on for this store
                        </label>
                        <button type="submit" class="cp-btn">Save</button>
                    </form>
                @endif
            </div>
        @endforeach
    </div>

    <div class="cp-card">
        <h2>Recent Pay on Clover attempts</h2>
        @if(empty($attempts))
            <p class="cp-small">None yet.</p>
        @else
            <table class="cp-table">
                <tr><th>When</th><th>Who</th><th>Amount</th><th>Result</th><th>Clover payment</th></tr>
                @foreach($attempts as $a)
                    <tr>
                        <td>{{ !empty($a['created_at']) ? date('m/d/y g:ia', strtotime($a['created_at'])) : '' }}</td>
                        <td>{{ $a['user'] ?? '' }}</td>
                        <td>${{ number_format(($a['amount_cents'] ?? 0) / 100, 2) }}</td>
                        <td>{{ ucfirst($a['status'] ?? '') }}@if(!empty($a['msg']) && ($a['status'] ?? '') !== 'paid') ({{ $a['msg'] }})@endif</td>
                        <td>{{ $a['clover_payment_id'] ?? '' }}</td>
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
</div>
@endsection
