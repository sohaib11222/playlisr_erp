@extends('layouts.app')

@section('title', 'Add Customer Pickup')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

<style>
body.pos-v2 .pickup-wrap { max-width: 1000px; margin: 0 auto; padding: 18px 16px 60px; font-family: "Inter Tight", system-ui, sans-serif; color: var(--pos-ink); }
body.pos-v2 .pickup-wrap h1 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
body.pos-v2 .pickup-wrap .sub { color: #6b6253; margin: 0 0 20px; font-size: 14px; }
body.pos-v2 .pickup-card { background: var(--pos-surface); border: 1px solid var(--pos-line); border-radius: 14px; padding: 18px 20px; margin-bottom: 20px; }
body.pos-v2 .pickup-card h2 { font-size: 16px; font-weight: 700; margin: 0 0 14px; display: flex; align-items: center; gap: 8px; }
body.pos-v2 .pickup-card h2 .fa { color: var(--pos-accent-deep); }
body.pos-v2 .pickup-row { display: flex; flex-wrap: wrap; gap: 16px; }
body.pos-v2 .pickup-field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 4px; flex: 1 1 220px; min-width: 0; }
body.pos-v2 .pickup-field.narrow { flex: 0 1 130px; }
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

{!! Form::open(['action' => 'CustomerPickupController@store', 'method' => 'post', 'id' => 'pickup_form']) !!}
<div class="pickup-wrap">
    <h1>Add Customer Pickup</h1>
    <p class="sub">Hold an item for a customer — or log a special order you're bringing in from AMS so it shows on their account until it arrives.</p>

    {{-- Customer + Store --}}
    <div class="pickup-card">
        <h2><i class="fa fa-user"></i> Customer &amp; Store</h2>
        <div class="pickup-row">
            <div class="pickup-field" style="flex: 2 1 360px;">
                {!! Form::label('contact_id', 'Customer *') !!}
                {!! Form::select('contact_id', $customers, null, ['class' => 'form-control select2', 'required', 'style' => 'width: 100%']); !!}
            </div>
            <div class="pickup-field">
                {!! Form::label('location_id', 'Store *') !!}
                {!! Form::select('location_id', $locations, null, ['class' => 'form-control select2', 'placeholder' => 'Select store', 'required', 'style' => 'width: 100%']); !!}
            </div>
        </div>
        <div class="pickup-row" style="margin-top: 4px;">
            <div class="pickup-field">
                {!! Form::label('notify_email', 'Email') !!}
                {!! Form::email('notify_email', null, ['class' => 'form-control', 'placeholder' => 'name@email.com']); !!}
            </div>
            <div class="pickup-field">
                {!! Form::label('notify_phone', 'Phone') !!}
                {!! Form::text('notify_phone', null, ['class' => 'form-control', 'placeholder' => '(555) 123-4567', 'maxlength' => 40]); !!}
            </div>
        </div>
        <small class="help-block">Only needed if the customer doesn't already have an email/phone on file — we use these to text/email them when the order arrives. Saved to their profile if it's blank.</small>
    </div>

    {{-- Product --}}
    <div class="pickup-card">
        <h2><i class="fa fa-compact-disc"></i> Item on Hold</h2>
        <div class="pickup-row">
            <div class="pickup-field" style="flex: 2 1 320px;">
                {!! Form::label('product_id', 'Product') !!}
                {!! Form::select('product_id', [], null, ['class' => 'form-control select2', 'id' => 'product_id', 'style' => 'width: 100%']); !!}
            </div>
            <div class="pickup-field">
                {!! Form::label('variation_id', 'Variation/SKU') !!}
                {!! Form::select('variation_id', [], null, ['class' => 'form-control select2', 'id' => 'variation_id', 'style' => 'width: 100%']); !!}
            </div>
            <div class="pickup-field narrow">
                {!! Form::label('quantity', 'Qty *') !!}
                {!! Form::number('quantity', 1, ['class' => 'form-control', 'required', 'min' => 1, 'step' => 1]); !!}
            </div>
        </div>
    </div>

    {{-- AMS special order --}}
    <div class="pickup-card">
        <h2><i class="fa fa-truck"></i> Special Order (AMS)</h2>
        <div class="pickup-row">
            <div class="pickup-field" style="flex: 1 1 100%;">
                <div class="pickup-check">
                    {!! Form::checkbox('is_on_order', 1, false, ['id' => 'is_on_order']) !!}
                    <label for="is_on_order"><strong>On order — not in yet</strong> — ordered from AMS; shows as "On Order" on their account until it arrives</label>
                </div>
            </div>
        </div>
        <div class="pickup-row" style="margin-top: 12px;">
            <div class="pickup-field">
                {!! Form::label('ams_order_number', 'AMS Order #') !!}
                {!! Form::text('ams_order_number', null, ['class' => 'form-control', 'placeholder' => 'AMS order / invoice number', 'maxlength' => 64]); !!}
            </div>
            <div class="pickup-field">
                {!! Form::label('ams_expected_date', 'Expected Arrival') !!}
                {!! Form::text('ams_expected_date', null, ['class' => 'form-control date-picker', 'placeholder' => 'Optional']); !!}
            </div>
        </div>
        <small class="help-block" style="margin-top: 10px;">Put this same AMS Order # on the purchase when you order it. The moment that purchase is marked <strong>received</strong>, the customer is texted/emailed automatically — no need to come back here. (You can still mark it Arrived by hand if needed.)</small>
    </div>

    {{-- Pickup schedule + paid --}}
    <div class="pickup-card">
        <h2><i class="fa fa-calendar-check"></i> Pickup Schedule</h2>
        <div class="pickup-row">
            <div class="pickup-field">
                {!! Form::label('hold_date', 'Hold Date *') !!}
                {!! Form::text('hold_date', \Carbon\Carbon::now()->format('Y-m-d'), ['class' => 'form-control date-picker', 'required']); !!}
                <small class="help-block">When item was set aside</small>
            </div>
            <div class="pickup-field">
                {!! Form::label('expected_pickup_date', 'Expected Pickup Date') !!}
                {!! Form::text('expected_pickup_date', null, ['class' => 'form-control date-picker', 'placeholder' => 'Optional']); !!}
            </div>
            <div class="pickup-field">
                {!! Form::label('expected_pickup_time', 'Pickup Time') !!}
                {!! Form::text('expected_pickup_time', null, ['class' => 'form-control', 'placeholder' => 'e.g. 5-6pm, after 3pm', 'maxlength' => 50]); !!}
                <small class="help-block">Free-text window</small>
            </div>
        </div>
        <div class="pickup-row" style="margin-top: 12px;">
            <div class="pickup-field" style="flex: 1 1 100%;">
                <div class="pickup-check">
                    {!! Form::checkbox('is_paid', 1, true, ['id' => 'is_paid']) !!}
                    <label for="is_paid"><strong>Paid?</strong> — uncheck if the customer still owes</label>
                </div>
            </div>
        </div>
    </div>

    {{-- Notes --}}
    <div class="pickup-card">
        <h2><i class="fa fa-sticky-note"></i> Notes</h2>
        {!! Form::textarea('notes', null, ['class' => 'form-control', 'rows' => 3, 'placeholder' => 'e.g. called customer, put in hold bin, deposit taken, etc.']); !!}
    </div>

    <div class="pickup-actions">
        <a href="{{ action('CustomerPickupController@index') }}" class="btn-ghost">Cancel</a>
        <button type="submit" class="btn-accent">Save Pickup</button>
    </div>
