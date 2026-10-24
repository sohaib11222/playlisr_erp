@extends('layouts.app')
@section('title', 'Whatnot Seller')

@section('content')
@php
    $name = $s['seller_name'];
    $underLines = array_values(array_filter($lines, function ($l) { return $l['sticker'] && $l['sold'] < $l['sticker'] - 0.009; }));
    $overLines = array_values(array_filter($lines, function ($l) { return $l['sticker'] && $l['sold'] > $l['sticker'] + 0.009; }));
    $newLines = array_values(array_filter($lines, function ($l) use ($s) { return $l['days_in_store'] !== null && $l['days_in_store'] > 0 && $l['days_in_store'] < $s['min_days_in_store']; }));
    $tab = request('show', 'under');
    $tabs = [
        'under' => ['Sold under sticker', $underLines],
        'over'  => ['Sold over sticker', $overLines],
        'new'   => ['Sold too soon', $newLines],
        'all'   => ['Everything', $lines],
    ];
    if (!isset($tabs[$tab])) { $tab = 'under'; }
    $shown = $tabs[$tab][1];
    $hrs = rtrim(rtrim(number_format($pay['hours'], 2), '0'), '.') ?: '0';
    $qs = function ($extra) use ($from, $to) { return url('/reports/whatnot-seller') . '?' . http_build_query(array_merge(['start_date' => $from, 'end_date' => $to], $extra)); };
