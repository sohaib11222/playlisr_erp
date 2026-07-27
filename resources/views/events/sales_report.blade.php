@extends('layouts.app')

@section('title', 'Event Sales Report')

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
@endphp

<style>
  .esr-table { width:100%; border-collapse:collapse; font-size:13px; }
  .esr-table th, .esr-table td { padding:10px 12px; border-bottom:1px solid var(--pos-line,#ECE3CF); text-align:left; vertical-align:top; }
  .esr-table th { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#8a7c6a; font-weight:700; background:#faf6ec; }
  .esr-table td.num, .esr-table th.num { text-align:right; font-variant-numeric:tabular-nums; }
  .esr-ev-name { font-weight:700; color:#3a2f0c; }
  .esr-ev-sub { font-size:11px; color:#8a7c6a; margin-top:2px; }
  .esr-tag { display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; padding:1px 7px; border-radius:999px; border:1px solid #ead9a6; background:#fff6df; color:#8a6a1a; margin-top:4px; }
  .esr-foot td { font-weight:700; color:#3a2f0c; background:#faf6ec; border-top:2px solid var(--pos-line,#ECE3CF); }
  .esr-muted { color:#b6ac97; }
</style>

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Event Sales Report</h1>
      <p class="sub">Per-event attendees, preorder interest, and day-of on-the-spot POS sales of the featured record. Sales come straight from the POS; attendees and preorder interest come from nivessa.com.</p>
      <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;">
        <a href="{{ route('events.index') }}" style="display:inline-block;padding:7px 14px;border-radius:999px;font-size:13px;text-decoration:none;border:1px solid var(--pos-line,#ECE3CF);color:#6b6253;">&larr; Events</a>
        <a href="{{ route('events.preordersOverview') }}" style="display:inline-block;padding:7px 14px;border-radius:999px;font-size:13px;text-decoration:none;border:1px solid var(--pos-line,#ECE3CF);color:#6b6253;">Preorders</a>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;gap:8px;align-items:stretch;">
      <a href="{{ route('events.salesReportExport') }}" class="btn-accent" style="text-decoration:none;text-align:center;">Export CSV</a>
    </div>
  </div>

  @if (!$bridgeKeySet)
    <div style="margin:0 0 14px;padding:10px 14px;border:1px solid #ead9a6;background:#fff6df;border-radius:10px;font-size:13px;color:#8a6a1a;">
      The website bridge key isn't set, so attendee and preorder-interest columns show 0. Day-of POS sales still work (they read the POS directly). Set the key on the Events page to fill those in.
    </div>
  @endif

  <p style="font-size:12px;color:#8a7c6a;margin:0 0 12px;max-width:820px;">
    "Day-of" columns count POS sales of the event's featured record at its store(s) on the event date. A dash means the event has no featured record set on nivessa.com, so its walk-up sales can't be attributed here - it doesn't mean zero sales.
  </p>

  <div style="overflow-x:auto;border:1px solid var(--pos-line,#ECE3CF);border-radius:12px;background:#fff;">
    <table class="esr-table">
      <thead>
        <tr>
          <th>Event</th>
          <th class="num">Attendees</th>
          <th class="num">Preorder interest</th>
          <th class="num">Day-of purchases</th>
          <th class="num">Day-of qty</th>
          <th class="num">Day-of revenue</th>
          <th>Featured record</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($rows as $r)
          <tr>
            <td>
              <div class="esr-ev-name">{{ $r['name'] }}</div>
              <div class="esr-ev-sub">
                {{ $fmtDate($r['date']) }}
                @php $tl = $eventTypes[$r['eventType']] ?? $r['eventType']; @endphp
                @if ($tl) &middot; {{ $tl }} @endif
                @if (!empty($r['stores'])) &middot; {{ implode(' + ', $r['stores']) }} @endif
              </div>
              @unless ($r['hasFeatured'])
                <span class="esr-tag">No featured record</span>
              @endunless
            </td>
            <td class="num">{{ $r['attendees'] ?: '-' }}</td>
            <td class="num">
              @if ($r['vinyl'] || $r['cd'])
                {{ $r['vinyl'] + $r['cd'] }}
                <div class="esr-ev-sub">{{ $r['vinyl'] }} vinyl &middot; {{ $r['cd'] }} CD</div>
              @else
                <span class="esr-muted">-</span>
              @endif
            </td>
            <td class="num">{{ $r['hasFeatured'] ? $r['txns'] : '' }}@unless($r['hasFeatured'])<span class="esr-muted">-</span>@endunless</td>
            <td class="num">{{ $r['hasFeatured'] ? rtrim(rtrim(number_format($r['units'], 2), '0'), '.') : '' }}@unless($r['hasFeatured'])<span class="esr-muted">-</span>@endunless</td>
            <td class="num">{{ $r['hasFeatured'] ? $money($r['revenue']) : '' }}@unless($r['hasFeatured'])<span class="esr-muted">-</span>@endunless</td>
            <td>{{ $r['featuredTitle'] ?: '-' }}</td>
          </tr>
        @empty
          <tr><td colspan="7" style="text-align:center;color:#8a7c6a;padding:26px;">No events yet.</td></tr>
        @endforelse
      </tbody>
      @if (count($rows))
        <tfoot>
          <tr class="esr-foot">
            <td>Totals ({{ count($rows) }} events)</td>
            <td class="num">{{ $totals['attendees'] }}</td>
            <td class="num">{{ $totals['interest'] }}</td>
            <td class="num">{{ $totals['txns'] }}</td>
            <td class="num">{{ rtrim(rtrim(number_format($totals['units'], 2), '0'), '.') }}</td>
            <td class="num">{{ $money($totals['revenue']) }}</td>
            <td></td>
          </tr>
        </tfoot>
      @endif
    </table>
  </div>
</div>
@endsection
