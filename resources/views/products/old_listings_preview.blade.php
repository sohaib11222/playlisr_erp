@extends('layouts.app')
@section('title', 'Old Listings')

@section('content')
<style>
.ol-wrap { max-width: 1250px; margin: 0 auto; padding: 14px 16px 50px; color: #1F1B16; }
.ol-wrap h1 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
.ol-sub { color: #6B6155; font-size: 14px; margin: 0 0 10px; }
.ol-tabs { display: flex; gap: 8px; margin-bottom: 10px; align-items: center; flex-wrap: wrap; }
.ol-tab { border: 1px solid #E1D7C4; background: #fff; border-radius: 999px; padding: 6px 14px; cursor: pointer; font-weight: 600; color: #1F1B16 !important; }
.ol-tab.on { background: #E8F0F8; color: #13507a !important; border-color: #1b6ca8; }
.ol-table { width: 100%; border-collapse: collapse; font-size: 13px; background: #fff; border: 1px solid #E6DCCF; }
.ol-table th { position: sticky; top: 0; background: #FAF6EE; text-align: left; padding: 7px 8px; }
.ol-table td { padding: 7px 8px; border-top: 1px solid #F3ECE1; vertical-align: top; }
.ol-small { color: #8E8273; font-size: 12px; }
.ol-diff { color: #8E1B1B; font-weight: 700; }
.ol-btn { border: 0; border-radius: 8px; padding: 6px 10px; font-weight: 600; cursor: pointer; background: #1b6ca8; color: #fff; }
.ol-btn.light { background: #F1EADF; color: #1F1B16; }
tr.done td { opacity: .4; }
</style>
<div class="ol-wrap">
    <h1>Old listings: same record twice</h1>
    <p class="ol-sub" id="olSub">Loading...</p>
    <div class="ol-tabs"><span class="ol-tab on" data-t="same">Same price</span><span class="ol-tab" data-t="diff">Different price</span>
        <button class="ol-btn" id="olAll" type="button">Merge all on this tab</button><span class="ol-small" id="olAllMsg"></span></div>
    <table class="ol-table"><thead><tr><th>Store</th><th>Old listing (goes away)</th><th>Price</th><th>Stock</th><th>Sold</th><th>Kept listing</th><th>Price</th><th>Stock</th><th>Sold</th><th></th></tr></thead><tbody id="olRows"></tbody></table>
</div>
@endsection

@section('javascript')
<script>
(function () {
    var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var rows = [], tab = 'same', armed = false, running = false;
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function money(n) { return '$' + Number(n || 0).toFixed(2); }
    function same(x) { return Math.abs(x.old_price - x.new_price) < 0.01; }
    function list() { return rows.filter(function (x) { return tab === 'same' ? same(x) : !same(x); }); }
    function render() {
        var d;
        document.getElementById('olRows').innerHTML = list().map(function (x) {
            d = same(x) ? '' : ' class="ol-diff"';
            return '<tr data-id="' + x.old_id + '" data-keep="' + x.new_id + '"><td>' + esc(x.store) + '</td>'
                + '<td><a href="/products/' + x.old_id + '/edit" target="_blank">' + esc(x.old) + '</a><div class="ol-small">' + esc(x.old_sku) + ', ' + esc(x.old_cat) + ', entered ' + esc(x.old_created) + '</div></td>'
                + '<td' + d + '>' + money(x.old_price) + '</td><td>' + x.old_qty + '</td><td>' + x.old_sold + '</td>'
                + '<td><a href="/products/' + x.new_id + '/edit" target="_blank">' + esc(x.new) + '</a><div class="ol-small">' + esc(x.new_sku) + ', ' + esc(x.cat) + ', entered ' + esc(x.new_created) + '</div></td>'
                + '<td' + d + '>' + money(x.new_price) + '</td><td>' + x.new_qty + '</td><td>' + x.new_sold + '</td>'
                + '<td><button class="ol-btn" data-a="merge" type="button">Merge</button> <button class="ol-btn light" data-a="skip" type="button">Not the same</button><div class="ol-small ol-msg"></div></td></tr>';
        }).join('') || '<tr><td colspan="10">Nothing here.</td></tr>';
        var n = list().length, b = document.getElementById('olAll');
        b.style.display = n ? '' : 'none';
        if (!running) b.textContent = armed ? 'Click again to merge all ' + n : 'Merge all ' + n + ' on this tab';
    }
    function merge(x) {
        return fetch('/products/fix-barcodes/' + x.old_id + '/merge', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: JSON.stringify({ keep_id: x.new_id }) }).then(function (r) { return r.json(); });
    }
    document.getElementById('olRows').addEventListener('click', function (e) {
        var b = e.target.closest('button[data-a]'); if (!b) return;
        var tr = b.closest('tr'), id = +tr.dataset.id, x = rows.find(function (r) { return r.old_id === id; }), msg = tr.querySelector('.ol-msg');
        b.disabled = true; msg.textContent = 'Working...';
        var p = b.dataset.a === 'merge' ? merge(x) : fetch('/products/fix-barcodes/' + id + '/skip', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } }).then(function (r) { return r.json(); });
        p.then(function (j) { if (j.success) { msg.textContent = b.dataset.a === 'merge' ? 'Merged.' : 'Skipped.'; tr.classList.add('done'); rows = rows.filter(function (r) { return r.old_id !== id; }); } else { msg.textContent = j.msg || 'Did not work.'; b.disabled = false; } });
    });
    document.getElementById('olAll').addEventListener('click', function () {
        if (running) return;
        if (!armed) { armed = true; render(); setTimeout(function () { armed = false; render(); }, 6000); return; }
        armed = false; running = true;
        var todo = list(), i = 0, ok = 0, bad = 0, b = this, m = document.getElementById('olAllMsg');
        b.disabled = true;
        (function next() {
            if (i >= todo.length) { running = false; b.disabled = false; m.textContent = 'Done: ' + ok + ' merged' + (bad ? ', ' + bad + ' failed' : '') + '. Undo at Admin Action History.'; render(); return; }
            var x = todo[i++]; b.textContent = 'Merging ' + i + ' of ' + todo.length + '...';
            merge(x).then(function (j) { if (j.success) { ok++; rows = rows.filter(function (r) { return r.old_id !== x.old_id; }); } else bad++; }, function () { bad++; }).then(next);
        })();
    });
    document.querySelector('.ol-tabs').addEventListener('click', function (e) {
        var t = e.target.closest('.ol-tab'); if (!t) return;
        tab = t.dataset.t; document.querySelectorAll('.ol-tab').forEach(function (x) { x.classList.toggle('on', x === t); }); render();
    });
    fetch('/products/old-listings-with-stock?all=1', { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
        var seen = {};
        rows = (j.rows || []).filter(function (x) { if (x.group > 2 || seen[x.old_id]) return false; seen[x.old_id] = 1; return true; });
        var s = rows.filter(same).length;
        document.getElementById('olSub').textContent = rows.length + ' old listings are the same record as a listing with the real barcode (same barcode, or a made-up SKU next to the real one), same format. ' + s + ' have the same price. Merging moves all sales history onto the kept listing and can be undone. Nothing changes until you press Merge.';
        render();
    });
})();
</script>
@endsection
