{{-- ===========================================================
     Nivessa global theme layer (2026-04-20)

     Surface-level reskin that applies site-wide. Covers ONLY:
       - Typography (Inter, 2026-10-07 for readability)
       - Body / content background (soft gray-white)
       - Main header + sidebar (calm slate, teal accent)
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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
	/* Calm palette (2026-10-07): Sarah wanted relaxing colors, high
	   contrast and an easy-to-read font. Slate-navy header + sidebar,
	   soft gray-white content, one muted teal accent. Inter replaces
	   Poppins (taller x-height, narrower, reads cleaner at small sizes). */
	:root {
		--nv-bg:          #F5F7FA;
		--nv-surface:     #FFFFFF;
		--nv-surface-2:   #F8FAFC;
		--nv-ink:         #1F2937;
		--nv-ink-2:       #374151;
		--nv-ink-3:       #6B7280;
		--nv-line:        #E5E7EB;
		--nv-line-2:      #D1D5DB;
		--nv-brand:       #1E2A3A;
		--nv-brand-ink:   #F1F5F9;
		--nv-accent:      #5EAAA8;
		--nv-accent-deep: #3F8A88;
		--nv-accent-soft: #EAF5F4;
		--nv-accent-text: #1D6F6C;
		--nv-cr:          #B91C1C;
		--nv-side-bg:     #1B2533;
		--nv-side-bg-2:   #151E2A;
		--nv-side-ink:    #FFFFFF;
		--nv-side-ink-2:  #FFFFFF;
		--nv-side-muted:  #8693A5;
	}

	/* ---------- Typography ---------- */
	/* Apply Poppins, but WITHOUT !important on body. The earlier version
	   used !important, which blocked the browser's fallback chain to icon
	   fonts (FontAwesome) and emoji fonts site-wide — every icon and emoji
	   rendered as a □ tofu square. Sarah: "none of our changes are here and
	   it looks worse." Root cause was this one rule.
	   Fix: drop the !important, include emoji + symbol fonts in the fallback
	   stack, and let .fa/.glyphicon rules keep their own font-family. */
	html, body {
		color: var(--nv-ink);
		-webkit-font-smoothing: antialiased;
		font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", system-ui, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol", sans-serif;
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
		border-bottom: 1px solid var(--nv-side-bg-2) !important;
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
		background-color: var(--nv-side-bg-2) !important;
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
	/* Calm slate sidebar, high-contrast off-white labels, every item on ONE
	   line. Widened 230 -> 260px (desktop, expanded only) so the longest
	   labels fit; anything still too long gets an ellipsis instead of
	   wrapping. Collapsed / mini / mobile states keep AdminLTE's own sizes.
	   AdminLTE's skin rule (.skin-blue-light .main-sidebar) is more specific
	   than a plain `body .main-sidebar`, so match its skin-class prefix. */
	body .main-sidebar,
	body .left-side,
	body[class*="skin-"] .main-sidebar,
	body[class*="skin-"] .left-side,
	body[class*="skin-"] .wrapper .main-sidebar,
	body .sidebar {
		background-color: var(--nv-side-bg) !important;
	}
	@media (min-width: 768px) {
		body:not(.sidebar-collapse) .main-sidebar { width: 260px; }
		body:not(.sidebar-collapse) .content-wrapper,
		body:not(.sidebar-collapse) .main-footer { margin-left: 260px; }
		body:not(.sidebar-collapse) .main-header .navbar { margin-left: 260px; }
	}
	body[class*="skin-"] .sidebar-menu > li > a {
		color: var(--nv-side-ink) !important;
		border-left: 3px solid transparent !important;
		font-size: 14px !important;
		font-weight: 500;
		line-height: 1.3;
		padding: 10px 28px 10px 13px !important;
	}
	body[class*="skin-"] .sidebar-menu > li > a > .fa,
	body[class*="skin-"] .sidebar-menu > li > a > i {
		width: 20px;
		margin-right: 6px;
		text-align: center;
		color: var(--nv-side-muted);
	}
	body[class*="skin-"]:not(.sidebar-collapse) .sidebar-menu li > a {
		white-space: nowrap !important;
		overflow: hidden !important;
		text-overflow: ellipsis;
	}
	body[class*="skin-"] .sidebar-menu > li > a > .pull-right-container {
		right: 8px;
	}
	body[class*="skin-"] .sidebar-menu > li:hover > a {
		background: rgba(255,255,255,0.05) !important;
		color: #FFFFFF !important;
	}
	body[class*="skin-"] .sidebar-menu > li.active > a,
	body[class*="skin-"] .sidebar-menu > li.menu-open > a {
		background: rgba(94,170,168,0.14) !important;
		color: #FFFFFF !important;
		border-left-color: var(--nv-accent) !important;
		font-weight: 600;
	}
	body[class*="skin-"] .sidebar-menu > li:hover > a > i,
	body[class*="skin-"] .sidebar-menu > li.active > a > i,
	body[class*="skin-"] .sidebar-menu > li.menu-open > a > i {
		color: var(--nv-accent);
	}
	body[class*="skin-"] .sidebar-menu > li > .treeview-menu {
		background: var(--nv-side-bg-2) !important;
		padding: 4px 0 6px;
	}
	body[class*="skin-"] .sidebar-menu .treeview-menu > li > a {
		/* Match the top-level items exactly; app.css forces 95% !important
		   which compounded into oversized submenu text. */
		color: var(--nv-side-ink-2) !important;
		font-size: 14px !important;
		font-weight: 400;
		line-height: 1.3;
		padding: 8px 10px 8px 42px !important;
	}
	body[class*="skin-"] .sidebar-menu .treeview-menu > li > a:hover {
		color: #FFFFFF !important;
		background: transparent !important;
	}
	body[class*="skin-"] .sidebar-menu .treeview-menu > li.active > a {
		color: #FFFFFF !important;
		background: transparent !important;
		font-weight: 600;
	}
	body[class*="skin-"] .sidebar-menu .treeview-menu > li > a > i,
	body[class*="skin-"] .sidebar-menu .treeview-menu > li > a > .fa {
		width: 16px;
		font-size: 12px !important;
		margin-right: 6px;
		color: var(--nv-side-muted);
	}
	body[class*="skin-"] .sidebar-menu .treeview-menu > li.active > a > i {
		color: var(--nv-accent);
	}
	/* Section labels (FAVORITES etc.) */
	body[class*="skin-"] .sidebar-menu > li.header {
		color: var(--nv-side-muted) !important;
		background: transparent !important;
		font-size: 11px;
		font-weight: 600;
		letter-spacing: .08em;
	}
	/* User panel at top of sidebar */
	body .sidebar .user-panel .info > p {
		color: var(--nv-side-ink) !important;
		font-weight: 600;
	}

	/* ---------- Scrollbar accent ---------- */
	body ::-webkit-scrollbar-thumb {
		background: #CBD2DC;
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
