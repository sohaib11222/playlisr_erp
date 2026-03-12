@extends('layouts.app')
@section('title', 'Sales by Item (Cost & Margin)')

@section('content')
<section class="content-header">
    <h1>Sales by Item (Cost & Margin)</h1>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('sbicm_location_id', __('purchase.business_location') . ':') !!}
                        {!! Form::select('sbicm_location_id', $business_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.all')]) !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('sbicm_start_date', __('report.start_date') . ':') !!}
                        {!! Form::date('sbicm_start_date', null, ['class' => 'form-control']) !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('sbicm_end_date', __('report.end_date') . ':') !!}
                        {!! Form::date('sbicm_end_date', null, ['class' => 'form-control']) !!}
                    </div>
                </div>
            @endcomponent
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary'])
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="sales_item_cost_margin_table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Variation</th>
                                <th>Qty Sold</th>
                                <th>Revenue</th>
                                <th>Cost</th>
                                <th>Gross Margin</th>
                                <th>Margin %</th>
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
    var table = $('#sales_item_cost_margin_table').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '{{ action("ReportController@salesByItemCostMargin") }}',
            data: function (d) {
                d.location_id = $('#sbicm_location_id').val();
                d.start_date = $('#sbicm_start_date').val();
                d.end_date = $('#sbicm_end_date').val();
            }
        },
        dom: 'Bfrtip',
        buttons: ['csv', 'excel', 'print'],
        order: [[4, 'desc']],
        columns: [
            { data: 'product', name: 'p.name' },
            { data: 'sku', name: 'v.sub_sku' },
            { data: 'variation', name: 'variation', orderable: false },
            { data: 'qty_sold', name: 'qty_sold' },
            {
                data: 'revenue',
                name: 'revenue',
                render: function(data) { return __currency_trans_from_en(data || 0, true); }
            },
            {
                data: 'cost',
                name: 'cost',
                render: function(data) { return __currency_trans_from_en(data || 0, true); }
            },
            {
                data: 'gross_margin',
                name: 'gross_margin',
                render: function(data) { return __currency_trans_from_en(data || 0, true); }
            },
            {
                data: 'margin_percent',
                name: 'margin_percent',
                render: function(data) { return (parseFloat(data || 0)).toFixed(2) + '%'; }
            }
        ]
    });

    $('#sbicm_location_id, #sbicm_start_date, #sbicm_end_date').change(function () {
        table.ajax.reload();
    });
});
</script>
@endsection
