@extends('layouts.app')

@section('title', 'Add Customer Pickup')

@section('content')

<section class="content-header">
    <h1>Add Customer Pickup</h1>
</section>

<section class="content">
    {!! Form::open(['action' => 'CustomerPickupController@store', 'method' => 'post', 'id' => 'pickup_form']) !!}
    <div class="row">
        <div class="col-md-12">
            <div class="box box-solid">
                <div class="box-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('contact_id', 'Customer: *') !!}
                                {!! Form::select('contact_id', $customers, null, ['class' => 'form-control select2', 'required', 'style' => 'width: 100%']); !!}
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('location_id', 'Location:') !!}
                                {!! Form::select('location_id', $locations, null, ['class' => 'form-control select2', 'placeholder' => 'Select location', 'style' => 'width: 100%']); !!}
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('product_id', 'Product:') !!}
                                {!! Form::select('product_id', [], null, ['class' => 'form-control select2', 'id' => 'product_id', 'style' => 'width: 100%']); !!}
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                {!! Form::label('variation_id', 'Variation/SKU:') !!}
                                {!! Form::select('variation_id', [], null, ['class' => 'form-control select2', 'id' => 'variation_id', 'style' => 'width: 100%']); !!}
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                {!! Form::label('quantity', 'Quantity: *') !!}
                                {!! Form::text('quantity', 1, ['class' => 'form-control input_number', 'required']); !!}
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                {!! Form::label('hold_date', 'Hold Date: *') !!}
                                {!! Form::text('hold_date', \Carbon\Carbon::now()->format('Y-m-d'), ['class' => 'form-control date-picker', 'required']); !!}
                                <small class="help-block">When item was set aside</small>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                {!! Form::label('expected_pickup_date', 'Expected Pickup Date:') !!}
                                {!! Form::text('expected_pickup_date', null, ['class' => 'form-control date-picker', 'placeholder' => 'Optional']); !!}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                {!! Form::label('expected_pickup_time', 'Pickup Time:') !!}
                                {!! Form::text('expected_pickup_time', null, ['class' => 'form-control', 'placeholder' => 'e.g. 5-6pm, after 3pm', 'maxlength' => 50]); !!}
                                <small class="help-block">Free-text window</small>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <div class="checkbox">
                                    <label>
                                        {!! Form::checkbox('is_paid', 1, true) !!}
                                        <strong>Paid?</strong> &nbsp;<small class="text-muted">— uncheck if customer still owes</small>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                {!! Form::label('notes', 'Notes:') !!}
                                {!! Form::textarea('notes', null, ['class' => 'form-control', 'rows' => 3, 'placeholder' => 'e.g. called customer, put in hold bin, deposit taken, etc.']); !!}
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
            <a href="{{ action('CustomerPickupController@index') }}" class="btn btn-default pull-right" style="margin-right: 10px;">Cancel</a>
        </div>
    </div>
    {!! Form::close() !!}
</section>

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
