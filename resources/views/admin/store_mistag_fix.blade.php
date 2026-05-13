@extends('layouts.app')
@section('title', 'Fix Store Mistags')

@section('content')
<section class="content-header">
    <h1>Fix Store Mistags</h1>
    <p class="text-muted" style="max-width:900px;">
        Before the duty picker landed on 2026-05-11, cashiers could ring sales without picking a specific
        store, so HW sales sometimes got stored with Pico's <code>location_id</code> (and vice versa).
        This finds ERP sales whose <strong>final_total</strong> and <strong>minute</strong> exactly match
        a Clover charge at a <em>different</em> location — almost certainly the same sale, mistagged on
        the ERP side. Check the rows you want to fix and click Apply. Snapshots BEFORE state to
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
                    Applied — {{ number_format($updated) }} sale(s) retagged
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

<form method="GET" action="{{ url('/admin/store-mistag-fix') }}" class="form-inline" style="margin-bottom:12px;">
    <label>Window:</label>
    <input type="date" name="start_date" value="{{ $start_date }}" class="form-control input-sm">
    <span>→</span>
    <input type="date" name="end_date" value="{{ $end_date }}" class="form-control input-sm">
    <button type="submit" class="btn btn-default btn-sm">Search</button>
</form>

<form method="POST" action="{{ url('/admin/store-mistag-fix/run') }}">
    @csrf
    <input type="hidden" name="start_date" value="{{ $start_date }}">
    <input type="hidden" name="end_date" value="{{ $end_date }}">
    <div class="row">
        <div class="col-md-12">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">Candidate mistags ({{ count($candidates) }})</h3>
                    @if (count($candidates) > 0)
                        <span class="pull-right">
                            <a href="#" id="select-all-rows" style="margin-right:12px;">Select all</a>
                            <a href="#" id="clear-all-rows">Clear all</a>
                        </span>
                    @endif
                </div>
                <div class="box-body" style="padding:0;">
                    <table class="table table-condensed table-striped" style="margin:0;">
                        <thead>
                            <tr>
                                <th style="width:30px;"></th>
                                <th style="width:80px;">Tx ID</th>
                                <th style="width:100px;">Invoice</th>
                                <th style="width:150px;">Date</th>
                                <th style="width:90px;">Amount</th>
                                <th>Cashier</th>
                                <th style="width:120px;">Current store</th>
                                <th style="width:120px;">Should be</th>
                                <th style="width:90px;">Δ amt</th>
                                <th style="width:90px;">Δ time</th>
                                <th>Clover ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($candidates as $c)
                                <tr>
                                    <td>
                                        <input type="checkbox"
                                               class="mistag-row-cb"
                                               name="retag[{{ $c['tx_id'] }}]"
                                               value="{{ $c['suggested_location_id'] }}">
                                    </td>
                                    <td>{{ $c['tx_id'] }}</td>
                                    <td>{{ $c['invoice_no'] }}</td>
                                    <td>{{ \Carbon\Carbon::parse($c['transaction_date'])->format('m/d/y g:i A') }}</td>
                                    <td>${{ number_format($c['final_total'], 2) }}</td>
                                    <td>{{ $c['cashier'] ?: '—' }}</td>
                                    <td style="color:#b91c1c;">
                                        {{ $c['current_location_name'] ?: ('loc ' . $c['current_location_id']) }}
                                    </td>
                                    <td style="color:#166534; font-weight:700;">
                                        {{ $business_locations[$c['suggested_location_id']] ?? ('loc ' . $c['suggested_location_id']) }}
                                    </td>
                                    <td>
                                        @if ($c['amount_delta_cents'] == 0)
                                            <span style="color:#166534;">$0.00 exact</span>
                                        @else
                                            ${{ number_format($c['amount_delta_cents'] / 100, 2) }}
                                        @endif
                                    </td>
                                    <td>{{ $c['time_delta_sec'] }}s</td>
                                    <td><code style="font-size:11px;">{{ $c['clover_payment_id'] }}</code></td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11" class="text-center text-muted" style="padding:20px;">
                                        No mistag candidates in this window — nothing to fix.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if (count($candidates) > 0)
                    <div class="box-footer">
                        <button type="submit" class="btn btn-primary btn-lg"
                                onclick="return confirm('Retag the checked sales? This updates transactions.location_id. Snapshot will be saved for undo.');">
                            Apply retags
                        </button>
                        <span class="help-block" style="display:inline-block;margin-left:12px;vertical-align:middle;">
                            Check each row you want to fix. Δ amt = $0.00 + tiny Δ time = highest confidence.
                        </span>
                    </div>
                @endif
            </div>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var selectAll = document.getElementById('select-all-rows');
    var clearAll = document.getElementById('clear-all-rows');
    if (selectAll) selectAll.addEventListener('click', function (e) {
        e.preventDefault();
        document.querySelectorAll('.mistag-row-cb').forEach(function (cb) { cb.checked = true; });
    });
    if (clearAll) clearAll.addEventListener('click', function (e) {
        e.preventDefault();
        document.querySelectorAll('.mistag-row-cb').forEach(function (cb) { cb.checked = false; });
    });
});
</script>

</section>
@endsection
