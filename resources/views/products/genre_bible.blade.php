@extends('layouts.app')
@section('title', 'Genre Bible')

@section('content')
<style>
.gb-wrap { max-width: 1000px; margin: 0 auto; padding: 24px 16px 60px; color: #1F1B16; }
.gb-wrap h1 { font-size: 26px; font-weight: 700; margin: 0 0 6px; }
.gb-intro { color: #6B6155; font-size: 14px; margin: 0 0 14px; }
.gb-controls { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 8px; }
.gb-search { flex: 1 1 260px; height: 44px; font-size: 17px; border: 1px solid #E1D7C4; border-radius: 10px; padding: 0 14px; }
.gb-select { height: 44px; font-size: 15px; border: 1px solid #E1D7C4; border-radius: 10px; padding: 0 10px; background: #fff; }
.gb-check { font-size: 15px; font-weight: 600; display: inline-flex; gap: 6px; align-items: center; cursor: pointer; margin: 0; }
.gb-count { color: #6B6155; font-size: 13px; margin: 6px 0 10px; }
.gb-table { width: 100%; border-collapse: collapse; font-size: 15px; background: #fff; border: 1px solid #E6DCCF; }
.gb-table th { text-align: left; background: #FAF6EE; padding: 9px 12px; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; color: #6B6155; position: sticky; top: 0; }
.gb-table td { padding: 8px 12px; border-top: 1px solid #F3ECE1; vertical-align: top; }
.gb-genre { font-weight: 700; }
.gb-diff td { background: #FFF8DC; }
.gb-was { color: #8E8273; text-decoration: line-through; }
.gb-filed { color: #6B6155; font-size: 13px; }
.gb-tabs { display: flex; gap: 6px; margin: 0 0 14px; }
.gb-tabs a { padding: 8px 14px; border-radius: 999px; border: 1px solid #E1D7C4; color: #3B2E2A; font-weight: 600; text-decoration: none; }
.gb-tabs a.on { background: #3B2E2A; color: #fff; border-color: #3B2E2A; }
.gb-new { color: #8E8273; font-size: 12px; }
.gb-fix { white-space: nowrap; }
.gb-fix select { height: 30px; font-size: 13px; border: 1px solid #E1D7C4; border-radius: 6px; max-width: 150px; }
.gb-btn { height: 30px; font-size: 13px; font-weight: 700; border: 0; border-radius: 6px; background: #3B2E2A; color: #fff; padding: 0 10px; margin-left: 4px; }
.gb-btn:disabled { opacity: .5; }
.gb-btn-lg { height: 44px; font-size: 15px; padding: 0 14px; border-radius: 10px; }
.gb-msg { font-size: 12px; color: #2E7D32; display: block; margin-top: 3px; white-space: normal; }
.gb-msg.err { color: #C62828; }
.gb-undo { background: none; border: 0; padding: 0; color: #1565C0; text-decoration: underline; font-size: 12px; }
.gb-bulk { margin: 6px 0 10px; font-size: 14px; }
</style>
<div class="gb-wrap">
    <h1>Genre Bible</h1>
    <div class="gb-tabs">
        <a href="/genre-bible" class="on">All artists</a>
        <a href="/genre-bible?tab=decide">Needs a decision ({{ $decideCount }})</a>
    </div>
    <p class="gb-intro">
        Every artist and the genre section they go in. The genre comes from the artist sheet, unless most of
        our products by that artist are filed in a different genre ({{ $minProducts }}+ products). Then that
        genre wins, and the row is highlighted. Artists we carry ({{ $minProducts }}+ products) who aren't on the
        sheet ({{ $extraCount }}) are added under the genre most of their products are in. Same list as
        <a href="https://nivessa.com/genre-bible" target="_blank" rel="noopener">nivessa.com/genre-bible</a>.
    </p>
    <div class="gb-controls">
        <input class="gb-search" id="gbQ" type="search" placeholder="Search an artist" autofocus>
        <select class="gb-select" id="gbG">
            <option value="">All genres</option>
            @foreach ($genres as $g)
                <option value="{{ $g }}">{{ $g }}</option>
            @endforeach
        </select>
        <label class="gb-check"><input type="checkbox" id="gbD"> Only where it differs ({{ $differCount }})</label>
        @if ($canEdit)
            <label class="gb-check"><input type="checkbox" id="gbS"> Only split across genres ({{ collect($rows)->where('toMove', '>', 0)->count() }})</label>
        @endif
    </div>
    @if ($canEdit)
        <form class="gb-controls" id="gbAdd" autocomplete="off">
            <input class="gb-search" id="gbAddName" placeholder="Add an artist (or change one's genre)" maxlength="191">
            <select class="gb-select" id="gbAddGenre">
                <option value="">Genre…</option>
                @foreach ($genres as $g)
                    <option value="{{ $g }}">{{ $g }}</option>
                @endforeach
            </select>
            <button type="submit" class="gb-btn gb-btn-lg">Add artist</button>
            <span id="gbAddMsg"></span>
        </form>
        <div class="gb-bulk">
            <button type="button" class="gb-btn gb-btn-lg" id="gbAll">Make every shown artist one genre</button>
            <span style="margin-left:14px">Send checked (<b id="gbCk">0</b>) to</span>
            <select class="gb-select" id="gbTo">
                @foreach ($genres as $g)
                    <option value="{{ $g }}">{{ $g }}</option>
                @endforeach
            </select>
            <button type="button" class="gb-btn gb-btn-lg" id="gbSend">Send</button>
            <span id="gbAllMsg"></span>
        </div>
    @endif
    <div class="gb-count" id="gbN"></div>
    <table class="gb-table">
        <thead><tr>@if ($canEdit)<th><input type="checkbox" id="gbCkAll" title="Check all shown"></th>@endif<th>Artist</th><th>Genre</th><th>How we file it</th>@if ($canEdit)<th>Make all one genre</th>@endif</tr></thead>
        <tbody id="gbBody">
        @foreach ($rows as $r)
            <tr class="{{ $r['differs'] ? 'gb-diff' : '' }}" data-n="{{ mb_strtolower($r['name']) }}" data-g="{{ $r['genre'] }}" data-d="{{ $r['differs'] ? 1 : 0 }}" data-m="{{ $r['toMove'] }}" data-a="{{ $r['name'] }}">
                @if ($canEdit)
                    <td>@if ($r['counts'])<input type="checkbox" class="gb-ck" aria-label="Select {{ $r['name'] }}">@endif</td>
                @endif
                <td><a href="/stock-lookup?q={{ urlencode($r['name']) }}">{{ $r['name'] }}</a></td>
                <td>
                    <span class="gb-genre">{{ $r['genre'] }}</span>
                    @if ($r['differs'])
                        <br><span class="gb-was">Sheet: {{ $r['sheet'] }}</span>
                    @elseif ($r['added'])
                        <br><span class="gb-new">Added on this page</span>
                    @elseif ($r['sheet'] === null)
                        <br><span class="gb-new">Not on the sheet, from how we file it</span>
                    @endif
                </td>
                <td class="gb-filed">
                    @if ($r['counts'])
                        {{ implode(' · ', array_map(function ($bin, $n) { return $bin . ' ' . $n; }, array_keys($r['counts']), $r['counts'])) }}
                    @else
                        No products yet
                    @endif
                </td>
                @if ($canEdit)
                    <td class="gb-fix">
                        @if ($r['toMove'] > 0)
                            <select aria-label="Genre for {{ $r['name'] }}">
                                @foreach ($genres as $g)
                                    <option value="{{ $g }}" {{ $g === $r['genre'] ? 'selected' : '' }}>{{ $g }}</option>
                                @endforeach
                            </select><button type="button" class="gb-btn gb-one">Make all</button>
                        @endif
                    </td>
                @endif
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection

@section('javascript')
<script>
(function () {
    var q = document.getElementById('gbQ'), g = document.getElementById('gbG'), d = document.getElementById('gbD'),
        s = document.getElementById('gbS'),
        n = document.getElementById('gbN'), rows = document.getElementById('gbBody').rows, timer = null;
    function run() {
        var term = q.value.trim().toLowerCase(), genre = g.value, only = d.checked, split = s && s.checked, shown = 0;
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i], ok = (!term || r.getAttribute('data-n').indexOf(term) !== -1)
                && (!genre || r.getAttribute('data-g') === genre)
                && (!only || r.getAttribute('data-d') === '1')
                && (!split || r.getAttribute('data-m') !== '0');
            r.style.display = ok ? '' : 'none';
            if (ok) shown++;
        }
        n.textContent = shown.toLocaleString() + ' of ' + rows.length.toLocaleString() + ' artists';
    }
    q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 120); });
    g.addEventListener('change', run);
    d.addEventListener('change', run);
    if (s) s.addEventListener('change', run);
    var startQ = new URLSearchParams(location.search).get('q');
    if (startQ) q.value = startQ;
    run();

    // ── Make all one genre ────────────────────────────────────────────
    var token = (document.querySelector('meta[name="csrf-token"]') || {}).content;
    function esc(t) { var x = document.createElement('div'); x.textContent = t == null ? '' : String(t); return x.innerHTML; }
    function post(url, body) {
        return fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify(body)
        }).then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || ('Error ' + r.status)); return j; }); });
    }
    function msg(row, html, err) {
        var cell = row.querySelector('.gb-fix') || row.cells[row.cells.length - 1], m = cell.querySelector('.gb-msg');
        if (!m) { m = document.createElement('span'); m.className = 'gb-msg'; cell.appendChild(m); }
        m.className = 'gb-msg' + (err ? ' err' : '');
        m.innerHTML = html;
    }
    function fmtCounts(c) {
        var parts = [];
        for (var k in c) parts.push(k + ' ' + c[k]);
        return parts.length ? parts.join(' · ') : 'No products yet';
    }
    function unify(row, forced) {
        var sel = row.querySelector('.gb-fix select'), btn = row.querySelector('.gb-one') || {}, genre = forced || sel.value;
        btn.disabled = true;
        return post('/genre-bible/unify', { artist: row.getAttribute('data-a'), genre: genre }).then(function (j) {
            row.querySelector('.gb-filed').textContent = fmtCounts(j.counts);
            row.querySelector('.gb-genre').textContent = genre;
            row.setAttribute('data-g', genre);
            row.setAttribute('data-m', '0');
            if (sel) sel.value = genre;
            var ck = row.querySelector('.gb-ck');
            if (ck) { ck.checked = false; countChecked(); }
            var text = 'Moved ' + j.moved + ' to ' + esc(genre) + '.';
            if (j.skipped.length) text += ' Skipped: ' + esc(j.skipped.join('; ')) + '.';
            if (j.undo) text += ' <button type="button" class="gb-undo" data-u="' + esc(j.undo) + '">Undo</button>';
            msg(row, text, false);
            return j;
        }).catch(function (e) {
            msg(row, esc(e.message), true);
            return null;
        }).then(function (j) { btn.disabled = false; return j; });
    }
    document.getElementById('gbBody').addEventListener('click', function (e) {
        var row = e.target.closest('tr');
        if (e.target.classList.contains('gb-one')) {
            var genre = row.querySelector('.gb-fix select').value;
            if (!confirm('Move every ' + row.getAttribute('data-a') + ' product to ' + genre + '?')) return;
            unify(row);
        } else if (e.target.classList.contains('gb-undo')) {
            e.target.disabled = true;
            post('/genre-bible/undo', { undo: e.target.getAttribute('data-u') }).then(function (j) {
                msg(row, 'Undone: ' + j.restored + ' product(s) put back. Reload to see counts.', false);
            }).catch(function (err) { msg(row, esc(err.message), true); });
        }
    });
    var addForm = document.getElementById('gbAdd');
    if (addForm) addForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var name = document.getElementById('gbAddName').value.trim(), genre = document.getElementById('gbAddGenre').value,
            m = document.getElementById('gbAddMsg');
        if (!name || !genre) { m.textContent = 'Type an artist and pick a genre.'; return; }
        m.textContent = 'Saving...';
        post('/genre-bible/artist', { name: name, genre: genre }).then(function (j) {
            m.textContent = (j.updated ? 'Updated ' : 'Added ') + name + ' to ' + genre + '. Reloading...';
            location.href = location.pathname + '?q=' + encodeURIComponent(name);
        }).catch(function (err) { m.textContent = err.message; });
    });
    var allMsg = document.getElementById('gbAllMsg'), ckN = document.getElementById('gbCk');
    function countChecked() { if (ckN) ckN.textContent = document.querySelectorAll('.gb-ck:checked').length; }
    document.getElementById('gbBody').addEventListener('change', function (e) { if (e.target.classList.contains('gb-ck')) countChecked(); });
    var ckAll = document.getElementById('gbCkAll');
    if (ckAll) ckAll.addEventListener('change', function () {
        for (var i = 0; i < rows.length; i++) {
            var c = rows[i].querySelector('.gb-ck');
            if (c && rows[i].style.display !== 'none') c.checked = ckAll.checked;
        }
        countChecked();
    });
    function runQueue(todo, genre, button) {
        button.disabled = true;
        var done = 0, moved = 0, failed = 0;
        (function next() {
            if (!todo.length) {
                button.disabled = false;
                allMsg.textContent = 'Done: ' + moved + ' products moved for ' + done + ' artists' + (failed ? ', ' + failed + ' failed' : '') + '. Each row has its own Undo.';
                return;
            }
            var r = todo.shift();
            allMsg.textContent = 'Working... ' + done + ' artists done, ' + todo.length + ' left';
            unify(r, genre).then(function (j) { done++; if (j) moved += j.moved; else failed++; next(); });
        })();
    }
    var send = document.getElementById('gbSend');
    if (send) send.addEventListener('click', function () {
        var todo = [], genre = document.getElementById('gbTo').value;
        for (var i = 0; i < rows.length; i++) {
            var c = rows[i].querySelector('.gb-ck');
            if (c && c.checked) todo.push(rows[i]);
        }
        if (!todo.length) { allMsg.textContent = 'Check some artists first.'; return; }
        if (!confirm('Move every product by ' + todo.length + ' checked artist(s) to ' + genre + '?')) return;
        runQueue(todo, genre, send);
    });
    var all = document.getElementById('gbAll');
    if (all) all.addEventListener('click', function () {
        var todo = [], products = 0;
        for (var i = 0; i < rows.length; i++) {
            var r = rows[i];
            if (r.style.display !== 'none' && r.getAttribute('data-m') !== '0' && r.querySelector('.gb-one')) {
                todo.push(r);
                products += parseInt(r.getAttribute('data-m'), 10) || 0;
            }
        }
        if (!todo.length) { allMsg.textContent = 'Nothing to move in the rows shown.'; return; }
        if (!confirm('Make ' + todo.length + ' artists one genre each (about ' + products + ' products), using the genre picked on each row?')) return;
        runQueue(todo, null, all);
    });
})();
</script>
@endsection
