@extends('layouts.app')

@section('title', 'Website Missing Images')

@section('content')
@include('website_admin.catalog._styles')
@php
  $sortUrl = function ($col) use ($sort, $dir) {
    return request()->fullUrlWithQuery(['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc']);
  };
  $arrow = function ($col) use ($sort, $dir) {
    return $sort === $col ? ($dir === 'asc' ? ' &uarr;' : ' &darr;') : '';
  };
@endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Missing Images</h1>
      <p class="sub">Popular website products that have no image. Paste an image URL and save it.</p>
    </div>
    <form method="GET" class="wc-actions">
      <label class="wc-muted" style="margin:0;">Show</label>
      <select name="limit" onchange="this.form.submit()" style="padding:6px 9px; border:1px solid #d1d5db; border-radius:7px;">
        @foreach([50 => '50 products', 100 => '100 products', 200 => '200 products', 500 => '500 products', 9999 => 'All products'] as $n => $l)
          <option value="{{ $n }}" @if($limit === $n) selected @endif>{{ $l }}</option>
        @endforeach
      </select>
      <a href="{{ request()->fullUrl() }}" class="wc-btn">Refresh list</a>
    </form>
  </div>
  @include('website_admin.catalog._nav')

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @if($bridgeError)<div class="alert-err">{{ $bridgeError }}</div>@endif

  <div class="wc-stats">
    <div class="wc-stat"><div class="n">{{ number_format($stats['total']) }}</div><div class="l">Products without images</div></div>
    <div class="wc-stat"><div class="n">{{ number_format($stats['publishedInStock']) }}</div><div class="l">Published and in stock</div></div>
    <div class="wc-stat"><div class="n">{{ number_format($stats['unpublishedOrOutOfStock']) }}</div><div class="l">Unpublished or out of stock</div></div>
  </div>

  <div class="wc-card">
    <div class="wc-scroll">
      <table class="wc-tbl" style="min-width:1100px;">
        <thead>
          <tr>
            <th style="width:44px;">#</th>
            <th><a href="{{ $sortUrl('name') }}">Product{!! $arrow('name') !!}</a></th>
            <th><a href="{{ $sortUrl('artist') }}">Artist{!! $arrow('artist') !!}</a></th>
            <th><a href="{{ $sortUrl('sku') }}">SKU{!! $arrow('sku') !!}</a></th>
            <th><a href="{{ $sortUrl('price') }}">Price{!! $arrow('price') !!}</a></th>
            <th><a href="{{ $sortUrl('quantity') }}">Qty{!! $arrow('quantity') !!}</a></th>
            <th><a href="{{ $sortUrl('location') }}">Location{!! $arrow('location') !!}</a></th>
            <th style="min-width:320px;">Image URL</th>
          </tr>
        </thead>
        <tbody>
          @forelse($rows as $i => $r)
            @php $rid = (string) ($r['_id'] ?? ''); $qty = (int) ($r['quantity'] ?? 0); @endphp
            <tr>
              <td class="wc-muted">{{ $i + 1 }}</td>
              <td>
                <a href="{{ route('wa.catalog.products.show', $rid) }}" style="font-weight:600; color:#111827;">{{ $r['name'] ?? 'Untitled' }}</a>
                <div style="margin-top:3px;">
                  @if((float) ($r['price'] ?? 0) > 50)<span class="wc-pill purple">High value</span>@endif
                  @if(isset($r['published']) && !$r['published'])<span class="wc-pill gray">Unpublished</span>@endif
                  @if($qty === 0)<span class="wc-pill red">Out of stock</span>@endif
                </div>
              </td>
              <td>{{ is_array($r['artist'] ?? null) ? implode(', ', $r['artist']) : ($r['artist'] ?? 'Unknown') }}</td>
              <td class="wc-muted">{{ $r['sku'] ?? 'N/A' }}</td>
              <td>${{ number_format((float) ($r['price'] ?? 0), 2) }}</td>
              <td><span class="wc-pill {{ $qty > 10 ? 'green' : ($qty > 0 ? 'orange' : 'red') }}">{{ $qty }}</span></td>
              <td class="wc-muted">{{ \App\Http\Controllers\WebsiteAdmin\WebsiteProductsController::locationText($r['location'] ?? null) }}</td>
              <td>
                <form method="POST" action="{{ route('wa.catalog.images.upload', $rid) }}" style="display:flex; gap:6px; margin:0;">
                  {{ csrf_field() }}
                  <input type="url" name="imageUrl" required placeholder="https://example.com/image.jpg" style="flex:1; font-size:13px; padding:6px 9px; border:1px solid #d1d5db; border-radius:7px;">
                  <button type="submit" class="wc-btn primary">Save</button>
                </form>
              </td>
            </tr>
          @empty
            <tr><td colspan="8" class="empty">Every popular product has an image.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="wc-card pad">
    <h2>How to use this page</h2>
    <p class="wc-muted" style="margin:0;">Find the cover online (Discogs or the label site work well), copy the image address, paste it into the row and click Save. The product drops off this list once it has an image.</p>
  </div>
</div>
@endsection
