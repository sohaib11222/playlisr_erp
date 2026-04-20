<style>
	/* Unified look for the totals/adjustments block — one font, one color system */
	.pos-tot-block { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; margin-top:10px; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif; }
	.pos-tot-flags { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:12px; }
	.pos-tot-chip { display:inline-flex; align-items:center; gap:8px; padding:7px 12px; border-radius:999px; background:#f3f4f6; border:1px solid #e5e7eb; font-size:13px; font-weight:600; color:#374151; cursor:pointer; }
	.pos-tot-chip input[type="checkbox"] { margin:0; }
	.pos-tot-chip.active-whatnot { background:#fef3c7; border-color:#f59e0b; color:#78350f; }
	.pos-tot-chip.active-bag { background:#fce7f3; border-color:#ec4899; color:#831843; }
	.pos-tot-chip.store-credit { background:#dcfce7; border-color:#22c55e; color:#14532d; }
	.pos-tot-summary { display:flex; flex-wrap:wrap; align-items:baseline; gap:18px 24px; padding:6px 0; }
	.pos-tot-summary > .stat { display:flex; flex-direction:column; line-height:1.2; }
	.pos-tot-summary .stat .lbl { font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:#6b7280; font-weight:600; }
	.pos-tot-summary .stat .val { font-size:15px; font-weight:600; color:#111827; margin-top:2px; }
	.pos-tot-summary .stat.grand .val { font-size:28px; font-weight:800; color:#065f46; }
	.pos-tot-summary .stat.grand .lbl { color:#065f46; }
	.pos-tot-summary .stat.grand { margin-left:auto; text-align:right; }
	/* Adjustments — one tidy grid, every row reads the same: [label] [edit] [value].
	   Previously Discount had three buttons (Edit + Manual + Preset) while Tax and
	   Shipping had just one each — it looked scattered. Now: single edit button per
	   row, consistent 14px label, consistent amount column on the right. */
	.pos-adjust-grid { margin-top:12px; padding-top:10px; border-top:1px solid #f1f2f4; display:grid; grid-template-columns: auto 1fr auto; column-gap:14px; row-gap:6px; align-items:center; }
	.pos-adjust-grid .adj-label { text-transform:uppercase; font-size:12px; letter-spacing:.5px; font-weight:700; color:#374151; }
	.pos-adjust-grid .adj-value { text-align:right; font-weight:700; color:#111827; font-size:14px; white-space:nowrap; }
	.pos-adjust-grid .adj-btn { display:inline-flex; align-items:center; gap:6px; padding:5px 12px; border-radius:6px; border:1px solid #e5e7eb; background:#fff; font-size:13px; font-weight:600; color:#374151; cursor:pointer; justify-self:start; }
	.pos-adjust-grid .adj-btn:hover { background:#f9fafb; border-color:#cbd5e1; }
	.pos-adjust-grid .adj-btn i { font-size:12px; }
	/* Discount edit button sits behind a dropdown so Manual / Preset live under one
	   menu instead of side-by-side "choose your discount" buttons — adds a deliberate
	   beat before a price break goes on, and dedupes the "scattered" feel. */
	.pos-adjust-grid .adj-discount-wrap { position:relative; }
	.pos-adjust-grid .adj-discount-menu { display:none; position:absolute; top:calc(100% + 4px); left:0; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 4px 12px rgba(0,0,0,.08); padding:4px; z-index:10; min-width:180px; }
	.pos-adjust-grid .adj-discount-wrap.open .adj-discount-menu { display:block; }
	.pos-adjust-grid .adj-discount-menu button { display:flex; align-items:center; gap:8px; width:100%; border:0; background:transparent; text-align:left; padding:8px 10px; border-radius:6px; font-size:13px; font-weight:500; color:#374151; cursor:pointer; }
	.pos-adjust-grid .adj-discount-menu button:hover { background:#f3f4f6; }
	.pos-adjust-grid .adj-discount-menu button i { width:14px; color:#6b7280; }
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

		{{-- Totals summary — Items / Subtotal / Tax / big Total --}}
		<div class="pos-tot-summary">
			<div class="stat"><span class="lbl">Items</span><span class="val total_quantity">0</span></div>
			<div class="stat"><span class="lbl">Subtotal</span><span class="val" id="pre_tax_amount">0</span></div>
			<div class="stat @if($pos_settings['disable_order_tax'] != 0) hide @endif"><span class="lbl">Tax</span><span class="val" id="order_tax_display">0</span></div>
			<div class="stat grand"><span class="lbl">Total</span><span class="val" id="total_with_tax">0</span></div>
		</div>

		{{-- Adjustments — one consistent grid: [label] [edit button] [running value]. --}}
		<div class="pos-adjust-grid">
			@if($is_discount_enabled)
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
			@endif
			@if($is_rp_enabled)
				<span class="adj-label">{{ session('business.rp_name') }}</span>
				<span></span>
				<span class="adj-value"></span>
			@endif
			<span class="adj-label @if($pos_settings['disable_order_tax'] != 0) hide @endif">Order Tax</span>
			<button type="button" class="adj-btn @if($pos_settings['disable_order_tax'] != 0) hide @endif" title="@lang('sale.edit_order_tax')" data-toggle="modal" data-target="#posEditOrderTaxModal" id="pos-edit-tax"><i class="fa fa-pencil-alt"></i> Edit</button>
			<span class="adj-value @if($pos_settings['disable_order_tax'] != 0) hide @endif">+ <span id="order_tax">@if(empty($edit)) 0 @else {{$transaction->tax_amount}} @endif</span></span>

			<span class="adj-label">Shipping</span>
			<button type="button" class="adj-btn" title="@lang('sale.shipping')" data-toggle="modal" data-target="#posShippingModal"><i class="fa fa-pencil-alt"></i> Edit</button>
			<span class="adj-value">+ <span id="shipping_charges_amount">0</span></span>

			@if(in_array('types_of_service', $enabled_modules))
				<span class="adj-label">Packing</span>
				<button type="button" class="adj-btn service_modal_btn"><i class="fa fa-pencil-alt"></i> Edit</button>
				<span class="adj-value">+ <span id="packing_charge_text">0</span></span>
			@endif
			@if(!empty($pos_settings['amount_rounding_method']) && $pos_settings['amount_rounding_method'] > 0)
				<span class="adj-label" id="round_off">Round off</span>
				<span></span>
				<span class="adj-value"><span id="round_off_text">0</span><input type="hidden" name="round_off_amount" id="round_off_amount" value=0></span>
			@endif
		</div>
		<script>
		(function () {
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
			// Close the menu after a choice is made; the existing handlers in pos.js
			// still fire via #pos-manual-discount / #pos-preset-discount / #pos-edit-discount.
			$(document).on('click', '#pos-manual-discount, #pos-preset-discount, #pos-edit-discount', function () {
				$('#adj-discount-wrap').removeClass('open');
				$('#adj-discount-toggle').attr('aria-expanded', 'false');
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