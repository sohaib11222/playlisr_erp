@extends('layouts.app')

@section('title', 'Website Sell Quotes')

@section('content')
@include('sell_quotes._style')
<div class="sq-wrap">
    <h1>Website Sell Quotes</h1>
    <p class="sub">Quotes sellers made on nivessa.com (Sell Your Stuff &rarr; Get a Quote). Each quote is good for 3 days if the condition matches. Open one and press <strong>Start buy</strong> to load it into the buy form.</p>

    <div class="sq-card">
        <form method="GET" action="{{ url('/sell-quotes') }}" class="sq-toolbar">
            <input type="text" name="q" value="{{ $q }}" placeholder="Quote # (Q1234), phone, name or email">
            <label style="font-weight:500;margin:0"><input type="checkbox" name="all" value="1" {{ request('all') ? 'checked' : '' }}> Include unfinished ({{ $startedCount }})</label>
            <button class="sq-btn" type="submit">Search</button>
        </form>
        <div class="table-responsive">
        <table class="sq-table">
            <thead><tr>
                <th>Quote</th><th>Seller</th><th>Store</th><th>Items</th><th>Quote (cash / credit)</th><th>Status</th><th>Made</th><th></th>
            </tr></thead>
            <tbody>
            @forelse($quotes as $sq)
                @php($expired = $sq->expires_at && now()->greaterThan($sq->expires_at))
                <tr>
                    <td><strong>Q{{ 1000 + $sq->id }}</strong></td>
                    <td>{{ $sq->name }}<br><span class="sq-muted">{{ $sq->phone }}{{ $sq->email ? ' · ' . $sq->email : '' }}</span></td>
                    <td>{{ $stores[$sq->location_id] ?? '—' }}</td>
                    <td>{{ $sq->item_count ?: '—' }}{{ $sq->size_tier === 'large' ? ' (large lot)' : '' }}</td>
                    <td>
                        @if($sq->cash_total !== null)
                            @if($sq->size_tier === 'large')
                                ~${{ number_format($sq->estimate_low_cash, 0) }}&ndash;${{ number_format($sq->cash_total, 0) }}
                            @else
                                ${{ number_format($sq->cash_total, 2) }} / ${{ number_format($sq->credit_total, 2) }}
                            @endif
                        @else
                            <span class="sq-muted">not finished</span>
                        @endif
                    </td>
                    <td>
                        @if($sq->status === 'in_buy')
                            <span class="sq-pill in_buy">In buy form</span>
                        @elseif($sq->status === 'started')
                            <span class="sq-pill">Contact only</span>
                        @elseif($expired)
                            <span class="sq-pill expired">Expired</span>
                        @else
                            <span class="sq-pill quoted">Quoted</span>
                        @endif
                    </td>
                    <td><span class="sq-muted">{{ \Carbon\Carbon::parse($sq->created_at)->format('n/j g:ia') }}</span></td>
                    <td>@if($sq->status !== 'started')<a class="sq-btn ghost" href="{{ url('/sell-quotes/' . $sq->id) }}">Open</a>@endif</td>
                </tr>
            @empty
                <tr><td colspan="8" class="sq-muted">No quotes yet.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
        {{ $quotes->links() }}
    </div>
</div>
@endsection
