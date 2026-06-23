@extends('layouts.app')

@section('title', 'Events / Listening Parties')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')

<div class="ev-wrap">
  <div class="ev-head">
    <div>
      <h1>{{ $filterType === 'listening_party' ? 'Listening Parties' : (($filterType ?? null) ? $filterLabel . 's' : 'All events') }}</h1>
      <p class="sub">The ERP is the source of truth for all event detail and listening-party prep. nivessa.com reads from here.</p>
      @php $tabBase = 'display:inline-block;padding:7px 14px;border-radius:999px;font-size:13px;text-decoration:none;border:1px solid var(--pos-line,#ECE3CF);'; $tabOn = 'background:var(--pos-accent,#FFE08A);color:#3a2f0c;font-weight:700;'; $tabOff = 'color:#6b6253;'; @endphp
      <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
        <a href="{{ route('events.index') }}" style="{{ $tabBase }}{{ $filterType === 'listening_party' ? $tabOn : $tabOff }}">Listening parties</a>
        <a href="{{ route('events.index', ['type' => 'all']) }}" style="{{ $tabBase }}{{ $filterType === null ? $tabOn : $tabOff }}">All events</a>
      </div>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <form method="POST" action="{{ route('events.seedOrders') }}"
            onsubmit="return confirm('Load what we ordered (from the 6/23 Hollywood + Pico sheets) into Madonna, Gracie, Heated Rivalry and Jack White? Overwrites their ordered fields. Undoable from Admin Action History.');">
        {{ csrf_field() }}
        <button type="submit" class="btn-ghost">Load 6/23 orders</button>
      </form>
      <form method="POST" action="{{ route('events.import') }}"
            onsubmit="return confirm('Pull the latest events from nivessa.com into the ERP? Existing prep-checklist progress entered here is preserved.');">
        {{ csrf_field() }}
        <button type="submit" class="btn-ghost">Import from nivessa.com</button>
      </form>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  {{-- ---------- What to order (shortfalls across upcoming events) ---------- --}}
  @if(!empty($toOrder))
    <div class="ev-card" style="border:1px solid var(--pos-accent,#FFE08A);">
      <h2 style="margin-top:0;">What to order</h2>
      <p class="sub" style="margin:0 0 10px;">Gaps between customer demand (RSVP buy-interest) and what you've ordered, per store. Non-hosting stores show a stock-a-couple baseline.</p>
      <table class="ev-tbl">
        <thead><tr><th style="width:45%;">Event</th><th style="width:20%;">Store</th><th style="width:35%;">Order</th></tr></thead>
        <tbody>
          @foreach($toOrder as $t)
            <tr>
              <td class="ev-name">{{ $t['event'] }}</td>
              <td>{{ $t['store'] }}</td>
              <td style="color:#a23;font-weight:700;">{{ $t['need'] }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif

  {{-- ---------- Website bridge (only shown when NOT connected) ---------- --}}
  @php $bstate = ($bridgeProbe['state'] ?? 'no_key'); $bcode = $bridgeProbe['code'] ?? null; @endphp
  @if($bstate !== 'connected')
  <div class="ev-card">
    <h2 style="margin-top:0;">Website bridge
      @if($bstate === 'connected')
        <span class="prep-badge done" style="vertical-align:middle;">Connected</span>
      @else
        <span class="prep-badge todo" style="vertical-align:middle;">Not connected</span>
      @endif
    </h2>
    @if($bstate === 'connected')
      <p class="sub" style="margin:0 0 4px;">RSVPs, check-in, the giveaway spin, and preorders load inside the ERP.</p>
    @elseif($bstate === 'rejected')
      <p class="sub" style="margin:0 0 4px;color:#a23;">A key is set, but the website rejected it (HTTP {{ $bcode }}). It must match the website's <code>ERP_API_KEY</code> exactly.</p>
    @elseif($bstate === 'unreachable')
      <p class="sub" style="margin:0 0 4px;color:#a23;">A key is set, but the website was unreachable{{ $bcode ? ' (HTTP '.$bcode.')' : '' }}. Check the <code>NIVESSA_API</code> URL.</p>
    @else
      <p class="sub" style="margin:0 0 4px;">Paste the same <code>ERP_API_KEY</code> used on nivessa.com to load RSVPs and preorders here. (Adding it to the box <code>.env</code> works too, but this doesn't need server access.)</p>
    @endif
    <details {{ $bstate === 'connected' ? '' : 'open' }} style="margin-top:8px;">
      <summary class="ev-create-summary">{{ $bstate === 'connected' ? 'Replace key' : 'Set bridge key' }}</summary>
      <form method="POST" action="{{ route('events.bridgeKey') }}" style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
        {{ csrf_field() }}
        <div class="ev-field" style="flex:2 1 320px;">
          <label>ERP_API_KEY (same value as the website)</label>
          <input type="password" name="erp_api_key" autocomplete="off" placeholder="paste key">
        </div>
        <button type="submit" class="btn-accent">Save &amp; test</button>
      </form>
      <p class="sub" style="margin:8px 4px 0;">Stored on the ERP server only (not in git). Save with the field blank to clear it.</p>
    </details>
  </div>
  @endif

  {{-- ---------- Create ---------- --}}
  <details class="ev-card" id="create-block">
    <summary class="ev-create-summary">+ New event</summary>
    <form method="POST" action="{{ route('events.store') }}" style="margin-top:14px;">
      {{ csrf_field() }}
      @include('events.partials._form', ['event' => null, 'eventTypes' => $eventTypes, 'genres' => $genres])
      <div style="margin-top:14px;">
        <button type="submit" class="btn-accent">Create event</button>
      </div>
    </form>
  </details>

  {{-- ---------- Upcoming ---------- --}}
  <div class="ev-card">
    <h2>Upcoming ({{ count($upcoming) }})</h2>
    @if(empty($upcoming))
      <div class="empty">No upcoming events. Add one above or import from the website.</div>
    @else
      @include('events.partials._list', ['rows' => $upcoming, 'prepItems' => $prepItems, 'eventTypes' => $eventTypes, 'rsvpCounts' => $rsvpCounts, 'vinylCounts' => $vinylCounts, 'cdCounts' => $cdCounts, 'storeCounts' => $storeCounts, 'publishedMap' => $publishedMap])
    @endif
  </div>

  {{-- ---------- Past ---------- --}}
  <details class="ev-card">
    <summary class="ev-create-summary">Past events ({{ count($past) }})</summary>
    <div style="margin-top:12px;">
      @if(empty($past))
        <div class="empty">No past events.</div>
      @else
        @include('events.partials._list', ['rows' => $past, 'prepItems' => $prepItems, 'eventTypes' => $eventTypes, 'rsvpCounts' => $rsvpCounts, 'vinylCounts' => $vinylCounts, 'cdCounts' => $cdCounts, 'storeCounts' => $storeCounts, 'publishedMap' => $publishedMap])
      @endif
    </div>
  </details>
</div>
@endsection
