@extends('layouts.app')

@section('title', 'Artist Submissions')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')
@include('website_admin.directory._styles')
@include('website_admin.directory._sub_head')

@php
  $la = fn($d, $f = 'M j, Y') => \App\Http\Controllers\WebsiteAdmin\DirectoryBaseController::la($d, $f);
  $stClass = fn($s) => in_array($s, ['pending', 'approved', 'rejected'], true) ? $s : 'other';
@endphp

<div class="ev-wrap">
  <div class="ev-head">
    <div>
      <h1>Artist Submissions</h1>
      <p class="sub">Artist profiles people submitted to nivessa.com.@if($total > 0) {{ number_format($total) }} in this view.@endif</p>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  <div class="wd-pills">
    @foreach(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $label)
      <a href="{{ route('wa.directory.artists', ['status' => $k]) }}" class="wd-pill {{ $status === $k ? 'active' : '' }}">{{ $label }}</a>
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
      $sb = is_array($s['submitted_by'] ?? null) ? $s['submitted_by'] : [];
      $who = $sb['name'] ?? ($s['guest_name'] ?? 'Unknown');
      $whoEmail = $sb['email'] ?? ($s['guest_email'] ?? '');
      $labels = is_array($s['labels'] ?? null) ? $s['labels'] : [];
      $stage = '';
      foreach ($labels as $lv) { if (is_array($lv) && !empty($lv['value'])) { $stage = (string) $lv['value']; break; } }
    @endphp
    <div class="wd-card">
      <div class="as-card">
        @if(!empty($s['image_url']))<img class="as-img" src="{{ $s['image_url'] }}" alt="">@else<div class="as-img"></div>@endif
        <div class="as-body">
          <div class="full" style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
            <div>
              <h3 class="as-title">{{ $s['artist_name'] ?? 'Unnamed artist' }}</h3>
              <span class="wd-st {{ $stClass($st) }}">{{ $st }}</span>
            </div>
            @if($st === 'pending')
              <div class="wd-actions" style="align-self:flex-start;">
                <form method="POST" action="{{ route('wa.directory.artistApprove', ['id' => $id]) }}" onsubmit="return confirm('Approve this artist?');">
                  {{ csrf_field() }}
                  <button type="submit" class="wd-btn wd-btn-ok">Approve</button>
                </form>
                <form method="POST" action="{{ route('wa.directory.artistReject', ['id' => $id]) }}">
                  {{ csrf_field() }}
                  <input type="text" name="reason" class="wd-input" placeholder="Reason (optional)" style="width:180px;">
                  <button type="submit" class="wd-btn wd-btn-danger">Reject</button>
                </form>
              </div>
            @endif
          </div>
          <div class="as-sec">
            <h4>Biography</h4>
            <p>{{ $s['artist_description'] ?? '' ?: 'No biography provided.' }}</p>
            @if($stage !== '')
              <h4 style="margin-top:10px;">Stage name</h4>
              <p>{{ $stage }}</p>
            @endif
          </div>
          <div class="as-sec" style="font-size:13.5px; color:#374151;">
            <div><span class="wd-muted">Submitted by</span> {{ $who }} @if($whoEmail !== '')<span class="wd-muted">({{ $whoEmail }})</span>@endif
              @if(!$sb && !empty($s['guest_name']))<span class="wd-tag">Guest</span>@endif</div>
            <div><span class="wd-muted">Submitted</span> {{ $la($s['submitted_at'] ?? ($s['createdAt'] ?? '')) }}</div>
            @if(!empty($s['discogs_url']))<div><a href="{{ $s['discogs_url'] }}" target="_blank" rel="noopener noreferrer">Discogs profile</a></div>@endif
            @if(!empty($s['website_url']))<div><a href="{{ $s['website_url'] }}" target="_blank" rel="noopener noreferrer">Official website</a></div>@endif
            @foreach(['instagram_url' => 'Instagram', 'youtube_url' => 'YouTube', 'facebook_url' => 'Facebook', 'twitter_url' => 'Twitter'] as $k => $label)
              @if(!empty($s[$k]))<div><a href="{{ $s[$k] }}" target="_blank" rel="noopener noreferrer">{{ $label }}</a></div>@endif
            @endforeach
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
      @if($page > 1)<a class="wd-btn" href="{{ route('wa.directory.artists', ['status' => $status, 'page' => $page - 1]) }}">Previous</a>@endif
      <span>Page {{ $page }} of {{ $totalPages }}</span>
      @if($page < $totalPages)<a class="wd-btn" href="{{ route('wa.directory.artists', ['status' => $status, 'page' => $page + 1]) }}">Next</a>@endif
    </div>
  @endif
</div>
@endsection
