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

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>

<section class="content">

    @if(empty($data))
        <div class="alert alert-warning">No snapshot data found.</div>
    @else
        <div style="background:#f5f5f5; border-radius:6px; padding:8px 14px; margin-bottom:20px; font-size:12px; color:#888;">
            Instagram / TikTok / Facebook below are a manual snapshot, not a live connection &mdash; they don't move with
            the date filter. Everything from "Website orders" down is live and filters with the dates further down.
        </div>

        {{-- ═══════════ INSTAGRAM ═══════════ --}}
        <h4 style="margin-top:0;">
            Instagram
            @if(!empty($data['instagram']['is_live']))
                <span style="background:#2ecc71; color:#fff; font-size:10px; text-transform:uppercase; letter-spacing:0.5px; padding:2px 8px; border-radius:10px; vertical-align:middle;">Live</span>
            @endif
        </h4>
        <div class="row">
            <div class="col-sm-4">
                {!! archerCard(
                    'Followers',
                    number_format($data['instagram']['followers_start'] / 1000, 1) . 'K &rarr; ' . number_format($data['instagram']['followers_now'] / 1000, 1) . 'K',
                    !empty($data['instagram']['is_live'])
                        ? 'live, ' . archerFmtDate($data['instagram']['followers_start_date']) . ' &ndash; ' . archerFmtDate($data['instagram']['followers_now_date'])
                        : '+' . number_format($data['instagram']['followers_now'] - $data['instagram']['followers_start']) . ' since ' . archerFmtDate($data['contract']['start_date']),
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

        @if(!empty($data['instagram']['is_live']) && !empty($data['instagram']['daily']))
            <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:16px 20px; margin-top:12px;">
                <div style="font-size:12px; color:#999; text-transform:uppercase; letter-spacing:1px; margin-bottom:10px;">Followers by day, live from Instagram</div>
                <div style="position:relative; height:200px;">
                    <canvas id="archerIgChart"
                        data-labels="{{ json_encode(array_map(fn($d) => archerFmtDate($d['date']), $data['instagram']['daily'])) }}"
                        data-followers="{{ json_encode(array_map(fn($d) => $d['followers'], $data['instagram']['daily'])) }}"
                    ></canvas>
                </div>
            </div>
        @endif

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
                <div style="font-size:12px; color:#999; text-transform:uppercase; letter-spacing:1px; margin-bottom:10px;">Week over week (real, exact)</div>
                <div style="position:relative; height:200px;">
                    <canvas id="archerTiktokChart"
                        data-labels="{{ json_encode(array_map(fn($w) => archerFmtDate($w['date']), $data['tiktok']['weekly_followers'])) }}"
                        data-followers="{{ json_encode(array_map(fn($w) => $w['followers'], $data['tiktok']['weekly_followers'])) }}"
                    ></canvas>
                </div>
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
        <div class="row" style="margin-top:16px;">
            <div class="col-sm-4">
                {!! archerCard('Engaged followers, last 28 days', number_format($data['facebook']['engaged_followers'])) !!}
            </div>
            <div class="col-sm-4">
                {!! archerCard('Messaging contacts, last 28 days', number_format($data['facebook']['messaging_contacts'])) !!}
            </div>
            <div class="col-sm-4">
                {!! archerCard('Unfollows, last 28 days', number_format($data['facebook']['unfollows_last_28_days'])) !!}
            </div>
        </div>
        <p class="text-muted" style="margin-top:10px; font-size:12px;">
            Pulled by hand from Meta Business Suite / TikTok Studio on {{ archerFmtDate($data['last_updated']) }}.
            Facebook's start figure is back-calculated from {{ archerFmtDate($data['facebook']['followers_start_asof']) }}
            (closest available date); TikTok's is exact, read off its chart.
        </p>

        <hr style="margin:32px 0;">

        {{-- ═══════════ LIVE, FILTERABLE SECTION ═══════════ --}}
        <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:16px 20px; margin-bottom:16px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <div style="font-size:12px; color:#999; text-transform:uppercase; letter-spacing:1px;">Filter website orders + ROI + discount code usage below</div>
                <div id="archerFilterStatus" style="font-size:12px; color:#2ecc71;"></div>
            </div>
            <form id="archerFilterForm" method="GET" style="display:flex; align-items:flex-end; gap:16px; flex-wrap:wrap; margin-top:8px;">
                <div>
                    <label style="display:block; font-size:12px; color:#999; margin-bottom:4px;">From</label>
                    <input type="date" name="start_date" id="archerStartDate" class="form-control" value="{{ $start_date }}">
                </div>
                <div>
                    <label style="display:block; font-size:12px; color:#999; margin-bottom:4px;">To</label>
                    <input type="date" name="end_date" id="archerEndDate" class="form-control" value="{{ $end_date }}">
                </div>
                <button type="submit" class="btn btn-primary"><i class="fa fa-filter"></i> Apply</button>
                <a href="{{ action('ReportController@archerPerformance') }}" id="archerResetLink" class="btn btn-default">Reset to campaign start &rarr; today</a>
            </form>
        </div>

        <div id="archer-filtered-section" style="position:relative;">
            @include('report.archer_performance_filtered', [
                'start_date' => $start_date,
                'end_date' => $end_date,
                'data' => $data,
                'order_stats' => $order_stats,
                'order_stats_error' => $order_stats_error,
                'archer_coupon' => $archer_coupon,
                'coupon_zipcodes' => $coupon_zipcodes,
                'coupon_zipcodes_total' => $coupon_zipcodes_total,
                'coupon_new_customers' => $coupon_new_customers,
                'coupon_repeat_customers' => $coupon_repeat_customers,
                'coupon_unique_customers' => $coupon_unique_customers,
                'coupon_uses_all_time' => $coupon_uses_all_time,
                'coupon_zipcodes_error' => $coupon_zipcodes_error,
            ])
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

<script>
(function() {
    // TikTok weekly-followers chart (static content, drawn once).
    var ttCanvas = document.getElementById('archerTiktokChart');
    if (ttCanvas && typeof Chart !== 'undefined') {
        new Chart(ttCanvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: JSON.parse(ttCanvas.dataset.labels),
                datasets: [{
                    label: 'TikTok followers',
                    data: JSON.parse(ttCanvas.dataset.followers),
                    borderColor: '#2ecc71',
                    backgroundColor: 'rgba(46,204,113,0.1)',
                    fill: true,
                    tension: 0.2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: false, ticks: { precision: 0 } } },
                plugins: { legend: { display: false } }
            }
        });
    }

    // Instagram daily-followers chart — only present once the Instagram
    // integration is actually connected and returning real data.
    var igCanvas = document.getElementById('archerIgChart');
    if (igCanvas && typeof Chart !== 'undefined') {
        new Chart(igCanvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: JSON.parse(igCanvas.dataset.labels),
                datasets: [{
                    label: 'Instagram followers',
                    data: JSON.parse(igCanvas.dataset.followers),
                    borderColor: '#e1306c',
                    backgroundColor: 'rgba(225,48,108,0.1)',
                    fill: true,
                    tension: 0.2
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: false, ticks: { precision: 0 } } },
                plugins: { legend: { display: false } }
            }
        });
    }

    // Live AJAX filtering: change either date, or hit Apply, and the
    // Website orders / ROI / Discount code usage section refreshes in
    // place — no full page reload. URL still updates (pushState) so the
    // filtered view stays linkable/bookmarkable.
    var form = document.getElementById('archerFilterForm');
    var section = document.getElementById('archer-filtered-section');
    var status = document.getElementById('archerFilterStatus');
    var startInput = document.getElementById('archerStartDate');
    var endInput = document.getElementById('archerEndDate');
    var partialUrl = '{{ route("reports.archer-performance.filtered") }}';
    var fullUrl = '{{ action("ReportController@archerPerformance") }}';

    function loadFiltered(start, end, pushUrl) {
        status.textContent = 'Loading...';
        status.style.color = '#999';
        section.style.opacity = '0.5';
        fetch(partialUrl + '?start_date=' + encodeURIComponent(start) + '&end_date=' + encodeURIComponent(end), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function(r) { if (!r.ok) throw new Error('bad response'); return r.text(); })
            .then(function(html) {
                section.innerHTML = html;
                // <script> tags set via innerHTML don't execute — re-create
                // them so the orders-chart drawing code actually re-runs
                // after each AJAX refresh, not just on first page load.
                section.querySelectorAll('script').forEach(function(oldScript) {
                    var newScript = document.createElement('script');
                    newScript.textContent = oldScript.textContent;
                    oldScript.parentNode.replaceChild(newScript, oldScript);
                });
                section.style.opacity = '1';
                var now = new Date();
                status.style.color = '#2ecc71';
                status.textContent = 'Updated ' + now.toLocaleTimeString();
                if (pushUrl) {
                    var url = fullUrl + '?start_date=' + encodeURIComponent(start) + '&end_date=' + encodeURIComponent(end);
                    window.history.pushState({start: start, end: end}, '', url);
                }
            })
            .catch(function() {
                section.style.opacity = '1';
                status.style.color = '#d9534f';
                status.textContent = 'Couldn\'t refresh — try Apply again.';
            });
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        loadFiltered(startInput.value, endInput.value, true);
    });
    startInput.addEventListener('change', function() { loadFiltered(startInput.value, endInput.value, true); });
    endInput.addEventListener('change', function() { loadFiltered(startInput.value, endInput.value, true); });

    document.getElementById('archerResetLink').addEventListener('click', function(e) {
        e.preventDefault();
        var resetStart = '2026-08-18';
        var resetEnd = new Date().toISOString().slice(0, 10);
        startInput.value = resetStart;
        endInput.value = resetEnd;
        loadFiltered(resetStart, resetEnd, true);
    });

    window.addEventListener('popstate', function(e) {
        if (e.state && e.state.start && e.state.end) {
            startInput.value = e.state.start;
            endInput.value = e.state.end;
            loadFiltered(e.state.start, e.state.end, false);
        }
    });
})();
</script>
@endsection
