{{-- Shared head for the artist / Index submission queues. --}}
<style>
  .as-card { display:flex; gap:16px; padding:16px; }
  .as-img { width:84px; height:84px; border-radius:8px; object-fit:cover; background:#f3f4f6; border:1px solid #e5e7eb; flex-shrink:0; }
  .as-body { flex:1; min-width:0; display:grid; grid-template-columns:1fr 1fr; gap:14px 24px; }
  .as-body .full { grid-column:1 / -1; }
  .as-title { font-size:18px; font-weight:700; margin:0 0 4px; }
  .as-sec h4 { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#9ca3af; margin:0 0 4px; }
  .as-sec p { margin:0; font-size:13.5px; color:#374151; white-space:pre-wrap; }
  .as-reject { background:#fef2f2; border-radius:8px; padding:8px 10px; color:#991b1b; font-size:13px; }
  @media (max-width: 640px) { .as-card { flex-direction:column; } .as-body { grid-template-columns:1fr; } }
</style>
