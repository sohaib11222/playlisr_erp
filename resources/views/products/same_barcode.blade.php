@extends('layouts.app')
@section('title', 'Same Barcode Twice')

@section('content')
<style>
.sb-wrap { max-width: 1250px; margin: 0 auto; padding: 14px 16px 50px; color: #1F1B16; }
.sb-wrap h1 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
.sb-sub { color: #2B2620; font-size: 14px; margin: 0 0 10px; }
.sb-group { background: #fff; border: 1px solid #E6DCCF; border-radius: 10px; margin-bottom: 10px; }
.sb-head { display: flex; justify-content: space-between; align-items: center; padding: 8px 12px; background: #FAF6EE; border-radius: 10px 10px 0 0; font-size: 13px; }
.sb-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.sb-table td { padding: 7px 12px; border-top: 1px solid #F3ECE1; vertical-align: top; }
.sb-keep { background: #F2FAF2; }
.sb-tag { font-size: 11px; font-weight: 700; color: #2E6B2E; }
.sb-small { color: #2B2620; font-size: 12px; }
.sb-btn { border: 0; border-radius: 8px; padding: 6px 12px; font-weight: 600; cursor: pointer; background: #1b6ca8; color: #fff; }
.sb-btn.light { background: #F1EADF; color: #1F1B16; }
.sb-group.done { opacity: .4; }
</style>
<div class="sb-wrap">
    <h1>Same barcode entered twice</h1>
    <p class="sb-sub" id="sbSub">Loading...</p>
    <p><button class="sb-btn" id="sbAll" type="button" style="display:none;">Merge all</button> <span class="sb-small" id="sbAllMsg"></span></p>
    <div id="sbList"></div>
</div>
@endsection

@section('javascript')
<script>
(function () {
    var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var groups = [], armed = false, running = false;
    function d8(t) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(t || ''); return m ? m[2] + '/' + m[3] + '/' + m[1].slice(2) : (t || ''); }
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function money(n) { return '$' + Number(n || 0).toFixed(2); }
    function stock(s) { var k = Object.keys(s || {}); return k.length ? k.map(function (x) { return x + ' ' + s[x]; }).join(', ') : 'none'; }
    function render() {
        document.getElementById('sbList').innerHTML = groups.map(function (g, gi) {
            return '<div class="sb-group" data-g="' + gi + '"><div class="sb-head"><span>Barcode ' + esc(g.barcode) + ', ' + esc(g.items[0].cat) + '</span><span><button class="sb-btn" data-a="merge" type="button">Merge</button> <button class="sb-btn light" data-a="skip" type="button">Not the same</button> <span class="sb-small sb-msg"></span></span></div><table class="sb-table">'
                + g.items.map(function (x, i) {
                    return '<tr class="' + (i === 0 ? 'sb-keep' : '') + '"><td style="width:38%;"><a href="/products/' + x.id + '/edit" target="_blank">' + esc(x.name) + '</a>' + (i === 0 ? ' <span class="sb-tag">KEPT</span>' : '') + '</td>'
                        + '<td>Entered by <b>' + esc(x.who) + '</b> on ' + esc(d8(x.created)) + '<div class="sb-small">last updated ' + esc(d8(x.updated)) + '</div></td>'
                        + '<td>' + money(x.price) + '</td><td>Stock: ' + esc(stock(x.stock)) + '</td><td>Sold ' + x.sold + '</td></tr>';
                }).join('') + '</table></div>';
        }).join('') || '<p>Nothing left.</p>';
        var b = document.getElementById('sbAll'); b.style.display = groups.length ? '' : 'none';
        if (!running) b.textContent = armed ? 'Click again to merge all ' + groups.length : 'Merge all ' + groups.length;
    }
    function mergeGroup(g) {
        var keep = g.items[0].id, others = g.items.slice(1), i = 0, ok = true;
        return new Promise(function (resolve) {
            (function next() {
                if (i >= others.length) return resolve(ok);
                var x = others[i++];
                fetch('/products/fix-barcodes/' + x.id + '/merge', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: JSON.stringify({ keep_id: keep }) })
                    .then(function (r) { return r.json(); }).then(function (j) { if (!j.success) ok = false; }, function () { ok = false; }).then(next);
            })();
        });
    }
    document.getElementById('sbList').addEventListener('click', function (e) {
        var b = e.target.closest('button[data-a]'); if (!b) return;
        var box = b.closest('.sb-group'), g = groups[+box.dataset.g], msg = box.querySelector('.sb-msg');
        b.disabled = true; msg.textContent = 'Working...';
        if (b.dataset.a === 'skip') {
            Promise.all(g.items.slice(1).map(function (x) { return fetch('/products/fix-barcodes/' + x.id + '/skip', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } }); }))
                .then(function () { msg.textContent = 'Skipped.'; box.classList.add('done'); });
            return;
        }
        mergeGroup(g).then(function (ok) { msg.textContent = ok ? 'Merged.' : 'Some did not merge, see Admin Action History.'; box.classList.add('done'); });
    });
    document.getElementById('sbAll').addEventListener('click', function () {
        if (running) return;
        if (!armed) { armed = true; render(); setTimeout(function () { armed = false; render(); }, 6000); return; }
        armed = false; running = true;
        var todo = groups.slice(), i = 0, ok = 0, b = this, m = document.getElementById('sbAllMsg');
        b.disabled = true;
        (function next() {
            if (i >= todo.length) { running = false; b.disabled = false; m.textContent = 'Done: ' + ok + ' of ' + todo.length + ' merged. Undo at Admin Action History.'; groups = []; render(); return; }
            var g = todo[i++]; b.textContent = 'Merging ' + i + ' of ' + todo.length + '...';
            mergeGroup(g).then(function (r) { if (r) ok++; next(); });
        })();
    });
    fetch('/products/same-barcode?json=1', { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
        groups = j.groups || [];
        document.getElementById('sbSub').textContent = groups.length + ' records have the same barcode on two or more listings in the same format. The green row is kept with its price and stock (the most recently updated listing, but never a 2024 one unless all copies are from 2024); the others merge into it with all their sales. Nothing changes until you press Merge, and every merge can be undone.';
        render();
    });
})();
</script>
@endsection
