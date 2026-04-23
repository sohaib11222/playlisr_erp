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
                <p class="help-block">Max 100 MB. PHP's <code>upload_max_filesize</code> still applies server-side.</p>
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

    document.getElementById('nbi-run').addEventListener('click', async () => {
        const f = fileEl.files && fileEl.files[0];
        if (!f) { alert('Pick an xlsx first.'); return; }

        const fd = new FormData();
        fd.append('xlsx', f);
        fd.append('import_type', currentType());
        fd.append('commit', document.getElementById('nbi-commit').checked ? '1' : '0');
        if (currentType() === 'sales') {
            fd.append('only_sheet', document.getElementById('nbi-only-sheet').value);
            fd.append('tax_rate', document.getElementById('nbi-tax-rate').value);
        }
        fd.append('_token', CSRF);

        out.textContent = 'Uploading + running…\n';
        const btn = document.getElementById('nbi-run');
        btn.disabled = true;
        try {
            const resp = await fetch('/admin/nivessa-backend-import/run', {
                method: 'POST',
                body: fd,
                headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'text/plain' },
            });
            if (!resp.ok) {
                out.textContent += '\nHTTP ' + resp.status + '\n' + (await resp.text());
                return;
            }
            const reader = resp.body.getReader();
            const decoder = new TextDecoder();
            while (true) {
                const { value, done } = await reader.read();
                if (done) break;
                out.textContent += decoder.decode(value, { stream: true });
                out.scrollTop = out.scrollHeight;
            }
            out.textContent += '\n— done —\n';
        } catch (e) {
            out.textContent += '\nError: ' + e.message;
        } finally {
            btn.disabled = false;
        }
    });
})();
</script>
@endsection
