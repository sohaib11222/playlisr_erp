@extends('layouts.app')
@section('title', 'Dead Stock Report')

@section('content')
<section class="content-header">
    <h1>Dead Stock Report <small>items with stock on hand that haven't sold recently</small></h1>
</section>

<section class="content">

    <div class="row">
        <div class="col-md-4">
            <div class="info-box bg-aqua">
                <span class="info-box-icon"><i class="fa fa-boxes"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Dead variations</span>
                    <span class="info-box-number">{{ number_format($totals->total_variations ?? 0) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="info-box bg-yellow">
                <span class="info-box-icon"><i class="fa fa-cubes"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Total units</span>
                    <span class="info-box-number">{{ number_format($totals->total_qty ?? 0, 2) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="info-box bg-red">
                <span class="info-box-icon"><i class="fa fa-dollar-sign"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Capital tied up (sell value)</span>
                    <span class="info-box-number">${{ number_format($totals->total_value ?? 0, 2) }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Filters</h3></div>
        <div class="box-body">
            <form method="GET" action="{{ action('ReportController@deadStockReport') }}" class="row">
                <div class="col-md-3">
                    <label>Not sold in</label>
                    <select name="days" class="form-control">
                        @foreach([30, 60, 90, 180, 365, 730] as $d)
                            <option value="{{ $d }}" @if((int)$days === $d) selected @endif>{{ $d }} days</option>
                        @endforeach
                    </select>
                </div>
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
                    <button type="submit" class="btn btn-primary"><i class="fa fa-filter"></i> Apply</button>
                    <a href="{{ action('ReportController@deadStockReport') }}" class="btn btn-default">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">Items not sold in the last {{ $days }} days (or never sold) — click any column to sort</h3>
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
                    return action('ReportController@deadStockReport') . '?' . http_build_query(array_merge($other, ['sort' => $col, 'dir' => $newDir]));
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
                        {!! $colHead('sku', 'SKU') !!}
                        {!! $colHead('qty', 'Qty on hand', 'text-right') !!}
                        {!! $colHead('price', 'Price', 'text-right') !!}
                        {!! $colHead('date_acquired', 'Date acquired') !!}
                        {!! $colHead('days_on_hand', 'Days on hand', 'text-right') !!}
                        {!! $colHead('last_sold', 'Last sold') !!}
                        {!! $colHead('days_since', 'Days since', 'text-right') !!}
                        {!! $colHead('tied_up_value', 'Tied-up value', 'text-right') !!}
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                    <tr>
                        <td>{{ $r->artist }}</td>
                        <td>{{ $r->name }}</td>
                        <td>{{ $r->format }}</td>
                        <td>{{ $r->sub_sku }}</td>
                        <td class="text-right">{{ number_format($r->qty_available, 2) }} {{ $r->unit }}</td>
                        <td class="text-right">${{ number_format($r->selling_price, 2) }}</td>
                        <td>
                            @php $acq = $r->date_acquired ?: $r->product_created_at; @endphp
                            @if($acq)
                                {{ \Carbon\Carbon::parse($acq)->format('M j, Y') }}
                                @if(!$r->date_acquired)<small class="text-muted" title="No purchase record found — showing product creation date">*</small>@endif
                            @else
                                —
                            @endif
                        </td>
                        <td class="text-right">
                            {{ $r->days_on_hand ? $r->days_on_hand : '—' }}
                        </td>
                        <td>
                            @if($r->last_sold)
                                {{ \Carbon\Carbon::parse($r->last_sold)->format('M j, Y') }}
                            @else
                                <span class="label label-danger">Never sold</span>
                            @endif
                        </td>
                        <td class="text-right">
                            {{ $r->days_since_sold ? $r->days_since_sold : '—' }}
                        </td>
                        <td class="text-right"><strong>${{ number_format($r->tied_up_value, 2) }}</strong></td>
                    </tr>
                    @empty
                    <tr><td colspan="11" class="text-center text-muted">🎉 No dead stock in this window — every item on hand has sold recently.</td></tr>
                    @endforelse
                </tbody>
            </table>

            <div class="text-center">
                {{ $rows->links() }}
            </div>

            <small class="text-muted">
                * If we couldn't find a purchase record for an item, we show when the product was first added to the system as an approximation of its acquisition date.
            </small>
        </div>
    </div>
</section>
@stop
