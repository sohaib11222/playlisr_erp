@extends('layouts.app')

@section('title', 'Website Product')

@section('content')
@include('website_admin.catalog._styles')
@php
  $img = [\App\Http\Controllers\WebsiteAdmin\CatalogBaseController::class, 'img'];
  $pid = (string) ($p['_id'] ?? '');
  $published = ($p['published'] ?? true) !== false;
  $sub = $p['subCategory'] ?? '';
  if (is_array($sub)) { $sub = $sub['name'] ?? ($sub['_id'] ?? ''); }
  $loc = $p['location'] ?? null;
  $txt = function ($v) { return is_array($v) ? implode(', ', array_filter($v, 'is_scalar')) : (string) $v; };
  $when = function ($d) { return $d ? \Carbon\Carbon::parse($d)->setTimezone('America/Los_Angeles')->format('M j, Y g:i A') : ''; };
@endphp

<div class="ev-wrap">
  <div class="ev-head">
    <div>
      <h1>{{ $p['name'] ?? 'Untitled' }}</h1>
      <p class="sub">SKU: {{ $p['sku'] ?? 'N/A' }}</p>
    </div>
    <div class="wc-actions">
      <a href="{{ route('wa.catalog.products') }}" class="wc-btn">Back to products</a>
      @if(!empty($p['slug']))<a href="https://nivessa.com/products/{{ $p['slug'] }}" target="_blank" rel="noopener" class="wc-btn">View on website</a>@endif
      <a href="{{ route('wa.catalog.products.edit', $pid) }}" class="wc-btn primary">Edit product</a>
    </div>
  </div>
  @include('website_admin.catalog._nav')

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  <div style="display:grid; grid-template-columns:minmax(0,1fr) minmax(0,2fr); gap:18px;" class="wc-show-grid">
    <div>
      <div class="wc-card pad">
        <h2>Image</h2>
        @if(!empty($p['image']))
          <img src="{{ $img($p['image']) }}" alt="" style="width:100%; border-radius:8px; border:1px solid #e5e7eb;">
        @else
          <p class="wc-muted">No image.</p>
        @endif
      </div>

      <div class="wc-card pad">
        <h2>Status</h2>
        <div class="wc-kv" style="grid-template-columns:120px 1fr;">
          <div class="k">Published</div>
          <div>
            <span class="wc-pill {{ $published ? 'blue' : 'gray' }}">{{ $published ? 'Published' : 'Hidden' }}</span>
            @if(!empty($p['adminUnpublished']))<span class="wc-lock" title="No sync job will publish or restock this product">Locked</span>@endif
          </div>
          <div class="k">On sale</div>
          <div><span class="wc-pill {{ !empty($p['onSale']) ? 'orange' : 'gray' }}">{{ !empty($p['onSale']) ? 'Yes' : 'No' }}</span></div>
          <div class="k">Availability</div>
          <div><span class="wc-pill {{ ($p['availability'] ?? '') === 'In Stock' ? 'green' : 'red' }}">{{ $p['availability'] ?? 'Unknown' }}</span></div>
          @if(!empty($p['isPreorder']))
            <div class="k">Preorder</div>
            <div>Yes @if(!empty($p['preorderShipDate']))(ships {{ \Carbon\Carbon::parse($p['preorderShipDate'])->format('M j, Y') }})@endif</div>
          @endif
        </div>
        <div style="display:flex; gap:8px; margin-top:14px; flex-wrap:wrap;">
          <form method="POST" action="{{ route('wa.catalog.products.publish', $pid) }}">
            {{ csrf_field() }}
            <input type="hidden" name="published" value="{{ $published ? '0' : '1' }}">
            <button type="submit" class="wc-btn">{{ $published ? 'Hide from website' : 'Publish' }}</button>
          </form>
          <form method="POST" action="{{ route('wa.catalog.products.sale', $pid) }}">
            {{ csrf_field() }}
            <input type="hidden" name="onSale" value="{{ !empty($p['onSale']) ? '0' : '1' }}">
            <button type="submit" class="wc-btn">{{ !empty($p['onSale']) ? 'Remove from sale' : 'Mark on sale' }}</button>
          </form>
        </div>
      </div>

      @if(!empty($p['syncSource']))
        <div class="wc-card pad">
          <h2>Sync info</h2>
          <div class="wc-kv" style="grid-template-columns:120px 1fr;">
            <div class="k">Source</div><div>{{ strtoupper($p['syncSource']) }}</div>
            @if(!empty($p['discogId']))
              <div class="k">Discogs listing</div><div><a href="https://www.discogs.com/sell/item/{{ $p['discogId'] }}" target="_blank" rel="noopener">{{ $p['discogId'] }}</a></div>
            @endif
            @if(!empty($p['discogsReleaseId']))
              <div class="k">Release ID</div><div><a href="https://www.discogs.com/release/{{ $p['discogsReleaseId'] }}" target="_blank" rel="noopener">{{ $p['discogsReleaseId'] }}</a></div>
            @endif
            @if(!empty($p['lastSyncedFrom']))
              <div class="k">Last synced</div><div>{{ $when($p['lastSyncedFrom']) }}</div>
            @endif
          </div>
        </div>
      @endif
    </div>

    <div>
      <div class="wc-stats">
        <div class="wc-stat"><div class="n">${{ number_format((float) ($p['price'] ?? 0), 2) }}</div><div class="l">Price</div></div>
        <div class="wc-stat"><div class="n">{{ $p['quantity'] ?? 0 }}</div><div class="l">Quantity</div></div>
      </div>

      <div class="wc-card pad">
        <h2>Product information</h2>
        <div class="wc-kv">
          @foreach(['artist' => 'Artist', 'label' => 'Label', 'format' => 'Format', 'genre' => 'Genre', 'condition' => 'Condition', 'categoryNumber' => 'Catalog number', 'ebayid' => 'eBay ID', 'slug' => 'Slug'] as $k => $l)
            @if(!empty($p[$k]))<div class="k">{{ $l }}</div><div>{{ $txt($p[$k]) }}</div>@endif
          @endforeach
          @if($sub)<div class="k">Category</div><div>{{ $typeNames[$sub] ?? $sub }}</div>@endif
        </div>
      </div>

      <div class="wc-card pad">
        <h2>Location</h2>
        @if(is_array($loc) && $loc)
          <div class="wc-kv">
            @foreach(['store' => 'Store', 'zone' => 'Zone', 'sub_location' => 'Sub location', 'shelf_details' => 'Shelf', 'discogs_code' => 'Discogs code'] as $k => $l)
              @if(!empty($loc[$k]))<div class="k">{{ $l }}</div><div>{{ $txt($loc[$k]) }}</div>@endif
            @endforeach
          </div>
        @elseif($loc)
          <p style="margin:0;">{{ $txt($loc) }}</p>
        @else
          <p class="wc-muted" style="margin:0;">No location set.</p>
        @endif
      </div>

      @if(!empty($p['description']))
        <div class="wc-card pad">
          <h2>Description</h2>
          <p style="white-space:pre-wrap; margin:0;">{{ $txt($p['description']) }}</p>
        </div>
      @endif

      <div class="wc-card pad">
        <h2>History</h2>
        <div class="wc-kv">
          @if(!empty($p['createdBy']))<div class="k">Created by</div><div>{{ $p['createdBy'] }}</div>@endif
          @if(!empty($p['lastModifiedBy']))<div class="k">Last changed by</div><div>{{ $p['lastModifiedBy'] }}</div>@endif
          @if(!empty($p['createdAt']))<div class="k">Created</div><div>{{ $when($p['createdAt']) }}</div>@endif
          @if(!empty($p['updatedAt']))<div class="k">Updated</div><div>{{ $when($p['updatedAt']) }}</div>@endif
        </div>
      </div>
    </div>
  </div>
</div>
<style>@media (max-width: 800px) { .wc-show-grid { grid-template-columns:1fr !important; } }</style>
@endsection
