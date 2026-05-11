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
/* The price input on a POS line is read-only. It should LOOK like static
   text — not an editable input — so cashiers don't feel invited to type. */
input.pos_unit_price_inc_tax[readonly],
input.pos_unit_price[readonly] {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
    padding: 0 !important;
    color: #2b1e16 !important;
    font-weight: 600 !important;
    cursor: default !important;
    width: auto !important;
    min-width: 0 !important;
    pointer-events: none;
}
input.pos_unit_price_inc_tax[readonly]:focus,
input.pos_unit_price[readonly]:focus {
    outline: none !important;
    box-shadow: none !important;
}
/* The Edit-price affordance is intentionally subtle — a small grey link
   that fades in only on row hover. Cashiers who need it know it's there;
   the rest of the time it stays out of the way. */
.pos-edit-price-btn {
    display: inline-block;
    margin-left: 6px;
    padding: 0;
    font-size: 10px;
    line-height: 1.4;
    color: #b0b0b0;
    background: transparent;
    border: none;
    border-radius: 0;
    cursor: pointer;
    opacity: 0;
    transition: opacity 0.15s ease, color 0.15s ease;
    text-decoration: underline dotted;
    text-underline-offset: 2px;
}
#pos_table tbody tr.product_row:hover .pos-edit-price-btn,
.pos-edit-price-btn:focus {
    opacity: 1;
}
.pos-edit-price-btn:hover { color: #8a6d3b; }
.pos-edit-price-btn .fa { display: none; }
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
        // The per-row $edit_price evaluation in product_row.blade.php sometimes
        // returns false even when the page-level permission check returned
        // true (different caches). Strip the .hide class off the price cell
        // so the input + Edit button are visible on every product row.
        if ($row.attr('data-plastic-bag') !== 'true') {
            $input.closest('td').removeClass('hide');
        }
        $input.prop('readonly', true).attr('readonly', 'readonly');
        if (!$input.attr('data-original-price')) {
            $input.attr('data-original-price', $input.val());
        }
        // Hidden reason + sticker inputs. The sticker is the original price
        // the cashier saw when they opened the modal — for manual / quick-add
        // products there's no catalog variation to read it from server-side,
        // so we have to pass it through the form.
        var $tr = $input.closest('tr');
        var rowIdx = $tr.attr('data-row_index');
        if (rowIdx === undefined || rowIdx === null) {
            var any = $tr.find('input[name^="products"]').first().attr('name') || '';
            var m = any.match(/products\[(\d+)\]/);
            rowIdx = m ? m[1] : '0';
        }
        if (!$tr.find('input.pos_price_override_reason').length) {
            $input.after('<input type="hidden" name="products[' + rowIdx + '][price_override_reason]" class="pos_price_override_reason" value="">');
        }
        if (!$tr.find('input.pos_price_override_sticker').length) {
            $input.after('<input type="hidden" name="products[' + rowIdx + '][price_override_sticker]" class="pos_price_override_sticker" value="">');
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
        // Capture the sticker (the original price the cashier saw when they
        // opened the modal) BEFORE we overwrite the input value, so manual
        // / quick-add lines can still log their pre-edit sticker.
        var stickerVal = activeInput.attr('data-original-price') || '';
        // Apply the new price to the inc-tax input, then trigger change
        // so the existing pos.js handlers recompute exc-tax + line totals.
        // Belt-and-suspenders: temporarily un-readonly the input so pos.js
        // change handlers (which sometimes ignore readonly inputs) fire.
        activeInput.prop('readonly', false);
        activeInput.val(newPrice.toFixed(2)).trigger('change').trigger('input');
        activeInput.prop('readonly', true).attr('readonly', 'readonly');
        $row.find('input.pos_price_override_reason').val(reason);
        $row.find('input.pos_price_override_sticker').val(stickerVal);
        // Mark the line visually as overridden so the cashier (and Sarah on
        // a recent-sales eyeball) can see at a glance which line was edited.
        $row.attr('data-price-overridden', '1');
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
