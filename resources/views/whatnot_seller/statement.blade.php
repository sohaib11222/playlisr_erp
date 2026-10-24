<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Whatnot pay statement</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #222; margin: 0; padding: 20px; background: #fff; }
        .wrap { max-width: 760px; margin: 0 auto; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 17px; margin: 22px 0 8px; }
        .top { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; flex-wrap: wrap; }
        .noprint a, .noprint button { font-size: 14px; margin-left: 8px; }
        form.noprint input { font-size: 14px; padding: 4px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 6px 4px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .sum td { border-bottom: 0; }
        .total td { font-size: 18px; font-weight: 700; border-top: 2px solid #222; border-bottom: 0; }
        .green { color: #2b6a2b; }
        .red { color: #a12622; }
        @media print { .noprint { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <h1>{{ $s['seller_name'] }}: Whatnot pay statement</h1>
            <div>{{ \Carbon\Carbon::parse($from)->format('m/d/y') }} to {{ \Carbon\Carbon::parse($to)->format('m/d/y') }}</div>
        </div>
        <div class="noprint">
            <a href="{{ $back }}">Back</a>
            <button onclick="window.print()">Print</button>
        </div>
    </div>

    <form class="noprint" method="get" style="margin-top:12px;">
        <input type="date" name="start_date" value="{{ $from }}"> to <input type="date" name="end_date" value="{{ $to }}">
        <button>Show</button>
    </form>

    <h2>Summary</h2>
    <table>
        <tr class="sum"><td>{{ (int) $pay['items'] - (int) $pay['premium_items'] }} items x ${{ number_format($s['per_item'], 2) }}</td><td class="num">${{ number_format(((int) $pay['items'] - (int) $pay['premium_items']) * $s['per_item'], 2) }}</td></tr>
        <tr class="sum"><td>{{ (int) $pay['premium_items'] }} items sold 10%+ over sticker x ${{ number_format($s['premium_per_item'], 2) }}</td><td class="num">${{ number_format($pay['premium_items'] * $s['premium_per_item'], 2) }}</td></tr>
        <tr class="sum"><td>{{ rtrim(rtrim(number_format($pay['hours'], 2), '0'), '.') ?: '0' }} show hours x ${{ number_format($s['hourly_rate'], 2) }}</td><td class="num">${{ number_format($pay['hour_pay'], 2) }}</td></tr>
        <tr class="total"><td>Total to pay</td><td class="num">${{ number_format($pay['total'], 2) }}</td></tr>
    </table>
    <div style="margin-top:6px;">Whatnot sales in this period: ${{ number_format($sales, 2) }}</div>

    <h2>Show hours</h2>
    <table>
        @forelse($shows as $h)
            <tr><td>{{ \Carbon\Carbon::parse($h['date'])->format('m/d/y') }}</td><td>{{ $h['note'] }}</td><td class="num">{{ $h['hours'] }} hrs</td><td class="num">${{ number_format($h['hours'] * $s['hourly_rate'], 2) }}</td></tr>
        @empty
            <tr><td>No show hours logged.</td></tr>
        @endforelse
    </table>

    <h2>Items sold</h2>
    <table>
        <tr><th>Date</th><th>Item</th><th class="num">Sold for</th><th class="num">Sticker</th><th class="num">Pay</th></tr>
        @forelse($lines as $l)
            @php $diff = $l['sticker'] ? $l['sold'] - $l['sticker'] : 0; @endphp
            <tr>
                <td>{{ $l['date'] }}</td>
                <td>{{ $l['title'] }}{{ $l['qty'] > 1 ? ' (x' . (int) $l['qty'] . ')' : '' }}</td>
                <td class="num {{ $diff < -0.009 ? 'red' : ($diff > 0.009 ? 'green' : '') }}">${{ number_format($l['sold'] * $l['qty'], 2) }}</td>
                <td class="num">{{ $l['sticker'] === null ? '' : '$' . number_format($l['sticker'] * $l['qty'], 2) }}</td>
                <td class="num">${{ number_format(\App\Services\WhatnotSeller::linePay($l), 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="5">No items sold in this period.</td></tr>
        @endforelse
    </table>
</div>
</body>
</html>
