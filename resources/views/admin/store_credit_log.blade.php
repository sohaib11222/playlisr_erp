@extends('layouts.app')
@section('title', 'Store Credit Log')

@section('content')
<section class="content-header">
    <h1>Store Credit Log</h1>
    <p class="text-muted">
        Every store-credit event &mdash; credit <strong>issued</strong> to customers and credit
        <strong>used</strong> (spent) on sales. Rows flagged
        <span class="label label-warning">No purchase form</span> were added with the
        &ldquo;Add Store Credit&rdquo; button or a manual adjustment &mdash; <strong>not</strong> an accepted
        buy-from-customer purchase form. Rows flagged
        <span class="label label-info">Used on sale</span> are store credit spent at checkout
        instead of cash or card.
    </p>
    @unless($has_structured)
        <div class="alert alert-info" style="margin-top:8px;">
            The structured <code>store_credit_logs</code> table isn&rsquo;t migrated yet, so employee names
            below come from the free-text audit line (first name only). Run <code>php artisan migrate</code>
            on prod to capture reliable, full-name attribution on new credits.
        </div>
    @endunless

    <form method="GET" action="{{ url('/admin/store-credit-log') }}" style="margin-top:8px;">
        <input type="text" name="employee" value="{{ $employee }}" placeholder="Employee name..." style="padding:4px 8px; width:160px;">
        <input type="text" name="customer" value="{{ $customer }}" placeholder="Customer name..." style="padding:4px 8px; width:160px; margin-left:6px;">
        <label style="margin-left:8px; font-weight:400;">From
            <input type="date" name="from" value="{{ $from }}" style="padding:2px 4px;">
        </label>
        <label style="margin-left:4px; font-weight:400;">To
            <input type="date" name="to" value="{{ $to }}" style="padding:2px 4px;">
        </label>
        <label style="margin-left:10px; font-weight:400;">
            <input type="checkbox" name="only_no_form" value="1" {{ $only_no_form ? 'checked' : '' }}>
            Only credits without a purchase form
        </label>
        <button type="submit" class="btn btn-default btn-sm">Filter</button>
        <a href="{{ url('/admin/store-credit-log') }}" class="btn btn-link btn-sm">Reset</a>
    </form>
</section>

<section class="content">
    <div class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title">Per Employee</h3></div>
        <div class="box-body">
            <table class="table table-striped table-condensed">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th style="text-align:right;">Events</th>
                        <th style="text-align:right;">Total Credit Issued</th>
                        <th style="text-align:right;">No-Form Events</th>
                        <th style="text-align:right;">No-Form Credit</th>
                        <th style="text-align:right;">Store Credit Used</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($byEmployee as $row)
                        <tr>
                            <td>{{ $row['employee'] }}</td>
                            <td style="text-align:right;">{{ $row['events'] }}</td>
                            <td style="text-align:right;">${{ number_format($row['total_issued'], 2) }}</td>
                            <td style="text-align:right;">
                                @if($row['no_form_events'] > 0)
                                    <span class="label label-warning">{{ $row['no_form_events'] }}</span>
                                @else
                                    0
                                @endif
                            </td>
                            <td style="text-align:right;">${{ number_format($row['no_form_issued'], 2) }}</td>
                            <td style="text-align:right;">${{ number_format($row['total_used'], 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-muted">No store-credit events match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">Events ({{ count($events) }})</h3>
        </div>
        <div class="box-body">
            <table class="table table-striped table-condensed">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Employee</th>
                        <th>Customer</th>
                        <th style="text-align:right;">Amount</th>
                        <th style="text-align:right;">Balance After</th>
                        <th>Reason</th>
                        <th>Type</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($events as $e)
                        <tr @if($e['type'] === 'redeem') style="background:#eef6fb;" @elseif(!$e['has_form']) style="background:#fcf8e3;" @endif>
                            <td style="white-space:nowrap;">{{ $e['ts'] }}</td>
                            <td>{{ $e['employee'] !== '' ? $e['employee'] : 'unknown' }}</td>
                            <td>
                                @if($e['contact_id'])
                                    <a href="{{ action('ContactController@show', [$e['contact_id']]) }}">{{ $e['contact_name'] ?: ('#' . $e['contact_id']) }}</a>
                                @else
                                    {{ $e['contact_name'] ?: '—' }}
                                @endif
                            </td>
                            <td style="text-align:right; {{ $e['amount'] < 0 ? 'color:#c0392b;' : '' }}">
                                {{ $e['amount'] < 0 ? '-' : '+' }}${{ number_format(abs($e['amount']), 2) }}
                            </td>
                            <td style="text-align:right;">{{ !is_null($e['balance_after']) ? '$' . number_format($e['balance_after'], 2) : '—' }}</td>
                            <td>{{ $e['reason'] !== '' ? $e['reason'] : '—' }}</td>
                            <td>
                                @if($e['type'] === 'redeem')
                                    <span class="label label-info">Used on sale</span>
                                @elseif($e['has_form'])
                                    <span class="label label-success">Issued &mdash; with form @if($e['offer_id'])(#{{ $e['offer_id'] }})@endif</span>
                                @else
                                    <span class="label label-warning">Issued &mdash; no purchase form</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-muted">No store-credit events match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
@endsection
