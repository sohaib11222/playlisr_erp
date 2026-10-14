@extends('layouts.app')
@section('title', 'Shelf Count')

@section('content')
<style>
.sh-wrap { max-width: 1280px; margin: 0 auto; padding: 10px 16px 40px; color: #1F1B16; }
.sh-wrap h1 { font-size: 22px; font-weight: 700; margin: 0 0 2px; }
.sh-sub { color: #6B6155; font-size: 14px; margin: 0 0 8px; }
.sh-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 8px; }
.sh-tile { display: block; background: #fff; border: 1px solid #E6DCCF; border-radius: 10px; padding: 9px 11px; text-decoration: none; color: #1F1B16; }
.sh-tile b { font-size: 15px; display: block; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sh-bar { height: 5px; background: #F1EADF; border-radius: 3px; margin: 5px 0 4px; overflow: hidden; }
.sh-bar span { display: block; height: 100%; background: #2E6B2E; }
.sh-small { color: #8E8273; font-size: 12px; }
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
    <div class="sh-grid">
        <a class="sh-tile" href="/reports/reorder?format=comics&location_id={{ $loc }}"><b>Comics &amp; magazines</b><div class="sh-bar"></div><span class="sh-small">Counted by series</span></a>
        @foreach ($tiles as $t)
            @php $pct = $t['shelves'] ? round(100 * ($t['done'] ?? 0) / $t['shelves']) : 0; @endphp
            <a class="sh-tile" href="/reports/shelf-count?location_id={{ $loc }}&kind={{ $t['kind'] }}">
                <b>{{ $t['name'] }}</b>
                <div class="sh-bar"><span style="width:{{ $pct }}%"></span></div>
                <span class="sh-small">{{ $t['done'] ?? 0 }}/{{ $t['shelves'] }} shelves{{ !empty($t['last']) ? ', last ' . \Carbon\Carbon::parse($t['last'])->format('n/j') : ', never' }}</span>
            </a>
        @endforeach
    </div>
</div>
@endsection
