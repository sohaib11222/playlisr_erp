@extends('layouts.app')
@section('title', 'Fastest Sellers — Items Sold')

@section('content')
<section class="content-header">
    <h1>Items sold &amp; time to sell
        <small>{{ $genre_label ?? 'all genres' }} · {{ $scopes[$scope]['label'] }} · {{ \Carbon::parse($start_date)->format('M j, Y') }} – {{ \Carbon::parse($end_date)->format('M j, Y') }}</small>
    </h1>
</section>

<section class="content">
    <style>
        .fsd-speed-pills { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:12px; }
        .fsd-speed-pills a { font-size:12px; padding:5px 12px; border:1px solid #d1d5db; border-radius:999px; color:#374151; background:#fff; text-decoration:none; }
        .fsd-speed-pills a:hover { border-color:#3b6d11; color:#3b6d11; }
        .fsd-speed-pills a.active { background:#3b6d11; border-color:#3b6d11; color:#fff; font-weight:600; }
        .fsd-days { font-weight:700; }
        .fsd-days.blazing { color:#9a3412; }
        .fsd-days.fast { color:#065f46; }
        .fsd-days.slow { color:#991b1b; }
        .fsd-cat { display:inline-block; font-size:11px; color:#374151; background:#eef2f7; border-radius:4px; padding:0 6px; margin-left:4px; }
        .fsd-stock-out { color:#991b1b; font-weight:600; }
    </style>

    <div class="box box-primary">
        <div class="box-body">
            {{-- Quick days-to-sell presets; keep every other filter as-is. --}}
            <div class="fsd-speed-pills">
                @foreach($speed_presets as $key => $preset)
                    <a href="{{ request()->fullUrlWithQuery(['speed' => $key, 'min_days' => null, 'max_days' => null, 'page' => null]) }}"
                       class="{{ ($speed === (string) $key && !request()->filled('min_days') && !request()->filled('max_days')) ? 'active' : '' }}">{{ $preset['label'] }}</a>
                @endforeach
            </div>

            <form method="GET" action="{{ action('HomeController@fastestSellersDetail') }}" class="row">
                <div class="col-md-2 col-sm-4">
                    <label>Store</label>
                    <select name="scope" class="form-control">
                        @foreach($scopes as $key => $s)
                            <option value="{{ $key }}" @if($scope === $key) selected @endif>{{ $s['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 col-sm-8">
                    <label>Genre</label>
                    @php $genre_val = ($category_id === '' && $sub_category_id === '') ? '' : (($category_id === '' ? '' : $category_id) . ':' . ($sub_category_id === '' ? '' : $sub_category_id)); @endphp
                    <select id="fsd_genre" class="form-control">
                        <option value="">All genres</option>
                        @foreach($genre_options as $g)
                            @php $v = $g->cid . ':' . $g->scid; @endphp
                            <option value="{{ $v }}" @if($genre_val === $v) selected @endif>{{ $g->category ? $g->category . ' — ' : '' }}{{ $g->genre }}</option>
                        @endforeach
                    </select>
                    <input type="hidden" name="category_id" id="fsd_category_id" value="{{ $category_id }}">
                    <input type="hidden" name="sub_category_id" id="fsd_sub_category_id" value="{{ $sub_category_id }}">
                </div>
                <div class="col-md-2 col-sm-4">
                    <label>Sold in</label>
                    <select name="range" id="fsd_range" class="form-control">
                        @foreach($ranges as $key => $r)
                            <option value="{{ $key }}" @if($range === $key) selected @endif>Last {{ $r['label'] }}</option>
                        @endforeach
                        <option value="custom" @if($range === 'custom') selected @endif>Custom dates…</option>
                    </select>
                </div>
                <div class="col-md-2 col-sm-4 fsd-custom-dates" @if($range !== 'custom') style="display:none;" @endif>
                    <label>From / to</label>
                    <input type="date" name="start_date" class="form-control input-sm" value="{{ $start_date }}">
                    <input type="date" name="end_date" class="form-control input-sm" value="{{ $end_date }}" style="margin-top:4px;">
                </div>
                <div class="col-md-2 col-sm-4">
                    <label>Days to sell</label>
                    <div style="display:flex; gap:4px; align-items:center;">
                        <input type="number" min="0" name="min_days" class="form-control" placeholder="min" value="{{ $min_days }}">
                        <span>–</span>
                        <input type="number" min="0" name="max_days" class="form-control" placeholder="max" value="{{ $max_days }}">
                    </div>
                </div>
                <div class="col-md-1 col-sm-4">
                    <label>Sort</label>
                    <select name="sort" class="form-control">
                        @foreach($sort_options as $key => $label)
                            <option value="{{ $key }}" @if($sort === $key) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-12" style="margin-top:10px;">
                    <button type="submit" class="btn btn-primary"><i class="fa fa-filter"></i> Apply</button>
                    <a href="{{ action('HomeController@fastestSellersDetail') }}" class="btn btn-default">Reset</a>
                    <a href="{{ action('HomeController@index') }}" class="btn btn-link">← Back to home</a>
                </div>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-md-3 col-sm-6">
            <div class="info-box bg-green">
                <span class="info-box-icon"><i class="fa fa-compact-disc"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Units sold</span>
                    <span class="info-box-number">{{ number_format((float) ($summary->units ?? 0)) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="info-box bg-aqua">
                <span class="info-box-icon"><i class="fa fa-dollar-sign"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Revenue</span>
                    <span class="info-box-number">${{ number_format((float) ($summary->revenue ?? 0), 0) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="info-box bg-yellow">
                <span class="info-box-icon"><i class="fa fa-stopwatch"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Avg days to sell</span>
                    <span class="info-box-number">{{ is_null($summary->avg_days ?? null) ? '—' : number_format((float) $summary->avg_days, 1) . 'd' }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="info-box bg-purple">
                <span class="info-box-icon"><i class="fa fa-arrows-alt-h"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Fastest / slowest</span>
                    <span class="info-box-number">{{ is_null($summary->min_days ?? null) ? '—' : $summary->min_days . 'd / ' . $summary->max_days . 'd' }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">{{ number_format($items->total()) }} sales</h3>
            <small class="text-muted" style="margin-left:8px;">Days to sell = sale date minus the date the item was taken in (purchase / buy-from-customer). "Left" = units on hand now{{ $scope !== 'all' ? ' at ' . $scopes[$scope]['label'] : '' }}.</small>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-striped table-condensed">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>SKU</th>
                        <th>Genre</th>
                        <th>Store</th>
                        <th>Taken in</th>
                        <th>Sold</th>
                        <th class="text-right">Days to sell</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Price</th>
                        <th class="text-right">Left</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($items as $it)
                        @php
                            $d = (int) $it->days_to_sell;
                            $cls = $d <= 7 ? 'blazing' : ($d <= 21 ? 'fast' : ($d <= 45 ? '' : 'slow'));
                        @endphp
                        <tr>
                            <td>{{ $it->artist ? $it->artist . ' — ' : '' }}{{ $it->product_name }}</td>
                            <td>{{ $it->sku }}</td>
                            <td>{{ $it->genre }}@if($it->category)<span class="fsd-cat">{{ $it->category }}</span>@endif</td>
                            <td>{{ $it->location_name }}</td>
                            <td>{{ \Carbon::parse($it->intake_date)->format('M j, Y') }}</td>
                            <td>{{ \Carbon::parse($it->sale_date)->format('M j, Y') }}</td>
                            <td class="text-right"><span class="fsd-days {{ $cls }}">{{ $d === 0 ? 'same day' : $d . 'd' }}</span></td>
                            <td class="text-right">{{ (float) $it->qty == (int) $it->qty ? (int) $it->qty : number_format((float) $it->qty, 2) }}</td>
                            <td class="text-right">${{ number_format((float) $it->price, 2) }}</td>
                            <td class="text-right {{ (float) $it->in_stock <= 0 ? 'fsd-stock-out' : '' }}">{{ number_format((float) $it->in_stock) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-muted" style="padding:20px;">No sales match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
            {{ $items->links() }}
        </div>
    </div>
</section>
@endsection

@section('javascript')
<script>
    $(function () {
        $('#fsd_genre').on('change', function () {
            var parts = ($(this).val() || ':').split(':');
            $('#fsd_category_id').val(parts[0] || '');
            $('#fsd_sub_category_id').val(parts[1] || '');
        });
        $('#fsd_range').on('change', function () {
            $('.fsd-custom-dates').toggle($(this).val() === 'custom');
        });
    });
</script>
@endsection
