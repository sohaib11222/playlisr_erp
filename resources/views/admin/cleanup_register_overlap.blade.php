@extends('layouts.app')
@section('title', 'Clean Up Register Overlap')
@section('content')
<section class="content-header">
    <h1>Clean Up — Register Overlap (4 baseline months)</h1>
    <p class="text-muted" style="max-width:900px;">
        Removes the small <code>(live POS)</code> register sales from the 4 months whose spreadsheet was
        just imported, so each month equals the spreadsheet total (the complete record from when the
        register was brand-new). Full rows are snapshotted first — one-click restore at
        <a href="/admin/admin-action-history">admin-action-history</a>. Only these 4 old months; live POS untouched.
    </p>
</section>
<section class="content">
@if (session('status'))
    <div class="alert {{ !empty(session('status')['success']) ? 'alert-success' : 'alert-danger' }}">{{ session('status')['msg'] }}</div>
@endif
<div class="box box-solid">
    <div class="box-header"><h3 class="box-title">What will be removed</h3></div>
    <div class="box-body">
        <table class="table table-condensed table-bordered" style="max-width:560px;">
            <thead><tr><th>Month</th><th style="text-align:right;">Register sales to remove</th></tr></thead>
            <tbody>
            @foreach ($cells as $c)
                <tr><td>{{ $c['label'] }}</td><td style="text-align:right;">{{ number_format($c['count']) }} / ${{ number_format($c['sum']) }}</td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="box-footer">
        <form method="POST" action="/admin/cleanup-register-overlap/run" style="display:inline;">
            @csrf
            <button type="submit" class="btn btn-danger" onclick="return confirm('Remove these register sales from the 4 months? A snapshot is saved so you can undo.');">
                Remove register overlap (match spreadsheet)
            </button>
        </form>
    </div>
</div>
</section>
@endsection
