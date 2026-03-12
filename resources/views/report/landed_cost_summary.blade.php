@extends('layouts.app')
@section('title', 'Landed Cost Summary')

@section('content')
<section class="content-header">
    <h1>Landed Cost Summary</h1>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('lcs_supplier_id', __('purchase.supplier') . ':') !!}
                        {!! Form::select('lcs_supplier_id', $suppliers, null, ['class' => 'form-control select2']) !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('lcs_location_id', __('purchase.business_location') . ':') !!}
                        {!! Form::select('lcs_location_id', $business_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.all')]) !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('lcs_start_date', __('report.start_date') . ':') !!}
                        {!! Form::date('lcs_start_date', null, ['class' => 'form-control']) !!}
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('lcs_end_date', __('report.end_date') . ':') !!}
                        {!! Form::date('lcs_end_date', null, ['class' => 'form-control']) !!}
                    </div>
                </div>
            @endcomponent
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary'])
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="landed_cost_summary_table" style="width: 100%;">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Ref #</th>
                                <th>Supplier</th>
                                <th>Location</th>
                                <th>Base Total</th>
                                <th>Landed Add-ons</th>
                                <th>Landed Total</th>
                                <th>Add-ons %</th>
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
    var table = $('#landed_cost_summary_table').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '{{ action("ReportController@landedCostSummary") }}',
            data: function (d) {
                d.supplier_id = $('#lcs_supplier_id').val();
                d.location_id = $('#lcs_location_id').val();
                d.start_date = $('#lcs_start_date').val();
                d.end_date = $('#lcs_end_date').val();
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
            {
                data: 'final_total',
                name: 't.final_total',
                render: function(data) { return __currency_trans_from_en(data || 0, true); }
            },
            {
                data: 'landed_addons',
                name: 'landed_addons',
                render: function(data) { return __currency_trans_from_en(data || 0, true); }
            },
            {
                data: 'landed_total',
                name: 'landed_total',
                render: function(data) { return __currency_trans_from_en(data || 0, true); }
            },
            {
                data: 'addons_pct',
                name: 'addons_pct',
                render: function(data) { return (parseFloat(data || 0)).toFixed(2) + '%'; }
            }
        ]
    });

    $('#lcs_supplier_id, #lcs_location_id, #lcs_start_date, #lcs_end_date').change(function () {
        table.ajax.reload();
    });
});
</script>
@endsection
