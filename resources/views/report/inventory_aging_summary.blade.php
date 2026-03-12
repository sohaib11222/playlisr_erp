@extends('layouts.app')
@section('title', 'Inventory Aging Summary')

@section('content')
<section class="content-header">
    <h1>Inventory Aging Summary</h1>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('ias_location_id', __('purchase.business_location') . ':') !!}
                        {!! Form::select('ias_location_id', $business_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.all')]) !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('ias_as_of_date', 'As Of Date:') !!}
                        {!! Form::date('ias_as_of_date', date('Y-m-d'), ['class' => 'form-control']) !!}
                    </div>
                </div>
            @endcomponent
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary'])
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="inventory_aging_summary_table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>0-30 Days</th>
                                <th>31-60 Days</th>
                                <th>61-90 Days</th>
                                <th>90+ Days</th>
                                <th>Total Value</th>
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
    var table = $('#inventory_aging_summary_table').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '{{ action("ReportController@inventoryAgingSummary") }}',
            data: function (d) {
                d.location_id = $('#ias_location_id').val();
                d.as_of_date = $('#ias_as_of_date').val();
            }
        },
        dom: 'Bfrtip',
        buttons: ['csv', 'excel', 'print'],
        columns: [
            { data: 'product', name: 'p.name' },
            { data: 'sku', name: 'v.sub_sku' },
            { data: 'qty_0_30', name: 'qty_0_30' },
            { data: 'qty_31_60', name: 'qty_31_60' },
            { data: 'qty_61_90', name: 'qty_61_90' },
            { data: 'qty_90_plus', name: 'qty_90_plus' },
            {
                data: 'total_value',
                name: 'total_value',
                render: function(data) { return __currency_trans_from_en(data || 0, true); }
            }
        ]
    });

    $('#ias_location_id, #ias_as_of_date').change(function () {
        table.ajax.reload();
    });
});
</script>
@endsection
