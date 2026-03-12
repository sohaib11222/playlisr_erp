@extends('layouts.app')
@section('title', 'Purchases by Item/Vendor')

@section('content')
<section class="content-header">
    <h1>Purchases by Item/Vendor</h1>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('pbiv_supplier_id', __('purchase.supplier') . ':') !!}
                        {!! Form::select('pbiv_supplier_id', $suppliers, null, ['class' => 'form-control select2']) !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('pbiv_location_id', __('purchase.business_location') . ':') !!}
                        {!! Form::select('pbiv_location_id', $business_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.all')]) !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('pbiv_start_date', __('report.start_date') . ':') !!}
                        {!! Form::date('pbiv_start_date', null, ['class' => 'form-control']) !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('pbiv_end_date', __('report.end_date') . ':') !!}
                        {!! Form::date('pbiv_end_date', null, ['class' => 'form-control']) !!}
                    </div>
                </div>
            @endcomponent
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary'])
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="purchases_item_vendor_table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Ref #</th>
                                <th>Product</th>
                                <th>SKU</th>
                                <th>Vendor</th>
                                <th>Location</th>
                                <th>Qty</th>
                                <th>Unit Cost</th>
                                <th>Total Cost</th>
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
    var table = $('#purchases_item_vendor_table').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '{{ action("ReportController@purchasesByItemVendor") }}',
            data: function (d) {
                d.supplier_id = $('#pbiv_supplier_id').val();
                d.location_id = $('#pbiv_location_id').val();
                d.start_date = $('#pbiv_start_date').val();
                d.end_date = $('#pbiv_end_date').val();
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
            { data: 'quantity', name: 'pl.quantity' },
            {
                data: 'unit_cost',
                name: 'pl.purchase_price_inc_tax',
                render: function(data) { return __currency_trans_from_en(data || 0, true); }
            },
            {
                data: 'total_cost',
                name: 'total_cost',
                render: function(data) { return __currency_trans_from_en(data || 0, true); }
            }
        ]
    });

    $('#pbiv_supplier_id, #pbiv_location_id, #pbiv_start_date, #pbiv_end_date').change(function () {
        table.ajax.reload();
    });
});
</script>
@endsection
