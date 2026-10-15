@extends('layouts.app')

@section('title', 'Sync Dashboard')

@section('content')
<div class="ev-wrap ev-wrap-wide">
  @include('website_admin.tools._head', ['active' => 'wa.tools.syncDashboard'])

  <div class="ev-head">
    <div>
      <h1>Sync Dashboard</h1>
      <p class="sub">Monitor POS and Discogs syncing to the website with a single data fetch.</p>
    </div>
    <div class="wt-row" style="margin:0;">
      <span class="wt-badge" id="sd-conn">Loading</span>
      <button type="button" class="wt-btn" id="sd-refresh">Refresh</button>
    </div>
  </div>

  <div id="sd-error" class="wt-box warn wt-hide"></div>
  <div id="sd-body"><p class="muted"><span class="wt-spin"></span> Loading sync data. The database count can take up to 30 seconds.</p></div>
</div>

@verbatim
<script>
(function () {
  // Shown only until the first database status loads (same fallback as the website page).
  const LAST_KNOWN = { total: 233786, pos: 147831, discogs: 85220, posPublished: 147018, posUnpublished: 813, discogsPublished: 48223, discogsUnpublished: 36997, manualPublished: 689, manualUnpublished: 46 };
  const N = WT.num, E = WT.esc;
  const pctStr = (v, t) => t ? ((v / t) * 100).toFixed(1) + '%' : '0.0%';
  let data = null, updatedAt = null;
  try { const s = localStorage.getItem('nivessa-sync-dashboard-db'); if (s) data = { importStatus: JSON.parse(s) }; } catch (e) {}

  function stat(label, value, hint, cls) {
    return '<div class="wt-stat"><div class="l">' + E(label) + '</div><div class="v ' + (cls || '') + '">' + N(value) + '</div>' + (hint ? '<div class="h">' + E(hint) + '</div>' : '') + '</div>';
  }
  function statBar(label, value, total, cls) {
    const w = total > 0 ? Math.min(100, (Number(value || 0) / total) * 100) : 0;
    return '<div style="margin:6px 0;"><div style="display:flex; justify-content:space-between; font-size:12px; color:#4b5563;"><span>' + E(label) + '</span><span>' + N(value) + ' (' + w.toFixed(1) + '%)</span></div>' + WT.bar(w, cls) + '</div>';
  }
  function pie(label, pub, unpub) {
    const total = Number(pub || 0) + Number(unpub || 0);
    const p = total ? (pub / total) * 100 : 0;
    return '<div style="display:flex; flex-direction:column; align-items:center; gap:8px;">' +
      '<div style="position:relative; width:140px; height:140px; border-radius:50%; background:conic-gradient(#10b981 0% ' + p + '%, #f59e0b ' + p + '% 100%);">' +
      '<div style="position:absolute; inset:20px; border-radius:50%; background:#fff; display:flex; flex-direction:column; align-items:center; justify-content:center;"><strong style="font-size:22px;">' + p.toFixed(1) + '%</strong><span class="muted" style="font-size:11px;">published</span></div></div>' +
      '<strong style="font-size:13px;">' + E(label) + '</strong><span style="font-size:12px;"><span class="green-t">' + N(pub) + ' published</span>, <span class="amber-t">' + N(unpub) + ' unpublished</span></span></div>';
  }
  function breakdown(title, subtitle, item, isDiscogs) {
    const reasons = [['enable_stock = false', item.enableStockFalse], ['adminUnpublished = true', item.adminUnpublished], ['Out Of Stock', item.outOfStock], ['quantity = 0', item.quantityZero], ['Other', item.otherUnpublished]];
    let h = '<div class="wt-card"><h3>' + E(title) + ' <span class="wt-badge">Database</span></h3><p class="wt-note" style="margin-top:-6px;">' + E(subtitle) + '</p>';
    h += '<div class="wt-grid">' + stat('Total', item.count) +
      '<div class="wt-stat"><div class="l">Published</div><div class="v green">' + N(item.published) + '</div><div class="h">' + pctStr(item.published, item.count) + ' of total</div></div>' +
      '<div class="wt-stat"><div class="l">Unpublished</div><div class="v amber">' + N(item.unpublished) + '</div><div class="h">' + pctStr(item.unpublished, item.count) + ' of total</div></div></div>';
    h += '<div class="wt-box"><strong style="font-size:13px;">Publication status</strong>' + statBar('Published', item.published, item.count, 'g') + statBar('Unpublished', item.unpublished, item.count, 'a') + '</div>';
    h += '<div style="display:flex; justify-content:space-between; margin-top:10px;"><strong style="font-size:13px;">Unpublished product details</strong><span class="wt-note" style="margin:0;">Reasons may overlap</span></div>';
    reasons.forEach(r => { h += statBar(r[0], r[1], item.unpublished, 's'); });
    if (isDiscogs) {
      h += '<div class="wt-box info"><strong>Discogs stock status for published products</strong><div class="wt-grid" style="margin-top:6px;"><div>In Stock: <strong>' + N(item.publishedInStock) + '</strong></div><div>Out of stock: <strong>' + N(item.published - (item.publishedInStock || 0)) + '</strong></div></div></div>';
    }
    return h + '</div>';
  }
  function sourceCard(o) {
    const progress = o.website > 0 ? Math.min(100, (o.local / o.website) * 100) : 0;
    let h = '<div class="wt-card"><h3>' + E(o.source) + ' <span class="wt-badge ' + (o.active ? 'run' : '') + '">' + (o.active ? 'Sync active' : 'Live data') + '</span></h3>';
    h += '<div class="wt-grid">' + stat('Published in database', o.local, '', o.cls) + stat(o.websiteLabel, o.website, '', 'green') + '</div>';
    if (o.website !== null && o.website !== undefined) {
      h += '<div style="margin:10px 0;"><div style="display:flex; justify-content:space-between; font-size:12px; color:#6b7280;"><span>Published compared with source data</span><span>' + progress.toFixed(1) + '%</span></div>' + WT.bar(progress) + '</div>';
    } else {
      h += '<p class="wt-note">Source publication count is unavailable.</p>';
    }
    if (o.summary) {
      const s = o.summary;
      h += '<div class="wt-box ok"><strong style="font-size:12px; text-transform:uppercase;">Latest successful POS stock sync</strong><div class="wt-grid" style="margin-top:6px;">' +
        '<div>Total <strong>' + N(s.total) + '</strong></div><div>Active <strong>' + N(s.active) + '</strong></div><div>Archived <strong>' + N(s.archived) + '</strong></div></div>' +
        (s.unknownPublicationState > 0 ? '<p class="amber-t" style="margin:6px 0 0; font-size:12px;">' + N(s.unknownPublicationState) + ' product(s) have an unknown POS publication state.</p>' : '') + '</div>';
    }
    if (o.note) h += '<p class="wt-note" style="background:#f8fafc; padding:8px; border-radius:6px;">' + E(o.note) + '</p>';
    h += '<p class="wt-note" style="border-top:1px solid #f0f0f0; padding-top:8px;">' + (o.lastJob ? 'Last activity: ' + E(o.lastJob) : 'No recent sync activity') + '</p>';
    return h + '</div>';
  }

  function render() {
    const imp = (data && data.importStatus) || {};
    const sb = Array.isArray(imp.sourceBreakdown) ? imp.sourceBreakdown : [];
    const entry = s => sb.find(i => i._id === s);
    const posE = entry('ultimatepos'), discE = entry('discogs'), manE = entry('manual');
    const dbCounts = { pos: posE ? posE.count : LAST_KNOWN.pos, discogs: discE ? discE.count : LAST_KNOWN.discogs };
    const pubCounts = { pos: posE ? posE.published : LAST_KNOWN.posPublished, discogs: discE ? discE.published : LAST_KNOWN.discogsPublished };
    const web = { pos: data && data.posWebsiteCount != null ? data.posWebsiteCount : null, discogs: data && data.discogsWebsiteCount ? data.discogsWebsiteCount : null };
    const bd = {
      pos: posE || { count: LAST_KNOWN.pos, published: LAST_KNOWN.posPublished, unpublished: LAST_KNOWN.posUnpublished, enableStockFalse: 0, adminUnpublished: 4, outOfStock: 771, quantityZero: 771, otherUnpublished: 809 },
      discogs: discE || { count: LAST_KNOWN.discogs, published: LAST_KNOWN.discogsPublished, unpublished: LAST_KNOWN.discogsUnpublished, enableStockFalse: 0, adminUnpublished: 16893, outOfStock: 20008, quantityZero: 20008, otherUnpublished: 97, publishedInStock: 48223 }
    };
    const totalPublished = bd.pos.published + bd.discogs.published + (manE ? manE.published : LAST_KNOWN.manualPublished);
    const total = imp.sourceBreakdown ? imp.totalProducts : LAST_KNOWN.total;
    const totalUnpublished = imp.sourceBreakdown ? imp.totalProducts - totalPublished : bd.pos.unpublished + bd.discogs.unpublished + LAST_KNOWN.manualUnpublished;
    const jobs = (data && data.jobs) || {};
    const hasActive = s => (jobs.active || []).some(j => String(j.source || j.type || '').toLowerCase().includes(s));
    const latest = s => {
      const list = [].concat(jobs.completed || [], jobs.failed || []).filter(j => String(j.source || j.type || '').toLowerCase().includes(s))
        .sort((a, b) => new Date(b.completedAt || b.failedAt || b.createdAt || 0) - new Date(a.completedAt || a.failedAt || a.createdAt || 0));
      return list[0] ? new Date(list[0].completedAt || list[0].failedAt || list[0].createdAt).toLocaleString() : null;
    };
    const fetchedAt = data && data.posSourceFetchedAt;

    let h = '<div class="wt-grid" style="margin-bottom:16px;">' + stat('Total in database', total, 'POS + Discogs combined') + stat('POS in database', dbCounts.pos, 'All UltimatePOS products', 'blue') + stat('Discogs in database', dbCounts.discogs, 'All Discogs products', 'green') + '</div>';
    h += '<div class="wt-grid two">' +
      sourceCard({ source: 'POS / UltimatePOS', cls: 'blue', local: pubCounts.pos, website: web.pos, websiteLabel: web.pos != null ? 'Active in POS (last full sync)' : 'POS source summary unavailable', summary: data && data.posSourceSummary, active: hasActive('pos'), lastJob: latest('pos'),
        note: 'Stored from the last successful full POS scan: total, active (is_inactive = 0), and archived (is_inactive = 1).' + (fetchedAt ? ' Scanned: ' + new Date(fetchedAt).toLocaleString() + '.' : ' Run a complete POS stock sync to create the first summary.') }) +
      sourceCard({ source: 'Discogs', cls: 'green', local: pubCounts.discogs, website: web.discogs, websiteLabel: web.discogs ? 'Published in source' : 'Live source unavailable', active: hasActive('discogs'), lastJob: latest('discogs'),
        note: 'Live counts every For Sale listing. Published counts only products with published = true. Admin-unpublished and out-of-stock records remain excluded.' }) + '</div>';

    // Reconciliation
    h += '<div class="wt-card"><h3>Reconciliation <span class="wt-badge">' + (updatedAt ? 'Checked ' + updatedAt.toLocaleTimeString() : 'Waiting for data') + '</span></h3><p class="wt-note" style="margin-top:-6px;">Compare published database products with live source totals.</p>';
    [['POS / UltimatePOS', pubCounts.pos, web.pos], ['Discogs', pubCounts.discogs, web.discogs]].forEach(r => {
      if (r[2] === null || r[2] === undefined) { h += '<div class="wt-box"><strong>' + E(r[0]) + '</strong><div class="wt-note">Live source total is unavailable. Refresh to retry the source request.</div></div>'; return; }
      const diff = r[2] - r[1], cov = r[2] > 0 ? (r[1] / r[2]) * 100 : 0, healthy = Math.abs(diff) <= Math.max(10, r[2] * 0.01);
      h += '<div class="wt-box"><div style="display:flex; justify-content:space-between; gap:8px; flex-wrap:wrap;"><div><strong>' + E(r[0]) + '</strong><div class="wt-note" style="margin:2px 0;">Published database: <strong>' + N(r[1]) + '</strong>, live source: <strong>' + N(r[2]) + '</strong></div></div><span class="wt-badge ' + (healthy ? 'on' : 'warn') + '">' + (healthy ? 'Healthy' : 'Review needed') + '</span></div>' +
        '<div class="wt-grid" style="margin-top:6px;"><div>Difference <strong class="' + (diff > 0 ? 'amber-t' : 'green-t') + '">' + (diff > 0 ? '+' : '') + N(diff) + '</strong></div><div>Coverage <strong>' + cov.toFixed(1) + '%</strong></div><div>' + (diff > 0 ? 'Live items not published' : diff < 0 ? 'Database exceeds live count' : 'Counts match') + '</div></div></div>';
    });
    h += '</div>';

    // All-source comparison
    const allRows = [['POS / UltimatePOS', dbCounts.pos, data && data.posAllWebsiteCount, 'blue'], ['Discogs (all inventory)', dbCounts.discogs, data && data.discogsAllWebsiteCount, 'green']].filter(r => r[2] !== null && r[2] !== undefined);
    h += '<div class="wt-card"><h3>All source totals comparison</h3><p class="wt-note" style="margin-top:-6px;">Compare every product in the database with complete source totals. Discogs uses all inventory, not only For Sale listings.</p><div class="wt-grid two">';
    allRows.forEach(r => {
      const diff = r[2] - r[1], cov = r[2] > 0 ? (r[1] / r[2]) * 100 : 0;
      h += '<div class="wt-box"><strong>' + E(r[0]) + '</strong> <span class="wt-badge">All data</span><div class="wt-grid" style="margin-top:8px;">' + stat('Total in database', r[1], '', r[3]) + stat('Total in source', r[2], '', 'green') + '</div>' +
        '<div class="wt-grid" style="margin-top:6px;"><div>Difference <strong class="' + (diff > 0 ? 'amber-t' : 'green-t') + '">' + (diff > 0 ? '+' : '') + N(diff) + '</strong></div><div>Database coverage <strong>' + cov.toFixed(1) + '%</strong></div></div></div>';
    });
    if (!allRows.length) h += '<p class="wt-note">Source totals are unavailable right now.</p>';
    h += '</div></div>';

    // Publication statistics
    const max = Math.max(bd.pos.count, bd.discogs.count, 1);
    const cmp = (label, it) => '<div style="margin-bottom:14px;"><div style="display:flex; justify-content:space-between; font-size:13px;"><span>' + E(label) + '</span><strong>' + N(it.count) + ' total</strong></div>' +
      '<div class="wd-r" style="display:flex; gap:8px; align-items:center; font-size:12px; margin-top:4px;"><span style="width:80px;">Published</span><div style="flex:1;">' + WT.bar((it.published / max) * 100, 'g') + '</div><span style="width:70px; text-align:right;">' + N(it.published) + '</span></div>' +
      '<div style="display:flex; gap:8px; align-items:center; font-size:12px; margin-top:4px;"><span style="width:80px;">Unpublished</span><div style="flex:1;">' + WT.bar((it.unpublished / max) * 100, 'a') + '</div><span style="width:70px; text-align:right;">' + N(it.unpublished) + '</span></div></div>';
    h += '<div class="wt-card"><h3>Publication statistics</h3><div class="wt-grid two"><div><strong style="font-size:13px;">Product count comparison</strong><div style="margin-top:10px;">' + cmp('POS / UltimatePOS', bd.pos) + cmp('Discogs', bd.discogs) + '</div></div>' +
      '<div><strong style="font-size:13px;">Published vs. unpublished ratio</strong><div style="display:flex; flex-wrap:wrap; justify-content:space-around; gap:20px; margin-top:10px;">' + pie('POS / UltimatePOS', bd.pos.published, bd.pos.unpublished) + pie('Discogs', bd.discogs.published, bd.discogs.unpublished) + '</div></div></div></div>';

    // Breakdown
    h += '<div class="wt-card"><h3>Publication breakdown</h3><p class="wt-note">Use this section to tell apart products stored in the database, published products, and the reasons products remain unpublished. A product may appear under more than one reason.</p><div class="wt-grid two">' +
      breakdown('POS / UltimatePOS', 'syncSource: ultimatepos', bd.pos, false) + breakdown('Discogs', 'syncSource: discogs', bd.discogs, true) + '</div>' +
      '<div class="wt-box info"><strong>Summary</strong><div class="wt-grid" style="margin-top:4px;"><div>Total products: <strong>' + N(total) + '</strong></div><div>Total published: <strong>' + N(totalPublished) + '</strong> (' + pctStr(totalPublished, total) + ')</div><div>Total unpublished: <strong>' + N(totalUnpublished) + '</strong> (' + pctStr(totalUnpublished, total) + ')</div></div></div></div>';

    // System status
    h += '<div class="wt-card"><h3>System status</h3><div class="wt-grid"><div>Database: <strong>' + (imp.sourceBreakdown ? 'Live query' : 'Last known snapshot') + '</strong></div><div>Auto sync: <strong>' + (data && data.sync && data.sync.isRunning ? 'Active' : 'Inactive') + '</strong></div><div>Update: <strong>' + (updatedAt ? updatedAt.toLocaleTimeString() : '-') + '</strong></div></div></div>';
    WT.$('sd-body').innerHTML = h;
  }

  async function load(btn) {
    WT.busy(btn, true, 'Refreshing');
    const res = await Promise.all([
      WT.call('GET', '/_api/product-import/status', { query: { localOnly: 'true' } }),
      WT.call('GET', '/_api/auto-sync/status'),
      WT.call('GET', '/_api/auto-sync/jobs', { query: { limit: 10 } }),
      WT.call('GET', '/product/pos-live-catalogue-total'),
      WT.call('GET', '/_api/synced-products', { query: { source: 'discogs', page: 1, per_page: 1 } }),
      WT.call('GET', '/product/discogs-source-total')
    ]);
    WT.busy(btn, false);
    const imp = res[0].success ? (res[0].data || null) : null;
    const err = WT.$('sd-error'), conn = WT.$('sd-conn');
    if (!imp || !imp.sourceBreakdown) {
      err.textContent = WT.err(res[0], 'Database status is not available.') + ' The last available data is still displayed.';
      err.classList.remove('wt-hide');
      conn.textContent = 'Disconnected'; conn.className = 'wt-badge bad';
      render();
      return;
    }
    try { localStorage.setItem('nivessa-sync-dashboard-db', JSON.stringify(imp)); } catch (e) {}
    const sum = imp.latestPosSourceSummary;
    const cat = res[3].success ? WT.unwrap(res[3]) : null;
    const live = res[4].success ? WT.unwrap(res[4]) : null;
    const all = res[5].success ? WT.unwrap(res[5]) : null;
    const catTotal = cat && cat.total !== null && cat.total !== undefined && Number.isSafeInteger(Number(cat.total)) ? Number(cat.total) : null;
    data = {
      importStatus: imp,
      sync: res[1].success ? res[1].status : null,
      jobs: res[2].success ? res[2] : null,
      posWebsiteCount: sum && Number.isSafeInteger(Number(sum.active)) ? Number(sum.active) : null,
      posAllWebsiteCount: cat && cat.success === true && cat.filter === 'Complete UltimatePOS Connector catalogue' ? catTotal : null,
      posSourceFetchedAt: sum ? sum.scannedAt || null : null,
      posSourceSummary: sum || null,
      discogsWebsiteCount: live ? (Number(live.discogsTotalItems != null ? live.discogsTotalItems : (live.pagination && live.pagination.total_items)) || null) : null,
      discogsAllWebsiteCount: all ? (Number(all.total) || null) : null
    };
    updatedAt = new Date();
    err.classList.add('wt-hide');
    conn.textContent = 'Single fetch'; conn.className = 'wt-badge on';
    render();
  }

  if (data) render();
  WT.$('sd-refresh').addEventListener('click', function () { load(this); });
  load();
})();
</script>
@endverbatim
@endsection
