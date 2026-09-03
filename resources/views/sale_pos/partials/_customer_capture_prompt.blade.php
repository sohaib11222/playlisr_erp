{{-- Customer-capture script prompt — Sarah 2026-07-19.
     Fires on /pos/create load so the cashier runs the same script on every
     walk-in, up front (phone-first), instead of at finalize time. Two
     branches: (1) has an account → focus the phone/name search; (2) no
     account → read the spend-reward pitch and optionally sign them up.
     Reward numbers come straight from the business settings (Settings →
     Store Credit Rewards) so the script never goes stale. --}}
@php
    $ccEnabled = session('business.enable_spend_credit_reward');
    $ccEnabled = is_null($ccEnabled) ? true : (bool) $ccEnabled;
    $ccAmount  = session('business.spend_credit_reward_amount');
    $ccAmount  = ($ccAmount === null || $ccAmount === '') ? 5 : (float) $ccAmount;
    $ccPer     = session('business.spend_credit_reward_per');
    $ccPer     = ((float) $ccPer > 0) ? (float) $ccPer : 100;
    $ccSym     = session('currency.symbol', '$');
    // Trim trailing .00 so "$5" reads better than "$5.00" in the script.
    $ccAmountF = rtrim(rtrim(number_format($ccAmount, 2), '0'), '.');
    $ccPerF    = rtrim(rtrim(number_format($ccPer, 2), '0'), '.');
