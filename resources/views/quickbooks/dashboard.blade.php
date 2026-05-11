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
                    <h3 class="box-title">Historical Backfill (ERP → QB sales)</h3>
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

    @php
        $qb_settings = optional(\App\Business::find(session('user.business_id')))->api_settings;
        $qb_settings = is_string($qb_settings) ? json_decode($qb_settings, true) : ($qb_settings ?: []);
        $qb_expense = is_array($qb_settings) && !empty($qb_settings['quickbooks']) ? $qb_settings['quickbooks'] : [];
    @endphp
    <div class="row">
        <div class="col-md-12">
            <div class="box box-success">
                <div class="box-header with-border">
                    <h3 class="box-title">Live expense sync (QB → ERP)</h3>
                </div>
                <div class="box-body">
                    <p>
                        Pulls QuickBooks's "Transaction List by Date" report and creates ERP expense rows so
                        <a href="{{ url('/reports/expense-report') }}">/reports/expense-report</a> reflects what Sabina
                        is recording in QB. <strong>Runs every 30 minutes automatically</strong> on a 14-day rolling window
                        (rows are idempotent — re-runs update in place).
                    </p>
                    <p>
                        <strong>Last sync:</strong>
                        {{ $qb_expense['expense_last_sync_at'] ?? '— never —' }}
                        @if(!empty($qb_expense['expense_last_sync_from']))
                            ({{ $qb_expense['expense_last_sync_from'] }} → {{ $qb_expense['expense_last_sync_to'] ?? '?' }})
                        @endif
                        <br>
                        <strong>Last result:</strong>
                        {{ $qb_expense['expense_last_sync_summary'] ?? '—' }}
                    </p>
                    <form method="POST" action="{{ action('QuickBooksController@syncExpenses') }}" style="display:inline-block; margin-right:8px;">
                        @csrf
                        <button type="submit" class="btn btn-success"><i class="fa fa-sync"></i> Sync now (last 14 days)</button>
                    </form>
                    <form method="POST" action="{{ action('QuickBooksController@syncExpenses') }}" style="display:inline-block;">
                        @csrf
                        <input type="hidden" name="from_date" value="2026-01-01">
                        <input type="hidden" name="to_date" value="{{ date('Y-m-d') }}">
                        <button type="submit" class="btn btn-default" onclick="return confirm('Pull every QB expense since 2026-01-01? Can take a couple minutes.');">
                            <i class="fa fa-history"></i> Backfill 2026-01-01 → today
                        </button>
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
