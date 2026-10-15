@extends('layouts.app')
@section('title', 'Sealed Velocity')

@section('content')
@include('website_admin.content._styles')
@php
  $H = \App\Http\Controllers\WebsiteAdmin\SearchInsightsController::class;
  $majorColor = ['Warner' => 'blue', 'Universal' => 'purple', 'Sony' => 'red', 'Independent' => 'green', 'Unknown' => ''];
  $chart = array_slice($rows, 0, 15);
  $max = 1; foreach ($chart as $c) { $max = max($max, (float) ($c['avgDaysToSell'] ?? 0)); }
  $days = function ($n) { return $n === null ? '' : (round($n * 10) / 10) . 'd'; };
  $f = $filters;
@endphp
<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Sealed Vinyl and CD Velocity</h1>
      <p class="sub">Average days from inventory to sale for sealed (Mint) vinyl and CDs, with major-label ownership from Discogs.</p>
    </div>
  </div>

  @if($bridgeError)<div class="alert-err">Could not reach the website: {{ $bridgeError }}</div>@endif

  <form method="GET" class="wc-filters">
    <div class="wc-field"><label>Format</label>
      <select name="format">
        <option value="both" @if($f['format'] === 'both') selected @endif>Vinyl and CD</option>
        <option value="vinyl" @if($f['format'] === 'vinyl') selected @endif>Vinyl only</option>
        <option value="cd" @if($f['format'] === 'cd') selected @endif>CD only</option>
      </select>
    </div>
    <div class="wc-field"><label>Genre</label>
      <select name="genre"><option value="">All genres</option>@foreach($genres as $g)<option value="{{ $g }}" @if($f['genre'] === $g) selected @endif>{{ $g }}</option>@endforeach</select>
    </div>
    <div class="wc-field" style="max-width:260px;"><label>Label</label>
      <select name="label"><option value="">All labels</option>@foreach($labels as $l)<option value="{{ $l }}" @if($f['label'] === $l) selected @endif>{{ $l }}</option>@endforeach</select>
    </div>
    <div class="wc-field"><label>Artist (search)</label><input type="text" name="artist" value="{{ $f['artist'] }}" placeholder="e.g. Fleetwood Mac"></div>
    <div class="wc-field"><label>Sold from</label><input type="date" name="from" value="{{ $f['from'] }}"></div>
    <div class="wc-field"><label>Sold to</label><input type="date" name="to" value="{{ $f['to'] }}"></div>
    <div class="wc-field" style="max-width:110px;"><label>Min sales</label><input type="number" name="minSales" min="1" value="{{ $f['minSales'] }}"></div>
    <div class="wc-actions"><button type="submit" class="wc-btn wc-btn-primary">Apply</button><a class="wc-btn" href="{{ route('wa.content.sealedVelocity') }}">Reset</a></div>
  </form>

  <div class="wc-stats">
    <div class="wc-stat"><div class="k">Titles on page</div><div class="v">{{ number_format($summary['titles']) }}</div><div class="s">of {{ number_format($total) }} total</div></div>
    <div class="wc-stat"><div class="k">Avg days to sell</div><div class="v">{{ $summary['avg'] === null ? '' : $summary['avg'] . 'd' }}</div><div class="s">across the titles shown</div></div>
    <div class="wc-stat"><div class="k">Sealed copies sold</div><div class="v">{{ number_format($summary['sales']) }}</div><div class="s">on this page</div></div>
  </div>

  <div class="wc-card">
    <div class="wc-card-head"><div><h2>Top 15 fastest movers</h2><div class="wc-muted">A shorter bar sells faster.</div></div></div>
    @if(count($chart) === 0)
      <div class="wc-empty">No data for these filters.</div>
    @else
      <div class="wc-card-pad"><div class="wc-bars">
        @foreach($chart as $c)
          <div class="wc-bar-row"><span class="n" title="{{ $c['title'] ?? '' }}">{{ ($c['title'] ?? '') !== '' ? $c['title'] : '(untitled)' }}</span><span class="b"><span style="width:{{ round(((float) ($c['avgDaysToSell'] ?? 0)) / $max * 100, 1) }}%;"></span></span><span class="c">{{ $days($c['avgDaysToSell'] ?? 0) }}</span></div>
        @endforeach
      </div></div>
    @endif
  </div>

  <div class="wc-card">
    <div class="wc-card-head"><div><h2>Per-title velocity</h2><div class="wc-muted">Fastest first. The median sits next to the average to spot averages pulled by outliers.</div></div></div>
    @if(count($rows) === 0)
      <div class="wc-empty">No sealed sales match these filters.</div>
    @else
    <div class="wc-scroll">
      <table class="wc-tbl">
        <thead><tr><th>Title</th><th>Artist</th><th>Label</th><th>Major</th><th>Genre</th><th>Format</th><th class="num">Sales</th><th class="num">Avg days</th><th class="num">Median</th><th>Last sold</th></tr></thead>
        <tbody>
        @foreach($rows as $r)
          <tr>
            <td style="font-weight:600;">{{ $r['title'] ?? '' }}</td>
            <td>{{ $r['artist'] ?? '' }}</td>
            <td>{{ $r['label'] ?? '' }}</td>
            <td><span class="wc-pill {{ $majorColor[$r['majorGroup'] ?? 'Unknown'] ?? '' }}" title="{{ !empty($r['parentLabel']) ? 'Parent: ' . $r['parentLabel'] : '' }}">{{ $r['majorGroup'] ?? 'Unknown' }}</span></td>
            <td>{{ $r['genre'] ?? '' }}</td>
            <td>{{ $r['format'] ?? '' }}</td>
            <td class="num">{{ $r['salesCount'] ?? 0 }}</td>
            <td class="num" style="font-weight:600;">{{ $days($r['avgDaysToSell'] ?? null) }}</td>
            <td class="num wc-muted">{{ $days($r['medianDaysToSell'] ?? null) }}</td>
            <td class="wc-muted">{{ $H::fmtDate($r['lastSoldAt'] ?? null) }}</td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    @endif
    @if($total > $limit)
    <div class="wc-pager">
      <span>Showing {{ number_format($skip + 1) }} to {{ number_format(min($skip + $limit, $total)) }} of {{ number_format($total) }}</span>
      <div class="wc-actions">
        @if($skip > 0)<a class="wc-btn wc-btn-sm" href="{{ request()->fullUrlWithQuery(['skip' => max($skip - $limit, 0)]) }}">Previous</a>@else<button class="wc-btn wc-btn-sm" disabled>Previous</button>@endif
        @if($skip + $limit < $total)<a class="wc-btn wc-btn-sm" href="{{ request()->fullUrlWithQuery(['skip' => $skip + $limit]) }}">Next</a>@else<button class="wc-btn wc-btn-sm" disabled>Next</button>@endif
      </div>
    </div>
    @endif
  </div>

  <div class="wc-card">
    <div class="wc-card-head"><div><h2>Most searched terms on nivessa.com</h2><div class="wc-muted">What customers type into the site search, a demand signal alongside velocity. Full list on <a href="{{ route('wa.content.mostSearched') }}">Most Searched</a>.</div></div></div>
    @if(count($topQueries) === 0)
      <div class="wc-empty">No search data yet.</div>
    @else
      <table class="wc-tbl">
        <thead><tr><th style="width:50px;">#</th><th>Query</th><th class="num">Searches</th><th>Last searched</th></tr></thead>
        <tbody>
        @foreach($topQueries as $i => $q)
          <tr><td>{{ $i + 1 }}</td><td style="font-weight:600;">{{ ($q['_id'] ?? '') !== '' ? $q['_id'] : '(empty)' }}</td><td class="num">{{ number_format((int) ($q['count'] ?? 0)) }}</td><td class="wc-muted">{{ $H::fmtDate($q['latestSearch'] ?? null, 'M j, Y g:i A') }}</td></tr>
        @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>
@endsection
