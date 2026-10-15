@extends('layouts.app')

@section('title', 'Warehouse Locations')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>
@include('events.partials._styles')
@include('website_admin.warehouse._styles')

@php
  $allZones = array_values(array_unique(array_merge(...array_values($zonesByStore))));
  $storeColor = ['Hollywood' => 'purple', 'Pico' => 'blue', 'Warehouse' => 'orange', 'Other' => ''];
  $exportQ = array_filter($f, fn($v) => $v !== '') + ['export' => 'csv'];
@endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Locations</h1>
      <p class="sub">Discogs location codes and the store and zone each one points to.</p>
    </div>
    <div style="display:flex; gap:8px; flex-wrap:wrap;">
      <a class="wh-btn" href="#wh-automap">Auto-Mapper</a>
      <a class="wh-btn" href="{{ route('wa.warehouse.locations', $exportQ) }}">Export CSV</a>
      <button type="button" class="wh-btn" onclick="document.getElementById('lc-import').showModal()">Bulk import</button>
      <button type="button" class="wh-btn wh-btn-primary" onclick="lcOpen(null)">Add location</button>
    </div>
  </div>

  @include('website_admin.warehouse._nav')

  @if($preview)
    <div class="wh-card wh-card-pad">
      <h2>Import preview: {{ $preview['name'] ?? 'CSV' }}, {{ count($preview['rows'] ?? []) }} rows</h2>
      <div class="wh-scroll" style="max-height:320px; overflow-y:auto;">
        <table class="wh-tbl">
          <thead><tr><th>Code</th><th>Store</th><th>Zone</th><th>Description</th><th>Sub-location</th><th>Shelf</th></tr></thead>
          <tbody>
            @foreach($preview['rows'] ?? [] as $r)
              <tr>
                <td class="wh-mono">{{ $r['discogs_code'] ?? '' }}</td><td>{{ $r['store'] ?? '' }}</td><td>{{ $r['zone'] ?? '' }}</td>
                <td>{{ $r['description'] ?? '' }}</td><td>{{ $r['sub_location'] ?? '' }}</td><td>{{ $r['shelf_details'] ?? '' }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <p class="wh-muted" style="margin:10px 0;">Codes that already exist are updated. New codes are added.</p>
      <div style="display:flex; gap:8px;">
        <form method="POST" action="{{ route('wa.warehouse.locationsImport') }}">{{ csrf_field() }}<input type="hidden" name="step" value="confirm"><button type="submit" class="wh-btn wh-btn-primary">Import {{ count($preview['rows'] ?? []) }} locations</button></form>
        <form method="POST" action="{{ route('wa.warehouse.locationsImport') }}">{{ csrf_field() }}<input type="hidden" name="step" value="cancel"><button type="submit" class="wh-btn">Cancel</button></form>
      </div>
    </div>
  @endif

  @if($importResult)
    <div class="wh-card wh-card-pad">
      <h2>Import results</h2>
      <div class="wh-stats" style="margin-bottom:8px;">
        <div class="wh-stat green"><div class="l">Added</div><div class="v">{{ $importResult['created'] ?? 0 }}</div></div>
        <div class="wh-stat blue"><div class="l">Updated</div><div class="v">{{ $importResult['updated'] ?? 0 }}</div></div>
        <div class="wh-stat red"><div class="l">Skipped</div><div class="v">{{ $importResult['skipped'] ?? 0 }}</div></div>
      </div>
      @foreach($importResult['errors'] ?? [] as $e)
        <div class="wh-note err" style="margin-bottom:6px;"><b class="wh-mono">{{ $e['code'] ?? 'No code' }}</b>: {{ $e['error'] ?? '' }}</div>
      @endforeach
    </div>
  @endif

  <div class="wh-stats">
    <div class="wh-stat"><div class="l">Total Locations</div><div class="v">{{ $counts['total'] }}</div></div>
    <div class="wh-stat purple"><div class="l">Hollywood</div><div class="v">{{ $counts['Hollywood'] }}</div></div>
    <div class="wh-stat blue"><div class="l">Pico</div><div class="v">{{ $counts['Pico'] }}</div></div>
    <div class="wh-stat amber"><div class="l">Warehouse</div><div class="v">{{ $counts['Warehouse'] }}</div></div>
    <div class="wh-stat"><div class="l">Other</div><div class="v">{{ $counts['Other'] }}</div></div>
  </div>

  <form method="GET" action="{{ route('wa.warehouse.locations') }}" class="wh-filters">
    <div class="wh-field wh-grow">
      <label>Search code or description</label>
      <input type="text" name="q" value="{{ $f['q'] }}" placeholder="For example FL2 or PICO66">
    </div>
    <div class="wh-field">
      <label>Store</label>
      <select name="store" onchange="this.form.submit()">
        <option value="">All stores</option>
        @foreach($stores as $s)<option value="{{ $s }}" @if($f['store'] === $s) selected @endif>{{ $s }}</option>@endforeach
      </select>
    </div>
    <div class="wh-field">
      <label>Zone</label>
      <select name="zone" onchange="this.form.submit()">
        <option value="">All zones</option>
        @foreach($allZones as $z)<option value="{{ $z }}" @if($f['zone'] === $z) selected @endif>{{ $z }}</option>@endforeach
      </select>
    </div>
    <div class="wh-field">
      <label>Status</label>
      <select name="active" onchange="this.form.submit()">
        <option value="" @if($f['active'] === '') selected @endif>All</option>
        <option value="true" @if($f['active'] === 'true') selected @endif>Active</option>
        <option value="false" @if($f['active'] === 'false') selected @endif>Inactive</option>
      </select>
    </div>
    <button type="submit" class="wh-btn wh-btn-primary">Search</button>
  </form>

  <div class="wh-note info">Location codes can be edited. When you change a code here, change it on Discogs too so they match.</div>

  <div class="wh-card">
    @if($error)
      <div class="wh-card-pad"><div class="wh-note err" style="margin:0;">{{ $error }}</div></div>
    @elseif(!count($rows))
      <div class="wh-card-pad empty">No locations match. Change the filters or add one.</div>
    @else
      <div class="wh-scroll">
        <table class="wh-tbl" style="min-width:980px;">
          <thead><tr><th>Code</th><th>Store</th><th>Zone</th><th>Description</th><th>Sub-location</th><th>Shelf</th><th>Usage</th><th>Status</th><th></th></tr></thead>
          <tbody>
            @foreach($rows as $l)
              <tr>
                <td class="wh-mono" style="font-weight:700;">{!! !empty($l['discogs_code']) ? e($l['discogs_code']) : '<span class="wh-pill red">Missing code</span>' !!}</td>
                <td><span class="wh-pill {{ $storeColor[$l['store'] ?? ''] ?? 'red' }}">{{ ($l['store'] ?? '') ?: 'N/A' }}</span></td>
                <td>{!! !empty($l['zone']) ? e($l['zone']) : '<span class="wh-pill red">Missing zone</span>' !!}</td>
                <td class="wh-muted" style="font-size:13.5px;">{{ ($l['description'] ?? '') ?: '-' }}</td>
                <td>{{ ($l['sub_location'] ?? '') ?: '-' }}</td>
                <td>{{ ($l['shelf_details'] ?? '') ?: '-' }}</td>
                <td>{{ $l['usage_count'] ?? 0 }}</td>
                <td><span class="wh-pill {{ !empty($l['active']) ? 'green' : 'red' }}">{{ !empty($l['active']) ? 'Active' : 'Inactive' }}</span></td>
                <td style="white-space:nowrap;">
                  <button type="button" class="wh-btn wh-btn-sm" onclick='lcOpen(@json($l))'>Edit</button>
                  <details class="wh-confirm">
                    <summary class="wh-btn wh-btn-sm wh-btn-danger">Delete</summary>
                    <div class="wh-confirm-box">
                      <form method="POST" action="{{ route('wa.warehouse.locationsDelete', $l['_id'] ?? '') }}">
                        {{ csrf_field() }}
                        <button type="submit" class="wh-btn wh-btn-sm wh-btn-red">Yes, delete {{ $l['discogs_code'] ?? '' }}</button>
                      </form>
                    </div>
                  </details>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>

  <div class="wh-card wh-card-pad" id="wh-automap">
    <h2>Auto-Mapper</h2>
    <p class="wh-muted" style="margin-top:-6px;">Scans the whole Discogs inventory for location codes that have no mapping yet and suggests a store and zone for each.</p>
    <form method="POST" action="{{ route('wa.warehouse.locationsScan') }}" onsubmit="var b=this.querySelector('button'); b.disabled=true; b.textContent='Scanning Discogs, this can take a few minutes...';" style="margin-bottom:12px;">
      {{ csrf_field() }}
      <button type="submit" class="wh-btn wh-btn-primary">{{ $scan ? 'Scan again' : 'Start scan' }}</button>
      @if($scan)<span class="wh-muted" style="margin-left:8px;">Last scan {{ \Carbon\Carbon::createFromTimestamp($scan['at'] ?? time())->setTimezone('America/Los_Angeles')->format('M j, g:i a') }}</span>@endif
    </form>

    @if($scanResult)
      <div class="wh-note {{ count($scanResult['errors'] ?? []) ? 'warn' : 'info' }}">
        @if(!empty($scanResult['dry']))
          Preview: {{ count($scanResult['preview'] ?? []) }} mappings would be created, {{ $scanResult['skipped'] ?? 0 }} skipped.
          @foreach($scanResult['preview'] ?? [] as $p)
            <div><span class="wh-mono">{{ $p['discogs_code'] ?? '' }}</span> to {{ $p['store'] ?? '' }}, {{ $p['zone'] ?? '' }}</div>
          @endforeach
        @else
          Created {{ $scanResult['created'] ?? 0 }} mappings, {{ $scanResult['skipped'] ?? 0 }} skipped.
        @endif
        @foreach($scanResult['errors'] ?? [] as $e)
          <div><span class="wh-mono">{{ $e['code'] ?? '' }}</span>: {{ $e['error'] ?? '' }}</div>
        @endforeach
      </div>
    @endif

    @if($scan)
      <div class="wh-stats">
        <div class="wh-stat blue"><div class="l">Items Scanned</div><div class="v">{{ $scan['scanned'] ?? 0 }}</div></div>
        <div class="wh-stat green"><div class="l">Already Mapped</div><div class="v">{{ $scan['existing_mappings'] ?? 0 }}</div></div>
        <div class="wh-stat amber"><div class="l">Unmapped Codes</div><div class="v">{{ $scan['unmapped_count'] ?? 0 }}</div></div>
      </div>
      @if(count($scan['unmapped_locations'] ?? []))
        <form method="POST" action="{{ route('wa.warehouse.locationsAutoCreate') }}">
          {{ csrf_field() }}
          <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:10px;">
            <button type="button" class="wh-btn wh-btn-sm" onclick="lcAll(true)">Select all</button>
            <button type="button" class="wh-btn wh-btn-sm" onclick="lcAll(false)">Clear</button>
            <span class="wh-muted" id="lc-selcount">0 selected</span>
            <a class="wh-btn wh-btn-sm" href="{{ route('wa.warehouse.locationsScanExport') }}" style="margin-left:auto;">Export CSV</a>
          </div>
          <div class="wh-scroll" style="max-height:420px; overflow-y:auto; border:1px solid #e5e7eb; border-radius:8px;">
            <table class="wh-tbl">
              <thead><tr><th></th><th>Code</th><th>Usage</th><th>Suggested store</th><th>Suggested zone</th><th>Sample item</th></tr></thead>
              <tbody>
                @foreach($scan['unmapped_locations'] as $u)
                  <tr>
                    <td><input type="checkbox" name="codes[]" value="{{ $u['code'] ?? '' }}" class="lc-code" onchange="lcCount()" style="width:20px; height:20px;"></td>
                    <td class="wh-mono" style="font-weight:700; color:#6b21a8;">{{ $u['code'] ?? '' }}</td>
                    <td><span class="wh-pill blue">{{ $u['count'] ?? 0 }}x</span></td>
                    <td><span class="wh-pill {{ $storeColor[$u['suggested']['store'] ?? ''] ?? '' }}">{{ $u['suggested']['store'] ?? '' }}</span></td>
                    <td>{{ $u['suggested']['zone'] ?? '' }}</td>
                    <td class="wh-muted" style="font-size:12.5px;">{{ $u['sample_title'] ?? '' }}</td>
                  </tr>
                @endforeach
              </tbody>
            </table>
          </div>
          <div style="display:flex; gap:8px; margin-top:12px; flex-wrap:wrap;">
            <button type="submit" name="mode" value="preview" class="wh-btn">Preview</button>
            <button type="submit" name="mode" value="create" class="wh-btn wh-btn-primary">Create selected mappings</button>
          </div>
        </form>
      @endif
    @endif
  </div>
</div>

<dialog id="lc-dialog" class="wh-dialog wide">
  <form method="POST" id="lc-form" class="wh-dialog-body" onsubmit="return lcCheck()">
    {{ csrf_field() }}
    <input type="hidden" name="old_code" id="lc-old">
    <h3 id="lc-title">Add location</h3>
    <label for="lc-code">Discogs location code</label>
    <input type="text" id="lc-code" name="discogs_code" required maxlength="50" pattern="[A-Za-z0-9 _\-]+" placeholder="For example FL2, PICO 66, hw a68, J 5">
    <div id="lc-warn" class="wh-note warn" style="display:none; margin-top:8px;">
      Changing this code changes it for every product and order that uses it. Change it on Discogs too.
      <label style="display:flex; gap:6px; align-items:center; text-transform:none; letter-spacing:0; font-weight:600; color:#92400e; margin-top:6px;"><input type="checkbox" id="lc-ack"> I will change it on Discogs too</label>
    </div>
    <label for="lc-store">Store</label>
    <select id="lc-store" name="store" required onchange="lcZones('')">
      <option value="">Pick a store</option>
      @foreach($stores as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach
    </select>
    <label for="lc-zone">Zone</label>
    <select id="lc-zone" name="zone" required></select>
    <label for="lc-desc">Description</label>
    <textarea id="lc-desc" name="description" rows="2" placeholder="For example, front left section 2 near the entrance"></textarea>
    <label for="lc-sub">Sub-location</label>
    <input type="text" id="lc-sub" name="sub_location" placeholder="For example Aisle F, Section 2">
    <label for="lc-shelf">Shelf details</label>
    <input type="text" id="lc-shelf" name="shelf_details" placeholder="For example Shelf L-2, middle rack">
    <p class="wh-muted" id="lc-usage" style="margin-top:10px;"></p>
    <div id="lc-err" class="wh-note err" style="display:none; margin-top:8px;"></div>
    <div class="wh-dialog-actions">
      <button type="button" class="wh-btn" onclick="document.getElementById('lc-dialog').close()">Cancel</button>
      <button type="submit" class="wh-btn wh-btn-primary" id="lc-submit">Save location</button>
    </div>
  </form>
</dialog>

<dialog id="lc-import" class="wh-dialog">
  <form method="POST" action="{{ route('wa.warehouse.locationsImport') }}" enctype="multipart/form-data" class="wh-dialog-body">
    {{ csrf_field() }}
    <input type="hidden" name="step" value="preview">
    <h3>Bulk import locations</h3>
    <ol style="font-size:14px; padding-left:18px; color:#374151;">
      <li>Download the sample CSV.</li>
      <li>Fill in your locations and keep the header row.</li>
      <li>Upload it, check the preview, then import.</li>
    </ol>
    <a class="wh-btn wh-btn-sm" href="{{ route('wa.warehouse.locationsSample') }}">Download sample CSV</a>
    <label for="lc-csv">CSV file</label>
    <input type="file" id="lc-csv" name="csv" accept=".csv" required>
    <div class="wh-dialog-actions">
      <button type="button" class="wh-btn" onclick="document.getElementById('lc-import').close()">Cancel</button>
      <button type="submit" class="wh-btn wh-btn-primary">Preview import</button>
    </div>
  </form>
</dialog>

<script>
  var lcZonesByStore = @json($zonesByStore);
  var lcStoreUrl = @json(route('wa.warehouse.locationsStore'));
  var lcUpdateUrl = {!! json_encode(route('wa.warehouse.locationsUpdate', ['id' => '__ID__']), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};
  var lcEditing = null;

  function lcZones(selected) {
    var store = document.getElementById('lc-store').value;
    var sel = document.getElementById('lc-zone');
    var list = (lcZonesByStore[store] || []).slice();
    if (selected && list.indexOf(selected) < 0) list.push(selected);
    sel.innerHTML = '';
    var first = document.createElement('option');
    first.value = ''; first.textContent = store ? 'Pick a zone' : 'Pick a store first';
    sel.appendChild(first);
    list.forEach(function (z) {
      var o = document.createElement('option'); o.value = z; o.textContent = z;
      if (z === selected) o.selected = true;
      sel.appendChild(o);
    });
    sel.disabled = !store;
  }

  function lcOpen(loc) {
    lcEditing = loc;
    var form = document.getElementById('lc-form');
    form.action = loc ? lcUpdateUrl.replace('__ID__', loc._id) : lcStoreUrl;
    document.getElementById('lc-title').textContent = loc ? 'Edit location' : 'Add location';
    document.getElementById('lc-submit').textContent = loc ? 'Save location' : 'Add location';
    document.getElementById('lc-old').value = loc ? (loc.discogs_code || '') : '';
    document.getElementById('lc-code').value = loc ? (loc.discogs_code || '') : '';
    document.getElementById('lc-store').value = loc ? (loc.store || '') : '';
    lcZones(loc ? (loc.zone || '') : '');
    document.getElementById('lc-desc').value = loc ? (loc.description || '') : '';
    document.getElementById('lc-sub').value = loc ? (loc.sub_location || '') : '';
    document.getElementById('lc-shelf').value = loc ? (loc.shelf_details || '') : '';
    document.getElementById('lc-usage').textContent = loc && loc.usage_count
      ? 'Used ' + loc.usage_count + ' time' + (loc.usage_count === 1 ? '' : 's') + (loc.last_used ? ', last on ' + new Date(loc.last_used).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : '') + '.'
      : '';
    document.getElementById('lc-ack').checked = false;
    document.getElementById('lc-err').style.display = 'none';
    lcWarn();
    document.getElementById('lc-dialog').showModal();
  }

  function lcChanged() {
    var v = document.getElementById('lc-code').value.trim().replace(/\s+/g, ' ');
    return !!(lcEditing && lcEditing.discogs_code && v.toUpperCase() !== String(lcEditing.discogs_code).trim().toUpperCase());
  }
  function lcWarn() { document.getElementById('lc-warn').style.display = lcChanged() ? 'block' : 'none'; }
  document.getElementById('lc-code').addEventListener('input', lcWarn);

  function lcCheck() {
    var err = document.getElementById('lc-err');
    if (lcChanged() && !document.getElementById('lc-ack').checked) {
      err.textContent = 'Tick the box to confirm you will change the code on Discogs too.';
      err.style.display = 'block';
      return false;
    }
    return true;
  }

  function lcCount() {
    var el = document.getElementById('lc-selcount');
    if (el) el.textContent = document.querySelectorAll('.lc-code:checked').length + ' selected';
  }
  function lcAll(on) {
    document.querySelectorAll('.lc-code').forEach(function (c) { c.checked = on; });
    lcCount();
  }
</script>
@endsection
