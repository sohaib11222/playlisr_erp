{{-- ===========================================================
     POS Checkout — Redesign v2 (per nivessa_pos_redesign.html mockup)
     2026-04-20 · Sarah

     GOAL: Visual + layout reskin of the existing POS checkout screen.
     Functionality, data bindings, IDs, and event handlers are all kept
     intact — everything here is CSS + a couple of DOM tweaks (bag stepper,
     KEY THIS INTO CLOVER badge, receipt row re-order). Included once at
     the top of sale_pos/create.blade.php.

     Overrides are scoped under body.pos-v2 so they only apply on the
     POS checkout screen and don't bleed to other views.

     USER-SPECIFIED TOKENS (from Sarah's ask, override mockup where they
     differ):
       --pos-accent:   #FFF2B3  pastel yellow — brand accent
       --pos-cr:       #7A1F1F  deep burgundy — Close Register only

     The rest of the palette is lifted from nivessa_pos_redesign.html.
     ============================================================ --}}

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
/* ============ SCOPED TOKENS ============ */
body.pos-v2 {
	--pos-bg:          #FAF6EE;
	--pos-surface:     #FFFFFF;
	--pos-surface-2:   #F7F1E3;
	--pos-ink:         #1F1B16;
	--pos-ink-2:       #5A5045;
	--pos-ink-3:       #8E8273;
	--pos-line:        #ECE3CF;
	--pos-line-2:      #DFD2B3;

	--pos-brand:       #1F1B16;
	--pos-brand-ink:   #FAF6EE;

	/* Sarah's overrides */
	--pos-accent:      #FFF2B3;   /* pastel yellow accent */
	--pos-accent-deep: #E8CF68;   /* hover / border for accent */
	--pos-accent-soft: #FFF9DB;   /* soft tint backgrounds */
	--pos-accent-text: #5A4410;   /* readable text on accent */
	--pos-cr:          #7A1F1F;   /* Close Register burgundy */
	--pos-cr-hover:    #5C1515;

	--pos-success:     #2F6B3E;
	--pos-danger:      #8A3A2E;

	--pos-radius:      10px;
	--pos-radius-sm:   8px;
	--pos-shadow-sm:   0 1px 2px rgba(31,27,22,.06);
	--pos-shadow-md:   0 4px 14px rgba(31,27,22,.08);

	background: var(--pos-bg);
	font-family: "Inter Tight", system-ui, sans-serif;
	color: var(--pos-ink);
	-webkit-font-smoothing: antialiased;
}

body.pos-v2,
body.pos-v2 .content,
body.pos-v2 .content-wrapper,
body.pos-v2 section.content {
	font-family: "Inter Tight", system-ui, sans-serif;
}
body.pos-v2 .box,
body.pos-v2 .box-body,
body.pos-v2 .form-control,
body.pos-v2 .btn,
body.pos-v2 input,
body.pos-v2 select,
body.pos-v2 textarea,
body.pos-v2 button {
	font-family: inherit;
}

/* ============ CLOSE REGISTER — burgundy, commands attention ============ */
body.pos-v2 #close_register,
body.pos-v2 .close-register-btn,
body.pos-v2 [data-target="#close_register_modal"],
body.pos-v2 button[onclick*="close_register"],
body.pos-v2 a[href*="close-register"],
body.pos-v2 .btn-close-register {
	background: var(--pos-cr) !important;
	color: #fff !important;
	border: 2px solid var(--pos-cr) !important;
	border-radius: 10px !important;
	padding: 8px 18px !important;
	font-weight: 800 !important;
	letter-spacing: .04em !important;
	text-transform: uppercase !important;
	font-size: 14px !important;
	box-shadow: 0 0 0 3px rgba(122,31,31,.15), 0 2px 6px rgba(0,0,0,.25) !important;
	transition: transform .1s ease, box-shadow .15s, background .15s !important;
}
body.pos-v2 #close_register:hover,
body.pos-v2 .close-register-btn:hover,
body.pos-v2 [data-target="#close_register_modal"]:hover,
body.pos-v2 .btn-close-register:hover {
	background: var(--pos-cr-hover) !important;
	transform: translateY(-1px);
	box-shadow: 0 0 0 4px rgba(122,31,31,.25), 0 4px 10px rgba(0,0,0,.3) !important;
}

