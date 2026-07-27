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
  .esr-table { width:100%; border-collapse:collapse; font-size:13px; }
  .esr-table th, .esr-table td { padding:10px 12px; border-bottom:1px solid var(--pos-line,#ECE3CF); text-align:left; vertical-align:top; }
  .esr-table th { font-size:11px; text-transform:uppercase; letter-spacing:.04em; color:#8a7c6a; font-weight:700; background:#faf6ec; }
  .esr-table td.num, .esr-table th.num { text-align:right; font-variant-numeric:tabular-nums; }
  .esr-ev-name { font-weight:700; color:#3a2f0c; }
  .esr-ev-sub { font-size:11px; color:#8a7c6a; margin-top:2px; }
  .esr-foot td { font-weight:700; color:#3a2f0c; background:#faf6ec; border-top:2px solid var(--pos-line,#ECE3CF); }
  .esr-muted { color:#b6ac97; }
  .esr-sold { margin:0; padding:0; list-style:none; font-size:12px; color:#4a4335; }
  .esr-sold li { display:flex; justify-content:space-between; gap:12px; padding:1px 0; }
  .esr-sold li span:last-child { color:#8a7c6a; font-variant-numeric:tabular-nums; white-space:nowrap; }
  .esr-more { font-size:11px; color:#b6ac97; margin-top:2px; }
</style>

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Listening Party Sales</h1>
      <p class="sub">Every listening party (most recent first) with attendees, preorder interest, and what actually sold on the POS at the party's store on the party date. Sales read straight from the register; attendees and preorder interest come from nivessa.com.</p>
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
      The website bridge key isn't set, so attendee and preorder-interest columns show 0. Sales still work (they read the POS directly). Set the key on the Events page to fill those in.
    </div>
  @endif

  <p style="font-size:12px;color:#8a7c6a;margin:0 0 12px;max-width:860px;">
    "Sold" counts every record rung up on the POS at the party's store(s) on the party date - the store is open to walk-ins too, so this is the store's sales that day, not only party guests. A dash means nothing was rung at that store that day (e.g. an upcoming party).
  </p>

  <div style="overflow-x:auto;border:1px solid var(--pos-line,#ECE3CF);border-radius:12px;background:#fff;">
    <table class="esr-table">
      <thead>
        <tr>
          <th>Party</th>
          <th class="num">Attendees</th>
          <th class="num">Preorder interest</th>
          <th class="num">Records sold</th>
          <th class="num">Sales revenue</th>
          <th>What sold (top records that day)</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($rows as $r)
          <tr>
            <td>
              <div class="esr-ev-name">{{ $r['name'] }}</div>
              <div class="esr-ev-sub">
                {{ $fmtDate($r['date']) }}
                @if (!empty($r['stores'])) &middot; {{ implode(' + ', $r['stores']) }} @endif
              </div>
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
            <td class="num">{{ $r['units'] > 0 ? $qty($r['units']) : '' }}@if ($r['units'] <= 0)<span class="esr-muted">-</span>@endif</td>
            <td class="num">{{ $r['units'] > 0 ? $money($r['revenue']) : '' }}@if ($r['units'] <= 0)<span class="esr-muted">-</span>@endif</td>
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
          <tr><td colspan="6" style="text-align:center;color:#8a7c6a;padding:26px;">No listening parties yet.</td></tr>
        @endforelse
      </tbody>
      @if (count($rows))
        <tfoot>
          <tr class="esr-foot">
            <td>Totals ({{ count($rows) }} parties)</td>
            <td class="num">{{ $totals['attendees'] }}</td>
            <td class="num">{{ $totals['interest'] }}</td>
            <td class="num">{{ $qty($totals['units']) }}</td>
            <td class="num">{{ $money($totals['revenue']) }}</td>
            <td></td>
          </tr>
        </tfoot>
      @endif
    </table>
  </div>
</div>
@endsection
