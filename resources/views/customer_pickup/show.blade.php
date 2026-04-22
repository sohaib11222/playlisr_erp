@extends('layouts.app')

@section('title', 'Pickup Details')

@section('content')

<section class="content-header">
    <h1>Customer Pickup Details</h1>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-12">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Pickup Information</h3>
                </div>
                <div class="box-body">
                    <table class="table table-bordered">
                        <tr>
                            <th width="30%">Customer:</th>
                            <td>
                                {{ $pickup->contact->name }}
                                @if($pickup->contact->mobile)
                                    <br><small><i class="fa fa-phone"></i> {{ $pickup->contact->mobile }}</small>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th>Location:</th>
                            <td>{{ $pickup->location->name ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Product:</th>
                            <td>{{ $pickup->product->name ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Variation/SKU:</th>
                            <td>{{ $pickup->variation->sub_sku ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Quantity:</th>
                            <td>{{ $pickup->quantity }}</td>
                        </tr>
                        <tr>
                            <th>Hold Date:</th>
                            <td>{{ \Carbon\Carbon::parse($pickup->hold_date)->format('Y-m-d') }}</td>
                        </tr>
                        <tr>
                            <th>Expected Pickup Date:</th>
                            <td>{{ $pickup->expected_pickup_date ? \Carbon\Carbon::parse($pickup->expected_pickup_date)->format('Y-m-d') : 'Not set' }}</td>
                        </tr>
                        <tr>
                            <th>Status:</th>
                            <td>
                                @if($pickup->status == 'ready')
                                    <span class="label label-warning">Ready for Pickup</span>
                                @elseif($pickup->status == 'picked_up')
                                    <span class="label label-success">Picked Up</span>
                                @else
                                    <span class="label label-danger">Cancelled</span>
                                @endif
                            </td>
                        </tr>
                        @if($pickup->picked_up_at)
                        <tr>
                            <th>Picked Up At:</th>
                            <td>{{ $pickup->picked_up_at->format('m/d/Y h:i A') }}</td>
                        </tr>
                        @endif
                        @if($pickup->picked_up_by_name)
                        <tr>
                            <th>Picked Up By:</th>
                            <td>{{ $pickup->picked_up_by_name }}</td>
                        </tr>
                        @endif
                        @if($pickup->notes)
                        <tr>
                            <th>Notes:</th>
                            <td>{{ $pickup->notes }}</td>
                        </tr>
                        @endif
                        <tr>
                            <th>Created By:</th>
                            <td>{{ $pickup->creator->user_full_name ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Created At:</th>
                            <td>{{ $pickup->created_at->format('m/d/Y h:i A') }}</td>
                        </tr>
                    </table>
                </div>
                <div class="box-footer">
                    <a href="{{ action('CustomerPickupController@index') }}" class="btn btn-default">Back</a>
                    @if($pickup->status == 'ready')
                        <a href="{{ action('CustomerPickupController@edit', [$pickup->id]) }}" class="btn btn-warning">Edit</a>
                        <button type="button" class="btn btn-success mark_picked_up" data-href="{{ action('CustomerPickupController@markPickedUp', [$pickup->id]) }}">Mark as Picked Up</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
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
                        window.location.reload();
                    } else {
                        toastr.error(result.msg);
                    }
                }
            });
        });
    });
</script>
@stop
