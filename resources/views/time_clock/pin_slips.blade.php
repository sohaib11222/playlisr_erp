<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Time clock PIN slips</title>
<style>
  body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #111; background: #fff; }
  .bar { padding: 14px 16px; background: #fef3c7; border-bottom: 2px solid #f59e0b; font-size: 16px; }
  .bar strong { display: block; font-size: 18px; margin-bottom: 4px; }
  .bar button { font-size: 16px; padding: 8px 18px; margin-top: 8px; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 0; padding: 16px; }
  .slip { border: 2px dashed #555; padding: 18px; margin: -1px 0 0 -1px; break-inside: avoid; page-break-inside: avoid; }
  .slip .n { font-size: 22px; font-weight: 700; }
  .slip .p { font-size: 40px; font-weight: 800; letter-spacing: 8px; margin: 8px 0; }
  .slip .u { font-size: 16px; }
  .slip .w { font-size: 14px; color: #444; margin-top: 6px; }
  @media print { .bar { display: none; } .grid { padding: 0; } }
</style>
</head>
<body>
<div class="bar">
  <strong>These PINs are shown only this once.</strong>
  Print this page now and cut out the slips. If you close it without printing, reset those people's PINs on Time clock settings.
  <div><button type="button" onclick="window.print()">Print</button></div>
</div>
<div class="grid">
  @foreach ($slips as $sl)
    <div class="slip">
      <div class="n">{{ $sl['name'] }}</div>
      <div class="p">{{ $sl['pin'] }}</div>
      <div class="u">Clock in at {{ preg_replace('#^https?://#', '', $url) }}</div>
      <div class="w">Don't share your PIN.</div>
    </div>
  @endforeach
</div>
</body>
</html>
