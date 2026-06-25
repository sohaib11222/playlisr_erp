{{-- POS-create (pos-v2) reskin for the Add/Edit Purchase screens. Pure CSS
     overlay scoped to body.pos-v2 — form structure, element IDs and
     purchase.js behaviour are untouched. Shared by purchase/create + edit. --}}
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap" media="print" onload="this.media='all'">
<script>document.body.classList.add('pos-v2');</script>
<style>
body.pos-v2 {
  --pos-bg:#FAF6EE; --pos-surface:#FFFFFF; --pos-surface-2:#F7F1E3; --pos-ink:#1F1B16;
  --pos-line:#ECE3CF; --pos-line-2:#DFD2B3; --pos-accent:#FFF2B3; --pos-accent-deep:#E8CF68;
  --pos-accent-soft:#FFF9DB; --pos-accent-text:#5A4410;
  font-family:"Inter Tight",system-ui,sans-serif; color:var(--pos-ink);
}
body.pos-v2 .content-wrapper { background:var(--pos-bg); }
body.pos-v2 .content-header h1 { font-family:"Inter Tight",system-ui,sans-serif; font-weight:700; font-size:24px; color:var(--pos-ink); }
body.pos-v2 .content { font-family:"Inter Tight",system-ui,sans-serif; }

/* widget boxes -> cards */
body.pos-v2 .content .box { background:var(--pos-surface); border:1px solid var(--pos-line); border-radius:14px; box-shadow:0 1px 2px rgba(31,27,22,.05); }
body.pos-v2 .content .box > .box-body { padding:18px 20px; }

/* labels + inputs */
body.pos-v2 .content label, body.pos-v2 .content .control-label { font-size:12px; font-weight:600; color:#5a5145; }
body.pos-v2 .content .form-control { border:1px solid var(--pos-line-2); border-radius:9px; box-shadow:none; height:auto; padding:8px 10px; font-family:inherit; color:var(--pos-ink); }
body.pos-v2 .content .form-control:focus { border-color:var(--pos-accent-deep); box-shadow:0 0 0 3px var(--pos-accent-soft); }
body.pos-v2 .content .input-group-addon { background:var(--pos-surface-2); border:1px solid var(--pos-line-2); color:#8a8070; border-radius:9px 0 0 9px; }
body.pos-v2 .content .input-group .form-control { border-radius:0 9px 9px 0; }

/* select2 */
body.pos-v2 .select2-container--default .select2-selection--single { border:1px solid var(--pos-line-2); border-radius:9px; height:38px; }
body.pos-v2 .select2-container--default .select2-selection--single .select2-selection__rendered { line-height:36px; }
body.pos-v2 .select2-container--default .select2-selection--single .select2-selection__arrow { height:36px; }

/* buttons */
body.pos-v2 .content .btn-primary { background:var(--pos-accent); border:1px solid var(--pos-accent-deep); color:var(--pos-accent-text); border-radius:10px; font-weight:700; font-family:inherit; }
body.pos-v2 .content .btn-primary:hover, body.pos-v2 .content .btn-primary:focus { background:var(--pos-accent-deep); color:var(--pos-accent-text); }
body.pos-v2 .content .btn-link { color:var(--pos-accent-text); font-weight:600; }

/* product entry table — compact + tidy */
body.pos-v2 #purchase_entry_table thead th { background:var(--pos-surface-2); color:#5a5145; text-transform:uppercase; font-size:11px; letter-spacing:.04em; border-color:var(--pos-line)!important; vertical-align:middle; }
body.pos-v2 #purchase_entry_table > tbody > tr > td { border-color:var(--pos-line)!important; vertical-align:middle; padding:6px 8px; }
body.pos-v2 #purchase_entry_table .form-control.input-sm { height:34px; }

/* Quantity cell: keep qty + unit + sub-unit on ONE tidy line instead of
   sprawling vertically. The label/inputs are inlined and the row stays short. */
body.pos-v2 #purchase_entry_table td .input_quantity { width:70px !important; }
body.pos-v2 #purchase_entry_table td > div[style*="flex"] { flex-wrap:nowrap !important; gap:6px !important; align-items:center !important; }
body.pos-v2 #purchase_entry_table td .sub_unit { min-width:84px !important; }
</style>
