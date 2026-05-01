@extends('layouts.app')
@section('title', $channel_name . ' Sales')

@section('content')
<section class="content-header">
    <h1>{{ $channel_name }} Sales <small>revenue, profit &amp; top items</small></h1>
</section>

<section class="content">

    <div class="row">
        <div class="col-md-3">
            <div class="info-box bg-blue">
                <span class="info-box-icon"><i class="fa fa-dollar-sign"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Revenue</span>
                    <span class="info-box-number">${{ number_format($revenue, 2) }}</span>
                    <span class="progress-description">{{ (int)$cnt }} transactions</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-green">
                <span class="info-box-icon"><i class="fa fa-chart-line"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Gross profit</span>
                    <span class="info-box-number">${{ number_format($gross_profit, 2) }}</span>
                    <span class="progress-description">{{ number_format($gross_margin, 1) }}% gross margin</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-purple">
                <span class="info-box-icon"><i class="fa fa-calendar"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Period</span>
                    <span class="info-box-number" style="font-size:18px;">{{ $start_date }}</span>
                    <span class="progress-description">to {{ $end_date }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-aqua">
                <span class="info-box-icon"><i class="fa fa-trophy"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Top item</span>
                    <span class="info-box-number" style="font-size:14px; line-height:1.2;">
                        {{ $top_items[0]->product_name ?? '—' }}
                    </span>
                    <span class="progress-description">
                        @if(isset($top_items[0]))
                            ${{ number_format($top_items[0]->revenue, 0) }} ({{ (int)$top_items[0]->qty }} qty)
                        @endif
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Filters</h3></div>
        <div class="box-body">
            <form method="GET" action="{{ action($action) }}" class="row">
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
                    <a class="btn btn-default" href="{{ action($action, ['start_date' => $start_date, 'end_date' => $end_date, 'export' => 'csv']) }}">
                        <i class="fa fa-download"></i> Download CSV
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-md-5">
            <div class="box box-solid">
                <div class="box-header with-border"><h3 class="box-title">Daily breakdown</h3></div>
                <div class="box-body table-responsive" style="max-height:520px; overflow-y:auto;">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th class="text-right">Txns</th>
                                <th class="text-right">Revenue</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($daily as $d)
                                <tr>
                                    <td>{{ $d->day }}</td>
                                    <td class="text-right">{{ (int)$d->cnt }}</td>
                                    <td class="text-right">${{ number_format($d->revenue, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted">No {{ $channel_name }} sales in this period.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-7">
            <div class="box box-solid">
                <div class="box-header with-border"><h3 class="box-title">Top items (up to 50)</h3></div>
                <div class="box-body table-responsive" style="max-height:520px; overflow-y:auto;">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>SKU</th>
                                <th>Product</th>
                                <th class="text-right">Qty</th>
                                <th class="text-right">Revenue</th>
                                <th class="text-right">Gross Profit</th>
                                <th class="text-right">Margin</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($top_items as $it)
                                @php $margin = $it->revenue > 0 ? ($it->gross_profit / $it->revenue) * 100 : 0; @endphp
                                <tr>
                                    <td><small>{{ $it->sku }}</small></td>
                                    <td>{{ $it->product_name }}</td>
                                    <td class="text-right">{{ (int)$it->qty }}</td>
                                    <td class="text-right">${{ number_format($it->revenue, 2) }}</td>
                                    <td class="text-right">${{ number_format($it->gross_profit, 2) }}</td>
                                    <td class="text-right">{{ number_format($margin, 1) }}%</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted">No items sold via {{ $channel_name }} in this period.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</section>
@stop
