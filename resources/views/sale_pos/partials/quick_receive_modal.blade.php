{{-- Quick-receive modal: shown when a cashier tries to add an item that
     shows out of stock. Confirms intent, receives 1 unit at the current
     store, logs it at /admin/pos-quick-receives, then adds the line to
     the sale. Cashiers can leave an optional note ("customer brought the
     last one from the bin", "scanned wrong sticker", etc.). --}}
<div id="pos_quick_receive_modal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="pos_quick_receive_modal_title" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background:#e8f4ff; border-bottom:1px solid #9fc6f1;">
                <h4 class="modal-title" id="pos_quick_receive_modal_title">
                    <i class="fa fa-archive text-primary"></i>
                    Out of stock &mdash; receive &amp; add?
                </h4>
            </div>
            <div class="modal-body">
                <p style="margin-bottom:14px;">
                    This item shows <strong>out of stock</strong> at this store. If you have it in hand,
                    you can receive <strong>1 unit here</strong> and add it to the sale in one click &mdash;
                    no need to walk it through a separate purchase form.
                </p>
                <div style="background:#f8f9fa; border-radius:8px; padding:12px 14px; margin-bottom:14px;">
                    <div id="pos_quick_receive_product" style="font-size:14px; color:#333; text-align:center; font-weight:600;">&mdash;</div>
                    <div id="pos_quick_receive_sub" style="font-size:12px; color:#777; text-align:center; margin-top:4px;"></div>
                </div>
                <label for="pos_quick_receive_note_input" style="font-weight:600; margin-bottom:6px; display:block;">
                    Optional note <span class="text-muted" style="font-weight:400; font-size:12px;">(why was this off the books?)</span>
                </label>
                <textarea id="pos_quick_receive_note_input" class="form-control" rows="2" maxlength="300"
                          placeholder="e.g. 'customer pulled the last copy from the dollar bin'"></textarea>
                <div id="pos_quick_receive_error" class="text-danger" style="display:none; margin-top:6px; font-size:13px;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" id="pos_quick_receive_cancel" class="btn btn-default">
                    Cancel
                </button>
                <button type="button" id="pos_quick_receive_confirm" class="btn btn-primary">
                    <i class="fa fa-check"></i> Receive 1 &amp; add to sale
                </button>
            </div>
        </div>
    </div>
</div>

<style>
/* Out-of-stock rows in the POS autocomplete used to be greyed out and
   un-clickable. Now they're tappable so the cashier can quick-receive,
   but we keep them visually distinct so it's obvious which rows are
   "in stock" vs. "tap to receive". */
.pos-quick-receive-row {
    background: #fff8e6 !important;
    border-left: 3px solid #f0a020 !important;
}
.pos-quick-receive-row .pos-oos-hint {
    color: #b06a00;
    font-style: italic;
    font-size: 11px;
}
</style>

<script>
(function bootstrap() {
    if (typeof window.jQuery === 'undefined') { setTimeout(bootstrap, 100); return; }
    var $ = window.jQuery;
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initPosQuickReceive);
    } else {
        initPosQuickReceive();
    }

    function initPosQuickReceive() {
        var $modal = $('#pos_quick_receive_modal');
        if (!$modal.length) { return; }
        var $product = $('#pos_quick_receive_product');
        var $sub = $('#pos_quick_receive_sub');
        var $note = $('#pos_quick_receive_note_input');
        var $error = $('#pos_quick_receive_error');
        var $confirm = $('#pos_quick_receive_confirm');
        var $cancel = $('#pos_quick_receive_cancel');

        var current = null; // { variation_id, product_label, sub_sku, price }

        // Expose a single entry point so pos.js can pop the modal from
        // either the search/click path or the scan/error path.
        window.posQuickReceivePrompt = function (item) {
            if (!item || !item.variation_id) { return; }
            current = item;
            var label = item.product_label || item.name || 'this item';
            $product.text(label);
            var bits = [];
            if (item.sub_sku) { bits.push('SKU ' + item.sub_sku); }
            if (item.selling_price) { bits.push('$' + item.selling_price); }
            $sub.text(bits.join(' &middot; ').replace(/&middot;/g, '·'));
            $note.val('');
            $error.hide().text('');
            $confirm.prop('disabled', false).html('<i class="fa fa-check"></i> Receive 1 & add to sale');
            $modal.modal('show');
            setTimeout(function () { $note.focus(); }, 200);
        };

        $cancel.on('click', function () {
            $modal.modal('hide');
            $('input#search_product').focus().select();
        });

        $confirm.on('click', function () {
            if (!current || !current.variation_id) {
                $modal.modal('hide');
                return;
            }
            var location_id = $('input#location_id').val();
            if (!location_id) {
                $error.text('No location set on this POS — log out and back in to your register.').show();
                return;
            }
            var note = ($note.val() || '').trim();
            $confirm.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Receiving...');
            $error.hide().text('');

            $.ajax({
                method: 'POST',
                url: '/sells/pos/quick-receive',
                data: {
                    variation_id: current.variation_id,
                    location_id: location_id,
                    qty: 1,
                    note: note,
                    _token: $('meta[name="csrf-token"]').attr('content') || $('input[name="_token"]').first().val(),
                },
                dataType: 'json'
            }).done(function (resp) {
                if (resp && resp.success) {
                    var variation_id = current.variation_id;
                    current = null;
                    $modal.modal('hide');
                    if (typeof window.toastr !== 'undefined' && window.toastr.success) {
                        window.toastr.success('Received 1 — adding to sale.');
                    }
                    // Now that stock is bumped, the normal add-to-cart path
                    // will succeed. Reuse pos_product_row so all the row
                    // computations / tax / discount wiring stays consistent.
                    if (typeof window.pos_product_row === 'function') {
                        window.pos_product_row(variation_id, null);
                    }
                } else {
                    var msg = (resp && resp.msg) ? resp.msg : 'Receive failed.';
                    $error.text(msg).show();
                    $confirm.prop('disabled', false).html('<i class="fa fa-check"></i> Receive 1 & add to sale');
                }
            }).fail(function (xhr) {
                var msg = 'Receive failed (' + (xhr.status || 'network') + ').';
                if (xhr.responseJSON && xhr.responseJSON.msg) { msg = xhr.responseJSON.msg; }
                $error.text(msg).show();
                $confirm.prop('disabled', false).html('<i class="fa fa-check"></i> Receive 1 & add to sale');
            });
        });
    }
})();
</script>
