@extends('layouts.app')
@section('title', 'Import ' . $cfg['label'] . ' Invoice')

@section('content')
<section class="content-header">
    <h1>Import {{ $cfg['label'] }} Invoice (PDF)</h1>
    <p style="max-width:900px;">
        @if ($distributor === 'vinylfuture')
        Drop a {{ $cfg['long'] }} invoice PDF below. It's read in your browser. Each line is matched to a product by
        catalog number or artist + title (these invoices have no barcodes), and titles we don't carry yet get a new
        product (name, category, cost and price filled in for you to check). Euro prices are converted to dollars at the
        invoice date's rate, with postage and fees spread into each record's cost.
        @else
        Drop an {{ $cfg['long'] }} invoice PDF below. It's read in your browser, each UPC is matched to a product,
        and titles we don't carry yet get a new product (name, barcode, category, cost and price filled in for you to check).
        @endif
        Confirming logs one purchase on the <a href="/purchases">Purchases</a> list with every ticked line, so the store
        can receive the whole box. No stock or existing costs change until it's received.
        Every import is undoable at <a href="/admin/admin-action-history">admin-action-history</a>.
        @if ($distributor === 'ams')
            Alliance invoices go in at <a href="/admin/alliance-invoice-import">Import Alliance Invoice</a>.
        @elseif ($distributor === 'vinylfuture')
            AMS and Alliance invoices go in at <a href="/admin/ams-invoice-import">Import AMS Invoice</a> and <a href="/admin/alliance-invoice-import">Import Alliance Invoice</a>.
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
        @if ($distributor === 'vinylfuture')
        <div class="row">
            <div class="col-sm-3 form-group">
                <label>EUR to USD rate</label>
                <input type="number" step="0.0001" min="0" id="inv_fx" class="form-control" placeholder="looked up from the invoice date">
            </div>
            <div class="col-sm-2 form-group">
                <label>Tariff %</label>
                <input type="number" step="0.1" min="0" max="100" id="inv_tariff" class="form-control" value="10">
            </div>
            <div class="col-sm-3 form-group">
                <label>Other costs paid ($)</label>
                <input type="number" step="0.01" min="0" id="inv_other" class="form-control" placeholder="e.g. 25 for Clark's prep">
            </div>
            <div class="col-sm-4 form-group" style="padding-top:28px;">
                <label style="font-weight:normal;"><input type="checkbox" id="inv_landed" checked> Include the invoice's postage + fees</label>
            </div>
        </div>
        <div id="inv_fx_info" style="margin:-6px 0 12px;color:#555;"></div>
        @endif

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
                        <th></th><th>{{ $distributor === 'vinylfuture' ? 'Cat #' : 'UPC' }}</th><th>Invoice line</th><th>Product</th><th>Category</th>
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
        <span style="margin-left:10px;">Ticked lines are imported. New products are created first. Lines whose invoice is already logged on another purchase start unticked so nothing is doubled.</span>
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
const STORE_LOCATIONS = {!! json_encode((object) ($store_locations ?? [])) !!}; // store name => id
const LABEL = {!! json_encode($cfg['label']) !!};

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

