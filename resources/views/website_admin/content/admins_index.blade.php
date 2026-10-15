@extends('layouts.app')
@section('title', 'Website Admin Logins')

@section('content')
@include('website_admin.content._styles')
@php $H = \App\Http\Controllers\WebsiteAdmin\WebsiteUsersController::class; @endphp
<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Website Admin Logins</h1>
      <p class="sub">Accounts that can sign in to the nivessa.com admin. Customer accounts are on <a href="{{ route('wa.content.users') }}">Website Customers</a>.</p>
    </div>
    <a class="wc-btn wc-btn-primary" href="{{ route('wa.content.adminsCreate') }}">Add admin login</a>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @if($bridgeError)<div class="alert-err">Could not reach the website: {{ $bridgeError }}</div>@endif

  <div class="wc-card">
    @if(count($admins) === 0)
      <div class="wc-empty">No admin accounts found.</div>
    @else
    <div class="wc-scroll">
      <table class="wc-tbl">
        <thead><tr><th style="width:60px;">#</th><th>Name</th><th>Email</th><th>Role</th><th>Created</th><th></th></tr></thead>
        <tbody>
        @foreach($admins as $i => $a)
          <tr>
            <td class="wc-muted">{{ $i + 1 }}</td>
            <td>{{ $a['name'] ?? '' }}</td>
            <td>{{ $a['email'] ?? '' }}</td>
            <td><span class="wc-pill {{ ($a['role'] ?? '') === 'admin' ? 'blue' : '' }}">{{ $a['role'] ?? '' }}</span></td>
            <td class="wc-muted" style="white-space:nowrap;">{{ $H::fmtDate($a['createdAt'] ?? null, 'M j, Y g:i A') }}</td>
            <td>
              <div class="wc-actions" style="justify-content:flex-end;">
                <a class="wc-btn wc-btn-sm" href="{{ route('wa.content.adminsEdit', $a['_id']) }}">Edit</a>
                <form method="POST" action="{{ route('wa.content.adminsDelete', $a['_id']) }}" onsubmit="return confirm({{ json_encode('Delete the admin login for ' . ($a['email'] ?? '') . '? This cannot be undone.') }});">
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
  </div>
</div>
@endsection
