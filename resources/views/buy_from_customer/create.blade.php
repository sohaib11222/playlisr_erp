@extends('layouts.app')
@section('title', 'Buy from Customer Calculator')

@section('content')
<section class="content-header">
    <h1>Buy from Customer <small>Offer calculator</small></h1>
</section>

<section class="content">
    @if(session('status'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">
            {{ session('status.msg') }}
        </div>
    @endif

    @php
        $input = $input_data ?? old();
        $input = is_array($input) ? $input : [];
        $calc = $calculation ?? null;
    @endphp

    <div class="row">
        <div class="col-md-12">
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
                                <label>Store Location</label>
                                {!! Form::select('location_id', $locations, $input['location_id'] ?? null, ['class' => 'form-control select2', 'style' => 'width:100%;']) !!}
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Seller Mode</label>
                                {!! Form::select('seller_mode', ['contact' => 'Existing Account', 'phone' => 'Phone First'], $input['seller_mode'] ?? 'phone', ['class' => 'form-control', 'id' => 'seller_mode']) !!}
                            </div>
                        </div>
                        <div class="col-md-3 seller-contact-block">
                            <div class="form-group">
                                <label>Existing Contact</label>
                                {!! Form::select('contact_id', $contacts, $input['contact_id'] ?? null, ['class' => 'form-control select2', 'style' => 'width:100%;']) !!}
                            </div>
                        </div>
                        <div class="col-md-3 seller-phone-block">
                            <div class="form-group">
                                <label>Phone</label>
                                {!! Form::text('seller_phone', $input['seller_phone'] ?? null, ['class' => 'form-control', 'placeholder' => 'Phone number']) !!}
                            </div>
                        </div>
                        <div class="col-md-3 seller-phone-block">
                            <div class="form-group">
                                <label>Seller Name (optional)</label>
                                {!! Form::text('seller_name', $input['seller_name'] ?? null, ['class' => 'form-control']) !!}
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label>Payout Type</label>
                                {!! Form::select('payout_type', ['cash' => 'Cash Offer', 'store_credit' => 'Store Credit Offer'], $input['payout_type'] ?? 'cash', ['class' => 'form-control']) !!}
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
                    <button type="button" class="btn btn-default btn-sm" id="add_line_btn"><i class="fa fa-plus"></i> Add Line</button>

                    <hr>
                    <h4>Negotiation offers</h4>
                    <div class="row">
                        <div class="col-md-3"><label>Starting Cash</label>{!! Form::number('starting_offer_cash', $input['starting_offer_cash'] ?? null, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
                        <div class="col-md-3"><label>Starting Credit</label>{!! Form::number('starting_offer_credit', $input['starting_offer_credit'] ?? null, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
                        <div class="col-md-3"><label>2nd Cash</label>{!! Form::number('second_offer_cash', $input['second_offer_cash'] ?? null, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
                        <div class="col-md-3"><label>2nd Credit</label>{!! Form::number('second_offer_credit', $input['second_offer_credit'] ?? null, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
                    </div>
                    <div class="row" style="margin-top:8px;">
                        <div class="col-md-3"><label>Final Cash</label>{!! Form::number('final_offer_cash', $input['final_offer_cash'] ?? null, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
                        <div class="col-md-3"><label>Final Credit</label>{!! Form::number('final_offer_credit', $input['final_offer_credit'] ?? null, ['class' => 'form-control', 'step' => '0.01']) !!}</div>
                        <div class="col-md-6"><label>Notes</label>{!! Form::text('notes', $input['notes'] ?? null, ['class' => 'form-control']) !!}</div>
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
        <div class="row">
            <div class="col-md-12">
                <div class="box box-success">
                    <div class="box-header with-border"><h3 class="box-title">Calculated Offer</h3></div>
                    <div class="box-body">
                        <div class="row">
                            <div class="col-md-3"><strong>Calculated Cash Total:</strong> @format_currency(data_get($calc, 'calculated_cash_total', 0))</div>
                            <div class="col-md-3"><strong>Calculated Credit Total:</strong> @format_currency(data_get($calc, 'calculated_credit_total', 0))</div>
                            <div class="col-md-3"><strong>Final Cash:</strong> @format_currency(data_get($calc, 'final_offer_cash', 0))</div>
                            <div class="col-md-3"><strong>Final Credit:</strong> @format_currency(data_get($calc, 'final_offer_credit', 0))</div>
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
                                <button type="submit" class="btn btn-default"><i class="fa fa-save"></i> Save Draft</button>
                                {!! Form::close() !!}

                                {!! Form::open(['url' => route('buy-from-customer.accept'), 'method' => 'post', 'style' => 'display:inline-block; margin-left:6px;']) !!}
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
                                <button type="submit" class="btn btn-success"><i class="fa fa-check"></i> Accept Offer (Create Purchase)</button>
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
                                <button type="submit" class="btn btn-danger"><i class="fa fa-times"></i> Mark Rejected</button>
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
    })();
</script>
@endsection

