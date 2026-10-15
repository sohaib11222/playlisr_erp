@extends('layouts.app')

@section('title', 'Warehouse Employee Analytics')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>
@include('events.partials._styles')
@include('website_admin.warehouse._styles')

@php
  $q = $useRange ? ['start' => $start, 'end' => $end] : ['date' => $date];
  $today = (new \DateTime('now', new \DateTimeZone('America/Los_Angeles')))->format('Y-m-d');
@endphp

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Employee Daily Summary</h1>
      <p class="sub">Items each person picked and orders they shipped. Picks made in the ERP show under the ERP user's name.</p>
    </div>
    <div style="display:flex; gap:8px;">
      <a class="wh-btn" href="{{ route('wa.warehouse.analytics', $q) }}">Refresh</a>
      <a class="wh-btn" href="{{ route('wa.warehouse.analytics', $q + ['export' => 'csv']) }}" @if(!count($rows)) style="pointer-events:none; opacity:.5;" @endif>Export CSV</a>
    </div>
  </div>

  @include('website_admin.warehouse._nav')

  <div class="wh-filters">
    <form method="GET" action="{{ route('wa.warehouse.analytics') }}" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
      <div class="wh-field">
        <label>Single date</label>
        <input type="date" name="date" value="{{ $useRange ? '' : $date }}" onchange="this.form.submit()">
      </div>
      <a class="wh-btn" href="{{ route('wa.warehouse.analytics', ['date' => $today]) }}">Today</a>
    </form>
    <form method="GET" action="{{ route('wa.warehouse.analytics') }}" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
      <div class="wh-field">
        <label>From</label>
        <input type="date" name="start" value="{{ $start }}" required>
      </div>
      <div class="wh-field">
        <label>To</label>
        <input type="date" name="end" value="{{ $end }}" required>
      </div>
      <button type="submit" class="wh-btn wh-btn-primary">Show range</button>
    </form>
    <div class="wh-check" style="padding-bottom:9px;">Viewing <b style="margin-left:4px;">{{ $label }}</b></div>
  </div>

  @if($error)<div class="wh-note err">{{ $error }}</div>@endif

  <div class="wh-stats">
    <div class="wh-stat"><div class="l">Active Employees</div><div class="v">{{ $totals['total_employees'] ?? 0 }}</div><div class="h">People who worked</div></div>
    <div class="wh-stat blue"><div class="l">Items Picked</div><div class="v">{{ $totals['total_items_picked'] ?? 0 }}</div><div class="h">Total items (quantity)</div></div>
    <div class="wh-stat green"><div class="l">Unique Items</div><div class="v">{{ $totals['total_unique_items'] ?? 0 }}</div><div class="h">Unique records picked</div></div>
    <div class="wh-stat purple"><div class="l">Orders Shipped</div><div class="v">{{ $totals['total_shipped_orders'] ?? 0 }}</div><div class="h">Fully completed</div></div>
  </div>

  <div class="wh-card">
    <div class="wh-card-pad" style="padding-bottom:0;"><h2>Employee performance</h2></div>
    @if(!count($rows))
      <div class="wh-card-pad empty">No activity for this date. Try another date or a range.</div>
    @else
      <div class="wh-scroll">
        <table class="wh-tbl">
          <thead><tr><th>#</th><th>Employee</th><th>Email</th><th>Items Picked (Qty)</th><th>Unique Items</th><th>Orders Worked</th><th>Orders Shipped</th><th>Performance</th></tr></thead>
          <tbody>
            @foreach($rows as $i => $r)
              @php
                $picked = (int) ($r['items_picked'] ?? 0);
                $shipped = (int) ($r['shipped_orders_count'] ?? 0);
                if ($picked >= 50 && $shipped >= 5) { $perf = 'Excellent'; $pc = 'green'; }
                elseif ($picked >= 30 || $shipped >= 3) { $perf = 'Good'; $pc = 'blue'; }
                elseif ($picked < 10 && $shipped === 0) { $perf = 'Low'; $pc = 'red'; }
                else { $perf = 'Average'; $pc = ''; }
              @endphp
              <tr>
                <td>{{ $i + 1 }}</td>
                <td style="font-weight:600;">{{ $r['employee_name'] ?? 'Unknown' }}</td>
                <td class="wh-muted">{{ ($r['employee_email'] ?? '') ?: 'None' }}</td>
                <td><span class="wh-pill blue">{{ $picked }}</span></td>
                <td><span class="wh-pill green">{{ (int) ($r['unique_items_picked'] ?? 0) }}</span></td>
                <td><span class="wh-pill">{{ (int) ($r['orders_worked_on'] ?? 0) }}</span></td>
                <td><span class="wh-pill purple">{{ $shipped }}</span></td>
                <td><span class="wh-pill {{ $pc }}">{{ $perf }}</span></td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>
      <div class="wh-card-pad wh-muted" style="font-size:13.5px;">
        {{ $totals['total_employees'] ?? 0 }} people picked {{ $totals['total_items_picked'] ?? 0 }} items ({{ $totals['total_unique_items'] ?? 0 }} unique records) and completed {{ $totals['total_shipped_orders'] ?? 0 }} orders on {{ $label }}.
      </div>
    @endif
  </div>
</div>
@endsection
