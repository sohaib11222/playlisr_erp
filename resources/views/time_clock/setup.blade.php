<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Nivessa Clock setup</title>
<style>
  html, body { margin: 0; padding: 0; background: #111; color: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
  .wrap { max-width: 560px; margin: 0 auto; padding: 40px 16px; text-align: center; }
  .wrap img { height: 34px; margin-bottom: 24px; }
  h1 { font-size: 28px; line-height: 1.3; margin: 0 0 18px; }
  p { font-size: 19px; line-height: 1.45; color: #d4d4d4; }
  button { border: 0; border-radius: 18px; background: #facc15; color: #111; font-size: 26px; font-weight: 800; padding: 22px 28px; width: 100%; cursor: pointer; margin-top: 12px; }
  a { color: #facc15; }
</style>
</head>
<body>
<div class="wrap">
  <img src="{{ asset('img/nivessa-logo.png') }}" alt="Nivessa">
  @if ($state === 'ready')
    <h1>Set up this computer for the time clock at {{ $store }}</h1>
    <p>Only do this on a computer at the {{ $store }} store.</p>
    <form method="POST" action="{{ url('/clock/setup/' . $token) }}">
      {{ csrf_field() }}
      <button type="submit">Set up this computer</button>
    </form>
  @elseif ($state === 'done')
    <h1>This computer is now set up for the time clock at {{ $store }}.</h1>
    <p><a href="{{ url('/clock?mode=computer') }}">Open the clock</a></p>
  @else
    <h1>This setup link was already used or has expired.</h1>
    <p>Ask the owner for a new one.</p>
  @endif
</div>
</body>
</html>
