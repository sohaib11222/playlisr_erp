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

    @if(config('nivessa_cogs.enabled'))
        <div class="alert alert-info" style="border-left:4px solid #3c8dbc;">
            <strong>COGS fallback is on.</strong> For products sold without a recorded
            purchase price, cost is filled from the category-based assumption
            map in <code>config/nivessa_cogs.php</code> (New Vinyl $17, Used
            Vinyl $0.10, New CD $6, Used CD $0.10, etc.). Rows marked with
            <strong style="color:#8a6d3b;">*</strong> used an assumed cost for
            at least one sold unit. Edit the config to tune the assumptions.
        </div>
    @endif

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
                render: function(data, _type, row) {
                    var val = __currency_trans_from_en(data || 0, true);
                    // Append an asterisk + mustard color when the COGS fallback
                    // kicked in, so accountants can distinguish real cost from
                    // assumption-based cost at a glance.
                    if (row.cost_is_assumed == 1) {
                        return val + ' <strong style="color:#8a6d3b;" title="Includes assumed cost (no purchase price on file)">*</strong>';
                    }
                    return val;
                }
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
