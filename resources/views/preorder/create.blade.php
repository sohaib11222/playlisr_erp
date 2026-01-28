@extends('layouts.app')

@section('title', 'Add Preorder')

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>Add Preorder</h1>
</section>

<!-- Main content -->
<section class="content">
    {!! Form::open(['action' => 'PreorderController@store', 'method' => 'post', 'id' => 'preorder_form']) !!}
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
                                {!! Form::label('product_id', 'Product: *') !!}
                                {!! Form::select('product_id', [], null, ['class' => 'form-control select2', 'id' => 'product_id', 'required', 'style' => 'width: 100%']); !!}
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('variation_id', 'Variation/SKU:') !!}
                                {!! Form::select('variation_id', [], null, ['class' => 'form-control select2', 'id' => 'variation_id', 'style' => 'width: 100%']); !!}
                                <small class="help-block">Select specific variation if needed</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('quantity', 'Quantity: *') !!}
                                {!! Form::text('quantity', 1, ['class' => 'form-control input_number', 'required']); !!}
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('order_date', 'Order Date: *') !!}
                                {!! Form::text('order_date', \Carbon\Carbon::now()->format('Y-m-d'), ['class' => 'form-control date-picker', 'required']); !!}
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('expected_date', 'Expected Date:') !!}
                                {!! Form::text('expected_date', null, ['class' => 'form-control date-picker', 'placeholder' => 'Optional']); !!}
                                <small class="help-block">Expected arrival date</small>
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
        // Initialize date pickers
        $('.date-picker').datepicker({
            autoclose: true,
            format: datepicker_date_format
        });

        // Initialize Select2 for customer dropdown
        var customerSelect = $('#contact_id');
        var customerOptions = customerSelect.find('option').length;
        
        if (customerOptions > 1) { // More than just empty option
            // Has static options from controller, initialize with them
            customerSelect.select2({
                placeholder: 'Select a customer',
                allowClear: true
            });
        } else {
            // No static options, use AJAX (like POS form)
            customerSelect.select2({
                ajax: {
                    url: '/contacts/customers',
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            q: params.term || '', // search term
                            page: params.page || 1,
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: data || [],
                        };
                    },
                },
                templateResult: function (data) { 
                    var template = '';
                    if (data.supplier_business_name) {
                        template += data.supplier_business_name + "<br>";
                    }
                    template += data.text;
                    if (data.mobile) {
                        template += "<br><small>" + data.mobile + "</small>";
                    }
                    return template;
                },
                minimumInputLength: 0, // Allow showing all options without typing
                placeholder: 'Select a customer',
                allowClear: true,
                escapeMarkup: function(markup) {
                    return markup;
                },
            });
        }

        // Initialize Select2 for product dropdown with AJAX (like POS form)
        // This prevents loading all products at once, improving performance
        $('#product_id').select2({
            ajax: {
                url: '/products/list',
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        term: params.term || '', // search term
                        page: params.page || 1,
                        not_for_selling: 0, // Only products for selling
                        search_fields: ['name', 'sku', 'artist'] // Search in name, SKU, and artist
                    };
                },
                processResults: function(data) {
                    // The endpoint returns JSON string, parse it
                    var parsedData = typeof data === 'string' ? JSON.parse(data) : data;
                    var results = [];
                    var seenProducts = {}; // Track unique products by product_id
                    
                    if (parsedData && parsedData.length > 0) {
                        $.each(parsedData, function(index, item) {
                            // item has product_id, name, artist, variation_id, etc.
                            var productId = item.product_id || item.id;
                            
                            // Only add each product once (even if it has multiple variations)
                            if (!seenProducts[productId]) {
                                seenProducts[productId] = true;
                                
                                // Format product display text
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
                    return {
                        results: results
                    };
                },
            },
            placeholder: 'Type to search for a product...',
            allowClear: true,
            minimumInputLength: 2 // Require at least 2 characters before searching
        });

        // Initialize Select2 for variation dropdown
        $('#variation_id').select2({
            placeholder: 'Select variation (optional)',
            allowClear: true
        });

        // Handle product change - use both change and select2:select events
        $('#product_id').on('change select2:select', function() {
            var productId = $(this).val();
            var variationSelect = $('#variation_id');
            
            // Clear and disable variation select
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
                        
                        if (response.variations && Object.keys(response.variations).length > 0) {
                            $.each(response.variations, function(key, value) {
                                variationSelect.append('<option value="' + key + '">' + value + '</option>');
                            });
                        }
                        
                        variationSelect.prop('disabled', false);
                        
                        // Reinitialize select2 for variation dropdown
                        if (variationSelect.hasClass('select2-hidden-accessible')) {
                            variationSelect.select2('destroy');
                        }
                        variationSelect.select2({
                            placeholder: 'Select variation (optional)',
                            allowClear: true
                        });
                    },
                    error: function(xhr, status, error) {
                        console.error('Error loading variations:', error);
                        variationSelect.empty();
                        variationSelect.append('<option value="">Error loading variations</option>');
                        variationSelect.prop('disabled', false);
                        
                        // Reinitialize select2
                        if (variationSelect.hasClass('select2-hidden-accessible')) {
                            variationSelect.select2('destroy');
                        }
                        variationSelect.select2({
                            placeholder: 'Error loading variations',
                            allowClear: true
                        });
                    }
                });
            } else {
                variationSelect.append('<option value="">Select product first</option>');
                variationSelect.prop('disabled', true);
                
                // Reinitialize select2
                if (variationSelect.hasClass('select2-hidden-accessible')) {
                    variationSelect.select2('destroy');
                }
                variationSelect.select2({
                    placeholder: 'Select product first',
                    allowClear: true
                });
            }
        });
    });
</script>
@stop
