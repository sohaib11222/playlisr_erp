@extends('layouts.app')

@section('title', 'Log an AMS Order')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('ams_order._form_styles')

<div class="ams-wrap">
    <h1>Log an AMS Order</h1>
    <p class="sub">Record what you sent AMS so the team can see what's coming. Paste the list exactly how you sent it.</p>

    @if(!empty($openOrders))
    <div class="ams-card ams-warnbox">
        <h2><i class="fa fa-exclamation-triangle"></i> Already on order — check before you re-order</h2>
        <ul class="ams-openlist">
            @foreach($openOrders as $o)
                <li>
                    <strong>{{ $o['store'] ?: 'AMS Order' }}</strong>
                    <span class="muted">· ordered {{ !empty($o['ordered_date']) ? \Carbon\Carbon::parse($o['ordered_date'])->format('n/j/y') : '-' }}</span>
                    @php $snip = trim(preg_replace('/\s+/', ' ', (string) ($o['items'] ?? ''))); @endphp
                    @if($snip !== '')<div class="muted snip">{{ \Illuminate\Support\Str::limit($snip, 160) }}</div>@endif
                </li>
            @endforeach
        </ul>
    </div>
    @endif

    {!! Form::open(['action' => 'AmsOrderController@store', 'method' => 'post', 'id' => 'ams_form']) !!}

    <div class="ams-card">
        <h2><i class="fa fa-truck"></i> Order Details</h2>
        <div class="ams-row">
            <div class="ams-field">
                {!! Form::label('store', 'Store / destination *') !!}
                {!! Form::text('store', null, ['class' => 'form-control', 'list' => 'store_options', 'required', 'placeholder' => 'e.g. Hollywood, Pico, Multiple stores']) !!}
                <datalist id="store_options">
                    @foreach($locations as $id => $name)
                        <option value="{{ $name }}">
                    @endforeach
                    <option value="Multiple stores">
                </datalist>
            </div>
            <div class="ams-field">
                {!! Form::label('ordered_date', 'Date ordered *') !!}
                {!! Form::text('ordered_date', \Carbon\Carbon::now()->format('Y-m-d'), ['class' => 'form-control date-picker', 'required']) !!}
            </div>
            <div class="ams-field">
                {!! Form::label('expected_date', 'Expected arrival') !!}
                {!! Form::text('expected_date', null, ['class' => 'form-control date-picker', 'placeholder' => 'Optional']) !!}
            </div>
            <div class="ams-field">
                {!! Form::label('ams_ref', 'AMS order / ref #') !!}
                {!! Form::text('ams_ref', null, ['class' => 'form-control', 'maxlength' => 120, 'placeholder' => 'Optional']) !!}
            </div>
        </div>
    </div>

    <div class="ams-card">
        <h2><i class="fa fa-list"></i> What we ordered *</h2>
        {!! Form::textarea('items', null, ['class' => 'form-control', 'rows' => 12, 'required', 'placeholder' => "Paste the list exactly how you sent it to AMS, e.g.\n\nAriana Grande – new album – 2 vinyl + 2 CD\nBowie – Blackstar / Heathen / Reality – 1 each (vinyl)\nThe Smiths – 2 Best-of comps – 1 each (vinyl)"]) !!}
        <small class="help-block">One title per line is easiest to read later, but anything works — this is just for our own reference.</small>
    </div>

    <div class="ams-card">
        <h2><i class="fa fa-sticky-note"></i> Notes</h2>
        {!! Form::textarea('notes', null, ['class' => 'form-control', 'rows' => 2, 'placeholder' => 'e.g. test order on Fleetwood Mac, hold title confirmed, etc.']) !!}
    </div>

    <div class="ams-actions">
        <a href="{{ action('AmsOrderController@index') }}" class="btn-ghost">Cancel</a>
        <button type="submit" class="btn-accent">Save AMS Order</button>
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
