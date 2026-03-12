@extends('layouts.app')
@section('title', 'ABC Inventory Classification')

@section('content')
<section class="content-header">
    <h1>ABC Inventory Classification</h1>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('abc_start_date', __('report.start_date') . ':') !!}
                        {!! Form::date('abc_start_date', null, ['class' => 'form-control']) !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('abc_end_date', __('report.end_date') . ':') !!}
                        {!! Form::date('abc_end_date', null, ['class' => 'form-control']) !!}
                    </div>
                </div>
            @endcomponent
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary'])
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="abc_inventory_table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Qty On Hand</th>
                                <th>Qty Sold</th>
                                <th>Inventory Value</th>
                                <th>Cumulative %</th>
                                <th>Class</th>
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
    var table = $('#abc_inventory_table').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '{{ action("ReportController@abcInventoryClassification") }}',
            data: function (d) {
                d.start_date = $('#abc_start_date').val();
                d.end_date = $('#abc_end_date').val();
            }
        },
        dom: 'Bfrtip',
        buttons: ['csv', 'excel', 'print'],
        order: [[4, 'desc']],
        columns: [
            { data: 'product', name: 'product' },
            { data: 'sku', name: 'sku' },
            { data: 'qty_on_hand', name: 'qty_on_hand' },
            { data: 'qty_sold', name: 'qty_sold' },
            {
                data: 'inventory_value',
                name: 'inventory_value',
                render: function(data) { return __currency_trans_from_en(data || 0, true); }
            },
            {
                data: 'cumulative_value_pct',
                name: 'cumulative_value_pct',
                render: function(data) { return (parseFloat(data || 0)).toFixed(2) + '%'; }
            },
            { data: 'abc_class', name: 'abc_class' }
        ]
    });

    $('#abc_start_date, #abc_end_date').change(function () {
        table.ajax.reload();
    });
});
</script>
@endsection
