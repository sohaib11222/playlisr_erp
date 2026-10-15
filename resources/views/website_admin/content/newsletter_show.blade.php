@extends('layouts.app')
@section('title', 'Newsletter draft')

@section('content')
@include('website_admin.content._styles')
@php
  $isSent = ($item['status'] ?? '') === 'sent';
  $H = \App\Http\Controllers\WebsiteAdmin\NewsletterController::class;
@endphp
<style>
  .nl-layout { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr); gap:18px; }
  @media (max-width: 1100px) { .nl-layout { grid-template-columns:1fr; } }
  .nl-picks { display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:8px; }
  .nl-pick { border:1px solid #e5e7eb; border-radius:8px; padding:7px; font-size:12px; display:flex; flex-direction:column; gap:3px; }
  .nl-pick img { width:100%; aspect-ratio:1/1; object-fit:cover; border-radius:6px; background:#f3f4f6; }
  .nl-pick .t { font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .nl-pick .a { color:#6b7280; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .nl-sec-head { display:flex; justify-content:space-between; align-items:center; margin:14px 0 8px; }
  .nl-sec-head:first-child { margin-top:0; }
  .nl-sec-head h3 { font-size:14px; font-weight:700; margin:0; }
  .nl-events { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:8px; }
  .nl-event { border:1px solid #e5e7eb; border-radius:8px; padding:7px; display:flex; gap:8px; align-items:flex-start; font-size:12px; }
  .nl-event img { width:44px; height:44px; object-fit:cover; border-radius:6px; }
  .nl-pool { display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:10px; }
  .nl-pool button { border:1px solid #e5e7eb; border-radius:8px; padding:7px; background:#fff; text-align:left; cursor:pointer; font-size:12px; font-family:inherit; }
  .nl-pool button:hover { border-color:#3b82f6; box-shadow:0 1px 4px rgba(0,0,0,.08); }
  .nl-pool img { width:100%; aspect-ratio:1/1; object-fit:cover; border-radius:6px; margin-bottom:5px; background:#f3f4f6; }
  .nl-topbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
</style>
<div class="ev-wrap ev-wrap-wide">
  <a class="wc-back" href="{{ route('wa.content.newsletter') }}">Back to all newsletters</a>
  <div class="ev-head">
    <div>
      <h1 id="nl-title">{{ ($item['subject'] ?? '') !== '' ? $item['subject'] : 'Untitled draft' }}</h1>
      <p class="sub">Status: <b id="nl-status" style="text-transform:capitalize;">{{ $item['status'] ?? '' }}</b>@if(!empty($item['sentAt'])), sent {{ $H::fmtDate($item['sentAt'], 'M j, Y g:i A') }}@endif</p>
    </div>
    @if(!$isSent)
    <div class="nl-topbar">
      <input type="email" id="nl-test-email" class="wc-input" style="width:220px;" placeholder="Test email" value="{{ $notifyEmail }}">
      <button type="button" class="wc-btn" id="nl-test-btn">Send test</button>
      <button type="button" class="wc-btn" id="nl-skip-btn">Skip</button>
      <button type="button" class="wc-btn wc-btn-go" id="nl-send-btn" @if($recipientCount === 0) disabled title="Pick a Brevo list in settings first" @endif>Approve and send</button>
    </div>
    @endif
  </div>

  <div id="nl-flash"></div>

  <div class="nl-layout">
    <div>
      <div class="wc-card wc-card-pad">
        <div class="wc-field" style="margin-bottom:10px;"><label>Subject</label><input type="text" id="nl-subject" @if($isSent) disabled @endif></div>
        <div class="wc-field"><label>Intro paragraph</label><textarea id="nl-intro" rows="3" @if($isSent) disabled @endif></textarea></div>
      </div>
      <div class="wc-card wc-card-pad" id="nl-sections"></div>
      @if(!$isSent)
      <div style="display:flex; justify-content:flex-end;">
        <button type="button" class="wc-btn wc-btn-primary" id="nl-save-btn">Save changes</button>
      </div>
      @endif
    </div>
    <div class="wc-card" style="overflow:hidden;">
      <div class="wc-card-head"><h2>Live preview</h2><span class="wc-muted">Updates after you save.</span></div>
      <iframe id="nl-preview" style="width:100%; height:calc(100vh - 220px); min-height:600px; border:0;" title="Newsletter preview"></iframe>
    </div>
  </div>
</div>

<dialog class="wc-dialog wide" id="nl-pool-dialog">
  <div class="wc-dialog-body">
    <div style="display:flex; justify-content:space-between; align-items:center;">
      <h3 id="nl-pool-title" style="margin:0;">Pick a record</h3>
      <button type="button" class="wc-btn wc-btn-sm" onclick="document.getElementById('nl-pool-dialog').close()">Close</button>
    </div>
    <div id="nl-pool-body" style="margin-top:14px;"></div>
  </div>
</dialog>

<script>
(function () {
  var ID = @json($item['_id']);
  var IS_SENT = @json($isSent);
  var CSRF = @json(csrf_token());
  var URLS = {
    save: {!! json_encode(route('wa.content.newsletterUpdate', $item['_id'])) !!},
    test: {!! json_encode(route('wa.content.newsletterTest', $item['_id'])) !!},
    send: {!! json_encode(route('wa.content.newsletterSend', $item['_id'])) !!},
    skip: {!! json_encode(route('wa.content.newsletterSkip', $item['_id'])) !!},
    pool: @json(route('wa.content.newsletterCandidates'))
  };
  var item = @json($item);
  var edits = {
    subject: item.subject || '',
    intro: item.intro || '',
    sealedNewReleases: item.sealedNewReleases || [],
    hollywoodPicks: item.hollywoodPicks || [],
    picoPicks: item.picoPicks || [],
    upcomingEvents: item.upcomingEvents || []
  };
  var STORE_KEY = { Hollywood: 'hollywoodPicks', Pico: 'picoPicks', Sealed: 'sealedNewReleases' };
  var pool = { store: 'Hollywood', mode: 'add', idx: null };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function price(n) { n = Number(n) || 0; return '$' + n.toFixed(n % 1 === 0 ? 0 : 2); }
  function flash(msg, ok) {
    document.getElementById('nl-flash').innerHTML = '<div class="' + (ok ? 'alert-ok' : 'alert-err') + '">' + esc(msg) + '</div>';
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  function post(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json().catch(function () { return { success: false, message: 'Unexpected response (HTTP ' + r.status + ').' }; }); });
  }
  function setPreview(html) { document.getElementById('nl-preview').srcdoc = html || '<p style="font-family:sans-serif">No content yet</p>'; }

  function pickList(title, store, key) {
    var items = edits[key] || [];
    var h = '<div class="nl-sec-head"><h3>' + esc(title) + '</h3>' +
      (IS_SENT ? '' : '<button type="button" class="wc-btn wc-btn-sm" data-add="' + store + '">Add pick</button>') + '</div>';
    if (!items.length) h += '<div class="wc-muted" style="font-style:italic;">No picks for this section.</div>';
    h += '<div class="nl-picks">';
    items.forEach(function (p, i) {
      h += '<div class="nl-pick">' + (p.image ? '<img src="' + esc(p.image) + '" alt="">' : '') +
        '<div class="t" title="' + esc(p.name) + '">' + esc(p.name) + '</div><div class="a">' + esc(p.artist) + '</div><div>' + price(p.price) + '</div>' +
        (IS_SENT ? '' : '<div class="wc-actions"><button type="button" class="wc-btn wc-btn-sm" data-replace="' + store + '" data-i="' + i + '">Replace</button>' +
        '<button type="button" class="wc-btn wc-btn-sm wc-btn-danger" data-remove="' + key + '" data-i="' + i + '">Remove</button></div>') +
        '</div>';
    });
    return h + '</div>';
  }
  function render() {
    var h = pickList('Cool New Releases (sealed vinyl)', 'Sealed', 'sealedNewReleases') +
      pickList('New in Hollywood', 'Hollywood', 'hollywoodPicks') +
      pickList('New in Pico', 'Pico', 'picoPicks');
    h += '<div class="nl-sec-head"><h3>Upcoming Events</h3></div>';
    if (!edits.upcomingEvents.length) h += '<div class="wc-muted" style="font-style:italic;">No upcoming events.</div>';
    h += '<div class="nl-events">';
    edits.upcomingEvents.forEach(function (e, i) {
      h += '<div class="nl-event">' + (e.image ? '<img src="' + esc(e.image) + '" alt="">' : '') +
        '<div style="flex:1; min-width:0;"><div style="font-weight:600;">' + esc(e.name) + '</div><div class="wc-muted">' + esc(e.date) + (e.time ? ', ' + esc(e.time) : '') + '</div>' +
        (e.venue ? '<div class="wc-muted">' + esc(e.venue) + '</div>' : '') + '</div>' +
        (IS_SENT ? '' : '<button type="button" class="wc-btn wc-btn-sm wc-btn-danger" data-remove="upcomingEvents" data-i="' + i + '">Remove</button>') + '</div>';
    });
    document.getElementById('nl-sections').innerHTML = h + '</div>';
  }

  function openPool(store, mode, idx) {
    pool = { store: store, mode: mode, idx: idx };
    document.getElementById('nl-pool-title').textContent = store === 'Sealed' ? 'Pick a sealed vinyl release' : 'Pick a ' + store + ' record';
    var body = document.getElementById('nl-pool-body');
    body.innerHTML = '<div class="wc-muted">Loading new arrivals...</div>';
    document.getElementById('nl-pool-dialog').showModal();
    var ids = (edits[STORE_KEY[store]] || []).map(function (p) { return p.productId; }).filter(Boolean);
    fetch(URLS.pool + '?store=' + encodeURIComponent(store) + (ids.length ? '&exclude=' + encodeURIComponent(ids.join(',')) : ''), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.success) { body.innerHTML = '<div class="alert-err">' + esc(res.message || 'Could not load candidates.') + '</div>'; return; }
        var items = res.items || (res.data && res.data.items) || [];
        if (!items.length) { body.innerHTML = '<div class="wc-muted">No more candidates available.</div>'; return; }
        window.__nlPool = items;
        body.innerHTML = '<div class="nl-pool">' + items.map(function (p, i) {
          return '<button type="button" data-pick="' + i + '">' + (p.image ? '<img src="' + esc(p.image) + '" alt="">' : '') +
            '<div style="font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">' + esc(p.name) + '</div><div class="wc-muted">' + esc(p.artist) + '</div><div>' + price(p.price) + '</div></button>';
        }).join('') + '</div>';
      })
      .catch(function () { body.innerHTML = '<div class="alert-err">Could not load candidates.</div>'; });
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-add],[data-replace],[data-remove],[data-pick]');
    if (!t) return;
    if (t.hasAttribute('data-add')) openPool(t.getAttribute('data-add'), 'add', null);
    else if (t.hasAttribute('data-replace')) openPool(t.getAttribute('data-replace'), 'replace', Number(t.getAttribute('data-i')));
    else if (t.hasAttribute('data-remove')) {
      var k = t.getAttribute('data-remove');
      edits[k] = edits[k].filter(function (_, i) { return i !== Number(t.getAttribute('data-i')); });
      render();
    } else if (t.hasAttribute('data-pick')) {
      var product = (window.__nlPool || [])[Number(t.getAttribute('data-pick'))];
      var key = STORE_KEY[pool.store] || 'hollywoodPicks';
      var list = (edits[key] || []).slice();
      if (pool.mode === 'replace' && pool.idx != null) list[pool.idx] = product; else list.push(product);
      edits[key] = list;
      document.getElementById('nl-pool-dialog').close();
      render();
    }
  });

  var subj = document.getElementById('nl-subject'), intro = document.getElementById('nl-intro');
  subj.value = edits.subject; intro.value = edits.intro;
  subj.addEventListener('input', function () { edits.subject = subj.value; });
  intro.addEventListener('input', function () { edits.intro = intro.value; });
  render();
  setPreview(item.html);

  function busy(btn, label) { var old = btn.textContent; btn.disabled = true; btn.textContent = label; return function () { btn.disabled = false; btn.textContent = old; }; }

  var saveBtn = document.getElementById('nl-save-btn');
  if (saveBtn) saveBtn.addEventListener('click', function () {
    var done = busy(saveBtn, 'Saving...');
    post(URLS.save, edits).then(function (res) {
      done();
      if (!res.success) return flash(res.message || 'Save failed.', false);
      var fresh = res.item || (res.data && res.data.item);
      if (fresh) { setPreview(fresh.html); document.getElementById('nl-title').textContent = fresh.subject || 'Untitled draft'; }
      flash('Saved.', true);
    }).catch(function () { done(); flash('Save failed.', false); });
  });

  var testBtn = document.getElementById('nl-test-btn');
  if (testBtn) testBtn.addEventListener('click', function () {
    var email = document.getElementById('nl-test-email').value.trim();
    if (!email) return flash('Enter a test email address.', false);
    var done = busy(testBtn, 'Sending...');
    post(URLS.test, { email: email }).then(function (res) {
      done();
      flash(res.success ? 'Test sent to ' + email + '.' : (res.message || 'Test send failed.'), !!res.success);
    }).catch(function () { done(); flash('Test send failed.', false); });
  });

  var skipBtn = document.getElementById('nl-skip-btn');
  if (skipBtn) skipBtn.addEventListener('click', function () {
    if (!confirm("Skip this week's newsletter? You can still send it later.")) return;
    var done = busy(skipBtn, '...');
    post(URLS.skip).then(function (res) {
      done();
      if (!res.success) return flash(res.message || 'Skip failed.', false);
      document.getElementById('nl-status').textContent = 'skipped';
      flash('Skipped.', true);
    }).catch(function () { done(); flash('Skip failed.', false); });
  });

  // Same confirmation text as the website's "Approve & send" button.
  var sendBtn = document.getElementById('nl-send-btn');
  if (sendBtn) sendBtn.addEventListener('click', function () {
    if (!confirm('Send this newsletter to all subscribers in your selected Brevo list(s)? This cannot be undone.')) return;
    var done = busy(sendBtn, 'Sending...');
    post(URLS.send).then(function (res) {
      if (!res.success) { done(); return flash(res.message || 'Send failed.', false); }
      flash('Sent.', true);
      setTimeout(function () { window.location.reload(); }, 1200);
    }).catch(function () { done(); flash('Send failed.', false); });
  });
})();
</script>
@endsection
