@extends('layouts.app')

@section('title', 'Index Submissions')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')
@include('website_admin.directory._styles')
@include('website_admin.directory._sub_head')

@php
  $la = fn($d, $f = 'M j, Y') => \App\Http\Controllers\WebsiteAdmin\DirectoryBaseController::la($d, $f);
  $stClass = fn($s) => in_array($s, ['pending', 'published', 'rejected'], true) ? $s : 'other';
@endphp

<div class="ev-wrap">
  <div class="ev-head">
    <div>
      <h1>Index Submissions</h1>
      <p class="sub">Items submitted to The Nivessa Index.@if($total > 0) {{ number_format($total) }} in this view.@endif</p>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  <div class="wd-pills">
    @foreach(['pending' => 'Pending', 'published' => 'Published', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $label)
      <a href="{{ route('wa.directory.indexSubmissions', ['status' => $k]) }}" class="wd-pill {{ $status === $k ? 'active' : '' }}">{{ $label }}</a>
    @endforeach
  </div>

  @if($error)
    <div class="wd-card"><div class="wd-empty" style="color:#b91c1c;">{{ $error }}</div></div>
  @elseif(count($rows) === 0)
    <div class="wd-card"><div class="wd-empty">No {{ $status !== 'all' ? $status . ' ' : '' }}submissions found.</div></div>
  @endif

  @foreach($rows as $s)
    @php
      $id = $s['_id'] ?? '';
      $st = (string) ($s['status'] ?? '');
      $images = array_values(array_filter((array) ($s['images'] ?? []), 'is_string'));
      $attrs = is_array($s['attributes'] ?? null) ? $s['attributes'] : [];
    @endphp
    <div class="wd-card">
      <div class="as-card">
        @if(!empty($images[0]))<a href="{{ $images[0] }}" target="_blank" rel="noopener noreferrer"><img class="as-img" src="{{ $images[0] }}" alt=""></a>@else<div class="as-img"></div>@endif
        <div class="as-body">
          <div class="full" style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
            <div>
              <h3 class="as-title">{{ $s['title'] ?? 'Untitled' }}</h3>
              <span class="wd-st {{ $stClass($st) }}">{{ $st }}</span>
              @if(!empty($s['category']))<span class="wd-tag" style="margin-left:4px;">{{ str_replace('-', ' ', $s['category']) }}</span>@endif
            </div>
            @if($st === 'pending')
              <div class="wd-actions" style="align-self:flex-start;">
                <form method="POST" action="{{ route('wa.directory.indexApprove', ['id' => $id]) }}" onsubmit="return confirm('Publish this submission to The Nivessa Index?');">
                  {{ csrf_field() }}
                  <button type="submit" class="wd-btn wd-btn-ok">Publish</button>
                </form>
                <form method="POST" action="{{ route('wa.directory.indexReject', ['id' => $id]) }}">
                  {{ csrf_field() }}
                  <input type="text" name="reason" class="wd-input" placeholder="Rejection reason" required style="width:180px;">
                  <button type="submit" class="wd-btn wd-btn-danger">Reject</button>
                </form>
              </div>
            @endif
          </div>
          <div class="as-sec" style="font-size:13.5px; color:#374151;">
            @if(!empty($s['brand']))<div><span class="wd-muted">Brand</span> {{ $s['brand'] }}</div>@endif
            @if(!empty($s['year']) || !empty($s['decade']) || !empty($s['condition']))
              <div>{{ implode(' · ', array_filter([$s['year'] ?? '', $s['decade'] ?? '', $s['condition'] ?? ''])) }}</div>
            @endif
            @if(!empty($s['description']))<p style="margin-top:6px;">{{ $s['description'] }}</p>@endif
            @if(count($attrs))
              <dl class="wd-kv" style="margin-top:8px;">
                @foreach($attrs as $k => $v)
                  <dt>{{ ucfirst(trim(preg_replace('/([A-Z])/', ' $1', (string) $k))) }}</dt>
                  <dd>{{ is_scalar($v) ? (is_bool($v) ? ($v ? 'Yes' : 'No') : $v) : json_encode($v) }}</dd>
                @endforeach
              </dl>
            @endif
          </div>
          <div class="as-sec" style="font-size:13.5px; color:#374151;">
            <div><span class="wd-muted">Submitted by</span> {{ $s['submitter_name'] ?? '' ?: 'Anonymous' }} @if(!empty($s['submitter_email']))<span class="wd-muted">({{ $s['submitter_email'] }})</span>@endif</div>
            <div><span class="wd-muted">Submitted</span> {{ $la($s['submitted_at'] ?? '') ?: '-' }}</div>
            @if(count($images) > 1)
              <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:8px;">
                @foreach(array_slice($images, 1, 4) as $img)
                  <a href="{{ $img }}" target="_blank" rel="noopener noreferrer"><img class="wd-thumb" style="width:56px; height:56px;" src="{{ $img }}" alt=""></a>
                @endforeach
              </div>
            @endif
            @if($st === 'rejected' && !empty($s['rejection_reason']))
              <div class="as-reject" style="margin-top:8px;"><strong>Rejection reason:</strong> {{ $s['rejection_reason'] }}</div>
            @endif
          </div>
        </div>
      </div>
    </div>
  @endforeach

  @if($totalPages > 1)
    <div class="wd-pager">
      @if($page > 1)<a class="wd-btn" href="{{ route('wa.directory.indexSubmissions', ['status' => $status, 'page' => $page - 1]) }}">Previous</a>@endif
      <span>Page {{ $page }} of {{ $totalPages }}</span>
      @if($page < $totalPages)<a class="wd-btn" href="{{ route('wa.directory.indexSubmissions', ['status' => $status, 'page' => $page + 1]) }}">Next</a>@endif
    </div>
  @endif
</div>
@endsection
