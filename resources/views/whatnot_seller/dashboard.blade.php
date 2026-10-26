@extends('layouts.app')
@section('title', 'Whatnot')

@section('content')
@php
    $WS = \App\Services\WhatnotSeller::class;
    $name = $s['seller_name'];
    $underLines = array_values(array_filter($lines, function ($l) { return $l['sticker'] && $l['real'] && $l['sold'] < $l['sticker'] - 0.009; }));
    $overLines = array_values(array_filter($lines, function ($l) { return $l['sticker'] && $l['real'] && $l['sold'] > $l['sticker'] + 0.009; }));
    $newLines = array_values(array_filter($lines, function ($l) use ($s) { return $l['days_in_store'] !== null && $l['days_in_store'] > 0 && $l['days_in_store'] < $s['min_days_in_store']; }));
    $tab = request('show', 'all');
    $tabs = [
        'all'   => ['All items', $lines],
        'under' => ['Under sticker', $underLines],
        'over'  => ['Over sticker', $overLines],
        'new'   => ['Sold too soon', $newLines],
    ];
    if (!isset($tabs[$tab])) { $tab = 'all'; }
    $shown = $tabs[$tab][1];
    $qs = function ($extra) use ($from, $to) { return url('/reports/whatnot-seller') . '?' . http_build_query(array_merge(['start_date' => $from, 'end_date' => $to], $extra)); };
    $fmt = function ($d) { return \Carbon\Carbon::parse($d)->format('m/d/y'); };
    $today = now('America/Los_Angeles')->format('Y-m-d');
    $isOwedView = $from === $owed_from && $to === $today;
