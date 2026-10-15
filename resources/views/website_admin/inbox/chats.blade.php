@extends('layouts.app')

@section('title', 'Chat Conversations')

@section('content')
@include('website_admin.inbox._styles')

@php
  $fmt = function ($iso) {
      if (empty($iso)) return '';
      try { return \Carbon\Carbon::parse($iso)->setTimezone('America/Los_Angeles')->format('M j, Y g:i a'); } catch (\Throwable $e) { return ''; }
  };
  $stMeta = ['new' => ['New', 'blue'], 'reviewed' => ['Reviewed', 'green'], 'archived' => ['Archived', 'gray']];
  $tabs = ['' => 'All', 'new' => 'New', 'reviewed' => 'Reviewed', 'archived' => 'Archived'];
@endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Chat Conversations</h1>
      <p class="sub">Customer chats with the Ask Nivessa helper on the website.</p>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  <div class="wi-tabs">
    @foreach($tabs as $key => $label)
      @php $n = $key === '' ? ($status === '' ? $totalCount : null) : ($counts[$key] ?? null); @endphp
      <a class="wi-tab {{ $status === $key ? 'active' : '' }}" href="{{ route('wa.inbox.chats', $key !== '' ? ['status' => $key] : []) }}">{{ $label }}@if(is_numeric($n)) <span class="n">{{ $n }}</span>@endif</a>
    @endforeach
    @if(isset($counts['wantedHuman']))
      <span class="wi-note">Wanted a human: {{ $counts['wantedHuman'] }}</span>
    @endif
  </div>

  @if($bridgeError)
    <div class="ev-card" style="border:1px solid #f0c2c2;">
      <h2 style="margin-top:0;">Could not load conversations</h2>
      <p class="sub" style="margin:0;">{{ $bridgeError }}</p>
    </div>
  @else
    <div class="wi-card wi-scroll">
      <table class="wi-tbl" style="min-width:860px;">
        <thead>
          <tr>
            <th style="width:50px;">#</th>
            <th>First question</th>
            <th>Messages</th>
            <th>Page</th>
            <th>Status</th>
            <th>When</th>
            <th class="r">Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($conversations as $i => $c)
            @php $st = $stMeta[$c['status'] ?? 'new'] ?? $stMeta['new']; @endphp
            <tr>
              <td class="wi-muted">{{ ($page - 1) * $limit + $i + 1 }}</td>
              <td><div class="wi-trunc" title="{{ $c['firstQuestion'] ?? '' }}">{{ ($c['firstQuestion'] ?? '') !== '' ? $c['firstQuestion'] : 'No question' }}</div>
                @if(!empty($c['wantedHuman']))<span class="wi-pill amber" style="margin-top:3px;">Wanted a human</span>@endif
              </td>
              <td>{{ $c['messageCount'] ?? 0 }}</td>
              <td><div class="wi-trunc wi-muted" style="max-width:180px;" title="{{ $c['page'] ?? '' }}">{{ $c['page'] ?? '' }}</div></td>
              <td><span class="wi-pill {{ $st[1] }}">{{ $st[0] }}</span></td>
              <td style="white-space:nowrap;">{{ $fmt($c['createdAt'] ?? null) }}</td>
              <td>
                <div class="wi-actions">
                  <button type="button" class="wi-btn" onclick="wiOpenChat('{{ $c['_id'] }}', {{ ($c['status'] ?? '') === 'new' ? 'true' : 'false' }})">View</button>
                  @if(($c['status'] ?? '') !== 'archived')
                    <form method="POST" action="{{ route('wa.inbox.chats.status', $c['_id']) }}">
                      {{ csrf_field() }}<input type="hidden" name="status" value="archived">
                      <button class="wi-btn" title="Archive">Archive</button>
                    </form>
                  @endif
                </div>
              </td>
            </tr>
          @empty
            <tr><td colspan="7" class="wi-empty">No conversations yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>

    @if($totalPages > 1)
      @php $pq = $status !== '' ? ['status' => $status] : []; @endphp
      <div class="wi-pager">
        @if($page > 1)<a class="wi-btn" href="{{ route('wa.inbox.chats', $pq + ['page' => $page - 1]) }}">Previous</a>@else<button class="wi-btn" disabled>Previous</button>@endif
        <span>Page {{ $page }} of {{ $totalPages }}</span>
        @if($page < $totalPages)<a class="wi-btn" href="{{ route('wa.inbox.chats', $pq + ['page' => $page + 1]) }}">Next</a>@else<button class="wi-btn" disabled>Next</button>@endif
      </div>
    @endif
  @endif
