@extends('layouts.app')
@section('title', 'Website Customers')

@section('content')
@include('website_admin.content._styles')
@php $H = \App\Http\Controllers\WebsiteAdmin\WebsiteUsersController::class; @endphp
<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Website Customers</h1>
      <p class="sub">Customer accounts on nivessa.com. Website admin logins are on <a href="{{ route('wa.content.admins') }}">Website Admin Logins</a>.</p>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @if($bridgeError)<div class="alert-err">Could not reach the website: {{ $bridgeError }}</div>@endif

  <form method="GET" class="wc-filters">
    <div class="wc-field" style="flex:1; min-width:220px;"><label>Search</label><input type="text" name="search" value="{{ $search }}" placeholder="Search by name or email"></div>
    <button type="submit" class="wc-btn wc-btn-primary">Search</button>
    @if($search !== '')<a class="wc-btn" href="{{ route('wa.content.users') }}">Clear</a><span class="wc-muted">{{ count($users) }} {{ count($users) === 1 ? 'result' : 'results' }} in the first {{ number_format($searchCap) }} accounts.</span>@endif
  </form>

  <div class="wc-card">
    @if(count($users) === 0)
      <div class="wc-empty">{{ $search !== '' ? 'No users match "' . $search . '".' : 'No users found.' }}</div>
    @else
    <div class="wc-scroll">
      <table class="wc-tbl">
        <thead><tr><th style="width:60px;">#</th><th>Name</th><th>Email</th><th>Created</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @foreach($users as $i => $u)
          <tr>
            <td class="wc-muted">{{ $search !== '' ? $i + 1 : ($page - 1) * 9 + $i + 1 }}</td>
            <td>{{ $u['name'] ?? '' }}</td>
            <td>{{ $u['email'] ?? '' }}</td>
            <td class="wc-muted" style="white-space:nowrap;">{{ $H::fmtDate($u['createdAt'] ?? null, 'M j, Y g:i A') }}</td>
            <td><span class="wc-pill {{ !empty($u['isActive']) ? 'green' : 'red' }}">{{ !empty($u['isActive']) ? 'Active' : 'Inactive' }}</span></td>
            <td style="text-align:right;"><a class="wc-btn wc-btn-sm" href="{{ route('wa.content.usersShow', $u['_id']) }}">Detail</a></td>
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
