@extends('layouts.app')
@section('title', 'Cash Flow')

@section('content')
<section class="content-header">
    <h1>Cash Flow <small>live from QuickBooks</small></h1>
</section>

<section class="content">

    @if(!$configured)
        <div class="alert alert-warning">
            <strong>QuickBooks isn't connected.</strong>
            Connect at <a href="{{ url('/business/quickbooks/connect') }}">Business → QuickBooks</a> first.
        </div>
    @endif

    @if($accounts_error)
        <div class="alert alert-danger"><strong>Bank accounts:</strong> {{ $accounts_error }}</div>
    @endif
    @if($report_error)
        <div class="alert alert-danger"><strong>Cash flow report:</strong> {{ $report_error }}</div>
    @endif

    <div class="row">
        <div class="col-md-3">
            <div class="info-box bg-blue">
                <span class="info-box-icon"><i class="fa fa-university"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Net cash position (now)</span>
                    <span class="info-box-number">${{ number_format($bank_total, 2) }}</span>
                    <span class="progress-description">bank − credit card debt</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-aqua">
                <span class="info-box-icon"><i class="fa fa-flag-checkered"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Cash at start of period</span>
                    <span class="info-box-number">${{ number_format($totals['beginning'] ?? 0, 2) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-green">
                <span class="info-box-icon"><i class="fa fa-flag"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Cash at end of period</span>
                    <span class="info-box-number">${{ number_format($totals['ending'] ?? 0, 2) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box {{ ($totals['net'] ?? 0) >= 0 ? 'bg-purple' : 'bg-yellow' }}">
                <span class="info-box-icon"><i class="fa fa-exchange-alt"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Net change for period</span>
                    <span class="info-box-number">${{ number_format($totals['net'] ?? 0, 2) }}</span>
                    <span class="progress-description">{{ $start_date }} → {{ $end_date }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Filters</h3></div>
        <div class="box-body">
            <form method="GET" action="{{ action('ReportController@cashFlowReport') }}" class="row">
                <div class="col-md-3">
                    <label>Start date</label>
                    <input type="date" class="form-control" name="start_date" value="{{ $start_date }}">
                </div>
                <div class="col-md-3">
                    <label>End date</label>
                    <input type="date" class="form-control" name="end_date" value="{{ $end_date }}">
                </div>
                <div class="col-md-6">
                    <label style="display:block;">&nbsp;</label>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-filter"></i> Apply</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-md-5">
            <div class="box box-solid">
                <div class="box-header with-border"><h3 class="box-title">Bank &amp; card balances</h3></div>
                <div class="box-body table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Account</th>
                                <th>Type</th>
                                <th class="text-right">Balance</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($accounts as $a)
                                <tr>
                                    <td>{{ $a['name'] }}</td>
                                    <td><small>{{ $a['type'] }}{{ !empty($a['subtype']) ? ' / '.$a['subtype'] : '' }}</small></td>
                                    <td class="text-right">${{ number_format($a['balance'], 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted">No bank/credit accounts found in QuickBooks.</td></tr>
                            @endforelse
                        </tbody>
                        @if(count($accounts))
                            <tfoot>
                                <tr style="font-weight:700; background:#f8fafc;">
                                    <td colspan="2">TOTAL</td>
                                    <td class="text-right">${{ number_format($bank_total, 2) }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-7">
            <div class="box box-solid">
                <div class="box-header with-border"><h3 class="box-title">Cash Flow Statement (QuickBooks)</h3></div>
                <div class="box-body table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Line</th>
                                <th class="text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($report_rows as $r)
                                <tr @if(!empty($r['is_summary'])) style="font-weight:700; background:#f1f5f9;" @endif>
                                    <td style="padding-left: {{ 12 + ($r['depth'] * 16) }}px;">{{ $r['label'] }}</td>
                                    <td class="text-right">
                                        @if(is_numeric($r['amount']))
                                            ${{ number_format((float)$r['amount'], 2) }}
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="2" class="text-center text-muted">No cash flow data for this period.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    <p class="text-muted" style="font-size:12px; margin-top:8px;">
                        Pulled live from QuickBooks /reports/CashFlow (accrual). For the full statement with sub-lines, open it in QuickBooks directly.
                    </p>
                </div>
            </div>
        </div>
    </div>

</section>
@stop
