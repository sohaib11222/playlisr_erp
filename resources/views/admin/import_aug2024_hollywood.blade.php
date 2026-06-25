@extends('layouts.app')
@section('title', 'Import Aug 2024 Hollywood')

@section('content')
<section class="content-header">
    <h1>Import Missing Sheet — Hollywood, August 2024</h1>
    <p class="text-muted" style="max-width:920px;">
        The bulk import skipped the "Hollywood Sales August 2024" tab because its price-column
        header was a broken Excel formula (<code>#REF!</code>) instead of "Amount". That's why
        Hollywood reads <strong>$0</strong> for Aug 2024 (an <code>n/a</code> hole between Jul and
        Sep) in the Like-for-Like report. The rows were re-parsed with the importer's own rules and
        are inserted exactly like every other imported sheet. Idempotent and snapshotted for
        one-click undo at <a href="/admin/admin-action-history">admin-action-history</a>. Live POS untouched.
    </p>
</section>

<section class="content">

@if (session('status'))
    <div class="alert {{ !empty(session('status')['success']) ? 'alert-success' : 'alert-danger' }}">
        {{ session('status')['msg'] }}
    </div>
@endif

@if (!$hasLoc)
    <div class="alert alert-danger">Couldn't resolve the Hollywood location by name. Nothing to do.</div>
@endif

@if ($hasLoc)
<div class="box box-solid">
    <div class="box-header"><h3 class="box-title">Ready to import</h3></div>
    <div class="box-body">
        <table class="table table-condensed" style="max-width:520px;">
            <tr><td>Rows in sheet</td><td style="text-align:right;"><strong>{{ number_format($count) }}</strong></td></tr>
            <tr><td>Pre-tax total</td><td style="text-align:right;">${{ number_format($preTax, 2) }}</td></tr>
            <tr><td>Final total (incl. 9.75% tax)</td><td style="text-align:right;"><strong>${{ number_format($final, 2) }}</strong></td></tr>
            <tr><td>Already imported</td><td style="text-align:right;">{{ number_format($already) }}</td></tr>
        </table>
        <p class="text-muted">All rows land in Aug 2024 under Hollywood. The final total is what shows in the LFL report.</p>
    </div>
    <div class="box-footer">
        @if ($already >= $count && $count > 0)
            <span class="text-muted">Already imported — nothing to do.</span>
        @endif
        @if ($already < $count)
        <form method="POST" action="/admin/import-aug2024-hollywood/run" style="display:inline;">
            @csrf
            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('Import {{ number_format($count) }} Aug 2024 Hollywood sales? A snapshot is saved for undo.');">
                Import Aug 2024 Hollywood sales
            </button>
        </form>
        @endif
    </div>
</div>
@endif

</section>
@endsection
