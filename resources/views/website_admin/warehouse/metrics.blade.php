@extends('layouts.app')

@section('title', 'Warehouse Metrics')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>
@include('events.partials._styles')
@include('website_admin.warehouse._styles')

@php
  $month = (new \DateTime('now', new \DateTimeZone('America/Los_Angeles')))->format('F Y');
  $n = fn($v, $d = 2) => number_format((float) ($v ?? 0), $d);
@endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Warehouse Metrics</h1>
      <p class="sub">Order status, money and performance for the warehouse, all time and this month.</p>
    </div>
    <a class="wh-btn" href="{{ route('wa.warehouse.metrics') }}">Refresh</a>
  </div>

  @include('website_admin.warehouse._nav')

  @if(!$m)
    <div class="wh-note err">Could not load the metrics: {{ $error }}</div>
  @else
    @php
      $oo = $m['overall']['operational'] ?? []; $of = $m['overall']['financial'] ?? []; $ok = $m['overall']['kpis'] ?? [];
      $mo = $m['thisMonth']['operational'] ?? []; $mf = $m['thisMonth']['financial'] ?? []; $mk = $m['thisMonth']['kpis'] ?? [];
      $topZones = $m['topZones'] ?? [];
      $topCount = max(1, (int) ($topZones[0]['orderCount'] ?? 1));
    @endphp

    <div class="wh-note info">
      <b>Overall</b> is the warehouse right now, every active order whatever its date. <b>This month</b> counts only orders received in {{ $month }}. Orders move from Not Started to In Progress, then Issues if anything is missing, then Completed.
    </div>

    @php
      $sections = [
        ['title' => 'Overall, all time', 'ops' => $oo, 'fin' => $of, 'kpi' => $ok, 'month' => false],
        ['title' => 'This month, ' . $month, 'ops' => $mo, 'fin' => $mf, 'kpi' => $mk, 'month' => true],
      ];
    @endphp
    @foreach($sections as $sec)
      @php
        $title = $sec['title']; $ops = $sec['ops']; $fin = $sec['fin']; $kpi = $sec['kpi']; $isMonth = $sec['month'];
      @endphp
      <div class="wh-card wh-card-pad">
        <h2>{{ $title }}</h2>
        <div class="wh-lbl" style="margin-bottom:8px;">Order status</div>
        <div class="wh-stats">
          <div class="wh-stat amber"><div class="l">Pending</div><div class="v">{{ $ops['pendingOrders'] ?? 0 }}</div><div class="h">Discogs status Payment Received</div></div>
          <div class="wh-stat blue"><div class="l">In Progress</div><div class="v">{{ $ops['inProgressOrders'] ?? 0 }}</div><div class="h">Discogs status In Progress</div></div>
          <div class="wh-stat red"><div class="l">Overdue</div><div class="v">{{ $ops['overdueOrders'] ?? 0 }}</div><div class="h">Taking more than 48 hours</div></div>
          <div class="wh-stat green"><div class="l">Completed</div><div class="v">{{ $ops['completedOrders'] ?? 0 }}</div><div class="h">Discogs status Shipped</div></div>
          <div class="wh-stat purple"><div class="l">Active Orders</div><div class="v">{{ (int) ($ops['pendingOrders'] ?? 0) + (int) ($ops['inProgressOrders'] ?? 0) }}</div><div class="h">Pending plus in progress</div></div>
        </div>

        <div class="wh-lbl" style="margin-bottom:8px;">Money</div>
        <div class="wh-stats">
          <div class="wh-stat green"><div class="l">{{ $isMonth ? 'Revenue (Completed)' : 'Total Revenue' }}</div><div class="v">${{ $n($fin['revenue'] ?? 0) }}</div><div class="h">{{ $isMonth ? 'From ' . ($fin['totalOrders'] ?? 0) . ' completed orders' : 'From all completed orders' }}</div></div>
          <div class="wh-stat red"><div class="l">Refunded</div><div class="v">${{ $n($fin['refunded'] ?? 0) }}</div><div class="h">Refunded to customers</div></div>
          <div class="wh-stat purple"><div class="l">Pending Value</div><div class="v">${{ $n($ops['pendingFulfillmentValue'] ?? 0, 0) }}</div><div class="h">Value of unfulfilled orders</div></div>
          <div class="wh-stat blue"><div class="l">Avg Order Value</div><div class="v">${{ $n($fin['averageOrderValue'] ?? 0) }}</div><div class="h">Revenue divided by orders</div></div>
        </div>

        <div class="wh-lbl" style="margin-bottom:8px;">Performance</div>
        <div class="wh-stats" style="margin-bottom:0;">
          @if($isMonth)
            <div class="wh-stat purple"><div class="l">Total Received</div><div class="v">{{ $kpi['totalReceived'] ?? 0 }}</div><div class="h">Orders received this month</div></div>
          @else
            <div class="wh-stat purple"><div class="l">Total Active</div><div class="v">{{ (int) ($ops['pendingOrders'] ?? 0) + (int) ($ops['inProgressOrders'] ?? 0) }}</div><div class="h">Orders needing warehouse work</div></div>
          @endif
          <div class="wh-stat"><div class="l">Avg Pick Time</div><div class="v">{{ $n($kpi['avgPickTimeHours'] ?? 0, 1) }} hrs</div><div class="h">Order received to completed</div></div>
          @if($isMonth)
            <div class="wh-stat green"><div class="l">Completion Rate</div><div class="v">{{ $n($kpi['completionRate'] ?? 0, 1) }}%</div><div class="h">Completed versus received</div></div>
            <div class="wh-stat red"><div class="l">Cancellation Rate</div><div class="v">{{ $n($kpi['cancellationRate'] ?? 0, 1) }}%</div><div class="h">{{ $ops['canceledOrders'] ?? 0 }} cancelled orders</div></div>
          @else
            <div class="wh-stat green"><div class="l">On-Time Rate</div><div class="v">{{ $n($kpi['onTimeFulfillmentPercent'] ?? 0, 1) }}%</div><div class="h">Completed within 48 hours</div></div>
            <div class="wh-stat red"><div class="l">Error Rate</div><div class="v">{{ $n($kpi['errorRatePercent'] ?? 0, 1) }}%</div><div class="h">Orders with issues or special notes</div></div>
          @endif
        </div>
      </div>
    @endforeach

    <div class="wh-card">
      <div class="wh-card-pad" style="padding-bottom:0;"><h2>Most active zones, all time</h2></div>
      @if(!count($topZones))
        <div class="wh-card-pad empty">No zone data yet. It fills in as orders are picked.</div>
      @else
        <table class="wh-tbl">
          <thead><tr><th>Rank</th><th>Zone</th><th>Orders</th><th>Items</th><th>Activity</th></tr></thead>
          <tbody>
            @foreach($topZones as $i => $z)
              @php $pct = min(100, round(((int) ($z['orderCount'] ?? 0)) / $topCount * 100)); @endphp
              <tr>
                <td><b>{{ $i + 1 }}</b></td>
                <td style="font-weight:600;">{{ ($z['zone'] ?? '') ?: 'Unknown zone' }}</td>
                <td>{{ $z['orderCount'] ?? 0 }} orders</td>
                <td>{{ $z['itemCount'] ?? 0 }} items</td>
                <td style="white-space:nowrap;"><span class="wh-bar" style="width:160px;"><span style="width:{{ $pct }}%; background:#4f46e5;"></span></span>{{ $pct }}%</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      @endif
      <div class="wh-card-pad wh-muted" style="font-size:13px;">Shows which zones get picked from most. Use it to place stock and plan staff.</div>
    </div>
  @endif
</div>
@endsection
