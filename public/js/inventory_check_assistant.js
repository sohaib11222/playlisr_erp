/**
 * Inventory Check Assistant — bucketed "Order for this week" view.
 *
 * Exposes no globals. Listens for the Apply button, renders bucket sections,
 * handles chart paste imports, and supports export / copy / print.
 */
(function () {
    'use strict';

    const $root = document.getElementById('ica_buckets_root');
    const $applyBtn = document.getElementById('ica_apply');
    const $preset = document.getElementById('ica_preset');
    const $location = document.getElementById('ica_location_id');
    const $category = document.getElementById('ica_category_id');
    const $exportStrip = document.getElementById('ica_export_strip');
    const $summary = document.getElementById('ica_summary');
    const $exportCsv = document.getElementById('ica_export_csv');
    const $copyCart = document.getElementById('ica_copy_cart');
    const $print = document.getElementById('ica_print');

    let lastResult = null;

    // ── Preset metadata → auto-populate location/category ─────────────
    function applyPresetMeta() {
        const key = $preset.value;
        if (!key) return;
        const meta = (window.ICA_PRESET_META || {})[key];
        if (!meta) return;
        if (meta.location_id && $location) {
            $location.value = String(meta.location_id);
            if (window.jQuery) jQuery($location).trigger('change');
        }
        if (meta.category_ids && meta.category_ids.length === 1 && $category) {
            $category.value = String(meta.category_ids[0]);
            if (window.jQuery) jQuery($category).trigger('change');
        }
    }
    if ($preset) $preset.addEventListener('change', applyPresetMeta);

    // ── Build order list (main action) ────────────────────────────────
    if ($applyBtn) {
        $applyBtn.addEventListener('click', function () {
            buildList();
        });
    }

    function buildList() {
        const params = new URLSearchParams();
        if ($location && $location.value) params.append('location_id', $location.value);
        if ($category && $category.value) params.append('category_id', $category.value);
        if ($preset && $preset.value) params.append('preset', $preset.value);

        $root.innerHTML = '<div class="text-center text-muted" style="padding: 30px;"><i class="fa fa-spinner fa-spin fa-2x"></i><p>Building…</p></div>';
        $exportStrip.style.display = 'none';

        fetch(window.ICA_BUCKETS_URL + '?' + params.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((payload) => {
                lastResult = payload;
                renderBuckets(payload);
                if (payload.buckets) $exportStrip.style.display = 'block';
            })
            .catch((err) => {
                $root.innerHTML = '<div class="alert alert-danger">Failed to load: ' + (err && err.message ? err.message : 'unknown error') + '</div>';
            });
    }

    // ── Rendering ────────────────────────────────────────────────────
    function renderBuckets(payload) {
        if (payload.meta && payload.meta.error === 'location_required') {
            $root.innerHTML = '<div class="alert alert-warning"><strong>Pick a location first.</strong></div>';
            return;
        }

        const order = ['fast_oos', 'street_pulse', 'universal_top', 'apple_music_top', 'top_artist_new_releases', 'events_upcoming', 'long_oos_essentials', 'hot_used_oos', 'customer_wants'];
        const buckets = payload.buckets || {};

        let html = '';
        let totalItems = 0;
        let totalQty = 0;

        order.forEach((key) => {
            const b = buckets[key];
            if (!b) return;
            html += renderBucketSection(key, b);
            totalItems += b.count || 0;
            (b.items || []).forEach((it) => {
                totalQty += parseInt(it.suggested_qty || 0, 10) || 0;
            });
        });

        if (html === '') {
            html = '<div class="alert alert-info">No candidates — try a different preset or paste this week\'s charts.</div>';
        }

        $root.innerHTML = html;
        $summary.textContent = `${totalItems} items across ${order.filter(k => buckets[k] && buckets[k].count > 0).length} buckets · ${totalQty} total qty suggested`;

        attachBucketHandlers();
    }

    function renderBucketSection(key, b) {
        const countClass = (b.count || 0) === 0 ? 'zero' : '';
        const rows = (b.items || []).map((it) => renderRow(key, it)).join('');
        const body = (b.count || 0) === 0
            ? `<div class="ica-bucket-empty">No items in this bucket${b.empty_reason ? ' (' + b.empty_reason.replace(/_/g, ' ') + ')' : ''}.</div>`
            : `<table class="table table-condensed table-striped ica-row-table"><thead><tr>
                <th><input type="checkbox" class="ica-select-all" data-bucket="${escapeHtml(key)}"></th>
                <th>Product</th><th>Artist</th><th>Format</th><th>Stock</th><th>Sold (window)</th><th>Reason</th><th>Tags</th><th>Qty</th><th></th>
              </tr></thead><tbody>${rows}</tbody></table>`;

        return `
            <div class="ica-bucket box box-default" data-bucket="${escapeHtml(key)}">
                <div class="ica-bucket-header">
                    <div>
                        <h3>${escapeHtml(b.label || key)} <span class="ica-bucket-count ${countClass}">${b.count || 0}</span></h3>
                        <span class="ica-why">${escapeHtml(b.why || '')}</span>
                    </div>
                    <div>
                        <button type="button" class="btn btn-xs btn-default ica-collapse-toggle" title="Collapse">
                            <i class="fa fa-chevron-up"></i>
                        </button>
                    </div>
                </div>
                <div class="ica-bucket-body">${body}</div>
            </div>
        `;
    }

    function renderRow(bucket, it) {
        const stock = (it.stock === null || it.stock === undefined) ? '—' : it.stock;
        const sold = (it.sold_qty_window === null || it.sold_qty_window === undefined) ? '—' : it.sold_qty_window;
        const tags = (it.tags || []).map((t) => `<span class="ica-tag ${escapeHtml(t)}">${escapeHtml(t.replace(/_/g, ' '))}</span>`).join('');
        const reason = escapeHtml(it.reason || '');
        const product = escapeHtml(it.product || '—');
        const artist = escapeHtml(it.artist || '—');
        const format = escapeHtml(it.format || '');
        const qty = parseInt(it.suggested_qty || 0, 10) || 0;
        const rowKey = [bucket, it.variation_id || '', it.customer_want_id || '', it.artist || '', it.product || ''].join('|');

        const extraCol = bucket === 'customer_wants' && it.customer_want_id
            ? `<button type="button" class="btn btn-xs btn-success ica-fulfill-want" data-want-id="${it.customer_want_id}"><i class="fa fa-check"></i> Fulfilled</button>`
            : (bucket === 'events_upcoming' && it.event_name ? `<small class="text-muted">${escapeHtml(it.event_name)} — ${escapeHtml(it.event_date)}</small>` : '');

        return `<tr data-row-key="${escapeHtml(rowKey)}">
            <td><input type="checkbox" class="ica-row-check" checked></td>
            <td>${product}</td>
            <td>${artist}</td>
            <td>${format}</td>
            <td>${stock}</td>
            <td>${sold}</td>
            <td><small>${reason}</small></td>
            <td>${tags}</td>
            <td><input type="number" class="form-control input-sm ica-qty-input" value="${qty}" min="0" max="99"></td>
            <td>${extraCol}</td>
        </tr>`;
    }

    function attachBucketHandlers() {
        $root.querySelectorAll('.ica-collapse-toggle').forEach((btn) => {
            btn.addEventListener('click', function () {
                const bucketEl = btn.closest('.ica-bucket');
                bucketEl.classList.toggle('ica-collapsed');
                const icon = btn.querySelector('i');
                if (icon) icon.className = bucketEl.classList.contains('ica-collapsed') ? 'fa fa-chevron-down' : 'fa fa-chevron-up';
            });
        });

        $root.querySelectorAll('.ica-select-all').forEach((cb) => {
            cb.addEventListener('change', function () {
                const bucket = cb.dataset.bucket;
                const rows = $root.querySelectorAll(`.ica-bucket[data-bucket="${cssEscape(bucket)}"] .ica-row-check`);
                rows.forEach((r) => { r.checked = cb.checked; });
            });
        });

        $root.querySelectorAll('.ica-fulfill-want').forEach((btn) => {
            btn.addEventListener('click', function () {
                const wantId = btn.dataset.wantId;
                if (!wantId) return;
                if (!confirm('Mark this customer want as fulfilled?')) return;
                fetch(window.ICA_CUSTOMER_WANT_FULFILL_URL + '/' + encodeURIComponent(wantId) + '/fulfill', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': window.ICA_CSRF,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ note: 'via Inventory Check Assistant' }),
                })
                    .then((r) => r.json())
                    .then(() => {
                        const tr = btn.closest('tr');
                        if (tr) tr.remove();
                    });
            });
        });
    }

    // ── Chart freshness ──────────────────────────────────────────────
    function renderFreshness() {
        const fresh = window.ICA_CHART_FRESHNESS || {};
        const fmt = (f) => f && f.week_of ? `Last imported ${f.week_of} (${f.imported_at ? String(f.imported_at).substring(0, 10) : ''})` : 'Not yet imported';
        const sp = document.getElementById('ica_sp_freshness');
        const ut = document.getElementById('ica_ut_freshness');
        if (sp) sp.textContent = fmt(fresh.street_pulse);
        if (ut) ut.textContent = fmt(fresh.universal_top);
    }
    renderFreshness();

    // ── Chart paste imports ──────────────────────────────────────────
    function importChart(source) {
        const isSp = source === 'street_pulse';
        const bodyEl = document.getElementById(isSp ? 'ica_sp_body' : 'ica_ut_body');
        const weekEl = document.getElementById(isSp ? 'ica_sp_week' : 'ica_ut_week');
        const btn = document.getElementById(isSp ? 'ica_sp_import' : 'ica_ut_import');
        const body = bodyEl.value.trim();
        const week = weekEl.value;
        if (!body) { alert('Paste the chart body first.'); return; }

        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Importing…';

        fetch(window.ICA_CHART_IMPORT_URL, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window.ICA_CSRF,
            },
            credentials: 'same-origin',
            body: JSON.stringify({ source: source, week_of: week, body: body }),
        })
            .then((r) => r.json())
            .then((resp) => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-upload"></i> Import';
                if (resp && resp.success) {
                    alert('Imported ' + resp.parsed_rows + ' rows for week of ' + resp.week_of + '.');
                    if (window.jQuery) jQuery('#' + (isSp ? 'ica_sp_modal' : 'ica_ut_modal')).modal('hide');
                    window.ICA_CHART_FRESHNESS = window.ICA_CHART_FRESHNESS || {};
                    window.ICA_CHART_FRESHNESS[source] = { week_of: resp.week_of, imported_at: new Date().toISOString() };
                    renderFreshness();
                    if (lastResult) buildList();
                } else {
                    alert('Import failed. Check the body and try again.');
                }
            })
            .catch(() => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-upload"></i> Import';
                alert('Import failed.');
            });
    }
    const $spImport = document.getElementById('ica_sp_import');
    const $utImport = document.getElementById('ica_ut_import');
    if ($spImport) $spImport.addEventListener('click', () => importChart('street_pulse'));
    if ($utImport) $utImport.addEventListener('click', () => importChart('universal_top'));

    // ── Run email import (auto-fetch trigger) ───────────────────────
    function runEmailImport(btn, dryRun) {
        const outputEl = document.getElementById('ica_run_import_output');
        const origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Running…';
        if (outputEl) { outputEl.style.display = 'block'; outputEl.textContent = 'Connecting to IMAP, searching recent emails…'; }

        fetch(window.ICA_RUN_EMAIL_IMPORT_URL, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window.ICA_CSRF,
            },
            credentials: 'same-origin',
            body: JSON.stringify({ dry_run: dryRun ? 1 : 0, since: 7 }),
        })
            .then((r) => r.json())
            .then((resp) => {
                btn.disabled = false;
                btn.innerHTML = origHtml;
                if (outputEl) {
                    const header = resp.success
                        ? `✅ Exit code ${resp.exit_code} (${resp.dry_run ? 'dry run' : 'committed'})`
                        : `❌ Failed (${resp.error || 'exit ' + resp.exit_code})`;
                    outputEl.textContent = header + '\n\n' + (resp.output || '(no output)');
                }
                if (resp.success && !resp.dry_run && lastResult) buildList();
            })
            .catch((err) => {
                btn.disabled = false;
                btn.innerHTML = origHtml;
                if (outputEl) outputEl.textContent = 'Request failed: ' + (err && err.message ? err.message : 'unknown');
            });
    }
    const $runDry = document.getElementById('ica_run_import');
    const $runReal = document.getElementById('ica_run_import_real');
    if ($runDry) $runDry.addEventListener('click', () => runEmailImport($runDry, true));
    if ($runReal) $runReal.addEventListener('click', () => {
        if (!confirm('Run the import and write chart_picks to the database?')) return;
        runEmailImport($runReal, false);
    });

    // ── Export / copy / print ───────────────────────────────────────
    if ($exportCsv) {
        $exportCsv.addEventListener('click', function () {
            const params = new URLSearchParams();
            if ($location.value) params.append('location_id', $location.value);
            if ($category.value) params.append('category_id', $category.value);
            if ($preset.value) params.append('preset', $preset.value);
            window.location.href = window.ICA_EXPORT_URL + '?' + params.toString();
        });
    }

    if ($copyCart) {
        $copyCart.addEventListener('click', function () {
            if (!lastResult) { alert('Build the list first.'); return; }
            const lines = [];
            const fmt = window.ICA_COPY_FORMAT || '{qty} x {sku} — {product}';
            Object.keys(lastResult.buckets || {}).forEach((key) => {
                const b = lastResult.buckets[key];
                (b.items || []).forEach((it) => {
                    const qty = parseInt(it.suggested_qty || 0, 10) || 0;
                    if (qty < 1) return;
                    const line = fmt
                        .replace('{qty}', qty)
                        .replace('{sku}', it.sku || '(no sku)')
                        .replace('{product}', (it.artist ? it.artist + ' — ' : '') + (it.product || ''));
                    lines.push(line);
                });
            });
            const text = lines.join('\n');
            if (!text) { alert('Nothing to copy.'); return; }
            navigator.clipboard.writeText(text).then(
                () => alert('Copied ' + lines.length + ' lines.'),
                () => prompt('Copy manually:', text)
            );
        });
    }

    if ($print) {
        $print.addEventListener('click', function () { window.print(); });
    }

    // ── Sessions ────────────────────────────────────────────────────
    function loadSessions() {
        fetch(window.ICA_SESSIONS_URL, { credentials: 'same-origin' })
            .then((r) => r.json())
            .then((resp) => {
                const sel = document.getElementById('ica_session_select');
                if (!sel) return;
                sel.innerHTML = '<option value="">—</option>';
                (resp.data || []).forEach((s) => {
                    const opt = document.createElement('option');
                    opt.value = s.id;
                    opt.textContent = s.name + ' (' + (s.updated_at || '').substring(0, 10) + ')';
                    sel.appendChild(opt);
                });
            });
    }
    loadSessions();

    const $saveSession = document.getElementById('ica_session_save');
    if ($saveSession) {
        $saveSession.addEventListener('click', function () {
            const name = document.getElementById('ica_session_name').value.trim();
            if (!name) { alert('Give it a name.'); return; }
            fetch(window.ICA_SESSIONS_STORE, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': window.ICA_CSRF,
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    name: name,
                    location_id: $location.value || null,
                    category_id: $category.value || null,
                    preset_key: $preset.value || null,
                }),
            })
                .then((r) => r.json())
                .then(() => loadSessions());
        });
    }

    // ── Util ─────────────────────────────────────────────────────────
    function escapeHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }
    function cssEscape(s) {
        return String(s).replace(/"/g, '\\"');
    }

})();
