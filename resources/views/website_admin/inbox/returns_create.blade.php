@extends('layouts.app')

@section('title', 'Create In-Store Return')

@section('content')
@include('website_admin.inbox._styles')

@php
  $fmt = function ($iso, $f = 'M j, Y') {
      if (empty($iso)) return '';
      try { return \Carbon\Carbon::parse($iso)->setTimezone('America/Los_Angeles')->format($f); } catch (\Throwable $e) { return ''; }
  };
  $money = function ($v) { return '$' . number_format((float) $v, 2); };
  $orderNo = function ($o) { return $o['orderNumber'] ?? substr((string) ($o['_id'] ?? ''), -8); };
  $stColor = function ($s) {
      $map = ['requested' => 'purple', 'pending_validation' => 'amber', 'approved' => 'green', 'rejected' => 'red', 'refunded' => 'blue'];
      return $map[strtolower((string) $s)] ?? 'gray';
  };
  $locked = $selectedOrder && (($selectedOrder['used_store_credit'] ?? 0) > 0);
@endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Create In-Store Return</h1>
      <p class="sub">Find the customer, pick the order, then enter the return details. Returns created here are approved right away.</p>
    </div>
    <a class="wi-btn" href="{{ route('wa.inbox.returns') }}">Back to returns</a>
  </div>

  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  <div style="display:grid;grid-template-columns:minmax(0,2fr) minmax(0,3fr);gap:20px;" class="wi-create-grid">
    <div>
      <div class="ev-card">
        <h2 style="margin-top:0;font-size:15px;">1. Find customer</h2>
        @if($customer)
          <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 12px;display:flex;justify-content:space-between;align-items:center;gap:10px;">
            <div><div class="wi-name">{{ $customer['name'] ?? '' }}</div><div style="font-size:12.5px;color:#2563eb;">{{ $customer['email'] ?? '' }}</div></div>
            <a class="wi-btn" href="{{ route('wa.inbox.returns.create') }}">Change</a>
          </div>
        @else
          <form method="GET" action="{{ route('wa.inbox.returns.create') }}" style="display:flex;gap:8px;">
            <input type="text" name="q" value="{{ $q }}" placeholder="Search by email or name" autofocus style="flex:1;font-size:14px;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;">
            <button class="wi-btn primary" style="height:38px;">Search</button>
          </form>
          @if($searchError)<p class="wi-muted" style="color:#b91c1c;margin-top:8px;">{{ $searchError }}</p>@endif
          @if(mb_strlen($q) > 0 && mb_strlen($q) < 2)<p class="wi-muted" style="margin-top:8px;">Type at least 2 characters.</p>@endif
          @if(mb_strlen($q) >= 2 && !$searchError && empty($results))<p class="wi-muted" style="margin-top:8px;">No customers found.</p>@endif
          @if(!empty($results))
            <div style="margin-top:10px;border:1px solid #e5e7eb;border-radius:8px;max-height:260px;overflow-y:auto;">
              @foreach($results as $c)
                <a href="{{ route('wa.inbox.returns.create', ['customer' => $c['_id']]) }}" style="display:block;padding:8px 12px;border-bottom:1px solid #f3f4f6;color:inherit;text-decoration:none;">
                  <div class="wi-name">{{ $c['name'] ?? '' }}</div>
                  <div class="wi-muted">{{ $c['email'] ?? '' }}</div>
                </a>
              @endforeach
            </div>
          @endif
        @endif
      </div>

      @if($customer || $ordersError)
        <div class="ev-card" style="margin-top:16px;">
          <h2 style="margin-top:0;font-size:15px;">2. Select order</h2>
          @if($ordersError)
            <p class="wi-muted" style="color:#b91c1c;">{{ $ordersError }}</p>
          @elseif(empty($orders))
            <p class="wi-muted">No orders found for this customer.</p>
          @else
            <div style="border:1px solid #e5e7eb;border-radius:8px;max-height:420px;overflow-y:auto;">
              @foreach($orders as $o)
                @php $on = $selectedOrder && ($selectedOrder['_id'] ?? '') === ($o['_id'] ?? ''); @endphp
                <a href="{{ route('wa.inbox.returns.create', ['customer' => $customer['_id'], 'order' => $o['_id']]) }}" style="display:flex;justify-content:space-between;gap:10px;padding:8px 12px;border-bottom:1px solid #f3f4f6;color:inherit;text-decoration:none;{{ $on ? 'background:#eff6ff;border-left:3px solid #3b82f6;' : '' }}">
                  <div>
                    <div style="font-family:monospace;font-size:12px;color:#6b7280;">#{{ $orderNo($o) }}</div>
                    <div style="font-weight:600;">{{ $money($o['total_amount'] ?? ($o['total'] ?? 0)) }}</div>
                    <div class="wi-muted">{{ $fmt($o['createdAt'] ?? null) }}</div>
                  </div>
                  <div style="display:flex;flex-direction:column;gap:3px;align-items:flex-end;">
                    <span class="wi-pill {{ $stColor($o['order_status'] ?? '') }}">{{ $o['order_status'] ?? '' }}</span>
                    <span class="wi-pill gray">{{ $o['payment_status'] ?? '' }}</span>
                    @if(($o['used_store_credit'] ?? 0) > 0)<span class="wi-pill amber">Store credit</span>@endif
                  </div>
                </a>
              @endforeach
            </div>
          @endif
        </div>
      @endif
    </div>

    <div>
      @if($selectedOrder)
        @php
          $so = $selectedOrder;
          $addr = $so['shippingAddress'] ?? null;
          if (is_array($addr) && isset($addr[0]) && is_array($addr[0])) { $addr = $addr[0]; }
          $addrLine = is_array($addr) ? implode(', ', array_filter([$addr['line1'] ?? null, $addr['line2'] ?? null, $addr['fullAddress'] ?? null, $addr['city'] ?? null, $addr['state'] ?? null, $addr['country'] ?? null, $addr['zip'] ?? null])) : '';
          $disc = $so['total_discount'] ?? ($so['discount'] ?? 0);
        @endphp
        <div class="ev-card">
          <h2 style="margin-top:0;font-size:15px;">Order #{{ $orderNo($so) }}</h2>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:13.5px;margin-bottom:12px;">
            <div><div class="wi-muted">Order status</div><span class="wi-pill {{ $stColor($so['order_status'] ?? '') }}">{{ $so['order_status'] ?? 'unknown' }}</span></div>
            <div><div class="wi-muted">Payment</div><span class="wi-pill gray">{{ $so['payment_status'] ?? 'unknown' }}</span> @if(!empty($so['paymentMethod']))<span class="wi-muted">{{ $so['paymentMethod'] }}</span>@endif</div>
            <div><div class="wi-muted">Date</div>{{ $fmt($so['createdAt'] ?? null, 'M j, Y g:i a') }}</div>
            <div><div class="wi-muted">Total</div><b>{{ $money($so['total_amount'] ?? ($so['total'] ?? 0)) }}</b></div>
            @if(!empty($so['tracking_number']))<div style="grid-column:1 / -1;"><div class="wi-muted">Tracking</div>{{ $so['tracking_number'] }}</div>@endif
            @if($addrLine !== '')<div style="grid-column:1 / -1;"><div class="wi-muted">Shipping address</div>{{ $addrLine }}</div>@endif
          </div>

          <div class="wi-sec">Items ({{ count($so['items'] ?? []) }})</div>
          <div style="border:1px solid #e5e7eb;border-radius:8px;max-height:280px;overflow-y:auto;">
            @foreach(($so['items'] ?? []) as $it)
              @php
                $prod = is_array($it['product_id'] ?? null) ? $it['product_id'] : [];
                $img = $it['product_image'] ?? ($prod['image'] ?? null);
              @endphp
              <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;border-bottom:1px solid #f3f4f6;font-size:13.5px;">
                @if($img)<img src="{{ $img }}" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:4px;">@else<span style="width:40px;height:40px;border-radius:4px;background:#f3f4f6;display:inline-block;"></span>@endif
                <div style="flex:1;min-width:0;">
                  <div style="font-weight:600;">{{ $it['product_name'] ?? ($prod['name'] ?? 'Product') }}</div>
                  <div class="wi-muted">Qty {{ $it['quantity'] ?? 1 }} x {{ $money($it['price'] ?? 0) }}</div>
                </div>
                <div style="font-weight:600;">{{ $money(($it['quantity'] ?? 1) * ($it['price'] ?? 0)) }}</div>
              </div>
            @endforeach
          </div>
          <div style="margin-top:8px;font-size:13.5px;">
            <div style="display:flex;justify-content:space-between;color:#4b5563;"><span>Subtotal</span><span>{{ $money($so['subtotal'] ?? ($so['total_amount'] ?? 0)) }}</span></div>
            @if($disc > 0)<div style="display:flex;justify-content:space-between;color:#4b5563;"><span>Discount</span><span>-{{ $money($disc) }}</span></div>@endif
            <div style="display:flex;justify-content:space-between;font-weight:700;"><span>Total</span><span>{{ $money($so['total_amount'] ?? ($so['total'] ?? 0)) }}</span></div>
          </div>
        </div>

        <form method="POST" action="{{ route('wa.inbox.returns.store') }}" class="ev-card" style="margin-top:16px;" onsubmit="this.querySelector('button[type=submit]').disabled = true;">
          {{ csrf_field() }}
          <input type="hidden" name="customer" value="{{ $customer['_id'] }}">
          <input type="hidden" name="order" value="{{ $so['_id'] }}">
          <h2 style="margin-top:0;font-size:15px;">3. Return details</h2>
          <label style="display:block;font-size:13px;font-weight:600;margin-bottom:5px;">Reason for return (required)</label>
          <textarea name="reason" rows="3" required placeholder="Describe why the customer is returning." style="width:100%;box-sizing:border-box;font-size:14px;padding:9px 10px;border:1px solid #d1d5db;border-radius:8px;">{{ old('reason') }}</textarea>

          <label style="display:block;font-size:13px;font-weight:600;margin:14px 0 5px;">Refund method</label>
          @if($locked)
            <input type="hidden" name="refund_method" value="store_credit">
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:9px 12px;font-size:13.5px;color:#92400e;">This order used store credit, so the refund must be issued as <b>store credit</b>.</div>
          @else
            <select name="refund_method" style="width:100%;font-size:14px;padding:9px 10px;border:1px solid #d1d5db;border-radius:8px;">
              @foreach($refundMethods as $k => $label)
                <option value="{{ $k }}" {{ old('refund_method', 'to_original') === $k ? 'selected' : '' }}>{{ $label }}</option>
              @endforeach
            </select>
          @endif
          <p class="wi-hint" style="margin-top:8px;">The return is approved as soon as it is created. A store credit refund issues a gift card and emails it to the customer.</p>

          <div class="wi-dialog-actions" style="border-top:1px solid #e5e7eb;padding-top:14px;">
            <a class="wi-btn" href="{{ route('wa.inbox.returns') }}">Cancel</a>
            <button type="submit" class="wi-btn primary">Create return request</button>
          </div>
        </form>
      @else
        <div class="ev-card" style="border-style:dashed;text-align:center;color:#9ca3af;padding:48px 20px;">
          Search for a customer, then select an order to see its details and create a return.
        </div>
      @endif
    </div>
  </div>
</div>

<style>
  @media (max-width: 900px) { .wi-create-grid { grid-template-columns:1fr !important; } }
</style>
@endsection
