@extends('layouts.app')
@section('title', 'Sling Shift Debug')

@section('content')
<section class="content-header">
    <h1>Sling Shift #{{ $shift->id }} <small>raw payload + ERP user lookup</small>
        <a href="{{ url('/admin/sling/shifts') }}" class="btn btn-default btn-sm pull-right">← Back</a>
    </h1>
</section>

<section class="content">

    @if(session('status_success'))
        <div class="alert alert-success">{{ session('status_success') }}</div>
    @endif
    @if(session('status_error'))
        <div class="alert alert-danger">{{ session('status_error') }}</div>
    @endif

    @if($currentOverride)
        <div class="alert alert-info">
            <strong>Manual mapping active:</strong>
            <code>{{ $email }}</code> →
            ERP user #{{ $currentOverride }}
            @if($overrideUser)
                ({{ $overrideUser->first_name }} {{ $overrideUser->last_name }} — {{ $overrideUser->username }})
            @endif
            <form method="POST" action="{{ url('/admin/sling/shifts/' . $shift->id . '/clear-mapping') }}" style="display:inline;margin-left:12px;">
                @csrf
                <button type="submit" class="btn btn-xs btn-default">Clear mapping</button>
            </form>
        </div>
    @endif
    <div class="box">
        <div class="box-header with-border"><h3 class="box-title">Stored row</h3></div>
        <div class="box-body table-responsive">
            <table class="table table-bordered" style="max-width:800px;">
                <tr><th>sling_shift_id</th><td>{{ $shift->sling_shift_id }}</td></tr>
                <tr><th>sling_user_id</th><td>{{ $shift->sling_user_id }}</td></tr>
                <tr><th>user_email</th><td>{{ $shift->user_email }}</td></tr>
                <tr><th>user_name</th><td>{{ $shift->user_name }}</td></tr>
                <tr><th>erp_user_id</th><td>{{ $shift->erp_user_id ?? '— UNMATCHED —' }}</td></tr>
                <tr><th>event_type</th><td>{{ $shift->event_type }}</td></tr>
                <tr><th>dtstart</th><td>{{ $shift->dtstart }}</td></tr>
                <tr><th>dtend</th><td>{{ $shift->dtend }}</td></tr>
                <tr><th>hours</th><td>{{ $shift->hours }}</td></tr>
                <tr><th>location_name</th><td>{{ $shift->location_name ?: '(none)' }}</td></tr>
                <tr><th>position_name</th><td>{{ $shift->position_name ?: '(none)' }}</td></tr>
            </table>
        </div>
    </div>

    <div class="box box-info">
        <div class="box-header with-border"><h3 class="box-title">Raw Sling payload (top-level keys & values)</h3></div>
        <div class="box-body">
            @if(is_array($payload))
                <table class="table table-condensed table-bordered">
                    <thead><tr><th style="width:200px;">Key</th><th>Value</th></tr></thead>
                    <tbody>
                        @foreach($payload as $k => $v)
                            <tr>
                                <td style="font-family:monospace;">{{ $k }}</td>
                                <td><pre style="margin:0;font-size:11px;max-height:120px;overflow:auto;">{{ is_scalar($v) ? (string) $v : json_encode($v, JSON_PRETTY_PRINT) }}</pre></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="text-muted">No raw payload stored.</p>
            @endif
        </div>
    </div>

    <div class="box box-success">
        <div class="box-header with-border"><h3 class="box-title">ERP users matching email "{{ $email }}" (incl. soft-deleted)</h3></div>
        <div class="box-body table-responsive">
            @if($erpUsers->isEmpty())
                <p class="text-danger"><strong>No ERP user found</strong> by exact email or username match (lowercased) — including soft-deleted.</p>
            @else
                <table class="table table-bordered">
                    <thead><tr><th>id</th><th>first_name</th><th>last_name</th><th>username</th><th>email</th><th>deleted_at</th><th>Map</th></tr></thead>
                    @foreach($erpUsers as $u)
                        <tr>
                            <td>{{ $u->id }}</td>
                            <td>{{ $u->first_name }}</td>
                            <td>{{ $u->last_name }}</td>
                            <td>{{ $u->username }}</td>
                            <td>{{ $u->email ?: '(null)' }}</td>
                            <td>{{ $u->deleted_at ?: '—' }}</td>
                            <td>
                                <form method="POST" action="{{ url('/admin/sling/shifts/' . $shift->id . '/map-user') }}">
                                    @csrf
                                    <input type="hidden" name="erp_user_id" value="{{ $u->id }}">
                                    <button type="submit" class="btn btn-xs btn-success">Map to #{{ $u->id }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    </div>

    <div class="box">
        <div class="box-header with-border"><h3 class="box-title">ERP users matching name (first 10, incl. soft-deleted)</h3></div>
        <div class="box-body table-responsive">
            @if($erpByName->isEmpty())
                <p class="text-muted">No name match either.</p>
            @else
                <table class="table table-bordered">
                    <thead><tr><th>id</th><th>first_name</th><th>last_name</th><th>surname</th><th>username</th><th>email</th><th>deleted_at</th><th>Map</th></tr></thead>
                    @foreach($erpByName as $u)
                        <tr>
                            <td>{{ $u->id }}</td>
                            <td>{{ $u->first_name }}</td>
                            <td>{{ $u->last_name }}</td>
                            <td>{{ $u->surname ?? '' }}</td>
                            <td>{{ $u->username }}</td>
                            <td>{{ $u->email ?: '(null)' }}</td>
                            <td>{{ $u->deleted_at ?: '—' }}</td>
                            <td>
                                <form method="POST" action="{{ url('/admin/sling/shifts/' . $shift->id . '/map-user') }}">
                                    @csrf
                                    <input type="hidden" name="erp_user_id" value="{{ $u->id }}">
                                    <button type="submit" class="btn btn-xs btn-success">Map to #{{ $u->id }}</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </table>
            @endif
        </div>
    </div>

    <div class="box box-default">
        <div class="box-header with-border"><h3 class="box-title">Manual map by ERP user id</h3></div>
        <div class="box-body">
            <p class="text-muted">If the right user isn't in the lists above, paste their ERP user id here.</p>
            <form method="POST" action="{{ url('/admin/sling/shifts/' . $shift->id . '/map-user') }}" class="form-inline">
                @csrf
                <input type="number" name="erp_user_id" class="form-control" placeholder="ERP user id" required style="width:180px;">
                <button type="submit" class="btn btn-primary">Map {{ $email }} → that user</button>
            </form>
        </div>
    </div>
</section>
@endsection
