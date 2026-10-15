@extends('layouts.app')

@section('title', 'Website Auto Sync')

@section('content')
<div class="ev-wrap ev-wrap-wide">
  @include('website_admin.tools._head', ['active' => 'wa.tools.autoSync'])
  <script>window.WT_ADMIN = @json((bool) $isAdmin);</script>

  <div class="ev-head">
    <div>
      <h1>Automatic Sync Management</h1>
      <p class="sub">The website's POS and Discogs sync jobs. This page refreshes every 30 seconds.</p>
    </div>
    <div class="wt-row" style="margin:0;">
      <button type="button" class="wt-btn" id="as-refresh">Refresh</button>
      <button type="button" class="wt-btn danger wt-hide" id="as-stop" data-admin="1">Stop Auto-Sync</button>
      <button type="button" class="wt-btn primary wt-hide" id="as-start" data-admin="1">Start Auto-Sync</button>
    </div>
  </div>
  @if(!$isAdmin)
    <div class="wt-box info">Some controls (start/stop, schedules, token, orphan sweep, release re-match, full re-sync, price floor apply/undo) need an admin login and are greyed out.</div>
  @endif

  {{-- ============================== OVERVIEW ============================== --}}
  <div class="wt-section"><div><h2>Overview and Health</h2><p>Live status across every job below, plus data-completeness counters.</p></div></div>

  <div class="wt-grid" id="as-overview"><p class="muted"><span class="wt-spin"></span> Loading status.</p></div>
  <div id="as-running" class="wt-banner-run wt-hide" style="margin-top:12px;"></div>

  <div class="wt-card tint-amber" style="margin-top:14px;">
    <h3>Data Health, Backfill Progress <span class="wt-note" style="margin:0; font-weight:400;">Refreshes every 30 seconds.</span></h3>
    <div class="wt-grid two">
      <div class="wt-stat"><div class="l">Unknown artist</div><div class="v amber" id="as-missing-artist">-</div><div class="h">Products still missing an artist name.</div></div>
      <div class="wt-stat"><div class="l">Missing images</div><div class="v amber" id="as-missing-image">-</div><div class="h">Products still missing a product image.</div></div>
    </div>
    <div id="as-recent-backfills"></div>
  </div>

  <div class="wt-card" style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
    <div><strong style="font-size:14px;">Run everything right now</strong><div class="wt-note" style="margin:2px 0 0;">Fires POS sync, Discogs orders sync, Discogs stock sync, and image/artist backfill together.</div></div>
    <button type="button" class="wt-btn primary" data-immediate="all">Sync All Now</button>
  </div>

  {{-- ============================== DISCOGS ============================== --}}
  <div class="wt-section blue"><div><h2>Discogs Sync</h2><p>Keeps website stock status matched to Discogs marketplace listings: orders, full inventory reconcile, cover art, and the access token they all depend on.</p></div></div>

  <div class="wt-card tint-blue">
    <h3>Discogs Orders Sync <span class="wt-badge run">every 15 min</span></h3>
    <p class="wt-note">Fetches the last 500 Discogs orders (most recently updated first) and marks sold items as <strong>Out Of Stock</strong> on the website. This is the fastest safety net and usually catches sales within 15 minutes.</p>
    <div class="wt-row">
      <button type="button" class="wt-btn blue" id="as-orders-run">Run Orders Sync Now</button>
      <span class="wt-note" id="as-orders-next"></span>
    </div>
    <div id="as-orders-result"></div>
  </div>

  <div class="wt-card tint-green">
    <h3>Discogs Full Inventory Stock Sync</h3>
    <p class="wt-note">Scans <strong>all Discogs inventory pages</strong> for account <code>nivessa</code> and matches each listing against website products. Products still <em>For Sale</em> on Discogs are set to <strong>In Stock</strong>. Products that are sold or expired are set to <strong>Out Of Stock</strong>.</p>
    <div class="wt-box"><strong class="green-t">When to use it</strong>
      <ul style="margin:4px 0 0 18px; padding:0; font-size:13px; color:#4b5563;">
        <li>An item sold on Discogs but still shows In Stock on the website.</li>
        <li>An item is still available but the website shows Out Of Stock or the Call button.</li>
        <li>After real-time sync was delayed or had issues.</li>
      </ul>
    </div>
    <div class="wt-row">
      <button type="button" class="wt-btn green" id="as-stock-run">Sync Stock Now</button>
      <button type="button" class="wt-btn" id="as-stock-dry">Dry Run (Preview)</button>
    </div>
    <div id="as-stock-running" class="wt-box ok wt-hide"><span class="wt-spin"></span> Running. Scanning all Discogs inventory pages. This can take 1 to 5 minutes depending on the number of listings.</div>
    <div id="as-stock-result"></div>
    <p class="wt-note">The sync runs in the background. Discogs API rate limits are handled automatically. Automatic scheduling is in the Discogs Schedules table below.</p>
  </div>

  <div class="wt-card">
    <h3>Discogs Token Diagnostics</h3>
    <p class="wt-note">Checks that the <code>DISCOGS_ACCESS_TOKEN</code> on the website server is accepted by Discogs. If it expired, generate a new one at <a href="https://www.discogs.com/settings/developers" target="_blank" rel="noreferrer">discogs.com/settings/developers</a> and paste it below. No server restart is needed.</p>
    <div class="wt-row"><button type="button" class="wt-btn" id="as-token-test">Test Current Token</button></div>
    <div id="as-token-test-result"></div>
    <div class="wt-box warn">
      <strong>Update Discogs Token</strong>
      <p style="margin:4px 0; font-size:12.5px;">Paste the new Personal Access Token from <a href="https://www.discogs.com/settings/developers" target="_blank" rel="noreferrer">discogs.com/settings/developers</a>. It is checked against Discogs right away and applied to the running server. Also update <code>.env</code> on the server so the token survives the next reboot.</p>
      <div class="wt-row">
        <input type="password" class="wt-input w" id="as-token-input" placeholder="Paste new Discogs Personal Access Token" autocomplete="off">
        <button type="button" class="wt-btn amber" id="as-token-update" data-admin="1">Update Token</button>
      </div>
      <div id="as-token-update-result"></div>
    </div>
  </div>

  <div class="wt-card">
    <h3>Force Refresh POS Images (Discogs)</h3>
    <p class="wt-note">Re-fetches a fresh Discogs cover image for every POS product, including those that already have an image. Use this after search accuracy improvements so old, wrong covers get replaced. Processing is rate-limited (about 1 request per second), so large batches run in the background.</p>
    <div class="wt-row">
      <label>Max products to process <input type="number" min="1" max="2000" value="200" class="wt-input n" id="as-img-max"></label>
      <button type="button" class="wt-btn blue" id="as-img-run">Force Refresh All POS Images</button>
    </div>
    <p class="wt-note">Jobs run in the background queue. Progress shows in Data Health above.</p>
  </div>

  <div class="wt-card tint-blue">
    <h3>Discogs Release-ID Re-Match Sweep (One-Time)</h3>
    <p class="wt-note">Re-resolves existing Discogs release IDs with the latest matching logic so legacy products stop sharing bad fallback IDs. Start with a dry run, then run write mode if the results look correct.</p>
    <div class="wt-grid two">
      <div class="wt-box"><div class="wt-note" style="margin:0 0 4px; font-weight:700; text-transform:uppercase;">Categories</div>
        <div class="wt-row" style="margin:0;">
          <label><input type="checkbox" class="as-rm-cat" value="Vinyl Records" checked> Vinyl Records</label>
          <label><input type="checkbox" class="as-rm-cat" value="CDs"> CDs</label>
          <label><input type="checkbox" class="as-rm-cat" value="Cassettes"> Cassettes</label>
        </div>
        <div class="wt-note">Recommended first pass: Vinyl Records only.</div>
      </div>
      <div class="wt-box"><div class="wt-note" style="margin:0 0 4px; font-weight:700; text-transform:uppercase;">Targeting rules</div>
        <div class="wt-row" style="margin:0;"><label>Min shared ID count by artist <input type="number" min="2" max="50" value="3" class="wt-input n" id="as-rm-minshared"></label></div>
        <div class="wt-row" style="margin:4px 0 0;"><label><input type="checkbox" id="as-rm-suspicious" checked> Only suspicious shared release IDs</label></div>
        <div class="wt-row" style="margin:4px 0 0;"><label><input type="checkbox" id="as-rm-manuallock" checked> Respect manual image lock (skip imageSource = manual)</label></div>
      </div>
    </div>
    <div class="wt-row">
      <label>Max products (blank = all eligible) <input type="number" min="1" class="wt-input n" id="as-rm-max" placeholder="all"></label>
      <button type="button" class="wt-btn" id="as-rm-dry" data-admin="1">Dry Run Re-Match</button>
      <button type="button" class="wt-btn blue" id="as-rm-write" data-admin="1">Run Re-Match (Write)</button>
    </div>
    <p class="wt-note">The sweep is queued as a background job. Check the results below before running write mode.</p>
  </div>

  <div class="wt-card">
    <h3 style="justify-content:space-between;"><span>Release Re-Match Results</span><button type="button" class="wt-btn sm" id="as-rm-refresh">Refresh Results</button></h3>
    <div id="as-rm-results"><p class="muted">Loading.</p></div>
  </div>

  {{-- ============================== POS ============================== --}}
  <div class="wt-section green"><div><h2>POS to Site Sync</h2><p>Keeps the website catalog matched to UltimatePOS: new items, quantities, and stale-listing cleanup.</p></div></div>

  <div class="wt-card">
    <h3>POS Quick Actions</h3>
    <div class="wt-row">
      <button type="button" class="wt-btn" data-immediate="pos">Sync POS Now</button>
      <button type="button" class="wt-btn" data-immediate="stock-sync">Sync POS Stock Only</button>
      <button type="button" class="wt-btn" data-immediate="pos-backfill" id="as-backfill-btn">Run Backfill Chunk Now</button>
      <button type="button" class="wt-btn" data-immediate="image-backfill">Backfill Missing Images</button>
      <button type="button" class="wt-btn" data-immediate="artist-backfill">Backfill Missing Artists</button>
      <button type="button" class="wt-btn" data-immediate="pos-image-refresh">POS Image Refresh Now</button>
      <button type="button" class="wt-btn" data-immediate="pos-artist-refresh">POS Artist Refresh Now</button>
      <button type="button" class="wt-btn" id="as-fullresync" data-admin="1" style="border-color:#f59e0b; color:#b45309;">Full Re-Sync (New Arrivals fix)</button>
      <button type="button" class="wt-btn" id="as-gear-pos">Pull Gear Categories from POS</button>
    </div>
    <p class="wt-note"><strong>Sync POS Stock Only</strong> updates quantity and availability for all POS products (lightweight, no new imports).</p>
    <p class="wt-note"><strong>Backfill Missing Images</strong> only fills missing POS product images from Discogs.</p>
    <p class="wt-note"><strong>Backfill Missing Artists</strong> fixes products showing "unknown artist" on the site by looking up the real artist on Discogs. It also fills missing images while it is at it. Runs 2,000 products per click (about 40 to 60 minutes). Click again to continue.</p>
  </div>

  <div class="wt-card">
    <h3>Quick POS Audit</h3>
    <div class="wt-row">
      <label>Stale threshold (hours) <input type="number" min="1" max="168" value="8" class="wt-input n" id="as-audit-hours"></label>
      <button type="button" class="wt-btn primary" id="as-audit-run">Run Quick Audit</button>
    </div>
    <div id="as-audit-result"></div>
  </div>

  <div class="wt-card tint-orange">
    <h3>POS Orphan Sweep</h3>
    <p class="wt-note">Finds products that came from POS but have not appeared in any POS sync pass for the number of days below. These were likely deleted or removed from the POS. The sweep sets them to <strong>published = false, quantity = 0</strong>. Nothing is hard deleted.</p>
    <div class="wt-row">
      <label>Days since last seen in POS <input type="number" min="1" max="90" value="3" class="wt-input n" id="as-orphan-days"></label>
      <button type="button" class="wt-btn" id="as-orphan-preview">Preview Orphans</button>
      <button type="button" class="wt-btn" id="as-orphan-dry" data-admin="1" style="border-color:#fb923c; color:#c2410c;">Dry Run</button>
      <button type="button" class="wt-btn danger" id="as-orphan-run" data-admin="1">Run Sweep Now</button>
    </div>
    <div id="as-orphan-result"></div>
  </div>

  {{-- ============================== OTHER ============================== --}}
  <div class="wt-section"><div><h2>Other Catalog Maintenance</h2><p>One-off cleanup tools that do not talk to POS or Discogs. Pure catalog data fixes.</p></div></div>

  <div class="wt-card tint-slate">
    <h3>Catalog Cleanup Tools</h3>
    <div class="wt-row"><button type="button" class="wt-btn" id="as-gear-fix">Fix Miscategorized Gear</button></div>
    <div class="wt-box">
      <strong style="font-size:14px;">Vinyl price floor ($5 minimum)</strong>
      <p class="wt-note">Raises any vinyl priced under $5 to $5 on the website. Vinyl only, CDs and other formats keep their price. In-store POS prices are not affected. New syncs are floored automatically. Use this to fix records already stored under $5.</p>
      <p id="as-floor-count" style="font-size:13px; font-weight:600; margin:4px 0;"></p>
      <div class="wt-row">
        <button type="button" class="wt-btn" id="as-floor-preview">Preview</button>
        <button type="button" class="wt-btn green" id="as-floor-apply" data-admin="1">Apply $5 floor</button>
        <button type="button" class="wt-btn" id="as-floor-undo" data-admin="1">Undo</button>
      </div>
    </div>
  </div>

  {{-- ============================== SCHEDULES ============================== --}}
  <div class="wt-card">
    <h3>Discogs Schedules</h3>
    <div class="wt-scroll"><table class="wt-tbl"><thead><tr><th>Sync type</th><th>Schedule</th><th>Next run</th><th>Status</th><th>On / off</th></tr></thead>
      <tbody>
        <tr style="opacity:.5;"><td><strong>Discogs Import</strong></td><td>Every 6 hours (upsert, 20 pages)</td><td class="muted">Disabled for server load</td><td><span class="wt-badge off">Disabled</span></td><td><input type="checkbox" disabled></td></tr>
        <tr data-sched="discogs-orders"><td><strong>Discogs Orders Sync</strong></td><td>Every 15 min. Fetches recent Discogs orders and marks sold items Out Of Stock.</td><td class="nx"></td><td class="st"></td><td class="tg"></td></tr>
        <tr data-sched="discogs-stock"><td><strong>Discogs Stock Sync</strong></td><td>Every 6 hours. Full inventory scan from Discogs, updates website quantity.</td><td class="nx"></td><td class="st"></td><td class="tg"></td></tr>
      </tbody></table></div>
  </div>
  <div class="wt-card">
    <h3>POS Schedules</h3>
    <div class="wt-scroll"><table class="wt-tbl"><thead><tr><th>Sync type</th><th>Schedule</th><th>Next run</th><th>Status</th><th>On / off</th></tr></thead>
      <tbody>
        <tr style="opacity:.5;"><td><strong>POS Import</strong></td><td>Every 2 hours (upsert, 10 newest pages)</td><td class="muted">Disabled for server load</td><td><span class="wt-badge off">Disabled</span></td><td><input type="checkbox" disabled></td></tr>
        <tr data-sched="daily"><td><strong>Daily Full Sync</strong></td><td>Daily at 2:00 AM</td><td class="nx"></td><td class="st"></td><td class="tg"></td></tr>
        <tr data-sched="stock-sync"><td><strong>POS Stock Sync</strong></td><td>Daily at 5:00 AM PST (quantity and availability only, all products)</td><td class="nx"></td><td class="st"></td><td class="tg"></td></tr>
        <tr data-sched="pos-backfill"><td><strong>POS Backfill Sweep</strong></td><td>Every 3 hours at :45. Walks the whole catalog in chunks so every product eventually syncs, not just the newest pages.<div class="wt-note" id="as-backfill-progress"></div></td><td class="nx"></td><td class="st"></td><td class="tg"></td></tr>
        <tr data-sched="orphan-sweep"><td><strong>POS Orphan Sweep</strong></td><td>Nightly at 4:30 AM. Marks listings gone from POS as Out Of Stock.</td><td class="nx"></td><td class="st"></td><td class="tg"></td></tr>
        <tr data-sched="pos-image-refresh"><td><strong>POS Image Refresh</strong></td><td>Daily at 3:00 AM. Re-fetches Discogs cover art for POS products missing images.</td><td class="nx"></td><td class="st"></td><td class="tg"></td></tr>
        <tr data-sched="pos-artist-refresh"><td><strong>POS Artist Refresh</strong></td><td>Daily at 6:00 AM. Fills in unknown artist names for POS products from Discogs.</td><td class="nx"></td><td class="st"></td><td class="tg"></td></tr>
      </tbody></table></div>
  </div>
