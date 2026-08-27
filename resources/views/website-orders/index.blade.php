@extends('layouts.app')

@section('title', 'Orders')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')

<style>
  .wo-tabs { display:flex; align-items:center; gap:2px; border-bottom:1px solid var(--pos-line,#ECE3CF); margin-bottom:16px; overflow-x:auto; flex-wrap:wrap; }
  .wo-tab { position:relative; padding:10px 16px; font-size:13px; font-weight:600; color:#7a7266; text-decoration:none; white-space:nowrap; }
  .wo-tab:hover { color:#2a2620; }
  .wo-tab.active { color:#2a2620; }
  .wo-tab.active::after { content:""; position:absolute; left:0; right:0; bottom:-1px; height:2px; background:#2a2620; border-radius:2px 2px 0 0; }
  .wo-badge { display:inline-flex; align-items:center; justify-content:center; min-width:20px; height:20px; padding:0 6px; border-radius:999px; font-size:11px; font-weight:700; color:#fff; background:#c9c1ac; margin-left:6px; }
  .wo-badge.hot { background:#c98a2c; }
  .wo-badge.overdue { background:#a23; }
  .wo-filters { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:14px; }
  .wo-filters select, .wo-filters input[type=text], .wo-filters input[type=date] { font-size:12px; padding:6px 8px; border:1px solid var(--pos-line,#ECE3CF); border-radius:8px; }
  .wo-banner { margin-bottom:14px; padding:10px 12px; border-radius:8px; font-size:13px; }
  .wo-banner.warn { background:#fff8e6; border:1px solid #f0dfa8; color:#6b5511; }
  .wo-banner.overdue { background:#fdecec; border:1px solid #f0c2c2; color:#7a2222; }
  .wo-banner.ok { background:#eef7ee; border:1px solid #cfe8cf; color:#245a24; }
  .wo-row-toggle { cursor:pointer; color:#4a6fa5; font-size:12px; background:none; border:none; padding:0; text-decoration:underline; }
  .wo-detail td { background:#faf8f2; }
  .wo-detail-grid { display:flex; flex-wrap:wrap; gap:24px; padding:10px 4px; font-size:12px; }
  .wo-detail-grid h5 { font-size:12px; font-weight:700; margin:0 0 6px; color:#5a5346; }
  .wo-detail-items { width:100%; border-collapse:collapse; margin-top:4px; }
  .wo-detail-items td, .wo-detail-items th { padding:3px 8px 3px 0; font-size:12px; text-align:left; }
  .wo-status-form { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
  .wo-status-form select, .wo-status-form input { font-size:12px; padding:5px 6px; border:1px solid var(--pos-line,#ECE3CF); border-radius:8px; }
  .wo-status-form input[type=text] { max-width:130px; }
  .wo-actions { display:flex; flex-direction:column; gap:6px; min-width:260px; }
  .wo-actions-row { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }
</style>

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Orders</h1>
      <p class="sub">Fulfillment for nivessa.com orders — the ERP's replacement for the website's own Orders page.</p>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  @if($bridgeError)
    <div class="ev-card" style="border:1px solid #f0c2c2;">
      <h2 style="margin-top:0;">Couldn't reach the website</h2>
      <p class="sub" style="margin:0;">{{ $bridgeErrorMessage }}</p>
    </div>
  @else
    @php
      $tabDefs = [
        'needs_action' => ['label' => 'Needs Action', 'hot' => true],
        'to_ship'      => ['label' => 'To Ship'],
        'pickup'       => ['label' => 'Pickup'],
        'completed'    => ['label' => 'Completed'],
        'archived'     => ['label' => 'Archived'],
      ];
      $baseQuery = array_filter([
        'status' => $statusFilter,
        'payment_status' => $paymentStatusFilter,
        'q' => $search,
        'from' => $dateFrom,
        'to' => $dateTo,
      ], fn($v) => $v !== '' && $v !== null);
    @endphp

    <div class="wo-tabs">
      @foreach($tabDefs as $key => $def)
        @php
          $count = $tabCounts[$key] ?? 0;
          $badgeClass = ($key === 'pickup' && ($tabCounts['pickup_overdue'] ?? 0) > 0) ? 'overdue' : ((($def['hot'] ?? false) && $count > 0) ? 'hot' : '');
        @endphp
        <a href="{{ route('website-orders.index', array_merge($baseQuery, ['tab' => $key])) }}"
           class="wo-tab {{ $activeTab === $key ? 'active' : '' }}">
          {{ $def['label'] }}
          <span class="wo-badge {{ $badgeClass }}">{{ $count }}</span>
        </a>
      @endforeach
    </div>

    @if($activeTab === 'needs_action' && ($tabCounts['needs_action'] ?? 0) === 0)
      <div class="wo-banner ok">No orders need action right now. All caught up.</div>
    @endif

    @if($activeTab === 'pickup')
      @if(($tabCounts['pickup_overdue'] ?? 0) > 0)
        <div class="wo-banner overdue"><strong>{{ $tabCounts['pickup_overdue'] }} overdue</strong> — waiting more than {{ $pickupSlaOverdueHours }} hours. Pull these as soon as possible.</div>
      @else
        <div class="wo-banner warn">In-store pickup orders should be pulled within 24&ndash;48 hours of the order being placed.</div>
      @endif
    @endif

    @if($activeTab === 'to_ship' && ($tabCounts['to_ship'] ?? 0) > 0)
      <div class="wo-banner warn">These orders need to be packed and shipped — set status to Shipped and enter a tracking number.</div>
    @endif

    <form method="GET" action="{{ route('website-orders.index') }}" class="wo-filters">
      <input type="hidden" name="tab" value="{{ $activeTab }}">
      <select name="payment_status" onchange="this.form.submit()">
        <option value="completed" {{ $paymentStatusFilter === 'completed' ? 'selected' : '' }}>Completed</option>
        <option value="all" {{ $paymentStatusFilter === 'all' ? 'selected' : '' }}>All Payment Status</option>
        <option value="pending" {{ $paymentStatusFilter === 'pending' ? 'selected' : '' }}>Pending</option>
        <option value="failed" {{ $paymentStatusFilter === 'failed' ? 'selected' : '' }}>Failed</option>
        <option value="cancelled" {{ $paymentStatusFilter === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
        <option value="refunded" {{ $paymentStatusFilter === 'refunded' ? 'selected' : '' }}>Refunded</option>
      </select>
      <select name="status" onchange="this.form.submit()">
        <option value="">All Order Statuses</option>
        @foreach($statuses as $key => $label)
          <option value="{{ $key }}" {{ $statusFilter === $key ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
      </select>
      <input type="date" name="from" value="{{ $dateFrom }}">
      <input type="date" name="to" value="{{ $dateTo }}">
      <input type="text" name="q" value="{{ $search }}" placeholder="Search by name, email, or order ID...">
      <button type="submit" class="btn-ghost" style="padding:6px 12px;font-size:12px;">Filter</button>
      <a href="{{ route('website-orders.index', ['tab' => $activeTab]) }}" style="font-size:12px;color:#7a7266;">Clear</a>
    </form>

    @if(empty($orders))
      <div class="ev-card"><div class="empty">No orders in this view.</div></div>
    @else
      <div class="ev-card">
        <table class="ev-tbl">
          <thead><tr>
            <th>Buyer</th>
            <th>Status</th>
            <th>Fulfillment</th>
            <th>Total</th>
            <th>Placed</th>
            <th style="min-width:280px;">Update status</th>
            <th style="min-width:260px;">Other</th>
          </tr></thead>
          <tbody>
            @foreach($orders as $o)
              @php
                $isGift = collect($o['items'] ?? [])->isNotEmpty() && collect($o['items'] ?? [])->every(fn($it) =>
                  ($it['is_gift_card'] ?? false) === true || (empty($it['product_id']) && !empty($it['gift_card_amount']))
                );
                $customerName = $o['user_id']['name'] ?? 'Guest';
                $customerEmail = $o['user_id']['email'] ?? '';
                $placed = !empty($o['createdAt']) ? date('M j, Y g:ia', strtotime($o['createdAt'])) : '—';
                $isArchived = !empty($o['archived']);
                $rowId = 'wo-detail-' . substr((string) ($o['_id'] ?? ''), -8);
              @endphp
              <tr>
                <td class="ev-name">
                  {{ $customerName }}
                  <div class="ev-meta">{{ $customerEmail }}</div>
                  <button type="button" class="wo-row-toggle" onclick="var el=document.getElementById('{{ $rowId }}'); el.style.display = el.style.display === 'none' ? '' : 'none';">View details</button>
                </td>
                <td><span class="pill">{{ $statuses[$o['order_status'] ?? ''] ?? ($o['order_status'] ?? 'pending') }}</span></td>
                <td>
                  @if($isGift)
                    Digital (Email)
                  @elseif(($o['fulfillment_method'] ?? null) === 'pickup')
                    Pickup ({{ ($o['pickup_location'] ?? '') === 'pico' ? 'Pico' : 'Hollywood' }})
                  @else
                    Shipping
                  @endif
                </td>
                <td>${{ number_format((float) ($o['total_amount'] ?? 0), 2) }}</td>
                <td class="ev-meta">{{ $placed }}</td>
                <td>
                  <form method="POST" action="{{ route('website-orders.updateStatus', ['id' => $o['_id']]) }}" class="wo-status-form">
                    {{ csrf_field() }}
                    <input type="hidden" name="tab" value="{{ $activeTab }}">
                    <select name="status" required>
                      <option value="">Status…</option>
                      @foreach($statuses as $key => $label)
                        <option value="{{ $key }}" {{ ($o['order_status'] ?? '') === $key ? 'selected' : '' }}>{{ $label }}</option>
                      @endforeach
                    </select>
                    <input type="text" name="tracking_number" value="{{ $o['tracking_number'] ?? '' }}" placeholder="Tracking #">
                    <label style="font-size:11px;display:flex;align-items:center;gap:3px;"><input type="checkbox" name="notify_customer" value="1" checked> Notify</label>
                    <button type="submit" class="btn-ghost" style="padding:5px 10px;font-size:12px;">Update</button>
                  </form>
                </td>
                <td class="wo-actions">
                  <div class="wo-actions-row">
                    <form method="POST" action="{{ route('website-orders.archive', ['id' => $o['_id']]) }}"
                          onsubmit="return confirm('{{ $isArchived ? 'Restore this order to its status bucket?' : 'Archive this order?' }}');">
                      {{ csrf_field() }}
                      <input type="hidden" name="archived" value="{{ $isArchived ? '0' : '1' }}">
                      <button type="submit" class="btn-ghost" style="padding:5px 10px;font-size:12px;">{{ $isArchived ? 'Restore' : 'Archive' }}</button>
                    </form>
                  </div>
                  @if(!$isArchived)
                    <form method="POST" action="{{ route('website-orders.cancel', ['id' => $o['_id']]) }}"
                          class="wo-actions-row"
                          onsubmit="return confirm('Cancel this order and email the customer? This can\'t be undone from here.');">
                      {{ csrf_field() }}
                      <select name="reason" required style="font-size:12px;padding:5px 6px;border:1px solid var(--pos-line,#ECE3CF);border-radius:8px;max-width:150px;">
                        <option value="">Cancel reason…</option>
                        @foreach($reasons as $key => $label)
                          <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                      </select>
                      <input type="text" name="note" placeholder="Note (if Other)" style="font-size:12px;padding:5px 6px;border:1px solid var(--pos-line,#ECE3CF);border-radius:8px;max-width:120px;">
                      <button type="submit" class="btn-ghost" style="padding:5px 10px;font-size:12px;color:#a23;border-color:#f3cccc;">Cancel</button>
                    </form>
                  @endif
                </td>
              </tr>
              <tr id="{{ $rowId }}" class="wo-detail" style="display:none;">
                <td colspan="7">
                  <div class="wo-detail-grid">
                    <div>
                      <h5>Order</h5>
                      {{ $o['_id'] ?? '—' }}<br>
                      Payment: {{ $o['payment_status'] ?? '—' }} via {{ $o['paymentMethod'] ?? '—' }}<br>
                      Total: ${{ number_format((float) ($o['total_amount'] ?? 0), 2) }}
                      @if(!empty($o['total_discount']) && $o['total_discount'] > 0)
                        (discount -${{ number_format((float) $o['total_discount'], 2) }})
                      @endif
                    </div>
                    @if(!$isGift)
                      <div>
                        <h5>{{ ($o['fulfillment_method'] ?? null) === 'pickup' ? 'Pickup' : 'Shipping' }} info</h5>
                        Contact: {{ $o['contactNumber'] ?? '—' }}<br>
                        @php $addr = $o['shippingAddress'][0] ?? []; @endphp
                        {{ $addr['fullAddress'] ?? '—' }}
                        @if(!empty($addr['apartment'])) , {{ $addr['apartment'] }} @endif
                        <br>
                        {{ $addr['city'] ?? '' }}{{ !empty($addr['city']) ? ',' : '' }} {{ $addr['state'] ?? '' }} {{ $addr['zipCode'] ?? '' }}<br>
                        {{ $addr['country'] ?? '' }}
                      </div>
                    @endif
                    <div style="flex:1 1 100%;">
                      <h5>Items</h5>
                      <table class="wo-detail-items">
                        <thead><tr><th>Item</th><th>Artist</th><th>Format</th><th>Location / Discogs</th><th>Qty</th><th>Price</th></tr></thead>
                        <tbody>
                          @foreach(($o['items'] ?? []) as $item)
                            @php
                              $itemIsGift = ($item['is_gift_card'] ?? false) === true || (empty($item['product_id']) && !empty($item['gift_card_amount']));
                              $loc = $item['location'] ?? ($item['product_id']['location'] ?? null);
                              $locText = is_string($loc) ? $loc : trim(implode(' - ', array_filter([$loc['store'] ?? null, $loc['zone'] ?? null])));
                              $discogs = $item['discogs_code'] ?? ($item['product_id']['discogsLocation'] ?? null);
                            @endphp
                            <tr>
                              <td>
                                @if($itemIsGift)
                                  Nivessa Gift Card - ${{ $item['gift_card_amount'] ?? $item['price'] ?? '' }}
                                @else
                                  {{ $item['product_id']['name'] ?? ($item['product_name'] ?? 'Unknown item') }}
                                @endif
                              </td>
                              <td>{{ $itemIsGift ? '—' : ($item['product_id']['artist'] ?? ($item['product_artist'] ?? '—')) }}</td>
                              <td>{{ $itemIsGift ? '—' : ($item['product_id']['subCategory'] ?? ($item['product_subCategory'] ?? '—')) }}</td>
                              <td>{{ $itemIsGift ? '—' : ($discogs ?: ($locText ?: '—')) }}</td>
                              <td>{{ $item['quantity'] ?? 1 }}</td>
                              <td>${{ number_format((float) ($item['price'] ?? 0), 2) }}</td>
                            </tr>
                          @endforeach
                        </tbody>
                      </table>
                    </div>
                    @if(!empty($o['appliedVouchers']))
                      <div style="flex:1 1 100%;">
                        <h5>Vouchers</h5>
                        @foreach($o['appliedVouchers'] as $v)
                          {{ $v['code'] ?? '—' }} (&minus;${{ number_format((float) ($v['discount'] ?? 0), 2) }})@if(!$loop->last), @endif
                        @endforeach
                      </div>
                    @endif
                  </div>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  @endif
</div>
@endsection
