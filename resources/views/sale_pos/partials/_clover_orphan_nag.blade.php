{{-- Luis's idea, Sarah 2026-05-15: real-time nag at the TOP of /pos
     when Clover swiped a card in the last 5 min without a matching ERP
     ring. Banner sits in the normal document flow above the form — NO
     position:fixed, NO snooze, NO dismiss button. The whole point is
     that cashiers cannot ignore it; missing rings degrade inventory +
     reports.

     Auto-clears the moment the matching ERP sale exists (next poll).
     Polling endpoint = /sells/pos/clover-orphans-recent.

     Hard rule per Sarah's POS stability policy: this widget must NEVER
     break the sell flow. Everything is wrapped in try/catch + lazy
     jQuery detect; if the endpoint 404s or the script throws, the
     banner just stays hidden and POS keeps working. --}}
<div id="clover_orphan_nag"
     style="display:none; padding:10px 14px;
            background:#fff7ed; border:2px solid #f97316; border-radius:8px;
            box-shadow:0 2px 6px rgba(249,115,22,.18);
            font-size:13px; color:#7c2d12;">
    {{-- 1. ERP-only — most urgent. Cashier rang card payment but the
         actual swipe never happened. Did the customer leave without
         paying? --}}
    <div id="eon_block" style="display:none;">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <div style="font-weight:800; font-size:14px; color:#7c2d12; white-space:nowrap;">
                <i class="fa fa-exclamation-triangle"></i>
                <span id="eon_count">0</span> sale<span id="eon_plural">s</span> not on Clover
            </div>
            <div style="font-size:12px; color:#9a3412; flex:1; min-width:240px;">
                Rung in ERP, not on Clover yet. Ring it on the terminal.
            </div>
        </div>
        <div id="eon_list" style="margin-top:6px; display:flex; flex-wrap:wrap; gap:6px;"></div>
    </div>

    {{-- Sarah 2026-05-15: mismatch banner removed entirely. The vast
         majority of small-cent diffs are the known $0.12 bag-fee gap
         (suppressed server-side), and the remaining real typos aren't
         worth nagging the floor about — Sarah wants to motivate
         cashiers, not pile on. Admins still audit mismatches on the
         EOD reconciliation page. --}}

    {{-- Clover-only — card swiped, no ERP ring. Cashier needs to ring
         the item so inventory is decremented. --}}
    <div id="con_block" style="display:none; margin-top:10px; padding-top:10px; border-top:1px dashed #fdba74;">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <div style="font-weight:800; font-size:14px; color:#7c2d12; white-space:nowrap;">
                <i class="fa fa-credit-card"></i>
                <span id="con_count">0</span> Clover charge<span id="con_plural">s</span> not in ERP
            </div>
            <div style="font-size:12px; color:#9a3412; flex:1; min-width:240px;">
                Charged on Clover, not rung in ERP yet. Ring the item in ERP.
            </div>
        </div>
        <div id="con_list" style="margin-top:6px; display:flex; flex-wrap:wrap; gap:6px;"></div>
    </div>
</div>

