@extends('layouts.app')
@section('title', 'Whatnot Sales Report')

@section('content')
<section class="content-header">
    <h1>Whatnot Sales Report <small>live-auction vs in-store / online</small></h1>
</section>

<section class="content">

    <div class="row">
        <div class="col-md-4">
            <div class="info-box bg-purple">
                <span class="info-box-icon"><i class="fa fa-tv"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Whatnot sales</span>
                    <span class="info-box-number">${{ number_format($whatnot->total ?? 0, 2) }}</span>
                    <span class="progress-description">{{ (int)($whatnot->cnt ?? 0) }} transactions ({{ number_format($whatnot_pct, 1) }}% of total)</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="info-box bg-blue">
                <span class="info-box-icon"><i class="fa fa-store"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Non-Whatnot sales</span>
                    <span class="info-box-number">${{ number_format($non->total ?? 0, 2) }}</span>
                    <span class="progress-description">{{ (int)($non->cnt ?? 0) }} transactions</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="info-box bg-green">
                <span class="info-box-icon"><i class="fa fa-dollar-sign"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Overall sales</span>
                    <span class="info-box-number">${{ number_format($overall_total, 2) }}</span>
                    <span class="progress-description">{{ (int)(($whatnot->cnt ?? 0) + ($non->cnt ?? 0)) }} transactions</span>
                </div>
            </div>
        </div>
    </div>

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Filters</h3></div>
        <div class="box-body">
            <form method="GET" action="{{ action('ReportController@whatnotReport') }}" class="row">
                <div class="col-md-3">
                    <label>Start date</label>
                    <input type="date" class="form-control" name="start_date" value="{{ $start_date }}">
                </div>
                <div class="col-md-3">
                    <label>End date</label>
                    <input type="date" class="form-control" name="end_date" value="{{ $end_date }}">
                </div>
                <div class="col-md-3">
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
                </div>
            </form>
        </div>
    </div>

    <style>
        .sortable-col a { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 4px; }
        .sortable-col a:hover { color: #1b6ca8; }
        .sortable-col .sort-arrow { opacity: 0.4; font-size: 11px; }
        .sortable-col.active a { color: #1b6ca8; font-weight: 700; }
        .sortable-col.active .sort-arrow { opacity: 1; }
    </style>
    @php
        $other = request()->except(['sort', 'dir', 'sort_table']);
        $sortUrl = function ($table, $col) use ($sort, $sort_table, $dir, $other) {
            $newDir = ($sort === $col && $sort_table === $table && $dir === 'asc') ? 'desc' : 'asc';
            return action('ReportController@whatnotReport') . '?' . http_build_query(array_merge($other, ['sort' => $col, 'dir' => $newDir, 'sort_table' => $table]));
        };
        $arrow = function ($table, $col) use ($sort, $sort_table, $dir) {
            if ($sort !== $col || $sort_table !== $table) return '<i class="fa fa-sort sort-arrow"></i>';
            return $dir === 'asc' ? '<i class="fa fa-sort-up sort-arrow"></i>' : '<i class="fa fa-sort-down sort-arrow"></i>';
        };
        $colHead = function ($table, $col, $label, $class = '') use ($sort, $sort_table, $sortUrl, $arrow) {
            $active = ($sort === $col && $sort_table === $table) ? 'active' : '';
            return '<th class="sortable-col ' . $class . ' ' . $active . '"><a href="' . $sortUrl($table, $col) . '">' . $label . ' ' . $arrow($table, $col) . '</a></th>';
        };
    @endphp
    <div class="row">
        <div class="col-md-7">
            <div class="box box-solid">
                <div class="box-header with-border"><h3 class="box-title">Daily breakdown <small style="color:#6b7280;">click any column to sort</small></h3></div>
                <div class="box-body table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                {!! $colHead('daily', 'day', 'Date') !!}
                                {!! $colHead('daily', 'whatnot_cnt', 'Whatnot #', 'text-right') !!}
                                {!! $colHead('daily', 'whatnot_total', 'Whatnot $', 'text-right') !!}
                                {!! $colHead('daily', 'non_cnt', 'Other #', 'text-right') !!}
                                {!! $colHead('daily', 'non_total', 'Other $', 'text-right') !!}
                                <th class="text-right">Total $</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($daily as $d)
                                <tr>
                                    <td>{{ \Carbon\Carbon::parse($d->day)->format('M j, Y') }}</td>
                                    <td class="text-right">{{ (int) $d->whatnot_cnt }}</td>
                                    <td class="text-right">${{ number_format($d->whatnot_total, 2) }}</td>
                                    <td class="text-right">{{ (int) $d->non_cnt }}</td>
                                    <td class="text-right">${{ number_format($d->non_total, 2) }}</td>
                                    <td class="text-right"><strong>${{ number_format($d->whatnot_total + $d->non_total, 2) }}</strong></td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted">No sales in this window.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-5">
            <div class="box box-solid">
                <div class="box-header with-border"><h3 class="box-title">Top Whatnot sellers (by employee) <small style="color:#6b7280;">click to sort</small></h3></div>
                <div class="box-body table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                {!! $colHead('top', 'employee', 'Employee') !!}
                                {!! $colHead('top', 'cnt', '# tx', 'text-right') !!}
                                {!! $colHead('top', 'total', 'Total $', 'text-right') !!}
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($top_sellers as $s)
                                <tr>
                                    <td>{{ trim($s->employee) ?: '(unassigned)' }}</td>
                                    <td class="text-right">{{ (int) $s->cnt }}</td>
                                    <td class="text-right">${{ number_format($s->total, 2) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="text-center text-muted">No Whatnot sales in this window.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</section>
@stop
