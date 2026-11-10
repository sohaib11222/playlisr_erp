{{-- "Report a bug or a fix" on every ERP page. Floating button everywhere
     except the POS screen (there it opens from the POS menu via
     window.erpOpenBugReport()). Sends to BugQueueController@report.
     Built to be as quick as possible: type, optionally tag people, Send.
     A picture of the page is attached automatically. --}}
@auth
@php
    $__bq_users = \App\Http\Controllers\BugQueueController::taggableUsers((int) session('user.business_id'));
    $__bq_pos = !empty($pos_layout);
@endphp
<style>
    #erp-bug-launcher {
        position: fixed; bottom: 78px; right: 22px; z-index: 99990;
        background: #fff; color: #1F1B16; border: 1px solid #DFD2B3;
        border-radius: 999px; padding: 9px 16px; cursor: pointer;
        font-weight: 600; font-size: 13px; font-family: 'Inter Tight', system-ui, sans-serif;
        box-shadow: 0 4px 14px rgba(31,27,22,.15);
    }
    #erp-bug-launcher:hover { background: #FAF6EE; }
    #erp-bug-overlay {
        position: fixed; inset: 0; z-index: 100000; background: rgba(31,27,22,.45);
        display: none; align-items: center; justify-content: center; padding: 16px;
    }
    #erp-bug-overlay.open { display: flex; }
    #erp-bug-box {
        background: #fff; border-radius: 16px; width: 480px; max-width: 100%; max-height: calc(100vh - 32px); overflow-y: auto;
        padding: 22px; font-family: 'Inter Tight', system-ui, sans-serif; color: #1F1B16;
        box-shadow: 0 18px 50px rgba(31,27,22,.3); box-sizing: border-box;
    }
    #erp-bug-box h3 { margin: 0 0 12px; font-size: 18px; font-weight: 800; }
    #erp-bug-box .eb-row { display: flex; gap: 8px; margin-bottom: 10px; flex-wrap: wrap; }
    #erp-bug-box .eb-pill {
        border: 1px solid #C9D9EA; background: #fff; color: #1F1B16; border-radius: 999px;
        padding: 6px 12px; font-weight: 600; font-size: 13px; cursor: pointer; font-family: inherit;
    }
    #erp-bug-box .eb-pill.on { background: #DCEBFA; border-color: #8DB6DE; }
    #erp-bug-box textarea {
        width: 100%; min-height: 110px; padding: 10px 12px; font-size: 15px; line-height: 1.45; font-family: inherit;
        border: 1px solid #DFD2B3; border-radius: 10px; resize: vertical; box-sizing: border-box;
    }
    #erp-bug-box .eb-label { font-size: 13px; font-weight: 700; margin: 12px 0 6px; }
    #erp-bug-box .eb-tag-search {
        width: 100%; padding: 7px 10px; font-size: 13px; font-family: inherit; border: 1px solid #DFD2B3;
        border-radius: 9px; box-sizing: border-box; margin-bottom: 6px;
    }
    #erp-bug-box .eb-people { display: flex; flex-wrap: wrap; gap: 6px; max-height: 96px; overflow-y: auto; }
    #erp-bug-box .eb-people .eb-pill { padding: 4px 10px; font-size: 12px; }
    #erp-bug-box .eb-pic { display: flex; align-items: center; gap: 10px; font-size: 13px; margin-top: 12px; }
    #erp-bug-box .eb-pic img { width: 72px; height: 46px; object-fit: cover; object-position: top; border: 1px solid #ECE3CF; border-radius: 6px; }
    #erp-bug-box .eb-link { background: none; border: none; padding: 0; color: #1F5FA8; font-weight: 600; cursor: pointer; font-family: inherit; font-size: 13px; }
    #erp-bug-box .eb-actions { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-top: 16px; }
    #erp-bug-box .eb-btn { padding: 10px 18px; border-radius: 9px; font-weight: 700; font-size: 14px; font-family: inherit; cursor: pointer; border: 1px solid #DFD2B3; background: #fff; color: #1F1B16; }
    #erp-bug-box .eb-btn.dark { background: #1F1B16; border-color: #1F1B16; color: #fff; }
    #erp-bug-box .eb-msg { font-size: 14px; margin-top: 10px; }
    #erp-bug-box .eb-small { font-size: 12px; color: #3D352C; }
    #erp-bug-box a { color: #1F5FA8; font-weight: 600; }
