@php
	$is_mobile = isMobile();
@endphp
<div class="row">
	<div class="pos-form-actions">
		<div class="col-md-12">
			{{-- Mark as Whatnot Transaction checkbox moved to pos_form_totals (above Bag Fee) per Sarah's request. --}}
			@if($is_mobile)
				<div class="col-md-12 text-right">
					<b>@lang('sale.total_payable'):</b>
					<input type="hidden" name="final_total" 
												id="final_total_input" value=0>
					<span id="total_payable" class="text-success lead text-bold text-right">0</span>
				</div>
			@endif
			{{-- Draft / Quotation / Suspend buttons hidden per Sarah's request (2026-04-19).
				Kept commented for easy restoration if ever needed.
			<button type="button" class="@if($is_mobile) col-xs-6 @endif btn bg-info text-white btn-default btn-flat @if($pos_settings['disable_draft'] != 0) hide @endif" id="pos-draft"><i class="fas fa-edit"></i> @lang('sale.draft')</button>
			<button type="button" class="btn btn-default bg-yellow btn-flat @if($is_mobile) col-xs-6 @endif" id="pos-quotation"><i class="fas fa-edit"></i> @lang('lang_v1.quotation')</button>

			@if(empty($pos_settings['disable_suspend']))
				<button type="button"
				class="@if($is_mobile) col-xs-6 @endif btn bg-red btn-default btn-flat no-print pos-express-finalize"
				data-pay_method="suspend"
				title="@lang('lang_v1.tooltip_suspend')" >
				<i class="fas fa-pause" aria-hidden="true"></i>
				@lang('lang_v1.suspend')
				</button>
			@endif
			--}}

			{{-- Cash / Card / More buttons + Cancel Sale moved into the receipt
				 card in pos_form_totals.blade.php (2026-04-21) so the whole sale
				 column fits without scrolling. The hidden #is_credit_sale input
				 and the edit-mode delete button stay here. --}}
			<input type="hidden" name="is_credit_sale" value="0" id="is_credit_sale">

			@if(!empty($edit))
			<div style="text-align:center; margin-top:8px;">
				<button type="button" class="btn btn-default hide" id="pos-delete" style="background:transparent;border:0;color:#9ca3af;font-weight:500;font-size:12px;text-transform:uppercase;letter-spacing:.5px;"><i class="fas fa-trash-alt"></i> @lang('messages.delete')</button>
			</div>
			@endif

			{{-- Hidden shim: the bottom-left "Total Payable" chip was visually redundant
				 with the big TOTAL (WITH TAX) in the totals row above, but lots of JS
				 still reads/writes #total_payable and #final_total_input. Keep the
				 elements present but invisible. --}}
			<div class="bg-navy pos-total text-white" style="display:none;">
				<span class="text">@lang('sale.total_payable')</span>
				<input type="hidden" name="final_total" id="final_total_input" value=0>
				<span id="total_payable" class="number">0</span>
			</div>

			@if(!isset($pos_settings['hide_recent_trans']) || $pos_settings['hide_recent_trans'] == 0)
			<button type="button" class="pull-right btn btn-primary btn-flat @if($is_mobile) col-xs-6 @endif" data-toggle="modal" data-target="#recent_transactions_modal" id="recent-transactions"> <i class="fas fa-clock"></i> @lang('lang_v1.recent_transactions')</button>
			@endif

			<a href="{{ route('pos.exportManualProducts') }}" class="pull-right btn btn-success btn-flat @if($is_mobile) col-xs-6 @endif" style="margin-right: 10px;" title="Export manually added products from POS">
				<i class="fas fa-file-excel"></i> Export Manual Products
			</a>
			
			
		</div>
	</div>
</div>
@if(isset($transaction))
	@include('sale_pos.partials.edit_discount_modal', ['sales_discount' => $transaction->discount_amount, 'discount_type' => $transaction->discount_type, 'discount_reason' => $transaction->discount_reason ?? '', 'rp_redeemed' => $transaction->rp_redeemed, 'rp_redeemed_amount' => $transaction->rp_redeemed_amount, 'max_available' => !empty($redeem_details['points']) ? $redeem_details['points'] : 0, 'transaction' => $transaction, 'discount_presets' => $discount_presets ?? []])
@else
	@include('sale_pos.partials.edit_discount_modal', ['sales_discount' => $business_details->default_sales_discount, 'discount_type' => 'percentage', 'discount_reason' => '', 'rp_redeemed' => 0, 'rp_redeemed_amount' => 0, 'max_available' => 0, 'discount_presets' => $discount_presets ?? []])
@endif

@if(isset($transaction))
	@include('sale_pos.partials.edit_order_tax_modal', ['selected_tax' => $transaction->tax_id])
@else
	@include('sale_pos.partials.edit_order_tax_modal', ['selected_tax' => $business_details->default_sales_tax])
@endif

@include('sale_pos.partials.edit_shipping_modal')