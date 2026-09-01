@extends('layouts.app')
@section('title', 'Orphaned Stock Cleanup')

@section('content')
<script>document.body.classList.add('merge-v2');</script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">

<style>
body.merge-v2 { background: #FAF6EE; font-family: "Inter Tight", system-ui, sans-serif; -webkit-font-smoothing: antialiased; color: #1F1B16; }
body.merge-v2 .content-wrapper { background: #FAF6EE !important; }
body.merge-v2 .content-header { background: transparent; padding: 28px 16px 8px; }
body.merge-v2 .content-header h1 { font-size: 26px; font-weight: 700; letter-spacing: -0.2px; color: #1F1B16; margin: 0 0 6px; }
body.merge-v2 .content { padding: 0 16px 60px; }
.mg-wrap { max-width: 860px; }
.mg-card { background: #fff; border: 1px solid #ECE3D2; border-radius: 16px; padding: 22px 22px 24px; box-shadow: 0 1px 2px rgba(31,27,22,.04); margin-bottom: 18px; }
.mg-card h2 { font-size: 17px; font-weight: 700; margin: 0 0 4px; }
.mg-card p.sub { color: #8E8273; font-size: 13.5px; margin: 0 0 18px; }
.mg-btn { height: 44px; padding: 0 22px; border-radius: 10px; border: 0; font-family: inherit; font-size: 14.5px; font-weight: 700; cursor: pointer; }
.mg-btn-primary { background: #1F1B16; color: #FFF2B3; }
.mg-btn-primary:disabled { opacity: .5; cursor: not-allowed; }
.mg-btn-ghost { background: #F3ECDD; color: #1F1B16; }
.mg-actions { margin-top: 18px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.mg-compare { display: none; }
.mg-compare table { width: 100%; border-collapse: collapse; margin-top: 6px; }
.mg-compare th, .mg-compare td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #F0E9DA; font-size: 14px; }
.mg-compare th { color: #8E8273; font-weight: 600; font-size: 12.5px; text-transform: uppercase; letter-spacing: .4px; }
.mg-compare td.num { font-variant-numeric: tabular-nums; font-weight: 600; }
.mg-note { font-size: 13px; color: #8E8273; margin-top: 14px; line-height: 1.5; }
.mg-msg { display: none; padding: 12px 14px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 16px; }
.mg-msg.ok { display: block; background: #E8F5E9; color: #1B5E20; }
.mg-msg.err { display: block; background: #FDECEA; color: #B71C1C; }
.mg-stat { font-size: 34px; font-weight: 800; letter-spacing: -0.5px; }
.mg-stat-row { display: flex; gap: 40px; flex-wrap: wrap; margin-bottom: 6px; }
.mg-stat-label { font-size: 12.5px; color: #8E8273; font-weight: 600; text-transform: uppercase; letter-spacing: .4px; margin-top: 4px; }
</style>

<section class="content-header"><h1>Orphaned Stock Cleanup<br><small>When a location is removed from a product, its stock row at that location used to stay behind — invisible on the product edit page and "Set current stock" (both only loop assigned locations), but still summed into the total shown on the site. So setting stock to 0 on the visible locations didn't actually zero it out. The forward bug is fixed in code; this cleans up the rows that already piled up.</small></h1></section>

<section class="content">
<div class="mg-wrap">
    <div id="mgMsg" class="mg-msg"></div>

    <div class="mg-card">
        <h2>Scan for orphaned rows</h2>
        <p class="sub">Read-only — finds stock rows sitting at a location the product is no longer assigned to. Nothing changes until you click Clean Up below.</p>
        <div class="mg-actions">
            <button class="mg-btn mg-btn-ghost" id="scanBtn" type="button">Scan</button>
        </div>
        <div id="scanResult" style="display:none;margin-top:18px;">
            <div class="mg-stat-row">
                <div><div class="mg-stat" id="statRows">0</div><div class="mg-stat-label">Orphaned rows</div></div>
                <div><div class="mg-stat" id="statQty">0</div><div class="mg-stat-label">Units</div></div>
                <div><div class="mg-stat" id="statProducts">0</div><div class="mg-stat-label">Products affected (sample)</div></div>
            </div>
            <div class="mg-compare" style="display:block;margin-top:10px;max-height:60vh;overflow:auto;border:1px solid #F0E9DA;border-radius:10px;">
                <table>
                    <thead><tr><th>Product</th><th>Stray location</th><th>Qty</th><th>Last touched</th></tr></thead>
                    <tbody id="scanRows"></tbody>
                </table>
            </div>
            <div class="mg-actions">
                <button class="mg-btn mg-btn-primary" id="cleanupBtn" type="button">Clean up all orphaned rows</button>
                <span class="mg-note" style="margin-top:0">Snapshotted first — undo any time at <a href="/admin/admin-action-history">Admin Action History</a>.</span>
            </div>
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
    var msg = document.getElementById('mgMsg');
    var scanBtn = document.getElementById('scanBtn');
    var cleanupBtn = document.getElementById('cleanupBtn');
    var scanResult = document.getElementById('scanResult');

    function showMsg(text, ok) { msg.textContent = text; msg.className = 'mg-msg ' + (ok ? 'ok' : 'err'); }
    function clearMsg() { msg.className = 'mg-msg'; msg.textContent = ''; }
    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function num(n) { return (Math.round(n * 100) / 100).toLocaleString(); }
    var prodUrl = function (id) { return '{{ url('/products') }}/' + id + '/edit'; };

    function runScan() {
        clearMsg();
        scanBtn.disabled = true;
        scanBtn.textContent = 'Scanning…';
        fetch('{{ route('products.duplicateStockRowsScope') }}?orphans=1', {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        }).then(function (r) { return r.json(); }).then(function (d) {
            scanBtn.disabled = false;
            scanBtn.textContent = 'Scan';
            if (!d.success) { showMsg(d.msg || 'Scan failed.', false); return; }
            document.getElementById('statRows').textContent = num(d.orphan_row_count);
            document.getElementById('statQty').textContent = num(d.orphan_qty_total);
            document.getElementById('statProducts').textContent = d.sample.length + (d.orphan_row_count > d.sample.length ? '+' : '');
            var rows = d.sample.map(function (r) {
                return '<tr><td><a href="' + prodUrl(r.product_id) + '" target="_blank" rel="noopener" style="color:#1F1B16;text-decoration:underline;">' + esc(r.name) + '</a>'
                    + ' <small style="color:#8E8273">(SKU ' + esc(r.sku) + ')</small></td>'
                    + '<td>location #' + r.location_id + '</td>'
                    + '<td class="num">' + num(r.qty_available) + '</td>'
                    + '<td>' + esc(r.updated_at) + '</td></tr>';
            }).join('');
            document.getElementById('scanRows').innerHTML = rows || '<tr><td colspan="4">No orphaned rows found.</td></tr>';
            cleanupBtn.style.display = d.orphan_row_count > 0 ? '' : 'none';
            scanResult.style.display = 'block';
        }).catch(function () {
            scanBtn.disabled = false;
            scanBtn.textContent = 'Scan';
            showMsg('Scan failed — try again.', false);
        });
    }

    scanBtn.addEventListener('click', runScan);

    cleanupBtn.addEventListener('click', function () {
        if (!confirm('Delete every orphaned stock row across the catalog? This is what has been silently keeping some zeroed-out products showing in stock on nivessa.com. Fully undoable from Admin Action History.')) return;
        clearMsg();
        cleanupBtn.disabled = true;
        cleanupBtn.textContent = 'Cleaning up…';
        fetch('{{ route('products.backfillOrphanedLocationStock') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            body: '{}',
        }).then(function (r) { return r.json(); }).then(function (d) {
            cleanupBtn.disabled = false;
            cleanupBtn.textContent = 'Clean up all orphaned rows';
            if (!d.success) { showMsg(d.msg || 'Cleanup failed.', false); return; }
            showMsg(d.msg || ('Removed ' + d.removed + ' orphaned row(s), ' + d.qty_total + ' units.'), true);
            scanResult.style.display = 'none';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }).catch(function () {
            cleanupBtn.disabled = false;
            cleanupBtn.textContent = 'Clean up all orphaned rows';
            showMsg('Cleanup failed — try again.', false);
        });
    });

    runScan();
})();
</script>
@endsection
