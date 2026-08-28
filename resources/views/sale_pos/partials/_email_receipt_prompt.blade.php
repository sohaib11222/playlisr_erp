{{-- Post-sale "want a receipt emailed to you?" prompt.
     Opens right after a sale finalizes (see pos.js, right after
     pos_print(result.receipt)). Manual, opt-in — no auto-send, no
     pre-filled email — the cashier asks the customer and types it in.
     Entirely decoupled from the sell-finalize response: a failure here
     is a toast, never a broken sale. --}}
<div class="modal fade" id="email_receipt_modal" tabindex="-1" role="dialog" aria-labelledby="email_receipt_title">
    <div class="modal-dialog modal-sm" role="document" style="margin-top: 14vh;">
        <div class="modal-content" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header" style="border: none; padding: 14px 18px;">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="email_receipt_title" style="font-weight: 700;">
                    Email a receipt?
                </h4>
            </div>
            <div class="modal-body" style="padding: 4px 20px 20px;">
                <input type="email" id="email_receipt_input" class="form-control" placeholder="customer@example.com" autocomplete="off" style="margin-bottom: 14px;">
                <button type="button" class="btn btn-block btn-primary" id="email_receipt_send_btn">
                    Send Receipt
                </button>
                <button type="button" class="btn btn-block btn-default" data-dismiss="modal" style="margin-top: 8px;">
                    Skip
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    function boot() {
        if (window.jQuery) { initEmailReceiptPrompt(window.jQuery); }
        else { setTimeout(boot, 50); }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else { boot(); }
})();

function initEmailReceiptPrompt($) {
    var $modal = $('#email_receipt_modal');
    var $input = $('#email_receipt_input');
    var $sendBtn = $('#email_receipt_send_btn');
    var currentTxId = null;

    // Called from pos.js right after a sale finalizes. Guarded with typeof
    // there, so a missing/broken partial never touches the sell flow.
    // customerEmail pre-fills from the attached contact's email on file (if
    // any) — still requires the cashier to hit Send, never auto-sends.
    window.__promptEmailReceipt = function (transactionId, customerEmail) {
        if (!transactionId) { return; }
        currentTxId = transactionId;
        $input.val(customerEmail || '');
        $sendBtn.prop('disabled', false).text('Send Receipt');
        $modal.modal({ backdrop: true, keyboard: true });
        setTimeout(function () {
            $input.focus();
            if (customerEmail) { $input.select(); }
        }, 300);
    };

    function isValidEmail(v) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
    }

    $sendBtn.on('click', function () {
        var email = $.trim($input.val());
        if (!isValidEmail(email)) {
            toastr.warning('Enter a valid email address.');
            $input.focus();
            return;
        }
        if (!currentTxId) { return; }

        $sendBtn.prop('disabled', true).text('Sending...');
        $.ajax({
            url: '/pos/' + currentTxId + '/email-receipt',
            method: 'POST',
            data: {
                _token: $('meta[name="csrf-token"]').attr('content'),
                email: email
            },
            dataType: 'json',
            success: function (result) {
                if (result && result.success) {
                    toastr.success(result.msg || 'Receipt emailed.');
                    $modal.modal('hide');
                } else {
                    toastr.error((result && result.msg) || 'Could not send — try again.');
                    $sendBtn.prop('disabled', false).text('Send Receipt');
                }
            },
            error: function () {
                toastr.error('Could not send — try again.');
                $sendBtn.prop('disabled', false).text('Send Receipt');
            }
        });
    });

    $input.on('keydown', function (e) {
        if (e.which === 13) {
            e.preventDefault();
            $sendBtn.trigger('click');
        }
    });
}
</script>
