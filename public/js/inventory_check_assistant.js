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
                            // checkbox/product/artist/format/bin/cat/genre/abc/stock
                            const stockCell = tr.children[8];
                            if (stockCell) stockCell.textContent = newQty;
                            btn.dataset.current = String(newQty);
                            btn.disabled = false; btn.textContent = 'Set stock';
                            // Reason is td:nth-child(12) after Bin + ABC + Cost
                            const reasonCell = tr.querySelector('td:nth-child(12) small');
                            if (reasonCell) {
                                const userName = (resp.entry && resp.entry.user_name) || 'you';
                                const when = new Date().toISOString().substring(0, 10);
                                reasonCell.innerHTML += ` <span class="ica-last-order">· updated to ${newQty} on ${when} by ${escapeHtml(userName)}</span>`;
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
        const secondary = ['manager_picks', 'customer_wants', 'street_pulse', 'universal_top', 'apple_music_top', 'top_artist_new_releases', 'events_upcoming', 'abc_a_restock', 'long_oos_essentials', 'hot_used_oos', 'frozen_inventory'];
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
            let visible = 0;
            bucketEl.querySelectorAll('tr[data-row-key]').forEach((tr) => {
                const rowCat = tr.getAttribute('data-cat') || '';
                const rowGen = tr.getAttribute('data-genre') || '';
                const rowAbc = tr.getAttribute('data-abc') || '';
                const rowRsd = tr.getAttribute('data-rsd') === '1';
                const match = (!cat || rowCat === cat)
                    && (!gen || rowGen === gen)
                    && (!abc || rowAbc === abc)
                    && (!hideRsd || !rowRsd);
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
        // Category + Genre columns added 2026-05-20 so the page can be
        // filtered by either. The two new dropdowns above the buckets
        // drive client-side row hiding.
        const headRow = `<th><input type="checkbox" class="ica-select-all" data-bucket="${escapeHtml(key)}"></th>
               <th>Product</th><th>Artist</th><th>Format</th><th>Bin</th><th>Category</th><th>Genre</th><th title="ABC class — A is the top 80% of inventory value">ABC</th><th>Stock</th><th>Sold (window)</th><th title="Wholesale / purchase price per unit">Cost</th><th>Reason</th><th>Tags</th><th>Qty</th><th></th>`;
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
        } else if (bucket === 'frozen_inventory' && it.variation_id) {
            extraCol = `<button type="button" class="btn btn-xs btn-default ica-frozen-edit-btn" data-vid="${it.variation_id}" data-lid="${it.location_id || ''}" data-current="${it.stock}">Set stock</button>`;
        }

        // Frozen rows also show "updated YYYY-MM-DD by Name" on the reason
        // line when there's a logged in-place correction.
        let reasonExtra = '';
        if (bucket === 'frozen_inventory' && it.last_correction && it.last_correction.when) {
            const when = String(it.last_correction.when).substring(0, 10);
            const by = it.last_correction.by || '';
            reasonExtra = ` <span class="ica-last-order">· updated to ${it.last_correction.after} on ${escapeHtml(when)} by ${escapeHtml(by)}</span>`;
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
        const productCell = isRsd ? `${product} <span class="ica-tag ica-rsd-tag" title="Record Store Day release">RSD</span>` : product;
        // Cost is the wholesale / default_purchase_price per unit.
        // data-cost holds the numeric value so renderBucketTotals can sum
        // "qty × cost" across visible rows.
        const costNum = (typeof it.cost_price === 'number' && !isNaN(it.cost_price)) ? it.cost_price : null;
        const costCell = costNum !== null ? `$${costNum.toFixed(2)}` : '—';
        return `<tr data-row-key="${escapeHtml(rowKey)}" data-pid="${pid}" data-cat="${category}" data-genre="${genre}" data-abc="${initialAbc}" data-rsd="${isRsd ? '1' : '0'}" data-cost="${costNum !== null ? costNum : ''}">
            <td><input type="checkbox" class="ica-row-check" ${checkboxAttrs}></td>
            <td>${productCell}</td>
            <td>${artist}</td>
            <td>${format}</td>
            <td><small>${bin || '—'}</small></td>
            <td><small>${category || '—'}</small></td>
            <td><small>${genre || '—'}</small></td>
            <td class="ica-abc-col">${abcCell}</td>
            <td>${stock}</td>
            <td>${sold}</td>
            <td class="ica-cost-col">${costCell}</td>
            <td><small>${reason}${reasonExtra}</small></td>
            <td>${tagsHtml}</td>
            <td><input type="number" class="form-control input-sm ica-qty-input" value="${qty}" min="0" max="99" ${qtyDisabled}></td>
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
