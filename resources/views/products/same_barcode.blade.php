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
.sb-table { background: #fff; border: 1px solid #E6DCCF; }
.sb-table th { text-align: left; background: #FAF6EE; padding: 8px 10px; }
.sb-table td { padding: 8px 10px; border-top: 1px solid #F3ECE1; vertical-align: top; }
tr.sb-group.done td { opacity: .4; }
.sb-keep { background: #F2FAF2; }
.sb-tag { font-size: 11px; font-weight: 700; color: #2E6B2E; }
.sb-small { color: #2B2620; font-size: 12px; }
.sb-btn { border: 0; border-radius: 8px; padding: 6px 12px; font-weight: 600; cursor: pointer; background: #1b6ca8; color: #fff; }
.sb-btn.light { background: #F1EADF; color: #1F1B16; }
.sb-group.done { opacity: .4; }
.sb-tab { display: inline-block; border: 1px solid #E1D7C4; background: #fff; border-radius: 999px; padding: 6px 14px; cursor: pointer; font-weight: 600; color: #1F1B16 !important; margin-right: 6px; }
.sb-tab.on { background: #E8F0F8; color: #13507a !important; border-color: #1b6ca8; }
</style>
<div class="sb-wrap">
    <h1>Same barcode entered twice</h1>
    <p class="sb-sub" id="sbSub">Loading...</p>
    <p><span class="sb-tab on" data-t="easy"></span> <span class="sb-tab" data-t="check"></span>
       <button class="sb-btn" id="sbAll" type="button" style="display:none;">Merge all</button> <span class="sb-small" id="sbAllMsg"></span></p>
    <div id="sbList"></div>
</div>
@endsection

@section('javascript')
<script>
(function () {
    var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var all = [], groups = [], tab = 'easy', armed = false, running = false;
    function tot(x) { return Object.keys(x.stock || {}).reduce(function (a, k) { return a + Math.max(0, x.stock[k]); }, 0); }
    function easy(g) { return g.items.filter(function (x) { return tot(x) > 0; }).length <= 1; }
    function pick() {
        groups = all.filter(function (g) { return !g.done && (tab === 'easy' ? easy(g) : !easy(g)); });
        var e = all.filter(function (g) { return !g.done && easy(g); }).length, c = all.filter(function (g) { return !g.done && !easy(g); }).length;
        document.querySelector('.sb-tab[data-t="easy"]').textContent = 'Easy: stock on one listing or none (' + e + ')';
        document.querySelector('.sb-tab[data-t="check"]').textContent = 'Both have stock (' + c + ')';
    }
    function d8(t) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(t || ''); return m ? m[2] + '/' + m[3] + '/' + m[1].slice(2) : (t || ''); }
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function money(n) { return '$' + Number(n || 0).toFixed(2); }
    function stock(s) { var k = Object.keys(s || {}); return k.length ? k.map(function (x) { return x + ' ' + s[x]; }).join(', ') : 'none'; }
    function who(x) { return esc(x.who) + ', ' + esc(d8(x.created)) + ', ' + money(x.price) + ', stock ' + esc(stock(x.stock)); }
    function render() {
        function after(a) {
            var k = Object.keys(a.stock || {});
            return '<b>' + money(a.price) + '</b>, ' + (k.length ? k.map(function (x) { var v = a.stock[x]; return '<b>' + esc(x) + ' ' + v.qty + '</b> (' + (v.why === 'counted' ? 'Clarissa counted ' : 'last updated ') + esc(d8(v.at)) + ')'; }).join(', ') : 'no stock');
        }
        document.getElementById('sbList').innerHTML = groups.length ? '<table class="sb-table"><thead><tr><th>Record</th><th>After merge</th><th>The listings now</th><th></th></tr></thead><tbody>'
            + groups.map(function (g, gi) {
                return '<tr class="sb-group" data-g="' + gi + '"><td><a href="/products/' + g.items[0].id + '/edit" target="_blank">' + esc(g.items[0].name) + '</a></td>'
                    + '<td>' + after(g.after) + '</td><td style="font-size:12px;">' + g.items.map(who).join('<br>') + '</td>'
                    + '<td><button class="sb-btn" data-a="merge" type="button">Merge</button> <span class="sb-msg"></span></td></tr>';
            }).join('') + '</tbody></table>' : '<p>Nothing left.</p>';
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
        var box = b.closest('tr'), g = groups[+box.dataset.g], msg = box.querySelector('.sb-msg');
        b.disabled = true; msg.textContent = 'Working...';
        if (b.dataset.a === 'skip') {
            Promise.all(g.items.slice(1).map(function (x) { return fetch('/products/fix-barcodes/' + x.id + '/skip', { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' } }); }))
                .then(function () { msg.textContent = 'Skipped.'; box.classList.add('done'); });
            return;
        }
        mergeGroup(g).then(function (ok) { msg.textContent = ok ? 'Merged.' : 'Some did not merge, see Admin Action History.'; box.classList.add('done'); if (ok) g.done = true; });
    });
    document.getElementById('sbAll').addEventListener('click', function () {
        if (running) return;
        if (!armed) { armed = true; render(); setTimeout(function () { armed = false; render(); }, 6000); return; }
        armed = false; running = true;
        var todo = groups.slice(), i = 0, ok = 0, b = this, m = document.getElementById('sbAllMsg');
        b.disabled = true;
        (function next() {
            if (i >= todo.length) { running = false; b.disabled = false; m.textContent = 'Done: ' + ok + ' of ' + todo.length + ' merged. Undo at Admin Action History.'; pick(); render(); return; }
            var g = todo[i++]; b.textContent = 'Merging ' + i + ' of ' + todo.length + '...';
            mergeGroup(g).then(function (r) { if (r) { ok++; g.done = true; } next(); });
        })();
    });
    document.querySelectorAll('.sb-tab').forEach(function (t) { t.addEventListener('click', function () {
        tab = t.dataset.t; document.querySelectorAll('.sb-tab').forEach(function (x) { x.classList.toggle('on', x === t); }); pick(); render();
    }); });
    fetch('/products/same-barcode?json=1', { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
        all = j.groups || []; pick();
        document.getElementById('sbSub').textContent = all.length + ' sealed records were entered more than once with the same barcode. Start with Easy: only one listing (or none) has stock, so merging can not lose any stock. "After merge" is what the record will show: the price of the newest listing, and for stock Clarissa's count if she counted it, otherwise the number that was updated most recently. Check that column, then Merge. You can undo any merge.';
        render();
    });
})();
</script>
@endsection
