@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>
@include('events.partials._styles')
<style>
  .wc-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 1px 2px rgba(0,0,0,.04); margin-bottom:18px; }
  .wc-card-pad { padding:16px 18px; }
  .wc-card h2 { font-size:16px; font-weight:700; margin:0 0 12px; color:#111827; }
  .wc-card .wc-card-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 18px; border-bottom:1px solid #e5e7eb; }
  .wc-card .wc-card-head h2 { margin:0; }
  .wc-scroll { overflow-x:auto; }
  .wc-tbl { width:100%; border-collapse:collapse; }
  .wc-tbl thead th { text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; padding:10px; border-bottom:1px solid #e5e7eb; background:#f9fafb; white-space:nowrap; }
  .wc-tbl tbody td { padding:10px; font-size:14px; border-bottom:1px solid #f0f0f0; vertical-align:top; overflow-wrap:anywhere; }
  .wc-tbl tbody tr:hover { background:#fafafa; }
  .wc-tbl .num { text-align:right; white-space:nowrap; }
  .wc-muted { color:#6b7280; font-size:12.5px; }
  .wc-mono { font-family:ui-monospace, Menlo, monospace; font-size:12.5px; }
  .wc-empty { padding:24px; text-align:center; color:#6b7280; font-size:14px; }

  .wc-filters { display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end; margin-bottom:16px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; }
  .wc-field { display:flex; flex-direction:column; gap:4px; min-width:0; }
  .wc-field > label, .wc-label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#6b7280; }
  .wc-field input[type=text], .wc-field input[type=email], .wc-field input[type=number], .wc-field input[type=date], .wc-field input[type=datetime-local], .wc-field input[type=url], .wc-field input[type=password], .wc-field select, .wc-field textarea,
  .wc-input { font-size:14px; padding:8px 10px; border:1px solid #d1d5db; border-radius:8px; background:#fff; font-family:inherit; width:100%; box-sizing:border-box; }
  .wc-field textarea { resize:vertical; min-height:70px; }
  .wc-help { font-size:12px; color:#6b7280; margin-top:2px; }
  .wc-grid { display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:14px 18px; }
  .wc-grid .wc-span2 { grid-column:1 / -1; }
  @media (max-width: 800px) { .wc-grid { grid-template-columns:1fr; } }
  .wc-check { display:flex; align-items:center; gap:8px; font-size:14px; color:#374151; }
  .wc-check input { width:16px; height:16px; }

  .wc-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; font-size:13px; padding:7px 12px; border-radius:7px; border:1px solid #d1d5db; background:#fff; cursor:pointer; font-weight:500; color:#374151; text-decoration:none; white-space:nowrap; font-family:inherit; }
  .wc-btn:hover { background:#f9fafb; color:#111827; text-decoration:none; }
  .wc-btn:disabled { opacity:.55; cursor:not-allowed; }
  .wc-btn-primary { background:#111827; color:#fff; border-color:#111827; }
  .wc-btn-primary:hover { background:#1f2937; color:#fff; }
  .wc-btn-go { background:#15803d; color:#fff; border-color:#15803d; }
  .wc-btn-go:hover { background:#166534; color:#fff; }
  .wc-btn-danger { color:#b91c1c; border-color:#fecaca; }
  .wc-btn-danger:hover { background:#fef2f2; color:#991b1b; }
  .wc-btn-sm { font-size:12px; padding:4px 9px; }
  .wc-actions { display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
  .wc-actions form { margin:0; display:inline; }

  .wc-pill { display:inline-block; padding:3px 9px; border-radius:999px; font-size:12px; font-weight:600; white-space:nowrap; background:#f3f4f6; color:#374151; border:0; }
  button.wc-pill { cursor:pointer; }
  .wc-pill.green { background:#dcfce7; color:#166534; }
  .wc-pill.blue { background:#dbeafe; color:#1e40af; }
  .wc-pill.amber { background:#fef3c7; color:#92400e; }
  .wc-pill.red { background:#fee2e2; color:#991b1b; }
  .wc-pill.purple { background:#f3e8ff; color:#6b21a8; }

  .wc-tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:16px; }
  .wc-tab { padding:7px 14px; border-radius:999px; font-size:13px; font-weight:600; color:#374151; background:#f3f4f6; text-decoration:none; border:1px solid transparent; cursor:pointer; }
  .wc-tab:hover { color:#111827; text-decoration:none; }
  .wc-tab.active { background:#dbeafe; color:#1e40af; border-color:#bfdbfe; }

  .wc-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px; margin-bottom:16px; }
  .wc-stat { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px 14px; }
  .wc-stat .k { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#6b7280; }
  .wc-stat .v { font-size:24px; font-weight:700; color:#111827; margin-top:2px; }
  .wc-stat .s { font-size:12px; color:#6b7280; }

  .wc-pager { display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; padding:12px 16px; font-size:13px; color:#6b7280; }

  .wc-bars { display:flex; flex-direction:column; gap:5px; }
  .wc-bar-row { display:grid; grid-template-columns:minmax(120px, 220px) 1fr 60px; gap:10px; align-items:center; font-size:12.5px; }
  .wc-bar-row .n { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#374151; }
  .wc-bar-row .b { height:14px; background:#e0e7ff; border-radius:0 6px 6px 0; }
  .wc-bar-row .b span { display:block; height:100%; background:#6366f1; border-radius:0 6px 6px 0; }
  .wc-bar-row .c { text-align:right; color:#111827; font-weight:600; }

  .wc-dialog { border:none; border-radius:12px; padding:0; max-width:640px; width:calc(100vw - 32px); box-shadow:0 10px 40px rgba(0,0,0,.2); }
  .wc-dialog.wide { max-width:960px; }
  .wc-dialog::backdrop { background:rgba(17,24,39,.45); }
  .wc-dialog-body { padding:20px 22px; max-height:80vh; overflow:auto; }
  .wc-dialog-body h3 { margin:0 0 14px; font-size:17px; font-weight:700; color:#111827; }
  .wc-dialog-actions { display:flex; justify-content:flex-end; gap:10px; margin-top:18px; }

  .wc-thumb { width:56px; height:56px; object-fit:cover; border-radius:6px; border:1px solid #e5e7eb; background:#f3f4f6; display:block; }
  .wc-swatch { display:inline-block; width:14px; height:14px; border-radius:999px; vertical-align:-2px; margin-right:6px; }
  .wc-back { display:inline-block; font-size:13px; color:#4b5563; margin-bottom:6px; text-decoration:none; }
  .wc-back:hover { color:#111827; }
  .wc-preview { border:1px solid #e5e7eb; border-radius:8px; padding:12px; background:#fff; min-height:60px; overflow:auto; }
  .wc-preview img { max-width:100%; height:auto; }
</style>
