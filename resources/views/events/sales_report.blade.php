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
  // "HW 3 · Pico 2" from a ['hollywood'=>x,'pico'=>y] map (only stores > 0).
  $storeSplit = function ($map) use ($qty) {
      $lbl = ['hollywood' => 'HW', 'pico' => 'Pico'];
      $parts = [];
      foreach ($map as $k => $v) { $parts[] = ($lbl[$k] ?? ucfirst($k)) . ' ' . $qty($v); }
      return implode(' · ', $parts);
  };
@endphp

<style>
  .esr-table { width:100%; border-collapse:collapse; font-size:13px; min-width:1400px; }
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
  .esr-section { background:#f2ead6; color:#6b6253; font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; padding:6px 12px; }
</style>

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Listening Party Sales @if ($archive) <span style="font-size:15px;color:#8a7c6a;font-weight:600;">&middot; Archive</span> @endif @if ($store) <span style="font-size:15px;color:#8a7c6a;font-weight:600;">&middot; {{ ucfirst($store) }}</span> @endif</h1>
      <p class="sub">{{ $archive ? 'Archived listening parties (older than 3 months).' : 'Listening parties - upcoming (that are live) first, then the last 3 months.' }} Shows attendees, preorders, and what sold on the POS, plus the album's 7-day and 14-day sell-through. Older parties live under Archive.</p>
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
        <a href="{{ route('events.preordersOverview') }}" style="{{ $tabBase }}{{ $tabOff }}">Preorders</a>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px;align-items:stretch;">
      <a href="{{ route('events.salesReportExport', array_merge($store ? ['store' => $store] : [], $arch)) }}" class="btn-accent" style="text-decoration:none;text-align:center;">Export CSV</a>
    </div>
  </div>

  @if (!$bridgeKeySet)
    <div style="margin:0 0 14px;padding:10px 14px;border:1px solid #ead9a6;background:#fff6df;border-radius:10px;font-size:13px;color:#8a6a1a;">
      The website bridge key isn't set, so attendees and preorders placed show 0. Sales still work (they read the POS directly). Set the key on the Events page to fill those in.
    </div>
  @endif

  <p style="font-size:12px;color:#8a7c6a;margin:0 0 12px;max-width:900px;">
    "Ordered" is the stock we ordered in for the party (from the event's order matrix) - compare it against records sold. Where no matrix was entered, it's estimated from purchase orders of the artist's records in the 30 days before the party (marked "est. from POs"). "Preorders placed" counts actual preorder records; "said they'd buy" underneath is the softer RSVP purchase-interest signal (guests who ticked vinyl/CD on their RSVP). "Album sold at party" is the party artist's own record(s) sold that day - matched by the artist in the party name (e.g. a Shania Twain party counts Shania Twain records, not the top-selling toy). For an Advance party the album wasn't out yet, so this shows preorders placed instead. "During party" counts all POS sales in the party's hours (start to end time, plus 1 hour grace); "that day" is the store's whole day for comparison. A dash means none sold / nothing was rung.
  </p>

  <div style="overflow-x:auto;border:1px solid var(--pos-line,#ECE3CF);border-radius:12px;background:#fff;">
    <table class="esr-table">
      <thead>
        <tr>
          <th>Party</th>
          <th class="num">Attendees</th>
          <th class="num">Preorders placed</th>
          <th class="num esr-star">Album sold at party</th>
          <th class="num">Album +7 days</th>
          <th class="num">Album +14 days</th>
          <th>Ordered</th>
          <th class="num">Records sold (party)</th>
          <th class="num">Total revenue (party)</th>
          <th>Formats sold (party)</th>
          <th class="num">Sold that day (store)</th>
          <th>Top records during party</th>
        </tr>
      </thead>
      <tbody>
        @php $seenUpcoming = false; $seenPast = false; @endphp
        @forelse ($rows as $r)
          @if ($r['isUpcoming'] && !$seenUpcoming)
            @php $seenUpcoming = true; @endphp
            <tr><td colspan="12" class="esr-section">Upcoming</td></tr>
          @elseif (!$r['isUpcoming'] && !$seenPast)
            @php $seenPast = true; @endphp
            <tr><td colspan="12" class="esr-section">Past</td></tr>
          @endif
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
              @elseif ($r['isNewRelease'])
                <div class="esr-ev-sub" style="text-align:right;">No preorders taken for new release listening parties</div>
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
                @if ($store === '' && !empty($r['albumByStore']))
                  <div class="esr-ev-sub">{{ $storeSplit($r['albumByStore']) }}</div>
                @endif
                <div class="esr-ev-sub">{{ $money($r['albumRevenue']) }}@if ($r['albumTitleCount'] > 1) &middot; {{ $r['albumTitleCount'] }} titles @endif</div>
                @if (!empty($r['albumFormats']))
                  <div class="esr-fmt" style="justify-content:flex-end;margin-top:3px;">
                    @foreach ($r['albumFormats'] as $f => $u)<span class="esr-fmt-pill">{{ $f }} {{ $qty($u) }}</span>@endforeach
                  </div>
                @endif
              @else
                <span class="esr-muted">-</span>
              @endif
            </td>
            <td class="num">
              @if ($r['album7'] > 0)
                {{ $qty($r['album7']) }}@unless ($r['window7Complete'])<span class="esr-more"> so far</span>@endunless
              @else
                <span class="esr-muted">-</span>
              @endif
            </td>
            <td class="num">
              @if ($r['album14'] > 0)
                {{ $qty($r['album14']) }}@unless ($r['window14Complete'])<span class="esr-more"> so far</span>@endunless
                @if ($store === '' && !empty($r['album14ByStore']))
                  <div class="esr-ev-sub">{{ $storeSplit($r['album14ByStore']) }}</div>
                @endif
              @else
                <span class="esr-muted">-</span>
              @endif
            </td>
            <td>
              @if ($r['orderedTotal'] > 0)
                <strong>{{ rtrim(rtrim(number_format($r['orderedTotal'], 2), '0'), '.') }}</strong>
                @if ($r['orderedEstimated'])<span class="esr-more" title="No order matrix was entered on this event - estimated from purchase orders of the artist's records in the 30 days before the party."> est. from POs</span>@endif
                <div class="esr-fmt" style="margin-top:2px;">
                  @foreach ($r['orderedByFmt'] as $f => $u)<span class="esr-fmt-pill">{{ $f }} {{ rtrim(rtrim(number_format($u, 2), '0'), '.') }}</span>@endforeach
                </div>
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
          <tr><td colspan="12" style="text-align:center;color:#8a7c6a;padding:26px;">{{ $archive ? 'No archived listening parties.' : 'No listening parties in the last 3 months.' }}</td></tr>
        @endforelse
      </tbody>
      @if (count($rows))
        <tfoot>
          <tr class="esr-foot">
            <td>Totals ({{ count($rows) }} parties)</td>
            <td class="num">{{ $totals['attendees'] }}</td>
            <td class="num">{{ $totals['preorders'] }}</td>
            <td class="num esr-star">{{ $qty($totals['albumUnits']) }}</td>
            <td class="num">{{ $qty($totals['album7']) }}</td>
            <td class="num">{{ $qty($totals['album14']) }}</td>
            <td>{{ $totals['ordered'] }}</td>
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
