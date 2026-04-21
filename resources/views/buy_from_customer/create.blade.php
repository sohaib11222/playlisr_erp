@extends('layouts.app')
@section('title', 'Buy from Customer Calculator')

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
<section class="content-header">
    <h1>Buy from Customer <small>Offer calculator</small></h1>
</section>

<section class="content">
    @if(session('status'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">
            {{ session('status.msg') }}
            @if(session('saved_offer_id'))
                <br><strong>Buy record:</strong> BFC-{{ str_pad((string) session('saved_offer_id'), 6, '0', STR_PAD_LEFT) }}
            @endif
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
    @endphp

    <div class="row">
        <div class="col-md-12">
            <div class="box box-default">
                <div class="box-body">
                    <div class="row text-muted small">
                        <div class="col-sm-4"><strong>Server date &amp; time:</strong> {{ @format_datetime(\Carbon\Carbon::now()) }}</div>
                        <div class="col-sm-4"><strong>Employee:</strong> {{ auth()->user()->user_full_name ?? auth()->user()->username ?? '—' }}</div>
                        <div class="col-sm-4"><strong>Buy record #:</strong> @if(session('saved_offer_id')) BFC-{{ str_pad((string) session('saved_offer_id'), 6, '0', STR_PAD_LEFT) }} @else Assigned when you save a draft or accept @endif</div>
                    </div>
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
                    <div class="row seller-phone-block">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label>Legacy single name <span class="text-muted">(optional if first/last used)</span></label>
                                {!! Form::text('seller_name', $input['seller_name'] ?? null, ['class' => 'form-control']) !!}
                            </div>
                        </div>
                    </div>
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
                                    $lines = $input['lines'] ?? [['item_type' => 'individual_vinyl', 'quantity' => 1, 'condition_grade' => 'VG+', 'standard_multiplier' => 0.10]];
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
                    <h4>Negotiation offers</h4>
                    <div class="row">
                        <div class="col-md-3"><label>Starting cash</label>{!! Form::number('starting_offer_cash', $input['starting_offer_cash'] ?? null, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
                        <div class="col-md-3"><label>Starting credit</label>{!! Form::number('starting_offer_credit', $input['starting_offer_credit'] ?? null, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
                        <div class="col-md-3"><label>2nd cash</label>{!! Form::number('second_offer_cash', $input['second_offer_cash'] ?? null, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
                        <div class="col-md-3"><label>2nd credit</label>{!! Form::number('second_offer_credit', $input['second_offer_credit'] ?? null, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
                    </div>
                    <div class="row" style="margin-top:8px;">
                        <div class="col-md-3"><label>Final cash</label>{!! Form::number('final_offer_cash', $input['final_offer_cash'] ?? null, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
                        <div class="col-md-3"><label>Final credit</label>{!! Form::number('final_offer_credit', $input['final_offer_credit'] ?? null, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
                        <div class="col-md-6"><label>Notes <span class="text-muted">(sealed items, rare finds, condition concerns)</span></label>{!! Form::textarea('notes', $input['notes'] ?? null, ['class' => 'form-control', 'rows' => 2]) !!}</div>
                    </div>

                    <div class="text-right" style="margin-top:15px;">
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

                                <div class="well" style="margin-top:15px; max-width:920px;">
                                    <h4>Final price &amp; override</h4>
                                    <p class="text-muted small">If final paid differs from calculator suggested total for the selected payment method, explain briefly.</p>
                                    <div class="form-group">
                                        <label>Override reason <span id="override_required_label" class="text-danger" style="display:none;">(required)</span></label>
                                        <textarea name="price_override_reason" class="form-control" rows="2" placeholder="e.g. Manager approved bump for sealed box set">{{ $input['price_override_reason'] ?? '' }}</textarea>
                                    </div>

                                    <h4>Compliance <small class="text-danger">required to accept</small></h4>
                                    <div class="checkbox">
                                        <label>
                                            <input type="checkbox" name="compliance_items_owned" value="1"> Seller confirms the items are legally theirs and not stolen.
                                        </label>
                                    </div>
                                    <div class="checkbox">
                                        <label>
                                            <input type="checkbox" name="compliance_sales_final" value="1"> Seller acknowledges all sales are final.
                                        </label>
                                    </div>
                                    <p class="help-block">Seller signs below to acknowledge the statements above.</p>
                                    <div class="form-group">
                                        <label>Signature</label>
                                        <div style="border:1px solid #ccc; background:#fafafa; display:inline-block;">
                                            <canvas id="buy_signature_canvas" width="700" height="180" style="max-width:100%; height:auto; touch-action:none;"></canvas>
                                        </div>
                                        <div style="margin-top:6px;">
                                            <button type="button" class="btn btn-default btn-sm" id="buy_signature_clear"><i class="fa fa-eraser"></i> Clear signature</button>
                                        </div>
                                        <input type="hidden" name="seller_signature_data" id="buy_signature_input" value="">
                                    </div>
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

        @if(!empty($calc))
        (function signaturePad() {
            var canvas = document.getElementById('buy_signature_canvas');
            if (!canvas || !canvas.getContext) return;
            var ctx = canvas.getContext('2d');
            var drawing = false;
            var pm = @json($pmVal);
            var suggestedCash = {{ (float) data_get($calc, 'calculated_cash_total', 0) }};
            var suggestedCredit = {{ (float) data_get($calc, 'calculated_credit_total', 0) }};
            var finalCash = {{ (float) data_get($calc, 'final_offer_cash', 0) }};
            var finalCredit = {{ (float) data_get($calc, 'final_offer_credit', 0) }};

            function refreshOverrideHint() {
                var sug = pm === 'store_credit' ? suggestedCredit : suggestedCash;
                var fin = pm === 'store_credit' ? finalCredit : finalCash;
                var diff = Math.abs(fin - sug) > 0.009;
                $('#override_required_label').toggle(diff);
            }
            refreshOverrideHint();

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
                ctx.beginPath();
                var p = pos(e);
                ctx.moveTo(p.x, p.y);
                e.preventDefault();
            }
            function move(e) {
                if (!drawing) return;
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
            });

            $('#accept_buy_offer_form').on('submit', function () {
                try {
                    $('#buy_signature_input').val(canvas.toDataURL('image/png'));
                } catch (err) {
                    $('#buy_signature_input').val('');
                }
            });
        })();
        @endif
    })();
</script>
@endsection
