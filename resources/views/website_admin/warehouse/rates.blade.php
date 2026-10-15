@extends('layouts.app')

@section('title', 'Shipping Rates')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>
@include('events.partials._styles')
@include('website_admin.warehouse._styles')

<style>
  .rt-in { width:110px; font-size:15px; padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; }
</style>

<div class="ev-wrap">
  <div class="ev-head">
    <div>
      <h1>Shipping Rates</h1>
      <p class="sub">The flat shipping rates nivessa.com charges, in dollars.</p>
    </div>
    <a class="wh-btn wh-btn-primary" href="{{ route('wa.warehouse.ratesAdd') }}">Add shipping rates</a>
  </div>

  @include('website_admin.warehouse._nav')

  <div class="wh-card">
    @if($error)
      <div class="wh-card-pad"><div class="wh-note err" style="margin:0;">{{ $error }}</div></div>
    @elseif(!count($rates))
      <div class="wh-card-pad empty">No shipping rates yet.</div>
    @else
      <table class="wh-tbl">
        <thead><tr><th>#</th><th>USA ($)</th><th>International ($)</th><th></th></tr></thead>
        <tbody>
          @foreach($rates as $i => $r)
            <tr>
              <td style="vertical-align:middle;">{{ $i + 1 }}</td>
              <td colspan="2">
                <form method="POST" action="{{ route('wa.warehouse.ratesUpdate', $r['_id'] ?? '') }}" id="rt-f{{ $i }}" style="display:flex; gap:24px; align-items:center;">
                  {{ csrf_field() }}
                  <input class="rt-in" type="number" step="0.01" min="0" name="usa" value="{{ $r['usa'] ?? '' }}" required aria-label="USA rate">
                  <input class="rt-in" type="number" step="0.01" min="0" name="international" value="{{ $r['international'] ?? '' }}" required aria-label="International rate">
                </form>
              </td>
              <td style="white-space:nowrap; text-align:right; vertical-align:middle;">
                <button type="submit" form="rt-f{{ $i }}" class="wh-btn wh-btn-sm wh-btn-primary">Save</button>
                <details class="wh-confirm">
                  <summary class="wh-btn wh-btn-sm wh-btn-danger">Delete</summary>
                  <div class="wh-confirm-box">
                    This cannot be undone.
                    <form method="POST" action="{{ route('wa.warehouse.ratesDelete', $r['_id'] ?? '') }}">
                      {{ csrf_field() }}
                      <button type="submit" class="wh-btn wh-btn-sm wh-btn-red">Yes, delete</button>
                    </form>
                  </div>
                </details>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>
@endsection
