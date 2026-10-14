{{-- Time clock admin pages (Timecards, Time clock settings): compact report
     styling, same palette as Payroll. Scoped to .tcx so nothing else moves. --}}
<style>
.tcx { --ink:#1F1B16; --ink2:#5A5045; --ink3:#8E8273; --line:#ECE3CF; --line2:#DFD2B3; --soft:#FFF9DB; --surface2:#F7F1E3; font-size:13px; color:var(--ink); }
.tcx .content-header h1 { font-size:22px; font-weight:800; }
.tcx .content-header h1 small { font-size:13px; color:var(--ink3); font-weight:600; }
.tcx .box, .tcx .box.box-solid { border:1px solid var(--line); border-radius:10px; box-shadow:0 1px 2px rgba(31,27,22,.06); margin-bottom:14px; }
.tcx .box > .box-header { border-bottom:1px solid var(--line); padding:10px 14px 8px; }
.tcx .box > .box-header .box-title { font-size:12px !important; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:var(--ink3); }
.tcx .box-body { padding:12px 14px; }
.tcx .table { margin-bottom:0; font-size:13px; }
.tcx .table > thead > tr > th { background:var(--soft); border-color:var(--line) !important; color:var(--ink2); font-size:11px !important; font-weight:800 !important; text-transform:uppercase; letter-spacing:.04em; padding:6px 8px; white-space:nowrap; line-height:1.4; }
.tcx .table > tbody > tr > td { border-color:var(--line) !important; vertical-align:middle; padding:6px 8px; font-size:13px; }
.tcx label { display:block; font-size:11px !important; font-weight:700 !important; text-transform:uppercase; letter-spacing:.04em; color:var(--ink3); margin:0 0 3px; }
.tcx label.tcx-inline { display:inline; text-transform:none; letter-spacing:0; font-size:13px !important; font-weight:600 !important; color:var(--ink); }
.tcx .form-control, .tcx .input-sm { height:32px; font-size:13px; padding:4px 8px; border:1px solid var(--line2); border-radius:8px; box-shadow:none; }
.tcx textarea.form-control { height:auto; }
.tcx .btn { border-radius:8px; font-weight:700; font-size:13px; }
.tcx .btn-xs { font-size:12px; padding:2px 8px; }
.tcx .tcx-bar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
.tcx .tcx-bar .form-control { width:auto; display:inline-block; }
.tcx .tcx-bar .tcx-to { font-size:13px; color:var(--ink3); }
.tcx .tcx-bar .tcx-right { margin-left:auto; display:flex; flex-wrap:wrap; gap:8px; }
.tcx .tcx-empty { padding:14px 8px; color:var(--ink3); font-size:13px; text-align:center; }
.tcx .tcx-hint { font-size:12px; color:var(--ink3); margin:0 0 8px; }
.tcx .tcx-badge { display:inline-block; padding:1px 7px; border-radius:999px; font-size:11px; font-weight:700; line-height:1.6; margin:1px 2px 1px 0; white-space:nowrap; background:#EDE7DA; color:#4A4238; }
.tcx .tcx-badge.amber { background:#FFF2B3; color:#5A4410; }
.tcx .tcx-badge.blue { background:#DBEAFE; color:#1E40AF; }
.tcx .tcx-badge.red { background:#FEE2E2; color:#991B1B; }
.tcx .tcx-badge.green { background:#E7F1E9; color:#2F6B3E; }
.tcx .tcx-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:8px 12px; }
.tcx .tcx-grid .tcx-full { grid-column:1 / -1; }
.tcx .tcx-small { font-size:11px; color:var(--ink3); }
.tcx .tcx-nowrap { white-space:nowrap; }
@media (max-width: 767px) {
  .tcx .tcx-bar .tcx-right { margin-left:0; }
  .tcx .tcx-bar .form-control { width:100%; }
  .tcx .tcx-grid { grid-template-columns:1fr; }
  .tcx .content-header h1 small { display:block; margin-top:2px; }
}
</style>
