@extends('layouts.app')
@section('title', 'Selling Below Cost')

@section('content')
<section class="content-header">
    <h1>Selling Below Cost <small>items whose sell price is currently below what we paid</small></h1>
</section>

<section class="content">

    <div class="row">
        <div class="col-md-4">
            <div class="info-box bg-red">
                <span class="info-box-icon"><i class="fa fa-arrow-down"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Items below cost</span>
                    <span class="info-box-number">{{ number_format($totals->total_variations ?? 0) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="info-box bg-yellow">
                <span class="info-box-icon"><i class="fa fa-cubes"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Units on hand</span>
                    <span class="info-box-number">{{ number_format($totals->total_qty ?? 0, 2) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="info-box bg-maroon">
                <span class="info-box-icon"><i class="fa fa-dollar-sign"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Loss if all sold at current price</span>
                    <span class="info-box-number">${{ number_format($totals->total_exposure ?? 0, 2) }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Filters</h3></div>
        <div class="box-body">
            <form method="GET" action="{{ action('ReportController@sellingBelowCostReport') }}" class="row">
                <div class="col-md-4">
                    <label>Location</label>
                    <select name="location_id" class="form-control">
                        <option value="">All locations</option>
                        @foreach($business_locations as $id => $name)
                            <option value="{{ $id }}" @if((string)$location_id === (string)$id) selected @endif>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label style="display:block;">&nbsp;</label>
                    <div class="checkbox" style="margin-top:0;">
                        <label>
                            <input type="hidden" name="in_stock_only" value="0">
                            <input type="checkbox" name="in_stock_only" value="1" @if($in_stock_only) checked @endif>
                            In-stock items only
                        </label>
                    </div>
                </div>
                <div class="col-md-3">
                    <label style="display:block;">&nbsp;</label>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-filter"></i> Apply</button>
                    <a href="{{ action('ReportController@sellingBelowCostReport') }}" class="btn btn-default">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">Items priced below cost — click any column to sort</h3>
        </div>
        <div class="box-body table-responsive">
            <style>
                .sortable-col a { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
                .sortable-col a:hover { color: #1b6ca8; }
                .sortable-col .sort-arrow { opacity: 0.4; font-size: 11px; }
                .sortable-col.active a { color: #1b6ca8; font-weight: 700; }
                .sortable-col.active .sort-arrow { opacity: 1; }
            </style>
            @php
                $other = request()->except(['sort', 'dir', 'page']);
                $sortUrl = function ($col) use ($sort, $dir, $other) {
                    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
                    return action('ReportController@sellingBelowCostReport') . '?' . http_build_query(array_merge($other, ['sort' => $col, 'dir' => $newDir]));
                };
                $arrow = function ($col) use ($sort, $dir) {
                    if ($sort !== $col) return '<i class="fa fa-sort sort-arrow"></i>';
                    return $dir === 'asc' ? '<i class="fa fa-sort-up sort-arrow"></i>' : '<i class="fa fa-sort-down sort-arrow"></i>';
                };
                $colHead = function ($col, $label, $class = '') use ($sort, $sortUrl, $arrow) {
                    $active = $sort === $col ? 'active' : '';
                    return '<th class="sortable-col ' . $class . ' ' . $active . '"><a href="' . $sortUrl($col) . '">' . $label . ' ' . $arrow($col) . '</a></th>';
                };
            @endphp
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        {!! $colHead('artist', 'Artist') !!}
                        {!! $colHead('title', 'Title') !!}
                        {!! $colHead('format', 'Format') !!}
                        {!! $colHead('category', 'Category') !!}
                        {!! $colHead('sku', 'SKU') !!}
                        {!! $colHead('qty', 'Qty on hand', 'text-right') !!}
                        {!! $colHead('cost', 'Cost (paid)', 'text-right') !!}
                        {!! $colHead('price', 'Sell price', 'text-right') !!}
                        {!! $colHead('loss_per_unit', 'Loss / unit', 'text-right') !!}
                        {!! $colHead('exposure', 'Total loss exposure', 'text-right') !!}
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                    <tr>
                        <td>{{ $r->artist }}</td>
                        <td>{{ $r->name }}</td>
                        <td>{{ $r->format }}</td>
                        <td>{{ $r->category }}</td>
                        <td>{{ $r->sub_sku }}</td>
                        <td class="text-right">{{ number_format($r->qty_available, 2) }}</td>
                        <td class="text-right">${{ number_format($r->cost, 2) }}</td>
                        <td class="text-right">${{ number_format($r->selling_price, 2) }}</td>
                        <td class="text-right text-red"><strong>&minus;${{ number_format($r->loss_per_unit, 2) }}</strong></td>
                        <td class="text-right text-red"><strong>&minus;${{ number_format($r->exposure, 2) }}</strong></td>
                    </tr>
                    @empty
                    <tr><td colspan="10" class="text-center text-muted">🎉 Nothing is priced below cost{{ $in_stock_only ? ' among in-stock items' : '' }}.</td></tr>
                    @endforelse
                </tbody>
            </table>

            <div class="text-center">
                {{ $rows->links() }}
            </div>

            <small class="text-muted">
                Compares each item's current sell price (incl. tax) against the price we last paid for it (incl. tax). "Total loss exposure" is the per-unit loss times units on hand. Untick "In-stock items only" to audit pricing across the whole catalog.
            </small>
        </div>
    </div>
</section>
@stop
