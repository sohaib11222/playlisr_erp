@extends('layouts.app')

@section('title', 'POS Image Refresh')

@section('content')
<div class="ev-wrap ev-wrap-wide">
  @include('website_admin.tools._head', ['active' => 'wa.tools.posImageRefresh'])

  <div class="ev-head">
    <div>
      <h1>POS Image Refresh</h1>
      <p class="sub">Fetch missing website product images from Discogs for the POS music catalog.</p>
    </div>
    <button type="button" class="wt-btn" id="ir-stats-refresh">Refresh Stats</button>
  </div>

  <div id="ir-stats-error" class="wt-box warn wt-hide"></div>
  <div id="ir-overall"></div>

  <div class="wt-card">
    <h3>Missing Images by Category</h3>
    <p class="wt-note" id="ir-table-note" style="margin-top:-6px;"></p>
    <div class="wt-scroll" id="ir-table"><p class="muted"><span class="wt-spin"></span> Loading.</p></div>
  </div>

  <div class="wt-card">
    <h3>Single Product Lookup</h3>
    <p class="wt-note" style="margin-top:-6px;">Search Discogs for a specific product by title and artist.</p>
    <div class="wt-grid two">
      <div class="ev-field"><label>Title (required)</label><input type="text" id="ir-s-title" placeholder="e.g. Things Ain't What They Used To Be"></div>
      <div class="ev-field"><label>Artist (optional)</label><input type="text" id="ir-s-artist" placeholder="e.g. Ella Fitzgerald"></div>
    </div>
    <div class="wt-row">
      <label><input type="checkbox" id="ir-s-dry" checked> Dry run (preview only)</label>
      <button type="button" class="wt-btn primary" id="ir-s-go">Search and Preview</button>
      <button type="button" class="wt-btn" id="ir-s-clear">Clear</button>
    </div>
    <div id="ir-s-result"></div>
  </div>

  <div class="wt-card tint-orange">
    <h3 style="color:#c2410c;">Fix Artist Photos</h3>
    <p class="wt-note" style="margin-top:-6px;">Finds products where several albums by the same artist share the exact same image URL. That usually means an artist portrait was saved instead of the album cover (for example every Lana Del Rey album showing the same photo). Clears those images so the next sync can fetch the correct cover.</p>
    <div class="ev-field" style="max-width:420px;"><label>Artist name (leave blank to scan all artists)</label><input type="text" id="ir-f-artist" placeholder="e.g. Lana Del Rey"></div>
    <div class="wt-row">
      <button type="button" class="wt-btn" id="ir-f-preview" style="border-color:#fdba74; color:#c2410c;">Preview</button>
      <button type="button" class="wt-btn orange wt-hide" id="ir-f-apply">Clear images</button>
      <button type="button" class="wt-btn sm wt-hide" id="ir-f-reset">Reset</button>
    </div>
    <div id="ir-f-result"></div>
  </div>

  <div class="wt-grid two">
    <div class="wt-card">
      <h3>Job Configuration</h3>
      <strong style="font-size:13px;">Categories</strong>
      <div class="wt-cats" id="ir-cats"></div>
      <div class="wt-row">
        <button type="button" class="wt-btn sm ir-lock" data-cats="all">All</button>
        <button type="button" class="wt-btn sm ir-lock" data-cats="music">Music only</button>
        <button type="button" class="wt-btn sm ir-lock" data-cats="none">None</button>
      </div>
      <div id="ir-apparel" class="wt-box wt-hide">
        <strong style="font-size:13px;">Apparel image source</strong>
        <div class="wt-row">
          <label><input type="radio" name="ir-apparel" value="both" class="ir-lock" checked> Rock Off, then EMP</label>
          <label><input type="radio" name="ir-apparel" value="rockoff" class="ir-lock"> Rock Off only</label>
          <label><input type="radio" name="ir-apparel" value="emp" class="ir-lock"> EMP only</label>
        </div>
        <div class="wt-note">Rock Off is the wholesale supplier (primary). EMP is the retail fallback.</div>
      </div>
      <div class="wt-grid" style="margin-top:8px;">
        <div class="ev-field"><label>Batch size (1 to 50)</label><input type="number" min="1" max="50" value="50" id="ir-batch" class="ir-lock"></div>
        <div class="ev-field"><label>Concurrent lookups (1 to 20)</label><input type="number" min="1" max="20" value="8" id="ir-conc" class="ir-lock"></div>
        <div class="ev-field"><label>Skip first N (resume from)</label><input type="number" min="0" value="0" id="ir-skip" class="ir-lock"></div>
        <div class="ev-field"><label>Limit (blank for all)</label><input type="number" min="1" id="ir-limit" class="ir-lock" placeholder="Leave blank for all"></div>
      </div>
      <div class="wt-row"><label><input type="checkbox" id="ir-force" class="ir-lock"> Force refresh (overwrite existing images too)</label></div>
      <div class="wt-row"><label><input type="checkbox" id="ir-dry" class="ir-lock"> Dry run (preview only, nothing is saved)</label></div>
      <div class="ev-field"><label>Artist filter (optional, combine with Force Refresh to fix one artist)</label><input type="text" id="ir-artist" class="ir-lock" placeholder="e.g. Lana Del Rey"></div>
      <div id="ir-artist-tip" class="wt-box warn wt-hide">Tip: turn on Force Refresh when targeting an artist whose products already have a wrong image.</div>
      <div class="wt-row" style="margin-top:12px;">
        <button type="button" class="wt-btn primary" id="ir-start">Start Job</button>
        <button type="button" class="wt-btn danger wt-hide" id="ir-cancel">Cancel Job</button>
        <button type="button" class="wt-btn wt-hide" id="ir-resume" title="Set skip to continue from where the last job stopped">Continue</button>
      </div>
      <div id="ir-dry-note" class="wt-box warn wt-hide">Dry run mode. Images will be found but NOT saved.</div>
    </div>

    <div class="wt-card">
      <h3 style="justify-content:space-between;"><span>Job Status</span><span id="ir-job-badge"></span></h3>
      <div id="ir-job"></div>
    </div>
  </div>

  <div class="wt-card wt-hide" id="ir-log-card">
    <h3>Live Log</h3>
    <div class="wt-log" id="ir-log"></div>
  </div>

  <div class="wt-card tint-purple">
    <h3 style="color:#7e22ce;">Upgrade Artist Photos to Cover Art</h3>
    <p class="wt-note" style="margin-top:-6px;">Scans products that have artist portrait photos instead of album cover art, found two ways: (1) explicitly tagged <code>imageSource: discogs-artist</code>, or (2) the same artist shares the exact same image URL across two or more albums. Tries to replace each with the proper album cover from Discogs, iTunes, MusicBrainz, or Deezer. If no cover art is found the product is <strong>left untouched</strong> and keeps its artist photo.</p>
    <div id="ir-u-stats"></div>
    <div class="wt-grid two">
      <div>
        <strong style="font-size:13px;">Categories</strong>
        <div class="wt-cats" id="ir-u-cats"></div>
        <div class="wt-row">
          <button type="button" class="wt-btn sm ir-u-lock" data-ucats="all">All</button>
          <button type="button" class="wt-btn sm ir-u-lock" data-ucats="none">None</button>
        </div>
        <div class="ev-field"><label>Artist filter (optional)</label><input type="text" id="ir-u-artist" class="ir-u-lock" placeholder="e.g. Loo Kye"></div>
        <div class="ev-field" style="margin-top:8px;"><label>Concurrent lookups</label><input type="number" min="1" max="20" value="6" id="ir-u-conc" class="ir-u-lock" style="width:100px;"></div>
        <div class="ev-field" style="margin-top:8px;"><label>Skip first N (resume after restart)</label>
          <div class="wt-row" style="margin:0;"><input type="number" min="0" value="0" id="ir-u-skip" class="wt-input n ir-u-lock"><button type="button" class="wt-btn sm wt-hide" id="ir-u-resume"></button></div>
        </div>
        <div id="ir-u-skip-note" class="wt-box info wt-hide"></div>
        <div class="wt-row"><label><input type="checkbox" id="ir-u-dry" class="ir-u-lock" checked> Dry run (preview only, nothing is saved)</label></div>
        <div id="ir-u-dry-note" class="wt-box warn">Dry run. Images are found but NOT saved.</div>
        <div class="wt-row">
          <button type="button" class="wt-btn purple" id="ir-u-start">Start Upgrade</button>
          <button type="button" class="wt-btn danger wt-hide" id="ir-u-cancel">Cancel</button>
        </div>
      </div>
      <div>
        <div style="display:flex; justify-content:space-between; align-items:center;"><strong style="font-size:13px;">Job Status</strong><span id="ir-u-badge"></span></div>
        <div id="ir-u-job"></div>
      </div>
    </div>
    <div class="wt-hide" id="ir-u-log-wrap"><div class="wt-note" style="font-weight:700; text-transform:uppercase;">Live log</div><div class="wt-log" id="ir-u-log" style="height:220px;"></div></div>
  </div>
