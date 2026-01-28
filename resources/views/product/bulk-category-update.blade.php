@extends('layouts.app')
@section('title', 'Bulk Update Categories')

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>Bulk Update Categories</h1>
    <ol class="breadcrumb">
        <li><a href="{{ action('HomeController@index') }}"><i class="fa fa-dashboard"></i> @lang('lang_v1.home')</a></li>
        <li><a href="{{ action('ProductController@index') }}">@lang('product.products')</a></li>
        <li class="active">Bulk Update Categories</li>
    </ol>
</section>

<!-- Main content -->
<section class="content">
    <div class="row">
        <div class="col-md-8 col-md-offset-2">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Update Categories for Selected Products</h3>
                </div>
                
                {!! Form::open(['url' => action('ProductController@bulkUpdateCategories'), 'method' => 'post', 'id' => 'bulk_category_update_form']) !!}
                
                <div class="box-body">
                    <div class="form-group">
                        <label>Select Category: <span class="text-danger">*</span></label>
                        {!! Form::select('category_id', $categories, null, [
                            'class' => 'form-control select2',
                            'style' => 'width:100%',
                            'id' => 'category_id',
                            'placeholder' => 'Select Category',
                            'required' => true
                        ]); !!}
                    </div>
                    
                    <div class="form-group">
                        <label>Select Subcategory (Optional):</label>
                        <select class="form-control select2" style="width:100%" id="subcategory_id" name="sub_category_id">
                            <option value="">Select Subcategory</option>
                        </select>
                    </div>
                    
                    <div class="alert alert-info">
                        <strong>Note:</strong> This will update the category for the selected products. 
                        @if(!empty($product_ids) && count($product_ids) > 0)
                            <br><strong>{{ count($product_ids) }} product(s) will be updated.</strong>
                        @else
                            <br><span class="text-warning">No products selected. Please go back to the products page and select products first.</span>
                        @endif
                    </div>
                    
                    @if(!empty($product_ids) && count($product_ids) > 0)
                        @foreach($product_ids as $product_id)
                            <input type="hidden" name="product_ids[]" value="{{ $product_id }}">
                        @endforeach
                    @endif
                </div>
                
                <div class="box-footer">
                    <a href="{{ action('ProductController@index') }}" class="btn btn-default">Cancel</a>
                    <button type="submit" class="btn btn-primary pull-right" id="submit_btn">
                        <i class="fa fa-save"></i> Update Categories
                    </button>
                </div>
                
                {!! Form::close() !!}
            </div>
        </div>
    </div>
</section>

@endsection

@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        // Initialize Select2
        $('#category_id').select2({
            placeholder: 'Select Category',
            allowClear: true
        });
        
        $('#subcategory_id').select2({
            placeholder: 'Select Subcategory',
            allowClear: true
        });
        
        // Handle category change to load subcategories
        $('#category_id').on('change', function() {
            const categoryId = $(this).val();
            const subCategorySelect = $('#subcategory_id');
            
            if (categoryId) {
                // Show loading state
                subCategorySelect.prop('disabled', true);
                subCategorySelect.html('<option value="">Loading...</option>');
                subCategorySelect.select2('destroy').select2({
                    placeholder: 'Loading...',
                    disabled: true
                });
                
                // Make AJAX request
                $.ajax({
                    url: "{{ route('product.get_sub_categories') }}",
                    type: 'POST',
                    data: { cat_id: categoryId },
                    headers: { 
                        'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    success: function (data) {
                        console.log('Subcategories loaded:', data);
                        subCategorySelect.select2('destroy');
                        subCategorySelect.html(data);
                        subCategorySelect.prop('disabled', false);
                        subCategorySelect.select2({
                            placeholder: 'Select Subcategory',
                            allowClear: true
                        });
                    },
                    error: function (xhr, status, error) {
                        console.error('Error loading subcategories:', error, xhr);
                        toastr.error('Failed to fetch subcategories.');
                        subCategorySelect.select2('destroy');
                        subCategorySelect.html('<option value="">Select Subcategory</option>');
                        subCategorySelect.prop('disabled', false);
                        subCategorySelect.select2({
                            placeholder: 'Select Subcategory',
                            allowClear: true
                        });
                    }
                });
            } else {
                // Clear subcategory if no category selected
                subCategorySelect.select2('destroy');
                subCategorySelect.html('<option value="">Select Subcategory</option>');
                subCategorySelect.select2({
                    placeholder: 'Select Subcategory',
                    allowClear: true
                });
            }
        });
        
        // Handle form submission
        $('#bulk_category_update_form').on('submit', function(e) {
            e.preventDefault();
            
            const categoryId = $('#category_id').val();
            const productIds = $('input[name="product_ids[]"]').map(function() {
                return $(this).val();
            }).get();
            
            if (!categoryId) {
                toastr.error('Please select a category.');
                return false;
            }
            
            if (productIds.length === 0) {
                toastr.error('No products selected. Please go back and select products first.');
                return false;
            }
            
            if (!confirm(`Are you sure you want to update ${productIds.length} product(s)?`)) {
                return false;
            }
            
            // Disable submit button
            const $submitBtn = $('#submit_btn');
            $submitBtn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Updating...');
            
            // Submit form via AJAX
            $.ajax({
                url: $(this).attr('action'),
                type: 'POST',
                data: $(this).serialize(),
                headers: { 
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                success: function(response) {
                    console.log('Bulk update response:', response);
                    if (response.success) {
                        toastr.success(response.msg || `Successfully updated ${productIds.length} products.`);
                        setTimeout(function() {
                            window.location.href = "{{ action('ProductController@index') }}";
                        }, 1500);
                    } else {
                        toastr.error(response.msg || 'Failed to update products.');
                        $submitBtn.prop('disabled', false).html('<i class="fa fa-save"></i> Update Categories');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Bulk update error:', {
                        status: status,
                        error: error,
                        response: xhr.responseText,
                        statusCode: xhr.status
                    });
                    let errorMsg = 'An error occurred while updating products.';
                    if (xhr.responseJSON && xhr.responseJSON.msg) {
                        errorMsg = xhr.responseJSON.msg;
                    } else if (xhr.responseText) {
                        try {
                            const errorData = JSON.parse(xhr.responseText);
                            if (errorData.msg) {
                                errorMsg = errorData.msg;
                            }
                        } catch (e) {
                            console.error('Failed to parse error response:', e);
                        }
                    }
                    toastr.error(errorMsg);
                    $submitBtn.prop('disabled', false).html('<i class="fa fa-save"></i> Update Categories');
                }
            });
            
            return false;
        });
    });
</script>
@endsection
