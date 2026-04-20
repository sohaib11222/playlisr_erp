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
            <h3 class="box-title">Items not sold in the last {{ $days }} days (or never sold)</h3>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Artist</th>
                        <th>Title</th>
                        <th>Format</th>
                        <th>SKU</th>
                        <th class="text-right">Qty on hand</th>
                        <th class="text-right">Price</th>
                        <th>Last sold</th>
                        <th class="text-right">Days since</th>
                        <th class="text-right">Tied-up value</th>
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
                    <tr><td colspan="9" class="text-center text-muted">🎉 No dead stock in this window — every item on hand has sold recently.</td></tr>
                    @endforelse
                </tbody>
            </table>

            <div class="text-center">
                {{ $rows->links() }}
            </div>
        </div>
    </div>
</section>
@stop
