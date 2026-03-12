@extends('layouts.app')
@section('title', 'Item Transaction History')

@section('content')
<section class="content-header">
    <h1>Item Transaction History</h1>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('ith_product_id', __('sale.product') . ':') !!}
                        {!! Form::select('ith_product_id', $products, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.all')]) !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('ith_location_id', __('purchase.business_location') . ':') !!}
                        {!! Form::select('ith_location_id', $business_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.all')]) !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('ith_start_date', __('report.start_date') . ':') !!}
                        {!! Form::date('ith_start_date', null, ['class' => 'form-control']) !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('ith_end_date', __('report.end_date') . ':') !!}
                        {!! Form::date('ith_end_date', null, ['class' => 'form-control']) !!}
                    </div>
                </div>
            @endcomponent
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary'])
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="item_transaction_history_table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Ref #</th>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Location</th>
                                <th>Txn Type</th>
                                <th>Qty In</th>
                                <th>Qty Out</th>
                                <th>Unit Amount</th>
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
    var table = $('#item_transaction_history_table').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '{{ action("ReportController@itemTransactionHistory") }}',
            data: function (d) {
                d.product_id = $('#ith_product_id').val();
                d.location_id = $('#ith_location_id').val();
                d.start_date = $('#ith_start_date').val();
                d.end_date = $('#ith_end_date').val();
            }
        },
        dom: 'Bfrtip',
        buttons: ['csv', 'excel', 'print'],
        order: [[0, 'desc']],
        columns: [
            { data: 'transaction_date', name: 'transaction_date' },
            { data: 'ref_no', name: 'ref_no' },
            { data: 'product', name: 'product' },
            { data: 'sku', name: 'sku' },
            { data: 'location_name', name: 'location_name' },
            { data: 'txn_type', name: 'txn_type' },
            { data: 'qty_in', name: 'qty_in' },
            { data: 'qty_out', name: 'qty_out' },
            {
                data: 'unit_cost',
                name: 'unit_cost',
                render: function(data) { return __currency_trans_from_en(data || 0, true); }
            }
        ]
    });

    $('#ith_product_id, #ith_location_id, #ith_start_date, #ith_end_date').change(function () {
        table.ajax.reload();
    });
});
</script>
@endsection
