{{-- ===========================================================
     /products/mass-create — UI reskin (2026-05-06 · Sarah)

     Mirrors the POS Checkout v2 visual language (cream surface,
     Inter Tight, soft cards, accent yellow) on the Mass Add page
     so it stops feeling like a different app from /pos/create.

     Pure CSS + a body-class hook. No DOM, no IDs, no handlers
     are touched — the existing JS in mass-create.blade.php and
     /public/js/products.js continues to work unchanged.

     Scoped under body.mass-add-v2 so it doesn't bleed elsewhere.
     ============================================================ --}}

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet"
      href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap"
      media="print" onload="this.media='all'">
<noscript>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap">
</noscript>

<style>
/* ===== Tokens (lifted from POS v2 so the two screens read as one app) ===== */
body.mass-add-v2 {
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
    --pos-accent:      #FFF2B3;
    --pos-accent-deep: #E8CF68;
    --pos-accent-soft: #FFF9DB;
    --pos-accent-text: #5A4410;

    --pos-success:     #2F6B3E;
    --pos-danger:      #8A3A2E;

    --pos-radius:      10px;
    --pos-radius-sm:   8px;
    --pos-shadow-sm:   0 1px 2px rgba(31,27,22,.06);
    --pos-shadow-md:   0 4px 14px rgba(31,27,22,.08);

    background: var(--pos-bg);
    font-family: "Inter Tight", system-ui, -apple-system, sans-serif;
    color: var(--pos-ink);
    -webkit-font-smoothing: antialiased;
}

body.mass-add-v2 .content-wrapper {
    background: var(--pos-bg) !important;
}

body.mass-add-v2 .content,
body.mass-add-v2 .content-wrapper,
body.mass-add-v2 section.content,
body.mass-add-v2 .box,
body.mass-add-v2 .box-body,
body.mass-add-v2 .form-control,
body.mass-add-v2 .btn,
body.mass-add-v2 input,
body.mass-add-v2 select,
body.mass-add-v2 textarea,
body.mass-add-v2 button,
body.mass-add-v2 table,
body.mass-add-v2 td,
body.mass-add-v2 th {
    font-family: inherit;
}

/* ===== Page header ===== */
body.mass-add-v2 .content-header {
    padding: 22px 20px 8px;
}
body.mass-add-v2 .content-header h1 {
    font-weight: 700;
    font-size: 26px;
    color: var(--pos-ink);
    letter-spacing: -.01em;
    margin: 0;
}
body.mass-add-v2 .content-header h1 small {
    color: var(--pos-ink-3);
    font-weight: 500;
    margin-left: 6px;
}

/* ===== Content shell ===== */
body.mass-add-v2 section.content {
    padding: 0 20px 20px !important;
    max-width: 1500px;
    margin: 0 auto;
}

/* ===== Cards (matches POS .box.box-solid look) ===== */
body.mass-add-v2 .box {
    background: var(--pos-surface) !important;
    border: 1px solid var(--pos-line) !important;
    border-radius: var(--pos-radius) !important;
    box-shadow: var(--pos-shadow-sm) !important;
    border-top: 1px solid var(--pos-line) !important;  /* AdminLTE adds a colored top border */
}
body.mass-add-v2 .box.box-primary,
body.mass-add-v2 .box.box-info,
body.mass-add-v2 .box.box-success,
body.mass-add-v2 .box.box-warning {
    border-top: 1px solid var(--pos-line) !important;
}
body.mass-add-v2 .box-header {
    padding: 14px 18px !important;
    border-bottom: 1px solid var(--pos-line) !important;
    background: var(--pos-surface) !important;
    color: var(--pos-ink) !important;
}
body.mass-add-v2 .box-header.with-border {
    border-bottom: 1px solid var(--pos-line) !important;
}
body.mass-add-v2 .box-title {
    color: var(--pos-ink) !important;
    font-weight: 600 !important;
    font-size: 14px !important;
    letter-spacing: .02em !important;
    margin: 0 !important;
}
body.mass-add-v2 .box-title .fa {
    color: var(--pos-ink-3) !important;
    margin-right: 6px;
}
body.mass-add-v2 .box-body {
    padding: 16px 18px !important;
    color: var(--pos-ink) !important;
}

