@extends('layouts.app')
@section('title', 'Import ' . $cfg['label'] . ' Invoice')

@section('content')
<section class="content-header">
    <h1>Import {{ $cfg['label'] }} Invoice (PDF)</h1>
    <p style="max-width:900px;">
        Drop an {{ $cfg['long'] }} invoice PDF below. It's read in your browser, each UPC is matched to a product,
        and titles we don't carry yet get a new product (name, barcode, category, cost and price filled in for you to check).
        Confirming logs one purchase on the <a href="/purchases">Purchases</a> list with every ticked line, so the store
        can receive the whole box. No stock or existing costs change until it's received.
        Every import is undoable at <a href="/admin/admin-action-history">admin-action-history</a>.
        @if ($distributor === 'ams')
            Alliance invoices go in at <a href="/admin/alliance-invoice-import">Import Alliance Invoice</a>.
        @else
            AMS invoices go in at <a href="/admin/ams-invoice-import">Import AMS Invoice</a>.
        @endif
        Every invoice vs. purchase: <a href="/reports/distributor-invoice-audit">Distributor Invoice Audit</a>.
    </p>
</section>

<section class="content">
<input type="hidden" id="inv_csrf" value="{{ csrf_token() }}">

<div class="box box-solid">
    <div class="box-body">
        <div class="row">
            <div class="col-sm-5 form-group">
                <label>Store (ship-to)</label>
                <select id="inv_location" class="form-control">
                    @foreach ($locations as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-5 form-group">
                <label>Supplier</label>
                <select id="inv_supplier" class="form-control">
                    <option value="">-- select --</option>
                    @foreach ($suppliers as $id => $name)
                        <option value="{{ $id }}" {{ $default_supplier_id == $id ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div id="inv_drop" style="border:2px dashed #c7c7c7;border-radius:8px;padding:32px;text-align:center;cursor:pointer;background:#fbfbf7;">
            <p style="margin:0;font-size:15px;"><strong>Drop the {{ $cfg['label'] }} invoice PDF here</strong> or click to choose a file</p>
            <p style="margin:6px 0 0;">Parsed in your browser. Nothing is saved until you confirm.</p>
            <input type="file" id="inv_file" accept="application/pdf" style="display:none;">
        </div>
        <div id="inv_status" style="margin-top:12px;"></div>
    </div>
</div>

<div class="box box-solid" id="inv_review_box" style="display:none;">
    <div class="box-header with-border">
        <h3 class="box-title">Review</h3>
        <div class="pull-right" id="inv_summary" style="font-size:13px;"></div>
    </div>
    <div class="box-body">
        <div id="inv_dupe_warn"></div>
        <div class="table-responsive" style="max-height:560px;overflow:auto;">
            <table class="table table-condensed table-bordered" style="font-size:12px;">
                <thead>
                    <tr>
                        <th></th><th>UPC</th><th>Invoice line</th><th>Product</th><th>Category</th>
                        <th style="text-align:right;">Qty</th><th style="text-align:right;">Unit cost</th>
                        <th style="text-align:right;">Sell price</th><th style="text-align:right;">Line total</th><th>Note</th>
                    </tr>
                </thead>
                <tbody id="inv_rows"></tbody>
            </table>
        </div>
    </div>
    <div class="box-footer">
        <label style="font-weight:normal;margin-right:14px;"><input type="checkbox" id="inv_force"> import anyway (this invoice was already imported)</label>
        <button class="btn btn-primary" id="inv_apply">Create Purchase</button>
        <span style="margin-left:10px;">Ticked lines are imported. New products are created first. Lines already on another purchase start unticked so nothing is doubled.</span>
    </div>
</div>
</section>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.2.67/pdf.min.mjs" type="module"></script>
<script type="module">
import * as pdfjsLib from 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.2.67/pdf.min.mjs';
pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.2.67/pdf.worker.min.mjs';

const DISTRIBUTOR = {!! json_encode($distributor) !!};
const BASE = {!! json_encode($base) !!};
const CATEGORIES = {!! json_encode((object) $categories) !!}; // name => id
const ACCOUNT_LOCATIONS = {!! json_encode((object) $account_locations) !!};

const num = s => parseFloat(String(s).replace(/,/g, ''));
const clean = s => String(s || '').replace(/­/g, '-');

// pdf.js text items -> rows (each row = items sorted left to right).
function rowsFromItems(items) {
    const rows = [], TOL = 3;
    items.sort((a, b) => a.y - b.y || a.x - b.x);
    for (const it of items) {
        if (!it.str || !it.str.trim()) continue;
        let row = rows.find(r => Math.abs(r.y - it.y) <= TOL);
        if (!row) { row = {y: it.y, parts: []}; rows.push(row); }
        row.parts.push(it);
    }
    rows.forEach(r => r.parts.sort((a, b) => a.x - b.x));
    return rows;
}
const rowText = r => r.parts.map(p => clean(p.str)).join(' ').replace(/\s+/g, ' ').trim();

// ---- AMS parser (mirrors the node-verified ams-parser.js) ----
const MONTHS = {Jan:0,Feb:1,Mar:2,Apr:3,May:4,Jun:5,Jul:6,Aug:7,Sep:8,Oct:9,Nov:10,Dec:11};
const AMS_LINE_RE = /^(\d{11,14})\s+(.+?)\s+(\d{2}\/\d{2}\/\d{4})\s+([A-Z0-9]{1,6})\s+(\d{1,4})\s+(.*?)\s+(\d{1,3}(?:,\d{3})*\.\d{2})\s+(\d{1,3}(?:,\d{3})*\.\d{2})\s+(\d{1,3}(?:,\d{3})*\.\d{2})$/;

function parseAms(rows) {
    const header = {}, items = [], totals = {};
    for (const line of rows.map(rowText)) {
        let m;
        if ((m = line.match(/INVOICE:\s*(\d+)/)) && !header.invoice) header.invoice = m[1];
        if ((m = line.match(/AMS REF #:\s*(\d+)/)) && !header.amsRef) header.amsRef = m[1];
        if ((m = line.match(/Account #:\s*(\d+)/)) && !header.account) header.account = m[1];
        if ((m = line.match(/Date:\s*([A-Z][a-z]{2})\s+(\d{1,2})\s+(\d{4})/)) && !header.date)
            header.date = `${m[3]}-${String(MONTHS[m[1]]+1).padStart(2,'0')}-${String(+m[2]).padStart(2,'0')}`;
        if ((m = line.match(/Total Goods\s*\$?\s*([\d,]+\.\d{2})/))) totals.goods = num(m[1]);
        const lm = line.match(AMS_LINE_RE);
        if (lm) {
            items.push({upc: lm[1], description: lm[2].trim(), prodType: lm[4],
                qty: +lm[5], unitPrice: num(lm[8]), lineTotal: num(lm[9])});
        }
    }
    return {header, items, totals};
}

// ---- Alliance parser ----
// Columns are found from the header row (Product # / CF / Description /
// Qty Ord / Ship / B/O / List Price / Unit Price / Extended Amt). Numbers are
// right-aligned, so each number goes to the last column whose header starts
// left of the number's right edge. qty = Qty SHIPPED (back-orders come on a
// later invoice); ship-0 lines are skipped; the "INFORMATION PAGE" at the end
// repeats back-ordered lines and is ignored.
function parseAlliance(rows) {
    const header = {}, items = [], totals = {};
    const text = rows.map(rowText).join('\n');
    let m;
    if ((m = text.match(/Invoice #\s*(PLS\d+)/))) header.invoice = m[1];
    if ((m = text.match(/Account:\s*(\d{6})/))) header.account = m[1];
    if ((m = text.match(/Invoice Date\s+(\d{2})\/(\d{2})\/(\d{2,4})/))) {
        const y = m[3].length === 2 ? '20' + m[3] : m[3];
        header.date = `${y}-${m[1]}-${m[2]}`;
    }
    if ((m = text.match(/P\/O #\s*(\S+)/))) header.amsRef = m[1];
    const subs = [...text.matchAll(/Subtotal\s+([\d,]+\.\d{2})/g)];
    if (subs.length) totals.goods = num(subs[subs.length - 1][1]);
    if ((m = text.match(/Invoice Amount\s+([\d,]+\.\d{2})/))) totals.amount = num(m[1]);

    // Column x positions (defaults = the layout of the 10/2026 invoices).
    // Only the first page's header counts (page 2 prints a different one).
    const col = {ord: 346, ship: 368, bo: 390, list: 412, unit: 458, ext: 505};
    const HEAD = {'Ord': 'ord', 'Ship': 'ship', 'B/O': 'bo', 'List Price': 'list', 'Unit Price': 'unit', 'Extended Amt': 'ext'};
    const seen = {};
    for (const r of rows) {
        if (r.y > 150000) break;
        for (const p of r.parts) {
            const k = HEAD[clean(p.str).trim()];
            if (k && !seen[k]) { seen[k] = true; col[k] = p.x; }
        }
    }
    const numCols = [['ord', col.ord], ['ship', col.ship], ['bo', col.bo], ['list', col.list], ['unit', col.unit], ['ext', col.ext]];
    const colFor = p => {
        const right = p.x + (p.width || 0) - 2;
        let name = null;
        for (const [k, x] of numCols) if (x <= right) name = k;
        return name;
    };

    for (const r of rows) {
        const t = rowText(r);
        if (/INFORMATION PAGE/.test(t)) break;
        // Product # is the first thing on a line, then the CF format code
        // (01 LP, 02 CD, TO toys), then the description up to the Qty columns.
        const upc = r.parts[0];
        if (!upc || !/^\d{11,14}$/.test(upc.str.trim()) || upc.x > col.ord / 3) continue;
        const cf = r.parts[1] && /^[A-Z0-9]{2}$/.test(r.parts[1].str.trim()) ? r.parts[1] : null;
        const desc = r.parts.filter(p => p !== upc && p !== cf && p.x < col.ord - 4).map(p => clean(p.str)).join(' ').replace(/\s+/g, ' ').trim();
        const v = {};
        for (const p of r.parts) {
            if (p.x < col.ord - 20) continue;
            const s = p.str.trim();
            if (!/^[\d,]+(\.\d{2})?$/.test(s)) continue;
            const k = colFor(p);
            if (k) v[k] = num(s);
        }
        const ship = v.ship || 0;
        if (ship <= 0) continue;
        items.push({upc: upc.str.trim(), description: desc, prodType: cf ? cf.str.trim() : '',
            qty: ship, unitPrice: v.unit || 0, lineTotal: v.ext != null ? v.ext : Math.round(ship * (v.unit || 0) * 100) / 100,
            ordered: v.ord || null, backordered: v.bo || 0});
    }
    return {header, items, totals};
}

// ---- UI wiring ----
const $ = id => document.getElementById(id);
const csrf = $('inv_csrf').value;
let parsed = null, previewLines = null;

function status(html, kind) {
    $('inv_status').innerHTML = html ? `<div class="alert alert-${kind || 'info'}" style="margin:0;">${html}</div>` : '';
}
const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const money = n => n == null ? '' : '$' + Number(n).toFixed(2);

async function handleFile(file) {
    if (!file || file.type !== 'application/pdf') { status('Please choose a PDF file.', 'warning'); return; }
    status('Reading ' + esc(file.name) + '…');
    try {
        const buf = new Uint8Array(await file.arrayBuffer());
        const doc = await pdfjsLib.getDocument({data: buf}).promise;
        const allItems = [];
        for (let p = 1; p <= doc.numPages; p++) {
            const page = await doc.getPage(p);
            const tc = await page.getTextContent();
            for (const it of tc.items)
                allItems.push({str: it.str, x: it.transform[4], width: it.width, y: (p * 100000) - it.transform[5]});
        }
        const rows = rowsFromItems(allItems);
        parsed = DISTRIBUTOR === 'alliance' ? parseAlliance(rows) : parseAms(rows);
        if (!parsed.items.length) { status('No invoice line items found. Is this an ' + esc(DISTRIBUTOR.toUpperCase()) + ' invoice PDF?', 'danger'); return; }
        parsed.sum = Math.round(parsed.items.reduce((a, b) => a + b.lineTotal, 0) * 100) / 100;
        const goods = parsed.totals.goods;
        parsed.goodsMatches = goods != null ? Math.abs(parsed.sum - goods) < 0.01 : null;

        const acctLoc = parsed.header.account ? ACCOUNT_LOCATIONS[parsed.header.account] : null;
        if (acctLoc) $('inv_location').value = String(acctLoc);

        const chk = parsed.goodsMatches === false
            ? ` <strong style="color:#c0392b;">The line totals DON'T match the invoice (read $${parsed.sum.toFixed(2)} vs $${goods.toFixed(2)}). Check the lines below.</strong>`
            : (parsed.goodsMatches ? ' Line totals match the invoice.' : '');
        status(`Read invoice <strong>${esc(parsed.header.invoice || '?')}</strong> (${parsed.header.date || '?'}${parsed.header.account ? ', account ' + esc(parsed.header.account) : ''}): ${parsed.items.length} lines, $${parsed.sum.toFixed(2)}.${chk} Matching to products…`, parsed.goodsMatches === false ? 'warning' : 'info');
        await runPreview();
    } catch (e) {
        status('Could not read PDF: ' + esc(e.message), 'danger');
    }
}

async function runPreview() {
    const res = await fetch(BASE + '/preview', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
        body: JSON.stringify({header: parsed.header, items: parsed.items, supplier_id: $('inv_supplier').value, location_id: $('inv_location').value}),
    });
    const data = await res.json();
    if (!data.success) { status(esc(data.msg || 'Match failed.'), 'danger'); return; }
    previewLines = data.lines;
    renderReview(data);
}

function catSelect(i, selectedId) {
    const opts = Object.entries(CATEGORIES).map(([name, id]) =>
        `<option value="${id}" ${String(id) === String(selectedId) ? 'selected' : ''}>${esc(name)}</option>`).join('');
    return `<select class="form-control input-sm inv-cat" data-i="${i}" style="min-width:130px;"><option value="">pick one</option>${opts}</select>`;
}

function renderReview(data) {
    $('inv_review_box').style.display = '';
    const newCount = data.lines.filter(l => !l.matched).length;
    const existCount = data.lines.filter(l => l.existing && l.existing.length).length;
    $('inv_summary').innerHTML = `<span class="label label-success">${data.matched} matched</span> <span class="label label-primary">${newCount} new product${newCount === 1 ? '' : 's'}</span>` +
        (existCount ? ` <span class="label label-warning">${existCount} already on a purchase</span>` : '');
    $('inv_dupe_warn').innerHTML = data.already_imported
        ? `<div class="alert alert-warning">Invoice <strong>${esc(parsed.header.invoice)}</strong> was already imported as purchase #${esc(data.already_imported.transaction_id)} on ${esc(data.already_imported.applied_at)}. Tick "import anyway" below to create it again.</div>`
        : '';
    $('inv_rows').innerHTML = data.lines.map((l, i) => {
        const onOther = l.existing && l.existing.length;
        const note = onOther
            ? 'Already on ' + l.existing.map(e => `<a href="/purchases/${e.transaction_id}" target="_blank">purchase #${e.transaction_id}</a> (${esc(e.status)}, qty ${Number(e.qty)})`).join(', ')
            : (l.product_inactive ? 'Matched product is inactive' : '');
        if (l.matched) {
            return `<tr${onOther ? ' style="background:#fff8e6;"' : ''}><td><input type="checkbox" class="inv-pick" data-i="${i}" ${onOther ? '' : 'checked'}></td>
                <td>${esc(l.upc)}</td><td>${esc(l.description)}</td><td><a href="/products/view/${l.product_id}" target="_blank">${esc(l.product_name)}</a></td><td></td>
                <td style="text-align:right;">${l.qty}</td><td style="text-align:right;">${money(l.unit_price)}</td>
                <td style="text-align:right;"></td><td style="text-align:right;">${money(l.line_total)}</td><td>${note}</td></tr>`;
        }
        const np = l.new_product;
        return `<tr style="background:#eef6ff;"><td><input type="checkbox" class="inv-pick" data-i="${i}" ${np.category_id ? 'checked' : ''}></td>
            <td>${esc(np.sku)}</td><td>${esc(l.description)}</td>
            <td><span class="label label-primary">NEW</span> <input type="text" class="form-control input-sm inv-name" data-i="${i}" value="${esc(np.name)}" style="min-width:240px;"></td>
            <td>${catSelect(i, np.category_id)}</td>
            <td style="text-align:right;">${l.qty}</td><td style="text-align:right;">${money(l.unit_price)}</td>
            <td style="text-align:right;"><input type="number" step="0.01" min="0" class="form-control input-sm inv-sell" data-i="${i}" value="${np.sell_price != null ? np.sell_price.toFixed(2) : ''}" style="width:90px;display:inline-block;"></td>
            <td style="text-align:right;">${money(l.line_total)}</td><td>${np.category_id ? '' : 'Pick a category'}</td></tr>`;
    }).join('');
    status('', 'info');
    $('inv_review_box').scrollIntoView({behavior: 'smooth'});
}

$('inv_apply').addEventListener('click', async () => {
    const supplier = $('inv_supplier').value, location = $('inv_location').value;
    if (!supplier) { status('Pick a supplier first.', 'warning'); return; }
    const picks = [...document.querySelectorAll('.inv-pick:checked')].map(c => {
        const i = +c.dataset.i, l = previewLines[i];
        const line = {variation_id: l.variation_id, qty: l.qty, unit_price: l.unit_price, upc: l.upc, description: l.description};
        if (!l.matched) {
            const q = sel => document.querySelector(`${sel}[data-i="${i}"]`);
            line.new_product = {name: q('.inv-name').value.trim(), artist: l.new_product.artist, sku: l.new_product.sku,
                category_id: q('.inv-cat').value, sell_price: q('.inv-sell').value};
        }
        return line;
    });
    if (!picks.length) { status('No lines ticked.', 'warning'); return; }
    $('inv_apply').disabled = true;
    const res = await fetch(BASE + '/apply', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
        body: JSON.stringify({
            location_id: location, supplier_id: supplier,
            invoice: parsed.header.invoice, ams_ref: parsed.header.amsRef, invoice_date: parsed.header.date,
            force: $('inv_force').checked, lines: picks,
        }),
    });
    const data = await res.json();
    $('inv_apply').disabled = false;
    if (!data.success) { status(esc(data.msg || 'Import failed.'), 'danger'); return; }
    $('inv_review_box').style.display = 'none';
    status(`${esc(data.msg)} <a href="${esc(data.view_url)}" class="btn btn-xs btn-default">View purchase</a>`, 'success');
});

// Store or supplier changed after reading: re-run the match so the
// "already on a purchase" check uses the right store/supplier.
['inv_location', 'inv_supplier'].forEach(id => $(id).addEventListener('change', () => { if (parsed) runPreview(); }));

$('inv_drop').addEventListener('click', () => $('inv_file').click());
$('inv_file').addEventListener('change', e => handleFile(e.target.files[0]));
['dragover', 'dragenter'].forEach(ev => $('inv_drop').addEventListener(ev, e => { e.preventDefault(); $('inv_drop').style.background = '#f1f1e4'; }));
['dragleave', 'drop'].forEach(ev => $('inv_drop').addEventListener(ev, e => { e.preventDefault(); $('inv_drop').style.background = '#fbfbf7'; }));
$('inv_drop').addEventListener('drop', e => handleFile(e.dataTransfer.files[0]));
</script>
@endsection
