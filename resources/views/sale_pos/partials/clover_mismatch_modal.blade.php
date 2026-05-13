{{-- Sarah 2026-05-13: pops a "explain or correct" modal whenever the
     cashier's current-day ERP↔Clover totals don't reconcile. Polls
     /pos/mismatch-pending every 30s; on a hit, shows one discrepancy
     at a time. Save = reason logged. Dismiss = comes back on next
     poll. Non-blocking — POS keeps working underneath. --}}
<div class="modal fade" id="clover_mismatch_modal" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
  <div class="modal-dialog modal-md" role="document">
    <div class="modal-content">
      <div class="modal-header" style="background:#FFF3D6; border-bottom:1px solid #E8C77A;">
        <button type="button" class="close" aria-label="Close" id="clover_mismatch_dismiss"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title" style="color:#6B4F12;">⚠ Clover vs ERP mismatch</h4>
      </div>
      <div class="modal-body">
        <p id="clover_mismatch_prompt" style="margin: 0 0 12px; font-size: 15px; line-height: 1.4;"></p>
        <div style="background:#fafafa; border:1px solid #eee; border-radius:6px; padding:10px 12px; margin-bottom:12px; font-size:13px;">
          <div id="clover_mismatch_detail_erp" style="margin-bottom:4px;"></div>
          <div id="clover_mismatch_detail_clover"></div>
        </div>
        <label for="clover_mismatch_reason" style="font-weight:600; margin-bottom:4px;">Why?</label>
        <textarea id="clover_mismatch_reason" class="form-control" rows="3" placeholder="e.g. discount given to customer, typo when keying Clover, voided on Clover but rang in ERP…" maxlength="2000"></textarea>
        <div id="clover_mismatch_error" style="color:#B0451A; margin-top:6px; display:none;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" id="clover_mismatch_later">Later</button>
        <button type="button" class="btn btn-primary" id="clover_mismatch_save">Save explanation</button>
      </div>
    </div>
  </div>
