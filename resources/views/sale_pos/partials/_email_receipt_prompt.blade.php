{{-- Post-sale "want a receipt?" prompt — email or text.
     Opens right after a sale finalizes (see pos.js, right after
     pos_print(result.receipt)). Manual, opt-in — no auto-send, no
     pre-filled email/phone unless a customer's already attached to the
     sale. Entirely decoupled from the sell-finalize response: a failure
     here is a toast, never a broken sale. --}}
<div class="modal fade" id="email_receipt_modal" tabindex="-1" role="dialog" aria-labelledby="email_receipt_title">
    <div class="modal-dialog modal-sm" role="document" style="margin-top: 14vh;">
        <div class="modal-content" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header" style="border: none; padding: 14px 18px;">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="email_receipt_title" style="font-weight: 700;">
                    Send a receipt?
                </h4>
            </div>
            <div class="modal-body" style="padding: 4px 20px 20px;">
                <label style="font-size: 12px; color: #888; margin-bottom: 4px;">Email</label>
                <input type="email" id="email_receipt_input" class="form-control" placeholder="customer@example.com" autocomplete="off" style="margin-bottom: 10px;">
                <button type="button" class="btn btn-block btn-primary" id="email_receipt_send_btn">
                    Email Receipt
                </button>

                <label style="font-size: 12px; color: #888; margin: 14px 0 4px;">Phone</label>
                <input type="tel" id="text_receipt_input" class="form-control" placeholder="(213) 555-0100" autocomplete="off" style="margin-bottom: 10px;">
                <button type="button" class="btn btn-block btn-primary" id="text_receipt_send_btn">
                    Text Receipt
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
    var $emailInput = $('#email_receipt_input');
    var $emailBtn = $('#email_receipt_send_btn');
    var $phoneInput = $('#text_receipt_input');
    var $phoneBtn = $('#text_receipt_send_btn');
    var currentTxId = null;

    // Called from pos.js right after a sale finalizes. Guarded with typeof
    // there, so a missing/broken partial never touches the sell flow.
    // customerEmail/customerPhone pre-fill from the attached contact's info
    // on file (if any) — still requires the cashier to hit Send, never
    // auto-sends either channel.
    window.__promptEmailReceipt = function (transactionId, customerEmail, customerPhone) {
        if (!transactionId) { return; }
        currentTxId = transactionId;
        $emailInput.val(customerEmail || '');
        $emailBtn.prop('disabled', false).text('Email Receipt');
        $phoneInput.val(customerPhone || '');
        $phoneBtn.prop('disabled', false).text('Text Receipt');
        $modal.modal({ backdrop: true, keyboard: true });
        setTimeout(function () {
            $emailInput.focus();
            if (customerEmail) { $emailInput.select(); }
        }, 300);
    };

    function isValidEmail(v) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
    }

    function isValidPhone(v) {
        return v.replace(/\D/g, '').length >= 10;
    }

    $emailBtn.on('click', function () {
        var email = $.trim($emailInput.val());
        if (!isValidEmail(email)) {
            toastr.warning('Enter a valid email address.');
            $emailInput.focus();
            return;
        }
        if (!currentTxId) { return; }

        $emailBtn.prop('disabled', true).text('Sending...');
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
                    $emailBtn.prop('disabled', false).text('Email Receipt');
                }
            },
            error: function () {
                toastr.error('Could not send — try again.');
                $emailBtn.prop('disabled', false).text('Email Receipt');
            }
        });
    });

    $phoneBtn.on('click', function () {
        var phone = $.trim($phoneInput.val());
        if (!isValidPhone(phone)) {
            toastr.warning('Enter a valid phone number.');
            $phoneInput.focus();
            return;
        }
        if (!currentTxId) { return; }

        $phoneBtn.prop('disabled', true).text('Sending...');
        $.ajax({
            url: '/pos/' + currentTxId + '/text-receipt',
            method: 'POST',
            data: {
                _token: $('meta[name="csrf-token"]').attr('content'),
                phone: phone
            },
            dataType: 'json',
            success: function (result) {
                if (result && result.success) {
                    toastr.success(result.msg || 'Receipt texted.');
                    $modal.modal('hide');
                } else {
                    toastr.error((result && result.msg) || 'Could not send — try again.');
                    $phoneBtn.prop('disabled', false).text('Text Receipt');
                }
            },
            error: function () {
                toastr.error('Could not send — try again.');
                $phoneBtn.prop('disabled', false).text('Text Receipt');
            }
        });
    });

    $emailInput.on('keydown', function (e) {
        if (e.which === 13) {
            e.preventDefault();
            $emailBtn.trigger('click');
        }
    });
    $phoneInput.on('keydown', function (e) {
        if (e.which === 13) {
            e.preventDefault();
            $phoneBtn.trigger('click');
        }
    });
}
</script>
