@extends('layouts.app')
@section('title', 'All Orders')
@section('content')
{{-- All Orders (Sarah, 2026-10-22): every order ever, nothing hidden, newest
     first. Read-only, so it is always the source of truth. Built by
     WebsiteOrdersController::allOrders() from the website's /erp/orders/all. --}}
<style>
  .ao-wrap { padding:20px; max-width:1500px; color:#000; }
  .ao-wrap h1 { font-size:24px; margin:0 0 4px; color:#000; }
  .ao-wrap .lead { margin:0 0 14px; font-size:15px; }
  .ao-src { display:flex; gap:20px; margin:0 0 12px; font-size:16px; font-weight:700; }
  .ao-src a { color:#000; text-decoration:none; }
  .ao-src a.on { color:#1e40af; text-decoration:underline; text-underline-offset:5px; }
  .ao-bar { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin:0 0 12px; }
  .ao-bar input { flex:1 1 320px; font-size:15px; padding:9px 12px; border:1px solid #d1d5db; border-radius:8px; }
  .ao-bar button { font-size:15px; padding:9px 16px; border:none; border-radius:8px; background:#1e3a8a; color:#fff; font-weight:700; }
  .ao-scroll { overflow-x:auto; background:#fff; border:1px solid #e5e7eb; border-radius:10px; }
  .ao-tbl { width:100%; min-width:1200px; border-collapse:collapse; font-size:14px; }
  .ao-tbl th { text-align:left; font-size:12px; text-transform:uppercase; letter-spacing:.03em; padding:10px; border-bottom:1px solid #e5e7eb; background:#f9fafb; color:#000; }
  .ao-tbl td { padding:9px 10px; border-bottom:1px solid #f1f5f9; vertical-align:top; color:#000; }
  .ao-tbl tr.archived td { background:#f9fafb; }
  .ao-items div { margin-bottom:2px; }
  .ao-items s { color:#000; }
  .ao-pager { display:flex; gap:18px; align-items:center; margin:14px 0; font-size:15px; font-weight:700; }
  .ao-pager a { color:#1e40af; }
  .ao-red { color:#b91c1c; font-weight:700; }
</style>
<div class="ao-wrap">
  <a href="{{ route('website-orders.index') }}" style="color:#000;font-weight:600;">&larr; Back to Orders</a>
  <h1 style="margin-top:8px;">All Orders</h1>
  <p class="lead">Every order ever placed, newest first: paid or not, shipped, cancelled, refunded, archived, duplicates too. Nothing is hidden here and nothing on this page changes an order.</p>
  <div class="ao-src">
    <a href="{{ route('website-orders.all', ['source' => 'website']) }}" class="{{ $source === 'website' ? 'on' : '' }}">Website</a>
    <a href="{{ route('website-orders.all', ['source' => 'discogs']) }}" class="{{ $source === 'discogs' ? 'on' : '' }}">Discogs</a>
  </div>
  <form method="GET" action="{{ route('website-orders.all') }}" class="ao-bar">
    <input type="hidden" name="source" value="{{ $source }}">
    <input type="text" name="q" value="{{ $q }}" placeholder="{{ $source === 'discogs' ? 'Buyer, order number or item' : 'Name, email, order number or item' }}">
    <button type="submit">Search</button>
    @if($q !== '')<a href="{{ route('website-orders.all', ['source' => $source]) }}" style="color:#000;font-weight:600;">Clear</a>@endif
  </form>
  @if($error)
    <div style="padding:14px;background:#fff;border:1px solid #fecaca;border-radius:8px;">{{ $error }}</div>
  @else
    @php $from = $total ? ($page - 1) * $per + 1 : 0; $to = min($total, $page * $per); @endphp
    <div class="ao-pager">
      <span>{{ number_format($total) }} {{ $total === 1 ? 'order' : 'orders' }}{{ $q !== '' ? ' matching "' . $q . '"' : '' }}. Showing {{ $from }} to {{ $to }}.</span>
      @if($page > 1)<a href="{{ route('website-orders.all', array_filter(['source' => $source, 'q' => $q, 'page' => $page - 1])) }}">&larr; Newer</a>@endif
      @if($to < $total)<a href="{{ route('website-orders.all', array_filter(['source' => $source, 'q' => $q, 'page' => $page + 1])) }}">Older &rarr;</a>@endif
    </div>
    <div class="ao-scroll">
      <table class="ao-tbl">
        <thead><tr>
          <th>Order</th><th>Placed</th><th>Buyer</th><th>Items</th><th>Total</th>
          @if($source === 'website')<th>Payment</th>@endif
          <th>Status</th><th>Fulfillment</th><th>Shipped</th><th>Tracking</th>
        </tr></thead>
        <tbody>
          @forelse($rows as $r)
            <tr class="{{ !empty($r['archived']) ? 'archived' : '' }}">
              <td style="white-space:nowrap;">
                @if($source === 'website')
                  <a href="{{ route('website-orders.index', ['order' => $r['id']]) }}" style="color:#1e40af;font-weight:700;">#{{ $r['number'] ?? substr($r['id'], -6) }}</a>
                @else
                  <a href="{{ $r['url'] }}" target="_blank" rel="noopener" style="color:#1e40af;font-weight:700;">{{ $r['number'] }}</a>
                @endif
                @if(!empty($r['archived']))<div style="font-size:12px;">Archived</div>@endif
              </td>
              <td style="white-space:nowrap;">{{ !empty($r['date']) ? \Carbon\Carbon::parse($r['date'])->setTimezone('America/Los_Angeles')->format('m/d/y g:ia') : '' }}</td>
              <td><strong>{{ $r['buyer'] }}</strong>@if(!empty($r['email']))<div style="font-size:12px;">{{ $r['email'] }}</div>@endif</td>
              <td class="ao-items">
                @foreach(($r['items'] ?? []) as $it)
                  <div>@if(!empty($it['cancelled']))<s>@endif{{ $it['qty'] > 1 ? $it['qty'] . ' x ' : '' }}{{ $it['name'] }}@if(!empty($it['artist'])) - {{ $it['artist'] }}@endif @if(!empty($it['price']))(${{ number_format((float) $it['price'], 2) }})@endif @if(!empty($it['cancelled']))</s> cancelled@endif</div>
                @endforeach
              </td>
              <td style="font-weight:700;">${{ number_format((float) ($r['total'] ?? 0), 2) }}</td>
              @if($source === 'website')
                <td class="{{ in_array($r['payment'] ?? '', ['failed', 'pending'], true) ? 'ao-red' : '' }}">{{ ucfirst($r['payment'] ?? '') }}@if(!empty($r['method']))<div style="font-size:12px;">{{ ucfirst($r['method']) }}</div>@endif</td>
              @endif
              <td>{{ ucwords(str_replace('_', ' ', $r['status'] ?? '')) }}</td>
              <td>{{ ucfirst($r['fulfillment'] ?? '') }}</td>
              <td style="white-space:nowrap;">{{ !empty($r['shipped_at']) ? \Carbon\Carbon::parse($r['shipped_at'])->setTimezone('America/Los_Angeles')->format('m/d/y') : '' }}</td>
              <td style="font-size:12px;word-break:break-all;">{{ $r['tracking'] ?? '' }}</td>
            </tr>
          @empty
            <tr><td colspan="10" style="padding:20px;">No orders found.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="ao-pager">
      @if($page > 1)<a href="{{ route('website-orders.all', array_filter(['source' => $source, 'q' => $q, 'page' => $page - 1])) }}">&larr; Newer</a>@endif
      @if($to < $total)<a href="{{ route('website-orders.all', array_filter(['source' => $source, 'q' => $q, 'page' => $page + 1])) }}">Older &rarr;</a>@endif
    </div>
  @endif
</div>
@endsection