/* ============ CONTENT SHELL ============ */
body.pos-v2 section.content {
	padding: 0 !important;
	background: var(--pos-bg);
}
body.pos-v2 section.content > form {
	padding: 20px;
	max-width: 1500px;
	margin: 0 auto;
}

/* Grid layout override removed 2026-04-21.
   Earlier version forced `grid-template-columns: minmax(0,1fr) 360px`
   which clobbered Bootstrap's col-md-8 / col-md-4 layout after Sohaib's
   buyer-calc deploy and pushed every POS element into a narrow right
   column. Visual restyling below still applies; Bootstrap columns
   handle the two-up layout natively. Safer. */

/* ============ CARDS ============ */
body.pos-v2 .box.box-solid,
body.pos-v2 .pos_form_totals,
body.pos-v2 .nv-card {
	background: var(--pos-surface) !important;
	border: 1px solid var(--pos-line) !important;
	border-radius: var(--pos-radius) !important;
	box-shadow: var(--pos-shadow-sm) !important;
}
body.pos-v2 .box .box-body {
	padding: 18px 20px !important;
}

/* Section titles (small uppercase ink-3 labels like "CUSTOMER", "RING UP…") */
body.pos-v2 .nv-card-title {
	font-size: 11px; font-weight: 600; letter-spacing: .12em;
	text-transform: uppercase; color: var(--pos-ink-3);
	margin: 0 0 12px;
}

/* ============ CUSTOMER ROW — input + rewards button side-by-side ============ */
body.pos-v2 .pos-customer-block .form-group { max-width: none !important; }
body.pos-v2 .pos-customer-block .control-label {
	font-size: 11px; font-weight: 600; letter-spacing: .12em;
	text-transform: uppercase; color: var(--pos-ink-3); margin-bottom: 12px;
}
body.pos-v2 .pos-customer-block .input-group { width: auto; }
body.pos-v2 .pos-customer-block .input-group-addon {
	background: var(--pos-surface) !important;
	border: 1px solid var(--pos-line-2) !important;
	border-right: none !important;
	color: var(--pos-ink-3) !important;
}
body.pos-v2 .pos-customer-block .select2-selection,
body.pos-v2 .pos-customer-block .form-control {
	height: 44px !important;
	border: 1px solid var(--pos-line-2) !important;
	border-radius: 0 var(--pos-radius-sm) var(--pos-radius-sm) 0 !important;
	background: var(--pos-surface) !important;
	color: var(--pos-ink) !important;
}
body.pos-v2 .pos-customer-block .select2-selection:focus,
body.pos-v2 .pos-customer-block .form-control:focus {
	border-color: var(--pos-accent-deep) !important;
	box-shadow: 0 0 0 3px var(--pos-accent-soft) !important;
	outline: none !important;
}

/* Move Sign Up for Nivessa Bucks to the RIGHT of the customer search.
   The Blade puts the button in its own <div> below the input group. We
   use flex on the parent form-group to lay them side-by-side, then re-
   order so the button sits after the input group. */
