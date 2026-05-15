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
     style="display:none; width:100%; margin:0 0 10px 0; padding:10px 14px;
            background:#fff7ed; border:2px solid #f97316; border-radius:8px;
            box-shadow:0 2px 6px rgba(249,115,22,.18);
            font-size:13px; color:#7c2d12;">
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <div style="font-weight:800; font-size:14px; color:#7c2d12; white-space:nowrap;">
            <i class="fa fa-exclamation-triangle"></i>
            <span id="con_count">0</span> Clover swipe<span id="con_plural">s</span> need ringing
        </div>
        <div style="font-size:12px; color:#9a3412; flex:1; min-width:240px;">
            Please ring the item in ERP now so inventory + reports stay accurate.
        </div>
    </div>
    <div id="con_list" style="margin-top:8px; display:flex; flex-wrap:wrap; gap:8px;"></div>
</div>

<style>
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

            var $list    = $('#con_list');
            var $count   = $('#con_count');
            var $plural  = $('#con_plural');

            function locationId() {
                var loc = $('input[name="location_id"]').val() || '';
                if (!loc) loc = $('#location_id').val() || '';
                return loc;
            }

            function render(orphans) {
                if (!orphans || !orphans.length) {
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
                    html += '<div class="con-chip" data-cp-id="' + o.id + '">';
                    html +=   '<div class="con-amt">$' + o.amount.toFixed(2) + '</div>';
                    html +=   '<div class="con-meta">';
                    html +=     '<span class="con-age">' + ageLabel + '</span> · ' + (o.paid_at || '');
                    if (o.location_name) html += ' · ' + o.location_name;
                    if (o.card_label) html += ' · ' + o.card_label;
                    html +=   '</div>';
                    html +=   '<button type="button" class="con-btn con-ring" data-amount="' + o.pre_tax + '" data-clover-id="' + (o.clover_payment_id || '') + '">+ Ring this in ERP</button>';
                    html += '</div>';
                }
                $list.html(html);
                $panel.show();
            }

            function poll() {
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
