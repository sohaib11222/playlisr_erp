@extends('layouts.app')
@section('title', 'Discogs Street Dates')

@section('content')
<section class="content-header">
    <h1>Discogs Street Dates</h1>
    <p class="text-muted">
        Fills the street/release date (used by the storefront's New Releases row and the ERP's
        New Releases ABC scope) from the Discogs release each product is already linked to.
        Only fills products with no street date set — never overwrites a date typed in by hand.
    </p>
</section>

<section class="content">

<div class="row">
    <div class="col-md-6">
        <div class="box box-solid">
            <div class="box-header"><h3 class="box-title">Run</h3></div>
            <div class="box-body">
                <p><strong id="ds-remaining">{{ number_format($remaining) }}</strong> product(s) have a Discogs link but no street date yet.</p>
                <div class="form-group">
                    <label>Batch size (per click)</label>
                    <input type="number" id="ds-limit" class="form-control" value="150" min="1" max="300" style="max-width:160px;">
                    <p class="text-muted" style="margin-top:4px;">Discogs rate-limits to ~60 requests/min, so this paces itself — a batch of 150 takes about 3 minutes.</p>
                </div>
                <button id="ds-preview" class="btn btn-default btn-lg">Preview batch</button>
                <button id="ds-commit" class="btn btn-primary btn-lg" disabled>Write dates</button>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="box box-solid">
            <div class="box-header"><h3 class="box-title">Result</h3></div>
            <div class="box-body" id="ds-out">
                <p class="text-muted">Run a preview to see what would be filled in.</p>
            </div>
        </div>
    </div>
</div>

</section>

<script>
(function () {
    const CSRF = "{{ csrf_token() }}";
    const previewBtn = document.getElementById('ds-preview');
    const commitBtn = document.getElementById('ds-commit');
    const out = document.getElementById('ds-out');
    const remainingEl = document.getElementById('ds-remaining');
    const limitEl = document.getElementById('ds-limit');

    previewBtn.addEventListener('click', () => run(false));
    commitBtn.addEventListener('click', () => run(true));

    async function run(commit) {
        previewBtn.disabled = true;
        commitBtn.disabled = true;
        out.innerHTML = '<p>' + (commit ? 'Writing dates…' : 'Checking Discogs…') + ' this can take a few minutes for a full batch.</p>';

        const fd = new FormData();
        fd.append('limit', limitEl.value || '150');
        fd.append('commit', commit ? '1' : '0');
        fd.append('_token', CSRF);

        try {
            const res = await fetch("{{ url('/admin/discogs-street-dates/run') }}", {
                method: 'POST', body: fd,
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            const text = await res.text();
            let data;
            try { data = JSON.parse(text); } catch (e) {
                throw new Error('Server returned non-JSON (HTTP ' + res.status + '). First 200 chars: ' + text.slice(0, 200));
            }
            if (!data.success) {
                out.innerHTML = '<div class="alert alert-danger">' + escapeHtml(data.msg || data.error || 'Failed.') + '</div>';
                previewBtn.disabled = false;
                return;
            }
            remainingEl.textContent = data.remaining.toLocaleString();
            render(data);
            previewBtn.disabled = false;
            commitBtn.disabled = commit || data.updated === 0;
        } catch (e) {
            out.innerHTML = '<div class="alert alert-danger">' + escapeHtml(e.message) + '</div>';
            previewBtn.disabled = false;
        }
    }

    function render(d) {
        let html = '<p>' + (d.commit ? '<strong>Written.</strong>' : '<strong>Preview only — nothing written yet.</strong>') + '</p>';
        html += '<p>Checked: ' + d.checked + ' · ' + (d.commit ? 'Updated' : 'Would update') + ': ' + d.updated
              + ' · No date on Discogs: ' + d.no_date + ' · Failed: ' + d.failed + ' · Remaining after this: ' + d.remaining + '</p>';
        if (!d.commit && d.updated > 0) {
            html += '<p>Looks good? Click <strong>Write dates</strong> to save this batch.</p>';
        }
        if (d.results && d.results.length) {
            html += '<table class="table table-condensed table-bordered" style="font-size:12px;"><thead><tr>'
                  + '<th>Product</th><th>Discogs id</th><th>Status</th><th>Detail</th></tr></thead><tbody>';
            d.results.forEach(r => {
                const color = r.status === 'found' ? '' : (r.status === 'error' ? ' style="background:#f6e3df"' : ' style="background:#fff3cd"');
                html += '<tr' + color + '>'
                      + '<td>' + escapeHtml(r.name) + ' <span style="color:#888">(#' + r.id + ')</span></td>'
                      + '<td>' + escapeHtml(String(r.discogs_release_id)) + '</td>'
                      + '<td>' + escapeHtml(r.status) + '</td>'
                      + '<td>' + escapeHtml(r.detail) + '</td>'
                      + '</tr>';
            });
            html += '</tbody></table>';
        }
        out.innerHTML = html;
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
})();
</script>
@endsection
