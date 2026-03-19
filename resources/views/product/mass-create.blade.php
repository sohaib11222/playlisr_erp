@extends('layouts.app')
@section('title', __('product.mass_add_new_products'))

@section('content')

    <!-- Content Header (Page header) -->
    <section class="content-header">
        <h1>@lang('product.mass_add_new_products')</h1>
        <!-- <ol class="breadcrumb">
            <li><a href="#"><i class="fa fa-dashboard"></i> Level</a></li>
            <li class="active">Here</li>
        </ol> -->
    </section>



    <style>
        /* Внешний контейнер с горизонтальной прокруткой */
        .responsive-table {
            width: 100%;
            max-width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
            border: 1px solid #ddd;
            margin: 20px 0;
        }

        #mass_create_table {
            white-space: nowrap;
            table-layout: fixed;
            width: 1350px;
            min-width: 1350px;
            max-width: 1350px;
        }

        #mass_create_table .thead .th,
        #mass_create_table .tbody .td {
            white-space: nowrap;
            min-width: 140px;
        }

        /* Product name column */
        #mass_create_table .col-name {
            min-width: 200px;
            width: 200px;
        }
        /* SKU - narrow */
        #mass_create_table .col-sku {
            min-width: 100px;
            width: 100px;
        }
        /* Category / Sub Category - need room for select2 + copy-down */
        #mass_create_table .col-select {
            min-width: 200px;
        }
        /* Artist (narrower) */
        #mass_create_table .col-artist {
            min-width: 100px;
        }
        /* Business Locations (narrower) */
        #mass_create_table .col-locations {
            min-width: 140px;
        }
        /* Action column - narrow */
        #mass_create_table .col-action {
            min-width: 50px !important;
            width: 50px;
        }

        .table-wrapper {
            display: table;
            width: 1350px;
            min-width: 1350px;
            max-width: 1350px;
            border-collapse: collapse;
        }

        /* Keep selects/inputs from stretching row width as new rows are added */
        #mass_create_table .td .form-control,
        #mass_create_table .td .select2-container {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            box-sizing: border-box;
        }

        /* Заголовок таблицы */
        .thead {
            display: table-header-group;
            background: #f5f5f5;
            font-weight: bold;
        }

        /* Тело таблицы */
        .tbody {
            display: table-row-group;
        }

        /* Подвал таблицы */
        .tfoot {
            display: table-footer-group;
            background: #f5f5f5;
        }

        /* Ряды таблицы */
        .tr {
            display: table-row;
            border-bottom: 1px solid #ddd;
        }

        .th, .td {
            display: table-cell;
            padding: 8px 6px;
            box-sizing: border-box;
            text-align: center;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            border-right: 1px solid #ddd;
        }

        /* Удаление правой границы у последней ячейки в ряду */
        .tr > .th:last-child,
        .tr > .td:last-child {
            border-right: none;
        }

        /* Подсветка строк тела при наведении */
        .tbody .tr:hover {
            background: #f9f9f9;
        }

        /* Стиль подвала */
        .tfoot .tr {
            display: table-row;
        }
        .tfoot .td {
            text-align: center;
            padding: 8px;
        }
        .tfoot .btn {
            width: 200px;
            margin: 8px auto;
            display: block;
        }

        .is-invalid {
            border-color: red;
        }
        .invalid-feedback {
            color: red;
            font-size: 0.9em;
        }

        /* Адаптивный режим */
        @media (max-width: 768px) {
            .table-wrapper {
                min-width: 1350px;
            }
            .th, .td {
                white-space: normal;
            }
            .tfoot .btn {
                width: 100%;
            }
        }

        .expandable {
            display: none;
        }

        #mass_create_table .price-col {
            min-width: 90px !important;
            max-width: 110px;
            width: 90px;
        }
        #mass_create_table .price-col .form-control {
            min-width: 70px;
            padding: 6px 4px;
            text-align: right;
        }

        .price-recomendation-card-wrapper {
            display: flex;
            flex-direction: row;
            gap: 10px;
        }

        /* Price Recommendation Styles - REMOVED: eBay/Discogs suggestions disabled */
        /* Subcategory Suggestions Styles */
        .sub-category-suggestion-item {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 15px;
            background: linear-gradient(145deg, #f6f8fa, #ffffff);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border: 1px solid #e1e4e8;
            border-radius: 8px;
            margin: 10px 0;
        }

        .sub-category-suggestion-item h4 {
            color: #24292e;
            margin: 0 0 12px 0;
            font-size: 14px;
            font-weight: 600;
            border-bottom: 1px solid #e1e4e8;
            padding-bottom: 8px;
            width: 100%;
        }

        .sub-category-suggestion-item-name {
            display: inline-block;
            padding: 4px 12px;
            background: #e9ecef;
            border-radius: 16px;
            font-size: 13px;
            color: #495057;
            cursor: pointer;
            transition: all 0.2s ease;
            border: 1px solid #dee2e6;
        }

        .sub-category-suggestion-item-name:hover {
            background: #dee2e6;
            color: #212529;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        /* Bulk text entry enhancements */
        #bulk_preview_container {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            background: #f9f9f9;
        }
        
        #bulk_preview_table {
            background: white;
            border-radius: 4px;
        }
        
        #bulk_preview_table table {
            font-size: 12px;
        }
        
        #bulk_preview_table th {
            background: #f5f5f5;
            font-weight: 600;
            padding: 8px;
        }
        
        #bulk_preview_table td {
            padding: 6px 8px;
        }
        
        #bulk_product_text {
            border: 2px solid #ddd;
            transition: border-color 0.3s;
        }
        
        #bulk_product_text:focus {
            border-color: #3c8dbc;
            box-shadow: 0 0 5px rgba(60, 141, 188, 0.3);
        }
        
        kbd {
            background: #f4f4f4;
            border: 1px solid #ccc;
            border-radius: 3px;
            padding: 2px 6px;
            font-size: 11px;
            font-family: monospace;
            box-shadow: 0 1px 0 rgba(0,0,0,0.2);
        }
    </style>

    {!! Form::open(['url' => action('ProductController@massStore'), 'method' => 'post', 'id' => 'mass_create_form', 'enctype' => 'multipart/form-data' ]) !!}

    <!-- Bulk Text Entry Section (collapsed by default) -->
    <div class="box box-primary collapsed-box" style="margin-bottom: 20px;">
        <div class="box-header with-border" style="cursor: pointer;" data-widget="collapse">
            <h3 class="box-title">
                <i class="fa fa-file-text"></i> Bulk Product Entry
            </h3>
            <div class="box-tools pull-right">
                <button type="button" class="btn btn-sm btn-primary" data-widget="collapse">
                    <i class="fa fa-plus"></i> Open Bulk Entry
                </button>
            </div>
        </div>
        <div class="box-body" style="display: none;">
            <div class="form-group">
                <label for="bulk_product_text">
                    <strong>Paste products here (one per line).</strong> Smart parser supports multiple formats:
                </label>
                <div class="alert alert-info" style="margin-bottom: 10px; padding: 10px;">
                    <strong>Supported Formats:</strong>
                    <ul style="margin-bottom: 0; padding-left: 20px;">
                        <li><code>Product Name - Artist</code> (Simple format)</li>
                        <li><code>Product Name | Artist | Category | Subcategory | SKU | Price | Bin | Location</code> (Pipe-delimited)</li>
                        <li><code>Product Name,Artist,Category,Subcategory,SKU,Price,Bin,Location</code> (CSV format)</li>
                        <li><code>Product Name	Artist	Category	Subcategory	SKU	Price	Bin	Location</code> (Tab-delimited)</li>
                        <li>Auto-complete suggestions appear as you type!</li>
                    </ul>
                </div>
                <textarea 
                    id="bulk_product_text" 
                    class="form-control" 
                    rows="12" 
                    placeholder="Example formats:&#10;Album Title - Artist Name&#10;Album Title | Artist Name | Category | Subcategory | SKU123 | 19.99 | A-12 | Warehouse A&#10;Album Title,Artist Name,Category,Subcategory,SKU123,19.99,A-12,Warehouse A&#10;&#10;Start typing to see auto-complete suggestions from your existing products..."
                    style="font-family: 'Courier New', monospace; font-size: 13px; line-height: 1.6;"></textarea>
                <small class="text-muted">
                    <i class="fa fa-lightbulb-o"></i> <strong>Tip:</strong> As you type product names, suggestions from your existing database will appear. 
                    Press <kbd>Tab</kbd> or <kbd>Enter</kbd> to accept suggestions.
                </small>
            </div>
            <div class="form-group">
                <button type="button" class="btn btn-success" id="parse_bulk_text">
                    <i class="fa fa-magic"></i> Parse & Add Products
                </button>
                <button type="button" class="btn btn-info" id="preview_bulk_text">
                    <i class="fa fa-eye"></i> Preview Parsed Data
                </button>
                <button type="button" class="btn btn-default" id="clear_bulk_text">
                    <i class="fa fa-eraser"></i> Clear
                </button>
                <button type="button" class="btn btn-warning" id="format_bulk_text">
                    <i class="fa fa-align-left"></i> Auto-Format
                </button>
                <span class="text-muted" id="bulk_parse_status" style="margin-left: 15px;"></span>
            </div>
            <div id="bulk_preview_container" style="display: none; margin-top: 15px;">
                <div class="alert alert-warning">
                    <strong>Preview:</strong> <span id="bulk_preview_count">0</span> products detected
                </div>
                <div id="bulk_preview_table" style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">
                    <!-- Preview will be inserted here -->
                </div>
            </div>
        </div>
    </div>

    <div class="responsive-table">
        <table class="table-wrapper" id="mass_create_table">
            <!-- Шапка таблицы с восстановленными колонками -->
            <thead class="thead">
                <tr class="tr">
                    <th class="th col-name">@lang('product.product_name')*</th>
                    <th class="th col-sku">@lang('product.sku')</th>
                    <th class="th col-select">@lang('product.category') / @lang('product.sub_category')</th>
                    <th class="th col-artist">Artist</th>
                    <th class="th col-locations">@lang('business.business_locations')</th>
                    <th class="th price-col">Purchase Price</th>
                    <th class="th price-col">Selling Price</th>
                    <th class="th" style="min-width: 60px; width: 60px;">
                        <button type="button" class="btn btn-primary btn-xs show-expandables">
                            More
                        </button>
                    </th>
                    <th class="th expandable">Bin Position</th>
                    <th class="th expandable">Listing Location</th>
                    <th class="th expandable">Product Image Url</th>
                    <th class="th expandable">Upload Product Image</th>
                    <th class="th expandable">Product Description</th>
                    <th class="th col-action">@lang('messages.action')</th>
                </tr>
            </thead>

            <!-- Тело таблицы -->
            <tbody class="tbody" id="product_rows_container">
                @include('product.partials.mass_product_row', ['index' => 0])
                <!-- Добавляйте новые .tr для каждой новой строки продукта -->
            </tbody>

            <!-- Подвал таблицы -->
            <tfoot class="tfoot">
                <tr class="tr">
                    <td class="td" colspan="1">
                        <button type="button" class="btn btn-primary" id="add_row">
                            Add New Product Row
                        </button>
                    </td>
                </tr>
                <tr class="tr">
                    <td class="td" colspan="1">
                        <div id="mass_add_action_buttons" style="display: flex; flex-wrap: nowrap; gap: 10px; align-items: center; white-space: nowrap; overflow-x: auto;">
                            <button type="button" class="btn btn-info" id="verify_all_categories">
                                <i class="fa fa-check-circle"></i> Verify All Categories
                            </button>
                            <button type="button" class="btn btn-success" id="save_all_products">Save All Products</button>
                            <button type="button" class="btn btn-warning" id="save_and_send_to_purchase" style="font-weight: bold;">
                                <i class="fa fa-save"></i> Save &amp; send to add purchase
                            </button>
                        </div>
                    </td>
                </tr>
            </tfoot>


        </table>
    </div>

    {!! Form::close() !!}

    <!-- Discogs Price Suggestions Modal - REMOVED: eBay/Discogs suggestions disabled -->

