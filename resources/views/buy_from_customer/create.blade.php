@extends('layouts.app')
@section('title', 'Buy from Customer Calculator')

@php
    $is_embed = request()->get('embed') == '1';
    $input = $input_data ?? old();
    $input = is_array($input) ? $input : [];
    $calc = $calculation ?? null;
    // Buy record number — not persisted until save, but show a preview so
    // the cashier can reference it on the paper receipt they're filling in.
    // Format: BUY-YYYYMMDD-HHMMSS (no collision risk for the shift).
    $buy_record_preview = 'BUY-' . now()->format('Ymd-His');
@endphp

@if($is_embed)
@section('css')
    {{-- When opened inside the POS modal iframe, hide the admin chrome so only the calculator shows. --}}
    <style>
        body, body.skin-blue, body.hold-transition { background: #fff !important; padding-top: 0 !important; }
        .main-header, .main-sidebar, .main-footer, .content-header > h1 > small, .left-side { display: none !important; }
        .content-wrapper { margin-left: 0 !important; min-height: auto !important; padding-top: 0 !important; }
        .content-header { padding: 10px 15px 0 !important; }
        section.content { padding: 10px 15px !important; }
        .wrapper { min-height: auto !important; }
    </style>
@stop
@endif

@section('content')
<style>
    /* ============================================================
       Buy Calculator Phase 1 intake form styles
       Scoped to .bc-v1 so the rest of the ERP is unaffected.
       ============================================================ */
    .bc-v1 { font-family: "Inter Tight", system-ui, sans-serif; }
    .bc-v1 .bc-step {
        background: #fff; border: 1px solid #ECE3CF; border-radius: 10px;
        padding: 18px 22px; margin-bottom: 14px;
        box-shadow: 0 1px 2px rgba(31,27,22,.06);
    }
    .bc-v1 .bc-step-head {
        display: flex; align-items: baseline; gap: 10px; margin-bottom: 14px;
        border-bottom: 1px dashed #DFD2B3; padding-bottom: 10px;
    }
    .bc-v1 .bc-step-num {
        background: #1F1B16; color: #FFF2B3;
        font-size: 11px; font-weight: 800; letter-spacing: .14em;
        padding: 4px 10px; border-radius: 999px;
    }
    .bc-v1 .bc-step-title {
        font-size: 17px; font-weight: 700; color: #1F1B16; margin: 0;
    }
    .bc-v1 .bc-step-hint {
        font-size: 12px; color: #8E8273; margin-left: auto;
    }
    .bc-v1 .bc-field label {
        font-size: 11px; font-weight: 700; letter-spacing: .06em;
        text-transform: uppercase; color: #5A5045; margin-bottom: 4px;
    }
    .bc-v1 .bc-field .form-control,
    .bc-v1 .bc-field select,
    .bc-v1 .bc-field input[type="text"],
    .bc-v1 .bc-field input[type="email"],
    .bc-v1 .bc-field input[type="number"] {
        border: 1px solid #DFD2B3; border-radius: 8px;
        font-family: inherit; font-size: 14px;
    }
    .bc-v1 .bc-auto-pill {
        display: inline-block; padding: 5px 10px;
        background: #F7F1E3; border: 1px solid #ECE3CF;
        border-radius: 999px; font-size: 12px; font-weight: 600;
        color: #5A5045; margin-right: 6px; margin-bottom: 4px;
    }
    .bc-v1 .bc-auto-pill .lbl { color: #8E8273; font-weight: 500; margin-right: 4px; }
    .bc-v1 .bc-count-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
        gap: 10px;
    }
    .bc-v1 .bc-count-cell {
        background: #F7F1E3; border: 1px solid #ECE3CF; border-radius: 8px;
        padding: 10px 12px; text-align: center;
    }
    .bc-v1 .bc-count-cell label {
        font-size: 11px; font-weight: 700; letter-spacing: .06em;
        text-transform: uppercase; color: #5A5045; display: block;
        margin-bottom: 6px;
    }
    .bc-v1 .bc-count-cell input {
        width: 100%; border: 1px solid #DFD2B3; border-radius: 6px;
        padding: 6px 8px; font-size: 16px; font-weight: 700;
        text-align: center; background: #fff;
    }
    .bc-v1 .bc-compliance {
        background: #FFF9DB; border: 2px solid #E8CF68; border-radius: 10px;
        padding: 16px 20px; margin-top: 14px;
    }
    .bc-v1 .bc-compliance .bc-step-title { color: #5A4410; }
    .bc-v1 .bc-compliance label {
        display: flex; align-items: flex-start; gap: 10px;
        font-size: 14px; color: #1F1B16; font-weight: 500;
        padding: 8px 0; cursor: pointer;
    }
    .bc-v1 .bc-compliance input[type="checkbox"] {
        margin-top: 3px; transform: scale(1.3);
    }
    .bc-v1 .bc-submit-row {
        display: flex; gap: 10px; justify-content: flex-end;
        padding-top: 14px; border-top: 1px dashed #DFD2B3; margin-top: 14px;
    }
    .bc-v1 .bc-btn-primary {
        background: #1F1B16; color: #FAF6EE;
        border: none; border-radius: 8px;
        padding: 10px 20px; font-weight: 700; font-size: 14px;
    }
    .bc-v1 .bc-btn-primary:hover { background: #3a2e22; }
    .bc-v1 .bc-btn-success {
        background: #2F6B3E; color: #fff;
        border: none; border-radius: 8px;
        padding: 10px 20px; font-weight: 700; font-size: 14px;
    }
    .bc-v1 .bc-btn-ghost {
        background: #fff; color: #5A5045;
        border: 1px solid #DFD2B3; border-radius: 8px;
        padding: 10px 20px; font-weight: 600; font-size: 14px;
    }
</style>

<section class="content-header">
    <h1 style="font-family: Inter Tight, system-ui, sans-serif; font-weight:800;">Buy from Customer <small>Collection intake + offer</small></h1>
</section>

<section class="content bc-v1">
    @if(session('status'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">{{ session('status.msg') }}</div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger">
            <strong>Fix these first:</strong>
            <ul style="margin:4px 0 0 18px;">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form id="buy_offer_form" method="POST" action="{{ route('buy-from-customer.calculate') }}">
        @csrf

        {{-- ===== STEP 1: Transaction details (auto) ===== --}}
        <div class="bc-step">
            <div class="bc-step-head">
                <span class="bc-step-num">1 · AUTO</span>
                <h3 class="bc-step-title">Transaction details</h3>
                <span class="bc-step-hint">auto-filled, just sanity-check</span>
            </div>
            <div>
                <span class="bc-auto-pill"><span class="lbl">Date/time:</span>{{ now()->format('M j, Y · g:i A') }}</span>
                <span class="bc-auto-pill"><span class="lbl">Employee:</span>{{ auth()->user()->first_name }} {{ auth()->user()->last_name }}</span>
                <span class="bc-auto-pill"><span class="lbl">Buy #:</span>{{ $buy_record_preview }}</span>
            </div>
            <div class="row" style="margin-top:10px;">
                <div class="col-md-4 bc-field">
                    <label>Store Location *</label>
                    {!! Form::select('location_id', $locations, $input['location_id'] ?? null, ['class' => 'form-control select2', 'style' => 'width:100%;', 'required' => true]) !!}
                </div>
            </div>
        </div>

        {{-- ===== STEP 2: Seller details (FIRST, per Sarah's ask) ===== --}}
        <div class="bc-step">
            <div class="bc-step-head">
                <span class="bc-step-num">2 · SELLER</span>
                <h3 class="bc-step-title">Seller details</h3>
                <span class="bc-step-hint">collect this before you start pricing</span>
            </div>
            <div class="row">
                <div class="col-md-3 bc-field">
                    <label>First Name *</label>
                    {!! Form::text('seller_first_name', $input['seller_first_name'] ?? null, ['class' => 'form-control', 'required' => true]) !!}
                </div>
                <div class="col-md-3 bc-field">
                    <label>Last Name *</label>
                    {!! Form::text('seller_last_name', $input['seller_last_name'] ?? null, ['class' => 'form-control', 'required' => true]) !!}
                </div>
                <div class="col-md-3 bc-field">
                    <label>Phone *</label>
                    {!! Form::text('seller_phone', $input['seller_phone'] ?? null, ['class' => 'form-control', 'required' => true, 'placeholder' => '(555) 123-4567']) !!}
                </div>
                <div class="col-md-3 bc-field">
                    <label>Email *</label>
                    {!! Form::email('seller_email', $input['seller_email'] ?? null, ['class' => 'form-control', 'required' => true]) !!}
                </div>
            </div>
            <div class="row" style="margin-top:8px;">
                <div class="col-md-3 bc-field">
                    <label>ID type (optional)</label>
                    {!! Form::select('seller_id_type', ['' => '— not collected —'] + $idTypes, $input['seller_id_type'] ?? null, ['class' => 'form-control']) !!}
                </div>
                <div class="col-md-3 bc-field">
                    <label>Last 4 of ID # (optional)</label>
                    {!! Form::text('seller_id_last4', $input['seller_id_last4'] ?? null, ['class' => 'form-control', 'maxlength' => 4, 'placeholder' => 'e.g. 4829']) !!}
                </div>
            </div>
            {{-- Existing-contact lookup retained, but demoted below intake. --}}
            <input type="hidden" name="seller_mode" value="phone">
            <input type="hidden" name="contact_id" value="{{ $input['contact_id'] ?? '' }}">
        </div>

        {{-- ===== STEP 3: Collection breakdown (counts + condition + notes) ===== --}}
        <div class="bc-step">
            <div class="bc-step-head">
                <span class="bc-step-num">3 · COLLECTION</span>
                <h3 class="bc-step-title">Collection buy</h3>
                <span class="bc-step-hint">rough counts — just eyeball it</span>
            </div>

            <div style="margin-bottom:10px; font-size:12px; color:#5A5045; font-weight:600; letter-spacing:.04em; text-transform:uppercase;">Item count by type</div>
            <div class="bc-count-grid">
                <div class="bc-count-cell"><label># LPs</label>{!! Form::number('items_lp_count', $input['items_lp_count'] ?? 0, ['min' => 0, 'max' => 9999]) !!}</div>
                <div class="bc-count-cell"><label># 45s</label>{!! Form::number('items_45_count', $input['items_45_count'] ?? 0, ['min' => 0, 'max' => 9999]) !!}</div>
                <div class="bc-count-cell"><label># CDs</label>{!! Form::number('items_cd_count', $input['items_cd_count'] ?? 0, ['min' => 0, 'max' => 9999]) !!}</div>
                <div class="bc-count-cell"><label># Cassettes</label>{!! Form::number('items_cassette_count', $input['items_cassette_count'] ?? 0, ['min' => 0, 'max' => 9999]) !!}</div>
                <div class="bc-count-cell"><label># DVDs</label>{!! Form::number('items_dvd_count', $input['items_dvd_count'] ?? 0, ['min' => 0, 'max' => 9999]) !!}</div>
                <div class="bc-count-cell"><label># Blu-rays</label>{!! Form::number('items_bluray_count', $input['items_bluray_count'] ?? 0, ['min' => 0, 'max' => 9999]) !!}</div>
                <div class="bc-count-cell"><label># Other</label>{!! Form::number('items_other_count', $input['items_other_count'] ?? 0, ['min' => 0, 'max' => 9999]) !!}</div>
            </div>

            <div style="margin:16px 0 10px; font-size:12px; color:#5A5045; font-weight:600; letter-spacing:.04em; text-transform:uppercase;">Condition breakdown</div>
            <div class="bc-count-grid">
                <div class="bc-count-cell"><label>Mint / NM</label>{!! Form::number('condition_mint_nm_count', $input['condition_mint_nm_count'] ?? 0, ['min' => 0, 'max' => 9999]) !!}</div>
                <div class="bc-count-cell"><label>VG+ / VG</label>{!! Form::number('condition_vg_plus_count', $input['condition_vg_plus_count'] ?? 0, ['min' => 0, 'max' => 9999]) !!}</div>
                <div class="bc-count-cell"><label>G+ &amp; below</label>{!! Form::number('condition_g_below_count', $input['condition_g_below_count'] ?? 0, ['min' => 0, 'max' => 9999]) !!}</div>
            </div>

            <div class="row" style="margin-top:14px;">
                <div class="col-md-12 bc-field">
                    <label>Notes (sealed items, rare finds, condition concerns)</label>
                    {!! Form::textarea('notes', $input['notes'] ?? null, ['class' => 'form-control', 'rows' => 2]) !!}
                </div>
            </div>
        </div>

        {{-- ===== STEP 4: Line-item calculator (existing — runs the offer math) ===== --}}
        <div class="bc-step">
            <div class="bc-step-head">
                <span class="bc-step-num">4 · PRICE</span>
                <h3 class="bc-step-title">Line-by-line pricing</h3>
                <span class="bc-step-hint">individual items you're pricing from Discogs / condition</span>
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
                            <th>Discogs median</th>
                            <th>Standard mult.</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @php $lines = $input['lines'] ?? [['item_type' => 'individual_vinyl', 'quantity' => 1, 'condition_grade' => 'VG+', 'standard_multiplier' => 0.10]]; @endphp
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

            <hr style="margin:18px 0;">
            <h4 style="font-size:14px; font-weight:700; color:#5A5045; margin-bottom:10px;">Negotiation offers</h4>
            <div class="row">
                <div class="col-md-3 bc-field"><label>Starting Cash</label>{!! Form::number('starting_offer_cash', $input['starting_offer_cash'] ?? null, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
                <div class="col-md-3 bc-field"><label>Starting Credit</label>{!! Form::number('starting_offer_credit', $input['starting_offer_credit'] ?? null, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
                <div class="col-md-3 bc-field"><label>2nd Cash</label>{!! Form::number('second_offer_cash', $input['second_offer_cash'] ?? null, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
                <div class="col-md-3 bc-field"><label>2nd Credit</label>{!! Form::number('second_offer_credit', $input['second_offer_credit'] ?? null, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
            </div>
            <div class="row" style="margin-top:8px;">
                <div class="col-md-3 bc-field"><label>Final Cash</label>{!! Form::number('final_offer_cash', $input['final_offer_cash'] ?? null, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
                <div class="col-md-3 bc-field"><label>Final Credit</label>{!! Form::number('final_offer_credit', $input['final_offer_credit'] ?? null, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
            </div>
        </div>

        {{-- ===== STEP 5: Transaction close — payment method + final paid + override ===== --}}
        <div class="bc-step">
            <div class="bc-step-head">
                <span class="bc-step-num">5 · PAY</span>
                <h3 class="bc-step-title">Transaction</h3>
                <span class="bc-step-hint">how the seller is getting paid</span>
            </div>
            <div class="row">
                <div class="col-md-4 bc-field">
                    <label>Payment method *</label>
                    {!! Form::select('payment_method', ['' => '— select —'] + $paymentMethods, $input['payment_method'] ?? null, ['class' => 'form-control']) !!}
                </div>
                <div class="col-md-3 bc-field">
                    <label>Final price paid</label>
                    {!! Form::number('final_price_paid', $input['final_price_paid'] ?? null, ['class' => 'form-control', 'step' => '0.01']) !!}
                </div>
                <div class="col-md-5 bc-field">
                    <label>Override reason (if final ≠ calculator)</label>
                    {!! Form::text('override_reason', $input['override_reason'] ?? null, ['class' => 'form-control', 'placeholder' => 'e.g. VIP seller, sealed rarity, damaged set']) !!}
                </div>
            </div>
            {{-- Payout_type kept for backwards-compat with createPurchaseFromOffer() — derive from payment_method. --}}
            <input type="hidden" name="payout_type" id="hidden_payout_type" value="{{ $input['payout_type'] ?? 'cash' }}">
        </div>

        {{-- ===== STEP 6: Compliance checkboxes ===== --}}
        <div class="bc-step bc-compliance">
            <div class="bc-step-head">
                <span class="bc-step-num" style="background:#7A1F1F; color:#fff;">6 · COMPLIANCE</span>
                <h3 class="bc-step-title">Before you accept the offer</h3>
            </div>
            <label>
                <input type="checkbox" name="compliance_confirmed_ownership" value="1" {{ !empty($input['compliance_confirmed_ownership']) ? 'checked' : '' }}>
                <span><strong>Seller confirms items are legally theirs and not stolen.</strong> Ask the seller to confirm out loud before checking this box.</span>
            </label>
            <label>
                <input type="checkbox" name="compliance_ack_final_sale" value="1" {{ !empty($input['compliance_ack_final_sale']) ? 'checked' : '' }}>
                <span><strong>Seller acknowledges all sales are final.</strong> No refunds or returns on bought collections.</span>
            </label>
            <div style="margin-top:8px; font-size:11px; color:#5A4410;">
                Both boxes must be checked to accept the offer. They're optional while the offer is in draft, so you can calculate + negotiate first and tick them at the very end.
            </div>
        </div>

        <div class="bc-submit-row">
            <button type="submit" class="bc-btn-primary"><i class="fa fa-calculator"></i> Calculate offer</button>
        </div>
    </form>

    @if(!empty($calc))
        <div class="bc-step" style="background:linear-gradient(135deg,#FFF9DB,#FFF2B3); border-color:#E8CF68;">
            <div class="bc-step-head" style="border-bottom-color:#E8CF68;">
                <span class="bc-step-num">✓ RESULT</span>
                <h3 class="bc-step-title">Calculated offer</h3>
            </div>
            <div class="row">
                <div class="col-md-3"><strong>Calc. cash total:</strong><br>@format_currency(data_get($calc, 'calculated_cash_total', 0))</div>
                <div class="col-md-3"><strong>Calc. credit total:</strong><br>@format_currency(data_get($calc, 'calculated_credit_total', 0))</div>
                <div class="col-md-3"><strong>Final cash:</strong><br>@format_currency(data_get($calc, 'final_offer_cash', 0))</div>
                <div class="col-md-3"><strong>Final credit:</strong><br>@format_currency(data_get($calc, 'final_offer_credit', 0))</div>
            </div>

            <div class="bc-submit-row">
                {!! Form::open(['url' => route('buy-from-customer.store'), 'method' => 'post', 'style' => 'display:inline-block;']) !!}
                    @foreach($input as $k => $v)
                        @if($k === 'lines' && is_array($v))
                            @foreach($v as $li => $line)
                                @foreach($line as $lk => $lv)<input type="hidden" name="lines[{{$li}}][{{$lk}}]" value="{{ $lv }}">@endforeach
                            @endforeach
                        @elseif(!is_array($v))
                            <input type="hidden" name="{{$k}}" value="{{ $v }}">
                        @endif
                    @endforeach
                    <button type="submit" class="bc-btn-ghost"><i class="fa fa-save"></i> Save draft</button>
                {!! Form::close() !!}

                {!! Form::open(['url' => route('buy-from-customer.accept'), 'method' => 'post', 'style' => 'display:inline-block;']) !!}
                    @foreach($input as $k => $v)
                        @if($k === 'lines' && is_array($v))
                            @foreach($v as $li => $line)
                                @foreach($line as $lk => $lv)<input type="hidden" name="lines[{{$li}}][{{$lk}}]" value="{{ $lv }}">@endforeach
                            @endforeach
                        @elseif(!is_array($v))
                            <input type="hidden" name="{{$k}}" value="{{ $v }}">
                        @endif
                    @endforeach
                    <button type="submit" class="bc-btn-success"><i class="fa fa-check"></i> Accept offer (creates purchase)</button>
                {!! Form::close() !!}
            </div>
        </div>
    @endif
</section>
@endsection

@section('javascript')
<script>
    (function () {
        // Add/remove calculator lines — same handlers as before.
        $(document).on('click', '#add_line_btn', function () {
            var $tbody = $('#offer_lines_table tbody');
            var idx = $tbody.find('tr').length;
            var row = '<tr>'
                + '<td><select name="lines[' + idx + '][item_type]" class="form-control">@foreach($itemTypes as $k => $label)<option value="{{$k}}">{{ $label }}</option>@endforeach</select></td>'
                + '<td><input type="text" name="lines[' + idx + '][title]" class="form-control"></td>'
                + '<td><input type="text" name="lines[' + idx + '][genre]" class="form-control"></td>'
                + '<td><select name="lines[' + idx + '][condition_grade]" class="form-control">@foreach($grades as $g)<option value="{{$g}}">{{ $g }}</option>@endforeach</select></td>'
                + '<td><input type="number" step="0.01" min="0.01" name="lines[' + idx + '][quantity]" value="1" class="form-control"></td>'
                + '<td><input type="number" step="0.01" min="0" name="lines[' + idx + '][discogs_median_price]" class="form-control"></td>'
                + '<td><input type="number" step="0.01" min="0" name="lines[' + idx + '][standard_multiplier]" value="0.10" class="form-control"></td>'
                + '<td><button type="button" class="btn btn-danger btn-xs remove-line"><i class="fa fa-times"></i></button></td>'
                + '</tr>';
            $tbody.append(row);
        });

        $(document).on('click', '.remove-line', function () {
            if ($('#offer_lines_table tbody tr').length > 1) {
                $(this).closest('tr').remove();
            }
        });

        // Keep hidden payout_type in sync with payment_method — the existing
        // createPurchaseFromOffer() downstream only understands 'cash' or
        // 'store_credit', so collapse the four payment methods down to those
        // two for backwards compat. zelle_jon / venmo_jon still count as
        // "cash" from the books' perspective.
        $(document).on('change', 'select[name="payment_method"]', function () {
            var v = $(this).val();
            var mapped = (v === 'store_credit') ? 'store_credit' : 'cash';
            $('#hidden_payout_type').val(mapped);
        });
    })();
</script>
@endsection
