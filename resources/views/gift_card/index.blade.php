@extends('layouts.app')

@section('title', 'Gift Cards')

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>Gift Cards</h1>
</section>

<!-- Main content -->
<section class="content">
    @component('components.widget', ['class' => 'box-primary', 'title' => 'All Gift Cards'])
        @slot('tool')
            <div class="box-tools">
                <a href="{{ action('GiftCardController@create') }}" class="btn btn-block btn-primary">
                    <i class="fa fa-plus"></i> Add
                </a>
            </div>
        @endslot
        <div class="table-responsive" style="width: 100%;">
            <table class="table table-bordered table-striped table-hover" id="gift_card_table" style="width: 100%;">
                <thead>
                    <tr>
                        <th>Card Number</th>
                        <th>Customer</th>
                        <th>Initial Value</th>
                        <th>Balance</th>
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
        var gift_card_table = $('#gift_card_table').DataTable({
            processing: true,
            serverSide: true,
            ajax: '{{ action("GiftCardController@index") }}',
            autoWidth: false,
            scrollX: false,
            columnDefs: [
                { width: '10%', targets: 0 },  // Card Number
                { width: '25%', targets: 1 },  // Customer
                { width: '12%', targets: 2 },  // Initial Value
                { width: '12%', targets: 3 },  // Balance
                { width: '12%', targets: 4 },  // Expiry Date
                { width: '10%', targets: 5 },  // Status
                { width: '10%', targets: 6 },  // Action
            ],
            columns: [
                { data: 'card_number', name: 'card_number' },
                { data: 'contact', name: 'contact' },
                { data: 'initial_value', name: 'initial_value' },
                { data: 'balance', name: 'balance' },
                { data: 'expiry_date', name: 'expiry_date' },
                { data: 'status', name: 'status' },
                { data: 'action', name: 'action', orderable: false, searchable: false },
            ],
        });

        $(document).on('click', '.delete_gift_card_button', function(e) {
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
                                gift_card_table.ajax.reload();
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

