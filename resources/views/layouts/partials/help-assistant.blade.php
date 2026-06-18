{{-- In-ERP help assistant. Floating launcher + chat panel. Vanilla JS, no jQuery
     dependency, scoped styles so it never clashes with page CSS. --}}
@auth
<link href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
    #erp-help-root {
        --eh-bg: #FAF6EE;
        --eh-surface: #FFFFFF;
        --eh-ink: #1F1B16;
        --eh-ink-2: #5A5045;
        --eh-accent: #FFF2B3;
        --eh-accent-deep: #E8CF68;
        --eh-line: #ECE3CF;
        font-family: 'Inter Tight', system-ui, sans-serif;
    }
    #erp-help-launcher {
        position: fixed; bottom: 22px; right: 22px; z-index: 99998;
        display: inline-flex; align-items: center; gap: 8px;
        background: var(--eh-ink); color: #fff; border: none;
        border-radius: 999px; padding: 12px 20px; cursor: pointer;
        font: 600 14px 'Inter Tight', system-ui, sans-serif;
        box-shadow: 0 6px 20px rgba(31,27,22,.22);
        transition: transform .12s ease;
    }
    #erp-help-launcher:hover { transform: translateY(-1px); }
    #erp-help-panel {
        position: fixed; bottom: 22px; right: 22px; z-index: 99999;
        width: 380px; max-width: calc(100vw - 32px);
        height: 70vh; max-height: 620px;
        background: var(--eh-surface);
        border: 1px solid var(--eh-line); border-radius: 16px;
        box-shadow: 0 18px 50px rgba(31,27,22,.28);
        display: none; flex-direction: column; overflow: hidden;
    }
    #erp-help-panel.open { display: flex; }
    #erp-help-head {
        display: flex; align-items: center; justify-content: space-between;
        background: var(--eh-ink); color: #fff; padding: 14px 16px;
    }
    #erp-help-head .eh-title { font-weight: 700; font-size: 15px; }
    #erp-help-head .eh-sub { font-size: 11px; color: rgba(255,255,255,.7); margin-top: 2px; }
    #erp-help-close {
        background: none; border: none; color: rgba(255,255,255,.85);
        font-size: 22px; line-height: 1; cursor: pointer; padding: 0 4px;
    }
    #erp-help-msgs {
        flex: 1; overflow-y: auto; background: var(--eh-bg);
        padding: 16px 14px; display: flex; flex-direction: column; gap: 12px;
    }
    .eh-row { display: flex; }
    .eh-row.user { justify-content: flex-end; }
    .eh-bubble {
        max-width: 86%; padding: 10px 13px; border-radius: 14px;
        font-size: 14px; line-height: 1.5; white-space: pre-wrap; word-break: break-word;
    }
    .eh-row.user .eh-bubble { background: var(--eh-ink); color: #fff; }
    .eh-row.bot .eh-bubble {
        background: var(--eh-surface); color: var(--eh-ink);
        border: 1px solid var(--eh-line);
    }
    .eh-bubble a { color: var(--eh-ink); font-weight: 600; text-decoration: underline; }
    #erp-help-chips { display: flex; flex-wrap: wrap; gap: 8px; padding-top: 2px; }
    .eh-chip {
        background: var(--eh-surface); border: 1px solid var(--eh-line);
        border-radius: 999px; padding: 7px 12px; cursor: pointer;
        font: 600 12.5px 'Inter Tight', system-ui, sans-serif; color: var(--eh-ink-2);
    }
    .eh-chip:hover { background: var(--eh-accent); border-color: var(--eh-accent-deep); color: var(--eh-ink); }
    #erp-help-form {
        display: flex; gap: 8px; padding: 10px;
        border-top: 1px solid var(--eh-line); background: var(--eh-surface);
    }
    #erp-help-input {
        flex: 1; border: 1px solid var(--eh-line); background: var(--eh-bg);
        border-radius: 999px; padding: 10px 15px; font-size: 14px;
        color: var(--eh-ink); outline: none;
    }
    #erp-help-input:focus { border-color: var(--eh-accent-deep); }
    #erp-help-send {
        border: none; background: var(--eh-ink); color: #fff;
        border-radius: 999px; padding: 10px 16px; cursor: pointer;
        font: 600 14px 'Inter Tight', system-ui, sans-serif;
    }
    #erp-help-send:disabled { opacity: .4; cursor: default; }
</style>

