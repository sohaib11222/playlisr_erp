@extends('layouts.app')

@section('title', 'Add Gift Card')

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>Add Gift Card</h1>
</section>

<!-- Main content -->
<section class="content">
    {!! Form::open(['action' => 'GiftCardController@store', 'method' => 'post', 'id' => 'gift_card_form']) !!}
    <div class="row">
        <div class="col-md-12">
            <div class="box box-solid">
                <div class="box-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('card_number', 'Card Number:') !!}
                                {!! Form::text('card_number', null, ['class' => 'form-control', 'placeholder' => 'Leave empty to auto-generate']); !!}
                                <small class="help-block">Leave empty to auto-generate</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('contact_id', 'Customer:') !!}
                                {!! Form::select('contact_id', $customers, null, ['class' => 'form-control select2', 'placeholder' => 'Optional']); !!}
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('initial_value', 'Initial Value:') !!}
                                {!! Form::text('initial_value', null, ['class' => 'form-control input_number', 'required']); !!}
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('expiry_date', 'Expiry Date:') !!}
                                {!! Form::text('expiry_date', null, ['class' => 'form-control date-picker', 'placeholder' => 'Optional']); !!}
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                {!! Form::label('notes', 'Notes:') !!}
                                {!! Form::textarea('notes', null, ['class' => 'form-control', 'rows' => 3]); !!}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <div class="col-md-12">
            <button type="submit" class="btn btn-primary pull-right">Save</button>
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

