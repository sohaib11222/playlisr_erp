{{--
    Reusable "Pin to my sidebar" button.

    Saves the given page to the current user's personal Favorites group (top of
    the left menu). Per-account; nobody else sees it. See SidebarFavoriteController.

    Usage:  @include('partials.pin_button', ['pinUrl' => url('/x'), 'pinLabel' => 'X'])
--}}
@php
    $__pinUrl     = $pinUrl ?? url()->current();
    $__pinLabel   = $pinLabel ?? 'This page';
    $__pinAlready = \App\Http\Controllers\SidebarFavoriteController::isPinned(
        session()->get('user.business_id'),
        session()->get('user.id'),
        $__pinUrl
    );
@endphp
<button type="button" class="niv-pin-btn {{ $__pinAlready ? 'is-on' : '' }}"
        data-pin-url="{{ $__pinUrl }}" data-pin-label="{{ $__pinLabel }}"
        title="{{ $__pinAlready ? 'Pinned to your sidebar' : 'Pin this page to your sidebar' }}">
    <i class="fa {{ $__pinAlready ? 'fa-star' : 'fa-star-o' }}"></i>
    <span class="pin-text">{{ $__pinAlready ? 'Pinned' : 'Pin to my sidebar' }}</span>
</button>

<style>
.niv-pin-btn {
    flex: 0 0 auto; display: inline-flex; align-items: center; gap: 7px;
    white-space: nowrap; cursor: pointer; font: inherit; font-size: 13px;
    font-weight: 700; color: #6b5a00; background: #FFF7CC;
    border: 1px solid #E6CE5A; border-radius: 999px; padding: 8px 14px; line-height: 1;
    transition: background .12s ease, box-shadow .12s ease;
}
.niv-pin-btn:hover { background: #FFF2B3; }
.niv-pin-btn.is-on { background: #FFF2B3; box-shadow: inset 0 0 0 1px #E6CE5A; }
.niv-pin-btn .fa { color: #C99A12; }
</style>
<script>
(function () {
    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }
    ready(function () {
        document.querySelectorAll('.niv-pin-btn[data-pin-url]').forEach(function (btn) {
            if (btn.__pinWired) { return; }
            btn.__pinWired = true;
            var url = btn.getAttribute('data-pin-url');
            var label = btn.getAttribute('data-pin-label');

            function paint(on) {
                btn.classList.toggle('is-on', on);
                var ic = btn.querySelector('.fa');
                if (ic) { ic.className = 'fa ' + (on ? 'fa-star' : 'fa-star-o'); }
                var t = btn.querySelector('.pin-text');
                if (t) { t.textContent = on ? 'Pinned' : 'Pin to my sidebar'; }
                btn.title = on ? 'Pinned to your sidebar' : 'Pin this page to your sidebar';
            }

            btn.addEventListener('click', function () {
                // Reuse the sidebar helper so the left-menu Favorites group updates live.
                if (window.NivessaSidebarFav && window.NivessaSidebarFav.toggle) {
                    var willBeOn = !window.NivessaSidebarFav.isPinned(url);
                    window.NivessaSidebarFav.toggle(url, label);
                    paint(willBeOn);
                    return;
                }
                // Fallback: post directly if the sidebar script isn't present.
                var tokenEl = document.querySelector('meta[name="csrf-token"]');
                var body = new FormData();
                body.append('url', url);
                body.append('label', label);
                fetch('{{ url('/sidebar-favorites/toggle') }}', {
                    method: 'POST', credentials: 'same-origin',
                    headers: {
                        'X-CSRF-TOKEN': tokenEl ? tokenEl.getAttribute('content') : '',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: body
                }).then(function (r) { return r.json(); }).then(function (d) {
                    if (d && d.ok) { paint(d.starred); }
                }).catch(function () {});
            });
        });
    });
})();
</script>
