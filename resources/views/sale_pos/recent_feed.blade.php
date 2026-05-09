@extends('layouts.app')
@section('title', 'Recent Sales Feed')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>
    document.body.classList.add('pos-v2','pos-list-v2');
    // Auto-refresh every 30 seconds so the feed stays live without
    // a manual reload. Pauses while the user has a focused select/input
    // (changing filters), and skips refresh when the tab is hidden so
    // background tabs don't keep hammering the server.
    (function () {
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
    .rf-wrap { max-width: 860px; margin: 0 auto; }
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
    <h1>Recent Sales Feed <small>— items sold, expanded inline</small></h1>
</section>

<section class="content">
    <div class="rf-wrap">
        @php
            $byStore = $today_by_store ?? [];
        @endphp
        <div style="background:#FFFFFF; border:1px solid #ECE3CF; border-radius:12px; padding:18px 24px; margin-bottom:16px; box-shadow:0 1px 3px rgba(31,27,22,.08);">
            <div style="display:flex; justify-content:space-between; align-items:baseline; margin-bottom:12px;">
                <div>
                    <div style="font-size:11px; color:#5A5045; text-transform:uppercase; letter-spacing:.08em; font-weight:600;">Today's sales</div>
                    <div style="font-size:13px; color:#8A7C6A; margin-top:2px;">{{ \Carbon\Carbon::now()->format('l, M j') }}</div>
                </div>
                <div style="font-size:11px; color:#8A7C6A; max-width:280px; line-height:1.4; text-align:right;">Net Sales (pre-tax). Card vs Clover should match per store. Cash and other tenders never hit Clover.</div>
            </div>
            @if(empty($byStore))
                <div style="padding:14px 0; color:#8A7C6A; font-style:italic;">No sales yet today.</div>
            @else
                <table style="width:100%; border-collapse:collapse; font-variant-numeric: tabular-nums;">
                    <thead>
                        <tr style="border-bottom:1px solid #ECE3CF;">
                            <th style="text-align:left; font-size:11px; color:#5A5045; padding:6px 8px; text-transform:uppercase; letter-spacing:.06em;">Store</th>
                            <th style="text-align:right; font-size:11px; color:#5A5045; padding:6px 8px; text-transform:uppercase; letter-spacing:.06em;">ERP Card</th>
                            <th style="text-align:right; font-size:11px; color:#5A5045; padding:6px 8px; text-transform:uppercase; letter-spacing:.06em;">Clover</th>
                            <th style="text-align:right; font-size:11px; color:#5A5045; padding:6px 8px; text-transform:uppercase; letter-spacing:.06em;">Diff</th>
                            <th style="text-align:right; font-size:11px; color:#5A5045; padding:6px 8px; text-transform:uppercase; letter-spacing:.06em; border-left:1px dashed #DFD2B3;">ERP Cash</th>
                            <th style="text-align:right; font-size:11px; color:#5A5045; padding:6px 8px; text-transform:uppercase; letter-spacing:.06em;">ERP Other</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $totErpCard = 0; $totClover = 0; $totDiff = 0; $totCash = 0; $totOther = 0;
                        @endphp
                        @foreach($byStore as $s)
                            @php
                                $matched = abs($s['card_diff']) < 0.01;
                                $diffColor = $matched ? '#2E6F40' : '#8B2C2C';
                                $totErpCard += $s['erp_card'];
                                $totClover  += $s['clover'];
                                $totDiff    += $s['card_diff'];
                                $totCash    += $s['erp_cash'];
                                $totOther   += $s['erp_other'];
                            @endphp
                            <tr style="border-bottom:1px solid #F5EDD9;">
                                <td style="padding:10px 8px; font-weight:600; color:#1F1B16; font-size:14px;">
                                    {{ $s['name'] }}
                                    <span style="font-weight:400; color:#8A7C6A; font-size:11px;">· {{ $s['erp_tx_count'] }} sales · {{ $s['clover_count'] }} charges</span>
                                </td>
                                <td style="padding:10px 8px; text-align:right; font-size:18px; font-weight:600; color:#1F1B16;">${{ number_format($s['erp_card'], 2) }}</td>
                                <td style="padding:10px 8px; text-align:right; font-size:18px; font-weight:600; color:#1F1B16;">${{ number_format($s['clover'], 2) }}</td>
                                <td style="padding:10px 8px; text-align:right; font-size:18px; font-weight:700; color:{{ $diffColor }};">
                                    @if($matched)
                                        $0.00
                                    @else
                                        {{ $s['card_diff'] > 0 ? '+' : '' }}${{ number_format($s['card_diff'], 2) }}
                                    @endif
                                </td>
                                <td style="padding:10px 8px; text-align:right; font-size:14px; color:#5A5045; border-left:1px dashed #DFD2B3;">${{ number_format($s['erp_cash'], 2) }}</td>
                                <td style="padding:10px 8px; text-align:right; font-size:14px; color:#5A5045;">{{ $s['erp_other'] > 0 ? '$' . number_format($s['erp_other'], 2) : '—' }}</td>
                            </tr>
                        @endforeach
                        @if(count($byStore) > 1)
                            @php $totMatched = abs($totDiff) < 0.01; @endphp
                            <tr style="background:#FAF6EE; font-weight:700;">
                                <td style="padding:10px 8px; color:#1F1B16; font-size:13px; text-transform:uppercase; letter-spacing:.06em;">All stores</td>
                                <td style="padding:10px 8px; text-align:right; font-size:18px;">${{ number_format($totErpCard, 2) }}</td>
                                <td style="padding:10px 8px; text-align:right; font-size:18px;">${{ number_format($totClover, 2) }}</td>
                                <td style="padding:10px 8px; text-align:right; font-size:18px; color:{{ $totMatched ? '#2E6F40' : '#8B2C2C' }};">
                                    @if($totMatched) $0.00 @else {{ $totDiff > 0 ? '+' : '' }}${{ number_format($totDiff, 2) }} @endif
                                </td>
                                <td style="padding:10px 8px; text-align:right; font-size:14px; color:#5A5045; border-left:1px dashed #DFD2B3;">${{ number_format($totCash, 2) }}</td>
                                <td style="padding:10px 8px; text-align:right; font-size:14px; color:#5A5045;">{{ $totOther > 0 ? '$' . number_format($totOther, 2) : '—' }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</section>

<section class="content">
    @include('sale_pos.partials.pos_duty_banner')
    <div class="rf-wrap">
        <form method="GET" action="{{ action('SellPosController@recentSalesFeed') }}" class="rf-filters">
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
            <div style="min-width: 120px;">
                <label for="rf-limit">Show</label>
                <select name="limit" id="rf-limit" class="form-control" onchange="this.form.submit()">
                    @foreach([15, 30, 50, 100, 250, 500] as $n)
                        <option value="{{ $n }}" {{ (int)$limit === $n ? 'selected' : '' }}>{{ $n }} sales</option>
                    @endforeach
                </select>
            </div>
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
                <span>Scanned <strong>{{ number_format($scanned_count) }}</strong> recent sale{{ $scanned_count === 1 ? '' : 's' }}:</span>
                <span><span style="color:#1F8B3F;font-weight:700;">{{ number_format($matched_count) }}</span> matched</span>
                <span><span style="color:#B0451A;font-weight:700;">{{ number_format($mismatch_count) }}</span> mismatch{{ $mismatch_count === 1 ? '' : 'es' }}</span>
                <span><span style="color:#8B6A1A;font-weight:700;">{{ number_format($no_clover_count) }}</span> ERP only (no Clover)</span>
                <span><span style="color:#7B3FA0;font-weight:700;">{{ number_format($no_erp_count) }}</span> Clover only (no ERP)</span>
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

        @php
            // Interleave ERP sales with orphan Clover charges (when the
            // current filter allows orphans). Each item carries a 'ts'
            // for unified newest-first ordering — ERP uses transaction_date,
            // orphan Clover uses paid_at (often the next morning's batch
            // settlement, which is the right "when did the money show up"
            // moment to surface in this feed).
            $feedItems = collect();
            foreach ($sales as $s) {
                $feedItems->push(['type' => 'erp', 'sale' => $s, 'ts' => (string) $s->transaction_date]);
            }
            if ($show_clover_only) {
                foreach ($unclaimed_clover_payments as $cp) {
                    $feedItems->push(['type' => 'clover', 'cp' => $cp, 'ts' => (string) $cp->paid_at]);
                }
            }
            $feedItems = $feedItems->sortByDesc('ts')->values();
        @endphp

        @forelse($feedItems as $item)
        @if($item['type'] === 'clover')
            @php
                $cp = $item['cp'];
                // paid_at is stored in app TZ (America/Los_Angeles) by
                // SyncCloverPayments — Carbon::createFromTimestampMs picks
                // up the app default TZ, and Eloquent serializes Carbons in
                // their current TZ. The model's 'datetime' cast then reads
                // them back in app TZ, so use that directly. (Earlier code
                // re-parsed the raw value as UTC and converted to LA, which
                // subtracted 7 hours from every charge — Sarah, 2026-05-07.)
                $cpDt = $cp->paid_at instanceof \Carbon\Carbon
                    ? $cp->paid_at->copy()->setTimezone('America/Los_Angeles')
                    : \Carbon\Carbon::parse((string) $cp->paid_at)->setTimezone('America/Los_Angeles');
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
            @endphp
            <div class="rf-card rf-clover-orphan">
                <div class="rf-head">
                    <div class="rf-head-left">
                        <span class="rf-invoice"><span class="rf-orphan-tag">Clover only</span></span>
                        <span class="rf-time">{{ $cpWhen }}</span>
                        <span class="rf-store-badge">{{ $cpStore }}</span>
                        @if($cpCardLabel)<span class="rf-customer">· {{ $cpCardLabel }}</span>@endif
                        @if($orphanCashierName)
                            <span class="rf-cashier" title="Who was logged into the ERP (POS) around this charge — not who is clocked into Clover">· <strong>ERP (POS): {{ $orphanCashierName }}</strong></span>
                        @else
                            <span class="rf-cashier" style="color:#8A7C6A;">· ERP session unknown (no login in activity log near this time)</span>
                        @endif
                    </div>
                </div>
                <div class="rf-orphan-note">
                    Clover charged <strong>${{ number_format($cpAmount, 2) }}</strong> but no matching ERP sale was found for this store + amount in the scanned window. Investigate: missing ring-up, voided sale, or a charge from outside the scanned date range.
                </div>
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
                    @if($cloverInfo)
                        <div class="rf-recon {{ $cloverMismatch ? 'is-mismatch' : '' }}">
                            <div class="rf-recon-col rf-recon-erp">
                                <div class="lbl">ERP</div>
                                <div class="amt">${{ number_format($total, 2) }}</div>
                            </div>
                            <div class="rf-recon-col rf-recon-clover">
                                <div class="lbl">
                                    Clover
                                    @if($cloverMismatch)<span class="rf-recon-mismatch-tag" title="Clover charged ≠ ERP total">mismatch</span>@endif
                                </div>
                                <div class="amt">${{ number_format($cloverInfo['amount_cents'] / 100, 2) }}</div>
                                <div class="sub">
                                    @if($cloverInfo['tax_cents'] > 0)Tax ${{ number_format($cloverInfo['tax_cents'] / 100, 2) }}@endif
                                    @if($cloverInfo['tip_cents'] > 0) · Tip ${{ number_format($cloverInfo['tip_cents'] / 100, 2) }}@endif
                                    @if(!empty($cloverInfo['cards']))<div>{{ implode(' · ', $cloverInfo['cards']) }}</div>@endif
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="rf-total"><span class="lbl">Total</span>${{ number_format($total, 2) }}</div>
                    @endif
                </div>
            </div>
        @endif
        @empty
            <div class="rf-empty">
                @if($discrepancy === 'mismatch')
                    No mismatches in the scanned window — every paired ERP sale matches Clover within ±1¢.
                @elseif($discrepancy === 'no_clover')
                    Every recent ERP sale paired to a Clover charge — nothing unmatched.
                @elseif($discrepancy === 'no_erp')
                    No orphan Clover charges in the scanned window — every Clover payment paired to an ERP sale.
                @elseif($discrepancy === 'any')
                    No discrepancies in the scanned window — ERP and Clover are fully reconciled.
                @else
                    No sales yet for this filter.
                @endif
            </div>
        @endforelse
    </div>
</section>
@endsection
