@extends('layouts.app')
@section('title', '2024 Listings Review')

@section('content')
<style>
.rv-wrap { max-width: 1500px; margin: 0 auto; padding: 20px 16px 60px; color: #1F1B16; }
.rv-wrap h1 { font-size: 26px; font-weight: 700; margin: 0 0 6px; }
.rv-sub { color: #6B6155; font-size: 14px; margin: 0 0 12px; max-width: 1000px; }
.rv-bar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 10px; }
.rv-bar input, .rv-bar select { height: 34px; border: 1px solid #E1D7C4; border-radius: 8px; padding: 0 10px; font-size: 14px; }
.rv-chip { border: 1px solid #E1D7C4; background: #fff; border-radius: 999px; padding: 5px 11px; font-size: 13px; cursor: pointer; user-select: none; }
.rv-chip.on { background: #1F1B16; color: #FFE8A3; border-color: #1F1B16; }
.rv-btn { border: 0; border-radius: 8px; padding: 8px 14px; font-weight: 600; cursor: pointer; background: #F1EADF; }
.rv-tablewrap { max-height: 70vh; overflow: auto; border: 1px solid #E6DCCF; border-radius: 10px; background: #fff; }
.rv-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.rv-table th { position: sticky; top: 0; background: #FAF6EE; text-align: left; padding: 7px 8px; font-weight: 600; cursor: pointer; white-space: nowrap; }
.rv-table td { padding: 6px 8px; border-top: 1px solid #F3ECE1; vertical-align: top; }
.rv-flag { display: inline-block; background: #FDECEA; color: #8E1B1B; border-radius: 6px; padding: 1px 6px; margin: 1px 2px 1px 0; font-size: 11.5px; white-space: nowrap; }
.rv-count { color: #6B6155; font-size: 13px; }
</style>

<div class="rv-wrap">
    <h1>2024 listings review</h1>
    <p class="rv-sub">Every active listing created in 2024, with the problems we found on Deftones flagged. Click a flag to filter, click a column to sort, click a name to open and fix it. Nothing here changes data. Download gives you everything in the current filter as a spreadsheet.</p>

    <div class="rv-bar">
        <input id="rvSearch" type="text" placeholder="Search name, artist, SKU" style="min-width:240px;">
        <select id="rvStock"><option value="">Any stock</option><option value="in">In stock</option><option value="out">No stock</option></select>
        <select id="rvCat"><option value="">All formats</option></select>
        <button class="rv-btn" id="rvCsv" type="button">Download (CSV)</button>
        <span class="rv-count" id="rvCount">Loading...</span>
    </div>
    <div class="rv-bar" id="rvFlags"></div>

    <div class="rv-tablewrap">
        <table class="rv-table">
            <thead><tr>
                <th data-k="name">Product</th><th data-k="artist">Artist</th><th data-k="sku">SKU</th><th data-k="cat">Format</th><th data-k="genre">Genre</th>
                <th data-k="cost">Cost</th><th data-k="price">Price</th><th data-k="hw">HW</th><th data-k="pico">Pico</th><th data-k="sold">Sold</th><th data-k="last_sold">Last sold</th>
                <th data-k="created">Created</th><th data-k="by">By</th><th data-k="flags">Flags</th>
            </tr></thead>
            <tbody id="rvRows"></tbody>
        </table>
    </div>
</div>
@endsection

@section('javascript')
<script>
(function () {
    var rows = [], on = {}, sortK = 'id', sortDir = 1, LIMIT = 1000;
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function money(n) { return n ? '$' + Number(n).toFixed(2) : ''; }
    function filtered() {
        var q = document.getElementById('rvSearch').value.toLowerCase().trim();
        var st = document.getElementById('rvStock').value, cat = document.getElementById('rvCat').value;
        var flags = Object.keys(on).filter(function (k) { return on[k]; });
        var list = rows.filter(function (r) {
            var stock = r.hw + r.pico + r.other;
            if (st === 'in' && stock <= 0) return false;
            if (st === 'out' && stock > 0) return false;
            if (cat && r.cat !== cat) return false;
            for (var i = 0; i < flags.length; i++) { if (r.flags.indexOf(flags[i]) === -1) return false; }
            if (q && ((r.name || '') + ' ' + (r.artist || '') + ' ' + (r.sku || '')).toLowerCase().indexOf(q) === -1) return false;
            return true;
        });
        list.sort(function (a, b) {
            var x = sortK === 'flags' ? a.flags.length : a[sortK], y = sortK === 'flags' ? b.flags.length : b[sortK];
            if (x == null) x = ''; if (y == null) y = '';
            return (typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y))) * sortDir;
        });
        return list;
    }
    function render() {
        var list = filtered();
        document.getElementById('rvCount').textContent = list.length.toLocaleString() + ' of ' + rows.length.toLocaleString() + ' listings' + (list.length > LIMIT ? ' (showing first ' + LIMIT + ', download for all)' : '');
        document.getElementById('rvRows').innerHTML = list.slice(0, LIMIT).map(function (r) {
            return '<tr><td><a href="/products/' + r.id + '/edit" target="_blank">' + esc(r.name) + '</a></td><td>' + esc(r.artist) + '</td><td>' + esc(r.sku) + '</td><td>' + esc(r.cat) + '</td><td>' + esc(r.genre) + '</td>'
                + '<td>' + money(r.cost) + '</td><td>' + money(r.price) + '</td><td>' + (r.hw || '') + '</td><td>' + (r.pico || '') + '</td><td>' + (r.sold || '') + '</td><td>' + esc(r.last_sold || '') + '</td>'
                + '<td>' + esc(r.created) + '</td><td>' + esc(r.by) + '</td><td>' + r.flags.map(function (f) { return '<span class="rv-flag">' + esc(f) + '</span>'; }).join('') + '</td></tr>';
        }).join('') || '<tr><td colspan="14">Nothing matches.</td></tr>';
    }
    function buildFlags() {
        var counts = {};
        rows.forEach(function (r) { r.flags.forEach(function (f) { counts[f] = (counts[f] || 0) + 1; }); });
        document.getElementById('rvFlags').innerHTML = Object.keys(counts).sort(function (a, b) { return counts[b] - counts[a]; }).map(function (f) {
            return '<span class="rv-chip" data-f="' + esc(f) + '">' + esc(f) + ' (' + counts[f].toLocaleString() + ')</span>';
        }).join('');
        var cats = {}; rows.forEach(function (r) { if (r.cat) cats[r.cat] = 1; });
        document.getElementById('rvCat').innerHTML += Object.keys(cats).sort().map(function (c) { return '<option>' + esc(c) + '</option>'; }).join('');
    }
    document.getElementById('rvFlags').addEventListener('click', function (e) {
        var c = e.target.closest('.rv-chip'); if (!c) return;
        var f = c.getAttribute('data-f'); on[f] = !on[f]; c.classList.toggle('on', on[f]); render();
    });
    document.querySelector('.rv-table thead').addEventListener('click', function (e) {
        var th = e.target.closest('th'); if (!th) return;
        var k = th.getAttribute('data-k'); sortDir = (sortK === k) ? -sortDir : 1; sortK = k; render();
    });
    ['rvSearch', 'rvStock', 'rvCat'].forEach(function (id) { document.getElementById(id).addEventListener('input', render); });
    document.getElementById('rvCsv').addEventListener('click', function () {
        var cols = ['id', 'name', 'artist', 'sku', 'cat', 'genre', 'cost', 'price', 'hw', 'pico', 'sold', 'last_sold', 'created', 'by', 'flags'];
        var q = function (v) { v = v == null ? '' : (Array.isArray(v) ? v.join('; ') : String(v)); return '"' + v.replace(/"/g, '""') + '"'; };
        var csv = cols.join(',') + '\n' + filtered().map(function (r) { return cols.map(function (c) { return q(r[c]); }).join(','); }).join('\n')
            + '\n';
        var a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
        a.download = '2024-listings-review.csv';
        a.click();
    });
    fetch('/products/review-2024/data', { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
        rows = j.rows || []; buildFlags(); render();
    }).catch(function () { document.getElementById('rvCount').textContent = 'Load failed, refresh to try again.'; });
})();
</script>
@endsection
