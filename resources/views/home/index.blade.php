@extends('layouts.app')
@section('title', __('home.home'))

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header content-header-custom">
    <h1>{{ __('home.welcome_message', ['name' => Session::get('user.first_name')]) }}
    </h1>
</section>

{{-- ============================================================
     Nivessa employee dashboard (new) — what's selling, collections
     bought, recent sales, top-dollar items. Lives above the classic
     admin widgets below.
     ============================================================ --}}
@if(auth()->user()->can('dashboard.data'))
<section class="content no-print" style="padding-bottom: 0;">
    <style>
        .niv-card { background:#fff; border:1px solid #e6e8ec; border-radius:10px; padding:14px 16px; margin-bottom:18px; box-shadow:0 1px 3px rgba(0,0,0,.03); }
        .niv-card h3 { margin:0 0 10px 0; font-size:15px; text-transform:uppercase; letter-spacing:.6px; color:#1b6ca8; font-weight:700; border-bottom:1px solid #eef0f3; padding-bottom:8px; }
        .niv-card h3 .niv-sub { font-size:11px; color:#7b8796; text-transform:none; letter-spacing:0; font-weight:500; margin-left:8px; }
        .niv-card table { width:100%; font-size:13px; }
        .niv-card table td, .niv-card table th { padding:6px 8px; vertical-align:top; }
        .niv-card table tr + tr td { border-top:1px solid #f1f2f4; }
        .niv-chip { display:inline-block; padding:2px 8px; border-radius:999px; background:#eef2f7; color:#3c5a73; font-size:11px; font-weight:600; }
        .niv-chip.niv-chip-lp { background:#fde7f1; color:#9d174d; }
        .niv-chip.niv-chip-cd { background:#e0f2fe; color:#075985; }
        .niv-chip.niv-chip-cassette { background:#fff7ed; color:#9a3412; }
        .niv-chip.niv-chip-dvd { background:#ecfeff; color:#155e75; }
        .niv-chip.niv-chip-magazine { background:#f5f3ff; color:#5b21b6; }
        .niv-muted { color:#7b8796; font-size:12px; }
        .niv-money { font-weight:700; color:#1f7a45; }
    </style>

    {{-- Progress: MoM, YoY, and personal month-over-month --}}
    <div class="row">
        <div class="col-md-3">
            <div class="niv-card" style="background:linear-gradient(135deg,#fff7ed,#ffedd5);">
                <h3 style="color:#9a3412;"><i class="fa fa-chart-line"></i> Store Sales MTD</h3>
                <div style="font-size:26px; font-weight:800; color:#9a3412;">${{ number_format($sales_mtd, 0) }}</div>
                <div class="niv-muted" style="margin-top:4px;">
                    @if(is_null($mom_pct))
                        vs last month same range: ${{ number_format($sales_lm_same, 0) }}
                    @else
                        <strong style="color:{{ $mom_pct >= 0 ? '#065f46' : '#991b1b' }};">{{ $mom_pct >= 0 ? '▲' : '▼' }} {{ number_format(abs($mom_pct), 1) }}%</strong>
                        vs last month (${{ number_format($sales_lm_same, 0) }})
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="niv-card" style="background:linear-gradient(135deg,#f0f9ff,#e0f2fe);">
                <h3 style="color:#075985;"><i class="fa fa-calendar-alt"></i> Store Sales YTD</h3>
                <div style="font-size:26px; font-weight:800; color:#075985;">${{ number_format($sales_ytd, 0) }}</div>
                <div class="niv-muted" style="margin-top:4px;">
                    @if(is_null($yoy_pct))
                        vs same range last year: ${{ number_format($sales_ly_same, 0) }}
                    @else
                        <strong style="color:{{ $yoy_pct >= 0 ? '#065f46' : '#991b1b' }};">{{ $yoy_pct >= 0 ? '▲' : '▼' }} {{ number_format(abs($yoy_pct), 1) }}%</strong>
                        vs last year (${{ number_format($sales_ly_same, 0) }})
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="niv-card" style="background:linear-gradient(135deg,#fef3c7,#fde68a);">
                <h3 style="color:#78350f;"><i class="fa fa-trophy"></i> You — Priced MTD</h3>
                <div style="font-size:26px; font-weight:800; color:#78350f;">{{ number_format($my_mtd_priced) }}</div>
                <div class="niv-muted" style="margin-top:4px;">
                    @if(is_null($my_priced_pct))
                        last month: {{ number_format($my_lm_priced) }}
                    @else
                        <strong style="color:{{ $my_priced_pct >= 0 ? '#065f46' : '#991b1b' }};">{{ $my_priced_pct >= 0 ? '▲' : '▼' }} {{ number_format(abs($my_priced_pct), 1) }}%</strong>
                        vs last month ({{ number_format($my_lm_priced) }})
                    @endif
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="niv-card" style="background:linear-gradient(135deg,#faf5ff,#f3e8ff);">
                <h3 style="color:#6b21a8;"><i class="fa fa-medal"></i> You — Rung Up MTD</h3>
                <div style="font-size:26px; font-weight:800; color:#6b21a8;">{{ number_format($my_mtd_rung) }}</div>
                <div class="niv-muted" style="margin-top:4px;">
                    @if(is_null($my_rung_pct))
                        last month: {{ number_format($my_lm_rung) }}
                    @else
                        <strong style="color:{{ $my_rung_pct >= 0 ? '#065f46' : '#991b1b' }};">{{ $my_rung_pct >= 0 ? '▲' : '▼' }} {{ number_format(abs($my_rung_pct), 1) }}%</strong>
                        vs last month ({{ number_format($my_lm_rung) }})
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Revenue driven by items YOU barcoded / priced --}}
    <div class="row">
        <div class="col-md-12">
            <div class="niv-card" style="background:linear-gradient(135deg,#fef9c3,#fde68a); border-color:#f59e0b;">
                <h3 style="color:#78350f;"><i class="fa fa-dollar-sign"></i> $$ Generated From Items YOU Barcoded <span class="niv-sub">the more you price, the more you earn for the shop</span></h3>
                <div class="row">
                    <div class="col-md-4">
                        <div class="niv-muted" style="text-transform:uppercase; font-size:11px; letter-spacing:.5px;">This month</div>
                        <div style="font-size:32px; font-weight:800; color:#78350f;">${{ number_format($my_priced_rev_mtd, 0) }}</div>
                        @if(!is_null($my_priced_rev_pct))
                            <div class="niv-muted" style="margin-top:2px;">
                                <strong style="color:{{ $my_priced_rev_pct >= 0 ? '#065f46' : '#991b1b' }};">{{ $my_priced_rev_pct >= 0 ? '▲' : '▼' }} {{ number_format(abs($my_priced_rev_pct), 1) }}%</strong>
                                vs last month (${{ number_format($my_priced_rev_lm, 0) }})
                            </div>
                        @endif
                    </div>
                    <div class="col-md-4">
                        <div class="niv-muted" style="text-transform:uppercase; font-size:11px; letter-spacing:.5px;">Last month</div>
                        <div style="font-size:22px; font-weight:700; color:#78350f;">${{ number_format($my_priced_rev_lm, 0) }}</div>
                    </div>
                    <div class="col-md-4">
                        <div class="niv-muted" style="text-transform:uppercase; font-size:11px; letter-spacing:.5px;">Lifetime</div>
                        <div style="font-size:22px; font-weight:700; color:#78350f;">${{ number_format($my_priced_rev_lifetime, 0) }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Personal "today" stats + rewards accounts created today --}}
    <div class="row">
        <div class="col-md-3">
            <div class="niv-card" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);">
                <h3 style="color:#3730a3;"><i class="fa fa-tag"></i> You — Items Priced Today</h3>
                <div style="font-size:36px; font-weight:800; color:#3730a3; line-height:1;">{{ number_format($my_priced_today) }}</div>
                <div class="niv-muted" style="margin-top:4px;">Products you created today</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="niv-card" style="background:linear-gradient(135deg,#ecfdf5,#d1fae5);">
                <h3 style="color:#065f46;"><i class="fa fa-cash-register"></i> You — Rung Up Today</h3>
                <div style="font-size:36px; font-weight:800; color:#065f46; line-height:1;">{{ number_format($my_pos_items_today) }}</div>
                <div class="niv-muted" style="margin-top:4px;">{{ $my_pos_tx_today }} transaction{{ $my_pos_tx_today == 1 ? '' : 's' }}, {{ $my_pos_items_today }} line items</div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="niv-card">
                <h3><i class="fa fa-id-card"></i> Rewards Accounts Created Today <span class="niv-sub">{{ $rewards_today_total }} today — by employee</span></h3>
                <table>
                    <tbody>
                    @forelse($rewards_today as $r)
                        <tr>
                            <td>{{ trim($r->employee) ?: '(unknown)' }}</td>
                            <td class="text-right niv-money">{{ (int) $r->cnt }}</td>
                        </tr>
                    @empty
                        <tr><td class="niv-muted">No new rewards accounts created yet today.</td></tr>
                    @endforelse
                    </tbody>
                </table>
                <div class="niv-muted" style="margin-top:6px; font-size:11px;">Note: attribution is by the employee who created the account; the system doesn't currently record which store it was created at (would need a small schema change).</div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="niv-card">
                <h3><i class="fa fa-star"></i> Top Selling Categories — by store <span class="niv-sub">last 30 days, by revenue</span></h3>
                @forelse($top_categories_by_location as $loc => $cats)
                    <div style="margin-bottom:12px;">
                        <strong>{{ $loc }}</strong>
                        <table>
                            <tbody>
                            @foreach($cats as $c)
                                <tr>
                                    <td>{{ $c->category }}</td>
                                    <td class="text-right niv-muted">{{ number_format($c->qty, 0) }} sold</td>
                                    <td class="text-right niv-money">${{ number_format($c->revenue, 0) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @empty
                    <div class="niv-muted">No sales yet in the last 30 days.</div>
                @endforelse
            </div>
        </div>

        <div class="col-md-6">
            <div class="niv-card">
                <h3><i class="fa fa-compact-disc"></i> What's Selling — by format <span class="niv-sub">last 30 days (LP / CD / Cassette / DVD / Magazine / …)</span></h3>
                <table>
                    <thead>
                        <tr style="color:#7b8796; text-transform:uppercase; font-size:11px; letter-spacing:.5px;">
                            <th>Format</th>
                            <th class="text-right">Units sold</th>
                            <th class="text-right">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($top_formats as $f)
                        @php
                            $cls = strtolower(preg_replace('/[^a-z0-9]/i','-', $f->format));
                        @endphp
                        <tr>
                            <td><span class="niv-chip niv-chip-{{ $cls }}">{{ $f->format }}</span></td>
                            <td class="text-right">{{ number_format($f->qty, 0) }}</td>
                            <td class="text-right niv-money">${{ number_format($f->revenue, 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="niv-muted">No format-tagged sales yet. Once products have a format set, this populates.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="niv-card">
                <h3><i class="fa fa-boxes"></i> Collections Bought <span class="niv-sub">what we bought — last 30 days</span></h3>
                <table>
                    <thead>
                        <tr style="color:#7b8796; text-transform:uppercase; font-size:11px; letter-spacing:.5px;">
                            <th>Date</th>
                            <th>Seller</th>
                            <th>Store</th>
                            <th>Employee</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($recent_purchases as $p)
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($p->transaction_date)->format('M j') }}</td>
                            <td>{{ $p->supplier }}</td>
                            <td>{{ $p->location_name }}</td>
                            <td class="niv-muted">{{ trim($p->employee) ?: '—' }}</td>
                            <td class="text-right niv-money">${{ number_format($p->final_total, 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="niv-muted">No purchases yet in the last 30 days.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="col-md-6">
            <div class="niv-card">
                <h3><i class="fa fa-gem"></i> Most Expensive Items Sold <span class="niv-sub">last 7 days, top 10 by unit price</span></h3>
                <table>
                    <thead>
                        <tr style="color:#7b8796; text-transform:uppercase; font-size:11px; letter-spacing:.5px;">
                            <th>Item</th>
                            <th>Format</th>
                            <th>Store</th>
                            <th class="text-right">Unit price</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($top_expensive_items as $t)
                        <tr>
                            <td>
                                <strong>{{ $t->artist ? $t->artist . ' — ' : '' }}{{ $t->name }}</strong>
                                <div class="niv-muted">{{ \Carbon\Carbon::parse($t->transaction_date)->format('M j, h:i A') }}</div>
                            </td>
                            <td>@if($t->format)<span class="niv-chip niv-chip-{{ strtolower(preg_replace('/[^a-z0-9]/i','-', $t->format)) }}">{{ $t->format }}</span>@endif</td>
                            <td>{{ $t->location_name }}</td>
                            <td class="text-right niv-money">${{ number_format($t->unit_price_inc_tax, 0) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="niv-muted">No sales in the last 7 days.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- Active customer wants — things to keep an eye out for in new inventory --}}
    <div class="row">
        <div class="col-md-12">
            <div class="niv-card" style="border-left:4px solid #dc2626;">
                <h3 style="color:#991b1b;"><i class="fa fa-hand-point-right"></i> Active Customer Wants <span class="niv-sub">call-me-when-it-comes-in list — <a href="{{ action('CustomerWantController@index') }}">see all / add new →</a></span></h3>
                <table>
                    <thead>
                        <tr style="color:#7b8796; text-transform:uppercase; font-size:11px; letter-spacing:.5px;">
                            <th>Priority</th>
                            <th>Artist</th>
                            <th>Title</th>
                            <th>Format</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Store</th>
                            <th>Added</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($active_wants as $w)
                        <tr>
                            <td>
                                @if($w->priority === 'high')
                                    <span class="label label-danger">HIGH</span>
                                @elseif($w->priority === 'low')
                                    <span class="label label-default">low</span>
                                @else
                                    <span class="label label-info">normal</span>
                                @endif
                            </td>
                            <td>{{ $w->artist }}</td>
                            <td><strong>{{ $w->title }}</strong></td>
                            <td>@if($w->format)<span class="niv-chip niv-chip-{{ strtolower(preg_replace('/[^a-z0-9]/i','-', $w->format)) }}">{{ $w->format }}</span>@endif</td>
                            <td>{{ trim($w->customer) ?: '—' }}</td>
                            <td>{{ $w->phone }}</td>
                            <td>{{ $w->location_name }}</td>
                            <td class="niv-muted">{{ \Carbon\Carbon::parse($w->created_at)->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="niv-muted">No active wants. <a href="{{ action('CustomerWantController@create') }}">Add one</a> when a customer asks for something you don't have in stock.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="niv-card">
                <h3><i class="fa fa-balance-scale"></i> Avg $ per Transaction — by Employee <span class="niv-sub">month-to-date, 3+ transactions only</span></h3>
                <table>
                    <thead>
                        <tr style="color:#7b8796; text-transform:uppercase; font-size:11px; letter-spacing:.5px;">
                            <th>Employee</th>
                            <th class="text-right"># transactions</th>
                            <th class="text-right">Total $</th>
                            <th class="text-right">Avg $ / tx</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($avg_per_employee as $a)
                        <tr>
                            <td><strong>{{ trim($a->employee) ?: '(unknown)' }}</strong></td>
                            <td class="text-right">{{ number_format($a->tx_count) }}</td>
                            <td class="text-right">${{ number_format($a->total, 0) }}</td>
                            <td class="text-right niv-money">${{ number_format($a->avg_tx, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="niv-muted">No sales in this window yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="niv-card" style="border-color:#ef4444;">
                <h3 style="color:#991b1b;"><i class="fa fa-heart"></i> Active Customer Wants <span class="niv-sub">{{ $active_wants_count ?? 0 }} open — flag high priority</span>
                    <a href="{{ action('CustomerWantController@index') }}" class="btn btn-xs btn-default pull-right" style="margin-top:-3px;"><i class="fa fa-list"></i> See all</a>
                    <a href="{{ action('CustomerWantController@create') }}" class="btn btn-xs btn-primary pull-right" style="margin-top:-3px; margin-right:6px;"><i class="fa fa-plus"></i> Add want</a>
                </h3>
                <table>
                    <thead>
                        <tr style="color:#7b8796; text-transform:uppercase; font-size:11px; letter-spacing:.5px;">
                            <th>Priority</th>
                            <th>Artist</th>
                            <th>Title</th>
                            <th>Format</th>
                            <th>Customer</th>
                            <th>Phone</th>
                            <th>Store</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($active_wants as $w)
                        <tr @if($w->priority==='high') style="background:#fef2f2;" @endif>
                            <td>
                                @if($w->priority==='high')<span class="label label-danger">HIGH</span>
                                @elseif($w->priority==='low')<span class="label label-default">low</span>
                                @else<span class="label label-info">normal</span>@endif
                            </td>
                            <td>{{ $w->artist }}</td>
                            <td><strong>{{ $w->title }}</strong>@if($w->notes)<div class="niv-muted"><small>{{ $w->notes }}</small></div>@endif</td>
                            <td>{{ $w->format }}</td>
                            <td>{{ trim($w->customer) ?: '—' }}</td>
                            <td>{{ $w->phone }}</td>
                            <td>{{ $w->location_name }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="niv-muted">No active wants. When a customer asks you to call them when something comes in, add it here.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="niv-card">
                <h3><i class="fa fa-history"></i> Last 15 Items Sold <span class="niv-sub">live, across all stores</span></h3>
                <table>
                    <thead>
                        <tr style="color:#7b8796; text-transform:uppercase; font-size:11px; letter-spacing:.5px;">
                            <th>Time</th>
                            <th>Item</th>
                            <th>Format</th>
                            <th>Store</th>
                            <th>Cashier</th>
                            <th class="text-right">Qty</th>
                            <th class="text-right">Price</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($last_sold_items as $l)
                        <tr>
                            <td class="niv-muted">{{ \Carbon\Carbon::parse($l->transaction_date)->format('M j, h:i A') }}</td>
                            <td><strong>{{ $l->artist ? $l->artist . ' — ' : '' }}{{ $l->name }}</strong></td>
                            <td>@if($l->format)<span class="niv-chip niv-chip-{{ strtolower(preg_replace('/[^a-z0-9]/i','-', $l->format)) }}">{{ $l->format }}</span>@endif</td>
                            <td>{{ $l->location_name }}</td>
                            <td class="niv-muted">{{ trim($l->employee) ?: '—' }}</td>
                            <td class="text-right">{{ number_format($l->quantity, 0) }}</td>
                            <td class="text-right niv-money">${{ number_format($l->unit_price_inc_tax, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="niv-muted">No sales yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</section>
@endif
<!-- Main content -->
<section class="content content-custom no-print">
    <br>
    @if(auth()->user()->can('dashboard.data'))
        @if($is_admin)
        	<div class="row">
                <div class="col-md-4 col-xs-12">
                    @if(count($all_locations) > 1)
                        {!! Form::select('dashboard_location', $all_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.select_location'), 'id' => 'dashboard_location']); !!}
                    @endif
                </div>
        		<div class="col-md-8 col-xs-12">
                    <div class="form-group pull-right d-flex justify-content-between">
                        <div class="input-group">
                            <button type="button" class="btn btn-primary" id="dashboard_date_filter">
                                <span>
                                    <i class="fa fa-calendar"></i> {{ __('messages.filter_by_date') }}
                                </span>
                                <i class="fa fa-caret-down"></i>
                            </button>
                        </div>
                        
                    </div>
                    <div class="input-group pull-center">
                        <a href="{{ route('products.create') }}" class="btn btn-success">
                            Add Product
                        </a>
                        <a href="{{ route('product.massCreate') }}" class="btn btn-success">
                            Bulk Add Product
                        </a>
                        <a href="{{ route('labels.show') }}" class="btn btn-success">
                            Print Labels
                        </a>
                    </div>
                </div>

        	</div>
    	   <br>
    	   <div class="row row-custom">
                <!-- /.col -->
                <div class="col-md-3 col-sm-6 col-xs-12 col-custom">
                   <div class="info-box info-box-new-style">
                        <span class="info-box-icon bg-aqua"><i class="ion ion-ios-cart-outline"></i></span>

                        <div class="info-box-content">
                          <span class="info-box-text">{{ __('home.total_sell') }}</span>
                          <span class="info-box-number total_sell"><i class="fas fa-sync fa-spin fa-fw margin-bottom"></i></span>
                        </div>
                        <!-- /.info-box-content -->
                   </div>
                  <!-- /.info-box -->
                </div>
                <div class="col-md-3 col-sm-6 col-xs-12 col-custom">
                    <div class="info-box info-box-new-style">
                       <span class="info-box-icon bg-green">
                            <i class="ion ion-ios-paper-outline"></i>
                            
                       </span>

                        <div class="info-box-content">
                          <span class="info-box-text">{{ __('lang_v1.net') }} @show_tooltip(__('lang_v1.net_home_tooltip'))</span>
                          <span class="info-box-number net"><i class="fas fa-sync fa-spin fa-fw margin-bottom"></i></span>
                        </div>
                        <!-- /.info-box-content -->
                    </div>
                  <!-- /.info-box -->
                </div>
                <div class="col-md-3 col-sm-6 col-xs-12 col-custom">
                    <div class="info-box info-box-new-style">
                       <span class="info-box-icon bg-yellow">
                            <i class="ion ion-ios-paper-outline"></i>
                            <i class="fa fa-exclamation"></i>
                       </span>

                        <div class="info-box-content">
                          <span class="info-box-text">{{ __('home.invoice_due') }}</span>
                          <span class="info-box-number invoice_due"><i class="fas fa-sync fa-spin fa-fw margin-bottom"></i></span>
                        </div>
                        <!-- /.info-box-content -->
                    </div>
                  <!-- /.info-box -->
                </div>

                <div class="col-md-3 col-sm-6 col-xs-12 col-custom">
                    <div class="info-box info-box-new-style">
                       <span class="info-box-icon bg-red text-white">
                            <i class="fas fa-exchange-alt"></i>
                       </span>

                        <div class="info-box-content">
                          <span class="info-box-text">{{ __('lang_v1.total_sell_return') }}</span>
                          <span class="info-box-number total_sell_return"><i class="fas fa-sync fa-spin fa-fw margin-bottom"></i></span>
                        </div>
                        <!-- /.info-box-content -->
                    </div>
                  <!-- /.info-box -->
                </div>
    	    <!-- /.col -->
            </div>
          	<div class="row row-custom">
                <div class="col-md-3 col-sm-6 col-xs-12 col-custom">
                   <div class="info-box info-box-new-style">
                        <span class="info-box-icon bg-aqua"><i class="ion ion-cash"></i></span>

                        <div class="info-box-content">
                          <span class="info-box-text">{{ __('home.total_purchase') }}</span>
                          <span class="info-box-number total_purchase"><i class="fas fa-sync fa-spin fa-fw margin-bottom"></i></span>
                        </div>
                        <!-- /.info-box-content -->
                   </div>
                   <!-- /.info-box -->
                </div>
                <!-- /.col -->

                <div class="col-md-3 col-sm-6 col-xs-12 col-custom">
                   <div class="info-box info-box-new-style">
                        <span class="info-box-icon bg-yellow">
                            <i class="fa fa-dollar"></i>
                            <i class="fa fa-exclamation"></i>
                        </span>

                        <div class="info-box-content">
                          <span class="info-box-text">{{ __('home.purchase_due') }}</span>
                          <span class="info-box-number purchase_due"><i class="fas fa-sync fa-spin fa-fw margin-bottom"></i></span>
                        </div>
                        <!-- /.info-box-content -->
                   </div>
                  <!-- /.info-box -->
                </div>
                <!-- /.col -->
                <div class="col-md-3 col-sm-6 col-xs-12 col-custom">
                    <div class="info-box info-box-new-style">
                       <span class="info-box-icon bg-red text-white">
                            <i class="fas fa-undo-alt"></i>
                       </span>

                        <div class="info-box-content">
                          <span class="info-box-text">{{ __('lang_v1.total_purchase_return') }}</span>
                          <span class="info-box-number total_purchase_return"><i class="fas fa-sync fa-spin fa-fw margin-bottom"></i></span>
                        </div>
                        <!-- /.info-box-content -->
                    </div>
                  <!-- /.info-box -->
                </div>

                <!-- expense -->
                <div class="col-md-3 col-sm-6 col-xs-12 col-custom">
                    <div class="info-box info-box-new-style">
                        <span class="info-box-icon bg-red">
                          <i class="fas fa-minus-circle"></i>
                        </span>

                        <div class="info-box-content">
                          <span class="info-box-text">
                            {{ __('lang_v1.expense') }}
                          </span>
                          <span class="info-box-number total_expense"><i class="fas fa-sync fa-spin fa-fw margin-bottom"></i></span>
                        </div>
                        <!-- /.info-box-content -->
                    </div>
                  <!-- /.info-box -->
                </div>
            </div>
            @if(!empty($widgets['after_sale_purchase_totals']))
                @foreach($widgets['after_sale_purchase_totals'] as $widget)
                    {!! $widget !!}
                @endforeach
            @endif
        @endif 
        <!-- end is_admin check -->
         @if(auth()->user()->can('sell.view') || auth()->user()->can('direct_sell.view'))
            @if(!empty($all_locations))
              	<!-- sales chart start -->
              	<div class="row">
              		<div class="col-sm-12">
                        @component('components.widget', ['class' => 'box-primary', 'title' => __('home.sells_last_30_days')])
                          {!! $sells_chart_1->container() !!}
                        @endcomponent
              		</div>
              	</div>
            @endif
            @if(!empty($widgets['after_sales_last_30_days']))
                @foreach($widgets['after_sales_last_30_days'] as $widget)
                    {!! $widget !!}
                @endforeach
            @endif
            @if(!empty($all_locations))
              	<div class="row">
              		<div class="col-sm-12">
                        @component('components.widget', ['class' => 'box-primary', 'title' => __('home.sells_current_fy')])
                          {!! $sells_chart_2->container() !!}
                        @endcomponent
              		</div>
              	</div>
            @endif
        @endif
      	<!-- sales chart end -->
        @if(!empty($widgets['after_sales_current_fy']))
            @foreach($widgets['after_sales_current_fy'] as $widget)
                {!! $widget !!}
            @endforeach
        @endif
      	<!-- products less than alert quntity -->
      	<div class="row">
            @if(auth()->user()->can('sell.view') || auth()->user()->can('direct_sell.view'))
                <div class="col-sm-6">
                    @component('components.widget', ['class' => 'box-warning'])
                      @slot('icon')
                        <i class="fa fa-exclamation-triangle text-yellow" aria-hidden="true"></i>
                      @endslot
                      @slot('title')
                        {{ __('lang_v1.sales_payment_dues') }} @show_tooltip(__('lang_v1.tooltip_sales_payment_dues'))
                      @endslot
                        <div class="row">
                            @if(count($all_locations) > 1)
                                <div class="col-md-6 col-sm-6 col-md-offset-6 mb-10">
                                    {!! Form::select('sales_payment_dues_location', $all_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.select_location'), 'id' => 'sales_payment_dues_location']); !!}
                                </div>
                            @endif
                            <div class="col-md-12">
                                <table class="table table-bordered table-striped" id="sales_payment_dues_table" style="width: 100%;">
                                    <thead>
                                      <tr>
                                        <th>@lang( 'contact.customer' )</th>
                                        <th>@lang( 'sale.invoice_no' )</th>
                                        <th>@lang( 'home.due_amount' )</th>
                                        <th>@lang( 'messages.action' )</th>
                                      </tr>
                                    </thead>
                                </table>
                            </div>
                        </div>
                    @endcomponent
                </div>
            @endif
            @can('purchase.view')
                <div class="col-sm-6">
                    @component('components.widget', ['class' => 'box-warning'])
                    @slot('icon')
                    <i class="fa fa-exclamation-triangle text-yellow" aria-hidden="true"></i>
                    @endslot
                    @slot('title')
                    {{ __('lang_v1.purchase_payment_dues') }} @show_tooltip(__('tooltip.payment_dues'))
                    @endslot
                    <div class="row">
                        @if(count($all_locations) > 1)
                            <div class="col-md-6 col-sm-6 col-md-offset-6 mb-10">
                                {!! Form::select('purchase_payment_dues_location', $all_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.select_location'), 'id' => 'purchase_payment_dues_location']); !!}
                            </div>
                        @endif
                        <div class="col-md-12">
                            <table class="table table-bordered table-striped" id="purchase_payment_dues_table" style="width: 100%;">
                                <thead>
                                  <tr>
                                    <th>@lang( 'purchase.supplier' )</th>
                                    <th>@lang( 'purchase.ref_no' )</th>
                                    <th>@lang( 'home.due_amount' )</th>
                                    <th>@lang( 'messages.action' )</th>
                                  </tr>
                                </thead>
                            </table>
                        </div>
                    </div>
                    @endcomponent
                </div>
            @endcan
        </div>
        @can('stock_report.view')
            <div class="row">
                <div class="@if((session('business.enable_product_expiry') != 1) && auth()->user()->can('stock_report.view')) col-sm-12 @else col-sm-6 @endif">
                    @component('components.widget', ['class' => 'box-warning'])
                      @slot('icon')
                        <i class="fa fa-exclamation-triangle text-yellow" aria-hidden="true"></i>
                      @endslot
                      @slot('title')
                        {{ __('home.product_stock_alert') }} @show_tooltip(__('tooltip.product_stock_alert'))
                      @endslot
                      <div class="row">
                            @if(count($all_locations) > 1)
                                <div class="col-md-6 col-sm-6 col-md-offset-6 mb-10">
                                    {!! Form::select('stock_alert_location', $all_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.select_location'), 'id' => 'stock_alert_location']); !!}
                                </div>
                            @endif
                            <div class="col-md-12">
                                <table class="table table-bordered table-striped" id="stock_alert_table" style="width: 100%;">
                                    <thead>
                                      <tr>
                                        <th>@lang( 'sale.product' )</th>
                                        <th>@lang( 'business.location' )</th>
                                        <th>@lang( 'report.current_stock' )</th>
                                      </tr>
                                    </thead>
                                </table>
                            </div>
                      </div>
                    @endcomponent
                </div>
                @if(session('business.enable_product_expiry') == 1)
                    <div class="col-sm-6">
                        @component('components.widget', ['class' => 'box-warning'])
                          @slot('icon')
                            <i class="fa fa-exclamation-triangle text-yellow" aria-hidden="true"></i>
                          @endslot
                          @slot('title')
                            {{ __('home.stock_expiry_alert') }} @show_tooltip( __('tooltip.stock_expiry_alert', [ 'days' =>session('business.stock_expiry_alert_days', 30) ]) )
                          @endslot
                          <input type="hidden" id="stock_expiry_alert_days" value="{{ \Carbon::now()->addDays(session('business.stock_expiry_alert_days', 30))->format('Y-m-d') }}">
                          <table class="table table-bordered table-striped" id="stock_expiry_alert_table">
                            <thead>
                              <tr>
                                  <th>@lang('business.product')</th>
                                  <th>@lang('business.location')</th>
                                  <th>@lang('report.stock_left')</th>
                                  <th>@lang('product.expires_in')</th>
                              </tr>
                            </thead>
                          </table>
                        @endcomponent
                    </div>
                @endif
      	    </div>
        @endcan
        @if(auth()->user()->can('so.view_all') || auth()->user()->can('so.view_own'))
            <div class="row" @if(!auth()->user()->can('dashboard.data'))style="margin-top: 190px !important;"@endif>
                <div class="col-sm-12">
                    @component('components.widget', ['class' => 'box-warning'])
                        @slot('icon')
                            <i class="fas fa-list-alt text-yellow fa-lg" aria-hidden="true"></i>
                        @endslot
                        @slot('title')
                            {{__('lang_v1.sales_order')}}
                        @endslot
                        <div class="row">
                        @if(count($all_locations) > 1)
                            <div class="col-md-4 col-sm-6 col-md-offset-8 mb-10">
                                {!! Form::select('so_location', $all_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.select_location'), 'id' => 'so_location']); !!}
                            </div>
                        @endif
                            <div class="col-md-12">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped ajax_view" id="sales_order_table">
                                        <thead>
                                            <tr>
                                                <th>@lang('messages.action')</th>
                                                <th>@lang('messages.date')</th>
                                                <th>@lang('restaurant.order_no')</th>
                                                <th>@lang('sale.customer_name')</th>
                                                <th>@lang('lang_v1.contact_no')</th>
                                                <th>@lang('sale.location')</th>
                                                <th>@lang('sale.status')</th>
                                                <th>@lang('lang_v1.shipping_status')</th>
                                                <th>@lang('lang_v1.quantity_remaining')</th>
                                                <th>@lang('lang_v1.added_by')</th>
                                            </tr>
                                        </thead>
                                    </table>
                                </div>
                            </div>
                        </div>
                    @endcomponent
                </div>
            </div>
        @endif
        @if(!empty($common_settings['enable_purchase_order']) && (auth()->user()->can('purchase_order.view_all') || auth()->user()->can('purchase_order.view_own')) )
            <div class="row" @if(!auth()->user()->can('dashboard.data'))style="margin-top: 190px !important;"@endif>
                <div class="col-sm-12">
                    @component('components.widget', ['class' => 'box-warning'])
                      @slot('icon')
                          <i class="fas fa-list-alt text-yellow fa-lg" aria-hidden="true"></i>
                      @endslot
                      @slot('title')
                          @lang('lang_v1.purchase_order')
                      @endslot
                        <div class="row">
                        @if(count($all_locations) > 1)
                            <div class="col-md-4 col-sm-6 col-md-offset-8 mb-10">
                                {!! Form::select('po_location', $all_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.select_location'), 'id' => 'po_location']); !!}
                            </div>
                        @endif
                            <div class="col-md-12">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-striped ajax_view" id="purchase_order_table" style="width: 100%;">
                                      <thead>
                                          <tr>
                                              <th>@lang('messages.action')</th>
                                              <th>@lang('messages.date')</th>
                                              <th>@lang('purchase.ref_no')</th>
                                              <th>@lang('purchase.location')</th>
                                              <th>@lang('purchase.supplier')</th>
                                              <th>@lang('sale.status')</th>
                                              <th>@lang('lang_v1.quantity_remaining')</th>
                                              <th>@lang('lang_v1.added_by')</th>
                                          </tr>
                                      </thead>
                                    </table>
                                </div>
                            </div>
                        </div>
                    @endcomponent
                </div>
            </div>
        @endif

        @if(auth()->user()->can('access_pending_shipments_only') || auth()->user()->can('access_shipping') || auth()->user()->can('access_own_shipping') )
            @component('components.widget', ['class' => 'box-warning'])
              @slot('icon')
                  <i class="fas fa-list-alt text-yellow fa-lg" aria-hidden="true"></i>
              @endslot
              @slot('title')
                  @lang('lang_v1.pending_shipments')
              @endslot
                <div class="row">
                    @if(count($all_locations) > 1)
                        <div class="col-md-4 col-sm-6 col-md-offset-8 mb-10">
                            {!! Form::select('pending_shipments_location', $all_locations, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.select_location'), 'id' => 'pending_shipments_location']); !!}
                        </div>
                    @endif
                    <div class="col-md-12">  
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped ajax_view" id="shipments_table">
                                <thead>
                                    <tr>
                                        <th>@lang('messages.action')</th>
                                        <th>@lang('messages.date')</th>
                                        <th>@lang('sale.invoice_no')</th>
                                        <th>@lang('sale.customer_name')</th>
                                        <th>@lang('lang_v1.contact_no')</th>
                                        <th>@lang('sale.location')</th>
                                        <th>@lang('lang_v1.shipping_status')</th>
                                        @if(!empty($custom_labels['shipping']['custom_field_1']))
                                            <th>
                                                {{$custom_labels['shipping']['custom_field_1']}}
                                            </th>
                                        @endif
                                        @if(!empty($custom_labels['shipping']['custom_field_2']))
                                            <th>
                                                {{$custom_labels['shipping']['custom_field_2']}}
                                            </th>
                                        @endif
                                        @if(!empty($custom_labels['shipping']['custom_field_3']))
                                            <th>
                                                {{$custom_labels['shipping']['custom_field_3']}}
                                            </th>
                                        @endif
                                        @if(!empty($custom_labels['shipping']['custom_field_4']))
                                            <th>
                                                {{$custom_labels['shipping']['custom_field_4']}}
                                            </th>
                                        @endif
                                        @if(!empty($custom_labels['shipping']['custom_field_5']))
                                            <th>
                                                {{$custom_labels['shipping']['custom_field_5']}}
                                            </th>
                                        @endif
                                        <th>@lang('sale.payment_status')</th>
                                        <th>@lang('restaurant.service_staff')</th>
                                    </tr>
                                </thead>
                            </table>
                        </div>
                    </div> 
                </div>
            @endcomponent
        @endif

        @if(auth()->user()->can('account.access') && config('constants.show_payments_recovered_today') == true)
            @component('components.widget', ['class' => 'box-warning'])
              @slot('icon')
                  <i class="fas fa-money-bill-alt text-yellow fa-lg" aria-hidden="true"></i>
              @endslot
              @slot('title')
                  @lang('lang_v1.payment_recovered_today')
              @endslot
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="cash_flow_table">
                        <thead>
                            <tr>
                                <th>@lang( 'messages.date' )</th>
                                <th>@lang( 'account.account' )</th>
                                <th>@lang( 'lang_v1.description' )</th>
                                <th>@lang( 'lang_v1.payment_method' )</th>
                                <th>@lang( 'lang_v1.payment_details' )</th>
                                <th>@lang('account.credit')</th>
                                <th>@lang( 'lang_v1.account_balance' ) @show_tooltip(__('lang_v1.account_balance_tooltip'))</th>
                                <th>@lang( 'lang_v1.total_balance' ) @show_tooltip(__('lang_v1.total_balance_tooltip'))</th>
                            </tr>
                        </thead>
                        <tfoot>
                            <tr class="bg-gray font-17 footer-total text-center">
                                <td colspan="5"><strong>@lang('sale.total'):</strong></td>
                                <td class="footer_total_credit"></td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endcomponent
        @endif

        @if(!empty($widgets['after_dashboard_reports']))
          @foreach($widgets['after_dashboard_reports'] as $widget)
            {!! $widget !!}
          @endforeach
        @endif

    @endif
   <!-- can('dashboard.data') end -->
</section>
<!-- /.content -->
<div class="modal fade payment_modal" tabindex="-1" role="dialog" 
    aria-labelledby="gridSystemModalLabel">
</div>
<div class="modal fade edit_pso_status_modal" tabindex="-1" role="dialog"></div>
<div class="modal fade edit_payment_modal" tabindex="-1" role="dialog" 
    aria-labelledby="gridSystemModalLabel">
</div>
@stop
@section('javascript')
    <script src="{{ asset('js/home.js?v=' . $asset_v) }}"></script>
    <script src="{{ asset('js/payment.js?v=' . $asset_v) }}"></script>
    @includeIf('sales_order.common_js')
    @includeIf('purchase_order.common_js')
    @if(!empty($all_locations))
        {!! $sells_chart_1->script() !!}
        {!! $sells_chart_2->script() !!}
    @endif
    <script type="text/javascript">
        $(document).ready( function(){
        sales_order_table = $('#sales_order_table').DataTable({
          processing: true,
          serverSide: true,
          scrollY: "75vh",
          scrollX:        true,
          scrollCollapse: true,
          aaSorting: [[1, 'desc']],
          "ajax": {
              "url": '{{action("SellController@index")}}?sale_type=sales_order',
              "data": function ( d ) {
                    d.for_dashboard_sales_order = true;

                    if ($('#so_location').length > 0) {
                        d.location_id = $('#so_location').val();
                    }
                }
          },
          columnDefs: [ {
              "targets": 7,
              "orderable": false,
              "searchable": false
          } ],
          columns: [
              { data: 'action', name: 'action'},
              { data: 'transaction_date', name: 'transaction_date'  },
              { data: 'invoice_no', name: 'invoice_no'},
              { data: 'conatct_name', name: 'conatct_name'},
              { data: 'mobile', name: 'contacts.mobile'},
              { data: 'business_location', name: 'bl.name'},
              { data: 'status', name: 'status'},
              { data: 'shipping_status', name: 'shipping_status'},
              { data: 'so_qty_remaining', name: 'so_qty_remaining', "searchable": false},
              { data: 'added_by', name: 'u.first_name'},
          ]
        });

        @if(auth()->user()->can('account.access') && config('constants.show_payments_recovered_today') == true)

            // Cash Flow Table
            cash_flow_table = $('#cash_flow_table').DataTable({
                processing: true,
                serverSide: true,
                "ajax": {
                        "url": "{{action("AccountController@cashFlow")}}",
                        "data": function ( d ) {
                            d.type = 'credit';
                            d.only_payment_recovered = true;
                        }
                    },
                "ordering": false,
                "searching": false,
                columns: [
                    {data: 'operation_date', name: 'operation_date'},
                    {data: 'account_name', name: 'account_name'},
                    {data: 'sub_type', name: 'sub_type'},
                    {data: 'method', name: 'TP.method'},
                    {data: 'payment_details', name: 'payment_details', searchable: false},
                    {data: 'credit', name: 'amount'},
                    {data: 'balance', name: 'balance'},
                    {data: 'total_balance', name: 'total_balance'},
                ],
                "fnDrawCallback": function (oSettings) {
                    __currency_convert_recursively($('#cash_flow_table'));
                },
                "footerCallback": function ( row, data, start, end, display ) {
                    var footer_total_credit = 0;

                    for (var r in data){
                        footer_total_credit += $(data[r].credit).data('orig-value') ? parseFloat($(data[r].credit).data('orig-value')) : 0;
                    }
                    $('.footer_total_credit').html(__currency_trans_from_en(footer_total_credit));
                }
            });
        @endif

        $('#so_location').change( function(){
            sales_order_table.ajax.reload();
        });
        @if(!empty($common_settings['enable_purchase_order']))
          //Purchase table
          purchase_order_table = $('#purchase_order_table').DataTable({
              processing: true,
              serverSide: true,
              aaSorting: [[1, 'desc']],
              scrollY: "75vh",
              scrollX:        true,
              scrollCollapse: true,
              ajax: {
                  url: '{{action("PurchaseOrderController@index")}}',
                  data: function(d) {
                      d.from_dashboard = true;

                        if ($('#po_location').length > 0) {
                            d.location_id = $('#po_location').val();
                        }
                  },
              },
              columns: [
                  { data: 'action', name: 'action', orderable: false, searchable: false },
                  { data: 'transaction_date', name: 'transaction_date' },
                  { data: 'ref_no', name: 'ref_no' },
                  { data: 'location_name', name: 'BS.name' },
                  { data: 'name', name: 'contacts.name' },
                  { data: 'status', name: 'transactions.status' },
                  { data: 'po_qty_remaining', name: 'po_qty_remaining', "searchable": false},
                  { data: 'added_by', name: 'u.first_name' }
              ]
            })

            $('#po_location').change( function(){
                purchase_order_table.ajax.reload();
            });
        @endif

        sell_table = $('#shipments_table').DataTable({
            processing: true,
            serverSide: true,
            aaSorting: [[1, 'desc']],
            scrollY:        "75vh",
            scrollX:        true,
            scrollCollapse: true,
            "ajax": {
                "url": '{{action("SellController@index")}}',
                "data": function ( d ) {
                    d.only_pending_shipments = true;
                    if ($('#pending_shipments_location').length > 0) {
                        d.location_id = $('#pending_shipments_location').val();
                    }
                }
            },
            columns: [
                { data: 'action', name: 'action', searchable: false, orderable: false},
                { data: 'transaction_date', name: 'transaction_date'  },
                { data: 'invoice_no', name: 'invoice_no'},
                { data: 'conatct_name', name: 'conatct_name'},
                { data: 'mobile', name: 'contacts.mobile'},
                { data: 'business_location', name: 'bl.name'},
                { data: 'shipping_status', name: 'shipping_status'},
                @if(!empty($custom_labels['shipping']['custom_field_1']))
                    { data: 'shipping_custom_field_1', name: 'shipping_custom_field_1'},
                @endif
                @if(!empty($custom_labels['shipping']['custom_field_2']))
                    { data: 'shipping_custom_field_2', name: 'shipping_custom_field_2'},
                @endif
                @if(!empty($custom_labels['shipping']['custom_field_3']))
                    { data: 'shipping_custom_field_3', name: 'shipping_custom_field_3'},
                @endif
                @if(!empty($custom_labels['shipping']['custom_field_4']))
                    { data: 'shipping_custom_field_4', name: 'shipping_custom_field_4'},
                @endif
                @if(!empty($custom_labels['shipping']['custom_field_5']))
                    { data: 'shipping_custom_field_5', name: 'shipping_custom_field_5'},
                @endif
                { data: 'payment_status', name: 'payment_status'},
                { data: 'waiter', name: 'ss.first_name', @if(empty($is_service_staff_enabled)) visible: false @endif }
            ],
            "fnDrawCallback": function (oSettings) {
                __currency_convert_recursively($('#sell_table'));
            },
            createdRow: function( row, data, dataIndex ) {
                $( row ).find('td:eq(4)').attr('class', 'clickable_td');
            }
        });

        $('#pending_shipments_location').change( function(){
            sell_table.ajax.reload();
        });
    });
    </script>
@endsection

