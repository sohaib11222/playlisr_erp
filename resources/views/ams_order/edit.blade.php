@extends('layouts.app')

@section('title', 'Edit AMS Order')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('ams_order._form_styles')

<div class="ams-wrap">
    <h1>Edit AMS Order</h1>
    <p class="sub">{{ $order['store'] ?: 'AMS Order' }} · ordered {{ !empty($order['ordered_date']) ? \Carbon\Carbon::parse($order['ordered_date'])->format('n/j/y') : '-' }}</p>

    {!! Form::open(['action' => ['AmsOrderController@update', $order['id']], 'method' => 'put', 'id' => 'ams_form']) !!}

    <div class="ams-card">
        <h2><i class="fa fa-truck"></i> Order Details</h2>
        <div class="ams-row">
            <div class="ams-field">
                {!! Form::label('store', 'Store / destination *') !!}
                {!! Form::text('store', $order['store'] ?? '', ['class' => 'form-control', 'list' => 'store_options', 'required']) !!}
                <datalist id="store_options">
                    @foreach($locations as $id => $name)
                        <option value="{{ $name }}">
                    @endforeach
                    <option value="Multiple stores">
                </datalist>
            </div>
            <div class="ams-field">
                {!! Form::label('ordered_date', 'Date ordered *') !!}
                {!! Form::text('ordered_date', !empty($order['ordered_date']) ? \Carbon\Carbon::parse($order['ordered_date'])->format('Y-m-d') : null, ['class' => 'form-control date-picker', 'required']) !!}
            </div>
            <div class="ams-field">
                {!! Form::label('expected_date', 'Expected arrival') !!}
                {!! Form::text('expected_date', !empty($order['expected_date']) ? \Carbon\Carbon::parse($order['expected_date'])->format('Y-m-d') : null, ['class' => 'form-control date-picker', 'placeholder' => 'Optional']) !!}
            </div>
            <div class="ams-field">
                {!! Form::label('ams_ref', 'AMS order / ref #') !!}
                {!! Form::text('ams_ref', $order['ams_ref'] ?? null, ['class' => 'form-control', 'maxlength' => 120, 'placeholder' => 'Optional']) !!}
            </div>
        </div>
        <div class="ams-statusrow" style="margin-top: 8px;">
            {!! Form::label('status', 'Status', ['style' => 'margin:0 4px 0 0;']) !!}
            {!! Form::select('status', $statuses, $order['status'] ?? 'placed', ['class' => '']) !!}
        </div>
    </div>

    <div class="ams-card">
        <h2><i class="fa fa-list"></i> What we ordered *</h2>
        {!! Form::textarea('items', $order['items'] ?? '', ['class' => 'form-control', 'rows' => 12, 'required']) !!}
    </div>

    <div class="ams-card">
        <h2><i class="fa fa-sticky-note"></i> Notes</h2>
        {!! Form::textarea('notes', $order['notes'] ?? null, ['class' => 'form-control', 'rows' => 2]) !!}
    </div>

    <div class="ams-actions">
        <a href="{{ action('AmsOrderController@index') }}" class="btn-ghost">Cancel</a>
        <button type="submit" class="btn-accent">Save Changes</button>
    </div>

    {!! Form::close() !!}
</div>

@stop
@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        $('.date-picker').datepicker({ autoclose: true, format: datepicker_date_format });
    });
</script>
@stop
