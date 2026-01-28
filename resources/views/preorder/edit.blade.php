@extends('layouts.app')

@section('title', 'Edit Preorder')

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>Edit Preorder</h1>
</section>

<!-- Main content -->
<section class="content">
    {!! Form::open(['action' => ['PreorderController@update', $preorder->id], 'method' => 'PUT', 'id' => 'preorder_form']) !!}
    <div class="row">
        <div class="col-md-12">
            <div class="box box-solid">
                <div class="box-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('contact_id', 'Customer: *') !!}
                                {!! Form::select('contact_id', $customers, $preorder->contact_id, ['class' => 'form-control select2', 'required', 'style' => 'width: 100%']); !!}
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('product_id', 'Product: *') !!}
                                {!! Form::select('product_id', [], $preorder->product_id, ['class' => 'form-control select2', 'id' => 'product_id', 'required', 'style' => 'width: 100%']); !!}
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('variation_id', 'Variation/SKU:') !!}
                                {!! Form::select('variation_id', $variations, $preorder->variation_id, ['class' => 'form-control select2', 'id' => 'variation_id', 'style' => 'width: 100%']); !!}
                                <small class="help-block">Select specific variation if needed</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('quantity', 'Quantity: *') !!}
                                {!! Form::text('quantity', $preorder->quantity, ['class' => 'form-control input_number', 'required']); !!}
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('order_date', 'Order Date: *') !!}
                                {!! Form::text('order_date', \Carbon\Carbon::parse($preorder->order_date)->format('Y-m-d'), ['class' => 'form-control date-picker', 'required']); !!}
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('expected_date', 'Expected Date:') !!}
                                {!! Form::text('expected_date', $preorder->expected_date ? \Carbon\Carbon::parse($preorder->expected_date)->format('Y-m-d') : null, ['class' => 'form-control date-picker', 'placeholder' => 'Optional']); !!}
                                <small class="help-block">Expected arrival date</small>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                {!! Form::label('notes', 'Notes:') !!}
                                {!! Form::textarea('notes', $preorder->notes, ['class' => 'form-control', 'rows' => 3]); !!}
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
            <a href="{{ action('PreorderController@index') }}" class="btn btn-default pull-right" style="margin-right: 10px;">Cancel</a>
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

        // Initialize Select2 for product dropdown with AJAX (like POS form)
        var selectedProductId = {{ $preorder->product_id ?? 'null' }};
        var selectedProductName = '{{ addslashes($preorder->product->name ?? "") }}';
        
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
                        search_fields: ['name', 'sku', 'artist']
                    };
                },
                processResults: function(data) {
                    var parsedData = typeof data === 'string' ? JSON.parse(data) : data;
                    var results = [];
                    var seenProducts = {};
                    
                    if (parsedData && parsedData.length > 0) {
                        $.each(parsedData, function(index, item) {
                            var productId = item.product_id || item.id;
                            
                            if (!seenProducts[productId]) {
                                seenProducts[productId] = true;
                                
                                var displayText = item.name || item.text || '';
                                if (item.artist) {
                                    displayText += ' - ' + item.artist;
                                }
                                if (item.sku) {
                                    displayText += ' (' + item.sku + ')';
                                }
                                
                                results.push({
                                    id: productId,
                                    text: displayText
                                });
                            }
                        });
                    }
                    return { results: results };
                },
            },
            placeholder: 'Type to search for a product...',
            allowClear: true,
            minimumInputLength: 2
        });
        
        // Pre-select the current product if editing
        if (selectedProductId && selectedProductName) {
            var $option = $('<option></option>').val(selectedProductId).text(selectedProductName);
            $('#product_id').append($option).val(selectedProductId).trigger('change');
        }

        // Initialize Select2 for variation dropdown
        $('#variation_id').select2({
            placeholder: 'Select variation (optional)',
            allowClear: true
        });

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
