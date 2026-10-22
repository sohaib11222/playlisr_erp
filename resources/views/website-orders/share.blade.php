<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $title }}</title>
  <meta name="robots" content="noindex, nofollow">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="Nivessa Orders">
  <meta property="og:title" content="{{ $title }}">
  <meta property="og:description" content="{{ $desc }}">
  @if($img)<meta property="og:image" content="{{ $img }}">@endif
  <meta name="twitter:card" content="{{ $img ? 'summary_large_image' : 'summary' }}">
  <meta name="twitter:title" content="{{ $title }}">
  <meta name="twitter:description" content="{{ $desc }}">
  @if($img)<meta name="twitter:image" content="{{ $img }}">@endif
  <style>
    body { margin:0; background:#fff; color:#000; font-family:-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    .wrap { max-width:560px; margin:0 auto; padding:24px 16px; }
    h1 { font-size:22px; margin:0 0 14px; }
    .it { display:flex; gap:12px; align-items:center; margin-bottom:10px; font-size:15px; }
    .it img, .it .ph { width:56px; height:56px; border-radius:8px; object-fit:cover; background:#f3f4f6; flex-shrink:0; }
    .loc { font-size:14px; }
    a.go { display:inline-block; margin-top:16px; padding:12px 18px; border-radius:8px; background:#1e3a8a; color:#fff; font-weight:700; text-decoration:none; }
  </style>
</head>
<body>
  <div class="wrap">
    <h1>{{ $title }}</h1>
    @foreach($items as $i)
      <div class="it">
        @if($i['img'])<img src="{{ $i['img'] }}" alt="">@else<div class="ph"></div>@endif
        <div>@if($i['artist'])<b>{{ $i['artist'] }}</b><br>@endif{{ $i['name'] }}@if($i['qty'] > 1) (x{{ $i['qty'] }})@endif<br>{{ $i['price'] }}@if($i['where'] !== '')<br><span class="loc">{{ $i['where'] }}</span>@endif</div>
      </div>
    @endforeach
    <a class="go" href="{{ $go }}">Open the order</a>
  </div>
</body>
</html>
