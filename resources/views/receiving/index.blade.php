@extends('layouts.app')

@section('title', 'Receiving')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

<style>
body.pos-v2 .rcv-wrap { max-width: 1280px; margin: 0 auto; padding: 18px 16px 60px; font-family: "Inter Tight", system-ui, sans-serif; color: var(--pos-ink); }
body.pos-v2 .rcv-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; flex-wrap: wrap; }
body.pos-v2 .rcv-head h1 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
body.pos-v2 .rcv-head .sub { color: #6b6253; margin: 0; font-size: 14px; }
body.pos-v2 .rcv-head .sub a { color: var(--pos-accent-deep); font-weight: 600; }
body.pos-v2 .rcv-card { background: var(--pos-surface); border: 1px solid var(--pos-line); border-radius: 14px; padding: 18px 20px; margin-bottom: 20px; }
body.pos-v2 .rcv-toolbar { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
body.pos-v2 .rcv-toolbar .filter-label { font-size: 12px; font-weight: 600; color: #5a5145; }
body.pos-v2 .rcv-toolbar select {
  border: 1px solid var(--pos-line-2); border-radius: 9px; padding: 8px 11px; font-size: 14px;
  font-family: inherit; background: #fff; box-shadow: none; height: auto; color: var(--pos-ink); min-width: 200px; }
body.pos-v2 .btn-accent { background: var(--pos-accent); color: var(--pos-accent-text); border: 1px solid var(--pos-accent-deep);
  border-radius: 10px; padding: 10px 18px; font-weight: 700; font-size: 14px; cursor: pointer; font-family: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 7px; }
body.pos-v2 .btn-accent:hover { background: var(--pos-accent-deep); color: var(--pos-accent-text); }
body.pos-v2 .btn-ghost { background: transparent; border: 1px solid var(--pos-line-2); border-radius: 10px;
  padding: 10px 18px; font-weight: 600; font-size: 14px; cursor: pointer; color: #5a5145; font-family: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 7px; }
body.pos-v2 #receiving_table { width: 100% !important; border-collapse: collapse; }
body.pos-v2 #receiving_table thead th {
  text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em;
  color: #8a8070; font-weight: 700; padding: 9px 10px; border-bottom: 1px solid var(--pos-line); background: transparent; }
body.pos-v2 #receiving_table tbody td { padding: 11px 10px; border-bottom: 1px solid var(--pos-line); font-size: 13.5px; vertical-align: middle; color: var(--pos-ink); }
body.pos-v2 #receiving_table tbody tr:hover { background: var(--pos-accent-soft); }
body.pos-v2 #receiving_table .label { font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 999px; }
</style>

<div class="rcv-wrap">
    <div class="rcv-head">
        <div>
            <h1>Receiving</h1>
            <p class="sub">Every package that's come in — mail, boxes, bags, retail deliveries, listening-event boxes. <a href="{{ action('ReceivingPackageController@inProgressQueue') }}">See what's still waiting to be priced &rarr;</a></p>
        </div>
        <a href="{{ action('ReceivingPackageController@create') }}" class="btn-accent">
            <i class="fa fa-plus"></i> Log a Package
        </a>
    </div>

    <div class="rcv-card">
        <div class="rcv-toolbar">
            <span class="filter-label">Status:</span>
            <select id="status_filter">
                <option value="">All</option>
                @foreach($statuses as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            <span class="filter-label">Type:</span>
            <select id="type_filter">
                <option value="">All</option>
                @foreach(\App\ReceivingPackage::$packageTypes as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="table-responsive">
            <table class="table table-hover" id="receiving_table" style="width:100%">
                <thead>
                    <tr>
                        <th>Received</th>
                        <th>Type</th>
                        <th>Store</th>
                        <th>Bin</th>
                        <th>Distributor</th>
                        <th>Order #</th>
                        <th>Invoice #</th>
                        <th>Received By</th>
                        <th>Photo</th>
                        <th>Items</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

@stop
@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        var receiving_table = $('#receiving_table').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ action("ReceivingPackageController@index") }}',
                data: function(d) {
                    d.status = $('#status_filter').val();
                    d.package_type = $('#type_filter').val();
                }
            },
            columns: [
                { data: 'received_at', name: 'received_at' },
                { data: 'package_type', name: 'package_type' },
                { data: 'location_name', name: 'business_locations.name', defaultContent: '-' },
                { data: 'bin_location', name: 'receiving_packages.bin_location', defaultContent: '-' },
                { data: 'distributor', name: 'receiving_packages.distributor', defaultContent: '-' },
                { data: 'order_number', name: 'order_number', defaultContent: '-' },
                { data: 'invoice_number', name: 'invoice_number', defaultContent: '-' },
                { data: 'received_by_name', name: 'received_by_name', defaultContent: '-' },
                { data: 'photo', name: 'receiving_packages.photo', orderable: false, searchable: false, defaultContent: '-' },
                { data: 'items_progress', name: 'items_progress', orderable: false, searchable: false },
                { data: 'status', name: 'status' },
                { data: 'action', name: 'action', orderable: false, searchable: false },
            ],
            order: [[0, 'desc']],
        });

        $('#status_filter, #type_filter').on('change', function() {
            receiving_table.ajax.reload();
        });

        $(document).on('click', '.delete_package', function(e) {
            e.preventDefault();
            var url = $(this).attr('data-href');
            swal({
                title: LANG.sure,
                text: 'This removes the package and everything logged inside it.',
                icon: 'warning',
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
                                receiving_table.ajax.reload();
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
