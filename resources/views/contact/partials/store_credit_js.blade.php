{{--
    Shared store-credit front-end. Included on every screen that can issue
    store credit (contact list, contact profile, POS, Buy From Customer) so
    the RULES live in exactly one place:

      • a reason is always required (locked dropdown),
      • "Collection purchase with credit" never adds credit directly — it
        routes the cashier to the Buy From Customer form with the customer
        pre-selected (the server enforces this too),
      • one delegated handler drives the shared #customer_account_modal.

    Sarah 2026-07-24.
--}}
<script type="text/javascript">
(function () {
    // Reason <option> markup, reused by the swal dialog below.
    var STORE_CREDIT_REASON_OPTIONS =
        `@include('contact.partials._store_credit_reason_options')`;

    // Core POST. amount may be null for the collection-purchase case (which
    // never reaches the server — we redirect first). onSuccess(result) fires
    // only on a successful credit add.
    window.postStoreCredit = function (contactId, amount, reasonCode, onSuccess) {
        if (!contactId) { toastr.error('Customer not selected.'); return; }
        if (!reasonCode) { toastr.error('Please choose a reason.'); return; }

        // Collection purchases go to the Buy From Customer form, not here.
        if (reasonCode === 'collection_purchase') {
            window.location.href = '/buy-from-customer?contact_id=' + encodeURIComponent(contactId);
            return;
        }

        if (!(amount > 0)) { toastr.error('Please enter a valid amount.'); return; }

        $.ajax({
            method: 'POST',
            url: '/contacts/' + contactId + '/store-credit',
            dataType: 'json',
            data: {
                amount: amount,
                reason: reasonCode,
                _token: $('meta[name="csrf-token"]').attr('content')
            },
            success: function (result) {
                if (result && result.success) {
                    toastr.success(result.msg);
                    if (typeof onSuccess === 'function') { onSuccess(result); }
                } else if (result && result.redirect_buy_form && result.redirect_url) {
                    // Server-side backstop for the collection-purchase case.
                    window.location.href = result.redirect_url;
                } else {
                    toastr.error((result && result.msg) || 'Unable to add store credit.');
                }
            },
            error: function () { toastr.error('Unable to add store credit.'); }
        });
    };

    // Popup used by the contact list & profile "Add Credit" buttons. Builds a
    // small bootstrap dialog inline (amount + reason) so we don't need a blade
    // modal on every page. onSuccess(result) is forwarded from postStoreCredit.
    window.openAddStoreCreditDialog = function (contactId, onSuccess) {
        if (!contactId) { toastr.error('Contact ID not found.'); return; }

        var $modal = $('#add_store_credit_modal');
        if (!$modal.length) {
            $modal = $(
                '<div class="modal fade" id="add_store_credit_modal" tabindex="-1" role="dialog">' +
                  '<div class="modal-dialog"><div class="modal-content">' +
                    '<div class="modal-header">' +
                      '<button type="button" class="close" data-dismiss="modal">&times;</button>' +
                      '<h4 class="modal-title">Add store credit</h4>' +
                    '</div>' +
                    '<div class="modal-body">' +
                      '<div class="form-group">' +
                        '<label>Reason (required):</label>' +
                        '<select id="asc_add_reason" class="form-control">' + STORE_CREDIT_REASON_OPTIONS + '</select>' +
                      '</div>' +
                      '<div class="form-group" id="asc_add_amount_group">' +
                        '<label>Amount:</label>' +
                        '<input type="number" step="0.01" min="0.01" id="asc_add_amount" class="form-control" placeholder="Amount">' +
                      '</div>' +
                      '<p id="asc_add_hint" class="text-muted" style="font-size:12px;margin:0;"></p>' +
                    '</div>' +
                    '<div class="modal-footer">' +
                      '<button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>' +
                      '<button type="button" class="btn btn-success" id="asc_add_submit">Add credit</button>' +
                    '</div>' +
                  '</div></div>' +
                '</div>'
            );
            $('body').append($modal);
        }

        // Reset + stash the current contact (and success callback) on the dialog.
        $modal.data('contact-id', contactId);
        $modal.data('on-success', typeof onSuccess === 'function' ? onSuccess : null);
        $modal.find('#asc_add_reason').val('');
        $modal.find('#asc_add_amount').val('');
        $modal.find('#asc_add_amount_group').show();
        $modal.find('#asc_add_hint').text('');
        $modal.find('#asc_add_submit').text('Add credit');
        $modal.modal('show');
    };

    // When "Collection purchase" is picked, hide the amount field and flip the
    // button — credit is issued by the Buy From Customer form, not this dialog.
    $(document).on('change', '#asc_add_reason', function () {
        var isCollection = $(this).val() === 'collection_purchase';
        $('#asc_add_amount_group').toggle(!isCollection);
        $('#asc_add_hint').text(isCollection
            ? "You'll be taken to the Buy From Customer form to itemize the collection."
            : '');
        $('#asc_add_submit').text(isCollection ? 'Continue →' : 'Add credit');
    });

    $(document).on('click', '#asc_add_submit', function () {
        var $modal = $('#add_store_credit_modal');
        var contactId = $modal.data('contact-id');
        var reasonCode = $('#asc_add_reason').val();
        var amount = parseFloat($('#asc_add_amount').val()) || 0;
        if (!reasonCode) { toastr.error('Please choose a reason.'); return; }

        // Redirects for collection; otherwise posts and closes on success.
        var onSuccess = $modal.data('on-success');
        postStoreCredit(contactId, amount, reasonCode, function (result) {
            $modal.modal('hide');
            if (typeof onSuccess === 'function') { onSuccess(result); }
        });
    });

    // Start each modal open with a clean reason/amount so a prior customer's
    // selection can never carry over to the next.
    $(document).on('shown.bs.modal', '#customer_account_modal', function () {
        $('#modal_store_credit_reason').val('');
        $('#modal_store_credit_amount').val('');
    });

    // Popup used by the contact list & profile "Remove / Adjust Credit"
    // buttons. Signed amount (minus removes) + free-text reason, POSTing to
    // /contacts/{id}/adjust-credit. The server requires a reason and refuses
    // any adjustment that would drive the balance below $0. onSuccess(result)
    // receives the endpoint's { new_balance, delta, ... } payload.
    window.openAdjustStoreCreditDialog = function (contactId, currentBalance, onSuccess) {
        if (!contactId) { toastr.error('Contact ID not found.'); return; }
        currentBalance = parseFloat(currentBalance || 0) || 0;

        var $modal = $('#adjust_store_credit_modal');
        if (!$modal.length) {
            $modal = $(
                '<div class="modal fade" id="adjust_store_credit_modal" tabindex="-1" role="dialog">' +
                  '<div class="modal-dialog"><div class="modal-content">' +
                    '<div class="modal-header">' +
                      '<button type="button" class="close" data-dismiss="modal">&times;</button>' +
                      '<h4 class="modal-title">Adjust store credit</h4>' +
                    '</div>' +
                    '<div class="modal-body">' +
                      '<p style="font-size:13px;color:#666;margin-bottom:10px;">Current balance: <strong id="asc_current_balance">$0.00</strong></p>' +
                      '<div class="form-group">' +
                        '<label>Amount (use a minus sign to remove credit, e.g. <code>-25</code>):</label>' +
                        '<input type="number" step="0.01" id="asc_amount" class="form-control" placeholder="e.g. -25 or 10">' +
                      '</div>' +
                      '<div class="form-group">' +
                        '<label>Reason (required):</label>' +
                        '<input type="text" id="asc_reason" class="form-control" placeholder="e.g. Applied by mistake — reversing">' +
                      '</div>' +
                      '<p id="asc_preview" style="font-size:12px;color:#555;margin:0;"></p>' +
                    '</div>' +
                    '<div class="modal-footer">' +
                      '<button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>' +
                      '<button type="button" class="btn btn-primary" id="asc_submit">Save adjustment</button>' +
                    '</div>' +
                  '</div></div>' +
                '</div>'
            );
            $('body').append($modal);
        }

        $modal.data('contact-id', contactId);
        $modal.data('on-success', typeof onSuccess === 'function' ? onSuccess : null);
        $modal.find('#asc_current_balance').text('$' + currentBalance.toFixed(2));
        $modal.find('#asc_amount').val('').data('current-balance', currentBalance);
        $modal.find('#asc_reason').val('');
        $modal.find('#asc_preview').text('');
        $modal.modal('show');
    };

    // Contact-list action button (data-contact-id / data-current-balance).
    $(document).off('click.storeCredit', '.adjust_store_credit_button')
      .on('click.storeCredit', '.adjust_store_credit_button', function (e) {
        e.preventDefault();
        openAdjustStoreCreditDialog(
            $(this).data('contact-id'),
            $(this).data('current-balance'),
            function () { if (typeof customer_table !== 'undefined') { customer_table.ajax.reload(); } }
        );
    });

    // Live preview of the resulting balance as the amount is typed.
    $(document).on('input', '#asc_amount', function () {
        var delta = parseFloat($(this).val() || 0) || 0;
        var current = parseFloat($(this).data('current-balance') || 0) || 0;
        var next = (current + delta).toFixed(2);
        $('#asc_preview').text('New balance will be: $' + next);
        $('#asc_preview').css('color', (parseFloat(next) < 0) ? '#b91c1c' : '#555');
    });

    $(document).off('click.storeCredit', '#asc_submit')
      .on('click.storeCredit', '#asc_submit', function () {
        var $modal = $('#adjust_store_credit_modal');
        var contactId = $modal.data('contact-id');
        var delta = parseFloat($('#asc_amount').val() || 0) || 0;
        var reason = ($('#asc_reason').val() || '').trim();
        if (!contactId) { toastr.error('No contact selected.'); return; }
        if (!delta) { toastr.error('Enter a non-zero amount.'); return; }
        if (!reason) { toastr.error('Reason is required.'); return; }

        var onSuccess = $modal.data('on-success');
        $.ajax({
            method: 'POST',
            url: '/contacts/' + contactId + '/adjust-credit',
            dataType: 'json',
            data: { amount: delta, reason: reason, _token: $('meta[name="csrf-token"]').attr('content') },
            success: function (result) {
                if (result && result.success) {
                    toastr.success(result.msg);
                    $modal.modal('hide');
                    if (typeof onSuccess === 'function') { onSuccess(result); }
                } else {
                    toastr.error((result && result.msg) || 'Unable to adjust credit.');
                }
            },
            error: function () { toastr.error('Unable to adjust credit.'); }
        });
    });

    // Single delegated handler for the shared #customer_account_modal (POS,
    // contact list, Buy From Customer). All DOM updates are guarded so the
    // same handler is safe on every page. Replaces the per-page copies.
    $(document).off('click.storeCredit', '#modal_add_store_credit_btn')
      .on('click.storeCredit', '#modal_add_store_credit_btn', function () {
        var contactId = $('#modal_store_credit_contact_id').val();
        var amount = parseFloat($('#modal_store_credit_amount').val()) || 0;
        var reasonCode = $('#modal_store_credit_reason').val();

        postStoreCredit(contactId, amount, reasonCode, function (result) {
            $('#modal_account_balance').text(__currency_trans_from_en(result.new_balance || 0, true));
            $('#modal_store_credit_amount').val('');
            $('#modal_store_credit_reason').val('');
            // POS-only: keep the advance/store-credit payment row in sync.
            if ($('#customer_id').length && $('#customer_id').val() == contactId) {
                $('#advance_balance').val(result.new_balance || 0);
                $('#advance_balance_text').text(__currency_trans_from_en(result.new_balance || 0, true));
                if (typeof loadCustomerAccountInfo === 'function') { loadCustomerAccountInfo(contactId); }
            }
        });
    });
})();
</script>
