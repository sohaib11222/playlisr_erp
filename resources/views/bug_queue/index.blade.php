@extends('layouts.app')
@section('title', 'Bug Fixes')

@section('content')
<style>
	.bq-wrap { font-family: "Inter Tight", system-ui, sans-serif; color: #1F1B16; max-width: 920px; margin: 24px auto; padding: 0 16px; }
	.bq-card { background: #FAF6EE; border: 1px solid #ECE3CF; border-radius: 14px; padding: 20px 22px; margin-bottom: 16px; }
	.bq-card h2 { margin: 0 0 4px; font-size: 21px; font-weight: 800; }
	.bq-sub { font-size: 14px; color: #3D352C; }
	.bq-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin: 4px 0 16px; }
	.bq-tab { padding: 7px 14px; border-radius: 999px; border: 1px solid #C9D9EA; background: #fff; color: #1F1B16; font-weight: 600; font-size: 13px; text-decoration: none; }
	.bq-tab:hover { text-decoration: none; color: #1F1B16; background: #F1F7FD; }
	.bq-tab.on { background: #DCEBFA; border-color: #8DB6DE; }
	.bq-n { display: inline-block; min-width: 18px; padding: 0 6px; margin-left: 4px; border-radius: 9px; background: #1F1B16; color: #fff; font-size: 11px; line-height: 18px; text-align: center; }
	.bq-item { background: #fff; border: 1px solid #ECE3CF; border-radius: 12px; padding: 16px 18px; margin-bottom: 12px; }
	.bq-head { display: flex; justify-content: space-between; gap: 10px; flex-wrap: wrap; align-items: baseline; }
	.bq-id { font-weight: 800; font-size: 15px; }
	.bq-meta { font-size: 13px; color: #3D352C; }
	.bq-status { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; }
	.bq-s-new, .bq-s-rework { background: #FFF2C7; color: #6B4E00; }
	.bq-s-needs_info { background: #FDE2D6; color: #8A3A2E; }
	.bq-s-fix_ready { background: #DCEBFA; color: #18467A; }
	.bq-s-merged { background: #E1F2E5; color: #2F6B3E; }
	.bq-s-wont_fix { background: #EEE9DF; color: #3D352C; }
	.bq-kind { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 11px; font-weight: 700; background: #F3EEE3; margin-right: 6px; }
	.bq-desc { white-space: pre-wrap; font-size: 14px; line-height: 1.5; margin: 10px 0 6px; }
	.bq-box { border-radius: 10px; padding: 10px 12px; font-size: 14px; margin-top: 10px; white-space: pre-wrap; line-height: 1.5; }
	.bq-fix { background: #F1F7FD; border: 1px solid #C9D9EA; }
	.bq-q { background: #FFF6EF; border: 1px solid #F2D2BF; }
	.bq-note { background: #FBF7EC; border: 1px solid #ECE3CF; }
	.bq-label { font-weight: 800; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; display: block; margin-bottom: 3px; }
	.bq-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; align-items: flex-start; }
	.bq-btn { padding: 8px 14px; border-radius: 9px; background: #fff; border: 1px solid #DFD2B3; color: #1F1B16; font-weight: 700; font-size: 13px; cursor: pointer; font-family: inherit; text-decoration: none; }
	.bq-btn:hover { text-decoration: none; color: #1F1B16; }
	.bq-btn-go { background: #2F6B3E; border-color: #2F6B3E; color: #fff; }
	.bq-btn-go:hover { color: #fff; }
	.bq-input { width: 100%; padding: 9px 11px; font-family: inherit; font-size: 14px; border: 1px solid #DFD2B3; border-radius: 9px; background: #fff; box-sizing: border-box; }
	.bq-msg { padding: 10px 14px; border-radius: 10px; margin-bottom: 14px; font-size: 14px; }
	.bq-shot img { max-width: 100%; max-height: 260px; border-radius: 8px; border: 1px solid #ECE3CF; margin-top: 6px; }
	.bq-empty { font-size: 14px; padding: 18px 4px; }
	details.bq-more summary { cursor: pointer; font-size: 13px; font-weight: 700; margin-top: 10px; }
</style>

<div class="bq-wrap">
	@if(session('status'))
		<div class="bq-msg" style="background:{{ session('status')['success'] ? '#E6F4EA' : '#FDE8E8' }}; color:{{ session('status')['success'] ? '#2F6B3E' : '#8A3A2E' }};">
			{{ session('status')['msg'] }}
		</div>
	@endif

	<div class="bq-card">
		<div class="bq-head">
			<div>
				<h2>Bug Fixes</h2>
				<div class="bq-sub">Use the Report a bug or a fix button at the bottom right of any page (on the POS it is in the menu), then follow it here. Tag anyone and they get a Slack message. Every fix is checked by Sarah or Jon before it goes live.</div>
			</div>
			<button type="button" class="bq-btn bq-btn-go" onclick="window.erpOpenBugReport && window.erpOpenBugReport()">Report a bug or a fix</button>
		</div>
		@if($is_admin)
			<div class="bq-sub" style="margin-top:10px;">
				Fixer last checked in: <strong>{{ $routine_seen ? \Carbon\Carbon::parse($routine_seen)->format('m/d/y g:ia') : 'not yet' }}</strong>
				@if(!$have_token) (Approve works after its first check-in.) @endif
			</div>
		@endif
	</div>

	<div class="bq-tabs">
		@foreach($tabs as $key => $t)
			<a class="bq-tab {{ $tab === $key ? 'on' : '' }}" href="/bug-fixes?tab={{ $key }}">{{ $t[0] }}@if(!empty($tab_counts[$key]) && in_array($key, ['review', 'open']))<span class="bq-n">{{ $tab_counts[$key] }}</span>@endif</a>
		@endforeach
	</div>

	@forelse($reports as $r)
		<div class="bq-item" id="bug-{{ $r->id }}">
			<div class="bq-head">
				<div class="bq-id">
					<span class="bq-kind">{{ $r->kind === 'request' ? 'Fix request' : 'Bug' }}</span>#{{ $r->id }}
				</div>
				<span class="bq-status bq-s-{{ $r->status }}">{{ $labels[$r->status] ?? $r->status }}</span>
			</div>
			<div class="bq-meta">
				{{ $r->reporter_name ?: 'Someone' }}@if($r->location_name), {{ $r->location_name }}@endif
				@if($r->source === 'slack') (from #erp-bugs) @endif
				on {{ \Carbon\Carbon::parse($r->created_at)->format('m/d/y g:ia') }}
				@if($r->page_url) &middot; <a href="{{ $r->page_url }}" target="_blank" rel="noopener">page</a>@endif
				@php $__tags = array_filter(array_map(function ($i) use ($tag_names) { return $tag_names[(int) $i] ?? null; }, explode(',', (string) $r->tagged_user_ids))); @endphp
				@if($__tags) &middot; Tagged: <strong>{{ implode(', ', $__tags) }}</strong>@endif
			</div>
			<div class="bq-desc">{{ $r->description }}</div>
			@if($r->screenshot_path)
				<div class="bq-shot"><a href="/bug-fixes/{{ $r->id }}/screenshot" target="_blank"><img src="/bug-fixes/{{ $r->id }}/screenshot" alt="Screenshot for #{{ $r->id }}" loading="lazy"></a></div>
			@endif

			@if($r->status === 'needs_info' && $r->question)
				<div class="bq-box bq-q"><span class="bq-label">Question</span>{{ $r->question }}</div>
				<form method="POST" action="/bug-fixes/{{ $r->id }}/answer" class="bq-actions" style="display:block;">
					@csrf
					<textarea name="answer" class="bq-input" rows="2" placeholder="Your answer" required></textarea>
					<button class="bq-btn bq-btn-go" style="margin-top:8px;">Send answer</button>
				</form>
			@endif

			@if($r->answer)
				<div class="bq-box bq-note"><span class="bq-label">Answer</span>{{ $r->answer }}</div>
			@endif

			@if($r->fix_summary && in_array($r->status, ['fix_ready', 'merged', 'wont_fix']))
				<div class="bq-box bq-fix"><span class="bq-label">{{ $r->status === 'wont_fix' ? 'Why' : 'What the fix does' }}</span>{{ $r->fix_summary }}</div>
			@endif
			@if($r->test_steps && in_array($r->status, ['fix_ready', 'merged']))
				<div class="bq-box bq-note"><span class="bq-label">How to check it after it is live</span>{{ $r->test_steps }}</div>
			@endif
			@if($r->review_note)
				<div class="bq-box bq-note"><span class="bq-label">Review note</span>{{ $r->review_note }}</div>
			@endif
			@if($r->status === 'merged' && $r->decided_by_name)
				<div class="bq-meta" style="margin-top:8px;">Approved by {{ $r->decided_by_name }} on {{ \Carbon\Carbon::parse($r->merged_at ?: $r->decided_at)->format('m/d/y g:ia') }}</div>
			@elseif($r->status === 'wont_fix' && $r->decided_by_name)
				<div class="bq-meta" style="margin-top:8px;">Closed by {{ $r->decided_by_name }} on {{ \Carbon\Carbon::parse($r->decided_at)->format('m/d/y') }}</div>
			@endif

			@if($is_admin)
				<div class="bq-actions">
					@if(in_array($r->status, ['new', 'rework']))
						@if(isset($fix_now[(string) $r->id]))
							<span class="bq-btn" style="background:#DCEBFA;border-color:#8DB6DE;cursor:default;">Sent to Claude {{ \Carbon\Carbon::parse($fix_now[(string) $r->id]['at'])->format('m/d/y g:ia') }}</span>
						@else
							<form method="POST" action="/bug-fixes/{{ $r->id }}/fix-now" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Sending...';">
								@csrf
								<button class="bq-btn bq-btn-go">Fix now</button>
							</form>
						@endif
					@endif
					@if($r->status === 'fix_ready')
						<form method="POST" action="/bug-fixes/{{ $r->id }}/approve" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Approving...';">
							@csrf
							<button class="bq-btn bq-btn-go">Approve and ship</button>
						</form>
					@endif
					@if($r->pr_url)
						<a class="bq-btn" href="{{ $r->pr_url }}/files" target="_blank" rel="noopener">See code change</a>
					@endif
					@if(in_array($r->status, ['fix_ready', 'new', 'rework', 'needs_info']))
						<details class="bq-more" style="margin-top:0;">
							<summary class="bq-btn" style="list-style:none;">Send back</summary>
							<form method="POST" action="/bug-fixes/{{ $r->id }}/send-back" style="margin-top:8px;min-width:280px;">
								@csrf
								<textarea name="note" class="bq-input" rows="2" placeholder="What should change?" required></textarea>
								<button class="bq-btn" style="margin-top:6px;">Send back to fixer</button>
							</form>
						</details>
						<details class="bq-more" style="margin-top:0;">
							<summary class="bq-btn" style="list-style:none;">Won't fix</summary>
							<form method="POST" action="/bug-fixes/{{ $r->id }}/wont-fix" style="margin-top:8px;min-width:280px;">
								@csrf
								<input name="note" class="bq-input" placeholder="Why (optional)">
								<button class="bq-btn" style="margin-top:6px;">Close it</button>
							</form>
						</details>
					@endif
					@if(in_array($r->status, ['wont_fix', 'merged']))
						<form method="POST" action="/bug-fixes/{{ $r->id }}/reopen">
							@csrf
							<button class="bq-btn">Reopen</button>
						</form>
					@endif
				</div>
			@endif
		</div>
	@empty
		<div class="bq-empty">
			@if($tab === 'review') Nothing waiting for approval right now.
			@elseif($tab === 'open') No open bugs right now.
			@elseif($tab === 'mine') You have not reported anything yet.
			@elseif($tab === 'tagged') Nobody has tagged you yet.
			@else Nothing here yet.
			@endif
		</div>
	@endforelse
</div>
@endsection
