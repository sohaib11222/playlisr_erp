<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Whatnot sales</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f4f4f4; color: #222; margin: 0; padding: 16px; }
        .card { background: #fff; border-radius: 10px; padding: 16px; max-width: 640px; margin: 0 auto 14px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 18px; margin: 0 0 10px; }
        .sub { color: #333; font-size: 15px; }
        input[type=text], input[type=number], select { width: 100%; font-size: 17px; padding: 12px; border: 1px solid #bbb; border-radius: 8px; }
        .row { display: flex; gap: 8px; align-items: center; }
        .item { border-bottom: 1px solid #eee; padding: 10px 0; }
        .item:last-child { border-bottom: 0; }
        .t { font-weight: 600; font-size: 16px; }
        .meta { font-size: 14px; color: #333; margin-top: 2px; }
        .warn { background: #fff4e5; color: #8a4b00; border-radius: 6px; padding: 6px 8px; font-size: 14px; margin-top: 6px; }
        .bad { background: #fdecea; color: #a12622; border-radius: 6px; padding: 6px 8px; font-size: 14px; margin-top: 6px; }
        .ok { background: #eaf4ea; color: #2b6a2b; border-radius: 6px; padding: 10px; font-size: 15px; }
        button { font-size: 16px; padding: 10px 14px; border: 0; border-radius: 8px; cursor: pointer; }
        .add { background: #d7ebfb; color: #0b4a7a; font-weight: 600; white-space: nowrap; }
        .save { background: #2e7d32; color: #fff; width: 100%; font-size: 18px; padding: 14px; font-weight: 600; }
        .save:disabled { background: #9fbfa1; }
        .link { background: none; color: #0b4a7a; text-decoration: underline; padding: 6px 0; }
        .rm { background: #eee; color: #222; }
        .totals { display: flex; gap: 10px; }
        .totals div { flex: 1; background: #d7ebfb; color: #0b4a7a; border-radius: 8px; padding: 10px; text-align: center; font-size: 14px; }
        .totals b { display: block; font-size: 22px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        td { padding: 6px 4px; border-bottom: 1px solid #eee; vertical-align: top; }
        td.num { text-align: right; white-space: nowrap; }
        .price { width: 110px; flex: none; }
        .hidden { display: none; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ $s['seller_name'] }}'s Whatnot sales</h1>
        <div class="sub">Mark every record you sell on Whatnot here. It comes off the website right away, and you get paid for every item on this list.</div>
    </div>

    @if($flash)
        <div class="card"><div class="{{ $flash['ok'] ? 'ok' : 'bad' }}">{{ $flash['msg'] }}</div></div>
    @endif

    <div class="card">
        <h2>1. Find what you sold</h2>
        <input type="text" id="q" placeholder="Artist or title, or type the barcode" autocomplete="off">
        <div class="meta" style="margin-top:6px;">Barcode faded? Just type a couple of words from the artist or title.</div>
        <div id="results"></div>
        <button type="button" class="link" id="notfound">Can't find it? Add it by name</button>
        <div id="manual" class="hidden" style="margin-top:8px;">
            <input type="text" id="m_title" placeholder="Artist - Title" style="margin-bottom:8px;">
            <div class="row">
                <select id="m_store">
                    @foreach($stores as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
                <button type="button" class="add" id="m_add">Add</button>
            </div>
        </div>
    </div>

    <div class="card">
        <h2>2. What you sold and for how much</h2>
        <div id="cart"><div class="meta">Nothing added yet.</div></div>
        <form method="post" action="{{ url('/whatnot-seller/' . $token . '/sell') }}" id="sellform" style="margin-top:12px;">
            @csrf
            <input type="hidden" name="items" id="items">
            <input type="hidden" name="store_id" id="store_id">
            <input type="hidden" name="submit_id" id="submit_id">
            <button type="submit" class="save" id="save" disabled>Save sold items</button>
        </form>
        <div class="meta" style="margin-top:8px;">@if($s['premium_pct'] !== null) {{ $s['premium_start'] && $s['premium_start'] > now('America/Los_Angeles')->format('Y-m-d') ? 'Starting ' . \Carbon\Carbon::parse($s['premium_start'])->format('m/d/y') . ': s' : 'S' }}ell for more than {{ rtrim(rtrim(number_format($s['premium_pct'], 2), '0'), '.') }}% over sticker and you get ${{ number_format($s['premium_per_item'], 2) }} for that item instead of ${{ number_format($s['per_item'], 2) }}. @endif Rules: sell at {{ $s['min_pct_of_sticker'] }}% of the sticker price or more, and leave anything in the store less than {{ $s['min_days_in_store'] }} days for in-store customers. If you go lower, write why.</div>
    </div>

    <div class="card">
        <h2>3. Log your show hours</h2>
        <form method="post" action="{{ url('/whatnot-seller/' . $token . '/hours') }}">
            @csrf
            <div class="row">
                <input type="date" name="date" value="{{ now('America/Los_Angeles')->format('Y-m-d') }}" required>
                <input type="number" name="hours" class="price" step="0.25" min="0.25" max="16" placeholder="Hours" required>
                <button type="submit" class="add">Log</button>
            </div>
            <input type="text" name="note" placeholder="Note (optional)" style="margin-top:8px;">
        </form>
    </div>

    <div class="card">
        <h2>Your pay since {{ $period_start }}</h2>
        <div class="totals">
            <div>Items sold<b>{{ (int) $pay['items'] }}</b>${{ number_format($pay['item_pay'], 2) }} at ${{ number_format($s['per_item'], 2) }} each @if($s['premium_pct'] !== null) ({{ (int) $pay['premium_items'] }} at ${{ number_format($s['premium_per_item'], 2) }}) @endif</div>
            <div>Show hours<b>{{ rtrim(rtrim(number_format($pay['hours'], 2), '0'), '.') ?: '0' }}</b>${{ number_format($pay['hour_pay'], 2) }} at ${{ number_format($s['hourly_rate'], 2) }}/hr</div>
            <div>Total<b>${{ number_format($pay['total'], 2) }}</b>sold ${{ number_format($sales, 2) }}</div>
        </div>
        @if(count($shows))
            <table style="margin-top:10px;">
                @foreach($shows as $h)
                    <tr><td>{{ \Carbon\Carbon::parse($h['date'])->format('m/d/y') }}</td><td>Show{{ $h['note'] !== '' ? ': ' . $h['note'] : '' }}</td><td class="num">{{ $h['hours'] }} hrs</td></tr>
                @endforeach
            </table>
        @endif
        <table style="margin-top:10px;">
            @forelse($lines as $l)
                <tr>
                    <td>{{ $l['date'] }}</td>
                    <td>{{ $l['title'] }}</td>
                    <td class="num">${{ number_format($l['sold'] * $l['qty'], 2) }}</td>
                </tr>
            @empty
                <tr><td>No Whatnot sales marked yet.</td></tr>
            @endforelse
        </table>
    </div>

<script>
(function () {
    var MIN_PCT = {{ (int) $s['min_pct_of_sticker'] }};
    var PREM_PCT = @json(($s['premium_start'] && $s['premium_start'] > now('America/Los_Angeles')->format('Y-m-d')) ? null : $s['premium_pct']);
    var PREM_PAY = {{ (float) $s['premium_per_item'] }};
    var searchUrl = @json(url('/whatnot-seller/' . $token . '/search'));
    var cart = [];
    var q = document.getElementById('q'), results = document.getElementById('results');
    var timer = null, seq = 0;

    function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    function money(n) { return '$' + Number(n).toFixed(2); }

    q.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(run, 250);
    });

    function run() {
        var term = q.value.trim();
        if (term.length < 2) { results.innerHTML = ''; return; }
        var mine = ++seq;
        fetch(searchUrl + '?q=' + encodeURIComponent(term), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (mine !== seq) return;
                if (!d.items.length) { results.innerHTML = '<div class="item meta">No copies in stock match that. Try fewer words, or add it by name below.</div>'; return; }
                results.innerHTML = d.items.map(function (it, i) {
                    var inCart = cart.some(function (c) { return c.variation_id === it.variation_id && c.location_id === it.location_id; });
                    return '<div class="item"><div class="row"><div style="flex:1">'
                        + '<div class="t">' + esc(it.title) + '</div>'
                        + '<div class="meta">' + esc(it.category) + ' · ' + esc(it.store) + ' · Sticker ' + money(it.price) + ' · In store since ' + esc(it.added) + '</div>'
                        + (it.too_new ? '<div class="warn">New in store. Please leave it for in-store customers until ' + esc(it.ok_after) + '.</div>' : '')
                        + '</div><button type="button" class="add" data-i="' + i + '"' + (inCart ? ' disabled>Added' : '>Sold') + '</button></div></div>';
                }).join('');
                results.querySelectorAll('button[data-i]').forEach(function (b) {
                    b.addEventListener('click', function () {
                        var it = d.items[+b.getAttribute('data-i')];
                        cart.push({ variation_id: it.variation_id, location_id: it.location_id, title: it.title, sticker: it.price, floor: it.floor, too_new: it.too_new, store: it.store, price: '', note: '' });
                        b.disabled = true; b.textContent = 'Added';
                        draw();
                    });
                });
            })
            .catch(function () { results.innerHTML = '<div class="bad">Search did not load. Check your connection and try again.</div>'; });
    }

    document.getElementById('notfound').addEventListener('click', function () {
        document.getElementById('manual').classList.toggle('hidden');
        document.getElementById('m_title').value = q.value;
    });
    document.getElementById('m_add').addEventListener('click', function () {
        var t = document.getElementById('m_title').value.trim();
        if (!t) return;
        var sel = document.getElementById('m_store');
        cart.push({ variation_id: null, location_id: +sel.value, title: t, sticker: null, floor: null, too_new: false, store: sel.options[sel.selectedIndex].text, price: '', note: '' });
        document.getElementById('m_title').value = '';
        draw();
    });

    function draw() {
        var el = document.getElementById('cart');
        if (!cart.length) { el.innerHTML = '<div class="meta">Nothing added yet.</div>'; check(); return; }
        el.innerHTML = cart.map(function (c, i) {
            return '<div class="item"><div class="t">' + esc(c.title) + '</div>'
                + '<div class="meta">' + esc(c.store) + (c.sticker ? ' · Sticker ' + money(c.sticker) : ' · Not found in the ERP') + '</div>'
                + '<div class="row" style="margin-top:6px;"><input type="number" class="price" inputmode="decimal" step="0.01" min="0" placeholder="Sold for" data-p="' + i + '" value="' + esc(c.price) + '">'
                + '<span class="meta" style="flex:1">What it actually sold for on Whatnot, before fees</span>'
                + '<button type="button" class="rm" data-r="' + i + '">Remove</button></div>'
                + '<div data-w="' + i + '"></div>'
                + '<input type="text" placeholder="Why so low? (required under ' + MIN_PCT + '% of sticker)" data-n="' + i + '" value="' + esc(c.note) + '" class="' + (needsNote(c) ? '' : 'hidden') + '" style="margin-top:6px;">'
                + '</div>';
        }).join('');
        el.querySelectorAll('input[data-p]').forEach(function (inp) {
            inp.addEventListener('input', function () {
                var i = +inp.getAttribute('data-p');
                cart[i].price = inp.value;
                el.querySelector('input[data-n="' + i + '"]').classList.toggle('hidden', !needsNote(cart[i]));
                warn(i); check();
            });
        });
        el.querySelectorAll('input[data-n]').forEach(function (inp) {
            inp.addEventListener('input', function () { cart[+inp.getAttribute('data-n')].note = inp.value; check(); });
        });
        el.querySelectorAll('button[data-r]').forEach(function (b) {
            b.addEventListener('click', function () { cart.splice(+b.getAttribute('data-r'), 1); draw(); run(); });
        });
        cart.forEach(function (c, i) { warn(i); });
        check();
    }

    function needsNote(c) {
        var p = parseFloat(c.price);
        return (c.floor && p > 0 && p < c.floor) || c.too_new;
    }

    function warn(i) {
        var c = cart[i], w = document.querySelector('[data-w="' + i + '"]'), p = parseFloat(c.price), html = '';
        if (c.sticker && p > 0 && p < c.floor) {
            html += '<div class="bad">' + Math.round(p / c.sticker * 100) + '% of sticker. The lowest without a reason is ' + money(c.floor) + '.</div>';
        }
        if (PREM_PCT !== null && c.sticker && p > c.sticker * (1 + PREM_PCT / 100)) {
            html += '<div class="ok" style="margin-top:6px;">Premium sale. You get ' + money(PREM_PAY) + ' for this one.</div>';
        }
        if (c.too_new) html += '<div class="warn">This one is new in the store. Write why it went on Whatnot.</div>';
        w.innerHTML = html;
    }

    function check() {
        var ok = cart.length > 0 && cart.every(function (c) {
            return parseFloat(c.price) > 0 && (!needsNote(c) || c.note.trim().length >= 3);
        });
        document.getElementById('save').disabled = !ok;
    }

    document.getElementById('sellform').addEventListener('submit', function (e) {
        document.getElementById('items').value = JSON.stringify(cart.map(function (c) {
            return { variation_id: c.variation_id, location_id: c.location_id, title: c.title, price: parseFloat(c.price), note: c.note };
        }));
        var manual = cart.filter(function (c) { return !c.variation_id; })[0];
        document.getElementById('store_id').value = manual ? manual.location_id : '';
        document.getElementById('submit_id').value = Date.now() + '-' + Math.random().toString(36).slice(2);
        document.getElementById('save').disabled = true;
        document.getElementById('save').textContent = 'Saving...';
    });
})();
</script>
</body>
</html>
