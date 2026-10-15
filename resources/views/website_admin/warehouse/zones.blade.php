@extends('layouts.app')

@section('title', 'Warehouse Zones')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>
@include('events.partials._styles')
@include('website_admin.warehouse._styles')

<style>
  .zn-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:16px; }
  .zn-head { background:#eef2ff; border-bottom:1px solid #c7d2fe; padding:12px 16px; }
  .zn-head h2 { margin:0; font-size:17px; color:#312e81; }
  .zn-head div { font-size:12.5px; color:#4338ca; }
  .zn-row { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:9px 12px; border-bottom:1px solid #f3f4f6; }
  .zn-row form { margin:0; }
  .zn-row input[type=text] { flex:1; font-size:14px; padding:7px 9px; border:1px solid #d1d5db; border-radius:7px; min-width:0; }
  .zn-edit { display:none; gap:6px; align-items:center; flex:1; }
  .zn-row.editing .zn-edit { display:flex; }
  .zn-row.editing .zn-view { display:none; }
  .zn-view { display:flex; align-items:center; justify-content:space-between; gap:8px; flex:1; }
</style>

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Zones</h1>
      <p class="sub">The zones each store uses. They show up in every zone list on the Warehouse pages, for everyone.</p>
    </div>
    <details class="wh-confirm">
      <summary class="wh-btn">Reset to defaults</summary>
      <div class="wh-confirm-box">
        Custom zones will be removed.
        <form method="POST" action="{{ route('wa.warehouse.zonesSave') }}">
          {{ csrf_field() }}
          <input type="hidden" name="action" value="reset">
          <button type="submit" class="wh-btn wh-btn-sm wh-btn-red">Yes, reset</button>
        </form>
      </div>
    </details>
  </div>

  @include('website_admin.warehouse._nav')

  <form method="POST" action="{{ route('wa.warehouse.zonesSave') }}" class="wh-filters">
    {{ csrf_field() }}
    <input type="hidden" name="action" value="add">
    <div class="wh-field">
      <label>Store</label>
      <select name="store" required>
        <option value="">Pick a store</option>
        @foreach(array_keys($zones) as $store)
          <option value="{{ $store }}">{{ $store }}</option>
        @endforeach
      </select>
    </div>
    <div class="wh-field wh-grow">
      <label>New zone name</label>
      <input type="text" name="name" required placeholder="For example, Hollywood Warehouse street level">
    </div>
    <button type="submit" class="wh-btn wh-btn-primary">Add zone</button>
  </form>

  <div class="zn-grid">
    @foreach($zones as $store => $list)
      <div class="wh-card" style="margin:0;">
        <div class="zn-head"><h2>{{ $store }}</h2><div>{{ count($list) }} {{ count($list) === 1 ? 'zone' : 'zones' }}</div></div>
        @forelse($list as $zone)
          <div class="zn-row">
            <div class="zn-view">
              <span style="font-weight:600;">{{ $zone }}</span>
              @if($zone !== 'Other')
                <span style="display:flex; gap:6px;">
                  <button type="button" class="wh-btn wh-btn-sm" onclick="this.closest('.zn-row').classList.add('editing')">Rename</button>
                  <details class="wh-confirm">
                    <summary class="wh-btn wh-btn-sm wh-btn-danger">Delete</summary>
                    <div class="wh-confirm-box">
                      <form method="POST" action="{{ route('wa.warehouse.zonesSave') }}">
                        {{ csrf_field() }}
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="store" value="{{ $store }}">
                        <input type="hidden" name="old" value="{{ $zone }}">
                        <button type="submit" class="wh-btn wh-btn-sm wh-btn-red">Yes, delete</button>
                      </form>
                    </div>
                  </details>
                </span>
              @endif
            </div>
            @if($zone !== 'Other')
              <form method="POST" action="{{ route('wa.warehouse.zonesSave') }}" class="zn-edit">
                {{ csrf_field() }}
                <input type="hidden" name="action" value="rename">
                <input type="hidden" name="store" value="{{ $store }}">
                <input type="hidden" name="old" value="{{ $zone }}">
                <input type="text" name="name" value="{{ $zone }}" required>
                <button type="submit" class="wh-btn wh-btn-sm wh-btn-primary">Save</button>
                <button type="button" class="wh-btn wh-btn-sm" onclick="this.closest('.zn-row').classList.remove('editing')">Cancel</button>
              </form>
            @endif
          </div>
        @empty
          <div class="empty" style="padding:14px;">No zones yet.</div>
        @endforelse
      </div>
    @endforeach
  </div>

  <div class="wh-note warn" style="margin-top:18px;">Renaming a zone here does not change items or location codes already saved with the old name. Update those on the Locations page or the order.</div>
</div>
@endsection
