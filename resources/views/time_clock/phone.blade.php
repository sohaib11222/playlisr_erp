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
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; background: #111; color: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
  body { min-height: 100vh; padding: max(16px, env(safe-area-inset-top)) 16px max(24px, env(safe-area-inset-bottom)); }
  .wrap { max-width: 460px; margin: 0 auto; }
  .brand { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
  .brand img { height: 26px; }
  .brand .who { font-size: 14px; color: #bbb; }
  h1 { font-size: 26px; margin: 0 0 6px; }
  p { font-size: 16px; line-height: 1.45; margin: 0 0 12px; }
  .muted { color: #a3a3a3; }
  .card { background: #1d1d1d; border: 1px solid #2c2c2c; border-radius: 14px; padding: 18px; margin-bottom: 14px; }
  .status { font-size: 20px; font-weight: 600; margin-bottom: 6px; }
  .status.in { color: #facc15; }
  .today { font-size: 16px; color: #d4d4d4; }
  .warn { color: #fca5a5; font-size: 14px; margin-top: 6px; }
  .btn { display: block; width: 100%; border: 0; border-radius: 14px; font-size: 24px; font-weight: 700; padding: 26px 16px; margin: 0 0 12px; cursor: pointer; -webkit-appearance: none; appearance: none; }
  .btn-in { background: #facc15; color: #111; }
  .btn-out { background: #f5f5f5; color: #111; }
  .btn[disabled] { opacity: .6; }
  .btn-small { font-size: 18px; padding: 16px; background: #facc15; color: #111; }
  .msg { border-radius: 12px; padding: 14px; margin-bottom: 14px; font-size: 16px; }
  .msg.ok { background: #14361f; color: #bbf7d0; }
  .msg.bad { background: #3f1515; color: #fecaca; }
  label { display: block; font-size: 15px; color: #d4d4d4; margin: 10px 0 6px; }
  select, input[type=password], input[type=tel] { width: 100%; font-size: 20px; padding: 14px; border-radius: 12px; border: 1px solid #3a3a3a; background: #0b0b0b; color: #fff; }
  input.pin { letter-spacing: 12px; text-align: center; font-size: 28px; }
  a { color: #facc15; }
  .links { text-align: center; font-size: 15px; margin-top: 18px; }
  .hint { font-size: 15px; }
  .hint h2 { font-size: 17px; margin: 0 0 8px; }
  .hint ol { margin: 0 0 8px 18px; padding: 0; }
  .hint li { margin-bottom: 4px; }
  .hint button { background: none; border: 1px solid #555; color: #ddd; border-radius: 10px; padding: 8px 14px; font-size: 15px; }
  .hidden { display: none; }
  body.kiosk .wrap { max-width: 760px; }
  body.kiosk h1 { font-size: 32px; }
  body.kiosk select, body.kiosk input.pin { font-size: 26px; padding: 18px; }
  .row2 { display: flex; gap: 14px; margin-top: 18px; }
  .row2 .btn { margin: 0; font-size: 28px; padding: 30px 12px; }
  .storeline { font-size: 15px; color: #a3a3a3; margin-top: 12px; }
</style>
</head>
<body class="{{ !empty($kiosk) ? 'kiosk' : '' }}">
<div class="wrap">
  <div class="brand">
    <img src="{{ asset('img/nivessa-logo.png') }}" alt="Nivessa">
    @if (!empty($device))<span class="who">{{ $name }}</span> @endif
  </div>

  <div id="msg" class="msg {{ !empty($flash) ? (!empty($flash['ok']) ? 'ok' : 'bad') : 'hidden' }}">{{ $flash['msg'] ?? '' }}</div>

  @if (!$ready)
    <div class="card">
      <h1>Clock is not ready yet</h1>
      <p>The phone clock is still being set up. For now, write down your start and end time and give it to your manager.</p>
      @if (!empty($error))<p class="muted">If this keeps showing, tell a manager.</p> @endif
    </div>
  @elseif (!empty($kiosk))
    <div class="card">
      <h1>Clock in or out</h1>
      <p class="muted">Pick your name, type your PIN, then tap Clock in or Clock out. Nothing is saved on this computer.</p>
      @if (empty($roster))
        <p>No staff are set up for the clock yet. Ask a manager.</p>
      @else
        <form id="kioskForm" method="POST" action="{{ url('/clock/computer') }}" autocomplete="off">
          {{ csrf_field() }}
          <input type="hidden" name="action" id="kAction" value="in">
          <label for="kUser">Your name</label>
          <select name="user_id" id="kUser" required>
            <option value="">Pick your name</option>
            @foreach ($roster as $uid => $uname)
              <option value="{{ $uid }}">{{ $uname }}</option>
            @endforeach
          </select>
          <label for="kPin">PIN</label>
          <input type="password" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" name="pin" id="kPin" class="pin" required autocomplete="off">
          <div class="row2">
            <button type="submit" class="btn btn-in" data-action="in">Clock in</button>
            <button type="submit" class="btn btn-out" data-action="out">Clock out</button>
          </div>
        </form>
        <div class="storeline">
          @if (!empty($kiosk_store) && in_array($kiosk_store['verify'], ['ip', 'gps'], true) && $kiosk_store['name'] !== '')
            This computer is at {{ $kiosk_store['name'] }}.
          @else
            This computer is not on a store network yet, so punches here are marked not verified. A manager can add this network on Time clock settings.
          @endif
        </div>
      @endif
    </div>
    <div class="links">Using your own phone? Open this page on it to be remembered: {{ url('/clock') }}</div>
  @elseif (!empty($device))
    <div class="card">
      <div id="statusLine" class="status {{ $status['clocked_in'] ? 'in' : '' }}">
        @if ($status['clocked_in'])
          {{ 'Clocked in at ' . $status['since'] . ($status['store'] !== '' ? ' at ' . $status['store'] : '') }}
        @elseif ($status['last_out'])
          Clocked out at {{ $status['last_out'] }}
        @else
          Not clocked in
        @endif
      </div>
      <div id="todayLine" class="today">Today: {{ number_format($status['today_hours'], 2) }} hours</div>
      <div id="verifyLine" class="warn {{ ($status['clocked_in'] && $status['verified'] === false) ? '' : 'hidden' }}">Not verified at store. A manager will check this punch.</div>
    </div>

    <form id="punchForm" method="POST" action="{{ url('/clock/punch') }}">
      {{ csrf_field() }}
      <input type="hidden" name="action" id="punchAction" value="{{ $status['clocked_in'] ? 'out' : 'in' }}">
      <input type="hidden" name="lat" id="fLat">
      <input type="hidden" name="lng" id="fLng">
      <input type="hidden" name="accuracy" id="fAcc">
      <input type="hidden" name="geo_error" id="fErr">
      <button type="submit" id="punchBtn" class="btn {{ $status['clocked_in'] ? 'btn-out' : 'btn-in' }}">{{ $status['clocked_in'] ? 'Clock out' : 'Clock in' }}</button>
    </form>
    <p class="muted" style="font-size:14px;">Allow location when your phone asks. It checks that you are at the store.</p>

    <div id="homeHint" class="card hint hidden">
      <h2>Put the clock on your home screen</h2>
      <div id="hintIos">
        <p>On iPhone, in Safari:</p>
        <ol>
          <li>Tap the Share button (the square with an arrow).</li>
          <li>Tap Add to Home Screen, then Add.</li>
          <li>Open the new Nivessa Clock icon and enter your PIN one more time.</li>
        </ol>
      </div>
      <div id="hintAndroid">
        <p>On Android, in Chrome:</p>
        <ol>
          <li>Tap the menu (three dots, top right).</li>
          <li>Tap Add to Home screen (or Install app).</li>
        </ol>
      </div>
      <button type="button" id="hintClose">Got it</button>
    </div>

    <div class="links">Not you? <a href="{{ url('/clock?switch=1') }}">Switch person</a></div>
  @else
    <div class="card">
      <h1>Set up this phone</h1>
      <p class="muted">Pick your name and enter your 4-number PIN. You only do this once on this phone.</p>
      @if (!empty($current_name))<p class="muted">This phone is set up for {{ $current_name }}. Entering another PIN switches it.</p> @endif
      @if (empty($roster))
        <p>No staff are set up for the clock yet. Ask a manager.</p>
      @else
        <form method="POST" action="{{ url('/clock/pin') }}" autocomplete="off">
          {{ csrf_field() }}
          @if ($switch)<input type="hidden" name="switch" value="1"> @endif
          <label for="user_id">Your name</label>
          <select name="user_id" id="user_id" required>
            <option value="">Pick your name</option>
            @foreach ($roster as $uid => $uname)
              <option value="{{ $uid }}">{{ $uname }}</option>
            @endforeach
          </select>
          <label for="pin">PIN</label>
          <input type="password" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" name="pin" id="pin" class="pin" required autocomplete="off">
          <button type="submit" class="btn btn-small" style="margin-top:16px;">Continue</button>
        </form>
        <p class="muted" style="font-size:14px;">No PIN yet, or forgot it? Ask a manager to set one.</p>
      @endif
    </div>
    @if ($switch)<div class="links"><a href="{{ url('/clock') }}">Back</a></div> @endif
  @endif
</div>

@if ($ready && !empty($kiosk) && !empty($roster))
<script>
(function () {
  var form = document.getElementById('kioskForm');
  var actionEl = document.getElementById('kAction');
  var msg = document.getElementById('msg');
  var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  var busy = false, resetTimer = null;
  var buttons = form.querySelectorAll('button[data-action]');
  for (var i = 0; i < buttons.length; i++) {
    buttons[i].addEventListener('click', function () { actionEl.value = this.getAttribute('data-action'); });
  }
  function show(ok, text) { msg.className = 'msg ' + (ok ? 'ok' : 'bad'); msg.textContent = text; }
  function refreshToken() {
    return fetch('/clock/state', { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, cache: 'no-store' })
      .then(function (r) { return r.json(); }).then(function (j) { if (j.csrf) { token = j.csrf; } }).catch(function () {});
  }
  function send(retried) {
    var body = new URLSearchParams(new FormData(form));
    body.set('action', actionEl.value);
    body.delete('_token');
    return fetch('/clock/computer', {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function (r) {
      if (r.status === 419 && !retried) { return refreshToken().then(function () { return send(true); }); }
      return r.json();
    });
  }
  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (busy) { return; }
    busy = true;
    for (var i = 0; i < buttons.length; i++) { buttons[i].disabled = true; }
    send(false).then(function (j) {
      var text = j.msg || '';
      if (j.ok && j.status) { text += ' Today: ' + Number(j.status.today_hours || 0).toFixed(2) + ' hours.'; }
      show(!!j.ok, text);
      document.getElementById('kPin').value = '';
      if (j.ok) {
        document.getElementById('kUser').value = '';
        clearTimeout(resetTimer);
        resetTimer = setTimeout(function () { msg.className = 'msg hidden'; }, 12000);
      }
    }).catch(function () {
      show(false, 'No connection. Try again.');
    }).then(function () {
      busy = false;
      for (var i = 0; i < buttons.length; i++) { buttons[i].disabled = false; }
    });
  });
  setInterval(refreshToken, 30 * 60 * 1000);
})();
</script>
@endif
@if ($ready && !empty($device))
<script>
(function () {
  var form = document.getElementById('punchForm');
  var btn = document.getElementById('punchBtn');
  var actionEl = document.getElementById('punchAction');
  var msg = document.getElementById('msg');
  var token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
  var busy = false;

  function show(ok, text) {
    msg.className = 'msg ' + (ok ? 'ok' : 'bad');
    msg.textContent = text;
  }
  function render(s) {
    if (!s) { return; }
    var line = document.getElementById('statusLine');
    if (s.clocked_in) {
      line.textContent = 'Clocked in at ' + s.since + (s.store ? ' at ' + s.store : '');
      line.className = 'status in';
    } else {
      line.textContent = s.last_out ? 'Clocked out at ' + s.last_out : 'Not clocked in';
      line.className = 'status';
    }
    document.getElementById('todayLine').textContent = 'Today: ' + Number(s.today_hours || 0).toFixed(2) + ' hours';
    document.getElementById('verifyLine').className = 'warn' + ((s.clocked_in && s.verified === false) ? '' : ' hidden');
    actionEl.value = s.clocked_in ? 'out' : 'in';
    btn.textContent = s.clocked_in ? 'Clock out' : 'Clock in';
    btn.className = 'btn ' + (s.clocked_in ? 'btn-out' : 'btn-in');
  }
  function refresh() {
    return fetch('/clock/state', { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, cache: 'no-store' })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (j.csrf) { token = j.csrf; } if (j.status) { render(j.status); } return j; })
      .catch(function () { return null; });
  }
  function getGeo() {
    return new Promise(function (resolve) {
      if (!navigator.geolocation) { resolve({ error: 'unsupported' }); return; }
      var done = false;
      var timer = setTimeout(function () { if (!done) { done = true; resolve({ error: 'timeout' }); } }, 9000);
      navigator.geolocation.getCurrentPosition(function (p) {
        if (done) { return; } done = true; clearTimeout(timer);
        resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: p.coords.accuracy });
      }, function (e) {
        if (done) { return; } done = true; clearTimeout(timer);
        resolve({ error: e && e.code === 1 ? 'denied' : (e && e.code === 3 ? 'timeout' : 'unavailable') });
      }, { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 });
    });
  }
  function send(geo, retried) {
    var body = new URLSearchParams();
    body.append('action', actionEl.value);
    if (geo.lat !== undefined) { body.append('lat', geo.lat); body.append('lng', geo.lng); body.append('accuracy', geo.accuracy); }
    if (geo.error) { body.append('geo_error', geo.error); }
    return fetch('/clock/punch', {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function (r) {
      if (r.status === 419 && !retried) {
        return refresh().then(function () { return send(geo, true); });
      }
      return r.json();
    });
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (busy) { return; }
    busy = true;
    btn.disabled = true;
    var label = btn.textContent;
    btn.textContent = 'Checking location...';
    getGeo().then(function (geo) {
      btn.textContent = 'Saving...';
      return send(geo, false);
    }).then(function (j) {
      if (j) { show(!!j.ok, j.msg || ''); if (j.status) { render(j.status); } }
    }).catch(function () {
      btn.textContent = label;
      show(false, 'No connection. Check your signal and try again.');
    }).then(function () {
      busy = false;
      btn.disabled = false;
    });
  });

  document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(); } });

  // One-time home screen hint (not shown when already opened from the icon).
  try {
    var standalone = window.navigator.standalone === true || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
    var seen = localStorage.getItem('nv_clock_hint_seen');
    if (!standalone && !seen) {
      var ua = navigator.userAgent || '';
      var ios = /iPhone|iPad|iPod/i.test(ua);
      var android = /Android/i.test(ua);
      if (ios) { document.getElementById('hintAndroid').className = 'hidden'; }
      if (android) { document.getElementById('hintIos').className = 'hidden'; }
      document.getElementById('homeHint').className = 'card hint';
      document.getElementById('hintClose').addEventListener('click', function () {
        try { localStorage.setItem('nv_clock_hint_seen', '1'); } catch (e) {}
        document.getElementById('homeHint').className = 'card hint hidden';
      });
    }
  } catch (e) {}
})();
</script>
@endif
</body>
</html>