// ---- Vinyl Future (deejay.de) parser ----
// Lines: "SW10 - Chris Pascoe- Darkside 11,99 4 47,96" = catalog # - Artist-
// Title, unit price, qty, sum. Euro amounts with a decimal comma. A title too
// long for its cell wraps onto the next row, which is added to the line above.
// The same record on two lines (deejay splits them) is merged.
const eur = s => parseFloat(String(s).replace(/\./g, '').replace(',', '.'));
function parseVinylFuture(rows) {
    const header = {currency: 'EUR'}, items = [], totals = {};
    const lines = rows.map(rowText);
    const text = lines.join('\n');
    let m;
    if ((m = text.match(/Invoice No\.?:\s*([0-9][0-9-]+)/))) header.invoice = m[1];
    if ((m = text.match(/Date:\s*(\d{1,2})\.(\d{1,2})\.(\d{4})/))) header.date = `${m[3]}-${m[2].padStart(2,'0')}-${m[1].padStart(2,'0')}`;
    if ((m = text.match(/Currency:\s*([A-Z]{3})/))) header.currency = m[1];
    // Footer: one row of labels ("Quantity Postage: Fees: Net: VAT. 7%: ...
    // Total:"), the next row their values ("54 89,70 23,59 772,45 ...").
    const foot = {};
    const fi = lines.findIndex(l => /Postage:/.test(l) && /Total:/.test(l));
    if (fi >= 0 && lines[fi + 1]) {
        const labels = lines[fi].replace(/^Quantity\s+/, 'Quantity: ').split(':').map(x => x.trim()).filter(Boolean);
        const vals = lines[fi + 1].trim().split(/\s+/);
        if (labels.length === vals.length) labels.forEach((k, i) => { foot[k] = vals[i]; });
    } else {
        for (const k of ['Postage', 'Fees', 'Net', 'Total']) {
            const r = text.match(new RegExp(k + ':\\s*([\\d.]*\\d,\\d{2})'));
            if (r) foot[k] = r[1];
        }
    }
    header.postage = foot.Postage ? eur(foot.Postage) : 0;
    header.fees = foot.Fees ? eur(foot.Fees) : 0;
    if (foot.Net) totals.net = eur(foot.Net);
    if (foot.Total) totals.amount = eur(foot.Total);
    if (foot.Quantity) totals.qty = +foot.Quantity;
    if (totals.net) totals.goods = Math.round((totals.net - header.postage - header.fees) * 100) / 100;
    const ri = text.indexOf('Recipient'), di = text.indexOf('Description', ri);
    const rec = ri >= 0 ? text.slice(ri, di > ri ? di : ri + 400) : '';
    if (/\bPico\b/i.test(rec)) header.store = 'pico';
    else if (/Hollywood|Sunset|Cahuenga/i.test(rec)) header.store = 'hollywood';

    const LINE = /^(.+?)\s+(\d{1,4}(?:\.\d{3})*,\d{2})\s+(\d{1,4})\s+(\d{1,3}(?:\.\d{3})*,\d{2})$/;
    let inTable = false, last = null;
    for (const line of lines) {
        if (/^Description\b.*Unit Price.*Quantity.*Sum/.test(line)) { inTable = true; last = null; continue; }
        if (!inTable) continue;
        if (/^(Quantity|note next page|Invoice No|Date:|Page:)/i.test(line)) { inTable = false; last = null; continue; }
        const lm = line.match(LINE);
        if (!lm) { if (last) last.raw += ' ' + line; continue; }
        last = {raw: lm[1], unitPrice: eur(lm[2]), qty: +lm[3], lineTotal: eur(lm[4])};
        items.push(last);
    }
    const merged = [];
    for (const it of items) {
        const raw = it.raw.replace(/\s+/g, ' ').trim();
        const cm = raw.match(/^(.+?)\s+-\s+(.+)$/);
        const catno = cm ? cm[1].trim() : '';
        const rest = cm ? cm[2] : raw;
        const am = rest.match(/^(.+?)\s*-\s+(.+)$/);
        const artist = am ? am[1].trim() : '', title = am ? am[2].trim() : rest.trim();
        const dupe = merged.find(x => x.catno === catno && x.description === raw && x.unitPrice === it.unitPrice);
        if (dupe) { dupe.qty += it.qty; dupe.lineTotal = Math.round((dupe.lineTotal + it.lineTotal) * 100) / 100; continue; }
        merged.push({upc: '', catno, artist, title, description: raw, prodType: '', qty: it.qty, unitPrice: it.unitPrice, lineTotal: it.lineTotal});
    }
    return {header, items: merged, totals};
}

// ---- UI wiring ----
const $ = id => document.getElementById(id);
const csrf = $('inv_csrf').value;
let parsed = null, previewLines = null, fxInfo = null;

