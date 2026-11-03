@extends('layouts.app')

@section('title', 'Real COGS by Channel')

@section('content')
<style>
.ca-wrap { max-width: 1280px; margin: 0 auto; padding: 18px 16px 60px; color: #1F1B16; }
.ca-wrap h1 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
.ca-wrap p { margin: 0 0 14px; font-size: 14px; color: #3B3226; }
.ca-form { margin: 0 0 14px; font-size: 14px; }
.ca-form input { border: 1px solid #DFD2B3; border-radius: 6px; padding: 5px 8px; color: #1F1B16; }
.ca-form button { background: #DCEBFF; border: 1px solid #DCEBFF; color: #1F4E8C; font-weight: 600; border-radius: 999px; padding: 6px 16px; }
.ca-table { width: 100%; background: #fff; border: 1px solid #ECE3CF; border-collapse: collapse; margin-top: 14px; font-size: 14px; }
.ca-table th, .ca-table td { padding: 8px 10px; border-bottom: 1px solid #ECE3CF; text-align: right; color: #1F1B16; }
.ca-table th:first-child, .ca-table td:first-child { text-align: left; }
.ca-table th { background: #F7F1E3; font-weight: 700; }
.ca-table tr.ca-total td { font-weight: 700; background: #F7F1E3; }
.ca-src { font-size: 13px; }
.ca-note { background: #FFF1D6; border: 1px solid #F0DFB4; border-radius: 8px; padding: 10px 12px; font-size: 14px; margin-top: 16px; color: #1F1B16; }
</style>
<div class="ca-wrap">
  <h1>Real COGS by Channel</h1>
  <p>Every unit sold is costed at the purchase price on its own purchase line. Units with no purchase line fall back to the price that product was last invoiced at, and failing that to the standard category cost rule.</p>

  <form class="ca-form" method="get">
    Start <input type="date" name="start_date" value="{{ $start }}">
    End <input type="date" name="end_date" value="{{ $end }}">
    <button type="submit">Apply</button>
  </form>

  <table class="ca-table">
    <thead>
      <tr>
        <th>Channel</th><th>Revenue</th><th>COGS</th><th>Gross profit</th><th>Margin</th>
        <th>From purchase line</th><th>From last invoice</th><th>From standard rule</th><th>Units with no cost</th>
      </tr>
    </thead>
    <tbody>
    @forelse($rows as $r)
      <tr>
        <td><a href="?start_date={{ $start }}&end_date={{ $end }}&channel={{ urlencode($r['channel']) }}">{{ $r['label'] }}</a></td>
        <td>${{ number_format($r['revenue'], 2) }}</td>
        <td>${{ number_format($r['cogs'], 2) }}</td>
        <td>${{ number_format($r['gross_profit'], 2) }}</td>
        <td>{{ number_format($r['margin'], 1) }}%</td>
        <td class="ca-src">${{ number_format($r['cost_purchase'], 2) }}<br>{{ number_format($r['qty_purchase']) }} units</td>
        <td class="ca-src">${{ number_format($r['cost_invoice'], 2) }}<br>{{ number_format($r['qty_invoice']) }} units</td>
        <td class="ca-src">${{ number_format($r['cost_rule'], 2) }}<br>{{ number_format($r['qty_rule']) }} units</td>
        <td>{{ number_format($r['qty_nocost']) }}</td>
      </tr>
    @empty
      <tr><td colspan="9">No sales in this range.</td></tr>
    @endforelse
      <tr class="ca-total">
        <td>Total</td>
        <td>${{ number_format($totals['revenue'], 2) }}</td>
        <td>${{ number_format($totals['cogs'], 2) }}</td>
        <td>${{ number_format($totals['gross_profit'], 2) }}</td>
        <td>{{ number_format($totals['margin'], 1) }}%</td>
        <td class="ca-src">${{ number_format($totals['cost_purchase'], 2) }}<br>{{ number_format($totals['qty_purchase']) }} units</td>
        <td class="ca-src">${{ number_format($totals['cost_invoice'], 2) }}<br>{{ number_format($totals['qty_invoice']) }} units</td>
        <td class="ca-src">${{ number_format($totals['cost_rule'], 2) }}<br>{{ number_format($totals['qty_rule']) }} units</td>
        <td>{{ number_format($totals['qty_nocost']) }}</td>
      </tr>
    </tbody>
  </table>

  <div class="ca-note">
    The last three columns say how much of the cost is real. Money under From purchase line is what we actually paid for those exact units. From last invoice is the same product at the price it was most recently bought in at. From standard rule is a category estimate, so the more cost sitting there, the softer the margin above it.
  </div>
</div>
@endsection
