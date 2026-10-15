@extends('layouts.app')

@section('title', 'Website Gift Cards')

@section('content')
@include('website_admin.catalog._styles')
@php
  $statusPill = ['active' => 'green', 'used' => 'blue', 'expired' => 'red'];
  $d = function ($v, $fmt) { return $v ? \Carbon\Carbon::parse($v)->setTimezone('America/Los_Angeles')->format($fmt) : '-'; };
@endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Website Gift Cards</h1>
      <p class="sub">Gift cards sold or issued on nivessa.com. In-store ERP gift cards are on the Gift Cards page.</p>
    </div>
    <a href="{{ route('wa.catalog.giftcards.create') }}" class="wc-btn primary">Add gift card</a>
  </div>
  @include('website_admin.catalog._nav')

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @if($bridgeError)<div class="alert-err">{{ $bridgeError }}</div>@endif

  <div class="wc-card">
    <div class="wc-scroll">
      <table class="wc-tbl" style="min-width:1250px;">
        <thead>
          <tr>
            <th style="width:44px;">#</th><th>Code</th><th>Amount</th><th>Description</th><th>Bought by</th><th>Received by</th>
            <th>Expires</th><th>Status</th><th>Used at</th><th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          @forelse($rows as $i => $g)
            @php
              $buyer = ($g['sender_name'] ?? '') ?: (is_array($g['user_id'] ?? null) ? ($g['user_id']['name'] ?? '') : '');
              $buyerEmail = is_array($g['user_id'] ?? null) ? ($g['user_id']['email'] ?? '') : '';
              $st = $g['status'] ?? '';
            @endphp
            <tr>
              <td class="wc-muted">{{ ($page - 1) * $limit + $i + 1 }}</td>
              <td style="font-family:monospace; font-weight:600;">{{ $g['code'] ?? '' }}</td>
              <td>${{ number_format((float) ($g['amount'] ?? 0), 2) }}</td>
              <td>{{ $g['description'] ?? '' }}</td>
              <td>
                @if($buyer || $buyerEmail)
                  {{ $buyer }}@if($buyerEmail)<div class="wc-muted">{{ $buyerEmail }}</div>@endif
                @else - @endif
              </td>
              <td>
                @if(!empty($g['recipient_name']) || !empty($g['recipient_email']))
                  {{ $g['recipient_name'] ?? '' }}@if(!empty($g['recipient_email']))<div class="wc-muted">{{ $g['recipient_email'] }}</div>@endif
                @else - @endif
              </td>
              <td>{{ $d($g['expiryDate'] ?? null, 'M d, Y') }}</td>
              <td><span class="wc-pill {{ $statusPill[$st] ?? 'gray' }}">{{ ucfirst($st) }}</span></td>
              <td class="wc-muted">{{ $d($g['used_at'] ?? null, 'M j, Y g:i A') }}</td>
              <td>
                <div class="wc-actions">
                  <details style="position:relative;">
                    <summary class="wc-btn" style="list-style:none;">Resend</summary>
                    <form method="POST" action="{{ route('wa.catalog.giftcards.resend', $g['_id']) }}" style="position:absolute; right:0; z-index:20; background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:10px; box-shadow:0 8px 24px rgba(0,0,0,.12); width:280px;">
                      {{ csrf_field() }}
                      <label class="wc-muted" style="display:block; margin-bottom:4px;">Send to (leave blank for the saved recipient)</label>
                      <input type="email" name="to" value="{{ $g['recipient_email'] ?? '' }}" style="width:100%; font-size:13px; padding:6px 9px; border:1px solid #d1d5db; border-radius:7px; margin-bottom:8px;">
                      <button type="submit" class="wc-btn primary">Send email</button>
                    </form>
                  </details>
                  <a href="{{ route('wa.catalog.giftcards.edit', $g['_id']) }}" class="wc-btn primary">Edit</a>
                  <form method="POST" action="{{ route('wa.catalog.giftcards.delete', $g['_id']) }}" onsubmit="return window.confirm('Delete gift card {{ addslashes($g['code'] ?? '') }}? This cannot be undone.');">
                    {{ csrf_field() }}
                    <button type="submit" class="wc-btn danger">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          @empty
            <tr><td colspan="10" class="empty">No gift cards found.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>

  <div class="wc-pager">
    @if($page > 1)<a class="wc-btn" href="{{ request()->fullUrlWithQuery(['page' => $page - 1]) }}">Previous</a>@endif
    <span>Page {{ $page }} of {{ $pages }} ({{ number_format($total) }} cards)</span>
    @if($page < $pages)<a class="wc-btn" href="{{ request()->fullUrlWithQuery(['page' => $page + 1]) }}">Next</a>@endif
  </div>
</div>
@endsection
