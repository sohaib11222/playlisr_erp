@extends('layouts.app')
@section('title', 'Inventory Valuation Detail')

@section('content')
<section class="content-header">
    <h1>Inventory Valuation Detail</h1>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('ivd_location_id', __('purchase.business_location') . ':') !!}
                        {!! Form::select('ivd_location_id', $business_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.all')]) !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('ivd_start_date', __('report.start_date') . ':') !!}
                        {!! Form::date('ivd_start_date', null, ['class' => 'form-control']) !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('ivd_end_date', __('report.end_date') . ':') !!}
                        {!! Form::date('ivd_end_date', null, ['class' => 'form-control']) !!}
                    </div>
                </div>
            @endcomponent
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary'])
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="inventory_valuation_detail_table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Ref #</th>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Vendor</th>
                                <th>Location</th>
                                <th>Lot #</th>
                                <th>Qty In</th>
                                <th>Qty Remaining</th>
                                <th>Unit Cost</th>
                                <th>Remaining Value</th>
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
    var table = $('#inventory_valuation_detail_table').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '{{ action("ReportController@inventoryValuationDetail") }}',
            data: function (d) {
                d.location_id = $('#ivd_location_id').val();
                d.start_date = $('#ivd_start_date').val();
                d.end_date = $('#ivd_end_date').val();
            }
        },
        dom: 'Bfrtip',
        buttons: ['csv', 'excel', 'print'],
        order: [[0, 'desc']],
        columns: [
            { data: 'transaction_date', name: 't.transaction_date' },
            { data: 'ref_no', name: 't.ref_no' },
            { data: 'product', name: 'p.name' },
            { data: 'sku', name: 'v.sub_sku' },
            { data: 'vendor_name', name: 'c.name' },
            { data: 'location_name', name: 'bl.name' },
            { data: 'lot_number', name: 'pl.lot_number' },
            { data: 'quantity', name: 'pl.quantity' },
            { data: 'remaining_qty', name: 'remaining_qty' },
            {
                data: 'unit_cost',
                name: 'pl.purchase_price_inc_tax',
                render: function(data) { return __currency_trans_from_en(data || 0, true); }
            },
            {
                data: 'remaining_value',
                name: 'remaining_value',
                render: function(data) { return __currency_trans_from_en(data || 0, true); }
            }
        ]
    });

    $('#ivd_location_id, #ivd_start_date, #ivd_end_date').change(function () {
        table.ajax.reload();
    });
});
</script>
@endsection
