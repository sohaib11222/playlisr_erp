@extends('layouts.app')
@section('title', 'Buy from Customer History')

@section('content')
<section class="content-header">
    <h1>Buy from Customer <small>History</small></h1>
</section>

<section class="content">
    @if(session('status'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">
            {{ session('status.msg') }}
        </div>
    @endif

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">Offer Records</h3>
            <div class="box-tools">
                <a href="{{ route('buy-from-customer.create') }}" class="btn btn-primary btn-sm">
                    <i class="fa fa-plus"></i> New Offer
                </a>
            </div>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Buy record</th>
                        <th>Date</th>
                        <th>Store</th>
                        <th>Seller</th>
                        <th>Status</th>
                        <th>Final Cash</th>
                        <th>Final Credit</th>
                        <th>Payment</th>
                        <th>Purchase Ref</th>
                        <th>Rejected Reason</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($offers as $offer)
                        @php
                            $pmKey = $offer->payment_method;
                            if (empty($pmKey)) {
                                $pmKey = $offer->payout_type === 'store_credit' ? 'store_credit' : 'cash_in_store';
                            }
                            $pmLabel = [
                                'cash_in_store' => 'Cash (in store)',
                                'store_credit' => 'Store credit',
                                'zelle_venmo' => 'Zelle / Venmo',
                            ][$pmKey] ?? ucfirst(str_replace('_', ' ', $offer->payout_type));
                        @endphp
                        <tr>
                            <td>{{ $offer->buy_record_number }}</td>
                            <td>{{ @format_datetime($offer->accepted_at ?? $offer->created_at) }}</td>
                            <td>{{ optional($offer->location)->name ?? '—' }}</td>
                            <td>
                                @if($offer->seller_first_name || $offer->seller_last_name)
                                    {{ trim($offer->seller_first_name . ' ' . $offer->seller_last_name) }}
                                @else
                                    {{ $offer->seller_name ?: optional($offer->contact)->name ?: '-' }}
                                @endif
                                @if(!empty($offer->seller_phone))
                                    <br><small>{{ $offer->seller_phone }}</small>
                                @endif
                            </td>
                            <td><span class="label bg-{{ $offer->status === 'accepted' ? 'green' : ($offer->status === 'rejected' ? 'red' : 'yellow') }}">{{ ucfirst($offer->status) }}</span></td>
                            <td>@format_currency($offer->final_offer_cash)</td>
                            <td>@format_currency($offer->final_offer_credit)</td>
                            <td>{{ $pmLabel }}</td>
                            <td>
                                @if($offer->acceptedPurchase)
                                    <a href="{{ action('PurchaseController@show', [$offer->acceptedPurchase->id]) }}" target="_blank">
                                        #{{ $offer->acceptedPurchase->id }}
                                    </a>
                                @else
                                    -
                                @endif
                            </td>
                            <td>{{ $offer->rejection_reason ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center">No records yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            {{ $offers->links() }}
        </div>
    </div>
</section>
@endsection

