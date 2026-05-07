@extends('layouts.app')

@section('title', $article['title'] . ' - Help')

@section('content')

<section class="content-header">
    <h1>
        <a href="{{ route('help.index') }}" style="color: inherit;"><i class="fa fa-life-ring"></i> Help</a>
        <small>&raquo; {{ $article['section'] ?? 'General' }}</small>
    </h1>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-9">
            @component('components.widget', ['class' => 'box-primary', 'title' => $article['title']])
                @if(!empty($article['summary']))
                    <p class="lead">{{ $article['summary'] }}</p>
                @endif
                <div class="help-article-body">
                    {!! $article['body_html'] !!}
                </div>
                <hr>
                <p class="text-muted small">
                    See something wrong or out of date?
                    <a href="mailto:sarah@nivessa.com?subject=Handbook%20fix:%20{{ urlencode($article['title']) }}">Email Sarah</a>
                </p>
            @endcomponent
        </div>
        <div class="col-md-3">
            <div class="panel panel-default">
                <div class="panel-heading"><strong>In this section</strong></div>
                <ul class="list-group">
                    @forelse($related as $r)
                        <li class="list-group-item">
                            <a href="{{ route('help.show', $r['slug']) }}">{{ $r['title'] }}</a>
                        </li>
                    @empty
                        <li class="list-group-item text-muted">No related articles yet.</li>
                    @endforelse
                </ul>
            </div>
            <p><a href="{{ route('help.index') }}" class="btn btn-default btn-block"><i class="fa fa-list"></i> All help</a></p>
        </div>
    </div>
</section>

<style>
.help-article-body h2 { font-size: 18px; margin-top: 18px; }
.help-article-body h3 { font-size: 16px; margin-top: 14px; }
.help-article-body ol, .help-article-body ul { padding-left: 22px; }
.help-article-body li { margin-bottom: 4px; }
.help-article-body code { background: #f5f5f5; padding: 1px 4px; border-radius: 3px; }
.help-article-body table { margin: 10px 0; }
.help-article-body .help-tip { background: #fffbe5; border-left: 4px solid #f0c419; padding: 8px 12px; margin: 10px 0; border-radius: 3px; }
.help-article-body .help-warn { background: #fdecea; border-left: 4px solid #d9534f; padding: 8px 12px; margin: 10px 0; border-radius: 3px; }
</style>

@endsection
