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


    <section class="content">
        {!! Form::open(['url' => action('ProductController@massStore'), 'method' => 'post', 'id' => 'mass_create_form' ]) !!}
        <div class="table-responsive">
            <table class="table table-bordered table-striped" id="mass_create_table">
                <thead>
                <tr>
                    <th>@lang('product.product_name')*</th>
                    <th>
                        {!! Form::label('sku', __('product.sku') . ':') !!} @show_tooltip(__('tooltip.sku'))
                    </th>
                    <th>
                        @lang('product.brand')
                        <i class="fa fa-info-circle" data-toggle="tooltip" title="@lang('tooltip.brand')"></i>
                    </th>
                    <th>@lang('product.category')</th>
                    <th>@lang('product.sub_category')</th>

                    <th>@lang('product.locations')</th>
                    <th>@lang('product.alert_quantity')</th>
                    <th>@lang('product.selling_price')</th>
                    <th>@lang('product.purchase_price')</th>
                    <th>@lang('product.tax')</th>
                    <th>@lang('messages.action')</th>
                </tr>
                </thead>
                <tbody id="product_rows_container">
                <tr>
                    @include('product.partials.mass_product_row', ['index' => 0])
                </tr>
                </tbody>
                <tfoot>
                <tr>
                    <td colspan="10">
                        <button type="button" class="btn btn-primary" id="add_row">
                            @lang('product.add_row')
                        </button>
                    </td>
                </tr>
                </tfoot>
            </table>
        </div>
        {!! Form::submit(__('messages.save'), ['class' => 'btn btn-success']) !!}
        {!! Form::close() !!}
    </section>
@endsection

@section('javascript')
    @php $asset_v = env('APP_VERSION'); @endphp
    <script src="{{ asset('js/product.js?v=' . $asset_v) }}"></script>

    <script type="text/javascript">
        $(document).ready(function(){


            alert('dom ready!');

            let rowIndex = 1;



                    // Добавление новой строки
                    $('#add_row').on('click', function () {
                        $.ajax({
                            url: "{{ route('product.getMassProductRow') }}",
                            type: 'GET',
                            data: {index: rowIndex},
                            success: function (row) {
                                $('#product_rows_container').append(row);
                                rowIndex++;

                                // Реинициализируем Select2 только для новых элементов
                                $('#product_rows_container .select2').last().select2();
                            },
                            error: function () {
                                toastr.error('Failed to add a new row.');
                            }
                        });
                    });


                    // Удаление строки
                    $(document).on('click', '.remove_row', function () {
                        $(this).closest('tr').remove();
                    });

                    // Обработка изменения категории
                    $(document).on('change', '.category-select', function () {
                        const $this = $(this);
                        const category_id = $this.val();
                        const rowId = $this.closest('tr').index();
                        const subCategorySelect = $this.closest('tr').find('.subcategory-select');

                        console.log(`Category changed in row ${rowId}, category_id: ${category_id}`);

                        if (category_id) {
                            $.ajax({
                                url: "{{ route('product.get_sub_categories') }}",
                                type: 'POST',
                                data: {cat_id: category_id},
                                headers: {
                                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
                                },
                                success: function (data) {
                                    subCategorySelect.html(data);
                                },
                                error: function () {
                                    toastr.error('Failed to fetch subcategories.');
                                },
                            });
                        } else {
                            subCategorySelect.html('<option value="">@lang("messages.please_select")</option>');
                        }
                    });


                    // Реинициализация Select2 для уже существующих строк
                    $('.select2').select2();

                    // Tooltip для элементов
                    $('[data-toggle="tooltip"]').tooltip();


                    // Tooltip initialization for dynamically added elements
                    $(document).on('mouseenter', '[data-toggle="tooltip"]', function () {
                        $(this).tooltip('show');
                    });


        });

    </script>
@endsection


