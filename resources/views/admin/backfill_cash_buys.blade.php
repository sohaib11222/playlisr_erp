@extends('layouts.app')

@section('title', 'Backfill cash buys')

@section('content')
<section class="content-header">
    <h1>Backfill cash buys — 2026-05-13 Slack #collections-hollywood</h1>
</section>
<section class="content">
    @if(session('status'))
        @php $st = session('status'); @endphp
        <div class="alert {{ ($st['success'] ?? 0) ? 'alert-success' : 'alert-danger' }}" style="white-space:pre-wrap;">{{ $st['msg'] ?? '' }}</div>
    @endif
    <div class="box box-warning">
        <div class="box-body">
            <p>
                These 6 buys were reported in Slack but never filed through /buy-from-customer.
                Clicking <strong>Backfill all</strong> inserts a minimal accepted offer + draft purchase
                for each one so they show up under "Collection buys (cash)" on the per-cashier card.
                No inventory lines are created — these are cash-drawer entries only.
                Undo is available at <a href="/admin/admin-action-history">/admin/admin-action-history</a>.
            </p>
            <table class="table table-condensed" style="margin-top:10px;">
                <thead><tr>
                    <th>Time (LA)</th>
                    <th>Cashier (Slack)</th>
                    <th>Resolves to user</th>
                    <th>Location</th>
                    <th style="text-align:right;">Amount</th>
                    <th>Note</th>
                </tr></thead>
                <tbody>
                @foreach($entries as $e)
                    <tr style="{{ $e['user_id'] ? '' : 'color:#b91c1c;' }}">
                        <td>{{ $e['time'] }}</td>
                        <td>{{ $e['cashier'] }}</td>
                        <td>{{ $e['user_label'] }}</td>
                        <td>{{ $e['location_name'] }}</td>
                        <td style="text-align:right; font-variant-numeric:tabular-nums;">${{ number_format($e['amount'], 2) }}</td>
                        <td style="font-size:12px; color:#374151;">{{ $e['note'] }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            <form method="POST" action="/admin/backfill-cash-buys/run" onsubmit="return confirm('Insert {{ count($entries) }} buy_customer_offers + matching purchase transactions for {{ $today }}?');">
                @csrf
                <button type="submit" class="btn btn-warning" style="margin-top:10px;">Backfill all</button>
                <span style="margin-left:10px; color:#6b7280; font-size:12px;">Re-running is safe — already-filed entries are skipped.</span>
            </form>
        </div>
    </div>
</section>
@endsection