body.pos-v2 .pos-customer-block .form-group {
	display: flex !important; flex-direction: column;
}
body.pos-v2 .pos-customer-block .form-group > .input-group {
	order: 1;
}
body.pos-v2 .pos-customer-block .form-group > div:has(.add_new_customer) {
	order: 2; margin-top: 8px !important;
}
/* On wider screens: put the button beside the input */
@media (min-width: 992px) {
	body.pos-v2 .pos-customer-block .form-group {
		flex-direction: row !important;
		flex-wrap: wrap; align-items: center; gap: 10px;
	}
	body.pos-v2 .pos-customer-block .form-group > .control-label {
		flex: 0 0 100%;
	}
	body.pos-v2 .pos-customer-block .form-group > .input-group {
		flex: 1 1 auto !important; min-width: 240px;
	}
	body.pos-v2 .pos-customer-block .form-group > div:has(.add_new_customer) {
		order: 2; flex: 0 0 auto; margin-top: 0 !important;
	}
	body.pos-v2 .pos-customer-block .form-group > div:has(.add_new_customer) > small {
		display: none !important;   /* helper text moves to row-below */
	}
	body.pos-v2 .pos-customer-block .form-group::after {
		content: "Rewards on every purchase — ask every walk-in.";
		flex: 0 0 100%;
		font-size: 12px; color: var(--pos-ink-3); margin-top: 2px;
	}
}
body.pos-v2 .add_new_customer {
	background: var(--pos-accent-soft) !important;
	color: var(--pos-accent-text) !important;
	border: 1px solid var(--pos-accent-deep) !important;
	border-radius: 999px !important;
	padding: 10px 16px !important;
	font-weight: 600 !important;
	white-space: nowrap;
}
body.pos-v2 .add_new_customer:hover {
	background: var(--pos-accent) !important;
}

/* ============ SEARCH / RING UP ============ */
body.pos-v2 .pos-product-search-label {
	color: var(--pos-ink-3) !important;
	font-size: 11px !important; letter-spacing: .12em !important;
	font-weight: 600 !important; text-transform: uppercase !important;
}
body.pos-v2 .pos-product-search-configbtn {
	border: 1px solid var(--pos-line-2) !important;
	border-right: none !important;
	background: var(--pos-surface) !important;
	color: var(--pos-ink-3) !important;
	border-radius: var(--pos-radius-sm) 0 0 var(--pos-radius-sm) !important;
	height: 48px !important; width: 48px !important;
}
body.pos-v2 .pos-product-search-configbtn:hover {
	background: var(--pos-surface-2) !important;
}
body.pos-v2 .pos-product-search-wrap #search_product {
	border: 1px solid var(--pos-line-2) !important;
	border-left: none !important;
	border-radius: 0 var(--pos-radius-sm) var(--pos-radius-sm) 0 !important;
	box-shadow: none !important;
	height: 48px !important;
	font-size: 15px !important; font-weight: 500 !important;
	color: var(--pos-ink) !important;
	background: var(--pos-surface) !important;
	padding: 10px 14px !important;
}
body.pos-v2 .pos-product-search-wrap #search_product:focus {
	border-color: var(--pos-accent-deep) !important;
	box-shadow: 0 0 0 3px var(--pos-accent-soft) !important;
}

/* Tool row (New Product / Add Manual Item / Buy Calculator) */
body.pos-v2 .pos-action-row .btn {
	background: var(--pos-surface) !important;
	color: var(--pos-ink-2) !important;
	border: 1px solid var(--pos-line-2) !important;
	font-weight: 500 !important; font-size: 13px !important;
	height: 36px !important; padding: 0 14px !important;
	border-radius: var(--pos-radius-sm) !important;
}
body.pos-v2 .pos-action-row .btn:hover {
	background: var(--pos-surface-2) !important;
	color: var(--pos-ink) !important;
}
body.pos-v2 .pos-action-row .btn.btn-info {
	background: #EAF1F8 !important;
	color: #2B4A6B !important;
	border-color: #C8D8E8 !important;
}
body.pos-v2 .pos-action-row .btn.btn-info:hover {
	background: #D8E5F1 !important;
	color: #1F3A55 !important;
}

/* ============ CART TABLE ============ */
body.pos-v2 table#pos_table {
	border: none !important; background: transparent !important;
}
body.pos-v2 table#pos_table thead th {
	text-align: left !important;
	font-size: 11px !important; font-weight: 600 !important;
	letter-spacing: .1em !important; text-transform: uppercase !important;
	color: var(--pos-ink-3) !important;
	padding: 10px 6px !important;
	border-bottom: 1px solid var(--pos-line) !important;
	background: transparent !important;
}
body.pos-v2 table#pos_table tbody td {
	border-color: var(--pos-line) !important;
	padding: 10px 6px !important;
}
body.pos-v2 table#pos_table tbody tr:nth-child(even) { background: transparent !important; }

