{{-- Modal that pops when a cashier edits a line's unit price in the cart.
     Policy: cashiers may only override price when the sticker disagrees with
     the ERP. Reason is required and gets logged with the override at
     /admin/pos-overrides. Cancelling reverts the price to the system value. --}}
<div id="pos_price_override_modal" class="modal fade" tabindex="-1" role="dialog" aria-labelledby="pos_price_override_modal_title" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog modal-md" role="document">
        <div class="modal-content">
            <div class="modal-header" style="background:#fff5e6; border-bottom:1px solid #f1c97d;">
                <h4 class="modal-title" id="pos_price_override_modal_title">
                    <i class="fa fa-exclamation-triangle text-warning"></i>
                    Price override — needs explanation
                </h4>
            </div>
            <div class="modal-body">
                <p style="margin-bottom:14px;">
                    Cashiers may only change a price when the <strong>sticker disagrees with the system</strong>
                    (e.g. the label on the record says a different price than the ERP). Please leave a quick
                    note so Sarah can review it later.
                </p>
                <div style="background:#f8f9fa; border-radius:8px; padding:12px 14px; margin-bottom:14px;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <div style="font-size:11px; color:#888; text-transform:uppercase;">Sticker (ERP)</div>
                            <div id="pos_price_override_old" style="font-size:18px; font-weight:600; color:#666;">&mdash;</div>
                        </div>
                        <div style="font-size:22px; color:#aaa;">&rarr;</div>
                        <div style="text-align:right;">
                            <div style="font-size:11px; color:#888; text-transform:uppercase;">Charging</div>
                            <div id="pos_price_override_new" style="font-size:18px; font-weight:700; color:#2b1e16;">&mdash;</div>
                        </div>
                    </div>
                    <div id="pos_price_override_product" style="margin-top:8px; font-size:13px; color:#666; text-align:center;"></div>
                </div>
                <label for="pos_price_override_reason_input" style="font-weight:600; margin-bottom:6px; display:block;">
                    Why is the price different? <span class="text-danger">*</span>
                </label>
                <textarea id="pos_price_override_reason_input" class="form-control" rows="3" maxlength="300"
                          placeholder="e.g. 'Sticker says $20, system has $25 — going with the sticker.'"></textarea>
                <div id="pos_price_override_reason_error" class="text-danger" style="display:none; margin-top:6px; font-size:13px;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" id="pos_price_override_cancel" class="btn btn-default">
                    Cancel (keep sticker price)
                </button>
                <button type="button" id="pos_price_override_confirm" class="btn btn-warning">
                    Confirm override
                </button>
            </div>
        </div>
    </div>
</div>

<script>
$(function () {
    // The unit-price input that fired the change. Captured when the modal
    // opens so Confirm/Cancel know which row to apply the result to.
    var activeInput = null;
    var activeOriginal = null;

    var $modal = $('#pos_price_override_modal');
    var $reason = $('#pos_price_override_reason_input');
    var $reasonError = $('#pos_price_override_reason_error');
    var $oldEl = $('#pos_price_override_old');
    var $newEl = $('#pos_price_override_new');
    var $productEl = $('#pos_price_override_product');

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

    // Listen for changes on the unit-price field. Bound after the existing
    // pos.js handler so totals recompute first, then we prompt for a reason.
    // If the cashier cancels, we restore the original price and re-trigger
    // change so totals recompute back to the sticker value.
    $('table#pos_table tbody').on('change', 'input.pos_unit_price_inc_tax', function () {
        var $input = $(this);
        var rawOriginal = $input.attr('data-original-price');
        var original = parseFloat((rawOriginal || '').toString().replace(/[^0-9.\-]/g, ''));
        var current = parseFloat(($input.val() || '').toString().replace(/[^0-9.\-]/g, ''));
        if (isNaN(original)) original = current;
        if (isNaN(current)) return;

        if (Math.abs(current - original) < 0.005) {
            $input.closest('tr').find('input.pos_price_override_reason').val('');
            return;
        }

        var $reasonInput = $input.closest('tr').find('input.pos_price_override_reason');
        var prevReasonPrice = $input.data('reason-for-price');
        if ($reasonInput.val() && prevReasonPrice !== undefined &&
            Math.abs(parseFloat(prevReasonPrice) - current) < 0.005) {
            return;
        }

        activeInput = $input;
        activeOriginal = original;
        $oldEl.text(fmt(original));
        $newEl.text(fmt(current));
        $productEl.text(getRowProductLabel($input.closest('tr')));
        $reason.val($reasonInput.val() || '');
        $reasonError.hide();
        $modal.modal('show');
        setTimeout(function () { $reason.focus(); }, 250);
    });

    $('#pos_price_override_confirm').on('click', function () {
        var reason = ($reason.val() || '').trim();
        if (reason.length < 4) {
            $reasonError.text('Please write a brief reason (at least 4 characters).').show();
            return;
        }
        if (!activeInput) { $modal.modal('hide'); return; }
        var $row = activeInput.closest('tr');
        $row.find('input.pos_price_override_reason').val(reason);
        var current = parseFloat((activeInput.val() || '').toString().replace(/[^0-9.\-]/g, ''));
        activeInput.data('reason-for-price', current);
        activeInput = null;
        activeOriginal = null;
        $modal.modal('hide');
    });

    $('#pos_price_override_cancel').on('click', function () {
        if (activeInput && activeOriginal !== null && !isNaN(activeOriginal)) {
            activeInput.val(activeOriginal.toFixed(2));
            activeInput.closest('tr').find('input.pos_price_override_reason').val('');
            activeInput.trigger('change');
        }
        activeInput = null;
        activeOriginal = null;
        $modal.modal('hide');
    });
});
</script>
