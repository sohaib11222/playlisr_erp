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
.sd-v.discogs { background: #FBE3DC; color: #9A3A1F; }
.sd-v.lean_discogs { background: #FCEFD9; color: #8A5A12; }
.sd-v.unknown { background: #EEE; color: #666; }
.sd-v.cheap { background: #ECE7F6; color: #5A3F8C; }
.sd-min { width: 64px; height: 36px; border: 1px solid #E1D7C4; border-radius: 8px; padding: 0 8px; font-size: 15px; }
.sd-scroll { overflow-x: auto; }
</style>
<div class="sd-wrap">
    <h1>Store or Discogs?</h1>
    <p class="sd-help">Paste albums, one per line as <b>Artist - Title</b> (or two columns copied from a spreadsheet). Checks every line against everything we've ever sold, including the old sales sheets.</p>
    <textarea class="sd-input" id="sdIn" placeholder="Wu-Tang Clan - Forever&#10;Fleetwood Mac - Rumours&#10;Beatles, The - Abbey Road"></textarea>
    <div class="sd-bar">
        <button class="sd-btn primary" id="sdGo">Check</button>
        <button class="sd-btn" id="sdBuys" title="Fill the box with the unpriced records from Price New Buys">Load unpriced buys</button>
        <label class="sd-status" style="margin:0 6px">Too cheap for Discogs under $<input class="sd-min" id="sdMin" type="number" min="0" step="1" value="{{ (int) $minDiscogsValue }}"></label>
        <button class="sd-btn" id="sdCopy" style="display:none">Copy results</button>
        <span class="sd-status" id="sdStatus"></span>
    </div>
    <div class="sd-summary" id="sdSummary"></div>
    <div class="sd-scroll" id="sdOut"></div>
    <p class="sd-help" style="margin-top:14px">
        <b>How it decides:</b> sold this title in the last {{ $recentYears }} years → the store that sold it most.
        Sold the artist → the store that sold the artist most, unless: no artist sales in {{ $recentYears }} years, or we already have more than {{ $overstockRatio }}× what the artist sold in the last 12 months on the shelves, or under {{ $minSellThrough }}% of the artist we've stocked ever sold → Discogs.
        Never sold the artist → Discogs.
        Anything headed to Discogs then gets its Discogs price checked; if the cheapest copy for sale is under the amount above, it's <b>Bargain bin</b>: not worth listing, and it won't sell at full price in the store either.
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
    var rows = [], filter = 'all';
    var LABEL = { store: 'Store', discogs: 'Discogs', lean_discogs: 'Lean Discogs', cheap: 'Bargain bin', unknown: '?' };
    var minIn = document.getElementById('sdMin'), runId = 0;
    function minValue() { var v = parseFloat(minIn.value); return isNaN(v) ? 0 : v; }
    function needsValue(r) { return r.verdict === 'discogs' || r.verdict === 'lean_discogs'; }
    // Final call = server verdict, then the Discogs price gate on anything headed to Discogs.
    function fin(r) {
        if (needsValue(r) && r.dv && r.dv.lowest != null && r.dv.lowest < minValue()) {
            return { v: 'cheap', where: 'Bargain bin', why: r.why + ' · cheapest on Discogs $' + r.dv.lowest.toFixed(2) };
        }
        return { v: r.verdict, where: r.where || LABEL[r.verdict], why: r.why };
    }
    function valueCell(r) {
        var bits = [];
        if (r.title_last_price != null) bits.push('Ours $' + r.title_last_price.toFixed(2));
        else if (r.artist_avg_price != null) bits.push('<span class="sd-sub">artist avg $' + r.artist_avg_price.toFixed(2) + '</span>');
        if (r.dv === 'pending') bits.push('<span class="sd-sub">checking Discogs…</span>');
        else if (r.dv && r.dv.found) {
            bits.push('<a href="' + esc(r.dv.uri) + '" target="_blank" rel="noopener">Discogs ' + (r.dv.lowest != null ? 'from $' + r.dv.lowest.toFixed(2) : 'none for sale') + '</a>'
                + '<div class="sd-sub">' + (r.dv.for_sale || 0) + ' for sale · want ' + r.dv.want + ' / have ' + r.dv.have + '</div>');
        } else if (r.dv && r.dv.error) bits.push('<span class="sd-sub">Discogs: ' + esc(r.dv.error).slice(0, 60) + '</span>');
        else if (r.dv) bits.push('<span class="sd-sub">not found on Discogs</span>');
        return bits.join('<br>');
    }

    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function sum(o) { return (o.pico || 0) + (o.hollywood || 0) + (o.other || 0); }
    function num(n) { return n > 0 ? String(Math.round(n * 100) / 100) : '<span class="sd-zero">0</span>'; }
    function split(o) { return sum(o) > 0 ? '<div class="sd-sub">P ' + (o.pico || 0) + ' · HW ' + (o.hollywood || 0) + '</div>' : ''; }
    function post(url, body) {
        return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
    }

    function render() {
        var counts = { all: rows.length, store: 0, discogs: 0, lean_discogs: 0, cheap: 0 };
        rows.forEach(function (r) { var v = fin(r).v; if (counts[v] != null) counts[v]++; });
        summary.innerHTML = [['all', 'All'], ['store', 'Store'], ['discogs', 'Discogs'], ['lean_discogs', 'Lean Discogs'], ['cheap', 'Bargain bin']].map(function (c) {
            return '<span class="sd-chip' + (filter === c[0] ? ' on' : '') + '" data-f="' + c[0] + '">' + c[1] + ' ' + counts[c[0]] + '</span>';
        }).join('');
        var shown = rows.filter(function (r) { return filter === 'all' || fin(r).v === filter; });
        if (!rows.length) { out.innerHTML = ''; copyBtn.style.display = 'none'; return; }
        copyBtn.style.display = '';
        out.innerHTML = '<table class="sd-table"><thead><tr><th>Album</th><th>Verdict</th><th>Why</th>'
            + '<th class="sd-num">Title sold</th><th class="sd-num">Value</th><th class="sd-num">Artist sold</th><th class="sd-num">Artist 12 mo</th>'
            + '<th class="sd-num">Artist on hand</th><th class="sd-num">This title on hand</th><th></th></tr></thead><tbody>'
            + shown.map(function (r) {
                var q = encodeURIComponent((r.artist + ' ' + r.title).trim()), f = fin(r);
                return '<tr><td><b>' + esc(r.artist) + '</b>' + (r.title ? '<div>' + esc(r.title) + '</div>' : '') + '</td>'
                    + '<td><span class="sd-v ' + f.v + '">' + esc(f.where) + '</span>'
                    + (f.v === 'lean_discogs' ? '<div class="sd-sub">lean</div>' : '') + '</td>'
                    + '<td>' + esc(f.why) + (r.artist_last_at ? '<div class="sd-sub">artist last sold ' + esc(r.artist_last_at) + '</div>' : '') + '</td>'
                    + '<td class="sd-num">' + num(sum(r.title_sold)) + split(r.title_sold) + '</td>'
                    + '<td class="sd-num">' + valueCell(r) + '</td>'
                    + '<td class="sd-num">' + num(sum(r.artist_sold)) + split(r.artist_sold) + '</td>'
                    + '<td class="sd-num">' + num(r.artist_sold_12m) + '</td>'
                    + '<td class="sd-num">' + num(sum(r.artist_have)) + split(r.artist_have) + '</td>'
                    + '<td class="sd-num">' + num(r.title_have) + '</td>'
                    + '<td style="white-space:nowrap"><a href="https://www.discogs.com/search/?type=release&q=' + q + '" target="_blank" rel="noopener">Discogs</a> · '
                    + '<a href="/stock-lookup?q=' + q + '" target="_blank">Ours</a></td></tr>';
            }).join('') + '</tbody></table>';
    }

    go.addEventListener('click', function () {
        var lines = input.value.split(/\r?\n/).map(function (l) { return l.trim(); }).filter(Boolean);
        if (!lines.length) { status.textContent = 'Paste some albums first.'; return; }
        rows = []; filter = 'all'; render();
        var myRun = ++runId;
        go.disabled = true;
        var batches = [];
        for (var i = 0; i < lines.length; i += CHUNK) batches.push(lines.slice(i, i + CHUNK));
        var done = 0, t0 = Date.now();
        (function next() {
            if (!batches.length) {
                go.disabled = false;
                status.textContent = 'Checked ' + rows.length + ' in ' + Math.round((Date.now() - t0) / 1000) + 's.';
                checkValues(myRun);
                return;
            }
            var b = batches.shift();
            status.textContent = 'Checking ' + (done + 1) + '–' + (done + b.length) + ' of ' + lines.length + '…';
            post('/store-or-discogs/check', { lines: b }).then(function (j) {
                rows = rows.concat(j.rows); done += b.length; render(); next();
            }).catch(function (e) {
                go.disabled = false;
                status.textContent = 'Stopped at line ' + (done + 1) + ' (' + e.message + '). Press Check to retry.';
            });
        })();
    });

    // Discogs allows ~60 calls a minute and each album is 2, so one at a time;
    // on a rate limit wait out the minute. Answers are cached server-side.
    function checkValues(myRun) {
        var queue = rows.filter(function (r) { return needsValue(r) && !r.dv; });
        queue.forEach(function (r) { r.dv = 'pending'; });
        render();
        var total = queue.length, n = 0;
        (function next() {
            if (myRun !== runId) return;
            if (!queue.length) { if (total) status.textContent += ' Discogs prices done.'; return; }
            var r = queue[0];
            status.textContent = 'Discogs prices ' + (n + 1) + ' of ' + total + '…';
            post('/store-or-discogs/value', { artist: r.artist, title: r.title }).then(function (j) {
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
        fetch('/store-or-discogs/unpriced-buys', { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
            input.value = j.lines.join('\n');
            status.textContent = j.lines.length + ' unpriced buys loaded. Press Check.';
        });
    });

    summary.addEventListener('click', function (e) {
        var c = e.target.closest('.sd-chip'); if (!c) return;
        filter = c.getAttribute('data-f'); render();
    });

    copyBtn.addEventListener('click', function () {
        var head = ['Artist', 'Title', 'Verdict', 'Why', 'Title sold', 'Our last price', 'Discogs lowest', 'Artist sold', 'Artist sold Pico', 'Artist sold HW', 'Artist 12 mo', 'Artist on hand', 'Title on hand'];
        var tsv = [head.join('\t')].concat(rows.filter(function (r) { return filter === 'all' || fin(r).v === filter; }).map(function (r) {
            var f = fin(r);
            return [r.artist, r.title, f.where, f.why, sum(r.title_sold), r.title_last_price == null ? '' : r.title_last_price,
                r.dv && r.dv.lowest != null ? r.dv.lowest : '',
                sum(r.artist_sold), r.artist_sold.pico, r.artist_sold.hollywood, r.artist_sold_12m, sum(r.artist_have), r.title_have].join('\t');
        })).join('\n');
        navigator.clipboard.writeText(tsv).then(function () { status.textContent = 'Copied — paste into a spreadsheet.'; });
    });
})();
</script>
@endsection
