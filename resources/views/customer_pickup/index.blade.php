@extends('layouts.app')

@section('title', 'Customer Pickups')

@section('content')
@include('sale_pos.partials._redesign_v2')
@include('events.partials._styles')
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

/* DataTable, pos-v2 skin — shared by the AMS pickups table and the
   preorders table below it so both read as one design. */
body.pos-v2 #pickup_table, body.pos-v2 #preorder_table { width: 100% !important; border-collapse: collapse; }
body.pos-v2 #pickup_table thead th, body.pos-v2 #preorder_table thead th {
  text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em;
  color: #8a8070; font-weight: 700; padding: 9px 10px; border-bottom: 1px solid var(--pos-line); background: transparent; }
body.pos-v2 #pickup_table tbody td, body.pos-v2 #preorder_table tbody td { padding: 11px 10px; border-bottom: 1px solid var(--pos-line); font-size: 13.5px; vertical-align: middle; color: var(--pos-ink); }
body.pos-v2 #pickup_table tbody tr:hover, body.pos-v2 #preorder_table tbody tr:hover { background: var(--pos-accent-soft); }
body.pos-v2 #pickup_table .label, body.pos-v2 #preorder_table .label, body.pos-v2 .wp-table .label { font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 999px; }
body.pos-v2 #pickup_table .btn-group, body.pos-v2 #preorder_table .btn-group { display: inline-flex; gap: 5px; }
body.pos-v2 #pickup_table .btn-xs, body.pos-v2 #preorder_table .btn-xs, body.pos-v2 .wp-table .btn-xs { border-radius: 8px; font-family: inherit; font-weight: 600; }
/* Picked-up rows stay listed, tinted green (Sarah, 2026-10-07). */
body.pos-v2 .wp-table tbody tr.row-picked-up td, body.pos-v2 #preorder_table tbody tr.row-picked-up td { background: #e6f4ea; color: #4b6b52; }
body.pos-v2 .wp-table .source-select {
  border: 1px solid var(--pos-line-2); border-radius: 8px; padding: 4px 8px; font-size: 12px; font-family: inherit;
  background: #fff; color: var(--pos-ink); max-width: 170px; text-overflow: ellipsis; }
body.pos-v2 .preorder-toggle { display: inline-flex; gap: 8px; }
body.pos-v2 .preorder-toggle .btn-accent, body.pos-v2 .preorder-toggle .btn-ghost { padding: 8px 16px; font-size: 13px; }
/* Paid/unpaid status is information, not an action — keep it visually
   distinct from the accent action buttons (Mark paid / Mark picked up)
   in the same row so they don't read as the same kind of control. */
body.pos-v2 #preorder_table .pill-paid, body.pos-v2 .wp-table .pill-paid { background: #e6f4ea; color: #2e7d32; border-color: #cce8d4; }
body.pos-v2 .wp-table .pill { display:inline-block; font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 999px; border: 1px solid transparent; }
/* Pickup Orders list: same header/row rhythm as the table above. */
body.pos-v2 .wp-table { width: 100% !important; border-collapse: collapse; }
body.pos-v2 .wp-table thead th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: #8a8070;
  font-weight: 700; padding: 9px 22px 9px 10px !important; border-bottom: 1px solid var(--pos-line); background: transparent; white-space: nowrap; }
