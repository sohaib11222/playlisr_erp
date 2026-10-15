@extends('layouts.app')
@section('title', 'Subscriber List')

@section('content')
@include('website_admin.content._styles')
@php $H = \App\Http\Controllers\WebsiteAdmin\SubscribersController::class; @endphp
<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Subscriber List</h1>
      <p class="sub">Everyone signed up for email on nivessa.com. To sync with Brevo, import, export or send, use <a href="{{ route('wa.content.emailSubscribers') }}">Email Subscribers</a>. Brevo lists pulled into the ERP are on <a href="{{ url('/marketing/email-lists') }}">Email Lists</a>.</p>
    </div>
  </div>

  @if($bridgeError)<div class="alert-err">Could not reach the website: {{ $bridgeError }}</div>@endif

  <form method="GET" class="wc-filters">
    <div class="wc-field" style="flex:1; min-width:220px;">
      <label>Search</label>
      <input type="text" name="search" value="{{ $search }}" placeholder="Search by name or email">
    </div>
    <button type="submit" class="wc-btn wc-btn-primary">Search</button>
    @if($search !== '')<a class="wc-btn" href="{{ route('wa.content.subscriberList') }}">Clear</a>@endif
  </form>

  <div class="wc-card">
    @if(count($rows) === 0)
      <div class="wc-empty">No subscribers found.</div>
    @else
    <div class="wc-scroll">
      <table class="wc-tbl">
        <thead><tr><th style="width:60px;">#</th><th>Email</th><th>First name</th><th>Last name</th><th>Created</th></tr></thead>
        <tbody>
          @foreach($rows as $i => $s)
          <tr>
            <td class="wc-muted">{{ ($page - 1) * $limit + $i + 1 }}</td>
            <td>{{ $s['email'] ?? '' }}</td>
            <td>{{ $s['first_name'] ?? '' }}</td>
            <td>{{ $s['last_name'] ?? '' }}</td>
            <td class="wc-muted" style="white-space:nowrap;">{{ $H::fmtDate($s['createdAt'] ?? null, 'M j, Y g:i A') }}</td>
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