/* Product name on ONE line */
body.pos-v2 table#pos_table .product_name,
body.pos-v2 table#pos_table .product-name,
body.pos-v2 table#pos_table tr.product_row td:first-child > div,
body.pos-v2 table#pos_table tr.product_row td:first-child > span {
	white-space: nowrap !important;
	overflow: hidden !important;
	text-overflow: ellipsis !important;
	max-width: 100%;
}
body.pos-v2 table#pos_table tr.product_row td:first-child {
	max-width: 0; /* trigger ellipsis in table cell */
	overflow: hidden;
}

/* Qty buttons: red/green → neutral brand-black */
body.pos-v2 table#pos_table .quantity-up i,
body.pos-v2 table#pos_table .quantity-down i,
body.pos-v2 table#pos_table .quantity-up .fa,
body.pos-v2 table#pos_table .quantity-down .fa {
	color: var(--pos-ink) !important;
}
body.pos-v2 table#pos_table .quantity-up,
body.pos-v2 table#pos_table .quantity-down {
	background: var(--pos-surface) !important;
	border: 1px solid var(--pos-line-2) !important;
	color: var(--pos-ink) !important;
}
body.pos-v2 table#pos_table .quantity-up:hover,
body.pos-v2 table#pos_table .quantity-down:hover {
	background: var(--pos-surface-2) !important;
}

/* ============ TOTALS / RECEIPT ============ */
body.pos-v2 .pos-tot-block {
	background: var(--pos-surface) !important;
	border: 1px solid var(--pos-line) !important;
	border-radius: var(--pos-radius) !important;
	padding: 18px 20px !important;
	font-family: inherit !important;
	color: var(--pos-ink) !important;
}

/* Sale flags (Mark as Whatnot / Bag Fee) */
body.pos-v2 .pos-tot-chip {
	background: var(--pos-surface-2) !important;
	border: 1px solid var(--pos-line) !important;
	color: var(--pos-ink-2) !important;
	font-weight: 500 !important;
	border-radius: 999px !important;
	padding: 6px 12px !important;
}
body.pos-v2 #whatnot_chip {
	background: transparent !important;
	border: 1px dashed var(--pos-line-2) !important;
	color: var(--pos-ink-3) !important;
	font-weight: 500 !important; font-size: 12px !important;
	padding: 5px 11px !important;
}
body.pos-v2 #whatnot_chip:hover {
	border-color: var(--pos-ink-3) !important; color: var(--pos-ink-2) !important;
}
body.pos-v2 .pos-tot-chip.active-whatnot {
	background: var(--pos-accent-soft) !important;
	border-color: var(--pos-accent-deep) !important;
	color: var(--pos-accent-text) !important;
}
body.pos-v2 #bag_chip,
body.pos-v2 .pos-tot-chip.active-bag {
	background: var(--pos-accent-soft) !important;
	border: 1px solid var(--pos-accent-deep) !important;
	color: var(--pos-accent-text) !important;
	font-weight: 600 !important;
}

/* Bag stepper (injected by the JS below) */
body.pos-v2 .pos-bag-stepper {
	display: inline-flex; align-items: center; margin-left: 6px;
	background: #fff; border: 1px solid var(--pos-accent-deep);
	border-radius: 999px; overflow: hidden;
}
body.pos-v2 .pos-bag-stepper button {
	border: none; background: transparent;
	width: 24px; height: 24px;
	font-size: 14px; font-weight: 700;
	color: var(--pos-accent-text); cursor: pointer;
	display: inline-flex; align-items: center; justify-content: center;
}
body.pos-v2 .pos-bag-stepper button:hover { background: var(--pos-accent); }
body.pos-v2 .pos-bag-stepper .pos-bag-count {
	min-width: 20px; text-align: center;
	font-weight: 700; font-size: 13px; color: var(--pos-accent-text);
	padding: 0 2px;
}

/* Re-layout the summary grid as a vertical receipt. Items and Tax become
   small label/value rows (like the adjustments below); Pre-tax → Clover
   becomes the hero mustard-yellow box with a "KEY THIS INTO CLOVER"
   pill; Total w/ tax becomes a bold grand-total row. Flow: Items →
   Tax → (hero Pre-Tax) → Grand Total. */
