<style>
.as-wrap { max-width: 1120px; margin: 0 auto; padding: 8px 4px 40px; color: #1e1f21; }
.as-head { display: flex; align-items: center; gap: 14px; margin: 6px 0 4px; }
.as-avatar { width: 32px; height: 32px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; color: #fff; flex: 0 0 auto; text-transform: uppercase; }
.as-avatar.lg { width: 48px; height: 48px; font-size: 18px; }
.as-avatar.sm { width: 24px; height: 24px; font-size: 10px; }
.as-avatars { display: inline-flex; }
.as-avatars .as-avatar { border: 2px solid #fff; margin-left: -6px; }
.as-avatars .as-avatar:first-child { margin-left: 0; }
.as-title { font-size: 24px; font-weight: 600; margin: 0; line-height: 1.2; }
.as-sub { color: #6d6e6f; font-size: 13px; margin-top: 2px; }
.as-nav { display: flex; gap: 22px; overflow-x: auto; overflow-y: hidden; scrollbar-width: none; white-space: nowrap; border-bottom: 1px solid #edeae9; margin: 14px 0 18px; }
.as-nav a { padding: 8px 0; color: #6d6e6f !important; font-size: 14px; border-bottom: 2px solid transparent; margin-bottom: -1px; text-decoration: none !important; }
.as-nav a.on { color: #1e1f21 !important; border-bottom-color: #1e1f21; font-weight: 500; }
.as-nav a:hover { color: #1e1f21 !important; }
.as-btn:hover { background: #3a64bd; }
.as-table { background: #fff; border: 1px solid #edeae9; border-radius: 8px; overflow: hidden; }
.as-cols, .as-row { display: grid; grid-template-columns: minmax(0, 1fr) 140px 100px 110px 120px; align-items: center; }
.as-cols { font-size: 12px; color: #6d6e6f; border-bottom: 1px solid #edeae9; }
.as-cols > div { padding: 8px 12px; border-left: 1px solid #edeae9; }
.as-cols > div:first-child { border-left: 0; padding-left: 16px; }
.as-section-h { display: flex; align-items: center; gap: 8px; padding: 14px 16px 8px; font-size: 16px; font-weight: 600; cursor: pointer; user-select: none; }
.as-section-h .as-caret { transition: transform .15s; color: #6d6e6f; font-size: 12px; width: 12px; }
.as-section.collapsed .as-caret { transform: rotate(-90deg); }
.as-section.collapsed .as-rows { display: none; }
.as-count { color: #6d6e6f; font-weight: 400; font-size: 13px; }
.as-row { border-top: 1px solid #edeae9; min-height: 40px; font-size: 14px; transition: background .1s, opacity .3s; }
.as-row:hover { background: #f9f8f8; }
.as-row > div { padding: 6px 12px; border-left: 1px solid #edeae9; min-height: 40px; display: flex; align-items: center; }
.as-row > div:first-child { border-left: 0; padding-left: 16px; gap: 10px; min-width: 0; }
.as-name, .as-name:visited { flex: 0 1 auto; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #1e1f21 !important; text-decoration: none !important; }
.as-name:hover { text-decoration: underline !important; }
.as-meta { color: #6d6e6f; font-size: 12px; white-space: nowrap; flex: 0 0 auto; }
.as-row .as-pill { flex: 0 0 auto; }
.as-check { width: 18px; height: 18px; border-radius: 50%; border: 1.5px solid #9ca6af; background: #fff; flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; padding: 0; transition: all .15s; }
.as-check svg { width: 10px; height: 10px; stroke: #9ca6af; }
.as-check:hover { border-color: #58a182; background: #e6f4ee; }
.as-check:hover svg { stroke: #58a182; }
.as-row.done .as-check, .as-check.done { background: #58a182; border-color: #58a182; }
.as-row.done .as-check svg, .as-check.done svg { stroke: #fff; }
.as-row.done .as-name { color: #6d6e6f !important; text-decoration: line-through !important; }
.as-row.done { opacity: .55; }
.as-pill { display: inline-block; border-radius: 4px; padding: 1px 8px; font-size: 12px; font-weight: 500; line-height: 20px; white-space: nowrap; }
.as-p-high { background: #fde2e4; color: #b3261e; }
.as-p-medium { background: #fdefc6; color: #8a6100; }
.as-p-low { background: #dbeafe; color: #1d4ed8; }
.as-tag { background: #f1f0ef; color: #4b4c4e; }
.as-tag-shift { background: #e6f4ee; color: #2f7a57; }
.as-due-late { color: #c92f54; }
.as-due-today { color: #2f7a57; }
.as-empty { padding: 10px 16px 14px 46px; color: #6d6e6f; font-size: 13px; border-top: 1px solid #edeae9; }
.as-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 18px; }
.as-card { background: #fff; border: 1px solid #edeae9; border-radius: 8px; padding: 16px 18px; }
.as-card .n { font-size: 30px; font-weight: 600; line-height: 1.1; }
.as-card .l { color: #6d6e6f; font-size: 13px; margin-top: 4px; }
.as-card.good .n { color: #2f7a57; }
.as-card.warn .n { color: #b86e00; }
.as-card.info .n { color: #4573d2; }
.as-bar { height: 6px; background: #f1f0ef; border-radius: 3px; overflow: hidden; width: 100%; }
.as-bar > span { display: block; height: 100%; background: #58a182; border-radius: 3px; }
.as-note { color: #6d6e6f; font-size: 12px; margin: 10px 2px 0; }
.as-toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: #1e1f21; color: #fff; padding: 10px 16px; border-radius: 8px; font-size: 13px; z-index: 9999; display: none; max-width: 90vw; }
.as-toast a { color: #9ec1ff; }
.as-tile { width: 28px; height: 28px; border-radius: 6px; display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: 13px; flex: 0 0 auto; }
.as-pname { display: flex; flex-direction: column; min-width: 0; }
.as-pname .as-name { font-weight: 500; }
.as-pname .as-meta { overflow: hidden; text-overflow: ellipsis; }
.as-status { border: 0; border-radius: 12px; padding: 2px 10px; font-size: 12px; font-weight: 500; height: 24px; cursor: pointer; -webkit-appearance: none; appearance: none; }
.as-st-not_started { background: #f1f0ef; color: #4b4c4e; }
.as-st-in_progress { background: #fdefc6; color: #8a6100; }
.as-st-complete { background: #e6f4ee; color: #2f7a57; }
.as-join { border: 1px dashed #9ca6af; background: #fff; color: #6d6e6f; border-radius: 12px; font-size: 12px; padding: 1px 8px; margin-left: 6px; cursor: pointer; white-space: nowrap; }
.as-join:hover { border-color: #4573d2; color: #4573d2; }
.as-leave { border: 0; background: none; color: #9ca6af; font-size: 11px; padding: 0 0 0 4px; cursor: pointer; }
.as-leave:hover { color: #c92f54; }
.as-acts { gap: 4px; justify-content: flex-end; }
.as-icon-btn { border: 0; background: none; color: #9ca6af; padding: 4px 6px; border-radius: 4px; cursor: pointer; }
.as-icon-btn:hover { background: #f1f0ef; color: #1e1f21; }
.as-icon-btn.del:hover { color: #c92f54; }
.as-row .as-acts { opacity: 0; transition: opacity .1s; }
.as-row:hover .as-acts { opacity: 1; }

.as-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
.as-tools { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin: 0; }
.as-btn { display: inline-flex; align-items: center; gap: 6px; background: #4573d2; color: #fff !important; border-radius: 6px; padding: 0 12px !important; height: 32px; font-size: 13px !important; font-weight: 500; white-space: nowrap; text-decoration: none !important; line-height: 1; }
.as-seg { display: inline-flex; border: 1px solid #e0dcdb; border-radius: 6px; overflow: hidden; background: #fff; height: 32px; }
.as-seg a { display: inline-flex; align-items: center; gap: 5px; padding: 0 12px; font-size: 13px !important; color: #6d6e6f !important; text-decoration: none !important; border-left: 1px solid #e0dcdb; white-space: nowrap; }
.as-seg a:first-child { border-left: 0; }
.as-seg a.on { background: #f1f0ef; color: #1e1f21 !important; font-weight: 500; }
.as-filter { border: 1px solid #e0dcdb !important; border-radius: 6px !important; background: #fff !important; font-size: 13px !important; height: 32px !important; padding: 0 8px !important; color: #1e1f21; width: auto !important; box-shadow: none !important; }
.as-chip { display: inline-flex; align-items: center; gap: 6px; height: 32px; padding: 0 12px; border: 1px solid #e0dcdb; border-radius: 6px; background: #fff; font-size: 13px !important; font-weight: 400 !important; color: #6d6e6f; margin: 0 !important; cursor: pointer; white-space: nowrap; }
.as-chip.on { background: #e8eefb; border-color: #4573d2; color: #2d5bb8; }
@media (max-width: 767px) {
    .as-row .as-acts { opacity: 1; }
    .as-cols { display: none; }
    .as-row { grid-template-columns: minmax(0, 1fr) auto; }
    .as-row > div { border-left: 0; }
    .as-row > .as-hide-sm, .as-row .as-hide-sm { display: none; }
    .as-name { white-space: normal; }
    .as-wrap { padding-left: 0; padding-right: 0; }
}
</style>
<div class="as-toast" id="asToast"></div>
