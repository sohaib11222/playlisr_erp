@extends('layouts.app')

@section('title', 'Customer Pickups')

@section('content')

<section class="content-header">
    <h1>Customer Pickups</h1>
</section>

<section class="content">
    @component('components.widget', ['class' => 'box-primary', 'title' => 'All Customer Pickups'])
        @slot('tool')
            <div class="box-tools">
                <a href="{{ action('CustomerPickupController@create') }}" class="btn btn-block btn-primary">
                    <i class="fa fa-plus"></i> Add Pickup
                </a>
            </div>
        @endslot

        <div class="row" style="margin-bottom: 10px;">
            <div class="col-md-3">
                <select class="form-control" id="status_filter">
                    <option value="">All Statuses</option>
                    @foreach($statuses as $key => $label)
                        <option value="{{ $key }}" {{ $key == 'ready' ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover" id="pickup_table">
                <thead>
                    <tr>
                        <th>Hold Date</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Qty</th>
                        <th>Location</th>
                        <th>Expected Pickup</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
            </table>
        </div>
    @endcomponent
</section>

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
                        <input type="text" class="form-control" name="picked_up_by_name" placeholder="Who picked it up?">
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
                { data: 'hold_date', name: 'hold_date' },
                { data: 'customer_name', name: 'customer_name' },
                { data: 'product_name', name: 'product_name' },
                { data: 'sub_sku', name: 'sub_sku' },
                { data: 'quantity', name: 'quantity' },
                { data: 'location_name', name: 'location_name' },
                { data: 'expected_pickup_date', name: 'expected_pickup_date' },
                { data: 'status', name: 'status' },
                { data: 'action', name: 'action', orderable: false, searchable: false },
            ],
            order: [[0, 'desc']],
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
    });
</script>
@stop
