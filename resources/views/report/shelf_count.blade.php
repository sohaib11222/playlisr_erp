@extends('layouts.app')
@section('title', 'Shelf Count')

@section('content')
<style>
.sc-wrap { max-width: 1280px; margin: 0 auto; padding: 24px 16px 60px; color: #1F1B16; }
.sc-wrap h1 { font-size: 26px; font-weight: 700; margin: 0 0 6px; }
.sc-sub { color: #2B2620; font-size: 15px; margin: 0 0 14px; }
.sc-row { display: flex; gap: 8px; margin: 0 0 12px; }
.sc-row select { height: 44px; font-size: 16px; border: 1px solid #E1D7C4; border-radius: 10px; padding: 0 10px; flex: 1; }
.sc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 8px; margin-bottom: 12px; }
.sc-bin { display: block; background: #fff; border: 1px solid #E6DCCF; border-radius: 10px; padding: 10px 12px; text-decoration: none; color: #1F1B16; }
.sc-bin.sc-hot { border-color: #8E1B1B; }
.sc-bin b { font-size: 15px; display: block; }
.sc-row select { max-width: 320px; }
.sc-small { color: #2B2620; font-size: 13px; }
.sc-due { color: #8E1B1B; font-weight: 700; font-size: 13px; }
.sc-bin.sc-done { position: relative; border: 2px solid #1E5A1E; background: #2E6B2E; color: #fff; }
.sc-bin.sc-done .sc-small, .sc-bin.sc-done .sc-ok { color: #fff; }
.sc-bin.sc-done .sc-check { background: #fff; color: #2E6B2E; }
.sc-done-tag { position: absolute; top: 8px; right: 10px; background: #fff; color: #2E6B2E; font-weight: 800; font-size: 13px; letter-spacing: .06em; padding: 2px 8px; border-radius: 6px; }
.sc-bin.sc-done b { padding-right: 64px; }
.sc-check { display: inline-flex; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 50%; background: #2E6B2E; color: #fff; font-size: 14px; font-weight: 800; margin-right: 6px; vertical-align: -3px; }
.sc-ok { color: #2E6B2E; font-weight: 700; font-size: 13px; }
</style>
<div class="sc-wrap">
@include('report._count_tabs', ['ctOn' => 'count'])
    <h1>Shelf count</h1>
    <p class="sc-sub">Pick the bin you're standing at. Then scan every record in it (or tap it in the list). When the bin is done, the page shows what the ERP thought was there but wasn't. A green check = at least 80% of the section's titles counted in the last {{ \App\Http\Controllers\ShelfCountController::DUE_DAYS }} days. Use Sort to put High priority or the oldest counts on top. High priority = a cancel or can't find, or 3+ online orders, on titles not counted since. <a href="/reports/shelf-count/missing?location_id={{ $loc }}">Missing list</a></p>
    <form class="sc-row" method="get">
        <select name="location_id" onchange="this.form.submit()">@foreach ($locations as $id => $nm)<option value="{{ $id }}" {{ $id == $loc ? 'selected' : '' }}>{{ ucfirst($nm) }}</option>@endforeach</select>
        <select name="kind" onchange="this.form.submit()">@foreach (($kind === 'all' ? $kinds : ['all' => 'All shelves'] + $kinds) as $k => $l)<option value="{{ $k }}" {{ $kind === $k ? 'selected' : '' }}>{{ $l }}</option>@endforeach</select>
        <select name="sort" onchange="this.form.submit()">@foreach (($sorts ?? []) as $k => $l)<option value="{{ $k }}" {{ ($sort ?? 'priority') === $k ? 'selected' : '' }}>Sort: {{ $l }}</option>@endforeach</select>
    </form>
    <div class="sc-grid">
    @php $scCut = now()->subDays(\App\Http\Controllers\ShelfCountController::DUE_DAYS)->toDateTimeString(); @endphp
    @foreach ($bins as $b)
        @php $scRecent = !empty($b['last']) && $b['last'] >= $scCut; $scDone = $scRecent && ($b['recent'] ?? 0) >= 0.8 * $b['titles']; @endphp
        <a class="sc-bin{{ !empty($b['priority']) ? ' sc-hot' : '' }}{{ $scDone && empty($b['priority']) ? ' sc-done' : '' }}" href="/reports/shelf-count/bin?location_id={{ $loc }}&kind={{ $b['kind'] ?? $kind }}&sub={{ $b['sub'] }}">
            @if ($scDone && empty($b['priority']))<span class="sc-done-tag">DONE</span>@endif
            <b>@if ($scDone)<span class="sc-check" aria-label="Done">&#10003;</span>@endif{{ $b['name'] }}</b>
            <span class="sc-small">{{ number_format($b['titles']) }} titles{{ !empty($b['counted']) ? ', ' . $b['counted'] . ' counted' : '' }}</span><br>
            @if ($scDone)<span class="sc-ok">Done {{ \Carbon\Carbon::parse($b['last'])->format('m/d/y') }}: {{ (int) ($b['recent'] ?? 0) }} of {{ number_format($b['titles']) }} titles counted</span>
            @elseif ($scRecent)<span class="sc-due" style="color:#8A4B00;">Started {{ \Carbon\Carbon::parse($b['last'])->format('m/d/y') }}: {{ (int) ($b['recent'] ?? 0) }} of {{ number_format($b['titles']) }} titles counted</span>
            @elseif (!empty($b['last']))<span class="sc-small">Counted {{ \Carbon\Carbon::parse($b['last'])->format('m/d/y') }}, due again</span>@else<span class="sc-due">Never counted</span>@endif
            @if (!empty($b['priority']))<br><span class="sc-due">High priority: {{ $b['web'] }} online {{ $b['web'] == 1 ? 'order' : 'orders' }}{{ !empty($b['web_bad']) ? ', ' . $b['web_bad'] . " cancelled or can't find" : '' }}</span>@endif
        </a>
    @endforeach
    </div>
</div>
@endsection
