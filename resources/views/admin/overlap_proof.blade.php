@extends('layouts.app')
@section('title', 'Overlap Proof')
@section('content')
<section class="content-header">
    <h1>Register vs Sheet - Overlap Proof</h1>
    <p class="text-muted" style="max-width:920px;">For each baseline month, every live-POS register sale is matched to a sheet row at the exact same price (multiset - a sheet row is consumed once). <strong>Overlaps sheet</strong> = register sales that have a price twin in the imported sheet (likely the same sale, double-counted). <strong>Unique to register</strong> = no sheet twin (genuinely separate). Real rows only.</p>
</section>
<section class="content">
<div class="box box-solid"><div class="box-body table-responsive">
<table class="table table-bordered">
<thead><tr><th>Month</th><th style="text-align:right;">Register sales</th><th style="text-align:right;">Overlaps sheet (trim?)</th><th style="text-align:right;">Unique to register (keep)</th></tr></thead>
<tbody>
@foreach ($cells as $c)
<tr>
    <td><strong>{{ $c['label'] }}</strong></td>
    <td style="text-align:right;">{{ number_format($c['posCnt']) }} / ${{ number_format($c['posVal']) }}</td>
    <td style="text-align:right; {{ $c['matchVal'] > $c['uniqVal'] ? 'background:#fdecea;' : '' }}">{{ number_format($c['matchCnt']) }} / <strong>${{ number_format($c['matchVal']) }}</strong> ({{ $c['posVal']>0 ? round($c['matchVal']/$c['posVal']*100) : 0 }}%)</td>
    <td style="text-align:right;">{{ number_format($c['uniqCnt']) }} / ${{ number_format($c['uniqVal']) }}</td>
</tr>
@endforeach
</tbody>
</table>
<p class="text-muted">If "overlaps sheet" is ~100%, those register sales are duplicates of the imported sheet and should be trimmed from the baseline.</p>
</div></div>
</section>
@endsection
