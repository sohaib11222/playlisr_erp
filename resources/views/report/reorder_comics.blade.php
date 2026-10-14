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
    /* Sarah 10/14 "too much scrolling": counting view shows the 6 columns
       Clarissa needs; the rest are behind "Show details". */
    table.rc.compact .rc-d { display:none; }
    table.rc.compact { width:auto !important; min-width:560px; }
    table.rc.compact td:first-child { min-width:220px; }
    table.rc.compact .rc-num { width:70px; height:32px; }
    table.rc td, table.rc th { padding:4px 8px !important; }
    table.rc thead th { position:sticky; top:0; background:#fff; z-index:2; }
    .rc-toggle { font-size:13px; margin-left:8px; }
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
            <details style="margin-top:10px;font-size:14px;"><summary style="cursor:pointer;">How this works</summary>
            <ol style="margin:8px 0 0; padding-left:22px; line-height:1.55;">
                <li>Comics and magazines are grouped by series (Spider-Man, Batman, Playboy...). Speed: Fast = sells within about 2 weeks of buying it, Staple = 2 to 7 weeks, Mid = slower.</li>
                <li>Count each series in the bins and type the total in <b>Qty in Stock</b>. It saves by itself.</li>
                <li><b># to Order</b> = enough for 4 weeks at the current pace, minus Qty in Stock (or minus what the ERP says if you haven't counted yet).</li>
            </ol></details>
            <div style="margin-top:10px;"><button type="button" class="btn btn-success" id="rcSubmit">Submit count</button>
                <span id="rcSubmitMsg" style="margin-left:8px;">@if($lastSubmit)Last submitted {{ \Carbon\Carbon::parse($lastSubmit['at'])->format('n/j g:ia') }} by {{ $lastSubmit['by'] }}@endif</span></div>
        </div>
    </div>

    @php
        $publishers = [
            'MARVEL' => ['Spider-Man', 'X-Men', 'Wolverine', 'Hulk', 'Avengers & solo heroes', 'Deadpool', 'Daredevil', 'Punisher', 'Fantastic Four', 'Doom', 'Silver Surfer / What If', 'Ghost Rider', 'Werewolf by Night', 'Conan'],
            'DC' => ['Batman', 'Superman', 'Flash', 'Justice League / DC', 'Wonder Woman', 'Green Lantern', 'Sandman / Vertigo', 'Cosmic Boy', 'COPS', 'Creature Commandos', 'Demon Knights', 'Final Crisis', 'The Fury of Firestorm', 'Green Arrow', 'Infinity Inc.', 'Kobalt', 'Outlaws', 'Peacemaker', 'Primal Force', 'The Ray', 'Secret Six', 'The Shadow', 'Teen Titans', 'Year of the Villain', 'Young Heroes In Love', 'DC - Other'],
            'IMAGE' => ['Spawn', 'WildCATS', 'Cyberforce', 'Deathblow', 'Image - Other'],
            'INDIE / LICENSED' => ['Star Wars', 'Star Trek', 'Godzilla', 'Cartoon & kids', 'Predator / Aliens', 'G.I. Joe', 'Virgin Comics', 'Vault Comics', 'Valiant Comics', 'Dark Horse', 'Eclipse Comics'],
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
                <a href="#" class="rc-toggle">Show details</a>
                <table class="table table-bordered table-condensed rc compact" data-type="{{ $type }}">
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

    // Submit count: every box saves on its own already; this re-sends any
    // that didn't save, then records that the count is finished.
    document.getElementById('rcSubmit').addEventListener('click', function () {
        var msg = document.getElementById('rcSubmitMsg'), btn = this, pending = [], n = 0;
        btn.disabled = true; msg.textContent = 'Checking every count is saved...';
        document.querySelectorAll('.rc-count').forEach(function (inp) {
            if (inp.value === '') return;
            n++;
            var tr = inp.closest('tr'), s = tr.querySelector('.rc-saved');
            if (s && s.textContent === 'saved') return;
            pending.push($.post('/reports/reorder/family-count', { _token: CSRF, location_id: LOC, format: 'comics', key: tr.dataset.key, qty: inp.value })
                .done(function () { if (s) s.textContent = 'saved'; }));
        });
        $.when.apply($, pending).done(function () {
            $.post('/reports/reorder/family-submit', { _token: CSRF, location_id: LOC, counted: n }).done(function () {
                msg.textContent = 'Submitted: ' + n + ' counts saved. Thank you!'; btn.disabled = false;
            }).fail(function () { msg.textContent = 'Counts are saved, but the submit did not go through. Try again.'; btn.disabled = false; });
        }).fail(function () { msg.textContent = 'Some counts did not save. Press Submit count again (nothing is lost).'; btn.disabled = false; });
    });

    // Mark the detail columns (everything but Series, Speed, Copies Sold,
    // ERP says, Qty in Stock, # to Order) so compact mode can hide them.
    var KEEP = [0, 1, 6, 13, 14, 15];
    document.querySelectorAll('table.rc').forEach(function (t) {
        t.querySelectorAll('tr').forEach(function (tr) {
            if (tr.classList.contains('rc-section')) return;
            Array.prototype.forEach.call(tr.children, function (td, i) { if (KEEP.indexOf(i) === -1) td.classList.add('rc-d'); });
        });
    });
    document.querySelectorAll('.rc-toggle').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            var t = a.parentNode.querySelector('table.rc');
            t.classList.toggle('compact');
            a.textContent = t.classList.contains('compact') ? 'Show details' : 'Hide details';
        });
    });

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
