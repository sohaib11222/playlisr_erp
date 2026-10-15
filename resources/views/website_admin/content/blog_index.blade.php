@extends('layouts.app')
@section('title', 'Blog')

@section('content')
@include('website_admin.content._styles')
@php $H = \App\Http\Controllers\WebsiteAdmin\BlogController::class; @endphp
<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Blog</h1>
      <p class="sub">Posts on nivessa.com/blog.</p>
    </div>
    <div class="wc-actions">
      <a class="wc-btn" href="{{ route('wa.content.blogCategories') }}">Categories</a>
      <a class="wc-btn wc-btn-primary" href="{{ route('wa.content.blogCreate') }}">New blog post</a>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @if($bridgeError)<div class="alert-err">Could not reach the website: {{ $bridgeError }}</div>@endif

  <form method="GET" class="wc-filters">
    <div class="wc-field" style="flex:1; min-width:220px;"><label>Search</label><input type="text" name="search" value="{{ $search }}" placeholder="Search title or author"></div>
    <button type="submit" class="wc-btn wc-btn-primary">Search</button>
    @if($search !== '')<a class="wc-btn" href="{{ route('wa.content.blog') }}">Clear</a>@endif
  </form>

  <div class="wc-card">
    @if(count($blogs) === 0)
      <div class="wc-empty">No blog posts found. <a href="{{ route('wa.content.blogCreate') }}">Write the first one.</a></div>
    @else
    <div class="wc-scroll">
      <table class="wc-tbl">
        <thead><tr><th style="width:80px;"></th><th>Post</th><th>Category</th><th>Status</th><th>Author</th><th>Created</th><th class="num">Views</th><th></th></tr></thead>
        <tbody>
        @foreach($blogs as $b)
          @php
            $scheduledAt = $b['scheduledPublishAt'] ?? null;
            $isScheduled = empty($b['isPublished']) && $scheduledAt && strtotime($scheduledAt) > time();
          @endphp
          <tr>
            <td>@if(!empty($b['featuredImage']))<img class="wc-thumb" src="{{ $H::siteImage($b['featuredImage']) }}" alt="">@endif</td>
            <td>
              <div style="font-weight:600;">{{ $b['title'] ?? '' }}</div>
              <div class="wc-muted">/blog/{{ $b['slug'] ?? '' }}</div>
            </td>
            <td>
              @if(!empty($b['category']['name']))
                <span class="wc-pill" style="background:{{ $b['category']['color'] ?? '#3B2E2A' }}; color:#fff;">{{ $b['category']['name'] }}</span>
              @endif
            </td>
            <td>
              @if(!empty($b['isPublished']))
                <span class="wc-pill green">Published</span>
              @elseif($isScheduled)
                <span class="wc-pill blue">Scheduled, {{ $H::fmtDate($scheduledAt, 'M j g:i A') }}</span>
              @else
                <span class="wc-pill">Draft</span>
              @endif
            </td>
            <td>{{ $b['author'] ?? '' }}</td>
            <td class="wc-muted" style="white-space:nowrap;">{{ $H::fmtDate($b['createdAt'] ?? null, 'F j, Y') }}</td>
            <td class="num">{{ number_format((int) ($b['views'] ?? 0)) }}</td>
            <td>
              <div class="wc-actions" style="justify-content:flex-end;">
                <a class="wc-btn wc-btn-sm" href="https://nivessa.com/blog/{{ $b['slug'] ?? '' }}" target="_blank" rel="noopener">View</a>
                <a class="wc-btn wc-btn-sm" href="{{ route('wa.content.blogEdit', $b['_id']) }}">Edit</a>
                <form method="POST" action="{{ route('wa.content.blogPublish', $b['_id']) }}">
                  {{ csrf_field() }}
                  <input type="hidden" name="publish" value="{{ !empty($b['isPublished']) ? '0' : '1' }}">
                  <button type="submit" class="wc-btn wc-btn-sm">{{ !empty($b['isPublished']) ? 'Unpublish' : 'Publish' }}</button>
                </form>
                <form method="POST" action="{{ route('wa.content.blogDelete', $b['_id']) }}" onsubmit="return confirm({{ json_encode('Are you sure you want to delete "' . ($b['title'] ?? '') . '"?') }});">
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
@endsection
