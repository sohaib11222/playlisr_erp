@extends('layouts.app')
@section('title', 'Inventory Valuation Summary')

@section('content')
<section class="content-header">
    <h1>Inventory Valuation Summary</h1>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('ivs_location_id', __('purchase.business_location') . ':') !!}
                        {!! Form::select('ivs_location_id', $business_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.all')]) !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('ivs_category_id', __('product.category') . ':') !!}
                        {!! Form::select('ivs_category_id', $categories, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.all')]) !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('ivs_brand_id', __('product.brand') . ':') !!}
                        {!! Form::select('ivs_brand_id', $brands, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.all')]) !!}
                    </div>
                </div>
            @endcomponent
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary'])
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="inventory_valuation_summary_table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Location</th>
                                <th>Qty On Hand</th>
                                <th>Cost Per Unit</th>
                                <th>Inventory Value</th>
                                <th>Selling Price</th>
                            </tr>
                        </thead>
                    </table>
                </div>
            @endcomponent
        </div>
    </div>
</section>
@endsection

@section('javascript')
<script type="text/javascript">
$(document).ready(function () {
    var table = $('#inventory_valuation_summary_table').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '{{ action("ReportController@inventoryValuationSummary") }}',
            data: function (d) {
                d.location_id = $('#ivs_location_id').val();
                d.category_id = $('#ivs_category_id').val();
                d.brand_id = $('#ivs_brand_id').val();
            }
        },
        dom: 'Bfrtip',
        buttons: [
            {
                extend: 'csv',
                action: function (e, dt, node, config) {
                    window.dtServerSideExportAllRows(dt, 'csv');
                }
            },
            {
                extend: 'excel',
                action: function (e, dt, node, config) {
                    window.dtServerSideExportAllRows(dt, 'excel');
                }
            },
            {
                extend: 'print',
                action: function (e, dt, node, config) {
                    window.dtServerSideExportAllRows(dt, 'print');
                }
            }
        ],
        columns: [
            { data: 'product', name: 'product' },
            { data: 'sku', name: 'sku' },
            { data: 'location_name', name: 'location_name' },
            { data: 'stock', name: 'stock' },
            {
                data: 'cost_per_unit',
                name: 'cost_per_unit',
                render: function(data) { return __currency_trans_from_en(data || 0, true); }
            },
            {
                data: 'stock_price',
                name: 'stock_price',
                render: function(data) { return __currency_trans_from_en(data || 0, true); }
            },
            {
                data: 'unit_price',
                name: 'unit_price',
                render: function(data) { return __currency_trans_from_en(data || 0, true); }
            }
        ]
    });

    $('#ivs_location_id, #ivs_category_id, #ivs_brand_id').change(function () {
        table.ajax.reload();
    });
});
</script>
@endsection
