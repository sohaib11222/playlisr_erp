<style>
.open-shell {
    --d-bg: #FAF6EE;
    --d-surface: #FFFFFF;
    --d-surface-2: #F7F1E3;
    --d-ink: #1F1B16;
    --d-ink-2: #5A5045;
    --d-ink-3: #8E8273;
    --d-line: #ECE3CF;
    --d-line-2: #DFD2B3;
    --d-accent: #FFF2B3;
    --d-accent-deep: #E8CF68;
    --d-accent-soft: #FFF9DB;
    --d-accent-text: #5A4410;
    --d-good: #2E7D32;
    --d-warn: #B26A00;
    --d-radius: 12px;
    --d-radius-sm: 10px;

    font-family: "Inter Tight", system-ui, sans-serif;
    color: var(--d-ink);
    -webkit-font-smoothing: antialiased;
    background: var(--d-bg);
    max-width: 820px;
    margin: 12px auto 48px;
    padding: 0 16px;
}
.open-shell *, .open-shell *::before, .open-shell *::after { box-sizing: border-box; }

.open-shell .open-header { margin: 12px 4px 16px; }
.open-shell .open-header h1 {
    font-size: 26px; font-weight: 800; letter-spacing: -.01em;
    margin: 0; line-height: 1.2;
}
.open-shell .open-header p {
    font-size: 14px; color: var(--d-ink-3); margin: 6px 0 0; line-height: 1.5;
}

.open-shell .card {
    background: var(--d-surface); border: 1px solid var(--d-line);
    border-radius: var(--d-radius); box-shadow: 0 1px 2px rgba(31,27,22,.06);
    padding: 18px 20px; margin-bottom: 16px;
}

.open-shell .callout {
    background: var(--d-accent-soft); border: 1px solid var(--d-accent-deep);
    color: var(--d-accent-text); border-radius: var(--d-radius-sm);
    padding: 12px 16px; margin-bottom: 16px; font-weight: 700; font-size: 14.5px;
    display: flex; align-items: center; gap: 9px;
}

.open-shell .topbar {
    display: flex; gap: 14px; align-items: center; flex-wrap: wrap;
    margin-bottom: 4px;
}
.open-shell .progress-pill {
    flex: 0 0 auto; background: var(--d-surface-2); border: 1px solid var(--d-line);
    border-radius: 999px; padding: 8px 16px; font-weight: 700; font-size: 14px;
    white-space: nowrap;
}
.open-shell .saved-note { font-size: 13px; font-weight: 700; color: var(--d-good); opacity: 0; transition: opacity .2s; }
.open-shell .saved-note.show { opacity: 1; }
.open-shell .date-note { font-size: 13px; color: var(--d-ink-3); margin-left: auto; }

.open-shell .grp { margin-top: 18px; }
.open-shell .grp:first-child { margin-top: 4px; }
.open-shell .grp h3 {
    font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: .05em;
    color: var(--d-ink-2); margin: 0 0 8px; padding-bottom: 6px;
    border-bottom: 2px solid var(--d-accent);
}

.open-shell .item {
    display: flex; align-items: center; gap: 10px;
    padding: 11px 12px; border: 1px solid var(--d-line); border-radius: var(--d-radius-sm);
    margin-bottom: 7px; transition: background .12s, border-color .12s;
}
.open-shell .item:hover { background: var(--d-surface-2); }
.open-shell .item-main { display: flex; align-items: flex-start; gap: 12px; flex: 1; cursor: pointer; user-select: none; }
.open-shell .item input[type=checkbox] {
    appearance: none; -webkit-appearance: none; flex: 0 0 auto;
    width: 22px; height: 22px; margin-top: 1px; border: 2px solid var(--d-line-2);
    border-radius: 6px; background: #fff; cursor: pointer; position: relative;
}
.open-shell .item input[type=checkbox]:checked { background: var(--d-accent); border-color: var(--d-accent-deep); }
.open-shell .item input[type=checkbox]:checked::after {
    content: ""; position: absolute; left: 6px; top: 2px; width: 6px; height: 11px;
    border: solid var(--d-accent-text); border-width: 0 2.5px 2.5px 0; transform: rotate(45deg);
}
.open-shell .item .txt { font-size: 15px; line-height: 1.35; color: var(--d-ink); padding-top: 1px; }
.open-shell .item input[type=checkbox]:checked + .txt { color: var(--d-ink-3); text-decoration: line-through; }
.open-shell .item .txt a { color: var(--d-accent-text); font-weight: 700; }

.open-shell table.hist { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.open-shell table.hist th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--d-ink-3); padding: 6px 8px; border-bottom: 1px solid var(--d-line); }
.open-shell table.hist td { padding: 8px; border-bottom: 1px solid var(--d-line); vertical-align: top; color: var(--d-ink-2); }
.open-shell .tag { display: inline-block; font-size: 12px; font-weight: 700; padding: 2px 9px; border-radius: 999px; }
.open-shell .tag.full { background: #E6F4E6; color: var(--d-good); }
.open-shell .tag.part { background: #FBEBD2; color: var(--d-warn); }
</style>
