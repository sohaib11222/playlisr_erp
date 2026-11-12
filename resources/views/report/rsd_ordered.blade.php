@extends('layouts.app')
@section('title', 'RSD Order')

@section('content')
@php
    $distColor = ['AMS' => '#1565c0', 'Alliance' => '#c62828', 'Redeye' => '#2e7d32', 'Secretly' => '#6a1b9a'];
    $d = function ($k) use ($distColor) { return '<span style="color:' . ($distColor[$k] ?? '#222') . ';font-weight:600">' . e($k) . '</span>'; };
    $date = function ($v) { return $v ? \Carbon\Carbon::parse($v)->format('m/d/y') : ''; };
@endphp
<style>
    .rso td, .rso th { color: #222; vertical-align: top !important; }
    .rso .n { text-align: center; white-space: nowrap; }
    .rso .hw { background: #fff8f2; }
    .rso .pico { background: #f5f3ff; }
    .rso .zero { color: #222; }
    .rso-tot td { font-weight: 700; border-top: 2px solid #222 !important; }
    .rso-sort { cursor: pointer; user-select: none; }
    .rso-sort:hover { text-decoration: underline; }
    .rso-pill { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #e3f2fd; color: #0d47a1; font-size: 12px; font-weight: 600; }
</style>
<section class="content-header">
    <h1>{{ $event }}: what customers asked for and what we ordered</h1>
</section>
<section class="content">
    <div class="box box-solid">
        <div class="box-body">
            <p style="margin:0 0 10px;">Street date {{ $date($eventDate) }}. Orders placed 10/19/26 and 10/20/26. Updated {{ $date($asOf) }}.</p>
            <div class="row">
            @foreach($stores as $st => $label)
                @php $t = $tot[$st] ?? ['req' => 0, 'qty' => 0, 'by' => []]; @endphp
                <div class="col-sm-6">
                    <h4 style="margin:4px 0;"><b>{{ $label }}</b></h4>
                    <p style="margin:0;">{{ $t['req'] }} customer requests, {{ $t['qty'] }} copies ordered:
                        @foreach($t['by'] ?? [] as $k => $n){!! $d($k) !!} {{ $n }}@if(!$loop->last), @endif @endforeach
                    </p>
                </div>
            @endforeach
            </div>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border"><b>Where each distributor is</b></div>
        <div class="box-body">
            <table class="table table-condensed rso" style="margin:0;">
                @foreach($status as [$k, $text, $pill])
                    <tr><td style="width:110px;">{!! $d($k) !!}</td><td style="width:160px;"><span class="rso-pill">{{ $pill }}</span></td><td>{{ $text }}</td></tr>
                @endforeach
            </table>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-body table-responsive">
            <table class="table table-bordered table-condensed rso" id="rso-table">
                <thead>
                    <tr>
                        <th class="rso-sort" data-col="0" data-type="text">Album <span class="rso-arrow"></span></th>
                        <th class="n hw rso-sort" data-col="1">Hollywood customer requests <span class="rso-arrow"></span></th>
                        <th class="n hw rso-sort" data-col="2">Hollywood ordered <span class="rso-arrow"></span></th>
                        <th class="n pico rso-sort" data-col="3">Pico customer requests <span class="rso-arrow"></span></th>
                        <th class="n pico rso-sort" data-col="4">Pico ordered <span class="rso-arrow"></span></th>
                    </tr>
                </thead>
                <tbody>
                @foreach($rows as $r)
                    <tr>
                        <td data-v="{{ strtolower($r['artist'] . ' ' . $r['title']) }}"><b>{{ $r['artist'] }}</b><br>{{ $r['title'] }}@if($r['note'])<br><i>{{ $r['note'] }}</i>@endif</td>
                        @foreach(['hollywood' => 'hw', 'pico' => 'pico'] as $st => $cls)
                            @php $c = $r[$st]; @endphp
                            <td class="n {{ $cls }}" data-v="{{ $c['req'] }}">{{ $c['req'] ?: '' }}</td>
                            <td class="n {{ $cls }}" data-v="{{ $c['qty'] }}">
                                @if($c['qty'])<b>{{ $c['qty'] }}</b>
                                    @foreach($c['by'] as $k => $n)<div style="font-size:12px">{!! $d($k) !!} {{ $n }}</div>@endforeach
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                    <tr class="rso-tot">
                        <td>Total</td>
                        @foreach(['hollywood' => 'hw', 'pico' => 'pico'] as $st => $cls)
                            <td class="n {{ $cls }}">{{ $tot[$st]['req'] ?? 0 }}</td>
                            <td class="n {{ $cls }}">{{ $tot[$st]['qty'] ?? 0 }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</section>
<script>
(function () {
    var table = document.getElementById('rso-table');
    if (!table) return;
    var body = table.tBodies[0], state = {};
    table.querySelectorAll('th.rso-sort').forEach(function (th) {
        th.addEventListener('click', function () {
            var col = +th.dataset.col, text = th.dataset.type === 'text';
            var dir = state.col === col ? -state.dir : (text ? 1 : -1);
            state = {col: col, dir: dir};
            var rows = Array.prototype.slice.call(body.rows);
            rows.sort(function (a, b) {
                var x = a.cells[col].dataset.v, y = b.cells[col].dataset.v;
                var c = text ? x.localeCompare(y) : (+x - +y);
                return c * dir || a.cells[0].dataset.v.localeCompare(b.cells[0].dataset.v);
            });
            rows.forEach(function (r) { body.appendChild(r); });
            table.querySelectorAll('.rso-arrow').forEach(function (s) { s.textContent = ''; });
            th.querySelector('.rso-arrow').textContent = dir > 0 ? '\u25B2' : '\u25BC';
        });
    });
})();
</script>
@endsection
