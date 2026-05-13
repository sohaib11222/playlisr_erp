{{-- Sarah 2026-05-13: behavior JS for the Clover-mismatch popup.
     Loaded in @section('javascript') AFTER jQuery + pos.js so we
     don't need to poll for jQuery — and crucially OUTSIDE the
     content section so a JS bug here can never disrupt the page
     layout. Wrapped in try/catch so even a thrown error keeps
     /pos checkout fully functional. --}}
<script>
(function () {
    try {
        if (typeof jQuery === 'undefined') return;
        var modalEl = document.getElementById('clover_mismatch_modal');
        if (!modalEl) return;

        var POLL_MS = 30000;
        var LATER_MS = 5 * 60 * 1000;
        var $modal = jQuery(modalEl);
        var current = null;
        var hiddenUntil = 0;
        var isOpen = false;
        var pendingUrl = "{{ route('pos.mismatchPending') }}";
        var explainUrl = "{{ route('pos.mismatchExplain') }}";

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
                prompt = 'Sale #' + (item.invoice_no || item.transaction_id) + ' shows ' + fmtMoney(item.erp_amount_cents) + ' in ERP and ' + fmtMoney(item.clover_amount_cents) + ' in Clover. Please make sure the exact total from ERP is entered into Clover. Explain the difference below.';
                detailErp = '<strong>ERP:</strong> ' + fmtMoney(item.erp_amount_cents) + ' &nbsp; (sale #' + (item.invoice_no || item.transaction_id) + ')';
                detailClover = '<strong>Clover charged:</strong> ' + fmtMoney(item.clover_amount_cents);
            } else if (item.type === 'no_clover') {
                prompt = 'Sale #' + (item.invoice_no || item.transaction_id) + ' shows ' + fmtMoney(item.erp_amount_cents) + ' in ERP, but there is no matching Clover charge. Please confirm the sale was rung up in Clover. All cash sales must also be entered into Clover. Explain the difference below.';
                detailErp = '<strong>ERP:</strong> ' + fmtMoney(item.erp_amount_cents) + ' &nbsp; (sale #' + (item.invoice_no || item.transaction_id) + ')';
                detailClover = '<strong>Clover:</strong> <span style="color:#B0451A;">no matching charge</span>';
            } else if (item.type === 'no_erp') {
                prompt = 'Clover charged ' + fmtMoney(item.clover_amount_cents) + ', but there is no matching ERP sale. Please make sure all sold products are added into ERP so inventory stays accurate. <a href="/pos/create" target="_blank" style="color:#1F1B16; text-decoration:underline; font-weight:600;">Add the sale to ERP →</a>';
                detailErp = '<strong>ERP:</strong> <span style="color:#B0451A;">no matching sale</span>';
                detailClover = '<strong>Clover charged:</strong> ' + fmtMoney(item.clover_amount_cents);
            }
            document.getElementById('clover_mismatch_prompt').innerHTML = prompt;
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
                url: pendingUrl,
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
            if (current._demo) { close(); return; }
            var btn = this; btn.disabled = true; btn.textContent = 'Saving…';
            jQuery.ajax({
                url: explainUrl,
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

        try {
            var qs = new URLSearchParams(window.location.search || '');
            var demoMode = qs.get('clover_mismatch_demo');
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
