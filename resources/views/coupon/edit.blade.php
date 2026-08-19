@extends('layouts.app')

@section('title', 'Edit Coupon')

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>Edit Coupon</h1>
</section>

<!-- Main content -->
<section class="content">
    {!! Form::open(['action' => ['CouponController@update', $coupon->id], 'method' => 'put', 'id' => 'coupon_form']) !!}
    <div class="row">
        <div class="col-md-12">
            <div class="box box-solid">
                <div class="box-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('code', 'Code:') !!}
                                {!! Form::text('code', $coupon->code, ['class' => 'form-control', 'required', 'style' => 'text-transform: uppercase;']); !!}
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('type', 'Type:') !!}
                                {!! Form::select('type', ['percent' => 'Percent off', 'fixed' => 'Fixed amount off'], $coupon->type, ['class' => 'form-control', 'required']); !!}
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('value', 'Value:') !!}
                                {!! Form::text('value', $coupon->value, ['class' => 'form-control input_number', 'required']); !!}
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('min_order_amount', 'Minimum Order Amount:') !!}
                                {!! Form::text('min_order_amount', $coupon->min_order_amount, ['class' => 'form-control input_number', 'placeholder' => 'Optional']); !!}
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('usage_limit', 'Usage Limit:') !!}
                                {!! Form::text('usage_limit', $coupon->usage_limit, ['class' => 'form-control input_number', 'placeholder' => 'Leave empty for unlimited']); !!}
                                <small class="help-block">Used {{ $coupon->times_used }} time(s) so far.</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('expiry_date', 'Expiry Date:') !!}
                                {!! Form::text('expiry_date', $coupon->expiry_date ? date('Y-m-d', strtotime($coupon->expiry_date)) : null, ['class' => 'form-control date-picker', 'placeholder' => 'Optional']); !!}
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('status', 'Status:') !!}
                                {!! Form::select('status', ['active' => 'Active', 'inactive' => 'Inactive'], $coupon->status, ['class' => 'form-control', 'required']); !!}
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                {!! Form::label('notes', 'Notes:') !!}
                                {!! Form::textarea('notes', $coupon->notes, ['class' => 'form-control', 'rows' => 3]); !!}
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
