@extends('layouts.app')

@section('title', 'Website Synced Products')

@section('content')
@include('website_admin.catalog._styles')
@php
  $img = [\App\Http\Controllers\WebsiteAdmin\CatalogBaseController::class, 'img'];
  $srcName = function ($s) {
    if ($s === 'ultimatepos') return ['POS', 'blue'];
    if ($s === 'discogs') return ['Discogs', 'green'];
    return ['Unknown', 'gray'];
  };
  $keep = array_filter(array_merge($f, ['search' => $search]), function ($v) { return $v !== ''; });
@endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Synced Products</h1>
      <p class="sub">Every product the website pulls from Discogs and the POS, and whether each one shows on the website.
        Loaded {{ now()->setTimezone('America/Los_Angeles')->format('g:i A') }}@if($cached), from the 5 minute cache @endif.</p>
    </div>
    <form method="POST" action="{{ route('wa.catalog.synced.refresh') }}">
      {{ csrf_field() }}
      @foreach($keep as $k => $v)<input type="hidden" name="q[{{ $k }}]" value="{{ $v }}">@endforeach
      <button type="submit" class="wc-btn primary">Refresh</button>
    </form>
  </div>
  @include('website_admin.catalog._nav')

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if($bridgeError)<div class="alert-err">{{ $bridgeError }}</div>@endif

  <div class="wc-stats">
    <div class="wc-stat"><div class="n">{{ number_format($counts['total']) }}</div><div class="l">Total products</div></div>
    <div class="wc-stat"><div class="n">{{ number_format($counts['discogs']) }}</div><div class="l">Discogs products ({{ $counts['pageDiscogs'] }} on this page)</div></div>
    <div class="wc-stat"><div class="n">{{ number_format($counts['pos']) }}</div><div class="l">POS products ({{ $counts['pagePos'] }} on this page)</div></div>
    <div class="wc-stat"><div class="n">{{ number_format($counts['filtered']) }}</div><div class="l">Matching these filters</div></div>
  </div>

  <form method="GET" action="{{ route('wa.catalog.synced') }}" class="wc-filters">
    <div class="f" style="flex:1 1 260px;"><label>Search</label><input type="text" name="search" value="{{ $search }}" placeholder="Title or creator"></div>
    <div class="f"><label>Source</label>
      <select name="source">
        @foreach(['' => 'All Sources', 'discogs' => 'Discogs', 'ultimatepos' => 'POS'] as $v => $l)<option value="{{ $v }}" @if($f['source'] === $v) selected @endif>{{ $l }}</option>@endforeach
      </select>
    </div>
    <div class="f"><label>Genre</label><input type="text" name="genre" value="{{ $f['genre'] }}" placeholder="Rock, Jazz" style="width:120px;"></div>
    <div class="f"><label>Style</label><input type="text" name="style" value="{{ $f['style'] }}" placeholder="Blues" style="width:110px;"></div>
    <div class="f"><label>Format</label><input type="text" name="format" value="{{ $f['format'] }}" placeholder="Vinyl, CD" style="width:110px;"></div>
    <div class="f"><label>Location</label><input type="text" name="location" value="{{ $f['location'] }}" style="width:110px;"></div>
    <div class="f"><label>Min price</label><input type="number" step="0.01" name="minPrice" value="{{ $f['minPrice'] }}" style="width:90px;"></div>
    <div class="f"><label>Max price</label><input type="number" step="0.01" name="maxPrice" value="{{ $f['maxPrice'] }}" style="width:90px;"></div>
    <div class="f"><label>Min qty</label><input type="number" name="minQuantity" value="{{ $f['minQuantity'] }}" style="width:80px;"></div>
    <div class="f"><label>Max qty</label><input type="number" name="maxQuantity" value="{{ $f['maxQuantity'] }}" style="width:80px;"></div>
    <div class="f"><label>On website</label>
      <select name="showOnWebsite">
        @foreach(['' => 'All', 'yes' => 'Yes', 'no' => 'No'] as $v => $l)<option value="{{ $v }}" @if($f['showOnWebsite'] === $v) selected @endif>{{ $l }}</option>@endforeach
      </select>
    </div>
    <div class="f"><label>Sort by</label>
      <select name="sortBy">
        @foreach(['default' => 'Default', 'title' => 'Title', 'price' => 'Price', 'quantity' => 'Quantity', 'source' => 'Source', 'genre' => 'Genre', 'format' => 'Format', 'location' => 'Location'] as $v => $l)
          <option value="{{ $v }}" @if(($f['sortBy'] ?: 'default') === $v) selected @endif>{{ $l }}</option>
        @endforeach
      </select>
    </div>
    <div class="f"><label>Order</label>
      <select name="sortOrder">
        <option value="asc" @if($f['sortOrder'] !== 'desc') selected @endif>Ascending</option>
        <option value="desc" @if($f['sortOrder'] === 'desc') selected @endif>Descending</option>
      </select>
    </div>
    <button type="submit" class="wc-btn primary">Apply</button>
    @if($activeFilters > 0 || $search !== '')<a href="{{ route('wa.catalog.synced') }}" class="wc-btn">Reset</a>@endif
  </form>

  <div class="wc-card">
    <div class="wc-scroll">
      <table class="wc-tbl" style="min-width:1700px;">
        <thead>
          <tr>
            <th>ID</th><th>Title</th><th>Source</th><th>Genre</th><th>Style</th><th>Description</th><th>Brand</th>
            <th>Catalog #</th><th>Tracklist</th><th>Format</th><th>Qty</th><th>Price</th><th>Location</th><th>Zone</th>
            <th>Show on website</th><th>URL</th>
          </tr>
        </thead>
        <tbody>
          @forelse($rows as $r)
            @php $s = $srcName($r['source'] ?? ''); $ld = is_array($r['locationData'] ?? null) ? $r['locationData'] : null; $on = !empty($r['sellOnHomepage']); @endphp
            <tr>
              <td class="wc-muted" style="font-family:monospace;">{{ $r['id'] ?? '' }}</td>
              <td style="max-width:240px;">
                <div style="display:flex; gap:8px; align-items:center;">
                  @if(!empty($r['image']))<img src="{{ $img($r['image']) }}" class="wc-img" style="width:40px; height:40px;" loading="lazy" alt="" onerror="this.style.visibility='hidden'">@endif
                  <span style="font-weight:600;">{{ $r['title'] ?? '' }}</span>
                </div>
              </td>
              <td><span class="wc-pill {{ $s[1] }}">{{ $s[0] }}</span></td>
              <td>{{ $r['genre'] ?? 'N/A' }}</td>
              <td>{{ $r['style'] ?? 'N/A' }}</td>
              <td class="wc-muted" style="max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="{{ $r['description'] ?? '' }}">{{ $r['description'] ?? 'N/A' }}</td>
              <td>{{ $r['brand'] ?? 'N/A' }}</td>
              <td>{{ $r['catalogue_number'] ?? 'N/A' }}</td>
              <td class="wc-muted" style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="{{ is_string($r['tracklist'] ?? null) ? $r['tracklist'] : '' }}">{{ is_string($r['tracklist'] ?? null) ? $r['tracklist'] : 'N/A' }}</td>
              <td>{{ $r['format'] ?? 'Unknown' }}</td>
              <td><span class="wc-pill {{ (int) ($r['quantity'] ?? 0) > 0 ? 'green' : 'red' }}">{{ $r['quantity'] ?? 0 }}</span></td>
              <td>{{ isset($r['price']) && $r['price'] !== null ? '$' . $r['price'] : 'N/A' }}</td>
              <td class="wc-muted" style="font-family:monospace;">{{ $r['location'] ?? 'N/A' }}</td>
              <td>
                @if($ld && !empty($ld['mapped']))
                  <strong>{{ $ld['store'] ?? '' }}</strong>@if(!empty($ld['zone']))<div class="wc-muted">{{ $ld['zone'] }}</div>@endif
                @elseif($ld)
                  <span class="wc-pill orange">Not mapped</span>
                @else
                  <span class="wc-muted">No location</span>
                @endif
              </td>
              <td>
                <button type="button" class="wc-btn {{ $on ? 'primary' : '' }}" data-on="{{ $on ? '1' : '0' }}" data-id="{{ json_encode($r['id'] ?? null) }}" data-source="{{ $r['source'] ?? '' }}" onclick="wcSyncToggle(this)">{{ $on ? 'Yes' : 'No' }}</button>
              </td>
              <td>@if(!empty($r['url']))<a href="{{ $r['url'] }}" target="_blank" rel="noopener">Open</a>@else<span class="wc-muted">N/A</span>@endif</td>
            </tr>
          @empty
            <tr><td colspan="16" class="empty">No products match.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="wc-pager">
    @if($hasPrev)<a class="wc-btn" href="{{ request()->fullUrlWithQuery(['page' => $page - 1, 'refresh' => null]) }}">Previous</a>@endif
    <span>Page {{ $page }} of {{ $pages }}</span>
    @if($hasNext)<a class="wc-btn" href="{{ request()->fullUrlWithQuery(['page' => $page + 1, 'refresh' => null]) }}">Next</a>@endif
  </div>
</div>
<script>
  function wcSyncToggle(btn) {
    var next = btn.getAttribute('data-on') !== '1';
    btn.disabled = true;
    fetch('{{ route('wa.catalog.synced.toggle') }}', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
      body: JSON.stringify({ productId: JSON.parse(btn.getAttribute('data-id')), source: btn.getAttribute('data-source'), sellOnHomepage: next })
    }).then(function (r) { return r.json(); }).then(function (j) {
      btn.disabled = false;
      if (!j.success) { alert(j.message || 'Could not update the homepage setting.'); return; }
      btn.setAttribute('data-on', next ? '1' : '0');
      btn.textContent = next ? 'Yes' : 'No';
      btn.classList.toggle('primary', next);
    }).catch(function () { btn.disabled = false; alert('Could not update the homepage setting.'); });
  }
</script>
@endsection