</div>

@verbatim
<script>
(function () {
  const N = WT.num, E = WT.esc, $ = WT.$;
  let status = null, ordersPolling = false, stockPolling = false;
  const ADMIN_TIP = 'Only an admin can do this.';
  if (!window.WT_ADMIN) document.querySelectorAll('[data-admin]').forEach(b => { b.disabled = true; b.title = ADMIN_TIP; });

  const badge = on => '<span class="wt-badge ' + (on ? 'on' : 'off') + '">' + (on ? 'Active' : 'Inactive') + '</span>';
  const fmtNext = v => { if (!v) return 'Not scheduled'; const s = WT.dt(v, { sec: true }); return s; };
  const fmtShort = v => v ? WT.dt(v) : 'Never';

  // ---------------------------------------------------------- status
  async function fetchStatus() {
    const r = await WT.call('GET', '/_api/auto-sync/status');
    if (!r.success) return;
    status = r.status || {};
    renderStatus();
  }
  function renderStatus() {
    const s = status || {};
    const act = s.activeSchedules || [];
    $('as-start').classList.toggle('wt-hide', !!s.enabled);
    $('as-stop').classList.toggle('wt-hide', !s.enabled);
    const last = s.lastSyncAttempts || {};
    $('as-overview').innerHTML =
      '<div class="wt-stat"><div class="l">Service status</div><div class="v" style="font-size:16px;">' + badge(s.enabled) + '</div></div>' +
      '<div class="wt-stat"><div class="l">Active schedules</div><div class="v">' + act.length + '</div><div class="h">' + E(act.join(', ') || 'None active') + '</div></div>' +
      '<div class="wt-stat"><div class="l">Last POS sync</div><div class="v" style="font-size:15px;">' + fmtShort(last.pos) + '</div></div>' +
      '<div class="wt-stat"><div class="l">Last Discogs orders</div><div class="v" style="font-size:15px;">' + fmtShort(last.discogs) + '</div><div class="h">every 15 min</div></div>' +
      '<div class="wt-stat"><div class="l">Last stock reconcile</div><div class="v" style="font-size:15px;">' + fmtShort(last.discogsStock) + '</div><div class="h">every 6 hours</div></div>';

    const p = s.syncInProgress || {};
    const labels = [['pos', 'POS sync'], ['posBackfill', 'POS backfill sweep'], ['discogs', 'Discogs orders sync'], ['discogsStock', 'Discogs stock reconcile'], ['posImageRefresh', 'POS image refresh'], ['posArtistRefresh', 'POS artist refresh']];
    const running = labels.filter(l => p[l[0]]);
    const rb = $('as-running');
    if (running.length) {
      rb.innerHTML = '<span class="wt-spin"></span> <strong>Currently running:</strong> ' + running.map(l => '<span class="wt-badge run">' + l[1] + '</span>').join(' ') + '<span style="margin-left:auto; font-size:12px;">Refreshes every 30 seconds.</span>';
      rb.classList.remove('wt-hide');
    } else rb.classList.add('wt-hide');

    $('as-orders-next').textContent = 'Next auto-run: ' + (s.nextSchedules && s.nextSchedules['discogs-orders'] ? WT.dt(s.nextSchedules['discogs-orders'], { timeOnly: true, sec: true }) : 'unknown');
    $('as-orders-run').disabled = ordersPolling || !!p.discogs;
    $('as-backfill-btn').disabled = !!p.posBackfill;
    if (!ordersPolling && s.lastDiscogsOrdersSyncResult) renderOrdersResult(s.lastDiscogsOrdersSyncResult);
    if (!stockPolling && s.lastDiscogsStockSyncResult) renderStockResult(s.lastDiscogsStockSyncResult);

    // schedules
    document.querySelectorAll('tr[data-sched]').forEach(tr => {
      const key = tr.dataset.sched;
      const on = key === 'discogs-orders' ? (act.includes('discogs-orders') || act.includes('discogs')) : act.includes(key);
      tr.querySelector('.nx').textContent = fmtNext(s.nextSchedules && s.nextSchedules[key]);
      tr.querySelector('.st').innerHTML = badge(on);
      tr.querySelector('.tg').innerHTML = '<input type="checkbox" ' + (on ? 'checked' : '') + ((!s.enabled || !window.WT_ADMIN) ? ' disabled title="' + (window.WT_ADMIN ? 'Start auto-sync first.' : ADMIN_TIP) + '"' : '') + ' data-toggle="' + key + '">';
    });
    const bf = s.posBackfill || {};
    $('as-backfill-progress').innerHTML = bf.totalPages > 0
      ? 'Cycle progress: page ' + N(bf.cursorPage) + ' / ' + N(bf.totalPages) + ' (' + E(bf.pctThroughCycle) + '%)' + (bf.lastCycleCompletedAt ? ', last full cycle ' + WT.dt(bf.lastCycleCompletedAt) : '') + (bf.lastError ? ' <span class="red-t">Last error: ' + E(bf.lastError) + '</span>' : '')
      : '';
  }
  document.addEventListener('change', async function (e) {
    const key = e.target && e.target.dataset && e.target.dataset.toggle;
    if (!key) return;
    const enabled = e.target.checked;
    const r = await WT.call('POST', '/_api/auto-sync/toggle', { body: { syncType: key, enabled: enabled } });
    if (r.success) WT.toast(key + ' sync ' + (enabled ? 'enabled' : 'disabled') + '.', 'ok');
    else WT.toast(WT.err(r, 'Failed to ' + (enabled ? 'enable' : 'disable') + ' ' + key + ' sync.'), 'bad');
    fetchStatus();
  });

  // ---------------------------------------------------------- artist stats
  async function fetchArtistStats() {
    const r = await WT.call('GET', '/_api/auto-sync/artist-stats');
    if (!r.success) return;
    $('as-missing-artist').textContent = N(r.missingArtistCount);
    $('as-missing-image').textContent = N(r.missingImageCount);
    const runs = (r.recentBackfills || []).slice(0, 5);
    if (!runs.length) { $('as-recent-backfills').innerHTML = ''; return; }
    let h = '<div class="wt-box"><div class="wt-note" style="margin:0 0 6px; font-weight:700; text-transform:uppercase;">Recent backfill runs</div>';
    runs.forEach(run => {
      const x = run.results || {}, when = run.completedAt ? WT.dt(run.completedAt) : 'unknown time';
      let body;
      if (run.source === 'artist-backfill') {
        body = '<span class="wt-badge">artist</span> scanned <strong>' + N(x.scanned || 0) + '</strong>, filled <strong class="green-t">' + N(x.artistUpdated || 0) + '</strong> artists, <strong class="green-t">' + N(x.imageUpdated || 0) + '</strong> images' + (x.noMatch ? ', <span class="muted">' + N(x.noMatch) + ' no match</span>' : '') + (x.errors ? ', <span class="red-t">' + N(x.errors) + ' errors</span>' : '');
      } else if (run.source === 'release-rematch') {
        body = '<span class="wt-badge">rematch</span> scanned <strong>' + N(x.scanned || 0) + '</strong>, eligible <strong>' + N(x.eligible || 0) + '</strong>, rematched <strong class="green-t">' + N(x.rematched || 0) + '</strong>' + (x.unchanged ? ', <span class="muted">' + N(x.unchanged) + ' unchanged</span>' : '') + (x.noMatch ? ', <span class="muted">' + N(x.noMatch) + ' no match</span>' : '') + (x.errors ? ', <span class="red-t">' + N(x.errors) + ' errors</span>' : '');
      } else {
        body = '<span class="wt-badge">image</span> scanned <strong>' + N(x.scanned || 0) + '</strong>, filled <strong class="green-t">' + N(x.updated || 0) + '</strong> images' + (x.errors ? ', <span class="red-t">' + N(x.errors) + ' errors</span>' : '');
      }
      h += '<div style="font-size:13px; margin:4px 0;">' + body + ' <span class="muted" style="font-size:12px;">(' + E(when) + ')</span></div>';
    });
    $('as-recent-backfills').innerHTML = h + '</div>';
  }

  // ---------------------------------------------------------- release rematch jobs
  async function fetchRematchJobs() {
    const r = await WT.call('GET', '/_api/auto-sync/jobs', { query: { source: 'release-rematch', limit: 10 } });
    if (!r.success) { $('as-rm-results').innerHTML = '<div class="wt-box bad">' + E(WT.err(r, 'Could not load release re-match jobs.')) + '</div>'; return; }
    const j = { active: r.active || [], completed: r.completed || [], failed: r.failed || [] };
    let h = '<div class="wt-grid"><div class="wt-stat"><div class="l">Active</div><div class="v">' + j.active.length + '</div></div><div class="wt-stat"><div class="l">Completed</div><div class="v">' + j.completed.length + '</div></div><div class="wt-stat"><div class="l">Failed</div><div class="v red">' + j.failed.length + '</div></div></div>';
    if (j.active.length) h += '<div class="wt-box warn"><strong>Jobs running now</strong>' + j.active.map(x => '<div class="wt-note" style="margin:2px 0;">Job #' + E(x.id) + ', started ' + (x.startedAt ? WT.dt(x.startedAt, { sec: true }) : '-') + '</div>').join('') + '</div>';
    if (j.completed.length) {
      const l = j.completed[0], x = l.results || {};
      h += '<div class="wt-box ok"><strong>Latest completed</strong><div style="font-size:13px; margin-top:4px;">Job #' + E(l.id) + ', ' + (l.completedAt ? WT.dt(l.completedAt, { sec: true }) : '-') + '</div>' +
        '<div style="font-size:13px;">Mode: <strong>' + (x.dryRun ? 'dry run' : 'write') + '</strong>, scanned <strong>' + N(x.scanned || 0) + '</strong>, eligible <strong>' + N(x.eligible || 0) + '</strong>, rematched <strong>' + N(x.rematched || 0) + '</strong></div>' +
        '<div class="wt-note" style="margin:2px 0;">Unchanged ' + N(x.unchanged || 0) + ', no match ' + N(x.noMatch || 0) + ', errors ' + N(x.errors || 0) + '</div></div>';
      if (Array.isArray(x.samples) && x.samples.length) {
        h += '<strong style="font-size:13px;">Sample changes (preview)</strong><div class="wt-scroll"><table class="wt-tbl"><thead><tr><th>Product</th><th>Artist</th><th>Old release ID</th><th>New release ID</th><th>Status</th></tr></thead><tbody>' +
          x.samples.slice(0, 30).map(s => '<tr><td>' + E(s.name || '-') + '</td><td>' + E(s.artist || '-') + '</td><td>' + E(s.oldReleaseId || '-') + '</td><td>' + E(s.newReleaseId || '-') + '</td><td>' + (s.error ? '<span class="wt-badge bad">error</span>' : '<span class="wt-badge on">candidate change</span>') + '</td></tr>').join('') + '</tbody></table></div>';
      }
    } else {
      h += '<p class="wt-note">No release re-match results yet. Run a dry run first, then click Refresh Results.</p>';
    }
    if (j.failed.length) h += '<div class="wt-box bad"><strong>Failed jobs</strong>' + j.failed.slice(0, 3).map(x => '<div style="font-size:12px;">Job #' + E(x.id) + ': ' + E(x.error || 'Unknown error') + '</div>').join('') + '</div>';
    $('as-rm-results').innerHTML = h;
  }

  // ---------------------------------------------------------- discogs orders
  function renderOrdersResult(x) {
    const cls = x.errors > 0 ? 'bad' : x.hitPageLimit ? 'warn' : 'info';
    let h = '<div class="wt-box ' + cls + '"><strong>' + (x.errors > 0 ? 'Sync failed' : x.hitPageLimit ? 'Sync complete (page limit hit)' : 'Sync complete') + '</strong>' + (x.completedAt ? ' <span class="muted" style="font-size:12px;">' + WT.dt(x.completedAt, { sec: true }) + '</span>' : '');
    if (x.hitPageLimit) h += '<p style="font-size:12px; margin:4px 0;">Hit the 500-order cap. Very old orders may not have been checked this run. The 6-hour full stock reconcile will catch any remaining gaps.</p>';
    h += '<div class="wt-grid" style="margin-top:8px;">' +
      '<div class="wt-stat c"><div class="l">Orders checked</div><div class="v">' + N(x.totalProcessed) + '</div><div class="h">' + N(x.pagesScanned) + ' pages</div></div>' +
      '<div class="wt-stat c"><div class="l">New orders</div><div class="v blue">' + N(x.newOrders || 0) + '</div></div>' +
      '<div class="wt-stat c"><div class="l">Status changes</div><div class="v">' + N(x.statusChanges || 0) + '</div></div>' +
      '<div class="wt-stat c"><div class="l">Marked Out Of Stock</div><div class="v red">' + N(x.markedOutOfStock || 0) + '</div></div>' +
      '<div class="wt-stat c"><div class="l">Restored In Stock</div><div class="v green">' + N(x.restoredInStock || 0) + '</div></div></div>';
    if (x.errors > 0) h += '<p class="red-t" style="font-size:12px; margin:6px 0 0;">' + N(x.errors) + ' error(s). Check the server logs.</p>';
    $('as-orders-result').innerHTML = h + '</div>';
  }
  $('as-orders-run').addEventListener('click', async function () {
    const btn = this;
    WT.busy(btn, true, 'Syncing');
    ordersPolling = true;
    const r = await WT.call('POST', '/_api/auto-sync/immediate', { body: { source: 'discogs-orders' } });
    if (!r.success) { WT.toast(WT.err(r, 'Failed to start orders sync.'), 'bad'); ordersPolling = false; WT.busy(btn, false); return; }
    WT.toast('Discogs orders sync started (Job ID: ' + r.jobId + ').', 'ok');
    const started = Date.now();
    const iv = setInterval(async function () {
      const s = await WT.call('GET', '/_api/auto-sync/status');
      const done = s.success && !(s.status && s.status.syncInProgress && s.status.syncInProgress.discogs);
      if (done || Date.now() - started > 5 * 60 * 1000) {
        clearInterval(iv); ordersPolling = false; WT.busy(btn, false);
        if (done && s.status.lastDiscogsOrdersSyncResult) {
          const x = s.status.lastDiscogsOrdersSyncResult;
          renderOrdersResult(x);
          WT.toast('Orders sync done. ' + x.markedOutOfStock + ' Out Of Stock, ' + x.restoredInStock + ' restored.', 'ok');
        }
        fetchStatus();
      }
    }, 3000);
  });

  // ---------------------------------------------------------- discogs stock
  function renderStockResult(x) {
    const cls = x.errors > 0 ? 'bad' : !x.wasComplete ? 'warn' : 'ok';
    let h = '<div class="wt-box ' + cls + '"><strong>' + (x.errors > 0 ? 'Sync failed' : x.wasComplete ? 'Sync complete (full scan)' : 'Sync partial, Out Of Stock skipped') + '</strong>' + (x.dryRun ? ' <span class="amber-t" style="font-size:12px;">(dry run, no changes written)</span>' : '');
    if (!x.wasComplete) {
      h += '<div class="wt-box warn"><strong>' + (x.patRejected ? 'Partial scan. The Discogs Personal Access Token was rejected.' : 'Partial scan. It stopped early, and this is not a token problem.') + '</strong>' +
        '<p style="font-size:12px; margin:4px 0;">Only <strong>' + N(x.pagesScanned) + '</strong> of <strong>' + N(x.totalPages) + '</strong> pages were read (' + N(x.totalListings) + ' listings). Products found were set to In Stock. Products not found were left unchanged, since they may be on pages that were not scanned.</p>' +
        (x.errorDetails && x.errorDetails.length ? '<p style="font-size:12px; margin:4px 0;"><strong>Reason reported by the server:</strong> ' + E(x.errorDetails[0]) + '</p>' : '') +
        (x.patRejected
          ? '<p style="font-size:12px; margin:4px 0;"><strong>Fix:</strong> update the token for account nivessa with a valid one from <a href="https://www.discogs.com/settings/developers" target="_blank" rel="noreferrer">discogs.com/settings/developers</a> (Update Discogs Token below), then use Test Current Token before running again.</p>'
          : '<p style="font-size:12px; margin:4px 0;">The token itself may be fine (check Test Current Token below). This is usually Discogs rate-limiting when another Discogs job overlaps this scan. Run Sync Stock Now again once other jobs have finished.</p>') + '</div>';
    }
    h += '<div class="wt-grid" style="margin-top:8px;">' +
      '<div class="wt-stat c"><div class="l">Listings scanned</div><div class="v">' + N(x.totalListings) + '</div><div class="h">' + N(x.pagesScanned) + (x.totalPages > x.pagesScanned ? ' / ' + N(x.totalPages) : '') + ' pages</div></div>' +
      '<div class="wt-stat c"><div class="l">Products checked</div><div class="v">' + N(x.totalProducts) + '</div></div>' +
      '<div class="wt-stat c"><div class="l">Restored In Stock</div><div class="v green">' + N(x.markedInStock || 0) + '</div></div>' +
      '<div class="wt-stat c" style="' + (x.wasComplete ? '' : 'opacity:.5;') + '"><div class="l">Marked Out Of Stock</div><div class="v red">' + N(x.markedOutOfStock || 0) + '</div>' + (x.wasComplete ? '' : '<div class="h amber-t">skipped (partial)</div>') + '</div></div>';
    h += '<p class="wt-note">Skipped: ' + N(x.skipped || 0) + ' (already correct, admin-unpublished, or partial scan)' + (x.completedAt ? '. Completed: ' + WT.dt(x.completedAt, { sec: true }) : '') + (x.errors > 0 ? '. <span class="red-t">Error: ' + E(x.errorDetails && x.errorDetails[0]) + '</span>' : '') + '</p>';
    $('as-stock-result').innerHTML = h + '</div>';
  }
  async function runStockSync(dryRun, btn) {
    const both = [$('as-stock-run'), $('as-stock-dry')];
    both.forEach(b => b.disabled = true);
    WT.busy(btn, true, dryRun ? 'Running' : 'Syncing');
    stockPolling = true;
    $('as-stock-result').innerHTML = '';
    const r = await WT.call('POST', '/_api/auto-sync/discogs-stock-sync', { body: { dryRun: dryRun } });
    const finish = () => { stockPolling = false; WT.busy(btn, false); both.forEach(b => b.disabled = false); $('as-stock-running').classList.add('wt-hide'); };
    if (!r.success) { WT.toast(WT.err(r, 'Failed to add Discogs stock sync to the queue.'), 'bad'); finish(); return; }
    $('as-stock-running').classList.remove('wt-hide');
    WT.toast(dryRun ? 'Discogs stock sync (dry run) added to the queue' + (r.jobId ? ', job #' + r.jobId : '') + '.' : 'Discogs stock sync added to the background queue' + (r.jobId ? ', job #' + r.jobId : '') + '. Results show below.', 'ok');
    const started = Date.now();
    const iv = setInterval(async function () {
      const s = await WT.call('GET', '/_api/auto-sync/status');
      const done = s.success && !(s.status && s.status.syncInProgress && s.status.syncInProgress.discogsStock);
      if (done || Date.now() - started > 20 * 60 * 1000) {
        clearInterval(iv); finish();
        if (done && s.status.lastDiscogsStockSyncResult) {
          const x = s.status.lastDiscogsStockSyncResult;
          renderStockResult(x);
          if (x.errors > 0) WT.toast('Discogs stock sync failed: ' + ((x.errorDetails && x.errorDetails[0]) || 'unknown error'), 'bad');
          else WT.toast('Discogs stock sync complete. ' + x.markedInStock + ' restored In Stock, ' + x.markedOutOfStock + ' marked Out Of Stock' + (x.dryRun ? ' (dry run).' : '.'), 'ok');
        }
        fetchStatus();
      }
    }, 5000);
  }
  $('as-stock-run').addEventListener('click', function () { runStockSync(false, this); });
  $('as-stock-dry').addEventListener('click', function () { runStockSync(true, this); });

  // ---------------------------------------------------------- token
  async function testToken(btn) {
    WT.busy(btn, true, 'Testing');
    const r = await WT.call('GET', '/_api/auto-sync/test-discogs');
    WT.busy(btn, false);
    if (r.success) {
      $('as-token-test-result').innerHTML = '<div class="wt-box ok"><strong>Token is valid</strong><div>Discogs user: <strong>' + E(r.discogsUser) + '</strong></div><div style="font-size:12px;">Token preview: <code>' + E(r.tokenPreview) + '</code> (' + E(r.tokenLength) + ' chars)</div></div>';
      WT.toast('Discogs token OK, logged in as ' + r.discogsUser + '.', 'ok');
    } else if (r.httpStatus || r.tokenPreview) {
      $('as-token-test-result').innerHTML = '<div class="wt-box bad"><strong>Token rejected, HTTP ' + E(r.httpStatus) + '</strong><div style="font-size:12px;">Token preview: <code>' + E(r.tokenPreview) + '</code> (' + E(r.tokenLength) + ' chars)</div>' + (r.discogsError && r.discogsError.message ? '<div style="font-size:12px;">Discogs says: <em>' + E(r.discogsError.message) + '</em></div>' : '') + '</div>';
      WT.toast('Discogs token failed: HTTP ' + r.httpStatus + '.', 'bad');
    } else {
      $('as-token-test-result').innerHTML = '';
      WT.toast(WT.err(r, 'Could not reach the Discogs test endpoint.'), 'bad');
    }
  }
  $('as-token-test').addEventListener('click', function () { testToken(this); });
  async function updateToken() {
    const input = $('as-token-input'), token = input.value.trim(), btn = $('as-token-update');
    if (!token || btn.disabled) return;
    WT.busy(btn, true, 'Validating');
    const r = await WT.call('POST', '/_api/auto-sync/update-discogs-token', { body: { token: token } });
    WT.busy(btn, false);
    if (r.success) {
      $('as-token-update-result').innerHTML = '<div class="wt-box ok"><strong>Token updated</strong><div style="font-size:12px;">Validated as <strong>' + E(r.discogsUser) + '</strong>, preview <code>' + E(r.tokenPreview) + '</code></div><div style="font-size:12px;">The full Discogs stock sync can now run. Click Sync Stock Now above to clear the backlog.</div></div>';
      WT.toast('Token updated, validated as ' + r.discogsUser + '.', 'ok');
      input.value = '';
      testToken($('as-token-test'));
    } else {
      $('as-token-update-result').innerHTML = '<div class="wt-box bad"><strong>' + E(WT.err(r, 'Failed to update token.')) + '</strong>' + (r.tokenPreview ? '<div style="font-size:12px;">Token preview: <code>' + E(r.tokenPreview) + '</code></div>' : '') + '</div>';
      WT.toast(WT.err(r, 'Token rejected by Discogs.'), 'bad');
    }
  }
  $('as-token-update').addEventListener('click', updateToken);
  $('as-token-input').addEventListener('keydown', e => { if (e.key === 'Enter') updateToken(); });

  // ---------------------------------------------------------- simple actions
  document.querySelectorAll('[data-immediate]').forEach(btn => btn.addEventListener('click', async function () {
    const source = this.dataset.immediate;
    const body = source === 'image-backfill' ? { source: source, options: { maxProducts: 100, latestFirst: true, recentSyncedOnly: true, maxDiscogsApiLookups: 80 } }
      : source === 'artist-backfill' ? { source: source, options: { maxProducts: 2000, alsoFillImages: true, latestFirst: true } }
      : { source: source };
    WT.busy(this, true, 'Starting');
    const r = await WT.call('POST', '/_api/auto-sync/immediate', { body: body });
    WT.busy(this, false);
    if (r.success) { WT.toast('Immediate ' + source + ' sync started (Job ID: ' + r.jobId + ').', 'ok'); fetchStatus(); }
    else WT.toast(WT.err(r, 'Failed to start immediate sync.'), 'bad');
  }));

  $('as-start').addEventListener('click', async function () {
    WT.busy(this, true, 'Starting');
    const r = await WT.call('POST', '/_api/auto-sync/start');
    WT.busy(this, false);
    WT.toast(r.success ? 'Automatic sync service started.' : WT.err(r, 'Failed to start automatic sync.'), r.success ? 'ok' : 'bad');
    fetchStatus();
  });
  $('as-stop').addEventListener('click', async function () {
    WT.busy(this, true, 'Stopping');
    const r = await WT.call('POST', '/_api/auto-sync/stop');
    WT.busy(this, false);
    WT.toast(r.success ? 'Automatic sync service stopped.' : WT.err(r, 'Failed to stop automatic sync.'), r.success ? 'ok' : 'bad');
    fetchStatus();
  });

  $('as-fullresync').addEventListener('click', async function () {
    if (!window.confirm('Run a FULL POS re-sync now?\n\nThis walks the whole catalogue to backfill real "added" dates and store attribution so New Arrivals shows your newest stock. It runs in the background (no customer emails) and can take several minutes.')) return;
    WT.busy(this, true, 'Full Re-Sync running');
    const r = await WT.call('POST', '/product/full-sync/trigger', { body: {} });
    WT.busy(this, false);
    if (r.success) WT.toast(r.message || 'Full re-sync started.', 'ok');
    else WT.toast(r._http === 409 ? 'A full re-sync is already running.' : WT.err(r, 'Failed to start full re-sync.'), 'bad');
  });
  $('as-gear-pos').addEventListener('click', async function () {
    WT.busy(this, true, 'Pulling gear');
    const r = await WT.call('POST', '/product/resync-audio-gear-from-pos', { body: {} });
    WT.busy(this, false);
    WT.toast(r.success ? (r.message || 'Re-pulled ' + (r.updated || 0) + ' gear items from POS.') : WT.err(r, 'Failed to re-pull gear from POS.'), r.success ? 'ok' : 'bad');
  });
  $('as-gear-fix').addEventListener('click', async function () {
    if (!window.confirm('Re-label mislabeled audio gear now?\n\nScans the catalogue and flips products whose type says player / turntable / speaker / headphones / receiver from Vinyl Records to Audio Gear. Fast (seconds), no customer emails.')) return;
    WT.busy(this, true, 'Fixing gear');
    const r = await WT.call('POST', '/product/recategorize-audio-gear', { body: {} });
    WT.busy(this, false);
    WT.toast(r.success ? (r.message || 'Re-labeled ' + (r.updated || 0) + ' products.') : WT.err(r, 'Failed to recategorize audio gear.'), r.success ? 'ok' : 'bad');
  });

  $('as-img-run').addEventListener('click', async function () {
    const max = Number($('as-img-max').value || 200);
    WT.busy(this, true, 'Queuing');
    const r = await WT.call('POST', '/_api/auto-sync/refresh-pos-images', { body: { maxProducts: max } });
    WT.busy(this, false);
    if (r.success) { WT.toast('Force image refresh started (Job ID: ' + r.jobId + '), up to ' + max + ' products.', 'ok'); fetchStatus(); }
    else WT.toast(WT.err(r, 'Failed to start force image refresh.'), 'bad');
  });

  async function queueRematch(dryRun, btn) {
    const cats = Array.from(document.querySelectorAll('.as-rm-cat:checked')).map(c => c.value);
    if (!cats.length) { WT.toast('Select at least one category.', 'warn'); return; }
    const max = String($('as-rm-max').value || '').trim();
    WT.busy(btn, true, 'Queuing');
    const r = await WT.call('POST', '/_api/auto-sync/release-rematch', { body: {
      categories: cats, dryRun: dryRun, maxProducts: max ? Number(max) : null,
      onlySuspiciousSharedIds: $('as-rm-suspicious').checked, minSharedByArtist: Number($('as-rm-minshared').value) || 3,
      latestFirst: true, respectAdminUnpublishedLock: true, skipManualImageLock: $('as-rm-manuallock').checked, sampleLimit: 200
    } });
    WT.busy(btn, false);
    if (r.success) { WT.toast('Release re-match ' + (dryRun ? 'dry run' : 'write') + ' queued (Job ID: ' + r.jobId + ').', 'ok'); fetchStatus(); fetchRematchJobs(); }
    else WT.toast(WT.err(r, 'Failed to queue release re-match sweep.'), 'bad');
  }
  $('as-rm-dry').addEventListener('click', function () { queueRematch(true, this); });
  $('as-rm-write').addEventListener('click', function () { queueRematch(false, this); });
  $('as-rm-refresh').addEventListener('click', async function () { WT.busy(this, true, 'Refreshing'); await fetchRematchJobs(); WT.busy(this, false); });

  // ---------------------------------------------------------- audit
  $('as-audit-run').addEventListener('click', async function () {
    WT.busy(this, true, 'Running audit');
    const r = await WT.call('GET', '/_api/auto-sync/audit', { query: { staleHours: Number($('as-audit-hours').value || 8), sampleSize: 30, priorityLimit: 25 } });
    WT.busy(this, false);
    if (!r.success || !r.audit) { WT.toast(WT.err(r, 'Failed to run quick audit.'), 'bad'); return; }
    const a = r.audit, sm = a.summary || {}, ms = a.mismatchSample || {};
    let h = '<div class="wt-grid" style="margin-top:12px;">' +
      '<div class="wt-stat"><div class="l">POS products not synced in ' + E((a.params || {}).staleHours) + 'h</div><div class="v">' + N(sm.staleCount) + '</div><div class="h">' + E(sm.stalePercentage) + '% of ' + N(sm.totalPosProducts) + '</div></div>' +
      '<div class="wt-stat"><div class="l">Sample quantity mismatch</div><div class="v">' + N(ms.mismatchCount) + '</div><div class="h">' + N(ms.sampleChecked) + ' checked, ' + E(ms.mismatchRate) + '% mismatch</div></div>' +
      '<div class="wt-stat"><div class="l">Audit generated</div><div class="v" style="font-size:14px;">' + (a.generatedAt ? WT.dt(a.generatedAt, { year: true, sec: true }) : '-') + '</div><div class="h">Errors during sample: ' + N(ms.errorCount) + '</div></div></div>';
    const pr = a.prioritySkus || [];
    h += '<strong style="display:block; margin-top:12px; font-size:13px;">Priority SKUs for resync</strong><div class="wt-scroll"><table class="wt-tbl"><thead><tr><th>SKU</th><th>Product</th><th>Qty</th><th>Availability</th><th>Stale (hours)</th><th>POS ID</th></tr></thead><tbody>' +
      (pr.length ? pr.map(x => '<tr><td>' + E(x.sku || '-') + '</td><td>' + E(x.name || '-') + '</td><td>' + E(x.quantity != null ? x.quantity : 0) + '</td><td>' + E(x.availability || '-') + '</td><td>' + E(x.staleHours != null ? x.staleHours : '-') + '</td><td>' + E(x.posProductId || '-') + '</td></tr>').join('') : '<tr><td colspan="6" style="text-align:center;">No stale SKUs found.</td></tr>') + '</tbody></table></div>';
    const mr = ms.rows || [];
    h += '<strong style="display:block; margin-top:12px; font-size:13px;">Sample quantity mismatch (website vs POS)</strong><div class="wt-scroll"><table class="wt-tbl"><thead><tr><th>SKU</th><th>Product</th><th>Website qty</th><th>POS qty</th><th>Diff</th><th>POS ID</th></tr></thead><tbody>' +
      (mr.length ? mr.map(x => '<tr><td>' + E(x.sku || '-') + '</td><td>' + E(x.name || '-') + '</td><td>' + E(x.dbQty) + '</td><td>' + E(x.posQty) + '</td><td>' + E(x.diff) + '</td><td>' + E(x.posProductId || '-') + '</td></tr>').join('') : '<tr><td colspan="6" style="text-align:center;">No mismatch found in the current sample.</td></tr>') + '</tbody></table></div>';
    $('as-audit-result').innerHTML = h;
    WT.toast('Quick audit completed.', 'ok');
  });

  // ---------------------------------------------------------- orphans
  async function previewOrphans(btn) {
    WT.busy(btn, true, 'Loading');
    const r = await WT.call('GET', '/_api/auto-sync/orphans', { query: { daysSince: Number($('as-orphan-days').value || 3), limit: 100 } });
    WT.busy(btn, false);
    if (!r.success) { WT.toast(WT.err(r, 'Failed to fetch orphan candidates.'), 'bad'); return; }
    WT.toast('Found ' + r.count + ' orphan candidate(s).', 'ok');
    const rows = r.orphans || [];
    let h = '<div class="wt-grid two" style="margin-top:10px;"><div class="wt-stat"><div class="l">Orphan candidates (not seen in ' + E(r.daysSince) + ' days)</div><div class="v" style="color:#ea580c;">' + N(r.count) + '</div></div>' +
      '<div class="wt-box">Showing the first ' + rows.length + ' of ' + N(r.count) + '. Run the sweep to unpublish all ' + N(r.count) + '.</div></div>';
    if (rows.length) {
      h += '<div class="wt-scroll"><table class="wt-tbl"><thead><tr><th>SKU</th><th>Product</th><th>Artist</th><th>Qty</th><th>Published</th><th>Last seen in POS</th><th>Last synced</th></tr></thead><tbody>' +
        rows.map(x => '<tr><td>' + E(x.sku || '-') + '</td><td>' + E(x.name || '-') + '</td><td>' + E(x.artist || '-') + '</td><td>' + E(x.quantity != null ? x.quantity : 0) + '</td><td>' + (x.published ? '<span class="wt-badge on">Yes</span>' : '<span class="wt-badge off">No</span>') + '</td><td style="font-size:12px;">' + (x.lastSeenInPOS ? WT.dt(x.lastSeenInPOS, { year: true }) : '<span style="color:#ea580c; font-weight:600;">Never stamped</span>') + '</td><td style="font-size:12px;">' + (x.syncedAt ? WT.dt(x.syncedAt, { year: true }) : '-') + '</td></tr>').join('') + '</tbody></table></div>';
    }
    $('as-orphan-result').innerHTML = h;
  }
  $('as-orphan-preview').addEventListener('click', function () { previewOrphans(this); });
  async function sweep(dryRun, btn) {
    WT.busy(btn, true, 'Running');
    const r = await WT.call('POST', '/_api/auto-sync/reconcile-orphans', { body: { daysSince: Number($('as-orphan-days').value || 3), dryRun: dryRun } });
    WT.busy(btn, false);
    if (!r.success) { WT.toast(WT.err(r, 'Orphan sweep failed.'), 'bad'); return; }
    if (dryRun) WT.toast('Dry run: ' + ((r.candidates || []).length) + ' product(s) would be unpublished.', 'ok');
    else { WT.toast('Orphan sweep complete: ' + r.swept + ' product(s) unpublished.', 'ok'); previewOrphans($('as-orphan-preview')); }
  }
  $('as-orphan-dry').addEventListener('click', function () { sweep(true, this); });
  $('as-orphan-run').addEventListener('click', function () { sweep(false, this); });

  // ---------------------------------------------------------- vinyl floor
  let floorCount = null;
  function renderFloor() {
    $('as-floor-count').textContent = floorCount === null ? '' : floorCount === 0 ? 'No vinyl currently under $5.' : floorCount + ' vinyl record(s) under $5 will be raised to $5.';
    if (window.WT_ADMIN) $('as-floor-apply').disabled = floorCount === 0;
  }
  $('as-floor-preview').addEventListener('click', async function () {
    WT.busy(this, true, 'Checking');
    const r = await WT.call('GET', '/product/floor-vinyl-prices/preview');
    WT.busy(this, false);
    if (!r.success) { WT.toast(WT.err(r, 'Failed to preview vinyl floor.'), 'bad'); return; }
    floorCount = r.count || 0; renderFloor();
    WT.toast(r.message || 'Preview ready.', 'ok');
  });
  $('as-floor-apply').addEventListener('click', async function () {
    if (!window.confirm('Raise all vinyl priced under $5 to $5?\n\nVinyl only. CDs and everything else are untouched. In-store POS prices are not affected. Snapshotted so it can be undone.')) return;
    WT.busy(this, true, 'Applying');
    const r = await WT.call('POST', '/product/floor-vinyl-prices/apply', { body: {} });
    WT.busy(this, false);
    if (!r.success) { WT.toast(WT.err(r, 'Failed to apply vinyl floor.'), 'bad'); return; }
    floorCount = 0; renderFloor();
    WT.toast(r.message || 'Raised ' + (r.updated || 0) + ' records to $5.', 'ok');
  });
  $('as-floor-undo').addEventListener('click', async function () {
    if (!window.confirm('Undo the vinyl price floor and restore prior prices?')) return;
    WT.busy(this, true, 'Restoring');
    const r = await WT.call('POST', '/product/floor-vinyl-prices/undo', { body: {} });
    WT.busy(this, false);
    if (!r.success) { WT.toast(WT.err(r, 'Failed to undo vinyl floor.'), 'bad'); return; }
    floorCount = null; renderFloor();
    WT.toast(r.message || 'Restored ' + (r.restored || 0) + ' records.', 'ok');
  });

  // ---------------------------------------------------------- boot
  $('as-refresh').addEventListener('click', async function () { WT.busy(this, true, 'Refreshing'); await fetchStatus(); WT.busy(this, false); });
  fetchStatus(); fetchArtistStats(); fetchRematchJobs();
  setInterval(function () { fetchStatus(); fetchArtistStats(); fetchRematchJobs(); }, 30000);
})();
</script>
@endverbatim
@endsection
