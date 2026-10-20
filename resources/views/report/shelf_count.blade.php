@extends('layouts.app')
@section('title', 'Shelf Count')

@section('content')
<style>
.sc-wrap { max-width: 760px; margin: 0 auto; padding: 24px 16px 60px; color: #1F1B16; }
.sc-wrap h1 { font-size: 26px; font-weight: 700; margin: 0 0 6px; }
.sc-sub { color: #2B2620; font-size: 15px; margin: 0 0 14px; }
.sc-row { display: flex; gap: 8px; margin: 0 0 12px; }
.sc-row select { height: 44px; font-size: 16px; border: 1px solid #E1D7C4; border-radius: 10px; padding: 0 10px; flex: 1; }
.sc-bin { display: flex; justify-content: space-between; align-items: center; background: #fff; border: 1px solid #E6DCCF; border-radius: 10px; padding: 12px 14px; margin-bottom: 8px; text-decoration: none; color: #1F1B16; }
.sc-bin b { font-size: 16px; }
.sc-small { color: #2B2620; font-size: 13px; }
.sc-due { color: #8E1B1B; font-weight: 700; font-size: 13px; }
.sc-web { display: inline-block; margin-top: 4px; background: #FBE3E3; color: #8E1B1B; border-radius: 6px; padding: 2px 8px; font-size: 13px; font-weight: 700; }
</style>
<div class="sc-wrap">
@include('report._count_tabs', ['ctOn' => 'count'])
    <h1>Shelf count</h1>
    <p class="sc-sub">Pick the bin you're standing at. Then scan every record in it (or tap it in the list). When the bin is done, the page shows what the ERP thought was there but wasn't. Bins not counted the longest are on top. High priority = sold online since its last count. <a href="/reports/shelf-count/missing?location_id={{ $loc }}">Missing list</a></p>
    <form class="sc-row" method="get">
        <select name="location_id" onchange="this.form.submit()">@foreach ($locations as $id => $nm)<option value="{{ $id }}" {{ $id == $loc ? 'selected' : '' }}>{{ ucfirst($nm) }}</option>@endforeach</select>
        <select name="kind" onchange="this.form.submit()">@foreach (($kind === 'all' ? $kinds : ['all' => 'All shelves'] + $kinds) as $k => $l)<option value="{{ $k }}" {{ $kind === $k ? 'selected' : '' }}>{{ $l }}</option>@endforeach</select>
    </form>
    @foreach ($bins as $b)
        <a class="sc-bin" href="/reports/shelf-count/bin?location_id={{ $loc }}&kind={{ $b['kind'] ?? $kind }}&sub={{ $b['sub'] }}">
            <span><b>{{ $b['name'] }}</b><br><span class="sc-small">{{ number_format($b['titles']) }} titles in the ERP{{ !empty($b['counted']) ? ', ' . $b['counted'] . ' counted' : '' }}</span>@if (!empty($b['web']))<br><span class="sc-web">High priority: {{ $b['web'] }} online {{ $b['web'] == 1 ? 'order' : 'orders' }} for titles not counted since{{ !empty($b['web_bad']) ? ' (' . $b['web_bad'] . " cancelled or can't find)" : '' }}</span>@endif</span>
            <span>@if (!empty($b['last']))<span class="sc-small">Counted {{ \Carbon\Carbon::parse($b['last'])->format('m/d/y') }}</span>@else<span class="sc-due">Never counted</span>@endif</span>
        </a>
    @endforeach
    <p class="sc-small">Comics and magazines are counted on <a href="/reports/reorder?format=comics&location_id={{ $loc }}">their own page</a>.</p>
</div>
@endsection
