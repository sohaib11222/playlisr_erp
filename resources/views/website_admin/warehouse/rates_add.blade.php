@extends('layouts.app')

@section('title', 'Add Shipping Rates')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>
@include('events.partials._styles')
@include('website_admin.warehouse._styles')

<div class="ev-wrap">
  <div class="ev-head">
    <div>
      <h1>Add Shipping Rates</h1>
      <p class="sub">Both rates are in dollars.</p>
    </div>
    <a class="wh-btn" href="{{ route('wa.warehouse.rates') }}">Back to shipping rates</a>
  </div>

  @include('website_admin.warehouse._nav')

  <form method="POST" action="{{ route('wa.warehouse.ratesStore') }}" class="ev-card">
    {{ csrf_field() }}
    <div class="ev-row">
      <div class="ev-field">
        <label for="rt-usa">USA</label>
        <input id="rt-usa" type="number" step="0.01" min="0" name="usa" value="{{ old('usa') }}" placeholder="3" required>
      </div>
      <div class="ev-field">
        <label for="rt-intl">International</label>
        <input id="rt-intl" type="number" step="0.01" min="0" name="international" value="{{ old('international') }}" placeholder="15" required>
      </div>
    </div>
    <button type="submit" class="wh-btn wh-btn-primary">Add rates</button>
  </form>
</div>
@endsection
