@extends('layouts.app')
@section('title', 'QuickBooks Sync Dashboard')

@section('content')
<section class="content-header">
    <h1>QuickBooks Sync Dashboard</h1>
</section>

<section class="content">
    @if(session('status'))
        <div class="alert alert-{{ !empty(session('status.success')) ? 'success' : 'danger' }}">
            {{ session('status.msg') }}
        </div>
    @endif

    <div class="row">
        <div class="col-md-6">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Connection</h3>
                </div>
                <div class="box-body">
                    <p><strong>Status:</strong> {{ !empty($connection) && $connection->is_active ? 'Connected' : 'Disconnected' }}</p>
                    <p><strong>Realm ID:</strong> {{ $connection->realm_id ?? '-' }}</p>
                    <p><strong>Environment:</strong> {{ $connection->environment ?? '-' }}</p>
                    <p><strong>Token Expires:</strong> {{ $connection->token_expires_at ?? '-' }}</p>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="box box-info">
                <div class="box-header with-border">
                    <h3 class="box-title">Historical Backfill</h3>
                </div>
                <div class="box-body">
                    <form method="POST" action="{{ action('QuickBooksController@backfill') }}">
                        @csrf
                        <div class="form-group">
                            <label>Backfill From Date</label>
                            <input type="date" class="form-control" name="from_date" value="2026-01-01" required>
                            <p class="help-block">Client requested backfill from 2026-01-01.</p>
                        </div>
                        <button type="submit" class="btn btn-primary">Run Backfill</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">Recent Sync Logs</h3>
        </div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Entity</th>
                        <th>Operation</th>
                        <th>Status</th>
                        <th>Error</th>
                        <th>Processed At</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr>
                            <td>{{ $log->id }}</td>
                            <td>{{ $log->erp_entity_type }} #{{ $log->erp_entity_id }}</td>
                            <td>{{ $log->operation }}</td>
                            <td>
                                <span class="label {{ $log->status === 'success' ? 'bg-green' : ($log->status === 'failed' ? 'bg-red' : 'bg-yellow') }}">
                                    {{ $log->status }}
                                </span>
                            </td>
                            <td>{{ $log->error_message }}</td>
                            <td>{{ $log->processed_at }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center">No QuickBooks sync logs found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
@endsection
