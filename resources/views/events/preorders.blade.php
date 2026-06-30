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
      <p class="sub">Everyone who reserved a record — which party they placed it at, when, the pickup (street) date, and whether they've paid.</p>
      <p class="sub"><a class="ev-edit" href="{{ route('events.index') }}">&larr; All events</a></p>
    </div>
    <div style="text-align:right;flex:0 1 auto;">
      {{-- Active = still to be picked up. All = include picked up + canceled. --}}
      <a class="{{ $showAll ? 'btn-ghost' : 'btn-accent' }}" href="{{ route('events.preordersOverview') }}" style="text-decoration:none;">Active</a>
      <a class="{{ $showAll ? 'btn-accent' : 'btn-ghost' }}" href="{{ route('events.preordersOverview', ['status' => 'all']) }}" style="text-decoration:none;">All</a>
    </div>
  </div>

  @if(!$keySet)
    <div class="ev-card" style="border:1px solid var(--pos-accent,#FFE08A);">
      <h2 style="margin-top:0;">Connect the bridge to load preorders</h2>
      <p class="sub" style="margin:0 0 10px;">Preorder records live on nivessa.com. Set the <code>ERP_API_KEY</code> from any event's edit page to pull them in here.</p>
      <a class="btn-accent" href="{{ route('events.index') }}">Go to events</a>
    </div>
  @elseif(!$reachable)
    <div class="ev-card" style="border:1px solid #f0c2c2;">
      <h2 style="margin-top:0;">Couldn't reach the website</h2>
      <p class="sub" style="margin:0;">A key is set, but nivessa.com rejected it or was unreachable. Try again, or re-check the key from an event's edit page.</p>
    </div>
  @elseif(empty($preorders))
    <div class="ev-card"><div class="empty">{{ $showAll ? 'No preorders yet.' : 'No active preorders — everything has been picked up or canceled.' }}</div></div>
  @else
    @php
      // Soonest pickup is on top. Count what's still owed so Sarah sees the
      // money outstanding at a glance.
      $unpaidCount = 0; $unpaidTotal = 0.0;
      foreach ($preorders as $p) {
        $st = $p['status'] ?? 'pending';
        if (empty($p['paid']) && in_array($st, ['pending', 'ready'], true)) {
          $unpaidCount++;
          $unpaidTotal += (float) ($p['preorderPrice'] ?? 0);
        }
      }
    @endphp
    <div class="ev-card">
      <div class="total-owed" style="margin-bottom:12px;">
        {{ count($preorders) }} {{ $showAll ? 'total' : 'active' }}
        @if($unpaidCount > 0)
          <span class="ev-meta">&middot; {{ $unpaidCount }} unpaid{{ $unpaidTotal > 0 ? ' (' . '$' . number_format($unpaidTotal, 2) . ' to collect at pickup)' : '' }}</span>
        @endif
      </div>
      <table class="ev-tbl">
        <thead><tr>
          <th>Customer</th>
          <th>Item</th>
          <th>Event</th>
          <th>Placed</th>
          <th>Pickup</th>
          <th>Paid</th>
          <th>Status</th>
        </tr></thead>
        <tbody>
          @foreach($preorders as $p)
            @php
              $name = trim(($p['firstName'] ?? '') . ' ' . ($p['lastName'] ?? '')) ?: '—';
              $email = (string) ($p['email'] ?? '');
              if (strpos($email, '@noemail.nivessa.com') !== false) { $email = ''; }
              $status = $p['status'] ?? 'pending';
              $statusLabel = str_replace('_', ' ', $status);
              $paid = !empty($p['paid']);
              $placed = !empty($p['createdAt']) ? date('M j, Y', strtotime($p['createdAt'])) : '—';
              $pickup = !empty($p['_pickup']) ? date('l, M j, Y', strtotime($p['_pickup'])) : '—';
              $eid = $p['_eventKnown'] ?? null;
            @endphp
            <tr>
              <td class="ev-name">{{ $name }}
                <div class="ev-meta">{{ $email }}@if(!empty($p['phone'])) @if($email) · @endif{{ $p['phone'] }}@endif</div>
              </td>
              <td>{{ $p['preorderTitle'] ?? '—' }}@if(isset($p['preorderPrice']) && $p['preorderPrice'] !== null) <span class="ev-meta">${{ number_format((float) $p['preorderPrice'], 2) }}</span>@endif</td>
              <td>
                @if($eid)
                  <a class="ev-edit" href="{{ route('events.edit', ['id' => $eid]) }}">{{ $p['eventName'] ?? '—' }}</a>
                @else
                  {{ $p['eventName'] ?? '—' }}
                @endif
              </td>
              <td class="ev-meta">{{ $placed }}</td>
              <td>{{ $pickup }}</td>
              <td>
                @if($paid)
                  <span class="pill" style="background:#e6f4ea;color:#2e7d32;border-color:#cce8d4;">Paid</span>
                @else
                  <span class="pill" style="background:#fdeaea;color:#a23;border-color:#f3cccc;">Unpaid</span>
                @endif
              </td>
              <td><span class="pill">{{ $statusLabel }}</span></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
</div>
@endsection
