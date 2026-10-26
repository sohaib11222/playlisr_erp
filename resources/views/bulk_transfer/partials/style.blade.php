<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap">
<script>document.body.classList.add('transfer-v2');</script>

<style>
body.transfer-v2 {
  --tr-bg: #FAF6EE; --tr-surface: #FFFFFF; --tr-surface-2: #F7F1E3; --tr-ink: #1F1B16; --tr-ink-2: #4A4036;
  --tr-line: #ECE3CF; --tr-line-2: #DFD2B3; --tr-success: #2F6B3E; --tr-success-soft: #E4F0E7;
  --tr-danger: #7A1F1F; --tr-danger-soft: #F6E4E4; --tr-sel: #DCEBFF; --tr-sel-ink: #1F4E8C;
}
body.transfer-v2 .content-wrapper { background: var(--tr-bg); }
body.transfer-v2 .tr-wrap { max-width: 1100px; margin: 0 auto; padding: 18px 16px 60px; font-family: "Inter Tight", system-ui, sans-serif; color: var(--tr-ink); }
body.transfer-v2 .tr-head h1 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
body.transfer-v2 .tr-head p { color: var(--tr-ink-2); margin: 0 0 18px; font-size: 14px; }
body.transfer-v2 .tr-card { background: var(--tr-surface); border: 1px solid var(--tr-line); border-radius: 12px; padding: 18px 20px; margin-bottom: 18px; }
body.transfer-v2 .tr-card h2 { font-size: 16px; font-weight: 700; margin: 0 0 12px; }
body.transfer-v2 .tr-row { display: flex; gap: 14px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 14px; }
body.transfer-v2 .tr-field { display: flex; flex-direction: column; gap: 6px; min-width: 220px; flex: 1; }
body.transfer-v2 .tr-field label { font-size: 12px; font-weight: 700; color: var(--tr-ink-2); margin: 0; }
body.transfer-v2 select, body.transfer-v2 textarea {
  border: 1px solid var(--tr-line-2); border-radius: 9px; padding: 9px 11px; font-size: 14px; font-family: inherit;
  background: #fff; color: var(--tr-ink); box-shadow: none; width: 100%; }
body.transfer-v2 select { height: 40px; }
body.transfer-v2 textarea { min-height: 220px; font-family: ui-monospace, Menlo, monospace; font-size: 14px; line-height: 1.5; resize: vertical; }
body.transfer-v2 select:focus, body.transfer-v2 textarea:focus { outline: none; border-color: var(--tr-sel-ink); box-shadow: 0 0 0 3px var(--tr-sel); }
body.transfer-v2 .tr-hint { font-size: 13px; color: var(--tr-ink-2); margin-top: 6px; }
body.transfer-v2 .tr-btn { border-radius: 10px; padding: 10px 18px; font-weight: 700; font-size: 14px; cursor: pointer; font-family: inherit;
  display: inline-flex; align-items: center; gap: 7px; border: 1px solid var(--tr-line-2); background: var(--tr-surface); color: var(--tr-ink); text-decoration: none; }
body.transfer-v2 .tr-btn:hover { background: var(--tr-surface-2); color: var(--tr-ink); }
body.transfer-v2 .tr-btn-primary { background: var(--tr-success); border-color: var(--tr-success); color: #fff; }
body.transfer-v2 .tr-btn-primary:hover { background: #24552F; color: #fff; }
body.transfer-v2 .tr-btn[disabled] { opacity: .55; cursor: not-allowed; }
body.transfer-v2 .tr-btn-swap { height: 40px; padding: 0 12px; }
body.transfer-v2 .tr-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
body.transfer-v2 .tr-summary { font-size: 14px; font-weight: 600; }
body.transfer-v2 .tr-table-wrap { overflow-x: auto; }
body.transfer-v2 table.tr-table { width: 100%; border-collapse: collapse; }
body.transfer-v2 .tr-table th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; color: var(--tr-ink-2);
  font-weight: 700; padding: 9px 10px; border-bottom: 1px solid var(--tr-line); white-space: nowrap; }
body.transfer-v2 .tr-table td { padding: 10px; border-bottom: 1px solid var(--tr-line); font-size: 13.5px; vertical-align: middle; color: var(--tr-ink); }
body.transfer-v2 .tr-table td.num { text-align: right; white-space: nowrap; }
body.transfer-v2 .tr-table tr.skip td { background: #FDF9F2; }
body.transfer-v2 .tr-pill { display: inline-block; font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 999px; border: 1px solid transparent; white-space: nowrap; }
body.transfer-v2 .tr-pill.ok { background: var(--tr-success-soft); color: var(--tr-success); border-color: #C6DFCC; }
body.transfer-v2 .tr-pill.bad { background: var(--tr-danger-soft); color: var(--tr-danger); border-color: #EBC9C9; }
body.transfer-v2 .tr-pill.dup { background: var(--tr-sel); color: var(--tr-sel-ink); border-color: #BCD6F7; }
body.transfer-v2 .tr-msg { border-radius: 10px; padding: 12px 14px; margin-bottom: 14px; font-size: 14px; font-weight: 600; }
body.transfer-v2 .tr-msg.ok { background: var(--tr-success-soft); color: var(--tr-success); border: 1px solid #C6DFCC; }
body.transfer-v2 .tr-msg.bad { background: var(--tr-danger-soft); color: var(--tr-danger); border: 1px solid #EBC9C9; }
body.transfer-v2 .tr-msg a { color: inherit; text-decoration: underline; }
body.transfer-v2 .tr-empty { color: var(--tr-ink-2); font-size: 14px; }
body.transfer-v2 .tr-link { color: var(--tr-sel-ink); font-weight: 600; cursor: pointer; }
@media (max-width: 640px) { body.transfer-v2 .tr-field { min-width: 100%; } }
</style>
