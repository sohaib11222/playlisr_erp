<style>
.as-task { max-width: 880px; margin: 0 auto; }
.as-task-card { background: #fff; border: 1px solid #edeae9; border-radius: 10px; }
.as-task-top { display: flex; align-items: center; justify-content: space-between; padding: 12px 20px; border-bottom: 1px solid #edeae9; gap: 10px; flex-wrap: wrap; }
.as-complete-btn { display: inline-flex; align-items: center; gap: 8px; border: 1px solid #cfcbcb; background: #fff; color: #1e1f21; border-radius: 6px; padding: 5px 12px; font-size: 13px; cursor: pointer; }
.as-complete-btn svg { width: 12px; height: 12px; stroke: #6d6e6f; }
.as-complete-btn:hover { border-color: #58a182; color: #2f7a57; background: #f2faf6; }
.as-complete-btn:hover svg { stroke: #58a182; }
.as-complete-btn.is-done { background: #e6f4ee; border-color: #58a182; color: #2f7a57; }
.as-complete-btn.is-done svg { stroke: #2f7a57; }
.as-back { color: #6d6e6f !important; font-size: 13px; text-decoration: none !important; }
.as-back:hover { color: #1e1f21 !important; }
.as-task-body { padding: 18px 24px 8px; }
.as-title-input { width: 100%; border: 1px solid transparent; border-radius: 6px; font-size: 24px; font-weight: 600; padding: 4px 8px; margin: 0 0 14px -8px; color: #1e1f21; background: transparent; }
.as-title-input:hover { border-color: #edeae9; }
.as-title-input:focus { border-color: #4573d2; outline: none; }
.as-fields { display: grid; grid-template-columns: 140px minmax(0, 1fr); row-gap: 6px; align-items: center; font-size: 14px; }
.as-fields > .k { color: #6d6e6f; font-size: 13px; padding: 6px 0; align-self: start; padding-top: 12px; }
.as-fields > .v { min-height: 36px; display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.as-fields .form-control, .as-in { border: 1px solid transparent; border-radius: 6px; box-shadow: none; background: transparent; height: 32px; padding: 4px 8px; font-size: 14px; width: auto; color: #1e1f21; }
.as-fields .form-control:hover, .as-in:hover { border-color: #edeae9; }
.as-fields .form-control:focus, .as-in:focus { border-color: #4573d2; outline: none; box-shadow: none; }
.as-fields .checkbox { margin: 0 !important; }
.as-fields .checkbox label { font-size: 13px; }
.as-fields small.text-muted { font-size: 12px; color: #9ca6af; }
.as-fields .select2-container--default .select2-selection--multiple { border: 1px solid transparent !important; border-radius: 6px; min-height: 32px; }
.as-fields .select2-container--default .select2-selection--multiple:hover { border-color: #edeae9 !important; }
.as-desc-label { color: #6d6e6f; font-size: 13px; margin: 18px 0 6px; }
.as-desc-box { width: 100%; min-height: 90px; border: 1px solid #edeae9; border-radius: 6px; padding: 10px 12px; font-size: 14px; resize: vertical; }
.as-desc-box:focus { border-color: #4573d2; outline: none; }
.as-save-bar { display: flex; gap: 8px; align-items: center; padding: 14px 24px; border-top: 1px solid #edeae9; }
.as-btn-ghost { border: 1px solid #cfcbcb; background: #fff; color: #1e1f21 !important; border-radius: 6px; padding: 6px 12px; font-size: 13px; text-decoration: none !important; }
.as-activity { background: #f9f8f8; border-top: 1px solid #edeae9; border-radius: 0 0 10px 10px; padding: 16px 24px 20px; }
.as-activity h4 { font-size: 14px; font-weight: 600; margin: 0 0 12px; }
.as-ev { display: flex; gap: 10px; align-items: flex-start; margin-bottom: 12px; font-size: 13px; }
.as-ev .as-dot { width: 24px; height: 24px; border-radius: 50%; background: #edeae9; color: #6d6e6f; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; flex: 0 0 auto; }
.as-ev .as-when { color: #9ca6af; font-size: 12px; margin-left: 4px; }
.as-ev .as-body { white-space: pre-wrap; margin-top: 2px; color: #1e1f21; }
.as-comment { display: flex; gap: 10px; align-items: flex-start; margin-top: 8px; }
.as-comment textarea { flex: 1; border: 1px solid #edeae9; border-radius: 8px; padding: 8px 12px; font-size: 14px; min-height: 40px; resize: vertical; background: #fff; }
.as-comment textarea:focus { border-color: #4573d2; outline: none; }
.as-comment button { border: 0; background: #4573d2; color: #fff; border-radius: 6px; padding: 7px 14px; font-size: 13px; }
.as-photo { background: #fff8e6; border: 1px solid #f5e0a8; border-radius: 6px; padding: 8px 12px; font-size: 13px; margin-top: 10px; }
@media (max-width: 767px) {
    .as-fields { grid-template-columns: 100px minmax(0, 1fr); }
    .as-task-body, .as-activity, .as-save-bar { padding-left: 14px; padding-right: 14px; }
}
</style>
