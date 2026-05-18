@extends('layouts.app')
@section('title', 'Discogs Inventory Import')

@section('content')
<section class="content-header">
    <h1>Discogs Inventory Import</h1>
    <p class="text-muted">Bulk-pull "For Sale" listings from Discogs and create one ERP product per listing in a dedicated location. Rate-limited paging happens in the browser — leave the tab open until it finishes.</p>
    <div class="callout callout-info" style="margin-top:8px;">
        <strong>Currently imported:</strong> {{ number_format($imported_count) }} Discogs listings
        @if(count($by_location))
            ·
            @foreach($by_location as $row)
                {{ $row->name }}: {{ number_format($row->cnt) }}@if(!$loop->last), @endif
            @endforeach
        @endif
    </div>
    @if($extra_rows > 0)
    <div class="callout callout-warning" style="margin-top:8px;">
        <strong>{{ number_format($extra_rows) }} duplicate products found</strong>
        ({{ number_format($dupe_sub_skus) }} unique listing_ids with more than one ERP row — from concurrent apply runs before the listing_id dedup fix).
        <div style="margin-top:8px;">
            <button id="dii-dedup-preview" class="btn btn-default btn-sm">Preview cleanup</button>
            <button id="dii-dedup-apply" class="btn btn-danger btn-sm">Delete duplicates</button>
            <span id="dii-dedup-status" style="margin-left:10px;"></span>
        </div>
    </div>
    @endif

    <div class="callout" style="margin-top:8px; background:#fff8e1;">
        <strong>Categorize + set purchase prices</strong>
        — Re-evaluates every Discogs product's format & Mint(M) condition, assigns the right ERP category (Sealed Vinyl / Used Vinyl / 7"/45 / CD / Cassette / 8-track / VHS), and stamps the matching cost ($17 / $0.35 / $0.15 / etc.) onto its variation. Skips variations with non-zero cost so manual edits survive.
        <div style="margin-top:8px;">
            <button id="dii-cat-preview" class="btn btn-default btn-sm">Preview categorization</button>
            <button id="dii-cat-apply" class="btn btn-warning btn-sm">Apply categories + costs</button>
            <span id="dii-cat-status" style="margin-left:10px;"></span>
        </div>
    </div>

    <div class="callout" style="margin-top:8px; background:#f4ecf7;">
        <strong>POS access for cashier roles</strong>
        — Discogs Warehouse is normally invisible to cashiers at the register. Grant per-role access here so they can ring Discogs items if a customer asks.
        <div id="dii-roles-list" style="margin-top:8px; font-size:13px;">
            <button id="dii-roles-load" class="btn btn-default btn-sm">Load roles</button>
        </div>
    </div>

    <div class="callout callout-primary" style="margin-top:8px;">
        <strong>Show in /products list</strong>
        — Discogs imports are <code>is_inactive=1</code> so they don't clog POS search. Flip to visible to manage them under /products. POS is unaffected (cashiers have no Discogs Warehouse location permission).
        <div style="margin-top:8px;">
            <button id="dii-vis-show" class="btn btn-primary btn-sm">Show in /products</button>
            <button id="dii-vis-hide" class="btn btn-default btn-sm">Hide again</button>
            <span id="dii-vis-status" style="margin-left:10px;"></span>
        </div>
    </div>

    <div class="callout callout-success" style="margin-top:8px;">
        <strong>Reconcile against current Discogs inventory CSV</strong>
        — upload your latest Discogs "Export Inventory" CSV; any ERP DG-{listing_id} product whose listing_id is not in the CSV gets deleted.
        <div style="margin-top:8px;">
            <input type="file" id="dii-reconcile-file" accept=".csv" style="display:inline-block;" />
            <button id="dii-reconcile-preview" class="btn btn-default btn-sm">Preview delta</button>
            <button id="dii-reconcile-apply" class="btn btn-danger btn-sm">Delete orphans</button>
            <span id="dii-reconcile-status" style="margin-left:10px;"></span>
        </div>
    </div>
</section>

<section class="content">

