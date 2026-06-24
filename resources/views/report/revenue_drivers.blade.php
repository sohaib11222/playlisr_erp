@extends('layouts.app')
@section('title', 'Revenue Drivers')

@section('content')

{{-- Revenue Drivers — POS-create-style reskin matching the Items / LFL reports.
     Lays out Revenue = Traffic x Conversion x AOV + Product Mix as a scorecard,
     filling in every metric the ERP can compute and flagging the rest. --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap"></noscript>
<link rel="stylesheet" href="{{ asset('css/items-report-layout.css?v=' . $asset_v) }}">
<script>document.body.classList.add('items-report-v2');</script>

<style>
    .rd-note { color: #5A5045; font-size: 13px; margin: 0 0 14px; }
    .rd-note strong { color: #1F1B16; }
    .rd-cards { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
    @media (max-width: 991px) { .rd-cards { grid-template-columns: 1fr; } }
    .rd-card { background: #FFFDF7; border: 1px solid #ECE3CF; border-radius: 12px; padding: 18px 20px; }
    .rd-card h3 { margin: 0 0 2px; font-weight: 800; font-size: 17px; color: #1F1B16; }
    .rd-card .rd-sub { color: #5A5045; font-size: 12.5px; margin: 0 0 14px; }
    .rd-metric { display: flex; justify-content: space-between; align-items: baseline; padding: 9px 0; border-bottom: 1px solid #F2EBDB; }
    .rd-metric:last-child { border-bottom: 0; }
    .rd-metric .lbl { color: #3A3329; font-size: 13.5px; }
    .rd-metric .val { font-weight: 700; font-size: 16px; color: #1F1B16; white-space: nowrap; }
    .rd-metric .val.big { font-size: 20px; }
    .rd-tag { display: inline-block; font-size: 11px; font-weight: 600; padding: 1px 7px; border-radius: 999px; vertical-align: middle; }
    .rd-tag.miss { background: #FBE9E7; color: #c0392b; }
    .rd-tag.proxy { background: #FFF2B3; color: #6b5d00; }
    .rd-missing { color: #b03a2e; font-weight: 600; font-size: 13.5px; }
    .rd-headline { background: #1F1B16; color: #FFFDF7; border-radius: 12px; padding: 16px 20px; margin-bottom: 16px; display: flex; flex-wrap: wrap; gap: 26px; align-items: baseline; }
    .rd-headline .formula { font-weight: 800; font-size: 18px; }
    .rd-headline .kpi { font-size: 13px; color: #D8CEB8; }
    .rd-headline .kpi b { color: #FFF2B3; font-size: 18px; font-weight: 800; display: block; }
    .rd-mini th, .rd-mini td { padding: 6px 8px; }
    .rd-mini td.num, .rd-mini th.num { text-align: right; white-space: nowrap; }
</style>

@php
    $s = $scorecard;
    $money = function ($n) { return '$' . number_format($n, 2); };
@endphp

<section class="content-header">
    <h1>Revenue Drivers <small>Revenue = Traffic × Conversion × AOV, plus product mix — what the ERP can measure, and what's missing.</small></h1>
</section>

<section class="content">

    <div class="row">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])
                {!! Form::open(['url' => action('ReportController@revenueDrivers'), 'method' => 'get']) !!}
                    <div class="col-md-4">
                        <div class="form-group">
                            {!! Form::label('date_range', 'Date range:') !!}
                            {!! Form::text('date_range', request('date_range'), ['placeholder' => 'Last 30 days', 'class' => 'form-control', 'id' => 'rd_date_range', 'readonly']); !!}
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label style="display:block;">&nbsp;</label>
                        <button type="submit" class="btn btn-primary">@lang('report.apply_filters')</button>
                    </div>
                {!! Form::close() !!}
            @endcomponent

            <p class="rd-note">
                Window: <strong>{{ $meta['label'] }}</strong> ({{ $meta['days'] }} days).
                Sales = finalized in-store sells; Whatnot livestream and bulk-imported history excluded, matching Store Performance &amp; LFL.
            </p>

            <div class="rd-headline">
                <span class="formula">Revenue = Traffic × Conversion × AOV</span>
                <span class="kpi">Revenue <b>{{ $money($s['revenue']) }}</b></span>
                <span class="kpi">Transactions <b>{{ number_format($s['tx_count']) }}</b></span>
                <span class="kpi">AOV <b>{{ $money($s['aov']) }}</b></span>
            </div>
        </div>
    </div>

    {{-- Per-store comparison --}}
    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary', 'title' => 'By store — the levers side by side'])
                <div class="table-responsive">
                    <table class="table table-bordered table-striped rd-mini" style="width:100%;">
                        <thead>
                            <tr>
                                <th>Store</th>
                                <th class="num">Revenue</th>
                                <th class="num">Transactions</th>
                                <th class="num">AOV</th>
                                <th class="num">Items / order</th>
                                <th class="num">Customers</th>
                                <th class="num">Repeat</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($by_store as $r)
                                <tr>
                                    <td>{{ $r['location'] }}</td>
                                    <td class="num">{{ $money($r['revenue']) }}</td>
                                    <td class="num">{{ number_format($r['tx_count']) }}</td>
                                    <td class="num">{{ $money($r['aov']) }}</td>
                                    <td class="num">{{ number_format($r['items_per_order'], 2) }}</td>
                                    <td class="num">{{ number_format($r['customers']) }}</td>
                                    <td class="num">{{ number_format($r['repeat']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted">No active stores.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endcomponent
        </div>
    </div>

    <div class="rd-cards">

        {{-- 1. TRAFFIC --}}
        <div class="rd-card">
            <h3>1. Traffic</h3>
            <p class="rd-sub">How many people come to the store</p>
            <div class="rd-metric"><span class="lbl">Transactions (buyers) <span class="rd-tag proxy">proxy</span></span><span class="val big">{{ number_format($s['tx_count']) }}</span></div>
            <div class="rd-metric"><span class="lbl">Identified customers</span><span class="val">{{ number_format($s['distinct_customers']) }}</span></div>
            <div class="rd-metric"><span class="lbl">Repeat customers (bought 2+×)</span><span class="val">{{ number_format($s['repeat_customers']) }}</span></div>
            <div class="rd-metric"><span class="lbl">Walk-in sales (no customer on file)</span><span class="val">{{ number_format($s['anon_tx']) }}</span></div>
            <div class="rd-metric"><span class="lbl">Door count / foot traffic <span class="rd-tag miss">missing</span></span><span class="rd-missing">not tracked yet</span></div>
            <div class="rd-metric"><span class="lbl">Marketing / social / events visits <span class="rd-tag miss">missing</span></span><span class="rd-missing">not tracked yet</span></div>
        </div>

        {{-- 2. CONVERSION --}}
        <div class="rd-card">
            <h3>2. Conversion</h3>
            <p class="rd-sub">How many visitors become customers</p>
            <div class="rd-metric"><span class="lbl">Conversion rate (buyers ÷ visitors) <span class="rd-tag miss">missing</span></span><span class="rd-missing">needs door count</span></div>
            <div class="rd-metric"><span class="lbl">Baskets per staff hour <span class="rd-tag miss">missing</span></span><span class="rd-missing">not tracked yet</span></div>
            <div class="rd-metric"><span class="lbl">Product availability / merch / pricing <span class="rd-tag miss">missing</span></span><span class="rd-missing">not tracked yet</span></div>
            <p class="rd-note" style="margin:14px 0 0;">
                Conversion can't be computed until we count visitors (door sensor or POS clienteling).
                The ERP only sees people who actually bought — there's no denominator yet.
            </p>
        </div>

        {{-- 3. AOV --}}
        <div class="rd-card">
            <h3>3. AOV</h3>
            <p class="rd-sub">How much money each customer spends</p>
            <div class="rd-metric"><span class="lbl">Average order value</span><span class="val big">{{ $money($s['aov']) }}</span></div>
            <div class="rd-metric"><span class="lbl">Items per order <span class="rd-tag proxy">upsell/cross-sell</span></span><span class="val">{{ number_format($s['items_per_order'], 2) }}</span></div>
            <div class="rd-metric"><span class="lbl">Average item price</span><span class="val">{{ $money($s['avg_item_price']) }}</span></div>
            <div class="rd-metric"><span class="lbl">Total units sold</span><span class="val">{{ number_format($s['units']) }}</span></div>
            <div class="rd-metric"><span class="lbl">Premium / limited-edition share <span class="rd-tag miss">missing</span></span><span class="rd-missing">not flagged in data</span></div>
            <div class="rd-metric"><span class="lbl">Bundles <span class="rd-tag miss">missing</span></span><span class="rd-missing">not tracked yet</span></div>
        </div>

        {{-- 4. INVENTORY & PRODUCT MIX --}}
        <div class="rd-card">
            <h3>4. Inventory &amp; Product Mix</h3>
            <p class="rd-sub">What's actually selling — by genre and title</p>
            @if(count($by_category))
                <table class="table rd-mini" style="margin:0 0 12px; width:100%;">
                    <thead><tr><th>Top genres</th><th class="num">Units</th><th class="num">Revenue</th></tr></thead>
                    <tbody>
                        @foreach($by_category as $c)
                            <tr><td>{{ $c->genre }}</td><td class="num">{{ number_format($c->units) }}</td><td class="num">{{ $money($c->revenue) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="rd-missing">No line items in this window.</p>
            @endif
            <div class="rd-metric"><span class="lbl">Demand vs new releases / best sellers <span class="rd-tag proxy">see best sellers below</span></span><span class="val">&nbsp;</span></div>
        </div>

    </div>

    {{-- Best sellers full width --}}
    <div class="row" style="margin-top:16px;">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary', 'title' => 'Best sellers (by units) — product-mix driver'])
                <div class="table-responsive">
                    <table class="table table-bordered table-striped rd-mini" style="width:100%;">
                        <thead><tr><th>#</th><th>Title</th><th class="num">Units</th><th class="num">Revenue</th></tr></thead>
                        <tbody>
                            @forelse($best_sellers as $i => $b)
                                <tr><td>{{ $i + 1 }}</td><td>{{ $b->product }}</td><td class="num">{{ number_format($b->units) }}</td><td class="num">{{ $money($b->revenue) }}</td></tr>
                            @empty
                                <tr><td colspan="4" class="text-center text-muted">No sales in this window.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            @endcomponent
        </div>
    </div>

    <p class="rd-note">
        <strong>How to read the gaps:</strong> values in black are live from sales data.
        <span class="rd-tag proxy">proxy</span> = closest stand-in we can compute today.
        <span class="rd-tag miss">missing</span> = needs data the ERP doesn't capture yet (visitor counts, premium/bundle flags).
        Tell me which gap matters most and I'll wire up the capture for it.
    </p>

</section>
@endsection

@section('javascript')
    <script>
        $(function () {
            var fmt = (typeof moment_date_format !== 'undefined') ? moment_date_format : 'MM/DD/YYYY';
            $('#rd_date_range').daterangepicker({
                autoUpdateInput: false,
                locale: {
                    format: fmt,
                    cancelLabel: (typeof LANG !== 'undefined' ? LANG.clear : 'Clear'),
                    applyLabel: (typeof LANG !== 'undefined' ? LANG.apply : 'Apply'),
                },
            });
            $('#rd_date_range').on('apply.daterangepicker', function (ev, picker) {
                $(this).val(picker.startDate.format(fmt) + ' ~ ' + picker.endDate.format(fmt));
            });
            $('#rd_date_range').on('cancel.daterangepicker', function () {
                $(this).val('');
            });
        });
    </script>
@endsection