@endphp
@if($ccEnabled)
<div class="modal fade" id="customer_capture_modal" tabindex="-1" role="dialog" aria-labelledby="customer_capture_title">
    <div class="modal-dialog modal-sm" role="document" style="margin-top: 12vh;">
        <div class="modal-content" style="border-radius: 12px; overflow: hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #1b6ca8, #13507a); color: #fff; border: none; padding: 14px 18px;">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color:#fff; opacity:.85; text-shadow:none;"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="customer_capture_title" style="font-weight: 800; letter-spacing:.3px;">
                    <i class="fa fa-user-plus"></i>&nbsp; New sale
                </h4>
            </div>

            {{-- Step 1: the ask --}}
            <div class="modal-body cc-step" id="cc_step_ask" style="padding: 22px 20px;">
                <p style="font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color:#8a9ba8; font-weight:700; margin-bottom:6px;">Ask the customer</p>
                <p style="font-size: 21px; font-weight: 700; color:#1f2937; line-height:1.3; margin-bottom:20px;">
                    &ldquo;Do you have an account with us?&rdquo;
                </p>
                <button type="button" class="btn btn-block cc-btn-yes" style="background:#1b6ca8; color:#fff; font-weight:700; padding:12px; border-radius:8px; font-size:15px; margin-bottom:10px;">
                    <i class="fa fa-search"></i>&nbsp; Yes — look up their account
                </button>
                <button type="button" class="btn btn-block cc-btn-no" style="background:#fde68a; color:#78350f; font-weight:800; padding:12px; border-radius:8px; font-size:15px; border:2px solid #f59e0b;">
                    <i class="fa fa-star"></i>&nbsp; No account yet
                </button>
            </div>

            {{-- Step 2: the pitch (hidden until "No account yet") --}}
            <div class="modal-body cc-step" id="cc_step_pitch" style="padding: 22px 20px; display:none;">
                <p style="font-size: 12px; text-transform: uppercase; letter-spacing: 1px; color:#8a9ba8; font-weight:700; margin-bottom:6px;">Say to the customer</p>
                <p style="font-size: 18px; font-weight: 600; color:#1f2937; line-height:1.4; margin-bottom:18px;">
                    &ldquo;Spend <strong style="color:#b45309;">{{ $ccSym }}{{ $ccPerF }}</strong> with us over time and get <strong style="color:#b45309;">{{ $ccSym }}{{ $ccAmountF }} in store credit</strong> — it&rsquo;s a free rewards program! Want me to sign you up? I just need your name and phone number.&rdquo;
                </p>
                <button type="button" class="btn btn-block cc-btn-signup" style="background:#f59e0b; color:#78350f; font-weight:800; padding:12px; border-radius:8px; font-size:15px; margin-bottom:10px;">
                    <i class="fa fa-user-plus"></i>&nbsp; Sign them up
                </button>
                <button type="button" class="btn btn-block btn-default cc-btn-skip" data-dismiss="modal" style="padding:10px; border-radius:8px; font-weight:600;">
                    Maybe next time
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// NOTE: this partial renders inside the page <body>, but jQuery/Bootstrap/
// select2 are all loaded at the BOTTOM of the layout (@yield('javascript')).
// So we must NOT touch `$` at parse time — defer until DOMContentLoaded (by
// which point those scripts have run), with a short poll as a safety net.
(function () {
    function boot() {
        // jQuery is loaded at the bottom of the body — wait for it if needed.
        if (window.jQuery) { initCustomerCapture(window.jQuery); }
        else { setTimeout(boot, 50); }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else { boot(); }
})();

function initCustomerCapture($) {
    // Only prompt on a fresh walk-in sale — never on edit/draft resume where a
    // real customer may already be attached.
    var isFreshSale = /\/pos\/create\b/.test(window.location.pathname);
    if (!isFreshSale) { return; }

    // Don't stack on top of the close-register modal that auto-opens on sign-out.
    if (/[?&]close_register=1\b/.test(window.location.search)) { return; }

    var $modal = $('#customer_capture_modal');

    function showStep(id) {
        $modal.find('.cc-step').hide();
        $modal.find('#' + id).show();
    }

    // Reset to the ask step and (re)open the prompt. Used on first load AND
    // after every completed sale.
    function openPrompt() {
        showStep('cc_step_ask');
        $modal.modal({ backdrop: true, keyboard: true });
    }

    // Bootstrap 3 modal; show once the POS screen has settled.
    setTimeout(openPrompt, 350);

    // Re-open the prompt for the NEXT walk-in after a sale finalizes. The POS
    // clears the cart with reset_pos_form() (a top-level global — see pos.js)
    // in place, with NO page reload, so DOMContentLoaded never fires again to
    // re-trigger the modal. Wrap that global so the prompt comes back. Poll
    // briefly in case pos.js hasn't defined it yet at this point.
    (function installResetHook(attempts) {
        if (typeof window.reset_pos_form === 'function') {
            if (!window.__cc_reset_wrapped) {
                window.__cc_reset_wrapped = true;
                var _ccOrigReset = window.reset_pos_form;
                window.reset_pos_form = function () {
                    var ret = _ccOrigReset.apply(this, arguments);
                    // Skip the edit-page branch — it navigates away on its own,
                    // and the fresh /pos/create load there re-shows the prompt.
                    if ($('form#edit_pos_sell_form').length === 0) {
                        scheduleReopen();
                    }
                    return ret;
                };
            }
        } else if (attempts > 0) {
            setTimeout(function () { installResetHook(attempts - 1); }, 100);
        }
    })(20);

    // The "want a receipt?" prompt (pos.js) opens on this same post-sale
    // tick, ~350ms out, with its own backdrop:true. Opening this modal at
    // the same time stacked a second backdrop on top of it and ate clicks
    // on the receipt modal's email/phone inputs. Wait for the receipt
    // prompt to actually close before showing this one; fall back to a
    // fixed delay if it never opens at all (e.g. sale had no transaction_id).
    function scheduleReopen() {
        var $receipt = $('#email_receipt_modal');
        if (!$receipt.length) {
            setTimeout(openPrompt, 500);
            return;
        }
        var opened = false;
        var doOpen = function () {
            if (opened) { return; }
            opened = true;
            openPrompt();
        };
        $receipt.one('hidden.bs.modal', doOpen);
        setTimeout(function () {
            if (!$receipt.hasClass('in')) { doOpen(); }
        }, 900);
    }

    // Yes → close and drop the cashier straight into the phone/name search.
    $modal.on('click', '.cc-btn-yes', function () {
        $modal.one('hidden.bs.modal', function () {
            try { $('#customer_id').select2('open'); } catch (e) {}
        });
        $modal.modal('hide');
    });

    // No account → reveal the rewards pitch.
    $modal.on('click', '.cc-btn-no', function () {
        showStep('cc_step_pitch');
    });

    // Sign up → hand off to the existing new-customer flow once we're hidden.
    $modal.on('click', '.cc-btn-signup', function () {
        $modal.one('hidden.bs.modal', function () {
            $('.add_new_customer').first().trigger('click');
        });
        $modal.modal('hide');
    });

    // Always reset to step 1 so a later re-open starts on the ask again.
    $modal.on('hidden.bs.modal', function () {
        showStep('cc_step_ask');
    });
}
</script>
@endif
