@extends('layouts.app')
@section('title', $employee . ' — Barcoded Items That Sold')

@section('content')
<section class="content-header">
    <h1>
        {{ $employee }} <small>barcoded items that sold</small>
    </h1>
    <p>
        <a href="{{ action('ReportController@revenueByEmployeeBarcoding') . '?' . http_build_query(['start_date' => $start_date, 'end_date' => $end_date]) }}">
            <i class="fa fa-arrow-left"></i> Back to all employees
        </a>
    </p>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-3">
            <div class="info-box bg-aqua">
                <span class="info-box-icon"><i class="fa fa-barcode"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Barcoded (lifetime)</span>
                    <span class="info-box-number">{{ number_format($totals->barcoded_lifetime) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-yellow">
                <span class="info-box-icon"><i class="fa fa-shopping-cart"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Items Sold (in period)</span>
                    <span class="info-box-number">{{ number_format($totals->items_sold) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-blue">
                <span class="info-box-icon"><i class="fa fa-dollar-sign"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Revenue / Item</span>
                    <span class="info-box-number">${{ number_format($totals->revenue_per_item, 2) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-green">
                <span class="info-box-icon"><i class="fa fa-money-bill"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Total Revenue</span>
                    <span class="info-box-number">${{ number_format($totals->total_revenue, 2) }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Date Range</h3></div>
        <div class="box-body">
            <form method="GET" action="{{ action('ReportController@revenueByEmployeeBarcodingDetail', ['user_id' => $user->id]) }}" class="row">
                <div class="col-md-3">
                    <label>Start Date</label>
                    <input type="date" class="form-control" name="start_date" value="{{ $start_date }}">
                </div>
                <div class="col-md-3">
                    <label>End Date</label>
                    <input type="date" class="form-control" name="end_date" value="{{ $end_date }}">
                </div>
                <div class="col-md-3">
                    <label style="display:block;">&nbsp;</label>
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </form>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">By Category</h3>
            <small class="text-muted">Items barcoded lifetime per category, paired with sales in the selected window.</small>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Subcategory</th>
                        <th class="text-right">Items Barcoded</th>
                        <th class="text-right">Items Sold</th>
                        <th class="text-right" title="Lifetime: items sold ÷ items barcoded">Sell-through</th>
                        <th class="text-right">Total Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($by_category as $c)
                        <tr>
                            <td>{{ $c->category_name }}</td>
                            <td>{{ $c->subcategory_name ?: '—' }}</td>
                            <td class="text-right">{{ number_format($c->barcoded_count) }}</td>
                            <td class="text-right">{{ number_format($c->items_sold) }}</td>
                            <td class="text-right">{{ number_format($c->sell_through_pct, 1) }}%</td>
                            <td class="text-right">${{ number_format($c->total_revenue, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted">No barcoded items found.</td></tr>
                    @endforelse
                </tbody>
                @if($by_category->isNotEmpty())
                @php
                    $cat_tot_bar = $by_category->sum('barcoded_count');
                    $cat_tot_life_sold = $by_category->sum('lifetime_items_sold');
                    $cat_overall_st = $cat_tot_bar > 0 ? ($cat_tot_life_sold / $cat_tot_bar) * 100 : 0;
                @endphp
                <tfoot>
                    <tr>
                        <th colspan="2" class="text-right">Total</th>
                        <th class="text-right">{{ number_format($cat_tot_bar) }}</th>
                        <th class="text-right">{{ number_format($by_category->sum('items_sold')) }}</th>
                        <th class="text-right">{{ number_format($cat_overall_st, 1) }}%</th>
                        <th class="text-right">${{ number_format($by_category->sum('total_revenue'), 2) }}</th>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">Items that sold {{ $start_date }} → {{ $end_date }}</h3>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>SKU</th>
                        <th>Barcoded On</th>
                        <th class="text-right">Qty Sold</th>
                        <th class="text-right">Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $it)
                        <tr>
                            <td>{{ $it->name }}</td>
                            <td><code>{{ $it->sku }}</code></td>
                            <td>{{ $it->created_at ? \Carbon::parse($it->created_at)->format('Y-m-d') : '—' }}</td>
                            <td class="text-right">{{ number_format((float) $it->qty_sold, 0) }}</td>
                            <td class="text-right">${{ number_format((float) $it->revenue, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted">No barcoded items by this employee sold in this window.</td></tr>
                    @endforelse
                </tbody>
                @if($items->isNotEmpty())
                <tfoot>
                    <tr>
                        <th colspan="3" class="text-right">Total</th>
                        <th class="text-right">{{ number_format((float) $items->sum('qty_sold'), 0) }}</th>
                        <th class="text-right">${{ number_format((float) $items->sum('revenue'), 2) }}</th>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>
    </div>
</section>
@endsection
