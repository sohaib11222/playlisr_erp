{{-- Customer rewards panel — mirrors the #customer_account_info block in the left column
	 so the Nivessa Bucks balance is visible at a glance on the right side of the POS.
	 Stays hidden until a customer is selected. A small script below syncs the values. --}}
<div id="sidebar_customer_panel" style="display:none; background:linear-gradient(135deg,#fff7ed,#fde68a); border:2px solid #f59e0b; border-radius:12px; padding:14px 16px; margin-bottom:14px; box-shadow:0 2px 6px rgba(245,158,11,0.15);">
	<div style="font-size:11px; text-transform:uppercase; letter-spacing:1px; color:#92400e; font-weight:700;">
		<i class="fa fa-star"></i> Nivessa Bucks — Customer
	</div>
	<div class="v-customer-name" style="font-size:17px; font-weight:800; color:#78350f; margin:4px 0 8px 0;"></div>
	<div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
		<div>
			<div style="font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:#92400e; font-weight:700;">Credit</div>
			<div class="v-balance" style="font-size:17px; font-weight:800; color:#78350f;">$0.00</div>
		</div>
		<div>
			<div style="font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:#92400e; font-weight:700;">Gift cards</div>
			<div class="v-gift" style="font-size:17px; font-weight:800; color:#78350f;">$0.00</div>
		</div>
		<div>
			<div style="font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:#92400e; font-weight:700;">Points</div>
			<div class="v-points" style="font-size:17px; font-weight:800; color:#78350f;">0</div>
		</div>
		<div>
			<div style="font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:#92400e; font-weight:700;">Lifetime</div>
			<div class="v-lifetime" style="font-size:14px; font-weight:700; color:#78350f;">$0.00</div>
		</div>
	</div>
</div>
<script>
(function () {
	// Sync the left-column #customer_account_info content into the sidebar panel.
	var $src = $('#customer_account_info');
	var $dst = $('#sidebar_customer_panel');
	if (!$src.length || !$dst.length) return;
	function sync() {
		var visible = $src.is(':visible');
		$dst.toggle(visible);
		if (!visible) return;
		$dst.find('.v-customer-name').text($('#customer_account_name').text().trim());
		$dst.find('.v-balance').text($('#customer_account_balance').text().trim());
		$dst.find('.v-gift').text($('#customer_gift_card_balance').text().trim());
		$dst.find('.v-lifetime').text($('#customer_lifetime_purchases').text().trim());
		$dst.find('.v-points').text($('#customer_loyalty_points').text().trim());
	}
	$(function () { sync(); });
	if (typeof MutationObserver !== 'undefined') {
		new MutationObserver(sync).observe($src[0], { childList: true, subtree: true, attributes: true, attributeFilter: ['style', 'class'] });
	}
})();
</script>

{{-- Quick-add preset tiles for items always entered manually (drinks, candy, pins, stickers).
     Tapping a tile adds the preset straight to the cart — it DOES NOT open a modal. --}}
