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
        </div>
        <div id="arResult" style="display:none;margin-top:18px;">
            <div class="mgn-note mgn-summary" id="arSummary" style="margin-top:0;color:#1F1B16;"></div>
            <div style="margin-top:10px;max-height:380px;overflow:auto;border:1px solid #F0E9DA;border-radius:10px;">
                <table class="mgn-table">
                    <thead><tr><th>Current name</th><th>Parsed artist</th><th>From</th></tr></thead>
                    <tbody id="arRows"></tbody>
                </table>
            </div>
            <div class="mgn-actions" style="margin-top:16px;">
                <button class="mgn-btn mgn-btn-primary" id="arApplyBtn" type="button">Fill all parsed artists</button>
                <span class="mgn-note" id="arProgress" style="margin-top:0"></span>
            </div>
            <div class="mgn-note">Fills the Artist field only — run "Scan names" above afterward to rename them to "ARTIST - TITLE". Undo any batch at <a href="/admin/admin-action-history">Admin Action History</a>.</div>
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

    function dgBatch(afterId, totalRenamed) {
        post('{{ route('products.name.discogs.rebuild') }}', { after_id: afterId, max: 20 }).then(function (d) {
            if (!d.success) { dgRunBtn.disabled = false; showMsg(d.msg || 'Rebuild failed.', false); return; }
            totalRenamed += d.renamed;
            var note = 'Rebuilt ' + totalRenamed + ' — ' + d.remaining.toLocaleString() + ' remaining';
            if (d.rate_limited) { note += ' · Discogs rate limit, pausing 60s…'; }
            document.getElementById('dgProgress').textContent = note + '…';
            if (d.done) {
                dgRunBtn.disabled = false; dgResult.style.display = 'none';
                showMsg('Done — rebuilt ' + totalRenamed + ' name(s) from Discogs. Undo any batch at Admin Action History.', true);
                window.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }
            var wait = d.rate_limited ? 60000 : 300;
            setTimeout(function () { dgBatch(d.after_id, totalRenamed); }, wait);
        }).catch(function () {
            // Network/timeout — wait and retry from the same cursor.
            document.getElementById('dgProgress').textContent = 'Hiccup — retrying in 10s…';
            setTimeout(function () { dgBatch(afterId, totalRenamed); }, 10000);
        });
    }

    dgRunBtn.addEventListener('click', function () {
        if (!confirm('Rebuild all Discogs-backed product names from Discogs? Runs in the background in batches (may take a while at Discogs rate limits). Each batch is undoable.')) return;
        clearMsg(); dgRunBtn.disabled = true;
        document.getElementById('dgProgress').textContent = 'Starting…';
        dgBatch(0, 0);
    });

    // ---- Backfill missing artist (from name) ----
    var arScanBtn = document.getElementById('arScanBtn');
    var arApplyBtn = document.getElementById('arApplyBtn');
    var arResult = document.getElementById('arResult');

    arScanBtn.addEventListener('click', function () {
        clearMsg(); arResult.style.display = 'none';
        arScanBtn.disabled = true; arScanBtn.textContent = 'Scanning…';
        post('{{ route('products.artist.scan') }}').then(function (d) {
            arScanBtn.disabled = false; arScanBtn.textContent = 'Scan missing artists';
            if (!d.success) { showMsg(d.msg || 'Scan failed.', false); return; }
            document.getElementById('arSummary').innerHTML =
                '<b>' + d.to_fill + '</b> artist(s) to fill &nbsp;·&nbsp; ' + d.flagged + ' flagged for manual review (no clear artist in the name).';
            var rows = d.preview.map(function (f) {
                return '<tr><td class="mgn-old">' + esc(f.name) + '</td><td class="mgn-new">' + esc(f['new']) + '</td><td style="color:#8E8273">' + esc(f.source) + '</td></tr>';
            }).join('');
            if (d.to_fill > d.preview.length) {
                rows += '<tr><td colspan="3" style="color:#8E8273">… and ' + (d.to_fill - d.preview.length) + ' more. All will be filled.</td></tr>';
            }
            document.getElementById('arRows').innerHTML = rows || '<tr><td colspan="3">Nothing to fill.</td></tr>';
            arApplyBtn.style.display = d.to_fill > 0 ? '' : 'none';
            arResult.style.display = 'block';
        }).catch(function () { arScanBtn.disabled = false; arScanBtn.textContent = 'Scan missing artists'; showMsg('Scan failed — try again.', false); });
    });

    function arBatch(total) {
        post('{{ route('products.artist.apply') }}', { max: 500 }).then(function (d) {
            if (!d.success) { arApplyBtn.disabled = false; showMsg(d.msg || 'Fill failed.', false); return; }
            total += d.filled;
            document.getElementById('arProgress').textContent = 'Filled ' + total + ' — ' + d.remaining + ' remaining…';
            if (d.remaining > 0 && d.filled > 0) { arBatch(total); }
            else {
                arApplyBtn.disabled = false; arResult.style.display = 'none';
                showMsg('Done — filled ' + total + ' artist(s). Run "Scan names" above to rename them to ARTIST - TITLE. Undo any batch at Admin Action History.', true);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }).catch(function () { arApplyBtn.disabled = false; showMsg('Fill failed mid-run — re-scan to see what remains.', false); });
    }

    arApplyBtn.addEventListener('click', function () {
        if (!confirm('Fill the Artist field on all parsed music products? Only confident parses are written. Each batch is undoable from Admin Action History.')) return;
        clearMsg(); arApplyBtn.disabled = true;
        document.getElementById('arProgress').textContent = 'Starting…';
        arBatch(0);
    });
})();
</script>
@endsection
