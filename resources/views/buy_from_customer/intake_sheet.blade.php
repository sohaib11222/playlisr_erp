@extends('layouts.app')
@section('title', 'Intake Sheet — ' . $offer->buy_record_number)

@section('content')
<section class="content-header no-print">
    <h1>Collection Intake Sheet</h1>
</section>

<section class="content">
    <div class="no-print" style="margin-bottom:15px;">
        @if(session('status'))
            <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">
                {{ session('status.msg') }}
            </div>
        @endif
        <button type="button" class="btn btn-primary" onclick="window.print()"><i class="fa fa-print"></i> Print</button>
        <button type="button" class="btn btn-default" id="bfc_copy_discogs_links"><i class="fa fa-copy"></i> Copy Discogs Links</button>
        <a href="{{ route('buy-from-customer.history') }}" class="btn btn-default"><i class="fa fa-arrow-left"></i> Back to History</a>
        <a href="{{ route('buy-from-customer.storage-locations') }}" class="btn btn-default"><i class="fa fa-boxes"></i> Storage Locations</a>
        <span id="bfc_copy_discogs_links_status" class="text-muted"></span>
    </div>

    <div class="box box-solid bfc-intake-sheet">
        <div class="box-body" style="max-width:820px; margin:0 auto; font-size:14px;">
            <div style="text-align:center; margin-bottom:20px;">
                <h2 style="margin-bottom:0;">Collection Intake Sheet</h2>
                <div style="font-size:20px; font-weight:700; letter-spacing:1px;">{{ $offer->buy_record_number }}</div>
                <div class="text-muted">{{ @format_datetime($offer->accepted_at ?? $offer->created_at) }} &middot; {{ optional($offer->location)->name ?? '—' }}</div>
            </div>

            @php $totalItems = $offer->total_item_quantity; @endphp
            @if($totalItems > 100)
                <div class="bfc-warehouse-label" style="border:4px solid #b0451a; background:#fff3e0; padding:20px; margin-bottom:20px; text-align:center; border-radius:6px;">
                    <div style="font-size:22px; font-weight:800; color:#b0451a; letter-spacing:1px;">&#9888; WAREHOUSE COLLECTION &mdash; DO NOT SHELVE IN STORE &#9888;</div>
                    <div style="font-size:16px; margin-top:8px;">{{ $offer->buy_record_number }} &middot; {{ $totalItems }} items</div>
                    <div style="font-size:15px; margin-top:10px; font-weight:600;">&rarr; Take this box to the Warehouse</div>
                    <div style="font-size:12px; color:#7a4a1a; margin-top:8px;">
                        Warehouse is not always staffed. If it's closed, hold this box at {{ optional($offer->location)->name ?: 'the store' }} until it can be dropped off.
                    </div>
                </div>
            @endif

            <table class="table table-bordered" style="margin-bottom:20px;">
                <tr>
                    <th style="width:25%; background:#f9f9f9;">Purchased from</th>
                    <td>
                        @if($offer->seller_first_name || $offer->seller_last_name)
                            {{ trim($offer->seller_first_name . ' ' . $offer->seller_last_name) }}
                        @else
                            {{ $offer->seller_name ?: optional($offer->contact)->name ?: '—' }}
                        @endif
                        @if(!empty($offer->seller_phone)) <br><small>{{ $offer->seller_phone }}</small> @endif
                        @if(!empty($offer->seller_email)) <br><small>{{ $offer->seller_email }}</small> @endif
                    </td>
                </tr>
                <tr>
                    <th style="background:#f9f9f9;">Purchased by</th>
                    <td>{{ optional($offer->createdBy)->user_full_name ?? optional($offer->createdBy)->username ?? '—' }}</td>
                </tr>
                <tr>
                    <th style="background:#f9f9f9;">ID number</th>
                    <td>
                        <strong>{{ $offer->buy_record_number }}</strong>
                        @if(!empty($offer->seller_id_type))
                            <br><small class="text-muted">Seller ID on file: {{ $offer->seller_id_type }} ending {{ $offer->seller_id_last_four ?: '—' }}</small>
                        @endif
                    </td>
                </tr>
                <tr>
                    <th style="background:#f9f9f9;">Value</th>
                    <td>
                        @php
                            $isCredit = $offer->payout_type === 'store_credit';
                            $finalAccepted = $isCredit ? $offer->final_offer_credit : $offer->final_offer_cash;
                            $pmLabel = [
                                'cash_in_store' => 'Cash (in store)',
                                'store_credit' => 'Store credit',
                                'zelle_venmo' => 'Zelle / Venmo',
                            ][$offer->payment_method] ?? ucfirst(str_replace('_', ' ', $offer->payout_type));
                        @endphp
                        @if($offer->is_donated)
                            <strong>Donated</strong>
                            <small class="text-muted">(no payout)</small>
                        @else
                            <strong>@format_currency($finalAccepted)</strong>
                            <small class="text-muted">({{ $pmLabel }})</small>
                        @endif
                    </td>
                </tr>
                <tr>
                    <th style="background:#f9f9f9;">Storage location</th>
                    <td>
                        <span id="bfc_storage_location_display">
                            {{ $offer->storage_location ?: 'Not yet assigned' }}
                        </span>
                        @if($offer->storage_location_updated_at)
                            <br><small class="text-muted" id="bfc_storage_location_meta">
                                Last set {{ $offer->storage_location_updated_at->format('M j, Y g:ia') }}
                            </small>
                        @else
                            <small class="text-muted" id="bfc_storage_location_meta"></small>
                        @endif
                        <div class="no-print" style="margin-top:8px;">
                            <div class="input-group" style="max-width:400px;">
                                <input type="text" id="bfc_storage_location_input" class="form-control" placeholder="e.g. Back room, shelf B3" value="{{ $offer->storage_location }}">
                                <span class="input-group-btn">
                                    <button type="button" class="btn btn-default" id="bfc_storage_location_save"><i class="fa fa-save"></i> Save</button>
                                </span>
                            </div>
                            <small class="text-muted" id="bfc_storage_location_status"></small>
                        </div>
                    </td>
                </tr>
            </table>

            <h4>Contents</h4>
            <table class="table table-condensed table-bordered">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Title</th>
                        <th>Discogs Link</th>
                        <th>Grade</th>
                        <th class="text-right">Qty</th>
                        <th>Destination</th>
                    </tr>
                </thead>
                <tbody>
                    @php
                        $dispositionLabels = [
                            'store' => 'Store',
                            'discogs' => 'Discogs',
                            'ebay' => 'eBay',
                            'hollywood' => 'Hollywood',
                            'trash' => 'Trash',
                            'clearance_bin' => 'Clearance Bin',
                        ];
                    @endphp
                    @forelse($offer->lines as $line)
                        <tr>
                            <td>{{ $itemTypes[$line->item_type] ?? $line->item_type }}</td>
                            <td>{{ $line->title ?: '—' }}</td>
                            <td>
                                @if($line->discogs_url)
                                    <a href="{{ $line->discogs_url }}" target="_blank" rel="noopener">{{ $line->discogs_link }}</a>
                                @else
                                    {{ $line->discogs_link ?: '—' }}
                                @endif
                            </td>
                            <td>{{ $line->condition_grade ?: '—' }}</td>
                            <td class="text-right">{{ number_format((float) $line->quantity, 2) }}</td>
                            <td>{{ $dispositionLabels[$line->disposition] ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted">No line items.</td></tr>
                    @endforelse
                </tbody>
            </table>

            <p style="margin-top:25px; border-top:1px solid #ddd; padding-top:15px;">
                <strong>Instructions:</strong> Print this sheet (or copy the ID number and storage location onto a label)
                and affix it to the box. Store the box at the location noted above.
            </p>
        </div>
    </div>
</section>

<style>
    @media print {
        .no-print, .main-header, .main-sidebar, .content-header, .content-wrapper > .content-header,
        .main-footer, .box-header { display: none !important; }
        .content-wrapper { margin-left: 0 !important; }
        .box { border: none !important; box-shadow: none !important; }
    }
</style>

<script>
(function () {
    function onReady(fn) {
        if (typeof jQuery === 'undefined') { setTimeout(function () { onReady(fn); }, 50); return; }
        jQuery(fn);
    }
    onReady(function ($) {
        $('#bfc_copy_discogs_links').on('click', function () {
            var links = @json($offer->lines->pluck('discogs_url')->filter()->values());
            var $status = $('#bfc_copy_discogs_links_status');
            if (!links.length) {
                $status.text('No Discogs links on this collection.');
                return;
            }
            var text = links.join('\n');
            var done = function () { $status.text(links.length + ' link' + (links.length === 1 ? '' : 's') + ' copied.'); };
            var fail = function () { $status.text('Copy failed — select and copy manually.'); };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, fail);
            } else {
                var $tmp = $('<textarea>').val(text).appendTo('body').select();
                try { document.execCommand('copy'); done(); } catch (e) { fail(); }
                $tmp.remove();
            }
        });

        $('#bfc_storage_location_save').on('click', function () {
            var $btn = $(this);
            var $status = $('#bfc_storage_location_status');
            var val = $('#bfc_storage_location_input').val();
            $btn.prop('disabled', true);
            $status.text('Saving...');
            $.post('{{ route('buy-from-customer.storage-location.update', $offer->id) }}', {
                _token: $('meta[name="csrf-token"]').attr('content'),
                storage_location: val,
            })
                .done(function (resp) {
                    $('#bfc_storage_location_display').text(resp.storage_location || 'Not yet assigned');
                    $('#bfc_storage_location_meta').text(resp.updated_at ? ('Last set ' + resp.updated_at + (resp.updated_by ? ' by ' + resp.updated_by : '')) : '');
                    $status.text('Saved.');
                })
                .fail(function () {
                    $status.text('Save failed — try again.');
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        });
    });
})();
</script>
@endsection
