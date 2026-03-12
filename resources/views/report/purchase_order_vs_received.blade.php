@extends('layouts.app')
@section('title', 'PO vs Received')

@section('content')
<section class="content-header">
    <h1>Purchase Order vs Received</h1>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('povr_supplier_id', __('purchase.supplier') . ':') !!}
                        {!! Form::select('povr_supplier_id', $suppliers, null, ['class' => 'form-control select2']) !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('povr_location_id', __('purchase.business_location') . ':') !!}
                        {!! Form::select('povr_location_id', $business_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.all')]) !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('povr_start_date', __('report.start_date') . ':') !!}
                        {!! Form::date('povr_start_date', null, ['class' => 'form-control']) !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('povr_end_date', __('report.end_date') . ':') !!}
                        {!! Form::date('povr_end_date', null, ['class' => 'form-control']) !!}
                    </div>
                </div>
            @endcomponent
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary'])
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="purchase_order_vs_received_table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>PO Ref #</th>
                                <th>Supplier</th>
                                <th>Location</th>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Ordered Qty</th>
                                <th>Received Qty</th>
                                <th>Pending Qty</th>
                                <th>Status</th>
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
    var table = $('#purchase_order_vs_received_table').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '{{ action("ReportController@purchaseOrderVsReceived") }}',
            data: function (d) {
                d.supplier_id = $('#povr_supplier_id').val();
                d.location_id = $('#povr_location_id').val();
                d.start_date = $('#povr_start_date').val();
                d.end_date = $('#povr_end_date').val();
            }
        },
        dom: 'Bfrtip',
        buttons: ['csv', 'excel', 'print'],
        order: [[0, 'desc']],
        columns: [
            { data: 'transaction_date', name: 't.transaction_date' },
            { data: 'ref_no', name: 't.ref_no' },
            { data: 'supplier_name', name: 'c.name' },
            { data: 'location_name', name: 'bl.name' },
            { data: 'product', name: 'p.name' },
            { data: 'sku', name: 'v.sub_sku' },
            { data: 'ordered_qty', name: 'pl.quantity' },
            { data: 'received_qty', name: 'pl.po_quantity_purchased' },
            { data: 'pending_qty', name: 'pending_qty' },
            { data: 'status', name: 't.status' }
        ]
    });

    $('#povr_supplier_id, #povr_location_id, #povr_start_date, #povr_end_date').change(function () {
        table.ajax.reload();
    });
});
</script>
@endsection
