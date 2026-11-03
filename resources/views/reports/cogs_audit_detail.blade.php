@extends('layouts.app')

@section('title', 'Real COGS detail')

@section('content')
<style>
.cd-wrap { max-width: 1280px; margin: 0 auto; padding: 18px 16px 60px; color: #1F1B16; }
.cd-wrap h1 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
.cd-wrap p { margin: 0 0 14px; font-size: 14px; color: #3B3226; }
.cd-back { display: inline-block; padding: 5px 12px; border-radius: 999px; border: 1px solid #DFD2B3; color: #1F1B16; font-size: 13px; margin-bottom: 10px; }
.cd-table { width: 100%; background: #fff; border: 1px solid #ECE3CF; border-collapse: collapse; margin-top: 10px; font-size: 14px; }
.cd-table th, .cd-table td { padding: 8px 10px; border-bottom: 1px solid #ECE3CF; text-align: right; color: #1F1B16; }
.cd-table th:nth-child(-n+4), .cd-table td:nth-child(-n+4) { text-align: left; }
.cd-table th { background: #F7F1E3; font-weight: 700; }
.cd-flag { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 12px; font-weight: 600; background: #FBE3E3; color: #7A1F1F; }
</style>
<div class="cd-wrap">
  <a class="cd-back" href="{{ url('/reports/cogs-audit') }}?start_date={{ $start }}&end_date={{ $end }}">Back to all channels</a>
  <h1>{{ $channel }}</h1>
  <p>Every sold line behind this channel for {{ \Carbon\Carbon::parse($start)->format('m/d/y') }} to {{ \Carbon\Carbon::parse($end)->format('m/d/y') }}. Dearest cost first, so anything mispriced shows up at the top. A line is flagged when the cost attached to it is more than the price it sold for.</p>

  <table class="cd-table">
    <thead>
      <tr>
        <th>Date</th><th>Invoice</th><th>Store</th><th>Item</th>
        <th>Sold qty</th><th>Returned</th><th>Unit price</th>
        <th>Costed qty</th><th>Unit cost</th><th>Line cost</th><th>Bought on</th><th></th>
      </tr>
    </thead>
    <tbody>
    @forelse($lines as $l)
      @php
        $netQty = (float) $l->quantity - (float) $l->quantity_returned;
        $costQty = (float) $l->mapped_qty - (float) $l->mapped_returned;
        $lineCost = $costQty * (float) $l->unit_cost;
        $lineRev = $netQty * (float) $l->unit_price_inc_tax;
      @endphp
      <tr>
        <td>{{ \Carbon\Carbon::parse($l->transaction_date)->format('m/d/y') }}</td>
        <td>{{ $l->invoice_no }}</td>
        <td>{{ $locations[$l->location_id] ?? $l->location_id }}</td>
        <td>{{ $l->product }}</td>
        <td>{{ rtrim(rtrim(number_format($netQty, 2), '0'), '.') }}</td>
        <td>{{ rtrim(rtrim(number_format((float) $l->quantity_returned, 2), '0'), '.') }}</td>
        <td>${{ number_format((float) $l->unit_price_inc_tax, 2) }}</td>
        <td>{{ rtrim(rtrim(number_format($costQty, 2), '0'), '.') }}</td>
        <td>${{ number_format((float) $l->unit_cost, 2) }}</td>
        <td>${{ number_format($lineCost, 2) }}</td>
        <td>@if($l->purchase_id)<a href="{{ url('/purchases/' . $l->purchase_id . '/edit') }}">{{ strip_tags($l->purchase_ref) }}</a>@endif</td>
        <td>@if($lineCost > $lineRev)<span class="cd-flag">cost above price</span>@endif</td>
      </tr>
    @empty
      <tr><td colspan="12">No lines in this range.</td></tr>
    @endforelse
    </tbody>
  </table>
</div>
@endsection