<style>
    .pos-quick-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 14px; }
    .pos-quick-grid-title { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; font-weight: 700; margin: 2px 0 8px 2px; }
    .pos-quick-tile {
        background: linear-gradient(135deg, #fef3c7, #fde68a);
        border: 2px solid #f59e0b;
        color: #78350f;
        border-radius: 12px;
        padding: 18px 14px;
        font-size: 17px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        cursor: pointer;
        text-align: center;
        transition: transform 0.08s ease, box-shadow 0.08s ease;
        box-shadow: 0 2px 4px rgba(245, 158, 11, 0.15);
    }
    .pos-quick-tile:hover { background: linear-gradient(135deg, #fde68a, #fcd34d); transform: translateY(-1px); box-shadow: 0 4px 8px rgba(245, 158, 11, 0.25); }
    .pos-quick-tile:active { transform: translateY(0); }
    .pos-quick-tile:disabled { opacity: 0.6; cursor: progress; }
    .pos-quick-tile .pos-quick-price { display: block; font-size: 14px; opacity: 0.85; margin-top: 4px; }
    .pos-quick-tile i { display: block; font-size: 22px; margin-bottom: 4px; }
</style>
<div class="pos-quick-grid-title">Quick Add — Snacks & Swag</div>
<div class="pos-quick-grid">
    <button type="button" class="pos-quick-tile pos-quick-preset" data-preset-name="Soda (can)" data-preset-price="2.00">
        <span style="font-size:26px; line-height:1; display:block; margin-bottom:4px;">🥤</span> Soda <span class="pos-quick-price">$2.00</span>
    </button>
    <button type="button" class="pos-quick-tile pos-quick-preset" data-preset-name="Energy Drink" data-preset-price="4.00">
        <i class="fa fa-bolt"></i> Energy Drink <span class="pos-quick-price">$4.00</span>
    </button>
    <button type="button" class="pos-quick-tile pos-quick-preset" data-preset-name="Candy" data-preset-price="2.00">
        <i class="fa fa-candy-cane"></i> Candy <span class="pos-quick-price">$2.00</span>
    </button>
    <button type="button" class="pos-quick-tile pos-quick-preset" data-preset-name="Pin" data-preset-price="3.00">
        <i class="fa fa-thumbtack"></i> Pin <span class="pos-quick-price">$3.00</span>
    </button>
    <button type="button" class="pos-quick-tile pos-quick-preset" data-preset-name="Sticker" data-preset-price="3.00">
        <i class="fa fa-sticky-note"></i> Sticker <span class="pos-quick-price">$3.00</span>
    </button>
    <button type="button" class="pos-quick-tile pos-quick-preset" data-preset-name="Patch" data-preset-price="3.00">
        <i class="fa fa-tshirt"></i> Patch <span class="pos-quick-price">$3.00</span>
    </button>
    <button type="button" class="pos-quick-tile pos-quick-preset" data-preset-name="Water" data-preset-price="1.50">
        <span style="font-size:26px; line-height:1; display:block; margin-bottom:4px;">💧</span> Water <span class="pos-quick-price">$1.50</span>
    </button>
    <button type="button" class="pos-quick-tile pos-quick-preset" data-preset-name="Chips" data-preset-price="1.75">
        <span style="font-size:26px; line-height:1; display:block; margin-bottom:4px;">🥔</span> Chips <span class="pos-quick-price">$1.75</span>
    </button>
</div>

{{-- Quick-add handler — skips the modal entirely and hits the same backend
     endpoint the manual-item modal uses, so the item lands directly in the
     cart. After the row renders, MutationObserver below moves the bag-fee
     row (if present) to the end so new items always appear above it.

     NOTE: jQuery loads at the bottom of the layout (after @yield('content')),
     so an inline IIFE that calls $(...) throws "$ is not defined" and silently
     detaches every handler below. Poll-wait-for-jQuery wrapper fixes it. --}}
<script>
(function runWhenReady() {
    if (typeof jQuery === 'undefined') { setTimeout(runWhenReady, 50); return; }
    jQuery(function ($) {
    $(document).on('click', '.pos-quick-preset', function () {
        var $btn = $(this);
        if ($btn.prop('disabled')) return;
        var name = $btn.data('preset-name');
        var price = parseFloat($btn.data('preset-price') || 0);
        if (!name || !price) return;
        $btn.prop('disabled', true);

        var rowIndex = ($('#pos_table tbody tr.product_row').length || 0);
        $.ajax({
            method: 'POST',
            url: '/sells/pos/get_manual_product_rows',
            data: {
                _token: $('meta[name="csrf-token"]').attr('content'),
                products: [{
                    product_id: 'manual',
                    product_type: 'single',
                    name: name,
                    artist: '',
                    price: price.toFixed(2),
                    quantity: 1
                }]
            },
            dataType: 'json'
        }).done(function (result) {
            if (result && result.success && result.html_content) {
                var html = result.html_content;
                var $bagRow = $('#pos_table tbody tr[data-plastic-bag="true"]').first();
                if (Array.isArray(html)) {
                    html.forEach(function (h) { $bagRow.length ? $bagRow.before(h) : $('#pos_table tbody').append(h); });
                } else {
                    $bagRow.length ? $bagRow.before(html) : $('#pos_table tbody').append(html);
                }
                $('#pos_table tbody tr').each(function () { if (typeof pos_each_row === 'function') pos_each_row($(this)); });
                if (typeof pos_total_row === 'function') pos_total_row();
                if (typeof toastr !== 'undefined' && toastr.success) toastr.success(name + ' added');
            } else {
                if (typeof toastr !== 'undefined') toastr.error((result && result.msg) || 'Could not add ' + name);
            }
        }).fail(function () {
            if (typeof toastr !== 'undefined') toastr.error('Could not add ' + name);
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    // Whenever rows change, keep the bag-fee row at the bottom so manually-added
    // products always sit above it.
    var tbody = document.querySelector('#pos_table tbody');
    if (tbody) {
        var reorder = function () {
            var $bag = $(tbody).find('tr[data-plastic-bag="true"]').first();
            if ($bag.length && $bag.next().length) {
                $(tbody).append($bag);
            }
        };
        new MutationObserver(reorder).observe(tbody, { childList: true });
    }
    });
})();
</script>

{{-- The admin "featured products" grid + category/brand filters used to live
     here but were removed per Sarah's request — cashiers don't use them and
     they crowd out the cart. Kept the hidden boxes that JS elsewhere still
     references so nothing crashes. --}}
<input type="hidden" id="product_category" value="all">
<input type="hidden" id="product_brand" value="">
<input type="hidden" id="is_enabled_stock" value="">
<input type="hidden" id="suggestion_page" value="1">
<div id="featured_products_box" style="display:none;"></div>
<div id="product_list_body" style="display:none;"></div>
<div id="suggestion_page_loader" style="display:none;"></div>