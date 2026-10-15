@extends('layouts.app')

@section('title', 'Website Order')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>
@include('events.partials._styles')
@include('website_admin.warehouse._styles')

@php $tz = 'America/Los_Angeles'; @endphp

<div class="ev-wrap ev-wrap-wide">
  @if(!$order)
    <div class="ev-head"><div><h1>Order not found</h1><p class="sub">{{ $error }}</p></div></div>
    @include('website_admin.warehouse._nav')
  @else
    @php
      $st = $order['order_status'] ?? 'pending';
      $stColor = ['shipped' => 'green', 'processing' => 'blue', 'pending' => 'yellow', 'cancelled' => 'red'][strtolower($st)] ?? '';
      $ship = is_array($order['shippingAddress'] ?? null) ? ($order['shippingAddress'][0] ?? null) : null;
    @endphp
    <div class="ev-head">
      <div>
        <h1>Order Details</h1>
        <p class="sub">Order #{{ $order['orderNumber'] ?? substr($order['_id'] ?? '', -8) }} · <span class="wh-pill blue">Website</span> <span class="wh-pill {{ $stColor }}">{{ $st }}</span></p>
      </div>
      <a class="wh-btn wh-btn-primary" href="{{ route('website-orders.index', ['q' => $order['_id'] ?? $id]) }}">Manage on the ERP Orders page</a>
    </div>

    @include('website_admin.warehouse._nav')

    <div style="display:grid; grid-template-columns:minmax(0, 2fr) minmax(260px, 1fr); gap:16px;">
      <div class="wh-card wh-card-pad">
        <h2>Order Items ({{ count($order['items'] ?? []) }})</h2>
        @foreach($order['items'] ?? [] as $it)
          <div style="display:flex; gap:14px; padding:12px 0; border-top:1px solid #f0f0f0;">
            @if(!empty($it['image']))<img src="{{ $it['image'] }}" alt="" style="width:80px; height:80px; border-radius:8px; object-fit:cover;">@else<div style="width:80px; height:80px; border-radius:8px; background:#e5e7eb;"></div>@endif
            <div>
              <div style="font-weight:700;">{{ $it['name'] ?? ($it['product_name'] ?? 'Item') }}</div>
              <div class="wh-muted" style="font-size:13.5px;">Quantity {{ $it['quantity'] ?? 1 }} · ${{ number_format((float) ($it['price'] ?? 0), 2) }}</div>
              <div style="margin-top:6px;">
                @php $code = ($it['location']['discogs_location_code'] ?? '') ?: ($it['discogs_location'] ?? ''); @endphp
                @if(!empty($it['location']['zone']) || $code)
                  @if(!empty($it['location']['zone']))<span class="wh-tag zone">{{ $it['location']['zone'] }}</span>@endif
                  @if($code)<span class="wh-tag code">{{ $code }}</span>@endif
                  @if(!empty($it['location']['sub_location']))<span class="wh-tag sub">{{ $it['location']['sub_location'] }}</span>@endif
                @else
                  <span class="wh-pill yellow">Location not set</span>
                @endif
              </div>
            </div>
          </div>
        @endforeach
      </div>
      <div>
        <div class="wh-card wh-card-pad">
          <h2>Customer</h2>
          <div style="font-weight:600;">{{ $order['user_id']['name'] ?? 'N/A' }}</div>
          @if(!empty($order['user_id']['email']))<div class="wh-muted" style="font-size:14px;">{{ $order['user_id']['email'] }}</div>@endif
          @if(!empty($order['contactNumber']))<div style="font-size:14px;">{{ $order['contactNumber'] }}</div>@endif
        </div>
        @if($ship)
          <div class="wh-card wh-card-pad">
            <h2>Shipping Address</h2>
            <div style="font-size:14px; line-height:1.5;">
              @if(!empty($ship['fullAddress']))<div>{{ $ship['fullAddress'] }}</div>@endif
              @if(!empty($ship['apartment']))<div>{{ $ship['apartment'] }}</div>@endif
              <div>{{ implode(', ', array_filter([$ship['city'] ?? null, $ship['zipCode'] ?? null])) }}</div>
              @if(!empty($ship['country']))<div>{{ $ship['country'] }}</div>@endif
            </div>
          </div>
        @endif
        <div class="wh-card wh-card-pad">
          <h2>Order Summary</h2>
          <div style="display:flex; justify-content:space-between; font-size:14px;"><span class="wh-muted">Subtotal</span><span>${{ number_format((float) ($order['total'] ?? 0), 2) }}</span></div>
          <div style="display:flex; justify-content:space-between; font-size:14px; font-weight:700;"><span>Total</span><span>${{ number_format((float) ($order['total_amount'] ?? 0), 2) }}</span></div>
          <div style="display:flex; justify-content:space-between; font-size:14px;"><span class="wh-muted">Payment method</span><span>{{ $order['paymentMethod'] ?? 'N/A' }}</span></div>
          <div style="display:flex; justify-content:space-between; font-size:14px;"><span class="wh-muted">Payment status</span><span class="wh-pill {{ ($order['payment_status'] ?? '') === 'completed' ? 'green' : 'yellow' }}">{{ $order['payment_status'] ?? 'pending' }}</span></div>
          @if(!empty($order['tracking_number']))
            <div style="margin-top:8px; font-size:14px;">Tracking: <b class="wh-mono">{{ $order['tracking_number'] }}</b></div>
          @endif
          <div style="margin-top:8px; font-size:14px;" class="wh-muted">Placed {{ !empty($order['createdAt']) ? \Carbon\Carbon::parse($order['createdAt'])->setTimezone($tz)->format('F j, Y') : 'N/A' }}</div>
          <div class="wh-muted wh-mono" style="font-size:12px;">{{ $order['_id'] ?? '' }}</div>
        </div>
      </div>
    </div>
  @endif
</div>
@endsection