<div class="row">
    <div class="col-md-5">
        <div class="box box-solid">
            <div class="box-header"><h3 class="box-title">1. Snapshot inventory</h3></div>
            <div class="box-body">
                <div class="form-group">
                    <label>Discogs username</label>
                    <input type="text" id="dii-username" class="form-control" value="nivessa" />
                </div>
                <button id="dii-start" class="btn btn-default btn-lg">Start snapshot</button>
                <button id="dii-resume" class="btn btn-default" style="display:none;">Resume</button>
                <p class="help-block" style="margin-top:10px;">
                    Discogs caps authenticated requests at 60/min. For 55k listings (~550 pages) this runs ~10 minutes.
                </p>
            </div>
        </div>

        <div class="box box-solid">
            <div class="box-header"><h3 class="box-title">2. Preview dupes</h3></div>
            <div class="box-body">
                <button id="dii-preview" class="btn btn-default" disabled>Run preview</button>
                <div id="dii-preview-stats" style="margin-top:10px; font-size:13px;"></div>
            </div>
        </div>

        <div class="box box-solid">
            <div class="box-header"><h3 class="box-title">3. Apply</h3></div>
            <div class="box-body">
                <div class="form-group">
                    <label>Target business location</label>
                    <select id="dii-location" class="form-control">
                        <option value="0">Auto-create "{{ $default_location_name }}"</option>
                        @foreach($locations as $loc)
                            <option value="{{ $loc->id }}">{{ $loc->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="checkbox" style="margin-top:6px;">
                    <label>
                        <input type="checkbox" id="dii-hide-pos" checked />
                        Hide imported products from POS (recommended — keeps cashier search fast)
                    </label>
                </div>
                <button id="dii-apply" class="btn btn-primary btn-lg" disabled>Apply (create products)</button>
                <p class="help-block" style="margin-top:10px;">
                    Skips listings whose Discogs <code>release_id</code> already matches an existing ERP product. Skipped rows are in the dupes CSV from step 2.
                </p>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="box box-solid">
            <div class="box-header"><h3 class="box-title">Progress</h3></div>
            <div class="box-body" style="padding:0;">
                <pre id="dii-output" style="margin:0; max-height:700px; overflow:auto; padding:12px; background:#1e1e1e; color:#d4d4d4; font-size:12px; line-height:1.45; white-space:pre-wrap;">Ready.</pre>
            </div>
        </div>

        @if(!empty($snapshots))
        <div class="box box-solid">
            <div class="box-header"><h3 class="box-title">Recent snapshots</h3></div>
            <div class="box-body" style="padding:0;">
                <table class="table table-condensed" style="margin:0;">
                    <thead><tr><th>Snapshot</th><th>Started</th><th>User</th><th>Items</th><th>Status</th><th>Resume</th></tr></thead>
                    <tbody>
                    @foreach($snapshots as $s)
                        <tr>
                            <td><code>{{ $s['snapshot_id'] ?? '' }}</code></td>
                            <td>{{ $s['started_at'] ?? '' }}</td>
                            <td>{{ $s['username'] ?? '' }}</td>
                            <td>{{ $s['rows_written'] ?? 0 }} / {{ $s['total_items'] ?? '?' }}</td>
                            <td>{{ $s['status'] ?? '' }} @if(!empty($s['apply_status'])) · {{ $s['apply_status'] }}@endif</td>
                            <td>
                                <button class="btn btn-xs btn-default dii-resume-btn" data-snap="{{ $s['snapshot_id'] ?? '' }}" data-user="{{ $s['username'] ?? '' }}">Load</button>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>
</div>

</section>

<script>
(function () {
    const CSRF = "{{ csrf_token() }}";
    const out = document.getElementById('dii-output');
    let currentSnap = null;

    function log(msg) {
        out.textContent += msg + "\n";
        out.scrollTop = out.scrollHeight;
    }
    function setSnap(id, meta) {
        currentSnap = id;
        document.getElementById('dii-preview').disabled = !id;
        document.getElementById('dii-apply').disabled = !id;
        document.getElementById('dii-resume').style.display = id ? 'inline-block' : 'none';
    }

    async function postJson(url, body) {
        const resp = await fetch(url, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': CSRF,
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
            body: JSON.stringify(body || {}),
        });
        const j = await resp.json().catch(() => ({ok:false, error: 'Bad JSON ' + resp.status}));
        return { status: resp.status, body: j };
    }

    async function startSnapshot() {
        const username = document.getElementById('dii-username').value.trim();
        if (!username) { alert('Enter a Discogs username'); return; }
        log('▶ Starting snapshot for @' + username + '…');
        const r = await postJson('/admin/discogs-import-inventory/snapshot-start', { username });
        if (!r.body.ok) { log('  ✗ ' + (r.body.error || 'failed')); return; }
        setSnap(r.body.snapshot_id);
        log('  snapshot=' + r.body.snapshot_id + '  total_items=' + r.body.total_items + '  total_pages=' + r.body.total_pages);
        log('  page 1 done (' + r.body.rows_written + ' rows)');
        await pageLoop(2, r.body.total_pages);
    }

    async function pageLoop(startPage, totalPages) {
        let page = startPage;
        // Discogs auth'd rate limit is 60/min — pace at 1.1s between requests
        // (~54/min, comfortable buffer for ngrok/proxy jitter).
        const SLEEP_MS = 1100;
        while (true) {
            const r = await postJson('/admin/discogs-import-inventory/snapshot-page', {
                snapshot_id: currentSnap,
                page: page,
            });
            if (r.status === 429) {
                log('  ⏸ rate-limited at page ' + page + ' — backing off 65s');
                await new Promise(rs => setTimeout(rs, 65000));
                continue;
            }
            if (!r.body.ok) { log('  ✗ page ' + page + ' failed: ' + (r.body.error || r.status)); return; }
            totalPages = r.body.total_pages || totalPages;
            if (page % 10 === 0 || r.body.done) {
                log('  page ' + page + '/' + totalPages + '  rows=' + r.body.rows_written);
            }
            if (r.body.done) {
                log('✓ snapshot complete: ' + r.body.rows_written + ' rows across ' + r.body.pages_fetched + ' pages');
                return;
            }
            page++;
            await new Promise(rs => setTimeout(rs, SLEEP_MS));
        }
    }

    async function preview() {
        if (!currentSnap) { alert('Start or load a snapshot first.'); return; }
        log('▶ Running preview (scanning snapshot for dupes vs existing products)…');
        const r = await postJson('/admin/discogs-import-inventory/preview', { snapshot_id: currentSnap });
        if (!r.body.ok) { log('  ✗ ' + (r.body.error || 'failed')); return; }
        const html = `
            <div><strong>Total in snapshot:</strong> ${r.body.total}</div>
            <div><strong>New (will be created):</strong> ${r.body.new}</div>
            <div><strong>Skipped — already imported:</strong> ${r.body.already_applied}</div>
            <div><strong>Skipped — release_id already in ERP:</strong> ${r.body.dupes}
                ${r.body.dupes > 0 ? ' <a href="' + r.body.dupes_csv_url + '">download dupes CSV</a>' : ''}
            </div>
        `;
        document.getElementById('dii-preview-stats').innerHTML = html;
        log('✓ preview: total=' + r.body.total + ' new=' + r.body.new + ' dupes=' + r.body.dupes + ' already_applied=' + r.body.already_applied);
    }

    async function apply() {
        if (!currentSnap) { alert('Start or load a snapshot first.'); return; }
        const locId = parseInt(document.getElementById('dii-location').value || '0', 10);
        const hidePos = document.getElementById('dii-hide-pos').checked;
        log('▶ Applying snapshot ' + currentSnap + (locId > 0 ? '  → location_id=' + locId : '  → auto-create location') + (hidePos ? '  (hidden from POS)' : '  (visible in POS)'));
        let offset = 0;
        let totalCreated = 0;
        let totalSkipped = 0;
        const batch = 100;
        while (true) {
            const r = await postJson('/admin/discogs-import-inventory/apply', {
                snapshot_id: currentSnap,
                offset: offset,
                batch_size: batch,
                location_id: locId,
                hide_from_pos: hidePos,
            });
            if (!r.body.ok) { log('  ✗ ' + (r.body.error || 'failed')); return; }
            totalCreated += r.body.created || 0;
            totalSkipped += r.body.skipped || 0;
            log('  offset ' + offset + '..' + r.body.next_offset + '  +' + r.body.created + ' created, ' + r.body.skipped + ' skipped' + ((r.body.errors||[]).length ? ' (' + r.body.errors.length + ' errors)' : ''));
            if ((r.body.errors || []).length) {
                for (const e of r.body.errors.slice(0, 5)) {
                    log('    ✗ listing ' + e.listing_id + ': ' + e.error);
                }
            }
            if (r.body.done) {
                log('✓ apply complete  total created=' + totalCreated + '  skipped=' + totalSkipped + '  → "' + r.body.location_name + '" (id ' + r.body.location_id + ')');
                return;
            }
            offset = r.body.next_offset;
        }
    }

    document.getElementById('dii-start').addEventListener('click', startSnapshot);
    document.getElementById('dii-preview').addEventListener('click', preview);
    document.getElementById('dii-apply').addEventListener('click', apply);
    async function resumeFromServer() {
        if (!currentSnap) { alert('Load a snapshot first.'); return; }
        log('▶ Asking server where to resume snapshot ' + currentSnap + '…');
        const r = await postJson('/admin/discogs-import-inventory/status', { snapshot_id: currentSnap });
        if (!r.body.ok) { log('  ✗ ' + (r.body.error || 'failed')); return; }
        const meta = r.body.meta || {};
        const pagesFetched = meta.pages_fetched || 1;
        const totalPages = meta.total_pages || 9999;
        if (pagesFetched >= totalPages) {
            log('  snapshot already complete (' + pagesFetched + '/' + totalPages + '). Skip to Preview.');
            return;
        }
        log('  resuming at page ' + (pagesFetched + 1) + ' of ' + totalPages + ' (' + (meta.rows_written || 0) + ' rows already written)');
        await pageLoop(pagesFetched + 1, totalPages);
    }

    document.getElementById('dii-resume').addEventListener('click', resumeFromServer);

    const dedupPreviewBtn = document.getElementById('dii-dedup-preview');
    const dedupApplyBtn = document.getElementById('dii-dedup-apply');
    const dedupStatus = document.getElementById('dii-dedup-status');
    if (dedupPreviewBtn) {
        dedupPreviewBtn.addEventListener('click', async () => {
            dedupStatus.textContent = 'scanning…';
            const r = await postJson('/admin/discogs-import-inventory/cleanup-duplicates', { confirm: false });
            if (!r.body.ok) { dedupStatus.textContent = 'error: ' + (r.body.error || r.status); return; }
            dedupStatus.textContent = 'Would delete ' + r.body.product_ids_to_delete.toLocaleString() + ' products.';
        });
    }
    if (dedupApplyBtn) {
        dedupApplyBtn.addEventListener('click', async () => {
            if (!confirm('Soft-delete duplicate Discogs products? Snapshot will be written to storage/app/admin-snapshots first.')) return;
            dedupStatus.textContent = 'deleting…';
            const r = await postJson('/admin/discogs-import-inventory/cleanup-duplicates', { confirm: true });
            if (!r.body.ok) { dedupStatus.textContent = 'error: ' + (r.body.error || r.status); return; }
            dedupStatus.textContent = '✓ Deleted ' + r.body.deleted.toLocaleString() + '. Snapshot: ' + r.body.snapshot + '. Reload the page to refresh counts.';
        });
    }

    const catPreviewBtn = document.getElementById('dii-cat-preview');
    const catApplyBtn = document.getElementById('dii-cat-apply');
    const catStatus = document.getElementById('dii-cat-status');
    async function runCat(confirm) {
        catStatus.textContent = confirm ? 'applying…' : 'analyzing…';
        const r = await postJson('/admin/discogs-import-inventory/backfill-categories', { confirm: confirm });
        if (!r.body.ok) { catStatus.textContent = 'error: ' + (r.body.error || r.status); return; }
        const breakdown = Object.entries(r.body.breakdown || {})
            .map(([k, v]) => k + ': ' + v.toLocaleString())
            .join(' · ');
        if (r.body.preview) {
            catStatus.innerHTML = 'Would categorize ' + r.body.total.toLocaleString() + ' products → ' + breakdown;
        } else {
            catStatus.innerHTML = '✓ Updated ' + r.body.products_updated.toLocaleString() + ' products + ' + r.body.variations_updated.toLocaleString() + ' variation costs. ' + breakdown;
        }
    }
    if (catPreviewBtn) catPreviewBtn.addEventListener('click', () => runCat(false));
    if (catApplyBtn) catApplyBtn.addEventListener('click', () => {
        if (!confirm('Assign category_id to all uncategorized Discogs products? Snapshot saved first.')) return;
        runCat(true);
    });

    const rolesListEl = document.getElementById('dii-roles-list');
    async function loadRoles() {
        rolesListEl.innerHTML = 'loading…';
        const resp = await fetch('/admin/discogs-import-inventory/roles', {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF },
        });
        const j = await resp.json().catch(() => ({ok:false, error: 'Bad JSON ' + resp.status}));
        if (!j.ok) { rolesListEl.textContent = 'error: ' + (j.error || resp.status); return; }
        const rows = j.roles.map(r => {
            const checked = r.has_access ? 'checked' : '';
            return `<label style="display:block; padding:3px 0;">
                <input type="checkbox" class="dii-role-toggle" data-role-id="${r.id}" ${checked} />
                ${r.name}
            </label>`;
        }).join('');
        rolesListEl.innerHTML = rows + '<div style="font-size:11px; color:#888; margin-top:6px;">Check = role can see Discogs Warehouse in POS. Changes take effect on next login.</div>';
        document.querySelectorAll('.dii-role-toggle').forEach(cb => {
            cb.addEventListener('change', async () => {
                const r = await postJson('/admin/discogs-import-inventory/set-pos-access', {
                    role_id: parseInt(cb.getAttribute('data-role-id'), 10),
                    grant: cb.checked,
                });
                if (!r.body.ok) { alert('error: ' + (r.body.error || r.status)); cb.checked = !cb.checked; }
            });
        });
    }
    const rolesLoadBtn = document.getElementById('dii-roles-load');
    if (rolesLoadBtn) rolesLoadBtn.addEventListener('click', loadRoles);

    const visShowBtn = document.getElementById('dii-vis-show');
    const visHideBtn = document.getElementById('dii-vis-hide');
    const visStatus = document.getElementById('dii-vis-status');
    async function toggleVis(show) {
        visStatus.textContent = '…';
        const r = await postJson('/admin/discogs-import-inventory/toggle-visibility', { show: show });
        if (!r.body.ok) { visStatus.textContent = 'error: ' + (r.body.error || r.status); return; }
        visStatus.textContent = '✓ ' + r.body.updated.toLocaleString() + ' products now ' + r.body.now + '. Visit /products to see them.';
    }
    if (visShowBtn) visShowBtn.addEventListener('click', () => toggleVis(true));
    if (visHideBtn) visHideBtn.addEventListener('click', () => toggleVis(false));

    const reconcileFile = document.getElementById('dii-reconcile-file');
    const reconcilePreviewBtn = document.getElementById('dii-reconcile-preview');
    const reconcileApplyBtn = document.getElementById('dii-reconcile-apply');
    const reconcileStatus = document.getElementById('dii-reconcile-status');

    async function runReconcile(confirm) {
        if (!reconcileFile.files || !reconcileFile.files[0]) {
            alert('Pick a CSV first.');
            return;
        }
        const fd = new FormData();
        fd.append('csv', reconcileFile.files[0]);
        fd.append('confirm', confirm ? '1' : '0');
        reconcileStatus.textContent = confirm ? 'deleting…' : 'analyzing…';
        const resp = await fetch('/admin/discogs-import-inventory/reconcile-csv', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
            body: fd,
        });
        const j = await resp.json().catch(() => ({ok:false, error: 'Bad JSON ' + resp.status}));
        if (!j.ok) { reconcileStatus.textContent = 'error: ' + (j.error || resp.status); return; }
        if (j.preview) {
            reconcileStatus.innerHTML = 'CSV listings: ' + j.csv_listings.toLocaleString()
                + ' · ERP listings: ' + j.erp_listings.toLocaleString()
                + ' · <strong>Would delete: ' + j.to_delete.toLocaleString() + '</strong>'
                + ' · Missing from ERP (need import): ' + j.missing_from_erp.toLocaleString();
        } else {
            reconcileStatus.innerHTML = '✓ Deleted ' + j.deleted.toLocaleString() + ' orphan products. Snapshot: ' + j.snapshot + '. Reload to refresh counts.';
        }
    }

    if (reconcilePreviewBtn) reconcilePreviewBtn.addEventListener('click', () => runReconcile(false));
    if (reconcileApplyBtn) reconcileApplyBtn.addEventListener('click', () => {
        if (!confirm('Delete ERP products whose Discogs listing_id is not in the CSV? Snapshot saved first.')) return;
        runReconcile(true);
    });

    document.querySelectorAll('.dii-resume-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const snap = btn.getAttribute('data-snap');
            const user = btn.getAttribute('data-user');
            setSnap(snap);
            document.getElementById('dii-username').value = user || 'nivessa';
            log('▶ Loaded snapshot ' + snap + ' (user=' + user + '). Click Resume to continue fetching, or Preview/Apply if it\'s complete.');
        });
    });
})();
</script>
@endsection
