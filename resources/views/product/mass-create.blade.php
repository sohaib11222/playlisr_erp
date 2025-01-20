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
    </style>

    {!! Form::open(['url' => action('ProductController@massStore'), 'method' => 'post', 'id' => 'mass_create_form', 'enctype' => 'multipart/form-data' ]) !!}


    <div class="responsive-table">
        <div class="table-wrapper" id="mass_create_table">
            <!-- Шапка таблицы с восстановленными колонками -->
            <div class="thead">
                <div class="tr">
                    <div class="th">@lang('product.product_name')*</div>
                    <div class="th">@lang('product.sku')</div>
                    <div class="th">@lang('product.brand')</div>
                    <div class="th">@lang('product.category')</div>
                    <div class="th">@lang('product.sub_category')</div>
                    <div class="th">@lang('business.business_locations')</div>
                    <div class="th">@lang('product.alert_quantity')</div>
                    <div class="th">Product Selling Price</div>
                    <div class="th">Product Purchase Price</div>
                    <div class="th">@lang('product.tax')</div>
                    <div class="th">Product Image Url</div>
                    <div class="th">Upload Product Image</div>

                    <div class="th">@lang('product.manage_stock')</div>
                    <div class="th">Product Description</div>

                    <div class="th">@lang('product.selling_price_tax_type')</div>

                    <div class="th">@lang('messages.action')</div>
                </div>
            </div>

            <!-- Тело таблицы -->
            <div class="tbody" id="product_rows_container">

                    @include('product.partials.mass_product_row', ['index' => 0])


                <!-- Добавляйте новые .tr для каждой новой строки продукта -->
            </div>

            <!-- Подвал таблицы -->
            <div class="tfoot">
                <div class="tr">
                    <div class="td" colspan="17">
                        <button type="button" class="btn btn-primary" id="add_row">
                            Add New Product Row
                        </button>
                    </div>
                </div>
                <div class="tr">
                    <div class="td" colspan="17">
                        <button type="button" class="btn btn-success" id="save_all_products">Save All Products</button>
                    </div>
                </div>
            </div>


        </div>
    </div>



    {!! Form::close() !!}


@endsection




    @section('javascript')
    @php $asset_v = env('APP_VERSION'); @endphp
    <script src="{{ asset('js/product.js?v=' . $asset_v) }}"></script>

    <script type="text/javascript">
        $(document).ready(function(){




            let rowIndex = 1;



            // Add a new row
            // Add new row
            $('#add_row').on('click', function () {
                $.ajax({
                    url: "{{ route('product.getMassProductRow') }}",
                    type: 'GET',
                    data: { index: rowIndex },
                    success: function (row) {
                        $('#product_rows_container').append(row);
                        rowIndex++;

                        // Reinitialize Select2 for new elements
                        $('#product_rows_container .select2').last().select2();
                    },
                    error: function () {
                        toastr.error('Failed to add a new row.');
                    },
                });
            });

            // Remove row
            $(document).on('click', '.remove_row', function () {
                $(this).closest('.tr').remove();
            });

            // Handle category change to fetch subcategories
            // Handle category change to fetch subcategories
            $(document).on('change', '.category-select', function () {
                const $this = $(this);
                const category_id = $this.val();
                const subCategorySelect = $this.closest('.tr').find('.subcategory-select');

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

            $(document).on('change', 'input[type="file"]', function () {
                const fileName = $(this).val().split('\\').pop();
                $(this).siblings('.custom-file-label').addClass("selected").html(fileName);
            });

            // Обработка клика по кнопке "Save All Products" с отладкой
            $('#save_all_products').on('click', function(e){
                e.preventDefault();  // Предотвращаем стандартную отправку формы

                let form = $('#mass_create_form')[0];
                let formData = new FormData(form);  // Собираем все данные формы

                console.log('Submitting form data...');
                for (let pair of formData.entries()) {
                    console.log(pair[0]+ ': ' + pair[1]);
                }

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
                            // Дополнительные действия при успехе
                        } else {
                            toastr.error('Error: ' + response.msg);
                            document.getElementById('error-audio').play();
                        }
                    },
                    error: function(xhr, status, error) {
                        let errorMsg = 'The product saving failed';
                        if(xhr.responseText) {
                            try {
                                let resp = JSON.parse(xhr.responseText);
                                if(resp.message) {
                                    errorMsg += ': ' + resp.message;
                                } else {
                                    errorMsg += ': ' + xhr.responseText;
                                }
                            } catch(e) {
                                errorMsg += ': ' + xhr.responseText;
                            }
                        }
                        toastr.error(errorMsg);
                        document.getElementById('error-audio').play();
                        console.log('AJAX error:', status, error);
                        console.log('Response Text:', xhr.responseText);
                    }
                });
            });


        });

    </script>
@endsection


