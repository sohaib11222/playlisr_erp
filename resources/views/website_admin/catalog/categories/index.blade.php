@extends('layouts.app')

@section('title', 'Website Categories')

@section('content')
@include('website_admin.catalog._styles')

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Categories</h1>
      <p class="sub">The buy, sell and trade category pages on nivessa.com.</p>
    </div>
    <a href="{{ route('wa.catalog.categories.create') }}" class="wc-btn primary">Add category</a>
  </div>
  @include('website_admin.catalog._nav')

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @if($bridgeError)<div class="alert-err">{{ $bridgeError }}</div>@endif

  <div class="wc-stats">
    @foreach($types as $k => $l)
      <div class="wc-stat"><div class="n">{{ $counts[$k] }}</div><div class="l">{{ $l }}</div></div>
    @endforeach
  </div>

  <form method="GET" class="wc-filters">
    <div class="f" style="flex:1 1 240px;"><label>Search</label><input type="text" name="q" value="{{ $q }}" placeholder="Title or slug"></div>
    <div class="f"><label>Type</label>
      <select name="type">
        <option value="all">All Types</option>
        @foreach($types as $k => $l)<option value="{{ $k }}" @if($type === $k) selected @endif>{{ $l }}</option>@endforeach
      </select>
    </div>
    <button type="submit" class="wc-btn primary">Apply</button>
  </form>

  <div class="wc-card">
    <div class="wc-scroll">
      <table class="wc-tbl">
        <thead>
          <tr><th>Category</th><th>Type</th><th>Slug</th><th>Status</th><th>Updated</th><th style="text-align:right;">Actions</th></tr>
        </thead>
        <tbody>
          @forelse($rows as $c)
            <tr>
              <td>
                <div style="font-weight:600;">{{ $c['title'] ?? '' }}</div>
                @if(!empty($c['subtitle']))<div class="wc-muted" style="max-width:360px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $c['subtitle'] }}</div>@endif
              </td>
              <td><span class="wc-pill gray">{{ $types[$c['categoryType'] ?? ''] ?? ($c['categoryType'] ?? '') }}</span></td>
              <td class="wc-muted" style="font-family:monospace;">{{ $c['slug'] ?? '' }}</td>
              <td>@if(!empty($c['isActive']))<span class="wc-pill green">Active</span>@else<span class="wc-pill red">Inactive</span>@endif</td>
              <td class="wc-muted">{{ !empty($c['updatedAt']) ? \Carbon\Carbon::parse($c['updatedAt'])->setTimezone('America/Los_Angeles')->format('M j, Y') : '' }}</td>
              <td>
                <div class="wc-actions">
                  <a href="https://nivessa.com/buy-sell-trade/{{ $c['slug'] ?? '' }}" target="_blank" rel="noopener" class="wc-btn">View</a>
                  <a href="{{ route('wa.catalog.categories.edit', $c['_id']) }}" class="wc-btn primary">Edit</a>
                  <form method="POST" action="{{ route('wa.catalog.categories.delete', $c['_id']) }}" onsubmit="return window.confirm('Delete this category?');">
                    {{ csrf_field() }}
                    <button type="submit" class="wc-btn danger">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          @empty
            <tr><td colspan="6" class="empty">No categories found.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>
@endsection
