@extends('layouts.app')
@section('title', 'Shift Notes — Slack')

@section('content')
<style>
	.sn-wrap {
		font-family: "Inter Tight", system-ui, sans-serif;
		color: #1F1B16; max-width: 640px; margin: 24px auto;
	}
	.sn-card {
		background: #FAF6EE; border: 1px solid #ECE3CF; border-radius: 14px;
		padding: 24px 26px; box-shadow: 0 4px 10px rgba(0,0,0,.04);
	}
	.sn-card h2 { margin: 0 0 4px; font-size: 20px; font-weight: 800; }
	.sn-sub { font-size: 13px; color: #8E8273; margin-bottom: 18px; }
	.sn-label {
		font-size: 12px; font-weight: 700; color: #5A5045;
		text-transform: uppercase; letter-spacing: .05em; margin-bottom: 6px;
	}
	.sn-input {
		width: 100%; padding: 11px 13px; font-family: inherit; font-size: 14px;
		border: 1px solid #DFD2B3; border-radius: 9px; background: #fff;
	}
	.sn-current {
		margin: 10px 0 0; font-size: 13px; color: #2F6B3E; font-weight: 600;
	}
	.sn-empty { color: #8A3A2E; }
	.sn-help { font-size: 12px; color: #8E8273; margin: 12px 0 18px; line-height: 1.5; }
	.sn-save {
		padding: 11px 22px; background: #1F1B16; color: #FAF6EE; border: none;
		border-radius: 10px; font-family: inherit; font-size: 14px;
		font-weight: 700; cursor: pointer;
	}
	.sn-save:hover { background: #3a2e22; }
	.sn-note {
		margin-top: 18px; padding: 12px 14px; background: #FFF2B3;
		border: 1px solid #E8CF68; border-radius: 10px;
		font-size: 12px; color: #5A4410;
	}
</style>

<div class="sn-wrap">
	<div class="sn-card">
		<h2>Shift Notes → Slack</h2>
		<div class="sn-sub">Paste the #shift-notes incoming-webhook URL. Stored on this server only, never in git.</div>

		@if(session('status'))
			<div class="sn-note" style="background:{{ session('status')['success'] ? '#E6F4EA' : '#FDE8E8' }}; border-color:{{ session('status')['success'] ? '#A8D5B5' : '#E8A0A0' }}; color:{{ session('status')['success'] ? '#2F6B3E' : '#8A3A2E' }};">
				{{ session('status')['msg'] }}
			</div>
		@endif

		@if($env_locked)
			<div class="sn-note">The webhook is set via the server <code>.env</code> (<code>SHIFT_NOTES_SLACK_WEBHOOK</code>), which takes priority over anything entered here.</div>
		@endif

		<form method="post" action="/shift-notes/settings" style="margin-top:16px;">
			{!! csrf_field() !!}
			<div class="sn-label">Slack incoming-webhook URL</div>
			<input type="text" name="slack_webhook" class="sn-input" placeholder="https://hooks.slack.com/services/…" autocomplete="off">
			@if($masked)
				<p class="sn-current">Currently set · ends in {{ $masked }}</p>
			@else
				<p class="sn-current sn-empty">Not set — shift notes are saved but not posted to Slack yet.</p>
			@endif
			<div class="sn-help">
				Get it in Slack: open #shift-notes → Integrations → Add an App → Incoming Webhooks → Add to #shift-notes → copy the URL.
				Leave blank and save to clear.
			</div>
			<button type="submit" class="sn-save">Save webhook</button>
		</form>
	</div>
</div>
@endsection
