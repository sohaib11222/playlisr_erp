@extends('layouts.app')
@section('title', 'Recent Sales Feed')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>
    document.body.classList.add('pos-v2','pos-list-v2');
    // Auto-refresh every 30 seconds so the feed stays live without
    // a manual reload. Pauses while the user has a focused select/input
    // (changing filters), and skips refresh when the tab is hidden so
    // background tabs don't keep hammering the server. Disabled on
    // past-day views — yesterday's data won't change, no point reloading.
    (function () {
        var IS_TODAY = {{ $is_today ? 'true' : 'false' }};
        if (!IS_TODAY) return;
        var REFRESH_MS = 30000;
        function tick() {
            if (document.hidden) return;
            var ae = document.activeElement;
            if (ae && (ae.tagName === 'SELECT' || ae.tagName === 'INPUT' || ae.tagName === 'TEXTAREA')) return;
            window.location.reload();
        }
        setInterval(tick, REFRESH_MS);
    })();
</script>

<style>
    body.pos-list-v2 section.content { background: #FAF6EE; }
    .rf-wrap { max-width: 1400px; margin: 0 auto; }
    .rf-day-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; align-items: start; }
    .rf-day-col { min-width: 0; }
    .rf-day-col-head { background: #FFFFFF; border: 1px solid #ECE3CF; border-radius: 10px;
        padding: 10px 14px; margin-bottom: 10px; box-shadow: 0 1px 2px rgba(31,27,22,.06);
        display: flex; gap: 10px; align-items: baseline; flex-wrap: wrap; }
    .rf-day-col-head .rf-day-store-name { font-size: 14px; font-weight: 700; color: #1F1B16;
        text-transform: uppercase; letter-spacing: .06em; }
    .rf-day-col-head .rf-day-col-summary { font-size: 12px; color: #5A5045; }
    .rf-day-empty { background: #FFFFFF; border: 1px dashed #DFD2B3; border-radius: 10px;
        padding: 18px; text-align: center; color: #8A7C6A; font-size: 13px; }
    .rf-card.rf-clover-pending { border-left: 4px solid #C99A2A; background: #FDF9EC; }
    .rf-card.rf-clover-pending .rf-orphan-tag { background: #C99A2A; color: #fff; }
    @media (max-width: 1000px) { .rf-day-grid { grid-template-columns: 1fr; } }
    .rf-filters { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;
        background: #FFFFFF; border: 1px solid #ECE3CF; border-radius: 10px;
        padding: 14px 16px; margin-bottom: 16px; box-shadow: 0 1px 2px rgba(31,27,22,.06); }
    .rf-filters label { color: #5A5045; font-weight: 600; font-size: 12px;
        text-transform: uppercase; letter-spacing: .06em; display: block; margin-bottom: 4px; }
    .rf-filters .form-control { border: 1px solid #DFD2B3; border-radius: 7px; color: #1F1B16; }
    .rf-count { color: #5A5045; font-size: 13px; margin-left: auto; }
    .rf-export { display: inline-flex; align-items: center; gap: 6px;
        background: #1F1B16; color: #FAF6EE !important; border: 1px solid #1F1B16;
        border-radius: 7px; padding: 8px 14px; font-size: 13px; font-weight: 600;
        text-decoration: none !important; transition: background .15s, border-color .15s; }
    .rf-export:hover { background: #3A3128; border-color: #3A3128; }

    .rf-card { background: #FFFFFF; border: 1px solid #ECE3CF; border-radius: 10px;
        padding: 14px 16px; margin-bottom: 12px; box-shadow: 0 1px 2px rgba(31,27,22,.06);
        font-family: "Inter Tight", system-ui, sans-serif; color: #1F1B16; }
    .rf-head { display: flex; justify-content: space-between; align-items: baseline;
        gap: 10px; flex-wrap: wrap; padding-bottom: 10px;
        border-bottom: 1px dashed #ECE3CF; margin-bottom: 10px; }
    .rf-head-left { display: flex; gap: 10px; align-items: baseline; flex-wrap: wrap; }
    .rf-invoice { font-weight: 700; font-size: 15px; letter-spacing: -.01em; }
    .rf-invoice a { color: #1F1B16; text-decoration: none; border-bottom: 1px dotted #BFB096; }
    .rf-invoice a:hover { color: #8B6A1A; border-bottom-color: #8B6A1A; }
    .rf-time, .rf-store, .rf-customer, .rf-cashier { color: #5A5045; font-size: 13px; }
    .rf-cashier strong { color: #1F1B16; font-weight: 700; }
    .rf-tender { display: inline-block; padding: 1px 7px; border-radius: 999px;
        font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; }
    .rf-tender.tender-cash { background: #E6F2E0; border: 1px solid #B8D9A8; color: #2E6F40; }
    .rf-tender.tender-card { background: #E0EAF7; border: 1px solid #A8C0DD; color: #2E4A8A; }
    .rf-tender.tender-other { background: #F2EAE0; border: 1px solid #DDC8A8; color: #8B6A1A; }
    .rf-tender.tender-mixed { background: #F0E0F2; border: 1px solid #D5A8DD; color: #5E2E80; }
    .rf-store-badge { display: inline-block; padding: 1px 7px; border-radius: 999px;
        background: #F7F1E3; border: 1px solid #DFD2B3; color: #5A5045;
        font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; }

    .rf-lines { list-style: none; padding: 0; margin: 0; }
    .rf-line { display: flex; justify-content: space-between; gap: 12px;
        padding: 4px 0; font-size: 14px; line-height: 1.4; }
    .rf-line-name { flex: 1; min-width: 0; }
    .rf-line-name .qty { color: #5A5045; font-weight: 600; margin-right: 6px; }
    .rf-line-price { color: #1F1B16; font-variant-numeric: tabular-nums;
        white-space: nowrap; font-weight: 500; }
    .rf-manual-tag { display: inline-block; margin-left: 6px; padding: 1px 6px;
        border-radius: 4px; background: #F7E8C2; color: #8B6A1A;
        font-size: 10px; font-weight: 700; text-transform: uppercase;
        letter-spacing: .06em; vertical-align: middle; }
    .rf-line-cat { display: block; margin-top: 1px; color: #8A7C6A;
        font-size: 11px; font-weight: 800; text-transform: uppercase;
        letter-spacing: .06em; }
    .rf-line-cat .sub { color: #BFB096; font-weight: 600; text-transform: none;
        letter-spacing: 0; margin-left: 4px; }
    /* Per-format colors. Sealed variants get a brighter shade than used. */
    .rf-line-cat.cat-vinyl-used     { color: #1F1B16; }
    .rf-line-cat.cat-vinyl-sealed   { color: #6B3F12; }
    .rf-line-cat.cat-cd-used        { color: #4A6FA5; }
    .rf-line-cat.cat-cd-sealed      { color: #1E5BAE; }
    .rf-line-cat.cat-cassette-used  { color: #C8602B; }
    .rf-line-cat.cat-cassette-sealed{ color: #E8742B; }
    .rf-line-cat.cat-7inch          { color: #7B3FA0; }
    .rf-line-cat.cat-8track         { color: #1A7A7A; }
    .rf-line-cat.cat-vhs            { color: #B23A3A; }
    .rf-line-cat.cat-dvd            { color: #A23A8C; }
    .rf-line-cat.cat-laserdisc      { color: #1A8A9A; }
    .rf-line-cat.cat-movies         { color: #8B2C2C; }
    .rf-line-cat.cat-books          { color: #2E6F40; }
    .rf-line-cat.cat-trading        { color: #A88B0F; }
    .rf-line-cat.cat-apparel        { color: #3A4A8A; }
    .rf-line-cat.cat-vgame          { color: #1F8B3F; }
    .rf-line-cat.cat-gear           { color: #4A4A4A; }
    .rf-line-cat.cat-gift           { color: #C8478A; }
    .rf-line-cat.cat-poster         { color: #B07A1A; }
    .rf-line-cat.cat-accessory      { color: #8B6A1A; }

    .rf-foot { display: flex; justify-content: space-between; align-items: flex-start;
        gap: 10px; flex-wrap: wrap; padding-top: 10px; margin-top: 8px;
        border-top: 1px dashed #ECE3CF; }
    .rf-foot-meta { color: #8A7C6A; font-size: 12px; }
    .rf-total { font-weight: 700; font-size: 16px; font-variant-numeric: tabular-nums; }
    .rf-total .lbl { color: #5A5045; font-weight: 600; font-size: 12px;
        text-transform: uppercase; letter-spacing: .06em; margin-right: 6px; }

    /* Reconcile block: ERP column vs Clover column, side by side. */
    .rf-recon { display: flex; gap: 24px; font-variant-numeric: tabular-nums; }
    .rf-recon-col { min-width: 90px; text-align: right; }
    .rf-recon-col .lbl { color: #5A5045; font-weight: 600; font-size: 11px;
        text-transform: uppercase; letter-spacing: .06em; margin-bottom: 2px; }
    .rf-recon-col .amt { color: #1F1B16; font-weight: 700; font-size: 16px;
        line-height: 1.2; }
    .rf-recon-col .sub { color: #8A7C6A; font-size: 11px; margin-top: 2px;
        line-height: 1.4; }
    .rf-recon.is-mismatch .rf-recon-clover .amt { color: #B0451A; }
    .rf-recon-mismatch-tag { display: inline-block; margin-left: 6px; padding: 1px 6px;
        border-radius: 4px; background: #FBE0D2; color: #B0451A;
        font-size: 10px; font-weight: 700; text-transform: uppercase;
        letter-spacing: .06em; vertical-align: middle; }

    /* Clover-only card: a charge that hit the terminal but has no ERP sale.
       Distinct purple-tinted accent so it's obvious in the feed without
       being alarming-red — these need investigation, not a fire drill. */
    .rf-card.rf-clover-orphan { border-left: 4px solid #7B3FA0; }
    .rf-card.rf-clover-orphan .rf-orphan-tag { display: inline-block; padding: 2px 8px;
        border-radius: 4px; background: #EFE0F5; color: #5E2E80;
        font-size: 11px; font-weight: 700; text-transform: uppercase;
        letter-spacing: .06em; }
    .rf-card.rf-clover-orphan .rf-orphan-note { color: #5A5045; font-size: 13px;
        font-style: italic; padding: 4px 0 6px; }
    .rf-card.rf-clover-orphan .rf-recon-clover .amt { color: #5E2E80; }

    .rf-empty { text-align: center; color: #8A7C6A; padding: 40px 20px;
        background: #FFFFFF; border: 1px dashed #DFD2B3; border-radius: 10px; }

    @media (max-width: 600px) {
        .rf-head, .rf-foot { flex-direction: column; align-items: flex-start; }
        .rf-line { font-size: 13px; }
    }
</style>

@php
    // Map a Nivessa category name to a CSS class for color-coding the
    // per-line category tag. Substring match (case-insensitive) so DB
    // names like "Used Vinyl", "CDs (Used)", "7\", 45 RPM" all hit the
    // right bucket. Order matters — check sealed variants first because
    // "sealed cd" also contains "cd".
    $rfCatClass = function ($name) {
        $n = strtolower(trim((string) $name));
        if ($n === '') return '';
        if (str_contains($n, 'sealed vinyl') || str_contains($n, 'new vinyl')) return 'cat-vinyl-sealed';
        if (str_contains($n, 'vinyl'))                                          return 'cat-vinyl-used';
        if (str_contains($n, '7"') || str_contains($n, '45 rpm') || str_contains($n, '7 inch')) return 'cat-7inch';
        if (str_contains($n, '8 track') || str_contains($n, '8-track') || str_contains($n, 'eight track')) return 'cat-8track';
        if (str_contains($n, 'sealed cd') || str_contains($n, 'cd (sealed)') || str_contains($n, 'new cd')) return 'cat-cd-sealed';
        if (str_contains($n, 'cd'))                                             return 'cat-cd-used';
        if (str_contains($n, 'sealed cassette') || str_contains($n, 'cassettes - sealed') || str_contains($n, 'new cassette')) return 'cat-cassette-sealed';
        if (str_contains($n, 'cassette'))                                       return 'cat-cassette-used';
        if (str_contains($n, 'vhs'))                                            return 'cat-vhs';
        if (str_contains($n, 'laser'))                                          return 'cat-laserdisc';
        if (str_contains($n, 'dvd') || str_contains($n, 'blu'))                 return 'cat-dvd';
        if (str_contains($n, 'movie'))                                          return 'cat-movies';
        if (str_contains($n, 'book') || str_contains($n, 'magazine'))           return 'cat-books';
        if (str_contains($n, 'trading'))                                        return 'cat-trading';
        if (str_contains($n, 'apparel') || str_contains($n, 'clothing'))        return 'cat-apparel';
        if (str_contains($n, 'video game'))                                     return 'cat-vgame';
        if (str_contains($n, 'record player') || str_contains($n, 'audio gear'))return 'cat-gear';
        if (str_contains($n, 'gift') || str_contains($n, 'toy'))                return 'cat-gift';
        if (str_contains($n, 'poster') || str_contains($n, 'picture'))          return 'cat-poster';
        if (str_contains($n, 'accessor') || str_contains($n, 'novelt'))         return 'cat-accessory';
        return '';
    };
@endphp

<section class="content-header">
    <h1>Recent Sales Feed</h1>
</section>

@if(!empty($tz_debug))
<section class="content">
    <div style="background:#FFF8E1; border:1px solid #E6D58A; border-radius:8px; padding:12px 14px; font-family:ui-monospace,Menlo,monospace; font-size:11px; color:#5A5045; line-height:1.5;">
        <div style="font-weight:700; font-size:12px; color:#1F1B16; margin-bottom:6px;">TZ diagnostic</div>
        <div>config(app.timezone) = <strong>{{ $tz_debug['app_tz'] }}</strong> · PHP default = <strong>{{ $tz_debug['php_default_tz'] }}</strong></div>
        <div>now (LA) = <strong>{{ $tz_debug['now_la'] }}</strong> · now (app TZ) = <strong>{{ $tz_debug['now_in_app_tz'] }}</strong></div>
        <div>Today filter (in app TZ): <strong>{{ $tz_debug['today_filter_start'] }}</strong> → <strong>{{ $tz_debug['today_filter_end'] }}</strong></div>
        <div>Rows matching today filter: <strong>{{ $tz_debug['today_bucket_count'] }}</strong></div>
        <div style="margin-top:8px; font-weight:700; color:#1F1B16;">Most recent 8 clover_payments rows (regardless of date):</div>
        @foreach($tz_debug['samples'] as $i => $s)
            <div style="margin-top:4px; padding-left:6px; border-left:2px solid #E6D58A;">
                <div>#{{ $i + 1 }} paid_at = <strong>{{ $s['paid_at_raw'] }}</strong> · paid_on = <strong>{{ $s['paid_on_raw'] }}</strong> · loc={{ $s['loc_id'] ?? '(null)' }} · $${{ $s['amount'] }}</div>
                <div style="padding-left:14px;">createdTime (Clover, UTC unix-ms) = {{ $s['createdMs'] ?: '—' }} → {{ $s['createdUtc'] ?: '—' }} → {{ $s['createdLa'] ?: '—' }}</div>
                <div style="padding-left:14px;">parse(paid_at, appTz) → LA = <strong>{{ $s['parsedAsAppTz'] }}</strong></div>
            </div>
        @endforeach
    </div>
</section>
@endif

<section class="content">
    @php
        $byStore = $today_by_store ?? [];
        $totErp = 0; $totErpCount = 0; $totClover = 0; $totCloverCount = 0;
        $totWhatnot = 0; $totWhatnotCount = 0;
        foreach ($byStore as $s) {
            $totErp           += $s['erp_net'];
            $totErpCount      += $s['erp_count'] ?? 0;
            $totClover        += $s['clover'];
            $totCloverCount   += $s['clover_count'] ?? 0;
            $totWhatnot       += $s['whatnot_net'] ?? 0;
            $totWhatnotCount  += $s['whatnot_count'] ?? 0;
        }
        $totDiff = round($totClover - $totErp, 2);
        $totMatched = abs($totDiff) < 0.01;
        $totPct = $totClover > 0 ? abs($totDiff) / $totClover : 0;
        $todayLabel = \Carbon\Carbon::now('America/Los_Angeles')->format('l, M j');
    @endphp
    {{-- Date navigation: prev / today / next day. Always visible so the
         user can step back through days even when the current day's banner
         is empty (no activity yet). --}}
    <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px;">
        <a href="{{ action('SellPosController@recentSalesFeed', array_filter(['date' => $prev_date, 'location_id' => $location_id, 'created_by' => $created_by, 'discrepancy' => $discrepancy])) }}"
           style="display:inline-flex; align-items:center; gap:6px; padding:8px 14px; background:#FFFFFF; border:1px solid #DFD2B3; border-radius:8px; color:#1F1B16; font-weight:600; font-size:13px; text-decoration:none;">
            ← Previous day
        </a>
        <div style="flex:1; text-align:center;">
            <div style="font-size:18px; font-weight:700; color:#1F1B16;">{{ $day_label }}</div>
            @if($is_today)
                <div style="font-size:11px; color:#2E6F40; font-weight:700; letter-spacing:.06em; text-transform:uppercase; margin-top:2px;">● Live · auto-refresh 30s</div>
            @else
                <div style="font-size:11px; color:#8A7C6A; margin-top:2px;">
                    <a href="{{ action('SellPosController@recentSalesFeed', array_filter(['location_id' => $location_id, 'created_by' => $created_by, 'discrepancy' => $discrepancy])) }}"
                       style="color:#8B6A1A; text-decoration:underline;">Jump to today</a>
                </div>
            @endif
        </div>
        @if($allow_next)
            <a href="{{ action('SellPosController@recentSalesFeed', array_filter(['date' => $next_date, 'location_id' => $location_id, 'created_by' => $created_by, 'discrepancy' => $discrepancy])) }}"
               style="display:inline-flex; align-items:center; gap:6px; padding:8px 14px; background:#FFFFFF; border:1px solid #DFD2B3; border-radius:8px; color:#1F1B16; font-weight:600; font-size:13px; text-decoration:none;">
                Next day →
            </a>
        @else
            <span style="display:inline-flex; align-items:center; gap:6px; padding:8px 14px; background:#F7F1E3; border:1px solid #ECE3CF; border-radius:8px; color:#BFB096; font-weight:600; font-size:13px; cursor:not-allowed;" title="Already on the most recent day">
                Next day →
            </span>
        @endif
    </div>

    @if(!empty($byStore))
        @php
            // Sarah 2026-05-12: pre-compute per-store status tiers so the
            // banner can shout when any store is off. Tiers:
            //   matched   $0.00 exact — green ✓
            //   rounding  $0.01-$0.99 — yellow ◐ (tax/fee dust, low concern)
            //   off       ≥$1.00      — RED ⚠ (real keying error, fix it)
            // Threshold aligns with Sarah's 429d301 "≥\$1 = keying error" rule.
            //
            // Grace period: Clover charges in the last 10 min that have no
            // ERP ring yet (pending) are subtracted from the diff before
            // tier classification — cashiers often run the card a beat
            // before they ring the sale. The raw Diff column still shows
            // the un-adjusted number so Sarah sees the live state.
            $storeStatus = [];
            $offStores = [];
            $roundingStores = [];
            $pendingStores = [];
            foreach ($byStore as $sk => $sv) {
                $rawDiff = round($sv['clover'] - $sv['erp_net'], 2);
                $pendAmt = (float) ($pending_amount_by_store[$sk] ?? 0);
                $pendCnt = (int) ($pending_count_by_store[$sk] ?? 0);
                $adjDiff = round($rawDiff - $pendAmt, 2);
                if ($pendCnt > 0) {
                    $pendingStores[] = ['name' => $sv['name'], 'amount' => $pendAmt, 'count' => $pendCnt];
                }
                if (abs($adjDiff) < 0.01) {
                    $tier = 'matched';
                } elseif (abs($adjDiff) < 1.00) {
                    $tier = 'rounding';
                    $roundingStores[] = $sv['name'];
                } else {
                    $tier = 'off';
                    $offStores[] = ['name' => $sv['name'], 'diff' => $adjDiff];
                }
                $storeStatus[$sk] = [
                    'tier'     => $tier,
                    'diff'     => $rawDiff,
                    'adj_diff' => $adjDiff,
                    'pending'  => $pendAmt,
                    'pending_n'=> $pendCnt,
                ];
            }
            $anyOff = !empty($offStores);
            $anyPending = !empty($pendingStores);
        @endphp

        <div style="background:#FFFFFF; border:1px solid {{ $anyOff ? '#D94B4B' : '#ECE3CF' }}; border-width:{{ $anyOff ? '2px' : '1px' }}; border-radius:12px; padding:0; margin-bottom:16px; box-shadow:0 1px 3px rgba(31,27,22,.08); overflow:hidden;">

            {{-- ALARM STRIP — only shown when ≥$1 discrepancy exists. Sized
                 big and red so Sarah can't miss it. Lists which stores are
                 off and by how much before she reads any other number. Diff
                 used here is *after* subtracting pending Clover so the
                 10-min grace doesn't trip false red alarms. --}}
            @if($anyOff)
                <div style="background:#D94B4B; color:#FFFFFF; padding:10px 20px; font-size:14px; font-weight:700; letter-spacing:.02em; display:flex; gap:14px; align-items:center; flex-wrap:wrap;">
                    <span style="font-size:18px;">⚠</span>
                    <span style="text-transform:uppercase; letter-spacing:.08em;">{{ count($offStores) }} store{{ count($offStores) === 1 ? '' : 's' }} off{{ $is_today ? ' today' : '' }}</span>
                    <span style="opacity:.85; font-weight:600;">·</span>
                    @foreach($offStores as $os)
                        <span style="font-variant-numeric:tabular-nums;">{{ $os['name'] }} {{ $os['diff'] > 0 ? '+' : '' }}${{ number_format($os['diff'], 2) }}</span>
                        @if(!$loop->last)<span style="opacity:.85;">·</span>@endif
                    @endforeach
                </div>
            @elseif(!empty($roundingStores) || $totErp > 0 || $totClover > 0)
                <div style="background:#E8F3EA; color:#1F5A2E; padding:8px 20px; font-size:13px; font-weight:700; letter-spacing:.02em; display:flex; gap:10px; align-items:center;">
                    <span style="font-size:16px;">✓</span>
                    <span style="text-transform:uppercase; letter-spacing:.06em;">All stores reconciled{{ empty($roundingStores) ? '' : ' (minor rounding only)' }}</span>
                </div>
            @endif

            {{-- PENDING strip — shown whenever there's a Clover charge in the
                 last 10 min with no ERP ring yet. Lives below any alarm/✓
                 strip so it's always visible. Yellow ⏱ to signal "soft hold,
                 not a problem yet". --}}
            @if($anyPending)
                <div style="background:#FDF2D7; color:#7A5A12; padding:8px 20px; font-size:13px; font-weight:700; letter-spacing:.02em; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <span style="font-size:16px;">⏱</span>
                    <span style="text-transform:uppercase; letter-spacing:.06em;">Pending — give it a minute:</span>
                    @foreach($pendingStores as $ps)
                        <span style="font-variant-numeric:tabular-nums;">{{ $ps['name'] }} ${{ number_format($ps['amount'], 2) }} ({{ $ps['count'] }})</span>
                        @if(!$loop->last)<span style="opacity:.7;">·</span>@endif
                    @endforeach
                    <span style="opacity:.7; font-weight:600;">— last-10-min Clover, alarm held off</span>
                </div>
            @endif

            <div style="padding:18px 24px;">
                {{-- Per-store rows. Tinted + left-bordered by reconcile
                     status so discrepancies pop at a glance. --}}
                <div>
                    @foreach($byStore as $sk => $s)
                        @php
                            $stat = $storeStatus[$sk];
                            $sDiff = $stat['diff'];
                            $sTier = $stat['tier'];
                            $sPct = $s['clover'] > 0 ? abs($sDiff) / $s['clover'] : 0;
                            $sWhatnot = (float) ($s['whatnot_net'] ?? 0);
                            $sWhatnotCount = (int) ($s['whatnot_count'] ?? 0);
                            // Visual treatment per tier.
                            $accent  = $sTier === 'off' ? '#D94B4B' : ($sTier === 'rounding' ? '#C99A2A' : '#2E6F40');
                            $rowBg   = $sTier === 'off' ? '#FDF1F1' : ($sTier === 'rounding' ? '#FDF9EC' : 'transparent');
                            $badgeBg = $sTier === 'off' ? '#D94B4B' : ($sTier === 'rounding' ? '#C99A2A' : '#2E6F40');
                            $badgeIcon = $sTier === 'off' ? '⚠' : ($sTier === 'rounding' ? '◐' : '✓');
                            $badgeText = $sTier === 'off'
                                ? 'OFF BY ' . ($sDiff > 0 ? '+' : '') . '$' . number_format($sDiff, 2)
                                : ($sTier === 'rounding' ? 'ROUNDING' : 'MATCHED');
                        @endphp
                        <div style="display:flex; gap:24px; align-items:center; flex-wrap:wrap; padding:14px 14px 14px 16px; margin:0 -8px 6px -8px; border-left:5px solid {{ $accent }}; background:{{ $rowBg }}; border-radius:6px;">
                            <div style="min-width:160px;">
                                <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                    <span style="font-size:14px; font-weight:700; color:#1F1B16; text-transform:uppercase; letter-spacing:.06em;">{{ $s['name'] }}</span>
                                </div>
                                <div style="margin-top:4px;">
                                    <span style="display:inline-block; background:{{ $badgeBg }}; color:#FFFFFF; font-size:11px; font-weight:700; letter-spacing:.04em; padding:3px 8px; border-radius:4px; font-variant-numeric:tabular-nums;">{{ $badgeIcon }} {{ $badgeText }}</span>
                                </div>
                            </div>
                            <div style="flex:1; min-width:140px;">
                                <div style="font-size:11px; color:#5A5045; font-weight:600; text-transform:uppercase; letter-spacing:.06em;">ERP Sales</div>
                                <div style="font-size:26px; font-weight:700; color:#1F1B16; font-variant-numeric: tabular-nums;">${{ number_format($s['erp_net'], 2) }}</div>
                                <div style="font-size:11px; color:#8A7C6A;">{{ $s['erp_count'] ?? 0 }} sale{{ ($s['erp_count'] ?? 0) === 1 ? '' : 's' }}</div>
                            </div>
                            <div style="flex:1; min-width:140px;">
                                <div style="font-size:11px; color:#5A5045; font-weight:600; text-transform:uppercase; letter-spacing:.06em;">Clover Sales</div>
                                <div style="font-size:26px; font-weight:700; color:#1F1B16; font-variant-numeric: tabular-nums;">${{ number_format($s['clover'], 2) }}</div>
                                <div style="font-size:11px; color:#8A7C6A;">{{ $s['clover_count'] ?? 0 }} charge{{ ($s['clover_count'] ?? 0) === 1 ? '' : 's' }}</div>
                            </div>
                            <div style="flex:1; min-width:140px;">
                                <div style="font-size:11px; color:#5A5045; font-weight:600; text-transform:uppercase; letter-spacing:.06em;">Diff</div>
                                @if($sTier === 'matched')
                                    <div style="font-size:26px; font-weight:700; color:#2E6F40; font-variant-numeric: tabular-nums;">$0.00</div>
                                    <div style="font-size:11px; color:#2E6F40; font-weight:600;">✓ Matched</div>
                                @else
                                    <div style="font-size:26px; font-weight:800; color:{{ $accent }}; font-variant-numeric: tabular-nums;">{{ $sDiff > 0 ? '+' : '' }}${{ number_format($sDiff, 2) }}</div>
                                    <div style="font-size:11px; color:{{ $accent }}; font-weight:700;">{{ $sDiff > 0 ? 'Clover ahead' : 'ERP ahead' }} · {{ number_format($sPct * 100, 1) }}%</div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Sarah 2026-05-12: dedicated Whatnot row(s). Same big-number
                     layout as ERP/Clover rows but only the ERP column —
                     Whatnot doesn't hit Clover, so no Diff to compute. One
                     row per store with Whatnot activity that day. Purple
                     accent so it reads as a separate channel from the main
                     ERP-vs-Clover reconciliation. --}}
                @php
                    $whatnotStores = [];
                    foreach ($byStore as $sk => $sv) {
                        if ((int) ($sv['whatnot_count'] ?? 0) > 0) {
                            $whatnotStores[$sk] = $sv;
                        }
                    }
                @endphp
                @if(!empty($whatnotStores))
                    <div style="margin-top:10px; padding-top:10px; border-top:1px dashed #ECE3CF;">
                        @foreach($whatnotStores as $sk => $s)
                            <div style="display:flex; gap:24px; align-items:center; flex-wrap:wrap; padding:14px 14px 14px 16px; margin:0 -8px 6px -8px; border-left:5px solid #7B3FA0; background:#FAF4FF; border-radius:6px;">
                                <div style="min-width:160px;">
                                    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                                        <span style="font-size:14px; font-weight:700; color:#1F1B16; text-transform:uppercase; letter-spacing:.06em;">Whatnot · {{ $s['name'] }}</span>
                                    </div>
                                    <div style="margin-top:4px;">
                                        <span style="display:inline-block; background:#7B3FA0; color:#FFFFFF; font-size:11px; font-weight:700; letter-spacing:.04em; padding:3px 8px; border-radius:4px;">📺 LIVESTREAM</span>
                                    </div>
                                </div>
                                <div style="flex:1; min-width:140px;">
                                    <div style="font-size:11px; color:#5A5045; font-weight:600; text-transform:uppercase; letter-spacing:.06em;">ERP Sales</div>
                                    <div style="font-size:26px; font-weight:700; color:#1F1B16; font-variant-numeric: tabular-nums;">${{ number_format($s['whatnot_net'] ?? 0, 2) }}</div>
                                    <div style="font-size:11px; color:#8A7C6A;">{{ (int) ($s['whatnot_count'] ?? 0) }} sale{{ ((int) ($s['whatnot_count'] ?? 0)) === 1 ? '' : 's' }}</div>
                                </div>
                                <div style="flex:1; min-width:140px;">
                                    <div style="font-size:11px; color:#5A5045; font-weight:600; text-transform:uppercase; letter-spacing:.06em;">Clover Sales</div>
                                    <div style="font-size:20px; font-weight:600; color:#BFB096; font-variant-numeric: tabular-nums;">—</div>
                                    <div style="font-size:11px; color:#8A7C6A; font-style:italic;">paid through Whatnot</div>
                                </div>
                                <div style="flex:1; min-width:140px;">
                                    <div style="font-size:11px; color:#5A5045; font-weight:600; text-transform:uppercase; letter-spacing:.06em;">Diff</div>
                                    <div style="font-size:20px; font-weight:600; color:#BFB096; font-variant-numeric: tabular-nums;">N/A</div>
                                    <div style="font-size:11px; color:#8A7C6A; font-style:italic;">inventory-only channel</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                @if(count($byStore) > 1)
                    @php $allStoresAccent = abs($totDiff) >= 1.00 ? '#D94B4B' : (abs($totDiff) >= 0.01 ? '#C99A2A' : '#2E6F40'); @endphp
                    <div style="margin-top:8px; padding:10px 14px; border-top:2px solid #ECE3CF; display:flex; gap:24px; align-items:baseline; flex-wrap:wrap; font-size:12px; color:#5A5045; background:#FAF6EE; border-radius:6px;">
                        <div style="min-width:160px; font-weight:700; color:#1F1B16; text-transform:uppercase; letter-spacing:.06em; font-size:12px;">All stores</div>
                        <div style="flex:1; min-width:140px; font-variant-numeric:tabular-nums;">ERP <strong style="color:#1F1B16;">${{ number_format($totErp, 2) }}</strong> · {{ $totErpCount }}</div>
                        <div style="flex:1; min-width:140px; font-variant-numeric:tabular-nums;">Clover <strong style="color:#1F1B16;">${{ number_format($totClover, 2) }}</strong> · {{ $totCloverCount }}</div>
                        <div style="flex:1; min-width:140px; font-variant-numeric:tabular-nums; font-weight:800; color:{{ $allStoresAccent }}; font-size:14px;">
                            Diff {{ $totMatched ? '$0.00 ✓' : (($totDiff > 0 ? '+' : '') . '$' . number_format($totDiff, 2)) }}
                        </div>
                    </div>
                @endif


            @php $anyCharges = false; foreach ($byStore as $_s) { if (!empty($_s['clover_charges'])) { $anyCharges = true; break; } } @endphp
            @if($anyCharges)
                <details style="margin-top:12px;">
                    <summary style="cursor:pointer; font-size:12px; color:#8A7C6A; list-style:none;">▸ Show every Clover charge today ({{ $totCloverCount }})</summary>
                    <div style="margin-top:10px;">
                        @foreach($byStore as $s)
                            @if(!empty($s['clover_charges']))
                                <div style="margin-top:8px; border-top:1px dashed #ECE3CF; padding-top:8px;">
                                    <div style="font-weight:600; color:#1F1B16; margin-bottom:4px; font-size:12px;">
                                        {{ $s['name'] }} · {{ count($s['clover_charges']) }} charge{{ count($s['clover_charges']) === 1 ? '' : 's' }}
                                        <span style="color:#8A7C6A; font-weight:400;">(gross ${{ number_format(array_sum(array_column($s['clover_charges'], 'amount')), 2) }})</span>
                                    </div>
                                    <table style="width:100%; font-size:11px; font-variant-numeric:tabular-nums; border-collapse:collapse;">
                                        <thead>
                                            <tr style="color:#8A7C6A; text-align:left;">
                                                <th style="padding:2px 6px; font-weight:500;">Time</th>
                                                <th style="padding:2px 6px; font-weight:500; text-align:right;">Gross</th>
                                                <th style="padding:2px 6px; font-weight:500; text-align:right;">Net</th>
                                                <th style="padding:2px 6px; font-weight:500;">Clover employee</th>
                                                <th style="padding:2px 6px; font-weight:500;">Card</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($s['clover_charges'] as $c)
                                                <tr>
                                                    <td style="padding:2px 6px; color:#5A5045;">{{ \Carbon\Carbon::parse($c['paid_at'])->format('g:i a') }}</td>
                                                    <td style="padding:2px 6px; text-align:right;">${{ number_format($c['amount'], 2) }}</td>
                                                    <td style="padding:2px 6px; text-align:right; color:#5A5045;">${{ number_format($c['net'], 2) }}</td>
                                                    <td style="padding:2px 6px; color:#5A5045;">{{ $c['employee'] ?: '—' }}</td>
                                                    <td style="padding:2px 6px; color:#5A5045;">{{ $c['card'] ?: '—' }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </details>
            @endif
            </div>{{-- /inner padding wrapper --}}
        </div>
    @endif
</section>

<section class="content">
    <div class="rf-wrap">
        <form method="GET" action="{{ action('SellPosController@recentSalesFeed') }}" class="rf-filters">
            {{-- Persist the chosen date through filter changes so picking
                 a different employee doesn't bounce back to today. --}}
            <input type="hidden" name="date" value="{{ $dateStr }}">
            <div style="min-width: 180px;">
                <label for="rf-location">Store</label>
                <select name="location_id" id="rf-location" class="form-control" onchange="this.form.submit()">
                    <option value="">All stores</option>
                    @foreach($business_locations as $id => $name)
                        <option value="{{ $id }}" {{ (string)$location_id === (string)$id ? 'selected' : '' }}>{{ $name }}</option>
                    @endforeach
                </select>
            </div>
            <div style="min-width: 200px;">
                <label for="rf-employee">Employee</label>
                <select name="created_by" id="rf-employee" class="form-control" onchange="this.form.submit()">
                    <option value="">All employees</option>
                    @foreach($employees as $id => $name)
                        <option value="{{ $id }}" {{ (string)$created_by === (string)$id ? 'selected' : '' }}>{{ trim($name) }}</option>
                    @endforeach
                </select>
            </div>
            {{-- Sarah 2026-05-12: limit dropdown removed in day-mode rework
                 (the page now shows the full day). Field kept hidden so
                 deep links carrying ?limit=N still parse. --}}
            <input type="hidden" name="limit" value="{{ $limit }}">
            <div style="min-width: 220px;">
                <label for="rf-discrepancy">Clover sync</label>
                <select name="discrepancy" id="rf-discrepancy" class="form-control" onchange="this.form.submit()">
                    <option value=""           {{ $discrepancy === ''           ? 'selected' : '' }}>All sales + Clover orphans</option>
                    <option value="any"        {{ $discrepancy === 'any'        ? 'selected' : '' }}>Any discrepancy</option>
                    <option value="mismatch"   {{ $discrepancy === 'mismatch'   ? 'selected' : '' }}>Mismatches only (ERP ≠ Clover)</option>
                    <option value="no_clover"  {{ $discrepancy === 'no_clover'  ? 'selected' : '' }}>ERP only (no Clover match)</option>
                    <option value="no_erp"     {{ $discrepancy === 'no_erp'     ? 'selected' : '' }}>Clover only (no ERP match)</option>
                </select>
            </div>
            @php
                $cloverShown = $show_clover_only ? $unclaimed_clover_payments->count() : 0;
                $rowsShown = $sales->count() + $cloverShown;
            @endphp
            <div class="rf-count">
                {{ $rowsShown }} row{{ $rowsShown === 1 ? '' : 's' }}@if($cloverShown > 0) <span style="color:#8B6A1A;">({{ $cloverShown }} Clover-only)</span>@endif
            </div>
            <button type="submit" class="rf-export"
                    formaction="{{ action('SellPosController@recentSalesFeedExport') }}"
                    title="Download these sales as CSV (one row per item)">
                Export CSV
            </button>
        </form>

        {{-- Discrepancy summary across the scanned pool. Always shown so the user
             knows whether it's worth flipping the filter on, and whether to widen
             the date range / cashier filter. Reset link clears the filter. --}}
        @if($scanned_count > 0 || $no_erp_count > 0)
            @php
                $matched_count = max(0, $scanned_count - $mismatch_count - $no_clover_count);
                $isFiltered = $discrepancy !== '';
            @endphp
            <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;
                        background:{{ $isFiltered ? '#FFF3E0' : '#F7F1E3' }};
                        border:1px solid {{ $isFiltered ? '#E6B98A' : '#DFD2B3' }};
                        border-radius:8px;padding:8px 14px;margin-bottom:14px;
                        font-size:12px;color:#5A5045;">
                <span><strong>{{ number_format($scanned_count) }}</strong> sale{{ $scanned_count === 1 ? '' : 's' }} on {{ $day_label }}:</span>
                <span><span style="color:#1F8B3F;font-weight:700;">{{ number_format($matched_count) }}</span> matched</span>
                <span><span style="color:#B0451A;font-weight:700;">{{ number_format($mismatch_count) }}</span> mismatch{{ $mismatch_count === 1 ? '' : 'es' }}</span>
                <span><span style="color:#8B6A1A;font-weight:700;">{{ number_format($no_clover_count) }}</span> ERP only (no Clover)</span>
                <span><span style="color:#7B3FA0;font-weight:700;">{{ number_format($no_erp_count) }}</span> Clover only (no ERP)</span>
                @if(!empty($orphan_by_loc) || ($orphan_null_loc ?? 0) > 0)
                    @php
                        $parts = [];
                        if (($orphan_null_loc ?? 0) > 0) {
                            $parts[] = '<span style="color:#8B2C2C;font-weight:700;">' . number_format($orphan_null_loc) . ' null-loc</span> <span style="color:#8A7C6A;">(historical sync, backfillable)</span>';
                        }
                        foreach (($orphan_by_loc ?? []) as $lid => $n) {
                            $name = $business_locations[$lid] ?? ('loc ' . $lid);
                            $parts[] = '<strong>' . number_format($n) . '</strong> at ' . e($name);
                        }
                    @endphp
                    <span style="width:100%;color:#5A5045;font-size:11px;padding-left:2px;">
                        ↳ orphan breakdown: {!! implode(' · ', $parts) !!}
                    </span>
                @endif
                @if($isFiltered)
                    <a href="{{ action('SellPosController@recentSalesFeed', array_filter(['location_id' => $location_id, 'created_by' => $created_by, 'limit' => $limit])) }}"
                       style="margin-left:auto;color:#8B6A1A;text-decoration:underline;font-weight:600;">
                        Clear discrepancy filter
                    </a>
                @endif
            </div>
        @endif

        @if(!empty($clover_debug))
            <div style="background:#FFF8E1;border:1px solid #E6D58A;border-radius:8px;padding:12px 14px;margin-bottom:14px;font-family:ui-monospace,Menlo,monospace;font-size:11px;color:#5A5045;line-height:1.5;">
                <div style="font-weight:700;font-size:12px;color:#1F1B16;margin-bottom:6px;">Clover match diagnostics</div>
                <div>Visible sales: <strong>{{ $clover_debug['sale_count'] }}</strong> · Clover payments in window: <strong>{{ $clover_debug['clover_payment_count'] }}</strong> · Matched: <strong>{{ $clover_debug['matched_tx_count'] }}</strong></div>
                <div>Clover data spans: <strong>{{ $clover_debug['clover_window_min'] }}</strong> → <strong>{{ $clover_debug['clover_window_max'] }}</strong></div>
                <div style="margin-top:4px;color:#8A7C6A;">Match rule: same store + same dollar amount (±$0.01 tolerance for tax rounding). Closest-time-to-the-ERP-sale is the tiebreaker. Cashier tender is ignored.</div>
                @if(!empty($clover_debug['unclaimed_sales']))
                    <div style="margin-top:10px;font-weight:700;color:#1F1B16;">Unmatched sales (newest first), 3 closest Clover candidates from the same day:</div>
                    @foreach($clover_debug['unclaimed_sales'] as $u)
                        <div style="margin-top:6px;padding-left:4px;border-left:2px solid #E6D58A;">
                            <div><strong>ERP</strong> ${{ number_format($u['amount'], 2) }} · {{ $u['ts'] }} · #{{ $u['invoice_no'] }} · loc {{ $u['loc_id'] }}</div>
                            @if(empty($u['closest_clover']))
                                <div style="color:#B0451A;">→ no Clover payments at all on the same day (probably real cash)</div>
                            @else
                                @foreach($u['closest_clover'] as $c)
                                    <div style="padding-left:14px;color:{{ $c['why'] === 'WOULD MATCH' ? '#1F8B3F' : '#8A7C6A' }};">
                                        → ${{ number_format($c['amount'], 2) }} · {{ $c['paid_at'] }} · loc {{ $c['loc_id'] ?? '(none)' }} · {{ $c['card'] ?: '(no card)' }} · <em>{{ $c['why'] }}</em>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    @endforeach
                @endif
                @if(!empty($clover_debug['unclaimed_clover']))
                    <div style="margin-top:10px;font-weight:700;color:#1F1B16;">Unclaimed Clover payments (charges with no matching ERP sale, newest first, top 20):</div>
                    @foreach($clover_debug['unclaimed_clover'] as $c)
                        <div style="padding-left:4px;">• ${{ number_format($c['amount'], 2) }} · {{ $c['paid_at'] }} · loc {{ $c['loc_id'] ?? '(none)' }} · {{ $c['card'] ?: '(no card)' }}</div>
                    @endforeach
                @endif
            </div>
        @endif

        {{-- Sarah 2026-05-12: 2-column day-mode layout. Each active store
             gets its own column with its sales + Clover orphans interleaved
             chronologically. LA-normalized epoch sort, same as before — see
             parseCloverPaidAtLa for the mixed-TZ background. --}}
        @php
            // Build per-store $feedItems collections. Stores with no
            // activity show an empty-state card instead of being hidden.
            $store_feeds = [];
            $store_id_list = (isset($store_order) && is_array($store_order))
                ? $store_order
                : array_keys($business_locations);
            // Filter out catch-all '0' bucket from headers, but keep its
            // items in a virtual "Unattributed" column if any rows live there.
            $visible_store_ids = [];
            foreach ($store_id_list as $sid) {
                $hasSales = isset($sales_by_store[$sid]) && $sales_by_store[$sid]->isNotEmpty();
                $hasOrph  = isset($orphans_by_store[$sid]) && $orphans_by_store[$sid]->isNotEmpty();
                $hasPend  = isset($pending_by_store[$sid]) && $pending_by_store[$sid]->isNotEmpty();
                if ($sid === 0 && !$hasOrph && !$hasPend) continue;
                $visible_store_ids[] = $sid;
            }
            // If a location_id filter is active, show only that store.
            if (!empty($location_id)) {
                $visible_store_ids = array_values(array_filter($visible_store_ids, fn($s) => (int) $s === (int) $location_id));
            }
            foreach ($visible_store_ids as $sid) {
                $items = collect();
                foreach (($sales_by_store[$sid] ?? collect()) as $s) {
                    // Apply discrepancy filter at render time so a "mismatch
                    // only" filter still uses the same per-store buckets.
                    if ($discrepancy === 'mismatch') {
                        $info = $clover_by_transaction[$s->id] ?? null;
                        $isMismatch = $info !== null && abs($info['amount_cents'] - (int) round((float) $s->final_total * 100)) > 5;
                        if (!$isMismatch) continue;
                    } elseif ($discrepancy === 'no_clover') {
                        if (isset($clover_by_transaction[$s->id])) continue;
                    } elseif ($discrepancy === 'any') {
                        $info = $clover_by_transaction[$s->id] ?? null;
                        $isMismatch = $info !== null && abs($info['amount_cents'] - (int) round((float) $s->final_total * 100)) > 5;
                        $isNoClover = $info === null;
                        if (!$isMismatch && !$isNoClover) continue;
                    } elseif ($discrepancy === 'no_erp') {
                        continue; // Hide all ERP rows in no_erp mode
                    }
                    $erpTs = 0;
                    try { $erpTs = \Carbon\Carbon::parse($s->transaction_date)->getTimestamp(); } catch (\Throwable $e) {}
                    $items->push(['type' => 'erp', 'sale' => $s, 'ts' => $erpTs]);
                }
                if ($show_clover_only) {
                    foreach (($orphans_by_store[$sid] ?? collect()) as $cp) {
                        $cpTs = 0;
                        try { $cpTs = \App\Http\Controllers\SellPosController::parseCloverPaidAtLa($cp)->getTimestamp(); } catch (\Throwable $e) {}
                        $items->push(['type' => 'clover', 'cp' => $cp, 'ts' => $cpTs, 'pending' => false]);
                    }
                    foreach (($pending_by_store[$sid] ?? collect()) as $cp) {
                        $cpTs = 0;
                        try { $cpTs = \App\Http\Controllers\SellPosController::parseCloverPaidAtLa($cp)->getTimestamp(); } catch (\Throwable $e) {}
                        $items->push(['type' => 'clover', 'cp' => $cp, 'ts' => $cpTs, 'pending' => true]);
                    }
                }
                $store_feeds[$sid] = $items->sortByDesc('ts')->values();
            }
        @endphp

        @if(count($visible_store_ids) === 0)
            <div class="rf-day-empty">No sales or Clover activity for {{ $day_label }}.</div>
        @else
        <div class="rf-day-grid" style="{{ count($visible_store_ids) === 1 ? 'grid-template-columns: 1fr; max-width: 860px; margin: 0 auto;' : '' }}">
        @foreach($visible_store_ids as $sid)
            @php
                $colItems = $store_feeds[$sid] ?? collect();
                $colName = $sid === 0 ? '(Unattributed)' : ($business_locations[$sid] ?? ('loc ' . $sid));
                $colSales = $sales_by_store[$sid] ?? collect();
                $colOrphans = $orphans_by_store[$sid] ?? collect();
                $colPending = $pending_by_store[$sid] ?? collect();
            @endphp
            <div class="rf-day-col">
                <div class="rf-day-col-head">
                    <span class="rf-day-store-name">{{ $colName }}</span>
                    <span class="rf-day-col-summary">
                        {{ $colSales->count() }} sale{{ $colSales->count() === 1 ? '' : 's' }}
                        @if($colOrphans->count() > 0)
                            · <strong style="color:#7B3FA0;">{{ $colOrphans->count() }} Clover-only</strong>
                        @endif
                        @if($colPending->count() > 0)
                            · <strong style="color:#C99A2A;">{{ $colPending->count() }} pending</strong>
                        @endif
                    </span>
                </div>

        @forelse($colItems as $item)
        @if($item['type'] === 'clover')
            @php
                $cp = $item['cp'];
                // Pass the full row, not just $cp->paid_at: parseCloverPaidAtLa
                // prefers raw_payload.createdTime (canonical UTC unix-ms from
                // Clover) and only reaches it when handed the row object.
                // Passing the string falls through to a heuristic against a
                // mixed-TZ-stored paid_at that bunches charges into wrong
                // wall-clock clusters. (Sarah 2026-05-12: 6 charges from
                // different times appearing at "9:51pm" because the heuristic
                // saw the same string for all of them.)
                $cpDt = \App\Http\Controllers\SellPosController::parseCloverPaidAtLa($cp);
                $cpWhen = $cpDt->isToday() ? $cpDt->format('g:i a') : $cpDt->format('M j · g:i a');
                $cpStore = $cp->location_id && isset($business_locations[$cp->location_id])
                    ? $business_locations[$cp->location_id]
                    : '—';
                $cpAmount = (float) $cp->amount;
                $cpCardBrand = $cp->card_type ? strtoupper($cp->card_type) : '';
                $cpCardLast4 = $cp->card_last4 ? '••' . $cp->card_last4 : '';
                $cpCardLabel = trim($cpCardBrand . ' ' . $cpCardLast4);
                $cpTax = (int) ($cp->tax_cents ?? 0);
                $cpTip = (int) ($cp->tip_cents ?? 0);
                $orphanCashierId = $cashier_for_orphan[$cp->id] ?? null;
                $orphanCashierName = $orphanCashierId ? ($cashierNameById[$orphanCashierId] ?? null) : null;
                $isPending = !empty($item['pending']);
                $dupOfTxId = $orphan_duplicate_of[$cp->id] ?? null;
            @endphp
            <div class="rf-card {{ $isPending ? 'rf-clover-pending' : 'rf-clover-orphan' }}">
                <div class="rf-head">
                    <div class="rf-head-left">
                        @if($isPending)
                            <span class="rf-invoice"><span class="rf-orphan-tag">⏱ Pending</span></span>
                        @elseif($dupOfTxId)
                            <span class="rf-invoice"><span class="rf-orphan-tag" style="background:#A88032;color:#fff;">⚠ DUPLICATE</span></span>
                        @else
                            <span class="rf-invoice"><span class="rf-orphan-tag">Clover only</span></span>
                        @endif
                        <span class="rf-time">{{ $cpWhen }}</span>
                        <span class="rf-store-badge">{{ $cpStore }}</span>
                        @if($cpCardLabel)<span class="rf-customer">· {{ $cpCardLabel }}</span>@endif
                        @if($orphanCashierName)
                            <span class="rf-cashier" title="Cashier whose pos_duty was 'cashier' at this store within 4h of the charge">· <strong>Cashier: {{ $orphanCashierName }}</strong></span>
                        @else
                            <span class="rf-cashier" style="color:#8A7C6A;">· cashier unknown</span>
                        @endif
                    </div>
                </div>
                <div class="rf-orphan-note">
                    @if($isPending)
                        Clover charged <strong>${{ number_format($cpAmount, 2) }}</strong> in the last 10 min — ERP ring not in yet. Give it a minute.
                    @elseif($dupOfTxId)
                        Clover charged <strong>${{ number_format($cpAmount, 2) }}</strong> — same Clover order as paired sale <a href="{{ url('sells/' . $dupOfTxId) }}" style="color:#1F1B16;text-decoration:underline;">#{{ $dupOfTxId }}</a>. Likely a sync-side duplicate (Clover API returned 2 payment records for one logical sale). Not a missed ring-up.
                    @else
                        Clover charged <strong>${{ number_format($cpAmount, 2) }}</strong> — no matching ERP ring.
                    @endif
                </div>
                @if(!empty($cp->clover_order_id))
                    <div style="margin:0 16px 6px 16px; font-size:11px; color:#8A7C6A;">
                        Clover order <code style="background:#F7F1E3;border:1px solid #DFD2B3;border-radius:3px;padding:1px 4px;font-size:11px;">{{ $cp->clover_order_id }}</code>
                    </div>
                @endif
                @php $nearMatches = $orphan_near_matches[$cp->clover_payment_id] ?? []; @endphp
                <div style="margin: 6px 16px 8px 16px; padding: 8px 10px; background:#FAF6EE; border:1px dashed #DFD2B3; border-radius:6px; font-size: 12px;">
                    @if(!empty($nearMatches))
                        <div style="color:#5A5045; font-weight:600; margin-bottom:4px;">Unmatched ERP sales within 1h:</div>
                        @foreach($nearMatches as $nm)
                            <div style="display:flex; gap:10px; padding:2px 0; color:#3A3128;">
                                <a href="{{ url('sells/' . $nm['tx_id']) }}" style="color:#1F1B16; text-decoration:underline;">#{{ $nm['invoice_no'] }}</a>
                                <span style="color:#5A5045;">{{ \Carbon\Carbon::parse($nm['ts'])->format('g:i a') }}</span>
                                <span style="font-variant-numeric: tabular-nums;">${{ number_format($nm['amount'], 2) }}</span>
                                <span style="color:{{ $nm['why'] === 'WOULD MATCH' ? '#8B2C2C' : '#5A5045' }}; margin-left:auto; font-style:italic;">{{ $nm['why'] }}</span>
                            </div>
                        @endforeach
                    @else
                        <div style="color:#8B2C2C; font-weight:600;">⚠ No unmatched ERP sale within 1 hour — this is a real missing ring-up.</div>
                    @endif
                </div>
                <div class="rf-foot">
                    <div class="rf-foot-meta">
                        @if(!empty($cp->clover_payment_id))
                            Clover ID <code style="background:#F7F1E3;border:1px solid #DFD2B3;border-radius:3px;padding:1px 4px;font-size:11px;">{{ $cp->clover_payment_id }}</code>
                        @endif
                        @if(!empty($cp->employee_name))
                            <span style="color:#8A7C6A;" title="Clover time-clock / terminal pin — can differ from who was on the ERP"> · Clover clock: {{ $cp->employee_name }}</span>
                        @endif
                    </div>
                    <div class="rf-recon">
                        <div class="rf-recon-col rf-recon-erp">
                            <div class="lbl">ERP</div>
                            <div class="amt" style="color:#8A7C6A;">—</div>
                        </div>
                        <div class="rf-recon-col rf-recon-clover">
                            <div class="lbl">Clover</div>
                            <div class="amt">${{ number_format($cpAmount, 2) }}</div>
                            <div class="sub">
                                @if($cpTax > 0)Tax ${{ number_format($cpTax / 100, 2) }}@endif
                                @if($cpTip > 0) · Tip ${{ number_format($cpTip / 100, 2) }}@endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        @else
            @php
                $sale = $item['sale'];
                $dt = \Carbon\Carbon::parse($sale->transaction_date);
                $isToday = $dt->isToday();
                $when = $isToday ? $dt->format('g:i a') : $dt->format('M j · g:i a');
                $customer = optional($sale->contact)->name ?: 'Walk-In Customer';
                $store = optional($sale->location)->name ?: '—';
                // sales_person -> User via created_by (who saved the sale in ERP)
                $cashier = optional($sale->sales_person)->user_full_name;
                $cashier = $cashier ? trim($cashier) : null;
                if ($cashier === null || $cashier === '') {
                    $cashier = optional($sale->sales_person)->username;
                    $cashier = $cashier ? trim((string) $cashier) : null;
                }
                $total = (float) $sale->final_total;
                $discount = (float) ($sale->discount_amount ?? 0);

                // Tender the cashier picked at checkout. Per-payment-row method
                // ('cash' / 'card' / 'bank_transfer' / 'cheque' / 'custom_pay_*' /
                // 'other'). Note (memory, 2026-04-28): Nivessa cashiers ring nearly
                // every sale as 'cash' even when the customer paid by card —
                // they manually re-enter on Clover. So this shows what the
                // cashier *chose*, which is mostly 'Cash'; the Clover column
                // tells you what actually ran.
                $methods = $sale->payment_lines->pluck('method')->filter()->unique()->values();
                $tenderClass = 'tender-other';
                $tenderLabel = '—';
                if ($methods->count() > 1) {
                    $tenderClass = 'tender-mixed';
                    $tenderLabel = 'Split';
                } elseif ($methods->count() === 1) {
                    $m = $methods->first();
                    if ($m === 'cash') { $tenderClass = 'tender-cash'; $tenderLabel = 'Cash'; }
                    elseif ($m === 'card') { $tenderClass = 'tender-card'; $tenderLabel = 'Card'; }
                    else { $tenderLabel = ucfirst(str_replace('_', ' ', $m)); }
                }
            @endphp
            <div class="rf-card">
                <div class="rf-head">
                    <div class="rf-head-left">
                        <span class="rf-invoice">
                            <a href="{{ action('SellController@show', [$sale->id]) }}">#{{ $sale->invoice_no }}</a>
                        </span>
                        <span class="rf-time">{{ $when }}</span>
                        <span class="rf-store-badge">{{ $store }}</span>
                        <span class="rf-tender {{ $tenderClass }}" title="Tender the cashier selected at checkout (cashiers usually pick Cash even for card sales — see Clover column for what actually ran)">{{ $tenderLabel }}</span>
                        <span class="rf-customer">· {{ $customer }}</span>
                        @if($cashier)<span class="rf-cashier" title="User who created this sale in the ERP (POS)">· ERP: <strong>{{ $cashier }}</strong></span>@endif
                    </div>
                </div>

                @if($sale->sell_lines->isEmpty())
                    <div class="rf-line"><span class="rf-line-name" style="color:#8A7C6A;">(no items)</span></div>
                @else
                    @php
                        // Aggregate identical sell_lines so 5 chips quick-add
                        // clicks render as "5× Chips $7.50" instead of five
                        // copies of "Chips $1.50" — each preset click adds a
                        // new line at qty=1, which made it look like Mariah's
                        // $8 ring was a single $1.50 sale. Group by
                        // (product_id|name, variation_id, unit price, discount).
                        $rfGroups = [];
                        foreach ($sale->sell_lines as $line) {
                            $product = $line->product;
                            $baseName = $product->name ?? ($line->product_name ?? 'Manual item');
                            $baseArtist = null;
                            if ($product && !empty($product->artist) && is_string($product->artist)) {
                                $baseArtist = $product->artist;
                            } elseif (!empty($line->product_artist)) {
                                $baseArtist = $line->product_artist;
                            }
                            $unit = (float) ($line->unit_price_inc_tax ?: $line->unit_price);
                            $disc = 0;
                            if (!empty($line->line_discount_amount)) {
                                $disc = $line->line_discount_type === 'percentage'
                                    ? ($unit * $line->line_discount_amount / 100)
                                    : (float) $line->line_discount_amount;
                            }
                            $key = ($product->id ?? ('m:' . $baseName))
                                . '|' . ($line->variation_id ?? '')
                                . '|' . round($unit, 2)
                                . '|' . round($disc, 2);
                            if (!isset($rfGroups[$key])) {
                                $rfGroups[$key] = [
                                    'product'    => $product,
                                    'name'       => $baseArtist ? ($baseArtist . ' — ' . $baseName) : $baseName,
                                    'isManual'   => empty($product),
                                    'catName'    => optional($product)->category->name ?? null,
                                    'subCatName' => optional($product)->sub_category->name ?? null,
                                    'qty'        => 0.0,
                                    'unit'       => $unit,
                                    'lineDisc'   => $disc,
                                    'lineTotal'  => 0.0,
                                ];
                            }
                            $rfGroups[$key]['qty']       += (float) $line->quantity;
                            $rfGroups[$key]['lineTotal'] += ($unit - $disc) * (float) $line->quantity;
                        }
                    @endphp
                    <ul class="rf-lines">
                    @foreach($rfGroups as $g)
                        @php
                            $qty       = $g['qty'];
                            $name      = $g['name'];
                            $isManual  = $g['isManual'];
                            $catName   = $g['catName'];
                            $subCatName= $g['subCatName'];
                            $unit      = $g['unit'];
                            $lineTotal = $g['lineTotal'];
                        @endphp
                        <li class="rf-line">
                            <span class="rf-line-name">
                                @if($qty > 1)<span class="qty">{{ rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') }}×</span>@endif
                                {{ $name }}
                                @if($qty > 1)<span class="qty" style="color:#8A7C6A; font-weight:400; margin-left:6px;">@ ${{ number_format($unit, 2) }}</span>@endif
                                @if($isManual)<span class="rf-manual-tag" title="Manual item (not from inventory)">manual</span>@endif
                                @if($catName)
                                    <span class="rf-line-cat {{ $rfCatClass($catName) }}">{{ $catName }}@if($subCatName)<span class="sub">› {{ $subCatName }}</span>@endif</span>
                                @endif
                            </span>
                            <span class="rf-line-price">${{ number_format($lineTotal, 2) }}</span>
                        </li>
                    @endforeach
                    </ul>
                @endif

                @php
                    $cloverInfo = $clover_by_transaction[$sale->id] ?? null;
                    // Mismatch = ERP total ≠ Clover gross (amount, which
                    // already includes tax). Tip is separate so it doesn't
                    // count as a mismatch. Compare in integer cents — float
                    // diff of $X.20 - $X.19 is 0.0100000000231, not 0.01,
                    // and the naive `> 0.01` test would lose 1¢ rounding.
                    $cloverMismatch = false;
                    if ($cloverInfo) {
                        $saleCents = (int) round($total * 100);
                        $cloverMismatch = abs($cloverInfo['amount_cents'] - $saleCents) > 1;
                    }
                @endphp
                <div class="rf-foot">
                    <div class="rf-foot-meta">
                        {{ ucfirst(str_replace('_', ' ', $sale->payment_status ?? '')) }}
                        @if($discount > 0) · discount −${{ number_format($discount, 2) }} @endif
                    </div>
                    {{-- Sarah 2026-05-12: always show ERP amount. When there's
                         no Clover pair, render the Clover column as "—" so the
                         layout stays consistent and "did this ring in ERP" is
                         answerable at a glance for every row. --}}
                    <div class="rf-recon {{ $cloverInfo && $cloverMismatch ? 'is-mismatch' : '' }}">
                        <div class="rf-recon-col rf-recon-erp">
                            <div class="lbl">ERP</div>
                            <div class="amt">${{ number_format($total, 2) }}</div>
                        </div>
                        <div class="rf-recon-col rf-recon-clover">
                            <div class="lbl">
                                Clover
                                @if($cloverInfo && $cloverMismatch)<span class="rf-recon-mismatch-tag" title="Clover charged ≠ ERP total">mismatch</span>@endif
                            </div>
                            @if($cloverInfo)
                                <div class="amt">${{ number_format($cloverInfo['amount_cents'] / 100, 2) }}</div>
                                <div class="sub">
                                    @if($cloverInfo['tax_cents'] > 0)Tax ${{ number_format($cloverInfo['tax_cents'] / 100, 2) }}@endif
                                    @if($cloverInfo['tip_cents'] > 0) · Tip ${{ number_format($cloverInfo['tip_cents'] / 100, 2) }}@endif
                                    @if(!empty($cloverInfo['cards']))<div>{{ implode(' · ', $cloverInfo['cards']) }}</div>@endif
                                </div>
                            @else
                                <div class="amt" style="color:#BFB096;">—</div>
                                <div class="sub" style="color:#8A7C6A;">no Clover match</div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @endif
        @empty
            <div class="rf-empty" style="background:#FFFFFF; border:1px dashed #DFD2B3; border-radius:10px; padding:18px; text-align:center; color:#8A7C6A; font-size:13px;">
                @if($discrepancy === 'mismatch')
                    No mismatches for this store on {{ $day_label }}.
                @elseif($discrepancy === 'no_clover')
                    Every {{ $colName }} sale paired to Clover.
                @elseif($discrepancy === 'no_erp')
                    No Clover-only orphans at {{ $colName }}.
                @elseif($discrepancy === 'any')
                    No discrepancies at {{ $colName }} on {{ $day_label }}.
                @else
                    No sales at {{ $colName }} on {{ $day_label }}.
                @endif
            </div>
        @endforelse
            </div>{{-- /rf-day-col --}}
        @endforeach
        </div>{{-- /rf-day-grid --}}
        @endif
    </div>
</section>
@endsection
