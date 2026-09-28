{{-- Filtered section (Website orders + ROI + Discount code usage). Rendered
     both by the full-page load and by the AJAX partial endpoint that the
     date inputs hit on change — same Blade, same numbers, no drift between
     the two entry points. --}}
@php
    if (!function_exists('archerFmtDate')) {
        function archerFmtDate($d) { return \Carbon::parse($d)->format('m/d/y'); }
    }
    if (!function_exists('archerCard')) {
        function archerCard($label, $value, $sub = null, $subColor = '#999') {
            $html = '<div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:12px 14px; text-align:center;">';
            $html .= '<div style="color:#999; font-size:10.5px; text-transform:uppercase; letter-spacing:0.75px; margin-bottom:3px;">' . e($label) . '</div>';
            $html .= '<div style="font-size:22px; font-weight:700; color:#333; line-height:1.15;">' . $value . '</div>';
            if ($sub) {
                $html .= '<div style="color:' . $subColor . '; font-size:11px; font-weight:600; margin-top:2px;">' . $sub . '</div>';
            }
            $html .= '</div>';
            return $html;
        }
    }
    if (!function_exists('archerMini')) {
        // Denser than archerCard, for the "at a glance" strip -- label and
        // value stacked tight, meant to sit many-to-a-row.
        function archerMini($label, $value, $accent = '#333') {
            $html = '<div style="flex:1; min-width:110px; background:#fff; border:1px solid #eee; border-left:3px solid ' . $accent . '; border-radius:4px; padding:8px 10px;">';
            $html .= '<div style="color:#999; font-size:10px; text-transform:uppercase; letter-spacing:0.5px;">' . e($label) . '</div>';
            $html .= '<div style="font-size:17px; font-weight:700; color:#333; line-height:1.3;">' . $value . '</div>';
            $html .= '</div>';
            return $html;
        }
    }
@endphp

{{-- ═══════════ ROI — FIRST THING ON THE PAGE, IMPOSSIBLE TO MISS ═══════════ --}}
{{-- Must be revenue actually attributable to Archer (his discount code),
     not site-wide website revenue — using the site-wide number here would
     credit him with traffic he had nothing to do with. --}}
@php
    $archerCancelledCount = $archer_coupon ? count(array_filter($coupon_zipcodes, fn($r) => ($r['order_status'] ?? null) === 'cancelled')) : 0;
    $archerCancelledTotal = $archer_coupon ? array_sum(array_map(fn($r) => ($r['order_status'] ?? null) === 'cancelled' ? (float) ($r['total'] ?? 0) : 0, $coupon_zipcodes)) : 0;
    $archerAttributedNet = $archer_coupon ? ((float) $coupon_zipcodes_total - $archerCancelledTotal) : null;

    // Derived cross-platform totals for the "at a glance" strip -- nothing
    // here is a new data source, just arithmetic on the same numbers each
    // platform section below already shows.
    $totalFollowerGrowth = ($data['instagram']['followers_now'] - $data['instagram']['followers_start'])
        + ($data['tiktok']['followers_now'] - $data['tiktok']['followers_start'])
        + ($data['facebook']['followers_now'] - $data['facebook']['followers_start']);
    $videoCount = $data['instagram']['confirmed_collab_videos'];
    $costPerVideo = $videoCount > 0 ? $data['contract']['pay_total'] / $videoCount : null;
    $costPerFollower = $totalFollowerGrowth > 0 ? $data['contract']['pay_total'] / $totalFollowerGrowth : null;
    $contractDaysTotal = \Carbon::parse($data['contract']['start_date'])->diffInDays(\Carbon::parse($data['contract']['end_date'])) ?: 1;
    $contractDaysElapsed = min($contractDaysTotal, max(0, \Carbon::parse($data['contract']['start_date'])->diffInDays(now())));
    $contractPct = round(($contractDaysElapsed / $contractDaysTotal) * 100);
