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

<div class="box box-solid" style="border-top:3px solid #1a7f37;">
    <div class="box-header">
        <h3 class="box-title">Method check — my parser vs the live database ({{ $matched }}/{{ $checkable }} match exactly)</h3>
    </div>
    <div class="box-body table-responsive">
        <p class="text-muted">For sheets the importer ALREADY loaded, this compares my offline total to what's actually in the database for that exact source. Green = my parser reproduces the real number (so it's trustworthy for the parked sheets). Red = investigate before trusting that layout.</p>
        <table class="table table-condensed table-bordered">
            <thead><tr><th>Sheet</th><th style="text-align:right;">My total</th><th style="text-align:right;">Live DB total</th><th style="text-align:right;">Diff</th><th></th></tr></thead>
            <tbody>
            @foreach ($validation as $v)
                <tr style="{{ $v['match'] ? '' : ($v['present'] ? 'background:#fdecea;' : 'background:#f5f5f5;') }}">
                    <td>{{ $v['label'] }}</td>
                    <td style="text-align:right;">${{ number_format($v['my_final']) }}</td>
                    <td style="text-align:right;">{{ $v['present'] ? '$' . number_format($v['db_total']) : 'not in DB' }}</td>
                    <td style="text-align:right;">{{ $v['present'] ? number_format($v['delta'],2) . '%' : '—' }}</td>
                    <td>{!! $v['match'] ? '<span style=\'color:#1a7f37;\'>match</span>' : ($v['present'] ? '<span style=\'color:#c0392b;\'>differs</span>' : '<span class=\'text-muted\'>n/a</span>') !!}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

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
