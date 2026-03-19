<div class="row pos_form_totals">
	<div class="col-md-12">
		<input type="hidden" name="store_credit_used_amount" id="store_credit_used_amount" value="0">
		<div id="pos_store_credit_row" class="form-group" style="margin-bottom: 12px; display: none;">
			<strong>Store Credit Available:</strong>
			<span id="pos_store_credit_amount" class="text-success" style="font-weight: bold; margin-right: 8px;">$0.00</span>
			<button type="button" class="btn btn-xs btn-success" id="btn_use_store_credit">
				Use Store Credit
			</button>
		</div>
		@if(!empty($pos_settings['enable_plastic_bag_charge']))
		<div class="form-group" style="margin-bottom: 12px;">
			<div class="checkbox">
				<label>
					<input type="checkbox" id="add_plastic_bag" name="add_plastic_bag" value="1" checked>
					<strong>Add Bag Fee</strong>
					<span id="plastic_bag_price_display" class="text-muted">
						(${{ number_format($pos_settings['plastic_bag_price'] ?? 0.10, 2) }})
					</span>
				</label>
			</div>
			<input type="hidden" id="plastic_bag_price" value="{{ $pos_settings['plastic_bag_price'] ?? 0.10 }}">
		</div>
		@endif
		<table class="table table-condensed" style="margin-top: 8px; margin-bottom: 8px; font-size: 14px;">
			<tr>
				<td style="white-space: nowrap;">
					<b>@lang('sale.item'):</b>&nbsp;
					<span class="total_quantity">0</span></td>
				<td style="white-space: nowrap;">
					<b>Without Tax:</b>&nbsp;
					<span id="pre_tax_amount" class="text-dark" style="font-weight: bold; font-size: 20px;">0</span>
				</td>
				<td class="@if($pos_settings['disable_order_tax'] != 0) hide @endif" style="white-space: nowrap;">
					<b>Tax:</b>&nbsp;
					<span id="order_tax_display" style="font-weight: bold;">0</span>
				</td>
				<td style="white-space: nowrap;">
					<b>Total (with Tax):</b>&nbsp;
					<span id="total_with_tax" style="font-weight: bold; font-size: 15px;">0</span>
				</td>
			</tr>
			<tr>
				<td>
					<b>
						@if($is_discount_enabled)
							@lang('sale.discount')
							@show_tooltip(__('tooltip.sale_discount'))
						@endif
						@if($is_rp_enabled)
							{{session('business.rp_name')}}
						@endif
						@if($is_discount_enabled)
							(-):
							@if($edit_discount)
							<i class="fas fa-edit cursor-pointer" id="pos-edit-discount" title="@lang('sale.edit_discount')" aria-hidden="true" data-toggle="modal" data-target="#posEditDiscountModal"></i>
							@endif
							<button type="button" class="btn btn-xs btn-info" id="pos-manual-discount" title="Apply Manual Discount" style="margin-left: 5px;">
								<i class="fa fa-percent"></i> Manual Discount
							</button>
							<button type="button" class="btn btn-xs btn-primary" id="pos-preset-discount" title="Apply Preset Discount" style="margin-left: 5px;">
								<i class="fa fa-tags"></i> Preset Discount
							</button>
						
							<span id="total_discount">0</span>
						@endif
							<input type="hidden" name="discount_type" id="discount_type" value="@if(empty($edit)){{'percentage'}}@else{{$transaction->discount_type}}@endif" data-default="percentage">

							<input type="hidden" name="discount_amount" id="discount_amount" value="@if(empty($edit)) {{0}} @else {{@num_format($transaction->discount_amount)}} @endif" data-default="0">

							<input type="hidden" name="discount_reason" id="discount_reason" value="@if(empty($edit)){{''}}@else{{$transaction->discount_reason ?? ''}}@endif">

							<input type="hidden" name="rp_redeemed" id="rp_redeemed" value="@if(empty($edit)){{'0'}}@else{{$transaction->rp_redeemed}}@endif">

							<input type="hidden" name="rp_redeemed_amount" id="rp_redeemed_amount" value="@if(empty($edit)){{'0'}}@else {{$transaction->rp_redeemed_amount}} @endif">

							</span>
					</b> 
				</td>
				<td class="@if($pos_settings['disable_order_tax'] != 0) hide @endif">
					<span>
						<b>@lang('sale.order_tax')(+): @show_tooltip(__('tooltip.sale_tax'))</b>
						<i class="fas fa-edit cursor-pointer" title="@lang('sale.edit_order_tax')" aria-hidden="true" data-toggle="modal" data-target="#posEditOrderTaxModal" id="pos-edit-tax" ></i> 
						<span id="order_tax">
							@if(empty($edit))
								0
							@else
								{{$transaction->tax_amount}}
							@endif
						</span>

						<input type="hidden" name="tax_rate_id" 
							id="tax_rate_id" 
							value="@if(empty($edit)) @if(!empty($business_details->default_sales_tax)){{$business_details->default_sales_tax}}@endif @else {{$transaction->tax_id}} @endif" 
							data-default="@if(!empty($business_details->default_sales_tax)){{$business_details->default_sales_tax}}@endif">

						<input type="hidden" name="tax_calculation_amount" id="tax_calculation_amount" 
							value="@if(empty($edit)) {{@num_format($business_details->tax_calculation_amount)}} @else {{@num_format(optional($transaction->tax)->amount)}} @endif" data-default="{{$business_details->tax_calculation_amount}}">

					</span>
				</td>
				<td>
					<span>

						<b>@lang('sale.shipping')(+): @show_tooltip(__('tooltip.shipping'))</b> 
						<i class="fas fa-edit cursor-pointer"  title="@lang('sale.shipping')" aria-hidden="true" data-toggle="modal" data-target="#posShippingModal"></i>
						<span id="shipping_charges_amount">0</span>
						<input type="hidden" name="shipping_details" id="shipping_details" value="@if(empty($edit)){{''}}@else{{$transaction->shipping_details}}@endif" data-default="">

						<input type="hidden" name="shipping_address" id="shipping_address" value="@if(empty($edit)){{''}}@else{{$transaction->shipping_address}}@endif">

						<input type="hidden" name="shipping_status" id="shipping_status" value="@if(empty($edit)){{''}}@else{{$transaction->shipping_status}}@endif">

						<input type="hidden" name="delivered_to" id="delivered_to" value="@if(empty($edit)){{''}}@else{{$transaction->delivered_to}}@endif">

						<input type="hidden" name="shipping_charges" id="shipping_charges" value="@if(empty($edit)){{@num_format(0.00)}} @else{{@num_format($transaction->shipping_charges)}} @endif" data-default="0.00">
					</span>
				</td>
				@if(in_array('types_of_service', $enabled_modules))
					<td class="col-sm-3 col-xs-6 d-inline-table">
						<b>@lang('lang_v1.packing_charge')(+):</b>
						<i class="fas fa-edit cursor-pointer service_modal_btn"></i> 
						<span id="packing_charge_text">
							0
						</span>
					</td>
				@endif
				@if(!empty($pos_settings['amount_rounding_method']) && $pos_settings['amount_rounding_method'] > 0)
				<td>
					<b id="round_off">@lang('lang_v1.round_off'):</b> <span id="round_off_text">0</span>								
					<input type="hidden" name="round_off_amount" id="round_off_amount" value=0>
				</td>
				@endif
			</tr>
		</table>
	</div>
</div>