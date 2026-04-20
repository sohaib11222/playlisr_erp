@extends('layouts.app')
@section('title', 'Clover vs ERP Reconciliation')

@section('content')
<section class="content-header">
    <h1>Clover vs ERP Reconciliation <small>daily card/Clover payment rollup</small></h1>
</section>

<section class="content">

    <div class="alert alert-info" style="border-left: 4px solid #3c8dbc;">
        <strong>How this works:</strong> this page shows the <strong>ERP side</strong> of your daily reconciliation — every card/Clover payment in the ERP for the selected date, rolled up by employee (like the "Employee / ERP" columns in your spreadsheet).
        <br><br>
        The Clover side (live pull from the Clover API for per-employee totals and per-transaction detail) requires a backend sync that hasn't been built yet — once Sohaib wires it up, the Clover columns will populate here automatically and the manual diffing step goes away.
    </div>

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Filters</h3></div>
        <div class="box-body">
            <form method="GET" action="{{ action('ReportController@cloverVsErpReport') }}" class="row">
                <div class="col-md-3">
                    <label>Date</label>
                    <input type="date" class="form-control" name="date" value="{{ $date }}">
                </div>
                <div class="col-md-4">
                    <label>Location</label>
                    <select name="location_id" class="form-control">
                        <option value="">All locations</option>
                        @foreach($business_locations as $id => $name)
                            <option value="{{ $id }}" @if((string)$location_id === (string)$id) selected @endif>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label style="display:block;">&nbsp;</label>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-filter"></i> Apply</button>
                </div>
            </form>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">By Employee — ERP card/Clover totals for {{ \Carbon\Carbon::parse($date)->format('M j, Y') }}</h3>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th class="text-right"># ERP payments</th>
                        <th class="text-right">ERP total</th>
                        <th class="text-right text-muted">Clover total <small>(pending)</small></th>
                        <th class="text-right text-muted">Difference <small>(pending)</small></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($employee_rows as $r)
                        <tr>
                            <td><strong>{{ trim($r->employee) ?: '(unknown)' }}</strong></td>
                            <td class="text-right">{{ (int) $r->cnt }}</td>
                            <td class="text-right">${{ number_format($r->erp_total, 2) }}</td>
                            <td class="text-right text-muted">—</td>
                            <td class="text-right text-muted">—</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted">No ERP card/Clover payments recorded on this date.</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr style="background: #f8f9fa; font-weight: bold;">
                        <td>Total</td>
                        <td class="text-right">{{ $employee_rows->sum('cnt') }}</td>
                        <td class="text-right">${{ number_format($grand_total, 2) }}</td>
                        <td class="text-right text-muted">—</td>
                        <td class="text-right text-muted">—</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">ERP payment detail ({{ $detail_rows->count() }} payments)</h3>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Location</th>
                        <th>Employee</th>
                        <th>Invoice #</th>
                        <th>Method</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($detail_rows as $d)
                        <tr>
                            <td>{{ $d->paid_on ? \Carbon\Carbon::parse($d->paid_on)->format('h:i A') : '' }}</td>
                            <td>{{ $d->location_name }}</td>
                            <td>{{ trim($d->employee) ?: '(unknown)' }}</td>
                            <td>{{ $d->invoice_no }}</td>
                            <td><span class="label label-default">{{ strtoupper($d->method) }}{{ $d->card_type ? ' / ' . strtoupper($d->card_type) : '' }}</span></td>
                            <td class="text-right">${{ number_format($d->amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted">No payments found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</section>
@stop
