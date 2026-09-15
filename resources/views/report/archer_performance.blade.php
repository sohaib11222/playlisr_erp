@extends('layouts.app')
@section('title', 'Archer x Nivessa Performance')

@php
    if (!function_exists('archerFmtDate')) {
        function archerFmtDate($d) { return \Carbon::parse($d)->format('m/d/y'); }
    }
@endphp

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
        <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:16px 20px; margin-bottom:20px;">
            <form method="GET" style="display:flex; align-items:flex-end; gap:16px; flex-wrap:wrap;">
                <div>
                    <label style="display:block; font-size:12px; color:#999; margin-bottom:4px;">From</label>
                    <input type="date" name="start_date" class="form-control" value="{{ $start_date }}">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#999; margin-bottom:4px;">To</label>
                    <input type="date" name="end_date" class="form-control" value="{{ $end_date }}">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fa fa-filter"></i> Apply</button>
                <a href="{{ action('ReportController@archerPerformance') }}" class="btn btn-default">Reset to campaign start &rarr; today</a>
            </form>
        </div>

        {{-- ───────── LIVE: selected date range ───────── --}}
        <h4 style="margin-top:0;">Website orders, {{ archerFmtDate($start_date) }} &ndash; {{ archerFmtDate($end_date) }}</h4>

        @if($live_orders_error)
            <div class="alert alert-warning">Couldn't reach the website API: {{ $live_orders_error }}</div>
        @else
            <div class="row">
                <div class="col-sm-4">
                    <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:24px; text-align:center;">
                        <div style="color:#999; font-size:13px; text-transform:uppercase; letter-spacing:1px; margin-bottom:10px;">Orders placed</div>
                        <div style="font-size:38px; font-weight:700; color:#333;">{{ number_format($live_orders['count'] ?? 0) }}</div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:24px; text-align:center;">
                        <div style="color:#999; font-size:13px; text-transform:uppercase; letter-spacing:1px; margin-bottom:10px;">Orders fulfilled (shipped)</div>
                        <div style="font-size:38px; font-weight:700; color:#333;">{{ number_format($live_fulfillment['shipped'] ?? 0) }}</div>
                        <div style="color:#999; font-size:13px; margin-top:6px;">+{{ number_format($live_fulfillment['picked_or_packed'] ?? 0) }} picked/packed, not yet shipped</div>
                    </div>
                </div>
                <div class="col-sm-4">
                    <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:24px; text-align:center;">
                        <div style="color:#999; font-size:13px; text-transform:uppercase; letter-spacing:1px; margin-bottom:10px;">Revenue</div>
                        <div style="font-size:38px; font-weight:700; color:#333;">${{ number_format($live_orders['revenue'] ?? 0) }}</div>
                    </div>
                </div>
            </div>
        @endif

        {{-- ───────── Cost vs. sales ───────── --}}
        <h4>Expense vs. sales</h4>
        <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:20px; margin-bottom:24px;">
            @php
                $revenue_in_range = $live_orders['revenue'] ?? null;
                $ratio = ($revenue_in_range !== null && $data['contract']['pay_total'] > 0)
                    ? $revenue_in_range / $data['contract']['pay_total'] : null;
            @endphp
            <table class="table" style="margin-bottom:0;">
                <tr>
                    <th style="width:220px;">Paying him (total contract)</th>
                    <td>${{ number_format($data['contract']['pay_total']) }}</td>
                </tr>
                <tr>
                    <th>Website revenue, {{ archerFmtDate($start_date) }}&ndash;{{ archerFmtDate($end_date) }}</th>
                    <td>{{ $revenue_in_range !== null ? '$' . number_format($revenue_in_range) : 'unavailable' }}</td>
                </tr>
                <tr>
                    <th>Return</th>
                    <td>
                        @if($ratio !== null)
                            <strong>${{ number_format($ratio, 2) }}</strong> in website revenue for every $1 paid
                        @else
                            &mdash;
                        @endif
                    </td>
                </tr>
            </table>
            <p class="text-muted" style="margin-top:12px; margin-bottom:0; font-size:13px;">
                This only counts revenue we can trace to the website in this window &mdash; it doesn't include
                in-store sales from people who saw his videos, or the value of the show/celebrity traffic itself.
            </p>
        </div>

        {{-- ───────── Coupon code usage ───────── --}}
        <h4>Discount code usage</h4>
        <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:20px; margin-bottom:24px;">
            @if($archer_coupon)
                <div style="font-size:28px; font-weight:700; color:#333;">{{ number_format($archer_coupon->times_used) }} uses</div>
                <div class="text-muted" style="margin-top:4px;">
                    Code <strong>{{ $archer_coupon->code }}</strong> &middot; all-time total, not scoped to the date range above &mdash;
                    this system doesn't log a timestamp per redemption, just a running count.
                </div>
            @else
                <div class="alert alert-warning" style="margin-bottom:0;">
                    No coupon code with "archer" in it exists yet. If you want to track this going forward,
                    create one at <a href="{{ route('coupons.index') }}">Coupons</a> first.
                </div>
            @endif
        </div>

        {{-- ───────── Instagram (manual snapshot) ───────── --}}
        <h4>Instagram (manual snapshot from {{ archerFmtDate($data['last_updated']) }})</h4>
        <div class="row">
            <div class="col-sm-6">
                <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:24px; text-align:center;">
                    <div style="color:#999; font-size:13px; text-transform:uppercase; letter-spacing:1px; margin-bottom:10px;">Followers</div>
                    <div style="font-size:38px; font-weight:700; color:#333;">
                        {{ number_format($data['instagram']['followers_start'] / 1000, 1) }}K
                        <span style="color:#ccc; font-weight:400;">&rarr;</span>
                        {{ number_format($data['instagram']['followers_now'] / 1000, 1) }}K
                    </div>
                    <div style="color:#2ecc71; font-size:15px; font-weight:600; margin-top:6px;">
                        +{{ number_format($data['instagram']['followers_now'] - $data['instagram']['followers_start']) }} since {{ archerFmtDate($data['contract']['start_date']) }}
                    </div>
                </div>
            </div>
            <div class="col-sm-6">
                <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:24px; text-align:center;">
                    <div style="color:#999; font-size:13px; text-transform:uppercase; letter-spacing:1px; margin-bottom:10px;">Confirmed campaign videos</div>
                    <div style="font-size:38px; font-weight:700; color:#333;">{{ $data['instagram']['confirmed_collab_videos'] }}</div>
                    <div style="color:#999; font-size:13px; margin-top:6px;">verified as genuinely tagging @nivessarecords</div>
                </div>
            </div>
        </div>

        <p class="text-muted" style="margin-top:16px; font-size:13px;">
            Instagram numbers aren't live &mdash; pulled by hand from Instagram Business Suite. Refresh by
            re-checking there and updating this page's code.
        </p>

        {{-- ───────── Contract ───────── --}}
        <h4>Contract</h4>
        <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:20px;">
            <strong>{{ archerFmtDate($data['contract']['start_date']) }} to {{ archerFmtDate($data['contract']['end_date']) }}</strong> &middot;
            ${{ number_format($data['contract']['pay_total']) }} total pay &middot;
            ${{ number_format($data['contract']['bonus_at_goal']) }} bonus if he hits {{ number_format($data['contract']['follower_goal']) }} followers
            by {{ archerFmtDate($data['contract']['follower_goal_date']) }}
            (he's at {{ number_format($data['instagram']['followers_now']) }} now &mdash; won't hit it this term).
        </div>
    @endif

</section>
@endsection
