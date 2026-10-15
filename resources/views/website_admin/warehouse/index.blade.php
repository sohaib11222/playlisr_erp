@extends('layouts.app')

@section('title', 'Warehouse')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>
@include('events.partials._styles')
@include('website_admin.warehouse._styles')

@php
  $WH = \App\Http\Controllers\WebsiteAdmin\WarehouseController::class;
  $tz = 'America/Los_Angeles';
  $baseQ = array_filter([
    'q' => $f['q'], 'source' => $f['source'], 'discogs_status' => $f['discogs_status'],
    'picking_status' => $f['picking_status'], 'zone' => $f['zone'], 'sort_by' => $f['sort_by'] !== 'newest' ? $f['sort_by'] : '',
    'all' => $f['all'] === '0' ? '0' : '',
  ], fn($v) => $v !== '');
  $sortUrl = function ($key) use ($baseQ, $sort, $dir) {
    $d = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
    return route('wa.warehouse.index', $baseQ + ['sort' => $key, 'dir' => $d]);
  };
  $arrow = fn($key) => $sort === $key ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
  $pageUrl = fn($p) => route('wa.warehouse.index', $baseQ + array_filter(['sort' => $sort, 'dir' => $sort ? $dir : ''], fn($v) => $v !== '') + ['page' => $p]);
@endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Warehouse</h1>
      <p class="sub">Every Discogs, website and eBay order on the pick list, with picking progress. Shipping, cancelling and order status for website orders stay on the ERP Orders page.</p>
    </div>
    <form method="POST" action="{{ route('wa.warehouse.sync') }}" onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').textContent='Syncing, this can take a minute...';">
      {{ csrf_field() }}
      <button type="submit" class="wh-btn wh-btn-primary">Sync Discogs</button>
    </form>
  </div>

  @include('website_admin.warehouse._nav')

  @if($quick)
    <div class="wh-stats">
      <div class="wh-stat"><div class="l">Pending Orders</div><div class="v">{{ $quick['pendingOrders'] ?? 0 }}</div><div class="h">Not started yet</div></div>
      <div class="wh-stat blue"><div class="l">In Progress</div><div class="v">{{ $quick['inProgressOrders'] ?? 0 }}</div><div class="h">Currently picking</div></div>
      <div class="wh-stat green"><div class="l">Completed (Month)</div><div class="v">{{ $quick['completedLast7Days'] ?? 0 }}</div><div class="h">Shipped this month</div></div>
      <div class="wh-stat purple"><div class="l">Revenue (Month)</div><div class="v">${{ number_format((float) ($quick['revenueThisMonth'] ?? 0), 2) }}</div><div class="h">This month</div></div>
    </div>
  @endif

  <form method="GET" action="{{ route('wa.warehouse.index') }}" class="wh-filters">
    <div class="wh-field wh-grow">
      <label for="wh-q">Search</label>
      <input id="wh-q" type="text" name="q" value="{{ $f['q'] }}" placeholder="Order ID, order number, customer, item title or artist">
    </div>
    <div class="wh-field">
      <label>Platform</label>
      <select name="source" onchange="this.form.submit()">
        <option value="">All platforms</option>
        @foreach(['Discogs', 'Website', 'eBay'] as $s)
          <option value="{{ $s }}" @if($f['source'] === $s) selected @endif>{{ $s }}</option>
        @endforeach
      </select>
    </div>
    <div class="wh-field">
      <label>Order Status</label>
      <select name="discogs_status" onchange="this.form.submit()">
        <option value="">All</option>
        @foreach($discogsStatuses as $s)
          <option value="{{ $s }}" @if($f['discogs_status'] === $s) selected @endif>{{ $s }}</option>
        @endforeach
      </select>
    </div>
    <div class="wh-field">
      <label>Picking Status</label>
      <select name="picking_status" onchange="this.form.submit()">
        <option value="">All</option>
        @foreach(['Not Started', 'In Progress', 'Completed', 'Issues'] as $s)
          <option value="{{ $s }}" @if($f['picking_status'] === $s) selected @endif>{{ $s }}</option>
        @endforeach
      </select>
    </div>
    <div class="wh-field">
      <label>Zone</label>
      <select name="zone" onchange="this.form.submit()">
        <option value="">All zones</option>
        @foreach($zones as $z)
          <option value="{{ $z }}" @if($f['zone'] === $z) selected @endif>{{ $z }}</option>
        @endforeach
      </select>
    </div>
    <div class="wh-field">
      <label>Sort By</label>
      <select name="sort_by" onchange="this.form.submit()">
        <option value="newest" @if($f['sort_by'] === 'newest') selected @endif>Newest first</option>
        <option value="oldest" @if($f['sort_by'] === 'oldest') selected @endif>Oldest first</option>
        <option value="highest_picked" @if($f['sort_by'] === 'highest_picked') selected @endif>Highest % picked</option>
      </select>
    </div>
    <label class="wh-check">
      <input type="hidden" name="all" value="0">
      <input type="checkbox" name="all" value="1" @if($f['all'] === '1') checked @endif onchange="this.form.submit()">
      Show all orders, including shipped and cancelled
    </label>
    <div class="wh-field" style="flex-direction:row; gap:8px;">
      <button type="submit" class="wh-btn wh-btn-primary">Search</button>
      <a href="{{ route('wa.warehouse.index') }}" class="wh-btn">Clear filters</a>
    </div>
  </form>

  <div class="wh-card">
    @if($error)
      <div class="wh-card-pad"><div class="wh-note err" style="margin:0;">Could not load orders: {{ $error }}</div></div>
    @elseif(count($orders) === 0)
      <div class="wh-card-pad empty">No orders match these filters. Try syncing Discogs or changing the filters.</div>
    @else
      <div class="wh-scroll">
        <table class="wh-tbl" style="min-width:1400px;">
          <thead>
            <tr>
              <th><a href="{{ $sortUrl('order_id') }}">Order ID{{ $arrow('order_id') }}</a></th>
              <th><a href="{{ $sortUrl('customer') }}">Customer{{ $arrow('customer') }}</a></th>
              <th><a href="{{ $sortUrl('items') }}">Items{{ $arrow('items') }}</a></th>
              <th><a href="{{ $sortUrl('total') }}">Total{{ $arrow('total') }}</a></th>
              <th>Source</th>
              <th>Fulfillment</th>
              <th style="min-width:260px;">Zones</th>
              <th><a href="{{ $sortUrl('status') }}">Status{{ $arrow('status') }}</a></th>
              <th><a href="{{ $sortUrl('picking') }}">Picking{{ $arrow('picking') }}</a></th>
              <th><a href="{{ $sortUrl('percentage') }}">% Picked{{ $arrow('percentage') }}</a></th>
              <th><a href="{{ $sortUrl('date') }}">Date{{ $arrow('date') }}</a></th>
              <th>Notes</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            @foreach($orders as $o)
              @php
                $oid = $o['_id'] ?? '';
                $items = $o['items'] ?? [];
                $groups = [];
                $noZone = [];
                foreach ($items as $it) {
                  $loc = $WH::effectiveLocation($it);
                  if (!empty($loc['zone'])) {
                    $k = $loc['zone'] . '|' . ($loc['sub_location'] ?? '') . '|' . ($loc['shelf_details'] ?? '') . '|' . ($loc['discogs_location_code'] ?? '');
                    if (!isset($groups[$k])) {
                      $groups[$k] = ['loc' => $loc, 'code' => ($loc['discogs_location_code'] ?? null) ?: ($it['discogs_location'] ?? null), 'items' => []];
                    }
                    $groups[$k]['items'][] = $it;
                  } else {
                    $noZone[] = $it;
                  }
                }
                $status = $o['status'] ?? $o['order_status'] ?? 'pending';
                $pct = (int) ($o['picking_percentage'] ?? 0);
                $pctColor = $pct === 100 ? '#16a34a' : ($pct >= 50 ? '#2563eb' : ($pct > 0 ? '#ca8a04' : '#d1d5db'));
                $total = $o['total_amount'] ?? ($o['total']['value'] ?? 0);
                $pickup = ($o['fulfillment_method'] ?? '') === 'pickup';
                $pickupLabel = ['hollywood' => 'Hollywood', 'pico' => 'Pico'][$o['pickup_location'] ?? ''] ?? null;
                $pay = $o['payment_status'] ?? null;
              @endphp
              <tr>
                <td>
                  <a class="wh-link" href="{{ route('wa.warehouse.show', $oid) }}">{{ ($o['external_order_id'] ?? '') ?: substr($oid, -8) }}</a>
                  @if(($o['source'] ?? '') === 'Discogs' && !empty($o['external_order_id']))
                    <div><a class="wh-muted" href="https://www.discogs.com/sell/order/{{ $o['external_order_id'] }}" target="_blank" rel="noopener">Open on Discogs</a></div>
                  @endif
                </td>
                <td>{{ ($o['customer_name'] ?? '') ?: ($o['buyer']['name'] ?? 'N/A') }}</td>
                <td>{{ count($items) }} {{ count($items) === 1 ? 'item' : 'items' }}</td>
                <td style="font-weight:600; color:#16a34a;">${{ number_format((float) $total, 2) }}</td>
                <td><span class="wh-pill {{ ['Discogs' => 'purple', 'Website' => 'blue', 'eBay' => 'yellow'][$o['source'] ?? ''] ?? '' }}">{{ $o['source'] ?? 'Unknown' }}</span></td>
                <td>
                  @if($pickup)
                    <span class="wh-pill {{ $pickupLabel ? 'purple' : 'red' }}">Pickup ({{ $pickupLabel ?: 'No Location Set' }})</span>
                  @else
                    <span class="wh-pill sky">Shipping</span>
                  @endif
                </td>
                <td>
                  @foreach($groups as $g)
                    <div class="wh-locgrp">
                      @if(!empty($g['loc']['fromProduct']))<div class="wh-muted" style="font-size:10.5px; text-transform:uppercase;">From product catalog</div>@endif
                      <div style="margin-bottom:5px;">
                        <span class="wh-tag zone">{{ $g['loc']['zone'] }}</span>
                        @if(!empty($g['loc']['sub_location']))<span class="wh-tag sub">{{ $g['loc']['sub_location'] }}</span>@endif
                        @if(!empty($g['loc']['shelf_details']))<span class="wh-tag shelf">{{ $g['loc']['shelf_details'] }}</span>@endif
                        @if(!empty($g['code']))<span class="wh-tag code">{{ $g['code'] }}</span>@endif
                        <button type="button" class="wh-btn wh-btn-sm" style="padding:2px 8px;" onclick="whEditZone('{{ $oid }}', '{{ $g['items'][0]['_id'] ?? '' }}', {{ json_encode($g['loc']['zone']) }})">Edit</button>
                      </div>
                      <div class="wh-thumbs">
                        @foreach($g['items'] as $it)
                          @php $ps = $it['picking_status']['status'] ?? 'Not Picked'; @endphp
                          <div class="wh-thumb {{ $ps === 'Picked' ? 'picked' : ($ps === "Can't Find" ? 'cant' : '') }}" title="{{ $it['name'] ?? '' }}{{ ($it['quantity'] ?? 1) > 1 ? ' (x' . $it['quantity'] . ')' : '' }}. {{ $ps }}">
                            @if(!empty($it['image']))<img src="{{ $it['image'] }}" alt="" loading="lazy">@endif
                            @if(($it['quantity'] ?? 1) > 1)<span class="q">{{ $it['quantity'] }}</span>@endif
                            @if(($it['cover_type'] ?? '') === 'generic')<span class="g">G</span>@endif
                          </div>
                        @endforeach
                      </div>
                      <div class="wh-muted" style="margin-top:3px;">{{ count($g['items']) }} {{ count($g['items']) === 1 ? 'item' : 'items' }}</div>
                    </div>
                  @endforeach
                  @if(count($noZone))
                    <div class="wh-locgrp nozone">
                      <div style="margin-bottom:5px;">
                        <span class="wh-pill red">{{ count($noZone) }} {{ count($noZone) === 1 ? 'item has' : 'items have' }} no zone</span>
                        <button type="button" class="wh-btn wh-btn-sm" style="padding:2px 8px;" onclick="whEditZone('{{ $oid }}', '{{ $noZone[0]['_id'] ?? '' }}', '')">Add zone</button>
                      </div>
                      <div class="wh-thumbs">
                        @foreach($noZone as $it)
                          <div class="wh-thumb" style="cursor:pointer;" title="{{ $it['name'] ?? '' }}" onclick="whEditZone('{{ $oid }}', '{{ $it['_id'] ?? '' }}', '')">
                            @if(!empty($it['image']))<img src="{{ $it['image'] }}" alt="" loading="lazy" style="border-color:#fca5a5;">@endif
                          </div>
                        @endforeach
                      </div>
                    </div>
                  @endif
                  @if(!count($items))<span class="wh-muted">No items</span>@endif
                </td>
                <td>
                  <span class="wh-pill {{ $WH::statusColor($status) }}">{{ $status }}</span>
                  @if(($o['source'] ?? '') === 'Website' && $pay)
                    <div style="margin-top:4px;"><span class="wh-pill {{ ['completed' => 'green', 'pending' => 'yellow', 'failed' => 'red'][$pay] ?? '' }}">{{ ['completed' => 'Paid', 'pending' => 'Unpaid', 'failed' => 'Failed', 'refunded' => 'Refunded'][$pay] ?? $pay }}</span></div>
                  @endif
                </td>
                <td><span class="wh-pill {{ $WH::pickingColor($o['overall_picking_status'] ?? '') }}">{{ ($o['overall_picking_status'] ?? '') ?: 'Not Picked' }}</span></td>
                <td style="white-space:nowrap;"><span class="wh-bar"><span style="width:{{ $pct }}%; background:{{ $pctColor }};"></span></span><b style="color:{{ $pct ? $pctColor : '#9ca3af' }};">{{ $pct }}%</b></td>
                <td style="white-space:nowrap;">{{ !empty($o['order_date']) ? \Carbon\Carbon::parse($o['order_date'])->setTimezone($tz)->format('n/j/Y') : 'N/A' }}</td>
                <td style="max-width:200px;">
                  @if(!empty($o['warehouse_notes']))
                    <span title="{{ $o['warehouse_notes'] }}" style="font-size:12.5px; color:#4b5563;">{{ \Illuminate\Support\Str::limit($o['warehouse_notes'], 60) }}</span>
                  @else
                    <span class="wh-muted">No notes</span>
                  @endif
                </td>
                <td><a class="wh-btn wh-btn-sm" href="{{ route('wa.warehouse.show', $oid) }}">View</a></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      @if($totalPages > 1)
        <div class="wh-pager">
          <div>Showing {{ ($page - 1) * 50 + 1 }} to {{ min($page * 50, $totalCount) }} of {{ $totalCount }}</div>
          <div class="pages">
            @if($page > 1)<a href="{{ $pageUrl($page - 1) }}">Previous</a>@endif
            @php
              $from = max(1, min($page - 2, $totalPages - 4));
              $to = min($totalPages, $from + 4);
            @endphp
            @for($p = $from; $p <= $to; $p++)
              @if($p === $page)<span class="cur">{{ $p }}</span>@else<a href="{{ $pageUrl($p) }}">{{ $p }}</a>@endif
            @endfor
            @if($page < $totalPages)<a href="{{ $pageUrl($page + 1) }}">Next</a>@endif
          </div>
        </div>
      @endif
    @endif
  </div>
