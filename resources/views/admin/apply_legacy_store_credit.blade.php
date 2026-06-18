@extends('layouts.app')
@section('title', 'Apply Legacy Store Credit')

@section('content')
<section class="content-header">
    <h1>Apply Legacy Store Credit</h1>
    <p class="text-muted">
        Bulk-applies the legacy store credit imported from the Nivessa Backend sheet —
        but ONLY to contacts that currently have a $0 balance and have never been
        credited. Anyone who already holds a balance is left untouched, so nobody gets
        double-credited.
    </p>
</section>

<section class="content">

@if(session('status'))
    @php($st = session('status'))
    <div class="alert {{ !empty($st['success']) ? 'alert-success' : 'alert-danger' }}">
        {{ $st['msg'] }}
    </div>
@endif

<div class="box box-solid">
    <div class="box-header with-border">
        <h3 class="box-title">
            {{ count($rows) }} contact(s) safe to apply — total ${{ number_format($total, 2) }}
        </h3>
    </div>
    <div class="box-body">
        @if(count($rows) === 0)
            <p>Nothing to apply. Either every legacy credit is already on an account, or no amounts were found.</p>
        @else
            <form method="POST" action="/admin/apply-legacy-store-credit/run"
                  onsubmit="return confirm('Apply ${{ number_format($total, 2) }} of store credit to {{ count($rows) }} contacts? A snapshot is taken first — this is undoable at /admin/admin-action-history.');"
                  style="margin-bottom:15px;">
                {{ csrf_field() }}
                <button type="submit" class="btn btn-primary btn-lg">
                    Apply ${{ number_format($total, 2) }} to {{ count($rows) }} contacts
                </button>
                <span class="help-block" style="display:inline-block; margin-left:10px;">
                    Snapshots balances first · skips anyone changed since this page loaded · undoable.
                </span>
            </form>

            <div style="max-height:520px; overflow:auto;">
                <table class="table table-condensed table-striped">
                    <thead>
                        <tr><th>Contact ID</th><th>Name</th><th>Phone</th><th class="text-right">Credit to apply</th></tr>
                    </thead>
                    <tbody>
                        @foreach($rows as $r)
                            <tr>
                                <td>{{ $r['contact_id'] }}</td>
                                <td>{{ $r['name'] }}</td>
                                <td>{{ $r['phone'] }}</td>
                                <td class="text-right">${{ number_format($r['csv_credit'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

</section>
@endsection
