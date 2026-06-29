@extends('layouts.app')
@section('title', 'Import AMS Invoice')

@section('content')
<section class="content-header">
    <h1>Import AMS Invoice (PDF)</h1>
    <p class="text-muted" style="max-width:900px;">
        Drop an AMS (All Media Supply) invoice PDF below. It's read in your browser, each UPC is matched
        to a product, and you review before anything is saved. Confirming logs one purchase on the
        <a href="/purchases">Purchases</a> list &mdash; no stock or costs are changed (received separately).
        Every import is undoable at <a href="/admin/admin-action-history">admin-action-history</a>.
    </p>
</section>

<section class="content">
<input type="hidden" id="ams_csrf" value="{{ csrf_token() }}">

<div class="box box-solid">
    <div class="box-body">
        <div class="row">
            <div class="col-sm-5 form-group">
                <label>Store (ship-to)</label>
                <select id="ams_location" class="form-control">
                    @foreach ($locations as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-5 form-group">
                <label>Supplier</label>
                <select id="ams_supplier" class="form-control">
                    <option value="">-- select --</option>
                    @foreach ($suppliers as $id => $name)
                        <option value="{{ $id }}" {{ $default_supplier_id == $id ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div id="ams_drop" style="border:2px dashed #c7c7c7;border-radius:8px;padding:32px;text-align:center;cursor:pointer;background:#fbfbf7;">
            <p style="margin:0;font-size:15px;"><strong>Drop the AMS invoice PDF here</strong> or click to choose a file</p>
            <p class="text-muted" style="margin:6px 0 0;">Parsed in your browser &mdash; nothing is uploaded until you confirm.</p>
            <input type="file" id="ams_file" accept="application/pdf" style="display:none;">
        </div>
        <div id="ams_status" style="margin-top:12px;"></div>
    </div>
</div>

<div class="box box-solid" id="ams_review_box" style="display:none;">
    <div class="box-header with-border">
        <h3 class="box-title">Review</h3>
        <div class="pull-right" id="ams_summary" style="font-size:13px;"></div>
    </div>
    <div class="box-body">
        <div id="ams_dupe_warn"></div>
        <div class="table-responsive" style="max-height:480px;overflow:auto;">
            <table class="table table-condensed table-bordered" style="font-size:12px;">
                <thead>
                    <tr>
                        <th></th><th>UPC</th><th>Description (from invoice)</th><th>Matched product</th>
                        <th style="text-align:right;">Qty</th><th style="text-align:right;">Unit cost</th>
                        <th style="text-align:right;">Current cost</th><th style="text-align:right;">Line total</th>
                    </tr>
                </thead>
                <tbody id="ams_rows"></tbody>
            </table>
        </div>
    </div>
    <div class="box-footer">
        <label style="font-weight:normal;margin-right:14px;"><input type="checkbox" id="ams_force"> import anyway (this invoice was already imported)</label>
        <button class="btn btn-primary" id="ams_apply">Create Purchase Order</button>
        <span class="text-muted" style="margin-left:10px;">Only matched rows (ticked) are imported. Unmatched UPCs are skipped.</span>
    </div>
</div>
</section>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.2.67/pdf.min.mjs" type="module"></script>
<script type="module">
import * as pdfjsLib from 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.2.67/pdf.min.mjs';
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.2.67/pdf.worker.min.mjs';

// ---- AMS parser (mirrors the node-verified ams-parser.js) ----
const MONTHS = {Jan:0,Feb:1,Mar:2,Apr:3,May:4,Jun:5,Jul:6,Aug:7,Sep:8,Oct:9,Nov:10,Dec:11};
const LINE_RE = /^(\d{11,14})\s+(.+?)\s+(\d{2}\/\d{2}\/\d{4})\s+([A-Z0-9]{1,6})\s+(\d{1,4})\s+(.*?)\s+(\d{1,3}(?:,\d{3})*\.\d{2})\s+(\d{1,3}(?:,\d{3})*\.\d{2})\s+(\d{1,3}(?:,\d{3})*\.\d{2})$/;
const num = s => parseFloat(String(s).replace(/,/g, ''));

function linesFromItems(items) {
    const rows = [], TOL = 3;
    items.sort((a, b) => a.y - b.y || a.x - b.x);
    for (const it of items) {
        if (!it.str || !it.str.trim()) continue;
        let row = rows.find(r => Math.abs(r.y - it.y) <= TOL);
        if (!row) { row = {y: it.y, parts: []}; rows.push(row); }
        row.parts.push(it);
    }
    return rows.map(r => r.parts.sort((a, b) => a.x - b.x).map(p => p.str).join(' ').replace(/\s+/g, ' ').trim());
}

function parseAmsLines(lines) {
    const header = {}, items = [], totals = {};
    for (const line of lines) {
        let m;
        if ((m = line.match(/INVOICE:\s*(\d+)/)) && !header.invoice) header.invoice = m[1];
        if ((m = line.match(/AMS REF #:\s*(\d+)/)) && !header.amsRef) header.amsRef = m[1];
        if ((m = line.match(/Date:\s*([A-Z][a-z]{2})\s+(\d{1,2})\s+(\d{4})/)) && !header.date)
            header.date = `${m[3]}-${String(MONTHS[m[1]]+1).padStart(2,'0')}-${String(+m[2]).padStart(2,'0')}`;
        if ((m = line.match(/Total Goods\s*\$?\s*([\d,]+\.\d{2})/))) totals.goods = num(m[1]);
        const lm = line.match(LINE_RE);
        if (lm) {
            items.push({
                upc: lm[1], description: lm[2].trim(), prodType: lm[4],
                qty: +lm[5], unitPrice: num(lm[8]), lineTotal: num(lm[9]),
            });
        }
    }
    const sum = Math.round(items.reduce((a, b) => a + b.lineTotal, 0) * 100) / 100;
    return {header, items, totals, sumLineTotals: sum,
        goodsMatches: totals.goods != null ? Math.abs(sum - totals.goods) < 0.01 : null};
}

// ---- UI wiring ----
const $ = id => document.getElementById(id);
const csrf = $('ams_csrf').value;
let parsed = null, previewLines = null;

function status(html, kind) {
    $('ams_status').innerHTML = html ? `<div class="alert alert-${kind || 'info'}" style="margin:0;">${html}</div>` : '';
}
const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const money = n => n == null ? '' : '$' + Number(n).toFixed(2);

async function handleFile(file) {
    if (!file || file.type !== 'application/pdf') { status('Please choose a PDF file.', 'warning'); return; }
    status('Reading ' + esc(file.name) + '…');
    try {
        const buf = new Uint8Array(await file.arrayBuffer());
        const doc = await pdfjsLib.getDocument({data: buf}).promise;
        let allItems = [];
        for (let p = 1; p <= doc.numPages; p++) {
            const page = await doc.getPage(p);
            const tc = await page.getTextContent();
            for (const it of tc.items)
                allItems.push({str: it.str, x: it.transform[4], y: (p * 100000) - it.transform[5]});
        }
        parsed = parseAmsLines(linesFromItems(allItems));
        if (!parsed.items.length) { status('No invoice line items found. Is this an AMS invoice PDF?', 'danger'); return; }
        const chk = parsed.goodsMatches === false
            ? ` <strong style="color:#c0392b;">— totals DON'T match the invoice (parsed $${parsed.sumLineTotals.toFixed(2)} vs $${parsed.totals.goods.toFixed(2)}); review carefully.</strong>`
            : (parsed.goodsMatches ? ' — line totals match the invoice goods total.' : '');
        status(`Parsed invoice <strong>${esc(parsed.header.invoice || '?')}</strong> (${parsed.header.date || '?'}): ${parsed.items.length} lines, $${parsed.sumLineTotals.toFixed(2)}${chk} Matching to products…`, parsed.goodsMatches === false ? 'warning' : 'info');
        await runPreview();
    } catch (e) {
        status('Could not read PDF: ' + esc(e.message), 'danger');
    }
}

async function runPreview() {
    const res = await fetch('/admin/ams-invoice-import/preview', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
        body: JSON.stringify({header: parsed.header, items: parsed.items}),
    });
    const data = await res.json();
    if (!data.success) { status(esc(data.msg || 'Match failed.'), 'danger'); return; }
    previewLines = data.lines;
    renderReview(data);
}

function renderReview(data) {
    $('ams_review_box').style.display = '';
    $('ams_summary').innerHTML = `<span class="label label-success">${data.matched} matched</span> <span class="label label-default">${data.unmatched} unmatched</span>`;
    $('ams_dupe_warn').innerHTML = data.already_imported
        ? `<div class="alert alert-warning">Invoice <strong>${esc(parsed.header.invoice)}</strong> was already imported as PO #${esc(data.already_imported.transaction_id)} on ${esc(data.already_imported.applied_at)}. Tick "import anyway" below to create it again.</div>`
        : '';
    $('ams_rows').innerHTML = data.lines.map((l, i) => {
        const cls = l.matched ? '' : ' style="background:#fff3f3;color:#999;"';
        const prod = l.matched ? esc(l.product_name) : '<em>no product with this UPC</em>';
        const chk = l.matched ? `<input type="checkbox" class="ams-pick" data-i="${i}" checked>` : '';
        return `<tr${cls}><td>${chk}</td><td>${esc(l.upc)}</td><td>${esc(l.description)}</td><td>${prod}</td>
            <td style="text-align:right;">${l.qty}</td><td style="text-align:right;">${money(l.unit_price)}</td>
            <td style="text-align:right;">${money(l.current_cost)}</td><td style="text-align:right;">${money(l.line_total)}</td></tr>`;
    }).join('');
    status('', 'info');
    $('ams_review_box').scrollIntoView({behavior: 'smooth'});
}

$('ams_apply').addEventListener('click', async () => {
    const supplier = $('ams_supplier').value, location = $('ams_location').value;
    if (!supplier) { alert('Pick a supplier first.'); return; }
    const picks = [...document.querySelectorAll('.ams-pick:checked')].map(c => previewLines[+c.dataset.i]);
    if (!picks.length) { alert('No matched rows selected.'); return; }
    $('ams_apply').disabled = true;
    const res = await fetch('/admin/ams-invoice-import/apply', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
        body: JSON.stringify({
            location_id: location, supplier_id: supplier,
            invoice: parsed.header.invoice, ams_ref: parsed.header.amsRef, invoice_date: parsed.header.date,
            force: $('ams_force').checked, lines: picks,
        }),
    });
    const data = await res.json();
    $('ams_apply').disabled = false;
    if (!data.success) { status(esc(data.msg || 'Import failed.'), 'danger'); return; }
    $('ams_review_box').style.display = 'none';
    status(`${esc(data.msg)} <a href="${esc(data.view_url)}" class="btn btn-xs btn-default">View PO</a>`, 'success');
});

$('ams_drop').addEventListener('click', () => $('ams_file').click());
$('ams_file').addEventListener('change', e => handleFile(e.target.files[0]));
['dragover', 'dragenter'].forEach(ev => $('ams_drop').addEventListener(ev, e => { e.preventDefault(); $('ams_drop').style.background = '#f1f1e4'; }));
['dragleave', 'drop'].forEach(ev => $('ams_drop').addEventListener(ev, e => { e.preventDefault(); $('ams_drop').style.background = '#fbfbf7'; }));
$('ams_drop').addEventListener('drop', e => handleFile(e.dataTransfer.files[0]));
</script>
@endsection
