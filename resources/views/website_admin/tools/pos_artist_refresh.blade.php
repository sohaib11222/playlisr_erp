@extends('layouts.app')

@section('title', 'POS Artist Refresh')

@section('content')
<div class="ev-wrap ev-wrap-wide">
  @include('website_admin.tools._head', ['active' => 'wa.tools.posArtistRefresh'])

  <div class="ev-head">
    <div>
      <h1>POS Artist Refresh</h1>
      <p class="sub">Fix "Unknown" artists on website POS products with strict title matching against Discogs.</p>
    </div>
    <button type="button" class="wt-btn" id="ar-stats-refresh">Refresh Stats</button>
  </div>

  <div class="wt-card">
    <h3>Unknown Artist by Category</h3>
    <div class="wt-scroll" id="ar-table"><p class="muted"><span class="wt-spin"></span> Loading.</p></div>
  </div>

  <div class="wt-card">
    <h3>Single Title Lookup</h3>
    <p class="wt-note" style="margin-top:-6px;">Check one product title first to confirm the artist before running a batch.</p>
    <div class="wt-grid two">
      <div class="ev-field"><label>Product title (required)</label><input type="text" id="ar-s-title" placeholder="e.g. Kind Of Blue / Miles Davis"></div>
      <div class="ev-field"><label>Artist hint (optional)</label><input type="text" id="ar-s-artist" placeholder="e.g. Miles Davis"></div>
    </div>
    <div class="wt-row">
      <label><input type="checkbox" id="ar-s-dry" checked> Dry run (preview only)</label>
      <button type="button" class="wt-btn primary" id="ar-s-go">Check Artist Match</button>
      <button type="button" class="wt-btn" id="ar-s-clear">Clear</button>
    </div>
    <div id="ar-s-result"></div>
  </div>

  <div class="wt-grid two">
    <div class="wt-card">
      <h3>Job Configuration</h3>
      <strong style="font-size:13px;">Categories</strong>
      <div class="wt-cats" id="ar-cats"></div>
      <div class="wt-row">
        <button type="button" class="wt-btn sm ar-lock" data-cats="all">All</button>
        <button type="button" class="wt-btn sm ar-lock" data-cats="none">None</button>
      </div>
      <div class="wt-grid" style="margin-top:8px;">
        <div class="ev-field"><label>Batch size (1 to 50)</label><input type="number" min="1" max="50" value="20" id="ar-batch" class="ar-lock"></div>
        <div class="ev-field"><label>Skip first N (resume from)</label><input type="number" min="0" value="0" id="ar-skip" class="ar-lock"></div>
        <div class="ev-field"><label>Limit (blank for all)</label><input type="number" min="1" id="ar-limit" class="ar-lock" placeholder="Leave blank for all"></div>
      </div>
      <div class="wt-row"><label><input type="checkbox" id="ar-dry" class="ar-lock" checked> Dry run (preview only, nothing is saved)</label></div>
      <div class="wt-row" style="margin-top:12px;">
        <button type="button" class="wt-btn primary" id="ar-start">Start Artist Refresh</button>
        <button type="button" class="wt-btn danger wt-hide" id="ar-cancel">Cancel Job</button>
        <button type="button" class="wt-btn wt-hide" id="ar-resume">Continue</button>
      </div>
    </div>
    <div class="wt-card">
      <h3 style="justify-content:space-between;"><span>Job Status</span><span id="ar-job-badge"></span></h3>
      <div id="ar-job"></div>
    </div>
  </div>

  <div class="wt-card wt-hide" id="ar-log-card">
    <h3>Live Log</h3>
    <div class="wt-log" id="ar-log"></div>
  </div>
</div>