body.pos-v2 .pos-tot-summary {
	display: flex !important; flex-direction: column !important;
	gap: 4px !important; border-bottom: none !important; padding: 0 !important;
}
body.pos-v2 .pos-tot-summary .stat {
	display: flex !important; flex-direction: row !important;
	align-items: center !important; justify-content: space-between !important;
	line-height: 1.2 !important;
	padding: 4px 0 !important; margin: 0 !important;
	text-align: left !important;
}
body.pos-v2 .pos-tot-summary .stat .lbl {
	font-size: 13.5px !important; font-weight: 500 !important;
	text-transform: none !important; letter-spacing: 0 !important;
	color: var(--pos-ink-2) !important; margin: 0 !important;
}
body.pos-v2 .pos-tot-summary .stat .val {
	font-size: 13.5px !important; font-weight: 600 !important;
	color: var(--pos-ink) !important; margin: 0 !important;
	font-variant-numeric: tabular-nums;
}

/* Pre-Tax → Clover hero styles moved into pos_form_totals.blade.php as
   .pos-pretax-bar (full-width horizontal bar outside the summary flex row,
   matching the nivessa_pos_redesign.html mockup). The old .stat.pretax
   markup is gone, so the v2 overrides here are dead — intentionally
   removed 2026-04-21. */

/* Grand total row */
body.pos-v2 .pos-tot-summary .stat.grand {
	border-top: 1px dashed var(--pos-line-2) !important;
	padding-top: 10px !important; padding-bottom: 2px !important;
	margin-top: 6px !important; margin-left: 0 !important;
	text-align: left !important;
}
body.pos-v2 .pos-tot-summary .stat.grand .lbl {
	color: var(--pos-ink) !important;
	font-weight: 700 !important; font-size: 15px !important;
	text-transform: none !important; letter-spacing: 0 !important;
}
body.pos-v2 .pos-tot-summary .stat.grand .val {
	color: var(--pos-ink) !important;
	font-weight: 800 !important; font-size: 22px !important;
	letter-spacing: -.01em !important;
}

/* Receipt rows */
body.pos-v2 .pos-adjust-list {
	margin-top: 14px !important;
	display: flex !important; flex-direction: column;
	gap: 4px !important;
}
body.pos-v2 .pos-adjust-row {
	display: flex !important; align-items: center !important;
	padding: 4px 0 !important; min-height: 26px !important;
	gap: 8px !important;
}
body.pos-v2 .pos-adjust-row .adj-label {
	flex: 0 0 auto !important;
	font-size: 13.5px !important;
	font-weight: 500 !important;
	text-transform: none !important; letter-spacing: 0 !important;
	color: var(--pos-ink-2) !important;
}
body.pos-v2 .pos-adjust-row .adj-btn {
	background: var(--pos-surface) !important;
	border: 1px solid var(--pos-line) !important;
	color: var(--pos-ink-2) !important;
	padding: 3px 8px !important;
	border-radius: 6px !important;
	font-size: 12px !important; font-weight: 500 !important;
}
body.pos-v2 .pos-adjust-row .adj-btn:hover {
	background: var(--pos-surface-2) !important;
}
body.pos-v2 .pos-adjust-row .adj-value {
	margin-left: auto !important;
	font-size: 13.5px !important; font-weight: 600 !important;
	color: var(--pos-ink) !important;
	font-variant-numeric: tabular-nums;
}
body.pos-v2 .pos-adjust-row .adj-rate {
	font-size: 12px !important;
	color: var(--pos-ink-3) !important; font-weight: 400 !important;
}

