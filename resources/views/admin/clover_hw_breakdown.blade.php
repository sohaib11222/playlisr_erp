@extends('layouts.app')
@section('title', 'Clover HW Breakdown')
@section('content')
<section class="content-header"><h1>Hollywood Clover — what's in it (Mar–May 2026)</h1>
<p class="text-muted">Read-only anatomy of Hollywood's Clover payments to find what pushes Clover above the ERP.</p></section>
<section class="content">
@if (!$loc)<div class="alert alert-danger">Hollywood not found.</div>@endif
@if ($loc)
<div class="box box-solid"><div class="box-body">
<p><strong>All payments:</strong> {{ number_format($all->c) }} payments across {{ number_format($all->orders) }} orders · total ${{ number_format($all->amt) }} · tips ${{ number_format($all->tips) }}</p>
<p><strong>Orders with more than one payment (split / auth+capture / dupes):</strong> {{ number_format($multiCount) }}</p>
<h4>By result</h4>
<table class="table table-condensed table-bordered" style="max-width:480px;"><thead><tr><th>result</th><th style="text-align:right;">count</th><th style="text-align:right;">$</th></tr></thead><tbody>
@foreach ($byResult as $r)<tr><td>{{ $r->r }}</td><td style="text-align:right;">{{ number_format($r->c) }}</td><td style="text-align:right;">${{ number_format($r->amt) }}</td></tr>@endforeach
</tbody></table>
<h4>By tender type</h4>
<table class="table table-condensed table-bordered" style="max-width:560px;"><thead><tr><th>tender_type</th><th style="text-align:right;">count</th><th style="text-align:right;">$</th></tr></thead><tbody>
@foreach ($byTender as $t)<tr><td><code>{{ $t->t }}</code></td><td style="text-align:right;">{{ number_format($t->c) }}</td><td style="text-align:right;">${{ number_format($t->amt) }}</td></tr>@endforeach
</tbody></table>
</div></div>
@endif
</section>
@endsection
