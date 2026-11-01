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
.sd-scroll { overflow-x: auto; }
</style>
<div class="sd-wrap">
    <h1>Store or Discogs?</h1>
    <p class="sd-help">Paste albums, one per line as <b>Artist - Title</b> (or two columns copied from a spreadsheet). Checks every line against everything we've ever sold, including the old sales sheets.</p>
    <textarea class="sd-input" id="sdIn" placeholder="Wu-Tang Clan - Forever&#10;Fleetwood Mac - Rumours&#10;Beatles, The - Abbey Road"></textarea>
    <div class="sd-bar">
        <button class="sd-btn primary" id="sdGo">Check</button>
        <button class="sd-btn" id="sdBuys" title="Fill the box with the unpriced records from Price New Buys">Load unpriced buys</button>
        <button class="sd-btn" id="sdCopy" style="display:none">Copy results</button>
        <span class="sd-status" id="sdStatus"></span>
    </div>
    <div class="sd-summary" id="sdSummary"></div>
    <div class="sd-scroll" id="sdOut"></div>
    <p class="sd-help" style="margin-top:14px">
        <b>How it decides:</b> sold this title before → the store that sold it most.
        Sold the artist (not this title) → the store that sold the artist most, unless we already have more than {{ $overstockRatio }}× what the artist sold in the last 12 months on the shelves → Discogs.
        Never sold the artist and already have {{ $neverSoldLots }}+ → Discogs. Never sold the artist and have few → lean Discogs.
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
    var LABEL = { store: 'Store', discogs: 'Discogs', lean_discogs: 'Lean Discogs', unknown: '?' };

    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function sum(o) { return (o.pico || 0) + (o.hollywood || 0) + (o.other || 0); }
    function num(n) { return n > 0 ? String(Math.round(n * 100) / 100) : '<span class="sd-zero">0</span>'; }
    function split(o) { return sum(o) > 0 ? '<div class="sd-sub">P ' + (o.pico || 0) + ' · HW ' + (o.hollywood || 0) + '</div>' : ''; }
    function post(url, body) {
        return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' }, body: JSON.stringify(body) })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
    }

    function render() {
        var counts = { all: rows.length, store: 0, discogs: 0, lean_discogs: 0 };
        rows.forEach(function (r) { if (counts[r.verdict] != null) counts[r.verdict]++; });
        summary.innerHTML = [['all', 'All'], ['store', 'Store'], ['discogs', 'Discogs'], ['lean_discogs', 'Lean Discogs']].map(function (c) {
            return '<span class="sd-chip' + (filter === c[0] ? ' on' : '') + '" data-f="' + c[0] + '">' + c[1] + ' ' + counts[c[0]] + '</span>';
        }).join('');
        var shown = rows.filter(function (r) { return filter === 'all' || r.verdict === filter; });
        if (!rows.length) { out.innerHTML = ''; copyBtn.style.display = 'none'; return; }
        copyBtn.style.display = '';
        out.innerHTML = '<table class="sd-table"><thead><tr><th>Album</th><th>Verdict</th><th>Why</th>'
            + '<th class="sd-num">Title sold</th><th class="sd-num">Last price</th><th class="sd-num">Artist sold</th><th class="sd-num">Artist 12 mo</th>'
            + '<th class="sd-num">Artist on hand</th><th class="sd-num">This title on hand</th><th></th></tr></thead><tbody>'
            + shown.map(function (r) {
                var q = encodeURIComponent((r.artist + ' ' + r.title).trim());
                return '<tr><td><b>' + esc(r.artist) + '</b>' + (r.title ? '<div>' + esc(r.title) + '</div>' : '') + '</td>'
                    + '<td><span class="sd-v ' + r.verdict + '">' + esc(r.where || LABEL[r.verdict]) + '</span>'
                    + (r.verdict === 'lean_discogs' ? '<div class="sd-sub">lean</div>' : '') + '</td>'
                    + '<td>' + esc(r.why) + (r.artist_last_at ? '<div class="sd-sub">artist last sold ' + esc(r.artist_last_at) + '</div>' : '') + '</td>'
                    + '<td class="sd-num">' + num(sum(r.title_sold)) + split(r.title_sold) + '</td>'
                    + '<td class="sd-num">' + (r.title_last_price != null ? '$' + r.title_last_price.toFixed(2) + '<div class="sd-sub">' + esc(r.title_last_at) + '</div>' : '') + '</td>'
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
        go.disabled = true;
        var batches = [];
        for (var i = 0; i < lines.length; i += CHUNK) batches.push(lines.slice(i, i + CHUNK));
        var done = 0, t0 = Date.now();
        (function next() {
            if (!batches.length) {
                go.disabled = false;
                status.textContent = 'Checked ' + rows.length + ' in ' + Math.round((Date.now() - t0) / 1000) + 's.';
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
        var head = ['Artist', 'Title', 'Verdict', 'Why', 'Title sold', 'Last price', 'Artist sold', 'Artist sold Pico', 'Artist sold HW', 'Artist 12 mo', 'Artist on hand', 'Title on hand'];
        var tsv = [head.join('\t')].concat(rows.filter(function (r) { return filter === 'all' || r.verdict === filter; }).map(function (r) {
            return [r.artist, r.title, r.where || LABEL[r.verdict], r.why, sum(r.title_sold), r.title_last_price == null ? '' : r.title_last_price,
                sum(r.artist_sold), r.artist_sold.pico, r.artist_sold.hollywood, r.artist_sold_12m, sum(r.artist_have), r.title_have].join('\t');
        })).join('\n');
        navigator.clipboard.writeText(tsv).then(function () { status.textContent = 'Copied — paste into a spreadsheet.'; });
    });
})();
</script>
@endsection
