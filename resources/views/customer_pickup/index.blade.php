@extends('layouts.app')

@section('title', 'Customer Pickups')

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

/* DataTable, pos-v2 skin */
body.pos-v2 #pickup_table { width: 100% !important; border-collapse: collapse; }
body.pos-v2 #pickup_table thead th {
  text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em;
  color: #8a8070; font-weight: 700; padding: 9px 10px; border-bottom: 1px solid var(--pos-line); background: transparent; }
body.pos-v2 #pickup_table tbody td { padding: 11px 10px; border-bottom: 1px solid var(--pos-line); font-size: 13.5px; vertical-align: middle; color: var(--pos-ink); }
body.pos-v2 #pickup_table tbody tr:hover { background: var(--pos-accent-soft); }
body.pos-v2 #pickup_table .label { font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 999px; }
body.pos-v2 #pickup_table .btn-group { display: inline-flex; gap: 5px; }
body.pos-v2 #pickup_table .btn-xs { border-radius: 8px; font-family: inherit; font-weight: 600; }
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
            <h1>Customer Pickups</h1>
            <p class="sub">Items held for customers and AMS special orders on their way in. Hit <strong>Arrived</strong> on an on-order item when it lands to alert the customer.</p>
        </div>
        <a href="{{ action('CustomerPickupController@create') }}" class="btn-accent">
            <i class="fa fa-plus"></i> Add Pickup
        </a>
    </div>

    <div class="pickup-card">
        <div class="pickup-toolbar">
            <span class="filter-label">Show:</span>
            <select id="status_filter">
                <option value="">All Statuses</option>
                @foreach($statuses as $key => $label)
                    <option value="{{ $key }}" {{ $key == 'ready' ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="table-responsive">
            <table class="table table-hover" id="pickup_table" style="width:100%">
                <thead>
                    <tr>
                        <th>Store</th>
                        <th>Hold Date</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Qty</th>
                        <th>Expected Pickup</th>
                        <th>Paid?</th>
                        <th>Status</th>
                        <th>Picked Up By</th>
                        <th>Created By</th>
                        <th>Action</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="pickup_completion_modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="pickup_completion_form">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title">Mark as Picked Up</h4>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Picked up by (name, optional):</label>
                        <input type="text" class="form-control" name="picked_up_by_name" placeholder="Who physically picked it up?">
                        <small class="help-block">Your cashier name + timestamp are captured automatically.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Confirm Pickup</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="pickup_arrived_modal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="pickup_arrived_form">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title">Mark Arrived</h4>
                </div>
                <div class="modal-body">
                    <p>This special order just came in. Notify the customer it's ready for pickup?</p>
                    <div class="radio"><label><input type="radio" name="notify_method" value="none" checked> Don't notify — I'll let them know</label></div>
                    <div class="radio"><label><input type="radio" name="notify_method" value="email"> Email</label></div>
                    <div class="radio"><label><input type="radio" name="notify_method" value="sms"> Text (OpenPhone)</label></div>
                    <div class="radio"><label><input type="radio" name="notify_method" value="both"> Both</label></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Mark Arrived</button>
                </div>
            </form>
        </div>
    </div>
</div>

@stop
@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        var pickup_table = $('#pickup_table').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ action("CustomerPickupController@index") }}',
                data: function(d) {
                    d.status = $('#status_filter').val();
                }
            },
            columns: [
                { data: 'location_name', name: 'business_locations.name', defaultContent: '-' },
                { data: 'hold_date', name: 'hold_date' },
                { data: 'customer_name', name: 'contacts.name' },
                { data: 'product_name', name: 'products.name', defaultContent: '-' },
                { data: 'sub_sku', name: 'variations.sub_sku', defaultContent: '-' },
                { data: 'quantity', name: 'quantity' },
                { data: 'expected_pickup_date', name: 'expected_pickup_date' },
                { data: 'is_paid_label', name: 'is_paid', orderable: false, searchable: false },
                { data: 'status', name: 'status' },
                { data: 'picked_up_info', name: 'picked_up_info', orderable: false, searchable: false },
                { data: 'created_info', name: 'created_info', orderable: false, searchable: false },
                { data: 'action', name: 'action', orderable: false, searchable: false },
            ],
            order: [[1, 'desc']],
        });

        $('#status_filter').on('change', function() {
            pickup_table.ajax.reload();
        });

        $(document).on('click', '.delete_pickup', function(e) {
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
                                pickup_table.ajax.reload();
                            } else {
                                toastr.error(result.msg);
                            }
                        }
                    });
                }
            });
        });

        var pickup_url_to_complete = null;
        $(document).on('click', '.mark_picked_up', function(e) {
            e.preventDefault();
            pickup_url_to_complete = $(this).attr('data-href');
            $('#pickup_completion_form')[0].reset();
            $('#pickup_completion_modal').modal('show');
        });

        $('#pickup_completion_form').on('submit', function(e) {
            e.preventDefault();
            if (!pickup_url_to_complete) return;
            $.ajax({
                method: 'POST',
                url: pickup_url_to_complete,
                data: $(this).serialize(),
                dataType: 'json',
                success: function(result) {
                    $('#pickup_completion_modal').modal('hide');
                    if (result.success) {
                        toastr.success(result.msg);
                        pickup_table.ajax.reload();
                    } else {
                        toastr.error(result.msg);
                    }
                }
            });
        });

        // AMS special order arrived → optional customer alert.
        var pickup_url_to_arrive = null;
        $(document).on('click', '.mark_arrived', function(e) {
            e.preventDefault();
            pickup_url_to_arrive = $(this).attr('data-href');
            $('#pickup_arrived_form')[0].reset();
            $('#pickup_arrived_modal').modal('show');
        });

        $('#pickup_arrived_form').on('submit', function(e) {
            e.preventDefault();
            if (!pickup_url_to_arrive) return;
            var $btn = $(this).find('button[type="submit"]').prop('disabled', true);
            $.ajax({
                method: 'POST',
                url: pickup_url_to_arrive,
                data: $(this).serialize() + '&_token=' + encodeURIComponent($('meta[name="csrf-token"]').attr('content') || ''),
                dataType: 'json',
                success: function(result) {
                    $('#pickup_arrived_modal').modal('hide');
                    $btn.prop('disabled', false);
                    if (result.success) {
                        toastr.success(result.msg);
                        var n = result.notifications || {};
                        Object.keys(n).forEach(function(k) {
                            (n[k].ok ? toastr.success : toastr.warning)(k + ': ' + n[k].msg);
                        });
                        pickup_table.ajax.reload();
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
