@extends('layouts.app')
@section('title', 'Weekly Reorder')

@section('content')
<style>
    .ro-bar { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
    .ro-bar .form-group { margin:0; }
    .ro-muted { color:#888; font-size:12px; }
    table.rc td { vertical-align:middle !important; }
    .rc-num { width:60px; text-align:right; font-weight:bold; font-size:15px; padding:2px 4px; }
    .rc-order { font-weight:bold; font-size:15px; }
    .rc-saved { color:#2e7d32; font-size:11px; }
</style>

<section class="content-header">
    <h1>Weekly Reorder <small>{{ $storeName }}, comics &amp; magazines</small></h1>
</section>

<section class="content">
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
                        <option value="vinyl">Sealed vinyl</option>
                        <option value="cd">Sealed CDs</option>
                        <option value="cassette">Sealed cassettes</option>
                        <option value="comics" selected>Comics &amp; magazines</option>
                        <option value="other">Everything else (apparel, toys, books...)</option>
                    </select>
                </div>
            </form>
            <ol style="margin:12px 0 0; padding-left:22px; font-size:15px; line-height:1.55;">
                <li>Comics and magazines are grouped by series (Spider-Man, Batman, Playboy...). Speed (like Clarissa's sheet): Fast = sells within about 2 weeks of buying it, Staple = 2 to 7 weeks, Mid = slower.</li>
                <li>Count each series in the bins and type the total in <b>Qty in Stock</b>. It saves by itself.</li>
                <li><b># to Order</b> = enough for 4 weeks at the current pace, minus Qty in Stock (or minus what the ERP says if you haven't counted yet).</li>
            </ol>
        </div>
    </div>

    @php
        $publishers = [
            'MARVEL' => ['Spider-Man', 'X-Men', 'Wolverine', 'Hulk', 'Avengers & solo heroes', 'Deadpool', 'Daredevil', 'Punisher', 'Fantastic Four', 'Doom', 'Silver Surfer / What If', 'Ghost Rider', 'Werewolf by Night', 'Conan'],
            'DC' => ['Batman', 'Superman', 'Flash', 'Justice League / DC', 'Wonder Woman', 'Green Lantern', 'Sandman / Vertigo'],
            'IMAGE' => ['Spawn'],
            'INDIE / LICENSED' => ['Star Wars / Star Trek', 'Godzilla', 'Cartoon & kids', 'Predator / Aliens'],
            'MANGA / POKEMON' => ['Pokemon', 'Manga'],
            'UNIDENTIFIED' => ['Other comics'],
        ];
        $speedRank = ['Fast' => 0, 'Staple' => 1, 'Mid' => 2, 'No data' => 3];
        $sortFams = function ($rows) use ($speedRank) {
            usort($rows, function ($a, $b) use ($speedRank) {
                return [$speedRank[$a['speed']] ?? 9, $a['days_to_sell'] ?? 999] <=> [$speedRank[$b['speed']] ?? 9, $b['days_to_sell'] ?? 999];
            });
            return $rows;
        };
        $sections = ['comics' => [], 'magazines' => ['MAGAZINES' => $sortFams($data['magazines'])]];
        $byFam = [];
        foreach ($data['comics'] as $f) $byFam[$f['family']] = $f;
        foreach ($publishers as $pub => $fams) {
            $rows = [];
            foreach ($fams as $fam) if (isset($byFam[$fam])) { $rows[] = $byFam[$fam]; unset($byFam[$fam]); }
            if ($rows) $sections['comics'][$pub] = $sortFams($rows);
        }
        if ($byFam) $sections['comics']['OTHER'] = $sortFams(array_values($byFam));
    @endphp

    @foreach(['comics' => 'Comic Books to Order', 'magazines' => 'Magazines to Order'] as $type => $label)
        <div class="box box-solid">
            <div class="box-header with-border">
                <h3 class="box-title">{{ $label }} ({{ $storeName }}) <small>sales through {{ now()->format('n/j/Y') }}. Weeks of cover to order for: 4. <span id="rc-total-{{ $type }}"></span></small></h3>
            </div>
            <div class="box-body table-responsive">
                <table class="table table-bordered table-condensed rc" data-type="{{ $type }}">
                    <thead>
                        <tr>
                            <th>Family / Series</th>
                            <th>Velocity Tier</th>
                            <th class="text-right" title="Average days from when we bought it to when it sold">Avg Days to Sell</th>
                            <th class="text-right" title="Sales we can track from purchase to sale (scanned)">Sales With a Purchase Date</th>
                            <th class="text-right">Fastest (days)</th>
                            <th class="text-right">Slowest (days)</th>
                            <th class="text-right">Copies Sold</th>
                            <th class="text-right" title="Typed in by hand at the register">Manual Sales</th>
                            <th class="text-right">Sales ($)</th>
                            <th class="text-right">Avg Price</th>
                            <th class="text-right">Avg Cost</th>
                            <th>Best Sellers / Examples</th>
                            <th class="text-right">Sold per Week</th>
                            <th class="text-right">ERP says</th>
                            <th style="background:#fff7d6;">Qty in Stock</th>
                            <th style="background:#fff7d6;"># to Order</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($sections[$type] as $pub => $rows)
                        <tr class="rc-section"><td colspan="16" style="background:#eef3f8; font-weight:bold;">{{ $pub }}</td></tr>
                        @foreach($rows as $f)
                        <tr data-key="{{ $type }}:{{ $f['family'] }}" data-target="{{ $f['target'] }}" data-erp="{{ $f['stock'] }}">
                            <td><b>{{ $f['family'] }}</b></td>
                            <td>{{ $f['speed'] }}</td>
                            <td class="text-right">{{ $f['days_to_sell'] }}</td>
                            <td class="text-right">{{ $f['scanned'] + 0 }}</td>
                            <td class="text-right">{{ $f['fastest'] }}</td>
                            <td class="text-right">{{ $f['slowest'] }}</td>
                            <td class="text-right">{{ $f['sold_ytd'] + 0 }}</td>
                            <td class="text-right">{{ $f['manual_ytd'] + 0 }}</td>
                            <td class="text-right">${{ number_format($f['revenue_ytd'], 2) }}</td>
                            <td class="text-right">@if($f['avg_price'])${{ number_format($f['avg_price'], 2) }}@endif</td>
                            <td class="text-right">@if($f['avg_cost'] !== null)${{ number_format($f['avg_cost'], 2) }}@endif</td>
                            <td class="ro-muted">{{ $f['examples'] }}</td>
                            <td class="text-right">{{ $f['per_week'] }}</td>
                            <td class="text-right">{{ $f['stock'] }}</td>
                            <td style="background:#fffbea;"><input type="number" min="0" class="form-control rc-num rc-count" value="{{ $f['count'] !== null ? $f['count'] : '' }}"
                                title="{{ $f['count_at'] ? 'Counted ' . \Carbon\Carbon::parse($f['count_at'])->format('M j') : '' }}"> <span class="rc-saved"></span></td>
                            <td class="rc-order" style="background:#fffbea;">{{ $f['order'] }}</td>
                        </tr>
                        @endforeach
                    @empty
                        <tr><td colspan="16" class="text-muted">Nothing sold this year.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach
</section>
@endsection

@section('javascript')
<script>
(function () {
    var CSRF = "{{ csrf_token() }}";
    var LOC = @json($locationId);
    function totals() {
        document.querySelectorAll('table.rc').forEach(function (t) {
            var sum = 0;
            t.querySelectorAll('.rc-order').forEach(function (td) { sum += parseInt(td.textContent, 10) || 0; });
            var el = document.getElementById('rc-total-' + t.dataset.type);
            if (el) el.textContent = 'to order: ' + sum;
        });
    }
    document.querySelectorAll('.rc-count').forEach(function (inp) {
        inp.addEventListener('change', function () {
            var tr = inp.closest('tr');
            var v = parseInt(inp.value, 10);
            var have = isNaN(v) ? +tr.dataset.erp : v;
            tr.querySelector('.rc-order').textContent = Math.max(0, +tr.dataset.target - have);
            totals();
            var saved = tr.querySelector('.rc-saved');
            saved.textContent = '...';
            $.post('/reports/reorder/family-count', { _token: CSRF, location_id: LOC, format: 'comics', key: tr.dataset.key, qty: inp.value })
                .done(function () { saved.textContent = 'saved'; })
                .fail(function () { saved.textContent = 'not saved'; saved.style.color = '#c62828'; });
        });
    });
    totals();

    // Click any column name to sort; click again to flip. "Back to sections" undoes it.
    var tierRank = { 'Fast': 0, 'Staple': 1, 'Mid': 2, 'No data': 3 };
    document.querySelectorAll('table.rc').forEach(function (table) {
        var tbody = table.querySelector('tbody');
        var original = Array.prototype.slice.call(tbody.children);
        var dataRows = original.filter(function (tr) { return tr.dataset.key; });
        var dir = {}, last = null;
        var back = document.createElement('a');
        back.href = '#'; back.textContent = 'Back to sections'; back.style.cssText = 'display:none; margin-left:10px; font-size:13px;';
        table.parentNode.insertBefore(back, table);
        back.addEventListener('click', function (e) {
            e.preventDefault();
            original.forEach(function (tr) { tr.style.display = ''; tbody.appendChild(tr); });
            table.querySelectorAll('th .rc-arrow').forEach(function (a) { a.textContent = ''; });
            back.style.display = 'none';
        });
        function val(tr, i) {
            var td = tr.children[i];
            var inp = td.querySelector('input');
            var t = inp ? inp.value : td.textContent.trim();
            if (t in tierRank) return tierRank[t];
            var n = parseFloat(t.replace(/[$,]/g, ''));
            return t === '' ? null : (isNaN(n) ? t.toLowerCase() : n);
        }
        table.querySelectorAll('thead th').forEach(function (th, i) {
            th.style.cursor = 'pointer';
            th.insertAdjacentHTML('beforeend', ' <span class="rc-arrow" style="color:#999;font-size:11px;"></span>');
            th.addEventListener('click', function () {
                dir[i] = last === i ? -(dir[i] || 1) : (i === 0 || i === 1 || i === 11 ? 1 : -1);
                last = i;
                var rows = dataRows.slice().sort(function (a, b) {
                    var x = val(a, i), y = val(b, i);
                    if (x === y) return 0;
                    if (x === null) return 1;
                    if (y === null) return -1;
                    return (x < y ? -1 : 1) * dir[i];
                });
                original.forEach(function (tr) { if (!tr.dataset.key) tr.style.display = 'none'; });
                rows.forEach(function (tr) { tbody.appendChild(tr); });
                table.querySelectorAll('th .rc-arrow').forEach(function (a) { a.textContent = ''; });
                th.querySelector('.rc-arrow').textContent = dir[i] === 1 ? '\u25B2' : '\u25BC';
                back.style.display = '';
            });
        });
    });
})();
</script>
@endsection
