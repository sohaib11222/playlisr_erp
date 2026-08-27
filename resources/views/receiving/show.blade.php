@extends('layouts.app')

@section('title', 'Package #' . $package->id)

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

<style>
body.pos-v2 .rcv-wrap { max-width: 1280px; margin: 0 auto; padding: 18px 16px 60px; font-family: "Inter Tight", system-ui, sans-serif; color: var(--pos-ink); }
body.pos-v2 .rcv-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; flex-wrap: wrap; }
body.pos-v2 .rcv-head h1 { font-size: 22px; font-weight: 700; margin: 0 0 4px; }
body.pos-v2 .rcv-head .sub { color: #6b6253; margin: 0; font-size: 13.5px; }
body.pos-v2 .rcv-card { background: var(--pos-surface); border: 1px solid var(--pos-line); border-radius: 14px; padding: 18px 20px; margin-bottom: 20px; }
body.pos-v2 .rcv-card h2 { font-size: 15px; font-weight: 700; margin: 0 0 14px; display: flex; align-items: center; gap: 8px; }
body.pos-v2 .rcv-meta { display: flex; flex-wrap: wrap; gap: 22px; }
body.pos-v2 .rcv-meta .item { min-width: 140px; }
body.pos-v2 .rcv-meta .item .k { font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #8a8070; font-weight: 700; }
body.pos-v2 .rcv-meta .item .v { font-size: 14px; margin-top: 2px; }
body.pos-v2 .rcv-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
body.pos-v2 .rcv-field { display: flex; flex-direction: column; gap: 4px; }
body.pos-v2 .rcv-field label { font-size: 11px; font-weight: 600; color: #5a5145; }
body.pos-v2 .rcv-field input, body.pos-v2 .rcv-field select { border: 1px solid var(--pos-line-2); border-radius: 8px; padding: 7px 9px; font-size: 13.5px; font-family: inherit; background: #fff; }
body.pos-v2 .btn-accent { background: var(--pos-accent); color: var(--pos-accent-text); border: 1px solid var(--pos-accent-deep);
  border-radius: 9px; padding: 8px 16px; font-weight: 700; font-size: 13.5px; cursor: pointer; font-family: inherit; text-decoration: none; }
body.pos-v2 .btn-accent:hover { background: var(--pos-accent-deep); color: var(--pos-accent-text); }
body.pos-v2 .btn-ghost { background: transparent; border: 1px solid var(--pos-line-2); border-radius: 9px;
  padding: 8px 14px; font-weight: 600; font-size: 13.5px; cursor: pointer; color: #5a5145; font-family: inherit; text-decoration: none; }
body.pos-v2 #items_table { width: 100%; border-collapse: collapse; }
body.pos-v2 #items_table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: #8a8070; font-weight: 700; padding: 8px; border-bottom: 1px solid var(--pos-line); }
body.pos-v2 #items_table td { padding: 8px; border-bottom: 1px solid var(--pos-line); font-size: 13.5px; vertical-align: middle; }
body.pos-v2 #items_table input { width: 90px; }
body.pos-v2 .label { font-size: 11px; font-weight: 600; padding: 3px 9px; border-radius: 999px; }
body.pos-v2 .timeline-row { padding: 8px 0; border-bottom: 1px solid var(--pos-line); font-size: 13px; }
body.pos-v2 .timeline-row:last-child { border-bottom: none; }
body.pos-v2 .timeline-row .who { font-weight: 700; }
body.pos-v2 .timeline-row .when { color: #8a8070; font-size: 11.5px; }
</style>

<div class="rcv-wrap">
    <div class="rcv-head">
        <div>
            <h1>Package #{{ $package->id }} — {{ \App\ReceivingPackage::$packageTypes[$package->package_type] ?? $package->package_type }}{{ $package->package_type_detail ? ' ('.$package->package_type_detail.')' : '' }}</h1>
            <p class="sub">
                @if($package->status == 'open')
                    <span class="label label-warning">Receiving window open</span>
                @else
                    <span class="label label-success">Closed</span>
                @endif
                — logged by {{ $package->receiver->user_full_name ?? 'Unknown' }} at {{ $package->location->name ?? '-' }}
            </p>
        </div>
        <div>
            <a href="{{ action('ReceivingPackageController@index') }}" class="btn-ghost">&larr; All Packages</a>
            @if($package->status == 'open')
                <button type="button" id="close_window_btn" class="btn-ghost">Close Receiving Window</button>
            @endif
        </div>
    </div>

    <div class="rcv-card">
        <div class="rcv-meta">
            <div class="item"><div class="k">Order #</div><div class="v">{{ $package->order_number ?: '-' }}</div></div>
            <div class="item"><div class="k">Invoice #</div><div class="v">{{ $package->invoice_number ?: '-' }}</div></div>
            <div class="item"><div class="k">Linked POs</div><div class="v">
                @if($package->purchaseOrders->count())
                    @foreach($package->purchaseOrders as $po)
                        <a href="{{ action('PurchaseOrderController@show', [$po->id]) }}">{{ $po->ref_no ?: ('#'.$po->id) }}</a>@if(!$loop->last), @endif
                    @endforeach
                @else
                    -
                @endif
            </div></div>
            <div class="item"><div class="k">Notes</div><div class="v">{{ $package->notes ?: '-' }}</div></div>
        </div>
    </div>

    <div class="rcv-card">
        <h2><i class="fa fa-barcode"></i> Scan / Add an Item</h2>
        <div class="rcv-row" id="add_item_row">
            <div class="rcv-field" style="flex: 2 1 280px;">
                <label>Product</label>
                <select id="add_item_product" style="width:100%"></select>
            </div>
            <div class="rcv-field"><label>SKU</label><input type="text" id="add_item_sku" placeholder="SKU"></div>
            <div class="rcv-field"><label>Qty</label><input type="number" id="add_item_qty" value="1" min="1" step="1"></div>
            <div class="rcv-field"><label>Cost Paid</label><input type="number" id="add_item_cost" step="0.01" min="0" placeholder="0.00"></div>
            <div class="rcv-field"><label>MSRP</label><input type="number" id="add_item_msrp" step="0.01" min="0" placeholder="0.00"></div>
            <div class="rcv-field"><label>Sell For</label><input type="number" id="add_item_sell" step="0.01" min="0" placeholder="0.00"></div>
            <div class="rcv-field"><button type="button" id="add_item_btn" class="btn-accent">Add to Box</button></div>
        </div>
        <input type="hidden" id="add_item_product_id">
        <input type="hidden" id="add_item_variation_id">
        <small class="help-block" style="color:#8a8070;">Not in the catalog yet? Skip the product search and just type the SKU/name — you can match it up later.</small>
        <div class="rcv-field" style="margin-top:8px; max-width:320px;">
            <label>Product name (if not matched above)</label>
            <input type="text" id="add_item_name" placeholder="Product name">
        </div>
    </div>

    <div class="rcv-card">
        <h2><i class="fa fa-list"></i> What's Inside ({{ $package->items->count() }})</h2>
        <div class="table-responsive">
            <table id="items_table">
                <thead>
                    <tr>
                        <th>SKU</th>
                        <th>Product</th>
                        <th>Qty</th>
                        <th>Cost Paid</th>
                        <th>MSRP</th>
                        <th>Sell For</th>
                        <th>Rack</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="items_tbody">
                    @foreach($package->items as $item)
                    <tr data-item-id="{{ $item->id }}">
                        <td>{{ $item->sku ?: '-' }}</td>
                        <td>{{ $item->product_name ?: '-' }}</td>
                        <td><input type="number" class="item-qty" value="{{ $item->quantity }}" min="0.01" step="0.01" {{ $item->status == 'priced' ? 'disabled' : '' }}></td>
                        <td><input type="number" class="item-cost" value="{{ $item->cost_price }}" step="0.01" min="0" {{ $item->status == 'priced' ? 'disabled' : '' }}></td>
                        <td><input type="number" class="item-msrp" value="{{ $item->msrp }}" step="0.01" min="0" {{ $item->status == 'priced' ? 'disabled' : '' }}></td>
                        <td><input type="number" class="item-sell" value="{{ $item->pending_sell_price }}" step="0.01" min="0" {{ $item->status == 'priced' ? 'disabled' : '' }}></td>
                        <td><input type="text" class="item-rack" value="{{ $item->rack }}" placeholder="Shelf/bin" {{ $item->status == 'priced' ? 'disabled' : '' }}></td>
                        <td class="item-status-cell">
                            @if($item->status == 'priced')
                                <span class="label label-success">Priced</span><br>
                                <small>{{ $item->pricedByUser->user_full_name ?? '' }} — {{ optional($item->priced_at)->format('n/j/y g:i A') }}</small>
                            @else
                                <span class="label label-warning">In Progress</span>
                            @endif
                        </td>
                        <td>
                            @if($item->status != 'priced')
                                <button type="button" class="btn-ghost btn-save-item">Save</button>
                                <button type="button" class="btn-accent btn-mark-priced">Mark Priced</button>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="rcv-card">
        <h2><i class="fa fa-history"></i> History</h2>
        @forelse($activities as $activity)
            <div class="timeline-row">
                <span class="who">{{ $activity->causer->user_full_name ?? 'System' }}</span>
                — {{ str_replace('_', ' ', $activity->description) }}
                <div class="when">{{ $activity->created_at->format('n/j/y g:i A') }}</div>
            </div>
        @empty
            <p style="color:#8a8070; font-size:13px;">No activity yet.</p>
        @endforelse
    </div>
</div>

@stop
@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        var packageId = {{ $package->id }};
        var addItemUrl = '{{ action("ReceivingPackageController@addItem", [$package->id]) }}';
        var csrfToken = $('meta[name="csrf-token"]').attr('content') || '';

        $('#add_item_product').select2({
            placeholder: 'Type to search the catalog...',
            allowClear: true,
            minimumInputLength: 2,
            ajax: {
                url: '/products/list',
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return {
                        term: params.term || '',
                        not_for_selling: 0,
                        search_fields: ['name', 'sku'],
                    };
                },
                processResults: function(data) {
                    var parsed = typeof data === 'string' ? JSON.parse(data) : data;
                    var results = [];
                    $.each(parsed || [], function(i, item) {
                        var text = (item.name || '') + (item.artist ? ' - ' + item.artist : '') + (item.sub_sku ? ' (' + item.sub_sku + ')' : '');
                        results.push({
                            id: item.variation_id,
                            text: text,
                            product_id: item.product_id,
                            variation_id: item.variation_id,
                            sub_sku: item.sub_sku,
                            name: item.name,
                            selling_price: item.selling_price,
                        });
                    });
                    return { results: results };
                },
            },
        });

        $('#add_item_product').on('select2:select', function(e) {
            var d = e.params.data;
            $('#add_item_product_id').val(d.product_id);
            $('#add_item_variation_id').val(d.variation_id);
            $('#add_item_sku').val(d.sub_sku || '');
            $('#add_item_name').val(d.name || '');
            if (d.selling_price) { $('#add_item_sell').val(parseFloat(d.selling_price).toFixed(2)); }
        });

        $('#add_item_product').on('select2:clear', function() {
            $('#add_item_product_id, #add_item_variation_id').val('');
        });

        function resetAddItemForm() {
            $('#add_item_product').val(null).trigger('change');
            $('#add_item_product_id, #add_item_variation_id, #add_item_sku, #add_item_name, #add_item_cost, #add_item_msrp, #add_item_sell').val('');
            $('#add_item_qty').val(1);
        }

        $('#add_item_btn').on('click', function() {
            var sku = $('#add_item_sku').val();
            var name = $('#add_item_name').val();
            if (!sku && !name && !$('#add_item_product_id').val()) {
                toastr.error('Enter a SKU or product name, or pick a product from the catalog.');
                return;
            }
            $.ajax({
                method: 'POST',
                url: addItemUrl,
                data: {
                    _token: csrfToken,
                    product_id: $('#add_item_product_id').val() || null,
                    variation_id: $('#add_item_variation_id').val() || null,
                    sku: sku,
                    product_name: name,
                    quantity: $('#add_item_qty').val() || 1,
                    cost_price: $('#add_item_cost').val() || null,
                    msrp: $('#add_item_msrp').val() || null,
                    pending_sell_price: $('#add_item_sell').val() || null,
                },
                dataType: 'json',
                success: function(result) {
                    if (result.success) {
                        toastr.success(result.msg);
                        resetAddItemForm();
                        location.reload();
                    } else {
                        toastr.error(result.msg);
                    }
                }
            });
        });

        $(document).on('click', '.btn-save-item', function() {
            var row = $(this).closest('tr');
            var itemId = row.data('item-id');
            $.ajax({
                method: 'PUT',
                url: '/receiving/' + packageId + '/items/' + itemId,
                data: {
                    _token: csrfToken,
                    quantity: row.find('.item-qty').val(),
                    cost_price: row.find('.item-cost').val() || null,
                    msrp: row.find('.item-msrp').val() || null,
                    pending_sell_price: row.find('.item-sell').val() || null,
                    rack: row.find('.item-rack').val() || null,
                },
                dataType: 'json',
                success: function(result) {
                    if (result.success) { toastr.success(result.msg); } else { toastr.error(result.msg); }
                }
            });
        });

        $(document).on('click', '.btn-mark-priced', function() {
            var row = $(this).closest('tr');
            var itemId = row.data('item-id');
            if (!row.find('.item-sell').val()) {
                toastr.error('Set a sell price first.');
                return;
            }
            // Save any pending edits before finalizing.
            $.ajax({
                method: 'PUT',
                url: '/receiving/' + packageId + '/items/' + itemId,
                data: {
                    _token: csrfToken,
                    quantity: row.find('.item-qty').val(),
                    cost_price: row.find('.item-cost').val() || null,
                    msrp: row.find('.item-msrp').val() || null,
                    pending_sell_price: row.find('.item-sell').val() || null,
                    rack: row.find('.item-rack').val() || null,
                },
                dataType: 'json',
                success: function() {
                    $.ajax({
                        method: 'POST',
                        url: '/receiving/' + packageId + '/items/' + itemId + '/mark-priced',
                        data: { _token: csrfToken },
                        dataType: 'json',
                        success: function(result) {
                            if (result.success) {
                                toastr.success(result.msg);
                                location.reload();
                            } else {
                                toastr.error(result.msg);
                            }
                        }
                    });
                }
            });
        });

        $('#close_window_btn').on('click', function() {
            swal({
                title: 'Close this receiving window?',
                text: 'You can still price items after closing — this just marks intake as done.',
                icon: 'warning',
                buttons: true,
            }).then((confirmed) => {
                if (confirmed) {
                    $.ajax({
                        method: 'POST',
                        url: '/receiving/' + packageId + '/close',
                        data: { _token: csrfToken },
                        dataType: 'json',
                        success: function(result) {
                            if (result.success) { location.reload(); } else { toastr.error(result.msg); }
                        }
                    });
                }
            });
        });
    });
</script>
@stop
