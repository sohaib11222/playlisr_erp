@extends('layouts.app')
@section('title', 'Do We Have It?')

@section('content')
<style>
.sl-wrap { max-width: 820px; margin: 0 auto; padding: 24px 16px 60px; color: #1F1B16; }
.sl-wrap h1 { font-size: 26px; font-weight: 700; margin: 0 0 12px; }
.sl-search { width: 100%; height: 48px; font-size: 18px; border: 1px solid #E1D7C4; border-radius: 12px; padding: 0 16px; }
.sl-table { width: 100%; border-collapse: collapse; margin-top: 18px; font-size: 17px; background: #fff; border: 1px solid #E6DCCF; border-radius: 12px; overflow: hidden; }
.sl-table th { text-align: left; background: #FAF6EE; padding: 10px 14px; font-size: 13px; text-transform: uppercase; letter-spacing: .03em; color: #6B6155; }
.sl-table td { padding: 12px 14px; border-top: 1px solid #F3ECE1; vertical-align: top; }
.sl-num { font-weight: 700; font-size: 20px; }
.sl-zero { color: #B9AFA2; font-weight: 600; }
.sl-more { font-size: 12px; color: #8E8273; cursor: pointer; }
.sl-sub { display: none; font-size: 13px; color: #6B6155; margin-top: 6px; }
.sl-note { color: #6B6155; font-size: 13px; margin-top: 10px; }
</style>
<div class="sl-wrap">
    <h1>Do we have it?</h1>
    <input class="sl-search" id="slQ" type="search" placeholder="Artist and album, e.g. wu-tang forever" autofocus>
    <div id="slOut"></div>
</div>
@endsection

@section('javascript')
<script>
(function () {
    var q = document.getElementById('slQ'), out = document.getElementById('slOut'), timer = null;
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function num(n) { return n > 0 ? '<span class="sl-num">' + n + '</span>' : '<span class="sl-zero">0</span>'; }
    function run() {
        var v = q.value.trim();
        try { history.replaceState(null, '', v ? ('?q=' + encodeURIComponent(v)) : location.pathname); } catch (e) {}
        if (v.length < 3) { out.innerHTML = ''; return; }
        out.innerHTML = '<div class="sl-note">Looking...</div>';
        fetch('/stock-lookup/data?q=' + encodeURIComponent(v), { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
            if (!j.groups.length) { out.innerHTML = '<div class="sl-note">Nothing found.</div>'; return; }
            out.innerHTML = '<table class="sl-table"><thead><tr><th>Format</th><th>Hollywood</th><th>Pico</th><th>Price</th></tr></thead><tbody>'
                + j.groups.map(function (g, i) {
                    var price = g.prices.length ? '$' + g.prices.join(' / $') : '';
                    var sub = g.listings.map(function (l) { return '<a href="/products/' + l.id + '/edit" target="_blank">' + esc(l.name) + '</a> HW ' + l.hw + ' · Pico ' + l.pico + ' · $' + l.price; }).join('<br>');
                    return '<tr><td>' + esc(g.format) + '<div class="sl-more" data-i="' + i + '">' + g.listings.length + ' listing' + (g.listings.length > 1 ? 's' : '') + '</div><div class="sl-sub" id="slSub' + i + '">' + sub + '</div></td><td>' + num(g.hw) + '</td><td>' + num(g.pico) + '</td><td>' + esc(price) + '</td></tr>';
                }).join('') + '</tbody></table><div class="sl-note">Counts are what the ERP shows. Duplicate listings of the same format are added together.</div>';
        });
    }
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 300); });
    out.addEventListener('click', function (e) { var m = e.target.closest('.sl-more'); if (!m) return; var s = document.getElementById('slSub' + m.getAttribute('data-i')); s.style.display = s.style.display === 'block' ? 'none' : 'block'; });
    // Prefill from ?q= after the page finishes loading (a global script
    // clears inputs on load).
    var start = new URLSearchParams(location.search).get('q');
    if (start) { setTimeout(function () { q.value = start; run(); }, 700); }
})();
</script>
@endsection
