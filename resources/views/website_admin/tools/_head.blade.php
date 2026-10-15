{{-- Shared look, tool nav and fetch helper for the website tools pages. Pass $active (route name). --}}
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>
@include('events.partials._styles')

<style>
  .wt-nav { display:flex; flex-wrap:wrap; gap:6px; margin:0 0 18px; }
  .wt-nav a { display:inline-block; padding:6px 12px; border-radius:999px; font-size:13px; font-weight:600; color:#374151; background:#fff; border:1px solid #e5e7eb; text-decoration:none; }
  .wt-nav a:hover { background:#f9fafb; }
  .wt-nav a.active { background:#dbeafe; color:#1e40af; border-color:#bfdbfe; }
  .wt-section { display:flex; gap:10px; align-items:flex-start; border-bottom:2px solid #e5e7eb; padding:6px 0 8px; margin:26px 0 14px; }
  .wt-section h2 { font-size:19px; font-weight:700; margin:0; }
  .wt-section p { margin:2px 0 0; font-size:13px; color:#6b7280; }
  .wt-section.blue { border-color:#93c5fd; } .wt-section.green { border-color:#6ee7b7; }
  .wt-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px 18px; margin-bottom:16px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
  .wt-card h3 { font-size:15.5px; font-weight:700; margin:0 0 10px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
  .wt-card.tint-blue { background:#f5f9ff; border-color:#bfdbfe; } .wt-card.tint-green { background:#f4fbf6; border-color:#bbf7d0; }
  .wt-card.tint-amber { background:#fffbeb; border-color:#fde68a; } .wt-card.tint-orange { border-color:#fed7aa; }
  .wt-card.tint-purple { border-color:#e9d5ff; } .wt-card.tint-slate { background:#f8fafc; }
  .wt-grid { display:grid; gap:12px; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); }
  .wt-grid.two { grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); }
  .wt-stat { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px 14px; }
  .wt-stat .l { font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#6b7280; }
  .wt-stat .v { font-size:24px; font-weight:700; color:#111827; margin-top:3px; }
  .wt-stat .h { font-size:12px; color:#6b7280; margin-top:2px; }
  .wt-stat.c { text-align:center; }
  .v.green, .green-t { color:#15803d !important; } .v.red, .red-t { color:#dc2626 !important; } .v.amber, .amber-t { color:#b45309 !important; }
  .v.blue, .blue-t { color:#1d4ed8 !important; } .v.purple, .purple-t { color:#7e22ce !important; } .muted { color:#6b7280; }
  .wt-row { display:flex; flex-wrap:wrap; gap:10px; align-items:center; margin:8px 0; }
  .wt-row label { font-size:13px; color:#4b5563; display:inline-flex; gap:6px; align-items:center; }
  .wt-input, .wt-select { font-size:13.5px; padding:7px 10px; border:1px solid #d1d5db; border-radius:8px; background:#fff; font-family:inherit; }
  .wt-input.n { width:110px; } .wt-input.w { flex:1; min-width:200px; }
  .wt-btn { display:inline-flex; align-items:center; gap:6px; font-size:13px; padding:7px 13px; border-radius:8px; border:1px solid #d1d5db; background:#fff; cursor:pointer; font-weight:600; color:#374151; font-family:inherit; text-decoration:none; }
  .wt-btn:hover { background:#f9fafb; }
  .wt-btn:disabled { opacity:.5; cursor:not-allowed; }
  .wt-btn.primary { background:#111827; color:#fff; border-color:#111827; } .wt-btn.primary:hover { background:#1f2937; }
  .wt-btn.blue { background:#1d4ed8; color:#fff; border-color:#1d4ed8; } .wt-btn.green { background:#15803d; color:#fff; border-color:#15803d; }
  .wt-btn.amber { background:#d97706; color:#fff; border-color:#d97706; } .wt-btn.purple { background:#7e22ce; color:#fff; border-color:#7e22ce; }
  .wt-btn.orange { background:#ea580c; color:#fff; border-color:#ea580c; } .wt-btn.danger { background:#dc2626; color:#fff; border-color:#dc2626; }
  .wt-btn.ghost-danger { color:#b91c1c; border-color:#fecaca; } .wt-btn.sm { padding:4px 9px; font-size:12px; }
  .wt-badge { display:inline-flex; align-items:center; gap:4px; padding:2px 9px; border-radius:999px; font-size:11.5px; font-weight:700; background:#f3f4f6; color:#374151; border:1px solid #e5e7eb; white-space:nowrap; }
  .wt-badge.on { background:#dcfce7; color:#166534; border-color:#bbf7d0; } .wt-badge.off { background:#f3f4f6; color:#4b5563; }
  .wt-badge.run { background:#dbeafe; color:#1e40af; border-color:#bfdbfe; } .wt-badge.warn { background:#fef3c7; color:#92400e; border-color:#fde68a; }
  .wt-badge.bad { background:#fee2e2; color:#991b1b; border-color:#fecaca; }
  .wt-note { font-size:12.5px; color:#6b7280; margin:6px 0; }
  .wt-box { border-radius:8px; padding:10px 12px; font-size:13px; margin:8px 0; border:1px solid #e5e7eb; background:#fff; }
  .wt-box.ok { background:#f0fdf4; border-color:#bbf7d0; color:#166534; } .wt-box.bad { background:#fef2f2; border-color:#fecaca; color:#991b1b; }
  .wt-box.warn { background:#fffbeb; border-color:#fde68a; color:#92400e; } .wt-box.info { background:#eff6ff; border-color:#bfdbfe; color:#1e40af; }
  .wt-tbl { width:100%; border-collapse:collapse; font-size:13.5px; }
  .wt-tbl th { text-align:left; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#6b7280; padding:8px 8px; border-bottom:1px solid #e5e7eb; background:#f9fafb; }
  .wt-tbl td { padding:8px 8px; border-bottom:1px solid #f0f0f0; vertical-align:top; overflow-wrap:anywhere; }
  .wt-tbl td.r, .wt-tbl th.r { text-align:right; } .wt-tbl tr.total td { font-weight:700; background:#f9fafb; }
  .wt-scroll { overflow-x:auto; }
  .wt-bar { height:8px; background:#e5e7eb; border-radius:999px; overflow:hidden; min-width:60px; }
  .wt-bar > span { display:block; height:100%; background:#2563eb; border-radius:999px; }
  .wt-bar.g > span { background:#10b981; } .wt-bar.a > span { background:#f59e0b; } .wt-bar.s > span { background:#94a3b8; }
  .wt-log { background:#0b1020; color:#d1d5db; border-radius:8px; height:280px; overflow-y:auto; padding:12px; font-family:ui-monospace,Menlo,monospace; font-size:12px; line-height:1.55; }
  .wt-log .g { color:#4ade80; } .wt-log .y { color:#facc15; } .wt-log .r { color:#f87171; } .wt-log .c { color:#22d3ee; } .wt-log .d { color:#6b7280; }
  .wt-cats { display:grid; grid-template-columns:repeat(auto-fill, minmax(190px, 1fr)); gap:6px 12px; margin:6px 0; }
  .wt-cats label { font-size:13px; display:flex; gap:6px; align-items:center; }
  .wt-toasts { position:fixed; right:18px; bottom:18px; z-index:2000; display:flex; flex-direction:column; gap:8px; max-width:380px; }
  .wt-toast { padding:10px 14px; border-radius:9px; font-size:13.5px; box-shadow:0 6px 20px rgba(0,0,0,.15); background:#111827; color:#fff; }
  .wt-toast.ok { background:#166534; } .wt-toast.bad { background:#991b1b; } .wt-toast.warn { background:#92400e; }
  .wt-spin { display:inline-block; width:12px; height:12px; border:2px solid currentColor; border-right-color:transparent; border-radius:50%; animation:wt-spin .8s linear infinite; vertical-align:-2px; }
  @@keyframes wt-spin { to { transform:rotate(360deg); } }
  .wt-hide { display:none !important; }
  .wt-banner-run { display:flex; flex-wrap:wrap; gap:8px; align-items:center; border:1px solid #93c5fd; background:#eff6ff; border-radius:8px; padding:10px 12px; margin-bottom:14px; font-size:13px; color:#1e40af; }
</style>

@php
  $wtNav = [
    'wa.tools.dashboard' => 'Dashboard',
    'wa.tools.syncDashboard' => 'Sync Dashboard',
    'wa.tools.autoSync' => 'Auto Sync',
    'wa.tools.posImageRefresh' => 'POS Image Refresh',
    'wa.tools.posArtistRefresh' => 'POS Artist Refresh',
    'wa.tools.bulkDiscogsImport' => 'Bulk Discogs Import',
    'wa.tools.backgroundImport' => 'Background Import',
    'wa.tools.importStatus' => 'Import Status',
    'wa.tools.abandonedCarts' => 'Abandoned Carts',
  ];
@endphp
<nav class="wt-nav">
  @foreach($wtNav as $wtRoute => $wtLabel)
    <a href="{{ route($wtRoute) }}" class="{{ ($active ?? '') === $wtRoute ? 'active' : '' }}">{{ $wtLabel }}</a>
  @endforeach
</nav>
<div class="wt-toasts" id="wt-toasts"></div>

<script>
  window.WT_CFG = { api: @json(route('wa.tools.api')), csrf: @json(csrf_token()) };
</script>
@verbatim
<script>
window.WT = {
  // Calls one website endpoint through the ERP (see WebsiteToolsController@api).
  call: async function (method, path, opts) {
    opts = opts || {};
    try {
      const res = await fetch(WT_CFG.api, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': WT_CFG.csrf },
        credentials: 'same-origin',
        body: JSON.stringify({ method: method, path: path, query: opts.query || null, body: opts.body === undefined ? null : opts.body })
      });
      try { return await res.json(); } catch (e) { return { success: false, message: 'The ERP returned an unexpected response (HTTP ' + res.status + ').' }; }
    } catch (e) {
      return { success: false, message: 'Could not reach the ERP. Check your connection.' };
    }
  },
  // WebsiteBridge wraps a reply that had no success flag as {success, data}.
  unwrap: function (r) {
    if (r && r.data && typeof r.data === 'object' && !Array.isArray(r.data)) {
      const keys = Object.keys(r).filter(function (k) { return k !== '_http'; });
      if (keys.length === 2 && keys.indexOf('success') >= 0) return r.data;
    }
    return r;
  },
  err: function (r, fallback) { return (r && (r.details || r.error || r.message)) || fallback; },
  toast: function (msg, kind) {
    const box = document.getElementById('wt-toasts');
    if (!box) return;
    const el = document.createElement('div');
    el.className = 'wt-toast ' + (kind || '');
    el.textContent = msg;
    box.appendChild(el);
    setTimeout(function () { el.remove(); }, kind === 'bad' ? 8000 : 5000);
  },
  esc: function (s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  },
  num: function (v) { return v === null || v === undefined || v === '' || isNaN(Number(v)) ? '-' : Number(v).toLocaleString(); },
  pct: function (a, b) { return b > 0 ? Math.round((a / b) * 100) : 0; },
  dt: function (v, opts) {
    if (!v) return '';
    const d = new Date(v);
    if (isNaN(d.getTime())) return 'Invalid date';
    const o = { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' };
    if (opts && opts.year) o.year = 'numeric';
    if (opts && opts.sec) o.second = '2-digit';
    if (opts && opts.timeOnly) { delete o.month; delete o.day; }
    return d.toLocaleString('en-US', o);
  },
  bar: function (value, cls) { return '<div class="wt-bar ' + (cls || '') + '"><span style="width:' + Math.max(0, Math.min(100, value)) + '%"></span></div>'; },
  busy: function (btn, on, label) {
    if (!btn) return;
    if (on) { btn.dataset.label = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<span class="wt-spin"></span> ' + (label || 'Working'); }
    else { btn.disabled = false; if (btn.dataset.label) btn.innerHTML = btn.dataset.label; }
  },
  $: function (id) { return document.getElementById(id); },
  logClass: function (line, rules) {
    const l = String(line || '').toLowerCase();
    for (let i = 0; i < rules.length; i++) { if (rules[i][0](l)) return rules[i][1]; }
    return '';
  }
};
</script>
@endverbatim
