@extends('layouts.app')
@section('title', 'Shift Notes — Preview')

@section('content')
<style>
	.snp-wrap {
		font-family: "Inter Tight", system-ui, sans-serif;
		color: #1F1B16; max-width: 680px; margin: 24px auto;
	}
	.snp-card {
		background: #FAF6EE; border: 1px solid #ECE3CF; border-radius: 14px;
		padding: 24px 26px; box-shadow: 0 4px 10px rgba(0,0,0,.04);
	}
	.snp-card h2 { margin: 0 0 4px; font-size: 20px; font-weight: 800; }
	.snp-sub { font-size: 13px; color: #8E8273; margin-bottom: 18px; }
	.snp-tag {
		display: inline-block; font-size: 11px; font-weight: 700;
		text-transform: uppercase; letter-spacing: .06em; color: #5A4410;
		background: #FFF2B3; border: 1px solid #E8CF68; border-radius: 7px;
		padding: 3px 9px; margin-bottom: 14px;
	}
	.snp-grid {
		display: grid; grid-template-columns: repeat(2, 1fr);
		gap: 12px; margin-bottom: 14px;
	}
	.snp-stat {
		background: #fff; border: 1px solid #ECE3CF; border-radius: 10px;
		padding: 12px 14px;
	}
	.snp-num { display: block; font-size: 22px; font-weight: 800; color: #1F1B16; }
	.snp-cap { display: block; font-size: 12px; color: #8E8273; margin-top: 2px; }
	.snp-cats-title { font-size: 12px; font-weight: 700; color: #5A5045; margin: 6px 0 8px; }
	.snp-cat {
		display: inline-block; font-size: 12px; color: #2F6B3E;
		background: #E6F4EA; border: 1px solid #A8D5B5; border-radius: 7px;
		padding: 3px 9px; margin: 0 6px 6px 0;
	}
	.snp-note {
		margin-top: 16px; padding: 12px 14px; background: #FFF2B3;
		border: 1px solid #E8CF68; border-radius: 10px;
		font-size: 12px; color: #5A4410; line-height: 1.5;
	}
	.snp-err { color: #8A3A2E; background: #FDE8E8; border-color: #E8A0A0; }
</style>

<div class="snp-wrap">
	<div class="snp-card">
		<h2>Shift Notes — Preview</h2>
		<div class="snp-sub">This is exactly what a cashier sees auto-filled when they close the register. Preview only — nothing is saved or posted to Slack from this page.</div>

		@if($error)
			<div class="snp-note snp-err">{{ $error }}</div>
		@elseif(!empty($shift_summary))
			<span class="snp-tag">Shift summary · auto-filled</span>
			<div class="snp-grid">
				<div class="snp-stat">
					<span class="snp-num">${{ number_format((float) $shift_summary['sales'], 2) }}</span>
					<span class="snp-cap">Sales this shift</span>
				</div>
				<div class="snp-stat">
					<span class="snp-num">{{ (int) $shift_summary['transactions_count'] }}</span>
					<span class="snp-cap">Transactions rung{{ (int) ($shift_summary['transactions_count'] ?? 0) > 0 ? ' · $' . number_format((float) $shift_summary['sales'] / (int) $shift_summary['transactions_count'], 2) . '/txn avg' : '' }}</span>
				</div>
					@if(!empty($shift_summary['customer_accounts_created']))
					<div class="snp-stat">
						<span class="snp-num">{{ (int) $shift_summary['customer_accounts_created'] }}</span>
						<span class="snp-cap">New customer accounts</span>
					</div>
					@endif
					@if(!empty($shift_summary['buys_count']))
					<div class="snp-stat">
						<span class="snp-num">{{ (int) $shift_summary['buys_count'] }}</span>
						<span class="snp-cap">Bought in · ${{ number_format((float) ($shift_summary['buys_amount'] ?? 0), 2) }} paid · ${{ number_format((float) ($shift_summary['buys_amount'] ?? 0) / (int) $shift_summary['buys_count'], 2) }}/purchase avg</span>
					</div>
					@endif
				<div class="snp-stat">
					<span class="snp-num">{{ (int) $shift_summary['labels_printed_count'] }}</span>
					<span class="snp-cap">Labels printed (items put out){{ $shift_summary['labels_value'] > 0 ? ' · $' . number_format((float) $shift_summary['labels_value'], 2) . ' value' : '' }}</span>
				</div>
				<div class="snp-stat">
					<span class="snp-num">{{ (int) $shift_summary['mass_add_count'] }}</span>
					<span class="snp-cap">Items added (mass add)</span>
				</div>
				<div class="snp-stat">
					<span class="snp-num">{{ (int) $shift_summary['purchase_add_count'] }}</span>
					<span class="snp-cap">Items added (purchase form)</span>
				</div>
				@if(!empty($shift_summary['packages_picked_count']))
				<div class="snp-stat">
					<span class="snp-num">{{ (int) $shift_summary['packages_picked_count'] }}</span>
					<span class="snp-cap">Packages picked</span>
				</div>
				@endif
				@if(!empty($shift_summary['packages_shipped_count']))
				<div class="snp-stat">
					<span class="snp-num">{{ (int) $shift_summary['packages_shipped_count'] }}</span>
					<span class="snp-cap">Packages shipped</span>
				</div>
				@endif
			</div>
			@if(!empty($shift_summary['labels_categories']))
			<div class="snp-cats-title">Categories labeled</div>
			@foreach($shift_summary['labels_categories'] as $cat => $cnt)
				<span class="snp-cat">{{ $cat }} <b>{{ (int) $cnt }}</b></span>
			@endforeach
			@endif
			<div class="snp-note">
				When this goes live, the cashier sees these numbers (read-only) at close, plus an optional box for highlights/lowlights. The note is posted to #shift-notes automatically.
			</div>
		@else
			<div class="snp-note">No shift data to preview yet.</div>
		@endif
	</div>
</div>
@endsection
