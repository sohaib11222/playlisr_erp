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
  .esr-table { width:100%; border-collapse:collapse; font-size:13px; min-width:1120px; }
  .esr-table th, .esr-table td { padding:10px 12px; border-bottom:1px solid var(--pos-line,#ECE3CF); text-align:left; vertical-align:top; }
  .esr-table th { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#8a7c6a; font-weight:700; background:#faf6ec; }
  .esr-table td.num, .esr-table th.num { text-align:right; font-variant-numeric:tabular-nums; }
  .esr-ev-name { font-weight:700; color:#3a2f0c; }
  .esr-ev-sub { font-size:11px; color:#8a7c6a; margin-top:2px; }
  .esr-foot td { font-weight:700; color:#3a2f0c; background:#faf6ec; border-top:2px solid var(--pos-line,#ECE3CF); }
  .esr-muted { color:#b6ac97; }
  .esr-star { background:#fff6df; }
  .esr-star.num { color:#3a2f0c; }
  .esr-album-qty { font-size:17px; font-weight:800; color:#3a2f0c; }
  .esr-album-name { font-size:11px; color:#8a7c6a; margin-top:1px; max-width:180px; }
  .esr-sold { margin:0; padding:0; list-style:none; font-size:12px; color:#4a4335; }
  .esr-sold li { display:flex; justify-content:space-between; gap:12px; padding:1px 0; }
  .esr-sold li span:last-child { color:#8a7c6a; font-variant-numeric:tabular-nums; white-space:nowrap; }
  .esr-more { font-size:11px; color:#b6ac97; margin-top:2px; }
  .esr-fmt { display:flex; flex-wrap:wrap; gap:4px; }
  .esr-fmt-pill { display:inline-block; font-size:11px; font-weight:600; color:#4a4335; background:#faf6ec; border:1px solid var(--pos-line,#ECE3CF); border-radius:999px; padding:1px 8px; white-space:nowrap; }
</style>

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Listening Party Sales @if ($store) <span style="font-size:15px;color:#8a7c6a;font-weight:600;">&middot; {{ ucfirst($store) }}</span> @endif</h1>
      <p class="sub">All listening parties - upcoming first, past below: attendees, preorders placed, and what sold on the POS - split into the party's own hours vs the whole store day, with the party artist's record (the album), total party revenue, and the format mix called out.</p>
      @php
        $tabBase = 'display:inline-block;padding:7px 14px;border-radius:999px;font-size:13px;text-decoration:none;border:1px solid var(--pos-line,#ECE3CF);';
        $tabOn = 'background:var(--pos-accent,#FFE08A);color:#3a2f0c;font-weight:700;';
        $tabOff = 'color:#6b6253;';
      @endphp
      <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
        <a href="{{ route('events.salesReport') }}" style="{{ $tabBase }}{{ $store === '' ? $tabOn : $tabOff }}">Both stores</a>
        <a href="{{ route('events.salesReport', ['store' => 'hollywood']) }}" style="{{ $tabBase }}{{ $store === 'hollywood' ? $tabOn : $tabOff }}">Hollywood</a>
        <a href="{{ route('events.salesReport', ['store' => 'pico']) }}" style="{{ $tabBase }}{{ $store === 'pico' ? $tabOn : $tabOff }}">Pico</a>
      </div>
      <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
        <a href="{{ route('events.index') }}" style="{{ $tabBase }}{{ $tabOff }}">&larr; Events</a>
        <a href="{{ route('events.preordersOverview') }}" style="{{ $tabBase }}{{ $tabOff }}">Preorders</a>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px;align-items:stretch;">
      <a href="{{ route('events.salesReportExport', $store ? ['store' => $store] : []) }}" class="btn-accent" style="text-decoration:none;text-align:center;">Export CSV</a>
    </div>
  </div>

  @if (!$bridgeKeySet)
    <div style="margin:0 0 14px;padding:10px 14px;border:1px solid #ead9a6;background:#fff6df;border-radius:10px;font-size:13px;color:#8a6a1a;">
      The website bridge key isn't set, so attendees and preorders placed show 0. Sales still work (they read the POS directly). Set the key on the Events page to fill those in.
    </div>
  @endif

  <p style="font-size:12px;color:#8a7c6a;margin:0 0 12px;max-width:900px;">
    "Preorders placed" counts actual preorder records; "said they'd buy" underneath is the softer RSVP purchase-interest signal (guests who ticked vinyl/CD on their RSVP). "Album sold at party" is the party artist's own record(s) sold that day - matched by the artist in the party name (e.g. a Shania Twain party counts Shania Twain records, not the top-selling toy). For an Advance party the album wasn't out yet, so this shows preorders placed instead. "During party" counts all POS sales in the party's hours (start to end time, plus 1 hour grace); "that day" is the store's whole day for comparison. A dash means none sold / nothing was rung.
  </p>

  <div style="overflow-x:auto;border:1px solid var(--pos-line,#ECE3CF);border-radius:12px;background:#fff;">
    <table class="esr-table">
      <thead>
        <tr>
          <th>Party</th>
          <th class="num">Attendees</th>
          <th class="num">Preorders placed</th>
          <th class="num esr-star">Album sold at party</th>
          <th class="num">Records sold (party)</th>
          <th class="num">Total revenue (party)</th>
          <th>Formats sold (party)</th>
          <th class="num">Sold that day (store)</th>
          <th>Top records during party</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($rows as $r)
          <tr>
            <td>
              <div class="esr-ev-name">{{ $r['name'] }}
                @if ($r['isUpcoming'])<span style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#1F8B3F;background:#e7f6ec;border:1px solid #bfe6cd;border-radius:999px;padding:1px 6px;margin-left:6px;vertical-align:middle;">Upcoming</span>@endif
                @if ($r['isAdvance'])<span style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#3a2f0c;background:var(--pos-accent,#FFE08A);border-radius:999px;padding:1px 6px;margin-left:6px;vertical-align:middle;">Advance</span>@endif
              </div>
              <div class="esr-ev-sub">
                {{ $fmtDate($r['date']) }}
                @if (!empty($r['stores'])) &middot; {{ implode(' + ', $r['stores']) }} @endif
                @unless ($r['hasWindow']) &middot; <span title="No start time set - using the whole day">no time set</span> @endunless
              </div>
            </td>
            <td class="num">{{ $r['attendees'] ?: '-' }}</td>
            <td class="num">
              @if ($r['preordersPlaced'] > 0)
                {{ $r['preordersPlaced'] }}
                @if ($r['interest']) <div class="esr-ev-sub">{{ $r['interest'] }} said they'd buy</div> @endif
              @elseif ($r['interest'])
                <span class="esr-muted">0</span>
                <div class="esr-ev-sub">{{ $r['interest'] }} said they'd buy</div>
              @else
                <span class="esr-muted">-</span>
              @endif
            </td>
            <td class="num esr-star">
              @if ($r['isAdvance'])
                <div class="esr-album-qty">{{ $r['preordersPlaced'] }}</div>
                <div class="esr-album-name">preordered (advance)</div>
                @if ($r['albumUnits'] > 0)
                  <div class="esr-ev-sub">{{ $qty($r['albumUnits']) }} sold day-of</div>
                @endif
              @elseif ($r['albumUnits'] > 0)
                <div class="esr-album-qty">{{ $qty($r['albumUnits']) }}</div>
                <div class="esr-album-name">{{ $r['albumName'] }}</div>
                <div class="esr-ev-sub">{{ $money($r['albumRevenue']) }}@if ($r['albumTitleCount'] > 1) &middot; {{ $r['albumTitleCount'] }} titles @endif</div>
              @else
                <span class="esr-muted">-</span>
              @endif
            </td>
            <td class="num">
              @if ($r['partyUnits'] > 0)
                {{ $qty($r['partyUnits']) }}
              @else
                <span class="esr-muted">-</span>
              @endif
            </td>
            <td class="num">
              @if ($r['partyRevenue'] > 0)
                <strong>{{ $money($r['partyRevenue']) }}</strong>
              @else
                <span class="esr-muted">-</span>
              @endif
            </td>
            <td>
              @if (!empty($r['formats']))
                <div class="esr-fmt">
                  @foreach ($r['formats'] as $f => $u)
                    <span class="esr-fmt-pill">{{ $f }} {{ $qty($u) }}</span>
                  @endforeach
                </div>
              @else
                <span class="esr-muted">-</span>
              @endif
            </td>
            <td class="num">
              @if ($r['dayUnits'] > 0)
                {{ $qty($r['dayUnits']) }}
                <div class="esr-ev-sub">{{ $money($r['dayRevenue']) }}</div>
              @else
                <span class="esr-muted">-</span>
              @endif
            </td>
            <td>
              @if (!empty($r['topSellers']))
                <ul class="esr-sold">
                  @foreach ($r['topSellers'] as $p)
                    <li><span>{{ $p['name'] }}</span><span>{{ $qty($p['units']) }} &middot; {{ $money($p['revenue']) }}</span></li>
                  @endforeach
                </ul>
                @if ($r['productCount'] > count($r['topSellers']))
                  <div class="esr-more">+ {{ $r['productCount'] - count($r['topSellers']) }} more titles</div>
                @endif
              @else
                <span class="esr-muted">-</span>
              @endif
            </td>
          </tr>
        @empty
          <tr><td colspan="9" style="text-align:center;color:#8a7c6a;padding:26px;">No listening parties yet.</td></tr>
        @endforelse
      </tbody>
      @if (count($rows))
        <tfoot>
          <tr class="esr-foot">
            <td>Totals ({{ count($rows) }} parties)</td>
            <td class="num">{{ $totals['attendees'] }}</td>
            <td class="num">{{ $totals['preorders'] }}</td>
            <td class="num esr-star">{{ $qty($totals['albumUnits']) }}</td>
            <td class="num">{{ $qty($totals['partyUnits']) }}</td>
            <td class="num">{{ $money($totals['partyRevenue']) }}</td>
            <td></td>
            <td class="num">{{ $qty($totals['dayUnits']) }}<div class="esr-ev-sub">{{ $money($totals['dayRevenue']) }}</div></td>
            <td></td>
          </tr>
        </tfoot>
      @endif
    </table>
  </div>
</div>
@endsection
