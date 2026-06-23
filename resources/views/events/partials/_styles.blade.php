<style>
body.pos-v2 .ev-wrap { max-width: 1100px; margin: 0 auto; padding: 18px 16px 60px; font-family: "Inter Tight", system-ui, sans-serif; color: var(--pos-ink); }
body.pos-v2 .ev-head { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:18px; }
body.pos-v2 .ev-wrap h1 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
body.pos-v2 .ev-wrap .sub { color: #6b6253; margin: 0; font-size: 14px; }
body.pos-v2 .ev-card { background: var(--pos-surface); border: 1px solid var(--pos-line); border-radius: 14px; padding: 18px 20px; margin-bottom: 22px; }
body.pos-v2 .ev-card h2 { font-size: 17px; font-weight: 700; margin: 0 0 14px; }
body.pos-v2 .ev-create-summary { cursor:pointer; font-weight:700; font-size:15px; list-style:none; }
body.pos-v2 .ev-create-summary::-webkit-details-marker { display:none; }
body.pos-v2 .ev-row { display: flex; flex-wrap: wrap; gap: 14px; margin-bottom: 12px; }
body.pos-v2 .ev-field { display: flex; flex-direction: column; gap: 5px; }
body.pos-v2 .ev-field label { font-size: 12px; font-weight: 600; color: #5a5145; }
body.pos-v2 .ev-field input, body.pos-v2 .ev-field select, body.pos-v2 .ev-field textarea {
  border: 1px solid var(--pos-line-2); border-radius: 9px; padding: 9px 11px; font-size: 14px;
  font-family: inherit; background: #fff; min-width: 0; }
body.pos-v2 .ev-field textarea { resize: vertical; min-height: 70px; }
body.pos-v2 .ev-field input:focus, body.pos-v2 .ev-field select:focus, body.pos-v2 .ev-field textarea:focus {
  outline: none; border-color: var(--pos-accent-deep); box-shadow: 0 0 0 3px var(--pos-accent-soft); }
body.pos-v2 .ev-checks { display:flex; gap:16px; align-items:center; flex-wrap:wrap; }
body.pos-v2 .ev-checks label { font-size:13px; font-weight:600; color:#5a5145; display:flex; gap:6px; align-items:center; }
body.pos-v2 .btn-accent { background: var(--pos-accent); color: var(--pos-accent-text); border: 1px solid var(--pos-accent-deep);
  border-radius: 10px; padding: 10px 18px; font-weight: 700; font-size: 14px; cursor: pointer; font-family: inherit; }
body.pos-v2 .btn-accent:hover { background: var(--pos-accent-deep); }
body.pos-v2 .btn-ghost { background: transparent; border: 1px dashed var(--pos-line-2); border-radius: 9px;
  padding: 9px 16px; font-weight: 600; font-size: 13px; cursor: pointer; color: #5a5145; font-family: inherit; }
body.pos-v2 .btn-link { background: none; border: none; color: #a23b3b; cursor: pointer; font-size: 13px; padding: 4px; font-family: inherit; }
body.pos-v2 .ev-tbl { width: 100%; border-collapse: collapse; }
body.pos-v2 .ev-tbl th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em;
  color: #8a8070; font-weight: 700; padding: 8px 10px; border-bottom: 1px solid var(--pos-line); }
body.pos-v2 .ev-tbl td { padding: 11px 10px; border-bottom: 1px solid var(--pos-line); font-size: 14px; vertical-align: middle; }
body.pos-v2 .ev-tbl .ev-name { font-weight:700; }
body.pos-v2 .ev-meta { color:#8a8070; font-size:12.5px; }
body.pos-v2 .pill { display:inline-block; font-size:11px; font-weight:600; padding:2px 9px; border-radius:999px;
  background: var(--pos-accent-soft); color: var(--pos-accent-text); border:1px solid var(--pos-line-2); }
body.pos-v2 .pill.lp { background:#efe7fb; color:#5b3aa3; border-color:#dcccf3; }
body.pos-v2 .prep-badge { font-size:12px; font-weight:700; }
body.pos-v2 .prep-badge.done { color:#2e7d32; }
body.pos-v2 .prep-badge.todo { color:#8a5a14; }
body.pos-v2 .prep-badge.na { color:#9a948a; font-weight:500; }
body.pos-v2 .alert-ok { background: #e7f3e8; border: 1px solid #bfe0c2; color: #226128; border-radius: 10px; padding: 11px 15px; margin-bottom: 18px; }
body.pos-v2 .alert-err { background: #fbe9e9; border: 1px solid #efc4c4; color: #9a2c2c; border-radius: 10px; padding: 11px 15px; margin-bottom: 18px; }
body.pos-v2 .empty { color: #8a8070; padding: 14px 4px; font-size: 14px; }
body.pos-v2 .prep-list { list-style:none; padding:0; margin:0; }
body.pos-v2 .prep-list li { display:flex; gap:10px; align-items:flex-start; padding:10px 0; border-bottom:1px solid var(--pos-line); }
body.pos-v2 .prep-list li:last-child { border-bottom:none; }
body.pos-v2 .prep-list .due { font-size:11px; color:#8a8070; white-space:nowrap; }
body.pos-v2 .prep-list .lbl { font-size:14px; }
body.pos-v2 .prep-note { width:100%; margin-top:6px; border:1px solid var(--pos-line-2); border-radius:8px; padding:7px 9px; font-size:13px; font-family:inherit; }
body.pos-v2 a.ev-edit { color: var(--pos-accent-text); font-weight:700; text-decoration:none; }
body.pos-v2 a.ev-edit:hover { text-decoration:underline; }
</style>
