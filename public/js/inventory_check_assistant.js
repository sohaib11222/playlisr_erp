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

    // ── Big plain-English store picker (primary entry point) ─────────
    // Sarah doesn't speak in "presets". The store-picker buttons sit
    // above the advanced filters; clicking one sets the preset, applies
    // location/category, and triggers Build immediately.
    function pickStore(presetKey, btnEl) {
        if (!$preset) return;
        $preset.value = presetKey;
        if (window.jQuery) jQuery($preset).trigger('change'); // syncs select2
        applyPresetMeta();
        document.querySelectorAll('.ica-store-btn').forEach((b) => b.classList.remove('is-active'));
        if (btnEl) btnEl.classList.add('is-active');
        // Defer so the select2/jQuery cascade settles before we POST.
        setTimeout(function () { buildList(); }, 80);
    }
    document.querySelectorAll('.ica-store-btn').forEach((btn) => {
        btn.addEventListener('click', function () {
            pickStore(btn.dataset.preset, btn);
        });
    });

    // The page renders with a default preset (hollywood_all). Apply its
    // meta on first load and auto-build, so Clyde lands on a populated
    // list instead of a "pick a location" error after clicking.
    if ($preset && $preset.value) {
        applyPresetMeta();
        const defaultBtn = document.querySelector('.ica-store-btn[data-preset="' + $preset.value + '"]');
        if (defaultBtn) defaultBtn.classList.add('is-active');
        setTimeout(function () {
            if ($location && $location.value) {
                buildList();
            }
        }, 80);
    }

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

        // Surface what we sent so debugging "No candidates" is one F12 away.
        console.log('[ICA] build request', { location_id: $location && $location.value, preset: $preset && $preset.value, category_id: $category && $category.value });

        // Visible warning if location is empty — most common cause of empty
        // result. The server falls back to preset, but if that doesn't
        // resolve a location either, every bucket comes back 0 items.
        if (!$location || !$location.value) {
            $root.innerHTML = '<div class="alert alert-warning"><strong>No location set.</strong> The store button picked a preset but the linked location couldn\'t be found in the database. Open <em>Advanced filters</em> below the store buttons and pick a location manually, then click Build.</div>';
            return;
        }

        fetch(window.ICA_BUCKETS_URL + '?' + params.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => r.text().then((t) => ({ status: r.status, text: t })))
            .then(({ status, text }) => {
                let payload = null;
                try { payload = JSON.parse(text); } catch (_) { /* not json */ }
                console.log('[ICA] build response', { status, payload, raw: payload ? null : text.substring(0, 500) });
                if (!payload) {
                    $root.innerHTML = '<div class="alert alert-danger"><strong>Server didn\'t return JSON (HTTP ' + status + ').</strong> The browser console (F12 → Console) has the first 500 chars of the response. Most common cause: a PHP error in InventoryCheckService — Sarah, screenshot the console and send me what it says.</div>';
                    return;
                }
                lastResult = payload;
                renderBuckets(payload);
                $exportStrip.style.display = 'block';
            })
            .catch((err) => {
                console.error('[ICA] build error', err);
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
            // payload.buckets came back as an empty object — that means the
            // server returned a structurally valid response but with NO
            // bucket keys. Surface the meta so we can see why.
            const metaJson = payload.meta ? JSON.stringify(payload.meta, null, 2) : '(no meta)';
            html = '<div class="alert alert-warning"><strong>Server returned no buckets.</strong> Usually means the location preset didn\'t resolve. Server meta:<pre style="margin-top:8px; font-size:11px;">' + escapeHtml(metaJson) + '</pre></div>';
        }

        $root.innerHTML = html;
        $summary.textContent = `${totalItems} items across ${order.filter(k => buckets[k] && buckets[k].count > 0).length} buckets · ${totalQty} total qty suggested`;

        attachBucketHandlers();
    }

    function renderBucketSection(key, b) {
        const countClass = (b.count || 0) === 0 ? 'zero' : '';
        const rows = (b.items || []).map((it) => renderRow(key, it)).join('');
        // Sell Speed column shown on fast_oos so Clyde sees the same number
        // his old ChatGPT step produced. Other buckets keep the wider Reason
        // column (no sell-speed concept).
        const showSellSpeed = key === 'fast_oos';
        const headRow = showSellSpeed
            ? `<th><input type="checkbox" class="ica-select-all" data-bucket="${escapeHtml(key)}"></th>
               <th>Product</th><th>Artist</th><th>Format</th><th>Stock</th><th>Sold (window)</th><th>Sell Speed</th><th>Reason</th><th>Tags</th><th>Qty</th><th></th>`
            : `<th><input type="checkbox" class="ica-select-all" data-bucket="${escapeHtml(key)}"></th>
               <th>Product</th><th>Artist</th><th>Format</th><th>Stock</th><th>Sold (window)</th><th>Reason</th><th>Tags</th><th>Qty</th><th></th>`;
        const body = (b.count || 0) === 0
            ? `<div class="ica-bucket-empty">No items in this bucket${b.empty_reason ? ' (' + b.empty_reason.replace(/_/g, ' ') + ')' : ''}.</div>`
            : `<table class="table table-condensed table-striped ica-row-table"><thead><tr>${headRow}</tr></thead><tbody>${rows}</tbody></table>`;

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

        const showSellSpeed = bucket === 'fast_oos';
        const sellSpeedCell = showSellSpeed
            ? `<td>${(it.avg_sell_days !== null && it.avg_sell_days !== undefined) ? escapeHtml(it.avg_sell_days + 'd') : '—'}</td>`
            : '';

        return `<tr data-row-key="${escapeHtml(rowKey)}">
            <td><input type="checkbox" class="ica-row-check" checked></td>
            <td>${product}</td>
            <td>${artist}</td>
            <td>${format}</td>
            <td>${stock}</td>
            <td>${sold}</td>
            ${sellSpeedCell}
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

    // ── Chart imports (file upload + paste) ──────────────────────────
    function importChart(source) {
        const isSp = source === 'street_pulse';
        const bodyEl = document.getElementById(isSp ? 'ica_sp_body' : 'ica_ut_body');
        const weekEl = document.getElementById(isSp ? 'ica_sp_week' : 'ica_ut_week');
        const fileEl = document.getElementById(isSp ? 'ica_sp_file' : 'ica_ut_file');
        const btn = document.getElementById(isSp ? 'ica_sp_import' : 'ica_ut_import');
        const body = (bodyEl && bodyEl.value || '').trim();
        const week = weekEl.value;
        const file = fileEl && fileEl.files && fileEl.files[0] ? fileEl.files[0] : null;

        if (!file && !body) {
            alert('Pick a chart file or paste the chart body.');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Importing…';

        // Use FormData so the file rides along; same endpoint accepts either.
        const fd = new FormData();
        fd.append('source', source);
        fd.append('week_of', week);
        if (body) fd.append('body', body);
        if (file) fd.append('chart_file', file);

        fetch(window.ICA_CHART_IMPORT_URL, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': window.ICA_CSRF,
            },
            credentials: 'same-origin',
            body: fd,
        })
            .then((r) => r.json().then((j) => ({ status: r.status, json: j })))
            .then(({ status, json: resp }) => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-upload"></i> Import';
                if (resp && resp.success) {
                    alert('Imported ' + resp.parsed_rows + ' rows for week of ' + resp.week_of + '.');
                    if (window.jQuery) jQuery('#' + (isSp ? 'ica_sp_modal' : 'ica_ut_modal')).modal('hide');
                    if (fileEl) fileEl.value = '';
                    if (bodyEl) bodyEl.value = '';
                    window.ICA_CHART_FRESHNESS = window.ICA_CHART_FRESHNESS || {};
                    window.ICA_CHART_FRESHNESS[source] = { week_of: resp.week_of, imported_at: new Date().toISOString() };
                    renderFreshness();
                    if (lastResult) buildList();
                } else {
                    const msg = resp && resp.message ? resp.message : ('Import failed (HTTP ' + status + ').');
                    alert(msg);
                }
            })
            .catch((err) => {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-upload"></i> Import';
                alert('Import failed: ' + (err && err.message ? err.message : 'unknown error'));
            });
    }
    const $spImport = document.getElementById('ica_sp_import');
    const $utImport = document.getElementById('ica_ut_import');
    if ($spImport) $spImport.addEventListener('click', () => importChart('street_pulse'));
    if ($utImport) $utImport.addEventListener('click', () => importChart('universal_top'));

    // ── Browser-side OCR for Luminate PNG/JPG screenshots ────────────
    // The weekly Street Pulse / Luminate email arrives as image
    // attachments (PNGs of the Top 200 chart). On image select, run
    // Tesseract.js in the browser, then post-process the recognised text
    // into a tab-separated CSV with Rank/Title/Artist columns and stuff
    // it into the paste textarea. The user reviews + clicks Import; the
    // server's TabularChartParser handles it from there.
    function isImageFile(file) {
        if (!file) return false;
        if (file.type && file.type.indexOf('image/') === 0) return true;
        return /\.(png|jpe?g|webp)$/i.test(file.name || '');
    }

    function ocrLuminateImage(fileInput, textarea, statusEl, fileEl) {
        const file = fileInput.files && fileInput.files[0];
        if (!file || !isImageFile(file)) return;
        if (typeof Tesseract === 'undefined') {
            alert('OCR library failed to load (network blocked?). Paste the rows manually for now.');
            return;
        }
        statusEl.style.display = 'block';
        statusEl.textContent = 'Reading image… 0%';

        Tesseract.recognize(file, 'eng', {
            logger: (m) => {
                if (m && m.status) {
                    const pct = m.progress ? Math.round(m.progress * 100) : 0;
                    statusEl.textContent = m.status + '… ' + pct + '%';
                }
            },
        })
            .then(({ data }) => {
                const text = (data && data.text) || '';
                const tsv = luminateOcrToTsv(text);
                if (!tsv) {
                    statusEl.innerHTML = '<span class="text-danger">Could not find Title/Artist columns in the OCR output. Paste the rows manually below — one per line, "Artist — Title".</span>';
                    return;
                }
                if (textarea) {
                    textarea.value = tsv;
                }
                // Now that the textarea is populated, clear the file input
                // so it doesn't also POST as chart_file (which would route
                // through the xlsx parser and fail on a PNG).
                if (fileEl) fileEl.value = '';
                const rowCount = tsv.split('\n').length - 1; // minus header
                statusEl.innerHTML = '<span class="text-success">✓ Extracted ' + rowCount + ' rows. Review the box below, fix any OCR mistakes, then click Import.</span>';
            })
            .catch((err) => {
                console.error('[ICA] tesseract failed', err);
                statusEl.innerHTML = '<span class="text-danger">OCR failed: ' + (err && err.message ? err.message : 'unknown') + '. Paste the rows manually.</span>';
            });
    }

    /**
     * Tesseract returns one line per visual row of the image. For Luminate's
     * Top 200 layout the line looks like:
     *   "1 MUTINY AFTER MIDNIGHT  JOHNNY BLUE SKIES & THE DARK ATLANTIC ..."
     * We split on runs of 2+ spaces (Tesseract preserves multi-space gaps
     * between columns) and take rank/title/artist as the first three cells.
     * Output is a TSV header + one line per row that the server's
     * TabularChartParser already understands.
     */
    function luminateOcrToTsv(rawText) {
        const lines = rawText.split(/\r?\n/);
        const out = [];
        let sawHeader = false;
        for (let i = 0; i < lines.length; i++) {
            const line = lines[i].replace(/[—–]/g, '-').trim();
            if (!line) continue;
            // Skip the marketing/header rows
            if (/luminate|copyright|confidential|week ending|top \d+|chart for/i.test(line)) {
                continue;
            }
            if (/^rank\b/i.test(line) && /artist/i.test(line) && /title/i.test(line)) {
                sawHeader = true;
                continue;
            }
            // A row should start with a rank number
            const m = line.match(/^(\d{1,3})\s+(.+)$/);
            if (!m) continue;
            const rank = m[1];
            const rest = m[2];
            // Split on 2+ spaces — Luminate exports use wide column gaps.
            // Fall back to single-space split if the OCR collapsed gaps.
            let parts = rest.split(/\s{2,}/).map((s) => s.trim()).filter(Boolean);
            if (parts.length < 2) {
                parts = rest.split(/\s+-\s+|\s+–\s+|\s+—\s+|\t/).map((s) => s.trim()).filter(Boolean);
            }
            if (parts.length < 2) continue;
            const title = parts[0] || '';
            const artist = parts[1] || '';
            if (!title || !artist) continue;
            out.push([rank, title, artist].join('\t'));
        }
        if (out.length === 0) return '';
        // Prepend a header the TabularChartParser can match.
        return ['Rank\tTitle\tArtist', ...out].join('\n');
    }

    const $spFile = document.getElementById('ica_sp_file');
    const $spStatus = document.getElementById('ica_sp_ocr_status');
    const $spBody = document.getElementById('ica_sp_body');
    if ($spFile && $spStatus && $spBody) {
        $spFile.addEventListener('change', function () {
            const f = $spFile.files && $spFile.files[0];
            if (f && isImageFile(f)) {
                ocrLuminateImage($spFile, $spBody, $spStatus, $spFile);
            } else {
                $spStatus.style.display = 'none';
            }
        });
    }

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

    function runApplePull(btn) {
        const outputEl = document.getElementById('ica_run_import_output');
        const origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Running…';
        if (outputEl) { outputEl.style.display = 'block'; outputEl.textContent = 'Fetching Apple Music top 100…'; }

        fetch(window.ICA_RUN_APPLE_URL, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': window.ICA_CSRF,
            },
            credentials: 'same-origin',
            body: JSON.stringify({ dry_run: 0 }),
        })
            .then((r) => r.json())
            .then((resp) => {
                btn.disabled = false;
                btn.innerHTML = origHtml;
                if (outputEl) {
                    const header = resp.success ? `✅ Exit ${resp.exit_code}` : `❌ Failed (${resp.error || 'exit ' + resp.exit_code})`;
                    outputEl.textContent = header + '\n\n' + (resp.output || '(no output)');
                }
                if (resp.success && lastResult) buildList();
            })
            .catch((err) => {
                btn.disabled = false;
                btn.innerHTML = origHtml;
                if (outputEl) outputEl.textContent = 'Request failed: ' + (err && err.message ? err.message : 'unknown');
            });
    }
    const $runApple = document.getElementById('ica_run_apple');
    if ($runApple) $runApple.addEventListener('click', () => runApplePull($runApple));

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
