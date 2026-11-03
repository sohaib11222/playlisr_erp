@extends('layouts.app')
@section('title', 'Store or Online?')

@section('content')
<style>
.sd-wrap { max-width: 1180px; margin: 0 auto; padding: 24px 16px 60px; color: #1F1B16; }
.sd-wrap h1 { font-size: 26px; font-weight: 700; margin: 0 0 4px; }
.sd-help { color: #6B6155; font-size: 14px; margin: 0 0 14px; }
.sd-input { width: 100%; min-height: 180px; font-size: 15px; border: 1px solid #E1D7C4; border-radius: 12px; padding: 12px 14px; font-family: inherit; }
.sd-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 10px 0 0; }
.sd-btn { border: 1px solid #E1D7C4; background: #fff; border-radius: 10px; padding: 9px 16px; font-size: 15px; cursor: pointer; }
.sd-btn.primary { background: #1F1B16; color: #fff; border-color: #1F1B16; font-weight: 600; }
.sd-btn[disabled] { opacity: .5; cursor: default; }
.sd-status { color: #6B6155; font-size: 14px; }
.sd-type { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 0 0 10px; font-size: 15px; }
.sd-type select { height: 38px; border: 1px solid #E1D7C4; border-radius: 10px; padding: 0 10px; font-size: 15px; background: #fff; }
.sd-summary { display: flex; flex-wrap: wrap; gap: 8px; margin: 18px 0 8px; }
.sd-chip { border: 1px solid #E1D7C4; background: #fff; border-radius: 999px; padding: 6px 14px; font-size: 14px; cursor: pointer; }
.sd-chip.on { background: #1F1B16; color: #fff; border-color: #1F1B16; }
.sd-table { width: 100%; border-collapse: collapse; font-size: 14px; background: #fff; border: 1px solid #E6DCCF; border-radius: 12px; overflow: hidden; }
.sd-table th { text-align: left; background: #FAF6EE; padding: 8px 8px; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; color: #6B6155; white-space: nowrap; }
.sd-table td { padding: 7px 8px; border-top: 1px solid #F3ECE1; vertical-align: top; }
.sd-num { text-align: right; white-space: nowrap; }
.sd-zero { color: #B9AFA2; }
.sd-sub { color: #8E8273; font-size: 12px; }
.sd-v { display: inline-block; border-radius: 8px; padding: 3px 9px; font-weight: 700; font-size: 13px; white-space: nowrap; }
.sd-v.store { background: #E3F2E1; color: #24622A; }
.sd-v.discogs, .sd-v.ebay { background: #FBE3DC; color: #9A3A1F; }
.sd-v.never_sold { background: #FCEFD9; color: #8A5A12; }
.sd-v.never_had { background: #E4EEF7; color: #2D5878; }
.sd-v.unknown { background: #EEE; color: #666; }
.sd-v.cheap { background: #ECE7F6; color: #5A3F8C; }
.sd-min { width: 64px; height: 36px; border: 1px solid #E1D7C4; border-radius: 8px; padding: 0 8px; font-size: 15px; }
.sd-scroll { overflow-x: auto; }
.sd-table th[data-sort] { cursor: pointer; user-select: none; }
.sd-table th[data-sort]:hover { color: #1F1B16; }
.sd-arrow { font-size: 10px; margin-left: 3px; }
.sd-cond { height: 32px; border: 1px solid #E1D7C4; border-radius: 8px; padding: 0 6px; font-size: 14px; background: #fff; }
.sd-rid { width: 92px; height: 32px; border: 1px solid #E1D7C4; border-radius: 8px; padding: 0 6px; font-size: 14px; }
.sd-rm { border: 1px solid #E1D7C4; background: #fff; color: #9A3A1F; border-radius: 8px; width: 28px; height: 28px; font-size: 16px; line-height: 1; cursor: pointer; }
.sd-rm:hover { background: #FBE3DC; }
.sd-first { white-space: nowrap; }
.sd-first .sd-rm { margin-left: 6px; vertical-align: middle; }
.sd-vcol { min-width: 110px; max-width: 170px; }
.sd-name { max-width: 190px; }
.sd-why { min-width: 160px; max-width: 260px; }
.sd-stack { text-align: right; white-space: nowrap; font-size: 13px; line-height: 1.45; }
.sd-stack span { color: #8E8273; }
.sd-vcol .sd-v { white-space: normal; }
.sd-note { background: #FFF6DB; border: 1px solid #F0D98A; color: #6B4E00; border-radius: 12px; padding: 10px 14px; font-size: 14px; margin: 0 0 14px; }
</style>
<div class="sd-wrap">
    <h1>Store or Online?</h1>
    <p class="sd-help">Paste one item per line. For music: <b>Artist - Title</b> (or two spreadsheet columns, or just "artist title"). For anything else, the item's name. Checks every line against everything we've ever sold.</p>
    <div class="sd-note"><b>Prices here are only an estimate.</b> The suggested price is a starting point from our past sales, shelf prices and online prices; it doesn't know the condition of your copy. Check the condition (media, sleeve, pressing), look up the price yourself on Discogs / eBay, and set your own price.</div>
    <div class="sd-type">
        <b>What are these?</b>
        <select id="sdCat">
            <option value="">Music (all formats)</option>
            <optgroup label="One music format">
                @foreach ($categories as $c)
                    @if ($c['music'])<option value="{{ $c['id'] }}" data-music="1">{{ $c['name'] }}</option>@endif
                @endforeach
            </optgroup>
            <optgroup label="Not music (checks eBay)">
                @foreach ($categories as $c)
                    @if (!$c['music'])<option value="{{ $c['id'] }}" data-music="0">{{ $c['name'] }}</option>@endif
                @endforeach
            </optgroup>
        </select>
    </div>
    <textarea class="sd-input" id="sdIn" placeholder="Wu-Tang Clan - Forever&#10;Fleetwood Mac - Rumours&#10;paul simon still crazy after all these years"></textarea>
    <div class="sd-bar">
        <button class="sd-btn primary" id="sdGo">Check</button>
        <button class="sd-btn" id="sdBuys" title="Fill the box with the unpriced buys of this type from Price New Buys">Load unpriced buys</button>
        <label class="sd-status" style="margin:0 6px">Too cheap to list online under $<input class="sd-min" id="sdMin" type="number" min="0" step="1" value="{{ (int) $minDiscogsValue }}"></label>
        <button class="sd-btn" id="sdCopy" style="display:none">Copy results</button>
        <button class="sd-btn" id="sdPrompt" style="display:none" title="Copies a pricing prompt for the checked items (or all shown) with each one's condition and suggested price, to paste into an AI">Copy pricing prompt</button>
        <button class="sd-btn" id="sdPickAll" style="display:none">Select all</button>
        <button class="sd-btn" id="sdPickNone" style="display:none">Clear</button>
        <button class="sd-btn primary" id="sdSend" style="display:none">Send checked to Mass Add</button>
        <span class="sd-status" id="sdStatus"></span>
    </div>
    <div class="sd-summary" id="sdSummary"></div>
    <div class="sd-scroll" id="sdOut"></div>
    <p class="sd-help" style="margin-top:14px">
        <b>Music:</b> sold this title in the last {{ $recentYears }} years → the store that sold it most.
        Sold the artist → the store that sold the artist most, unless: no artist sales in {{ $recentYears }} years, or we already have more than {{ $overstockRatio }}× what the artist sold in the last 12 months on the shelves, or under {{ $minSellThrough }}% of the artist we've stocked ever sold → Discogs.
        Picking one format (e.g. CD) only counts that format.<br>
        <b>Not music:</b> matched by name within that type. Sold in the last {{ $recentYears }} years → store; sold before but not lately, or too many on hand → eBay.<br>
        <b>Discogs / eBay</b> then get their online price checked; under the amount above it's <b>Bargain bin</b>: not worth listing, and it doesn't move in the store either.
        <b>Never sold</b> = we've had it but none sold; if one store never even stocked it, it also says <b>Never had for sale at</b> that store, and it shows how many days it's been sitting at each store. <b>Never had for sale</b> = we've never carried it. Neither is a call; the online price is shown so you can decide.
    </p>
</div>
@endsection

@section('javascript')
<script>
(function () {
    var CHUNK = {{ (int) $maxLines }};
    var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var input = document.getElementById('sdIn'), go = document.getElementById('sdGo'), status = document.getElementById('sdStatus');
    var out = document.getElementById('sdOut'), summary = document.getElementById('sdSummary'), copyBtn = document.getElementById('sdCopy');
    var catSel = document.getElementById('sdCat'), minIn = document.getElementById('sdMin');
    var promptBtn = document.getElementById('sdPrompt'), sendBtn = document.getElementById('sdSend'), pickAll = document.getElementById('sdPickAll'), pickNone = document.getElementById('sdPickNone');
    var rows = [], filter = 'all', mode = 'music', runCat = '', runId = 0, otherStore = 'Other';
    var LABEL = { store: 'Store', discogs: 'Discogs', ebay: 'eBay', never_sold: 'Never sold', never_had: 'Never had for sale', cheap: 'Bargain bin', unknown: '?' };
    var ONLINE = { discogs: 1, ebay: 1, never_sold: 1, never_had: 1 };

    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function sum(o) { return (o.pico || 0) + (o.hollywood || 0) + (o.other || 0); }
    function num(n) { return n > 0 ? String(Math.round(n * 100) / 100) : '<span class="sd-zero">0</span>'; }
    function split(o) { return sum(o) > 0 ? '<div class="sd-sub">P ' + (o.pico || 0) + ' · HW ' + (o.hollywood || 0) + '</div>' : ''; }
    function money(n) { return '$' + Number(n).toFixed(2); }
    function minValue() { var v = parseFloat(minIn.value); return isNaN(v) ? 0 : v; }
    function post(url, body) {
        return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
    }

    // Final call = server verdict, then the online-price gate.
    function fin(r) {
        var p = r.dv && r.dv.price != null ? r.dv.price : null, cheap = p != null && p < minValue();
        var where = r.where || LABEL[r.verdict], src = r.dv && r.dv.source === 'ebay' ? 'eBay' : 'Discogs';
        if (cheap && (r.verdict === 'discogs' || r.verdict === 'ebay')) {
            return { v: 'cheap', where: 'Bargain bin', why: r.why + ' · ' + src + ' ' + money(p) };
        }
        if (cheap && (r.verdict === 'never_sold' || r.verdict === 'never_had')) {
            return { v: r.verdict, where: where, why: r.why + ' · cheap on ' + src + ' (' + money(p) + ')' };
        }
        return { v: r.verdict, where: where, why: r.why };
    }
    function valueCell(r) {
        var bits = [];
        if (r.title_last_price != null) bits.push('Ours ' + money(r.title_last_price));
        else if (r.artist_avg_price != null) bits.push('<span class="sd-sub">artist avg ' + money(r.artist_avg_price) + '</span>');
        var d = r.dv;
        if (d === 'pending') bits.push('<span class="sd-sub">checking online…</span>');
        else if (d && d.found && d.source === 'ebay') {
            bits.push('<a href="' + esc(d.uri) + '" target="_blank" rel="noopener">eBay ~' + money(d.median) + '</a>'
                + '<div class="sd-sub">median of ' + d.for_sale + ' listed · low ' + money(d.lowest) + '</div>');
        } else if (d && d.found) {
            bits.push('<a href="' + esc(d.uri) + '" target="_blank" rel="noopener">Discogs ' + (d.median != null ? '~' + money(d.median) : (d.lowest != null ? 'from ' + money(d.lowest) : 'none for sale')) + '</a>'
                + '<div class="sd-sub">' + (d.median != null ? 'VG+ sales median · low ' + (d.lowest != null ? money(d.lowest) : '–') + ' · ' : '') + (d.for_sale || 0) + ' for sale · want ' + d.want + ' / have ' + d.have + '</div>');
        } else if (d && d.error) bits.push('<span class="sd-sub">' + esc(d.error).slice(0, 60) + '</span>');
        else if (d) bits.push('<span class="sd-sub">not found online</span>');
        return bits.join('<br>');
    }

    // {Vinyl: 120, CD: 21} → "Vinyl 120 · CD 21"
    function fmtList(o) { return Object.keys(o || {}).map(function (k) { return k + ' ' + Math.round(o[k] * 100) / 100; }).join(' · '); }
    // The format that clearly sells for this line: title sales first, else the artist's.
    function mainFormat(r) {
        var o = Object.keys(r.title_fmt || {}).length ? r.title_fmt : r.artist_fmt, keys = Object.keys(o || {});
        if (!keys.length) return '';
        var total = keys.reduce(function (a, k) { return a + o[k]; }, 0);
        return o[keys[0]] / total >= 0.6 ? keys[0] : '';
    }
    function formatCell(r) {
        var bits = [];
        if (Object.keys(r.title_fmt || {}).length) bits.push('<b>' + esc(fmtList(r.title_fmt)) + '</b><div class="sd-sub">this title sold</div>');
        else if (Object.keys(r.artist_fmt || {}).length) bits.push(esc(fmtList(r.artist_fmt)) + '<div class="sd-sub">artist sold</div>');
        if (Object.keys(r.title_have_fmt || {}).length) bits.push('<div class="sd-sub">on hand: ' + esc(fmtList(r.title_have_fmt)) + '</div>');
        return bits.join('');
    }

    // Never sold rows: "Pico 214 days on shelf · Hollywood none left (first in 2025-03-02)"
    function shelfLine(r) {
        var names = { pico: 'Pico', hollywood: 'Hollywood', other: otherStore };
        var parts = ['pico', 'hollywood', 'other'].filter(function (k) { return r.shelf && r.shelf[k]; }).map(function (k) {
            var x = r.shelf[k];
            return names[k] + ' ' + (x.shelf_days != null ? '<b>' + x.shelf_days + ' days</b> on shelf' : 'none left' + (x.first_in ? ' (first in ' + esc(x.first_in) + ')' : ''));
        });
        return parts.length ? '<div class="sd-sub">' + parts.join(' · ') + '</div>' : '';
    }

    // Rough price: what this title sold for here > what copies on our shelves are priced at >
    // the online price > the artist's average sale (music). Returns [price, where it came from].
    function baseEstimate(r) {
        if (r.sold_median != null) return [r.sold_median, 'our sales (' + r.sold_median_n + ')'];
        if (r.shelf_price != null) return [r.shelf_price, 'our shelf price'];
        if (r.dv && r.dv.price != null) return [r.dv.price, r.dv.source === 'ebay' ? 'eBay' : 'Discogs'];
        if (r.artist_avg_price != null) return [r.artist_avg_price, 'artist average'];
        return null;
    }
    // Picking a condition scales the estimate (treated as a VG+ copy): by this
    // release's own Discogs price per grade when we have it, else a typical curve.
    var COND_X = { 'Sealed': 1.6, 'M': 1.5, 'NM': 1.3, 'VG+': 1, 'VG': 0.6, 'G+': 0.4, 'G': 0.3, 'F/P': 0.15 };
    function condX(r) {
        if (!r.cond) return 1;
        var cr = r.dv && r.dv.cond_ratio, k = r.cond === 'Sealed' ? 'M' : r.cond;
        if (cr && cr[k] > 0) return r.cond === 'Sealed' ? Math.max(cr[k], COND_X.Sealed) : cr[k];
        return COND_X[r.cond] || 1;
    }
    function estimate(r) {
        var e = baseEstimate(r);
        if (!e || !r.cond) return e;
        return [e[0] * condX(r), e[1] + ', ' + r.cond];
    }
    function priceCell(r) {
        var e = estimate(r);
        if (!e) return r.dv === 'pending' ? '<span class="sd-sub">…</span>' : '<span class="sd-zero">?</span>';
        return '<span title="Estimate only: check condition and look it up before pricing"><b>~$' + Math.max(1, Math.round(e[0])) + '</b><div class="sd-sub">' + esc(e[1]) + ' · estimate</div></span>';
    }

    // Discogs release ID: only for vinyl, CD and cassette. Pre-filled from the
    // Discogs match, editable when the match is the wrong pressing.
    function catFormat() {
        if (!runCat) return '';
        var o = catSel.querySelector('option[value="' + runCat + '"]'), n = o ? o.textContent.toLowerCase() : '';
        if (/vinyl|45 rpm|\brecord/.test(n)) return 'Vinyl';
        if (/\bcds?\b/.test(n)) return 'CD';
        if (/cassette/.test(n)) return 'Cassette';
        return 'other';
    }
    var RID_FORMATS = { Vinyl: 1, CD: 1, Cassette: 1 };
    function hasRid(r) {
        var f = catFormat() || mainFormat(r);
        return !f || !!RID_FORMATS[f];
    }
    function rid(r) { return r.rid != null ? r.rid : (r.dv && r.dv.release_id ? String(r.dv.release_id) : ''); }
    function ridCell(r, i) {
        if (!hasRid(r)) return '<span class="sd-zero">–</span>';
        if (r.dv === 'pending' && r.rid == null) return '<span class="sd-sub">…</span>';
        var v = rid(r);
        return '<input class="sd-rid" data-i="' + i + '" value="' + esc(v) + '" inputmode="numeric" placeholder="ID">'
            + (v ? '<div class="sd-sub"><a href="https://www.discogs.com/release/' + encodeURIComponent(v) + '" target="_blank" rel="noopener">open</a></div>' : '');
    }

    // Condition is picked by staff per row; it goes into the pricing prompt.
    var CONDITIONS = ['Sealed', 'M', 'NM', 'VG+', 'VG', 'G+', 'G', 'F/P'];
    function condCell(r, i) {
        return '<select class="sd-cond" data-i="' + i + '"><option value="">–</option>'
            + CONDITIONS.map(function (c) { return '<option' + (r.cond === c ? ' selected' : '') + '>' + c + '</option>'; }).join('') + '</select>';
    }

    // ---- Sorting: click a header to sort, again to reverse. null = the order pasted.
    var sortKey = null, sortDir = 1;
    // Verdict sorts best-to-worst: store, online, bargain bin, never sold, never had.
    var VERDICT_RANK = { store: 0, discogs: 1, ebay: 1, cheap: 2, never_sold: 3, never_had: 4, unknown: 5 };
    var SORTS = {
        name: function (r) { return ((r.artist || '') + ' ' + (r.title || '')).toLowerCase(); },
        verdict: function (r) { var f = fin(r); return [VERDICT_RANK[f.v] != null ? VERDICT_RANK[f.v] : 9, f.where]; },
        price: function (r) { var e = estimate(r); return e ? e[0] : -1; },
        sold: function (r) { return sum(r.title_sold); },
        pico: function (r) { return r.for_sale.pico; },
        hollywood: function (r) { return r.for_sale.hollywood; },
        other: function (r) { return r.for_sale.other; },
        artist_sold: function (r) { return sum(r.artist_sold); },
        artist_12m: function (r) { return r.artist_sold_12m; },
        artist_have: function (r) { return sum(r.artist_have); },
        sold_12m: function (r) { return r.title_sold_12m; },
        listed: function (r) { return r.title_listed; },
        for_sale: function (r) { return sum(r.for_sale); }
    };
    function cmp(a, b) {
        if (Array.isArray(a)) { for (var i = 0; i < a.length; i++) { var c = cmp(a[i], b[i]); if (c) return c; } return 0; }
        return a < b ? -1 : (a > b ? 1 : 0);
    }
    function sorted(list) {
        if (!sortKey) return list;
        var key = SORTS[sortKey];
        return list.map(function (r, i) { return [key(r), i, r]; })
            .sort(function (a, b) { return cmp(a[0], b[0]) * sortDir || a[1] - b[1]; })
            .map(function (x) { return x[2]; });
    }
    function th(label, key, cls) {
        var arrow = sortKey === key ? '<span class="sd-arrow">' + (sortDir > 0 ? '▲' : '▼') + '</span>' : '';
        return '<th' + (cls ? ' class="' + cls + '"' : '') + ' data-sort="' + key + '" title="Sort">' + label + arrow + '</th>';
    }

    function render() {
        var counts = { all: rows.length };
        rows.forEach(function (r) { var v = fin(r).v; counts[v] = (counts[v] || 0) + 1; });
        summary.innerHTML = ['all', 'store', 'discogs', 'ebay', 'never_sold', 'never_had', 'cheap'].filter(function (k) { return k === 'all' || counts[k]; }).map(function (k) {
            return '<span class="sd-chip' + (filter === k ? ' on' : '') + '" data-f="' + k + '">' + (k === 'all' ? 'All' : LABEL[k]) + ' ' + (counts[k] || 0) + '</span>';
        }).join('');
        var shown = sorted(rows.filter(function (r) { return filter === 'all' || fin(r).v === filter; }));
        if (!rows.length) { out.innerHTML = ''; [copyBtn, promptBtn, sendBtn, pickAll, pickNone].forEach(function (b) { b.style.display = 'none'; }); return; }
        [copyBtn, promptBtn, sendBtn, pickAll, pickNone].forEach(function (b) { b.style.display = ''; });
        updateSendLabel();
        var music = mode === 'music';
        var allOn = shown.length && shown.every(function (r) { return r.picked; });
        out.innerHTML = '<table class="sd-table"><thead><tr><th><input type="checkbox" id="sdAll" title="Check all shown"' + (allOn ? ' checked' : '') + '></th>' + th(music ? 'Album' : 'Item', 'name') + th('Verdict', 'verdict') + '<th>Condition</th>' + th('Price', 'price', 'sd-num') + (music ? '<th>Release ID</th>' : '') + '<th>Why</th>'
            + (music ? '<th>Format</th>' : '')
            + th(music ? 'Title sold' : 'Sold', 'sold', 'sd-num') + '<th class="sd-num">Value</th>'
            + th('For sale', 'for_sale', 'sd-num')
            + (music ? th('Artist', 'artist_sold', 'sd-num') : '')
            + '<th></th></tr></thead><tbody>'
            + shown.map(function (r) {
                var q = encodeURIComponent((r.artist + ' ' + r.title).trim()), f = fin(r);
                var name = r.artist ? '<b>' + esc(r.artist) + '</b>' + (r.title ? '<div>' + esc(r.title) + '</div>' : '') : '<b>' + esc(r.title) + '</b>';
                return '<tr><td class="sd-first"><input type="checkbox" class="sd-pick" data-i="' + rows.indexOf(r) + '"' + (r.picked ? ' checked' : '') + '>'
                    + '<button type="button" class="sd-rm" data-i="' + rows.indexOf(r) + '" title="Remove this row">×</button></td><td class="sd-name">' + name + '</td>'
                    + '<td class="sd-vcol"><span class="sd-v ' + f.v + '">' + esc(f.where) + '</span>'
                    + (r.new_title_artist_sells ? '<div style="margin-top:4px"><span class="sd-v never_had">Never had this title · artist sold ' + num(sum(r.artist_sold)) + (r.artist_sold_12m ? ' (' + num(r.artist_sold_12m) + ' in 12 mo)' : '') + '</span></div>' : '')
                    + (r.never_had_at && r.never_had_at.length ? (r.never_had_at.map(function (st) { return '<div style="margin-top:4px"><span class="sd-v never_had">Never had for sale at ' + esc(st) + '</span></div>'; }).join('')) : '')
                    + (music && mainFormat(r) ? '<div class="sd-sub">mostly ' + esc(mainFormat(r)) + '</div>' : '') + '</td>'
                    + '<td>' + condCell(r, rows.indexOf(r)) + '</td>'
                    + '<td class="sd-num">' + priceCell(r) + '</td>'
                    + (music ? '<td>' + ridCell(r, rows.indexOf(r)) + '</td>' : '')
                    + '<td class="sd-why">' + esc(f.why) + shelfLine(r) + (r.artist_last_at ? '<div class="sd-sub">artist last sold ' + esc(r.artist_last_at) + '</div>' : '')
                    + (!music && r.title_last_at ? '<div class="sd-sub">last sold ' + esc(r.title_last_at) + '</div>' : '') + '</td>'
                    + (music ? '<td>' + formatCell(r) + '</td>' : '')
                    + '<td class="sd-num">' + num(sum(r.title_sold)) + split(r.title_sold)
                        + (!music ? '<div class="sd-sub">' + (r.title_sold_12m || 0) + ' in 12 mo · ' + (r.title_listed || 0) + ' listings</div>' : '') + '</td>'
                    + '<td class="sd-num">' + valueCell(r) + '</td>'
                    + '<td class="sd-stack"><span>Pico</span> ' + num(r.for_sale.pico) + '<br><span>HW</span> ' + num(r.for_sale.hollywood) + '<br><span>' + esc(otherStore === 'Online warehouse' ? 'Online' : otherStore) + '</span> ' + num(r.for_sale.other) + '</td>'
                    + (music
                        ? '<td class="sd-stack"><span>sold</span> ' + num(sum(r.artist_sold)) + '<br><span>12 mo</span> ' + num(r.artist_sold_12m) + '<br><span>on hand</span> ' + num(sum(r.artist_have)) + '</td>'
                        : '')
                    + '<td style="white-space:nowrap">' + (music
                        ? '<a href="https://www.discogs.com/search/?type=release&q=' + q + '" target="_blank" rel="noopener">Discogs</a>'
                        : '<a href="https://www.ebay.com/sch/i.html?_nkw=' + q + '" target="_blank" rel="noopener">eBay</a>')
                    + ' · <a href="/stock-lookup?q=' + q + '" target="_blank">Ours</a></td></tr>';
            }).join('') + '</tbody></table>';
    }

    go.addEventListener('click', function () {
        var lines = input.value.split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean);
        if (!lines.length) { status.textContent = 'Paste some items first.'; return; }
        rows = []; filter = 'all'; runCat = catSel.value; render();
        var myRun = ++runId;
        go.disabled = true;
        var batches = [];
        for (var i = 0; i < lines.length; i += CHUNK) batches.push(lines.slice(i, i + CHUNK));
        var done = 0, t0 = Date.now();
        (function next() {
            if (myRun !== runId) return;
            if (!batches.length) {
                go.disabled = false;
                status.textContent = 'Checked ' + rows.length + ' in ' + Math.round((Date.now() - t0) / 1000) + 's.';
                checkValues(myRun);
                return;
            }
            var b = batches.shift();
            status.textContent = 'Checking ' + (done + 1) + '–' + (done + b.length) + ' of ' + lines.length + '…';
            post('/store-or-discogs/check', { lines: b, category_id: runCat }).then(function (j) {
                if (myRun !== runId) return;
                mode = j.mode || 'music';
                // The Discogs Warehouse holds what we sell online (Discogs and eBay); the ERP
                // location keeps its name because imports and syncs look it up by name.
                if (j.other_store) otherStore = /warehouse/i.test(j.other_store) ? 'Online warehouse' : j.other_store;
                rows = rows.concat(j.rows); done += b.length; render(); next();
            }).catch(function (e) {
                go.disabled = false;
                status.textContent = 'Stopped at line ' + (done + 1) + ' (' + e.message + '). Press Check to retry.';
            });
        })();
    });

    // Discogs allows ~60 calls a minute and each line is 2, so one at a time;
    // on a rate limit wait out the minute. Answers are cached server-side.
    function checkValues(myRun) {
        var queue = rows.filter(function (r) { return ONLINE[r.verdict] && !r.dv; });
        queue.forEach(function (r) { r.dv = 'pending'; });
        render();
        var total = queue.length, n = 0;
        (function next() {
            if (myRun !== runId) return;
            while (queue.length && queue[0].removed) { queue.shift(); n++; }
            if (!queue.length) { if (total) status.textContent += ' Online prices done.'; return; }
            var r = queue[0];
            status.textContent = 'Online prices ' + (n + 1) + ' of ' + total + '…';
            post('/store-or-discogs/value', { artist: r.artist, title: r.title, category_id: runCat }).then(function (j) {
                queue.shift(); n++; r.dv = j; render();
                setTimeout(next, j.cached ? 0 : 1100);
            }).catch(function (e) {
                if (String(e.message).indexOf('429') !== -1) {
                    status.textContent = 'Discogs says slow down; waiting a minute (' + n + ' of ' + total + ' done)…';
                    setTimeout(next, 61000);
                } else { queue.shift(); n++; r.dv = { found: false, error: e.message }; render(); setTimeout(next, 1100); }
            });
        })();
    }
    minIn.addEventListener('input', render);

    document.getElementById('sdBuys').addEventListener('click', function () {
        status.textContent = 'Loading unpriced buys…';
        fetch('/store-or-discogs/unpriced-buys?category_id=' + encodeURIComponent(catSel.value), { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
            input.value = j.lines.join('\n');
            status.textContent = j.lines.length + ' unpriced buys loaded. Press Check.';
        });
    });

    // ---- Send checked rows to Mass Add (its Bulk Product Entry box, pipe format:
    // Name | Artist | Category | Subcategory | SKU | Price | Bin | Location).
    // Location = the verdict's store, or the Discogs Warehouse for Discogs / eBay;
    // left blank for Never sold / Never had / Bargain bin so staff choose.
    var FORMAT_CATEGORY = { Vinyl: 'Vinyl - Used', CD: 'CD - Used', Cassette: 'Cassettes - Used' };
    function massAddLine(r) {
        var f = fin(r), e = estimate(r), clean = function (t) { return String(t || '').replace(/\|/g, '/').trim(); };
        var cat = '';
        if (runCat) { var o = catSel.querySelector('option[value="' + runCat + '"]'); cat = o ? o.textContent.trim() : ''; }
        else if (mainFormat(r)) cat = FORMAT_CATEGORY[mainFormat(r)] || '';
        var loc = '';
        if (f.v === 'store') loc = /pico/i.test(f.where) && !/hollywood/i.test(f.where) ? 'Pico' : (/hollywood/i.test(f.where) && !/pico/i.test(f.where) ? 'Hollywood' : '');
        else if (f.v === 'discogs' || f.v === 'ebay') loc = 'Discogs Warehouse';
        return [clean(r.title || r.artist), clean(r.title ? r.artist : ''), cat, '', '', e ? String(Math.round(e[0])) : '', '', loc].join(' | ');
    }
    function updateSendLabel() {
        var n = rows.filter(function (r) { return r.picked; }).length;
        // "Select all" follows the chip filter: on "Discogs" it selects just the Discogs rows.
        pickAll.textContent = filter === 'all' ? 'Select all' : 'Select all ' + (LABEL[filter] || '');
        pickNone.disabled = !n;
        sendBtn.textContent = n ? 'Send ' + n + ' checked to Mass Add' : 'Send checked to Mass Add';
        sendBtn.disabled = !n;
    }
    // Header click: first click sorts (numbers biggest first, names/verdict A-Z / best first), again reverses, third goes back to pasted order.
    out.addEventListener('click', function (e) {
        var rm = e.target.closest('.sd-rm');
        if (rm) {
            var gone = rows.splice(+rm.getAttribute('data-i'), 1)[0];
            if (gone) gone.removed = true;
            status.textContent = 'Removed ' + ((gone && (gone.artist ? gone.artist + (gone.title ? ' - ' + gone.title : '') : gone.title)) || 'row') + '.';
            render();
            return;
        }
        var h = e.target.closest('th[data-sort]');
        if (!h) return;
        var k = h.getAttribute('data-sort'), first = (k === 'name' || k === 'verdict') ? 1 : -1;
        if (sortKey !== k) { sortKey = k; sortDir = first; }
        else if (sortDir === first) sortDir = -first;
        else sortKey = null;
        render();
    });
    out.addEventListener('change', function (e) {
        if (e.target.id === 'sdAll') {
            rows.forEach(function (r) { if (filter === 'all' || fin(r).v === filter) r.picked = e.target.checked; });
            render();
        } else if (e.target.classList.contains('sd-cond')) {
            rows[+e.target.getAttribute('data-i')].cond = e.target.value;
            render();
        } else if (e.target.classList.contains('sd-rid')) {
            rows[+e.target.getAttribute('data-i')].rid = e.target.value.replace(/^.*\/release\/(\d+).*$/i, '$1').replace(/[^0-9]/g, '');
            render();
        } else if (e.target.classList.contains('sd-pick')) {
            rows[+e.target.getAttribute('data-i')].picked = e.target.checked;
            updateSendLabel();
        }
    });
    pickAll.addEventListener('click', function () {
        rows.forEach(function (r) { if (filter === 'all' || fin(r).v === filter) r.picked = true; });
        render();
    });
    pickNone.addEventListener('click', function () {
        rows.forEach(function (r) { r.picked = false; });
        render();
    });
    sendBtn.addEventListener('click', function () {
        var picked = rows.filter(function (r) { return r.picked; });
        if (!picked.length) return;
        try { localStorage.setItem('massAddFromStoreOrOnline', picked.map(massAddLine).join('\n')); }
        catch (e) { status.textContent = 'Could not hand off to Mass Add (browser storage blocked).'; return; }
        window.open('/product/mass-create?from=store-or-online', '_blank');
        status.textContent = picked.length + ' sent to Mass Add (new tab). Prices are only suggestions: check condition, look each one up and set your own price before pressing Parse & Add.';
    });

    summary.addEventListener('click', function (e) {
        var c = e.target.closest('.sd-chip'); if (!c) return;
        filter = c.getAttribute('data-f'); render();
    });

    // Pricing prompt for an AI: checked rows (or everything shown), one per line,
    // each followed by its condition and our suggested price.
    promptBtn.addEventListener('click', function () {
        var list = rows.filter(function (r) { return r.picked; });
        if (!list.length) list = sorted(rows.filter(function (r) { return filter === 'all' || fin(r).v === filter; }));
        var lines = list.map(function (r) {
            var e = estimate(r), name = r.artist ? r.artist + (r.title ? ' - ' + r.title : '') : r.title;
            return name + ' | Condition: ' + (r.cond || 'not given') + ' | Suggested price: ' + (e ? '$' + Math.round(e[0]) : 'none');
        });
        var text = 'Price these items for a Hollywood / Pico Blvd retail record store, using fair current market value (Discogs / eBay) for the condition. '
            + 'Fill in the best category and subcategory for each, and leave SKU blank. Return one item per line in this exact format, nothing else:\n'
            + 'Product Name,Artist,Category,Subcategory,SKU,Price,\n\n' + lines.join('\n');
        navigator.clipboard.writeText(text).then(function () { status.textContent = 'Pricing prompt for ' + list.length + ' copied — paste it into an AI.'; });
    });

    copyBtn.addEventListener('click', function () {
        var head = ['Artist', 'Title', 'Verdict', 'Suggested price (estimate)', 'Price from', 'Condition', 'Discogs release ID', 'Format', 'Why', 'Sold', 'Our last price', 'Online price', 'Pico for sale', 'Hollywood for sale', otherStore + ' for sale', 'Pico days on shelf', 'Hollywood days on shelf', 'Artist sold', 'Artist 12 mo', 'Artist on hand', 'On hand', 'Listings'];
        var tsv = [head.join('\t')].concat(rows.filter(function (r) { return filter === 'all' || fin(r).v === filter; }).map(function (r) {
            var f = fin(r), e = estimate(r);
            return [r.artist, r.title, f.where + (r.never_had_at && r.never_had_at.length ? '; never had for sale at ' + r.never_had_at.join(' and ') : ''), e ? Math.round(e[0]) : '', e ? e[1] : '', r.cond || '', mode === 'music' && hasRid(r) ? rid(r) : '', fmtList(Object.keys(r.title_fmt || {}).length ? r.title_fmt : r.artist_fmt), f.why, sum(r.title_sold), r.title_last_price == null ? '' : r.title_last_price,
                r.dv && r.dv.price != null ? r.dv.price : '', r.for_sale.pico, r.for_sale.hollywood, r.for_sale.other,
                r.shelf && r.shelf.pico && r.shelf.pico.shelf_days != null ? r.shelf.pico.shelf_days : '', r.shelf && r.shelf.hollywood && r.shelf.hollywood.shelf_days != null ? r.shelf.hollywood.shelf_days : '',
                sum(r.artist_sold), r.artist_sold_12m, sum(r.artist_have), r.title_have, mode === 'music' ? r.artist_listed : r.title_listed].join('\t');
        })).join('\n');
        navigator.clipboard.writeText(tsv).then(function () { status.textContent = 'Copied — paste into a spreadsheet.'; });
    });

    // Remember the last type picked on this computer.
    try { var saved = localStorage.getItem('sdCat'); if (saved !== null && catSel.querySelector('option[value="' + saved + '"]')) catSel.value = saved; } catch (e) {}
    catSel.addEventListener('change', function () { try { localStorage.setItem('sdCat', catSel.value); } catch (e) {} });
})();
</script>
@endsection
