@extends('layouts.app')
@section('title', 'Channel Sales Sync')

@section('content')
<section class="content-header">
    <h1>Channel Sales Sync</h1>
    <p class="text-muted">
        Pull online sales into the ERP as real transactions, so the ERP is the source of truth.
        Always <strong>Dry-run</strong> first to see the counts, then <strong>Commit</strong>.
        Re-running never creates duplicates (each order is matched by its source ID).
    </p>
</section>

<section class="content">
<div class="row">
    <div class="col-md-5">
        <div class="box box-solid">
            <div class="box-header"><h3 class="box-title">Web orders + Space rentals</h3></div>
            <div class="box-body">
                <p class="text-muted" style="margin-top:0;">From nivessa.com — web store orders and venue (space rental) bookings.</p>
                <div class="form-group">
                    <label>Look back (days)</label>
                    <input type="number" id="web-days" value="120" min="1" max="3650" class="form-control" style="width:120px;">
                </div>
                <button id="web-dry" class="btn btn-default btn-lg" data-source="web">Dry-run</button>
                <button id="web-commit" class="btn btn-primary btn-lg" data-source="web">Commit</button>
            </div>
        </div>

        <div class="box box-solid">
            <div class="box-header"><h3 class="box-title">Discogs orders</h3></div>
            <div class="box-body">
                <p class="text-muted" style="margin-top:0;">Marketplace orders via your Discogs seller token (Business Settings &gt; Integrations).</p>
                <div class="form-group">
                    <label>Look back (days)</label>
                    <input type="number" id="dg-days" value="120" min="1" max="3650" class="form-control" style="width:120px;">
                </div>
                <button id="dg-dry" class="btn btn-default btn-lg" data-source="discogs">Dry-run</button>
                <button id="dg-commit" class="btn btn-primary btn-lg" data-source="discogs">Commit</button>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="box box-solid">
            <div class="box-header">
                <h3 class="box-title">Output</h3>
                <button id="clear-out" class="btn btn-link pull-right" style="padding:0;">Clear</button>
            </div>
            <div class="box-body" style="padding:0;">
                <pre id="out" style="margin:0; max-height:720px; overflow:auto; padding:12px; background:#1e1e1e; color:#d4d4d4; font-size:12px; line-height:1.45; white-space:pre-wrap;">Ready.</pre>
            </div>
        </div>
    </div>
</div>
</section>

<script>
(function () {
    const CSRF = "{{ csrf_token() }}";
    const out = document.getElementById('out');
    const buttons = Array.prototype.slice.call(document.querySelectorAll('button[data-source]'));

    function append(t) { out.textContent += t; out.scrollTop = out.scrollHeight; }
    document.getElementById('clear-out').addEventListener('click', () => { out.textContent = 'Ready.'; });

    async function run(source, commit) {
        const days = document.getElementById(source === 'web' ? 'web-days' : 'dg-days').value || 120;
        const url = source === 'web' ? '/admin/channel-sales-sync/web' : '/admin/channel-sales-sync/discogs';
        out.textContent = '';
        buttons.forEach(b => b.disabled = true);
        try {
            const fd = new FormData();
            fd.append('commit', commit ? '1' : '0');
            fd.append('days', days);
            fd.append('_token', CSRF);
            const resp = await fetch(url, { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'text/plain' } });
            if (!resp.ok) { append('\nHTTP ' + resp.status + '\n' + (await resp.text())); return; }
            const reader = resp.body.getReader();
            const decoder = new TextDecoder();
            while (true) {
                const { value, done } = await reader.read();
                if (done) break;
                append(decoder.decode(value, { stream: true }));
            }
        } catch (e) {
            append('\nError: ' + e.message);
        } finally {
            buttons.forEach(b => b.disabled = false);
        }
    }

    document.getElementById('web-dry').addEventListener('click', () => run('web', false));
    document.getElementById('dg-dry').addEventListener('click', () => run('discogs', false));
    document.getElementById('web-commit').addEventListener('click', () => { if (confirm('Commit web orders + space rentals to the ERP? (Safe to re-run — no duplicates.)')) run('web', true); });
    document.getElementById('dg-commit').addEventListener('click', () => { if (confirm('Commit Discogs orders to the ERP? (Safe to re-run — no duplicates.)')) run('discogs', true); });
})();
</script>
@endsection
