@extends('layouts.app')
@section('title', 'Sling Diagnose')

@section('content')
<section class="content-header">
    <h1>Sling Diagnose <small>which shift endpoint works for this account?</small></h1>
</section>

<section class="content">
    <div class="alert alert-info">
        Probed the candidate shift endpoints for the window <strong>{{ $start }} → {{ $end }}</strong>.
        Look for the row with <span class="text-success">HTTP 200</span> AND a non-zero <strong>count</strong>.
        <a href="{{ url('/admin/sling/shifts') }}" class="btn btn-default btn-sm pull-right">← Back</a>
    </div>

    @foreach($results as $r)
        <div class="box {{ ($r['http_code'] >= 200 && $r['http_code'] < 300 && $r['count']) ? 'box-success' : (($r['http_code'] >= 200 && $r['http_code'] < 300) ? 'box-warning' : 'box-danger') }}">
            <div class="box-header with-border">
                <h3 class="box-title">
                    {{ $r['label'] }}
                    &nbsp;<span class="label label-{{ ($r['http_code'] >= 200 && $r['http_code'] < 300) ? 'success' : 'danger' }}">HTTP {{ $r['http_code'] }}</span>
                    @if($r['count'] !== null)
                        &nbsp;<span class="label label-{{ $r['count'] ? 'primary' : 'default' }}">count: {{ $r['count'] }}</span>
                    @endif
                </h3>
            </div>
            <div class="box-body">
                <p style="font-family:monospace;font-size:11px;word-break:break-all;">{{ $r['url'] }}</p>
                <pre style="max-height:300px;overflow:auto;font-size:11px;">{{ $r['body'] ?: '(empty body)' }}</pre>
            </div>
        </div>
    @endforeach
</section>
@endsection
