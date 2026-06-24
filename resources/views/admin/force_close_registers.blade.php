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
        this for duplicate same-day opens that are polluting totals.
        <strong>Reassign to…</strong> moves the shift to a different cashier
        when it was opened under the wrong login (then offers a link to move
        that shift's sales/listings/labels too). All
        actions snapshot first; undo at
        <a href="{{ url('/admin/admin-action-history') }}">/admin/admin-action-history</a>.
    </p>
</section>

<section class="content">
@if(session('status'))
    @php $s = session('status'); @endphp
    <div class="alert alert-{{ ($s['success'] ?? 0) ? 'success' : 'danger' }}">
        {{ $s['msg'] ?? '' }}
        @if(!empty($s['link_url']))
            <a href="{{ $s['link_url'] }}" class="btn btn-xs btn-primary" style="margin-left:8px;">{{ $s['link_text'] ?? 'Continue' }}</a>
        @endif
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
                            <form method="POST" action="{{ url('/admin/force-close-registers/reassign-one') }}" style="display:inline-block; margin-top:4px; white-space:nowrap;">
                                {!! csrf_field() !!}
                                <input type="hidden" name="register_id" value="{{ $r->id }}">
                                <select name="new_user_id" class="form-control input-sm" style="display:inline-block; width:auto; max-width:140px;">
                                    <option value="">Reassign to…</option>
                                    @foreach($users as $u)
                                        @if($u->id != $r->user_id)
                                            <option value="{{ $u->id }}">{{ $u->display_name }}</option>
                                        @endif
                                    @endforeach
                                </select>
                                <button type="submit" class="btn btn-xs btn-info"
                                    onclick="if(!this.form.new_user_id.value){alert('Pick a cashier first.');return false;} return confirm('Reassign register #{{ $r->id }} (currently {{ addslashes($r->name) }}) to the selected cashier? Snapshot saved for undo.');">
                                    Go
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
