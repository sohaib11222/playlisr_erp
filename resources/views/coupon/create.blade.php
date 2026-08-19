@extends('layouts.app')

@section('title', 'Add Coupon')

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>Add Coupon</h1>
</section>

<!-- Main content -->
<section class="content">
    {!! Form::open(['action' => 'CouponController@store', 'method' => 'post', 'id' => 'coupon_form']) !!}
    <div class="row">
        <div class="col-md-12">
            <div class="box box-solid">
                <div class="box-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('code', 'Code:') !!}
                                {!! Form::text('code', null, ['class' => 'form-control', 'placeholder' => 'e.g. FALL10', 'required', 'style' => 'text-transform: uppercase;']); !!}
                                <small class="help-block">Not case-sensitive — always stored uppercase.</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('type', 'Type:') !!}
                                {!! Form::select('type', ['percent' => 'Percent off', 'fixed' => 'Fixed amount off'], 'percent', ['class' => 'form-control', 'required']); !!}
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('value', 'Value:') !!}
                                {!! Form::text('value', null, ['class' => 'form-control input_number', 'placeholder' => 'e.g. 10 for 10% or $10', 'required']); !!}
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('min_order_amount', 'Minimum Order Amount:') !!}
                                {!! Form::text('min_order_amount', null, ['class' => 'form-control input_number', 'placeholder' => 'Optional']); !!}
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('usage_limit', 'Usage Limit:') !!}
                                {!! Form::text('usage_limit', null, ['class' => 'form-control input_number', 'placeholder' => 'Leave empty for unlimited']); !!}
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
