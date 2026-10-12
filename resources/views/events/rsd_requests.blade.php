@extends('layouts.app')

@section('title', 'RSD Requests')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')

@php
  $fmtDate = function ($d) {
      if (!$d) return '-';
      try { return \Carbon\Carbon::parse($d)->setTimezone('America/Los_Angeles')->format('M j, g:i A'); } catch (\Throwable $e) { return $d; }
  };
  $totHw = array_sum(array_column($titles, 'hollywood'));
  $totPico = array_sum(array_column($titles, 'pico'));
@endphp

<style>
  .rq-section { font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:#6b6253; margin:20px 0 10px; }
  .rq-summary { display:flex; flex-wrap:wrap; gap:6px 18px; margin:10px 0 12px; font-size:13px; color:#6b6253; }
  .rq-summary b { color:#3a2f0c; font-weight:800; }
  .rq-card { border:1px solid var(--pos-line,#ECE3CF); border-radius:12px; background:#fff; padding:6px 10px; overflow-x:auto; }
  .rq-table { width:100%; border-collapse:collapse; font-size:13px; }
  .rq-table th { font-size:10px; text-transform:uppercase; letter-spacing:.04em; color:#8a7c6a; text-align:left; padding:6px 10px; border-bottom:1px solid var(--pos-line,#ECE3CF); white-space:nowrap; }
  .rq-table td { padding:7px 10px; border-bottom:1px solid #f5efe1; color:#4a4335; vertical-align:top; }
  .rq-table th.num, .rq-table td.num { text-align:right; font-variant-numeric:tabular-nums; }
  .rq-table td.title { font-weight:700; color:#3a2f0c; }
  .rq-table td.total { font-weight:800; color:#3a2f0c; background:#fffaf0; }
  .rq-muted { color:#b6ac97; }
  .rq-empty { border:1px solid var(--pos-line,#ECE3CF); border-radius:12px; background:#fff; padding:26px; text-align:center; color:#8a7c6a; }
</style>

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>RSD Requests <span style="font-size:15px;color:#8a7c6a;font-weight:600;">&middot; Black Friday 2026</span></h1>
      <p class="sub">What customers asked us to order, from the "What should we order for you?" form on <a href="https://nivessa.com/rsd-black-friday" target="_blank" rel="noopener">nivessa.com/rsd-black-friday</a>. RSD titles can't be held, so this is demand for buying, not reservations.</p>
    </div>
  </div>

  @if (!$keySet)
    <div class="rq-empty">The website bridge key (ERP_API_KEY) isn't set, so requests can't be loaded.</div>
  @elseif (!$reachable)
    <div class="rq-empty">Couldn't reach the website to load requests. Try refreshing in a minute.</div>
  @elseif (empty($titles))
    <div class="rq-empty">No requests yet.</div>
  @else
    <div class="rq-summary">
      <span><b>{{ $count }}</b> {{ $count === 1 ? 'request' : 'requests' }}</span>
      <span><b>{{ count($titles) }}</b> titles</span>
      <span>Hollywood <b>{{ $totHw }}</b></span>
      <span>Pico <b>{{ $totPico }}</b></span>
    </div>

    <div class="rq-section">By title</div>
    <div class="rq-card">
      <table class="rq-table">
        <thead>
          <tr><th>Title</th><th>Artist</th><th class="num">Hollywood</th><th class="num">Pico</th><th class="num">Total</th></tr>
        </thead>
        <tbody>
          @foreach ($titles as $t)
            <tr>
              <td class="title">{{ $t['title'] ?? '' }}</td>
              <td>{{ $t['artist'] ?? '' }}</td>
              <td class="num {{ empty($t['hollywood']) ? 'rq-muted' : '' }}">{{ (int) ($t['hollywood'] ?? 0) }}</td>
              <td class="num {{ empty($t['pico']) ? 'rq-muted' : '' }}">{{ (int) ($t['pico'] ?? 0) }}</td>
              <td class="num total">{{ (int) ($t['total'] ?? 0) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    <div class="rq-section">All requests (newest first)</div>
    <div class="rq-card">
      <table class="rq-table">
        <thead>
          <tr><th>Date</th><th>Name</th><th>Store</th><th>Titles</th></tr>
        </thead>
        <tbody>
          @foreach ($requests as $r)
            <tr>
              <td style="white-space:nowrap;">{{ $fmtDate($r['createdAt'] ?? null) }}</td>
              <td>{{ $r['name'] ?? '' }}</td>
              <td>{{ ($r['store'] ?? '') === 'hollywood' ? 'Hollywood' : 'Pico' }}</td>
              <td>
                @foreach (($r['items'] ?? []) as $it)
                  <div>{{ $it['artist'] ?? '' }} - {{ $it['title'] ?? '' }}@if (!empty($it['format'])) <span class="rq-muted">({{ $it['format'] }})</span>@endif</div>
                @endforeach
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif
</div>
@endsection
