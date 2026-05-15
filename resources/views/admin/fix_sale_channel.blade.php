@extends('layouts.app')
@section('title', 'Fix sale channel')

@section('content')
<section class="content-header">
    <h1>Fix sale channel</h1>
    <p class="text-muted" style="max-width:760px;">
        Flip <code>channel</code> on an ERP sale (e.g. <strong>whatnot → discogs</strong>) when a cashier
        picked the wrong chip at the register. Snapshots BEFORE state to
        <a href="{{ url('/admin/admin-action-history') }}">admin-action-history</a> for undo.
        After the change the Clover↔ERP matcher will re-pair the swipe on the next refresh.
    </p>
</section>

<section class="content">

@if (session('status'))
    <div class="alert alert-warning">{{ session('status') }}</div>
@endif

@if($mode === 'commit')
<div class="box box-solid" style="border:3px solid #00a65a; margin-bottom:14px;">
    <div class="box-header" style="background:#dff0d8;">
        <h3 class="box-title" style="font-size:18px;">
            ✓ Channel changed: <code>{{ $old_channel ?? '—' }}</code> → <code>{{ $new_channel }}</code> on #{{ $tx->invoice_no }}
        </h3>
    </div>
    <div class="box-body">
        Snapshot key <code>{{ $snapshot_key }}</code> — undo at
        <a href="{{ url('/admin/admin-action-history') }}">/admin/admin-action-history</a>.
    </div>
</div>
@endif

<div class="box">
    <div class="box-header with-border">
        <h3 class="box-title">Look up by invoice number</h3>
    </div>
    <div class="box-body">
        <form method="GET" action="{{ url('/admin/fix-channel') }}" class="form-inline">
            <div class="form-group">
                <label for="invoice" style="margin-right:8px;">Invoice #</label>
                <input type="text" name="invoice" id="invoice" value="{{ $invoice }}" class="form-control" placeholder="e.g. 18712" style="min-width:180px;">
                <button class="btn btn-default" type="submit">Look up</button>
            </div>
        </form>

        @if($invoice && !$tx)
            <p class="text-danger" style="margin-top:14px;">No ERP sale found with invoice <code>{{ $invoice }}</code>.</p>
        @endif

        @if($tx)
            <table class="table" style="margin-top:14px;">
                <tbody>
                    <tr><th style="width:160px;">Invoice #</th><td>{{ $tx->invoice_no }}</td></tr>
                    <tr><th>Date</th><td>{{ $tx->transaction_date }}</td></tr>
                    <tr><th>Final total</th><td>${{ number_format($tx->final_total, 2) }}</td></tr>
                    <tr><th>Current channel</th><td><code>{{ $tx->channel ?? 'in_store' }}</code></td></tr>
                </tbody>
            </table>

            <form method="POST" action="{{ url('/admin/fix-channel/apply') }}" onsubmit="return confirm('Change channel on #{{ $tx->invoice_no }} from `' + ({!! json_encode($tx->channel ?? 'in_store') !!}) + '` to `' + document.getElementById('new_channel').value + '`? A snapshot will be saved.');" style="margin-top:8px;">
                @csrf
                <input type="hidden" name="invoice" value="{{ $tx->invoice_no }}">
                <div class="form-group">
                    <label for="new_channel" style="margin-right:8px;">New channel</label>
                    <select name="channel" id="new_channel" class="form-control">
                        @foreach($allowed_channels as $c)
                            <option value="{{ $c }}" {{ ($tx->channel ?? 'in_store') === $c ? 'disabled' : '' }}>{{ $c }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-primary" style="margin-left:8px;">Flip channel (with snapshot)</button>
                </div>
            </form>
        @endif
    </div>
</div>

</section>
@endsection
