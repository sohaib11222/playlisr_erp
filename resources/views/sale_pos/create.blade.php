@extends('layouts.app')

@section('title', __('sale.pos_sale'))

@section('content')
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
				<div class="@if(empty($pos_settings['hide_product_suggestion'])) col-md-7 @else col-md-10 col-md-offset-1 @endif no-padding pr-12">
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
				<div class="col-md-5 no-padding">
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

@include('sale_pos.partials.recent_transactions_modal')

@include('sale_pos.partials.weighing_scale_modal')

@include('sale_pos.partials.add_manual_product_modal')

@include('sale_pos.partials.customer_account_modal')

{{-- Buy Calculator popup (iframes /buy-from-customer?embed=1) --}}
<div class="modal fade" id="buy_calculator_modal" tabindex="-1" role="dialog" aria-labelledby="buyCalculatorModalLabel">
	<div class="modal-dialog modal-lg" role="document" style="width: 95%; max-width: 1200px;">
		<div class="modal-content" style="min-height: 85vh;">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				<h4 class="modal-title" id="buyCalculatorModalLabel"><i class="fa fa-calculator"></i> Buy from Customer Calculator</h4>
			</div>
			<div class="modal-body" style="padding: 0;">
				<iframe id="buy_calculator_iframe" src="about:blank" style="width: 100%; height: 80vh; border: 0;"></iframe>
			</div>
		</div>
	</div>
</div>
<script>
	$(document).on('show.bs.modal', '#buy_calculator_modal', function() {
		var url = $('#open_buy_calculator_modal').data('url');
		$('#buy_calculator_iframe').attr('src', url);
	});
	$(document).on('hidden.bs.modal', '#buy_calculator_modal', function() {
		// Clear iframe on close so fresh session starts next time
		$('#buy_calculator_iframe').attr('src', 'about:blank');
	});
</script>

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

		/* Inputs inside the pos product list row */
		section.content table#pos_table input.form-control {
			height: 34px;
			font-size: 14px;
		}
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