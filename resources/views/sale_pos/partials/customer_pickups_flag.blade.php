{{-- ===========================================================
     Customer Pickup flag (POS sidebar)

     When the cashier pulls up a real rewards customer, this checks
     /customer-pickups/for-contact and, if they have anything waiting,
     shows a compact banner at the top of the customer-account-info box:

       * "Ready for pickup" — items in hand behind the counter.
       * "On order (AMS)" — special orders still inbound, with the
         AMS order # + ETA so the cashier can answer "where's my record?"

     Entirely self-contained and defensive: any failure is swallowed so
     it can never interfere with the POS sell flow.
     ============================================================ --}}
<style>
    .cpf-banner {
        margin: 8px 0 0;
        border-radius: 8px;
        font-family: "Inter Tight", system-ui, sans-serif;
        font-size: 12px;
        overflow: hidden;
        border: 1px solid #E8CF68;
        display: none;
    }
    .cpf-banner.cpf-visible { display: block; }
    .cpf-head {
        background: #FFF2B3; color: #5A4410;
        padding: 6px 12px; font-weight: 800;
        font-size: 11px; letter-spacing: .04em; text-transform: uppercase;
        display: flex; align-items: center; gap: 6px;
    }
    .cpf-section { padding: 8px 12px; background: #FFFDF5; }
    .cpf-section + .cpf-section { border-top: 1px solid #F0E4BC; }
    .cpf-section-title { font-size: 10px; font-weight: 800; color: #8E8273; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 4px; }
    .cpf-item { padding: 3px 0; color: #1F1B16; }
    .cpf-item .cpf-label { font-weight: 600; }
    .cpf-meta { color: #8E8273; font-size: 10px; }
    .cpf-onorder .cpf-section-title { color: #2C5F8A; }
    .cpf-unpaid { color: #8A3A2E; font-weight: 700; font-size: 10px; }
</style>

<script>
(function () {
    function onReady(fn) {
        if (typeof jQuery === 'undefined') { setTimeout(function () { onReady(fn); }, 50); return; }
        jQuery(fn);
    }
    onReady(function ($) {
        function currentContactId() {
            var id = parseInt($('#customer_id').val(), 10);
            var walkIn = parseInt($('#default_customer_id').val(), 10);
            if (!id || id <= 0) return null;
            if (walkIn && id === walkIn) return null;
            return id;
        }

        function ensureBanner() {
            var $box = $('#customer_account_info');
            if (!$box.length) return null;
            if (!$box.find('#cpf-banner').length) {
                $box.prepend('<div class="cpf-banner" id="cpf-banner"></div>');
            }
            return $box.find('#cpf-banner');
        }

        function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

        function itemHtml(it, showAms) {
            var html = '<div class="cpf-item"><span class="cpf-label">' + esc(it.label) + '</span>';
            if (it.qty > 1) html += ' <span class="cpf-meta">×' + it.qty + '</span>';
            if (!it.is_paid) html += ' <span class="cpf-unpaid">UNPAID</span>';
            var meta = [];
            if (showAms && it.ams_order_number) meta.push('AMS #' + esc(it.ams_order_number));
            if (showAms && it.expected_date) meta.push('ETA ' + esc(it.expected_date));
            if (meta.length) html += '<div class="cpf-meta">' + meta.join(' · ') + '</div>';
            html += '</div>';
            return html;
        }

        function render(data) {
            var $banner = ensureBanner();
            if (!$banner) return;
            var ready = (data && data.ready) || [];
            var onOrder = (data && data.on_order) || [];
            if (!ready.length && !onOrder.length) {
                $banner.removeClass('cpf-visible').empty();
                return;
            }
            var total = ready.length + onOrder.length;
            var html = '<div class="cpf-head"><i class="fa fa-box"></i> ' + total + ' order' + (total === 1 ? '' : 's') + ' on file</div>';
            if (ready.length) {
                html += '<div class="cpf-section"><div class="cpf-section-title">Ready for pickup</div>';
                ready.forEach(function (it) { html += itemHtml(it, false); });
                html += '</div>';
            }
            if (onOrder.length) {
                html += '<div class="cpf-section cpf-onorder"><div class="cpf-section-title">On order (AMS)</div>';
                onOrder.forEach(function (it) { html += itemHtml(it, true); });
                html += '</div>';
            }
            $banner.html(html).addClass('cpf-visible');
        }

        function reload() {
            var $banner = ensureBanner();
            var cid = currentContactId();
            if (!cid) { if ($banner) $banner.removeClass('cpf-visible').empty(); return; }
            $.get('/customer-pickups/for-contact/' + cid)
                .done(render)
                .fail(function () { if ($banner) $banner.removeClass('cpf-visible').empty(); });
        }

        $(document).on('change', '#customer_id', reload);
        $(document).on('customer:loaded customer:cleared', reload);
        setTimeout(reload, 500);
    });
})();
</script>
