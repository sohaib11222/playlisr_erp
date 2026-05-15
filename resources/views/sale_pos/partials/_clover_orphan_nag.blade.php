{{-- Luis's idea, Sarah 2026-05-15: real-time nag on /pos when Clover
     swiped a sale in the last 5 min that doesn't have a matching ERP
     ring. Floats on the right edge so it sits next to the cart without
     touching the existing "Recently rung up" widget on the left.

     Auto-clears the moment the matching ERP sale exists (next poll).
     Polling endpoint = /sells/pos/clover-orphans-recent — defaults to
     last 5 minutes, scoped to the cashier's current location. --}}
<div id="clover_orphan_nag"
     style="position:fixed; top:96px; right:10px; width:280px; z-index:51;
            background:#fff7ed; border:2px solid #f97316; border-radius:10px;
            padding:10px 12px; font-size:12px; box-shadow:0 4px 12px rgba(249,115,22,.25);
            display:none;">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
        <div style="font-size:10px; text-transform:uppercase; letter-spacing:1px; color:#7c2d12; font-weight:800;">
            <i class="fa fa-exclamation-triangle"></i> <span id="con_count">0</span> Clover swipe<span id="con_plural">s</span> unrung
        </div>
        <a href="#" id="con_refresh" style="font-size:10px; color:#0ea5e9; text-decoration:none;">refresh</a>
    </div>
    <div id="con_blurb" style="font-size:11px; color:#7c2d12; margin-bottom:6px; line-height:1.35;">
        Cashier swiped a card on Clover but the ERP ring hasn't gone in yet. Ring the item now so inventory + reports stay accurate.
    </div>
    <div id="con_list"></div>
</div>

<style>
    /* Hide the nag on narrow viewports — the cart needs the full width
       there. The bottom-right corner is safer than the left because
       the existing "Recently rung up" widget already lives at left:10px. */
    @media (max-width: 1199.98px) {
        #clover_orphan_nag { display: none !important; }
    }
    .con-row { padding:8px 0; border-top:1px dashed #fed7aa; font-size:12px; color:#7c2d12; }
    .con-row:first-child { border-top:none; padding-top:4px; }
    .con-row .con-amt { font-size:16px; font-weight:800; color:#9a3412; font-variant-numeric: tabular-nums; }
    .con-row .con-meta { font-size:10px; color:#a16207; margin-top:2px; line-height:1.3; }
    .con-row .con-actions { margin-top:6px; display:flex; gap:6px; }
    .con-row .con-btn {
        flex:1; padding:5px 8px; background:#9a3412; color:#fff; border:none;
        border-radius:5px; font-size:11px; font-weight:700; cursor:pointer;
        text-align:center;
    }
    .con-row .con-btn.sec {
        flex:0 0 auto; background:transparent; color:#7c2d12;
        border:1px solid #fdba74; font-weight:600;
    }
    .con-row .con-age { font-weight:600; color:#9a3412; }
</style>

<script>
(function conInit(attempts){
    if (typeof jQuery === 'undefined') {
        if ((attempts || 0) > 300) return;
        return setTimeout(function(){ conInit((attempts||0)+1); }, 20);
    }

    jQuery(function ($) {
        try {
            var $panel = $('#clover_orphan_nag');
            if (!$panel.length) return;

            var $list    = $('#con_list');
            var $count   = $('#con_count');
            var $plural  = $('#con_plural');
            var $refresh = $('#con_refresh');

            // Cashiers can snooze the panel for 10 min if they're
            // mid-flow and don't want to be interrupted; reappears on the
            // next orphan or after the timer.
            var snoozeKey = 'clover_orphan_nag_snooze_until';

            function snoozed() {
                try {
                    var until = parseInt(localStorage.getItem(snoozeKey) || '0', 10);
                    return until > Date.now();
                } catch (e) { return false; }
            }

            function locationId() {
                var loc = $('input[name="location_id"]').val() || '';
                if (!loc) loc = $('#location_id').val() || '';
                return loc;
            }

            function render(orphans) {
                if (snoozed() || !orphans || !orphans.length) {
                    $panel.hide();
                    return;
                }
                $count.text(orphans.length);
                $plural.text(orphans.length === 1 ? '' : 's');

                var html = '';
                for (var i = 0; i < orphans.length; i++) {
                    var o = orphans[i];
                    var ageMin = Math.max(0, Math.round(o.age_seconds / 60));
                    var ageLabel = ageMin <= 0 ? 'just now' : (ageMin + ' min ago');
                    var preTax = (typeof o.pre_tax === 'number') ? o.pre_tax.toFixed(2) : '';
                    html += '<div class="con-row" data-cp-id="' + o.id + '">';
                    html +=   '<div class="con-amt">$' + o.amount.toFixed(2) + '</div>';
                    html +=   '<div class="con-meta">';
                    html +=     '<span class="con-age">' + ageLabel + '</span> · ' + (o.paid_at || '');
                    if (o.location_name) html += ' · ' + o.location_name;
                    if (o.card_label) html += ' · ' + o.card_label;
                    if (preTax) html += '<br>pre-tax $' + preTax + ' + tax $' + o.tax.toFixed(2);
                    html +=   '</div>';
                    html +=   '<div class="con-actions">';
                    html +=     '<button type="button" class="con-btn con-ring" data-amount="' + o.pre_tax + '" data-clover-id="' + (o.clover_payment_id || '') + '">+ Ring this in ERP</button>';
                    html +=   '</div>';
                    html += '</div>';
                }
                $list.html(html);
                $panel.show();
            }

            function poll() {
                if (snoozed()) { $panel.hide(); return; }
                var loc = locationId();
                $.ajax({
                    url: "{{ route('pos.cloverOrphansRecent') }}",
                    method: 'GET',
                    data: { location_id: loc, minutes: 5 },
                    dataType: 'json',
                    timeout: 8000
                }).done(function (r) {
                    render(r && r.orphans ? r.orphans : []);
                }).fail(function () {
                    /* silent — POS sell flow must not break on a side-channel error */
                });
            }

            // Ring-this clicks: scroll to the manual-product price input
            // and prefill the price with the pre-tax amount. The cashier
            // types the item name + quantity, finishes the sale, and the
            // next poll auto-clears the orphan once the new ERP ring
            // matches the Clover amount.
            $list.on('click', '.con-ring', function (e) {
                e.preventDefault();
                var amount = parseFloat($(this).data('amount') || '0');
                var cloverId = $(this).data('clover-id') || '';
                try {
                    var $priceInput = $('input[name="manual_product_price"], input#manual_product_price, input[name="manual_unit_price[]"]').first();
                    if ($priceInput.length) {
                        $priceInput.focus().val(amount.toFixed(2)).trigger('change');
                        $priceInput[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    } else {
                        // Fall back to the regular product search box and tell
                        // the cashier the target price.
                        var $search = $('input[name="search_product"], input#search_product_search').first();
                        if ($search.length) $search.focus();
                    }
                    if (window.toastr && typeof toastr.info === 'function') {
                        toastr.info('Ring the item at $' + amount.toFixed(2) + ' pre-tax. Clover ' + cloverId + ' will auto-pair on save.');
                    }
                } catch (err) { /* never throw out of a side widget */ }
            });

            $refresh.on('click', function (e) { e.preventDefault(); poll(); });

            poll();
            setInterval(poll, 30000);
        } catch (e) { /* side-channel, swallow */ }
    });
})(0);
</script>
