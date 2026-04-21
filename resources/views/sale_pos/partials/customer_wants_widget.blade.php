{{-- ===========================================================
     Customer Snapshot — expandable overlay inside the customer
     account info box. Replaces the old floating left-edge panel
     per Sarah's 2026-04-21 ask.

     Behaviour:
       * Compact mode: a single "See wants & purchases" button sits
         at the bottom of the customer-account-info box. Shows a
         badge with the open-wants count.
       * Expanded mode: an absolutely-positioned panel drops down
         from the button, overlaying the content below (scan input
         etc.). Same footprint when collapsed, no layout shift.

     The panel aggregates everything "important" about the customer
     in one place: active wishes (with in-stock matches + inline
     add form), recent purchases (last 5), and past/fulfilled wishes.

     Only renders for real rewards customers — walk-in default
     customer is treated as "no customer selected" so the button
     never appears.
     ============================================================ --}}

<style>
    /* Trigger button — sits at the bottom of the customer account box. */
    .cwl-trigger-row {
        display: none;
        margin-top: 8px;
        padding-top: 8px;
        border-top: 1px dashed #DFD2B3;
    }
    .cwl-trigger-row.cwl-visible { display: flex; align-items: center; gap: 8px; }
    .cwl-trigger-btn {
        display: inline-flex; align-items: center; gap: 8px;
        background: #FFF9DB; color: #5A4410;
        border: 1px solid #E8CF68;
        border-radius: 999px;
        padding: 5px 14px;
        font-size: 12px; font-weight: 600;
        cursor: pointer;
        font-family: "Inter Tight", system-ui, sans-serif;
    }
    .cwl-trigger-btn:hover { background: #FFF2B3; }
    .cwl-trigger-btn .cwl-chev { font-size: 10px; transition: transform .15s; }
    .cwl-trigger-btn.cwl-open .cwl-chev { transform: rotate(180deg); }
    .cwl-trigger-badge {
        background: #8A3A2E; color: #fff;
        font-size: 10px; font-weight: 800;
        padding: 1px 7px; border-radius: 999px; line-height: 1.4;
    }
    .cwl-trigger-hint { font-size: 11px; color: #8E8273; }

    /* The expandable panel — absolutely positioned from the account box.
       When open, overlays the content below (scan input, cart, etc.). */
    .cwl-panel {
        position: absolute;
        left: 0; right: 0; top: 100%;
        margin-top: 6px;
        z-index: 1030;
        background: #fff;
        border: 1px solid #ECE3CF;
        border-radius: 10px;
        box-shadow: 0 8px 24px rgba(31,27,22,.12);
        font-family: "Inter Tight", system-ui, sans-serif;
        font-size: 12px;
        max-height: 70vh; overflow-y: auto;
        display: none;
    }
    .cwl-panel.cwl-open { display: block; }

    /* Section headers inside the panel */
    .cwl-section { padding: 10px 14px; }
    .cwl-section + .cwl-section { border-top: 1px solid #F7F1E3; }
    .cwl-section-head {
        display: flex; align-items: center; justify-content: space-between;
        font-size: 10px; font-weight: 800; letter-spacing: .12em;
        text-transform: uppercase; color: #8E8273;
        margin-bottom: 8px;
    }
    .cwl-section-head .cwl-count {
        background: #F7F1E3; color: #5A4410;
        font-size: 10px; font-weight: 800; padding: 1px 7px; border-radius: 999px;
    }

    /* Wish list items */
    .cwl-item { padding: 6px 0; border-bottom: 1px solid #F7F1E3; }
    .cwl-item:last-child { border-bottom: none; }
    .cwl-label {
        font-size: 12px; font-weight: 600; color: #1F1B16;
        overflow: hidden; text-overflow: ellipsis; word-break: break-word;
    }
    .cwl-pri {
        display: inline-block; padding: 1px 6px; border-radius: 999px;
        font-size: 9px; font-weight: 700; text-transform: uppercase;
        margin-left: 4px; vertical-align: middle;
    }
    .cwl-pri.high { background: #fde8e8; color: #8A3A2E; }
    .cwl-pri.normal { background: #F7F1E3; color: #5A5045; }
    .cwl-pri.low { background: #f3f4f6; color: #8E8273; }
    .cwl-match {
        margin-top: 4px; padding: 6px 8px;
        background: #FFF9DB; border: 1px solid #E8CF68; border-radius: 6px;
        font-size: 10px; color: #5A4410;
    }
    .cwl-match-product {
        font-weight: 600; color: #1F1B16;
        overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
    }
    .cwl-match-stock { color: #2F6B3E; font-weight: 700; font-size: 9px; }
    .cwl-found {
        margin-top: 6px; background: #2F6B3E; color: #fff;
        border: none; border-radius: 6px; padding: 4px 10px;
        font-family: inherit; font-size: 11px; font-weight: 700; cursor: pointer;
    }
    .cwl-found:hover { background: #265732; }

    /* Recent purchases */
    .cwl-tx {
        padding: 6px 0; border-bottom: 1px solid #F7F1E3;
        display: flex; justify-content: space-between; align-items: baseline;
        gap: 10px;
    }
    .cwl-tx:last-child { border-bottom: none; }
    .cwl-tx-left { flex: 1; min-width: 0; }
    .cwl-tx-date { font-size: 10px; color: #8E8273; }
    .cwl-tx-items {
        font-size: 12px; color: #1F1B16;
        overflow: hidden; text-overflow: ellipsis; word-break: break-word;
    }
    .cwl-tx-loc { font-size: 10px; color: #8E8273; }
    .cwl-tx-total {
        font-size: 13px; font-weight: 700; color: #1F1B16;
        font-variant-numeric: tabular-nums; white-space: nowrap;
    }
    .cwl-empty { padding: 8px 0; font-size: 11px; color: #8E8273; text-align: center; }

    /* Past wishes (collapsed sub-section) */
    .cwl-past-item { padding: 3px 0; font-size: 11px; color: #8E8273; }

    /* Add-a-new-wish inline form */
    .cwl-add-form { padding: 10px 14px; background: #FAF6EE; border-top: 1px solid #ECE3CF; border-radius: 0 0 10px 10px; }
    .cwl-add-form summary {
        font-size: 10px; font-weight: 700; letter-spacing: .08em;
        text-transform: uppercase; color: #8E8273; cursor: pointer;
    }
    .cwl-add-form input, .cwl-add-form select {
        width: 100%; padding: 5px 8px; font-family: inherit; font-size: 11px;
        border: 1px solid #DFD2B3; border-radius: 5px;
        margin-top: 4px; background: #fff;
    }
    .cwl-add-form button {
        width: 100%; margin-top: 6px; padding: 6px 8px;
        background: #1F1B16; color: #FAF6EE; border: none; border-radius: 5px;
        font-family: inherit; font-size: 11px; font-weight: 700; cursor: pointer;
    }
    .cwl-status {
        margin-top: 6px; font-size: 10px; padding: 3px 6px; border-radius: 4px;
    }
    .cwl-status.ok { background: #e6f4ea; color: #1e4d2b; }
    .cwl-status.err { background: #fde8e8; color: #8A3A2E; }

    /* Notify modal — unchanged */
    .cwl-notify {
        position: fixed; inset: 0; background: rgba(0,0,0,.35); z-index: 1060;
        display: none; align-items: center; justify-content: center;
    }
    .cwl-notify.cwl-notify-open { display: flex; }
    .cwl-notify-body {
        background: #fff; border-radius: 10px; padding: 18px 22px; max-width: 380px; width: 100%;
        font-family: "Inter Tight", system-ui, sans-serif;
    }
    .cwl-notify-body h4 { margin: 0 0 8px; font-size: 15px; }
    .cwl-notify-body p { font-size: 12px; color: #5A5045; }
    .cwl-notify-body label { display: block; font-size: 12px; padding: 4px 0; }
    .cwl-notify-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px; }
    .cwl-btn-cancel {
        background: #fff; border: 1px solid #DFD2B3; color: #5A5045;
        padding: 6px 12px; border-radius: 5px; font-size: 12px; cursor: pointer;
    }
    .cwl-btn-confirm {
        background: #2F6B3E; color: #fff; border: none;
        padding: 6px 12px; border-radius: 5px; font-size: 12px; font-weight: 700; cursor: pointer;
    }
</style>

{{-- Notify modal lives at the bottom of the page (unchanged). --}}
<div class="cwl-notify" id="cwl-notify">
    <div class="cwl-notify-body">
        <h4>Mark "<span id="cwl-notify-label"></span>" as fulfilled?</h4>
        <p>Notify the customer too?</p>
        <label><input type="radio" name="cwl-method" value="none" checked> Don't notify — I'll hand it to them now</label>
        <label><input type="radio" name="cwl-method" value="email"> Email</label>
        <label><input type="radio" name="cwl-method" value="sms"> Text (OpenPhone)</label>
        <label><input type="radio" name="cwl-method" value="both"> Both</label>
        <div class="cwl-notify-actions">
            <button type="button" class="cwl-btn-cancel" id="cwl-notify-cancel">Cancel</button>
            <button type="button" class="cwl-btn-confirm" id="cwl-notify-confirm">Mark fulfilled</button>
        </div>
        <div class="cwl-status" id="cwl-notify-result" style="display:none; margin-top:8px;"></div>
    </div>
</div>

<script>
(function () {
    function onReady(fn) {
        if (typeof jQuery === 'undefined') { setTimeout(function () { onReady(fn); }, 50); return; }
        jQuery(fn);
    }
    onReady(function ($) {
        var CSRF = $('meta[name="csrf-token"]').attr('content');
        var pendingWantId = null;
        var pendingWantLabel = null;

        // Treat the walk-in default customer as "no customer selected" —
        // the snapshot only appears once the cashier has pulled up a real
        // rewards customer.
        function currentContactId() {
            var id = parseInt($('#customer_id').val(), 10);
            var walkIn = parseInt($('#default_customer_id').val(), 10);
            if (!id || id <= 0) return null;
            if (walkIn && id === walkIn) return null;
            return id;
        }

        // Make sure the account-info box is positioned so the absolute
        // panel anchors correctly. Also ensure the trigger + panel DOM
        // exists inside it.
        function ensurePreviewDom() {
            var $box = $('#customer_account_info');
            if (!$box.length) return null;
            if (!$box.css('position') || $box.css('position') === 'static') {
                $box.css('position', 'relative');
            }
            if (!$box.find('.cwl-trigger-row').length) {
                $box.append([
                    '<div class="cwl-trigger-row" id="cwl-trigger-row">',
                    '  <button type="button" class="cwl-trigger-btn" id="cwl-trigger-btn">',
                    '    <span>See wants &amp; purchases</span>',
                    '    <span class="cwl-trigger-badge" id="cwl-trigger-badge" style="display:none;">0</span>',
                    '    <span class="cwl-chev">▾</span>',
                    '  </button>',
                    '  <span class="cwl-trigger-hint" id="cwl-trigger-hint"></span>',
                    '</div>',
                    '<div class="cwl-panel" id="cwl-panel">',
                    '  <div class="cwl-section">',
                    '    <div class="cwl-section-head"><span>Open wish list</span><span class="cwl-count" id="cwl-count">0</span></div>',
                    '    <div id="cwl-list"></div>',
                    '    <div class="cwl-empty" id="cwl-empty" style="display:none;">No open wishes. Ask what they\'re looking for and add it below.</div>',
                    '  </div>',
                    '  <div class="cwl-section">',
                    '    <div class="cwl-section-head"><span>Recent purchases</span><span class="cwl-count" id="cwl-tx-count">0</span></div>',
                    '    <div id="cwl-tx-list"></div>',
                    '    <div class="cwl-empty" id="cwl-tx-empty" style="display:none;">No purchases on file yet.</div>',
                    '  </div>',
                    '  <div class="cwl-section" id="cwl-past-section" style="display:none;">',
                    '    <div class="cwl-section-head"><span>Past wishes</span><span class="cwl-count" id="cwl-past-count">0</span></div>',
                    '    <div id="cwl-past-list"></div>',
                    '  </div>',
                    '  <details class="cwl-add-form">',
                    '    <summary>+ Add a new wish</summary>',
                    '    <input type="text" id="cwl-artist" placeholder="Artist (optional)">',
                    '    <input type="text" id="cwl-title" placeholder="Title *">',
                    '    <select id="cwl-format">',
                    '      <option value="">Format</option>',
                    '      <option value="LP">LP</option>',
                    '      <option value="45">45</option>',
                    '      <option value="CD">CD</option>',
                    '      <option value="Cassette">Cassette</option>',
                    '      <option value="DVD">DVD</option>',
                    '      <option value="Blu-ray">Blu-ray</option>',
                    '    </select>',
                    '    <select id="cwl-priority">',
                    '      <option value="normal">Normal priority</option>',
                    '      <option value="high">High priority</option>',
                    '      <option value="low">Low priority</option>',
                    '    </select>',
                    '    <button type="button" id="cwl-save">Save wish</button>',
                    '    <div class="cwl-status" id="cwl-status" style="display:none;"></div>',
                    '  </details>',
                    '</div>'
                ].join(''));
            }
            return $box;
        }

        function hidePreview() {
            $('#cwl-trigger-row').removeClass('cwl-visible');
            $('#cwl-panel').removeClass('cwl-open');
            $('#cwl-trigger-btn').removeClass('cwl-open');
        }

        function renderActive(active) {
            var $list = $('#cwl-list').empty();
            $('#cwl-count').text(active.length);
            $('#cwl-empty').toggle(active.length === 0);

            active.forEach(function (w) {
                var label = [w.artist, w.title].filter(Boolean).join(' — ');
                if (w.format) label += ' (' + w.format + ')';

                var $row = $('<div class="cwl-item"></div>');
                $row.append($('<div class="cwl-label"></div>').text(label)
                    .append($('<span class="cwl-pri"></span>').addClass(w.priority).text(w.priority))
                );

                if (w.possible_matches && w.possible_matches.length) {
                    var $match = $('<div class="cwl-match"></div>');
                    w.possible_matches.slice(0, 2).forEach(function (m) {
                        var stockNote = (m.total_stock > 0) ? (m.total_stock + ' in stock') : 'out of stock';
                        $match.append(
                            $('<div class="cwl-match-product"></div>')
                                .text(((m.artist || '') + ' — ' + m.name).replace(/^ — /, ''))
                                .append(' <span class="cwl-match-stock">(' + stockNote + ')</span>')
                        );
                    });
                    $row.append($match);
                }

                $row.append($('<button type="button" class="cwl-found">✓ Found it!</button>').on('click', function () {
                    pendingWantId = w.id;
                    pendingWantLabel = label;
                    $('#cwl-notify-label').text(label);
                    $('input[name="cwl-method"][value="none"]').prop('checked', true);
                    $('#cwl-notify-result').hide();
                    $('#cwl-notify').addClass('cwl-notify-open');
                }));
                $list.append($row);
            });
        }

        function renderRecentPurchases(txs) {
            var $list = $('#cwl-tx-list').empty();
            $('#cwl-tx-count').text(txs.length);
            $('#cwl-tx-empty').toggle(txs.length === 0);

            txs.forEach(function (tx) {
                var items = (tx.items || []).map(function (it) {
                    var a = (it.artist || '').trim();
                    var n = (it.name || '').trim();
                    return a ? (a + ' — ' + n) : n;
                }).filter(Boolean);
                var itemsText = items.slice(0, 2).join(', ');
                if (tx.item_count > items.length) itemsText += ' +' + (tx.item_count - items.length) + ' more';
                if (!itemsText) itemsText = (tx.item_count || 0) + ' item' + ((tx.item_count === 1) ? '' : 's');

                var date = (tx.transaction_date || '').substring(0, 10);
                var loc = tx.location_name ? (' · ' + tx.location_name) : '';

                $list.append(
                    $('<div class="cwl-tx"></div>').append(
                        $('<div class="cwl-tx-left"></div>')
                            .append($('<div class="cwl-tx-date"></div>').text(date + loc))
                            .append($('<div class="cwl-tx-items"></div>').text(itemsText)),
                        $('<div class="cwl-tx-total"></div>').text('$' + parseFloat(tx.final_total || 0).toFixed(2))
                    )
                );
            });
        }

        function renderPast(past) {
            $('#cwl-past-section').toggle(past.length > 0);
            $('#cwl-past-count').text(past.length);
            var $list = $('#cwl-past-list').empty();
            past.forEach(function (w) {
                var label = [w.artist, w.title].filter(Boolean).join(' — ');
                if (w.format) label += ' (' + w.format + ')';
                var when = w.fulfilled_at || w.updated_at || w.created_at || '';
                var meta = w.status === 'fulfilled' ? ('✓ ' + when.substring(0, 10)) : ('cancelled ' + when.substring(0, 10));
                $list.append($('<div class="cwl-past-item"></div>').text(label + ' · ' + meta));
            });
        }

        function updateTriggerSummary(active, txs) {
            var badge = active.length;
            $('#cwl-trigger-badge').text(badge).toggle(badge > 0);
            var parts = [];
            if (active.length) parts.push(active.length + ' open wish' + (active.length === 1 ? '' : 'es'));
            if (txs.length) parts.push(txs.length + ' past purchase' + (txs.length === 1 ? '' : 's'));
            $('#cwl-trigger-hint').text(parts.length ? parts.join(' · ') : 'No history yet');
        }

        function reload() {
            ensurePreviewDom();
            var cid = currentContactId();
            if (!cid) { hidePreview(); return; }

            $.get('/customer-wants/for-contact/' + cid).done(function (data) {
                var active = data.active || data.wants || [];
                var past = data.past || [];
                var txs = data.recent_purchases || [];
                renderActive(active);
                renderRecentPurchases(txs);
                renderPast(past);
                updateTriggerSummary(active, txs);
                $('#cwl-trigger-row').addClass('cwl-visible');
            }).fail(hidePreview);
        }

        // Toggle the panel open/closed.
        $(document).on('click', '#cwl-trigger-btn', function (e) {
            e.preventDefault();
            var $panel = $('#cwl-panel');
            var nowOpen = !$panel.hasClass('cwl-open');
            $panel.toggleClass('cwl-open', nowOpen);
            $('#cwl-trigger-btn').toggleClass('cwl-open', nowOpen);
        });

        // Close the panel if you click outside of it.
        $(document).on('click', function (e) {
            if (!$('#cwl-panel').hasClass('cwl-open')) return;
            var $t = $(e.target);
            if ($t.closest('#cwl-panel').length) return;
            if ($t.closest('#cwl-trigger-btn').length) return;
            $('#cwl-panel').removeClass('cwl-open');
            $('#cwl-trigger-btn').removeClass('cwl-open');
        });

        // Save a new wish (same behaviour as before).
        $(document).on('click', '#cwl-save', function () {
            var cid = currentContactId();
            if (!cid) return;
            var title = ($('#cwl-title').val() || '').trim();
            if (!title) { status('cwl-status', 'Title required.', 'err'); return; }
            $.post('/customer-wants/from-pos', {
                _token: CSRF,
                contact_id: cid,
                artist: $('#cwl-artist').val(),
                title: title,
                format: $('#cwl-format').val(),
                priority: $('#cwl-priority').val() || 'normal',
            }).done(function () {
                status('cwl-status', 'Added.', 'ok');
                $('#cwl-artist, #cwl-title').val('');
                reload();
            }).fail(function () { status('cwl-status', 'Failed.', 'err'); });
        });

        // Fulfil-a-wish modal handlers (unchanged).
        $('#cwl-notify-cancel').on('click', function () { $('#cwl-notify').removeClass('cwl-notify-open'); });
        $('#cwl-notify-confirm').on('click', function () {
            if (!pendingWantId) return;
            var method = $('input[name="cwl-method"]:checked').val();
            $.post('/customer-wants/' + pendingWantId + '/fulfill-ajax', {
                _token: CSRF, notify_method: method,
            }).done(function (r) {
                var notifs = r.notifications || {};
                var msgs = Object.keys(notifs).map(function (k) {
                    return (notifs[k].ok ? '✓ ' : '✗ ') + k + ': ' + notifs[k].msg;
                });
                status('cwl-notify-result', msgs.length ? msgs.join(' / ') : '✓ Marked fulfilled.',
                    msgs.every(function (m) { return m.indexOf('✓') === 0; }) ? 'ok' : 'err');
                setTimeout(function () { $('#cwl-notify').removeClass('cwl-notify-open'); reload(); }, 1400);
            }).fail(function () { status('cwl-notify-result', 'Failed. Try again.', 'err'); });
        });

        function status(id, text, cls) {
            var $el = $('#' + id);
            $el.removeClass('ok err').addClass(cls).text(text).show();
            setTimeout(function () { $el.fadeOut(); }, 3500);
        }

        $(document).on('change', '#customer_id', reload);
        $(document).on('customer:loaded customer:cleared', reload);
        setTimeout(reload, 400);
    });
})();
</script>
