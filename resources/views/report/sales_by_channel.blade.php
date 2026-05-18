@extends('layouts.app')
@section('title', 'Sales by Channel')

@section('content')
<section class="content-header">
    <h1>Sales by Channel <small>revenue &amp; gross profit per location &amp; channel</small></h1>
</section>

<section class="content">

    <div class="row">
        <div class="col-md-3">
            <div class="info-box bg-blue">
                <span class="info-box-icon"><i class="fa fa-dollar-sign"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Total revenue</span>
                    <span class="info-box-number">${{ number_format($totals['revenue'], 2) }}</span>
                    <span class="progress-description">{{ (int)$totals['cnt'] }} transactions</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-green">
                <span class="info-box-icon"><i class="fa fa-chart-line"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Gross profit</span>
                    <span class="info-box-number">${{ number_format($totals['gross_profit'], 2) }}</span>
                    <span class="progress-description">{{ number_format($totals['gross_margin'], 1) }}% gross margin</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-purple">
                <span class="info-box-icon"><i class="fa fa-stream"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Channels reporting</span>
                    <span class="info-box-number">{{ count($rows) }}</span>
                    <span class="progress-description">{{ $start_date }} → {{ $end_date }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-aqua">
                <span class="info-box-icon"><i class="fa fa-trophy"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Top channel</span>
                    <span class="info-box-number" style="font-size:18px;">{{ $rows[0]['label'] ?? '—' }}</span>
                    <span class="progress-description">
                        @if(!empty($rows))
                            ${{ number_format($rows[0]['revenue'], 0) }} ({{ number_format($rows[0]['share_pct'], 1) }}%)
                        @endif
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="box box-primary">
        <div class="box-header with-border">
            <h3 class="box-title">Filters</h3>
        </div>
        <div class="box-body">
            <form method="GET" action="{{ action('ReportController@salesByChannel') }}" class="row">
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
                    <a class="btn btn-default" href="{{ action('ReportController@salesByChannel', ['start_date' => $start_date, 'end_date' => $end_date, 'export' => 'csv']) }}">
                        <i class="fa fa-download"></i> Download CSV
                    </a>
                </div>
            </form>
        </div>
    </div>

    @if(!empty($diagnostics))
        @php
            $diagnostics_open = false;
            foreach ($diagnostics as $_d) {
                $dlow = strtolower((string) $_d);
                if (strpos($dlow, 'failed') !== false
                    || strpos($dlow, 'not set') !== false
                    || strpos($dlow, 'not configured') !== false
                    || strpos($dlow, 'not connected') !== false
                    || strpos($dlow, 'rejected') !== false
                    || strpos($dlow, 'error') !== false) {
                    $diagnostics_open = true;
                    break;
                }
            }
        @endphp
        <details @if($diagnostics_open) open @endif style="margin: 0 0 12px 0; padding: 8px 12px; background: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px;">
            <summary style="cursor: pointer; font-size: 12px; color: #475569; font-weight: 600;">
                Channel fetch status ({{ count($diagnostics) }})
            </summary>
            <ul style="margin: 8px 0 0 0; padding-left: 20px; font-size: 12px; color: #334155;">
                @foreach($diagnostics as $d)
                    <li>{{ $d }}</li>
                @endforeach
            </ul>
        </details>
    @endif

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">Breakdown</h3>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Channel</th>
                        <th class="text-right">Revenue</th>
                        <th>Progress <small class="text-muted">({{ $target_period_label }})</small></th>
                        <th class="text-right">Share</th>
                        <th class="text-right">Transactions</th>
                        <th class="text-right">Gross Profit</th>
                        <th class="text-right">Gross Margin</th>
                        <th class="text-right">Op. Profit</th>
                        <th class="text-right">Net Profit</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                        <tr @if(!empty($r['integration_placeholder'])) class="warning" style="background:#fffbeb;" @endif>
                            <td>
                                <strong>{{ $r['label'] }}</strong>
                            </td>
                            <td class="text-right">${{ number_format($r['revenue'], 2) }}</td>
                            <td style="min-width:180px;">
                                @if(!is_null($r['target_pct']))
                                    @php
                                        $pct = max(0, $r['target_pct']);
                                        $bar_w = min(100, $pct);
                                        if ($pct >= 100)      { $bar_class = 'progress-bar-success'; }
                                        elseif ($pct >= 75)   { $bar_class = 'progress-bar-primary'; }
                                        elseif ($pct >= 50)   { $bar_class = 'progress-bar-warning'; }
                                        else                  { $bar_class = 'progress-bar-danger'; }
                                        $combined = isset($r['target_revenue']) ? (float) $r['target_revenue'] : (float) $r['revenue'];
                                        $is_rollup = isset($r['target_store']) && $r['label'] !== $r['target_store'];
                                    @endphp
                                    <div class="progress" style="margin-bottom:4px; height:14px;" title="${{ number_format($combined, 0) }} of ${{ number_format($r['target'], 0) }} {{ $target_period_label }}{{ $is_rollup ? ' (rolled into ' . $r['target_store'] . ')' : '' }}">
                                        <div class="progress-bar {{ $bar_class }}" role="progressbar" style="width: {{ $bar_w }}%;" aria-valuenow="{{ $bar_w }}" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <small class="text-muted">
                                        {{ number_format($pct, 0) }}% of ${{ number_format($r['target'], 0) }}
                                        @if($is_rollup)
                                            <em>· {{ $r['target_store'] }} total</em>
                                        @endif
                                    </small>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-right">{{ number_format($r['share_pct'], 1) }}%</td>
                            <td class="text-right">{{ (int)$r['cnt'] }}</td>
                            @if(!empty($r['cost_unknown']))
                                <td class="text-right text-muted" title="Cost basis lives on the website backend; not yet pulled into ERP.">—</td>
                                <td class="text-right text-muted">—</td>
                            @else
                                <td class="text-right">${{ number_format($r['gross_profit'], 2) }}</td>
                                <td class="text-right">{{ number_format($r['gross_margin'], 1) }}%</td>
                            @endif
                            <td class="text-right text-muted">—</td>
                            <td class="text-right text-muted">—</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted">No sales in this period.</td></tr>
                    @endforelse
                </tbody>
                @if(!empty($rows))
                    <tfoot>
                        <tr style="font-weight:700; background:#f8fafc;">
                            <td>TOTAL</td>
                            <td class="text-right">${{ number_format($totals['revenue'], 2) }}</td>
                            <td style="min-width:180px;">
                                @if(!is_null($totals['target_pct']))
                                    @php
                                        $tpct = max(0, $totals['target_pct']);
                                        $tbar_w = min(100, $tpct);
                                        if ($tpct >= 100)     { $tbar_class = 'progress-bar-success'; }
                                        elseif ($tpct >= 75)  { $tbar_class = 'progress-bar-primary'; }
                                        elseif ($tpct >= 50)  { $tbar_class = 'progress-bar-warning'; }
                                        else                  { $tbar_class = 'progress-bar-danger'; }
                                    @endphp
                                    <div class="progress" style="margin-bottom:4px; height:14px;" title="${{ number_format($totals['revenue'], 0) }} of ${{ number_format($totals['target'], 0) }} {{ $totals['target_period_label'] }}">
                                        <div class="progress-bar {{ $tbar_class }}" role="progressbar" style="width: {{ $tbar_w }}%;" aria-valuenow="{{ $tbar_w }}" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                    <small class="text-muted">{{ number_format($tpct, 0) }}% of ${{ number_format($totals['target'], 0) }}</small>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-right">100.0%</td>
                            <td class="text-right">{{ (int)$totals['cnt'] }}</td>
                            <td class="text-right">${{ number_format($totals['gross_profit'], 2) }}</td>
                            <td class="text-right">{{ number_format($totals['gross_margin'], 1) }}%</td>
                            <td class="text-right text-muted">—</td>
                            <td class="text-right text-muted">—</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
            <p class="text-muted" style="font-size:12px; margin-top:8px;">
                Operating profit and net profit per channel require expense-allocation rules (rent, payroll, etc.) that are not yet defined.
                See the <a href="{{ action('ReportController@getProfitLoss') }}">Profit / Loss report</a> for the consolidated view.<br>
                Website Shipping &amp; Pickup rows show revenue only — cost basis lives on the website backend and isn't pulled into the ERP yet.
                Space Rentals are pure revenue (no COGS).
            </p>
        </div>
    </div>

</section>
@stop
