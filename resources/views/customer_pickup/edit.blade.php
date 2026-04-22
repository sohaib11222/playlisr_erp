@extends('layouts.app')

@section('title', 'Edit Customer Pickup')

@section('content')

<section class="content-header">
    <h1>Edit Customer Pickup</h1>
</section>

<section class="content">
    {!! Form::open(['action' => ['CustomerPickupController@update', $pickup->id], 'method' => 'PUT', 'id' => 'pickup_form']) !!}
    <div class="row">
        <div class="col-md-12">
            <div class="box box-solid">
                <div class="box-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('contact_id', 'Customer: *') !!}
                                {!! Form::select('contact_id', $customers, $pickup->contact_id, ['class' => 'form-control select2', 'required', 'style' => 'width: 100%']); !!}
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('location_id', 'Location:') !!}
                                {!! Form::select('location_id', $locations, $pickup->location_id, ['class' => 'form-control select2', 'placeholder' => 'Select location', 'style' => 'width: 100%']); !!}
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('product_id', 'Product:') !!}
                                {!! Form::select('product_id', [], $pickup->product_id, ['class' => 'form-control select2', 'id' => 'product_id', 'style' => 'width: 100%']); !!}
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                {!! Form::label('variation_id', 'Variation/SKU:') !!}
                                {!! Form::select('variation_id', $variations, $pickup->variation_id, ['class' => 'form-control select2', 'id' => 'variation_id', 'style' => 'width: 100%']); !!}
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                {!! Form::label('quantity', 'Quantity: *') !!}
                                {!! Form::text('quantity', $pickup->quantity, ['class' => 'form-control input_number', 'required']); !!}
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                {!! Form::label('hold_date', 'Hold Date: *') !!}
                                {!! Form::text('hold_date', \Carbon\Carbon::parse($pickup->hold_date)->format('Y-m-d'), ['class' => 'form-control date-picker', 'required']); !!}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                {!! Form::label('expected_pickup_date', 'Expected Pickup Date:') !!}
                                {!! Form::text('expected_pickup_date', $pickup->expected_pickup_date ? \Carbon\Carbon::parse($pickup->expected_pickup_date)->format('Y-m-d') : null, ['class' => 'form-control date-picker', 'placeholder' => 'Optional']); !!}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                {!! Form::label('expected_pickup_time', 'Pickup Time:') !!}
                                {!! Form::text('expected_pickup_time', $pickup->expected_pickup_time, ['class' => 'form-control', 'placeholder' => 'e.g. 5-6pm', 'maxlength' => 50]); !!}
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                <div class="checkbox">
                                    <label>
                                        {!! Form::checkbox('is_paid', 1, (bool) $pickup->is_paid) !!}
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
                                {!! Form::textarea('notes', $pickup->notes, ['class' => 'form-control', 'rows' => 3]); !!}
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
            <a href="{{ action('CustomerPickupController@index') }}" class="btn btn-default pull-right" style="margin-right: 10px;">Cancel</a>
        </div>
    </div>
    {!! Form::close() !!}
</section>

@stop
@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        $('.date-picker').datepicker({ autoclose: true, format: datepicker_date_format });

        var selectedProductId = {{ $pickup->product_id ?? 'null' }};
        var selectedProductName = '{{ addslashes($pickup->product->name ?? "") }}';

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

        if (selectedProductId && selectedProductName) {
            var $opt = $('<option></option>').val(selectedProductId).text(selectedProductName);
            $('#product_id').append($opt).val(selectedProductId).trigger('change.select2');
        }

        $('#variation_id').select2({ placeholder: 'Select variation (optional)', allowClear: true });

        $('#product_id').on('change select2:select', function() {
            var productId = $(this).val();
            $('#variation_id').empty().append('<option value="">Loading...</option>');

            if (productId) {
                $.ajax({
                    url: '/products/get-product-to-edit/' + productId,
                    method: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        $('#variation_id').empty().append('<option value="">None (Use default)</option>');
                        if (response.variations) {
                            $.each(response.variations, function(key, value) {
                                $('#variation_id').append('<option value="' + key + '">' + value + '</option>');
                            });
                        }
                    },
                    error: function() {
                        $('#variation_id').empty().append('<option value="">Error loading variations</option>');
                    }
                });
            } else {
                $('#variation_id').empty().append('<option value="">Select product first</option>');
            }
        });
    });
</script>
@stop
