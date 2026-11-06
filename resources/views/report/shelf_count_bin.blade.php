@extends('layouts.app')
@section('title', 'Count Bin')

@section('content')
<style>
.sc-wrap { max-width: 760px; margin: 0 auto; padding: 18px 16px 80px; color: #1F1B16; }
.sc-wrap h1 { font-size: 22px; font-weight: 700; margin: 4px 0; }
.sc-small { color: #2B2620; font-size: 13px; }
.sc-sticky { position: sticky; top: 0; background: #F7F4EE; padding: 8px 0; margin: 12px 0 6px; z-index: 5; }
.sc-scan { display: flex; gap: 8px; }
.sc-find { display: flex; gap: 8px; margin-top: 8px; }
.sc-find input { flex: 1; height: 44px; font-size: 17px; border: 1px solid #1F1B16; border-radius: 10px; padding: 0 12px; background: #fff; }
.sc-find .sc-btn { height: 44px; padding: 0 14px; font-size: 15px; }
.sc-scan input { flex: 1; height: 52px; font-size: 20px; border: 2px solid #1F1B16; border-radius: 12px; padding: 0 14px; }
.sc-btn { border: 0; border-radius: 10px; padding: 0 20px; height: 52px; font-size: 17px; font-weight: 700; background: #2E6B2E; color: #fff; cursor: pointer; }
.sc-btn.light { background: #F1EADF; color: #1F1B16; }
.sc-flash { min-height: 24px; font-size: 15px; font-weight: 600; }
.sc-flash.bad { color: #8E1B1B; }
.sc-flash.ok { color: #2E6B2E; }
.sc-qty { margin-left: 6px; min-width: 52px; height: 40px; border: 2px solid #2E6B2E; border-radius: 10px; background: #fff; color: #2E6B2E; font-size: 20px; font-weight: 800; cursor: pointer; vertical-align: middle; }
.sc-qin { margin-left: 6px; width: 76px; height: 40px; border: 2px solid #2E6B2E; border-radius: 10px; font-size: 20px; font-weight: 800; text-align: center; vertical-align: middle; }
.sc-flash .sc-small { font-weight: 400; }
.sc-item { display: flex; align-items: center; gap: 10px; background: #fff; border: 1px solid #E6DCCF; border-radius: 10px; padding: 10px 12px; margin-bottom: 6px; }
.sc-item .t { flex: 1; font-size: 15px; }
.sc-item .n { font-size: 22px; font-weight: 800; min-width: 34px; text-align: center; }
.sc-item.seen { border-color: #2E6B2E; background: #F2FAF2; }
.sc-item.none { border-color: #8E1B1B; background: #FBF1F1; }
.sc-item.none .n { font-size: 15px; color: #8E1B1B; }
.sc-n { width: 64px; height: 44px; border-radius: 10px; border: 2px solid #1F1B16; font-size: 20px; font-weight: 800; text-align: center; }
.sc-item.none .sc-n { border-color: #8E1B1B; color: #8E1B1B; }
.sc-pm { width: 44px; height: 44px; border-radius: 10px; border: 1px solid #E1D7C4; background: #fff; font-size: 22px; font-weight: 700; cursor: pointer; }
.sc-h { font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #2B2620; margin: 16px 0 6px; }
#scCam { width: 100%; max-width: 420px; margin: 6px 0; display: none; position: relative; }
#scCam video { width: 100%; display: block; border-radius: 10px; background: #000; }
#scCam .sc-aim { position: absolute; left: 6%; right: 6%; top: 50%; height: 2px; background: #E0342B; box-shadow: 0 0 6px #E0342B; }
#scCam .sc-camhint { position: absolute; left: 0; right: 0; bottom: 6px; text-align: center; color: #fff; font-size: 13px; text-shadow: 0 1px 2px #000; }
.sc-bottom { position: fixed; left: 0; right: 0; bottom: 0; background: #fff; border-top: 2px solid #2E6B2E; padding: 10px 16px; display: flex; gap: 14px; justify-content: center; align-items: center; z-index: 10; }
#scProg { font-size: 15px; color: #1F1B16; }
.sc-sum td { padding: 6px 8px; border-top: 1px solid #F3ECE1; font-size: 14px; }
.miss { color: #8E1B1B; font-weight: 700; }
.sc-how { background: #fff; border: 1px solid #E6DCCF; border-radius: 10px; padding: 10px 14px; margin: 8px 0; font-size: 15px; color: #1F1B16; }
.sc-how ol { margin: 6px 0 0 18px; padding: 0; }
.sc-how li { margin-bottom: 4px; }
.sc-zero { border-color: #8E1B1B !important; color: #8E1B1B !important; }
.sc-genre { height: 44px; border-radius: 10px; border: 1px solid #E1D7C4; background: #fff; color: #1F1B16; font-size: 14px; padding: 0 10px; cursor: pointer; margin-right: 6px; }
.sc-gsel { display: block; margin-top: 6px; height: 40px; font-size: 15px; border: 2px solid #1F1B16; border-radius: 8px; padding: 0 8px; max-width: 100%; }
.sc-moved { color: #2E6B2E; font-weight: 700; }
.sc-all { height: 44px; border-radius: 10px; border: 1px solid #2E6B2E; background: #fff; color: #2E6B2E; font-size: 14px; font-weight: 700; padding: 0 10px; cursor: pointer; margin-right: 6px; }
</style>
<div class="sc-wrap">
    <a class="sc-small" href="/reports/shelf-count?location_id={{ $loc }}&kind={{ $kind }}">Back to bins</a>
    <h1>{{ $kindLabel }} / {{ $name }}</h1>
    <div class="sc-how">
        <b>How to count ({{ count($items) }} titles)</b>
        <ol>
            <li>Go down the list as you flip through the bin.</li>
            <li>Tap <b>All here</b>, tap <b>None</b>, or type how many you see.</li>
            <li>Record not on the list? Scan it.</li>
            @if(count((array) $genres))<li>Wrong genre? Tap <b>Genre</b> on that record and pick the right one. It saves right away.</li>@endif
            <li>Tap the green <b>Done</b> button at the bottom, then <b>Save</b>.</li>
        </ol>
    </div>
    <div class="sc-sticky">
    <div class="sc-scan">
        <input id="scQ" type="text" placeholder="Record not on the list? Scan it here" autocomplete="off">
        <button class="sc-btn light" id="scCamBtn" type="button">Camera</button>
    </div>
    <div class="sc-find">
        <input id="scFind" type="search" placeholder="Search this list (artist, title, SKU)" autocomplete="off" enterkeyhint="go">
        <button class="sc-btn light" id="scFindClear" type="button" style="display:none;">Clear</button>
    </div>
    </div>
    <div id="scCam"></div>
    <div class="sc-flash" id="scFlash"></div>
    <div id="scChoices"></div>
    <div class="sc-h" style="display:flex;justify-content:space-between;align-items:center;gap:8px;">In this bin (ERP list)
        <select id="scSort" style="height:36px;font-size:15px;border:1px solid #E1D7C4;border-radius:8px;padding:0 8px;font-weight:400;">
            <option value="artist">Sort: Artist</option><option value="title">Sort: Title</option><option value="bin">Sort: Bin spot</option>
        </select></div>
    <div id="scList"></div>
    <div class="sc-h" id="scExtraH" style="display:none;">Found here but the ERP had it somewhere else</div>
    <div id="scExtra"></div>
    <div id="scSummary"></div>
</div>
<div class="sc-bottom"><span class="sc-small" id="scProg" style="align-self:center;"></span><button class="sc-btn" id="scFinish" type="button">Done: review and save</button></div>
@endsection

@section('javascript')
<script src="https://cdn.jsdelivr.net/npm/barcode-detector@3.2.2/dist/iife/ponyfill.min.js"></script>
<script>
(function () {
    var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var LOC = {{ (int) $loc }};
    var items = @json($items);
    var GENRES = @json($genres), SUB = {{ (int) $sub }};
    var byId = {}, count = {}, touched = {}, extra = [], moved = {}, editing = null;
    items.forEach(function (i) { byId[i.id] = i; count[i.id] = 0; });
    function d8(t) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(t || ''); return m ? m[2] + '/' + m[3] + '/' + m[1].slice(2) : (t || ''); }
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function label(i) { return (i.artist && (i.name || '').toLowerCase().indexOf(String(i.artist).toLowerCase()) === -1 ? i.artist + ' - ' : '') + i.name; }
    function row(i) {
        var n = count[i.id] || 0, none = touched[i.id] && !n;
        return '<div class="sc-item' + (n ? ' seen' : '') + (none ? ' none' : '') + '" data-id="' + i.id + '"><div class="t">' + esc(label(i)) + '<div class="sc-small">' + (i.sku ? 'SKU ' + esc(i.sku) + ', ' : '') + (i.bin ? esc(i.bin) + ', ' : '') + 'ERP ' + (i.erp != null ? i.erp : '-') + (none ? ', marked none here' : (touched[i.id] ? '' : ', not counted yet')) + '</div>'
            + (moved[i.id] ? '<div class="sc-small sc-moved">Genre changed to ' + esc(moved[i.id]) + '</div>' : '')
            + (editing === i.id ? genreSelect(i) : '') + '</div>'
            + (GENRES[i.cat] ? '<button class="sc-genre" type="button">Genre</button>' : '')
            + (i.erp > 0 && i.erp <= 2 && !(touched[i.id] && n === Math.round(i.erp)) ? '<button class="sc-all" type="button">All here</button>' : '')
            + (!none ? '<button class="sc-all sc-zero" type="button">None</button>' : '')
            + '<input class="sc-n" type="number" inputmode="numeric" enterkeyhint="next" min="0" step="1" aria-label="Copies here" placeholder="#" value="' + (touched[i.id] ? n : '') + '"></div>';
    }
    // Wrong genre (Sarah 10/21): pick another genre of the same format. Saves at once.
    function genreSelect(i) {
        return '<select class="sc-gsel"><option value="">Move to genre...</option>' + GENRES[i.cat].map(function (g) {
            return '<option value="' + g.id + '"' + (g.id === (i.sub || SUB) ? ' disabled' : '') + '>' + esc(g.name) + (g.id === (i.sub || SUB) ? ' (now)' : '') + '</option>';
        }).join('') + '</select>';
    }
    // Sort the list the way the shelf is laid out (Sarah 10/20): artist,
    // title or bin spot. Choice remembered on this device.
    var SORT_KEY = 'shelfcount:sort';
    // People are filed by last name like the shelf (Sarah 10/21): "Bob Dylan"
    // sorts as "Dylan Bob", "Tom Petty & The Heartbreakers" as "Petty Tom".
    // The ERP doesn't know person vs band, so only flip 2-3 word names that
    // start with a common first name; bands that do are listed in NOT_PEOPLE.
    var FIRST = ('aaron adam al alan albert alex alice alicia amy andre andrew andy angel angie ann anna anne annie anthony antonio archie aretha art arthur barbara barry ben benny bernard bert bessie beth betty bill billie billy bing blake bo bob bobbie bobby bonnie brad brandon brenda brian bruce bruno bryan bud buddy burt carl carla carlos carly carol carole caroline carrie cass cat celine charles charley charlie chet chris christina christine chuck cindy claire clarence cliff clint cole colin connie conway cyndi dan daniel danny darlene dave david dean debbie dee del dennis diana diane dick dinah dion dolly don donald donna donny doris dorothy doug duane dusty dwight earl ed eddie eddy edgar edith edward elizabeth ella ellie elton elvis emily emmylou eric erykah etta eva frank frankie fred freddie freddy gary gene george gil gilbert gladys glen glenn gloria gordon grace graham greg gregory gwen hank harold harry hayley helen herb herbie howard ian ike irma isaac isaiah jack jackie jackson jake james jamie jan jane janet janis jason jay jean jeff jeffrey jennifer jenny jerry jessica jesse jill jim jimi jimmie jimmy jo joan joanna joe joel john johnnie johnny jon jonathan joni joseph josh joshua judy julia julian julie justin karen kate katie kay keith kelly ken kenny kevin kim kirk kris kurt kylie lana larry laura lauren lee lena lenny leon leonard les leslie lily linda lionel liz lloyd lou louis lucinda lucy luke lyle mac marc marcus margo maria mariah marian marianne mark marshall martha martin marty marvin mary matt matthew maurice max mel melanie melissa merle michael mick mickey miguel mike miles miley minnie miriam mitch molly muddy nancy nat natalie neil nick nicky nina nora norah norman otis pat patrick patsy patti patty paul paula peggy percy pete peter phil philip phoebe quincy rachel randy ray rick ricky rita rob robert roberta robbie robin rod roger ron ronnie rosanne rose roy ruby rufus russell ruth ry ryan sam sammy sarah scott sean shania sharon sheryl shirley simon sonny stan stephen steve steven stevie sue susan suzanne taylor ted teddy terry thelma thomas tim tina toby todd tom tommy tony townes tracy van vance vern vic victor vince walter warren wayne wendy wes whitney will william willie wilson woody yoko zach').split(' ');
    var NOT_PEOPLE = ['joy division', 'jethro tull', 'molly hatchet', 'van halen', 'ben folds five', 'billy talent', 'tom tom club', 'mac demarco band', 'marshall tucker band', 'lucy dacus band', 'archie bronson outfit', 'joe jackson band'];
    function fileAs(artist) {
        var full = String(artist || '').replace(/\s*\(\d+\)\s*$/, '').trim();
        var lead = full.split(/\s*(?:&|\+|,|\/)\s*|\s+(?:and|with|feat\.?|featuring|ft\.?|x)\s+/i)[0].trim();
        if (NOT_PEOPLE.indexOf(lead.toLowerCase()) !== -1 || NOT_PEOPLE.indexOf(full.toLowerCase()) !== -1) return full;
        var w = lead.split(/\s+/), sfx = /^(jr\.?|sr\.?|ii|iii|iv)$/i.test(w[w.length - 1]) ? w.pop() : '';
        if (w.length < 2 || w.length > 3 || FIRST.indexOf(w[0].toLowerCase()) === -1 || /\d|band$|group$|orchestra$|trio$|quartet$/i.test(lead)) return full;
        var last = w.pop();
        return last + ' ' + w.join(' ') + (sfx ? ' ' + sfx : '') + full.slice(lead.length);
    }
    function sortKey(i, by) {
        var a = i.artist ? fileAs(i.artist) : String(i.name || ''), t = String(i.name || '');
        return (by === 'title' ? t : by === 'bin' ? (i.bin || '~') + ' ' + a : a + ' ' + t).toLowerCase().replace(/^the\s+/, '');
    }
    function sortItems(by) {
        items.sort(function (x, y) { return sortKey(x, by).localeCompare(sortKey(y, by), undefined, { numeric: true }); });
    }
    var sortSel = document.getElementById('scSort');
    try { sortSel.value = localStorage.getItem(SORT_KEY) || 'artist'; } catch (e) {}
    sortItems(sortSel.value);
    sortSel.addEventListener('change', function () { try { localStorage.setItem(SORT_KEY, sortSel.value); } catch (e) {} sortItems(sortSel.value); render(); });
    function render() {
        // Keep the cursor in the box she moved to while the list redraws.
        var ae = document.activeElement, keep = ae && ae.classList && ae.classList.contains('sc-n') ? ae.closest('.sc-item').getAttribute('data-id') : null;
        var shown = items.filter(matches);
        document.getElementById('scList').innerHTML = shown.length ? shown.map(row).join('') : (findQ ? '<div class="sc-small">Nothing on this list matches "' + esc(findQ) + '". Not on the list? Scan it in the box above.</div>' : '');
        if (pendingFocus) setTimeout(focusPending, 0);
        else if (keep) setTimeout(function () { var el = document.querySelector('.sc-item[data-id="' + keep + '"] .sc-n'); if (el) el.focus(); }, 0);
        var shownX = extra.filter(matches);
        document.getElementById('scExtraH').style.display = shownX.length ? '' : 'none';
        document.getElementById('scExtra').innerHTML = shownX.map(row).join('');
        var seen = items.filter(function (i) { return count[i.id] > 0; }).length, done = items.filter(function (i) { return touched[i.id]; }).length;
        document.getElementById('scProg').textContent = done + ' of ' + items.length + ' titles counted (' + seen + ' found). Counts save when you tap Done, then Save. Genre changes save right away.'
            + (findQ ? ' Showing ' + shown.length + ' matching "' + findQ + '".' : '');
    }
    // Search this list as you type (Sarah 11/3). Every word has to match
    // somewhere in artist, title, SKU or bin spot; accents ignored.
    var findQ = '', findWords = [];
    function fold(t) { return String(t == null ? '' : t).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, ' '); }
    function matches(i) {
        if (!findWords.length) return true;
        var hay = ' ' + fold([i.artist, i.artist ? fileAs(i.artist) : '', i.name, i.sku, i.bin].join(' ')) + ' ';
        return findWords.every(function (w) { return hay.indexOf(w) !== -1; });
    }
    var findEl = document.getElementById('scFind'), findClr = document.getElementById('scFindClear');
    function setFind(v) {
        findQ = v.trim(); findWords = fold(findQ).split(' ').filter(Boolean);
        findClr.style.display = findQ ? '' : 'none';
        render();
    }
    findEl.addEventListener('input', function () { setFind(findEl.value); });
    findEl.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { findEl.value = ''; setFind(''); return; }
        // Enter jumps to the first match's count box.
        if (e.key === 'Enter') { e.preventDefault(); var b = document.querySelector('#scList .sc-n, #scExtra .sc-n'); if (b) { b.focus(); b.scrollIntoView({ block: 'center', behavior: 'smooth' }); } }
    });
    findClr.addEventListener('click', function () { findEl.value = ''; setFind(''); findEl.focus(); });
    // Draft kept on this device after every scan, so closing the tab, a
    // dead battery or a refresh never loses a half-counted bin (Sarah 10/14).
    var DRAFT = 'shelfcount:' + LOC + ':{{ $kind }}:{{ (int) request('sub') }}', saved = true;
    function keepDraft() {
        saved = false;
        try { localStorage.setItem(DRAFT, JSON.stringify({ count: count, touched: touched, extra: extra, at: Date.now() })); } catch (e) {}
    }
    try {
        var dr = JSON.parse(localStorage.getItem(DRAFT) || 'null');
        if (dr && dr.count) {
            Object.keys(dr.count).forEach(function (k) { count[k] = dr.count[k]; if (dr.touched ? dr.touched[k] : dr.count[k] > 0) touched[k] = true; });
            (dr.extra || []).forEach(function (i) { if (!byId[i.id]) { byId[i.id] = i; extra.push(i); } });
            saved = false;
            setTimeout(function () { var f = document.getElementById('scFlash'); f.className = 'sc-flash ok'; f.textContent = 'Picked up where you left off (' + new Date(dr.at).toLocaleString() + ').'; }, 0);
        }
    } catch (e) {}
    window.addEventListener('beforeunload', function (e) { if (!saved) { e.preventDefault(); e.returnValue = ''; } });
    // Scan sounds (Jon 11/6): high beep on every scan, low buzz when it isn't in the ERP.
    // Phones only allow sound after a tap/keypress, so the audio is unlocked on the first one.
    var actx = null;
    function unlockAudio() {
        try { if (!actx) actx = new (window.AudioContext || window.webkitAudioContext)(); if (actx.state === 'suspended') actx.resume(); } catch (e) {}
    }
    document.addEventListener('pointerdown', unlockAudio, true);
    document.addEventListener('keydown', unlockAudio, true);
    function tone(freq, ms, type) {
        try {
            unlockAudio(); if (!actx) return;
            var o = actx.createOscillator(), g = actx.createGain(), t = actx.currentTime;
            o.type = type || 'square'; o.frequency.value = freq;
            g.gain.setValueAtTime(0.0001, t); g.gain.exponentialRampToValueAtTime(0.25, t + 0.01); g.gain.exponentialRampToValueAtTime(0.0001, t + ms / 1000);
            o.connect(g); g.connect(actx.destination); o.start(t); o.stop(t + ms / 1000 + 0.02);
        } catch (e) {}
    }
    function beepOk() { tone(1760, 120); }
    function beepBad() { tone(220, 160, 'sawtooth'); setTimeout(function () { tone(180, 220, 'sawtooth'); }, 180); }
    function bump(i, d) {
        if (!byId[i.id]) { byId[i.id] = i; extra.unshift(i); count[i.id] = 0; }
        count[i.id] = Math.max(0, (count[i.id] || 0) + d);
        touched[i.id] = true;
        keepDraft();
        render();
        var f = document.getElementById('scFlash'); f.className = 'sc-flash ok';
        // The count is a button (Jon 11/6): tap it to type the right number, e.g. after scanning the same record twice.
        f.innerHTML = esc((d > 0 ? 'Added: ' : (count[i.id] ? 'Changed: ' : 'None here: ')) + label(i))
            + (count[i.id] || d > 0 ? ' <button class="sc-qty" type="button" data-id="' + i.id + '" aria-label="Copies counted, tap to change">' + count[i.id] + '</button> <span class="sc-small">tap the number to change it</span>' : '');
    }
    function setQty(inp) {
        var it = byId[inp.getAttribute('data-id')]; if (!it || inp.dataset.done) return;
        inp.dataset.done = '1';
        var v = Math.max(0, parseInt(inp.value, 10) || 0);
        bump(it, v - (count[it.id] || 0));
    }
    // Type the number of copies on the shelf (Sarah 10/20: "key in instead of + and -").
    document.querySelector('.sc-wrap').addEventListener('change', function (e) {
        var gs = e.target.closest('.sc-gsel');
        if (gs && gs.value) {
            var gi = byId[gs.closest('.sc-item').getAttribute('data-id')], f = document.getElementById('scFlash');
            gs.disabled = true;
            fetch('/reports/shelf-count/genre', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ product_id: gi.id, sub_id: parseInt(gs.value, 10) }) })
                .then(function (r) { return r.json(); }).then(function (j) {
                    if (!j.ok) { gs.disabled = false; f.className = 'sc-flash bad'; f.textContent = j.msg || 'Could not change the genre.'; return; }
                    gi.sub = parseInt(gs.value, 10); moved[gi.id] = j.genre; editing = null; render();
                    f.className = 'sc-flash ok'; f.textContent = 'Moved to ' + j.genre + ': ' + label(gi);
                }, function () { gs.disabled = false; f.className = 'sc-flash bad'; f.textContent = 'Could not change the genre. Check the connection.'; });
            return;
        }
        var qi = e.target.closest('.sc-qin'); if (qi) { setQty(qi); return; }
        var inp = e.target.closest('.sc-n'); if (!inp) return;
        var it = byId[inp.closest('.sc-item').getAttribute('data-id')];
        if (inp.value === '') { delete touched[it.id]; count[it.id] = 0; keepDraft(); render(); return; }
        var v = Math.max(0, parseInt(inp.value, 10) || 0);
        bump(it, v - (count[it.id] || 0));
    });
    // Enter saves the number and jumps to the next title's box, so a bin
    // can be done on the number pad alone (Sarah 10/20).
    var pendingFocus = null;
    function focusPending() {
        if (!pendingFocus) return;
        var el = document.querySelector('.sc-item[data-id="' + pendingFocus + '"] .sc-n');
        pendingFocus = null;
        if (el) { el.focus(); el.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
    }
    document.querySelector('.sc-wrap').addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && e.target.closest('.sc-qin')) { e.preventDefault(); setQty(e.target); document.getElementById('scQ').focus(); return; }
        if (e.key !== 'Enter' || !e.target.closest('.sc-n')) return;
        e.preventDefault();
        var next = e.target.closest('.sc-item').nextElementSibling;
        pendingFocus = next ? next.getAttribute('data-id') : null;
        e.target.blur();
        setTimeout(focusPending, 0);
    });
    document.querySelector('.sc-wrap').addEventListener('click', function (e) {
        var qb = e.target.closest('.sc-qty');
        if (qb) {
            var qin = document.createElement('input');
            qin.type = 'number'; qin.className = 'sc-qin'; qin.min = '0'; qin.step = '1'; qin.setAttribute('inputmode', 'numeric'); qin.setAttribute('enterkeyhint', 'done');
            qin.setAttribute('data-id', qb.getAttribute('data-id')); qin.value = qb.textContent; qin.setAttribute('aria-label', 'Copies here');
            qb.replaceWith(qin); qin.focus(); qin.select();
            qin.addEventListener('blur', function () { setQty(qin); });
            return;
        }
        var gb = e.target.closest('.sc-genre'); if (gb) { var gid = +gb.closest('.sc-item').getAttribute('data-id'); editing = editing === gid ? null : gid; render(); return; }
        var z = e.target.closest('.sc-zero'); if (z) { var zi = byId[z.closest('.sc-item').getAttribute('data-id')]; count[zi.id] = 0; bump(zi, 0); return; }
        var all = e.target.closest('.sc-all'); if (all) { var ai = byId[all.closest('.sc-item').getAttribute('data-id')]; bump(ai, Math.round(ai.erp) - (count[ai.id] || 0)); return; }
        var b = e.target.closest('.sc-pm'); if (b) { var id = b.closest('.sc-item').getAttribute('data-id'); bump(byId[id], parseInt(b.getAttribute('data-d'), 10)); return; }
        // Tap a "Which one?" pick (fixed 11/6: the old data-i JSON attribute was cut off at the first quote, so taps did nothing).
        var c = e.target.closest('.sc-choice'); if (c) { var x = choices[+c.getAttribute('data-k')]; if (x) bump(byId[x.id] || x, 1); clearChoices(); document.getElementById('scQ').focus(); }
    });
    var choices = [];
    function clearChoices() { choices = []; document.getElementById('scChoices').innerHTML = ''; }
    function lookup(q) {
        q = q.trim(); if (!q) return;
        clearChoices();
        beepOk();
        var digits = q.replace(/[\s-]/g, '');
        // Barcode already in this bin: count it without a server call.
        if (/^\d{4,14}$/.test(digits)) {
            var key = digits.replace(/^0+/, '');
            var hit = items.concat(extra).filter(function (i) { return String(i.sku || '').replace(/[\s-]/g, '').replace(/^0+/, '') === key; });
            if (hit.length === 1) { bump(hit[0], 1); return; }
        }
        fetch('/reports/shelf-count/lookup?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
            var f = document.getElementById('scFlash');
            if (!j.items.length) { beepBad(); f.className = 'sc-flash bad'; f.textContent = 'Not found in the ERP: ' + q; return; }
            if (j.items.length === 1) { bump(byId[j.items[0].id] || j.items[0], 1); return; }
            choices = j.items;
            document.getElementById('scChoices').innerHTML = '<div class="sc-small">Which one? Tap it to add 1.</div>' + j.items.map(function (x, k) {
                return '<div class="sc-item sc-choice" role="button" tabindex="0" data-k="' + k + '" style="cursor:pointer;"><div class="t">' + esc(label(x)) + '<div class="sc-small">' + esc(x.sku) + '</div></div></div>';
            }).join('');
        });
    }
    document.getElementById('scQ').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); lookup(this.value); this.value = ''; }
    });
    // Camera barcode reader (rewritten 11/6: html5-qrcode's JS decoder almost never read record UPC/EAN
    // barcodes on phones). Uses the phone's built-in BarcodeDetector when it has one (Android Chrome),
    // otherwise ZXing-C++ compiled to WebAssembly (iPhone), which reads 1D barcodes well.
    var cam = null;
    function stopCam() {
        if (!cam) return;
        cam.on = false; clearTimeout(cam.t);
        cam.stream.getTracks().forEach(function (t) { t.stop(); });
        cam = null;
        var box = document.getElementById('scCam'); box.innerHTML = ''; box.style.display = 'none';
        document.getElementById('scCamBtn').textContent = 'Camera';
    }
    function makeDetector() {
        var fmts = ['ean_13', 'upc_a', 'ean_8', 'upc_e', 'code_128', 'code_39'];
        var Native = window.BarcodeDetector;
        var Pony = window.BarcodeDetectionAPI && window.BarcodeDetectionAPI.BarcodeDetector;
        var p = (Native && Native.getSupportedFormats) ? Native.getSupportedFormats().catch(function () { return []; }) : Promise.resolve([]);
        return p.then(function (have) {
            if (have.indexOf('ean_13') >= 0) return new Native({ formats: fmts.filter(function (f) { return have.indexOf(f) >= 0; }) });
            if (Pony) return new Pony({ formats: fmts });
            throw new Error('Barcode reader did not load. Reload the page and try again.');
        });
    }
    document.getElementById('scCamBtn').addEventListener('click', function () {
        if (cam) { stopCam(); return; }
        var box = document.getElementById('scCam'), btn = this;
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { alert('This browser cannot open the camera.'); return; }
        btn.textContent = 'Starting...';
        var flash = document.getElementById('scFlash');
        Promise.all([
            navigator.mediaDevices.getUserMedia({ audio: false, video: { facingMode: { ideal: 'environment' }, width: { ideal: 1920 }, height: { ideal: 1080 } } }),
            makeDetector()
        ]).then(function (r) {
            var stream = r[0], det = r[1];
            box.innerHTML = '<video playsinline muted autoplay></video><div class="sc-aim"></div><div class="sc-camhint">Fill the screen with the barcode, line across the bars</div>';
            box.style.display = 'block';
            var v = box.querySelector('video'); v.srcObject = stream; v.setAttribute('playsinline', ''); v.muted = true;
            var p = v.play(); if (p && p.catch) p.catch(function () {});
            // Ask for continuous autofocus where the phone allows it.
            try { var tr = stream.getVideoTracks()[0], caps = tr.getCapabilities ? tr.getCapabilities() : {};
                if (caps.focusMode && caps.focusMode.indexOf('continuous') >= 0) tr.applyConstraints({ advanced: [{ focusMode: 'continuous' }] }).catch(function () {}); } catch (e) {}
            btn.textContent = 'Stop camera';
            cam = { stream: stream, on: true, t: null };
            var me = cam, last = '', lastAt = 0;
            (function tick() {
                if (!me.on) return;
                if (v.readyState < 2) { me.t = setTimeout(tick, 150); return; }
                det.detect(v).then(function (codes) {
                    if (!me.on) return;
                    var code = codes && codes.length ? String(codes[0].rawValue || '').trim() : '';
                    var now = Date.now();
                    // Same barcode only counts again after it has left the camera for 1.5s, so holding
                    // a record in view no longer adds a copy every few seconds.
                    if (code && code === last && now - lastAt < 1500) { lastAt = now; }
                    else if (code) {
                        last = code; lastAt = now;
                        try { if (navigator.vibrate) navigator.vibrate(80); } catch (e) {}
                        flash.className = 'sc-flash ok'; flash.textContent = 'Read ' + code + ', looking it up...';
                        lookup(code);
                    }
                    me.t = setTimeout(tick, 120);
                }, function () { if (me.on) me.t = setTimeout(tick, 300); });
            })();
        }).catch(function (err) {
            stopCam(); btn.textContent = 'Camera';
            var m = String(err && (err.name + ': ' + err.message) || err);
            alert(/NotAllowed|Permission|Security/i.test(m) ? 'Camera is blocked. Allow camera access for this site in your browser settings, then try again.' : 'Camera not available. ' + m);
        });
    });
    document.getElementById('scFinish').addEventListener('click', function () {
        var missing = items.filter(function (i) { return touched[i.id] && (count[i.id] || 0) < i.erp; });
        var skipped = items.filter(function (i) { return !touched[i.id]; });
        var rowsHtml = missing.map(function (i) { return '<tr><td>' + esc(label(i)) + '</td><td>ERP ' + i.erp + '</td><td class="miss">found ' + (count[i.id] || 0) + '</td></tr>'; }).join('');
        document.getElementById('scSummary').innerHTML = '<div class="sc-h">Before saving</div><div>' + missing.length + ' titles you counted have fewer copies than the ERP says. If you looked everywhere (wall, shelves above), press Save. Otherwise keep scanning.</div>'
            + '<table class="sc-sum">' + rowsHtml + '</table>'
            + (skipped.length ? '<p>' + skipped.length + ' titles were not counted (not scanned, no + or -). They stay as they are in the ERP and are not marked missing.</p>' : '')
            + '<p><button class="sc-btn" id="scSave" type="button">Save counts for this bin</button> <span class="sc-small" id="scSaveMsg"></span></p>';
        document.getElementById('scSave').scrollIntoView({ behavior: 'smooth' });
        document.getElementById('scSave').addEventListener('click', save);
    });
    function save() {
        // Only titles someone counted (Sarah 10/16: "what if she didnt count it yet?").
        var all = (save.retry || items.concat(extra).filter(function (x) { return touched[x.id]; })), i = 0, failed = [], msg = document.getElementById('scSaveMsg'), btn = document.getElementById('scSave');
        btn.disabled = true;
        (function next() {
            if (i >= all.length) {
                if (failed.length) { msg.textContent = failed.length + ' did not save. Press Save again to retry them (nothing is lost).'; save.retry = failed; btn.disabled = false; return; }
                saved = true; save.retry = null; try { localStorage.removeItem(DRAFT); } catch (e) {}
                msg.textContent = 'Saved ' + all.length + ' titles. Anything short is on the Missing list.'; return;
            }
            var x = all[i++];
            msg.textContent = 'Saving ' + i + ' of ' + all.length + '...';
            fetch('/reports/reorder/count', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ product_id: x.id, qty: String(count[x.id] || 0), location_id: LOC }) })
                .then(function (r) { return r.json(); }).then(function (j) { if (!j.ok) failed.push(x); next(); }, function () { failed.push(x); next(); });
        })();
    }
    render();
})();
</script>
@endsection
