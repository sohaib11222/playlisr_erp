@extends('layouts.app')

@section('title', 'In Store Orders')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

<style>
body.pos-v2 .pickup-wrap { max-width: 1280px; margin: 0 auto; padding: 18px 16px 60px; font-family: "Inter Tight", system-ui, sans-serif; color: var(--pos-ink); }
body.pos-v2 .pickup-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; flex-wrap: wrap; }
body.pos-v2 .pickup-head h1 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
body.pos-v2 .pickup-head .sub { color: #6b6253; margin: 0; font-size: 14px; }
body.pos-v2 .pickup-card { background: var(--pos-surface); border: 1px solid var(--pos-line); border-radius: 14px; padding: 18px 20px; margin-bottom: 20px; }
body.pos-v2 .pickup-toolbar { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
body.pos-v2 .pickup-toolbar .filter-label { font-size: 12px; font-weight: 600; color: #5a5145; }
body.pos-v2 .pickup-toolbar select {
  border: 1px solid var(--pos-line-2); border-radius: 9px; padding: 8px 11px; font-size: 14px;
  font-family: inherit; background: #fff; box-shadow: none; height: auto; color: var(--pos-ink); min-width: 200px; }
body.pos-v2 .pickup-toolbar select:focus { outline: none; border-color: var(--pos-accent-deep); box-shadow: 0 0 0 3px var(--pos-accent-soft); }
body.pos-v2 .btn-accent { background: var(--pos-accent); color: var(--pos-accent-text); border: 1px solid var(--pos-accent-deep);
  border-radius: 10px; padding: 10px 18px; font-weight: 700; font-size: 14px; cursor: pointer; font-family: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 7px; }
body.pos-v2 .btn-accent:hover { background: var(--pos-accent-deep); color: var(--pos-accent-text); }
body.pos-v2 #order_table { width: 100% !important; border-collapse: collapse; }
body.pos-v2 #order_table thead th {
  text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em;
  color: #8a8070; font-weight: 700; padding: 9px 10px; border-bottom: 1px solid var(--pos-line); background: transparent; }
body.pos-v2 #order_table tbody td { padding: 11px 10px; border-bottom: 1px solid var(--pos-line); font-size: 13.5px; vertical-align: middle; color: var(--pos-ink); }
body.pos-v2 #order_table tbody tr:hover { background: var(--pos-accent-soft); }
body.pos-v2 #order_table .label { font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 999px; }
body.pos-v2 #order_table .btn-group { display: inline-flex; gap: 5px; }
body.pos-v2 #order_table .btn-xs { border-radius: 8px; font-family: inherit; font-weight: 600; }
body.pos-v2 .dataTables_wrapper .dataTables_filter input,
body.pos-v2 .dataTables_wrapper .dataTables_length select {
  border: 1px solid var(--pos-line-2); border-radius: 8px; padding: 6px 9px; font-family: inherit; background: #fff; }
body.pos-v2 .dataTables_wrapper .dataTables_filter input:focus { outline: none; border-color: var(--pos-accent-deep); box-shadow: 0 0 0 3px var(--pos-accent-soft); }
body.pos-v2 .dataTables_wrapper .dataTables_info,
body.pos-v2 .dataTables_wrapper .dataTables_length,
body.pos-v2 .dataTables_wrapper .dataTables_filter { color: #8a8070; font-size: 13px; }
body.pos-v2 .dataTables_wrapper .dataTables_paginate .paginate_button.current {
  background: var(--pos-accent) !important; border: 1px solid var(--pos-accent-deep) !important; color: var(--pos-accent-text) !important; border-radius: 8px; }
body.pos-v2 .dataTables_wrapper .dataTables_paginate .paginate_button { border-radius: 8px; }
</style>

<div class="pickup-wrap">
    <div class="pickup-head">
        <div>
            <h1>In Store Orders</h1>
            <p class="sub">Walk-in items you're holding for a customer — name, item, price paid, and paid status. Hit <strong>Notify</strong> when it's ready to alert them by email or text.</p>
        </div>
        <a href="{{ action('InStoreOrderController@create') }}" class="btn-accent">
            <i class="fa fa-plus"></i> Add Order
        </a>
    </div>

    @if(is_string(session('status')))<div class="alert-ok">{{ session('status') }}</div>@endif
    @if(is_string(session('error')))<div class="alert-err">{{ session('error') }}</div>@endif

    <div class="pickup-card">
        <div class="pickup-toolbar">
            <span class="filter-label">Show:</span>
            <select id="status_filter">
                @foreach($statuses as $key => $label)
                    <option value="{{ $key }}" {{ $key == 'pending' ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
                <option value="">All Statuses</option>
            </select>
        </div>

        <div class="table-responsive">
            <table class="table table-hover" id="order_table" style="width:100%">
                <thead>
                    <tr>
                        <th>Store</th>
                        <th>Customer</th>
                        <th>Item</th>
                        <th>Price Paid</th>
                        <th>Paid?</th>
                        <th>Status</th>
                        <th>Notified</th>
                        <th>Created By</th>
                        <th>Action</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="notify_modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="notify_form">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title">Notify Customer</h4>
                </div>
                <div class="modal-body">
                    <p>Let the customer know their order is ready for pickup.</p>
                    <div class="radio"><label><input type="radio" name="notify_method" value="email" checked> Email</label></div>
                    <div class="radio"><label><input type="radio" name="notify_method" value="sms"> Text (OpenPhone)</label></div>
                    <div class="radio"><label><input type="radio" name="notify_method" value="both"> Both</label></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Send</button>
                </div>
            </form>
        </div>
    </div>
</div>

@stop
@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        var order_table = $('#order_table').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ action("InStoreOrderController@index") }}',
                data: function(d) {
                    d.status = $('#status_filter').val();
                }
            },
            columns: [
                { data: 'location_name', name: 'business_locations.name', defaultContent: '-' },
                { data: 'customer_name', name: 'customer_name' },
                { data: 'item_name', name: 'item_name' },
                { data: 'price_paid', name: 'price_paid' },
                { data: 'is_paid_label', name: 'is_paid', orderable: false, searchable: false },
                { data: 'status', name: 'status' },
                { data: 'notified_info', name: 'notified_at', orderable: false, searchable: false },
                { data: 'created_info', name: 'created_info', orderable: false, searchable: false },
                { data: 'action', name: 'action', orderable: false, searchable: false },
            ],
            order: [[7, 'desc']],
        });

        $('#status_filter').on('change', function() {
            order_table.ajax.reload();
        });

        $(document).on('click', '.delete_order', function(e) {
            e.preventDefault();
            var url = $(this).attr('data-href');
            swal({
                title: LANG.sure,
                icon: "warning",
                buttons: true,
                dangerMode: true,
            }).then((confirmed) => {
                if (confirmed) {
                    $.ajax({
                        method: 'DELETE',
                        url: url,
                        dataType: 'json',
                        success: function(result) {
                            if (result.success) {
                                toastr.success(result.msg);
                                order_table.ajax.reload();
                            } else {
                                toastr.error(result.msg);
                            }
                        }
                    });
                }
            });
        });

        $(document).on('click', '.mark_complete', function(e) {
            e.preventDefault();
            var url = $(this).attr('data-href');
            $.ajax({
                method: 'POST',
                url: url,
                data: { _token: $('meta[name="csrf-token"]').attr('content') },
                dataType: 'json',
                success: function(result) {
                    if (result.success) {
                        toastr.success(result.msg);
                        order_table.ajax.reload();
                    } else {
                        toastr.error(result.msg);
                    }
                }
            });
        });

        var notify_url = null;
        $(document).on('click', '.notify_customer', function(e) {
            e.preventDefault();
            notify_url = $(this).attr('data-href');
            $('#notify_form')[0].reset();
            $('#notify_modal').modal('show');
        });

        $('#notify_form').on('submit', function(e) {
            e.preventDefault();
            if (!notify_url) return;
            var $btn = $(this).find('button[type="submit"]').prop('disabled', true);
            $.ajax({
                method: 'POST',
                url: notify_url,
                data: $(this).serialize() + '&_token=' + encodeURIComponent($('meta[name="csrf-token"]').attr('content') || ''),
                dataType: 'json',
                success: function(result) {
                    $('#notify_modal').modal('hide');
                    $btn.prop('disabled', false);
                    if (result.success) {
                        toastr.success(result.msg);
                        var n = result.notifications || {};
                        Object.keys(n).forEach(function(k) {
                            (n[k].ok ? toastr.success : toastr.warning)(k + ': ' + n[k].msg);
                        });
                        order_table.ajax.reload();
                    } else {
                        toastr.error(result.msg);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false);
                    toastr.error('Something went wrong.');
                }
            });
        });
    });
</script>
@stop