/* ===== Buttons — neutral surface look (POS .pos-action-row pattern) ===== */
body.mass-add-v2 .btn {
    border-radius: var(--pos-radius-sm) !important;
    font-weight: 500 !important;
    font-size: 13px !important;
    letter-spacing: .01em !important;
    padding: 8px 14px !important;
    transition: background .15s, border-color .15s, color .15s !important;
}
body.mass-add-v2 .btn-default {
    background: var(--pos-surface) !important;
    color: var(--pos-ink-2) !important;
    border: 1px solid var(--pos-line-2) !important;
}
body.mass-add-v2 .btn-default:hover {
    background: var(--pos-surface-2) !important;
    color: var(--pos-ink) !important;
}
body.mass-add-v2 .btn-primary {
    background: var(--pos-brand) !important;
    color: var(--pos-brand-ink) !important;
    border: 1px solid var(--pos-brand) !important;
}
body.mass-add-v2 .btn-primary:hover {
    background: #3a2e22 !important;
    border-color: #3a2e22 !important;
}
body.mass-add-v2 .btn-info {
    background: var(--pos-accent-soft) !important;
    color: var(--pos-accent-text) !important;
    border: 1px solid var(--pos-accent-deep) !important;
}
body.mass-add-v2 .btn-info:hover {
    background: var(--pos-accent) !important;
    color: var(--pos-accent-text) !important;
    border-color: var(--pos-accent-deep) !important;
}
body.mass-add-v2 .btn-success {
    background: var(--pos-success) !important;
    color: #fff !important;
    border: 1px solid var(--pos-success) !important;
}
body.mass-add-v2 .btn-success:hover {
    background: #265732 !important;
    border-color: #265732 !important;
}
body.mass-add-v2 .btn-warning {
    background: var(--pos-accent) !important;
    color: var(--pos-accent-text) !important;
    border: 1px solid var(--pos-accent-deep) !important;
    font-weight: 700 !important;
}
body.mass-add-v2 .btn-warning:hover {
    background: var(--pos-accent-deep) !important;
    color: var(--pos-ink) !important;
}
body.mass-add-v2 .btn-danger {
    background: var(--pos-danger) !important;
    color: #fff !important;
    border: 1px solid var(--pos-danger) !important;
}
body.mass-add-v2 .btn-danger:hover {
    background: #6f2e25 !important;
    border-color: #6f2e25 !important;
}
body.mass-add-v2 .btn-xs {
    font-size: 11px !important;
    padding: 4px 9px !important;
}
body.mass-add-v2 .btn-sm {
    font-size: 12px !important;
    padding: 6px 11px !important;
}
body.mass-add-v2 .btn-block {
    padding: 11px 14px !important;
    min-height: 44px !important;
}

/* ===== Inputs ===== */
body.mass-add-v2 .form-control {
    border: 1px solid var(--pos-line-2) !important;
    border-radius: var(--pos-radius-sm) !important;
    background: var(--pos-surface) !important;
    color: var(--pos-ink) !important;
    box-shadow: none !important;
    transition: border-color .15s, box-shadow .15s !important;
}
body.mass-add-v2 .form-control:focus {
    border-color: var(--pos-accent-deep) !important;
    box-shadow: 0 0 0 3px var(--pos-accent-soft) !important;
    outline: none !important;
}
body.mass-add-v2 .input-group-addon {
    background: var(--pos-surface-2) !important;
    border: 1px solid var(--pos-line-2) !important;
    color: var(--pos-ink-3) !important;
}
body.mass-add-v2 .select2-container--default .select2-selection--single {
    border: 1px solid var(--pos-line-2) !important;
    border-radius: var(--pos-radius-sm) !important;
    background: var(--pos-surface) !important;
}
body.mass-add-v2 .select2-container--default .select2-selection--single .select2-selection__rendered {
    color: var(--pos-ink) !important;
    line-height: 32px !important;
}
body.mass-add-v2 .select2-container--default.select2-container--focus
    .select2-selection--single,
body.mass-add-v2 .select2-container--default.select2-container--open
    .select2-selection--single {
    border-color: var(--pos-accent-deep) !important;
    box-shadow: 0 0 0 3px var(--pos-accent-soft) !important;
}

/* ===== Tables (cost-rules table inside box, mass-create main table) ===== */
body.mass-add-v2 .table {
    background: transparent !important;
    color: var(--pos-ink) !important;
}
body.mass-add-v2 .table > thead > tr > th,
body.mass-add-v2 .thead .th,
body.mass-add-v2 #mass_create_table .thead .th {
    text-align: left !important;
    font-size: 11px !important;
    font-weight: 600 !important;
    letter-spacing: .1em !important;
    text-transform: uppercase !important;
    color: var(--pos-ink-3) !important;
    background: var(--pos-surface-2) !important;
    border-bottom: 1px solid var(--pos-line) !important;
    padding: 10px 8px !important;
}
body.mass-add-v2 .table > tbody > tr > td,
body.mass-add-v2 .tbody .td,
body.mass-add-v2 #mass_create_table .tbody .td {
    border-color: var(--pos-line) !important;
    color: var(--pos-ink) !important;
    padding: 10px 8px !important;
    vertical-align: middle !important;
}
body.mass-add-v2 .table-striped > tbody > tr:nth-of-type(odd) {
    background: var(--pos-surface-2) !important;
}
body.mass-add-v2 .responsive-table {
    border: 1px solid var(--pos-line) !important;
    border-radius: var(--pos-radius) !important;
    background: var(--pos-surface) !important;
    box-shadow: var(--pos-shadow-sm) !important;
}