/* Pre-tax hero row — "KEY THIS INTO CLOVER" badge */
body.pos-v2 .pos-receipt-pretax {
	margin: 14px 0 6px !important;
	padding: 16px 18px !important;
	background: var(--pos-accent) !important;
	border: 2px solid var(--pos-accent-deep) !important;
	border-radius: 12px !important;
	box-shadow: 0 0 0 3px rgba(232,207,104,.3), 0 2px 6px rgba(0,0,0,.08);
	position: relative;
	display: flex !important; align-items: center !important;
	min-height: auto !important;
}
body.pos-v2 .pos-receipt-pretax::before {
	content: "KEY THIS INTO CLOVER";
	position: absolute; top: -9px; left: 14px;
	background: var(--pos-ink); color: var(--pos-accent);
	font-size: 10px; font-weight: 700;
	letter-spacing: .14em; padding: 3px 10px;
	border-radius: 999px;
}
body.pos-v2 .pos-receipt-pretax .adj-label {
	color: var(--pos-accent-text) !important;
	font-weight: 700 !important;
	font-size: 13px !important;
	text-transform: uppercase !important; letter-spacing: .08em !important;
	display: flex !important; flex-direction: column !important;
	align-items: flex-start !important; gap: 2px !important;
}
body.pos-v2 .pos-receipt-pretax .adj-label::after {
	content: "Type this amount into the Clover terminal";
	font-size: 10px; font-weight: 500;
	text-transform: none; letter-spacing: 0;
	color: var(--pos-accent-text); opacity: .7;
}
body.pos-v2 .pos-receipt-pretax .adj-value {
	color: var(--pos-accent-text) !important;
	font-weight: 800 !important;
	font-size: 30px !important;
	letter-spacing: -.02em !important;
	font-variant-numeric: tabular-nums;
}

/* Dashed dividers between receipt sections */
body.pos-v2 .pos-receipt-divider {
	border-top: 1px dashed var(--pos-line-2);
	margin: 8px 0;
}

/* Grand total row */
body.pos-v2 .pos-receipt-grand .adj-label {
	color: var(--pos-ink) !important;
	font-weight: 700 !important; font-size: 15px !important;
	text-transform: none !important; letter-spacing: 0 !important;
}
body.pos-v2 .pos-receipt-grand .adj-value {
	color: var(--pos-ink) !important;
	font-weight: 800 !important; font-size: 22px !important;
	letter-spacing: -.01em !important;
}

