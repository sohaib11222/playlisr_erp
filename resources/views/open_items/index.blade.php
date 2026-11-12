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
@media (max-width: 700px) {
  .open-shell .oi-row { grid-template-columns:1fr auto; }
  .open-shell .oi-what { grid-column:1 / -1; }
}
</style>

<div class="open-shell">
    <div class="oi-top">
        <div>
            <h1>Open Items</h1>
            <p>Everything still waiting on someone. Work it top to bottom and close each one out the same day. Updated {{ $asOf }}.</p>
            <div class="oi-total {{ $total === 0 ? 'zero' : '' }}">{{ $total === 0 ? 'Nothing open. Nice work.' : $total . ' open' }}</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <a class="oi-btn" href="{{ url('/open-items?refresh=1') }}">Refresh</a>
            <a class="oi-btn" href="{{ url('/daily-checklist') }}">Daily checklist</a>
        </div>
    </div>

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
                    </div>
                @endforeach
            @endif
        </div>
    @endforeach
</div>
@endsection
