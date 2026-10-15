@extends('layouts.app')

@section('title', 'Website Featured Products')

@section('content')
@include('website_admin.catalog._styles')
@php $img = [\App\Http\Controllers\WebsiteAdmin\CatalogBaseController::class, 'img']; @endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Featured Products</h1>
      <p class="sub">Staff picks, best sellers, most collected this week and related products on nivessa.com.</p>
    </div>
  </div>
  @include('website_admin.catalog._nav')

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @if($bridgeError)<div class="alert-err">{{ $bridgeError }}</div>@endif

  <div class="wc-tabs">
    @foreach($types as $k => $l)
      <a href="{{ route('wa.catalog.featured', ['type' => $k]) }}" class="wc-tab {{ $type === $k ? 'active' : '' }}">{{ $l }}</a>
    @endforeach
  </div>

  <div class="wc-card pad">
    <h2>Add a product to {{ $types[$type] }}</h2>
    <form method="GET" action="{{ route('wa.catalog.featured') }}" style="display:flex; gap:8px; flex-wrap:wrap;">
      <input type="hidden" name="type" value="{{ $type }}">
      <input type="text" name="q" value="{{ $q }}" placeholder="Search by title, artist or SKU" style="flex:1 1 280px; font-size:13.5px; padding:7px 10px; border:1px solid #d1d5db; border-radius:8px;">
      <button type="submit" class="wc-btn primary">Search</button>
      @if($q !== '')<a href="{{ route('wa.catalog.featured', ['type' => $type]) }}" class="wc-btn">Clear</a>@endif
    </form>
    @if($searchError)<p class="wc-muted" style="margin:10px 0 0; color:#b91c1c;">{{ $searchError }}</p>@endif
    @if($q !== '' && !$searchError)
      <div style="margin-top:12px;">
        @forelse($results as $r)
          @php $rid = (string) ($r['_id'] ?? ''); $already = isset($featuredIds[$rid]); @endphp
          <div style="display:flex; gap:12px; align-items:center; padding:8px 0; border-bottom:1px solid #f0f0f0;">
            @if(!empty($r['image']))<img src="{{ $img($r['image']) }}" class="wc-img" alt="" loading="lazy">@else<div class="wc-img"></div>@endif
            <div style="flex:1; min-width:0;">
              <div style="font-weight:600;">{{ $r['name'] ?? '' }}</div>
              <div class="wc-muted">{{ is_array($r['artist'] ?? null) ? implode(', ', $r['artist']) : ($r['artist'] ?? 'Unknown') }} | ${{ number_format((float) ($r['price'] ?? 0), 2) }} | Stock {{ $r['quantity'] ?? 0 }} | SKU {{ $r['sku'] ?? 'N/A' }}</div>
            </div>
            @if($already)
              <span class="wc-pill gray">Already added</span>
            @else
              <form method="POST" action="{{ route('wa.catalog.featured.add') }}" style="margin:0;">
                {{ csrf_field() }}
                <input type="hidden" name="type" value="{{ $type }}">
                <input type="hidden" name="productId" value="{{ $rid }}">
                <button type="submit" class="wc-btn primary">Add</button>
              </form>
            @endif
          </div>
        @empty
          <p class="empty">No products match.</p>
        @endforelse
      </div>
    @endif
  </div>

  <div class="wc-card pad">
    <div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:10px; font-size:13.5px;">
      <span>Total in this list: <strong>{{ count($rows) }}</strong></span>
      <span>Visible on the website: <strong style="color:{{ $visibleOnPublic < count($rows) ? '#c2410c' : '#15803d' }};">{{ $visibleOnPublic }}</strong></span>
      @if($visibleOnPublic < count($rows))<span style="color:#c2410c;">{{ count($rows) - $visibleOnPublic }} product(s) are hidden because they are out of stock or deleted.</span>@endif
    </div>
    <table class="wc-tbl">
      <thead><tr><th style="width:120px;">Order</th><th>Product</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
      <tbody>
        @forelse($rows as $item)
          @php $prod = is_array($item['productId'] ?? null) ? $item['productId'] : []; $st = $item['productStatus'] ?? ''; @endphp
          <tr>
            <td>
              <form method="POST" action="{{ route('wa.catalog.featured.order', $item['_id']) }}" style="display:flex; gap:4px; margin:0;">
                {{ csrf_field() }}
                <input type="number" name="order" value="{{ $item['order'] ?? 0 }}" style="width:60px; padding:4px 6px; border:1px solid #d1d5db; border-radius:6px;" onchange="this.form.submit()">
              </form>
            </td>
            <td>
              <div style="display:flex; gap:10px; align-items:center;">
                @if(!empty($prod['image']))<img src="{{ $img($prod['image']) }}" class="wc-img" alt="" loading="lazy">@endif
                <div>
                  <div style="font-weight:600; {{ $st === 'deleted' ? 'color:#9ca3af; text-decoration:line-through;' : '' }}">{{ $prod['name'] ?? 'Product deleted' }}</div>
                  <div class="wc-muted">{{ is_array($prod['artist'] ?? null) ? implode(', ', $prod['artist']) : ($prod['artist'] ?? 'Unknown artist') }} | {{ $prod['productType'] ?? 'N/A' }} | ${{ number_format((float) ($prod['price'] ?? 0), 2) }}</div>
                  @if(!empty($item['note']))<div class="wc-muted">{{ $item['note'] }}</div>@endif
                </div>
              </div>
            </td>
            <td>
              <span class="wc-pill {{ !empty($item['isActive']) ? 'green' : 'gray' }}">{{ !empty($item['isActive']) ? 'Active' : 'Inactive' }}</span>
              @if($st === 'deleted')<span class="wc-pill red">Deleted</span>@endif
              @if($st === 'out-of-stock')<span class="wc-pill orange">Out of stock</span>@endif
            </td>
            <td>
              <div class="wc-actions">
                <form method="POST" action="{{ route('wa.catalog.featured.remove', $item['_id']) }}" onsubmit="return window.confirm('Remove this product from the list?');">
                  {{ csrf_field() }}
                  <button type="submit" class="wc-btn danger">Remove</button>
                </form>
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="4" class="empty">No products in this list yet.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
