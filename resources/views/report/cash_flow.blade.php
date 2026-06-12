@extends('layouts.app')
@section('title', 'Cash Flow')

@section('content')
<section class="content-header">
    <h1>Cash Flow <small>weekly — budget vs actual</small></h1>
</section>

<section class="content">

    @if(!$configured)
        <div class="alert alert-warning">
            <strong>QuickBooks isn't connected.</strong>
            The budget still shows, but actuals will be blank. Connect at
            <a href="{{ url('/business/quickbooks/connect') }}">Business → QuickBooks</a>.
        </div>
    @endif
    @if($accounts_error)
        <div class="alert alert-danger"><strong>Bank accounts:</strong> {{ $accounts_error }}</div>
    @endif
    @if(!empty($actuals_error))
        <div class="alert alert-info">{{ $actuals_error }}</div>
    @endif

    {{-- Reference figures + budget upload --}}
    <div class="row">
        <div class="col-md-3">
            <div class="info-box bg-blue">
                <span class="info-box-icon"><i class="fa fa-university"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Cash in the bank now</span>
                    <span class="info-box-number">${{ number_format($bank_total, 0) }}</span>
                    <span class="progress-description">bank − credit card debt (live)</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-aqua">
                <span class="info-box-icon"><i class="fa fa-flag-checkered"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Opening balance (week 1)</span>
                    <span class="info-box-number">${{ number_format($grid['opening_seed'] ?? 0, 0) }}</span>
                    <span class="progress-description">money at the beginning</span>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="box box-solid" style="margin-bottom:0;">
                <div class="box-header with-border"><h3 class="box-title">Budget sheet</h3></div>
                <div class="box-body">
                    <form method="POST" action="{{ url('/reports/cash-flow/budget') }}" enctype="multipart/form-data" class="form-inline">
                        @csrf
                        <div class="form-group">
                            <input type="file" name="budget_file" accept=".xlsx,.xls" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="fa fa-upload"></i> Upload / replace budget</button>
                    </form>
                    @if(!empty($budget))
                        <p class="text-muted" style="margin-top:8px; margin-bottom:0;">
                            Loaded <strong>{{ $budget['uploaded_filename'] ?? $budget['source_sheet'] }}</strong>
                            ({{ count($budget['weeks']) }} weeks)
                            @if(!empty($budget['uploaded_at'])) on {{ $budget['uploaded_at'] }} @endif.
                            Upload the <em>Weekly v2</em> tab of your cash-flow workbook to update it.
                        </p>
                    @else
                        <p class="text-muted" style="margin-top:8px; margin-bottom:0;">
                            Upload the <em>Weekly v2</em> tab of your cash-flow workbook to build the report.
                        </p>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @if(empty($budget) || empty($grid))
        <div class="box box-default">
            <div class="box-body">
                <p class="text-muted" style="font-size:14px;">
                    No budget loaded yet. Upload your weekly cash-flow sheet above and this report
                    will show, for each week: <strong>money at the beginning</strong>,
                    the <strong>budget</strong> for every line item, and <strong>what you actually
                    spent / brought in</strong> (pulled from QuickBooks), ending with the closing balance.
                </p>
            </div>
        </div>
    @else
        @php
            $weeks = $budget['weeks'];
            $weekCount = count($weeks);
            $hasActuals = !empty($grid['has_actuals']);

            // Whole-dollar formatter: "-" for zero, parentheses for negatives.
            $money = function ($v) {
                $v = round((float) $v);
                if ($v == 0) return '-';
                $s = number_format(abs($v));
                return $v < 0 ? '(' . $s . ')' : $s;
            };
            // Pick a text colour for balance/net rows.
            $signColor = function ($v) {
                $v = round((float) $v);
                if ($v < 0) return '#b91c1c';
                if ($v > 0) return '#15803d';
                return '#64748b';
            };
        @endphp

        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Weekly cash flow</h3>
                <span class="pull-right text-muted" style="font-size:12px;">
                    Each cell: <strong>actual</strong> on top
                    @if($hasActuals)(from QuickBooks)@else<em>(no actuals — QuickBooks unavailable)</em>@endif,
                    <span style="color:#64748b;">budget</span> below.
                </span>
            </div>
            <div class="box-body table-responsive">
                <table class="table table-bordered" style="font-size:12px; white-space:nowrap;">
                    <thead>
                        <tr style="background:#f1f5f9;">
                            <th style="position:sticky; left:0; background:#f1f5f9; min-width:200px;">Line item</th>
                            @foreach($weeks as $w)
                                <th class="text-right" style="min-width:90px;">
                                    {{ $w['label'] }}<br>
                                    <small class="text-muted">{{ $w['range'] }}</small>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Opening balance --}}
                        <tr style="background:#ecfeff; font-weight:700;">
                            <td style="position:sticky; left:0; background:#ecfeff;">Money at the beginning</td>
                            @for($i = 0; $i < $weekCount; $i++)
                                <td class="text-right">
                                    @if($hasActuals)<div style="color:{{ $signColor($grid['opening_actual'][$i]) }};">{{ $money($grid['opening_actual'][$i]) }}</div>@endif
                                    <div style="color:#64748b; font-weight:400;">{{ $money($grid['opening_budget'][$i]) }}</div>
                                </td>
                            @endfor
                        </tr>

                        {{-- Sections --}}
                        @foreach($grid['sections'] as $sec)
                            <tr style="background:#f8fafc;">
                                <td colspan="{{ $weekCount + 1 }}" style="position:sticky; left:0; background:#f8fafc; font-weight:700; letter-spacing:.5px; color:#475569;">
                                    {{ $sec['title'] }}
                                </td>
                            </tr>
                            @foreach($sec['items'] as $item)
                                <tr @if(!empty($item['is_unmapped'])) style="font-style:italic; color:#92400e;" @endif>
                                    <td style="position:sticky; left:0; background:#fff; padding-left:24px;">{{ $item['label'] }}</td>
                                    @for($i = 0; $i < $weekCount; $i++)
                                        <td class="text-right">
                                            @if($hasActuals)<div>{{ $money($item['actual'][$i]) }}</div>@endif
                                            <div style="color:#94a3b8;">{{ $money($item['budget'][$i]) }}</div>
                                        </td>
                                    @endfor
                                </tr>
                            @endforeach
                            <tr style="font-weight:700; background:#f1f5f9;">
                                <td style="position:sticky; left:0; background:#f1f5f9; padding-left:24px;">Total {{ ucfirst(strtolower($sec['title'])) }}</td>
                                @for($i = 0; $i < $weekCount; $i++)
                                    <td class="text-right">
                                        @if($hasActuals)<div>{{ $money($sec['subtotal_actual'][$i]) }}</div>@endif
                                        <div style="color:#64748b; font-weight:400;">{{ $money($sec['subtotal_budget'][$i]) }}</div>
                                    </td>
                                @endfor
                            </tr>
                        @endforeach

                        {{-- Net cash flow --}}
                        <tr style="font-weight:700; background:#fffbeb;">
                            <td style="position:sticky; left:0; background:#fffbeb;">Net cash flow</td>
                            @for($i = 0; $i < $weekCount; $i++)
                                <td class="text-right">
                                    @if($hasActuals)<div style="color:{{ $signColor($grid['net_actual'][$i]) }};">{{ $money($grid['net_actual'][$i]) }}</div>@endif
                                    <div style="color:#64748b; font-weight:400;">{{ $money($grid['net_budget'][$i]) }}</div>
                                </td>
                            @endfor
                        </tr>

                        {{-- Closing balance --}}
                        <tr style="font-weight:700; background:#ecfeff;">
                            <td style="position:sticky; left:0; background:#ecfeff;">Money at the end</td>
                            @for($i = 0; $i < $weekCount; $i++)
                                <td class="text-right">
                                    @if($hasActuals)<div style="color:{{ $signColor($grid['closing_actual'][$i]) }};">{{ $money($grid['closing_actual'][$i]) }}</div>@endif
                                    <div style="color:#64748b; font-weight:400;">{{ $money($grid['closing_budget'][$i]) }}</div>
                                </td>
                            @endfor
                        </tr>
                    </tbody>
                </table>
                <p class="text-muted" style="font-size:11px; margin-top:8px;">
                    Budget comes from your uploaded <em>Weekly v2</em> sheet. Actuals are pulled from
                    QuickBooks (cash basis) and bucketed into the same weeks. Both balances roll forward
                    from the same week-1 opening seed (${{ number_format($grid['opening_seed'] ?? 0, 0) }}).
                    Costs show in parentheses. Anything QuickBooks couldn't match to a line item is
                    collected in an <em>Other (QuickBooks, unmapped)</em> row so totals stay honest.
                </p>
            </div>
        </div>

        {{-- Bank balances reference --}}
        <div class="box box-solid">
            <div class="box-header with-border"><h3 class="box-title">Bank &amp; card balances (live)</h3></div>
            <div class="box-body table-responsive">
                <table class="table table-bordered table-striped" style="max-width:520px;">
                    <thead>
                        <tr><th>Account</th><th>Type</th><th class="text-right">Balance</th></tr>
                    </thead>
                    <tbody>
                        @forelse($accounts as $a)
                            <tr>
                                <td>{{ $a['name'] }}</td>
                                <td><small>{{ $a['type'] }}{{ !empty($a['subtype']) ? ' / '.$a['subtype'] : '' }}</small></td>
                                <td class="text-right">${{ number_format($a['balance'], 0) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="text-center text-muted">No bank/credit accounts found in QuickBooks.</td></tr>
                        @endforelse
                    </tbody>
                    @if(count($accounts))
                        <tfoot>
                            <tr style="font-weight:700; background:#f8fafc;">
                                <td colspan="2">TOTAL</td>
                                <td class="text-right">${{ number_format($bank_total, 0) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>
    @endif

</section>
@stop
