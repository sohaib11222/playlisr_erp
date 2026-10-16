@extends('layouts.app')

@section('title', 'Pick Route')

@section('content')
{{-- Pick route (Sarah, 2026-10-16): every item still to pull at one spot,
     in walking order (area, then bin or genre, then artist), so the picker
     does one pass instead of running around. Built by
     WebsiteOrdersController::pickRoute(). --}}
<style>
  .pr-wrap { padding:20px; max-width:1100px; }
  .pr-wrap h1 { font-size:22px; margin:0 0 6px; color:#000; }
  .pr-wrap p.lead { color:#000; margin:0 0 14px; }
  .pr-tabs { display:flex; gap:8px; margin:0 0 16px; flex-wrap:wrap; }
  .pr-tabs a { padding:7px 14px; border-radius:999px; border:1px solid #d1d5db; background:#fff; color:#000; font-weight:600; text-decoration:none; }
  .pr-tabs a.on { background:#dbeafe; border-color:#93c5fd; color:#1e40af; }
  .pr-area { font-size:16px; font-weight:700; color:#000; margin:22px 0 6px; padding-bottom:4px; border-bottom:2px solid #e5e7eb; }
  .pr-wrap table { width:100%; border-collapse:collapse; font-size:14px; background:#fff; }
  .pr-wrap th, .pr-wrap td { border-bottom:1px solid #e5e7eb; padding:8px; text-align:left; color:#000; vertical-align:top; }
  .pr-wrap th { font-size:12px; text-transform:uppercase; letter-spacing:.03em; }
  .pr-bin { display:inline-block; padding:2px 8px; border-radius:5px; background:#fde68a; font-weight:700; }
  .pr-n { width:34px; font-weight:700; }
  .pr-box { width:22px; height:22px; }
  .pr-empty { padding:20px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; color:#000; }
  @media print { .pr-tabs, .pr-print { display:none; } }
</style>
<div class="pr-wrap">
  <h1>Pick Route</h1>
  <p class="lead">Everything still to pull, in walking order. Mark items picked on <a href="{{ route('website-orders.index') }}">Website Orders</a> as usual.</p>

  <div class="pr-tabs">
    @foreach(['hollywood' => 'Hollywood Store', 'pico' => 'Pico Store', 'warehouse' => 'Warehouse'] as $key => $label)
      <a href="{{ route('website-orders.pickRoute', ['store' => $key]) }}" class="{{ $store === $key ? 'on' : '' }}">{{ $label }} ({{ $counts[$key] ?? 0 }})</a>
    @endforeach
    <a href="javascript:window.print()" class="pr-print">Print</a>
  </div>

  @if($error)
    <div class="pr-empty">{{ $error }}</div>
  @elseif(empty($rows))
    <div class="pr-empty">Nothing to pull here right now.</div>
  @else
    @php $groups = collect($rows)->groupBy(fn($r) => $r['parts']['area'] !== '' ? $r['parts']['area'] : 'No area saved'); $n = 0; @endphp
    @foreach($groups as $area => $areaRows)
      <div class="pr-area">{{ $area }} ({{ count($areaRows) }})</div>
      <table>
        <thead><tr><th></th><th>#</th><th>{{ $store === 'warehouse' ? 'Bin' : 'Genre' }}</th><th>Item</th><th>Qty</th><th>Order</th></tr></thead>
        <tbody>
          @foreach($areaRows as $r)
            @php
              $n++;
              $it = $r['item'];
              $o = $r['order'];
              $spot = $store === 'warehouse' ? $r['parts']['bin'] : (($r['parts']['genre'] ?? '') !== '' ? $r['parts']['genre'] : $r['parts']['bin']);
              $who = $o['display_name'] ?? (($o['shippingAddress'][0]['name'] ?? null) ?: ($o['user_id']['name'] ?? 'Guest'));
              $how = ($o['fulfillment_method'] ?? null) === 'pickup' ? 'Pickup ' . (($o['pickup_location'] ?? '') === 'pico' ? 'Pico' : 'Hollywood') : 'Ship';
            @endphp
            <tr>
              <td><input type="checkbox" class="pr-box" aria-label="Got it"></td>
              <td class="pr-n">{{ $n }}</td>
              <td>@if($spot !== '')<span class="{{ $store === 'warehouse' ? 'pr-bin' : '' }}">{{ $store === 'warehouse' ? 'Bin ' . $spot : $spot }}</span>@else &mdash; @endif</td>
              <td><strong>{{ $r['name'] }}</strong>@if(!empty($it['product_id']['artist']))<br>{{ $it['product_id']['artist'] }}@endif @if(!empty($it['product_id']['format']))<br><span style="font-size:12px;">{{ $it['product_id']['format'] }}</span>@endif</td>
              <td>{{ (int) ($it['quantity'] ?? 1) }}</td>
              <td>{{ $who }}<br><span style="font-size:12px;">{{ $how }} &middot; {{ !empty($o['createdAt']) ? date('m/d/y', strtotime($o['createdAt'])) : '' }}</span></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endforeach
  @endif
</div>
@endsection
