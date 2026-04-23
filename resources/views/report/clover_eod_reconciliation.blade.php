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

    // Sync-everything handler — fires the umbrella clover:sync command
    // (items + orders + customers pull + ERP→Clover push). Reuses the
    // same output block as the payments sync above. Does not auto-reload
    // since this report is scoped to payments; user reloads if they want.
    $('#eod_sync_all_btn').on('click', function () {
        var $btn = $(this);
        var $status = $('#eod_sync_status');
        var $out = $('#eod_sync_output');
        var $pre = $('#eod_sync_output_pre');
        var original = $btn.html();
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Syncing everything…');
        $status.text('Pulling items/orders/customers + pushing dirty rows — 1–3 minutes…').css('color', '#6b7280');
        $out.hide();

        $.ajax({
            url: '/business/clover/sync-now',
            method: 'POST',
            dataType: 'json',
            timeout: 600000,
            data: { _token: $('meta[name="csrf-token"]').attr('content') }
        }).done(function (r) {
            $pre.text(r.output || '(no output)');
            $out.show();
            $status.text(r.success ? 'Full sync complete.' : 'Full sync exited with errors — see output.')
                   .css('color', r.success ? '#166534' : '#b91c1c');
        }).fail(function (xhr) {
            var out = '';
            try { out = (xhr.responseJSON && xhr.responseJSON.output) || xhr.responseText; } catch (e) {}
            $pre.text(out || ('HTTP ' + xhr.status + ' — ' + xhr.statusText));
            $out.show();
            $status.text('Full sync failed — see output below.').css('color', '#b91c1c');
        }).always(function () {
            $btn.prop('disabled', false).html(original);
        });
    });
});
</script>
@endsection
