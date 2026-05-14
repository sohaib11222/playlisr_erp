@extends('layouts.app')
@section('title', 'Force-Close / Delete Registers')

@section('content')
<section class="content-header">
    <h1>Force-Close / Delete Registers</h1>
    <p class="text-muted">
        Lists currently-open registers plus any closed in the last 48h.
        <strong>Close</strong> marks the register status as closed (closing
        amount defaults to the initial count). <strong>Delete</strong> removes
        the register row and its cash_register_transactions outright — use
        this for duplicate same-day opens that are polluting totals. Both
        actions snapshot first; undo at
        <a href="{{ url('/admin/admin-action-history') }}">/admin/admin-action-history</a>.
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
            Bulk close — useful for clearing months of ex-employee leftovers.
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
                    <th>Status</th>
                    <th style="text-align:right;">Age (h)</th>
                    <th style="text-align:right;">Initial</th>
                    <th>Notes</th>
                    <th style="text-align:center; min-width:160px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $r)
                    <tr style="{{ $r->is_stale ? 'background:#fde8e8;' : ($r->is_duplicate ? 'background:#fff4d6;' : '') }}">
                        <td><code>{{ $r->id }}</code></td>
                        <td><strong>{{ $r->name }}</strong></td>
                        <td>{{ $r->location_name }}</td>
                        <td><small>{{ $r->opened_at }}</small></td>
                        <td>
                            <span class="label label-{{ $r->status === 'open' ? 'success' : 'default' }}">{{ $r->status }}</span>
                        </td>
                        <td style="text-align:right; font-variant-numeric:tabular-nums;">
                            {{ number_format($r->age_hours, 1) }}
                        </td>
                        <td style="text-align:right; font-variant-numeric:tabular-nums;">
                            ${{ number_format($r->initial_amount, 2) }}
                        </td>
                        <td>
                            @if(!$r->is_current_staff)
                                <span class="label label-default" style="margin-right:4px;">ex-staff</span>
                            @endif
                            @if($r->is_duplicate)
                                <span class="label label-warning">duplicate same-day open</span>
                            @endif
                        </td>
                        <td style="text-align:center;">
                            @if($r->status === 'open')
                                <form method="POST" action="{{ url('/admin/force-close-registers/close-one') }}" style="display:inline;">
                                    {!! csrf_field() !!}
                                    <input type="hidden" name="register_id" value="{{ $r->id }}">
                                    <button type="submit" class="btn btn-xs btn-warning"
                                        onclick="return confirm('Close {{ addslashes($r->name) }}\'s register #{{ $r->id }}? Snapshot will be saved.');">
                                        Close
                                    </button>
                                </form>
                            @endif
                            <form method="POST" action="{{ url('/admin/force-close-registers/delete-one') }}" style="display:inline;">
                                {!! csrf_field() !!}
                                <input type="hidden" name="register_id" value="{{ $r->id }}">
                                <button type="submit" class="btn btn-xs btn-danger"
                                    onclick="return confirm('DELETE register #{{ $r->id }} ({{ addslashes($r->name) }})? This removes the row + all its cash_register_transactions. Snapshot will be saved for undo.');">
                                    Delete
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted">No open or recently-closed registers.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
</section>
@endsection
