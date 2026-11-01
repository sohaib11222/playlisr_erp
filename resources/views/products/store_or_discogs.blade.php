@extends('layouts.app')
@section('title', 'Store or Discogs?')

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
.sd-table th { text-align: left; background: #FAF6EE; padding: 9px 10px; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; color: #6B6155; white-space: nowrap; }
.sd-table td { padding: 9px 10px; border-top: 1px solid #F3ECE1; vertical-align: top; }
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
</style>
<div class="sd-wrap">
    <h1>Store or Discogs?</h1>
    <p class="sd-help">Paste one item per line. For music: <b>Artist - Title</b> (or two spreadsheet columns, or just "artist title"). For anything else, the item's name. Checks every line against everything we've ever sold.</p>
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
        <b>Never sold</b> = we've had it but none sold. <b>Never had for sale</b> = we've never carried it. Neither is a call; the online price is shown so you can decide.
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
    var rows = [], filter = 'all', mode = 'music', runCat = '', runId = 0;
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
            bits.push('<a href="' + esc(d.uri) + '" target="_blank" rel="noopener">Discogs ' + (d.lowest != null ? 'from ' + money(d.lowest) : 'none for sale') + '</a>'
                + '<div class="sd-sub">' + (d.for_sale || 0) + ' for sale · want ' + d.want + ' / have ' + d.have + '</div>');
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

    function render() {
        var counts = { all: rows.length };
        rows.forEach(function (r) { var v = fin(r).v; counts[v] = (counts[v] || 0) + 1; });
        summary.innerHTML = ['all', 'store', 'discogs', 'ebay', 'never_sold', 'never_had', 'cheap'].filter(function (k) { return k === 'all' || counts[k]; }).map(function (k) {
            return '<span class="sd-chip' + (filter === k ? ' on' : '') + '" data-f="' + k + '">' + (k === 'all' ? 'All' : LABEL[k]) + ' ' + (counts[k] || 0) + '</span>';
        }).join('');
        var shown = rows.filter(function (r) { return filter === 'all' || fin(r).v === filter; });
        if (!rows.length) { out.innerHTML = ''; copyBtn.style.display = 'none'; return; }
        copyBtn.style.display = '';
        var music = mode === 'music';
        out.innerHTML = '<table class="sd-table"><thead><tr><th>' + (music ? 'Album' : 'Item') + '</th><th>Verdict</th><th>Why</th>'
            + (music ? '<th>Format</th>' : '')
            + '<th class="sd-num">' + (music ? 'Title sold' : 'Sold') + '</th><th class="sd-num">Value</th>'
            + (music ? '<th class="sd-num">Artist sold</th><th class="sd-num">Artist 12 mo</th><th class="sd-num">Artist on hand</th><th class="sd-num">This title on hand</th>'
                     : '<th class="sd-num">Sold 12 mo</th><th class="sd-num">On hand</th><th class="sd-num">Listings</th>')
            + '<th></th></tr></thead><tbody>'
            + shown.map(function (r) {
                var q = encodeURIComponent((r.artist + ' ' + r.title).trim()), f = fin(r);
                var name = r.artist ? '<b>' + esc(r.artist) + '</b>' + (r.title ? '<div>' + esc(r.title) + '</div>' : '') : '<b>' + esc(r.title) + '</b>';
                return '<tr><td>' + name + '</td>'
                    + '<td><span class="sd-v ' + f.v + '">' + esc(f.where) + '</span>'
                    + (music && mainFormat(r) ? '<div class="sd-sub">mostly ' + esc(mainFormat(r)) + '</div>' : '') + '</td>'
                    + '<td>' + esc(f.why) + (r.artist_last_at ? '<div class="sd-sub">artist last sold ' + esc(r.artist_last_at) + '</div>' : '')
                    + (!music && r.title_last_at ? '<div class="sd-sub">last sold ' + esc(r.title_last_at) + '</div>' : '') + '</td>'
                    + (music ? '<td>' + formatCell(r) + '</td>' : '')
                    + '<td class="sd-num">' + num(sum(r.title_sold)) + split(r.title_sold) + '</td>'
                    + '<td class="sd-num">' + valueCell(r) + '</td>'
                    + (music
                        ? '<td class="sd-num">' + num(sum(r.artist_sold)) + split(r.artist_sold) + '</td>'
                          + '<td class="sd-num">' + num(r.artist_sold_12m) + '</td>'
                          + '<td class="sd-num">' + num(sum(r.artist_have)) + split(r.artist_have) + '</td>'
                          + '<td class="sd-num">' + num(r.title_have) + '</td>'
                        : '<td class="sd-num">' + num(r.title_sold_12m) + '</td>'
                          + '<td class="sd-num">' + num(r.title_have) + '</td>'
                          + '<td class="sd-num">' + num(r.title_listed) + '</td>')
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

    summary.addEventListener('click', function (e) {
        var c = e.target.closest('.sd-chip'); if (!c) return;
        filter = c.getAttribute('data-f'); render();
    });

    copyBtn.addEventListener('click', function () {
        var head = ['Artist', 'Title', 'Verdict', 'Format', 'Why', 'Sold', 'Our last price', 'Online price', 'Artist sold', 'Artist 12 mo', 'Artist on hand', 'On hand', 'Listings'];
        var tsv = [head.join('\t')].concat(rows.filter(function (r) { return filter === 'all' || fin(r).v === filter; }).map(function (r) {
            var f = fin(r);
            return [r.artist, r.title, f.where, fmtList(Object.keys(r.title_fmt || {}).length ? r.title_fmt : r.artist_fmt), f.why, sum(r.title_sold), r.title_last_price == null ? '' : r.title_last_price,
                r.dv && r.dv.price != null ? r.dv.price : '',
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
