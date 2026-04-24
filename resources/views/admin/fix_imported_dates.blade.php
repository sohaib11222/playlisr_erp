@extends('layouts.app')
@section('title', 'Fix Imported Dates')

@section('content')
<section class="content-header">
    <h1>Fix Imported Dates</h1>
    <p class="text-muted">
        Historical xlsx imports wrote some transactions with future dates (&gt; {{ $cutoff }}).
        This rewrites those dates to the 1st of the month encoded in each sheet name
        (e.g. <code>PICO OCT 25</code> → 2025-10-01).
        Only touches rows where <code>import_source</code> begins with <code>nivessa_backend_sales_</code>.
    </p>
</section>

<section class="content">

<div class="row">
    <div class="col-md-12">
        <div class="box box-solid">
            <div class="box-body">
                <form method="POST" action="{{ url('/admin/fix-imported-dates/run') }}" id="fid-form">
                    @csrf
                    <input type="hidden" name="commit" id="fid-commit" value="0">
                    <button type="button" class="btn btn-default btn-lg" onclick="fidSubmit(0)">Preview</button>
                    <button type="button" class="btn btn-primary btn-lg" onclick="fidSubmit(1)">Apply</button>
                    <span id="fid-status" class="help-block" style="display:inline-block;margin-left:12px;vertical-align:middle;">
                        Preview first to verify the per-sheet counts. Apply rewrites dates.
                    </span>
                </form>
                <script>
                    function fidSubmit(commit) {
                        document.getElementById('fid-commit').value = commit;
                        document.getElementById('fid-status').innerHTML =
                            '<span style="color:#c00;font-weight:bold;">' +
                            (commit ? 'Applying — rewriting dates…' : 'Running preview…') +
                            '</span>';
                        document.getElementById('fid-form').submit();
                    }
                </script>
            </div>
        </div>
    </div>
</div>

@if ($mode !== null)
<div class="row">
    <div class="col-md-12">
        <div class="box box-solid" style="border: 3px solid {{ $mode === 'commit' ? '#00a65a' : '#3c8dbc' }};">
            <div class="box-header" style="background: {{ $mode === 'commit' ? '#dff0d8' : '#d9edf7' }};">
                <h3 class="box-title" style="font-size:20px;">
                    @if ($mode === 'commit')
                        ✅ Applied — {{ number_format($updated_total ?? 0) }} transactions rewritten
                    @else
                        Preview — rows that would be updated
                    @endif
                </h3>
            </div>
        </div>
    </div>
</div>
@endif

<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">Per-sheet breakdown ({{ count($breakdown) }} sheets)</h3>
            </div>
            <div class="box-body" style="padding:0;">
                <table class="table table-condensed table-striped" style="margin:0;">
                    <thead>
                        <tr>
                            <th>Sheet</th>
                            <th>Bad row count</th>
                            <th>Current bad dates (min → max)</th>
                            <th>Will rewrite to</th>
                            @if ($mode === 'commit')
                                <th>Updated</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($breakdown as $row)
                            <tr>
                                <td>{{ $row['sheet_label'] }}</td>
                                <td>{{ number_format($row['bad_rows']) }}</td>
                                <td>
                                    <small>
                                        {{ \Carbon\Carbon::parse($row['min_bad_date'])->format('m/d/y') }}
                                        →
                                        {{ \Carbon\Carbon::parse($row['max_bad_date'])->format('m/d/y') }}
                                    </small>
                                </td>
                                <td>
                                    @if ($row['derived_date'])
                                        <strong>{{ \Carbon\Carbon::parse($row['derived_date'])->format('m/d/y') }}</strong>
                                    @else
                                        <span class="text-danger">
                                            — can't derive date from sheet name, will be skipped
                                        </span>
                                    @endif
                                </td>
                                @if ($mode === 'commit')
                                    <td>{{ number_format($updated[$row['import_source']] ?? 0) }}</td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ $mode === 'commit' ? 5 : 4 }}" class="text-center text-muted" style="padding:20px;">
                                    No imported transactions with future dates. Nothing to fix.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@if (!empty($samples))
<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">Sample (first 10 affected rows)</h3>
            </div>
            <div class="box-body" style="padding:0;">
                <table class="table table-condensed table-striped" style="margin:0;">
                    <thead>
                        <tr>
                            <th style="width:80px;">Tx ID</th>
                            <th>Sheet</th>
                            <th>Current date</th>
                            <th>→ New date</th>
                            <th style="width:100px;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($samples as $s)
                            <tr>
                                <td>{{ $s['id'] }}</td>
                                <td>{{ $s['sheet_label'] }}</td>
                                <td>{{ \Carbon\Carbon::parse($s['current_date'])->format('m/d/y g:i A') }}</td>
                                <td>
                                    @if ($s['target_date'])
                                        <strong>{{ \Carbon\Carbon::parse($s['target_date'])->format('m/d/y') }}</strong>
                                    @else
                                        <span class="text-danger">— (skipped)</span>
                                    @endif
                                </td>
                                <td>${{ number_format($s['amount'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif

</section>
@endsection
