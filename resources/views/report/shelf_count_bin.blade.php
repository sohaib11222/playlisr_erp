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
.sc-aprice { margin-left: 8px; width: 110px; height: 40px; border: 2px solid #2E6B2E; border-radius: 10px; font-size: 18px; padding: 0 8px; vertical-align: middle; }
.sc-addbtn { margin-left: 8px; height: 40px; border: 0; border-radius: 10px; background: #2E6B2E; color: #fff; font-size: 15px; font-weight: 700; padding: 0 14px; cursor: pointer; vertical-align: middle; }
#scAdd .sc-shade { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 1050; display: flex; align-items: flex-end; justify-content: center; }
.sc-addbox { background: #fff; border-radius: 16px 16px 0 0; padding: 14px 16px calc(14px + env(safe-area-inset-bottom)); width: 100%; max-width: 520px; max-height: 92vh; overflow-y: auto; box-shadow: 0 -4px 20px rgba(0,0,0,.25); }
.sc-addbox h3 { margin: 0 0 8px; font-size: 18px; font-weight: 800; }
@media (min-width: 600px) { #scAdd .sc-shade { align-items: center; } .sc-addbox { border-radius: 16px; } }
.sc-addbox .row2 { display: flex; gap: 10px; align-items: flex-start; }
.sc-addbox img { width: 72px; height: 72px; object-fit: cover; border-radius: 8px; background: #F1EADF; flex: none; }
.sc-addbox label { display: block; font-size: 13px; color: #2B2620; margin: 6px 0 2px; }
.sc-addbox input { width: 100%; height: 44px; font-size: 17px; border: 1px solid #1F1B16; border-radius: 10px; padding: 0 10px; }
.sc-addbox .prices { display: flex; gap: 10px; }
.sc-addbox .prices > div { flex: 1; }
.sc-addbox .acts { display: flex; gap: 8px; margin-top: 12px; align-items: center; flex-wrap: wrap; }
.sc-addbox .acts #scAGo { flex: 1; }
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
/* Ask the ERP button sat on top of Done (Jon 11/8): lift it above the bottom bar on this page. */
#erp-help-launcher { bottom: 130px !important; }
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
.sc-prev { color: #8A4B00; font-weight: 700; }
.sc-all { height: 44px; border-radius: 10px; border: 1px solid #2E6B2E; background: #fff; color: #2E6B2E; font-size: 14px; font-weight: 700; padding: 0 10px; cursor: pointer; margin-right: 6px; }
.sc-fixnote { background: #fff; border: 2px solid #1F1B16; border-left: 8px solid #1F1B16; border-radius: 10px; padding: 12px 16px; margin: 0 0 12px; font-size: 16px; line-height: 1.45; color: #1F1B16; }
</style>
<div class="sc-wrap">
    <a class="sc-small" href="/reports/shelf-count?location_id={{ $loc }}&kind={{ $kind }}">Back to bins</a>
    <h1>{{ $kindLabel }} / {{ $name }}</h1>
    <div class="sc-fixnote"><b style="color:#B91C1C">If the record is in the wrong bin in the ERP, please fix it!</b> When you count a bin, tap <b>Genre</b> on any record that belongs somewhere else and pick the right one. This way we know exactly where to find things.</div>
    <div class="sc-how">
        <b>How to count ({{ count($items) }} titles)</b>
        <ol>
            <li>Go down the list as you flip through the bin.</li>
            <li>Tap <b>All here</b>, or type how many you see.</li>
            <li><b>Not there? Tap None to mark it 0. Do this for every missing title.</b></li>
            <li>Record not on the list? Scan it.</li>
            <li>If a record was already counted in another bin this week, or you have more than the ERP says, it asks you first so nothing gets counted twice.</li>
            @if(count((array) $genres))<li>Wrong genre? Tap <b>Genre</b> on that record and pick the right one. It saves right away.</li>@endif
            <li>Tap the green <b>Done</b> button at the bottom. It saves and takes you back to the bins for the next one. Anything short goes on the Missing list.</li>
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
    <div id="scAdd"></div>
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
<div class="sc-bottom"><span class="sc-small" id="scProg" style="align-self:center;"></span><button class="sc-btn" id="scFinish" type="button">Done: save, next bin</button></div>
@endsection

@section('javascript')
<script src="https://cdn.jsdelivr.net/npm/barcode-detector@3.2.2/dist/iife/ponyfill.min.js"></script>
<script>
(function () {
    var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var LOC = {{ (int) $loc }}, KIND = @json($kind), CAN_ADD = {{ auth()->user()->can('product.create') ? 'true' : 'false' }};
    var items = @json($items);
    var GENRES = @json($genres), SUB = {{ (int) $sub }};
    var BIN_KEY = @json($binKey), BIN_LABEL = @json($binLabel);
    var byId = {}, count = {}, touched = {}, extra = [], moved = {}, editing = null;
    // another: said "another copy" for a title counted in a different bin; overOk: highest count okayed above the ERP.
    var another = {}, overOk = {}, lastDialog = 0;
    items.forEach(function (i) { byId[i.id] = i; count[i.id] = 0; });
    function d8(t) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(t || ''); return m ? m[2] + '/' + m[3] + '/' + m[1].slice(2) : (t || ''); }
    function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
    function label(i) { return (i.artist && (i.name || '').toLowerCase().indexOf(String(i.artist).toLowerCase()) === -1 ? i.artist + ' - ' : '') + i.name; }
    // Nivessa SKU (shelf sticker) + price + UPC, so you can tell which copy you're holding (Jon 11/9).
    function ids(i) {
        var p = [];
        if (i.nsku) p.push('#' + esc(i.nsku));
        if (i.price != null && i.price !== '') p.push('<b>$' + esc(Number(i.price).toFixed(2)) + '</b>');
        if (i.sku && String(i.sku) !== String(i.nsku || '')) p.push('UPC ' + esc(i.sku));
        return p.join(' · ');
    }
    function row(i) {
        var n = count[i.id] || 0, none = touched[i.id] && !n;
        return '<div class="sc-item' + (n ? ' seen' : '') + (none ? ' none' : '') + '" data-id="' + i.id + '"><div class="t">' + esc(label(i)) + '<div class="sc-small">' + (ids(i) ? ids(i) + ', ' : '') + (i.bin ? esc(i.bin) + ', ' : '') + 'ERP ' + (i.erp != null ? i.erp : '-') + (none ? ', marked none here' : (touched[i.id] ? '' : (i.cnt && i.cnt.recent && !elsewhere(i) ? '' : ', not counted yet'))) + '</div>'
            + prevLine(i)
            + (moved[i.id] ? '<div class="sc-small sc-moved">Genre changed to ' + esc(moved[i.id]) + '</div>' : '')
            + (editing === i.id ? genreSelect(i) : '') + '</div>'
            + (GENRES[i.cat] ? '<button class="sc-genre" type="button">Genre</button>' : '')
            + (i.erp > 0 && i.erp <= 2 && !(touched[i.id] && n === Math.round(i.erp)) ? '<button class="sc-all" type="button">All here</button>' : '')
            + (!none ? '<button class="sc-all sc-zero" type="button">None</button>' : '')
            + '<input class="sc-n" type="number" inputmode="numeric" enterkeyhint="next" min="0" step="1" aria-label="Copies here" placeholder="#" value="' + (touched[i.id] ? n : '') + '"></div>';
    }
    // Last saved count of this title at this store, and where (Jon 11/8).
    function elsewhere(i) { var c = i.cnt; return !!(c && c.recent && c.bin_key && c.bin_key !== BIN_KEY); }
    function prevLine(i) {
        var c = i.cnt; if (!c || !c.at) return '';
        if (elsewhere(i)) return '<div class="sc-small sc-prev">Already counted ' + d8(c.at) + ' in ' + esc(c.bin) + ' (' + c.qty + ')' + (another[i.id] ? ', counting another copy here' : '') + '</div>';
        // Counted in this bin recently (Sarah 11/12: overview said counted, bin said "not counted yet").
        if (c.recent && !touched[i.id]) return '<div class="sc-small sc-prev" style="color:#2E6B2E;font-weight:700;">Counted ' + d8(c.at) + (c.by ? ' by ' + esc(c.by) : '') + ': ' + c.qty + (c.qty === 1 ? ' copy' : ' copies') + '. Count again only if it changed.</div>';
        return '';
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
        document.getElementById('scProg').textContent = done + ' of ' + items.length + ' titles counted (' + seen + ' found). Counts save when you tap Done. Genre changes save right away.'
            + (findQ ? ' Showing ' + shown.length + ' matching "' + findQ + '".' : '');
    }
    // Search this list as you type (Sarah 11/3). Every word has to match
    // somewhere in artist, title, SKU or bin spot; accents ignored.
    var findQ = '', findWords = [];
    function fold(t) { return String(t == null ? '' : t).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, ' '); }
    function matches(i) {
        if (!findWords.length) return true;
        var hay = ' ' + fold([i.artist, i.artist ? fileAs(i.artist) : '', i.name, i.sku, i.nsku, i.bin].join(' ')) + ' ';
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
        try { localStorage.setItem(DRAFT, JSON.stringify({ count: count, touched: touched, extra: extra, another: another, overOk: overOk, at: Date.now() })); } catch (e) {}
    }
    try {
        var dr = JSON.parse(localStorage.getItem(DRAFT) || 'null');
        if (dr && dr.count) {
            Object.keys(dr.count).forEach(function (k) { count[k] = dr.count[k]; if (dr.touched ? dr.touched[k] : dr.count[k] > 0) touched[k] = true; });
            another = dr.another || {}; overOk = dr.overOk || {};
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
    // After a pop-up the camera may still see the same barcode; don't count it again for 1.5s.
    function quiet() { lastDialog = Date.now(); }
    function skipped(i, why) {
        var f = document.getElementById('scFlash'); f.className = 'sc-flash bad'; f.textContent = why + label(i);
        render();
    }
    /**
     * Ask before a count that might be a double (Jon 11/8):
     *  - counted in another bin in the last 7 days (record moved from Rock to Pop
     *    and scanned again): same copy = don't count it; another copy = add it on
     *    top of the ERP stock, which that count already set.
     * (The over-ERP "are you sure?" popup was removed 11/9: staff disliked it, and
     *  if it's being scanned, we have it. Tap the count to fix a double scan.)
     * Returns the new count, or null to not count it.
     */
    function gate(i, next, cur) {
        if (next <= cur) return next;
        if (!touched[i.id] && !another[i.id] && elsewhere(i)) {
            var c = i.cnt;
            var ok = window.confirm(label(i) + '\n\nAlready counted ' + d8(c.at) + ' in ' + c.bin + ' (' + c.qty + ' there' + (c.by ? ', by ' + c.by : '') + ').\n\n'
                + 'OK = this is ANOTHER copy, count it too\nCancel = same copy that got moved here, don\'t count it twice');
            quiet();
            if (!ok) { skipped(i, 'Not counted twice (already counted in ' + c.bin + '): '); return null; }
            another[i.id] = true;
            // That count already set the ERP, so copies here go on top of it.
            return Math.round(i.erp || 0) + (next - cur);
        }
        return next;
    }
    function bump(i, d) {
        var known = byId[i.id] || i, cur = count[i.id] || 0;
        var want = gate(known, Math.max(0, cur + d), cur);
        if (want === null) return;
        d = want - cur;
        if (!byId[i.id]) { byId[i.id] = i; extra.unshift(i); count[i.id] = 0; }
        count[i.id] = Math.max(0, (count[i.id] || 0) + d);
        touched[i.id] = true;
        keepDraft();
        render();
        var f = document.getElementById('scFlash'); f.className = 'sc-flash ok';
        // The count is a button (Jon 11/6): tap it to type the right number, e.g. after scanning the same record twice.
        f.innerHTML = esc((d > 0 ? (count[i.id] > 1 && d === 1 ? 'Another copy: ' : 'Added: ') : (count[i.id] ? 'Changed: ' : 'None here: ')) + label(i)) + (ids(known) ? ' <span class="sc-small">' + ids(known) + '</span>' : '')
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
    // Not in the ERP: add it from here (Jon 11/6). Discogs fills in title, artist,
    // cover and a price from the barcode; one tap creates it like Mass Add does
    // (1 copy in stock at this store, this bin's format + genre) and counts it.
    function openAdd(code, price) {
        var box = document.getElementById('scAdd'), f = document.getElementById('scFlash');
        f.className = 'sc-flash'; f.textContent = 'Adding ' + code + ': getting artist and title from Discogs...';
        box.innerHTML = '';
        fetch('/reports/shelf-count/barcode-info?' + new URLSearchParams({ code: code, kind: KIND, sub: SUB, location_id: LOC }), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); }, function () { return {}; })
            .then(function (d) {
                d = d || {};
                // Jon 11/6: just submit it. Discogs knows the artist + title: add it now with the typed price, no popup.
                if (d.found && d.name && d.artist) {
                    submitAdd(code, d, { name: d.name, artist: d.artist, price: price, qty: 1 }, function (err) { showAddForm(code, d, err, price); });
                } else {
                    showAddForm(code, d, '', price);
                }
            });
    }
    function submitAdd(code, d, v, onFail) {
        var f = document.getElementById('scFlash');
        f.className = 'sc-flash'; f.textContent = 'Adding to the ERP...';
        fetch('/reports/shelf-count/quick-add', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            body: JSON.stringify({ code: code, kind: KIND, sub: SUB, location_id: LOC, name: v.name, artist: v.artist, price: v.price, qty: v.qty,
                image_url: d.image_url || '', discogs_release_id: d.discogs_release_id || '' }) })
            .then(function (r) { return r.json(); }).then(function (j) {
                if (!j.ok) { onFail(j.msg || 'Could not add it.'); return; }
                document.getElementById('scAdd').innerHTML = '';
                bump(byId[j.item.id] || j.item, j.existing ? 1 : Math.max(1, parseInt(v.qty, 10) || 1));
                beepOk();
                f.insertAdjacentHTML('beforeend', '<div class="sc-small" style="margin-top:4px;">'
                    + (j.existing ? 'It was already in the ERP, so it was counted.' : 'New in the ERP: ' + esc(label(j.item)) + ', $' + esc(Number(v.price).toFixed(2)) + ', SKU ' + esc(j.item.sku) + '.')
                    + ' <a href="' + esc(j.edit_url) + '" target="_blank">Edit price</a></div>');
                document.getElementById('scQ').focus();
            }, function () { onFail('Could not add it. Check the connection.'); });
    }
    // Only when Discogs can't fill it in: a small phone popup for artist, title and price.
    function showAddForm(code, d, err, price) {
        var box = document.getElementById('scAdd'), f = document.getElementById('scFlash');
        f.className = 'sc-flash'; f.textContent = '';
        var hint = d.found ? 'Check the artist and title, then tap Add.' : 'Not on Discogs. Type the artist and title, then tap Add.';
        box.innerHTML = '<div class="sc-shade"><div class="sc-addbox" role="dialog" aria-label="Add to ERP"><h3>Add to ERP</h3><div class="row2">' + (d.image_url ? '<img src="' + esc(d.image_url) + '" alt="">' : '')
            + '<div style="flex:1;"><div class="sc-small">' + esc(hint) + ' Barcode ' + esc(code) + '</div>'
            + '<label>Artist</label><input id="scAArtist" value="' + esc(d.artist || '') + '" autocomplete="off">'
            + '<label>Title</label><input id="scAName" value="' + esc(d.name || '') + '" autocomplete="off"></div></div>'
            + '<div class="prices"><div><label>Selling price $</label><input id="scAPrice" type="number" inputmode="decimal" step="0.01" min="0" value="' + esc(price || '') + '"></div>'
            + '<div><label>Copies here</label><input id="scAQty" type="number" inputmode="numeric" min="1" step="1" value="1"></div></div>'
            + '<div class="acts"><button class="sc-btn" id="scAGo" type="button">Add</button><button class="sc-btn light" id="scACancel" type="button">Cancel</button></div>'
            + '<div class="sc-small" id="scAMsg" style="color:#8E1B1B;font-weight:700;margin-top:6px;">' + esc(err || '') + '</div></div></div>';
        function closeAdd() { box.innerHTML = ''; document.getElementById('scQ').focus(); }
        document.getElementById('scACancel').addEventListener('click', closeAdd);
        box.querySelector('.sc-shade').addEventListener('click', function (e) { if (e.target === this) closeAdd(); });
        // Enter in any box = Add (one less tap on the phone keyboard).
        box.querySelectorAll('input').forEach(function (el) { el.setAttribute('enterkeyhint', 'done'); el.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); document.getElementById('scAGo').click(); } }); });
        document.getElementById('scAGo').addEventListener('click', function () {
            var btn = this, msg = document.getElementById('scAMsg');
            btn.disabled = true; msg.textContent = '';
            submitAdd(code, d, { name: document.getElementById('scAName').value, artist: document.getElementById('scAArtist').value,
                price: document.getElementById('scAPrice').value, qty: document.getElementById('scAQty').value },
                function (e) { btn.disabled = false; msg.textContent = e; });
        });
        var first = !d.artist ? document.getElementById('scAArtist') : !d.name ? document.getElementById('scAName') : document.getElementById('scAPrice');
        if (first) first.focus();
    }
    var choices = [];
    function showChoices(list) {
        choices = list;
        document.getElementById('scChoices').innerHTML = '<div class="sc-small">Which one? Tap it to add 1.</div>' + list.map(function (x, k) {
            return '<div class="sc-item sc-choice" role="button" tabindex="0" data-k="' + k + '" style="cursor:pointer;"><div class="t">' + esc(label(x)) + '<div class="sc-small">' + ids(x) + '</div></div></div>';
        }).join('');
    }
    function clearChoices() { choices = []; document.getElementById('scChoices').innerHTML = ''; }
    function lookup(q) {
        q = q.trim(); if (!q) return;
        clearChoices();
        beepOk();
        var digits = q.replace(/[\s-]/g, '');
        // Barcode already in this bin: count it without a server call.
        if (/^\d{4,14}$/.test(digits)) {
            var key = digits.replace(/^0+/, '');
            var hit = items.concat(extra).filter(function (i) { return [i.sku, i.nsku].some(function (s) { return String(s || '').replace(/[\s-]/g, '').replace(/^0+/, '') === key; }); });
            if (hit.length === 1) { bump(hit[0], 1); return; }
        }
        fetch('/reports/shelf-count/lookup?location_id=' + LOC + '&q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) {
            var f = document.getElementById('scFlash');
            if (!j.items.length) {
                beepBad(); f.className = 'sc-flash bad'; f.textContent = 'Not found in the ERP: ' + q;
                // Add it right here (Jon 11/6): type the price, Enter. Artist + title come from Discogs, the barcode is the SKU.
                if (CAN_ADD) {
                    var row = document.createElement('div'); row.style.marginTop = '6px';
                    row.innerHTML = '<span class="sc-small">Add it:</span><input class="sc-aprice" type="number" inputmode="decimal" step="0.01" min="0" placeholder="$ price" enterkeyhint="done"><button type="button" class="sc-addbtn">Add to ERP</button>';
                    f.appendChild(row);
                    // Or it's in the ERP under another barcode / no barcode (Jon 11/6): find it by artist or title.
                    var srow = document.createElement('div'); srow.style.marginTop = '6px';
                    srow.innerHTML = '<span class="sc-small">Or find it:</span><input class="sc-aprice sc-afind" type="search" placeholder="Type artist or title" enterkeyhint="search" style="width:220px;"><button type="button" class="sc-addbtn sc-afindgo">Find</button>';
                    f.insertBefore(srow, row);
                    var fi = srow.querySelector('.sc-afind');
                    var find = function () {
                        var t = fi.value.trim(); if (t.length < 2) { fi.focus(); return; }
                        fetch('/reports/shelf-count/lookup?location_id=' + LOC + '&q=' + encodeURIComponent(t), { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (k) {
                            if (!k.items.length) { document.getElementById('scChoices').innerHTML = '<div class="sc-small">Nothing in the ERP matches "' + esc(t) + '". Add it below with a price.</div>'; return; }
                            showChoices(k.items);
                        });
                    };
                    srow.querySelector('.sc-afindgo').addEventListener('click', find);
                    fi.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); find(); } });
                    var pr = row.querySelector('.sc-aprice'), go = row.querySelector('.sc-addbtn');
                    var send = function () { if (!(parseFloat(pr.value) > 0)) { pr.focus(); pr.style.borderColor = '#8E1B1B'; return; } go.disabled = true; openAdd(q, pr.value); };
                    go.addEventListener('click', send);
                    pr.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); send(); } });
                }
                return;
            }
            if (j.items.length === 1) { bump(byId[j.items[0].id] || j.items[0], 1); return; }
            showChoices(j.items);
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
                    if (code && code === last && now - Math.max(lastAt, lastDialog) < 1500) { lastAt = now; }
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
    // Done = save + back to the bin list for the next bin (Jon 11/8: one tap, no review step).
    var BACK = '/reports/shelf-count?location_id=' + LOC + '&kind=' + encodeURIComponent(KIND);
    document.getElementById('scFinish').addEventListener('click', save);
    function save() {
        // Only titles someone counted (Sarah 10/16: "what if she didnt count it yet?").
        var all = (save.retry || items.concat(extra).filter(function (x) { return touched[x.id]; })), i = 0, failed = [], msg = document.getElementById('scProg'), btn = document.getElementById('scFinish');
        if (!all.length) {
            if (window.confirm('Nothing counted in this bin yet. Go back to the bins anyway?')) { saved = true; location.href = BACK; }
            return;
        }
        btn.disabled = true;
        (function next() {
            if (i >= all.length) {
                if (failed.length) { msg.textContent = failed.length + ' did not save. Tap Done again to retry them (nothing is lost).'; save.retry = failed; btn.disabled = false; return; }
                saved = true; save.retry = null; try { localStorage.removeItem(DRAFT); } catch (e) {}
                msg.textContent = 'Saved ' + all.length + ' titles. Going back to the bins...';
                location.href = BACK;
                return;
            }
            var x = all[i++];
            msg.textContent = 'Saving ' + i + ' of ' + all.length + '...';
            fetch('/reports/reorder/count', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ product_id: x.id, qty: String(count[x.id] || 0), location_id: LOC, bin_key: BIN_KEY, bin: BIN_LABEL }) })
                .then(function (r) { return r.json(); }).then(function (j) { if (!j.ok) failed.push(x); next(); }, function () { failed.push(x); next(); });
        })();
    }
    render();
})();
</script>
@endsection
