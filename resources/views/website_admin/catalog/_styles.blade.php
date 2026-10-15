@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>
@include('events.partials._styles')
<style>
  .wc-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px; }
  .wc-tab { padding:7px 14px; border-radius:999px; border:1px solid #e5e7eb; background:#fff; color:#374151; font-size:13px; font-weight:600; text-decoration:none; }
  .wc-tab:hover { background:#f9fafb; color:#111827; text-decoration:none; }
  .wc-tab.active { background:#dbeafe; border-color:#93c5fd; color:#1e40af; }
  .wc-nav { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:14px; }
  .wc-nav a { font-size:12.5px; color:#4b5563; text-decoration:none; padding:4px 10px; border-radius:999px; border:1px solid #e5e7eb; background:#fff; }
  .wc-nav a.active { background:#dbeafe; border-color:#93c5fd; color:#1e40af; font-weight:600; }
  .wc-filters { display:flex; flex-wrap:wrap; gap:12px; align-items:flex-end; margin-bottom:16px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px 14px; }
  .wc-filters .f { display:flex; flex-direction:column; gap:4px; }
  .wc-filters label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#9ca3af; margin:0; }
  .wc-filters select, .wc-filters input[type=text], .wc-filters input[type=number] { font-size:13.5px; padding:7px 10px; border:1px solid #d1d5db; border-radius:8px; background:#fff; }
  .wc-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 1px 2px rgba(0,0,0,.04); margin-bottom:18px; }
  .wc-card.pad { padding:16px 18px; }
  .wc-card h2 { font-size:16px; font-weight:700; margin:0 0 12px; }
  .wc-scroll { overflow-x:auto; }
  .wc-tbl { width:100%; border-collapse:collapse; }
  .wc-tbl thead th { text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; padding:10px; border-bottom:1px solid #e5e7eb; background:#f9fafb; white-space:nowrap; }
  .wc-tbl thead th a { color:inherit; text-decoration:none; }
  .wc-tbl thead th a:hover { text-decoration:underline; }
  .wc-tbl tbody td { padding:9px 10px; font-size:13.5px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
  .wc-tbl tbody tr:hover { background:#fafafa; }
  .wc-tbl tbody tr.sel { background:#eff6ff; }
  .wc-img { width:56px; height:56px; border-radius:6px; object-fit:cover; border:1px solid #e5e7eb; background:#f3f4f6; display:block; }
  .wc-muted { color:#6b7280; font-size:12.5px; }
  .wc-pill { display:inline-block; padding:2px 9px; border-radius:999px; font-size:11.5px; font-weight:600; white-space:nowrap; }
  .wc-pill.green { background:#dcfce7; color:#166534; }
  .wc-pill.red { background:#fee2e2; color:#991b1b; }
  .wc-pill.blue { background:#dbeafe; color:#1e40af; }
  .wc-pill.gray { background:#f3f4f6; color:#374151; }
  .wc-pill.orange { background:#ffedd5; color:#9a3412; }
  .wc-pill.purple { background:#ede9fe; color:#5b21b6; }
  .wc-btn { display:inline-flex; align-items:center; gap:5px; height:30px; padding:0 11px; font-size:12.5px; font-weight:600; border-radius:7px; border:1px solid #d1d5db; background:#fff; color:#374151; cursor:pointer; text-decoration:none; white-space:nowrap; }
  .wc-btn:hover { background:#f9fafb; color:#111827; text-decoration:none; }
  .wc-btn.primary { background:#dbeafe; border-color:#93c5fd; color:#1e40af; }
  .wc-btn.danger { color:#b91c1c; border-color:#fecaca; }
  .wc-btn.danger:hover { background:#fef2f2; }
  .wc-btn:disabled { opacity:.5; cursor:wait; }
  .wc-actions { display:flex; gap:5px; flex-wrap:wrap; align-items:center; justify-content:flex-end; }
  .wc-actions form { margin:0; }
  .wc-bulkbar { display:none; align-items:center; gap:10px; flex-wrap:wrap; background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; padding:10px 14px; margin-bottom:14px; }
  .wc-bulkbar.on { display:flex; }
  .wc-bulkbar select, .wc-bulkbar input { font-size:13px; padding:6px 9px; border:1px solid #d1d5db; border-radius:7px; background:#fff; }
  .wc-tool { display:flex; flex-wrap:wrap; gap:10px; align-items:center; background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:10px 14px; margin-bottom:14px; font-size:13px; color:#92400e; }
  .wc-tool input { font-size:13px; padding:6px 9px; border:1px solid #d1d5db; border-radius:7px; width:110px; }
  .wc-pager { display:flex; justify-content:center; align-items:center; gap:10px; margin:18px 0; font-size:13px; }
  .wc-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px; margin-bottom:16px; }
  .wc-stat { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px 14px; }
  .wc-stat .n { font-size:22px; font-weight:700; color:#111827; }
  .wc-stat .l { font-size:12.5px; color:#6b7280; }
  .wc-form .ev-field { flex:1 1 260px; }
  .wc-form .ev-field.wide { flex:1 1 100%; }
  .wc-chips { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px; }
  .wc-chip { display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:999px; background:#dbeafe; color:#1e40af; font-size:12.5px; font-weight:600; }
  .wc-chip button { background:none; border:0; color:#1e40af; cursor:pointer; padding:0; font-size:14px; line-height:1; }
  .wc-lock { display:inline-flex; align-items:center; gap:4px; padding:2px 8px; border-radius:6px; background:#ffedd5; color:#9a3412; font-size:11.5px; font-weight:600; }
  .wc-kv { display:grid; grid-template-columns:160px 1fr; gap:6px 14px; font-size:14px; }
  .wc-kv .k { color:#6b7280; }
  @media (max-width: 640px) { .wc-kv { grid-template-columns:1fr; } }
</style>
