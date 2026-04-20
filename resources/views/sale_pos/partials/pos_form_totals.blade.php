<style>
	/* One font, two text colors (#111827 primary, #6b7280 muted), three sizes
	   (11/13/26). All adjustments share a single row template so the eye doesn't
	   bounce between fonts/weights/alignments. Jonathan kept flagging the totals
	   block as "many fonts, sizes, colors, spacings" — this is the cleanup pass. */
	.pos-tot-block {
		background:#fff; border:1px solid #e5e7eb; border-radius:10px;
		padding:14px 16px; margin-top:10px;
		font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
		color:#111827;
	}
	.pos-tot-flags { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:10px; }
	.pos-tot-chip {
		display:inline-flex; align-items:center; gap:8px;
		padding:6px 12px; border-radius:999px;
		background:#f3f4f6; border:1px solid #e5e7eb;
		font-size:13px; font-weight:600; color:#374151;
		cursor:pointer;
	}
	.pos-tot-chip input[type="checkbox"] { margin:0; }
	#whatnot_chip { background:#f5ce3e; border-color:#d4a92a; color:#2b1e16; font-weight:700; }
	#whatnot_chip:hover { background:#eac232; }
	.pos-tot-chip.active-whatnot { background:#e5b92e; border-color:#b5901f; color:#2b1e16; box-shadow:inset 0 0 0 1px rgba(0,0,0,.08); }
	.pos-tot-chip.active-bag { background:#faf0df; border-color:#b98b5c; color:#5c3c10; }
	.pos-tot-chip.store-credit { background:#dcfce7; border-color:#22c55e; color:#14532d; }

	/* Summary row: every label/value uses the same type ramp except the grand
	   total which dominates (26px green). */
	.pos-tot-summary { display:flex; flex-wrap:wrap; align-items:baseline; gap:14px 24px; padding:4px 0 8px; border-bottom:1px solid #f1f2f4; }
	.pos-tot-summary .stat { display:flex; flex-direction:column; line-height:1.15; }
	.pos-tot-summary .stat .lbl { font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:#6b7280; font-weight:600; }
	.pos-tot-summary .stat .val { font-size:14px; font-weight:700; color:#111827; margin-top:3px; }

	/* Pre-tax is the number cashiers type into the Clover device. It has to be
	   the single most obvious number on the screen, not "Subtotal" in 14px. Mustard
	   background + 26px value puts it at parity with the grand Total. */
	.pos-tot-summary .stat.pretax {
		background:#fdf3cf; border:1.5px solid #e5b92e; border-radius:8px;
		padding:6px 12px; margin-left:auto;
	}
	.pos-tot-summary .stat.pretax .lbl { color:#5c3c10; font-size:11px; }
	.pos-tot-summary .stat.pretax .val { font-size:26px; font-weight:800; color:#2b1e16; margin-top:1px; }

	.pos-tot-summary .stat.grand { text-align:right; }
	.pos-tot-summary .stat.grand .lbl { color:#065f46; }
	.pos-tot-summary .stat.grand .val { font-size:22px; font-weight:800; color:#065f46; margin-top:1px; }

	/* Adjustments — flex rows. Each row: [label fixed-width][edit button][value pushed right].
	   Labels share a fixed width so the edit buttons align vertically regardless of
	   label length. Value sits on the far right via margin-left:auto. */
	.pos-adjust-list { margin-top:10px; display:flex; flex-direction:column; gap:6px; }
	.pos-adjust-row { display:flex; align-items:center; gap:10px; min-height:28px; }
	.pos-adjust-row .adj-label {
		flex:0 0 96px;
		font-size:12px; text-transform:uppercase; letter-spacing:.5px;
		font-weight:700; color:#374151;
	}
	.pos-adjust-row .adj-btn {
		display:inline-flex; align-items:center; gap:6px;
		padding:4px 10px; border-radius:6px;
		border:1px solid #e5e7eb; background:#fff;
		font-size:12px; font-weight:600; color:#374151;
		cursor:pointer; line-height:1.2;
	}
	.pos-adjust-row .adj-btn:hover { background:#f9fafb; border-color:#cbd5e1; }
	.pos-adjust-row .adj-btn i { font-size:11px; }
	.pos-adjust-row .adj-value {
		margin-left:auto;
		font-size:14px; font-weight:700; color:#111827;
		white-space:nowrap;
	}
	.pos-adjust-row .adj-rate {
		font-size:11px; color:#6b7280; font-weight:600;
	}

	/* Discount edit opens a small dropdown so Manual / Preset live under one
	   menu — adds a deliberate beat before a price break goes on. */
	.pos-adjust-row .adj-discount-wrap { position:relative; }
	.pos-adjust-row .adj-discount-menu { display:none; position:absolute; top:calc(100% + 4px); left:0; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,.08); padding:4px; z-index:10; min-width:180px; }
	.pos-adjust-row .adj-discount-wrap.open .adj-discount-menu { display:block; }
	.pos-adjust-row .adj-discount-menu button { display:flex; align-items:center; gap:8px; width:100%; border:0; background:transparent; text-align:left; padding:7px 10px; border-radius:6px; font-size:13px; font-weight:500; color:#374151; cursor:pointer; }
	.pos-adjust-row .adj-discount-menu button:hover { background:#f3f4f6; }
	.pos-adjust-row .adj-discount-menu button i { width:14px; color:#6b7280; }
</style>

<div class="pos_form_totals">
	<input type="hidden" name="store_credit_used_amount" id="store_credit_used_amount" value="0">

	<div class="pos-tot-block">
		{{-- Sale-flag chips: Whatnot, Bag Fee, Store Credit (only shown when applicable) --}}
		<div class="pos-tot-flags">
			<label class="pos-tot-chip" id="whatnot_chip">
				<input type="checkbox" name="is_whatnot" id="is_whatnot" value="1">
				<span>Mark as Whatnot</span>
			</label>
			@if(!empty($pos_settings['enable_plastic_bag_charge']))
			<label class="pos-tot-chip active-bag" id="bag_chip">
				<input type="checkbox" id="add_plastic_bag" name="add_plastic_bag" value="1" checked>
				<span>Bag Fee <span id="plastic_bag_price_display" style="font-weight:500; opacity:.8;">(${{ number_format($pos_settings['plastic_bag_price'] ?? 0.10, 2) }})</span></span>
				<input type="hidden" id="plastic_bag_price" value="{{ $pos_settings['plastic_bag_price'] ?? 0.10 }}">
			</label>
			@endif
			<div id="pos_store_credit_row" class="pos-tot-chip store-credit" style="display:none; cursor:default;">
				<span>Store credit:</span>
				<span id="pos_store_credit_amount" style="font-weight:700;">$0.00</span>
				<button type="button" class="adj-btn" id="btn_use_store_credit" style="padding:2px 8px;">Use it</button>
			</div>
		</div>

		{{-- Totals summary — Items / Tax / hero PRE-TAX (what cashier types into
		     Clover) / grand Total. Pre-tax sits in a mustard-yellow hero box at
		     parity with the grand total because it's the number that has to be
		     read and re-typed on every sale. #pre_tax_amount is populated by
		     pos.js line 3130 (pre-tax after discount/shipping/packing, the exact
		     value Clover expects). --}}
		<div class="pos-tot-summary">
			<div class="stat"><span class="lbl">Items</span><span class="val total_quantity">0</span></div>
			<div class="stat @if($pos_settings['disable_order_tax'] != 0) hide @endif">
				<span class="lbl">Tax <span id="tax_rate_display" style="opacity:.8; font-weight:700;"></span></span>
				<span class="val" id="order_tax_display">0</span>
			</div>
			<div class="stat pretax" title="Type this number into the Clover device">
				<span class="lbl">Pre-tax → Clover</span>
				<span class="val" id="pre_tax_amount">0</span>
			</div>
			<div class="stat grand"><span class="lbl">Total w/ tax</span><span class="val" id="total_with_tax">0</span></div>
		</div>

		{{-- Adjustments — each row: [fixed-width label][edit button][value pushed right]. --}}
		<div class="pos-adjust-list">
			@if($is_discount_enabled)
			<div class="pos-adjust-row">
				<span class="adj-label">Discount</span>
				<span class="adj-discount-wrap" id="adj-discount-wrap">
					<button type="button" class="adj-btn" id="adj-discount-toggle" aria-haspopup="true" aria-expanded="false"><i class="fa fa-pencil-alt"></i> Edit <i class="fa fa-caret-down" style="margin-left:2px;"></i></button>
					<div class="adj-discount-menu" role="menu">
						<button type="button" id="pos-manual-discount"><i class="fa fa-percent"></i> Manual discount</button>
						<button type="button" id="pos-preset-discount"><i class="fa fa-tags"></i> Preset discount</button>
						@if($edit_discount)
							<button type="button" id="pos-edit-discount" data-toggle="modal" data-target="#posEditDiscountModal"><i class="fa fa-edit"></i> @lang('sale.edit_discount')</button>
						@endif
					</div>
				</span>
				<span class="adj-value">− <span id="total_discount">0</span></span>
			</div>
			@endif
			<div class="pos-adjust-row @if($pos_settings['disable_order_tax'] != 0) hide @endif">
				<span class="adj-label">Order Tax</span>
				<button type="button" class="adj-btn" title="@lang('sale.edit_order_tax')" data-toggle="modal" data-target="#posEditOrderTaxModal" id="pos-edit-tax"><i class="fa fa-pencil-alt"></i> Edit</button>
				<span class="adj-rate" id="order_tax_rate_label"></span>
				<span class="adj-value">+ <span id="order_tax">@if(empty($edit)) 0 @else {{$transaction->tax_amount}} @endif</span></span>
			</div>
			<div class="pos-adjust-row">
				<span class="adj-label">Shipping</span>
				<button type="button" class="adj-btn" title="@lang('sale.shipping')" data-toggle="modal" data-target="#posShippingModal"><i class="fa fa-pencil-alt"></i> Edit</button>
				<span class="adj-value">+ <span id="shipping_charges_amount">0</span></span>
			</div>
			@if(in_array('types_of_service', $enabled_modules))
			<div class="pos-adjust-row">
				<span class="adj-label">Packing</span>
				<button type="button" class="adj-btn service_modal_btn"><i class="fa fa-pencil-alt"></i> Edit</button>
				<span class="adj-value">+ <span id="packing_charge_text">0</span></span>
			</div>
			@endif
			@if(!empty($pos_settings['enable_plastic_bag_charge']))
			<div class="pos-adjust-row" id="pos-bag-fee-row" style="opacity:.7;">
				<span class="adj-label" style="color:#6b7280; font-weight:600;">Bag Fee</span>
				<span class="adj-rate">(added when checkbox is checked)</span>
				<span class="adj-value" style="color:#6b7280;">+ ${{ number_format($pos_settings['plastic_bag_price'] ?? 0.10, 2) }}</span>
			</div>
			@endif
			@if(!empty($pos_settings['amount_rounding_method']) && $pos_settings['amount_rounding_method'] > 0)
			<div class="pos-adjust-row">
				<span class="adj-label" id="round_off">Round off</span>
				<span class="adj-value"><span id="round_off_text">0</span><input type="hidden" name="round_off_amount" id="round_off_amount" value=0></span>
			</div>
			@endif
		</div>
		<script>
		/* jQuery loads after @yield('content') — wait for it, otherwise the
		   Edit▾ dropdown click handler never binds and the discount menu
		   looks broken. */
		(function runWhenReady() {
			if (typeof jQuery === 'undefined') { setTimeout(runWhenReady, 50); return; }
			jQuery(function ($) {
				$(document).on('click', '#adj-discount-toggle', function (e) {
					e.stopPropagation();
					$('#adj-discount-wrap').toggleClass('open');
					var expanded = $('#adj-discount-wrap').hasClass('open');
					$(this).attr('aria-expanded', expanded ? 'true' : 'false');
				});
				$(document).on('click', function (e) {
					if (!$(e.target).closest('#adj-discount-wrap').length) {
						$('#adj-discount-wrap').removeClass('open');
						$('#adj-discount-toggle').attr('aria-expanded', 'false');
					}
				});
				$(document).on('click', '#pos-manual-discount, #pos-preset-discount, #pos-edit-discount', function () {
					$('#adj-discount-wrap').removeClass('open');
					$('#adj-discount-toggle').attr('aria-expanded', 'false');
				});

				// Show the sales tax rate (e.g. "(9.5%)") next to both the summary Tax
				// label and the Order Tax adjustment row. Source = the selected option
				// text in the order-tax modal (which pos.js keeps in sync with tax_rate_id).
				function updateTaxRateLabel() {
					var rate = null;
					var calc = parseFloat($('#tax_calculation_amount').val());
					if (!isNaN(calc) && calc > 0) rate = calc;
					// Try to pull the % from the selected <option> text if rate isn't a clean number
					var $opt = $('#order_tax_modal option:selected');
					if ($opt.length) {
						var m = ($opt.text() || '').match(/([\d.]+)\s*%/);
						if (m && m[1]) rate = parseFloat(m[1]);
					}
					var label = (rate !== null && !isNaN(rate) && rate > 0) ? '(' + rate + '%)' : '';
					$('#tax_rate_display').text(label);
					$('#order_tax_rate_label').text(label);
				}
				updateTaxRateLabel();
				$(document).on('change', '#order_tax_modal, #tax_rate_id, #tax_calculation_amount', updateTaxRateLabel);
				// Modal fires its own change event when the cashier picks a new rate
				$(document).on('hidden.bs.modal', '#posEditOrderTaxModal', updateTaxRateLabel);
			});
		})();
		</script>

		{{-- Hidden form inputs preserved exactly — pos.js reads/writes these --}}
		<input type="hidden" name="discount_type" id="discount_type" value="@if(empty($edit)){{'percentage'}}@else{{$transaction->discount_type}}@endif" data-default="percentage">
		<input type="hidden" name="discount_amount" id="discount_amount" value="@if(empty($edit)) {{0}} @else {{@num_format($transaction->discount_amount)}} @endif" data-default="0">
		<input type="hidden" name="discount_reason" id="discount_reason" value="@if(empty($edit)){{''}}@else{{$transaction->discount_reason ?? ''}}@endif">
		<input type="hidden" name="rp_redeemed" id="rp_redeemed" value="@if(empty($edit)){{'0'}}@else{{$transaction->rp_redeemed}}@endif">
		<input type="hidden" name="rp_redeemed_amount" id="rp_redeemed_amount" value="@if(empty($edit)){{'0'}}@else {{$transaction->rp_redeemed_amount}} @endif">
		<input type="hidden" name="tax_rate_id" id="tax_rate_id" value="@if(empty($edit)) @if(!empty($business_details->default_sales_tax)){{$business_details->default_sales_tax}}@endif @else {{$transaction->tax_id}} @endif" data-default="@if(!empty($business_details->default_sales_tax)){{$business_details->default_sales_tax}}@endif">
		<input type="hidden" name="tax_calculation_amount" id="tax_calculation_amount" value="@if(empty($edit)) {{@num_format($business_details->tax_calculation_amount)}} @else {{@num_format(optional($transaction->tax)->amount)}} @endif" data-default="{{$business_details->tax_calculation_amount}}">
		<input type="hidden" name="shipping_details" id="shipping_details" value="@if(empty($edit)){{''}}@else{{$transaction->shipping_details}}@endif" data-default="">
		<input type="hidden" name="shipping_address" id="shipping_address" value="@if(empty($edit)){{''}}@else{{$transaction->shipping_address}}@endif">
		<input type="hidden" name="shipping_status" id="shipping_status" value="@if(empty($edit)){{''}}@else{{$transaction->shipping_status}}@endif">
		<input type="hidden" name="delivered_to" id="delivered_to" value="@if(empty($edit)){{''}}@else{{$transaction->delivered_to}}@endif">
		<input type="hidden" name="shipping_charges" id="shipping_charges" value="@if(empty($edit)){{@num_format(0.00)}} @else{{@num_format($transaction->shipping_charges)}} @endif" data-default="0.00">
	</div>

	{{-- Visual state of the Whatnot chip tracks the checkbox --}}
	<script>
	(function(){
		function syncWhatnotChip(){ $('#whatnot_chip').toggleClass('active-whatnot', $('#is_whatnot').is(':checked')); }
		$(document).on('change', '#is_whatnot', syncWhatnotChip);
		syncWhatnotChip();
	})();
	</script>
</div>