body.pos-v2 .wp-table tbody td { padding: 12px 10px; border-bottom: 1px solid var(--pos-line); vertical-align: top; color: var(--pos-ink); line-height: 1.45; }
body.pos-v2 .wp-table tbody tr:hover td { background: var(--pos-accent-soft); }
body.pos-v2 .wp-table .label { white-space: nowrap; display: inline-block; line-height: 1.4; }
body.pos-v2 .wp-table .status-sub { display: block; font-size: 12px; color: #6b6253; margin-top: 5px; white-space: nowrap; }
body.pos-v2 .wp-table .item-meta { display: flex; align-items: center; gap: 10px; margin-top: 6px; font-size: 12px; flex-wrap: wrap; }
body.pos-v2 .wp-table .src-tag { font-weight: 600; }
body.pos-v2 .wp-table .pill-ready { background: #2e7d32; color: #fff; }
body.pos-v2 .wp-table .pill-ready-from { background: #e6f4ea; color: #2e7d32; border: 1px solid #a8d5b5; }
/* Fit the card width, no sideways scroll (Sarah, 2026-10-08). */
body.pos-v2 #website-pickups .table-responsive { overflow-x: visible; border: 0; }
body.pos-v2 .wp-table tbody td { padding: 10px 6px; }
body.pos-v2 .wp-table thead th { padding: 9px 16px 9px 6px !important; }
body.pos-v2 .wp-table .cust-cell { min-width: 125px; }
body.pos-v2 .wp-table .cust-line { white-space: normal; word-break: break-word; }
body.pos-v2 .wp-table .item-cell { min-width: 150px; }
/* Before DataTables applies the tabs, don't flash older/picked-up rows. */
body.pos-v2 #website_pickup_table:not(.dataTable) tr.row-older,
body.pos-v2 #website_pickup_table:not(.dataTable) tr.row-picked-up { display: none; }
body.pos-v2 .wp-table .src-meta { flex-direction: column; align-items: flex-start; gap: 4px; }
body.pos-v2 .wp-table .source-select { max-width: 112px; font-size: 11.5px; padding: 3px 6px; }
body.pos-v2 .wp-table .act-select { border: 1px solid var(--pos-line-2); border-radius: 8px; padding: 5px 8px; font-size: 12px; font-family: inherit;
  background: #fff; color: var(--pos-ink); width: 156px; height: 30px; }
body.pos-v2 .wp-table .act-select:focus { outline: none; border-color: var(--pos-accent-deep); box-shadow: 0 0 0 3px var(--pos-accent-soft); }
body.pos-v2 .wp-table .act-btn { display: block; margin-top: 6px; width: 156px; padding: 5px 8px; font-size: 12px; border-radius: 8px; text-align: center; }
body.pos-v2 #website_pickup_search { border: 1px solid var(--pos-line-2); border-radius: 8px; padding: 7px 10px; font-family: inherit; background: #fff; min-width: 240px; }
body.pos-v2 #website_pickup_search:focus { outline: none; border-color: var(--pos-accent-deep); box-shadow: 0 0 0 3px var(--pos-accent-soft); }
body.pos-v2 #preorder_table .pill-unpaid, body.pos-v2 .wp-table .pill-unpaid { background: #fdeaea; color: #a23; border-color: #f3cccc; }
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

    @if(is_string(session('status')))<div class="alert-ok">{{ session('status') }}</div>@endif
    @if(is_string(session('error')))<div class="alert-err">{{ session('error') }}</div>@endif

    {{-- In-store holds / AMS orders: hidden while there's nothing in it (Sarah, 2026-10-08). --}}
    <div class="pickup-card" id="store_pickups_card" style="display:none;">
        <div class="pickup-toolbar">
            <strong style="font-size:15px; margin-right:auto;">In-Store Holds &amp; AMS Orders</strong>
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

    @php
        // Orders still waiting 30+ days after they were placed get their own
        // tab so the main list stays current (Sarah, 2026-10-08).
        $olderCutoff = strtotime('-30 days');
        $isOlder = function ($w) use ($olderCutoff) {
            $ts = !empty($w['placed']) ? strtotime($w['placed']) : 0;
            return ($w['status'] ?? '') !== 'picked_up' && $ts && $ts < $olderCutoff;
        };
        $olderCount = count(array_filter($websitePickups, $isOlder));
        $sourceOpts = ['Website order', 'Instagram DM', 'Phone', 'Email', 'Walk-in'];
        $wpPickedCount = collect($websitePickups)->where('status', 'picked_up')->count();
        $wpWaitingCount = count($websitePickups) - $wpPickedCount - $olderCount;
    @endphp

    <div class="pickup-card" id="website-pickups">
        <div class="pickup-toolbar" style="justify-content:space-between;">
            <div>
                <strong style="font-size:15px;">Pickup Orders</strong>
                <p class="sub" style="margin:2px 0 0;">Website orders, listening-party preorders and in-store special orders waiting for pickup.</p>
            </div>
            @if(!empty($websitePickups))
            <div class="preorder-toggle" id="website_pickup_tabs" style="flex:0 1 auto;">
                <a href="#website-pickups" class="btn-accent js-wp-tab" data-tab="waiting" style="text-decoration:none;">Waiting ({{ $wpWaitingCount }})</a>
                <a href="#website-pickups" class="btn-ghost js-wp-tab" data-tab="older" style="text-decoration:none;">Older than 30 days ({{ $olderCount }})</a>
                <a href="#website-pickups" class="btn-ghost js-wp-tab" data-tab="picked" style="text-decoration:none;">Picked up ({{ $wpPickedCount }})</a>
            </div>
            <div style="flex:0 1 auto;">
                <input type="search" id="website_pickup_search" placeholder="Search customer name, email, phone or item" aria-label="Search pickup orders">
            </div>
            @endif
        </div>

        @if(!$preorderKeySet)
            <div class="alert-ok" style="border:1px solid var(--pos-accent,#FFE08A);background:transparent;padding:10px 14px;border-radius:10px;margin-bottom:10px;">
                Listening-party preorders live on nivessa.com. Set the <code>ERP_API_KEY</code> from any event's edit page to pull them in here.
            </div>
        @elseif(!$preorderReachable)
            <div class="alert-err" style="border:1px solid #f0c2c2;background:transparent;padding:10px 14px;border-radius:10px;margin-bottom:10px;">
                nivessa.com rejected the key or was unreachable, so listening-party preorders are missing. Re-check the key from an event's edit page.
            </div>
        @endif

        @if(empty($websitePickups))
            <div class="sub" style="padding:8px 2px;">No pickup orders waiting right now.</div>
        @else
            @include('customer_pickup.partials.website_pickup_table', ['rows' => $websitePickups, 'tableId' => 'website_pickup_table'])
        @endif
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
                { data: 'is_paid_label', name: 'customer_pickups.is_paid', orderable: true, searchable: false },
                { data: 'status', name: 'status' },
                { data: 'picked_up_info', name: 'picked_up_info', orderable: false, searchable: false },
                { data: 'created_info', name: 'created_info', orderable: false, searchable: false },
                { data: 'action', name: 'action', orderable: false, searchable: false },
            ],
            order: [[1, 'desc']],
            // No export/print/column buttons or page-size picker here.
            dom: 'rtip',
        });
        // Only show the holds card when it actually has something in it.
        pickup_table.on('xhr.dt', function(e, settings, json) {
            if (json && json.recordsTotal > 0) { $('#store_pickups_card').show(); }
        });

        // Preorders table is fully server-rendered (small, non-paginated
        // list) — just bolt on client-side sorting, no ajax/paging/search.
        // Sort values come from each <td>'s data-order (raw price/date/
        // paid-priority) so sorting is correct, not alphabetical-on-HTML.
        // Website pickup orders: same treatment — every column sortable
        // (data-order carries the raw value), plus a customer search box
        // that drives DataTables' filter so it matches name, email, phone
        // and item text.
        // Pickup lists are server-rendered (small, non-paginated): client-side
        // sorting off each <td>'s data-order, plus one search box that filters
        // both the main list and the Older section.
        var wpTables = [];
        var wpTab = 'waiting';
        $.fn.dataTable.ext.search.push(function(settings, data, idx) {
            if (settings.nTable.id !== 'website_pickup_table') { return true; }
            var tr = $(settings.aoData[idx].nTr);
            if (wpTab === 'picked') { return tr.hasClass('row-picked-up'); }
            if (wpTab === 'older') { return tr.hasClass('row-older'); }
            return !tr.hasClass('row-picked-up') && !tr.hasClass('row-older');
        });
        ['#website_pickup_table'].forEach(function(sel) {
            if (!$(sel + ' tbody tr').length) { return; }
            var t = $(sel).DataTable({
                paging: false,
                searching: true,
                // The site-wide sticky header (common.js) swaps in a header
                // clone with no click handlers here, so sorting never fired.
                fixedHeader: false,
                dom: 't',
                info: false,
                order: [],
                columnDefs: [{ targets: [0, -1], orderable: false }],
            });
            // Keep # as a plain 1..N row count in whatever order is shown.
            t.on('order.dt search.dt draw.dt', function() {
                t.column(0, { search: 'applied', order: 'applied' }).nodes().each(function(cell, i) {
                    cell.innerHTML = i + 1;
                });
            });
            t.draw();
            wpTables.push(t);
        });
        // Waiting / Picked up tabs on the main list (Sarah, 2026-10-07).
        $('#website_pickup_tabs').on('click', '.js-wp-tab', function(e) {
            e.preventDefault();
            wpTab = $(this).data('tab');
            $('#website_pickup_tabs .js-wp-tab').removeClass('btn-accent').addClass('btn-ghost');
            $(this).removeClass('btn-ghost').addClass('btn-accent');
            wpTables.forEach(function(t) { t.draw(); });
        });
        $('#website_pickup_search').on('input search', function() {
            var q = this.value;
            wpTables.forEach(function(t) { t.search(q).draw(); });
        });
        // In-store event holds use the regular pickup endpoint (JSON).
        $(document).on('click', '.js-hold-picked-up', function() {
            var btn = $(this).prop('disabled', true);
            $.post('/customer-pickups/' + btn.data('id') + '/mark-picked-up', {
                _token: $('meta[name="csrf-token"]').attr('content')
            }).done(function(res) {
                if (res && res.success) { location.reload(); }
                else { toastr.error((res && res.msg) || 'Could not mark picked up'); btn.prop('disabled', false); }
            }).fail(function() { toastr.error('Could not mark picked up'); btn.prop('disabled', false); });
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
