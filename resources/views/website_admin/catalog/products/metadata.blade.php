@extends('layouts.app')

@section('title', 'Website Product Metadata')

@section('content')
@include('website_admin.catalog._styles')
@php $labels = ['productType' => 'Product Type', 'format' => 'Format', 'genre' => 'Genre']; @endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Product Metadata</h1>
      <p class="sub">The product types, formats and genres offered on the website product edit form.</p>
    </div>
  </div>
  @include('website_admin.catalog._nav')

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @if($bridgeError)<div class="alert-err">{{ $bridgeError }}</div>@endif

  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:16px;">
    @foreach($labels as $type => $label)
      <div class="wc-card pad">
        <h2>{{ $label }}s <span class="wc-muted">({{ count($metadata[$type]) }})</span></h2>
        <form method="POST" action="{{ route('wa.catalog.metadata.change') }}" style="display:flex; gap:6px; margin-bottom:12px;">
          {{ csrf_field() }}
          <input type="hidden" name="type" value="{{ $type }}">
          <input type="hidden" name="op" value="add">
          <input type="text" name="value" required placeholder="Add a {{ strtolower($label) }}" style="flex:1; font-size:13.5px; padding:7px 10px; border:1px solid #d1d5db; border-radius:8px;">
          <button type="submit" class="wc-btn primary">Add</button>
        </form>
        <div style="max-height:420px; overflow-y:auto;">
          @forelse($metadata[$type] as $val)
            <div style="display:flex; justify-content:space-between; align-items:center; padding:7px 10px; border:1px solid #f0f0f0; border-radius:7px; margin-bottom:5px; background:#fafafa;">
              <span style="font-size:13.5px;">{{ $val }}</span>
              <form method="POST" action="{{ route('wa.catalog.metadata.change') }}" style="margin:0;" onsubmit="return window.confirm('Remove {{ addslashes($val) }}?');">
                {{ csrf_field() }}
                <input type="hidden" name="type" value="{{ $type }}">
                <input type="hidden" name="op" value="remove">
                <input type="hidden" name="value" value="{{ $val }}">
                <button type="submit" class="btn-link">Remove</button>
              </form>
            </div>
          @empty
            <p class="empty">No {{ strtolower($label) }}s added yet.</p>
          @endforelse
        </div>
      </div>
    @endforeach
  </div>

  <div class="wc-card pad">
    <h2>Tips</h2>
    <p class="wc-muted" style="margin:0;">New values show up in the website product edit form right away. Removing a value does not change products that already use it.</p>
  </div>
</div>
@endsection
