@extends('layouts.app')

@section('title', 'Website Returns')

@section('content')
@include('website_admin.inbox._styles')

@php
  $fmt = function ($iso, $f = 'M j, Y') {
      if (empty($iso)) return '';
      try { return \Carbon\Carbon::parse($iso)->setTimezone('America/Los_Angeles')->format($f); } catch (\Throwable $e) { return ''; }
  };
  $money = function ($v) { return '$' . number_format((float) $v, 2); };
  $stColor = ['REQUESTED' => 'amber', 'PENDING_VALIDATION' => 'amber', 'APPROVED' => 'green', 'RETURNED' => 'green', 'REJECTED' => 'red'];
  $stLabel = ['REQUESTED' => 'Requested', 'PENDING_VALIDATION' => 'Pending', 'APPROVED' => 'Approved', 'RETURNED' => 'Returned', 'REJECTED' => 'Rejected'];
  $payLabel = function ($m) {
      if (!$m || $m === 'cash_on_delivery') return 'Cash on delivery';
      if ($m === 'stripe') return 'Stripe';
      if ($m === 'paypal') return 'PayPal';
      return $m;
  };
  $refundLabel = ['to_original' => 'Original payment', 'store_credit' => 'Store credit', 'cash' => 'Cash'];
@endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Website Returns</h1>
      <p class="sub">Return requests from nivessa.com customers, plus in-store returns created here.</p>
    </div>
    <a class="wi-btn primary" href="{{ route('wa.inbox.returns.create') }}">Create return</a>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  <div class="wi-tabs">
    @foreach($tabs as $key => $label)
      <a class="wi-tab {{ $status === $key ? 'active' : '' }}" href="{{ route('wa.inbox.returns', ['status' => $key]) }}">{{ $label }}</a>
    @endforeach
  </div>

  @if($bridgeError)
    <div class="ev-card" style="border:1px solid #f0c2c2;">
      <h2 style="margin-top:0;">Could not load returns</h2>
      <p class="sub" style="margin:0;">{{ $bridgeError }}</p>
    </div>
  @else
    <div class="wi-card wi-scroll">
      <table class="wi-tbl" style="min-width:860px;">
        <thead>
          <tr>
            <th>Order</th>
            <th>Customer</th>
            <th>Reason</th>
            <th>Refund</th>
            <th>Status</th>
            <th>Date</th>
            <th class="r">Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($returns as $r)
            @php
              $st = $r['status'] ?? '';
              $order = is_array($r['order_id'] ?? null) ? $r['order_id'] : [];
              $user = is_array($r['user_id'] ?? null) ? $r['user_id'] : [];
            @endphp
            <tr>
              <td style="font-family:monospace;font-size:12.5px;">{{ !empty($order['_id']) ? substr($order['_id'], -8) : 'None' }}</td>
              <td><div class="wi-name">{{ $user['name'] ?? 'Unknown' }}</div><div class="wi-muted">{{ $user['email'] ?? '' }}</div></td>
              <td><div class="wi-trunc" style="max-width:260px;" title="{{ $r['reason'] ?? '' }}">{{ $r['reason'] ?? '' }}</div></td>
              <td class="wi-muted">{{ $refundLabel[$r['refund_method'] ?? ''] ?? ($r['refund_method'] ?? '') }}</td>
              <td><span class="wi-pill {{ $stColor[$st] ?? 'gray' }}">{{ $stLabel[$st] ?? $st }}</span></td>
              <td style="white-space:nowrap;">{{ $fmt($r['createdAt'] ?? null) }}</td>
              <td>
                <div class="wi-actions">
                  <button type="button" class="wi-btn" onclick="document.getElementById('wi-ret-{{ $r['_id'] }}').showModal()">View</button>
                  @if(in_array($st, ['PENDING_VALIDATION', 'REQUESTED'], true))
                    <form method="POST" action="{{ route('wa.inbox.returns.resolve', $r['_id']) }}" onsubmit="this.querySelector('button').disabled = true;">
                      {{ csrf_field() }}<input type="hidden" name="action" value="approve">
                      <button class="wi-btn ok">Approve</button>
                    </form>
                    <button type="button" class="wi-btn bad" onclick="wiOpenReject('{{ $r['_id'] }}')">Reject</button>
                  @endif
                </div>
              </td>
            </tr>
          @empty
            <tr><td colspan="7" class="wi-empty">No return requests found.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <p class="wi-hint" style="margin-top:10px;">Approving a return that refunds as store credit issues a store credit gift card and emails it to the customer.</p>
  @endif
</div>

