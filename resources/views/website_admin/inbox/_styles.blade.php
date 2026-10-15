@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>
@include('events.partials._styles')

<style>
  .wi-tabs { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px; }
  .wi-tab { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:999px; border:1px solid #d1d5db; background:#fff; color:#374151; font-size:13.5px; font-weight:600; text-decoration:none; }
  .wi-tab:hover { border-color:#93c5fd; color:#1e40af; text-decoration:none; }
  .wi-tab.active { background:#dbeafe; border-color:#93c5fd; color:#1e40af; }
  .wi-tab .n { font-weight:700; }
  .wi-note { display:inline-flex; align-items:center; padding:7px 14px; border-radius:999px; border:1px solid #fde68a; background:#fffbeb; color:#92400e; font-size:13px; font-weight:600; }

  .wi-bar { display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between; margin-bottom:14px; }
  .wi-bar form { margin:0; }
  .wi-bar label { font-size:12px; color:#6b7280; margin-right:6px; }
  .wi-bar select, .wi-bar input[type=text] { font-size:13.5px; padding:7px 10px; border:1px solid #d1d5db; border-radius:8px; background:#fff; }
  .wi-hint { font-size:12px; color:#9ca3af; }

  .wi-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; box-shadow:0 1px 2px rgba(0,0,0,.04); }
  .wi-scroll { overflow-x:auto; }
  .wi-tbl { width:100%; border-collapse:collapse; }
  .wi-tbl thead th { text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; padding:10px; border-bottom:1px solid #e5e7eb; background:#f9fafb; white-space:nowrap; }
  .wi-tbl thead th a { color:inherit; text-decoration:none; }
  .wi-tbl thead th a:hover { text-decoration:underline; }
  .wi-tbl thead th a.on { color:#111827; }
  .wi-tbl tbody tr { border-bottom:1px solid #f0f0f0; }
  .wi-tbl tbody tr:hover { background:#fafafa; }
  .wi-tbl td { padding:10px; font-size:13.5px; vertical-align:top; color:#111827; }
  .wi-tbl td.r, .wi-tbl th.r { text-align:right; }
  .wi-empty { padding:36px 16px; text-align:center; color:#6b7280; font-size:14px; }
  .wi-muted { color:#6b7280; font-size:12.5px; }
  .wi-trunc { max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
  .wi-name { font-weight:600; color:#111827; }
  .wi-also { font-size:11px; color:#b45309; font-weight:600; margin-top:2px; }
  .wi-links a { display:block; color:#2563eb; font-size:13px; }

  .wi-pill { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:600; white-space:nowrap; }
  .wi-pill.blue { background:#dbeafe; color:#1e40af; }
  .wi-pill.green { background:#dcfce7; color:#166534; }
  .wi-pill.amber { background:#fef3c7; color:#92400e; }
  .wi-pill.purple { background:#f3e8ff; color:#6b21a8; }
  .wi-pill.red { background:#fee2e2; color:#991b1b; }
  .wi-pill.gray { background:#f3f4f6; color:#374151; }
  .wi-pill-btn { border:0; cursor:pointer; margin-top:4px; font-size:11px; }

  .wi-actions { display:flex; flex-wrap:wrap; gap:5px; justify-content:flex-end; align-items:center; }
  .wi-actions form { margin:0; }
  .wi-btn { display:inline-flex; align-items:center; justify-content:center; gap:5px; height:30px; padding:0 11px; font-size:12.5px; font-weight:600; border-radius:7px; border:1px solid #d1d5db; background:#fff; color:#374151; cursor:pointer; text-decoration:none; white-space:nowrap; }
  .wi-btn:hover { background:#f9fafb; text-decoration:none; color:#111827; }
  .wi-btn:disabled { opacity:.5; cursor:not-allowed; }
  .wi-btn.primary { background:#111827; border-color:#111827; color:#fff; }
  .wi-btn.primary:hover { background:#1f2937; color:#fff; }
  .wi-btn.ok { background:#16a34a; border-color:#16a34a; color:#fff; }
  .wi-btn.ok:hover { background:#15803d; color:#fff; }
  .wi-btn.warn { background:#f59e0b; border-color:#f59e0b; color:#fff; }
  .wi-btn.warn:hover { background:#d97706; color:#fff; }
  .wi-btn.bad { background:#dc2626; border-color:#dc2626; color:#fff; }
  .wi-btn.bad:hover { background:#b91c1c; color:#fff; }
  .wi-btn.danger { color:#b91c1c; border-color:#fecaca; }
  .wi-btn.danger:hover { background:#fef2f2; }

  .wi-pager { display:flex; justify-content:center; align-items:center; gap:12px; margin-top:16px; font-size:13px; color:#4b5563; }

  .wi-dialog { border:none; border-radius:12px; padding:0; max-width:560px; width:calc(100vw - 32px); max-height:88vh; box-shadow:0 10px 40px rgba(0,0,0,.2); }
  .wi-dialog.wide { max-width:720px; }
  .wi-dialog::backdrop { background:rgba(17,24,39,.45); }
  .wi-dialog-body { padding:22px; }
  .wi-dialog-body h3 { margin:0 0 6px; font-size:17px; font-weight:700; color:#111827; display:flex; justify-content:space-between; gap:12px; align-items:center; }
  .wi-dialog-body .sub { font-size:13px; color:#6b7280; margin:0 0 12px; }
  .wi-dialog-body textarea, .wi-dialog-body input[type=text], .wi-dialog-body select { width:100%; box-sizing:border-box; font-size:14px; padding:9px 10px; border:1px solid #d1d5db; border-radius:8px; }
  .wi-dialog-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:18px; flex-wrap:wrap; }
  .wi-dialog-actions.split { justify-content:space-between; }
  .wi-kv { display:flex; gap:8px; font-size:13.5px; margin:5px 0; }
  .wi-kv b { min-width:150px; color:#111827; }
  .wi-kv span { color:#374151; overflow-wrap:anywhere; }
  .wi-sec { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#b45309; margin:14px 0 6px; }
  .wi-block { margin:8px 0; font-size:13.5px; }
  .wi-block b { display:block; color:#111827; }
  .wi-block p { white-space:pre-wrap; margin:3px 0 0; color:#374151; }
  .wi-box { border:1px solid #e5e7eb; border-radius:8px; padding:12px; background:#f9fafb; margin:8px 0; }
</style>
