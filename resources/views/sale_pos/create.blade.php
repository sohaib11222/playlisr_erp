@extends('layouts.app')

@section('title', __('sale.pos_sale'))

{{-- POS checkout redesign v2 (2026-04-20): pulls in the scoped stylesheet
     that reskins this screen per nivessa_pos_redesign.html. Add the
     body.pos-v2 hook so the CSS only applies here — safer than touching
     the shared layout file.
     2026-04-22: bumped to force Blade recompile after _redesign_v2 CSS
     changes (compiled-view cache was holding the pre-hotfix inline). --}}
@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>
{{-- Sarah 2026-04-22 LAYOUT HOTFIX — the rules live in a real static
     file under /public/css/ so nginx serves them directly. Takes PHP
     OPcache / Blade compile cache out of the loop entirely. --}}
<link rel="stylesheet" href="{{ asset('css/pos-create-layout.css?v=' . $asset_v) }}">
@include('sale_pos.partials.pos_duty_banner')
<section class="content no-print">
	<input type="hidden" id="amount_rounding_method" value="{{$pos_settings['amount_rounding_method'] ?? ''}}">
	@if(!empty($pos_settings['allow_overselling']))
		<input type="hidden" id="is_overselling_allowed">
	@endif
	@if(session('business.enable_rp') == 1)
        <input type="hidden" id="reward_point_enabled">
    @endif
    @php
		$is_discount_enabled = $pos_settings['disable_discount'] != 1 ? true : false;
		$is_rp_enabled = session('business.enable_rp') == 1 ? true : false;
	@endphp
	{!! Form::open(['url' => action('SellPosController@store'), 'method' => 'post', 'id' => 'add_pos_sell_form' ]) !!}
	<div class="row mb-12">
		<div class="col-md-12">
			<div class="row">
				<div class="@if(empty($pos_settings['hide_product_suggestion'])) col-sm-8 @else col-md-10 col-md-offset-1 @endif no-padding pr-12">
					<div class="box box-solid mb-12 @if(!isMobile()) mb-40 @endif">
						<div class="box-body pb-0">
							{!! Form::hidden('location_id', $default_location->id ?? null , ['id' => 'location_id', 'data-receipt_printer_type' => !empty($default_location->receipt_printer_type) ? $default_location->receipt_printer_type : 'browser', 'data-default_payment_accounts' => $default_location->default_payment_accounts ?? '']) !!}
							<!-- sub_type -->
							{!! Form::hidden('sub_type', isset($sub_type) ? $sub_type : null) !!}
							<input type="hidden" id="item_addition_method" value="{{$business_details->item_addition_method}}">
								@include('sale_pos.partials.pos_form')

								@include('sale_pos.partials.pos_form_totals')

								@include('sale_pos.partials.payment_modal')

								@if(empty($pos_settings['disable_suspend']))
									@include('sale_pos.partials.suspend_note_modal')
								@endif

								@if(empty($pos_settings['disable_recurring_invoice']))
									@include('sale_pos.partials.recurring_invoice_modal')
								@endif
							</div>
						</div>
					</div>
				@if(empty($pos_settings['hide_product_suggestion']) && !isMobile())
				{{-- Sarah 2026-04-22: col-sm-4 so the Quick Add sidebar stays on
				     the right at viewports as narrow as 768px. See matching
				     col-sm-8 comment above. --}}
				<div class="col-sm-4 no-padding">
					@include('sale_pos.partials.pos_sidebar')
				</div>
				@endif
			</div>
		</div>
	</div>

	@include('sale_pos.partials.pos_form_actions')

	{!! Form::close() !!}
</section>

<!-- This will be printed -->
<section class="invoice print_section" id="receipt_section"></section>

<div class="modal fade contact_modal" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel">
	@include('contact.create', ['quick_add' => true])
</div>

@if(empty($pos_settings['hide_product_suggestion']) && isMobile())
	@include('sale_pos.partials.mobile_product_suggestions')
@endif

<!-- /.content -->
<div class="modal fade register_details_modal" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel"></div>

<div class="modal fade close_register_modal" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel"></div>

<!-- quick product modal -->
<div class="modal fade quick_add_product_modal" tabindex="-1" role="dialog" aria-labelledby="modalTitle"></div>

<div class="modal fade" id="expense_modal" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel"></div>

@include('sale_pos.partials.configure_search_modal')

{{-- Sarah 2026-04-30: "Recently rung up" widget — position:fixed in the
     tan area to the left of the cart on wide screens, hidden under 1200px.
     Pulled out of layout flow so it cannot affect the locked cart layout. --}}
@include('sale_pos.partials._recent_rings_panel')

@include('sale_pos.partials.recent_transactions_modal')

@include('sale_pos.partials.weighing_scale_modal')

@include('sale_pos.partials.add_manual_product_modal')

@include('sale_pos.partials.customer_account_modal')

