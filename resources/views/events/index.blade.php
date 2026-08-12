@extends('layouts.app')

@section('title', 'Events / Listening Parties')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>{{ $filterType === 'listening_party' ? 'Listening Parties' : (($filterType ?? null) ? $filterLabel . 's' : 'All events') }}</h1>
      <p class="sub">The ERP is the source of truth for all event detail and listening-party prep. nivessa.com reads from here.</p>
      @php $tabBase = 'display:inline-block;padding:7px 14px;border-radius:999px;font-size:13px;text-decoration:none;border:1px solid var(--pos-line,#ECE3CF);'; $tabOn = 'background:var(--pos-accent,#FFE08A);color:#3a2f0c;font-weight:700;'; $tabOff = 'color:#6b6253;'; @endphp
      <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
        <a href="{{ route('events.index') }}" style="{{ $tabBase }}{{ $filterType === 'listening_party' ? $tabOn : $tabOff }}">Listening parties</a>
        <a href="{{ route('events.index', ['type' => 'all']) }}" style="{{ $tabBase }}{{ $filterType === null ? $tabOn : $tabOff }}">All events</a>
        <a href="{{ route('events.preordersOverview') }}" style="{{ $tabBase }}{{ $tabOff }}">Preorders &rarr;</a>
        <a href="{{ route('events.salesReport') }}" style="{{ $tabBase }}{{ $tabOff }}">Sales report &rarr;</a>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px;align-items:stretch;">
      <form method="POST" action="{{ route('events.import') }}"
            onsubmit="return confirm('Pull the latest events from nivessa.com into the ERP? Your prep progress and preorder settings entered here are preserved.');">
        {{ csrf_field() }}
        <button type="submit" class="btn-ghost" style="width:100%;">Import from nivessa.com</button>
      </form>
      <button type="button" class="btn-accent"
              onclick="var c=document.getElementById('create-block'); var willOpen=(c.style.display==='none'); c.style.display=willOpen?'block':'none'; if(willOpen){c.scrollIntoView({behavior:'smooth',block:'start'});}">+ New event</button>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  {{-- ---------- What to order (shortfalls across upcoming events) ---------- --}}
  @if(!empty($toOrder))
    <div class="ev-card" style="border:1px solid var(--pos-accent,#FFE08A);">
      <h2 style="margin-top:0;">What to order</h2>
      <p class="sub" style="margin:0 0 10px;">Demand vs. what you've ordered at each hosting store. Type what you ordered and the tracking number, then Save.</p>
      @foreach($toOrder as $t)
        @php
          $evD = !empty($t['date']) ? date('m/d/y', strtotime($t['date'])) : '';
          $stD = !empty($t['streetDate']) ? date('m/d/y', strtotime($t['streetDate'])) : '';
        @endphp
        <form method="POST" action="{{ route('events.orderNotes', ['id' => $t['id']]) }}" class="ev-order-grp">
          {{ csrf_field() }}
          <div class="ev-order-title">{{ $t['event'] }}@if($evD || $stD)<span class="ev-order-date">{{ $evD }}@if($stD) · street {{ $stD }}@endif</span>@endif</div>
          <div class="ev-order-stores">
            @foreach($t['stores'] as $s)
              <div class="ev-order-store-grp">
                <div class="ev-order-store"><b class="ev-store-{{ $s['key'] }}">{{ $s['label'] }}</b>@if($s['need']) <span class="ev-order-need">{{ $s['need'] }}</span>@endif</div>
                <input type="text" name="note[{{ $s['key'] }}][ordered]" class="ev-order-note"
                       value="{{ $s['ordered'] }}" placeholder="What we ordered">
                <input type="text" name="note[{{ $s['key'] }}][tracking]" class="ev-order-note ev-order-track"
                       value="{{ $s['tracking'] }}" placeholder="Tracking #">
              </div>
            @endforeach
          </div>
          <div class="ev-order-actions"><button type="submit" class="btn-accent">Save</button></div>
        </form>
      @endforeach
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

  {{-- ---------- Create (opened by the "+ New event" button up top) ---------- --}}
  <div class="ev-card" id="create-block" style="display:{{ $errors->any() ? 'block' : 'none' }};">
    <h2 style="margin:0 0 6px;">+ New event</h2>
    <form method="POST" action="{{ route('events.store') }}" style="margin-top:6px;">
      {{ csrf_field() }}
      @include('events.partials._form', ['event' => null, 'eventTypes' => $eventTypes, 'genres' => $genres])
      <div style="margin-top:14px;">
        <button type="submit" class="btn-accent">Create event</button>
      </div>
    </form>
  </div>

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
