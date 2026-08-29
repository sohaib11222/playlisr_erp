@extends('layouts.app')

@section('title', 'Listening Party Sales')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')

@php
  $fmtDate = function ($d) {
      if (!$d) return '-';
      try { return \Carbon\Carbon::parse($d)->format('M j, Y'); } catch (\Throwable $e) { return $d; }
  };
  $money = fn($n) => '$' . number_format((float) $n, 2);
  $qty = fn($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
@endphp

<style>
  .lp-note { font-size:12px; color:#8a7c6a; margin:0 0 14px; max-width:900px; }
  .lp-section { font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:#6b6253; margin:20px 0 10px; }
  .lp-card { border:1px solid var(--pos-line,#ECE3CF); border-radius:12px; background:#fff; padding:14px 16px; margin-bottom:12px; }
  .lp-title { font-size:16px; font-weight:800; color:#3a2f0c; }
  .lp-meta { font-size:12px; color:#8a7c6a; margin-top:2px; }
  .lp-tag { display:inline-block; font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; border-radius:999px; padding:1px 7px; margin-left:6px; vertical-align:middle; }
  .lp-tag-up { color:#1F8B3F; background:#e7f6ec; border:1px solid #bfe6cd; }
  .lp-tag-adv { color:#3a2f0c; background:var(--pos-accent,#FFE08A); }
  .lp-tag-new { color:#7a5b12; background:#fff6df; border:1px solid #ead9a6; }
  .lp-summary { display:flex; flex-wrap:wrap; gap:6px 18px; margin:10px 0 12px; font-size:13px; color:#6b6253; }
  .lp-summary b { color:#3a2f0c; font-weight:800; }
  .lp-stores { width:100%; border-collapse:collapse; font-size:13px; }
  .lp-stores th { font-size:10px; text-transform:uppercase; letter-spacing:.04em; color:#8a7c6a; text-align:right; padding:6px 10px; border-bottom:1px solid var(--pos-line,#ECE3CF); white-space:nowrap; }
  .lp-stores th:first-child { text-align:left; }
  .lp-stores td { padding:7px 10px; text-align:right; font-variant-numeric:tabular-nums; border-bottom:1px solid #f5efe1; color:#4a4335; }
  .lp-stores td:first-child { text-align:left; font-weight:700; color:#3a2f0c; }
  .lp-stores td.album { font-weight:800; color:#3a2f0c; background:#fffaf0; }
  .lp-total td { font-weight:800; background:#faf6ec; border-top:2px solid var(--pos-line,#ECE3CF); }
  .lp-muted { color:#b6ac97; }
  .lp-empty { border:1px solid var(--pos-line,#ECE3CF); border-radius:12px; background:#fff; padding:26px; text-align:center; color:#8a7c6a; }
</style>

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Listening Party Sales @if ($archive) <span style="font-size:15px;color:#8a7c6a;font-weight:600;">&middot; Archive</span> @endif @if ($store) <span style="font-size:15px;color:#8a7c6a;font-weight:600;">&middot; {{ ucfirst($store) }}</span> @endif</h1>
      <p class="sub">{{ $archive ? 'Archived listening parties (older than 3 months).' : 'Listening parties - upcoming (live) first, then the last 3 months.' }} Each party shows how Hollywood and Pico each did. Older parties live under Archive.</p>
      @php
        $tabBase = 'display:inline-block;padding:7px 14px;border-radius:999px;font-size:13px;text-decoration:none;border:1px solid var(--pos-line,#ECE3CF);';
        $tabOn = 'background:var(--pos-accent,#FFE08A);color:#3a2f0c;font-weight:700;';
        $tabOff = 'color:#6b6253;';
        $arch = $archive ? ['archive' => 1] : [];
        $storeParam = fn($s) => array_merge($s ? ['store' => $s] : [], $arch);
      @endphp
      <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
        <a href="{{ route('events.salesReport', $storeParam('')) }}" style="{{ $tabBase }}{{ $store === '' ? $tabOn : $tabOff }}">Both stores</a>
        <a href="{{ route('events.salesReport', $storeParam('hollywood')) }}" style="{{ $tabBase }}{{ $store === 'hollywood' ? $tabOn : $tabOff }}">Hollywood</a>
        <a href="{{ route('events.salesReport', $storeParam('pico')) }}" style="{{ $tabBase }}{{ $store === 'pico' ? $tabOn : $tabOff }}">Pico</a>
      </div>
      <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
        <a href="{{ route('events.salesReport', $store ? ['store' => $store] : []) }}" style="{{ $tabBase }}{{ !$archive ? $tabOn : $tabOff }}">Active (last 3 months)</a>
        <a href="{{ route('events.salesReport', array_merge($store ? ['store' => $store] : [], ['archive' => 1])) }}" style="{{ $tabBase }}{{ $archive ? $tabOn : $tabOff }}">Archive</a>
        <a href="{{ route('events.index') }}" style="{{ $tabBase }}{{ $tabOff }}">&larr; Events</a>
        <a href="{{ action('CustomerPickupController@index') }}#preorders" style="{{ $tabBase }}{{ $tabOff }}">Preorders</a>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px;align-items:stretch;">
      <a href="{{ route('events.salesReportExport', array_merge($store ? ['store' => $store] : [], $arch)) }}" class="btn-accent" style="text-decoration:none;text-align:center;">Export CSV</a>
    </div>
  </div>

  @if (!$bridgeKeySet)
    <div style="margin:0 0 14px;padding:10px 14px;border:1px solid #ead9a6;background:#fff6df;border-radius:10px;font-size:13px;color:#8a6a1a;">
      The website bridge key isn't set, so attendees and preorders show 0. Sales still work (they read the POS directly). Set the key on the Events page to fill those in.
    </div>
  @endif

  <p class="lp-note">
    "Album sold" is the party's own record (matched by artist/title). "+14 days" is how much of it sold within two weeks (did it all move). "Ordered" is what we brought in (from the order matrix, or estimated from purchase orders). "Records" and "Revenue (party)" cover the party's hours. "Revenue (day)" is the store's whole day of sales, not just the party window. Each row is a store.
  </p>

  @php $seenUpcoming = false; $seenPast = false; @endphp
  @forelse ($rows as $r)
    @if ($r['isUpcoming'] && !$seenUpcoming)
      @php $seenUpcoming = true; @endphp
      <div class="lp-section">Upcoming</div>
    @elseif (!$r['isUpcoming'] && !$seenPast)
      @php $seenPast = true; @endphp
      <div class="lp-section">Past</div>
    @endif
    <div class="lp-card">
      <div class="lp-title">
        {{ $r['name'] }}
        @if ($r['isUpcoming'])<span class="lp-tag lp-tag-up">Upcoming</span>@endif
        @if ($r['isAdvance'])<span class="lp-tag lp-tag-adv">Advance</span>@endif
        @if ($r['isNewRelease'])<span class="lp-tag lp-tag-new">New release</span>@endif
      </div>
      <div class="lp-meta">
        {{ $fmtDate($r['date']) }}@if (!empty($r['stores'])) &middot; {{ implode(' + ', $r['stores']) }} @endif
      </div>

      <div class="lp-summary">
        <span>Attendees <b>{{ $r['attendees'] ?: 0 }}</b></span>
        @if ($r['isAdvance'])
          <span>Preorders <b>{{ $r['preordersPlaced'] }}</b> (advance)</span>
        @elseif ($r['preordersPlaced'] > 0)
          <span>Preorders <b>{{ $r['preordersPlaced'] }}</b></span>
        @elseif ($r['isNewRelease'])
          <span>No preorders (new release sold at party)</span>
        @elseif ($r['interest'])
          <span>Preorders <b>0</b> ({{ $r['interest'] }} said they'd buy)</span>
        @endif
        @if ($r['albumName'])<span>Release: <b>{{ $r['albumName'] }}</b></span>@endif
        @if ($r['album14'] > 0)<span>Sold in 14 days <b>{{ $qty($r['album14']) }}</b>@unless ($r['window14Complete']) so far @endunless</span>@endif
      </div>

      @if (!empty($r['storeBreakdown']))
        <table class="lp-stores">
          <thead>
            <tr>
              <th>Store</th>
              <th>Attendees</th>
              <th>Album sold</th>
              <th>+14 days</th>
              <th>Ordered</th>
              <th>Records (party)</th>
              <th>Revenue (party)</th>
              <th>Revenue (day)</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($r['storeBreakdown'] as $s)
              <tr>
                <td>{{ $s['label'] }}</td>
                <td>{{ $s['attendees'] ?: '-' }}</td>
                <td class="album">{{ $s['album'] > 0 ? $qty($s['album']) : '-' }}</td>
                <td>{{ $s['album14'] > 0 ? $qty($s['album14']) : '-' }}</td>
                <td>{{ $s['ordered'] > 0 ? $s['ordered'] : '-' }}</td>
                <td>{{ $s['records'] > 0 ? $qty($s['records']) : '-' }}</td>
                <td>{{ $s['revenue'] > 0 ? $money($s['revenue']) : '-' }}</td>
                <td>{{ ($s['dayRevenue'] ?? 0) > 0 ? $money($s['dayRevenue']) : '-' }}</td>
              </tr>
            @endforeach
            @if (count($r['storeBreakdown']) > 1)
              <tr class="lp-total">
                <td>Total</td>
                <td>{{ $r['attendees'] ?: '-' }}</td>
                <td class="album">{{ $r['albumUnits'] > 0 ? $qty($r['albumUnits']) : '-' }}</td>
                <td>{{ $r['album14'] > 0 ? $qty($r['album14']) : '-' }}</td>
                <td>{{ $r['orderedTotal'] > 0 ? $qty($r['orderedTotal']) . ($r['orderedEstimated'] ? '*' : '') : '-' }}</td>
                <td>{{ $r['partyUnits'] > 0 ? $qty($r['partyUnits']) : '-' }}</td>
                <td>{{ $r['partyRevenue'] > 0 ? $money($r['partyRevenue']) : '-' }}</td>
                <td>{{ $r['dayRevenue'] > 0 ? $money($r['dayRevenue']) : '-' }}</td>
              </tr>
            @endif
          </tbody>
        </table>
        @if ($r['orderedEstimated'])<div class="lp-meta" style="margin-top:6px;">* Ordered estimated from purchase orders (no order matrix entered).</div>@endif
      @else
        <div class="lp-muted" style="font-size:13px;">No store sales recorded.</div>
      @endif
    </div>
  @empty
    <div class="lp-empty">{{ $archive ? 'No archived listening parties.' : 'No listening parties in the last 3 months.' }}</div>
  @endforelse
</div>
@endsection