<div id="erp-help-root">
    <button type="button" id="erp-help-launcher" aria-label="Ask the ERP for help">Ask the ERP</button>

    <section id="erp-help-panel" role="dialog" aria-label="ERP help assistant">
        <div id="erp-help-head">
            <div>
                <div class="eh-title">Ask the ERP</div>
                <div class="eh-sub">How do I do anything in here?</div>
            </div>
            <button type="button" id="erp-help-close" aria-label="Close help">&times;</button>
        </div>

        <div id="erp-help-msgs"></div>

        <form id="erp-help-form" autocomplete="off">
            <input id="erp-help-input" type="text" placeholder="Ask how to do something..." />
            <button type="submit" id="erp-help-send">Send</button>
        </form>
    </section>
</div>

<script>
(function () {
    var ENDPOINT = "{{ route('help-assistant.message') }}";
    var CSRF = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    var launcher = document.getElementById('erp-help-launcher');
    var panel = document.getElementById('erp-help-panel');
    var closeBtn = document.getElementById('erp-help-close');
    var msgs = document.getElementById('erp-help-msgs');
    var form = document.getElementById('erp-help-form');
    var input = document.getElementById('erp-help-input');
    var sendBtn = document.getElementById('erp-help-send');

    var CHIPS = [
        'How do I ring up a sale?',
        'How do I add a new product?',
        'How do I do a return or refund?',
        'Where do I see today’s sales?',
        'How do I receive new stock?',
        'How do I close my register?'
    ];

    // History sent to the API (role/content only).
    var history = [];
    var loading = false;

    function escapeHtml(s) {
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // Linkify ERP-relative paths, full URLs and emails inside bot replies.
    function richText(text) {
        var safe = escapeHtml(text);
        var re = /(https?:\/\/[^\s]+|\/[a-zA-Z][\w\-\/]*|[\w.+-]+@[\w.-]+\.\w+)/g;
        return safe.replace(re, function (tok) {
            var href = tok;
            if (tok.indexOf('@') > -1 && tok.indexOf('http') !== 0 && tok.charAt(0) !== '/') {
                href = 'mailto:' + tok;
            }
            var ext = tok.indexOf('http') === 0;
            return '<a href="' + href + '"' + (ext ? ' target="_blank" rel="noopener noreferrer"' : '') + '>' + tok + '</a>';
        });
    }

    function addRow(role, text, isHtml) {
        var row = document.createElement('div');
        row.className = 'eh-row ' + (role === 'user' ? 'user' : 'bot');
        var bubble = document.createElement('div');
        bubble.className = 'eh-bubble';
        if (isHtml) { bubble.innerHTML = text; } else { bubble.textContent = text; }
        row.appendChild(bubble);
        msgs.appendChild(row);
        msgs.scrollTop = msgs.scrollHeight;
        return bubble;
    }

    function renderChips() {
        var wrap = document.createElement('div');
        wrap.id = 'erp-help-chips';
        CHIPS.forEach(function (label) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'eh-chip';
            b.textContent = label;
            b.addEventListener('click', function () {
                if (wrap.parentNode) { wrap.parentNode.removeChild(wrap); }
                send(label);
            });
            wrap.appendChild(b);
        });
        msgs.appendChild(wrap);
    }

    function setLoading(on) {
        loading = on;
        sendBtn.disabled = on;
        if (on) {
            var b = addRow('bot', 'Thinking…');
            b.id = 'erp-help-typing';
        } else {
            var t = document.getElementById('erp-help-typing');
            if (t && t.parentNode && t.parentNode.parentNode) {
                t.parentNode.parentNode.removeChild(t.parentNode);
            }
        }
    }

    function send(raw) {
        var text = (raw || input.value).trim();
        if (!text || loading) return;
        input.value = '';
        addRow('user', text);
        history.push({ role: 'user', content: text });
        setLoading(true);

        fetch(ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: JSON.stringify({ messages: history })
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            setLoading(false);
            var reply = (data && data.reply) ? data.reply : 'Sorry, I could not get that. Try asking a manager.';
            addRow('bot', richText(reply), true);
            history.push({ role: 'assistant', content: reply });
        })
        .catch(function () {
            setLoading(false);
            addRow('bot', 'I am having trouble connecting. Try again, or ask a manager.');
        });
    }

    var greeted = false;
    function openPanel() {
        panel.classList.add('open');
        launcher.style.display = 'none';
        if (!greeted) {
            addRow('bot', 'Hi! Ask me how to do anything in the ERP, or tap a question below.');
            renderChips();
            greeted = true;
        }
        input.focus();
    }
    function closePanel() {
        panel.classList.remove('open');
        launcher.style.display = 'inline-flex';
    }

    launcher.addEventListener('click', openPanel);
    closeBtn.addEventListener('click', closePanel);
    form.addEventListener('submit', function (e) { e.preventDefault(); send(); });
})();
</script>
@endauth
