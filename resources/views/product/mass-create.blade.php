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
                    <th>@lang('product.product_name')</th>
                    <th>@lang('product.sku')</th>
                    <th>@lang('product.brand')</th>
                    <th>@lang('product.category')</th>
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

@push('scripts')
    <script>
        let rowIndex = 1;

        $('#add_row').on('click', function () {
            $.ajax({
                url: "{{ route('product.getMassProductRow') }}",
                type: 'GET',
                data: { index: rowIndex },
                success: function (row) {
                    $('#product_rows_container').append(row);
                    rowIndex++;
                }
            });
        });

        $(document).on('click', '.remove_row', function () {
            $(this).closest('tr').remove();
        });
    </script>
@endpush
