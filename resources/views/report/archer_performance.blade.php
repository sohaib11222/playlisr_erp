@extends('layouts.app')
@section('title', 'Archer x Nivessa Performance')

@section('content')
<section class="content-header">
    <h1>Archer x Nivessa Performance <small>IG growth + order impact since his campaign started</small></h1>
</section>

<section class="content">

    @if(empty($data))
        <div class="alert alert-warning">
            No snapshot data found at <code>storage/app/archer-performance/data.json</code>.
        </div>
    @else
        <div class="alert alert-info">
            <i class="fa fa-info-circle"></i>
            Manual snapshot, not a live feed — pulled from Instagram Business Suite and the nivessa.com
            orders admin on <strong>{{ $data['last_updated'] }}</strong>. Refresh by re-checking those two
            sources and updating the JSON file.
        </div>

        <div class="row">
            <div class="col-md-3">
                <div class="info-box bg-purple">
                    <span class="info-box-icon"><i class="fa fa-instagram"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">IG followers</span>
                        <span class="info-box-number">{{ number_format($data['instagram']['followers_now']) }}</span>
                        <span class="progress-description">
                            from {{ number_format($data['instagram']['followers_start']) }} on {{ $data['contract']['start_date'] }}
                            (+{{ number_format($data['instagram']['followers_now'] - $data['instagram']['followers_start']) }})
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-blue">
                    <span class="info-box-icon"><i class="fa fa-eye"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">IG reach (28 days)</span>
                        <span class="info-box-number">{{ number_format($data['instagram']['reach_last_28_days']) }}</span>
                        <span class="progress-description">
                            {{ $data['instagram']['reach_change_pct'] }}% vs prior 28 days — cooling off, watch this
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-green">
                    <span class="info-box-icon"><i class="fa fa-shopping-cart"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Orders (28 days)</span>
                        <span class="info-box-number">{{ number_format($data['orders']['archer_period']['order_count']) }}</span>
                        <span class="progress-description">
                            vs {{ number_format($data['orders']['baseline_period']['order_count']) }} the 28 days before
                            (+{{ number_format((($data['orders']['archer_period']['order_count'] / $data['orders']['baseline_period']['order_count']) - 1) * 100) }}%)
                        </span>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="info-box bg-aqua">
                    <span class="info-box-icon"><i class="fa fa-dollar-sign"></i></span>
                    <div class="info-box-content">
                        <span class="info-box-text">Net revenue (28 days)</span>
                        <span class="info-box-number">${{ number_format($data['orders']['archer_period']['net_revenue_excl_cancelled'], 0) }}</span>
                        <span class="progress-description">
                            vs ${{ number_format($data['orders']['baseline_period']['net_revenue_excl_cancelled'], 0) }} the 28 days before
                            (+{{ number_format((($data['orders']['archer_period']['net_revenue_excl_cancelled'] / $data['orders']['baseline_period']['net_revenue_excl_cancelled']) - 1) * 100) }}%)
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Orders: before vs during the campaign</h3>
            </div>
            <div class="box-body table-responsive">
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Period</th>
                            <th>Dates</th>
                            <th class="text-right">Orders</th>
                            <th class="text-right">Gross revenue</th>
                            <th class="text-right">Net revenue (excl. cancelled)</th>
                            <th class="text-right">Cancelled</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>{{ $data['orders']['baseline_period']['label'] }}</td>
                            <td>{{ $data['orders']['baseline_period']['start_date'] }} &rarr; {{ $data['orders']['baseline_period']['end_date'] }}</td>
                            <td class="text-right">{{ number_format($data['orders']['baseline_period']['order_count']) }}</td>
                            <td class="text-right">${{ number_format($data['orders']['baseline_period']['gross_revenue'], 2) }}</td>
                            <td class="text-right">${{ number_format($data['orders']['baseline_period']['net_revenue_excl_cancelled'], 2) }}</td>
                            <td class="text-right">{{ $data['orders']['baseline_period']['cancelled_count'] }}
                                ({{ number_format(($data['orders']['baseline_period']['cancelled_count'] / $data['orders']['baseline_period']['order_count']) * 100) }}%)</td>
                        </tr>
                        <tr class="active">
                            <td><strong>{{ $data['orders']['archer_period']['label'] }}</strong></td>
                            <td>{{ $data['orders']['archer_period']['start_date'] }} &rarr; {{ $data['orders']['archer_period']['end_date'] }}</td>
                            <td class="text-right"><strong>{{ number_format($data['orders']['archer_period']['order_count']) }}</strong></td>
                            <td class="text-right"><strong>${{ number_format($data['orders']['archer_period']['gross_revenue'], 2) }}</strong></td>
                            <td class="text-right"><strong>${{ number_format($data['orders']['archer_period']['net_revenue_excl_cancelled'], 2) }}</strong></td>
                            <td class="text-right">{{ $data['orders']['archer_period']['cancelled_count'] }}
                                ({{ number_format(($data['orders']['archer_period']['cancelled_count'] / $data['orders']['archer_period']['order_count']) * 100) }}%)</td>
                        </tr>
                    </tbody>
                </table>
                <p class="text-muted" style="margin-top:10px;">
                    <i class="fa fa-lightbulb-o"></i> {{ $data['orders']['note'] }}
                </p>
            </div>
        </div>

        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">Contract terms</h3>
            </div>
            <div class="box-body">
                <table class="table">
                    <tr>
                        <th style="width:220px;">Term</th>
                        <td>{{ $data['contract']['start_date'] }} &rarr; {{ $data['contract']['end_date'] }}</td>
                    </tr>
                    <tr>
                        <th>Pay</th>
                        <td>${{ number_format($data['contract']['pay_total']) }} total — {{ $data['contract']['pay_schedule'] }}</td>
                    </tr>
                    <tr>
                        <th>Follower goal</th>
                        <td>
                            {{ number_format($data['contract']['follower_goal']) }} by {{ $data['contract']['follower_goal_date'] }}
                            for a ${{ number_format($data['contract']['bonus_at_goal']) }} bonus —
                            currently at {{ number_format($data['instagram']['followers_now']) }}, unlikely to hit it this term
                        </td>
                    </tr>
                    <tr>
                        <th>Confirmed campaign videos</th>
                        <td>{{ $data['instagram']['confirmed_collab_videos'] }} (verified as genuinely tagging @nivessarecords, out of everything checked on Instagram)</td>
                    </tr>
                </table>
            </div>
        </div>
    @endif

</section>
@endsection
