@extends('layouts.app')
@section('title', 'Import Duplicate Check')

@section('content')
<section class="content-header">
    <h1>Import Duplicate Check</h1>
    <p class="text-muted">
        Finds every day where both ERP-native transactions and xlsx-imported transactions exist for the same location.
        Any overlap for Sep 2025 or later is almost certainly a duplicate — the ERP was live by then, so staff was
        logging sales twice (POS + xlsx). Read-only report.
    </p>
</section>

<section class="content">

<div class="row">
    <div class="col-md-6">
        <div class="box box-solid">
            <div class="box-header"><h3 class="box-title">ERP-native sells</h3></div>
            <div class="box-body">
                <p><strong>Count:</strong> {{ number_format($erpRange->erp_total_rows ?? 0) }}</p>
                <p><strong>First:</strong> {{ $erpRange->first_erp_tx ? \Carbon\Carbon::parse($erpRange->first_erp_tx)->format('m/d/y g:i A') : '—' }}</p>
                <p><strong>Last:</strong> {{ $erpRange->last_erp_tx ? \Carbon\Carbon::parse($erpRange->last_erp_tx)->format('m/d/y g:i A') : '—' }}</p>
                <p class="help-block">
                    The first date is your effective ERP go-live — everything before that was xlsx only.
                </p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="box box-solid">
            <div class="box-header"><h3 class="box-title">Imported sells (xlsx)</h3></div>
            <div class="box-body">
                <p><strong>Count:</strong> {{ number_format($importRange->import_total_rows ?? 0) }}</p>
                <p><strong>First:</strong> {{ $importRange->first_import_tx ? \Carbon\Carbon::parse($importRange->first_import_tx)->format('m/d/y') : '—' }}</p>
                <p><strong>Last:</strong> {{ $importRange->last_import_tx ? \Carbon\Carbon::parse($importRange->last_import_tx)->format('m/d/y') : '—' }}</p>
                <p class="help-block">
                    Any import rows dated after the ERP go-live above are candidates for deletion.
                </p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-solid">
            <div class="box-header with-border">
                <h3 class="box-title">
                    Overlap days ({{ count($overlaps) }})
                    — both ERP + imported rows on the same date + location
                </h3>
            </div>
            <div class="box-body" style="padding:0;">
                <table class="table table-condensed table-striped" style="margin:0;">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Location</th>
                            <th style="text-align:right;">ERP rows</th>
                            <th style="text-align:right;">Imported rows</th>
                            <th style="text-align:right;">ERP $ total</th>
                            <th style="text-align:right;">Imported $ total</th>
                            <th style="text-align:right;">Difference</th>
                            <th>Verdict</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($overlaps as $r)
                            @php
                                $diff = abs(($r->erp_total ?? 0) - ($r->imported_total ?? 0));
                                $pctDiff = ($r->erp_total > 0) ? ($diff / $r->erp_total) * 100 : 0;
                                $isLikelyDup = $pctDiff < 20;
                                $isPostSep25 = $r->sale_date >= '2025-09-01';
                            @endphp
                            <tr style="{{ $isPostSep25 ? 'background: #fcf8e3;' : '' }}">
                                <td>{{ \Carbon\Carbon::parse($r->sale_date)->format('m/d/y') }}</td>
                                <td>{{ $r->location ?? '—' }}</td>
                                <td style="text-align:right;">{{ number_format($r->erp_rows) }}</td>
                                <td style="text-align:right;">{{ number_format($r->imported_rows) }}</td>
                                <td style="text-align:right;">${{ number_format($r->erp_total, 2) }}</td>
                                <td style="text-align:right;">${{ number_format($r->imported_total, 2) }}</td>
                                <td style="text-align:right;">${{ number_format($diff, 2) }}</td>
                                <td>
                                    @if ($isPostSep25 && $isLikelyDup)
                                        <span class="label label-danger">Likely duplicate (post-Sep 2025)</span>
                                    @elseif ($isPostSep25)
                                        <span class="label label-warning">Post-Sep 2025 — investigate</span>
                                    @else
                                        <span class="label label-default">Pre-ERP overlap</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted" style="padding:20px;">No overlap days found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</section>
@endsection
