@extends('layouts.app')

@section('title', 'Bin Store Check')

@section('content')
<style>
  .bc-wrap { padding:20px; max-width:1100px; }
  .bc-wrap h1 { font-size:22px; margin:0 0 6px; }
  .bc-wrap p.lead { color:#374151; margin:0 0 18px; }
  .bc-wrap h2 { font-size:17px; margin:26px 0 8px; }
  .bc-wrap table { width:100%; border-collapse:collapse; font-size:13px; background:#fff; }
  .bc-wrap th, .bc-wrap td { border-bottom:1px solid #e5e7eb; padding:7px 8px; text-align:left; color:#111827; }
  .bc-wrap th { background:#f9fafb; font-weight:600; }
  .bc-btn { background:#2563eb; color:#fff; border:0; border-radius:6px; padding:8px 14px; font-weight:600; cursor:pointer; }
  .alert-ok { background:#ecfdf5; color:#065f46; padding:10px 12px; border-radius:6px; margin-bottom:12px; }
  .alert-err { background:#fef2f2; color:#991b1b; padding:10px 12px; border-radius:6px; margin-bottom:12px; }
</style>
<div class="bc-wrap">
  <h1>Bin Store Check</h1>
  <p class="lead">Which store has each record, by the bin map in <a href="{{ url('/website-admin/warehouse/locations') }}">Warehouse Locations</a>. Most Pico## bins are at Hollywood.</p>
  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  <form method="POST" action="{{ route('website-orders.binRunDaily') }}" style="margin-bottom:12px;display:inline-block;margin-right:8px;" onsubmit="this.querySelector('button').disabled=true;">
    {{ csrf_field() }}
    <button type="submit" class="bc-btn">Run the daily check now</button>
  </form>
  <span style="color:#374151;">Runs by itself every day at 7:30 AM.</span>
  @if($canUndo)
    <form method="POST" action="{{ route('website-orders.binMoveUndo') }}" style="margin-bottom:12px;" onsubmit="this.querySelector('button').disabled=true;">
      {{ csrf_field() }}
      <button type="submit" class="bc-btn" style="background:#fff;color:#2563eb;border:1px solid #2563eb;">Undo last move to Hollywood</button>
    </form>
  @endif

  <h2>Open orders pulled from the wrong store ({{ count($orderFixes) }})</h2>
  @if($orderError)<div class="alert-err">{{ $orderError }}</div>@endif
  @if(count($orderFixes))
    <form method="POST" action="{{ route('website-orders.binCheckApply') }}" style="margin-bottom:10px;">
      {{ csrf_field() }}
      <button type="submit" class="bc-btn">Fix these {{ count($orderFixes) }} order lines</button>
    </form>
    <table>
      <tr><th>Order</th><th>Buyer</th><th>Item</th><th>Bin</th><th>Now</th><th>Should be</th></tr>
      @foreach($orderFixes as $f)
        <tr>
          <td><a href="{{ url('/website-orders?tab=to_ship&q=' . $f['orderId']) }}">#{{ $f['orderNumber'] ?? '' }}</a></td>
          <td>{{ $f['buyer'] }}</td>
          <td>@if(!empty($f['posProductId']))<a href="{{ url('/products/' . $f['posProductId'] . '/edit') }}">{{ $f['name'] }}</a>@else{{ $f['name'] }}@endif</td>
          <td>{{ $f['bin'] }}</td>
          <td>{{ $f['from'] ? ucfirst($f['from']) : 'Not set' }}</td>
          <td>{{ ucfirst($f['to']) }}</td>
        </tr>
      @endforeach
    </table>
  @elseif(!$orderError)
    <p>None. Every open order is pulled from the store its bin is in.</p>
  @endif

  <h2>In-stock records in a Hollywood bin but filed under Pico ({{ count($products) }})</h2>
  @if($productError)<div class="alert-err">{{ $productError }}</div>@endif
  @if(count($products))
    <p>These show as Pico stock in the ERP, so their sales are booked at Pico. Pico has no bins like these, so they belong at Hollywood.</p>
    <form method="POST" action="{{ route('website-orders.binMove') }}" style="margin-bottom:10px;" onsubmit="this.querySelector('button').disabled=true;">
      {{ csrf_field() }}
      <button type="submit" class="bc-btn">Move these {{ count($products) }} to Hollywood</button>
      <span style="margin-left:8px;color:#374151;">One stock transfer from Pico to Hollywood. A backup is saved and it can be undone.</span>
    </form>
    <table>
      <tr><th>Record</th><th>Bin</th><th>ERP store</th><th>Price</th></tr>
      @foreach($products as $p)
        <tr>
          <td>@if(!empty($p['posProductId']))<a href="{{ url('/products/' . $p['posProductId'] . '/edit') }}">{{ $p['name'] }}</a>@else{{ $p['name'] }}@endif</td>
          <td>{{ $p['bin'] }}</td>
          <td>{{ $p['erpStore'] }}</td>
          <td>${{ number_format((float) ($p['price'] ?? 0), 2) }}</td>
        </tr>
      @endforeach
    </table>
  @elseif(!$productError)
    <p>None.</p>
  @endif

  <h2 id="unknown">Bins that aren't on the bin map ({{ count($unknown) + count($erpUnknown) }})</h2>
  <p>A bin code that isn't in <a href="{{ url('/website-admin/warehouse/locations') }}">Warehouse Locations</a> can't be found on a pull (like "DS894", which was really GL42).</p>
  @if($unknownError)<div class="alert-err">{{ $unknownError }}</div>@endif
  @php $job = $unknownFix ?: []; @endphp
  @if(!empty($job['running']))
    <div class="alert-ok">Fixing from Discogs: {{ $job['done'] ?? 0 }} of {{ $job['total'] ?? 0 }} checked, {{ count($job['fixed'] ?? []) }} fixed so far. Reload to update.</div>
  @elseif(!empty($job['finishedAt']))
    <div class="alert-ok">Last fix ({{ date('m/d/y g:ia', strtotime($job['finishedAt'])) }}): {{ count($job['fixed'] ?? []) }} fixed from Discogs, {{ count($job['notFound'] ?? []) }} still need someone to find them.@if(!empty($job['error'])) Stopped early: {{ $job['error'] }}@endif</div>
  @endif
  @if(count($unknown) && empty($job['running']))
    <form method="POST" action="{{ route('website-orders.binUnknownFix') }}" style="margin-bottom:10px;" onsubmit="this.querySelector('button').disabled=true;">
      {{ csrf_field() }}
      <button type="submit" class="bc-btn">Fix these from Discogs</button>
      <span style="margin-left:8px;color:#374151;">Takes each record's bin from its Discogs listing when that bin is on the map. The old bin is saved on each record.</span>
    </form>
  @endif
  @php $why = collect($job['notFound'] ?? [])->keyBy('productId'); @endphp
  @if(count($unknown))
    <h3 style="font-size:15px;margin:14px 0 6px;">Website ({{ count($unknown) }})</h3>
    <table>
      <tr><th>Record</th><th>Bin</th><th>Price</th><th>Discogs</th><th>Note</th></tr>
      @foreach($unknown as $p)
        <tr>
          <td>@if(!empty($p['posProductId']))<a href="{{ url('/products/' . $p['posProductId'] . '/edit') }}">{{ $p['name'] }}</a>@else<a href="https://nivessa.com/products/{{ $p['productId'] }}" target="_blank">{{ $p['name'] }}</a>@endif</td>
          <td>{{ $p['bin'] }}</td>
          <td>${{ number_format((float) ($p['price'] ?? 0), 2) }}</td>
          <td>@if(!empty($p['discogId']))<a href="https://www.discogs.com/sell/item/{{ $p['discogId'] }}" target="_blank">{{ $p['discogId'] }}</a>@else None @endif</td>
          <td>{{ $why[$p['productId']]['why'] ?? '' }}</td>
        </tr>
      @endforeach
    </table>
  @elseif(!$unknownError)
    <p>Website: none. Every in-stock record's bin is on the map.</p>
  @endif
  @if(count($erpUnknown))
    <h3 style="font-size:15px;margin:14px 0 6px;">ERP products ({{ count($erpUnknown) }})</h3>
    <table>
      <tr><th>Product</th><th>Bin</th><th>Field</th></tr>
      @foreach($erpUnknown as $r)
        <tr>
          <td><a href="{{ url('/products/' . $r['id'] . '/edit') }}">{{ $r['name'] }}</a></td>
          <td>{{ $r['bin'] }}</td>
          <td>{{ $r['field'] }}</td>
        </tr>
      @endforeach
    </table>
  @elseif(!$unknownError)
    <p>ERP: none.</p>
  @endif

  @if(!empty($origins))
    <h2>Where the moved records came from ({{ count($origins) }})</h2>
    <p>The first time each one got stock in the ERP.</p>
    <table>
      <tr><th>Record</th><th>Created</th><th>Created by</th><th>First stock</th><th>At</th><th>By</th><th>Note</th></tr>
      @foreach($origins as $r)
        <tr>
          <td><a href="{{ url('/products/' . $r['id'] . '/edit') }}">{{ $r['name'] }}</a></td>
          <td>{{ $r['created'] ? date('m/d/y', strtotime($r['created'])) : '' }}</td>
          <td>{{ $r['created_by'] }}</td>
          <td>{{ $r['how'] }}</td>
          <td>{{ $r['where'] }}</td>
          <td>{{ $r['by'] }}</td>
          <td>{{ $r['note'] }}</td>
        </tr>
      @endforeach
    </table>
  @endif
</div>
@endsection
