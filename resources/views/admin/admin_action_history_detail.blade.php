@extends('layouts.app')
@section('title', 'Snapshot changes')

@section('content')
<section class="content-header">
    <h1>What this action changed</h1>
    <p class="text-muted">
        <strong>{{ $action }}</strong>
        @if ($detail) &nbsp;·&nbsp; {{ $detail }} @endif
        @if ($timestamp) &nbsp;·&nbsp; {{ $timestamp }} @endif
    </p>
    <a href="{{ url('/admin/admin-action-history') }}" class="btn btn-default btn-sm">&larr; Back to history</a>
</section>

<section class="content">
<div class="row">
    <div class="col-md-12">
        <div class="box box-solid">
            <div class="box-body">
                <p class="text-muted">{{ number_format(count($rows)) }} row(s). "Before" is what was there prior to this action; "After" is what it wrote.</p>
                @if (empty($rows))
                    <p class="text-muted">This snapshot has no row-level detail.</p>
                @else
                    <table class="table table-striped">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Before</th>
                                <th>After</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $r)
                                <tr>
                                    <td>{{ $r['name'] ?? ('#' . $r['id']) }}</td>
                                    <td style="color:#8E8273;">{{ ($r['old'] === null || trim((string) $r['old']) === '') ? '(blank)' : $r['old'] }}</td>
                                    <td><strong>{{ $r['new'] }}</strong></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</div>
</section>
@endsection