@endphp
@if(!is_null($archerAttributedNet))
    @php $roiRatio = $archerAttributedNet / $data['contract']['pay_total']; @endphp
    <div style="background:{{ $roiRatio >= 1 ? '#eafaf1' : '#fdf2f2' }}; border:2px solid {{ $roiRatio >= 1 ? '#2ecc71' : '#d9534f' }}; border-radius:8px; padding:14px 18px; margin-bottom:10px;">
        <div style="display:flex; align-items:baseline; justify-content:space-between; flex-wrap:wrap; gap:8px;">
            <div>
                <span style="font-size:11px; color:#666; text-transform:uppercase; letter-spacing:1px;">ROI, {{ archerFmtDate($start_date) }} &ndash; {{ archerFmtDate($end_date) }}</span><br>
                <span style="font-size:34px; font-weight:800; line-height:1.2; color:{{ $roiRatio >= 1 ? '#27ae60' : '#c0392b' }};">${{ number_format($roiRatio, 2) }}</span>
                <span style="font-size:14px; font-weight:600; color:#666;">back per $1 spent</span>
            </div>
            <div style="font-size:13px; color:#444; text-align:right;">
                Paid <strong>${{ number_format($data['contract']['pay_total']) }}</strong> &rarr; got back <strong>${{ number_format($archerAttributedNet, 2) }}</strong><br>
                <span style="font-size:11px; color:#888;">from code {{ $archer_coupon->code }}, net of refunds &mdash; a floor, not the whole picture</span>
            </div>
        </div>
    </div>
@elseif($order_stats)
    <div style="background:#fdf2f2; border:2px solid #d9534f; border-radius:8px; padding:14px 18px; margin-bottom:10px;">
        <div style="font-size:14px; color:#444;">No Archer discount-code orders found for this range &mdash; can't compute an attributed ROI.</div>
    </div>
@endif

{{-- ═══════════ AT A GLANCE — dense derived-stat strip ═══════════ --}}
<div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px;">
    {!! archerMini('Contract', $contractPct >= 100 ? 'Ended' : $contractPct . '% elapsed', $contractPct >= 100 ? '#999' : '#2ecc71') !!}
    {!! archerMini('Confirmed videos', number_format($videoCount), '#333') !!}
    {!! archerMini('Cost / video', $costPerVideo !== null ? '$' . number_format($costPerVideo) : '&mdash;', '#333') !!}
    {!! archerMini('Cross-platform follower growth', '+' . number_format($totalFollowerGrowth), '#2ecc71') !!}
    {!! archerMini('Cost / follower gained', $costPerFollower !== null ? '$' . number_format($costPerFollower, 2) : '&mdash;', '#333') !!}
    {!! archerMini('Discount-code orders', number_format(count($coupon_zipcodes ?? [])) . ' this range', '#333') !!}
    {!! archerMini('Earned from his code', !is_null($archerAttributedNet) ? '$' . number_format($archerAttributedNet, 2) : '&mdash;', '#2ecc71') !!}
</div>

{{-- ═══════════ PLATFORM COMPARISON — one row per platform, side by side ═══════════ --}}
@php
    function archerLiveDot($isLive) {
        return '<span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:' . ($isLive ? '#2ecc71' : '#ccc') . '; margin-right:5px;" title="' . ($isLive ? 'Now live' : 'Manual snapshot') . '"></span>';
    }
