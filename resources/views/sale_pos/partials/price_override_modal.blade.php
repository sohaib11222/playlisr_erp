{{-- Modal for the deliberate "Edit price" flow on a POS cart line.
     Policy: cashiers may only change a price when the physical sticker
     disagrees with the ERP. The price input itself stays read-only; the
     only way to change it is to click the "Edit price" button next to the
     line, which opens this modal asking for the new price AND a required
     reason. Every override is logged at /admin/pos-overrides. --}}
<div id="pos_price_override_modal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="pos_price_override_modal_title" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background:#fff5e6; border-bottom:1px solid #f1c97d;">
                <h4 class="modal-title" id="pos_price_override_modal_title">
                    <i class="fa fa-exclamation-triangle text-warning"></i>
                    Edit price &mdash; sticker mismatch
                </h4>
            </div>
            <div class="modal-body">
                <p style="margin-bottom:14px;">
                    Cashiers may only change a price when the <strong>sticker disagrees with the system</strong>
                    (the label on the record says a different price than the ERP). The price below is the
                    ERP&rsquo;s sticker price. Enter what you&rsquo;re actually charging and a short reason.
                </p>
                <div style="background:#f8f9fa; border-radius:8px; padding:12px 14px; margin-bottom:14px;">
                    <div id="pos_price_override_product" style="font-size:13px; color:#666; text-align:center; margin-bottom:10px; font-weight:600;"></div>
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                        <div style="flex:1;">
                            <div style="font-size:11px; color:#888; text-transform:uppercase; margin-bottom:4px;">Sticker (ERP)</div>
                            <div id="pos_price_override_old" style="font-size:20px; font-weight:600; color:#666;">&mdash;</div>
                        </div>
                        <div style="font-size:22px; color:#aaa;">&rarr;</div>
                        <div style="flex:1;">
                            <div style="font-size:11px; color:#888; text-transform:uppercase; margin-bottom:4px;">Charging</div>
                            <div class="input-group">
                                <span class="input-group-addon">$</span>
                                <input id="pos_price_override_new_input" type="number" step="0.01" min="0" class="form-control" style="font-size:18px; font-weight:700;">
                            </div>
                        </div>
                    </div>
                </div>
                <label for="pos_price_override_reason_input" style="font-weight:600; margin-bottom:6px; display:block;">
                    Why is the price different? <span class="text-danger">*</span>
                </label>
                <textarea id="pos_price_override_reason_input" class="form-control" rows="3" maxlength="300"
                          placeholder="e.g. 'Sticker says $20, system has $25 — going with the sticker.'"></textarea>
                <div id="pos_price_override_error" class="text-danger" style="display:none; margin-top:6px; font-size:13px;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" id="pos_price_override_cancel" class="btn btn-default">
                    Cancel
                </button>
                <button type="button" id="pos_price_override_confirm" class="btn btn-warning">
                    Save price &amp; reason
                </button>
            </div>
        </div>
    </div>
</div>

