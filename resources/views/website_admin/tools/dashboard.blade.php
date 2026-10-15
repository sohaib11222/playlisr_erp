@extends('layouts.app')

@section('title', 'Website Dashboard')

@section('content')
<div class="ev-wrap ev-wrap-wide">
  @include('website_admin.tools._head', ['active' => 'wa.tools.dashboard'])

  <style>
    .wd-tile { position:relative; display:block; background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:14px; text-decoration:none; color:inherit; }
    .wd-tile:hover { border-color:#cbd5e1; box-shadow:0 4px 12px rgba(0,0,0,.06); text-decoration:none; color:inherit; }
    .wd-tile.top { background:linear-gradient(135deg, #eff6ff, #ecfeff); }
    .wd-tile .t { font-size:12px; font-weight:600; color:#475569; display:flex; justify-content:space-between; gap:6px; }
    .wd-tile .rank { font-size:10px; font-weight:700; padding:1px 7px; border-radius:999px; background:#fff; border:1px solid #e2e8f0; color:#334155; }
    .wd-tile .v { font-size:26px; font-weight:700; color:#0f172a; margin:6px 0; }
    .wd-tile .s { display:flex; justify-content:space-between; font-size:11px; color:#475569; margin-bottom:6px; }
    .wd-hbar { display:flex; align-items:center; gap:8px; font-size:12.5px; margin:6px 0; }
    .wd-hbar .n { width:170px; flex-shrink:0; color:#374151; }
    .wd-hbar .b { flex:1; height:16px; background:#f1f5f9; border-radius:6px; overflow:hidden; }
    .wd-hbar .b span { display:block; height:100%; border-radius:6px; }
    .wd-hbar .c { width:80px; text-align:right; font-weight:600; }
  </style>
  @php
    $colors = ['#2563EB', '#16A34A', '#D97706', '#DC2626', '#7C3AED', '#0891B2', '#4F46E5', '#9333EA', '#EA580C', '#0F766E', '#BE123C', '#1D4ED8', '#65A30D'];
    $featured = $ranked[0] ?? null;
    $regular = array_values(array_filter($rows, function ($r) use ($featured) { return !$featured || $r['key'] !== $featured['key']; }));
    $regular = array_slice($regular, 0, 12);
    $share = function ($v) use ($total) { return $total > 0 ? $v / $total * 100 : 0; };
    $top6 = array_slice($ranked, 0, 6);
    $maxTop = max(1, $top6[0]['value'] ?? 0);
    $maxCh = max(1, $channels[0]['value'] ?? 0);
    $maxFunnel = max(1, max(array_column($funnel, 'value')));
  @endphp

  <div class="wt-card">
    <div class="ev-head" style="margin:0;">
      <div>
        <p class="wt-note" style="margin:0; text-transform:uppercase; letter-spacing:.04em;">Website admin dashboard</p>
        <h1>Summary Overview</h1>
        <p class="sub">Total records: <strong>{{ number_format($total) }}</strong></p>
      </div>
      <form method="GET" action="{{ route('wa.tools.dashboard') }}" class="wt-row" style="margin:0;">
        <a class="wt-btn sm" href="{{ route('wa.tools.dashboard', ['days' => 7]) }}">7D</a>
        <a class="wt-btn sm" href="{{ route('wa.tools.dashboard', ['days' => 30]) }}">30D</a>
        <a class="wt-btn sm" href="{{ route('wa.tools.dashboard', ['days' => 90]) }}">90D</a>
        <input type="date" name="start" value="{{ $start }}" class="wt-input">
        <input type="date" name="end" value="{{ $end }}" class="wt-input">
        <button type="submit" class="wt-btn primary">Apply</button>
      </form>
    </div>
  </div>

  @if($error)
    <div class="alert-err">{{ $error }}</div>
  @endif

  @if($featured)
    <a href="{{ $featured['url'] }}" class="wd-tile top" style="margin-bottom:12px;" @if($featured['external']) target="_blank" rel="noopener" @endif>
      <div class="t"><span>{{ $featured['title'] }}</span><span><span class="rank">#1</span> <i class="fas {{ $featured['icon'] }}"></i></span></div>
      <div class="v" style="font-size:32px;">{{ number_format($featured['value']) }}</div>
      <div class="s"><span>Share: {{ number_format($share($featured['value']), 1) }}%</span><span class="green-t">Top performer</span></div>
      <div class="wt-bar"><span style="width:{{ max(4, min(100, $share($featured['value']))) }}%; background:#1e293b;"></span></div>
    </a>
  @endif

  <div class="wt-grid" style="grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); margin-bottom:16px;">
    @foreach($regular as $m)
      @php $s = $share($m['value']); $above = $m['value'] >= $avg; @endphp
      <a href="{{ $m['url'] }}" class="wd-tile" @if($m['external']) target="_blank" rel="noopener" @endif>
        <div class="t"><span>{{ $m['title'] }}</span><span><span class="rank">#{{ $rank[$m['key']] ?? 0 }}</span> <i class="fas {{ $m['icon'] }}"></i></span></div>
        <div class="v">{{ number_format($m['value']) }}</div>
        <div class="s"><span>Share: {{ number_format($s, 1) }}%</span><span class="{{ $above ? 'green-t' : 'amber-t' }}">{{ $above ? 'Above avg' : 'Below avg' }}</span></div>
        <div class="wt-bar"><span style="width:{{ max(4, min(100, $s)) }}%; background:#1e293b;"></span></div>
      </a>
    @endforeach
  </div>
  <p class="wt-note">Tiles open the ERP page when one exists, otherwise the website admin page in a new tab.</p>

  <div class="wt-grid two">
    <div class="wt-card">
      <h3>Top Metrics</h3>
      <p class="wt-note" style="margin-top:-6px;">Highest activity in the selected range.</p>
      @foreach($top6 as $i => $m)
        <div class="wd-hbar"><span class="n">{{ $m['title'] }}</span><span class="b"><span style="width:{{ $m['value'] / $maxTop * 100 }}%; background:{{ $colors[$i % count($colors)] }};"></span></span><span class="c">{{ number_format($m['value']) }}</span></div>
      @endforeach
    </div>
    <div class="wt-card">
      <h3>Distribution</h3>
      <p class="wt-note" style="margin-top:-6px;">Composition across all metrics.</p>
      @foreach($rows as $i => $m)
        <div class="wd-hbar"><span class="n"><span style="display:inline-block; width:9px; height:9px; border-radius:2px; background:{{ $colors[$i % count($colors)] }};"></span> {{ $m['title'] }}</span><span class="b"><span style="width:{{ $share($m['value']) }}%; background:{{ $colors[$i % count($colors)] }};"></span></span><span class="c">{{ number_format($share($m['value']), 1) }}%</span></div>
      @endforeach
    </div>
    <div class="wt-card">
      <h3>Channel Priority</h3>
      <p class="wt-note" style="margin-top:-6px;">Which channel contributes the biggest volume.</p>
      @foreach($channels as $c)
        <div class="wd-hbar"><span class="n">{{ $c['channel'] }}</span><span class="b"><span style="width:{{ $c['value'] / $maxCh * 100 }}%; background:#16A34A;"></span></span><span class="c">{{ number_format($c['value']) }}</span></div>
      @endforeach
    </div>
    <div class="wt-card">
      <h3>Signal to Action Funnel</h3>
      <p class="wt-note" style="margin-top:-6px;">How discovery converts into actions.</p>
      @foreach($funnel as $f)
        <div class="wd-hbar"><span class="n">{{ $f['stage'] }} <span class="muted">({{ $f['conversion'] }}%)</span></span><span class="b"><span style="width:{{ $f['value'] / $maxFunnel * 100 }}%; background:#2563EB;"></span></span><span class="c">{{ number_format($f['value']) }}</span></div>
      @endforeach
    </div>
  </div>
</div>
@endsection
