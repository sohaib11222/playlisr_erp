{{-- "Report a bug or a fix" button on every ERP page (not the POS screen).
     Sends to the bug queue (/bug-fixes, BugQueueController@report). Plain JS,
     scoped styles, sits just above the help assistant launcher. --}}
@auth
<style>
    #erp-bug-launcher {
        position: fixed; bottom: 78px; right: 22px; z-index: 99990;
        background: #fff; color: #1F1B16; border: 1px solid #DFD2B3;
        border-radius: 999px; padding: 9px 16px; cursor: pointer;
        font: 600 13px 'Inter Tight', system-ui, sans-serif;
        box-shadow: 0 4px 14px rgba(31,27,22,.15);
    }
    #erp-bug-launcher:hover { background: #FAF6EE; }
    #erp-bug-overlay {
        position: fixed; inset: 0; z-index: 100000; background: rgba(31,27,22,.45);
        display: none; align-items: center; justify-content: center; padding: 16px;
    }
    #erp-bug-overlay.open { display: flex; }
    #erp-bug-box {
        background: #fff; border-radius: 16px; width: 460px; max-width: 100%;
        padding: 22px; font-family: 'Inter Tight', system-ui, sans-serif; color: #1F1B16;
        box-shadow: 0 18px 50px rgba(31,27,22,.3);
    }
    #erp-bug-box h3 { margin: 0 0 4px; font-size: 18px; font-weight: 800; }
    #erp-bug-box .eb-sub { font-size: 13px; color: #3D352C; margin-bottom: 14px; }
    #erp-bug-box .eb-kinds { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
    #erp-bug-box .eb-kind {
        border: 1px solid #C9D9EA; background: #fff; color: #1F1B16; border-radius: 999px;
        padding: 7px 14px; font-weight: 600; font-size: 13px; cursor: pointer; font-family: inherit;
    }
    #erp-bug-box .eb-kind.on { background: #DCEBFA; border-color: #8DB6DE; }
    #erp-bug-box textarea {
        width: 100%; min-height: 120px; padding: 10px 12px; font-size: 14px; line-height: 1.45; font-family: inherit;
        border: 1px solid #DFD2B3; border-radius: 10px; resize: vertical; box-sizing: border-box;
    }
    #erp-bug-box .eb-shot { font-size: 13px; margin: 10px 0 4px; }
    #erp-bug-box .eb-hint { font-size: 12px; color: #3D352C; }
    #erp-bug-box .eb-actions { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 16px; }
    #erp-bug-box .eb-btn { padding: 9px 16px; border-radius: 9px; font-weight: 700; font-size: 13px; font-family: inherit; cursor: pointer; border: 1px solid #DFD2B3; background: #fff; color: #1F1B16; }
    #erp-bug-box .eb-btn.dark { background: #1F1B16; border-color: #1F1B16; color: #fff; }
    #erp-bug-box .eb-msg { font-size: 13px; margin-top: 10px; }
    #erp-bug-box a { color: #1F5FA8; font-weight: 600; }
</style>

<button type="button" id="erp-bug-launcher">Report a bug or a fix</button>

<div id="erp-bug-overlay" role="dialog" aria-label="Report a bug or a fix">
  <div id="erp-bug-box">
    <form id="erp-bug-form">
      <h3>Report a bug or a fix</h3>
      <div class="eb-sub">Tell us what happened or what you want changed. You can follow it on <a href="/bug-fixes?tab=mine">Bug Fixes</a>.</div>
      <div class="eb-kinds">
        <button type="button" class="eb-kind on" data-kind="bug">Something is broken</button>
        <button type="button" class="eb-kind" data-kind="request">I want a fix or change</button>
      </div>
      <textarea name="description" id="erp-bug-desc" placeholder="What happened? What did you expect? Which product, order or customer?" required></textarea>
      <div class="eb-shot">
        Screenshot (optional): <input type="file" name="screenshot" id="erp-bug-file" accept="image/*">
      </div>
      <div class="eb-hint" id="erp-bug-paste-hint">Tip: you can also paste a screenshot into the box.</div>
      <div class="eb-msg" id="erp-bug-msg"></div>
      <div class="eb-actions">
        <button type="button" class="eb-btn" id="erp-bug-cancel">Cancel</button>
        <button type="submit" class="eb-btn dark" id="erp-bug-send">Send</button>
      </div>
    </form>
  </div>
