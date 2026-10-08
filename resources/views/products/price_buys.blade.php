@extends('layouts.app')
@section('title', 'Price New Buys')

@section('content')
<style>
.pb-wrap { max-width: 1300px; margin: 0 auto; padding: 20px 16px 60px; color: #1F1B16; }
.pb-wrap h1 { font-size: 26px; font-weight: 700; margin: 0 0 6px; }
.pb-sub { color: #6B6155; font-size: 14px; margin: 0 0 14px; max-width: 900px; }
.pb-bar { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
.pb-bar select, .pb-bar input { height: 34px; border: 1px solid #E1D7C4; border-radius: 8px; padding: 0 10px; font-size: 14px; }
.pb-table { width: 100%; border-collapse: collapse; font-size: 13px; background: #fff; border: 1px solid #E6DCCF; border-radius: 10px; }
.pb-table th { background: #FAF6EE; text-align: left; padding: 8px; font-weight: 600; position: sticky; top: 0; }
.pb-table td { padding: 7px 8px; border-top: 1px solid #F3ECE1; vertical-align: top; }
.pb-table input, .pb-table select { width: 100%; height: 32px; border: 1px solid #E1D7C4; border-radius: 6px; padding: 0 8px; font-size: 13px; }
.pb-meta { color: #6B6155; font-size: 12px; line-height: 1.5; }
.pb-old { color: #B71C1C; font-weight: 600; }
.pb-btn { border: 0; border-radius: 7px; padding: 7px 12px; font-weight: 600; cursor: pointer; white-space: nowrap; }
.pb-save { background: #1F1B16; color: #FFE8A3; }
.pb-miss { background: #F1EADF; color: #1F1B16; margin-top: 6px; }
.pb-msg { font-size: 12px; margin-top: 4px; }
.pb-done td { opacity: .45; }
</style>

<div class="pb-wrap">
    <h1>Price New Buys</h1>
    <p class="pb-sub">Everything bought from customers that hasn't been priced yet. Price the copy right here, on the record Buy from Customer already made. Don't make a new listing. Saving puts it on sale in the store and on the website. If you can't find the copy, click "Can't find it."</p>

    <div class="pb-bar">
        <select id="pbStore"><option value="">Both stores</option><option value="hollywood">Hollywood</option><option value="pico">Pico</option></select>
        <input id="pbSearch" type="text" placeholder="Search name, offer #, buyer">
        <span class="pb-meta" id="pbCount"></span>
    </div>

    <table class="pb-table">
        <thead><tr>
            <th style="width:22%">Name (Title)</th>
            <th style="width:14%">Artist</th>
            <th style="width:14%">Format</th>
            <th style="width:12%">Barcode (if it has one)</th>
            <th style="width:8%">Price $</th>
            <th style="width:20%">Bought</th>
            <th style="width:10%"></th>
        </tr></thead>
        <tbody id="pbRows"><tr><td colspan="7">Loading...</td></tr></tbody>
    </table>
</div>
@endsection

@section('javascript')
<script>
(function () {
    var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var categories = @json($categories);
    var rows = [];
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function catOptions(sel) {
        var h = '<option value="">Pick format</option>';
        Object.keys(categories).forEach(function (id) {
            h += '<option value="' + id + '"' + (Number(id) === Number(sel) ? ' selected' : '') + '>' + esc(categories[id]) + '</option>';
        });
        return h;
    }
    function render() {
        var store = document.getElementById('pbStore').value;
        var q = document.getElementById('pbSearch').value.toLowerCase().trim();
        var list = rows.filter(function (r) {
            if (store && String(r.store || '').toLowerCase().indexOf(store) === -1) return false;
            if (q && (r.name + ' ' + r.offer + ' ' + r.bought_by + ' ' + (r.artist || '')).toLowerCase().indexOf(q) === -1) return false;
            return true;
        });
        document.getElementById('pbCount').textContent = list.length + ' waiting to be priced';
        document.getElementById('pbRows').innerHTML = list.map(function (r) {
            var age = r.days >= 14 ? '<span class="pb-old">' + r.days + ' days ago</span>' : (r.days + ' days ago');
            return '<tr data-id="' + r.id + '">'
                + '<td><input class="pb-name" value="' + esc(r.name) + '"></td>'
                + '<td><input class="pb-artist" value="' + esc(r.artist && !/^(n\/a|-)$/i.test(r.artist) ? r.artist : '') + '" placeholder="Artist"></td>'
                + '<td><select class="pb-cat">' + catOptions(r.category_id) + '</select></td>'
                + '<td><input class="pb-barcode" placeholder="scan or leave blank"></td>'
                + '<td><input class="pb-price" type="number" step="0.01" min="0" placeholder="0.00"></td>'
                + '<td class="pb-meta">' + esc(r.store || '') + ' &middot; ' + esc(r.offer) + '<br>' + esc(r.type) + (r.grade && r.grade !== '—' ? ' &middot; grade ' + esc(r.grade) : '') + '<br>by ' + esc(r.bought_by) + ', ' + age + ' &middot; paid $' + Number(r.cost || 0).toFixed(2) + '</td>'
                + '<td><button class="pb-btn pb-save" type="button">Save &amp; put on sale</button><br><button class="pb-btn pb-miss" type="button">Can\'t find it</button><div class="pb-msg"></div></td>'
                + '</tr>';
        }).join('') || '<tr><td colspan="7">Nothing waiting. Nice.</td></tr>';
    }
    function post(url, body) {
        return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: JSON.stringify(body || {}) }).then(function (r) { return r.json(); });
    }
    document.getElementById('pbRows').addEventListener('click', function (e) {
        var tr = e.target.closest('tr[data-id]');
        if (!tr) return;
        var id = tr.getAttribute('data-id');
        var msg = tr.querySelector('.pb-msg');
        if (e.target.classList.contains('pb-save')) {
            e.target.disabled = true; msg.textContent = 'Saving...';
            post('/price-new-buys/' + id + '/save', {
                name: tr.querySelector('.pb-name').value,
                artist: tr.querySelector('.pb-artist').value,
                category_id: tr.querySelector('.pb-cat').value,
                barcode: tr.querySelector('.pb-barcode').value,
                price: tr.querySelector('.pb-price').value
            }).then(function (j) {
                if (j.success) { tr.classList.add('pb-done'); msg.textContent = 'On sale as "' + j.name + '"'; rows = rows.filter(function (r) { return String(r.id) !== id; }); document.getElementById('pbCount').textContent = rows.length + ' waiting to be priced'; }
                else { e.target.disabled = false; msg.textContent = j.msg || 'Save failed.'; }
            }).catch(function () { e.target.disabled = false; msg.textContent = 'Save failed.'; });
        }
        if (e.target.classList.contains('pb-miss')) {
            e.target.disabled = true; msg.textContent = 'Removing...';
            post('/price-new-buys/' + id + '/missing').then(function (j) {
                if (j.success) { tr.classList.add('pb-done'); msg.textContent = 'Removed from the list'; rows = rows.filter(function (r) { return String(r.id) !== id; }); }
                else { e.target.disabled = false; msg.textContent = j.msg || 'Failed.'; }
            }).catch(function () { e.target.disabled = false; msg.textContent = 'Failed.'; });
        }
    });
    document.getElementById('pbStore').addEventListener('change', render);
    document.getElementById('pbSearch').addEventListener('input', render);
    fetch('/price-new-buys/data', { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) { rows = j.rows || []; render(); });
})();
</script>
@endsection
