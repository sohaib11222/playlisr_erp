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

/* ===== Footer action block (moved OUT of <tfoot>).
        2026-05-06 v5 (Sarah): the two action rows now live in their own
        <div class="mass-add-footer-actions"> sibling under the table, so
        they size to the viewport instead of being clamped to one table
        column.

          ROW 1: [Add New Product Row] [Add 5 Product Rows] [Verify All Categories]
                 ← row-management actions, three equal buttons, no text clipping
          ROW 2: [    Save All Products    ] [  Save & send to add purchase  ]
                 ← primary save actions, two equal prominent buttons
        ===== */
body.mass-add-v2 .mass-add-footer-actions {
    margin-top: 16px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    width: 100%;
    max-width: 900px;
}

/* ROW 1 — three equal buttons. */
body.mass-add-v2 .mass-add-row-actions {
    display: grid !important;
    grid-template-columns: repeat(3, 1fr) !important;
    gap: 10px !important;
    width: 100% !important;
}
body.mass-add-v2 #add_row,
body.mass-add-v2 #add_5_rows,
body.mass-add-v2 #verify_all_categories {
    margin: 0 !important;
    min-height: 48px !important;
    height: auto !important;
    padding: 11px 14px !important;
    font-weight: 600 !important;
    font-size: 13.5px !important;
    letter-spacing: .02em !important;
    border-radius: var(--pos-radius-sm) !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 6px !important;
    /* Never clip — let labels wrap to a second line if the column is narrow. */
    white-space: normal !important;
    overflow: visible !important;
    text-overflow: clip !important;
    word-break: normal !important;
    line-height: 1.25 !important;
    width: 100% !important;
    box-sizing: border-box !important;
}

/* ROW 2 — two equal primary save buttons side-by-side, both prominent. */
body.mass-add-v2 .mass-add-footer-actions #mass_add_action_buttons {
    display: grid !important;
    grid-template-columns: 1fr 1fr !important;
    gap: 10px !important;
    width: 100% !important;
    margin-top: 0 !important;
    padding: 0 !important;
}
body.mass-add-v2 #save_all_products,
body.mass-add-v2 #save_and_send_to_purchase {
    margin: 0 !important;
    min-height: 56px !important;
    height: auto !important;
    padding: 14px 16px !important;
    font-weight: 700 !important;
    font-size: 14.5px !important;
    letter-spacing: .03em !important;
    border-radius: var(--pos-radius-sm) !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    white-space: normal !important;
    overflow: visible !important;
    text-overflow: clip !important;
    word-break: normal !important;
    line-height: 1.25 !important;
    width: 100% !important;
    box-sizing: border-box !important;
    transition: transform .1s ease, box-shadow .15s !important;
}
/* Both save buttons get the soft "primary action" glow. */
body.mass-add-v2 #save_all_products {
    box-shadow: 0 0 0 3px rgba(47,107,62,.18),
                0 2px 6px rgba(0,0,0,.06) !important;
}
body.mass-add-v2 #save_all_products:hover {
    box-shadow: 0 0 0 4px rgba(47,107,62,.28),
                0 4px 10px rgba(0,0,0,.08) !important;
    transform: translateY(-1px);
}
body.mass-add-v2 #save_and_send_to_purchase {
    box-shadow: 0 0 0 3px rgba(232,207,104,.30),
                0 2px 6px rgba(0,0,0,.06) !important;
}
body.mass-add-v2 #save_and_send_to_purchase:hover {
    box-shadow: 0 0 0 4px rgba(232,207,104,.45),
                0 4px 10px rgba(0,0,0,.08) !important;
    transform: translateY(-1px);
}

/* Stack to a single column on narrow screens to keep labels readable. */
@media (max-width: 720px) {
    body.mass-add-v2 .mass-add-row-actions,
    body.mass-add-v2 .mass-add-footer-actions #mass_add_action_buttons {
        grid-template-columns: 1fr !important;
    }
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
