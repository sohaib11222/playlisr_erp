@extends('layouts.app')

@section('title', 'Preorder Details')

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>Preorder Details</h1>
</section>

<!-- Main content -->
<section class="content">
    <div class="row">
        <div class="col-md-12">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Preorder Information</h3>
                </div>
                <div class="box-body">
                    <table class="table table-bordered">
                        <tr>
                            <th width="30%">Customer:</th>
                            <td>{{ $preorder->contact->name }}</td>
                        </tr>
                        <tr>
                            <th>Product:</th>
                            <td>{{ $preorder->product->name ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Variation/SKU:</th>
                            <td>{{ $preorder->variation->sub_sku ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Quantity:</th>
                            <td>{{ $preorder->quantity }}</td>
                        </tr>
                        <tr>
                            <th>Order Date:</th>
                            <td>{{ \Carbon\Carbon::parse($preorder->order_date)->format('Y-m-d') }}</td>
                        </tr>
                        <tr>
                            <th>Expected Date:</th>
                            <td>{{ $preorder->expected_date ? \Carbon\Carbon::parse($preorder->expected_date)->format('Y-m-d') : 'Not set' }}</td>
                        </tr>
                        <tr>
                            <th>Status:</th>
                            <td>
                                @if($preorder->status == 'pending')
                                    <span class="label label-warning">Pending</span>
                                @elseif($preorder->status == 'fulfilled')
                                    <span class="label label-success">Fulfilled</span>
                                @else
                                    <span class="label label-danger">Cancelled</span>
                                @endif
                            </td>
                        </tr>
                        @if($preorder->notes)
                        <tr>
                            <th>Notes:</th>
                            <td>{{ $preorder->notes }}</td>
                        </tr>
                        @endif
                        <tr>
                            <th>Created By:</th>
                            <td>{{ $preorder->creator->user_full_name ?? 'N/A' }}</td>
                        </tr>
                        <tr>
                            <th>Created At:</th>
                            <td>{{ $preorder->created_at->format('Y-m-d H:i:s') }}</td>
                        </tr>
                    </table>
                </div>
                <div class="box-footer">
                    <a href="{{ action('PreorderController@index') }}" class="btn btn-default">Back</a>
                    @if($preorder->status == 'pending')
                        <a href="{{ action('PreorderController@edit', [$preorder->id]) }}" class="btn btn-warning">Edit</a>
                        <button type="button" class="btn btn-success fulfill_preorder" data-href="{{ action('PreorderController@fulfill', [$preorder->id]) }}">Mark as Fulfilled</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</section>
<!-- /.content -->

@stop
@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        $(document).on('click', '.fulfill_preorder', function(e) {
            e.preventDefault();
            var url = $(this).attr('data-href');
            swal({
                title: 'Mark as Fulfilled?',
                text: 'This will mark the preorder as fulfilled.',
                icon: "info",
                buttons: true,
            }).then((confirmed) => {
                if (confirmed) {
                    $.ajax({
                        method: 'POST',
                        url: url,
                        dataType: 'json',
                        success: function(result) {
                            if (result.success) {
                                toastr.success(result.msg);
                                window.location.reload();
                            } else {
                                toastr.error(result.msg);
                            }
                        }
                    });
                }
            });
        });
    });
</script>
@stop
