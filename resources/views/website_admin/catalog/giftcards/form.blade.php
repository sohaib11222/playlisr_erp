@extends('layouts.app')

@section('title', $isNew ? 'Add Website Gift Card' : 'Edit Website Gift Card')

@section('content')
@include('website_admin.catalog._styles')
@php
  $val = function ($k, $d = '') use ($g) { return old($k, $g[$k] ?? $d); };
  $expiry = old('expiryDate', !empty($g['expiryDate']) ? substr((string) $g['expiryDate'], 0, 10) : '');
  $action = $isNew ? route('wa.catalog.giftcards.store') : route('wa.catalog.giftcards.update', $g['_id']);
@endphp

<div class="ev-wrap">
  <div class="ev-head">
    <div>
      <h1>{{ $isNew ? 'Add website gift card' : 'Edit website gift card' }}</h1>
      <p class="sub">{{ $isNew ? 'Issue a gift card that works at nivessa.com checkout.' : ($g['code'] ?? '') }}</p>
    </div>
    <a href="{{ route('wa.catalog.giftcards') }}" class="wc-btn">Back to gift cards</a>
  </div>
  @include('website_admin.catalog._nav')

  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  <form method="POST" action="{{ $action }}" class="ev-card wc-form">
    {{ csrf_field() }}
    <div class="ev-row">
      <div class="ev-field"><label>Code *</label><input type="text" name="code" value="{{ $val('code') }}" required></div>
      <div class="ev-field"><label>Amount *</label><input type="number" step="0.01" min="0" name="amount" value="{{ $val('amount') }}" required></div>
    </div>
    <div class="ev-row">
      <div class="ev-field"><label>Description</label><input type="text" name="description" value="{{ $val('description') }}"></div>
      <div class="ev-field"><label>Expiry date *</label><input type="date" name="expiryDate" value="{{ $expiry }}" required></div>
    </div>
    @if(!$isNew)
      <div class="ev-row">
        <div class="ev-field"><label>Status</label>
          <select name="status">
            @foreach($statuses as $k => $l)<option value="{{ $k }}" @if($val('status', 'active') === $k) selected @endif>{{ $l }}</option>@endforeach
          </select>
        </div>
        <div class="ev-field"></div>
      </div>
    @endif
    <div style="display:flex; gap:10px;">
      <button type="submit" class="btn-accent">{{ $isNew ? 'Add gift card' : 'Save gift card' }}</button>
      <a href="{{ route('wa.catalog.giftcards') }}" class="wc-btn" style="height:auto;">Cancel</a>
    </div>
  </form>
</div>
@endsection