@endphp
<style>
    .wn { color: #222; }
    .wn-card { background: #fff; border-radius: 10px; padding: 16px 18px; margin-bottom: 16px; box-shadow: 0 1px 2px rgba(0,0,0,.08); }
    .wn-big { font-size: 34px; font-weight: 700; line-height: 1.15; }
    .wn-label { font-size: 16px; font-weight: 700; margin-bottom: 6px; }
    .wn-sub { font-size: 14px; }
    .wn-red { color: #a12622; }
    .wn-green { color: #2b6a2b; }
    .wn-pay td { padding: 3px 0; font-size: 15px; }
    .wn-pay td.num { text-align: right; padding-left: 24px; }
    .wn-tabs a { display: inline-block; padding: 6px 14px; border-radius: 18px; margin: 0 6px 8px 0; background: #f0f0f0; color: #222; font-weight: 600; }
    .wn-tabs a.on { background: #d7ebfb; color: #0b4a7a; }
    .wn table td, .wn table th { color: #222; vertical-align: top; }
    .wn details > summary { cursor: pointer; font-size: 16px; font-weight: 700; list-style: none; }
    .wn details > summary::-webkit-details-marker { display: none; }
    .wn details > summary::before { content: '+ '; }
    .wn details[open] > summary::before { content: '- '; }
    .wn-set input.form-control { display: inline-block; width: 80px; margin: 0 4px; }
    .wn-set input.wn-date { width: 160px; }
    .wn-set p { font-size: 15px; margin: 0 0 12px; line-height: 2.2; }
</style>

<section class="content-header">
    <h1>Whatnot: {{ $name }}</h1>
</section>

<section class="content wn">
    @if(session('status'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">{{ session('status.msg') }}</div>
    @endif

    <div class="row">
        <div class="col-md-5">
            <div class="wn-card">
                <div class="wn-label">Owed now</div>
                <div class="wn-big">${{ number_format($owed_now['total'], 2) }}</div>
                <div class="wn-sub" style="margin:4px 0 10px;">for {{ $fmt($owed_from) }} to today</div>
                <table class="wn-pay">
                    <tr><td>{{ (int) $owed_now['items'] - (int) $owed_now['premium_items'] }} items x ${{ number_format($s['per_item'], 2) }}</td><td class="num">${{ number_format(((int) $owed_now['items'] - (int) $owed_now['premium_items']) * $s['per_item'], 2) }}</td></tr>
                    @if($owed_now['premium_items'] > 0)<tr><td>{{ (int) $owed_now['premium_items'] }} items 10%+ over sticker x ${{ number_format($s['premium_per_item'], 2) }}</td><td class="num">${{ number_format($owed_now['premium_items'] * $s['premium_per_item'], 2) }}</td></tr>@endif
                    <tr><td>{{ $WS::hoursLabel($owed_now['minutes']) }} on Whatnot ({{ $owed_now['shows'] }} shows) x ${{ number_format($s['hourly_rate'], 2) }}</td><td class="num">${{ number_format($owed_now['hour_pay'], 2) }}</td></tr>
                    <tr><td>Tips</td><td class="num">${{ number_format($owed_now['tips'], 2) }}</td></tr>
                </table>
                <div style="margin-top:12px;">
                    <a class="btn btn-primary" target="_blank" href="{{ url('/reports/whatnot-seller/statement') . '?' . http_build_query(['start_date' => $owed_from, 'end_date' => $today]) }}">Pay statement</a>
                </div>
                @if($s['last_paid_on'])
                    <div class="wn-sub" style="margin-top:12px;">Last paid {{ $fmt($s['last_paid_on']) }}: ${{ number_format($s['last_paid_amount'], 2) }}, for shows through {{ $fmt($s['paid_thru']) }}.</div>
                @endif
            </div>
        </div>
        <div class="col-md-7">
            <div class="wn-card">
                @php
                    $known = array_values(array_filter($lines, function ($l) { return $l['real'] && $l['sticker']; }));
                    $kSt = 0; $kSold = 0; $kAt = 0; $k10 = 0;
                    foreach ($known as $l) {
                        $kSt += $l['sticker'] * $l['qty']; $kSold += $l['sold'] * $l['qty'];
                        if (abs($l['sold'] - $l['sticker']) < 0.01) { $kAt++; }
                        if ($l['sold'] < $l['sticker'] * $s['min_pct_of_sticker'] / 100 - 0.009) { $k10++; }
                    }
                    $kPct = $kSt > 0 ? round(($kSt - $kSold) / $kSt * 100) : 0;
                @endphp
                <div class="wn-label">How he's pricing</div>
                <table class="wn-pay" style="width:100%;">
                    <tr><td>Records with a known Whatnot price</td><td class="num">{{ count($known) }} of {{ count($lines) }}</td></tr>
                    <tr><td>Sticker total</td><td class="num">${{ number_format($kSt, 2) }}</td></tr>
                    <tr><td>Sold for</td><td class="num">${{ number_format($kSold, 2) }}</td></tr>
                    <tr><td><strong>Difference</strong></td><td class="num {{ $kSold < $kSt ? 'wn-red' : 'wn-green' }}"><strong>{{ $kSold < $kSt ? '-' : '+' }}${{ number_format(abs($kSt - $kSold), 2) }} ({{ abs($kPct) }}% {{ $kSold < $kSt ? 'off' : 'over' }})</strong></td></tr>
                    <tr><td>Under sticker</td><td class="num"><a href="{{ $qs(['show' => 'under']) }}">{{ count($underLines) }} records</a></td></tr>
                    <tr><td>More than {{ 100 - $s['min_pct_of_sticker'] }}% off</td><td class="num">{{ $k10 }} records</td></tr>
                    <tr><td>At sticker</td><td class="num">{{ $kAt }} records</td></tr>
                    <tr><td>Over sticker</td><td class="num"><a href="{{ $qs(['show' => 'over']) }}">{{ count($overLines) }} records</a></td></tr>
                    @php
                        $stacks = array_values(array_filter($receipt_rows, function ($r) { return !empty($r['stack']); }));
                        $sRec = 0; $sSt = 0; $sSold = 0;
                        foreach ($stacks as $r) { $sRec += count($r['stack']); $sSold += $r['price']; foreach ($r['stack'] as $c) { $sSt += (float) $c['sticker']; } }
                        $inStackCount = count(array_filter($lines, function ($l) { return $l['stack'] && !$l['real']; }));
                    @endphp
                    <tr><td>Sold in stacks</td><td class="num">{{ count($stacks) }} stacks, {{ $sRec }} records: ${{ number_format($sSt, 2) }} stickers, sold ${{ number_format($sSold, 2) }}</td></tr>
                    @php
                        $cp = 0; $cpN = 0;
                        foreach ($receipt_rows as $r) { if ($r['coupon'] > 0) { $cp += $r['coupon']; $cpN++; } }
                    @endphp
                    <tr><td>Coupons (our Whatnot Rewards Club)</td><td class="num">{{ $cpN }} sales, -${{ number_format($cp, 2) }}</td></tr>
                    <tr><td>Price not known yet</td><td class="num">{{ count($lines) - count($known) - $inStackCount }} records</td></tr>
                </table>
            </div>
        </div>
    </div>

    @if(count($stacks))
    <div class="wn-card">
        <details>
            <summary>Stacks: several records sold as one ({{ count($stacks) }})</summary>
            <div class="table-responsive" style="margin-top:10px;">
                <table class="table table-condensed">
                    <thead><tr><th>Day</th><th>Records in the stack</th><th class="text-right">Stickers</th><th class="text-right">Sold for</th><th class="text-right">Difference</th></tr></thead>
                    <tbody>
                    @foreach($stacks as $r)
                        @php $st = array_sum(array_map(function ($c) { return (float) $c['sticker']; }, $r['stack'])); $d = $r['price'] - $st; @endphp
                        <tr>
                            <td>{{ $fmt($r['date']) }}</td>
                            <td>@foreach($r['stack'] as $c)<div>{{ $c['title'] }}{{ $c['sticker'] ? ' ($' . number_format($c['sticker'], 2) . ')' : '' }}</div>@endforeach</td>
                            <td class="text-right">${{ number_format($st, 2) }}</td>
                            <td class="text-right">${{ number_format($r['price'], 2) }}</td>
                            <td class="text-right {{ $d < -0.009 ? 'wn-red' : ($d > 0.009 ? 'wn-green' : '') }}">{{ abs($d) < 0.01 ? '' : ($d > 0 ? '+' : '-') . '$' . number_format(abs($d), 2) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </details>
    </div>
    @endif

    <div class="wn-card">
        <details>
            <summary>Show hours from Whatnot: {{ $WS::hoursLabel(array_sum(array_column($shows, 'minutes'))) }}, {{ $fmt($from) }} to {{ $fmt($to) }}</summary>
            <table class="table table-condensed" style="margin:0;">
                @forelse($shows as $h)
                    <tr><td>{{ $fmt($h['date']) }}</td><td>{{ $h['note'] }}</td><td>@if($h['url'])<a href="{{ $h['url'] }}" target="_blank">View show on Whatnot</a>@endif</td><td class="text-right">${{ number_format($h['minutes'] / 60 * $s['hourly_rate'], 2) }}</td></tr>
                @empty
                    <tr><td>No Whatnot shows imported for these dates yet.</td></tr>
                @endforelse
            </table>
        </details>
    </div>

    <div class="wn-card">
        <form method="get" class="form-inline" style="margin-bottom:12px;">
            <span class="wn-label" style="margin-right:10px;">Items sold</span>
            <input type="date" name="start_date" value="{{ $from }}" class="form-control input-sm">
            <span style="margin:0 4px;">to</span>
            <input type="date" name="end_date" value="{{ $to }}" class="form-control input-sm">
            <input type="hidden" name="show" value="{{ $tab }}">
            <button class="btn btn-default btn-sm">Show</button>
            @unless($isOwedView)<a href="{{ url('/reports/whatnot-seller') }}" class="btn btn-link btn-sm">Back to since last paid</a>@endunless
        </form>
        <div class="wn-tabs">
            @foreach($tabs as $key => $t)
                <a href="{{ $qs(['show' => $key]) }}" class="{{ $tab === $key ? 'on' : '' }}">{{ $t[0] }} ({{ count($t[1]) }})</a>
            @endforeach
        </div>
        @if($tab === 'new')
            <p>These were in the store less than {{ $s['min_days_in_store'] }} days before they sold on Whatnot.</p>
        @endif
        <div class="table-responsive">
            <table class="table table-striped table-condensed">
                <thead><tr><th>Date</th><th>Item</th><th class="text-right">Sticker</th><th class="text-right">Sold for</th><th class="text-right">Difference</th><th class="text-right">Coupon</th><th class="text-right">Days in store</th><th class="text-right">His pay</th><th>Note</th></tr></thead>
                <tbody>
                @forelse($shown as $l)
                    @php $diff = ($l['sticker'] && $l['real']) ? ($l['sold'] - $l['sticker']) * $l['qty'] : null; @endphp
                    <tr>
                        <td>{{ $l['date'] }}</td>
                        <td><a href="{{ url('/sells/' . $l['transaction_id']) }}" target="_blank">{{ $l['title'] }}</a></td>
                        <td class="text-right">{{ $l['sticker'] === null ? '' : '$' . number_format($l['sticker'] * $l['qty'], 2) }}</td>
                        <td class="text-right">{{ $l['real'] ? '$' . number_format($l['sold'] * $l['qty'], 2) : ($l['stack'] ? 'in a stack' : 'not known yet') }}</td>
                        <td class="text-right {{ $diff !== null && $diff < -0.009 ? 'wn-red' : ($diff !== null && $diff > 0.009 ? 'wn-green' : '') }}">{{ $diff === null || abs($diff) < 0.01 ? '' : ($diff > 0 ? '+' : '-') . '$' . number_format(abs($diff), 2) }}</td>
                        <td class="text-right">{{ $l['coupon'] > 0 ? '-$' . number_format($l['coupon'], 2) : '' }}</td>
                        <td class="text-right">{{ $l['days_in_store'] }}</td>
                        <td class="text-right">${{ number_format($WS::linePay($l), 2) }}</td>
                        <td>{{ $l['note'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9">Nothing here for these dates.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="wn-card">
        <details>
            <summary>Check against Whatnot, day by day</summary>
            <form method="post" action="{{ url('/reports/whatnot-seller/import') }}" enctype="multipart/form-data" class="form-inline" style="margin-bottom:12px;">
                @csrf
                <input type="file" name="csv[]" accept=".csv" multiple class="form-control" style="height:auto;">
                <button class="btn btn-default">Add Whatnot reports or show times</button>
            </form>
            @php $ready = count(array_filter($sku_rows, function ($r) { return $r['status'] === 'ready'; })); @endphp
            <div class="wn-label" style="margin-top:6px;">Record by record (Whatnot sales with our SKU)</div>
            @if(count($sku_rows))
                @if($ready)
                    <form method="post" action="{{ url('/reports/whatnot-seller/import-sku-sales') }}" style="margin-bottom:8px;">@csrf
                        <button class="btn btn-primary btn-sm">Mark {{ $ready }} unmarked {{ $ready === 1 ? 'sale' : 'sales' }} sold in the ERP</button>
                        <span class="wn-sub">at the real Whatnot price, so they come off the website</span>
                    </form>
                @endif
                <div class="table-responsive">
                    <table class="table table-condensed">
                        <thead><tr><th>Day</th><th>SKU</th><th>Item</th><th class="text-right">Whatnot price</th><th class="text-right">Sticker</th><th class="text-right">Off</th><th>In the ERP?</th></tr></thead>
                        <tbody>
                        @foreach($sku_rows as $r)
                            @php $off = $r['sticker'] ? round(($r['sticker'] - $r['price']) / $r['sticker'] * 100) : null; @endphp
                            <tr>
                                <td>{{ $fmt($r['date']) }}</td><td>{{ $r['sku'] }}</td><td>{{ $r['title'] }}</td>
                                <td class="text-right">${{ number_format($r['price'], 2) }}</td>
                                <td class="text-right">{{ $r['sticker'] === null ? '' : '$' . number_format($r['sticker'], 2) }}</td>
                                <td class="text-right {{ $off !== null && $off > 100 - $s['min_pct_of_sticker'] ? 'wn-red' : ($off !== null && $off < 0 ? 'wn-green' : '') }}">{{ $off === null ? '' : ($off < 0 ? '+' . abs($off) . '%' : $off . '%') }}</td>
                                <td>{{ ['marked' => 'Yes', 'ready' => 'No, ready to mark', 'no_stock' => 'No, but stock is already 0', 'not_found' => 'SKU not in the ERP'][$r['status']] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="wn-sub">None yet.</p>
            @endif
            <div class="wn-label" style="margin-top:14px;">Record by record, from Whatnot video receipts</div>
            @if(count($receipt_rows))
                <div class="table-responsive">
                    <table class="table table-condensed">
                        <thead><tr><th>Day</th><th>Whatnot order</th><th>Record in the video</th><th>Matched ERP item</th><th class="text-right">Whatnot price</th><th class="text-right">Sticker</th><th class="text-right">Off</th></tr></thead>
                        <tbody>
                        @foreach($receipt_rows as $r)
                            @php $st = $r['match']['sticker'] ?? null; $off = $st ? round(($st - $r['price']) / $st * 100) : null; @endphp
                            <tr>
                                <td>{{ $fmt($r['date']) }}</td><td>{{ $r['order'] }}</td><td>{{ $r['record'] }}</td>
                                <td>@if($r['match'])<a href="{{ url('/sells/' . $r['match']['transaction_id']) }}" target="_blank">{{ $r['match']['title'] }}</a>@else Not found in the ERP @endif</td>
                                <td class="text-right">${{ number_format($r['price'], 2) }}</td>
                                <td class="text-right">{{ $st === null ? '' : '$' . number_format($st, 2) }}</td>
                                <td class="text-right {{ $off !== null && $off > 100 - $s['min_pct_of_sticker'] ? 'wn-red' : ($off !== null && $off < 0 ? 'wn-green' : '') }}">{{ $off === null ? '' : ($off < 0 ? '+' . abs($off) . '%' : $off . '%') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="wn-sub">None loaded for these dates yet.</p>
            @endif
            <div class="wn-label" style="margin-top:14px;">Day by day</div>
            <div class="table-responsive">
                <table class="table table-condensed">
                    <thead><tr><th>Day</th><th class="text-right">Items on Whatnot</th><th class="text-right">Items in ERP</th><th class="text-right">Not marked</th><th class="text-right">Whatnot sold for</th><th class="text-right">ERP sticker</th><th class="text-right">Difference</th></tr></thead>
                    <tbody>
                    @forelse($daily as $d)
                        @php
                            $miss = $d['wn_items'] === null ? null : $d['wn_items'] - $d['erp_items'];
                            $dd = ($d['wn_total'] === null || $miss !== 0) ? null : round($d['wn_total'] - $d['erp_total'], 2);
                        @endphp
                        <tr>
                            <td>{{ $d['date'] }}</td>
                            <td class="text-right">{{ $d['wn_items'] === null ? 'not loaded' : $d['wn_items'] }}</td>
                            <td class="text-right">{{ $d['erp_items'] }}</td>
                            <td class="text-right {{ $miss !== null && $miss > 0 ? 'wn-red' : '' }}">{{ $miss !== null && $miss > 0 ? $miss : '' }}</td>
                            <td class="text-right">{{ $d['wn_total'] === null ? '' : '$' . number_format($d['wn_total'], 2) }}</td>
                            <td class="text-right">${{ number_format($d['erp_total'], 2) }}</td>
                            @php $ddPct = ($dd !== null && $d['erp_total'] > 0) ? round(-$dd / $d['erp_total'] * 100) : null; @endphp
                            <td class="text-right {{ $ddPct !== null && $ddPct > 100 - $s['min_pct_of_sticker'] ? 'wn-red' : ($dd !== null && $dd > 0.009 ? 'wn-green' : '') }}">{{ $dd !== null && abs($dd) >= 0.01 ? ($dd > 0 ? '+' : '-') . '$' . number_format(abs($dd), 2) . ($ddPct > 0 ? ' (' . $ddPct . '% off)' : '') : '' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7">No Whatnot sales in these dates.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </details>
    </div>

    <div class="wn-card wn-set">
        <details>
            <summary>{{ $name }}'s link, pay rates and rules</summary>
            <div class="input-group" style="margin-bottom:16px;">
                <input type="text" class="form-control" id="wn-link" value="{{ $link }}" readonly style="width:100%;">
                <span class="input-group-btn"><button class="btn btn-default" type="button" onclick="var i=document.getElementById('wn-link');i.select();document.execCommand('copy');this.textContent='Copied';">Copy</button></span>
            </div>
            <form method="post" action="{{ url('/reports/whatnot-seller/settings') }}">
                @csrf
                <input type="hidden" name="seller_name" value="{{ $s['seller_name'] }}">
                <input type="hidden" name="user_id" value="{{ $s['user_id'] }}">
                <p>Pay him $<input class="form-control" type="number" step="0.01" name="per_item" value="{{ $s['per_item'] }}"> per item
                    and $<input class="form-control" type="number" step="0.01" name="hourly_rate" value="{{ $s['hourly_rate'] }}"> per hour on Whatnot, plus tips.</p>
                <p>Items sold more than <input class="form-control" type="number" step="1" name="premium_pct" value="{{ $s['premium_pct'] }}">% over sticker pay
                    $<input class="form-control" type="number" step="0.01" name="premium_per_item" value="{{ $s['premium_per_item'] }}"> instead, starting
                    <input class="form-control wn-date" type="date" name="premium_start" value="{{ $s['premium_start'] }}">.</p>
                <p>He has to write a reason if he sells under
                    <input class="form-control" type="number" name="min_pct_of_sticker" value="{{ $s['min_pct_of_sticker'] }}">% of sticker, or sells something that's been in the store less than
                    <input class="form-control" type="number" name="min_days_in_store" value="{{ $s['min_days_in_store'] }}"> days.</p>
                <p>Last paid on <input class="form-control wn-date" type="date" name="last_paid_on" value="{{ $s['last_paid_on'] }}">
                    $<input class="form-control" style="width:100px;" type="number" step="0.01" name="last_paid_amount" value="{{ $s['last_paid_amount'] }}">,
                    for shows through <input class="form-control wn-date" type="date" name="paid_thru" value="{{ $s['paid_thru'] }}">.</p>
                <button class="btn btn-primary">Save</button>
                <label style="margin-left:14px; font-weight:normal;"><input type="checkbox" name="new_link" value="1"> Make a new link (the old one stops working)</label>
            </form>
        </details>
    </div>
</section>
@endsection
