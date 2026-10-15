@extends('layouts.app')
@section('title', 'Most Searched')

@section('content')
@include('website_admin.content._styles')
@php
  $H = \App\Http\Controllers\WebsiteAdmin\SearchInsightsController::class;
  $chart = array_slice($queries, 0, 15);
  $max = 1; foreach ($chart as $c) { $max = max($max, (int) ($c['count'] ?? 0)); }
  $rankBg = ['#fef9c3', '#f3f4f6', '#ffedd5'];
@endphp
<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Most Searched</h1>
      <p class="sub">What people search for most on nivessa.com, and which artists get boosted to the top of auto-suggest.</p>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @if($bridgeError)<div class="alert-err">Could not reach the website: {{ $bridgeError }}</div>@endif

  <div class="wc-card">
    <div class="wc-card-head"><div><h2>Prioritize artists in auto-suggest</h2><div class="wc-muted">Boosted artists always show first in the search dropdown.</div></div></div>
    @if($artistError)
      <div class="wc-empty">{{ $artistError }}</div>
    @elseif(count($artists) === 0)
      <div class="wc-empty">No search data yet.</div>
    @else
    <div class="wc-scroll">
      <table class="wc-tbl">
        <thead><tr><th style="width:50px;">#</th><th>Search query</th><th class="num">Searches</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @foreach($artists as $i => $a)
          <tr>
            <td class="wc-muted">{{ $i + 1 }}</td>
            <td style="font-weight:600;">{{ $a['query'] ?? '' }}</td>
            <td class="num">{{ number_format((int) ($a['count'] ?? 0)) }}</td>
            <td>@if(!empty($a['isPrioritized']))<span class="wc-pill green">Boosted</span>@else<span class="wc-pill">Normal</span>@endif</td>
            <td>
              <form method="POST" action="{{ !empty($a['isPrioritized']) ? route('wa.content.mostSearchedUnboost') : route('wa.content.mostSearchedBoost') }}" style="margin:0;">
                {{ csrf_field() }}
                <input type="hidden" name="query" value="{{ $a['query'] ?? '' }}">
                <button type="submit" class="wc-btn wc-btn-sm {{ !empty($a['isPrioritized']) ? '' : 'wc-btn-primary' }}">{{ !empty($a['isPrioritized']) ? 'Remove boost' : 'Boost' }}</button>
              </form>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    @endif
  </div>

  <div class="wc-card">
    <div class="wc-card-head"><div><h2>Most frequent search terms</h2><div class="wc-muted">Loaded {{ $loadedAt }}.</div></div></div>
    @if(count($queries) === 0)
      <div class="wc-empty">No search data yet.</div>
    @else
      @if($page === 1)
      <div class="wc-card-pad">
        <div class="wc-bars">
          @foreach($chart as $c)
            <div class="wc-bar-row"><span class="n" title="{{ $c['_id'] ?? '' }}">{{ ($c['_id'] ?? '') !== '' ? $c['_id'] : '(empty)' }}</span><span class="b"><span style="width:{{ round(((int) ($c['count'] ?? 0)) / $max * 100, 1) }}%;"></span></span><span class="c">{{ number_format((int) ($c['count'] ?? 0)) }}</span></div>
          @endforeach
        </div>
      </div>
      @endif
      <div class="wc-scroll">
        <table class="wc-tbl">
          <thead><tr><th style="width:60px;">#</th><th>Query</th><th class="num">Count</th><th>Last searched</th></tr></thead>
          <tbody>
          @foreach($queries as $i => $q)
            @php $rank = ($page - 1) * $limit + $i; @endphp
            <tr style="{{ isset($rankBg[$rank]) ? 'background:' . $rankBg[$rank] . ';' : '' }}{{ $rank === 0 ? 'font-weight:700;' : '' }}">
              <td>{{ $rank + 1 }}</td>
              <td title="{{ $q['_id'] ?? '' }}">{{ \Illuminate\Support\Str::limit((string) ($q['_id'] ?? ''), 60) }}</td>
              <td class="num">{{ number_format((int) ($q['count'] ?? 0)) }}</td>
              <td class="wc-muted">{{ !empty($q['latestSearch']) ? $H::fmtDate($q['latestSearch'], 'M j, Y g:i A') : 'N/A' }}</td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
      <div class="wc-pager">
        <span>Showing {{ number_format(($page - 1) * $limit + 1) }} to {{ number_format(($page - 1) * $limit + count($queries)) }} of {{ number_format($total) }}</span>
        <form method="GET" style="margin:0; display:flex; align-items:center; gap:6px;">
          <span>Per page</span>
          <select name="limit" class="wc-input" style="width:auto;" onchange="this.form.submit()">
            @foreach($pageSizes as $s)<option value="{{ $s }}" @if($limit === $s) selected @endif>{{ number_format($s) }}</option>@endforeach
          </select>
        </form>
        <div class="wc-actions">
          @if($page > 1)<a class="wc-btn wc-btn-sm" href="{{ request()->fullUrlWithQuery(['page' => $page - 1]) }}">Previous</a>@else<button class="wc-btn wc-btn-sm" disabled>Previous</button>@endif
          <span>Page {{ $page }} of {{ $totalPages }}</span>
          @if($page < $totalPages)<a class="wc-btn wc-btn-sm" href="{{ request()->fullUrlWithQuery(['page' => $page + 1]) }}">Next</a>@else<button class="wc-btn wc-btn-sm" disabled>Next</button>@endif
        </div>
      </div>
    @endif
  </div>
</div>
@endsection
