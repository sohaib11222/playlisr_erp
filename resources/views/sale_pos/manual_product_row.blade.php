@php
	$common_settings = session()->get('business.common_settings');
	$hide_tax = session()->get('business.enable_inline_tax') == 1 ? '' : 'hide';

	// Drinks/snacks are tax-exempt at POS. Resolve before the <tr> opens
	// so data-tax-exempt makes it onto the row — get_taxable_subtotal in
	// pos.js skips rows with that attribute when summing the tax base.
	$is_tax_exempt = false;
	if (!empty($category) && \App\Product::categoryNameIsTaxExempt($category->name ?? '')) {
		$is_tax_exempt = true;
	}
	if (!$is_tax_exempt && !empty($subCategory) && \App\Product::categoryNameIsTaxExempt($subCategory->name ?? '')) {
		$is_tax_exempt = true;
	}
@endphp

<tr class="product_row manual_product_row" data-row_index="{{$rowCount}}" @if($is_tax_exempt) data-tax-exempt="true" @endif>
	<td>
		{{-- Product Name and Artist --}}
		<div>
			<strong>{{ $productName }}</strong>
			@if(!empty($artist))
				<br><small class="text-muted">Artist: {{ $artist }}</small>
			@endif
		</div>
		
		{{-- Category and Sub Category --}}
		@if(!empty($category) || !empty($subCategory))
			<div class="text-muted small">
				@if(!empty($category))
					<div><span>Category: {{ $category->name }}</span></div>
				@endif
				@if(!empty($subCategory))
					<div><span>Sub-Category: {{ $subCategory->name }}</span></div>
				@endif
			</div>
		@endif

		{{-- Hidden fields for manual product --}}
		<input type="hidden" class="enable_sr_no" value="0">
		<input type="hidden" class="product_type" name="products[{{$rowCount}}][product_type]" value="single">
		<input type="hidden" name="products[{{$rowCount}}][product_id]" value="manual">
		<input type="hidden" name="products[{{$rowCount}}][product_name]" value="{{ $productName }}">
		<input type="hidden" name="products[{{$rowCount}}][product_artist]" value="{{ $artist }}">
		<input type="hidden" name="products[{{$rowCount}}][category_id]" value="{{ $category_id }}">
		<input type="hidden" name="products[{{$rowCount}}][sub_category_id]" value="{{ $sub_category_id }}">
		<input type="hidden" name="products[{{$rowCount}}][variation_id]" value="">
		<input type="hidden" name="products[{{$rowCount}}][enable_stock]" value="0">
		<input type="hidden" name="products[{{$rowCount}}][product_unit_id]" value="">
		<input type="hidden" class="base_unit_multiplier" name="products[{{$rowCount}}][base_unit_multiplier]" value="1">
		<input type="hidden" class="hidden_base_unit_sell_price" value="{{ $price }}">
	</td>

	<td>
		{{-- Quantity input --}}
		<div class="input-group input-number">
			<span class="input-group-btn">
				<button type="button" class="btn btn-default btn-flat quantity-down">
					<i class="fa fa-minus text-danger"></i>
				</button>
			</span>
			<input type="text" 
				data-min="1" 
				class="form-control pos_quantity input_number mousetrap input_quantity" 
				value="1" 
				name="products[{{$rowCount}}][quantity]" 
				data-allow-overselling="true"
				data-decimal="0" 
				data-rule-required="true" 
				data-msg-required="@lang('validation.custom-messages.this_field_is_required')">
			<span class="input-group-btn">
				<button type="button" class="btn btn-default btn-flat quantity-up">
					<i class="fa fa-plus text-success"></i>
				</button>
			</span>
		</div>
	</td>

	{{-- Unit Price --}}
	<td class="hide">
		<input type="text" 
			name="products[{{$rowCount}}][unit_price]" 
			class="form-control pos_unit_price input_number mousetrap" 
			value="{{ number_format($price, 2) }}" 
			readonly>
	</td>

	{{-- Tax --}}
	<td class="text-center {{$hide_tax}}">
		<input type="hidden" name="products[{{$rowCount}}][item_tax]" class="item_tax" value="0">
		@if(!empty($tax_dropdown) && !empty($tax_dropdown['tax_rates']))
			{!! Form::select("products[{$rowCount}][tax_id]", $tax_dropdown['tax_rates'], !empty($default_tax) ? $default_tax : null, ['placeholder' => 'Select', 'class' => 'form-control tax_id'], $tax_dropdown['attributes']); !!}
		@else
			<select name="products[{{$rowCount}}][tax_id]" class="form-control tax_id">
				<option value="">@lang('lang_v1.select_tax')</option>
			</select>
		@endif
	</td>

	{{-- Purchase Price (read-only placeholder for manual rows) --}}
	<td class="text-center {{$hide_tax}}">
		<span class="display_currency" data-currency_symbol="true">0</span>
	</td>

	{{-- Unit Price Inc Tax --}}
	<td class="{{$hide_tax}}">
		<input type="text" 
			name="products[{{$rowCount}}][unit_price_inc_tax]" 
			class="form-control pos_unit_price_inc_tax input_number" 
			value="{{ number_format($price, 2) }}" 
			readonly>
	</td>

	{{-- Warranty (if enabled) --}}
	@if(!empty($common_settings['enable_product_warranty']))
		<td>
			<select name="products[{{$rowCount}}][warranty_id]" class="form-control">
				<option value="">@lang('messages.please_select')</option>
			</select>
		</td>
	@endif

	{{-- Line Total --}}
	<td class="text-center">
		{{-- <input type="text" 
			class="form-control pos_line_total input_number" 
			value="{{ number_format($price, 2) }}" 
			readonly> --}}
        <input type="hidden" class="form-control pos_line_total @if(!empty($pos_settings['is_pos_subtotal_editable'])) input_number @endif" value="{{ $price }}">
		<span class="display_currency pos_line_total_text" data-currency_symbol="true">$ {{ number_format($price, 2) }}</span>
	</td>

	{{-- Remove button --}}
	<td class="text-center v-center">
		<button type="button" class="btn btn-default pos_remove_row" aria-label="Remove line" style="min-height:36px; min-width:36px; padding:0; line-height:1;">
			<i class="fa fa-times text-danger" aria-hidden="true"></i>
		</button>
	</td>
</tr>