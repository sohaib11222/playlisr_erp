@extends('layouts.app')
@section('title', 'Clover EOD Reconciliation')

@section('content')
<section class="content-header no-print">
    <h1>Clover EOD Reconciliation <small>ERP card sales vs Clover settlements, day by day</small></h1>
</section>

<section class="content no-print">
    {{-- Filters: date range + location --}}
    @component('components.filters', ['title' => __('report.filters')])
        <div class="col-md-4">
            <div class="form-group">
                <label>Date range:</label>
                {!! Form::text('eod_date_range', $start . ' ~ ' . $end, [
                    'class' => 'form-control', 'id' => 'eod_date_range',
                    'placeholder' => 'Select a date range', 'readonly',
                ]) !!}
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-group">
                <label>Location:</label>
                {!! Form::select('location_id', $business_locations, $location_id, [
                    'class' => 'form-control select2', 'id' => 'eod_location_id',
                    'placeholder' => 'All locations', 'style' => 'width:100%'
                ]) !!}
            </div>
        </div>
        <div class="col-md-4">
            <div class="form-group">
                <label>&nbsp;</label><br>
                <button type="button" class="btn btn-primary" id="eod_apply_btn">Apply</button>
            </div>
        </div>
    @endcomponent

    {{-- Sync-now button — Sarah 2026-04-22: Clover column was $0 across
         every day because the scheduled clover:sync-payments wasn't
         running (or credentials aren't set). This button fires the
         same command on demand and prints the raw stdout so a failed
         API call / missing creds / zero-payment day are all visible
         instead of buried in a log file. Admin-only on the backend. --}}
    <div style="margin-bottom:12px; text-align:right;">
        <select id="eod_sync_days" class="form-control" style="display:inline-block; width:auto; vertical-align:middle; margin-right:4px;">
            <option value="2" selected>Last 2 days</option>
            <option value="7">Last 7 days</option>
            <option value="30">Last 30 days (backfill)</option>
            <option value="90">Last 90 days (backfill)</option>
        </select>
        <button type="button" class="btn btn-default" id="eod_sync_now_btn">
            <i class="fa fa-sync"></i> Sync Clover now
        </button>
        {{-- Sync Everything — full bidirectional sync (items, orders,
             customers + push dirty products/contacts). Lives next to the
             payments-only button because that's the first place Sarah
             looks when numbers feel off, and "resync all" is usually the
             shotgun answer. Reuses /business/clover/sync-now wired in
             CloverController. --}}
        <button type="button" class="btn btn-info" id="eod_sync_all_btn" style="margin-left:6px;">
            <i class="fa fa-exchange"></i> Sync everything
        </button>
        <span id="eod_sync_status" style="margin-left:8px; font-size:12px; color:#6b7280;"></span>
    </div>
    <div id="eod_sync_output" style="display:none; margin-bottom:14px;">
        <div style="background:#111827; color:#e5e7eb; padding:12px 14px; border-radius:8px; font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace; font-size:12px; white-space:pre-wrap; max-height:260px; overflow:auto;" id="eod_sync_output_pre"></div>
    </div>

    {{-- Grand totals banner --}}
    @php
        $variance = round($grand['erp'] - $grand['clover'], 2);
        $deposit_variance = round($grand['erp'] - $grand['deposit'], 2);
        $variance_abs = abs($variance);
        $banner_class = $variance_abs < 1.00 ? 'success' : ($variance_abs < 10.00 ? 'warning' : 'danger');
        $banner_msg = $variance_abs < 1.00
            ? 'Reconciled — ERP and Clover match for this range.'
            : ($variance_abs < 10.00 ? 'Minor variance (< $10).' : 'Material variance — review flagged days below.');
    @endphp
    <div class="alert alert-{{ $banner_class }}" style="margin-bottom:16px;">
        <strong>{{ $banner_msg }}</strong>
        &nbsp;
        ERP card sales: <strong>${{ number_format($grand['erp'], 2) }}</strong>
        &nbsp;·&nbsp;
        Clover settlements: <strong>${{ number_format($grand['clover'], 2) }}</strong>
        &nbsp;·&nbsp;
        Variance: <strong>${{ number_format($variance, 2) }}</strong>
        &nbsp;·&nbsp;
        Clover batch deposits: <strong>${{ number_format($grand['deposit'], 2) }}</strong>
        &nbsp;·&nbsp;
        Deposit variance: <strong>${{ number_format($deposit_variance, 2) }}</strong>
        @if($grand['flagged_days'] > 0)
            &nbsp;·&nbsp; <span>{{ $grand['flagged_days'] }} day(s) flagged</span>
        @endif
        @if(($grand['deposit_flagged_days'] ?? 0) > 0)
            &nbsp;·&nbsp; <span>{{ $grand['deposit_flagged_days'] }} day(s) deposit-flagged</span>
        @endif
    </div>

    {{-- Daily-nav bar — shown in single-day mode so Fatteen can step
         through days one at a time without re-opening the date picker.
         The range picker above still works if she wants to audit history. --}}
    @if($is_single_day)
        @php
            $todayDate = \Carbon\Carbon::parse($today_str);
            $viewDate = \Carbon\Carbon::parse($start);
            $dayLabel = $viewDate->isToday() ? 'Today'
                : ($viewDate->isYesterday() ? 'Yesterday'
                : $viewDate->format('l, F j'));
            $qs = function($day) use ($location_id) {
                $p = ['start_date' => $day, 'end_date' => $day];
                if (!empty($location_id)) $p['location_id'] = $location_id;
                return '?' . http_build_query($p);
            };
        @endphp
        <div style="display:flex; align-items:center; gap:10px; margin:0 0 14px;">
            <a href="{{ $qs($prev_day) }}" class="btn btn-default">&lsaquo; Prev day</a>
            <div style="flex:1; text-align:center; font-size:18px; font-weight:700; color:#111827;">
                {{ $dayLabel }}
                <div style="font-size:12px; color:#6b7280; font-weight:500;">{{ $viewDate->format('Y-m-d') }}</div>
            </div>
            @if($start < $today_str)
                <a href="{{ $qs($next_day) }}" class="btn btn-default">Next day &rsaquo;</a>
            @else
                <a href="{{ $qs($today_str) }}" class="btn btn-default disabled" style="pointer-events:none; opacity:.45;">Next day &rsaquo;</a>
            @endif
            <a href="{{ $qs($today_str) }}" class="btn btn-primary" @if($viewDate->isToday()) style="opacity:.55;" @endif>Today</a>
        </div>
    @endif

    {{-- Daily reconciliation (xlsx layout) — mirrors Sarah's manual
         "clover vs erp" spreadsheet exactly so Fatteen reads it the way
         she's used to:
           1. Top: cross-store employee summary (Clover / ERP / Diff)
           2. Per day, per store block: two side-by-side lists (Clover
              payments | ERP payments) with totals at the bottom of each
              column. No auto-pairing; Fatteen eyeballs like she always
              has. --}}
    @if(!empty($xlsx_layout['employee_summary']) || !empty($xlsx_layout['by_day']))
        <style>
            .rx-summary-wrap { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px 14px; margin-bottom:20px; }
            .rx-summary-wrap h4 { margin:0 0 8px; font-size:14px; color:#111827; font-weight:700; }
            .rx-summary-table { width:100%; font-size:13px; border-collapse:collapse; font-variant-numeric: tabular-nums; }
            .rx-summary-table th { text-align:left; color:#6b7280; font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:.04em; border-bottom:1px solid #e5e7eb; padding:6px 8px; }
            .rx-summary-table td { padding:6px 8px; border-bottom:1px solid #f3f4f6; }
            .rx-summary-table td.num { text-align:right; }
            .rx-summary-table tr.totals td { border-top:2px solid #d1d5db; border-bottom:none; font-weight:700; background:#f9fafb; }
            .rx-diff-ok   { color:#166534; }
            .rx-diff-warn { color:#b45309; }
            .rx-diff-bad  { color:#b91c1c; }

            .rx-day-block { margin-bottom:22px; }
            .rx-day-head { font-size:13px; color:#6b7280; font-weight:700; letter-spacing:.04em; text-transform:uppercase; margin:4px 0 10px; }
            .rx-store-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px 14px; margin-bottom:12px; }
            .rx-store-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:10px; }
            .rx-store-head h3 { margin:0 0 2px; font-size:16px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; color:#111827; }
            .rx-sbs { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
            @media (max-width: 820px) { .rx-sbs { grid-template-columns: 1fr; } }
            .rx-col { min-width:0; overflow-x:auto; }
            .rx-col-head { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#374151; margin-bottom:4px; }
            .rx-col-head .src { color:#6b7280; font-weight:600; font-size:10px; margin-left:4px; }
            .rx-list { width:100%; font-size:12px; border-collapse:collapse; font-variant-numeric: tabular-nums; }
            .rx-list th { text-align:left; color:#6b7280; font-weight:600; font-size:10px; text-transform:uppercase; letter-spacing:.04em; border-bottom:1px solid #e5e7eb; padding:4px 6px; white-space:nowrap; }
            .rx-list td { padding:4px 6px; border-bottom:1px solid #f3f4f6; white-space:nowrap; }
            .rx-list td.num { text-align:right; }
            .rx-list tr.totals td { border-top:2px solid #d1d5db; border-bottom:none; font-weight:700; background:#f9fafb; }
            .rx-list .muted { color:#9ca3af; }
        </style>

        {{-- Employee summary (top of Sarah's xlsx) — one row per first
             name, aggregated across both stores. Sorted by |diff| desc so
             biggest mismatches float to the top. --}}
        @if(!empty($xlsx_layout['employee_summary']))
            @php
                $sumClover = array_sum(array_column($xlsx_layout['employee_summary'], 'clover'));
                $sumErp    = array_sum(array_column($xlsx_layout['employee_summary'], 'erp'));
                $sumDiff   = round($sumClover - $sumErp, 2);
            @endphp
            <div class="rx-summary-wrap">
                <h4>Employee summary · Clover vs ERP</h4>
                <table class="rx-summary-table">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th class="num">Clover</th>
                            <th class="num">ERP</th>
                            <th class="num">Difference</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($xlsx_layout['employee_summary'] as $r)
                            @php
                                $d = $r['diff'];
                                $cls = abs($d) < 1 ? 'rx-diff-ok' : (abs($d) < 10 ? 'rx-diff-warn' : 'rx-diff-bad');
                            @endphp
                            <tr>
                                <td>{{ $r['name'] }}</td>
                                <td class="num">{{ $r['clover'] > 0 ? '$' . number_format($r['clover'], 2) : '—' }}</td>
                                <td class="num">{{ $r['erp'] > 0 ? '$' . number_format($r['erp'], 2) : '—' }}</td>
                                <td class="num {{ $cls }}">{{ $d >= 0 ? '+' : '' }}${{ number_format($d, 2) }}</td>
                            </tr>
                        @endforeach
                        <tr class="totals">
                            <td>Total</td>
                            <td class="num">${{ number_format($sumClover, 2) }}</td>
                            <td class="num">${{ number_format($sumErp, 2) }}</td>
                            @php $cls = abs($sumDiff) < 1 ? 'rx-diff-ok' : (abs($sumDiff) < 10 ? 'rx-diff-warn' : 'rx-diff-bad'); @endphp
                            <td class="num {{ $cls }}">{{ $sumDiff >= 0 ? '+' : '' }}${{ number_format($sumDiff, 2) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Per-day, per-store side-by-side Clover + ERP lists. Each
             store card keeps the ✓ Reconciled + notes controls already
             wired up for Fatteen. --}}
        @foreach($xlsx_layout['by_day'] as $dayBlock)
            <div class="rx-day-block">
                <div class="rx-day-head">{{ \Carbon\Carbon::parse($dayBlock['day'])->format('D, M j, Y') }}</div>

                @foreach($dayBlock['locations'] as $loc)
                    @php
                        $locNameRaw = $loc['location_name'];
                        $isNoLoc = (strtolower($locNameRaw) === '(no location)' || stripos($locNameRaw, 'no location') !== false);
                        $locNameDisplay = $isNoLoc
                            ? '⚠ No location set — test data or setup issue'
                            : $locNameRaw;

                        $ldiff = round($loc['clover_total'] - $loc['erp_total'], 2);
                        $lcls  = abs($ldiff) < 1 ? 'rx-diff-ok' : (abs($ldiff) < 10 ? 'rx-diff-warn' : 'rx-diff-bad');

                        $rKey = $dayBlock['day'] . '|' . ($loc['location_id'] ?: 0);
                        $rec = $reconciliations[$rKey] ?? null;
                        $isReconciled = (bool) optional($rec)->reconciled_at;
                        $recNotes = $rec ? (string) $rec->notes : '';
                        $recStampLabel = null;
                        if ($rec && $rec->reconciled_at) {
                            $u = $rec->user;
                            $who = $u ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: $u->username) : null;
                            $recStampLabel = 'Reconciled' . ($who ? ' by ' . $who : '') . ' · '
                                . \Carbon\Carbon::parse($rec->reconciled_at)->format('M j, g:i a');
                        }
                    @endphp
                    <div class="rx-store-card eod-loc-card" data-day="{{ $dayBlock['day'] }}" data-location-id="{{ $loc['location_id'] ?: 0 }}">
                        <div class="rx-store-head">
                            <div style="flex:1; min-width:0;">
                                <h3>{{ $locNameDisplay }}</h3>
                                <div style="font-size:13px; color:#374151;">
                                    Clover <strong>${{ number_format($loc['clover_total'], 2) }}</strong>
                                    &nbsp;vs&nbsp;
                                    ERP <strong>${{ number_format($loc['erp_total'], 2) }}</strong>
                                    &nbsp;·&nbsp;
                                    diff <span class="{{ $lcls }}">{{ $ldiff >= 0 ? '+' : '' }}${{ number_format($ldiff, 2) }}</span>
                                </div>
                            </div>
                            <div style="text-align:right; min-width:220px;">
                                <label class="eod-recon-toggle" style="display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600; cursor:pointer; color:{{ $isReconciled ? '#166534' : '#374151' }};">
                                    <input type="checkbox" class="eod-recon-checkbox" {{ $isReconciled ? 'checked' : '' }}>
                                    <span class="eod-recon-label">{{ $isReconciled ? '✓ Reconciled' : 'Mark reconciled' }}</span>
                                </label>
                                <div class="eod-recon-stamp" style="font-size:11px; color:#6b7280; margin-top:2px;">{{ $recStampLabel }}</div>
                            </div>
                        </div>

                        <div class="rx-sbs">
                            {{-- Clover side --}}
                            <div class="rx-col">
                                <div class="rx-col-head">Clover <span class="src">· from /v3/merchants</span></div>
                                <table class="rx-list">
                                    <thead>
                                        <tr>
                                            <th>Time</th>
                                            <th class="num">Amount</th>
                                            <th>Employee</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($loc['clover_payments'] as $p)
                                            <tr>
                                                <td>{{ \Carbon\Carbon::parse($p->ts)->setTimezone(config('app.timezone'))->format('g:i:s a') }}</td>
                                                <td class="num">${{ number_format($p->amount, 2) }}</td>
                                                <td @if($p->employee === '(unattributed)') class="muted" @endif>{{ $p->employee }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="3" class="muted" style="text-align:center;">No Clover payments.</td></tr>
                                        @endforelse
                                        <tr class="totals">
                                            <td>Total</td>
                                            <td class="num">${{ number_format($loc['clover_total'], 2) }}</td>
                                            <td></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            {{-- ERP side --}}
                            <div class="rx-col">
                                <div class="rx-col-head">ERP <span class="src">· from transaction_payments</span></div>
                                <table class="rx-list">
                                    <thead>
                                        <tr>
                                            <th>Time</th>
                                            <th class="num">Total paid</th>
                                            <th>Added by</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @forelse($loc['erp_payments'] as $p)
                                            <tr>
                                                <td>{{ \Carbon\Carbon::parse($p->ts)->setTimezone(config('app.timezone'))->format('g:i:s a') }}</td>
                                                <td class="num">
                                                    <a href="{{ route('sell.printInvoice', $p->transaction_id) }}" target="_blank" title="{{ $p->invoice_no ?: ('#' . $p->transaction_id) }}">${{ number_format($p->amount, 2) }}</a>
                                                </td>
                                                <td>{{ $p->added_by }}</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="3" class="muted" style="text-align:center;">No ERP payments.</td></tr>
                                        @endforelse
                                        <tr class="totals">
                                            <td>Total</td>
                                            <td class="num">${{ number_format($loc['erp_total'], 2) }}</td>
                                            <td></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div style="margin-top:10px;">
                            <label style="font-size:11px; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:.04em;">Reconciliation notes</label>
                            <textarea class="eod-recon-notes form-control" rows="2"
                                placeholder="Notes for this store & day (auto-saves)"
                                style="font-size:12px; resize:vertical;">{{ $recNotes }}</textarea>
                            <div class="eod-recon-notes-status" style="font-size:11px; color:#9ca3af; margin-top:2px; min-height:14px;"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endforeach
    @endif

    {{-- OLD transaction-match panel retired on 2026-04-23 — Sarah asked
         for the xlsx layout above instead. Keeping this guard so existing
         callers that still reference $txn_match don't crash; the new view
         above handles all of Fatteen's daily reconciliation needs. --}}
    @if(false && !empty($txn_match) && (!empty($txn_match['by_cashier']) || !empty($txn_match['online']['clover_only'])))
        <style>
            .tmx-panel { margin-bottom: 22px; }
            .tmx-summary { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
            .tmx-chip { background:#fff; border:1px solid #e5e7eb; border-radius:999px; padding:6px 12px; font-size:12px; font-weight:600; color:#111827; }
            .tmx-chip b { margin-left:6px; }
            .tmx-chip.ok { border-color:#bbf7d0; color:#166534; background:#f0fdf4; }
            .tmx-chip.warn { border-color:#fde68a; color:#b45309; background:#fffbeb; }
            .tmx-chip.bad  { border-color:#fecaca; color:#b91c1c; background:#fef2f2; }
            .tmx-chip.muted { color:#6b7280; }
            .tmx-cashier-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px 14px; margin-bottom:12px; }
            .tmx-cashier-head { display:flex; align-items:baseline; justify-content:space-between; gap:10px; margin-bottom:8px; }
            .tmx-cashier-name { font-size:15px; font-weight:700; color:#111827; }
            .tmx-cashier-loc { font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; }
            .tmx-cashier-totals { font-size:12px; color:#374151; font-variant-numeric: tabular-nums; }
            .tmx-row-table { width:100%; font-size:12px; border-collapse:collapse; font-variant-numeric: tabular-nums; }
            .tmx-row-table th { text-align:left; color:#6b7280; font-weight:600; font-size:10px; text-transform:uppercase; letter-spacing:.04em; border-bottom:1px solid #e5e7eb; padding:4px 6px; }
            .tmx-row-table td { padding:5px 6px; border-bottom:1px solid #f3f4f6; }
            .tmx-row-table td.num { text-align:right; }
            .tmx-row-table tr.ok   td { background:#f0fdf4; }
            .tmx-row-table tr.warn td { background:#fffbeb; }
            .tmx-row-table tr.bad  td { background:#fef2f2; }
            .tmx-bucket-head { font-size:11px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:.04em; margin:10px 0 4px; }
            details.tmx-details summary { cursor:pointer; font-size:11px; color:#6b7280; padding:4px 0; }
            details.tmx-details[open] summary { color:#111827; }
        </style>
        <h4 style="margin: 10px 0 6px; font-size: 14px; color: #111827; font-weight: 700;">
            Transaction match · by cashier
        </h4>
        <div class="tmx-summary">
            <span class="tmx-chip ok">✓ Matched<b>{{ $txn_match['totals']['matched_count'] }}</b> <span class="tmx-chip muted" style="padding:0 0 0 6px; border:none; background:transparent; font-size:11px;">${{ number_format($txn_match['totals']['matched'], 2) }}</span></span>
            @if($txn_match['totals']['clover_only_count'] > 0)
                <span class="tmx-chip bad">❌ Clover only<b>{{ $txn_match['totals']['clover_only_count'] }}</b> <span class="tmx-chip muted" style="padding:0 0 0 6px; border:none; background:transparent; font-size:11px;">${{ number_format($txn_match['totals']['clover_only'] + $txn_match['totals']['online'], 2) }}</span></span>
            @endif
            @if($txn_match['totals']['erp_only_count'] > 0)
                <span class="tmx-chip bad">❌ ERP only<b>{{ $txn_match['totals']['erp_only_count'] }}</b> <span class="tmx-chip muted" style="padding:0 0 0 6px; border:none; background:transparent; font-size:11px;">${{ number_format($txn_match['totals']['erp_only'], 2) }}</span></span>
            @endif
        </div>

        @foreach($txn_match['by_cashier'] as $key => $c)
            @php
                $mCount = count($c['matched']);
                $coCount = count($c['clover_only']);
                $eoCount = count($c['erp_only']);
                $allMatched = $coCount === 0 && $eoCount === 0 && $mCount > 0;
            @endphp
            <div class="tmx-cashier-card">
                <div class="tmx-cashier-head">
                    <div>
                        <div class="tmx-cashier-name">{{ $c['display_name'] }} @if($allMatched)<span style="color:#166534; font-weight:600;"> · ✓ all match</span>@endif</div>
                        <div class="tmx-cashier-loc">{{ $c['location_name'] }}</div>
                    </div>
                    <div class="tmx-cashier-totals">
                        ✓ <strong>{{ $mCount }}</strong> · ${{ number_format($c['totals']['matched'], 2) }}
                        @if($coCount > 0)
                            &nbsp;·&nbsp; <span style="color:#b91c1c;">Clover-only <strong>{{ $coCount }}</strong> · ${{ number_format($c['totals']['clover_only'], 2) }}</span>
                        @endif
                        @if($eoCount > 0)
                            &nbsp;·&nbsp; <span style="color:#b91c1c;">ERP-only <strong>{{ $eoCount }}</strong> · ${{ number_format($c['totals']['erp_only'], 2) }}</span>
                        @endif
                    </div>
                </div>

                @if($coCount > 0)
                    <div class="tmx-bucket-head" style="color:#b91c1c;">❌ Clover-only (card ran, no ERP sale recorded)</div>
                    <table class="tmx-row-table">
                        <thead><tr><th>Time</th><th class="num">Amount</th><th>Clover payment</th><th>Card</th></tr></thead>
                        <tbody>
                            @foreach($c['clover_only'] as $r)
                                <tr class="bad">
                                    <td>{{ \Carbon\Carbon::parse($r->ts)->format('g:i:s a') }}</td>
                                    <td class="num">${{ number_format($r->amount, 2) }}</td>
                                    <td><code style="font-size:11px;">{{ $r->clover_payment_id }}</code></td>
                                    <td>{{ $r->card ?: $r->tender_type }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                @if($eoCount > 0)
                    <div class="tmx-bucket-head" style="color:#b91c1c;">❌ ERP-only (sale booked, no Clover settlement)</div>
                    <table class="tmx-row-table">
                        <thead><tr><th>Time</th><th class="num">Amount</th><th>Invoice</th><th>Method</th></tr></thead>
                        <tbody>
                            @foreach($c['erp_only'] as $r)
                                <tr class="bad">
                                    <td>{{ \Carbon\Carbon::parse($r->ts)->format('g:i:s a') }}</td>
                                    <td class="num">${{ number_format($r->amount, 2) }}</td>
                                    <td>
                                        <a href="{{ route('sell.printInvoice', $r->erp_transaction_id) }}" target="_blank">{{ $r->erp_invoice_no ?: ('#' . $r->erp_transaction_id) }}</a>
                                    </td>
                                    <td>{{ strtoupper($r->method ?? '') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                @if($mCount > 0)
                    <details class="tmx-details">
                        <summary>Show {{ $mCount }} matched transaction(s)</summary>
                        <table class="tmx-row-table">
                            <thead><tr><th>Time</th><th class="num">Amount</th><th>Invoice</th><th>Clover payment</th><th class="num">Δ sec</th></tr></thead>
                            <tbody>
                                @foreach($c['matched'] as $r)
                                    <tr class="ok">
                                        <td>{{ \Carbon\Carbon::parse($r->ts)->format('g:i:s a') }}</td>
                                        <td class="num">${{ number_format($r->amount, 2) }}</td>
                                        <td>
                                            <a href="{{ route('sell.printInvoice', $r->erp_transaction_id) }}" target="_blank">{{ $r->erp_invoice_no ?: ('#' . $r->erp_transaction_id) }}</a>
                                        </td>
                                        <td><code style="font-size:11px;">{{ $r->clover_payment_id }}</code></td>
                                        <td class="num">{{ $r->delta_sec }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </details>
                @endif
            </div>
        @endforeach

        @if(!empty($txn_match['online']['clover_only']))
            <div class="tmx-cashier-card" style="border-color:#c7d2fe; background:#eef2ff;">
                <div class="tmx-cashier-head">
                    <div>
                        <div class="tmx-cashier-name">🌐 Online / automated</div>
                        <div class="tmx-cashier-loc">Clover sales with no cashier pin — website checkout, card-on-file, etc.</div>
                    </div>
                    <div class="tmx-cashier-totals">
                        <strong>{{ count($txn_match['online']['clover_only']) }}</strong> · ${{ number_format($txn_match['online']['total'], 2) }}
                    </div>
                </div>
                <details class="tmx-details">
                    <summary>Show {{ count($txn_match['online']['clover_only']) }} online / automated payment(s)</summary>
                    <table class="tmx-row-table">
                        <thead><tr><th>Time</th><th class="num">Amount</th><th>Clover payment</th><th>Card</th></tr></thead>
                        <tbody>
                            @foreach($txn_match['online']['clover_only'] as $r)
                                <tr>
                                    <td>{{ \Carbon\Carbon::parse($r->ts)->format('g:i:s a') }}</td>
                                    <td class="num">${{ number_format($r->amount, 2) }}</td>
                                    <td><code style="font-size:11px;">{{ $r->clover_payment_id }}</code></td>
                                    <td>{{ $r->card ?: $r->tender_type }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </details>
            </div>
        @endif
    @endif

    {{-- Per-cashier side-by-side breakdown — mirrors Sarah's daily xlsx
         (PICO on the left, HOLLYWOOD on the right, Employee / Clover / ERP /
         Diff per row). Rendered once per day across the selected range,
         most recent first; single-day selections just show one block. --}}
    @if(!empty($employee_breakdown_by_day))
        <style>
            .eod-day-block { margin-bottom: 22px; }
            .eod-day-head { margin: 4px 0 10px; font-size: 13px; color: #6b7280; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
            .eod-loc-wrap { display: flex; flex-direction: column; gap: 16px; }
            .eod-loc-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 12px 14px; overflow-x: auto; }
            .eod-loc-card h3 { margin: 0 0 8px; font-size: 15px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #111827; }
            .eod-loc-card table { width: 100%; font-size: 12px; border-collapse: collapse; min-width: 1080px; }
            .eod-loc-card th { text-align: left; color: #6b7280; font-weight: 600; font-size: 10px; text-transform: uppercase; letter-spacing: .04em; border-bottom: 1px solid #e5e7eb; padding: 5px 6px; white-space: nowrap; }
            .eod-loc-card td { padding: 6px; border-bottom: 1px solid #f3f4f6; font-variant-numeric: tabular-nums; white-space: nowrap; }
            .eod-loc-card td.num { text-align: right; }
            .eod-loc-card td.muted { color: #9ca3af; }
            .eod-loc-card tr.totals td { border-top: 2px solid #d1d5db; border-bottom: none; font-weight: 700; background: #f9fafb; }
            .eod-loc-card th.group { background: #f9fafb; color: #111827; font-size: 10px; }
            .eod-loc-card th.sep, .eod-loc-card td.sep { border-left: 1px solid #e5e7eb; }
            .eod-diff-ok { color: #166534; }
            .eod-diff-warn { color: #b45309; }
            .eod-diff-bad { color: #b91c1c; }
            .eod-loc-empty { color: #9ca3af; font-size: 12px; padding: 8px 0; text-align: center; }
            .eod-shift-open-pill { display: inline-block; padding: 1px 6px; background: #fef3c7; color: #92400e; border-radius: 10px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
        </style>
        <h4 style="margin: 10px 0 6px; font-size: 14px; color: #111827; font-weight: 700;">Per-cashier breakdown</h4>
        @foreach($employee_breakdown_by_day as $dayBlock)
            <div class="eod-day-block">
                <div class="eod-day-head">{{ \Carbon\Carbon::parse($dayBlock['day'])->format('D, M j, Y') }}</div>
                <div class="eod-loc-wrap">
                    @foreach($dayBlock['locations'] as $loc)
                        @php
                            $ldiff = $loc['totals']['difference'];
                            $lcls  = abs($ldiff) < 1 ? 'eod-diff-ok' : (abs($ldiff) < 10 ? 'eod-diff-warn' : 'eod-diff-bad');

                            // Relabel the confusing names Fatteen asked about.
                            // (no location) usually = test register opened
                            // without picking a store; warn so she knows it
                            // isn't a bug on her side.
                            $locNameRaw = $loc['location_name'];
                            $isNoLoc = (strtolower($locNameRaw) === '(no location)' || stripos($locNameRaw, 'no location') !== false);
                            $locNameDisplay = $isNoLoc
                                ? '⚠ No location set — test data or setup issue'
                                : $locNameRaw;

                            // Reconciliation lookup. Key = "day|locId" with
                            // locId=0 for the no-location bucket. Matches
                            // what loadReconciliations() in the controller
                            // returns, so each card can show its own ✓/notes.
                            $rKey = $dayBlock['day'] . '|' . ($loc['location_id'] ?: 0);
                            $rec = $reconciliations[$rKey] ?? null;
                            $isReconciled = (bool) optional($rec)->reconciled_at;
                            $recNotes = $rec ? (string) $rec->notes : '';
                            $recStampLabel = null;
                            if ($rec && $rec->reconciled_at) {
                                $u = $rec->user;
                                $who = $u
                                    ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: $u->username)
                                    : null;
                                $recStampLabel = 'Reconciled' . ($who ? ' by ' . $who : '') . ' · '
                                    . \Carbon\Carbon::parse($rec->reconciled_at)->format('M j, g:i a');
                            }

                            // Glance summary — the big "is this OK?" line at
                            // the top of each card so Fatteen doesn't have to
                            // read the table to know.
                            $cloverTot = $loc['totals']['clover_total'];
                            $erpTot    = $loc['totals']['erp_total'];
                            $diffAbs   = abs($ldiff);
                            $glance = $diffAbs < 1
                                ? ['🟢', 'Match', '#166534']
                                : ($diffAbs < 10 ? ['🟡', 'Minor variance', '#b45309'] : ['🔴', 'Variance to review', '#b91c1c']);
                        @endphp
                        <div class="eod-loc-card" data-day="{{ $dayBlock['day'] }}" data-location-id="{{ $loc['location_id'] ?: 0 }}">
                            <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:10px;">
                                <div style="flex:1; min-width:0;">
                                    <h3 style="margin-bottom:4px;">{{ $locNameDisplay }}</h3>
                                    <div style="font-size:13px; color:{{ $glance[2] }}; font-weight:600;">
                                        {{ $glance[0] }} {{ $glance[1] }}
                                        &nbsp;·&nbsp;
                                        Clover ${{ number_format($cloverTot, 2) }}
                                        &nbsp;vs&nbsp;
                                        ERP ${{ number_format($erpTot, 2) }}
                                        &nbsp;·&nbsp;
                                        diff <span class="{{ $lcls }}">{{ $ldiff >= 0 ? '+' : '' }}${{ number_format($ldiff, 2) }}</span>
                                    </div>
                                </div>
                                <div style="text-align:right; min-width:220px;">
                                    <label class="eod-recon-toggle" style="display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600; cursor:pointer; color:{{ $isReconciled ? '#166534' : '#374151' }};">
                                        <input type="checkbox" class="eod-recon-checkbox" {{ $isReconciled ? 'checked' : '' }}>
                                        <span class="eod-recon-label">{{ $isReconciled ? '✓ Reconciled' : 'Mark reconciled' }}</span>
                                    </label>
                                    <div class="eod-recon-stamp" style="font-size:11px; color:#6b7280; margin-top:2px;">{{ $recStampLabel }}</div>
                                </div>
                            </div>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Employee</th>
                                        <th>Shift</th>
                                        <th class="num sep">Open $</th>
                                        <th class="num">Cash sales</th>
                                        <th class="num">Collection buys</th>
                                        <th class="num sep">Clover</th>
                                        <th class="num">ERP</th>
                                        <th class="num">Card diff</th>
                                        <th class="num sep">End $ (reported)</th>
                                        <th class="num">Expected</th>
                                        <th class="num">Cash variance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($loc['employees'] as $e)
                                        @php
                                            $d = $e['difference'];
                                            $cls = abs($d) < 1 ? 'eod-diff-ok' : (abs($d) < 10 ? 'eod-diff-warn' : 'eod-diff-bad');
                                            $cv = $e['cash_variance'] ?? null;
                                            $cvCls = $cv === null ? '' : (abs($cv) < 1 ? 'eod-diff-ok' : (abs($cv) < 10 ? 'eod-diff-warn' : 'eod-diff-bad'));
                                            $fmt = function($t) {
                                                if (!$t) return '—';
                                                try { return \Carbon\Carbon::parse($t)->format('g:i a'); } catch (\Exception $ex) { return '—'; }
                                            };
                                            $shiftDisplay = !empty($e['shift_start'])
                                                ? $fmt($e['shift_start']) . ' → ' . ($e['shift_status'] === 'open'
                                                    ? '<span class="eod-shift-open-pill">open</span>'
                                                    : $fmt($e['shift_end']))
                                                : '—';
                                        @endphp
                                        @php
                                            // Relabel "Unknown" so Fatteen knows what to do when she sees it.
                                            $isUnknown = strcasecmp($e['display_name'], 'Unknown') === 0;
                                            $displayName = $isUnknown
                                                ? '⚠ No pin — ask cashier'
                                                : $e['display_name'];
                                        @endphp
                                        <tr @if($isUnknown) title="Clover didn't receive a cashier pin for these sales — usually online/self-checkout, or someone forgot to pin in." style="background:#fffbeb;" @endif>
                                            <td>{{ $displayName }}</td>
                                            <td class="{{ empty($e['shift_start']) ? 'muted' : '' }}">{!! $shiftDisplay !!}</td>
                                            <td class="num sep {{ is_null($e['opening_cash']) ? 'muted' : '' }}">{{ is_null($e['opening_cash']) ? '—' : '$' . number_format($e['opening_cash'], 2) }}</td>
                                            <td class="num {{ !$e['has_shift'] ? 'muted' : '' }}">{{ !$e['has_shift'] ? '—' : '$' . number_format($e['cash_sales'], 2) }}</td>
                                            <td class="num {{ !$e['has_shift'] ? 'muted' : '' }}">{{ !$e['has_shift'] ? '—' : '$' . number_format($e['collection_buys_all'], 2) }}</td>
                                            <td class="num sep">${{ number_format($e['clover_total'], 2) }}</td>
                                            <td class="num">${{ number_format($e['erp_total'], 2) }}</td>
                                            <td class="num {{ $cls }}">{{ $d >= 0 ? '+' : '' }}${{ number_format($d, 2) }}</td>
                                            <td class="num sep {{ is_null($e['reported_ending_cash']) ? 'muted' : '' }}">{{ is_null($e['reported_ending_cash']) ? '—' : '$' . number_format($e['reported_ending_cash'], 2) }}</td>
                                            <td class="num {{ is_null($e['expected_ending_cash']) ? 'muted' : '' }}">{{ is_null($e['expected_ending_cash']) ? '—' : '$' . number_format($e['expected_ending_cash'], 2) }}</td>
                                            <td class="num {{ $cvCls }}">{{ is_null($cv) ? '—' : (($cv >= 0 ? '+' : '') . '$' . number_format($cv, 2)) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="11" class="eod-loc-empty">No cashier activity.</td></tr>
                                    @endforelse
                                    <tr class="totals">
                                        <td>Total</td>
                                        <td></td>
                                        <td class="num sep"></td>
                                        <td class="num"></td>
                                        <td class="num"></td>
                                        <td class="num sep">${{ number_format($loc['totals']['clover_total'], 2) }}</td>
                                        <td class="num">${{ number_format($loc['totals']['erp_total'], 2) }}</td>
                                        <td class="num {{ $lcls }}">{{ $ldiff >= 0 ? '+' : '' }}${{ number_format($ldiff, 2) }}</td>
                                        <td class="num sep"></td>
                                        <td class="num"></td>
                                        <td class="num"></td>
                                    </tr>
                                </tbody>
                            </table>

                            {{-- Reconciliation notes — Fatteen leaves context
                                 for tomorrow's review ("drawer jammed at 3pm",
                                 "variance = Clyde's split on #12345", etc.).
                                 Saves on blur + debounced input. --}}
                            <div style="margin-top:10px;">
                                <label style="font-size:11px; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:.04em;">Reconciliation notes</label>
                                <textarea class="eod-recon-notes form-control" rows="2"
                                    placeholder="Notes for this store & day (auto-saves)"
                                    style="font-size:12px; resize:vertical;">{{ $recNotes }}</textarea>
                                <div class="eod-recon-notes-status" style="font-size:11px; color:#9ca3af; margin-top:2px; min-height:14px;"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
        <p class="help-block" style="margin-top:-6px; margin-bottom: 18px;">
            <strong>Card diff = Clover − ERP.</strong> Positive means Clover
            settled more than the POS recorded; negative means the POS booked
            a card tender that didn't swipe through Clover. <strong>Cash
            variance = reported ending cash − expected.</strong> Expected
            opens the register at the opening count, adds cash sales, subtracts
            cash-paid collection buys and cash refunds — anything left is the
            cashier's short/over. A shift showing "—" in the cash columns
            means the employee rang sales on someone else's open register,
            so there's no drawer to audit for them individually. Open shifts
            hide variance until they're closed. Names match on first name.
        </p>
    @endif

    @component('components.widget', ['class' => 'box-primary', 'title' => 'Daily reconciliation'])
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Location</th>
                        <th class="text-right">ERP card $</th>
                        <th class="text-right">ERP txns</th>
                        <th class="text-right">Clover $</th>
                        <th class="text-right">Clover txns</th>
                        <th class="text-right">Batch deposits $</th>
                        <th class="text-right">Batch count</th>
                        <th class="text-right">Variance</th>
                        <th class="text-right">Deposit variance</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                        @php
                            $row_class = $r->status === 'reconciled' ? '' : ($r->status === 'minor' ? 'warning' : 'danger');
                            $status_label = $r->status === 'reconciled' ? '✓ Reconciled'
                                          : ($r->status === 'minor' ? '⚠ Minor' : '⚠ Review');
                        @endphp
                        <tr @if($row_class) class="{{ $row_class }}" @endif>
                            <td>{{ $r->day }}</td>
                            <td>{{ $r->location_name }}</td>
                            <td class="text-right">${{ number_format($r->erp_total, 2) }}</td>
                            <td class="text-right">{{ $r->erp_count }}</td>
                            <td class="text-right">${{ number_format($r->clover_total, 2) }}</td>
                            <td class="text-right">{{ $r->clover_count }}</td>
                            <td class="text-right">${{ number_format($r->deposit_total ?? 0, 2) }}</td>
                            <td class="text-right">{{ $r->batch_count ?? 0 }}</td>
                            <td class="text-right"><strong>${{ number_format($r->variance, 2) }}</strong></td>
                            <td class="text-right"><strong>${{ number_format($r->deposit_variance ?? 0, 2) }}</strong></td>
                            <td>
                                {{ $status_label }}
                                @if(($r->deposit_status ?? 'reconciled') !== 'reconciled')
                                    <br><small>⚠ Deposit {{ ucfirst($r->deposit_status) }}</small>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-muted">No matching sales in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="help-block" style="margin-top:10px;">
            <strong>How to read this:</strong> each row pairs one day's ERP card-method payments with Clover payment totals and Clover batch/deposit totals.
            Variance = ERP − Clover payments. Deposit variance = ERP − Clover batch deposits. Rows &lt; $1 off are green, &lt; $10 off yellow, otherwise flagged red.
            Clover data comes from the <code>clover_payments</code> table populated by the scheduled <code>clover:sync-payments</code> command —
            if it hasn't run recently the Clover column will lag behind.
        </p>
    @endcomponent

    {{-- Why Unknown? drill-down — Sarah 2026-04-22: "why is employee
         unknown sometimes?". Lists the raw rows that bucketed as Unknown
         on either side, with the underlying cause so she can tell benign
         walk-in / online-checkout from actual data problems (deleted
         users, broken imports). Collapsed by default so the panel stays
         small unless she cares. --}}
    @if(!empty($unknown_rows) && (count($unknown_rows['erp'] ?? []) > 0 || count($unknown_rows['clover'] ?? []) > 0 || count($unknown_rows['clover_fields'] ?? []) > 0))
        @component('components.widget', ['class' => 'box-warning', 'title' => 'Why Unknown / Manual Clover Fields? &mdash; ' . (count($unknown_rows['erp']) + count($unknown_rows['clover']) + count($unknown_rows['clover_fields'] ?? [])) . ' row(s)'])
            <p class="help-block" style="margin-top:-6px;">
                Each row below is a payment that bucketed as <em>Unknown</em> in the per-cashier breakdown.
                <strong>ERP side</strong> means <code>transactions.created_by</code> is null or the user row is gone — commonly walk-in flows or automated imports.
                <strong>Clover side</strong> means the payment came in without an employee pin — usually online Clover checkout, self-checkout, or a card-on-file charge.
            </p>

            @if(count($unknown_rows['erp']) > 0)
                <h5 style="margin-top:12px;">ERP ({{ count($unknown_rows['erp']) }})</h5>
                <div class="table-responsive">
                    <table class="table table-condensed table-striped">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Invoice</th>
                                <th>Location</th>
                                <th>Method</th>
                                <th class="text-right">Amount</th>
                                <th>Cause</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($unknown_rows['erp'] as $r)
                                <tr>
                                    <td>{{ $r->day }}</td>
                                    <td>
                                        <a href="{{ route('sell.printInvoice', $r->transaction_id) }}" target="_blank">{{ $r->invoice_no ?: ('#' . $r->transaction_id) }}</a>
                                    </td>
                                    <td>{{ $r->location_name ?: '(no location)' }}</td>
                                    <td>{{ strtoupper($r->method ?? '') }}</td>
                                    <td class="text-right">${{ number_format((float) $r->amount, 2) }}</td>
                                    <td>{{ $r->cause }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if(count($unknown_rows['clover']) > 0)
                <h5 style="margin-top:12px;">Clover ({{ count($unknown_rows['clover']) }})</h5>
                <div class="table-responsive">
                    <table class="table table-condensed table-striped">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Clover payment</th>
                                <th>Location</th>
                                <th>Tender</th>
                                <th>Card</th>
                                <th class="text-right">Amount</th>
                                <th>Cause</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($unknown_rows['clover'] as $r)
                                <tr>
                                    <td>{{ $r->day }}</td>
                                    <td><code style="font-size:11px;">{{ $r->clover_payment_id }}</code></td>
                                    <td>{{ $r->location_name ?: '(no location)' }}</td>
                                    <td>{{ $r->tender_type }}</td>
                                    <td>{{ $r->card_type }}{{ $r->card_last4 ? ' ****' . $r->card_last4 : '' }}</td>
                                    <td class="text-right">${{ number_format((float) $r->amount, 2) }}</td>
                                    <td>{{ $r->cause }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            @if(count($unknown_rows['clover_fields'] ?? []) > 0)
                <h5 style="margin-top:12px;">Clover field-quality issues ({{ count($unknown_rows['clover_fields']) }})</h5>
                <div class="table-responsive">
                    <table class="table table-condensed table-striped">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Clover payment</th>
                                <th>Location</th>
                                <th>Employee</th>
                                <th>Tender</th>
                                <th>Card</th>
                                <th>Order ID</th>
                                <th class="text-right">Amount</th>
                                <th>Issue</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($unknown_rows['clover_fields'] as $r)
                                <tr>
                                    <td>{{ $r->day }}</td>
                                    <td><code style="font-size:11px;">{{ $r->clover_payment_id }}</code></td>
                                    <td>{{ $r->location_name ?: '(no location)' }}</td>
                                    <td>{{ $r->employee_name ?: 'Unknown' }}</td>
                                    <td>{{ $r->tender_type ?: '—' }}</td>
                                    <td>{{ $r->card_type ?: '—' }}{{ $r->card_last4 ? ' ****' . $r->card_last4 : '' }}</td>
                                    <td>{{ $r->clover_order_id ?: '—' }}</td>
                                    <td class="text-right">${{ number_format((float) $r->amount, 2) }}</td>
                                    <td>{{ $r->cause }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endcomponent
    @endif
</section>
@stop

@section('javascript')
<script>
$(function () {
    $('#eod_date_range').daterangepicker(dateRangeSettings, function (start, end) {
        $('#eod_date_range').val(start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format));
    });
    $('#eod_apply_btn').on('click', function () {
        var val = $('#eod_date_range').val() || '';
        var parts = val.split(' ~ ');
        var dp = $('#eod_date_range').data('daterangepicker');
        var startFmt = dp ? dp.startDate.format('YYYY-MM-DD') : '';
        var endFmt   = dp ? dp.endDate.format('YYYY-MM-DD')   : '';
        var loc = $('#eod_location_id').val() || '';
        var qs = $.param({start_date: startFmt, end_date: endFmt, location_id: loc});
        window.location.href = '/reports/clover-eod-reconciliation?' + qs;
    });

    // Sync-now handler — POSTs to the web-wrapped artisan command and
    // pipes stdout into the black console block. On success we reload
    // so the report picks up the new clover_payments rows.
    $('#eod_sync_now_btn').on('click', function () {
        var $btn = $(this);
        var $status = $('#eod_sync_status');
        var $out = $('#eod_sync_output');
        var $pre = $('#eod_sync_output_pre');
        var days = parseInt($('#eod_sync_days').val(), 10) || 2;
        var original = $btn.html();
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Syncing…');
        var reachMsg = days > 7
            ? 'Backfilling ' + days + ' days, this can take 1–3 minutes…'
            : 'Reaching Clover API, this can take 20–60 seconds…';
        $status.text(reachMsg).css('color', '#6b7280');
        $out.hide();

        $.ajax({
            url: '/reports/clover-eod-reconciliation/sync-now',
            method: 'POST',
            dataType: 'json',
            timeout: 240000,
            data: { _token: $('meta[name="csrf-token"]').attr('content'), days: days }
        }).done(function (r) {
            $pre.text(r.output || '(no output)');
            $out.show();
            var msg = r.success
                ? 'Done · ' + (r.rows_recently_written || 0) + ' rows written (this call) · '
                    + (r.rows_in_window || 0) + ' rows now in the ' + days + '-day window. Reloading…'
                : 'Sync exited with code ' + (r.exit_code || '?') + ' — see output below.';
            $status.text(msg).css('color', r.success ? '#166534' : '#b91c1c');
            if (r.success && (r.rows_recently_written || 0) > 0) {
                setTimeout(function () { window.location.reload(); }, 1500);
            }
        }).fail(function (xhr) {
            var out = '';
            try { out = (xhr.responseJSON && xhr.responseJSON.output) || xhr.responseText; } catch (e) {}
            $pre.text(out || ('HTTP ' + xhr.status + ' — ' + xhr.statusText));
            $out.show();
            $status.text('Sync failed — see output below.').css('color', '#b91c1c');
        }).always(function () {
            $btn.prop('disabled', false).html(original);
        });
    });

    // Sync-everything handler — kicks off the umbrella clover:sync
    // command as a background process (nginx times out at 60s, and full
    // syncs can run minutes) and then polls /sync-status to pipe the log
    // tail into the same console block. Defaults to --days=30 so the
    // reconciliation report lights up for the last month of history.
    $('#eod_sync_all_btn').on('click', function () {
        var $btn = $(this);
        var $status = $('#eod_sync_status');
        var $out = $('#eod_sync_output');
        var $pre = $('#eod_sync_output_pre');
        var original = $btn.html();
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Syncing everything…');
        $status.text('Starting background sync (last 30 days)…').css('color', '#6b7280');
        $out.hide();

        $.ajax({
            url: '/business/clover/sync-now',
            method: 'POST',
            dataType: 'json',
            timeout: 20000,
            data: { _token: $('meta[name="csrf-token"]').attr('content'), days: 30 }
        }).done(function (r) {
            if (!r.success || !r.run_id) {
                $status.text('Could not start sync: ' + (r.msg || 'unknown')).css('color', '#b91c1c');
                $btn.prop('disabled', false).html(original);
                return;
            }
            $status.text('Sync running in background — tailing log…').css('color', '#6b7280');
            $out.show();
            $pre.text('(starting…)');

            var runId = r.run_id;
            var elapsed = 0;
            var interval = setInterval(function () {
                elapsed += 3;
                $.ajax({
                    url: '/business/clover/sync-status',
                    method: 'GET',
                    dataType: 'json',
                    data: { run_id: runId }
                }).done(function (s) {
                    if (s.output) $pre.text(s.output);
                    if (s.finished) {
                        clearInterval(interval);
                        $status.text('Sync complete (' + elapsed + 's) — refresh to see updated numbers.').css('color', '#166534');
                        $btn.prop('disabled', false).html(original);
                    } else {
                        $status.text('Sync running… ' + elapsed + 's').css('color', '#6b7280');
                    }
                });
                // Give up polling after 10 min; the sync may still be
                // running server-side but we stop spamming the status
                // endpoint.
                if (elapsed > 600) {
                    clearInterval(interval);
                    $status.text('Still running after 10 min — check logs on server.').css('color', '#b45309');
                    $btn.prop('disabled', false).html(original);
                }
            }, 3000);
        }).fail(function (xhr) {
            $status.text('Failed to start sync — ' + xhr.status).css('color', '#b91c1c');
            $btn.prop('disabled', false).html(original);
        });
    });

    // Per-store "Mark reconciled" checkbox — toggles the audit stamp on
    // the clover_reconciliations row for (day, location_id).
    $('.eod-loc-card').on('change', '.eod-recon-checkbox', function () {
        var $card = $(this).closest('.eod-loc-card');
        var $lbl = $card.find('.eod-recon-label');
        var $stamp = $card.find('.eod-recon-stamp');
        var $toggle = $card.find('.eod-recon-toggle');
        $lbl.text('Saving…');
        $.ajax({
            url: '/reports/clover-eod/mark-reconciled',
            method: 'POST',
            dataType: 'json',
            data: {
                _token: $('meta[name="csrf-token"]').attr('content'),
                day: $card.data('day'),
                location_id: $card.data('location-id')
            }
        }).done(function (r) {
            if (!r.success) {
                $lbl.text('Mark reconciled');
                alert(r.msg || 'Could not save.');
                return;
            }
            if (r.reconciled) {
                $lbl.text('✓ Reconciled');
                $toggle.css('color', '#166534');
                $stamp.text('Reconciled' + (r.reconciled_by ? ' by ' + r.reconciled_by : '') + ' · ' + (r.reconciled_at || ''));
            } else {
                $lbl.text('Mark reconciled');
                $toggle.css('color', '#374151');
                $stamp.text('');
            }
        }).fail(function () {
            $lbl.text('Mark reconciled');
            alert('Network error — try again.');
        });
    });

    // Per-store notes — debounced autosave on input + immediate save on blur.
    (function () {
        var timers = new WeakMap();
        $('.eod-loc-card').on('input', '.eod-recon-notes', function () {
            var el = this;
            var $status = $(el).siblings('.eod-recon-notes-status');
            $status.text('Typing…').css('color', '#9ca3af');
            if (timers.get(el)) clearTimeout(timers.get(el));
            timers.set(el, setTimeout(function () { saveNotes(el); }, 900));
        });
        $('.eod-loc-card').on('blur', '.eod-recon-notes', function () {
            if (timers.get(this)) clearTimeout(timers.get(this));
            saveNotes(this);
        });

        function saveNotes(el) {
            var $el = $(el);
            var $card = $el.closest('.eod-loc-card');
            var $status = $el.siblings('.eod-recon-notes-status');
            $status.text('Saving…').css('color', '#9ca3af');
            $.ajax({
                url: '/reports/clover-eod/save-notes',
                method: 'POST',
                dataType: 'json',
                data: {
                    _token: $('meta[name="csrf-token"]').attr('content'),
                    day: $card.data('day'),
                    location_id: $card.data('location-id'),
                    notes: $el.val()
                }
            }).done(function (r) {
                $status.text(r.success ? ('Saved ' + (r.saved_at || '')) : 'Save failed').css('color', r.success ? '#166534' : '#b91c1c');
            }).fail(function () {
                $status.text('Save failed — will retry on blur').css('color', '#b91c1c');
            });
        }
    })();
});
</script>
@endsection
