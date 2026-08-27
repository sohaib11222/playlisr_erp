@extends('layouts.app')

@section('title', 'Website Orders')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Website Orders</h1>
      <p class="sub">Orders from nivessa.com that still need action. Cancel one here when it can't be fulfilled — a reason is required and the customer is emailed automatically with an explanation matching it.</p>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  @if($bridgeError)
    <div class="ev-card" style="border:1px solid #f0c2c2;">
      <h2 style="margin-top:0;">Couldn't reach the website</h2>
      <p class="sub" style="margin:0;">{{ $bridgeErrorMessage }}</p>
    </div>
  @elseif(empty($orders))
    <div class="ev-card"><div class="empty">No orders currently need action.</div></div>
  @else
    <div class="ev-card">
      <table class="ev-tbl">
        <thead><tr>
          <th>Customer</th>
          <th>Items</th>
          <th>Total</th>
          <th>Status</th>
          <th>Placed</th>
          <th style="min-width:340px;">Cancel</th>
        </tr></thead>
        <tbody>
          @foreach($orders as $o)
            @php
              $customerName = $o['user_id']['name'] ?? 'Guest';
              $customerEmail = $o['user_id']['email'] ?? '';
              $placed = !empty($o['createdAt']) ? date('M j, Y g:ia', strtotime($o['createdAt'])) : '—';
              $itemNames = collect($o['items'] ?? [])->map(function ($it) {
                  $name = $it['product_id']['name'] ?? $it['product_name'] ?? 'Unknown item';
                  $qty = $it['quantity'] ?? 1;
                  return $qty > 1 ? "{$name} × {$qty}" : $name;
              })->implode(', ');
            @endphp
            <tr>
              <td class="ev-name">{{ $customerName }}
                <div class="ev-meta">{{ $customerEmail }}</div>
              </td>
              <td style="max-width:280px;">{{ $itemNames ?: '—' }}</td>
              <td>${{ number_format((float) ($o['total_amount'] ?? 0), 2) }}</td>
              <td><span class="pill">{{ $o['order_status'] ?? '—' }}</span></td>
              <td class="ev-meta">{{ $placed }}</td>
              <td>
                <form method="POST" action="{{ route('website-orders.cancel', ['id' => $o['_id']]) }}"
                      style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;"
                      onsubmit="return confirm('Cancel this order and email the customer? This can\'t be undone from here.');">
                  {{ csrf_field() }}
                  <select name="reason" required style="font-size:12px;padding:5px 6px;border:1px solid var(--pos-line,#ECE3CF);border-radius:8px;max-width:170px;">
                    <option value="">Reason…</option>
                    @foreach($reasons as $key => $label)
                      <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                  </select>
                  <input type="text" name="note" placeholder="Note (required if Other)" style="font-size:12px;padding:5px 6px;border:1px solid var(--pos-line,#ECE3CF);border-radius:8px;max-width:150px;">
                  <button type="submit" class="btn-ghost" style="padding:5px 12px;font-size:12px;color:#a23;border-color:#f3cccc;">Cancel order</button>
                </form>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
</div>
@endsection
