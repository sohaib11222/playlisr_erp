@extends('layouts.app')
@section('title', 'Weekly Reorder')

@section('content')
@php
    $whyLabels = app(\App\Http\Controllers\ReorderController::class)->whyLabels();
    $whyShort = ['sold' => 'Sold', 'core' => 'Always stock', 'overdue' => 'Missing?', 'once' => 'Sold fast once', 'used' => 'Sells used', 'other' => 'Sold this year'];
    $whyColors = ['sold' => '#2e7d32', 'core' => '#1565c0', 'overdue' => '#c62828', 'once' => '#6a1b9a', 'used' => '#ef6c00', 'other' => '#757575'];
    $genres = collect($rows)->pluck('genre')->unique()->values();
    $q = ['location_id' => $locationId, 'format' => $format];
@endphp
<style>
    .ro-bar { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
    .ro-bar .form-group { margin:0; }
    .ro-steps { margin:0; padding-left:18px; }
    .ro-steps li { margin-bottom:3px; }
    .ro-chips { display:flex; flex-wrap:wrap; gap:6px; margin:10px 0; }
    .ro-chip { border:1px solid #ccc; background:#fff; border-radius:14px; padding:3px 11px; cursor:pointer; font-size:13px; }
    .ro-chip.active { background:#333; color:#fff; border-color:#333; }
    .ro-tag { display:inline-block; color:#fff; border-radius:10px; padding:1px 8px; font-size:12px; margin:1px 2px 1px 0; white-space:nowrap; }
    table.ro-table { font-size:14px; }
    table.ro-table th { position:sticky; top:0; background:#f4f4f4; z-index:1; white-space:nowrap; cursor:pointer; user-select:none; }
    table.ro-table th:hover { background:#e8e8e8; }
    table.ro-table th .ro-arrow { color:#999; font-size:11px; }
    table.ro-table td { vertical-align:middle !important; padding:7px 8px !important; }
    table.ro-table td.n { text-align:center; }
    table.ro-table .ro-artist { font-weight:bold; }
    table.ro-table .ro-title { color:#333; }
    .ro-grade { display:inline-block; border:1px solid #ccc; border-radius:3px; padding:0 4px; font-size:10px; color:#666; margin-right:4px; }
    .ro-settings td { padding:6px 4px !important; vertical-align:middle !important; }
    .ro-settings .ro-num { display:inline-block; }
    .ro-tool h4 { font-size:15px; margin:14px 0 6px; }
    tr.ro-genre td { background:#eef3f8; font-weight:bold; font-size:14px; }
    .ro-num { width:58px; text-align:right; padding:2px 4px; }
    .ro-order { font-weight:bold; }
    tr.ro-zero .ro-order { color:#999; font-weight:normal; }
    tr.ro-cant { opacity:.6; }
    .ro-muted { color:#888; font-size:11px; }
    .ro-saved { color:#2e7d32; font-size:11px; }
    .ro-list textarea { font-family:monospace; width:100%; height:110px; }
    #ro-lists { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:12px; }
    .ro-list { margin-bottom:0; }
    .ro-list h4 { margin:0 0 4px; font-size:14px; display:flex; justify-content:space-between; align-items:center; }
</style>

<section class="content-header">
    <h1>Weekly Reorder <small>{{ $storeName }}, sealed {{ ['cd' => 'CDs', 'cassette' => 'cassettes'][$format] ?? 'vinyl' }}</small></h1>
</section>

<section class="content">
    @if(session('status'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">{{ session('status.msg') }}</div>
    @endif

    <div class="box box-solid">
        <div class="box-body">
            <form method="GET" action="{{ action('ReorderController@index') }}" class="ro-bar">
                <div class="form-group">
                    <label>Store</label>
                    <select name="location_id" class="form-control" onchange="this.form.submit()">
                        @foreach($locations as $id => $name)
                            <option value="{{ $id }}" @if($id == $locationId) selected @endif>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>Format</label>
                    <select name="format" class="form-control" onchange="this.form.submit()">
                        <option value="vinyl" @if($format === 'vinyl') selected @endif>Sealed vinyl</option>
                        <option value="cd" @if($format === 'cd') selected @endif>Sealed CDs</option>
                        <option value="cassette" @if($format === 'cassette') selected @endif>Sealed cassettes</option>
                        <option value="other">Everything else (apparel, toys, books...)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Sold since</label>
                    <input type="date" name="since" class="form-control" value="{{ $sinceParam ?: $since->toDateString() }}" onchange="this.form.submit()">
                </div>
                <div class="form-group">
                    <a class="btn btn-default" href="{{ action('ReorderController@csv', array_merge($q, ['since' => $sinceParam])) }}"><i class="fa fa-download"></i> Download spreadsheet</a>
                </div>
            </form>
            <p style="margin:10px 0 0;">
                Counting sales since <b>{{ $since->format('D M j, g:ia') }}</b> <span class="ro-muted">({{ $since_source }})</span>.
                <span id="ro-summary"></span>
            </p>
            <p class="ro-muted" style="margin:4px 0 0;">Categories: {{ implode(', ', $categories ?? []) ?: 'none found' }}</p>
            @if(!$abc_loaded)
                <p class="text-warning" style="margin:6px 0 0;">No ABC grades for this store yet, so every title is treated as a B.</p>
            @endif
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="box box-solid">
                <div class="box-header with-border"><h3 class="box-title">Each week</h3></div>
                <div class="box-body">
                    <ol class="ro-steps">
                        <li>Pick the store and format above. The list starts from what sold since the last order.</li>
                        <li>Walk the bins in genre order. Type what's actually there in <b>Counted</b>. It saves as you go and <b>Order</b> updates.</li>
                        <li>Also count the <b>Always stock</b> and <b>Missing?</b> titles. They should always be in the bin, even if nothing sold.</li>
                        <li>At the bottom, each distributor gets its own list with the titles it's cheapest on. Copy each list into that distributor's order.</li>
                        <li>Click <b>Mark as ordered</b>. Next week starts from here, and these copies show as on order.</li>
                    </ol>
                    <p class="ro-muted" style="margin:8px 0 0;"><b>Where the numbers come from:</b> sales are live from the register. Distributor prices refresh every night at 11pm. ABC grades update on the 1st of each month. Stock is your bin count, or the ERP count if there isn't one. To fix a price or title, edit the product in the ERP.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-body">
            <div class="ro-chips" id="ro-chips">
                <span class="ro-chip active" data-f="order">To order</span>
                @foreach($whyLabels as $k => $label)
                    <span class="ro-chip" data-f="{{ $k }}">{{ $label }} <span class="ro-muted" data-count="{{ $k }}"></span></span>
                @endforeach
                <span class="ro-chip" data-f="all">Everything</span>
            </div>
            <div class="ro-bar" style="margin-bottom:8px;">
                <select id="ro-genre" class="form-control" style="width:auto;">
                    <option value="">All genres</option>
                    @foreach($genres as $g)<option>{{ $g }}</option>@endforeach
                </select>
                <input id="ro-search" class="form-control" style="width:240px;" placeholder="Search artist or title">
                <a href="#" id="ro-unsort" style="display:none; align-self:center;">Back to bin order (by genre)</a>
                <span class="ro-muted" style="align-self:center;">Click any column name to sort.</span>
            </div>
            <div class="table-responsive" style="max-height:75vh; overflow:auto;">
                <table class="table table-condensed table-bordered ro-table" id="ro-table">
                    <thead>
                        <tr>
                            <th data-sort="album">Album <span class="ro-arrow"></span></th>
                            <th data-sort="why">Why <span class="ro-arrow"></span></th>
                            <th data-sort="since" title="Copies sold since the last order">Sold since last order <span class="ro-arrow"></span></th>
                            <th data-sort="ytd">Sold this year <span class="ro-arrow"></span></th>
                            <th data-sort="days" title="Average days from when we bought it to when it sold">Days to sell <span class="ro-arrow"></span></th>
                            <th data-sort="last">Last sold <span class="ro-arrow"></span></th>
                            <th data-sort="erp" title="What the ERP thinks is in the store">ERP says <span class="ro-arrow"></span></th>
                            <th data-sort="count" title="Type what's actually in the bin">Counted <span class="ro-arrow"></span></th>
                            <th data-sort="onorder" title="Already ordered, not here yet">On the way <span class="ro-arrow"></span></th>
                            <th data-sort="order">Order <span class="ro-arrow"></span></th>
                            <th data-sort="cost">Cheapest <span class="ro-arrow"></span></th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($rows as $i => $r)
                        <tr class="ro-row @if($r['cant_order']) ro-cant @endif"
                            data-i="{{ $i }}"
                            data-pid="{{ $r['product_id'] }}"
                            data-pids="{{ implode(',', $r['product_ids'] ?? []) }}"
                            data-genre="{{ $r['genre'] }}"
                            data-why="{{ implode(' ', $r['why']) }}"
                            data-text="{{ mb_strtolower($r['artist'] . ' ' . $r['title']) }}"
                            data-album="{{ mb_strtolower(($r['artist'] ?: '') . ' ' . $r['title']) }}"
                            data-ytd="{{ $r['sold_ytd'] }}"
                            data-days="{{ $r['avg_days_to_sell'] ?? '' }}"
                            data-last="{{ $r['last_sold'] ?? '' }}"
                            data-upc="{{ $r['supplier_upc'] }}"
                            data-supplier="{{ $r['best_supplier'] ?: 'No price found' }}"
                            data-title="{{ $r['title'] }}"
                            data-suggested="{{ $r['suggested'] }}"
                            data-onorder="{{ $r['on_order'] }}"
                            data-erp="{{ $r['erp_stock'] }}"
                            data-since="{{ $r['sold_since'] }}"
                            data-cant="{{ $r['cant_order'] ? 1 : 0 }}"
                            data-cost="{{ $r['best_cost'] ?? $r['ams_cost'] ?? $r['erp_cost'] ?? 0 }}">
                            <td title="{{ !empty($r['merged']) ? 'Combines ' . count($r['merged']) . ' ERP entries: ' . implode(' | ', $r['merged']) : '' }}">
                                @if($r['artist'])<div class="ro-artist">{{ $r['artist'] }}</div>@endif
                                <div class="ro-title">{{ $r['title'] }}</div>
                                <div class="ro-muted">@if($r['grade'])<span class="ro-grade" title="Sales grade: A best seller to C slow, X steady to Z rare">{{ $r['grade'] }}</span>@endif{{ $r['supplier_upc'] ?: $r['sku'] }}
                                    @if($r['cant_order']) <span class="text-danger">Can't order: {{ $r['cant_order'] }}</span>@endif
                                    @if($r['used_note']) <div>{{ $r['used_note'] }}</div>@endif
                                </div>
                            </td>
                            <td>@foreach($r['why'] as $w)<span class="ro-tag" style="background:{{ $whyColors[$w] ?? '#777' }}" title="{{ $whyLabels[$w] ?? $w }}">{{ $whyShort[$w] ?? $w }}</span> @endforeach</td>
                            <td class="n">{{ $r['sold_since'] ?: '' }}</td>
                            <td class="n">{{ $r['sold_ytd'] ?: '' }}</td>
                            <td class="n">{{ $r['avg_days_to_sell'] !== null ? $r['avg_days_to_sell'] : '' }}</td>
                            <td style="white-space:nowrap;">{{ $r['last_sold'] ? \Carbon\Carbon::parse($r['last_sold'])->format('M j') : '' }}
                                @if(in_array('overdue', $r['why']))<div class="ro-muted text-danger">{{ $r['days_since_sale'] }} days ago</div>@endif</td>
                            <td class="n">{{ $r['erp_stock'] }}</td>
                            <td>
                                @if($r['product_id'])
                                    <input type="number" min="0" class="form-control ro-num ro-count" value="{{ $r['count'] !== null ? $r['count'] : '' }}"
                                        title="{{ $r['count_at'] ? 'Counted ' . \Carbon\Carbon::parse($r['count_at'])->format('M j') . ($r['count_raw'] != $r['count'] ? ' as ' . $r['count_raw'] . ', minus sales since' : '') : '' }}">
                                    <span class="ro-saved"></span>
                                @endif
                            </td>
                            <td class="n">{{ $r['on_order'] ?: '' }}</td>
                            <td title="Should have {{ $r['suggested'] }}"><input type="number" min="0" class="form-control ro-num ro-order" value="{{ $r['order_qty'] }}"></td>
                            <td style="white-space:nowrap;">@if($r['best_cost'])${{ number_format($r['best_cost'], 2) }}<div class="ro-muted">{{ $r['best_supplier'] }}</div>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

            @if($budget)
            <div class="box box-solid">
                <div class="box-body" style="font-size:15px;">
                    <b>Budget for new stock, {{ $budget['store'] }}, this week</b>
                    @if($budget['start'])<span class="ro-muted">({{ \Carbon\Carbon::parse($budget['start'])->format('M j') }} to {{ \Carbon\Carbon::parse($budget['end'])->format('M j') }})</span>@endif:
                    ${{ number_format($budget['new']['spent']) }} spent of ${{ number_format($budget['new']['budget']) }},
                    <b style="color:{{ $budget['new']['remaining'] < 0 ? '#c62828' : '#2e7d32' }}">${{ number_format($budget['new']['remaining']) }} left</b>.
                    This order: <b id="ro-order-cost">$0</b>, which leaves <b id="ro-after">${{ number_format($budget['new']['remaining']) }}</b>.
                </div>
            </div>
            @endif

            <div class="box box-solid">
                <div class="box-header with-border"><h3 class="box-title">Order lists <small>each title goes to the cheapest distributor that has it</small></h3></div>
                <div class="box-body">
                    <div id="ro-lists"></div>
                    <div style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap;">
                        <button type="button" class="btn btn-success btn-sm" id="ro-mark"><i class="fa fa-check"></i> Mark as ordered</button>
                        <span class="ro-muted" id="ro-paste-note" style="align-self:center;"></span>
                    </div>
                </div>
            </div>

    <div class="box box-solid collapsed-box ro-tool">
        <div class="box-header with-border">
            <h3 class="box-title">Settings and past orders <small>you rarely need these</small></h3>
            <div class="box-tools pull-right"><button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-plus"></i></button></div>
        </div>
        <div class="box-body">
            <div class="row">
                <div class="col-md-6">
                    <h4>Past orders</h4>
                    @forelse($orders as $o)
                        <div style="margin-bottom:6px;">
                            <b>{{ \Carbon\Carbon::parse($o['at'])->format('D M j, g:ia') }}</b>:
                            {{ count($o['lines']) }} titles, {{ array_sum(array_column($o['lines'], 'qty')) }} copies
                            @if($o['by']) by {{ $o['by'] }}@endif
                            <form method="POST" action="{{ action('ReorderController@deleteOrder', array_merge(['id' => $o['id']], $q)) }}" style="display:inline;">
                                @csrf
                                <button class="btn btn-link btn-xs text-danger" onclick="if (this.dataset.armed) return true; this.dataset.armed = 1; this.textContent = 'click again to undo'; return false;">undo</button>
                            </form>
                        </div>
                    @empty
                        <p class="text-muted">None yet. Once you click Mark as ordered, it shows here.</p>
                    @endforelse

                    <h4>Already counted in a spreadsheet?</h4>
                    <form method="POST" action="{{ action('ReorderController@importCounts', $q) }}" enctype="multipart/form-data">
                        @csrf
                        <p class="ro-muted">Upload a CSV with two columns named <b>upc</b> and <b>qty</b>. The counts fill in the Counted column for {{ $storeName }}.</p>
                        <div class="ro-bar">
                            <input type="file" name="file" accept=".csv" class="form-control" style="width:auto;">
                            <label style="margin:0;">Day you counted <input type="date" name="counted_on" class="form-control" value="{{ now()->toDateString() }}"></label>
                            <button class="btn btn-default">Upload</button>
                        </div>
                    </form>
                </div>
                <div class="col-md-6">
                    <h4>How much to order</h4>
                    <p class="ro-muted">Order = how many we should have, minus what's counted in the bin, minus what's on the way.</p>
                    <form method="POST" action="{{ action('ReorderController@saveSettings', $q) }}">
                        @csrf
                        <table class="table ro-settings">
                            <tr><td>Months of stock to keep</td><td>
                                vinyl <input name="cover_months_vinyl" class="form-control ro-num" value="{{ $settings['cover_months_vinyl'] }}">
                                CDs <input name="cover_months_cd" class="form-control ro-num" value="{{ $settings['cover_months_cd'] }}">
                                cassettes <input name="cover_months_cassette" class="form-control ro-num" value="{{ $settings['cover_months_cassette'] }}"></td></tr>
                            <tr><td>Extra for best sellers, less for slow ones<div class="ro-muted">1 = normal, 1.25 = 25% more</div></td><td>
                                best <input name="abc_A" class="form-control ro-num" value="{{ $settings['abc_factor']['A'] }}">
                                middle <input name="abc_B" class="form-control ro-num" value="{{ $settings['abc_factor']['B'] }}">
                                slow <input name="abc_C" class="form-control ro-num" value="{{ $settings['abc_factor']['C'] }}"></td></tr>
                            <tr><td>Less for titles that sell on and off</td><td>
                                steady <input name="xyz_X" class="form-control ro-num" value="{{ $settings['xyz_factor']['X'] }}">
                                up and down <input name="xyz_Y" class="form-control ro-num" value="{{ $settings['xyz_factor']['Y'] }}">
                                rare <input name="xyz_Z" class="form-control ro-num" value="{{ $settings['xyz_factor']['Z'] }}"></td></tr>
                            <tr><td>Reorder a title we bought once if it sold within</td><td><input name="bought_once_days" class="form-control ro-num" value="{{ $settings['bought_once_days'] }}"> days</td></tr>
                            <tr><td>Suggest buying new when a used copy sold within</td><td><input name="used_fast_days" class="form-control ro-num" value="{{ $settings['used_fast_days'] }}"> days, or for $<input name="used_min_price" class="form-control ro-num" value="{{ $settings['used_min_price'] }}"> or more</td></tr>
                            <tr><td>Never order more than</td><td><input name="max_line_qty" class="form-control ro-num" value="{{ $settings['max_line_qty'] }}"> copies of one title</td></tr>
                            <tr><td>Ignore bin counts older than</td><td><input name="count_fresh_days" class="form-control ro-num" value="{{ $settings['count_fresh_days'] }}"> days</td></tr>
                            <tr><td>If a title hasn't been counted</td><td>
                                <select name="blank_count" class="form-control">
                                    <option value="sold" @if($settings['blank_count'] === 'sold') selected @endif>Order back what sold</option>
                                    <option value="erp" @if($settings['blank_count'] === 'erp') selected @endif>Trust the ERP count</option>
                                    <option value="zero" @if($settings['blank_count'] === 'zero') selected @endif>Assume the bin is empty</option>
                                </select></td></tr>
                        </table>
                        <button class="btn btn-default">Save</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>
@endsection

@section('javascript')
<script>
(function () {
    var CSRF = "{{ csrf_token() }}";
    var BLANK = @json($settings['blank_count']);
    var BUDGET_LEFT = {{ $budget ? (float) $budget['new']['remaining'] : 0 }};
    var Q = @json($q);
    var filter = 'order';
    var sortKey = 'genre', sortDir = 1;
    var tbody = document.querySelector('#ro-table tbody');
    var rows = Array.prototype.slice.call(document.querySelectorAll('#ro-table tr.ro-row'));

    function num(v) { var n = parseInt(v, 10); return isNaN(n) ? null : n; }

    // Same rule as ReorderService::orderQty, for a count typed in just now.
    function calc(tr) {
        var d = tr.dataset, sug = +d.suggested, onord = +d.onorder;
        var countEl = tr.querySelector('.ro-count');
        var c = countEl ? num(countEl.value) : null;
        if (+d.cant) return 0;
        if (c !== null) return Math.max(0, sug - c - onord);
        if (BLANK === 'erp') return Math.max(0, sug - Math.max(0, +d.erp) - onord);
        if (BLANK === 'zero') return Math.max(0, sug - onord);
        var once = /\b(once|used)\b/.test(d.why);
        var base = once ? Math.max(1, +d.since) : +d.since;
        if (base === 0 && +d.erp <= 0 && +d.onorder === 0 && (/\bcore\b/.test(d.why) || +d.ytd >= 2)) base = Math.max(1, sug);
        return Math.max(0, Math.min(base, Math.max(sug, 1)));
    }

    function orderOf(tr) { return num(tr.querySelector('.ro-order').value) || 0; }

    function sortVal(tr) {
        var d = tr.dataset, v;
        switch (sortKey) {
            case 'album': return d.album || '';
            case 'why': return d.why;
            case 'last': return d.last || null;
            case 'count': var c = tr.querySelector('.ro-count'); return c && c.value !== '' ? +c.value : null;
            case 'order': return orderOf(tr);
            case 'onorder': return +d.onorder;
            case 'cost': return +d.cost || null;
            default: v = d[sortKey]; return v === '' || v === undefined ? null : +v;
        }
    }

    document.querySelectorAll('#ro-table th[data-sort]').forEach(function (th) {
        th.addEventListener('click', function () {
            var k = th.dataset.sort;
            // Numbers start biggest-first, names A to Z.
            sortDir = sortKey === k ? -sortDir : (k === 'album' || k === 'why' ? 1 : -1);
            sortKey = k;
            document.querySelectorAll('#ro-table .ro-arrow').forEach(function (a) { a.textContent = ''; });
            th.querySelector('.ro-arrow').textContent = sortDir === 1 ? '\u25B2' : '\u25BC';
            document.getElementById('ro-unsort').style.display = '';
            render();
        });
    });
    document.getElementById('ro-unsort').addEventListener('click', function (e) {
        e.preventDefault();
        sortKey = 'genre';
        document.querySelectorAll('#ro-table .ro-arrow').forEach(function (a) { a.textContent = ''; });
        this.style.display = 'none';
        render();
    });

    function render() {
        var genre = document.getElementById('ro-genre').value;
        var text = document.getElementById('ro-search').value.toLowerCase().trim();
        var counts = {}, lastGenre = null, lists = {}, noUpc = 0, titles = 0, copies = 0, cost = 0;
        document.querySelectorAll('tr.ro-genre').forEach(function (g) { g.remove(); });
        var ordered = rows.slice();
        if (sortKey !== 'genre') {
            ordered.sort(function (a, b) {
                var x = sortVal(a), y = sortVal(b);
                if (x === y) return (+a.dataset.i) - (+b.dataset.i);
                if (x === null) return 1;
                if (y === null) return -1;
                return (x < y ? -1 : 1) * sortDir;
            });
        }
        ordered.forEach(function (tr) { tbody.appendChild(tr); });
        ordered.forEach(function (tr) {
            var d = tr.dataset, q = orderOf(tr);
            tr.classList.toggle('ro-zero', q === 0);
            d.why.split(' ').forEach(function (w) { counts[w] = (counts[w] || 0) + 1; });
            if (q > 0) {
                titles++; copies += q; cost += q * (+d.cost || 0);
                if (d.upc) {
                    var L = lists[d.supplier] = lists[d.supplier] || { lines: [], copies: 0, cost: 0 };
                    L.lines.push(d.upc + ' ' + q);
                    L.copies += q;
                    L.cost += q * (+d.cost || 0);
                } else noUpc++;
            }
            var show = (filter === 'all' || (filter === 'order' ? q > 0 : (' ' + d.why + ' ').indexOf(' ' + filter + ' ') >= 0))
                && (!genre || d.genre === genre)
                && (!text || d.text.indexOf(text) >= 0);
            tr.style.display = show ? '' : 'none';
            if (show && sortKey === 'genre' && d.genre !== lastGenre) {
                var g = document.createElement('tr');
                g.className = 'ro-genre';
                g.innerHTML = '<td colspan="11"></td>';
                g.firstChild.textContent = d.genre;
                tr.parentNode.insertBefore(g, tr);
                lastGenre = d.genre;
            }
        });
        Object.keys(counts).forEach(function (k) {
            var el = document.querySelector('[data-count="' + k + '"]');
            if (el) el.textContent = counts[k];
        });
        var box = document.getElementById('ro-lists');
        box.innerHTML = '';
        Object.keys(lists).sort(function (a, b) {
            return (a === 'No price found') - (b === 'No price found') || lists[b].cost - lists[a].cost;
        }).forEach(function (name) {
            var L = lists[name], div = document.createElement('div');
            div.className = 'ro-list';
            div.innerHTML = '<h4><span></span><button type="button" class="btn btn-primary btn-xs">Copy</button></h4><textarea readonly></textarea>';
            div.querySelector('span').textContent = name + ': ' + L.lines.length + ' titles, ' + L.copies + ' copies' +
                (name === 'No price found' ? ' (look these up)' : ', about $' + Math.round(L.cost).toLocaleString());
            var ta = div.querySelector('textarea');
            ta.value = L.lines.join('\n');
            div.querySelector('button').addEventListener('click', function () {
                ta.select();
                try { document.execCommand('copy'); } catch (err) {}
                if (navigator.clipboard) navigator.clipboard.writeText(ta.value);
                this.textContent = 'Copied';
            });
            box.appendChild(div);
        });
        if (!Object.keys(lists).length) box.innerHTML = '<p class="ro-muted">Nothing to order yet.</p>';
        document.getElementById('ro-paste-note').textContent = noUpc ? noUpc + ' titles to order have no barcode, order them by hand.' : '';
        var oc = document.getElementById('ro-order-cost');
        if (oc) {
            var left = BUDGET_LEFT - cost;
            oc.textContent = '$' + Math.round(cost).toLocaleString();
            var af = document.getElementById('ro-after');
            af.textContent = (left < 0 ? '-$' : '$') + Math.abs(Math.round(left)).toLocaleString();
            af.style.color = left < 0 ? '#c62828' : '#2e7d32';
        }
        document.getElementById('ro-summary').textContent = 'To order: ' + titles + ' titles, ' + copies + ' copies, about $' +
            Math.round(cost).toLocaleString() + ' at the best price.';
    }

    document.getElementById('ro-chips').addEventListener('click', function (e) {
        var chip = e.target.closest('.ro-chip');
        if (!chip) return;
        document.querySelectorAll('.ro-chip').forEach(function (c) { c.classList.remove('active'); });
        chip.classList.add('active');
        filter = chip.dataset.f;
        render();
    });
    document.getElementById('ro-genre').addEventListener('change', render);
    document.getElementById('ro-search').addEventListener('input', render);

    document.getElementById('ro-table').addEventListener('change', function (e) {
        var tr = e.target.closest('tr.ro-row');
        if (!tr) return;
        if (e.target.classList.contains('ro-count')) {
            tr.querySelector('.ro-order').value = calc(tr);
            var saved = tr.querySelector('.ro-saved');
            saved.textContent = '...';
            $.post('/reports/reorder/count', $.extend({ _token: CSRF, product_id: tr.dataset.pid, product_ids: tr.dataset.pids, qty: e.target.value }, Q))
                .done(function () { saved.textContent = 'saved'; })
                .fail(function () { saved.textContent = 'not saved'; saved.style.color = '#c62828'; });
        }
        render();
    });

    document.getElementById('ro-mark').addEventListener('click', function () {
        var lines = [];
        rows.forEach(function (tr) {
            var q = orderOf(tr);
            if (q > 0) lines.push({ product_id: tr.dataset.pid, upc: tr.dataset.upc, title: tr.dataset.title, qty: q, supplier: tr.dataset.supplier });
        });
        if (!lines.length) { toastr.warning('Nothing to order.'); return; }
        var btn = this;
        if (!btn.dataset.armed) {
            btn.dataset.armed = 1;
            btn.innerHTML = 'Click again: mark ' + lines.length + ' titles ordered';
            return;
        }
        btn.disabled = true;
        $.post('/reports/reorder/ordered', $.extend({ _token: CSRF, lines: lines }, Q))
            .done(function () { window.location.reload(); })
            .fail(function () { btn.disabled = false; toastr.error('Could not save the order.'); });
    });

    render();
})();
</script>
@endsection
