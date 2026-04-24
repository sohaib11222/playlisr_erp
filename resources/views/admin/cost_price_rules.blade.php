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

                <form method="POST" action="{{ url('/admin/cost-price-rules/run') }}" style="margin-top:16px;">
                    @csrf
                    <button type="submit" name="commit" value="0" class="btn btn-default btn-lg">Preview</button>
                    <button type="submit" name="commit" value="1" class="btn btn-primary btn-lg"
                            onclick="return confirm('Apply these cost prices to all missing variations? This writes to the DB.');">
                        Apply
                    </button>
                    <span class="help-block" style="display:inline-block;margin-left:12px;vertical-align:middle;">
                        Preview first to confirm category matches. Apply writes values.
                    </span>
                </form>
            </div>
        </div>
    </div>
</div>

@if ($results !== null)
<div class="row">
    <div class="col-md-12">
        <div class="box box-solid">
            <div class="box-header">
                <h3 class="box-title">
                    {{ $mode === 'commit' ? 'Applied' : 'Preview' }}
                    —
                    {{ $mode === 'commit'
                        ? number_format($grand_updated) . ' variations updated'
                        : number_format($grand_matched) . ' variations would be updated' }}
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

</section>
@endsection