</div>
<script>
try { console.log('[clover_mismatch_modal] partial loaded'); } catch (_) {}
(function () {
    // Sarah 2026-05-13: hard-wrapped in try/catch so a popup bug can
    // never block /pos checkout. If anything throws on init, the rest
    // of the page keeps working — popup just silently doesn't appear.
    try {
    var POLL_MS = 30000;
    var LATER_MS = 5 * 60 * 1000;
    var modalEl = document.getElementById('clover_mismatch_modal');
    try { console.log('[clover_mismatch_modal] modalEl=', !!modalEl, 'jQuery=', typeof jQuery); } catch (_) {}
    if (!modalEl || typeof jQuery === 'undefined') {
        try { console.warn('[clover_mismatch_modal] early-exit: missing modal or jQuery'); } catch (_) {}
        return;
    }
    var $modal = jQuery(modalEl);
    var current = null;
    var hiddenUntil = 0;
    var isOpen = false;

    function fmtMoney(cents) {
        if (cents === null || cents === undefined) return '$0.00';
        var n = (cents | 0) / 100;
        return '$' + n.toFixed(2);
    }

    function render(item) {
        current = item;
        var prompt = '';
        var detailErp = '';
        var detailClover = '';
        if (item.type === 'mismatch') {
            prompt = 'You charged ' + fmtMoney(item.clover_amount_cents) + ' on Clover but ERP says ' + fmtMoney(item.erp_amount_cents) + ' for sale #' + (item.invoice_no || item.transaction_id) + '. Why?';
            detailErp = '<strong>ERP:</strong> ' + fmtMoney(item.erp_amount_cents) + ' &nbsp; (sale #' + (item.invoice_no || item.transaction_id) + ')';
            detailClover = '<strong>Clover charged:</strong> ' + fmtMoney(item.clover_amount_cents);
        } else if (item.type === 'no_clover') {
            prompt = 'You rang sale #' + (item.invoice_no || item.transaction_id) + ' for ' + fmtMoney(item.erp_amount_cents) + ' in ERP — but no matching Clover charge. Forgot to charge? Was it cash? Why?';
            detailErp = '<strong>ERP:</strong> ' + fmtMoney(item.erp_amount_cents) + ' &nbsp; (sale #' + (item.invoice_no || item.transaction_id) + ')';
            detailClover = '<strong>Clover:</strong> <span style="color:#B0451A;">no matching charge</span>';
        } else if (item.type === 'no_erp') {
            prompt = 'Clover shows a ' + fmtMoney(item.clover_amount_cents) + ' charge but no matching ERP sale. Did you forget to ring it in ERP? Why?';
            detailErp = '<strong>ERP:</strong> <span style="color:#B0451A;">no matching sale</span>';
            detailClover = '<strong>Clover charged:</strong> ' + fmtMoney(item.clover_amount_cents);
        }
        document.getElementById('clover_mismatch_prompt').textContent = prompt;
        document.getElementById('clover_mismatch_detail_erp').innerHTML = detailErp;
        document.getElementById('clover_mismatch_detail_clover').innerHTML = detailClover;
        document.getElementById('clover_mismatch_reason').value = '';
        document.getElementById('clover_mismatch_error').style.display = 'none';
    }

    function open(item) {
        if (isOpen) return;
        render(item);
        isOpen = true;
        $modal.modal('show');
    }

    function close() {
        isOpen = false;
        $modal.modal('hide');
        current = null;
    }

    function poll() {
        if (isOpen) return;
        if (Date.now() < hiddenUntil) return;
        jQuery.ajax({
            url: "{{ route('pos.mismatchPending') }}",
            method: 'GET',
            dataType: 'json',
            timeout: 15000
        }).done(function (resp) {
            var list = (resp && resp.pending) || [];
            if (!list.length) return;
            open(list[0]);
        }).fail(function () { /* swallow — POS must never crash */ });
    }

    document.getElementById('clover_mismatch_save').addEventListener('click', function () {
        if (!current) { close(); return; }
        var reason = (document.getElementById('clover_mismatch_reason').value || '').trim();
        if (reason.length < 3) {
            var err = document.getElementById('clover_mismatch_error');
            err.textContent = 'Please type a short reason (at least 3 characters).';
            err.style.display = 'block';
            return;
        }
        if (current._demo) {
            close();
            return;
        }
        var btn = this; btn.disabled = true; btn.textContent = 'Saving…';
        jQuery.ajax({
            url: "{{ route('pos.mismatchExplain') }}",
            method: 'POST',
            data: {
                _token: jQuery('meta[name="csrf-token"]').attr('content') || jQuery('input[name=_token]').val(),
                type: current.type,
                transaction_id: current.transaction_id || '',
                clover_payment_id: current.clover_payment_id || '',
                erp_amount_cents: current.erp_amount_cents,
                clover_amount_cents: current.clover_amount_cents,
                reason: reason
            },
            timeout: 15000
        }).done(function (resp) {
            btn.disabled = false; btn.textContent = 'Save explanation';
            if (resp && resp.ok) {
                close();
                setTimeout(poll, 600);
            } else {
                var err = document.getElementById('clover_mismatch_error');
                err.textContent = 'Save failed — try again.';
                err.style.display = 'block';
            }
        }).fail(function () {
            btn.disabled = false; btn.textContent = 'Save explanation';
            var err = document.getElementById('clover_mismatch_error');
            err.textContent = 'Save failed — try again.';
            err.style.display = 'block';
        });
    });

    document.getElementById('clover_mismatch_later').addEventListener('click', function () {
        hiddenUntil = Date.now() + LATER_MS;
        close();
    });
    document.getElementById('clover_mismatch_dismiss').addEventListener('click', function () {
        hiddenUntil = Date.now() + LATER_MS;
        close();
    });

    setTimeout(poll, 4000);
    setInterval(poll, POLL_MS);

    // Sarah 2026-05-13: ?clover_mismatch_demo=mismatch|no_clover|no_erp
    // shows the popup once on page load with fake data so Sarah can
    // preview the UI from home without a Clover terminal. Does NOT
    // write to the DB — Save button just closes the modal in demo mode.
    try {
        var qs = new URLSearchParams(window.location.search || '');
        var demoMode = qs.get('clover_mismatch_demo');
        try { console.log('[clover_mismatch_modal] demo mode =', demoMode); } catch (_) {}
        if (demoMode && ['mismatch', 'no_clover', 'no_erp'].indexOf(demoMode) !== -1) {
            var fake = {
                'mismatch': {
                    type: 'mismatch', transaction_id: 99999, clover_payment_id: null,
                    invoice_no: 'DEMO-99999', erp_amount_cents: 500, clover_amount_cents: 400,
                    _demo: true
                },
                'no_clover': {
                    type: 'no_clover', transaction_id: 99999, clover_payment_id: null,
                    invoice_no: 'DEMO-99999', erp_amount_cents: 1599, clover_amount_cents: null,
                    _demo: true
                },
                'no_erp': {
                    type: 'no_erp', transaction_id: null, clover_payment_id: 99999,
                    invoice_no: null, erp_amount_cents: null, clover_amount_cents: 1599,
                    _demo: true
                }
            }[demoMode];
            setTimeout(function () { open(fake); }, 800);
        }
    } catch (e) { /* swallow */ }

    } catch (e) {
        try { console && console.warn && console.warn('clover_mismatch_modal init error', e); } catch (_) {}
    }
})();
</script>
