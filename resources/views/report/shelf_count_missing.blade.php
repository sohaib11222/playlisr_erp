@extends('layouts.app')
@section('title', 'Missing List')

@section('content')
<style>
.sc-wrap { max-width: 1000px; margin: 0 auto; padding: 24px 16px 60px; color: #1F1B16; }
.sc-wrap h1 { font-size: 26px; font-weight: 700; margin: 0 0 6px; }
.sc-wrap h2 { font-size: 18px; margin: 22px 0 8px; }
.sc-sub { color: #6B6155; font-size: 15px; margin: 0 0 14px; }
.sc-bar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 14px; }
.sc-pill { border: 1px solid #E1D7C4; background: #fff; border-radius: 999px; padding: 6px 14px; font-weight: 600; color: #1F1B16; text-decoration: none; }
.sc-pill.on { background: #1F1B16; color: #FFE8A3; border-color: #1F1B16; }
.sc-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #E6DCCF; font-size: 14px; }
.sc-table th { text-align: left; background: #FAF6EE; padding: 8px 10px; font-size: 13px; color: #6B6155; }
.sc-table td { padding: 8px 10px; border-top: 1px solid #F3ECE1; }
</style>
<div class="sc-wrap">
    <h1>Missing list</h1>
    @php $tu = array_sum(array_column($rows, 'units')); $tc = array_sum(array_map(function ($r) { return $r['units'] * $r['unit_cost']; }, $rows)); @endphp
    <p class="sc-sub">Copies the ERP had that weren't on the shelf when counted, last {{ $days }} days: {{ $tu }} copies, about ${{ number_format($tc, 2) }} at cost. Some of it is old bad data rather than theft, especially on a section's first count.</p>
    <div class="sc-bar">
        @foreach ($locations as $id => $n)
            <a class="sc-pill {{ $id == $loc ? 'on' : '' }}" href="?location_id={{ $id }}&days={{ $days }}">{{ $n }}</a>
        @endforeach
        <a class="sc-pill" href="/reports/shelf-count?location_id={{ $loc }}">Back to shelf count</a>
    </div>
    <h2>Most missing, by artist</h2>
    <table class="sc-table"><thead><tr><th>Artist</th><th>Copies missing</th><th>Times short</th><th>Cost</th></tr></thead><tbody>
        @forelse (array_slice($byArtist, 0, 30) as $a)
            <tr><td>{{ $a['artist'] }}</td><td>{{ $a['units'] }}</td><td>{{ $a['times'] }}</td><td>${{ number_format($a['cost'], 2) }}</td></tr>
        @empty
            <tr><td colspan="4">Nothing missing yet.</td></tr>
        @endforelse
    </tbody></table>
    <h2>Every missing count</h2>
    <table class="sc-table"><thead><tr><th>When</th><th>Title</th><th>Section</th><th>Missing</th><th>Cost</th><th>Counted by</th></tr></thead><tbody>
        @foreach ($rows as $r)
            <tr><td>{{ \Carbon\Carbon::parse($r['at'])->format('n/j') }}</td><td><a href="/products/{{ $r['product_id'] }}/edit" target="_blank">{{ $r['name'] }}</a></td>
                <td>{{ $r['format'] }}{{ $r['genre'] ? ' / ' . $r['genre'] : '' }}</td><td>{{ $r['units'] }}</td><td>${{ number_format($r['units'] * $r['unit_cost'], 2) }}</td><td>{{ $r['by'] }}</td></tr>
        @endforeach
    </tbody></table>
</div>
@endsection
