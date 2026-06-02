@extends('layouts.app')
@section('title', 'Employee Leaderboard')

@section('content')
{{-- POS-create visual language (Inter Tight + cream palette) applied to the
     leaderboard. Pure reskin: a single scoped style block with !important
     overrides — no markup changes — so it can't collide with concurrent edits
     to the table body. Tokens mirror sale_pos/partials/_redesign_v2.blade.php. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<style>
    /* ---- POS tokens ---- */
    .content-wrapper, body { background: #FAF6EE !important; }
    .content-header, .content, .content .box, .content .btn, .content input,
    .content select, .content textarea, .content table {
        font-family: "Inter Tight", system-ui, -apple-system, sans-serif !important;
        color: #1F1B16;
    }
    .content-header > h1 { font-weight: 800; font-size: 26px; color: #1F1B16; letter-spacing: -.01em; }
    .content-header > h1 > .fa-trophy { color: #E8CF68; }
    .content-header > h1 small { color: #8E8273; font-weight: 500; }

    /* ---- cards ---- */
    .content .box { background: #FFFFFF !important; border: 1px solid #ECE3CF !important;
        border-top: 1px solid #ECE3CF !important; border-radius: 10px !important;
        box-shadow: 0 1px 2px rgba(31,27,22,.06) !important; }
    .content .box .box-header { border-bottom: 1px solid #ECE3CF !important; }
    .content .box .box-title { font-weight: 700; color: #1F1B16; }
    .content .box .box-body { padding: 18px 20px !important; }

    /* ---- labels + controls ---- */
    .content label { font-size: 11px; font-weight: 600; letter-spacing: .12em;
        text-transform: uppercase; color: #8E8273; }
    .content .form-control { border: 1px solid #DFD2B3 !important; border-radius: 8px !important;
        box-shadow: none !important; color: #1F1B16 !important; background: #FFFFFF !important; }
    .content .form-control:focus { border-color: #E8CF68 !important;
        box-shadow: 0 0 0 3px rgba(232,207,104,.25) !important; }
    .content .input-group-btn .btn-primary,
    .content .btn-primary { background: #1F1B16 !important; border: 1px solid #1F1B16 !important;
        color: #FAF6EE !important; border-radius: 8px !important; font-weight: 700;
        letter-spacing: .02em; }
    .content .btn-primary:hover { background: #000 !important; }
    .content .text-muted { color: #8E8273 !important; }

    /* ---- banners ---- */
    .content .alert-info { background: #FFF9DB !important; border: 1px solid #E8CF68 !important;
        border-left: 4px solid #E8CF68 !important; color: #5A4410 !important; border-radius: 10px !important; }
    .content .alert-info strong { color: #5A4410 !important; }
    .content .alert-success { background: #EAF3EC !important; border: 1px solid #2F6B3E !important;
        color: #2F6B3E !important; border-radius: 10px !important; }
    .content .alert-warning { border-radius: 10px !important; }

    /* ---- leaderboard table ---- */
    .lb-store-head { font-weight: 800 !important; color: #1F1B16 !important; font-size: 14px !important;
        text-transform: uppercase; letter-spacing: .05em; }
    .lb-table thead tr { color: #8E8273 !important; }
    .lb-table th { border-bottom: 1px solid #ECE3CF !important; }
    .lb-table td { border-top: 1px solid #F4ECD9 !important; }
    .lb-table tbody tr:hover { background: #FAF6EE !important; }
    .lb-rank-1, .lb-rank-2, .lb-rank-3 { border-radius: 6px; }
    .lb-me { background: #FFF9DB !important; }
    .lb-hit { color: #2F6B3E !important; }
    .lb-comm { background: #EFF6F0 !important; color: #2F6B3E !important; }
    .lb-soon { background: #FBF7EC !important; }
    .lb-soon-badge { background: #FFF9DB !important; color: #5A4410 !important; }

    /* ---- live KPI strip: keep green=ahead / red=behind, warm the tones ---- */
    .lb-live-card { border-radius: 10px !important; }
    .lb-up { background: #2F6B3E !important; }
    .lb-down { background: #8A3A2E !important; }
    .lb-neutral { background: #5A5045 !important; }
</style>
<section class="content-header">
    <h1><i class="fa fa-trophy"></i> Employee Leaderboard <small>sales floor performance &amp; commission</small></h1>
</section>

<section class="content">

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Window &amp; Goal</h3></div>
        <div class="box-body">
            <div class="row">
                <div class="col-md-6">
                    <form method="GET" action="{{ action('ReportController@employeeLeaderboard') }}" id="lb-period-form">
                        <label>Period</label>
                        <select name="period" class="form-control" id="lb-period" onchange="lbPeriodChange(this)">
                            <option value="today" @if($period==='today') selected @endif>Today</option>
                            <option value="yesterday" @if($period==='yesterday') selected @endif>Yesterday</option>
                            <option value="this_week" @if($period==='this_week') selected @endif>This week</option>
                            <option value="last_week" @if($period==='last_week') selected @endif>Previous week</option>
                            <option value="last_7" @if($period==='last_7') selected @endif>Last 7 days</option>
                            <option value="this_month" @if($period==='this_month') selected @endif>This month</option>
                            <option value="last_30" @if($period==='last_30') selected @endif>Last 30 days</option>
                            <option value="this_quarter" @if($period==='this_quarter') selected @endif>This quarter</option>
                            <option value="custom" @if($period==='custom') selected @endif>Custom dates&hellip;</option>
                        </select>
                        <div id="lb-custom" style="margin-top:8px; @if($period!=='custom') display:none; @endif">
                            <div class="row">
                                <div class="col-xs-5"><input type="date" name="start_date" class="form-control" value="{{ $start->format('Y-m-d') }}"></div>
                                <div class="col-xs-5"><input type="date" name="end_date" class="form-control" value="{{ $end->format('Y-m-d') }}"></div>
                                <div class="col-xs-2"><button type="submit" class="btn btn-primary btn-block">Go</button></div>
                            </div>
                        </div>
                        <p class="text-muted" style="margin-top:8px;">Showing <strong>{{ $start->format('M j, Y') }}</strong> &rarr; <strong>{{ $end->format('M j, Y') }}</strong></p>
                    </form>
                    <script>
                        function lbPeriodChange(sel) {
                            if (sel.value === 'custom') {
                                document.getElementById('lb-custom').style.display = '';
                            } else {
                                document.getElementById('lb-period-form').submit();
                            }
                        }
                    </script>
                </div>
            </div>
        </div>
    </div>

    @php $me = auth()->user()->id; @endphp

    <style>
        .lb-rank { font-size:16px; font-weight:800; text-align:center; width:44px; }
        .lb-rank-1 { background:#f6c244; color:#5b3b00; }
        .lb-rank-2 { background:#d4d8de; color:#1f2937; }
        .lb-rank-3 { background:#e8a06a; color:#5a2200; }
        .lb-me { background:#eef2ff; }
        .lb-table td, .lb-table th { vertical-align:middle; padding:6px 8px; }
        .lb-hit { color:#1b7a32; font-weight:700; }
        .lb-miss { color:#9aa0a6; }
        .lb-comm { background:#f1faf3; font-weight:700; color:#1b5e20; }
        .lb-soon { background:#f7f7f9; }
        .lb-soon-badge { display:inline-block; background:#e3e6ec; color:#6b7280; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; border-radius:10px; padding:2px 8px; }
        .lb-listed-link { color:#1f2937; border-bottom:1px dashed #c7b870; cursor:pointer; }
        .lb-listed-link:hover { color:#8a6d00; border-bottom-color:#8a6d00; text-decoration:none; }
        .lb-store-head { font-size:16px; font-weight:700; margin:0 0 8px; }
        .lb-sub { font-size:11px; color:#9aa0a6; }
        .lb-live { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin:0 0 12px; }
        .lb-live-card { border-radius:8px; padding:10px 12px; color:#fff; }
        .lb-up { background:#2e7d32; }
        .lb-down { background:#c62828; }
        .lb-neutral { background:#455a64; }
        .lb-live-lbl { font-size:10px; text-transform:uppercase; letter-spacing:.05em; opacity:.9; }
        .lb-live-val { font-size:22px; font-weight:800; line-height:1.1; margin-top:2px; }
        .lb-live-sub { font-size:11px; opacity:.92; margin-top:3px; }
        .lb-bar { position:relative; height:9px; background:rgba(255,255,255,.28); border-radius:6px; margin:7px 0 5px; }
        .lb-bar-fill { position:absolute; left:0; top:0; height:100%; background:#fff; border-radius:6px; transition:width .4s ease; }
        .lb-bar-pace { position:absolute; top:-2px; width:2px; height:13px; background:rgba(0,0,0,.55); }
    </style>

    <div class="alert alert-info" style="border-left:4px solid #3c8dbc;">
        <a href="{{ url('/admin/listing-commissions') }}" class="btn btn-primary btn-sm pull-right">Pay listing commissions</a>
        Each store is ranked by <strong>sales per hour</strong>. Whatnot sales don't count. <strong>Sales commission</strong> starts Jun 15.
    </div>

    <div class="row">
        @forelse($stores as $store)
            <div class="col-md-6">
                <div class="box box-solid">
                    <div class="box-body table-responsive">
                        <p class="lb-store-head">{{ $store['name'] }}</p>
                        @if(!empty($store['live']))
                            @php $lv = $store['live']; @endphp
                            <div class="lb-live" data-live-loc="{{ $store['id'] }}">
                                @php
                                    $tw = $lv['target_full'] > 0 ? min(100, max(0, $lv['revenue_today'] / $lv['target_full'] * 100)) : 0;
                                    $pw = $lv['target_full'] > 0 ? min(100, max(0, $lv['target_so_far'] / $lv['target_full'] * 100)) : 0;
                                @endphp
                                <div class="lb-live-card {{ $lv['target_state'] === 'ahead' ? 'lb-up' : 'lb-down' }}" data-tile="target">
                                    <div class="lb-live-lbl">Today vs target</div>
                                    <div class="lb-live-val" data-f="revenue_today">${{ number_format($lv['revenue_today']) }}</div>
                                    <div class="lb-bar" title="Full-day target ${{ number_format($lv['target_full']) }}">
                                        <div class="lb-bar-fill" data-f-bar="target" style="width: {{ round($tw, 1) }}%"></div>
                                        <div class="lb-bar-pace" data-f-pace="target" style="left: {{ round($pw, 1) }}%"></div>
                                    </div>
                                    <div class="lb-live-sub"><span data-f="target_pct">{{ number_format($lv['target_pct']) }}%</span> of <span data-f="target_so_far">${{ number_format($lv['target_so_far']) }}</span> by now &middot; ${{ number_format($lv['target_full']) }} goal</div>
                                </div>
                                <div class="lb-live-card {{ $lv['lfl_state'] === 'ahead' ? 'lb-up' : ($lv['lfl_state'] === 'behind' ? 'lb-down' : 'lb-neutral') }}" data-tile="lfl">
                                    <div class="lb-live-lbl">Vs last year</div>
                                    <div class="lb-live-val" data-f="lfl_pct">@if($lv['lfl_pct'] === null) — @else {{ ($lv['lfl_pct'] >= 0 ? '+' : '') . number_format($lv['lfl_pct'], 1) }}% @endif</div>
                                    <div class="lb-live-sub"><span data-f="lfl_last_year">${{ number_format($lv['lfl_last_year']) }}</span> by now</div>
                                </div>
                                <div class="lb-live-card lb-neutral" data-tile="tx">
                                    <div class="lb-live-lbl">Sales today</div>
                                    <div class="lb-live-val" data-f="tx_count">{{ number_format($lv['tx_count']) }}</div>
                                    <div class="lb-live-sub">avg sale <span data-f="avg_tx">${{ number_format($lv['avg_tx'], 2) }}</span></div>
                                </div>
                            </div>
                        @endif
                        <table class="table table-condensed lb-table">
                            <thead>
                                <tr style="color:#6b7280; text-transform:uppercase; font-size:10px; letter-spacing:.5px;">
                                    <th class="text-center">#</th>
                                    <th>Employee</th>
                                    <th class="text-right">Sales / hr</th>
                                    <th class="text-right">Hours</th>
                                    <th class="text-right">Sales</th>
                                    <th class="text-right">Hour target</th>
                                    <th class="text-right">Pace</th>
                                    <th class="text-right">Items listed</th>
                                    <th class="text-right">Sales from listed</th>
                                    <th class="text-right lb-comm">Listing pay</th>
                                    <th class="text-right lb-soon">Sales bonus</th>
                                    <th class="text-right">Total commission</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($store['rows'] as $i => $r)
                                    @php
                                        $rank = $i + 1;
                                        $rank_cls = $rank === 1 ? 'lb-rank-1' : ($rank === 2 ? 'lb-rank-2' : ($rank === 3 ? 'lb-rank-3' : ''));
                                        $no_hours = is_null($r->revenue_per_hour);
                                    @endphp
                                    <tr class="{{ $r->user_id == $me ? 'lb-me' : '' }}">
                                        <td class="lb-rank {{ $no_hours ? '' : $rank_cls }}">{{ $no_hours ? '—' : $rank }}</td>
                                        <td>
                                            <strong>{{ $r->employee }}</strong>
                                            @if($r->whatnot_revenue > 0)<div class="lb-sub">Whatnot ${{ number_format($r->whatnot_revenue, 0) }} (excluded)</div>@endif
                                        </td>
                                        <td class="text-right">@if(!$no_hours)<strong style="color:#065f46;">${{ number_format($r->revenue_per_hour, 0) }}</strong>@else — @endif</td>
                                        <td class="text-right">
                                            @if($r->hours_worked > 0)
                                                {{ number_format($r->hours_worked, 1) }}h
                                                @if(($r->hour_peak ?? 0) > 0 || ($r->hour_offpeak ?? 0) > 0)<div class="lb-sub">{{ number_format($r->hour_peak, 1) }}h peak · {{ number_format($r->hour_offpeak, 1) }}h off</div>@endif
                                            @else <span class="text-muted">—</span>@endif
                                        </td>
                                        <td class="text-right">${{ number_format($r->non_whatnot_revenue, 0) }}</td>
                                        <td class="text-right">
                                            @if(!is_null($r->hour_target))
                                                ${{ number_format($r->hour_target, 0) }}
                                                <div class="lb-sub">+{{ rtrim(rtrim(number_format($r->hour_target_stretch_pct, 1), '0'), '.') }}% vs store rate</div>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-right">
                                            @if(!is_null($r->hour_pace_pct))
                                                @if($r->hour_pace_pct >= 100)<span class="lb-hit">{{ number_format($r->hour_pace_pct, 0) }}%</span>@else<span class="lb-miss">{{ number_format($r->hour_pace_pct, 0) }}%</span>@endif
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        @php
                                            $listedAttrs = 'data-listed="1" data-user="'.$r->user_id.'" data-name="'.e($r->employee).'" data-loc="'.$store['id'].'" data-store="'.e($store['name']).'"';
                                        @endphp
                                        <td class="text-right">@if($r->priced_count > 0)<a href="#" class="lb-listed-link" {!! $listedAttrs !!}>{{ number_format($r->priced_count, 0) }}</a>@else <span class="text-muted">—</span>@endif</td>
                                        <td class="text-right">@if($r->priced_revenue > 0)<a href="#" class="lb-listed-link" {!! $listedAttrs !!}>${{ number_format($r->priced_revenue, 0) }}</a>@else ${{ number_format($r->priced_revenue, 0) }}@endif</td>
                                        <td class="text-right lb-comm">
                                            @if($r->barcoding_commission > 0)${{ number_format($r->barcoding_commission, 2) }}@else <span class="text-muted">—</span>@endif
                                        </td>
                                        <td class="text-right lb-soon">
                                            @if($r->sales_bonus_live)
                                                @if($r->goal_bonus > 0)${{ number_format($r->goal_bonus, 2) }}@else <span class="text-muted">—</span>@endif
                                            @else
                                                @if($r->goal_bonus > 0)
                                                    <span class="text-muted">${{ number_format($r->goal_bonus, 2) }}</span>
                                                    <div class="lb-sub">projected · from Jun 15</div>
                                                @else
                                                    <span class="lb-soon-badge">From Jun 15</span>
                                                @endif
                                            @endif
                                        </td>
                                        <td class="text-right">@if($r->total_commission > 0)<strong>${{ number_format($r->total_commission, 2) }}</strong>@else <span class="text-muted">—</span>@endif</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="12" class="text-center text-muted">No activity in this window.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @empty
            <div class="col-md-12"><div class="alert alert-warning">No active store locations found.</div></div>
        @endforelse
    </div>

    {{-- Drill-down: items a person listed that sold in this window/store --}}
    <div class="modal fade" id="lb-listed-modal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="lb-listed-title">Items listed &amp; sold</h4>
                    <div class="text-muted" id="lb-listed-sub" style="font-size:12px;"></div>
                </div>
                <div class="modal-body" style="max-height:65vh; overflow:auto;">
                    <div id="lb-listed-loading" class="text-muted text-center" style="padding:24px;">Loading…</div>
                    <table class="table table-condensed" id="lb-listed-table" style="display:none;">
                        <thead>
                            <tr style="color:#6b7280; text-transform:uppercase; font-size:10px; letter-spacing:.5px;">
                                <th>Item</th>
                                <th class="text-right">Units sold</th>
                                <th class="text-right">Revenue</th>
                            </tr>
                        </thead>
                        <tbody id="lb-listed-body"></tbody>
                        <tfoot>
                            <tr style="font-weight:700; border-top:2px solid #ECE3CF;">
                                <td>Total</td>
                                <td class="text-right" id="lb-listed-total-units"></td>
                                <td class="text-right" id="lb-listed-total-rev"></td>
                            </tr>
                        </tfoot>
                    </table>
                    <div id="lb-listed-empty" class="text-muted text-center" style="display:none; padding:24px;">No listed items sold in this window.</div>
                </div>
            </div>
        </div>
    </div>

</section>
@stop

@section('javascript')
<script>
(function () {
    var DATA_URL = "{{ $live_data_url ?? '' }}";
    if (!DATA_URL) return;

    function money(n)  { return '$' + Math.round(n).toLocaleString(); }
    function money2(n) { return '$' + Number(n).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

    function setState(card, state) {
        if (!card) return;
        card.classList.remove('lb-up', 'lb-down', 'lb-neutral');
        card.classList.add(state === 'ahead' ? 'lb-up' : (state === 'behind' ? 'lb-down' : 'lb-neutral'));
    }
    function setField(scope, name, value) {
        var el = scope.querySelector('[data-f="' + name + '"]');
        if (el) el.textContent = value;
    }

    // Each store's live strip refreshes independently from the same endpoint
    // the /store-performance dashboard uses, scoped by location id. The
    // leaderboard tables themselves are a fixed window and never re-fetch.
    function refreshAll() {
        document.querySelectorAll('[data-live-loc]').forEach(function (scope) {
            var loc = scope.getAttribute('data-live-loc');
            fetch(DATA_URL + '?location_id=' + loc, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.ok ? r.json() : null; })
                .then(function (d) {
                    if (!d) return;
                    setField(scope, 'revenue_today', money(d.revenue_today));
                    setField(scope, 'target_pct', Math.round(d.target_pct) + '%');
                    setField(scope, 'target_so_far', money(d.target_so_far));
                    setState(scope.querySelector('[data-tile="target"]'), d.target_state);

                    var tFull = Number(d.target_full) || 0;
                    var fillEl = scope.querySelector('[data-f-bar="target"]');
                    var paceEl = scope.querySelector('[data-f-pace="target"]');
                    if (fillEl) fillEl.style.width = (tFull > 0 ? Math.min(100, Math.max(0, d.revenue_today / tFull * 100)) : 0) + '%';
                    if (paceEl) paceEl.style.left = (tFull > 0 ? Math.min(100, Math.max(0, d.target_so_far / tFull * 100)) : 0) + '%';

                    setField(scope, 'lfl_pct', d.lfl_pct === null ? '—' : (d.lfl_pct >= 0 ? '+' : '') + Number(d.lfl_pct).toFixed(1) + '%');
                    setField(scope, 'lfl_last_year', money(d.lfl_last_year));
                    setState(scope.querySelector('[data-tile="lfl"]'), d.lfl_state);

                    setField(scope, 'tx_count', Number(d.tx_count).toLocaleString());
                    setField(scope, 'avg_tx', money2(d.avg_tx));
                })
                .catch(function () { /* keep last good values on transient error */ });
        });
    }

    setInterval(refreshAll, 60000);
})();

// Items-listed drill-down: click a person's "items listed" / "sales from
// listed" to see exactly which of their listings sold, best-sellers first.
(function () {
    var URL = "{{ $listed_items_url ?? '' }}";
    var START = "{{ optional($start)->toDateString() }}";
    var END   = "{{ optional($end)->toDateString() }}";
    if (!URL) return;

    function money(n) { return '$' + Math.round(Number(n)).toLocaleString(); }

    document.addEventListener('click', function (ev) {
        var a = ev.target.closest ? ev.target.closest('.lb-listed-link') : null;
        if (!a) return;
        ev.preventDefault();

        var user = a.getAttribute('data-user');
        var loc  = a.getAttribute('data-loc');
        var name = a.getAttribute('data-name') || '';
        var store = a.getAttribute('data-store') || '';

        document.getElementById('lb-listed-title').textContent = name + ' — items listed & sold';
        document.getElementById('lb-listed-sub').textContent = store;
        document.getElementById('lb-listed-loading').style.display = '';
        document.getElementById('lb-listed-table').style.display = 'none';
        document.getElementById('lb-listed-empty').style.display = 'none';
        document.getElementById('lb-listed-body').innerHTML = '';

        if (window.jQuery) { jQuery('#lb-listed-modal').modal('show'); }

        var qs = '?user_id=' + encodeURIComponent(user) +
                 '&location_id=' + encodeURIComponent(loc) +
                 '&start_date=' + encodeURIComponent(START) +
                 '&end_date=' + encodeURIComponent(END);

        fetch(URL + qs, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                document.getElementById('lb-listed-loading').style.display = 'none';
                if (!d || !d.items || d.items.length === 0) {
                    document.getElementById('lb-listed-empty').style.display = '';
                    return;
                }
                var rows = d.items.map(function (it) {
                    var nameCell = document.createElement('div');
                    nameCell.textContent = it.product; // textContent escapes
                    return '<tr><td>' + nameCell.innerHTML + '</td>' +
                           '<td class="text-right">' + Number(it.units).toLocaleString() + '</td>' +
                           '<td class="text-right">' + money(it.revenue) + '</td></tr>';
                }).join('');
                document.getElementById('lb-listed-body').innerHTML = rows;
                document.getElementById('lb-listed-total-units').textContent = Number(d.total_units).toLocaleString();
                document.getElementById('lb-listed-total-rev').textContent = money(d.total_revenue);
                document.getElementById('lb-listed-table').style.display = '';
            })
            .catch(function () {
                document.getElementById('lb-listed-loading').style.display = 'none';
                document.getElementById('lb-listed-empty').style.display = '';
            });
    });
})();
</script>
@stop
