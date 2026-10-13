<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Time clock QR codes</title>
<style>
  body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #111; background: #fff; }
  .bar { padding: 12px 16px; background: #f3f4f6; border-bottom: 1px solid #e5e7eb; font-size: 14px; }
  .bar button { font-size: 14px; padding: 6px 14px; margin-right: 8px; }
  .sheet { page-break-after: always; break-after: page; text-align: center; padding: 40px 24px; min-height: 90vh; box-sizing: border-box; }
  .sheet:last-child { page-break-after: auto; break-after: auto; }
  .sheet img.logo { height: 44px; margin-bottom: 18px; }
  .sheet h1 { font-size: 40px; margin: 0 0 6px; }
  .sheet h2 { font-size: 24px; margin: 0 0 24px; font-weight: 500; color: #333; }
  .qr { display: inline-block; padding: 16px; border: 2px solid #111; border-radius: 12px; }
  .qr img { width: 320px; height: 320px; display: block; image-rendering: pixelated; }
  .url { font-size: 22px; margin: 18px 0 24px; font-weight: 600; }
  ol { text-align: left; display: inline-block; font-size: 18px; line-height: 1.5; max-width: 560px; margin: 0; }
  @media print { .bar { display: none; } .sheet { min-height: auto; } }
</style>
</head>
<body>
<div class="bar">
  <button type="button" onclick="window.print()">Print</button>
  One page per store. Tape it by the register or the back office.
</div>
@php $__list = count($stores) ? $stores : [['name' => 'Nivessa']]; @endphp
@foreach ($__list as $st)
  <div class="sheet">
    <img class="logo" src="{{ asset('img/nivessa-logo.png') }}" alt="Nivessa">
    <h1>Clock in with your phone</h1>
    <h2>{{ $st['name'] }}</h2>
    @if ($qr)
      <div class="qr"><img src="data:image/png;base64,{{ $qr }}" alt="QR code for {{ $url }}"></div>
    @else
      <p>QR code could not be made. Use the link below.</p>
    @endif
    <div class="url">{{ preg_replace('#^https?://#', '', $url) }}</div>
    <ol>
      <li>Scan the code with your phone camera.</li>
      <li>Type your 4-number PIN (ask a manager if you do not have one). You only do this once on your phone.</li>
      <li>Add it to your home screen when the page shows you how.</li>
      <li>Every shift: open it, tap Clock in. Tap Clock out when you leave. Allow location so it knows you are at the store.</li>
    </ol>
  </div>
@endforeach
</body>
</html>
