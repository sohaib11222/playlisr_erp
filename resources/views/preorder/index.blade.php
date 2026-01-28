@extends('layouts.app')

@section('title', 'Preorders')

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>Preorders</h1>
</section>

<!-- Main content -->
<section class="content">
    @component('components.widget', ['class' => 'box-primary', 'title' => 'All Preorders'])
        @slot('tool')
            <div class="box-tools">
                <a href="{{ action('PreorderController@create') }}" class="btn btn-block btn-primary">
                    <i class="fa fa-plus"></i> Add Preorder
                </a>
            </div>
        @endslot
        
        <div class="row" style="margin-bottom: 10px;">
            <div class="col-md-3">
                <select class="form-control" id="status_filter">
                    <option value="">All Statuses</option>
                    @foreach($statuses as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        
        <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover" id="preorder_table">
                <thead>
                    <tr>
                        <th>Order Date</th>
                        <th>Customer</th>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Quantity</th>
                        <th>Expected Date</th>
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
        var preorder_table = $('#preorder_table').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ action("PreorderController@index") }}',
                data: function(d) {
                    d.status = $('#status_filter').val();
                }
            },
            columns: [
                { data: 'order_date', name: 'order_date' },
                { data: 'customer_name', name: 'customer_name' },
                { data: 'product_name', name: 'product_name' },
                { data: 'sub_sku', name: 'sub_sku' },
                { data: 'quantity', name: 'quantity' },
                { data: 'expected_date', name: 'expected_date' },
                { data: 'status', name: 'status' },
                { data: 'action', name: 'action', orderable: false, searchable: false },
            ],
        });

        $('#status_filter').on('change', function() {
            preorder_table.ajax.reload();
        });

        $(document).on('click', '.delete_preorder', function(e) {
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
                                preorder_table.ajax.reload();
                            } else {
                                toastr.error(result.msg);
                            }
                        }
                    });
                }
            });
        });

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
                                preorder_table.ajax.reload();
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
