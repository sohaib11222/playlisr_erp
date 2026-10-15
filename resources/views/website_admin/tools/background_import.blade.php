@extends('layouts.app')

@section('title', 'Background Import')

@section('content')
<div class="ev-wrap ev-wrap-wide">
  @include('website_admin.tools._head', ['active' => 'wa.tools.backgroundImport'])
  <script>window.WT_ADMIN = @json((bool) $isAdmin);</script>

  <div class="ev-head">
    <div>
      <h1>Background Import Management</h1>
      <p class="sub">Queue website product imports from Discogs and POS. This page refreshes every 10 seconds.</p>
    </div>
    <div class="wt-row" style="margin:0;">
      <button type="button" class="wt-btn" id="bi-refresh">Refresh</button>
      <button type="button" class="wt-btn" id="bi-clean" data-admin="1">Clean Old Jobs</button>
    </div>
  </div>

  <div class="wt-card">
    <h3>Start Background Import</h3>
    <div class="wt-row" style="align-items:flex-end;">
      <div class="ev-field"><label>Import type</label>
        <select id="bi-type">
          <option value="continuous">Continuous Sync (All Pages)</option>
          <option value="full">Full Import</option>
          <option value="discogs">Discogs Only</option>
          <option value="pos">POS Only</option>
        </select>
      </div>
      <div class="ev-field" id="bi-source-wrap"><label>Source</label>
        <select id="bi-source">
          <option value="all">All Sources</option>
          <option value="discogs">Discogs Only</option>
          <option value="pos">POS Only</option>
        </select>
      </div>
      <div class="ev-field wt-hide" id="bi-start-wrap"><label>Start page</label><input type="number" id="bi-start" value="1" min="1" style="width:110px;"></div>
      <div class="ev-field wt-hide" id="bi-max-wrap"><label>Max pages</label><input type="number" id="bi-max" value="10" min="1" max="50" style="width:110px;"></div>
    </div>
    <button type="button" class="wt-btn primary" id="bi-go" data-admin="1">Start Background Import</button>
    @if(!$isAdmin)<p class="wt-note">Only an admin can start, cancel or clean imports.</p>@endif
  </div>

  <div class="wt-grid wt-hide" id="bi-stats" style="margin-bottom:16px;"></div>
  <div class="wt-card wt-hide" id="bi-active"></div>
  <div class="wt-card wt-hide" id="bi-history"></div>
  <p class="muted" id="bi-loading"><span class="wt-spin"></span> Loading.</p>
</div>

