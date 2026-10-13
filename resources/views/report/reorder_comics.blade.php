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
                <li>Comics and magazines are grouped by series (Spider-Man, Batman, Playboy...). Speed: Fast = sells about every 2 weeks or faster, Staple = every 2 to 7 weeks, Mid = slower.</li>
                <li>Count each series in the bins and type the total in <b>Counted</b>. It saves by itself.</li>
                <li><b>Order</b> = enough for 4 weeks at the current pace, minus what you counted (or minus the ERP count if you haven't counted yet).</li>
            </ol>
        </div>
    </div>

    @foreach(['comics' => 'Comics', 'magazines' => 'Magazines'] as $type => $label)
        <div class="box box-solid">
            <div class="box-header with-border"><h3 class="box-title">{{ $label }} <small id="rc-total-{{ $type }}"></small></h3></div>
            <div class="box-body table-responsive">
                <table class="table table-bordered table-condensed rc" data-type="{{ $type }}">
                    <thead>
                        <tr>
                            <th>Series</th>
                            <th>Counted</th>
                            <th>Order</th>
                            <th>Speed</th>
                            <th class="text-right" title="Average days between sales this year">Days between sales</th>
                            <th class="text-right">Sold, 90 days</th>
                            <th class="text-right">Sold this year</th>
                            <th class="text-right">Sales this year</th>
                            <th class="text-right">Per week</th>
                            <th class="text-right">Avg price</th>
                            <th>Last sold</th>
                            <th class="text-right">ERP says</th>
                            <th>Best sellers</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($data[$type] as $f)
                        <tr data-key="{{ $type }}:{{ $f['family'] }}" data-target="{{ $f['target'] }}" data-erp="{{ $f['stock'] }}">
                            <td><b>{{ $f['family'] }}</b></td>
                            <td><input type="number" min="0" class="form-control rc-num rc-count" value="{{ $f['count'] !== null ? $f['count'] : '' }}"
                                title="{{ $f['count_at'] ? 'Counted ' . \Carbon\Carbon::parse($f['count_at'])->format('M j') : '' }}"> <span class="rc-saved"></span></td>
                            <td class="rc-order">{{ $f['order'] }}</td>
                            <td>{{ $f['speed'] }}</td>
                            <td class="text-right">{{ $f['days_between'] }}</td>
                            <td class="text-right">{{ $f['sold'] + 0 }}</td>
                            <td class="text-right">{{ $f['sold_ytd'] + 0 }}</td>
                            <td class="text-right">${{ number_format($f['revenue_ytd']) }}</td>
                            <td class="text-right">{{ $f['per_week'] }}</td>
                            <td class="text-right">@if($f['avg_price'])${{ number_format($f['avg_price'], 2) }}@endif</td>
                            <td>@if($f['last']){{ \Carbon\Carbon::parse($f['last'])->format('M j') }}@if($f['days_since'] !== null && $f['days_since'] > 30) <span class="ro-muted">({{ $f['days_since'] }} days)</span>@endif @endif</td>
                            <td class="text-right">{{ $f['stock'] }}</td>
                            <td class="ro-muted">{{ $f['examples'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="13" class="text-muted">Nothing sold in the last 90 days.</td></tr>
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
})();
</script>
@endsection
