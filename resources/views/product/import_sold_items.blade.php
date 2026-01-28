@extends('layouts.app')

@section('title', 'Import Sold Items as Products')

@section('content')
<section class="content-header">
    <h1>Import Sold Items as Products
        <small>Extract products from sold items for autocomplete suggestions</small>
    </h1>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary'])
                <div class="row">
                    <div class="col-md-12">
                        <h4>Import Statistics</h4>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="info-box">
                                    <span class="info-box-icon bg-blue"><i class="fa fa-shopping-cart"></i></span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">Total Sold Items</span>
                                        <span class="info-box-number">{{ number_format($total_sold_items) }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-box">
                                    <span class="info-box-icon bg-green"><i class="fa fa-check-circle"></i></span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">Items with Products</span>
                                        <span class="info-box-number">{{ number_format($items_with_products) }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-box">
                                    <span class="info-box-icon bg-yellow"><i class="fa fa-database"></i></span>
                                    <div class="info-box-content">
                                        <span class="info-box-text">Existing Products</span>
                                        <span class="info-box-number">{{ number_format($unique_products_count) }}</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <hr>

                <div class="row">
                    <div class="col-md-12">
                        <h4>Import Options</h4>
                        <p class="help-block">Import products from sold items. You can either extract from transaction history or upload a CSV/Excel file with 50,000 items.</p>
                        
                        <ul class="nav nav-tabs" role="tablist">
                            <li role="presentation" class="active">
                                <a href="#from_transactions" aria-controls="from_transactions" role="tab" data-toggle="tab">
                                    <i class="fa fa-database"></i> From Transaction History
                                </a>
                            </li>
                            <li role="presentation">
                                <a href="#from_file" aria-controls="from_file" role="tab" data-toggle="tab">
                                    <i class="fa fa-file-excel-o"></i> Upload CSV/Excel File
                                </a>
                            </li>
                        </ul>
                        
                        <div class="tab-content" style="margin-top: 20px;">
                            <!-- Tab 1: From Transactions -->
                            <div role="tabpanel" class="tab-pane active" id="from_transactions">
                                <p class="help-block">Extract unique products from your sold items (transaction_sell_lines) and create them in the products database.</p>
                                
                                {!! Form::open(['url' => action('ProductController@processImportSoldItems'), 'method' => 'post', 'id' => 'import_sold_items_form']) !!}
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    {!! Form::label('limit', 'Maximum Items to Import:') !!}
                                    {!! Form::number('limit', 50000, ['class' => 'form-control', 'min' => 1, 'max' => 100000, 'required' => true]) !!}
                                    <p class="help-block">Maximum number of unique products to import (default: 50,000)</p>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    {!! Form::label('min_sales_count', 'Minimum Sales Count:') !!}
                                    {!! Form::number('min_sales_count', 1, ['class' => 'form-control', 'min' => 1, 'required' => true]) !!}
                                    <p class="help-block">Only import products that have been sold at least this many times</p>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12">
                                <div class="form-group">
                                    <div class="checkbox">
                                        <label>
                                            {!! Form::checkbox('create_duplicates', 1, false, ['class' => 'input-icheck']) !!} 
                                            Create products even if similar products already exist
                                        </label>
                                    </div>
                                    <p class="help-block">If unchecked, products with matching name/SKU/artist will be skipped</p>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12">
                                <div class="alert alert-warning">
                                    <i class="fa fa-exclamation-triangle"></i> 
                                    <strong>Important:</strong> This process may take several minutes for large imports (50,000+ items). 
                                    Make sure you have at least one category and sub-category created before importing.
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12">
                                <button type="submit" class="btn btn-primary btn-lg" id="import_btn">
                                    <i class="fa fa-upload"></i> Start Import
                                </button>
                                <a href="{{action('ProductController@index')}}" class="btn btn-default btn-lg">
                                    <i class="fa fa-arrow-left"></i> Back to Products
                                </a>
                            </div>
                        </div>

                                {!! Form::close() !!}
                            </div>
                            
                            <!-- Tab 2: From File -->
                            <div role="tabpanel" class="tab-pane" id="from_file">
                                <p class="help-block">Upload a CSV or Excel file with product data. File should contain columns: Name, SKU, Artist, Category, Price, etc.</p>
                                
                                {!! Form::open(['url' => action('ProductController@processImportSoldItemsFromFile'), 'method' => 'post', 'id' => 'import_file_form', 'files' => true]) !!}
                                
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="form-group">
                                            {!! Form::label('import_file', 'Select File (CSV or Excel):') !!}
                                            {!! Form::file('import_file', ['class' => 'form-control', 'accept' => '.csv,.xlsx,.xls', 'required' => true]) !!}
                                            <p class="help-block">Maximum file size: 50MB. Supported formats: CSV, XLS, XLSX</p>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            {!! Form::label('file_min_sales_count', 'Minimum Sales Count (if applicable):') !!}
                                            {!! Form::number('file_min_sales_count', 1, ['class' => 'form-control', 'min' => 1]) !!}
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-group">
                                            <div class="checkbox" style="margin-top: 25px;">
                                                <label>
                                                    {!! Form::checkbox('file_create_duplicates', 1, false, ['class' => 'input-icheck']) !!} 
                                                    Create products even if similar products already exist
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="alert alert-info">
                                            <strong>File Format:</strong> Your CSV/Excel file should have columns like:
                                            <ul>
                                                <li>Name (required)</li>
                                                <li>SKU (optional)</li>
                                                <li>Artist (optional)</li>
                                                <li>Category (optional)</li>
                                                <li>Price (optional)</li>
                                                <li>Format (optional)</li>
                                            </ul>
                                            The system will try to match columns automatically.
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-12">
                                        <button type="submit" class="btn btn-primary btn-lg" id="import_file_btn">
                                            <i class="fa fa-upload"></i> Upload and Import
                                        </button>
                                    </div>
                                </div>
                                
                                {!! Form::close() !!}
                            </div>
                        </div>
                    </div>
                </div>

                <hr>

                <div class="row" id="import_progress" style="display: none;">
                    <div class="col-md-12">
                        <h4>Import Progress</h4>
                        <div class="progress progress-lg active">
                            <div class="progress-bar progress-bar-primary progress-bar-striped" role="progressbar" 
                                 aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="width: 0%">
                                <span class="sr-only">0% Complete</span>
                            </div>
                        </div>
                        <p id="progress_text" class="text-center">Preparing import...</p>
                    </div>
                </div>

                <div class="row" id="import_results" style="display: none;">
                    <div class="col-md-12">
                        <h4>Import Results</h4>
                        <div id="results_content"></div>
                    </div>
                </div>

            @endcomponent
        </div>
    </div>
</section>

@endsection

@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        $('#import_sold_items_form').on('submit', function(e) {
            e.preventDefault();
            
            var form = $(this);
            var submitBtn = $('#import_btn');
            var originalBtnText = submitBtn.html();
            
            // Disable form
            form.find('input, select, button').prop('disabled', true);
            submitBtn.html('<i class="fa fa-spinner fa-spin"></i> Importing...');
            
            // Show progress
            $('#import_progress').show();
            $('#import_results').hide();
            
            // Update progress bar
            var progressBar = $('#import_progress .progress-bar');
            progressBar.css('width', '10%').attr('aria-valuenow', 10);
            $('#progress_text').text('Starting import...');
            
            $.ajax({
                url: form.attr('action'),
                method: 'POST',
                data: form.serialize(),
                dataType: 'json',
                success: function(response) {
                    progressBar.css('width', '100%').attr('aria-valuenow', 100);
                    $('#progress_text').text('Import completed!');
                    
                    setTimeout(function() {
                        if (response.success) {
                            var stats = response.stats || {};
                            var resultsHtml = '<div class="alert alert-success">' +
                                '<h4><i class="fa fa-check-circle"></i> ' + response.msg + '</h4>' +
                                '<ul>' +
                                '<li><strong>Total Found:</strong> ' + (stats.total_found || 0) + '</li>' +
                                '<li><strong>Created:</strong> ' + (stats.created || 0) + '</li>' +
                                '<li><strong>Skipped:</strong> ' + (stats.skipped || 0) + '</li>' +
                                '<li><strong>Errors:</strong> ' + (stats.errors || 0) + '</li>' +
                                '</ul>';
                            
                            if (stats.errors > 0 && stats.errors_list && stats.errors_list.length > 0) {
                                resultsHtml += '<div class="alert alert-warning">' +
                                    '<strong>Errors:</strong><ul>';
                                stats.errors_list.slice(0, 10).forEach(function(error) {
                                    resultsHtml += '<li>' + error + '</li>';
                                });
                                if (stats.errors_list.length > 10) {
                                    resultsHtml += '<li>... and ' + (stats.errors_list.length - 10) + ' more errors</li>';
                                }
                                resultsHtml += '</ul></div>';
                            }
                            
                            resultsHtml += '</div>';
                            
                            $('#results_content').html(resultsHtml);
                        } else {
                            $('#results_content').html(
                                '<div class="alert alert-danger">' +
                                '<h4><i class="fa fa-exclamation-triangle"></i> Import Failed</h4>' +
                                '<p>' + (response.msg || 'An error occurred during import') + '</p>' +
                                '</div>'
                            );
                        }
                        
                        $('#import_results').show();
                        $('#import_progress').hide();
                        
                        // Re-enable form
                        form.find('input, select, button').prop('disabled', false);
                        submitBtn.html(originalBtnText);
                    }, 1000);
                },
                error: function(xhr) {
                    var errorMsg = 'An error occurred during import';
                    if (xhr.responseJSON && xhr.responseJSON.msg) {
                        errorMsg = xhr.responseJSON.msg;
                    }
                    
                    $('#results_content').html(
                        '<div class="alert alert-danger">' +
                        '<h4><i class="fa fa-exclamation-triangle"></i> Import Failed</h4>' +
                        '<p>' + errorMsg + '</p>' +
                        '</div>'
                    );
                    
                    $('#import_results').show();
                    $('#import_progress').hide();
                    
                    // Re-enable form
                    form.find('input, select, button').prop('disabled', false);
                    submitBtn.html(originalBtnText);
                }
            });
        });
    });
</script>
@endsection