@verbatim
<script>
(function () {
  const $ = WT.$, E = WT.esc, N = WT.num;
  if (!window.WT_ADMIN) document.querySelectorAll('[data-admin]').forEach(b => { b.disabled = true; b.title = 'Only an admin can do this.'; });
  const MAP = { waiting: ['warn', 'Waiting'], active: ['run', 'Running'], completed: ['on', 'Completed'], failed: ['bad', 'Failed'], stalled: ['warn', 'Stalled'] };
  let statusWarned = false;
  const badge = s => { const m = MAP[s] || MAP.waiting; return '<span class="wt-badge ' + m[0] + '">' + m[1] + '</span>'; };

  function syncForm() {
    const cont = $('bi-type').value === 'continuous';
    $('bi-source-wrap').classList.toggle('wt-hide', !cont);
    $('bi-start-wrap').classList.toggle('wt-hide', cont);
    $('bi-max-wrap').classList.toggle('wt-hide', cont);
  }
  $('bi-type').addEventListener('change', syncForm);

  async function fetchStatus() {
    const r = await WT.call('GET', '/_api/import/background/status');
    $('bi-loading').classList.add('wt-hide');
    if (!r.success) { if (!statusWarned) WT.toast(WT.err(r, 'Failed to fetch background import status.'), 'bad'); statusWarned = true; return; }
    statusWarned = false;
    const s = r.status || {};
    const st = $('bi-stats');
    st.innerHTML = '<div class="wt-stat"><div class="l">Active jobs</div><div class="v blue">' + ((s.active || []).length) + '</div></div>' +
      '<div class="wt-stat"><div class="l">Queue waiting</div><div class="v amber">' + N((s.queue && s.queue.waiting) || 0) + '</div></div>' +
      '<div class="wt-stat"><div class="l">Completed</div><div class="v green">' + ((s.completed || []).length) + '</div></div>' +
      '<div class="wt-stat"><div class="l">Failed</div><div class="v red">' + ((s.failed || []).length) + '</div></div>';
    st.classList.remove('wt-hide');
    const act = s.active || [], box = $('bi-active');
    if (!act.length) { box.classList.add('wt-hide'); return; }
    box.innerHTML = '<h3><span class="wt-spin"></span> Active Jobs</h3><div class="wt-scroll"><table class="wt-tbl"><thead><tr><th>Job ID</th><th>Type</th><th>Started</th><th>Progress</th><th>Status</th><th>Actions</th></tr></thead><tbody>' +
      act.map(j => '<tr><td style="font-family:monospace;">' + E(j.id) + '</td><td><span class="wt-badge">' + E(j.type) + '</span></td><td>' + (j.startedAt ? WT.dt(j.startedAt, { sec: true }) : '-') + '</td><td>' +
        (j.progress ? '<div style="display:flex; gap:6px; align-items:center;"><span style="width:80px;">' + WT.bar(j.progress) + '</span>' + E(j.progress) + '%</div>' : '-') + '</td><td>' + badge(j.status) + '</td><td>' +
        '<button type="button" class="wt-btn sm danger" data-cancel="' + E(j.id) + '"' + (window.WT_ADMIN ? '' : ' disabled title="Only an admin can do this."') + '>Cancel</button></td></tr>').join('') + '</tbody></table></div>';
    box.classList.remove('wt-hide');
  }
  async function fetchHistory() {
    const r = await WT.call('GET', '/_api/import/background/history', { query: { limit: 20 } });
    if (!r.success) return;
    const st = r.statistics, box = $('bi-history');
    box.innerHTML = '<h3 style="justify-content:space-between;"><span>Import History and Statistics</span>' + (st ? '<span style="font-size:13px; font-weight:400;">Success rate: <strong>' + E(st.successRate) + '</strong>, total imported: <strong>' + N(st.totalProductsImported) + '</strong></span>' : '') + '</h3>' +
      '<div class="wt-scroll"><table class="wt-tbl"><thead><tr><th>Job ID</th><th>Type</th><th>Source</th><th>Status</th><th>Imported</th><th>Errors</th><th>Completed</th></tr></thead><tbody>' +
      (r.history || []).map(j => {
        const sum = (j.results && j.results.summary) || {};
        return '<tr><td style="font-family:monospace;">' + E(j.id) + '</td><td><span class="wt-badge">' + E(j.type) + '</span></td><td>' + (j.source ? '<span class="wt-badge">' + E(j.source) + '</span>' : '') + '</td><td>' + badge(j.completedAt ? 'completed' : 'failed') + '</td><td>' + N(sum.totalImported || 0) + '</td><td>' + N(sum.totalErrors || 0) + '</td><td>' + E(WT.dt(j.completedAt || j.failedAt, { sec: true })) + '</td></tr>';
      }).join('') + '</tbody></table></div>';
    box.classList.remove('wt-hide');
  }
  function refreshAll() { fetchStatus(); fetchHistory(); }

  document.addEventListener('click', async function (e) {
    const id = e.target && e.target.dataset && e.target.dataset.cancel;
    if (!id) return;
    WT.busy(e.target, true, 'Cancelling');
    const r = await WT.call('DELETE', '/_api/import/background/cancel/' + encodeURIComponent(id));
    WT.busy(e.target, false);
    WT.toast(r.success ? 'Job ' + id + ' cancelled.' : WT.err(r, 'Failed to cancel job.'), r.success ? 'ok' : 'bad');
    refreshAll();
  });
  $('bi-go').addEventListener('click', async function () {
    WT.busy(this, true, 'Starting');
    const r = await WT.call('POST', '/_api/import/background/start', { body: { type: $('bi-type').value, source: $('bi-source').value, startPage: parseInt($('bi-start').value, 10), maxPages: parseInt($('bi-max').value, 10) } });
    WT.busy(this, false);
    WT.toast(r.success ? (r.message || 'Background import started.') : WT.err(r, 'Failed to start background import.'), r.success ? 'ok' : 'bad');
    refreshAll();
  });
  $('bi-clean').addEventListener('click', async function () {
    WT.busy(this, true, 'Cleaning');
    const r = await WT.call('POST', '/_api/import/background/clean');
    WT.busy(this, false);
    WT.toast(r.success ? 'Old jobs cleaned.' : WT.err(r, 'Failed to clean old jobs.'), r.success ? 'ok' : 'bad');
    refreshAll();
  });
  $('bi-refresh').addEventListener('click', async function () { WT.busy(this, true, 'Refreshing'); await fetchStatus(); WT.busy(this, false); });

  syncForm();
  refreshAll();
  setInterval(refreshAll, 10000);
})();
</script>
@endverbatim
@endsection