@endphp
<div style="background:#fff; border:1px solid #eee; border-radius:6px; overflow-x:auto; margin-bottom:6px;">
    <table class="table table-condensed" style="margin-bottom:0; font-size:12.5px;">
        <thead>
            <tr style="color:#999; text-transform:uppercase; font-size:10px; letter-spacing:0.5px;">
                <th>Platform</th><th>Followers</th><th>Growth</th><th>Reach (trailing 28d, fixed)</th><th>Extra</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{!! archerLiveDot(!empty($data['instagram']['is_live'])) !!}<strong>Instagram</strong></td>
                <td>{{ number_format($data['instagram']['followers_start'] / 1000, 1) }}K &rarr; {{ number_format($data['instagram']['followers_now'] / 1000, 1) }}K</td>
                <td style="color:#2ecc71; font-weight:600;">+{{ number_format($data['instagram']['followers_now'] - $data['instagram']['followers_start']) }}</td>
                <td>{{ number_format($data['instagram']['reach_last_28_days']) }} ({{ $data['instagram']['reach_change_pct'] }}%)</td>
                <td>{{ $videoCount }} confirmed videos</td>
            </tr>
            <tr>
                <td>{!! archerLiveDot(!empty($data['tiktok']['is_live'])) !!}<strong>TikTok</strong></td>
                <td>{{ number_format($data['tiktok']['followers_start']) }} &rarr; {{ number_format($data['tiktok']['followers_now']) }}</td>
                <td style="color:#2ecc71; font-weight:600;">+{{ number_format($data['tiktok']['followers_now'] - $data['tiktok']['followers_start']) }}</td>
                <td>&mdash;</td>
                <td>{{ number_format($data['tiktok']['total_likes']) }} total likes</td>
            </tr>
            <tr>
                <td>{!! archerLiveDot(!empty($data['facebook']['is_live'])) !!}<strong>Facebook</strong></td>
                <td>{{ number_format($data['facebook']['followers_start']) }} &rarr; {{ number_format($data['facebook']['followers_now']) }}</td>
                <td style="color:#2ecc71; font-weight:600;">+{{ number_format($data['facebook']['followers_now'] - $data['facebook']['followers_start']) }}</td>
                <td>{{ number_format($data['facebook']['reach_last_28_days']) }} ({{ $data['facebook']['reach_change_pct'] }}%)</td>
                <td>{{ number_format($data['facebook']['engaged_followers']) }} engaged followers</td>
            </tr>
        </tbody>
    </table>
</div>
<p class="text-muted" style="font-size:11px; margin-bottom:18px;">Confirmed videos: verified, posted by @archerxvalentine tagging @nivessarecords. Dot = now live vs. manual snapshot. Reach is always trailing-28-days as of the last pull &mdash; it does NOT move with the date filter above, unlike Followers/Growth.</p>

<hr style="margin:18px 0;">

<h4 style="margin-top:0;">Orders using code ARCHER, {{ archerFmtDate($start_date) }} &ndash; {{ archerFmtDate($end_date) }}</h4>
<p class="text-muted" style="margin-top:-8px; font-size:12px;">What's actually attributable to him &mdash; moves with the date filter above.</p>
<div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:20px; margin-bottom:24px;">
    @if($archer_coupon)
        <div style="font-size:13px; color:#999; margin-bottom:8px;">
            {{ number_format(count($coupon_zipcodes)) }} uses in this range &middot; Code <strong>{{ $archer_coupon->code }}</strong> &middot; {{ number_format($coupon_uses_all_time) }} uses all-time
        </div>
        <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:14px 18px; margin-bottom:16px;">
            <table class="table" style="margin-bottom:0;">
                <tr>
                    <th style="width:320px;">Potential (if everything had been in stock)</th>
                    <td>${{ number_format($coupon_zipcodes_total, 2) }}</td>
                </tr>
                <tr>
                    <th>Lost to refunds/cancellations ({{ $archerCancelledCount }} {{ $archerCancelledCount === 1 ? 'order' : 'orders' }})</th>
                    <td style="color:#d9534f;">&minus;${{ number_format($archerCancelledTotal, 2) }}</td>
                </tr>
                <tr style="border-top:2px solid #eee;">
                    <th>Actually earned (net of refunds)</th>
                    <td><strong style="color:#2ecc71;">${{ number_format($archerAttributedNet, 2) }}</strong></td>
                </tr>
            </table>
        </div>

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
                    <tr><th>Zip code</th><th>City</th><th>State</th><th>Date</th><th>Customer</th><th>Status</th><th class="text-right">Order total</th></tr>
                </thead>
                <tbody>
                    @foreach($coupon_zipcodes as $row)
                        <tr @if(($row['order_status'] ?? null) === 'cancelled') style="color:#d9534f;" @endif>
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
                            <td>{{ ($row['order_status'] ?? null) === 'cancelled' ? 'Refunded' : 'Completed' }}</td>
                            <td class="text-right">{{ $row['total'] ? '$' . number_format($row['total'], 2) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="text-muted" style="margin-bottom:0;">No orders used this code in this date range ({{ number_format($coupon_uses_all_time) }} all-time).</p>
        @endif
    @else
        <div class="alert alert-warning" style="margin-bottom:0;">
            No coupon code with "archer" in it exists yet. Create one at
            <a href="{{ route('coupons.index') }}">Coupons</a> to start tracking this.
        </div>
    @endif
</div>

