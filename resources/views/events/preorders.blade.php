@extends('layouts.app')

@section('title', 'Preorders')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head" style="display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap;">
    <div>
      <h1>Preorders</h1>
      <p class="sub">Everything a customer is waiting to pick up — listening-party reservations and in-store special orders. Shows who reserved what, where they placed it, when, the pickup date, and whether they've paid.</p>
      <p class="sub"><a class="ev-edit" href="{{ route('events.index') }}">&larr; All events</a></p>
    </div>
    <div style="text-align:right;flex:0 1 auto;">
      {{-- Active = still to be picked up. All = include picked up + canceled. --}}
      <a class="{{ $showAll ? 'btn-ghost' : 'btn-accent' }}" href="{{ route('events.preordersOverview') }}" style="text-decoration:none;">Active</a>
      <a class="{{ $showAll ? 'btn-accent' : 'btn-ghost' }}" href="{{ route('events.preordersOverview', ['status' => 'all']) }}" style="text-decoration:none;">All</a>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  @if(!$keySet)
    <div class="ev-card" style="border:1px solid var(--pos-accent,#FFE08A);">
      <h2 style="margin-top:0;">Connect the bridge to load listening-party preorders</h2>
      <p class="sub" style="margin:0 0 10px;">Listening-party preorders live on nivessa.com. Set the <code>ERP_API_KEY</code> from any event's edit page to pull them in here. (In-store special orders still show below.)</p>
      <a class="btn-accent" href="{{ route('events.index') }}">Go to events</a>
    </div>
  @elseif(!$reachable)
    <div class="ev-card" style="border:1px solid #f0c2c2;">
      <h2 style="margin-top:0;">Couldn't reach the website for listening-party preorders</h2>
      <p class="sub" style="margin:0;">A key is set, but nivessa.com rejected it or was unreachable. In-store special orders still show below; re-check the key from an event's edit page.</p>
    </div>
  @endif

  @if(empty($preorders))
    <div class="ev-card"><div class="empty">{{ $showAll ? 'No preorders yet.' : 'No active preorders — everything has been picked up or canceled.' }}</div></div>
  @else
    @php
      // Unpaid total only applies to listening-party preorders (special orders
      // don't track payment here). Soonest pickup is already on top.
      $unpaidCount = 0; $unpaidTotal = 0.0;
      foreach ($preorders as $p) {
        if (!empty($p['active']) && !empty($p['paidKnown']) && empty($p['paid'])) {
          $unpaidCount++;
          $unpaidTotal += (float) ($p['price'] ?? 0);
        }
      }
    @endphp
    <div class="ev-card">
      <div class="total-owed" style="margin-bottom:12px;">
        {{ count($preorders) }} {{ $showAll ? 'total' : 'active' }}
        @if($unpaidCount > 0)
          <span class="ev-meta">&middot; {{ $unpaidCount }} unpaid{{ $unpaidTotal > 0 ? ' ($' . number_format($unpaidTotal, 2) . ' to collect at pickup)' : '' }}</span>
        @endif
      </div>
      <table class="ev-tbl">
        <thead><tr>
          <th>Customer</th>
          <th>Item</th>
          <th>Where placed</th>
          <th>Placed</th>
          <th>Pickup</th>
          <th>Paid</th>
          <th>Status</th>
          <th></th>
        </tr></thead>
        <tbody>
          @foreach($preorders as $p)
            @php
              $placed = !empty($p['placed']) ? date('M j, Y', strtotime($p['placed'])) : '—';
              $pickup = !empty($p['pickup']) ? date('l, M j, Y', strtotime($p['pickup'])) : '—';
            @endphp
            <tr>
              <td class="ev-name">{{ $p['name'] }}
                <div class="ev-meta">{{ $p['email'] }}@if(!empty($p['phone']))@if(!empty($p['email'])) · @endif{{ $p['phone'] }}@endif</div>
              </td>
              <td>{{ $p['item'] }}@if($p['price'] !== null) <span class="ev-meta">${{ number_format((float) $p['price'], 2) }}</span>@endif</td>
              <td>
                @if($p['type'] === 'event' && !empty($p['eventId']))
                  <a class="ev-edit" href="{{ route('events.edit', ['id' => $p['eventId']]) }}">{{ $p['source'] }}</a>
                @else
                  {{ $p['source'] }}
                @endif
                <div><span class="pill lp">{{ $p['sourceTag'] }}</span></div>
              </td>
              <td class="ev-meta">{{ $placed }}</td>
              <td>{{ $pickup }}</td>
              <td>
                @if(!$p['paidKnown'])
                  <span class="ev-meta">—</span>
                @elseif($p['paid'])
                  <span class="pill" style="background:#e6f4ea;color:#2e7d32;border-color:#cce8d4;">Paid</span>
                @else
                  <span class="pill" style="background:#fdeaea;color:#a23;border-color:#f3cccc;">Unpaid</span>
                @endif
              </td>
              <td><span class="pill">{{ $p['statusLabel'] }}</span></td>
              <td style="white-space:nowrap;">
                @if(!empty($p['active']))
                  @if($p['type'] === 'event')
                    <form method="POST" action="{{ route('events.overviewEventPickup', ['preorderId' => $p['id']]) }}" style="display:inline;">
                      {{ csrf_field() }}
                      <input type="hidden" name="filter" value="{{ $showAll ? 'all' : '' }}">
                      <button type="submit" class="btn-accent" style="padding:5px 12px;font-size:12px;">Mark picked up</button>
                    </form>
                  @else
                    <form method="POST" action="{{ route('events.overviewSpecialPickup', ['id' => $p['id']]) }}" style="display:inline;">
                      {{ csrf_field() }}
                      <input type="hidden" name="filter" value="{{ $showAll ? 'all' : '' }}">
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
@endsection
