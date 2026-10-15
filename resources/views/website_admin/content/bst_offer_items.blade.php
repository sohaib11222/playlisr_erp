@extends('layouts.app')
@section('title', 'BST Offer Items')

@section('content')
@include('website_admin.content._styles')
@php
  $price = function ($min, $max) {
      if (!$min && !$max) return '';
      if ($min == $max || !$max) return '$' . $min;
      if (!$min) return 'up to $' . $max;
      return '$' . $min . ' to $' . $max;
  };
@endphp
<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>BST Offer Items</h1>
      <p class="sub">Items Nivessa will buy, shown as suggestions with price ranges on nivessa.com/buy-sell-trade. Add artist names, formats or collectible categories so customer searches route correctly even when the words aren't in a page title. See what people search on <a href="{{ route('wa.content.bstSearchQueries') }}">Buy/Sell/Trade Searches</a>.</p>
    </div>
    <button type="button" class="wc-btn wc-btn-primary" onclick="boOpen(null)">Add item</button>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  <form method="GET" class="wc-filters">
    <div class="wc-field" style="flex:1; min-width:220px;"><label>Search</label><input type="text" name="search" value="{{ $search }}" placeholder="Search label, keywords, category"></div>
    <button type="submit" class="wc-btn wc-btn-primary">Search</button>
    @if($search !== '')<a class="wc-btn" href="{{ route('wa.content.bstOfferItems') }}">Clear</a>@endif
  </form>

  <div class="wc-card">
    <div class="wc-card-head"><div><h2>Items ({{ number_format($total) }})</h2><div class="wc-muted">Keywords are comma-separated words customers might type, like "outkast, hip hop vinyl". The BST page slug links the item to a sub-page for smart routing.</div></div></div>
    @if($bridgeError)
      <div class="wc-empty" style="color:#b91c1c;">{{ $bridgeError }}</div>
    @elseif(count($items) === 0)
      <div class="wc-empty">No items yet. Click "Add item" to make the first one.</div>
    @else
    <div class="wc-scroll">
      <table class="wc-tbl">
        <thead><tr><th>Label</th><th>Keywords</th><th>Category</th><th>Price range</th><th>BST page</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @foreach($items as $it)
          @php $kw = is_array($it['keywords'] ?? null) ? $it['keywords'] : []; @endphp
          <tr>
            <td style="font-weight:600; max-width:200px;">{{ $it['label'] ?? '' }}</td>
            <td style="max-width:240px;">
              @foreach(array_slice($kw, 0, 4) as $k)<span class="wc-pill" style="margin:0 3px 3px 0;">{{ $k }}</span>@endforeach
              @if(count($kw) > 4)<span class="wc-muted">+{{ count($kw) - 4 }}</span>@endif
            </td>
            <td>{{ $it['category'] ?? '' }}</td>
            <td style="color:#15803d; font-weight:600; white-space:nowrap;">{{ $price($it['priceMin'] ?? 0, $it['priceMax'] ?? 0) }}</td>
            <td class="wc-mono" style="color:#1d4ed8;">{{ $it['bstPageSlug'] ?? '' }}</td>
            <td>
              <form method="POST" action="{{ route('wa.content.bstOfferItemsToggle', $it['_id']) }}" style="margin:0;">
                {{ csrf_field() }}
                <input type="hidden" name="active" value="{{ !empty($it['active']) ? '0' : '1' }}">
                <button type="submit" class="wc-pill {{ !empty($it['active']) ? 'green' : '' }}" title="Click to switch">{{ !empty($it['active']) ? 'Active' : 'Inactive' }}</button>
              </form>
            </td>
            <td>
              <div class="wc-actions" style="justify-content:flex-end;">
                <button type="button" class="wc-btn wc-btn-sm" onclick='boOpen(@json($it))'>Edit</button>
                <form method="POST" action="{{ route('wa.content.bstOfferItemsDelete', $it['_id']) }}" onsubmit="return confirm({{ json_encode('Delete "' . ($it['label'] ?? '') . '"? It will be permanently removed.') }});">
                  {{ csrf_field() }}
                  <button type="submit" class="wc-btn wc-btn-sm wc-btn-danger">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    @endif
    @include('website_admin.content._pager')
  </div>
</div>

<dialog class="wc-dialog" id="bo-dialog">
  <form method="POST" id="bo-form" class="wc-dialog-body" action="{{ route('wa.content.bstOfferItemsStore') }}">
    {{ csrf_field() }}
    <h3 id="bo-title">New offer item</h3>
    <div class="wc-grid">
      <div class="wc-field wc-span2"><label>Label (required)</label><input type="text" name="label" id="bo-label" placeholder="e.g. Outkast Vinyl Records" required></div>
      <div class="wc-field wc-span2"><label>Keywords (comma separated)</label><input type="text" name="keywords" id="bo-keywords" placeholder="e.g. outkast, stankonia, hip hop vinyl, rap vinyl"><div class="wc-help">When a customer types any of these words, this item shows in the dropdown.</div></div>
      <div class="wc-field"><label>Category</label><input type="text" name="category" id="bo-category" placeholder="e.g. Vinyl Records"></div>
      <div class="wc-field"><label>Display order</label><input type="number" name="displayOrder" id="bo-displayOrder" placeholder="0"></div>
      <div class="wc-field"><label>Min price ($)</label><input type="number" name="priceMin" id="bo-priceMin" min="0" step="any" placeholder="e.g. 5"></div>
      <div class="wc-field"><label>Max price ($)</label><input type="number" name="priceMax" id="bo-priceMax" min="0" step="any" placeholder="e.g. 30"></div>
      <div class="wc-field wc-span2"><label>BST page slug (for smart routing)</label><input type="text" name="bstPageSlug" id="bo-bstPageSlug" class="wc-mono" placeholder="e.g. buy-sell-trade/hip-hop-rap-vinyl"><div class="wc-help">When a customer searches for this item, they go to this sub-page.</div></div>
      <label class="wc-check wc-span2"><input type="checkbox" name="active" id="bo-active" value="1" checked> Active (shows in suggestions)</label>
    </div>
    <div class="wc-dialog-actions">
      <button type="button" class="wc-btn" onclick="document.getElementById('bo-dialog').close()">Cancel</button>
      <button type="submit" class="wc-btn wc-btn-primary" id="bo-submit">Create item</button>
    </div>
  </form>
</dialog>
<script>
  var BO_STORE = @json(route('wa.content.bstOfferItemsStore'));
  var BO_BASE = @json(url('/website-content/bst-offer-items')) + '/';
  function boOpen(it) {
    document.getElementById('bo-form').action = it ? BO_BASE + encodeURIComponent(it._id) : BO_STORE;
    document.getElementById('bo-title').textContent = it ? 'Edit offer item' : 'New offer item';
    document.getElementById('bo-submit').textContent = it ? 'Save changes' : 'Create item';
    var d = it || {};
    document.getElementById('bo-label').value = d.label || '';
    document.getElementById('bo-keywords').value = Array.isArray(d.keywords) ? d.keywords.join(', ') : '';
    document.getElementById('bo-category').value = d.category || '';
    document.getElementById('bo-displayOrder').value = d.displayOrder != null ? d.displayOrder : '';
    document.getElementById('bo-priceMin').value = d.priceMin != null ? d.priceMin : '';
    document.getElementById('bo-priceMax').value = d.priceMax != null ? d.priceMax : '';
    document.getElementById('bo-bstPageSlug').value = d.bstPageSlug || '';
    document.getElementById('bo-active').checked = it ? it.active !== false : true;
    document.getElementById('bo-dialog').showModal();
  }
</script>
@endsection
