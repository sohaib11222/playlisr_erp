<style>
  .wb-links { display:flex; gap:10px; flex-wrap:wrap; }
  .wb-btn { display:inline-flex; align-items:center; gap:6px; font-size:13px; padding:8px 14px; border-radius:8px; border:1px solid #d1d5db; background:#fff; cursor:pointer; font-weight:600; color:#374151; text-decoration:none; font-family:inherit; }
  .wb-btn:hover { background:#f9fafb; color:#111827; text-decoration:none; }
  .wb-btn-primary { background:#111827; color:#fff; border-color:#111827; }
  .wb-btn-primary:hover { background:#1f2937; color:#fff; }
  .wb-btn-danger { color:#b91c1c; border-color:#fecaca; }
  .wb-btn-danger:hover { background:#fef2f2; color:#991b1b; }
  .wb-btn-blue { background:#dbeafe; border-color:#93c5fd; color:#1e40af; }
  .wb-btn-blue:hover { background:#bfdbfe; color:#1e3a8a; }
  .wb-btn:disabled { opacity:.55; cursor:wait; }
  .wb-btn-sm { font-size:12px; padding:4px 9px; border-radius:6px; }

  .wb-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:12px; margin-bottom:18px; }
  .wb-stat { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px 14px; }
  .wb-stat .l { font-size:12px; color:#6b7280; }
  .wb-stat .v { font-size:26px; font-weight:700; color:#111827; }
  .wb-stat .v.pending { color:#b45309; } .wb-stat .v.ok { color:#15803d; } .wb-stat .v.blue { color:#1d4ed8; }

  .wb-tabs { display:flex; align-items:center; gap:4px; border-bottom:2px solid #e5e7eb; margin-bottom:16px; overflow-x:auto; }
  .wb-tab { position:relative; padding:11px 16px; font-size:14px; font-weight:600; color:#6b7280; background:none; border:none; cursor:pointer; white-space:nowrap; font-family:inherit; }
  .wb-tab:hover { color:#111827; }
  .wb-tab.active { color:#1e40af; background:#dbeafe; border-radius:8px 8px 0 0; }
  .wb-badge { display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 7px; border-radius:999px; font-size:12px; font-weight:700; color:#fff; background:#9ca3af; margin-left:8px; }
  .wb-badge.hot { background:#d97706; }

  .wb-filters { display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end; margin-bottom:16px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; }
  .wb-field { display:flex; flex-direction:column; gap:4px; }
  .wb-field.grow { flex:1 1 260px; }
  .wb-field label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#9ca3af; margin:0; }
  .wb-field input, .wb-field select, .wb-field textarea { font-size:14px; padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; background:#fff; font-family:inherit; width:100%; box-sizing:border-box; }

  .wb-banner { margin-bottom:14px; padding:12px 14px; border-radius:8px; font-size:14px; }
  .wb-banner.ok { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; }
  .wb-banner.err { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }

  .wb-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; box-shadow:0 1px 2px rgba(0,0,0,.04); }
  .wb-scroll { overflow-x:auto; }
  .wb-tbl { width:100%; min-width:1000px; border-collapse:collapse; }
  .wb-tbl thead th { text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; padding:10px; border-bottom:1px solid #e5e7eb; background:#f9fafb; }
  .wb-tbl td { padding:11px 10px; font-size:14px; vertical-align:middle; border-bottom:1px solid #f0f0f0; }
  .wb-tbl tr:hover td { background:#fafafa; }
  .wb-main { font-weight:600; color:#111827; }
  .wb-sub { font-size:12.5px; color:#6b7280; margin-top:2px; }
  .wb-empty { text-align:center; padding:50px 16px; color:#6b7280; }
  .wb-empty h3 { font-size:18px; color:#374151; margin:0 0 6px; }

  .wb-pill { display:inline-block; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:700; text-transform:uppercase; white-space:nowrap; }
  .wb-pill.st-pending { background:#fef9c3; color:#854d0e; }
  .wb-pill.st-confirmed { background:#dcfce7; color:#166534; }
  .wb-pill.st-cancelled { background:#fee2e2; color:#991b1b; }
  .wb-pill.st-completed { background:#dbeafe; color:#1e40af; }
  .wb-pill.st-no-show, .wb-pill.st-default { background:#f3f4f6; color:#374151; }

  .wb-actions { display:flex; flex-wrap:wrap; gap:5px; justify-content:flex-end; align-items:center; }
  .wb-actions form { margin:0; }
  .wb-dep { font-size:12px; padding:3px 8px; border-radius:6px; font-weight:600; }
  .wb-dep.cap { background:#fef2f2; color:#b91c1c; }
  .wb-dep.rel { background:#f0fdf4; color:#15803d; }
  .wb-sep { display:inline-block; width:1px; height:20px; background:#e5e7eb; margin:0 3px; }

  .wb-dialog { border:none; border-radius:12px; padding:0; max-width:460px; width:calc(100vw - 32px); box-shadow:0 10px 40px rgba(0,0,0,.2); }
  .wb-dialog::backdrop { background:rgba(17,24,39,.45); }
  .wb-dialog-body { padding:22px; }
  .wb-dialog-body h3 { margin:0 0 8px; font-size:17px; font-weight:700; color:#111827; }
  .wb-dialog-body p.desc { font-size:13.5px; color:#4b5563; margin:0 0 12px; line-height:1.45; }
  .wb-dialog-body .wb-field { margin-top:10px; }
  .wb-summary { margin:10px 0; padding:10px 12px; border-radius:8px; background:#f9fafb; border:1px solid #e5e7eb; font-size:13px; line-height:1.5; }
  .wb-summary .num { font-family:monospace; font-size:12px; color:#6b7280; }
  .wb-dialog-err { margin-top:10px; font-size:13px; color:#991b1b; background:#fef2f2; border:1px solid #fecaca; border-radius:6px; padding:8px 10px; display:none; }
  .wb-dialog-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:18px; }
  .wb-presets { display:flex; gap:6px; margin-top:6px; }
  .wb-hint { font-size:12px; color:#6b7280; margin-top:4px; }
  .wb-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
</style>
