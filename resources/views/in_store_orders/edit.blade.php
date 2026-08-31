@extends('layouts.app')

@section('title', 'Edit In Store Order')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

<style>
body.pos-v2 .pickup-wrap { max-width: 760px; margin: 0 auto; padding: 18px 16px 60px; font-family: "Inter Tight", system-ui, sans-serif; color: var(--pos-ink); }
body.pos-v2 .pickup-wrap h1 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
body.pos-v2 .pickup-wrap .sub { color: #6b6253; margin: 0 0 20px; font-size: 14px; }
body.pos-v2 .pickup-card { background: var(--pos-surface); border: 1px solid var(--pos-line); border-radius: 14px; padding: 18px 20px; margin-bottom: 20px; }
body.pos-v2 .pickup-card h2 { font-size: 16px; font-weight: 700; margin: 0 0 14px; display: flex; align-items: center; gap: 8px; }
body.pos-v2 .pickup-card h2 .fa { color: var(--pos-accent-deep); }
body.pos-v2 .pickup-row { display: flex; flex-wrap: wrap; gap: 16px; }
body.pos-v2 .pickup-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 4px; flex: 1 1 220px; min-width: 0; }
body.pos-v2 .pickup-field.narrow { flex: 0 1 160px; }
body.pos-v2 .pickup-field label { font-size: 12px; font-weight: 600; color: #5a5145; }
body.pos-v2 .pickup-field .help-block { font-size: 11.5px; color: #8a8070; margin: 2px 0 0; }
body.pos-v2 .pickup-wrap .form-control,
body.pos-v2 .pickup-field input,
body.pos-v2 .pickup-field textarea {
  border: 1px solid var(--pos-line-2); border-radius: 9px; padding: 9px 11px; font-size: 14px;
  font-family: inherit; background: #fff; box-shadow: none; height: auto; min-width: 0; color: var(--pos-ink); }
body.pos-v2 .pickup-wrap .form-control:focus,
body.pos-v2 .pickup-field input:focus,
body.pos-v2 .pickup-field textarea:focus {
  outline: none; border-color: var(--pos-accent-deep); box-shadow: 0 0 0 3px var(--pos-accent-soft); }
body.pos-v2 .pickup-wrap .select2-container--default .select2-selection--single {
  border: 1px solid var(--pos-line-2); border-radius: 9px; height: 40px; font-family: inherit; }
body.pos-v2 .pickup-wrap .select2-container--default .select2-selection--single .select2-selection__rendered { line-height: 38px; padding-left: 11px; }
body.pos-v2 .pickup-wrap .select2-container--default .select2-selection--single .select2-selection__arrow { height: 38px; }
body.pos-v2 .pickup-check { display: flex; align-items: flex-start; gap: 9px; padding: 11px 13px; border: 1px solid var(--pos-line); border-radius: 10px; background: var(--pos-accent-soft); }
body.pos-v2 .pickup-check input { margin-top: 2px; }
body.pos-v2 .pickup-check label { font-size: 13.5px; color: var(--pos-ink); font-weight: 500; margin: 0; cursor: pointer; }
body.pos-v2 .btn-accent { background: var(--pos-accent); color: var(--pos-accent-text); border: 1px solid var(--pos-accent-deep);
  border-radius: 10px; padding: 10px 22px; font-weight: 700; font-size: 14px; cursor: pointer; font-family: inherit; }
body.pos-v2 .btn-accent:hover { background: var(--pos-accent-deep); }
body.pos-v2 .btn-ghost { background: transparent; border: 1px solid var(--pos-line-2); border-radius: 10px;
  padding: 10px 18px; font-weight: 600; font-size: 14px; cursor: pointer; color: #5a5145; font-family: inherit; text-decoration: none; display: inline-block; }
body.pos-v2 .btn-ghost:hover { background: var(--pos-surface-2); color: #5a5145; }
body.pos-v2 .pickup-actions { display: flex; justify-content: flex-end; gap: 10px; }
</style>

{!! Form::open(['action' => ['InStoreOrderController@update', $order->id], 'method' => 'put', 'id' => 'order_form']) !!}
<div class="pickup-wrap">
    <h1>Edit In Store Order</h1>
    <p class="sub">Update the customer, item, or paid status for this order.</p>

    <div class="pickup-card">
        <h2><i class="fa fa-user"></i> Customer</h2>
        <div class="pickup-row">
            <div class="pickup-field" style="flex: 2 1 260px;">
                {!! Form::label('customer_name', 'Customer Name *') !!}
                {!! Form::text('customer_name', $order->customer_name, ['class' => 'form-control', 'required', 'placeholder' => 'Full name']); !!}
            </div>
            <div class="pickup-field">
                {!! Form::label('location_id', 'Store') !!}
                {!! Form::select('location_id', $locations, $order->location_id, ['id' => 'location_id', 'class' => 'form-control select2', 'placeholder' => 'Select store', 'style' => 'width: 100%']); !!}
            </div>
        </div>
        <div class="pickup-row" style="margin-top: 4px;">
            <div class="pickup-field">
                {!! Form::label('customer_phone', 'Phone') !!}
                {!! Form::text('customer_phone', $order->customer_phone, ['class' => 'form-control', 'placeholder' => '(555) 123-4567', 'maxlength' => 40]); !!}
            </div>
            <div class="pickup-field">
                {!! Form::label('customer_email', 'Email') !!}
                {!! Form::email('customer_email', $order->customer_email, ['class' => 'form-control', 'placeholder' => 'name@email.com']); !!}
            </div>
        </div>
        <small class="help-block">Phone/email are used to notify the customer when the order's ready.</small>
    </div>

    <div class="pickup-card">
        <h2><i class="fa fa-compact-disc"></i> Order</h2>
        <div class="pickup-row">
            <div class="pickup-field" style="flex: 2 1 260px;">
                {!! Form::label('item_name', 'Vinyl / CD *') !!}
                {!! Form::text('item_name', $order->item_name, ['class' => 'form-control', 'required', 'placeholder' => 'Artist — Title']); !!}
            </div>
            <div class="pickup-field narrow">
                {!! Form::label('price_paid', 'Price Paid') !!}
                <div class="input-group">
                    <span class="input-group-addon">$</span>
                    {!! Form::number('price_paid', $order->price_paid, ['class' => 'form-control', 'step' => '0.01', 'min' => '0', 'placeholder' => '0.00']); !!}
                </div>
            </div>
        </div>
        <div class="pickup-row" style="margin-top: 12px;">
            <div class="pickup-field" style="flex: 1 1 100%;">
                <div class="pickup-check">
                    {!! Form::checkbox('is_paid', 1, (bool) $order->is_paid, ['id' => 'is_paid']) !!}
                    <label for="is_paid"><strong>Paid?</strong> — uncheck if the customer still owes</label>
                </div>
            </div>
        </div>
    </div>

    <div class="pickup-card">
        <h2><i class="fa fa-sticky-note"></i> Notes</h2>
        {!! Form::textarea('notes', $order->notes, ['class' => 'form-control', 'rows' => 3, 'placeholder' => 'Optional']); !!}
    </div>

    <div class="pickup-actions">
        <a href="{{ action('InStoreOrderController@index') }}" class="btn-ghost">Cancel</a>
        <button type="submit" class="btn-accent">Save Changes</button>
    </div>
</div>
{!! Form::close() !!}

@stop
@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        $('#location_id').select2({ placeholder: 'Select store', allowClear: true });
    });
</script>
@stop
