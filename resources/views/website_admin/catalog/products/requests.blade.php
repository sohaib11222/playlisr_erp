@extends('layouts.app')

@section('title', 'Website Stock Requests')

@section('content')
@include('website_admin.catalog._styles')
@php $img = [\App\Http\Controllers\WebsiteAdmin\CatalogBaseController::class, 'img']; @endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Stock Requests</h1>
      <p class="sub">Products shoppers asked to be told about when they come back in stock, most requested first.</p>
    </div>
  </div>
  @include('website_admin.catalog._nav')

  @if($bridgeError)<div class="alert-err">{{ $bridgeError }}</div>@endif

  <div class="wc-card">
    <div class="wc-scroll">
      <table class="wc-tbl">
        <thead>
          <tr>
            <th style="width:44px;">#</th>
            <th>Image</th>
            <th>Title</th>
            <th>Price</th>
            <th>Requests</th>
            <th>Stock</th>
          </tr>
        </thead>
        <tbody>
          @forelse($rows as $i => $r)
            <tr>
              <td class="wc-muted">{{ $i + 1 }}</td>
              <td>@php $rimg = $r['productImage'] ?? ($r['image'] ?? ''); @endphp @if(!empty($rimg))<img src="{{ $img($rimg) }}" class="wc-img" loading="lazy" alt="">@else<div class="wc-img"></div>@endif</td>
              <td>
                @if(!empty($r['_id']) && is_string($r['_id']))
                  <a href="{{ route('wa.catalog.products.show', $r['_id']) }}" style="font-weight:600; color:#111827;">{{ $r['productName'] ?? 'Unknown product' }}</a>
                @else
                  {{ $r['productName'] ?? 'Unknown product' }}
                @endif
              </td>
              <td>${{ number_format((float) ($r['productPrice'] ?? 0), 2) }}</td>
              <td><strong>{{ $r['count'] ?? 0 }}</strong></td>
              <td>@if((int) ($r['productQty'] ?? 0) > 0)<span class="wc-pill green">In Stock</span>@else<span class="wc-pill red">Out Of Stock</span>@endif</td>
            </tr>
          @empty
            <tr><td colspan="6" class="empty">No stock requests yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>
@endsection
