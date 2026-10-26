<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Labels {{ $receipt->code }}</title>
<style>
  /* 30 per sheet (2.625" x 1", Avery 5160 / 8160), same sheet as the label printer's "30 Labels per sheet". */
  @page { size: letter; margin: 0.5in 0.1875in; }
  body { margin: 0; font-family: -apple-system, system-ui, sans-serif; color: #000; }
  .sheet { display: grid; grid-template-columns: repeat(3, 2.625in); column-gap: 0.125in; grid-auto-rows: 1in; }
  .lab { box-sizing: border-box; padding: 0.06in 0.1in; overflow: hidden; display: flex; flex-direction: column; justify-content: space-between; }
  .to { font-size: 15px; font-weight: 800; letter-spacing: .3px; }
  .name { font-size: 10px; line-height: 1.15; max-height: 2.3em; overflow: hidden; }
  .foot { display: flex; justify-content: space-between; align-items: flex-end; font-size: 9px; }
  .foot img { height: 0.28in; max-width: 1.5in; }
  .noprint { padding: 12px; font-family: -apple-system, system-ui, sans-serif; }
  @media screen { .lab { border: 1px dashed #bbb; } .sheet { padding: 0 0.1875in; } }
  @media print { .noprint { display: none; } }
</style>
</head>
<body>
<div class="noprint">
  <button onclick="window.print()" style="font-size:16px; padding:8px 16px;">Print {{ count($labels) }} label{{ count($labels) == 1 ? '' : 's' }}</button>
  &nbsp; Transfer {{ $receipt->code }}, {{ $receipt->from_name }} to {{ $receipt->to_name }}. Prints on 30-per-sheet labels.
</div>
<div class="sheet">
@foreach($labels as $it)
  <div class="lab">
    <div class="to">GOES TO {{ strtoupper($receipt->to_name) }}</div>
    <div class="name">{{ $it['name'] }}</div>
    <div class="foot">
      @php try { $bc = $it['sku'] !== '' ? DNS1D::getBarcodePNG($it['sku'], 'C128', 1, 22) : ''; } catch (\Throwable $e) { $bc = ''; } @endphp
      @if($bc !== '')<img src="data:image/png;base64,{{ $bc }}" alt="">@else<span></span>@endif
      <div style="text-align:right;">{{ $receipt->code }}<br>${{ number_format($it['price'], 2) }}</div>
    </div>
  </div>
@endforeach
</div>
</body>
</html>
