@extends('layouts.app')
@section('title', 'Merge Duplicate Products')

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
.mg-row { display: flex; gap: 16px; flex-wrap: wrap; }
.mg-field { flex: 1 1 320px; }
.mg-field label { display: block; font-size: 13px; font-weight: 600; margin: 0 0 6px; }
.mg-field label .hint { font-weight: 400; color: #8E8273; }
.mg-field input { width: 100%; height: 44px; border: 1px solid #E1D7C4; border-radius: 10px; padding: 0 14px; font-size: 15px; font-family: inherit; background: #FEFCF7; }
.mg-field input:focus { outline: none; border-color: #C9A227; box-shadow: 0 0 0 3px #FFF2B3; }
.mg-btn { height: 44px; padding: 0 22px; border-radius: 10px; border: 0; font-family: inherit; font-size: 14.5px; font-weight: 700; cursor: pointer; }
.mg-btn-primary { background: #1F1B16; color: #FFF2B3; }
.mg-btn-primary:disabled { opacity: .5; cursor: not-allowed; }
.mg-btn-ghost { background: #F3ECDD; color: #1F1B16; }
.mg-actions { margin-top: 18px; display: flex; gap: 10px; align-items: center; }
.mg-compare { display: none; }
.mg-compare table { width: 100%; border-collapse: collapse; margin-top: 6px; }
.mg-compare th, .mg-compare td { text-align: left; padding: 10px 12px; border-bottom: 1px solid #F0E9DA; font-size: 14px; }
.mg-compare th { color: #8E8273; font-weight: 600; font-size: 12.5px; text-transform: uppercase; letter-spacing: .4px; }
.mg-compare td.num { font-variant-numeric: tabular-nums; font-weight: 600; }
.mg-after { background: #FFF9E3; }
.mg-keep-tag { display: inline-block; background: #E8F5E9; color: #1B5E20; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px; margin-left: 6px; }
.mg-drop-tag { display: inline-block; background: #FDECEA; color: #B71C1C; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 999px; margin-left: 6px; }
.mg-note { font-size: 13px; color: #8E8273; margin-top: 14px; line-height: 1.5; }
.mg-msg { display: none; padding: 12px 14px; border-radius: 10px; font-size: 14px; font-weight: 600; margin-bottom: 16px; }
.mg-msg.ok { display: block; background: #E8F5E9; color: #1B5E20; }
.mg-msg.err { display: block; background: #FDECEA; color: #B71C1C; }
</style>

<section class="content-header"><h1>Merge Duplicate Products<br><small>Move all sales &amp; stock onto the one you keep, then deactivate the duplicate. Fully undoable.</small></h1></section>

<section class="content">
<div class="mg-wrap">
    <div id="mgMsg" class="mg-msg"></div>

    <div class="mg-card">
        <h2>Pick the two products</h2>
        <p class="sub">Enter each product's SKU (or its ERP id). The one you keep stays live; the duplicate is deactivated and its sales, purchases and stock move onto the kept product.</p>
        <div class="mg-row">
            <div class="mg-field">
                <label>Keep this one <span class="hint">(SKU or id — survives)</span></label>
                <input type="text" id="mgKeep" placeholder="e.g. 197190162899" autocomplete="off">
            </div>
            <div class="mg-field">
                <label>Merge this one in <span class="hint">(SKU or id — deactivated)</span></label>
                <input type="text" id="mgMerge" placeholder="e.g. 0197190162899" autocomplete="off">
            </div>
        </div>
        <div class="mg-actions">
            <button class="mg-btn mg-btn-ghost" id="mgPreviewBtn" type="button">Preview</button>
        </div>
    </div>

    <div class="mg-card mg-compare" id="mgCompare">
        <h2>Preview</h2>
        <p class="sub">Check this is right before merging. Nothing is changed until you confirm.</p>
        <table>
            <thead><tr><th>Product</th><th>Units sold</th><th>Current stock</th></tr></thead>
            <tbody>
                <tr id="mgRowKeep"></tr>
                <tr id="mgRowMerge"></tr>
                <tr class="mg-after" id="mgRowAfter"></tr>
            </tbody>
        </table>
        <div class="mg-note" id="mgMoves"></div>
        <div class="mg-actions">
            <button class="mg-btn mg-btn-primary" id="mgConfirmBtn" type="button">Confirm merge</button>
            <span class="mg-note" style="margin-top:0">Undo any time at <a href="/admin/admin-action-history">Admin Action History</a>.</span>
        </div>
    </div>

    <div class="mg-card">
        <h2>Or merge the whole catalog</h2>
        <p class="sub">Scans every product and groups duplicates that share the SAME real barcode (8+ digit UPC/EAN, leading zeros ignored — placeholder SKUs like "003" are ignored), the same store, and the same format (category, e.g. Vinyl - Sealed). Title and genre don't have to match — a shared barcode is the source of truth, so title variants and miscategorised genres still merge. Each set merges into one listing — keeping the trustworthy copy's name/price and totaling stock + units sold onto it. Multiple-variation products are skipped for manual review. Scanning changes nothing.</p>
        <div class="mg-actions">
            <button class="mg-btn mg-btn-ghost" id="mgScanBtn" type="button">Scan whole catalog</button>
            <a class="mg-btn mg-btn-ghost" href="{{ route('products.merge.scan-export') }}" style="text-decoration:none;display:inline-flex;align-items:center;">Download full list (CSV)</a>
        </div>
        <div id="mgScanResult" style="display:none;margin-top:18px;">
            <div class="mg-note" id="mgScanSummary" style="margin-top:0;font-size:15px;color:#1F1B16;font-weight:600;"></div>
            <div class="mg-compare" style="display:block;margin-top:10px;max-height:75vh;overflow:auto;border:1px solid #F0E9DA;border-radius:10px;">
                <table>
                    <thead><tr><th>Keep (survivor)</th><th>Store</th><th>Format</th><th>Merging in</th><th>Combined stock</th><th>Combined sold</th></tr></thead>
                    <tbody id="mgScanRows"></tbody>
                </table>
            </div>
            <div class="mg-actions">
                <button class="mg-btn mg-btn-primary" id="mgBulkBtn" type="button">Merge all duplicates</button>
                <span class="mg-note" id="mgBulkProgress" style="margin-top:0"></span>
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
    var compare = document.getElementById('mgCompare');
    var previewBtn = document.getElementById('mgPreviewBtn');
    var confirmBtn = document.getElementById('mgConfirmBtn');
    var lastPair = null;

    function showMsg(text, ok) {
        msg.textContent = text;
        msg.className = 'mg-msg ' + (ok ? 'ok' : 'err');
    }
    function clearMsg() { msg.className = 'mg-msg'; msg.textContent = ''; }
    function num(n) { return (Math.round(n * 100) / 100).toLocaleString(); }

    function post(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            body: JSON.stringify(body),
        }).then(function (r) { return r.json(); });
    }

    previewBtn.addEventListener('click', function () {
        clearMsg();
        compare.style.display = 'none';
        var keep = document.getElementById('mgKeep').value.trim();
        var merge = document.getElementById('mgMerge').value.trim();
        if (!keep || !merge) { showMsg('Enter both products first.', false); return; }
        previewBtn.disabled = true;
        post('{{ route('products.merge.preview') }}', { keep: keep, merge: merge }).then(function (d) {
            previewBtn.disabled = false;
            if (!d.success) { showMsg(d.msg || 'Could not preview.', false); return; }
            lastPair = { keep: keep, merge: merge };
            document.getElementById('mgRowKeep').innerHTML =
                '<td>' + d.target.name + ' <span class="mg-keep-tag">KEEP</span><br><small style="color:#8E8273">SKU ' + d.target.sku + '</small></td>' +
                '<td class="num">' + num(d.target.units_sold) + '</td><td class="num">' + num(d.target.current_stock) + '</td>';
            document.getElementById('mgRowMerge').innerHTML =
                '<td>' + d.source.name + ' <span class="mg-drop-tag">DEACTIVATE</span><br><small style="color:#8E8273">SKU ' + d.source.sku + '</small></td>' +
                '<td class="num">' + num(d.source.units_sold) + '</td><td class="num">' + num(d.source.current_stock) + '</td>';
            document.getElementById('mgRowAfter').innerHTML =
                '<td><strong>' + d.target.name + '</strong> after merge</td>' +
                '<td class="num">' + num(d.after.units_sold) + '</td><td class="num">' + num(d.after.current_stock) + '</td>';
            document.getElementById('mgMoves').textContent =
                'Moves ' + d.moves.sell_lines + ' sale line(s) and ' + d.moves.purchase_lines + ' purchase line(s) onto the kept product.';
            compare.style.display = 'block';
        }).catch(function () { previewBtn.disabled = false; showMsg('Preview failed — try again.', false); });
    });

    confirmBtn.addEventListener('click', function () {
        if (!lastPair) return;
        if (!confirm('Merge these two products? The duplicate will be deactivated. You can undo this from Admin Action History.')) return;
        confirmBtn.disabled = true;
        post('{{ route('products.merge') }}', lastPair).then(function (d) {
            confirmBtn.disabled = false;
            if (!d.success) { showMsg(d.msg || 'Merge failed.', false); return; }
            compare.style.display = 'none';
            showMsg(d.msg, true);
            document.getElementById('mgKeep').value = '';
            document.getElementById('mgMerge').value = '';
            lastPair = null;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }).catch(function () { confirmBtn.disabled = false; showMsg('Merge failed — try again.', false); });
    });

    // ---- Whole-catalog scan + bulk sweep ----
    var scanBtn = document.getElementById('mgScanBtn');
    var bulkBtn = document.getElementById('mgBulkBtn');
    var scanResult = document.getElementById('mgScanResult');

    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    scanBtn.addEventListener('click', function () {
        clearMsg();
        scanResult.style.display = 'none';
        scanBtn.disabled = true;
        scanBtn.textContent = 'Scanning…';
        post('{{ route('products.merge.scan') }}', {}).then(function (d) {
            scanBtn.disabled = false;
            scanBtn.textContent = 'Scan whole catalog';
            if (!d.success) { showMsg(d.msg || 'Scan failed.', false); return; }
            var summary = d.total_groups + ' duplicate set(s) found — ' + d.total_merges + ' product(s) will be deactivated after merging.';
            if (d.skipped > 0) { summary += ' ' + d.skipped + ' set(s) skipped (multiple variations — merge those manually).'; }
            document.getElementById('mgScanSummary').textContent = summary;
            var money = function (v) { return (v === null || v === undefined) ? '' : '$' + Number(v).toFixed(2); };
            var by = function (x) {
                var color = x.is_untrusted ? '#B45309' : '#8E8273';
                var bits = [];
                if (x.sell_price !== null && x.sell_price !== undefined) { bits.push(money(x.sell_price) + ' sell'); }
                if (x.purchase_price !== null && x.purchase_price !== undefined) { bits.push(money(x.purchase_price) + ' cost'); }
                if (x.created_date) { bits.push(esc(x.created_date)); }
                if (x.creator) { bits.push('by ' + esc(x.creator)); }
                if (!bits.length) { return ''; }
                return '<br><small style="color:' + color + '">' + bits.join(' · ') + '</small>';
            };
            var rows = d.preview.map(function (g) {
                var mergeNames = g.merge_in.map(function (m) { return esc(m.name) + ' <small style="color:#8E8273">(SKU ' + esc(m.sku) + ')</small>' + by(m); }).join('<br>');
                return '<tr><td>' + esc(g.keep.name) + ' <small style="color:#8E8273">(SKU ' + esc(g.keep.sku) + ')</small>' + by(g.keep) + '</td>' +
                    '<td>' + esc(g.store) + '</td>' +
                    '<td>' + esc(g.category) + '</td>' +
                    '<td>' + mergeNames + '</td>' +
                    '<td class="num">' + num(g.combined_stock) + '</td>' +
                    '<td class="num">' + num(g.combined_sold) + '</td></tr>';
            }).join('');
            if (d.total_groups > d.preview.length) {
                rows += '<tr><td colspan="6" style="color:#8E8273">… and ' + (d.total_groups - d.preview.length) + ' more set(s) not shown. All will be merged.</td></tr>';
            }
            document.getElementById('mgScanRows').innerHTML = rows || '<tr><td colspan="6">No duplicates found.</td></tr>';
            bulkBtn.style.display = d.total_merges > 0 ? '' : 'none';
            scanResult.style.display = 'block';
        }).catch(function () {
            scanBtn.disabled = false;
            scanBtn.textContent = 'Scan whole catalog';
            showMsg('Scan failed — try again.', false);
        });
    });

    function runBulkBatch(totalDone) {
        post('{{ route('products.merge.bulk') }}', { max: 150 }).then(function (d) {
            if (!d.success) { bulkBtn.disabled = false; showMsg(d.msg || 'Merge failed.', false); return; }
            totalDone += d.merged;
            document.getElementById('mgBulkProgress').textContent =
                'Merged ' + totalDone + ' so far — ' + d.remaining + ' remaining' + (d.failed ? ' (' + d.failed + ' skipped)' : '') + '…';
            if (d.remaining > 0 && d.merged > 0) {
                runBulkBatch(totalDone);
            } else {
                bulkBtn.disabled = false;
                scanResult.style.display = 'none';
                showMsg('Done — merged ' + totalDone + ' duplicate(s) across the catalog. Undo any batch at Admin Action History.', true);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }).catch(function () { bulkBtn.disabled = false; showMsg('Merge failed mid-run — re-scan to see what remains.', false); });
    }

    bulkBtn.addEventListener('click', function () {
        if (!confirm('Merge ALL duplicate sets across the catalog? Duplicates get deactivated and their stock + sales combine onto each survivor. Each batch is undoable from Admin Action History.')) return;
        clearMsg();
        bulkBtn.disabled = true;
        document.getElementById('mgBulkProgress').textContent = 'Starting…';
        runBulkBatch(0);
    });
})();
</script>
@endsection
