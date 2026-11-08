@extends('layouts.app')
@section('title', 'Genre Bible: Needs a decision')

@section('content')
<style>
.gb-wrap { max-width: 1100px; margin: 0 auto; padding: 24px 16px 60px; color: #1F1B16; }
.gb-wrap h1 { font-size: 26px; font-weight: 700; margin: 0 0 6px; }
.gb-tabs { display: flex; gap: 6px; margin: 0 0 14px; }
.gb-tabs a { padding: 8px 14px; border-radius: 999px; border: 1px solid #E1D7C4; color: #3B2E2A; font-weight: 600; text-decoration: none; }
.gb-tabs a.on { background: #3B2E2A; color: #fff; border-color: #3B2E2A; }
.gb-intro { color: #6B6155; font-size: 14px; margin: 0 0 12px; }
.gb-controls { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-bottom: 10px; }
.gb-search { flex: 1 1 240px; height: 42px; font-size: 16px; border: 1px solid #E1D7C4; border-radius: 10px; padding: 0 14px; }
.gb-check { font-size: 14px; font-weight: 600; display: inline-flex; gap: 6px; align-items: center; cursor: pointer; margin: 0; }
.gb-count { color: #6B6155; font-size: 13px; margin: 0 0 8px; }
.gb-table { width: 100%; border-collapse: collapse; font-size: 15px; background: #fff; border: 1px solid #E6DCCF; }
.gb-table th { text-align: left; background: #FAF6EE; padding: 9px 12px; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; color: #6B6155; position: sticky; top: 0; z-index: 1; }
.gb-table td { padding: 10px 12px; border-top: 1px solid #F3ECE1; vertical-align: top; }
.gb-name { font-weight: 700; }
.gb-why { font-size: 13px; color: #6B6155; margin-top: 3px; }
.gb-tag { display: inline-block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; padding: 2px 7px; border-radius: 999px; margin-right: 4px; }
.gb-tag-sheet { background: #FFE9C2; color: #8A5300; }
.gb-tag-split { background: #E3ECFF; color: #1E4BB8; }
.gb-tag-new { background: #EEE; color: #555; }
.gb-choice { display: inline-block; margin: 0 6px 6px 0; height: 32px; padding: 0 11px; font-size: 13px; font-weight: 700; border-radius: 7px; border: 1px solid #3B2E2A; background: #fff; color: #3B2E2A; }
.gb-choice.cur { background: #3B2E2A; color: #fff; }
.gb-choice:disabled { opacity: .5; }
.gb-other { height: 32px; font-size: 13px; border: 1px solid #E1D7C4; border-radius: 7px; margin: 0 6px 6px 0; }
.gb-junk { background: none; border: 0; padding: 0; color: #9A3B3B; font-size: 12px; text-decoration: underline; }
.gb-done td { background: #F1F8EE; }
.gb-done .gb-choices { display: none; }
.gb-msg { font-size: 13px; font-weight: 600; color: #2E7D32; }
.gb-msg.err { color: #C62828; }
.gb-undo { background: none; border: 0; padding: 0; color: #1565C0; text-decoration: underline; font-size: 12px; margin-left: 6px; }
</style>
<div class="gb-wrap">
    <h1>Genre Bible</h1>
    <div class="gb-tabs">
        <a href="/genre-bible">All artists</a>
        <a href="/genre-bible?tab=decide" class="on">Needs a decision ({{ $decideCount }})</a>
    </div>
    <p class="gb-intro">
        Artists where things don't line up: the sheet says one genre but most of their products are filed in
        another, or their products are split across genres. Pick the genre they belong in. Biggest messes first.
        Your pick becomes their genre everywhere (this page, nivessa.com and Mass Add).
    </p>
    <div class="gb-controls">
        <input class="gb-search" id="gbQ" type="search" placeholder="Search an artist">
        @if ($canEdit)
            <label class="gb-check"><input type="checkbox" id="gbMove" checked> Also move their products into the genre I pick</label>
        @endif
    </div>
    <div class="gb-count" id="gbN"></div>
    <table class="gb-table">
        <thead><tr><th style="width:38%">Artist</th><th>Pick their genre</th></tr></thead>
        <tbody id="gbBody">
        @foreach ($rows as $r)
            <tr data-n="{{ mb_strtolower($r['name']) }}" data-a="{{ $r['name'] }}">
                <td>
                    <a class="gb-name" href="/stock-lookup?q={{ urlencode($r['name']) }}">{{ $r['name'] }}</a>
                    <div class="gb-why">
                        @if ($r['differs'])
                            <span class="gb-tag gb-tag-sheet">Sheet vs shelf</span>
                            Sheet says {{ $r['sheet'] }}, most products are {{ $r['genre'] }}.
                        @endif
                        @if ($r['toMove'] > 0)
                            <span class="gb-tag gb-tag-split">Split</span>
                            {{ implode(' · ', array_map(function ($bin, $n) { return $bin . ' ' . $n; }, array_keys($r['counts']), $r['counts'])) }}
                        @endif
                        @if ($r['added'])
                            <br>Decided before: {{ $r['genre'] }} ({{ $r['toMove'] }} products still elsewhere)
                        @elseif ($r['sheet'] === null)
                            <br><span class="gb-tag gb-tag-new">Not on sheet</span>
                        @endif
                    </div>
                </td>
                <td>
                    @if ($canEdit)
                        <div class="gb-choices">
                            @foreach ($r['counts'] as $bin => $n)
                                <button type="button" class="gb-choice {{ $bin === $r['genre'] ? 'cur' : '' }}" data-g="{{ $bin }}">{{ $bin }} ({{ $n }})</button>
                            @endforeach
                            @if ($r['sheet'] !== null && !isset($r['counts'][$r['sheet']]))
                                <button type="button" class="gb-choice" data-g="{{ $r['sheet'] }}">{{ $r['sheet'] }} (sheet)</button>
                            @endif
                            <select class="gb-other" aria-label="Other genre for {{ $r['name'] }}">
                                <option value="">Other genre…</option>
                                @foreach ($genres as $g)
                                    <option value="{{ $g }}">{{ $g }}</option>
                                @endforeach
                            </select>
                            <br><button type="button" class="gb-junk">Not an artist (hide it)</button>
                        </div>
                        <span class="gb-msg"></span>
                    @else
                        <span class="gb-why">Ask a manager to decide.</span>
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection

@section('javascript')
<script>
(function () {
    var q = document.getElementById('gbQ'), n = document.getElementById('gbN'),
        rows = document.getElementById('gbBody').rows, timer = null,
        token = (document.querySelector('meta[name="csrf-token"]') || {}).content;
    function run() {
        var term = q.value.trim().toLowerCase(), shown = 0;
        for (var i = 0; i < rows.length; i++) {
            var ok = !term || rows[i].getAttribute('data-n').indexOf(term) !== -1;
            rows[i].style.display = ok ? '' : 'none';
            if (ok) shown++;
        }
        n.textContent = shown.toLocaleString() + ' of ' + rows.length.toLocaleString() + ' artists to decide';
    }
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 120); });
    run();

    function esc(t) { var x = document.createElement('div'); x.textContent = t == null ? '' : String(t); return x.innerHTML; }
    function post(url, body) {
        return fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || ('Error ' + r.status)); return j; }); });
    }
    function setMsg(row, html, err) {
        var m = row.querySelector('.gb-msg');
        m.className = 'gb-msg' + (err ? ' err' : '');
        m.innerHTML = html;
    }
    function busy(row, on) {
        var b = row.querySelectorAll('button, select');
        for (var i = 0; i < b.length; i++) b[i].disabled = on;
    }
    function decide(row, genre) {
        var name = row.getAttribute('data-a'), move = document.getElementById('gbMove').checked;
        busy(row, true);
        setMsg(row, 'Saving...');
        post('/genre-bible/artist', { name: name, genre: genre }).then(function () {
            if (!move) return null;
            return post('/genre-bible/unify', { artist: name, genre: genre });
        }).then(function (j) {
            var text = '✓ ' + esc(genre);
            if (j) {
                text += ', moved ' + j.moved + ' product' + (j.moved === 1 ? '' : 's');
                if (j.skipped.length) text += ' (skipped: ' + esc(j.skipped.join('; ')) + ')';
                if (j.undo) text += '<button type="button" class="gb-undo" data-u="' + esc(j.undo) + '">Undo move</button>';
            }
            row.classList.add('gb-done');
            setMsg(row, text);
        }).catch(function (e) {
            busy(row, false);
            setMsg(row, esc(e.message), true);
        });
    }
    var body = document.getElementById('gbBody');
    body.addEventListener('click', function (e) {
        var t = e.target, row = t.closest('tr');
        if (!row) return;
        if (t.classList.contains('gb-choice')) {
            decide(row, t.getAttribute('data-g'));
        } else if (t.classList.contains('gb-junk')) {
            if (!confirm('Hide "' + row.getAttribute('data-a') + '"? It drops off the Genre Bible (here and nivessa.com) and Mass Add stops using it. Products are not changed.')) return;
            busy(row, true);
            post('/genre-bible/artist', { name: row.getAttribute('data-a'), genre: 'Not an artist' }).then(function () {
                row.classList.add('gb-done');
                setMsg(row, '✓ Hidden (not an artist)');
            }).catch(function (err) { busy(row, false); setMsg(row, esc(err.message), true); });
        } else if (t.classList.contains('gb-undo')) {
            t.disabled = true;
            post('/genre-bible/undo', { undo: t.getAttribute('data-u') }).then(function (j) {
                setMsg(row, 'Move undone: ' + j.restored + ' product(s) put back. Genre pick kept.');
            }).catch(function (err) { setMsg(row, esc(err.message), true); });
        }
    });
    body.addEventListener('change', function (e) {
        if (e.target.classList.contains('gb-other') && e.target.value) {
            decide(e.target.closest('tr'), e.target.value);
        }
    });
})();
</script>
@endsection
