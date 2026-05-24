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

        // Friendlier loading state — Sarah called "Building…" confusing
        // (2026-05-20). Tell the user what's happening + that the slow
        // part is one-time-per-store and other lists fill in after.
        const activeBtn = document.querySelector('.ica-store-btn.is-active');
        const storeLabel = activeBtn ? activeBtn.textContent.trim() : 'this store';
        $root.innerHTML = `
            <div class="ica-loading-card">
                <div class="ica-loading-head">
                    <i class="fa fa-spinner fa-spin"></i>
                    <strong>Loading fast sellers list for ${escapeHtml(storeLabel)}…</strong>
                </div>
                <div class="ica-loading-meta">
                    First load takes 5-15 seconds — re-clicks are cached and instant.
                    The other lists (charts, events, ABC, frozen, manager picks) load in the background after this finishes.
                </div>
                <div class="ica-loading-skeleton">
                    <div class="ica-skeleton-row"></div>
                    <div class="ica-skeleton-row"></div>
                    <div class="ica-skeleton-row"></div>
                    <div class="ica-skeleton-row"></div>
                    <div class="ica-skeleton-row"></div>
                </div>
            </div>`;
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
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': window.ICA_CSRF || '' },
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
                // Lazy-load the heavy buckets — events hits 2 external feeds,
                // ABC scans the full catalog, frozen crosses transaction
                // history. None of them block the main page now (Sarah was
                // stuck on "Building…" 2026-05-20).
                lazyLoadEventsBucket();
                lazyLoadAuxBucket('manager_picks', window.ICA_MGRPICKS_BUCKET_URL);
                lazyLoadAuxBucket('ume_spotlights', window.ICA_UME_SPOT_URL);
                lazyLoadAuxBucket('abc_a_restock', window.ICA_ABC_URL);
                lazyLoadAuxBucket('frozen_inventory', window.ICA_FROZEN_URL);
                lazyLoadSecondaryBuckets();
            })
            .catch((err) => {
                console.error('[ICA] build error', err);
                $root.innerHTML = '<div class="alert alert-danger">Failed to load: ' + (err && err.message ? err.message : 'unknown error') + '</div>';
            });
    }

    // ── Rendering ────────────────────────────────────────────────────

    /** Generic lazy-bucket loader for ABC + frozen (same pattern as events). */
    function lazyLoadAuxBucket(bucketKey, url) {
        if (!url) return;
        const params = new URLSearchParams();
        if ($location && $location.value) params.append('location_id', $location.value);
        if ($preset && $preset.value) params.append('preset', $preset.value);

        fetch(url + '?' + params.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': window.ICA_CSRF || '' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((resp) => {
                if (!resp || !resp.bucket) return;
                if (lastResult && lastResult.buckets) {
                    lastResult.buckets[bucketKey] = resp.bucket;
                }
                const existing = $root.querySelector('.ica-bucket[data-bucket="' + bucketKey + '"]');
                if (!existing) return;
                const html = renderBucketSection(bucketKey, resp.bucket);
                const tmp = document.createElement('div');
                tmp.innerHTML = html;
                const fresh = tmp.firstElementChild;
                if (fresh) {
                    existing.replaceWith(fresh);
                    attachBucketHandlers();
                }
                // Frozen bucket loaded — sweep other rendered rows and tag
                // matches as "frozen_dupe" so Sarah sees the dupe warning
                // right on the reorder row. Also surface the total $$ tied
                // up as a banner under the budget bar (Sarah liked the
                // budget-bar style 2026-05-20).
                if (bucketKey === 'frozen_inventory') {
                    applyFrozenDupeTags(resp.bucket.items || []);
                    renderFrozenInsight(resp.bucket);
                    attachFrozenStockEditors();
                }
                // ABC bucket carries the full A/B/C product_id map — paint
                // pills on rows in every other bucket so fast sellers etc.
                // show whether the title is A/B/C class.
                if (bucketKey === 'abc_a_restock' && resp.bucket && resp.bucket.abc_map) {
                    applyAbcTags(resp.bucket.abc_map);
                }
                rebuildFilterOptions();
                applyRowFilters();
            })
            .catch((err) => console.error('[ICA] aux bucket lazy-load failed', bucketKey, err));
    }

    /**
     * Re-fetch the frozen bucket with a custom days threshold. Triggered
     * from the in-header "Frozen if no sale in [N] days" select / input.
     * Shows a loading shim on the bucket body while the request runs.
     */
    function refetchFrozenBucket(days) {
        if (!window.ICA_FROZEN_URL) return;
        const bucket = $root.querySelector('.ica-bucket[data-bucket="frozen_inventory"]');
        const body = bucket ? bucket.querySelector('.ica-bucket-body') : null;
        if (body) body.innerHTML = '<div class="ica-bucket-empty"><i class="fa fa-spinner fa-spin"></i> Recalculating with ' + days + '-day threshold…</div>';
        const params = new URLSearchParams();
        if ($location && $location.value) params.append('location_id', $location.value);
        if ($preset && $preset.value) params.append('preset', $preset.value);
        params.append('days', String(days));
        fetch(window.ICA_FROZEN_URL + '?' + params.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': window.ICA_CSRF || '' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((resp) => {
                if (!resp || !resp.bucket) return;
                if (lastResult && lastResult.buckets) {
                    lastResult.buckets.frozen_inventory = resp.bucket;
                }
                const existing = $root.querySelector('.ica-bucket[data-bucket="frozen_inventory"]');
                if (!existing) return;
                const html = renderBucketSection('frozen_inventory', resp.bucket);
                const tmp = document.createElement('div');
                tmp.innerHTML = html;
                const fresh = tmp.firstElementChild;
                if (fresh) {
                    existing.replaceWith(fresh);
                    attachBucketHandlers();
                }
                applyFrozenDupeTags(resp.bucket.items || []);
                renderFrozenInsight(resp.bucket);
                attachFrozenStockEditors();
                rebuildFilterOptions();
                applyRowFilters();
            })
            .catch((err) => console.error('[ICA] frozen days re-fetch failed', err));
    }

    function renderFrozenInsight(bucket) {
        if (!bucket) return;
        const tied = parseFloat(bucket.tied_up_value_total || 0);
        const count = parseInt(bucket.count || 0, 10);
        const days = parseInt(bucket.frozen_days || 180, 10);
        let host = document.getElementById('ica_frozen_insight');
        if (!host) {
            host = document.createElement('div');
            host.id = 'ica_frozen_insight';
            const budgetBanner = document.querySelector('.ica-budget-banner');
            if (budgetBanner && budgetBanner.parentNode) {
                budgetBanner.parentNode.insertBefore(host, budgetBanner.nextSibling);
            } else if ($root && $root.parentNode) {
                $root.parentNode.insertBefore(host, $root);
            } else {
                return;
            }
        }
        if (!count || !tied) {
            host.innerHTML = '';
            return;
        }
        const sev = tied > 25000 ? 'high' : (tied > 10000 ? 'med' : 'low');
        host.innerHTML = `
            <div class="ica-frozen-insight ica-frozen-insight-${sev}">
                <div class="ica-frozen-head">
                    <span><strong>$${tied.toLocaleString('en-US', {maximumFractionDigits:0})}</strong> tied up in frozen inventory</span>
                    <span class="text-muted small">${count} items haven't sold in ${days}+ days · ${sev === 'high' ? 'review before reordering anything from chart picks' : (sev === 'med' ? 'cross-check before adding more chart titles' : 'OK for now')}</span>
                </div>
                <div class="ica-frozen-cta">
                    <a href="#" class="ica-jump-frozen">Jump to Frozen list ↓</a>
                </div>
            </div>`;
        const jumpLink = host.querySelector('.ica-jump-frozen');
        if (jumpLink) {
            jumpLink.addEventListener('click', function (e) {
                e.preventDefault();
                const frozen = $root.querySelector('.ica-bucket[data-bucket="frozen_inventory"]');
                if (frozen) {
                    // If the secondary disclosure is closed, open it first.
                    const disc = frozen.closest('details.ica-secondary-disclosure');
                    if (disc && !disc.open) disc.open = true;
                    frozen.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        }
    }

    function applyAbcTags(abcMap) {
        if (!abcMap || typeof abcMap !== 'object') return;
        $root.querySelectorAll('tr[data-pid]').forEach((tr) => {
            const pid = tr.getAttribute('data-pid');
            if (!pid) return;
            const cls = abcMap[pid];
            if (!cls) return;
            const cell = tr.querySelector('.ica-abc-col');
            if (cell) {
                cell.innerHTML = `<span class="ica-abc-cell ica-abc-${cls}">${cls}</span>`;
            }
            tr.setAttribute('data-abc', cls);
        });
        // Once rows have data-abc populated, the ABC filter dropdown
        // becomes useful — repopulate options + re-apply current filter.
        rebuildFilterOptions();
        applyRowFilters();
    }

    function attachFrozenStockEditors() {
        const frozenBucket = $root.querySelector('.ica-bucket[data-bucket="frozen_inventory"]');
        if (!frozenBucket) return;
        frozenBucket.querySelectorAll('.ica-frozen-edit-btn').forEach((btn) => {
            btn.addEventListener('click', function () {
                const tr = btn.closest('tr');
                if (!tr) return;
                const vid = btn.dataset.vid;
                const lid = btn.dataset.lid;
                const current = btn.dataset.current || '0';
                const next = prompt('Update actual on-shelf stock for this item.\nEnter the real qty you counted (or 0 if it\'s gone):', current);
                if (next === null) return;
                const newQty = Number(next);
                if (!Number.isFinite(newQty) || newQty < 0) {
                    alert('Enter a number ≥ 0.');
                    return;
                }
                const note = prompt('Optional reason (e.g. "sold on Discogs", "miscounted"):', '') || '';
                btn.disabled = true; btn.textContent = 'Saving…';
                const fd = new FormData();
                fd.append('variation_id', vid);
                fd.append('location_id', lid);
                fd.append('new_qty', String(newQty));
                if (note) fd.append('note', note);
                fetch(window.ICA_FROZEN_UPDATE_URL, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ICA_CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    body: fd,
                })
                    .then((r) => r.json())
                    .then((resp) => {
                        if (!resp || !resp.success) {
                            btn.disabled = false; btn.textContent = 'Set stock';
                            alert('Save failed: ' + (resp && resp.error ? resp.error : 'unknown'));
                            return;
                        }
                        // If new stock = 0 the row no longer qualifies as
                        // frozen, drop it. Otherwise update the displayed
                        // stock + last-update strip and clear the button.
                        if (newQty === 0) {
                            tr.style.transition = 'opacity .4s';
                            tr.style.opacity = '0';
                            setTimeout(() => tr.remove(), 400);
                        } else {
                            const stockCell = tr.querySelector('.ica-stock-col');
                            if (stockCell) stockCell.textContent = newQty;
                            btn.dataset.current = String(newQty);
                            btn.disabled = false; btn.textContent = 'Set stock';
                            const whenIso = new Date().toISOString().substring(0, 10);
                            const whenDisplay = formatDateMDY(whenIso);
                            const userName = (resp.entry && resp.entry.user_name) || 'you';
                            const reasonCell = tr.querySelector('.ica-reason-col small');
                            if (reasonCell) {
                                reasonCell.innerHTML += ` <span class="ica-last-order">· updated to ${newQty} on ${whenDisplay} by ${escapeHtml(userName)}</span>`;
                            }
                            const updatedCell = tr.querySelector('.ica-updated-col');
                            if (updatedCell) {
                                updatedCell.setAttribute('data-updated', whenIso);
                                updatedCell.innerHTML = `<small>${whenDisplay}</small>`;
                            }
                        }
                    })
                    .catch((err) => {
                        console.error('[ICA] frozen stock update failed', err);
                        btn.disabled = false; btn.textContent = 'Set stock';
                        alert('Save failed — see console.');
                    });
            });
        });
    }

    function applyFrozenDupeTags(frozenItems) {
        if (!frozenItems || !frozenItems.length) return;
        const frozenVids = new Set(frozenItems.map((it) => parseInt(it.variation_id, 10)).filter(Boolean));
        if (!frozenVids.size) return;
        $root.querySelectorAll('.ica-bucket').forEach((bucketEl) => {
            const bkey = bucketEl.getAttribute('data-bucket');
            if (bkey === 'frozen_inventory') return;
            bucketEl.querySelectorAll('tr[data-row-key]').forEach((tr) => {
                const key = tr.getAttribute('data-row-key') || '';
                const vid = parseInt(key.split('|')[1] || 0, 10);
                if (vid && frozenVids.has(vid)) {
                    const tagsCell = tr.children[tr.children.length - 3]; // qty is -2, extra -1
                    if (tagsCell && !tagsCell.querySelector('.ica-tag.frozen_dupe')) {
                        tagsCell.insertAdjacentHTML('beforeend', '<span class="ica-tag frozen_dupe" title="Also sitting frozen at this location — think twice before reordering">frozen dupe</span>');
                    }
                }
            });
        });
    }

    function lazyLoadSecondaryBuckets() {
        if (!window.ICA_SECONDARY_URL) return;
        const params = new URLSearchParams();
        if ($location && $location.value) params.append('location_id', $location.value);
        if ($preset && $preset.value) params.append('preset', $preset.value);

        const SECONDARY_KEYS = ['street_pulse', 'universal_top', 'apple_music_top', 'top_artist_new_releases', 'long_oos_essentials', 'hot_used_oos'];

        // Surface a stuck-load warning so Sarah doesn't stare at
        // "Loading…" forever (2026-05-20).
        const stuckTimer = setTimeout(() => {
            SECONDARY_KEYS.forEach((k) => {
                const el = $root.querySelector('.ica-bucket[data-bucket="' + k + '"] .ica-why');
                if (el && el.textContent.indexOf('Loading') === 0) {
                    el.textContent = 'Still loading… first build can take 20-40s. Re-clicks are instant.';
                }
            });
        }, 8000);

        fetch(window.ICA_SECONDARY_URL + '?' + params.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': window.ICA_CSRF || '' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((resp) => {
                clearTimeout(stuckTimer);
                if (!resp || !resp.buckets) {
                    // Surface a clear error on every placeholder instead of leaving "Loading…"
                    SECONDARY_KEYS.forEach((k) => paintBucketError(k, 'Empty response from server'));
                    return;
                }
                Object.keys(resp.buckets).forEach((key) => {
                    const bucket = resp.buckets[key];
                    if (lastResult && lastResult.buckets) {
                        lastResult.buckets[key] = bucket;
                    }
                    const existing = $root.querySelector('.ica-bucket[data-bucket="' + key + '"]');
                    if (!existing) return;
                    const html = renderBucketSection(key, bucket);
                    const tmp = document.createElement('div');
                    tmp.innerHTML = html;
                    const fresh = tmp.firstElementChild;
                    if (fresh) existing.replaceWith(fresh);
                });
                attachBucketHandlers();
                rebuildFilterOptions();
                applyRowFilters();
                renderBucketTotals();
            })
            .catch((err) => {
                clearTimeout(stuckTimer);
                console.error('[ICA] secondary buckets lazy-load failed', err);
                SECONDARY_KEYS.forEach((k) => paintBucketError(k, (err && err.message) || 'network error'));
            });
    }

    function paintBucketError(bucketKey, msg) {
        const el = $root.querySelector('.ica-bucket[data-bucket="' + bucketKey + '"] .ica-why');
        if (el) el.textContent = 'Failed to load: ' + msg + ' — open the browser console (F12) for details.';
    }

    function lazyLoadEventsBucket() {
        const params = new URLSearchParams();
        if ($location && $location.value) params.append('location_id', $location.value);
        if ($preset && $preset.value) params.append('preset', $preset.value);
        const url = window.ICA_EVENTS_URL || (window.ICA_BUCKETS_URL.replace('/buckets', '/events-bucket'));

        const stuckTimer = setTimeout(() => {
            const el = $root.querySelector('.ica-bucket[data-bucket="events_upcoming"] .ica-why');
            if (el && el.textContent.indexOf('Loading') === 0) {
                el.textContent = 'Still loading… events feed can take 10-30s on cold cache.';
            }
        }, 8000);

        fetch(url + '?' + params.toString(), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': window.ICA_CSRF || '' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((resp) => {
                clearTimeout(stuckTimer);
                if (!resp || !resp.bucket) {
                    paintBucketError('events_upcoming', 'empty response');
                    return;
                }
                if (lastResult && lastResult.buckets) {
                    lastResult.buckets.events_upcoming = resp.bucket;
                }
                // Replace the placeholder events section in the DOM with the real one.
                const existing = $root.querySelector('.ica-bucket[data-bucket="events_upcoming"]');
                if (existing) {
                    const html = renderBucketSection('events_upcoming', resp.bucket);
                    const tmp = document.createElement('div');
                    tmp.innerHTML = html;
                    const fresh = tmp.firstElementChild;
                    if (fresh) {
                        existing.replaceWith(fresh);
                        attachBucketHandlers();
                    }
                    rebuildFilterOptions();
                    applyRowFilters();
                }
            })
            .catch((err) => {
                clearTimeout(stuckTimer);
                console.error('[ICA] events lazy-load failed', err);
                paintBucketError('events_upcoming', (err && err.message) || 'network error');
            });
    }

    function renderBuckets(payload) {
        if (payload.meta && payload.meta.error === 'location_required') {
            $root.innerHTML = '<div class="alert alert-warning"><strong>Pick a location first.</strong> The store-button preset didn\'t resolve to a location_id. Open Advanced filters and pick one manually.</div>';
            return;
        }
        if (payload.meta && payload.meta.error === 'build_failed') {
            // Server caught an exception in buildBuckets — surface the
            // actual message so we can fix the underlying issue instead
            // of staring at "0 items".
            $root.innerHTML = '<div class="alert alert-danger"><strong>Build failed on the server.</strong><br><br>'
                + '<code>' + escapeHtml(payload.meta.message || 'unknown') + '</code><br><small>'
                + escapeHtml(payload.meta.file || '') + '</small><br><br>'
                + 'Send Sarah/Claude this message and the page will get fixed.</div>';
            return;
        }

        // Per Sarah 2026-05-20: the wall of buckets was overwhelming. Default
        // view = just fast_oos (Jon's focus). Everything else lives behind a
        // single "Show all the other reorder lists" disclosure so it's one
        // click away when needed but not in the face on landing.
        const primary = ['fast_oos'];
        const secondary = ['manager_picks', 'ume_spotlights', 'customer_wants', 'street_pulse', 'universal_top', 'apple_music_top', 'top_artist_new_releases', 'events_upcoming', 'abc_a_restock', 'long_oos_essentials', 'hot_used_oos', 'frozen_inventory'];
        const buckets = payload.buckets || {};

        let primaryHtml = '';
        let secondaryHtml = '';
        let totalItems = 0;
        let totalQty = 0;
        let secondaryItems = 0;

        primary.forEach((key) => {
            const b = buckets[key];
            if (!b) return;
            primaryHtml += renderBucketSection(key, b);
            totalItems += b.count || 0;
            (b.items || []).forEach((it) => { totalQty += parseInt(it.suggested_qty || 0, 10) || 0; });
        });
        secondary.forEach((key) => {
            const b = buckets[key];
            if (!b) return;
            secondaryHtml += renderBucketSection(key, b);
            totalItems += b.count || 0;
            secondaryItems += b.count || 0;
            (b.items || []).forEach((it) => { totalQty += parseInt(it.suggested_qty || 0, 10) || 0; });
        });

        let html = primaryHtml;
        if (secondaryHtml !== '') {
            html += '<details class="ica-secondary-disclosure">'
                + '<summary><strong>Show all the other reorder lists</strong> '
                + '<small class="text-muted">(charts, events, ABC, frozen, customer wants — <span id="ica_secondary_count">' + secondaryItems + '</span> more items)</small></summary>'
                + '<div class="ica-secondary-buckets">' + secondaryHtml + '</div>'
                + '</details>';
        }

        if (html === '') {
            const metaJson = payload.meta ? JSON.stringify(payload.meta, null, 2) : '(no meta)';
            html = '<div class="alert alert-warning"><strong>Server returned no buckets.</strong> Usually means the location preset didn\'t resolve. Server meta:<pre style="margin-top:8px; font-size:11px;">' + escapeHtml(metaJson) + '</pre></div>';
        }

        $root.innerHTML = html;
        $summary.textContent = `${totalItems} items · ${totalQty} total qty suggested · fast sellers <90d: ${(buckets.fast_oos && buckets.fast_oos.count) || 0}`;

        attachBucketHandlers();
        rebuildFilterOptions();
        applyRowFilters();
        renderBucketTotals();
        // Live-update totals when the user tweaks qty or toggles checkboxes.
        $root.addEventListener('input', (e) => {
            if (e.target && e.target.classList.contains('ica-qty-input')) renderBucketTotals();
        });
        $root.addEventListener('change', (e) => {
            if (e.target && e.target.classList.contains('ica-row-check')) renderBucketTotals();
            if (e.target && e.target.classList.contains('ica-select-all')) renderBucketTotals();
        });
    }

    // ── Category + Genre filter (client-side row hiding) ─────────────
    function rebuildFilterOptions() {
        const cats = new Set();
        const genres = new Set();
        if (lastResult && lastResult.buckets) {
            Object.values(lastResult.buckets).forEach((b) => {
                (b.items || []).forEach((it) => {
                    if (it.category_name) cats.add(it.category_name);
                    if (it.genre) genres.add(it.genre);
                });
            });
        }
        const $cat = document.getElementById('ica_filter_category');
        const $gen = document.getElementById('ica_filter_genre');
        if ($cat) {
            const prev = $cat.value;
            $cat.innerHTML = '<option value="">All</option>' + Array.from(cats).sort().map((c) => `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('');
            if (Array.from(cats).includes(prev)) $cat.value = prev;
        }
        if ($gen) {
            const prev = $gen.value;
            $gen.innerHTML = '<option value="">All</option>' + Array.from(genres).sort().map((g) => `<option value="${escapeHtml(g)}">${escapeHtml(g)}</option>`).join('');
            if (Array.from(genres).includes(prev)) $gen.value = prev;
        }
    }

    /**
     * Walk every bucket, sum (qty × cost) across visible + checked rows,
     * and write a "Total cost" footer onto each bucket header + a grand
     * total into the export-strip summary so Sarah can budget the order.
     */
    function renderBucketTotals() {
        let grandQty = 0;
        let grandCost = 0;
        $root.querySelectorAll('.ica-bucket').forEach((bucketEl) => {
            let bQty = 0;
            let bCost = 0;
            bucketEl.querySelectorAll('tr[data-row-key]').forEach((tr) => {
                if (tr.style.display === 'none') return;
                const checkbox = tr.querySelector('.ica-row-check');
                if (checkbox && !checkbox.checked) return;
                const qtyInput = tr.querySelector('.ica-qty-input');
                const q = qtyInput ? (parseInt(qtyInput.value, 10) || 0) : 0;
                const c = parseFloat(tr.getAttribute('data-cost') || '0') || 0;
                bQty += q;
                bCost += q * c;
            });
            grandQty += bQty;
            grandCost += bCost;
            const header = bucketEl.querySelector('.ica-bucket-header > div:first-child');
            if (!header) return;
            let totalEl = header.querySelector('.ica-bucket-total');
            if (!totalEl) {
                totalEl = document.createElement('div');
                totalEl.className = 'ica-bucket-total';
                header.appendChild(totalEl);
            }
            totalEl.textContent = bQty
                ? `Total cost: $${grandFormat(bCost)} · ${bQty} units`
                : '';
        });
        const $sum = document.getElementById('ica_summary');
        if ($sum) {
            $sum.innerHTML = `<span><strong>${grandQty}</strong> units</span> · <span>order cost <strong>$${grandFormat(grandCost)}</strong></span>`;
        }
    }
    function grandFormat(n) {
        return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    function applyRowFilters() {
        const $cat = document.getElementById('ica_filter_category');
        const $gen = document.getElementById('ica_filter_genre');
        const $abc = document.getElementById('ica_filter_abc');
        const $rsd = document.getElementById('ica_filter_hide_rsd');
        const cat = $cat ? $cat.value : '';
        const gen = $gen ? $gen.value : '';
        const abc = $abc ? $abc.value : '';
        const hideRsd = $rsd ? $rsd.checked : false;
        $root.querySelectorAll('.ica-bucket').forEach((bucketEl) => {
            // Frozen bucket can carry its own Category / Genre filter on
            // top of the global ones — selects live in the bucket header.
            const frozenCat = bucketEl.getAttribute('data-frozen-cat') || '';
            const frozenGen = bucketEl.getAttribute('data-frozen-gen') || '';
            let visible = 0;
            bucketEl.querySelectorAll('tr[data-row-key]').forEach((tr) => {
                const rowCat = tr.getAttribute('data-cat') || '';
                const rowGen = tr.getAttribute('data-genre') || '';
                const rowAbc = tr.getAttribute('data-abc') || '';
                const rowRsd = tr.getAttribute('data-rsd') === '1';
                const match = (!cat || rowCat === cat)
                    && (!gen || rowGen === gen)
                    && (!abc || rowAbc === abc)
                    && (!hideRsd || !rowRsd)
                    && (!frozenCat || rowCat === frozenCat)
                    && (!frozenGen || rowGen === frozenGen);
                tr.style.display = match ? '' : 'none';
                if (match) visible++;
            });
            // After filter changes, recompute the total cost so it reflects
            // only visible rows.
            // (deferred — single call after the bucket loop below)
            // Update the bucket count pill to reflect filtered visibility
            const countEl = bucketEl.querySelector('.ica-bucket-count');
            if (countEl) {
                const original = countEl.dataset.original || countEl.textContent;
                countEl.dataset.original = original;
                if (cat || gen) {
                    countEl.textContent = visible + ' / ' + original;
                    countEl.classList.toggle('zero', visible === 0);
                } else {
                    countEl.textContent = original;
                }
            }
        });
        renderBucketTotals();
    }

    ['ica_filter_category', 'ica_filter_genre', 'ica_filter_abc', 'ica_filter_hide_rsd'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', applyRowFilters);
    });

    function renderBucketSection(key, b) {
        const countClass = (b.count || 0) === 0 ? 'zero' : '';
        const rows = (b.items || []).map((it) => renderRow(key, it)).join('');
        const isFrozen = key === 'frozen_inventory';
        const sortable = (label, type, title) => {
            const t = title ? ` title="${escapeHtml(title)}"` : '';
            return `<th class="ica-sortable" data-sort-type="${type}"${t}>${escapeHtml(label)}<span class="ica-sort-ind"></span></th>`;
        };
        // Category + Genre columns added 2026-05-20 so the page can be
        // filtered by either. The two new dropdowns above the buckets
        // drive client-side row hiding. Sortable headers + the frozen-only
        // Last-updated / Price columns added 2026-05-24.
        const headParts = [
            `<th><input type="checkbox" class="ica-select-all" data-bucket="${escapeHtml(key)}"></th>`,
            sortable('Product', 'text'),
            sortable('Artist', 'text'),
            sortable('Format', 'text'),
            sortable('Bin', 'text'),
            sortable('Category', 'text'),
            sortable('Genre', 'text'),
            sortable('ABC', 'text', 'ABC class — A is the top 80% of inventory value'),
            sortable('Stock', 'number'),
            sortable('Sold (window)', 'number'),
            sortable('Cost', 'number', 'Per-unit purchase price (variations.dpp_inc_tax) — what we paid for one unit.'),
        ];
        if (isFrozen) {
            headParts.push(sortable('Price', 'number', 'Retail sell price (from product_stock_cache.unit_price).'));
            headParts.push(sortable('Added', 'date', 'When this product was first added to the system (products.created_at).'));
            headParts.push(sortable('Last edited', 'date', 'Most recent edit to the product / variation record. NOT the last-sold date — frozen status is based on no SALE in N days, regardless of when the record was last edited.'));
        }
        headParts.push(sortable('Reason', 'text'));
        headParts.push(sortable('Tags', 'text'));
        headParts.push(sortable('Qty', 'qty'));
        headParts.push('<th></th>');
        const headRow = headParts.join('');

        // Frozen bucket gets an inline filter strip in its header — days
        // threshold (re-fetches with ?days=) plus Category / Genre selects
        // scoped to just the frozen list. The page-level filter row above
        // applies globally; these stack on top so Sarah can drill into
        // frozen without affecting other buckets.
        let frozenControls = '';
        if (isFrozen) {
            const days = parseInt(b.frozen_days || 180, 10);
            const presetVals = [90, 120, 180, 365];
            const opts = presetVals.map((v) => `<option value="${v}" ${v === days ? 'selected' : ''}>${v} days</option>`).join('');
            const isCustom = !presetVals.includes(days);

            // Pull unique categories + genres from this bucket's items.
            // Re-selection across re-renders is preserved by reading the
            // previous values off the existing bucket DOM (if present).
            const prevBucket = $root && $root.querySelector('.ica-bucket[data-bucket="frozen_inventory"]');
            const prevCat = prevBucket ? (prevBucket.getAttribute('data-frozen-cat') || '') : '';
            const prevGen = prevBucket ? (prevBucket.getAttribute('data-frozen-gen') || '') : '';
            const cats = new Set();
            const gens = new Set();
            (b.items || []).forEach((it) => {
                if (it.category_name) cats.add(it.category_name);
                if (it.genre) gens.add(it.genre);
            });
            const catOpts = '<option value="">All</option>' + Array.from(cats).sort().map((c) => `<option value="${escapeHtml(c)}" ${c === prevCat ? 'selected' : ''}>${escapeHtml(c)}</option>`).join('');
            const genOpts = '<option value="">All</option>' + Array.from(gens).sort().map((g) => `<option value="${escapeHtml(g)}" ${g === prevGen ? 'selected' : ''}>${escapeHtml(g)}</option>`).join('');

            frozenControls = `
                <div class="ica-frozen-controls">
                    <label class="ica-filter-label">Frozen if no sale in</label>
                    <select class="ica-frozen-days-select form-control input-sm">${opts}<option value="custom" ${isCustom ? 'selected' : ''}>Custom…</option></select>
                    <input type="number" class="form-control input-sm ica-frozen-days-custom" min="7" max="3650" value="${days}" style="${isCustom ? '' : 'display:none;'}" placeholder="days">
                    <label class="ica-filter-label">Category</label>
                    <select class="ica-frozen-cat-filter form-control input-sm">${catOpts}</select>
                    <label class="ica-filter-label">Genre</label>
                    <select class="ica-frozen-gen-filter form-control input-sm">${genOpts}</select>
                </div>`;
        }
        const body = (b.count || 0) === 0
            ? `<div class="ica-bucket-empty">No items in this bucket${b.empty_reason ? ' (' + b.empty_reason.replace(/_/g, ' ') + ')' : ''}.</div>`
            : `<table class="table table-condensed table-striped ica-row-table"><thead><tr>${headRow}</tr></thead><tbody>${rows}</tbody></table>`;

        // Frozen-scoped filter values, if any, must persist across re-renders.
        // Read off the prior bucket DOM (or its selects) and re-emit as data attrs.
        let bucketDataAttrs = '';
        if (isFrozen) {
            const prevBucket = $root && $root.querySelector('.ica-bucket[data-bucket="frozen_inventory"]');
            const prevCat = prevBucket ? (prevBucket.getAttribute('data-frozen-cat') || '') : '';
            const prevGen = prevBucket ? (prevBucket.getAttribute('data-frozen-gen') || '') : '';
            if (prevCat) bucketDataAttrs += ` data-frozen-cat="${escapeHtml(prevCat)}"`;
            if (prevGen) bucketDataAttrs += ` data-frozen-gen="${escapeHtml(prevGen)}"`;
        }

        return `
            <div class="ica-bucket box box-default" data-bucket="${escapeHtml(key)}"${bucketDataAttrs}>
                <div class="ica-bucket-header">
                    <div>
                        <h3>${escapeHtml(b.label || key)} <span class="ica-bucket-count ${countClass}">${b.count || 0}</span></h3>
                        <span class="ica-why">${escapeHtml(b.why || '')}</span>
                        ${frozenControls}
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
        // Build the tag list. ABC class always gets pulled out and rendered
        // first (it's the highest-signal pill); other tags follow.
        const rawTags = it.tags || [];
        const abcTag = rawTags.find((t) => t === 'abc_A' || t === 'abc_B' || t === 'abc_C');
        const nonAbcTags = rawTags.filter((t) => t !== abcTag);
        const tagsHtml = nonAbcTags.map((t) => `<span class="ica-tag ${escapeHtml(t)}">${escapeHtml(t.replace(/_/g, ' '))}</span>`).join('');
        const abcHtml = abcTag
            ? `<span class="ica-tag ${escapeHtml(abcTag)}" title="ABC class ${abcTag.replace('abc_', '')}">${escapeHtml(abcTag.replace('abc_', ''))}</span>`
            : '';
        const reason = escapeHtml(it.reason || '');
        const product = escapeHtml(it.product || '—');
        const artist = escapeHtml(it.artist || '—');
        const format = escapeHtml(it.format || '');
        const category = escapeHtml(it.category_name || '');
        const genre = escapeHtml(it.genre || '');
        const bin = escapeHtml(it.bin_position || '');
        const isRsd = !!it.is_rsd;
        const qty = parseInt(it.suggested_qty || 0, 10) || 0;
        const rowKey = [bucket, it.variation_id || '', it.customer_want_id || '', it.artist || '', it.product || ''].join('|');

        let extraCol = '';
        if (bucket === 'customer_wants' && it.customer_want_id) {
            extraCol = `<button type="button" class="btn btn-xs btn-success ica-fulfill-want" data-want-id="${it.customer_want_id}"><i class="fa fa-check"></i> Fulfilled</button>`;
        } else if (bucket === 'events_upcoming' && it.event_name) {
            extraCol = `<small class="text-muted">${escapeHtml(it.event_name)} — ${escapeHtml(it.event_date)}</small>`;
        } else if (bucket === 'ume_spotlights') {
            const date = escapeHtml(it.release_date_label || it.release_date || '');
            extraCol = date ? `<small class="text-muted ica-spot-date">Release: ${date}</small>` : '';
        } else if (bucket === 'frozen_inventory' && it.variation_id) {
            extraCol = `<button type="button" class="btn btn-xs btn-default ica-frozen-edit-btn" data-vid="${it.variation_id}" data-lid="${it.location_id || ''}" data-current="${it.stock}">Set stock</button>`;
        }

        // Frozen rows also show "updated mm/dd/yy by Name" on the reason
        // line when there's a logged in-place correction.
        let reasonExtra = '';
        if (bucket === 'frozen_inventory' && it.last_correction && it.last_correction.when) {
            const whenIso = String(it.last_correction.when).substring(0, 10);
            const whenDisplay = formatDateMDY(whenIso);
            const by = it.last_correction.by || '';
            reasonExtra = ` <span class="ica-last-order">· updated to ${it.last_correction.after} on ${escapeHtml(whenDisplay)} by ${escapeHtml(by)}</span>`;
        }

        // Frozen rows are a warning list — qty stays 0, checkbox starts
        // unchecked, qty input disabled so a careless export can't bulk-
        // reorder dead stock.
        const isFrozen = bucket === 'frozen_inventory';
        const checkboxAttrs = isFrozen ? '' : 'checked';
        const qtyDisabled = isFrozen ? 'disabled' : '';

        // data-cat / data-genre / data-abc attrs power the client-side
        // filter. data-pid lets the ABC sweep populate the ABC cell on
        // rows in other buckets once the lazy ABC bucket arrives.
        const pid = parseInt(it.product_id || 0, 10) || '';
        // Some buckets ship with their own abc tag (e.g. abc_a_restock
        // tags every row 'abc_A'); use that as the initial ABC cell.
        const initialAbc = abcTag ? abcTag.replace('abc_', '') : '';
        const abcCell = initialAbc ? `<span class="ica-abc-cell ica-abc-${initialAbc}">${initialAbc}</span>` : '—';
        // is_rsd flag flows from the server — RSD titles can be hidden
        // via the new "Hide RSD titles" checkbox above the buckets.
        let productCell = isRsd ? `${product} <span class="ica-tag ica-rsd-tag" title="Record Store Day release">RSD</span>` : product;
        // Best-supplier price badge — appears on rows where any uploaded
        // supplier feed had a matching (artist, title) row. Shows the
        // cheapest cost + which supplier had it.
        if (it.best_supplier && it.best_supplier.cost) {
            const bs = it.best_supplier;
            productCell += ` <span class="ica-tag ica-supplier-best" title="Cheapest match across uploaded supplier feeds">$${Number(bs.cost).toFixed(2)} via ${escapeHtml(bs.supplier_label || bs.supplier_key)}</span>`;
        }
        // Cost is the wholesale / default_purchase_price per unit.
        // data-cost holds the numeric value so renderBucketTotals can sum
        // "qty × cost" across visible rows.
        const costNum = (typeof it.cost_price === 'number' && !isNaN(it.cost_price)) ? it.cost_price : null;
        const costCell = costNum !== null ? `$${costNum.toFixed(2)}` : '—';

        // Frozen rows: product cell links to the full /products/view/{id}
        // page so a click opens the title with all details (sales history,
        // variations, suppliers). Also adds Price / Added / Last edited
        // columns so Sarah can spot bad-data rows ($300 mugs etc.) and
        // tell whether a stale-looking item is genuinely old or just
        // recently re-imported.
        let priceCellHtml = '';
        let createdCellHtml = '';
        let updatedCellHtml = '';
        if (isFrozen) {
            if (it.product_id && window.ICA_PRODUCT_VIEW_URL_BASE) {
                const href = window.ICA_PRODUCT_VIEW_URL_BASE + '/' + encodeURIComponent(it.product_id);
                productCell = `<a href="${href}" target="_blank" rel="noopener" class="ica-product-link" title="Open full product details">${productCell}</a>`;
            }
            const sellNum = (typeof it.sell_price === 'number' && !isNaN(it.sell_price)) ? it.sell_price : null;
            const priceTxt = sellNum !== null ? `$${sellNum.toFixed(2)}` : '—';
            priceCellHtml = `<td class="ica-price-col" data-price="${sellNum !== null ? sellNum : ''}">${priceTxt}</td>`;
            const created = it.created_at || '';
            const createdDisplay = created ? formatDateMDY(created) : '—';
            createdCellHtml = `<td class="ica-created-col" data-created="${escapeHtml(created)}"><small>${escapeHtml(createdDisplay)}</small></td>`;
            const updated = it.last_updated_at || '';
            const updatedDisplay = updated ? formatDateMDY(updated) : '—';
            updatedCellHtml = `<td class="ica-updated-col" data-updated="${escapeHtml(updated)}"><small>${escapeHtml(updatedDisplay)}</small></td>`;
        }

        return `<tr data-row-key="${escapeHtml(rowKey)}" data-pid="${pid}" data-cat="${category}" data-genre="${genre}" data-abc="${initialAbc}" data-rsd="${isRsd ? '1' : '0'}" data-cost="${costNum !== null ? costNum : ''}">
            <td><input type="checkbox" class="ica-row-check" ${checkboxAttrs}></td>
            <td>${productCell}</td>
            <td>${artist}</td>
            <td>${format}</td>
            <td><small>${bin || '—'}</small></td>
            <td><small>${category || '—'}</small></td>
            <td><small>${genre || '—'}</small></td>
            <td class="ica-abc-col">${abcCell}</td>
            <td class="ica-stock-col">${stock}</td>
            <td>${sold}</td>
            <td class="ica-cost-col">${costCell}</td>
            ${priceCellHtml}
            ${createdCellHtml}
            ${updatedCellHtml}
            <td class="ica-reason-col"><small>${reason}${reasonExtra}</small></td>
            <td>${tagsHtml}</td>
            <td><input type="number" class="form-control input-sm ica-qty-input" value="${qty}" min="0" max="99" ${qtyDisabled}></td>
            <td>${extraCol}</td>
        </tr>`;
    }

    // Generic per-bucket column sort. Click a sortable <th> to sort that
    // bucket's tbody by the column under it; click again to reverse.
    function sortBucketTable(th) {
        const table = th.closest('table');
        if (!table) return;
        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        const headerRow = th.parentElement;
        const colIndex = Array.prototype.indexOf.call(headerRow.children, th);
        if (colIndex < 0) return;
        const sortType = th.getAttribute('data-sort-type') || 'text';
        const curDir = th.getAttribute('data-sort-dir');
        const newDir = curDir === 'asc' ? 'desc' : 'asc';

        headerRow.querySelectorAll('th.ica-sortable').forEach((other) => {
            other.removeAttribute('data-sort-dir');
            const ind = other.querySelector('.ica-sort-ind');
            if (ind) ind.textContent = '';
        });
        th.setAttribute('data-sort-dir', newDir);
        const ind = th.querySelector('.ica-sort-ind');
        if (ind) ind.textContent = newDir === 'asc' ? ' ▲' : ' ▼';

        const rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        const factor = newDir === 'asc' ? 1 : -1;
        rows.sort((a, b) => {
            const av = sortValueFor(a.children[colIndex], sortType);
            const bv = sortValueFor(b.children[colIndex], sortType);
            if (av < bv) return -1 * factor;
            if (av > bv) return 1 * factor;
            return 0;
        });
        const frag = document.createDocumentFragment();
        rows.forEach((r) => frag.appendChild(r));
        tbody.appendChild(frag);
    }

    function sortValueFor(td, type) {
        if (!td) return type === 'number' ? -Infinity : '';
        if (type === 'number') {
            const text = td.textContent.replace(/[^0-9.\-]/g, '');
            if (text === '' || text === '-' || text === '.') return -Infinity;
            const n = parseFloat(text);
            return isNaN(n) ? -Infinity : n;
        }
        if (type === 'qty') {
            const input = td.querySelector('input');
            return input ? (parseFloat(input.value) || 0) : 0;
        }
        if (type === 'date') {
            // Sort by the underlying ISO date kept in data-updated / data-created;
            // displayed text is mm/dd/yy which doesn't sort lexicographically.
            const v = td.getAttribute('data-updated') || td.getAttribute('data-created') || '';
            return v;
        }
        return td.textContent.trim().toLowerCase();
    }

    // ISO ("YYYY-MM-DD") → "mm/dd/yy". Returns empty string for falsy input.
    function formatDateMDY(iso) {
        if (!iso) return '';
        const m = String(iso).match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (!m) return iso;
        return m[2] + '/' + m[3] + '/' + m[1].substring(2);
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

        $root.querySelectorAll('th.ica-sortable').forEach((th) => {
            th.addEventListener('click', function () {
                sortBucketTable(th);
            });
        });

        // Frozen bucket: days-threshold control. Re-fetch the bucket with
        // ?days= when the user picks a preset or types a custom value.
        $root.querySelectorAll('.ica-frozen-days-select').forEach((sel) => {
            sel.addEventListener('change', function () {
                const wrap = sel.closest('.ica-frozen-controls');
                const custom = wrap ? wrap.querySelector('.ica-frozen-days-custom') : null;
                if (sel.value === 'custom') {
                    if (custom) {
                        custom.style.display = '';
                        custom.focus();
                    }
                } else {
                    if (custom) custom.style.display = 'none';
                    refetchFrozenBucket(parseInt(sel.value, 10));
                }
            });
        });
        $root.querySelectorAll('.ica-frozen-days-custom').forEach((inp) => {
            inp.addEventListener('change', function () {
                const v = parseInt(inp.value, 10);
                if (v && v >= 7 && v <= 3650) refetchFrozenBucket(v);
            });
        });

        // Frozen-scoped Category / Genre filters — write the choice onto
        // the bucket element so applyRowFilters can pick it up.
        $root.querySelectorAll('.ica-frozen-cat-filter, .ica-frozen-gen-filter').forEach((sel) => {
            sel.addEventListener('change', function () {
                const bucketEl = sel.closest('.ica-bucket');
                if (!bucketEl) return;
                if (sel.classList.contains('ica-frozen-cat-filter')) {
                    bucketEl.setAttribute('data-frozen-cat', sel.value || '');
                } else {
                    bucketEl.setAttribute('data-frozen-gen', sel.value || '');
                }
                applyRowFilters();
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

    // ── Manager picks admin (More options panel) ─────────────────────
    function renderManagerPicksList(picks) {
        const list = document.getElementById('ica_mgrpicks_list');
        if (!list) return;
        const active = (picks || []).filter((p) => !p.dismissed);
        if (!active.length) {
            list.innerHTML = '<p class="text-muted small">No active picks. Add one below to surface candidates in the Manager picks bucket.</p>';
            return;
        }
        list.innerHTML = active.map((p) => `
            <div class="ica-mgrpick-item">
                <div class="ica-mgrpick-meta">
                    <span class="ica-mgrpick-by">${escapeHtml(p.suggested_by || 'Manager')}:</span>
                    <span>${escapeHtml(p.note || '')}</span>
                    ${p.category_pattern ? `<span class="ica-mgrpick-cat">[${escapeHtml(p.category_pattern)}]</span>` : ''}
                </div>
                <button type="button" class="btn btn-xs btn-default ica-mgrpick-dismiss" data-pick-id="${escapeHtml(p.id || '')}" title="Mark done — removes from the bucket">
                    <i class="fa fa-check"></i> Done
                </button>
            </div>
        `).join('');
        list.querySelectorAll('.ica-mgrpick-dismiss').forEach((btn) => {
            btn.addEventListener('click', function () {
                const id = btn.dataset.pickId;
                if (!id) return;
                if (!confirm('Mark this manager pick as done? It will stop surfacing in the bucket.')) return;
                fetch(window.ICA_MGRPICKS_DISMISS_URL + '/' + encodeURIComponent(id) + '/dismiss', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ICA_CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                })
                    .then((r) => r.json())
                    .then((resp) => {
                        if (resp && resp.success) renderManagerPicksList(resp.picks);
                        // Reload the bucket so the row drops out
                        if (typeof lazyLoadAuxBucket === 'function' && window.ICA_MGRPICKS_BUCKET_URL) {
                            lazyLoadAuxBucket('manager_picks', window.ICA_MGRPICKS_BUCKET_URL);
                        }
                    });
            });
        });
    }

    // ── Supplier price feeds (AMS / Alliance / Secretly / Beggars / Red Eye / VP) ──
    // Sarah 2026-05-20: she doesn't have xlsx files from suppliers — she
    // looks prices up on each supplier's site one-at-a-time. UI is now:
    //   1. quick-add form per supplier (artist, title, format, cost) — primary
    //   2. paste rows from website — secondary
    //   3. xlsx/csv upload — tertiary (collapsed by default)
    function renderSupplierGrid(feeds) {
        const host = document.getElementById('ica_supplier_grid');
        if (!host) return;
        if (!feeds || !Object.keys(feeds).length) {
            host.innerHTML = '<p class="text-muted small">No supplier feeds configured.</p>';
            return;
        }
        host.innerHTML = Object.keys(feeds).map((key) => {
            const f = feeds[key];
            const status = f.row_count
                ? `<span class="ica-supplier-stat">${f.row_count.toLocaleString()} titles tracked · last updated ${String(f.imported_at || '').substring(0, 10)}</span>`
                : '<span class="ica-supplier-stat ica-supplier-empty">No titles yet — add your first below</span>';
            return `
                <details class="ica-supplier-row" data-key="${escapeHtml(key)}">
                    <summary>
                        <strong>${escapeHtml(f.label || key)}</strong>
                        <small class="text-muted">${escapeHtml(f.notes || '')}</small>
                        ${status}
                    </summary>
                    <div class="ica-supplier-body">
                        <div class="ica-supplier-form">
                            <label class="ica-supplier-label">Add one title (artist + title + cost):</label>
                            <div class="ica-supplier-quickadd">
                                <input type="text" class="form-control input-sm ica-sup-artist" placeholder="Artist" data-key="${escapeHtml(key)}">
                                <input type="text" class="form-control input-sm ica-sup-title" placeholder="Title" data-key="${escapeHtml(key)}">
                                <select class="form-control input-sm ica-sup-format" data-key="${escapeHtml(key)}">
                                    <option value="">—</option>
                                    <option value="LP">LP</option>
                                    <option value="CD">CD</option>
                                    <option value="Cassette">Cassette</option>
                                    <option value="7&quot;">7"</option>
                                </select>
                                <input type="number" class="form-control input-sm ica-sup-cost" placeholder="$" step="0.01" min="0" data-key="${escapeHtml(key)}">
                                <button type="button" class="btn btn-primary btn-sm ica-supplier-quick" data-key="${escapeHtml(key)}">Add</button>
                            </div>
                            <span class="ica-supplier-msg"></span>
                        </div>
                        <details class="ica-supplier-paste">
                            <summary>Paste rows from supplier site →</summary>
                            <p class="text-muted small">Paste CSV (Artist, Title, Cost) or tab-separated rows copied from a supplier portal. First line is the header.</p>
                            <textarea class="form-control input-sm ica-sup-body" rows="4" placeholder="Artist,Title,Cost&#10;Drake,Take Care,12.50" data-key="${escapeHtml(key)}"></textarea>
                            <button type="button" class="btn btn-default btn-sm ica-supplier-paste-go" data-key="${escapeHtml(key)}">Save pasted rows</button>
                        </details>
                        <details class="ica-supplier-paste">
                            <summary>Upload xlsx/csv file (if you have one) →</summary>
                            <input type="file" class="ica-supplier-file" accept=".xlsx,.xls,.csv,.tsv,.txt" data-key="${escapeHtml(key)}">
                            <button type="button" class="btn btn-default btn-sm ica-supplier-file-go" data-key="${escapeHtml(key)}">Upload file</button>
                        </details>
                    </div>
                </details>
            `;
        }).join('');

        // Wire all three buttons
        host.querySelectorAll('.ica-supplier-quick').forEach((btn) => btn.addEventListener('click', () => supplierSubmit('single', btn)));
        host.querySelectorAll('.ica-supplier-paste-go').forEach((btn) => btn.addEventListener('click', () => supplierSubmit('paste', btn)));
        host.querySelectorAll('.ica-supplier-file-go').forEach((btn) => btn.addEventListener('click', () => supplierSubmit('file', btn)));
    }

    function supplierSubmit(mode, btn) {
        const key = btn.dataset.key;
        const host = document.getElementById('ica_supplier_grid');
        const row = host.querySelector(`details.ica-supplier-row[data-key="${cssEscape(key)}"]`);
        const msgEl = row && row.querySelector('.ica-supplier-msg');
        const fd = new FormData();
        fd.append('supplier_key', key);
        fd.append('mode', mode);

        if (mode === 'single') {
            const artist = row.querySelector('.ica-sup-artist').value.trim();
            const title = row.querySelector('.ica-sup-title').value.trim();
            const format = row.querySelector('.ica-sup-format').value;
            const cost = row.querySelector('.ica-sup-cost').value;
            if (!artist && !title) { if (msgEl) msgEl.textContent = 'Need at least artist or title.'; return; }
            if (!cost || Number(cost) <= 0) { if (msgEl) msgEl.textContent = 'Cost required.'; return; }
            fd.append('artist', artist);
            fd.append('title', title);
            fd.append('format', format);
            fd.append('cost', cost);
        } else if (mode === 'paste') {
            const body = row.querySelector('.ica-sup-body').value;
            if (!body.trim()) { if (msgEl) msgEl.textContent = 'Paste some rows first.'; return; }
            fd.append('body', body);
        } else {
            const fileEl = row.querySelector('.ica-supplier-file');
            if (!fileEl.files || !fileEl.files[0]) { if (msgEl) msgEl.textContent = 'Pick a file.'; return; }
            fd.append('feed_file', fileEl.files[0]);
        }

        btn.disabled = true;
        const origLabel = btn.textContent;
        btn.textContent = 'Saving…';
        if (msgEl) msgEl.textContent = '';
        fetch(window.ICA_SUPPLIER_UPLOAD_URL, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ICA_CSRF, 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: fd,
        })
            .then((r) => r.json())
            .then((resp) => {
                btn.disabled = false; btn.textContent = origLabel;
                if (resp && resp.success) {
                    if (msgEl) msgEl.textContent = `Saved · ${resp.added_rows} new, ${resp.row_count} total.`;
                    // Clear single-add form for next entry
                    if (mode === 'single') {
                        row.querySelector('.ica-sup-artist').value = '';
                        row.querySelector('.ica-sup-title').value = '';
                        row.querySelector('.ica-sup-cost').value = '';
                    }
                    setTimeout(loadSupplierFeeds, 500);
                } else {
                    if (msgEl) msgEl.textContent = (resp && resp.message) || 'Save failed.';
                }
            })
            .catch((err) => {
                btn.disabled = false; btn.textContent = origLabel;
                if (msgEl) msgEl.textContent = 'Save failed — see console.';
                console.error('[ICA] supplier save failed', err);
            });
    }

    function loadSupplierFeeds() {
        if (!window.ICA_SUPPLIER_LIST_URL) return;
        fetch(window.ICA_SUPPLIER_LIST_URL, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((resp) => renderSupplierGrid((resp && resp.feeds) || {}))
            .catch((err) => console.error('[ICA] supplier feeds list failed', err));
    }
    loadSupplierFeeds();

    // ── Budget: "+ Log a buy" inline form ────────────────────────────
    const $logBtn = document.getElementById('ica_log_buy_btn');
    const $logForm = document.getElementById('ica_log_buy_form');
    const $logCancel = document.getElementById('ica_log_cancel');
    const $logSave = document.getElementById('ica_log_save');
    if ($logBtn && $logForm) {
        $logBtn.addEventListener('click', () => {
            $logForm.style.display = $logForm.style.display === 'none' ? 'block' : 'none';
            if ($logForm.style.display === 'block') {
                const amt = document.getElementById('ica_log_amount');
                if (amt) amt.focus();
            }
        });
    }
    if ($logCancel) $logCancel.addEventListener('click', () => { $logForm.style.display = 'none'; });
    if ($logSave) {
        $logSave.addEventListener('click', () => {
            const amount = (document.getElementById('ica_log_amount').value || '').trim();
            const date = (document.getElementById('ica_log_date').value || '').trim();
            const source = (document.getElementById('ica_log_source').value || '').trim();
            const note = (document.getElementById('ica_log_note').value || '').trim();
            if (!amount || Number(amount) <= 0) { alert('Amount required.'); return; }
            if (!date) { alert('Pick a date.'); return; }
            $logSave.disabled = true;
            const fd = new FormData();
            fd.append('amount', amount);
            fd.append('date', date);
            if (source) fd.append('source', source);
            if (note) fd.append('note', note);
            fetch(window.ICA_LOG_BUY_URL, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ICA_CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: fd,
            })
                .then((r) => r.json())
                .then((resp) => {
                    $logSave.disabled = false;
                    if (resp && resp.success) {
                        // Easiest: hard-reload so the manual-entry chip
                        // appears under the bar and the bar repaints in
                        // the correct color band.
                        location.reload();
                    } else {
                        alert('Save failed: ' + ((resp && resp.message) || 'unknown'));
                    }
                })
                .catch((err) => {
                    $logSave.disabled = false;
                    console.error('[ICA] log buy failed', err);
                    alert('Save failed — see console.');
                });
        });
    }
    document.querySelectorAll('.ica-budget-manual-remove').forEach((btn) => {
        btn.addEventListener('click', () => {
            const id = btn.dataset.entryId;
            if (!id) return;
            if (!confirm('Remove this manual budget entry?')) return;
            fetch(window.ICA_LOG_BUY_DELETE_BASE + '/' + encodeURIComponent(id), {
                method: 'DELETE',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ICA_CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then((r) => r.json())
                .then((resp) => { if (resp && resp.success) location.reload(); });
        });
    });

    // ── "Auto-fill to budget" — pre-check rows in priority order ─────
    const $autofill = document.getElementById('ica_autofill_budget');
    if ($autofill) {
        $autofill.addEventListener('click', () => {
            const banner = document.getElementById('ica_budget_banner');
            const remaining = banner ? parseFloat(banner.dataset.remaining || '0') : 0;
            if (!remaining || remaining <= 0) {
                alert('No remaining budget this week — log a buy or wait for the week to roll over.');
                return;
            }
            // Priority order — items in higher-priority buckets get auto-
            // checked first until the running cost crosses the remaining
            // budget. Rows in lower-priority buckets get unchecked.
            const priority = ['fast_oos', 'abc_a_restock', 'manager_picks', 'top_artist_new_releases', 'customer_wants', 'universal_top', 'street_pulse', 'apple_music_top', 'long_oos_essentials', 'events_upcoming', 'ume_spotlights', 'hot_used_oos'];
            let running = 0;
            let checkedCount = 0;
            const usedRows = new WeakSet();
            priority.forEach((key) => {
                const bucket = $root.querySelector('.ica-bucket[data-bucket="' + key + '"]');
                if (!bucket) return;
                bucket.querySelectorAll('tr[data-row-key]').forEach((tr) => {
                    if (tr.style.display === 'none') { return; } // respect current filters
                    const cost = parseFloat(tr.getAttribute('data-cost') || '0') || 0;
                    const qtyInput = tr.querySelector('.ica-qty-input');
                    const qty = qtyInput ? (parseInt(qtyInput.value, 10) || 0) : 1;
                    const lineCost = cost * qty;
                    const checkbox = tr.querySelector('.ica-row-check');
                    if (!checkbox) return;
                    if (lineCost > 0 && running + lineCost <= remaining) {
                        checkbox.checked = true;
                        running += lineCost;
                        checkedCount++;
                        usedRows.add(tr);
                    }
                });
            });
            // Uncheck everything we didn't pick (only rows visible)
            $root.querySelectorAll('tr[data-row-key]').forEach((tr) => {
                if (tr.style.display === 'none') return;
                if (usedRows.has(tr)) return;
                const checkbox = tr.querySelector('.ica-row-check');
                if (checkbox) checkbox.checked = false;
            });
            renderBucketTotals();
            alert(`Auto-filled ${checkedCount} rows · $${Math.round(running).toLocaleString('en-US')} of $${Math.round(remaining).toLocaleString('en-US')} remaining budget.`);
        });
    }

    function loadManagerPicks() {
        if (!window.ICA_MGRPICKS_LIST_URL) return;
        fetch(window.ICA_MGRPICKS_LIST_URL, {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((resp) => renderManagerPicksList((resp && resp.picks) || []))
            .catch((err) => console.error('[ICA] mgr picks list failed', err));
    }

    const $mgrAddBtn = document.getElementById('ica_mgrpick_add');
    if ($mgrAddBtn) {
        $mgrAddBtn.addEventListener('click', function () {
            const note = (document.getElementById('ica_mgrpick_note').value || '').trim();
            const cat = (document.getElementById('ica_mgrpick_category').value || '').trim();
            const by = (document.getElementById('ica_mgrpick_by').value || '').trim();
            if (!note) {
                alert('Type the suggestion first (e.g. "get more sealed electronic").');
                return;
            }
            $mgrAddBtn.disabled = true;
            const fd = new FormData();
            fd.append('note', note);
            if (cat) fd.append('category_pattern', cat);
            if (by) fd.append('suggested_by', by);
            fetch(window.ICA_MGRPICKS_ADD_URL, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ICA_CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: fd,
            })
                .then((r) => r.json())
                .then((resp) => {
                    $mgrAddBtn.disabled = false;
                    if (resp && resp.success) {
                        document.getElementById('ica_mgrpick_note').value = '';
                        document.getElementById('ica_mgrpick_category').value = '';
                        renderManagerPicksList(resp.picks);
                        if (typeof lazyLoadAuxBucket === 'function' && window.ICA_MGRPICKS_BUCKET_URL) {
                            lazyLoadAuxBucket('manager_picks', window.ICA_MGRPICKS_BUCKET_URL);
                        }
                    } else {
                        alert('Add failed: ' + (resp && resp.message ? resp.message : 'unknown error'));
                    }
                })
                .catch((err) => {
                    $mgrAddBtn.disabled = false;
                    console.error('[ICA] mgr pick add failed', err);
                    alert('Add failed — see browser console.');
                });
        });
    }

    loadManagerPicks();

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

        // Image files are never submitted to the server — they get OCR'd in
        // the browser into the textarea first. If we see an image at Import
        // time, the OCR hasn't finished yet (or the user changed their
        // mind). Block the submit so the server never sees the .png and
        // returns its 422 HTML page.
        if (file && isImageFile(file) && !body) {
            alert('OCR is still running on the image — wait for the green ✓ in the status line, then click Import again. (Or paste rows manually below.)');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Importing…';

        // Use FormData so the file rides along; same endpoint accepts either.
        const fd = new FormData();
        fd.append('source', source);
        fd.append('week_of', week);
        if (body) fd.append('body', body);
        // Only forward the file if it's a tabular file the server can parse.
        // Images were already converted into `body` by Tesseract, so don't
        // double-submit.
        if (file && !isImageFile(file)) fd.append('chart_file', file);

        fetch(window.ICA_CHART_IMPORT_URL, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
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

    function ocrLuminateImages(fileInput, textarea, statusEl, fileEl, importBtn) {
        const allFiles = fileInput.files ? Array.from(fileInput.files) : [];
        const imageFiles = allFiles.filter(isImageFile);
        if (imageFiles.length === 0) return;
        if (typeof Tesseract === 'undefined') {
            alert('OCR library failed to load (network blocked?). Paste the rows manually for now.');
            return;
        }
        statusEl.style.display = 'block';

        if (importBtn) {
            importBtn.disabled = true;
            importBtn.dataset.origHtml = importBtn.dataset.origHtml || importBtn.innerHTML;
            importBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> OCR running, please wait…';
        }

        // Process images sequentially — running 5 Tesseract workers in
        // parallel would crush the user's laptop. Append rows to the
        // textarea as we go so the user sees progress.
        const allRows = [];
        const total = imageFiles.length;

        const processNext = (idx) => {
            if (idx >= total) {
                // Done with all images — write the consolidated TSV.
                if (allRows.length === 0) {
                    statusEl.innerHTML = '<span class="text-danger">No Title/Artist rows recognized in any of the ' + total + ' image' + (total > 1 ? 's' : '') + '. Try clearer screenshots, or paste rows manually.</span>';
                } else {
                    // Existing textarea content stays — append, don't overwrite.
                    const existing = (textarea.value || '').trim();
                    const header = 'Rank\tTitle\tArtist';
                    const hasHeader = existing.indexOf(header) !== -1 || /^rank\b/i.test(existing.split('\n')[0] || '');
                    let combined;
                    if (existing) {
                        combined = existing + '\n' + allRows.join('\n');
                    } else {
                        combined = header + '\n' + allRows.join('\n');
                    }
                    textarea.value = combined;
                    statusEl.innerHTML = '<span class="text-success">✓ Extracted ' + allRows.length + ' rows from ' + total + ' image' + (total > 1 ? 's' : '') + '. Review the paste box below, fix any obvious OCR mistakes, then click Import.</span>';
                }
                if (fileEl) fileEl.value = '';
                if (importBtn) {
                    importBtn.disabled = false;
                    if (importBtn.dataset.origHtml) importBtn.innerHTML = importBtn.dataset.origHtml;
                }
                return;
            }

            const file = imageFiles[idx];
            const fileLabel = (idx + 1) + '/' + total;
            statusEl.textContent = 'Image ' + fileLabel + ': starting…';

            Tesseract.recognize(file, 'eng', {
                logger: (m) => {
                    if (m && m.status) {
                        const pct = m.progress ? Math.round(m.progress * 100) : 0;
                        statusEl.textContent = 'Image ' + fileLabel + ': ' + m.status + '… ' + pct + '%';
                    }
                },
            })
                .then(({ data }) => {
                    const text = (data && data.text) || '';
                    const rows = luminateOcrToRows(text);
                    rows.forEach((r) => allRows.push(r));
                })
                .catch((err) => {
                    console.error('[ICA] tesseract failed on image ' + fileLabel, err);
                    statusEl.innerHTML = '<span class="text-warning">⚠ OCR failed on image ' + fileLabel + ': ' + escapeHtml(err && err.message ? err.message : 'unknown') + '. Continuing with the rest…</span>';
                })
                .finally(() => {
                    processNext(idx + 1);
                });
        };

        processNext(0);
    }

    /**
     * Tesseract returns one line per visual row of the image. For Luminate's
     * Top 200 layout the line looks like:
     *   "1 MUTINY AFTER MIDNIGHT  JOHNNY BLUE SKIES & THE DARK ATLANTIC ..."
     * We split on runs of 2+ spaces (Tesseract preserves multi-space gaps
     * between columns) and take rank/title/artist as the first three cells.
     * Returns an array of TSV-formatted strings (no header) so multiple
     * images can be concatenated cleanly.
     */
    function luminateOcrToRows(rawText) {
        const lines = rawText.split(/\r?\n/);
        const out = [];
        for (let i = 0; i < lines.length; i++) {
            const line = lines[i].replace(/[—–]/g, '-').trim();
            if (!line) continue;
            // Skip the marketing/header rows
            if (/luminate|copyright|confidential|week ending|top \d+|chart for/i.test(line)) {
                continue;
            }
            if (/^rank\b/i.test(line) && /artist/i.test(line) && /title/i.test(line)) {
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
        return out;
    }

    const $spFile = document.getElementById('ica_sp_file');
    const $spStatus = document.getElementById('ica_sp_ocr_status');
    const $spBody = document.getElementById('ica_sp_body');
    const $spImportBtn = document.getElementById('ica_sp_import');
    if ($spFile && $spStatus && $spBody) {
        $spFile.addEventListener('change', function () {
            const files = $spFile.files ? Array.from($spFile.files) : [];
            const anyImages = files.some(isImageFile);
            if (anyImages) {
                ocrLuminateImages($spFile, $spBody, $spStatus, $spFile, $spImportBtn);
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

    // Sessions UI removed 2026-05-20 — Sarah didn't recognize / use it.
    // Backend endpoints (listSessions/storeSession/etc) still wired so a
    // future use-case doesn't require restoring routes.

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
