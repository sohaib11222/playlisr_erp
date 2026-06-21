@extends('layouts.app')
@section('title', 'Listing Commissions')

@section('content')
<section class="content-header">
    <h1>Listing Commissions Owed</h1>
    <p class="text-muted">
        What we owe each person for items they listed that have <strong>sold</strong>.
        Commission = <strong>{{ rtrim(rtrim(number_format($rate_pct, 2), '0'), '.') }}%</strong>
        of the actual sale price of each item they listed/barcoded on/after the start
        date that has since sold and hasn't been paid for yet. These are the same
        numbers each person sees as earned commission on the Employee Leaderboard.
        Click <strong>Mark paid</strong> once you've paid someone — those sales drop
        off the owed list.
    </p>
</section>

<section class="content">

@if (session('status'))
    <div class="alert {{ session('status')['success'] ? 'alert-success' : 'alert-danger' }}">
        {{ session('status')['msg'] }}
    </div>
@endif

<div class="row">
    <div class="col-md-12">
        <div class="box box-solid">
            <div class="box-body">
                <form method="GET" action="{{ url('/admin/listing-commissions') }}" class="form-inline">
                    <label for="from">Listed since</label>
                    <input type="date" id="from" name="from" value="{{ $from }}" min="2026-05-15" class="form-control" style="margin:0 8px;">
                    <button type="submit" class="btn btn-primary">Apply</button>
                    <span class="text-muted" style="margin-left:8px;font-size:12px;">Earned &amp; Owed are for items listed since this date; Paid is the total you've actually paid them. At May 15 (program start), Earned = Paid + Owed and matches the Leaderboard &amp; My Earnings.</span>
                    <span class="pull-right" style="font-size:16px;">
                        Earned <strong>${{ number_format($total_earned, 2) }}</strong> &nbsp;·&nbsp; Paid <strong>${{ number_format($total_paid_window, 2) }}</strong> &nbsp;·&nbsp; Owed <strong>${{ number_format($total_owed, 2) }}</strong>
                    </span>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        @component('components.widget', ['title' => 'By person (listed since ' . $from . ')'])
            @if ($people->isEmpty())
                <p class="text-muted">No listing commission for items listed since {{ $from }} that have sold.</p>
            @else
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Person</th>
                            <th style="text-align:right;">Items listed</th>
                            <th style="text-align:right;">Listed value</th>
                            <th style="text-align:right;">Items sold</th>
                            <th style="text-align:right;">Sale total</th>
                            <th style="text-align:right;">Earned</th>
                            <th style="text-align:right;">Paid</th>
                            <th style="text-align:right;">Owed</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($people as $p)
                            <tr>
                                <td><a href="{{ url('/my-earnings') }}?user_id={{ $p->user_id }}" title="See {{ $p->name }}'s full earnings page (what they see)">{{ $p->name }}</a></td>
                                <td style="text-align:right;"><a href="{{ url('/my-earnings/items') }}?user_id={{ $p->user_id }}">{{ number_format($p->listed_count) }}</a></td>
                                <td style="text-align:right;">${{ number_format($p->listed_value, 2) }}</td>
                                <td style="text-align:right;">{{ number_format($p->sold_count) }}</td>
                                <td style="text-align:right;">${{ number_format($p->sale_total, 2) }}</td>
                                <td style="text-align:right;">${{ number_format($p->earned, 2) }}</td>
                                <td style="text-align:right;">${{ number_format($p->paid, 2) }}</td>
                                <td style="text-align:right;"><strong>${{ number_format($p->owed, 2) }}</strong></td>
                                <td style="text-align:right;">
                                    @if($p->owed > 0)
                                    <form method="POST" action="{{ url('/admin/listing-commissions/mark-paid') }}"
                                          onsubmit="return confirm('Mark {{ $p->count }} sold item(s) for {{ $p->name }} as paid (${{ number_format($p->owed, 2) }})?');"
                                          style="margin:0;">
                                        @csrf
                                        <input type="hidden" name="user_id" value="{{ $p->user_id }}">
                                        <input type="hidden" name="from" value="{{ $from }}">
                                        <button type="submit" class="btn btn-success btn-xs">Mark paid</button>
                                    </form>
                                    @else
                                        <span class="text-muted">paid up</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endcomponent
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        @component('components.widget', ['title' => 'Paid history'])
            @if ($history->isEmpty())
                <p class="text-muted">No payouts recorded yet. Total paid: $0.00</p>
            @else
                <p class="text-muted">Total paid to date: <strong>${{ number_format($total_paid, 2) }}</strong></p>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Paid on</th>
                            <th>Person</th>
                            <th style="text-align:right;">Items</th>
                            <th style="text-align:right;">Amount</th>
                            <th>Covered</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $h)
                            <tr>
                                <td>{{ $h['marked_at'] ?? '—' }}</td>
                                <td>{{ $h['name'] ?? ('User #' . ($h['user_id'] ?? '?')) }}</td>
                                <td style="text-align:right;">{{ number_format($h['count'] ?? 0) }}</td>
                                <td style="text-align:right;">${{ number_format($h['amount'] ?? 0, 2) }}</td>
                                <td>{{ $h['from_date'] ?? '?' }} → {{ $h['to_date'] ?? '?' }}</td>
                                <td style="text-align:right;">
                                    <form method="POST" action="{{ url('/admin/listing-commissions/undo-payout') }}"
                                          onsubmit="return confirm('Undo this payout? Those listings will be owed again.');"
                                          style="margin:0;">
                                        @csrf
                                        <input type="hidden" name="id" value="{{ $h['id'] ?? '' }}">
                                        <input type="hidden" name="from" value="{{ $from }}">
                                        <button type="submit" class="btn btn-warning btn-xs">Undo</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endcomponent
    </div>
</div>

</section>
@endsection
