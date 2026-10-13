<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="#111111">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Clock">
<meta name="apple-mobile-web-app-status-bar-style" content="black">
<link rel="manifest" href="{{ url('/clock/manifest.webmanifest') }}">
<link rel="apple-touch-icon" href="{{ asset('img/clock-icon-180.png') }}?v={{ config('constants.asset_version') }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ asset('img/clock-icon-192.png') }}?v={{ config('constants.asset_version') }}">
<title>Nivessa Clock</title>
<style>
  * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
  html, body { margin: 0; padding: 0; background: #111; color: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
  body { min-height: 100vh; min-height: 100dvh; padding: max(14px, env(safe-area-inset-top)) 16px max(18px, env(safe-area-inset-bottom)); display: flex; flex-direction: column; }
  .wrap { width: 100%; max-width: 520px; margin: 0 auto; flex: 1; display: flex; flex-direction: column; }
  body.computer .wrap { max-width: 720px; }
  .logo { text-align: center; margin-bottom: 14px; }
  .logo img { height: 30px; }
  body.computer .logo img { height: 40px; }
  .title { text-align: center; font-size: 30px; font-weight: 700; margin: 4px 0 14px; }
  body.computer .title { font-size: clamp(32px, 5vh, 54px); }
  .dots { display: flex; justify-content: center; gap: 18px; margin: 4px 0 12px; }
  .dot { width: 22px; height: 22px; border-radius: 50%; border: 3px solid #facc15; }
  .dot.on { background: #facc15; }
  body.computer .dot { width: clamp(22px, 3.6vh, 38px); height: clamp(22px, 3.6vh, 38px); }
  .msg { text-align: center; font-size: 22px; min-height: 32px; margin-bottom: 10px; color: #fca5a5; }
  .pad { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; flex: 1; max-height: 520px; }
  body.computer .pad { max-height: none; min-height: 58vh; }
  .key { border: 0; border-radius: 18px; background: #262626; color: #fff; font-size: 38px; font-weight: 600; min-height: 76px; cursor: pointer; -webkit-appearance: none; appearance: none; touch-action: manipulation; }
  body.computer .key { font-size: clamp(40px, 7vh, 80px); }
  .key:active { background: #3a3a3a; }
  .key.alt { background: transparent; color: #d4d4d4; font-size: 24px; }
  body.computer .key.alt { font-size: clamp(24px, 3.5vh, 40px); }
  .key[disabled] { opacity: .5; }
  .help { text-align: center; font-size: 19px; color: #bdbdbd; margin-top: 14px; }
  .help a { color: #facc15; }
  .name { text-align: center; font-size: 40px; font-weight: 800; margin: 6px 0 6px; }
  .status { text-align: center; font-size: 26px; font-weight: 600; margin-bottom: 4px; }
  .status.in { color: #facc15; }
  .today { text-align: center; font-size: 22px; color: #d4d4d4; margin-bottom: 18px; }
  .big { display: block; width: 100%; border: 0; border-radius: 26px; font-size: 52px; font-weight: 800; min-height: 42vh; padding: 20px; cursor: pointer; -webkit-appearance: none; appearance: none; touch-action: manipulation; }
  .big.in { background: #facc15; color: #111; }
  .big.out { background: #f5f5f5; color: #111; }
  .big[disabled] { opacity: .7; }
  .done { text-align: center; font-size: 24px; min-height: 34px; margin: 14px 0 6px; color: #bbf7d0; }
  .done.bad { color: #fca5a5; }
  .hint { background: #1d1d1d; border: 1px solid #333; border-radius: 16px; padding: 16px; margin-top: 18px; font-size: 19px; line-height: 1.45; }
  .hint b { display: block; font-size: 21px; margin-bottom: 6px; }
  .hint ol { margin: 6px 0 10px 22px; padding: 0; }
  .hint button { background: none; border: 2px solid #666; color: #eee; border-radius: 12px; padding: 10px 18px; font-size: 19px; }
  .switch { text-align: center; font-size: 20px; margin-top: 18px; }
  .switch a { color: #facc15; }
  .overlay { position: fixed; inset: 0; display: none; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 24px; z-index: 10; }
  .overlay.show { display: flex; }
  .overlay.in { background: #facc15; color: #111; }
  .overlay.out { background: #f5f5f5; color: #111; }
  .overlay .h { font-size: clamp(40px, 8vw, 96px); font-weight: 800; line-height: 1.1; }
  .overlay .d { font-size: clamp(28px, 5vw, 60px); margin-top: 20px; font-weight: 600; }
  .notready { text-align: center; font-size: 24px; line-height: 1.45; margin-top: 30px; }
  .hidden { display: none !important; }
</style>
</head>
<body class="{{ $mode === 'computer' ? 'computer' : '' }}">
<div class="wrap">
  <div class="logo"><img src="{{ asset('img/nivessa-logo.png') }}" alt="Nivessa"></div>

  @if (!$ready)
    <div class="notready">
      <div class="title">Clock is not ready yet</div>
      Write down your start and end time and give it to your manager.
    </div>
  @elseif ($mode === 'phone')
    <div class="name">{{ $name }}</div>
    <div id="statusLine" class="status {{ $status['clocked_in'] ? 'in' : '' }}">
      @if ($status['clocked_in'])
        {{ 'Clocked in since ' . $status['since'] }}
      @elseif ($status['last_out'])
        {{ 'Clocked out at ' . $status['last_out'] }}
      @else
        Not clocked in
      @endif
    </div>
    <div id="todayLine" class="today">{{ $status['today_text'] }} today</div>
    <button type="button" id="bigBtn" class="big {{ $status['clocked_in'] ? 'out' : 'in' }}" data-action="{{ $status['clocked_in'] ? 'out' : 'in' }}">{{ $status['clocked_in'] ? 'Clock out' : 'Clock in' }}</button>
    <div id="done" class="done"></div>

    <div id="homeHint" class="hint hidden">
      <b>Put the clock on your home screen</b>
      <div id="hintIos">
        iPhone, in Safari:
        <ol>
          <li>Tap Share (the square with the arrow).</li>
          <li>Tap Add to Home Screen, then Add.</li>
          <li>Open the new icon and enter your PIN one more time.</li>
        </ol>
      </div>
      <div id="hintAndroid">
        Android, in Chrome:
        <ol>
          <li>Tap the menu (three dots, top right).</li>
          <li>Tap Add to Home screen or Install app.</li>
        </ol>
      </div>
      <button type="button" id="hintClose">Got it</button>
    </div>

    <div class="switch">Not {{ $name }}? <a href="{{ url('/clock?switch=1') }}">Switch person</a></div>
  @else
    <div class="title">{{ $mode === 'computer' ? 'Enter your PIN' : 'Enter your PIN to set up this phone' }}</div>
    <div class="dots" id="dots"><span class="dot"></span><span class="dot"></span><span class="dot"></span><span class="dot"></span></div>
    <div class="msg" id="msg"></div>
    <div class="pad" id="pad">
      <button type="button" class="key" data-k="1">1</button>
      <button type="button" class="key" data-k="2">2</button>
      <button type="button" class="key" data-k="3">3</button>
      <button type="button" class="key" data-k="4">4</button>
      <button type="button" class="key" data-k="5">5</button>
      <button type="button" class="key" data-k="6">6</button>
      <button type="button" class="key" data-k="7">7</button>
      <button type="button" class="key" data-k="8">8</button>
      <button type="button" class="key" data-k="9">9</button>
      <button type="button" class="key alt" data-k="clear">Clear</button>
      <button type="button" class="key" data-k="0">0</button>
      <button type="button" class="key alt" data-k="back" aria-label="Delete">Delete</button>
    </div>
    @if ($mode === 'computer')
      <div class="help">Your PIN clocks you in, or out if you are already in.</div>
    @else
      <div class="help">No PIN yet? Ask a manager.</div>
      @if ($switch)<div class="switch"><a href="{{ url('/clock') }}">Back</a></div> @endif
    @endif
  @endif
</div>

<div id="overlay" class="overlay"><div class="h" id="ovH"></div><div class="d" id="ovD"></div></div>

@if ($ready)
<script>
(function () {
  var MODE = @json($mode);
  var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

  function refresh() {
    return fetch('/clock/state', { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j.csrf) { token = j.csrf; } return j; })
      .catch(function () { return null; });
  }
  function post(url, data, retried) {
    var body = new URLSearchParams();
    Object.keys(data).forEach(function (k) { if (data[k] !== undefined && data[k] !== null) { body.append(k, data[k]); } });
    return fetch(url, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function (r) {
      if (r.status === 419 && !retried) { return refresh().then(function () { return post(url, data, true); }); }
      return r.json();
    });
  }

  // ---- Keypad (store computer, and phone setup) ----
  var pad = document.getElementById('pad');
  if (pad) {
    var pin = '', busy = false;
    var dots = document.querySelectorAll('#dots .dot');
    var msg = document.getElementById('msg');
    var overlay = document.getElementById('overlay');
    function draw() { for (var i = 0; i < dots.length; i++) { dots[i].className = 'dot' + (i < pin.length ? ' on' : ''); } }
    function setBusy(b) { busy = b; var ks = pad.querySelectorAll('.key'); for (var i = 0; i < ks.length; i++) { ks[i].disabled = b; } }
    function submit() {
      setBusy(true);
      msg.textContent = '';
      var url = MODE === 'computer' ? '/clock/computer' : '/clock/pin';
      post(url, { pin: pin }, false).then(function (j) {
        pin = ''; draw();
        if (!j || !j.ok) { msg.textContent = (j && j.msg) || 'That did not work. Try again.'; setBusy(false); return; }
        if (MODE === 'computer') {
          document.getElementById('ovH').textContent = j.headline || '';
          document.getElementById('ovD').textContent = j.detail || '';
          overlay.className = 'overlay show ' + (j.action === 'out' ? 'out' : 'in');
          setTimeout(function () { overlay.className = 'overlay'; setBusy(false); }, 3000);
        } else {
          window.location.href = '/clock';
        }
      }).catch(function () {
        pin = ''; draw();
        msg.textContent = 'No connection. Try again.';
        setBusy(false);
      });
    }
    function press(k) {
      if (busy) { return; }
      msg.textContent = '';
      if (k === 'back') { pin = pin.slice(0, -1); }
      else if (k === 'clear') { pin = ''; }
      else if (/^\d$/.test(k) && pin.length < 4) { pin += k; }
      draw();
      if (pin.length === 4) { submit(); }
    }
    pad.addEventListener('click', function (e) {
      var b = e.target.closest ? e.target.closest('.key') : null;
      if (b) { press(b.getAttribute('data-k')); }
    });
    document.addEventListener('keydown', function (e) {
      if (/^\d$/.test(e.key)) { press(e.key); e.preventDefault(); }
      else if (e.key === 'Backspace') { press('back'); e.preventDefault(); }
      else if (e.key === 'Escape') { press('clear'); }
    });
    if (MODE === 'computer') { setInterval(refresh, 30 * 60 * 1000); }
  }

  // ---- Remembered phone: one big button ----
  var btn = document.getElementById('bigBtn');
  if (btn) {
    var done = document.getElementById('done');
    var busyBtn = false;
    function render(s) {
      if (!s) { return; }
      var line = document.getElementById('statusLine');
      line.textContent = s.clocked_in ? 'Clocked in since ' + s.since : (s.last_out ? 'Clocked out at ' + s.last_out : 'Not clocked in');
      line.className = 'status' + (s.clocked_in ? ' in' : '');
      document.getElementById('todayLine').textContent = (s.today_text || '0h 0m') + ' today';
      btn.setAttribute('data-action', s.clocked_in ? 'out' : 'in');
      btn.textContent = s.clocked_in ? 'Clock out' : 'Clock in';
      btn.className = 'big ' + (s.clocked_in ? 'out' : 'in');
    }
    function getGeo() {
      return new Promise(function (resolve) {
        if (!navigator.geolocation) { resolve({ geo_error: 'unsupported' }); return; }
        var finished = false;
        var timer = setTimeout(function () { if (!finished) { finished = true; resolve({ geo_error: 'timeout' }); } }, 8000);
        navigator.geolocation.getCurrentPosition(function (p) {
          if (finished) { return; } finished = true; clearTimeout(timer);
          resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy });
        }, function (e) {
          if (finished) { return; } finished = true; clearTimeout(timer);
          resolve({ geo_error: e && e.code === 1 ? 'denied' : (e && e.code === 3 ? 'timeout' : 'unavailable') });
        }, { enableHighAccuracy: true, timeout: 7000, maximumAge: 60000 });
      });
    }
    btn.addEventListener('click', function () {
      if (busyBtn) { return; }
      busyBtn = true;
      btn.disabled = true;
      var label = btn.textContent;
      btn.textContent = 'One moment';
      done.textContent = '';
      getGeo().then(function (geo) {
        geo.action = btn.getAttribute('data-action');
        return post('/clock/punch', geo, false);
      }).then(function (j) {
        if (j && j.setup) { window.location.href = '/clock'; return; }
        if (!j || !j.ok) { btn.textContent = label; done.className = 'done bad'; done.textContent = (j && j.msg) || 'That did not work. Try again.'; return; }
        render(j.status);
        done.className = 'done';
        done.textContent = (j.headline || '') + ' ' + (j.detail || '');
      }).catch(function () {
        btn.textContent = label;
        done.className = 'done bad';
        done.textContent = 'No connection. Check your signal and try again.';
      }).then(function () { busyBtn = false; btn.disabled = false; });
    });
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) { refresh().then(function (j) { if (j && j.status) { render(j.status); } }); }
    });

    // One-time home screen hint (not when opened from the icon).
    try {
      var standalone = window.navigator.standalone === true || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
      if (!standalone && !localStorage.getItem('nv_clock_hint_seen')) {
        var ua = navigator.userAgent || '';
        if (/iPhone|iPad|iPod/i.test(ua)) { document.getElementById('hintAndroid').className = 'hidden'; }
        if (/Android/i.test(ua)) { document.getElementById('hintIos').className = 'hidden'; }
        var hint = document.getElementById('homeHint');
        hint.className = 'hint';
        document.getElementById('hintClose').addEventListener('click', function () {
          try { localStorage.setItem('nv_clock_hint_seen', '1'); } catch (e) {}
          hint.className = 'hint hidden';
        });
      }
    } catch (e) {}
  }
})();
</script>
@endif
</body>
</html>
