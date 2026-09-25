@extends('layouts.app')
@section('title', 'Register Reconciliation')

@section('content')
<style>
	.rr-wrap { font-family: "Inter Tight", system-ui, sans-serif; color: #1F1B16; max-width: 860px; margin: 24px auto; padding: 0 16px; }
	.rr-card { background: #FAF6EE; border: 1px solid #ECE3CF; border-radius: 14px; padding: 22px 24px; margin-bottom: 18px; }
	.rr-card h2 { margin: 0 0 4px; font-size: 20px; font-weight: 800; }
	.rr-sub { font-size: 13px; color: #8E8273; margin-bottom: 14px; }
	.rr-nav { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
	.rr-nav a, .rr-btn { padding: 8px 14px; border-radius: 9px; background: #fff; border: 1px solid #DFD2B3; color: #1F1B16; font-weight: 700; font-size: 13px; text-decoration: none; cursor: pointer; font-family: inherit; }
	.rr-btn-dark { background: #1F1B16; color: #FAF6EE; border-color: #1F1B16; }
	.rr-store { margin-top: 16px; }
	.rr-store h3 { font-size: 16px; font-weight: 800; margin: 0 0 4px; }
	.rr-totals { font-size: 13px; color: #5A5045; margin-bottom: 8px; }
	.rr-item { background: #fff; border: 1px solid #ECE3CF; border-radius: 10px; padding: 10px 12px; margin-bottom: 6px; font-size: 14px; }
	.rr-ask { font-weight: 700; color: #8A3A2E; }
	.rr-note { font-size: 12px; color: #2F6B3E; margin-top: 4px; }
	.rr-ok { color: #2F6B3E; font-weight: 600; font-size: 14px; }
	.rr-input { width: 100%; padding: 10px 12px; font-family: inherit; font-size: 14px; border: 1px solid #DFD2B3; border-radius: 9px; background: #fff; }
	.rr-msg { padding: 10px 14px; border-radius: 10px; margin-bottom: 14px; font-size: 13px; }
	.rr-pre { white-space: pre-wrap; font-size: 12px; background: #fff; border: 1px solid #ECE3CF; border-radius: 10px; padding: 12px; }
</style>

<div class="rr-wrap">
	@if(session('status'))
		<div class="rr-msg" style="background:{{ session('status')['success'] ? '#E6F4EA' : '#FDE8E8' }}; color:{{ session('status')['success'] ? '#2F6B3E' : '#8A3A2E' }};">
			{{ session('status')['msg'] }}
		</div>
	@endif

	<div class="rr-card">
		<h2>Register reconciliation</h2>
		<div class="rr-sub">Everything that needs fixing or a question to a cashier for the day. Posts to Slack every morning at 8am for the day before.</div>
		<div class="rr-nav">
			<a href="/register-recon?date={{ $prev_date }}">&larr; Prev day</a>
			<strong>{{ \Carbon\Carbon::parse($date)->format('l, M j, Y') }}</strong>
			@if($allow_next) <a href="/register-recon?date={{ $next_date }}">Next day &rarr;</a>@endif
			<a href="/pos/recent-feed?date={{ $date }}">Open recent feed</a>
			@if(!empty($posted[$date])) <span style="font-size:12px;color:#8E8273;">Posted to Slack {{ $posted[$date] }}</span>@endif
		</div>

		@if($error)
			<div class="rr-msg" style="background:#FDE8E8;color:#8A3A2E;">Could not build this day: {{ $error }}</div>
		@elseif($report)
			@if($report['issue_count'] === 0)
				<div class="rr-ok">Everything matches. Nothing to fix.</div>
			@endif
			@foreach($report['stores'] as $s)
				<div class="rr-store">
					<h3>{{ $s['name'] }}</h3>
					<div class="rr-totals">
						ERP ${{ number_format($s['erp'], 2) }} ({{ $s['erp_count'] }})
						&middot; Clover ${{ number_format($s['clover'], 2) }} ({{ $s['clover_count'] }})
						&middot; @if(abs($s['diff']) < 1) matches @else {{ $s['diff'] > 0 ? 'Clover' : 'ERP' }} higher by ${{ number_format(abs($s['diff']), 2) }} @endif
					</div>
					@forelse($s['items'] as $it)
						<div class="rr-item">
							{{ $it['text'] }} -
							@if($it['ask'] !== '') <span class="rr-ask">ask {{ $it['ask'] }}</span>@else <span class="rr-ask">cashier unknown</span>@endif
							@if(!empty($it['note'])) <div class="rr-note">Already explained: {{ $it['note'] }}</div>@endif
						</div>
					@empty
						<div class="rr-ok">Nothing to fix.</div>
					@endforelse
				</div>
			@endforeach

			<form method="post" action="/register-recon/post" style="margin-top:18px;">
				{!! csrf_field() !!}
				<input type="hidden" name="date" value="{{ $date }}">
				<button type="submit" class="rr-btn rr-btn-dark">Post this day to Slack now</button>
			</form>
		@endif
	</div>

	<div class="rr-card">
		<h2>Slack channel</h2>
		<form method="post" action="/register-recon/settings" style="margin-top:10px;">
			{!! csrf_field() !!}
			<input type="text" name="slack_webhook" class="rr-input" placeholder="https://hooks.slack.com/services/..." autocomplete="off">
			<p style="font-size:13px;margin:8px 0;{{ $masked ? 'color:#2F6B3E' : 'color:#8A3A2E' }};font-weight:600;">
				{{ $masked ? 'Connected - webhook ends in ' . $masked : 'Not connected yet - nothing posts until a webhook is saved.' }}
			</p>
			<div class="rr-sub">In Slack: open #register-reconciliation, Integrations, Add an App, Incoming Webhooks, add to this channel, copy the URL.</div>
			<button type="submit" class="rr-btn rr-btn-dark">Save webhook</button>
		</form>
	</div>

	@if($slack_text)
	<div class="rr-card">
		<h2>Slack message preview</h2>
		<div class="rr-pre">{{ $slack_text }}</div>
	</div>
	@endif
</div>
@endsection
