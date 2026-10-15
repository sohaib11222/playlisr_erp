{{-- Shared look for the directory / community pages (Sarah, 2026-10-15). --}}
<style>
  .wd-pills { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:16px; }
  .wd-pill { display:inline-flex; align-items:center; gap:6px; padding:7px 14px; border-radius:999px; border:1px solid #d1d5db; background:#fff; color:#374151; font-size:13px; font-weight:600; text-decoration:none; }
  .wd-pill:hover { background:#f9fafb; color:#111827; text-decoration:none; }
  .wd-pill.active { background:#dbeafe; color:#1e40af; border-color:#bfdbfe; }
  .wd-pill .n { font-weight:700; color:inherit; opacity:.8; }

  .wd-filters { display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end; margin-bottom:16px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px 14px; }
  .wd-filters .wd-field { display:flex; flex-direction:column; gap:4px; }
  .wd-filters label, .wd-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#9ca3af; }
  .wd-filters select, .wd-filters input[type=text] { font-size:13.5px; padding:7px 10px; border:1px solid #d1d5db; border-radius:8px; background:#fff; }

  .wd-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 1px 2px rgba(0,0,0,.04); margin-bottom:18px; }
  .wd-card-head { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:12px 16px; border-bottom:1px solid #f0f0f0; flex-wrap:wrap; }
  .wd-card-head h2 { font-size:16px; font-weight:700; margin:0; }
  .wd-card-head .sub { font-size:13px; }
  .wd-card-body { padding:14px 16px; }

  .wd-scroll { overflow-x:auto; }
  .wd-tbl { width:100%; border-collapse:collapse; }
  .wd-tbl thead th { text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; padding:9px 10px; border-bottom:1px solid #e5e7eb; background:#f9fafb; white-space:nowrap; }
  .wd-tbl td { padding:10px; font-size:13.5px; vertical-align:top; border-bottom:1px solid #f3f4f6; overflow-wrap:anywhere; }
  .wd-tbl tr:hover td { background:#fafafa; }
  .wd-tbl .num { text-align:right; font-variant-numeric:tabular-nums; }
  .wd-muted { color:#6b7280; font-size:12.5px; }
  .wd-mono { font-family:monospace; font-size:12.5px; }

  .wd-st { display:inline-block; padding:3px 9px; border-radius:999px; font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:.02em; white-space:nowrap; }
  .wd-st.pending { background:#fef3c7; color:#92400e; }
  .wd-st.approved, .wd-st.published, .wd-st.success, .wd-st.handled { background:#dcfce7; color:#166534; }
  .wd-st.rejected, .wd-st.failed { background:#fee2e2; color:#991b1b; }
  .wd-st.new { background:#ffedd5; color:#9a3412; }
  .wd-st.other { background:#f3f4f6; color:#374151; }
  .wd-tag { display:inline-block; padding:2px 8px; margin:0 4px 4px 0; border-radius:6px; font-size:12px; background:#f3f4f6; color:#374151; }
  .wd-tag.blue { background:#dbeafe; color:#1e40af; }
  .wd-tag.green { background:#dcfce7; color:#166534; }
  .wd-tag.purple { background:#f3e8ff; color:#6b21a8; }
  .wd-tag.orange { background:#ffedd5; color:#9a3412; }
  .wd-tag.yellow { background:#fef9c3; color:#854d0e; }

  .wd-actions { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
  .wd-actions form { margin:0; display:inline-flex; gap:6px; align-items:center; }
  .wd-btn { display:inline-flex; align-items:center; justify-content:center; gap:5px; height:30px; padding:0 11px; font-size:12.5px; font-weight:600; border-radius:7px; border:1px solid #d1d5db; background:#fff; color:#374151; cursor:pointer; text-decoration:none; white-space:nowrap; font-family:inherit; }
  .wd-btn:hover { background:#f9fafb; color:#111827; text-decoration:none; }
  .wd-btn[disabled] { opacity:.5; cursor:not-allowed; }
  .wd-btn-primary { background:#111827; color:#fff; border-color:#111827; }
  .wd-btn-primary:hover { background:#1f2937; color:#fff; }
  .wd-btn-ok { color:#166534; border-color:#bbf7d0; }
  .wd-btn-ok:hover { background:#f0fdf4; }
  .wd-btn-danger { color:#b91c1c; border-color:#fecaca; }
  .wd-btn-danger:hover { background:#fef2f2; }
  .wd-input { font-size:13px; padding:6px 9px; border:1px solid #d1d5db; border-radius:7px; min-width:0; font-family:inherit; }

  .wd-pager { display:flex; justify-content:center; align-items:center; gap:10px; padding:14px; font-size:13px; color:#6b7280; }
  .wd-empty { padding:28px 16px; text-align:center; color:#6b7280; font-size:14px; }
  .wd-details > summary { cursor:pointer; color:#2563eb; font-size:12.5px; font-weight:600; list-style:none; }
  .wd-details > summary::-webkit-details-marker { display:none; }
  .wd-details[open] > summary { margin-bottom:8px; }
  .wd-kv { display:grid; grid-template-columns:max-content 1fr; gap:4px 14px; font-size:13px; }
  .wd-kv dt { color:#9ca3af; font-weight:600; }
  .wd-kv dd { margin:0; color:#111827; overflow-wrap:anywhere; }
  .wd-thumb { width:44px; height:44px; border-radius:6px; object-fit:cover; border:1px solid #e5e7eb; background:#f3f4f6; flex-shrink:0; }
  .wd-timeline { list-style:none; margin:0; padding:0 0 0 14px; border-left:2px solid #e5e7eb; }
  .wd-timeline li { margin:0 0 10px; font-size:13px; }
  .wd-timeline .t { color:#9ca3af; font-size:12px; }
  @media (max-width: 640px) {
    .wd-filters { gap:10px; }
    .wd-filters .wd-field, .wd-filters select, .wd-filters input[type=text] { width:100%; }
  }
</style>