@verbatim
<script>
(function () {
  const N = WT.num, E = WT.esc, $ = WT.$, pct = WT.pct;
  const ALL = ['Vinyl Records', 'CDs', 'Used CD', 'Cassettes', 'DVDs & Blu-ray Discs', 'VHS Tapes', 'Laser Discs', '7", 45 RPM', 'Movies', 'Trading Cards', 'Books & Magazines', 'Magazines', 'Pictures & Posters', 'Audio Gear', 'Toys', 'Toys & Collectibles'];
  let selected = ALL.slice(), job = null, wasRunning = false, single = null;

  function renderCats() {
    $('ar-cats').innerHTML = ALL.map((c, i) => '<label><input type="checkbox" class="ar-cat ar-lock" data-i="' + i + '"' + (selected.includes(c) ? ' checked' : '') + '> ' + E(c) + '</label>').join('');
    lock();
  }
  function lock() {
    const running = !!(job && job.running);
    document.querySelectorAll('.ar-lock').forEach(el => { el.disabled = running; });
    $('ar-start').disabled = !selected.length;
  }
  document.addEventListener('change', e => { if (e.target.classList.contains('ar-cat')) { const c = ALL[e.target.dataset.i]; selected = e.target.checked ? selected.concat([c]) : selected.filter(x => x !== c); renderCats(); } });
  document.querySelectorAll('[data-cats]').forEach(b => b.addEventListener('click', function () { selected = this.dataset.cats === 'none' ? [] : ALL.slice(); renderCats(); }));

  async function fetchStats(btn) {
    WT.busy(btn, true, 'Loading');
    const r = await WT.call('GET', '/_api/pos-artist-refresh/stats');
    WT.busy(btn, false);
    if (!r.success) { WT.toast(WT.err(r, 'Failed to load artist stats.'), 'bad'); return; }
    let t = '<table class="wt-tbl"><thead><tr><th>Category</th><th class="r">Total</th><th class="r">Has artist</th><th class="r">Unknown</th><th class="r">Coverage</th></tr></thead><tbody>';
    (r.rows || []).forEach(row => {
      t += '<tr><td><strong>' + E(row.category) + '</strong></td><td class="r">' + N(row.total) + '</td><td class="r green-t">' + N(row.hasArtist) + '</td><td class="r red-t"><strong>' + N(row.missing) + '</strong></td><td class="r"><div style="display:flex; gap:6px; align-items:center; justify-content:flex-end;"><span style="width:80px;">' + WT.bar(pct(row.hasArtist, row.total)) + '</span><span class="muted" style="font-size:12px; width:36px;">' + pct(row.hasArtist, row.total) + '%</span></div></td></tr>';
    });
    if (r.grand) { const has = r.grand.total - r.grand.missing; t += '<tr class="total"><td>Total</td><td class="r">' + N(r.grand.total) + '</td><td class="r green-t">' + N(has) + '</td><td class="r red-t">' + N(r.grand.missing) + '</td><td class="r">' + pct(has, r.grand.total) + '%</td></tr>'; }
    $('ar-table').innerHTML = t + '</tbody></table>';
  }

  const logRules = [[l => l.includes('found'), 'g'], [l => l.includes('error') || l.includes('fatal'), 'r']];
  async function fetchStatus() {
    const r = await WT.call('GET', '/_api/pos-artist-refresh/status');
    if (!r.success) return;
    job = r.job || null;
    const running = !!(job && job.running);
    // Refresh the table once a job finishes (same as the website).
    if (wasRunning && job && !running) fetchStats();
    wasRunning = running;
    $('ar-job-badge').innerHTML = !job ? '' : running ? '<span class="wt-badge run">Running</span>' : job.cancelled ? '<span class="wt-badge warn">Cancelled</span>' : job.finishedAt ? '<span class="wt-badge on">Finished</span>' : '<span class="wt-badge">Idle</span>';
    $('ar-start').classList.toggle('wt-hide', running);
    $('ar-cancel').classList.toggle('wt-hide', !running);
    $('ar-resume').classList.toggle('wt-hide', running || !(job && job.finishedAt));
    lock();
    if (!job || (!running && !job.finishedAt)) $('ar-job').innerHTML = '<p class="muted">No job has run yet.</p>';
    else {
      const p = job.progress || {}, pp = pct(p.processed, p.total);
      const box = (l, v, c) => '<div class="wt-stat c"><div class="v ' + c + '">' + N(v || 0) + '</div><div class="h">' + l + '</div></div>';
      $('ar-job').innerHTML = '<div style="display:flex; justify-content:space-between; font-size:12px; color:#6b7280;"><span>' + N(p.processed) + ' / ' + N(p.total) + ' processed</span><span>' + pp + '%</span></div>' + WT.bar(pp) +
        '<div class="wt-grid" style="grid-template-columns:1fr 1fr; margin-top:10px;">' + box('Found artist', p.found, 'green') + box('Not found', p.notFound, 'amber') + box('Written to the website', p.written, 'blue') + box('Errors', p.errors, 'red') + '</div>' +
        '<div class="wt-note">' + (job.startedAt ? 'Started: ' + new Date(job.startedAt).toLocaleString() + '<br>' : '') + (job.finishedAt ? 'Finished: ' + new Date(job.finishedAt).toLocaleString() : '') + (job.options && job.options.dryRun ? ' <span class="wt-badge warn">Dry run</span>' : '') + '</div>';
    }
    const logs = (job && job.logs) || [];
    $('ar-log-card').classList.toggle('wt-hide', !logs.length);
    if (logs.length) {
      const box = $('ar-log');
      box.innerHTML = logs.map(l => '<div class="' + WT.logClass(l, logRules) + '">' + E(l) + '</div>').join('');
      box.scrollTop = box.scrollHeight;
    }
  }

  $('ar-start').addEventListener('click', async function () {
    if (!selected.length) { WT.toast('Select at least one category.', 'warn'); return; }
    const limit = $('ar-limit').value;
    WT.busy(this, true, 'Starting');
    const r = await WT.call('POST', '/_api/pos-artist-refresh/start', { body: { categories: selected, dryRun: $('ar-dry').checked, batchSize: Number($('ar-batch').value) || 20, skip: Number($('ar-skip').value) || 0, limit: limit ? Number(limit) : null } });
    WT.busy(this, false);
    if (r.success) { WT.toast('Artist refresh started.', 'ok'); fetchStatus(); } else WT.toast(WT.err(r, 'Failed to start job.'), 'bad');
  });
  $('ar-cancel').addEventListener('click', async function () {
    WT.busy(this, true, 'Cancelling');
    const r = await WT.call('POST', '/_api/pos-artist-refresh/cancel');
    WT.busy(this, false);
    WT.toast(r.success ? 'Cancellation requested.' : WT.err(r, 'Failed to cancel.'), r.success ? 'ok' : 'bad');
  });
  $('ar-resume').addEventListener('click', function () {
    if (!job) return;
    const processed = job.lastProcessed || (job.progress && job.progress.processed) || 0, prev = (job.options && job.options.skip) || 0;
    $('ar-skip').value = String(prev + processed);
    WT.toast('Skip set to ' + (prev + processed) + '. Click Start to continue.');
  });

  function renderSingle() {
    const s = single, box = $('ar-s-result');
    if (!s) { box.innerHTML = ''; return; }
    if (!s.found) { box.innerHTML = '<div class="wt-box warn">No accurate artist match found.</div>'; return; }
    const res = s.result || {}, m = res.match;
    let h = '<div class="wt-box"><div class="green-t" style="font-weight:700;">' + (s.saved ? 'Artist updated on the website' : 'Accurate artist found') + '</div><div class="wt-note" style="margin:2px 0;">Source: ' + E(res.source) + '</div>' +
      '<div class="wt-grid two"><div class="wt-stat"><div class="l">Current artist</div><div class="v" style="font-size:15px;">' + E((s.product && s.product.artist) || '(empty)') + '</div></div><div class="wt-stat" style="border-color:#86efac; background:#f0fdf4;"><div class="l green-t">Discogs artist</div><div class="v" style="font-size:15px;">' + E(res.artist) + '</div></div></div>';
    if (m) h += '<div class="wt-note" style="border-top:1px solid #f0f0f0; padding-top:8px;">Title score: ' + E(m.titleScore) + '<br>Artist score: ' + E(m.artistScore) + '<br>Total score: ' + E(m.totalScore) + '<br>Title overlap: ' + Math.round((m.titleOverlap || 0) * 100) + '%</div>';
    if (s.product) h += '<div class="wt-note" style="border-top:1px solid #f0f0f0; padding-top:8px;"><strong>Product:</strong> ' + E(s.product.name) + (s.product.sku ? '<br><strong>SKU:</strong> ' + E(s.product.sku) : '') + '</div>';
    if ($('ar-s-dry').checked && !s.saved && s.product) h += '<button type="button" class="wt-btn green" id="ar-s-save">Save Artist to Website</button>';
    box.innerHTML = h + '</div>';
    const save = $('ar-s-save');
    if (save) save.addEventListener('click', async function () {
      WT.busy(this, true, 'Saving');
      const r = await WT.call('POST', '/_api/pos-artist-refresh/single', { body: { title: $('ar-s-title').value.trim(), artist: $('ar-s-artist').value.trim() || undefined, dryRun: false } });
      WT.busy(this, false);
      if (r.success === false && !('found' in r)) { WT.toast(WT.err(r, 'Save failed.'), 'bad'); return; }
      single = r; renderSingle();
      if (r.saved) { WT.toast('Artist updated on the website.', 'ok'); fetchStats(); }
      else WT.toast('The product artist is no longer unknown, or no write was needed.', 'warn');
    });
  }
  async function singleLookup() {
    const title = $('ar-s-title').value.trim();
    if (!title) { WT.toast('Enter a title first.', 'warn'); return; }
    const btn = $('ar-s-go');
    WT.busy(btn, true, 'Searching');
    single = null; renderSingle();
    const r = await WT.call('POST', '/_api/pos-artist-refresh/single', { body: { title: title, artist: $('ar-s-artist').value.trim() || undefined, dryRun: $('ar-s-dry').checked } });
    WT.busy(btn, false);
    if (r.success === false && !('found' in r)) { WT.toast(WT.err(r, 'Lookup failed.'), 'bad'); return; }
    single = r; renderSingle();
    if (!r.found) WT.toast('No accurate artist match found on Discogs.', 'warn');
    else if (r.saved) { WT.toast('Artist updated on the website.', 'ok'); fetchStats(); }
    else WT.toast('Accurate artist found.', 'ok');
  }
  $('ar-s-go').addEventListener('click', singleLookup);
  ['ar-s-title', 'ar-s-artist'].forEach(id => $(id).addEventListener('keydown', e => { if (e.key === 'Enter') singleLookup(); }));
  $('ar-s-clear').addEventListener('click', () => { $('ar-s-title').value = ''; $('ar-s-artist').value = ''; single = null; renderSingle(); });
  $('ar-stats-refresh').addEventListener('click', function () { fetchStats(this); });

  renderCats();
  fetchStats(); fetchStatus();
  setInterval(fetchStatus, 3000);
})();
</script>
@endverbatim
@endsection
