@extends('layouts.app')

@section('title', 'Directory Searches')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')
@include('website_admin.directory._styles')

<style>
  .dsr-stats { display:grid; grid-template-columns:repeat(5, minmax(0, 1fr)); gap:12px; margin-bottom:18px; }
  @media (max-width: 900px) { .dsr-stats { grid-template-columns:repeat(2, minmax(0, 1fr)); } }
  .dsr-stat { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px; text-align:center; }
  .dsr-stat .v { font-size:26px; font-weight:700; color:#111827; font-variant-numeric:tabular-nums; }
  .dsr-stat .l { font-size:12.5px; color:#6b7280; margin-top:2px; }
  .dsr-bars { margin:0 0 16px; }
  .dsr-bar { display:grid; grid-template-columns:minmax(120px, 220px) 1fr 50px; gap:10px; align-items:center; font-size:13px; margin-bottom:5px; }
  .dsr-bar .q { white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color:#374151; }
  .dsr-bar .track { background:#f3f4f6; border-radius:5px; height:16px; overflow:hidden; }
  .dsr-bar .fill { background:#93c5fd; height:100%; border-radius:5px; }
  .dsr-bar .c { text-align:right; font-variant-numeric:tabular-nums; font-weight:600; }
  .dsr-top1 td { background:#fefce8; font-weight:600; }
  .dsr-top2 td { background:#f9fafb; }
  .dsr-top3 td { background:#fff7ed; }
</style>

@php
  $la = fn($d) => \App\Http\Controllers\WebsiteAdmin\DirectoryBaseController::la($d);
  $qText = function ($id) {
      if (is_array($id)) { return (string) ($id['query'] ?? json_encode($id)); }
      return (string) $id;
  };
  $topMax = 0;
  foreach (array_slice($top, 0, 15) as $t) { $topMax = max($topMax, (int) ($t['count'] ?? 0)); }
  $typeTag = fn($t) => $t === 'keyword' ? 'purple' : ($t === 'location' ? 'blue' : '');
  $base = ['type' => $type, 'days' => $days];
@endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Directory Searches</h1>
      <p class="sub">What people search for in the nivessa.com store directories, keyword searches and location lookups.</p>
    </div>
  </div>

  @if($stats)
    <div class="dsr-stats">
      <div class="dsr-stat"><div class="v">{{ number_format((int) ($stats['totalSearches'] ?? 0)) }}</div><div class="l">Total searches</div></div>
      <div class="dsr-stat"><div class="v">{{ number_format((int) ($stats['uniqueQueries'] ?? 0)) }}</div><div class="l">Unique queries</div></div>
      <div class="dsr-stat"><div class="v">{{ number_format($byType['keyword'] ?? 0) }}</div><div class="l">Keyword searches</div></div>
      <div class="dsr-stat"><div class="v">{{ number_format($byType['location'] ?? 0) }}</div><div class="l">Location searches</div></div>
      <div class="dsr-stat"><div class="v">{{ number_format((int) ($stats['withLocationCount'] ?? 0)) }}</div><div class="l">Keyword plus location</div></div>
    </div>
  @endif

  <form method="GET" action="{{ route('wa.directory.searches') }}" class="wd-filters">
    <div class="wd-field">
      <label>Type</label>
      <select name="type" onchange="this.form.submit()">
        @foreach(['all' => 'All types', 'keyword' => 'Keyword', 'location' => 'Location', 'with-location' => 'With location'] as $k => $label)
          <option value="{{ $k }}" {{ $type === $k ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
      </select>
    </div>
    <div class="wd-field">
      <label>Period (top searches)</label>
      <select name="days" onchange="this.form.submit()">
        @foreach(['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', '365' => 'Last year'] as $k => $label)
          <option value="{{ $k }}" {{ $days === (string) $k ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
      </select>
    </div>
    <div class="wd-field">
      <a href="{{ route('wa.directory.searches', $base) }}" class="wd-btn">Refresh</a>
    </div>
    <span class="wd-muted">Loaded {{ $loadedAt }}</span>
  </form>

  <div class="wd-card">
    <div class="wd-card-head"><h2>Top directory searches</h2></div>
    <div class="wd-card-body">
      @if($topError)
        <div style="color:#b91c1c;">{{ $topError }}</div>
      @elseif(count($top) === 0)
        <div class="wd-empty">No directory search data yet.</div>
      @else
        <div class="dsr-bars">
          @foreach(array_slice($top, 0, 15) as $t)
            @php $c = (int) ($t['count'] ?? 0); $q = $qText($t['_id'] ?? ''); @endphp
            <div class="dsr-bar">
              <span class="q" title="{{ $q }}">{{ $q !== '' ? $q : '(empty)' }}</span>
              <span class="track"><span class="fill" style="display:block; width:{{ $topMax > 0 ? round($c / $topMax * 100) : 0 }}%;"></span></span>
              <span class="c">{{ number_format($c) }}</span>
            </div>
          @endforeach
        </div>
        <div class="wd-scroll">
          <table class="wd-tbl" style="min-width:720px;">
            <thead><tr><th style="width:44px;">#</th><th>Query</th><th>Location</th><th>Type</th><th class="num">Count</th><th>Last searched</th></tr></thead>
            <tbody>
            @foreach($top as $i => $t)
              @php $q = $qText($t['_id'] ?? ''); @endphp
              <tr class="{{ $i < 3 ? 'dsr-top' . ($i + 1) : '' }}">
                <td>{{ $i + 1 }}</td>
                <td title="{{ $q }}">{{ \Illuminate\Support\Str::limit($q, 60) }}</td>
                <td class="wd-muted">{{ $t['location'] ?? '' ?: '-' }}</td>
                <td>@if(!empty($t['type']))<span class="wd-tag {{ $typeTag($t['type']) }}">{{ $t['type'] }}</span>@else<span class="wd-tag">mixed</span>@endif</td>
                <td class="num">{{ number_format((int) ($t['count'] ?? 0)) }}</td>
                <td class="wd-muted">{{ $la($t['lastSearched'] ?? '') ?: 'N/A' }}</td>
              </tr>
            @endforeach
            </tbody>
          </table>
        </div>
      @endif
    </div>
  </div>

  @if($stats && !empty($stats['byDirectory']))
    <div class="wd-card">
      <div class="wd-card-head"><h2>Searches by directory</h2><span class="wd-muted">Which directory sections get the most searches.</span></div>
      <table class="wd-tbl">
        <thead><tr><th>Directory</th><th class="num">Searches</th></tr></thead>
        <tbody>
        @foreach((array) $stats['byDirectory'] as $d)
          <tr><td style="font-weight:600;">{{ $d['_id'] ?? '' ?: '(unknown)' }}</td><td class="num">{{ number_format((int) ($d['count'] ?? 0)) }}</td></tr>
        @endforeach
        </tbody>
      </table>
    </div>
  @endif

  <div class="wd-card">
    <div class="wd-card-head"><h2>Recent directory searches</h2><span class="wd-muted">Most recent first.</span></div>
    @if($recentError)
      <div class="wd-empty" style="color:#b91c1c;">{{ $recentError }}</div>
    @elseif(count($recent) === 0)
      <div class="wd-empty">No searches recorded yet.</div>
    @else
      <div class="wd-scroll">
        <table class="wd-tbl" style="min-width:760px;">
          <thead><tr><th>Query</th><th>Location</th><th>Type</th><th>Directory</th><th class="num">Results</th><th>Time</th></tr></thead>
          <tbody>
          @foreach($recent as $r)
            <tr>
              <td style="font-weight:600;">{{ $r['query'] ?? '' }}</td>
              <td class="wd-muted">{{ $r['location'] ?? '' ?: '-' }}</td>
              <td><span class="wd-tag {{ $typeTag($r['type'] ?? '') }}">{{ $r['type'] ?? '' }}</span></td>
              <td class="wd-muted">{{ $r['directory'] ?? '' ?: '-' }}</td>
              <td class="num">{{ isset($r['resultsCount']) ? number_format((int) $r['resultsCount']) : '-' }}</td>
              <td class="wd-muted" style="white-space:nowrap;">{{ $la($r['createdAt'] ?? '') ?: '-' }}</td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
      @if($recentPages > 1)
        <div class="wd-pager">
          @if($page > 1)<a class="wd-btn" href="{{ route('wa.directory.searches', $base + ['page' => $page - 1]) }}">Previous</a>@endif
          <span>Page {{ $page }} of {{ $recentPages }}</span>
          @if($page < $recentPages)<a class="wd-btn" href="{{ route('wa.directory.searches', $base + ['page' => $page + 1]) }}">Next</a>@endif
        </div>
      @endif
    @endif
  </div>
</div>
@endsection
