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
  .wo-tbl { width:100%; min-width:1080px; table-layout:fixed; border-collapse:collapse; }
  .wo-scroll { overflow-x:auto; }
  .wo-tbl td { overflow-wrap:anywhere; }
  .wo-tbl td.wo-actions-cell { overflow:visible; }
  .wo-tbl thead th { text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; padding:10px 10px; border-bottom:1px solid #e5e7eb; background:#f9fafb; }
  .wo-tbl tbody tr.wo-row { border-bottom:1px solid #f0f0f0; }
  .wo-tbl tbody tr.wo-row:hover { background:#fafafa; }
  .wo-tbl td { padding:12px 10px; font-size:14px; vertical-align:top; }

  .wo-buyer-name { font-size:14.5px; font-weight:600; color:#111827; }
  .wo-prod-with-img { display:flex; gap:10px; align-items:center; }
  .wo-prod-img { width:64px; height:64px; border-radius:8px; object-fit:cover; border:1px solid #e5e7eb; background:#f3f4f6; flex-shrink:0; display:block; }
  .wo-listed { font-size:12.5px; color:#374151; line-height:1.35; min-width:96px; }
  .wo-listed .t { display:block; color:#9ca3af; white-space:nowrap; }
  .wo-sort { color:inherit; text-decoration:none; white-space:nowrap; }
  .wo-sort:hover { text-decoration:underline; }
  .wo-sort-arrow { font-size:10px; margin-left:3px; color:#6b7280; }
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
  .wo-fm-pill.both-sep { background:#fef3c7; color:#92400e; margin-top:4px; }
  .wo-fm-pill.both-comb { background:#ffedd5; color:#9a3412; margin-top:4px; }
  .wo-shipfrom { font-size:11px; color:#6b7280; margin-top:3px; }

  .wo-prod-line { font-size:13.5px; color:#111827; line-height:1.35; margin-bottom:6px; word-break:break-word; }
  .wo-prod-line:last-child { margin-bottom:0; }
  .wo-prod-qty { margin-left:4px; font-size:12px; font-weight:700; color:#6b7280; }
  .wo-prod-artist { font-size:12px; color:#6b7280; }
  .wo-prod-more { font-size:12px; color:#6b7280; margin-top:4px; }

  .wo-total { font-weight:600; color:#111827; }
  .wo-loc { font-size:12.5px; line-height:1.35; min-width:110px; }
  .wo-loc-line { min-height:64px; margin-bottom:4px; }
  .wo-loc .st { font-weight:600; color:#111827; white-space:nowrap; }
  .wo-loc .q { font-weight:400; color:#6b7280; }
  .wo-loc .bin { display:block; color:#4b5563; font-family:monospace; font-size:12px; }
  .wo-loc .none { color:#b91c1c; font-weight:600; }
  .wo-loc .na { color:#9ca3af; }
  .wo-placed { font-size:12.5px; color:#6b7280; line-height:1.35; }
  .wo-placed .t { display:block; color:#9ca3af; }
  .wo-pill, .wo-fm-pill { white-space:normal !important; }
  .wo-tag { display:inline-block; margin-left:4px; padding:0 5px; border-radius:4px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; vertical-align:1px; }
  .wo-tag.media { color:#9a3412; background:#ffedd5; border:1px solid #fed7aa; }
  .wo-tag.fmt { color:#6b7280; border:1px solid #e5e7eb; font-weight:600; }
  .wo-prod-price { font-size:12px; color:#6b7280; }
  .wo-sla { display:inline-block; margin-top:4px; padding:1px 7px; border-radius:5px; font-size:10.5px; font-weight:700; }
  .wo-sla.ok { color:#166534; background:#f0fdf4; border:1px solid #bbf7d0; }
  .wo-sla.warn { color:#92400e; background:#fef3c7; border:1px solid #fde68a; }
  .wo-sla.over { color:#b91c1c; background:#fee2e2; border:1px solid #fecaca; }
  .wo-sla.pre { color:#9a3412; background:#fff7ed; border:1px solid #fed7aa; font-weight:600; }

  .wo-row-actions { display:flex; flex-direction:row; flex-wrap:wrap; align-items:center; gap:5px; position:relative; }
  .wo-row-actions .wo-btn-primary { padding:0 9px; }
  .wo-row-actions form { margin:0; }
  .wo-row-actions .wo-btn { display:inline-flex; align-items:center; justify-content:center; width:auto; height:30px; padding:0 10px; font-size:12.5px; }
  .wo-more { position:relative; }
  .wo-more-btn { width:30px !important; padding:0 !important; }
  .wo-more-btn svg { width:16px; height:16px; fill:#374151; }
  .wo-more-menu { display:none; position:fixed; z-index:1000; min-width:150px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,.12); padding:4px; }
  .wo-more.open .wo-more-menu { display:block; }
  .wo-more-menu form { margin:0; }
  .wo-more-item { display:block; width:100%; text-align:left; background:none; border:none; padding:8px 10px; font-size:13px; color:#374151; border-radius:6px; cursor:pointer; }
  .wo-more-item:hover { background:#f3f4f6; }
  .wo-more-item.danger { color:#b91c1c; }
  .wo-more-item.danger:hover { background:#fef2f2; }
  .wo-row > td { padding-top:4px; padding-bottom:4px; vertical-align:middle; }
  .wo-row .wo-prod-line { margin-bottom:4px; }
  .wo-prod-name { color:#111827; text-decoration:none; }
  .wo-prod-name:hover { color:#4a6fa5; text-decoration:underline; }
  .wo-noerp { display:inline-block; margin-left:5px; padding:0 6px; border-radius:999px; background:#f3f4f6; color:#6b7280; font-size:10.5px; font-weight:600; vertical-align:1px; white-space:nowrap; }
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
        'needs_action'    => ['label' => 'Needs Action', 'hot' => true],
        'to_ship'         => ['label' => 'To Ship'],
        'pickup'          => ['label' => 'Pickup'],
        'preorder_ship'   => ['label' => 'Preorder · To Ship'],
        'preorder_pickup' => ['label' => 'Preorder · Pickup'],
        'completed'       => ['label' => 'Completed'],
        'archived'        => ['label' => 'Archived'],
      ];
      $baseQuery = array_filter([
        'status' => $statusFilter,
        'payment_status' => $paymentStatusFilter,
        'q' => $search,
        'from' => $dateFrom,
        'to' => $dateTo,
        'location' => $locationFilter,
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

    @if(!empty($summaryFilter))
      <div class="wo-banner warn">Showing the orders from the #shipping summary link only. <a href="{{ route('website-orders.index', ['tab' => $activeTab]) }}">Show all</a></div>
    @endif
    @if($activeTab === 'to_ship' && ($tabCounts['to_ship'] ?? 0) > 0)
      <div class="wo-banner warn">These orders need to be packed and shipped — set status to Shipped and enter a tracking number.</div>
    @endif

    @if($activeTab === 'preorder_ship')
      <div class="wo-banner warn">These orders include at least one item that isn't in stock yet. Nothing to ship until the street date hits, check back once the item arrives.</div>
    @endif

    @if($activeTab === 'preorder_pickup')
      <div class="wo-banner warn">These orders include at least one item that isn't in stock yet. Not shipped - the customer collects in store, sometimes later than the street date (e.g. picking up at an event).</div>
    @endif

    @if(($unknownLocationCount ?? 0) > 0)
      <div class="wo-banner overdue"><strong>{{ $unknownLocationCount }} pickup {{ $unknownLocationCount === 1 ? 'order has' : 'orders have' }} no pickup location set.</strong> Choose <a href="{{ route('website-orders.index', ['tab' => $activeTab, 'location' => 'unknown']) }}">No Location Set</a> in the Pickup location filter to find {{ $unknownLocationCount === 1 ? 'it' : 'them' }}.</div>
    @endif
    @if(in_array($activeTab, ['preorder_ship', 'preorder_pickup'], true) && !empty($preorderSummary))
      <div class="wo-card" style="margin-bottom:16px;border-color:#fed7aa;">
        <div style="padding:8px 12px;background:#ffedd5;color:#9a3412;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;">Total preorders per album (ship + pickup combined)</div>
        <table style="width:100%;border-collapse:collapse;font-size:13.5px;">
          @foreach($preorderSummary as $al)
            <tr style="border-top:1px solid #ffedd5;">
              <td style="padding:7px 12px;">
                @if(!empty($al['erp_id']))<a class="wo-prod-name" href="{{ url('/products/' . $al['erp_id'] . '/edit') }}" target="_blank" rel="noopener">{{ $al['name'] }}</a>@else{{ $al['name'] }}@endif
                @if($al['media'])<span class="wo-tag media">{{ $al['media'] }}</span>@endif
                @if($al['format'])<span class="wo-tag fmt">{{ $al['format'] }}</span>@endif
                @if($al['sku'] || $al['price'] !== null)
                  <div style="font-size:11px;color:#9ca3af;">@if($al['sku'])SKU {{ $al['sku'] }}@endif @if($al['sku'] && $al['price'] !== null) &middot; @endif @if($al['price'] !== null)${{ number_format((float) $al['price'], 2) }}@endif</div>
                @endif
              </td>
              <td style="padding:7px 12px;color:#4b5563;white-space:nowrap;">street date {{ $al['street_date'] ? gmdate('M d, Y', strtotime($al['street_date'])) : 'TBD' }}</td>
              <td style="padding:7px 12px;text-align:right;font-weight:700;color:#c2410c;white-space:nowrap;">{{ $al['quantity'] }} preordered</td>
            </tr>
          @endforeach
        </table>
      </div>
    @endif
    <details class="wo-notes" style="margin-bottom:16px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:10px 14px;" {{ trim($deskNotes['text'] ?? '') !== '' ? 'open' : '' }}>
      <summary style="cursor:pointer;font-size:13.5px;font-weight:600;color:#92400e;">Notes for the orders desk
        @if(!empty($deskNotes['at']))<span style="font-weight:400;color:#a16207;font-size:12px;"> &middot; saved {{ \Carbon\Carbon::parse($deskNotes['at'])->setTimezone('America/Los_Angeles')->format('n/j g:ia') }}{{ !empty($deskNotes['by']) ? ' by ' . $deskNotes['by'] : '' }}</span>@endif
      </summary>
      <form method="POST" action="{{ route('website-orders.saveNotes') }}" style="margin-top:8px;">
        {{ csrf_field() }}
        <textarea name="notes" rows="3" placeholder="Add notes for the orders desk..." style="width:100%;box-sizing:border-box;font-size:13.5px;padding:8px;border:1px solid #fde68a;border-radius:8px;background:#fff;resize:vertical;">{{ $deskNotes['text'] ?? '' }}</textarea>
        <button type="submit" class="wo-btn" style="width:auto;display:inline-block;margin-top:6px;background:#d97706;border-color:#d97706;color:#fff;">Save notes</button>
      </form>
    </details>
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
        <label>Pickup location</label>
        <select name="location" onchange="this.form.submit()">
          <option value="">All Locations</option>
          <option value="hollywood" {{ $locationFilter === 'hollywood' ? 'selected' : '' }}>Hollywood</option>
          <option value="pico" {{ $locationFilter === 'pico' ? 'selected' : '' }}>Pico</option>
          <option value="unknown" {{ $locationFilter === 'unknown' ? 'selected' : '' }}>No Location Set ({{ $unknownLocationCount }})</option>
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
        <div class="wo-scroll">
        <table class="wo-tbl">
          <colgroup>
            <col style="width:15%">
            <col>
            <col style="width:108px">
            <col style="width:118px">
            <col style="width:112px">
            <col style="width:86px">
            <col style="width:92px">
            <col style="width:236px">
          </colgroup>
          <thead><tr>
            <th>@include('website-orders.partials.sort-th', ['label' => 'Buyer', 'key' => 'buyer'])</th>
            <th>@include('website-orders.partials.sort-th', ['label' => 'Product', 'key' => 'product'])</th>
            <th>@include('website-orders.partials.sort-th', ['label' => 'Listed', 'key' => 'listed'])</th>
            <th>@include('website-orders.partials.sort-th', ['label' => 'Status', 'key' => 'status'])</th>
            <th>@include('website-orders.partials.sort-th', ['label' => 'Fulfillment', 'key' => 'fulfillment'])</th>
            <th>Location</th>
            <th>@include('website-orders.partials.sort-th', ['label' => 'Total', 'key' => 'total'])</th>
            <th>@include('website-orders.partials.sort-th', ['label' => 'Placed', 'key' => 'placed'])</th>
            <th>Actions</th>
          </tr></thead>
          <tbody>
            @foreach($orders as $o)
              @php
                $isGift = collect($o['items'] ?? [])->isNotEmpty() && collect($o['items'] ?? [])->every(fn($it) =>
                  ($it['is_gift_card'] ?? false) === true || (empty($it['product_id']) && !empty($it['gift_card_amount']))
                );
                // Real name (looked up from the payment when the checkout name
                // was a username), then the shipping name, then the account.
                $customerName = $o['display_name'] ?? (($o['shippingAddress'][0]['name'] ?? null) ?: ($o['user_id']['name'] ?? 'Guest'));
                $customerEmail = $o['user_id']['email'] ?? '';
                $placed = !empty($o['createdAt']) ? date('M j, Y g:ia', strtotime($o['createdAt'])) : '—';
                $isArchived = !empty($o['archived']);
                $status = $o['order_status'] ?? '';
                $pillClass = in_array($status, ['processing','ready_for_pickup','picked_up','shipped','email_sent','delivered','cancelled','flag'], true) ? "st-{$status}" : 'st-default';
                $rowId = 'wo-detail-' . preg_replace('/[^A-Za-z0-9]/', '', (string) ($o['_id'] ?? '')) . '-' . $loop->index;
                // Order-level preorder flag: true if ANY item is still a
                // preorder. Surfaced on the collapsed row (not just inside
                // View details) so Nick doesn't hunt for stock that hasn't
                // shipped to us yet, or cancel it thinking it's missing.
                $rowShipDates = [];
                $rowIsPreorder = false;
                foreach (($o['items'] ?? []) as $rowItem) {
                  if (!empty($rowItem['product_id']['isPreorder'])) {
                    $rowIsPreorder = true;
                    if (!empty($rowItem['product_id']['preorderShipDate'])) {
                      $rowShipDates[] = $rowItem['product_id']['preorderShipDate'];
                    }
                  }
                }
                sort($rowShipDates);
                $rowShipDate = $rowShipDates[0] ?? null;
                // Lines the Cancel dialog can tick (item-level cancel).
                $cancelLines = collect($o['items'] ?? [])->map(fn($it) => [
                  'id' => (string) ($it['_id'] ?? ''),
                  'name' => (($it['is_gift_card'] ?? false) === true || (empty($it['product_id']) && !empty($it['gift_card_amount'])))
                    ? 'Nivessa Gift Card' : ($it['product_id']['name'] ?? ($it['product_name'] ?? 'Item')),
                  'qty' => (int) ($it['quantity'] ?? 1),
                  'price' => (float) ($it['price'] ?? 0),
                  'cancelled' => !empty($it['cancelled']),
                  'gift' => ($it['is_gift_card'] ?? false) === true || (empty($it['product_id']) && !empty($it['gift_card_amount'])),
                ])->values();
              @endphp
              @php
                // Thumbnails (up to 3, then +N) and the oldest item's listed
                // date for the row (Sarah, 2026-10-13).
                $rowItems = collect($o['items'] ?? [])->filter(fn($it) => !(($it['is_gift_card'] ?? false) === true || (empty($it['product_id']) && !empty($it['gift_card_amount']))))->values();
                $rowListedDates = $rowItems->map(fn($it) => $it['product_id']['posCreatedAt'] ?? ($it['product_id']['createdAt'] ?? null))->filter()->sort()->values();
                $rowListed = \App\Http\Controllers\WebsiteOrdersController::listedShort($rowListedDates->first());
              @endphp
              <tr class="wo-row">
                <td>
                  <div class="wo-buyer-name">{{ $customerName }}</div>
                  <div class="wo-buyer-email">{{ $customerEmail }}</div>
                </td>
                <td>
                  @foreach($cancelLines->take(3) as $line)
                    @php
                      $lineItem = $o['items'][$loop->index] ?? [];
                      $lineArtist = $line['gift'] ? null : (($lineItem['product_id']['artist'] ?? null) ?: ($lineItem['product_artist'] ?? null));
                      $lineImg = $line['gift'] ? null : (($lineItem['product_id']['image'] ?? null) ?: ($lineItem['product_image'] ?? null));
                      $lineErp = $line['gift'] ? null : ($lineItem['product_id']['posProductId'] ?? null);
                    @endphp
                    <div class="wo-prod-line wo-prod-with-img">
                      @if($lineImg)<img class="wo-prod-img" src="{{ $lineImg }}" alt="" loading="lazy">@else<div class="wo-prod-img"></div>@endif
                      <div>
                        @if($line['cancelled'])<s>@endif @if($lineErp)<a class="wo-prod-name" href="{{ url('/products/' . $lineErp . '/edit') }}" target="_blank" rel="noopener">{{ $line['name'] }}</a>@else{{ $line['name'] }}@endif @if($line['cancelled'])</s>@endif
                        @if(!$line['gift'] && !$lineErp)<span class="wo-noerp">not in ERP</span>@endif
                        @php $lineMedia = $line['gift'] ? null : \App\Http\Controllers\WebsiteOrdersController::mediaTypeLabel($lineItem['product_id']['productType'] ?? null); $lineFmt = $line['gift'] ? null : ($lineItem['product_id']['format'] ?? null); @endphp
                        @if($lineMedia)<span class="wo-tag media">{{ $lineMedia }}</span>@endif
                        @if($lineFmt)<span class="wo-tag fmt">{{ $lineFmt }}</span>@endif
                        <div class="wo-prod-price">{{ $lineItem['quantity'] ?? $line['qty'] }} &times; ${{ number_format((float) ($lineItem['price'] ?? 0), 2) }}</div>
                        @if($lineArtist)<div class="wo-prod-artist">{{ $lineArtist }}</div>@endif
                      </div>
                    </div>
                  @endforeach
                  @if($cancelLines->count() > 3)
                    <div class="wo-prod-more">+{{ $cancelLines->count() - 3 }} more</div>
                  @endif
                </td>
                <td class="wo-listed">{{ explode(' · ', (string) ($rowListed ?? ''), 2)[0] }}@if(count(explode(' · ', (string) ($rowListed ?? ''), 2)) > 1)<span class="t">{{ explode(' · ', (string) ($rowListed ?? ''), 2)[1] }}</span>@endif</td>
                <td>
                  <span class="wo-pill {{ $pillClass }}">{{ $statuses[$status] ?? ($status ?: 'Pending') }}</span>
                  @if($paymentStatusFilter === 'all')
                    @php $ps = $o['payment_status'] ?? 'unknown'; $psColors = ['completed' => '#dcfce7;color:#166534', 'pending' => '#fef9c3;color:#854d0e', 'failed' => '#fee2e2;color:#991b1b', 'cancelled' => '#f3f4f6;color:#374151', 'refunded' => '#f3e8ff;color:#6b21a8']; @endphp
                    <div style="margin-top:5px;"><span class="wo-pill" style="background:{{ $psColors[$ps] ?? '#f3f4f6;color:#374151' }};font-size:11px;padding:2px 8px;">Payment: {{ ucfirst($ps) }}</span></div>
                  @endif
                  @if($rowIsPreorder)
                    <div style="margin-top:6px;">
                      <span style="display:inline-block;padding:3px 9px;border-radius:999px;background:#f97316;color:#fff;font-size:11px;font-weight:700;white-space:nowrap;">PREORDER — NOT IN STOCK</span>
                      @if($rowShipDate)
                        <div style="font-size:11px;color:#9a3412;margin-top:3px;">Ships {{ gmdate('M j, Y', strtotime($rowShipDate)) }}</div>
                      @endif
                    </div>
                  @endif
                </td>
                <td>
                  @if($isGift)
                    <span class="wo-fm-pill digital">Digital (Email)</span>
                  @elseif(($o['fulfillment_method'] ?? null) === 'pickup')
                    <span class="wo-fm-pill pickup" @if(!\App\Http\Controllers\WebsiteOrdersController::knownPickupLocation($o)) style="background:#fee2e2;color:#991b1b;" @endif>Pickup ({{ \App\Http\Controllers\WebsiteOrdersController::pickupLocationLabel($o) }})</span>
                  @else
                    <span class="wo-fm-pill shipping">Shipping</span>
                    @php
                      // Two store carts: which store(s) this order ships from.
                      $woShipStores = collect($o['items'] ?? [])
                        ->filter(function ($it) { return empty($it['is_gift_card']) && !empty($it['ship_from']); })
                        ->pluck('ship_from')->unique()->values();
                    @endphp
                    @if(!empty($o['ships_from_both_stores']))
                      <div>
                        @if(($o['ship_mode'] ?? null) === 'combine')
                          <span class="wo-fm-pill both-comb" title="Customer chose one package: bring everything to one store, ship together">Both stores: combine into 1 package</span>
                        @else
                          <span class="wo-fm-pill both-sep" title="Customer chose separate packages: each store ships its own part">Both stores: ship separately</span>
                        @endif
                      </div>
                    @elseif($woShipStores->count() === 1)
                      <div class="wo-shipfrom">Ships from {{ $woShipStores->first() === 'pico' ? 'Pico' : 'Hollywood' }}</div>
                    @endif
                  @endif
                </td>
                <td class="wo-loc">
                  {{-- One line per product shown in the Product column (same first 3). --}}
                  @foreach($cancelLines->take(3) as $line)
                    @php
                      $locPid = $line['gift'] ? 0 : (int) ($o['items'][$loop->index]['product_id']['posProductId'] ?? 0);
                      $locInfo = $locPid ? ($erpLocations[$locPid] ?? null) : null;
                    @endphp
                    <div class="wo-loc-line">
                      @if($line['gift'])
                        <span class="na">Digital</span>
                      @elseif(!$locInfo)
                        <span class="na">&mdash;</span>
                      @else
                        @if(!empty($locInfo['stores']))
                          @foreach($locInfo['stores'] as $locName => $locQty)<span class="st">{{ $locName }}@if($locQty > 1) <span class="q">({{ (int) $locQty }})</span>@endif</span>@if(!$loop->last), @endif @endforeach
                        @elseif(!empty($locInfo['stocked']))
                          <span class="st">{{ implode(', ', array_keys($locInfo['stocked'])) }}</span> <span class="q">(ordered the last 1 in stock, check the bins anyway)</span>
                        @else
                          <span class="none">Not set</span>
                        @endif
                        @if($locInfo['bin'] !== '')<span class="bin">Bin {{ $locInfo['bin'] }}</span>@endif
                      @endif
                    </div>
                  @endforeach
                </td>
                <td class="wo-total">${{ number_format((float) ($o['total_amount'] ?? 0), 2) }}
                  @if(!empty($o['pick_progress']) && in_array($activeTab, ['to_ship', 'preorder_ship', 'needs_action'], true))
                    <div style="font-size:11px;font-weight:600;color:{{ ($o['pick_progress']['picked'] ?? 0) >= ($o['pick_progress']['total'] ?? 0) ? '#047857' : '#6b7280' }};">picked {{ $o['pick_progress']['picked'] ?? 0 }}/{{ $o['pick_progress']['total'] ?? 0 }}</div>
                  @endif
                </td>
                <td class="wo-placed">
                  @if(!empty($o['createdAt'])){{ date('M j, Y', strtotime($o['createdAt'])) }}<span class="t">{{ date('g:ia', strtotime($o['createdAt'])) }}</span>@else &mdash; @endif
                  @if(($o['fulfillment_method'] ?? null) === 'pickup' && !in_array($status, ['picked_up', 'delivered', 'cancelled'], true) && !$isArchived)
                    @if($rowIsPreorder)
                      <span class="wo-sla pre">not in stock yet</span>
                    @else
                      @php $slaH = \App\Http\Controllers\WebsiteOrdersController::hoursSinceStatic($o['createdAt'] ?? null); @endphp
                      <span class="wo-sla {{ $slaH >= 48 ? 'over' : ($slaH >= 24 ? 'warn' : 'ok') }}">{{ $slaH }}h{{ $slaH >= 48 ? ' · OVERDUE' : ($slaH >= 24 ? ' · pull soon' : '') }}</span>
                    @endif
                  @endif
                </td>
                <td class="wo-actions-cell">
                  <div class="wo-row-actions">
                    <button type="button" class="wo-btn wo-btn-primary"
                      onclick="woOpenStatus('{{ $o['_id'] }}', '{{ $status }}', '{{ $o['tracking_number'] ?? '' }}')">Update status</button>
                    {{-- Toggles the details row right after this order's row (not by id:
                         two orders could end up with the same short id). --}}
                    <button type="button" class="wo-btn" onclick="woToggleDetails(this)">Details</button>
                    <div class="wo-more">
                      <button type="button" class="wo-btn wo-more-btn" title="More actions" aria-label="More actions" onclick="woToggleMore(this, event)"><svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="3" cy="8" r="1.5"/><circle cx="8" cy="8" r="1.5"/><circle cx="13" cy="8" r="1.5"/></svg></button>
                      <div class="wo-more-menu">
                        <form method="POST" action="{{ route('website-orders.archive', ['id' => $o['_id']]) }}"
                              onsubmit="return confirm('{{ $isArchived ? 'Restore this order to its status bucket?' : 'Archive this order?' }}');">
                          {{ csrf_field() }}
                          <input type="hidden" name="archived" value="{{ $isArchived ? '0' : '1' }}">
                          <button type="submit" class="wo-more-item">{{ $isArchived ? 'Restore' : 'Archive' }}</button>
                        </form>
                        @if(!$isArchived)
                          <button type="button" class="wo-more-item danger" data-lines="{{ json_encode($cancelLines) }}" onclick="woCloseMore(); woOpenCancel('{{ $o['_id'] }}', this)">Cancel order</button>
                        @endif
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
              <tr id="{{ $rowId }}" class="wo-detail" style="display:none;">
                <td colspan="9">
                  <div class="wo-detail-grid">
                    <div>
                      <h5>Order</h5>
                      {{ $o['_id'] ?? '—' }}<br>
                      Payment: {{ $o['payment_status'] ?? '—' }} via {{ $o['paymentMethod'] ?? '—' }}<br>
                      Placed: {{ !empty($o['createdAt']) ? date('M j, Y g:ia', strtotime($o['createdAt'])) : '—' }}<br>
                      @php $subtotal = collect($o['items'] ?? [])->sum(fn($it) => (float) ($it['price'] ?? 0) * (int) ($it['quantity'] ?? 1)); @endphp
                      Subtotal: ${{ number_format($subtotal, 2) }}<br>
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
                        {{ $o['display_name'] ?? ($o['user_id']['name'] ?? '—') }}<br>
                        {{ $o['user_id']['email'] ?? '' }}@if(!empty($o['user_id']['email']))<br>@endif
                        Contact: {{ $o['contactNumber'] ?? '—' }}<br>
                        @php $addr = $o['shippingAddress'][0] ?? []; @endphp
                        {{ $addr['fullAddress'] ?? '—' }}
                        @if(!empty($addr['apartment'])) , {{ $addr['apartment'] }} @endif
                        <br>
                        {{ $addr['city'] ?? '' }}{{ !empty($addr['city']) ? ',' : '' }} {{ $addr['state'] ?? '' }} {{ $addr['zipCode'] ?? '' }}<br>
                        {{ $addr['country'] ?? '' }}
                        @php
                          $addrText = implode("\n", array_filter([
                            $o['display_name'] ?? ($o['user_id']['name'] ?? null),
                            $addr['fullAddress'] ?? null,
                            $addr['apartment'] ?? null,
                            implode(', ', array_filter([$addr['city'] ?? null, trim(($addr['state'] ?? '') . ' ' . ($addr['zipCode'] ?? ''))])),
                            $addr['country'] ?? null,
                          ]));
                        @endphp
                        @if(!empty($addr['fullAddress']))
                          <div style="margin-top:8px;"><button type="button" class="wo-btn" style="width:auto;display:inline-block;font-size:12px;padding:4px 10px;" data-addr="{{ $addrText }}" onclick="woCopyAddr(this)">Copy address</button></div>
                        @endif
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
                                  <div style="width:44px;height:44px;border-radius:6px;background:#dcfce7;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#166534;">GC</div>
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
                                  @if(!empty($item['cancelled']))<s>@endif @if($erpProductId)<a class="wo-prod-name" href="{{ url('/products/' . $erpProductId . '/edit') }}" target="_blank" rel="noopener">{{ $item['product_id']['name'] ?? ($item['product_name'] ?? 'Unknown item') }}</a>@else{{ $item['product_id']['name'] ?? ($item['product_name'] ?? 'Unknown item') }}<span class="wo-noerp">not in ERP</span>@endif @if(!empty($item['cancelled']))</s> <span class="wo-pill st-cancelled">Cancelled</span>@endif
                                  @if($isPreorder)
                                    <span style="display:inline-block;margin-left:4px;padding:1px 7px;border-radius:999px;background:#f97316;color:#fff;font-size:10px;font-weight:700;">Preorder</span>
                                    @if($shipDate)
                                      <div style="font-size:11px;color:#c2410c;">Ships {{ gmdate('M j, Y', strtotime($shipDate)) }}</div>
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
                                  @php $listed = \App\Http\Controllers\WebsiteOrdersController::listedLabel($item['product_id']['posCreatedAt'] ?? ($item['product_id']['createdAt'] ?? null)); @endphp
                                  @if($listed)
                                    <div style="font-size:11px;color:#6b7280;">{{ $listed }}</div>
                                  @endif
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

    @if($activeTab === 'to_ship')
      {{-- Discogs orders to ship (Sarah, 2026-10-12): marked shipped here, which
           sets Shipped on Discogs and sends the buyer the tracking number. --}}
      <h3 style="margin:22px 0 10px;font-size:16px;font-weight:700;color:#111827;">
        <span class="wo-pill" style="background:#fef3c7;color:#92400e;">Discogs</span>
        Discogs orders to ship ({{ count($discogsToShip ?? []) }})
      </h3>
      @if(!empty($discogsError))
        <div class="alert-err">{{ $discogsError }}</div>
      @elseif(empty($discogsToShip))
        <div class="ev-card"><div class="empty">No Discogs orders waiting to ship.</div></div>
      @else
        <div class="wo-card">
          <div class="wo-scroll">
          <table class="wo-tbl">
            <colgroup>
              <col style="width:120px"><col style="width:14%"><col><col style="width:108px"><col style="width:86px"><col style="width:92px"><col style="width:236px">
            </colgroup>
            <thead><tr>
              <th>@include('website-orders.partials.sort-th', ['label' => 'Discogs order', 'key' => 'status'])</th>
              <th>@include('website-orders.partials.sort-th', ['label' => 'Buyer', 'key' => 'buyer'])</th>
              <th>@include('website-orders.partials.sort-th', ['label' => 'Items (bin)', 'key' => 'product'])</th>
              <th>@include('website-orders.partials.sort-th', ['label' => 'Listed', 'key' => 'listed'])</th>
              <th>@include('website-orders.partials.sort-th', ['label' => 'Total', 'key' => 'total'])</th>
              <th>@include('website-orders.partials.sort-th', ['label' => 'Ordered', 'key' => 'placed'])</th>
              <th>Mark shipped</th>
            </tr></thead>
            <tbody>
              @foreach($discogsToShip as $d)
                <tr class="wo-row">
                  <td>
                    <span class="wo-pill" style="background:#fef3c7;color:#92400e;">Discogs</span>
                    <div><a href="{{ $d['url'] ?? '#' }}" target="_blank" rel="noopener">{{ $d['id'] }}</a></div>
                    <div style="font-size:11px;color:#6b7280;">{{ $d['status'] ?? '' }}</div>
                  </td>
                  @php
                    $dListed = \App\Http\Controllers\WebsiteOrdersController::listedShort(collect($d['items'] ?? [])->map(fn($it) => $it['listed_at'] ?? null)->filter()->sort()->first());
                  @endphp
                  <td><div class="wo-buyer-name">{{ $d['buyer'] ?? '' }}</div></td>
                  <td style="font-size:13px;">
                    @foreach(($d['items'] ?? []) as $it)
                      <div class="wo-prod-line wo-prod-with-img">
                      @if(!empty($it['image']))<img class="wo-prod-img" src="{{ $it['image'] }}" alt="" loading="lazy">@else<div class="wo-prod-img"></div>@endif
                      <div>@if(!empty($it['erp_product_id']))<a class="wo-prod-name" href="{{ url('/products/' . $it['erp_product_id'] . '/edit') }}" target="_blank" rel="noopener">{{ $it['name'] ?? 'Item' }}</a>@else{{ $it['name'] ?? 'Item' }}<span class="wo-noerp">not in ERP</span>@endif @if(($it['quantity'] ?? 1) > 1) x{{ $it['quantity'] }}@endif @if(!empty($it['location']))<span style="color:#6b7280;">({{ $it['location'] }})</span>@endif
                        @php $dlisted = \App\Http\Controllers\WebsiteOrdersController::listedLabel($it['listed_at'] ?? null); @endphp
                        @if($dlisted && count($d['items'] ?? []) > 1)<div style="font-size:11px;color:#6b7280;">{{ $dlisted }}</div>@endif
                      </div>
                      </div>
                    @endforeach
                  </td>
                  <td class="wo-listed">{{ explode(' · ', (string) ($dListed ?? ''), 2)[0] }}@if(count(explode(' · ', (string) ($dListed ?? ''), 2)) > 1)<span class="t">{{ explode(' · ', (string) ($dListed ?? ''), 2)[1] }}</span>@endif</td>
                  <td class="wo-total">${{ number_format((float) ($d['total'] ?? 0), 2) }}
                    @if(!empty($d['pick']))
                      <div style="font-size:11px;font-weight:600;color:{{ ($d['pick']['picked'] ?? 0) >= ($d['pick']['total'] ?? 0) ? '#047857' : '#6b7280' }};">picked {{ $d['pick']['picked'] ?? 0 }}/{{ $d['pick']['total'] ?? 0 }}</div>
                    @endif
                  </td>
                  <td class="wo-placed">@if(!empty($d['order_date'])){{ date('M j, Y', strtotime($d['order_date'])) }}<span class="t">{{ date('g:ia', strtotime($d['order_date'])) }}</span>@endif</td>
                  <td>
                    <form method="POST" action="{{ route('website-orders.shipDiscogs', ['id' => $d['id']]) }}"
                          onsubmit="var b=this.querySelector('button'); b.disabled=true; b.textContent='Marking...';">
                      {{ csrf_field() }}
                      <input type="text" name="tracking_number" required placeholder="Tracking number"
                             style="width:100%;font-size:13px;padding:7px 9px;border:1px solid #d1d5db;border-radius:7px;box-sizing:border-box;margin-bottom:6px;">
                      <button type="submit" class="wo-btn wo-btn-primary">Mark shipped</button>
                    </form>
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
          </div>
        </div>
      @endif
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
            onsubmit="return woConfirmCancel(this);">
        {{ csrf_field() }}
        <h3>Cancel order</h3>
        <p style="margin:0 0 10px;font-size:13px;color:#991b1b;">Tick the items to cancel. They're refunded with their tax, and the customer gets an email with the reason.</p>
        <label>Items to cancel</label>
        <div id="wo-cancel-items" style="display:grid;gap:6px;margin-bottom:12px;font-size:14px;"></div>
        <input type="hidden" name="item_select" id="wo-cancel-item-select" value="0">
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
      function woCopyAddr(btn) {
        var t = btn.getAttribute('data-addr') || '';
        var done = function () { var o = btn.textContent; btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = o; }, 1500); };
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(t).then(done, function () {}); }
        else { var ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (e) {} ta.remove(); }
      }
      function woCloseMore() {
        document.querySelectorAll('.wo-more.open').forEach(function (m) { m.classList.remove('open'); });
      }
      function woToggleMore(btn, ev) {
        if (ev) ev.stopPropagation();
        var box = btn.closest('.wo-more');
        var wasOpen = box.classList.contains('open');
        woCloseMore();
        if (wasOpen) return;
        box.classList.add('open');
        // Fixed position so the table's overflow wrapper can't clip it.
        var menu = box.querySelector('.wo-more-menu');
        var r = btn.getBoundingClientRect();
        menu.style.left = Math.max(8, r.right - menu.offsetWidth) + 'px';
        var below = r.bottom + 4;
        menu.style.top = (below + menu.offsetHeight > window.innerHeight ? Math.max(8, r.top - menu.offsetHeight - 4) : below) + 'px';
      }
      window.addEventListener('scroll', woCloseMore, true);
      document.addEventListener('click', function (e) { if (!e.target.closest('.wo-more')) woCloseMore(); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape') woCloseMore(); });
      function woToggleDetails(btn) {
        var row = btn.closest('tr');
        var el = row ? row.nextElementSibling : null;
        while (el && !(el.classList && el.classList.contains('wo-detail'))) {
          if (el.classList && el.classList.contains('wo-row')) { el = null; break; }
          el = el.nextElementSibling;
        }
        if (!el) return;
        var hidden = el.style.display === 'none' || getComputedStyle(el).display === 'none';
        el.style.display = hidden ? 'table-row' : 'none';
        btn.textContent = hidden ? 'Hide' : 'Details';
      }
      function woOpenStatus(orderId, currentStatus, currentTracking) {
        var form = document.getElementById('wo-status-form');
        form.action = '{{ url("/website-orders") }}/' + orderId + '/status';
        form.dataset.orderId = orderId;
        document.getElementById('wo-status-select').value = currentStatus || '';
        document.getElementById('wo-status-tracking').value = currentTracking || '';
        document.getElementById('wo-status-dialog').showModal();
      }
      // Picking "Cancelled" here hands off to the Cancel order dialog, which
      // asks for the reason and sends the refund + apology email. A plain
      // status change to cancelled told the customer nothing.
      document.getElementById('wo-status-select').addEventListener('change', function () {
        if (this.value !== 'cancelled') return;
        var orderId = document.getElementById('wo-status-form').dataset.orderId;
        document.getElementById('wo-status-dialog').close();
        woOpenCancel(orderId);
      });
      function woConfirmCancel(form) {
        var boxes = form.querySelectorAll('input[name="item_ids[]"]');
        var picked = form.querySelectorAll('input[name="item_ids[]"]:checked:not(:disabled)');
        var open = form.querySelectorAll('input[name="item_ids[]"]:not(:disabled)');
        if (boxes.length && !picked.length) {
          alert('Tick at least one item to cancel.');
          return false;
        }
        // Same rule as the website: it's only a whole-order cancel when
        // nothing else is left, and a gift card line counts as left (it is
        // never cancelled or refunded here).
        var active = parseInt(form.dataset.active || '0', 10);
        var giftLeft = parseInt(form.dataset.giftLeft || '0', 10);
        var whole = !boxes.length || picked.length === active;
        var ok = confirm(whole
          ? 'Cancel the whole order, refund the customer and email them? This can\'t be undone from here.'
          : (picked.length === open.length && giftLeft
              ? 'Cancel ' + picked.length + ' item(s), refund them and email the customer? The gift card on this order is not refunded and stays active.'
              : 'Cancel ' + picked.length + ' item(s), refund them and email the customer? The rest of the order still goes out.'));
        if (ok) {
          // One submit only: a second click used to send a second cancel.
          var btn = form.querySelector('button[type="submit"]');
          if (btn) { btn.disabled = true; btn.textContent = 'Cancelling...'; }
        }
        return ok;
      }
      function woOpenCancel(orderId, btn) {
        var form = document.getElementById('wo-cancel-form');
        form.action = '{{ url("/website-orders") }}/' + orderId + '/cancel';
        form.reset();
        var box = document.getElementById('wo-cancel-items');
        box.innerHTML = '';
        var lines = [];
        try { lines = JSON.parse((btn && btn.getAttribute('data-lines')) || '[]'); } catch (e) {}
        if (!btn) {
          var b = document.querySelector('button[onclick^="woOpenCancel(\'' + orderId + '\'"]');
          try { lines = JSON.parse((b && b.getAttribute('data-lines')) || '[]'); } catch (e) {}
        }
        document.getElementById('wo-cancel-item-select').value = lines.length ? '1' : '0';
        form.dataset.active = String(lines.filter(function (l) { return !l.cancelled; }).length);
        form.dataset.giftLeft = String(lines.filter(function (l) { return !l.cancelled && l.gift; }).length);
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Cancel order'; }
        lines.forEach(function (l) {
          var row = document.createElement('label');
          row.style.cssText = 'display:flex;gap:8px;align-items:center;text-transform:none;font-weight:400;margin:0;';
          var cb = document.createElement('input');
          cb.type = 'checkbox';
          cb.name = 'item_ids[]';
          cb.value = l.id;
          cb.checked = false;
          cb.disabled = l.cancelled || l.gift;
          var text = document.createElement('span');
          text.textContent = l.name + (l.qty > 1 ? ' x' + l.qty : '') + ' ($' + (l.price * l.qty).toFixed(2) + ')' +
            (l.cancelled ? ' - already cancelled' : l.gift ? ' - gift card, can\'t cancel here' : '');
          row.appendChild(cb);
          row.appendChild(text);
          box.appendChild(row);
        });
        document.getElementById('wo-cancel-dialog').showModal();
      }
    </script>
  @endif

  {{-- Keep this page from going stale — it only pulled fresh data from the
       website on load/navigation, so a new order or a status change made on
       nivessa.com/admin/orders wouldn't show up until someone manually
       reloaded. Auto-reload every 60s, skipped while a dialog is open or a
       filter field has focus so it never yanks the page out from under
       someone mid-edit. --}}
  <script>
    (function () {
      setInterval(function () {
        var statusDialog = document.getElementById('wo-status-dialog');
        var cancelDialog = document.getElementById('wo-cancel-dialog');
        if ((statusDialog && statusDialog.open) || (cancelDialog && cancelDialog.open)) return;
        var active = document.activeElement;
        if (active && /^(INPUT|SELECT|TEXTAREA)$/.test(active.tagName)) return;
        window.location.reload();
      }, 60000);
    })();
  </script>
</div>
@endsection
