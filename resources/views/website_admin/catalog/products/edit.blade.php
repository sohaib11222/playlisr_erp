@extends('layouts.app')

@section('title', 'Edit Website Product')

@section('content')
@include('website_admin.catalog._styles')
@php
  $img = [\App\Http\Controllers\WebsiteAdmin\CatalogBaseController::class, 'img'];
  $pid = (string) ($p['_id'] ?? '');
  $published = ($p['published'] ?? true) !== false;
  $split = function ($v) {
    if (is_array($v)) return array_values(array_filter(array_map('trim', array_filter($v, 'is_scalar'))));
    return array_values(array_filter(array_map('trim', explode(',', (string) $v))));
  };
  $loc = $p['location'] ?? null;
  $wh = is_array($loc) ? $loc : [];
  $sub = $p['subCategory'] ?? '';
  if (is_array($sub)) { $sub = $sub['_id'] ?? ($sub['name'] ?? ''); }
  $v = function ($k, $default = '') { return old($k, $default); };
  $genres = $split(old('genre', implode(', ', $split($p['genre'] ?? ''))));
  $formats = $split(old('format', implode(', ', $split($p['format'] ?? ''))));
  $whStore = old('warehouseStore', $wh['store'] ?? '');
  $whZone = old('warehouseZone', $wh['zone'] ?? '');
  $isPre = old('_submitted') ? (bool) old('isPreorder') : !empty($p['isPreorder']);
  $shipDate = old('preorderShipDate', !empty($p['preorderShipDate']) ? substr((string) $p['preorderShipDate'], 0, 10) : '');
  $zones = [
    'Hollywood' => ['Hollywood Upstairs', 'Hollywood Main Floor', 'Hollywood Basement', 'Hollywood Basement Small Room'],
    'Pico' => ['Pico Main Floor', 'Pico Storage'],
    'Warehouse' => ['Warehouse'],
    'Other' => ['Other'],
  ];
  $types = $metadata['productType'];
  if ($sub !== '' && !in_array($sub, $types, true)) { array_unshift($types, $sub); }
@endphp

