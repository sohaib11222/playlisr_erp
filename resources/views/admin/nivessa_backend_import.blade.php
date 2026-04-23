@extends('layouts.app')
@section('title', 'Nivessa Backend Import')

@section('content')
<section class="content-header">
    <h1>Nivessa Backend Import <small class="text-muted">— upload the backend xlsx and run</small></h1>
    <p class="text-muted">Dry-run first. Then tick the "commit" box to actually write. Idempotent: safe to re-run.</p>
</section>

<section class="content">

<div class="row">
    <div class="col-md-6">
        <div class="box box-solid">
            <div class="box-header"><h3 class="box-title">1. Pick the xlsx</h3></div>
            <div class="box-body">
                <input type="file" id="nbi-file" accept=".xlsx,.xls" class="form-control" />
                <p class="help-block">Uploaded in 512&nbsp;KB chunks to slip past the nginx <code>client_max_body_size</code> cap. Progress shows on the right.</p>
            </div>
        </div>

        <div class="box box-solid">
            <div class="box-header"><h3 class="box-title">2. Choose the import</h3></div>
            <div class="box-body">
                <div class="radio">
                    <label><input type="radio" name="nbi-type" value="sales" checked> <strong>Historical sales</strong> — per-row transactions + sell_lines across all monthly sheets</label>
                </div>
                <div class="radio">
                    <label><input type="radio" name="nbi-type" value="store_credit"> <strong>Store Credit</strong> — tag contacts with legacy credit amounts (manual apply)</label>
                </div>
                <div class="radio">
                    <label><input type="radio" name="nbi-type" value="customer_asks"> <strong>Customer Asks</strong> — create customer_wants rows</label>
                </div>

                <div id="nbi-sales-opts" style="margin-top:10px;">
                    <div class="form-group">
                        <label>Only this sheet (optional, e.g. <code>HW SEP 25</code>)</label>
                        <input type="text" id="nbi-only-sheet" class="form-control" placeholder="leave blank for all sheets" />
                    </div>
                    <div class="form-group">
                        <label>Tax rate to back out (default 0.0975)</label>
                        <input type="text" id="nbi-tax-rate" class="form-control" placeholder="0.0975" />
                    </div>
                </div>
            </div>
        </div>

        <div class="box box-solid">
            <div class="box-header"><h3 class="box-title">3. Run</h3></div>
            <div class="box-body">
                <div class="checkbox">
                    <label><input type="checkbox" id="nbi-commit"> <strong>Commit</strong> (actually write to the DB). Leave unchecked for a dry run.</label>
                </div>
                <button id="nbi-run" class="btn btn-primary btn-lg">Run import</button>
                <button id="nbi-clear" class="btn btn-default">Clear output</button>
            </div>
        </div>
    </div>

    <div class="col-md-6">
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
    const out = document.getElementById('nbi-output');
    const fileEl = document.getElementById('nbi-file');
    const salesOpts = document.getElementById('nbi-sales-opts');
    const typeInputs = document.querySelectorAll('input[name="nbi-type"]');

    function currentType() {
        for (const el of typeInputs) if (el.checked) return el.value;
        return 'sales';
    }
    function toggleSalesOpts() {
        salesOpts.style.display = currentType() === 'sales' ? 'block' : 'none';
    }
    typeInputs.forEach(el => el.addEventListener('change', toggleSalesOpts));
    toggleSalesOpts();

    document.getElementById('nbi-clear').addEventListener('click', () => { out.textContent = 'Ready.'; });

    const CHUNK_SIZE = 512 * 1024; // 512 KB — safely under default nginx 1 MB cap

    function makeSessionId() {
        return 'nbi_' + Math.random().toString(36).slice(2, 10) + '_' + Date.now().toString(36);
    }

    function appendOutput(text) {
        out.textContent += text;
        out.scrollTop = out.scrollHeight;
    }

    async function uploadFileChunked(file, sessionId) {
        const total = Math.ceil(file.size / CHUNK_SIZE);
        appendOutput('Uploading ' + file.name + ' (' + file.size.toLocaleString() + ' bytes) in ' + total + ' chunks of ' + CHUNK_SIZE + '…\n');
        for (let i = 0; i < total; i++) {
            const start = i * CHUNK_SIZE;
            const end = Math.min(start + CHUNK_SIZE, file.size);
            const slice = file.slice(start, end);
            const url = '/admin/nivessa-backend-import/chunk?session_id=' + encodeURIComponent(sessionId)
                + '&index=' + i
                + (i === total - 1 ? '&final=1' : '');
            const resp = await fetch(url, {
                method: 'POST',
                body: slice,
                headers: {
                    'X-CSRF-TOKEN': CSRF,
                    'Content-Type': 'application/octet-stream',
                },
            });
            if (!resp.ok) {
                throw new Error('chunk ' + i + ' → HTTP ' + resp.status + ': ' + (await resp.text()).slice(0, 200));
            }
            const pct = Math.round(((i + 1) / total) * 100);
            appendOutput('  chunk ' + (i + 1) + '/' + total + ' → ' + pct + "%\n");
        }
        appendOutput('Upload complete. Running import…\n\n');
    }

    document.getElementById('nbi-run').addEventListener('click', async () => {
        const f = fileEl.files && fileEl.files[0];
        if (!f) { alert('Pick an xlsx first.'); return; }

        const sessionId = makeSessionId();
        out.textContent = '';
        const btn = document.getElementById('nbi-run');
        btn.disabled = true;
        try {
            await uploadFileChunked(f, sessionId);

            const fd = new FormData();
            fd.append('session_id', sessionId);
            fd.append('import_type', currentType());
            fd.append('commit', document.getElementById('nbi-commit').checked ? '1' : '0');
            if (currentType() === 'sales') {
                fd.append('only_sheet', document.getElementById('nbi-only-sheet').value);
                fd.append('tax_rate', document.getElementById('nbi-tax-rate').value);
            }
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
            appendOutput('\n— done —\n');
        } catch (e) {
            appendOutput('\nError: ' + e.message);
        } finally {
            btn.disabled = false;
        }
    });
})();
</script>
@endsection
