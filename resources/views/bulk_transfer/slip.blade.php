<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Transfer {{ $receipt->code }}</title>
<style>
  body { font-family: -apple-system, system-ui, sans-serif; color: #111; margin: 24px; }
  .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; border-bottom: 3px solid #111; padding-bottom: 14px; flex-wrap: wrap; }
  .to { font-size: 34px; font-weight: 800; letter-spacing: .5px; }
  .meta { font-size: 15px; margin-top: 6px; line-height: 1.5; }
  .code { text-align: center; }
  .code .id { font-size: 26px; font-weight: 800; letter-spacing: 2px; margin-top: 4px; }
  .steps { background: #fff8e1; border: 2px solid #f0dc7a; border-radius: 6px; padding: 10px 14px; margin: 16px 0; font-size: 15px; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th, td { text-align: left; padding: 7px 8px; border-bottom: 1px solid #ccc; }
  th { font-size: 12px; text-transform: uppercase; }
  td.n, th.n { text-align: right; }
  .box { display: inline-block; width: 16px; height: 16px; border: 2px solid #111; }
  .noprint { margin-bottom: 16px; }
  @media print { .noprint { display: none; } body { margin: 10mm; } }
</style>
</head>
<body>
<div class="noprint"><button onclick="window.print()" style="font-size:16px; padding:8px 16px;">Print</button></div>
<div class="head">
  <div>
    <div class="to">GOES TO {{ strtoupper($receipt->to_name) }}</div>
    <div class="meta">
      From <strong>{{ $receipt->from_name }}</strong> to <strong>{{ $receipt->to_name }}</strong><br>
      Sent {{ \Carbon::parse($receipt->sent_at)->format('m/d/y g:ia') }} by <strong>{{ $receipt->sent_by_name }}</strong><br>
      {{ rtrim(rtrim(number_format($receipt->item_count, 2), '0'), '.') }} item{{ (float) $receipt->item_count == 1 ? '' : 's' }} &middot; Stock transfer {{ $receipt->ref_no }}
    </div>
  </div>
  <div class="code">
    <img src="data:image/png;base64,{{ DNS1D::getBarcodePNG($receipt->code, 'C128', 2.4, 60) }}" alt="{{ $receipt->code }}">
    <div class="id">{{ $receipt->code }}</div>
    <div style="font-size:12px;">Acceptance ID</div>
  </div>
</div>
<div class="steps">
  <strong>Receiving store:</strong> open Receive Transfer in the ERP, scan the barcode above, scan each item, then click Mark received.
</div>
<table>
  <thead><tr><th>Got it</th><th>Artist - Title</th><th>SKU</th><th>Listed</th><th class="n">Qty</th></tr></thead>
  <tbody>
  @foreach($items as $it)
    <tr><td><span class="box"></span></td><td>{{ $it['name'] }}</td><td>{{ $it['sku'] }}</td><td>{{ $it['listed'] }}</td><td class="n">{{ rtrim(rtrim(number_format($it['qty'], 2), '0'), '.') }}</td></tr>
  @endforeach
  </tbody>
</table>
</body>
</html>
