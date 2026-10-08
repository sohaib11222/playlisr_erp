@extends('layouts.app')

@section('title', 'Customer Pickups')

@section('content')
@include('sale_pos.partials._redesign_v2')
@include('events.partials._styles')
<script>document.body.classList.add('pos-v2');</script>

<style>
body.pos-v2 .pickup-wrap { max-width: 1280px; margin: 0 auto; padding: 18px 16px 60px; font-family: "Inter Tight", system-ui, sans-serif; color: var(--pos-ink); }
body.pos-v2 .pickup-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; flex-wrap: wrap; }
body.pos-v2 .pickup-head h1 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
body.pos-v2 .pickup-head .sub { color: #6b6253; margin: 0; font-size: 14px; }
body.pos-v2 .pickup-card { background: var(--pos-surface); border: 1px solid var(--pos-line); border-radius: 14px; padding: 18px 20px; margin-bottom: 20px; }
body.pos-v2 .pickup-toolbar { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
body.pos-v2 .pickup-toolbar .filter-label { font-size: 12px; font-weight: 600; color: #5a5145; }
body.pos-v2 .pickup-toolbar select {
  border: 1px solid var(--pos-line-2); border-radius: 9px; padding: 8px 11px; font-size: 14px;
  font-family: inherit; background: #fff; box-shadow: none; height: auto; color: var(--pos-ink); min-width: 200px; }
body.pos-v2 .pickup-toolbar select:focus { outline: none; border-color: var(--pos-accent-deep); box-shadow: 0 0 0 3px var(--pos-accent-soft); }
body.pos-v2 .btn-accent { background: var(--pos-accent); color: var(--pos-accent-text); border: 1px solid var(--pos-accent-deep);
  border-radius: 10px; padding: 10px 18px; font-weight: 700; font-size: 14px; cursor: pointer; font-family: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 7px; }
body.pos-v2 .btn-accent:hover { background: var(--pos-accent-deep); color: var(--pos-accent-text); }

/* DataTable, pos-v2 skin — shared by the AMS pickups table and the
   preorders table below it so both read as one design. */
body.pos-v2 #pickup_table, body.pos-v2 #preorder_table { width: 100% !important; border-collapse: collapse; }
body.pos-v2 #pickup_table thead th, body.pos-v2 #preorder_table thead th {
  text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em;
  color: #8a8070; font-weight: 700; padding: 9px 10px; border-bottom: 1px solid var(--pos-line); background: transparent; }
body.pos-v2 #pickup_table tbody td, body.pos-v2 #preorder_table tbody td { padding: 11px 10px; border-bottom: 1px solid var(--pos-line); font-size: 13.5px; vertical-align: middle; color: var(--pos-ink); }
body.pos-v2 #pickup_table tbody tr:hover, body.pos-v2 #preorder_table tbody tr:hover { background: var(--pos-accent-soft); }
body.pos-v2 #pickup_table .label, body.pos-v2 #preorder_table .label, body.pos-v2 #website_pickup_table .label { font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 999px; }
body.pos-v2 #pickup_table .btn-group, body.pos-v2 #preorder_table .btn-group { display: inline-flex; gap: 5px; }
body.pos-v2 #pickup_table .btn-xs, body.pos-v2 #preorder_table .btn-xs, body.pos-v2 #website_pickup_table .btn-xs { border-radius: 8px; font-family: inherit; font-weight: 600; }
/* Picked-up rows stay listed, tinted green (Sarah, 2026-10-07). */
body.pos-v2 #website_pickup_table tbody tr.row-picked-up td, body.pos-v2 #preorder_table tbody tr.row-picked-up td { background: #e6f4ea; color: #4b6b52; }
body.pos-v2 #preorder_table .source-select {
  border: 1px solid var(--pos-line-2); border-radius: 8px; padding: 4px 8px; font-size: 12px; font-family: inherit;
  background: #fff; color: var(--pos-ink); max-width: 170px; text-overflow: ellipsis; }
body.pos-v2 .preorder-toggle { display: inline-flex; gap: 8px; }
body.pos-v2 .preorder-toggle .btn-accent, body.pos-v2 .preorder-toggle .btn-ghost { padding: 8px 16px; font-size: 13px; }
/* Paid/unpaid status is information, not an action — keep it visually
   distinct from the accent action buttons (Mark paid / Mark picked up)
   in the same row so they don't read as the same kind of control. */
body.pos-v2 #preorder_table .pill-paid, body.pos-v2 #website_pickup_table .pill-paid { background: #e6f4ea; color: #2e7d32; border-color: #cce8d4; }
body.pos-v2 #website_pickup_table .pill { display:inline-block; font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 999px; border: 1px solid transparent; }
body.pos-v2 #website_pickup_table thead th { padding-right: 18px !important; }
body.pos-v2 #website_pickup_table .label { white-space: normal; display: inline-block; line-height: 1.3; }
body.pos-v2 #website_pickup_search { border: 1px solid var(--pos-line-2); border-radius: 8px; padding: 7px 10px; font-family: inherit; background: #fff; min-width: 240px; }
body.pos-v2 #website_pickup_search:focus { outline: none; border-color: var(--pos-accent-deep); box-shadow: 0 0 0 3px var(--pos-accent-soft); }
body.pos-v2 #preorder_table .pill-unpaid, body.pos-v2 #website_pickup_table .pill-unpaid { background: #fdeaea; color: #a23; border-color: #f3cccc; }
body.pos-v2 .dataTables_wrapper .dataTables_filter input,
body.pos-v2 .dataTables_wrapper .dataTables_length select {
  border: 1px solid var(--pos-line-2); border-radius: 8px; padding: 6px 9px; font-family: inherit; background: #fff; }
body.pos-v2 .dataTables_wrapper .dataTables_filter input:focus { outline: none; border-color: var(--pos-accent-deep); box-shadow: 0 0 0 3px var(--pos-accent-soft); }
body.pos-v2 .dataTables_wrapper .dataTables_info,
body.pos-v2 .dataTables_wrapper .dataTables_length,
body.pos-v2 .dataTables_wrapper .dataTables_filter { color: #8a8070; font-size: 13px; }
body.pos-v2 .dataTables_wrapper .dataTables_paginate .paginate_button.current {
  background: var(--pos-accent) !important; border: 1px solid var(--pos-accent-deep) !important; color: var(--pos-accent-text) !important; border-radius: 8px; }
body.pos-v2 .dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 8px; }
</style>

<div class="pickup-wrap">
    <div class="pickup-head">
        <div>
            <h1>Customer Pickups</h1>
            <p class="sub">Items held for customers and AMS special orders on their way in. Hit <strong>Arrived</strong> on an on-order item when it lands to alert the customer.</p>
        </div>
        <a href="{{ action('CustomerPickupController@create') }}" class="btn-accent">
            <i class="fa fa-plus"></i> Add Pickup
        </a>
    </div>

    @if(is_string(session('status')))<div class="alert-ok">{{ session('status') }}</div>@endif
    @if(is_string(session('error')))<div class="alert-err">{{ session('error') }}</div>@endif

    <div class="pickup-card">
        <div class="pickup-toolbar">
            <span class="filter-label">Show:</span>
            <select id="status_filter">
                <option value="">All Statuses</option>
                @foreach($statuses as $key => $label)
                    <option value="{{ $key }}" {{ $key == 'ready' ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="table-responsive">
            <table class="table table-hover" id="pickup_table" style="width:100%">
                <thead>
                    <tr>
                        <th>Store</th>
                        <th>Hold Date</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Qty</th>
                        <th>Expected Pickup</th>
                        <th>Paid?</th>
                        <th>Status</th>
                        <th>Picked Up By</th>
                        <th>Created By</th>
                        <th>Action</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>

    <div class="pickup-card" id="website-pickups">
        <div class="pickup-toolbar" style="justify-content:space-between;">
            <div>
                <strong style="font-size:15px;">Website Pickup Orders</strong>
                <p class="sub" style="margin:2px 0 0;">Paid nivessa.com orders held for in-store pickup — regular checkout, not tied to an event or AMS special order.</p>
            </div>
            @if(!empty($websitePickups))
            @php
                $wpPickedCount = collect($websitePickups)->where('status', 'picked_up')->count();
                $wpWaitingCount = count($websitePickups) - $wpPickedCount;
            @endphp
            <div class="preorder-toggle" id="website_pickup_tabs" style="flex:0 1 auto;">
                <a href="#website-pickups" class="btn-accent js-wp-tab" data-tab="waiting" style="text-decoration:none;">Waiting ({{ $wpWaitingCount }})</a>
                <a href="#website-pickups" class="btn-ghost js-wp-tab" data-tab="picked" style="text-decoration:none;">Picked up ({{ $wpPickedCount }})</a>
            </div>
            <div style="flex:0 1 auto;">
                <input type="search" id="website_pickup_search" placeholder="Search customer name, email, phone or item" aria-label="Search website pickup orders">
            </div>
            @endif
        </div>

        @if(empty($websitePickups))
            <div class="sub" style="padding:8px 2px;">No website pickup orders waiting right now.</div>
        @else
            @php
                // Event pickups (Axis Mundi) don't need a street date, so the
                // column only shows when some other order has one (Sarah, 2026-10-06).
                $showStreetDate = collect($websitePickups)->contains(function ($w) {
                    return !empty($w['shipDate']) && !preg_grep('/axis mundi/i', $w['items']);
                });
            @endphp
            <div class="table-responsive">
            <table class="table" id="website_pickup_table" style="width:100%; font-size:13px;">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Store</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Item(s)</th>
                        <th>Qty</th>
                        <th>Paid</th>
                        <th>Placed</th>
                        @if($showStreetDate)
                        <th>Street Date</th>
                        @endif
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($websitePickups as $wp)
                        @php
                            $shipTs = !empty($wp['shipDate']) ? strtotime($wp['shipDate']) : null;
                            $notYetDue = !empty($wp['isPreorder']) && $shipTs && $shipTs > time();
                        @endphp
                        @php
                            $placedTs = !empty($wp['placed']) ? strtotime($wp['placed']) : 0;
                            $methodLabel = ['stripe' => 'Card', 'paypal' => 'PayPal', 'free' => 'No charge'][$wp['paymentMethod'] ?? ''] ?? ucfirst($wp['paymentMethod'] ?? '');
                            if (!empty($wp['storeCredit'])) { $methodLabel = trim($methodLabel . ' + store credit'); }
                            $statusSort = !empty($wp['isPreorder']) ? ($notYetDue ? 0 : 1) : ($wp['status'] === 'ready_for_pickup' ? 3 : 2);
                            // Signing-event preorders get handed out at the event,
                            // not pulled for a regular pickup (Sarah, 2026-10-06).
                            $eventPickup = (bool) preg_grep('/axis mundi/i', $wp['items']);
                            // Picked up: keep the row (staff still need the name), just green.
                            $pickedUp = ($wp['status'] ?? '') === 'picked_up';
                            if ($pickedUp) { $statusSort = 9; }
                        @endphp
                        <tr @if($pickedUp) class="row-picked-up" @elseif(!empty($wp['isPreorder'])) style="background:#fff7e0;" @endif>
                            <td data-order="{{ $loop->iteration }}">{{ $loop->iteration }}</td>
                            <td data-order="{{ $wp['location'] === 'pico' ? 'Pico' : 'Hollywood' }}">{{ $wp['location'] === 'pico' ? 'Pico' : 'Hollywood' }}</td>
                            <td data-order="{{ strtolower($wp['customer']) }}">{{ $wp['customer'] }}</td>
                            <td data-order="{{ strtolower($wp['email']) }}">@if($wp['email'] !== ''){!! str_replace('@', '<wbr>@', e($wp['email'])) !!}@else — @endif</td>
                            <td style="white-space:nowrap;">{{ $wp['phone'] ?: '—' }}</td>
                            <td data-order="{{ strtolower(implode(', ', $wp['items'])) }}" style="min-width:190px;">
                                @forelse($wp['items'] as $itemLabel)
                                    <div>{{ $itemLabel }}</div>
                                @empty
                                    —
                                @endforelse
                            </td>
                            <td data-order="{{ $wp['unitCount'] }}"><strong>{{ $wp['unitCount'] }}</strong></td>
                            <td data-order="{{ $wp['total'] ?? 0 }}" style="white-space:nowrap;">
                                @if($wp['total'] !== null)<div>${{ number_format($wp['total'], 2) }}</div>@endif
                                @if(!empty($wp['paid']))
                                    <span class="pill pill-paid">Paid</span>
                                @else
                                    <span class="pill pill-unpaid">Unpaid</span>
                                @endif
                                @if($methodLabel !== '') <span class="sub">{{ $methodLabel }}</span> @endif
                            </td>
                            <td class="sub" data-order="{{ $placedTs }}" >@if($placedTs){{ date('n/j/y', $placedTs) }}<br>{{ date('g:ia', $placedTs) }}@else — @endif</td>
                            @if($showStreetDate)
                            <td data-order="{{ $shipTs ?: 0 }}" style="white-space:nowrap;">{{ ($shipTs && !$eventPickup) ? gmdate('n/j/y', $shipTs) : '—' }}</td>
                            @endif
                            <td data-order="{{ $statusSort }}">
                                @if($pickedUp)
                                    <span class="label" style="background:#2e7d32; font-weight:700;">Picked up</span>
                                @elseif($eventPickup)
                                    <span class="label" style="background:#2e7d32; font-weight:700;">Will pick up at event</span>
                                @elseif(!empty($wp['isPreorder']))
                                    <span class="label" style="background:#c9720a; font-weight:700;">PREORDER — NOT IN STOCK</span><br>
                                    @if($notYetDue)
                                        <span class="sub" style="color:#a23;">Don't pull — ships {{ gmdate('M j, Y', $shipTs) }}</span>
                                    @else
                                        <span class="sub">Street date has passed — check it's in before pulling</span>
                                    @endif
                                @elseif($wp['status'] === 'ready_for_pickup')
                                    <span class="label label-warning">Ready for Pickup</span>
                                @else
                                    <span class="label label-default">Preparing</span>
                                @endif
                            </td>
                            <td style="white-space:nowrap;">
                                @if($pickedUp)
                                    <span class="sub">Done</span>
                                @elseif(($wp['source'] ?? '') === 'store_hold')
                                    <button type="button" class="btn btn-success btn-xs js-hold-picked-up" data-id="{{ $wp['id'] }}">Mark Picked Up</button>
                                @elseif($notYetDue)
                                    <span class="sub">Available after street date</span>
                                @elseif(!$eventPickup)
                                    {{-- Regular pickups: change status from a dropdown (Sarah, 2026-10-06).
                                         Cancel stays on /website-orders since it can involve a refund. --}}
                                    <form method="POST" action="{{ route('website-orders.updateStatus', ['id' => $wp['id']]) }}" style="display:inline;">
                                        {{ csrf_field() }}
                                        <select name="status" class="form-control input-sm" style="height:28px; padding:2px 6px; font-size:12px; width:auto;" onchange="this.form.submit()" aria-label="Order status">
                                            @foreach(['processing' => 'Preparing', 'ready_for_pickup' => 'Ready for Pickup', 'picked_up' => 'Picked Up'] as $sv => $sl)
                                                <option value="{{ $sv }}" @if($wp['status'] === $sv) selected @endif>{{ $sl }}</option>
                                            @endforeach
                                        </select>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('website-orders.updateStatus', ['id' => $wp['id']]) }}" style="display:inline;">
                                        {{ csrf_field() }}
                                        <input type="hidden" name="status" value="picked_up">
                                        <button type="submit" class="btn btn-success btn-xs">Mark Picked Up</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    <div class="pickup-card" id="preorders">
        <div class="pickup-toolbar" style="justify-content:space-between;">
            <div>
                <strong style="font-size:15px;">Party &amp; Special-Order Preorders</strong>
                <p class="sub" style="margin:2px 0 0;">Listening-party reservations and in-store special orders — separate from AMS special orders above until they're placed with a distributor.</p>
            </div>
            <div class="preorder-toggle" style="flex:0 1 auto;">
                <a class="{{ $preorderShowAll ? 'btn-ghost' : 'btn-accent' }}" href="{{ action('CustomerPickupController@index') }}#preorders" style="text-decoration:none;">Active</a>
                <a class="{{ $preorderShowAll ? 'btn-accent' : 'btn-ghost' }}" href="{{ action('CustomerPickupController@index', ['preorder_status' => 'all']) }}#preorders" style="text-decoration:none;">All</a>
            </div>
        </div>

        @if(!$preorderKeySet)
            <div class="alert-ok" style="border:1px solid var(--pos-accent,#FFE08A);background:transparent;padding:10px 14px;border-radius:10px;">
                Listening-party preorders live on nivessa.com. Set the <code>ERP_API_KEY</code> from any event's edit page to pull them in here. In-store special orders still show below.
            </div>
        @elseif(!$preorderReachable)
            <div class="alert-err" style="border:1px solid #f0c2c2;background:transparent;padding:10px 14px;border-radius:10px;">
                A key is set, but nivessa.com rejected it or was unreachable. In-store special orders still show below; re-check the key from an event's edit page.
            </div>
        @endif

        @if(empty($preorders))
            <div class="sub" style="padding:8px 2px;">{{ $preorderShowAll ? 'No preorders yet.' : 'No active preorders.' }}</div>
        @else
            @php $sourceOpts = ['Website order', 'Instagram DM', 'Phone', 'Email', 'Walk-in']; @endphp
            <div class="table-responsive">
            <table class="table" id="preorder_table" style="width:100%;">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Item</th>
                        <th>Price</th>
                        <th>Where placed</th>
                        <th>Placed</th>
                        <th>Pickup</th>
                        <th>Paid</th>
                        @if($preorderShowAll)<th>Status</th>@endif
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($preorders as $p)
                        @php
                            $placed = !empty($p['placed']) ? date('M j, Y', strtotime($p['placed'])) : '—';
                            $pickup = !empty($p['pickup']) ? date('l, M j, Y', strtotime($p['pickup'])) : '—';
                            $filterVal = $preorderShowAll ? 'all' : '';
                            $sourceForSort = $p['source'] !== '' ? $p['source'] : ('At event' . ($p['eventName'] ? ' — ' . $p['eventName'] : ''));
                            $paidSort = !$p['paidKnown'] ? 0 : ($p['paid'] ? 2 : 1);
                            $pickedUp = $p['statusLabel'] === 'picked up';
                        @endphp
                        <tr @if($pickedUp) class="row-picked-up" @endif>
                            <td>{{ $p['name'] }}
                                <div class="sub" style="margin:0;">{{ $p['email'] }}@if(!empty($p['phone']))@if(!empty($p['email'])) &middot; @endif{{ $p['phone'] }}@endif</div>
                            </td>
                            <td>{{ $p['item'] }}</td>
                            <td data-order="{{ $p['price'] ?? 0 }}">{{ $p['price'] !== null ? '$' . number_format((float) $p['price'], 2) : '—' }}</td>
                            <td data-order="{{ $sourceForSort }}">
                                @if($p['type'] === 'event')
                                    <form method="POST" action="{{ route('events.overviewEventSource', ['preorderId' => $p['id']]) }}" style="margin:0;">
                                        {{ csrf_field() }}
                                        <input type="hidden" name="filter" value="{{ $filterVal }}">
                                        <select name="source" onchange="this.form.submit()" class="source-select" title="{{ $p['source'] !== '' ? $p['source'] : ('At event' . ($p['eventName'] ? ' — ' . $p['eventName'] : '')) }}">
                                            <option value="" {{ $p['source'] === '' ? 'selected' : '' }}>At event{{ $p['eventName'] ? ' — ' . $p['eventName'] : '' }}</option>
                                            @foreach($sourceOpts as $opt)
                                                <option value="{{ $opt }}" {{ $p['source'] === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                                            @endforeach
                                            @if($p['source'] !== '' && !in_array($p['source'], $sourceOpts, true))
                                                <option value="{{ $p['source'] }}" selected>{{ $p['source'] }}</option>
                                            @endif
                                        </select>
                                    </form>
                                    @if(!empty($p['eventId']))
                                        <div style="margin-top:3px;"><a href="{{ route('events.edit', ['id' => $p['eventId']]) }}" style="font-size:12px;">Open event</a></div>
                                    @endif
                                @else
                                    <span class="label" style="background:#7a6a4a;">{{ $p['sourceTag'] }}</span>
                                @endif
                            </td>
                            <td class="sub" data-order="{{ $p['placed'] ?? '' }}">{{ $placed }}</td>
                            <td data-order="{{ $p['pickup'] ?? '' }}">{{ $pickup }}</td>
                            <td data-order="{{ $paidSort }}">
                                @if(!$p['paidKnown'])
                                    <span class="sub">—</span>
                                @elseif($p['paid'])
                                    <span class="pill pill-paid">Paid</span>
                                @else
                                    <span class="pill pill-unpaid">Unpaid</span>
                                @endif
                            </td>
                            @if($preorderShowAll)<td>{{ $p['statusLabel'] }}</td>@endif
                            <td style="white-space:nowrap;">
                                @if($pickedUp && !$preorderShowAll)
                                    <span class="label" style="background:#2e7d32; font-weight:700;">Picked up</span>
                                @endif
                                @if(!empty($p['active']))
                                    @if($p['type'] === 'event' && $p['paidKnown'] && empty($p['paid']))
                                        <form method="POST" action="{{ route('events.overviewEventPaid', ['preorderId' => $p['id']]) }}" style="display:inline;">
                                            {{ csrf_field() }}
                                            <input type="hidden" name="filter" value="{{ $filterVal }}">
                                            <button type="submit" class="btn-ghost" style="padding:5px 12px;font-size:12px;">Mark paid</button>
                                        </form>
                                    @endif
                                    @if($p['type'] === 'event')
                                        <form method="POST" action="{{ route('events.overviewEventPickup', ['preorderId' => $p['id']]) }}" style="display:inline;">
                                            {{ csrf_field() }}
                                            <input type="hidden" name="filter" value="{{ $filterVal }}">
                                            <button type="submit" class="btn-accent" style="padding:5px 12px;font-size:12px;">Mark picked up</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('events.overviewSpecialPickup', ['id' => $p['id']]) }}" style="display:inline;">
                                            {{ csrf_field() }}
                                            <input type="hidden" name="filter" value="{{ $filterVal }}">
                                            <button type="submit" class="btn-accent" style="padding:5px 12px;font-size:12px;">Mark picked up</button>
                                        </form>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
</div>

<div class="modal fade" id="pickup_completion_modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="pickup_completion_form">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title">Mark as Picked Up</h4>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Picked up by (name, optional):</label>
                        <input type="text" class="form-control" name="picked_up_by_name" placeholder="Who physically picked it up?">
                        <small class="help-block">Your cashier name + timestamp are captured automatically.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Confirm Pickup</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="pickup_arrived_modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="pickup_arrived_form">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title">Mark Arrived</h4>
                </div>
                <div class="modal-body">
                    <p>This special order just came in. Notify the customer it's ready for pickup?</p>
                    <div class="radio"><label><input type="radio" name="notify_method" value="none" checked> Don't notify — I'll let them know</label></div>
                    <div class="radio"><label><input type="radio" name="notify_method" value="email"> Email</label></div>
                    <div class="radio"><label><input type="radio" name="notify_method" value="sms"> Text (OpenPhone)</label></div>
                    <div class="radio"><label><input type="radio" name="notify_method" value="both"> Both</label></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Mark Arrived</button>
                </div>
            </form>
        </div>
    </div>
</div>

@stop
@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        var pickup_table = $('#pickup_table').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ action("CustomerPickupController@index") }}',
                data: function(d) {
                    d.status = $('#status_filter').val();
                }
            },
            columns: [
                { data: 'location_name', name: 'business_locations.name', defaultContent: '-' },
                { data: 'hold_date', name: 'hold_date' },
                { data: 'customer_name', name: 'contacts.name' },
                { data: 'product_name', name: 'products.name', defaultContent: '-' },
                { data: 'sub_sku', name: 'variations.sub_sku', defaultContent: '-' },
                { data: 'quantity', name: 'quantity' },
                { data: 'expected_pickup_date', name: 'expected_pickup_date' },
                { data: 'is_paid_label', name: 'customer_pickups.is_paid', orderable: true, searchable: false },
                { data: 'status', name: 'status' },
                { data: 'picked_up_info', name: 'picked_up_info', orderable: false, searchable: false },
                { data: 'created_info', name: 'created_info', orderable: false, searchable: false },
                { data: 'action', name: 'action', orderable: false, searchable: false },
            ],
            order: [[1, 'desc']],
        });

        // Preorders table is fully server-rendered (small, non-paginated
        // list) — just bolt on client-side sorting, no ajax/paging/search.
        // Sort values come from each <td>'s data-order (raw price/date/
        // paid-priority) so sorting is correct, not alphabetical-on-HTML.
        // Website pickup orders: same treatment — every column sortable
        // (data-order carries the raw value), plus a customer search box
        // that drives DataTables' filter so it matches name, email, phone
        // and item text.
        if ($('#website_pickup_table tbody tr').length) {
            var website_pickup_table = $('#website_pickup_table').DataTable({
                paging: false,
                searching: true,
                // The site-wide sticky header (common.js) swaps in a header
                // clone with no click handlers here, so sorting never fired.
                fixedHeader: false,
                dom: 't',
                info: false,
                order: [],
                columnDefs: [{ targets: [0, -1], orderable: false }],
            });
            // Keep # as a plain 1..N row count in whatever order is shown,
            // so sorting/searching doesn't scramble it.
            website_pickup_table.on('order.dt search.dt draw.dt', function() {
                website_pickup_table.column(0, { search: 'applied', order: 'applied' }).nodes().each(function(cell, i) {
                    cell.innerHTML = i + 1;
                });
            });
            // Waiting / Picked up tabs: picked-up rows live on their own tab
            // so open orders are easy to see (Sarah, 2026-10-07).
            var wpTab = 'waiting';
            $.fn.dataTable.ext.search.push(function(settings, data, idx) {
                if (settings.nTable.id !== 'website_pickup_table') { return true; }
                var picked = $(website_pickup_table.row(idx).node()).hasClass('row-picked-up');
                return wpTab === 'picked' ? picked : !picked;
            });
            $('#website_pickup_tabs').on('click', '.js-wp-tab', function(e) {
                e.preventDefault();
                wpTab = $(this).data('tab');
                $('#website_pickup_tabs .js-wp-tab').removeClass('btn-accent').addClass('btn-ghost');
                $(this).removeClass('btn-ghost').addClass('btn-accent');
                website_pickup_table.draw();
            });
            website_pickup_table.draw();
            $('#website_pickup_search').on('input search', function() {
                website_pickup_table.search(this.value).draw();
            });
            // In-store event holds use the regular pickup endpoint (JSON).
            $('#website_pickup_table').on('click', '.js-hold-picked-up', function() {
                var btn = $(this).prop('disabled', true);
                $.post('/customer-pickups/' + btn.data('id') + '/mark-picked-up', {
                    _token: $('meta[name="csrf-token"]').attr('content')
                }).done(function(res) {
                    if (res && res.success) { location.reload(); }
                    else { toastr.error((res && res.msg) || 'Could not mark picked up'); btn.prop('disabled', false); }
                }).fail(function() { toastr.error('Could not mark picked up'); btn.prop('disabled', false); });
            });
        }

        if ($('#preorder_table tbody tr').length) {
            $('#preorder_table').DataTable({
                paging: false,
                searching: false,
                info: false,
                order: [],
                columnDefs: [{ targets: -1, orderable: false }],
            });
        }

        $('#status_filter').on('change', function() {
            pickup_table.ajax.reload();
        });

        $(document).on('click', '.delete_pickup', function(e) {
            e.preventDefault();
            var url = $(this).attr('data-href');
            swal({
                title: LANG.sure,
                icon: "warning",
                buttons: true,
                dangerMode: true,
            }).then((confirmed) => {
                if (confirmed) {
                    $.ajax({
                        method: 'DELETE',
                        url: url,
                        dataType: 'json',
                        success: function(result) {
                            if (result.success) {
                                toastr.success(result.msg);
                                pickup_table.ajax.reload();
                            } else {
                                toastr.error(result.msg);
                            }
                        }
                    });
                }
            });
        });

        var pickup_url_to_complete = null;
        $(document).on('click', '.mark_picked_up', function(e) {
            e.preventDefault();
            pickup_url_to_complete = $(this).attr('data-href');
            $('#pickup_completion_form')[0].reset();
            $('#pickup_completion_modal').modal('show');
        });

        $('#pickup_completion_form').on('submit', function(e) {
            e.preventDefault();
            if (!pickup_url_to_complete) return;
            $.ajax({
                method: 'POST',
                url: pickup_url_to_complete,
                data: $(this).serialize(),
                dataType: 'json',
                success: function(result) {
                    $('#pickup_completion_modal').modal('hide');
                    if (result.success) {
                        toastr.success(result.msg);
                        pickup_table.ajax.reload();
                    } else {
                        toastr.error(result.msg);
                    }
                }
            });
        });

        // AMS special order arrived → optional customer alert.
        var pickup_url_to_arrive = null;
        $(document).on('click', '.mark_arrived', function(e) {
            e.preventDefault();
            pickup_url_to_arrive = $(this).attr('data-href');
            $('#pickup_arrived_form')[0].reset();
            $('#pickup_arrived_modal').modal('show');
        });

        $('#pickup_arrived_form').on('submit', function(e) {
            e.preventDefault();
            if (!pickup_url_to_arrive) return;
            var $btn = $(this).find('button[type="submit"]').prop('disabled', true);
            $.ajax({
                method: 'POST',
                url: pickup_url_to_arrive,
                data: $(this).serialize() + '&_token=' + encodeURIComponent($('meta[name="csrf-token"]').attr('content') || ''),
                dataType: 'json',
                success: function(result) {
                    $('#pickup_arrived_modal').modal('hide');
                    $btn.prop('disabled', false);
                    if (result.success) {
                        toastr.success(result.msg);
                        var n = result.notifications || {};
                        Object.keys(n).forEach(function(k) {
                            (n[k].ok ? toastr.success : toastr.warning)(k + ': ' + n[k].msg);
                        });
                        pickup_table.ajax.reload();
                    } else {
                        toastr.error(result.msg);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false);
                    toastr.error('Something went wrong.');
                }
            });
        });
    });
</script>
@stop