<div class="ev-wrap">
  <div class="ev-head">
    <div>
      <h1>Edit website product</h1>
      <p class="sub">{{ $p['name'] ?? '' }}</p>
    </div>
    <div class="wc-actions">
      <a href="{{ route('wa.catalog.products') }}" class="wc-btn">Back to products</a>
      <a href="{{ route('wa.catalog.products.show', $pid) }}" class="wc-btn">View details</a>
    </div>
  </div>
  @include('website_admin.catalog._nav')

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  <div class="ev-card">
    <h2>Website visibility</h2>
    <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
      <span class="wc-pill {{ $published ? 'blue' : 'gray' }}">{{ $published ? 'Published' : 'Hidden' }}</span>
      @if(!empty($p['adminUnpublished']))<span class="wc-lock" title="No sync job will publish or restock this product">Locked against the POS sync</span>@endif
      <span class="wc-pill {{ !empty($p['onSale']) ? 'orange' : 'gray' }}">{{ !empty($p['onSale']) ? 'On sale' : 'Not on sale' }}</span>
      <form method="POST" action="{{ route('wa.catalog.products.publish', $pid) }}" style="margin:0;">
        {{ csrf_field() }}
        <input type="hidden" name="published" value="{{ $published ? '0' : '1' }}">
        <button type="submit" class="wc-btn">{{ $published ? 'Hide from website' : 'Publish' }}</button>
      </form>
      <form method="POST" action="{{ route('wa.catalog.products.sale', $pid) }}" style="margin:0;">
        {{ csrf_field() }}
        <input type="hidden" name="onSale" value="{{ !empty($p['onSale']) ? '0' : '1' }}">
        <button type="submit" class="wc-btn">{{ !empty($p['onSale']) ? 'Remove from sale' : 'Mark on sale' }}</button>
      </form>
      @if(!empty($p['slug']))<span class="wc-muted">Slug: {{ $p['slug'] }}</span>@endif
    </div>
  </div>

  <form method="POST" action="{{ route('wa.catalog.products.update', $pid) }}" enctype="multipart/form-data" class="ev-card wc-form" onsubmit="wcSyncChips()">
    {{ csrf_field() }}
    <input type="hidden" name="_submitted" value="1">
    <input type="hidden" name="discogsReleaseId" value="{{ $p['discogsReleaseId'] ?? '' }}">
    <h2>Product details</h2>

    <div class="ev-row">
      <div class="ev-field"><label>Product type *</label>
        <select name="parentCollection" required>
          <option value="">Select</option>
          @foreach($types as $t)
            <option value="{{ $t }}" @if($v('parentCollection', $sub) === $t) selected @endif>{{ $typeNames[$t] ?? $t }}</option>
          @endforeach
        </select>
      </div>
      <div class="ev-field"><label>SKU *</label><input type="text" name="sku" value="{{ $v('sku', $p['sku'] ?? '') }}" required></div>
    </div>
    <div class="ev-row">
      <div class="ev-field wide"><label>Product name *</label><input type="text" name="name" value="{{ $v('name', $p['name'] ?? '') }}" placeholder="Clairo / Diary 001" required></div>
    </div>
    <div class="ev-row">
      <div class="ev-field"><label>Artist</label><input type="text" name="artist" value="{{ $v('artist', is_array($p['artist'] ?? null) ? implode(', ', $p['artist']) : ($p['artist'] ?? '')) }}"></div>
      <div class="ev-field"><label>Label</label><input type="text" name="label" value="{{ $v('label', $p['label'] ?? '') }}" placeholder="London Records"></div>
    </div>
    <div class="ev-row">
      <div class="ev-field"><label>Catalog number</label><input type="text" name="categoryNumber" value="{{ $v('categoryNumber', $p['categoryNumber'] ?? '') }}"></div>
      <div class="ev-field"><label>eBay ID</label><input type="text" name="ebayid" value="{{ $v('ebayid', $p['ebayid'] ?? '') }}"></div>
    </div>
    <div class="ev-row">
      <div class="ev-field"><label>Location @if(is_array($loc))<span class="wc-muted">(set by the warehouse fields below)</span>@endif</label>
        <input type="text" name="location" value="{{ $v('location', is_string($loc) ? $loc : '') }}" placeholder="Los Angeles, CA">
      </div>
      <div class="ev-field"><label>Condition</label>
        <select name="condition">
          <option value="">Select</option>
          @foreach(['Very Good (VG+)', 'Very Good', 'Near Mint (NM or M-)', 'Mint (M)', 'Good Plus (G+)', 'Good (G)'] as $c)
            <option value="{{ $c }}" @if($v('condition', $p['condition'] ?? '') === $c) selected @endif>{{ $c }}</option>
          @endforeach
          @if(!empty($p['condition']) && !in_array($p['condition'], ['Very Good (VG+)', 'Very Good', 'Near Mint (NM or M-)', 'Mint (M)', 'Good Plus (G+)', 'Good (G)'], true))
            <option value="{{ $p['condition'] }}" selected>{{ $p['condition'] }}</option>
          @endif
        </select>
      </div>
    </div>

    <h2 style="margin-top:8px;">Warehouse location</h2>
    <div class="ev-row">
      <div class="ev-field"><label>Store</label>
        <select name="warehouseStore" id="wcWhStore" onchange="wcZones()">
          <option value="">Select store</option>
          @foreach(['Hollywood' => 'Hollywood', 'Pico' => 'Pico', 'Warehouse' => 'Warehouse (offsite, next to Hollywood)', 'Other' => 'Other'] as $sv => $sl)
            <option value="{{ $sv }}" @if($whStore === $sv) selected @endif>{{ $sl }}</option>
          @endforeach
        </select>
      </div>
      <div class="ev-field"><label>Zone</label>
        <select name="warehouseZone" id="wcWhZone" data-current="{{ $whZone }}"></select>
      </div>
    </div>
    <div class="ev-row">
      <div class="ev-field"><label>Sub location</label><input type="text" name="warehouseSubLocation" value="{{ old('warehouseSubLocation', $wh['sub_location'] ?? '') }}" placeholder="Aisle 3, Section B"></div>
      <div class="ev-field"><label>Shelf details</label><input type="text" name="warehouseShelfDetails" value="{{ old('warehouseShelfDetails', $wh['shelf_details'] ?? '') }}" placeholder="Shelf 3B, Row 2"></div>
    </div>

    <h2 style="margin-top:8px;">Genre and format</h2>
    <div class="ev-row">
      <div class="ev-field">
        <label>Genres</label>
        <div style="display:flex; gap:6px;">
          <select id="wcGenrePick" style="flex:1;">
            <option value="">Select a genre</option>
            @foreach($metadata['genre'] as $g)<option value="{{ $g }}">{{ $g }}</option>@endforeach
          </select>
          <button type="button" class="wc-btn" onclick="wcAddChip('genre', document.getElementById('wcGenrePick').value)">Add</button>
        </div>
        <div class="wc-chips" id="wcChips_genre"></div>
        <input type="hidden" name="genre" id="wcVal_genre" value="{{ implode(', ', $genres) }}">
      </div>
      <div class="ev-field">
        <label>Formats</label>
        <div style="display:flex; gap:6px;">
          <select id="wcFormatPick" style="flex:1;">
            <option value="">Select a format</option>
            @foreach($metadata['format'] as $fm)<option value="{{ $fm }}">{{ $fm }}</option>@endforeach
          </select>
          <button type="button" class="wc-btn" onclick="wcAddChip('format', document.getElementById('wcFormatPick').value)">Add</button>
        </div>
        <div style="display:flex; gap:6px; margin-top:6px;">
          <input type="text" id="wcFormatCustom" placeholder="Other format, like Picture Disc" style="flex:1;">
          <button type="button" class="wc-btn" onclick="wcAddChip('format', document.getElementById('wcFormatCustom').value); document.getElementById('wcFormatCustom').value='';">Add</button>
        </div>
        <div class="wc-chips" id="wcChips_format"></div>
        <input type="hidden" name="format" id="wcVal_format" value="{{ implode(', ', $formats) }}">
      </div>
    </div>

    <h2 style="margin-top:8px;">Image</h2>
    <div class="ev-row">
      <div class="ev-field" style="flex:0 0 120px;">
        @if(!empty($p['image']))<img src="{{ $img($p['image']) }}" alt="" style="width:110px; height:110px; object-fit:cover; border-radius:8px; border:1px solid #e5e7eb;">@else<div class="wc-img" style="width:110px; height:110px;"></div>@endif
      </div>
      <div class="ev-field">
        <label>Upload a new image (under 2 MB)</label>
        <input type="file" name="image" accept="image/*">
        <label style="margin-top:8px;">Or paste an image URL</label>
        <input type="text" name="image_url" value="{{ old('image_url', $p['image'] ?? '') }}" placeholder="https://example.com/image.jpg">
        <span class="wc-muted">Leave as is to keep the current image.</span>
        @if(!empty($p['discogsReleaseId']))
          <label style="font-weight:400; display:flex; gap:6px; align-items:center; margin-top:8px;">
            <input type="checkbox" name="use_discogs_cover" value="1"> Replace it with the Discogs cover for release {{ $p['discogsReleaseId'] }} when I save
          </label>
        @endif
      </div>
    </div>

    <h2 style="margin-top:8px;">Price and stock</h2>
    <div class="ev-row">
      <div class="ev-field"><label>Price *</label><input type="number" step="0.01" min="0" name="price" value="{{ $v('price', $p['price'] ?? '') }}" required></div>
      <div class="ev-field"><label>Quantity *</label><input type="number" step="1" min="0" name="quantity" value="{{ $v('quantity', $p['quantity'] ?? '') }}" required></div>
      <div class="ev-field"><label>Availability</label>
        <select name="availability">
          <option value="">Select</option>
          @foreach(['In Stock', 'Out Of Stock', 'Preorder'] as $a)
            <option value="{{ $a }}" @if($v('availability', $p['availability'] ?? '') === $a) selected @endif>{{ $a }}</option>
          @endforeach
        </select>
      </div>
    </div>
    <div class="ev-row">
      <div class="ev-checks">
        <label><input type="checkbox" name="isPreorder" value="1" id="wcPre" onchange="document.getElementById('wcShip').style.display = this.checked ? '' : 'none';" @if($isPre) checked @endif> This is a preorder product</label>
      </div>
      <div class="ev-field" id="wcShip" @if(!$isPre) style="display:none;" @endif><label>Preorder ship date</label><input type="date" name="preorderShipDate" value="{{ $shipDate }}"></div>
    </div>
    <div class="ev-row">
      <div class="ev-field wide"><label>Description</label><textarea name="description" rows="4">{{ $v('description', is_string($p['description'] ?? null) ? $p['description'] : '') }}</textarea></div>
    </div>

    <button type="submit" class="btn-accent">Save product</button>
  </form>
