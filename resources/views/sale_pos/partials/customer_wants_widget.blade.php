{{-- ===========================================================
     Customer Wish List — slim floating panel

     Sits OUTSIDE the main POS flow as a fixed-position panel on the
     left edge of the screen. Only shows up when a rewards customer is
     pulled up AND they have at least one open wish-list item. Zero
     impact on the main cart/checkout area and zero impact on the
     Quick Add tiles on the right.

     Sarah's ask (2026-04-21 12:55 AM): "maybe a very slim pop up on
     the left of the cart if u pull up a customer and they have a wish
     list. if u dont pull up a customer nothing happens."
     ============================================================ --}}

<style>
    /* Floating slim panel pinned to the LEFT edge of the screen. Positioned
       so it sits to the LEFT of the cart area. When the customer has no
       wants, the panel never renders at all — zero layout impact. */
    .cwl-panel {
        position: fixed;
        left: 0;
        top: 180px;
        width: 240px;
        max-height: calc(100vh - 220px);
        z-index: 1030;
        background: #fff;
        border: 1px solid #ECE3CF;
        border-left: 4px solid #E8CF68;
        border-radius: 0 10px 10px 0;
        box-shadow: 2px 4px 14px rgba(31,27,22,0.08);
        font-family: "Inter Tight", system-ui, sans-serif;
        font-size: 12px;
        display: none;
        transform: translateX(-100%);
        transition: transform .2s ease-out;
    }
    .cwl-panel.cwl-open {
        display: block;
        transform: translateX(0);
    }
    .cwl-head {
        display: flex; align-items: center; justify-content: space-between;
        padding: 10px 12px; border-bottom: 1px dashed #DFD2B3;
        background: #FFF9DB;
        border-radius: 0 10px 0 0;
    }
    .cwl-title {
        font-size: 11px; font-weight: 800; letter-spacing: .12em;
        text-transform: uppercase; color: #5A4410;
    }
    .cwl-head-right { display: flex; align-items: center; gap: 8px; }
    .cwl-count {
        background: #FFF2B3; color: #5A4410;
        font-size: 10px; font-weight: 800; padding: 2px 7px; border-radius: 999px;
    }
    .cwl-close {
        background: none; border: none; color: #8E8273; cursor: pointer;
        font-size: 16px; line-height: 1; padding: 0 2px;
    }
    .cwl-close:hover { color: #1F1B16; }
    .cwl-body {
        max-height: calc(100vh - 280px); overflow-y: auto;
        padding: 8px 12px;
    }
    .cwl-item {
        padding: 8px 0; border-bottom: 1px solid #F7F1E3;
    }
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
    .cwl-empty { padding: 12px 0; font-size: 11px; color: #8E8273; text-align: center; }
    .cwl-past {
        margin-top: 10px; padding-top: 8px; border-top: 1px dashed #DFD2B3;
    }
    .cwl-past-head {
        font-size: 10px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
        color: #8E8273; cursor: pointer; padding: 2px 0;
    }
    .cwl-past-list { display: none; margin-top: 4px; }
    .cwl-panel.cwl-past-open .cwl-past-list { display: block; }
    .cwl-past-item {
        padding: 3px 0; font-size: 11px; color: #8E8273;
        overflow: hidden; text-overflow: ellipsis;
    }
    .cwl-add {
        padding: 8px 12px; border-top: 1px solid #ECE3CF;
        background: #FAF6EE; border-radius: 0 0 10px 0;
    }
    .cwl-add summary {
        font-size: 10px; font-weight: 700; letter-spacing: .08em;
        text-transform: uppercase; color: #8E8273; cursor: pointer;
    }
    .cwl-add input, .cwl-add select {
        width: 100%; padding: 5px 8px; font-family: inherit; font-size: 11px;
        border: 1px solid #DFD2B3; border-radius: 5px;
        margin-top: 4px; background: #fff;
    }
    .cwl-add button {
        width: 100%; margin-top: 6px; padding: 5px 8px;
        background: #1F1B16; color: #FAF6EE; border: none; border-radius: 5px;
        font-family: inherit; font-size: 11px; font-weight: 700; cursor: pointer;
    }
    .cwl-status {
        margin-top: 6px; font-size: 10px; padding: 3px 6px; border-radius: 4px;
    }
    .cwl-status.ok { background: #e6f4ea; color: #1e4d2b; }
    .cwl-status.err { background: #fde8e8; color: #8A3A2E; }

    /* Notify modal — only appears when Found it! is clicked. Overlay covers
       the screen but the underlying POS stays interactive once closed. */
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

{{-- The panel itself. Lives outside the main POS form so no chance of
     breaking the grid/column layout. Hidden by default; shown only when a
     customer with at least one active want is loaded. --}}
<div class="cwl-panel" id="cwl-panel">
    <div class="cwl-head">
        <span class="cwl-title">Customer Wish List</span>
        <span class="cwl-head-right">
            <span class="cwl-count" id="cwl-count">0</span>
            <button type="button" class="cwl-close" id="cwl-close" title="Dismiss for this customer">×</button>
        </span>
    </div>

    <div class="cwl-body">
        <div id="cwl-list"></div>
        <div class="cwl-empty" id="cwl-empty" style="display:none;">No open wishes.</div>

        <div class="cwl-past" id="cwl-past" style="display:none;">
            <div class="cwl-past-head" id="cwl-past-head">Past wishes (<span id="cwl-past-count">0</span>) ▾</div>
            <div class="cwl-past-list" id="cwl-past-list"></div>
        </div>
    </div>

    <details class="cwl-add">
        <summary>+ Add a new wish</summary>
        <input type="text" id="cwl-artist" placeholder="Artist (optional)">
        <input type="text" id="cwl-title" placeholder="Title *">
        <select id="cwl-format">
            <option value="">Format</option>
            <option value="LP">LP</option>
            <option value="45">45</option>
            <option value="CD">CD</option>
            <option value="Cassette">Cassette</option>
            <option value="DVD">DVD</option>
            <option value="Blu-ray">Blu-ray</option>
        </select>
        <select id="cwl-priority">
            <option value="normal">Normal priority</option>
            <option value="high">High priority</option>
            <option value="low">Low priority</option>
        </select>
        <button type="button" id="cwl-save">Save wish</button>
        <div class="cwl-status" id="cwl-status" style="display:none;"></div>
    </details>
</div>

{{-- Notify modal — unchanged logic from before. --}}
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
        var dismissedForContact = null;    // so "close" stays closed until customer changes
        var pendingWantId = null;
        var pendingWantLabel = null;

        function currentContactId() {
            var id = $('#customer_id').val();
            return (id && parseInt(id, 10) > 0) ? parseInt(id, 10) : null;
        }
        function hide() { $('#cwl-panel').removeClass('cwl-open'); }
        function show() { $('#cwl-panel').addClass('cwl-open'); }

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

        function renderPast(past) {
            $('#cwl-past').toggle(past.length > 0);
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

        function reload() {
            var cid = currentContactId();
            if (!cid) { hide(); dismissedForContact = null; return; }
            if (dismissedForContact === cid) { return; }  // cashier closed it, respect that until customer changes
            $.get('/customer-wants/for-contact/' + cid).done(function (data) {
                var active = data.active || data.wants || [];
                var past = data.past || [];
                renderActive(active);
                renderPast(past);
                if (active.length > 0 || past.length > 0) {
                    show();
                } else {
                    hide();
                }
            }).fail(hide);
        }

        $(document).on('click', '#cwl-close', function () {
            var cid = currentContactId();
            dismissedForContact = cid;
            hide();
        });
        $(document).on('click', '#cwl-past-head', function () {
            $('#cwl-panel').toggleClass('cwl-past-open');
        });
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
