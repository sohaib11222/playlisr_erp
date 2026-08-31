@extends('layouts.app')
@section('title', 'Zero Stock Rules')

@section('content')
<section class="content-header">
    <h1>Zero Stock Rules</h1>
    <p class="text-muted">Sets Current Stock to 0 for named groups of products. Only touches variation stock rows currently above 0.</p>
</section>

<section class="content">

<div class="row">
    <div class="col-md-12">
        <div class="box box-solid">
            <div class="box-body">
                <h4 style="margin-top:0;">Rules</h4>
                <ul>
                    <li>Name starts with "RETIRED:" and the product is not in the Apparel category</li>
                    <li>Kanye West - Graduation, Vinyl and Cassette formats only</li>
                </ul>

                <form method="POST" action="{{ url('/admin/zero-stock-rules/run') }}" style="margin-top:16px;" id="zsr-form">
                    @csrf
                    <input type="hidden" name="commit" id="zsr-commit" value="0">
                    <button type="button" class="btn btn-default btn-lg" onclick="zsrSubmit(0)">Preview</button>
                    <button type="button" class="btn btn-primary btn-lg" onclick="zsrSubmit(1)">Apply</button>
                    <span id="zsr-status" class="help-block" style="display:inline-block;margin-left:12px;vertical-align:middle;">
                        Preview first to confirm which products match. Apply writes 0 to stock.
                    </span>
                </form>
                <script>
                    function zsrSubmit(commit) {
                        document.getElementById('zsr-commit').value = commit;
                        document.getElementById('zsr-status').innerHTML =
                            '<span style="color:#c00;font-weight:bold;">' +
                            (commit ? 'Applying — writing to DB, do not close this tab...' : 'Running preview...') +
                            '</span>';
                        document.getElementById('zsr-form').submit();
                    }
                </script>
            </div>
        </div>
    </div>
</div>

@if ($results !== null)
<div class="row">
    <div class="col-md-12">
        <div class="box box-solid" style="border: 3px solid {{ $mode === 'commit' ? '#00a65a' : '#3c8dbc' }};">
            <div class="box-header" style="background: {{ $mode === 'commit' ? '#dff0d8' : '#d9edf7' }};">
                <h3 class="box-title" style="font-size:20px;">
                    @if ($mode === 'commit')
                        Applied — {{ number_format($grand_zeroed) }} stock row(s) zeroed
                        @if ($snapshot_key)
                            <span class="help-block" style="margin:4px 0 0;font-size:13px;">
                                Snapshot <code>{{ $snapshot_key }}</code> — undo anytime at
                                <a href="{{ url('/admin/admin-action-history') }}">Admin Action History</a>.
                            </span>
                        @endif
                    @else
                        Preview — {{ number_format($grand_rows) }} stock row(s) would be zeroed
                    @endif
                </h3>
            </div>
            <div class="box-body" style="padding:0;">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Rule</th>
                            <th style="text-align:right;">Products matched</th>
                            <th style="text-align:right;">Stock rows &gt; 0</th>
                            <th style="text-align:right;">Total units</th>
                            <th style="text-align:right;">{{ $mode === 'commit' ? 'Zeroed' : '—' }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($results as $row)
                            <tr>
                                <td>{{ $row['label'] }}</td>
                                <td style="text-align:right;">{{ number_format($row['matched_products']) }}</td>
                                <td style="text-align:right;">{{ number_format($row['rows_with_stock']) }}</td>
                                <td style="text-align:right;">{{ number_format($row['stock_to_clear']) }}</td>
                                <td style="text-align:right;">
                                    {{ $mode === 'commit' ? number_format($row['zeroed']) : '—' }}
                                </td>
                            </tr>
                            @if (count($row['preview']) > 0)
                                <tr>
                                    <td colspan="5" style="padding:0;">
                                        <table class="table table-condensed" style="margin:0;">
                                            <thead>
                                                <tr>
                                                    <th style="width:80px;padding-left:32px;">Product ID</th>
                                                    <th>Name</th>
                                                    <th style="text-align:right;width:140px;">{{ $mode === 'commit' ? 'Stock cleared' : 'Current stock' }}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach ($row['preview'] as $p)
                                                    <tr>
                                                        <td style="padding-left:32px;">{{ $p['id'] }}</td>
                                                        <td>{{ $p['name'] }}</td>
                                                        <td style="text-align:right;">{{ number_format($p['stock']) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif

</section>
@endsection
