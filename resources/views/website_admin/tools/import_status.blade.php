@extends('layouts.app')

@section('title', 'Website Import Status')

@section('content')
<div class="ev-wrap ev-wrap-wide">
  @include('website_admin.tools._head', ['active' => 'wa.tools.importStatus'])
  <script>window.WT_ADMIN = @json((bool) $isAdmin);</script>

  <div class="ev-head">
    <div>
      <h1>Import Status</h1>
      <p class="sub">Website product import progress from the Discogs and POS APIs. Refreshes every 30 seconds.</p>
    </div>
    <button type="button" class="wt-btn" id="is-refresh">Refresh</button>
  </div>

  <div class="wt-box info">This is the website catalog. For the Nivessa Backend spreadsheet import into the ERP, see <a href="{{ url('/admin/nivessa-import-status') }}">Nivessa import status</a>.</div>
  @if(!$isAdmin)<div class="wt-box">Importing and clearing need an admin login, so those buttons are greyed out.</div>@endif

  <div class="wt-card wt-hide" id="is-overall"></div>

  <div class="wt-grid two">
    <div class="wt-card">
      <h3>Discogs Import</h3>
      <div id="is-discogs"></div>
      <div class="wt-row">
        <button type="button" class="wt-btn primary is-import" data-source="discogs" data-pages="5" data-admin="1">Import 5 pages</button>
        <button type="button" class="wt-btn danger is-clear" data-source="discogs" data-admin="1">Clear</button>
      </div>
    </div>
    <div class="wt-card">
      <h3>POS Import</h3>
      <div id="is-pos"></div>
      <div class="wt-row">
        <button type="button" class="wt-btn primary is-import" data-source="pos" data-pages="5" data-admin="1">Import 5 pages</button>
        <button type="button" class="wt-btn danger is-clear" data-source="ultimatepos" data-admin="1">Clear</button>
      </div>
    </div>
  </div>

  <div class="wt-grid" id="is-stats" style="margin-bottom:16px;"></div>
  <div class="wt-card wt-hide" id="is-recent"></div>

  <div class="wt-card">
    <h3>Bulk Operations</h3>
    <div class="wt-row">
      <button type="button" class="wt-btn primary is-import" data-source="all" data-pages="10" data-admin="1">Import 10 pages from both sources</button>
      <button type="button" class="wt-btn is-import" data-source="all" data-pages="5" data-admin="1">Import 5 pages from both</button>
      <button type="button" class="wt-btn danger is-clear" data-source="all" data-admin="1">Clear All Imported</button>
    </div>
  </div>
</div>

