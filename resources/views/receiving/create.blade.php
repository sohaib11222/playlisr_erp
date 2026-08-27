@extends('layouts.app')

@section('title', 'Log a Package')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

<style>
body.pos-v2 .rcv-wrap { max-width: 900px; margin: 0 auto; padding: 18px 16px 60px; font-family: "Inter Tight", system-ui, sans-serif; color: var(--pos-ink); }
body.pos-v2 .rcv-wrap h1 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
body.pos-v2 .rcv-wrap .sub { color: #6b6253; margin: 0 0 20px; font-size: 14px; }
body.pos-v2 .rcv-card { background: var(--pos-surface); border: 1px solid var(--pos-line); border-radius: 14px; padding: 18px 20px; margin-bottom: 20px; }
body.pos-v2 .rcv-card h2 { font-size: 16px; font-weight: 700; margin: 0 0 14px; display: flex; align-items: center; gap: 8px; }
body.pos-v2 .rcv-card h2 .fa { color: var(--pos-accent-deep); }
body.pos-v2 .rcv-row { display: flex; flex-wrap: wrap; gap: 16px; }
body.pos-v2 .rcv-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 4px; flex: 1 1 220px; min-width: 0; }
body.pos-v2 .rcv-field label { font-size: 12px; font-weight: 600; color: #5a5145; }
body.pos-v2 .rcv-field .help-block { font-size: 11.5px; color: #8a8070; margin: 2px 0 0; }
body.pos-v2 .rcv-wrap .form-control,
body.pos-v2 .rcv-field input,
body.pos-v2 .rcv-field textarea {
  border: 1px solid var(--pos-line-2); border-radius: 9px; padding: 9px 11px; font-size: 14px;
  font-family: inherit; background: #fff; box-shadow: none; height: auto; min-width: 0; color: var(--pos-ink); }
body.pos-v2 .rcv-wrap .select2-container--default .select2-selection--single,
body.pos-v2 .rcv-wrap .select2-container--default .select2-selection--multiple {
  border: 1px solid var(--pos-line-2); border-radius: 9px; font-family: inherit; }
body.pos-v2 .btn-accent { background: var(--pos-accent); color: var(--pos-accent-text); border: 1px solid var(--pos-accent-deep);
  border-radius: 10px; padding: 10px 22px; font-weight: 700; font-size: 14px; cursor: pointer; font-family: inherit; }
body.pos-v2 .btn-accent:hover { background: var(--pos-accent-deep); }
body.pos-v2 .btn-ghost { background: transparent; border: 1px solid var(--pos-line-2); border-radius: 10px;
  padding: 10px 18px; font-weight: 600; font-size: 14px; cursor: pointer; color: #5a5145; font-family: inherit; text-decoration: none; display: inline-block; }
body.pos-v2 .rcv-actions { display: flex; justify-content: flex-end; gap: 10px; }
</style>

{!! Form::open(['action' => 'ReceivingPackageController@store', 'method' => 'post', 'id' => 'receiving_form']) !!}
<div class="rcv-wrap">
    <h1>Log a Package</h1>
    <p class="sub">What just came in? Log it now — you'll add the contents on the next screen.</p>

    <div class="rcv-card">
        <h2><i class="fa fa-box"></i> What is it?</h2>
        <div class="rcv-row">
            <div class="rcv-field">
                {!! Form::label('package_type', 'Type *') !!}
                {!! Form::select('package_type', $packageTypes, null, ['class' => 'form-control', 'id' => 'package_type', 'required']); !!}
            </div>
            <div class="rcv-field" id="type_detail_field" style="display:none;">
                {!! Form::label('package_type_detail', 'Detail') !!}
                {!! Form::text('package_type_detail', null, ['class' => 'form-control', 'placeholder' => 'e.g. Walmart, Instacart']); !!}
            </div>
            <div class="rcv-field">
                {!! Form::label('location_id', 'Store *') !!}
                {!! Form::select('location_id', $locations, null, ['class' => 'form-control select2', 'placeholder' => 'Select store', 'required', 'style' => 'width: 100%']); !!}
            </div>
        </div>
    </div>

    <div class="rcv-card">
        <h2><i class="fa fa-file-invoice"></i> Order &amp; Invoice</h2>
        <div class="rcv-row">
            <div class="rcv-field">
                {!! Form::label('order_number', 'Order #') !!}
                {!! Form::text('order_number', null, ['class' => 'form-control', 'placeholder' => 'Optional']); !!}
            </div>
            <div class="rcv-field">
                {!! Form::label('invoice_number', 'Invoice #') !!}
                {!! Form::text('invoice_number', null, ['class' => 'form-control', 'placeholder' => 'Optional']); !!}
            </div>
        </div>
        <div class="rcv-row" style="margin-top: 4px;">
            <div class="rcv-field" style="flex: 1 1 100%;">
                {!! Form::label('purchase_order_ids', 'Link to Purchase Order(s)') !!}
                {!! Form::select('purchase_order_ids[]', [], null, ['class' => 'form-control', 'id' => 'purchase_order_ids', 'multiple' => 'multiple', 'style' => 'width: 100%']); !!}
                <small class="help-block">Optional — a box can match one PO, several, or none at all (e.g. a Walmart supply run).</small>
            </div>
        </div>
    </div>

    <div class="rcv-card">
        <h2><i class="fa fa-sticky-note"></i> Notes</h2>
        {!! Form::textarea('notes', null, ['class' => 'form-control', 'rows' => 3, 'placeholder' => 'Anything worth flagging about this package']); !!}
    </div>

    <div class="rcv-actions">
        <a href="{{ action('ReceivingPackageController@index') }}" class="btn-ghost">Cancel</a>
        <button type="submit" class="btn-accent">Log Package &amp; Add Contents &rarr;</button>
    </div>
</div>
{!! Form::close() !!}

@stop
@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        $('select[name="location_id"]').select2({ placeholder: 'Select store' });

        function toggleTypeDetail() {
            var needsDetail = $('#package_type').val() === 'retail_delivery' || $('#package_type').val() === 'other';
            $('#type_detail_field').toggle(needsDetail);
        }
        $('#package_type').on('change', toggleTypeDetail);
        toggleTypeDetail();

        $('#purchase_order_ids').select2({
            placeholder: 'Search open purchase orders...',
            allowClear: true,
            minimumInputLength: 1,
            ajax: {
                url: '{{ action("ReceivingPackageController@searchPurchaseOrders") }}',
                dataType: 'json',
                delay: 250,
                data: function(params) { return { term: params.term || '' }; },
                processResults: function(data) { return { results: data.results || [] }; },
            },
        });
    });
</script>
@stop