@endphp
<style>
    .wn { color: #222; }
    .wn-card { background: #fff; border-radius: 10px; padding: 16px 18px; margin-bottom: 16px; box-shadow: 0 1px 2px rgba(0,0,0,.08); }
    .wn-big { font-size: 30px; font-weight: 700; line-height: 1.2; }
    .wn-label { font-size: 15px; font-weight: 600; margin-bottom: 4px; }
    .wn-sub { font-size: 14px; margin-top: 4px; }
    .wn-red { color: #a12622; }
    .wn-green { color: #2b6a2b; }
    .wn-tabs { margin: 0 0 12px; }
    .wn-tabs a { display: inline-block; padding: 7px 14px; border-radius: 18px; margin: 0 6px 6px 0; background: #f0f0f0; color: #222; font-weight: 600; }
    .wn-tabs a.on { background: #d7ebfb; color: #0b4a7a; }
    .wn table td, .wn table th { color: #222; vertical-align: top; }
    .wn-set input.form-control { display: inline-block; width: 80px; margin: 0 4px; }
    .wn-set input.wn-date { width: 160px; }
    .wn-set p { font-size: 15px; margin: 0 0 12px; line-height: 2.2; }
</style>

<section class="content-header">
    <h1>Whatnot Seller</h1>
</section>

<section class="content wn">
    @if(session('status'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">{{ session('status.msg') }}</div>
    @endif

    <form method="get" class="form-inline" style="margin-bottom:14px;">
        <input type="date" name="start_date" value="{{ $from }}" class="form-control">
        <span style="margin:0 6px;">to</span>
        <input type="date" name="end_date" value="{{ $to }}" class="form-control">
        <input type="hidden" name="show" value="{{ $tab }}">
        <button class="btn btn-primary">Show</button>
        <a class="btn btn-default" style="margin-left:8px;" target="_blank" href="{{ url('/reports/whatnot-seller/statement') . '?' . http_build_query(['start_date' => $from, 'end_date' => $to]) }}">Pay statement for these dates</a>
    </form>

    <div class="row">
        <div class="col-sm-4">
            <div class="wn-card">
                <div class="wn-label">{{ $name }} is owed</div>
                <div class="wn-big">${{ number_format($pay['total'], 2) }}</div>
                <div class="wn-sub">
                    {{ (int) $pay['items'] - (int) $pay['premium_items'] }} items x ${{ number_format($s['per_item'], 2) }}<br>
                    {{ (int) $pay['premium_items'] }} items 10%+ over sticker x ${{ number_format($s['premium_per_item'], 2) }}<br>
                    {{ $hrs }} show hours x ${{ number_format($s['hourly_rate'], 2) }}
                </div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="wn-card">
                <div class="wn-label">Sold under sticker</div>
                <div class="wn-big {{ $under > 0 ? 'wn-red' : '' }}">${{ number_format($under, 2) }}</div>
                <div class="wn-sub">{{ count($underLines) }} items sold for less than the sticker price</div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="wn-card">
                <div class="wn-label">Sold over sticker</div>
                <div class="wn-big {{ $premium > 0 ? 'wn-green' : '' }}">${{ number_format($premium, 2) }}</div>
                <div class="wn-sub">{{ count($overLines) }} items sold for more than the sticker price</div>
            </div>
        </div>
    </div>

    <div class="wn-card">
        <div class="wn-sub" style="margin:0 0 10px;">{{ (int) $pay['items'] }} items sold on Whatnot for ${{ number_format($total, 2) }}, {{ \Carbon\Carbon::parse($from)->format('m/d/y') }} to {{ \Carbon\Carbon::parse($to)->format('m/d/y') }}. {{ $by_golden }} of them {{ $name }} marked himself, the rest were rung up by staff.</div>
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
                <thead><tr><th>Date</th><th>Item</th><th class="text-right">Sold for</th><th class="text-right">Sticker</th><th class="text-right">Difference</th><th class="text-right">Days in store</th><th>Marked by</th><th class="text-right">His pay</th><th>Reason he gave</th></tr></thead>
                <tbody>
                @forelse($shown as $l)
                    @php $diff = $l['sticker'] ? ($l['sold'] - $l['sticker']) * $l['qty'] : null; @endphp
                    <tr>
                        <td>{{ $l['date'] }}</td>
                        <td><a href="{{ url('/sells/' . $l['transaction_id']) }}" target="_blank">{{ $l['title'] }}</a></td>
                        <td class="text-right">${{ number_format($l['sold'] * $l['qty'], 2) }}</td>
                        <td class="text-right">{{ $l['sticker'] === null ? '' : '$' . number_format($l['sticker'] * $l['qty'], 2) }}</td>
                        <td class="text-right {{ $diff !== null && $diff < -0.009 ? 'wn-red' : ($diff !== null && $diff > 0.009 ? 'wn-green' : '') }}">{{ $diff === null || abs($diff) < 0.01 ? '' : ($diff > 0 ? '+' : '-') . '$' . number_format(abs($diff), 2) }}</td>
                        <td class="text-right">{{ $l['days_in_store'] }}</td>
                        <td>{{ $l['entered_by'] }}</td>
                        <td class="text-right">${{ number_format(\App\Services\WhatnotSeller::linePay($l), 2) }}</td>
                        <td>{{ $l['note'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9">Nothing here for these dates.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="wn-card">
                <div class="wn-label">Show hours {{ $name }} logged</div>
                <table class="table table-condensed" style="margin:6px 0 0;">
                    @forelse($shows as $h)
                        <tr><td>{{ \Carbon\Carbon::parse($h['date'])->format('m/d/y') }}</td><td>{{ $h['hours'] }} hrs</td><td>{{ $h['note'] }}</td>
                            <td class="text-right"><form method="post" action="{{ url('/reports/whatnot-seller/hours/delete') }}" style="display:inline;">@csrf<input type="hidden" name="id" value="{{ $h['id'] }}"><button class="btn btn-xs btn-default">Remove</button></form></td></tr>
                    @empty
                        <tr><td>None yet. He logs his hours on his page after each show.</td></tr>
                    @endforelse
                </table>
            </div>
        </div>
    </div>

    <div class="wn-card">
        <div class="wn-label">Whatnot vs the ERP, day by day</div>
        <p class="wn-sub" style="margin:0 0 10px;">Whatnot's own report shows what really sold and for how much. If Whatnot has more items than the ERP, some were never marked. If Whatnot's total is lower than the ERP sticker total, he sold under sticker that day.
            @if($wn_range) Whatnot data loaded for {{ \Carbon\Carbon::parse($wn_range[0])->format('m/d/y') }} to {{ \Carbon\Carbon::parse($wn_range[1])->format('m/d/y') }}. @endif</p>
        <form method="post" action="{{ url('/reports/whatnot-seller/import') }}" enctype="multipart/form-data" class="form-inline" style="margin-bottom:12px;">
            @csrf
            <input type="file" name="csv[]" accept=".csv" multiple class="form-control" style="height:auto;">
            <button class="btn btn-default">Add Whatnot weekly reports</button>
            <span class="wn-sub" style="margin-left:8px;">From Whatnot Seller Hub, Finances, Statements &amp; Reports, Download CSV.</span>
        </form>
        <div class="table-responsive">
            <table class="table table-condensed">
                <thead><tr><th>Day</th><th class="text-right">Items on Whatnot</th><th class="text-right">Items in ERP</th><th class="text-right">Not marked</th><th class="text-right">Whatnot sold for</th><th class="text-right">ERP sticker</th><th class="text-right">Difference</th><th>Show</th></tr></thead>
                <tbody>
                @forelse($daily as $d)
                    @php
                        $miss = $d['wn_items'] === null ? null : $d['wn_items'] - $d['erp_items'];
                        $dd = ($d['wn_total'] === null || $miss !== 0) ? null : round($d['wn_total'] - $d['erp_total'], 2);
                    @endphp
                    <tr>
                        <td>{{ $d['date'] }}</td>
                        <td class="text-right">{{ $d['wn_items'] === null ? 'no data' : $d['wn_items'] }}</td>
                        <td class="text-right">{{ $d['erp_items'] }}</td>
                        <td class="text-right {{ $miss !== null && $miss > 0 ? 'wn-red' : '' }}">{{ $miss !== null && $miss > 0 ? $miss : '' }}</td>
                        <td class="text-right">{{ $d['wn_total'] === null ? '' : '$' . number_format($d['wn_total'], 2) }}</td>
                        <td class="text-right">${{ number_format($d['erp_total'], 2) }}</td>
                        <td class="text-right {{ $dd !== null && $dd < -0.009 ? 'wn-red' : ($dd !== null && $dd > 0.009 ? 'wn-green' : '') }}">@if($dd !== null && abs($dd) >= 0.01){{ ($dd > 0 ? '+' : '-') . '$' . number_format(abs($dd), 2) }}@elseif($miss !== null && $miss !== 0)<span class="wn-sub">counts differ</span>@endif</td>
                        <td>{{ $d['shows'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8">No Whatnot sales in these dates.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="wn-card wn-set">
        <div class="wn-label">{{ $name }}'s link and rules</div>
        <p style="line-height:1.5;">Send him this link. He doesn't log into the ERP and only sees the items he can sell and his own pay.</p>
        <div class="input-group" style="margin-bottom:16px;">
            <input type="text" class="form-control" id="wn-link" value="{{ $link }}" readonly style="width:100%;">
            <span class="input-group-btn"><button class="btn btn-default" type="button" onclick="var i=document.getElementById('wn-link');i.select();document.execCommand('copy');this.textContent='Copied';">Copy</button></span>
        </div>
        <form method="post" action="{{ url('/reports/whatnot-seller/settings') }}">
            @csrf
            <input type="hidden" name="seller_name" value="{{ $s['seller_name'] }}">
            <input type="hidden" name="user_id" value="{{ $s['user_id'] }}">
            <p>Pay him $<input class="form-control" type="number" step="0.01" name="per_item" value="{{ $s['per_item'] }}"> per item
                and $<input class="form-control" type="number" step="0.01" name="hourly_rate" value="{{ $s['hourly_rate'] }}"> per show hour.</p>
            <p>Items sold more than <input class="form-control" type="number" step="1" name="premium_pct" value="{{ $s['premium_pct'] }}">% over sticker pay
                $<input class="form-control" type="number" step="0.01" name="premium_per_item" value="{{ $s['premium_per_item'] }}"> instead, starting
                <input class="form-control wn-date" type="date" name="premium_start" value="{{ $s['premium_start'] }}">.</p>
            <p>He has to write a reason if he sells under
                <input class="form-control" type="number" name="min_pct_of_sticker" value="{{ $s['min_pct_of_sticker'] }}">% of sticker, or sells something that's been in the store less than
                <input class="form-control" type="number" name="min_days_in_store" value="{{ $s['min_days_in_store'] }}"> days.</p>
            <button class="btn btn-primary">Save</button>
            <label style="margin-left:14px; font-weight:normal;"><input type="checkbox" name="new_link" value="1"> Make a new link (the old one stops working)</label>
        </form>
    </div>
</section>
@endsection