</style>

@if(!$__bq_pos)
<button type="button" id="erp-bug-launcher" title="Report a bug or a fix (Ctrl+Shift+B)">Report a bug or a fix</button>
@endif

<div id="erp-bug-overlay" role="dialog" aria-label="Report a bug or a fix">
  <div id="erp-bug-box">
    <form id="erp-bug-form">
      <h3>Report a bug or a fix</h3>
      <div class="eb-row">
        <button type="button" class="eb-pill eb-kind on" data-kind="bug">Something is broken</button>
        <button type="button" class="eb-pill eb-kind" data-kind="request">I want a fix or change</button>
      </div>
      <textarea id="erp-bug-desc" placeholder="What happened? A few words is fine." required></textarea>

      <div class="eb-label">Tag someone (optional)</div>
      @if(count($__bq_users) > 12)
        <input type="text" class="eb-tag-search" id="erp-bug-tag-search" placeholder="Search a name">
      @endif
      <div class="eb-people" id="erp-bug-people">
        @foreach($__bq_users as $u)
          <button type="button" class="eb-pill" data-uid="{{ $u[0] }}" data-name="{{ strtolower($u[1]) }}">{{ $u[1] }}</button>
        @endforeach
      </div>

      <div class="eb-pic" id="erp-bug-pic">
        <span id="erp-bug-pic-status">Taking a picture of this page...</span>
      </div>
      <div class="eb-small" style="margin-top:6px;">
        Or add your own: <input type="file" id="erp-bug-file" accept="image/*" style="display:inline-block;font-size:12px;"> or paste one into the box.
      </div>

      <div class="eb-msg" id="erp-bug-msg"></div>
      <div class="eb-actions">
        <button type="button" class="eb-btn" id="erp-bug-cancel">Cancel</button>
        <button type="submit" class="eb-btn dark" id="erp-bug-send">Send</button>
      </div>
      <div class="eb-small" style="margin-top:10px;">Follow it on <a href="/bug-fixes?tab=mine">Bug Fixes</a>. Ctrl+Enter sends.</div>
    </form>
  </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('erp-bug-overlay');
    if (!overlay) return;
    var launcher = document.getElementById('erp-bug-launcher');
    var form = document.getElementById('erp-bug-form');
    var desc = document.getElementById('erp-bug-desc');
    var fileInput = document.getElementById('erp-bug-file');
    var msg = document.getElementById('erp-bug-msg');
    var sendBtn = document.getElementById('erp-bug-send');
    var picBox = document.getElementById('erp-bug-pic');
    var people = document.getElementById('erp-bug-people');
    var search = document.getElementById('erp-bug-tag-search');
    var kind = 'bug';
    var ownShot = null;      // file the person pasted or picked
    var autoShot = null;     // automatic picture of the page
    var autoShotOn = true;

    // Recent JS errors on this page, sent along so the fixer can see them.
    var jsErrors = [];
    window.addEventListener('error', function (e) {
        if (jsErrors.length < 10) jsErrors.push(String(e.message || 'error') + (e.filename ? ' @ ' + e.filename.split('/').pop() + ':' + e.lineno : ''));
    });
    window.addEventListener('unhandledrejection', function (e) {
        if (jsErrors.length < 10) jsErrors.push('Promise: ' + String((e.reason && e.reason.message) || e.reason));
    });

    function loadH2C(cb) {
        if (window.html2canvas) return cb();
        var s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
        s.onload = function () { cb(); }; s.onerror = function () { cb(new Error('load')); };
        document.head.appendChild(s);
    }

    function renderPic() {
        if (ownShot) {
            picBox.innerHTML = '<span>Your picture is attached.</span> <button type="button" class="eb-link" id="erp-bug-pic-x">Remove</button>';
            document.getElementById('erp-bug-pic-x').onclick = function () { ownShot = null; fileInput.value = ''; renderPic(); };
        } else if (autoShot && autoShotOn) {
            var url = URL.createObjectURL(autoShot);
            picBox.innerHTML = '<img src="' + url + '" alt="Picture of this page"><span>Picture of this page attached.</span> <button type="button" class="eb-link" id="erp-bug-pic-x">Remove</button>';
            document.getElementById('erp-bug-pic-x').onclick = function () { autoShotOn = false; renderPic(); };
        } else if (autoShot && !autoShotOn) {
            picBox.innerHTML = '<span>No picture.</span> <button type="button" class="eb-link" id="erp-bug-pic-x">Add picture of this page</button>';
            document.getElementById('erp-bug-pic-x').onclick = function () { autoShotOn = true; renderPic(); };
        } else {
            picBox.innerHTML = '';
        }
    }

    function takeAutoShot() {
        autoShot = null; autoShotOn = true;
        picBox.innerHTML = '<span>Taking a picture of this page...</span>';
        loadH2C(function (err) {
            if (err || !window.html2canvas) { renderPic(); return; }
            try {
                window.html2canvas(document.body, {
                    logging: false, useCORS: true, scale: Math.min(window.devicePixelRatio || 1, 1.5),
                    x: window.scrollX, y: window.scrollY, width: window.innerWidth, height: window.innerHeight,
                    ignoreElements: function (el) { return el.id === 'erp-bug-overlay' || el.id === 'erp-bug-launcher' || el.id === 'erp-help-root'; }
                }).then(function (canvas) {
                    try { canvas.toBlob(function (b) { autoShot = b; renderPic(); }, 'image/jpeg', 0.75); } catch (e) { renderPic(); }
                }).catch(function () { renderPic(); });
            } catch (e) { renderPic(); }
        });
    }

    function open() {
        msg.textContent = '';
        overlay.classList.add('open');
        setTimeout(function () { desc.focus(); }, 30);
        // Picture the page as it looks behind the dialog.
        overlay.style.visibility = 'hidden';
        setTimeout(function () { overlay.style.visibility = ''; }, 0);
        takeAutoShot();
    }
    function close() { overlay.classList.remove('open'); }
    window.erpOpenBugReport = open;

    if (launcher) launcher.addEventListener('click', open);
    document.getElementById('erp-bug-cancel').addEventListener('click', close);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay.classList.contains('open')) close();
        if ((e.ctrlKey || e.metaKey) && e.shiftKey && (e.key === 'B' || e.key === 'b')) { e.preventDefault(); open(); }
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter' && overlay.classList.contains('open')) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : sendBtn.click(); }
    });

    Array.prototype.forEach.call(document.querySelectorAll('#erp-bug-box .eb-kind'), function (b) {
        b.addEventListener('click', function () {
            Array.prototype.forEach.call(document.querySelectorAll('#erp-bug-box .eb-kind'), function (x) { x.classList.remove('on'); });
            b.classList.add('on');
            kind = b.getAttribute('data-kind');
            desc.placeholder = kind === 'request' ? 'What should change? A few words is fine.' : 'What happened? A few words is fine.';
        });
    });

    people.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('.eb-pill') : null;
        if (b) b.classList.toggle('on');
    });
    if (search) search.addEventListener('input', function () {
        var q = search.value.trim().toLowerCase();
        Array.prototype.forEach.call(people.querySelectorAll('.eb-pill'), function (b) {
            b.style.display = (!q || b.getAttribute('data-name').indexOf(q) !== -1 || b.classList.contains('on')) ? '' : 'none';
        });
    });

    fileInput.addEventListener('change', function () { ownShot = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null; renderPic(); });
    desc.addEventListener('paste', function (e) {
        var items = (e.clipboardData || {}).items || [];
        for (var i = 0; i < items.length; i++) {
            if (items[i].type && items[i].type.indexOf('image') === 0) {
                ownShot = items[i].getAsFile(); renderPic(); e.preventDefault(); return;
            }
        }
    });

    // Jon 11/10/26: big phone photos / Retina screenshots (and long-page auto
    // pictures) went over the server upload limit, so the whole report failed.
    // Shrink every picture to a small JPEG first; if it still will not send,
    // send the report without the picture so the ticket always goes in.
    function shrinkPic(blob, cb) {
        if (!blob) return cb(null);
        var url;
        try { url = URL.createObjectURL(blob); } catch (e) { return cb(null); }
        var img = new Image();
        img.onload = function () {
            try {
                var max = 1600, w = img.naturalWidth, h = img.naturalHeight;
                var k = Math.min(1, max / Math.max(w, h));
                var c = document.createElement('canvas');
                c.width = Math.max(1, Math.round(w * k)); c.height = Math.max(1, Math.round(h * k));
                var g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height);
                g.drawImage(img, 0, 0, c.width, c.height);
                URL.revokeObjectURL(url);
                c.toBlob(function (b) {
                    if (b && b.size > 900000) {
                        c.toBlob(function (b2) { cb(b2 || b); }, 'image/jpeg', 0.55);
                    } else { cb(b); }
                }, 'image/jpeg', 0.8);
            } catch (e) { cb(null); }
        };
        img.onerror = function () { try { URL.revokeObjectURL(url); } catch (e) {} cb(null); };
        img.src = url;
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!desc.value.trim()) { desc.focus(); return; }
        var rawPic = ownShot ? ownShot : ((autoShot && autoShotOn) ? autoShot : null);
        sendBtn.disabled = true; sendBtn.textContent = 'Sending...';
        shrinkPic(rawPic, function (pic) {
            sendReport(pic, !!rawPic && !pic);
        });
    });

    function sendReport(pic, picDropped) {
        var fd = new FormData();
        fd.append('description', desc.value.trim());
        fd.append('kind', kind);
        fd.append('page_url', window.location.href);
        Array.prototype.forEach.call(people.querySelectorAll('.eb-pill.on'), function (b) { fd.append('tagged[]', b.getAttribute('data-uid')); });
        fd.append('context', JSON.stringify({
            user_agent: navigator.userAgent, screen: window.innerWidth + 'x' + window.innerHeight,
            page_title: document.title, js_errors: jsErrors, reported_at: new Date().toString()
        }));
        if (pic) fd.append('screenshot', pic, 'picture.jpg');
        var tokenMeta = document.querySelector('meta[name="csrf-token"]');
        sendBtn.disabled = true; sendBtn.textContent = 'Sending...';
        fetch('/bug-fixes/report', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': tokenMeta ? tokenMeta.getAttribute('content') : '', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: fd, credentials: 'same-origin'
        }).then(function (r) {
            return r.json().then(function (j) { return { ok: r.ok, j: j, status: r.status }; },
                function () { return { ok: false, j: null, status: r.status }; });
        })
          .then(function (res) {
            // Picture rejected or too big: send the report again without it.
            if ((!res.ok || !res.j || !res.j.success) && pic && (res.status === 413 || res.status === 422 || !res.j)) {
                sendReport(null, true);
                return 'retry';
            }
            if (!res.ok || !res.j || !res.j.success) {
                var err = (res.j && res.j.errors) ? Object.values(res.j.errors)[0][0] : ((res.j && res.j.message) || 'Could not send. Try again.');
                msg.style.color = '#8A3A2E'; msg.textContent = err; return;
            }
            msg.style.color = '#2F6B3E';
            msg.innerHTML = 'Thanks! #' + res.j.id + ' is in the queue. <a href="' + res.j.url + '">Track it here</a>.'
                + (picDropped ? ' (The picture could not be attached, so it went in without it.)' : '');
            desc.value = ''; fileInput.value = ''; ownShot = null; autoShot = null;
            Array.prototype.forEach.call(people.querySelectorAll('.eb-pill.on'), function (b) { b.classList.remove('on'); });
            renderPic();
            setTimeout(function () { if (msg.textContent.indexOf('Thanks') === 0) close(); }, 3500);
        }).catch(function () {
            msg.style.color = '#8A3A2E'; msg.textContent = 'Could not send. Check your connection and try again.';
        }).then(function (r) { if (r !== 'retry') { sendBtn.disabled = false; sendBtn.textContent = 'Send'; } });
    }
})();
</script>
@endauth
