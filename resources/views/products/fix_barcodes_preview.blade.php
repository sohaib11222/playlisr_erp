@extends('layouts.app')
@section('title', 'Entered Twice')

@section('content')
<style>
.pv-wrap { max-width: 1200px; margin: 0 auto; padding: 24px 16px 60px; color: #1F1B16; }
.pv-wrap h1 { font-size: 26px; font-weight: 700; margin: 0 0 6px; }
.pv-sub { color: #6B6155; font-size: 15px; margin: 0 0 12px; }
.pv-tabs { display: flex; gap: 8px; margin-bottom: 12px; }
.pv-tab { border: 1px solid #E1D7C4; background: #fff; border-radius: 999px; padding: 6px 14px; cursor: pointer; font-weight: 600; }
.pv-tab.on { background: #1F1B16; color: #FFE8A3; border-color: #1F1B16; }
.pv-tablewrap { max-height: 72vh; overflow: auto; border: 1px solid #E6DCCF; border-radius: 10px; background: #fff; }
.pv-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.pv-table th { position: sticky; top: 0; background: #FAF6EE; text-align: left; padding: 8px; font-weight: 600; }
.pv-table td { padding: 7px 8px; border-top: 1px solid #F3ECE1; vertical-align: top; }
.pv-table td.arrow { color: #8E8273; }
.pv-small { color: #8E8273; font-size: 12px; }
.pv-diff { color: #8E1B1B; font-weight: 700; }
.pv-btn { border: 0; border-radius: 8px; padding: 6px 10px; font-weight: 600; cursor: pointer; background: #1F1B16; color: #FFE8A3; white-space: nowrap; margin: 0 0 4px; }
.pv-btn.light { background: #F1EADF; color: #1F1B16; }
.pv-msg { font-size: 12px; color: #2E6B2E; }
tr.pv-done td { opacity: .4; }
</style>
<div class="pv-wrap">
    <h1>Records entered twice</h1>
    <p class="pv-sub" id="pvSub">Loading, this takes about a minute...</p>
    <div class="pv-tabs"><span class="pv-tab on" data-t="safe">Safe to merge</span><span class="pv-tab" data-t="check">Check by hand</span><a class="pv-tab" href="/products/fix-barcodes/scan?csv=1">Download all (CSV)</a></div>
    <div class="pv-tablewrap"><table class="pv-table">
        <thead><tr><th>Fake-SKU listing (goes away)</th><th>Price</th><th>HW / Pico</th><th>Sold</th><th></th><th>Real-barcode listing (kept)</th><th>Price</th><th>HW / Pico</th><th>Sold</th><th></th></tr></thead>
        <tbody id="pvRows"></tbody>
    </table></div>
</div>
@endsection

@section('javascript')
<script>
(function () {
    var pairs = [], tab = 'safe';
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function money(n) { return n ? '$' + Number(n).toFixed(2) : '$0'; }
    function same(x) { return Math.abs(x.price - x.keep_price) < 0.01; }
    function safe(x) { return x.exact && same(x); }
    function render() {
        var list = pairs.filter(function (x) { return tab === 'safe' ? safe(x) : !safe(x); });
        document.getElementById('pvRows').innerHTML = list.map(function (x) {
            var d = same(x) ? '' : ' class="pv-diff"';
            var nm = x.exact ? '' : ' <span class="pv-diff">(names differ)</span>';
            return '<tr><td><a href="/products/' + x.id + '/edit" target="_blank">' + esc(x.name) + '</a><div class="pv-small">' + esc(x.sku) + ', ' + esc(x.cat) + '</div></td>'
                + '<td' + d + '>' + money(x.price) + '</td><td>' + x.hw + ' / ' + x.pico + '</td><td>' + x.sold + '</td><td class="arrow">into</td>'
                + '<td><a href="/products/' + x.keep_id + '/edit" target="_blank">' + esc(x.keep_name) + '</a>' + nm + '<div class="pv-small">' + esc(x.keep_sku) + '</div></td>'
                + '<td' + d + '>' + money(x.keep_price) + '</td><td>' + x.keep_hw + ' / ' + x.keep_pico + '</td><td>' + x.keep_sold + '</td>'
                + '<td class="pv-act" data-id="' + x.id + '" data-keep="' + x.keep_id + '"><button class="pv-btn" data-a="merge" type="button">Merge</button> <button class="pv-btn light" data-a="skip" type="button">Not the same</button><div class="pv-msg"></div></td></tr>';
        }).join('') || '<tr><td colspan="10">Nothing here.</td></tr>';
    }
    var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    document.getElementById('pvRows').addEventListener('click', function (e) {
        var b = e.target.closest('.pv-btn'); if (!b) return;
        var td = b.closest('.pv-act'), id = td.getAttribute('data-id'), msg = td.querySelector('.pv-msg'), a = b.getAttribute('data-a');
        td.querySelectorAll('.pv-btn').forEach(function (x) { x.disabled = true; });
        msg.textContent = 'Working...';
        fetch('/products/fix-barcodes/' + id + '/' + a, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: JSON.stringify({ keep_id: td.getAttribute('data-keep') }) })
            .then(function (r) { return r.json(); }).then(function (j) {
                if (j.success) { msg.textContent = a === 'merge' ? 'Merged.' : 'Marked not the same.'; td.closest('tr').classList.add('pv-done'); pairs = pairs.filter(function (x) { return String(x.id) !== String(id); }); }
                else { msg.textContent = j.msg || 'Did not work.'; td.querySelectorAll('.pv-btn').forEach(function (x) { x.disabled = false; }); }
            }).catch(function () { msg.textContent = 'Did not work, try again.'; td.querySelectorAll('.pv-btn').forEach(function (x) { x.disabled = false; }); });
    });
    document.querySelector('.pv-tabs').addEventListener('click', function (e) {
        var t = e.target.closest('.pv-tab[data-t]'); if (!t) return;
        tab = t.getAttribute('data-t');
        document.querySelectorAll('.pv-tab[data-t]').forEach(function (x) { x.classList.toggle('on', x === t); });
        render();
    });
    fetch('/products/fix-barcodes/scan?all=1', { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
        pairs = j.sample || [];
        var s = pairs.filter(safe).length;
        document.getElementById('pvSub').textContent = pairs.length.toLocaleString() + ' records look entered twice: once with a made-up SKU and once with the real barcode. ' + s + ' are safe to merge (same name and same price). ' + (pairs.length - s) + ' need a look (different price or a different word in the name, like Box Set or Acoustic). Nothing changes until you press Merge on a row. Open both titles to compare if unsure.';
        render();
    }).catch(function () { document.getElementById('pvSub').textContent = 'Load failed, refresh to try again.'; });
})();
</script>
@endsection
