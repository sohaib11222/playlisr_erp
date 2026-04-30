@extends('layouts.app')
@section('title', 'Fix In Store Sold Dates')

@section('content')
<section class="content-header">
    <h1>Fix In Store Sold Dates</h1>
    <p class="text-muted">
        The original importer didn't read the per-row Sold Date (col P) /
        Bought Date (col F) on the <code>In Store New & Used Sales</code> sheet,
        so its 728 transactions ended up with a placeholder date (04/26/26).
        Upload the same Nivessa Backend xlsx and this rewrites each transaction's
        date to its actual Sold Date from the sheet (or Bought Date if Sold is blank).
    </p>
</section>

<section class="content">

<div class="row">
    <div class="col-md-5">
        <div class="box box-solid">
            <div class="box-body">
                <div class="form-group">
                    <label>Nivessa Backend xlsx</label>
                    <input type="file" id="fis-file" accept=".xlsx,.xls" class="form-control" />
                </div>
                <button id="fis-preview" class="btn btn-default btn-lg">Preview</button>
                <button id="fis-apply" class="btn btn-primary btn-lg">Apply</button>
                <button id="fis-clear" class="btn btn-link">Clear output</button>
                <p class="help-block" style="margin-top:10px;">
                    Preview shows what would change; Apply writes and saves a BEFORE
                    snapshot so you can undo from
                    <a href="{{ url('/admin/admin-action-history') }}">admin-action-history</a>.
                    The xlsx (~25&nbsp;MB) uploads in 512&nbsp;KB chunks to the same
                    endpoint as the main import page (~45&nbsp;s).
                </p>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="box box-solid">
            <div class="box-header"><h3 class="box-title">Output</h3></div>
            <div class="box-body" style="padding:0;">
                <pre id="fis-output" style="margin:0; max-height:700px; overflow:auto; padding:12px; background:#1e1e1e; color:#d4d4d4; font-size:12px; line-height:1.45; white-space:pre-wrap;">Ready.</pre>
            </div>
        </div>
    </div>
</div>

