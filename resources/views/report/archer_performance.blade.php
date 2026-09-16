@extends('layouts.app')
@section('title', 'Archer x Nivessa Performance')

@php
    if (!function_exists('archerFmtDate')) {
        function archerFmtDate($d) { return \Carbon::parse($d)->format('m/d/y'); }
    }
    if (!function_exists('archerCard')) {
        function archerCard($label, $value, $sub = null, $subColor = '#999') {
            $html = '<div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:20px; text-align:center;">';
            $html .= '<div style="color:#999; font-size:12px; text-transform:uppercase; letter-spacing:1px; margin-bottom:8px;">' . e($label) . '</div>';
            $html .= '<div style="font-size:32px; font-weight:700; color:#333;">' . $value . '</div>';
            if ($sub) {
                $html .= '<div style="color:' . $subColor . '; font-size:13px; font-weight:600; margin-top:6px;">' . $sub . '</div>';
            }
            $html .= '</div>';
            return $html;
        }
    }
@endphp

@section('content')
<section class="content-header">
    <h1>Archer x Nivessa Performance</h1>
</section>

<section class="content">

    @if(empty($data))
        <div class="alert alert-warning">No snapshot data found.</div>
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
            <p class="text-muted" style="margin:10px 0 0; font-size:12px;">
                The website orders section below moves with this date range. Social follower counts are point-in-time
                snapshots (start of campaign vs. today), not day-by-day, so they don't change with the filter.
            </p>
        </div>

        {{-- ═══════════ INSTAGRAM ═══════════ --}}
        <h4 style="margin-top:0;">Instagram</h4>
        <div class="row">
            <div class="col-sm-4">
                {!! archerCard(
                    'Followers',
                    number_format($data['instagram']['followers_start'] / 1000, 1) . 'K &rarr; ' . number_format($data['instagram']['followers_now'] / 1000, 1) . 'K',
                    '+' . number_format($data['instagram']['followers_now'] - $data['instagram']['followers_start']) . ' since ' . archerFmtDate($data['contract']['start_date']),
                    '#2ecc71'
                ) !!}
            </div>
            <div class="col-sm-4">
                {!! archerCard('Reach, last 28 days', number_format($data['instagram']['reach_last_28_days']), $data['instagram']['reach_change_pct'] . '%', '#d9534f') !!}
            </div>
            <div class="col-sm-4">
                {!! archerCard('Confirmed campaign videos', $data['instagram']['confirmed_collab_videos'], 'verified, posted by @archerxvalentine tagging @nivessarecords') !!}
            </div>
        </div>

        {{-- ═══════════ TIKTOK ═══════════ --}}
        <h4 style="margin-top:24px;">TikTok</h4>
        <div class="row">
            <div class="col-sm-4">
                {!! archerCard(
                    'Followers',
                    number_format($data['tiktok']['followers_start']) . ' &rarr; ' . number_format($data['tiktok']['followers_now']),
                    '+' . number_format($data['tiktok']['followers_now'] - $data['tiktok']['followers_start']) . ' since ' . archerFmtDate($data['contract']['start_date']),
                    '#2ecc71'
                ) !!}
            </div>
            <div class="col-sm-4">
                {!! archerCard('Total likes', number_format($data['tiktok']['total_likes'])) !!}
            </div>
        </div>

        @if(!empty($data['tiktok']['weekly_followers']))
            <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:16px 20px; margin-top:12px;">
                <div style="font-size:12px; color:#999; text-transform:uppercase; letter-spacing:1px; margin-bottom:10px;">Week over week</div>
                <table class="table" style="margin-bottom:0;">
                    <thead>
                        <tr><th>Week of</th><th class="text-right">Followers</th><th class="text-right">Change</th></tr>
                    </thead>
                    <tbody>
                        @php $prevWeek = null; @endphp
                        @foreach($data['tiktok']['weekly_followers'] as $week)
                            <tr>
                                <td>{{ archerFmtDate($week['date']) }}</td>
                                <td class="text-right">{{ number_format($week['followers']) }}</td>
                                <td class="text-right" style="color:#2ecc71;">
                                    {{ $prevWeek !== null ? '+' . number_format($week['followers'] - $prevWeek) : '—' }}
                                </td>
                            </tr>
                            @php $prevWeek = $week['followers']; @endphp
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- ═══════════ FACEBOOK ═══════════ --}}
        <h4 style="margin-top:24px;">Facebook</h4>
        <div class="row">
            <div class="col-sm-4">
                {!! archerCard(
                    'Followers',
                    number_format($data['facebook']['followers_start']) . ' &rarr; ' . number_format($data['facebook']['followers_now']),
                    '+' . number_format($data['facebook']['followers_now'] - $data['facebook']['followers_start']) . ' since ' . archerFmtDate($data['facebook']['followers_start_asof']),
                    '#2ecc71'
                ) !!}
            </div>
            <div class="col-sm-4">
                {!! archerCard('Reach, last 28 days', number_format($data['facebook']['reach_last_28_days']), $data['facebook']['reach_change_pct'] . '%', '#d9534f') !!}
            </div>
        </div>
        <p class="text-muted" style="margin-top:10px; font-size:12px;">
            Instagram, TikTok and Facebook followers pulled by hand from Meta Business Suite / TikTok Studio on
            {{ archerFmtDate($data['last_updated']) }}. Facebook's start figure is back-calculated (Meta doesn't expose
            a followers-on-a-date lookup) from {{ archerFmtDate($data['facebook']['followers_start_asof']) }}, the
            closest available date &mdash; TikTok's start figure is exact, read directly off its followers chart for
            {{ archerFmtDate($data['contract']['start_date']) }}. TikTok is the only platform with a real week-over-week
            table above &mdash; Instagram and Facebook's own dashboards only expose a start/now snapshot here, not a
            reliable weekly history, so no week-by-week numbers are shown for them rather than estimating.
        </p>

        {{-- ═══════════ WEBSITE ORDERS ═══════════ --}}
        <h4 style="margin-top:32px;">All website orders, {{ archerFmtDate($start_date) }} &ndash; {{ archerFmtDate($end_date) }}</h4>
        <p class="text-muted" style="margin-top:-8px; font-size:12px;">Site-wide totals, for context &mdash; not Archer-specific. See "Discount code usage" below for what's actually attributable to him.</p>

        @if($order_stats_error)
            <div class="alert alert-warning">Couldn't reach the website API: {{ $order_stats_error }}</div>
        @elseif($order_stats)
            <div class="row">
                <div class="col-sm-3">{!! archerCard('Orders placed', number_format($order_stats['orders_placed'])) !!}</div>
                <div class="col-sm-3">{!! archerCard('Fulfilled', number_format($order_stats['orders_fulfilled']), $order_stats['orders_in_progress'] . ' still in progress') !!}</div>
                <div class="col-sm-3">
                    @php
                        $cancel_pct = $order_stats['orders_placed'] > 0
                            ? round(($order_stats['orders_cancelled'] / $order_stats['orders_placed']) * 100)
                            : 0;
                    @endphp
                    {!! archerCard(
                        'Cancelled',
                        number_format($order_stats['orders_cancelled']) . ' (' . $cancel_pct . '%)',
                        number_format($order_stats['orders_cancelled_discogs']) . ' sold on Discogs (unrelated)<br>' . number_format($order_stats['orders_cancelled_other']) . ' other inventory issue',
                        '#d9534f'
                    ) !!}
                </div>
                <div class="col-sm-3">{!! archerCard('Net revenue realized', '$' . number_format($order_stats['net_revenue'])) !!}</div>
            </div>

            <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:20px; margin-top:16px;">
                <table class="table" style="margin-bottom:0;">
                    <tr>
                        <th style="width:260px;">Potential revenue (if nothing cancelled)</th>
                        <td>${{ number_format($order_stats['gross_revenue'], 2) }}</td>
                    </tr>
                    <tr>
                        <th>Lost &mdash; sold on Discogs (unrelated to Archer)</th>
                        <td style="color:#d9534f;">&minus;${{ number_format($order_stats['cancelled_discogs_revenue'], 2) }}</td>
                    </tr>
                    <tr>
                        <th>Lost &mdash; other inventory issue</th>
                        <td style="color:#d9534f;">&minus;${{ number_format($order_stats['cancelled_other_revenue'], 2) }}</td>
                    </tr>
                    <tr style="border-top:2px solid #eee;">
                        <th>Actual revenue realized</th>
                        <td><strong>${{ number_format($order_stats['net_revenue'], 2) }}</strong></td>
                    </tr>
                </table>
            </div>
        @endif

        {{-- ═══════════ ROI ═══════════ --}}
        <h4>ROI</h4>
        <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:24px; margin-bottom:24px;">
            @if($order_stats)
                @php $ratio = $order_stats['net_revenue'] / $data['contract']['pay_total']; @endphp
                <div style="font-size:20px; text-align:center; margin-bottom:8px;">
                    Paid <strong>${{ number_format($data['contract']['pay_total']) }}</strong>
                    &rarr; got back <strong>${{ number_format($order_stats['net_revenue']) }}</strong> in trackable website revenue
                </div>
                <div style="font-size:36px; font-weight:700; text-align:center; color:{{ $ratio >= 1 ? '#2ecc71' : '#d9534f' }};">
                    ${{ number_format($ratio, 2) }} back per $1 spent
                </div>
            @endif
            <p class="text-muted" style="margin-top:16px; margin-bottom:0; font-size:13px;">
                Website revenue only, net of cancellations &mdash; a floor, not the whole picture.
            </p>
        </div>

        {{-- ═══════════ COUPON CODE — REAL ATTRIBUTED CONVERSIONS ═══════════ --}}
        <h4>Discount code usage</h4>
        <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:20px; margin-bottom:24px;">
            @if($archer_coupon)
                <div style="font-size:28px; font-weight:700; color:#333;">{{ number_format($archer_coupon->times_used) }} uses &middot; ${{ number_format($coupon_zipcodes_total, 2) }} total</div>
                <div class="text-muted" style="margin-top:4px; margin-bottom:16px;">
                    Code <strong>{{ $archer_coupon->code }}</strong> &middot; all-time, real attributed conversions.
                </div>

                @if($order_stats)
                    <div style="display:flex; gap:24px; flex-wrap:wrap; margin-bottom:16px; font-size:13px;">
                        <div>Refunded (other inventory issue): <strong style="color:#d9534f;">${{ number_format($order_stats['cancelled_other_revenue'], 2) }}</strong></div>
                        <div>Refunded (sold on Discogs, unrelated): <strong style="color:#d9534f;">${{ number_format($order_stats['cancelled_discogs_revenue'], 2) }}</strong></div>
                        <div>Net revenue: <strong style="color:#2ecc71;">${{ number_format($order_stats['net_revenue'], 2) }}</strong></div>
                    </div>
                @endif

                @if(!is_null($coupon_unique_customers))
                    <div style="display:flex; gap:24px; flex-wrap:wrap; margin-bottom:16px; font-size:13px;">
                        <div>New customers: <strong style="color:#2ecc71;">{{ number_format($coupon_new_customers) }}</strong></div>
                        <div>Repeat customers (already shopped Nivessa before): <strong>{{ number_format($coupon_repeat_customers) }}</strong></div>
                    </div>
                @endif

                @if($coupon_zipcodes_error)
                    <div class="alert alert-warning" style="margin-bottom:0;">Couldn't load zip codes: {{ $coupon_zipcodes_error }}</div>
                @elseif(count($coupon_zipcodes) > 0)
                    <table class="table table-bordered" style="margin-bottom:0;">
                        <thead>
                            <tr><th>Zip code</th><th>City</th><th>State</th><th>Date</th><th>Customer</th><th class="text-right">Order total</th></tr>
                        </thead>
                        <tbody>
                            @foreach($coupon_zipcodes as $row)
                                <tr>
                                    <td>{{ $row['zip_code'] ?? '—' }}</td>
                                    <td>{{ $row['city'] ?? '—' }}</td>
                                    <td>{{ $row['state'] ?? '—' }}</td>
                                    <td>{{ $row['order_date'] ? archerFmtDate($row['order_date']) : '—' }}</td>
                                    <td>
                                        @if(isset($row['is_repeat_customer']))
                                            {{ $row['is_repeat_customer'] ? 'Repeat' : 'New' }}
                                            @if(($row['code_uses_by_this_customer'] ?? 1) > 1)
                                                <span class="text-muted">&middot; used code {{ $row['code_uses_by_this_customer'] }}x</span>
                                            @endif
                                        @else
                                            &mdash;
                                        @endif
                                    </td>
                                    <td class="text-right">{{ $row['total'] ? '$' . number_format($row['total'], 2) : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="text-muted" style="margin-bottom:0;">No orders found using this code yet.</p>
                @endif
            @else
                <div class="alert alert-warning" style="margin-bottom:0;">
                    No coupon code with "archer" in it exists yet. Create one at
                    <a href="{{ route('coupons.index') }}">Coupons</a> to start tracking this.
                </div>
            @endif
        </div>

        {{-- ═══════════ CONTRACT ═══════════ --}}
        <h4 style="margin-top:24px;">Contract</h4>
        <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:20px;">
            <strong>{{ archerFmtDate($data['contract']['start_date']) }}&ndash;{{ archerFmtDate($data['contract']['end_date']) }}</strong> &middot;
            ${{ number_format($data['contract']['pay_total']) }} pay &middot;
            ${{ number_format($data['contract']['bonus_at_goal']) }} bonus at {{ number_format($data['contract']['follower_goal']) }} followers
            by {{ archerFmtDate($data['contract']['follower_goal_date']) }}.
        </div>
    @endif

</section>
@endsection
