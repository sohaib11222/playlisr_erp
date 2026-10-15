@extends('layouts.app')

@section('title', 'Directory Stores')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')
@include('website_admin.directory._styles')

<style>
  .ds-grid { display:grid; grid-template-columns:minmax(260px, 340px) 1fr; gap:18px; align-items:start; }
  @media (max-width: 991px) { .ds-grid { grid-template-columns:1fr; } }
  .ds-list { max-height:65vh; overflow-y:auto; }
  .ds-item { display:block; padding:11px 14px; border-bottom:1px solid #f3f4f6; color:#111827; text-decoration:none; }
  .ds-item:hover { background:#f9fafb; color:#111827; text-decoration:none; }
  .ds-item.active { background:#dbeafe; }
  .ds-item .n { font-weight:600; font-size:13.5px; }
  .ds-item .l { font-size:12px; color:#6b7280; margin-top:2px; }
  .ds-side-filters { padding:12px 14px; border-bottom:1px solid #f0f0f0; display:grid; grid-template-columns:1fr 1fr; gap:8px; }
  .ds-side-filters .full { grid-column:1 / -1; }
  .ds-side-filters label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#9ca3af; margin-bottom:3px; }
  .ds-side-filters input, .ds-side-filters select { width:100%; font-size:13px; padding:7px 9px; border:1px solid #d1d5db; border-radius:7px; background:#fff; }
  .ds-form { display:grid; grid-template-columns:1fr 1fr; gap:12px 14px; }
  .ds-form .full { grid-column:1 / -1; }
  @media (max-width: 640px) { .ds-form { grid-template-columns:1fr; } }
  .ds-form label { display:block; font-size:12px; font-weight:600; color:#5a5145; margin-bottom:4px; }
  .ds-form input[type=text], .ds-form input[type=datetime-local], .ds-form select, .ds-form textarea { width:100%; box-sizing:border-box; border:1px solid #d1d5db; border-radius:8px; padding:8px 10px; font-size:13.5px; font-family:inherit; background:#fff; }
  .ds-form textarea { resize:vertical; }
  .ds-note { margin-bottom:12px; padding:9px 12px; border-radius:8px; font-size:13px; }
  .ds-note.warn { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
  .ds-note.info { background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; }
  .ds-ev { display:flex; justify-content:space-between; gap:12px; padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; margin-bottom:8px; }
  .ds-ev .t { font-weight:600; font-size:13.5px; }
</style>

@php
  $filterQuery = array_filter($f, fn($v) => $v !== '' && $v !== null && $v !== 'all');
  $s = $store ?: [];
  $val = function ($k) use ($s) {
      $old = old($k);
      if ($old !== null) { return $old; }
      $v = $s[$k] ?? '';
      return is_array($v) ? implode(', ', $v) : $v;
  };
  $ev = $editEvent ?: [];
  $evVal = function ($k) use ($ev) {
      $old = old($k);
      if ($old !== null) { return $old; }
      if (in_array($k, ['start_at', 'end_at'], true)) {
          return \App\Http\Controllers\WebsiteAdmin\DirectoryBaseController::la($ev[$k] ?? '', 'Y-m-d\TH:i');
      }
      return $ev[$k] ?? '';
  };
  $showForm = $isNew || $store;
@endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Directory Stores</h1>
      <p class="sub">Store profiles in the nivessa.com record store directory, with their social links, map, images and events.</p>
    </div>
    <a href="{{ route('wa.directory.stores', $filterQuery + ['mode' => 'new']) }}" class="wd-btn wd-btn-primary">New store</a>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  <div class="ds-grid">
    {{-- Store list --}}
    <div class="wd-card">
      <div class="wd-card-head"><h2>Store list</h2></div>
      <form method="GET" action="{{ route('wa.directory.stores') }}" class="ds-side-filters">
        <div class="full">
          <label>Search</label>
          <input type="text" name="q" value="{{ $f['q'] }}" placeholder="Name, location or address">
        </div>
        <div class="full">
          <label>Status</label>
          <select name="status">
            <option value="all" {{ $f['status'] === 'all' ? 'selected' : '' }}>All</option>
            @foreach($statuses as $st)
              <option value="{{ $st }}" {{ $f['status'] === $st ? 'selected' : '' }}>{{ ucfirst($st) }}</option>
            @endforeach
          </select>
        </div>
        <div class="full">
          <label>Category</label>
          <input type="text" name="primary_category" value="{{ $f['primary_category'] }}" list="ds-category-options" placeholder="Filter by category">
        </div>
        <div>
          <label>Country</label>
          <input type="text" name="country" value="{{ $f['country'] }}" list="ds-country-options" placeholder="Country">
        </div>
        <div>
          <label>City</label>
          <input type="text" name="city" value="{{ $f['city'] }}" placeholder="City">
        </div>
        <div>
          <label>Sort by</label>
          <select name="sortBy">
            @foreach($sorts as $k => $label)
              <option value="{{ $k }}" {{ $f['sortBy'] === $k ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label>Order</label>
          <select name="sortOrder">
            <option value="desc" {{ $f['sortOrder'] === 'desc' ? 'selected' : '' }}>Newest / Z first</option>
            <option value="asc" {{ $f['sortOrder'] === 'asc' ? 'selected' : '' }}>Oldest / A first</option>
          </select>
        </div>
        <div>
          <label>Per page</label>
          <select name="limit">
            @foreach([20, 50, 100, 250] as $n)
              <option value="{{ $n }}" {{ (int) $f['limit'] === $n ? 'selected' : '' }}>{{ $n }}</option>
            @endforeach
          </select>
        </div>
        <div style="display:flex; align-items:flex-end; gap:6px;">
          <button type="submit" class="wd-btn wd-btn-primary">Apply</button>
          <a href="{{ route('wa.directory.stores') }}" class="wd-btn">Reset</a>
        </div>
      </form>
      <datalist id="ds-category-options">@foreach($categories as $o)<option value="{{ $o }}">@endforeach</datalist>
      <datalist id="ds-country-options">@foreach($countries as $o)<option value="{{ $o }}">@endforeach</datalist>

      <div class="ds-list">
        @if($listError)
          <div class="wd-empty" style="color:#b91c1c;">{{ $listError }}</div>
        @elseif(count($stores) === 0)
          <div class="wd-empty">No stores found.</div>
        @else
          @foreach($stores as $row)
            <a href="{{ route('wa.directory.stores', $filterQuery + ['store' => $row['_id'] ?? '']) }}"
               class="ds-item {{ ($row['_id'] ?? '') === $selectedId ? 'active' : '' }}">
              <div class="n">{{ $row['store_name'] ?? 'Unnamed store' }}</div>
              <div class="l">{{ implode(', ', array_filter([$row['city'] ?? '', $row['country'] ?? ''])) }}
                @if(($row['status'] ?? '') !== '' && ($row['status'] ?? '') !== 'published') &middot; {{ $row['status'] }} @endif
                @if(($row['source'] ?? '') === 'legacy') &middot; legacy @endif
              </div>
            </a>
          @endforeach
        @endif
      </div>
      <div class="wd-pager" style="justify-content:space-between;">
        <span>Page {{ $pagination['page'] }} of {{ max(1, (int) $pagination['totalPages']) }}, {{ number_format((int) $pagination['total']) }} total</span>
        <span class="wd-actions">
          @if(!empty($pagination['hasPrevPage']))
            <a class="wd-btn" href="{{ route('wa.directory.stores', array_merge($filterQuery, ['page' => $f['page'] - 1])) }}">Prev</a>
          @endif
          @if(!empty($pagination['hasNextPage']))
            <a class="wd-btn" href="{{ route('wa.directory.stores', array_merge($filterQuery, ['page' => $f['page'] + 1])) }}">Next</a>
          @endif
        </span>
      </div>
    </div>

    <div>
      {{-- Store profile --}}
      <div class="wd-card">
        <div class="wd-card-head">
          <h2>{{ $isNew ? 'New store' : ($store ? ($store['store_name'] ?? 'Store profile') : 'Store profile') }}</h2>
          @if($store)
            <form method="POST" action="{{ route('wa.directory.storeDelete', ['id' => $selectedId]) }}"
                  onsubmit="return confirm('Delete this directory store and all its events? This cannot be undone.');">
              {{ csrf_field() }}
              @foreach($filterQuery as $k => $v)<input type="hidden" name="f[{{ $k }}]" value="{{ $v }}">@endforeach
              <button type="submit" class="wd-btn wd-btn-danger" {{ $isLegacy ? 'disabled' : '' }}
                      title="{{ $isLegacy ? 'Legacy stores cannot be deleted here.' : 'Delete this store' }}">Delete store</button>
            </form>
          @endif
        </div>
        <div class="wd-card-body">
          @if(!$showForm)
            <div class="wd-empty">Pick a store on the left, or start a new one.</div>
          @else
            @if($isLegacy)
              <div class="ds-note warn">This store comes from the legacy records dataset. Saving here updates the legacy store record.</div>
            @endif
            <form method="POST" action="{{ route('wa.directory.storeSave') }}" enctype="multipart/form-data">
              {{ csrf_field() }}
              <input type="hidden" name="id" value="{{ $selectedId }}">
              @foreach($filterQuery as $k => $v)<input type="hidden" name="f[{{ $k }}]" value="{{ $v }}">@endforeach
              <div class="ds-form">
                <div class="full"><label>Store name</label><input type="text" name="store_name" value="{{ $val('store_name') }}" required></div>
                <div class="full"><label>Address</label><input type="text" name="address" value="{{ $val('address') }}"></div>
                <div><label>Primary category</label><input type="text" name="primary_category" value="{{ $val('primary_category') }}" list="ds-category-options" placeholder="Choose or type a category"></div>
                <div><label>Country</label><input type="text" name="country" id="ds-country" value="{{ $val('country') }}" list="ds-country-options" placeholder="Choose or type a country"></div>
                <div><label>State</label><input type="text" name="state" id="ds-state" value="{{ $val('state') }}" list="ds-state-options" placeholder="Choose or type a state"></div>
                <div><label>City</label><input type="text" name="city" value="{{ $val('city') }}" list="ds-city-options" placeholder="Choose or type a city"></div>
                <datalist id="ds-state-options">@foreach($states as $o)<option value="{{ $o }}">@endforeach</datalist>
                <datalist id="ds-city-options">@foreach($cities as $o)<option value="{{ $o }}">@endforeach</datalist>
                <div><label>Postal code</label><input type="text" name="postal_code" value="{{ $val('postal_code') }}"></div>
                <div><label>Phone</label><input type="text" name="phone" value="{{ $val('phone') }}"></div>
                <div><label>Email</label><input type="text" name="email" value="{{ $val('email') }}"></div>
                <div><label>Website URL</label><input type="text" name="website_url" value="{{ $val('website_url') }}"></div>
                <div><label>Map URL</label><input type="text" name="map_url" value="{{ $val('map_url') }}"></div>
                <div><label>Opening hours</label><input type="text" name="opening_hours" value="{{ $val('opening_hours') }}"></div>
                <div><label>Image URL</label><input type="text" name="image_url" value="{{ $val('image_url') }}"></div>
                <div><label>Instagram URL</label><input type="text" name="instagram_url" value="{{ $val('instagram_url') }}"></div>
                <div><label>Facebook URL</label><input type="text" name="facebook_url" value="{{ $val('facebook_url') }}"></div>
                <div><label>Twitter URL</label><input type="text" name="twitter_url" value="{{ $val('twitter_url') }}"></div>
                <div class="full">
                  <label>Upload store cover image</label>
                  <input type="file" name="image" accept="image/*">
                  @if(!empty($s['image_url']))
                    <div style="margin-top:6px;"><img src="{{ $s['image_url'] }}" alt="" style="max-height:90px; border-radius:6px; border:1px solid #e5e7eb;"></div>
                  @endif
                </div>
                <div class="full"><label>Categories (comma separated, at least one)</label><input type="text" name="categories" value="{{ $val('categories') }}"></div>
                <div class="full"><label>Gallery image URLs (comma separated)</label><input type="text" name="gallery_images" value="{{ $val('gallery_images') }}"></div>
                <div class="full"><label>Description</label><textarea name="description" rows="4">{{ $val('description') }}</textarea></div>
                <div class="full"><label>SEO title (max 60 characters)</label><input type="text" name="meta_title" maxlength="60" value="{{ $val('meta_title') }}"></div>
                <div class="full"><label>SEO meta description (max 160 characters)</label><textarea name="meta_description" rows="2" maxlength="160">{{ $val('meta_description') }}</textarea></div>
                <div>
                  <label>Status</label>
                  @php $curStatus = $val('status') ?: 'published'; @endphp
                  <select name="status">
                    @foreach($statuses as $st)
                      <option value="{{ $st }}" {{ $curStatus === $st ? 'selected' : '' }}>{{ ucfirst($st) }}</option>
                    @endforeach
                  </select>
                </div>
                <div style="display:flex; align-items:flex-end;">
                  @php $active = old('_token') ? (bool) old('is_active') : ($store ? !empty($s['is_active']) : true); @endphp
                  <label style="display:flex; gap:7px; align-items:center; font-size:13.5px;">
                    <input type="checkbox" name="is_active" value="1" {{ $active ? 'checked' : '' }}> Active listing
                  </label>
                </div>
              </div>
              <div style="display:flex; gap:12px; align-items:center; margin-top:16px; flex-wrap:wrap;">
                <button type="submit" class="wd-btn wd-btn-primary">Save store</button>
                @if(!empty($s['map_url']))
                  <a href="{{ $s['map_url'] }}" target="_blank" rel="noopener noreferrer" class="wd-muted">Preview map link</a>
                @endif
                @if(!empty($s['slug']))
                  <span class="wd-muted">Slug: {{ $s['slug'] }}</span>
                @endif
              </div>
            </form>
          @endif
        </div>
      </div>

      {{-- Store events --}}
      <div class="wd-card">
        <div class="wd-card-head"><h2>Store events</h2></div>
        <div class="wd-card-body">
          @if(!$store)
            <p class="wd-muted" style="margin:0;">Save or pick a store first to manage its events.</p>
          @elseif($isLegacy)
            <div class="ds-note warn" style="margin:0;">Event management is only available for submission-based stores.</div>
          @else
            @if($editEvent)
              <div class="ds-note info">Editing "{{ $editEvent['title'] ?? 'event' }}".
                <a href="{{ route('wa.directory.stores', $filterQuery + ['store' => $selectedId]) }}">Cancel edit</a></div>
            @endif
            <form method="POST" action="{{ route('wa.directory.storeEventSave', ['id' => $selectedId]) }}" enctype="multipart/form-data">
              {{ csrf_field() }}
              <input type="hidden" name="event_id" value="{{ $editEvent['_id'] ?? '' }}">
              @foreach($filterQuery as $k => $v)<input type="hidden" name="f[{{ $k }}]" value="{{ $v }}">@endforeach
              <div class="ds-form">
                <div><label>Event title</label><input type="text" name="title" value="{{ $evVal('title') }}" required></div>
                <div><label>Venue name</label><input type="text" name="venue_name" value="{{ $evVal('venue_name') }}"></div>
                <div><label>Starts (Pacific time)</label><input type="datetime-local" name="start_at" value="{{ $evVal('start_at') }}" required></div>
                <div><label>Ends (Pacific time)</label><input type="datetime-local" name="end_at" value="{{ $evVal('end_at') }}"></div>
                <div><label>Event URL</label><input type="text" name="event_url" value="{{ $evVal('event_url') }}"></div>
                <div><label>Image URL</label><input type="text" name="image_url" value="{{ $evVal('image_url') }}"></div>
                <div class="full"><label>Upload event image</label><input type="file" name="image" accept="image/*"></div>
                <div class="full"><label>Description</label><textarea name="description" rows="3">{{ $evVal('description') }}</textarea></div>
                <div>
                  @php $evActive = $editEvent ? (($editEvent['is_active'] ?? true) !== false) : true; @endphp
                  <label style="display:flex; gap:7px; align-items:center; font-size:13.5px;">
                    <input type="checkbox" name="is_active" value="1" {{ $evActive ? 'checked' : '' }}> Active event
                  </label>
                </div>
              </div>
              <div style="margin-top:14px;">
                <button type="submit" class="wd-btn wd-btn-primary">{{ $editEvent ? 'Update event' : 'Add event' }}</button>
              </div>
            </form>

            <h3 style="font-size:14px; font-weight:700; margin:20px 0 10px;">Existing events</h3>
            @if($eventsError)
              <p style="color:#b91c1c; margin:0;">{{ $eventsError }}</p>
            @elseif(count($events) === 0)
              <p class="wd-muted" style="margin:0;">No events yet for this store.</p>
            @else
              @foreach($events as $e)
                <div class="ds-ev">
                  <div>
                    <div class="t">{{ $e['title'] ?? 'Untitled' }}
                      @if(($e['is_active'] ?? true) === false) <span class="wd-st other">Inactive</span> @endif
                    </div>
                    <div class="wd-muted">{{ \App\Http\Controllers\WebsiteAdmin\DirectoryBaseController::la($e['start_at'] ?? '') }}
                      @if(!empty($e['venue_name'])) &middot; {{ $e['venue_name'] }} @endif</div>
                    @if(!empty($e['event_url']))
                      <a href="{{ $e['event_url'] }}" target="_blank" rel="noopener noreferrer" class="wd-muted">Open event link</a>
                    @endif
                  </div>
                  <div class="wd-actions" style="align-self:flex-start;">
                    <a class="wd-btn" href="{{ route('wa.directory.stores', $filterQuery + ['store' => $selectedId, 'event' => $e['_id'] ?? '']) }}">Edit</a>
                    <form method="POST" action="{{ route('wa.directory.storeEventDelete', ['id' => $selectedId, 'eventId' => $e['_id'] ?? '']) }}"
                          onsubmit="return confirm('Delete this event?');">
                      {{ csrf_field() }}
                      @foreach($filterQuery as $k => $v)<input type="hidden" name="f[{{ $k }}]" value="{{ $v }}">@endforeach
                      <button type="submit" class="wd-btn wd-btn-danger">Delete</button>
                    </form>
                  </div>
                </div>
              @endforeach
            @endif
          @endif
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// Refresh the state/city suggestions when the country or state changes.
(function () {
  var country = document.getElementById('ds-country');
  var state = document.getElementById('ds-state');
  if (!country || !state) return;
  function fill(id, list) {
    var dl = document.getElementById(id);
    if (!dl) return;
    dl.innerHTML = '';
    (list || []).forEach(function (v) { var o = document.createElement('option'); o.value = v; dl.appendChild(o); });
  }
  function load() {
    var url = '{{ route('wa.directory.storeOptions') }}?country=' + encodeURIComponent(country.value.trim()) + '&state=' + encodeURIComponent(state.value.trim());
    fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { fill('ds-state-options', j.states); fill('ds-city-options', j.cities); })
      .catch(function () {});
  }
  country.addEventListener('change', load);
  state.addEventListener('change', load);
})();
</script>
@endsection
