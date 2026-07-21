@extends('layouts.app')
@section('title', 'Buy from Customer Form')

@php
    $is_embed = request()->get('embed') == '1';
    $idTypes = [
        '' => '—',
        'drivers_license' => "Driver's license",
        'passport' => 'Passport',
        'state_id' => 'State ID',
        'military_id' => 'Military ID',
        'other' => 'Other',
    ];
    $paymentMethods = [
        'cash_in_store' => 'Cash (in store)',
        'store_credit' => 'Store credit',
        'zelle_venmo' => 'Zelle / Venmo (Jon)',
    ];
@endphp

@section('css')
    <style>
        /* Buy-from-customer create — Sarah 2026-04-28: tighter, easier to read.
           Scoped to .bfc-create so nothing else on the site is affected. */
        .bfc-create { max-width: 1200px; margin: 0 auto; }
        .bfc-create .box { border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .bfc-create .box-header { padding: 10px 14px; }
        .bfc-create .box-header .box-title { font-size: 14px; font-weight: 700; letter-spacing: 0.2px; }
        .bfc-create .box-body { padding: 14px; }
        .bfc-create .form-group { margin-bottom: 10px; }
        .bfc-create label { font-size: 12px; font-weight: 600; color: #555; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.3px; }
        .bfc-create label .text-muted { text-transform: none; font-weight: 400; letter-spacing: 0; }
        .bfc-create .form-control { height: 34px; padding: 6px 10px; font-size: 13px; border-radius: 6px; }
        .bfc-create textarea.form-control { height: auto; min-height: 60px; }
        .bfc-create .select2-container .select2-selection--single { height: 34px !important; }
        .bfc-create .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 32px !important; font-size: 13px; }
        .bfc-create .select2-container--default .select2-selection--single .select2-selection__arrow { height: 32px !important; }
        .bfc-create hr { margin: 16px 0 12px; border-top-color: #eee; }
        .bfc-create h4 { font-size: 13px; font-weight: 700; color: #333; text-transform: uppercase; letter-spacing: 0.4px; margin: 0 0 10px; }
        .bfc-create h4 small { text-transform: none; letter-spacing: 0; font-weight: 400; }
        .bfc-create #offer_lines_table { font-size: 13px; }
        .bfc-create #offer_lines_table th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; color: #666; background: #f7f7f7; padding: 8px 10px; border-bottom: 1px solid #ddd; }
        .bfc-create #offer_lines_table td { padding: 6px; vertical-align: middle; }
        .bfc-create #offer_lines_table .form-control { height: 32px; font-size: 12px; padding: 4px 8px; }
        .bfc-create #offer_lines_table td:first-child { width: 220px; }
        .bfc-create #offer_lines_table td:nth-child(4) { width: 110px; }
        .bfc-create #offer_lines_table td:nth-child(5),
        .bfc-create #offer_lines_table td:nth-child(6),
        .bfc-create #offer_lines_table td:nth-child(7) { width: 110px; }
        .bfc-create #offer_lines_table td:last-child { width: 40px; text-align: center; }
        /* Individual-Discogs line missing its median price — the grade multiplier
           has nothing to scale, so flag the cell before Calculate. */
        .bfc-create #offer_lines_table input.bfc-median-missing { background: #fff3cd; border-color: #d9534f; box-shadow: 0 0 0 2px rgba(217,83,79,0.18); }
        /* Cells that don't apply to the selected item type (e.g. median on a
           bulk line, grade on a no-grading type) are disabled + grayed. */
        .bfc-create #offer_lines_table .bfc-cell-off { background: #f0f0f0; color: #bbb; cursor: not-allowed; text-align: center; }
        /* Live per-line value (read-out, computed client-side to mirror the server). */
        .bfc-create #offer_lines_table td.bfc-line-cell { text-align: right; white-space: nowrap; }
        .bfc-create .bfc-line-value { font-weight: 600; font-variant-numeric: tabular-nums; color: #333; }
        .bfc-create .bfc-line-formula { display: block; font-size: 11px; color: #aaa; font-weight: 400; font-variant-numeric: tabular-nums; }
        /* Running totals bar above Calculate — offer builds live as items are typed. */
        .bfc-create .bfc-running { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin: 12px 0 8px; padding: 10px 14px; background: #fafafa; border: 1px solid #eee; border-radius: 8px; }
        .bfc-create .bfc-running-note { font-size: 11px; color: #999; }
        .bfc-create .bfc-running-figs { display: flex; gap: 22px; font-size: 12px; color: #666; }
        .bfc-create .bfc-running-figs strong { font-size: 18px; color: #333; margin-left: 6px; font-variant-numeric: tabular-nums; }
        .bfc-create .bfc-running-figs strong#bfc_running_final { color: #2c699a; }
        .bfc-create .negotiation-row { display: grid; grid-template-columns: repeat(4, minmax(0, 180px)) 1fr; gap: 12px; align-items: end; }
        .bfc-create .negotiation-row .form-control { max-width: 180px; }
        .bfc-create .meta-row { background: #fafafa; border: 1px solid #eee; border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; font-size: 12px; }
        .bfc-create .meta-row strong { color: #333; }
        .bfc-create details.bfc-advanced { margin: 8px 0 0; font-size: 12px; }
        .bfc-create details.bfc-advanced summary { cursor: pointer; color: #888; padding: 4px 0; }
        .bfc-create details.bfc-advanced[open] summary { color: #555; margin-bottom: 6px; }
        .bfc-create .pos-action-row { display: flex; justify-content: flex-end; gap: 8px; margin-top: 14px; }
        .bfc-create .well { background: #fafafa; border: 1px solid #eee; border-radius: 8px; padding: 14px; }
        /* Readonly offer-amount displays — look like read-outs, not inputs. */
        .bfc-create .bfc-offer-display { background: #f5f5f5; border-color: #e6e6e6; color: #333; font-weight: 600; cursor: default; }
        .bfc-create .bfc-offer-display:focus { outline: none; box-shadow: none; }
        /* Editable final-offer inputs — visually distinct so cashier knows they can adjust. */
        .bfc-create .bfc-final-edit { background: #fffdf0; border-color: #e0c46c; color: #333; font-weight: 600; }
        .bfc-create .bfc-final-edit.bfc-final-overridden { background: #fff3cd; border-color: #d4a017; }
        /* "Calculator: $X.XX" hint under each editable final input. */
        .bfc-create .bfc-calc-hint { display: block; margin-top: 3px; font-size: 11px; color: #888; }
        .bfc-create .bfc-delta { font-weight: 600; margin-left: 2px; }
        .bfc-create .bfc-delta.bfc-delta-up { color: #27ae60; }
        .bfc-create .bfc-delta.bfc-delta-down { color: #c0392b; }
        /* Three-row offer table: Starting / 2nd / Final × Cash / Credit. */
        .bfc-create .bfc-offer-table { max-width: 560px; margin-bottom: 12px; }
        .bfc-create .bfc-offer-table th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; color: #666; background: #f7f7f7; padding: 8px 10px; border-bottom: 1px solid #ddd; }
        .bfc-create .bfc-offer-table td { padding: 6px; vertical-align: middle; }
        .bfc-create .bfc-offer-table .bfc-offer-rowlabel { width: 200px; font-weight: 600; color: #333; text-transform: none; letter-spacing: 0; background: #fafafa; }
        /* Final row is the one that actually gets recorded — make it read as the destination of the ladder. */
        .bfc-create .bfc-offer-table tr.bfc-final-row .bfc-offer-rowlabel { background: #fffdf0; border-left: 3px solid #d4a017; }
        .bfc-create .bfc-offer-table tr.bfc-final-row td { background: #fffef8; }
        /* Make per-row remove "X" subtle — just a muted glyph, no big red block. */
        .bfc-create #offer_lines_table .remove-line {
            background: transparent;
            border: 0;
            color: #c8c0b8;
            padding: 4px 6px;
            line-height: 1;
            box-shadow: none;
            opacity: 0.7;
            transition: color 0.15s ease, opacity 0.15s ease;
        }
        .bfc-create #offer_lines_table .remove-line:hover,
        .bfc-create #offer_lines_table .remove-line:focus {
            background: transparent;
            color: #c0392b;
            opacity: 1;
            outline: none;
        }
        .bfc-create #offer_lines_table .remove-line .fa { font-size: 11px; }
        /* Compliance + signature — visually obvious so the cashier doesn't skip them. */
        .bfc-create .bfc-compliance-row { padding: 6px 10px; margin-bottom: 4px; border-left: 3px solid #d9534f; background: #fff7f6; border-radius: 3px; }
        .bfc-create .bfc-compliance-row label { font-size: 13px; color: #333; text-transform: none; letter-spacing: 0; font-weight: 500; margin-bottom: 0; cursor: pointer; }
        .bfc-create .bfc-compliance-row .bfc-compliance-cb { margin-right: 6px; transform: scale(1.1); }
        .bfc-create #buy_signature_box { border: 2px dashed #c0392b !important; }
        /* Sarah 2026-07-19: items + quote are locked until the seller is
           identified. Banner explains why; disabled inputs grey out on their own. */
        .bfc-create .bfc-items-gate-hint { padding: 10px 14px; margin-bottom: 12px; background: #fff7f6; border: 1px solid #f1c2c2; border-left: 4px solid #c0392b; border-radius: 4px; font-size: 13px; color: #842029; }
        .bfc-create .bfc-items-gate-hint .fa { margin-right: 6px; }
        /* Used buying-budget bars by store — mirrors the ICA banner figures so
           the cashier knows how much of this week's per-store Used (35% cap)
           budget is left before quoting. Buys are used inventory, so only the
           Used cap shows here (New lives on the inventory purchase tool).
           Hollywood 75% / Pico 25% of the weekly total. Data from
           InventoryCheckService::currentPurchaseBudget. */
        .bfc-create .bfc-used-budget { background: #fafafa; border: 1px solid #eee; border-radius: 8px; padding: 10px 14px; margin-bottom: 12px; }
        .bfc-create .bfc-used-budget-head { display: flex; justify-content: space-between; align-items: baseline; flex-wrap: wrap; gap: 6px; margin-bottom: 6px; }
        .bfc-create .bfc-used-budget-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; color: #333; }
        .bfc-create .bfc-used-budget-title small { text-transform: none; letter-spacing: 0; font-weight: 400; }
        .bfc-create .bfc-used-budget-figures { font-size: 12px; color: #555; }
        .bfc-create .bfc-used-budget-figures strong { color: #333; }
        /* Mirror the inventory-check-assistant budget bars (Sarah 2026-06-19). */
        .bfc-create .ica-bar-row { display: grid; grid-template-columns: 200px 1fr 180px; gap: 18px; align-items: center; margin-top: 14px; padding: 10px 12px; border-radius: 6px; background: #fafafa; border: 1px solid #ececec; }
        .bfc-create .ica-bar-row:first-of-type { margin-top: 6px; }
        .bfc-create .ica-bar-used { background: #f5faf6; border-color: #d6ead9; }
        .bfc-create .ica-bar-left { min-width: 0; }
        .bfc-create .ica-bar-kind { font-size: 18px; font-weight: 800; letter-spacing: 1.2px; color: #2e7d32; line-height: 1.1; }
        .bfc-create .ica-bar-caption { font-size: 11px; color: #888; margin-top: 2px; line-height: 1.3; }
        .bfc-create .ica-bar-track-wrap { min-width: 0; }
        .bfc-create .ica-bar-track { position: relative; height: 28px; border-radius: 4px; background: #e8e8e8; overflow: hidden; box-shadow: inset 0 1px 2px rgba(0,0,0,0.04); }
        .bfc-create .ica-bar-fill { position: absolute; top: 0; left: 0; bottom: 0; transition: width 0.3s ease; background-color: #5cb85c; }
        .bfc-create .ica-bar-fill.progress-bar-success { background-color: #5cb85c; }
        .bfc-create .ica-bar-fill.progress-bar-warning { background-color: #f0ad4e; }
        .bfc-create .ica-bar-fill.progress-bar-danger  { background-color: #d9534f; }
        /* Slice of the Used cap New's overspend consumed — sits after the spent fill. */
        .bfc-create .ica-bar-eaten { position: absolute; top: 0; bottom: 0; z-index: 1; background-image: repeating-linear-gradient(45deg, #cfcfcf, #cfcfcf 5px, #c2c2c2 5px, #c2c2c2 10px); }
        .bfc-create .ica-bar-track-label { position: relative; z-index: 2; line-height: 28px; padding: 0 12px; font-size: 14px; font-weight: 600; color: #222; text-shadow: 0 1px 0 rgba(255,255,255,0.6); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .bfc-create .ica-bar-track-of { color: #888; font-weight: 400; font-size: 12px; }
        .bfc-create .ica-bar-right { text-align: right; }
        .bfc-create .ica-bar-pct { font-size: 22px; font-weight: 800; color: #2e7d32; line-height: 1; }
        .bfc-create .ica-bar-remaining-wrap { font-size: 12px; color: #666; margin-top: 4px; }
        .bfc-create .ica-bar-remaining { color: #2c699a; font-weight: 600; }
        .bfc-create .ica-bar-remaining-over { color: #a94442; font-weight: 700; }
        @media (max-width: 900px) {
            .bfc-create .ica-bar-row { grid-template-columns: 1fr; gap: 8px; }
            .bfc-create .ica-bar-right { text-align: left; display: flex; align-items: baseline; gap: 12px; }
            .bfc-create .ica-bar-remaining-wrap { margin-top: 0; }
        }
        .bfc-create .bfc-used-budget-warn { margin-top: 10px; padding: 10px 12px; font-size: 13px; font-weight: 400; line-height: 1.45; color: #842029; background: #fff5f5; border: 1px solid #f1c2c2; border-left: 4px solid #c0392b; border-radius: 4px; }
        .bfc-create .bfc-used-budget-warn strong { color: #842029; }
        .bfc-create .bfc-used-budget-warn + .bfc-used-budget-warn { margin-top: 6px; }
    </style>
    @if($is_embed)
        {{-- When opened inside the POS modal iframe, hide the admin chrome so only the calculator shows. --}}
        <style>
            body, body.skin-blue, body.hold-transition { background: #fff !important; padding-top: 0 !important; }
            .main-header, .main-sidebar, .main-footer, .content-header > h1 > small, .left-side { display: none !important; }
            .content-wrapper { margin-left: 0 !important; min-height: auto !important; padding-top: 0 !important; }
            .content-header { padding: 10px 15px 0 !important; }
            section.content { padding: 10px 15px !important; }
            .wrapper { min-height: auto !important; }
        </style>
    @endif
@stop

@section('content')
<section class="content-header">
    <h1>Buy from Customer Form</h1>
</section>

<section class="content bfc-create">
    @if(session('status'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">
            {{ session('status.msg') }}
            @if(($saved_offer_id ?? session('saved_offer_id')))
                <br><strong>Buy record:</strong> BFC-{{ str_pad((string) ($saved_offer_id ?? session('saved_offer_id')), 6, '0', STR_PAD_LEFT) }}
            @endif
        </div>
    @endif

    {{-- Sarah 2026-05-06: surface validation failures. Without this the Accept
         button silently rejects (e.g. compliance boxes unchecked, signature
         missing) and the offer stays at its prior auto-saved Draft, which
         looks like the form just did nothing. --}}
    @if ($errors->any())
        <div class="alert alert-danger">
            <strong>The form couldn't be submitted:</strong>
            <ul style="margin-top:6px; margin-bottom:0;">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $input = $input_data ?? old();
        $input = is_array($input) ? $input : [];
        $calc = $calculation ?? null;
        $pmVal = $input['payment_method'] ?? ($input['payout_type'] ?? 'cash');
        if ($pmVal === 'cash') {
            $pmVal = 'cash_in_store';
        }
        // Sarah 2026-05-06: starting / 2nd / final offers are no longer typed by the
        // cashier — they're whatever the calculator returned (50% / 75% / 95% of the
        // calculated total). Mirror $calc back into $input so the Save / Accept /
        // Reject foreach loops below still emit them as hidden inputs (and the
        // override-reason validation in BuyFromCustomerController still sees a
        // matching final amount).
        if ($calc) {
            foreach (['starting_offer_cash', 'starting_offer_credit', 'second_offer_cash', 'second_offer_credit', 'final_offer_cash', 'final_offer_credit'] as $offerKey) {
                $input[$offerKey] = data_get($calc, $offerKey);
            }
        }
    @endphp

    <div class="row">
        <div class="col-md-12">
            <div class="meta-row">
                <div class="row">
                    <div class="col-sm-4"><strong>Date &amp; time:</strong> {{ @format_datetime(\Carbon\Carbon::now()) }}</div>
                    <div class="col-sm-4"><strong>Employee:</strong> {{ auth()->user()->user_full_name ?? auth()->user()->username ?? '—' }}</div>
                    <div class="col-sm-4"><strong>Buy record #:</strong> @if(($saved_offer_id ?? session('saved_offer_id'))) BFC-{{ str_pad((string) ($saved_offer_id ?? session('saved_offer_id')), 6, '0', STR_PAD_LEFT) }} @else <span class="text-muted">assigned on save</span> @endif</div>
                </div>
            </div>

            {{-- Used buying budget by store — same figures as the inventory
                 purchase tool. Buys are used inventory, so only the per-store
                 Used cap (35% of each store's weekly share) shows here; the
                 New split lives on the inventory purchase tool. --}}
            @php $perStore = ($purchaseBudget ?? null)['per_store'] ?? null; @endphp
            @if(!empty($perStore))
                @php
                    $bfcBar = function ($label, $caption, $bucket, $capFull = null, $eaten = null) {
                        if ($bucket['over_budget']) { $band = 'progress-bar-danger'; $accent = '#a94442'; }
                        elseif ($bucket['pct_spent'] >= 80) { $band = 'progress-bar-warning'; $accent = '#8a6d3b'; }
                        else { $band = 'progress-bar-success'; $accent = '#2c699a'; }
                        $spent = number_format($bucket['spent'], 0);
                        $budget = number_format($bucket['budget'], 0);
                        $pct = $bucket['pct_spent'];
                        $remaining = $bucket['remaining'];
                        $remainLine = $bucket['over_budget']
                            ? '<span class="ica-bar-remaining-over">over by $' . number_format(abs($remaining), 0) . '</span>'
                            : '<span class="ica-bar-remaining">$' . number_format($remaining, 0) . ' left</span>';
                        // Slice of the cap that New's overspend ate, drawn gray right
                        // after the spent fill so the bar reads: spent | eaten | left.
                        $grayEl = '';
                        $eaten = (float) ($eaten ?? 0);
                        $capFull = (float) ($capFull ?? $bucket['budget']);
                        if ($eaten > 0 && $capFull > 0) {
                            $grayPct = min(100, ($eaten / $capFull) * 100);
                            $grayTitle = 'Held by New overspend: $' . number_format($eaten, 0);
                            $grayEl = '<div class="ica-bar-eaten" style="left: ' . $pct . '%; width: ' . $grayPct . '%;" title="' . $grayTitle . '"></div>';
                        }
                        return <<<HTML
                    <div class="ica-bar-row ica-bar-used">
                        <div class="ica-bar-left">
                            <div class="ica-bar-kind">{$label}</div>
                            <div class="ica-bar-caption">{$caption}</div>
                        </div>
                        <div class="ica-bar-track-wrap">
                            <div class="ica-bar-track">
                                <div class="ica-bar-fill {$band}" style="width: {$pct}%;"></div>
                                {$grayEl}
                                <div class="ica-bar-track-label">\${$spent} <span class="ica-bar-track-of">of</span> \${$budget}</div>
                            </div>
                        </div>
                        <div class="ica-bar-right">
                            <div class="ica-bar-pct" style="color: {$accent};">{$pct}%</div>
                            <div class="ica-bar-remaining-wrap">{$remainLine}</div>
                        </div>
                    </div>
HTML;
                    };
                @endphp
                <div class="bfc-used-budget">
                    <div class="bfc-used-budget-head">
                        <span class="bfc-used-budget-title">Used buying budget by store — week {{ $purchaseBudget['week_no'] }} of 13 <small class="text-muted">({{ \Carbon\Carbon::parse($purchaseBudget['start'])->format('M j') }} – {{ \Carbon\Carbon::parse($purchaseBudget['end'])->format('M j') }}) · 35% cap</small></span>
                        <span class="bfc-used-budget-figures">Weekly total <strong>${{ number_format($purchaseBudget['budget'], 0) }}</strong></span>
                    </div>
                    @foreach($perStore as $st)
                        @php
                            $bfcCaption = rtrim(rtrim(number_format($st['pct_of_total'] * 100, 1), '0'), '.') . '% of week · 35% cap';
                            if (!empty($st['used_eaten'])) {
                                $bfcCaption .= ' · $' . number_format($st['used_eaten'], 0) . ' held by New overspend';
                            }
                        @endphp
                        {!! $bfcBar($st['label'], $bfcCaption, $st['used'], $st['used_cap_full'] ?? null, $st['used_eaten'] ?? null) !!}
                    @endforeach
                    @php
                        // Stores where New's overspend ate the whole shared pot, so
                        // there's no used budget left to buy collections against.
                        $blocked = collect($perStore)->filter(function ($s) {
                            return !empty($s['used_eaten']) && ($s['used']['remaining'] ?? 0) <= 0;
                        });
                    @endphp
                    @foreach($blocked as $s)
                        @php $newOver = abs($s['new']['remaining'] ?? 0); @endphp
                        <div class="bfc-used-budget-warn">
                            <strong>{{ $s['label'] }}: no used buying budget left this week.</strong>
                            New purchases ran ${{ number_format($newOver, 0) }} over and used up {{ $s['label'] }}'s whole weekly pot — so there's nothing left to buy collections against. Hold off on buying more used here, or get Jon's OK first.
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="box box-solid">
                <div class="box-header with-border">
                    <h3 class="box-title">Seller + Offer Setup</h3>
                    <div class="box-tools">
                        <a class="btn btn-default btn-sm" href="{{ route('buy-from-customer.history') }}">
                            <i class="fa fa-history"></i> History
                        </a>
                    </div>
                </div>
                <div class="box-body">
                    <form id="buy_offer_form" method="POST" action="{{ route('buy-from-customer.calculate') }}">
                        @csrf
                        {{-- offer_id is set after the first auto-saved Calculate so subsequent
                             Calculates UPDATE that draft instead of creating a new BFC each click. --}}
                        <input type="hidden" name="offer_id" id="bfc_offer_id" value="{{ $saved_offer_id ?? session('saved_offer_id') ?? '' }}">
                    <div class="row">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Store location</label>
                                {!! Form::select('location_id', $locations, $input['location_id'] ?? null, ['class' => 'form-control select2', 'style' => 'width:100%;']) !!}
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>New or returning seller?</label>
                                {!! Form::select('seller_mode', ['contact' => 'Returning — has an account', 'phone' => 'New / walk-in'], $input['seller_mode'] ?? 'phone', ['class' => 'form-control', 'id' => 'seller_mode']) !!}
                            </div>
                        </div>
                        <div class="col-md-3 seller-contact-block">
                            <div class="form-group">
                                <label>Existing contact <span class="text-danger">*</span></label>
                                {!! Form::select('contact_id', $contacts, $input['contact_id'] ?? null, ['class' => 'form-control select2', 'style' => 'width:100%;']) !!}
                                <button type="button" class="btn btn-link btn-xs" id="bfc_view_account_btn" style="padding-left:0;">
                                    <i class="fa fa-user"></i> View account (store credit &amp; history)
                                </button>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>How do they want to be paid?</label>
                                {!! Form::select('payment_method', $paymentMethods, $pmVal, ['class' => 'form-control', 'id' => 'payment_method']) !!}
                            </div>
                        </div>
                    </div>

                    <div class="row seller-phone-block">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Seller first name <span class="text-danger">*</span></label>
                                {!! Form::text('seller_first_name', $input['seller_first_name'] ?? null, ['class' => 'form-control']) !!}
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Seller last name</label>
                                {!! Form::text('seller_last_name', $input['seller_last_name'] ?? null, ['class' => 'form-control']) !!}
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Phone <span class="text-danger">*</span></label>
                                {!! Form::text('seller_phone', $input['seller_phone'] ?? null, ['class' => 'form-control', 'placeholder' => 'Phone number']) !!}
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Email</label>
                                {!! Form::email('seller_email', $input['seller_email'] ?? null, ['class' => 'form-control']) !!}
                            </div>
                        </div>
                    </div>
                    <div class="seller-phone-block">
                        <details class="bfc-advanced">
                            <summary>+ legacy single-name field</summary>
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label>Legacy single name <span class="text-muted">(only if you can't split into first / last)</span></label>
                                        {!! Form::text('seller_name', $input['seller_name'] ?? null, ['class' => 'form-control']) !!}
                                    </div>
                                </div>
                            </div>
                        </details>
                    </div>
                    {{-- ID capture is hidden behind "more" — only fill if you suspect the seller may
                         be sketchy. Auto-opens if either field already has a value (e.g. on re-render
                         after Calculate) so the cashier doesn't lose what they typed. --}}
                    <details class="bfc-advanced bfc-id-block" @if(!empty($input['seller_id_type']) || !empty($input['seller_id_last_four'])) open @endif>
                        <summary>more</summary>
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>ID type <span class="text-muted">(optional)</span></label>
                                    {!! Form::select('seller_id_type', $idTypes, $input['seller_id_type'] ?? null, ['class' => 'form-control']) !!}
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label>Last 4 of ID # <span class="text-muted">(optional)</span></label>
                                    {!! Form::text('seller_id_last_four', $input['seller_id_last_four'] ?? null, ['class' => 'form-control', 'maxlength' => 4, 'pattern' => '[0-9]*', 'inputmode' => 'numeric', 'placeholder' => '1234']) !!}
                                </div>
                            </div>
                        </div>
                    </details>

                    <hr>
                    <h4>Items brought in</h4>
                    <div id="bfc_items_gate_hint" class="bfc-items-gate-hint" style="display:none;">
                        <i class="fa fa-lock"></i> Enter the seller first — pick an existing account, or fill in the walk-in seller's first name and phone above. Items unlock once the seller is identified.
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered" id="offer_lines_table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Title/Notes</th>
                                    <th>Genre</th>
                                    <th>Grade</th>
                                    <th>Qty</th>
                                    <th>Discogs median / value</th>
                                    <th>Line value</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    // Sarah 2026-05-06: render 7 blank rows on first load so the cashier can
                                    // type a typical haul without having to click "Add line" each time.
                                    $defaultRow = ['item_type' => 'individual_vinyl', 'quantity' => 1, 'condition_grade' => 'VG+', 'standard_multiplier' => 0.10];
                                    $lines = $input['lines'] ?? array_fill(0, 7, $defaultRow);
                                @endphp
                                @foreach($lines as $i => $line)
                                    <tr>
                                        <td>{!! Form::select("lines[$i][item_type]", $itemTypes, $line['item_type'] ?? null, ['class' => 'form-control']) !!}</td>
                                        <td>{!! Form::text("lines[$i][title]", $line['title'] ?? null, ['class' => 'form-control']) !!}</td>
                                        <td>{!! Form::text("lines[$i][genre]", $line['genre'] ?? null, ['class' => 'form-control']) !!}</td>
                                        <td>{!! Form::select("lines[$i][condition_grade]", array_combine($grades, $grades), $line['condition_grade'] ?? 'VG+', ['class' => 'form-control']) !!}</td>
                                        <td>{!! Form::number("lines[$i][quantity]", $line['quantity'] ?? 1, ['class' => 'form-control', 'step' => '0.01', 'min' => '0.01']) !!}</td>
                                        <td>
                                            {!! Form::number("lines[$i][discogs_median_price]", $line['discogs_median_price'] ?? null, ['class' => 'form-control', 'step' => '0.01', 'min' => '0']) !!}
                                            {!! Form::hidden("lines[$i][standard_multiplier]", $line['standard_multiplier'] ?? 0.10, ['class' => 'bfc-std']) !!}
                                        </td>
                                        <td class="bfc-line-cell"><span class="bfc-line-value">$0.00</span><small class="bfc-line-formula"></small></td>
                                        <td><button type="button" class="btn btn-danger btn-xs remove-line"><i class="fa fa-times"></i></button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-default btn-sm" id="add_line_btn"><i class="fa fa-plus"></i> Add line</button>

                    <hr>
                    {{-- Sarah 2026-05-19: offer fields are editable again. The calculator
                         suggests 50% / 75% / 95% of the calculated total, but the cashier
                         needs to type the actual offered + accepted prices (e.g. for
                         fixed-rate item types like Book/CD where the Discogs Median +
                         Multiplier are ignored, or any negotiated number that diverges
                         from the suggestion). Blank a field to fall back to the auto
                         suggestion on the next Calculate. --}}
                    <div class="bfc-running">
                        <span class="bfc-running-note"><i class="fa fa-eye-slash"></i> Standard multiplier is automatic (hidden) · grayed cells don't apply to that item type</span>
                        <span class="bfc-running-figs">
                            <span>Running total <strong id="bfc_running_total">$0.00</strong></span>
                            <span>Final offer · 95% <strong id="bfc_running_final">$0.00</strong></span>
                        </span>
                    </div>
                    <h4>Negotiation offers <small class="text-muted">— auto-filled from the items above. Open at row 1 and work down; <strong style="color:#8a6d00;">row 3 (Final) is the price actually paid and recorded.</strong> Type over any figure to use a negotiated number.</small></h4>
                    @php
                        $offerStartingCash = data_get($calc, 'starting_offer_cash');
                        $offerStartingCredit = data_get($calc, 'starting_offer_credit');
                        $offerSecondCash = data_get($calc, 'second_offer_cash');
                        $offerSecondCredit = data_get($calc, 'second_offer_credit');
                        $offerFinalCash = data_get($calc, 'final_offer_cash');
                        $offerFinalCredit = data_get($calc, 'final_offer_credit');
                        $offerInput = function ($v) {
                            return $v === null || $v === '' ? '' : number_format((float) $v, 2, '.', '');
                        };
                    @endphp
                    <table class="table table-bordered bfc-offer-table">
                        <thead>
                            <tr>
                                <th class="bfc-offer-rowlabel"></th>
                                <th>Cash</th>
                                <th>Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <th class="bfc-offer-rowlabel">1. Opening offer <small class="text-muted" style="font-weight:400;">(start low)</small></th>
                                <td>{!! Form::number('starting_offer_cash', $offerInput($offerStartingCash), ['class' => 'form-control', 'step' => '0.01', 'min' => '0', 'placeholder' => 'auto (50%)']) !!}</td>
                                <td>{!! Form::number('starting_offer_credit', $offerInput($offerStartingCredit), ['class' => 'form-control', 'step' => '0.01', 'min' => '0', 'placeholder' => 'auto (50%)']) !!}</td>
                            </tr>
                            <tr>
                                <th class="bfc-offer-rowlabel">2. Counter offer <small class="text-muted" style="font-weight:400;">(if they push back)</small></th>
                                <td>{!! Form::number('second_offer_cash', $offerInput($offerSecondCash), ['class' => 'form-control', 'step' => '0.01', 'min' => '0', 'placeholder' => 'auto (75%)']) !!}</td>
                                <td>{!! Form::number('second_offer_credit', $offerInput($offerSecondCredit), ['class' => 'form-control', 'step' => '0.01', 'min' => '0', 'placeholder' => 'auto (75%)']) !!}</td>
                            </tr>
                            <tr class="bfc-final-row">
                                <th class="bfc-offer-rowlabel">3. Final price <small style="font-weight:600; color:#8a6d00;">← what you'll pay</small></th>
                                <td>
                                    {!! Form::number('final_offer_cash', $offerInput($offerFinalCash), ['class' => 'form-control bfc-final-edit', 'id' => 'bfc_final_cash', 'step' => '0.01', 'min' => '0', 'placeholder' => 'auto (95%)', 'data-auto' => $offerInput($offerFinalCash)]) !!}
                                    @if(!empty($calc) && $offerFinalCash !== null)
                                        <small class="bfc-calc-hint">Calculator: ${{ number_format((float) $offerFinalCash, 2) }} <span class="bfc-delta" id="bfc_final_cash_delta"></span></small>
                                    @endif
                                </td>
                                <td>
                                    {!! Form::number('final_offer_credit', $offerInput($offerFinalCredit), ['class' => 'form-control bfc-final-edit', 'id' => 'bfc_final_credit', 'step' => '0.01', 'min' => '0', 'placeholder' => 'auto (95%)', 'data-auto' => $offerInput($offerFinalCredit)]) !!}
                                    @if(!empty($calc) && $offerFinalCredit !== null)
                                        <small class="bfc-calc-hint">Calculator: ${{ number_format((float) $offerFinalCredit, 2) }} <span class="bfc-delta" id="bfc_final_credit_delta"></span></small>
                                    @endif
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="help-block small" style="margin-top:-4px;">Tip: these fill in automatically from the items above as you type. Type over any figure to use a negotiated price, or blank it to snap back to the suggestion.</p>
                    <div class="form-group">
                        <label>Notes <span class="text-muted">(sealed items, rare finds, condition concerns)</span></label>
                        {!! Form::textarea('notes', $input['notes'] ?? null, ['class' => 'form-control', 'rows' => 2]) !!}
                    </div>

                    <hr>
                    <div id="bfc_calc_error" class="alert alert-danger" style="display:none;"></div>
                    <div class="pos-action-row">
                        <span class="text-muted small" style="margin-right:auto; align-self:center; max-width:520px;">The totals above are a live preview. Saving records this quote to History and opens the accept / reject step (compliance &amp; signature).</span>
                        <button type="submit" class="btn btn-primary"><i class="fa fa-arrow-right"></i> Save quote &amp; continue</button>
                    </div>

                    </form>
                </div>
            </div>
        </div>
    </div>

    @if(!empty($calc))
        @php
            $summary = data_get($calc, 'collection_summary', []);
            $fc = data_get($summary, 'format_counts', []);
            $cb = data_get($summary, 'condition_buckets', []);
            $locName = '—';
            if (!empty($input['location_id'])) {
                $locName = $locations[$input['location_id']] ?? ('#' . $input['location_id']);
            }
        @endphp
        <div class="row">
            <div class="col-md-12">
                <div class="box box-success">
                    <div class="box-header with-border"><h3 class="box-title">Calculated offer &amp; transaction details</h3></div>
                    <div class="box-body">
                        <h4 class="text-muted">Automatic snapshot</h4>
                        <div class="row small" style="margin-bottom:12px;">
                            <div class="col-md-4"><strong>Date &amp; time:</strong> {{ @format_datetime(\Carbon\Carbon::now()) }}</div>
                            <div class="col-md-4"><strong>Store:</strong> {{ $locName }}</div>
                            <div class="col-md-4"><strong>Employee:</strong> {{ auth()->user()->user_full_name ?? auth()->user()->username ?? '—' }}</div>
                        </div>

                        <h4>Calculator totals</h4>
                        <div class="row">
                            <div class="col-md-3"><strong>Calculator cash total (suggested):</strong><br>@format_currency(data_get($calc, 'calculated_cash_total', 0))</div>
                            <div class="col-md-3"><strong>Calculator credit total (suggested):</strong><br>@format_currency(data_get($calc, 'calculated_credit_total', 0))</div>
                            <div class="col-md-3"><strong>Final cash:</strong><br>@format_currency(data_get($calc, 'final_offer_cash', 0))</div>
                            <div class="col-md-3"><strong>Final credit:</strong><br>@format_currency(data_get($calc, 'final_offer_credit', 0))</div>
                        </div>

                        <hr>
                        @php $calcLines = data_get($calc, 'lines', []); @endphp
                        @if(!empty($calcLines))
                            <h4>Per-item breakdown <small class="text-muted">(calculator value per line, at 100% — before the 50/75/95% offer steps)</small></h4>
                            <div class="table-responsive">
                                <table class="table table-condensed table-bordered">
                                    <thead>
                                        <tr>
                                            <th>Item type</th>
                                            <th>Title</th>
                                            <th>Grade</th>
                                            <th class="text-right">Qty</th>
                                            <th class="text-right">Line cash value</th>
                                            <th class="text-right">Line credit value</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($calcLines as $cl)
                                            <tr>
                                                <td>{{ $itemTypes[data_get($cl, 'item_type')] ?? data_get($cl, 'item_type') }}</td>
                                                <td>{{ data_get($cl, 'title') ?: '—' }}</td>
                                                <td>{{ data_get($cl, 'condition_grade') ?: '—' }}</td>
                                                <td class="text-right">{{ number_format((float) data_get($cl, 'quantity', 0), 2) }}</td>
                                                <td class="text-right">@format_currency(data_get($cl, 'line_cash_total', 0))</td>
                                                <td class="text-right">@format_currency(data_get($cl, 'line_credit_total', 0))</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <th colspan="4" class="text-right">Calculator total</th>
                                            <th class="text-right">@format_currency(data_get($calc, 'calculated_cash_total', 0))</th>
                                            <th class="text-right">@format_currency(data_get($calc, 'calculated_credit_total', 0))</th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        @endif

                        <hr>
                        <h4>Collection buy <small class="text-muted">(from line items)</small></h4>
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-condensed table-bordered">
                                    <thead><tr><th>Format</th><th class="text-right">Qty</th></tr></thead>
                                    <tbody>
                                        <tr><td>LPs / vinyl bulk &amp; individual</td><td class="text-right">{{ number_format(data_get($fc, 'lp', 0), 2) }}</td></tr>
                                        <tr><td>45s</td><td class="text-right">{{ number_format(data_get($fc, 'rpm45', 0), 2) }}</td></tr>
                                        <tr><td>CDs</td><td class="text-right">{{ number_format(data_get($fc, 'cd', 0), 2) }}</td></tr>
                                        <tr><td>Cassettes</td><td class="text-right">{{ number_format(data_get($fc, 'cassette', 0), 2) }}</td></tr>
                                        <tr><td>DVDs</td><td class="text-right">{{ number_format(data_get($fc, 'dvd', 0), 2) }}</td></tr>
                                        <tr><td>Blu-rays</td><td class="text-right">{{ number_format(data_get($fc, 'bluray', 0), 2) }}</td></tr>
                                        <tr><td>Other</td><td class="text-right">{{ number_format(data_get($fc, 'other', 0), 2) }}</td></tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-condensed table-bordered">
                                    <thead><tr><th>Condition bucket</th><th class="text-right">Qty</th></tr></thead>
                                    <tbody>
                                        <tr><td>Mint / Near Mint</td><td class="text-right">{{ number_format(data_get($cb, 'mint_nm', 0), 2) }}</td></tr>
                                        <tr><td>VG+ / VG</td><td class="text-right">{{ number_format(data_get($cb, 'vg_plus_vg', 0), 2) }}</td></tr>
                                        <tr><td>Good+ and below / other grades</td><td class="text-right">{{ number_format(data_get($cb, 'g_plus_below', 0), 2) }}</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="row" style="margin-top:15px;">
                            <div class="col-md-12">
                                {!! Form::open(['url' => route('buy-from-customer.store'), 'method' => 'post', 'style' => 'display:inline-block;']) !!}
                                @foreach($input as $k => $v)
                                    @if($k === 'lines' && is_array($v))
                                        @foreach($v as $li => $line)
                                            @foreach($line as $lk => $lv)
                                                <input type="hidden" name="lines[{{$li}}][{{$lk}}]" value="{{ $lv }}">
                                            @endforeach
                                        @endforeach
                                    @elseif(!is_array($v))
                                        <input type="hidden" name="{{$k}}" value="{{ $v }}">
                                    @endif
                                @endforeach
                                <button type="submit" class="btn btn-default"><i class="fa fa-save"></i> Save draft</button>
                                {!! Form::close() !!}

                                {!! Form::open(['url' => route('buy-from-customer.accept'), 'method' => 'post', 'style' => 'display:inline-block; margin-left:6px;', 'id' => 'accept_buy_offer_form']) !!}
                                @foreach($input as $k => $v)
                                    @if($k === 'lines' && is_array($v))
                                        @foreach($v as $li => $line)
                                            @foreach($line as $lk => $lv)
                                                <input type="hidden" name="lines[{{$li}}][{{$lk}}]" value="{{ $lv }}">
                                            @endforeach
                                        @endforeach
                                    {{-- payment_method is chosen fresh on the accept step below, so
                                         don't carry the step-1 value in as a duplicate hidden field. --}}
                                    @elseif(!is_array($v) && $k !== 'payment_method')
                                        <input type="hidden" name="{{$k}}" value="{{ $v }}">
                                    @endif
                                @endforeach

                                <div class="well bfc-accept-well" style="margin-top:15px; max-width:920px;">
                                    {{-- Sarah 2026-07-09: capture the amount actually handed over
                                         (cash / store credit / Zelle-Venmo) in one blank field. This is
                                         the number that gets recorded — it overrides the suggestions above. --}}
                                    <h4>Final payment <small class="text-muted">— what the seller actually got; this is the amount recorded</small></h4>
                                    <div class="row">
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>Paid by</label>
                                                <select name="payment_method" id="bfc_accept_pm" class="form-control">
                                                    <option value="cash_in_store" @if($pmVal === 'cash_in_store') selected @endif>Cash (in store)</option>
                                                    <option value="store_credit" @if($pmVal === 'store_credit') selected @endif>Store credit</option>
                                                    <option value="zelle_venmo" @if($pmVal === 'zelle_venmo') selected @endif>Zelle / Venmo (Jon)</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <div class="form-group">
                                                <label>Final amount paid <span class="text-danger">*</span></label>
                                                <div class="input-group">
                                                    <span class="input-group-addon">$</span>
                                                    {!! Form::number('final_amount_paid', null, ['class' => 'form-control', 'id' => 'bfc_accept_final_amount', 'step' => '0.01', 'min' => '0', 'placeholder' => 'amount handed over']) !!}
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-4">
                                            <p class="help-block small" style="margin-top:24px;">
                                                Suggested — Cash <strong>${{ number_format((float) data_get($calc, 'final_offer_cash', 0), 2) }}</strong>
                                                · Credit <strong>${{ number_format((float) data_get($calc, 'final_offer_credit', 0), 2) }}</strong>
                                            </p>
                                        </div>
                                    </div>
                                    <hr style="margin:6px 0 14px;">
                                    <h4>Override</h4>
                                    <p class="text-muted small">If final paid differs from calculator suggested total for the selected payment method, explain briefly.</p>
                                    <div class="form-group">
                                        <label>Override reason <span id="override_required_label" class="text-danger" style="display:none;">(required)</span></label>
                                        <textarea name="price_override_reason" class="form-control" rows="2" placeholder="e.g. Manager approved bump for sealed box set">{{ $input['price_override_reason'] ?? '' }}</textarea>
                                    </div>

                                    <h4>Compliance <small class="text-danger">both required to accept</small></h4>
                                    <div class="bfc-compliance-row">
                                        <label>
                                            <input type="checkbox" name="compliance_items_owned" value="1" class="bfc-compliance-cb"> Seller confirms the items are legally theirs and not stolen.
                                        </label>
                                    </div>
                                    <div class="bfc-compliance-row">
                                        <label>
                                            <input type="checkbox" name="compliance_sales_final" value="1" class="bfc-compliance-cb"> Seller acknowledges all sales are final.
                                        </label>
                                    </div>
                                    <p class="help-block">Seller signs below to acknowledge the statements above. <strong class="text-danger">Signature is required.</strong></p>
                                    <div class="form-group">
                                        <label>Signature <span class="text-danger">*</span></label>
                                        <div id="buy_signature_box" style="border:1px solid #ccc; background:#fafafa; display:inline-block;">
                                            <canvas id="buy_signature_canvas" width="700" height="180" style="max-width:100%; height:auto; touch-action:none;"></canvas>
                                        </div>
                                        <div style="margin-top:6px;">
                                            <button type="button" class="btn btn-default btn-sm" id="buy_signature_clear"><i class="fa fa-eraser"></i> Clear signature</button>
                                        </div>
                                        <input type="hidden" name="seller_signature_data" id="buy_signature_input" value="">
                                    </div>
                                    <div id="bfc_accept_error" class="alert alert-danger" style="display:none;"></div>
                                </div>

                                <button type="submit" class="btn btn-success" id="accept_buy_offer_btn"><i class="fa fa-check"></i> Accept offer (create purchase)</button>
                                {!! Form::close() !!}

                                {!! Form::open(['url' => route('buy-from-customer.reject'), 'method' => 'post', 'style' => 'display:inline-block; margin-left:6px;', 'id' => 'reject_buy_offer_form']) !!}
                                @foreach($input as $k => $v)
                                    @if($k === 'lines' && is_array($v))
                                        @foreach($v as $li => $line)
                                            @foreach($line as $lk => $lv)
                                                <input type="hidden" name="lines[{{$li}}][{{$lk}}]" value="{{ $lv }}">
                                            @endforeach
                                        @endforeach
                                    @elseif(!is_array($v))
                                        <input type="hidden" name="{{$k}}" value="{{ $v }}">
                                    @endif
                                @endforeach
                                <input type="text" name="rejection_reason" class="form-control" style="display:inline-block; width:260px;" placeholder="Rejection reason" required>
                                <button type="submit" class="btn btn-danger"><i class="fa fa-times"></i> Mark rejected</button>
                                {!! Form::close() !!}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</section>

@include('sale_pos.partials.customer_account_modal')
@include('help.partials.tour_button', ['tourSteps' => \App\Help\Catalog::tour('buy_from_customer')])
@endsection

@section('javascript')
<script>
    (function () {
        function toggleSellerMode() {
            var mode = $('#seller_mode').val();
            $('.seller-contact-block').toggle(mode === 'contact');
            $('.seller-phone-block').toggle(mode !== 'contact');
        }

        $(document).on('change', '#seller_mode', toggleSellerMode);

        // Sarah 2026-07-19: hard-gate the items + quote behind seller capture.
        // Until the seller is identified — an existing account picked, or a
        // walk-in's first name + phone entered — the item table, Add line,
        // negotiation offers, notes and the Save-quote button are all disabled.
        // Mirrors the server-side required_if rule in validateRequest, and uses
        // the same bfcSellerGate() check the submit handler does, so the client
        // and server agree on what "identified" means. "The contact is the asset."
        function bfcSellerReady() {
            return !bfcSellerGate();
        }
        function bfcApplyItemsGate() {
            var locked = !bfcSellerReady();
            $('#offer_lines_table :input')
                .add('#add_line_btn')
                .add('.bfc-offer-table :input')
                .add('#buy_offer_form textarea[name="notes"]')
                .add('#buy_offer_form button[type="submit"]')
                .prop('disabled', locked);
            $('#bfc_items_gate_hint').toggle(locked);
        }
        // Re-evaluate the gate on any seller-field change. #contact_id is a
        // select2 picker whose selection surfaces via select2:select / :unselect
        // / :clear (a plain `change` isn't fired reliably here); those events
        // bubble, so a single delegated binding from document catches them
        // regardless of when select2 initializes — and still works if select2
        // fails to init at all (the native `change` fires). (Sarah 2026-07-20)
        $(document).on('input change select2:select select2:unselect select2:clear',
            '#buy_offer_form input[name="seller_first_name"], #buy_offer_form input[name="seller_phone"], #contact_id, #seller_mode',
            bfcApplyItemsGate);

        toggleSellerMode();
        bfcApplyItemsGate();

        // View account: load the selected contact's store credit, gift cards,
        // preorders and purchase history into the shared POS customer modal so
        // the cashier can check balances before quoting a store-credit offer.
        function loadCustomerAccount(contactId) {
            $('#customer_account_loading').show();
            $('#customer_account_content').hide();
            $('#customer_account_modal').modal('show');

            $.ajax({
                url: '/sells/pos/get-customer-account-info',
                type: 'GET',
                data: { contact_id: contactId },
                dataType: 'json',
                success: function (response) {
                    $('#customer_account_loading').hide();
                    if (!response || !response.success || !response.data) {
                        $('#customer_account_content').show();
                        toastr.error('Failed to load customer information.');
                        return;
                    }
                    var data = response.data;
                    var contact = data.contact;

                    $('#modal_customer_name').text(contact.name);
                    $('#modal_account_balance').text(__currency_trans_from_en(contact.balance || 0, true));
                    $('#modal_lifetime_purchases').text(__currency_trans_from_en(contact.lifetime_purchases || 0, true));
                    $('#modal_loyalty_points').text(contact.loyalty_points || 0);
                    $('#modal_loyalty_tier').text(contact.loyalty_tier || 'Bronze');
                    $('#modal_last_purchase_date').text(contact.last_purchase_date || 'Never');
                    $('#modal_total_gift_card_balance').text(__currency_trans_from_en(data.total_gift_card_balance || 0, true));
                    $('#modal_store_credit_contact_id').val(contact.id);
                    $('#modal_store_credit_amount').val('');

                    var giftCardsHtml = '';
                    if (data.gift_cards && data.gift_cards.length > 0) {
                        data.gift_cards.forEach(function (card) {
                            giftCardsHtml += '<p><strong>Card:</strong> ' + card.card_number +
                                ' | <strong>Balance:</strong> ' + __currency_trans_from_en(card.balance, true);
                            if (card.expiry_date) {
                                giftCardsHtml += ' | <strong>Expires:</strong> ' + card.expiry_date;
                            }
                            giftCardsHtml += '</p>';
                        });
                    } else {
                        giftCardsHtml = '<p class="text-muted">No active gift cards</p>';
                    }
                    $('#modal_gift_cards_list').html(giftCardsHtml);

                    var preordersHtml = '';
                    var preorderCount = 0;
                    if (data.preorders && data.preorders.length > 0) {
                        preorderCount = data.preorders.length;
                        data.preorders.forEach(function (preorder) {
                            var productDisplay = preorder.product_name;
                            if (preorder.artist) {
                                productDisplay = preorder.artist + ' - ' + productDisplay;
                            }
                            preordersHtml += '<tr>' +
                                '<td>' + productDisplay + '</td>' +
                                '<td>' + (preorder.sub_sku || 'N/A') + '</td>' +
                                '<td>' + preorder.quantity + '</td>' +
                                '<td>' + preorder.order_date + '</td>' +
                                '<td>' + (preorder.expected_date || 'Not set') + '</td>' +
                                '</tr>';
                        });
                    } else {
                        preordersHtml = '<tr><td colspan="5" class="text-center text-muted">No pending preorders</td></tr>';
                    }
                    $('#modal_preorders_list').html(preordersHtml);
                    $('#modal_preorder_count').text('(' + preorderCount + ' preorder' + (preorderCount !== 1 ? 's' : '') + ')');

                    var totalPurchases = data.total_purchases_count || 0;
                    $('#modal_purchase_count').text('(' + totalPurchases + ' purchase' + (totalPurchases !== 1 ? 's' : '') + ')');

                    var purchasesHtml = '';
                    if (data.all_purchases && data.all_purchases.length > 0) {
                        data.all_purchases.forEach(function (purchase) {
                            var itemsText = purchase.item_count + ' item' + (purchase.item_count !== 1 ? 's' : '');
                            var viewLink = '<a href="/sells/' + purchase.id + '" target="_blank" class="btn btn-xs btn-info"><i class="fa fa-eye"></i> View</a>';
                            purchasesHtml += '<tr>' +
                                '<td><strong>' + purchase.invoice_no + '</strong></td>' +
                                '<td>' + purchase.date + '</td>' +
                                '<td>' + itemsText + '</td>' +
                                '<td>' + __currency_trans_from_en(purchase.total, true) + '</td>' +
                                '<td><span class="label label-' + (purchase.payment_status === 'paid' ? 'success' : purchase.payment_status === 'partial' ? 'warning' : 'danger') + '">' + purchase.payment_status + '</span></td>' +
                                '<td>' + viewLink + '</td>' +
                                '</tr>';
                        });
                    } else {
                        purchasesHtml = '<tr><td colspan="6" class="text-center text-muted">No purchases found</td></tr>';
                    }
                    $('#modal_all_purchases_list').html(purchasesHtml);

                    $('#customer_account_content').show();
                },
                error: function (xhr, status, error) {
                    $('#customer_account_loading').hide();
                    $('#customer_account_content').show();
                    toastr.error('Error loading customer information: ' + error);
                }
            });
        }

        $(document).on('click', '#bfc_view_account_btn', function (e) {
            e.preventDefault();
            var contactId = $('#contact_id').val();
            if (!contactId) {
                toastr.error('Select an existing contact first.');
                return;
            }
            loadCustomerAccount(contactId);
        });

        // Add store credit from inside the modal (mirrors the POS / contact list).
        $(document).on('click', '#modal_add_store_credit_btn', function () {
            var contactId = $('#modal_store_credit_contact_id').val();
            var amount = parseFloat($('#modal_store_credit_amount').val()) || 0;
            if (!contactId) {
                toastr.error('Customer not selected.');
                return;
            }
            if (amount <= 0) {
                toastr.error('Please enter a valid amount.');
                return;
            }
            $.ajax({
                method: 'POST',
                url: '/contacts/' + contactId + '/store-credit',
                dataType: 'json',
                data: {
                    amount: amount,
                    _token: $('meta[name="csrf-token"]').attr('content')
                },
                success: function (result) {
                    if (result.success) {
                        toastr.success(result.msg);
                        $('#modal_account_balance').text(__currency_trans_from_en(result.new_balance || 0, true));
                        $('#modal_store_credit_amount').val('');
                    } else {
                        toastr.error(result.msg || 'Unable to add store credit.');
                    }
                },
                error: function () {
                    toastr.error('Unable to add store credit.');
                }
            });
        });

        // Sarah 2026-05-06: auto-fill the per-row "Standard Multiplier" from the
        // Discogs median price (the value tier from Sarah's sheet). Condition is
        // already factored in separately by the calculator's gradeMultiplier, so
        // we don't double-apply it here. Cashier can type over the value and the
        // override sticks (we mark the cell as touched on input).
        function computeStdMult(price) {
            var p = parseFloat(price);
            if (!isFinite(p) || p <= 0) {
                return 0.10;
            }
            if (p < 5)    return 0.10;
            if (p < 10)   return 0.20;
            if (p < 15)   return 0.22;
            if (p < 20)   return 0.25;
            if (p < 30)   return 0.26;
            if (p < 375)  return 0.27;
            return 0.31;
        }

        function refreshStdMultForRow($row) {
            var $stdMult = $row.find('input[name$="[standard_multiplier]"]');
            if (!$stdMult.length) return;
            if ($stdMult.data('manual')) return;
            var price = $row.find('input[name$="[discogs_median_price]"]').val();
            $stdMult.val(computeStdMult(price).toFixed(2));
        }

        $(document).on('input change', '#offer_lines_table input[name$="[discogs_median_price]"]', function () {
            refreshStdMultForRow($(this).closest('tr'));
        });

        // If the cashier types directly into the multiplier, treat it as a
        // manual override and stop auto-recomputing for that row.
        $(document).on('input', '#offer_lines_table input[name$="[standard_multiplier]"]', function () {
            $(this).data('manual', true);
        });

        $(document).on('click', '#add_line_btn', function () {
            var $tbody = $('#offer_lines_table tbody');
            var $lastRow = $tbody.find('tr').last();
            // Inherit type / grade / standard multiplier from the row above so
            // a fresh row doesn't snap to whichever option is first in the
            // dropdown (which is alphabetical "Fair" — almost never right).
            var prevType = $lastRow.find('select[name$="[item_type]"]').val() || 'individual_vinyl';
            var prevGrade = $lastRow.find('select[name$="[condition_grade]"]').val() || 'VG+';
            var prevStdMult = $lastRow.find('input[name$="[standard_multiplier]"]').val() || '0.10';
            var idx = $tbody.find('tr').length;
            var row = '<tr>'
                + '<td><select name="lines[' + idx + '][item_type]" class="form-control">@foreach($itemTypes as $k => $label)<option value="{{$k}}">{{ $label }}</option>@endforeach</select></td>'
                + '<td><input type="text" name="lines[' + idx + '][title]" class="form-control"></td>'
                + '<td><input type="text" name="lines[' + idx + '][genre]" class="form-control"></td>'
                + '<td><select name="lines[' + idx + '][condition_grade]" class="form-control">@foreach($grades as $g)<option value="{{$g}}">{{ $g }}</option>@endforeach</select></td>'
                + '<td><input type="number" step="0.01" min="0.01" name="lines[' + idx + '][quantity]" value="1" class="form-control"></td>'
                + '<td><input type="number" step="0.01" min="0" name="lines[' + idx + '][discogs_median_price]" class="form-control"><input type="hidden" name="lines[' + idx + '][standard_multiplier]" value="' + prevStdMult + '" class="bfc-std"></td>'
                + '<td class="bfc-line-cell"><span class="bfc-line-value">$0.00</span><small class="bfc-line-formula"></small></td>'
                + '<td><button type="button" class="btn btn-danger btn-xs remove-line"><i class="fa fa-times"></i></button></td>'
                + '</tr>';
            var $newRow = $($.parseHTML(row));
            $newRow.find('select[name$="[item_type]"]').val(prevType);
            $newRow.find('select[name$="[condition_grade]"]').val(prevGrade);
            $tbody.append($newRow);
            bfcApplyRowState($newRow);
            bfcRecalcAll();
        });

        $(document).on('click', '.remove-line', function () {
            if ($('#offer_lines_table tbody tr').length > 1) {
                $(this).closest('tr').remove();
                bfcRecalcAll();
            }
        });

        // Sarah 2026-07-05: individual-Discogs lines are priced as
        // Median × grade × standard multiplier, so a blank Discogs Median
        // flattens every grade to $0 — it looks like grade "does nothing".
        // Guard Calculate: if a filled-in individual line (has a title/genre)
        // is missing its median, highlight the cell and stop. Blank default
        // rows (no title) are ignored so the form still calculates normally.
        function bfcFlagMissingMedians() {
            var problems = 0;
            $('#offer_lines_table tbody tr').each(function () {
                var $row = $(this);
                if ($row.find('select[name$="[item_type]"]').val() !== 'individual_vinyl') {
                    return;
                }
                var qty = parseFloat($row.find('input[name$="[quantity]"]').val()) || 0;
                var title = ($row.find('input[name$="[title]"]').val() || '').trim();
                var genre = ($row.find('input[name$="[genre]"]').val() || '').trim();
                var $median = $row.find('input[name$="[discogs_median_price]"]');
                var median = parseFloat($median.val()) || 0;
                var inUse = qty > 0 && (title !== '' || genre !== '');
                if (inUse && median <= 0) {
                    $median.addClass('bfc-median-missing');
                    problems++;
                } else {
                    $median.removeClass('bfc-median-missing');
                }
            });
            return problems;
        }

        // Clear a row's flag as soon as a median is typed in.
        $(document).on('input change', '#offer_lines_table input[name$="[discogs_median_price]"]', function () {
            if ((parseFloat($(this).val()) || 0) > 0) {
                $(this).removeClass('bfc-median-missing');
            }
        });

        // Sarah 2026-07-19: the seller must be identified before a quote can be
        // produced. Returning seller → pick an existing account; new / walk-in →
        // first name + phone at minimum. Mirrors the server-side required_if
        // gate in BuyFromCustomerController::validateRequest so the cashier gets
        // an instant, clear message instead of a round-trip.
        function bfcSellerGate() {
            var mode = $('#seller_mode').val();
            if (mode === 'contact') {
                if (!$('#contact_id').val()) {
                    return { field: '#contact_id', msg: 'Select the seller\'s existing account before getting a quote.' };
                }
                return null;
            }
            // New / walk-in
            if (!$.trim($('input[name="seller_first_name"]').val())) {
                return { field: 'input[name="seller_first_name"]', msg: 'Enter the seller\'s first name before getting a quote.' };
            }
            if (!$.trim($('input[name="seller_phone"]').val())) {
                return { field: 'input[name="seller_phone"]', msg: 'Enter the seller\'s phone number before getting a quote.' };
            }
            return null;
        }

        $('#buy_offer_form').on('submit', function (e) {
            var sellerErr = bfcSellerGate();
            if (sellerErr) {
                e.preventDefault();
                $('#bfc_calc_error').html('<strong>' + sellerErr.msg + '</strong>').show();
                var $f = $(sellerErr.field);
                if ($f.length) {
                    $('html, body').animate({ scrollTop: $f.offset().top - 140 }, 200);
                    // select2-backed contact picker opens on .select2-open; a plain
                    // input just focuses.
                    if ($f.hasClass('select2')) { $f.select2('open'); } else { $f.trigger('focus'); }
                }
                return false;
            }
            if (bfcFlagMissingMedians() > 0) {
                e.preventDefault();
                $('#bfc_calc_error').html(
                    '<strong>Enter a Discogs Median Price for each highlighted line.</strong> ' +
                    'Individual Discogs items are priced as Median × grade × standard multiplier, so with a blank median ' +
                    'every grade calculates to $0 — the grade can\'t lower the offer until there\'s a median for it to scale.'
                ).show();
                var $first = $('#offer_lines_table .bfc-median-missing').first();
                if ($first.length) {
                    $('html, body').animate({ scrollTop: $first.offset().top - 140 }, 200);
                    $first.trigger('focus');
                }
                return false;
            }
            $('#bfc_calc_error').hide();
        });

        // Sarah 2026-07-05: live per-line value + running total. Mirrors the
        // server's BuyOfferCalculatorService::calculate so what the cashier sees
        // while typing matches the numbers Calculate returns. Rules (modes, unit
        // rates, grade multipliers, value tiers) come straight from getRules() so
        // the two can't drift. Also drives the per-row enable/disable: median only
        // applies to individual/value-percent types, grade only to graded types.
        @php
            $bfcRulesRaw = app(\App\Services\BuyOfferCalculatorService::class)->getRules();
            $bfcRules = [
                'credit_bonus' => $bfcRulesRaw['credit_bonus_multiplier'],
                'grades' => $bfcRulesRaw['grade_multipliers'],
                'types' => [],
            ];
            foreach ($bfcRulesRaw['item_types'] as $bfcK => $bfcCfg) {
                $bfcRules['types'][$bfcK] = [
                    'mode' => $bfcCfg['mode'],
                    'unit_rate' => $bfcCfg['unit_rate'] ?? null,
                    'no_grading' => !empty($bfcCfg['no_grading']),
                    'value_tiers' => $bfcCfg['value_tiers'] ?? null,
                ];
            }
        @endphp
        var BFC_RULES = @json($bfcRules);
        var BFC_HAS_CALC = @json(!empty($calc));
        // The negotiation ladder auto-fills from the live total at 50 / 75 / 95%
        // (credit at ×credit_bonus), mirroring the server. We only drive it live
        // BEFORE the first save — after a save the server owns those values and the
        // override tracking further down manages the Final row. A field the cashier
        // has typed into is left alone; blanking it re-enables auto-fill.
        var BFC_LADDER = [
            ['starting_offer_cash', 'cash', 0.50], ['starting_offer_credit', 'credit', 0.50],
            ['second_offer_cash', 'cash', 0.75], ['second_offer_credit', 'credit', 0.75],
            ['final_offer_cash', 'cash', 0.95], ['final_offer_credit', 'credit', 0.95]
        ];
        function bfcPopulateLadder(cashTotal) {
            var creditTotal = Math.round(cashTotal * (parseFloat(BFC_RULES.credit_bonus) || 1) * 100) / 100;
            BFC_LADDER.forEach(function (f) {
                var $inp = $('#buy_offer_form').find('[name="' + f[0] + '"]');
                if (!$inp.length || $inp.data('manual')) return;
                var base = f[1] === 'credit' ? creditTotal : cashTotal;
                var val = cashTotal > 0 ? (Math.round(base * f[2] * 100) / 100).toFixed(2) : '';
                $inp.val(val).attr('data-auto', val);
            });
        }

        function bfcGradeMult(type, grade) {
            var t = BFC_RULES.types[type];
            if (!t || t.no_grading) return 1.0;
            var g = BFC_RULES.grades[grade];
            return (g === undefined || g === null) ? 1 : g;
        }
        function bfcPercentForValue(v, tiers) {
            v = parseFloat(v) || 0;
            tiers = tiers || [];
            for (var i = 0; i < tiers.length; i++) {
                var u = tiers[i].under;
                if (u === null || u === undefined || v < parseFloat(u)) {
                    return parseFloat(tiers[i].percent) || 0;
                }
            }
            return 0;
        }
        function bfcMoney(n) { n = Math.round(n * 100) / 100; return '$' + n.toFixed(2); }
        function bfcLineValue($row) {
            var type = $row.find('select[name$="[item_type]"]').val();
            var t = BFC_RULES.types[type];
            if (!t) return 0;
            var qty = parseFloat($row.find('input[name$="[quantity]"]').val()) || 0;
            if (qty <= 0) return 0;
            var gm = bfcGradeMult(type, $row.find('select[name$="[condition_grade]"]').val());
            var median = parseFloat($row.find('input[name$="[discogs_median_price]"]').val()) || 0;
            var line = 0;
            if (t.mode === 'individual_discogs') {
                line = qty * median * gm * computeStdMult(median);
            } else if (t.mode === 'value_percent') {
                line = qty * median * bfcPercentForValue(median, t.value_tiers);
            } else {
                line = qty * (parseFloat(t.unit_rate) || 0) * gm;
            }
            return Math.round(line * 100) / 100;
        }
        function bfcLineFormula($row) {
            var type = $row.find('select[name$="[item_type]"]').val();
            var t = BFC_RULES.types[type];
            if (!t) return '';
            var qty = parseFloat($row.find('input[name$="[quantity]"]').val()) || 0;
            if (qty <= 0) return '';
            var median = parseFloat($row.find('input[name$="[discogs_median_price]"]').val()) || 0;
            var gm = bfcGradeMult(type, $row.find('select[name$="[condition_grade]"]').val());
            if (t.mode === 'individual_discogs') {
                if (median <= 0) return '';
                var f = median + ' × ' + gm + ' × ' + computeStdMult(median).toFixed(2);
                return qty !== 1 ? (qty + ' × (' + f + ')') : f;
            }
            if (t.mode === 'value_percent') {
                if (median <= 0) return '';
                return qty + ' × ' + median + ' × ' + Math.round(bfcPercentForValue(median, t.value_tiers) * 100) + '%';
            }
            return qty + ' × ' + (parseFloat(t.unit_rate) || 0).toFixed(2) + (t.no_grading ? '' : ' × ' + gm);
        }
        function bfcApplyRowState($row) {
            var type = $row.find('select[name$="[item_type]"]').val();
            var t = BFC_RULES.types[type] || {};
            var usesValue = (t.mode === 'individual_discogs' || t.mode === 'value_percent');
            var $median = $row.find('input[name$="[discogs_median_price]"]');
            $median.prop('disabled', !usesValue).toggleClass('bfc-cell-off', !usesValue);
            $median.attr('placeholder', usesValue ? (t.mode === 'value_percent' ? 'value $' : 'median $') : 'n/a');
            if (!usesValue) { $median.removeClass('bfc-median-missing'); }
            var $grade = $row.find('select[name$="[condition_grade]"]');
            $grade.prop('disabled', !!t.no_grading).toggleClass('bfc-cell-off', !!t.no_grading);
        }
        function bfcRecalcAll() {
            var cashTotal = 0;
            $('#offer_lines_table tbody tr').each(function () {
                var $row = $(this);
                var v = bfcLineValue($row);
                $row.find('.bfc-line-value').text(bfcMoney(v));
                $row.find('.bfc-line-formula').text(bfcLineFormula($row));
                cashTotal += v;
            });
            cashTotal = Math.round(cashTotal * 100) / 100;
            $('#bfc_running_total').text(bfcMoney(cashTotal));
            $('#bfc_running_final').text(bfcMoney(Math.round(cashTotal * 0.95 * 100) / 100));
            if (!BFC_HAS_CALC) { bfcPopulateLadder(cashTotal); }
        }

        $(document).on('input change', '#offer_lines_table input', bfcRecalcAll);
        $(document).on('change', '#offer_lines_table select', function () {
            bfcApplyRowState($(this).closest('tr'));
            bfcRecalcAll();
        });

        // Pre-save: a ladder field the cashier edits is marked manual so the
        // auto-fill leaves it alone; blanking it clears the flag so it snaps back
        // to the suggestion on the next recalc.
        if (!BFC_HAS_CALC) {
            $(document).on('input', '#buy_offer_form input[name$="_offer_cash"], #buy_offer_form input[name$="_offer_credit"]', function () {
                $(this).data('manual', $(this).val() !== '');
                bfcRecalcAll();
            });
        }

        $('#offer_lines_table tbody tr').each(function () { bfcApplyRowState($(this)); });
        bfcRecalcAll();

        // Sarah 2026-07-05: background autosave. Persists seller name / phone /
        // email + whatever items are entered to a Draft (visible in History) even
        // if the cashier never clicks Save & continue — the contact is the asset.
        // Debounced; reuses offer_id so it's one draft per quote, not one per
        // keystroke. The server skips Contact creation on this path (a half-typed
        // phone would spawn junk) and swallows errors. A final flush fires on
        // tab-hide / close via sendBeacon.
        (function bfcAutosave() {
            var $form = $('#buy_offer_form');
            if (!$form.length) return;
            var url = '{{ route('buy-from-customer.autosave') }}';
            var timer = null, inFlight = false, pending = false, submitting = false;

            function hasContent() {
                // Returning seller: an existing account selected is enough on its
                // own to save a draft (matches the server's autosaveHasContent).
                if ($('#seller_mode').val() === 'contact' && ($form.find('[name="contact_id"]').val() || '').toString().trim() !== '') {
                    return true;
                }
                var seller = ['seller_first_name', 'seller_last_name', 'seller_name', 'seller_phone', 'seller_email'].some(function (n) {
                    return ($form.find('[name="' + n + '"]').val() || '').trim() !== '';
                });
                if (seller) return true;
                var line = false;
                $('#offer_lines_table tbody tr').each(function () {
                    var $r = $(this);
                    if (($r.find('input[name$="[title]"]').val() || '').trim() !== '') line = true;
                    if (($r.find('input[name$="[genre]"]').val() || '').trim() !== '') line = true;
                    if (($r.find('input[name$="[discogs_median_price]"]').val() || '').trim() !== '') line = true;
                });
                return line;
            }

            function doSave() {
                if (submitting) return;
                if (!hasContent()) return;
                if (inFlight) { pending = true; return; }
                inFlight = true;
                $.ajax({ url: url, method: 'POST', dataType: 'json', data: $form.serialize() })
                    .done(function (resp) {
                        if (resp && resp.offer_id) { $('#bfc_offer_id').val(resp.offer_id); }
                    })
                    .always(function () {
                        inFlight = false;
                        if (pending) { pending = false; doSave(); }
                    });
            }

            function schedule() { clearTimeout(timer); timer = setTimeout(doSave, 1200); }

            $form.on('input change', 'input, select, textarea', schedule);
            // Once the cashier intentionally submits ANY buy form (Calculate on
            // #buy_offer_form, or the separate Save-draft / Accept / Reject forms),
            // stop autosaving entirely. Otherwise the unload path below fires a
            // sendBeacon autosave AS the page navigates away on submit — and when
            // the async autosave hasn't returned an offer_id yet, that beacon POSTs
            // with an empty offer_id and spawns a SECOND draft alongside the one the
            // submitted form creates. That's the "2 drafts per buy" bug. Guard both
            // the debounced save and the unload beacon on this flag; a genuine tab
            // close (submitting stays false) still flushes as intended.
            // Scoped to the buy forms by action (Calculate/Save/Accept/Reject all
            // POST to a buy-from-customer.* route) so an unrelated AJAX form in the
            // layout can't permanently kill autosave for the page.
            $(document).on('submit', 'form', function () {
                var action = ($(this).attr('action') || '');
                if (action.indexOf('buy-from-customer') === -1 && this.id !== 'buy_offer_form') return;
                submitting = true;
                clearTimeout(timer);
            });

            // Final flush when the tab is hidden / closed — sendBeacon survives unload.
            function flushBeacon() {
                if (submitting) return;
                if (!hasContent() || !navigator.sendBeacon) return;
                try {
                    var blob = new Blob([$form.serialize()], { type: 'application/x-www-form-urlencoded' });
                    navigator.sendBeacon(url, blob);
                } catch (e) {}
            }
            document.addEventListener('visibilitychange', function () {
                if (document.visibilityState === 'hidden') flushBeacon();
            });
            window.addEventListener('pagehide', flushBeacon);
        })();

        @if(!empty($calc))
        // Sarah 2026-05-19: the offer fields live inside the Calculate form, but
        // Save / Accept / Reject are separate forms whose offer values come from
        // hidden inputs emitted server-side (last Calculate's $input). If the
        // cashier types into an offer field AFTER Calculate, that typed value
        // would be lost when they click Accept. So at submit time, sync the live
        // offer field values into matching hidden inputs in the submitting form.
        var BFC_OFFER_FIELDS = [
            'starting_offer_cash', 'starting_offer_credit',
            'second_offer_cash', 'second_offer_credit',
            'final_offer_cash', 'final_offer_credit',
        ];
        function syncOffersIntoForm($form) {
            BFC_OFFER_FIELDS.forEach(function (name) {
                var live = $('#buy_offer_form').find('[name="' + name + '"]').val();
                if (live === undefined) return;
                var $h = $form.find('input[name="' + name + '"]').not(':visible');
                if ($h.length) {
                    $h.val(live);
                } else {
                    $form.append('<input type="hidden" name="' + name + '" value="' + $('<div>').text(live).html() + '">');
                }
            });
        }
        $('form').each(function () {
            var $form = $(this);
            if ($form.attr('id') === 'buy_offer_form') return;
            $form.on('submit', function () { syncOffersIntoForm($form); });
        });

        // Sarah 2026-05-20: side-by-side calc reference under each editable Final
        // input ("Calculator: $X.XX  (+$5.00)") so the cashier can always see both
        // their actual offer and the auto suggestion. Also toggles the override-
        // required label live, and resets the Reject form's final back to the
        // calc auto so rejecting after an override doesn't trip the server's
        // override-reason validation (Reject has no override-reason field).
        // Runs AFTER the per-form submit handler above so the Reject reset wins.
        (function finalOverrideSync() {
            var $cash = $('#bfc_final_cash');
            var $credit = $('#bfc_final_credit');
            if (!$cash.length && !$credit.length) return;

            var autoCash = parseFloat($cash.data('auto'));
            var autoCredit = parseFloat($credit.data('auto'));

            function diverged($input, auto) {
                var v = parseFloat($input.val());
                return isFinite(v) && isFinite(auto) && Math.abs(v - auto) > 0.009;
            }

            function paintDelta($deltaSpan, $input, auto) {
                $deltaSpan.removeClass('bfc-delta-up bfc-delta-down');
                var v = parseFloat($input.val());
                if (!isFinite(v) || !isFinite(auto)) { $deltaSpan.text(''); return; }
                var d = v - auto;
                if (Math.abs(d) < 0.005) { $deltaSpan.text(''); return; }
                var sign = d > 0 ? '+' : '−'; // unicode minus
                $deltaSpan.text('(' + sign + '$' + Math.abs(d).toFixed(2) + ')');
                $deltaSpan.addClass(d > 0 ? 'bfc-delta-up' : 'bfc-delta-down');
            }

            function refresh() {
                if ($cash.length) {
                    $cash.toggleClass('bfc-final-overridden', diverged($cash, autoCash));
                    paintDelta($('#bfc_final_cash_delta'), $cash, autoCash);
                }
                if ($credit.length) {
                    $credit.toggleClass('bfc-final-overridden', diverged($credit, autoCredit));
                    paintDelta($('#bfc_final_credit_delta'), $credit, autoCredit);
                }
                var pm = $('#payment_method').val();
                var activeDiverged = (pm === 'store_credit')
                    ? diverged($credit, autoCredit)
                    : diverged($cash, autoCash);
                $('#override_required_label').toggle(activeDiverged);
            }

            $cash.on('input change', refresh);
            $credit.on('input change', refresh);
            $(document).on('change', '#payment_method', refresh);
            refresh();

            $('#reject_buy_offer_form').on('submit', function () {
                $(this).find('input[type="hidden"][name="final_offer_cash"]').val(isFinite(autoCash) ? autoCash.toFixed(2) : '');
                $(this).find('input[type="hidden"][name="final_offer_credit"]').val(isFinite(autoCredit) ? autoCredit.toFixed(2) : '');
            });
        })();

        (function signaturePad() {
            var canvas = document.getElementById('buy_signature_canvas');
            if (!canvas || !canvas.getContext) return;
            var ctx = canvas.getContext('2d');
            var drawing = false;
            var hasSignature = false; // tracks whether the user actually drew anything

            function pos(e) {
                var r = canvas.getBoundingClientRect();
                var x = (e.clientX !== undefined ? e.clientX : e.touches[0].clientX) - r.left;
                var y = (e.clientY !== undefined ? e.clientY : e.touches[0].clientY) - r.top;
                var sx = canvas.width / r.width;
                var sy = canvas.height / r.height;
                return { x: x * sx, y: y * sy };
            }
            function start(e) {
                drawing = true;
                hasSignature = true;
                ctx.beginPath();
                var p = pos(e);
                ctx.moveTo(p.x, p.y);
                e.preventDefault();
            }
            function move(e) {
                if (!drawing) return;
                hasSignature = true;
                var p = pos(e);
                ctx.lineTo(p.x, p.y);
                ctx.strokeStyle = '#111';
                ctx.lineWidth = 2;
                ctx.stroke();
                ctx.beginPath();
                ctx.moveTo(p.x, p.y);
                e.preventDefault();
            }
            function end() {
                drawing = false;
                ctx.beginPath();
            }
            canvas.addEventListener('mousedown', start);
            canvas.addEventListener('mousemove', move);
            window.addEventListener('mouseup', end);
            canvas.addEventListener('touchstart', start, { passive: false });
            canvas.addEventListener('touchmove', move, { passive: false });
            window.addEventListener('touchend', end);

            $('#buy_signature_clear').on('click', function () {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                $('#buy_signature_input').val('');
                hasSignature = false;
            });

            // Pre-flight check on Accept submit. Catches the silent-fail cases
            // (compliance unchecked, signature blank) on the client so the
            // cashier sees an inline error instead of a server redirect that
            // looks like nothing happened. Sarah 2026-05-06: BFC offers were
            // staying as Drafts because Accept was failing validation server-
            // side with no UI feedback. We still show server errors at the top
            // (see $errors block) — this is the first line of defense.
            $('#accept_buy_offer_form').on('submit', function (e) {
                var problems = [];
                if (!$('input[name="compliance_items_owned"]').is(':checked')) {
                    problems.push('Tick "Seller confirms the items are legally theirs and not stolen."');
                }
                if (!$('input[name="compliance_sales_final"]').is(':checked')) {
                    problems.push('Tick "Seller acknowledges all sales are final."');
                }
                if (!hasSignature) {
                    problems.push('Seller must sign in the signature box.');
                }
                // Sarah 2026-07-09: the actual amount paid must be entered here —
                // it's the number that gets recorded.
                var finalAmt = parseFloat($('#bfc_accept_final_amount').val());
                if (!isFinite(finalAmt) || finalAmt <= 0) {
                    problems.push('Enter the final amount actually paid.');
                }
                // Mirror the server's override gate: if the amount paid differs
                // from the suggested final for the selected payment method, a
                // reason is required (matches validateRequest()).
                var acceptPm = $('#bfc_accept_pm').val();
                var autoForPm = parseFloat(acceptPm === 'store_credit'
                    ? $('#bfc_final_credit').data('auto')
                    : $('#bfc_final_cash').data('auto'));
                if (isFinite(finalAmt) && isFinite(autoForPm) && Math.abs(finalAmt - autoForPm) > 0.009
                    && !$.trim($('textarea[name="price_override_reason"]').val())) {
                    problems.push('Final amount differs from the suggestion — add an override reason.');
                }
                if (problems.length) {
                    e.preventDefault();
                    var $err = $('#bfc_accept_error');
                    $err.html('<strong>Can\'t accept yet:</strong><ul style="margin-top:6px; margin-bottom:0;"><li>' + problems.join('</li><li>') + '</li></ul>').show();
                    $('html, body').animate({ scrollTop: $err.offset().top - 80 }, 200);
                    return false;
                }
                try {
                    $('#buy_signature_input').val(canvas.toDataURL('image/png'));
                } catch (err) {
                    $('#buy_signature_input').val('');
                }
                $('#bfc_accept_error').hide();
            });
        })();
        @endif
    })();
</script>
@endsection
