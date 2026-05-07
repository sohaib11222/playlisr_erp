{{-- Help launcher: search box + drawer trigger.
     Drops into the global header so help is one click away from any ERP page.
     Each page may set its context via @section('help_page_key', 'pos.create'). --}}

@php
    $helpPageKey = trim((string) ($__env->yieldContent('help_page_key') ?? ''));
@endphp

<div class="help-launcher pull-left m-8 mt-10 hidden-xs hidden-sm" style="position: relative;">
    <div class="input-group input-group-sm" style="width: 280px;">
        <input type="text" id="help_search_input"
               class="form-control"
               placeholder="Help / how do I…"
               autocomplete="off"
               data-page-key="{{ $helpPageKey }}">
        <span class="input-group-btn">
            <button type="button" class="btn btn-default" id="help_drawer_trigger"
                    data-page-key="{{ $helpPageKey }}"
                    data-drawer-url="{{ route('help.drawer') }}"
                    title="Open page-specific help">
                <i class="fa fa-question-circle"></i>
            </button>
        </span>
    </div>
    <div id="help_search_results" class="help-search-results" style="display: none;"></div>
</div>

<div id="help_drawer" class="help-drawer" aria-hidden="true">
    <div class="help-drawer-overlay" data-help-close="1"></div>
    <div class="help-drawer-panel" role="dialog" aria-label="Help">
        <div class="help-drawer-header">
            <strong><i class="fa fa-life-ring"></i> Help</strong>
            <button type="button" class="close" data-help-close="1" aria-label="Close">&times;</button>
        </div>
        <div class="help-drawer-search">
            <input type="text" id="help_drawer_search" class="form-control input-sm"
                   placeholder="Search the handbook…" autocomplete="off">
            <div id="help_drawer_search_results" class="help-search-results help-search-results--inline" style="display: none;"></div>
        </div>
        <div class="help-drawer-body" id="help_drawer_body">
            <p class="text-muted">Loading…</p>
        </div>
    </div>
</div>

