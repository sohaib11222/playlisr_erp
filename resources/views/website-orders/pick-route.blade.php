@extends('layouts.app')

@section('title', 'Pick Route')

@section('content')
{{-- Pick route (Sarah, 2026-10-16): every item still to pull at one spot,
     in walking order (area, then bin or genre, then artist), so the picker
     does one pass instead of running around. Built by
     WebsiteOrdersController::pickRoute(). --}}
<style>
  .pr-wrap { padding:20px; max-width:1400px; }
  .pr-wrap h1 { font-size:22px; margin:0 0 6px; color:#000; }
  .pr-wrap p.lead { color:#000; margin:0 0 14px; }
  .pr-tabs { display:flex; gap:8px; margin:0 0 16px; flex-wrap:wrap; }
  .pr-tabs a { padding:4px 0; color:#000; font-weight:700; text-decoration:none; margin-right:14px; }
  .pr-tabs a.on { color:#1e40af; text-decoration:underline; text-underline-offset:4px; }
  .pr-area { font-size:16px; font-weight:700; color:#000; margin:22px 0 6px; padding-bottom:4px; border-bottom:2px solid #e5e7eb; }
  .pr-wrap table { width:100%; border-collapse:collapse; font-size:14px; background:#fff; }
  .pr-wrap th, .pr-wrap td { border-bottom:1px solid #e5e7eb; padding:8px; text-align:left; color:#000; vertical-align:top; }
  .pr-wrap th { font-size:12px; text-transform:uppercase; letter-spacing:.03em; }
  .pr-bin { display:inline-block; padding:2px 8px; border-radius:5px; border:2px solid #000; font-weight:800; font-size:15px; }
  tr.is-late td:first-child { box-shadow:inset 4px 0 0 #b91c1c; }
  .pr-n { width:34px; font-weight:700; }
  .pr-act button { font-size:13px; padding:6px 11px; border:1px solid #d1d5db; border-radius:6px; background:#fff; color:#000; cursor:pointer; margin:0 4px 4px 0; }
  .pr-act button.go { background:#1e3a8a; border-color:#1e3a8a; color:#fff; font-weight:700; }
  .pr-act button:disabled { opacity:.5; cursor:wait; }
  .pr-state { font-size:13px; font-weight:600; color:#000; }
  .pr-state.miss { color:#b91c1c; }
  .pr-state.done { color:#047857; }
  tr.is-done td { background:#ecfdf5; }
  tr.is-missing td { background:#fef2f2; }
  tr.is-wait td { background:#fffbeb; }
  .pr-state.wait { color:#92400e; }
  .pr-empty { padding:20px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; color:#000; }
  @media print { .pr-tabs, .pr-print { display:none; } }
</style>
<div class="pr-wrap">
  <a href="{{ route('website-orders.index') }}" style="display:inline-block;margin-bottom:10px;padding:7px 14px;border-radius:6px;border:1px solid #d1d5db;background:#fff;color:#000;font-weight:600;text-decoration:none;">&larr; Back to Website Orders</a>
  <h1>Pick Route</h1>
  <p class="lead">Website and Discogs items still to pull, in walking order: room by room, bin by bin. Late orders are in red. Tap Picked or Can't find as you go.</p>
  @if(($lateCount ?? 0) > 0 || !empty($lateOnly))
    <p style="font-size:15px;font-weight:700;color:#000;margin:0 0 12px;">
      @if(!empty($lateOnly))
        Showing only the {{ $lateCount }} late {{ $lateCount === 1 ? 'item' : 'items' }}. <a href="{{ route('website-orders.pickRoute', ['store' => $store]) }}">Show everything</a>
      @else
        {{ $lateCount }} {{ $lateCount === 1 ? 'item is' : 'items are' }} for late orders. <a href="{{ route('website-orders.pickRoute', ['store' => $store, 'late' => 1]) }}">Pull just those first</a>
      @endif
    </p>
  @endif

  <div class="pr-tabs">
    @foreach(['hollywood' => 'Hollywood Store', 'pico' => 'Pico Store', 'warehouse' => 'Warehouse'] as $key => $label)
      <a href="{{ route('website-orders.pickRoute', ['store' => $key]) }}" class="{{ $store === $key ? 'on' : '' }}">{{ $label }} ({{ $counts[$key] ?? 0 }})</a>
    @endforeach
    <a href="javascript:window.print()" class="pr-print">Print</a>
  </div>

  @if($error)
    <div class="pr-empty">{{ $error }}</div>
  @elseif(empty($rows))
    <div class="pr-empty">Nothing to pull here right now.</div>
  @else
    @php
      // Store: one section per genre (bins are by genre). Warehouse: per area.
      $groups = collect($rows)->groupBy(fn($r) => $store === 'warehouse'
        ? ($r['parts']['area'] !== '' ? $r['parts']['area'] : 'No area saved')
        : \App\Http\Controllers\WebsiteOrdersController::routeGenre($r));
      $n = 0;
    @endphp
    @foreach($groups as $area => $areaRows)
      <div class="pr-area">{{ $area }} ({{ count($areaRows) }})</div>
      <table style="table-layout:fixed;">
        <colgroup><col style="width:250px"><col style="width:36px"><col style="width:76px"><col style="width:120px"><col><col style="width:140px"><col style="width:160px"><col style="width:44px"><col style="width:90px"><col style="width:150px"></colgroup>
        <thead><tr><th></th><th>#</th><th>Photo</th><th>{{ $store === 'warehouse' ? 'Bin' : 'Spot' }}</th><th>Item</th><th>Format</th><th>Genre</th><th>Qty</th><th>Due</th><th>Customer</th></tr></thead>
        <tbody>
          @foreach($areaRows as $r)
            @php
              $n++;
              $it = $r['item'];
              $o = $r['order'];
              $spot = $store === 'warehouse' ? $r['parts']['bin'] : (($r['parts']['genre'] ?? '') !== '' ? $r['parts']['genre'] : $r['parts']['bin']);
              $who = $o['display_name'] ?? (($o['shippingAddress'][0]['name'] ?? null) ?: ($o['user_id']['name'] ?? 'Guest'));
              $how = ($o['fulfillment_method'] ?? null) === 'pickup' ? 'Pickup ' . (($o['pickup_location'] ?? '') === 'pico' ? 'Pico' : 'Hollywood') : 'Ship';
            @endphp
            <tr class="{{ $r['pick_status'] === "Can't Find" ? 'is-missing' : ($r['pick_status'] === 'Pending Arrival' ? 'is-wait' : '') }} {{ ($r['due'][1] ?? false) ? 'is-late' : '' }}">
              <td class="pr-act" data-order="{{ $r['pick_order'] }}" data-item="{{ $r['pick_item'] }}">
                @if($r['pick_order'] !== '' && $r['pick_item'] !== '')
                  @if($r['pick_status'] === "Can't Find" || $r['pick_status'] === 'Pending Arrival')
                    <span class="pr-state {{ $r['pick_status'] === 'Pending Arrival' ? 'wait' : 'miss' }}">{{ $r['pick_status'] === 'Pending Arrival' ? 'Pending arrival' : "Can't find" }}</span> <button type="button" onclick="prPick(this, 'Not Picked')">Undo</button>
                  @else
                    <button type="button" class="go" onclick="prPick(this, 'Picked')">Picked</button><button type="button" onclick="prPick(this, &quot;Can't Find&quot;)">Can't find</button><button type="button" onclick="prPick(this, 'Pending Arrival')">Pending arrival</button><button type="button" onclick="prPick(this, 'Dropshipped')">Dropshipped</button>
                  @endif
                @else
                  <span class="pr-state">Not on pick list</span>
                @endif
              </td>
              <td class="pr-n">{{ $n }}</td>
              @php $img = $it['product_id']['image'] ?? ($it['product_image'] ?? null); @endphp
              <td>@if($img)<img src="{{ $img }}" alt="" loading="lazy" style="width:64px;height:64px;object-fit:cover;border-radius:6px;display:block;">@else &mdash; @endif</td>
              <td>@if($spot !== '')<span class="{{ $store === 'warehouse' ? 'pr-bin' : '' }}">{{ $store === 'warehouse' ? 'Bin ' . $spot : $spot }}</span>@else &mdash; @endif</td>
              <td><strong>{{ $r['name'] }}</strong>@if(!empty($it['product_id']['artist']))<br>{{ $it['product_id']['artist'] }}@endif</td>
              @php [$prTag] = \App\Http\Controllers\WebsiteOrdersController::itemTag($r['genre_full'] ?? null, $it['product_id']['subCategory'] ?? null, $it['product_id']['productType'] ?? null); @endphp
              <td style="font-weight:700;">{{ $prTag !== '' ? $prTag : ($r['format'] !== '' ? $r['format'] : '—') }}</td>
              <td>{{ $r['genre_full'] !== '' ? $r['genre_full'] : '—' }}</td>
              <td>{{ (int) ($it['quantity'] ?? 1) }}</td>
              <td style="{{ ($r['due'][1] ?? false) ? 'color:#b91c1c;font-weight:800;' : '' }}">{{ $r['due'][0] ?? '—' }}</td>
              <td>{{ $who }}<br><span style="font-size:12px;">{{ !empty($o['_discogs']) ? 'Discogs ' . $o['_discogs'] : $how }}</span></td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endforeach
  @endif
</div>
<script>
  // Same pick records as the Website Orders checklist (WebsiteOrdersController::pick)
  function prPick(btn, status) {
    var td = btn.closest('.pr-act');
    var tr = btn.closest('tr');
    td.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
    fetch('{{ url('/website-orders/pick') }}/' + td.dataset.order + '/' + td.dataset.item, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
      credentials: 'same-origin',
      body: JSON.stringify({ status: status })
    }).then(function (r) { return r.json().catch(function () { return { success: false }; }); })
      .then(function (res) {
        if (!res.success) throw new Error(res.message || 'Not saved.');
        var done = status === 'Picked' || status === 'Dropshipped', missing = status === "Can't Find", wait = status === 'Pending Arrival';
        tr.classList.toggle('is-done', done);
        tr.classList.toggle('is-missing', missing);
        tr.classList.toggle('is-wait', wait);
        td.innerHTML = (done || missing || wait)
          ? '<span class="pr-state ' + (done ? 'done' : (wait ? 'wait' : 'miss')) + '">' + (done ? (status === 'Dropshipped' ? 'Dropshipped' : 'Picked') : (wait ? 'Pending arrival' : "Can't find")) + '</span> <button type="button">Undo</button>'
          : '<button type="button" class="go">Picked</button><button type="button">Can\'t find</button><button type="button">Pending arrival</button><button type="button">Dropshipped</button>';
        var bs = td.querySelectorAll('button');
        if (done || missing || wait) { bs[0].onclick = function () { prPick(this, 'Not Picked'); }; }
        else { bs[0].onclick = function () { prPick(this, 'Picked'); }; bs[1].onclick = function () { prPick(this, "Can't Find"); }; bs[2].onclick = function () { prPick(this, 'Pending Arrival'); }; bs[3].onclick = function () { prPick(this, 'Dropshipped'); }; }
      })
      .catch(function (e) { alert('Pick not saved: ' + e.message); td.querySelectorAll('button').forEach(function (b) { b.disabled = false; }); });
  }
</script>
@endsection
