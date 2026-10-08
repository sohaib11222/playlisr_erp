@extends('layouts.app')
@section('title', 'Old Setup Listings')

@section('content')
<style>
.lc-wrap { max-width: 1200px; margin: 0 auto; padding: 20px 16px 60px; color: #1F1B16; }
.lc-wrap h1 { font-size: 26px; font-weight: 700; margin: 0 0 6px; }
.lc-sub { color: #6B6155; font-size: 14px; margin: 0 0 18px; max-width: 900px; }
.lc-card { background: #fff; border: 1px solid #E6DCCF; border-radius: 12px; padding: 16px 18px; margin-bottom: 18px; }
.lc-card h2 { font-size: 18px; font-weight: 700; margin: 0 0 6px; }
.lc-btn { border: 0; border-radius: 8px; padding: 9px 16px; font-weight: 600; cursor: pointer; }
.lc-btn-ghost { background: #F1EADF; color: #1F1B16; }
.lc-btn-primary { background: #1F1B16; color: #FFE8A3; }
.lc-btn[disabled] { opacity: .5; cursor: default; }
.lc-note { font-size: 13px; color: #6B6155; margin-left: 10px; }
.lc-table-wrap { max-height: 420px; overflow: auto; border: 1px solid #EFE6D8; border-radius: 10px; margin-top: 10px; }
.lc-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.lc-table th { position: sticky; top: 0; background: #FAF6EE; text-align: left; padding: 7px 8px; font-weight: 600; }
.lc-table td { padding: 6px 8px; border-top: 1px solid #F3ECE1; }
.lc-big { font-size: 22px; font-weight: 700; }
</style>

<div class="lc-wrap">
    <h1>Old setup listings</h1>
    <p class="lc-sub">Listings with a made-up SKU (no barcode) and a selling price that is exactly cost + 25%, which is how the 2024 catalog setup priced things. Only listings created in 2024 that have never sold are shown. Checking changes nothing.</p>

    <button class="lc-btn lc-btn-ghost" id="lcScan" type="button">Check</button>
    <span class="lc-note" id="lcScanNote"></span>

    <div id="lcResults" style="display:none;margin-top:18px;">
        <div class="lc-card">
            <h2>Retire: no sales, no stock</h2>
            <div><span class="lc-big" id="lcRetireCount">0</span> listings. They come off the products list and the website, and every one can be brought back from Admin Action History.</div>
            <div class="lc-table-wrap"><table class="lc-table">
                <thead><tr><th>Product</th><th>SKU</th><th>Category</th><th>Cost</th><th>Price</th><th>Created</th><th>By</th></tr></thead>
                <tbody id="lcRetireRows"></tbody>
            </table></div>
            <div style="margin-top:12px;">
                <button class="lc-btn lc-btn-primary" id="lcApply" type="button">Retire these</button>
                <span class="lc-note" id="lcApplyNote"></span>
            </div>
        </div>

        <div class="lc-card">
            <h2>Check the shelf first: no sales, but showing stock</h2>
            <div><span class="lc-big" id="lcCheckCount">0</span> listings (<span id="lcCheckUnits">0</span> units). These might be real copies, so nothing happens to them here.</div>
            <div class="lc-table-wrap"><table class="lc-table">
                <thead><tr><th>Product</th><th>SKU</th><th>Category</th><th>Cost</th><th>Price</th><th>Stock</th><th>Created</th><th>By</th></tr></thead>
                <tbody id="lcCheckRows"></tbody>
            </table></div>
        </div>
    </div>
</div>
@endsection

@section('javascript')
<script>
(function () {
    var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    function post(url) {
        return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: '{}' }).then(function (r) { return r.json(); });
    }
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function money(n) { return '$' + Number(n || 0).toFixed(2); }
    function row(r, withStock) {
        return '<tr><td><a href="/products/' + r.id + '/edit" target="_blank">' + esc(r.name) + '</a></td><td>' + esc(r.sku) + '</td><td>' + esc(r.category || '') + '</td><td>' + money(r.cost) + '</td><td>' + money(r.price) + '</td>'
            + (withStock ? '<td>' + Math.round(r.stock) + '</td>' : '') + '<td>' + esc(r.created) + '</td><td>' + esc(r.by) + '</td></tr>';
    }
    var scanBtn = document.getElementById('lcScan'), applyBtn = document.getElementById('lcApply');
    scanBtn.addEventListener('click', function () {
        scanBtn.disabled = true;
        document.getElementById('lcScanNote').textContent = 'Checking the whole catalog...';
        post('/products/legacy-cleanup/scan').then(function (j) {
            scanBtn.disabled = false;
            if (!j.success) { document.getElementById('lcScanNote').textContent = j.msg || 'Check failed.'; return; }
            document.getElementById('lcScanNote').textContent = '';
            document.getElementById('lcRetireCount').textContent = j.retire_count.toLocaleString();
            document.getElementById('lcCheckCount').textContent = j.check_count.toLocaleString();
            document.getElementById('lcCheckUnits').textContent = Math.round(j.check_units).toLocaleString();
            document.getElementById('lcRetireRows').innerHTML = j.retire.map(function (r) { return row(r, false); }).join('') || '<tr><td colspan="7">None.</td></tr>';
            document.getElementById('lcCheckRows').innerHTML = j.check.map(function (r) { return row(r, true); }).join('') || '<tr><td colspan="8">None.</td></tr>';
            applyBtn.style.display = j.retire_count ? '' : 'none';
            document.getElementById('lcResults').style.display = '';
        }).catch(function () { scanBtn.disabled = false; document.getElementById('lcScanNote').textContent = 'Check failed.'; });
    });
    applyBtn.addEventListener('click', function () {
        applyBtn.disabled = true;
        document.getElementById('lcApplyNote').textContent = 'Retiring...';
        post('/products/legacy-cleanup/apply').then(function (j) {
            document.getElementById('lcApplyNote').textContent = j.success
                ? ('Retired ' + j.retired.toLocaleString() + '. Undo any time at Admin Action History.')
                : (j.msg || 'Retire failed, nothing changed.');
        }).catch(function () { applyBtn.disabled = false; document.getElementById('lcApplyNote').textContent = 'Retire failed.'; });
    });
})();
</script>
@endsection
