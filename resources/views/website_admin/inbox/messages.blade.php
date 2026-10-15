@extends('layouts.app')

@section('title', 'Contact Messages')

@section('content')
@include('website_admin.inbox._styles')

@php
  $fmt = function ($iso) {
      if (empty($iso)) return '';
      try { return \Carbon\Carbon::parse($iso)->setTimezone('America/Los_Angeles')->format('M j, Y g:i a'); } catch (\Throwable $e) { return ''; }
  };
  $stMeta = ['new' => ['New', 'blue'], 'replied' => ['Replied', 'green'], 'archived' => ['Archived', 'gray']];
@endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Contact Messages</h1>
      <p class="sub">Messages sent through the contact form on nivessa.com.</p>
    </div>
    <form method="POST" action="{{ route('wa.inbox.messages.test_email') }}">
      {{ csrf_field() }}
      <button class="wi-btn">Send test to hello@nivessa.com</button>
    </form>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  @if($bridgeError)
    <div class="ev-card" style="border:1px solid #f0c2c2;">
      <h2 style="margin-top:0;">Could not load messages</h2>
      <p class="sub" style="margin:0;">{{ $bridgeError }}</p>
    </div>
  @else
    <div class="wi-card wi-scroll">
      <table class="wi-tbl" style="min-width:860px;">
        <thead>
          <tr>
            <th style="width:50px;">#</th>
            <th>Name</th>
            <th>Email</th>
            <th>Subject</th>
            <th>Status</th>
            <th><a class="on" href="{{ route('wa.inbox.messages', ['sort' => $sort === 'desc' ? 'asc' : 'desc']) }}" title="{{ $sort === 'desc' ? 'Newest first' : 'Oldest first' }}">Received {!! $sort === 'desc' ? '&darr;' : '&uarr;' !!}</a></th>
            <th class="r">Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($messages as $i => $m)
            @php $st = $stMeta[$m['status'] ?? 'new'] ?? $stMeta['new']; @endphp
            <tr>
              <td class="wi-muted">{{ $i + 1 }}</td>
              <td class="wi-name">{{ $m['name'] ?? '' }}</td>
              <td>{{ $m['email'] ?? '' }}</td>
              <td>{{ $m['subject'] ?? '' }}</td>
              <td><span class="wi-pill {{ $st[1] }}">{{ $st[0] }}</span></td>
              <td style="white-space:nowrap;">{{ $fmt($m['createdAt'] ?? null) }}</td>
              <td>
                <div class="wi-actions">
                  <button type="button" class="wi-btn" onclick="document.getElementById('wi-view-{{ $m['_id'] }}').showModal()">View</button>
                  <button type="button" class="wi-btn primary" onclick="document.getElementById('wi-reply-{{ $m['_id'] }}').showModal()" {{ ($m['status'] ?? '') === 'archived' ? 'disabled' : '' }}>Reply</button>
                  @if(($m['status'] ?? '') !== 'archived')
                    <form method="POST" action="{{ route('wa.inbox.messages.status', $m['_id']) }}">
                      {{ csrf_field() }}<input type="hidden" name="status" value="archived">
                      <button class="wi-btn" title="Archive">Archive</button>
                    </form>
                  @endif
                </div>
              </td>
            </tr>
          @empty
            <tr><td colspan="7" class="wi-empty">No messages yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  @endif
</div>

@foreach($messages as $m)
  @php $st = $stMeta[$m['status'] ?? 'new'] ?? $stMeta['new']; @endphp
  <dialog class="wi-dialog wide" id="wi-view-{{ $m['_id'] }}">
    <div class="wi-dialog-body">
      <h3>Message details</h3>
      <div class="wi-kv"><b>From</b><span>{{ $m['name'] ?? '' }}</span></div>
      <div class="wi-kv"><b>Email</b><span><a href="mailto:{{ $m['email'] ?? '' }}">{{ $m['email'] ?? '' }}</a></span></div>
      <div class="wi-kv"><b>Subject</b><span>{{ $m['subject'] ?? '' }}</span></div>
      <div class="wi-block"><b>Message</b><p>{{ $m['message'] ?? '' }}</p></div>
      @if(!empty($m['reply']))
        <div class="wi-box">
          <b style="font-size:13px;">Your reply</b>
          <p style="white-space:pre-wrap;margin:6px 0;font-size:13.5px;">{{ $m['reply'] }}</p>
          <div class="wi-muted">Replied by {{ $m['repliedBy'] ?? 'Admin' }}@if(!empty($m['repliedAt'])) on {{ $fmt($m['repliedAt']) }}@endif</div>
        </div>
      @endif
      <div class="wi-kv"><b>Status</b><span><span class="wi-pill {{ $st[1] }}">{{ $st[0] }}</span></span></div>
      <div class="wi-dialog-actions">
        <button type="button" class="wi-btn" onclick="this.closest('dialog').close()">Close</button>
        @if(($m['status'] ?? '') !== 'archived')
          <button type="button" class="wi-btn primary" onclick="this.closest('dialog').close(); document.getElementById('wi-reply-{{ $m['_id'] }}').showModal();">Reply</button>
        @endif
      </div>
    </div>
  </dialog>

  @if(($m['status'] ?? '') !== 'archived')
    <dialog class="wi-dialog wide" id="wi-reply-{{ $m['_id'] }}">
      <form method="POST" action="{{ route('wa.inbox.messages.reply', $m['_id']) }}" class="wi-dialog-body">
        {{ csrf_field() }}
        <h3>Reply to message</h3>
        <p class="sub">Send a reply to {{ $m['name'] ?? '' }} ({{ $m['email'] ?? '' }}).</p>
        <div class="wi-box">
          <div style="font-size:13px;"><b>Subject:</b> {{ $m['subject'] ?? '' }}</div>
          <p style="white-space:pre-wrap;margin:6px 0 0;font-size:13px;color:#374151;">{{ $m['message'] ?? '' }}</p>
        </div>
        <textarea name="reply" rows="7" placeholder="Type your reply here." required>{{ $m['reply'] ?? '' }}</textarea>
        <p style="font-size:12px;color:#15803d;margin:6px 0 0;">Your reply is emailed to the customer automatically.</p>
        <div class="wi-dialog-actions">
          <button type="button" class="wi-btn" onclick="this.closest('dialog').close()">Cancel</button>
          <button class="wi-btn primary">Send reply</button>
        </div>
      </form>
    </dialog>
  @endif
@endforeach
@endsection
