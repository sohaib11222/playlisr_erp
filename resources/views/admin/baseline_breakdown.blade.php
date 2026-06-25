@extends('layouts.app')
@section('title', 'Baseline Breakdown')

@section('content')
<section class="content-header">
    <h1>Prior-Year Baseline — Exact Source Breakdown</h1>
    <p class="text-muted" style="max-width:920px;">
        Every <code>import_source</code> contributing to the four prior-year months the parked-sheet
        import touched. Straight <code>SUM(final_total)</code> / <code>COUNT</code> from the live
        database — nothing inferred. The row tagged <strong>(just imported)</strong> is what we added;
        everything else was already there. If the pre-existing rows are separate channels (web/Discogs)
        the baseline is correct; if they're a duplicate in-store source, that's the overlap to remove.
    </p>
</section>

<section class="content">

@foreach ($cells as $c)
<div class="box box-solid">
    <div class="box-header">
        <h3 class="box-title">{{ $c['label'] }} — total ${{ number_format($c['total']) }}</h3>
    </div>
    <div class="box-body table-responsive">
        <table class="table table-condensed table-bordered" style="max-width:760px;">
            <thead><tr><th>import_source</th><th style="text-align:right;">Tx</th><th style="text-align:right;">$ (incl. tax)</th><th></th></tr></thead>
            <tbody>
            @foreach ($c['rows'] as $r)
                <tr style="{{ $r->src === $c['addedSource'] ? 'background:#eafaf0;' : '' }}">
                    <td><code>{{ $r->src }}</code></td>
                    <td style="text-align:right;">{{ number_format($r->cnt) }}</td>
                    <td style="text-align:right;">${{ number_format($r->total) }}</td>
                    <td>{!! $r->src === $c['addedSource'] ? '<strong style=\'color:#1a7f37;\'>(just imported)</strong>' : '' !!}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        @if (!empty($c['liveStats']) && $c['liveStats']->cnt > 0)
        <div style="margin-top:10px; padding:10px; background:#f8f9fa; border:1px solid #ddd; max-width:760px;">
            <strong>(live POS) forensics</strong> — {{ number_format($c['liveStats']->cnt) }} txns, ${{ number_format($c['liveStats']->total) }}.
            Created between <code>{{ $c['liveStats']->min_c }}</code> and <code>{{ $c['liveStats']->max_c }}</code>.
            <div class="text-muted" style="font-size:12px; margin-top:4px;">
                If created back in {{ $c['month'] }} → real register sales (separate, keep). If created recently (2026) → entered later (check for overlap).
            </div>
            <table class="table table-condensed" style="margin-top:6px; max-width:720px;">
                <thead><tr><th>id</th><th>invoice</th><th>sale date</th><th>created_at</th><th style="text-align:right;">$</th></tr></thead>
                <tbody>
                @foreach ($c['liveSamples'] as $s)
                    <tr><td>{{ $s->id }}</td><td>{{ $s->invoice_no }}</td><td>{{ $s->transaction_date }}</td><td>{{ $s->created_at }}</td><td style="text-align:right;">${{ number_format($s->final_total,2) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
@endforeach

</section>
@endsection
