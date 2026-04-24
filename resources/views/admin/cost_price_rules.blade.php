@extends('layouts.app')
@section('title', 'Cost Price Rules')

@section('content')
<section class="content-header">
    <h1>Cost Price Rules</h1>
    <p class="text-muted">Fills missing cost prices on variations using flat per-category rules. Only touches variations where cost is currently NULL or 0 — never overwrites.</p>
</section>

<section class="content">

<div class="row">
    <div class="col-md-12">
        <div class="box box-solid">
            <div class="box-body">
                <h4 style="margin-top:0;">Rules</h4>
                <table class="table table-condensed" style="max-width:600px;">
                    <thead>
                        <tr><th>Category label</th><th style="text-align:right;">Cost</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($rules as $r)
                            <tr>
                                <td>{{ $r['label'] }}</td>
                                <td style="text-align:right;">${{ number_format($r['cost'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                <form method="POST" action="{{ url('/admin/cost-price-rules/run') }}" style="margin-top:16px;" id="cpr-form">
                    @csrf
                    <button type="submit" name="commit" value="0" class="btn btn-default btn-lg">Preview</button>
                    <button type="submit" name="commit" value="1" class="btn btn-primary btn-lg">
                        Apply
                    </button>
                    <span id="cpr-status" class="help-block" style="display:inline-block;margin-left:12px;vertical-align:middle;">
                        Preview first to confirm category matches. Apply writes values.
                    </span>
                </form>
                <script>
                    document.getElementById('cpr-form').addEventListener('submit', function (e) {
                        const btn = e.submitter;
                        const isApply = btn && btn.value === '1';
                        document.getElementById('cpr-status').innerHTML =
                            '<span style="color:#c00;font-weight:bold;">' +
                            (isApply ? 'Applying — writing to DB, do not close this tab…' : 'Running preview…') +
                            '</span>';
                        Array.from(e.target.querySelectorAll('button')).forEach(b => b.disabled = true);
                    });
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
                        ✅ Applied — {{ number_format($grand_updated) }} variations updated
                    @else
                        Preview — {{ number_format($grand_matched) }} variations would be updated
                    @endif
                </h3>
            </div>
            <div class="box-body" style="padding:0;">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Rule</th>
                            <th style="text-align:right;">Cost</th>
                            <th>ERP category IDs matched</th>
                            <th style="text-align:right;">Variations eligible</th>
                            <th style="text-align:right;">{{ $mode === 'commit' ? 'Updated' : '—' }}</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($results as $row)
                            <tr>
                                <td>{{ $row['label'] }}</td>
                                <td style="text-align:right;">${{ number_format($row['cost'], 2) }}</td>
                                <td>
                                    @if (empty($row['category_ids']))
                                        <span class="text-danger">—</span>
                                    @else
                                        {{ implode(', ', $row['category_ids']) }}
                                    @endif
                                </td>
                                <td style="text-align:right;">{{ number_format($row['eligible']) }}</td>
                                <td style="text-align:right;">
                                    {{ $mode === 'commit' ? number_format($row['updated']) : '—' }}
                                </td>
                                <td>
                                    @if ($row['note'])
                                        <span class="text-warning">{{ $row['note'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif

<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">All ERP categories ({{ count($categories) }})</h3>
                <span class="help-block" style="display:inline-block;margin-left:12px;">
                    Copy the exact name of a category and tell me which rule it should match.
                </span>
            </div>
            <div class="box-body" style="padding:0;">
                <table class="table table-condensed table-striped" style="margin:0;">
                    <thead>
                        <tr><th style="width:80px;">ID</th><th>Name</th><th style="width:120px;">Parent ID</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($categories as $c)
                            <tr>
                                <td>{{ $c->id }}</td>
                                <td>{{ $c->name }}</td>
                                <td>{{ $c->parent_id ?: '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

</section>
@endsection
