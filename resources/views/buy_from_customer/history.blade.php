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

    @if(!empty($diagnostics))
        @php
            $diagOk = $diagnostics['total_in_db'] === $diagnostics['total_for_business'];
        @endphp
        <div class="alert alert-{{ $diagOk ? 'info' : 'warning' }}" style="margin-bottom:10px;">
            <strong>Records:</strong>
            {{ $diagnostics['total_for_business'] }} for your business (id {{ $diagnostics['business_id'] }})
            / {{ $diagnostics['total_in_db'] }} total in DB.
            @if(!empty($diagnostics['distinct_business_ids']))
                Business IDs in table: {{ implode(', ', $diagnostics['distinct_business_ids']) }}.
            @endif
            @if(!$diagnostics['show_all'])
                <a href="{{ route('buy-from-customer.history', ['show_all' => 1]) }}" class="btn btn-default btn-xs" style="margin-left:8px;">Show all (no business filter)</a>
            @else
                <a href="{{ route('buy-from-customer.history') }}" class="btn btn-default btn-xs" style="margin-left:8px;">Hide other businesses</a>
            @endif
        </div>
    @endif

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">Offer Records @if(!empty($diagnostics['show_all'])) <small>(showing ALL business IDs)</small> @endif</h3>
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
                        @if(!empty($diagnostics['show_all'])) <th>Biz</th> @endif
                        <th>Date</th>
                        <th>Store</th>
                        <th>Seller</th>
                        <th>Status</th>
                        <th>Final Cash</th>
                        <th>Final Credit</th>
                        <th>Payment</th>
                        <th>Purchase Ref</th>
                        <th>Rejected Reason</th>
                        <th></th>
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
                            @if(!empty($diagnostics['show_all'])) <td>{{ $offer->business_id }}</td> @endif
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
                            <td>
                                @if($offer->status !== 'accepted')
                                    <form method="POST" action="{{ route('buy-from-customer.destroy', $offer->id) }}" style="display:inline;" onsubmit="return confirm('Delete {{ $offer->buy_record_number }}? This cannot be undone.');">
                                        @csrf
                                        <button type="submit" class="btn btn-danger btn-xs"><i class="fa fa-trash"></i></button>
                                    </form>
                                @else
                                    <span class="text-muted small" title="Accepted offers can't be deleted — void the linked purchase first">locked</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ !empty($diagnostics['show_all']) ? 12 : 11 }}" class="text-center">No records yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            {{ $offers->links() }}
        </div>
    </div>
</section>
@endsection

