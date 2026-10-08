@extends('layouts.app')
@section('title', 'Fix Barcodes')

@section('content')
<style>
.fb-wrap { max-width: 980px; margin: 0 auto; padding: 24px 16px 60px; color: #1F1B16; }
.fb-wrap h1 { font-size: 26px; font-weight: 700; margin: 0 0 6px; }
.fb-sub { color: #6B6155; font-size: 15px; margin: 0 0 16px; }
.fb-card { background: #fff; border: 1px solid #E6DCCF; border-radius: 12px; padding: 14px 16px; margin-bottom: 12px; }
.fb-card.done { opacity: .45; }
.fb-title { font-size: 17px; font-weight: 700; }
.fb-meta { color: #6B6155; font-size: 13px; margin-top: 2px; }
.fb-h { font-size: 12px; text-transform: uppercase; letter-spacing: .03em; color: #8E8273; margin: 12px 0 4px; }
.fb-opt { display: flex; align-items: center; gap: 10px; padding: 7px 0; border-top: 1px solid #F3ECE1; font-size: 14px; }
.fb-opt > span { flex: 1; }
.fb-btn { border: 0; border-radius: 8px; padding: 7px 12px; font-weight: 600; cursor: pointer; background: #1F1B16; color: #FFE8A3; white-space: nowrap; }
.fb-btn.light { background: #F1EADF; color: #1F1B16; }
.fb-row { display: flex; gap: 8px; margin-top: 10px; align-items: center; flex-wrap: wrap; }
.fb-row input { height: 34px; border: 1px solid #E1D7C4; border-radius: 8px; padding: 0 10px; font-size: 14px; width: 200px; }
.fb-msg { font-size: 13px; color: #2E6B2E; }
.fb-msg.bad { color: #8E1B1B; }
.fb-none { font-size: 14px; color: #8E8273; padding-top: 6px; }
.fb-nav { display: flex; gap: 8px; align-items: center; margin: 16px 0; }
</style>
<div class="fb-wrap">
    <h1>Fix barcodes</h1>
    <p class="fb-sub" id="fbSub">Loading...</p>
    <div id="fbList"></div>
    <div class="fb-nav"><button class="fb-btn light" id="fbPrev" type="button">Previous</button><button class="fb-btn light" id="fbNext" type="button">Next</button><span class="fb-meta" id="fbPage"></span></div>
</div>
@endsection

@section('javascript')
<script>
(function () {
    var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var offset = 0, done = 0, data = null;
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function money(n) { return n ? '$' + Number(n).toFixed(2) : ''; }
    function post(url, body) {
        return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: JSON.stringify(body || {}) }).then(function (r) { return r.json(); });
    }
    function stock(hw, pico) { return 'HW ' + hw + ', Pico ' + pico; }
    function card(r) {
        var h = '<div class="fb-card" data-id="' + r.id + '">'
            + '<div class="fb-title"><a href="/products/' + r.id + '/edit" target="_blank">' + esc(r.name) + '</a></div>'
            + '<div class="fb-meta">' + esc(r.artist || '') + (r.artist ? ', ' : '') + esc(r.cat) + ', SKU ' + esc(r.sku) + ', ' + stock(r.hw, r.pico) + (r.price ? ', ' + money(r.price) : '') + '</div>';
        if (r.twins.length) {
            h += '<div class="fb-h">Already in the ERP with a real barcode</div>';
            r.twins.forEach(function (t) {
                h += '<div class="fb-opt"><span><a href="/products/' + t.id + '/edit" target="_blank">' + esc(t.name) + '</a><br><small>' + esc(t.sku) + ', ' + stock(t.hw, t.pico) + (t.price ? ', ' + money(t.price) : '') + '</small></span>'
                    + '<button class="fb-btn" data-act="merge" data-keep="' + t.id + '" type="button">Merge into this</button></div>';
            });
        }
        if (r.distributors.length) {
            h += '<div class="fb-h">Distributor barcodes for this album</div>';
            r.distributors.forEach(function (d) {
                var sup = Object.keys(d.suppliers).map(function (k) { return k + ' ' + money(d.suppliers[k]); }).join(', ');
                h += '<div class="fb-opt"><span>' + esc(d.title || '') + ' (' + esc(d.format || '') + ')<br><small>' + esc(d.upc) + ', ' + esc(sup) + '</small></span>'
                    + '<button class="fb-btn" data-act="save" data-upc="' + esc(d.upc) + '" type="button">Use this barcode</button></div>';
            });
        }
        if (!r.twins.length && !r.distributors.length) h += '<div class="fb-none">No match found. Type the barcode from the item.</div>';
        h += '<div class="fb-row"><input type="text" inputmode="numeric" placeholder="Type or scan barcode"><button class="fb-btn light" data-act="manual" type="button">Save barcode</button>'
            + '<button class="fb-btn light" data-act="skip" type="button">Skip</button><span class="fb-msg"></span></div></div>';
        return h;
    }
    function load() {
        document.getElementById('fbList').innerHTML = '';
        document.getElementById('fbSub').textContent = 'Loading...';
        fetch('/products/fix-barcodes/data?offset=' + offset, { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
            data = j;
            document.getElementById('fbSub').textContent = j.total.toLocaleString() + ' sealed listings have a made-up SKU instead of the real barcode (' + j.in_stock.toLocaleString() + ' in stock, shown first). Merge it into the real listing, pick the right barcode, or type it in. Every change can be undone in Admin Action History.';
            document.getElementById('fbList').innerHTML = j.rows.map(card).join('') || '<div class="fb-none">Nothing left to fix.</div>';
            done = 0;
            document.getElementById('fbPage').textContent = (j.total ? (offset + 1) + ' to ' + Math.min(j.total, offset + j.rows.length) : 0) + ' of ' + j.total.toLocaleString();
            document.getElementById('fbPrev').disabled = offset === 0;
            document.getElementById('fbNext').disabled = offset + j.rows.length >= j.total;
        }).catch(function () { document.getElementById('fbSub').textContent = 'Load failed, refresh to try again.'; });
    }
    document.getElementById('fbList').addEventListener('click', function (e) {
        var b = e.target.closest('button[data-act]'); if (!b) return;
        var c = b.closest('.fb-card'), id = c.getAttribute('data-id'), msg = c.querySelector('.fb-msg'), act = b.getAttribute('data-act');
        var req;
        if (act === 'merge') req = post('/products/fix-barcodes/' + id + '/merge', { keep_id: b.getAttribute('data-keep') });
        else if (act === 'save') req = post('/products/fix-barcodes/' + id + '/save', { barcode: b.getAttribute('data-upc') });
        else if (act === 'manual') req = post('/products/fix-barcodes/' + id + '/save', { barcode: c.querySelector('input').value });
        else req = post('/products/fix-barcodes/' + id + '/skip');
        b.disabled = true; msg.className = 'fb-msg'; msg.textContent = 'Saving...';
        req.then(function (j) {
            b.disabled = false;
            if (j.success) { msg.textContent = act === 'skip' ? 'Skipped.' : (j.msg || 'Done.'); if (!c.classList.contains('done')) done++; c.classList.add('done'); }
            else { msg.className = 'fb-msg bad'; msg.textContent = j.msg || 'Did not work.'; }
        }).catch(function () { b.disabled = false; msg.className = 'fb-msg bad'; msg.textContent = 'Did not work, try again.'; });
    });
    document.getElementById('fbPrev').addEventListener('click', function () { offset = Math.max(0, offset - 25); load(); });
    document.getElementById('fbNext').addEventListener('click', function () { offset += 25 - done; load(); window.scrollTo(0, 0); });
    load();
})();
</script>
@endsection
