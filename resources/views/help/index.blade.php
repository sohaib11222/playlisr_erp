@extends('layouts.app')

@section('title', 'Help & Handbook')

@section('content')

@include('help.partials.styles')

<script>document.body.classList.add('help-v2');</script>

<section class="content-header help-content-header">
    <h1>Help &amp; Handbook
        <small>How to do anything in the ERP</small>
    </h1>
</section>

<section class="content help-page">
    <div class="help-card help-card--search">
        <form method="GET" action="{{ route('help.index') }}" class="help-search-form">
            <label for="help_q" class="help-search-label">Search the handbook</label>
            <div class="help-search-wrap">
                <i class="fa fa-search help-search-icon"></i>
                <input type="text" id="help_q" name="q" value="{{ $q }}"
                       placeholder="How do I… e.g. ‘print labels’, ‘store credit’, ‘ship Discogs order’"
                       class="form-control help-search-input" autofocus autocomplete="off">
                <button class="btn btn-primary help-search-btn" type="submit">Search</button>
            </div>
            @if($q !== '')
                <p class="help-block help-search-clear">
                    <a href="{{ route('help.index') }}"><i class="fa fa-times"></i> Clear search</a>
                </p>
            @endif
        </form>
    </div>

    @if($q !== '')
        <div class="help-card">
            <div class="help-card-header">
                <span>Results for &ldquo;{{ $q }}&rdquo;</span>
                <span class="help-pill">{{ count($results) }} match{{ count($results) === 1 ? '' : 'es' }}</span>
            </div>
            <div class="help-card-body">
                @if(empty($results))
                    <div class="help-empty">
                        <i class="fa fa-info-circle"></i>
                        No matches. Try a simpler word, or
                        <a href="mailto:sarah@nivessa.com?subject=Help%20request:%20{{ urlencode($q) }}">email Sarah</a>
                        with what you were looking for.
                    </div>
                @else
                    <ul class="help-result-list">
                        @foreach($results as $r)
                            <li>
                                <a href="{{ route('help.show', $r['slug']) }}" class="help-result-link">
                                    <div class="help-result-title">{{ $r['title'] }}</div>
                                    <div class="help-result-meta">
                                        @if(!empty($r['section']))
                                            <span class="help-section-tag">{{ $r['section'] }}</span>
                                        @endif
                                        @if(!empty($r['summary']))
                                            <span class="help-result-summary">{{ $r['summary'] }}</span>
                                        @endif
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif

    @if(empty($sections))
        <div class="help-card">
            <div class="help-card-body"><p class="text-muted">No help articles yet.</p></div>
        </div>
    @else
        <h2 class="help-section-heading">Browse by section</h2>
        <div class="help-section-grid">
            @foreach($sections as $sectionName => $items)
                <div class="help-card help-card--section">
                    <div class="help-card-header">{{ $sectionName }}</div>
                    <ul class="help-article-list">
                        @foreach($items as $item)
                            <li>
                                <a href="{{ route('help.show', $item['slug']) }}" class="help-article-link">
                                    <div class="help-article-title">{{ $item['title'] }}</div>
                                    @if(!empty($item['summary']))
                                        <div class="help-article-summary">{{ $item['summary'] }}</div>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    @endif

    <p class="help-footnote">
        <i class="fa fa-info-circle"></i>
        Don't see what you need? Email <a href="mailto:sarah@nivessa.com">sarah@nivessa.com</a> and we'll add it.
    </p>
</section>

@endsection
