@extends('layouts.app')
@section('title', 'ERP vs Clover')
@section('content')
<section class="content-header">
    <h1>ERP Sales vs Clover (monthly, by store)</h1>
    <p class="text-muted" style="max-width:960px;">
        ERP finalized sales (what the LFL sums) next to Clover's own approved card sales, for the months
        Clover data exists. <strong>Clover is card-only</strong>, so ERP should be <em>higher</em> by the
        cash amount (shown as "implied cash"). If ERP is <em>lower</em> than Clover, the ERP is missing
        card entries (the known Clover-to-ERP gap). Pure DB facts.
    </p>
</section>
<section class="content">
<div class="box box-solid"><div class="box-body table-responsive">
<table class="table table-condensed table-bordered">
    <thead><tr><th>Month</th>
    @foreach ($locations as $name)
        <th style="text-align:center;" colspan="3">{{ $name }}</th>
    @endforeach
    </tr>
    <tr><th></th>
    @foreach ($locations as $name)
        <th style="text-align:right;">ERP</th><th style="text-align:right;">Clover</th><th style="text-align:right;">implied cash</th>
    @endforeach
    </tr></thead>
    <tbody>
    @foreach ($months as $ym => $byLoc)
        <tr><td><strong>{{ $ym }}</strong></td>
        @foreach ($locations as $id => $name)
            @php($d = $byLoc[$id] ?? [])
            @php($erp = $d['erp'] ?? 0)
            @php($clv = $d['clover'] ?? 0)
            @php($cash = $erp - $clv)
            <td style="text-align:right;">${{ number_format($erp) }}</td>
            <td style="text-align:right;">${{ number_format($clv) }}</td>
            <td style="text-align:right; {{ $cash < 0 ? 'background:#fdecea;color:#c0392b;' : '' }}">${{ number_format($cash) }}</td>
        @endforeach
        </tr>
    @endforeach
    </tbody>
</table>
<p class="text-muted">"Implied cash" = ERP - Clover. Positive = cash sales (expected). Red/negative = ERP missing card sales Clover has.</p>
</div></div>
</section>
@endsection
