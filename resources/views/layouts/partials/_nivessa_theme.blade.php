{{-- ===========================================================
     Nivessa global theme layer (2026-04-20)

     Surface-level reskin that applies site-wide. Covers ONLY:
       - Typography (Inter Tight)
       - Body / content background (cream)
       - Main header + sidebar (near-black, yellow accent)
       - Color tokens (CSS custom properties) for pages that opt in

     Intentionally does NOT restyle buttons, forms, tables, modals,
     alerts — those touch too many flows (red delete buttons, success
     greens, warning yellows, bulk-import tables, etc.) and blanket-
     overriding them is how this blows up. Per-page surfaces like
     the POS opt in to their own deeper restyle via scoped classes.

     Revert = delete this file + remove the @include line from
     layouts/partials/css.blade.php.
     ============================================================ --}}

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
	:root {
		--nv-bg:          #FAF6EE;
		--nv-surface:     #FFFFFF;
		--nv-surface-2:   #F7F1E3;
		--nv-ink:         #1F1B16;
		--nv-ink-2:       #5A5045;
		--nv-ink-3:       #8E8273;
		--nv-line:        #ECE3CF;
		--nv-line-2:      #DFD2B3;
		--nv-brand:       #1F1B16;
		--nv-brand-ink:   #FAF6EE;
		--nv-accent:      #FFF2B3;
		--nv-accent-deep: #E8CF68;
		--nv-accent-soft: #FFF9DB;
		--nv-accent-text: #5A4410;
		--nv-cr:          #7A1F1F;
	}

	/* ---------- Typography ---------- */
	/* Apply Inter Tight, but WITHOUT !important on body. The earlier version
	   used !important, which blocked the browser's fallback chain to icon
	   fonts (FontAwesome) and emoji fonts site-wide — every icon and emoji
	   rendered as a □ tofu square. Sarah: "none of our changes are here and
	   it looks worse." Root cause was this one rule.
	   Fix: drop the !important, include emoji + symbol fonts in the fallback
	   stack, and let .fa/.glyphicon rules keep their own font-family. */
	html, body {
		font-family: "Inter Tight", -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", sans-serif;
		font-feature-settings: "ss01", "cv11";
	}
	body .content-wrapper,
	body .main-sidebar,
	body .main-header,
	body .modal,
	body h1, body h2, body h3, body h4, body h5, body h6,
	body .box,
	body .box-body,
	body .navbar,
	body .sidebar-menu,
	body input, body select, body textarea, body button, body .btn {
		font-family: inherit;
	}
	/* Icon fonts MUST keep their own family. Extra-specific rule so any
	   ancestor font-family doesn't accidentally win. Covers classic
	   FontAwesome 4, FA5 Free/Solid/Regular/Brands, and Glyphicons. */
	body .fa, body .fas, body .far, body .fab, body .fal,
	body .fa-solid, body .fa-regular, body .fa-brands,
	body [class^="fa-"], body [class*=" fa-"],
	body .glyphicon, body [class^="glyphicon-"] {
		font-family: 'FontAwesome', 'Font Awesome 5 Free', 'Font Awesome 5 Brands' !important;
	}

	/* ---------- Page background ---------- */
	/* AdminLTE paints the body + .content-wrapper gray (#ecf0f5-ish). Warm it
	   up to Nivessa cream. Left at a plain selector (no !important) so any
	   page that deliberately wants a different bg can still override. */
	body.skin-blue-light,
	body.skin-blue,
	body.skin-blue-light .content-wrapper,
	body.skin-blue .content-wrapper,
	body .content-wrapper {
		background: var(--nv-bg);
	}

	/* Main content cards — soften the default white-on-gray with a warmer
	   border and subtle shadow. Boxes already exist everywhere; this keeps
	   structure, just refreshes the edges. */
	body .box {
		border-top-color: var(--nv-accent-deep);
		border-radius: 8px;
		box-shadow: 0 1px 3px rgba(31, 27, 22, 0.05);
	}

	/* ---------- Main header (top navbar) ---------- */
	/* AdminLTE's .main-header.navbar defaults to the theme color (usually blue).
	   Swap to Nivessa near-black with a yellow accent dot. Scoped to .main-header
	   so we don't bleed into Bootstrap's generic .navbar used in other contexts. */
	body .main-header .navbar,
	body .main-header {
		background-color: var(--nv-brand) !important;
		border-bottom: 1px solid #000 !important;
	}
	body .main-header .logo {
		background-color: var(--nv-brand) !important;
		color: var(--nv-brand-ink) !important;
		font-family: inherit !important;
		font-weight: 800 !important;
		letter-spacing: .14em !important;
		border-bottom: none !important;
	}
	body .main-header .logo::before {
		content: "";
		display: inline-block;
		width: 8px; height: 8px;
		border-radius: 50%;
		background: var(--nv-accent);
		margin-right: 8px;
		vertical-align: middle;
	}
	body .main-header .logo:hover {
		background-color: #3a2e22 !important;
	}
	body .main-header .navbar .sidebar-toggle {
		color: var(--nv-brand-ink) !important;
	}
	body .main-header .navbar .sidebar-toggle:hover {
		background-color: rgba(255,255,255,0.08) !important;
	}
	body .main-header .navbar .nav > li > a {
		color: var(--nv-brand-ink) !important;
	}
	body .main-header .navbar .nav > li > a:hover,
	body .main-header .navbar .nav > li > a:focus,
	body .main-header .navbar .nav > li.active > a {
		background-color: rgba(255,255,255,0.08) !important;
		color: var(--nv-brand-ink) !important;
	}
	/* Username / dropdown labels in the navbar */
	body .main-header .user-header {
		background-color: var(--nv-brand) !important;
		color: var(--nv-brand-ink) !important;
	}

	/* ---------- Sidebar (left nav) ---------- */
	/* AdminLTE sidebar is dark navy by default. Shift to a Nivessa-flavored
	   dark that coordinates with the near-black navbar. */
	body .main-sidebar,
	body .left-side {
		background-color: #2a2320 !important;
	}
	body .sidebar-menu > li > a {
		color: #E8DBC7 !important;
		border-left-color: transparent !important;
	}
	body .sidebar-menu > li:hover > a,
	body .sidebar-menu > li.active > a,
	body .sidebar-menu > li.menu-open > a {
		background: #1F1B16 !important;
		color: var(--nv-accent) !important;
		border-left-color: var(--nv-accent) !important;
	}
	body .sidebar-menu > li > .treeview-menu {
		background: #1F1B16 !important;
		padding: 6px 0;
	}
	body .sidebar-menu .treeview-menu > li > a {
		color: #C9BEA7 !important;
	}
	body .sidebar-menu .treeview-menu > li > a:hover,
	body .sidebar-menu .treeview-menu > li.active > a {
		color: var(--nv-accent) !important;
		background: transparent !important;
	}
	/* User panel at top of sidebar */
	body .sidebar .user-panel .info > p {
		color: var(--nv-accent) !important;
		font-weight: 600;
	}

	/* ---------- Scrollbar accent ---------- */
	body ::-webkit-scrollbar-thumb {
		background: #d4c69e;
		border-radius: 4px;
	}
	body ::-webkit-scrollbar-track {
		background: var(--nv-bg);
	}

	/* ---------- Links ---------- */
	/* Warm the default link blue toward Nivessa's palette without breaking
	   informational .text-info / .text-primary utility classes (those stay blue
	   so alerts still read right). */
	body a:not(.btn):not(.nav-link):not(.text-info):not(.text-primary):not(.text-success):not(.text-warning):not(.text-danger):not(.dropdown-toggle) {
		color: var(--nv-accent-text);
	}
	body a:not(.btn):not(.nav-link):not(.text-info):not(.text-primary):not(.text-success):not(.text-warning):not(.text-danger):not(.dropdown-toggle):hover {
		color: var(--nv-ink);
	}

	/* ---------- Page title bar ---------- */
	/* AdminLTE .content-header on content pages. Swap its border/color toward
	   the Nivessa palette so the first thing you see feels on-brand. */
	body .content-header > h1 {
		font-weight: 800;
		color: var(--nv-ink);
		letter-spacing: -.005em;
	}
	body .content-header > h1 > small {
		color: var(--nv-ink-3);
		font-weight: 500;
	}
</style>
