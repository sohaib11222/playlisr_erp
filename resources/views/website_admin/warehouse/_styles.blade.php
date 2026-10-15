<style>
  .wh-nav { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:18px; }
  .wh-nav a { padding:8px 14px; border-radius:999px; font-size:13.5px; font-weight:600; color:#374151; background:#f3f4f6; text-decoration:none; }
  .wh-nav a:hover { background:#e5e7eb; }
  .wh-nav a.active { background:#dbeafe; color:#1e40af; }
  .wh-nav a.ext { background:#fff; border:1px solid #e5e7eb; }

  .wh-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px; margin-bottom:18px; }
  .wh-stat { background:#fff; border:1px solid #e5e7eb; border-left:4px solid #9ca3af; border-radius:10px; padding:12px 14px; }
  .wh-stat .l { font-size:12px; font-weight:600; color:#6b7280; }
  .wh-stat .v { font-size:24px; font-weight:700; color:#111827; margin-top:2px; }
  .wh-stat .h { font-size:11.5px; color:#9ca3af; margin-top:2px; }
  .wh-stat.blue { border-left-color:#3b82f6; } .wh-stat.blue .v { color:#2563eb; }
  .wh-stat.green { border-left-color:#22c55e; } .wh-stat.green .v { color:#16a34a; }
  .wh-stat.purple { border-left-color:#a855f7; } .wh-stat.purple .v { color:#9333ea; }
  .wh-stat.amber { border-left-color:#f59e0b; } .wh-stat.amber .v { color:#d97706; }
  .wh-stat.red { border-left-color:#ef4444; } .wh-stat.red .v { color:#dc2626; }

  .wh-filters { display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end; margin-bottom:18px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; }
  .wh-filters .wh-field { display:flex; flex-direction:column; gap:4px; }
  .wh-filters label, .wh-lbl { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#9ca3af; }
  .wh-filters select, .wh-filters input[type=text], .wh-filters input[type=date], .wh-filters input[type=search] { font-size:14px; padding:9px 10px; border:1px solid #d1d5db; border-radius:8px; background:#fff; }
  .wh-filters .wh-grow { flex:1 1 260px; }
  .wh-filters .wh-grow input { width:100%; box-sizing:border-box; }
  .wh-check { display:flex; align-items:center; gap:7px; font-size:13.5px; color:#374151; padding-bottom:9px; }

  .wh-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; box-shadow:0 1px 2px rgba(0,0,0,.04); margin-bottom:18px; }
  .wh-card-pad { padding:16px 18px; }
  .wh-card h2 { font-size:16px; font-weight:700; margin:0 0 12px; }
  .wh-scroll { overflow-x:auto; }
  .wh-tbl { width:100%; border-collapse:collapse; }
  .wh-tbl thead th { text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; padding:10px; border-bottom:1px solid #e5e7eb; background:#f9fafb; white-space:nowrap; }
  .wh-tbl thead th a { color:inherit; text-decoration:none; }
  .wh-tbl thead th a:hover { text-decoration:underline; }
  .wh-tbl td { padding:11px 10px; font-size:14px; vertical-align:top; border-bottom:1px solid #f0f0f0; }
  .wh-tbl tr:hover td { background:#fafafa; }
  .wh-muted { color:#9ca3af; font-size:12.5px; }
  .wh-mono { font-family:ui-monospace, Menlo, monospace; }
  .wh-link { color:#2563eb; text-decoration:none; font-weight:600; }
  .wh-link:hover { text-decoration:underline; }

  .wh-pill { display:inline-block; padding:3px 9px; border-radius:999px; font-size:12px; font-weight:600; white-space:nowrap; background:#f3f4f6; color:#374151; }
  .wh-pill.green { background:#dcfce7; color:#166534; }
  .wh-pill.blue { background:#dbeafe; color:#1e40af; }
  .wh-pill.yellow { background:#fef9c3; color:#854d0e; }
  .wh-pill.orange { background:#ffedd5; color:#9a3412; }
  .wh-pill.red { background:#fee2e2; color:#991b1b; }
  .wh-pill.purple { background:#f3e8ff; color:#6b21a8; }
  .wh-pill.indigo { background:#e0e7ff; color:#3730a3; }
  .wh-pill.sky { background:#e0f2fe; color:#0369a1; }
  .wh-pill.teal { background:#ccfbf1; color:#115e59; }

  .wh-tag { display:inline-block; padding:2px 7px; border-radius:5px; font-size:11.5px; font-weight:600; margin:0 3px 3px 0; }
  .wh-tag.zone { background:#e0e7ff; color:#3730a3; }
  .wh-tag.sub { background:#dbeafe; color:#1e40af; }
  .wh-tag.shelf { background:#ccfbf1; color:#115e59; }
  .wh-tag.code { background:#f3e8ff; color:#6b21a8; font-family:ui-monospace, Menlo, monospace; font-weight:700; }

  .wh-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; font-size:13.5px; padding:8px 14px; border-radius:8px; border:1px solid #d1d5db; background:#fff; cursor:pointer; font-weight:600; color:#374151; text-decoration:none; font-family:inherit; }
  .wh-btn:hover { background:#f9fafb; text-decoration:none; color:#111827; }
  .wh-btn:disabled { opacity:.5; cursor:not-allowed; }
  .wh-btn-primary { background:#111827; color:#fff; border-color:#111827; }
  .wh-btn-primary:hover { background:#1f2937; color:#fff; }
  .wh-btn-green { background:#16a34a; color:#fff; border-color:#16a34a; }
  .wh-btn-green:hover { background:#15803d; color:#fff; }
  .wh-btn-red { background:#dc2626; color:#fff; border-color:#dc2626; }
  .wh-btn-red:hover { background:#b91c1c; color:#fff; }
  .wh-btn-danger { color:#b91c1c; border-color:#fecaca; }
  .wh-btn-danger:hover { background:#fef2f2; color:#991b1b; }
  .wh-btn-sm { padding:5px 10px; font-size:12.5px; }

  .wh-thumbs { display:flex; flex-wrap:wrap; gap:6px; }
  .wh-thumb { position:relative; width:44px; height:44px; border-radius:6px; background:#e5e7eb; overflow:visible; flex-shrink:0; }
  .wh-thumb img { width:44px; height:44px; border-radius:6px; object-fit:cover; display:block; border:1px solid #fff; box-shadow:0 1px 2px rgba(0,0,0,.1); }
  .wh-thumb .q { position:absolute; top:-5px; right:-5px; background:#2563eb; color:#fff; font-size:10.5px; font-weight:700; border-radius:999px; padding:1px 5px; }
  .wh-thumb .g { position:absolute; bottom:-5px; right:-5px; background:#eab308; color:#fff; font-size:10px; font-weight:700; border-radius:999px; padding:1px 5px; }
  .wh-thumb.picked::after { content:""; position:absolute; inset:0; border-radius:6px; background:rgba(34,197,94,.35); }
  .wh-thumb.cant::after { content:""; position:absolute; inset:0; border-radius:6px; background:rgba(239,68,68,.35); }
  .wh-locgrp { background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px; padding:7px; margin-bottom:6px; }
  .wh-locgrp.nozone { background:#fef2f2; border-color:#fecaca; }

  .wh-bar { display:inline-block; width:60px; height:7px; background:#e5e7eb; border-radius:999px; vertical-align:middle; margin-right:6px; overflow:hidden; }
  .wh-bar span { display:block; height:7px; border-radius:999px; }

  .wh-pager { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:12px 16px; font-size:13.5px; color:#4b5563; }
  .wh-pager .pages { display:flex; gap:4px; flex-wrap:wrap; }
  .wh-pager .pages a, .wh-pager .pages span { padding:6px 11px; border:1px solid #e5e7eb; border-radius:7px; color:#374151; text-decoration:none; }
  .wh-pager .pages .cur { background:#dbeafe; color:#1e40af; border-color:#bfdbfe; font-weight:700; }

  .wh-dialog { border:none; border-radius:12px; padding:0; max-width:460px; width:calc(100vw - 32px); box-shadow:0 10px 40px rgba(0,0,0,.2); }
  .wh-dialog.wide { max-width:640px; }
  .wh-dialog::backdrop { background:rgba(17,24,39,.45); }
  .wh-dialog-body { padding:22px; }
  .wh-dialog-body h3 { margin:0 0 14px; font-size:17px; font-weight:700; color:#111827; }
  .wh-dialog-body label { display:block; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#6b7280; margin:12px 0 5px; }
  .wh-dialog-body select, .wh-dialog-body input[type=text], .wh-dialog-body input[type=number], .wh-dialog-body textarea { width:100%; font-size:15px; padding:10px; border:1px solid #d1d5db; border-radius:8px; box-sizing:border-box; font-family:inherit; }
  .wh-dialog-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:20px; flex-wrap:wrap; }

  .wh-note { padding:10px 12px; border-radius:8px; font-size:13px; margin-bottom:14px; }
  .wh-note.info { background:#eff6ff; border:1px solid #bfdbfe; color:#1e3a8a; }
  .wh-note.warn { background:#fffbeb; border:1px solid #fde68a; color:#92400e; }
  .wh-note.err { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
  .wh-confirm { display:inline-block; }
  .wh-confirm summary { list-style:none; cursor:pointer; }
  .wh-confirm summary::-webkit-details-marker { display:none; }
  .wh-confirm[open] .wh-confirm-box { display:flex; }
  .wh-confirm-box { display:none; gap:6px; align-items:center; margin-top:6px; font-size:12.5px; color:#991b1b; flex-wrap:wrap; }
  .wh-imgzoom { max-width:min(92vw, 900px); background:transparent; border:none; padding:0; }
  .wh-imgzoom img { max-width:100%; max-height:88vh; display:block; border-radius:10px; }
  .wh-imgzoom::backdrop { background:rgba(0,0,0,.75); }
</style>
