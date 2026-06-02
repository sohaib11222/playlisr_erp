@extends('layouts.app')
@section('title', 'Store Performance')

@section('content')
<style>
    .sp-wrap { max-width: 1200px; margin: 0 auto; padding: 8px 4px 40px; }
    .sp-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin: 6px 2px 18px; }
    .sp-title { font-size: 22px; font-weight: 700; color: #2b2b2b; margin: 0; }
    .sp-asof { font-size: 13px; color: #777; }
    .sp-asof b { color: #444; }
    .sp-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
    @media (max-width: 700px) { .sp-grid { grid-template-columns: 1fr; } }
    .sp-tile { border-radius: 12px; padding: 22px 24px; color: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.12); min-height: 170px; display: flex; flex-direction: column; }
    .sp-ahead  { background: #2e7d32; }
    .sp-behind { background: #c62828; }
    .sp-neutral { background: #455a64; }
    .sp-label { font-size: 14px; text-transform: uppercase; letter-spacing: .06em; opacity: .9; margin-bottom: 6px; }
    .sp-big { font-size: 44px; font-weight: 800; line-height: 1.05; }
    .sp-sub { font-size: 15px; margin-top: auto; padding-top: 12px; opacity: .95; }
    .sp-sub .sp-pill { display: inline-block; background: rgba(255,255,255,.22); border-radius: 20px; padding: 2px 12px; font-weight: 700; margin-right: 8px; }
    .sp-loc select { display: inline-block; width: auto; min-width: 160px; }
    .sp-foot { margin-top: 16px; font-size: 12px; color: #999; text-align: center; }
    .sp-lb { margin-top: 26px; background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.10); padding: 18px 20px 8px; }
    .sp-lb-head { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px; margin-bottom: 10px; }
    .sp-lb-title { font-size: 18px; font-weight: 700; color: #2b2b2b; margin: 0; }
    .sp-lb-window { font-size: 13px; color: #888; }
    .sp-lb table { width: 100%; border-collapse: collapse; }
    .sp-lb th { text-align: left; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #999; border-bottom: 2px solid #eee; padding: 8px 10px; }
    .sp-lb td { padding: 10px; border-bottom: 1px solid #f1f1f1; font-size: 15px; color: #333; }
    .sp-lb th.num, .sp-lb td.num { text-align: right; }
    .sp-lb tr:last-child td { border-bottom: none; }
    .sp-rank { font-weight: 800; color: #777; width: 48px; }
    .sp-rank-1 { color: #c9a227; }
    .sp-rank-2 { color: #8c8c8c; }
    .sp-rank-3 { color: #b07b41; }
    .sp-lb-empty { color: #999; padding: 14px 10px 20px; font-size: 14px; }
</style>

<section class="content sp-wrap">
    <div class="sp-head">
        <h1 class="sp-title">Store Performance &middot; {{ $location_name }}</h1>
        <div class="sp-loc">
            <form method="GET" action="{{ action('StorePerformanceController@index') }}" id="sp-loc-form">
                <select name="location_id" class="form-control" onchange="this.form.submit()">
                    @foreach($locations as $id => $name)
                        <option value="{{ $id }}" @if($id == $location_id) selected @endif>{{ $name }}</option>
                    @endforeach
                </select>
            </form>
        </div>
    </div>

    <div class="sp-grid" id="sp-grid">
        {{-- Revenue vs target --}}
        <div class="sp-tile {{ $data['target_state'] === 'ahead' ? 'sp-ahead' : 'sp-behind' }}" data-tile="target">
            <div class="sp-label">Revenue today vs target</div>
            <div class="sp-big" data-f="revenue_today">${{ number_format($data['revenue_today']) }}</div>
            <div class="sp-sub">
                <span class="sp-pill" data-f="target_pct">{{ number_format($data['target_pct']) }}%</span>
                <span data-f="target_so_far">${{ number_format($data['target_so_far']) }}</span> expected by now
                &middot; ${{ number_format($data['target_full']) }} full-day target
            </div>
        </div>

        {{-- LFL vs same day last year --}}
        <div class="sp-tile {{ $data['lfl_state'] === 'ahead' ? 'sp-ahead' : ($data['lfl_state'] === 'behind' ? 'sp-behind' : 'sp-neutral') }}" data-tile="lfl">
            <div class="sp-label">Like-for-like vs last year</div>
            <div class="sp-big" data-f="lfl_pct">
                @if($data['lfl_pct'] === null) — @else {{ ($data['lfl_pct'] >= 0 ? '+' : '') . number_format($data['lfl_pct'], 1) }}% @endif
            </div>
            <div class="sp-sub">
                <span data-f="lfl_last_year">${{ number_format($data['lfl_last_year']) }}</span> by this time on
                <span data-f="lfl_date">{{ $data['lfl_date'] }}</span>
                &middot; ${{ number_format($data['lfl_last_year_full']) }} that full day
            </div>
        </div>

        {{-- Transaction count --}}
        <div class="sp-tile sp-neutral" data-tile="tx">
            <div class="sp-label">Transactions today</div>
            <div class="sp-big" data-f="tx_count">{{ number_format($data['tx_count']) }}</div>
            <div class="sp-sub">Completed sales rung at this store today</div>
        </div>

        {{-- Average transaction value --}}
        <div class="sp-tile sp-neutral" data-tile="atv">
            <div class="sp-label">Average transaction value</div>
            <div class="sp-big" data-f="avg_tx">${{ number_format($data['avg_tx'], 2) }}</div>
            <div class="sp-sub">Revenue today &divide; transactions today</div>
        </div>
    </div>

    @if(!empty($show_leaderboard))
    <div class="sp-lb">
        <div class="sp-lb-head">
            <h2 class="sp-lb-title">Last week&rsquo;s leaderboard &middot; {{ $location_name }}</h2>
            <span class="sp-lb-window">{{ $leaderboard_label }} &middot; ranked by revenue / hour</span>
        </div>
        @if($leaderboard_rows->count() === 0)
            <div class="sp-lb-empty">No ranked sales for this store last week.</div>
        @else
        <table>
            <thead>
                <tr>
                    <th class="sp-rank">#</th>
                    <th>Employee</th>
                    <th class="num">Revenue</th>
                    <th class="num">Items</th>
                    <th class="num">Avg sale</th>
                    <th class="num">$ / hr</th>
                </tr>
            </thead>
            <tbody>
                @foreach($leaderboard_rows as $i => $r)
                <tr>
                    <td class="sp-rank sp-rank-{{ $i + 1 }}">{{ $i + 1 }}</td>
                    <td>{{ $r->employee }}</td>
                    <td class="num">${{ number_format($r->non_whatnot_revenue) }}</td>
                    <td class="num">{{ number_format($r->items_rung) }}</td>
                    <td class="num">${{ number_format($r->avg_tx, 2) }}</td>
                    <td class="num">{{ $r->revenue_per_hour === null ? '—' : '$' . number_format($r->revenue_per_hour) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
    @endif

    <div class="sp-foot">
        Updated <b id="sp-asof">{{ $data['as_of'] }}</b> &middot; tiles refresh automatically every 60 seconds &middot; leaderboard is last week, fixed
    </div>
</section>
@stop

@section('javascript')
<script>
(function () {
    var DATA_URL = "{{ action('StorePerformanceController@data') }}";
    var locId = {{ (int) $location_id }};

    function money(n)    { return '$' + Math.round(n).toLocaleString(); }
    function money2(n)   { return '$' + Number(n).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

    function setTileState(tile, state) {
        var el = document.querySelector('[data-tile="' + tile + '"]');
        if (!el) return;
        el.classList.remove('sp-ahead', 'sp-behind', 'sp-neutral');
        el.classList.add(state === 'ahead' ? 'sp-ahead' : (state === 'behind' ? 'sp-behind' : 'sp-neutral'));
    }
    function setField(name, value) {
        var el = document.querySelector('[data-f="' + name + '"]');
        if (el) el.textContent = value;
    }

    function refresh() {
        fetch(DATA_URL + '?location_id=' + locId, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) return;
                setField('revenue_today', money(d.revenue_today));
                setField('target_pct', Math.round(d.target_pct) + '%');
                setField('target_so_far', money(d.target_so_far));
                setTileState('target', d.target_state);

                setField('lfl_pct', d.lfl_pct === null ? '—' : (d.lfl_pct >= 0 ? '+' : '') + Number(d.lfl_pct).toFixed(1) + '%');
                setField('lfl_last_year', money(d.lfl_last_year));
                setField('lfl_date', d.lfl_date);
                setTileState('lfl', d.lfl_state);

                setField('tx_count', Number(d.tx_count).toLocaleString());
                setField('avg_tx', money2(d.avg_tx));

                var asof = document.getElementById('sp-asof');
                if (asof) asof.textContent = d.as_of;
            })
            .catch(function () { /* keep last good values on transient error */ });
    }

    setInterval(refresh, 60000);
})();
</script>
@stop
