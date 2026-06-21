@extends('layouts.app')
@section('title', 'Ring Backfill — Alec / Pico $705')

@section('content')
<section class="content-header">
    <h1>Ring Backfill — Alec's Pico sale (6/20/26 7:23pm)</h1>
    <p class="text-muted">
        Re-rings the 103-item cash sale that failed at the register (it 500'd on the
        old line-item limit, so nothing was saved and no stock moved). This creates
        the sale through the normal POS logic — stock decrements, the cash is recorded —
        and is fully undoable. Check every line below, then Apply.
    </p>
</section>

<section class="content">

@if(session('status'))
    @php($st = session('status'))
    <div class="alert {{ !empty($st['success']) ? 'alert-success' : 'alert-danger' }}">{{ $st['msg'] }}</div>
@endif

@if($already)
    <div class="alert alert-warning">
        Already rung up (a transaction tagged <code>{{ \App\Http\Controllers\RingBackfillController::TAG }}</code> exists).
        Undo it at <a href="/admin/admin-action-history">/admin/admin-action-history</a> before re-running.
    </div>
@endif

<div class="box box-solid">
    <div class="box-body">
        <form method="GET" action="/admin/ring-backfill" class="form-inline" style="margin-bottom:12px;">
            <label>Location</label>
            <select name="location_id" class="form-control" onchange="this.form.submit()">
                @foreach($locations as $l)
                    <option value="{{ $l->id }}" {{ $l->id == $location_id ? 'selected' : '' }}>{{ $l->name }}</option>
                @endforeach
            </select>
            <span style="display:inline-block;width:12px;"></span>
            <label>Cashier</label>
            <select name="user_id" class="form-control" form="applyForm">
                @foreach($users as $u)
                    <option value="{{ $u->id }}" {{ $u->id == $user_id ? 'selected' : '' }}>{{ $u->full_name }}</option>
                @endforeach
            </select>
            <span class="help-block" style="display:inline;margin-left:8px;">change Location to re-preview stock</span>
        </form>

        <p>
            <strong>{{ $line_count }}</strong> lines / <strong>{{ $unit_count }}</strong> units ·
            computed total <strong>${{ number_format($computed_total, 2) }}</strong>
            @if(abs($computed_total - $expected_total) >= 0.01)
                <span class="text-red">— register said ${{ number_format($expected_total, 2) }}
                (off by ${{ number_format($expected_total - $computed_total, 2) }}, reconcile before applying)</span>
            @else
                <span class="text-green">— matches the register's ${{ number_format($expected_total, 2) }}</span>
            @endif
            @if($unmatched > 0)
                · <span class="text-red">{{ $unmatched }} barcode(s) NOT found — will ring as revenue-only (no stock decrement)</span>
            @endif
        </p>

        <form id="applyForm" method="POST" action="/admin/ring-backfill/apply"
              onsubmit="return confirm('Ring up this ${{ number_format($computed_total, 2) }} sale and decrement stock? A snapshot is taken first — undoable at /admin/admin-action-history.');">
            {{ csrf_field() }}
            <input type="hidden" name="location_id" value="{{ $location_id }}">
            <label>Sale date/time</label>
            <input type="datetime-local" name="transaction_date" value="{{ $sale_datetime }}" class="form-control" style="width:auto;display:inline-block;">
            <button type="submit" class="btn btn-primary btn-lg" {{ $already ? 'disabled' : '' }}>
                Ring up sale (${{ number_format($computed_total, 2) }})
            </button>
        </form>
    </div>
</div>

<div class="box box-solid">
    <div class="box-body" style="max-height:560px;overflow:auto;">
        <table class="table table-condensed table-striped">
            <thead>
                <tr><th>#</th><th>Item</th><th>Barcode</th><th class="text-right">Qty</th><th class="text-right">Unit</th><th class="text-right">Line</th><th class="text-right">Stock now</th><th>Note</th></tr>
            </thead>
            <tbody>
                @foreach($lines as $i => $ln)
                    <tr @if(!$ln['matched'] && !$ln['manual']) style="background:#fde8e8;" @elseif($ln['manual']) style="background:#fbf6e6;" @endif>
                        <td>{{ $i + 1 }}</td>
                        <td>{{ $ln['name'] }}@if(!empty($ln['artist']))<br><small class="text-muted">{{ $ln['artist'] }}</small>@endif</td>
                        <td>{{ $ln['sku'] ?? '—' }}</td>
                        <td class="text-right">{{ $ln['qty'] }}</td>
                        <td class="text-right">${{ number_format($ln['price'], 2) }}</td>
                        <td class="text-right">${{ number_format($ln['qty'] * $ln['price'], 2) }}</td>
                        <td class="text-right">{{ $ln['matched'] ? rtrim(rtrim(number_format($ln['stock'] ?? 0, 2), '0'), '.') : '—' }}</td>
                        <td><small>{{ $ln['note'] }}</small></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

</section>
@endsection
