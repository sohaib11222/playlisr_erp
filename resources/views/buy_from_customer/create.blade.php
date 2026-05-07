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
        /* Three-row offer table: Starting / 2nd / Final × Cash / Credit. */
        .bfc-create .bfc-offer-table { max-width: 560px; margin-bottom: 12px; }
        .bfc-create .bfc-offer-table th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; color: #666; background: #f7f7f7; padding: 8px 10px; border-bottom: 1px solid #ddd; }
        .bfc-create .bfc-offer-table td { padding: 6px; vertical-align: middle; }
        .bfc-create .bfc-offer-table .bfc-offer-rowlabel { width: 160px; font-weight: 600; color: #333; text-transform: none; letter-spacing: 0; background: #fafafa; }
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
                    <div class="col-sm-4"><strong>Buy record #:</strong> @if(($saved_offer_id ?? session('saved_offer_id'))) BFC-{{ str_pad((string) ($saved_offer_id ?? session('saved_offer_id')), 6, '0', STR_PAD_LEFT) }} @else <span class="text-muted">assigned on Calculate</span> @endif</div>
                </div>
            </div>

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
                                <label>Seller mode</label>
                                {!! Form::select('seller_mode', ['contact' => 'Existing account', 'phone' => 'Walk-in / phone first'], $input['seller_mode'] ?? 'phone', ['class' => 'form-control', 'id' => 'seller_mode']) !!}
                            </div>
                        </div>
                        <div class="col-md-3 seller-contact-block">
                            <div class="form-group">
                                <label>Existing contact</label>
                                {!! Form::select('contact_id', $contacts, $input['contact_id'] ?? null, ['class' => 'form-control select2', 'style' => 'width:100%;']) !!}
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Payment method</label>
                                {!! Form::select('payment_method', $paymentMethods, $pmVal, ['class' => 'form-control', 'id' => 'payment_method']) !!}
                            </div>
                        </div>
                    </div>

                    <div class="row seller-phone-block">
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Seller first name</label>
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
                                <label>Phone</label>
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
                    <div class="table-responsive">
                        <table class="table table-bordered" id="offer_lines_table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Title/Notes</th>
                                    <th>Genre</th>
                                    <th>Grade</th>
                                    <th>Qty</th>
                                    <th>Discogs Median (if individual)</th>
                                    <th>Standard Multiplier</th>
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
                                        <td>{!! Form::number("lines[$i][discogs_median_price]", $line['discogs_median_price'] ?? null, ['class' => 'form-control', 'step' => '0.01', 'min' => '0']) !!}</td>
                                        <td>{!! Form::number("lines[$i][standard_multiplier]", $line['standard_multiplier'] ?? 0.10, ['class' => 'form-control', 'step' => '0.01', 'min' => '0']) !!}</td>
                                        <td><button type="button" class="btn btn-danger btn-xs remove-line"><i class="fa fa-times"></i></button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-default btn-sm" id="add_line_btn"><i class="fa fa-plus"></i> Add line</button>

                    <hr>
                    {{-- Sarah 2026-05-06: Starting / 2nd / Final cash + credit are no longer
                         editable — they ARE the offer the calculator computed (50% / 75% / 95%
                         of the calculated total). Cashier just reads them off. The calculate
                         form does NOT POST the offer fields — calculator always recomputes
                         from lines so re-Calculate after editing items always reflects the
                         new total. Save / Accept / Reject forms below DO emit the calc values
                         as hidden inputs so the saveOffer / override-reason logic still works. --}}
                    <h4>Offer to customer <small class="text-muted">auto-calculated from items above</small></h4>
                    @php
                        $offerStartingCash = data_get($calc, 'starting_offer_cash');
                        $offerStartingCredit = data_get($calc, 'starting_offer_credit');
                        $offerSecondCash = data_get($calc, 'second_offer_cash');
                        $offerSecondCredit = data_get($calc, 'second_offer_credit');
                        $offerFinalCash = data_get($calc, 'final_offer_cash');
                        $offerFinalCredit = data_get($calc, 'final_offer_credit');
                        $fmtOffer = function ($v) {
                            return $v === null || $v === '' ? '—' : '$' . number_format((float) $v, 2);
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
                                <th class="bfc-offer-rowlabel">1. Starting offer</th>
                                <td><input type="text" class="form-control bfc-offer-display" value="{{ $fmtOffer($offerStartingCash) }}" readonly tabindex="-1"></td>
                                <td><input type="text" class="form-control bfc-offer-display" value="{{ $fmtOffer($offerStartingCredit) }}" readonly tabindex="-1"></td>
                            </tr>
                            <tr>
                                <th class="bfc-offer-rowlabel">2. 2nd offer</th>
                                <td><input type="text" class="form-control bfc-offer-display" value="{{ $fmtOffer($offerSecondCash) }}" readonly tabindex="-1"></td>
                                <td><input type="text" class="form-control bfc-offer-display" value="{{ $fmtOffer($offerSecondCredit) }}" readonly tabindex="-1"></td>
                            </tr>
                            <tr>
                                <th class="bfc-offer-rowlabel">3. Final offer</th>
                                <td><input type="text" class="form-control bfc-offer-display" value="{{ $fmtOffer($offerFinalCash) }}" readonly tabindex="-1"></td>
                                <td><input type="text" class="form-control bfc-offer-display" value="{{ $fmtOffer($offerFinalCredit) }}" readonly tabindex="-1"></td>
                            </tr>
                        </tbody>
                    </table>
                    <div class="form-group">
                        <label>Notes <span class="text-muted">(sealed items, rare finds, condition concerns)</span></label>
                        {!! Form::textarea('notes', $input['notes'] ?? null, ['class' => 'form-control', 'rows' => 2]) !!}
                    </div>

                    <div class="pos-action-row">
                        <button type="submit" class="btn btn-primary"><i class="fa fa-calculator"></i> Calculate</button>
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
                                    @elseif(!is_array($v))
                                        <input type="hidden" name="{{$k}}" value="{{ $v }}">
                                    @endif
                                @endforeach

                                <div class="well bfc-accept-well" style="margin-top:15px; max-width:920px;">
                                    <h4>Final price &amp; override</h4>
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

                                {!! Form::open(['url' => route('buy-from-customer.reject'), 'method' => 'post', 'style' => 'display:inline-block; margin-left:6px;']) !!}
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
        toggleSellerMode();

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
                + '<td><input type="number" step="0.01" min="0" name="lines[' + idx + '][discogs_median_price]" class="form-control"></td>'
                + '<td><input type="number" step="0.01" min="0" name="lines[' + idx + '][standard_multiplier]" value="' + prevStdMult + '" class="form-control"></td>'
                + '<td><button type="button" class="btn btn-danger btn-xs remove-line"><i class="fa fa-times"></i></button></td>'
                + '</tr>';
            var $newRow = $($.parseHTML(row));
            $newRow.find('select[name$="[item_type]"]').val(prevType);
            $newRow.find('select[name$="[condition_grade]"]').val(prevGrade);
            $tbody.append($newRow);
        });

        $(document).on('click', '.remove-line', function () {
            if ($('#offer_lines_table tbody tr').length > 1) {
                $(this).closest('tr').remove();
            }
        });

        @if(!empty($calc))
        (function signaturePad() {
            var canvas = document.getElementById('buy_signature_canvas');
            if (!canvas || !canvas.getContext) return;
            var ctx = canvas.getContext('2d');
            var drawing = false;
            var hasSignature = false; // tracks whether the user actually drew anything
            // Override-reason hint: kept for parity with the controller validation,
            // but with read-only offer fields the submitted final always matches the
            // calculator's auto-final, so this stays hidden in the normal flow.
            $('#override_required_label').hide();

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
