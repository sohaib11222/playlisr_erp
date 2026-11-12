@extends('layouts.app')
@section('title', 'Shelf Count')

@section('content')
<style>
.sc-wrap { max-width: 1280px; margin: 0 auto; padding: 24px 16px 60px; color: #1F1B16; font-family: 'Inter Tight', Inter, system-ui, sans-serif; }
.sc-wrap h1 { font-size: 28px; font-weight: 800; margin: 0 0 12px; color: #1F1B16; }
.sc-card { background: #fff; border: 1px solid #E6DCCF; border-radius: 16px; box-shadow: 0 1px 2px rgba(31,27,22,.06), 0 4px 14px rgba(31,27,22,.05); }
.sc-row { display: flex; gap: 8px; margin: 0 0 14px; flex-wrap: wrap; }
.sc-row select { height: 46px; font-size: 16px; border: 1px solid #D9CDB8; border-radius: 12px; padding: 0 12px; flex: 1; min-width: 160px; max-width: 320px; background: #fff; color: #1F1B16; }

/* Hero: ring + milestones */
.sc-hero { display: flex; gap: 22px; align-items: center; padding: 20px 22px; margin: 0 0 14px; flex-wrap: wrap; }
.sc-ring { --p: 0; width: 132px; height: 132px; border-radius: 50%; flex: 0 0 auto; display: grid; place-items: center;
  background: conic-gradient(#2E6B2E calc(var(--p) * 1%), #EFE8DC 0); }
.sc-ring-in { width: 104px; height: 104px; border-radius: 50%; background: #fff; display: grid; place-items: center; text-align: center; }
.sc-ring-in b { font-size: 30px; font-weight: 800; line-height: 1; }
.sc-ring-in span { font-size: 12px; font-weight: 700; letter-spacing: .04em; }
.sc-hero-main { flex: 1; min-width: 260px; }
.sc-hero-title { font-size: 21px; font-weight: 800; margin: 0 0 2px; }
.sc-hero-line { font-size: 16px; margin: 2px 0; }
.sc-pace { display: inline-block; margin-top: 8px; background: #E8F0F8; color: #13507a; border: 1px solid #1b6ca8; font-weight: 700; font-size: 15px; padding: 6px 12px; border-radius: 999px; }
.sc-miles { display: flex; gap: 6px; margin-top: 12px; flex-wrap: wrap; }
.sc-mile { font-size: 13px; font-weight: 700; padding: 5px 10px; border-radius: 999px; border: 1px solid #D9CDB8; color: #1F1B16; background: #fff; }
.sc-mile.on { background: #2E6B2E; border-color: #2E6B2E; color: #fff; }

/* Stats + leaderboard */
.sc-side { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin: 0 0 14px; align-items: start; }
@media (max-width: 760px) { .sc-side { grid-template-columns: 1fr; } }
.sc-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; padding: 16px; }
.sc-stat b { display: block; font-size: 26px; font-weight: 800; line-height: 1.1; }
.sc-stat span { font-size: 14px; }
.sc-board { padding: 14px 16px; }
.sc-board h3, .sc-guide h3 { font-size: 17px; font-weight: 800; margin: 0 0 8px; }
.sc-lead { display: flex; align-items: center; gap: 10px; padding: 6px 0; border-top: 1px solid #F1EADF; font-size: 15px; }
.sc-lead:first-of-type { border-top: 0; }
.sc-rank { width: 28px; height: 28px; border-radius: 50%; display: grid; place-items: center; font-weight: 800; font-size: 14px; background: #F1EADF; flex: 0 0 auto; }
.sc-lead.r1 .sc-rank { background: #2E6B2E; color: #fff; }
.sc-lead-n { margin-left: auto; font-weight: 800; }
.sc-top-tag { font-size: 11px; font-weight: 800; letter-spacing: .05em; background: #2E6B2E; color: #fff; padding: 2px 7px; border-radius: 6px; }

/* Goal */
.sc-goalbar { background: #1F1B16; color: #fff; border-radius: 16px; padding: 18px 22px; margin: 0 0 14px; }
.sc-goalbar-t { font-size: 13px; font-weight: 800; letter-spacing: .12em; color: #fff; }
.sc-goalbar-b { font-size: 26px; font-weight: 800; line-height: 1.2; margin: 2px 0 4px; color: #fff; }
.sc-goalbar-w { font-size: 16px; color: #fff; }
/* Guide */
.sc-guide { padding: 16px 18px; margin: 0 0 14px; font-size: 16px; line-height: 1.5; }
.sc-goal { font-size: 17px; margin: 0 0 10px; }
.sc-steps { list-style: none; margin: 0; padding: 0; counter-reset: st; }
.sc-steps li { counter-increment: st; position: relative; padding: 4px 0 4px 38px; margin: 2px 0; }
.sc-steps li::before { content: counter(st); position: absolute; left: 0; top: 4px; width: 26px; height: 26px; border-radius: 50%; background: #1F1B16; color: #fff; font-weight: 800; font-size: 14px; display: grid; place-items: center; }
.sc-red { color: #B91C1C; }

/* Start here */
.sc-next { display: flex; align-items: center; gap: 14px; padding: 14px 18px; margin: 0 0 14px; text-decoration: none !important; color: #1F1B16 !important; border: 2px solid #2E6B2E; }
.sc-next:hover { background: #F4FAF4; }
.sc-next-tag { background: #2E6B2E; color: #fff; font-weight: 800; font-size: 13px; letter-spacing: .05em; padding: 4px 10px; border-radius: 8px; }
.sc-next b { font-size: 17px; }
.sc-next-go { margin-left: auto; font-weight: 800; color: #2E6B2E; font-size: 16px; }

/* Section cards */
.sc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 12px; margin-bottom: 12px; }
.sc-bin { display: block; position: relative; padding: 14px 16px; text-decoration: none !important; color: #1F1B16 !important; transition: transform .12s ease, box-shadow .12s ease; }
.sc-bin:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(31,27,22,.12); }
.sc-bin b { font-size: 17px; display: block; color: #1F1B16 !important; padding-right: 58px; line-height: 1.3; font-weight: 800; }
.sc-pct { position: absolute; top: 14px; right: 16px; font-size: 17px; font-weight: 800; color: #1F1B16; }
.sc-bar { height: 10px; background: #EFE8DC; border-radius: 999px; margin: 10px 0 8px; overflow: hidden; }
.sc-bar span { display: block; height: 100%; background: #2E6B2E; border-radius: 999px; }
.sc-line { color: #1F1B16; font-size: 14px; margin-top: 2px; }
.sc-flag { display: inline-block; margin-top: 8px; background: #8E1B1B; color: #fff; font-size: 12px; font-weight: 700; padding: 3px 9px; border-radius: 8px; }
.sc-bin.sc-hot { border: 2px solid #8E1B1B; }
.sc-bin.sc-done { border: 2px solid #1E5A1E; background: #2E6B2E; color: #fff !important; }
.sc-bin.sc-done b, .sc-bin.sc-done .sc-line { color: #fff !important; }
.sc-bin.sc-done .sc-bar { background: rgba(255,255,255,.3); }
.sc-bin.sc-done .sc-bar span { background: #fff; }
.sc-done-tag { position: absolute; top: 12px; right: 14px; background: #fff; color: #2E6B2E; font-weight: 800; font-size: 13px; letter-spacing: .06em; padding: 3px 9px; border-radius: 8px; }
.sc-bin.sc-done b { padding-right: 74px; }
.sc-h2 { font-size: 19px; font-weight: 800; margin: 18px 0 10px; }
.sc-h2-n { display: inline-block; margin-left: 6px; font-size: 14px; background: #F1EADF; border-radius: 999px; padding: 2px 10px; vertical-align: 2px; }
</style>
<div class="sc-wrap">
@include('report._count_tabs', ['ctOn' => 'count'])
    <h1>Shelf count</h1>
    <div class="sc-goalbar"><div class="sc-goalbar-t">GOAL</div><div class="sc-goalbar-b">The ERP shows exactly what we have in each store.</div><div class="sc-goalbar-w">This helps us with 5-star customer service, organization, ordering and tracking theft.</div></div>
    <form class="sc-row" method="get">
        <select name="location_id" onchange="this.form.submit()">@foreach ($locations as $id => $nm)<option value="{{ $id }}" {{ $id == $loc ? 'selected' : '' }}>{{ ucfirst($nm) }}</option>@endforeach</select>
        <select name="kind" onchange="this.form.submit()">@foreach (($kind === 'all' ? $kinds : ['all' => 'All shelves'] + $kinds) as $k => $l)<option value="{{ $k }}" {{ $kind === $k ? 'selected' : '' }}>{{ $l }}</option>@endforeach</select>
        <select name="sort" onchange="this.form.submit()">@foreach (($sorts ?? []) as $k => $l)<option value="{{ $k }}" {{ ($sort ?? 'priority') === $k ? 'selected' : '' }}>Sort: {{ $l }}</option>@endforeach</select>
    </form>

    @if (!empty($overall))
    @php
        $ovPct = $overall['titles'] ? min(100, (int) round(100 * $overall['counted'] / $overall['titles'])) : 0;
        $left = max(0, $overall['titles'] - $overall['counted']);
        $perDay = !empty($game) ? (int) ceil($left / max(1, $game['daysLeft'])) : 0;
        $miles = [25 => 'Warmed up', 50 => 'Halfway', 75 => 'Almost there', 100 => 'Finished'];
    @endphp
    <div class="sc-card sc-hero">
        <div class="sc-ring" style="--p: {{ $ovPct }}"><div class="sc-ring-in"><div><b>{{ $ovPct }}%</b><br><span>COUNTED</span></div></div></div>
        <div class="sc-hero-main">
            <div class="sc-hero-title">{{ $overall['name'] }}{{ $overall['sealed'] ? ': year-end count' : '' }}</div>
            <div class="sc-hero-line"><b>{{ number_format($overall['counted']) }}</b> of {{ number_format($overall['titles']) }} titles counted. <b>{{ number_format($left) }}</b> to go.</div>
            @if (!$overall['sealed'])<div class="sc-hero-line">Counted in the last {{ \App\Http\Controllers\ShelfCountController::DUE_DAYS }} days.</div>@endif
        </div>
    </div>
    @endif

    @if (!empty($game))
    <div class="sc-side">
        <div class="sc-card sc-stats">
            <div class="sc-stat"><b>{{ number_format($game['today']) }}</b><span>titles counted today</span></div>
            <div class="sc-stat"><b>{{ $game['done'] }}/{{ $game['sections'] }}</b><span>sections done</span></div>
            <div class="sc-stat"><b>{{ number_format(array_sum($game['board'])) }}</b><span>counted this week (top 5)</span></div>
        </div>
        <div class="sc-card sc-board">
            <h3>Top counters this week</h3>
            @forelse ($game['board'] as $who => $n)
                <div class="sc-lead r{{ $loop->iteration }}"><span class="sc-rank">{{ $loop->iteration }}</span><span>{{ $who }}</span>@if ($loop->first)<span class="sc-top-tag">TOP COUNTER</span>@endif<span class="sc-lead-n">{{ number_format($n) }}</span></div>
            @empty
                <div class="sc-line">No counts yet this week. Be the first!</div>
            @endforelse
        </div>
    </div>
    @endif

    <div class="sc-card sc-guide">
        <ol class="sc-steps">
            <li>Pick the bin you're standing at.</li>
            <li>Scan every record in it, or tap it in the list.</li>
            <li><b>If a title shows in a bin in the ERP but it is not physically there, please tap None to mark it 0.</b></li>
            <li><b class="sc-red">If the record is in the wrong bin in the ERP, please fix it!</b> Tap <b>Genre</b> and pick the right one, so we know exactly where to find things.</li>
            <li>If you need an edit to this page to make your inventory count easier, please tap <b>Report a bug</b> at the bottom right and we will fix it.</li>
        </ol>
    </div>

    @php
        $scCut = now()->subDays(\App\Http\Controllers\ShelfCountController::DUE_DAYS)->toDateTimeString();
        $isDone = function ($b) use ($scCut) { return !empty($b['last']) && $b['last'] >= $scCut && ($b['recent'] ?? 0) >= 0.8 * $b['titles']; };
        $next = null;
        foreach ($bins as $b) { if (!$isDone($b)) { $next = $b; break; } }
    @endphp
    @if ($next)
        <a class="sc-card sc-next" href="/reports/shelf-count/bin?location_id={{ $loc }}&kind={{ $next['kind'] ?? $kind }}&sub={{ $next['sub'] }}">
            <span class="sc-next-tag">START HERE</span>
            <span><b>{{ $next['name'] }}</b><br><span class="sc-line">{{ !empty($next['priority']) ? 'High priority: online orders waiting on this section' : number_format($next['titles']) . ' titles, ' . number_format((int) ($next['recent'] ?? 0)) . ' counted so far' }}</span></span>
            <span class="sc-next-go">Count it &rsaquo;</span>
        </a>
    @endif

    @php
        $groups = ['todo' => [], 'recount' => [], 'done' => []];
        foreach ($bins as $b) {
            if ($isDone($b)) $groups['done'][] = $b;
            elseif (!empty($b['last']) && $b['last'] < $scCut) $groups['recount'][] = $b;
            else $groups['todo'][] = $b;
        }
        $gTitle = ['todo' => 'To count', 'recount' => 'Due for a recount (last count over ' . \App\Http\Controllers\ShelfCountController::DUE_DAYS . ' days ago)', 'done' => 'Done'];
    @endphp
    @foreach ($groups as $g => $list)
    @if (count($list))
    <div class="sc-h2">{{ $gTitle[$g] }} <span class="sc-h2-n">{{ count($list) }}</span></div>
    <div class="sc-grid">
    @foreach ($list as $b)
        @php $scRecent = !empty($b['last']) && $b['last'] >= $scCut; $scDone = $isDone($b); $scPct = $b['titles'] ? min(100, (int) round(100 * ($b['recent'] ?? 0) / $b['titles'])) : 0; @endphp
        <a class="sc-card sc-bin{{ !empty($b['priority']) ? ' sc-hot' : '' }}{{ $scDone && empty($b['priority']) ? ' sc-done' : '' }}" href="/reports/shelf-count/bin?location_id={{ $loc }}&kind={{ $b['kind'] ?? $kind }}&sub={{ $b['sub'] }}">
            @if ($scDone && empty($b['priority']))<span class="sc-done-tag">DONE</span>@else<span class="sc-pct">{{ $scPct }}%</span>@endif
            <b>{{ $b['name'] }}</b>
            <div class="sc-bar"><span style="width:{{ $scPct }}%"></span></div>
            <div class="sc-line">{{ number_format((int) ($b['recent'] ?? 0)) }} of {{ number_format($b['titles']) }} counted</div>
            <div class="sc-line">{{ !empty($b['last']) ? 'Last count ' . \Carbon\Carbon::parse($b['last'])->format('m/d/y') . ($scRecent ? '' : ', due again') : 'Never counted' }}</div>
            @if (empty($b['sub']))<span class="sc-flag">Please assign these titles to their correct genre bins</span>@endif
            @if (!empty($b['priority']))<span class="sc-flag">High priority: {{ $b['web'] }} online {{ $b['web'] == 1 ? 'order' : 'orders' }}{{ !empty($b['web_bad']) ? ', ' . $b['web_bad'] . " cancelled or can't find" : '' }}</span>@endif
        </a>
    @endforeach
    </div>
    @endif
    @endforeach
</div>
@endsection
