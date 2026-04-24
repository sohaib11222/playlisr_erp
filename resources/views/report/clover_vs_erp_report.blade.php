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

    {{-- Per-cashier breakdown: every active employee with activity today gets
         a card showing (a) ERP sales lines, (b) Clover txns, (c) cash paid
         out on collection buys. Lets Sarah reconcile one person at a time. --}}
    @php
        // Bucket ERP sale lines per employee too
        $detail_by_employee = $detail_rows->groupBy(fn($d) => trim((string) $d->employee) ?: '(unknown)');

        // Union of all employees that had any activity on this date
        $all_employees = collect()
            ->merge($detail_by_employee->keys())
            ->merge($clover_by_employee->keys())
            ->merge($buy_cash_by_employee->keys())
            ->unique()
            ->filter()
            ->sort()
            ->values();
    @endphp

    @foreach($all_employees as $emp_name)
        @php
            $emp_sales   = $detail_by_employee[$emp_name] ?? collect();
            $emp_clover  = collect();
            // Match this ERP employee against Clover employee keys (same
            // substring heuristic as the top rollup). Multiple Clover keys
            // might point at the same person (e.g. "Clyde B" + "Clyde").
            $emp_parts = array_filter(explode(' ', strtolower($emp_name)));
            foreach ($clover_by_employee as $cname => $crows) {
                $ckey = strtolower(trim($cname));
                foreach ($emp_parts as $p) {
                    if ($p !== '' && (strpos($ckey, $p) !== false || strpos($p, $ckey) !== false)) {
                        $emp_clover = $emp_clover->merge($crows);
                        break;
                    }
                }
            }
            $emp_buys = $buy_cash_by_employee[$emp_name] ?? collect();

            $erp_sum    = $emp_sales->sum('amount');
            $clover_sum = $emp_clover->sum('amount');
            $buy_sum    = $emp_buys->sum('amount');
            $diff       = $erp_sum - $clover_sum;
            $diffAbs    = abs($diff);
            $isMatch    = $diffAbs < 1;
        @endphp
        <div class="box {{ $isMatch ? 'box-success' : 'box-warning' }}" style="border-top-width:3px;">
            <div class="box-header with-border">
                <h3 class="box-title">
                    <i class="fa fa-user"></i>
                    <strong>{{ $emp_name }}</strong>
                    <small style="margin-left:8px;">
                        ERP ${{ number_format($erp_sum, 2) }}
                        &nbsp;vs&nbsp;
                        Clover ${{ number_format($clover_sum, 2) }}
                        &nbsp;→&nbsp;
                        @if($isMatch)
                            <span class="label label-success">✓ Match</span>
                        @else
                            <span class="label {{ $diffAbs < 50 ? 'label-warning' : 'label-danger' }}">
                                Off by ${{ number_format($diffAbs, 2) }}
                            </span>
                        @endif
                        @if($buy_sum > 0)
                            &nbsp;<span class="label label-primary" title="Cash paid out on collection buys">− ${{ number_format($buy_sum, 2) }} cash buys</span>
                        @endif
                    </small>
                </h3>
            </div>
            <div class="box-body">
                <div class="row">
                    <div class="col-md-6">
                        <h4 style="margin-top:0;">ERP sales <small>({{ $emp_sales->count() }})</small></h4>
                        <table class="table table-condensed table-striped">
                            <thead>
                                <tr><th>Time</th><th>Invoice</th><th>Method</th><th class="text-right">Amount</th></tr>
                            </thead>
                            <tbody>
                                @forelse($emp_sales as $d)
                                    <tr>
                                        <td>{{ $d->paid_on ? \Carbon\Carbon::parse($d->paid_on)->format('h:i A') : '' }}</td>
                                        <td>{{ $d->invoice_no }}</td>
                                        <td><small>{{ strtoupper($d->method) }}{{ $d->card_type ? ' / ' . strtoupper($d->card_type) : '' }}</small></td>
                                        <td class="text-right">${{ number_format($d->amount, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-muted text-center">No ERP sales recorded.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h4 style="margin-top:0;">Clover transactions <small>({{ $emp_clover->count() }})</small></h4>
                        <table class="table table-condensed table-striped">
                            <thead>
                                <tr><th>Time</th><th>Clover ID</th><th>Tender</th><th class="text-right">Amount</th></tr>
                            </thead>
                            <tbody>
                                @forelse($emp_clover->sortByDesc('paid_at') as $c)
                                    @php $isVoid = in_array(strtoupper((string) $c->result), ['VOIDED', 'FAILED', 'REFUNDED']); @endphp
                                    <tr @if($isVoid) style="background:#fff0f0; color:#c0392b; text-decoration:line-through;" @endif>
                                        <td>{{ $c->paid_at ? \Carbon\Carbon::parse($c->paid_at)->setTimezone(config('app.timezone'))->format('h:i A') : '' }}</td>
                                        <td><code style="font-size:11px;">{{ substr($c->clover_payment_id, 0, 8) }}…</code></td>
                                        <td><small>{{ $c->display_tender }}</small></td>
                                        <td class="text-right">${{ number_format((float) $c->amount, 2) }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-muted text-center">No Clover transactions found for this cashier.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                @if($emp_buys->count() > 0)
                <div class="row">
                    <div class="col-md-12">
                        <h4>Cash paid out on collection buys <small>({{ $emp_buys->count() }}) — reduces this cashier's drawer</small></h4>
                        <table class="table table-condensed">
                            <thead>
                                <tr><th>Ref #</th><th>Location</th><th class="text-right">Cash out</th></tr>
                            </thead>
                            <tbody>
                                @foreach($emp_buys as $b)
                                    <tr>
                                        <td>{{ $b->ref_no ?: $b->invoice_no }}</td>
                                        <td>{{ $b->location_name }}</td>
                                        <td class="text-right text-danger"><strong>− ${{ number_format($b->amount, 2) }}</strong></td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr style="font-weight:bold; background:#fafafa;">
                                    <td colspan="2">Total cash out</td>
                                    <td class="text-right text-danger">− ${{ number_format($buy_sum, 2) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                @endif
            </div>
        </div>
    @endforeach

    @if($all_employees->isEmpty())
        <div class="alert alert-info">No active-cashier activity recorded for {{ \Carbon\Carbon::parse($date)->format('M j, Y') }}.</div>
    @endif

    <div class="alert alert-warning" style="border-left: 4px solid #f0ad4e;">
        <strong>Safe drops aren't tracked yet.</strong> There's no way in the ERP right now to record "Clyde dropped $200 in the safe at 2pm." If you want this reflected per cashier, I can add a <em>Cash drop</em> button to the POS + a <code>cash_drops</code> table, and surface drops here next to the cash-out on buys. Say the word.
    </div>

</section>
@stop