</div>

<script>
  var wcZoneMap = @json($zones);
  function wcZones() {
    var store = document.getElementById('wcWhStore').value;
    var sel = document.getElementById('wcWhZone');
    var cur = sel.value || sel.getAttribute('data-current') || '';
    sel.innerHTML = '<option value="">Select zone</option>';
    (wcZoneMap[store] || []).forEach(function (z) {
      var o = document.createElement('option');
      o.value = z; o.textContent = z; if (z === cur) o.selected = true;
      sel.appendChild(o);
    });
    sel.setAttribute('data-current', '');
  }
  var wcChips = {
    genre: @json($genres),
    format: @json($formats)
  };
  function wcRenderChips(kind) {
    var box = document.getElementById('wcChips_' + kind);
    box.innerHTML = '';
    wcChips[kind].forEach(function (val, i) {
      var c = document.createElement('span');
      c.className = 'wc-chip';
      c.textContent = val + ' ';
      var b = document.createElement('button');
      b.type = 'button'; b.textContent = '×'; b.title = 'Remove';
      b.onclick = function () { wcChips[kind].splice(i, 1); wcRenderChips(kind); };
      c.appendChild(b);
      box.appendChild(c);
    });
    document.getElementById('wcVal_' + kind).value = wcChips[kind].join(', ');
  }
  function wcAddChip(kind, val) {
    val = (val || '').trim();
    if (!val || wcChips[kind].indexOf(val) !== -1) return;
    wcChips[kind].push(val);
    wcRenderChips(kind);
  }
  function wcSyncChips() { wcRenderChips('genre'); wcRenderChips('format'); }
  wcZones(); wcRenderChips('genre'); wcRenderChips('format');
</script>
@endsection
