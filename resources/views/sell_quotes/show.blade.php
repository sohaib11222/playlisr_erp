@extends('layouts.app')

@section('title', 'Sell Quote ' . $number)

@section('content')
@include('sell_quotes._style')
@php($expired = $quote->expires_at && now()->greaterThan($quote->expires_at))
<div class="sq-wrap">
    <p><a href="{{ url('/sell-quotes') }}">&larr; All website quotes</a></p>
    <h1>Quote {{ $number }}</h1>
    <p class="sub">
        {{ $quote->name }} · {{ $quote->phone }}{{ $quote->email ? ' · ' . $quote->email : '' }} · {{ $store }}
    </p>

    <div class="sq-card sq-grid">
        <div>
            <div class="sq-muted">{{ $quote->size_tier === 'large' ? 'Estimate (cash)' : 'Quoted cash' }}</div>
            <div class="sq-big">
                @if($quote->size_tier === 'large')
                    ${{ number_format($quote->estimate_low_cash, 0) }}&ndash;${{ number_format($quote->cash_total, 0) }}
                @else
                    ${{ number_format($quote->cash_total, 2) }}
                @endif
            </div>
        </div>
        <div>
            <div class="sq-muted">Quoted store credit</div>
            <div class="sq-big">${{ number_format($quote->credit_total, 2) }}</div>
        </div>
        <div>
            <div class="sq-muted">Items</div>
            <div class="sq-big">{{ $quote->item_count }}</div>
        </div>
        <div>
            <div class="sq-muted">Good through</div>
            <div class="sq-big" style="font-size:20px">
                @if($quote->size_tier === 'large')
                    Appraisal needed
                @elseif($quote->expires_at)
                    {{ \Carbon\Carbon::parse($quote->expires_at)->format('D n/j') }}{{ $expired ? ' (expired)' : '' }}
                @else
                    &mdash;
                @endif
            </div>
        </div>
    </div>

    <div class="sq-card">
        <p style="margin:0 0 10px">The seller saw only the one number above, firm if each item's condition matches what they picked. Check grades and counts; re-grade anything that doesn't match and the buy form re-prices just those lines.</p>
        @if($quote->status === 'in_buy' && $quote->buy_offer_id)
            <a class="sq-btn" href="{{ route('buy-from-customer.edit', [$quote->buy_offer_id, 'beta' => 1]) }}">Open the buy (BFC-{{ str_pad($quote->buy_offer_id, 6, '0', STR_PAD_LEFT) }})</a>
        @else
            <form method="POST" action="{{ url('/sell-quotes/' . $quote->id . '/start-buy') }}" style="display:inline">
                {{ csrf_field() }}
                <button class="sq-btn" type="submit">Start buy from this quote</button>
            </form>
        @endif
    </div>

    @if($quote->notes)
    <div class="sq-card"><strong>Seller notes:</strong><br>{!! nl2br(e($quote->notes)) !!}</div>
    @endif

    <div class="sq-card">
        <table class="sq-table">
            <thead><tr><th>Item</th><th>Type (buy form)</th><th>Qty</th><th>Condition</th><th>Discogs value</th><th>Quoted cash</th><th>Note</th></tr></thead>
            <tbody>
            @foreach($calcLines as $i => $l)
                @php($d = $display[$i] ?? [])
                <tr>
                    <td>{{ $d['label'] ?? ($l['title'] ?? '') }}
                        @if(!empty($l['discogs_link']))<br><a href="{{ $l['discogs_link'] }}" target="_blank" rel="noopener" class="sq-muted">Discogs</a>@endif
                    </td>
                    <td class="sq-muted">{{ $labels[$l['item_type']] ?? $l['item_type'] }}</td>
                    <td>{{ rtrim(rtrim(number_format($l['quantity'], 2), '0'), '.') }}</td>
                    <td>{{ $l['condition_grade'] ?? '—' }}</td>
                    <td>{{ !empty($l['discogs_median_price']) ? '$' . number_format($l['discogs_median_price'], 2) : '—' }}</td>
                    <td>${{ number_format($d['cash'] ?? 0, 2) }}</td>
                    <td class="sq-muted">
                        @if(($d['how'] ?? '') === 'bulk_rate') Under ${{ \App\Http\Controllers\SellQuoteController::CHERRY_MIN }}: bulk rate
                        @elseif(($d['how'] ?? '') === 'no_sales_data') No Discogs sales: bulk rate
                        @endif
                        {{ !empty($l['demand_reason']) ? $l['demand_reason'] : '' }}
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
