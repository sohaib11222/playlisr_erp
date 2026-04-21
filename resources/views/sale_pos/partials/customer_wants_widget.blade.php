{{-- ===========================================================
     Customer Wants widget for POS sidebar.

     Renders under the Nivessa Bucks customer panel whenever a rewards
     account is loaded. Shows the customer's active wish list + which of
     their wants we currently have in stock (cheap LIKE match). The
     cashier can:
       - Click "Found it!" → mark fulfilled + optionally notify by
         email, SMS (via OpenPhone), or both.
       - Add a new want inline without leaving the POS.

     Hidden when no customer is selected.
     ============================================================ --}}
<style>
    .cw-widget {
        background: #fff; border: 1px solid #ECE3CF; border-radius: 12px;
        padding: 14px 16px; margin-bottom: 14px; box-shadow: 0 1px 3px rgba(31,27,22,0.05);
    }
    .cw-widget .cw-head {
        display: flex; align-items: center; justify-content: space-between; gap: 8px;
        padding-bottom: 10px; border-bottom: 1px dashed #DFD2B3; margin-bottom: 10px;
    }
    .cw-widget .cw-title {
        font-size: 12px; text-transform: uppercase; letter-spacing: .12em; font-weight: 700; color: #5A5045;
    }
    .cw-widget .cw-count {
        background: #FFF2B3; color: #5A4410; font-size: 11px; font-weight: 700;
        padding: 2px 8px; border-radius: 999px;
    }
    .cw-widget .cw-empty { font-size: 12px; color: #8E8273; padding: 6px 0; }
    .cw-item {
        padding: 10px 0; border-bottom: 1px solid #F7F1E3;
    }
    .cw-item:last-child { border-bottom: none; }
    .cw-item .cw-label { font-size: 13px; font-weight: 600; color: #1F1B16; }
    .cw-item .cw-sub { font-size: 11px; color: #8E8273; margin-top: 2px; }
    .cw-item .cw-pri { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase; margin-left: 6px; vertical-align: middle; }
    .cw-item .cw-pri.high { background: #fde8e8; color: #8A3A2E; }
    .cw-item .cw-pri.normal { background: #F7F1E3; color: #5A5045; }
    .cw-item .cw-pri.low { background: #f3f4f6; color: #8E8273; }
    .cw-match {
        margin-top: 6px; padding: 8px 10px; background: #FFF9DB; border: 1px solid #E8CF68; border-radius: 8px;
    }
    .cw-match-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #5A4410; }
    .cw-match-product { font-size: 12px; color: #1F1B16; margin-top: 2px; font-weight: 600; }
    .cw-match-stock { font-size: 10px; color: #2F6B3E; font-weight: 700; }
    .cw-actions { margin-top: 8px; display: flex; flex-wrap: wrap; gap: 6px; }
    .cw-btn {
        font-family: inherit; font-size: 11px; font-weight: 700;
        padding: 5px 10px; border-radius: 6px; cursor: pointer;
        border: 1px solid transparent;
    }
    .cw-btn-found { background: #2F6B3E; color: #fff; }
    .cw-btn-found:hover { background: #265732; }
    .cw-btn-muted { background: #fff; color: #5A5045; border-color: #DFD2B3; }
    .cw-btn-muted:hover { background: #F7F1E3; }
    .cw-add-form {
        margin-top: 12px; padding-top: 12px; border-top: 1px dashed #DFD2B3;
    }
    .cw-add-form input, .cw-add-form select {
        width: 100%; padding: 6px 8px; font-family: inherit; font-size: 12px;
        border: 1px solid #DFD2B3; border-radius: 6px; margin-bottom: 6px; background: #fff;
    }
    .cw-notify-modal {
        position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 1060;
        display: flex; align-items: center; justify-content: center;
    }
    .cw-notify-modal .cw-notify-body {
        background: #fff; border-radius: 12px; padding: 20px 24px; max-width: 420px; width: 100%;
    }
    .cw-notify-modal h4 { margin: 0 0 10px; font-size: 16px; }
    .cw-notify-modal label { display: block; margin: 8px 0; font-size: 13px; }
    .cw-status {
        margin-top: 6px; font-size: 11px; padding: 4px 8px; border-radius: 6px;
    }
    .cw-status.ok { background: #e6f4ea; color: #1e4d2b; }
    .cw-status.err { background: #fde8e8; color: #8A3A2E; }
</style>

<div class="cw-widget" id="pos-cw-widget" style="display:none;">
    <div class="cw-head">
        <span class="cw-title">Customer Wish List</span>
        <span class="cw-count" id="pos-cw-count">0</span>
    </div>

    <div id="pos-cw-list"></div>
    <div class="cw-empty" id="pos-cw-empty" style="display:none;">No open wants. Add one below to track what they're looking for.</div>

    {{-- Quick-add form --}}
    <div class="cw-add-form">
        <div style="font-size:11px; color:#8E8273; font-weight:600; margin-bottom:6px;">Add a new want</div>
        <input type="text" id="pos-cw-artist" placeholder="Artist (optional)">
        <input type="text" id="pos-cw-title" placeholder="Title *">
        <select id="pos-cw-format">
            <option value="">Format (optional)</option>
            <option value="LP">LP</option>
            <option value="45">45</option>
            <option value="CD">CD</option>
            <option value="Cassette">Cassette</option>
            <option value="DVD">DVD</option>
            <option value="Blu-ray">Blu-ray</option>
        </select>
        <select id="pos-cw-priority">
            <option value="normal">Normal priority</option>
            <option value="high">High priority</option>
            <option value="low">Low priority</option>
        </select>
        <button type="button" class="cw-btn cw-btn-muted" id="pos-cw-save" style="width:100%;">+ Add to wish list</button>
        <div class="cw-status" id="pos-cw-status" style="display:none;"></div>
    </div>
</div>

{{-- Notification modal — appears when "Found it!" is clicked --}}
<div class="cw-notify-modal" id="pos-cw-notify-modal" style="display:none;">
    <div class="cw-notify-body">
        <h4>Notify customer?</h4>
        <p style="font-size:13px; color:#5A5045;">You found <strong id="pos-cw-notify-label"></strong> for them.</p>
        <label><input type="radio" name="pos-cw-notify" value="none" checked> Don't notify — I'll hand it to them now</label>
        <label><input type="radio" name="pos-cw-notify" value="email"> Email them</label>
        <label><input type="radio" name="pos-cw-notify" value="sms"> Text them (OpenPhone)</label>
        <label><input type="radio" name="pos-cw-notify" value="both"> Both — email + text</label>
        <div style="margin-top:14px; display:flex; gap:8px; justify-content:flex-end;">
            <button type="button" class="cw-btn cw-btn-muted" id="pos-cw-notify-cancel">Cancel</button>
            <button type="button" class="cw-btn cw-btn-found" id="pos-cw-notify-confirm">Mark fulfilled</button>
        </div>
        <div class="cw-status" id="pos-cw-notify-result" style="display:none; margin-top:10px;"></div>
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

        function currentContactId() {
            // POS stores the selected contact id on the customer_id Select2.
            var id = $('#customer_id').val();
            return (id && parseInt(id, 10) > 0) ? parseInt(id, 10) : null;
        }

        function hide() {
            $('#pos-cw-widget').hide();
        }

        function show() {
            $('#pos-cw-widget').show();
        }

        function renderWants(wants) {
            var $list = $('#pos-cw-list').empty();
            $('#pos-cw-count').text(wants.length);
            $('#pos-cw-empty').toggle(wants.length === 0);

            wants.forEach(function (w) {
                var label = [w.artist, w.title].filter(Boolean).join(' — ');
                if (w.format) label += ' (' + w.format + ')';

                var $row = $('<div class="cw-item"></div>');
                var $label = $('<div class="cw-label"></div>').text(label);
                $label.append($('<span class="cw-pri"></span>').addClass(w.priority).text(w.priority));
                $row.append($label);

                if (w.notes) {
                    $row.append($('<div class="cw-sub"></div>').text(w.notes));
                }

                // Match block — only shown if we found candidate products in stock.
                if (w.possible_matches && w.possible_matches.length) {
                    var $match = $('<div class="cw-match"></div>');
                    $match.append('<div class="cw-match-label">We might have this</div>');
                    w.possible_matches.slice(0, 3).forEach(function (m) {
                        var stockLabel = (m.total_stock > 0) ? (m.total_stock + ' in stock') : 'but none in stock right now';
                        $match.append(
                            $('<div class="cw-match-product"></div>')
                                .text(((m.artist || '') + ' — ' + m.name).replace(/^ — /, ''))
                                .append(' <span class="cw-match-stock">(' + stockLabel + ')</span>')
                        );
                    });
                    $row.append($match);
                }

                var $actions = $('<div class="cw-actions"></div>');
                $actions.append($('<button type="button" class="cw-btn cw-btn-found"></button>')
                    .text('✓ Found it!')
                    .on('click', function () {
                        pendingWantId = w.id;
                        pendingWantLabel = label;
                        $('#pos-cw-notify-label').text(label);
                        $('input[name="pos-cw-notify"][value="none"]').prop('checked', true);
                        $('#pos-cw-notify-result').hide();
                        $('#pos-cw-notify-modal').css('display', 'flex');
                    }));
                $row.append($actions);
                $list.append($row);
            });
        }

        function reload() {
            var cid = currentContactId();
            if (!cid) { hide(); return; }
            show();
            $.get('/customer-wants/for-contact/' + cid).done(function (data) {
                renderWants(data.wants || []);
            }).fail(function () {
                $('#pos-cw-list').html('<div class="cw-empty">Couldn\'t load wants. Try refreshing.</div>');
            });
        }

        // Add-to-wish-list handler
        $(document).on('click', '#pos-cw-save', function () {
            var cid = currentContactId();
            if (!cid) {
                showStatus('pos-cw-status', 'Select a customer first.', 'err');
                return;
            }
            var title = ($('#pos-cw-title').val() || '').trim();
            if (!title) {
                showStatus('pos-cw-status', 'Title is required.', 'err');
                return;
            }
            $.post('/customer-wants/from-pos', {
                _token: CSRF,
                contact_id: cid,
                artist: $('#pos-cw-artist').val(),
                title: title,
                format: $('#pos-cw-format').val(),
                priority: $('#pos-cw-priority').val() || 'normal',
            }).done(function () {
                showStatus('pos-cw-status', 'Added.', 'ok');
                $('#pos-cw-artist, #pos-cw-title').val('');
                reload();
            }).fail(function (xhr) {
                var m = (xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.error)) || 'Failed to add.';
                showStatus('pos-cw-status', m, 'err');
            });
        });

        // Notify modal handlers
        $('#pos-cw-notify-cancel').on('click', function () {
            $('#pos-cw-notify-modal').hide();
        });
        $('#pos-cw-notify-confirm').on('click', function () {
            if (!pendingWantId) return;
            var method = $('input[name="pos-cw-notify"]:checked').val();
            $.post('/customer-wants/' + pendingWantId + '/fulfill-ajax', {
                _token: CSRF,
                notify_method: method,
            }).done(function (r) {
                var notifs = r.notifications || {};
                var messages = [];
                Object.keys(notifs).forEach(function (k) {
                    messages.push((notifs[k].ok ? '✓ ' : '✗ ') + k + ': ' + notifs[k].msg);
                });
                if (messages.length === 0) messages.push('✓ Marked fulfilled.');
                showStatus('pos-cw-notify-result', messages.join(' / '), messages.every(function (m) { return m.indexOf('✓') === 0; }) ? 'ok' : 'err');
                setTimeout(function () {
                    $('#pos-cw-notify-modal').hide();
                    reload();
                }, 1500);
            }).fail(function () {
                showStatus('pos-cw-notify-result', 'Mark-fulfilled failed — try again.', 'err');
            });
        });

        function showStatus(id, text, cls) {
            var $el = $('#' + id);
            $el.removeClass('ok err').addClass(cls).text(text).show();
            setTimeout(function () { $el.fadeOut(); }, 4000);
        }

        // Reload whenever a customer is selected / changed / cleared.
        $(document).on('change', '#customer_id', reload);
        // Also hook into the explicit "select customer" event that pos.js fires.
        $(document).on('customer:loaded customer:cleared', reload);

        // Initial render in case a customer is already loaded on page load.
        setTimeout(reload, 400);
    });
})();
</script>
