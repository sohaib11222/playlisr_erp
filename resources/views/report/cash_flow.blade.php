@extends('layouts.app')
@section('title', 'Cash Flow')

@section('content')
<section class="content-header">
    <h1>Cash Flow <small>weekly — budget vs actual</small></h1>
    @php
        $cf_tx_qs = [];
        if (!empty($budget['weeks'])) {
            $cf_tx_qs['from_date'] = $budget['weeks'][0]['start'] ?? null;
            $cf_tx_qs['to_date'] = $budget['weeks'][count($budget['weeks']) - 1]['end'] ?? null;
        }
        $cf_tx_qs = array_filter($cf_tx_qs);
    @endphp
    <a href="{{ action('QuickBooksController@transactionList') }}{{ $cf_tx_qs ? '?' . http_build_query($cf_tx_qs) : '' }}"
       class="btn btn-default btn-sm" style="margin-top:6px;">
        <i class="fa fa-list"></i> Transaction List by Date
    </a>
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

        @php
            // Render a Budget + Actual cell pair for one week. $sign turns on
            // red/green colouring (balance/net rows); plain rows stay neutral.
            $bDivider = 'border-left:2px solid #cbd5e1;';
            $pair = function ($budgetVal, $actualVal, $sign = false, $budgetMuted = true) use ($money, $signColor, $hasActuals, $bDivider) {
                $bColor = $budgetMuted ? '#64748b' : '#1f2937';
                $bStyle = 'text-align:right;' . $bDivider . 'color:' . $bColor . ';';
                $aStyle = 'text-align:right;';
                if ($hasActuals && $sign) {
                    $aStyle .= 'color:' . $signColor($actualVal) . ';';
                } elseif (!$hasActuals) {
                    $aStyle .= 'color:#cbd5e1;';
                }
                $aText = $hasActuals ? $money($actualVal) : '—';
                return '<td style="' . $bStyle . '">' . $money($budgetVal) . '</td>'
                     . '<td style="' . $aStyle . '">' . $aText . '</td>';
            };
        @endphp

        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Weekly cash flow — budget vs actual</h3>
                <span class="pull-right text-muted" style="font-size:12px;">
                    @if($hasActuals)Actual from QuickBooks.@else<em>Actuals unavailable — QuickBooks not connected.</em>@endif
                </span>
            </div>
            <div class="box-body table-responsive">
                <table class="table table-bordered" style="font-size:12px; white-space:nowrap;">
                    <thead>
                        <tr style="background:#f1f5f9;">
                            <th rowspan="2" style="position:sticky; left:0; background:#f1f5f9; min-width:200px; vertical-align:middle;">Line item</th>
                            @foreach($weeks as $w)
                                <th colspan="2" class="text-center" style="{{ $bDivider }} min-width:140px;">
                                    {{ $w['label'] }}
                                    <br><small class="text-muted">{{ $w['range'] }}</small>
                                </th>
                            @endforeach
                        </tr>
                        <tr style="background:#f1f5f9;">
                            @foreach($weeks as $w)
                                <th class="text-right" style="{{ $bDivider }} color:#64748b; font-weight:600;">Budget</th>
                                <th class="text-right" style="font-weight:600;">Actual</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        {{-- Opening balance --}}
                        <tr style="background:#ecfeff; font-weight:700;">
                            <td style="position:sticky; left:0; background:#ecfeff;">Money at the beginning</td>
                            @for($i = 0; $i < $weekCount; $i++)
                                {!! $pair($grid['opening_budget'][$i], $grid['opening_actual'][$i], true) !!}
                            @endfor
                        </tr>

                        {{-- Sections --}}
                        @foreach($grid['sections'] as $sec)
                            <tr style="background:#f8fafc;">
                                <td colspan="{{ $weekCount * 2 + 1 }}" style="position:sticky; left:0; background:#f8fafc; font-weight:700; letter-spacing:.5px; color:#475569;">
                                    {{ $sec['title'] }}
                                </td>
                            </tr>
                            @foreach($sec['items'] as $item)
                                <tr @if(!empty($item['is_unmapped'])) style="font-style:italic; color:#92400e;" @endif>
                                    <td style="position:sticky; left:0; background:#fff; padding-left:24px;">{{ $item['label'] }}</td>
                                    @for($i = 0; $i < $weekCount; $i++)
                                        {!! $pair($item['budget'][$i], $item['actual'][$i]) !!}
                                    @endfor
                                </tr>
                            @endforeach
                            <tr style="font-weight:700; background:#f1f5f9;">
                                <td style="position:sticky; left:0; background:#f1f5f9; padding-left:24px;">Total {{ ucfirst(strtolower($sec['title'])) }}</td>
                                @for($i = 0; $i < $weekCount; $i++)
                                    {!! $pair($sec['subtotal_budget'][$i], $sec['subtotal_actual'][$i], false, false) !!}
                                @endfor
                            </tr>
                        @endforeach

                        {{-- Net cash flow --}}
                        <tr style="font-weight:700; background:#fffbeb;">
                            <td style="position:sticky; left:0; background:#fffbeb;">Net cash flow</td>
                            @for($i = 0; $i < $weekCount; $i++)
                                {!! $pair($grid['net_budget'][$i], $grid['net_actual'][$i], true) !!}
                            @endfor
                        </tr>

                        {{-- Closing balance --}}
                        <tr style="font-weight:700; background:#ecfeff;">
                            <td style="position:sticky; left:0; background:#ecfeff;">Money at the end</td>
                            @for($i = 0; $i < $weekCount; $i++)
                                {!! $pair($grid['closing_budget'][$i], $grid['closing_actual'][$i], true) !!}
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
