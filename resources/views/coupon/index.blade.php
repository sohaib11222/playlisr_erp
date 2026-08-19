@extends('layouts.app')

@section('title', 'Coupons')

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>Coupons</h1>
</section>

<!-- Main content -->
<section class="content">
    @component('components.widget', ['class' => 'box-primary', 'title' => 'All Coupons'])
        @slot('tool')
            <div class="box-tools">
                <a href="{{ action('CouponController@create') }}" class="btn btn-block btn-primary">
                    <i class="fa fa-plus"></i> Add
                </a>
            </div>
        @endslot
        <div class="table-responsive" style="width: 100%;">
            <table class="table table-bordered table-striped table-hover" id="coupon_table" style="width: 100%;">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Discount</th>
                        <th>Min. Order</th>
                        <th>Usage</th>
                        <th>Expiry Date</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
            </table>
        </div>
    @endcomponent

</section>
<!-- /.content -->

@stop
@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        var coupon_table = $('#coupon_table').DataTable({
            processing: true,
            serverSide: true,
            ajax: '{{ action("CouponController@index") }}',
            autoWidth: false,
            scrollX: false,
            columnDefs: [
                { width: '14%', targets: 0 }, // Code
                { width: '14%', targets: 1 }, // Discount
                { width: '14%', targets: 2 }, // Min. Order
                { width: '14%', targets: 3 }, // Usage
                { width: '14%', targets: 4 }, // Expiry Date
                { width: '10%', targets: 5 }, // Status
                { width: '10%', targets: 6 }, // Action
            ],
            columns: [
                { data: 'code', name: 'code' },
                { data: 'discount', name: 'discount', orderable: false, searchable: false },
                { data: 'min_order_amount', name: 'min_order_amount' },
                { data: 'usage', name: 'usage', orderable: false, searchable: false },
                { data: 'expiry_date', name: 'expiry_date' },
                { data: 'status', name: 'status' },
                { data: 'action', name: 'action', orderable: false, searchable: false },
            ],
        });

        $(document).on('click', '.delete_coupon_button', function(e) {
            e.preventDefault();
            var url = $(this).attr('href');
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
                                coupon_table.ajax.reload();
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
@endsection
