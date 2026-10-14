@extends('layouts.app')
@section('title', 'Shelf Count')

@section('content')
<style>
.sc-wrap { max-width: 760px; margin: 0 auto; padding: 24px 16px 60px; color: #1F1B16; }
.sc-wrap h1 { font-size: 26px; font-weight: 700; margin: 0 0 6px; }
.sc-sub { color: #6B6155; font-size: 15px; margin: 0 0 14px; }
.sc-row { display: flex; gap: 8px; margin: 0 0 12px; }
.sc-row select { height: 44px; font-size: 16px; border: 1px solid #E1D7C4; border-radius: 10px; padding: 0 10px; flex: 1; }
.sc-bin { display: flex; justify-content: space-between; align-items: center; background: #fff; border: 1px solid #E6DCCF; border-radius: 10px; padding: 12px 14px; margin-bottom: 8px; text-decoration: none; color: #1F1B16; }
.sc-bin b { font-size: 16px; }
.sc-small { color: #8E8273; font-size: 13px; }
.sc-due { color: #8E1B1B; font-weight: 700; font-size: 13px; }
</style>
<div class="sc-wrap">
    <h1>Shelf count</h1>
    <p class="sc-sub">Pick the bin you're standing at. Then scan every record in it (or tap it in the list). When the bin is done, the page shows what the ERP thought was there but wasn't. Bins not counted the longest are on top. <a href="/reports/shelf-count/missing?location_id={{ $loc }}">Missing list</a></p>
    <form class="sc-row" method="get">
        <select name="location_id" onchange="this.form.submit()">@foreach ($locations as $id => $nm)<option value="{{ $id }}" {{ $id == $loc ? 'selected' : '' }}>{{ ucfirst($nm) }}</option>@endforeach</select>
        <select name="kind" onchange="this.form.submit()">@foreach (['vinyl-new' => 'New vinyl', 'vinyl-used' => 'Used vinyl', 'cd-new' => 'New CDs', 'cd-used' => 'Used CDs'] as $k => $l)<option value="{{ $k }}" {{ $kind === $k ? 'selected' : '' }}>{{ $l }}</option>@endforeach</select>
    </form>
    @foreach ($bins as $b)
        <a class="sc-bin" href="/reports/shelf-count/bin?location_id={{ $loc }}&kind={{ $kind }}&sub={{ $b['sub'] }}">
            <span><b>{{ $b['name'] }}</b><br><span class="sc-small">{{ number_format($b['titles']) }} titles in the ERP</span></span>
            <span>@if (!empty($b['last']))<span class="sc-small">Counted {{ \Carbon\Carbon::parse($b['last'])->format('n/j') }}</span>@else<span class="sc-due">Never counted</span>@endif</span>
        </a>
    @endforeach
    <p class="sc-small">Comics and magazines are counted on <a href="/reports/reorder?format=comics&location_id={{ $loc }}">their own page</a>.</p>
</div>
@endsection