@foreach($returns as $r)
  @php
    $st = $r['status'] ?? '';
    $order = is_array($r['order_id'] ?? null) ? $r['order_id'] : [];
    $user = is_array($r['user_id'] ?? null) ? $r['user_id'] : [];
    $ship = $order['shippingAddress'][0] ?? null;
    $pay = $order['payment_status'] ?? '';
  @endphp
  <dialog class="wi-dialog wide" id="wi-ret-{{ $r['_id'] }}">
    <div class="wi-dialog-body">
      <h3>Return request</h3>

      <div class="wi-box">
        <div style="font-weight:700;margin-bottom:6px;">Order details</div>
        <div class="wi-kv"><b>Order ID</b><span style="font-family:monospace;">{{ $order['_id'] ?? 'None' }}</span></div>
        <div class="wi-kv"><b>Date</b><span>{{ $fmt($order['createdAt'] ?? null, 'M j, Y g:i a') }}</span></div>
        <div class="wi-kv"><b>Contact</b><span>{{ $order['contactNumber'] ?? '' }}</span></div>
        <div class="wi-kv"><b>Order status</b><span><span class="wi-pill gray">{{ $order['order_status'] ?? 'Unknown' }}</span></span></div>
        <div class="wi-kv"><b>Payment</b><span><span class="wi-pill {{ $pay === 'completed' ? 'green' : ($pay === 'pending' ? 'amber' : 'red') }}">{{ $pay !== '' ? $pay : 'Unknown' }}</span></span></div>
        <div class="wi-kv"><b>Payment method</b><span>{{ $payLabel($order['paymentMethod'] ?? null) }}</span></div>
        @if(!empty($order['tracking_number']))<div class="wi-kv"><b>Tracking number</b><span>{{ $order['tracking_number'] }}</span></div>@endif
        @if($ship)
          <div class="wi-kv"><b>Shipping</b><span>{{ implode(', ', array_filter([$ship['fullAddress'] ?? null, $ship['city'] ?? null, $ship['country'] ?? null])) }}</span></div>
        @endif

        @if(!empty($order['items']))
          <div class="wi-sec">Items in this order</div>
          @foreach($order['items'] as $k => $it)
            @php
              $prod = is_array($it['product_id'] ?? null) ? $it['product_id'] : [];
              $img = $it['product_image'] ?? ($prod['image'] ?? null);
            @endphp
            <div style="display:flex;align-items:center;gap:8px;font-size:12.5px;margin:4px 0;">
              @if($img)<img src="{{ $img }}" alt="" style="width:32px;height:32px;object-fit:cover;border-radius:4px;border:1px solid #e5e7eb;">@else<span style="width:32px;height:32px;border-radius:4px;border:1px solid #e5e7eb;background:#f3f4f6;display:inline-block;"></span>@endif
              <span style="flex:1;">{{ $it['product_name'] ?? ($prod['name'] ?? 'Product ' . ($k + 1)) }}@if(!empty($it['product_sku'])) <span class="wi-muted">({{ $it['product_sku'] }})</span>@endif</span>
              <span class="wi-muted">x{{ $it['quantity'] ?? 1 }}</span>
              <span style="font-weight:600;">{{ $money($it['price'] ?? 0) }}</span>
            </div>
          @endforeach
        @endif

        <div style="border-top:1px solid #e5e7eb;margin-top:8px;padding-top:6px;font-size:13px;">
          <div style="display:flex;justify-content:space-between;"><span>Subtotal</span><span>{{ $money($order['total'] ?? 0) }}</span></div>
          @if(($order['total_discount'] ?? 0) > 0)
            <div style="display:flex;justify-content:space-between;color:#b91c1c;"><span>Discount</span><span>-{{ $money($order['total_discount']) }}</span></div>
          @endif
          <div style="display:flex;justify-content:space-between;font-weight:700;"><span>Grand total</span><span>{{ $money($order['total_amount'] ?? 0) }}</span></div>
        </div>
      </div>

      <div class="wi-kv"><b>Customer</b><span>{{ $user['name'] ?? 'Unknown' }} ({{ $user['email'] ?? 'no email' }})</span></div>
      <div class="wi-kv"><b>Status</b><span><span class="wi-pill {{ $stColor[$st] ?? 'gray' }}">{{ $stLabel[$st] ?? $st }}</span></span></div>
      <div class="wi-kv"><b>Refund method</b><span>{{ $refundLabel[$r['refund_method'] ?? ''] ?? ($r['refund_method'] ?? '') }}</span></div>
      <div class="wi-kv"><b>Reason</b><span>{{ $r['reason'] ?? '' }}</span></div>
      @if(!empty($r['evidence_urls']))
        <div class="wi-kv"><b>Evidence</b><span style="display:flex;gap:6px;flex-wrap:wrap;">
          @foreach($r['evidence_urls'] as $k => $url)
            <a href="{{ $url }}" target="_blank" rel="noopener noreferrer"><img src="{{ $url }}" alt="Evidence {{ $k + 1 }}" style="width:80px;height:80px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;"></a>
          @endforeach
        </span></div>
      @endif
      @if(!empty($r['admin_response']['rejection_reason']))
        <div class="wi-kv"><b>Rejection reason</b><span>{{ $r['admin_response']['rejection_reason'] }}</span></div>
      @endif
      @if(!empty($r['items']))
        <div class="wi-sec">Items being returned</div>
        <ul style="margin:0 0 0 18px;padding:0;font-size:13px;">
          @foreach($r['items'] as $it)
            @php $prod = is_array($it['product_id'] ?? null) ? $it['product_id'] : []; @endphp
            <li>{{ $it['product_name'] ?? ($prod['name'] ?? 'Product') }} x{{ $it['quantity'] ?? 1 }}, {{ $money($it['price'] ?? 0) }}</li>
          @endforeach
        </ul>
      @endif

      <div class="wi-dialog-actions">
        <button type="button" class="wi-btn" onclick="this.closest('dialog').close()">Close</button>
      </div>
    </div>
  </dialog>
@endforeach

<dialog class="wi-dialog" id="wi-reject">
  <form method="POST" id="wi-reject-form" class="wi-dialog-body">
    {{ csrf_field() }}
    <input type="hidden" name="action" value="reject">
    <h3>Reject return request</h3>
    <p class="sub">Give a reason for rejecting this return.</p>
    <textarea name="rejectionReason" rows="3" placeholder="Rejection reason" required></textarea>
    <div class="wi-dialog-actions">
      <button type="button" class="wi-btn" onclick="this.closest('dialog').close()">Cancel</button>
      <button class="wi-btn bad">Reject</button>
    </div>
  </form>
</dialog>

<script>
  window.wiOpenReject = function (id) {
    var f = document.getElementById('wi-reject-form');
    f.action = @json(route('wa.inbox.returns.resolve', '__ID__')).replace('__ID__', id);
    f.rejectionReason.value = '';
    document.getElementById('wi-reject').showModal();
  };
</script>
@endsection