@verbatim
<script>
(function () {
  const $ = WT.$, E = WT.esc, N = WT.num;
  if (!window.WT_ADMIN) document.querySelectorAll('[data-admin]').forEach(b => { b.disabled = true; b.title = 'Only an admin can do this.'; });
  let importing = false, clearing = false, warned = false;
  function lockButtons() {
    if (!window.WT_ADMIN) return;
    document.querySelectorAll('.is-import').forEach(b => { b.disabled = importing; });
    document.querySelectorAll('.is-clear').forEach(b => { b.disabled = clearing; });
  }
  function sourceBlock(src, cls) {
    const s = src || {};
    return '<div style="display:flex; justify-content:space-between;"><span>Progress</span><strong class="' + cls + '">' + E(s.percentage || 0) + '%</strong></div>' + WT.bar(parseFloat(s.percentage || 0)) +
      '<div class="wt-grid" style="grid-template-columns:1fr 1fr; margin-top:8px;"><div><strong class="' + cls + '">' + N(s.imported || 0) + '</strong><div class="muted" style="font-size:12px;">Imported</div></div><div><strong class="amber-t">' + N(s.remaining || 0) + '</strong><div class="muted" style="font-size:12px;">Remaining</div></div></div>';
  }
  async function fetchStatus(btn) {
    WT.busy(btn, true, 'Refreshing');
    const r = await WT.call('GET', '/_api/product-import/status');
    WT.busy(btn, false);
    if (!r.success) { if (!warned || btn) WT.toast(WT.err(r, 'Failed to fetch import status.'), 'bad'); warned = true; return; }
    const s = r.data || {};
    const ip = s.importProgress;
    if (ip && ip.overall) {
      const o = ip.overall;
      $('is-overall').innerHTML = '<h3>Overall Import Progress</h3><div style="display:flex; justify-content:space-between; font-size:15px;"><span>Total progress</span><strong class="blue-t">' + E(o.percentage) + '%</strong></div>' + WT.bar(parseFloat(o.percentage)) +
        '<div class="wt-grid" style="margin-top:10px; text-align:center;"><div><div class="v green" style="font-size:22px; font-weight:700;">' + N(o.imported) + '</div><div class="muted">Imported</div></div><div><div class="v amber" style="font-size:22px; font-weight:700;">' + N(o.remaining) + '</div><div class="muted">Remaining</div></div><div><div class="v blue" style="font-size:22px; font-weight:700;">' + N(o.total) + '</div><div class="muted">Total available</div></div></div>';
      $('is-overall').classList.remove('wt-hide');
    }
    const src = (ip && ip.sources) || {};
    $('is-discogs').innerHTML = sourceBlock(src.discogs, 'green-t');
    $('is-pos').innerHTML = sourceBlock(src.pos, 'purple-t');
    const sb = s.sourceBreakdown || [];
    const inStock = sb.reduce((a, i) => a + (i.inStock || 0), 0), oos = sb.reduce((a, i) => a + (i.outOfStock || 0), 0);
    $('is-stats').innerHTML = '<div class="wt-stat"><div class="l">Total products</div><div class="v blue">' + N(s.totalProducts || 0) + '</div><div class="h">In the website database</div></div>' +
      '<div class="wt-stat"><div class="l">In stock</div><div class="v green">' + N(inStock) + '</div><div class="h">Available products</div></div>' +
      '<div class="wt-stat"><div class="l">Out of stock</div><div class="v red">' + N(oos) + '</div><div class="h">Unavailable products</div></div>';
    const rec = s.recentImports || [], box = $('is-recent');
    if (!rec.length) { box.classList.add('wt-hide'); return; }
    box.innerHTML = '<h3>Recent Imports</h3><div class="wt-scroll"><table class="wt-tbl"><thead><tr><th>Product name</th><th>Source</th><th>SKU</th><th>Price</th><th>Quantity</th><th>Imported</th></tr></thead><tbody>' +
      rec.map(p => '<tr><td><strong>' + E(p.name) + '</strong></td><td><span class="wt-badge ' + (p.source === 'discogs' ? 'on' : '') + '">' + (p.source === 'discogs' ? 'Discogs' : 'POS') + '</span></td><td style="font-family:monospace;">' + E(p.sku) + '</td><td>$' + E(p.price) + '</td><td><span class="wt-badge ' + (p.quantity > 0 ? 'on' : 'off') + '">' + E(p.quantity) + '</span></td><td class="muted">' + E(WT.dt(p.createdAt)) + '</td></tr>').join('') + '</tbody></table></div>';
    box.classList.remove('wt-hide');
  }
  document.querySelectorAll('.is-import').forEach(b => b.addEventListener('click', async function () {
    const source = this.dataset.source, pages = Number(this.dataset.pages);
    importing = true; lockButtons(); WT.busy(this, true, 'Importing');
    const r = await WT.call('POST', '/_api/product-import/import', { body: { source: source, discogsMaxPages: source === 'discogs' || source === 'all' ? pages : 1, posMaxPages: source === 'pos' || source === 'all' ? pages : 1 } });
    WT.busy(this, false); importing = false; lockButtons();
    if (r.success) { const d = r.data || {}; WT.toast('Import completed. Imported ' + ((d.summary && d.summary.totalImported) || 0) + ' products.', 'ok'); fetchStatus(); }
    else WT.toast('Failed to start import: ' + WT.err(r, 'unknown error'), 'bad');
  }));
  document.querySelectorAll('.is-clear').forEach(b => b.addEventListener('click', async function () {
    const source = this.dataset.source;
    if (!window.confirm('Are you sure you want to clear all imported products from ' + source + '? This cannot be undone.')) return;
    clearing = true; lockButtons(); WT.busy(this, true, 'Clearing');
    const r = await WT.call('DELETE', '/_api/product-import/clear', { body: { source: source } });
    WT.busy(this, false); clearing = false; lockButtons();
    if (r.success) { WT.toast('Cleared ' + (r.deletedCount != null ? r.deletedCount : 0) + ' products.', 'ok'); fetchStatus(); }
    else WT.toast('Failed to clear products: ' + WT.err(r, 'unknown error'), 'bad');
  }));
  $('is-refresh').addEventListener('click', function () { fetchStatus(this); });
  fetchStatus();
  setInterval(fetchStatus, 30000);
})();
</script>
@endverbatim
@endsection
