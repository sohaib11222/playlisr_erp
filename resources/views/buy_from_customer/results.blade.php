@extends('layouts.app')
@section('title', 'Buy Results')

@php
    $money = function ($v) { return is_null($v) ? '—' : '$' . number_format($v, 2); };
    $pct = function ($v) { return is_null($v) ? '—' : round($v * 100) . '%'; };
    $num = function ($v, $d = 0) { return is_null($v) ? '—' : number_format($v, $d); };
    $cov = $data['coverage'];
    $followedPct = $cov['units'] > 0 ? $cov['followed_units'] / $cov['units'] : null;
    $listedPct = $cov['units'] > 0 ? $cov['listed_units'] / $cov['units'] : null;
@endphp

@section('content')
<section class="content-header">
    <h1>Buy from Customer <small>Results</small></h1>
</section>

<section class="content">
    <div class="box box-solid">
        <div class="box-body">
            <form method="GET" action="{{ route('buy-from-customer.results') }}" class="form-inline">
                <div class="form-group" style="margin-right:10px;">
                    <label>Accepted from</label>
                    <input type="date" name="start" value="{{ $start }}" class="form-control input-sm">
                </div>
                <div class="form-group" style="margin-right:10px;">
                    <label>to</label>
                    <input type="date" name="end" value="{{ $end }}" class="form-control input-sm">
                </div>
                <div class="form-group" style="margin-right:10px;">
                    <label>Store</label>
                    <select name="location_id" class="form-control input-sm">
                        <option value="">All stores</option>
                        @foreach($locations as $id => $name)
                            <option value="{{ $id }}" @if((string) $location_id === (string) $id) selected @endif>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group" style="margin-right:10px;">
                    <label>Group by</label>
                    <select name="group_by" class="form-control input-sm">
                        @foreach($group_options as $key => $label)
                            <option value="{{ $key }}" @if($data['group_by'] === $key) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Update</button>
                <a href="{{ route('buy-from-customer.history') }}" class="btn btn-default btn-sm">Back to history</a>
            </form>
        </div>
    </div>

    <div class="alert alert-{{ ($followedPct ?? 0) >= 0.8 ? 'info' : 'warning' }}">
        <strong>What this can see:</strong>
        {{ $num($cov['units']) }} units bought in accepted offers.
        {{ $num($cov['listed_units']) }} ({{ $pct($listedPct) }}) have been listed on Mass Add with their buy record #,
        and {{ $num($cov['followed_units']) }} ({{ $pct($followedPct) }}) are on lots the report can follow.
        Only lots with something listed (or entered individually on the buy form) show up below;
        items listed without a buy record # can't be traced back to what we paid.
    </div>

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">Paid vs. sold — by {{ strtolower($group_options[$data['group_by']]) }}</h3>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped table-condensed">
                <thead>
                    <tr>
                        <th>{{ $group_options[$data['group_by']] }}</th>
                        <th class="text-right">Units bought</th>
                        <th class="text-right" title="Listed on Mass Add with this buy's record #">Listed</th>
                        <th class="text-right">Paid</th>
                        <th class="text-right">Avg paid / unit</th>
                        <th class="text-right">Units sold</th>
                        <th class="text-right">Sell-through</th>
                        <th class="text-right">Revenue</th>
                        <th class="text-right">Avg sale / unit</th>
                        <th class="text-right" title="Cost of the units that sold ÷ what they sold for">Paid % of sale</th>
                        <th class="text-right" title="Revenue from sold units − what we paid for those units">Profit on sold</th>
                        <th class="text-right" title="Revenue so far ÷ everything paid in this group, sold or not">$ back per $1 paid</th>
                        <th class="text-right">Avg days to sell</th>
                        <th class="text-right" title="Average days since purchase for units not yet sold">Unsold avg age (days)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($data['groups'] as $g)
                        <tr>
                            <td>{{ $g['label'] }}</td>
                            <td class="text-right">{{ $num($g['units']) }}</td>
                            <td class="text-right">{{ $num($g['listed']) }}</td>
                            <td class="text-right">{{ $money($g['paid']) }}</td>
                            <td class="text-right">{{ $money($g['avg_paid']) }}</td>
                            <td class="text-right">{{ $num($g['sold_units']) }}</td>
                            <td class="text-right">{{ $pct($g['sell_through']) }}</td>
                            <td class="text-right">{{ $money($g['revenue']) }}</td>
                            <td class="text-right">{{ $money($g['avg_sale']) }}</td>
                            <td class="text-right">{{ $pct($g['paid_pct_of_sale']) }}</td>
                            <td class="text-right">{{ $money($g['profit_on_sold']) }}</td>
                            <td class="text-right">{{ is_null($g['return_per_dollar']) ? '—' : '$' . number_format($g['return_per_dollar'], 2) }}</td>
                            <td class="text-right">{{ $num($g['avg_days_to_sell']) }}</td>
                            <td class="text-right">{{ $num($g['unsold_avg_age']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="14" class="text-center text-muted">No accepted buys with inventory in this range.</td></tr>
                    @endforelse
                </tbody>
                @if(!empty($data['groups']))
                    @php $t = $data['totals']; @endphp
                    <tfoot>
                        <tr style="font-weight:bold;">
                            <td>Total</td>
                            <td class="text-right">{{ $num($t['units']) }}</td>
                            <td class="text-right">{{ $num($t['listed']) }}</td>
                            <td class="text-right">{{ $money($t['paid']) }}</td>
                            <td class="text-right">{{ $money($t['avg_paid']) }}</td>
                            <td class="text-right">{{ $num($t['sold_units']) }}</td>
                            <td class="text-right">{{ $pct($t['sell_through']) }}</td>
                            <td class="text-right">{{ $money($t['revenue']) }}</td>
                            <td class="text-right">{{ $money($t['avg_sale']) }}</td>
                            <td class="text-right">{{ $pct($t['paid_pct_of_sale']) }}</td>
                            <td class="text-right">{{ $money($t['profit_on_sold']) }}</td>
                            <td class="text-right">{{ is_null($t['return_per_dollar']) ? '—' : '$' . number_format($t['return_per_dollar'], 2) }}</td>
                            <td class="text-right">{{ $num($t['avg_days_to_sell']) }}</td>
                            <td class="text-right">{{ $num($t['unsold_avg_age']) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
            <p class="text-muted small">
                Revenue is after discounts and before tax, net of returns. "Paid" is what the negotiated payout
                allocated to each line (store-credit buys are recorded at the credit amount).
                "Paid % of sale" is the number the calculator's multipliers are aiming at — compare it across
                tiers and grades to see which are paying too much or too little.
            </p>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">By buyer</h3>
            <small class="text-muted" style="margin-left:8px;">Employee who wrote the buy · same dates and store as above · all amounts in cash terms</small>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped table-condensed">
                <thead>
                    <tr>
                        <th>Buyer</th>
                        <th class="text-right">Buys</th>
                        <th class="text-right">Items</th>
                        <th class="text-right">Paid</th>
                        <th class="text-right">Avg / buy</th>
                        <th class="text-right" title="Depends on what they buy — CD lots vs. turntables">Avg / item</th>
                        <th class="text-right" title="Final paid ÷ calculator value, on buys the calculator priced. The form's default final offer is 95%.">Paid % of calculator</th>
                        <th class="text-right" title="Buys where the final price was above the calculator value">Buys over calculator</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($data['buyers'] as $b)
                        <tr>
                            <td>{{ $b['name'] }}</td>
                            <td class="text-right">{{ $num($b['offers']) }}</td>
                            <td class="text-right">{{ $num($b['items']) }}</td>
                            <td class="text-right">{{ $money($b['paid']) }}</td>
                            <td class="text-right">{{ $money($b['avg_per_offer']) }}</td>
                            <td class="text-right">{{ $money($b['avg_per_item']) }}</td>
                            <td class="text-right">{{ $pct($b['pct_of_calc']) }} <small class="text-muted">({{ $b['priced_offers'] }} buys)</small></td>
                            <td class="text-right">{{ $b['over_calc_offers'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted">No accepted buys in this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <p class="text-muted small">
                Sorted by paid % of calculator, lowest first. That's the fair comparison: avg per item mostly
                reflects what a buyer happens to buy, not how they negotiate.
            </p>
        </div>
    </div>
</section>
@endsection
