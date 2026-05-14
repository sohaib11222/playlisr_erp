@extends('layouts.app')
@section('title', 'Force-Close Registers')

@section('content')
<section class="content-header">
    <h1>Force-Close Registers</h1>
    <p class="text-muted">
        Closes registers that cashiers left open. Each close writes a snapshot
        to <code>storage/admin-snapshots/</code> first — undo any time at
        <a href="{{ url('/admin/admin-action-history') }}">/admin/admin-action-history</a>.
        Closing amount defaults to the register's <em>initial</em> count (we don't
        know what the cashier counted at close) and the closing note flags the
        row as admin-force-closed so the reconciliation trail stays honest.
    </p>
</section>

<section class="content">
@if(session('status'))
    @php $s = session('status'); @endphp
    <div class="alert alert-{{ ($s['success'] ?? 0) ? 'success' : 'danger' }}">
        {{ $s['msg'] ?? '' }}
    </div>
@endif

<div class="box box-solid" style="margin-bottom:14px;">
    <div class="box-body">
        <form method="POST" action="{{ url('/admin/force-close-registers/close-stale') }}" style="display:inline;">
            {!! csrf_field() !!}
            <button type="submit" class="btn btn-warning"
                onclick="return confirm('Close all {{ $stale_count }} register(s) older than {{ $stale_hours }} hours? Snapshot will be saved for undo.');"
                @if($stale_count === 0) disabled @endif>
                Close all {{ $stale_count }} register(s) older than {{ $stale_hours }}h
            </button>
        </form>
        <span style="margin-left:14px; color:#666; font-size:13px;">
            Bulk action — useful for clearing months of ex-employee leftovers.
        </span>
    </div>
</div>

<div class="box box-solid">
    <div class="box-body">
        <table class="table table-striped table-condensed">
            <thead>
                <tr>
                    <th>Reg id</th>
                    <th>Cashier</th>
                    <th>Store</th>
                    <th>Opened</th>
                    <th style="text-align:right;">Age (h)</th>
                    <th style="text-align:right;">Initial (will be closing)</th>
                    <th>Staff status</th>
                    <th style="text-align:center;">Close</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $r)
                    <tr style="{{ $r->is_stale ? 'background:#fde8e8;' : '' }}">
                        <td><code>{{ $r->id }}</code></td>
                        <td><strong>{{ $r->name }}</strong></td>
                        <td>{{ $r->location_name }}</td>
                        <td><small>{{ $r->opened_at }}</small></td>
                        <td style="text-align:right; font-variant-numeric:tabular-nums;">
                            {{ number_format($r->age_hours, 1) }}
                        </td>
                        <td style="text-align:right; font-variant-numeric:tabular-nums;">
                            ${{ number_format($r->initial_amount, 2) }}
                        </td>
                        <td>
                            @if($r->is_current_staff)
                                <span class="label label-success">current</span>
                            @else
                                <span class="label label-default">ex-staff</span>
                            @endif
                        </td>
                        <td style="text-align:center;">
                            <form method="POST" action="{{ url('/admin/force-close-registers/close-one') }}" style="display:inline;">
                                {!! csrf_field() !!}
                                <input type="hidden" name="register_id" value="{{ $r->id }}">
                                <button type="submit" class="btn btn-xs btn-danger"
                                    onclick="return confirm('Close {{ $r->name }}\'s register #{{ $r->id }}? Snapshot will be saved.');">
                                    Close
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted">No open registers. Everyone closed their shifts cleanly.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
</section>
@endsection