</div>

@foreach($conversations as $c)
  <dialog class="wi-dialog wide" id="wi-chat-{{ $c['_id'] }}">
    <div class="wi-dialog-body">
      <h3>Conversation</h3>
      <p class="sub">
        {{ $fmt($c['createdAt'] ?? null) }} &middot; {{ $c['messageCount'] ?? 0 }} messages
        @if(!empty($c['page'])) &middot; on {{ $c['page'] }}@endif
        @if(!empty($c['wantedHuman'])) &middot; <span style="color:#b45309;font-weight:600;">Reached for a human</span>@endif
      </p>
      <div style="max-height:50vh;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;padding:12px;background:#f9fafb;">
        @foreach(($c['messages'] ?? []) as $msg)
          @php $mine = ($msg['role'] ?? '') === 'user'; @endphp
          <div style="display:flex;justify-content:{{ $mine ? 'flex-end' : 'flex-start' }};margin-bottom:8px;">
            <div style="max-width:80%;border-radius:10px;padding:8px 12px;font-size:13.5px;white-space:pre-wrap;{{ $mine ? 'background:#dbeafe;color:#1e3a8a;' : 'background:#fff;border:1px solid #e5e7eb;color:#1f2937;' }}">{{ $msg['content'] ?? '' }}</div>
          </div>
        @endforeach
      </div>
      <div class="wi-dialog-actions split">
        <form method="POST" action="{{ route('wa.inbox.chats.delete', $c['_id']) }}" onsubmit="return wiConfirmDelete(this);">
          {{ csrf_field() }}
          <button class="wi-btn danger" data-armed="0">Delete</button>
        </form>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button type="button" class="wi-btn" onclick="this.closest('dialog').close()">Close</button>
          @if(($c['status'] ?? '') !== 'reviewed')
            <form method="POST" action="{{ route('wa.inbox.chats.status', $c['_id']) }}">
              {{ csrf_field() }}<input type="hidden" name="status" value="reviewed">
              <button class="wi-btn">Mark reviewed</button>
            </form>
          @endif
          @if(($c['status'] ?? '') !== 'archived')
            <form method="POST" action="{{ route('wa.inbox.chats.status', $c['_id']) }}">
              {{ csrf_field() }}<input type="hidden" name="status" value="archived">
              <button class="wi-btn primary">Archive</button>
            </form>
          @endif
        </div>
      </div>
    </div>
  </dialog>
@endforeach

<script>
(function () {
  var statusUrl = @json(route('wa.inbox.chats.status', '__ID__'));
  // Opening a New chat marks it Reviewed, like the website did.
  window.wiOpenChat = function (id, isNew) {
    document.getElementById('wi-chat-' + id).showModal();
    if (!isNew) return;
    fetch(statusUrl.replace('__ID__', id), {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json', 'Content-Type': 'application/json' },
      body: JSON.stringify({ status: 'reviewed' })
    }).catch(function () {});
  };
  // Two-click delete instead of confirm(), which blocks browser automation.
  window.wiConfirmDelete = function (form) {
    var b = form.querySelector('button');
    if (b.dataset.armed === '1') return true;
    b.dataset.armed = '1';
    b.textContent = 'Click again to delete permanently';
    return false;
  };
})();
</script>
@endsection
