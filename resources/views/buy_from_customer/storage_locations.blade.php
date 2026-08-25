@extends('layouts.app')
@section('title', 'Storage Locations')

@section('content')
<section class="content-header">
    <h1>Storage Locations <small>Where purchased collections are kept</small></h1>
</section>

<section class="content">
    @if(session('status'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">
            {{ session('status.msg') }}
        </div>
    @endif

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">Purchased Collections</h3>
            <div class="box-tools">
                {!! Form::open(['url' => route('buy-from-customer.storage-locations'), 'method' => 'get', 'style' => 'display:inline-block;']) !!}
                {!! Form::text('q', $search, ['class' => 'form-control input-sm', 'style' => 'width:220px; display:inline-block;', 'placeholder' => 'Search location or seller']) !!}
                <button type="submit" class="btn btn-default btn-sm"><i class="fa fa-search"></i></button>
                {!! Form::close() !!}
            </div>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Buy record</th>
                        <th>Date</th>
                        <th>Seller</th>
                        <th>Items</th>
                        <th>Value</th>
                        <th>Storage location</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($offers as $offer)
                        @php
                            $isCredit = $offer->payout_type === 'store_credit';
                            $finalAccepted = $isCredit ? $offer->final_offer_credit : $offer->final_offer_cash;
                        @endphp
                        <tr>
                            <td>
                                <a href="{{ route('buy-from-customer.intake-sheet', $offer->id) }}">{{ $offer->buy_record_number }}</a>
                            </td>
                            <td>{{ @format_datetime($offer->accepted_at ?? $offer->created_at) }}</td>
                            <td>
                                @if($offer->seller_first_name || $offer->seller_last_name)
                                    {{ trim($offer->seller_first_name . ' ' . $offer->seller_last_name) }}
                                @else
                                    {{ $offer->seller_name ?: optional($offer->contact)->name ?: '—' }}
                                @endif
                            </td>
                            <td>{{ $offer->lines->count() }}</td>
                            <td>@format_currency($finalAccepted)</td>
                            <td>
                                <span class="bfc-loc-display" data-offer-id="{{ $offer->id }}">{{ $offer->storage_location ?: '—' }}</span>
                                <div class="bfc-loc-edit" data-offer-id="{{ $offer->id }}" style="display:none; margin-top:4px;">
                                    <div class="input-group input-group-sm">
                                        <input type="text" class="form-control bfc-loc-input" value="{{ $offer->storage_location }}" placeholder="e.g. Back room, shelf B3">
                                        <span class="input-group-btn">
                                            <button type="button" class="btn btn-primary bfc-loc-save"><i class="fa fa-save"></i></button>
                                        </span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <button type="button" class="btn btn-default btn-xs bfc-loc-edit-btn" data-offer-id="{{ $offer->id }}"><i class="fa fa-pencil"></i> Edit</button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted">No accepted collections yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            {{ $offers->links() }}
        </div>
    </div>
</section>

<script>
(function () {
    function onReady(fn) {
        if (typeof jQuery === 'undefined') { setTimeout(function () { onReady(fn); }, 50); return; }
        jQuery(fn);
    }
    onReady(function ($) {
        function updateBaseUrl(id) {
            return '{{ url('/buy-from-customer') }}/' + id + '/storage-location';
        }

        $(document).on('click', '.bfc-loc-edit-btn', function () {
            var id = $(this).data('offer-id');
            $('.bfc-loc-display[data-offer-id="' + id + '"]').hide();
            $('.bfc-loc-edit[data-offer-id="' + id + '"]').show().find('.bfc-loc-input').focus();
        });

        $(document).on('click', '.bfc-loc-save', function () {
            var $wrap = $(this).closest('.bfc-loc-edit');
            var id = $wrap.data('offer-id');
            var val = $wrap.find('.bfc-loc-input').val();
            var $btn = $(this);
            $btn.prop('disabled', true);
            $.post(updateBaseUrl(id), {
                _token: $('meta[name="csrf-token"]').attr('content'),
                storage_location: val,
            })
                .done(function (resp) {
                    var $display = $('.bfc-loc-display[data-offer-id="' + id + '"]');
                    $display.text(resp.storage_location || '—');
                    $wrap.hide();
                    $display.show();
                })
                .fail(function () {
                    alert('Save failed — try again.');
                })
                .always(function () {
                    $btn.prop('disabled', false);
                });
        });
    });
})();
</script>
@endsection