function status(html, kind) {
    $('inv_status').innerHTML = html ? `<div class="alert alert-${kind || 'info'}" style="margin:0;">${html}</div>` : '';
}
const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const money = n => n == null ? '' : '$' + Number(n).toFixed(2);
const cost = l => money(l.unit_price) + (l.unit_price_eur != null ? `<br><small style="color:#888;">€${Number(l.unit_price_eur).toFixed(2)}</small>` : '');

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
        parsed = DISTRIBUTOR === 'alliance' ? parseAlliance(rows) : (DISTRIBUTOR === 'vinylfuture' ? parseVinylFuture(rows) : parseAms(rows));
        if (!parsed.items.length) { status('No invoice line items found. Is this a ' + esc(LABEL) + ' invoice PDF?', 'danger'); return; }
        parsed.sum = Math.round(parsed.items.reduce((a, b) => a + b.lineTotal, 0) * 100) / 100;
        const goods = parsed.totals.goods;
        parsed.goodsMatches = goods != null ? Math.abs(parsed.sum - goods) < 0.01 : null;

        const acctLoc = parsed.header.account ? ACCOUNT_LOCATIONS[parsed.header.account] : null;
        if (acctLoc) $('inv_location').value = String(acctLoc);
        const storeLoc = parsed.header.store ? STORE_LOCATIONS[parsed.header.store] : null;
        if (storeLoc) $('inv_location').value = String(storeLoc);
        const cur = parsed.header.currency === 'EUR' ? '€' : '$';

        const chk = parsed.goodsMatches === false
            ? ` <strong style="color:#c0392b;">The line totals DON'T match the invoice (read ${cur}${parsed.sum.toFixed(2)} vs ${cur}${goods.toFixed(2)}). Check the lines below.</strong>`
            : (parsed.goodsMatches ? ' Line totals match the invoice.' : '');
        status(`Read invoice <strong>${esc(parsed.header.invoice || '?')}</strong> (${parsed.header.date || '?'}${parsed.header.account ? ', account ' + esc(parsed.header.account) : ''}${parsed.header.store ? ', ship to ' + esc(parsed.header.store) : ''}): ${parsed.items.length} lines, ${cur}${parsed.sum.toFixed(2)}.${chk} Matching to products…`, parsed.goodsMatches === false ? 'warning' : 'info');
        await runPreview();
    } catch (e) {
        status('Could not read PDF: ' + esc(e.message), 'danger');
    }
}