</div>

<script>
(function () {
    var launcher = document.getElementById('erp-bug-launcher');
    var overlay = document.getElementById('erp-bug-overlay');
    var form = document.getElementById('erp-bug-form');
    var desc = document.getElementById('erp-bug-desc');
    var fileInput = document.getElementById('erp-bug-file');
    var msg = document.getElementById('erp-bug-msg');
    var sendBtn = document.getElementById('erp-bug-send');
    var hint = document.getElementById('erp-bug-paste-hint');
    var kind = 'bug';
    var pasted = null;
    if (!launcher || !overlay) return;

    function open() { overlay.classList.add('open'); msg.textContent = ''; setTimeout(function () { desc.focus(); }, 30); }
    function close() { overlay.classList.remove('open'); }

    launcher.addEventListener('click', open);
    document.getElementById('erp-bug-cancel').addEventListener('click', close);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && overlay.classList.contains('open')) close(); });

    Array.prototype.forEach.call(document.querySelectorAll('#erp-bug-box .eb-kind'), function (b) {
        b.addEventListener('click', function () {
            Array.prototype.forEach.call(document.querySelectorAll('#erp-bug-box .eb-kind'), function (x) { x.classList.remove('on'); });
            b.classList.add('on');
            kind = b.getAttribute('data-kind');
            desc.placeholder = kind === 'request'
                ? 'What should change? Which page? How should it work instead?'
                : 'What happened? What did you expect? Which product, order or customer?';
        });
    });

    desc.addEventListener('paste', function (e) {
        var items = (e.clipboardData || {}).items || [];
        for (var i = 0; i < items.length; i++) {
            if (items[i].type && items[i].type.indexOf('image') === 0) {
                pasted = items[i].getAsFile();
                hint.textContent = 'Screenshot attached from paste.';
                e.preventDefault();
                return;
            }
        }
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!desc.value.trim()) { desc.focus(); return; }
        var fd = new FormData();
        fd.append('description', desc.value.trim());
        fd.append('kind', kind);
        fd.append('page_url', window.location.href);
        if (fileInput.files && fileInput.files[0]) fd.append('screenshot', fileInput.files[0]);
        else if (pasted) fd.append('screenshot', pasted, 'pasted.png');
        var tokenMeta = document.querySelector('meta[name="csrf-token"]');
        sendBtn.disabled = true; sendBtn.textContent = 'Sending...';
        fetch('/bug-fixes/report', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': tokenMeta ? tokenMeta.getAttribute('content') : '', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: fd,
            credentials: 'same-origin'
        }).then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
          .then(function (res) {
            if (!res.ok || !res.j.success) {
                var err = (res.j && res.j.errors) ? Object.values(res.j.errors)[0][0] : (res.j.message || 'Could not send. Try again.');
                msg.style.color = '#8A3A2E'; msg.textContent = err; return;
            }
            msg.style.color = '#2F6B3E';
            msg.innerHTML = 'Thanks! #' + res.j.id + ' is in the queue. <a href="' + res.j.url + '">Track it here</a>.';
            desc.value = ''; fileInput.value = ''; pasted = null; hint.textContent = 'Tip: you can also paste a screenshot into the box.';
        }).catch(function () {
            msg.style.color = '#8A3A2E'; msg.textContent = 'Could not send. Check your connection and try again.';
        }).then(function () { sendBtn.disabled = false; sendBtn.textContent = 'Send'; });
    });
})();
</script>
@endauth