/* ============ PAYMENT ACTIONS (Cash / Card / More) ============ */
body.pos-v2 .pos-payment-actions {
	display: grid !important;
	grid-template-columns: 1fr 1fr auto !important;
	gap: 10px !important;
	margin-top: 16px !important;
}
body.pos-v2 .btn-pay-primary,
body.pos-v2 .btn-pay-cash,
body.pos-v2 .btn-pay-card,
body.pos-v2 .btn-pay-more {
	padding: 11px 16px !important;
	border-radius: var(--pos-radius-sm) !important;
	font-weight: 600 !important; font-size: 14px !important;
	border: none !important;
	display: flex !important; align-items: center !important;
	justify-content: center !important; gap: 8px !important;
	letter-spacing: .02em !important;
	min-height: 44px !important;
	height: 44px !important;
}
body.pos-v2 .btn-pay-cash {
	background: var(--pos-success) !important; color: #fff !important;
}
body.pos-v2 .btn-pay-cash:hover { background: #265732 !important; }
body.pos-v2 .btn-pay-card {
	background: var(--pos-brand) !important; color: var(--pos-brand-ink) !important;
}
body.pos-v2 .btn-pay-card:hover { background: #3a2e22 !important; }
body.pos-v2 .btn-pay-more {
	background: var(--pos-surface) !important; color: var(--pos-ink) !important;
	border: 1px solid var(--pos-line-2) !important;
}
body.pos-v2 .btn-pay-more:hover { background: var(--pos-surface-2) !important; }

/* Cancel Sale link */
body.pos-v2 .pos-cancel-sale {
	display: block !important; text-align: center !important;
	margin-top: 12px !important;
	color: var(--pos-ink-3) !important; font-size: 13px !important;
	text-decoration: none !important;
}
body.pos-v2 .pos-cancel-sale:hover { color: var(--pos-danger) !important; }

/* ============ QUICK ADD TILES (sidebar) ============ */
body.pos-v2 .pos-quick-grid-title {
	font-size: 11px !important; font-weight: 600 !important;
	letter-spacing: .12em !important; text-transform: uppercase !important;
	color: var(--pos-ink-3) !important; margin-bottom: 10px !important;
}
body.pos-v2 .pos-quick-grid {
	display: grid !important;
	grid-template-columns: 1fr 1fr !important;
	gap: 10px !important;
}
body.pos-v2 .pos-quick-tile {
	background: var(--pos-surface) !important;
	border: 1px solid var(--pos-line) !important;
	border-radius: var(--pos-radius-sm) !important;
	padding: 18px 10px !important;
	display: flex !important; flex-direction: column !important;
	align-items: center !important; justify-content: center !important;
	gap: 4px !important;
	color: var(--pos-ink) !important;
	box-shadow: none !important;
	transition: transform .1s ease, border-color .15s, background .15s !important;
}
body.pos-v2 .pos-quick-tile:hover {
	border-color: var(--pos-accent-deep) !important;
	background: var(--pos-accent-soft) !important;
	transform: none !important;
}
body.pos-v2 .pos-quick-tile:active { transform: translateY(1px) !important; }

/* Uniform icon treatment — strip the mixed emoji/FA bold styling.
   Tiles contain either <i class="fa fa-..."> OR an inline emoji <span>.
   Normalize both to 20px, ink color, muted weight so the grid feels even. */
body.pos-v2 .pos-quick-tile i,
body.pos-v2 .pos-quick-tile > span:first-child {
	font-size: 20px !important;
	line-height: 1 !important;
	margin-bottom: 4px !important;
	display: block !important;
	color: var(--pos-ink-2) !important;
	font-weight: 400 !important;
}
body.pos-v2 .pos-quick-tile .pos-quick-price {
	font-weight: 700 !important;
	font-size: 15px !important;
	color: var(--pos-ink-2) !important;
	margin-top: 2px !important;
	letter-spacing: 0 !important;
}
/* Name text (sits between icon and price as a text node) — can't target
   directly without a wrapper, but the children spans already get styled
   above; the plain text node inherits body.pos-v2 styles. */
</style>

{{-- Hidden helper: inject a bag stepper next to the Bag Fee toggle and
     handle +/- clicks. Uses existing plastic-bag row in #pos_table so the
     backend sees a single line with quantity N (no new data path). --}}
<script>
(function () {
	function onReady(fn) {
		if (typeof jQuery === 'undefined') { setTimeout(function(){ onReady(fn); }, 50); return; }
		jQuery(fn);
	}
	onReady(function ($) {
		if ($('#bag_chip').length === 0) return;
		if ($('#bag_chip .pos-bag-stepper').length) return;

		var $stepper = $('<span class="pos-bag-stepper" onclick="event.preventDefault()">' +
			'<button type="button" aria-label="Remove a bag" data-step="-1">−</button>' +
			'<span class="pos-bag-count">1</span>' +
			'<button type="button" aria-label="Add a bag" data-step="1">+</button>' +
			'</span>');

		$('#bag_chip span').first().after($stepper);

		function currentCount() {
			var $qty = $('#pos_table tbody tr[data-plastic-bag="true"] input.input_quantity');
			if (!$qty.length) return parseInt($('.pos-bag-count').text(), 10) || 1;
			return parseInt($qty.val(), 10) || 1;
		}

		function setCount(n) {
			if (n < 1) n = 1;
			if (n > 20) n = 20;
			$('.pos-bag-count').text(n);
			var $qty = $('#pos_table tbody tr[data-plastic-bag="true"] input.input_quantity');
			if ($qty.length) {
				$qty.val(n).trigger('change').trigger('input');
			}
		}

		$stepper.on('click', 'button', function (e) {
			e.preventDefault(); e.stopPropagation();
			var step = parseInt($(this).data('step'), 10) || 0;
			setCount(currentCount() + step);
		});

		// Keep count in sync if other code changes the bag qty
		$(document).on('change input', '#pos_table tbody tr[data-plastic-bag="true"] input.input_quantity', function () {
			$('.pos-bag-count').text(currentCount());
		});

		// Initialize display from the current plastic-bag row qty, if any
		setTimeout(function () { $('.pos-bag-count').text(currentCount()); }, 300);
	});
})();
</script>
