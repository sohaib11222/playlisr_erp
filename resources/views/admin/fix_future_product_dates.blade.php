@extends('layouts.app')
@section('title', 'Fix Future Product Dates')

@section('content')
<section class="content-header">
    <h1>Fix Future Product Dates</h1>
    <p class="text-muted">
        Some products got <code>created_at</code> / <code>updated_at</code> values that are in the future
        (suspected: a sync run with the wrong server time). Those rows poison the
        <a href="/products">/products</a> report's date columns and sort.
        Apply nulls them out — the list will then render those columns as "—".
        Snapshot is saved to <a href="/admin/admin-action-history">admin action history</a> so it can be undone.
    </p>
</section>

<section class="content">

<div class="row">
    <div class="col-md-12">
        <div class="box box-solid">
            <div class="box-body">
                <form method="POST" action="{{ url('/admin/fix-future-product-dates/run') }}" id="fpd-form">
                    @csrf
                    <input type="hidden" name="commit" id="fpd-commit" value="0">
                    <button type="button" class="btn btn-default btn-lg" onclick="fpdSubmit(0)">Preview</button>
                    <button type="button" class="btn btn-primary btn-lg" onclick="fpdSubmit(1)">Apply</button>
                    <span id="fpd-status" class="help-block" style="display:inline-block;margin-left:12px;vertical-align:middle;">
                        Preview shows the count + sample rows. Apply nulls the bad timestamps.
                    </span>
                </form>
                <script>
                    function fpdSubmit(commit) {
                        document.getElementById('fpd-commit').value = commit;
                        document.getElementById('fpd-status').innerHTML =
                            '<span style="color:#c00;font-weight:bold;">' +
                            (commit ? 'Applying — clearing future timestamps…' : 'Running preview…') +
                            '</span>';
                        document.getElementById('fpd-form').submit();
                    }
                </script>
            </div>
        </div>
    </div>
</div>

@if ($mode !== null)
<div class="row">
    <div class="col-md-12">
        <div class="box box-solid" style="border: 3px solid {{ $mode === 'commit' ? '#00a65a' : '#3c8dbc' }};">
            <div class="box-header" style="background: {{ $mode === 'commit' ? '#dff0d8' : '#d9edf7' }};">
                <h3 class="box-title" style="font-size:20px;">
                    @if ($mode === 'commit')
                        ✅ Applied —
                        cleared {{ number_format($created_cleared ?? 0) }} future <code>created_at</code>,
                        {{ number_format($updated_cleared ?? 0) }} future <code>updated_at</code>
                        across {{ number_format($rows_touched ?? 0) }} rows.
                    @else
                        Preview — current state
                    @endif
                </h3>
            </div>
        </div>
    </div>
</div>
@endif

<div class="row">
    <div class="col-md-6">
        <div class="box box-default">
            <div class="box-header with-border"><h3 class="box-title">Counts</h3></div>
            <div class="box-body">
                <p>Products with future <code>created_at</code>: <strong>{{ number_format($future_created) }}</strong></p>
                <p>Products with future <code>updated_at</code>: <strong>{{ number_format($future_updated) }}</strong></p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">Sample (first 15 affected rows)</h3>
            </div>
            <div class="box-body" style="padding:0;">
                <table class="table table-condensed table-striped" style="margin:0;">
                    <thead>
                        <tr>
                            <th style="width:80px;">ID</th>
                            <th>Name</th>
                            <th>SKU</th>
                            <th>created_at</th>
                            <th>updated_at</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($samples as $s)
                            <tr>
                                <td>{{ $s->id }}</td>
                                <td>{{ $s->name }}</td>
                                <td>{{ $s->sku }}</td>
                                <td>
                                    @if ($s->created_at && strtotime($s->created_at) > time())
                                        <span class="text-danger">{{ \Carbon\Carbon::parse($s->created_at)->format('m/d/y g:i A') }}</span>
                                    @elseif ($s->created_at)
                                        {{ \Carbon\Carbon::parse($s->created_at)->format('m/d/y g:i A') }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    @if ($s->updated_at && strtotime($s->updated_at) > time())
                                        <span class="text-danger">{{ \Carbon\Carbon::parse($s->updated_at)->format('m/d/y g:i A') }}</span>
                                    @elseif ($s->updated_at)
                                        {{ \Carbon\Carbon::parse($s->updated_at)->format('m/d/y g:i A') }}
                                    @else
                                        —
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-muted" style="padding:20px;">
                                    Nothing to fix — no products with future timestamps.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</section>
@endsection