</div>
{!! Form::close() !!}

@stop
@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        $('.date-picker').datepicker({
            autoclose: true,
            format: datepicker_date_format
        });

        var customerSelect = $('#contact_id');
        if (customerSelect.find('option').length > 1) {
            customerSelect.select2({ placeholder: 'Select a customer', allowClear: true });
        } else {
            customerSelect.select2({
                ajax: {
                    url: '/contacts/customers',
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return { q: params.term || '', page: params.page || 1 };
                    },
                    processResults: function(data) { return { results: data || [] }; },
                },
                templateResult: function(data) {
                    var t = '';
                    if (data.supplier_business_name) t += data.supplier_business_name + '<br>';
                    t += data.text;
                    if (data.mobile) t += '<br><small>' + data.mobile + '</small>';
                    return t;
                },
                minimumInputLength: 0,
                placeholder: 'Select a customer',
                allowClear: true,
                escapeMarkup: function(m) { return m; },
            });
        }

        $('#product_id').select2({
            ajax: {
                url: '/products/list',
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        term: params.term || '',
                        page: params.page || 1,
                        not_for_selling: 0,
                        search_fields: ['name', 'sku', 'artist'],
                    };
                },
                processResults: function(data) {
                    var parsed = typeof data === 'string' ? JSON.parse(data) : data;
                    var results = [];
                    var seen = {};
                    if (parsed && parsed.length) {
                        $.each(parsed, function(i, item) {
                            var pid = item.product_id || item.id;
                            if (!seen[pid]) {
                                seen[pid] = true;
                                var text = item.name || item.text || '';
                                if (item.artist) text += ' - ' + item.artist;
                                if (item.sku) text += ' (' + item.sku + ')';
                                results.push({ id: pid, text: text });
                            }
                        });
                    }
                    return { results: results };
                },
            },
            placeholder: 'Type to search for a product...',
            allowClear: true,
            minimumInputLength: 2,
        });

        $('#variation_id').select2({ placeholder: 'Select variation (optional)', allowClear: true });

        $('#product_id').on('change select2:select', function() {
            var productId = $(this).val();
            var variationSelect = $('#variation_id');
            variationSelect.empty();

            if (productId) {
                variationSelect.append('<option value="">Loading...</option>');
                variationSelect.prop('disabled', true);

                $.ajax({
                    url: '/products/get-product-to-edit/' + productId,
                    method: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        variationSelect.empty();
                        variationSelect.append('<option value="">None (Use default)</option>');
                        if (response.variations) {
                            $.each(response.variations, function(key, value) {
                                variationSelect.append('<option value="' + key + '">' + value + '</option>');
                            });
                        }
                        variationSelect.prop('disabled', false);
                        if (variationSelect.hasClass('select2-hidden-accessible')) variationSelect.select2('destroy');
                        variationSelect.select2({ placeholder: 'Select variation (optional)', allowClear: true });
                    },
                    error: function() {
                        variationSelect.empty().append('<option value="">Error loading variations</option>').prop('disabled', false);
                    }
                });
            } else {
                variationSelect.append('<option value="">Select product first</option>').prop('disabled', true);
                if (variationSelect.hasClass('select2-hidden-accessible')) variationSelect.select2('destroy');
                variationSelect.select2({ placeholder: 'Select product first', allowClear: true });
            }
        });
    });
</script>
@stop
