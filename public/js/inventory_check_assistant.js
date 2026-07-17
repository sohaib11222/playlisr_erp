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

    // Delegated click handler for the supplier-fetch / jump / Apple-pull
    // buttons. Registered FIRST — before any rendering that could throw —
    // so the "Fetch AMS now" button is never dead even if a later init
    // step errors out. Handlers it calls (runOneClickFetch,
    // lazyLoadSecondaryBuckets) are hoisted function declarations.
    try {
        document.addEventListener('click', function (e) {
            try {
                if (!e.target || !e.target.closest) return;
                const fetchBtn = e.target.closest('.ica-fetch-supplier-now');
                if (fetchBtn) {
                    console.log('[ICA] delegated catch — fetch click on', fetchBtn.dataset.supplier);
                    e.preventDefault();
                    runOneClickFetch(fetchBtn);
                    return;
                }
                const jumpBtn = e.target.closest('.ica-jump-supplier-feeds');
                if (jumpBtn) {
                    e.preventDefault();
                    const more = document.querySelector('details.ica-more-options');
                    if (more) more.open = true;
                    const grid = document.getElementById('ica_supplier_grid');
                    if (grid) grid.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    return;
                }
                const appleBtn = e.target.closest('.ica-empty-run-apple');
                if (appleBtn) {
                    e.preventDefault();
                    appleBtn.disabled = true; appleBtn.textContent = 'Running… 30-60s';
                    fetch(window.ICA_RUN_APPLE_URL, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ICA_CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    })
                        .then((r) => r.json())
                        .then(() => {
                            appleBtn.textContent = 'Pull done — reloading chart…';
                            lazyLoadSecondaryBuckets();
                        })
                        .catch(() => { appleBtn.disabled = false; appleBtn.textContent = 'Retry Apple Music pull'; });
                }
            } catch (innerErr) {
                console.error('[ICA] delegated click handler threw', innerErr);
            }
        });
        console.log('[ICA] delegated click listener bound on document');
    } catch (outerErr) {
        console.error('[ICA] failed to bind delegated click listener', outerErr);
    }

    let lastResult = null;
    // 2026-05-27: tracks whether the abc_a_restock bucket has lazy-loaded
    // and stamped ABC classes onto rows. While false, applyRowFilters
    // treats empty rowAbc as a wildcard so the default ABC=A filter
    // doesn't hide everything pre-load. Once true, "—" rows are real
    // (unclassified products) and the filter hides them.
    let abcMapApplied = false;

    // 2026-05-27: step-card metadata for lazy-loaded buckets so a late
    // event/chart fetch can re-render its full STEP card (badge, title,
    // note, event chips) instead of replacing only the inner table and
    // losing the surrounding chrome.
    const STEP_CARDS_BY_KEY = {
        fast_oos:        { key: 'fast_oos',        step: 1, title: 'Fast moving — out of stock',  note: 'Prioritize A products (already filtered). <span class="ica-step-dont">Do NOT buy C products.</span>' },
        abc_a_restock:   { key: 'abc_a_restock',   step: 2, title: 'A-class items to restock',     note: 'Your top-value titles (A class) that are running low. RSD-exclusive titles are filtered out — restock the everyday A-class first.' },
        events_upcoming: { key: 'events_upcoming', step: 3, title: 'Listening parties + big LA shows', note: 'Listening parties = events we host on nivessa.com. LA shows = arena / amphitheater / stadium / mid-size venue. Next 45 days. Only events where we stock the artist are shown.' },
        apple_music_top: { key: 'apple_music_top', step: 4, title: 'Apple Music Top 100',          note: 'Trending Top 100 on Apple Music — make sure we carry the artists fans are streaming.' },
        universal_top:   { key: 'universal_top',   step: 5, title: 'UMe / Universal Top',          note: 'This week\'s UMe Top 200 + new deliveries.' },
        street_pulse:    { key: 'street_pulse',    step: 6, title: 'Street Pulse / Luminate chart', note: 'Luminate top sellers — the industry-wide chart.' },
        seasonal:        { key: 'seasonal',        step: 7, title: 'Seasonal — stock up ahead', note: 'Low or out-of-stock titles for the season(s) coming up (Holiday, Valentine\'s, Halloween, plus anything in a Seasonal category). Order now so they\'re on the shelf in time.' },
        accessories_low: { key: 'accessories_low', step: 8, title: 'Accessories — cleaning kits to restock', note: 'Cleaning kits, sleeves, brushes and other accessories that are low or out of stock. Reorder so they\'re always on the shelf.' },
    };

    /**
     * Replace a lazy-loaded bucket section in place. If the bucket is
     * wrapped in a STEP card (events_upcoming, charts), re-render the
     * whole card so the badge / note / event chips stay in sync.
     */
    function replaceBucketInPlace(bucketKey, bucket) {
        const existing = $root.querySelector('.ica-bucket[data-bucket="' + bucketKey + '"]');
        if (!existing) return false;
        const cardWrap = existing.closest('.ica-step-card');
        const card = STEP_CARDS_BY_KEY[bucketKey];
        const html = (cardWrap && card) ? renderStepCard(card, bucket) : renderBucketSection(bucketKey, bucket);
        const tmp = document.createElement('div');
        tmp.innerHTML = html;
        const fresh = tmp.firstElementChild;
        if (!fresh) return false;
        (cardWrap || existing).replaceWith(fresh);
        wizardReapply(); // re-assert wizard visibility on the swapped-in card
        return true;
    }

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
        // Reset the ABC-loaded flag so the wildcard kicks back in until
        // the new build's abc_a_restock lazy-load finishes.
        abcMapApplied = false;
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
                    The other lists (charts, events, ABC, frozen) load in the background after this finishes.
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
                lazyLoadAuxBucket('ume_spotlights', window.ICA_UME_SPOT_URL);
                lazyLoadAuxBucket('abc_a_restock', window.ICA_ABC_URL);
                lazyLoadAuxBucket('seasonal', window.ICA_SEASONAL_URL);
                lazyLoadAuxBucket('accessories_low', window.ICA_ACCESSORIES_URL);
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
                if (!resp || !resp.bucket) {
                    // Don't leave a permanent spinner — tell Sarah it came
                    // back empty so "not loading" isn't ambiguous.
                    if (bucketKey !== 'ume_spotlights') paintBucketError(bucketKey, 'empty response from server');
                    return;
                }
                if (lastResult && lastResult.buckets) {
                    lastResult.buckets[bucketKey] = resp.bucket;
                }
                // 2026-05-27: ume_spotlights renders as a subsection inside
                // STEP 4 (universal_top). When the spotlights bucket lazy-
                // arrives, re-render STEP 4 instead of looking for a
                // dedicated ume_spotlights element (which no longer exists).
                if (bucketKey === 'ume_spotlights') {
                    const utBucket = lastResult && lastResult.buckets && lastResult.buckets.universal_top;
                    if (utBucket) {
                        replaceBucketInPlace('universal_top', utBucket);
                        attachBucketHandlers();
                    }
                    return;
                }
                if (!replaceBucketInPlace(bucketKey, resp.bucket)) return;
                attachBucketHandlers();
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
            .catch((err) => {
                console.error('[ICA] aux bucket lazy-load failed', bucketKey, err);
                if (bucketKey !== 'ume_spotlights') paintBucketError(bucketKey, (err && err.message) || 'network error');
            });
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
                if (!replaceBucketInPlace('frozen_inventory', resp.bucket)) return;
                attachBucketHandlers();
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
                // In step-by-step mode the frozen disclosure is a wizard slide
                // and carries .ica-wizard-hidden (display:none) unless it's the
                // current step — so opening the <details> + scrollIntoView is a
                // no-op. Route through the wizard so it navigates to (and
                // un-hides) the frozen step before scrolling.
                if (wizardIsCurrentMode()) {
                    wizardGoToKey('frozen_review');
                    return;
                }
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
        // ABC map is now applied — drop the empty-rowAbc wildcard so the
        // ABC filter starts hiding genuinely unclassified rows.
        abcMapApplied = true;
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
                    replaceBucketInPlace(key, bucket);
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
                if (replaceBucketInPlace('events_upcoming', resp.bucket)) {
                    attachBucketHandlers();
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

        // 2026-05-27 Sarah: page felt overwhelming. New layout —
        //   STEP 1: Fast moving, out of stock (A products only by default)
        //   STEP 2: Listening parties + LA events (events_upcoming)
        //   STEP 3: Charts (Apple Top 100, UMe Top, Street Pulse)
        //   then everything else (manager picks, customer wants, ABC, UMe spotlights,
        //   long-OOS, hot-used) lives behind one disclosure.
        //   Frozen lives in a separate "DO NOT REORDER" warning disclosure
        //   below the other secondary so it never crowds the buy flow.
        const stepCards = [
            { key: 'fast_oos',         step: 1, title: 'Fast moving — out of stock',  note: 'Prioritize A products (already filtered). <span class="ica-step-dont">Do NOT buy C products.</span>' },
            { key: 'abc_a_restock',    step: 2, title: 'A-class items to restock',     note: 'Your top-value titles (A class) that are running low. RSD-exclusive titles are filtered out — restock the everyday A-class first.' },
            { key: 'events_upcoming',  step: 3, title: 'Listening parties + big LA shows', note: 'Listening parties = events we host on nivessa.com. LA shows = arena / amphitheater / stadium / mid-size venue. Next 45 days. Only events where we stock the artist are shown.' },
            { key: 'apple_music_top',  step: 4, title: 'Apple Music Top 100',          note: 'Trending Top 100 on Apple Music — make sure we carry the artists fans are streaming.' },
            { key: 'universal_top',    step: 5, title: 'UMe / Universal Top',          note: 'This week\'s UMe Top 200 + new deliveries.' },
            { key: 'street_pulse',     step: 6, title: 'Street Pulse / Luminate chart', note: 'Luminate top sellers — the industry-wide chart.' },
            { key: 'seasonal',         step: 7, title: 'Seasonal — stock up ahead', note: 'Low or out-of-stock titles for the season(s) coming up (Holiday, Valentine\'s, Halloween, plus anything in a Seasonal category). Order now so they\'re on the shelf in time.' },
            { key: 'accessories_low',  step: 8, title: 'Accessories — cleaning kits to restock', note: 'Cleaning kits, sleeves, brushes and other accessories that are low or out of stock. Reorder so they\'re always on the shelf.' },
        ];
        // 2026-05-27 Sarah: ume_spotlights pulled out of the secondary list —
        // it was duplicating the UMe vibe of STEP 4. Spotlights now render
        // as a "Curated UMe picks" subsection inside the STEP 4 card.
        const secondary = ['top_artist_new_releases', 'customer_wants', 'long_oos_essentials', 'hot_used_oos'];
        const buckets = payload.buckets || {};

        let primaryHtml = '';
        let secondaryHtml = '';
        let frozenHtml = '';
        let totalItems = 0;
        let totalQty = 0;
        let secondaryItems = 0;

        stepCards.forEach((card) => {
            const b = buckets[card.key];
            if (!b) return;
            primaryHtml += renderStepCard(card, b);
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
        if (buckets.frozen_inventory) {
            frozenHtml = renderBucketSection('frozen_inventory', buckets.frozen_inventory);
            totalItems += buckets.frozen_inventory.count || 0;
        }

        let html = primaryHtml;
        if (secondaryHtml !== '') {
            html += '<details class="ica-secondary-disclosure">'
                + '<summary><strong>Show the other reorder lists</strong> '
                + '<small class="text-muted">(top-artist new releases, customer wants, long out-of-stock, hot used — <span id="ica_secondary_count">' + secondaryItems + '</span> more items)</small></summary>'
                + '<div class="ica-secondary-buckets">' + secondaryHtml + '</div>'
                + '</details>';
        }
        if (frozenHtml !== '') {
            html += '<details class="ica-secondary-disclosure ica-frozen-disclosure">'
                + '<summary><strong style="color:#a94442;">⚠ Don\'t reorder these — frozen inventory</strong> '
                + '<small class="text-muted">(items already sitting unsold; review before buying any chart picks)</small></summary>'
                + '<div class="ica-secondary-buckets">'
                + '<div class="ica-dont-card"><div class="ica-dont-head"><span class="ica-dont-badge">DO NOT REORDER</span><h3 class="ica-dont-title">These titles are already sitting unsold</h3></div></div>'
                + frozenHtml + '</div>'
                + '</details>';
        }

        if (html === '') {
            const metaJson = payload.meta ? JSON.stringify(payload.meta, null, 2) : '(no meta)';
            html = '<div class="alert alert-warning"><strong>Server returned no buckets.</strong> Usually means the location preset didn\'t resolve. Server meta:<pre style="margin-top:8px; font-size:11px;">' + escapeHtml(metaJson) + '</pre></div>';
        }

        $root.innerHTML = html;
        if ($summary) $summary.textContent = '';

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
        // Hand control to the step-by-step wizard (no-op in "Show all" mode).
        wizardOnBucketsRendered();
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
        buildOrderPreview();
    }
    function grandFormat(n) {
        return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    }

    /**
     * Build the plain-English "Your order list" preview shown on the final
     * Place-order step: every ticked row across all buckets, grouped by the
     * cheapest supplier for that title, with qty. This is the printable list
     * Sarah asked for — it answers "what am I actually ordering, and from
     * whom?" Rebuilt automatically whenever totals recompute (qty / checkbox
     * changes), so it always mirrors the ticks above.
     */
    function buildOrderPreview() {
        const host = document.getElementById('ica_order_preview');
        if (!host) return;
        const supLabels = {};
        (window.ICA_KNOWN_SUPPLIERS || []).forEach((s) => { if (s && s.key) supLabels[s.key] = s.label || s.key; });
        const groups = {};       // supplierKey -> { label, qty, cost, rows: [] }
        const seen = {};         // de-dupe identical title rows across buckets
        $root.querySelectorAll('.ica-bucket tr[data-row-key]').forEach((tr) => {
            if (tr.style.display === 'none') return;
            const cb = tr.querySelector('.ica-row-check');
            if (cb && !cb.checked) return;
            const qtyInput = tr.querySelector('.ica-qty-input');
            const qty = qtyInput ? (parseInt(qtyInput.value, 10) || 0) : 0;
            if (qty < 1) return;
            const cells = tr.children;
            const product = (cells[1] ? cells[1].textContent : '').trim();
            const artist = (cells[2] ? cells[2].textContent : '').trim();
            // Cheapest supplier = the cell flagged ica-supplier-best-cell.
            let supKey = 'unassigned';
            let unitPrice = null;
            const bestCell = tr.querySelector('.ica-supplier-best-cell');
            if (bestCell) {
                supKey = bestCell.getAttribute('data-supplier') || 'unassigned';
                const p = parseFloat(bestCell.getAttribute('data-price'));
                if (Number.isFinite(p)) unitPrice = p;
            } else {
                const c = parseFloat(tr.getAttribute('data-cost'));
                if (Number.isFinite(c) && c > 0) unitPrice = c;
            }
            const dedupeKey = supKey + '|' + artist + '|' + product;
            if (seen[dedupeKey]) return;
            seen[dedupeKey] = true;
            const label = supKey === 'unassigned' ? 'No supplier matched yet' : (supLabels[supKey] || supKey);
            if (!groups[supKey]) groups[supKey] = { label: label, qty: 0, cost: 0, rows: [] };
            const lineCost = unitPrice !== null ? unitPrice * qty : null;
            groups[supKey].qty += qty;
            if (lineCost !== null) groups[supKey].cost += lineCost;
            groups[supKey].rows.push({ product: product, artist: artist, qty: qty, unitPrice: unitPrice });
        });

        const keys = Object.keys(groups);
        if (!keys.length) {
            host.innerHTML = '<div class="ica-order-preview-empty">Nothing ticked yet. Tick the titles you want to order in the steps above and they\'ll show up here, grouped by supplier.</div>';
            return;
        }
        // Real suppliers first (alpha), "unassigned" last.
        keys.sort((a, b) => {
            if (a === 'unassigned') return 1;
            if (b === 'unassigned') return -1;
            return groups[a].label.localeCompare(groups[b].label);
        });
        let html = '';
        keys.forEach((k) => {
            const g = groups[k];
            const totTxt = g.cost > 0
                ? `${g.qty} units · $${grandFormat(g.cost)}`
                : `${g.qty} units`;
            html += '<div class="ica-order-supplier">';
            html += '<div class="ica-order-supplier-head"><span>' + escapeHtml(g.label) + '</span><span class="ica-order-supplier-tot">' + escapeHtml(totTxt) + '</span></div>';
            html += '<table class="ica-order-table"><tbody>';
            g.rows.forEach((r) => {
                const name = (r.artist ? r.artist + ' — ' : '') + r.product;
                const priceTxt = r.unitPrice !== null ? '$' + r.unitPrice.toFixed(2) + ' ea' : '';
                html += '<tr><td class="ica-order-qty">' + r.qty + '×</td><td>' + escapeHtml(name) + '</td><td class="ica-order-price">' + escapeHtml(priceTxt) + '</td></tr>';
            });
            html += '</tbody></table></div>';
        });
        host.innerHTML = html;
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
            // Per-bucket Category / Genre filters layered on top of the
            // page-level selects — selects live in the bucket header.
            // (data-frozen-cat / data-frozen-gen are old names for the
            // frozen bucket only; kept for backward compat.)
            const bucketCat = bucketEl.getAttribute('data-bucket-cat') || bucketEl.getAttribute('data-frozen-cat') || '';
            const bucketGen = bucketEl.getAttribute('data-bucket-gen') || bucketEl.getAttribute('data-frozen-gen') || '';
            let visible = 0;
            bucketEl.querySelectorAll('tr[data-row-key]').forEach((tr) => {
                const rowCat = tr.getAttribute('data-cat') || '';
                const rowGen = tr.getAttribute('data-genre') || '';
                const rowAbc = tr.getAttribute('data-abc') || '';
                const rowRsd = tr.getAttribute('data-rsd') === '1';
                // 2026-05-27: only treat empty rowAbc as a wildcard while
                // the ABC bucket is still loading (abcMapApplied=false).
                // Once it's loaded, "—" means genuinely unclassified and
                // the ABC filter should hide it if a specific class is
                // selected.
                const abcMatch = !abc
                    || rowAbc === abc
                    || (!abcMapApplied && !rowAbc);
                const match = (!cat || rowCat === cat)
                    && (!gen || rowGen === gen)
                    && abcMatch
                    && (!hideRsd || !rowRsd)
                    && (!bucketCat || rowCat === bucketCat)
                    && (!bucketGen || rowGen === bucketGen);
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

    /**
     * Wrap a bucket section inside a STEP card (2026-05-27). Used for the
     * top-of-page workflow: STEP 1 fast-OOS, STEP 2 events, STEP 3-5 charts.
     */
    /**
     * 2026-05-28 Sarah: one-click "Fetch <Supplier> now" flow. Triggers
     * the artisan auto-fetch endpoint. On failure due to missing portal
     * creds, expands an inline username/password form right under the
     * button — Sarah types once + saves + auto-retries the fetch without
     * leaving STEP 1.
     */
    function runOneClickFetch(btn) {
        const key = btn.dataset.supplier;
        const origLabel = btn.dataset.origLabel || btn.textContent;
        btn.dataset.origLabel = origLabel;
        console.log('[ICA] fetch click', key, 'at', new Date().toISOString());
        // Immediate visible feedback — flash + scroll into view so "nothing
        // happened" is impossible to mistake for a dead click.
        btn.disabled = true;
        btn.textContent = 'Fetching ' + key.toUpperCase() + '…';
        btn.classList.add('ica-btn-flash');
        setTimeout(() => btn.classList.remove('ica-btn-flash'), 400);
        try { btn.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (_) {}
        // Wipe ANY prior inline cred form / error for this supplier
        // anywhere on the page so retries never stack.
        document.querySelectorAll('.ica-inline-creds[data-supplier="' + key + '"]').forEach((el) => el.remove());
        document.querySelectorAll('.ica-inline-err[data-supplier="' + key + '"]').forEach((el) => el.remove());
        // Tick-tock label so it's obvious the fetch is alive.
        let secs = 0;
        const ticker = setInterval(() => {
            secs += 1;
            btn.textContent = 'Fetching ' + key.toUpperCase() + '… ' + secs + 's';
        }, 1000);
        // Hard client-side timeout — if PHP-FPM hangs (AMS slow login),
        // surface an inline error rather than leaving the button stuck.
        const controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        const timeout = setTimeout(() => {
            if (controller) controller.abort();
        }, 300000);
        const fd = new FormData();
        fd.append('supplier_key', key);
        const fetchOpts = {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ICA_CSRF, 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: fd,
        };
        if (controller) fetchOpts.signal = controller.signal;
        // The endpoint STREAMS plain text now (heartbeat dots + the artisan
        // command's output) instead of a single JSON blob — a portal walk
        // runs for minutes and used to die on the web-request timeout. We
        // read the whole stream to completion via r.text(); the streamed
        // bytes keep the connection alive the entire time.
        fetch(window.ICA_SUPPLIER_AUTOFETCH_URL, fetchOpts)
            .then((r) => r.text())
            .then((out) => {
                clearInterval(ticker); clearTimeout(timeout);
                out = out || '';
                console.log('[ICA] fetch resp', key, out);
                const exitM = out.match(/\[exit code:\s*(\d+)\]/i);
                const exit = exitM ? parseInt(exitM[1], 10) : null;
                const hardErr = /\[error:/i.test(out);
                const needsCreds = /missing credential|credential keys|missing portal|not set|not configured|set them in \.env/i.test(out);
                // Row count from the command's "[key] fetched N rows." line.
                const m = out.match(/fetched\s+([0-9][0-9,]*)\s+rows/i) || out.match(/([0-9][0-9,]*)\s+rows\s+fetched/i);
                const fetched = m ? parseInt(m[1].replace(/,/g, ''), 10) : null;

                if (needsCreds) {
                    btn.disabled = false; btn.textContent = origLabel;
                    showInlineCredsForm(btn, key);
                    return;
                }
                // Success = the process exited 0, printed no [error:], and
                // reported a positive row count.
                if (exit === 0 && !hardErr && fetched !== null && fetched > 0) {
                    btn.textContent = '✓ Pulled ' + fetched.toLocaleString() + ' rows — rebuilding…';
                    const activeBtn = document.querySelector('.ica-store-btn.is-active');
                    if (activeBtn) activeBtn.click();
                    return;
                }
                // Ran but produced nothing usable — say so loudly rather than
                // pretending it worked and leaving every column empty.
                btn.disabled = false; btn.textContent = origLabel;
                if (fetched === 0 || exit === 0) {
                    showInlineError(btn, key, key.toUpperCase() + ' returned 0 rows. The portal login likely bounced or the catalog page changed — prices can\'t populate. Re-save the portal credentials below and retry.');
                } else {
                    // Surface the tail of the command output (the real error).
                    const lines = out.split('\n').map((l) => l.trim()).filter((l) => l && l !== '.');
                    showInlineError(btn, key, lines.slice(-4).join(' ') || 'unknown error');
                }
            })
            .catch((err) => {
                clearInterval(ticker); clearTimeout(timeout);
                console.error('[ICA] fetch err', key, err);
                btn.disabled = false; btn.textContent = origLabel;
                const isAbort = err && err.name === 'AbortError';
                const msg = isAbort
                    ? 'Timed out after 120s — the portal login is slow or stuck. Try again, or check Apache error log on the server.'
                    : (err && err.message) || 'network error';
                showInlineError(btn, key, msg);
            });
    }

    /**
     * Dismissable inline error box (replaces alert(), which Sarah couldn't
     * dismiss on her browser). Renders right next to the Fetch button.
     */
    function showInlineError(btn, key, message) {
        document.querySelectorAll('.ica-inline-err[data-supplier="' + key + '"]').forEach((el) => el.remove());
        const box = document.createElement('div');
        box.className = 'ica-inline-err';
        box.setAttribute('data-supplier', key);
        const label = (window.ICA_KNOWN_SUPPLIERS || []).find((s) => s.key === key);
        const supLabel = label ? label.label : key.toUpperCase();
        box.innerHTML = `
            <div class="ica-inline-err-head">
                <strong>${escapeHtml(supLabel)} fetch failed</strong>
                <button type="button" class="ica-inline-err-close" title="Dismiss">×</button>
            </div>
            <pre class="ica-inline-err-body">${escapeHtml(String(message))}</pre>`;
        btn.parentElement.insertBefore(box, btn.nextSibling);
        box.querySelector('.ica-inline-err-close').addEventListener('click', () => box.remove());
    }

    function showInlineCredsForm(btn, key) {
        // Defensive: nuke any leftover form for this supplier first so the
        // banner can never stack multiple AMS / Secretly / etc. forms.
        document.querySelectorAll('.ica-inline-creds[data-supplier="' + key + '"]').forEach((el) => el.remove());
        const label = (window.ICA_KNOWN_SUPPLIERS || []).find((s) => s.key === key);
        const supLabel = label ? label.label : key.toUpperCase();
        const form = document.createElement('div');
        form.className = 'ica-inline-creds';
        form.setAttribute('data-supplier', key);
        // 2026-05-28 Sarah: per-supplier field set. Only AMS asks for an
        // Account Number. Other portals just need username + password
        // (the Portal URL is captured server-side from config and is
        // optional here for advanced override).
        const showAccount = key === 'ams';
        const accountField = showAccount
            ? `<input type="text" class="form-control input-sm ica-inline-account" placeholder="Account number" autocomplete="off">`
            : '';
        form.innerHTML = `
            <div class="ica-inline-creds-head">🔐 ${escapeHtml(supLabel)} portal login (saved encrypted, never shown back)</div>
            <div class="ica-inline-creds-row">
                ${accountField}
                <input type="text" class="form-control input-sm ica-inline-user" placeholder="Username" autocomplete="off">
                <input type="password" class="form-control input-sm ica-inline-pass" placeholder="Password" autocomplete="new-password">
                <button type="button" class="btn btn-success btn-sm ica-inline-save">Save + fetch</button>
                <button type="button" class="btn btn-link btn-sm ica-inline-cancel">Cancel</button>
            </div>
            <div class="ica-inline-creds-msg"></div>`;
        btn.parentElement.insertBefore(form, btn.nextSibling);
        form.querySelector('.ica-inline-cancel').addEventListener('click', () => form.remove());
        form.querySelector('.ica-inline-save').addEventListener('click', function () {
            const accountEl = form.querySelector('.ica-inline-account');
            const account = accountEl ? accountEl.value.trim() : '';
            const user = form.querySelector('.ica-inline-user').value.trim();
            const pass = form.querySelector('.ica-inline-pass').value;
            if (!user || !pass) {
                form.querySelector('.ica-inline-creds-msg').textContent = 'Username + password required.';
                return;
            }
            const saveBtn = form.querySelector('.ica-inline-save');
            saveBtn.disabled = true; saveBtn.textContent = 'Saving…';
            const fd = new FormData();
            fd.append('supplier_key', key);
            fd.append('portal_user', user);
            fd.append('portal_pass', pass);
            if (account) fd.append('portal_account', account);
            fetch(window.ICA_SUPPLIER_CREDS_URL, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ICA_CSRF, 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: fd,
            })
                .then((r) => r.json())
                .then((resp) => {
                    if (!resp || !resp.success) {
                        form.querySelector('.ica-inline-creds-msg').textContent = (resp && resp.message) || 'Save failed.';
                        saveBtn.disabled = false; saveBtn.textContent = 'Save + fetch';
                        return;
                    }
                    form.querySelector('.ica-inline-creds-msg').textContent = '✓ Saved. Fetching prices now…';
                    runOneClickFetch(btn);
                    setTimeout(() => form.remove(), 800);
                })
                .catch(() => {
                    form.querySelector('.ica-inline-creds-msg').textContent = 'Save failed — see console.';
                    saveBtn.disabled = false; saveBtn.textContent = 'Save + fetch';
                });
        });
    }

    function renderStepCard(card, bucket) {
        // 2026-06-03 Sarah: the events step is a heads-up reminder (the chip
        // summary already aggregates "X units across Y titles"); the raw
        // filter + table underneath was just noise, so drop it for events.
        const inner = card.key === 'events_upcoming' ? '' : renderBucketSection(card.key, bucket);
        let extras = '';
        // 2026-05-27 Sarah: surface the bucket's `why` line inside the
        // step card so the live diagnostic (e.g. "Supplier feeds loaded:
        // AMS (1,234) · matched 42 / 180 rows") isn't hidden by the
        // header-suppression CSS. Lives under the static step note.
        const whyText = bucket && typeof bucket.why === 'string' ? bucket.why.trim() : '';
        const whyLine = whyText ? `<div class="ica-step-why">${escapeHtml(whyText)}</div>` : '';
        // Events bucket: prepend a per-event order summary so Sarah can see
        // "did we order for the listening party? how many?" at a glance.
        if (card.key === 'events_upcoming' && bucket) {
            extras = renderEventOrderSummary(bucket);
        }
        // 2026-05-27 Sarah: UMe spotlights live INSIDE the STEP 4 universal_top
        // card as a curated-picks subsection so she doesn't see "UMe" twice.
        if (card.key === 'universal_top') {
            const spot = lastResult && lastResult.buckets && lastResult.buckets.ume_spotlights;
            if (spot && Array.isArray(spot.items) && spot.items.length) {
                extras += renderUmeSpotlightChips(spot);
            }
        }
        // 2026-05-27 Sarah: when fast_oos comes back with no supplier
        // feeds loaded, surface a one-click "Fetch AMS now" so prices
        // populate without a widget hunt. Auto-fetch then re-runs every
        // Monday via the scheduled cron once credentials are saved.
        if (card.key === 'fast_oos' && bucket && Array.isArray(bucket.supplier_feeds_loaded) && bucket.supplier_feeds_loaded.length === 0) {
            const supplierBtns = (window.ICA_KNOWN_SUPPLIERS || []).map((sup) =>
                `<button type="button" class="btn btn-primary btn-sm ica-fetch-supplier-now" data-supplier="${escapeHtml(sup.key)}">Fetch ${escapeHtml(sup.label)} now</button>`
            ).join(' ');
            extras += `
                <div class="ica-empty-cta ica-empty-cta-prices">
                    <p><strong>Distributor price columns are empty — no feeds pulled yet for this business.</strong> Once portal credentials are saved, the weekly Monday 06:00 PST cron auto-refreshes them. Trigger a pull now:</p>
                    <div class="ica-fetch-supplier-row">${supplierBtns}</div>
                    <p class="ica-fetch-supplier-hint"><small>First pull asks for portal login if not saved yet. After that, it auto-refreshes every Monday so the columns are always current — no weekly upload.</small> <a href="#" class="ica-jump-supplier-feeds">Or open supplier feeds widget ↓</a></p>
                </div>`;
        }
        // Apple Music Top 100 empty-state CTA — 2026-05-27 Sarah saw a
        // "No items in this bucket" with no path forward; surface the
        // Run-Apple-Music-pull action right here so she knows what to do.
        if (card.key === 'apple_music_top' && bucket && (!bucket.items || !bucket.items.length)) {
            extras = `
                <div class="ica-empty-cta">
                    <p>No Apple Music chart data yet. Click below to fetch the latest Top 100.</p>
                    <button type="button" class="btn btn-primary btn-sm ica-empty-run-apple">
                        <i class="fa fa-bolt"></i> Run Apple Music pull now
                    </button>
                </div>`;
        }
        // UMe + Street Pulse charts are imported by hand (xlsx/paste/inbox).
        // When there's nothing for this week, show a clear "import it here"
        // prompt instead of a silent empty table (Sarah 2026-06-03).
        if ((card.key === 'universal_top' || card.key === 'street_pulse') && bucket && !bucket.lazy && (!bucket.items || !bucket.items.length)) {
            const modalTarget = card.key === 'universal_top' ? '#ica_ut_modal' : '#ica_sp_modal';
            const chartName = card.key === 'universal_top' ? 'UMe / Universal' : 'Street Pulse / Luminate';
            extras += `
                <div class="ica-empty-cta">
                    <p>No ${escapeHtml(chartName)} chart imported for this week yet. Import it to populate this step.</p>
                    <button type="button" class="btn btn-primary btn-sm" data-toggle="modal" data-target="${modalTarget}">
                        <i class="fa fa-upload"></i> Import this week's chart
                    </button>
                </div>`;
        }
        return `
            <div class="ica-step-card" data-step="${card.step}">
                <div class="ica-step-head">
                    <span class="ica-step-badge">Step ${card.step}</span>
                    <h2 class="ica-step-title">${escapeHtml(card.title)}</h2>
                </div>
                <div class="ica-step-note">${card.note}</div>
                ${whyLine}
                ${extras}
                ${inner}
            </div>`;
    }

    /**
     * 2026-05-27 Sarah: UMe Update spotlights rendered as chips inside the
     * STEP 4 Universal Top card so she sees the UMe section once, not twice.
     */
    function renderUmeSpotlightChips(bucket) {
        const items = (bucket.items || []).slice(0, 24);
        if (!items.length) return '';
        const chips = items.map((it) => {
            const stockBadge = (it.stock !== null && it.stock !== undefined && Number(it.stock) > 0)
                ? `<span class="ica-spot-have">In stock: ${Number(it.stock)}</span>`
                : `<span class="ica-spot-miss">not in stock</span>`;
            const date = escapeHtml(it.release_date_label || it.release_date || '');
            return `
                <div class="ica-spot-chip">
                    <div class="ica-spot-chip-head">
                        <strong>${escapeHtml(it.artist || '')}</strong>${it.product ? ' — ' + escapeHtml(it.product) : ''}
                    </div>
                    <div class="ica-spot-chip-meta">
                        ${date ? `<span>Release: ${date}</span>` : ''}
                        ${stockBadge}
                    </div>
                </div>`;
        }).join('');
        return `
            <div class="ica-spot-block">
                <div class="ica-event-summary-head">💿 UMe weekly spotlights — curated picks (${items.length})</div>
                <div class="ica-spot-chips">${chips}</div>
            </div>`;
    }

    // 2026-05-27 Sarah: LA shows chips trimmed to "very popular names" only —
    // arena / amphitheater / stadium / large-theater venues. Anything in
    // a smaller club is still in the underlying bucket table but isn't
    // promoted as a featured chip. Substring match, case-insensitive.
    const POPULAR_LA_VENUES = [
        'crypto.com arena', 'staples center', 'kia forum', 'the forum', 'inglewood forum',
        'sofi stadium', 'dodger stadium', 'rose bowl', 'bmo stadium', 'banc of california',
        'honda center', 'intuit dome', 'hollywood bowl', 'greek theatre',
        'microsoft theater', 'peacock theater', 'youtube theater',
        'wiltern', 'walt disney concert hall', 'shrine auditorium',
        'hollywood palladium', 'orpheum theatre', 'the novo',
    ];
    function isPopularLaVenue(location) {
        if (!location) return false;
        const lc = String(location).toLowerCase();
        return POPULAR_LA_VENUES.some((v) => lc.indexOf(v) !== -1);
    }

    /**
     * Render event chips using bucket.all_events (every event we pulled
     * from nivessa.com / TM, regardless of whether a product matched) +
     * bucket.event_orders (manual "ordered via email" entries from Sarah).
     *
     * Categorization (2026-05-27):
     *   listening parties = source nivessa (events we host on nivessa.com)
     *   LA shows          = source ticketmaster AND venue in POPULAR_LA_VENUES
     *   anniversaries     = source anniversary (UMe biopic/birthday rows)
     * Small-club TM shows go to the underlying bucket table but aren't
     * promoted as a featured chip.
     */
    function renderEventOrderSummary(bucket) {
        const allEvents = Array.isArray(bucket.all_events) ? bucket.all_events : [];
        const items = Array.isArray(bucket.items) ? bucket.items : [];

        // Aggregate per-event qty + item count from bucket items.
        const itemAgg = {};
        items.forEach((it) => {
            const k = (it.event_name || '') + '|' + (it.event_date || '');
            if (!itemAgg[k]) itemAgg[k] = { items: 0, qty: 0 };
            itemAgg[k].items += 1;
            itemAgg[k].qty += parseInt(it.suggested_qty || 0, 10) || 0;
        });

        // Build event list. Prefer bucket.all_events; fall back to keys
        // observed in items if all_events is missing.
        const events = allEvents.length ? allEvents.slice() : (() => {
            const out = [];
            const seen = {};
            items.forEach((it) => {
                const k = (it.event_name || '') + '|' + (it.event_date || '');
                if (seen[k]) return;
                seen[k] = true;
                out.push({
                    name: it.event_name || '',
                    date: it.event_date || '',
                    location: it.event_location || '',
                    source: it.event_source || 'nivessa',
                    is_anniversary: (it.tags || []).indexOf('anniversary') !== -1,
                });
            });
            return out;
        })();
        events.sort((a, b) => (a.date || '').localeCompare(b.date || ''));

        const tagged = events.map((e) => {
            const src = e.source || '';
            const isAnniversary = src === 'anniversary' || !!e.is_anniversary;
            const isListening = src === 'nivessa' && !isAnniversary;
            const isPopularTm = src === 'ticketmaster' && isPopularLaVenue(e.location);
            return Object.assign({}, e, {
                is_listening_party: isListening,
                is_anniversary: isAnniversary,
                is_popular_tm: isPopularTm,
            });
        });

        // 2026-06-03 Sarah: only surface events where we actually stock the
        // artist's records — hides indie live shows + small-time artists with
        // no in-store matches. LA venue tier still includes mid-size rooms.
        const hasStock = (e) => {
            const agg = itemAgg[(e.name || '') + '|' + (e.date || '')];
            return !!(agg && agg.qty > 0);
        };
        const listening = tagged.filter((e) => e.is_listening_party && hasStock(e));
        const others = tagged.filter((e) => (e.is_popular_tm || e.is_anniversary) && hasStock(e));

        const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const eventChip = (e) => {
            const dateMd = (e.date || '').match(/^(\d{4})-(\d{2})-(\d{2})/);
            let dateBadge = '<span class="ica-event-date-day">' + escapeHtml(e.date || 'TBD') + '</span>';
            if (dateMd) {
                const mo = MONTHS[parseInt(dateMd[2], 10) - 1] || dateMd[2];
                const dy = parseInt(dateMd[3], 10);
                dateBadge = `<span class="ica-event-date-mo">${mo}</span><span class="ica-event-date-day">${dy}</span>`;
            }
            const locTxt = e.location ? `<div class="ica-event-chip-loc">${escapeHtml(e.location)}</div>` : '';
            const annivBadge = e.is_anniversary ? ' <span class="ica-event-anniv">anniv</span>' : '';
            const k = (e.name || '') + '|' + (e.date || '');
            const agg = itemAgg[k] || { items: 0, qty: 0 };
            const qtyLine = `<strong>${agg.qty}</strong> units suggested across ${agg.items} title${agg.items === 1 ? '' : 's'}`;
            return `
                <div class="ica-event-chip ${e.is_listening_party ? 'ica-event-listening' : 'ica-event-show'}">
                    <div class="ica-event-date-badge">${dateBadge}</div>
                    <div class="ica-event-chip-main">
                        <div class="ica-event-chip-head"><strong>${escapeHtml(e.name || '(untitled)')}</strong>${annivBadge}</div>
                        ${locTxt}
                        <div class="ica-event-chip-qty">${qtyLine}</div>
                    </div>
                </div>`;
        };
        let html = '<div class="ica-event-summary">';
        html += '<div class="ica-event-summary-head">Listening parties at Nivessa' + (listening.length ? ' (' + listening.length + ')' : '') + '</div>';
        html += '<div class="ica-event-reminder">Did we order the stock for each listening party? Tick it off as you order.</div>';
        if (listening.length) {
            html += '<div class="ica-event-chips">' + listening.map(eventChip).join('') + '</div>';
        } else {
            html += '<div class="ica-event-empty">No listening parties (that we stock) in the next 45 days.</div>';
        }
        if (others.length) {
            html += '<div class="ica-event-summary-head">Big LA shows + artist moments (' + others.length + ')</div>';
            html += '<div class="ica-event-chips">' + others.map(eventChip).join('') + '</div>';
        }
        html += '</div>';
        return html;
    }

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
        // 2026-05-28 Sarah: Format + Bin columns dropped globally — too
        // noisy for ordering (Bin still useful inside the Frozen list,
        // re-added below for that bucket only).
        const headParts = [
            `<th><input type="checkbox" class="ica-select-all" data-bucket="${escapeHtml(key)}"></th>`,
            sortable('Product', 'text'),
            sortable('Artist', 'text'),
            sortable('Category', 'text'),
            sortable('Genre', 'text'),
            sortable('ABC', 'text', 'ABC class — A is the top 80% of inventory value'),
            sortable('Stock', 'number'),
            sortable('Sold (window)', 'number'),
            sortable('Last paid', 'number', 'Wholesale price we paid for the most recent unit of this title (variations.dpp_inc_tax). Compare against the distributor columns to see if a current supplier is cheaper than last time.'),
        ];
        if (isFrozen) {
            // Bin position is essential for physically finding the unsold
            // copy on shelf — keep it in the frozen list only.
            headParts.splice(3, 0, sortable('Bin', 'text'));
        }
        if (isFrozen) {
            headParts.push(sortable('Price', 'number', 'Retail sell price (from product_stock_cache.unit_price).'));
            headParts.push(sortable('Added', 'date', 'When this product was first added to the system (products.created_at).'));
            headParts.push(sortable('Last edited', 'date', 'Most recent edit to the product / variation record. NOT the last-sold date — frozen status is based on no SALE in N days, regardless of when the record was last edited.'));
        }
        // 2026-05-27 Sarah: one column per distributor in fast-OOS + chart
        // buckets so Sarah can see every supplier's price at a glance and
        // pick the cheapest. Inline chips dropped (now a column).
        const suppliers = (window.ICA_KNOWN_SUPPLIERS || []);
        const showSupplierCols = suppliers.length > 0 && (key === 'fast_oos' || key === 'street_pulse' || key === 'universal_top' || key === 'apple_music_top' || key === 'top_artist_new_releases' || key === 'abc_a_restock' || key === 'long_oos_essentials' || key === 'hot_used_oos' || key === 'customer_wants' || key === 'seasonal');
        if (showSupplierCols) {
            suppliers.forEach((sup) => {
                headParts.push(sortable(sup.label, 'number', 'Latest wholesale price for this title from ' + sup.label + '. Cheapest cell across the row is highlighted green.'));
            });
        }
        headParts.push(sortable('Reason', 'text'));
        headParts.push(sortable('Tags', 'text'));
        headParts.push(sortable('Qty', 'qty'));
        headParts.push('<th></th>');
        const headRow = headParts.join('');

        // 2026-05-27 Sarah: every bucket gets its own Category + Genre
        // filter chips so she can scope each section without changing the
        // page-level filter. Persists across re-renders by reading the
        // prior bucket's data-bucket-cat / data-bucket-gen.
        const prevBucket = $root && $root.querySelector('.ica-bucket[data-bucket="' + key + '"]');
        const prevBucketCat = prevBucket ? (prevBucket.getAttribute('data-bucket-cat') || prevBucket.getAttribute('data-frozen-cat') || '') : '';
        const prevBucketGen = prevBucket ? (prevBucket.getAttribute('data-bucket-gen') || prevBucket.getAttribute('data-frozen-gen') || '') : '';
        const bucketCats = new Set();
        const bucketGens = new Set();
        (b.items || []).forEach((it) => {
            if (it.category_name) bucketCats.add(it.category_name);
            if (it.genre) bucketGens.add(it.genre);
        });
        const bucketCatOpts = '<option value="">All</option>' + Array.from(bucketCats).sort().map((c) => `<option value="${escapeHtml(c)}" ${c === prevBucketCat ? 'selected' : ''}>${escapeHtml(c)}</option>`).join('');
        const bucketGenOpts = '<option value="">All</option>' + Array.from(bucketGens).sort().map((g) => `<option value="${escapeHtml(g)}" ${g === prevBucketGen ? 'selected' : ''}>${escapeHtml(g)}</option>`).join('');
        const bucketFilters = (b.count || 0) === 0 ? '' : `
            <div class="ica-bucket-controls">
                <label class="ica-filter-label">Category</label>
                <select class="ica-bucket-cat-filter form-control input-sm">${bucketCatOpts}</select>
                <label class="ica-filter-label">Genre</label>
                <select class="ica-bucket-gen-filter form-control input-sm">${bucketGenOpts}</select>
            </div>`;

        // Frozen bucket carries an extra "frozen if no sale in N days"
        // re-fetch select on top of the standard cat/genre filters.
        let frozenControls = '';
        if (isFrozen) {
            const days = parseInt(b.frozen_days || 180, 10);
            const presetVals = [90, 120, 180, 365];
            const opts = presetVals.map((v) => `<option value="${v}" ${v === days ? 'selected' : ''}>${v} days</option>`).join('');
            const isCustom = !presetVals.includes(days);
            frozenControls = `
                <div class="ica-frozen-controls">
                    <label class="ica-filter-label">Frozen if no sale in</label>
                    <select class="ica-frozen-days-select form-control input-sm">${opts}<option value="custom" ${isCustom ? 'selected' : ''}>Custom…</option></select>
                    <input type="number" class="form-control input-sm ica-frozen-days-custom" min="7" max="3650" value="${days}" style="${isCustom ? '' : 'display:none;'}" placeholder="days">
                </div>`;
        }
        let emptyHtml = `<div class="ica-bucket-empty">No items in this bucket${b.empty_reason ? ' (' + b.empty_reason.replace(/_/g, ' ') + ')' : ''}.</div>`;
        // Customer wants only shows requests logged in the ERP's Customer
        // Wants page (status = active, this store or all-stores). If it's
        // empty it usually means requests aren't being entered here yet —
        // point Sarah straight at the page to add / review them rather than
        // leaving a bare "no items".
        if (key === 'customer_wants' && (b.count || 0) === 0) {
            const manageUrl = window.ICA_CUSTOMER_WANTS_URL || '';
            const link = manageUrl
                ? ` <a href="${escapeHtml(manageUrl)}" target="_blank" rel="noopener">Open the Customer Wants page</a> to add or review requests.`
                : '';
            emptyHtml = `<div class="ica-bucket-empty">No active customer requests logged for this store yet.${link}</div>`;
        }
        const body = (b.count || 0) === 0
            ? emptyHtml
            : `<table class="table table-condensed table-striped ica-row-table"><thead><tr>${headRow}</tr></thead><tbody>${rows}</tbody></table>`;

        // Per-bucket cat/gen values, if any, must persist across re-renders.
        let bucketDataAttrs = '';
        if (prevBucketCat) bucketDataAttrs += ` data-bucket-cat="${escapeHtml(prevBucketCat)}"`;
        if (prevBucketGen) bucketDataAttrs += ` data-bucket-gen="${escapeHtml(prevBucketGen)}"`;

        return `
            <div class="ica-bucket box box-default" data-bucket="${escapeHtml(key)}"${bucketDataAttrs}>
                <div class="ica-bucket-header">
                    <div>
                        <h3>${escapeHtml(b.label || key)} <span class="ica-bucket-count ${countClass}">${b.count || 0}</span></h3>
                        <span class="ica-why">${escapeHtml(b.why || '')}</span>
                        ${frozenControls}
                        ${bucketFilters}
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

    // 2026-05-28 Sarah: per-supplier site for the "search this title"
    // fallback link on supplier-price cells (when the fetcher didn't
    // save a direct product URL). Google site-search lands close to
    // the right page on each distributor's portal.
    function supplierFallbackHost(key) {
        switch (key) {
            case 'ams':      return 'allmediasupply.com';
            case 'alliance': return 'aent.com';
            case 'secretly': return 'secretlydistribution.com';
            case 'beggars':  return 'beggarsgroup.com';
            case 'redeye':   return 'redeyeworldwide.com';
            case 'vp':       return 'vprecords.com';
            default:         return '';
        }
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
        // 2026-05-27 Sarah: supplier prices moved out of the product cell
        // into dedicated columns (see supplierCellsHtml below). The inline
        // chips were noisy; columns let her compare side-by-side.
        const prices = Array.isArray(it.supplier_prices) ? it.supplier_prices : [];
        const pricesByKey = {};
        prices.forEach((p) => {
            if (!p || !p.supplier_key) return;
            const cost = parseFloat(p.cost);
            if (!Number.isFinite(cost)) return;
            // Already sorted asc by cost — first per supplier wins.
            if (!pricesByKey[p.supplier_key]) pricesByKey[p.supplier_key] = p;
        });
        const bestCost = prices.length ? parseFloat(prices[0].cost) : null;
        const supplierList = (window.ICA_KNOWN_SUPPLIERS || []);
        const showSupplierCols = supplierList.length > 0 && (
            bucket === 'fast_oos' || bucket === 'street_pulse' || bucket === 'universal_top' ||
            bucket === 'apple_music_top' || bucket === 'top_artist_new_releases' ||
            bucket === 'abc_a_restock' || bucket === 'long_oos_essentials' ||
            bucket === 'hot_used_oos' || bucket === 'customer_wants' || bucket === 'seasonal'
        );
        let supplierCellsHtml = '';
        if (showSupplierCols) {
            supplierCellsHtml = supplierList.map((sup) => {
                const p = pricesByKey[sup.key];
                if (!p) return `<td class="ica-supplier-col" data-supplier="${escapeHtml(sup.key)}">—</td>`;
                const cost = parseFloat(p.cost);
                const isBest = bestCost !== null && Math.abs(cost - bestCost) < 0.0001;
                const upcTip = p.upc ? ` · UPC ${p.upc}` : '';
                const cls = isBest ? 'ica-supplier-col ica-supplier-best-cell' : 'ica-supplier-col';
                // 2026-05-28 Sarah: clicking a price opens the supplier's
                // product page in a new tab. Uses the parsed product URL
                // when the fetcher saved one (AMS does); falls back to a
                // Google site search for the title so the click is never
                // a dead-end.
                const directUrl = p.url || '';
                const fallbackHost = supplierFallbackHost(sup.key);
                const queryFrom = (it.product || it.artist || '').toString();
                const queryUrl = queryFrom
                    ? 'https://www.google.com/search?q=' + encodeURIComponent('site:' + fallbackHost + ' ' + queryFrom)
                    : '';
                const href = directUrl || queryUrl;
                const linkTitle = (directUrl ? 'Open this product on ' : 'Search ') + escapeHtml(sup.label) + upcTip;
                if (href) {
                    return `<td class="${cls}" data-supplier="${escapeHtml(sup.key)}" data-price="${cost}"><a href="${escapeHtml(href)}" target="_blank" rel="noopener" class="ica-supplier-link" title="${linkTitle}">$${cost.toFixed(2)}</a></td>`;
                }
                return `<td class="${cls}" data-supplier="${escapeHtml(sup.key)}" data-price="${cost}" title="${escapeHtml(sup.label)}${escapeHtml(upcTip)}">$${cost.toFixed(2)}</td>`;
            }).join('');
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

        // 2026-05-28: Format + Bin columns dropped globally. Bin re-added
        // for the Frozen list only (matches header insertion above).
        const binCell = isFrozen ? `<td><small>${bin || '—'}</small></td>` : '';
        return `<tr data-row-key="${escapeHtml(rowKey)}" data-pid="${pid}" data-cat="${category}" data-genre="${genre}" data-abc="${initialAbc}" data-rsd="${isRsd ? '1' : '0'}" data-cost="${costNum !== null ? costNum : ''}">
            <td><input type="checkbox" class="ica-row-check" ${checkboxAttrs}></td>
            <td>${productCell}</td>
            <td>${artist}</td>
            ${binCell}
            <td><small>${category || '—'}</small></td>
            <td><small>${genre || '—'}</small></td>
            <td class="ica-abc-col">${abcCell}</td>
            <td class="ica-stock-col">${stock}</td>
            <td>${sold}</td>
            <td class="ica-cost-col">${costCell}</td>
            ${priceCellHtml}
            ${createdCellHtml}
            ${updatedCellHtml}
            ${supplierCellsHtml}
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

        // Per-bucket Category / Genre filters (every bucket, not just frozen)
        // — write the choice onto the bucket element so applyRowFilters can
        // pick it up.
        $root.querySelectorAll('.ica-bucket-cat-filter, .ica-bucket-gen-filter').forEach((sel) => {
            sel.addEventListener('change', function () {
                const bucketEl = sel.closest('.ica-bucket');
                if (!bucketEl) return;
                if (sel.classList.contains('ica-bucket-cat-filter')) {
                    bucketEl.setAttribute('data-bucket-cat', sel.value || '');
                } else {
                    bucketEl.setAttribute('data-bucket-gen', sel.value || '');
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

        // (Empty-banner buttons — supplier-feed jump + per-supplier fetch +
        // Apple Music pull — are now wired via delegated $root click handler
        // at module init below so they survive partial re-renders.)

        // (Apple Music CTA is also handled by the delegated listener below.)
    }

    // (Delegated click handler for the supplier-fetch / jump / Apple-pull
    // buttons is registered at the very TOP of this IIFE — see init — so
    // it survives any later module-init error.)

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
            const af = f.auto_fetch;
            let afHtml = '<small class="text-muted">Auto-fetch: not yet configured · needs portal URL + credentials in server .env</small>';
            if (af) {
                const when = String(af.at || '').substring(0, 16).replace('T', ' ');
                afHtml = af.ok
                    ? `<small class="ica-supplier-autofetch ok">Auto-fetch ✓ ${escapeHtml(when)} — ${escapeHtml(af.message || '')}</small>`
                    : `<small class="ica-supplier-autofetch err">Auto-fetch ✗ ${escapeHtml(when)} — ${escapeHtml(af.message || '')}</small>`;
            }
            return `
                <details class="ica-supplier-row" data-key="${escapeHtml(key)}">
                    <summary>
                        <strong>${escapeHtml(f.label || key)}</strong>
                        <small class="text-muted">${escapeHtml(f.notes || '')}</small>
                        ${status}
                    </summary>
                    <div class="ica-supplier-body">
                        <div class="ica-supplier-autofetch-row">
                            ${afHtml}
                            <button type="button" class="btn btn-xs btn-default ica-supplier-autofetch-go" data-key="${escapeHtml(key)}">Run auto-fetch now</button>
                        </div>
                        <details class="ica-supplier-creds">
                            <summary>🔐 Portal credentials (for auto-fetch)</summary>
                            <p class="text-muted small">Stored encrypted on the server. Never displayed back. Auto-fetch uses these to log into the supplier portal.</p>
                            <div class="ica-creds-row">
                                <label>Username</label>
                                <input type="text" class="form-control input-sm ica-cred-user" autocomplete="off" data-key="${escapeHtml(key)}" placeholder="leave blank to keep current">
                                <label>Account #</label>
                                <input type="text" class="form-control input-sm ica-cred-account" autocomplete="off" data-key="${escapeHtml(key)}" placeholder="(only if portal asks for one)">
                                <label>Password</label>
                                <input type="password" class="form-control input-sm ica-cred-pass" autocomplete="new-password" data-key="${escapeHtml(key)}" placeholder="leave blank to keep current">
                                <button type="button" class="btn btn-primary btn-sm ica-cred-save" data-key="${escapeHtml(key)}">Save</button>
                            </div>
                            <span class="ica-cred-msg" data-key="${escapeHtml(key)}"></span>
                        </details>
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
        host.querySelectorAll('.ica-supplier-autofetch-go').forEach((btn) => btn.addEventListener('click', () => runSupplierAutoFetch(btn)));
        host.querySelectorAll('.ica-cred-save').forEach((btn) => btn.addEventListener('click', () => saveSupplierCredentials(btn)));
    }

    function saveSupplierCredentials(btn) {
        const key = btn.dataset.key;
        const host = document.getElementById('ica_supplier_grid');
        const row = host.querySelector(`details.ica-supplier-row[data-key="${cssEscape(key)}"]`);
        const user = row.querySelector('.ica-cred-user').value.trim();
        const account = row.querySelector('.ica-cred-account').value.trim();
        const pass = row.querySelector('.ica-cred-pass').value;
        const msg = row.querySelector('.ica-cred-msg');
        if (!user && !account && !pass) { if (msg) msg.textContent = 'Nothing to save.'; return; }
        btn.disabled = true;
        const orig = btn.textContent; btn.textContent = 'Saving…';
        if (msg) msg.textContent = '';
        const fd = new FormData();
        fd.append('supplier_key', key);
        if (user) fd.append('portal_user', user);
        if (account) fd.append('portal_account', account);
        if (pass) fd.append('portal_pass', pass);
        fetch(window.ICA_SUPPLIER_CREDS_URL, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ICA_CSRF, 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: fd,
        })
            .then((r) => r.json())
            .then((resp) => {
                btn.disabled = false; btn.textContent = orig;
                if (resp && resp.success) {
                    row.querySelector('.ica-cred-user').value = '';
                    row.querySelector('.ica-cred-account').value = '';
                    row.querySelector('.ica-cred-pass').value = '';
                    if (msg) msg.textContent = 'Saved encrypted. You can now click "Run auto-fetch now" above.';
                } else {
                    if (msg) msg.textContent = (resp && resp.message) || 'Save failed.';
                }
            })
            .catch((err) => {
                btn.disabled = false; btn.textContent = orig;
                if (msg) msg.textContent = 'Save failed — see console.';
                console.error('[ICA] supplier creds save failed', err);
            });
    }

    function runSupplierAutoFetch(btn) {
        const key = btn.dataset.key;
        const host = document.getElementById('ica_supplier_grid');
        const row = host.querySelector(`details.ica-supplier-row[data-key="${cssEscape(key)}"]`);
        const statusEl = row && row.querySelector('.ica-supplier-autofetch-row small');
        btn.disabled = true;
        const orig = btn.textContent;
        btn.textContent = 'Fetching…';
        if (statusEl) statusEl.textContent = 'Running…';
        const fd = new FormData();
        fd.append('supplier_key', key);
        fetch(window.ICA_SUPPLIER_AUTOFETCH_URL, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': window.ICA_CSRF, 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: fd,
        })
            .then((r) => r.json())
            .then((resp) => {
                btn.disabled = false;
                btn.textContent = orig;
                console.log('[ICA] widget auto-fetch resp', key, resp);
                const out = (resp && resp.output) || (resp && resp.message) || '';
                if (resp && resp.success) {
                    const m = out.match(/fetched\s+([0-9][0-9,]*)\s+rows/i) || out.match(/([0-9][0-9,]*)\s+rows\s+fetched/i);
                    const fetched = m ? parseInt(m[1].replace(/,/g, ''), 10) : null;
                    if (fetched === 0) {
                        if (statusEl) statusEl.textContent = 'Ran, but returned 0 rows — portal login likely bounced or the catalog page changed. Re-save credentials below and retry.';
                        return;
                    }
                    if (statusEl) statusEl.textContent = 'Pulled ' + (fetched !== null ? (fetched.toLocaleString() + ' rows') : '') + ' — refreshing feeds…';
                    setTimeout(loadSupplierFeeds, 300);
                    return;
                }
                // 200 OK but the fetch itself failed (exit != 0) — surface
                // WHY so the click never looks like a no-op. Most common
                // cause is missing portal credentials → point Sarah at the
                // Credentials form right below in the same panel.
                const needsCreds = /credential|env|missing portal|not set|not configured/i.test(out);
                const firstLine = String(out).split('\n').map((l) => l.trim()).filter(Boolean)[0] || 'Fetch failed.';
                if (statusEl) {
                    statusEl.textContent = needsCreds
                        ? 'No portal login saved yet — open "Credentials" below, save it, then retry.'
                        : ('Fetch failed: ' + firstLine);
                }
            })
            .catch((err) => {
                btn.disabled = false;
                btn.textContent = orig;
                console.error('[ICA] supplier auto-fetch failed', err);
                if (statusEl) statusEl.textContent = 'Network/timeout error — the portal may be slow; retry, or check the server log.';
            });
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
            .then((r) => r.text().then((t) => ({ status: r.status, text: t })))
            .then(({ status, text }) => {
                const host = document.getElementById('ica_supplier_grid');
                let resp = null;
                try { resp = JSON.parse(text); } catch (_) { /* HTML error page */ }
                if (!resp) {
                    if (host) host.innerHTML = '<div class="alert alert-danger" style="margin:8px 0;"><strong>Supplier feeds failed to load (HTTP ' + status + ').</strong> Server didn\'t return JSON. Open the browser console (F12) for the raw response.</div>';
                    console.error('[ICA] supplier feeds non-JSON response', text.substring(0, 500));
                    return;
                }
                if (resp.error) {
                    if (host) host.innerHTML = '<div class="alert alert-danger" style="margin:8px 0;"><strong>Server error:</strong> ' + escapeHtml(resp.error) + '</div>';
                    return;
                }
                renderSupplierGrid(resp.feeds || {});
            })
            .catch((err) => {
                const host = document.getElementById('ica_supplier_grid');
                if (host) host.innerHTML = '<div class="alert alert-danger" style="margin:8px 0;"><strong>Supplier feeds: network error.</strong> ' + escapeHtml((err && err.message) || 'fetch failed') + '</div>';
                console.error('[ICA] supplier feeds list failed', err);
            });
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
            const kindEl = document.querySelector('input[name="ica_log_kind"]:checked');
            const kind = kindEl ? (kindEl.value || 'new') : 'new';
            if (!amount || Number(amount) <= 0) { alert('Amount required.'); return; }
            if (!date) { alert('Pick a date.'); return; }
            $logSave.disabled = true;
            const fd = new FormData();
            fd.append('amount', amount);
            fd.append('date', date);
            fd.append('kind', kind);
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
            const priority = ['fast_oos', 'abc_a_restock', 'seasonal', 'accessories_low', 'top_artist_new_releases', 'customer_wants', 'universal_top', 'street_pulse', 'apple_music_top', 'long_oos_essentials', 'events_upcoming', 'ume_spotlights', 'hot_used_oos'];
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

    // ── Step-by-step wizard ──────────────────────────────────────────
    // Presentation layer over the already-rendered buckets: shows one step
    // at a time with a progress stepper, Back / Skip / Mark-done nav, and a
    // shared per-store weekly checklist persisted server-side. Fully
    // non-destructive — "Show all" drops back to the classic full scroll,
    // and every bucket / handler keeps working untouched. Resets each Monday.
    const WIZARD_SLIDES = [
        { key: 'fast_oos',        label: 'Fast movers',  sel: () => $root.querySelector('.ica-step-card[data-step="1"]') },
        { key: 'abc_a_restock',   label: 'A-class restock', sel: () => $root.querySelector('.ica-step-card[data-step="2"]') },
        { key: 'events_upcoming', label: 'Events',       sel: () => $root.querySelector('.ica-step-card[data-step="3"]') },
        { key: 'apple_music_top', label: 'Apple Top 100', importStep: true, sel: () => $root.querySelector('.ica-step-card[data-step="4"]') },
        { key: 'universal_top',   label: 'UMe Top',      importStep: true, sel: () => $root.querySelector('.ica-step-card[data-step="5"]') },
        { key: 'street_pulse',    label: 'Street Pulse', importStep: true, sel: () => $root.querySelector('.ica-step-card[data-step="6"]') },
        { key: 'seasonal',        label: 'Seasonal',     sel: () => $root.querySelector('.ica-step-card[data-step="7"]') },
        { key: 'accessories_low', label: 'Accessories',  sel: () => $root.querySelector('.ica-step-card[data-step="8"]') },
        { key: 'other_lists',     label: 'Other lists',  sel: () => $root.querySelector('.ica-secondary-disclosure:not(.ica-frozen-disclosure)') },
        { key: 'frozen_review',   label: "Frozen — don't reorder", sel: () => $root.querySelector('.ica-frozen-disclosure') },
        { key: 'manual_nonmusic', label: 'Other categories', sel: () => document.getElementById('ica_manual_reorder_step') },
        { key: 'place_order',     label: 'Place order',  sel: () => document.getElementById('ica_export_strip') },
    ];

    const $wizBar = document.getElementById('ica_wizard_bar');
    const $wizNav = document.getElementById('ica_wizard_nav');
    const $wizDots = document.getElementById('ica_wizard_dots');
    const $wizLabel = document.getElementById('ica_wizard_step_label');
    const $wizToggle = document.getElementById('ica_wizard_toggle');
    const $wizBack = document.getElementById('ica_wizard_back');
    const $wizSkip = document.getElementById('ica_wizard_skip');
    const $wizDone = document.getElementById('ica_wizard_done');
    const $wizCenter = document.getElementById('ica_wizard_nav_center');

    let wizardMode = (function () {
        try { return localStorage.getItem('ica_wizard_mode') || 'step'; } catch (_) { return 'step'; }
    })();
    let wizardProgress = {};     // { slideKey: {state:'done'|'skipped'} }
    let wizardCurrentKey = null; // key of the slide on screen
    let wizardBuilt = false;     // a list has been rendered at least once

    function wizardStoreKey() {
        return ($preset && $preset.value) ? $preset.value : 'default';
    }

    /** Slides whose DOM element currently exists, in canonical order. */
    function wizardExistingSlides() {
        return WIZARD_SLIDES.map((s) => ({ slide: s, el: s.sel() })).filter((x) => !!x.el);
    }

    function wizardIsCurrentMode() { return wizardMode === 'step'; }

    function wizardApply() {
        const existing = wizardExistingSlides();
        if ($wizToggle) {
            $wizToggle.textContent = wizardIsCurrentMode() ? 'Show all (classic scroll)' : 'Back to step-by-step';
        }
        if (!wizardIsCurrentMode() || !wizardBuilt || existing.length === 0) {
            // Classic scroll — strip per-slide chrome, show everything.
            existing.forEach((x) => { x.el.classList.remove('ica-wizard-hidden', 'ica-wizard-current'); });
            document.body.classList.remove('ica-wizard-active');
            if ($root) $root.classList.remove('ica-wizard-mode');
            if ($wizNav) $wizNav.style.display = 'none';
            // Keep the bar (toggle only) reachable once a list is built so the
            // user can switch back into step-by-step; hide the dots/label.
            if ($wizBar) $wizBar.style.display = (wizardBuilt && existing.length) ? '' : 'none';
            if ($wizDots) $wizDots.style.display = 'none';
            if ($wizLabel) $wizLabel.textContent = 'All steps (classic scroll)';
            if (wizardBuilt) wizardDecorate(existing);
            return;
        }
        if ($wizDots) $wizDots.style.display = '';
        // Ensure currentKey points at an existing slide.
        if (!existing.some((x) => x.slide.key === wizardCurrentKey)) {
            wizardCurrentKey = existing[0].slide.key;
        }
        document.body.classList.add('ica-wizard-active');
        if ($root) $root.classList.add('ica-wizard-mode');
        if ($wizBar) $wizBar.style.display = '';
        if ($wizNav) $wizNav.style.display = 'flex';
        existing.forEach((x) => {
            const isCur = x.slide.key === wizardCurrentKey;
            x.el.classList.toggle('ica-wizard-hidden', !isCur);
            x.el.classList.toggle('ica-wizard-current', isCur);
            // <details> slides need to be open to show their content.
            if (isCur && x.el.tagName === 'DETAILS') x.el.open = true;
        });
        wizardDecorate(existing);
        wizardRenderChrome(existing);
    }

    /**
     * Inject / refresh the per-step control bar (status + Mark done / Reopen
     * + Skip + shared note) at the top of each slide, and collapse completed
     * steps to a slim green bar. Runs in both step and show-all modes.
     */
    function wizardDecorate(existing) {
        existing.forEach((x, i) => {
            const st = wizardProgress[x.slide.key] || {};
            const isDone = st.state === 'done';
            const isSkipped = st.state === 'skipped';
            // Find / create the control bar as the slide's first child (after
            // a <summary> for <details> slides so the disclosure still works).
            let ctl = x.el.querySelector(':scope > .ica-wizard-stepctl');
            if (!ctl) {
                ctl = document.createElement('div');
                ctl.className = 'ica-wizard-stepctl';
                const summary = x.el.querySelector(':scope > summary');
                if (summary) summary.insertAdjacentElement('afterend', ctl);
                else x.el.insertBefore(ctl, x.el.firstChild);
            }
            ctl.dataset.wizkey = x.slide.key;
            const num = i + 1;
            const noteVal = typeof st.note === 'string' ? st.note : '';
            const noteMeta = (noteVal && st.note_by)
                ? 'last edit ' + escapeHtml(st.note_by) : '';
            let badge = '<span class="ica-wizard-stepctl-todo">To do</span>';
            if (isDone) badge = '<span class="ica-wizard-stepctl-done">✓ Done' + (st.by ? ' · ' + escapeHtml(st.by) : '') + '</span>';
            else if (isSkipped) badge = '<span class="ica-wizard-stepctl-skip">Skipped this week</span>';
            const skipBtn = (x.slide.importStep && !isDone && !isSkipped)
                ? '<button type="button" class="btn btn-link btn-xs ica-wizard-ctl-skip">Skip this week</button>' : '';
            const doneBtn = isDone
                ? '<button type="button" class="btn btn-default btn-xs ica-wizard-ctl-reopen">Reopen</button>'
                : '<button type="button" class="btn btn-success btn-xs ica-wizard-ctl-done">Mark step done</button>';
            const noteCount = noteVal ? ' •' : '';
            ctl.innerHTML =
                '<div class="ica-wizard-stepctl-row">'
                + '<span class="ica-wizard-stepctl-head">'
                +   '<span class="ica-wizard-stepctl-num">' + num + '</span>'
                +   '<span class="ica-wizard-stepctl-title">' + escapeHtml(x.slide.label) + '</span>'
                +   badge
                + '</span>'
                + '<span class="ica-wizard-stepctl-actions">'
                +   '<button type="button" class="btn btn-link btn-xs ica-wizard-ctl-notetoggle">' + (noteVal ? 'Note' + noteCount : 'Add note') + '</button>'
                +   skipBtn
                +   doneBtn
                + '</span>'
                + '</div>'
                + '<div class="ica-wizard-stepctl-notes" style="display:none;">'
                +   '<textarea class="form-control input-sm ica-wizard-note-input" rows="2" maxlength="1000" placeholder="Leave a note for the team — e.g. ordered 12, rest backordered…">' + escapeHtml(noteVal) + '</textarea>'
                +   '<div class="ica-wizard-note-foot">'
                +     '<button type="button" class="btn btn-primary btn-xs ica-wizard-note-save">Save note</button>'
                +     '<span class="ica-wizard-note-meta">' + noteMeta + '</span>'
                +   '</div>'
                + '</div>';
            // Collapse completed steps to the slim bar (keep current peekable).
            x.el.classList.toggle('ica-wizard-collapsed', isDone);
            if (!isDone) x.el.classList.remove('ica-wizard-peek');
            // If there's a saved note, surface the box open so it's visible.
            if (noteVal) {
                const box = ctl.querySelector(':scope > .ica-wizard-stepctl-notes');
                if (box) box.style.display = '';
            }
        });
    }

    function wizardSaveNote(key, note) {
        const body = new URLSearchParams();
        body.append('store', wizardStoreKey());
        body.append('step', key);
        body.append('note', note);
        fetch(window.ICA_WIZARD_PROGRESS_URL, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': window.ICA_CSRF || '',
            },
            credentials: 'same-origin',
            body: body.toString(),
        })
            .then((r) => r.json())
            .then((resp) => { if (resp && resp.steps) wizardProgress = resp.steps; wizardApply(); })
            .catch((err) => console.error('[ICA] wizard note save failed', err));
    }

    function wizardRenderChrome(existing) {
        const pos = existing.findIndex((x) => x.slide.key === wizardCurrentKey);
        const total = existing.length;
        const curIdx = pos < 0 ? 0 : pos;
        const cur = existing[curIdx];
        if ($wizLabel) {
            const doneCount = existing.filter((x) => (wizardProgress[x.slide.key] || {}).state === 'done').length;
            $wizLabel.textContent = 'Step ' + (curIdx + 1) + ' of ' + total + ' · ' + doneCount + ' done';
        }
        // Dots
        if ($wizDots) {
            $wizDots.innerHTML = existing.map((x, i) => {
                const st = (wizardProgress[x.slide.key] || {}).state || '';
                let cls = 'ica-wizard-dot';
                if (x.slide.key === wizardCurrentKey) cls += ' is-current';
                else if (st === 'done') cls += ' is-done';
                else if (st === 'skipped') cls += ' is-skipped';
                const mark = st === 'done' ? '✓' : (st === 'skipped' ? '–' : (i + 1));
                return '<span class="' + cls + '" data-wizkey="' + x.slide.key + '">'
                    + '<span class="ica-wizard-dot-num">' + mark + '</span>'
                    + escapeHtml(x.slide.label) + '</span>';
            }).join('');
        }
        // Nav buttons
        if ($wizBack) $wizBack.disabled = (curIdx === 0);
        const isLast = (curIdx === total - 1);
        if ($wizDone) $wizDone.innerHTML = isLast ? 'Finish' : 'Mark done &amp; next →';
        if ($wizSkip) $wizSkip.style.display = (cur && cur.slide.importStep) ? '' : 'none';
        if ($wizCenter) {
            const allDone = existing.every((x) => {
                const st = (wizardProgress[x.slide.key] || {}).state;
                return st === 'done' || st === 'skipped';
            });
            $wizCenter.textContent = allDone
                ? 'All steps handled for this week ✓'
                : (cur ? cur.slide.label : '');
        }
    }

    function wizardGoToKey(key) {
        wizardCurrentKey = key;
        wizardApply();
        const cur = WIZARD_SLIDES.find((s) => s.key === key);
        const el = cur ? cur.sel() : null;
        if (el && el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    function wizardAdvance() {
        const existing = wizardExistingSlides();
        const pos = existing.findIndex((x) => x.slide.key === wizardCurrentKey);
        if (pos >= 0 && pos < existing.length - 1) {
            wizardGoToKey(existing[pos + 1].slide.key);
        } else {
            wizardApply(); // stay on last, refresh chrome
        }
    }

    function wizardSetState(key, state) {
        // Optimistic local update so the UI is instant.
        if (state === 'reset') delete wizardProgress[key];
        else wizardProgress[key] = { state: state };
        const body = new URLSearchParams();
        body.append('store', wizardStoreKey());
        body.append('step', key);
        body.append('state', state);
        fetch(window.ICA_WIZARD_PROGRESS_URL, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': window.ICA_CSRF || '',
            },
            credentials: 'same-origin',
            body: body.toString(),
        })
            .then((r) => r.json())
            .then((resp) => { if (resp && resp.steps) wizardProgress = resp.steps; wizardApply(); })
            .catch((err) => console.error('[ICA] wizard save failed', err));
    }

    function wizardLoadProgress() {
        if (!window.ICA_WIZARD_PROGRESS_URL) return;
        fetch(window.ICA_WIZARD_PROGRESS_URL + '?store=' + encodeURIComponent(wizardStoreKey()), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        })
            .then((r) => r.json())
            .then((resp) => {
                wizardProgress = (resp && resp.steps) ? resp.steps : {};
                // Resume on the first step that isn't done/skipped.
                const existing = wizardExistingSlides();
                const firstOpen = existing.find((x) => {
                    const st = (wizardProgress[x.slide.key] || {}).state;
                    return st !== 'done' && st !== 'skipped';
                });
                wizardCurrentKey = firstOpen ? firstOpen.slide.key
                    : (existing[0] ? existing[0].slide.key : null);
                wizardApply();
            })
            .catch((err) => { console.error('[ICA] wizard load failed', err); wizardApply(); });
    }

    // Called at the end of renderBuckets and after lazy re-renders.
    function wizardOnBucketsRendered() {
        wizardBuilt = true;
        if (wizardIsCurrentMode()) wizardLoadProgress();
        else wizardApply();
    }
    // Re-assert visibility after a single step card is replaced in place.
    function wizardReapply() {
        if (wizardBuilt) wizardApply();
    }

    if ($wizToggle) {
        $wizToggle.addEventListener('click', function (e) {
            e.preventDefault();
            wizardMode = wizardIsCurrentMode() ? 'all' : 'step';
            try { localStorage.setItem('ica_wizard_mode', wizardMode); } catch (_) {}
            $wizToggle.textContent = wizardIsCurrentMode() ? 'Show all (classic scroll)' : 'Back to step-by-step';
            if (wizardIsCurrentMode()) wizardLoadProgress();
            else wizardApply();
        });
    }
    if ($wizBack) $wizBack.addEventListener('click', function () {
        const existing = wizardExistingSlides();
        const pos = existing.findIndex((x) => x.slide.key === wizardCurrentKey);
        if (pos > 0) wizardGoToKey(existing[pos - 1].slide.key);
    });
    if ($wizDone) $wizDone.addEventListener('click', function () {
        wizardSetState(wizardCurrentKey, 'done');
        wizardAdvance();
    });
    if ($wizSkip) $wizSkip.addEventListener('click', function () {
        wizardSetState(wizardCurrentKey, 'skipped');
        wizardAdvance();
    });
    if ($wizDots) $wizDots.addEventListener('click', function (e) {
        const dot = e.target.closest('.ica-wizard-dot');
        if (dot && dot.dataset.wizkey) wizardGoToKey(dot.dataset.wizkey);
    });

    // Delegated handlers for the per-step control bars injected by
    // wizardDecorate(). One listener on document covers every slide, including
    // ones that get re-rendered by the lazy bucket pipeline.
    document.addEventListener('click', function (e) {
        const ctl = e.target.closest('.ica-wizard-stepctl');
        if (!ctl || !ctl.dataset.wizkey) return;
        const key = ctl.dataset.wizkey;
        const stepMode = wizardIsCurrentMode() && wizardBuilt;
        // Mark step done — collapse to green bar, advance in step mode.
        if (e.target.closest('.ica-wizard-ctl-done')) {
            wizardSetState(key, 'done');
            if (stepMode && wizardCurrentKey === key) wizardAdvance();
            return;
        }
        // Reopen a completed/skipped step.
        if (e.target.closest('.ica-wizard-ctl-reopen')) {
            wizardSetState(key, 'reset');
            return;
        }
        // Skip this week (import steps only).
        if (e.target.closest('.ica-wizard-ctl-skip')) {
            wizardSetState(key, 'skipped');
            if (stepMode && wizardCurrentKey === key) wizardAdvance();
            return;
        }
        // Toggle the note box open/closed.
        if (e.target.closest('.ica-wizard-ctl-notetoggle')) {
            e.preventDefault();
            const box = ctl.querySelector(':scope > .ica-wizard-stepctl-notes');
            if (box) {
                const showing = box.style.display !== 'none';
                box.style.display = showing ? 'none' : '';
                if (!showing) {
                    const ta = box.querySelector('.ica-wizard-note-input');
                    if (ta) ta.focus();
                }
            }
            return;
        }
        // Save the shared note.
        if (e.target.closest('.ica-wizard-note-save')) {
            const ta = ctl.querySelector('.ica-wizard-note-input');
            wizardSaveNote(key, ta ? ta.value : '');
            return;
        }
        // Clicking the header of a collapsed (done) step peeks it open.
        if (e.target.closest('.ica-wizard-stepctl-head')) {
            const slide = ctl.parentElement;
            if (slide && slide.classList.contains('ica-wizard-collapsed')) {
                slide.classList.toggle('ica-wizard-peek');
            }
        }
    });

    // Expose the two hooks for the render pipeline (same IIFE scope).
    window.__icaWizardOnRendered = wizardOnBucketsRendered;
    window.__icaWizardReapply = wizardReapply;

})();
