@extends('layouts.app')
@section('title', 'Fix Imported Dates')

@section('content')
<section class="content-header">
    <h1>Fix Imported Dates</h1>
    <p class="text-muted">
        Historical xlsx imports carried date typos forward — a single bad date row
        in the source sheet drags every item below it to the wrong year.
        This page flags two kinds of bad rows in <code>nivessa_backend_sales_*</code> imports:
    </p>
    <ul class="text-muted" style="margin-top:-6px;">
        <li><strong>Sheet-name encodes a year</strong> (e.g. <code>HW SEP 25</code> → 2025): any row whose year doesn't match the sheet year is bad. Catches both 2014-style past strays and 2026-style future strays. Rewrites to the 1st of the encoded month.</li>
        <li><strong>Sheet-name has no year</strong> (e.g. <code>IN STORE NEW USED SALES</code>): only future-dated rows (&gt; {{ $cutoff }}) are flagged. Type a <code>YYYY-MM-DD</code> override in the row to enable a year-mismatch fix here too.</li>
    </ul>
    <p class="text-muted" style="margin-top:8px;">
        <strong>Reference (per Sarah):</strong> Nivessa opened 2021. Pico opened 2022. Hollywood opened June 2024.
        Nothing before those dates is real for the relevant store.
    </p>
</section>

<section class="content">

<form method="POST" action="{{ url('/admin/fix-imported-dates/run') }}" id="fid-form">
    @csrf
    <input type="hidden" name="commit" id="fid-commit" value="0">

    <div class="row">
        <div class="col-md-12">
            <div class="box box-solid">
                <div class="box-body">
                    <button type="button" class="btn btn-default btn-lg" onclick="fidSubmit(0)">Preview</button>
                    <button type="button" class="btn btn-primary btn-lg" onclick="fidSubmit(1)">Apply</button>
                    <span id="fid-status" class="help-block" style="display:inline-block;margin-left:12px;vertical-align:middle;">
                        Preview first to verify the per-sheet counts.
                        Apply rewrites dates and saves a BEFORE snapshot so you can undo from
                        <a href="{{ url('/admin/admin-action-history') }}">admin-action-history</a>.
                    </span>
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
                            Applied — {{ number_format($updated_total ?? 0) }} transactions rewritten
                        @else
                            Preview — rows that would be updated
                        @endif
                    </h3>
                </div>
                @if ($mode === 'commit' && !empty($snapshot_key))
                    <div class="box-body" style="background: #dff0d8;">
                        Saved BEFORE state to snapshot
                        <code>{{ $snapshot_key }}</code>.
                        If anything looks wrong, undo from
                        <a href="{{ url('/admin/admin-action-history') }}">/admin/admin-action-history</a>.
                    </div>
                @endif
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
                                <th>Current bad dates (min &rarr; max)</th>
                                <th>Will rewrite to</th>
                                <th>Override (YYYY-MM-DD)</th>
                                @if ($mode === 'commit')
                                    <th>Updated</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($breakdown as $row)
                                <tr>
                                    <td><strong>{{ $row['sheet_label'] }}</strong></td>
                                    <td>{{ number_format($row['bad_rows']) }}</td>
                                    <td>
                                        @if ($row['min_bad_date'])
                                            <small>
                                                {{ \Carbon\Carbon::parse($row['min_bad_date'])->format('m/d/y') }}
                                                &rarr;
                                                {{ \Carbon\Carbon::parse($row['max_bad_date'])->format('m/d/y') }}
                                            </small>
                                        @else
                                            <small class="text-muted">—</small>
                                        @endif
                                    </td>
                                    <td>
                                        @if ($row['target_date'])
                                            <strong>{{ \Carbon\Carbon::parse($row['target_date'])->format('m/d/y') }}</strong>
                                            @if ($row['override'])
                                                <small class="text-muted">(from override)</small>
                                            @elseif ($row['derived_date'])
                                                <small class="text-muted">(from sheet name)</small>
                                            @endif
                                        @else
                                            <span class="text-danger">
                                                — no date derivable, supply override &rarr;
                                            </span>
                                        @endif
                                    </td>
                                    <td>
                                        <input
                                            type="text"
                                            name="override[{{ $row['import_source'] }}]"
                                            value="{{ $overrides[$row['import_source']] ?? '' }}"
                                            placeholder="2024-04-26"
                                            class="form-control input-sm"
                                            style="max-width:140px;"
                                            form="fid-form"
                                        >
                                    </td>
                                    @if ($mode === 'commit')
                                        <td>{{ number_format($updated[$row['import_source']] ?? 0) }}</td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $mode === 'commit' ? 6 : 5 }}" class="text-center text-muted" style="padding:20px;">
                                        No imported transactions with bad dates. Nothing to fix.
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
                    <h3 class="box-title">Sample (first {{ count($samples) }} affected rows)</h3>
                </div>
                <div class="box-body" style="padding:0;">
                    <table class="table table-condensed table-striped" style="margin:0;">
                        <thead>
                            <tr>
                                <th style="width:80px;">Tx ID</th>
                                <th>Sheet</th>
                                <th>Current date</th>
                                <th>&rarr; New date</th>
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
</form>

</section>
@endsection
