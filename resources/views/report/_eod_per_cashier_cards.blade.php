{{-- Per-cashier daily reconciliation cards.

     Shared between /reports/clover-eod-reconciliation and
     /pos/recent-feed (Sarah 2026-05-13: moved the daily-cash flow onto
     the live feed so reconciliation happens in the same place cashiers
     are watched). Both views compute $employee_breakdown_by_day +
     $reconciliations the same way, then @include this partial.

     One card per (cashier, day, location). Three plain sections answer
     three questions:
       · WHAT THEY SOLD — cash + card, with the totals broken out
       · CARD CHECK — Clover settled vs ERP card sales for that
         cashier (the theft signal: a card swiped on Clover but
         never rung into the POS = pocketed)
       · CASH DRAWER — opening + cash sales − cash buys = expected,
         vs what the cashier counted at close (the wrong-change /
         skim signal)
     Each card has its own ✓ Reconciled toggle + notes so each cashier
     can be signed off independently.

     POST handlers for the checkbox / notes / recat buttons live on
     the existing /reports/clover-eod/* routes; their JS is duplicated
     into both views verbatim so the partial stays markup-only. --}}
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
                            $empKey = strtolower(trim($e['display_name']));
                            if ($empKey === 'unknown' || $empKey === 'unattributed') continue;

                            // Sarah 2026-05-13: per-cashier numbers are GROSS
                            // (final_total vs Clover amount) so they sum to
                            // the per-store banner above. NET-vs-NET caused
                            // confusing $13+ "diffs" on individual cashiers
                            // when the store as a whole reconciled to pennies
                            // — the gap was Clover's tax_cents column
                            // disagreeing with ERP's tax rather than a real
                            // keying/swipe issue.
                            $totalSold       = (float) ($e['total_sales'] ?? 0);
                            $cardClover      = (float) ($e['clover_total'] ?? 0);
                            $totalSoldNet    = (float) ($e['net_sales'] ?? 0);
                            $cardCloverNet   = (float) ($e['clover_net'] ?? 0);
                            $impliedCash = max(0.0, round($totalSold - $cardClover, 2));
                            $overSwipe   = round($cardClover - $totalSold, 2);

                            $opening     = $e['opening_cash'];
                            $cashBuys    = (float) ($e['cash_buys'] ?? 0);
                            $expected    = $e['expected_ending_cash'];
                            $reported    = $e['reported_ending_cash'];
                            $cashVar     = $e['cash_variance'];
                            $hasShift    = !empty($e['has_shift']);
                            $shiftStatus = $e['shift_status'] ?? null;

                            if ($overSwipe < 1) { $swipeCls = 'ok'; $swipeLabel = 'OK'; }
                            elseif ($overSwipe < 10) { $swipeCls = 'warn'; $swipeLabel = 'Over swipe'; }
                            else { $swipeCls = 'bad'; $swipeLabel = 'Over swipe'; }

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

                            @php
                                $missingClover = $totalSold > 0 && $cardClover == 0;
                            @endphp
                            <div class="cc-section">
                                <div class="cc-sec-h">What they sold @if($txnCount)<span style="font-weight:500; color:#9ca3af;">· {{ $txnCount }} sale{{ $txnCount === 1 ? '' : 's' }}</span>@endif</div>
                                <div class="cc-line sum"><span class="cc-label" title="ERP Sales (gross) = sum of final_total — what customers actually paid for this cashier's sales. Matches per-store and Day Totals.">ERP Sales</span><span class="cc-val">${{ number_format($totalSold, 2) }}</span></div>
                                <div class="cc-line"><span class="cc-label minor" title="Clover swipes attributed to this cashier (gross, customer-paid). Matches the per-store banner.">Clover Sales (this cashier)</span><span class="cc-val">${{ number_format($cardClover, 2) }}</span></div>
                                <div class="cc-line"><span class="cc-label minor" style="color:{{ abs($overSwipe) < 0.01 ? '#2E6F40' : '#8B2C2C' }};">Diff (Clover − ERP)</span><span class="cc-val" style="color:{{ abs($overSwipe) < 0.01 ? '#2E6F40' : '#8B2C2C' }}; font-weight:700;">{{ abs($overSwipe) < 0.01 ? '$0.00 ✓' : (($overSwipe > 0 ? '+' : '') . '$' . number_format($overSwipe, 2)) }}</span></div>
                                <div class="cc-line"><span class="cc-label minor" style="color:#8A7C6A; font-size:11px;" title="Net (pre-tax) — same sales without tax + fees. Useful for cross-checking Clover's Net Sales dashboard.">Net (pre-tax, both sides)</span><span class="cc-val" style="color:#8A7C6A; font-size:11px;">${{ number_format($totalSoldNet, 2) }} / ${{ number_format($cardCloverNet, 2) }}</span></div>
                                @if($missingClover)
                                    <div class="cc-line"><span class="cc-label" style="color:#92400e;">⚠ No Clover swipes matched — sync may be stale</span><span class="cc-val"></span></div>
                                @endif
                                @if($overSwipe >= 1)
                                    <div class="cc-line"><span class="cc-label" style="color:#b91c1c;">⚠ Clover collected more than rung</span><span class="cc-val bad">+${{ number_format($overSwipe, 2) }}</span></div>
                                @endif
                            </div>

                            @php
                                $cashRefunds      = (float) ($e['cash_refunds']       ?? 0);
                                $cashExpenses     = (float) ($e['cash_expenses']      ?? 0);
                                $cashTransfersOut = (float) ($e['cash_transfers_out'] ?? 0);
                                $cashTransfersIn  = (float) ($e['cash_transfers_in']  ?? 0);
                                $cashOtherNet     = (float) ($e['cash_other_net']     ?? 0);
                                $noDrawerCounts   = is_null($opening) && is_null($expected) && is_null($reported) && is_null($cashVar);
                                $cashRung = (float) ($e['cash_rung'] ?? 0);
                            @endphp
                            <div class="cc-section">
                                <div class="cc-sec-h">Cash drawer <span class="cc-flag {{ $cashCls }}">{{ $cashLabel }}</span></div>
                                @if(!$noDrawerCounts)
                                    <div class="cc-line"><span class="cc-label minor">Opening cash</span><span class="cc-val {{ is_null($opening) ? 'muted' : '' }}">{{ is_null($opening) ? '—' : '$' . number_format($opening, 2) }}</span></div>
                                @endif
                                <div class="cc-line"><span class="cc-label minor" title="Sum of transaction_payments where method='cash' for this cashier's day. Authoritative source for cash collected — not the ERP–Clover gap.">+ Cash sales (rung as cash)</span><span class="cc-val">${{ number_format($cashRung, 2) }}</span></div>
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
                                @if($noDrawerCounts)
                                    <div class="cc-line" style="margin-top:6px; color:#9ca3af; font-size:12px;">
                                        <span class="cc-label minor">Register not opened/closed — no drawer to reconcile. Leave a note below if expected.</span>
                                    </div>
                                @else
                                    <div class="cc-line sum"><span class="cc-label">Expected in drawer</span><span class="cc-val {{ is_null($expected) ? 'muted' : '' }}">{{ is_null($expected) ? '—' : '$' . number_format($expected, 2) }}</span></div>
                                    <div class="cc-line"><span class="cc-label">Counted at close</span><span class="cc-val {{ is_null($reported) ? 'muted' : '' }}">{{ is_null($reported) ? '—' : '$' . number_format($reported, 2) }}</span></div>
                                    <div class="cc-line sum"><span class="cc-label">Variance</span><span class="cc-val {{ $cashCls }}">{{ is_null($cashVar) ? '—' : (($cashVar >= 0 ? '+' : '') . '$' . number_format($cashVar, 2)) }}</span></div>
                                @endif
                            </div>

                            @php
                                $details = $e['details'] ?? ['clover_unmatched'=>[], 'erp_unmatched'=>[], 'amount_mismatch'=>[], 'buys'=>[], 'other_channels'=>[]];
                                $cuCount = count($details['clover_unmatched'] ?? []);
                                $euCount = count($details['erp_unmatched'] ?? []);
                                $amCount = count($details['amount_mismatch'] ?? []);
                                $bCount  = count($details['buys'] ?? []);
                                $ocCount = count($details['other_channels'] ?? []);
                                $detailsTotal = $cuCount + $euCount + $amCount + $bCount + $ocCount;
                                $tFmt = function ($t) {
                                    if (!$t) return '—';
                                    try { return \Carbon\Carbon::parse($t)->setTimezone(config('app.timezone'))->format('g:i a'); }
                                    catch (\Exception $ex) { return '—'; }
                                };
                                $isBagFee = function ($diffDollars) {
                                    $cents = (int) round(abs((float) $diffDollars) * 100);
                                    return $cents > 0 && $cents <= 144 && $cents % 12 === 0;
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
                                        @if($ocCount > 0)<span style="color:#1d4ed8; font-weight:500; text-transform:none; margin-left:6px;">· {{ $ocCount }} other-channel</span>@endif
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
                                                @php $bagHint = $isBagFee($row->diff); @endphp
                                                <div style="display:flex; justify-content:space-between; padding:2px 0; border-bottom:1px dotted #f3f4f6;">
                                                    <span style="color:#6b7280;">
                                                        {{ $tFmt($row->ts) }}
                                                        @if($bagHint)<span style="color:#1d4ed8; font-size:10px; font-weight:700; margin-left:4px;" title="Diff is an exact multiple of $0.12 — likely bag fee not added on Clover">· likely bag fee</span>@endif
                                                    </span>
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
                                        @if(($ocCount ?? 0) > 0)
                                            <div style="margin-top:8px;"><span style="font-size:10px; font-weight:700; color:#1d4ed8; text-transform:uppercase;">Other channels</span></div>
                                            @php $ocSum = 0; @endphp
                                            @foreach($details['other_channels'] as $row)
                                                @php $ocSum += (float) $row->amount; @endphp
                                                <div style="display:flex; justify-content:space-between; padding:2px 0; border-bottom:1px dotted #f3f4f6;" class="cc-recat-row" data-txn-id="{{ $row->transaction_id }}">
                                                    <span style="color:#6b7280;">
                                                        {{ $tFmt($row->ts) }} ·
                                                        <span style="text-transform:uppercase; font-size:10px; font-weight:700; color:#1d4ed8; letter-spacing:.04em;">{{ $row->channel }}</span>
                                                        <button type="button" class="cc-recat-btn" title="Mis-tagged? Click to flip this back to in-store"
                                                            style="margin-left:6px; background:transparent; border:1px solid #93c5fd; color:#1d4ed8; font-size:10px; font-weight:700; padding:1px 6px; border-radius:999px; cursor:pointer;">→ in-store</button>
                                                    </span>
                                                    <span><a href="{{ route('sell.printInvoice', $row->transaction_id) }}" target="_blank" style="color:#1f2937; font-weight:600; text-decoration:none;">${{ number_format($row->amount, 2) }}</a></span>
                                                </div>
                                            @endforeach
                                            <div style="display:flex; justify-content:space-between; padding:3px 0; font-weight:700;"><span>Subtotal</span><span>${{ number_format($ocSum, 2) }}</span></div>
                                        @endif
                                    </div>
                                </details>
                            @endif

                            <div class="cc-foot">
                                @php
                                    $viewQs = ['location_id' => $loc['location_id'] ?: '', 'date' => $dayBlock['day'], 'limit' => 200, 'hide_orphans' => 1];
                                    if (!empty($e['user_id'])) $viewQs['created_by'] = (int) $e['user_id'];
                                @endphp
                                <a href="/pos/recent-feed?{{ http_build_query($viewQs) }}" target="_blank"
                                   style="font-size:11px; color:#4f46e5; text-decoration:none; font-weight:600;">
                                    View {{ $e['display_name'] }}'s sales →
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
        <strong>ERP Sales</strong> is the gross total (final_total) of every sale this cashier rang up. <strong>Clover Sales (this cashier)</strong> = gross Clover swipes attributed to them. Both match the per-store banner above. <strong>Over swipe</strong> means Clover collected more than the cashier rang up — the theft tell.
        <strong>Cash drawer</strong>: opening + cash collected − cash buys should equal what the cashier counted at close. <em>Short</em> = drawer low (skim or wrong change), <em>Over</em> = drawer high (mis-rung sale).
    </p>
@endif
