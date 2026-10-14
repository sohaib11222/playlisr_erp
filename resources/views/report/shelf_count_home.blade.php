@extends('layouts.app')
@section('title', 'Shelf Count')

@section('content')
<style>
.sh-wrap { max-width: 820px; margin: 0 auto; padding: 18px 16px 60px; color: #1F1B16; }
.sh-wrap h1 { font-size: 26px; font-weight: 700; margin: 0 0 4px; }
.sh-sub { color: #6B6155; font-size: 15px; margin: 0 0 14px; }
.sh-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 10px; }
.sh-tile { display: block; background: #fff; border: 1px solid #E6DCCF; border-radius: 12px; padding: 14px; text-decoration: none; color: #1F1B16; }
.sh-tile b { font-size: 17px; display: block; margin-bottom: 4px; }
.sh-bar { height: 6px; background: #F1EADF; border-radius: 3px; margin: 8px 0 6px; overflow: hidden; }
.sh-bar span { display: block; height: 100%; background: #2E6B2E; }
.sh-small { color: #8E8273; font-size: 13px; }
.sh-row { display: flex; gap: 8px; margin: 0 0 14px; align-items: center; }
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
        <a class="sh-tile" href="/reports/reorder?format=comics&location_id={{ $loc }}"><b>Comics &amp; magazines</b><span class="sh-small">Counted by series</span></a>
        @foreach ($tiles as $t)
            @php $pct = $t['shelves'] ? round(100 * ($t['done'] ?? 0) / $t['shelves']) : 0; @endphp
            <a class="sh-tile" href="/reports/shelf-count?location_id={{ $loc }}&kind={{ $t['kind'] }}">
                <b>{{ $t['name'] }}</b>
                <div class="sh-bar"><span style="width:{{ $pct }}%"></span></div>
                <span class="sh-small">{{ $t['done'] ?? 0 }} of {{ $t['shelves'] }} shelves counted{{ !empty($t['last']) ? ', last ' . \Carbon\Carbon::parse($t['last'])->format('n/j') : ', never counted' }}</span>
            </a>
        @endforeach
    </div>
</div>
@endsection
