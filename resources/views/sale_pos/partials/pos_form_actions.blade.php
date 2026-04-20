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

			<style>
				/* Prioritized payment actions — Cash + Card dominate, rest tucked away.
				   Cap to max-width so they don't stretch absurdly on wide screens. */
				.pos-payment-primary-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; margin-top: 6px; justify-content: center; }
				.pos-payment-primary-row .btn-pay-primary {
					flex: 0 1 280px; min-width: 200px; max-width: 320px;
					min-height: 60px;
					font-size: 18px; font-weight: 800;
					border-radius: 10px;
					text-transform: uppercase; letter-spacing: 0.6px;
					display: inline-flex; align-items: center; justify-content: center; gap: 10px;
					box-shadow: 0 2px 6px rgba(0,0,0,0.08);
				}
				.pos-payment-primary-row .btn-pay-primary i { font-size: 22px; }
				/* Cash stays green (money = green is universal cashier expectation).
				   Card switched from navy to Nivessa brown for brand consistency. */
				.btn-pay-cash { background: #16a34a !important; color: #fff !important; border: none !important; }
				.btn-pay-cash:hover { background: #15803d !important; color: #fff !important; }
				.btn-pay-card { background: #3a2a1f !important; color: #fff !important; border: none !important; }
				.btn-pay-card:hover { background: #2b1e16 !important; color: #fff !important; }

				/* "More payment options" menu — Credit Sale + Multi-Pay tucked here */
				.pos-payment-more .dropdown-menu { min-width: 220px; padding: 4px 0; }
				.pos-payment-more .dropdown-menu a { padding: 10px 16px; font-weight: 600; }

				/* Soften cancel to a subtle text link — avoid accidental clicks mid-sale */
				#pos-cancel.pos-cancel-link {
					background: transparent !important;
					border: none !important;
					color: #9ca3af !important;
					padding: 6px 10px !important;
					font-weight: 500;
					font-size: 12px;
					text-transform: uppercase; letter-spacing: 0.5px;
					box-shadow: none !important;
				}
				#pos-cancel.pos-cancel-link:hover { color: #dc2626 !important; text-decoration: underline; }
			</style>

			<input type="hidden" name="is_credit_sale" value="0" id="is_credit_sale">

			<div class="pos-payment-primary-row">
				<button type="button"
					class="btn btn-pay-primary btn-pay-cash pos-express-finalize @if($pos_settings['disable_express_checkout'] != 0 || !array_key_exists('cash', $payment_types)) hide @endif"
					data-pay_method="cash"
					title="@lang('tooltip.express_checkout')">
					<i class="fas fa-money-bill-alt"></i> Cash
				</button>
				<button type="button"
					class="btn btn-pay-primary btn-pay-card pos-express-finalize @if(!array_key_exists('card', $payment_types)) hide @endif"
					data-pay_method="card"
					title="@lang('lang_v1.tooltip_express_checkout_card')">
					<i class="fas fa-credit-card"></i> Card
				</button>

				<div class="dropup pos-payment-more">
					<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" style="min-height:60px; padding:10px 16px; font-weight:700;">
						<i class="fa fa-ellipsis-h"></i> More <span class="caret"></span>
					</button>
					<ul class="dropdown-menu dropdown-menu-right" style="padding:4px;">
						{{-- Buttons (not links) so the original pos.js click handlers fire --}}
						<li style="list-style:none;">
							<button type="button" class="btn btn-link" id="pos-finalize" title="@lang('lang_v1.tooltip_checkout_multi_pay')" style="display:block; width:100%; text-align:left; padding:10px 16px; font-weight:600; text-decoration:none;">
								<i class="fas fa-money-check-alt text-primary"></i> @lang('lang_v1.checkout_multi_pay')
							</button>
						</li>
						@if(empty($pos_settings['disable_credit_sale_button']))
						<li style="list-style:none;">
							<button type="button" class="btn btn-link pos-express-finalize" data-pay_method="credit_sale" title="@lang('lang_v1.tooltip_credit_sale')" style="display:block; width:100%; text-align:left; padding:10px 16px; font-weight:600; text-decoration:none;">
								<i class="fas fa-check text-purple"></i> @lang('lang_v1.credit_sale')
							</button>
						</li>
						@endif
					</ul>
				</div>
			</div>

			{{-- Keyboard shortcut hint bar. The shortcuts existed all along but lived
				 behind a hover popover in the header, so nobody saw them. Surfacing the
				 most-used ones here teaches cashiers without cluttering the screen. --}}
			<div class="pos-shortcut-hints" style="margin-top:10px; padding:8px 12px; background:#faf0df; border:1px solid #ecd9b5; border-radius:8px; font-size:12px; color:#5c3c10; display:flex; flex-wrap:wrap; gap:6px 14px; justify-content:center; align-items:center;">
				<span style="font-weight:700; letter-spacing:.5px; text-transform:uppercase; font-size:10px; opacity:.8;">Shortcuts</span>
				<span><kbd style="background:#fff; color:#5c3c10; border:1px solid #d6c29a; border-radius:4px; padding:1px 6px; font-family:inherit; font-weight:700;">Shift+P</kbd> Pay</span>
				<span><kbd style="background:#fff; color:#5c3c10; border:1px solid #d6c29a; border-radius:4px; padding:1px 6px; font-family:inherit; font-weight:700;">Shift+E</kbd> Express</span>
				<span><kbd style="background:#fff; color:#5c3c10; border:1px solid #d6c29a; border-radius:4px; padding:1px 6px; font-family:inherit; font-weight:700;">Shift+I</kbd> Discount</span>
				<span><kbd style="background:#fff; color:#5c3c10; border:1px solid #d6c29a; border-radius:4px; padding:1px 6px; font-family:inherit; font-weight:700;">Shift+T</kbd> Tax</span>
				<span><kbd style="background:#fff; color:#5c3c10; border:1px solid #d6c29a; border-radius:4px; padding:1px 6px; font-family:inherit; font-weight:700;">F2</kbd> Qty</span>
				<span><kbd style="background:#fff; color:#5c3c10; border:1px solid #d6c29a; border-radius:4px; padding:1px 6px; font-family:inherit; font-weight:700;">F4</kbd> New item</span>
				<span><kbd style="background:#fff; color:#5c3c10; border:1px solid #d6c29a; border-radius:4px; padding:1px 6px; font-family:inherit; font-weight:700;">Shift+C</kbd> Cancel</span>
			</div>

			<div style="text-align:center; margin-top:8px;">
				@if(empty($edit))
					<button type="button" class="pos-cancel-link" id="pos-cancel"><i class="fas fa-times"></i> Cancel sale</button>
				@else
					<button type="button" class="pos-cancel-link hide" id="pos-delete"><i class="fas fa-trash-alt"></i> @lang('messages.delete')</button>
				@endif
			</div>

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