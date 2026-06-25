@extends('layouts.app')
@section('title', 'Import Parked Sheets')

@section('content')
<section class="content-header">
    <h1>Import Parked Historical Sheets</h1>
    <p class="text-muted" style="max-width:940px;">
        These monthly sheets were skipped by the bulk import because their price-column header was
        a broken Excel formula (<code>#REF!</code>), a stray backtick, or blank — so the parser
        couldn't recognize them. Each one left its store/month under-counted in the
        <a href="/reports/lfl-sales">Like-for-Like report</a>. Rows were re-parsed with the importer's
        own rules and stamped to the sheet's month. Each batch imports under its own source (adds to,
        never overwrites, whatever's already on that month) and is snapshotted for one-click undo at
        <a href="/admin/admin-action-history">admin-action-history</a>. Live POS untouched.
    </p>
</section>

<section class="content">

@if (session('status'))
    <div class="alert {{ !empty(session('status')['success']) ? 'alert-success' : 'alert-danger' }}">
        {{ session('status')['msg'] }}
    </div>
@endif

<div class="box box-solid">
    <div class="box-header"><h3 class="box-title">Parked sheets</h3></div>
    <div class="box-body table-responsive">
        <table class="table table-condensed table-bordered">
            <thead>
                <tr>
                    <th>Month / store</th>
                    <th style="text-align:right;">Rows</th>
                    <th style="text-align:right;">Showing now</th>
                    <th style="text-align:right;">Sheet adds</th>
                    <th style="text-align:right;">Projected</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @foreach ($batches as $b)
                <tr>
                    <td><strong>{{ $b['label'] }}</strong><br><code style="font-size:11px;">{{ $b['import_source'] }}</code></td>
                    <td style="text-align:right;">{{ number_format($b['rows']) }}</td>
                    <td style="text-align:right;">${{ number_format($b['current']) }}</td>
                    <td style="text-align:right;">{{ $b['done'] ? '—' : '$' . number_format($b['final']) }}</td>
                    <td style="text-align:right;"><strong>${{ number_format($b['projected']) }}</strong></td>
                    <td>
                        @if (!$b['locId'])
                            <span class="text-muted">no location</span>
                        @endif
                        @if ($b['locId'] && $b['done'])
                            <span class="text-muted">imported</span>
                        @endif
                        @if ($b['locId'] && !$b['done'])
                        <form method="POST" action="/admin/import-parked-sheets/run" style="display:inline;">
                            @csrf
                            <input type="hidden" name="import_source" value="{{ $b['import_source'] }}">
                            <button type="submit" class="btn btn-primary btn-sm"
                                    onclick="return confirm('Import {{ $b['label'] }} ({{ number_format($b['rows']) }} rows, adds ${{ number_format($b['final']) }})? Snapshot saved for undo.');">
                                Import
                            </button>
                        </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <p class="text-muted">"Showing now" is what the store/month totals today; "Projected" is after import. Sanity-check before applying.</p>
    </div>
</div>

</section>
@endsection
