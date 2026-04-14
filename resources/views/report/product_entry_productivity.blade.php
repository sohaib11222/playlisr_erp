@extends('layouts.app')
@section('title', 'Employee Productivity Report')

@section('content')
<section class="content-header">
    <h1>Employee Productivity Report</h1>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-6">
            <div class="info-box bg-aqua">
                <span class="info-box-icon"><i class="fa fa-layer-group"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Today - Mass Add Items</span>
                    <span class="info-box-number">{{ (int) $today_mass_add }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="info-box bg-green">
                <span class="info-box-icon"><i class="fa fa-cart-plus"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Today - Add Purchase Lines</span>
                    <span class="info-box-number">{{ (int) $today_purchase_add }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Date Range</h3></div>
        <div class="box-body">
            <form method="GET" action="{{ action('ReportController@productEntryProductivity') }}" class="row">
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
        <div class="box-header with-border"><h3 class="box-title">By Employee</h3></div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Mass Add Count</th>
                        <th>Add Purchase Count</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                        <tr>
                            <td>{{ $r->employee ?: ('User #' . $r->user_id) }}</td>
                            <td>{{ (int) $r->mass_add_count }}</td>
                            <td>{{ (int) $r->purchase_add_count }}</td>
                            <td><b>{{ (int) $r->total_count }}</b></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted">No data found for this range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
@endsection