@if (!empty($mode))
<div class="row">
    <div class="col-md-12">
        <div class="box box-solid" style="border: 3px solid {{ $mode === 'commit' ? '#00a65a' : '#3c8dbc' }};">
            <div class="box-header" style="background: {{ $mode === 'commit' ? '#dff0d8' : '#d9edf7' }};">
                <h3 class="box-title" style="font-size:20px;">
                    @if ($mode === 'commit')
                        Applied — {{ number_format($updated) }} of {{ number_format($tx_total) }} transactions rewritten
                    @else
                        Preview — {{ number_format($matched_count) }} of {{ number_format($tx_total) }} transactions would be rewritten
                    @endif
                </h3>
            </div>
            <div class="box-body" style="background: {{ $mode === 'commit' ? '#dff0d8' : '#d9edf7' }};">
                <ul style="margin:0; padding-left:20px;">
                    <li>Sheet rows with a usable date (col A running date or col P fallback): <strong>{{ number_format($sheet_row_count) }}</strong></li>
                    <li>Transactions tagged <code>{{ \App\Http\Controllers\FixInStoreSoldDatesController::IMPORT_SOURCE }}</code>: <strong>{{ number_format($tx_total) }}</strong></li>
                    <li>Will rewrite (current date wrong): <strong>{{ number_format($matched_count) }}</strong></li>
                    <li>Already correct (skip): <strong>{{ number_format($already_ok) }}</strong></li>
                    <li>Unmatched (xlsx row had no date or external_id didn't parse): <strong>{{ number_format($unmatched_count) }}</strong> — left untouched</li>
                </ul>
                @if ($mode === 'commit' && !empty($snapshot_key))
                    <p style="margin-top:8px;">
                        Snapshot saved as <code>{{ $snapshot_key }}</code>. Undo from
                        <a href="{{ url('/admin/admin-action-history') }}">/admin/admin-action-history</a>.
                    </p>
                @endif
            </div>
        </div>
    </div>
</div>

@if (!empty($already_ok_samples))
<div class="row">
    <div class="col-md-12">
        <div class="box box-success">
            <div class="box-header with-border">
                <h3 class="box-title">Debug — sample "already correct" transactions ({{ count($already_ok_samples) }} of {{ number_format($already_ok) }})</h3>
            </div>
            <div class="box-body" style="padding:0;">
                <table class="table table-condensed table-striped" style="margin:0;">
                    <thead>
                        <tr>
                            <th style="width:80px;">Tx ID</th>
                            <th>import_external_id</th>
                            <th>Current date</th>
                            <th>Computed target</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($already_ok_samples as $a)
                            <tr>
                                <td>{{ $a['id'] }}</td>
                                <td><code>{{ $a['external_id'] }}</code></td>
                                <td>{{ \Carbon\Carbon::parse($a['current_date'])->format('m/d/y g:i A') }}</td>
                                <td><strong>{{ $a['target'] }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif

@if (!empty($unmatched_samples))
<div class="row">
    <div class="col-md-12">
        <div class="box box-warning">
            <div class="box-header with-border">
                <h3 class="box-title">Debug — sample unmatched transactions ({{ count($unmatched_samples) }} of {{ number_format($unmatched_count) }})</h3>
            </div>
            <div class="box-body" style="padding:0;">
                <table class="table table-condensed table-striped" style="margin:0;">
                    <thead>
                        <tr>
                            <th style="width:80px;">Tx ID</th>
                            <th>import_external_id</th>
                            <th>Current date</th>
                            <th>Why unmatched</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($unmatched_samples as $u)
                            <tr>
                                <td>{{ $u['id'] }}</td>
                                <td><code>{{ $u['external_id'] }}</code></td>
                                <td>{{ \Carbon\Carbon::parse($u['current_date'])->format('m/d/y g:i A') }}</td>
                                <td>{{ $u['reason'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif

@if (!empty($row_date_samples))
<div class="row">
    <div class="col-md-12">
        <div class="box box-info">
            <div class="box-header with-border">
                <h3 class="box-title">Debug — xlsx rows that DO have a Sold/Bought date (first 5 + last 5)</h3>
            </div>
            <div class="box-body" style="padding:0;">
                <table class="table table-condensed table-striped" style="margin:0;">
                    <thead>
                        <tr>
                            <th style="width:120px;">xlsx row #</th>
                            <th>Date from col P/F</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($row_date_samples as $r)
                            <tr>
                                <td><code>row{{ $r['row'] }}</code></td>
                                <td>{{ $r['date'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif

@if (!empty($samples))
<div class="row">
    <div class="col-md-12">
        <div class="box box-default">
            <div class="box-header with-border">
                <h3 class="box-title">Sample (first {{ count($samples) }} affected rows)</h3>
            </div>
            <div class="box-body" style="padding:0;">
                <table class="table table-condensed table-striped" style="margin:0;">
                    <thead>
                        <tr>
                            <th style="width:80px;">Tx ID</th>
                            <th>Current date</th>
                            <th>&rarr; New date (from xlsx)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($samples as $s)
                            <tr>
                                <td>{{ $s['id'] }}</td>
                                <td>{{ \Carbon\Carbon::parse($s['current_date'])->format('m/d/y g:i A') }}</td>
                                <td><strong>{{ \Carbon\Carbon::parse($s['new_date'])->format('m/d/y') }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endif
@endif

</section>

<script>
(function () {
    const CSRF = "{{ csrf_token() }}";
    const CHUNK_SIZE = 512 * 1024;
    const out = document.getElementById('fis-output');
    const fileEl = document.getElementById('fis-file');

    function appendOutput(text) {
        out.textContent += text;
        out.scrollTop = out.scrollHeight;
    }
    function makeSessionId() {
        return 'fis_' + Math.random().toString(36).slice(2, 10) + '_' + Date.now().toString(36);
    }

    document.getElementById('fis-clear').addEventListener('click', () => { out.textContent = 'Ready.'; });

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

    async function postRunForm(sessionId, commit) {
        appendOutput('Server is parsing xlsx and ' + (commit ? 'applying' : 'previewing') + '…\n');
        // Use a real form post so the result page renders below.
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/admin/fix-in-store-sold-dates/run';
        form.style.display = 'none';
        const fields = { _token: CSRF, session_id: sessionId, commit: commit ? '1' : '0' };
        for (const k of Object.keys(fields)) {
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = k;
            inp.value = fields[k];
            form.appendChild(inp);
        }
        document.body.appendChild(form);
        form.submit();
    }

    async function go(commit) {
        try {
            const file = fileEl.files[0];
            if (!file) throw new Error('Pick the Nivessa Backend xlsx first.');
            const sessionId = makeSessionId();
            await uploadFileChunked(file, sessionId);
            await postRunForm(sessionId, commit);
        } catch (e) {
            appendOutput('\nERROR: ' + e.message + '\n');
        }
    }

    document.getElementById('fis-preview').addEventListener('click', () => go(false));
    document.getElementById('fis-apply').addEventListener('click', () => {
        if (!confirm('This rewrites transaction_date for the matched In Store rows. A snapshot is saved automatically. Continue?')) return;
        go(true);
    });
})();
</script>
@endsection
