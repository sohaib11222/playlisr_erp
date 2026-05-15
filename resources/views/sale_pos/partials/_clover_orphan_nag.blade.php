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
                <span id="eon_count">0</span> ERP card sale<span id="eon_plural">s</span> not on Clover
            </div>
            <div style="font-size:12px; color:#9a3412; flex:1; min-width:240px;">
                Sale rung in ERP as card but no Clover charge yet — did the customer pay? Run the card on Clover now, or correct the ERP payment method if it was cash.
            </div>
        </div>
        <div id="eon_list" style="margin-top:8px; display:flex; flex-wrap:wrap; gap:8px;"></div>
    </div>

    {{-- 2. Mismatches — paired sales whose ERP total ≠ Clover total by >$0.01.
         Could be a price-typo, sticker disagreement, etc. Cashier should
         leave a short explanation so reconciliation knows what happened. --}}
    <div id="mis_block" style="display:none; margin-top:10px; padding-top:10px; border-top:1px dashed #fdba74;">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <div style="font-weight:800; font-size:14px; color:#7c2d12; white-space:nowrap;">
                <i class="fa fa-balance-scale"></i>
                <span id="mis_count">0</span> mismatch<span id="mis_plural">es</span> — ERP vs Clover
            </div>
            <div style="font-size:12px; color:#9a3412; flex:1; min-width:240px;">
                ERP total doesn't match Clover total. Be accurate when entering prices, and please leave a short explanation so reconciliation knows what happened.
            </div>
        </div>
        <div id="mis_list" style="margin-top:8px; display:flex; flex-direction:column; gap:8px;"></div>
    </div>

    {{-- 3. Clover-only — card swiped, no ERP ring. Cashier needs to ring
         the item so inventory is decremented. --}}
    <div id="con_block" style="display:none; margin-top:10px; padding-top:10px; border-top:1px dashed #fdba74;">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <div style="font-weight:800; font-size:14px; color:#7c2d12; white-space:nowrap;">
                <i class="fa fa-credit-card"></i>
                <span id="con_count">0</span> Clover swipe<span id="con_plural">s</span> need ringing
            </div>
            <div style="font-size:12px; color:#9a3412; flex:1; min-width:240px;">
                Card was charged on Clover but no ERP ring yet — please ring the item in ERP so inventory + reports stay accurate.
            </div>
        </div>
        <div id="con_list" style="margin-top:8px; display:flex; flex-wrap:wrap; gap:8px;"></div>
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
        padding:6px 10px; min-width:200px; display:flex; flex-direction:column; gap:2px;
    }
    .con-chip .con-amt {
        font-size:18px; font-weight:800; color:#9a3412; font-variant-numeric: tabular-nums;
        line-height:1.1;
    }
    .con-chip .con-meta { font-size:10px; color:#a16207; line-height:1.3; }
    .con-chip .con-age  { font-weight:700; color:#9a3412; }
    .con-chip .con-btn {
        margin-top:4px; padding:5px 10px; background:#9a3412; color:#fff; border:none;
        border-radius:5px; font-size:11px; font-weight:700; cursor:pointer; text-align:center;
    }
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

            var $misBlock  = $('#mis_block');
            var $misList   = $('#mis_list');
            var $misCount  = $('#mis_count');
            var $misPlural = $('#mis_plural');

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
                var mismatches = (payload && payload.mismatches) || [];
                var any = orphans.length + erpOrphans.length + mismatches.length;
                if (!any) { $panel.hide(); return; }

                // Clover-only chips
                if (orphans.length) {
                    $count.text(orphans.length);
                    $plural.text(orphans.length === 1 ? '' : 's');
                    var html = '';
                    for (var i = 0; i < orphans.length; i++) {
                        var o = orphans[i];
                        html += '<div class="con-chip" data-cp-id="' + o.id + '">';
                        html +=   '<div class="con-amt">$' + o.amount.toFixed(2) + '</div>';
                        html +=   '<div class="con-meta">';
                        html +=     '<span class="con-age">' + ageLabel(o.age_seconds) + '</span> · ' + (o.paid_at || '');
                        if (o.location_name) html += ' · ' + o.location_name;
                        if (o.card_label) html += ' · ' + o.card_label;
                        html +=   '</div>';
                        html +=   '<button type="button" class="con-btn con-ring" data-amount="' + o.pre_tax + '" data-clover-id="' + escapeAttr(o.clover_payment_id || '') + '">+ Ring this in ERP</button>';
                        html += '</div>';
                    }
                    $list.html(html);
                    $conBlock.show();
                } else {
                    $conBlock.hide();
                }

                // Mismatch chips — each gets an inline "Why?" textarea
                if (mismatches.length) {
                    $misCount.text(mismatches.length);
                    $misPlural.text(mismatches.length === 1 ? '' : 'es');
                    var mhtml = '';
                    for (var k = 0; k < mismatches.length; k++) {
                        var m = mismatches[k];
                        var diff = (m.diff || 0);
                        var diffStr = (diff >= 0 ? '+' : '−') + '$' + Math.abs(diff).toFixed(2);
                        mhtml += '<div class="con-chip" style="flex:1; min-width:100%; display:flex; flex-direction:row; flex-wrap:wrap; gap:10px; align-items:center;" data-tx-id="' + m.tx_id + '">';
                        mhtml +=   '<div style="display:flex; flex-direction:column; min-width:160px;">';
                        mhtml +=     '<div class="con-amt">ERP $' + m.erp_total.toFixed(2) + ' · Clover $' + m.clover_total.toFixed(2) + '</div>';
                        mhtml +=     '<div class="con-meta">';
                        mhtml +=       '<span class="con-age">Diff ' + diffStr + '</span> · ' + ageLabel(m.age_seconds);
                        if (m.location_name) mhtml += ' · ' + m.location_name;
                        if (m.invoice_no) mhtml += ' · #' + escapeAttr(m.invoice_no);
                        mhtml +=     '</div>';
                        mhtml +=   '</div>';
                        mhtml +=   '<form class="con-mis-form" data-tx-id="' + m.tx_id + '" style="display:flex; flex:1; gap:6px; min-width:280px; align-items:center;">';
                        mhtml +=     '<input type="text" class="con-mis-reason" placeholder="Why? (e.g., \'rang Clover at $14 instead of $15 sticker\')" required style="flex:1; padding:5px 8px; border:1px solid #fdba74; border-radius:5px; font-size:11px;">';
                        mhtml +=     '<button type="submit" class="con-btn" style="margin-top:0; padding:5px 12px;">Save</button>';
                        mhtml +=   '</form>';
                        mhtml += '</div>';
                    }
                    $misList.html(mhtml);
                    $misBlock.show();
                } else {
                    $misBlock.hide();
                }

                // ERP-only chips
                if (erpOrphans.length) {
                    $eonCount.text(erpOrphans.length);
                    $eonPlural.text(erpOrphans.length === 1 ? '' : 's');
                    var ehtml = '';
                    for (var j = 0; j < erpOrphans.length; j++) {
                        var e = erpOrphans[j];
                        ehtml += '<div class="con-chip" data-tx-id="' + e.tx_id + '">';
                        ehtml +=   '<div class="con-amt">$' + e.amount.toFixed(2) + '</div>';
                        ehtml +=   '<div class="con-meta">';
                        ehtml +=     '<span class="con-age">' + ageLabel(e.age_seconds) + '</span> · ' + (e.transaction_date || '');
                        if (e.location_name) ehtml += ' · ' + e.location_name;
                        if (e.invoice_no) ehtml += ' · #' + escapeAttr(e.invoice_no);
                        ehtml +=   '</div>';
                        ehtml +=   '<a class="con-btn" style="display:block; text-decoration:none;" href="/sells/' + e.tx_id + '/edit">Open in ERP</a>';
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
            // Mismatch "Why?" form submit — POSTs to the existing
            // mismatchExplain endpoint with source=register_reconciliation
            // so reconciliation logs pick it up.
            $misList.on('submit', '.con-mis-form', function (e) {
                e.preventDefault();
                var $form = $(this);
                var txId = $form.data('tx-id');
                var reason = $.trim($form.find('.con-mis-reason').val() || '');
                if (!reason) return;
                $.ajax({
                    url: "{{ route('pos.mismatchExplain') }}",
                    method: 'POST',
                    data: {
                        _token: "{{ csrf_token() }}",
                        discrepancy_type: 'mismatch',
                        transaction_id: txId,
                        source: 'register_reconciliation',
                        reason: reason
                    },
                    timeout: 8000
                }).done(function () {
                    // Optimistic remove — next poll will confirm the chip stays gone.
                    $form.closest('.con-chip').fadeOut(150, function(){ $(this).remove(); poll(); });
                    if (window.toastr && typeof toastr.success === 'function') {
                        toastr.success('Saved.');
                    }
                }).fail(function () {
                    if (window.toastr && typeof toastr.error === 'function') {
                        toastr.error('Could not save the note. Try again?');
                    }
                });
            });

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
