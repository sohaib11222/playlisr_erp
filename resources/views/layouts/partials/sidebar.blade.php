<!-- Left side column. contains the logo and sidebar -->
<aside class="main-sidebar">

  <!-- sidebar: style can be found in sidebar.less -->
  <section class="sidebar">

	<a href="{{route('home')}}" class="logo">
		<span class="logo-lg">{{ Session::get('business.name') }}</span>
	</a>

    @php
        // Per-employee starred links. Rendered server-side so a user's pinned
        // pages show at the top of the sidebar on every page load. Stars are
        // private to each account — see SidebarFavoriteController.
        $sidebarFavorites = \App\Http\Controllers\SidebarFavoriteController::forUser(
            session()->get('user.business_id'),
            session()->get('user.id')
        );
    @endphp

    <!-- Favorites: a user's own pinned links. Hidden until they pin something. -->
    <ul class="sidebar-menu sidebar-favorites" data-fav-section style="{{ empty($sidebarFavorites) ? 'display:none;' : '' }}">
        <li class="header">FAVORITES</li>
        @foreach ($sidebarFavorites as $fav)
            <li>
                <a href="{{ $fav['url'] }}">
                    <i class="fa fa-star"></i> <span>{{ $fav['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>

    <!-- Sidebar Menu -->
    {!! Menu::render('admin-sidebar-menu', 'adminltecustom'); !!}

    <!-- /.sidebar-menu -->
  </section>
  <!-- /.sidebar -->
</aside>

{{-- Sidebar favorites: hover any leaf link to reveal a star; click to pin it to
     the Favorites group above. Per-user, saved via /sidebar-favorites/toggle.
     Inline (rendered each request) so it never goes stale. --}}
<style>
.sidebar-favorites .header { color: #E8CF68; }
.sidebar-favorites > li > a > .fa-star { color: #FFC107; }
.sidebar-menu a.has-fav-star { position: relative; padding-right: 34px; }
.sidebar-fav-star {
    position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
    background: none; border: 0; padding: 5px; margin: 0; cursor: pointer;
    line-height: 1; color: rgba(255,255,255,.28);
    opacity: 0; transition: opacity .12s ease, color .12s ease;
}
.sidebar-menu li:hover > a > .sidebar-fav-star,
.sidebar-menu a:focus > .sidebar-fav-star { opacity: 1; }
.sidebar-fav-star:hover { color: #FFD24A; }
.sidebar-fav-star.is-on { opacity: 1; color: #FFC107; }
</style>
<script>
(function () {
    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }
    ready(function () {
        var TOGGLE_URL = '{{ url('/sidebar-favorites/toggle') }}';
        var tokenEl = document.querySelector('meta[name="csrf-token"]');
        var token = tokenEl ? tokenEl.getAttribute('content') : '';
        var favSection = document.querySelector('[data-fav-section]');
        if (!favSection) { return; }

        // Seed the "already pinned" set from the server-rendered Favorites group.
        var pinned = {};
        favSection.querySelectorAll('li > a[href]').forEach(function (a) {
            pinned[a.getAttribute('href')] = true;
        });

        function makeStar(url, label, on) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'sidebar-fav-star' + (on ? ' is-on' : '');
            b.dataset.favUrl = url;
            b.dataset.favLabel = label;
            b.title = on ? 'Unpin from Favorites' : 'Pin to my Favorites';
            b.setAttribute('aria-label', b.title);
            b.innerHTML = '<i class="fa ' + (on ? 'fa-star' : 'fa-star-o') + '"></i>';
            b.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                toggle(url, label);
            });
            return b;
        }

        // Add a star toggle to a single leaf link.
        function decorate(a) {
            var href = a.getAttribute('href');
            if (!href || href === '#' || href.charAt(0) === '#') { return; } // skip dropdown parents
            if (a.querySelector('.sidebar-fav-star')) { return; }            // already decorated
            var span = a.querySelector('span');
            var label = (span ? span.textContent : a.textContent).trim();
            if (!label) { return; }
            a.appendChild(makeStar(href, label, !!pinned[href]));
            a.classList.add('has-fav-star');
        }

        function decorateAll() {
            document.querySelectorAll('.sidebar-menu li a[href]').forEach(decorate);
        }

        // Rebuild the Favorites group <li> items from a server list.
        function renderFavorites(list) {
            favSection.querySelectorAll('li:not(.header)').forEach(function (li) { li.remove(); });
            list.forEach(function (f) {
                var li = document.createElement('li');
                var a = document.createElement('a');
                a.setAttribute('href', f.url);
                a.innerHTML = '<i class="fa fa-star"></i> <span></span>';
                a.querySelector('span').textContent = f.label;
                li.appendChild(a);
                favSection.appendChild(li);
                decorate(a); // give the favorite its own (filled) unpin star
            });
            favSection.style.display = list.length ? '' : 'none';
        }

        function syncStars(url, on) {
            pinned[url] = on;
            document.querySelectorAll('.sidebar-fav-star').forEach(function (s) {
                if (s.dataset.favUrl !== url) { return; }
                s.classList.toggle('is-on', on);
                var ic = s.querySelector('i');
                if (ic) { ic.className = 'fa ' + (on ? 'fa-star' : 'fa-star-o'); }
                s.title = on ? 'Unpin from Favorites' : 'Pin to my Favorites';
                s.setAttribute('aria-label', s.title);
            });
        }

        function toggle(url, label) {
            var body = new FormData();
            body.append('url', url);
            body.append('label', label);
            fetch(TOGGLE_URL, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                body: body
            }).then(function (r) { return r.json(); }).then(function (d) {
                if (!d || !d.ok) { return; }
                syncStars(url, d.starred);
                renderFavorites(d.favorites || []);
            }).catch(function () { /* leave UI as-is on network error */ });
        }

        decorateAll();

        // Expose a tiny helper so a page's own "Pin to my sidebar" button can
        // reuse the exact same toggle + live sidebar update.
        window.NivessaSidebarFav = {
            toggle: toggle,
            isPinned: function (url) { return !!pinned[url]; }
        };
    });
})();
</script>
