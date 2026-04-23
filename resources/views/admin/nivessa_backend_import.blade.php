@extends('layouts.app')
@section('title', 'Nivessa Backend Import')

@section('content')
<section class="content-header">
    <h1>Nivessa Backend Import</h1>
    <p class="text-muted">One upload. Dry-run → Commit. Runs all three imports (Store Credit, Customer Asks, Historical Sales) against the same xlsx.</p>
</section>

<section class="content">

<div class="row">
    <div class="col-md-5">
        <div class="box box-solid">
            <div class="box-body">
                <div class="form-group">
                    <label>Nivessa Backend xlsx</label>
                    <input type="file" id="nbi-file" accept=".xlsx,.xls" class="form-control" />
                </div>
                <button id="nbi-dry" class="btn btn-default btn-lg">Dry-run all 3</button>
                <button id="nbi-commit" class="btn btn-primary btn-lg">Commit all 3</button>
                <button id="nbi-clear" class="btn btn-link">Clear output</button>
                <p class="help-block" style="margin-top:10px;">
                    Dry-run shows what would change without writing. Commit actually writes rows — idempotent on re-run.
                    Upload happens in 512&nbsp;KB chunks; a ~25&nbsp;MB file takes ~45&nbsp;s to transfer.
                </p>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="box box-solid">
            <div class="box-header"><h3 class="box-title">Output</h3></div>
            <div class="box-body" style="padding:0;">
                <pre id="nbi-output" style="margin:0; max-height:700px; overflow:auto; padding:12px; background:#1e1e1e; color:#d4d4d4; font-size:12px; line-height:1.45; white-space:pre-wrap;">Ready.</pre>
            </div>
        </div>
    </div>
</div>

</section>

<script>
(function () {
    const CSRF = "{{ csrf_token() }}";
    const CHUNK_SIZE = 512 * 1024;
    const out = document.getElementById('nbi-output');
    const fileEl = document.getElementById('nbi-file');

    function appendOutput(text) {
        out.textContent += text;
        out.scrollTop = out.scrollHeight;
    }
    function makeSessionId() {
        return 'nbi_' + Math.random().toString(36).slice(2, 10) + '_' + Date.now().toString(36);
    }

    document.getElementById('nbi-clear').addEventListener('click', () => { out.textContent = 'Ready.'; });

    async function uploadFileChunked(file, sessionId) {
        const total = Math.ceil(file.size / CHUNK_SIZE);
        appendOutput('Uploading ' + file.name + ' (' + file.size.toLocaleString() + ' bytes) in ' + total + ' chunks…\n');
        for (let i = 0; i < total; i++) {
            const slice = file.slice(i * CHUNK_SIZE, Math.min((i + 1) * CHUNK_SIZE, file.size));
            const url = '/admin/nivessa-backend-import/chunk?session_id=' + encodeURIComponent(sessionId) + '&index=' + i;
            const resp = await fetch(url, {
                method: 'POST',
                body: slice,
                headers: { 'X-CSRF-TOKEN': CSRF, 'Content-Type': 'application/octet-stream' },
            });
            if (!resp.ok) {
                throw new Error('chunk ' + i + ' → HTTP ' + resp.status + ': ' + (await resp.text()).slice(0, 200));
            }
            const pct = Math.round(((i + 1) / total) * 100);
            if (i % 4 === 0 || i === total - 1) {
                appendOutput('  ' + pct + '% (' + (i + 1) + '/' + total + ")\n");
            }
        }
        appendOutput('Upload complete.\n\n');
    }

    async function go(commit) {
        const f = fileEl.files && fileEl.files[0];
        if (!f) { alert('Pick an xlsx first.'); return; }

        const sessionId = makeSessionId();
        out.textContent = '';
        const dryBtn = document.getElementById('nbi-dry');
        const commitBtn = document.getElementById('nbi-commit');
        dryBtn.disabled = commitBtn.disabled = true;
        try {
            await uploadFileChunked(f, sessionId);

            const fd = new FormData();
            fd.append('session_id', sessionId);
            fd.append('commit', commit ? '1' : '0');
            fd.append('_token', CSRF);

            const resp = await fetch('/admin/nivessa-backend-import/run', {
                method: 'POST',
                body: fd,
                headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'text/plain' },
            });
            if (!resp.ok) {
                appendOutput('\nHTTP ' + resp.status + '\n' + (await resp.text()));
                return;
            }
            const reader = resp.body.getReader();
            const decoder = new TextDecoder();
            while (true) {
                const { value, done } = await reader.read();
                if (done) break;
                appendOutput(decoder.decode(value, { stream: true }));
            }
        } catch (e) {
            appendOutput('\nError: ' + e.message);
        } finally {
            dryBtn.disabled = commitBtn.disabled = false;
        }
    }

    document.getElementById('nbi-dry').addEventListener('click', () => go(false));
    document.getElementById('nbi-commit').addEventListener('click', () => {
        if (!confirm('Commit all 3 imports to the DB? (Safe to re-run — idempotent.)')) return;
        go(true);
    });
})();
</script>
@endsection