async function runPreview() {
    const res = await fetch(BASE + '/preview', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json'},
        body: JSON.stringify({header: parsed.header, items: parsed.items, supplier_id: $('inv_supplier').value, location_id: $('inv_location').value,
            fx_rate: $('inv_fx') ? $('inv_fx').value : '', landed: $('inv_landed') ? $('inv_landed').checked : true,
            tariff_pct: $('inv_tariff') ? $('inv_tariff').value : 0, other_usd: $('inv_other') ? $('inv_other').value : 0}),
    });
    const data = await res.json();
    if (!data.success) { status(esc(data.msg || 'Match failed.'), 'danger'); if (data.need_rate && $('inv_fx')) $('inv_fx').focus(); return; }
    fxInfo = data.fx || null;
    if (fxInfo && $('inv_fx')) {
        if (!$('inv_fx').value) $('inv_fx').placeholder = fxInfo.rate;
        const usd = n => '$' + Number(n).toFixed(2);
        const parts = [`records ${usd(fxInfo.goods_eur * fxInfo.rate)} (€${fxInfo.goods_eur.toFixed(2)} at $${fxInfo.rate}, ${fxInfo.rate_date})`];
        if (fxInfo.landed && fxInfo.extra_eur) parts.push(`postage + fees ${usd(fxInfo.extra_eur * fxInfo.rate)} (${Math.round(fxInfo.extra_eur / fxInfo.goods_eur * 100)}%)`);
        if (fxInfo.tariff_pct) parts.push(`tariff ${fxInfo.tariff_pct}% ${usd(fxInfo.tariff_usd)}`);
        if (fxInfo.other_usd) parts.push(`other ${usd(fxInfo.other_usd)}`);
        $('inv_fx_info').innerHTML = `<strong>Landed cost ${usd(fxInfo.landed_usd)}</strong> = ` + parts.join(' + ') +
            `. Each €1 of record costs <strong>$${fxInfo.factor.toFixed(3)}</strong>.`;
    }
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
            ? 'This invoice is already on ' + l.existing.map(e => `<a href="/purchases/${e.transaction_id}" target="_blank">purchase #${e.transaction_id}</a> (${esc(e.status)}, qty ${Number(e.qty)})`).join(', ')
            : ((l.earlier && l.earlier.length)
                ? 'Also on earlier ' + l.earlier.slice(0, 2).map(e => `<a href="/purchases/${e.transaction_id}" target="_blank">#${e.transaction_id}</a> (${esc(e.status)})`).join(', ') + ', imported as new copies'
                : (l.product_inactive ? 'Matched product is inactive' : ''));
        if (l.matched) {
            return `<tr${onOther ? ' style="background:#fff8e6;"' : ''}><td><input type="checkbox" class="inv-pick" data-i="${i}" ${onOther ? '' : 'checked'}></td>
                <td>${esc(l.upc || l.catno)}</td><td>${esc(l.description)}</td><td><a href="/products/view/${l.product_id}" target="_blank">${esc(l.product_name)}</a></td><td></td>
                <td style="text-align:right;">${l.qty}</td><td style="text-align:right;">${cost(l)}</td>
                <td style="text-align:right;"></td><td style="text-align:right;">${money(l.line_total)}</td><td>${note}</td></tr>`;
        }
        const np = l.new_product;
        return `<tr style="background:#eef6ff;"><td><input type="checkbox" class="inv-pick" data-i="${i}" ${np.category_id ? 'checked' : ''}></td>
            <td>${esc(np.sku || l.catno)}</td><td>${esc(l.description)}</td>
            <td><span class="label label-primary">NEW</span> <input type="text" class="form-control input-sm inv-name" data-i="${i}" value="${esc(np.name)}" style="min-width:240px;"></td>
            <td>${catSelect(i, np.category_id)}</td>
            <td style="text-align:right;">${l.qty}</td><td style="text-align:right;">${cost(l)}</td>
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
        const line = {variation_id: l.variation_id, qty: l.qty, unit_price: l.unit_price, upc: l.upc, description: l.description, catno: l.catno || ''};
        if (!l.matched) {
            const q = sel => document.querySelector(`${sel}[data-i="${i}"]`);
            line.new_product = {name: q('.inv-name').value.trim(), artist: l.new_product.artist, sku: l.new_product.sku,
                category_id: q('.inv-cat').value, sell_price: q('.inv-sell').value, sub_category_id: l.new_product.sub_category_id};
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
            fx_note: fxInfo ? `EUR invoice total ${(parsed.totals.amount || 0).toFixed(2)} at ${fxInfo.rate} USD/EUR${fxInfo.landed ? ', postage+fees' : ''}${fxInfo.tariff_pct ? ', ' + fxInfo.tariff_pct + '% tariff' : ''}${fxInfo.other_usd ? ', other costs ' + fxInfo.other_usd.toFixed(2) + ' USD' : ''} in unit costs, landed ${fxInfo.landed_usd.toFixed(2)} USD` : '',
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
['inv_location', 'inv_supplier', 'inv_fx', 'inv_landed', 'inv_tariff', 'inv_other'].forEach(id => $(id) && $(id).addEventListener('change', () => { if (parsed) runPreview(); }));

$('inv_drop').addEventListener('click', () => $('inv_file').click());
$('inv_file').addEventListener('change', e => handleFile(e.target.files[0]));
['dragover', 'dragenter'].forEach(ev => $('inv_drop').addEventListener(ev, e => { e.preventDefault(); $('inv_drop').style.background = '#f1f1e4'; }));
['dragleave', 'drop'].forEach(ev => $('inv_drop').addEventListener(ev, e => { e.preventDefault(); $('inv_drop').style.background = '#fbfbf7'; }));
$('inv_drop').addEventListener('drop', e => handleFile(e.dataTransfer.files[0]));
</script>
@endsection
