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
        <aside class="help-show-side">
            <div class="help-card help-toc-card" id="help-toc-card" hidden>
                <div class="help-card-header">On this page</div>
                <nav class="help-toc" id="help-toc"></nav>
            </div>
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
                    <div class="help-article-body" id="help-article-body">
                        {!! $article['body_html'] !!}
                    </div>
                    <hr>
                    <p class="help-article-footer">
                        <i class="fa fa-flag"></i>
                        See something wrong or out of date?
                        <a href="https://slack.com/app_redirect?channel=U07D33PMQLA" target="_blank" rel="noopener">Slack Sarah</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
(function () {
    var bodyEl = document.getElementById('help-article-body');
    var toc = document.getElementById('help-toc');
    var card = document.getElementById('help-toc-card');
    if (!bodyEl || !toc || !card) return;

    var heads = bodyEl.querySelectorAll('h2');
    if (heads.length < 4) return; // short articles keep the simple layout

    var used = {};
    var links = [];
    for (var i = 0; i < heads.length; i++) {
        (function (h) {
            var id = h.id;
            if (!id) {
                id = (h.textContent || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
                if (!id) { id = 'section'; }
                if (used[id]) { used[id]++; id = id + '-' + used[id]; } else { used[id] = 1; }
                h.id = id;
            }
            var a = document.createElement('a');
            a.href = '#' + id;
            a.className = 'help-toc-link';
            a.textContent = h.textContent;
            a.addEventListener('click', function (e) {
                e.preventDefault();
                var target = document.getElementById(id);
                if (target) {
                    window.scrollTo({ top: target.getBoundingClientRect().top + window.pageYOffset - 70, behavior: 'smooth' });
                    if (history.replaceState) { history.replaceState(null, '', '#' + id); }
                }
            });
            toc.appendChild(a);
            links.push(a);
        })(heads[i]);
    }
    card.hidden = false;

    // Scroll-spy: highlight the section currently in view.
    function spy() {
        var current = links[0];
        for (var i = 0; i < heads.length; i++) {
            if (heads[i].getBoundingClientRect().top <= 90) { current = links[i]; } else { break; }
        }
        for (var j = 0; j < links.length; j++) { links[j].classList.remove('is-active'); }
        if (current) { current.classList.add('is-active'); }
    }
    var ticking = false;
    window.addEventListener('scroll', function () {
        if (!ticking) {
            window.requestAnimationFrame(function () { spy(); ticking = false; });
            ticking = true;
        }
    });
    spy();
})();
</script>

@endsection
