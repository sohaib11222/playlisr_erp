@extends('layouts.app')
@section('title', 'Open Items')

@section('content')
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter+Tight:wght@400;500;600;700;800&display=swap">
@include('daily_checklist.partials.styles')

<style>
.open-shell .oi-top { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap; }
.open-shell .oi-total { font-size:15px; font-weight:700; color:var(--d-ink); margin-top:8px; }
.open-shell .oi-total.zero { color:var(--d-good); }
.open-shell .oi-btn { display:inline-block; font-size:13px; font-weight:700; color:var(--d-accent-text); background:var(--d-accent-soft); border:1px solid var(--d-accent-deep); border-radius:999px; padding:8px 14px; text-decoration:none; }
.open-shell .oi-btn:hover { background:var(--d-accent); text-decoration:none; }
.open-shell .oi-card { background:var(--d-surface); border:1px solid var(--d-line); border-radius:var(--d-radius); margin-top:16px; overflow:hidden; }
.open-shell .oi-head { display:flex; justify-content:space-between; align-items:baseline; gap:12px; padding:14px 18px; border-bottom:1px solid var(--d-line); flex-wrap:wrap; }
.open-shell .oi-head h2 { font-size:17px; font-weight:800; margin:0; color:var(--d-ink); }
.open-shell .oi-count { display:inline-block; min-width:28px; text-align:center; font-size:13px; font-weight:800; border-radius:999px; padding:2px 9px; margin-left:8px; background:#FDE2DF; color:#9B1C12; }
.open-shell .oi-count.zero { background:#DDF1DE; color:var(--d-good); }
.open-shell .oi-how { font-size:13px; color:var(--d-ink-2); margin:0; padding:10px 18px; background:var(--d-surface-2); border-bottom:1px solid var(--d-line); }
.open-shell .oi-row { display:grid; grid-template-columns:minmax(120px,200px) 1fr auto auto; gap:12px; align-items:center; padding:10px 18px; border-bottom:1px solid var(--d-line); font-size:14px; color:var(--d-ink); }
.open-shell .oi-row:last-child { border-bottom:0; }
.open-shell .oi-who { font-weight:700; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.open-shell .oi-what { color:var(--d-ink-2); }
.open-shell .oi-age { font-weight:700; white-space:nowrap; }
.open-shell .oi-age.late { color:#B3261E; }
.open-shell .oi-open a { font-weight:700; color:var(--d-accent-text); white-space:nowrap; }
.open-shell .oi-empty { padding:12px 18px; font-size:14px; color:var(--d-good); font-weight:600; }
.open-shell .oi-err { padding:12px 18px; font-size:14px; color:var(--d-warn); font-weight:600; }

.open-shell .oi-row { grid-template-columns:minmax(120px,200px) 1fr auto auto auto; }
.open-shell .oi-reopen { grid-column:1 / -1; font-size:13px; font-weight:600; color:#B3261E; }
.open-shell .oi-acts { display:flex; gap:6px; }
.open-shell .oi-acts details { position:relative; }
.open-shell .oi-acts summary { list-style:none; cursor:pointer; font-size:12px; font-weight:700; border-radius:999px; padding:5px 10px; white-space:nowrap; border:1px solid var(--d-line-2); color:var(--d-ink); background:var(--d-surface); }
.open-shell .oi-acts summary::-webkit-details-marker { display:none; }
.open-shell .oi-acts .fix summary { background:#DDF1DE; border-color:#9FD3A4; color:#1B5E20; }
.open-shell .oi-acts .ask summary { background:#E3F0FB; border-color:#A9CDEB; color:#174E7C; }
.open-shell .oi-acts form { position:absolute; right:0; top:32px; z-index:20; width:300px; background:var(--d-surface); border:1px solid var(--d-line-2); border-radius:10px; padding:10px; box-shadow:0 8px 24px rgba(0,0,0,.12); display:grid; gap:8px; }
.open-shell .oi-acts textarea { width:100%; min-height:72px; font:inherit; font-size:13px; border:1px solid var(--d-line-2); border-radius:8px; padding:8px; color:var(--d-ink); background:var(--d-surface); }
.open-shell .oi-acts button { font:inherit; font-size:13px; font-weight:700; border-radius:8px; padding:7px 10px; border:1px solid var(--d-accent-deep); background:var(--d-accent); color:var(--d-accent-text); cursor:pointer; }
.open-shell .oi-rec { grid-column:1 / -1; font-size:13px; color:var(--d-ink); background:#E3F0FB; border-radius:8px; padding:6px 10px; }
.open-shell .oi-msg { margin-top:12px; font-weight:700; color:var(--d-ink); background:#E3F0FB; border-radius:10px; padding:10px 14px; }
.open-shell .oi-done { padding:10px 18px; border-bottom:1px solid var(--d-line); font-size:14px; color:var(--d-ink); }
@media (max-width: 700px) {
  .open-shell .oi-acts form { right:auto; left:0; width:260px; }
  .open-shell .oi-row { grid-template-columns:1fr auto; }
  .open-shell .oi-what { grid-column:1 / -1; }
}
</style>

<div class="open-shell">
    <div class="oi-top">
        <div>
            <h1>Open Items</h1>
            <p>Everything still waiting on someone. Work it top to bottom and solve each one the same day. "Fixed it" means the problem is gone, not that someone was told. If it is still open 48 hours later, it comes back. If the ERP is what's stopping you, report it as an ERP bug. Updated {{ $asOf }}.</p>
            <div class="oi-total {{ $total === 0 ? 'zero' : '' }}">{{ $total === 0 ? 'Nothing open. Nice work.' : $total . ' open' }}</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a class="oi-btn" href="{{ url('/open-items?refresh=1') }}">Refresh</a>
            <a class="oi-btn" href="{{ url('/daily-checklist') }}">Daily checklist</a>
        </div>
    </div>

    @if(session('oi_msg'))<div class="oi-msg">{{ session('oi_msg') }}</div>@endif

    @foreach($sections as $key => $s)
        <div class="oi-card" id="oi-{{ $key }}">
            <div class="oi-head">
                <h2>{{ $s['title'] }} <span class="oi-count {{ count($s['rows']) === 0 ? 'zero' : '' }}">{{ count($s['rows']) }}</span></h2>
                @if($s['link'])<a class="oi-btn" href="{{ $s['link'] }}">Open page</a>@endif
            </div>
            @if($s['how'])<p class="oi-how">{{ $s['how'] }}</p>@endif
            @if($s['error'])
                <div class="oi-err">{{ $s['error'] }}</div>
            @elseif(count($s['rows']) === 0)
                <div class="oi-empty">All clear.</div>
            @else
                @foreach($s['rows'] as $r)
                    <div class="oi-row">
                        <div class="oi-who" title="{{ $r['who'] }}">{{ $r['who'] }}</div>
                        <div class="oi-what">{{ $r['what'] }}</div>
                        <div class="oi-age {{ ($r['late'] ?? ($r['hours'] >= 72)) ? 'late' : '' }}" title="{{ $r['since'] }}">{{ $r['age'] }}</div>
                        <div class="oi-open"><a href="{{ $r['link'] }}">Open</a></div>
                        <div class="oi-acts">
                            @if(!empty($r['key']))
                            <details class="fix"><summary>Fixed it</summary>
                                <form method="POST" action="{{ route('open-items.act') }}">
                                    {{ csrf_field() }}
                                    <input type="hidden" name="key" value="{{ $r['key'] }}">
                                    <input type="hidden" name="action" value="fixed">
                                    <input type="hidden" name="section" value="{{ $key }}">
                                    <input type="hidden" name="label" value="{{ $s['title'] }}: {{ $r['who'] }}">
                                    <textarea name="note" required minlength="8" placeholder="What did you do? e.g. Called her, refunded $24.50, she is happy"></textarea>
                                    <button type="submit">Save</button>
                                </form>
                            </details>
                            <details class="ask"><summary>ERP bug</summary>
                                <form method="POST" action="{{ route('open-items.act') }}">
                                    {{ csrf_field() }}
                                    <input type="hidden" name="key" value="{{ $r['key'] }}">
                                    <input type="hidden" name="action" value="bug">
                                    <input type="hidden" name="section" value="{{ $key }}">
                                    <input type="hidden" name="link" value="{{ $r['link'] }}">
                                    <input type="hidden" name="label" value="{{ $s['title'] }}: {{ $r['who'] }}">
                                    <textarea name="note" required minlength="8" placeholder="What in the ERP is broken or missing? e.g. Refund button errors on this order"></textarea>
                                    <button type="submit">Report bug</button>
                                </form>
                            </details>
                            @endif
                        </div>
                        @if(!empty($r['reopened']))<div class="oi-reopen">{{ $r['reopened'] }}</div>@endif
                    </div>
                @endforeach
            @endif
        </div>
    @endforeach

    <div class="oi-card" id="oi-done">
        <div class="oi-head"><h2>Fixed or reported today <span class="oi-count zero">{{ count($done) }}</span></h2></div>
        @forelse($done as $a)
            <div class="oi-done"><strong>{{ $a['label'] ?? '' }}</strong> ({{ $a['by'] ?? '' }}, {{ \Carbon\Carbon::parse($a['at'])->setTimezone('America/Los_Angeles')->format('g:i A') }}): {{ $a['note'] ?? '' }}</div>
        @empty
            <div class="oi-done">Nothing marked fixed yet today.</div>
        @endforelse
    </div>
</div>
@endsection