<style>
.pos-edit-price-btn {
    display: inline-block;
    margin-top: 4px;
    padding: 2px 8px;
    font-size: 12px;
    line-height: 1.4;
    color: #8a6d3b;
    background: transparent;
    border: 1px dashed #d4ad6e;
    border-radius: 4px;
    cursor: pointer;
}
.pos-edit-price-btn:hover { background: #fff5e6; color: #2b1e16; }
.pos-edit-price-btn .fa { margin-right: 4px; }
.pos-price-overridden {
    color: #d9534f !important;
    font-weight: 600;
    text-decoration: line-through;
}
.pos-price-current {
    color: #2b1e16;
    font-weight: 700;
    margin-left: 6px;
}
</style>

<script>
(function bootstrap() {
    if (typeof window.jQuery === 'undefined') { setTimeout(bootstrap, 100); return; }
    var $ = window.jQuery;
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initPosPriceOverride($); }, { once: true });
    } else {
        initPosPriceOverride($);
    }
})();
function initPosPriceOverride($) {
    var $modal = $('#pos_price_override_modal');
    var $reason = $('#pos_price_override_reason_input');
    var $newInput = $('#pos_price_override_new_input');
    var $errorEl = $('#pos_price_override_error');
    var $oldEl = $('#pos_price_override_old');
    var $productEl = $('#pos_price_override_product');
    var activeInput = null; // hidden price input on the row being edited

    function fmt(n) {
        var v = parseFloat(n);
        if (isNaN(v)) return '$0.00';
        return '$' + v.toFixed(2);
    }

    function getRowProductLabel($row) {
        var cellText = $row.find('td').first().text() || '';
        cellText = cellText.replace(/\s+/g, ' ').trim();
        if (cellText.length > 80) cellText = cellText.substring(0, 80) + '…';
        return cellText;
    }

    // The price input is kept ALWAYS read-only at the input level — the only
    // way to change it is via the modal. We also stamp data-original-price
    // on first sight so we can show the sticker value in the modal even when
    // the cashier reopens the modal after a previous override.
    function ensureRowReady($row) {
        var $input = $row.find('input.pos_unit_price_inc_tax');
        if (!$input.length) return;
        $input.prop('readonly', true).attr('readonly', 'readonly');
        if (!$input.attr('data-original-price')) {
            $input.attr('data-original-price', $input.val());
        }
        // Hidden reason input.
        var $tr = $input.closest('tr');
        if (!$tr.find('input.pos_price_override_reason').length) {
            var rowIdx = $tr.attr('data-row_index');
            if (rowIdx === undefined || rowIdx === null) {
                var any = $tr.find('input[name^="products"]').first().attr('name') || '';
                var m = any.match(/products\[(\d+)\]/);
                rowIdx = m ? m[1] : '0';
            }
            $input.after('<input type="hidden" name="products[' + rowIdx + '][price_override_reason]" class="pos_price_override_reason" value="">');
        }
        // "Edit price" button — sits in the same cell as the price input.
        // Skip for the bag-fee row and any row without a real product cell.
        if ($tr.attr('data-plastic-bag') === 'true') return;
        if (!$tr.find('.pos-edit-price-btn').length) {
            $input.after(' <button type="button" class="pos-edit-price-btn" title="Edit this line’s price (sticker mismatch)"><i class="fa fa-pencil"></i>Edit price</button>');
        }
    }

    function sweepRows() {
        $('#pos_table tbody tr.product_row').each(function () { ensureRowReady($(this)); });
    }
    sweepRows();
    // MutationObserver was unreliable on this layout (rows seem to get rebuilt
    // out of band); fall back to a cheap interval poll. ensureRowReady is
    // idempotent — it no-ops when the row already has the button + hidden
    // reason input, so the cost is just a few DOM reads per tick.
    setInterval(sweepRows, 500);

    // Open the modal in response to a deliberate Edit-price click. Pre-fill
    // it with the line's current value and last-saved reason (if any) so
    // re-opening after a previous override doesn't blank the field.
    $('#pos_table tbody').on('click', '.pos-edit-price-btn', function (e) {
        e.preventDefault();
        var $row = $(this).closest('tr');
        var $input = $row.find('input.pos_unit_price_inc_tax');
        if (!$input.length) return;
        activeInput = $input;
        var stickerRaw = $input.attr('data-original-price') || $input.val();
        var sticker = parseFloat((stickerRaw || '').toString().replace(/[^0-9.\-]/g, ''));
        var current = parseFloat(($input.val() || '').toString().replace(/[^0-9.\-]/g, ''));
        if (isNaN(sticker)) sticker = 0;
        if (isNaN(current)) current = sticker;
        $oldEl.text(fmt(sticker));
        $newInput.val(current.toFixed(2));
        $productEl.text(getRowProductLabel($row));
        var existingReason = $row.find('input.pos_price_override_reason').val() || '';
        $reason.val(existingReason);
        $errorEl.hide();
        $modal.modal('show');
        setTimeout(function () { $newInput.focus().select(); }, 250);
    });

    $('#pos_price_override_confirm').on('click', function () {
        var newPriceStr = ($newInput.val() || '').toString().replace(/[^0-9.\-]/g, '');
        var newPrice = parseFloat(newPriceStr);
        if (isNaN(newPrice) || newPrice < 0) {
            $errorEl.text('Enter a valid price (zero or greater).').show();
            return;
        }
        var reason = ($reason.val() || '').trim();
        if (reason.length < 4) {
            $errorEl.text('Please write a brief reason (at least 4 characters).').show();
            return;
        }
        if (!activeInput) { $modal.modal('hide'); return; }

        var $row = activeInput.closest('tr');
        // Apply the new price to the underlying input, then trigger change
        // so the existing pos.js handlers recompute the line subtotal and
        // cart totals.
        activeInput.val(newPrice.toFixed(2)).trigger('change');
        $row.find('input.pos_price_override_reason').val(reason);
        $errorEl.hide();
        activeInput = null;
        $modal.modal('hide');
    });

    $('#pos_price_override_cancel').on('click', function () {
        activeInput = null;
        $errorEl.hide();
        $modal.modal('hide');
    });
}
</script>
