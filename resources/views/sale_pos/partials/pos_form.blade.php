<div class="row">
	<div class="col-md-5 pos-customer-block">
		<style>.pos-customer-block .select2-container { width: 100% !important; min-width: 0; margin-bottom: 8px; } .pos-customer-select2-dropdown { min-width: 320px !important; } .pos-customer-block .select2-selection__rendered { white-space: normal !important; word-break: break-word; }</style>
		<div class="form-group">
			<label class="control-label">@lang('contact.customer')</label>
			<div class="input-group" style="margin-bottom: 4px;">
				<span class="input-group-addon">
					<i class="fa fa-user"></i>
				</span>
				<input type="hidden" id="default_customer_id" 
				value="{{ $walk_in_customer['id'] ?? ''}}" >
				<input type="hidden" id="default_customer_name" 
				value="{{ $walk_in_customer['name'] ?? ''}}" >
				<input type="hidden" id="default_customer_display_name" 
				value="{{ $walk_in_display_name ?? $walk_in_customer['name'] ?? ''}}" >
				<input type="hidden" id="default_customer_balance" 
				value="{{ $walk_in_customer['balance'] ?? ''}}" >
				<input type="hidden" id="default_customer_address" 
				value="{{ $walk_in_customer['shipping_address'] ?? ''}}" >
				@if(!empty($walk_in_customer['price_calculation_type']) && $walk_in_customer['price_calculation_type'] == 'selling_price_group')
					<input type="hidden" id="default_selling_price_group" 
				value="{{ $walk_in_customer['selling_price_group_id'] ?? ''}}" >
				@endif
				{!! Form::select('contact_id',
					[], null, ['class' => 'form-control mousetrap', 'id' => 'customer_id', 'placeholder' => 'Search by name, phone, or email…', 'required', 'style' => 'width: 100%;']) !!}
			</div>
			<div style="margin-top: 10px;">
				<button type="button" class="btn add_new_customer" data-name="" @if(!auth()->user()->can('customer.create')) disabled @endif title="Enroll this customer in Nivessa Bucks rewards">
					<i class="fa fa-star"></i>&nbsp; Sign Up for Nivessa Bucks
				</button>
				<small class="text-muted" style="display:block; margin-top:4px;">Rewards on every purchase — ask every walk-in.</small>
			</div>
			<small class="text-danger hide contact_due_text"><strong>@lang('account.customer_due'):</strong> <span></span></small>
		</div>
	</div>
	<div class="col-md-7">
		<!-- Customer Account Info Display -->
		<div id="customer_account_info" class="customer-account-info" style="display: none; margin-bottom: 10px; padding: 8px; background-color: #f8f9fa; border-radius: 4px; border: 1px solid #dee2e6;">
			<div class="row">
				<div class="col-xs-12 col-sm-8">
					<strong id="customer_account_name" style="color: #495057;"></strong>
				</div>
				<div class="col-xs-12 col-sm-4 text-right" style="margin-top: 4px;">
					<button type="button" class="btn btn-xs btn-default" id="clear_customer_btn" title="Clear selected account">
						<i class="fa fa-times-circle"></i> Clear Account
					</button>
					<button type="button" class="btn btn-xs btn-info" id="view_customer_details_btn">
						<i class="fa fa-info-circle"></i> View/Edit Account
					</button>
				</div>
			</div>
			<div class="row" style="margin-top: 5px;">
				<div class="col-xs-6 col-sm-6 col-md-3">
					<small><strong>Credit:</strong> <span id="customer_account_balance" class="text-danger">$0.00</span></small>
				</div>
				<div class="col-xs-6 col-sm-6 col-md-3">
					<small><strong>Gift Cards:</strong> <span id="customer_gift_card_balance" class="text-success">$0.00</span></small>
				</div>
				<div class="col-xs-6 col-sm-6 col-md-3">
					<small><strong>Lifetime:</strong> <span id="customer_lifetime_purchases">$0.00</span></small>
				</div>
				<div class="col-xs-6 col-sm-6 col-md-3">
					<small><strong>Points:</strong> <span id="customer_loyalty_points">0</span></small>
				</div>
			</div>
			<!-- Employee Discount Checkbox - Only shown for employee customers -->
			<div class="row" id="employee_discount_row" style="margin-top: 10px; display: none;">
				<div class="col-md-12">
					<div class="checkbox">
						<label>
							<input type="checkbox" id="apply_employee_discount" name="apply_employee_discount" value="1">
							<strong style="color: #f39c12;">Apply Employee Discount (20%)</strong>
							<span class="text-muted">(20% discount on all items)</span>
						</label>
					</div>
				</div>
			</div>
		</div>
		
		<div class="form-group">
			<style>
				/* Product search — dominant, standalone row */
				.pos-product-search-wrap { position: relative; display: flex; align-items: stretch; }
				.pos-product-search-label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: 1.2px; color: #1b6ca8; font-weight: 700; margin-bottom: 6px; }
				.pos-product-search-configbtn {
					flex: 0 0 auto;
					height: 56px; width: 52px;
					border: 2px solid #1b6ca8; border-right: none;
					background: #f0f7fc; color: #1b6ca8;
					border-radius: 8px 0 0 8px;
					display: inline-flex; align-items: center; justify-content: center;
					cursor: pointer; font-size: 18px;
				}
				.pos-product-search-configbtn:hover { background: #e1eef8; }
				.pos-product-search-wrap #search_product {
					flex: 1 1 auto;
					height: 56px;
					font-size: 22px;
					font-weight: 600;
					padding: 10px 16px;
					border: 2px solid #1b6ca8;
					border-radius: 0 8px 8px 0;
					box-shadow: 0 0 0 3px rgba(27, 108, 168, 0.12);
					background: #ffffff;
				}
				.pos-product-search-wrap #search_product:focus {
					border-color: #13507a;
					box-shadow: 0 0 0 4px rgba(27, 108, 168, 0.25);
					outline: none;
				}
				.pos-product-search-wrap #search_product::placeholder { color: #8a9ba8; font-weight: 500; }

				/* Secondary action row — sits below search, not inside it */
				.pos-action-row { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
				.pos-action-row .btn { height: 40px; padding: 0 16px; font-weight: 600; font-size: 13px; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px; }
				.pos-quick-preset {
					background: linear-gradient(135deg, #fef3c7, #fde68a);
					color: #78350f; border: 1px solid #f59e0b;
				}
				.pos-quick-preset:hover { background: linear-gradient(135deg, #fde68a, #fcd34d); color: #78350f; }
				.pos-quick-preset .preset-price { font-size: 11px; opacity: 0.75; font-weight: 700; margin-left: 2px; }
			</style>
			<label class="pos-product-search-label" for="search_product"><i class="fa fa-search"></i> Ring Up / Scan Product</label>
			<div class="pos-product-search-wrap">
				<button type="button" class="pos-product-search-configbtn" data-toggle="modal" data-target="#configure_search_modal" title="{{__('lang_v1.configure_product_search')}}"><i class="fas fa-search-plus"></i></button>
				{!! Form::text('search_product', null, ['class' => 'form-control mousetrap', 'id' => 'search_product', 'placeholder' => 'Type product name, artist, or scan barcode…',
					'disabled' => is_null($default_location)? true : false,
					'autofocus' => is_null($default_location)? false : true,
				]) !!}
			</div>

			<div class="pos-action-row">
				@if(isset($pos_settings['enable_weighing_scale']) && $pos_settings['enable_weighing_scale'] == 1)
					<button type="button" class="btn btn-default" id="weighing_scale_btn" data-toggle="modal" data-target="#weighing_scale_modal" title="@lang('lang_v1.weighing_scale')"><i class="fa fa-digital-tachograph text-primary"></i> Scale</button>
				@endif
				<button type="button" class="btn btn-default pos_add_quick_product" data-href="{{action('ProductController@quickAdd')}}" data-container=".quick_add_product_modal" title="Quick add product"><i class="fa fa-plus-circle text-primary"></i> New Product</button>
				<button type="button" class="btn btn-default pos_add_manual_product" title="Add Manual Item" data-href="/" data-container=".add_manual_product_modal"><i class="fa fa-pen"></i> Add Manual Item</button>
				<a href="{{ route('buy-from-customer.create') }}" target="_blank" rel="noopener" class="btn btn-info" title="Open Buy from Customer Calculator in a new tab">
					<i class="fa fa-calculator"></i> Buy Calculator <i class="fa fa-external-link-alt" style="font-size: 11px; opacity: 0.7; margin-left: 4px;"></i>
				</a>
			</div>

			{{-- Quick-add preset tiles now live at the top of the product grid sidebar
				 (see sale_pos/partials/pos_sidebar.blade.php). Wiring script below still
				 applies — it listens on .pos-quick-preset anywhere in the page. --}}

			{{-- Wire up the quick-add tiles to open the existing Add Manual Item modal with name + price pre-filled. --}}
			<script>
			(function () {
				$(document).on('click', '.pos-quick-preset', function () {
					var name = $(this).data('preset-name');
					var price = $(this).data('preset-price');
					// Trigger the existing Add Manual Item flow first so the modal is built.
					$('.pos_add_manual_product').trigger('click');
					// Then fill the first row once the modal is shown.
					$('#add_manual_product_modal').one('shown.bs.modal', function () {
						var $row = $('#manual_products_container .manual_product_row').first();
						if ($row.length === 0) return;
						$row.find('input[name*="[name]"]').val(name).trigger('change').trigger('blur');
						$row.find('input[name*="[price]"]').val(price).trigger('input').trigger('change');
					});
				});
			})();
			</script>
		</div>
	</div>
</div>
<div class="row">
	@if(!empty($pos_settings['show_invoice_layout']))
	<div class="col-md-4">
		<div class="form-group">
		{!! Form::select('invoice_layout_id', 
					$invoice_layouts, $default_location->invoice_layout_id, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.select_invoice_layout'), 'id' => 'invoice_layout_id']) !!}
		</div>
	</div>
	@endif
	<input type="hidden" name="pay_term_number" id="pay_term_number" value="{{$walk_in_customer['pay_term_number'] ?? ''}}">
	<input type="hidden" name="pay_term_type" id="pay_term_type" value="{{$walk_in_customer['pay_term_type'] ?? ''}}">
	
	@if(!empty($commission_agent))
		@php
			$is_commission_agent_required = !empty($pos_settings['is_commission_agent_required']);
		@endphp
		<div class="col-md-4">
			<div class="form-group">
			{!! Form::select('commission_agent', 
						$commission_agent, null, ['class' => 'form-control select2', 'placeholder' => __('lang_v1.commission_agent'), 'id' => 'commission_agent', 'required' => $is_commission_agent_required]) !!}
			</div>
		</div>
	@endif
	@if(!empty($pos_settings['enable_transaction_date']))
		<div class="col-md-4 col-sm-6">
			<div class="form-group">
				<div class="input-group">
					<span class="input-group-addon">
						<i class="fa fa-calendar"></i>
					</span>
					{!! Form::text('transaction_date', $default_datetime, ['class' => 'form-control', 'readonly', 'required', 'id' => 'transaction_date']) !!}
				</div>
			</div>
		</div>
	@endif
	@if(config('constants.enable_sell_in_diff_currency') == true)
		<div class="col-md-4 col-sm-6">
			<div class="form-group">
				<div class="input-group">
					<span class="input-group-addon">
						<i class="fas fa-exchange-alt"></i>
					</span>
					{!! Form::text('exchange_rate', config('constants.currency_exchange_rate'), ['class' => 'form-control input-sm input_number', 'placeholder' => __('lang_v1.currency_exchange_rate'), 'id' => 'exchange_rate']) !!}
				</div>
			</div>
		</div>
	@endif
	@if(!empty($price_groups) && count($price_groups) > 1)
		<div class="col-md-4 col-sm-6">
			<div class="form-group">
				<div class="input-group">
					<span class="input-group-addon">
						<i class="fas fa-money-bill-alt"></i>
					</span>
					@php
						reset($price_groups);
						$selected_price_group = !empty($default_price_group_id) && array_key_exists($default_price_group_id, $price_groups) ? $default_price_group_id : null;
					@endphp
					{!! Form::hidden('hidden_price_group', key($price_groups), ['id' => 'hidden_price_group']) !!}
					{!! Form::select('price_group', $price_groups, $selected_price_group, ['class' => 'form-control select2', 'id' => 'price_group']) !!}
					<span class="input-group-addon">
						@show_tooltip(__('lang_v1.price_group_help_text'))
					</span> 
				</div>
			</div>
		</div>
	@else
		@php
			reset($price_groups);
		@endphp
		{!! Form::hidden('price_group', key($price_groups), ['id' => 'price_group']) !!}
	@endif
	@if(!empty($default_price_group_id))
		{!! Form::hidden('default_price_group', $default_price_group_id, ['id' => 'default_price_group']) !!}
	@endif

	@if(in_array('types_of_service', $enabled_modules) && !empty($types_of_service))
		<div class="col-md-4 col-sm-6">
			<div class="form-group">
				<div class="input-group">
					<span class="input-group-addon">
						<i class="fa fa-external-link-square-alt text-primary service_modal_btn"></i>
					</span>
					{!! Form::select('types_of_service_id', $types_of_service, null, ['class' => 'form-control', 'id' => 'types_of_service_id', 'style' => 'width: 100%;', 'placeholder' => __('lang_v1.select_types_of_service')]) !!}

					{!! Form::hidden('types_of_service_price_group', null, ['id' => 'types_of_service_price_group']) !!}

					<span class="input-group-addon">
						@show_tooltip(__('lang_v1.types_of_service_help'))
					</span> 
				</div>
				<small><p class="help-block hide" id="price_group_text">@lang('lang_v1.price_group'): <span></span></p></small>
			</div>
		</div>
		<div class="modal fade types_of_service_modal" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel"></div>
	@endif

	@if(!empty($pos_settings['show_invoice_scheme']))
		<div class="col-md-4 col-sm-6">
			<div class="form-group">
				{!! Form::select('invoice_scheme_id', $invoice_schemes, $default_invoice_schemes->id, ['class' => 'form-control', 'placeholder' => __('lang_v1.select_invoice_scheme')]) !!}
			</div>
		</div>
	@endif
	@if(in_array('subscription', $enabled_modules))
		<div class="col-md-4 col-sm-6">
			<label>
              {!! Form::checkbox('is_recurring', 1, false, ['class' => 'input-icheck', 'id' => 'is_recurring']) !!} @lang('lang_v1.subscribe')?
            </label><button type="button" data-toggle="modal" data-target="#recurringInvoiceModal" class="btn btn-link"><i class="fa fa-external-link-square-alt"></i></button>@show_tooltip(__('lang_v1.recurring_invoice_help'))
		</div>
	@endif
	<!-- Call restaurant module if defined -->
    @if(in_array('tables' ,$enabled_modules) || in_array('service_staff' ,$enabled_modules))
    	<div class="clearfix"></div>
    	<span id="restaurant_module_span">
      		<div class="col-md-3"></div>
    	</span>
    @endif
    
</div>
<!-- include module fields -->
@if(!empty($pos_module_data))
    @foreach($pos_module_data as $key => $value)
        @if(!empty($value['view_path']))
            @includeIf($value['view_path'], ['view_data' => $value['view_data']])
        @endif
    @endforeach
@endif
<div class="row">
	<div class="col-sm-12 pos_product_div">
		<input type="hidden" name="sell_price_tax" id="sell_price_tax" value="{{$business_details->sell_price_tax}}">

		<!-- Keeps count of product rows -->
		<input type="hidden" id="product_row_count" 
			value="0">
		@php
			$hide_tax = '';
			if( session()->get('business.enable_inline_tax') == 0){
				$hide_tax = 'hide';
			}
		@endphp
		<table class="table table-condensed table-bordered table-striped table-responsive" id="pos_table">
			<thead>
				<tr>
					<th class="tex-center @if(!empty($pos_settings['inline_service_staff'])) col-md-3 @else col-md-4 @endif">	
						@lang('sale.product') @show_tooltip(__('lang_v1.tooltip_sell_product_column'))
					</th>
					<th class="text-center col-md-3">
						@lang('sale.qty')
					</th>
					@if(!empty($pos_settings['inline_service_staff']))
						<th class="text-center col-md-2">
							@lang('restaurant.service_staff')
						</th>
					@endif
					<th class="text-center col-md-2 {{$hide_tax}}">
						@lang('lang_v1.purchase_price') 
					</th>
					<th class="text-center col-md-2 {{$hide_tax}}">
						@lang('sale.price_inc_tax')
					</th>
					<th class="text-center col-md-2">
						@lang('sale.subtotal')
					</th>
					<th class="text-center"><i class="fas fa-times" aria-hidden="true"></i></th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>
	</div>
</div>