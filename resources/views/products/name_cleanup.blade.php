@extends('layouts.app')
@section('title', 'Product Name Cleanup')

@section('content')
<script>document.body.classList.add('mgn-v2');</script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">

<style>
body.mgn-v2 { background: #FAF6EE; font-family: "Inter Tight", system-ui, sans-serif; -webkit-font-smoothing: antialiased; color: #1F1B16; }
body.mgn-v2 .content-wrapper { background: #FAF6EE !important; }
body.mgn-v2 .content-header { background: transparent; padding: 28px 16px 8px; }
body.mgn-v2 .content-header h1 { font-size: 26px; font-weight: 700; letter-spacing: -0.2px; color: #1F1B16; margin: 0 0 6px; }
body.mgn-v2 .content { padding: 0 16px 60px; }
.mgn-wrap { max-width: 900px; }
.mgn-card { background: #fff; border: 1px solid #ECE3D2; border-radius: 16px; padding: 22px; box-shadow: 0 1px 2px rgba(31,27,22,.04); margin-bottom: 18px; }
.mgn-card h2 { font-size: 17px; font-weight: 700; margin: 0 0 4px; }
.mgn-card p.sub { color: #8E8273; font-size: 13.5px; margin: 0 0 18px; }
.mgn-btn { height: 44px; padding: 0 22px; border-radius: 10px; border: 0; font-family: inherit; font-size: 14.5px; font-weight: 700; cursor: pointer; }
.mgn-btn-primary { background: #1F1B16; color: #FFF2B3; }
.mgn-btn-primary:disabled { opacity: .5; cursor: not-allowed; }
.mgn-btn-ghost { background: #F3ECDD; color: #1F1B16; }
.mgn-actions { margin-top: 8px; display: flex; gap: 10px; align-items: center; }
.mgn-note { font-size: 13px; color: #8E8273; margin-top: 14px; line-height: 1.5; }
.mgn-msg { display: none; padding: 12px 14px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 16px; }
.mgn-msg.ok { display: block; background: #E8F5E9; color: #1B5E20; }
.mgn-msg.err { display: block; background: #FDECEA; color: #B71C1C; }
.mgn-table { width: 100%; border-collapse: collapse; margin-top: 6px; }
.mgn-table th, .mgn-table td { text-align: left; padding: 9px 12px; border-bottom: 1px solid #F0E9DA; font-size: 13.5px; vertical-align: top; }
.mgn-table th { color: #8E8273; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: .4px; }
.mgn-old { color: #B71C1C; }
.mgn-new { color: #1B5E20; font-weight: 600; }
.mgn-summary b { font-size: 17px; }
.ar-table thead th { position: sticky; top: 0; background: #fff; z-index: 1; }
.ar-table td, .ar-table th { padding: 5px 10px; font-size: 12.5px; line-height: 1.25; }
.ar-sort { cursor: pointer; user-select: none; white-space: nowrap; }
.ar-sort:hover { color: #1F1B16; }
.ar-arrow { font-size: 10px; color: #C9A227; }
.ar-table tbody tr.ar-off { opacity: .45; }
.ar-alpha-btn { min-width: 30px; height: 30px; padding: 0 7px; border: 1px solid #ECE3D2; background: #fff; border-radius: 7px; font-family: inherit; font-size: 13px; font-weight: 600; color: #1F1B16; cursor: pointer; }
.ar-alpha-btn:hover { background: #F3ECDD; }
.ar-alpha-btn.on { background: #1F1B16; color: #FFF2B3; border-color: #1F1B16; }
.ar-table td .ar-cb { cursor: pointer; }
</style>

<section class="content-header"><h1>Product Name Cleanup<br><small>Normalize names to "ARTIST - TITLE". Read-only scan first; fully undoable.</small></h1></section>

<section class="content">
<div class="mgn-wrap">
    <div id="mgnMsg" class="mgn-msg"></div>

    <div class="mgn-card">
        <h2>Standard: <span style="color:#8E8273">ARTIST - TITLE</span></h2>
        <p class="sub"><b style="color:#B71C1C">Heads up:</b> this uses the Artist field, which is unreliable on a lot of products (it sometimes holds the title), so it can flip good names. Prefer "Rebuild from Discogs" below. Scanning changes nothing either way.</p>
        <div class="mgn-actions">
            <button class="mgn-btn mgn-btn-ghost" id="mgnScanBtn" type="button">Scan names</button>
        </div>
        <div id="mgnResult" style="display:none;margin-top:18px;">
            <div class="mgn-note mgn-summary" id="mgnSummary" style="margin-top:0;color:#1F1B16;"></div>
            <div style="margin-top:10px;max-height:380px;overflow:auto;border:1px solid #F0E9DA;border-radius:10px;">
                <table class="mgn-table">
                    <thead><tr><th>Current name</th><th>Proposed</th></tr></thead>
                    <tbody id="mgnRows"></tbody>
                </table>
            </div>
            <div class="mgn-actions" style="margin-top:16px;">
                <button class="mgn-btn mgn-btn-primary" id="mgnApplyBtn" type="button">Rename all off-standard</button>
                <span class="mgn-note" id="mgnProgress" style="margin-top:0"></span>
            </div>
            <div class="mgn-note">Undo any batch at <a href="/admin/admin-action-history">Admin Action History</a>.</div>
        </div>
    </div>

    <div class="mgn-card" style="border-color:#2E7D32;">
        <h2>Backfill missing Artist <span style="color:#8E8273;font-weight:400">(music formats only)</span></h2>
        <p class="sub">Music products (vinyl, CD, cassette, 45s) with a blank or "N/A" artist, where the artist is still sitting inside the name. Parses it from "Title / Artist" or "Artist - Title" and fills the Artist field. Only confident parses are filled; anything unclear is flagged for you to do by hand. Scanning changes nothing. Fully undoable.</p>
        <div class="mgn-actions">
            <button class="mgn-btn mgn-btn-ghost" id="arScanBtn" type="button">Scan missing artists</button>
            <input type="text" id="arFilter" placeholder="Filter by artist (e.g. A, or Beat…)" autocomplete="off"
                   style="height:44px;padding:0 14px;border:1px solid #ECE3D2;border-radius:10px;font-family:inherit;font-size:14px;width:280px;background:#fff;">
        </div>
        <div style="margin-top:14px;padding:14px;border:1px solid #CDE3CD;background:#F3F9F3;border-radius:10px;">
            <div style="font-weight:600;color:#1B5E20;">Best option: fill blank artists from Discogs (accurate, no guessing)</div>
            <p class="sub" style="margin:6px 0 10px;">For every blank / "N/A" artist music product that has a Discogs release id, this writes the <b>true artist straight from Discogs</b> into the Artist field — no parsing, no guessing. <b>Sealed vinyl first.</b> Rate-limited (~55/min), runs in batches; leave the tab open. Fully undoable. Products with no release id won't be touched — use the scan below for those.</p>
            <div class="mgn-actions" style="margin-top:0;">
                <button class="mgn-btn mgn-btn-ghost" id="dgArtistScanBtn" type="button">Check + preview</button>
                <span class="mgn-note" id="dgArtistScanNote" style="margin-top:0"></span>
            </div>
            <div id="dgArtistPreview" style="display:none;margin-top:14px;">
                <div class="mgn-note mgn-summary" id="dgArtistSummary" style="margin-top:0;color:#1F1B16;"></div>
                <div style="margin-top:10px;max-height:340px;overflow:auto;border:1px solid #E1EFE1;border-radius:10px;background:#fff;">
                    <table class="mgn-table">
                        <thead><tr><th>Current name</th><th>Artist it will write (from Discogs)</th></tr></thead>
                        <tbody id="dgArtistRows"></tbody>
                    </table>
                </div>
                <div class="mgn-actions" style="margin-top:14px;">
                    <button class="mgn-btn mgn-btn-primary" id="dgArtistBtn" type="button">Looks right — fill them all</button>
                    <span class="mgn-note" id="dgArtistProgress" style="margin-top:0"></span>
                </div>
            </div>
        </div>
        <div id="arAlpha" style="display:none;margin-top:12px;flex-wrap:wrap;gap:4px;"></div>
        <div id="arResult" style="display:none;margin-top:18px;">
            <div class="mgn-note mgn-summary" id="arSummary" style="margin-top:0;color:#1F1B16;"></div>
            <div class="mgn-note" id="arSelInfo" style="margin-top:6px;"></div>
            <div style="margin-top:10px;max-height:75vh;overflow:auto;border:1px solid #F0E9DA;border-radius:10px;">
                <table class="mgn-table ar-table">
                    <thead><tr>
                        <th style="width:34px;text-align:center"><input type="checkbox" id="arCheckAll" checked title="Select all"></th>
                        <th class="ar-sort" data-key="name">Current name <span class="ar-arrow"></span></th>
                        <th class="ar-sort" data-key="old">Current artist <span class="ar-arrow"></span></th>
                        <th class="ar-sort" data-key="new">Parsed artist <span class="ar-arrow"></span></th>
                        <th class="ar-sort" data-key="source">From <span class="ar-arrow"></span></th>
                    </tr></thead>
                    <tbody id="arRows"></tbody>
                </table>
            </div>
            <div class="mgn-actions" style="margin-top:16px;flex-wrap:wrap;">
                <button class="mgn-btn mgn-btn-primary" id="arApplyBtn" type="button">Fill selected</button>
                <button class="mgn-btn mgn-btn-ghost" id="arSelAll" type="button">Select all</button>
                <button class="mgn-btn mgn-btn-ghost" id="arSelNone" type="button">Select none</button>
                <span class="mgn-note" id="arProgress" style="margin-top:0"></span>
            </div>
            <div class="mgn-note" style="margin-top:8px;">
                Everything is pre-checked. Tip: click a row to toggle it, or <b>shift-click</b> a checkbox to flip a whole range at once. Rows are grouped by parsed artist, so duplicates sit together.
            </div>
            <div class="mgn-note">Fills the Artist field only — run "Scan names" above afterward to rename them to "ARTIST - TITLE". Undo any batch at <a href="/admin/admin-action-history">Admin Action History</a>.</div>

            <div id="arCatWrap" style="display:none;margin-top:22px;">
                <div class="mgn-note mgn-summary" style="margin-top:0;color:#1F1B16;">Where the blank / N/A artists are, by category:</div>
                <div style="margin-top:10px;max-height:300px;overflow:auto;border:1px solid #F0E9DA;border-radius:10px;">
                    <table class="mgn-table">
                        <thead><tr><th>Category</th><th style="text-align:right">Missing artist</th><th>In scope?</th></tr></thead>
                        <tbody id="arCatRows"></tbody>
                    </table>
                </div>
                <div class="mgn-note">"In scope" = treated as a music format. If a music category shows "no" here, tell me its name and I'll add it.</div>
            </div>

            <div id="arFlaggedWrap" style="display:none;margin-top:22px;">
                <div class="mgn-note mgn-summary" id="arFlaggedSummary" style="margin-top:0;color:#1F1B16;"></div>
                <div style="margin-top:10px;max-height:340px;overflow:auto;border:1px solid #F0E9DA;border-radius:10px;">
                    <table class="mgn-table">
                        <thead><tr><th>Current name</th><th>Why it's flagged</th></tr></thead>
                        <tbody id="arFlaggedRows"></tbody>
                    </table>
                </div>
                <div class="mgn-note">These are left untouched — fix the Artist field by hand on each product's edit page.</div>
            </div>
        </div>
    </div>

    <div class="mgn-card" style="border-color:#C9A227;">
        <h2>Rebuild from Discogs <span style="color:#8E8273;font-weight:400">(accurate — recommended)</span></h2>
        <p class="sub">The Artist field is unreliable (often holds the title), so this pulls the true artist + title straight from Discogs using each product's release id, and rewrites the name as "ARTIST - TITLE". Rate-limited (~55/min), runs in batches, undoable. Only products with a Discogs release id are touched; "retired" is skipped.</p>
        <div class="mgn-actions">
            <button class="mgn-btn mgn-btn-ghost" id="dgScanBtn" type="button">Check + sample</button>
        </div>
        <div id="dgResult" style="display:none;margin-top:18px;">
            <div class="mgn-note mgn-summary" id="dgSummary" style="margin-top:0;color:#1F1B16;"></div>
            <div style="margin-top:10px;max-height:300px;overflow:auto;border:1px solid #F0E9DA;border-radius:10px;">
                <table class="mgn-table">
                    <thead><tr><th>Current name</th><th>From Discogs</th></tr></thead>
                    <tbody id="dgRows"></tbody>
                </table>
            </div>
            <div class="mgn-actions" style="margin-top:16px;">
                <button class="mgn-btn mgn-btn-primary" id="dgRunBtn" type="button">Rebuild all from Discogs</button>
                <span class="mgn-note" id="dgProgress" style="margin-top:0"></span>
            </div>
            <div class="mgn-note">Runs unattended in batches — leave this tab open. Undo any batch at <a href="/admin/admin-action-history">Admin Action History</a>.</div>
        </div>
    </div>
</div>
</section>
@stop

@section('javascript')
<script>
(function () {
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).getAttribute
        ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') : '';
    var msg = document.getElementById('mgnMsg');
    var result = document.getElementById('mgnResult');
    var scanBtn = document.getElementById('mgnScanBtn');
    var applyBtn = document.getElementById('mgnApplyBtn');

    function showMsg(t, ok) { msg.textContent = t; msg.className = 'mgn-msg ' + (ok ? 'ok' : 'err'); }
    function clearMsg() { msg.className = 'mgn-msg'; msg.textContent = ''; }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function post(url, body) {
        return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: JSON.stringify(body || {}) }).then(function (r) { return r.json(); });
    }

    scanBtn.addEventListener('click', function () {
        clearMsg(); result.style.display = 'none';
        scanBtn.disabled = true; scanBtn.textContent = 'Scanning…';
        post('{{ route('products.name.scan') }}').then(function (d) {
            scanBtn.disabled = false; scanBtn.textContent = 'Scan names';
            if (!d.success) { showMsg(d.msg || 'Scan failed.', false); return; }
            document.getElementById('mgnSummary').innerHTML =
                '<b>' + d.to_fix + '</b> name(s) to fix &nbsp;·&nbsp; ' + d.compliant + ' already standard &nbsp;·&nbsp; ' + d.flagged + ' flagged for manual review (no clear artist).';
            var rows = d.preview.map(function (f) {
                return '<tr><td class="mgn-old">' + esc(f.old) + '</td><td class="mgn-new">' + esc(f['new']) + '</td></tr>';
            }).join('');
            if (d.to_fix > d.preview.length) {
                rows += '<tr><td colspan="2" style="color:#8E8273">… and ' + (d.to_fix - d.preview.length) + ' more. All will be renamed.</td></tr>';
            }
            document.getElementById('mgnRows').innerHTML = rows || '<tr><td colspan="2">Nothing to fix.</td></tr>';
            applyBtn.style.display = d.to_fix > 0 ? '' : 'none';
            result.style.display = 'block';
        }).catch(function () { scanBtn.disabled = false; scanBtn.textContent = 'Scan names'; showMsg('Scan failed — try again.', false); });
    });

    function runBatch(total) {
        post('{{ route('products.name.apply') }}', { max: 500 }).then(function (d) {
            if (!d.success) { applyBtn.disabled = false; showMsg(d.msg || 'Rename failed.', false); return; }
            total += d.renamed;
            document.getElementById('mgnProgress').textContent = 'Renamed ' + total + ' — ' + d.remaining + ' remaining…';
            if (d.remaining > 0 && d.renamed > 0) { runBatch(total); }
            else {
                applyBtn.disabled = false; result.style.display = 'none';
                showMsg('Done — renamed ' + total + ' product(s). Undo any batch at Admin Action History.', true);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }).catch(function () { applyBtn.disabled = false; showMsg('Rename failed mid-run — re-scan to see what remains.', false); });
    }

    applyBtn.addEventListener('click', function () {
        if (!confirm('Rename all off-standard product names to "ARTIST - TITLE"? Each batch is undoable from Admin Action History.')) return;
        clearMsg(); applyBtn.disabled = true;
        document.getElementById('mgnProgress').textContent = 'Starting…';
        runBatch(0);
    });

    // ---- Discogs rebuild ----
    var dgScanBtn = document.getElementById('dgScanBtn');
    var dgRunBtn = document.getElementById('dgRunBtn');
    var dgResult = document.getElementById('dgResult');

    dgScanBtn.addEventListener('click', function () {
        clearMsg(); dgResult.style.display = 'none';
        dgScanBtn.disabled = true; dgScanBtn.textContent = 'Checking (fetching samples)…';
        post('{{ route('products.name.discogs.scan') }}').then(function (d) {
            dgScanBtn.disabled = false; dgScanBtn.textContent = 'Check + sample';
            if (!d.success) { showMsg(d.msg || 'Check failed.', false); return; }
            document.getElementById('dgSummary').innerHTML = '<b>' + d.total.toLocaleString() + '</b> product(s) have a Discogs id and can be rebuilt. Sample:';
            document.getElementById('dgRows').innerHTML = (d.sample || []).map(function (s) {
                return '<tr><td class="mgn-old">' + esc(s.old) + '</td><td class="mgn-new">' + esc(s['new']) + '</td></tr>';
            }).join('') || '<tr><td colspan="2">No sample differences.</td></tr>';
            dgRunBtn.style.display = d.total > 0 ? '' : 'none';
            dgResult.style.display = 'block';
        }).catch(function () { dgScanBtn.disabled = false; dgScanBtn.textContent = 'Check + sample'; showMsg('Check failed — try again.', false); });
    });

    function dgBatch(afterId, totalRenamed, phase) {
        var phaseLabel = phase === 'rest' ? 'everything else' : 'sealed vinyl';
        post('{{ route('products.name.discogs.rebuild') }}', { after_id: afterId, max: 20, phase: phase }).then(function (d) {
            if (!d.success) { dgRunBtn.disabled = false; showMsg(d.msg || 'Rebuild failed.', false); return; }
            totalRenamed += d.renamed;
            var note = 'Rebuilt ' + totalRenamed + ' (' + phaseLabel + ': ' + d.remaining.toLocaleString() + ' left)';
            if (d.rate_limited) { note += ' · Discogs rate limit, pausing 60s…'; }
            document.getElementById('dgProgress').textContent = note + '…';
            if (d.done) {
                if (phase === 'sealed') {
                    // Sealed vinyl finished — roll straight into everything else.
                    document.getElementById('dgProgress').textContent = 'Sealed vinyl done (' + totalRenamed + '). Now everything else…';
                    dgBatch(0, totalRenamed, 'rest');
                    return;
                }
                dgRunBtn.disabled = false; dgResult.style.display = 'none';
                showMsg('Done — rebuilt ' + totalRenamed + ' name(s) from Discogs (sealed vinyl first). Undo any batch at Admin Action History.', true);
                window.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }
            var wait = d.rate_limited ? 60000 : 300;
            setTimeout(function () { dgBatch(d.after_id, totalRenamed, phase); }, wait);
        }).catch(function () {
            // Network/timeout — wait and retry from the same cursor.
            document.getElementById('dgProgress').textContent = 'Hiccup — retrying in 10s…';
            setTimeout(function () { dgBatch(afterId, totalRenamed, phase); }, 10000);
        });
    }

    dgRunBtn.addEventListener('click', function () {
        if (!confirm('Rebuild all Discogs-backed product names from Discogs, sealed vinyl first? Runs in the background in batches (may take a while at Discogs rate limits). Each batch is undoable.')) return;
        clearMsg(); dgRunBtn.disabled = true;
        document.getElementById('dgProgress').textContent = 'Starting with sealed vinyl…';
        dgBatch(0, 0, 'sealed');
    });

    // ---- Backfill missing artist (from name) ----
    var arScanBtn = document.getElementById('arScanBtn');
    var arApplyBtn = document.getElementById('arApplyBtn');
    var arResult = document.getElementById('arResult');
    var arRowsEl = document.getElementById('arRows');
    var arCheckAll = document.getElementById('arCheckAll');
    var arData = [];            // all previewed fixes {id,name,old,new,source, sel}
    var arSort = { key: null, dir: 1 };

    function curArtistHtml(old) {
        return (old == null || String(old).trim() === '')
            ? '<span style="color:#B9AE99">(blank)</span>' : esc(old);
    }

    function renderRows() {
        var rows = arData.map(function (f) {
            var sealedTag = f.sealed
                ? '<span style="display:inline-block;margin-right:6px;padding:1px 6px;font-size:11px;font-weight:600;color:#1B5E20;background:#E4F2E4;border-radius:4px;vertical-align:middle">SEALED VINYL</span>'
                : '';
            return '<tr class="' + (f.sel ? '' : 'ar-off') + '" data-id="' + f.id + '" style="cursor:pointer">' +
                '<td style="text-align:center"><input type="checkbox" class="ar-cb"' + (f.sel ? ' checked' : '') + '></td>' +
                '<td class="mgn-old">' + sealedTag + esc(f.name) + '</td>' +
                '<td style="color:#8E8273">' + curArtistHtml(f.old) + '</td>' +
                '<td class="mgn-new">' + esc(f['new']) + '</td>' +
                '<td style="color:#8E8273">' + esc(f.source) + '</td></tr>';
        }).join('');
        arRowsEl.innerHTML = rows || '<tr><td colspan="5">Nothing to fill.</td></tr>';
        updateSelInfo();
    }

    function updateSelInfo() {
        var n = arData.filter(function (f) { return f.sel; }).length;
        document.getElementById('arSelInfo').innerHTML = '<b>' + n + '</b> of ' + arData.length + ' shown selected to fill.';
        arApplyBtn.textContent = 'Fill selected (' + n + ')';
        arApplyBtn.disabled = n === 0;
        arCheckAll.checked = n > 0 && n === arData.length;
    }

    function sortBy(key) {
        if (arSort.key === key) { arSort.dir *= -1; } else { arSort.key = key; arSort.dir = 1; }
        arData.sort(function (a, b) {
            var x = String(a[key] || '').toLowerCase(), y = String(b[key] || '').toLowerCase();
            return x < y ? -1 * arSort.dir : x > y ? 1 * arSort.dir : 0;
        });
        Array.prototype.forEach.call(document.querySelectorAll('.ar-sort .ar-arrow'), function (s) { s.textContent = ''; });
        var th = document.querySelector('.ar-sort[data-key="' + key + '"] .ar-arrow');
        if (th) { th.textContent = arSort.dir > 0 ? '▲' : '▼'; }
        renderRows();
    }

    Array.prototype.forEach.call(document.querySelectorAll('.ar-sort'), function (th) {
        th.addEventListener('click', function () { sortBy(th.getAttribute('data-key')); });
    });

    // Index of a row in arData by its product id (arData is what's on screen,
    // in the current sort order).
    var arLastIdx = null;
    function arIdxById(id) { return arData.findIndex(function (x) { return x.id === id; }); }

    // One click handler for the whole table:
    //   - click a checkbox      -> toggle that row
    //   - shift-click a checkbox -> set every row between it and the last click
    //   - click anywhere else on the row -> toggle that row
    arRowsEl.addEventListener('click', function (e) {
        var tr = e.target.closest ? e.target.closest('tr') : null;
        if (!tr || !tr.getAttribute('data-id')) { return; }
        if (e.target.tagName === 'A') { return; }
        var idx = arIdxById(parseInt(tr.getAttribute('data-id'), 10));
        if (idx < 0) { return; }
        var isCb = e.target.classList && e.target.classList.contains('ar-cb');

        if (isCb && e.shiftKey && arLastIdx !== null && arLastIdx !== idx) {
            var lo = Math.min(arLastIdx, idx), hi = Math.max(arLastIdx, idx);
            var val = e.target.checked;
            for (var i = lo; i <= hi; i++) { arData[i].sel = val; }
            arLastIdx = idx;
            renderRows();
            return;
        }

        // Plain checkbox click already flipped its own state; a row click flips
        // the model ourselves.
        arData[idx].sel = isCb ? e.target.checked : !arData[idx].sel;
        arLastIdx = idx;
        if (isCb) { tr.classList.toggle('ar-off', !arData[idx].sel); updateSelInfo(); }
        else { renderRows(); }
    });

    function arSelectWhere(fn) { arData.forEach(function (f) { f.sel = fn(f); }); arLastIdx = null; renderRows(); }
    arCheckAll.addEventListener('change', function () { arSelectWhere(function () { return arCheckAll.checked; }); });
    document.getElementById('arSelAll').addEventListener('click', function () { arSelectWhere(function () { return true; }); });
    document.getElementById('arSelNone').addEventListener('click', function () { arSelectWhere(function () { return false; }); });

    var arFilterEl = document.getElementById('arFilter');
    var arAlphaEl = document.getElementById('arAlpha');
    var arFilter = '';

    // Build the A-Z / 0-9 quick filter bar once.
    (function buildAlpha() {
        var letters = ['All', '#', 'A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','U','V','W','X','Y','Z'];
        arAlphaEl.innerHTML = letters.map(function (l) {
            var val = l === 'All' ? '' : l;
            return '<button type="button" class="ar-alpha-btn" data-val="' + esc(val) + '">' + esc(l) + '</button>';
        }).join('');
        Array.prototype.forEach.call(arAlphaEl.querySelectorAll('.ar-alpha-btn'), function (b) {
            b.addEventListener('click', function () {
                arFilter = b.getAttribute('data-val');
                arFilterEl.value = (arFilter === '#') ? '' : arFilter;
                doArScan();
            });
        });
    })();

    function markAlpha() {
        var cur = (arFilter || 'All');
        Array.prototype.forEach.call(arAlphaEl.querySelectorAll('.ar-alpha-btn'), function (b) {
            var v = b.getAttribute('data-val') || 'All';
            b.classList.toggle('on', v === cur);
        });
    }

    // Debounced free-text filter.
    var arFilterTimer = null;
    arFilterEl.addEventListener('input', function () {
        arFilter = arFilterEl.value.trim();
        clearTimeout(arFilterTimer);
        arFilterTimer = setTimeout(doArScan, 350);
    });

    function doArScan() {
        clearMsg(); arResult.style.display = 'none';
        arScanBtn.disabled = true; arScanBtn.textContent = 'Scanning…';
        markAlpha();
        post('{{ route('products.artist.scan') }}', { filter: arFilter }).then(function (d) {
            arScanBtn.disabled = false; arScanBtn.textContent = 'Scan missing artists';
            if (!d.success) { showMsg(d.msg || 'Scan failed.', false); return; }
            arAlphaEl.style.display = 'flex';
            var filterNote = d.filter ? ' matching "' + esc(d.filter) + '"' : '';
            document.getElementById('arSummary').innerHTML =
                '<b>' + d.to_fill + '</b> to fill' + filterNote
                + (d.filter ? ' &nbsp;·&nbsp; ' + d.total_to_fill + ' total' : '')
                + ' &nbsp;·&nbsp; ' + d.flagged + ' flagged'
                + (d.to_fill > d.preview.length ? ' &nbsp;·&nbsp; showing first ' + d.preview.length : '') + '.';
            arData = d.preview.map(function (f) { f.sel = true; return f; });
            arSort = { key: null, dir: 1 };
            Array.prototype.forEach.call(document.querySelectorAll('.ar-sort .ar-arrow'), function (s) { s.textContent = ''; });
            renderRows();
            arApplyBtn.style.display = d.to_fill > 0 ? '' : 'none';

            var bc = d.by_category || [];
            if (bc.length) {
                document.getElementById('arCatRows').innerHTML = bc.map(function (c) {
                    var tag = c.in_scope
                        ? '<span style="color:#1B5E20;font-weight:600">yes</span>'
                        : '<span style="color:#B71C1C;font-weight:600">no</span>';
                    return '<tr><td>' + esc(c.category) + '</td><td style="text-align:right">' + c.count + '</td><td>' + tag + '</td></tr>';
                }).join('');
                document.getElementById('arCatWrap').style.display = 'block';
            } else {
                document.getElementById('arCatWrap').style.display = 'none';
            }

            var fp = d.flagged_preview || [];
            if (fp.length) {
                document.getElementById('arFlaggedSummary').innerHTML =
                    '<b>' + d.flagged + '</b> flagged for manual review' + (d.flagged > fp.length ? ' (showing first ' + fp.length + ')' : '') + ':';
                document.getElementById('arFlaggedRows').innerHTML = fp.map(function (f) {
                    return '<tr><td>' + esc(f.name) + '</td><td style="color:#8E8273">' + esc(f.reason) + '</td></tr>';
                }).join('');
                document.getElementById('arFlaggedWrap').style.display = 'block';
            } else {
                document.getElementById('arFlaggedWrap').style.display = 'none';
            }
            arResult.style.display = 'block';
        }).catch(function () { arScanBtn.disabled = false; arScanBtn.textContent = 'Scan missing artists'; showMsg('Scan failed — try again.', false); });
    }

    arScanBtn.addEventListener('click', function () { doArScan(); });

    // Preview first: show a live sample of "name -> Discogs artist" before filling.
    var dgArtistScanBtn = document.getElementById('dgArtistScanBtn');
    var dgArtistScanNote = document.getElementById('dgArtistScanNote');
    dgArtistScanBtn.addEventListener('click', function () {
        clearMsg();
        dgArtistScanBtn.disabled = true; dgArtistScanBtn.textContent = 'Checking Discogs…';
        dgArtistScanNote.textContent = 'Fetching a sample (~10s)…';
        post('{{ route('products.artist.discogs.scan') }}', {}).then(function (d) {
            dgArtistScanBtn.disabled = false; dgArtistScanBtn.textContent = 'Check + preview';
            dgArtistScanNote.textContent = '';
            if (!d.success) { showMsg(d.msg || 'Check failed.', false); return; }
            document.getElementById('dgArtistSummary').innerHTML =
                '<b>' + d.total.toLocaleString() + '</b> blank-artist product(s) have a Discogs id and will be filled (sealed vinyl first). Sample:';
            document.getElementById('dgArtistRows').innerHTML = (d.sample || []).length
                ? d.sample.map(function (s) {
                    return '<tr><td class="mgn-old">' + esc(s.name) + '</td><td class="mgn-new">' + esc(s.artist) + '</td></tr>';
                }).join('')
                : '<tr><td colspan="2" style="color:#8E8273">No sample rows (nothing to fill, or Discogs returned no artist).</td></tr>';
            document.getElementById('dgArtistPreview').style.display = d.total > 0 ? 'block' : 'none';
            if (d.total === 0) { showMsg('Nothing to fill — no blank-artist products with a Discogs id.', true); }
        }).catch(function () { dgArtistScanBtn.disabled = false; dgArtistScanBtn.textContent = 'Check + preview'; dgArtistScanNote.textContent = ''; showMsg('Check failed — try again.', false); });
    });

    // Accurate artist-column fill from Discogs — two passes (sealed vinyl, then
    // the rest), cursor-paged, rate-limited. Leaves per-batch snapshots.
    var dgArtistBtn = document.getElementById('dgArtistBtn');
    var dgArtistProgress = document.getElementById('dgArtistProgress');
    function dgArtistBatch(afterId, total, phase) {
        var phaseLabel = phase === 'rest' ? 'everything else' : 'sealed vinyl';
        post('{{ route('products.artist.discogs') }}', { after_id: afterId, max: 20, phase: phase }).then(function (d) {
            if (!d.success) { dgArtistBtn.disabled = false; showMsg(d.msg || 'Discogs fill failed.', false); dgArtistProgress.textContent = ''; return; }
            total += (d.filled || 0);
            var note = 'Filled ' + total + ' (' + phaseLabel + ': ' + d.remaining.toLocaleString() + ' left)';
            if (d.rate_limited) { note += ' · Discogs rate limit, pausing 60s…'; }
            dgArtistProgress.textContent = note + '…';
            if (d.done) {
                if (phase === 'sealed') {
                    dgArtistProgress.textContent = 'Sealed vinyl done (' + total + '). Now everything else…';
                    dgArtistBatch(0, total, 'rest');
                    return;
                }
                dgArtistBtn.disabled = false; dgArtistProgress.textContent = '';
                showMsg('Filled ' + total + ' artist(s) from Discogs (sealed vinyl first). Undo any batch at Admin Action History.', true);
                return;
            }
            var wait = d.rate_limited ? 60000 : 300;
            setTimeout(function () { dgArtistBatch(d.after_id, total, phase); }, wait);
        }).catch(function () {
            dgArtistProgress.textContent = 'Hiccup — retrying in 10s…';
            setTimeout(function () { dgArtistBatch(afterId, total, phase); }, 10000);
        });
    }
    dgArtistBtn.addEventListener('click', function () {
        if (!confirm('Fill the Artist field from Discogs for every blank-artist product that has a release id, sealed vinyl first? Runs in batches; undoable from Admin Action History.')) { return; }
        clearMsg(); dgArtistBtn.disabled = true;
        dgArtistProgress.textContent = 'Starting with sealed vinyl…';
        dgArtistBatch(0, 0, 'sealed');
    });

    arApplyBtn.addEventListener('click', function () {
        var ids = arData.filter(function (f) { return f.sel; }).map(function (f) { return f.id; });
        if (!ids.length) { return; }
        if (!confirm('Fill the Artist field on ' + ids.length + ' selected product(s)? Undoable from Admin Action History.')) return;
        clearMsg(); arApplyBtn.disabled = true;
        document.getElementById('arProgress').textContent = 'Filling ' + ids.length + '…';
        post('{{ route('products.artist.apply') }}', { ids: ids }).then(function (d) {
            arApplyBtn.disabled = false;
            if (!d.success) { showMsg(d.msg || 'Fill failed.', false); return; }
            // Drop the filled rows from the table.
            var filledSet = {}; (d.filled_ids || []).forEach(function (i) { filledSet[i] = 1; });
            arData = arData.filter(function (f) { return !filledSet[f.id]; });
            renderRows();
            document.getElementById('arProgress').textContent = '';
            showMsg('Filled ' + d.filled + ' artist(s). ' + d.remaining + ' still missing overall. Run "Scan names" above to rename to ARTIST - TITLE; undo at Admin Action History.', true);
            if (!arData.length) { arResult.style.display = 'none'; window.scrollTo({ top: 0, behavior: 'smooth' }); }
        }).catch(function () { arApplyBtn.disabled = false; showMsg('Fill failed — re-scan to see what remains.', false); });
    });
})();
</script>
@endsection
