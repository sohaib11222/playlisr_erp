@extends('layouts.app')

@section('title', 'Bulk Discogs Import')

@section('content')
<div class="ev-wrap ev-wrap-wide">
  @include('website_admin.tools._head', ['active' => 'wa.tools.bulkDiscogsImport'])
  <script>window.WT_BULK = { url: @json(route('wa.tools.bulkDiscogsStream')), admin: @json((bool) $isAdmin) };</script>

  <div class="ev-head">
    <div>
      <h1>Bulk Discogs Import</h1>
      <p class="sub">Import products from the Discogs inventory straight into the website catalog.</p>
    </div>
  </div>

  <div class="wt-box info">This fills the <strong>website</strong> catalog. To import Discogs listings into the ERP itself (Discogs Warehouse location), use <a href="{{ url('/admin/discogs-import-inventory') }}">Discogs Inventory Import</a>.</div>

  <div class="wt-card">
    <h3>Import Configuration</h3>
    <div class="wt-grid two">
      <div class="ev-field"><label>Target products</label><input type="number" id="bd-target" value="500" min="1" max="5000" placeholder="500"><span class="wt-note">Number of products to import (500 to 1,000 recommended).</span></div>
      <div class="ev-field"><label>Start page</label><input type="text" id="bd-start" value="auto" placeholder="auto or page number"><span class="wt-note">Auto finds the last imported page to avoid duplicates.</span></div>
    </div>
    <div class="wt-box info">Auto mode looks through the Discogs inventory for the best starting page (where the fewest products have been imported yet), so it does not waste time on pages that are mostly duplicates. Progress shows live below. Keep this page open to follow progress. Cancel stops following it here, but the website may keep importing in the background.</div>
    <div class="wt-row">
      <button type="button" class="wt-btn primary" id="bd-run" @if(!$isAdmin) disabled title="Only an admin can do this." @endif>Start Import</button>
      <button type="button" class="wt-btn danger wt-hide" id="bd-cancel">Cancel</button>
    </div>
    @if(!$isAdmin)<p class="wt-note">Only an admin can run a bulk import.</p>@endif
  </div>

  <div class="wt-grid wt-hide" id="bd-progress" style="margin-bottom:16px;">
    <div class="wt-stat"><div class="l">Current page</div><div class="v" id="bd-page">0</div></div>
    <div class="wt-stat"><div class="l">Products imported</div><div class="v" id="bd-imported">0</div><div class="h" id="bd-of"></div></div>
    <div class="wt-stat"><div class="l">Progress</div><div class="v" id="bd-pct">0%</div><div id="bd-bar"></div></div>
    <div class="wt-stat"><div class="l">Status</div><div class="v blue" style="font-size:17px;"><span class="wt-spin"></span> Running</div></div>
  </div>

  <div class="wt-card wt-hide" id="bd-results"></div>

  <div class="wt-card wt-hide" id="bd-log-card">
    <h3>Import Logs</h3>
    <div class="wt-log" id="bd-log" style="height:auto; max-height:280px; background:#fff; color:#374151; border:1px solid #e5e7eb;"></div>
  </div>
</div>

