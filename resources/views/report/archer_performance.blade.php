@extends('layouts.app')
@section('title', 'Archer x Nivessa Performance')

@section('content')
<section class="content-header">
    <h1>Archer x Nivessa Performance</h1>
</section>

<section class="content">

    @if(empty($data))
        <div class="alert alert-warning">
            No snapshot data found.
        </div>
    @else
        <p class="text-muted">
            Snapshot from {{ $data['last_updated'] }} &mdash; pulled by hand from Instagram and the
            website orders admin. Not a live feed.
        </p>

        <div class="row" style="margin-top:20px;">
            <div class="col-sm-4">
                <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:24px; text-align:center;">
                    <div style="color:#999; font-size:13px; text-transform:uppercase; letter-spacing:1px; margin-bottom:10px;">Instagram Followers</div>
                    <div style="font-size:38px; font-weight:700; color:#333;">
                        {{ number_format($data['instagram']['followers_start'] / 1000, 1) }}K
                        <span style="color:#ccc; font-weight:400;">&rarr;</span>
                        {{ number_format($data['instagram']['followers_now'] / 1000, 1) }}K
                    </div>
                    <div style="color:#2ecc71; font-size:15px; font-weight:600; margin-top:6px;">
                        +{{ number_format($data['instagram']['followers_now'] - $data['instagram']['followers_start']) }} followers
                    </div>
                </div>
            </div>
            <div class="col-sm-4">
                <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:24px; text-align:center;">
                    <div style="color:#999; font-size:13px; text-transform:uppercase; letter-spacing:1px; margin-bottom:10px;">Website Orders (28 days)</div>
                    <div style="font-size:38px; font-weight:700; color:#333;">
                        {{ $data['orders']['baseline_period']['order_count'] }}
                        <span style="color:#ccc; font-weight:400;">&rarr;</span>
                        {{ $data['orders']['archer_period']['order_count'] }}
                    </div>
                    <div style="color:#2ecc71; font-size:15px; font-weight:600; margin-top:6px;">
                        +{{ number_format((($data['orders']['archer_period']['order_count'] / $data['orders']['baseline_period']['order_count']) - 1) * 100) }}% more orders
                    </div>
                </div>
            </div>
            <div class="col-sm-4">
                <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:24px; text-align:center;">
                    <div style="color:#999; font-size:13px; text-transform:uppercase; letter-spacing:1px; margin-bottom:10px;">Revenue (28 days)</div>
                    <div style="font-size:38px; font-weight:700; color:#333;">
                        ${{ number_format($data['orders']['baseline_period']['net_revenue_excl_cancelled']) }}
                        <span style="color:#ccc; font-weight:400;">&rarr;</span>
                        ${{ number_format($data['orders']['archer_period']['net_revenue_excl_cancelled']) }}
                    </div>
                    <div style="color:#2ecc71; font-size:15px; font-weight:600; margin-top:6px;">
                        +{{ number_format((($data['orders']['archer_period']['net_revenue_excl_cancelled'] / $data['orders']['baseline_period']['net_revenue_excl_cancelled']) - 1) * 100) }}% more revenue
                    </div>
                </div>
            </div>
        </div>

        <p class="text-muted" style="margin-top:16px; font-size:13px;">
            "28 days before" = {{ $data['orders']['baseline_period']['start_date'] }} to {{ $data['orders']['baseline_period']['end_date'] }}.
            "Since Archer" = {{ $data['orders']['archer_period']['start_date'] }} to {{ $data['orders']['archer_period']['end_date'] }} (his campaign started 8/18).
        </p>

        <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:20px; margin-top:10px;">
            <strong>Contract:</strong>
            {{ $data['contract']['start_date'] }} to {{ $data['contract']['end_date'] }} &middot;
            ${{ number_format($data['contract']['pay_total']) }} total pay &middot;
            ${{ number_format($data['contract']['bonus_at_goal']) }} bonus if he hits {{ number_format($data['contract']['follower_goal']) }} followers
            (he's at {{ number_format($data['instagram']['followers_now']) }} now &mdash; won't hit it this term).
        </div>
    @endif

</section>
@endsection
