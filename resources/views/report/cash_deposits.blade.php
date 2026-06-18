@extends('layouts.app')
@section('title', 'Cash Deposits')

@section('content')

<section class="content-header">
    <h1>Cash Deposits <small>safe-drop log</small></h1>
</section>

<section class="content">

    @if(!empty($not_installed) && $not_installed)
        <div class="row">
            <div class="col-md-12">
                <div class="callout callout-warning">
                    <h4>Deposit log not installed yet</h4>
                    <p class="mb-0">
                        The <code>cash_deposits</code> table hasn't been created on this
                        environment. Run the one-click installer, then deposits will start
                        appearing here.
                    </p>
                    <p style="margin-top:10px;">
                        <a href="{{ url('/admin/install-cash-deposits-table') }}" class="btn btn-primary btn-sm">
                            Go to installer
                        </a>
                    </p>
                </div>
            </div>
        </div>
    @else

    <div class="row">
        <div class="col-md-12">
            <div class="callout callout-info">
                <p class="text-muted mb-0"><small>
                    Every safe drop a cashier makes, with the deposit number they wrote on
                    the envelope, who dropped it, when, and how much. Use it to trace any
                    brown envelope back to its shift.
                </small></p>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])
                {!! Form::open(['url' => action('CashDepositsReportController@index'), 'method' => 'get', 'id' => 'cash_deposits_filter_form' ]) !!}
                    <div class="col-md-3">
                        <div class="form-group">
                            {!! Form::label('location_id', 'Store:') !!}
                            {!! Form::select('location_id', $business_locations, $filters['location_id'] ?? null, ['class' => 'form-control select2', 'style' => 'width:100%', 'placeholder' => 'All stores']) !!}
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            {!! Form::label('phase', 'When dropped:') !!}
                            {!! Form::select('phase', ['open' => 'At register open', 'close' => 'At register close'], $filters['phase'] ?? null, ['class' => 'form-control select2', 'style' => 'width:100%', 'placeholder' => 'Open + close']) !!}
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            {!! Form::label('start_date', 'From:') !!}
                            {!! Form::date('start_date', $filters['start_date'] ?? null, ['class' => 'form-control']) !!}
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            {!! Form::label('end_date', 'To:') !!}
                            {!! Form::date('end_date', $filters['end_date'] ?? null, ['class' => 'form-control']) !!}
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary btn-block">Apply</button>
                        </div>
                    </div>
                {!! Form::close() !!}
            @endcomponent
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="box box-solid">
                <div class="box-body">

                    <div style="margin-bottom:12px;">
                        <strong>{{ number_format($totals['count']) }}</strong> deposit{{ $totals['count'] === 1 ? '' : 's' }}
                        &nbsp;·&nbsp;
                        total <strong>${{ number_format($totals['amount'], 2) }}</strong>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-bordered table-striped">
                            <thead>
                                <tr>
                                    <th>Deposit #</th>
                                    <th>Store</th>
                                    <th>Cashier</th>
                                    <th>When dropped</th>
                                    <th>Phase</th>
                                    <th class="text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($deposits as $d)
                                    <tr>
                                        <td><strong>#{{ $d->deposit_seq }}</strong></td>
                                        <td>{{ $d->location_name ?: '—' }}</td>
                                        <td>{{ $d->cashier_name ?: '—' }}</td>
                                        <td>
                                            {{ \Carbon::parse($d->deposited_at)->setTimezone('America/Los_Angeles')->format('M j, Y g:i A') }}
                                        </td>
                                        <td>
                                            @if($d->phase === 'open')
                                                <span class="label label-info">Open</span>
                                            @elseif($d->phase === 'close')
                                                <span class="label label-default">Close</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="text-right">${{ number_format((float) $d->amount, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="text-center text-muted" style="padding:24px;">
                                            No deposits in this range.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                            @if($totals['count'] > 0)
                            <tfoot>
                                <tr>
                                    <th colspan="5" class="text-right">Total</th>
                                    <th class="text-right">${{ number_format($totals['amount'], 2) }}</th>
                                </tr>
                            </tfoot>
                            @endif
                        </table>
                    </div>

                </div>
            </div>
        </div>
    </div>

    @endif

</section>

@endsection