{{-- Buy Calculator iframe modal removed (2026-04-19): iframe loaded blank for some users,
     likely auth/cookie issues when the protected route is loaded inside an iframe.
     Button now opens the calculator in a new tab — simpler + more reliable. --}}

@stop
@section('css')
	<!-- POS: scrollable product table so totals/actions stay visible without page scroll -->
	<style>
		.pos_product_div {
			max-height: 50vh;
			overflow-y: auto;
			-webkit-overflow-scrolling: touch;
		}
		.pos_form_totals {
			flex-shrink: 0;
		}
		/* Keep checkout actions visible on short/old monitors */
		@@media (min-width: 768px) and (max-height: 900px) {
			.pos-form-actions {
				position: sticky;
				bottom: 0;
				z-index: 1030;
				background: #f5f6f8;
				padding: 8px 0;
				border-top: 1px solid #d9dde3;
			}
		}

		/* ============================================================
		   POS page: unified typography + button sizing
		   Scoped to section.content so it does NOT affect the rest
		   of the ERP. Everything below only applies inside /pos/create.
		   ============================================================ */
		section.content,
		section.content .box-body,
		section.content .form-group,
		section.content label,
		section.content p,
		section.content td,
		section.content th,
		section.content .control-label,
		section.content .help-block,
		section.content small {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
			font-size: 14px;
			line-height: 1.45;
			color: #2b3440;
		}
		section.content .form-control {
			font-family: inherit;
			font-size: 14px;
			height: 38px;
			padding: 6px 12px;
			border-radius: 6px;
		}
		section.content label,
		section.content .control-label {
			font-weight: 600;
			font-size: 13px;
			letter-spacing: 0.2px;
		}
		section.content small,
		section.content .help-block {
			font-size: 12px;
		}

		/* Button normalization — one size, one radius, consistent weight.
		   Excludes: the big POS-express finalize buttons (cash/card/checkout)
		   which need to stay prominent, and the big search input group. */
		section.content .btn:not(.pos-express-finalize):not(#pos-finalize):not(.btn-lg):not(#search_product):not(.input-group-btn > .btn) {
			font-family: inherit;
			font-size: 13px;
			font-weight: 600;
			padding: 7px 14px;
			height: 36px;
			line-height: 1.2;
			border-radius: 6px;
			border: 1px solid rgba(0, 0, 0, 0.08);
			letter-spacing: 0.2px;
		}
		section.content .btn-xs:not(.pos-express-finalize) {
			font-size: 12px;
			padding: 5px 10px;
			height: 30px;
		}
		/* Finalize / express checkout — keep bold + larger so they stand out */
		section.content .btn.pos-express-finalize,
		section.content .btn#pos-finalize {
			font-family: inherit;
			font-size: 15px;
			font-weight: 700;
			padding: 10px 18px;
			min-height: 44px;
			border-radius: 8px;
			letter-spacing: 0.3px;
		}

		/* Tables inside POS — align line-heights for easier scanning */
		section.content table.table-condensed td,
		section.content table.table-condensed th {
			padding: 8px 10px;
			vertical-align: middle;
		}

		/* Inputs inside the pos product list row — compact qty cell. Jonathan
		   said the previous 40px/15px was too big and was eating visual space. */
		section.content table#pos_table input.form-control {
			height: 32px;
			font-size: 14px;
			width: 54px;
			text-align: center;
		}
		section.content table#pos_table .input-number {
			max-width: 150px;
			margin: 0 auto;
		}
		/* Qty +/- buttons — compact but still tap-friendly. */
		section.content table#pos_table .quantity-up,
		section.content table#pos_table .quantity-down {
			min-height: 32px;
			min-width: 32px;
			padding: 0;
			font-size: 13px;
			line-height: 1;
		}
		section.content table#pos_table .quantity-up i,
		section.content table#pos_table .quantity-down i {
			font-size: 13px;
		}

		/* Bag-fee row is auto-added to the cart but Jonathan doesn't want it
		   showing up like a product. Hide the row entirely — it's kept in the
		   DOM so pos.js can still read the quantity/price for totals, it just
		   doesn't render. The fee is still visible as the checkbox chip above
		   the totals and a muted line in the adjustments list. */
		section.content table#pos_table tr[data-plastic-bag="true"] {
			display: none !important;
		}

		/* Nivessa sells everything by the piece (1 record = 1 piece). The
		   default product template still renders a sub-unit <select> ("Pieces")
		   and a second "Quantity in Pc(s)*" input below the qty +/- controls,
		   which Jonathan saw as "why do i need to enter qty twice?". Hide both
		   — the hidden product_unit_id input still submits the unit, and the
		   sub_unit_id / secondary_unit_quantity inputs keep their default
		   values so nothing server-side breaks. */
		section.content table#pos_table .sub_unit,
		section.content table#pos_table input[name*="secondary_unit_quantity"],
		section.content table#pos_table input[name*="secondary_unit_quantity"] ~ br,
		section.content table#pos_table .quantity_in_unit_help,
		section.content table#pos_table span:has(> input[name*="secondary_unit_quantity"]) { display:none !important; }
		/* Fallback for browsers without :has() — hide any <br> or short-label
		   text between qty input-group and its siblings. */
		section.content table#pos_table .input-number + br { display:none; }

		/* Shrink the row-remove X (was way too large) */
		section.content table#pos_table .pos_remove_row,
		section.content table#pos_table .fa-times,
		section.content table#pos_table .fa-trash,
		section.content table#pos_table .remove-row,
		section.content table#pos_table a[title*="Remove"],
		section.content table#pos_table button.pos_remove_row {
			font-size: 14px !important;
			color: #dc2626;
		}
		section.content table#pos_table thead th i.fa-times { font-size: 11px; opacity: 0.5; }

		/* Hide the "ERP V4.7.8 | Copyright" footer on POS — every pixel counts here */
		body footer.main-footer,
		body > footer.no-print {
			display: none !important;
		}

		/* Make the cart (pos_table) the most prominent element — it's the actual sale. */
		section.content table#pos_table {
			font-size: 15px;
		}
		section.content table#pos_table tbody tr td {
			padding: 10px 8px;
			vertical-align: middle;
		}
		section.content table#pos_table tbody tr td .product_name,
		section.content table#pos_table tbody tr td .product-name {
			font-size: 15px;
			font-weight: 600;
		}
		section.content .pos_product_div {
			max-height: 58vh;   /* a touch more breathing room for the cart */
		}

		/* Totals row — labels and running values at the same size so a cashier's eye
		   doesn't bounce. Darker text too; 12px muted gray was the main "inconsistent
		   and hard to read" complaint. Grand total still dominates at 26px. */
		section.content .pos_form_totals table.table-condensed td b {
			font-weight: 600;
			color: #1f2937;
			font-size: 14px;
			text-transform: uppercase;
			letter-spacing: 0.4px;
		}
		/* #pre_tax_amount was dragged down to 14px here by the legacy totals
		   design; now that it lives inside the Pre-Tax → Clover hero bar, it
		   should inherit the 24px/800 from .pretax-amt. Only constrain
		   #order_tax_display (still a compact receipt row). */
		section.content .pos_form_totals #order_tax_display {
			font-weight: 600 !important;
			font-size: 14px !important;
			color: #1f2937 !important;
		}
		/* Make the "create account/rewards" CTA impossible to miss — we want cashiers
		   to enroll walk-ins into Nivessa Bucks every chance they get. */
		.pos-customer-block .add_new_customer {
			background: linear-gradient(135deg, #fde68a, #f59e0b) !important;
			color: #78350f !important;
			border: 2px solid #f59e0b !important;
			font-weight: 800 !important;
			font-size: 13px !important;
			padding: 8px 14px !important;
			text-transform: uppercase !important;
			letter-spacing: 0.5px !important;
			border-radius: 8px !important;
			box-shadow: 0 1px 3px rgba(245, 158, 11, 0.2) !important;
		}
		.pos-customer-block .add_new_customer:hover {
			background: linear-gradient(135deg, #fcd34d, #d97706) !important;
			color: #78350f !important;
		}
		.pos-customer-block .add_new_customer i { color: #78350f !important; }
	</style>
	<!-- include module css -->
    @if(!empty($pos_module_data))
        @foreach($pos_module_data as $key => $value)
            @if(!empty($value['module_css_path']))
                @includeIf($value['module_css_path'])
            @endif
        @endforeach
    @endif
@stop
@section('javascript')
	<script>
		window.manualItemPriceRules = @json($manual_item_price_rules ?? []);
	</script>
	<script src="{{ asset('js/pos.js?v=' . $asset_v) }}"></script>
	<script src="{{ asset('js/printer.js?v=' . $asset_v) }}"></script>
	<script src="{{ asset('js/product.js?v=' . $asset_v) }}"></script>
	<script src="{{ asset('js/opening_stock.js?v=' . $asset_v) }}"></script>
	@include('sale_pos.partials.keyboard_shortcuts')

	<!-- Call restaurant module if defined -->
    @if(in_array('tables' ,$enabled_modules) || in_array('modifiers' ,$enabled_modules) || in_array('service_staff' ,$enabled_modules))
    	<script src="{{ asset('js/restaurant.js?v=' . $asset_v) }}"></script>
    @endif
    <!-- include module js -->
    @if(!empty($pos_module_data))
	    @foreach($pos_module_data as $key => $value)
            @if(!empty($value['module_js_path']))
                @includeIf($value['module_js_path'], ['view_data' => $value['view_data']])
            @endif
	    @endforeach
	@endif
@endsection