/* ===== Helper text / alerts ===== */
body.mass-add-v2 .text-muted,
body.mass-add-v2 small.text-muted {
    color: var(--pos-ink-3) !important;
}
body.mass-add-v2 .alert {
    border-radius: var(--pos-radius-sm) !important;
    border: 1px solid var(--pos-line) !important;
    background: var(--pos-surface-2) !important;
    color: var(--pos-ink-2) !important;
}
body.mass-add-v2 .alert-info {
    background: var(--pos-accent-soft) !important;
    border-color: var(--pos-accent-deep) !important;
    color: var(--pos-accent-text) !important;
}
body.mass-add-v2 .alert-warning {
    background: var(--pos-accent) !important;
    border-color: var(--pos-accent-deep) !important;
    color: var(--pos-accent-text) !important;
}

/* ===== Table footer action toolbar.
        2026-05-06 v2 (Sarah): the previous 320px column made the page feel
        squished. Now the action area is a wide toolbar:
          ROW 1: [Add New Product Row] [Add 5 Product Rows]   ← compact, content-sized
          ROW 2: [   Verify All Categories   ]                ← full toolbar width
          ROW 3: [ Save All Products ] [ Save & send to add purchase ]
        Toolbar fills the table width up to a comfortable 720px max. ===== */
body.mass-add-v2 #mass_create_table > tfoot > tr > td {
    border: none !important;
    padding: 16px 0 0 !important;
    background: transparent !important;
}

/* Row 1 — Add row buttons: left-aligned, content-sized. */
body.mass-add-v2 #mass_create_table > tfoot > tr:first-child > td {
    display: flex !important;
    flex-wrap: wrap !important;
    gap: 10px !important;
    width: auto !important;
    max-width: none !important;
}
body.mass-add-v2 #add_row,
body.mass-add-v2 #add_5_rows {
    flex: 0 0 auto !important;
    margin: 0 !important;
    min-width: 170px !important;
    height: 44px !important;
    padding: 11px 18px !important;
    font-weight: 600 !important;
    letter-spacing: .02em !important;
    border-radius: var(--pos-radius-sm) !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
}

/* Row 2 — action toolbar: wider grid so labels breathe. */
body.mass-add-v2 #mass_add_action_buttons {
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    grid-auto-rows: 48px !important;
    gap: 10px !important;
    width: 100% !important;
    max-width: 720px !important;
    margin-top: 6px !important;
    padding: 0 !important;
}
/* Verify spans the full toolbar — it's a precondition for save, so it reads
   as a setup step, then the two save actions sit side-by-side underneath. */
body.mass-add-v2 #verify_all_categories {
    grid-column: 1 / -1 !important;
}
body.mass-add-v2 #mass_add_action_buttons .btn-block {
    border-radius: var(--pos-radius-sm) !important;
    font-weight: 600 !important;
    letter-spacing: .02em !important;
    height: 48px !important;
    min-height: 48px !important;
    padding: 0 16px !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    white-space: nowrap !important;
    word-break: normal !important;
    line-height: 1.2 !important;
    margin: 0 !important;
    width: 100% !important;
}
/* "Save & send to add purchase" — keep on a single line at the wider width. */
body.mass-add-v2 #save_and_send_to_purchase {
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

/* ===== Misc: code/kbd inside the bulk-entry helper text ===== */
body.mass-add-v2 code {
    background: var(--pos-surface-2) !important;
    color: var(--pos-ink) !important;
    border: 1px solid var(--pos-line) !important;
    border-radius: 4px !important;
    padding: 1px 6px !important;
    font-size: 12px !important;
}
body.mass-add-v2 kbd {
    background: var(--pos-ink) !important;
    color: var(--pos-brand-ink) !important;
    border-radius: 4px !important;
    padding: 1px 6px !important;
    font-size: 11px !important;
    box-shadow: 0 1px 0 rgba(0,0,0,.2);
}
</style>
