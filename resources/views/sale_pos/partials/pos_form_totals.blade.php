{{-- ===========================================================
     POS totals + receipt + payment (Sarah's 2026-04-21 mockup)

     Rewritten per nivessa_sale_column.html so the entire "sale column"
     (customer + cart + receipt + Cash/Card buttons) fits in one card
     without scrolling. Key changes:
       - Tight card padding (14px), tight row padding (3px).
       - Receipt row order: Discount → Shipping → Bag Fee → Subtotal
         (with items chip) → hero Pre-Tax → Clover bar → Tax → Grand.
       - Bag-fee toggle sits at the top of the receipt, with a +/-
         stepper so cashiers can add multiple bags inline.
       - Payment buttons (Cash / Card / More) render IMMEDIATELY
         below the grand total, in the same card. Cancel Sale is a
         subtle link below that.

     All existing IDs, spans, hidden inputs, and handlers are preserved
     so pos.js and pos_form_actions.blade.php keep working without
     modification. Any elements not visible in the mockup (Mark as
     Whatnot, Store Credit row) are kept in the markup but rendered as
     unobtrusive chips above the receipt.
     ============================================================ --}}

<style>
    /* Compact card — receipt + payment all live here, no scrolling. */
    .pos-tot-block {
        background: #fff;
        border: 1px solid #ECE3CF;
        border-radius: 10px;
        padding: 14px 16px;
        margin-top: 10px;
        box-shadow: 0 1px 2px rgba(31, 27, 22, .06);
        color: #1F1B16;
        font-family: "Inter Tight", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }

    /* Sale-flag chips row (Whatnot, Store Credit). Bag gets its own
       dedicated toggle below — the chip version is hidden. */
    .pos-tot-flags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
    .pos-tot-chip {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 4px 10px; border-radius: 999px;
        background: transparent; border: 1px dashed #DFD2B3;
        font-size: 11px; font-weight: 500; color: #8E8273;
        cursor: pointer;
    }
    .pos-tot-chip input[type="checkbox"] { margin: 0; }
    .pos-tot-chip.active-whatnot, #whatnot_chip.active-whatnot {
        background: #FFF9DB; border-color: #E8CF68; color: #5A4410; font-weight: 600;
    }
    #bag_chip { display: none; }  /* replaced by .bag-toggle below */
    #pos_store_credit_row.pos-tot-chip { background: #dcfce7; border-color: #22c55e; color: #14532d; font-weight: 600; border-style: solid; }

    /* Bag Fee toggle + stepper (per mockup — mustard chip w/ inline +/−). */
    .bag-toggle {
        display: inline-flex; align-items: center; gap: 8px;
        padding: 5px 12px; background: #FFF9DB;
        border: 1px solid #E8CF68; border-radius: 999px;
        font-size: 12px; color: #5A4410; font-weight: 600;
        margin-bottom: 10px;
    }
    .bag-toggle input[type="checkbox"] { accent-color: #E8CF68; margin: 0; }
    .bag-stepper {
        display: inline-flex; align-items: center;
        background: #fff; border: 1px solid #E8CF68; border-radius: 999px;
    }
    .bag-stepper button {
        border: none; background: transparent;
        width: 22px; height: 20px;
        font-size: 13px; font-weight: 700; color: #5A4410; cursor: pointer;
        padding: 0;
    }
    .bag-stepper button:hover { background: #FFF2B3; border-radius: 999px; }
    .bag-stepper .bag-count {
        min-width: 16px; text-align: center; font-weight: 700; font-size: 12px; color: #5A4410;
    }

    /* Receipt rows — tight 3px padding, simple label : amount layout. */
    .pos-receipt { margin: 0; }
    .pos-receipt .row {
        display: flex; justify-content: space-between; align-items: baseline;
        padding: 3px 0; font-size: 13px; gap: 16px;
        margin: 0;  /* Override Bootstrap's negative-margin .row */
    }
    .pos-receipt .label { color: #5A5045; display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .pos-receipt .amt { color: #1F1B16; font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .pos-receipt .muted { color: #8E8273; font-size: 11px; font-weight: 400; }
    .pos-receipt .rate { color: #8E8273; font-size: 11px; font-weight: 500; }
    .pos-receipt .edit {
        font-size: 10px; color: #5A5045;
        padding: 1px 6px; border: 1px solid #ECE3CF;
        border-radius: 4px; background: #fff; cursor: pointer;
        line-height: 1.3;
    }
    .pos-receipt .edit:hover { background: #F7F1E3; }
    .pos-receipt .edit i { font-size: 9px; margin-right: 2px; }
    .pos-receipt .divider { border-top: 1px dashed #DFD2B3; margin: 5px 0; }
    .pos-receipt .row.subtotal { padding: 5px 0; }
    .pos-receipt .row.subtotal .label { font-weight: 700; color: #1F1B16; font-size: 13px; }
    .pos-receipt .row.subtotal .amt { font-weight: 700; font-size: 14px; }
    .pos-receipt .items-inline {
        font-size: 10px; color: #8E8273; font-weight: 500;
        background: #F7F1E3; padding: 1px 7px; border-radius: 999px;
        margin-left: 4px;
    }

    /* Discount dropdown menu (Manual / Preset). */
    .adj-discount-wrap { position: relative; display: inline-block; }
    .adj-discount-menu {
        display: none; position: absolute; top: calc(100% + 4px); left: 0;
        background: #fff; border: 1px solid #ECE3CF; border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,.08); padding: 4px; z-index: 30; min-width: 180px;
    }
    .adj-discount-wrap.open .adj-discount-menu { display: block; }
    .adj-discount-menu button {
        display: flex; align-items: center; gap: 8px; width: 100%;
        border: 0; background: transparent; text-align: left;
        padding: 7px 10px; border-radius: 6px;
        font-size: 12px; font-weight: 500; color: #5A5045; cursor: pointer;
    }
    .adj-discount-menu button:hover { background: #F7F1E3; }
    .adj-discount-menu button i { width: 14px; color: #8E8273; }

    /* Pre-Tax → Clover — the obvious bar. Most visible number on the page. */
    .pos-pretax-bar {
        position: relative;
        display: flex; align-items: center; justify-content: space-between; gap: 16px;
        background: #FFF2B3;
        border: 2px solid #E8CF68;
        border-radius: 10px;
        padding: 12px 16px;
        margin: 10px 0 6px;
        box-shadow: 0 0 0 3px rgba(255, 242, 179, .4);
    }
    .pos-pretax-bar::before {
        content: "KEY THIS INTO CLOVER";
        position: absolute; top: -9px; left: 14px;
        background: #1F1B16; color: #FFF2B3;
        font-size: 9px; font-weight: 800;
        letter-spacing: .14em; padding: 3px 9px;
        border-radius: 999px;
    }
    .pos-pretax-bar .pretax-label {
        display: flex; flex-direction: column; gap: 1px;
        color: #5A4410; font-weight: 700;
        font-size: 11px; text-transform: uppercase; letter-spacing: .08em;
    }
    .pos-pretax-bar .pretax-sub {
        font-size: 10px; font-weight: 500; text-transform: none;
        letter-spacing: 0; opacity: .72;
    }
    .pos-pretax-bar .pretax-val {
        color: #5A4410; font-weight: 800;
        font-size: 26px; letter-spacing: -.02em;
        font-variant-numeric: tabular-nums;
    }

    /* Grand total row */
    .pos-receipt .row.grand { padding: 6px 0 2px; }
    .pos-receipt .row.grand .label {
        font-weight: 700; color: #1F1B16; font-size: 14px;
        display: flex; flex-direction: column; gap: 1px; align-items: flex-start;
    }
    .pos-receipt .row.grand .amt {
        font-size: 22px; font-weight: 800; letter-spacing: -.01em;
    }

    /* Payment buttons — Cash / Card / More, grid row flush against the grand
       total. Cash green, Card brown (brand), More neutral. Classes & IDs
       preserved from the old pos_form_actions markup so pos.js handlers
       (.pos-express-finalize, #pos-finalize) keep firing untouched. */
    .pos-pay-row {
        display: grid; grid-template-columns: 1fr 1fr auto;
        gap: 8px; margin-top: 12px;
    }
    .pos-pay-btn {
        padding: 10px 14px; border-radius: 7px;
        font-weight: 600; font-size: 13px;
        border: none; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center; gap: 7px;
        letter-spacing: .02em;
        line-height: 1.2;
    }
    .pos-pay-btn.pay-cash { background: #2F6B3E; color: #fff; }
    .pos-pay-btn.pay-cash:hover, .pos-pay-btn.pay-cash:focus { background: #235530; color: #fff; }
    .pos-pay-btn.pay-card { background: #1F1B16; color: #FAF6EE; }
    .pos-pay-btn.pay-card:hover, .pos-pay-btn.pay-card:focus { background: #0F0A06; color: #FAF6EE; }
    .pos-pay-btn.pay-more { background: #fff; color: #1F1B16; border: 1px solid #DFD2B3; }
    .pos-pay-btn.pay-more:hover, .pos-pay-btn.pay-more:focus { background: #F7F1E3; color: #1F1B16; }
    .pos-pay-more { display: inline-block; }
    .pos-pay-more .dropdown-menu { min-width: 220px; padding: 4px; }
    .pos-pay-more .dropdown-menu .btn-link {
        display: block; width: 100%; text-align: left;
        padding: 8px 14px; font-weight: 500; text-decoration: none; color: #1F1B16;
    }
    .pos-pay-more .dropdown-menu .btn-link:hover { background: #F7F1E3; text-decoration: none; }

    /* Cancel Sale — subtle text link right under the pay row. */
    .pos-cancel-wrap { text-align: center; margin-top: 8px; }
    .pos-cancel-wrap .pos-cancel-link {
        display: inline-block; background: transparent; border: 0;
        color: #8E8273; font-size: 12px; padding: 4px 8px;
        text-transform: uppercase; letter-spacing: .05em; font-weight: 500;
        cursor: pointer;
    }
    .pos-cancel-wrap .pos-cancel-link:hover { color: #8A3A2E; text-decoration: underline; }
</style>

<div class="pos_form_totals">
    <input type="hidden" name="store_credit_used_amount" id="store_credit_used_amount" value="0">

    <div class="pos-tot-block">
        {{-- Sale flags row — Store Credit chip (when applicable). "Mark as
             Whatnot" moved up into the cart/scan area (pos_form.blade.php)
             since it's a cart-scope flag, not a totals-scope one. --}}
        <div class="pos-tot-flags">
            @if(!empty($pos_settings['enable_plastic_bag_charge']))
            {{-- Hidden but kept so pos.js can read #bag_chip / #add_plastic_bag / #plastic_bag_price. --}}
            <label id="bag_chip" style="display:none;">
                <input type="checkbox" id="add_plastic_bag" name="add_plastic_bag" value="1" checked>
                <span id="plastic_bag_price_display">(${{ number_format($pos_settings['plastic_bag_price'] ?? 0.10, 2) }})</span>
                <input type="hidden" id="plastic_bag_price" value="{{ $pos_settings['plastic_bag_price'] ?? 0.10 }}">
            </label>
            @endif
            <div id="pos_store_credit_row" class="pos-tot-chip store-credit" style="display:none; cursor:default;">
                <span>Store credit:</span>
                <span id="pos_store_credit_amount" style="font-weight:700;">$0.00</span>
                <button type="button" class="edit" id="btn_use_store_credit">Use it</button>
            </div>
        </div>

        {{-- Bag-fee visible toggle with stepper (per mockup). Linked to
             #add_plastic_bag above via JS — when this is checked, the
             hidden checkbox is checked too, and the bag-fee row in the
             cart table picks up the quantity. --}}
        @if(!empty($pos_settings['enable_plastic_bag_charge']))
        <label class="bag-toggle" id="bag-toggle-visible">
            <input type="checkbox" id="bag-toggle-checkbox" checked>
            <span>Bag Fee</span>
            <div class="bag-stepper" onclick="event.preventDefault();">
                <button type="button" id="bag-step-minus" aria-label="Remove a bag">−</button>
                <span class="bag-count" id="bag-count">1</span>
                <button type="button" id="bag-step-plus" aria-label="Add a bag">+</button>
            </div>
            <span>· $<span id="bag-total-visible">{{ number_format($pos_settings['plastic_bag_price'] ?? 0.10, 2) }}</span></span>
        </label>
        @endif

        <div class="pos-receipt">
            {{-- Discount row --}}
            @if($is_discount_enabled)
            <div class="row">
                <span class="label">
                    Discount
                    <span class="adj-discount-wrap" id="adj-discount-wrap">
                        <button type="button" class="edit" id="adj-discount-toggle" aria-haspopup="true" aria-expanded="false"><i class="fa fa-pencil-alt"></i> Edit ▾</button>
                        <div class="adj-discount-menu" role="menu">
                            <button type="button" id="pos-manual-discount"><i class="fa fa-percent"></i> Manual discount</button>
                            <button type="button" id="pos-preset-discount"><i class="fa fa-tags"></i> Preset discount</button>
                            @if($edit_discount)
                                <button type="button" id="pos-edit-discount" data-toggle="modal" data-target="#posEditDiscountModal"><i class="fa fa-edit"></i> @lang('sale.edit_discount')</button>
                            @endif
                        </div>
                    </span>
                </span>
                <span class="amt">− $<span id="total_discount">0</span></span>
            </div>
            @endif

            {{-- Shipping row --}}
            <div class="row">
                <span class="label">
                    Shipping
                    <button type="button" class="edit" title="@lang('sale.shipping')" data-toggle="modal" data-target="#posShippingModal"><i class="fa fa-pencil-alt"></i> Edit</button>
                </span>
                <span class="amt">+ $<span id="shipping_charges_amount">0</span></span>
            </div>

            {{-- Packing (only when types_of_service module is enabled) --}}
            @if(in_array('types_of_service', $enabled_modules))
            <div class="row">
                <span class="label">
                    Packing
                    <button type="button" class="edit service_modal_btn"><i class="fa fa-pencil-alt"></i> Edit</button>
                </span>
                <span class="amt">+ $<span id="packing_charge_text">0</span></span>
            </div>
            @endif

            {{-- Bag Fee row (shown in receipt when enabled) --}}
            @if(!empty($pos_settings['enable_plastic_bag_charge']))
            <div class="row" id="pos-bag-fee-row">
                <span class="label">Bag Fee <span class="muted" id="bag-fee-muted-label">(1 bag)</span></span>
                <span class="amt">+ $<span id="bag-fee-amount">{{ number_format($pos_settings['plastic_bag_price'] ?? 0.10, 2) }}</span></span>
            </div>
            @endif

            {{-- Round off (only when enabled) --}}
            @if(!empty($pos_settings['amount_rounding_method']) && $pos_settings['amount_rounding_method'] > 0)
            <div class="row">
                <span class="label" id="round_off">Round off</span>
                <span class="amt"><span id="round_off_text">0</span><input type="hidden" name="round_off_amount" id="round_off_amount" value=0></span>
            </div>
            @endif

            <div class="divider"></div>

            {{-- Subtotal (item subtotal, bold, with items count chip inline) --}}
            <div class="row subtotal">
                <span class="label">
                    Subtotal
                    <span class="items-inline"><span class="total_quantity">0</span> items</span>
                </span>
                <span class="amt">$<span class="price_total">0</span></span>
            </div>

            {{-- Pre-Tax → Clover — full-width hero bar. #pre_tax_amount is
                 populated by pos.js (pre-tax after discount/shipping/packing
                 — the exact value Clover expects). --}}
            <div class="pos-pretax-bar" title="Type this amount into the Clover terminal">
                <div class="pretax-label">
                    Pre-Tax → Clover
                    <span class="pretax-sub">Type this amount into the Clover terminal</span>
                </div>
                <div class="pretax-val">$<span id="pre_tax_amount">0</span></div>
            </div>

            {{-- Tax row --}}
            <div class="row @if($pos_settings['disable_order_tax'] != 0) hide @endif">
                <span class="label">
                    Tax
                    <button type="button" class="edit" title="@lang('sale.edit_order_tax')" data-toggle="modal" data-target="#posEditOrderTaxModal" id="pos-edit-tax"><i class="fa fa-pencil-alt"></i> Edit</button>
                    <span class="rate" id="tax_rate_display"></span>
                    <span class="rate hide" id="order_tax_rate_label"></span>
                </span>
                <span class="amt">+ $<span id="order_tax_display">0</span><span id="order_tax" style="display:none;">@if(empty($edit)) 0 @else {{$transaction->tax_amount}} @endif</span></span>
            </div>

            <div class="divider"></div>

            {{-- Grand total --}}
            <div class="row grand">
                <span class="label">
                    Total w/ Tax
                    <span class="muted" style="font-weight:400;">Subtotal + Tax</span>
                </span>
                <span class="amt">$<span id="total_with_tax">0</span></span>
            </div>
        </div>
        {{-- Payment row — Cash / Card / More. Same classes & IDs as the
             old pos_form_actions markup so pos.js handlers keep firing
             without any JS changes. --}}
        <div class="pos-pay-row">
            <button type="button"
                class="pos-pay-btn pay-cash pos-express-finalize @if($pos_settings['disable_express_checkout'] != 0 || !array_key_exists('cash', $payment_types)) hide @endif"
                data-pay_method="cash"
                title="@lang('tooltip.express_checkout')">
                <i class="fas fa-money-bill-alt"></i> Cash
            </button>
            <button type="button"
                class="pos-pay-btn pay-card pos-express-finalize @if(!array_key_exists('card', $payment_types)) hide @endif"
                data-pay_method="card"
                title="@lang('lang_v1.tooltip_express_checkout_card')">
                <i class="fas fa-credit-card"></i> Card
            </button>
            <div class="dropup pos-pay-more">
                <button type="button" class="pos-pay-btn pay-more dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <i class="fa fa-ellipsis-h"></i> More <span class="caret"></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-right">
                    <li style="list-style:none;">
                        <button type="button" class="btn btn-link" id="pos-finalize" title="@lang('lang_v1.tooltip_checkout_multi_pay')">
                            <i class="fas fa-money-check-alt text-primary"></i> @lang('lang_v1.checkout_multi_pay')
                        </button>
                    </li>
                    @if(empty($pos_settings['disable_credit_sale_button']))
                    <li style="list-style:none;">
                        <button type="button" class="btn btn-link pos-express-finalize" data-pay_method="credit_sale" title="@lang('lang_v1.tooltip_credit_sale')">
                            <i class="fas fa-check text-purple"></i> @lang('lang_v1.credit_sale')
                        </button>
                    </li>
                    @endif
                </ul>
            </div>
        </div>

        @if(empty($edit))
        <div class="pos-cancel-wrap">
            <button type="button" class="pos-cancel-link" id="pos-cancel"><i class="fas fa-times"></i> Cancel Sale</button>
        </div>
        @endif

        <script>
        /* Discount dropdown + tax-rate display + bag stepper — runs inline
           but waits for jQuery since jQuery loads at the bottom of the layout. */
        (function runWhenReady() {
            if (typeof jQuery === 'undefined') { setTimeout(runWhenReady, 50); return; }
            jQuery(function ($) {
                // Discount dropdown (Manual / Preset)
                $(document).on('click', '#adj-discount-toggle', function (e) {
                    e.stopPropagation();
                    $('#adj-discount-wrap').toggleClass('open');
                    $(this).attr('aria-expanded', $('#adj-discount-wrap').hasClass('open') ? 'true' : 'false');
                });
                $(document).on('click', function (e) {
                    if (!$(e.target).closest('#adj-discount-wrap').length) {
                        $('#adj-discount-wrap').removeClass('open');
                        $('#adj-discount-toggle').attr('aria-expanded', 'false');
                    }
                });
                $(document).on('click', '#pos-manual-discount, #pos-preset-discount, #pos-edit-discount', function () {
                    $('#adj-discount-wrap').removeClass('open');
                });

                // Tax rate display (e.g. "(9.75%)"). Sources: #tax_calculation_amount
                // input + the selected <option> text on the tax modal.
                function updateTaxRateLabel() {
                    var rate = null;
                    var calc = parseFloat($('#tax_calculation_amount').val());
                    if (!isNaN(calc) && calc > 0) rate = calc;
                    var $opt = $('#order_tax_modal option:selected');
                    if ($opt.length) {
                        var m = ($opt.text() || '').match(/([\d.]+)\s*%/);
                        if (m && m[1]) rate = parseFloat(m[1]);
                    }
                    var label = (rate !== null && !isNaN(rate) && rate > 0) ? '(' + rate + '%)' : '';
                    $('#tax_rate_display, #order_tax_rate_label').text(label);
                }
                updateTaxRateLabel();
                $(document).on('change', '#order_tax_modal, #tax_rate_id, #tax_calculation_amount', updateTaxRateLabel);
                $(document).on('hidden.bs.modal', '#posEditOrderTaxModal', updateTaxRateLabel);

                // Bag-fee visible toggle + stepper. Syncs state to the hidden
                // #add_plastic_bag checkbox and updates the plastic-bag row in
                // the cart table (which is what pos.js reads for totals).
                var $bagToggle = $('#bag-toggle-checkbox');
                var $addBag = $('#add_plastic_bag');
                var $bagCount = $('#bag-count');
                var $bagTotalVis = $('#bag-total-visible');
                var $bagFeeAmount = $('#bag-fee-amount');
                var $bagFeeMutedLabel = $('#bag-fee-muted-label');
                var bagPrice = parseFloat($('#plastic_bag_price').val()) || 0.10;

                function getBagQty() {
                    var $qty = $('#pos_table tbody tr[data-plastic-bag="true"] input.input_quantity');
                    if (!$qty.length) return parseInt($bagCount.text(), 10) || 1;
                    return parseInt($qty.val(), 10) || 1;
                }
                function setBagQty(n) {
                    if (n < 1) n = 1;
                    if (n > 20) n = 20;
                    $bagCount.text(n);
                    var total = (n * bagPrice).toFixed(2);
                    $bagTotalVis.text(total);
                    $bagFeeAmount.text(total);
                    $bagFeeMutedLabel.text('(' + n + ' bag' + (n === 1 ? '' : 's') + ')');
                    var $qty = $('#pos_table tbody tr[data-plastic-bag="true"] input.input_quantity');
                    if ($qty.length) $qty.val(n).trigger('change').trigger('input');
                }
                function refreshBagRow() {
                    var on = $bagToggle.is(':checked');
                    $('#pos-bag-fee-row').toggle(on);
                    $addBag.prop('checked', on).trigger('change');
                    if (on) setBagQty(getBagQty());
                }

                $bagToggle.on('change', refreshBagRow);
                $('#bag-step-minus').on('click', function (e) { e.preventDefault(); e.stopPropagation(); setBagQty(getBagQty() - 1); });
                $('#bag-step-plus').on('click', function (e) { e.preventDefault(); e.stopPropagation(); setBagQty(getBagQty() + 1); });
                $(document).on('change input', '#pos_table tbody tr[data-plastic-bag="true"] input.input_quantity', function () {
                    setBagQty(getBagQty());
                });
                setTimeout(refreshBagRow, 300);

                // Whatnot chip — light up when checked.
                $(document).on('change', '#is_whatnot', function () {
                    $('#whatnot_chip').toggleClass('active-whatnot', this.checked);
                });
            });
        })();
        </script>
    </div>
</div>
