@extends('layouts.app')
@section('title', 'Website Customer')

@section('content')
@include('website_admin.content._styles')
@php
  $H = \App\Http\Controllers\WebsiteAdmin\WebsiteUsersController::class;
  $payColor = ['pending' => 'amber', 'failed' => 'red', 'refunded' => 'red', 'cancelled' => 'red', 'completed' => 'green'];
  $orderColor = ['processing' => 'amber', 'cancelled' => 'red', 'delivered' => 'green', 'email_sent' => 'green', 'shipped' => 'blue'];
@endphp
<div class="ev-wrap ev-wrap-wide">
  <a class="wc-back" href="{{ route('wa.content.users') }}">Back to website customers</a>
  <div class="ev-head"><div><h1>{{ ($user['name'] ?? '') !== '' ? $user['name'] : 'Customer' }}</h1><p class="sub">Website customer account.</p></div></div>

  <div class="wc-card wc-card-pad">
    <div class="wc-grid">
      <div><div class="wc-label">Name</div>{{ $user['name'] ?? '' }}</div>
      <div><div class="wc-label">Email</div>{{ $user['email'] ?? '' }}</div>
      <div><div class="wc-label">Created</div>{{ $H::fmtDate($user['createdAt'] ?? null, 'M j, Y g:i A') }}</div>
      <div><div class="wc-label">Status</div><span class="wc-pill {{ !empty($user['isActive']) ? 'green' : 'red' }}">{{ !empty($user['isActive']) ? 'Active' : 'Inactive' }}</span></div>
    </div>
  </div>

  <div class="wc-card">
    <div class="wc-card-head"><h2>Order history</h2></div>
    @if($ordersError)
      <div class="wc-empty">{{ $ordersError }}</div>
    @elseif(count($orders) === 0)
      <div class="wc-empty">No orders found.</div>
    @else
    <div class="wc-scroll">
      <table class="wc-tbl">
        <thead><tr><th>#</th><th>Order ID</th><th>Placed</th><th class="num">Amount</th><th>Payment</th><th>Order status</th><th>Products</th><th>Address</th></tr></thead>
        <tbody>
        @foreach($orders as $i => $o)
          @php $addr = $o['shippingAddress'][0] ?? null; @endphp
          <tr>
            <td class="wc-muted">{{ $i + 1 }}</td>
            <td class="wc-mono">{{ $o['_id'] ?? '' }}</td>
            <td class="wc-muted" style="white-space:nowrap;">{{ $H::fmtDate($o['createdAt'] ?? null) }}</td>
            <td class="num">${{ number_format((float) ($o['total_amount'] ?? 0), 2) }}</td>
            <td><span class="wc-pill {{ $payColor[$o['payment_status'] ?? ''] ?? '' }}">{{ $o['payment_status'] ?? '' }}</span></td>
            <td><span class="wc-pill {{ $orderColor[$o['order_status'] ?? ''] ?? '' }}">{{ ($o['order_status'] ?? '') === 'email_sent' ? 'Email sent' : ($o['order_status'] ?? '') }}</span></td>
            <td>
              @foreach(($o['items'] ?? []) as $it)
                <div style="display:flex; gap:8px; align-items:center; margin-bottom:6px;">
                  @if(!empty($it['product_id']['image']))<img class="wc-thumb" style="width:40px; height:40px;" src="{{ $it['product_id']['image'] }}" alt="">@endif
                  <div><div style="font-weight:600;">{{ $it['product_id']['name'] ?? 'Product' }}</div><div class="wc-muted">Qty {{ $it['quantity'] ?? '' }}, ${{ $it['price'] ?? '' }}</div></div>
                </div>
              @endforeach
            </td>
            <td>{{ $addr ? trim(($addr['fullAddress'] ?? '') . ', ' . ($addr['city'] ?? '') . ', ' . ($addr['country'] ?? ''), ', ') : 'No address' }}</td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    @endif
  </div>
</div>
@endsection
