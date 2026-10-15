@extends('layouts.app')

@section('title', 'Website Products')

@section('content')
@include('website_admin.catalog._styles')
@php
  $img = [\App\Http\Controllers\WebsiteAdmin\CatalogBaseController::class, 'img'];
  $sourceName = function ($s) {
    switch ($s) {
      case 'pos': case 'ultimatepos': return ['POS', 'blue'];
      case 'discogs': return ['Discogs', 'green'];
      case 'both': return ['Multiple', 'orange'];
      default: return ['System', 'purple'];
    }
  };
  $sortUrl = function ($col) use ($f) {
    $order = ($f['sortBy'] === $col && $f['sortOrder'] === 'asc') ? 'desc' : 'asc';
    return request()->fullUrlWithQuery(['sortBy' => $col, 'sortOrder' => $order, 'page' => 1]);
  };
  $arrow = function ($col) use ($f) {
    return $f['sortBy'] === $col ? ($f['sortOrder'] === 'asc' ? ' &uarr;' : ' &darr;') : '';
  };
@endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Website Products</h1>
      <p class="sub">Products listed on nivessa.com. New products come from Add Product in the ERP and the POS sync, so there is no add button here.</p>
    </div>
  </div>
  @include('website_admin.catalog._nav')

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @if($bridgeError)<div class="alert-err">{{ $bridgeError }}</div>@endif

  <form method="POST" action="{{ route('wa.catalog.products.priceUnpublish.preview') }}" class="wc-tool">
    {{ csrf_field() }}
    <strong>Bulk unpublish over $</strong>
    <input type="number" name="threshold" min="0" step="1" value="{{ $preview['threshold'] ?? 2000 }}">
    <button type="submit" class="wc-btn primary">Find and unpublish</button>
    <span>Hides every published product at or above this price. You can undo it right after.</span>
  </form>
  @if(!empty($undoIds))
    <form method="POST" action="{{ route('wa.catalog.products.priceUnpublish.undo') }}" class="wc-tool">
      {{ csrf_field() }}
      <span>{{ count($undoIds) }} product(s) were just unpublished by price.</span>
      <button type="submit" class="wc-btn">Undo ({{ count($undoIds) }})</button>
    </form>
  @endif

  @if($preview)
    <div class="wc-card pad">
      <h2>Unpublish {{ count($preview['items']) }} product(s) over ${{ number_format($preview['threshold'], 2) }}?</h2>
      <p class="wc-muted">These will be hidden from the storefront and locked so the POS sync cannot publish them again. You can undo this right after.</p>
      <div style="max-height:260px; overflow-y:auto; border:1px solid #e5e7eb; border-radius:8px; margin-bottom:12px;">
        <table class="wc-tbl">
          <tbody>
            @foreach(array_slice($preview['items'], 0, 200) as $it)
              <tr><td>{{ $it['name'] }}</td><td style="text-align:right; white-space:nowrap;">${{ number_format($it['price'], 2) }}</td></tr>
            @endforeach
          </tbody>
        </table>
      </div>
      @if(count($preview['items']) > 200)<p class="wc-muted">Plus {{ count($preview['items']) - 200 }} more.</p>@endif
      <form method="POST" action="{{ route('wa.catalog.products.priceUnpublish.run') }}" style="display:flex; gap:8px;">
        {{ csrf_field() }}
        <button type="submit" class="wc-btn primary" @if(count($preview['items']) === 0) disabled @endif>Unpublish {{ count($preview['items']) }}</button>
        <button type="submit" name="cancel" value="1" class="wc-btn">Cancel</button>
      </form>
    </div>
  @endif

  <form method="GET" action="{{ route('wa.catalog.products') }}" class="wc-filters">
    <div class="f" style="flex:1 1 240px;"><label>Search</label><input type="text" name="search" value="{{ $f['search'] }}" placeholder="Search products"></div>
    <div class="f"><label>Stock</label>
      <select name="availability">
        @foreach(['all' => 'All', 'In Stock' => 'In Stock', 'Out Of Stock' => 'Out of Stock'] as $v => $l)
          <option value="{{ $v }}" @if($f['availability'] === $v) selected @endif>{{ $l }}</option>
        @endforeach
      </select>
    </div>
    <div class="f"><label>Source</label>
      <select name="source">
        @foreach(['all' => 'All Sources', 'discogs' => 'Discogs', 'pos' => 'POS', 'manual' => 'System', 'both' => 'Multiple'] as $v => $l)
          <option value="{{ $v }}" @if($f['source'] === $v) selected @endif>{{ $l }}</option>
        @endforeach
      </select>
    </div>
    <div class="f"><label>Sort by</label>
      <select name="sortBy">
        @foreach(['name' => 'Name', 'category' => 'Category', 'quantity' => 'Quantity', 'price' => 'Price'] as $v => $l)
          <option value="{{ $v }}" @if($f['sortBy'] === $v) selected @endif>{{ $l }}</option>
        @endforeach
      </select>
    </div>
    <div class="f"><label>Order</label>
      <select name="sortOrder">
        <option value="asc" @if($f['sortOrder'] === 'asc') selected @endif>Ascending</option>
        <option value="desc" @if($f['sortOrder'] === 'desc') selected @endif>Descending</option>
      </select>
    </div>
    <div class="f"><label>Per page</label>
      <select name="limit">
        @foreach([15, 25, 50, 100, 200] as $n)
          <option value="{{ $n }}" @if($f['limit'] === $n) selected @endif>{{ $n }}</option>
        @endforeach
      </select>
    </div>
    <button type="submit" class="wc-btn primary">Apply</button>
  </form>

  <form method="POST" action="{{ route('wa.catalog.products.bulk') }}" id="wcBulkForm" onsubmit="return wcBulkSubmit();">
    {{ csrf_field() }}
    <div class="wc-bulkbar" id="wcBulkBar">
      <strong><span id="wcSelCount">0</span> selected</strong>
      <select name="action" id="wcBulkAction" onchange="wcBulkActionChanged()">
        <option value="">Choose an action</option>
        <optgroup label="Publish status">
          <option value="publish">Publish</option>
          <option value="unpublish">Unpublish (hide)</option>
        </optgroup>
        <optgroup label="Sell on homepage">
          <option value="onSale">Mark as on sale</option>
          <option value="offSale">Remove from sale</option>
        </optgroup>
        <optgroup label="Price">
          <option value="changePrice">Set price</option>
          <option value="increasePrice">Increase price by %</option>
          <option value="decreasePrice">Decrease price by %</option>
        </optgroup>
        <optgroup label="Inventory">
          <option value="changeQuantity">Set quantity</option>
        </optgroup>
        <optgroup label="Details">
          <option value="changeProductType">Change product type</option>
          <option value="changeGenre">Change genre</option>
          <option value="changeFormat">Change format</option>
        </optgroup>
        <optgroup label="Danger">
          <option value="delete">Delete selected</option>
        </optgroup>
      </select>
      <input type="number" step="0.01" min="0" name="value" id="wcBulkNum" placeholder="Value" style="display:none; width:120px;" disabled>
      <select name="value" id="wcBulk_changeProductType" class="wcBulkPick" style="display:none;" disabled>
        <option value="">Pick a product type</option>
        @foreach($filterMeta['productTypes'] as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
      </select>
      <select name="value" id="wcBulk_changeGenre" class="wcBulkPick" style="display:none;" disabled>
        <option value="">Pick a genre</option>
        @foreach($filterMeta['genres'] as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
      </select>
      <select name="value" id="wcBulk_changeFormat" class="wcBulkPick" style="display:none;" disabled>
        <option value="">Pick a format</option>
        @foreach($filterMeta['formats'] as $n)<option value="{{ $n }}">{{ $n }}</option>@endforeach
      </select>
      <button type="submit" class="wc-btn primary">Apply</button>
      <button type="button" class="wc-btn" onclick="wcClearSel()">Clear selection</button>
    </div>

    <div class="wc-card">
      <div class="wc-scroll">
        <table class="wc-tbl" style="min-width:1200px;">
          <thead>
            <tr>
              <th style="width:34px;"><input type="checkbox" id="wcAll" onchange="wcSelectAll(this.checked)"></th>
              <th style="width:44px;">#</th>
              <th><a href="{{ $sortUrl('name') }}">Title{!! $arrow('name') !!}</a></th>
              <th><a href="{{ $sortUrl('category') }}">Type{!! $arrow('category') !!}</a></th>
              <th>Source</th>
              <th>Image</th>
              <th><a href="{{ $sortUrl('quantity') }}">Qty{!! $arrow('quantity') !!}</a></th>
              <th><a href="{{ $sortUrl('price') }}">Price{!! $arrow('price') !!}</a></th>
              <th>Sell on homepage</th>
              <th>Status</th>
              <th>Last changed by</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            @forelse($products as $i => $p)
              @php
                $pid = (string) ($p['_id'] ?? '');
                $sub = $p['subCategory'] ?? '';
                if (is_array($sub)) { $sub = $sub['name'] ?? ($sub['_id'] ?? ''); }
                $src = $sourceName($p['syncSource'] ?? '');
                $published = ($p['published'] ?? true) !== false;
              @endphp
              <tr data-id="{{ $pid }}">
                <td>
                  <input type="checkbox" class="wcRow" name="ids[]" value="{{ $pid }}" onchange="wcSelChanged()">
                  <input type="hidden" name="prices[{{ $pid }}]" value="{{ (float) ($p['price'] ?? 0) }}">
                </td>
                <td class="wc-muted">{{ ($page - 1) * $f['limit'] + $i + 1 }}</td>
                <td><a href="{{ route('wa.catalog.products.show', $pid) }}" style="font-weight:600; color:#111827;">{{ $p['name'] ?? 'Untitled' }}</a>
                  @if(!empty($p['artist']))<div class="wc-muted">{{ is_array($p['artist']) ? implode(', ', $p['artist']) : $p['artist'] }}</div>@endif
                </td>
                <td>{{ $typeNames[$sub] ?? ($sub ?: 'N/A') }}</td>
                <td><span class="wc-pill {{ $src[1] }}">{{ $src[0] }}</span></td>
                <td>
                  @if(!empty($p['image']))<img src="{{ $img($p['image']) }}" class="wc-img" loading="lazy" alt="">@else<div class="wc-img"></div>@endif
                </td>
                <td>{{ $p['quantity'] ?? 0 }}</td>
                <td>${{ number_format((float) ($p['price'] ?? 0), 2) }}</td>
                <td>
                  <label style="display:flex; gap:6px; align-items:center; margin:0; font-weight:400;">
                    <input type="checkbox" @if(!empty($p['onSale'])) checked @endif onchange="wcToggleSale('{{ $pid }}', this)">
                    <span class="wc-muted wcSaleLbl">{{ !empty($p['onSale']) ? 'Yes' : 'No' }}</span>
                  </label>
                </td>
                <td>
                  @if((int) ($p['quantity'] ?? 0) > 0)<span class="wc-pill green">In Stock</span>@else<span class="wc-pill red">Out Of Stock</span>@endif
                  <div style="margin-top:4px;"><span class="wc-pill wcPubPill {{ $published ? 'blue' : 'gray' }}">{{ $published ? 'Published' : 'Hidden' }}</span></div>
                </td>
                <td class="wc-muted">
                  {{ $p['lastModifiedBy'] ?? 'System' }}
                  @if(!empty($p['createdAt']))<br>{{ \Carbon\Carbon::parse($p['createdAt'])->setTimezone('America/Los_Angeles')->format('M j, Y g:i A') }}@endif
                </td>
                <td>
                  <div class="wc-actions">
                    <span class="wc-lock wcLock" title="Sync-locked: no sync job will publish or restock this product" @if(empty($p['adminUnpublished'])) style="display:none;" @endif>Locked</span>
                    <button type="button" class="wc-btn wcPubBtn" data-published="{{ $published ? '1' : '0' }}" onclick="wcTogglePublish('{{ $pid }}', this)">{{ $published ? 'Hide' : 'Publish' }}</button>
                    <a href="{{ route('wa.catalog.products.edit', $pid) }}" class="wc-btn primary">Edit</a>
                    <button type="button" class="wc-btn danger" onclick="wcDeleteOne('{{ $pid }}')">Delete</button>
                  </div>
                </td>
              </tr>
            @empty
              <tr><td colspan="12" class="empty">No products match these filters.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </form>

  <form method="POST" id="wcDeleteForm" style="display:none;">{{ csrf_field() }}</form>

  <div class="wc-pager">
    @if($page > 1)<a class="wc-btn" href="{{ request()->fullUrlWithQuery(['page' => $page - 1]) }}">Previous</a>@endif
    <span>Page {{ $page }} of {{ $pages }} ({{ number_format($total) }} products)</span>
    @if($page < $pages)<a class="wc-btn" href="{{ request()->fullUrlWithQuery(['page' => $page + 1]) }}">Next</a>@endif
  </div>
</div>

<script>
  var wcCsrf = '{{ csrf_token() }}';
  var wcPublishUrl = '{{ route('wa.catalog.products.publish', '__ID__') }}';
  var wcSaleUrl = '{{ route('wa.catalog.products.sale', '__ID__') }}';
  var wcDeleteUrl = '{{ route('wa.catalog.products.delete', '__ID__') }}';

  function wcPost(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': wcCsrf },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json().catch(function () { return { success: false, message: 'Unexpected reply.' }; }); });
  }
  function wcTogglePublish(id, btn) {
    var next = btn.getAttribute('data-published') !== '1';
    btn.disabled = true;
    wcPost(wcPublishUrl.replace('__ID__', id), { published: next }).then(function (j) {
      btn.disabled = false;
      if (!j.success) { alert(j.message || 'Could not change the publish status.'); return; }
      var row = btn.closest('tr');
      btn.setAttribute('data-published', next ? '1' : '0');
      btn.textContent = next ? 'Hide' : 'Publish';
      var pill = row.querySelector('.wcPubPill');
      pill.textContent = next ? 'Published' : 'Hidden';
      pill.className = 'wc-pill wcPubPill ' + (next ? 'blue' : 'gray');
      row.querySelector('.wcLock').style.display = next ? 'none' : '';
    });
  }
  function wcToggleSale(id, box) {
    var next = box.checked;
    box.disabled = true;
    wcPost(wcSaleUrl.replace('__ID__', id), { onSale: next }).then(function (j) {
      box.disabled = false;
      if (!j.success) { box.checked = !next; alert(j.message || 'Could not update the sale status.'); return; }
      box.parentNode.querySelector('.wcSaleLbl').textContent = next ? 'Yes' : 'No';
    });
  }
  function wcDeleteOne(id) {
    if (!window.confirm('Delete this product from the website? This cannot be undone.')) return;
    var f = document.getElementById('wcDeleteForm');
    f.action = wcDeleteUrl.replace('__ID__', id);
    f.submit();
  }
  function wcRows() { return Array.prototype.slice.call(document.querySelectorAll('.wcRow')); }
  function wcSelChanged() {
    var n = 0;
    wcRows().forEach(function (b) { b.closest('tr').classList.toggle('sel', b.checked); if (b.checked) n++; });
    document.getElementById('wcSelCount').textContent = n;
    document.getElementById('wcBulkBar').classList.toggle('on', n > 0);
    document.getElementById('wcAll').checked = n > 0 && n === wcRows().length;
  }
  function wcSelectAll(on) { wcRows().forEach(function (b) { b.checked = on; }); wcSelChanged(); }
  function wcClearSel() { wcSelectAll(false); }
  function wcBulkActionChanged() {
    var a = document.getElementById('wcBulkAction').value;
    var num = document.getElementById('wcBulkNum');
    var numeric = ['changePrice', 'increasePrice', 'decreasePrice', 'changeQuantity'].indexOf(a) !== -1;
    num.style.display = numeric ? '' : 'none';
    num.disabled = !numeric;
    num.step = a === 'changePrice' ? '0.01' : '1';
    num.placeholder = a === 'changePrice' ? 'New price' : (a === 'changeQuantity' ? 'New quantity' : 'Percent');
    document.querySelectorAll('.wcBulkPick').forEach(function (s) {
      var on = s.id === 'wcBulk_' + a;
      s.style.display = on ? '' : 'none';
      s.disabled = !on;
    });
  }
  function wcBulkSubmit() {
    var a = document.getElementById('wcBulkAction').value;
    var n = document.querySelectorAll('.wcRow:checked').length;
    if (!a) { alert('Choose an action first.'); return false; }
    if (a === 'delete') return window.confirm('Delete ' + n + ' product(s) from the website? This cannot be undone.');
    return true;
  }
</script>
@endsection
