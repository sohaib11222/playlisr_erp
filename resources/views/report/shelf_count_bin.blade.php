@extends('layouts.app')
@section('title', 'Count Bin')

@section('content')
<style>
.sc-wrap { max-width: 760px; margin: 0 auto; padding: 18px 16px 80px; color: #1F1B16; }
.sc-wrap h1 { font-size: 22px; font-weight: 700; margin: 4px 0; }
.sc-small { color: #2B2620; font-size: 13px; }
.sc-scan { display: flex; gap: 8px; margin: 12px 0 6px; position: sticky; top: 0; background: #F7F4EE; padding: 8px 0; z-index: 5; }
.sc-scan input { flex: 1; height: 52px; font-size: 20px; border: 2px solid #1F1B16; border-radius: 12px; padding: 0 14px; }
.sc-btn { border: 0; border-radius: 10px; padding: 0 20px; height: 52px; font-size: 17px; font-weight: 700; background: #2E6B2E; color: #fff; cursor: pointer; }
.sc-btn.light { background: #F1EADF; color: #1F1B16; }
.sc-flash { min-height: 24px; font-size: 15px; font-weight: 600; }
.sc-flash.bad { color: #8E1B1B; }
.sc-flash.ok { color: #2E6B2E; }
.sc-item { display: flex; align-items: center; gap: 10px; background: #fff; border: 1px solid #E6DCCF; border-radius: 10px; padding: 10px 12px; margin-bottom: 6px; }
.sc-item .t { flex: 1; font-size: 15px; }
.sc-item .n { font-size: 22px; font-weight: 800; min-width: 34px; text-align: center; }
.sc-item.seen { border-color: #2E6B2E; background: #F2FAF2; }
.sc-item.none { border-color: #8E1B1B; background: #FBF1F1; }
.sc-item.none .n { font-size: 15px; color: #8E1B1B; }
.sc-n { width: 64px; height: 44px; border-radius: 10px; border: 2px solid #1F1B16; font-size: 20px; font-weight: 800; text-align: center; }
.sc-item.none .sc-n { border-color: #8E1B1B; color: #8E1B1B; }
.sc-pm { width: 44px; height: 44px; border-radius: 10px; border: 1px solid #E1D7C4; background: #fff; font-size: 22px; font-weight: 700; cursor: pointer; }
.sc-h { font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #2B2620; margin: 16px 0 6px; }
#scCam { width: 100%; max-width: 420px; margin: 6px 0; display: none; }
.sc-bottom { position: fixed; left: 0; right: 0; bottom: 0; background: #fff; border-top: 2px solid #2E6B2E; padding: 10px 16px; display: flex; gap: 14px; justify-content: center; align-items: center; z-index: 10; }
#scProg { font-size: 15px; color: #1F1B16; }
.sc-sum td { padding: 6px 8px; border-top: 1px solid #F3ECE1; font-size: 14px; }
.miss { color: #8E1B1B; font-weight: 700; }
.sc-how { background: #fff; border: 1px solid #E6DCCF; border-radius: 10px; padding: 10px 14px; margin: 8px 0; font-size: 15px; color: #1F1B16; }
.sc-how ol { margin: 6px 0 0 18px; padding: 0; }
.sc-how li { margin-bottom: 4px; }
.sc-zero { border-color: #8E1B1B !important; color: #8E1B1B !important; }
.sc-all { height: 44px; border-radius: 10px; border: 1px solid #2E6B2E; background: #fff; color: #2E6B2E; font-size: 14px; font-weight: 700; padding: 0 10px; cursor: pointer; margin-right: 6px; }
</style>
<div class="sc-wrap">
    <a class="sc-small" href="/reports/shelf-count?location_id={{ $loc }}&kind={{ $kind }}">Back to bins</a>
    <h1>{{ $kindLabel }} / {{ $name }}</h1>
    <div class="sc-how">
        <b>How to count this bin ({{ ucfirst($locations[$loc] ?? '') }}, {{ count($items) }} titles)</b>
        <ol>
            <li>Set Sort to match how the bin is filed (usually Artist).</li>
            <li>Flip through the bin and follow along in the list. No need to pull records out or scan.</li>
            <li>Titles with 1 or 2 copies: if they're all there, tap <b>All here</b>. 3 or more copies: count them, type the number in the box and press Enter (it jumps to the next title). Not there at all: tap <b>None</b>. To fix a mistake, type the right number.</li>
            <li>A record in the bin that isn't on the list: scan its barcode into the box below (or tap Camera and point the phone at the barcode). It's added right away.</li>
            <li>When you finish (or need to stop), tap the green <b>Done: review and save</b> at the bottom, then <b>Save counts for this bin</b>. Titles you didn't touch stay as they are, so you can finish the rest another day.</li>
        </ol>
    </div>
    <div class="sc-scan">
        <input id="scQ" type="text" placeholder="Record not on the list? Scan it here" autocomplete="off">
        <button class="sc-btn light" id="scCamBtn" type="button">Camera</button>
    </div>
    <div id="scCam"></div>
    <div class="sc-flash" id="scFlash"></div>
    <div id="scChoices"></div>
    <div class="sc-h" style="display:flex;justify-content:space-between;align-items:center;gap:8px;">In this bin (ERP list)
        <select id="scSort" style="height:36px;font-size:15px;border:1px solid #E1D7C4;border-radius:8px;padding:0 8px;font-weight:400;">
            <option value="artist">Sort: Artist</option><option value="title">Sort: Title</option><option value="bin">Sort: Bin spot</option>
        </select></div>
    <div id="scList"></div>
    <div class="sc-h" id="scExtraH" style="display:none;">Found here but the ERP had it somewhere else</div>
    <div id="scExtra"></div>
    <div id="scSummary"></div>
</div>
<div class="sc-bottom"><span class="sc-small" id="scProg" style="align-self:center;"></span><button class="sc-btn" id="scFinish" type="button">Done: review and save</button></div>
@endsection

@section('javascript')
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function () {
    var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var LOC = {{ (int) $loc }};
    var items = @json($items);
    var byId = {}, count = {}, touched = {}, extra = [];
    items.forEach(function (i) { byId[i.id] = i; count[i.id] = 0; });
    function d8(t) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(t || ''); return m ? m[2] + '/' + m[3] + '/' + m[1].slice(2) : (t || ''); }
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function label(i) { return (i.artist && (i.name || '').toLowerCase().indexOf(String(i.artist).toLowerCase()) === -1 ? i.artist + ' - ' : '') + i.name; }
    function row(i) {
        var n = count[i.id] || 0, none = touched[i.id] && !n;
        return '<div class="sc-item' + (n ? ' seen' : '') + (none ? ' none' : '') + '" data-id="' + i.id + '"><div class="t">' + esc(label(i)) + '<div class="sc-small">' + (i.sku ? 'SKU ' + esc(i.sku) + ', ' : '') + (i.bin ? esc(i.bin) + ', ' : '') + 'ERP ' + (i.erp != null ? i.erp : '-') + (none ? ', marked none here' : (touched[i.id] ? '' : ', not counted yet')) + '</div></div>'
            + (i.erp > 0 && i.erp <= 2 && !(touched[i.id] && n === Math.round(i.erp)) ? '<button class="sc-all" type="button">All here</button>' : '')
            + (!none ? '<button class="sc-all sc-zero" type="button">None</button>' : '')
            + '<input class="sc-n" type="number" inputmode="numeric" enterkeyhint="next" min="0" step="1" aria-label="Copies here" placeholder="#" value="' + (touched[i.id] ? n : '') + '"></div>';
    }
    // Sort the list the way the shelf is laid out (Sarah 10/20): artist,
    // title or bin spot. Choice remembered on this device.
    var SORT_KEY = 'shelfcount:sort';
    function sortKey(i, by) {
        var a = String(i.artist || i.name || ''), t = String(i.name || '');
        return (by === 'title' ? t : by === 'bin' ? (i.bin || '~') + ' ' + a : a + ' ' + t).toLowerCase().replace(/^the\s+/, '');
    }
    function sortItems(by) {
        items.sort(function (x, y) { return sortKey(x, by).localeCompare(sortKey(y, by), undefined, { numeric: true }); });
    }
    var sortSel = document.getElementById('scSort');
    try { sortSel.value = localStorage.getItem(SORT_KEY) || 'artist'; } catch (e) {}
    sortItems(sortSel.value);
    sortSel.addEventListener('change', function () { try { localStorage.setItem(SORT_KEY, sortSel.value); } catch (e) {} sortItems(sortSel.value); render(); });
    function render() {
        // Keep the cursor in the box she moved to while the list redraws.
        var ae = document.activeElement, keep = ae && ae.classList && ae.classList.contains('sc-n') ? ae.closest('.sc-item').getAttribute('data-id') : null;
        document.getElementById('scList').innerHTML = items.map(row).join('');
        if (pendingFocus) setTimeout(focusPending, 0);
        else if (keep) setTimeout(function () { var el = document.querySelector('.sc-item[data-id="' + keep + '"] .sc-n'); if (el) el.focus(); }, 0);
        document.getElementById('scExtraH').style.display = extra.length ? '' : 'none';
        document.getElementById('scExtra').innerHTML = extra.map(row).join('');
        var seen = items.filter(function (i) { return count[i.id] > 0; }).length, done = items.filter(function (i) { return touched[i.id]; }).length;
        document.getElementById('scProg').textContent = done + ' of ' + items.length + ' titles counted (' + seen + ' found). Not saved until you tap Done, then Save.';
    }
    // Draft kept on this device after every scan, so closing the tab, a
    // dead battery or a refresh never loses a half-counted bin (Sarah 10/14).
    var DRAFT = 'shelfcount:' + LOC + ':{{ $kind }}:{{ (int) request('sub') }}', saved = true;
    function keepDraft() {
        saved = false;
        try { localStorage.setItem(DRAFT, JSON.stringify({ count: count, touched: touched, extra: extra, at: Date.now() })); } catch (e) {}
    }
    try {
        var dr = JSON.parse(localStorage.getItem(DRAFT) || 'null');
        if (dr && dr.count) {
            Object.keys(dr.count).forEach(function (k) { count[k] = dr.count[k]; if (dr.touched ? dr.touched[k] : dr.count[k] > 0) touched[k] = true; });
            (dr.extra || []).forEach(function (i) { if (!byId[i.id]) { byId[i.id] = i; extra.push(i); } });
            saved = false;
            setTimeout(function () { var f = document.getElementById('scFlash'); f.className = 'sc-flash ok'; f.textContent = 'Picked up where you left off (' + new Date(dr.at).toLocaleString() + ').'; }, 0);
        }
    } catch (e) {}
    window.addEventListener('beforeunload', function (e) { if (!saved) { e.preventDefault(); e.returnValue = ''; } });
    function bump(i, d) {
        if (!byId[i.id]) { byId[i.id] = i; extra.unshift(i); count[i.id] = 0; }
        count[i.id] = Math.max(0, (count[i.id] || 0) + d);
        touched[i.id] = true;
        keepDraft();
        render();
        var f = document.getElementById('scFlash'); f.className = 'sc-flash ok'; f.textContent = (d > 0 ? 'Added: ' : (count[i.id] ? 'Removed: ' : 'None here: ')) + label(i) + (count[i.id] || d > 0 ? ' (' + count[i.id] + ')' : '');
    }
    // Type the number of copies on the shelf (Sarah 10/20: "key in instead of + and -").
    document.querySelector('.sc-wrap').addEventListener('change', function (e) {
        var inp = e.target.closest('.sc-n'); if (!inp) return;
        var it = byId[inp.closest('.sc-item').getAttribute('data-id')];
        if (inp.value === '') { delete touched[it.id]; count[it.id] = 0; keepDraft(); render(); return; }
        var v = Math.max(0, parseInt(inp.value, 10) || 0);
        bump(it, v - (count[it.id] || 0));
    });
    // Enter saves the number and jumps to the next title's box, so a bin
    // can be done on the number pad alone (Sarah 10/20).
    var pendingFocus = null;
    function focusPending() {
        if (!pendingFocus) return;
        var el = document.querySelector('.sc-item[data-id="' + pendingFocus + '"] .sc-n');
        pendingFocus = null;
        if (el) { el.focus(); el.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    }
    document.querySelector('.sc-wrap').addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || !e.target.closest('.sc-n')) return;
        e.preventDefault();
        var next = e.target.closest('.sc-item').nextElementSibling;
        pendingFocus = next ? next.getAttribute('data-id') : null;
        e.target.blur();
        setTimeout(focusPending, 0);
    });
    document.querySelector('.sc-wrap').addEventListener('click', function (e) {
        var z = e.target.closest('.sc-zero'); if (z) { var zi = byId[z.closest('.sc-item').getAttribute('data-id')]; count[zi.id] = 0; bump(zi, 0); return; }
        var all = e.target.closest('.sc-all'); if (all) { var ai = byId[all.closest('.sc-item').getAttribute('data-id')]; bump(ai, Math.round(ai.erp) - (count[ai.id] || 0)); return; }
        var b = e.target.closest('.sc-pm'); if (b) { var id = b.closest('.sc-item').getAttribute('data-id'); bump(byId[id], parseInt(b.getAttribute('data-d'), 10)); return; }
        var c = e.target.closest('.sc-choice'); if (c) { var x = JSON.parse(c.getAttribute('data-i')); bump(byId[x.id] || x, 1); document.getElementById('scChoices').innerHTML = ''; document.getElementById('scQ').focus(); }
    });
    function lookup(q) {
        q = q.trim(); if (!q) return;
        var digits = q.replace(/[\s-]/g, '');
        // Barcode already in this bin: count it without a server call.
        if (/^\d{8,14}$/.test(digits)) {
            var key = digits.replace(/^0+/, '');
            var hit = items.concat(extra).filter(function (i) { return String(i.sku || '').replace(/[\s-]/g, '').replace(/^0+/, '') === key; });
            if (hit.length === 1) { bump(hit[0], 1); return; }
        }
        fetch('/reports/shelf-count/lookup?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
            var f = document.getElementById('scFlash');
            if (!j.items.length) { f.className = 'sc-flash bad'; f.textContent = 'Not found in the ERP: ' + q; return; }
            if (j.items.length === 1) { bump(byId[j.items[0].id] || j.items[0], 1); return; }
            document.getElementById('scChoices').innerHTML = '<div class="sc-small">Which one?</div>' + j.items.map(function (x) {
                return '<div class="sc-item sc-choice" data-i="' + esc(JSON.stringify(x)) + '" style="cursor:pointer;"><div class="t">' + esc(label(x)) + '<div class="sc-small">' + esc(x.sku) + '</div></div></div>';
            }).join('');
        });
    }
    document.getElementById('scQ').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); lookup(this.value); this.value = ''; }
    });
    var cam = null;
    document.getElementById('scCamBtn').addEventListener('click', function () {
        var box = document.getElementById('scCam');
        if (cam) { cam.stop().then(function () { cam = null; box.style.display = 'none'; }); return; }
        if (!window.Html5Qrcode) return;
        box.style.display = 'block';
        cam = new Html5Qrcode('scCam');
        var last = '', lastAt = 0;
        cam.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 280, height: 120 } }, function (code) {
            var now = Date.now(); if (code === last && now - lastAt < 2500) return;
            last = code; lastAt = now; lookup(code);
        }).catch(function () { box.style.display = 'none'; cam = null; alert('Camera not available'); });
    });
    document.getElementById('scFinish').addEventListener('click', function () {
        var missing = items.filter(function (i) { return touched[i.id] && (count[i.id] || 0) < i.erp; });
        var skipped = items.filter(function (i) { return !touched[i.id]; });
        var rowsHtml = missing.map(function (i) { return '<tr><td>' + esc(label(i)) + '</td><td>ERP ' + i.erp + '</td><td class="miss">found ' + (count[i.id] || 0) + '</td></tr>'; }).join('');
        document.getElementById('scSummary').innerHTML = '<div class="sc-h">Before saving</div><div>' + missing.length + ' titles you counted have fewer copies than the ERP says. If you looked everywhere (wall, shelves above), press Save. Otherwise keep scanning.</div>'
            + '<table class="sc-sum">' + rowsHtml + '</table>'
            + (skipped.length ? '<p>' + skipped.length + ' titles were not counted (not scanned, no + or -). They stay as they are in the ERP and are not marked missing.</p>' : '')
            + '<p><button class="sc-btn" id="scSave" type="button">Save counts for this bin</button> <span class="sc-small" id="scSaveMsg"></span></p>';
        document.getElementById('scSave').scrollIntoView({ behavior: 'smooth' });
        document.getElementById('scSave').addEventListener('click', save);
    });
    function save() {
        // Only titles someone counted (Sarah 10/16: "what if she didnt count it yet?").
        var all = (save.retry || items.concat(extra).filter(function (x) { return touched[x.id]; })), i = 0, failed = [], msg = document.getElementById('scSaveMsg'), btn = document.getElementById('scSave');
        btn.disabled = true;
        (function next() {
            if (i >= all.length) {
                if (failed.length) { msg.textContent = failed.length + ' did not save. Press Save again to retry them (nothing is lost).'; save.retry = failed; btn.disabled = false; return; }
                saved = true; save.retry = null; try { localStorage.removeItem(DRAFT); } catch (e) {}
                msg.textContent = 'Saved ' + all.length + ' titles. Anything short is on the Missing list.'; return;
            }
            var x = all[i++];
            msg.textContent = 'Saving ' + i + ' of ' + all.length + '...';
            fetch('/reports/reorder/count', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ product_id: x.id, qty: String(count[x.id] || 0), location_id: LOC }) })
                .then(function (r) { return r.json(); }).then(function (j) { if (!j.ok) failed.push(x); next(); }, function () { failed.push(x); next(); });
        })();
    }
    render();
})();
</script>
@endsection
