{{-- "You May Also Like" — when the cart has records by an artist, this panel
     surfaces other IN-STOCK titles by the same artist(s) so the cashier can
     suggest an add-on at checkout. Purely additive: it reads the cart, asks
     the server for matches, and the Add button reuses pos_product_row() (the
     same path as scanning/searching), so it cannot affect the sell flow.
     Hidden whenever there are no matches. --}}
<style>
    #pos_recommendations { margin-bottom: 14px; }
    .pos-rec-title {
        font-size: 11px; text-transform: uppercase; letter-spacing: 1px;
        color: #92400e; font-weight: 700; margin: 2px 0 8px 2px;
    }
    .pos-rec-card {
        display: flex; align-items: center; justify-content: space-between;
        gap: 10px; background: linear-gradient(135deg, #fffdf5, #fff2b3);
        border: 1px solid #f59e0b; border-radius: 10px;
        padding: 9px 11px; margin-bottom: 8px;
    }
    .pos-rec-info { min-width: 0; }
    .pos-rec-name {
        font-size: 13px; font-weight: 700; color: #78350f;
        line-height: 1.25; white-space: normal; word-break: break-word;
    }
    .pos-rec-meta { font-size: 11px; color: #a16207; margin-top: 2px; }
    .pos-rec-add {
        flex: 0 0 auto; background: #f59e0b; color: #fff;
        border: none; border-radius: 8px; padding: 7px 12px;
        font-size: 12px; font-weight: 800; text-transform: uppercase;
        letter-spacing: 0.3px; cursor: pointer; line-height: 1;
    }
    .pos-rec-add:hover { background: #d97706; }
    .pos-rec-add:disabled { opacity: 0.6; cursor: progress; }
</style>
<div id="pos_recommendations" style="display:none;">
    <div class="pos-rec-title">You May Also Like — In Stock</div>
    <div id="pos_recommendations_body"></div>
</div>
<script>
(function runWhenReady(attempts) {
    // jQuery loads at the bottom of the layout (after @yield('content')), so a
    // bare $(...) here throws and detaches every later handler. Poll-wait first.
    if (typeof jQuery === 'undefined') {
        if ((attempts || 0) > 300) return;
        return setTimeout(function () { runWhenReady((attempts || 0) + 1); }, 50);
    }
    jQuery(function ($) {
        if (!$('#pos_recommendations').length) return;

        // One combined list, fed by three endpoints and interleaved ~half/half:
        //   same-artist  -> more titles by an artist already in the cart
        //   genre        -> other in-stock artists in the same genre (sales tags)
        //   related      -> "customers also bought" different artists, in stock
        // The "different artist" half prefers genre, then fills with co-purchase.
        var SAME_URL    = '/sells/pos/get-recommendations';
        var GENRE_URL   = '/sells/pos/get-genre-artists';
        var RELATED_URL = '/sells/pos/get-related-artists';
        var TOTAL = 8;

        function escapeHtml(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function cartProductIds() {
            var ids = [];
            $('#pos_table tbody tr.product_row .product_id').each(function () {
                var v = parseInt($(this).val(), 10);
                if (!isNaN(v) && v > 0 && ids.indexOf(v) === -1) ids.push(v);
            });
            return ids;
        }

        function render($panel, $body, list) {
            if (!list || !list.length) { $panel.hide(); $body.empty(); return; }
            var html = '';
            list.forEach(function (r) {
                var label = r.artist ? (r.artist + ' - ' + r.product_name) : r.product_name;
                var price = '$' + Number(r.selling_price || 0).toFixed(2);
                html += '<div class="pos-rec-card">' +
                    '<div class="pos-rec-info">' +
                        '<div class="pos-rec-name">' + escapeHtml(label) + '</div>' +
                        '<div class="pos-rec-meta">' + escapeHtml(r.sub_sku || '') + ' &middot; ' + price + '</div>' +
                    '</div>' +
                    '<button type="button" class="pos-rec-add" data-variation_id="' +
                        escapeHtml(r.variation_id) + '">Add</button>' +
                '</div>';
            });
            $body.html(html);
            $panel.show();
        }

        // Interleave the two sources, one from each in turn, deduped by
        // variation, up to TOTAL — roughly an even split, and if one source
        // is thin the other simply fills the remaining slots.
        function interleave(same, related) {
            var out = [], seen = {}, i = 0, j = 0;
            same = same || []; related = related || [];
            while (out.length < TOTAL && (i < same.length || j < related.length)) {
                if (i < same.length) {
                    var s = same[i++];
                    if (s && !seen[s.variation_id]) { seen[s.variation_id] = 1; out.push(s); }
                }
                if (out.length >= TOTAL) break;
                if (j < related.length) {
                    var r = related[j++];
                    if (r && !seen[r.variation_id]) { seen[r.variation_id] = 1; out.push(r); }
                }
            }
            return out;
        }

        function fetchRecs(url, ids, location_id) {
            return $.ajax({
                method: 'GET', url: url, dataType: 'json',
                data: { location_id: location_id, product_ids: ids }
            }).then(function (res) {
                return res && res.success ? res.recommendations : [];
            }, function () {
                // Soft-fail: a recommendations hiccup must never disrupt checkout.
                return [];
            });
        }

        // Concat sources, deduped by variation, preserving order.
        function concatUnique() {
            var out = [], seen = {};
            for (var a = 0; a < arguments.length; a++) {
                var list = arguments[a] || [];
                for (var i = 0; i < list.length; i++) {
                    var r = list[i];
                    if (r && !seen[r.variation_id]) { seen[r.variation_id] = 1; out.push(r); }
                }
            }
            return out;
        }

        function refresh() {
            var ids = cartProductIds();
            var location_id = $('#location_id').val();
            var $panel = $('#pos_recommendations'), $body = $('#pos_recommendations_body');
            if (!ids.length || !location_id) { render($panel, $body, []); return; }
            $.when(
                fetchRecs(SAME_URL, ids, location_id),
                fetchRecs(GENRE_URL, ids, location_id),
                fetchRecs(RELATED_URL, ids, location_id)
            ).done(function (same, genre, related) {
                // Different-artist half = genre first, co-purchase as backup.
                render($panel, $body, interleave(same, concatUnique(genre, related)));
            });
        }

        // Add reuses the canonical product-add path so stock checks, pricing,
        // and totals behave exactly as a scan/search would.
        $(document).on('click', '.pos-rec-add', function () {
            var $btn = $(this);
            if ($btn.prop('disabled')) return;
            var variationId = parseInt($btn.data('variation_id'), 10);
            if (isNaN(variationId)) return;
            $btn.prop('disabled', true);
            try {
                if (typeof pos_product_row === 'function') {
                    pos_product_row(variationId);
                }
            } catch (e) { /* never let an upsell break the sell flow */ }
            // The cart-change observer below will refresh the list (which now
            // excludes the just-added title). Re-enable as a fallback.
            setTimeout(function () { $btn.prop('disabled', false); }, 600);
        });

        // Refresh whenever the cart changes, debounced so rapid edits coalesce.
        var debounce = null;
        function scheduleRefresh() {
            clearTimeout(debounce);
            debounce = setTimeout(refresh, 500);
        }
        var tbody = document.querySelector('#pos_table tbody');
        if (tbody && typeof MutationObserver !== 'undefined') {
            new MutationObserver(scheduleRefresh).observe(tbody, { childList: true, subtree: true });
        }
        scheduleRefresh();
    });
})(0);
</script>
