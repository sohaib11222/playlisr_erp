@extends('layouts.app')
@section('title', 'Shelf Count')

@section('content')
<style>
.sh-wrap { max-width: 1280px; margin: 0 auto; padding: 10px 16px 40px; color: #1F1B16; }
.sh-wrap h1 { font-size: 22px; font-weight: 700; margin: 0 0 2px; }
.sh-sub { color: #2B2620; font-size: 14px; margin: 0 0 8px; }
.sh-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 8px; }
.sh-tile { display: block; background: #fff; border: 1px solid #E6DCCF; border-radius: 10px; padding: 9px 11px; text-decoration: none; color: #1F1B16; }
.sh-tile b { font-size: 15px; display: block; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sh-bar { height: 5px; background: #F1EADF; border-radius: 3px; margin: 5px 0 4px; overflow: hidden; }
.sh-bar span { display: block; height: 100%; background: #2E6B2E; }
.sh-small { color: #2B2620; font-size: 12px; }
.sh-row { display: flex; gap: 8px; margin: 0 0 10px; align-items: center; }
.sh-row select { height: 40px; font-size: 15px; border: 1px solid #E1D7C4; border-radius: 10px; padding: 0 10px; }
</style>
<div class="sh-wrap">
    @include('report._count_tabs', ['ctOn' => 'count'])
    <h1>What are you counting?</h1>
    <p class="sh-sub">{{ $done }} of {{ $shelves }} shelves counted in the last {{ $dueDays }} days. Pick a type, then pick the shelf you're standing at.</p>
    <form class="sh-row" method="get">
        <select name="location_id" onchange="this.form.submit()">@foreach ($locations as $id => $nm)<option value="{{ $id }}" {{ $id == $loc ? 'selected' : '' }}>{{ ucfirst($nm) }}</option>@endforeach</select>
        <a class="sh-small" href="/reports/shelf-count?location_id={{ $loc }}&kind=all">See every shelf and its last count</a>
    </form>
    @if (!empty($yearEnd))
    <div style="background:#EAF3FB;border:2px solid #2B6CB0;border-radius:12px;padding:12px 14px;margin:0 0 12px">
        <b style="font-size:17px;display:block;margin-bottom:2px">Year-end count: do sealed first</b>
        <span class="sh-small" style="font-size:14px">For taxes we need every sealed record, CD and cassette counted by 12/31/26. Count these before anything else.</span>
        <div class="sh-grid" style="margin-top:8px">
        @foreach ($yearEnd as $y)
            @php $yp = $y['titles'] ? min(100, round(100 * $y['counted'] / $y['titles'])) : 0; @endphp
            <a class="sh-tile" style="border-color:#2B6CB0" href="/reports/shelf-count?location_id={{ $loc }}&kind={{ $y['kind'] }}">
                <b>{{ $y['name'] }}</b>
                <div class="sh-bar"><span style="width:{{ $yp }}%"></span></div>
                <span class="sh-small">{{ number_format($y['counted']) }} of {{ number_format($y['titles']) }} titles counted ({{ $yp }}%)</span>
            </a>
        @endforeach
        </div>
    </div>
    @endif
    <div class="sh-grid">
        <a class="sh-tile" href="/reports/reorder?format=comics&location_id={{ $loc }}">@php $cp = $comics['series'] ? min(100, round(100 * $comics['recent'] / $comics['series'])) : 0; @endphp<b>Comics &amp; magazines</b><div class="sh-bar"><span style="width:{{ $cp }}%"></span></div><span class="sh-small">{{ $comics['recent'] }}/{{ $comics['series'] }} series{{ $comics['last'] ? ', last ' . \Carbon\Carbon::parse($comics['last'])->format('m/d/y') : ', never' }}</span></a>
        @foreach ($tiles as $t)
            @php $pct = $t['shelves'] ? round(100 * ($t['done'] ?? 0) / $t['shelves']) : 0; @endphp
            <a class="sh-tile" href="/reports/shelf-count?location_id={{ $loc }}&kind={{ $t['kind'] }}">
                <b>{{ $t['name'] }}</b>
                <div class="sh-bar"><span style="width:{{ $pct }}%"></span></div>
                <span class="sh-small">{{ $t['done'] ?? 0 }}/{{ $t['shelves'] }} shelves{{ !empty($t['last']) ? ', last ' . \Carbon\Carbon::parse($t['last'])->format('m/d/y') : ', never' }}</span>
                @if (!empty($t['priority']))<br><span class="sh-small" style="color:#8E1B1B;font-weight:700">{{ $t['priority'] }} high priority {{ $t['priority'] == 1 ? 'shelf' : 'shelves' }}</span>@endif
            </a>
        @endforeach
    </div>
</div>
@endsection
