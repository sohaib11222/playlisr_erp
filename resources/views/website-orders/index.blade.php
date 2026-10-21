@extends('layouts.app')

@section('title', 'Orders')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

@include('events.partials._styles')

<style>
  .wo-tabs { display:flex; align-items:center; gap:4px; border-bottom:2px solid #e5e7eb; margin-bottom:20px; overflow-x:auto; flex-wrap:wrap; }
  .wo-dg-pick .wo-pick-line { display:block; padding:6px 8px; margin-bottom:5px; }
  .wo-dg-pick .wo-pick-name { font-size:12px; font-weight:600; margin-bottom:3px; }
  .wo-dg-pick .wo-pick-state { display:inline-block; font-size:11.5px; font-weight:700; padding:2px 8px; border-radius:999px; background:#fef3c7; color:#92400e; margin-bottom:5px; }
  .wo-dg-pick .is-done .wo-pick-state { background:#dcfce7; color:#047857; }
  .wo-dg-pick .is-missing .wo-pick-state { background:#fee2e2; color:#b91c1c; }
  .wo-dg-pick .wo-pick-btns { display:flex; gap:5px; flex-wrap:wrap; }
  .wo-dg-pick .wo-pick-btns button { flex:1 1 auto; font-size:12px; font-weight:700; padding:6px 8px; border:1.5px solid #111827; border-radius:6px; background:#fff; color:#000; }
  .wo-dg-pick .wo-pick-btns button.go { border-color:#047857; color:#047857; background:#fff; }
  .wo-prog-wrap { margin-top:8px; width:150px; }
  .wo-prog { display:flex; height:8px; border-radius:999px; background:#e5e7eb; overflow:hidden; }
  .wo-prog span { display:block; height:100%; }
  .wo-prog span.p { background:#047857; }
  .wo-prog span.c { background:#dc2626; }
  .wo-prog-sub { font-size:11.5px; font-weight:600; color:#000; }
  .wo-prog-sub .cf { color:#b91c1c; }
  .wo-prog-lbl { font-size:12px; font-weight:700; color:#000; margin-top:3px; }
  .wo-prog-wrap.full .wo-prog-lbl { color:#047857; }
  .wo-prog-wrap.part .wo-prog-lbl { color:#92400e; }
  .wo-cards { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:12px; margin-bottom:20px; }
  @media (max-width: 1100px) { .wo-cards { grid-template-columns:repeat(2, minmax(0,1fr)); } }
  .wo-card-group { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:10px 12px; }
  .wo-card-title { font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#000; margin:0 0 6px; }
  .wo-card-line { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:6px 8px; border-radius:7px; flex-wrap:wrap; }
  .wo-card-line.on { background:#eff6ff; }
  .wo-card-name { font-size:14px; font-weight:600; color:#000; text-decoration:none; white-space:nowrap; }
  .wo-card-line.on .wo-card-name { color:#1e40af; }
  .wo-card-pills { display:flex; gap:5px; flex-wrap:wrap; }
  .wo-split b { font-weight:700; margin-left:3px; }
  .wo-split.has b { color:#b91c1c; }
  .wo-tab { position:relative; padding:10px 12px; font-size:14px; font-weight:600; color:#000; text-decoration:none; white-space:nowrap; }
  .wo-tab:hover { color:#111827; }
  .wo-tab.active { color:#111827; }
  .wo-tab.active::after { content:""; position:absolute; left:0; right:0; bottom:-2px; height:3px; background:#111827; border-radius:2px 2px 0 0; }
  .wo-badge { display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 7px; border-radius:999px; font-size:12px; font-weight:700; color:#fff; background:#9ca3af; margin-left:8px; }
  .wo-badge.hot { background:#d97706; }
  /* Store pills on Ready to Ship / Need to Pull: light blue when selected (Sarah, 2026-10-20) */
  .wo-split { display:inline-flex; align-items:center; height:24px; padding:0 10px; border-radius:999px; font-size:12px; font-weight:700; color:#000; background:#fff; border:1px solid #d1d5db; text-decoration:none; }
  .wo-split:hover { border-color:#93c5fd; }
  .wo-split.on { background:#dbeafe; border-color:#93c5fd; color:#1e40af; }
  .wo-badge.overdue { background:#dc2626; }

  .wo-filters { display:flex; flex-wrap:wrap; gap:16px; align-items:flex-end; margin-bottom:18px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; }
  .wo-filters .wo-field { display:flex; flex-direction:column; gap:4px; }
  .wo-filters label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#000; }
  .wo-filters select, .wo-filters input[type=text], .wo-filters input[type=date] { font-size:13.5px; padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; background:#fff; }
  .wo-filters .wo-actions-inline { display:flex; gap:10px; align-items:center; }

  .wo-banner { margin-bottom:16px; padding:12px 14px; border-radius:8px; font-size:14px; }
  .wo-banner.warn { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
  .wo-banner.overdue { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
  .wo-banner.ok { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }

  .wo-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; box-shadow:0 1px 2px rgba(0,0,0,.04); }
  .wo-tbl { width:100%; min-width:1380px; table-layout:fixed; border-collapse:collapse; }
  .wo-scroll { overflow-x:auto; }
  .wo-tbl td { overflow-wrap:anywhere; }
  .wo-tbl td.wo-actions-cell { overflow:visible; }
  .wo-tbl thead th { text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#000; padding:10px 10px; border-bottom:1px solid #e5e7eb; background:#f9fafb; }
  .wo-tbl tbody tr.wo-row { border-bottom:1px solid #f0f0f0; }
  .wo-tbl tbody tr.wo-row:hover { background:#fafafa; }
  .wo-tbl td { padding:12px 10px; font-size:14px; vertical-align:top; color:#000; }

  .wo-buyer-name { font-size:14.5px; font-weight:600; color:#111827; }
  .wo-prod-with-img { display:flex; gap:10px; align-items:center; }
  .wo-prod-img { width:64px; height:64px; border-radius:8px; object-fit:cover; border:1px solid #e5e7eb; background:#f3f4f6; flex-shrink:0; display:block; }
  .wo-listed { font-size:12.5px; color:#000; line-height:1.35; min-width:96px; }
  .wo-listed .t { display:block; color:#000; white-space:nowrap; }
  .wo-sort { color:inherit; text-decoration:none; white-space:nowrap; }
  .wo-sort:hover { text-decoration:underline; }
  .wo-sort-arrow { font-size:10px; margin-left:3px; color:#000; }
  .wo-buyer-email { font-size:12.5px; color:#000; margin-top:2px; }

  .wo-pill { display:inline-flex; align-items:center; gap:5px; padding:4px 10px; border-radius:999px; font-size:12.5px; font-weight:600; white-space:nowrap; }
  .wo-pill.st-processing { background:#fef9c3; color:#854d0e; }
  .wo-pill.st-ready_for_pickup { background:#fef3c7; color:#92400e; }
  .wo-pill.st-picked_up { background:#d1fae5; color:#065f46; }
  .wo-pill.st-shipped { background:#dbeafe; color:#1e40af; }
  .wo-pill.st-email_sent { background:#dcfce7; color:#166534; }
  .wo-pill.st-delivered { background:#dcfce7; color:#166534; }
  .wo-pill.st-cancelled { background:#fee2e2; color:#991b1b; }
  .wo-pill.st-flag { background:#fee2e2; color:#991b1b; }
  .wo-pill.st-default { background:#f3f4f6; color:#000; }

  .wo-fm-pill { display:inline-block; padding:3px 9px; border-radius:8px; font-size:12px; font-weight:600; margin:0 0 4px; }
  .wo-item-chip { display:inline-block; margin-top:4px; padding:2px 8px; border-radius:6px; font-size:12px; font-weight:700; }
  .wo-item-chip.ok { background:#d1fae5; color:#065f46; }
  .wo-item-chip.sub { background:#dbeafe; color:#1e40af; }
  .wo-item-chip.cf { background:#fee2e2; color:#991b1b; }
  .wo-item-chip.no { background:#f3f4f6; color:#000; }
  .wo-fm-pill.shipping { background:#e0f2fe; color:#0369a1; }
  .wo-fm-pill.pickup { background:#f3e8ff; color:#6d28d9; }
  .wo-fm-pill.digital { background:#dcfce7; color:#166534; }
  .wo-fm-pill.both-sep { background:#fef3c7; color:#92400e; margin-top:4px; }
  .wo-fm-pill.both-comb { background:#ffedd5; color:#9a3412; margin-top:4px; }
  .wo-shipfrom { font-size:11px; color:#000; margin-top:3px; }

  .wo-prod-line { font-size:13.5px; color:#111827; line-height:1.35; margin-bottom:6px; word-break:break-word; }
  .wo-prod-line:last-child { margin-bottom:0; }
  .wo-prod-qty { margin-left:4px; font-size:12px; font-weight:700; color:#000; }
  .wo-prod-artist { font-size:12px; color:#000; }
  .wo-prod-more { font-size:12px; color:#000; margin-top:4px; }

  .wo-total { font-weight:600; color:#000; }
  .wo-loc .place { display:block; font-weight:700; font-size:14px; color:#000; }
  .wo-loc .area { display:block; font-size:13px; color:#000; }
  .wo-loc .binv { display:block; width:fit-content; max-width:220px; margin-top:2px; padding:2px 7px; border-radius:5px; background:#fde68a; color:#000; font-size:13.5px; font-weight:700; }
  .wo-loc { font-size:12.5px; line-height:1.35; min-width:110px; }
  .wo-loc-line { margin-bottom:4px; box-sizing:border-box; }
  .wo-loc .st { font-weight:600; color:#111827; white-space:nowrap; }
  .wo-loc .q { font-weight:400; color:#000; }
  .wo-genre .genre { display:block; margin:0 0 4px; padding:2px 7px; border-radius:5px; background:#eef2ff; color:#000; font-size:13.5px; font-weight:700; width:fit-content; }
  .wo-loc .kallax { display:block; width:fit-content; max-width:220px; margin-bottom:2px; padding:2px 7px; border-radius:5px; background:#fef3c7; color:#000; font-family:monospace; font-size:13.5px; font-weight:700; }
  .wo-loc .zone { display:block; color:#000; font-size:12px; margin-bottom:3px; }
  .wo-loc .bin { display:block; color:#000; font-family:monospace; font-size:12px; }
  .wo-loc .none { color:#b91c1c; font-weight:600; }
  .wo-loc .na { color:#000; }
  .wo-placed { font-size:12.5px; color:#000; line-height:1.35; }
  .wo-placed .t { display:block; color:#000; }
  .wo-pill, .wo-fm-pill { white-space:normal !important; }
  .wo-tag { display:inline-block; margin-left:4px; padding:0 5px; border-radius:4px; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; vertical-align:1px; }
  .wo-tag.media { color:#9a3412; background:#ffedd5; border:1px solid #fed7aa; }
  .wo-tag.fmt { color:#000; border:1px solid #e5e7eb; font-weight:600; }
  .wo-prod-price { font-size:12px; color:#000; }
  .wo-due { display:block; margin-top:4px; font-size:12px; font-weight:700; color:#166534; }
  .wo-due.warn { color:#92400e; }
  .wo-due.over { color:#b91c1c; }
  .wo-sla { display:inline-block; margin-top:4px; padding:1px 7px; border-radius:5px; font-size:10.5px; font-weight:700; }
  .wo-sla.ok { color:#166534; background:#f0fdf4; border:1px solid #bbf7d0; }
  .wo-sla.warn { color:#92400e; background:#fef3c7; border:1px solid #fde68a; }
  .wo-sla.over { color:#b91c1c; background:#fee2e2; border:1px solid #fecaca; }
  .wo-sla.pre { color:#9a3412; background:#fff7ed; border:1px solid #fed7aa; font-weight:600; }

  .wo-route-btn { align-self:center; padding:10px 18px; border-radius:8px; background:#1e40af; color:#fff !important; font-weight:700; font-size:15px; text-decoration:none; white-space:nowrap; }
  .ev-head { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; }
  .wo-onote { margin-top:4px; }
  .wo-onote-show { background:#fef9c3; border:1px solid #fde68a; border-radius:6px; padding:6px 8px; margin-bottom:5px; }
  .wo-onote-show .txt { font-size:13px; color:#000; white-space:pre-wrap; font-weight:600; }
  .wo-onote-show .by { font-size:11px; color:#000; margin-top:2px; }
  .wo-onote-open { font-size:11px; padding:1px 6px; border:none; background:none; color:#1e40af; text-decoration:underline; cursor:pointer; }
  .wo-onote-edit textarea { width:100%; font-size:13px; padding:6px; border:1px solid #d1d5db; border-radius:6px; margin-bottom:5px; color:#000; }
  .wo-row-actions { display:flex; flex-direction:row; flex-wrap:wrap; align-items:center; gap:5px; position:relative; }
  .wo-row-actions .wo-btn-primary { padding:0 9px; }
  .wo-row-actions form { margin:0; }
  .wo-row-actions .wo-btn { display:inline-flex; align-items:center; justify-content:center; width:auto; height:26px; padding:0 8px; font-size:12px; }
  .wo-more { position:relative; }
  .wo-more-btn { width:30px !important; padding:0 !important; }
  .wo-more-btn svg { width:16px; height:16px; fill:#374151; }
  .wo-more-menu { display:none; position:fixed; z-index:1000; min-width:150px; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,.12); padding:4px; }
  .wo-more.open .wo-more-menu { display:block; }
  .wo-more-menu form { margin:0; }
  .wo-more-item { display:block; width:100%; text-align:left; background:none; border:none; padding:8px 10px; font-size:13px; color:#000; border-radius:6px; cursor:pointer; }
  .wo-more-item:hover { background:#f3f4f6; }
  .wo-more-item.danger { color:#b91c1c; }
  .wo-more-item.danger:hover { background:#fef2f2; }
  .wo-row > td { padding-top:4px; padding-bottom:4px; vertical-align:middle; }
  .wo-row .wo-prod-line { margin-bottom:4px; }
  .wo-prod-name { color:#111827; text-decoration:none; }
  .wo-prod-name:hover { color:#4a6fa5; text-decoration:underline; }
  .wo-noerp { display:inline-block; margin-left:5px; padding:0 6px; border-radius:999px; background:#f3f4f6; color:#000; font-size:10.5px; font-weight:600; vertical-align:1px; white-space:nowrap; }
  .wo-btn { display:block; width:100%; box-sizing:border-box; font-size:13px; padding:7px 12px; border-radius:7px; border:1px solid #d1d5db; background:#fff; cursor:pointer; font-weight:500; color:#000; text-align:center; }
  .wo-btn:hover { background:#f9fafb; }
  .wo-btn-primary { background:#111827; color:#fff; border-color:#111827; }
  .wo-btn-primary:hover { background:#1f2937; }
  .wo-btn-danger { color:#b91c1c; border-color:#fecaca; }
  .wo-btn-danger:hover { background:#fef2f2; }
  /* Cancel box buttons: solid and obvious (Sarah, 2026-10-16) */
  #wo-cancel-form .wo-dialog-actions .wo-btn { font-size:15px; font-weight:700; padding:11px 14px; }
  #wo-cancel-form .wo-dialog-actions .wo-btn:not(.wo-btn-danger) { background:#111827; border-color:#111827; color:#fff; }
  #wo-cancel-form .wo-dialog-actions .wo-btn-danger { background:#dc2626; border-color:#dc2626; color:#fff; }
  #wo-cancel-form .wo-dialog-actions .wo-btn-danger:disabled { background:#fecaca; border-color:#fecaca; color:#7f1d1d; cursor:not-allowed; }

  .wo-dialog { border:none; border-radius:12px; padding:0; max-width:420px; width:calc(100vw - 32px); box-shadow:0 10px 40px rgba(0,0,0,.2); }
  .wo-dialog::backdrop { background:rgba(17,24,39,.45); }
  .wo-dialog-body { padding:22px; }
  .wo-dialog-body h3 { margin:0 0 14px; font-size:16px; font-weight:700; color:#111827; }
  .wo-dialog-body label { display:block; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#000; margin:12px 0 5px; }
  .wo-dialog-body label:first-of-type { margin-top:0; }
  .wo-dialog-body select, .wo-dialog-body input[type=text] { width:100%; font-size:14px; padding:9px 10px; border:1px solid #d1d5db; border-radius:8px; box-sizing:border-box; }
  .wo-dialog-checkline { display:flex; align-items:center; gap:7px; font-size:13px; font-weight:400; text-transform:none; letter-spacing:0; color:#000; margin-top:14px; }
  .wo-dialog-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:20px; }

  .wo-detail td { background:#f9fafb; padding:0 14px 18px; }
  .wo-detail-grid { display:flex; flex-wrap:wrap; gap:28px; padding:14px 4px; font-size:13px; }
  .wo-detail-grid h5 { font-size:12.5px; font-weight:700; margin:0 0 8px; color:#000; text-transform:uppercase; letter-spacing:.03em; }
  .wo-detail-items { width:100%; border-collapse:collapse; margin-top:6px; }
  .wo-detail-items td, .wo-detail-items th { padding:5px 10px 5px 0; font-size:13px; text-align:left; }
  .wo-detail-items th { color:#000; font-weight:700; font-size:11px; text-transform:uppercase; }
  /* Pick list (Sarah, 2026-10-15) */
  /* Pick buttons beside each item in Location (Sarah, 2026-10-19): one row, equal, solid */
  .wo-pick-inline { display:block !important; border:none !important; padding:0 !important; margin:6px 0 0; background:none !important; }
  /* Status chip so picked vs not picked is obvious at a glance (Sarah, 2026-10-20) */
  .wo-pick-inline .wo-pick-state { display:inline-block; font-size:12px; font-weight:700; margin:0 0 6px; padding:3px 9px; border-radius:999px; background:#fef3c7; color:#92400e; }
  .wo-pick-inline.is-done .wo-pick-state { background:#dcfce7; color:#047857; }
  .wo-pick-inline.is-missing .wo-pick-state { background:#fee2e2; color:#b91c1c; }
  .wo-pick-inline.is-done { background:#f0fdf4 !important; border-radius:6px; padding:4px 6px !important; }
  .wo-pick-inline .wo-pick-btns { display:flex; gap:4px; flex-wrap:nowrap; grid-column:auto; grid-row:auto; }
  .wo-pick-inline .wo-pick-btns button { flex:1 1 auto; min-width:0; font-size:12.5px; font-weight:700; padding:8px 10px; border-radius:6px; white-space:nowrap; border:1.5px solid #111827; background:#fff; color:#000; cursor:pointer; }
  .wo-pick-inline .wo-pick-btns { flex-wrap:wrap; }
  .wo-pick-inline .wo-pick-btns button.go { background:#fff; border-color:#047857; color:#047857; }
  .wo-pick-inline .wo-pick-btns button.go:hover { background:#047857; color:#fff; }
  .wo-pick-inline .wo-pick-btns button.cf { background:#fff; border-color:#b91c1c; color:#b91c1c; }
  .wo-pick-inline .wo-pick-btns button.ds { background:#fff; border-color:#1e40af; color:#1e40af; }
  .wo-pick-inline .wo-pick-btns button.undo { flex:0 0 auto; font-size:11px; font-weight:600; padding:2px 10px; border-color:#9ca3af; color:#374151; }
  /* Compact pick checklist: one line per item, buttons on the right (Sarah, 2026-10-16) */
  .wo-pick { margin-top:4px; text-align:left; }
  .wo-pick-line { display:grid; grid-template-columns:minmax(0,1fr) auto; column-gap:6px; align-items:center; border:1px solid #e5e7eb; border-radius:5px; padding:3px 6px; margin-bottom:3px; background:#fff; }
  .wo-pick-line.is-done { background:#ecfdf5; border-color:#a7f3d0; }
  .wo-pick-line.is-missing { background:#fef2f2; border-color:#fecaca; }
  .wo-pick-name { grid-column:1; font-size:12px; font-weight:600; color:#000; line-height:1.25; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
  .wo-pick-state { grid-column:1; font-size:10.5px; color:#000; margin:0; line-height:1.2; }
  .wo-pick-btns { grid-column:2; grid-row:1 / span 2; display:flex; gap:3px; }
  .wo-pick-btns button, .wo-pick-all { font-size:11px; padding:2px 7px; border:1px solid #d1d5db; border-radius:5px; background:#fff; color:#000; cursor:pointer; white-space:nowrap; }
  .wo-pick-btns button.go, .wo-pick-all { background:#dbeafe; border-color:#93c5fd; color:#1e40af; font-weight:600; }
  .wo-pick-all { margin-top:2px; }
  .wo-pick-btns button:disabled, .wo-pick-all:disabled { opacity:.5; cursor:wait; }
  /* Use the full screen width on this page */
  body.pos-v2 .ev-wrap { max-width:none !important; padding:10px 14px 40px !important; }
  /* Compact (Sarah, 2026-10-20: "less white space on this page") */
  .wo-tbl td { padding:7px 8px !important; vertical-align:top !important; }
  .wo-tbl thead th { padding:7px 8px !important; }
  .wo-prod-img { width:52px; height:52px; }
  .wo-row .wo-prod-line, .wo-prod-line { margin-bottom:2px; line-height:1.25; }
  .wo-filters { margin-bottom:10px; padding:8px 12px; gap:10px; }
  .wo-filters select, .wo-filters input[type=text], .wo-filters input[type=date] { padding:6px 8px; }
  .wo-banner { margin-bottom:10px; padding:7px 12px; font-size:13px; }
  .wo-cards { margin-bottom:10px; gap:8px; }
  .wo-card-group { padding:7px 10px; }
  .wo-card-line { padding:3px 6px; }
  .wo-loc-line { margin-bottom:2px; }
  .wo-pick-inline { margin-top:3px; }
  .wo-pick-inline .wo-pick-state { margin-bottom:3px; }
  /* One row of buttons under the bin, not stacked (Sarah, 2026-10-21) */
  .wo-pick-inline .wo-pick-btns { flex-wrap:nowrap !important; gap:4px; }
  .wo-pick-inline .wo-pick-btns button { flex:0 0 auto !important; padding:4px 8px; font-size:12px; line-height:1.2; }
  /* Priority work list (Sarah, 2026-10-21) */
  .wo-steps { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px 14px; margin-bottom:16px; }
  .wo-steps-head { font-size:13px; font-weight:800; text-transform:uppercase; letter-spacing:.03em; color:#000; margin-bottom:8px; }
  .wo-step { display:flex; align-items:center; gap:12px; padding:9px 10px; border-radius:8px; border:1px solid transparent; flex-wrap:wrap; }
  .wo-step + .wo-step { margin-top:4px; }
  .wo-step.on { background:#eff6ff; border-color:#bfdbfe; }
  .wo-step-num { flex:0 0 30px; height:30px; border-radius:50%; background:#1e40af; color:#fff; font-weight:800; font-size:15px; display:flex; align-items:center; justify-content:center; }
  .wo-step.clear .wo-step-num { background:#047857; }
  .wo-step-main { flex:0 1 auto; min-width:0; max-width:520px; text-decoration:none; color:#000; }
  /* Store counts sit right next to the step, big enough to tap (Sarah, 2026-10-21) */
  .wo-step .wo-card-pills { display:flex; flex-wrap:wrap; gap:8px; }
  .wo-step .wo-split { height:36px; padding:0 16px; font-size:15px; border-width:1.5px; }
  .wo-step .wo-split b { font-size:16px; margin-left:6px; }
  /* No green text anywhere on the work list (Sarah, 2026-10-21) */
  .wo-steps a, .wo-steps .wo-split, .wo-steps .wo-more { color:#000 !important; }
  /* Counts with work: white number in a green circle (Sarah, 2026-10-21) */
  .wo-steps .wo-split.has b { display:inline-flex; align-items:center; justify-content:center; min-width:26px; height:26px; padding:0 6px; border-radius:999px; background:#047857; color:#fff !important; box-sizing:border-box; }
  .wo-steps .wo-split.on, .wo-steps .wo-more.on { color:#1e40af !important; }
  .wo-steps .wo-more { height:36px; padding:0 16px; font-size:15px; font-weight:700; border-width:1.5px; }
  .wo-steps-more { font-size:15px; }
  .wo-step.clear .wo-step-num { background:#9ca3af; }
  .wo-step-done { background:#bbf7d0 !important; color:#000 !important; }
  /* Sealed vs Used, impossible to miss (Sarah, 2026-10-21) */
  .wo-cond { display:inline-block; margin:0 4px 0 2px; padding:3px 10px; border-radius:6px; font-size:13px; font-weight:800; letter-spacing:.04em; color:#fff; vertical-align:1px; }
  .wo-cond.sealed { background:#1e40af; }
  .wo-cond.used { background:#c2410c; }
  .wo-step-title { font-size:16px; font-weight:800; color:#000; }
  .wo-step-why { display:block; font-size:13px; color:#000; margin-top:2px; }
  .wo-step-start { display:inline-block; margin-left:8px; padding:2px 9px; border-radius:999px; background:#1e40af; color:#fff; font-size:12px; font-weight:800; vertical-align:2px; }
  .wo-step-done { display:inline-block; margin-left:8px; padding:2px 9px; border-radius:999px; background:#dcfce7; color:#047857; font-size:12px; font-weight:800; vertical-align:2px; }
  .wo-steps-more { display:flex; flex-wrap:wrap; gap:8px 22px; margin-top:12px; padding-top:10px; border-top:1px solid #e5e7eb; font-size:13px; }
  .wo-more-group { display:inline-flex; flex-wrap:wrap; align-items:center; gap:6px; }
  .wo-more-label { font-weight:700; color:#000; }
  .wo-more { display:inline-flex; align-items:center; height:26px; padding:0 10px; border-radius:999px; border:1px solid #d1d5db; background:#fff; color:#000; font-weight:600; text-decoration:none; }
  .wo-more:hover { border-color:#93c5fd; }
  .wo-more.on { background:#dbeafe; border-color:#93c5fd; color:#1e40af; }
</style>

<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>Orders</h1>
      <p class="sub">Website and Discogs orders to pull, pack and ship.</p>
    </div>
    <a href="{{ route('website-orders.pickRoute', ['store' => in_array($locationFilter, ['hollywood', 'pico', 'warehouse'], true) ? $locationFilter : ($locationFilter === 'hw' ? 'hollywood' : 'hollywood')]) }}" class="wo-route-btn">Pick Route &rarr;</a>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  @if($bridgeError)
    <div class="ev-card" style="border:1px solid #f0c2c2;">
      <h2 style="margin-top:0;">Couldn't reach the website</h2>
      <p class="sub" style="margin:0;">{{ $bridgeErrorMessage }}</p>
    </div>
  @else
    @php
      // Simplified tabs (Sarah, 2026-10-19). Ready to Ship and Need to Pull
      // are split HW / Pico; old tab links (to_ship, pickup, ...) still work.
      // Rows by job (Sarah, 2026-10-20): shipments, pickups, refunds, done.
      // Store pills count items; old tab links (needs_action, ...) still work.
      $tabRows = [
        'Shipments' => [
          'to_ship'    => ['label' => 'Need to Pull', 'hot' => true, 'split' => ['warehouse' => 'HW Warehouse', 'hollywood' => 'HW Store', 'pico' => 'Pico'], 'items' => true],
          'ready_ship' => ['label' => 'Ready to Ship', 'split' => ['hw' => 'HW', 'pico' => 'Pico']],
        ],
        'Pickups' => [
          'pickup'       => ['label' => 'Need to Pull', 'hot' => true, 'split' => ['hw' => 'HW', 'pico' => 'Pico'], 'items' => true],
          'ready_pickup' => ['label' => 'Ready for Pickup', 'split' => ['hollywood' => 'HW', 'pico' => 'Pico']],
        ],
        'Refunds' => [
          'recheck_hw' => ['label' => 'Recheck in HW Bins', 'hot' => true],
          'cant_find'  => ['label' => "Can't Find: Decide Refund", 'hot' => true],
          'refunded'   => ['label' => 'Refunded', 'nocount' => true],
        ],
        'Other' => [
          'preorders' => ['label' => 'Preorders'],
          'completed' => ['label' => 'Completed'],
        ],
      ];
      $tabDefs = array_merge(...array_values($tabRows));
      if (!isset($tabDefs[$activeTab])) $tabDefs[$activeTab] = ['label' => $activeTab === 'archived' ? 'Archived' : (\App\Http\Controllers\WebsiteOrdersController::TABS[$activeTab]['label'] ?? $activeTab)];
      $baseQuery = array_filter([
        'status' => $statusFilter,
        'payment_status' => $paymentStatusFilter,
        'q' => $search,
        'from' => $dateFrom,
        'to' => $dateTo,
        'location' => $locationFilter,
      ], fn($v) => $v !== '' && $v !== null);
    @endphp

    @if(!isset(array_merge(...array_values($tabRows))[$activeTab]))
      @php $tabRows['Other'][$activeTab] = $tabDefs[$activeTab]; @endphp
    @endif
    {{-- Work list in priority order (Sarah, 2026-10-21: "ppl dont know what to
         prioritize"). Numbered steps, top to bottom; the first step with work
         says Start here. Waiting / manager / done views sit below, smaller. --}}
    @php
      $woSteps = [
        ['tab' => 'pickup', 'title' => 'Pull pickup orders', 'why' => 'The customer is coming in for these.', 'split' => ['warehouse' => 'HW Warehouse', 'hollywood' => 'HW Store', 'pico' => 'Pico']],
        ['tab' => 'ready_ship', 'title' => 'Pack and ship', 'why' => 'Everything is pulled. Pack it and print the label.', 'split' => ['warehouse' => 'HW Warehouse', 'hollywood' => 'HW Store', 'pico' => 'Pico']],
        ['tab' => 'to_ship', 'title' => 'Pull shipping orders', 'why' => 'Find each item, tap Mark picked or Can\'t find. Discogs orders are here too.', 'split' => ['warehouse' => 'HW Warehouse', 'hollywood' => 'HW Store', 'pico' => 'Pico']],
        ['tab' => 'recheck_hw', 'title' => 'Recheck in Hollywood bins', 'why' => 'Pico couldn\'t find these. Hollywood checks its store and warehouse before anyone refunds.'],
        ['tab' => 'cant_find', 'title' => 'Managers: refund or substitute', 'why' => 'Nobody could find these. A manager decides: refund the item or send a substitute.'],
      ];
      $woStepCount = function ($st) use ($tabCounts) {
        if (in_array($st['tab'], ['ready_ship', 'recheck_hw'], true)) return (int) ($tabCounts[$st['tab']] ?? 0);
        if (empty($st['split'])) return (int) ($tabCounts[$st['tab']] ?? 0);
        $n = 0;
        foreach (array_keys($st['split']) as $lk) $n += (int) ($tabCounts[$st['tab'] . '_' . $lk] ?? 0);
        return $n;
      };
      $woStartAt = null;
      foreach ($woSteps as $i => $st) if ($woStartAt === null && $woStepCount($st) > 0) $woStartAt = $i;
    @endphp
    {{-- Searching or one order: the store work list and its counts aren't
         about that order, so hide them (Sarah, 2026-10-21: Apurva's shipped
         order looked like Pico Ready to Ship). --}}
    @php $woLookup = !empty($searchAll) || request()->filled('order'); @endphp
    @if($woLookup)
      <div class="wo-banner" style="background:#eff6ff;border:1px solid #bfdbfe;color:#000;">{{ request()->filled('order') ? 'One order.' : 'Search results only.' }} <a href="{{ route('website-orders.index') }}">Back to the work list</a></div>
    @else
    <div class="wo-steps">
      <div class="wo-steps-head">Do these in order, top to bottom</div>
      @foreach($woSteps as $i => $st)
        @php $n = $woStepCount($st); @endphp
        <div class="wo-step {{ $activeTab === $st['tab'] ? 'on' : '' }} {{ $n === 0 ? 'clear' : '' }}">
          <div class="wo-step-num">{{ $i + 1 }}</div>
          <a class="wo-step-main" href="{{ route('website-orders.index', array_merge($baseQuery, ['tab' => $st['tab'], 'location' => null])) }}">
            <span class="wo-step-title">{{ $st['title'] }}</span>
            @if($woStartAt === $i)<span class="wo-step-start">Start here</span>@endif
            @if($n === 0)<span class="wo-step-done">All done</span>@endif
            <span class="wo-step-why">{{ $st['why'] }}</span>
          </a>
          <div class="wo-card-pills">
            @if(!empty($st['split']))
              @foreach($st['split'] as $lk => $ll)
                @php $sn = $tabCounts[$st['tab'] . '_' . $lk] ?? 0; @endphp
                {{-- No 0 buttons: a step with nothing just says All done (Sarah, 2026-10-21) --}}
                @continue($sn === 0 && !($activeTab === $st['tab'] && $locationFilter === $lk))
                <a href="{{ route('website-orders.index', array_merge($baseQuery, ['tab' => $st['tab'], 'location' => $lk])) }}" class="wo-split {{ $activeTab === $st['tab'] && $locationFilter === $lk ? 'on' : '' }} {{ $sn > 0 ? 'has' : '' }}">{{ $ll }} <b>{{ $sn }}</b></a>
              @endforeach
            @elseif($n > 0)
              <a href="{{ route('website-orders.index', array_merge($baseQuery, ['tab' => $st['tab'], 'location' => null])) }}" class="wo-split {{ $activeTab === $st['tab'] ? 'on' : '' }} {{ $n > 0 ? 'has' : '' }}">{{ $st['tab'] === 'recheck_hw' ? 'Hollywood' : 'Orders' }} <b>{{ $n }}</b></a>
            @endif
          </div>
        </div>
      @endforeach
      <div class="wo-steps-more">
        <span class="wo-more-group"><span class="wo-more-label">Waiting on the customer:</span>
          <a href="{{ route('website-orders.index', array_merge($baseQuery, ['tab' => 'ready_pickup', 'location' => null])) }}" class="wo-more {{ $activeTab === 'ready_pickup' ? 'on' : '' }}">Ready for Pickup ({{ $tabCounts['ready_pickup'] ?? 0 }})</a>
          <a href="{{ route('website-orders.index', array_merge($baseQuery, ['tab' => 'preorders', 'location' => null])) }}" class="wo-more {{ in_array($activeTab, ['preorders', 'preorder_ship', 'preorder_pickup'], true) ? 'on' : '' }}">Preorders ({{ $tabCounts['preorders'] ?? 0 }})</a>
        </span>
        <span class="wo-more-group"><span class="wo-more-label">Done:</span>
          <a href="{{ route('website-orders.index', array_merge($baseQuery, ['tab' => 'completed', 'location' => null])) }}" class="wo-more {{ $activeTab === 'completed' ? 'on' : '' }}">Completed</a>
          <a href="{{ route('website-orders.index', array_merge($baseQuery, ['tab' => 'refunded', 'location' => null])) }}" class="wo-more {{ $activeTab === 'refunded' ? 'on' : '' }}">Refunded</a>
          @if(in_array($activeTab, ['archived', 'unsorted', 'needs_action'], true))
            <a href="{{ route('website-orders.index', array_merge($baseQuery, ['tab' => $activeTab])) }}" class="wo-more on">{{ $tabDefs[$activeTab]['label'] ?? ucfirst($activeTab) }}</a>
          @endif
        </span>
      </div>
    </div>
    @endif
    @if(!$woLookup && $activeTab === 'needs_action' && ($tabCounts['needs_action'] ?? 0) === 0)
      <div class="wo-banner ok">No orders need action right now. All caught up.</div>
    @endif

    @if(!$woLookup && $activeTab === 'pickup')
      @if(($tabCounts['pickup_overdue'] ?? 0) > 0)
        <div class="wo-banner overdue"><strong>{{ $tabCounts['pickup_overdue'] }} overdue</strong> — waiting more than {{ $pickupSlaOverdueHours }} hours. Pull these as soon as possible.</div>
      @else
        <div class="wo-banner warn">In-store pickup orders should be pulled within 24&ndash;48 hours of the order being placed.</div>
      @endif
    @endif

    @if(!empty($summaryFilter))
      <div class="wo-banner warn">Showing the orders from the #shipping summary link only. <a href="{{ route('website-orders.index', ['tab' => $activeTab]) }}">Show all</a></div>
    @endif
    @if(!$woLookup && $activeTab === 'recheck_hw')
      <style>.wo-prod-img { width:84px !important; height:84px !important; }</style>
      <div class="wo-banner warn">Pico couldn't find these. Check the Hollywood spots listed for each one, then tap Found it or Not here either.</div>
    @endif
    @if(!$woLookup && $activeTab === 'to_ship' && ($tabCounts['to_ship'] ?? 0) > 0)
      <div class="wo-banner warn">These shipping orders still have items to pull. Tap Mark picked or Can't find on each item.</div>
    @endif

    @if($activeTab === 'preorder_ship')
      <div class="wo-banner warn">These orders include at least one item that isn't in stock yet. Nothing to ship until the street date hits, check back once the item arrives.</div>
    @endif

    @if($activeTab === 'preorder_pickup')
      <div class="wo-banner warn">These orders include at least one item that isn't in stock yet. Not shipped - the customer collects in store, sometimes later than the street date (e.g. picking up at an event).</div>
    @endif

    @if(($unknownLocationCount ?? 0) > 0)
      <div class="wo-banner overdue"><strong>{{ $unknownLocationCount }} pickup {{ $unknownLocationCount === 1 ? 'order has' : 'orders have' }} no pickup location set.</strong></div>
    @endif
    @if(in_array($activeTab, ['preorder_ship', 'preorder_pickup', 'preorders'], true) && !empty($preorderSummary))
      <div class="wo-card" style="margin-bottom:16px;border-color:#fed7aa;">
        <div style="padding:8px 12px;background:#ffedd5;color:#9a3412;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;">Total preorders per album (ship + pickup combined)</div>
        <table style="width:100%;border-collapse:collapse;font-size:13.5px;">
          @foreach($preorderSummary as $al)
            <tr style="border-top:1px solid #ffedd5;">
              <td style="padding:7px 12px;">
                @if(!empty($al['erp_id']))<a class="wo-prod-name" href="{{ url('/products/' . $al['erp_id'] . '/edit') }}" target="_blank" rel="noopener">{{ $al['name'] }}</a>@else{{ $al['name'] }}@endif
                @if($al['media'])<span class="wo-tag media">{{ $al['media'] }}</span>@endif
                @if($al['format'])<span class="wo-tag fmt">{{ $al['format'] }}</span>@endif
                @if($al['sku'] || $al['price'] !== null)
                  <div style="font-size:11px;color:#000;">@if($al['sku'])SKU {{ $al['sku'] }}@endif @if($al['sku'] && $al['price'] !== null) &middot; @endif @if($al['price'] !== null)${{ number_format((float) $al['price'], 2) }}@endif</div>
                @endif
              </td>
              <td style="padding:7px 12px;color:#000;white-space:nowrap;">street date {{ $al['street_date'] ? gmdate('M d, Y', strtotime($al['street_date'])) : 'TBD' }}</td>
              <td style="padding:7px 12px;text-align:right;font-weight:700;color:#c2410c;white-space:nowrap;">{{ $al['quantity'] }} preordered</td>
            </tr>
          @endforeach
        </table>
      </div>
    @endif
    <form method="GET" action="{{ route('website-orders.index') }}" class="wo-filters">
      <input type="hidden" name="tab" value="{{ $activeTab }}">
      <div class="wo-field">
        <label>Payment</label>
        <select name="payment_status" onchange="this.form.submit()">
          <option value="completed" {{ $paymentStatusFilter === 'completed' ? 'selected' : '' }}>Completed</option>
          <option value="all" {{ $paymentStatusFilter === 'all' ? 'selected' : '' }}>All Payment Status</option>
          <option value="pending" {{ $paymentStatusFilter === 'pending' ? 'selected' : '' }}>Pending</option>
          <option value="failed" {{ $paymentStatusFilter === 'failed' ? 'selected' : '' }}>Failed</option>
          <option value="cancelled" {{ $paymentStatusFilter === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
          <option value="refunded" {{ $paymentStatusFilter === 'refunded' ? 'selected' : '' }}>Refunded</option>
        </select>
      </div>
      <div class="wo-field">
        <label>Order status</label>
        <select name="status" onchange="this.form.submit()">
          <option value="">All Order Statuses</option>
          @foreach($statuses as $key => $label)
            <option value="{{ $key }}" {{ $statusFilter === $key ? 'selected' : '' }}>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="wo-field">
        <label>Store</label>
        <select name="location" onchange="this.form.submit()">
          <option value="">All Stores</option>
          <option value="hw" {{ $locationFilter === 'hw' ? 'selected' : '' }}>HW (store + warehouse)</option>
          <option value="hollywood" {{ $locationFilter === 'hollywood' ? 'selected' : '' }}>Hollywood ({{ $pullCounts['hollywood'] ?? 0 }} to pull)</option>
          <option value="pico" {{ $locationFilter === 'pico' ? 'selected' : '' }}>Pico ({{ $pullCounts['pico'] ?? 0 }} to pull)</option>
          <option value="warehouse" {{ $locationFilter === 'warehouse' ? 'selected' : '' }}>Warehouse ({{ $pullCounts['warehouse'] ?? 0 }} to pull)</option>
        </select>
      </div>
      <div class="wo-field">
        <label>From</label>
        <input type="date" name="from" value="{{ $dateFrom }}">
      </div>
      <div class="wo-field">
        <label>To</label>
        <input type="date" name="to" value="{{ $dateTo }}">
      </div>
      <div class="wo-field" style="flex:1 1 220px;">
        <label>Search</label>
        <input type="text" name="q" value="{{ $search }}" placeholder="Name, email, or order ID" style="width:100%;">
      </div>
      <div class="wo-actions-inline">
        <button type="submit" class="wo-btn wo-btn-primary">Filter</button>
        <a href="{{ route('website-orders.index', ['tab' => $activeTab]) }}" style="font-size:13px;color:#000;">Clear</a>
      </div>
    </form>

    {{-- Safety net: paid orders no tab shows (Sarah, 2026-10-21) --}}
    @if($activeTab === 'unsorted')
      <div class="wo-banner warn" style="font-size:15px;font-weight:700;">Not on any tab: these paid orders didn't fit any tab. Open each one and set its status (or tell Sarah).</div>
    @elseif(($tabCounts['unsorted'] ?? 0) > 0)
      <div class="wo-banner warn" style="font-size:15px;font-weight:700;">{{ $tabCounts['unsorted'] }} paid {{ $tabCounts['unsorted'] === 1 ? 'order isn\'t' : 'orders aren\'t' }} on any tab. <a href="{{ route('website-orders.index', ['tab' => 'unsorted']) }}">Show {{ $tabCounts['unsorted'] === 1 ? 'it' : 'them' }}</a></div>
    @endif
    {{-- Orders never disappear: say where else the search matches (Sarah, 2026-10-21) --}}
    @if(!empty($searchElsewhere))
      <div class="wo-banner warn" style="font-size:15px;font-weight:700;">"{{ request('q') }}" also matches orders on:
        @foreach($searchElsewhere as $seTab => $seN)
          <a href="{{ route('website-orders.index', array_filter(['tab' => $seTab, 'q' => request('q'), 'payment_status' => request('payment_status')])) }}" style="margin-left:8px;">{{ \App\Http\Controllers\WebsiteOrdersController::TABS[$seTab]['label'] ?? ucfirst($seTab) }} ({{ $seN }})</a>
        @endforeach
      </div>
    @endif
    @if(!empty($searchAll))
      <div class="wo-banner" style="font-size:15px;font-weight:700;background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;">Every order matching "{{ request('q') }}" from all tabs, any date. Each one says which tab it's on.</div>
    @endif
    @if(!empty($linkOrderMissing))
      <div class="wo-banner warn" style="font-size:15px;font-weight:700;">This order wasn't found on the website. Check the order number, or search the buyer's name above.</div>
    @endif
    @if(empty($orders))
      <div class="ev-card"><div class="empty">No orders in this view.
        {{-- Store filter on a tab with nothing to pull: point to where the pulls are (Sarah, 2026-10-19) --}}
        @if(in_array($locationFilter, ['hollywood', 'pico', 'warehouse'], true) && $activeTab !== 'needs_action' && ($pullCounts[$locationFilter] ?? 0) > 0)
          <div style="margin-top:10px;font-size:15px;font-weight:700;color:#000;">{{ ucfirst($locationFilter) }} has {{ $pullCounts[$locationFilter] }} items on the Need to Pull tab.
            <a href="{{ route('website-orders.index', ['tab' => 'needs_action', 'location' => $locationFilter]) }}" style="display:inline-block;margin-left:8px;padding:8px 14px;border-radius:6px;background:#1e40af;color:#fff;text-decoration:none;">Show them</a>
          </div>
        @endif
      </div></div>
    @else
      <div class="wo-card">
        <div class="wo-scroll">
        <table class="wo-tbl">
          <colgroup>
            <col style="width:100px">
            <col style="width:12%">
            <col>
            <col style="width:340px">
            <col style="width:108px">
            <col style="width:118px">
            <col style="width:112px">
            <col style="width:86px">
            <col style="width:236px">
          </colgroup>
          <thead><tr>
            <th>@include('website-orders.partials.sort-th', ['label' => 'Placed', 'key' => 'placed'])</th>
            <th>@include('website-orders.partials.sort-th', ['label' => 'Buyer', 'key' => 'buyer'])</th>
            <th>@include('website-orders.partials.sort-th', ['label' => 'Product', 'key' => 'product'])</th>
            <th>Location</th>
            <th>@include('website-orders.partials.sort-th', ['label' => 'Listed', 'key' => 'listed'])</th>
            <th>@include('website-orders.partials.sort-th', ['label' => 'Status', 'key' => 'status'])</th>
            <th>@include('website-orders.partials.sort-th', ['label' => 'Fulfillment', 'key' => 'fulfillment'])</th>
            <th>@include('website-orders.partials.sort-th', ['label' => 'Total', 'key' => 'total'])</th>
            <th>Actions</th>
          </tr></thead>
          <tbody>
            @foreach($orders as $o)
              @php
                $isGift = collect($o['items'] ?? [])->isNotEmpty() && collect($o['items'] ?? [])->every(fn($it) =>
                  ($it['is_gift_card'] ?? false) === true || (empty($it['product_id']) && !empty($it['gift_card_amount']))
                );
                // Real name (looked up from the payment when the checkout name
                // was a username), then the shipping name, then the account.
                $customerName = $o['display_name'] ?? (($o['shippingAddress'][0]['name'] ?? null) ?: ($o['user_id']['name'] ?? 'Guest'));
                $customerEmail = $o['user_id']['email'] ?? '';
                $placed = !empty($o['createdAt']) ? date('M j, Y g:ia', strtotime($o['createdAt'])) : '—';
                $isArchived = !empty($o['archived']);
                $status = $o['order_status'] ?? '';
                $pillClass = in_array($status, ['processing','ready_for_pickup','picked_up','shipped','email_sent','delivered','cancelled','flag'], true) ? "st-{$status}" : 'st-default';
                $rowId = 'wo-detail-' . preg_replace('/[^A-Za-z0-9]/', '', (string) ($o['_id'] ?? '')) . '-' . $loop->index;
                // Order-level preorder flag: true if ANY item is still a
                // preorder. Surfaced on the collapsed row (not just inside
                // View details) so Nick doesn't hunt for stock that hasn't
                // shipped to us yet, or cancel it thinking it's missing.
                $rowShipDates = [];
                $rowIsPreorder = false;
                foreach (($o['items'] ?? []) as $rowItem) {
                  if (!empty($rowItem['product_id']['isPreorder'])) {
                    $rowIsPreorder = true;
                    if (!empty($rowItem['product_id']['preorderShipDate'])) {
                      $rowShipDates[] = $rowItem['product_id']['preorderShipDate'];
                    }
                  }
                }
                sort($rowShipDates);
                $rowShipDate = $rowShipDates[0] ?? null;
                // Lines the Cancel dialog can tick (item-level cancel).
                $cancelLines = collect($o['items'] ?? [])->map(fn($it) => [
                  'id' => (string) ($it['_id'] ?? ''),
                  'name' => (($it['is_gift_card'] ?? false) === true || (empty($it['product_id']) && !empty($it['gift_card_amount'])))
                    ? 'Nivessa Gift Card' : ($it['product_id']['name'] ?? ($it['product_name'] ?? 'Item')),
                  'qty' => (int) ($it['quantity'] ?? 1),
                  'price' => (float) ($it['price'] ?? 0),
                  'cancelled' => !empty($it['cancelled']),
                  'gift' => ($it['is_gift_card'] ?? false) === true || (empty($it['product_id']) && !empty($it['gift_card_amount'])),
                  // Where it is (shown in the cancel box) and which store's package it ships in
                  'spot' => empty($it['product_id']) ? '' : \App\Http\Controllers\WebsiteOrdersController::pullSpot($it, $erpLocations[(int) ($it['product_id']['posProductId'] ?? 0)] ?? null),
                  'store' => (string) ($it['ship_from'] ?? ''),
                ])->values();
              @endphp
              @php
                // Thumbnails (up to 3, then +N) and the oldest item's listed
                // date for the row (Sarah, 2026-10-13).
                $rowItems = collect($o['items'] ?? [])->filter(fn($it) => !(($it['is_gift_card'] ?? false) === true || (empty($it['product_id']) && !empty($it['gift_card_amount']))))->values();
                $rowListedDates = $rowItems->map(fn($it) => $it['product_id']['posCreatedAt'] ?? ($it['product_id']['createdAt'] ?? null))->filter()->sort()->values();
                $rowListed = \App\Http\Controllers\WebsiteOrdersController::listedShort($rowListedDates->first());
              @endphp
              <tr class="wo-row">
                <td class="wo-placed">
                  @if(!empty($o['createdAt'])){{ date('M j, Y', strtotime($o['createdAt'])) }}<span class="t">{{ date('g:ia', strtotime($o['createdAt'])) }}</span>@else &mdash; @endif
                  @php $due = ($rowIsPreorder && !$rowShipDate) ? null : \App\Http\Controllers\WebsiteOrdersController::dueDate($o, $rowIsPreorder ? $rowShipDate : null); @endphp
                  @if($due)<span class="wo-due {{ $due[1] }}">{{ $due[0] }}</span>@endif
                  @if($activeTab === 'refunded')
                    @php [$rfAmt, $rfAt, $rfWhy] = \App\Http\Controllers\WebsiteOrdersController::refundSummary($o); @endphp
                    <span style="display:block;margin-top:5px;font-size:12px;font-weight:700;color:#6b21a8;">Refunded{{ $rfAmt > 0 ? ' $' . number_format($rfAmt, 2) : '' }}{{ $rfAt ? ' on ' . date('m/d/y', strtotime($rfAt)) : '' }}</span>
                    @if($rfWhy !== '')<span style="display:block;font-size:12px;color:#000;">{{ \Illuminate\Support\Str::limit($rfWhy, 90) }}</span>@endif
                  @endif
                  @if($activeTab === 'cant_find')
                    @php [$cfN, $cfAll] = \App\Http\Controllers\WebsiteOrdersController::cantFindCount($o); @endphp
                    <span style="display:block;margin-top:5px;font-size:12px;font-weight:700;color:#b91c1c;">{{ $cfN >= $cfAll ? 'Whole order' : 'Part of order' }}: {{ $cfN }} of {{ $cfAll }} can't find</span>
                  @endif
                  @if(($o['fulfillment_method'] ?? null) === 'pickup' && !in_array($status, ['picked_up', 'delivered', 'cancelled'], true) && !$isArchived)
                    @if($rowIsPreorder)
                      <span class="wo-sla pre">not in stock yet</span>
                    @else
                      @php $slaH = \App\Http\Controllers\WebsiteOrdersController::hoursSinceStatic($o['createdAt'] ?? null); @endphp
                      <span class="wo-sla {{ $slaH >= 48 ? 'over' : ($slaH >= 24 ? 'warn' : 'ok') }}">{{ $slaH }}h{{ $slaH >= 48 ? ' · OVERDUE' : ($slaH >= 24 ? ' · pull soon' : '') }}</span>
                    @endif
                  @endif
                </td>
                <td>
                  @if(!empty($searchAll))
                    @php [$onTab, $onTabName] = \App\Http\Controllers\WebsiteOrdersController::tabFor($o); @endphp
                    <a href="{{ url('/website-orders') }}?order={{ $o['_id'] }}" style="display:inline-block;margin-bottom:5px;padding:3px 8px;border-radius:999px;background:#dbeafe;color:#1e3a8a;font-size:12px;font-weight:700;text-decoration:none;">{{ $onTabName }}</a>
                  @endif
                  <div class="wo-buyer-name">{{ $customerName }}</div>
                  <div class="wo-buyer-email">{{ $customerEmail }}</div>
                </td>
                @php
                  // Store filter: show only the items that store pulls; the
                  // rest become "+N at Hollywood" (Sarah, 2026-10-16).
                  $woStore = ($activeTab !== 'ready_ship' && in_array($locationFilter, ['hollywood', 'pico', 'warehouse', 'hw'], true)) ? $locationFilter : null;
                  $lineShow = [];
                  $cfTab = in_array($activeTab, ['cant_find', 'recheck_hw'], true); // only the Can't Find lines
                  // Need to Pull tabs only show what's still to pull: picked, can't find
                  // and cancelled lines are hidden (Sarah, 2026-10-20)
                  $needTab = in_array($activeTab, ['to_ship', 'pickup', 'needs_action'], true) && !(($o['fulfillment_method'] ?? null) === 'pickup' && \App\Http\Controllers\WebsiteOrdersController::allPicked($o));
                  $offQueue = ($woStore || $cfTab || $needTab) ? \App\Http\Controllers\WebsiteOrdersController::offQueueNames($o, $cfTab) : [];
                  if ($activeTab === 'recheck_hw') {
                    // Only the lines Hollywood still has to check
                    $hwRe = \App\Http\Controllers\WebsiteOrdersController::hwRecheckNames($o);
                    foreach (($o['items'] ?? []) as $rit) { $rk = strtolower(trim((string) ($rit['product_id']['name'] ?? ($rit['product_name'] ?? '')))); if (!isset($hwRe[$rk])) $offQueue[$rk] = true; }
                  }
                  foreach ($cancelLines as $i => $l) {
                    $it = $o['items'][$i] ?? [];
                    // Store queue hides items already picked or marked Can't Find
                    if (($woStore || $needTab) && !empty($it['cancelled'])) { $lineShow[$i] = false; continue; }
                    if (($woStore || $cfTab || $needTab) && isset($offQueue[strtolower(trim((string) ($it['product_id']['name'] ?? ($it['product_name'] ?? ''))))])) { $lineShow[$i] = false; continue; }
                    $pp = $l['gift'] ? null : \App\Http\Controllers\WebsiteOrdersController::pullParts($it, $erpLocations[(int) ($it['product_id']['posProductId'] ?? 0)] ?? null);
                    $lineShow[$i] = !$woStore || ($pp && \App\Http\Controllers\WebsiteOrdersController::placeIsStore($pp['place'], $woStore));
                  }
                  // Opened from an order link: show every item with its pick
                  // state, picked and can't find included (Sarah, 2026-10-21)
                  // Shipped / done orders too: nothing left to pull, so show it all
                  // with what happened to each item (Sarah, 2026-10-21).
                  if (preg_match('/^[a-f0-9]{24}$/i', (string) request()->query('order', '')) || in_array($status, ['shipped', 'delivered', 'picked_up', 'email_sent'], true)) $lineShow = array_fill_keys(array_keys($lineShow), true);
                  $rowPickLines = [];
                  foreach (($o['pick_progress']['lines'] ?? []) as $rpl) $rowPickLines[strtolower(trim((string) ($rpl['name'] ?? '')))] = $rpl;
                  $rowPickable = !empty($o['pick_progress']['order_id']) && in_array($activeTab, ['to_ship', 'ready_ship', 'preorder_ship', 'preorders', 'needs_action', 'cant_find', 'recheck_hw', 'pickup', 'ready_pickup'], true);
                  $hiddenLines = count(array_filter($lineShow, fn($v) => !$v));
                @endphp
                <td>
                  @foreach($cancelLines as $line)
                    @continue(!$lineShow[$loop->index])
                    @php
                      $lineItem = $o['items'][$loop->index] ?? [];
                      $lineArtist = $line['gift'] ? null : (($lineItem['product_id']['artist'] ?? null) ?: ($lineItem['product_artist'] ?? null));
                      $lineImg = $line['gift'] ? null : (($lineItem['product_id']['image'] ?? null) ?: ($lineItem['product_image'] ?? null));
                      $lineErp = $line['gift'] ? null : ($lineItem['product_id']['posProductId'] ?? null);
                    @endphp
                    <div class="wo-prod-line wo-prod-with-img">
                      @if($lineImg)<img class="wo-prod-img" src="{{ $lineImg }}" alt="" loading="lazy">@else<div class="wo-prod-img"></div>@endif
                      <div>
                        @if($line['cancelled'])<s>@endif @if($lineErp)<a class="wo-prod-name" href="{{ url('/products/' . $lineErp . '/edit') }}" target="_blank" rel="noopener">{{ $line['name'] }}</a>@else{{ $line['name'] }}@endif @if($line['cancelled'])</s>@endif
                        @if(!$line['gift'] && !$lineErp)<span class="wo-noerp">not in ERP</span>@endif
                        @php $lineMedia = $line['gift'] ? null : \App\Http\Controllers\WebsiteOrdersController::mediaTypeLabel($lineItem['product_id']['productType'] ?? null); $lineFmt = $line['gift'] ? null : ($lineItem['product_id']['format'] ?? null); @endphp
                        @php $lineCond = $line['gift'] ? null : \App\Http\Controllers\WebsiteOrdersController::conditionOf($erpLocations[(int) ($lineErp ?? 0)]['genre'] ?? null, $lineItem['product_id']['subCategory'] ?? null); @endphp
                        @if($lineCond)<span class="wo-cond {{ $lineCond }}">{{ strtoupper($lineCond) }}</span>@endif
                        @if($lineMedia)<span class="wo-tag media">{{ $lineMedia }}</span>@endif
                        @if($lineFmt)<span class="wo-tag fmt">{{ $lineFmt }}</span>@endif
                        <div class="wo-prod-price">{{ $lineItem['quantity'] ?? $line['qty'] }} &times; ${{ number_format((float) ($lineItem['price'] ?? 0), 2) }}</div>
                        @if($lineArtist)<div class="wo-prod-artist">{{ $lineArtist }}</div>@endif
                        @php $rowPl = (!$rowPickable && !$line['gift'] && !$line['cancelled']) ? ($rowPickLines[strtolower(trim((string) $line['name']))] ?? null) : null; @endphp
                        @if($rowPl)
                          @php
                            $rowPs = $rowPl['status'] ?? 'Not Picked';
                            $rowDone = in_array($rowPs, ['Picked', 'Packed', 'Shipped', 'Dropshipped'], true);
                            $rowSub = !empty($lineItem['substituted_from']['name']);
                          @endphp
                          <span class="wo-item-chip {{ $rowPs === "Can't Find" ? 'cf' : ($rowDone ? ($rowSub ? 'sub' : 'ok') : 'no') }}" data-pick-item="{{ $rowPl['item_id'] ?? '' }}">{{ $rowPs === "Can't Find" ? "Can't find" : ($rowDone ? ($rowSub ? 'Substitute sent' : ($rowPs === 'Dropshipped' ? 'Dropshipped' : 'Picked')) : ($rowPs === 'Pending Arrival' ? 'Pending arrival' : 'Not picked')) }}</span>
                        @elseif($line['cancelled'])
                          <span class="wo-item-chip no">Cancelled</span>
                        @endif
                        @if(!empty($lineItem['substituted_from']['name']))
                          <div style="margin-top:4px;font-size:12px;font-weight:700;color:#1e40af;">Substitute for {{ $lineItem['substituted_from']['name'] }}@if(!empty($lineItem['substituted_from']['by'])) ({{ $lineItem['substituted_from']['by'] }}{{ !empty($lineItem['substituted_from']['at']) ? ' ' . date('m/d/y', strtotime($lineItem['substituted_from']['at'])) : '' }})@endif</div>
                          @if(!empty($lineItem['substituted_from']['note']))<div style="font-size:12px;color:#000;">{{ $lineItem['substituted_from']['note'] }}</div>@endif
                        @endif
                      </div>
                    </div>
                  @endforeach
                  @if($hiddenLines > 0)
                    <div style="font-size:12px;color:#000;margin-top:4px;">+{{ $hiddenLines }} other {{ $hiddenLines === 1 ? 'item' : 'items' }} (other spot, picked, can't find or cancelled) <a href="{{ route('website-orders.index', ['order' => $o['_id']]) }}">Show all items</a></div>
                  @endif
                </td>
                @php
                  // Pick buttons sit beside each item (Sarah, 2026-10-19: the
                  // separate checklist cut names to one letter). Matched by name.
                  $pickLines = [];
                  foreach (($o['pick_progress']['lines'] ?? []) as $pl) $pickLines[strtolower(trim((string) ($pl['name'] ?? '')))] = $pl;
                  $pickable = !empty($o['pick_progress']['order_id']) && in_array($activeTab, ['to_ship', 'ready_ship', 'preorder_ship', 'preorders', 'needs_action', 'cant_find', 'recheck_hw', 'pickup', 'ready_pickup'], true);
                @endphp
                <td class="wo-loc @if($pickable) wo-pick @endif" data-web="{{ $o['_id'] }}" @if($pickable) data-order="{{ $o['pick_progress']['order_id'] }}" @endif>
                  {{-- One block per product, kept level with its product by
                       woAlignLoc() below (Sarah, 2026-10-16). See pullParts(). --}}
                  @foreach($cancelLines as $line)
                    @continue(!$lineShow[$loop->index])
                    @php
                      $wItem = $o['items'][$loop->index] ?? [];
                      $locPid = $line['gift'] ? 0 : (int) ($wItem['product_id']['posProductId'] ?? 0);
                      $pull = $line['gift'] ? null : \App\Http\Controllers\WebsiteOrdersController::pullParts($wItem, $locPid ? ($erpLocations[$locPid] ?? null) : null);
                    @endphp
                    <div class="wo-loc-line">
                      @if($line['gift'])
                        <span class="na">Digital</span>
                      @elseif($pull['place'] === '' && $pull['area'] === '' && $pull['bin'] === '')
                        <span class="none">Not set</span>
                      @else
                        @if($pull['place'] !== '')<span class="place">{{ $pull['place'] }}</span>@endif
                        @if($pull['area'] !== '')<span class="area">{{ $pull['area'] }}</span>@endif
                        @if(($pull['genre'] ?? '') !== '')<span class="area">{{ $pull['genre'] }}</span>@endif
                        @if($pull['bin'] !== '')<span class="binv">Bin {{ $pull['bin'] }}</span>@endif
                      @endif
                      @php $pl = (!$pickable || $line['gift'] || $line['cancelled']) ? null : ($pickLines[strtolower(trim((string) $line['name']))] ?? null); @endphp
                      @if($pl)
                        @php
                          $plStatus = $pl['status'] ?? 'Not Picked';
                          $plDone = in_array($plStatus, ['Picked', 'Packed', 'Shipped', 'Dropshipped'], true);
                          $plMissing = $plStatus === "Can't Find";
                        @endphp
                        <div class="wo-pick-line wo-pick-inline {{ $plDone ? 'is-done' : ($plMissing ? 'is-missing' : '') }}" data-item="{{ $pl['item_id'] }}" data-qty="{{ (int) ($pl['quantity'] ?? 1) }}">
                          <div class="wo-pick-state">{{ $plDone ? $plStatus . (!empty($pl['picked_by']) ? ' by ' . $pl['picked_by'] : '') : ($plMissing ? "Can't find" . (!empty($pl['picked_by']) ? ' (' . $pl['picked_by'] . ')' : '') : ($plStatus === 'Pending Arrival' ? 'Pending arrival' : 'Not picked yet')) }}</div>
                          <div class="wo-pick-btns">
                            @if($plMissing && $activeTab === 'recheck_hw')
                              {{-- Pico couldn't find it: Hollywood checks its own bins (Sarah, 2026-10-20) --}}
                              @php
                                // Where it most likely is at Hollywood (Sarah, 2026-10-20)
                                $hwErp = $locPid ? ($erpLocations[$locPid] ?? null) : null;
                                $hwSpots = [];
                                $hwGenre = trim((string) ($hwErp['genre'] ?? ''));
                                $hwSpots[] = 'Hollywood Store' . ($hwGenre !== '' ? ': ' . $hwGenre : '') . ' section';
                                $hwBin = trim((string) (($wItem['product_id']['discogsLocation'] ?? '') ?: ($hwErp['bin'] ?? '')));
                                if ($hwBin !== '' && stripos($hwBin, 'pico') !== 0) $hwSpots[] = 'Warehouse bin ' . $hwBin;
                                elseif ($hwBin !== '') $hwSpots[] = 'Bin ' . $hwBin . ' (Pico bins are on the HW main floor)';
                                $hwQty = (float) ($hwErp['stores']['Hollywood In Store'] ?? 0) + (float) ($hwErp['stores']['Hollywood Warehouse'] ?? 0);
                                if ($hwQty > 0) $hwSpots[] = 'ERP shows ' . (int) $hwQty . ' at Hollywood';
                              @endphp
                              <div style="flex:0 0 100%;font-size:12px;color:#000;margin:2px 0 2px;"><strong>Most likely at Hollywood:</strong><br>@foreach($hwSpots as $hs)&bull; {{ $hs }}<br>@endforeach</div>
                              <button type="button" class="go" onclick="woPick(this, 'Picked')">Found it</button><button type="button" class="cf" onclick="woHwCheck(this, {{ json_encode(strtolower(trim((string) $line['name']))) }})">Not here either</button>
                            @elseif($plMissing && $activeTab === 'cant_find')
                              {{-- After talking to the customer: swap for another record (Sarah, 2026-10-21) --}}
                              <button type="button" class="go" onclick="woSubOpen('{{ $o['_id'] }}', '{{ $line['id'] }}', {{ json_encode($line['name']) }}, {{ json_encode($customerName) }})">Substitute</button><button type="button" class="undo" onclick="woPick(this, 'Not Picked')">Undo</button>
                            @elseif($plDone || $plMissing)
                              <button type="button" class="undo" onclick="woPick(this, 'Not Picked')">Undo</button>
                            @else
                              <button type="button" class="go" onclick="woPick(this, 'Picked')">Mark picked</button><button type="button" class="cf" onclick="woPick(this, 'Can\'t Find')">Can't find</button><button type="button" class="ds" onclick="woPick(this, 'Dropshipped')">Dropshipped</button>
                            @endif
                          </div>
                        </div>
                      @endif
                    </div>
                  @endforeach
                </td>
                <td class="wo-listed">{{ explode(' · ', (string) ($rowListed ?? ''), 2)[0] }}@if(count(explode(' · ', (string) ($rowListed ?? ''), 2)) > 1)<span class="t">{{ explode(' · ', (string) ($rowListed ?? ''), 2)[1] }}</span>@endif</td>
                <td>
                  <span class="wo-pill {{ $pillClass }}">{{ $statuses[$status] ?? ($status ?: 'Pending') }}</span>
                  @if($paymentStatusFilter === 'all')
                    @php $ps = $o['payment_status'] ?? 'unknown'; $psColors = ['completed' => '#dcfce7;color:#166534', 'pending' => '#fef9c3;color:#854d0e', 'failed' => '#fee2e2;color:#991b1b', 'cancelled' => '#f3f4f6;color:#000', 'refunded' => '#f3e8ff;color:#6b21a8']; @endphp
                    <div style="margin-top:5px;"><span class="wo-pill" style="background:{{ $psColors[$ps] ?? '#f3f4f6;color:#000' }};font-size:11px;padding:2px 8px;">Payment: {{ ucfirst($ps) }}</span></div>
                  @endif
                  @if($rowIsPreorder)
                    <div style="margin-top:6px;">
                      <span style="display:inline-block;padding:3px 9px;border-radius:999px;background:#f97316;color:#fff;font-size:11px;font-weight:700;white-space:nowrap;">PREORDER — NOT IN STOCK</span>
                      @if($rowShipDate)
                        <div style="font-size:11px;color:#9a3412;margin-top:3px;">Ships {{ gmdate('M j, Y', strtotime($rowShipDate)) }}</div>
                      @endif
                    </div>
                  @else
                    @php
                      // Released preorders keep their street date (Sarah, 2026-10-19: show it on Ready for Pickup)
                      $streetDates = collect($o['items'] ?? [])->pluck('product_id.preorderShipDate')->filter()->sort()->values();
                    @endphp
                    @if($streetDates->isNotEmpty())
                      <div style="font-size:12px;color:#000;margin-top:5px;font-weight:600;">Street date {{ gmdate('m/d/y', strtotime($streetDates->first())) }}</div>
                    @endif
                  @endif
                </td>
                <td>
                  @if($isGift)
                    <span class="wo-fm-pill digital">Digital (Email)</span>
                  @elseif(($o['fulfillment_method'] ?? null) === 'pickup')
                    <span class="wo-fm-pill pickup" @if(!\App\Http\Controllers\WebsiteOrdersController::knownPickupLocation($o)) style="background:#fee2e2;color:#991b1b;" @endif>Pickup ({{ \App\Http\Controllers\WebsiteOrdersController::pickupLocationLabel($o) }})</span>
                  @else
                    <span class="wo-fm-pill shipping">Shipping</span>
                    @if(($o['ship_speed'] ?? null) === 'priority')
                      @if(in_array($status, ['shipped', 'delivered', 'email_sent'], true))
                        <span class="wo-fm-pill" style="background:#fee2e2;color:#991b1b;font-weight:700;">Shipped Priority</span>
                      @else
                        <span class="wo-fm-pill" style="background:#fee2e2;color:#991b1b;font-weight:700;" title="Customer paid for USPS Priority Mail. Buy a Priority Mail label for this order.">PRIORITY: ship Priority Mail</span>
                      @endif
                    @endif
                    @php
                      // Two store carts: which store(s) this order ships from.
                      $woShipStores = collect($o['items'] ?? [])
                        ->filter(function ($it) { return empty($it['is_gift_card']) && !empty($it['ship_from']); })
                        ->pluck('ship_from')->unique()->values();
                    @endphp
                    @if(!empty($o['ships_from_both_stores']))
                      <div>
                        @if(($o['ship_mode'] ?? null) === 'combine')
                          <span class="wo-fm-pill both-comb" title="Customer chose one package: bring everything to one store, ship together">Both stores: combine into 1 package</span>
                        @else
                          <span class="wo-fm-pill both-sep" title="Customer chose separate packages: each store ships its own part">Both stores: ship separately</span>
                        @endif
                      </div>
                    @elseif($woShipStores->count() === 1)
                      <div class="wo-shipfrom">Ships from {{ $woShipStores->first() === 'pico' ? 'Pico' : 'Hollywood' }}</div>
                    @endif
                    {{-- What the customer paid for shipping, at a glance (Sarah, 2026-10-21) --}}
                    @php $woShipPaid = $o['shipping_cost'] ?? null; @endphp
                    <div style="margin-top:6px;font-size:14px;font-weight:700;color:#000;">
                      @if($woShipPaid === null)
                        Shipping paid: not recorded
                      @elseif((float) $woShipPaid <= 0)
                        Shipping paid: $0.00 (free)
                      @else
                        Shipping paid: ${{ number_format((float) $woShipPaid, 2) }}
                      @endif
                      <button type="button" class="wo-onote-open" onclick="woShipPaidEdit('{{ $o['_id'] }}', '{{ $woShipPaid === null ? '' : number_format((float) $woShipPaid, 2, '.', '') }}')">Edit</button>
                    </div>
                    @if(($o['ship_speed'] ?? null) === 'priority' && !empty($o['priority_packages']))
                      @foreach($o['priority_packages'] as $pp)
                        <div style="font-size:12px;color:#000;">{{ ($pp['store'] ?? '') === 'pico' ? 'Pico' : 'Hollywood' }} package: ${{ number_format((float) ($pp['price'] ?? 0), 2) }}</div>
                      @endforeach
                    @endif
                  @endif
                </td>
                <td class="wo-total">${{ number_format((float) ($o['total_amount'] ?? 0), 2) }}
                  {{-- Paid outside checkout, already in the total (Sarah, 2026-10-21) --}}
                  @foreach(($o['extra_payments'] ?? []) as $xp)
                    <div style="font-size:12px;font-weight:600;color:#000;margin-top:4px;">incl. ${{ number_format((float) ($xp['amount'] ?? 0), 2) }} {{ $xp['method'] ?? '' }}</div>
                  @endforeach
                </td>
                <td class="wo-actions-cell">
                  <div class="wo-row-actions">
                    <button type="button" class="wo-btn wo-btn-primary"
                      onclick="woOpenStatus('{{ $o['_id'] }}', '{{ $status }}', '{{ $o['tracking_number'] ?? '' }}')">Update status</button>
                    {{-- Toggles the details row right after this order's row (not by id:
                         two orders could end up with the same short id). --}}
                    <button type="button" class="wo-btn" onclick="woToggleDetails(this)">Details</button>
                    <button type="button" class="wo-btn" title="Copy a link to this order (for Slack)" onclick="woCopyLink(this, '{{ url('/website-orders') }}?order={{ $o['_id'] }}')">Copy link</button>
                    @if($status === 'ready_for_pickup' && ($o['fulfillment_method'] ?? null) === 'pickup')
                      {{-- Re-sends the "ready for pickup" email (Sarah, 2026-10-20) --}}
                      <form method="POST" action="{{ route('website-orders.updateStatus', $o['_id']) }}" style="display:inline;" onsubmit="this.querySelector('button').disabled=true;">
                        @csrf
                        <input type="hidden" name="status" value="ready_for_pickup">
                        <input type="hidden" name="notify_customer" value="1">
                        <button type="submit" class="wo-btn" title="Email the customer again that their order is ready">Send pickup reminder</button>
                      </form>
                      @if(!empty($o['pickup_ready_notified_at']))<div style="font-size:12px;color:#000;margin-top:3px;">Last emailed {{ date('m/d/y', strtotime($o['pickup_ready_notified_at'])) }}</div>@endif
                    @endif
                    <div class="wo-more">
                      <button type="button" class="wo-btn wo-more-btn" title="More actions" aria-label="More actions" onclick="woToggleMore(this, event)"><svg viewBox="0 0 16 16" aria-hidden="true"><circle cx="3" cy="8" r="1.5"/><circle cx="8" cy="8" r="1.5"/><circle cx="13" cy="8" r="1.5"/></svg></button>
                      <div class="wo-more-menu">
                        <form method="POST" action="{{ route('website-orders.archive', ['id' => $o['_id']]) }}"
                              onsubmit="return confirm('{{ $isArchived ? 'Restore this order to its status bucket?' : 'Archive this order?' }}');">
                          {{ csrf_field() }}
                          <input type="hidden" name="archived" value="{{ $isArchived ? '0' : '1' }}">
                          <button type="submit" class="wo-more-item">{{ $isArchived ? 'Restore' : 'Archive' }}</button>
                        </form>
                        <button type="button" class="wo-more-item" onclick="woCloseMore(); woExtraOpen('{{ $o['_id'] }}')">Add extra payment</button>
                        @if(!$isArchived)
                          <button type="button" class="wo-more-item danger" data-order="{{ $o['_id'] }}" data-who="{{ $customerName }}" data-num="{{ $o['orderNumber'] ?? '' }}" data-total="{{ number_format((float) ($o['total_amount'] ?? 0), 2, '.', '') }}" data-split="{{ (!empty($o['ships_from_both_stores']) && ($o['ship_mode'] ?? 'separate') !== 'combine' && ($o['fulfillment_method'] ?? '') === 'shipping') ? '1' : '0' }}" data-lines="{{ json_encode($cancelLines) }}" onclick="woCloseMore(); woOpenCancel('{{ $o['_id'] }}', this)">Cancel order</button>
                        @endif
                      </div>
                    </div>
                  </div>
                  {{-- Internal refund reasons (for Jon), saved from the cancel box --}}
                  @foreach(($o['refund_notes'] ?? []) as $rn)
                    <div class="wo-onote-show" style="background:#fee2e2;border-color:#fecaca;">
                      <div class="txt">Refund reason: {{ $rn['text'] ?? '' }}</div>
                      <div class="by">@if(!empty($rn['items'])){{ implode(', ', $rn['items']) }} &middot; @endif{{ $rn['by'] ?? '' }} @if(!empty($rn['at'])){{ \Carbon\Carbon::parse($rn['at'])->setTimezone('America/Los_Angeles')->format('m/d/y g:ia') }}@endif</div>
                    </div>
                  @endforeach
                  {{-- Note on this one order (Sarah, 2026-10-16), saved to the website order --}}
                  @foreach(($o['extra_payments'] ?? []) as $xp)
                    <div class="wo-onote-show" style="background:#dbeafe;border-color:#bfdbfe;">
                      <div class="txt">Extra paid ${{ number_format((float) ($xp['amount'] ?? 0), 2) }} &middot; {{ $xp['method'] ?? '' }}@if(!empty($xp['payer'])) from {{ $xp['payer'] }}@endif</div>
                      @if(!empty($xp['note']))<div style="font-size:12px;color:#000;margin-top:2px;">{{ $xp['note'] }}</div>@endif
                      <div class="by">{{ $xp['by'] ?? '' }} @if(!empty($xp['at'])){{ \Carbon\Carbon::parse($xp['at'])->setTimezone('America/Los_Angeles')->format('m/d/y g:ia') }}@endif</div>
                    </div>
                  @endforeach
                  <div class="wo-xpay" id="wo-xpay-{{ $o['_id'] }}" hidden style="border:1px solid #bfdbfe;border-radius:6px;padding:8px;margin:4px 0;background:#fff;">
                    <div style="font-size:13px;font-weight:700;color:#000;margin-bottom:4px;">Extra payment (added to the total)</div>
                    <input type="number" step="0.01" min="0" class="xp-amount" placeholder="Amount" style="width:100%;margin-bottom:4px;">
                    <input type="text" class="xp-method" placeholder="How (PayPal, Zelle...)" style="width:100%;margin-bottom:4px;">
                    <input type="text" class="xp-payer" placeholder="Paid by (name on the payment)" style="width:100%;margin-bottom:4px;">
                    <textarea class="xp-note" rows="2" placeholder="What it was for" style="width:100%;margin-bottom:4px;"></textarea>
                    <label style="font-size:13px;color:#000;"><input type="checkbox" class="xp-priority"> Shipped Priority</label>
                    <div class="xp-msg" style="font-size:12px;color:#991b1b;margin:4px 0;"></div>
                    <button type="button" class="wo-btn wo-btn-primary" onclick="woExtraSave('{{ $o['_id'] }}', this)">Save</button>
                    <button type="button" class="wo-btn" onclick="document.getElementById('wo-xpay-{{ $o['_id'] }}').hidden = true">Cancel</button>
                  </div>
                  @php $oNote = $o['staff_note'] ?? []; $oNoteText = trim((string) ($oNote['text'] ?? '')); @endphp
                  <div class="wo-onote" data-id="{{ $o['_id'] }}">
                    <div class="wo-onote-show" @if($oNoteText === '') hidden @endif>
                      <div class="txt">{{ $oNoteText }}</div>
                      <div class="by">@if(!empty($oNote['by'])){{ $oNote['by'] }} @endif @if(!empty($oNote['at'])){{ \Carbon\Carbon::parse($oNote['at'])->setTimezone('America/Los_Angeles')->format('m/d/y g:ia') }}@endif</div>
                    </div>
                    <button type="button" class="wo-onote-open" onclick="woNoteOpen(this)">{{ $oNoteText === '' ? '+ Add note' : 'Edit note' }}</button>
                    <div class="wo-onote-edit" hidden>
                      <textarea rows="3" maxlength="2000">{{ $oNoteText }}</textarea>
                      <button type="button" class="wo-btn wo-btn-primary" onclick="woNoteSave(this)">Save note</button>
                      <button type="button" class="wo-btn" onclick="woNoteClose(this)">Cancel</button>
                    </div>
                  </div>
                  @if(!empty($o['pick_progress']) && in_array($activeTab, ['to_ship', 'ready_ship', 'preorder_ship', 'preorders', 'needs_action', 'cant_find', 'recheck_hw', 'pickup', 'ready_pickup'], true))
                    @php $pp = $o['pick_progress']; @endphp
                    {{-- Pick buttons are beside each item in Location; just the count here --}}
                    @php
                      $ppN = (int) ($pp['picked'] ?? 0); $ppT = max(1, (int) ($pp['total'] ?? 0)); $ppPct = (int) round(100 * min($ppN, $ppT) / $ppT);
                      // Can't find shows in red on the same bar (Sarah, 2026-10-20)
                      $ppCf = 0; foreach (($pp['lines'] ?? []) as $ppl) if (($ppl['status'] ?? '') === "Can't Find") $ppCf += max(1, (int) ($ppl['quantity'] ?? 1));
                      $ppCfPct = (int) round(100 * min($ppCf, $ppT - min($ppN, $ppT)) / $ppT);
                      $ppLeft = max(0, (int) ($pp['total'] ?? 0) - $ppN - $ppCf);
                      // Substitutes sent count on their own, not as picked (Sarah, 2026-10-21: "5 of 8 picked w 2 substitutes")
                      $ppSub = 0;
                      foreach (($o['items'] ?? []) as $ppIt) {
                        if (empty($ppIt['substituted_from']['name']) || !empty($ppIt['cancelled'])) continue;
                        $ppL = $rowPickLines[strtolower(trim((string) ($ppIt['product_id']['name'] ?? ($ppIt['product_name'] ?? ''))))] ?? null;
                        if ($ppL && in_array($ppL['status'] ?? '', ['Picked', 'Packed', 'Shipped', 'Dropshipped'], true)) $ppSub += max(1, (int) ($ppL['quantity'] ?? 1));
                      }
                      $ppSub = min($ppSub, $ppN);
                      $ppSubPct = (int) round(100 * $ppSub / $ppT);
                      $ppPct = max(0, $ppPct - $ppSubPct);
                    @endphp
                    {{-- Progress bar: none / partly / all picked at a glance (Sarah, 2026-10-20) --}}
                    <div class="wo-pick-count wo-prog-wrap {{ $ppN >= $ppT ? 'full' : ($ppN > 0 ? 'part' : 'none') }}">
                      <div class="wo-prog"><span class="p" style="width:{{ $ppPct }}%"></span><span class="s" style="width:{{ $ppSubPct }}%;display:inline-block;height:100%;background:#2563eb;"></span><span class="c" style="width:{{ $ppCfPct }}%"></span></div>
                      <div class="wo-prog-lbl"><span class="n">{{ $ppN - $ppSub }}</span> of <span class="t">{{ (int) ($pp['total'] ?? 0) }}</span> picked</div>
                      <div class="wo-prog-sub">@if($ppSub)<span style="color:#1e40af;font-weight:700;">{{ $ppSub }} {{ $ppSub === 1 ? 'substitute' : 'substitutes' }}</span>@endif<span class="cf">{{ $ppCf ? ($ppSub ? ' · ' : '') . $ppCf . " can't find" : '' }}</span><span class="left">{{ $ppLeft ? (($ppCf || $ppSub) ? ' · ' : '') . $ppLeft . ' left' : '' }}</span></div>
                      @if(($o['fulfillment_method'] ?? null) === 'pickup' && $ppN >= $ppT && $status !== 'ready_for_pickup')
                        <div style="font-size:11.5px;font-weight:700;color:#047857;margin-top:3px;">All picked: set status to Ready for Pickup (emails the customer)</div>
                      @endif
                    </div>
                  @endif
                </td>
              </tr>
              <tr id="{{ $rowId }}" class="wo-detail" style="display:none;">
                <td colspan="9">
                  <div class="wo-detail-grid">
                    <div>
                      <h5>Order</h5>
                      {{ $o['_id'] ?? '—' }}<br>
                      Payment: {{ $o['payment_status'] ?? '—' }} via {{ $o['paymentMethod'] ?? '—' }}<br>
                      Placed: {{ !empty($o['createdAt']) ? date('M j, Y g:ia', strtotime($o['createdAt'])) : '—' }}<br>
                      @php $subtotal = collect($o['items'] ?? [])->sum(fn($it) => (float) ($it['price'] ?? 0) * (int) ($it['quantity'] ?? 1)); @endphp
                      Subtotal: ${{ number_format($subtotal, 2) }}<br>
                      Total: ${{ number_format((float) ($o['total_amount'] ?? 0), 2) }}
                      @if(!empty($o['total_discount']) && $o['total_discount'] > 0)
                        (discount -${{ number_format((float) $o['total_discount'], 2) }})
                      @endif
                      @if(!empty($o['_id']))
                        <div style="margin-top:8px;">
                          <a href="https://nivessa.com/admin/orders/{{ $o['_id'] }}" target="_blank" rel="noopener" style="font-size:12px;color:#4a6fa5;">View on nivessa.com &rarr;</a>
                        </div>
                      @endif
                    </div>
                    @if(!$isGift)
                      <div>
                        <h5>{{ ($o['fulfillment_method'] ?? null) === 'pickup' ? 'Pickup' : 'Shipping' }} info</h5>
                        {{ $o['display_name'] ?? ($o['user_id']['name'] ?? '—') }}<br>
                        {{ $o['user_id']['email'] ?? '' }}@if(!empty($o['user_id']['email']))<br>@endif
                        Contact: {{ $o['contactNumber'] ?? '—' }}<br>
                        @php $addr = $o['shippingAddress'][0] ?? []; @endphp
                        {{ $addr['fullAddress'] ?? '—' }}
                        @if(!empty($addr['apartment'])) , {{ $addr['apartment'] }} @endif
                        <br>
                        {{ $addr['city'] ?? '' }}{{ !empty($addr['city']) ? ',' : '' }} {{ $addr['state'] ?? '' }} {{ $addr['zipCode'] ?? '' }}<br>
                        {{ $addr['country'] ?? '' }}
                        @php
                          $addrText = implode("\n", array_filter([
                            $o['display_name'] ?? ($o['user_id']['name'] ?? null),
                            $addr['fullAddress'] ?? null,
                            $addr['apartment'] ?? null,
                            implode(', ', array_filter([$addr['city'] ?? null, trim(($addr['state'] ?? '') . ' ' . ($addr['zipCode'] ?? ''))])),
                            $addr['country'] ?? null,
                          ]));
                        @endphp
                        @if(!empty($addr['fullAddress']))
                          <div style="margin-top:8px;"><button type="button" class="wo-btn" style="width:auto;display:inline-block;font-size:12px;padding:4px 10px;" data-addr="{{ $addrText }}" onclick="woCopyAddr(this)">Copy address</button></div>
                        @endif
                        {{-- Ship instead of pickup, or fix the address (Sarah, 2026-10-16) --}}
                        @php $isPickupO = ($o['fulfillment_method'] ?? null) === 'pickup'; @endphp
                        <details style="margin-top:8px;">
                          <summary style="cursor:pointer;font-size:12px;color:#4a6fa5;">{{ $isPickupO ? 'Ship instead / edit address' : 'Edit address' }}</summary>
                          <form method="POST" action="{{ route('website-orders.saveFulfillment', ['id' => $o['_id']]) }}" style="display:grid;gap:6px;margin-top:8px;max-width:340px;">
                            {{ csrf_field() }}
                            <select name="method" style="padding:6px;">
                              <option value="shipping" {{ $isPickupO ? '' : 'selected' }}>Ship it (no shipping charge)</option>
                              <option value="hollywood" {{ $isPickupO && ($o['pickup_location'] ?? '') === 'hollywood' ? 'selected' : '' }}>Pickup at Hollywood</option>
                              <option value="pico" {{ $isPickupO && ($o['pickup_location'] ?? '') === 'pico' ? 'selected' : '' }}>Pickup at Pico</option>
                            </select>
                            <input name="name" placeholder="Name" value="{{ $addr['name'] ?? ($o['display_name'] ?? ($o['user_id']['name'] ?? '')) }}" style="padding:6px;">
                            <input name="fullAddress" placeholder="Street address" value="{{ $addr['fullAddress'] ?? '' }}" style="padding:6px;">
                            <input name="apartment" placeholder="Apt / unit" value="{{ $addr['apartment'] ?? '' }}" style="padding:6px;">
                            <div style="display:flex;gap:6px;">
                              <input name="city" placeholder="City" value="{{ $addr['city'] ?? '' }}" style="padding:6px;flex:2;min-width:0;">
                              <input name="state" placeholder="State" value="{{ $addr['state'] ?? '' }}" style="padding:6px;flex:1;min-width:0;">
                              <input name="zipCode" placeholder="Zip" value="{{ $addr['zipCode'] ?? '' }}" style="padding:6px;flex:1;min-width:0;">
                            </div>
                            <input name="country" placeholder="Country" value="{{ $addr['country'] ?? 'US' }}" style="padding:6px;">
                            <button type="submit" class="wo-btn wo-btn-primary" style="width:auto;">Save</button>
                          </form>
                        </details>
                      </div>
                    @endif
                    <div style="flex:1 1 100%;">
                      <h5>What they ordered</h5>
                      <table class="wo-detail-items">
                        <thead><tr>
                          <th></th><th>Name</th><th>Artist</th><th>Format</th><th>Genre</th><th>Store</th><th>Discogs code</th><th>Location</th><th>Qty</th><th>Price</th>
                        </tr></thead>
                        <tbody>
                          @foreach(($o['items'] ?? []) as $item)
                            @php
                              $itemIsGift = ($item['is_gift_card'] ?? false) === true || (empty($item['product_id']) && !empty($item['gift_card_amount']));
                              $img = $itemIsGift ? null : ($item['product_id']['image'] ?? $item['product_image'] ?? null);
                              $loc = $item['location'] ?? ($item['product_id']['location'] ?? null);
                              $locStore = is_string($loc) ? $loc : trim(implode(' - ', array_filter([
                                $loc['store'] ?? null, $loc['zone'] ?? null, $loc['location_store'] ?? null,
                              ])));
                              $locSub = is_array($loc) ? ($loc['sub_location'] ?? null) : null;
                              $discogs = $item['discogs_code'] ?? ($item['product_id']['discogsLocation'] ?? null);
                              $hasLoc = $locStore !== '';
                              $inStoreBins = $hasLoc && !$discogs && (stripos($locStore, 'hollywood') !== false || stripos($locStore, 'pico') !== false);
                              $isPreorder = !$itemIsGift && !empty($item['product_id']['isPreorder']);
                              $shipDate = $item['product_id']['preorderShipDate'] ?? null;
                              // Website storefront link — same _id/slug scheme as the
                              // website's own order-detail page (getProductUrl()).
                              $websiteId = $item['product_id']['_id'] ?? $item['product_sku'] ?? null;
                              $websiteSlug = $item['product_slug'] ?? ($item['product_id']['slug'] ?? null);
                              $websiteUrl = (!$itemIsGift && $websiteId && $websiteSlug) ? "https://nivessa.com/products/{$websiteId}/{$websiteSlug}" : null;
                              // ERP catalog record — posProductId is the website's copy of
                              // the ERP's own product primary key (see Product model).
                              $erpProductId = $item['product_id']['posProductId'] ?? null;
                            @endphp
                            <tr>
                              <td style="width:52px;">
                                @if($itemIsGift)
                                  <div style="width:44px;height:44px;border-radius:6px;background:#dcfce7;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#166534;">GC</div>
                                @elseif($img)
                                  <img src="{{ $img }}" alt="" style="width:44px;height:44px;object-fit:cover;border-radius:6px;border:1px solid #e5e7eb;">
                                @else
                                  <div style="width:44px;height:44px;border-radius:6px;background:#f3f4f6;"></div>
                                @endif
                              </td>
                              <td>
                                @if($itemIsGift)
                                  <span style="color:#166534;font-weight:600;">Nivessa Gift Card - ${{ $item['gift_card_amount'] ?? $item['price'] ?? '' }}</span>
                                @else
                                  @if(!empty($item['cancelled']))<s>@endif @if($erpProductId)<a class="wo-prod-name" href="{{ url('/products/' . $erpProductId . '/edit') }}" target="_blank" rel="noopener">{{ $item['product_id']['name'] ?? ($item['product_name'] ?? 'Unknown item') }}</a>@else{{ $item['product_id']['name'] ?? ($item['product_name'] ?? 'Unknown item') }}<span class="wo-noerp">not in ERP</span>@endif @if(!empty($item['cancelled']))</s> <span class="wo-pill st-cancelled">Cancelled</span>@endif
                                  @if($isPreorder)
                                    <span style="display:inline-block;margin-left:4px;padding:1px 7px;border-radius:999px;background:#f97316;color:#fff;font-size:10px;font-weight:700;">Preorder</span>
                                    @if($shipDate)
                                      <div style="font-size:11px;color:#c2410c;">Ships {{ gmdate('M j, Y', strtotime($shipDate)) }}</div>
                                    @endif
                                  @endif
                                  <div style="font-size:11px;margin-top:3px;">
                                    @if($websiteUrl)
                                      <a href="{{ $websiteUrl }}" target="_blank" rel="noopener" style="color:#4a6fa5;">Website</a>
                                    @endif
                                    @if($websiteUrl && $erpProductId) &middot; @endif
                                    @if($erpProductId)
                                      <a href="{{ url('/products/' . $erpProductId . '/edit') }}" target="_blank" rel="noopener" style="color:#4a6fa5;">ERP</a>
                                    @endif
                                  </div>
                                  @php $listed = \App\Http\Controllers\WebsiteOrdersController::listedLabel($item['product_id']['posCreatedAt'] ?? ($item['product_id']['createdAt'] ?? null)); @endphp
                                  @if($listed)
                                    <div style="font-size:11px;color:#000;">{{ $listed }}</div>
                                  @endif
                                @endif
                              </td>
                              <td>{{ $itemIsGift ? '—' : ($item['product_id']['artist'] ?? ($item['product_artist'] ?? '—')) }}</td>
                              <td>{{ $itemIsGift ? '—' : ($item['product_id']['subCategory'] ?? ($item['product_subCategory'] ?? '—')) }}</td>
                              <td>{{ $itemIsGift ? '—' : ($item['product_id']['genre'] ?? ($item['product_genre'] ?? '—')) }}</td>
                              <td>
                                @if($itemIsGift)
                                  —
                                @elseif($locStore !== '')
                                  {{ $locStore }}@if($locSub)<br><span style="color:#000;">&rarr; {{ $locSub }}</span>@endif
                                @else
                                  <span style="color:#000;font-style:italic;">Not set</span>
                                @endif
                              </td>
                              <td style="font-family:monospace;font-size:12px;">{{ $itemIsGift ? '—' : ($discogs ?: '—') }}</td>
                              <td>
                                @if($itemIsGift)
                                  —
                                @elseif($inStoreBins)
                                  <span style="color:#15803d;font-weight:600;">In Store Bins</span>
                                @else
                                  <span style="color:#000;">—</span>
                                @endif
                              </td>
                              <td>{{ $item['quantity'] ?? 1 }}</td>
                              <td>${{ number_format((float) ($item['price'] ?? 0), 2) }}</td>
                            </tr>
                          @endforeach
                        </tbody>
                      </table>
                    </div>
                    @if(!empty($o['appliedVouchers']))
                      <div style="flex:1 1 100%;">
                        <h5>Vouchers</h5>
                        @foreach($o['appliedVouchers'] as $v)
                          {{ $v['code'] ?? '—' }} (&minus;${{ number_format((float) ($v['discount'] ?? 0), 2) }})@if(!$loop->last), @endif
                        @endforeach
                      </div>
                    @endif
                  </div>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
        </div>
      </div>
    @endif

    @if(in_array($activeTab, ['to_ship', 'ready_ship'], true))
      {{-- Discogs orders to ship (Sarah, 2026-10-12): marked shipped here, which
           sets Shipped on Discogs and sends the buyer the tracking number.
           Need to Pull shows what's left to pull; fully pulled ones are on Pack and ship (2026-10-21). --}}
      <h3 style="margin:22px 0 10px;font-size:16px;font-weight:700;color:#111827;">
        <span class="wo-pill" style="background:#fef3c7;color:#92400e;">Discogs</span>
        {{ $activeTab === 'ready_ship' ? 'Discogs orders to pack and ship' : 'Discogs orders to pull' }} ({{ count($discogsToShip ?? []) }})
      </h3>
      @if(!empty($discogsError))
        <div class="alert-err">{{ $discogsError }}</div>
      @elseif(empty($discogsToShip))
        <div class="ev-card"><div class="empty">{{ $activeTab === 'ready_ship' ? 'No Discogs orders fully pulled yet.' : 'No Discogs items left to pull.' }}</div></div>
      @else
        <div class="wo-card">
          <div class="wo-scroll">
          <table class="wo-tbl">
            <colgroup>
              <col style="width:100px">
              <col style="width:12%">
              <col>
              <col style="width:340px">
              <col style="width:108px">
              <col style="width:118px">
              <col style="width:112px">
              <col style="width:86px">
              <col style="width:236px">
            </colgroup>
            <thead><tr>
              <th>@include('website-orders.partials.sort-th', ['label' => 'Placed', 'key' => 'placed'])</th>
              <th>@include('website-orders.partials.sort-th', ['label' => 'Buyer', 'key' => 'buyer'])</th>
              <th>@include('website-orders.partials.sort-th', ['label' => 'Product', 'key' => 'product'])</th>
              <th>Location</th>
              <th>@include('website-orders.partials.sort-th', ['label' => 'Listed', 'key' => 'listed'])</th>
              <th>@include('website-orders.partials.sort-th', ['label' => 'Status', 'key' => 'status'])</th>
              <th>Fulfillment</th>
              <th>@include('website-orders.partials.sort-th', ['label' => 'Total', 'key' => 'total'])</th>
              <th>Actions</th>
            </tr></thead>
            <tbody>
              {{-- Same columns and look as the website order rows (Sarah, 2026-10-21) --}}
              @foreach($discogsToShip as $d)
                @php
                  $dListed = \App\Http\Controllers\WebsiteOrdersController::listedShort(collect($d['items'] ?? [])->map(fn($it) => $it['listed_at'] ?? null)->filter()->sort()->first());
                  $dPick = $d['pick'] ?? null;
                  $dPickLines = [];
                  foreach (($dPick['lines'] ?? []) as $pl) $dPickLines[strtolower(trim((string) ($pl['name'] ?? '')))] = $pl;
                  $dDays = !empty($d['order_date']) ? (int) floor((time() - strtotime($d['order_date'])) / 86400) : 0;
                @endphp
                <tr class="wo-row">
                  <td class="wo-placed">
                    @if(!empty($d['order_date'])){{ date('M j, Y', strtotime($d['order_date'])) }}<span class="t">{{ date('g:ia', strtotime($d['order_date'])) }}</span>@else &mdash; @endif
                    @if($dDays >= 5)<span class="wo-due over">{{ $dDays }} days old</span>@endif
                  </td>
                  <td>
                    <span class="wo-pill" style="background:#fef3c7;color:#92400e;margin-bottom:5px;">Discogs</span>
                    <div class="wo-buyer-name">{{ $d['buyer'] ?? '' }}</div>
                    <div class="wo-buyer-email"><a href="{{ $d['url'] ?? '#' }}" target="_blank" rel="noopener">Order {{ $d['id'] }}</a></div>
                  </td>
                  @php
                    // Need to Pull: only what's still to pull (Sarah, 2026-10-21)
                    $dShow = function ($it) use ($activeTab, $d) { return $activeTab !== 'to_ship' || array_key_exists(strtolower(trim((string) ($it['name'] ?? ''))), $d['_left'] ?? []); };
                    $dHidden = count(array_filter($d['items'] ?? [], fn($it) => !$dShow($it)));
                  @endphp
                  <td>
                    @foreach(($d['items'] ?? []) as $it)
                      @continue(!$dShow($it))
                      @php $dMedia = \App\Http\Controllers\WebsiteOrdersController::mediaTypeLabel($it['product_type'] ?? null); $dCond = \App\Http\Controllers\WebsiteOrdersController::conditionOf($erpLocations[(int) ($it['erp_product_id'] ?? 0)]['genre'] ?? null, null); @endphp
                      <div class="wo-prod-line wo-prod-with-img">
                        @if(!empty($it['image']))<img class="wo-prod-img" src="{{ $it['image'] }}" alt="" loading="lazy">@else<div class="wo-prod-img"></div>@endif
                        <div>
                          @if(!empty($it['erp_product_id']))<a class="wo-prod-name" href="{{ url('/products/' . $it['erp_product_id'] . '/edit') }}" target="_blank" rel="noopener">{{ $it['name'] ?? 'Item' }}</a>@else{{ $it['name'] ?? 'Item' }}<span class="wo-noerp">not in ERP</span>@endif
                          @if($dCond)<span class="wo-cond {{ $dCond }}">{{ strtoupper($dCond) }}</span>@endif
                          @if($dMedia)<span class="wo-tag media">{{ $dMedia }}</span>@endif
                          @if(!empty($it['format']))<span class="wo-tag fmt">{{ $it['format'] }}</span>@endif
                          <div class="wo-prod-price">{{ $it['quantity'] ?? 1 }}@if(!empty($it['price'])) &times; ${{ number_format((float) $it['price'], 2) }}@endif</div>
                          @if(!empty($it['artist']))<div class="wo-prod-artist">{{ $it['artist'] }}</div>@endif
                        </div>
                      </div>
                    @endforeach
                    @if($dHidden > 0)<div style="font-size:12px;color:#000;margin-top:4px;">+{{ $dHidden }} already picked</div>@endif
                  </td>
                  <td class="wo-loc @if(!empty($dPick['order_id'])) wo-pick @endif" @if(!empty($dPick['order_id'])) data-order="{{ $dPick['order_id'] }}" @endif>
                    @foreach(($d['items'] ?? []) as $it)
                      @continue(!$dShow($it))
                      @php
                        $dPid = (int) ($it['erp_product_id'] ?? 0);
                        $pull = \App\Http\Controllers\WebsiteOrdersController::pullParts(['discogs_code' => $it['location'] ?? null, 'product_id' => ['posProductId' => $dPid]], $dPid ? ($erpLocations[$dPid] ?? null) : null);
                        $pl = $dPickLines[strtolower(trim((string) ($it['name'] ?? '')))] ?? null;
                      @endphp
                      <div class="wo-loc-line">
                        @if($pull['place'] === '' && $pull['area'] === '' && $pull['bin'] === '')
                          <span class="none">Not set</span>
                        @else
                          @if($pull['place'] !== '')<span class="place">{{ $pull['place'] }}</span>@endif
                          @if($pull['area'] !== '')<span class="area">{{ $pull['area'] }}</span>@endif
                          @if(($pull['genre'] ?? '') !== '')<span class="area">{{ $pull['genre'] }}</span>@endif
                          @if($pull['bin'] !== '')<span class="binv">Bin {{ $pull['bin'] }}</span>@endif
                        @endif
                        @if($pl)
                          @php
                            $plStatus = $pl['status'] ?? 'Not Picked';
                            $plDone = in_array($plStatus, ['Picked', 'Packed', 'Shipped', 'Dropshipped'], true);
                            $plMissing = $plStatus === "Can't Find";
                          @endphp
                          <div class="wo-pick-line wo-pick-inline {{ $plDone ? 'is-done' : ($plMissing ? 'is-missing' : '') }}" data-item="{{ $pl['item_id'] }}" data-qty="{{ (int) ($pl['quantity'] ?? 1) }}">
                            <div class="wo-pick-state">{{ $plDone ? $plStatus . (!empty($pl['picked_by']) ? ' by ' . $pl['picked_by'] : '') : ($plMissing ? "Can't find" . (!empty($pl['picked_by']) ? ' (' . $pl['picked_by'] . ')' : '') : ($plStatus === 'Pending Arrival' ? 'Pending arrival' : 'Not picked yet')) }}</div>
                            <div class="wo-pick-btns">
                              @if($plDone || $plMissing)
                                <button type="button" class="undo" onclick="woPick(this, 'Not Picked')">Undo</button>
                              @else
                                <button type="button" class="go" onclick="woPick(this, 'Picked')">Mark picked</button><button type="button" class="cf" onclick="woPick(this, 'Can\'t Find')">Can't find</button><button type="button" class="ds" onclick="woPick(this, 'Dropshipped')">Dropshipped</button>
                              @endif
                            </div>
                          </div>
                        @endif
                      </div>
                    @endforeach
                  </td>
                  <td class="wo-listed">{{ explode(' · ', (string) ($dListed ?? ''), 2)[0] }}@if(count(explode(' · ', (string) ($dListed ?? ''), 2)) > 1)<span class="t">{{ explode(' · ', (string) ($dListed ?? ''), 2)[1] }}</span>@endif</td>
                  <td><span class="wo-pill st-processing">{{ $d['status'] ?? 'Payment Received' }}</span></td>
                  <td><span class="wo-fm-pill shipping">Shipping</span><div class="wo-shipfrom">Discogs order</div></td>
                  <td class="wo-total">${{ number_format((float) ($d['total'] ?? 0), 2) }}</td>
                  <td class="wo-actions-cell">
                    <form method="POST" action="{{ route('website-orders.shipDiscogs', ['id' => $d['id']]) }}"
                          onsubmit="var b=this.querySelector('button'); b.disabled=true; b.textContent='Marking...';">
                      {{ csrf_field() }}
                      <input type="text" name="tracking_number" required placeholder="Tracking number"
                             style="width:100%;font-size:13px;padding:7px 9px;border:1px solid #d1d5db;border-radius:7px;box-sizing:border-box;margin-bottom:6px;">
                      <button type="submit" class="wo-btn wo-btn-primary">Mark shipped</button>
                    </form>
                    <a class="wo-btn" href="{{ $d['url'] ?? '#' }}" target="_blank" rel="noopener" style="margin-top:6px;display:inline-block;">Open on Discogs</a>
                    @if(!empty($dPick['total']))
                      <div class="wo-pick-count" style="margin-top:8px;font-size:13px;font-weight:700;color:#000;"><span class="n">{{ (int) ($dPick['picked'] ?? 0) }}</span> of {{ (int) $dPick['total'] }} picked</div>
                    @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
          </div>
        </div>
      @endif

      {{-- Discogs orders waiting on the buyer (Sarah, 2026-10-14): we told them
           we can't find the item and they haven't answered. Nothing to ship;
           each needs a refund or substitute on Discogs. --}}
      @if(!empty($discogsWaitingError))
        <div class="alert-err" style="margin-top:16px;">{{ $discogsWaitingError }}</div>
      @endif
      @if(!empty($discogsWaiting))
        <h3 style="margin:22px 0 10px;font-size:16px;font-weight:700;color:#111827;">
          <span class="wo-pill" style="background:#fee2e2;color:#991b1b;">Discogs</span>
          Waiting on buyer / needs refund ({{ count($discogsWaiting) }})
        </h3>
        <div style="font-size:13px;color:#000;margin:-4px 0 10px;">We told these buyers we can't find the item and they haven't replied. Not to ship: refund or send a substitute on Discogs.</div>
        <div class="wo-card">
          <div class="wo-scroll">
          <table class="wo-tbl">
            <thead><tr>
              <th>Discogs order</th><th>Buyer</th><th>Items</th><th>Total</th><th>Ordered</th><th>Waiting</th>
            </tr></thead>
            <tbody>
              @foreach($discogsWaiting as $d)
                @php $wDays = $d['days_waiting'] ?? null; @endphp
                <tr class="wo-row">
                  <td><a href="{{ $d['url'] ?? '#' }}" target="_blank" rel="noopener">{{ $d['id'] }}</a></td>
                  <td><div class="wo-buyer-name">{{ $d['buyer'] ?? '' }}</div></td>
                  <td style="font-size:13px;">
                    @foreach(($d['items'] ?? []) as $it)
                      <div class="wo-prod-line">{{ $it['name'] ?? 'Item' }}</div>
                    @endforeach
                  </td>
                  <td class="wo-total">${{ number_format((float) ($d['total'] ?? 0), 2) }}</td>
                  <td class="wo-placed">@if(!empty($d['order_date'])){{ date('M j, Y', strtotime($d['order_date'])) }}@endif</td>
                  <td style="font-weight:600;white-space:nowrap;color:{{ ($wDays ?? 0) >= 7 ? '#b91c1c' : '#92400e' }};">
                    @if($wDays !== null){{ $wDays }} {{ $wDays === 1 ? 'day' : 'days' }}@else &mdash; @endif
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
          </div>
        </div>
      @endif
    @endif

    {{-- Shared "Update status" dialog — one dialog reused for every row, retargeted by
         woOpenStatus() rather than one dialog per order (keeps the DOM small). --}}
    <dialog id="wo-status-dialog" class="wo-dialog">
      <form method="POST" id="wo-status-form" class="wo-dialog-body">
        {{ csrf_field() }}
        <input type="hidden" name="tab" value="{{ $activeTab }}">
        <h3>Update order status</h3>
        <label>Status</label>
        <select name="status" id="wo-status-select" required>
          <option value="">Choose a status…</option>
          @foreach($statuses as $key => $label)
            <option value="{{ $key }}">{{ $label }}</option>
          @endforeach
        </select>
        <label>Tracking number</label>
        <input type="text" name="tracking_number" id="wo-status-tracking" placeholder="Required if Shipped">
        <label class="wo-dialog-checkline" style="text-transform:none;">
          <input type="checkbox" name="notify_customer" value="1" checked> Notify the customer by email
        </label>
        <div class="wo-dialog-actions">
          <button type="button" class="wo-btn" onclick="document.getElementById('wo-status-dialog').close()">Cancel</button>
          <button type="submit" class="wo-btn wo-btn-primary">Update</button>
        </div>
      </form>
    </dialog>

    {{-- Shared "Cancel order" dialog — same reuse pattern. --}}
    <dialog id="wo-cancel-dialog" class="wo-dialog">
      <form method="POST" id="wo-cancel-form" class="wo-dialog-body"
            onsubmit="return woConfirmCancel(this);">
        {{ csrf_field() }}
        <h3>Cancel &amp; refund</h3>
        {{-- Made deliberately hard to do by accident (Sarah, 2026-10-16): who,
             how much, and a typed CANCEL before the button works. --}}
        <div id="wo-cancel-who" style="margin:0 0 10px;padding:8px 10px;background:#f3f4f6;border-radius:6px;font-size:14px;color:#000;"></div>
        <div style="margin:0 0 12px;padding:8px 10px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;font-size:13px;color:#7f1d1d;">This <strong>refunds the customer's card right away</strong> and <strong>emails them</strong>. It can't be undone.</div>
        <label>1. Tick the items to cancel</label>
        <div id="wo-cancel-items" style="display:grid;gap:6px;margin-bottom:12px;font-size:14px;"></div>
        <input type="hidden" name="item_select" id="wo-cancel-item-select" value="0">
        <div id="wo-cancel-sum" style="margin:-4px 0 12px;font-size:14px;font-weight:700;color:#000;">Nothing ticked yet.</div>
        <label>2. Reason (goes in the customer's email)</label>
        <select name="reason" required>
          <option value="">Choose a reason…</option>
          @foreach($reasons as $key => $label)
            <option value="{{ $key }}">{{ $label }}</option>
          @endforeach
        </select>
        <label>3. Note (required if Other, shown to the customer)</label>
        <input type="text" name="note" placeholder="Optional detail for the customer email">
        <label>4. Reason for Jon (internal, NOT sent to the customer)</label>
        <textarea name="internal_note" id="wo-cancel-internal" rows="3" placeholder="What happened and why we refunded, e.g. couldn't find it at Pico after checking the bins twice" oninput="woCancelReady()" style="width:100%;font-size:14px;padding:8px;border:1px solid #d1d5db;border-radius:6px;color:#000;"></textarea>
        <input type="hidden" name="item_names" id="wo-cancel-item-names" value="">
        <label>5. Type CANCEL to confirm</label>
        <input type="text" id="wo-cancel-typed" autocomplete="off" placeholder="CANCEL" oninput="woCancelReady()">
        <div class="wo-dialog-actions">
          <button type="button" class="wo-btn" onclick="document.getElementById('wo-cancel-dialog').close()">Keep the order</button>
          <button type="submit" class="wo-btn wo-btn-danger" disabled>Cancel &amp; refund</button>
        </div>
      </form>
    </dialog>

    <script>
      function woCopyAddr(btn) {
        var t = btn.getAttribute('data-addr') || '';
        var done = function () { var o = btn.textContent; btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = o; }, 1500); };
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(t).then(done, function () {}); }
        else { var ta = document.createElement('textarea'); ta.value = t; document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (e) {} ta.remove(); }
      }
      function woCloseMore() {
        document.querySelectorAll('.wo-more.open').forEach(function (m) { m.classList.remove('open'); });
      }
      function woToggleMore(btn, ev) {
        if (ev) ev.stopPropagation();
        var box = btn.closest('.wo-more');
        var wasOpen = box.classList.contains('open');
        woCloseMore();
        if (wasOpen) return;
        box.classList.add('open');
        // Fixed position so the table's overflow wrapper can't clip it.
        var menu = box.querySelector('.wo-more-menu');
        var r = btn.getBoundingClientRect();
        menu.style.left = Math.max(8, r.right - menu.offsetWidth) + 'px';
        var below = r.bottom + 4;
        menu.style.top = (below + menu.offsetHeight > window.innerHeight ? Math.max(8, r.top - menu.offsetHeight - 4) : below) + 'px';
      }
      window.addEventListener('scroll', woCloseMore, true);
      document.addEventListener('click', function (e) { if (!e.target.closest('.wo-more')) woCloseMore(); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape') woCloseMore(); });
      function woToggleDetails(btn) {
        var row = btn.closest('tr');
        var el = row ? row.nextElementSibling : null;
        while (el && !(el.classList && el.classList.contains('wo-detail'))) {
          if (el.classList && el.classList.contains('wo-row')) { el = null; break; }
          el = el.nextElementSibling;
        }
        if (!el) return;
        var hidden = el.style.display === 'none' || getComputedStyle(el).display === 'none';
        el.style.display = hidden ? 'table-row' : 'none';
        btn.textContent = hidden ? 'Hide' : 'Details';
      }
      function woOpenStatus(orderId, currentStatus, currentTracking) {
        var form = document.getElementById('wo-status-form');
        form.action = '{{ url("/website-orders") }}/' + orderId + '/status';
        form.dataset.orderId = orderId;
        document.getElementById('wo-status-select').value = currentStatus || '';
        document.getElementById('wo-status-tracking').value = currentTracking || '';
        document.getElementById('wo-status-dialog').showModal();
      }
      // Picking "Cancelled" here hands off to the Cancel order dialog, which
      // asks for the reason and sends the refund + apology email. A plain
      // status change to cancelled told the customer nothing.
      document.getElementById('wo-status-select').addEventListener('change', function () {
        if (this.value !== 'cancelled') return;
        var orderId = document.getElementById('wo-status-form').dataset.orderId;
        document.getElementById('wo-status-dialog').close();
        woOpenCancel(orderId);
      });
      function woConfirmCancel(form) {
        var boxes = form.querySelectorAll('input[name="item_ids[]"]');
        var picked = form.querySelectorAll('input[name="item_ids[]"]:checked:not(:disabled)');
        var open = form.querySelectorAll('input[name="item_ids[]"]:not(:disabled)');
        if (!boxes.length) {
          alert('The items did not load, so nothing was cancelled. Reload the page and try again.');
          return false;
        }
        if (!picked.length) {
          alert('Tick at least one item to cancel.');
          return false;
        }
        // Same rule as the website: it's only a whole-order cancel when
        // nothing else is left, and a gift card line counts as left (it is
        // never cancelled or refunded here).
        var active = parseInt(form.dataset.active || '0', 10);
        var giftLeft = parseInt(form.dataset.giftLeft || '0', 10);
        // Typed CANCEL replaces the browser pop-up (Sarah, 2026-10-16)
        var ok = (document.getElementById('wo-cancel-typed').value || '').trim().toUpperCase() === 'CANCEL';
        if ((document.getElementById('wo-cancel-internal').value || '').trim().length < 10) { alert('Write the reason for Jon in step 4.'); return false; }
        if (!ok) { alert('Type CANCEL in step 5 to confirm.'); return false; }
        document.getElementById('wo-cancel-item-names').value = Array.prototype.map.call(picked, function (cb) { return cb.parentNode.querySelector('span').childNodes[0].textContent.trim(); }).join('||');
        if (ok) {
          // One submit only: a second click used to send a second cancel.
          var btn = form.querySelector('button[type="submit"]');
          if (btn) { btn.disabled = true; btn.textContent = 'Cancelling...'; }
        }
        return ok;
      }
      function woOpenCancel(orderId, btn) {
        var form = document.getElementById('wo-cancel-form');
        form.action = '{{ url("/website-orders") }}/' + orderId + '/cancel';
        form.reset();
        var box = document.getElementById('wo-cancel-items');
        box.innerHTML = '';
        var lines = [];
        try { lines = JSON.parse((btn && btn.getAttribute('data-lines')) || '[]'); } catch (e) {}
        if (!btn) {
          // From Update status -> Cancelled: find this order's Cancel button for its items
          var b = document.querySelector('button.wo-more-item.danger[data-order="' + orderId + '"]');
          try { lines = JSON.parse((b && b.getAttribute('data-lines')) || '[]'); } catch (e) {}
        }
        if (!lines.length) {
          // Never open the dialog without the items: it would cancel the whole order.
          alert('Could not load this order\'s items. Reload the page and use the order\'s ... menu > Cancel order.');
          return;
        }
        document.getElementById('wo-cancel-item-select').value = lines.length ? '1' : '0';
        form.dataset.active = String(lines.filter(function (l) { return !l.cancelled; }).length);
        form.dataset.giftLeft = String(lines.filter(function (l) { return !l.cancelled && l.gift; }).length);
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Cancel & refund'; }
        lines.forEach(function (l) {
          var row = document.createElement('label');
          row.style.cssText = 'display:flex;gap:8px;align-items:flex-start;text-transform:none;font-weight:400;margin:0;padding:6px 8px;border:1px solid #e5e7eb;border-radius:6px;';
          var cb = document.createElement('input');
          cb.type = 'checkbox';
          cb.name = 'item_ids[]';
          cb.value = l.id;
          cb.checked = false;
          cb.disabled = l.cancelled || l.gift;
          var text = document.createElement('span');
          text.textContent = l.name + (l.qty > 1 ? ' x' + l.qty : '') + ' ($' + (l.price * l.qty).toFixed(2) + ')' +
            (l.cancelled ? ' - already cancelled' : l.gift ? ' - gift card, can\'t cancel here' : '');
          if (l.spot) {
            var where = document.createElement('span');
            where.style.cssText = 'display:block;font-size:12px;color:#000;font-weight:600;';
            where.textContent = l.spot;
            text.appendChild(where);
          }
          cb.dataset.store = l.store || '';
          cb.addEventListener('change', woCancelReady);
          row.appendChild(cb);
          row.appendChild(text);
          box.appendChild(row);
        });
        var src = btn || document.querySelector('button.wo-more-item.danger[data-order="' + orderId + '"]');
        form.dataset.total = (src && src.dataset.total) || '';
        form.dataset.split = (src && src.dataset.split) || '0';
        document.getElementById('wo-cancel-who').textContent = 'Customer: ' + ((src && src.dataset.who) || '?') +
          ((src && src.dataset.num) ? '  ·  Order #' + src.dataset.num : '') + '  ·  Paid $' + (form.dataset.total || '?');
        document.getElementById('wo-cancel-typed').value = '';
        document.getElementById('wo-cancel-internal').value = '';
        woCancelReady();
        document.getElementById('wo-cancel-dialog').showModal();
      }
    </script>
  @endif

  {{-- Pick buttons: save to the website's pick list and update the row in place
       so picking several items doesn't reload the page each tap. --}}
  
<div id="wo-sub-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:2000;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto;" onclick="if (event.target === this) woSubClose()">
  <div style="background:#fff;border-radius:12px;max-width:620px;width:100%;padding:20px;box-sizing:border-box;color:#000;">
    <div style="font-size:19px;font-weight:800;margin-bottom:4px;">Substitute</div>
    <div style="font-size:14px;margin-bottom:12px;">Only after the customer said yes. Replacing <strong id="wo-sub-what"></strong>. They keep the price they paid; refund any difference separately.</div>
    <input id="wo-sub-q" type="text" placeholder="Search the ERP: title, artist or SKU" oninput="woSubSearch(this.value)" style="width:100%;font-size:15px;padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;box-sizing:border-box;">
    <div id="wo-sub-results" style="max-height:300px;overflow:auto;margin-top:8px;"></div>
    <div id="wo-sub-picked" style="margin-top:10px;font-size:14px;font-weight:700;"></div>
    <textarea id="wo-sub-note" rows="2" placeholder="How the customer agreed, e.g. Texted 10/21, OK with the 2019 repress" style="width:100%;margin-top:10px;font-size:14px;padding:9px 12px;border:1.5px solid #d1d5db;border-radius:8px;box-sizing:border-box;"></textarea>
    <div id="wo-sub-msg" style="margin-top:8px;font-size:14px;font-weight:700;color:#1e40af;"></div>
    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;">
      <button type="button" class="wo-btn" onclick="woSubClose()">Close</button>
      <button type="button" id="wo-sub-save" class="wo-btn wo-btn-primary" onclick="woSubSave()">Substitute</button>
    </div>
  </div>
</div>
<style>
  .wo-sub-row { display:block; width:100%; text-align:left; padding:9px 11px; margin-bottom:6px; border:1.5px solid #e5e7eb; border-radius:8px; background:#fff; color:#000; cursor:pointer; font-size:14px; }
  .wo-sub-row .m { display:block; font-size:12.5px; margin-top:2px; color:#000; }
  .wo-sub-row:hover { border-color:#93c5fd; }
  .wo-sub-row.on { background:#dbeafe; border-color:#93c5fd; }
</style>
<script>
    // Hollywood checked and it isn't there: moves to Can't Find: Decide Refund
    function woHwCheck(btn, name) {
      var box = btn.closest('.wo-pick');
      var line = btn.closest('.wo-pick-line');
      line.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
      fetch('{{ url('/website-orders') }}/' + box.dataset.web + '/hw-check', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        credentials: 'same-origin',
        body: JSON.stringify({ name: name })
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (!res.success) throw new Error(res.message || 'Not saved.');
        line.querySelector('.wo-pick-btns').innerHTML = '<div style="font-size:12px;font-weight:700;color:#b91c1c;">Not at Hollywood either. Moved to Can\'t Find: Decide Refund.</div>';
      }).catch(function (e) { alert('Not saved: ' + e.message); line.querySelectorAll('button').forEach(function (b) { b.disabled = false; }); });
    }

    function woPick(btn, status, all) {
      var box = btn.closest('.wo-pick');
      var line = btn.closest('.wo-pick-line');
      var url = '{{ url('/website-orders/pick') }}/' + box.dataset.order + '/' + (all ? 'all' : line.dataset.item);
      var btns = (all ? box : line).querySelectorAll('button');
      btns.forEach(function (b) { b.disabled = true; });
      window.woPickQuietUntil = Date.now() + 30000;
      fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        credentials: 'same-origin',
        body: JSON.stringify({ status: status })
      }).then(function (r) { return r.json().catch(function () { return { success: false }; }); })
        .then(function (res) {
          if (!res.success) throw new Error(res.message || 'Not saved.');
          var who = @json(trim(auth()->user()->first_name . ' ' . auth()->user()->last_name) ?: auth()->user()->username);
          var lines = all ? box.querySelectorAll('.wo-pick-line:not(.is-missing)') : [line];
          lines.forEach(function (l) { woPickPaint(l, status, who); });
          woPickCount(box);
        })
        .catch(function (e) { alert('Pick not saved: ' + e.message); btns.forEach(function (b) { b.disabled = false; }); });
    }
    // Substitute box (Sarah, 2026-10-21)
    var woSub = { order: null, item: null, pick: null, t: null };
    function woSubOpen(orderId, itemId, name, buyer) {
      woSub.order = orderId; woSub.item = itemId; woSub.pick = null;
      document.getElementById('wo-sub-what').textContent = name + ' for ' + buyer;
      document.getElementById('wo-sub-q').value = '';
      document.getElementById('wo-sub-note').value = '';
      document.getElementById('wo-sub-results').innerHTML = '';
      document.getElementById('wo-sub-picked').textContent = 'Nothing picked yet';
      document.getElementById('wo-sub-msg').textContent = '';
      document.getElementById('wo-sub-save').disabled = false;
      document.getElementById('wo-sub-modal').style.display = 'flex';
      setTimeout(function () { document.getElementById('wo-sub-q').focus(); }, 50);
    }
    function woSubClose() { document.getElementById('wo-sub-modal').style.display = 'none'; }
    function woSubSearch(q) {
      clearTimeout(woSub.t);
      woSub.t = setTimeout(function () {
        var box = document.getElementById('wo-sub-results');
        if (q.trim().length < 2) { box.innerHTML = ''; return; }
        box.innerHTML = '<div style="padding:8px;">Searching...</div>';
        fetch('{{ route('website-orders.substituteSearch') }}?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            box.innerHTML = '';
            var rows = (res && res.data) || [];
            if (!rows.length) { box.innerHTML = '<div style="padding:8px;">No records found.</div>'; return; }
            rows.forEach(function (p) {
              var b = document.createElement('button');
              b.type = 'button';
              b.className = 'wo-sub-row';
              var stock = p.stock.length ? p.stock.join(', ') : 'No stock in the ERP';
              b.innerHTML = '<strong></strong><span class="m"></span>';
              b.querySelector('strong').textContent = (p.artist ? p.artist + ' - ' : '') + p.name;
              b.querySelector('.m').textContent = (p.price !== null ? '$' + p.price.toFixed(2) + ' · ' : '') + (p.sku ? 'SKU ' + p.sku + ' · ' : '') + stock;
              b.onclick = function () {
                woSub.pick = p.id;
                document.querySelectorAll('.wo-sub-row').forEach(function (x) { x.classList.remove('on'); });
                b.classList.add('on');
                document.getElementById('wo-sub-picked').textContent = 'Swap for: ' + b.querySelector('strong').textContent;
              };
              box.appendChild(b);
            });
          })
          .catch(function () { box.innerHTML = '<div style="padding:8px;">Search failed, try again.</div>'; });
      }, 250);
    }
    function woSubSave() {
      var msg = document.getElementById('wo-sub-msg');
      var note = document.getElementById('wo-sub-note').value.trim();
      if (!woSub.pick) { msg.textContent = 'Pick the substitute record first.'; return; }
      if (!note) { msg.textContent = 'Say how the customer agreed (text, email, call).'; return; }
      var btn = document.getElementById('wo-sub-save');
      btn.disabled = true; msg.textContent = 'Saving...';
      fetch('{{ url('/website-orders') }}/' + woSub.order + '/items/' + woSub.item + '/substitute', {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
        body: JSON.stringify({ product_id: woSub.pick, note: note })
      }).then(function (r) { return r.json().catch(function () { return { success: false }; }); })
        .then(function (res) {
          msg.textContent = res.message || (res.success ? 'Substituted.' : 'Not saved.');
          if (res.success) { setTimeout(function () { location.reload(); }, 1800); } else { btn.disabled = false; }
        })
        .catch(function () { msg.textContent = 'Not saved, try again.'; btn.disabled = false; });
    }
    function woPickPaint(l, status, who) {
      var done = status === 'Picked' || status === 'Dropshipped', missing = status === "Can't Find";
      l.classList.toggle('is-done', done);
      l.classList.toggle('is-missing', missing);
      var now = new Date();
      var when = (now.getMonth() + 1) + '/' + now.getDate() + ' ' + now.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }).replace(' ', '').toLowerCase();
      l.querySelector('.wo-pick-state').textContent = done ? (status === 'Dropshipped' ? 'Dropshipped' : 'Picked') + ' by ' + who + ' ' + when : (missing ? "Can't find (" + who + ')' : 'Not picked yet');
      var b = l.querySelector('.wo-pick-btns');
      b.innerHTML = (done || missing)
        ? '<button type="button" class="undo">Undo</button>'
        : '<button type="button" class="go">Mark picked</button><button type="button" class="cf">Can\'t find</button><button type="button" class="ds">Dropshipped</button>';
      var bs = b.querySelectorAll('button');
      if (done || missing) { bs[0].onclick = function () { woPick(this, 'Not Picked'); }; }
      else { bs[0].onclick = function () { woPick(this, 'Picked'); }; bs[1].onclick = function () { woPick(this, "Can't Find"); }; bs[2].onclick = function () { woPick(this, 'Dropshipped'); }; }
    }
    function woPickCount(box) {
      var picked = 0, total = 0;
      box.querySelectorAll('.wo-pick-line').forEach(function (l) {
        var q = parseInt(l.dataset.qty, 10) || 1;
        total += q;
        if (l.classList.contains('is-done')) picked += q;
      });
      var c = box.parentNode.querySelector('.wo-pick-count');
      if (c) {
        c.querySelector('.n').textContent = picked;
        var missing = 0;
        box.querySelectorAll('.wo-pick-line.is-missing').forEach(function (l) { missing += parseInt(l.dataset.qty, 10) || 1; });
        var bp = c.querySelector('.wo-prog .p'), bc = c.querySelector('.wo-prog .c');
        if (bp) bp.style.width = (total ? Math.round(100 * Math.min(picked, total) / total) : 0) + '%';
        if (bc) bc.style.width = (total ? Math.round(100 * Math.min(missing, total - Math.min(picked, total)) / total) : 0) + '%';
        var left = Math.max(0, total - picked - missing);
        var sc = c.querySelector('.wo-prog-sub .cf'), sl = c.querySelector('.wo-prog-sub .left');
        if (sc) sc.textContent = missing ? missing + " can't find" : '';
        if (sl) sl.textContent = left ? (missing ? ' · ' : '') + left + ' left' : '';
        c.classList.toggle('full', picked >= total);
        c.classList.toggle('part', picked > 0 && picked < total);
        c.classList.toggle('none', picked === 0);
      }
      var allBtn = box.querySelector('.wo-pick-all');
      if (allBtn) { allBtn.hidden = picked >= total; allBtn.disabled = false; }
    }
  </script>
  {{-- Keep this page from going stale — it only pulled fresh data from the
       website on load/navigation, so a new order or a status change made on
       nivessa.com/admin/orders wouldn't show up until someone manually
       reloaded. Auto-reload every 60s, skipped while a dialog is open or a
       filter field has focus so it never yanks the page out from under
       someone mid-edit. --}}
  <script>
    (function () {
      setInterval(function () {
        var statusDialog = document.getElementById('wo-status-dialog');
        var cancelDialog = document.getElementById('wo-cancel-dialog');
        if ((statusDialog && statusDialog.open) || (cancelDialog && cancelDialog.open)) return;
        var active = document.activeElement;
        if (active && /^(INPUT|SELECT|TEXTAREA)$/.test(active.tagName)) return;
        if (Date.now() < (window.woPickQuietUntil || 0)) return;
        window.location.reload();
      }, 60000);
    })();
  </script>
</div>

<script>
  // Keep each Location block level with its product in the Product column
  // (items have different heights, so the columns drifted apart).
  function woAlignLoc() {
    document.querySelectorAll('tr.wo-row').forEach(function (tr) {
      var prods = tr.querySelectorAll('.wo-prod-line');
      var locs = tr.querySelectorAll('td.wo-loc .wo-loc-line');
      locs.forEach(function (l) { l.style.minHeight = ''; l.style.marginTop = ''; });
      prods.forEach(function (p) { p.style.minHeight = ''; p.style.marginTop = ''; });
      if (!prods.length || prods.length !== locs.length) return;
      // Line each product up with its location: whichever side is lower or
      // taller pushes the other, so pick buttons sit beside their item.
      locs.forEach(function (l, i) {
        var pr = prods[i].getBoundingClientRect();
        var lr = l.getBoundingClientRect();
        var shift = pr.top - lr.top;
        if (shift > 0) l.style.marginTop = shift + 'px';
        else if (shift < 0) prods[i].style.marginTop = (-shift) + 'px';
        var h = Math.max(prods[i].getBoundingClientRect().height, l.getBoundingClientRect().height);
        l.style.minHeight = h + 'px';
        prods[i].style.minHeight = h + 'px';
      });
    });
  }
  window.addEventListener('load', woAlignLoc);
  window.addEventListener('resize', woAlignLoc);
</script>
<script>
  // Shipping paid outside checkout (Sarah, 2026-10-21); total unchanged
  function woShipPaidEdit(id, current) {
    var v = prompt('What did the customer pay for shipping? (This does not change the order total.)', current);
    if (v === null) return;
    fetch('{{ url('/website-orders') }}/' + id + '/shipping-paid', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
      credentials: 'same-origin',
      body: JSON.stringify({ amount: v })
    }).then(function (r) { return r.json().catch(function () { return { success: false }; }); })
      .then(function (res) { if (!res.success) { alert(res.message || 'Not saved.'); return; } location.reload(); });
  }
  // Extra payment taken outside checkout (Sarah, 2026-10-21)
  function woExtraOpen(id) {
    var box = document.getElementById('wo-xpay-' + id);
    box.hidden = false;
    window.woPickQuietUntil = Date.now() + 10 * 60000;
    box.querySelector('.xp-amount').focus();
  }
  function woExtraSave(id, btn) {
    var box = document.getElementById('wo-xpay-' + id);
    var msg = box.querySelector('.xp-msg');
    btn.disabled = true;
    fetch('{{ url('/website-orders') }}/' + id + '/extra-payment', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
      credentials: 'same-origin',
      body: JSON.stringify({
        amount: box.querySelector('.xp-amount').value,
        method: box.querySelector('.xp-method').value,
        payer: box.querySelector('.xp-payer').value,
        note: box.querySelector('.xp-note').value,
        priority: box.querySelector('.xp-priority').checked
      })
    }).then(function (r) { return r.json().catch(function () { return { success: false }; }); })
      .then(function (res) {
        btn.disabled = false;
        if (!res.success) { msg.textContent = res.message || 'Not saved.'; return; }
        window.woPickQuietUntil = 0;
        location.reload();
      }).catch(function () { btn.disabled = false; msg.textContent = 'Not saved. Try again.'; });
  }
  // Per-order notes (Sarah, 2026-10-16): saved on the website order via WebsiteOrdersController::saveOrderNote
  function woNoteOpen(btn) {
    var box = btn.closest('.wo-onote');
    box.querySelector('.wo-onote-edit').hidden = false;
    btn.hidden = true;
    window.woPickQuietUntil = Date.now() + 10 * 60000;
    box.querySelector('textarea').focus();
  }
  function woNoteClose(btn) {
    var box = btn.closest('.wo-onote');
    box.querySelector('.wo-onote-edit').hidden = true;
    box.querySelector('.wo-onote-open').hidden = false;
    window.woPickQuietUntil = 0;
  }
  function woNoteSave(btn) {
    var box = btn.closest('.wo-onote');
    var text = box.querySelector('textarea').value;
    btn.disabled = true;
    fetch('{{ url('/website-orders') }}/' + box.dataset.id + '/note', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
      credentials: 'same-origin',
      body: JSON.stringify({ text: text })
    }).then(function (r) { return r.json().catch(function () { return { success: false }; }); })
      .then(function (res) {
        btn.disabled = false;
        if (!res.success) throw new Error(res.message || 'Not saved.');
        var show = box.querySelector('.wo-onote-show');
        show.querySelector('.txt').textContent = text.trim();
        show.querySelector('.by').textContent = res.by || '';
        show.hidden = text.trim() === '';
        box.querySelector('.wo-onote-open').textContent = text.trim() === '' ? '+ Add note' : 'Edit note';
        woNoteClose(btn);
      })
      .catch(function (e) { btn.disabled = false; alert('Note not saved: ' + e.message); });
  }
</script>
<script>
  // Copy a link to one order, e.g. to paste in Slack (Sarah, 2026-10-16)
  function woCopyLink(btn, url) {
    var done = function () { var t = btn.textContent; btn.textContent = 'Copied!'; setTimeout(function () { btn.textContent = t; }, 1500); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(done, function () { window.prompt('Copy this link:', url); });
    } else {
      window.prompt('Copy this link:', url);
    }
  }
</script>
<script>
  // Cancel box: plain-words refund summary, button only after CANCEL is typed
  function woCancelReady() {
    var form = document.getElementById('wo-cancel-form');
    var picked = form.querySelectorAll('input[name="item_ids[]"]:checked:not(:disabled)');
    var active = parseInt(form.dataset.active || '0', 10);
    var sum = 0;
    picked.forEach(function (cb) { var m = cb.parentNode.textContent.match(/\$([0-9.]+)/); if (m) sum += parseFloat(m[1]); });
    var el = document.getElementById('wo-cancel-sum');
    if (!picked.length) el.textContent = 'Nothing ticked yet.';
    else if (picked.length >= active) el.textContent = 'Whole order: the customer gets back everything they paid ($' + (form.dataset.total || '?') + ', including tax and shipping).';
    else {
      // Separate packages: a store whose items are all ticked doesn't ship, so its shipping goes back too
      var storeOut = [];
      if (form.dataset.split === '1') {
        ['hollywood', 'pico'].forEach(function (st) {
          var all = form.querySelectorAll('input[name="item_ids[]"]:not(:disabled)[data-store="' + st + '"]');
          var on = form.querySelectorAll('input[name="item_ids[]"]:checked:not(:disabled)[data-store="' + st + '"]');
          if (all.length && on.length === all.length) storeOut.push(st === 'pico' ? 'Pico' : 'Hollywood');
        });
      }
      el.textContent = 'Part of the order: ' + picked.length + ' item(s), $' + sum.toFixed(2) + ' plus their tax goes back. ' +
        (storeOut.length ? 'The ' + storeOut.join(' and ') + ' package no longer ships, so its shipping (if they paid any) is refunded too. The rest still ships.' : 'Shipping stays, since the rest still ships.');
    }
    var typed = (document.getElementById('wo-cancel-typed').value || '').trim().toUpperCase() === 'CANCEL';
    var btn = form.querySelector('button[type="submit"]');
    var why = (document.getElementById('wo-cancel-internal').value || '').trim().length >= 10;
    if (btn && btn.textContent !== 'Cancelling...') btn.disabled = !(typed && picked.length && why);
  }
</script>
@endsection
