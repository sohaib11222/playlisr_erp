@extends('layouts.app')

@section('title', 'Website Featured Records')

@section('content')
@include('website_admin.catalog._styles')
@php $inp = 'font-size:13.5px; padding:6px 9px; border:1px solid #d1d5db; border-radius:7px; width:100%;'; @endphp

<div class="ev-wrap">
  <div class="ev-head">
    <div>
      <h1>Featured Records</h1>
      <p class="sub">The "what is my vinyl worth" table on the vinyl-worth page.</p>
    </div>
    <a href="https://nivessa.com/vinyl-worth" target="_blank" rel="noopener" class="wc-btn">View page</a>
  </div>
  @include('website_admin.catalog._nav')

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @if($bridgeError)<div class="alert-err">{{ $bridgeError }}</div>@endif

  @if(!$bridgeError)
    <details class="ev-card" @if(old('op') === 'add') open @endif>
      <summary class="ev-create-summary">Add a record</summary>
      <form method="POST" action="{{ route('wa.catalog.records.save') }}" class="wc-form" style="margin-top:12px;">
        {{ csrf_field() }}
        <input type="hidden" name="op" value="add">
        <div class="ev-row">
          <div class="ev-field"><label>Artist *</label><input type="text" name="artist" value="{{ old('op') === 'add' ? old('artist') : '' }}" required placeholder="The Beatles"></div>
          <div class="ev-field"><label>Album *</label><input type="text" name="album" value="{{ old('op') === 'add' ? old('album') : '' }}" required placeholder="Abbey Road"></div>
          <div class="ev-field"><label>Worth *</label><input type="text" name="worth" value="{{ old('op') === 'add' ? old('worth') : '' }}" required placeholder="$50-$500"></div>
        </div>
        <button type="submit" class="btn-accent">Add record</button>
      </form>
    </details>

    <div class="wc-card">
      <table class="wc-tbl">
        <thead><tr><th style="width:44px;">#</th><th>Artist</th><th>Album</th><th>Worth</th><th style="text-align:right;">Actions</th></tr></thead>
        <tbody>
          @forelse($records as $i => $r)
            @if($editIndex === $i)
              <tr>
                <td class="wc-muted">{{ $i + 1 }}</td>
                <td colspan="4">
                  <form method="POST" action="{{ route('wa.catalog.records.save') }}" style="display:flex; gap:8px; align-items:center; margin:0;">
                    {{ csrf_field() }}
                    <input type="hidden" name="op" value="update">
                    <input type="hidden" name="index" value="{{ $i }}">
                    <input type="text" name="artist" value="{{ $r['artist'] ?? '' }}" required style="{{ $inp }}">
                    <input type="text" name="album" value="{{ $r['album'] ?? '' }}" required style="{{ $inp }}">
                    <input type="text" name="worth" value="{{ $r['worth'] ?? '' }}" required style="{{ $inp }} max-width:140px;">
                    <button type="submit" class="wc-btn primary">Save</button>
                    <a href="{{ route('wa.catalog.records') }}" class="wc-btn">Cancel</a>
                  </form>
                </td>
              </tr>
            @else
              <tr>
                <td class="wc-muted">{{ $i + 1 }}</td>
                <td style="font-weight:600;">{{ $r['artist'] ?? '' }}</td>
                <td>{{ $r['album'] ?? '' }}</td>
                <td>{{ $r['worth'] ?? '' }}</td>
                <td>
                  <div class="wc-actions">
                    <a href="{{ route('wa.catalog.records', ['edit' => $i]) }}" class="wc-btn primary">Edit</a>
                    <form method="POST" action="{{ route('wa.catalog.records.save') }}" onsubmit="return window.confirm('Delete this record?');">
                      {{ csrf_field() }}
                      <input type="hidden" name="op" value="delete">
                      <input type="hidden" name="index" value="{{ $i }}">
                      <button type="submit" class="wc-btn danger">Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
            @endif
          @empty
            <tr><td colspan="5" class="empty">No featured records yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  @endif
</div>
@endsection
