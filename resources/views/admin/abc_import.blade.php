@extends('layouts.app')
@section('title', 'ABC Import')

@section('content')
<section class="content-header">
    <h1>ABC Import</h1>
    <p class="text-muted">
        Replaces the live inventory-value ABC with externally-computed (sales-based) classification.
        Upload the analyzer CSV — columns: Product, Format, Location, Sales, Q-ty, ABC.
    </p>
</section>

<section class="content">

@if(session('status'))
<div class="alert alert-info">{{ session('status') }}</div>
@endif

<div class="row">
    <div class="col-md-5">
        <div class="box box-solid">
            <div class="box-header"><h3 class="box-title">Upload</h3></div>
            <div class="box-body">
                <div class="form-group">
                    <label>CSV file</label>
                    <input type="file" id="abc-file" accept=".csv,text/csv" class="form-control" />
                </div>
                <div class="form-group">
                    <label>Period label (optional)</label>
                    <input type="text" id="abc-period" class="form-control" placeholder="e.g. April 2026" />
                </div>
                <button id="abc-preview" class="btn btn-default btn-lg">Preview match</button>
                <button id="abc-save" class="btn btn-primary btn-lg" disabled>Save &amp; activate</button>
                <form action="{{ url('/admin/abc-import/clear') }}" method="POST" style="display:inline;">
                    @csrf
                    <button type="submit" class="btn btn-link text-danger" onclick="return confirm('Clear imported ABC and fall back to the live inventory-value computation?')">Clear imported</button>
                </form>
            </div>
        </div>

        @if($current)
        <div class="box box-solid">
            <div class="box-header"><h3 class="box-title">Currently active</h3></div>
            <div class="box-body">
                <p><strong>Source:</strong> {{ $current['source_file'] ?? '—' }}</p>
                <p><strong>Period:</strong> {{ $current['period_label'] ?: '—' }}</p>
                <p><strong>Uploaded:</strong> {{ $current['uploaded_at'] ?? '—' }}</p>
                <p>
                    <strong>Rows:</strong> {{ number_format($current['stats']['rows'] ?? 0) }} ·
                    <strong>Matched:</strong> {{ number_format($current['stats']['matched'] ?? 0) }} ·
                    <strong>Unmatched:</strong> {{ number_format($current['stats']['unmatched'] ?? 0) }} ·
                    <strong>Distinct products:</strong> {{ number_format($current['stats']['distinct_products'] ?? 0) }}
                </p>
            </div>
        </div>
        @else
        <div class="box box-solid">
            <div class="box-body">
                <p class="text-muted">No imported ABC active — falling back to live inventory-value computation.</p>
            </div>
        </div>
        @endif
    </div>

    <div class="col-md-7">
        <div class="box box-solid">
            <div class="box-header"><h3 class="box-title">Preview</h3></div>
            <div class="box-body" id="abc-preview-out">
                <p class="text-muted">Run a preview to see match rate and unmatched names.</p>
            </div>
        </div>
    </div>
</div>

</section>

<script>
(function () {
    const CSRF = "{{ csrf_token() }}";
    const fileEl = document.getElementById('abc-file');
    const periodEl = document.getElementById('abc-period');
    const previewBtn = document.getElementById('abc-preview');
    const saveBtn = document.getElementById('abc-save');
    const out = document.getElementById('abc-preview-out');
    let pendingToken = null;

    previewBtn.addEventListener('click', async () => {
        if (!fileEl.files.length) { alert('Pick a CSV first.'); return; }
        out.innerHTML = '<p>Parsing…</p>';
        saveBtn.disabled = true;
        pendingToken = null;

        const fd = new FormData();
        fd.append('csv', fileEl.files[0]);
        fd.append('period_label', periodEl.value || '');
        fd.append('_token', CSRF);

        try {
            const data = await postJson("{{ url('/admin/abc-import/preview') }}", fd);
            if (!data.ok) {
                out.innerHTML = '<div class="alert alert-danger">' + escapeHtml(data.error || data.message || 'Preview failed (empty response).') + '</div>';
                return;
            }
            pendingToken = data.token;
            saveBtn.disabled = false;
            renderPreview(data);
        } catch (e) {
            out.innerHTML = '<div class="alert alert-danger">' + escapeHtml(e.message) + '</div>';
        }
    });

    saveBtn.addEventListener('click', async () => {
        if (!pendingToken) return;
        saveBtn.disabled = true;
        const fd = new FormData();
        fd.append('token', pendingToken);
        fd.append('_token', CSRF);
        try {
            const data = await postJson("{{ url('/admin/abc-import/save') }}", fd);
            if (!data.ok) {
                alert(data.error || data.message || 'Save failed.');
                saveBtn.disabled = false;
                return;
            }
            alert('Saved. ' + data.stats.distinct_products + ' products active.');
            location.reload();
        } catch (e) {
            alert(e.message);
            saveBtn.disabled = false;
        }
    });

    // Request JSON explicitly so Laravel returns JSON for validation errors.
    // If the server still returns HTML (auth redirect, 500 page), surface a
    // useful error instead of a cryptic "Unexpected token '<'".
    async function postJson(url, fd) {
        const res = await fetch(url, {
            method: 'POST',
            body: fd,
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error('Server returned non-JSON (HTTP ' + res.status + '). First 200 chars: ' + text.slice(0, 200));
        }
    }

    function renderPreview(d) {
        const pct = d.stats.rows ? Math.round((d.stats.matched / d.stats.rows) * 100) : 0;
        let html = '';
        html += '<p><strong>' + d.source_file + '</strong>' + (d.period_label ? ' · ' + escapeHtml(d.period_label) : '') + '</p>';
        html += '<p>Rows: ' + d.stats.rows + ' · Matched: ' + d.stats.matched + ' (' + pct + '%) · Unmatched: ' + d.stats.unmatched + ' · Distinct products: ' + d.stats.distinct_products + '</p>';

        if (d.sample_matched && d.sample_matched.length) {
            html += '<h4>Matched (sample)</h4><ul>';
            d.sample_matched.forEach(r => {
                html += '<li>[' + r.class + '] ' + escapeHtml(r.name) + '</li>';
            });
            html += '</ul>';
        }
        if (d.sample_unmatched && d.sample_unmatched.length) {
            html += '<h4>Unmatched (first 25)</h4><ul>';
            d.sample_unmatched.forEach(r => {
                html += '<li>[' + r.class + '] ' + escapeHtml(r.product) + ' — ' + escapeHtml(r.format) + ' / ' + escapeHtml(r.location) + '</li>';
            });
            html += '</ul>';
        }
        out.innerHTML = html;
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
})();
</script>
@endsection
