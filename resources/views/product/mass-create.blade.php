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
            overflow-x: auto;
            border: 1px solid #ddd;
            margin: 20px 0;
        }

        /* Задаём минимальную ширину таблицы, чтобы колонки не сжимались */
        #mass_create_table {
            min-width: 1500px; /* или другое нужное значение */
            white-space: nowrap;
        }

        /* Предотвращаем перенос строк внутри ячеек и устанавливаем минимальную ширину колонок */
        #mass_create_table .thead .th,
        #mass_create_table .tbody .td {
            white-space: nowrap;
            min-width: 300px; /* Установите желаемую минимальную ширину */
        }

        /* Пример установки фиксированной ширины для первой колонки */
        #mass_create_table .thead .tr > .th:nth-child(1),
        #mass_create_table .tbody .tr > .td:nth-child(1) {
            width: 300px; /* Ширина первой колонки */
        }

        /* Предотвращаем перенос строк внутри ячеек */
        #mass_create_table th,
        #mass_create_table td {
            white-space: nowrap;
            min-width: 300px; /* Установите желаемую минимальную ширину */
        }

        #mass_create_table th:nth-child(1),
        #mass_create_table td:nth-child(1) {
            width: 300px; /* Ширина первой колонки */
        }


        /* Основная таблица */
        .table-wrapper {
            display: table;
            width: 100%;
            min-width: 1500px; /* Минимальная ширина таблицы */
            border-collapse: collapse;
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

        /* Ячейки – заголовки и данные */
        .th, .td {
            display: table-cell;
            padding: 15px;
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
            padding: 15px;
        }
        .tfoot .btn {
            width: 300px;
            margin: 15px auto;
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
                min-width: 100%;
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

        .price-recomendation-card-wrapper {
            display: flex;
            flex-direction: row;
            gap: 10px;
        }

        /* Price Recommendation Styles */
        .price-recommendation-card {
            margin: 10px 0;
            padding: 15px;
            border-radius: 8px;
            background: linear-gradient(145deg, #f6f8fa, #ffffff);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border: 1px solid #e1e4e8;
        }

        .price-recommendation-card h4 {
            color: #24292e;
            margin: 0 0 12px 0;
            font-size: 14px;
            font-weight: 600;
            border-bottom: 1px solid #e1e4e8;
            padding-bottom: 8px;
        }

        .price-recommendation-content {
            display: flex;
            flex-direction: column;
            gap: 6px;
            font-size: 13px;
        }

        .price-recommendation-item {
            display: flex;
            justify-content: space-between;
            padding: 4px 8px;
            border-radius: 4px;
            transition: background-color 0.2s;
            cursor: default;
        }

        .price-recommendation-item:hover {
            background-color: #f1f3f5;
        }

        .price-label {
            color: #586069;
        }

        .price-value-highest {
            color: #2ea44f;
        }

        .price-value-lowest {
            color: #d73a49;
        }

        .price-value-default {
            color: #1a73e8;
        }


        /* Discogs Price Recommendations Styles */
        .discogs-price-recommendation-card {
            margin: 10px 0;
            padding: 15px;
            border-radius: 8px;
            background: linear-gradient(145deg, #f6f8fa, #ffffff);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border: 1px solid #e1e4e8;
        }

        .discogs-price-recommendation-card h4 {
            color: #24292e;
            margin: 0 0 12px 0;
            font-size: 14px;
            font-weight: 600;
            border-bottom: 1px solid #e1e4e8;
            padding-bottom: 8px;
        }

        .discogs-price-recommendation-content {
            display: flex;
            flex-direction: column;
            gap: 6px;
            font-size: 13px;
        }

        .discogs-price-recommendation-item {
            display: flex;
            justify-content: space-between;
            padding: 4px 8px;
            border-radius: 4px;
            transition: background-color 0.2s;
            cursor: default;
        }

        .discogs-price-recommendation-item:hover {
            background-color: #f1f3f5;
        }

        .discogs-condition-label {
            color: #586069;
        }

        .discogs-price-value {
            color: #1a73e8;
            font-weight: 500;
        }

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
    </style>

    {!! Form::open(['url' => action('ProductController@massStore'), 'method' => 'post', 'id' => 'mass_create_form', 'enctype' => 'multipart/form-data' ]) !!}


    <div class="responsive-table">
        <table class="table-wrapper" id="mass_create_table">
            <!-- Шапка таблицы с восстановленными колонками -->
            <thead class="thead">
                <tr class="tr">
                    <th class="th">@lang('product.product_name')*</th>
                    <th class="th" style="min-width: 150px !important;">@lang('product.sku')</th>
                    <th class="th">@lang('product.category')</th>
                    <th class="th">@lang('product.sub_category')</th>
                    <th class="th">Artist</th>
                    <th class="th">@lang('business.business_locations')</th>
                    <th class="th">Opening Stock</th>
                    <th class="th">Product Selling Price</th>
                    <th class="th">Product Purchase Price</th>
                    <th class="th" style="min-width: 75px;">
                        <button type="button" class="btn btn-primary btn-xs show-expandables">
                            More
                        </button>
                    </th>
                    <th class="th expandable">Product Image Url</th>
                    <th class="th expandable">Upload Product Image</th>
                    <th class="th expandable">Product Description</th>
                    <th class="th">@lang('messages.action')</th>
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
                        <button type="button" class="btn btn-success" id="save_all_products">Save All Products</button>
                    </td>
                </tr>
            </tfoot>


        </table>
    </div>

    {!! Form::close() !!}

    <!-- Discogs Price Suggestions Modal -->
    <div class="modal fade" id="discogsPriceSuggestionsModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-xl" role="document" style="width: 95%; max-width: 1400px;">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                    <h4 class="modal-title">Discogs Price Suggestions</h4>
                </div>
                <div class="modal-body" style="padding: 20px;">
                    <div id="discogsPriceSuggestionsContent"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

@endsection


@section('javascript')
@php $asset_v = env('APP_VERSION'); @endphp
<script src="{{ asset('js/product.js?v=' . $asset_v) }}"></script>

<script type="text/javascript">
    var businessLocations = JSON.parse(`{!! json_encode($business_locations) !!}`);
    window.openingStockLocations = [];
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

        // Handle category change to fetch subcategories
        $(document).on('change', '.category-select', function () {
            const $this = $(this);
            const category_id = $this.val();
            const subCategorySelect = $this.closest('.tr').find('.subcategory-select');

            window.getProductPriceRecommendation($(this).attr('data-row-index'));

            if (category_id) {
                $.ajax({
                    url: "{{ route('product.get_sub_categories') }}",
                    type: 'POST',
                    data: { cat_id: category_id },
                    headers: { 'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content') },
                    success: function (data) {
                        subCategorySelect.html(data); // Directly insert received <option> tags
                    },
                    error: function () {
                        toastr.error('Failed to fetch subcategories.');
                    },
                });
            } else {
                subCategorySelect.html('<option value="">@lang("messages.please_select")</option>');
            }
        });

        // Tooltip для элементов
        $('[data-toggle="tooltip"]').tooltip();


        // Tooltip initialization for dynamically added elements
        $(document).on('mouseenter', '[data-toggle="tooltip"]', function () {
            $(this).tooltip('show');
        });

        // Реинициализация Select2 для уже существующих строк
        $('.select2').select2();

        $(document).on('change', '.select2_business_locations', function() {
            const inputId = this.id.split('_');
            var rowId = inputId[1] ?? -1;
            if (rowId < 0) {
                return;
            }

            var selectedValues = $(this).val();
            var container = $('#qty-container-' + rowId); // Target container
            
            if (selectedValues.length == 0) {
                container.html(`<span id="no_location_selected_message">Select Business Location to Edit Stock<span>`);
                return;
            }

            $("#no_location_selected_message").remove();
            var existingQty = {};

            if (window.openingStockLocations[rowId] != undefined) {
                window.openingStockLocations[rowId].forEach((item, index) => {
                    existingQty[item.id] = item.opening_stock;
                });
            }

            container.find('input[type="number"]').each(function() {
                var key = $(this).attr('data-location');
                if ($(this).val() != undefined && $(this).val() != '') {
                    existingQty[key] = $(this).val();
                }
            });

            container.find('.qty-input').each(function() {
                var location = $(this).find('input').attr('data-location');
                if (!selectedValues.includes(location)) {
                    $(this).remove();
                }
            });

            selectedValues.forEach(function(locationId) {
                if (!container.find(`#qty-wrapper-${rowId}-${locationId}`).length) {
                    var locationName = businessLocations[locationId] || 'Unknown';
                    var qtyValue = existingQty[locationId] || 1;

                    container.append(`
                        <div class="qty-input" id="qty-wrapper-${rowId}-${locationId}" style="margin-bottom: 10px;">
                            <label for="qty_${rowId}_${locationId}">Stock for ${locationName}:</label>
                            <input class="form-control" value="${qtyValue}" id="qty_${rowId}_${locationId}"  placeholder="Enter Stock" name="products[${rowId}][stock_locations][${locationId}][stock]" type="number" data-location="${locationId}">
                        </div>
                    `);
                }
            });
        });

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

        // Обработка клика по кнопке "Save All Products" с отладкой
        $('#save_all_products').on('click', function(e){
            e.preventDefault();  // Предотвращаем стандартную отправку формы
                        
            // Clear previous error messages
            $('.error-message').remove();
            $('.is-invalid').removeClass('is-invalid');

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
                        setTimeout(() => {
                            if (window.confirm("Do you want to print the labels?")) {
                                window.location.href = `/labels/show?product_ids=${product_ids.join(",")}`;
                            } else {
                                window.location.href = `/products`;
                            }
                        }, 300);
                        // Дополнительные действия при успехе
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
        const openingLocations = item.opening_locations;
        window.openingStockLocations[rowIndex] = openingLocations;
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

    window.discogsReleasesData = [];
    window.getProductPriceRecommendation = (function() {
        let timeout;
        return function(rowIndex) {
            clearTimeout(timeout);
            const productName = $(".product-name-autocomplete[data-row-index='" + rowIndex + "']").val();
            const categoryId = $(`#products_${rowIndex}_category_id`).val();


            const priceRecommendationContainer = $(".product-price-recommendation-container[data-row-index='" + rowIndex + "']");
            priceRecommendationContainer.html("");

            const subCategorySuggestionsContainer = $(`.sub-category-suggestions-container[data-row-index='${rowIndex}']`);
            subCategorySuggestionsContainer.html("");

            timeout = setTimeout(function() {
                $.getJSON('/product/mass-create/get-product-price-recommendation', {
                    query: productName,
                    category_id: categoryId,
                    row_index: rowIndex
                }, function(response) {
                    rowIndex = response.row_index;

                    const priceRecommendationContainer = $(".product-price-recommendation-container[data-row-index='" + rowIndex + "']");
                    priceRecommendationContainer.html("");

                    if (response.error) {
                        return;
                    }
                    
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
                    
                    let recommendationHtml = '';

                    // Add a button to show Discogs suggestions
                    if (response.discogs_releases.results && response.discogs_releases.results.length > 0) {
                        recommendationHtml += `
                            <button type="button" class="btn btn-info btn-sm show-discogs-suggestions" 
                                    data-row-index="${rowIndex}" 
                                    style="margin-top: 10px;">
                                <i class="fa fa-list"></i> View (${response.discogs_releases.results.length}) Discogs Versions
                            </button>
                        `;

                        window.discogsReleasesData[rowIndex] = response.discogs_releases;
                        // Store the suggestions data for later use
                        priceRecommendationContainer.data('discogsSuggestions', response.discogs_releases);
                    }

                    recommendationHtml += `<div class="price-recomendation-card-wrapper">`;
                    // Keep the eBay recommendations in the main view
                    recommendationHtml += `<div class="price-recommendation-card">
                        <h4>Ebay Price Recommendations</h4>
                        <div class="price-recommendation-content">
                            <div class="price-recommendation-item">
                                <span class="price-label">Highest Price:</span>
                                <span class="price-value-highest">$${response.price_recommendation.highest}</span>
                            </div>
                            <div class="price-recommendation-item">
                                <span class="price-label">Lowest Price:</span>
                                <span class="price-value-lowest">$${response.price_recommendation.lowest}</span>
                            </div>
                            <div class="price-recommendation-item">
                                <span class="price-label">Median Price:</span>
                                <span class="price-value-default">$${response.price_recommendation.median}</span>
                            </div>
                            <div class="price-recommendation-item">
                                <span class="price-label">Average Price:</span>
                                <span class="price-value-default">$${response.price_recommendation.average}</span>
                            </div>
                        </div>
                    </div>`;


                    const discogs_price_recommendation = response.discogs_price_recommendation;

                    if (discogs_price_recommendation != undefined) {
                        // Add Discogs price recommendations
                        recommendationHtml += `<div class="discogs-price-recommendation-card">
                            <h4>Discogs Price Recommendations</h4>
                            <div class="discogs-price-recommendation-content">
                                ${response.discogs_price_recommendation.map(price => `
                                    <div class="discogs-price-recommendation-item">
                                        <span class="discogs-condition-label">${price.condition}:</span>
                                        <span class="discogs-price-value">${price.currency} ${price.value}</span>
                                    </div>
                                `).join('')}
                            </div>
                        </div>`;
                    }

                    recommendationHtml += `</div>`;
                    
                    priceRecommendationContainer.html(recommendationHtml);
                });
            }, 500);
        };
    })();

    // Add click handler for showing Discogs suggestions
    $(document).on('click', '.show-discogs-suggestions', function() {
        const rowIndex = $(this).data('row-index');
        const container = $(`.product-price-recommendation-container[data-row-index='${rowIndex}']`);
        const suggestions = window.discogsReleasesData[rowIndex];
        
        let modalContent = '';

        if (suggestions.results && suggestions.results.length > 0) {
            modalContent = `
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th style="width: 5%"></th>
                                <th style="width: 35%">Title, Format</th>
                                <th style="width: 25%">Label - Catalog Number</th>
                                <th style="width: 15%">Genre</th>
                                <th style="width: 15%">Country</th>
                                <th style="width: 15%">Year</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${suggestions.results.map((release, index) => `
                                <tr class="release-row" data-release-id="${release.id}">
                                    <td>
                                        <img src="${release.thumb}" alt="Release Thumbnail" style="width: 50px; height: 50px; object-fit: cover;">
                                    </td>
                                    <td>
                                        <div style="display: flex; align-items: start;">
                                            <button type="button" class="btn btn-xs btn-link expand-release" 
                                                    style="padding: 0 5px; margin-right: 5px;"
                                                    data-release-id="${release.id}">
                                                <i class="fa fa-chevron-right"></i>
                                            </button>
                                            <div>
                                                <strong>${release.title}</strong>
                                                ${release.formats ? `<br><small>${release.formats.map(format => format.name).join(', ')}</small>` : ''}
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        ${release.label ? release.label.join(', ') : ''}
                                        ${release.catno ? `<br><small>${release.catno}</small>` : ''}
                                    </td>
                                    <td>${release.genre ? release.genre.join(', ') : ''}</td>
                                    <td>${release.country || ''}</td>
                                    <td>${release.year || ''}</td>
                                </tr>
                                <tr class="release-details" id="release-details-${release.id}" style="display: none;">
                                    <td colspan="6">
                                        <div class="release-stats" style="padding: 10px;">
                                            
                                        </div>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        } else {
            modalContent = '<div class="alert alert-info">No Discogs releases found.</div>';
        }
        
        $('#discogsPriceSuggestionsContent').html(modalContent);
        $('#discogsPriceSuggestionsModal').modal('show');
    });

    // Handle expand/collapse of release details
    $(document).on('click', '.expand-release', function(e) {
        e.preventDefault();
        const releaseId = $(this).data('release-id');
        const detailsRow = $(`#release-details-${releaseId}`);
        const icon = $(this).find('i');
        
        if (detailsRow.is(':visible')) {
            detailsRow.hide();
            icon.removeClass('fa-chevron-down').addClass('fa-chevron-right');
        } else {
            // Show loading state
            detailsRow.show();
            detailsRow.find('.release-stats').html('<div class="text-center p-3"><i class="fa fa-spinner fa-spin"></i> Loading prices...</div>');
            icon.removeClass('fa-chevron-right').addClass('fa-chevron-down');

            // Fetch prices from API
            $.get('/product/mass-create/get-discogs-prices', {
                release_id: releaseId
            }, function(response) {
                if (response.success) {
                    const prices = response.prices;
                    const priceTable = `
                        <div style="background: #f9f9f9; padding: 15px; border-radius: 4px;">
                            <h5 style="margin-top: 0; margin-bottom: 15px; font-weight: bold;">Price Suggestions by Condition</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered table-condensed" style="margin-bottom: 0;">
                                    <thead>
                                        <tr>
                                            <th style="width: 70%">Condition</th>
                                            <th style="width: 30%">Price</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${prices && prices.length > 0 ? prices.map(price => `
                                            <tr>
                                                <td>${price.condition}</td>
                                                <td>${price.currency} ${price.value}</td>
                                            </tr>
                                        `).join('') : '<tr><td colspan="2">No price suggestions available</td></tr>'}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    `;
                    detailsRow.find('.release-stats').html(priceTable);
                } else {
                    detailsRow.find('.release-stats').html('<div class="alert alert-danger">Failed to load price suggestions</div>');
                }
            }).fail(function() {
                detailsRow.find('.release-stats').html('<div class="alert alert-danger">Failed to load price suggestions</div>');
            });
        }
    });

    $(document).on('keyup', '.product-name-autocomplete', function() {
        window.getProductPriceRecommendation($(this).attr('data-row-index'));
    });

    $(document).on('keyup', '.sku-input', function() {
        window.getProductPriceRecommendation($(this).attr('data-row-index'));
    });

    window.setupProductNameSelect2 = function () {
        try {
            $(".product-name-autocomplete").each(function () {
                $(this).autocomplete({
                    source: function(request, response) {
                        $.getJSON('/product/mass-create/get-products', { term: request.term }, response);
                    },
                    minLength: 2,
                    response: function(event, ui) {
                        if (ui.content.length == 1) {
                            // ui.item = ui.content[0];
                            // $(this).data('ui-autocomplete')._trigger('select', 'autocompleteselect', ui);
                            // $(this).autocomplete('close');
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
                        setTimeout(n => {
                            window.setAsSelectedProductRow(ui, $(this));
                        }, 50);
                    }
                }).autocomplete('instance')._renderItem = function(ul, item) {
                    return $('<li>').append('<div>' + item.text + '</div>').appendTo(ul);
                };
            });
                    
        } catch (error) {
            console.log("ERRROR : ", error);
        }
    }
</script>
@endsection


