@extends('layouts.app')
@section('title', 'Website Pages')

@section('content')
@include('website_admin.content._styles')
@php
  $H = \App\Http\Controllers\WebsiteAdmin\PagesController::class;
  $typeLabel = ['all' => 'Total pages', 'regular' => 'Regular pages', 'category' => 'Category pages'][$type];
@endphp
<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Website Pages</h1>
      <p class="sub">The pages and content on nivessa.com, including the homepage.</p>
    </div>
    <div class="wc-actions">
      <form method="POST" action="{{ route('wa.content.pagesSync') }}" onsubmit="this.querySelector('button').disabled=true; this.querySelector('button').textContent='Syncing...';">
        {{ csrf_field() }}
        <button type="submit" class="wc-btn">Sync categories</button>
      </form>
      <a class="wc-btn wc-btn-primary" href="{{ route('wa.content.pagesCreate') }}">New page</a>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @if($bridgeError)<div class="alert-err">Could not reach the website: {{ $bridgeError }}</div>@endif

  <div class="wc-stats">
    <div class="wc-stat"><div class="k">{{ $typeLabel }}</div><div class="v">{{ number_format($total) }}</div></div>
    <div class="wc-stat"><div class="k">Published</div><div class="v" style="color:#15803d;">{{ number_format($counts['published']) }}</div></div>
    <div class="wc-stat"><div class="k">Drafts</div><div class="v" style="color:#1d4ed8;">{{ number_format($counts['draft']) }}</div></div>
  </div>

  <form method="GET" class="wc-filters">
    <div class="wc-field" style="flex:1; min-width:220px;"><label>Search</label><input type="text" name="search" value="{{ $search }}" placeholder="Search pages by title or slug"></div>
    <div class="wc-field"><label>Type</label>
      <select name="type" onchange="this.form.submit()">
        <option value="all" @if($type === 'all') selected @endif>All pages</option>
        <option value="regular" @if($type === 'regular') selected @endif>Regular pages</option>
        <option value="category" @if($type === 'category') selected @endif>Category pages</option>
      </select>
    </div>
    <div class="wc-field"><label>Status</label>
      <select name="status" onchange="this.form.submit()">
        <option value="all" @if($status === 'all') selected @endif>All statuses</option>
        <option value="published" @if($status === 'published') selected @endif>Published</option>
        <option value="draft" @if($status === 'draft') selected @endif>Draft</option>
      </select>
    </div>
    <button type="submit" class="wc-btn wc-btn-primary">Search</button>
  </form>

  <div class="wc-card">
    @if(count($pages) === 0)
      <div class="wc-empty">No pages found. <a href="{{ route('wa.content.pagesCreate') }}">Make a new page.</a></div>
    @else
    <div class="wc-scroll">
      <table class="wc-tbl">
        <thead><tr><th>Page</th><th>URL</th><th>Status</th><th class="num">Views</th><th>Modified</th><th></th></tr></thead>
        <tbody>
        @foreach($pages as $p)
          @php $published = ($p['status'] ?? '') === 'published'; $system = !empty($p['isSystemPage']); @endphp
          <tr>
            <td>
              <div style="font-weight:600;">{{ $p['title'] ?? '' }}
                @if($system) <span class="wc-pill blue">System</span>@endif
                @if(!empty($p['categoryId'])) <span class="wc-pill purple">Category</span>@endif
              </div>
              <div class="wc-muted" style="max-width:420px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $p['seo']['description'] ?? '' }}</div>
            </td>
            <td class="wc-mono">/{{ $p['slug'] ?? '' }}</td>
            <td>
              @if($system)
                <span class="wc-pill {{ $published ? 'green' : 'amber' }}" title="System pages can't change status here.">{{ $p['status'] ?? '' }}</span>
              @else
                <form method="POST" action="{{ route('wa.content.pagesStatus', $p['_id']) }}" style="margin:0;">
                  {{ csrf_field() }}
                  <input type="hidden" name="to" value="{{ $published ? 'draft' : 'published' }}">
                  <button type="submit" class="wc-pill {{ $published ? 'green' : 'amber' }}" title="{{ $published ? 'Unpublish' : 'Publish' }}">{{ $p['status'] ?? '' }}</button>
                </form>
              @endif
            </td>
            <td class="num">{{ number_format((int) ($p['viewCount'] ?? 0)) }}</td>
            <td class="wc-muted" style="white-space:nowrap;">{{ $H::fmtDate($p['updatedAt'] ?? null) }}<div>by {{ $p['author']['name'] ?? 'Unknown' }}</div></td>
            <td>
              <div class="wc-actions" style="justify-content:flex-end;">
                <a class="wc-btn wc-btn-sm" href="https://nivessa.com/{{ ($p['slug'] ?? '') === 'home' ? '' : ($p['slug'] ?? '') }}" target="_blank" rel="noopener">Preview</a>
                <a class="wc-btn wc-btn-sm" href="{{ route('wa.content.pagesEdit', $p['_id']) }}">Edit</a>
                <form method="POST" action="{{ route('wa.content.pagesDuplicate', $p['_id']) }}">
                  {{ csrf_field() }}
                  <button type="submit" class="wc-btn wc-btn-sm">Duplicate</button>
                </form>
                @if(!$system)
                <form method="POST" action="{{ route('wa.content.pagesDelete', $p['_id']) }}" onsubmit="return confirm({{ json_encode('Are you sure you want to delete "' . ($p['title'] ?? '') . '"? This action cannot be undone.') }});">
                  {{ csrf_field() }}
                  <button type="submit" class="wc-btn wc-btn-sm wc-btn-danger">Delete</button>
                </form>
                @endif
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
@endsection