@endsection


@section('javascript')
@php $asset_v = env('APP_VERSION'); @endphp
<script src="{{ asset('js/product.js?v=' . $asset_v) }}"></script>

<script type="text/javascript">
    window.isAddingNewRow = false;
    $(document).ready(function(){
        let rowIndex = 1;
        // Add a new row
        // Add new row
        $('#add_row').on('click', function () {
            if (window.isAddingNewRow) {
                return;
            }

            window.isAddingNewRow = true;
            $(this).html('Adding row...');
            $.ajax({
                url: "{{ route('product.getMassProductRow') }}",
                type: 'GET',
                data: { index: rowIndex },
                success: function (row) {
                    $('#product_rows_container').append(row);
                    rowIndex++;

                    // Reinitialize Select2 for new elements
                    $('#product_rows_container .product-row').last().find('.select2').select2();
                    applyCategoryComboSelect2Matcher($('#product_rows_container .product-row').last());
                    window.setupProductNameSelect2();
                    window.isAddingNewRow = false;
                    $('#add_row').html('Add New Product Row');
                },
                error: function () {
                    toastr.error('Failed to add a new row.');
                    window.isAddingNewRow = false;
                    $('#add_row').html('Add New Product Row');
                },
            });
        });

        // Copy down feature
        $(document).on('click', '.copy-down', function() {
            const rowIndex = parseInt($(this).attr('data-row-index'));
            const inputClass = $(this).attr('data-class');
            const row = $('#product_rows_container .product-row').eq(rowIndex);

            const value = row.find(`.${inputClass}`).val();

            $('#product_rows_container .product-row')
                .slice(rowIndex + 1) // Ambil semua baris setelah rowIndex
                .each(function() {
                    $(this).find(`.${inputClass}`).val(value).trigger('change');
                });
        });

        // Remove row
        $(document).on('click', '.remove_row', function () {
            $(this).closest('.tr').remove();
        });

        // When merged Category/Subcategory combo changes, sync hidden ids
        $(document).on('change', '.category-combo-select', function () {
            const $this = $(this);
            const rowIndex = $this.attr('data-row-index');
            const selected = $this.find('option:selected');
            const categoryId = selected.data('category-id') || '';
            const subCategoryId = selected.data('sub-category-id') || 0;

            $(`#products_${rowIndex}_category_id`).val(categoryId);
            $(`#products_${rowIndex}_sub_category_id`).val(subCategoryId);
        });

        // Store valid subcategory IDs for each category
        window.categorySubcategories = {};

        // Handle category change to fetch subcategories
        $(document).on('change', '.category-select', function () {
            const $this = $(this);
            const category_id = $this.val();
            const rowIndex = $this.attr('data-row-index');
            const subCategorySelect = $this.closest('.tr').find('.subcategory-select');
            const $row = $this.closest('.tr');

            // Verify category immediately
            verifyCategorySubcategoryMatch($row, rowIndex);

            // window.getProductPriceRecommendation($(this).attr('data-row-index')); // DISABLED: eBay/Discogs suggestions

            if (category_id) {
                // Check if category exists in options
                const categoryOption = $this.find('option[value="' + category_id + '"]');
                if (categoryOption.length === 0 || !category_id || category_id === '') {
                    // Invalid category
                    verifyCategorySubcategoryMatch($row, rowIndex);
                    return;
                }
                
                $.ajax({
                    url: "{{ route('product.get_sub_categories') }}",
                    type: 'POST',
                    data: { cat_id: category_id },
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    success: function (data) {
                        subCategorySelect.html(data);
                        
                        // Store valid subcategory IDs for this category
                        const validSubcategoryIds = [];
                        // Parse the HTML string to extract option values
                        const $temp = $('<div>').html(data);
                        $temp.find('option').each(function() {
                            const val = $(this).val();
                            if (val && val !== '') {
                                validSubcategoryIds.push(val);
                            }
                        });
                        window.categorySubcategories[category_id] = validSubcategoryIds;
                        
                        // Reinitialize Select2
                        subCategorySelect.trigger('change');
                        
                        // Verify current subcategory selection after a short delay to ensure Select2 is updated
                        setTimeout(function() {
                            verifyCategorySubcategoryMatch($row, rowIndex);
                        }, 100);
                    },
                    error: function () {
                        toastr.error('Failed to fetch subcategories.');
                        verifyCategorySubcategoryMatch($row, rowIndex);
                    },
                });
            } else {
                subCategorySelect.html('<option value="">@lang("messages.please_select")</option>');
                subCategorySelect.trigger('change');
                verifyCategorySubcategoryMatch($row, rowIndex);
            }
        });

        // Verify category/subcategory match
        function verifyCategorySubcategoryMatch($row, rowIndex) {
            const categorySelect = $row.find('.category-select');
            const categoryId = categorySelect.val();
            const subcategoryId = $row.find('.subcategory-select').val();
            
            // Remove existing indicators
            $row.find('.category-validation-indicator').remove();
            $row.find('.subcategory-validation-indicator').remove();
            
            // Check if category exists in dropdown options
            const categoryOption = categorySelect.find('option[value="' + categoryId + '"]');
            const categoryExists = categoryOption.length > 0 && categoryId && categoryId !== '';
            
            // Add validation indicator for category
            const categoryContainer = $row.find('.category-selection-container');
            if (!categoryContainer.find('.category-validation-indicator').length) {
                categoryContainer.append('<span class="category-validation-indicator" style="margin-left: 5px;"></span>');
            }
            
            if (!categoryId || categoryId === '') {
                // Category is optional; clear validation state when empty
                categorySelect.removeClass('is-invalid');
                $row.find('.subcategory-select').removeClass('is-invalid');
                $row.find('.category-validation-indicator').html('');
                return;
            }
            
            if (!categoryExists) {
                // Category doesn't exist in dropdown (invalid)
                $row.find('.category-validation-indicator').html('<i class="fa fa-times-circle text-danger" title="Invalid category - not found in system"></i>');
                categorySelect.addClass('is-invalid');
                return;
            } else {
                categorySelect.removeClass('is-invalid');
            }
            
            // Category is valid
            $row.find('.category-validation-indicator').html('<i class="fa fa-check-circle text-success" title="Valid category"></i>');
            
            if (subcategoryId) {
                // Check if subcategory belongs to category
                const validSubcategories = window.categorySubcategories[categoryId] || [];
                const isValid = validSubcategories.includes(subcategoryId);
                
                const subcategoryContainer = $row.find('.subcategory-select').parent();
                if (!subcategoryContainer.find('.subcategory-validation-indicator').length) {
                    subcategoryContainer.append('<span class="subcategory-validation-indicator" style="margin-left: 5px;"></span>');
                }
                
                const indicator = $row.find('.subcategory-validation-indicator');
                if (isValid) {
                    indicator.html('<i class="fa fa-check-circle text-success" title="Valid subcategory for this category"></i>');
                    $row.find('.subcategory-select').removeClass('is-invalid');
                } else {
                    indicator.html('<i class="fa fa-exclamation-triangle text-danger" title="This subcategory does not belong to the selected category"></i>');
                    $row.find('.subcategory-select').addClass('is-invalid');
                }
            } else {
                // No subcategory selected, but category is valid
                $row.find('.subcategory-select').removeClass('is-invalid');
            }
        }

        // Handle subcategory change to verify match
        $(document).on('change', '.subcategory-select', function () {
            const $this = $(this);
            const rowIndex = $this.closest('.tr').find('.category-select').attr('data-row-index');
            const $row = $this.closest('.tr');
            verifyCategorySubcategoryMatch($row, rowIndex);
        });
        
        // Validate categories on page load and when Select2 is opened/closed
        $(document).on('select2:open select2:close', '.category-select', function() {
            const $row = $(this).closest('.tr');
            const rowIndex = $(this).attr('data-row-index');
            setTimeout(function() {
                verifyCategorySubcategoryMatch($row, rowIndex);
            }, 100);
        });
        
        // Also validate when Select2 selection changes (for manual entry)
        $(document).on('select2:select select2:unselect', '.category-select', function() {
            const $row = $(this).closest('.tr');
            const rowIndex = $(this).attr('data-row-index');
            setTimeout(function() {
                verifyCategorySubcategoryMatch($row, rowIndex);
            }, 100);
        });

        // Verify all rows
        $('#verify_all_categories').on('click', function() {
            const rows = $('#product_rows_container .product-row');
            let validCount = 0;
            let invalidCount = 0;
            let missingSubcategoryCount = 0;
            const invalidRows = [];

            rows.each(function() {
                const $row = $(this);
                const rowIndex = $row.find('.category-select').attr('data-row-index');
                const categoryId = $row.find('.category-select').val();
                const subcategoryId = $row.find('.subcategory-select').val();
                const productName = $row.find('.product-name-autocomplete').val();

                // Verify this row
                verifyCategorySubcategoryMatch($row, rowIndex);

                if (!categoryId) {
                    // Category is optional; count this as valid if no category is selected.
                    validCount++;
                } else if (subcategoryId) {
                    // Check if subcategory belongs to category
                    const validSubcategories = window.categorySubcategories[categoryId] || [];
                    if (validSubcategories.includes(subcategoryId)) {
                        validCount++;
                    } else {
                        invalidCount++;
                        invalidRows.push({
                            row: rowIndex,
                            product: productName || 'Unnamed product',
                            issue: 'Subcategory does not belong to selected category'
                        });
                    }
                } else {
                    missingSubcategoryCount++;
                    // Category selected but no subcategory - this is OK, just count it
                    validCount++;
                }
            });

            // Show summary
            let summaryHtml = '<div class="alert alert-info"><h4><i class="fa fa-info-circle"></i> Verification Summary</h4>';
            summaryHtml += '<ul style="margin-bottom: 0;">';
            summaryHtml += `<li><strong>Valid:</strong> <span class="text-success">${validCount}</span> rows</li>`;
            if (invalidCount > 0) {
                summaryHtml += `<li><strong>Invalid:</strong> <span class="text-danger">${invalidCount}</span> rows (subcategory mismatch)</li>`;
            }
            if (missingSubcategoryCount > 0) {
                summaryHtml += `<li><strong>Missing Subcategory:</strong> <span class="text-info">${missingSubcategoryCount}</span> rows (category selected but no subcategory)</li>`;
            }
            summaryHtml += '</ul>';

            if (invalidRows.length > 0) {
                summaryHtml += '<hr><h5>Issues Found:</h5><ul>';
                invalidRows.forEach(item => {
                    summaryHtml += `<li>Row ${parseInt(item.row) + 1}: "${item.product}" - ${item.issue}</li>`;
                });
                summaryHtml += '</ul>';
            }
            summaryHtml += '</div>';

            // Show in a modal or alert
            swal({
                title: 'Category Verification Complete',
                content: {
                    element: 'div',
                    attributes: {
                        innerHTML: summaryHtml
                    }
                },
                icon: invalidCount > 0 ? 'warning' : 'success',
                buttons: {
                    confirm: {
                        text: 'OK',
                        className: 'btn btn-primary'
                    }
                }
            });
        });

        // Tooltip для элементов
        $('[data-toggle="tooltip"]').tooltip();


        // Tooltip initialization for dynamically added elements
        $(document).on('mouseenter', '[data-toggle="tooltip"]', function () {
            $(this).tooltip('show');
        });

        // Реинициализация Select2 для уже существующих строк
        $('.select2').select2();

        // Improved category/subcategory search:
        // tokenized prefix matching against the combo label (e.g. "used rock" => "Used Vinyl - Rock")
        function __massadd_tokenize_prefix_words(text) {
            if (text === undefined || text === null) return [];
            return String(text)
                .toLowerCase()
                .trim()
                .split(/[^a-z0-9]+/g)
                .filter(Boolean);
        }

        function __massadd_category_combo_matcher(params, data) {
            if (!data || !data.text) return data;

            var term = params && params.term ? String(params.term).trim().toLowerCase() : '';
            if (term === '') return data;

            var labelText = String(data.text || '').toLowerCase();
            var tokens = __massadd_tokenize_prefix_words(term);
            if (tokens.length === 0) return data;

            var words = labelText.match(/[a-z0-9]+/g) || [];
            var matchedAll = tokens.every(function (tok) {
                if (!tok) return true;
                return labelText.indexOf(tok) !== -1 || words.some(function (w) { return w.indexOf(tok) === 0; });
            });

            return matchedAll ? data : null;
        }

        function applyCategoryComboSelect2Matcher($scope) {
            var $root = $scope && $scope.length ? $scope : $('#product_rows_container');
            $root.find('select.category-combo-select').each(function () {
                var $el = $(this);
                var currentVal = $el.val();
                try {
                    if ($el.data('select2')) {
                        $el.select2('destroy');
                    }
                } catch (e) {
                    // ignore; keep default if re-init fails
                }

                $el.select2({
                    matcher: __massadd_category_combo_matcher
                });

                if (currentVal !== null && currentVal !== undefined && currentVal !== '') {
                    $el.val(currentVal).trigger('change.select2');
                }
            });
        }

        applyCategoryComboSelect2Matcher($('#product_rows_container'));

        // Note: opening stock/location-level stock editing has been removed from mass-add.

        $(document).on('click', '.show-expandables', function() {
            if ($(this).hasClass('show')) {
                $('.expandable').hide();    
            } else {
                $('.expandable').css('display', 'table-cell');
            }

            $(this).toggleClass('show');
        });

        $(document).on('change', 'input[type="file"]', function () {
            const fileName = $(this).val().split('\\').pop();
            $(this).siblings('.custom-file-label').addClass("selected").html(fileName);
        });

        $(document).on('click', '.btn-remove-product-selection', function() {
            window.setAsFreeTextProductRow($(this).attr('data-row-index'));
        });

        window.massAddThenSendToPurchase = false;

        function runMassAddSave(sendToPurchase) {
            window.massAddThenSendToPurchase = !!sendToPurchase;
            $('#save_all_products').trigger('click');
        }

        $('#save_and_send_to_purchase').on('click', function(e) {
            e.preventDefault();
            runMassAddSave(true);
        });

        // Обработка клика по кнопке "Save All Products" с отладкой
        $('#save_all_products').on('click', function(e){
            e.preventDefault();  // Предотвращаем стандартную отправку формы
                        
            // Clear previous error messages
            $('.error-message').remove();
            $('.is-invalid').removeClass('is-invalid');
            
            // Validate all categories before submission
            let hasErrors = false;
            let errorMessages = [];
            const rows = $('#product_rows_container .product-row');
            
            rows.each(function(index) {
                const $row = $(this);
                const rowIndex = $row.attr('data-row-index');
                const categorySelect = $row.find('.category-select');
                const categoryId = categorySelect.val();
                const subcategorySelect = $row.find('.subcategory-select');
                const subcategoryId = subcategorySelect.val();
                const productName = $row.find('.product-name-autocomplete').val() || `Product ${parseInt(rowIndex) + 1}`;
                
                // Category is optional; validate only when provided
                if (categoryId && categoryId !== '') {
                    // Check if category exists in dropdown options
                    const categoryOption = categorySelect.find('option[value="' + categoryId + '"]');
                    if (categoryOption.length === 0) {
                        hasErrors = true;
                        categorySelect.addClass('is-invalid');
                        const errorMsg = `Row ${parseInt(rowIndex) + 1} (${productName}): Invalid category - not found in system`;
                        errorMessages.push(errorMsg);
                        categorySelect.closest('td').append(`<div class="invalid-feedback error-message" style="display: block; color: red; font-size: 12px;">Invalid category - not found in system</div>`);
                    } else if (subcategoryId && subcategoryId !== '') {
                        // Check subcategory if selected
                        const validSubcategories = window.categorySubcategories[categoryId] || [];
                        if (!validSubcategories.includes(subcategoryId)) {
                            hasErrors = true;
                            subcategorySelect.addClass('is-invalid');
                            const errorMsg = `Row ${parseInt(rowIndex) + 1} (${productName}): Subcategory does not belong to selected category`;
                            errorMessages.push(errorMsg);
                            subcategorySelect.closest('td').append(`<div class="invalid-feedback error-message" style="display: block; color: red; font-size: 12px;">Subcategory does not belong to selected category</div>`);
                        }
                    }
                }
            });
            
            // If validation errors found, show them and prevent submission
            if (hasErrors) {
                toastr.error('Please fix the following errors before saving:\n' + errorMessages.join('\n'), 'Validation Errors', {
                    timeOut: 10000,
                    extendedTimeOut: 10000
                });
                
                // Scroll to first error
                const firstError = $('.is-invalid').first();
                if (firstError.length) {
                    $('html, body').animate({
                        scrollTop: firstError.offset().top - 100
                    }, 500);
                }
                
                return false;
            }

            let form = $('#mass_create_form')[0];
            let formData = new FormData(form);  // Собираем все данные формы

            // console.log('Submitting form data...');
            // for (let pair of formData.entries()) {
            //     console.log(pair[0]+ ': ' + pair[1]);
            // }

            $.ajax({
                url: $('#mass_create_form').attr('action'),
                type: $('#mass_create_form').attr('method'),
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if(response.success) {
                        toastr.success(response.msg);
                        document.getElementById('success-audio').play();
                        const product_ids = response.product_ids;
                        var fromPurchase = window !== window.top && /from_purchase=1/.test(window.location.search);
                        if (fromPurchase && product_ids && product_ids.length) {
                            window.parent.postMessage({ type: 'massAddComplete', product_ids: product_ids }, '*');
                            return;
                        }
                        if (window.massAddThenSendToPurchase && product_ids && product_ids.length) {
                            window.massAddThenSendToPurchase = false;
                            var baseUrl = "{{ url('') }}";
                            window.location.href = baseUrl + '/purchases/create?from_products=' + product_ids.join(',');
                            return;
                        }
                        setTimeout(() => {
                            if (window.confirm("Do you want to print the labels?")) {
                                window.location.href = `/labels/show?product_ids=${product_ids.join(",")}`;
                            } else {
                                window.location.href = `/products`;
                            }
                        }, 300);
                    } else {
                        toastr.error('Error: ' + response.msg);
                        document.getElementById('error-audio').play();
                    }
                },
                error: function(xhr) {
                    if (xhr.status === 422) {
                        // Handle validation errors
                        let errors = xhr.responseJSON.errors;
                        let errorMessages = [];
                        
                        // Clear previous error messages
                        $('.error-message').remove();
                        $('.is-invalid').removeClass('is-invalid');
                        
                        // Helper function to add error message
                        function addError(inputSelector, errorMessage) {
                            let $input = $(inputSelector);
                            if ($input.length) {
                                $input.addClass('is-invalid');
                                let $errorDiv = $('<div>')
                                    .addClass('invalid-feedback error-message')
                                    .text(errorMessage);
                                $input.closest('td').append($errorDiv);
                            }
                        }
                        
                        // Process each error
                        Object.keys(errors).forEach(function(key) {
                            // Extract product index and field name from the key (e.g., "products.0.name")
                            let parts = key.split('.');
                            let productIndex = parts[1];
                            let fieldName = parts[2];
                            
                            // Add error message based on field type
                            if (fieldName === 'business_locations') {
                                addError(`[name="products[${productIndex}][business_locations][]"]`, errors[key][0]);
                            } else {
                                addError(`[name="products[${productIndex}][${fieldName}]"]`, errors[key][0]);
                            }
                            
                            // Add to error messages array for toastr
                            errorMessages.push(errors[key][0]);
                        });
                        
                        // Show all error messages in toastr
                        // if (errorMessages.length > 0) {
                        //     toastr.error(errorMessages.join('<br>'));
                        // }
                    } else {
                        toastr.error('An unexpected error occurred. Please try again.');
                    }
                }
            });
        });

        window.setupProductNameSelect2();
    
    // Ensure all buttons are properly bound (in case of duplicate IDs or timing issues)
    console.log('Mass create page initialized');
    console.log('Parse button found:', $('#parse_bulk_text').length);
    console.log('Preview button found:', $('#preview_bulk_text').length);
    console.log('Clear button found:', $('#clear_bulk_text').length);
    console.log('Format button found:', $('#format_bulk_text').length);
    });

    window.setAsFreeTextProductRow = function(rowIndex) {
        $(`.btn-remove-product-selection[data-row-index="${rowIndex}"]`).remove();
        $(`.product-name-autocomplete[data-row-index="${rowIndex}"]`).val("").attr('disabled', false);
        $(`.product-row[data-row-index="${rowIndex}"] .td[data-hide-on-selection='yes']`).find('input,div,span,textarea,select').show();
        $(`.product-row[data-row-index="${rowIndex}"] .select2_business_locations`).val(null).trigger('change');
        $(`.product-row[data-row-index="${rowIndex}"] .product-id`).val('');
        $(`.product-row[data-row-index="${rowIndex}"] .variation-id`).val('');
    }

    window.setAsSelectedProductRow = function(ui, input) {
        const rowIndex = input.attr('data-row-index');
        const item = ui.item;
        const openingLocations = item.opening_locations || [];
        const locationIds = openingLocations.map(n => n.id);
        // Disable input
        input.val(ui.item.text).attr('disabled', true);

        // Show remove button
        input.after(`<button type="button" class="btn btn-xs btn-remove-product-selection" data-row-index="${rowIndex}" style="min-width: 40px; font-size: 15px;">
            <i class="fa fa-times-circle"></i>
        </button>`)

        // Hide other columns
        $(`.product-row[data-row-index="${rowIndex}"] .td[data-hide-on-selection='yes']`).find('input,div,span,textarea,select').hide();
        $(`.product-row[data-row-index="${rowIndex}"] .select2_business_locations`).val(locationIds).trigger('change');
        $(`.product-row[data-row-index="${rowIndex}"] .product-id`).val(item.product_id);
        $(`.product-row[data-row-index="${rowIndex}"] .variation-id`).val(item.variation_id);
    }

    // DISABLED: eBay/Discogs price recommendations removed to reduce row height
    // window.discogsReleasesData = [];
    // window.getProductPriceRecommendation = (function() { ... })(); // REMOVED
    
    // Subcategory suggestions still work via separate API call
    window.getSubcategorySuggestions = (function() {
        let timeout;
        return function(rowIndex) {
            clearTimeout(timeout);
            const productName = $(".product-name-autocomplete[data-row-index='" + rowIndex + "']").val();
            const categoryId = $(`#products_${rowIndex}_category_id`).val();
            const subCategorySuggestionsContainer = $(`.sub-category-suggestions-container[data-row-index='${rowIndex}']`);
            subCategorySuggestionsContainer.html("");

            timeout = setTimeout(function() {
                $.getJSON('/product/mass-create/get-product-price-recommendation', {
                    query: productName,
                    category_id: categoryId,
                    row_index: rowIndex
                }, function(response) {
                    rowIndex = response.row_index;
                    const discogs_price_recommendation_sub_categories = response.discogs_price_recommendation_sub_categories;
                    if (discogs_price_recommendation_sub_categories && discogs_price_recommendation_sub_categories.length > 0) {
                        subCategorySuggestionsContainer.html(`
                            <div class="sub-category-suggestion-item">
                                <h4>Subcategory Suggestions</h4>
                                ${discogs_price_recommendation_sub_categories.map(subCategory => `<span class="sub-category-suggestion-item-name">${subCategory}</span>`).join('')}
                            </div>
                        `);
                    } else {
                        subCategorySuggestionsContainer.html("");
                    }
                });
            }, 500);
        };
    })();

    $(document).on('keyup', '.product-name-autocomplete', function() {
        window.getSubcategorySuggestions($(this).attr('data-row-index'));
    });

    $(document).on('keyup', '.sku-input', function() {
        window.getSubcategorySuggestions($(this).attr('data-row-index'));
    });

    window.setupProductNameSelect2 = function () {
        try {
            $(".product-name-autocomplete").each(function () {
                $(this).autocomplete({
                    source: function(request, response) {
                        $.getJSON('/product/mass-create/get-products', { term: request.term }, response);
                    },
                    minLength: 3,
                    autoFocus: true,
                    response: function(event, ui) {
                        if (ui.content.length == 1) {
                            // Auto-select if only one result
                            setTimeout(() => {
                                $(this).data('ui-autocomplete').menu.activate();
                                $(this).data('ui-autocomplete').menu.select();
                            }, 100);
                        } else if (ui.content.length == 0) {
                            var term = $(this).data('ui-autocomplete').term;
                            
                            // swal({
                            //     title: LANG.no_products_found,
                            //     text: __translate('add_name_as_new_product', { term: term }),
                            //     buttons: [LANG.cancel, LANG.ok],
                            // }).then(value => {
                            //     if (value) {
                            //         var container = $('.quick_add_product_modal');
                            //         $.ajax({
                            //             url: '/products/quick_add?product_name=' + term,
                            //             dataType: 'html',
                            //             success: function(result) {
                            //                 $(container)
                            //                     .html(result)
                            //                     .modal('show');
                            //             },
                            //         });
                            //     }
                            // });
                        }
                    },
                    select: function(event, ui) {
                        event.preventDefault();
                        $(this).val(ui.item.text);
                            window.setAsSelectedProductRow(ui, $(this));
                        $(this).autocomplete('close');
                        return false;
                    },
                    focus: function(event, ui) {
                        event.preventDefault();
                        return false;
                    }
                }).autocomplete('instance')._renderItem = function(ul, item) {
                    return $('<li>').append('<div>' + item.text + '</div>').appendTo(ul);
                };
                
                // Handle Enter key to submit autocomplete
                $(this).on('keydown', function(event) {
                    if (event.keyCode === 13) { // Enter key
                        const autocomplete = $(this).data('ui-autocomplete');
                        if (autocomplete && autocomplete.menu.active) {
                            event.preventDefault();
                            autocomplete.menu.select();
                        }
                    }
                });
            });
                    
        } catch (error) {
            console.log("ERRROR : ", error);
        }
    }

    // Enhanced bulk text parsing functionality with smart format detection
    function parseBulkProductText(text) {
        const lines = text.split('\n').filter(line => line.trim() !== '');
        const products = [];
        
        lines.forEach((line, index) => {
            line = line.trim();
            if (!line || line.startsWith('//') || line.startsWith('#')) return; // Skip comments
            
            let product = {
                name: '',
                artist: '',
                category: '',
                subcategory: '',
                sku: '',
                price: '',
                bin_position: '',
                listing_location: '',
                lineNumber: index + 1
            };
            
            // Detect format by checking delimiters
            const hasPipe = line.includes('|');
            const hasComma = line.includes(',');
            const hasTab = line.includes('\t');
            const hasDash = line.includes(' - ') || line.includes(' – '); // Regular dash or en-dash
            const hasMultipleSpaces = /\s{2,}/.test(line);
            
            // Priority: Tab > Pipe > Comma > Multiple Spaces > Dash > Simple
            if (hasTab) {
                // Tab-delimited format
                const parts = line.split('\t').map(p => p.trim());
                product.name = parts[0] || '';
                product.artist = parts[1] || '';
                product.category = parts[2] || '';
                product.subcategory = parts[3] || '';
                product.sku = parts[4] || '';
                product.price = parts[5] || '';
                product.bin_position = parts[6] || '';
                product.listing_location = parts[7] || '';
            }
            else if (hasPipe) {
                // Pipe-delimited format: Product | Artist | Category | Subcategory | SKU | Price | Bin | Listing Location
                const parts = line.split('|').map(p => p.trim());
                product.name = parts[0] || '';
                product.artist = parts[1] || '';
                product.category = parts[2] || '';
                product.subcategory = parts[3] || '';
                product.sku = parts[4] || '';
                product.price = parts[5] || '';
                product.bin_position = parts[6] || '';
                product.listing_location = parts[7] || '';
            }
            else if (hasComma && line.split(',').length >= 2) {
                // CSV format: Product,Artist,Category,Subcategory,SKU,Price,Bin,Listing Location
                // Handle quoted CSV values
                const parts = [];
                let current = '';
                let inQuotes = false;
                
                for (let i = 0; i < line.length; i++) {
                    const char = line[i];
                    if (char === '"') {
                        inQuotes = !inQuotes;
                    } else if (char === ',' && !inQuotes) {
                        parts.push(current.trim());
                        current = '';
                    } else {
                        current += char;
                    }
                }
                parts.push(current.trim()); // Add last part
                
                product.name = parts[0] || '';
                product.artist = parts[1] || '';
                product.category = parts[2] || '';
                product.subcategory = parts[3] || '';
                product.sku = parts[4] || '';
                product.price = parts[5] || '';
                product.bin_position = parts[6] || '';
                product.listing_location = parts[7] || '';
            }
            else if (hasMultipleSpaces) {
                // Multiple spaces as delimiter (common in copied text)
                const parts = line.split(/\s{2,}/).map(p => p.trim());
                product.name = parts[0] || '';
                product.artist = parts[1] || '';
                product.category = parts[2] || '';
                product.subcategory = parts[3] || '';
                product.sku = parts[4] || '';
                product.price = parts[5] || '';
                product.bin_position = parts[6] || '';
                product.listing_location = parts[7] || '';
            }
            else if (hasDash) {
                // Dash format: Product - Artist or Product – Artist
                const dashIndex = line.indexOf(' - ') !== -1 ? line.indexOf(' - ') : line.indexOf(' – ');
                product.name = line.substring(0, dashIndex).trim();
                product.artist = line.substring(dashIndex + 3).trim();
            }
            else {
                // Just product name
                product.name = line;
            }
            
            // Clean up price (remove $, commas, etc.)
            if (product.price) {
                product.price = product.price.replace(/[$,]/g, '').trim();
            }
            
            if (product.name) {
                products.push(product);
            }
        });
        
        return products;
    }
    
    // Show preview of parsed products
    function showBulkPreview(products) {
        const container = $('#bulk_preview_container');
        const table = $('#bulk_preview_table');
        const count = $('#bulk_preview_count');
        
        if (products.length === 0) {
            container.hide();
            return;
        }
        
        count.text(products.length);
        
        let html = '<table class="table table-bordered table-sm" style="margin-bottom: 0;">';
        html += '<thead><tr><th>#</th><th>Name</th><th>Artist</th><th>Category</th><th>SKU</th><th>Price</th><th>Bin</th></tr></thead><tbody>';
        
        products.forEach((product, index) => {
            html += `<tr>
                <td>${index + 1}</td>
                <td>${product.name || '<span class="text-muted">-</span>'}</td>
                <td>${product.artist || '<span class="text-muted">-</span>'}</td>
                <td>${product.category || '<span class="text-muted">-</span>'}</td>
                <td>${product.sku || '<span class="text-muted">-</span>'}</td>
                <td>${product.price ? '$' + product.price : '<span class="text-muted">-</span>'}</td>
                <td>${product.bin_position || '<span class="text-muted">-</span>'}</td>
            </tr>`;
        });
        
        html += '</tbody></table>';
        table.html(html);
        container.show();
    }

    function addProductFromParsedData(productData, rowIndex) {
        return new Promise((resolve) => {
            $.ajax({
                url: "{{ route('product.getMassProductRow') }}",
                type: 'GET',
                data: { index: rowIndex },
                success: function (row) {
                    const $row = $(row);
                    $('#product_rows_container').append($row);
                    
                    // Fill in the data
                    const rowSelector = `.product-row[data-row-index="${rowIndex}"]`;
                    
                    // Product name
                    $row.find('.product-name-autocomplete').val(productData.name || '');
                    
                    // Artist
                    $row.find('input[name*="[artist]"]').val(productData.artist || '');
                    
                    // SKU
                    $row.find('input[name*="[sku]"]').val(productData.sku || '');
                    
                    // Price (selling price)
                    if (productData.price) {
                        $row.find('input[name*="[selling_price]"]').val(productData.price);
                    }
                    
                    // Bin position
                    if (productData.bin_position) {
                        $row.find('input[name*="[bin_position]"]').val(productData.bin_position);
                    }
                    
                    // Listing location
                    if (productData.listing_location) {
                        $row.find('input[name*="[listing_location]"]').val(productData.listing_location);
                    }
                    
                    // Category - try to match by name
                    if (productData.category) {
                        const categorySelect = $row.find('.category-select');
                        const categoryOption = categorySelect.find('option').filter(function() {
                            return $(this).text().toLowerCase().includes(productData.category.toLowerCase());
                        }).first();
                        if (categoryOption.length) {
                            categorySelect.val(categoryOption.val()).trigger('change');
                            
                            // Wait a bit for subcategory to load, then set it
                            setTimeout(() => {
                                if (productData.subcategory) {
                                    const subCategorySelect = $row.find('.subcategory-select');
                                    const subCategoryOption = subCategorySelect.find('option').filter(function() {
                                        return $(this).text().toLowerCase().includes(productData.subcategory.toLowerCase());
                                    }).first();
                                    if (subCategoryOption.length) {
                                        subCategorySelect.val(subCategoryOption.val()).trigger('change');
                                    }
                                }
                                resolve();
                            }, 500);
                        } else {
                            resolve();
                        }
                    } else {
                        resolve();
                    }
                    
                    // Reinitialize Select2
                    $row.find('.select2').select2();
                    window.setupProductNameSelect2();
                },
                error: function () {
                    console.error('Failed to add row for product:', productData.name);
                    resolve();
                }
            });
        });
    }

    // Real-time preview as user types (debounced)
    let previewTimeout;
    $('#bulk_product_text').on('input', function() {
        clearTimeout(previewTimeout);
        previewTimeout = setTimeout(() => {
            const text = $(this).val().trim();
            if (text.length > 10) { // Only preview if there's substantial text
                const products = parseBulkProductText(text);
                if (products.length > 0) {
                    showBulkPreview(products);
                } else {
                    $('#bulk_preview_container').hide();
                }
            }
        }, 500);
    });
    
    // Preview button
    $('#preview_bulk_text').on('click', function() {
        const bulkText = $('#bulk_product_text').val().trim();
        if (!bulkText) {
            toastr.warning('Please enter some product data to preview.');
            return;
        }
        
        const products = parseBulkProductText(bulkText);
        if (products.length === 0) {
            toastr.warning('No valid products found in the text.');
            $('#bulk_preview_container').hide();
            return;
        }
        
        showBulkPreview(products);
        toastr.info(`Found ${products.length} products. Review the preview below.`);
    });
    
    // Auto-format button
    $('#format_bulk_text').on('click', function() {
        const bulkText = $('#bulk_product_text').val().trim();
        if (!bulkText) {
            toastr.warning('Please enter some product data to format.');
            return;
        }
        
        const products = parseBulkProductText(bulkText);
        if (products.length === 0) {
            toastr.warning('No valid products found to format.');
            return;
        }
        
        // Format as pipe-delimited for consistency
        const formatted = products.map(p => {
            return [
                p.name || '',
                p.artist || '',
                p.category || '',
                p.subcategory || '',
                p.sku || '',
                p.price || '',
                p.bin_position || '',
                p.listing_location || ''
            ].join(' | ');
        }).join('\n');
        
        $('#bulk_product_text').val(formatted);
        toastr.success('Text formatted successfully!');
        showBulkPreview(products);
    });
    
    // Handle bulk text parsing
    $('#parse_bulk_text').on('click', function() {
        const bulkText = $('#bulk_product_text').val().trim();
        if (!bulkText) {
            toastr.warning('Please enter some product data to parse.');
            return;
        }
        
        const products = parseBulkProductText(bulkText);
        if (products.length === 0) {
            toastr.warning('No valid products found in the text.');
            return;
        }
        
        // Confirm before adding
        if (!confirm(`Are you sure you want to add ${products.length} products to the table?`)) {
            return;
        }
        
        $('#bulk_parse_status').html(`<i class="fa fa-spinner fa-spin"></i> Adding ${products.length} products...`);
        $(this).prop('disabled', true);
        $('#preview_bulk_text').prop('disabled', true);
        
        let currentRowIndex = parseInt($('#product_rows_container .product-row').last().attr('data-row-index') || '0');
        let addedCount = 0;
        let errorCount = 0;
        
        // Add products sequentially to avoid overwhelming the server
        function addNextProduct(index) {
            if (index >= products.length) {
                const message = errorCount > 0 
                    ? `Added ${addedCount} products (${errorCount} errors).`
                    : `Successfully added ${addedCount} products!`;
                    
                $('#bulk_parse_status').html(`<span class="text-success"><i class="fa fa-check"></i> ${message}</span>`);
                $('#parse_bulk_text').prop('disabled', false);
                $('#preview_bulk_text').prop('disabled', false);
                
                if (errorCount === 0) {
                    toastr.success(`Added ${addedCount} products from bulk text.`);
                    $('#bulk_product_text').val('');
                    $('#bulk_preview_container').hide();
                } else {
                    toastr.warning(message);
                }
                return;
            }
            
            currentRowIndex++;
            addProductFromParsedData(products[index], currentRowIndex)
                .then(() => {
                    addedCount++;
                    $('#bulk_parse_status').html(`<i class="fa fa-spinner fa-spin"></i> Adding ${addedCount}/${products.length} products...`);
                    setTimeout(() => addNextProduct(index + 1), 200); // Small delay between adds
                })
                .catch(() => {
                    errorCount++;
                    addedCount++;
                    $('#bulk_parse_status').html(`<i class="fa fa-spinner fa-spin"></i> Adding ${addedCount}/${products.length} products... (${errorCount} errors)`);
                    setTimeout(() => addNextProduct(index + 1), 200);
                });
        }
        
        addNextProduct(0);
    });

    // Clear bulk text
    $('#clear_bulk_text').on('click', function() {
        if (confirm('Are you sure you want to clear the bulk text entry?')) {
            $('#bulk_product_text').val('');
            $('#bulk_parse_status').html('');
            $('#bulk_preview_container').hide();
        }
    });
    
    // Enhanced autocomplete for bulk text area (suggestions from existing products)
    let autocompleteCache = {};
    $('#bulk_product_text').on('keydown', function(e) {
        // Trigger autocomplete on Tab or when typing product names
        if (e.key === 'Tab' || e.key === 'Enter') {
            const cursorPos = this.selectionStart;
            const textBefore = this.value.substring(0, cursorPos);
            const lines = textBefore.split('\n');
            const currentLine = lines[lines.length - 1];
            const words = currentLine.split(/\s+/);
            const lastWord = words[words.length - 1] || '';
            
            // If last word looks like a product name (2+ chars), try to autocomplete
            if (lastWord.length >= 2 && !lastWord.includes('|') && !lastWord.includes(',')) {
                // This is a simple implementation - could be enhanced with actual API call
                // For now, we'll rely on the preview feature
            }
        }
    });
</script>
@endsection


