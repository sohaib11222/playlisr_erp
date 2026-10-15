@extends('layouts.app')

@section('title', 'Store Location Requests')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')
@include('website_admin.directory._styles')

<style>
  .sr-poll { list-style:none; margin:0; padding:0; }
  .sr-poll li { position:relative; overflow:hidden; border:1px solid #e5e7eb; border-radius:8px; margin-bottom:6px; }
  .sr-poll .bar { position:absolute; top:0; bottom:0; left:0; background:#fef3c7; }
  .sr-poll .row { position:relative; display:flex; justify-content:space-between; align-items:center; gap:10px; padding:8px 12px; }
  .sr-poll .rank { display:inline-block; width:24px; font-size:12px; color:#9ca3af; font-variant-numeric:tabular-nums; }
  .sr-poll .lbl { font-weight:600; font-size:14px; }
  .sr-poll .ex { font-size:12px; color:#6b7280; margin-left:6px; }
  .sr-poll .v { font-weight:700; font-variant-numeric:tabular-nums; font-size:14px; }
  .sr-poll .v small { font-weight:400; color:#6b7280; }
</style>

@php
  $la = fn($d) => \App\Http\Controllers\WebsiteAdmin\DirectoryBaseController::la($d);
  $pages = max(1, (int) ceil($total / $limit));
@endphp

<div class="ev-wrap">
  <div class="ev-head">
    <div>
      <h1>Store Location Requests</h1>
      <p class="sub">Where customers want us to open next, from the poll and the "tell us where you'd like a store" form on nivessa.com/locations.</p>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
      <span class="wd-muted">Loaded {{ $loadedAt }}</span>
      <a href="{{ route('wa.directory.requests', ['page' => $page]) }}" class="wd-btn">Refresh</a>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  <div class="wd-card">
    <div class="wd-card-head">
      <h2>Poll: where should we open next?</h2>
      <span class="wd-muted">Votes from the city tabs on /locations. {{ number_format($pollTotal) }} {{ $pollTotal === 1 ? 'vote' : 'votes' }} total.</span>
    </div>
    <div class="wd-card-body">
      @if($pollError)
        <div style="color:#b91c1c;">{{ $pollError }}</div>
      @elseif(count($cities) === 0)
        <div class="wd-empty">No votes yet.</div>
      @else
        <p class="wd-muted" style="margin:0 0 10px;">Use Remove a vote to take back an obviously bogus vote. It deletes the most recent vote for that city.</p>
        <ul class="sr-poll">
          @foreach($cities as $i => $c)
            @php
              $v = (int) ($c['votes'] ?? 0);
              $pct = $pollMax > 0 ? round($v / $pollMax * 100) : 0;
              if ($v > 0 && $pct < 6) { $pct = 6; }
            @endphp
            <li>
              <span class="bar" style="width:{{ $pct }}%;"></span>
              <div class="row">
                <span><span class="rank">{{ $i + 1 }}.</span><span class="lbl">{{ $c['label'] ?? $c['slug'] ?? '' }}</span>@if(!empty($c['examples']))<span class="ex">{{ $c['examples'] }}</span>@endif</span>
                <span style="display:flex; align-items:center; gap:10px;">
                  <span class="v">{{ number_format($v) }} <small>{{ $v === 1 ? 'vote' : 'votes' }}</small></span>
                  @if($v > 0)
                    <form method="POST" action="{{ route('wa.directory.requestCityVoteRemove', ['slug' => $c['slug'] ?? '']) }}" style="margin:0;"
                          onsubmit="return confirm('Remove one vote from {{ addslashes($c['label'] ?? '') }}? This deletes the most recent vote for this city and cannot be undone.');">
                      {{ csrf_field() }}
                      <button type="submit" class="wd-btn wd-btn-danger" title="Remove the most recent vote for this city">Remove a vote</button>
                    </form>
                  @endif
                </span>
              </div>
            </li>
          @endforeach
        </ul>
      @endif
    </div>
  </div>

  <div class="wd-card">
    <div class="wd-card-head">
      <h2>Individual poll votes</h2>
      <span class="wd-muted">Every vote, newest first. Repeated votes from the same IP are the usual sign of stuffing.@if(count($votes)) {{ number_format(count($votes)) }} shown.@endif</span>
    </div>
    @if($votesError)
      <div class="wd-empty" style="color:#b91c1c;">{{ $votesError }}</div>
    @elseif(count($votes) === 0)
      <div class="wd-empty">No votes yet.</div>
    @else
      @php
        $ipCounts = [];
        foreach ($votes as $vv) { if (!empty($vv['ip'])) { $ipCounts[$vv['ip']] = ($ipCounts[$vv['ip']] ?? 0) + 1; } }
      @endphp
      <div class="wd-scroll" style="max-height:520px; overflow-y:auto;">
        <table class="wd-tbl">
          <thead><tr><th>City</th><th>Submitted</th><th>IP</th><th style="text-align:right;">Actions</th></tr></thead>
          <tbody>
          @foreach($votes as $vote)
            <tr>
              <td style="font-weight:600;">{{ $vote['label'] ?? $vote['city'] ?? '' }}</td>
              <td class="wd-muted" style="white-space:nowrap;">{{ $la($vote['createdAt'] ?? '') ?: '-' }}</td>
              <td class="wd-mono">{{ $vote['ip'] ?? '' ?: '-' }}
                @if(!empty($vote['ip']) && ($ipCounts[$vote['ip']] ?? 0) > 1)<span class="wd-tag orange" title="Votes from this IP">{{ $ipCounts[$vote['ip']] }} from this IP</span>@endif
              </td>
              <td style="text-align:right;">
                <form method="POST" action="{{ route('wa.directory.requestDelete', ['id' => $vote['_id'] ?? '']) }}" style="margin:0;"
                      onsubmit="return confirm('Delete this {{ addslashes($vote['label'] ?? '') }} vote{{ !empty($vote['ip']) ? ' from ' . $vote['ip'] : '' }}? This cannot be undone.');">
                  {{ csrf_field() }}
                  <input type="hidden" name="kind" value="vote">
                  <button type="submit" class="wd-btn wd-btn-danger">Delete</button>
                </form>
              </td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
    @endif
  </div>

  <div class="wd-card">
    <div class="wd-card-head">
      <h2>Write-in requests</h2>
      <span class="wd-muted">Free-text submissions from the "somewhere not on the list" box, newest first. Mark handled once you have followed up.</span>
    </div>
    @if($listError)
      <div class="wd-empty" style="color:#b91c1c;">{{ $listError }}</div>
    @elseif(count($rows) === 0)
      <div class="wd-empty">No requests yet.</div>
    @else
      <div class="wd-scroll">
        <table class="wd-tbl" style="min-width:720px;">
          <thead><tr><th>Requested location</th><th>Email</th><th>Status</th><th>Submitted</th><th style="text-align:right;">Actions</th></tr></thead>
          <tbody>
          @foreach($rows as $r)
            @php $handled = !empty($r['handled']); @endphp
            <tr>
              <td style="font-weight:600; white-space:pre-wrap;">{{ $r['location'] ?? '' }}</td>
              <td>@if(!empty($r['email']))<a href="mailto:{{ $r['email'] }}">{{ $r['email'] }}</a>@else <span class="wd-muted">-</span> @endif</td>
              <td><span class="wd-st {{ $handled ? 'handled' : 'new' }}">{{ $handled ? 'Handled' : 'New' }}</span></td>
              <td class="wd-muted" style="white-space:nowrap;">{{ $la($r['createdAt'] ?? '') ?: '-' }}</td>
              <td>
                <div class="wd-actions" style="justify-content:flex-end;">
                  <form method="POST" action="{{ route('wa.directory.requestHandled', ['id' => $r['_id'] ?? '']) }}">
                    {{ csrf_field() }}
                    <input type="hidden" name="handled" value="{{ $handled ? 0 : 1 }}">
                    <button type="submit" class="wd-btn">{{ $handled ? 'Mark new' : 'Mark handled' }}</button>
                  </form>
                  <form method="POST" action="{{ route('wa.directory.requestDelete', ['id' => $r['_id'] ?? '']) }}"
                        onsubmit="return confirm('Delete this request? This cannot be undone.');">
                    {{ csrf_field() }}
                    <button type="submit" class="wd-btn wd-btn-danger">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          @endforeach
          </tbody>
        </table>
      </div>
      @if($pages > 1)
        <div class="wd-pager">
          @if($page > 1)<a class="wd-btn" href="{{ route('wa.directory.requests', ['page' => $page - 1]) }}">Previous</a>@endif
          <span>Page {{ $page }} of {{ $pages }}, {{ number_format($total) }} total</span>
          @if($page < $pages)<a class="wd-btn" href="{{ route('wa.directory.requests', ['page' => $page + 1]) }}">Next</a>@endif
        </div>
      @endif
    @endif
  </div>
</div>
@endsection
