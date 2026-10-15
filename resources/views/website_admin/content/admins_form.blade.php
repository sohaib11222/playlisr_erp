@extends('layouts.app')
@section('title', $admin ? 'Edit admin login' : 'Add admin login')

@section('content')
@include('website_admin.content._styles')
@php
  $role = old('role', $admin['role'] ?? 'editor');
  $options = $roles;
  if ($role !== '' && !array_key_exists($role, $options)) { $options = [$role => ucfirst($role)] + $options; }
@endphp
<div class="ev-wrap" style="max-width:720px;">
  <a class="wc-back" href="{{ route('wa.content.admins') }}">Back to website admin logins</a>
  <div class="ev-head"><div><h1>{{ $admin ? 'Edit admin login' : 'Add admin login' }}</h1><p class="sub">A login for the nivessa.com admin.</p></div></div>

  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  <form method="POST" action="{{ $admin ? route('wa.content.adminsUpdate', $admin['_id']) : route('wa.content.adminsStore') }}" class="wc-card wc-card-pad" autocomplete="off">
    {{ csrf_field() }}
    <div class="wc-field" style="margin-bottom:12px;"><label>Name</label><input type="text" name="name" value="{{ old('name', $admin['name'] ?? '') }}" placeholder="Name" required></div>
    <div class="wc-field" style="margin-bottom:12px;"><label>Email</label><input type="email" name="email" value="{{ old('email', $admin['email'] ?? '') }}" placeholder="e.g. john@nivessa.com" required></div>
    <div class="wc-field" style="margin-bottom:12px;">
      <label>{{ $admin ? 'New password' : 'Password' }}</label>
      <input type="password" name="password" autocomplete="new-password" @if(!$admin) required @endif>
      @if($admin)<div class="wc-help">Leave blank to keep the current password.</div>@endif
    </div>
    <div class="wc-field" style="margin-bottom:12px;">
      <label>Role</label>
      <select name="role" required>
        @foreach($options as $k => $l)<option value="{{ $k }}" @if($role === $k) selected @endif>{{ $l }}</option>@endforeach
      </select>
    </div>
    <div class="wc-actions">
      <button type="submit" class="wc-btn wc-btn-primary">{{ $admin ? 'Save changes' : 'Create account' }}</button>
      <a class="wc-btn" href="{{ route('wa.content.admins') }}">Cancel</a>
    </div>
  </form>
</div>
@endsection
