@extends('layouts.app')
@section('title', 'Sales Transaction Detail Report — Itemized')

@section('content')

<section class="content-header no-print">
    <h1>Sales Transaction Detail Report — Itemized</h1>
    <p class="text-muted">
        One row per product sold. Includes cost + margin per line.
        <a href="{{ url('/sells') }}" class="btn btn-sm btn-default" style="margin-left:12px;">
            <i class="fa fa-list"></i> Switch to transaction view
        </a>
    </p>
</section>

<section class="content no-print">
    @component('components.filters', ['title' => __('report.filters')])
        @include('sell.partials.sell_list_filters', ['only' => [
            'sell_list_filter_location_id',
            'sell_list_filter_customer_id',
            'sell_list_filter_payment_status',
            'sell_list_filter_date_range',
            'sell_list_filter_is_whatnot',
            'created_by',
        ]])
    @endcomponent

    @component('components.widget', ['class' => 'box-primary', 'title' => 'Itemized Sales'])
        <table class="table table-bordered table-striped ajax_view" id="itemized_table">
            <thead>
                <tr>
                    <th>@lang('messages.action')</th>
                    <th>@lang('messages.date')</th>
                    <th>Customer</th>
                    <th>Location</th>
                    <th>Product</th>
                    <th>SKU</th>
                    <th>Artist</th>
                    <th style="text-align:right;">Qty</th>
                    <th style="text-align:right;">Unit Price</th>
                    <th style="text-align:right;">Line Total</th>
                    <th style="text-align:right;">Cost</th>
                    <th style="text-align:right;">Margin</th>
                    <th>Added By</th>
                    <th>Payment Status</th>
                    <th>Payment Method</th>
                    <th>Whatnot</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    @endcomponent
</section>

@stop

@section('javascript')
<script type="text/javascript">
$(document).ready(function () {
    $('#sell_list_filter_date_range').daterangepicker(
        dateRangeSettings,
        function (start, end) {
            $('#sell_list_filter_date_range').val(start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format));
            itemized_table.ajax.reload();
        }
    );
    $('#sell_list_filter_date_range').on('cancel.daterangepicker', function () {
        $('#sell_list_filter_date_range').val('');
        itemized_table.ajax.reload();
    });

    var itemized_table = $('#itemized_table').DataTable({
        processing: true,
        serverSide: true,
        aaSorting: [[1, 'desc']],
        scrollY: '70vh',
        scrollX: true,
        scrollCollapse: true,
        ajax: {
            url: "{{ url('/sales-itemized') }}",
            data: function (d) {
                if ($('#sell_list_filter_date_range').val()) {
                    var start = $('#sell_list_filter_date_range').data('daterangepicker').startDate.format('YYYY-MM-DD');
                    var end = $('#sell_list_filter_date_range').data('daterangepicker').endDate.format('YYYY-MM-DD');
                    d.start_date = start;
                    d.end_date = end;
                }
                d.location_id = $('#sell_list_filter_location_id').val();
                d.customer_id = $('#sell_list_filter_customer_id').val();
                d.payment_status = $('#sell_list_filter_payment_status').val();
                d.is_whatnot = $('#sell_list_filter_is_whatnot').val();
                d.created_by = $('#created_by').val();
            }
        },
        columns: [
            { data: 'action', orderable: false, searchable: false },
            { data: 'transaction_date', name: 't.transaction_date' },
            { data: 'customer', name: 'contacts.name' },
            { data: 'location', name: 'bl.name' },
            { data: 'product_name', name: 'p.name' },
            { data: 'sku', name: 'v.sub_sku' },
            { data: 'artist', name: 'p.artist' },
            { data: 'quantity', name: 'tsl.quantity' },
            { data: 'unit_price', name: 'tsl.unit_price' },
            { data: 'line_total', orderable: false, searchable: false },
            { data: 'cost', orderable: false, searchable: false },
            { data: 'margin', orderable: false, searchable: false },
            { data: 'added_by', name: 'u.first_name' },
            { data: 'payment_status', name: 't.payment_status' },
            { data: 'payment_methods', orderable: false, searchable: false },
            { data: 'is_whatnot', name: 't.is_whatnot', orderable: false, searchable: false },
        ],
    });

    $(document).on('change', '#sell_list_filter_location_id, #sell_list_filter_customer_id, #sell_list_filter_payment_status, #sell_list_filter_is_whatnot, #created_by', function () {
        itemized_table.ajax.reload();
    });
});
</script>
@endsection
