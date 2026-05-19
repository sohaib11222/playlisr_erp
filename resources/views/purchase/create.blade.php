@extends('layouts.app')
@section('title', __('purchase.add_purchase'))

@section('content')

@php
	$custom_labels = json_decode(session('business.custom_labels'), true);
@endphp
<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>@lang('purchase.add_purchase') <i class="fa fa-keyboard-o hover-q text-muted" aria-hidden="true" data-container="body" data-toggle="popover" data-placement="bottom" data-content="@include('purchase.partials.keyboard_shortcuts_details')" data-html="true" data-trigger="hover" data-original-title="" title=""></i></h1>
</section>

<!-- Main content -->
<section class="content">

	<!-- Page level currency setting -->
	<input type="hidden" id="p_code" value="{{$currency_details->code}}">
	<input type="hidden" id="p_symbol" value="{{$currency_details->symbol}}">
	<input type="hidden" id="p_thousand" value="{{$currency_details->thousand_separator}}">
	<input type="hidden" id="p_decimal" value="{{$currency_details->decimal_separator}}">
	<input type="hidden" id="prefill_product_ids" value="{{ !empty($from_product_ids) ? implode(',', $from_product_ids) : '' }}">

	@include('layouts.partials.error')

	{!! Form::open(['url' => action('PurchaseController@store'), 'method' => 'post', 'id' => 'add_purchase_form', 'files' => true ]) !!}
	{!! Form::hidden('save_action', 'save', ['id' => 'purchase_save_action']) !!}

	@component('components.widget', ['class' => 'box-primary'])
		<div class="row">
			<div class="@if(!empty($default_purchase_status)) col-sm-4 @else col-sm-3 @endif">
				<div class="form-group">
					{!! Form::label('supplier_id', __('purchase.supplier') . ':*') !!}
					<div class="input-group">
						<span class="input-group-addon">
							<i class="fa fa-user"></i>
						</span>
						{!! Form::select('contact_id', [], null, ['class' => 'form-control', 'placeholder' => __('messages.please_select'), 'required', 'id' => 'supplier_id']) !!}
						<span class="input-group-btn">
							<button type="button" class="btn btn-default bg-white btn-flat add_new_supplier" data-name=""><i class="fa fa-plus-circle text-primary fa-lg"></i></button>
						</span>
					</div>
					<p class="help-block">Type <strong>walkin</strong> and select the Walk-in supplier for a used collection.</p>
				</div>
				{{-- supplier address block removed (Sarah 2026-05-19) — kept the hidden
				     div so any legacy JS that targets #supplier_address_div is harmless. --}}
				<div id="supplier_address_div" style="display:none;"></div>
			</div>
			<div class="@if(!empty($default_purchase_status)) col-sm-4 @else hide col-sm-3 @endif">
				<div class="form-group">
					{!! Form::label('ref_no', __('purchase.ref_no').':') !!}
					@show_tooltip(__('lang_v1.leave_empty_to_autogenerate'))
					{!! Form::text('ref_no', null, ['class' => 'form-control']) !!}
				</div>
			</div>
			<div class="@if(!empty($default_purchase_status)) col-sm-4 @else col-sm-3 @endif">
				<div class="form-group">
					{!! Form::label('transaction_date', __('purchase.purchase_date') . ':*') !!}
					<div class="input-group">
						<span class="input-group-addon">
							<i class="fa fa-calendar"></i>
						</span>
						{!! Form::text('transaction_date', @format_datetime('now'), ['class' => 'form-control', 'readonly', 'required']) !!}
					</div>
				</div>
			</div>
			<div class="col-sm-3 @if(!empty($default_purchase_status)) hide @endif">
				<div class="form-group">
					{!! Form::label('status', __('purchase.purchase_status') . ':*') !!} @show_tooltip(__('tooltip.order_status'))
					{!! Form::select('status', $orderStatuses, $default_purchase_status, ['class' => 'form-control select2', 'placeholder' => __('messages.please_select'), 'required']) !!}
					<p class="help-block">Choose <strong>received</strong> if item is in hand.</p>
				</div>
			</div>
			@if(count($business_locations) == 1)
				@php
					$default_location = current(array_keys($business_locations->toArray()));
					$search_disable = false;
				@endphp
			@else
				@php
					// Prefer the location the user is signed in / on duty at.
					// Falls back to null (no default) only if neither session
					// value is set, which keeps the original behavior.
					$default_location = !empty($user_default_location_id) ? $user_default_location_id : null;
					$search_disable = true;
				@endphp
			@endif
			<div class="col-sm-3">
				<div class="form-group">
					{!! Form::label('location_id', __('purchase.business_location').':*') !!}
					@show_tooltip(__('tooltip.purchase_location'))
					{!! Form::select('location_id', $business_locations, $default_location, ['class' => 'form-control select2', 'placeholder' => __('messages.please_select'), 'required'], $bl_attributes) !!}
				</div>
			</div>

			<!-- Currency Exchange Rate -->
			<div class="col-sm-3 @if(!$currency_details->purchase_in_diff_currency) hide @endif">
				<div class="form-group">
					{!! Form::label('exchange_rate', __('purchase.p_exchange_rate') . ':*') !!}
					@show_tooltip(__('tooltip.currency_exchange_factor'))
					<div class="input-group">
						<span class="input-group-addon">
							<i class="fa fa-info"></i>
						</span>
						{!! Form::number('exchange_rate', $currency_details->p_exchange_rate, ['class' => 'form-control', 'required', 'step' => 0.001]); !!}
					</div>
					<span class="help-block text-danger">
						@lang('purchase.diff_purchase_currency_help', ['currency' => $currency_details->name])
					</span>
				</div>
			</div>

			<div class="col-md-3 hide">
		          <div class="form-group">
		            <div class="multi-input">
		              {!! Form::label('pay_term_number', __('contact.pay_term') . ':') !!} @show_tooltip(__('tooltip.pay_term'))
		              <br/>
		              {!! Form::number('pay_term_number', null, ['class' => 'form-control width-40 pull-left', 'placeholder' => __('contact.pay_term')]); !!}

		              {!! Form::select('pay_term_type',
		              	['months' => __('lang_v1.months'),
		              		'days' => __('lang_v1.days')],
		              		null,
		              	['class' => 'form-control width-60 pull-left','placeholder' => __('messages.please_select'), 'id' => 'pay_term_type']); !!}
		            </div>
		        </div>
		    </div>

			<div class="col-sm-3 hide" >
                <div class="form-group">
                    {!! Form::label('document', __('purchase.attach_document') . ':') !!}
                    {!! Form::file('document', ['id' => 'upload_document', 'accept' => implode(',', array_keys(config('constants.document_upload_mimes_types')))]); !!}
                    <p class="help-block">
                    	@lang('purchase.max_file_size', ['size' => (config('constants.document_size_limit') / 1000000)])
                    	@includeIf('components.document_help_text')
                    </p>
                </div>
            </div>
		</div>
		<div class="row">
			@php
		    $custom_field_1_label = !empty($custom_labels['purchase']['custom_field_1']) ? $custom_labels['purchase']['custom_field_1'] : '';

		    $is_custom_field_1_required = !empty($custom_labels['purchase']['is_custom_field_1_required']) && $custom_labels['purchase']['is_custom_field_1_required'] == 1 ? true : false;

		    $custom_field_2_label = !empty($custom_labels['purchase']['custom_field_2']) ? $custom_labels['purchase']['custom_field_2'] : '';

		    $is_custom_field_2_required = !empty($custom_labels['purchase']['is_custom_field_2_required']) && $custom_labels['purchase']['is_custom_field_2_required'] == 1 ? true : false;

		    $custom_field_3_label = !empty($custom_labels['purchase']['custom_field_3']) ? $custom_labels['purchase']['custom_field_3'] : '';

		    $is_custom_field_3_required = !empty($custom_labels['purchase']['is_custom_field_3_required']) && $custom_labels['purchase']['is_custom_field_3_required'] == 1 ? true : false;

		    $custom_field_4_label = !empty($custom_labels['purchase']['custom_field_4']) ? $custom_labels['purchase']['custom_field_4'] : '';

		    $is_custom_field_4_required = !empty($custom_labels['purchase']['is_custom_field_4_required']) && $custom_labels['purchase']['is_custom_field_4_required'] == 1 ? true : false;
		@endphp
		@if(!empty($custom_field_1_label))
			@php
				$label_1 = $custom_field_1_label . ':';
				if($is_custom_field_1_required) {
					$label_1 .= '*';
				}
			@endphp

			<div class="col-md-4">
		        <div class="form-group">
		            {!! Form::label('custom_field_1', $label_1 ) !!}
		            {!! Form::text('custom_field_1', null, ['class' => 'form-control','placeholder' => $custom_field_1_label, 'required' => $is_custom_field_1_required]); !!}
		        </div>
		    </div>
		@endif
		@if(!empty($custom_field_2_label))
			@php
				$label_2 = $custom_field_2_label . ':';
				if($is_custom_field_2_required) {
					$label_2 .= '*';
				}
			@endphp

			<div class="col-md-4">
		        <div class="form-group">
		            {!! Form::label('custom_field_2', $label_2 ) !!}
		            {!! Form::text('custom_field_2', null, ['class' => 'form-control','placeholder' => $custom_field_2_label, 'required' => $is_custom_field_2_required]); !!}
		        </div>
		    </div>
		@endif
		@if(!empty($custom_field_3_label))
			@php
				$label_3 = $custom_field_3_label . ':';
				if($is_custom_field_3_required) {
					$label_3 .= '*';
				}
			@endphp

			<div class="col-md-4">
		        <div class="form-group">
		            {!! Form::label('custom_field_3', $label_3 ) !!}
		            {!! Form::text('custom_field_3', null, ['class' => 'form-control','placeholder' => $custom_field_3_label, 'required' => $is_custom_field_3_required]); !!}
		        </div>
		    </div>
		@endif
		@if(!empty($custom_field_4_label))
			@php
				$label_4 = $custom_field_4_label . ':';
				if($is_custom_field_4_required) {
					$label_4 .= '*';
				}
			@endphp

			<div class="col-md-4">
		        <div class="form-group">
		            {!! Form::label('custom_field_4', $label_4 ) !!}
		            {!! Form::text('custom_field_4', null, ['class' => 'form-control','placeholder' => $custom_field_4_label, 'required' => $is_custom_field_4_required]); !!}
		        </div>
		    </div>
		@endif
		</div>
		@if(!empty($common_settings['enable_purchase_order']))
		<div class="row">
			<div class="col-sm-3">
				<div class="form-group">
					{!! Form::label('purchase_order_ids', __('lang_v1.purchase_order').':') !!}
					{!! Form::select('purchase_order_ids[]', [], null, ['class' => 'form-control select2', 'multiple', 'id' => 'purchase_order_ids']); !!}
				</div>
			</div>
		</div>
		@endif
	@endcomponent

	@component('components.widget', ['class' => 'box-primary'])
		<div class="row">
			<div class="col-sm-12">
				<div class="form-group">
					<button type="button" class="btn btn-primary btn-flat" id="open_mass_add_product_btn"><i class="fa fa-plus"></i> Mass Add</button>
				</div>
			</div>
			<div class="col-sm-8">
				<div class="form-group">
					<div class="input-group">
						<span class="input-group-addon">
							<i class="fa fa-search"></i>
						</span>
						{!! Form::text('search_product', null, ['class' => 'form-control mousetrap', 'id' => 'search_product', 'placeholder' => __('lang_v1.search_product_placeholder'), 'disabled' => $search_disable]); !!}
					</div>
				</div>
			</div>
		</div>
		@php
			$hide_tax = '';
			if( session()->get('business.enable_inline_tax') == 0){
				$hide_tax = 'hide';
			}
		@endphp
		<div class="row">
			<div class="col-sm-12">
				<div class="table-responsive">
					<table class="table table-condensed table-bordered table-th-green text-center table-striped" id="purchase_entry_table">
						<thead>
							<tr>
								<th>#</th>
								<th>@lang( 'product.product_name' )</th>
								<th>@lang( 'purchase.purchase_quantity' )</th>
								<th>@lang( 'lang_v1.unit_cost_before_discount' )</th>
								<th>@lang( 'lang_v1.discount_percent' )</th>
								<th>@lang( 'purchase.unit_cost_before_tax' )</th>
								<th class="{{$hide_tax}}">@lang( 'purchase.subtotal_before_tax' )</th>
								<th class="{{$hide_tax}}">@lang( 'purchase.product_tax' )</th>
								<th class="{{$hide_tax}}">@lang( 'purchase.net_cost' )</th>
								<th>@lang( 'purchase.line_total' )</th>
								<th class="@if(!session('business.enable_editing_product_from_purchase')) hide @endif">
									@lang( 'lang_v1.profit_margin' )
								</th>
								<th>
									@lang( 'purchase.unit_selling_price' )
									<small>(pre-tax sticker)</small>
								</th>
								@if(session('business.enable_lot_number'))
									<th>
										@lang('lang_v1.lot_number')
									</th>
								@endif
								@if(session('business.enable_product_expiry'))
									<th>
										@lang('product.mfg_date') / @lang('product.exp_date')
									</th>
								@endif
								<th><i class="fa fa-trash" aria-hidden="true"></i></th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
				<hr/>
				<div class="pull-right col-md-5">
					<table class="pull-right col-md-12">
						<tr>
							<th class="col-md-7 text-right">@lang( 'lang_v1.total_items' ):</th>
							<td class="col-md-5 text-left">
								<span id="total_quantity" class="display_currency" data-currency_symbol="false"></span>
							</td>
						</tr>
						<tr class="hide">
							<th class="col-md-7 text-right">@lang( 'purchase.total_before_tax' ):</th>
							<td class="col-md-5 text-left">
								<span id="total_st_before_tax" class="display_currency"></span>
								<input type="hidden" id="st_before_tax_input" value=0>
							</td>
						</tr>
						<tr>
							<th class="col-md-7 text-right">@lang( 'purchase.net_total_amount' ):</th>
							<td class="col-md-5 text-left">
								<span id="total_subtotal" class="display_currency"></span>
								<!-- This is total before purchase tax-->
								<input type="hidden" id="total_subtotal_input" value=0  name="total_before_tax">
							</td>
						</tr>
					</table>
				</div>

				<input type="hidden" id="row_count" value="0">
			</div>
		</div>
	@endcomponent

	@component('components.widget', ['class' => 'box-primary hide'])
		<div class="row" style="display: none">
			<div class="col-sm-12">
			<table class="table">
				<tr>
					<td class="col-md-3">
						<div class="form-group">
							{!! Form::label('discount_type', __( 'purchase.discount_type' ) . ':') !!}
							{!! Form::select('discount_type', [ '' => __('lang_v1.none'), 'fixed' => __( 'lang_v1.fixed' ), 'percentage' => __( 'lang_v1.percentage' )], '', ['class' => 'form-control select2']); !!}
						</div>
					</td>
					<td class="col-md-3">
						<div class="form-group">
						{!! Form::label('discount_amount', __( 'purchase.discount_amount' ) . ':') !!}
						{!! Form::text('discount_amount', 0, ['class' => 'form-control input_number', 'required']); !!}
						</div>
					</td>
					<td class="col-md-3">
						&nbsp;
					</td>
					<td class="col-md-3">
						<b>@lang( 'purchase.discount' ):</b>(-)
						<span id="discount_calculated_amount" class="display_currency">0</span>
					</td>
				</tr>
				<tr>
					<td>
						<div class="form-group">
						{!! Form::label('tax_id', __('purchase.purchase_tax') . ':') !!}
						<select name="tax_id" id="tax_id" class="form-control select2" placeholder="'Please Select'">
							<option value="" data-tax_amount="0" data-tax_type="fixed" selected>@lang('lang_v1.none')</option>
							@foreach($taxes as $tax)
								<option value="{{ $tax->id }}" data-tax_amount="{{ $tax->amount }}" data-tax_type="{{ $tax->calculation_type }}">{{ $tax->name }}</option>
							@endforeach
						</select>
						{!! Form::hidden('tax_amount', 0, ['id' => 'tax_amount']); !!}
						</div>
					</td>
					<td>&nbsp;</td>
					<td>&nbsp;</td>
					<td>
						<b>@lang( 'purchase.purchase_tax' ):</b>(+)
						<span id="tax_calculated_amount" class="display_currency">0</span>
					</td>
				</tr>
				<tr>
					<td colspan="4">
						<div class="form-group">
							{!! Form::label('additional_notes',__('purchase.additional_notes')) !!}
							{!! Form::textarea('additional_notes', null, ['class' => 'form-control', 'rows' => 3]); !!}
						</div>
					</td>
				</tr>
			</table>
			</div>
		</div>
	@endcomponent
	@component('components.widget', ['class' => 'box-primary hide'])
	<div class="row" style="display: none">
		<div class="col-md-4">
			<div class="form-group">
			{!! Form::label('shipping_details', __( 'purchase.shipping_details' ) . ':') !!}
			{!! Form::text('shipping_details', null, ['class' => 'form-control']); !!}
			</div>
		</div>
		<div class="col-md-4 col-md-offset-4">
			<div class="form-group">
				{!! Form::label('shipping_charges','(+) ' . __( 'purchase.additional_shipping_charges' ) . ':') !!}
				{!! Form::text('shipping_charges', 0, ['class' => 'form-control input_number', 'required']); !!}
			</div>
		</div>
	</div>
	<div class="row" style="display: none">
			@php
			    $shipping_custom_label_1 = !empty($custom_labels['purchase_shipping']['custom_field_1']) ? $custom_labels['purchase_shipping']['custom_field_1'] : '';

			    $is_shipping_custom_field_1_required = !empty($custom_labels['purchase_shipping']['is_custom_field_1_required']) && $custom_labels['purchase_shipping']['is_custom_field_1_required'] == 1 ? true : false;

			    $shipping_custom_label_2 = !empty($custom_labels['purchase_shipping']['custom_field_2']) ? $custom_labels['purchase_shipping']['custom_field_2'] : '';

			    $is_shipping_custom_field_2_required = !empty($custom_labels['purchase_shipping']['is_custom_field_2_required']) && $custom_labels['purchase_shipping']['is_custom_field_2_required'] == 1 ? true : false;

			    $shipping_custom_label_3 = !empty($custom_labels['purchase_shipping']['custom_field_3']) ? $custom_labels['purchase_shipping']['custom_field_3'] : '';

			    $is_shipping_custom_field_3_required = !empty($custom_labels['purchase_shipping']['is_custom_field_3_required']) && $custom_labels['purchase_shipping']['is_custom_field_3_required'] == 1 ? true : false;

			    $shipping_custom_label_4 = !empty($custom_labels['purchase_shipping']['custom_field_4']) ? $custom_labels['purchase_shipping']['custom_field_4'] : '';

			    $is_shipping_custom_field_4_required = !empty($custom_labels['purchase_shipping']['is_custom_field_4_required']) && $custom_labels['purchase_shipping']['is_custom_field_4_required'] == 1 ? true : false;

			    $shipping_custom_label_5 = !empty($custom_labels['purchase_shipping']['custom_field_5']) ? $custom_labels['purchase_shipping']['custom_field_5'] : '';

			    $is_shipping_custom_field_5_required = !empty($custom_labels['purchase_shipping']['is_custom_field_5_required']) && $custom_labels['purchase_shipping']['is_custom_field_5_required'] == 1 ? true : false;
			@endphp

			@if(!empty($shipping_custom_label_1))
				@php
					$label_1 = $shipping_custom_label_1 . ':';
					if($is_shipping_custom_field_1_required) {
						$label_1 .= '*';
					}
				@endphp

				<div class="col-md-4">
			        <div class="form-group">
			            {!! Form::label('shipping_custom_field_1', $label_1 ) !!}
			            {!! Form::text('shipping_custom_field_1', null, ['class' => 'form-control','placeholder' => $shipping_custom_label_1, 'required' => $is_shipping_custom_field_1_required]); !!}
			        </div>
			    </div>
			@endif
			@if(!empty($shipping_custom_label_2))
				@php
					$label_2 = $shipping_custom_label_2 . ':';
					if($is_shipping_custom_field_2_required) {
						$label_2 .= '*';
					}
				@endphp

				<div class="col-md-4">
			        <div class="form-group">
			            {!! Form::label('shipping_custom_field_2', $label_2 ) !!}
			            {!! Form::text('shipping_custom_field_2', null, ['class' => 'form-control','placeholder' => $shipping_custom_label_2, 'required' => $is_shipping_custom_field_2_required]); !!}
			        </div>
			    </div>
			@endif
			@if(!empty($shipping_custom_label_3))
				@php
					$label_3 = $shipping_custom_label_3 . ':';
					if($is_shipping_custom_field_3_required) {
						$label_3 .= '*';
					}
				@endphp

				<div class="col-md-4">
			        <div class="form-group">
			            {!! Form::label('shipping_custom_field_3', $label_3 ) !!}
			            {!! Form::text('shipping_custom_field_3', null, ['class' => 'form-control','placeholder' => $shipping_custom_label_3, 'required' => $is_shipping_custom_field_3_required]); !!}
			        </div>
			    </div>
			@endif
			@if(!empty($shipping_custom_label_4))
				@php
					$label_4 = $shipping_custom_label_4 . ':';
					if($is_shipping_custom_field_4_required) {
						$label_4 .= '*';
					}
				@endphp

				<div class="col-md-4">
			        <div class="form-group">
			            {!! Form::label('shipping_custom_field_4', $label_4 ) !!}
			            {!! Form::text('shipping_custom_field_4', null, ['class' => 'form-control','placeholder' => $shipping_custom_label_4, 'required' => $is_shipping_custom_field_4_required]); !!}
			        </div>
			    </div>
			@endif
			@if(!empty($shipping_custom_label_5))
				@php
					$label_5 = $shipping_custom_label_5 . ':';
					if($is_shipping_custom_field_5_required) {
						$label_5 .= '*';
					}
				@endphp

				<div class="col-md-4">
			        <div class="form-group">
			            {!! Form::label('shipping_custom_field_5', $label_5 ) !!}
			            {!! Form::text('shipping_custom_field_5', null, ['class' => 'form-control','placeholder' => $shipping_custom_label_5, 'required' => $is_shipping_custom_field_5_required]); !!}
			        </div>
			    </div>
			@endif
		</div>
		<div class="row">
			<div class="col-md-12 text-center">
				<button type="button" class="btn btn-primary btn-sm" id="toggle_additional_expense"> <i class="fas fa-plus"></i> @lang('lang_v1.add_additional_expenses') <i class="fas fa-chevron-down"></i></button>
			</div>
			<div class="col-md-8 col-md-offset-4" id="additional_expenses_div" style="display: none;">
				<table class="table table-condensed">
					<thead>
						<tr>
							<th>@lang('lang_v1.additional_expense_name')</th>
							<th>@lang('sale.amount')</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td>
								{!! Form::text('additional_expense_key_1', null, ['class' => 'form-control', 'id' => 'additional_expense_key_1']); !!}
							</td>
							<td>
								{!! Form::text('additional_expense_value_1', 0, ['class' => 'form-control input_number', 'id' => 'additional_expense_value_1']); !!}
							</td>
						</tr>
						<tr>
							<td>
								{!! Form::text('additional_expense_key_2', null, ['class' => 'form-control', 'id' => 'additional_expense_key_2']); !!}
							</td>
							<td>
								{!! Form::text('additional_expense_value_2', 0, ['class' => 'form-control input_number', 'id' => 'additional_expense_value_2']); !!}
							</td>
						</tr>
						<tr>
							<td>
								{!! Form::text('additional_expense_key_3', null, ['class' => 'form-control', 'id' => 'additional_expense_key_3']); !!}
							</td>
							<td>
								{!! Form::text('additional_expense_value_3', 0, ['class' => 'form-control input_number', 'id' => 'additional_expense_value_3']); !!}
							</td>
						</tr>
						<tr>
							<td>
								{!! Form::text('additional_expense_key_4', null, ['class' => 'form-control', 'id' => 'additional_expense_key_4']); !!}
							</td>
							<td>
								{!! Form::text('additional_expense_value_4', 0, ['class' => 'form-control input_number', 'id' => 'additional_expense_value_4']); !!}
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
		<div class="row">
			<div class="col-md-12 text-right">
				{!! Form::hidden('final_total', 0 , ['id' => 'grand_total_hidden']); !!}
						<b>@lang('purchase.purchase_total'): </b><span id="grand_total" class="display_currency" data-currency_symbol='true'>0</span>
			</div>
		</div>
	@endcomponent
	@component('components.widget', ['class' => 'box-primary', 'title' => __('purchase.add_payment')])
		<div class="box-body payment_row">
			{{-- "Advance Balance: 0" hidden (Sarah 2026-05-19). The hidden input
			     is kept because some pages JS depends on #advance_balance. --}}
			{!! Form::hidden('advance_balance', null, ['id' => 'advance_balance', 'data-error-msg' => __('lang_v1.required_advance_balance_not_available')]); !!}
			<span id="advance_balance_text" style="display:none;">0</span>

			{{-- Purchase-price guardrail banner — lives next to the payment
			     method row so cashiers see it right when they're finalizing.
			     JS in this view watches every line in the entry table and
			     disables Save when any line is $0 (unless donated is checked). --}}
			<div id="purchase_price_guard" style="background:#FFF2B3; border:2px solid #F0DC7A; border-radius:10px; padding:12px 16px; margin-bottom:14px; box-shadow: 0 0 0 3px rgba(255, 242, 179, 0.4);">
				<div style="display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;">
					<label style="margin:0; font-weight:700; color:#1F1B16; cursor:pointer; font-size:14px;">
						{!! Form::checkbox('is_donated', 1, false, ['id' => 'is_donated_checkbox', 'style' => 'margin-right:8px; transform:scale(1.2);']) !!}
						These items were donated (free stock)
					</label>
					<span id="purchase_price_guard_status" style="font-size:13px; font-weight:600; color:#5A4410;"></span>
				</div>
				<div style="font-size:12px; color:#5A4410; margin-top:6px;">
					Purchase price must be greater than <strong>$0</strong> on every line. Check the box above to skip this rule for donated / free stock.
				</div>
			</div>

			@include('sale_pos.partials.payment_row_form', ['row_index' => 0, 'show_date' => true, 'show_denomination' => true])
			<hr>
			<div class="row">
				<div class="col-sm-12">
					<div class="pull-right"><strong>@lang('purchase.payment_due'):</strong> <span id="payment_due">0.00</span></div>
				</div>
			</div>
			<br>
			<div class="row">
				<div class="col-sm-12">
					<button type="button" id="submit_purchase_form" class="btn btn-primary pull-right btn-flat">@lang('messages.save')</button>
					<button type="button" id="submit_purchase_form_print_labels" class="btn btn-success pull-right btn-flat" style="margin-right: 8px;">
						<i class="fas fa-barcode"></i> Save &amp; Print Labels
					</button>
				</div>
			</div>
		</div>
	@endcomponent

{!! Form::close() !!}
</section>
<!-- quick product modal -->
<div class="modal fade quick_add_product_modal" tabindex="-1" role="dialog" aria-labelledby="modalTitle"></div>
<!-- Mass Add product modal (iframe) -->
<div class="modal fade mass_add_product_modal" id="mass_add_product_modal" tabindex="-1" role="dialog" aria-labelledby="massAddModalTitle" style="z-index: 1050;">
	<div class="modal-dialog modal-xl" role="document" style="width: 95%; max-width: 1200px;">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
				<h4 class="modal-title" id="massAddModalTitle">Mass Add Products</h4>
			</div>
			<div class="modal-body" style="padding: 0; height: 80vh;">
				<iframe id="mass_add_product_iframe" style="width: 100%; height: 100%; border: none;"></iframe>
			</div>
		</div>
	</div>
</div>
<div class="modal fade contact_modal" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel">
	@include('contact.create', ['quick_add' => true])
</div>

@include('purchase.partials.import_purchase_products_modal')
<!-- /.content -->
@endsection

@section('javascript')
	<script src="{{ asset('js/purchase.js?v=' . $asset_v) }}"></script>
	<script src="{{ asset('js/product.js?v=' . $asset_v) }}"></script>
	<script type="text/javascript">
		// Purchase-price guardrail (Sarah 2026-05-19). Watches every line in
		// the entry table — if any price is <= $0 and "donated" is unchecked,
		// marks the line red and disables Save. Re-evaluates on input change,
		// row add/remove, and donated checkbox toggle.
		(function () {
			function parseMoney($el) {
				var raw = String($el.val() || '').replace(/[^0-9.\-]/g, '');
				var n = parseFloat(raw);
				return isNaN(n) ? 0 : n;
			}
			function refreshPriceGuard() {
				var $rows = $('#purchase_entry_table tbody tr');
				var donated = $('#is_donated_checkbox').is(':checked');
				var bad = 0;
				$rows.each(function () {
					var $row = $(this);
					var $price = $row.find('.purchase_unit_cost').first();
					if ($price.length === 0) return;
					var amount = parseMoney($price);
					var rowBad = !donated && amount <= 0;
					$row.find('.purchase_unit_cost, .purchase_unit_cost_after_tax, .purchase_unit_cost_without_discount')
						.css('border-color', rowBad ? '#DC2626' : '')
						.css('background', rowBad ? '#FEE2E2' : '');
					if (rowBad) bad++;
				});
				var $status = $('#purchase_price_guard_status');
				var $buttons = $('#submit_purchase_form, #submit_purchase_form_print_labels');
				if (donated) {
					$status.html('<span style="color:#5A4410;">Donated — $0 prices allowed</span>');
					$buttons.prop('disabled', false);
				} else if (bad > 0) {
					$status.html('<span style="color:#991B1B;">' + bad + ' line' + (bad === 1 ? '' : 's') + ' missing a price — save disabled</span>');
					$buttons.prop('disabled', true);
				} else if ($rows.length === 0) {
					$status.html('<span style="color:#8B6914;">Add a product to begin.</span>');
					$buttons.prop('disabled', false);
				} else {
					$status.html('<span style="color:#166534;">✓ All ' + $rows.length + ' line' + ($rows.length === 1 ? '' : 's') + ' priced</span>');
					$buttons.prop('disabled', false);
				}
			}
			$(document)
				.on('input change keyup',
					'#purchase_entry_table .purchase_unit_cost, ' +
					'#purchase_entry_table .purchase_unit_cost_after_tax, ' +
					'#purchase_entry_table .purchase_unit_cost_without_discount, ' +
					'#purchase_entry_table .purchase_quantity',
					refreshPriceGuard)
				.on('change', '#is_donated_checkbox', refreshPriceGuard)
				.on('click', '.pos_remove_row, .remove_purchase_entry_row', function () {
					setTimeout(refreshPriceGuard, 80);
				});

			// MutationObserver is more reliable than DOMNodeInserted (which is
			// deprecated and inconsistently fires for inserts done by complex
			// AJAX chains like the mass-add modal).
			var tbody = document.querySelector('#purchase_entry_table tbody');
			if (tbody && typeof MutationObserver !== 'undefined') {
				new MutationObserver(function () {
					clearTimeout(window.__pprGuardTimer);
					window.__pprGuardTimer = setTimeout(refreshPriceGuard, 60);
				}).observe(tbody, { childList: true, subtree: true });
			}

			// Belt-and-suspenders: also poll every 1s so even a row added by a
			// channel my listeners can't catch (jQuery .html(), iframe → parent
			// postMessage, etc.) is re-evaluated.
			setInterval(refreshPriceGuard, 1000);

			$(refreshPriceGuard);
		})();

		$(document).ready( function(){
      		__page_leave_confirmation('#add_purchase_form');
      		$('.paid_on').datetimepicker({
                format: moment_date_format + ' ' + moment_time_format,
                ignoreReadonly: true,
            });
    	});
    	$(document).on('change', '.payment_types_dropdown, #location_id', function(e) {
		    var default_accounts = $('select#location_id').length ?
		                $('select#location_id')
		                .find(':selected')
		                .data('default_payment_accounts') : [];
		    var payment_types_dropdown = $('.payment_types_dropdown');
		    var payment_type = payment_types_dropdown.val();
		    var payment_row = payment_types_dropdown.closest('.payment_row');
	        var row_index = payment_row.find('.payment_row_index').val();

	        var account_dropdown = payment_row.find('select#account_' + row_index);
		    if (payment_type && payment_type != 'advance') {
		        var default_account = default_accounts && default_accounts[payment_type]['account'] ?
		            default_accounts[payment_type]['account'] : '';
		        if (account_dropdown.length && default_accounts) {
		            account_dropdown.val(default_account);
		            account_dropdown.change();
		        }
		    }

		    if (payment_type == 'advance') {
		        if (account_dropdown) {
		            account_dropdown.prop('disabled', true);
		            account_dropdown.closest('.form-group').addClass('hide');
		        }
		    } else {
		        if (account_dropdown) {
		            account_dropdown.prop('disabled', false);
		            account_dropdown.closest('.form-group').removeClass('hide');
		        }
		    }
		});
	</script>
	@include('purchase.partials.keyboard_shortcuts')
@endsection

@section('css')
<style>
    /* ============================================================
       /purchases/create — POS-create palette overlay.
       Cream backdrop, warm amber accents, unified typography so the
       purchase form feels like /pos/create's sibling. Scoped to
       section.content so the rest of the ERP is unaffected.
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
        border: 1px solid #DFD2B3;
    }
    section.content .form-control:focus {
        border-color: #F0DC7A;
        box-shadow: 0 0 0 3px rgba(255, 242, 179, 0.4);
    }
    section.content label,
    section.content .control-label {
        font-weight: 600;
        font-size: 13px;
        letter-spacing: 0.2px;
        color: #1F1B16;
    }
    section.content small,
    section.content .help-block {
        font-size: 12px;
        color: #8B6914;
    }
    /* Card surfaces mirror POS-create cream tones */
    section.content .box.box-primary {
        border-top-color: #F0DC7A;
        border-radius: 10px;
        box-shadow: 0 1px 0 rgba(31, 27, 22, 0.04);
    }
    section.content .box.box-primary > .box-header {
        background: #FAF6EE;
        border-bottom: 1px solid #DFD2B3;
        border-radius: 10px 10px 0 0;
    }
    section.content .box.box-primary > .box-header .box-title {
        color: #1F1B16;
        font-weight: 700;
        letter-spacing: 0.02em;
    }
    /* Button normalization — match POS-create one-size + radius */
    section.content .btn:not(.btn-lg):not(#search_product):not(.input-group-btn > .btn) {
        font-family: inherit;
        font-size: 13px;
        font-weight: 600;
        padding: 7px 14px;
        height: 36px;
        line-height: 1.2;
        border-radius: 6px;
        letter-spacing: 0.2px;
    }
    /* Save / Save & Print Labels — the prominent finalize pair */
    section.content #submit_purchase_form,
    section.content #submit_purchase_form_print_labels {
        font-size: 15px;
        font-weight: 700;
        padding: 10px 18px;
        min-height: 44px;
        border-radius: 8px;
        letter-spacing: 0.3px;
    }
    section.content #submit_purchase_form {
        background: #1F1B16;
        border-color: #1F1B16;
        color: #FAF6EE;
    }
    section.content #submit_purchase_form:hover,
    section.content #submit_purchase_form:focus {
        background: #0F0A06;
        border-color: #0F0A06;
        color: #FAF6EE;
    }
    section.content #submit_purchase_form_print_labels {
        background: #2F6B3E;
        border-color: #2F6B3E;
        color: #fff;
    }
    section.content #submit_purchase_form_print_labels:hover,
    section.content #submit_purchase_form_print_labels:focus {
        background: #235530;
        border-color: #235530;
        color: #fff;
    }
    /* Hide the page footer like POS does — every pixel counts */
    body footer.main-footer,
    body > footer.no-print {
        display: none !important;
    }

    /* Make purchase entry rows single-line and compact */
    #purchase_entry_table tbody tr {
        height: auto !important;
        min-height: 35px;
    }
    
    #purchase_entry_table tbody td {
        padding: 4px 6px !important;
        vertical-align: middle !important;
        white-space: nowrap;
    }
    
    #purchase_entry_table tbody td input[type="text"],
    #purchase_entry_table tbody td select {
        margin: 0;
        padding: 2px 5px;
        font-size: 12px;
        height: 28px;
        line-height: 1.2;
        vertical-align: middle;
    }
    
    #purchase_entry_table tbody td .input-group {
        margin: 0;
        display: inline-block;
    }
    
    #purchase_entry_table tbody td .input-group-addon {
        padding: 2px 5px;
        font-size: 11px;
    }
    
    /* Ensure product name column doesn't wrap */
    #purchase_entry_table tbody td:first-child + td {
        max-width: 250px;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    
    /* Compact date pickers */
    #purchase_entry_table .expiry_datepicker {
        font-size: 11px !important;
        padding: 2px 5px !important;
    }
    
    /* Quantity column - everything inline on one line using flexbox - FORCE IT */
    #purchase_entry_table tbody td:nth-child(3) {
        white-space: nowrap !important;
        padding: 4px 6px !important;
        line-height: 1 !important;
    }
    
    #purchase_entry_table tbody td:nth-child(3) > div {
        display: flex !important;
        align-items: center !important;
        gap: 5px !important;
        white-space: nowrap !important;
        flex-wrap: nowrap !important;
        width: 100% !important;
    }
    
    #purchase_entry_table tbody td:nth-child(3) input[type="text"],
    #purchase_entry_table tbody td:nth-child(3) select,
    #purchase_entry_table tbody td:nth-child(3) span {
        display: inline-block !important;
        vertical-align: middle !important;
        margin: 0 !important;
        flex-shrink: 0 !important;
        float: none !important;
    }
    
    /* Force quantity input and unit to be on same line - override Bootstrap */
    #purchase_entry_table tbody td:nth-child(3) .purchase_quantity {
        width: 70px !important;
        display: inline-block !important;
        margin-right: 5px !important;
        float: none !important;
        clear: none !important;
    }
    
    #purchase_entry_table tbody td:nth-child(3) .sub_unit {
        width: auto !important;
        min-width: 80px !important;
        display: inline-block !important;
        margin-left: 0 !important;
        float: none !important;
        clear: none !important;
    }
    
    /* Remove any block-level spacing or line breaks */
    #purchase_entry_table tbody td:nth-child(3) br {
        display: none !important;
    }
    
    /* Override any Bootstrap form-control block display */
    #purchase_entry_table tbody td:nth-child(3) .form-control {
        display: inline-block !important;
        width: auto !important;
    }
    
    /* Prevent any block-level elements */
    #purchase_entry_table tbody td:nth-child(3) > div > * {
        display: inline-block !important;
        float: none !important;
    }
</style>
@endsection