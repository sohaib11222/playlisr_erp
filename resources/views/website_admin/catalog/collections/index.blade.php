@extends('layouts.app')

@section('title', 'Website Collections')

@section('content')
@include('website_admin.catalog._styles')
@php $img = [\App\Http\Controllers\WebsiteAdmin\CatalogBaseController::class, 'img']; @endphp

<div class="ev-wrap">
  <div class="ev-head">
    <div>
      <h1>Collections</h1>
      <p class="sub">Shop collections shown on nivessa.com.</p>
    </div>
    <a href="{{ route('wa.catalog.collections.create') }}" class="wc-btn primary">Add collection</a>
  </div>
  @include('website_admin.catalog._nav')

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @if($bridgeError)<div class="alert-err">{{ $bridgeError }}</div>@endif

  <div class="wc-card">
    <table class="wc-tbl">
      <thead><tr><th style="width:44px;">#</th><th>Icon</th><th>Title</th><th>Status</th><th style="text-align:right;">Actions</th></tr></thead>
      <tbody>
        @forelse($rows as $i => $c)
          @php
            $icon = trim((string) ($c['image'] ?? ''));
            if ($icon === '' || !(strpos($icon, '/') === 0 || stripos($icon, 'http') === 0)) { $icon = '/vinylrecord.png'; }
          @endphp
          <tr>
            <td class="wc-muted">{{ ($page - 1) * $limit + $i + 1 }}</td>
            <td><img src="{{ $img($icon) }}" alt="" style="width:44px; height:44px; object-fit:contain;"></td>
            <td style="font-weight:600;">{{ $c['name'] ?? '' }}</td>
            <td><span class="wc-pill {{ ($c['status'] ?? '') === 'Active' ? 'green' : 'gray' }}">{{ $c['status'] ?? '' }}</span></td>
            <td>
              <div class="wc-actions">
                <form method="POST" action="{{ route('wa.catalog.collections.delete', $c['_id']) }}" onsubmit="return window.confirm('Delete this collection? This cannot be undone.');">
                  {{ csrf_field() }}
                  <button type="submit" class="wc-btn danger">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="5" class="empty">No collections yet.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>

  <div class="wc-pager">
    @if($page > 1)<a class="wc-btn" href="{{ request()->fullUrlWithQuery(['page' => $page - 1]) }}">Previous</a>@endif
    <span>Page {{ $page }} of {{ $pages }}</span>
    @if($page < $pages)<a class="wc-btn" href="{{ request()->fullUrlWithQuery(['page' => $page + 1]) }}">Next</a>@endif
  </div>
</div>
@endsection
