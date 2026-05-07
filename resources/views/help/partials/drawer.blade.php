@if($article)
    <div class="help-drawer-article">
        <h3 style="margin-top: 0;">{{ $article->title }}</h3>
        @if($article->section)
            <p><span class="label label-default">{{ $article->section }}</span></p>
        @endif
        @if($article->summary)
            <p class="text-muted">{{ $article->summary }}</p>
        @endif
        <div class="help-article-body">
            {!! $article->body_html !!}
        </div>
        <hr>
        <p>
            <a href="{{ route('help.show', $article->slug) }}" class="btn btn-default btn-sm" target="_blank">
                <i class="fa fa-external-link-alt"></i> Open full page
            </a>
            <a href="{{ route('help.index') }}" class="btn btn-default btn-sm" target="_blank">
                <i class="fa fa-book"></i> All help
            </a>
        </p>
    </div>
@else
    <div class="help-drawer-empty">
        <p class="text-muted">No help article is linked to this page yet.</p>
        @if($suggestions->isNotEmpty())
            <h4>Related</h4>
            <ul>
                @foreach($suggestions as $s)
                    <li><a href="{{ route('help.show', $s->slug) }}" target="_blank">{{ $s->title }}</a></li>
                @endforeach
            </ul>
        @endif
        <p><a href="{{ route('help.index') }}" target="_blank" class="btn btn-default btn-sm"><i class="fa fa-book"></i> Browse the handbook</a></p>
        <p class="text-muted small">If you can't find what you need, <a href="mailto:sarah@nivessa.com">email Sarah</a> and we'll add it.</p>
    </div>
@endif

<style>
.help-article-body h2 { font-size: 16px; margin-top: 14px; }
.help-article-body h3 { font-size: 15px; margin-top: 12px; }
.help-article-body ol, .help-article-body ul { padding-left: 22px; }
.help-article-body li { margin-bottom: 3px; }
.help-article-body .help-tip { background: #fffbe5; border-left: 4px solid #f0c419; padding: 6px 10px; margin: 8px 0; border-radius: 3px; }
.help-article-body .help-warn { background: #fdecea; border-left: 4px solid #d9534f; padding: 6px 10px; margin: 8px 0; border-radius: 3px; }
</style>
