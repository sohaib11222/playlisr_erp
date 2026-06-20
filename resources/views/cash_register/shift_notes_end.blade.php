@extends('layouts.app')
@section('title', 'End Shift')

@section('content')
<style>
	.sne-wrap {
		font-family: "Inter Tight", system-ui, sans-serif;
		color: #1F1B16; max-width: 680px; margin: 24px auto;
	}
	.sne-card {
		background: #FAF6EE; border: 1px solid #ECE3CF; border-radius: 14px;
		padding: 24px 26px; box-shadow: 0 4px 10px rgba(0,0,0,.04);
	}
	.sne-card h2 { margin: 0 0 4px; font-size: 20px; font-weight: 800; }
	.sne-sub { font-size: 13px; color: #8E8273; margin-bottom: 18px; }
	.sne-tag {
		display: inline-block; font-size: 11px; font-weight: 700;
		text-transform: uppercase; letter-spacing: .06em; color: #5A4410;
		background: #FFF2B3; border: 1px solid #E8CF68; border-radius: 7px;
		padding: 3px 9px; margin-bottom: 14px;
	}
	.sne-grid {
		display: grid; grid-template-columns: repeat(2, 1fr);
		gap: 12px; margin-bottom: 14px;
	}
	.sne-stat {
		background: #fff; border: 1px solid #ECE3CF; border-radius: 10px;
		padding: 12px 14px;
	}
	.sne-num { display: block; font-size: 22px; font-weight: 800; color: #1F1B16; }
	.sne-cap { display: block; font-size: 12px; color: #8E8273; margin-top: 2px; }
	.sne-cats-title { font-size: 12px; font-weight: 700; color: #5A5045; margin: 6px 0 8px; }
	.sne-cat {
		display: inline-block; font-size: 12px; color: #2F6B3E;
		background: #E6F4EA; border: 1px solid #A8D5B5; border-radius: 7px;
		padding: 3px 9px; margin: 0 6px 6px 0;
	}
	.sne-field { margin: 14px 0; }
	.sne-field label { display: block; font-size: 12px; font-weight: 700; color: #5A5045; margin-bottom: 6px; }
	.sne-times { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
	.sne-input, .sne-textarea {
		width: 100%; font-family: inherit; font-size: 14px; color: #1F1B16;
		background: #fff; border: 1px solid #ECE3CF; border-radius: 10px;
		padding: 10px 12px; box-sizing: border-box;
	}
	.sne-textarea { min-height: 76px; resize: vertical; }
	.sne-btn {
		display: inline-block; font-family: inherit; font-size: 15px; font-weight: 700;
		color: #1F1B16; background: #FFF2B3; border: 1px solid #E8CF68;
		border-radius: 10px; padding: 11px 22px; cursor: pointer; margin-top: 8px;
	}
	.sne-btn:hover { background: #FFE98A; }
	.sne-note {
		margin-top: 16px; padding: 12px 14px; background: #FFF2B3;
		border: 1px solid #E8CF68; border-radius: 10px;
		font-size: 12px; color: #5A4410; line-height: 1.5;
	}
	.sne-err { color: #8A3A2E; background: #FDE8E8; border-color: #E8A0A0; }
	.sne-ok { color: #2F6B3E; background: #E6F4EA; border-color: #A8D5B5; }
</style>

<div class="sne-wrap">
	<div class="sne-card">
		<h2>End Shift — {{ $employee }}</h2>
		<div class="sne-sub">Your numbers for today are filled in below. Add your hours and any notes, then post to #shift-notes.</div>

		@if(session('status'))
			<div class="sne-note {{ session('status')['success'] ? 'sne-ok' : 'sne-err' }}">
				{{ session('status')['msg'] }}
			</div>
		@endif

		@if(!$webhook_set)
			<div class="sne-note sne-err">
				Heads up: the #shift-notes Slack webhook isn't set yet, so posts won't go through. An admin can add it under Shift Notes settings — your shift is still recorded in the meantime.
			</div>
		@endif

		@if($error)
			<div class="sne-note sne-err">{{ $error }}</div>
		@elseif(!empty($shift_summary))
			<span class="sne-tag">Today · auto-filled</span>
			<div class="sne-grid">
				@if(!empty($shift_summary['packages_picked_count']))
				<div class="sne-stat">
					<span class="sne-num">{{ (int) $shift_summary['packages_picked_count'] }}</span>
					<span class="sne-cap">Picked</span>
				</div>
				@endif
				@if(!empty($shift_summary['packages_shipped_count']))
				<div class="sne-stat">
					<span class="sne-num">{{ (int) $shift_summary['packages_shipped_count'] }}</span>
					<span class="sne-cap">Shipped</span>
				</div>
				@endif
				@if(!empty($shift_summary['labels_printed_count']))
				<div class="sne-stat">
					<span class="sne-num">{{ (int) $shift_summary['labels_printed_count'] }}</span>
					<span class="sne-cap">Labels printed (items put out){{ $shift_summary['labels_value'] > 0 ? ' · $' . number_format((float) $shift_summary['labels_value'], 2) . ' value' : '' }}</span>
				</div>
				@endif
				@if(!empty($shift_summary['mass_add_count']))
				<div class="sne-stat">
					<span class="sne-num">{{ (int) $shift_summary['mass_add_count'] }}</span>
					<span class="sne-cap">Items listed (mass add)</span>
				</div>
				@endif
				@if(!empty($shift_summary['purchase_add_count']))
				<div class="sne-stat">
					<span class="sne-num">{{ (int) $shift_summary['purchase_add_count'] }}</span>
					<span class="sne-cap">Items added (purchase form)</span>
				</div>
				@endif
				@if((float) ($shift_summary['sales'] ?? 0) > 0)
				<div class="sne-stat">
					<span class="sne-num">${{ number_format((float) $shift_summary['sales'], 2) }}</span>
					<span class="sne-cap">Sales · {{ (int) $shift_summary['transactions_count'] }} transactions</span>
				</div>
				@endif
			</div>
			@if(!empty($shift_summary['labels_categories']))
			<div class="sne-cats-title">Categories labeled</div>
			@foreach($shift_summary['labels_categories'] as $cat => $cnt)
				<span class="sne-cat">{{ $cat }} <b>{{ (int) $cnt }}</b></span>
			@endforeach
			@endif
		@else
			<div class="sne-note">No activity recorded for today yet — you can still add hours and a note below.</div>
		@endif

		<form method="POST" action="{{ url('/shift-notes/end') }}">
			@csrf
			<div class="sne-field">
				<label>Hours (optional)</label>
				<div class="sne-times">
					<input class="sne-input" type="time" name="shift_start" placeholder="Start">
					<input class="sne-input" type="time" name="shift_end" placeholder="End">
				</div>
			</div>
			<div class="sne-field">
				<label>Notes (optional)</label>
				<textarea class="sne-textarea" name="note" placeholder="Anything else from your shift"></textarea>
			</div>
			<button class="sne-btn" type="submit">Post to #shift-notes</button>
		</form>
	</div>
</div>
@endsection