@verbatim
<script>
(function () {
  const $ = WT.$, E = WT.esc;
  let logs = [], controller = null, progress = { currentPage: 0, totalImported: 0, targetProducts: 0, percentage: 0 };
  const colors = { success: '#15803d', error: '#dc2626', warning: '#b45309', info: '#374151' };
  function addLog(message, type) {
    logs = logs.slice(-19).concat([{ t: new Date().toLocaleTimeString(), message: message, type: type || 'info' }]);
    $('bd-log-card').classList.remove('wt-hide');
    $('bd-log').innerHTML = logs.map(l => '<div><span class="muted" style="display:inline-block; min-width:90px;">' + E(l.t) + '</span> <span style="color:' + colors[l.type] + ';">' + E(l.message) + '</span></div>').join('');
  }
  function renderProgress() {
    $('bd-page').textContent = progress.currentPage;
    $('bd-imported').textContent = progress.totalImported;
    $('bd-of').textContent = 'of ' + progress.targetProducts + ' target';
    $('bd-pct').textContent = progress.percentage + '%';
    $('bd-bar').innerHTML = WT.bar(Number(progress.percentage) || 0);
  }
  function setRunning(on) {
    $('bd-run').disabled = on || !window.WT_BULK.admin;
    $('bd-run').innerHTML = on ? '<span class="wt-spin"></span> Importing' : 'Start Import';
    $('bd-cancel').classList.toggle('wt-hide', !on);
    $('bd-progress').classList.toggle('wt-hide', !on);
    $('bd-target').disabled = on; $('bd-start').disabled = on;
  }
  function showResults(s) {
    const box = $('bd-results');
    box.innerHTML = '<h3>Import Results</h3><div class="wt-grid">' +
      '<div class="wt-stat"><div class="l">Target products</div><div class="v blue">' + E(s.targetProducts) + '</div></div>' +
      '<div class="wt-stat"><div class="l">Actually imported</div><div class="v green">' + E(s.actualImported) + '</div></div>' +
      '<div class="wt-stat"><div class="l">Pages processed</div><div class="v purple">' + E(s.pagesProcessed) + '</div></div>' +
      '<div class="wt-stat"><div class="l">Completion rate</div><div class="v">' + E(s.completionPercentage) + '%</div></div></div>' +
      '<p style="margin-top:10px;"><span class="wt-badge ' + (s.stoppedReason === 'target_reached' ? 'on' : 'warn') + '">' + (s.stoppedReason === 'target_reached' ? 'Target reached' : 'No more products available') + '</span></p>';
    box.classList.remove('wt-hide');
  }
  function handle(d) {
    switch (d.status) {
      case 'started': addLog(d.message, 'success'); break;
      case 'detecting': addLog(d.message, 'info'); break;
      case 'page_detected': addLog(d.message, 'success'); break;
      case 'page_set': addLog(d.message, 'info'); break;
      case 'warning': addLog(d.message, 'warning'); break;
      case 'progress':
        progress = { currentPage: d.currentPage, totalImported: d.totalImported, targetProducts: d.targetProducts, percentage: d.percentage };
        renderProgress();
        addLog('Processing page ' + d.currentPage + ' (' + d.totalImported + '/' + d.targetProducts + ' products)', 'info');
        break;
      case 'page_complete':
        if (d.imported > 0) addLog('Page ' + d.page + ': ' + d.imported + ' new products imported', 'success');
        else addLog('Page ' + d.page + ': no new products (all duplicates)', 'warning');
        progress.totalImported = d.totalImported; renderProgress();
        break;
      case 'page_error': addLog('Error on page ' + d.page + ': ' + d.error, 'error'); break;
      case 'completed':
        showResults(d.summary || {});
        addLog('Import completed. ' + d.summary.actualImported + '/' + d.summary.targetProducts + ' products imported.', 'success');
        WT.toast('Import completed. ' + d.summary.actualImported + ' products imported.', 'ok');
        break;
      case 'error':
        addLog('Import failed: ' + d.error, 'error');
        WT.toast('Import failed: ' + d.error, 'bad');
        break;
    }
  }

  $('bd-run').addEventListener('click', async function () {
    const target = parseInt($('bd-target').value, 10);
    if (!target) return;
    const startPage = $('bd-start').value.trim() || 'auto';
    logs = []; $('bd-results').classList.add('wt-hide');
    progress = { currentPage: 0, totalImported: 0, targetProducts: target, percentage: 0 };
    renderProgress(); setRunning(true);
    addLog('Starting bulk Discogs import for ' + target + ' products.', 'info');
    controller = new AbortController();
    try {
      const res = await fetch(window.WT_BULK.url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': WT_CFG.csrf },
        credentials: 'same-origin',
        body: JSON.stringify({ targetProducts: target, startPage: startPage }),
        signal: controller.signal
      });
      if (!res.ok) {
        let msg = 'HTTP error ' + res.status;
        try { const j = await res.json(); msg = j.error || j.message || msg; } catch (e) {}
        throw new Error(msg);
      }
      const reader = res.body.getReader(), decoder = new TextDecoder();
      let buf = '';
      while (true) {
        const step = await reader.read();
        if (step.done) break;
        buf += decoder.decode(step.value, { stream: true });
        const lines = buf.split('\n');
        buf = lines.pop();
        lines.filter(l => l.trim()).forEach(l => { try { handle(JSON.parse(l)); } catch (e) {} });
      }
      if (buf.trim()) { try { handle(JSON.parse(buf)); } catch (e) {} }
    } catch (err) {
      if (err.name === 'AbortError') { addLog('Import cancelled.', 'warning'); WT.toast('Import cancelled.', 'warn'); }
      else { addLog('Import failed: ' + err.message, 'error'); WT.toast('Import failed.', 'bad'); }
    } finally {
      setRunning(false);
      controller = null;
    }
  });
  $('bd-cancel').addEventListener('click', function () { if (controller) controller.abort(); setRunning(false); });
})();
</script>
@endverbatim
@endsection
