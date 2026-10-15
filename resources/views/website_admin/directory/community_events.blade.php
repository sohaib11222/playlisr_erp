@extends('layouts.app')

@section('title', 'Community Events')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')
@include('website_admin.directory._styles')

<style>
  .ce-detail td { background:#f9fafb; }
  .ce-detail-grid { display:flex; flex-wrap:wrap; gap:26px; padding:6px 2px; }
  .ce-detail-grid > div { min-width:240px; flex:1; }
  .ce-detail-grid h5 { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#9ca3af; margin:0 0 8px; }
  .ce-img { max-width:100%; max-height:200px; border-radius:8px; border:1px solid #e5e7eb; object-fit:cover; }
</style>

@php
  $la = fn($d, $f = 'M j, Y g:ia') => \App\Http\Controllers\WebsiteAdmin\DirectoryBaseController::la($d, $f);
  $stClass = fn($s) => in_array($s, ['pending', 'approved', 'rejected'], true) ? $s : 'other';
  $day = function ($d, $f = 'M j, Y') {
      if (empty($d)) { return ''; }
      try { return \Carbon\Carbon::parse(substr((string) $d, 0, 10))->format($f); } catch (\Throwable $e) { return (string) $d; }
  };
  $clock = function ($t) {
      if (empty($t)) { return ''; }
      try { return \Carbon\Carbon::parse('2000-01-01 ' . $t)->format('g:i a'); } catch (\Throwable $e) { return (string) $t; }
  };
  $allCount = $counts['pending'] + $counts['approved'] + $counts['rejected'];
@endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Community Events</h1>
      <p class="sub">Events the community submits through the nivessa.com events form. Approved ones show on the site. Nivessa's own events live on the <a href="{{ route('events.index') }}">Events page</a>.</p>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  <div class="wd-pills">
    <a href="{{ route('wa.directory.communityEvents') }}" class="wd-pill {{ $status === '' ? 'active' : '' }}">All <span class="n">{{ $allCount }}</span></a>
    <a href="{{ route('wa.directory.communityEvents', ['status' => 'pending']) }}" class="wd-pill {{ $status === 'pending' ? 'active' : '' }}">Pending <span class="n">{{ $counts['pending'] }}</span></a>
    <a href="{{ route('wa.directory.communityEvents', ['status' => 'approved']) }}" class="wd-pill {{ $status === 'approved' ? 'active' : '' }}">Approved <span class="n">{{ $counts['approved'] }}</span></a>
    <a href="{{ route('wa.directory.communityEvents', ['status' => 'rejected']) }}" class="wd-pill {{ $status === 'rejected' ? 'active' : '' }}">Rejected <span class="n">{{ $counts['rejected'] }}</span></a>
  </div>

  <div class="wd-card">
    <div class="wd-scroll">
      <table class="wd-tbl" style="min-width:1100px;">
        <thead><tr>
          <th style="width:40px;">#</th><th>Event</th><th>Date / time</th><th>Venue</th><th>Submitted by</th><th>Status</th><th>Reviewed by</th><th>Submitted</th><th style="width:300px;">Actions</th>
        </tr></thead>
        <tbody>
        @if($error)
          <tr><td colspan="9" class="wd-empty" style="color:#b91c1c;">{{ $error }}</td></tr>
        @elseif(count($rows) === 0)
          <tr><td colspan="9" class="wd-empty">No community events found.</td></tr>
        @endif
        @foreach($rows as $i => $e)
          @php
            $id = $e['_id'] ?? '';
            $st = (string) ($e['status'] ?? '');
            $by = is_array($e['submittedBy'] ?? null) ? $e['submittedBy'] : [];
            $times = trim($clock($e['time'] ?? '') . (!empty($e['endTime']) ? ' to ' . $clock($e['endTime']) : ''));
          @endphp
          <tr>
            <td>{{ ($page - 1) * $limit + $i + 1 }}</td>
            <td>
              <div style="display:flex; gap:8px; align-items:center;">
                @if(!empty($e['image']))<img class="wd-thumb" src="{{ $e['image'] }}" alt="">@endif
                <span style="font-weight:600;">{{ $e['name'] ?? 'Untitled' }}</span>
              </div>
            </td>
            <td>{{ $day($e['date'] ?? '') }}<div class="wd-muted">{{ $times }}</div></td>
            <td>{{ $e['venue'] ?? '' }}</td>
            <td><div style="font-weight:600;">{{ $by['name'] ?? '' }}</div><div class="wd-muted">{{ $by['email'] ?? '' }}</div></td>
            <td><span class="wd-st {{ $stClass($st) }}">{{ $st }}</span></td>
            <td class="wd-muted">{{ $e['reviewedBy'] ?? '' ?: '-' }}@if(!empty($e['reviewedAt']))<div>{{ $la($e['reviewedAt'], 'M j') }}</div>@endif</td>
            <td class="wd-muted">{{ $la($e['createdAt'] ?? '', 'M j, Y') }}<br>{{ $la($e['createdAt'] ?? '', 'g:i a') }}</td>
            <td>
              <div class="wd-actions">
                <button type="button" class="wd-btn" onclick="var r=document.getElementById('ce-d-{{ $id }}'); r.style.display = r.style.display === 'none' ? '' : 'none';">Details</button>
                @if($st !== 'approved')
                  <form method="POST" action="{{ route('wa.directory.communityEventApprove', ['id' => $id]) }}">
                    {{ csrf_field() }}
                    <button type="submit" class="wd-btn wd-btn-ok">Approve</button>
                  </form>
                @endif
                <form method="POST" action="{{ route('wa.directory.communityEventDelete', ['id' => $id]) }}"
                      onsubmit="return confirm('Delete this event? It is removed for good.');">
                  {{ csrf_field() }}
                  <button type="submit" class="wd-btn wd-btn-danger">Delete</button>
                </form>
              </div>
              @if($st !== 'rejected')
                <form method="POST" action="{{ route('wa.directory.communityEventReject', ['id' => $id]) }}" class="wd-actions" style="margin-top:6px;">
                  {{ csrf_field() }}
                  <input type="text" name="reason" class="wd-input" placeholder="Reason (optional)" style="width:160px;">
                  <button type="submit" class="wd-btn wd-btn-danger">Reject</button>
                </form>
              @endif
            </td>
          </tr>
          <tr id="ce-d-{{ $id }}" class="ce-detail" style="display:none;">
            <td colspan="9">
              <div class="ce-detail-grid">
                <div>
                  @if(!empty($e['image']))<a href="{{ $e['image'] }}" target="_blank" rel="noopener noreferrer"><img class="ce-img" src="{{ $e['image'] }}" alt=""></a>@endif
                  <h5 style="margin-top:10px;">Event</h5>
                  <dl class="wd-kv">
                    <dt>Date</dt><dd>{{ $day($e['date'] ?? '', 'l, M j, Y') }}</dd>
                    <dt>Time</dt><dd>{{ $times ?: '-' }}</dd>
                    <dt>Venue</dt><dd>{{ $e['venue'] ?? '' }}</dd>
                    <dt>Address</dt><dd>{{ $e['address'] ?? '' }}</dd>
                    @if(!empty($e['eventLink']))<dt>Link</dt><dd><a href="{{ $e['eventLink'] }}" target="_blank" rel="noopener noreferrer">{{ $e['eventLink'] }}</a></dd>@endif
                  </dl>
                  @if(!empty($e['description']))
                    <h5 style="margin-top:12px;">Description</h5>
                    <div style="font-size:13px; white-space:pre-wrap;">{{ $e['description'] }}</div>
                  @endif
                </div>
                <div>
                  <h5>Submitted by</h5>
                  <dl class="wd-kv">
                    <dt>Name</dt><dd>{{ $by['name'] ?? '' }}</dd>
                    <dt>Email</dt><dd>{{ $by['email'] ?? '' }}</dd>
                    @if(!empty($by['phone']))<dt>Phone</dt><dd>{{ $by['phone'] }}</dd>@endif
                    @if(!empty($by['instagram']))<dt>Instagram</dt><dd>{{ $by['instagram'] }}</dd>@endif
                    @if(!empty($e['ip']))<dt>IP</dt><dd class="wd-mono">{{ $e['ip'] }}</dd>@endif
                  </dl>
                </div>
                <div>
                  <h5>Moderation history</h5>
                  <ul class="wd-timeline">
                    <li><strong>Submitted</strong><div class="t">{{ $la($e['createdAt'] ?? '') }}, by {{ $by['name'] ?? '' }} ({{ $by['email'] ?? '' }})</div></li>
                    @if(!empty($e['reviewedBy']))
                      <li><strong>{{ $st === 'approved' ? 'Approved' : 'Rejected' }}</strong><div class="t">{{ $la($e['reviewedAt'] ?? '') }}, by {{ $e['reviewedBy'] }}</div>
                        @if(!empty($e['rejectionReason']))<div style="color:#b91c1c;">Reason: {{ $e['rejectionReason'] }}</div>@endif
                      </li>
                    @else
                      <li><strong style="color:#92400e;">Awaiting review</strong><div class="t">Not reviewed yet.</div></li>
                    @endif
                  </ul>
                </div>
              </div>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    </div>
    @if($pages > 1)
      <div class="wd-pager">
        @if($page > 1)<a class="wd-btn" href="{{ route('wa.directory.communityEvents', array_filter(['status' => $status, 'page' => $page - 1])) }}">Previous</a>@endif
        <span>Page {{ $page }} of {{ $pages }}</span>
        @if($page < $pages)<a class="wd-btn" href="{{ route('wa.directory.communityEvents', array_filter(['status' => $status, 'page' => $page + 1])) }}">Next</a>@endif
      </div>
    @endif
  </div>
</div>
@endsection
