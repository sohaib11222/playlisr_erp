@extends('layouts.app')

@section('title', $mode === 'add' ? 'Add New Venue' : 'Edit Venue')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')
@include('website_admin.bookings._styles')
<style>
  .wbf-sec { margin-bottom:22px; }
  .wbf-sec h2 { font-size:16px; font-weight:700; margin:0 0 12px; }
  .wbf-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; }
  .wbf-grid.three { grid-template-columns:repeat(3, minmax(0, 1fr)); }
  .wbf-grid .span2 { grid-column:1 / -1; }
  @media (max-width: 767px) { .wbf-grid, .wbf-grid.three { grid-template-columns:1fr; } }
  .wbf-sec .wb-field label { font-size:13px; font-weight:600; color:#374151; text-transform:none; letter-spacing:0; }
  .wbf-preset { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 12px; border:1px solid #e5e7eb; border-radius:8px; background:#f9fafb; margin-bottom:8px; cursor:pointer; }
  .wbf-preset.on { border-color:#93c5fd; background:#dbeafe; }
  .wbf-preset .nm { font-weight:600; color:#111827; font-size:14px; }
  .wbf-preset .ds { font-size:12px; color:#6b7280; }
  .wbf-preset input[type=number] { width:96px; padding:5px 8px; border:1px solid #d1d5db; border-radius:6px; font-size:13px; }
  .wbf-row { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:10px; }
  .wbf-row input[type=text], .wbf-row input[type=number] { padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; }
  .wbf-am { display:flex; justify-content:space-between; align-items:center; padding:9px 12px; background:#f9fafb; border-radius:8px; margin-bottom:6px; font-size:14px; }
  .wbf-day { display:flex; align-items:center; gap:14px; margin-bottom:8px; flex-wrap:wrap; }
  .wbf-day .dl { width:130px; display:flex; align-items:center; gap:8px; font-size:14px; font-weight:600; color:#374151; }
  .wbf-day input[type=time] { padding:6px 8px; border:1px solid #d1d5db; border-radius:8px; font-size:13px; }
  .wbf-imgs { display:grid; grid-template-columns:repeat(auto-fill, minmax(160px, 1fr)); gap:12px; margin-top:12px; }
  .wbf-img { position:relative; height:120px; border:1px solid #d1d5db; border-radius:8px; overflow:hidden; background:#f3f4f6; }
  .wbf-img img { width:100%; height:100%; object-fit:cover; display:block; }
  .wbf-img .rm { position:absolute; top:6px; right:6px; width:26px; height:26px; border-radius:50%; border:none; background:#dc2626; color:#fff; cursor:pointer; }
  .wbf-img .main { position:absolute; bottom:6px; left:6px; padding:2px 8px; border-radius:5px; background:#1e40af; color:#fff; font-size:11px; }
  .wbf-noimg { text-align:center; padding:28px; border:2px dashed #d1d5db; border-radius:8px; color:#6b7280; font-size:13px; margin-top:12px; }
  .wbf-foot { display:flex; justify-content:space-between; align-items:center; gap:10px; padding-top:16px; border-top:1px solid #e5e7eb; }
</style>

@php
  $loc = (array) ($venue['location'] ?? []);
  $cap = (array) ($venue['capacity'] ?? []);
  $pri = (array) ($venue['pricing'] ?? []);
  $o = function ($key, $fallback) { return old($key, $fallback); };
  $isActive = old('_token') ? (bool) old('isActive') : ($venue ? (array_key_exists('isActive', $venue) ? (bool) $venue['isActive'] : true) : true);
  $amenitiesInit = old('amenities_json') ? json_decode(old('amenities_json'), true) : $amenities;
  $imagesInit = $images;
  if (old('images_json')) {
    $paths = json_decode(old('images_json'), true) ?: [];
    $ctrl = app(\App\Http\Controllers\WebsiteAdmin\WebsiteBookingsController::class);
    $imagesInit = array_map(function ($p) use ($ctrl) { return ['path' => $p, 'url' => $ctrl->imageUrl($p)]; }, $paths);
  }
@endphp

<div class="ev-wrap" style="max-width:900px;">
  <div class="ev-head">
    <div>
      <a href="{{ route('wa.bookings.venues') }}" style="font-size:13px;"><i class="fa fa-arrow-left"></i> Back to Venues</a>
      <h1>{{ $mode === 'add' ? 'Add New Venue' : 'Edit Venue' }}</h1>
      @if($venue)<p class="sub">{{ $venue['name'] ?? '' }}</p>@endif
    </div>
  </div>

  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  <form method="POST" id="wbf-form" class="ev-card" action="{{ $mode === 'add' ? route('wa.bookings.venueStore') : route('wa.bookings.venueUpdate', $venue['_id']) }}">
    {{ csrf_field() }}
    <input type="hidden" name="amenities_json" id="wbf-amenities-json">
    <input type="hidden" name="images_json" id="wbf-images-json">

    <div class="wbf-sec">
      <h2>Basic Information</h2>
      <div class="wbf-grid">
        <div class="wb-field span2">
          <label for="name">Venue Name *</label>
          <input type="text" id="name" name="name" required value="{{ $o('name', $venue['name'] ?? '') }}">
        </div>
        <div class="wb-field span2">
          <label for="description">Description</label>
          <textarea rows="3" id="description" name="description">{{ $o('description', $venue['description'] ?? '') }}</textarea>
        </div>
      </div>
    </div>

    <div class="wbf-sec">
      <h2>Address</h2>
      <div class="wbf-grid">
        <div class="wb-field span2">
          <label for="loc-address">Street Address</label>
          <input type="text" id="loc-address" name="location[address]" value="{{ $o('location.address', $loc['address'] ?? '') }}">
        </div>
        <div class="wb-field">
          <label for="loc-city">City</label>
          <input type="text" id="loc-city" name="location[city]" value="{{ $o('location.city', $loc['city'] ?? '') }}">
        </div>
        <div class="wb-field">
          <label for="loc-state">State</label>
          <input type="text" id="loc-state" name="location[state]" value="{{ $o('location.state', $loc['state'] ?? '') }}">
        </div>
        <div class="wb-field">
          <label for="loc-zip">Zip Code</label>
          <input type="text" id="loc-zip" name="location[zipCode]" value="{{ $o('location.zipCode', $loc['zipCode'] ?? '') }}">
        </div>
        <div class="wb-field">
          <label for="loc-store">Store</label>
          @php $store = $o('location.store', $loc['store'] ?? 'Hollywood'); @endphp
          <select id="loc-store" name="location[store]">
            <option value="Hollywood" @if($store === 'Hollywood') selected @endif>Hollywood</option>
            <option value="Pico" @if($store === 'Pico') selected @endif>Pico</option>
          </select>
        </div>
      </div>
    </div>

    <div class="wbf-sec">
      <h2>Capacity</h2>
      <div class="wbf-grid three">
        <div class="wb-field">
          <label for="cap-max">Max Capacity <span style="color:#dc2626;">*</span></label>
          <input type="number" id="cap-max" name="capacity[maxCapacity]" placeholder="e.g. 50" value="{{ $o('capacity.maxCapacity', $cap['maxCapacity'] ?? '') }}">
          <div class="wb-hint">Shown publicly and used to validate bookings.</div>
        </div>
        <div class="wb-field">
          <label for="cap-seated">Seated Capacity</label>
          <input type="number" id="cap-seated" name="capacity[seated]" value="{{ $o('capacity.seated', $cap['seated'] ?? '') }}">
        </div>
        <div class="wb-field">
          <label for="cap-standing">Standing Capacity</label>
          <input type="number" id="cap-standing" name="capacity[standing]" value="{{ $o('capacity.standing', $cap['standing'] ?? '') }}">
        </div>
      </div>
    </div>

    <div class="wbf-sec">
      <h2>Pricing</h2>
      <div class="wbf-grid">
        <div class="wb-field">
          <label for="pr-hourly">Standard Hourly Rate ($)</label>
          <input type="number" step="0.01" id="pr-hourly" name="pricing[hourlyRate]" placeholder="e.g. 99" value="{{ $o('pricing.hourlyRate', $pri['hourlyRate'] ?? '') }}">
          <div class="wb-hint">Monday to Friday rate.</div>
        </div>
        <div class="wb-field">
          <label for="pr-peak">Peak Hourly Rate ($) <span style="color:#c2410c;">Weekend</span></label>
          <input type="number" step="0.01" id="pr-peak" name="pricing[peakHourlyRate]" placeholder="e.g. 149" value="{{ $o('pricing.peakHourlyRate', $pri['peakHourlyRate'] ?? '') }}">
          <div class="wb-hint">Saturday and Sunday rate. Leave blank to use the standard rate.</div>
        </div>
        <div class="wb-field">
          <label for="pr-clean">Cleaning Fee ($)</label>
          <input type="number" step="0.01" id="pr-clean" name="pricing[cleaningFee]" value="{{ $o('pricing.cleaningFee', $pri['cleaningFee'] ?? '') }}">
        </div>
        <div class="wb-field">
          <label for="pr-dep">Security Deposit ($)</label>
          <input type="number" step="0.01" id="pr-dep" name="pricing[securityDeposit]" placeholder="e.g. 200" value="{{ $o('pricing.securityDeposit', $pri['securityDeposit'] ?? '') }}">
          <div class="wb-hint">Refundable. Shown as an optional add-on at checkout.</div>
        </div>
      </div>
    </div>

    <div class="wbf-sec">
      <h2>Amenities and Add-ons</h2>
      <p style="font-size:13px; font-weight:600; color:#374151; margin:0 0 8px;">Standard add-ons (check to enable for this venue)</p>
      <div id="wbf-presets"></div>

      <p style="font-size:13px; font-weight:600; color:#374151; margin:16px 0 8px; padding-top:12px; border-top:1px solid #e5e7eb;">Custom Amenity</p>
      <div class="wbf-row">
        <input type="text" id="wbf-am-name" placeholder="Amenity name" style="flex:1 1 200px;">
        <label style="display:flex; align-items:center; gap:6px; font-size:13px; margin:0;"><input type="checkbox" id="wbf-am-incl" checked> Included</label>
        <input type="number" id="wbf-am-cost" placeholder="Cost" style="width:96px; display:none;">
        <button type="button" class="wb-btn wb-btn-blue" id="wbf-am-add"><i class="fa fa-plus"></i> Add</button>
      </div>
      <div id="wbf-am-list"></div>
    </div>

    <div class="wbf-sec">
      <h2>Operating Hours</h2>
      @foreach($days as $key => $label)
        @php
          $d = (array) ($availability[$key] ?? ['available' => false, 'openTime' => '10:00', 'closeTime' => '22:00']);
          $avail = old('_token') ? (bool) old('availability.' . $key . '.available') : !empty($d['available']);
        @endphp
        <div class="wbf-day">
          <label class="dl" for="avail-{{ $key }}"><input type="checkbox" id="avail-{{ $key }}" name="availability[{{ $key }}][available]" value="1" class="wbf-day-toggle" @if($avail) checked @endif> {{ $label }}</label>
          <span class="wbf-times" style="{{ $avail ? '' : 'display:none;' }}">
            <input type="time" name="availability[{{ $key }}][openTime]" value="{{ $o('availability.' . $key . '.openTime', $d['openTime'] ?? '10:00') }}">
            <span style="color:#6b7280;">to</span>
            <input type="time" name="availability[{{ $key }}][closeTime]" value="{{ $o('availability.' . $key . '.closeTime', $d['closeTime'] ?? '22:00') }}">
          </span>
          <span class="wbf-closed" style="font-size:13px; color:#9ca3af; font-style:italic; {{ $avail ? 'display:none;' : '' }}">Closed</span>
        </div>
      @endforeach
    </div>

    <div class="wbf-sec">
      <h2>Venue Images</h2>
      <div class="wbf-row">
        <label class="wb-btn wb-btn-blue" style="margin:0; cursor:pointer;">
          <i class="fa fa-image"></i> Upload Images
          <input type="file" id="wbf-file" multiple accept="image/*" style="display:none;">
        </label>
        <input type="text" id="wbf-url" placeholder="Or paste an image URL" style="flex:1 1 240px;">
        <button type="button" class="wb-btn" id="wbf-url-add"><i class="fa fa-plus"></i> Add URL</button>
      </div>
      <div id="wbf-upload-msg" class="wb-hint" style="display:none;"></div>
      <div id="wbf-imgs" class="wbf-imgs"></div>
      <div id="wbf-noimg" class="wbf-noimg">No images uploaded yet. Upload images or add image URLs.</div>
    </div>

    <div class="wbf-sec">
      <label style="display:flex; align-items:center; gap:8px; font-size:14px; font-weight:600; margin:0;">
        <input type="checkbox" name="isActive" value="1" @if($isActive) checked @endif>
        Venue is active and available for booking
      </label>
      <div class="wb-hint">The website hides inactive venues from its venue list, so an inactive venue will not show on the Manage Venues page afterwards.</div>
    </div>

    <div class="wbf-foot">
      <a href="{{ route('wa.bookings.venues') }}" class="wb-btn">Cancel</a>
      <button type="submit" class="wb-btn wb-btn-primary" id="wbf-save"><i class="fa fa-save"></i> {{ $mode === 'add' ? 'Create Venue' : 'Save Changes' }}</button>
    </div>
  </form>
</div>

<script>
(function () {
  var CSRF = '{{ csrf_token() }}';
  var UPLOAD_URL = '{{ route('wa.bookings.venueImageUpload') }}';
  var DELETE_URL = '{{ route('wa.bookings.venueImageDelete') }}';
  var PRESETS = {!! json_encode($presets, JSON_HEX_TAG | JSON_HEX_AMP) !!};
  var amenities = {!! json_encode(array_values((array) $amenitiesInit), JSON_HEX_TAG | JSON_HEX_AMP) !!} || [];
  var images = {!! json_encode(array_values($imagesInit), JSON_HEX_TAG | JSON_HEX_AMP) !!} || [];
  var uploading = 0;

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function findAm(name) { for (var i = 0; i < amenities.length; i++) if (amenities[i].name === name) return i; return -1; }

  function renderPresets() {
    var box = document.getElementById('wbf-presets');
    box.innerHTML = '';
    PRESETS.forEach(function (p) {
      var idx = findAm(p.name), on = idx >= 0;
      var row = document.createElement('label');
      row.className = 'wbf-preset' + (on ? ' on' : '');
      row.innerHTML = '<span style="display:flex;gap:10px;align-items:center;"><input type="checkbox"' + (on ? ' checked' : '') + '>' +
        '<span><span class="nm">' + esc(p.name) + '</span><br><span class="ds">' + esc(p.description) + '</span></span></span>' +
        (on ? '<input type="number" step="0.01" placeholder="Cost $" value="' + esc(amenities[idx].additionalCost != null ? amenities[idx].additionalCost : p.additionalCost) + '">'
            : '<span style="font-size:13px;color:#9ca3af;">+$' + esc(p.additionalCost) + '</span>');
      row.querySelector('input[type=checkbox]').addEventListener('change', function (e) {
        if (e.target.checked) amenities.push(JSON.parse(JSON.stringify(p)));
        else amenities = amenities.filter(function (a) { return a.name !== p.name; });
        renderAll();
      });
      var cost = row.querySelector('input[type=number]');
      if (cost) {
        cost.addEventListener('input', function () {
          var i = findAm(p.name); if (i >= 0) amenities[i].additionalCost = parseFloat(cost.value) || 0;
          renderList();
        });
      }
      box.appendChild(row);
    });
  }

  function renderList() {
    var box = document.getElementById('wbf-am-list');
    box.innerHTML = '';
    amenities.forEach(function (a, i) {
      var row = document.createElement('div');
      row.className = 'wbf-am';
      row.innerHTML = '<span><strong>' + esc(a.name) + '</strong> ' +
        (a.included ? '<span style="color:#15803d;font-size:13px;">Included</span>' : '<span style="color:#4b5563;font-size:13px;">+$' + esc(a.additionalCost) + '</span>') +
        '</span><button type="button" class="wb-btn wb-btn-sm wb-btn-danger" title="Remove">Remove</button>';
      row.querySelector('button').addEventListener('click', function () { amenities.splice(i, 1); renderAll(); });
      box.appendChild(row);
    });
  }

  function renderAll() { renderPresets(); renderList(); }

  var inclEl = document.getElementById('wbf-am-incl'), costEl = document.getElementById('wbf-am-cost');
  inclEl.addEventListener('change', function () { costEl.style.display = inclEl.checked ? 'none' : ''; });
  document.getElementById('wbf-am-add').addEventListener('click', function () {
    var nameEl = document.getElementById('wbf-am-name');
    var name = nameEl.value.trim();
    if (!name) return;
    amenities.push({ name: name, included: inclEl.checked, additionalCost: inclEl.checked ? 0 : (parseFloat(costEl.value) || 0) });
    nameEl.value = ''; costEl.value = ''; inclEl.checked = true; costEl.style.display = 'none';
    renderAll();
  });
  document.getElementById('wbf-am-name').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); document.getElementById('wbf-am-add').click(); } });

  document.querySelectorAll('.wbf-day-toggle').forEach(function (cb) {
    cb.addEventListener('change', function () {
      var row = cb.closest('.wbf-day');
      row.querySelector('.wbf-times').style.display = cb.checked ? '' : 'none';
      row.querySelector('.wbf-closed').style.display = cb.checked ? 'none' : '';
    });
  });

  function renderImages() {
    var box = document.getElementById('wbf-imgs');
    box.innerHTML = '';
    images.forEach(function (img, i) {
      var el = document.createElement('div');
      el.className = 'wbf-img';
      el.innerHTML = '<img src="' + esc(img.url || img.path) + '" alt="Venue ' + (i + 1) + '">' +
        '<button type="button" class="rm" title="Remove image">Remove</button>' +
        (i === 0 ? '<span class="main">Main Image</span>' : '');
      el.querySelector('.rm').addEventListener('click', function () { removeImage(i); });
      box.appendChild(el);
    });
    document.getElementById('wbf-noimg').style.display = images.length ? 'none' : '';
  }

  // Same as the website: the stored file is cleaned up right away.
  function removeImage(i) {
    var img = images[i];
    images.splice(i, 1);
    renderImages();
    var fd = new FormData(); fd.append('path', img.path);
    fetch(DELETE_URL, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, credentials: 'same-origin', body: fd }).catch(function () {});
  }

  function msg(t, bad) {
    var m = document.getElementById('wbf-upload-msg');
    m.textContent = t || ''; m.style.display = t ? '' : 'none'; m.style.color = bad ? '#b91c1c' : '#4b5563';
  }

  document.getElementById('wbf-file').addEventListener('change', function (e) {
    var files = Array.prototype.slice.call(e.target.files || []);
    e.target.value = '';
    if (!files.length) return;
    var tooBig = files.filter(function (f) { return f.size > 10 * 1024 * 1024; });
    if (tooBig.length) { msg(tooBig[0].name + ' is too large. Max size is 10MB.', true); return; }
    uploading++;
    document.getElementById('wbf-save').disabled = true;
    msg('Uploading images...');
    Promise.all(files.map(function (f) {
      var fd = new FormData(); fd.append('image', f);
      return fetch(UPLOAD_URL, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, credentials: 'same-origin', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (!d.success) throw new Error(d.message || 'Upload failed'); return d; });
    })).then(function (list) {
      list.forEach(function (d) { images.push({ path: d.path, url: d.url }); });
      renderImages();
      msg(files.length + ' image(s) uploaded. Save the venue to keep them.');
    }).catch(function (err) {
      msg('Failed to upload images. ' + (err.message || ''), true);
    }).then(function () {
      uploading--;
      if (!uploading) document.getElementById('wbf-save').disabled = false;
    });
  });

  document.getElementById('wbf-url-add').addEventListener('click', function () {
    var el = document.getElementById('wbf-url'), u = el.value.trim();
    if (!u) return;
    images.push({ path: u, url: u });
    el.value = '';
    renderImages();
  });
  document.getElementById('wbf-url').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); document.getElementById('wbf-url-add').click(); } });

  document.getElementById('wbf-form').addEventListener('submit', function (e) {
    if (uploading) { e.preventDefault(); return; }
    document.getElementById('wbf-amenities-json').value = JSON.stringify(amenities);
    document.getElementById('wbf-images-json').value = JSON.stringify(images.map(function (i) { return i.path; }));
    var b = document.getElementById('wbf-save'); b.disabled = true; b.textContent = 'Saving...';
  });

  renderAll();
  renderImages();
})();
</script>
@endsection