<style>
    /* Sit to the right of the fixed 'Recently rung up' panel at wide
       widths (same 220px gutter the cart form uses). Full width on
       narrow viewports where the rings panel hides itself. */
    #clover_orphan_nag {
        margin: 12px 16px 10px 16px;
    }
    @media (min-width: 1200px) {
        #clover_orphan_nag {
            margin-left: 220px !important;
            margin-right: 16px !important;
        }
    }
    .con-chip {
        background:#fff; border:1px solid #fdba74; border-radius:6px;
        padding:4px 6px 4px 10px; display:inline-flex; align-items:center; gap:8px;
    }
    .con-chip .con-amt {
        font-size:14px; font-weight:800; color:#9a3412; font-variant-numeric: tabular-nums;
    }
    .con-chip .con-meta { font-size:11px; color:#a16207; white-space:nowrap; }
    .con-chip .con-btn {
        padding:3px 8px; background:#9a3412; color:#fff; border:none;
        border-radius:4px; font-size:11px; font-weight:700; cursor:pointer; text-decoration:none; white-space:nowrap;
    }
    .con-chip a.con-btn:hover { color:#fff; }
</style>

<script>
(function conInit(attempts){
    if (typeof jQuery === 'undefined') {
        if ((attempts || 0) > 300) return;
        return setTimeout(function(){ conInit((attempts||0)+1); }, 20);
    }

    jQuery(function ($) {
        try {
            var $panel  = $('#clover_orphan_nag');
            if (!$panel.length) return;

            var $conBlock  = $('#con_block');
            var $list      = $('#con_list');
            var $count     = $('#con_count');
            var $plural    = $('#con_plural');

            var $eonBlock  = $('#eon_block');
            var $eonList   = $('#eon_list');
            var $eonCount  = $('#eon_count');
            var $eonPlural = $('#eon_plural');

            function locationId() {
                var loc = $('input[name="location_id"]').val() || '';
                if (!loc) loc = $('#location_id').val() || '';
                return loc;
            }

            function ageLabel(seconds) {
                var s = Math.max(0, seconds || 0);
                if (s < 60) return 'just now';
                var m = Math.round(s / 60);
                if (m < 60) return m + ' min ago';
                var h = Math.floor(m / 60);
                var rem = m % 60;
                return rem ? (h + ' hr ' + rem + ' min ago') : (h + ' hr ago');
            }

            function escapeAttr(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g,'&amp;').replace(/"/g,'&quot;');
            }

            function render(payload) {
                var orphans = (payload && payload.orphans) || [];
                var erpOrphans = (payload && payload.erp_orphans) || [];
                // Mismatches still come back in the payload but we no
                // longer surface them on the floor — Sarah wants to
                // motivate cashiers, not nag for cents. Admins see the
                // detail on the EOD reconciliation page.
                var any = orphans.length + erpOrphans.length;
                if (!any) { $panel.hide(); return; }

                // Clover-only chips
                if (orphans.length) {
                    $count.text(orphans.length);
                    $plural.text(orphans.length === 1 ? '' : 's');
                    var html = '';
                    for (var i = 0; i < orphans.length; i++) {
                        var o = orphans[i];
                        html += '<div class="con-chip" data-cp-id="' + o.id + '">';
                        html +=   '<span class="con-amt">$' + o.amount.toFixed(2) + '</span>';
                        html +=   '<span class="con-meta" title="' + escapeAttr(ageLabel(o.age_seconds)) + '">' + (o.paid_at || '');
                        if (o.card_label) html += ' · ' + o.card_label;
                        html +=   '</span>';
                        html +=   '<button type="button" class="con-btn con-ring" data-amount="' + o.pre_tax + '" data-clover-id="' + escapeAttr(o.clover_payment_id || '') + '">Ring in ERP</button>';
                        html += '</div>';
                    }
                    $list.html(html);
                    $conBlock.show();
                } else {
                    $conBlock.hide();
                }

                // ERP-only chips
                if (erpOrphans.length) {
                    $eonCount.text(erpOrphans.length);
                    $eonPlural.text(erpOrphans.length === 1 ? '' : 's');
                    var ehtml = '';
                    for (var j = 0; j < erpOrphans.length; j++) {
                        var e = erpOrphans[j];
                        ehtml += '<div class="con-chip" data-tx-id="' + e.tx_id + '">';
                        ehtml +=   '<span class="con-amt">$' + e.amount.toFixed(2) + '</span>';
                        ehtml +=   '<span class="con-meta" title="' + escapeAttr(ageLabel(e.age_seconds)) + '">' + (e.transaction_date || '');
                        if (e.invoice_no) ehtml += ' · #' + escapeAttr(e.invoice_no);
                        ehtml +=   '</span>';
                        ehtml +=   '<a class="con-btn" href="/pos/' + e.tx_id + '/edit">Open</a>';
                        ehtml += '</div>';
                    }
                    $eonList.html(ehtml);
                    $eonBlock.show();
                } else {
                    $eonBlock.hide();
                }

                $panel.show();
            }

            function poll() {
                var loc = locationId();
                $.ajax({
                    url: "{{ route('pos.cloverOrphansRecent') }}",
                    method: 'GET',
                    data: { location_id: loc },
                    dataType: 'json',
                    timeout: 8000
                }).done(function (r) {
                    render(r || {});
                }).fail(function () {
                    /* silent — POS sell flow must never break on this widget */
                });
            }

            // Ring-this clicks: try to focus the manual-product price
            // input and prefill with the pre-tax amount. Cashier fills
            // in the item name + finishes the sale; next poll auto-
            // clears the chip once the new ERP ring matches.
            $list.on('click', '.con-ring', function (e) {
                e.preventDefault();
                var amount = parseFloat($(this).data('amount') || '0');
                var cloverId = $(this).data('clover-id') || '';
                try {
                    var $priceInput = $('input[name="manual_product_price"], input#manual_product_price, input[name="manual_unit_price[]"]').first();
                    if ($priceInput.length) {
                        $priceInput.focus().val(amount.toFixed(2)).trigger('change');
                        $priceInput[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                    if (window.toastr && typeof toastr.info === 'function') {
                        toastr.info('Ring the item at $' + amount.toFixed(2) + ' pre-tax. Clover ' + cloverId + ' will auto-pair on save.');
                    }
                } catch (err) { /* never throw out of a side widget */ }
            });

            poll();
            setInterval(poll, 30000);
        } catch (e) { /* side-channel, swallow */ }
    });
})(0);
</script>
