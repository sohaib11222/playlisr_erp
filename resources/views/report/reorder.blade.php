@extends('layouts.app')
@section('title', 'Weekly Reorder')

@section('content')
@php
    $whyLabels = app(\App\Http\Controllers\ReorderController::class)->whyLabels();
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
    .ro-tag { display:inline-block; color:#fff; border-radius:3px; padding:0 5px; font-size:11px; margin:1px 2px 1px 0; white-space:nowrap; }
    table.ro-table { font-size:13px; }
    table.ro-table th { position:sticky; top:0; background:#f4f4f4; z-index:1; white-space:nowrap; }
    table.ro-table td { vertical-align:middle !important; }
    tr.ro-genre td { background:#eef3f8; font-weight:bold; font-size:14px; }
    .ro-num { width:58px; text-align:right; padding:2px 4px; }
    .ro-order { font-weight:bold; }
    tr.ro-zero .ro-order { color:#999; font-weight:normal; }
    tr.ro-cant { opacity:.6; }
    .ro-muted { color:#888; font-size:11px; }
    .ro-saved { color:#2e7d32; font-size:11px; }
    #ro-paste { font-family:monospace; width:100%; height:160px; }
</style>

<section class="content-header">
    <h1>Weekly Reorder <small>{{ $storeName }}, sealed {{ $format === 'cd' ? 'CDs' : 'vinyl' }}</small></h1>
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
            @if(!$abc_loaded)
                <p class="text-warning" style="margin:6px 0 0;">No ABC grades for this store yet, so every title is treated as a B.</p>
            @endif
        </div>
    </div>

    <div class="row">
        <div class="col-md-7">
            <div class="box box-solid">
                <div class="box-header with-border"><h3 class="box-title">Each week</h3></div>
                <div class="box-body">
                    <ol class="ro-steps">
                        <li>Pick the store and format above. The list starts from what sold since the last order.</li>
                        <li>Walk the bins in genre order. Type what's in the bin in <b>In bin</b>. It saves as you go and the order qty updates.</li>
                        <li>Check the <b>Core: check bins</b> and <b>Overdue</b> titles too. They should always be there, so count them even if nothing sold.</li>
                        <li>Copy the AMS list below into AMS Quick Order (Cut &amp; Paste). Don't check out. Jon reviews the cart and submits it.</li>
                        <li>Click <b>Mark as ordered</b>. Next week starts from here, and these copies show as on order.</li>
                    </ol>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="box box-solid">
                <div class="box-header with-border"><h3 class="box-title">AMS paste list <small>UPC and qty</small></h3></div>
                <div class="box-body">
                    <textarea id="ro-paste" readonly></textarea>
                    <div style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap;">
                        <button type="button" class="btn btn-primary btn-sm" id="ro-copy"><i class="fa fa-copy"></i> Copy</button>
                        <button type="button" class="btn btn-success btn-sm" id="ro-mark"><i class="fa fa-check"></i> Mark as ordered</button>
                        <span class="ro-muted" id="ro-paste-note" style="align-self:center;"></span>
                    </div>
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
            </div>
            <div class="table-responsive" style="max-height:75vh; overflow:auto;">
                <table class="table table-condensed table-bordered ro-table" id="ro-table">
                    <thead>
                        <tr>
                            <th>Artist / Title</th>
                            <th>Grade</th>
                            <th>Why</th>
                            <th title="Sold since the last order">Since</th>
                            <th>10 days</th>
                            <th>This year</th>
                            <th title="Purchase date to sale date">Days to sell</th>
                            <th>Last sold</th>
                            <th>ERP stock</th>
                            <th>In bin</th>
                            <th>On order</th>
                            <th>Target</th>
                            <th>Order</th>
                            <th>Best price</th>
                            <th>AMS</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($rows as $i => $r)
                        <tr class="ro-row @if($r['cant_order']) ro-cant @endif"
                            data-i="{{ $i }}"
                            data-pid="{{ $r['product_id'] }}"
                            data-genre="{{ $r['genre'] }}"
                            data-why="{{ implode(' ', $r['why']) }}"
                            data-text="{{ mb_strtolower($r['artist'] . ' ' . $r['title']) }}"
                            data-upc="{{ $r['supplier_upc'] }}"
                            data-title="{{ $r['title'] }}"
                            data-suggested="{{ $r['suggested'] }}"
                            data-onorder="{{ $r['on_order'] }}"
                            data-erp="{{ $r['erp_stock'] }}"
                            data-since="{{ $r['sold_since'] }}"
                            data-cant="{{ $r['cant_order'] ? 1 : 0 }}"
                            data-cost="{{ $r['best_cost'] ?? $r['ams_cost'] ?? $r['erp_cost'] ?? 0 }}"
                            data-countsold="{{ $r['count'] !== null && $r['count_raw'] !== null ? $r['count_raw'] - $r['count'] : 0 }}">
                            <td>
                                <b>{{ $r['artist'] }}</b> {{ $r['artist'] ? '/' : '' }} {{ $r['title'] }}
                                <div class="ro-muted">{{ $r['supplier_upc'] ?: $r['sku'] }}
                                    @if($r['cant_order']) <span class="text-danger">Can't order: {{ $r['cant_order'] }}</span>@endif
                                    @if($r['used_note']) <span>{{ $r['used_note'] }}</span>@endif
                                </div>
                            </td>
                            <td>{{ $r['grade'] }}</td>
                            <td>@foreach($r['why'] as $w)<span class="ro-tag" style="background:{{ $whyColors[$w] ?? '#777' }}">{{ $whyLabels[$w] ?? $w }}</span>@endforeach</td>
                            <td class="text-right">{{ $r['sold_since'] ?: '' }}</td>
                            <td class="text-right">{{ $r['sold_10d'] ?: '' }}</td>
                            <td class="text-right">{{ $r['sold_ytd'] ?: '' }}</td>
                            <td class="text-right">{{ $r['avg_days_to_sell'] !== null ? $r['avg_days_to_sell'] : '' }}</td>
                            <td style="white-space:nowrap;">{{ $r['last_sold'] ? \Carbon\Carbon::parse($r['last_sold'])->format('M j') : '' }}
                                @if(in_array('overdue', $r['why']))<div class="ro-muted text-danger">{{ $r['days_since_sale'] }} days ago</div>@endif</td>
                            <td class="text-right">{{ $r['erp_stock'] }}</td>
                            <td>
                                @if($r['product_id'])
                                    <input type="number" min="0" class="form-control ro-num ro-count" value="{{ $r['count'] !== null ? $r['count'] : '' }}"
                                        title="{{ $r['count_at'] ? 'Counted ' . \Carbon\Carbon::parse($r['count_at'])->format('M j') . ($r['count_raw'] != $r['count'] ? ' as ' . $r['count_raw'] . ', minus sales since' : '') : '' }}">
                                    <span class="ro-saved"></span>
                                @endif
                            </td>
                            <td class="text-right">{{ $r['on_order'] ?: '' }}</td>
                            <td class="text-right">{{ $r['suggested'] }}</td>
                            <td><input type="number" min="0" class="form-control ro-num ro-order" value="{{ $r['order_qty'] }}"></td>
                            <td style="white-space:nowrap;">@if($r['best_cost'])${{ number_format($r['best_cost'], 2) }} <span class="ro-muted">{{ $r['best_supplier'] }}</span>@endif</td>
                            <td>@if($r['ams_cost'])${{ number_format($r['ams_cost'], 2) }}@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="box box-solid collapsed-box">
                <div class="box-header with-border">
                    <h3 class="box-title">Orders marked placed</h3>
                    <div class="box-tools pull-right"><button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-plus"></i></button></div>
                </div>
                <div class="box-body">
                    @forelse($orders as $o)
                        <div style="margin-bottom:6px;">
                            <b>{{ \Carbon\Carbon::parse($o['at'])->format('D M j, g:ia') }}</b>
                            {{ count($o['lines']) }} titles, {{ array_sum(array_column($o['lines'], 'qty')) }} copies
                            @if($o['by']) by {{ $o['by'] }}@endif
                            <form method="POST" action="{{ action('ReorderController@deleteOrder', array_merge(['id' => $o['id']], $q)) }}" style="display:inline;">
                                @csrf
                                <button class="btn btn-link btn-xs text-danger" onclick="if (this.dataset.armed) return true; this.dataset.armed = 1; this.textContent = 'click again to remove'; return false;">remove</button>
                            </form>
                        </div>
                    @empty
                        <p class="text-muted">None yet. Until the first one, sales count from the last distributor purchase in the ERP.</p>
                    @endforelse
                </div>
            </div>
            <div class="box box-solid collapsed-box">
                <div class="box-header with-border">
                    <h3 class="box-title">Load bin counts from a spreadsheet</h3>
                    <div class="box-tools pull-right"><button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-plus"></i></button></div>
                </div>
                <div class="box-body">
                    <form method="POST" action="{{ action('ReorderController@importCounts', $q) }}" enctype="multipart/form-data">
                        @csrf
                        <p class="ro-muted">A CSV with a "upc" column and a "qty" column, for {{ $storeName }}. Sales after the count date are taken out automatically.</p>
                        <div class="ro-bar">
                            <input type="file" name="file" accept=".csv" class="form-control" style="width:auto;">
                            <label style="margin:0;">Counted on <input type="date" name="counted_on" class="form-control" value="{{ now()->toDateString() }}"></label>
                            <button class="btn btn-default">Load counts</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="box box-solid collapsed-box">
                <div class="box-header with-border">
                    <h3 class="box-title">Settings</h3>
                    <div class="box-tools pull-right"><button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-plus"></i></button></div>
                </div>
                <div class="box-body">
                    <form method="POST" action="{{ action('ReorderController@saveSettings', $q) }}">
                        @csrf
                        <p class="ro-muted">Target = monthly pace (40% last 10 days, 60% this year's average) x months of cover x ABC factor x XYZ factor. Order = Target minus In bin minus On order.</p>
                        <table class="table table-condensed">
                            <tr><td>Months of cover, vinyl</td><td><input name="cover_months_vinyl" class="form-control ro-num" value="{{ $settings['cover_months_vinyl'] }}"></td></tr>
                            <tr><td>Months of cover, CDs</td><td><input name="cover_months_cd" class="form-control ro-num" value="{{ $settings['cover_months_cd'] }}"></td></tr>
                            <tr><td>ABC factor A / B / C</td><td style="display:flex;gap:4px;">
                                @foreach(['A','B','C'] as $k)<input name="abc_{{ $k }}" class="form-control ro-num" value="{{ $settings['abc_factor'][$k] }}">@endforeach</td></tr>
                            <tr><td>XYZ factor X / Y / Z</td><td style="display:flex;gap:4px;">
                                @foreach(['X','Y','Z'] as $k)<input name="xyz_{{ $k }}" class="form-control ro-num" value="{{ $settings['xyz_factor'][$k] }}">@endforeach</td></tr>
                            <tr><td>Bought once: reorder if it sold within (days)</td><td><input name="bought_once_days" class="form-control ro-num" value="{{ $settings['bought_once_days'] }}"></td></tr>
                            <tr><td>Used: buy sealed if it sold within (days of buying it)</td><td><input name="used_fast_days" class="form-control ro-num" value="{{ $settings['used_fast_days'] }}"></td></tr>
                            <tr><td>Used: or sold for at least ($)</td><td><input name="used_min_price" class="form-control ro-num" value="{{ $settings['used_min_price'] }}"></td></tr>
                            <tr><td>Most copies on one line</td><td><input name="max_line_qty" class="form-control ro-num" value="{{ $settings['max_line_qty'] }}"></td></tr>
                            <tr><td>Bin counts expire after (days)</td><td><input name="count_fresh_days" class="form-control ro-num" value="{{ $settings['count_fresh_days'] }}"></td></tr>
                            <tr><td>When a title has no bin count</td><td>
                                <select name="blank_count" class="form-control">
                                    <option value="sold" @if($settings['blank_count'] === 'sold') selected @endif>Order back what sold</option>
                                    <option value="erp" @if($settings['blank_count'] === 'erp') selected @endif>Use ERP stock</option>
                                    <option value="zero" @if($settings['blank_count'] === 'zero') selected @endif>Assume the bin is empty</option>
                                </select></td></tr>
                        </table>
                        <button class="btn btn-default">Save settings</button>
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
    var Q = @json($q);
    var filter = 'order';
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
        return Math.max(0, Math.min(base, Math.max(sug, 1)));
    }

    function orderOf(tr) { return num(tr.querySelector('.ro-order').value) || 0; }

    function render() {
        var genre = document.getElementById('ro-genre').value;
        var text = document.getElementById('ro-search').value.toLowerCase().trim();
        var counts = {}, lastGenre = null, paste = [], noUpc = 0, titles = 0, copies = 0, cost = 0;
        document.querySelectorAll('tr.ro-genre').forEach(function (g) { g.remove(); });
        rows.forEach(function (tr) {
            var d = tr.dataset, q = orderOf(tr);
            tr.classList.toggle('ro-zero', q === 0);
            d.why.split(' ').forEach(function (w) { counts[w] = (counts[w] || 0) + 1; });
            if (q > 0) {
                titles++; copies += q; cost += q * (+d.cost || 0);
                if (d.upc) paste.push(d.upc + ' ' + q); else noUpc++;
            }
            var show = (filter === 'all' || (filter === 'order' ? q > 0 : (' ' + d.why + ' ').indexOf(' ' + filter + ' ') >= 0))
                && (!genre || d.genre === genre)
                && (!text || d.text.indexOf(text) >= 0);
            tr.style.display = show ? '' : 'none';
            if (show && d.genre !== lastGenre) {
                var g = document.createElement('tr');
                g.className = 'ro-genre';
                g.innerHTML = '<td colspan="15"></td>';
                g.firstChild.textContent = d.genre;
                tr.parentNode.insertBefore(g, tr);
                lastGenre = d.genre;
            }
        });
        Object.keys(counts).forEach(function (k) {
            var el = document.querySelector('[data-count="' + k + '"]');
            if (el) el.textContent = counts[k];
        });
        document.getElementById('ro-paste').value = paste.join('\n');
        document.getElementById('ro-paste-note').textContent = noUpc ? noUpc + ' titles to order have no barcode, order them by hand.' : '';
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
            $.post('/reports/reorder/count', $.extend({ _token: CSRF, product_id: tr.dataset.pid, qty: e.target.value }, Q))
                .done(function () { saved.textContent = 'saved'; })
                .fail(function () { saved.textContent = 'not saved'; saved.style.color = '#c62828'; });
        }
        render();
    });

    document.getElementById('ro-copy').addEventListener('click', function () {
        var ta = document.getElementById('ro-paste');
        ta.select();
        try { document.execCommand('copy'); } catch (err) {}
        if (navigator.clipboard) navigator.clipboard.writeText(ta.value);
        this.innerHTML = '<i class="fa fa-check"></i> Copied';
    });

    document.getElementById('ro-mark').addEventListener('click', function () {
        var lines = [];
        rows.forEach(function (tr) {
            var q = orderOf(tr);
            if (q > 0) lines.push({ product_id: tr.dataset.pid, upc: tr.dataset.upc, title: tr.dataset.title, qty: q });
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
