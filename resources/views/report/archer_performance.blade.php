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
        {{-- ═══════════ ONE FILTER, EVERYTHING BELOW MOVES WITH IT ═══════════ --}}
        <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:16px 20px; margin-bottom:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <div style="font-size:12px; color:#999; text-transform:uppercase; letter-spacing:1px;">Filter this whole report</div>
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
            <p class="text-muted" style="margin:8px 0 0; font-size:12px;">
                Instagram/Facebook go live automatically once their API token is connected (see
                <a href="{{ url('/communications/instagram-settings') }}">Instagram DM Settings</a>) &mdash; until then they
                show the last manual snapshot pulled on {{ archerFmtDate($data['last_updated']) }}, filtered to the closest
                real data available. TikTok shows real weekly checkpoints filtered to this range.
            </p>
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
    // Live AJAX filtering: change either date, or hit Apply, and the
    // entire report (social snapshots + website orders + ROI + discount
    // code usage) refreshes in place — no full page reload. URL still
    // updates (pushState) so the filtered view stays linkable/bookmarkable.
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
                // Destroy any Chart.js instances still attached to canvases
                // in the section before replacing it — otherwise each old
                // chart stays alive in Chart.js's internal registry with no
                // way to reach it once its canvas is detached (a slow leak
                // across repeated filter changes in one long session).
                section.querySelectorAll('canvas').forEach(function(c) {
                    var existing = typeof Chart !== 'undefined' && Chart.getChart ? Chart.getChart(c) : null;
                    if (existing) existing.destroy();
                });
                section.innerHTML = html;
                // <script> tags set via innerHTML don't execute — re-create
                // them so the chart-drawing code actually re-runs after
                // each AJAX refresh, not just on first page load.
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
