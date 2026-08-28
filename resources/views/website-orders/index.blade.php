@extends('layouts.app')

@section('title', 'Orders')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')

<style>
  .wo-tabs { display:flex; align-items:center; gap:4px; border-bottom:2px solid #e5e7eb; margin-bottom:20px; overflow-x:auto; flex-wrap:wrap; }
  .wo-tab { position:relative; padding:12px 18px; font-size:14px; font-weight:600; color:#6b7280; text-decoration:none; white-space:nowrap; }
  .wo-tab:hover { color:#111827; }
  .wo-tab.active { color:#111827; }
  .wo-tab.active::after { content:""; position:absolute; left:0; right:0; bottom:-2px; height:3px; background:#111827; border-radius:2px 2px 0 0; }
  .wo-badge { display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 7px; border-radius:999px; font-size:12px; font-weight:700; color:#fff; background:#9ca3af; margin-left:8px; }
  .wo-badge.hot { background:#d97706; }
  .wo-badge.overdue { background:#dc2626; }

  .wo-filters { display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end; margin-bottom:18px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; }
  .wo-filters .wo-field { display:flex; flex-direction:column; gap:4px; }
  .wo-filters label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#9ca3af; }
  .wo-filters select, .wo-filters input[type=text], .wo-filters input[type=date] { font-size:13.5px; padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; background:#fff; }
  .wo-filters .wo-actions-inline { display:flex; gap:10px; align-items:center; }

  .wo-banner { margin-bottom:16px; padding:12px 14px; border-radius:8px; font-size:14px; }
  .wo-banner.warn { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
  .wo-banner.overdue { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
  .wo-banner.ok { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }

  .wo-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; box-shadow:0 1px 2px rgba(0,0,0,.04); }
  .wo-tbl { width:100%; table-layout:fixed; border-collapse:collapse; }
  .wo-tbl thead th { text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; padding:12px 14px; border-bottom:1px solid #e5e7eb; background:#f9fafb; }
  .wo-tbl tbody tr.wo-row { border-bottom:1px solid #f0f0f0; }
  .wo-tbl tbody tr.wo-row:hover { background:#fafafa; }
  .wo-tbl td { padding:16px 14px; font-size:14px; vertical-align:top; }

  .wo-buyer-name { font-size:14.5px; font-weight:600; color:#111827; }
  .wo-buyer-email { font-size:12.5px; color:#6b7280; margin-top:2px; }

  .wo-pill { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:999px; font-size:12.5px; font-weight:600; white-space:nowrap; }
  .wo-pill.st-processing { background:#fef9c3; color:#854d0e; }
  .wo-pill.st-ready_for_pickup { background:#fef3c7; color:#92400e; }
  .wo-pill.st-picked_up { background:#d1fae5; color:#065f46; }
  .wo-pill.st-shipped { background:#dbeafe; color:#1e40af; }
  .wo-pill.st-email_sent { background:#dcfce7; color:#166534; }
  .wo-pill.st-delivered { background:#dcfce7; color:#166534; }
  .wo-pill.st-cancelled { background:#fee2e2; color:#991b1b; }
  .wo-pill.st-flag { background:#fee2e2; color:#991b1b; }
  .wo-pill.st-default { background:#f3f4f6; color:#374151; }

  .wo-fm-pill { display:inline-block; padding:3px 9px; border-radius:999px; font-size:12px; font-weight:600; }
  .wo-fm-pill.shipping { background:#e0f2fe; color:#0369a1; }
  .wo-fm-pill.pickup { background:#f3e8ff; color:#6d28d9; }
  .wo-fm-pill.digital { background:#dcfce7; color:#166534; }

  .wo-total { font-weight:600; color:#111827; }
  .wo-placed { font-size:13px; color:#6b7280; white-space:nowrap; }

  .wo-row-actions { display:flex; flex-direction:column; gap:6px; width:132px; }
  .wo-row-actions form { margin:0; }
  .wo-btn { display:block; width:100%; box-sizing:border-box; font-size:13px; padding:7px 12px; border-radius:7px; border:1px solid #d1d5db; background:#fff; cursor:pointer; font-weight:500; color:#374151; text-align:center; }
  .wo-btn:hover { background:#f9fafb; }
  .wo-btn-primary { background:#111827; color:#fff; border-color:#111827; }
  .wo-btn-primary:hover { background:#1f2937; }
  .wo-btn-danger { color:#b91c1c; border-color:#fecaca; }
  .wo-btn-danger:hover { background:#fef2f2; }

  .wo-dialog { border:none; border-radius:12px; padding:0; max-width:420px; width:calc(100vw - 32px); box-shadow:0 10px 40px rgba(0,0,0,.2); }
  .wo-dialog::backdrop { background:rgba(17,24,39,.45); }
  .wo-dialog-body { padding:22px; }
  .wo-dialog-body h3 { margin:0 0 14px; font-size:16px; font-weight:700; color:#111827; }
  .wo-dialog-body label { display:block; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#6b7280; margin:12px 0 5px; }
  .wo-dialog-body label:first-of-type { margin-top:0; }
  .wo-dialog-body select, .wo-dialog-body input[type=text] { width:100%; font-size:14px; padding:9px 10px; border:1px solid #d1d5db; border-radius:8px; box-sizing:border-box; }
  .wo-dialog-checkline { display:flex; align-items:center; gap:7px; font-size:13px; font-weight:400; text-transform:none; letter-spacing:0; color:#374151; margin-top:14px; }
  .wo-dialog-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:20px; }

  .wo-detail td { background:#f9fafb; padding:0 14px 18px; }
  .wo-detail-grid { display:flex; flex-wrap:wrap; gap:28px; padding:14px 4px; font-size:13px; }
  .wo-detail-grid h5 { font-size:12.5px; font-weight:700; margin:0 0 8px; color:#4b5563; text-transform:uppercase; letter-spacing:.03em; }
  .wo-detail-items { width:100%; border-collapse:collapse; margin-top:6px; }
  .wo-detail-items td, .wo-detail-items th { padding:5px 10px 5px 0; font-size:13px; text-align:left; }
  .wo-detail-items th { color:#6b7280; font-weight:700; font-size:11px; text-transform:uppercase; }
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
      <div class="wo-field">
        <label>Payment</label>
        <select name="payment_status" onchange="this.form.submit()">
          <option value="completed" {{ $paymentStatusFilter === 'completed' ? 'selected' : '' }}>Completed</option>
          <option value="all" {{ $paymentStatusFilter === 'all' ? 'selected' : '' }}>All Payment Status</option>
          <option value="pending" {{ $paymentStatusFilter === 'pending' ? 'selected' : '' }}>Pending</option>
          <option value="failed" {{ $paymentStatusFilter === 'failed' ? 'selected' : '' }}>Failed</option>
          <option value="cancelled" {{ $paymentStatusFilter === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
          <option value="refunded" {{ $paymentStatusFilter === 'refunded' ? 'selected' : '' }}>Refunded</option>
        </select>
      </div>
      <div class="wo-field">
        <label>Order status</label>
        <select name="status" onchange="this.form.submit()">
          <option value="">All Order Statuses</option>
          @foreach($statuses as $key => $label)
            <option value="{{ $key }}" {{ $statusFilter === $key ? 'selected' : '' }}>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="wo-field">
        <label>From</label>
        <input type="date" name="from" value="{{ $dateFrom }}">
      </div>
      <div class="wo-field">
        <label>To</label>
        <input type="date" name="to" value="{{ $dateTo }}">
      </div>
      <div class="wo-field" style="flex:1 1 220px;">
        <label>Search</label>
        <input type="text" name="q" value="{{ $search }}" placeholder="Name, email, or order ID" style="width:100%;">
      </div>
      <div class="wo-actions-inline">
        <button type="submit" class="wo-btn wo-btn-primary">Filter</button>
        <a href="{{ route('website-orders.index', ['tab' => $activeTab]) }}" style="font-size:13px;color:#6b7280;">Clear</a>
      </div>
    </form>

    @if(empty($orders))
      <div class="ev-card"><div class="empty">No orders in this view.</div></div>
    @else
      <div class="wo-card">
        <div style="overflow-x:auto;">
        <table class="wo-tbl">
          <colgroup>
            <col style="width:26%">
            <col style="width:13%">
            <col style="width:15%">
            <col style="width:10%">
            <col style="width:16%">
            <col style="width:160px">
          </colgroup>
          <thead><tr>
            <th>Buyer</th>
            <th>Status</th>
            <th>Fulfillment</th>
            <th>Total</th>
            <th>Placed</th>
            <th>Actions</th>
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
                $status = $o['order_status'] ?? '';
                $pillClass = in_array($status, ['processing','ready_for_pickup','picked_up','shipped','email_sent','delivered','cancelled','flag'], true) ? "st-{$status}" : 'st-default';
                $rowId = 'wo-detail-' . substr((string) ($o['_id'] ?? ''), -8);
              @endphp
              <tr class="wo-row">
                <td>
                  <div class="wo-buyer-name">{{ $customerName }}</div>
                  <div class="wo-buyer-email">{{ $customerEmail }}</div>
                </td>
                <td><span class="wo-pill {{ $pillClass }}">{{ $statuses[$status] ?? ($status ?: 'Pending') }}</span></td>
                <td>
                  @if($isGift)
                    <span class="wo-fm-pill digital">Digital (Email)</span>
                  @elseif(($o['fulfillment_method'] ?? null) === 'pickup')
                    <span class="wo-fm-pill pickup">Pickup ({{ ($o['pickup_location'] ?? '') === 'pico' ? 'Pico' : 'Hollywood' }})</span>
                  @else
                    <span class="wo-fm-pill shipping">Shipping</span>
                  @endif
                </td>
                <td class="wo-total">${{ number_format((float) ($o['total_amount'] ?? 0), 2) }}</td>
                <td class="wo-placed">{{ $placed }}</td>
                <td>
                  <div class="wo-row-actions">
                    <button type="button" class="wo-btn wo-btn-primary"
                      onclick="woOpenStatus('{{ $o['_id'] }}', '{{ $status }}', '{{ $o['tracking_number'] ?? '' }}')">Update status</button>
                    <button type="button" class="wo-btn" onclick="var el=document.getElementById('{{ $rowId }}'); el.style.display = el.style.display === 'none' ? '' : 'none';">View details</button>
                    <form method="POST" action="{{ route('website-orders.archive', ['id' => $o['_id']]) }}"
                          onsubmit="return confirm('{{ $isArchived ? 'Restore this order to its status bucket?' : 'Archive this order?' }}');">
                      {{ csrf_field() }}
                      <input type="hidden" name="archived" value="{{ $isArchived ? '0' : '1' }}">
                      <button type="submit" class="wo-btn" style="width:100%;">{{ $isArchived ? 'Restore' : 'Archive' }}</button>
                    </form>
                    @if(!$isArchived)
                      <button type="button" class="wo-btn wo-btn-danger" onclick="woOpenCancel('{{ $o['_id'] }}')">Cancel order</button>
                    @endif
                  </div>
                </td>
              </tr>
              <tr id="{{ $rowId }}" class="wo-detail" style="display:none;">
                <td colspan="6">
                  <div class="wo-detail-grid">
                    <div>
                      <h5>Order</h5>
                      {{ $o['_id'] ?? '—' }}<br>
                      Payment: {{ $o['payment_status'] ?? '—' }} via {{ $o['paymentMethod'] ?? '—' }}<br>
                      Total: ${{ number_format((float) ($o['total_amount'] ?? 0), 2) }}
                      @if(!empty($o['total_discount']) && $o['total_discount'] > 0)
                        (discount -${{ number_format((float) $o['total_discount'], 2) }})
                      @endif
                      @if(!empty($o['_id']))
                        <div style="margin-top:8px;">
                          <a href="https://nivessa.com/admin/orders/{{ $o['_id'] }}" target="_blank" rel="noopener" style="font-size:12px;color:#4a6fa5;">View on nivessa.com &rarr;</a>
                        </div>
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
                      <h5>What they ordered</h5>
                      <table class="wo-detail-items">
                        <thead><tr>
                          <th></th><th>Name</th><th>Artist</th><th>Format</th><th>Genre</th><th>Store</th><th>Discogs code</th><th>Location</th><th>Qty</th><th>Price</th>
                        </tr></thead>
                        <tbody>
                          @foreach(($o['items'] ?? []) as $item)
                            @php
                              $itemIsGift = ($item['is_gift_card'] ?? false) === true || (empty($item['product_id']) && !empty($item['gift_card_amount']));
                              $img = $itemIsGift ? null : ($item['product_id']['image'] ?? $item['product_image'] ?? null);
                              $loc = $item['location'] ?? ($item['product_id']['location'] ?? null);
                              $locStore = is_string($loc) ? $loc : trim(implode(' - ', array_filter([
                                $loc['store'] ?? null, $loc['zone'] ?? null, $loc['location_store'] ?? null,
                              ])));
                              $locSub = is_array($loc) ? ($loc['sub_location'] ?? null) : null;
                              $discogs = $item['discogs_code'] ?? ($item['product_id']['discogsLocation'] ?? null);
                              $hasLoc = $locStore !== '';
                              $inStoreBins = $hasLoc && !$discogs && (stripos($locStore, 'hollywood') !== false || stripos($locStore, 'pico') !== false);
                              $isPreorder = !$itemIsGift && !empty($item['product_id']['isPreorder']);
                              $shipDate = $item['product_id']['preorderShipDate'] ?? null;
                              // Website storefront link — same _id/slug scheme as the
                              // website's own order-detail page (getProductUrl()).
                              $websiteId = $item['product_id']['_id'] ?? $item['product_sku'] ?? null;
                              $websiteSlug = $item['product_slug'] ?? ($item['product_id']['slug'] ?? null);
                              $websiteUrl = (!$itemIsGift && $websiteId && $websiteSlug) ? "https://nivessa.com/products/{$websiteId}/{$websiteSlug}" : null;
                              // ERP catalog record — posProductId is the website's copy of
                              // the ERP's own product primary key (see Product model).
                              $erpProductId = $item['product_id']['posProductId'] ?? null;
                            @endphp
                            <tr>
                              <td style="width:52px;">
                                @if($itemIsGift)
                                  <div style="width:44px;height:44px;border-radius:6px;background:#dcfce7;display:flex;align-items:center;justify-content:center;font-size:16px;">&#127873;</div>
                                @elseif($img)
                                  <img src="{{ $img }}" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;">
                                @else
                                  <div style="width:44px;height:44px;border-radius:6px;background:#f3f4f6;"></div>
                                @endif
                              </td>
                              <td>
                                @if($itemIsGift)
                                  <span style="color:#166534;font-weight:600;">Nivessa Gift Card - ${{ $item['gift_card_amount'] ?? $item['price'] ?? '' }}</span>
                                @else
                                  {{ $item['product_id']['name'] ?? ($item['product_name'] ?? 'Unknown item') }}
                                  @if($isPreorder)
                                    <span style="display:inline-block;margin-left:4px;padding:1px 7px;border-radius:999px;background:#f97316;color:#fff;font-size:10px;font-weight:700;">Preorder</span>
                                    @if($shipDate)
                                      <div style="font-size:11px;color:#c2410c;">Ships {{ date('M j, Y', strtotime($shipDate)) }}</div>
                                    @endif
                                  @endif
                                  <div style="font-size:11px;margin-top:3px;">
                                    @if($websiteUrl)
                                      <a href="{{ $websiteUrl }}" target="_blank" rel="noopener" style="color:#4a6fa5;">Website</a>
                                    @endif
                                    @if($websiteUrl && $erpProductId) &middot; @endif
                                    @if($erpProductId)
                                      <a href="{{ url('/products/' . $erpProductId . '/edit') }}" target="_blank" rel="noopener" style="color:#4a6fa5;">ERP</a>
                                    @endif
                                  </div>
                                @endif
                              </td>
                              <td>{{ $itemIsGift ? '—' : ($item['product_id']['artist'] ?? ($item['product_artist'] ?? '—')) }}</td>
                              <td>{{ $itemIsGift ? '—' : ($item['product_id']['subCategory'] ?? ($item['product_subCategory'] ?? '—')) }}</td>
                              <td>{{ $itemIsGift ? '—' : ($item['product_id']['genre'] ?? ($item['product_genre'] ?? '—')) }}</td>
                              <td>
                                @if($itemIsGift)
                                  —
                                @elseif($locStore !== '')
                                  {{ $locStore }}@if($locSub)<br><span style="color:#6b7280;">&rarr; {{ $locSub }}</span>@endif
                                @else
                                  <span style="color:#9ca3af;font-style:italic;">Not set</span>
                                @endif
                              </td>
                              <td style="font-family:monospace;font-size:12px;">{{ $itemIsGift ? '—' : ($discogs ?: '—') }}</td>
                              <td>
                                @if($itemIsGift)
                                  —
                                @elseif($inStoreBins)
                                  <span style="color:#15803d;font-weight:600;">In Store Bins</span>
                                @else
                                  <span style="color:#9ca3af;">—</span>
                                @endif
                              </td>
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
      </div>
    @endif

    {{-- Shared "Update status" dialog — one dialog reused for every row, retargeted by
         woOpenStatus() rather than one dialog per order (keeps the DOM small). --}}
    <dialog id="wo-status-dialog" class="wo-dialog">
      <form method="POST" id="wo-status-form" class="wo-dialog-body">
        {{ csrf_field() }}
        <input type="hidden" name="tab" value="{{ $activeTab }}">
        <h3>Update order status</h3>
        <label>Status</label>
        <select name="status" id="wo-status-select" required>
          <option value="">Choose a status…</option>
          @foreach($statuses as $key => $label)
            <option value="{{ $key }}">{{ $label }}</option>
          @endforeach
        </select>
        <label>Tracking number</label>
        <input type="text" name="tracking_number" id="wo-status-tracking" placeholder="Required if Shipped">
        <label class="wo-dialog-checkline" style="text-transform:none;">
          <input type="checkbox" name="notify_customer" value="1" checked> Notify the customer by email
        </label>
        <div class="wo-dialog-actions">
          <button type="button" class="wo-btn" onclick="document.getElementById('wo-status-dialog').close()">Cancel</button>
          <button type="submit" class="wo-btn wo-btn-primary">Update</button>
        </div>
      </form>
    </dialog>

    {{-- Shared "Cancel order" dialog — same reuse pattern. --}}
    <dialog id="wo-cancel-dialog" class="wo-dialog">
      <form method="POST" id="wo-cancel-form" class="wo-dialog-body"
            onsubmit="return confirm('Cancel this order and email the customer? This can\'t be undone from here.');">
        {{ csrf_field() }}
        <h3>Cancel order</h3>
        <label>Reason</label>
        <select name="reason" required>
          <option value="">Choose a reason…</option>
          @foreach($reasons as $key => $label)
            <option value="{{ $key }}">{{ $label }}</option>
          @endforeach
        </select>
        <label>Note (required if Other)</label>
        <input type="text" name="note" placeholder="Optional detail for the customer email">
        <div class="wo-dialog-actions">
          <button type="button" class="wo-btn" onclick="document.getElementById('wo-cancel-dialog').close()">Never mind</button>
          <button type="submit" class="wo-btn wo-btn-danger">Cancel order</button>
        </div>
      </form>
    </dialog>

    <script>
      function woOpenStatus(orderId, currentStatus, currentTracking) {
        var form = document.getElementById('wo-status-form');
        form.action = '{{ url("/website-orders") }}/' + orderId + '/status';
        document.getElementById('wo-status-select').value = currentStatus || '';
        document.getElementById('wo-status-tracking').value = currentTracking || '';
        document.getElementById('wo-status-dialog').showModal();
      }
      function woOpenCancel(orderId) {
        var form = document.getElementById('wo-cancel-form');
        form.action = '{{ url("/website-orders") }}/' + orderId + '/cancel';
        form.reset();
        document.getElementById('wo-cancel-dialog').showModal();
      }
    </script>
  @endif
</div>
@endsection
