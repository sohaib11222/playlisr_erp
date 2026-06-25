@extends('layouts.app')
@section('title', 'Live POS Timeline')
@section('content')
<section class="content-header">
    <h1>Register (live POS) Usage Timeline</h1>
    <p class="text-muted" style="max-width:900px;">Monthly count and dollars of real register sales (finalized sells with no <code>import_source</code>) per store, newest first. Shows when the ERP register actually started being used. Pure DB facts.</p>
</section>
<section class="content">
<div class="box box-solid">
    <div class="box-header"><h3 class="box-title">First register sale per store</h3></div>
    <div class="box-body">
        <ul>
        @foreach ($locations as $id => $name)
            <li><strong>{{ $name }}:</strong> {{ optional($firsts->get($id))->first_sale ?? 'none' }}</li>
        @endforeach
        </ul>
    </div>
</div>
<div class="box box-solid">
    <div class="box-header"><h3 class="box-title">Register sales by month (count / total)</h3></div>
    <div class="box-body table-responsive">
        <table class="table table-condensed table-bordered">
            <thead><tr><th>Month</th>@foreach ($locations as $name)<th style="text-align:right;">{{ $name }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($months as $ym => $byLoc)
                <tr><td><strong>{{ $ym }}</strong></td>
                @foreach ($locations as $id => $name)
                    @php($d = $byLoc[$id] ?? null)
                    <td style="text-align:right;">{{ $d ? (number_format($d['cnt']) . ' / $' . number_format($d['total'])) : '-' }}</td>
                @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
</section>
@endsection
