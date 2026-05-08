@extends('layouts.app')
@section('title', 'Sling Shifts')

@section('content')
<section class="content-header">
    <h1>
        Sling Shifts
        <small>scheduled shifts pulled from Sling daily</small>
    </h1>
</section>

<section class="content">

    @if(session('status_success'))
        <div class="alert alert-success">{{ session('status_success') }}</div>
    @endif
    @if(session('status_error'))
        <div class="alert alert-danger">{{ session('status_error') }}</div>
    @endif

    @if(!$connected)
        <div class="alert alert-warning">
            Sling isn't connected — go to <a href="{{ url('/admin/sling/login') }}">Sling Connection</a> to log in first.
        </div>
    @endif

    <div class="box box-primary">
        <div class="box-header with-border">
            <h3 class="box-title">Status</h3>
            <div class="box-tools pull-right">
                <a href="{{ url('/admin/sling/login') }}" class="btn btn-default btn-sm">
                    <i class="fa fa-cog"></i> Connection
                </a>
            </div>
        </div>
        <div class="box-body">
            <table class="table table-bordered" style="max-width:600px;">
                <tr><th style="width:220px;">Total shifts in ERP</th><td>{{ number_format($totalCount) }}</td></tr>
                <tr><th>Last synced at</th><td>{{ $lastSyncedAt ?: 'never — click Sync now' }}</td></tr>
                <tr><th>Daily auto-sync</th><td>03:30 PST</td></tr>
            </table>
            <form method="POST" action="{{ url('/admin/sling/shifts/sync') }}" style="display:inline;">
                @csrf
                <button type="submit" class="btn btn-primary" {{ $connected ? '' : 'disabled' }}>
                    <i class="fa fa-refresh"></i> Sync now
                </button>
            </form>
            <small class="text-muted" style="margin-left:12px;">Pulls last 7 days + next 30 days from Sling.</small>
        </div>
    </div>

    <div class="box box-default">
        <div class="box-header with-border"><h3 class="box-title">Filter</h3></div>
        <div class="box-body">
            <form method="GET" action="{{ url('/admin/sling/shifts') }}" class="form-inline">
                <div class="form-group">
                    <label>From</label>
                    <input type="date" name="start" class="form-control" value="{{ $start->toDateString() }}">
                </div>
                <div class="form-group" style="margin-left:8px;">
                    <label>To</label>
                    <input type="date" name="end" class="form-control" value="{{ $end->toDateString() }}">
                </div>
                <button type="submit" class="btn btn-default" style="margin-left:8px;">
                    <i class="fa fa-search"></i> Apply
                </button>
            </form>
        </div>
    </div>

    <div class="box">
        <div class="box-header with-border">
            <h3 class="box-title">
                Shifts
                <small>{{ $start->toDateString() }} → {{ $end->toDateString() }} ({{ $shifts->count() }} rows)</small>
            </h3>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-striped table-condensed">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Hours</th>
                        <th>Employee</th>
                        <th>Email</th>
                        <th>Location</th>
                        <th>Position</th>
                        <th>ERP user</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($shifts as $s)
                    <tr class="{{ $s->published ? '' : 'text-muted' }}">
                        <td>{{ optional($s->dtstart)->format('Y-m-d (D)') }}</td>
                        <td>{{ optional($s->dtstart)->format('H:i') }}</td>
                        <td>{{ optional($s->dtend)->format('H:i') }}</td>
                        <td>{{ number_format((float) $s->hours, 2) }}</td>
                        <td>{{ $s->user_name ?: '—' }}</td>
                        <td>{{ $s->user_email ?: '—' }}</td>
                        <td>{{ $s->location_name ?: '—' }}</td>
                        <td>{{ $s->position_name ?: '—' }}</td>
                        <td>
                            @if($s->erp_user_id)
                                <span class="text-success"><i class="fa fa-link"></i> #{{ $s->erp_user_id }}</span>
                            @else
                                <span class="text-muted">unmatched</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted">No shifts in this window. Click <strong>Sync now</strong> above to pull from Sling.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

</section>
@endsection
