@extends('layouts.app')
@section('title', 'Clover vs ERP Reconciliation')

@section('content')
<section class="content-header">
    <h1>Clover vs ERP Reconciliation <small>daily card/Clover payment rollup</small></h1>
</section>

<section class="content">

    <div class="alert alert-info" style="border-left: 4px solid #3c8dbc;">
        <strong>What this is:</strong> daily reconciliation of ERP sales vs. Clover payments, by cashier. Pick a date and location, then confirm each cashier's ERP total matches their Clover total. Any row that isn't a match needs explaining (actual cash sale, refund, void, etc.).
        @php
            $last_clover_sync = \DB::table('clover_payments')->max('updated_at');
        @endphp
        @if($last_clover_sync)
            <br><span style="font-size:12px; color:#3c8dbc;">Clover data last synced {{ \Carbon\Carbon::parse($last_clover_sync)->diffForHumans() }} ({{ \Carbon\Carbon::parse($last_clover_sync)->format('M j, g:i A') }}).</span>
        @else
            <br><span style="font-size:12px; color:#c0392b;">⚠ Clover has never synced. Contact Jon to prime the Clover feed before using this page.</span>
        @endif
    </div>

    @php
        $prev_day = \Carbon\Carbon::parse($date)->subDay()->format('Y-m-d');
        $next_day = \Carbon\Carbon::parse($date)->addDay()->format('Y-m-d');
        $today_str = \Carbon::today()->format('Y-m-d');
        $is_today = $date === $today_str;
        $is_future = $date > $today_str;
        $nav_base = action('ReportController@cloverVsErpReport');
        $loc_qs = !empty($location_id) ? '&location_id=' . urlencode($location_id) : '';
    @endphp

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Reconcile a day</h3></div>
        <div class="box-body">
            <form method="GET" action="{{ $nav_base }}" class="row" id="reconcile-filter-form">
                <div class="col-md-5">
                    <label>Date</label>
                    <div class="input-group">
                        <a href="{{ $nav_base }}?date={{ $prev_day }}{{ $loc_qs }}" class="btn btn-default" title="Previous day">
                            <i class="fa fa-chevron-left"></i>
                        </a>
                        <input type="date" class="form-control" name="date" value="{{ $date }}" max="{{ $today_str }}" style="text-align:center;" onchange="this.form.submit()">
                        <a href="{{ $nav_base }}?date={{ $next_day }}{{ $loc_qs }}" class="btn btn-default {{ $is_today || $is_future ? 'disabled' : '' }}" title="Next day">
                            <i class="fa fa-chevron-right"></i>
                        </a>
                        <span class="input-group-btn">
                            <a href="{{ $nav_base }}?date={{ $today_str }}{{ $loc_qs }}" class="btn {{ $is_today ? 'btn-default disabled' : 'btn-primary' }}">Today</a>
                        </span>
                    </div>
                    <small class="text-muted">
                        {{ \Carbon\Carbon::parse($date)->format('l, M j, Y') }}
                        @if($is_today) <strong>(today)</strong>
                        @elseif(\Carbon\Carbon::parse($date)->isYesterday()) <strong>(yesterday)</strong>
                        @endif
                    </small>
                </div>
                <div class="col-md-5">
                    <label>Location</label>
                    <select name="location_id" class="form-control" onchange="this.form.submit()">
                        <option value="">All locations</option>
                        @foreach($business_locations as $id => $name)
                            <option value="{{ $id }}" @if((string)$location_id === (string)$id) selected @endif>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label style="display:block;">&nbsp;</label>
                    <button type="submit" class="btn btn-default btn-block"><i class="fa fa-refresh"></i> Reload</button>
                </div>
            </form>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">Daily reconciliation — {{ \Carbon\Carbon::parse($date)->format('l, M j, Y') }}</h3>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Cashier</th>
                        <th class="text-right">ERP sales</th>
                        <th class="text-right">Clover sales</th>
                        <th class="text-right">Difference</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($employee_rows as $r)
                        @php
                            $diff = $r->diff ?? (($r->erp_total ?? 0) - ($r->clover_total ?? 0));
                            $diffAbs = abs((float) $diff);
                            $isMatch = $diffAbs < 1;
                            $diffClass = $isMatch ? 'text-success' : ($diffAbs < 50 ? 'text-warning' : 'text-danger');
                        @endphp
                        <tr>
                            <td><strong>{{ trim($r->employee) ?: '(unknown)' }}</strong>
                                <br><small class="text-muted">{{ (int) $r->cnt }} ERP / {{ (int) ($r->clover_cnt ?? 0) }} Clover</small></td>
                            <td class="text-right">${{ number_format($r->erp_total, 2) }}</td>
                            <td class="text-right">${{ number_format((float) ($r->clover_total ?? 0), 2) }}</td>
                            <td class="text-right {{ $diffClass }}"><strong>{{ $diff >= 0 ? '+' : '−' }}${{ number_format($diffAbs, 2) }}</strong></td>
                            <td>
                                @if($isMatch)
                                    <span class="label label-success">✓ Match</span>
                                @else
                                    <span class="label {{ $diffAbs < 50 ? 'label-warning' : 'label-danger' }}">
                                        Off by ${{ number_format($diffAbs, 2) }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted">No ERP sales recorded on this date.</td></tr>
                    @endforelse
                    @foreach($unmatched_clover ?? [] as $r)
                        <tr style="background:#fff8e1;">
                            <td><em>{{ $r->employee }}</em>
                                <br><small class="text-muted">in Clover but no matching ERP cashier</small></td>
                            <td class="text-right">$0.00</td>
                            <td class="text-right">${{ number_format($r->clover_total, 2) }}</td>
                            <td class="text-right text-danger"><strong>−${{ number_format(abs($r->diff), 2) }}</strong></td>
                            <td><span class="label label-danger">Missing in ERP</span></td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    @php
                        $total_diff = $grand_total - ($clover_grand_total ?? 0);
                        $totalDiffAbs = abs($total_diff);
                        $totalMatch = $totalDiffAbs < 1;
                    @endphp
                    <tr style="background: #f8f9fa; font-weight: bold;">
                        <td>Total</td>
                        <td class="text-right">${{ number_format($grand_total, 2) }}</td>
                        <td class="text-right">${{ number_format($clover_grand_total ?? 0, 2) }}</td>
                        <td class="text-right {{ $totalMatch ? 'text-success' : ($totalDiffAbs < 50 ? 'text-warning' : 'text-danger') }}">
                            {{ $total_diff >= 0 ? '+' : '−' }}${{ number_format($totalDiffAbs, 2) }}
                        </td>
                        <td>
                            @if($totalMatch)
                                <span class="label label-success">✓ Match</span>
                            @else
                                <span class="label {{ $totalDiffAbs < 50 ? 'label-warning' : 'label-danger' }}">
                                    Off by ${{ number_format($totalDiffAbs, 2) }}
                                </span>
                            @endif
                        </td>
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
