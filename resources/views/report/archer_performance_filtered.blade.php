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

<h4 style="margin-top:0;">All website orders, {{ archerFmtDate($start_date) }} &ndash; {{ archerFmtDate($end_date) }}</h4>
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

    @if(!empty($order_stats['daily']))
        <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:16px 20px; margin-top:16px;">
            <div style="font-size:12px; color:#999; text-transform:uppercase; letter-spacing:1px; margin-bottom:10px;">Orders placed vs. cancelled, by day</div>
            <canvas id="archerOrdersChart" height="90"
                data-labels="{{ json_encode(array_map(fn($d) => archerFmtDate($d['date']), $order_stats['daily'])) }}"
                data-placed="{{ json_encode(array_map(fn($d) => $d['placed'], $order_stats['daily'])) }}"
                data-cancelled="{{ json_encode(array_map(fn($d) => $d['cancelled'], $order_stats['daily'])) }}"
            ></canvas>
        </div>
    @endif

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

    @if(!empty($order_stats['orders']))
        @php
            $cancelledOrders = array_values(array_filter($order_stats['orders'], fn($o) => $o['status'] === 'cancelled'));
        @endphp

        <h5 style="margin-top:24px;">Exactly what got refunded ({{ count($cancelledOrders) }} orders)</h5>
        @if(count($cancelledOrders) > 0)
            <div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:0; margin-bottom:16px; max-height:400px; overflow-y:auto;">
                <table class="table table-bordered" style="margin-bottom:0;">
                    <thead>
                        <tr><th>Order #</th><th>Date</th><th>Reason</th><th class="text-right">Amount</th></tr>
                    </thead>
                    <tbody>
                        @foreach($cancelledOrders as $o)
                            <tr>
                                <td>{{ $o['order_number'] ?? '—' }}</td>
                                <td>{{ $o['date'] ? archerFmtDate($o['date']) : '—' }}</td>
                                <td>
                                    @if($o['cancel_reason'] === 'sold_on_discogs')
                                        Sold on Discogs (unrelated)
                                    @elseif($o['cancel_reason'])
                                        {{ ucfirst(str_replace('_', ' ', $o['cancel_reason'])) }}
                                    @else
                                        No reason logged
                                    @endif
                                    @if($o['cancel_reason_note'])
                                        <div class="text-muted" style="font-size:12px;">{{ $o['cancel_reason_note'] }}</div>
                                    @endif
                                </td>
                                <td class="text-right">${{ number_format($o['total'] ?? 0, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <h5 style="margin-top:24px;">
            All {{ count($order_stats['orders']) }} orders in this window
            <button type="button" class="btn btn-default btn-xs" onclick="var t=document.getElementById('archerAllOrdersTable'); t.style.display = t.style.display === 'none' ? '' : 'none';">Show / hide</button>
        </h5>
        <div id="archerAllOrdersTable" style="display:none; background:#fff; border:1px solid #eee; border-radius:6px; padding:0; margin-bottom:24px; max-height:500px; overflow-y:auto;">
            <table class="table table-bordered" style="margin-bottom:0;">
                <thead>
                    <tr><th>Order #</th><th>Date</th><th>Status</th><th class="text-right">Amount</th></tr>
                </thead>
                <tbody>
                    @foreach($order_stats['orders'] as $o)
                        <tr @if($o['status'] === 'cancelled') style="color:#d9534f;" @endif>
                            <td>{{ $o['order_number'] ?? '—' }}</td>
                            <td>{{ $o['date'] ? archerFmtDate($o['date']) : '—' }}</td>
                            <td>{{ ucfirst(str_replace('_', ' ', $o['status'])) }}</td>
                            <td class="text-right">${{ number_format($o['total'] ?? 0, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endif

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

<h4>Discount code usage, {{ archerFmtDate($start_date) }} &ndash; {{ archerFmtDate($end_date) }}</h4>
<p class="text-muted" style="margin-top:-8px; font-size:12px;">Same date range as "Website orders" above &mdash; moves with the filter.</p>
<div style="background:#fff; border:1px solid #eee; border-radius:6px; padding:20px; margin-bottom:24px;">
    @if($archer_coupon)
        <div style="font-size:28px; font-weight:700; color:#333;">{{ number_format(count($coupon_zipcodes)) }} uses in this range &middot; ${{ number_format($coupon_zipcodes_total, 2) }} total</div>
        <div class="text-muted" style="margin-top:4px; margin-bottom:16px;">
            Code <strong>{{ $archer_coupon->code }}</strong> &middot; {{ number_format($coupon_uses_all_time) }} uses all-time, real attributed conversions.
        </div>

        @php
            $archerCancelledCount = count(array_filter($coupon_zipcodes, fn($r) => ($r['order_status'] ?? null) === 'cancelled'));
            $archerCancelledTotal = array_sum(array_map(fn($r) => ($r['order_status'] ?? null) === 'cancelled' ? (float) ($r['total'] ?? 0) : 0, $coupon_zipcodes));
        @endphp
        <div style="background:{{ $archerCancelledCount > 0 ? '#fdf2f2' : '#f2fdf5' }}; border-radius:4px; padding:10px 14px; margin-bottom:16px; font-size:13px;">
            Of these {{ count($coupon_zipcodes) }} Archer-code orders,
            <strong>{{ $archerCancelledCount }} {{ $archerCancelledCount === 1 ? 'was' : 'were' }} refunded/cancelled</strong>
            @if($archerCancelledCount > 0)
                (${{ number_format($archerCancelledTotal, 2) }}).
            @else
                &mdash; none of his own attributed orders were refunded.
            @endif
            This is separate from the site-wide refund numbers below, which cover all website orders, not just his.
        </div>

        @if($order_stats)
            <div style="display:flex; gap:24px; flex-wrap:wrap; margin-bottom:16px; font-size:13px;">
                <div>Site-wide refunded (other inventory issue): <strong style="color:#d9534f;">${{ number_format($order_stats['cancelled_other_revenue'], 2) }}</strong></div>
                <div>Site-wide refunded (sold on Discogs, unrelated): <strong style="color:#d9534f;">${{ number_format($order_stats['cancelled_discogs_revenue'], 2) }}</strong></div>
                <div>Site-wide net revenue: <strong style="color:#2ecc71;">${{ number_format($order_stats['net_revenue'], 2) }}</strong></div>
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
            <p class="text-muted" style="margin-bottom:0;">No orders used this code in this date range ({{ number_format($coupon_uses_all_time) }} all-time).</p>
        @endif
    @else
        <div class="alert alert-warning" style="margin-bottom:0;">
            No coupon code with "archer" in it exists yet. Create one at
            <a href="{{ route('coupons.index') }}">Coupons</a> to start tracking this.
        </div>
    @endif
</div>

<script>
(function() {
    var canvas = document.getElementById('archerOrdersChart');
    if (!canvas || typeof Chart === 'undefined') return;
    if (canvas.__chart) { canvas.__chart.destroy(); }
    canvas.__chart = new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: JSON.parse(canvas.dataset.labels),
            datasets: [
                { label: 'Placed', data: JSON.parse(canvas.dataset.placed), backgroundColor: '#2ecc71' },
                { label: 'Cancelled', data: JSON.parse(canvas.dataset.cancelled), backgroundColor: '#d9534f' }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { x: { stacked: false }, y: { beginAtZero: true, ticks: { precision: 0 } } },
            plugins: { legend: { position: 'bottom' } }
        }
    });
})();
</script>