<style>
.help-search-results {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    z-index: 1080;
    background: #fff;
    border: 1px solid #ddd;
    border-top: none;
    box-shadow: 0 4px 10px rgba(0,0,0,0.08);
    max-height: 360px;
    overflow-y: auto;
    border-radius: 0 0 3px 3px;
}
.help-search-results--inline {
    position: static;
    max-height: 240px;
    margin-top: 6px;
    border: 1px solid #ddd;
    border-radius: 3px;
}
.help-search-result {
    display: block;
    padding: 8px 12px;
    border-bottom: 1px solid #f0f0f0;
    color: #333;
    text-decoration: none;
}
.help-search-result:last-child { border-bottom: none; }
.help-search-result:hover, .help-search-result:focus { background: #f5f7fa; text-decoration: none; }
.help-search-result strong { color: #1a73e8; }
.help-search-result .help-section-tag { font-size: 11px; color: #888; margin-left: 6px; }
.help-search-result .help-summary { font-size: 12px; color: #666; margin-top: 2px; }
.help-search-empty { padding: 10px 12px; color: #888; }
.help-drawer { position: fixed; inset: 0; z-index: 1090; display: none; }
.help-drawer.is-open { display: block; }
.help-drawer-overlay { position: absolute; inset: 0; background: rgba(0,0,0,0.35); }
.help-drawer-panel {
    position: absolute;
    top: 0; right: 0; bottom: 0;
    width: 460px;
    max-width: 92vw;
    background: #fff;
    box-shadow: -4px 0 14px rgba(0,0,0,0.15);
    display: flex;
    flex-direction: column;
}
.help-drawer-header {
    padding: 12px 16px;
    border-bottom: 1px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #f7f9fb;
}
.help-drawer-header .close { font-size: 22px; line-height: 1; opacity: .6; }
.help-drawer-search {
    padding: 10px 16px;
    border-bottom: 1px solid #eee;
}
.help-drawer-body {
    flex: 1;
    overflow-y: auto;
    padding: 14px 16px 22px;
    font-size: 14px;
}
.help-drawer-body h2, .help-drawer-body h3 { margin-top: 14px; }
.help-drawer-body ol, .help-drawer-body ul { padding-left: 22px; }
.help-drawer-body li { margin-bottom: 4px; }
.help-drawer-body .help-tip { background: #fffbe5; border-left: 4px solid #f0c419; padding: 6px 10px; margin: 8px 0; border-radius: 3px; }
.help-drawer-body .help-warn { background: #fdecea; border-left: 4px solid #d9534f; padding: 6px 10px; margin: 8px 0; border-radius: 3px; }
@media (max-width: 768px) {
    .help-launcher { display: none; }
}
</style>

<script>
(function() {
    var searchUrl = "{{ route('help.searchAjax') }}";
    var drawerUrl = "{{ route('help.drawer') }}";
    var logClickUrl = "{{ route('help.logClick') }}";
    var csrf = "{{ csrf_token() }}";

    function debounce(fn, wait) {
        var t;
        return function() {
            var args = arguments, ctx = this;
            clearTimeout(t);
            t = setTimeout(function() { fn.apply(ctx, args); }, wait);
        };
    }

    function renderResults(container, lastQuery, results) {
        if (!container) return;
        if (!results || results.length === 0) {
            container.innerHTML = '<div class="help-search-empty">No matches. <a href="mailto:sarah@nivessa.com?subject=Help%20gap:%20' + encodeURIComponent(lastQuery) + '">Tell Sarah</a> what you were looking for.</div>';
            container.style.display = 'block';
            return;
        }
        var html = '';
        results.forEach(function(r) {
            html += '<a class="help-search-result" href="' + r.url + '" target="_blank" data-slug="' + r.slug + '">';
            html += '<strong>' + escapeHtml(r.title) + '</strong>';
            if (r.section) html += '<span class="help-section-tag">' + escapeHtml(r.section) + '</span>';
            if (r.summary) html += '<div class="help-summary">' + escapeHtml(r.summary) + '</div>';
            html += '</a>';
        });
        container.innerHTML = html;
        container.style.display = 'block';

        Array.prototype.forEach.call(container.querySelectorAll('.help-search-result'), function(el) {
            el.addEventListener('click', function() {
                logClick(lastQuery, el.getAttribute('data-slug'));
            });
        });
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }

    function logClick(q, slug) {
        if (!q || !slug) return;
        try {
            var fd = new FormData();
            fd.append('q', q);
            fd.append('slug', slug);
            fd.append('_token', csrf);
            fetch(logClickUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
        } catch (e) { /* swallow */ }
    }

    function attachSearch(input, container) {
        if (!input) return;
        var lastQuery = '';
        var run = debounce(function() {
            var q = input.value.trim();
            lastQuery = q;
            if (q.length < 2) {
                container.style.display = 'none';
                container.innerHTML = '';
                return;
            }
            var pageKey = input.getAttribute('data-page-key') || '';
            fetch(searchUrl + '?q=' + encodeURIComponent(q) + '&page_key=' + encodeURIComponent(pageKey), {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) { renderResults(container, q, data.results || []); })
            .catch(function() { /* ignore */ });
        }, 220);

        input.addEventListener('input', run);
        input.addEventListener('focus', function() {
            if (input.value.trim().length >= 2) container.style.display = 'block';
        });
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var q = input.value.trim();
                if (q !== '') window.location.href = "{{ route('help.index') }}" + '?q=' + encodeURIComponent(q);
            }
            if (e.key === 'Escape') {
                container.style.display = 'none';
            }
        });
    }

    function openDrawer(pageKey, slug) {
        var drawer = document.getElementById('help_drawer');
        var body = document.getElementById('help_drawer_body');
        if (!drawer || !body) return;
        body.innerHTML = '<p class="text-muted">Loading…</p>';
        drawer.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');

        var qs = 'page_key=' + encodeURIComponent(pageKey || '');
        if (slug) qs += '&slug=' + encodeURIComponent(slug);
        fetch(drawerUrl + '?' + qs, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.text(); })
        .then(function(html) { body.innerHTML = html; })
        .catch(function() { body.innerHTML = '<p class="text-danger">Could not load help.</p>'; });
    }

    function closeDrawer() {
        var drawer = document.getElementById('help_drawer');
        if (!drawer) return;
        drawer.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
    }

    document.addEventListener('DOMContentLoaded', function() {
        attachSearch(
            document.getElementById('help_search_input'),
            document.getElementById('help_search_results')
        );
        attachSearch(
            document.getElementById('help_drawer_search'),
            document.getElementById('help_drawer_search_results')
        );

        var trigger = document.getElementById('help_drawer_trigger');
        if (trigger) {
            trigger.addEventListener('click', function(e) {
                e.preventDefault();
                openDrawer(trigger.getAttribute('data-page-key') || '');
            });
        }

        document.addEventListener('click', function(e) {
            if (e.target && e.target.matches('[data-help-close="1"]')) {
                closeDrawer();
            }
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeDrawer();
            if ((e.ctrlKey || e.metaKey) && e.key === '/') {
                e.preventDefault();
                var input = document.getElementById('help_search_input');
                if (input) { input.focus(); input.select(); }
            }
        });

        document.addEventListener('click', function(e) {
            var box = document.getElementById('help_search_results');
            var input = document.getElementById('help_search_input');
            if (!box || !input) return;
            if (!box.contains(e.target) && e.target !== input) {
                box.style.display = 'none';
            }
        });
    });
})();
</script>
