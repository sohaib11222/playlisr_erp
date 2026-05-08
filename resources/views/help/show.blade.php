@extends('layouts.app')

@section('title', $article['title'] . ' - Help')

@section('content')

@include('help.partials.styles')

<script>document.body.classList.add('help-v2');</script>

<section class="content-header help-content-header">
    <h1>
        <a href="{{ route('help.index') }}" class="help-breadcrumb-link"><i class="fa fa-life-ring"></i> Help</a>
        <small>&raquo; {{ $article['section'] ?? 'General' }}</small>
    </h1>
</section>

<section class="content help-page">
    <div class="help-show-grid">
        <div class="help-show-main">
            <div class="help-card">
                <div class="help-card-header help-card-header--article">
                    <span>{{ $article['title'] }}</span>
                    @if(!empty($article['section']))
                        <span class="help-pill">{{ $article['section'] }}</span>
                    @endif
                </div>
                <div class="help-card-body help-article">
                    @if(!empty($article['summary']))
                        <p class="help-article-lead">{{ $article['summary'] }}</p>
                    @endif
                    <div class="help-article-body">
                        {!! $article['body_html'] !!}
                    </div>
                    <hr>
                    <p class="help-article-footer">
                        <i class="fa fa-flag"></i>
                        See something wrong or out of date?
                        <a href="mailto:sarah@nivessa.com?subject=Handbook%20fix:%20{{ urlencode($article['title']) }}">Email Sarah</a>
                    </p>
                </div>
            </div>
        </div>
        <aside class="help-show-side">
            <div class="help-card">
                <div class="help-card-header">In this section</div>
                <ul class="help-article-list">
                    @forelse($related as $r)
                        <li>
                            <a href="{{ route('help.show', $r['slug']) }}" class="help-article-link">
                                <div class="help-article-title">{{ $r['title'] }}</div>
                            </a>
                        </li>
                    @empty
                        <li class="help-empty-side">No related articles yet.</li>
                    @endforelse
                </ul>
            </div>
            <a href="{{ route('help.index') }}" class="btn btn-default help-back-btn"><i class="fa fa-list"></i> All help</a>
        </aside>
    </div>
</section>

@endsection
