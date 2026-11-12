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
.sc-bin { display: block; position: relative; background: #fff; border: 1px solid #E6DCCF; border-radius: 10px; padding: 12px 14px; text-decoration: none !important; color: #1F1B16 !important; }
.sc-bin:hover { border-color: #1F1B16; }
.sc-bin b { font-size: 16px; display: block; color: #1F1B16 !important; padding-right: 54px; line-height: 1.3; }
.sc-row select { max-width: 320px; }
.sc-small { color: #1F1B16; font-size: 13px; }
.sc-line { color: #1F1B16; font-size: 14px; margin-top: 2px; }
.sc-pct { position: absolute; top: 12px; right: 14px; font-size: 15px; font-weight: 800; color: #1F1B16; }
.sc-bar { height: 8px; background: #EFE8DC; border-radius: 4px; margin: 8px 0 6px; overflow: hidden; }
.sc-bar span { display: block; height: 100%; background: #2E6B2E; }
.sc-flag { display: inline-block; margin-top: 6px; background: #8E1B1B; color: #fff; font-size: 12px; font-weight: 700; padding: 2px 8px; border-radius: 6px; }
.sc-bin.sc-hot { border: 2px solid #8E1B1B; }
.sc-bin.sc-done { border: 2px solid #1E5A1E; background: #2E6B2E; color: #fff !important; }
.sc-bin.sc-done b, .sc-bin.sc-done .sc-line { color: #fff !important; }
.sc-bin.sc-done .sc-bar { background: rgba(255,255,255,.3); }
.sc-bin.sc-done .sc-bar span { background: #fff; }
.sc-done-tag { position: absolute; top: 10px; right: 12px; background: #fff; color: #2E6B2E; font-weight: 800; font-size: 13px; letter-spacing: .06em; padding: 2px 8px; border-radius: 6px; }
.sc-bin.sc-done b { padding-right: 70px; }
.sc-overall { position: relative; background: #fff; border: 2px solid #1F1B16; border-radius: 12px; padding: 14px 16px; margin: 0 0 12px; }
.sc-overall b { font-size: 18px; display: block; padding-right: 80px; }
.sc-ov-pct { position: absolute; top: 12px; right: 16px; font-size: 24px; font-weight: 800; }
.sc-ov-bar { height: 14px; border-radius: 7px; }
.sc-guide { background: #fff; border: 2px solid #1F1B16; border-radius: 12px; padding: 14px 18px; margin: 0 0 14px; color: #1F1B16; font-size: 16px; line-height: 1.5; }
.sc-goal-line { font-size: 18px; }
.sc-why { margin: 2px 0 8px; }
.sc-guide ul { margin: 0; padding-left: 22px; }
.sc-guide li { margin: 4px 0; }
.sc-red { color: #B91C1C; }
</style>
<div class="sc-wrap">
@include('report._count_tabs', ['ctOn' => 'count'])
    <h1>Shelf count</h1>
    <div class="sc-guide">
        <div class="sc-goal-line"><b>Goal:</b> the ERP shows exactly what we have in each store.</div>
        <div class="sc-why">This helps us with 5-star customer service, organization, ordering and tracking theft.</div>
        <ul>
            <li>Pick the bin you're standing at.</li>
            <li>Scan every record in it, or tap it in the list.</li>
            <li><b>If a title shows in a bin in the ERP but it is not physically there, please tap None to mark it 0.</b></li>
            <li><b class="sc-red">If the record is in the wrong bin in the ERP, please fix it!</b> Tap <b>Genre</b> and pick the right one, so we know exactly where to find things.</li>
            <li>If you need an edit to this page to make your inventory count easier, please tap <b>Report a bug</b> at the bottom right and we will fix it.</li>
        </ul>
    </div>
    <form class="sc-row" method="get">
        <select name="location_id" onchange="this.form.submit()">@foreach ($locations as $id => $nm)<option value="{{ $id }}" {{ $id == $loc ? 'selected' : '' }}>{{ ucfirst($nm) }}</option>@endforeach</select>
        <select name="kind" onchange="this.form.submit()">@foreach (($kind === 'all' ? $kinds : ['all' => 'All shelves'] + $kinds) as $k => $l)<option value="{{ $k }}" {{ $kind === $k ? 'selected' : '' }}>{{ $l }}</option>@endforeach</select>
        <select name="sort" onchange="this.form.submit()">@foreach (($sorts ?? []) as $k => $l)<option value="{{ $k }}" {{ ($sort ?? 'priority') === $k ? 'selected' : '' }}>Sort: {{ $l }}</option>@endforeach</select>
    </form>
    @if (!empty($overall))
    @php $ovPct = $overall['titles'] ? min(100, (int) round(100 * $overall['counted'] / $overall['titles'])) : 0; @endphp
    <div class="sc-overall">
        <span class="sc-ov-pct">{{ $ovPct }}%</span>
        <b>All {{ $overall['name'] }}{{ $overall['sealed'] ? ': year-end count' : '' }}</b>
        <div class="sc-bar sc-ov-bar"><span style="width:{{ $ovPct }}%"></span></div>
        <div class="sc-line">{{ number_format($overall['counted']) }} of {{ number_format($overall['titles']) }} titles counted{{ $overall['sealed'] ? ' (must be done by 12/31/26)' : ' in the last ' . \App\Http\Controllers\ShelfCountController::DUE_DAYS . ' days' }}</div>
    </div>
    @endif
    <div class="sc-grid">
    @php $scCut = now()->subDays(\App\Http\Controllers\ShelfCountController::DUE_DAYS)->toDateTimeString(); @endphp
    @foreach ($bins as $b)
        @php $scRecent = !empty($b['last']) && $b['last'] >= $scCut; $scDone = $scRecent && ($b['recent'] ?? 0) >= 0.8 * $b['titles']; $scPct = $b['titles'] ? min(100, (int) round(100 * ($b['recent'] ?? 0) / $b['titles'])) : 0; @endphp
        <a class="sc-bin{{ !empty($b['priority']) ? ' sc-hot' : '' }}{{ $scDone && empty($b['priority']) ? ' sc-done' : '' }}" href="/reports/shelf-count/bin?location_id={{ $loc }}&kind={{ $b['kind'] ?? $kind }}&sub={{ $b['sub'] }}">
            @if ($scDone && empty($b['priority']))<span class="sc-done-tag">DONE</span>@else<span class="sc-pct">{{ $scPct }}%</span>@endif
            <b>{{ $b['name'] }}</b>
            <div class="sc-bar"><span style="width:{{ $scPct }}%"></span></div>
            <div class="sc-line">{{ number_format((int) ($b['recent'] ?? 0)) }} of {{ number_format($b['titles']) }} counted</div>
            <div class="sc-line">{{ !empty($b['last']) ? 'Last count ' . \Carbon\Carbon::parse($b['last'])->format('m/d/y') . ($scRecent ? '' : ', due again') : 'Never counted' }}</div>
            @if (!empty($b['priority']))<span class="sc-flag">High priority: {{ $b['web'] }} online {{ $b['web'] == 1 ? 'order' : 'orders' }}{{ !empty($b['web_bad']) ? ', ' . $b['web_bad'] . " cancelled or can't find" : '' }}</span>@endif
        </a>
    @endforeach
    </div>
</div>
@endsection
