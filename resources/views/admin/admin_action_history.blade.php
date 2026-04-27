@extends('layouts.app')
@section('title', 'Admin Action History')

@section('content')
<section class="content-header">
    <h1>Admin Action History</h1>
    <p class="text-muted">
        Every destructive admin backfill writes a JSON snapshot of the BEFORE
        state here before applying changes. Click <strong>Undo</strong> to
        restore the affected rows to their previous values.
    </p>
</section>

<section class="content">

@if (session('status'))
    <div class="alert {{ session('status')['success'] ? 'alert-success' : 'alert-danger' }}">
        {{ session('status')['msg'] }}
    </div>
@endif

<div class="row">
    <div class="col-md-12">
        <div class="box box-solid">
            <div class="box-body">
                @if (empty($snapshots))
                    <p class="text-muted">No admin-action snapshots on disk yet.</p>
                @else
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Action</th>
                                <th>Direction</th>
                                <th style="text-align:right;">Rows</th>
                                <th>Snapshot key</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($snapshots as $s)
                                <tr>
                                    <td>{{ $s->timestamp }}</td>
                                    <td>{{ $s->action }}</td>
                                    <td>{{ $s->direction ?? '—' }}</td>
                                    <td style="text-align:right;"><strong>{{ number_format($s->rows_count) }}</strong></td>
                                    <td><code>{{ $s->key }}</code></td>
                                    <td>
                                        <form method="POST" action="{{ url('/admin/admin-action-history/undo') }}"
                                              onsubmit="return confirm('Restore {{ $s->rows_count }} rows from snapshot {{ $s->key }}? This will overwrite current values for those rows.');"
                                              style="margin:0;">
                                            @csrf
                                            <input type="hidden" name="key" value="{{ $s->key }}">
                                            <button type="submit" class="btn btn-warning btn-xs">Undo</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</div>

</section>
@endsection
