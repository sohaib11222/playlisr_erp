@extends('layouts.app')
@section('title', 'Store Credit Sync')

@section('content')
<section class="content-header">
    <h1>Store Credit Sync</h1>
    <p class="text-muted">
        Compare ERP store credit with website backend balance by email.
        For legacy mismatches, choose how to reconcile. New users continue to auto-sync.
    </p>
    <form method="GET" action="{{ url('/admin/store-credit-sync') }}" style="margin-top:8px;">
        <input type="text" name="q" value="{{ $q }}" placeholder="Search name, email, mobile..." style="padding:4px 8px; width:260px;">
        <label style="margin-left:10px; font-weight:400;">
            <input type="checkbox" name="only_mismatch" value="1" {{ $only_mismatch ? 'checked' : '' }}>
            Only mismatches
        </label>
        <button type="submit" class="btn btn-default btn-sm">Refresh</button>
    </form>
</section>

<section class="content">
    @if(session('status'))
        @php $st = session('status'); @endphp
        <div class="alert alert-{{ ($st['type'] ?? '') === 'success' ? 'success' : 'danger' }}">
            {{ $st['msg'] ?? '' }}
        </div>
    @endif

    <div class="box box-solid">
        <div class="box-body">
            <table class="table table-striped table-condensed">
                <thead>
                    <tr>
                        <th>Customer</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th style="text-align:right;">ERP Balance</th>
                        <th style="text-align:right;">Backend Balance</th>
                        <th>Status</th>
                        <th>Reconcile (legacy)</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($rows as $r)
                    <tr style="{{ $r->mismatch ? 'background:#fff8d6;' : '' }}">
                        <td>{{ $r->name }}</td>
                        <td><code>{{ $r->email }}</code></td>
                        <td>{{ $r->mobile ?: '—' }}</td>
                        <td style="text-align:right; font-variant-numeric:tabular-nums;">${{ number_format((float) $r->erp_balance, 2) }}</td>
                        <td style="text-align:right; font-variant-numeric:tabular-nums;">
                            @if($r->backend_exists)
                                ${{ number_format((float) $r->backend_balance, 2) }}
                            @else
                                <span class="text-muted">not found</span>
                            @endif
                        </td>
                        <td>
                            @if(!$r->backend_exists)
                                <span class="label label-default">No backend user</span>
                            @elseif($r->mismatch)
                                <span class="label label-warning">Mismatch</span>
                            @else
                                <span class="label label-success">Synced</span>
                            @endif
                        </td>
                        <td>
                            @if($r->backend_exists)
                                <form method="POST" action="{{ url('/admin/store-credit-sync/reconcile') }}" style="display:flex; gap:6px; align-items:center;">
                                    @csrf
                                    <input type="hidden" name="contact_id" value="{{ $r->id }}">
                                    <input type="hidden" name="backend_balance" value="{{ $r->backend_balance }}">
                                    <select name="strategy" class="form-control input-sm" style="width:190px;">
                                        <option value="sum">Sum both balances</option>
                                        <option value="erp">Use ERP balance only</option>
                                        <option value="backend">Use backend balance only</option>
                                    </select>
                                    <button type="submit" class="btn btn-primary btn-xs">Apply</button>
                                </form>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted">No matching customers found.</td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</section>
@endsection
