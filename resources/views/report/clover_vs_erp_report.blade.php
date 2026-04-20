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

    @if($selected_method === 'auto' && isset($effective_method) && $effective_method === 'all')
        <div class="alert alert-warning" style="border-left: 4px solid #f0ad4e;">
            <strong>Heads up:</strong> none of this date's payment methods matched the built-in card/Clover list, so the report is showing <strong>all</strong> payment methods instead of auto-filtering. Pick the exact method from the dropdown above (e.g. the one Clover payments are stored as on this install) to narrow the results.
        </div>
    @endif

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Filters</h3></div>
        <div class="box-body">
            <form method="GET" action="{{ action('ReportController@cloverVsErpReport') }}" class="row">
                <div class="col-md-3">
                    <label>Date</label>
                    <input type="date" class="form-control" name="date" value="{{ $date }}">
                </div>
                <div class="col-md-3">
                    <label>Location</label>
                    <select name="location_id" class="form-control">
                        <option value="">All locations</option>
                        @foreach($business_locations as $id => $name)
                            <option value="{{ $id }}" @if((string)$location_id === (string)$id) selected @endif>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label>Payment method</label>
                    <select name="method" class="form-control">
                        <option value="auto" @if($selected_method==='auto') selected @endif>Auto (card + clover + custom_pay)</option>
                        <option value="all" @if($selected_method==='all') selected @endif>All methods</option>
                        @foreach($methods_breakdown as $m)
                            <option value="{{ $m->method }}" @if($selected_method===$m->method) selected @endif>{{ $m->method }} ({{ $m->cnt }})</option>
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

    {{-- Diagnostic: payment methods actually seen for this date. Makes it obvious
         when the query returns 0 why that is (wrong method filter, etc.). --}}
    <div class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title">Payment methods seen on this date <small style="color:#6b7280;">helps spot data mismatches quickly</small></h3></div>
        <div class="box-body table-responsive">
            <table class="table table-condensed">
                <thead>
                    <tr style="color:#6b7280; text-transform:uppercase; font-size:11px; letter-spacing:.5px;">
                        <th>Method (raw value in transaction_payments.method)</th>
                        <th class="text-right"># payments</th>
                        <th class="text-right">Total $</th>
                        <th>Included in report?</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($methods_breakdown as $m)
                        @php
                            if ($selected_method === 'all') { $included = true; }
                            elseif ($selected_method === 'auto') { $included = in_array($m->method, $default_card_methods); }
                            else { $included = $selected_method === $m->method; }
                        @endphp
                        <tr>
                            <td><code>{{ $m->method }}</code></td>
                            <td class="text-right">{{ $m->cnt }}</td>
                            <td class="text-right">${{ number_format($m->total, 2) }}</td>
                            <td>
                                @if($included)
                                    <span class="label label-success">Yes</span>
                                @else
                                    <span class="label label-default">No</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted text-center">No finalized sell payments at all on this date.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <small class="text-muted">
                If your Clover payments show up under a method name that isn't selected (e.g. <code>custom_pay_1</code>), pick it from the "Payment method" dropdown above. Once we know the canonical name used on this install, we'll lock it in as the default.
            </small>
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
