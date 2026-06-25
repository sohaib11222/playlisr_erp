@extends('layouts.app')
@section('title', 'Fix Misfiled In-Store Sales')

@section('content')
<section class="content-header">
    <h1>Fix Misfiled In-Store Sales (Hollywood &rarr; Pico)</h1>
    <p class="text-muted" style="max-width:920px;">
        The historical importer dumped every store-agnostic "in store sales" sheet onto
        <strong>Hollywood</strong>, but Hollywood didn't open until <strong>June 2024</strong> — so any
        in-store sale before then is really <strong>Pico</strong>. That's why Pico's older months read $0
        (the <code>n/a</code> cells in Like-for-Like) and Hollywood shows revenue from before it existed.
        Rows dated before the cutoff are pre-checked. Review and apply — a snapshot is saved so you can
        undo at <a href="/admin/admin-action-history">admin-action-history</a>. Read of live POS is untouched.
    </p>
</section>

<section class="content">

@if (session('status'))
    @php($st = session('status'))
    <div class="alert {{ !empty($st['success']) ? 'alert-success' : 'alert-danger' }}">
        {{ $st['msg'] }}
    </div>
@endif

@if (!$fromId || !$toId)
    <div class="alert alert-danger">
        Couldn't resolve both locations (Hollywood and Pico) by name. Nothing to show.
    </div>
@else

<form method="GET" action="/admin/move-import-location" class="form-inline" style="margin-bottom:14px;">
    <label>Cutoff (sales before this date = Pico): </label>
    <input type="date" name="cutoff" value="{{ $cutoff }}" class="form-control" style="margin:0 8px;">
    <button class="btn btn-default">Reload preview</button>
</form>

@if ($groups->isEmpty())
    <div class="alert alert-info">No in-store import batches are sitting on Hollywood. Nothing to move.</div>
@else
<form method="POST" action="/admin/move-import-location/run">
    @csrf
    <input type="hidden" name="cutoff" value="{{ $cutoff }}">

    @php
        $totalPre = 0; $revPre = 0;
        foreach ($groups as $g) { if ($g->pre_cutoff) { $totalPre += $g->cnt; $revPre += $g->revenue; } }
    @endphp

    <div class="box box-solid">
        <div class="box-header">
            <h3 class="box-title">
                Misfiled in-store sells on Hollywood — {{ number_format($totalPre) }} tx /
                ${{ number_format($revPre) }} pre-cutoff (pre-checked)
            </h3>
        </div>
        <div class="box-body table-responsive">
            <p>
                <a href="#" onclick="document.querySelectorAll('.grp').forEach(c=>c.checked=true);return false;">Select all</a> ·
                <a href="#" onclick="document.querySelectorAll('.grp').forEach(c=>c.checked=false);return false;">Select none</a> ·
                <a href="#" onclick="document.querySelectorAll('.grp').forEach(c=>c.checked=c.dataset.pre==='1');return false;">Pre-cutoff only</a>
            </p>
            <table class="table table-condensed table-bordered">
                <thead>
                    <tr>
                        <th></th>
                        <th>Import batch (import_source)</th>
                        <th>Month</th>
                        <th style="text-align:right;">Tx</th>
                        <th style="text-align:right;">Revenue</th>
                        <th>Before Hollywood opened?</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($groups as $g)
                    <tr style="{{ $g->pre_cutoff ? '' : 'background:#fcf8e3;' }}">
                        <td>
                            <input type="checkbox" class="grp" name="groups[]"
                                   data-pre="{{ $g->pre_cutoff ? '1' : '0' }}"
                                   value="{{ $g->import_source }}|{{ $g->ym }}"
                                   {{ $g->pre_cutoff ? 'checked' : '' }}>
                        </td>
                        <td><code>{{ $g->import_source }}</code></td>
                        <td>{{ $g->ym }}</td>
                        <td style="text-align:right;">{{ number_format($g->cnt) }}</td>
                        <td style="text-align:right;">${{ number_format($g->revenue) }}</td>
                        <td>
                            @if ($g->pre_cutoff)
                                <span style="color:#1a7f37;">yes — move to Pico</span>
                            @else
                                <span style="color:#8a6d3b;">no — review before moving</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="box-footer">
            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('Move the checked batches from Hollywood to Pico? A snapshot is saved for undo.');">
                Move checked to Pico
            </button>
        </div>
    </div>
</form>
@endif

@endif

</section>
@endsection
