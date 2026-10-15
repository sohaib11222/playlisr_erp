@extends('layouts.app')

@section('title', 'Manage Venues')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')
@include('website_admin.bookings._styles')
<style>
  .wbv-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:18px; }
  .wbv-img { height:180px; background:#e5e7eb; position:relative; display:flex; align-items:center; justify-content:center; color:#9ca3af; font-size:40px; }
  .wbv-img img { width:100%; height:100%; object-fit:cover; display:block; }
  .wbv-flag { position:absolute; top:8px; right:8px; padding:3px 9px; border-radius:999px; font-size:12px; font-weight:600; }
  .wbv-flag.on { background:#dcfce7; color:#166534; } .wbv-flag.off { background:#fee2e2; color:#991b1b; }
  .wbv-body { padding:14px; }
  .wbv-body h3 { font-size:16px; font-weight:700; margin:0 0 8px; }
  .wbv-meta { font-size:13px; color:#4b5563; line-height:1.7; margin-bottom:12px; }
  .wbv-meta i { width:16px; color:#9ca3af; }
  .wbv-actions { display:flex; gap:6px; }
  .wbv-actions > * { flex:1; justify-content:center; }
  .wbv-actions form { flex:0 0 auto; margin:0; }
</style>

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <a href="{{ route('wa.bookings.index') }}" style="font-size:13px;"><i class="fa fa-arrow-left"></i> Back to Bookings</a>
      <h1>Manage Venues</h1>
      <p class="sub">Add, edit, or remove venue listings on nivessa.com.</p>
    </div>
    <a href="{{ route('wa.bookings.venueCreate') }}" class="wb-btn wb-btn-primary"><i class="fa fa-plus"></i> Add New Venue</a>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @if($error)<div class="alert-err">Failed to load venues: {{ $error }}</div>@endif

  <div class="wb-filters">
    <div class="wb-field grow">
      <label for="wbv-search">Search</label>
      <input type="text" id="wbv-search" placeholder="Search venues by name or city..." autocomplete="off">
    </div>
  </div>

  <div class="wb-card wb-empty" id="wbv-empty" style="{{ count($venues) ? 'display:none;' : '' }}">
    <h3>No venues found</h3>
    <p id="wbv-empty-sub">Get started by adding your first venue.</p>
  </div>

  <div class="wbv-grid">
    @foreach($venues as $v)
      <div class="wb-card wbv-card" data-search="{{ strtolower(($v['name'] ?? '') . ' ' . ($v['location']['city'] ?? '')) }}">
        <div class="wbv-img">
          @if($v['_img'])
            <img src="{{ $v['_img'] }}" alt="{{ $v['name'] ?? '' }}" loading="lazy">
          @else
            <i class="fa fa-building"></i>
          @endif
          <span class="wbv-flag {{ !empty($v['isActive']) ? 'on' : 'off' }}">{{ !empty($v['isActive']) ? 'Active' : 'Inactive' }}</span>
        </div>
        <div class="wbv-body">
          <h3>{{ $v['name'] ?? '' }}</h3>
          <div class="wbv-meta">
            <div><i class="fa fa-map-marker"></i> {{ $v['location']['city'] ?? '' }}, {{ $v['location']['state'] ?? '' }}</div>
            <div><i class="fa fa-users"></i> Up to {{ $v['capacity']['maxCapacity'] ?? '' }} guests</div>
            <div><i class="fa fa-dollar-sign"></i> {{ $v['pricing']['hourlyRate'] ?? '' }}/hour</div>
          </div>
          <div class="wbv-actions">
            <a href="https://nivessa.com/venues/{{ urlencode($v['slug'] ?? $v['_id']) }}" target="_blank" rel="noopener" class="wb-btn wb-btn-sm"><i class="fa fa-eye"></i> View</a>
            <a href="{{ route('wa.bookings.venueEdit', $v['_id']) }}" class="wb-btn wb-btn-sm wb-btn-blue"><i class="fa fa-pencil-alt"></i> Edit</a>
            <form method="POST" action="{{ route('wa.bookings.venueDelete', $v['_id']) }}" onsubmit="return confirm('Are you sure you want to delete this venue?');">
              {{ csrf_field() }}
              <button type="submit" class="wb-btn wb-btn-sm wb-btn-danger" title="Delete venue"><i class="fa fa-trash"></i></button>
            </form>
          </div>
        </div>
      </div>
    @endforeach
  </div>
</div>
<script>
(function () {
  var input = document.getElementById('wbv-search');
  var cards = Array.prototype.slice.call(document.querySelectorAll('.wbv-card'));
  input.addEventListener('input', function () {
    var q = input.value.toLowerCase().trim(), shown = 0;
    cards.forEach(function (c) {
      var ok = !q || c.getAttribute('data-search').indexOf(q) >= 0;
      c.style.display = ok ? '' : 'none';
      if (ok) shown++;
    });
    document.getElementById('wbv-empty').style.display = shown ? 'none' : '';
    document.getElementById('wbv-empty-sub').textContent = q ? 'Try adjusting your search.' : 'Get started by adding your first venue.';
  });
})();
</script>
@endsection
