@extends('layouts.app')

@section('title', 'Edit Gift Card')

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>Edit Gift Card</h1>
</section>

<!-- Main content -->
<section class="content">
    {!! Form::open(['action' => ['GiftCardController@update', $gift_card->id], 'method' => 'put', 'id' => 'gift_card_form']) !!}
    <div class="row">
        <div class="col-md-12">
            <div class="box box-solid">
                <div class="box-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('card_number', 'Card Number:') !!}
                                {!! Form::text('card_number', $gift_card->card_number, ['class' => 'form-control', 'required']); !!}
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('contact_id', 'Customer:') !!}
                                {!! Form::select('contact_id', $customers, $gift_card->contact_id, ['class' => 'form-control select2', 'placeholder' => 'Optional']); !!}
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('balance', 'Balance:') !!}
                                {!! Form::text('balance', $gift_card->balance, ['class' => 'form-control input_number', 'required']); !!}
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('expiry_date', 'Expiry Date:') !!}
                                {!! Form::text('expiry_date', $gift_card->expiry_date ? date('Y-m-d', strtotime($gift_card->expiry_date)) : null, ['class' => 'form-control date-picker', 'placeholder' => 'Optional']); !!}
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('status', 'Status:') !!}
                                {!! Form::select('status', ['active' => 'Active', 'expired' => 'Expired', 'used' => 'Used', 'cancelled' => 'Cancelled'], $gift_card->status, ['class' => 'form-control', 'required']); !!}
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                {!! Form::label('notes', 'Notes:') !!}
                                {!! Form::textarea('notes', $gift_card->notes, ['class' => 'form-control', 'rows' => 3]); !!}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            <button type="submit" class="btn btn-primary pull-right">Update</button>
        </div>
    </div>
    {!! Form::close() !!}

</section>
<!-- /.content -->

@stop
@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        $('.date-picker').datepicker({
            autoclose: true,
            format: datepicker_date_format
        });
    });
</script>
@endsection

