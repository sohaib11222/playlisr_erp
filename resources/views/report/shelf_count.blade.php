@extends('layouts.app')
@section('title', 'Shelf Count')

@section('content')
<style>
.sc-wrap { max-width: 900px; margin: 0 auto; padding: 24px 16px 60px; color: #1F1B16; }
.sc-wrap h1 { font-size: 26px; font-weight: 700; margin: 0 0 6px; }
.sc-sub { color: #6B6155; font-size: 15px; margin: 0 0 14px; }
.sc-bar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
.sc-pill { border: 1px solid #E1D7C4; background: #fff; border-radius: 999px; padding: 6px 14px; font-weight: 600; color: #1F1B16; text-decoration: none; }
.sc-pill.on { background: #1F1B16; color: #FFE8A3; border-color: #1F1B16; }
.sc-table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #E6DCCF; border-radius: 10px; overflow: hidden; font-size: 15px; }
.sc-table th { text-align: left; background: #FAF6EE; padding: 9px 12px; font-size: 13px; color: #6B6155; }
.sc-table td { padding: 10px 12px; border-top: 1px solid #F3ECE1; }
.sc-due { color: #8E1B1B; font-weight: 700; }
.sc-ok { color: #2E6B2E; }
</style>
<div class="sc-wrap">
    <h1>Shelf count</h1>
    <p class="sc-sub">Count every section once every {{ $dueDays }} days. Open a section, count each title in the bin (check the wall and shelves above too), and type the number. The ERP and website update right away, and anything missing goes on the <a href="/reports/shelf-count/missing?location_id={{ $loc }}">Missing list</a>.</p>
    <div class="sc-bar">
        @foreach ($locations as $id => $n)
            <a class="sc-pill {{ $id == $loc ? 'on' : '' }}" href="?location_id={{ $id }}">{{ $n }}</a>
        @endforeach
        <a class="sc-pill" href="/reports/shelf-count/missing?location_id={{ $loc }}">Missing list</a>
    </div>
    <table class="sc-table">
        <thead><tr><th>Section</th><th>Titles</th><th>Copies (ERP)</th><th>Last counted</th><th></th></tr></thead>
        <tbody>
        @foreach ($sections as $s)
            <tr>
                <td><a href="/reports/shelf-count/section?location_id={{ $loc }}&cat={{ $s['cat_id'] }}&sub={{ $s['sub_id'] }}">{{ $s['name'] }}</a></td>
                <td>{{ number_format($s['titles']) }}</td>
                <td>{{ number_format($s['units']) }}</td>
                <td>{{ $s['last'] ? \Carbon\Carbon::parse($s['last'])->format('n/j') . ' (' . $s['counted'] . ' of ' . $s['titles'] . ')' : 'Never' }}</td>
                <td class="{{ $s['due'] ? 'sc-due' : 'sc-ok' }}">{{ $s['due'] ? 'Due' : 'Done' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