</div>

@verbatim
<script>
(function () {
  const N = WT.num, E = WT.esc, $ = WT.$, pct = WT.pct;
  // Same list and order as the website page (published count, high to low).
  const MUSIC = ['Vinyl Records', 'Used Vinyl', 'CDs', 'Used CD', 'Cassettes', 'DVDs & Blu-ray Discs', 'Books & Magazines', 'VHS Tapes', 'Trading Cards', '7", 45 RPM', 'Books', 'DVD/Blu Ray', 'Apparel & Merch', 'CD (Sealed)', 'Movies', 'Magazines', 'Apparel', 'VHS', 'Audio Gear', 'Toys & Collectibles', 'Cassettes - Sealed', 'Toys', 'Pictures & Posters', 'Laser Discs', 'Acessories & Novelties', 'Decorative Collectibles', 'Laser Disc', 'Video Games', 'Food'];
  const ALL = MUSIC.slice();
  const APPAREL = 'Apparel & Merch';
  let selected = ALL.slice(), uSelected = MUSIC.slice(), job = null, uJob = null, fixResult = null, single = null;

  function renderCats() {
    $('ir-cats').innerHTML = ALL.map((c, i) => '<label><input type="checkbox" class="ir-cat ir-lock" data-i="' + i + '"' + (selected.includes(c) ? ' checked' : '') + '> ' + E(c) + '</label>').join('');
    $('ir-u-cats').innerHTML = MUSIC.map((c, i) => '<label><input type="checkbox" class="ir-u-cat ir-u-lock" data-i="' + i + '"' + (uSelected.includes(c) ? ' checked' : '') + '> ' + E(c) + '</label>').join('');
    $('ir-apparel').classList.toggle('wt-hide', !selected.includes(APPAREL));
    $('ir-start').disabled = !selected.length;
    $('ir-u-start').disabled = !uSelected.length;
    lockForms();
  }
  document.addEventListener('change', function (e) {
    const t = e.target;
    if (t.classList.contains('ir-cat')) { const c = ALL[t.dataset.i]; selected = t.checked ? selected.concat([c]) : selected.filter(x => x !== c); renderCats(); }
    if (t.classList.contains('ir-u-cat')) { const c = MUSIC[t.dataset.i]; uSelected = t.checked ? uSelected.concat([c]) : uSelected.filter(x => x !== c); renderCats(); }
  });
  document.querySelectorAll('[data-cats]').forEach(b => b.addEventListener('click', function () { selected = this.dataset.cats === 'none' ? [] : (this.dataset.cats === 'music' ? MUSIC : ALL).slice(); renderCats(); }));
  document.querySelectorAll('[data-ucats]').forEach(b => b.addEventListener('click', function () { uSelected = this.dataset.ucats === 'none' ? [] : MUSIC.slice(); renderCats(); }));
  function lockForms() {
    const r1 = !!(job && job.running), r2 = !!(uJob && uJob.running);
    document.querySelectorAll('.ir-lock').forEach(el => { el.disabled = r1; });
    document.querySelectorAll('.ir-u-lock').forEach(el => { el.disabled = r2; });
    if (!r1) $('ir-start').disabled = !selected.length;
    if (!r2) $('ir-u-start').disabled = !uSelected.length;
  }
  $('ir-dry').addEventListener('change', () => $('ir-dry-note').classList.toggle('wt-hide', !$('ir-dry').checked));
  $('ir-u-dry').addEventListener('change', () => $('ir-u-dry-note').classList.toggle('wt-hide', !$('ir-u-dry').checked));
  const tip = () => $('ir-artist-tip').classList.toggle('wt-hide', !($('ir-artist').value.trim() && !$('ir-force').checked));
  $('ir-artist').addEventListener('input', tip); $('ir-force').addEventListener('change', tip);
  $('ir-u-skip').addEventListener('input', function () {
    const n = Number(this.value) || 0, box = $('ir-u-skip-note');
    box.textContent = 'Will skip the first ' + n.toLocaleString() + ' candidates and resume from item ' + (n + 1).toLocaleString() + '.';
    box.classList.toggle('wt-hide', n <= 0);
  });

  // ---------------------------------------------------------- stats
  async function fetchStats(btn) {
    WT.busy(btn, true, 'Loading');
    const r = await WT.call('GET', '/_api/pos-image-refresh/stats', { query: selected.length ? { categories: selected } : null });
    WT.busy(btn, false);
    const err = $('ir-stats-error');
    if (!r.success) { err.textContent = WT.err(r, 'Image stats are temporarily unavailable. Please retry.'); err.classList.remove('wt-hide'); if (!$('ir-table').dataset.loaded) $('ir-table').innerHTML = ''; return; }
    err.classList.add('wt-hide');
    renderStats(r);
  }
  function renderStats(s) {
    const o = s.overall, g = s.grand;
    if (o) {
      const has = o.hasImage != null ? o.hasImage : Math.max(0, o.published - (o.missing || 0));
      let h = '<div class="wt-card tint-blue"><div class="wt-row" style="margin:0;"><strong>POS overall (syncSource: ultimatepos)</strong><span>Total: <strong>' + N(o.total) + '</strong></span><span class="green-t">Published: <strong>' + N(o.published) + '</strong></span><span class="amber-t">Unpublished: <strong>' + N(o.unpublished) + '</strong></span><span class="muted" style="font-size:12px;">(' + pct(o.published, o.total) + '% published)</span></div>';
      if (o.missing != null || o.hasImage != null) {
        h += '<div class="wt-row" style="border-top:1px solid #dbeafe; padding-top:8px;"><span>Image coverage (of ' + N(o.published) + ' published):</span><span class="green-t">With image: <strong>' + N(has) + '</strong> (' + pct(has, o.published) + '%)</span><span class="red-t">Missing image: <strong>' + N(o.missing || 0) + '</strong> (' + pct(o.missing || 0, o.published) + '%)</span>' + ((o.missing || 0) > 0 ? '<span style="width:120px;">' + WT.bar(pct(o.hasImage || 0, o.published)) + '</span>' : '') + '</div>';
      }
      h += '<div class="wt-note">' + (g && o.published === g.total
        ? '<span class="green-t">The table below covers all 29 POS categories. Table total (' + N(g.total) + ') matches POS overall (' + N(o.published) + ').</span>'
        : 'The table below is a filtered view (' + (g ? N(g.total) : '-') + ' of ' + N(o.published) + ', ' + pct(g ? g.total : 0, o.published) + '% of POS). Click All under Categories to match the POS total.') + '</div></div>';
      $('ir-overall').innerHTML = h;
      $('ir-table-note').textContent = 'Rows are filtered by the selected categories (' + (g ? N(g.total) : '-') + ' published in view), not the POS total of ' + N(o.published) + '. The totals above are the ground truth.';
    }
    let t = '<table class="wt-tbl"><thead><tr><th>Category</th><th class="r">Total</th><th class="r">Has image</th><th class="r">Missing</th><th class="r">Coverage</th></tr></thead><tbody>';
    (s.rows || []).forEach(row => {
      t += '<tr><td><strong>' + E(row.category) + '</strong></td><td class="r">' + N(row.total) + '</td><td class="r green-t">' + N(row.hasImage) + '</td><td class="r red-t"><strong>' + N(row.missing) + '</strong></td><td class="r"><div style="display:flex; gap:6px; align-items:center; justify-content:flex-end;"><span style="width:80px;">' + WT.bar(pct(row.hasImage, row.total)) + '</span><span class="muted" style="font-size:12px; width:36px;">' + pct(row.hasImage, row.total) + '%</span></div></td></tr>';
    });
    if (g) {
      const has = g.total - g.missing;
      t += '<tr class="total"><td>Total</td><td class="r">' + N(g.total) + '</td><td class="r green-t">' + N(has) + '</td><td class="r red-t">' + N(g.missing) + '</td><td class="r">' + pct(has, g.total) + '%</td></tr>';
    }
    $('ir-table').innerHTML = t + '</tbody></table>';
    $('ir-table').dataset.loaded = '1';
  }
  $('ir-stats-refresh').addEventListener('click', function () { fetchStats(this); });

  // ---------------------------------------------------------- job status
  const statusBadge = j => !j ? '' : j.running ? '<span class="wt-badge run">Running</span>' : j.cancelled ? '<span class="wt-badge warn">Cancelled</span>' : j.finishedAt ? '<span class="wt-badge on">Finished</span>' : '<span class="wt-badge">Idle</span>';
  const logRules = [[l => l.includes('found') && !l.includes('not found'), 'g'], [l => l.includes('not found'), 'y'], [l => l.includes('error') || l.includes('failed') || l.includes('fatal'), 'r'], [l => l.includes('skip'), 'd'], [l => l.includes('done') || l.includes('job started') || l.includes('total matching'), 'c']];
  const uLogRules = [[l => l.includes('upgraded'), 'g'], [l => l.includes('kept'), 'y'], [l => l.includes('error') || l.includes('fatal'), 'r'], [l => l.includes('done') || l.includes('started'), 'c']];
  function logHtml(lines, rules) { return (lines || []).map(l => '<div class="' + WT.logClass(l, rules) + '">' + E(l) + '</div>').join(''); }
  function statBoxes(items) { return '<div class="wt-grid" style="grid-template-columns:1fr 1fr;">' + items.map(i => '<div class="wt-stat c"><div class="v ' + i[2] + '">' + N(i[1] || 0) + '</div><div class="h">' + i[0] + '</div></div>').join('') + '</div>'; }

  async function fetchStatus() {
    const r = await WT.call('GET', '/_api/pos-image-refresh/status');
    if (!r.success) return;
    job = r.job || null;
    $('ir-job-badge').innerHTML = statusBadge(job);
    const running = !!(job && job.running);
    $('ir-start').classList.toggle('wt-hide', running);
    $('ir-cancel').classList.toggle('wt-hide', !running);
    $('ir-resume').classList.toggle('wt-hide', running || !(job && job.finishedAt));
    lockForms();
    if (!job || (!job.running && !job.finishedAt)) { $('ir-job').innerHTML = '<p class="muted">No job has run yet.</p>'; }
    else {
      const p = job.progress || {}, pp = pct(p.processed, p.total);
      $('ir-job').innerHTML = '<div style="display:flex; justify-content:space-between; font-size:12px; color:#6b7280;"><span>' + N(p.processed) + ' / ' + N(p.total) + ' processed</span><span>' + pp + '%</span></div>' + WT.bar(pp) +
        '<div style="margin-top:10px;">' + statBoxes([['Found', p.found, 'green'], ['Not found', p.notFound, 'amber'], ['Written to the website', p.written, 'blue'], ['Errors', p.errors, 'red']]) + '</div>' +
        '<div class="wt-note">' + (job.startedAt ? 'Started: ' + new Date(job.startedAt).toLocaleString() + '<br>' : '') + (job.finishedAt ? 'Finished: ' + new Date(job.finishedAt).toLocaleString() : '') + (job.options && job.options.dryRun ? ' <span class="wt-badge warn">Dry run</span>' : '') + '</div>';
    }
    const logs = (job && job.logs) || [];
    $('ir-log-card').classList.toggle('wt-hide', !logs.length);
    if (logs.length) { const box = $('ir-log'); box.innerHTML = logHtml(logs, logRules); }
  }
  async function fetchUpgradeStatus() {
    const r = await WT.call('GET', '/_api/pos-image-refresh/upgrade-status');
    if (!r.success) return;
    uJob = r.job || null;
    $('ir-u-badge').innerHTML = statusBadge(uJob);
    const running = !!(uJob && uJob.running);
    $('ir-u-start').classList.toggle('wt-hide', running);
    $('ir-u-cancel').classList.toggle('wt-hide', !running);
    const rb = $('ir-u-resume');
    if (uJob && uJob.lastProcessed > 0 && !running) { rb.textContent = 'Resume from ' + Number(uJob.lastProcessed).toLocaleString(); rb.classList.remove('wt-hide'); } else rb.classList.add('wt-hide');
    lockForms();
    if (!uJob || (!uJob.running && !uJob.finishedAt)) { $('ir-u-job').innerHTML = '<p class="muted">No upgrade job has run yet.</p>'; }
    else {
      const p = uJob.progress || {}, pp = pct(p.processed, p.total);
      $('ir-u-job').innerHTML = '<div style="display:flex; justify-content:space-between; font-size:12px; color:#6b7280; margin-top:8px;"><span>' + N(p.processed) + ' / ' + N(p.total) + ' processed</span><span>' + pp + '%</span></div>' + WT.bar(pp) +
        '<div style="margin-top:10px;">' + statBoxes([['Upgraded', p.upgraded, 'green'], ['Kept artist photo', p.kept, 'amber'], ['Written to the website', p.written, 'blue'], ['Errors', p.errors, 'red']]) + '</div>' +
        '<div class="wt-note">' + (uJob.startedAt ? 'Started: ' + new Date(uJob.startedAt).toLocaleString() + '<br>' : '') + (uJob.finishedAt ? 'Finished: ' + new Date(uJob.finishedAt).toLocaleString() : '') + '</div>';
    }
    const logs = (uJob && uJob.logs) || [];
    $('ir-u-log-wrap').classList.toggle('wt-hide', !logs.length);
    if (logs.length) $('ir-u-log').innerHTML = logHtml(logs, uLogRules);
  }
  async function fetchUpgradeStats(btn) {
    WT.busy(btn, true, 'Loading');
    const r = await WT.call('GET', '/_api/pos-image-refresh/upgrade-stats');
    WT.busy(btn, false);
    if (!r.success) return;
    let h = '<div class="wt-row" style="align-items:flex-start;"><div class="wt-stat c" style="min-width:150px; border-color:#e9d5ff; background:#faf5ff;"><div class="v purple">' + N(r.total) + '</div><div class="h"><strong>Total to upgrade</strong><br><span style="color:#ea580c;">' + N(r.totalTypeA || 0) + '</span> artist-tagged + <span class="amber-t">' + N(r.totalTypeB || 0) + '</span> duplicate-image</div></div>';
    (r.rows || []).filter(x => x.count > 0).forEach(x => { h += '<div class="wt-stat c" style="padding:6px 10px;"><strong>' + N(x.count) + '</strong><div class="h">' + E(x.category) + '</div>' + (x.typeB > 0 ? '<div class="h amber-t">' + N(x.typeB) + ' dup</div>' : '') + '</div>'; });
    if (r.total === 0) h += '<span class="green-t" style="font-size:13px;">No artist-photo products found. The catalog looks clean.</span>';
    h += '<button type="button" class="wt-btn sm" id="ir-u-stats-refresh">Refresh</button></div>';
    if (r.totalTypeB > 0) h += '<div class="wt-box warn"><strong>' + N(r.totalTypeB) + '</strong> products were found by the duplicate-image pattern (the same artist shares one cover URL across several albums, likely an untagged artist portrait). These will be upgraded too.</div>';
    $('ir-u-stats').innerHTML = h;
    $('ir-u-stats-refresh').addEventListener('click', function () { fetchUpgradeStats(this); });
  }

  // ---------------------------------------------------------- actions
  $('ir-start').addEventListener('click', async function () {
    if (!selected.length) { WT.toast('Select at least one category.', 'warn'); return; }
    const limit = $('ir-limit').value;
    const ap = document.querySelector('input[name="ir-apparel"]:checked');
    WT.busy(this, true, 'Starting');
    const r = await WT.call('POST', '/_api/pos-image-refresh/start', { body: {
      categories: selected, forceRefresh: $('ir-force').checked, dryRun: $('ir-dry').checked,
      batchSize: Number($('ir-batch').value) || 50, concurrent: Number($('ir-conc').value) || 8,
      skip: Number($('ir-skip').value) || 0, limit: limit ? Number(limit) : null,
      apparelSource: selected.includes(APPAREL) ? (ap ? ap.value : 'both') : undefined,
      artistFilter: $('ir-artist').value.trim() || undefined
    } });
    WT.busy(this, false);
    if (r.success) { WT.toast('Job started.', 'ok'); fetchStatus(); } else WT.toast(WT.err(r, 'Failed to start job.'), 'bad');
  });
  $('ir-cancel').addEventListener('click', async function () {
    WT.busy(this, true, 'Cancelling');
    const r = await WT.call('POST', '/_api/pos-image-refresh/cancel');
    WT.busy(this, false);
    WT.toast(r.success ? 'Cancellation requested.' : WT.err(r, 'Failed to cancel.'), r.success ? 'ok' : 'bad');
  });
  $('ir-resume').addEventListener('click', function () {
    if (!job) return;
    const processed = job.lastProcessed || (job.progress && job.progress.processed) || 0, prev = (job.options && job.options.skip) || 0;
    $('ir-skip').value = String(prev + processed);
    WT.toast('Skip set to ' + (prev + processed) + '. Check the settings and press Start Job to resume.');
  });

  function renderSingle() {
    const s = single, box = $('ir-s-result');
    if (!s) { box.innerHTML = ''; return; }
    if (!s.found) { box.innerHTML = '<div class="wt-box warn">No image found on Discogs for this search.</div>'; return; }
    const n = s.updatedCount != null ? s.updatedCount : 1;
    let h = '<div class="wt-box"><div class="green-t" style="font-weight:700;">' + (s.saved ? 'Saved. ' + n + ' document' + (n !== 1 ? 's' : '') + ' updated.' : 'Image found. Preview below.') + '</div><div class="wt-note" style="margin:2px 0;">Source: ' + E(s.result && s.result.source) + '</div>';
    h += '<div class="wt-row" style="align-items:flex-start; gap:24px;"><div><div class="wt-note" style="font-weight:700; text-transform:uppercase;">Current image</div>' +
      (s.product && s.product.image ? '<img src="' + E(s.product.image) + '" alt="current" style="width:160px; height:160px; object-fit:contain; border:1px solid #e5e7eb; border-radius:8px; background:#f3f4f6;">' : '<div style="width:160px; height:160px; border:1px solid #e5e7eb; border-radius:8px; background:#f3f4f6; display:flex; align-items:center; justify-content:center;" class="muted">No image</div>') +
      '</div><div><div class="wt-note green-t" style="font-weight:700; text-transform:uppercase;">New image (Discogs)</div><img src="' + E(s.result && s.result.image) + '" alt="new" referrerpolicy="no-referrer" style="width:160px; height:160px; object-fit:contain; border:1px solid #bbf7d0; border-radius:8px; background:#f3f4f6;"></div></div>';
    if (s.product) {
      h += '<div class="wt-note" style="border-top:1px solid #f0f0f0; padding-top:8px;"><strong>Product:</strong> ' + E(s.product.name) + (s.product.artist ? '<br><strong>Artist:</strong> ' + E(s.product.artist) : '') + '<br><strong>SKU:</strong> ' + E(s.product.sku) + (s.result && s.result.releaseId ? '<br><strong>Discogs release ID:</strong> ' + E(s.result.releaseId) : '') + '</div>';
    } else {
      h += '<div class="wt-box warn">Product not found on the website, so the image cannot be saved.</div>';
    }
    if ($('ir-s-dry').checked && !s.saved && s.product) h += '<button type="button" class="wt-btn green" id="ir-s-save">Save to Website</button>';
    box.innerHTML = h + '</div>';
    const save = $('ir-s-save');
    if (save) save.addEventListener('click', async function () {
      WT.busy(this, true, 'Saving');
      const r = await WT.call('POST', '/_api/pos-image-refresh/single', { body: { title: $('ir-s-title').value.trim(), artist: $('ir-s-artist').value.trim() || undefined, dryRun: false } });
      WT.busy(this, false);
      if (r.success === false && !('found' in r)) { WT.toast(WT.err(r, 'Save failed.'), 'bad'); return; }
      single = r; renderSingle();
      if (r.saved) { const k = r.updatedCount != null ? r.updatedCount : 1; WT.toast('Image saved. ' + k + ' document' + (k !== 1 ? 's' : '') + ' updated.', 'ok'); fetchStats(); }
      else WT.toast('Save failed. Product not found on the website.', 'bad');
    });
  }
  async function singleLookup() {
    const title = $('ir-s-title').value.trim();
    if (!title) { WT.toast('Enter a title to search.', 'warn'); return; }
    const btn = $('ir-s-go');
    WT.busy(btn, true, 'Searching');
    single = null; renderSingle();
    const r = await WT.call('POST', '/_api/pos-image-refresh/single', { body: { title: title, artist: $('ir-s-artist').value.trim() || undefined, dryRun: $('ir-s-dry').checked } });
    WT.busy(btn, false);
    if (r.success === false && !('found' in r)) { WT.toast(WT.err(r, 'Lookup failed.'), 'bad'); return; }
    single = r; renderSingle();
    if (!r.found) WT.toast('No image found on Discogs for this product.', 'warn');
    else if (r.saved) { const k = r.updatedCount != null ? r.updatedCount : 1; WT.toast('Image saved. ' + k + ' document' + (k !== 1 ? 's' : '') + ' updated.', 'ok'); fetchStats(); }
    else WT.toast('Image found. Preview ready.', 'ok');
  }
  $('ir-s-go').addEventListener('click', singleLookup);
  ['ir-s-title', 'ir-s-artist'].forEach(id => $(id).addEventListener('keydown', e => { if (e.key === 'Enter') singleLookup(); }));
  $('ir-s-clear').addEventListener('click', () => { $('ir-s-title').value = ''; $('ir-s-artist').value = ''; single = null; renderSingle(); });

  function renderFix() {
    const f = fixResult;
    $('ir-f-apply').classList.toggle('wt-hide', !(f && f.found > 0));
    $('ir-f-reset').classList.toggle('wt-hide', !f);
    if (f && f.found > 0) $('ir-f-apply').textContent = 'Clear ' + f.found + ' Images';
    if (!f) { $('ir-f-result').innerHTML = ''; return; }
    let h = '<div class="wt-box"><div style="font-weight:600;" class="' + (f.found === 0 ? 'green-t' : '') + '">' + E(f.message) + '</div>';
    if (f.buckets && f.buckets.length) {
      h += '<div style="max-height:260px; overflow-y:auto; margin-top:6px;">' + f.buckets.map(b => '<div class="wt-box" style="font-size:12px;"><strong>Artist:</strong> ' + E(b.artist || '(none)') + ', <strong>' + N(b.count) + ' products</strong> with the same image<div class="muted" style="word-break:break-all;">Image: ' + E(b.image) + '</div><div class="muted">Albums: ' + E((b.sampleNames || []).join(' | ')) + (b.count > 5 ? ' and ' + (b.count - 5) + ' more' : '') + '</div></div>').join('') + '</div>';
    }
    if (f.applied && f.cleared > 0) h += '<div class="wt-box info">Images cleared. Now run the image refresh job (with Force Refresh and Artist Filter set to the artist name) to fetch the correct cover art.</div>';
    $('ir-f-result').innerHTML = h + '</div>';
  }
  async function fixArtistPhotos(apply, btn) {
    WT.busy(btn, true, apply ? 'Clearing' : 'Scanning');
    fixResult = null;
    const r = await WT.call('POST', '/_api/pos-image-refresh/fix-artist-photos', { body: { artist: $('ir-f-artist').value.trim() || undefined, dryRun: !apply } });
    WT.busy(btn, false);
    if (!r.success && !('found' in r)) { WT.toast(WT.err(r, 'Request failed.'), 'bad'); renderFix(); return; }
    fixResult = Object.assign({}, r, { applied: apply });
    renderFix();
    if (!apply) WT.toast(r.found === 0 ? 'No artist-photo duplicates found.' : 'Found ' + r.found + ' products with shared artist photos. Review below, then click Clear.', r.found === 0 ? 'ok' : '');
    else if (r.cleared > 0) { WT.toast('Cleared ' + r.cleared + ' products. Run the image refresh to fetch covers.', 'ok'); fetchStats(); }
    else WT.toast('Nothing was cleared.', 'warn');
  }
  $('ir-f-preview').addEventListener('click', function () { fixArtistPhotos(false, this); });
  $('ir-f-apply').addEventListener('click', function () { fixArtistPhotos(true, this); });
  $('ir-f-reset').addEventListener('click', () => { fixResult = null; renderFix(); });

  $('ir-u-start').addEventListener('click', async function () {
    if (!uSelected.length) { WT.toast('Select at least one category.', 'warn'); return; }
    WT.busy(this, true, 'Starting');
    const r = await WT.call('POST', '/_api/pos-image-refresh/upgrade-start', { body: {
      categories: uSelected, artistFilter: $('ir-u-artist').value.trim() || undefined, dryRun: $('ir-u-dry').checked,
      concurrent: Number($('ir-u-conc').value) || 6, skip: Math.max(0, Number($('ir-u-skip').value) || 0)
    } });
    WT.busy(this, false);
    if (r.success) { WT.toast('Upgrade job started.', 'ok'); fetchUpgradeStatus(); } else WT.toast(WT.err(r, 'Failed to start upgrade job.'), 'bad');
  });
  $('ir-u-cancel').addEventListener('click', async function () {
    WT.busy(this, true, 'Cancelling');
    const r = await WT.call('POST', '/_api/pos-image-refresh/upgrade-cancel');
    WT.busy(this, false);
    WT.toast(r.success ? 'Cancellation requested.' : WT.err(r, 'Failed to cancel.'), r.success ? 'ok' : 'bad');
  });
  $('ir-u-resume').addEventListener('click', function () { if (uJob) { $('ir-u-skip').value = String(uJob.lastProcessed); $('ir-u-skip').dispatchEvent(new Event('input')); } });

  // Stats load once; use Refresh for a new snapshot (same as the website).
  renderCats();
  fetchStats(); fetchStatus(); fetchUpgradeStats(); fetchUpgradeStatus();
  setInterval(fetchStatus, 3000);
  setInterval(fetchUpgradeStatus, 3000);
})();
</script>
@endverbatim
@endsection
