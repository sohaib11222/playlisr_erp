@extends('layouts.app')
@section('title', 'Clover Gap Preview')
@section('content')
<section class="content-header">
    <h1>Missing Hollywood Card Sales (Clover not in ERP)</h1>
    <p class="text-muted" style="max-width:920px;">Clover card sales at Hollywood (Mar-May 2026) that are NOT linked to an ERP sale and have no same-day ERP total within $0.50 — i.e. card sales that were run on Clover but never entered in the ERP. Read-only; nothing is created. This is the gap to fill so the LFL matches Clover.</p>
</section>
<section class="content">
<div class="box box-solid"><div class="box-body">
@if (!$loc)
    <div class="alert alert-danger">Hollywood location not found.</div>
@endif
@if ($loc)
<table class="table table-condensed table-bordered" style="max-width:520px;">
<thead><tr><th>Month</th><th style="text-align:right;">Missing card sales</th></tr></thead>
<tbody>
@php($tot = 0)
@foreach ($groups as $ym => $g)
    @php($tot += $g['sum'])
    <tr><td>{{ $ym }}</td><td style="text-align:right;">{{ number_format($g['cnt']) }} / ${{ number_format($g['sum']) }}</td></tr>
@endforeach
<tr><td><strong>Total</strong></td><td style="text-align:right;"><strong>${{ number_format($tot) }}</strong></td></tr>
</tbody>
</table>
<p class="text-muted">If this total is close to the ~$19k gap, these are the sales to enter into the ERP.</p>
@endif
</div></div>
</section>
@endsection
