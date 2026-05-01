@extends('layouts.app')
@section('title', 'Fix Stray In Store Date')

@section('content')
<section class="content-header">
    <h1>Fix Stray In Store Date</h1>
    <p class="text-muted">
        Lists any transactions tagged <code>nivessa_backend_sales_in_store_new_used_sales</code>
        whose date is still in the future (&gt; {{ \App\Http\Controllers\FixStrayInStoreDateController::CUTOFF }}).
        These are leftovers the bulk fixes couldn't auto-resolve. Type the correct
        <code>YYYY-MM-DD</code> per row and click Apply. Snapshots BEFORE state to
        <a href="{{ url('/admin/admin-action-history') }}">admin-action-history</a> for undo.
    </p>
</section>

<section class="content">

@if (session('status'))
<div class="alert alert-warning">{{ session('status') }}</div>
@endif

@if ($mode === 'commit')
<div class="row">
    <div class="col-md-12">
        <div class="box box-solid" style="border: 3px solid #00a65a;">
            <div class="box-header" style="background: #dff0d8;">
                <h3 class="box-title" style="font-size:20px;">
                    Applied — {{ number_format($updated) }} row(s) updated
                </h3>
            </div>
            @if (!empty($snapshot_key))
                <div class="box-body" style="background: #dff0d8;">
                    Snapshot saved as <code>{{ $snapshot_key }}</code>. Undo from
                    <a href="{{ url('/admin/admin-action-history') }}">/admin/admin-action-history</a>.
                </div>
            @endif
        </div>
    </div>
</div>
@endif

<form method="POST" action="{{ url('/admin/fix-stray-in-store-date/run') }}">
    @csrf
    <div class="row">
        <div class="col-md-12">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">Stray rows ({{ count($rows) }})</h3>
                </div>
                <div class="box-body" style="padding:0;">
                    <table class="table table-condensed table-striped" style="margin:0;">
                        <thead>
                            <tr>
                                <th style="width:80px;">Tx ID</th>
                                <th style="width:90px;">ext_id</th>
                                <th>Artist</th>
                                <th>Title</th>
                                <th style="width:90px;">Format</th>
                                <th style="width:90px;">Amount</th>
                                <th style="width:130px;">Current date</th>
                                <th style="width:160px;">Set date to (YYYY-MM-DD)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $r)
                                <tr>
                                    <td>{{ $r->id }}</td>
                                    <td><code>{{ $r->import_external_id }}</code></td>
                                    <td>{{ $r->legacy_artist }}</td>
                                    <td>{{ $r->legacy_title }}</td>
                                    <td>{{ $r->legacy_format }}</td>
                                    <td>${{ number_format($r->final_total, 2) }}</td>
                                    <td>{{ \Carbon\Carbon::parse($r->transaction_date)->format('m/d/y g:i A') }}</td>
                                    <td>
                                        <input
                                            type="text"
                                            name="date[{{ $r->id }}]"
                                            placeholder="2023-05-02"
                                            class="form-control input-sm"
                                            style="max-width:140px;"
                                        >
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center text-muted" style="padding:20px;">
                                        No stray In Store rows. Nothing to fix.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if (count($rows) > 0)
                    <div class="box-footer">
                        <button type="submit" class="btn btn-primary btn-lg">Apply</button>
                        <span class="help-block" style="display:inline-block;margin-left:12px;vertical-align:middle;">
                            Type a date for each row you want to fix. Empty rows are skipped.
                        </span>
                    </div>
                @endif
            </div>
        </div>
    </div>
</form>

</section>
@endsection
