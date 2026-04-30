@extends('layouts.app')
@section('title', 'Discogs Order Sync')

@section('content')
<section class="content-header">
    <h1>Discogs Order Sync <small>pull marketplace orders into ERP</small></h1>
</section>

<section class="content">

    @if(session('status'))
        @php $st = session('status'); @endphp
        <div class="alert alert-{{ $st['type'] === 'success' ? 'success' : ($st['type'] === 'warning' ? 'warning' : 'danger') }}">
            {{ $st['msg'] }}
        </div>
    @endif

    <div class="row">
        <div class="col-md-3">
            <div class="info-box bg-blue">
                <span class="info-box-icon"><i class="fa fa-record-vinyl"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Orders synced</span>
                    <span class="info-box-number">{{ (int)($totals->cnt ?? 0) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-green">
                <span class="info-box-icon"><i class="fa fa-dollar-sign"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Revenue (all-time)</span>
                    <span class="info-box-number">${{ number_format((float)($totals->revenue ?? 0), 2) }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-purple">
                <span class="info-box-icon"><i class="fa fa-calendar"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">First synced</span>
                    <span class="info-box-number" style="font-size:14px;">{{ !empty($totals->first_at) ? \Carbon::parse($totals->first_at)->toDateString() : '—' }}</span>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="info-box bg-aqua">
                <span class="info-box-icon"><i class="fa fa-clock"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Latest synced</span>
                    <span class="info-box-number" style="font-size:14px;">{{ !empty($totals->last_at) ? \Carbon::parse($totals->last_at)->toDateString() : '—' }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Pull from Discogs</h3></div>
        <div class="box-body">
            <form method="POST" action="{{ url('/admin/discogs-order-sync') }}" class="row">
                @csrf
                <div class="col-md-3">
                    <label>From date</label>
                    <input type="date" class="form-control" name="start_date" value="{{ \Carbon::now()->startOfMonth()->format('Y-m-d') }}" required>
                </div>
                <div class="col-md-3">
                    <label>To date</label>
                    <input type="date" class="form-control" name="end_date" value="{{ \Carbon::now()->format('Y-m-d') }}" required>
                </div>
                <div class="col-md-6">
                    <label style="display:block;">&nbsp;</label>
                    <button type="submit" class="btn btn-primary"><i class="fa fa-download"></i> Sync orders</button>
                    <span class="text-muted" style="margin-left:10px;">Idempotent — re-running the same window updates existing rows, never duplicates.</span>
                </div>
            </form>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title">Most recent 25 orders</h3></div>
        <div class="box-body table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Discogs ID</th>
                        <th>Status</th>
                        <th>Buyer</th>
                        <th class="text-right">Items</th>
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($recent as $o)
                        <tr>
                            <td>{{ \Carbon::parse($o->order_date)->format('Y-m-d') }}</td>
                            <td><a href="https://www.discogs.com/sell/order/{{ $o->discogs_order_id }}" target="_blank">{{ $o->discogs_order_id }}</a></td>
                            <td>{{ $o->status }}</td>
                            <td>{{ $o->buyer ?? '—' }}</td>
                            <td class="text-right">{{ (int)$o->items_count }}</td>
                            <td class="text-right">${{ number_format((float)$o->total, 2) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted">No Discogs orders synced yet. Pick a date range above and click Sync.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</section>
@stop
