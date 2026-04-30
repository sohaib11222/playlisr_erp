{{-- Sarah 2026-04-30: "Recently rung up" panel + soft duplicate warning.
     Polls /sells/pos/recent-rings every 30s, lists last 30min of rings at
     this location, and flashes a non-blocking banner when the cashier
     adds a product+price combo that was already rung up in the last
     5 minutes.

     ALL behaviour is wrapped in try/catch and lazy jQuery checks — if
     anything in here throws or 404s, the POS sell flow keeps working.
     This is a side-channel, not part of the cart. --}}
{{-- position:fixed so it floats in the tan area to the left of the cart
     box without touching the locked POS column layout. Hidden on narrow
     screens so it doesn't overlap the form. --}}
<div id="recent_rings_panel"
     style="position:fixed; top:96px; left:10px; width:200px; z-index:50;
            background:#fffaf0; border:1px solid #d4a574; border-radius:10px;
            padding:8px 10px; font-size:12px; box-shadow:0 1px 3px rgba(0,0,0,0.06);">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">
        <div style="font-size:10px; text-transform:uppercase; letter-spacing:1px; color:#7c2d12; font-weight:700;">
            <i class="fa fa-history"></i> Recently rung up
        </div>
        <a href="#" id="rr_refresh" style="font-size:10px; color:#0ea5e9; text-decoration:none;">refresh</a>
    </div>
    <div id="rr_list"></div>
    <div id="rr_empty" style="color:#94a3b8; font-style:italic; font-size:11px;">Loading…</div>
</div>
{{-- Hide on narrow viewports so the widget can never overlap the cart.
     2026-04-30: at 100% zoom on 1200–1500px screens the cart spans the
     whole .content-wrapper, so the fixed widget was painting on top of
     the customer/search inputs. Reserve 220px of left padding on the
     POS section whenever the panel is visible so the cart shifts right
     to clear it. The padding is scoped to body.pos-v2 section.content
     so no other ERP page is touched. --}}
<style>
    @media (max-width: 1199.98px) {
        #recent_rings_panel { display: none !important; }
    }
    @media (min-width: 1200px) {
        body.pos-v2 section.content { padding-left: 220px; }
    }
</style>

<div id="rr_dup_banner"
     style="display:none; position:fixed; top:14px; left:50%; transform:translateX(-50%); z-index:9999;
            background:#fff7ed; border:2px solid #f97316; color:#7c2d12;
            border-radius:10px; padding:12px 18px; box-shadow:0 6px 18px rgba(0,0,0,.15);
            font-size:14px; max-width:540px;">
    <div style="font-weight:800; font-size:15px; margin-bottom:4px;">
        <i class="fa fa-exclamation-triangle"></i> Are you sure? You just rang this up.
    </div>
    <div id="rr_dup_msg" style="margin-bottom:8px;"></div>
    <div>
        <button type="button" id="rr_dup_keep"
                style="background:#fff; border:1px solid #f97316; color:#7c2d12; padding:5px 12px; border-radius:6px; font-weight:600; cursor:pointer;">
            Keep it
        </button>
        <button type="button" id="rr_dup_dismiss"
                style="background:transparent; border:none; color:#7c2d12; padding:5px 8px; cursor:pointer; font-size:12px;">
            dismiss
        </button>
    </div>
</div>

<script>
(function rrInit(attempts){
    if (typeof jQuery === 'undefined') {
        if ((attempts || 0) > 300) return;
        return setTimeout(function(){ rrInit((attempts||0)+1); }, 20);
    }

    jQuery(function ($) {
        try {
            var $panel  = $('#recent_rings_panel');
            var $list   = $('#rr_list');
            var $empty  = $('#rr_empty');
            var $banner = $('#rr_dup_banner');
            var $msg    = $('#rr_dup_msg');

            // Cached recent-rings list (other transactions). The cart itself
            // is read live from the DOM each time we check, so "already in
            // this cart" detection works on the current state.
            var rings = [];

            function locationId() {
                var v = $('#location_id').val();
                return v ? parseInt(v, 10) : null;
            }

            function fmtAgo(unix, nowUnix) {
                var diff = Math.max(0, (nowUnix || Math.floor(Date.now()/1000)) - unix);
                if (diff < 60)  return diff + 's ago';
                var m = Math.floor(diff/60);
                if (m < 60)     return m + ' min ago';
                var h = Math.floor(m/60);
                return h + 'h ' + (m%60) + 'm ago';
            }

            function fmtMoney(n) {
                var v = parseFloat(n);
                if (isNaN(v)) return '';
                return '$' + v.toFixed(2);
            }

            function escapeHtml(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                    .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
            }

            function render(now_unix) {
                if (!rings || !rings.length) {
                    $list.empty();
                    $empty.show();
                    return;
                }
                $empty.hide();
                var html = '';
                // Cap UI to 8 entries — small widget, not a novel. Older
                // rings still live in `rings[]` so the duplicate check can
                // still match them.
                for (var i = 0; i < rings.length && i < 8; i++) {
                    var r = rings[i];
                    var artistLine = r.artist
                        ? '<div style="color:#64748b; font-size:11px; font-style:italic; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">'
                          + escapeHtml(r.artist) + '</div>'
                        : '';
                    html += '<div style="display:flex; justify-content:space-between; gap:8px; padding:6px 0; border-bottom:1px dashed #e2e8f0;">'
                        +    '<div style="flex:1; min-width:0;">'
                        +      '<div style="font-weight:600; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">'
                        +        escapeHtml(r.product_name)
                        +      '</div>'
                        +      artistLine
                        +      '<div style="color:#64748b; font-size:11px;">'
                        +        escapeHtml(r.cashier_name || 'Unknown') + ' · ' + fmtAgo(r.ts_unix, now_unix)
                        +        (r.invoice_no ? ' · ' + escapeHtml(r.invoice_no) : '')
                        +      '</div>'
                        +    '</div>'
                        +    '<div style="text-align:right; white-space:nowrap;">'
                        +      '<div style="font-weight:700; color:#0f172a;">' + fmtMoney(r.unit_price) + '</div>'
                        +      '<div style="color:#64748b; font-size:11px;">qty ' + (r.quantity || 1) + '</div>'
                        +    '</div>'
                        +  '</div>';
                }
                $list.html(html);
            }

            function fetchRings() {
                var loc = locationId();
                if (!loc) {
                    $empty.text('No active location.');
                    return;
                }
                $.ajax({
                    method: 'GET',
                    url: '/sells/pos/recent-rings',
                    data: { location_id: loc, minutes: 30 },
                    dataType: 'json',
                    timeout: 6000
                }).done(function(resp){
                    try {
                        rings = (resp && resp.rings) ? resp.rings : [];
                        if (!rings.length) {
                            $empty.text('No sales yet in this window.');
                        }
                        render(resp && resp.now_unix);
                    } catch (e) {
                        $empty.text('Recent rings unavailable.');
                    }
                }).fail(function(xhr){
                    $empty.text('Recent rings unavailable (' + (xhr ? xhr.status : '?') + ').');
                });
            }

            function showDupBanner(text) {
                $msg.html(text);
                $banner.stop(true,true).fadeIn(150);
                window.clearTimeout(showDupBanner._t);
                showDupBanner._t = window.setTimeout(function(){
                    $banner.fadeOut(200);
                }, 12000);
            }

            $('#rr_dup_dismiss, #rr_dup_keep').on('click', function(){
                $banner.fadeOut(150);
            });

            $('#rr_refresh').on('click', function(e){ e.preventDefault(); fetchRings(); });

            // Watch for new product rows being inserted into the cart so we
            // can match them against the cached recent rings list. Using
            // MutationObserver instead of editing pos.js — keeps the sell
            // flow untouched.
            function readRowSnapshot($row) {
                var vid = $row.find('.row_variation_id').val() || null;
                var rowPriceStr = $row.find('input.pos_unit_price_inc_tax').val()
                               || $row.find('input.pos_unit_price').val() || '';
                var rowPrice = parseFloat(String(rowPriceStr).replace(/[^0-9.\-]/g,''));

                // Pull a name even for "manual product" rows (Quick-add tiles
                // like Water/Soda — no variation_id but they DO carry a
                // product_name input or visible label).
                var productName = $row.find('.product_name, .product-name').first().text().trim();
                if (!productName) {
                    productName = ($row.find('input[name*="product_name"]').first().val() || '').trim();
                }
                if (!productName) {
                    // Last resort: any text in the first cell.
                    productName = $row.find('td').first().text().replace(/\s+/g,' ').trim().slice(0, 80);
                }

                return { vid: vid, name: productName, price: rowPrice };
            }

            function findRingMatch(snap) {
                if (!rings || !rings.length) return null;
                var nowS = Math.floor(Date.now()/1000);
                var nameKey = (snap.name || '').toLowerCase();
                // Skip obvious non-product lines.
                if (!nameKey || nameKey.indexOf('bag fee') !== -1) return null;

                for (var i = 0; i < rings.length; i++) {
                    var r = rings[i];
                    var ageSec = Math.max(0, nowS - r.ts_unix);
                    if (ageSec > 5 * 60) continue;
                    var idMatch = snap.vid && r.variation_id && String(r.variation_id) === String(snap.vid);
                    var nameMatch = !idMatch && r.product_name &&
                                    r.product_name.toLowerCase() === nameKey;
                    if (!idMatch && !nameMatch) continue;
                    var match = r;
                    match._priceDelta = (!isNaN(snap.price) && r.unit_price)
                        ? Math.abs(snap.price - r.unit_price) : null;
                    return match;
                }
                return null;
            }

            function showRingMatchBanner(match, snap) {
                var ageMin = Math.max(1, Math.round((Date.now()/1000 - match.ts_unix)/60));
                var line = '<b>' + escapeHtml(match.product_name) + '</b> was rung up '
                    + ageMin + ' min ago at ' + fmtMoney(match.unit_price)
                    + ' by ' + escapeHtml(match.cashier_name || 'someone')
                    + (match.invoice_no ? ' (' + escapeHtml(match.invoice_no) + ')' : '')
                    + '.';
                if (match._priceDelta !== null && match._priceDelta > 0.05) {
                    line += ' This add is ' + fmtMoney(snap.price) + ' — different price.';
                }
                line += '<br><span style="color:#9a3412; font-size:12px;">'
                      + 'If this is a different copy, click Keep it. Otherwise remove the line so the customer isn\'t charged twice.</span>';
                showDupBanner(line);
            }

            function checkForDuplicate($row) {
                try {
                    var snap = readRowSnapshot($row);

                    // Bag fees are not "duplicates" worth warning about.
                    if (snap.name && snap.name.toLowerCase().indexOf('bag fee') !== -1) return;

                    // (1) Already in THIS cart? Manolo's case — same item
                    //     entered as multiple lines on one sale. Match on
                    //     variation_id when present, otherwise on product
                    //     name + same price.
                    var $allRows = $('table#pos_table tbody tr.product_row');
                    var sameCount = 0;
                    $allRows.each(function(){
                        var $r = $(this);
                        if ($r[0] === $row[0]) { sameCount++; return; }
                        var rs = readRowSnapshot($r);
                        var idMatch = snap.vid && rs.vid && String(rs.vid) === String(snap.vid);
                        var nameMatch = !idMatch && snap.name && rs.name &&
                                        rs.name.toLowerCase() === snap.name.toLowerCase();
                        if (idMatch || nameMatch) { sameCount++; }
                    });
                    if (sameCount > 1) {
                        var msg = '<b>' + escapeHtml(snap.name || 'This item') + '</b> is already in this cart'
                            + (!isNaN(snap.price) ? ' (this add: ' + fmtMoney(snap.price) + ')' : '')
                            + '.<br><span style="color:#9a3412; font-size:12px;">'
                            + 'If this is a different copy, click Keep it. Otherwise remove the duplicate line so the customer isn\'t charged twice.</span>';
                        showDupBanner(msg);
                        return;
                    }

                    // (2) Recently rung up on a different sale at this location?
                    var match = findRingMatch(snap);
                    if (match) {
                        showRingMatchBanner(match, snap);
                        return;
                    }

                    // (3) Cache miss — the previous sale may have completed
                    //     between the last fetchRings tick and this add.
                    //     Refetch fresh and try once more so a Water → save →
                    //     Water-again sequence within ~30s still fires.
                    var loc = locationId();
                    if (!loc) return;
                    $.ajax({
                        method: 'GET',
                        url: '/sells/pos/recent-rings',
                        data: { location_id: loc, minutes: 30 },
                        dataType: 'json',
                        timeout: 4000
                    }).done(function(resp){
                        try {
                            rings = (resp && resp.rings) ? resp.rings : rings;
                            render(resp && resp.now_unix);
                            var freshMatch = findRingMatch(snap);
                            if (freshMatch) showRingMatchBanner(freshMatch, snap);
                        } catch (e) { /* swallow */ }
                    });
                } catch (e) { /* swallow */ }
            }

            var tbody = document.querySelector('table#pos_table tbody');
            if (tbody && typeof MutationObserver !== 'undefined') {
                var obs = new MutationObserver(function(mutations){
                    try {
                        mutations.forEach(function(m){
                            (m.addedNodes || []).forEach(function(node){
                                if (node.nodeType !== 1) return;
                                var $node = $(node);
                                if ($node.is('tr.product_row')) {
                                    // Wait a tick for pos.js to populate price fields
                                    setTimeout(function(){ checkForDuplicate($node); }, 60);
                                }
                            });
                        });
                    } catch (e) { /* swallow */ }
                });
                obs.observe(tbody, { childList: true });
            }

            // Refetch on location change so a register/store switch rebuilds the panel.
            $(document).on('change', '#location_id, select#select_location_id', function(){
                fetchRings();
            });

            fetchRings();
            window.setInterval(fetchRings, 30000);
        } catch (e) {
            // Defensive: if anything in this whole panel blows up, swallow it
            // so the POS screen continues to work.
            if (window.console) console.warn('recent_rings panel init failed', e);
        }
    });
})();
</script>
