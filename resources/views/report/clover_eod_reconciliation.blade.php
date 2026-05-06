@extends('layouts.app')
@section('title', 'Clover EOD Reconciliation')

@section('content')
<section class="content-header no-print">
    <h1>Daily Cash Reconciliation <small>per-cashier sales + drawer check, one day at a time</small></h1>
</section>

<section class="content no-print">
    {{-- Filters retired 2026-05-05 — page is now strictly single-day,
         use the prev/next/today nav. Location stays "All" by default;
         deep-link via ?location_id=N if needed. --}}
    @if(false)
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
    @endif

    {{-- Sync-now button — Sarah 2026-04-22: Clover column was $0 across
         every day because the scheduled clover:sync-payments wasn't
         running (or credentials aren't set). This button fires the
         same command on demand and prints the raw stdout so a failed
         API call / missing creds / zero-payment day are all visible
         instead of buried in a log file. Admin-only on the backend. --}}
    <div style="margin-bottom:12px; text-align:right;">
        {{-- Sarah 2026-05-06: removed the days-back dropdown and the
             "Sync everything" button — both confusing. This page only
             needs the Clover-payments sync (the swipes). "Sync
             everything" was the broader items+orders+customers sync,
             which is irrelevant to the daily cash reconciliation flow
             and lives elsewhere if she ever needs it. Hidden input
             keeps the existing JS handler working with a fixed 2-day
             pull window. --}}
        <input type="hidden" id="eod_sync_days" value="2">
        <button type="button" class="btn btn-default" id="eod_sync_now_btn">
            <i class="fa fa-sync"></i> Refresh Clover swipes
        </button>
        <span id="eod_sync_status" style="margin-left:8px; font-size:12px; color:#6b7280;"></span>
    </div>
    <div id="eod_sync_output" style="display:none; margin-bottom:14px;">
        <div style="background:#111827; color:#e5e7eb; padding:12px 14px; border-radius:8px; font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace; font-size:12px; white-space:pre-wrap; max-height:260px; overflow:auto;" id="eod_sync_output_pre"></div>
    </div>

    {{-- Day summary — tallied from the per-cashier card data so it
         reflects the same source of truth Sarah's reading below.
         Sarah 2026-05-06: previous red banner with aggregate over-swipe
         and "drawer off" alerts was confusing because per-cashier cards
         already flag those issues themselves. Calmer neutral summary
         now: just the totals and reconcile progress. --}}
    @php
        $sumTotal = 0.0; $sumCard = 0.0; $sumCashiers = 0; $sumReconciled = 0;
        foreach ($employee_breakdown_by_day as $_dayBlock) {
            foreach ($_dayBlock['locations'] as $_loc) {
                foreach ($_loc['employees'] as $_e) {
                    $_emp = strtolower(trim($_e['display_name'] ?? ''));
                    if ($_emp === 'unknown' || $_emp === 'unattributed') continue;
                    $sumCashiers++;
                    $sumTotal += (float) ($_e['total_sales'] ?? 0);
                    $sumCard  += (float) ($_e['clover_total'] ?? 0);
                    $_rKey = $_dayBlock['day'] . '|' . ($_loc['location_id'] ?: 0) . '|' . $_emp;
                    if (!empty($reconciliations[$_rKey]) && optional($reconciliations[$_rKey])->reconciled_at) $sumReconciled++;
                }
            }
        }
        // Clamp implied cash at zero — a negative number here means the
        // amount-match attribution credited Clover swipes from outside
        // this day to a today cashier. Showing a negative "Paid in cash"
        // is more confusing than helpful; the per-card warnings flag the
        // specific cashier where it happened.
        $sumCash = max(0, round($sumTotal - $sumCard, 2));
    @endphp
    <div style="background:#f3f4f6; border:1px solid #e5e7eb; border-radius:8px; padding:10px 14px; margin-bottom:16px; font-size:14px; color:#1f2937; font-variant-numeric: tabular-nums;">
        Total sold: <strong>${{ number_format($sumTotal, 2) }}</strong>
        &nbsp;·&nbsp; Paid by card: <strong>${{ number_format($sumCard, 2) }}</strong>
        &nbsp;·&nbsp; Paid in cash: <strong>${{ number_format($sumCash, 2) }}</strong>
        &nbsp;·&nbsp; Reconciled: <strong>{{ $sumReconciled }} of {{ $sumCashiers }}</strong>
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

    {{-- Per-cashier daily reconciliation — the primary view (Sarah,
         2026-05-05). One card per (cashier, day, location). Three plain
         sections answer her three questions:
           · WHAT THEY SOLD — cash + card, with the totals broken out
           · CARD CHECK — Clover settled vs ERP card sales for that
             cashier (the theft signal: a card swiped on Clover but
             never rung into the POS = pocketed)
           · CASH DRAWER — opening + cash sales − cash buys = expected,
             vs what the cashier counted at close (the wrong-change /
             skim signal)
         Each card has its own ✓ Reconciled toggle + notes so she can
         sign each cashier off independently. --}}
    @if(!empty($employee_breakdown_by_day))
        <style>
            .cc-day-block { margin-bottom: 22px; }
            .cc-day-head { font-size:13px; color:#6b7280; font-weight:700; letter-spacing:.04em; text-transform:uppercase; margin:6px 0 10px; }
            .cc-grid { display:grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap:14px; }
            .cc-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; }
            .cc-card.flag { border-color:#fecaca; background:#fff5f5; }
            .cc-card.warn { border-color:#fde68a; background:#fffbeb; }
            .cc-card.ok   { border-color:#bbf7d0; }
            .cc-head { display:flex; align-items:flex-start; justify-content:space-between; gap:8px; margin-bottom:10px; padding-bottom:8px; border-bottom:1px solid #e5e7eb; }
            .cc-title { font-size:16px; font-weight:800; color:#111827; }
            .cc-sub { font-size:11px; color:#6b7280; margin-top:2px; text-transform:uppercase; letter-spacing:.04em; }
            .cc-section { margin:10px 0 6px; }
            .cc-sec-h { font-size:10px; font-weight:800; color:#374151; text-transform:uppercase; letter-spacing:.06em; margin-bottom:4px; }
            .cc-line { display:flex; justify-content:space-between; align-items:baseline; font-size:13px; padding:2px 0; font-variant-numeric: tabular-nums; }
            .cc-line.sum { border-top:1px solid #d1d5db; padding-top:5px; margin-top:4px; font-weight:700; }
            .cc-label { color:#374151; }
            .cc-label.minor { color:#6b7280; font-size:12px; }
            .cc-val { font-weight:600; color:#111827; }
            .cc-val.muted { color:#9ca3af; font-weight:500; }
            .cc-val.ok { color:#166534; }
            .cc-val.warn { color:#b45309; }
            .cc-val.bad  { color:#b91c1c; }
            .cc-flag { display:inline-block; margin-left:6px; padding:1px 6px; border-radius:10px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
            .cc-flag.ok { background:#dcfce7; color:#166534; }
            .cc-flag.warn { background:#fef3c7; color:#92400e; }
            .cc-flag.bad { background:#fee2e2; color:#991b1b; }
            .cc-flag.muted { background:#f3f4f6; color:#6b7280; }
            .cc-foot { margin-top:10px; padding-top:10px; border-top:1px solid #e5e7eb; }

            /* Collapsed cards — once Sarah ticks "Mark reconciled" we
               shrink the card to just its header + total so signed-off
               cashiers fall out of her field of view but stay clickable
               to re-expand if she needs to revisit. Click the title to
               un-collapse, click the checkbox to toggle reconciled. */
            .cc-card.cc-collapsed { padding:10px 14px; cursor:pointer; opacity:.65; background:#f9fafb; }
            .cc-card.cc-collapsed.flag,
            .cc-card.cc-collapsed.warn { background:#f9fafb; border-color:#e5e7eb; }
            .cc-card.cc-collapsed .cc-head { margin-bottom:0; padding-bottom:0; border-bottom:none; }
            .cc-card.cc-collapsed .cc-section,
            .cc-card.cc-collapsed .cc-foot { display:none; }
            .cc-card.cc-collapsed .cc-collapsed-summary { display:block; }
            .cc-collapsed-summary { display:none; font-size:12px; color:#6b7280; margin-top:4px; font-variant-numeric: tabular-nums; }
        </style>

        @foreach($employee_breakdown_by_day as $dayBlock)
            @php
                $dayDate = \Carbon\Carbon::parse($dayBlock['day']);
                $dayHeader = $dayDate->isToday() ? 'Today'
                    : ($dayDate->isYesterday() ? 'Yesterday'
                    : $dayDate->format('l, F j'));
            @endphp
            <div class="cc-day-block">
                <div class="cc-day-head">{{ $dayHeader }} <span style="color:#9ca3af; font-weight:500; margin-left:6px;">{{ $dayDate->format('Y-m-d') }}</span></div>
                <div class="cc-grid">
                    @foreach($dayBlock['locations'] as $loc)
                        @php
                            $locNameRaw = $loc['location_name'];
                            $isNoLoc = (strtolower($locNameRaw) === '(no location)' || stripos($locNameRaw, 'no location') !== false);
                            $locNameDisplay = $isNoLoc ? 'No location' : $locNameRaw;
                        @endphp
                        @foreach($loc['employees'] as $e)
                            @php
                                // Skip the synthetic "Unknown" / "Unattributed" cashier
                                // buckets — those rows are walk-in, online, or no-pin
                                // sales and don't have a physical drawer to reconcile.
                                $empKey = strtolower(trim($e['display_name']));
                                if ($empKey === 'unknown' || $empKey === 'unattributed') continue;

                                // Source of truth: total_sales = sum of t.final_total for
                                // this cashier's transactions, IGNORING payment method.
                                // Cashiers ring everything as 'cash' regardless of how
                                // the customer actually paid, so the only trustworthy
                                // "what they sold" number is the transaction total.
                                $totalSold  = (float) ($e['total_sales'] ?? 0);
                                $cardClover = (float) ($e['clover_total'] ?? 0);
                                // Implied cash = what was sold minus what Clover settled.
                                // If Clover > sold (mis-keyed amount), don't go negative;
                                // surface as a flag instead.
                                $impliedCash = max(0.0, round($totalSold - $cardClover, 2));
                                $overSwipe   = round($cardClover - $totalSold, 2);

                                $opening     = $e['opening_cash'];
                                $cashBuys    = (float) ($e['cash_buys'] ?? 0);
                                $expected    = $e['expected_ending_cash'];
                                $reported    = $e['reported_ending_cash'];
                                $cashVar     = $e['cash_variance']; // null until shift closes
                                $hasShift    = !empty($e['has_shift']);
                                $shiftStatus = $e['shift_status'] ?? null;

                                // Sales-vs-Clover signal. If Clover collected more than
                                // ERP recorded as sold for this cashier, that's the theft
                                // tell — a card was swiped but no sale was rung.
                                if ($overSwipe < 1) { $swipeCls = 'ok'; $swipeLabel = 'OK'; }
                                elseif ($overSwipe < 10) { $swipeCls = 'warn'; $swipeLabel = 'Over swipe'; }
                                else { $swipeCls = 'bad'; $swipeLabel = 'Over swipe'; }

                                // Cash-check signal — drawer short / over.
                                if ($cashVar === null) {
                                    $cashCls = 'muted';
                                    $cashLabel = $hasShift && $shiftStatus === 'open' ? 'Shift open' : 'No close yet';
                                } else {
                                    $cashAbs = abs($cashVar);
                                    if ($cashAbs < 1) { $cashCls = 'ok'; $cashLabel = 'Even'; }
                                    elseif ($cashAbs < 5) { $cashCls = 'warn'; $cashLabel = $cashVar < 0 ? 'Short' : 'Over'; }
                                    else { $cashCls = 'bad'; $cashLabel = $cashVar < 0 ? 'Short' : 'Over'; }
                                }

                                $cardKind = ($swipeCls === 'bad' || $cashCls === 'bad') ? 'flag'
                                          : (($swipeCls === 'warn' || $cashCls === 'warn') ? 'warn' : 'ok');

                                // Reconciliation lookup — per-cashier key.
                                $rKey = $dayBlock['day'] . '|' . ($loc['location_id'] ?: 0) . '|' . $empKey;
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

                                $fmt = function ($t) {
                                    if (!$t) return null;
                                    try { return \Carbon\Carbon::parse($t)->setTimezone(config('app.timezone'))->format('g:i a'); }
                                    catch (\Exception $ex) { return null; }
                                };
                                $shiftLabel = null;
                                if (!empty($e['shift_start'])) {
                                    $a = $fmt($e['shift_start']);
                                    $b = $shiftStatus === 'open' ? 'open' : $fmt($e['shift_end']);
                                    if ($a) $shiftLabel = $a . ' → ' . ($b ?: '—');
                                }
                                $txnCount = (int) ($e['txn_count'] ?? 0);
                            @endphp
                            <div class="cc-card {{ $cardKind }} {{ $isReconciled ? 'cc-collapsed' : '' }} eod-loc-card"
                                 data-day="{{ $dayBlock['day'] }}"
                                 data-location-id="{{ $loc['location_id'] ?: 0 }}"
                                 data-employee-key="{{ $empKey }}">
                                <div class="cc-head">
                                    <div style="flex:1; min-width:0;">
                                        <div class="cc-title">{{ $e['display_name'] }}</div>
                                        <div class="cc-sub">{{ $locNameDisplay }}@if($shiftLabel) · {{ $shiftLabel }}@endif</div>
                                        <div class="cc-collapsed-summary">${{ number_format($totalSold, 2) }} sold @if($txnCount) · {{ $txnCount }} sale{{ $txnCount === 1 ? '' : 's' }}@endif @if(!is_null($cashVar)) · drawer {{ $cashVar >= 0 ? '+' : '' }}${{ number_format($cashVar, 2) }}@endif</div>
                                    </div>
                                    <label class="eod-recon-toggle" style="display:inline-flex; align-items:center; gap:6px; font-size:12px; font-weight:600; cursor:pointer; color:{{ $isReconciled ? '#166534' : '#374151' }}; white-space:nowrap;" onclick="event.stopPropagation();">
                                        <input type="checkbox" class="eod-recon-checkbox" {{ $isReconciled ? 'checked' : '' }}>
                                        <span class="eod-recon-label">{{ $isReconciled ? '✓ Reconciled' : 'Mark reconciled' }}</span>
                                    </label>
                                </div>

                                {{-- WHAT THEY SOLD — total + payment split.
                                     Sarah 2026-05-06: "we don't necessarily
                                     know if cash or credit was collected" —
                                     so the non-Clover bucket is labeled
                                     "Not on Clover" rather than asserted as
                                     cash. The cashier could've taken cash,
                                     or Clover sync might be lagging. --}}
                                @php
                                    $missingClover = $totalSold > 0 && $cardClover == 0;
                                @endphp
                                <div class="cc-section">
                                    <div class="cc-sec-h">What they sold @if($txnCount)<span style="font-weight:500; color:#9ca3af;">· {{ $txnCount }} sale{{ $txnCount === 1 ? '' : 's' }}</span>@endif</div>
                                    <div class="cc-line sum"><span class="cc-label">Total sales</span><span class="cc-val">${{ number_format($totalSold, 2) }}</span></div>
                                    <div class="cc-line"><span class="cc-label minor">— matched on Clover</span><span class="cc-val">${{ number_format($cardClover, 2) }}</span></div>
                                    <div class="cc-line"><span class="cc-label minor">— not on Clover (cash or unsynced)</span><span class="cc-val">${{ number_format($impliedCash, 2) }}</span></div>
                                    @if($missingClover)
                                        <div class="cc-line"><span class="cc-label" style="color:#92400e;">⚠ No Clover swipes matched — sync may be stale</span><span class="cc-val"></span></div>
                                    @endif
                                    @if($overSwipe >= 1)
                                        <div class="cc-line"><span class="cc-label" style="color:#b91c1c;">⚠ Clover collected more than rung</span><span class="cc-val bad">+${{ number_format($overSwipe, 2) }}</span></div>
                                    @endif
                                </div>

                                {{-- CASH DRAWER — opening + cash collected − buys = expected vs counted --}}
                                @php
                                    $cashRefunds      = (float) ($e['cash_refunds']       ?? 0);
                                    $cashExpenses     = (float) ($e['cash_expenses']      ?? 0);
                                    $cashTransfersOut = (float) ($e['cash_transfers_out'] ?? 0);
                                    $cashTransfersIn  = (float) ($e['cash_transfers_in']  ?? 0);
                                    $cashOtherNet     = (float) ($e['cash_other_net']     ?? 0);
                                @endphp
                                <div class="cc-section">
                                    <div class="cc-sec-h">Cash drawer <span class="cc-flag {{ $cashCls }}">{{ $cashLabel }}</span></div>
                                    <div class="cc-line"><span class="cc-label minor">Opening cash</span><span class="cc-val {{ is_null($opening) ? 'muted' : '' }}">{{ is_null($opening) ? '—' : '$' . number_format($opening, 2) }}</span></div>
                                    <div class="cc-line"><span class="cc-label minor">+ Not on Clover (assumed cash)</span><span class="cc-val">${{ number_format($impliedCash, 2) }}</span></div>
                                    @if($cashBuys > 0 || $hasShift)
                                        <div class="cc-line"><span class="cc-label minor">− Collection buys (cash)</span><span class="cc-val {{ $hasShift ? '' : 'muted' }}">{{ $hasShift ? '$' . number_format($cashBuys, 2) : '—' }}</span></div>
                                    @endif
                                    @if($cashRefunds > 0)
                                        <div class="cc-line"><span class="cc-label minor">− Cash refunds</span><span class="cc-val">$ {{ number_format($cashRefunds, 2) }}</span></div>
                                    @endif
                                    @if($cashExpenses > 0)
                                        <div class="cc-line"><span class="cc-label minor">− Expenses</span><span class="cc-val">$ {{ number_format($cashExpenses, 2) }}</span></div>
                                    @endif
                                    @if($cashTransfersOut > 0)
                                        <div class="cc-line"><span class="cc-label minor">− Transferred out</span><span class="cc-val">$ {{ number_format($cashTransfersOut, 2) }}</span></div>
                                    @endif
                                    @if($cashTransfersIn > 0)
                                        <div class="cc-line"><span class="cc-label minor">+ Transferred in</span><span class="cc-val">$ {{ number_format($cashTransfersIn, 2) }}</span></div>
                                    @endif
                                    @if(abs($cashOtherNet) >= 0.01)
                                        <div class="cc-line"><span class="cc-label minor">{{ $cashOtherNet >= 0 ? '+' : '−' }} Other movements</span><span class="cc-val">${{ number_format(abs($cashOtherNet), 2) }}</span></div>
                                    @endif
                                    <div class="cc-line sum"><span class="cc-label">Expected in drawer</span><span class="cc-val {{ is_null($expected) ? 'muted' : '' }}">{{ is_null($expected) ? '—' : '$' . number_format($expected, 2) }}</span></div>
                                    <div class="cc-line"><span class="cc-label">Counted at close</span><span class="cc-val {{ is_null($reported) ? 'muted' : '' }}">{{ is_null($reported) ? '—' : '$' . number_format($reported, 2) }}</span></div>
                                    <div class="cc-line sum"><span class="cc-label">Variance</span><span class="cc-val {{ $cashCls }}">{{ is_null($cashVar) ? '—' : (($cashVar >= 0 ? '+' : '') . '$' . number_format($cashVar, 2)) }}</span></div>
                                </div>

                                {{-- Variance investigation drill-down — Sarah
                                     2026-05-06: "I need to figure out all
                                     these variances daily." Each list is the
                                     transactions that explain (or fail to
                                     explain) the variance:
                                       · Clover-only = over-swipe (theft tell)
                                       · ERP-only = likely cash, but a missed
                                         swipe shows up here too
                                       · Cash buys = real money out of drawer
                                     Collapsed by default to keep the card
                                     compact. Click to expand. --}}
                                @php
                                    $details = $e['details'] ?? ['clover_unmatched'=>[], 'erp_unmatched'=>[], 'amount_mismatch'=>[], 'buys'=>[]];
                                    $cuCount = count($details['clover_unmatched'] ?? []);
                                    $euCount = count($details['erp_unmatched'] ?? []);
                                    $amCount = count($details['amount_mismatch'] ?? []);
                                    $bCount  = count($details['buys'] ?? []);
                                    $detailsTotal = $cuCount + $euCount + $amCount + $bCount;
                                    $tFmt = function ($t) {
                                        if (!$t) return '—';
                                        try { return \Carbon\Carbon::parse($t)->setTimezone(config('app.timezone'))->format('g:i a'); }
                                        catch (\Exception $ex) { return '—'; }
                                    };
                                @endphp
                                @if($detailsTotal > 0)
                                    <details class="cc-details" style="margin-top:8px; border-top:1px solid #e5e7eb; padding-top:8px;" onclick="event.stopPropagation();">
                                        <summary style="cursor:pointer; font-size:11px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:.04em; padding:2px 0; user-select:none;">
                                            Show breakdown
                                            @if($cuCount > 0)<span style="color:#b91c1c; font-weight:600; text-transform:none; margin-left:6px;">· {{ $cuCount }} over-swipe</span>@endif
                                            @if($amCount > 0)<span style="color:#b45309; font-weight:600; text-transform:none; margin-left:6px;">· {{ $amCount }} keying error{{ $amCount === 1 ? '' : 's' }}</span>@endif
                                            @if($euCount > 0)<span style="color:#374151; font-weight:500; text-transform:none; margin-left:6px;">· {{ $euCount }} cash sale{{ $euCount === 1 ? '' : 's' }}</span>@endif
                                            @if($bCount > 0)<span style="color:#374151; font-weight:500; text-transform:none; margin-left:6px;">· {{ $bCount }} buy{{ $bCount === 1 ? '' : 's' }}</span>@endif
                                        </summary>
                                        <div style="margin-top:8px; font-size:12px; font-variant-numeric: tabular-nums;">
                                            @if($cuCount > 0)
                                                <div style="margin-top:4px;"><span style="font-size:10px; font-weight:700; color:#b91c1c; text-transform:uppercase;">Clover-only (over-swipe)</span></div>
                                                @php $cuSum = 0; @endphp
                                                @foreach($details['clover_unmatched'] as $row)
                                                    @php $cuSum += (float) $row->amount; @endphp
                                                    <div style="display:flex; justify-content:space-between; padding:2px 0; border-bottom:1px dotted #f3f4f6;">
                                                        <span style="color:#6b7280;">{{ $tFmt($row->ts) }}</span>
                                                        <span style="color:#b91c1c; font-weight:600;">${{ number_format($row->amount, 2) }}</span>
                                                    </div>
                                                @endforeach
                                                <div style="display:flex; justify-content:space-between; padding:3px 0; font-weight:700;"><span>Subtotal</span><span style="color:#b91c1c;">${{ number_format($cuSum, 2) }}</span></div>
                                            @endif
                                            @if($amCount > 0)
                                                <div style="margin-top:8px;"><span style="font-size:10px; font-weight:700; color:#b45309; text-transform:uppercase;">Keying errors (Clover amount ≠ ERP amount)</span></div>
                                                @php $amSum = 0; @endphp
                                                @foreach($details['amount_mismatch'] as $row)
                                                    @php $amSum += (float) $row->diff; @endphp
                                                    <div style="display:flex; justify-content:space-between; padding:2px 0; border-bottom:1px dotted #f3f4f6;">
                                                        <span style="color:#6b7280;">{{ $tFmt($row->ts) }}</span>
                                                        <span style="text-align:right;">
                                                            <a href="{{ route('sell.printInvoice', $row->transaction_id) }}" target="_blank" style="color:#1f2937; font-weight:600; text-decoration:none;">
                                                                Clover ${{ number_format($row->clover_amount, 2) }} vs ERP ${{ number_format($row->erp_amount, 2) }}
                                                            </a>
                                                            <span style="color:{{ $row->diff < 0 ? '#b91c1c' : '#92400e' }}; font-weight:700; margin-left:6px;">{{ $row->diff < 0 ? 'under' : 'over' }} ${{ number_format(abs($row->diff), 2) }}</span>
                                                        </span>
                                                    </div>
                                                @endforeach
                                                <div style="display:flex; justify-content:space-between; padding:3px 0; font-weight:700;"><span>Net keying drift</span><span style="color:{{ $amSum < 0 ? '#b91c1c' : '#92400e' }};">{{ $amSum >= 0 ? '+' : '' }}${{ number_format($amSum, 2) }}</span></div>
                                            @endif
                                            @if($euCount > 0)
                                                <div style="margin-top:8px;"><span style="font-size:10px; font-weight:700; color:#374151; text-transform:uppercase;">ERP-only (likely cash, or missed swipe)</span></div>
                                                @php $euSum = 0; @endphp
                                                @foreach($details['erp_unmatched'] as $row)
                                                    @php $euSum += (float) $row->amount; @endphp
                                                    <div style="display:flex; justify-content:space-between; padding:2px 0; border-bottom:1px dotted #f3f4f6;">
                                                        <span style="color:#6b7280;">{{ $tFmt($row->ts) }}</span>
                                                        <span><a href="{{ route('sell.printInvoice', $row->transaction_id) }}" target="_blank" style="color:#1f2937; font-weight:600; text-decoration:none;">${{ number_format($row->amount, 2) }}</a></span>
                                                    </div>
                                                @endforeach
                                                <div style="display:flex; justify-content:space-between; padding:3px 0; font-weight:700;"><span>Subtotal</span><span>${{ number_format($euSum, 2) }}</span></div>
                                            @endif
                                            @if($bCount > 0)
                                                <div style="margin-top:8px;"><span style="font-size:10px; font-weight:700; color:#374151; text-transform:uppercase;">Cash buys (collection purchases)</span></div>
                                                @php $bSum = 0; @endphp
                                                @foreach($details['buys'] as $row)
                                                    @php $bSum += (float) $row->amount; @endphp
                                                    <div style="display:flex; justify-content:space-between; padding:2px 0; border-bottom:1px dotted #f3f4f6;">
                                                        <span style="color:#6b7280;">{{ $tFmt($row->ts) }}</span>
                                                        <span style="color:#1f2937; font-weight:600;">−${{ number_format($row->amount, 2) }}</span>
                                                    </div>
                                                @endforeach
                                                <div style="display:flex; justify-content:space-between; padding:3px 0; font-weight:700;"><span>Subtotal</span><span>−${{ number_format($bSum, 2) }}</span></div>
                                            @endif
                                        </div>
                                    </details>
                                @endif

                                <div class="cc-foot">
                                    @php
                                        $viewQs = ['location_id' => $loc['location_id'] ?: '', 'start_date' => $dayBlock['day'], 'end_date' => $dayBlock['day'], 'limit' => 200, 'hide_orphans' => 1];
                                        if (!empty($e['user_id'])) $viewQs['created_by'] = (int) $e['user_id'];
                                    @endphp
                                    <a href="/pos/recent-feed?{{ http_build_query($viewQs) }}" target="_blank"
                                       style="font-size:11px; color:#4f46e5; text-decoration:none; font-weight:600;">
                                        View {{ $e['display_name'] }}'s sales (today) →
                                    </a>
                                    <textarea class="eod-recon-notes form-control" rows="2"
                                        placeholder="Notes for {{ $e['display_name'] }} (auto-saves)"
                                        style="font-size:12px; resize:vertical; margin-top:6px;">{{ $recNotes }}</textarea>
                                    <div class="eod-recon-notes-status" style="font-size:11px; color:#9ca3af; margin-top:2px; min-height:14px;">{{ $recStampLabel }}</div>
                                </div>
                            </div>
                        @endforeach
                    @endforeach
                </div>
            </div>
        @endforeach
        <p class="help-block" style="margin-top:-6px; margin-bottom:18px;">
            <strong>Total sales</strong> is the sum of every sale this cashier rang up, regardless of how the cashier punched the payment method (Sarah 2026-05-05: cashiers ring all sales as cash even when the customer pays by card). <strong>Paid by card</strong> = matched against Clover settlements; <strong>paid in cash</strong> = the rest. <strong>Over swipe</strong> means Clover collected more than the cashier rang up — the theft tell.
            <strong>Cash drawer</strong>: opening + cash collected − cash buys should equal what the cashier counted at close. <em>Short</em> = drawer low (skim or wrong change), <em>Over</em> = drawer high (mis-rung sale).
        </p>
    @endif

    {{-- Per-shift theft audit — the view Sarah actually wants. One card
         per cashier shift, two plain-language checks:
         SALES CHECK (Clover card ↔ ERP card during shift) catches keying
         errors + skimmed sales. CASH CHECK (opening + cash sales − buys
         = expected vs reported) catches drawer shortages.
         Drill-in on the SALES CHECK shows the raw Clover + ERP payment
         lists side-by-side, so Fatteen can find the specific sale with
         a typo when the totals disagree.

         Sarah 2026-05-05: hidden — daily cash reconciliation now uses the
         xlsx-style per-store side-by-side panel below. Block is kept so
         it can be re-enabled later by removing the `false &&` guard. --}}
    @if(false && !empty($shift_audit))
        <style>
            .sa-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px 18px; margin-bottom:14px; }
            .sa-card.flag { border-color:#fecaca; background:#fef2f2; }
            .sa-card.warn { border-color:#fde68a; background:#fffbeb; }
            .sa-head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; margin-bottom:12px; padding-bottom:10px; border-bottom:1px solid #e5e7eb; }
            .sa-title { font-size:16px; font-weight:700; color:#111827; }
            .sa-sub { font-size:12px; color:#6b7280; margin-top:2px; }
            .sa-pill { display:inline-block; padding:2px 8px; border-radius:999px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
            .sa-pill.open { background:#fef3c7; color:#92400e; }
            .sa-pill.closed { background:#e5e7eb; color:#374151; }
            .sa-checks { display:grid; grid-template-columns: 1fr 1fr; gap:20px; }
            @media (max-width: 720px) { .sa-checks { grid-template-columns: 1fr; } }
            .sa-check h4 { margin:0 0 6px; font-size:12px; font-weight:800; text-transform:uppercase; letter-spacing:.04em; color:#374151; }
            .sa-line { display:flex; justify-content:space-between; font-size:14px; padding:3px 0; font-variant-numeric: tabular-nums; }
            .sa-line.sum { border-top:1px solid #d1d5db; padding-top:6px; margin-top:4px; font-weight:700; }
            .sa-line.warn { color:#b45309; }
            .sa-line.bad  { color:#b91c1c; }
            .sa-line.ok   { color:#166534; }
            .sa-verdict { margin-top:6px; font-size:13px; font-weight:700; padding:6px 10px; border-radius:6px; }
            .sa-verdict.ok   { background:#f0fdf4; color:#166534; }
            .sa-verdict.warn { background:#fffbeb; color:#b45309; }
            .sa-verdict.bad  { background:#fef2f2; color:#b91c1c; }
            .sa-verdict.muted { background:#f9fafb; color:#6b7280; }
            .sa-drill { margin-top:12px; }
            .sa-drill summary { cursor:pointer; font-size:12px; color:#374151; padding:6px 0; font-weight:600; }
            .sa-drill[open] summary { color:#111827; }
            .sa-sbs { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-top:8px; }
            @media (max-width: 720px) { .sa-sbs { grid-template-columns: 1fr; } }
            .sa-list { width:100%; font-size:12px; border-collapse:collapse; font-variant-numeric: tabular-nums; }
            .sa-list th { text-align:left; color:#6b7280; font-weight:600; font-size:10px; text-transform:uppercase; letter-spacing:.04em; border-bottom:1px solid #e5e7eb; padding:4px 6px; white-space:nowrap; }
            .sa-list td { padding:4px 6px; border-bottom:1px solid #f3f4f6; white-space:nowrap; }
            .sa-list td.num { text-align:right; }
            .sa-list tr.totals td { border-top:2px solid #d1d5db; font-weight:700; background:#f9fafb; }
            .sa-actions { margin-top:12px; padding-top:10px; border-top:1px solid #e5e7eb; display:flex; justify-content:space-between; gap:12px; align-items:flex-start; }
            .sa-actions label { font-size:13px; font-weight:600; cursor:pointer; }
        </style>

        <h4 style="margin: 10px 0 6px; font-size: 14px; color: #111827; font-weight: 700;">Cashier shift audit</h4>
        <p class="help-block" style="margin-top:0; margin-bottom:14px;">
            One card per cashier shift. <strong>Sales check</strong> compares Clover card sales to ERP card sales during the shift — a mismatch usually means a keying error at the Clover terminal.
            <strong>Cash check</strong> reconciles the drawer: opening cash + cash sales − cash buys/refunds should equal what the cashier counted at close.
        </p>

        @php
            // Group shift cards by the day they opened, most-recent day first.
            // Sarah was getting confused by Apr-23 and Apr-24 cards stacked
            // with no visual separation; a clear "Today" / "Yesterday" header
            // above each group makes it obvious which shift belongs to which
            // day before she even reads the times on the card.
            $shiftsByDay = collect($shift_audit)
                ->groupBy(fn($s) => $s['opened_at']->copy()->setTimezone(config('app.timezone'))->format('Y-m-d'))
                ->sortKeysDesc();
            $tzToday = \Carbon\Carbon::today(config('app.timezone'))->format('Y-m-d');
            $tzYesterday = \Carbon\Carbon::yesterday(config('app.timezone'))->format('Y-m-d');
        @endphp

        @foreach($shiftsByDay as $dayKey => $dayShifts)
            @php
                $dayHeader = $dayKey === $tzToday ? 'Today'
                    : ($dayKey === $tzYesterday ? 'Yesterday'
                    : \Carbon\Carbon::parse($dayKey)->format('l'));
                $dayDate = \Carbon\Carbon::parse($dayKey)->format('M j, Y');
            @endphp
            <div style="margin: 18px 0 8px; padding: 6px 10px; background: #f3f4f6; border-left: 4px solid #6366f1; border-radius: 4px;">
                <span style="font-size: 13px; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; color: #1f2937;">{{ $dayHeader }}</span>
                <span style="font-size: 12px; color: #6b7280; margin-left: 8px;">{{ $dayDate }}</span>
                <span style="font-size: 11px; color: #9ca3af; margin-left: 8px;">· {{ count($dayShifts) }} shift{{ count($dayShifts) === 1 ? '' : 's' }}</span>
            </div>

        @foreach($dayShifts as $s)
            @php
                $salesDiffAbs = abs($s['sales_diff']);
                $salesBad = $salesDiffAbs >= 10;
                $salesWarn = !$salesBad && $salesDiffAbs >= 1;
                $salesOk = $salesDiffAbs < 1;

                $cashVar = $s['cash_variance'];
                $cashBad = $cashVar !== null && abs($cashVar) >= 10;
                $cashWarn = !$cashBad && $cashVar !== null && abs($cashVar) >= 1;
                $cashOk = $cashVar !== null && abs($cashVar) < 1;
                $cashPending = $cashVar === null;

                $cardKind = ($salesBad || $cashBad) ? 'flag' : (($salesWarn || $cashWarn) ? 'warn' : '');
                $rKey = $s['opened_at']->format('Y-m-d') . '|' . ($s['location_id'] ?: 0);
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

                $openedTs = $s['opened_at']->copy()->setTimezone(config('app.timezone'));
                $closedTs = $s['closed_at'] ? $s['closed_at']->copy()->setTimezone(config('app.timezone')) : null;
                $shiftLabel = $openedTs->format('M j, g:i a') . ' → '
                    . ($s['is_open']
                        ? '<span class="sa-pill open">shift open</span>'
                        : $closedTs->format('g:i a') . ' <span class="sa-pill closed">closed</span>');
            @endphp
            <div class="sa-card {{ $cardKind }}" data-day="{{ $s['opened_at']->format('Y-m-d') }}" data-location-id="{{ $s['location_id'] ?: 0 }}">
                <div class="sa-head">
                    <div>
                        <div class="sa-title">{{ $s['user_name'] }} · {{ $s['location_name'] }}</div>
                        <div class="sa-sub">{!! $shiftLabel !!}</div>
                    </div>
                    <div style="text-align:right; min-width:220px;">
                        <label class="eod-recon-toggle" style="display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600; cursor:pointer; color:{{ $isReconciled ? '#166534' : '#374151' }};">
                            <input type="checkbox" class="eod-recon-checkbox" {{ $isReconciled ? 'checked' : '' }}>
                            <span class="eod-recon-label">{{ $isReconciled ? '✓ Reconciled' : 'Mark reconciled' }}</span>
                        </label>
                        <div class="eod-recon-stamp" style="font-size:11px; color:#6b7280; margin-top:2px;">{{ $recStampLabel }}</div>
                    </div>
                </div>

                <div class="sa-checks">
                    {{-- SALES CHECK --}}
                    <div class="sa-check">
                        <h4>💳 Sales check · Clover ↔ ERP</h4>
                        <div class="sa-line"><span>Clover card sales</span><span>${{ number_format($s['clover_card_total'], 2) }}</span></div>
                        <div class="sa-line"><span>ERP card sales</span><span>${{ number_format($s['erp_card_total'], 2) }}</span></div>
                        <div class="sa-line sum {{ $salesOk ? 'ok' : ($salesWarn ? 'warn' : 'bad') }}">
                            <span>Difference (Clover − ERP)</span>
                            <span>{{ $s['sales_diff'] >= 0 ? '+' : '' }}${{ number_format($s['sales_diff'], 2) }}</span>
                        </div>
                        @if($salesOk)
                            <div class="sa-verdict ok">✓ Sales match</div>
                        @elseif($salesWarn)
                            <div class="sa-verdict warn">⚠ Minor difference — eyeball below</div>
                        @else
                            <div class="sa-verdict bad">🚨 ${{ number_format(abs($s['sales_diff']), 2) }} mismatch — likely a keying error at Clover</div>
                        @endif
                    </div>

                    {{-- CASH CHECK --}}
                    <div class="sa-check">
                        <h4>💵 Cash check · drawer math</h4>
                        <div class="sa-line"><span>Opening cash</span><span>${{ number_format($s['opening_cash'], 2) }}</span></div>
                        <div class="sa-line"><span>+ Cash sales</span><span>${{ number_format($s['cash_sales'], 2) }}</span></div>
                        <div class="sa-line"><span>− Cash buys / refunds</span><span>−${{ number_format($s['cash_buys'] + $s['cash_refunds'], 2) }}</span></div>
                        <div class="sa-line sum"><span>= Expected closing</span><span>${{ number_format($s['expected_closing_cash'], 2) }}</span></div>
                        <div class="sa-line"><span>Reported closing</span>
                            <span>{{ $s['reported_closing_cash'] === null ? '— (shift open)' : '$' . number_format($s['reported_closing_cash'], 2) }}</span>
                        </div>
                        @if($cashPending)
                            <div class="sa-verdict muted">Shift still open — variance will show at close</div>
                        @elseif($cashOk)
                            <div class="sa-verdict ok">✓ Drawer matches</div>
                        @elseif($cashWarn)
                            <div class="sa-verdict warn">⚠ Drawer {{ $cashVar >= 0 ? 'over' : 'short' }} ${{ number_format(abs($cashVar), 2) }}</div>
                        @else
                            <div class="sa-verdict bad">🚨 Drawer {{ $cashVar >= 0 ? 'over' : 'short' }} ${{ number_format(abs($cashVar), 2) }} — investigate</div>
                        @endif
                    </div>
                </div>

                @if(!$salesOk && (count($s['clover_payments']) > 0 || count($s['erp_payments']) > 0))
                    <details class="sa-drill">
                        <summary>▸ Show sales side-by-side to find the mismatch</summary>
                        <div class="sa-sbs">
                            <div>
                                <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#374151; margin-bottom:4px;">Clover</div>
                                <table class="sa-list">
                                    <thead><tr><th>Time</th><th class="num">Amount</th><th>Card</th></tr></thead>
                                    <tbody>
                                        @foreach($s['clover_payments'] as $p)
                                            <tr>
                                                <td>{{ \Carbon\Carbon::parse($p->ts)->setTimezone(config('app.timezone'))->format('g:i a') }}</td>
                                                <td class="num">${{ number_format((float) $p->amount, 2) }}</td>
                                                <td>{{ trim(($p->card_type ?? '') . ($p->card_last4 ? ' ****' . $p->card_last4 : '')) ?: $p->tender_type }}</td>
                                            </tr>
                                        @endforeach
                                        <tr class="totals"><td>Total</td><td class="num">${{ number_format($s['clover_card_total'], 2) }}</td><td></td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div>
                                <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#374151; margin-bottom:4px;">ERP</div>
                                <table class="sa-list">
                                    <thead><tr><th>Time</th><th class="num">Amount</th><th>Invoice</th></tr></thead>
                                    <tbody>
                                        @foreach($s['erp_payments'] as $p)
                                            <tr>
                                                <td>{{ \Carbon\Carbon::parse($p->ts)->setTimezone(config('app.timezone'))->format('g:i a') }}</td>
                                                <td class="num">
                                                    <a href="{{ route('sell.printInvoice', $p->transaction_id) }}" target="_blank">${{ number_format((float) $p->amount, 2) }}</a>
                                                </td>
                                                <td>{{ $p->invoice_no ?: ('#' . $p->transaction_id) }}</td>
                                            </tr>
                                        @endforeach
                                        <tr class="totals"><td>Total</td><td class="num">${{ number_format($s['erp_card_total'], 2) }}</td><td></td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </details>
                @endif

                <div class="sa-actions">
                    <div style="flex:1;">
                        <label style="font-size:11px; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:.04em;">Reconciliation notes</label>
                        <textarea class="eod-recon-notes form-control" rows="1"
                            placeholder="Notes for this shift (auto-saves)"
                            style="font-size:12px; resize:vertical;">{{ $recNotes }}</textarea>
                        <div class="eod-recon-notes-status" style="font-size:11px; color:#9ca3af; margin-top:2px; min-height:14px;"></div>
                    </div>
                </div>
            </div>
        @endforeach {{-- end inner per-shift cards loop --}}
        @endforeach {{-- end outer per-day grouping loop --}}
    @elseif(empty($rows))
        <div class="alert alert-info" style="margin-bottom:20px;">No shifts in this window. Pick a different day or open a register.</div>
    @endif

    {{-- Daily cash reconciliation — mirrors Sarah's xlsx workflow
         (one per-employee summary at top, then per-store side-by-side
         Clover/ERP raw payment lists with totals at the bottom).
         Re-enabled 2026-05-05 after the shift-audit cards above were
         judged unusable for the daily flow. --}}
    {{-- xlsx side-by-side raw payment lists hidden 2026-05-05 — Sarah
         finds them confusing now that the per-cashier cards above show
         the matched view. The "View {cashier}'s sales" link inside each
         card opens /pos/recent-feed for transaction-level drill-in. --}}
    @if(false && (!empty($xlsx_layout['employee_summary']) || !empty($xlsx_layout['by_day'])))
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
             biggest mismatches float to the top.

             Sarah 2026-05-05: hidden because the per-cashier cards above
             already show the same Clover↔ERP card-check per employee in
             plainer language. Kept in code so it's a one-line flip if
             she wants the dense summary back. --}}
        @if(false && !empty($xlsx_layout['employee_summary']))
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
                                <div class="rx-col-head">Clover</div>
                                <table class="rx-list">
                                    <thead>
                                        <tr>
                                            <th>Payment Date</th>
                                            <th class="num">Amount</th>
                                            <th>Employee Name</th>
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
                                <div class="rx-col-head">ERP</div>
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

    {{-- Old per-cashier side-by-side breakdown — retired 2026-04-23 in
         favor of the cashier shift audit at the top of the page. Gated
         behind @if(false) so legacy data lookups don't run. --}}
    @if(false)
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
    @endif {{-- /@if(false) wrapper for the retired breakdown block --}}

    {{-- Daily reconciliation rollup table hidden 2026-05-05 — its
         "ERP card $" / "Variance" columns rely on payment-method
         filtering, which is meaningless given the workflow where
         cashiers ring everything as cash. The per-cashier cards above
         are the source of truth now. --}}
    @if(false)
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
    @endif {{-- /retired Daily reconciliation rollup table --}}

    {{-- Why Unknown? drill-down — Sarah 2026-04-22: "why is employee
         unknown sometimes?". Lists the raw rows that bucketed as Unknown
         on either side, with the underlying cause so she can tell benign
         walk-in / online-checkout from actual data problems (deleted
         users, broken imports). Collapsed by default so the panel stays
         small unless she cares. --}}
    @if(false && !empty($unknown_rows) && (count($unknown_rows['erp'] ?? []) > 0 || count($unknown_rows['clover'] ?? []) > 0 || count($unknown_rows['clover_fields'] ?? []) > 0))
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
    // Force the picker to default to today/today, not the inherited
    // dateRangeSettings (which is "last 30 days" most places). Sarah
    // 2026-05-05: this report is a daily-cash flow, multi-day blurs it.
    var eodPickerOpts = $.extend({}, dateRangeSettings || {}, {
        startDate: moment(),
        endDate: moment(),
        singleDatePicker: false,
        autoApply: true
    });
    $('#eod_date_range').daterangepicker(eodPickerOpts, function (start, end) {
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

    // Sync-everything handler retired 2026-05-06 — Sarah found two
    // sync buttons confusing. Daily reconciliation only needs the
    // Clover-payments sync above. The full bidirectional sync still
    // exists at /business/clover/sync-now if it's needed elsewhere.


    // Per-store "Mark reconciled" checkbox — toggles the audit stamp on
    // the clover_reconciliations row for (day, location_id).
    $('body').on('change', '.eod-loc-card .eod-recon-checkbox', function () {
        var $card = $(this).closest('.eod-loc-card');
        var $lbl = $card.find('.eod-recon-label');
        var $stamp = $card.find('.eod-recon-stamp, .eod-recon-notes-status').first();
        var $toggle = $card.find('.eod-recon-toggle');
        $lbl.text('Saving…');
        $.ajax({
            url: '/reports/clover-eod/mark-reconciled',
            method: 'POST',
            dataType: 'json',
            data: {
                _token: $('meta[name="csrf-token"]').attr('content'),
                day: $card.data('day'),
                location_id: $card.data('location-id'),
                employee_key: $card.data('employee-key') || ''
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
                // Collapse the card to a single-line summary so signed-off
                // cashiers fall out of Sarah's field of view. Card stays
                // clickable to expand again.
                $card.addClass('cc-collapsed');
            } else {
                $lbl.text('Mark reconciled');
                $toggle.css('color', '#374151');
                $stamp.text('');
                $card.removeClass('cc-collapsed');
            }
        }).fail(function () {
            $lbl.text('Mark reconciled');
            alert('Network error — try again.');
        });
    });

    // Click a collapsed card (anywhere except the checkbox label which
    // stops-propagation) to re-expand it for review.
    $('body').on('click', '.eod-loc-card.cc-collapsed', function () {
        $(this).removeClass('cc-collapsed');
    });

    // Per-store notes — debounced autosave on input + immediate save on blur.
    (function () {
        var timers = new WeakMap();
        $('body').on('input', '.eod-loc-card .eod-recon-notes', function () {
            var el = this;
            var $status = $(el).siblings('.eod-recon-notes-status');
            $status.text('Typing…').css('color', '#9ca3af');
            if (timers.get(el)) clearTimeout(timers.get(el));
            timers.set(el, setTimeout(function () { saveNotes(el); }, 900));
        });
        $('body').on('blur', '.eod-loc-card .eod-recon-notes', function () {
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
                    employee_key: $card.data('employee-key') || '',
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