</div>

<dialog id="wh-zone-dialog" class="wh-dialog">
  <form method="POST" id="wh-zone-form" class="wh-dialog-body">
    {{ csrf_field() }}
    <h3>Edit location zone</h3>
    <label for="wh-zone-select">Zone</label>
    <select id="wh-zone-select" name="zone" required>
      <option value="">Pick a zone</option>
      @foreach($zones as $z)
        <option value="{{ $z }}">{{ $z }}</option>
      @endforeach
    </select>
    <p class="wh-muted" style="margin-top:10px;">This changes the zone for one item. To change several, open the order.</p>
    <div class="wh-dialog-actions">
      <button type="button" class="wh-btn" onclick="document.getElementById('wh-zone-dialog').close()">Cancel</button>
      <button type="submit" class="wh-btn wh-btn-primary">Save location</button>
    </div>
  </form>
</dialog>

<script>
  var whLocUrl = {!! json_encode(route('wa.warehouse.location', ['id' => '__O__', 'itemId' => '__I__']), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
  function whEditZone(orderId, itemId, zone) {
    var form = document.getElementById('wh-zone-form');
    form.action = whLocUrl.replace('__O__', orderId).replace('__I__', itemId);
    document.getElementById('wh-zone-select').value = zone || '';
    document.getElementById('wh-zone-dialog').showModal();
  }
</script>
@endsection
