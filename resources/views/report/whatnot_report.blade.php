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

    <div class="row">
        <div class="col-md-7">
            <div class="box box-solid">
                <div class="box-header with-border"><h3 class="box-title">Daily breakdown</h3></div>
                <div class="box-body table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th class="text-right">Whatnot #</th>
                                <th class="text-right">Whatnot $</th>
                                <th class="text-right">Other #</th>
                                <th class="text-right">Other $</th>
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
                <div class="box-header with-border"><h3 class="box-title">Top Whatnot sellers (by employee)</h3></div>
                <div class="box-body table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th class="text-right"># tx</th>
                                <th class="text-right">Total $</th>
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
