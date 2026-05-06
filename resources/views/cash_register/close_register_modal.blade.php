{{-- ===========================================================
     Close Register modal — redesigned 2026-04-20 per Sarah's ask.

     Goals:
       1. What the cashier has to type ("closing balance") is HERO at
          the top of the modal, not buried below a 20-row breakdown.
       2. "Total cheques" field removed — Nivessa doesn't take cheques.
          Still send total_cheques=0 as a hidden input so the backend
          (which does $request->only([..., 'total_cheques', ...])) gets
          a value and the DB column stays consistent.
       3. Reference data (payment breakdown + product detail) is still
          there but collapsed below the action, so the primary flow is
          instantly obvious: count the cash, type it, hit Close.
       4. Styling inherits from body.pos-v2 (Inter Tight, cream surface,
          pastel-yellow accent) since the modal is rendered inside the
          POS page. No separate font imports needed.
     ============================================================ --}}

<style>
	.cr-v2 {
		font-family: "Inter Tight", system-ui, sans-serif;
		color: #1F1B16;
	}
	.cr-v2 .modal-header {
		background: #1F1B16; color: #FAF6EE; border: none;
		padding: 16px 20px; border-radius: 10px 10px 0 0;
	}
	.cr-v2 .modal-header .modal-title {
		font-size: 18px; font-weight: 800; letter-spacing: .02em;
	}
	.cr-v2 .modal-header .cr-shift {
		font-size: 12px; font-weight: 400; opacity: .75; margin-top: 2px; display: block;
	}
	.cr-v2 .modal-header .close {
		color: #FAF6EE; opacity: .7; text-shadow: none; font-size: 22px;
	}
	.cr-v2 .modal-header .close:hover { opacity: 1; }

	.cr-v2 .modal-body { padding: 22px 24px; background: #FAF6EE; }
	.cr-v2 .modal-footer { background: #FAF6EE; border-top: 1px solid #ECE3CF; padding: 14px 22px; border-radius: 0 0 10px 10px; }

	/* Hero block — the closing-cash input dominates the modal. */
	.cr-hero {
		background: #FFF2B3; border: 2px solid #E8CF68; border-radius: 14px;
		padding: 22px 24px 20px; position: relative;
		box-shadow: 0 0 0 4px rgba(232, 207, 104, .25), 0 4px 10px rgba(0,0,0,.06);
	}
	.cr-hero::before {
		content: "STEP 1 · COUNT THE DRAWER";
		position: absolute; top: -10px; left: 16px;
		background: #1F1B16; color: #FFF2B3;
		font-size: 10px; font-weight: 800;
		letter-spacing: .16em; padding: 4px 12px; border-radius: 999px;
	}
	.cr-hero-label {
		font-size: 14px; font-weight: 700; color: #5A4410;
		margin-bottom: 4px; letter-spacing: .01em;
	}
	.cr-hero-hint {
		font-size: 12px; color: #5A4410; opacity: .75;
		margin-bottom: 14px;
	}
	.cr-hero-inputwrap {
		display: flex; align-items: center; gap: 12px;
		background: #fff; border: 2px solid #E8CF68; border-radius: 12px;
		padding: 10px 16px;
	}
	.cr-hero-currency {
		font-size: 32px; font-weight: 800; color: #5A4410; line-height: 1;
	}
	.cr-hero-input {
		flex: 1; border: none; outline: none; background: transparent;
		font-family: inherit; font-size: 36px; font-weight: 800;
		color: #1F1B16; padding: 0; letter-spacing: -.02em;
		font-variant-numeric: tabular-nums;
	}
	.cr-hero-input::placeholder { color: #c9b670; }

	/* Secondary: card slips (kept, smaller). */
	.cr-card-slips {
		margin-top: 18px; display: flex; align-items: center; gap: 14px;
	}
	.cr-card-slips label {
		font-size: 13px; font-weight: 600; color: #5A5045; margin: 0;
	}
	.cr-card-slips input {
		width: 100px; padding: 8px 12px;
		border: 1px solid #DFD2B3; border-radius: 8px;
		font-family: inherit; font-size: 15px; font-weight: 600;
		background: #fff;
	}
	.cr-card-slips .cr-sub {
		font-size: 11px; color: #8E8273;
	}

	/* Optional closing note */
	.cr-note {
		margin-top: 18px;
	}
	.cr-note label {
		font-size: 12px; font-weight: 600; color: #5A5045;
		letter-spacing: .04em; text-transform: uppercase;
	}
	.cr-note textarea {
		width: 100%; padding: 10px 12px;
		border: 1px solid #DFD2B3; border-radius: 8px;
		font-family: inherit; font-size: 13px; background: #fff;
		min-height: 60px;
	}

	/* Collapsible reference block — payment breakdown + products --*/
	.cr-ref {
		margin-top: 22px; border-top: 1px dashed #DFD2B3; padding-top: 16px;
	}
	.cr-ref-toggle {
		background: none; border: none; padding: 4px 0;
		font-family: inherit; font-size: 13px; font-weight: 600;
		color: #5A5045; cursor: pointer;
		display: flex; align-items: center; gap: 6px;
	}
	.cr-ref-toggle::before {
		content: "▸"; font-size: 12px; transition: transform .15s;
	}
	.cr-ref-open .cr-ref-toggle::before {
		transform: rotate(90deg);
	}
	.cr-ref-body { display: none; margin-top: 12px; font-size: 12px; }
	.cr-ref-open .cr-ref-body { display: block; }
	.cr-ref-body table {
		background: #fff; border-radius: 8px; overflow: hidden;
		border: 1px solid #ECE3CF;
	}
	.cr-ref-body .box-header { display: none; }
	.cr-ref-body .box { box-shadow: none; border: none; margin: 0; }

	/* Submit button — big, obvious, dark. */
	.cr-v2 .cr-submit {
		padding: 12px 22px; background: #1F1B16; color: #FAF6EE;
		border: none; border-radius: 10px;
		font-family: inherit; font-size: 15px; font-weight: 700;
		letter-spacing: .02em; cursor: pointer;
		display: inline-flex; align-items: center; gap: 8px;
	}
	.cr-v2 .cr-submit:hover { background: #3a2e22; }
	.cr-v2 .cr-cancel {
		padding: 12px 18px; background: transparent; color: #8E8273;
		border: 1px solid #DFD2B3; border-radius: 10px;
		font-family: inherit; font-size: 14px; font-weight: 600; cursor: pointer;
	}
	.cr-v2 .cr-cancel:hover { color: #1F1B16; border-color: #8E8273; }

	/* Denominations — kept but tucked into the reference block */
	.cr-denom {
		margin-top: 14px; padding: 12px;
		background: #fff; border: 1px solid #ECE3CF; border-radius: 8px;
	}
	.cr-denom h4 {
		margin: 0 0 10px; font-size: 12px; font-weight: 700;
		color: #5A5045; text-transform: uppercase; letter-spacing: .08em;
	}
</style>

<div class="modal-dialog modal-lg cr-v2" role="document">
	<div class="modal-content" style="border-radius: 10px; border: none; box-shadow: 0 20px 50px rgba(0,0,0,.3);">
		{!! Form::open(['url' => action('CashRegisterController@postCloseRegister'), 'method' => 'post' ]) !!}
		{!! Form::hidden('user_id', $register_details->user_id); !!}
		{{-- Cheque payment form field kept as a hidden 0 because Nivessa doesn't
			 take cheques, but $request->only() in postCloseRegister expects the
			 key. Removing the input entirely would save a payload byte but also
			 creates risk if the backend ever starts validating presence. --}}
		{!! Form::hidden('total_cheques', 0); !!}

		<div class="modal-header">
			<button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
			<h3 class="modal-title">Close Register</h3>
			<small class="cr-shift">
				Shift: {{ \Carbon::createFromFormat('Y-m-d H:i:s', $register_details->open_time)->format('M j, g:i A') }}
				&nbsp;→&nbsp;
				{{ \Carbon::now()->format('M j, g:i A') }}
			</small>
		</div>

		<div class="modal-body">

			{{-- HERO: count the drawer --}}
			<div class="cr-hero">
				<div class="cr-hero-label">Closing balance — total cash in the drawer</div>
				<div class="cr-hero-hint">
					Count every bill &amp; coin in the register right now and enter the total here. This is what gets reconciled against ERP cash sales.
				</div>
				<div class="cr-hero-inputwrap">
					<span class="cr-hero-currency">$</span>
					{!! Form::text('closing_amount',
						@num_format($register_details->cash_in_hand + $register_details->total_cash - $register_details->total_cash_refund - $register_details->total_cash_expense),
						['class' => 'cr-hero-input input_number', 'required', 'placeholder' => '0.00', 'autofocus', 'data-decimal' => '1']) !!}
				</div>

				<div class="cr-card-slips"
					data-location-id="{{ $register_details->location_id }}"
					data-shift-start="{{ $register_details->open_time }}"
					data-shift-end="{{ \Carbon::now()->toDateTimeString() }}">
					<label for="total_card_slips">Total card slips:</label>
					{!! Form::number('total_card_slips', $register_details->total_card_slips, ['class' => '', 'id' => 'total_card_slips', 'min' => 0, 'placeholder' => '0']); !!}
					<span class="cr-sub" id="cr-card-slips-sub">count of swipes, not dollars</span>
				</div>
				<script>
				/* Clover auto-fill for 'Total card slips'. Fires once when the
				   close-register modal renders: calls /clover/shift-summary
				   with the register's location + shift window, drops the
				   returned count into the input, and shows a small badge so
				   the cashier knows the number came from Clover (editable
				   if they want to override). Silently falls back to manual
				   if Clover is down or not configured for this location. */
				(function () {
					function onReady(fn) {
						if (typeof jQuery === 'undefined') { setTimeout(function () { onReady(fn); }, 50); return; }
						jQuery(fn);
					}
					onReady(function ($) {
						var $wrap = $('.cr-card-slips').last();
						if (!$wrap.length || $wrap.data('clover-fetched')) return;
						$wrap.data('clover-fetched', true);

						var locationId = $wrap.data('location-id');
						var start = $wrap.data('shift-start');
						var end   = $wrap.data('shift-end');
						if (!locationId || !start || !end) return;

						var $input = $wrap.find('#total_card_slips');
						var $sub = $wrap.find('#cr-card-slips-sub');
						var originalSub = $sub.text();
						$sub.text('Fetching from Clover…').css('color', '#8E8273');

						$.get('/clover/shift-summary', {
							location_id: locationId,
							start: start,
							end: end
						}).done(function (r) {
							if (r && r.success) {
								$input.val(r.card_slip_count || 0);
								var total = '$' + parseFloat(r.card_total || 0).toFixed(2);
								$sub.html('✓ From Clover · ' + total + ' in credit-card payments <span style="opacity:.7;">(editable)</span>')
									.css('color', '#2F6B3E');
							} else {
								$sub.text(originalSub + ' · Clover unavailable, enter manually')
									.css('color', '#8A3A2E');
							}
						}).fail(function () {
							$sub.text(originalSub + ' · Clover unavailable, enter manually')
								.css('color', '#8A3A2E');
						});

						// If the cashier edits the field themselves, stop
						// claiming the number came from Clover.
						$input.on('input change', function () {
							$sub.text(originalSub).css('color', '');
						});
					});
				})();
				</script>
			</div>

			{{-- Optional closing note --}}
			<div class="cr-note">
				{!! Form::label('closing_note', 'Closing note (optional)') !!}
				{!! Form::textarea('closing_note', null, ['class' => '', 'placeholder' => 'Anything unusual about today?', 'rows' => 2 ]); !!}
			</div>

			{{-- Clover keying-error feedback (Sarah 2026-05-06): show
			     during close so the cashier sees their typos before
			     leaving the shift and can self-correct next time.
			     Server-side $keying_errors is an array of pairs where
			     this cashier's Clover swipe drifted >5¢ from the
			     matching ERP sale; empty array = no feedback. --}}
			@if(!empty($keying_errors))
				<div style="background:#fffbeb; border:1px solid #fde68a; border-radius:10px; padding:14px 16px; margin-top:14px;">
					<div style="font-size:12px; font-weight:800; color:#92400e; text-transform:uppercase; letter-spacing:.04em; margin-bottom:6px;">
						⚠ {{ count($keying_errors) }} Clover amount{{ count($keying_errors) === 1 ? '' : 's' }} didn't match the ERP sale
					</div>
					<div style="font-size:12px; color:#78350f; margin-bottom:8px;">
						Type the same amount on Clover that the POS shows. Mismatches by even a few cents add up to drawer variance at end of shift.
					</div>
					<div style="font-size:12px; font-variant-numeric: tabular-nums;">
						@foreach($keying_errors as $err)
							<div style="display:flex; justify-content:space-between; padding:3px 0; border-top:1px solid #fef3c7;">
								<span style="color:#92400e;">{{ \Carbon\Carbon::parse($err['ts'])->setTimezone(config('app.timezone'))->format('g:i a') }}</span>
								<span>
									Clover <strong>${{ number_format($err['clover_amount'], 2) }}</strong>
									vs POS <strong>${{ number_format($err['erp_amount'], 2) }}</strong>
									<span style="color:{{ $err['diff'] < 0 ? '#b91c1c' : '#92400e' }}; font-weight:700; margin-left:6px;">
										{{ $err['diff'] < 0 ? 'under' : 'over' }} ${{ number_format(abs($err['diff']), 2) }}
									</span>
								</span>
							</div>
						@endforeach
					</div>
				</div>
			@endif

			{{-- Reference breakdown (collapsed) --}}
			<div class="cr-ref" id="cr-ref">
				<button type="button" class="cr-ref-toggle" onclick="document.getElementById('cr-ref').classList.toggle('cr-ref-open')">
					Show payment breakdown &amp; products sold
				</button>
				<div class="cr-ref-body">
					@include('cash_register.payment_details')

					@if(!empty($pos_settings['cash_denominations']))
					<div class="cr-denom">
						<h4>Cash denominations (optional)</h4>
						<table class="table table-slim" style="margin:0;">
							<thead>
								<tr>
									<th width="20%" class="text-right">@lang('lang_v1.denomination')</th>
									<th width="20%">&nbsp;</th>
									<th width="20%" class="text-center">@lang('lang_v1.count')</th>
									<th width="20%">&nbsp;</th>
									<th width="20%" class="text-left">@lang('sale.subtotal')</th>
								</tr>
							</thead>
							<tbody>
								@foreach(explode(',', $pos_settings['cash_denominations']) as $dnm)
								<tr>
									<td class="text-right">{{$dnm}}</td>
									<td class="text-center">X</td>
									<td>{!! Form::number("denominations[$dnm]", null, ['class' => 'form-control cash_denomination input-sm', 'min' => 0, 'data-denomination' => $dnm, 'style' => 'width: 100px; margin:auto;' ]); !!}</td>
									<td class="text-center">=</td>
									<td class="text-left"><span class="denomination_subtotal">0</span></td>
								</tr>
								@endforeach
							</tbody>
							<tfoot>
								<tr>
									<th colspan="4" class="text-center">@lang('sale.total')</th>
									<td><span class="denomination_total">0</span></td>
								</tr>
							</tfoot>
						</table>
					</div>
					@endif

					<div style="margin-top:10px; font-size:11px; color:#8E8273;">
						User: {{ $register_details->user_name }} · Location: {{ $register_details->location_name }}
					</div>
				</div>
			</div>

		</div>

		<div class="modal-footer">
			<button type="button" class="cr-cancel" data-dismiss="modal">Cancel</button>
			<button type="submit" class="cr-submit">
				<i class="fa fa-lock"></i> Close Register
			</button>
		</div>
		{!! Form::close() !!}
	</div>
</div>
