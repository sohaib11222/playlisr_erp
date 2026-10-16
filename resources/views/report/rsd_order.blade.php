@extends('layouts.app')
@section('title', 'RSD Order')

@section('content')
@php
    $distColor = ['ams' => '#1565c0', 'alliance' => '#c62828', 'redeye' => '#2e7d32', 'secretly' => '#6a1b9a', 'matador' => '#ef6c00', 'monostereo' => '#00838f', 'deejay' => '#5d4037'];
    $dist = function ($key, $label) use ($distColor) { return '<span style="color:' . ($distColor[$key] ?? '#555') . ';font-weight:600">' . e($label) . '</span>'; };
    $money = function ($v) { return $v === null ? '' : '$' . number_format((float) $v, 2); };
@endphp
<style>
    .ro-bar { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
    .ro-bar .form-group { margin:0; }
    table.rsd-table { width:auto; max-width:100%; font-size:14px; }
    table.rsd-table td:first-child { width:300px; max-width:300px; }
    table.rsd-table th.n, table.rsd-table td.n { width:120px; }
    table.rsd-table th { background:#f4f4f4; font-size:12px; line-height:1.2; vertical-align:bottom !important; position:sticky; top:0; z-index:1; }
    table.rsd-table td { vertical-align:middle !important; padding:6px 5px !important; }
    table.rsd-table td.n, table.rsd-table th.n { text-align:center; }
    table.rsd-table .hw { background:#f3f9ff; }
    table.rsd-table th.hw { background:#e3f2fd; }
    table.rsd-table .pico { background:#fff8ef; }
    table.rsd-table th.pico { background:#ffe9cc; }
    .rsd-cart-hw { border-top:4px solid #1565c0; }
    .rsd-cart-pico { border-top:4px solid #ef6c00; }
    .rsd-artist { font-weight:bold; }
    .rsd-num { width:56px; text-align:center; display:inline-block; }
    .rsd-req { border:1px solid transparent; background:transparent; font-size:15px; }
    .rsd-req:hover, .rsd-req:focus { border-color:#ccc; background:#fff; }
    .rsd-upc { width:120px; font-size:12px; }
    .rsd-muted { color:#888; font-size:12px; }
    .rsd-tag { display:inline-block; font-size:11px; border-radius:9px; padding:0 7px; color:#fff; background:#757575; }
    .rsd-wrap { max-height:75vh; overflow:auto; }
    .rsd-list { font-family:monospace; font-size:13px; width:100%; }
    tr.rsd-ordered td { background:#f1f8e9 !important; }
    tr.rsd-none td { color:#999; }
</style>
<section class="content-header">
    <h1>RSD Order <small>{{ $event }} (Fri {{ \Carbon\Carbon::parse($eventDate)->format('M j') }})</small></h1>
</section>
<section class="content">
    <div class="box box-solid">
        <div class="box-body">
            <div class="ro-bar">
                <div class="form-group">
                    <label>Format</label>
                    <select class="form-control" onchange="location.href=this.value">
                        <option value="{{ action('ReorderController@index', ['location_id' => $locationId, 'format' => 'vinyl']) }}">Sealed vinyl</option>
                        <option value="{{ action('ReorderController@index', ['location_id' => $locationId, 'format' => 'cd']) }}">Sealed CDs</option>
                        <option value="{{ action('ReorderController@index', ['location_id' => $locationId, 'format' => 'cassette']) }}">Sealed cassettes</option>
                        <option value="{{ action('ReorderController@index', ['location_id' => $locationId, 'format' => 'other']) }}">Everything else (apparel, toys, books...)</option>
                        <option value="" selected>RSD Black Friday order</option>
                    </select>
                </div>
            </div>
            <p class="rsd-muted" style="margin:10px 0 0; font-size:13px;">Cart starts at 2 copies per request (3 per request once a store has 3+ asks), plus 1 for every 5 copies of that artist the store sold in the last 12 months. Titles nobody asked for show up when we sold 3+ of that artist.</p>
            <p style="margin:10px 0 0; font-size:15px;">
                <b>Hollywood cart:</b> {{ $carts['hollywood']['qty'] }} copies, {{ $money($carts['hollywood']['cost']) }}.
                &nbsp; <b>Pico cart:</b> {{ $carts['pico']['qty'] }} copies, {{ $money($carts['pico']['cost']) }}.
            </p>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-body rsd-wrap">
            <table class="table table-bordered rsd-table" id="rsd-table">
                <thead>
                    <tr>
                        <th>Album</th>
                        <th class="n hw">Hollywood customer requests</th>
                        <th class="n hw">Hollywood sold this artist, last 12 months</th>
                        <th class="n hw">Hollywood cart</th>
                        <th class="n pico">Pico customer requests</th>
                        <th class="n pico">Pico sold this artist, last 12 months</th>
                        <th class="n pico">Pico cart</th>
                        <th>Cheapest price</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($rows as $r)
                    @php $others = array_filter($r['prices'], function ($e) use ($r) { return !$r['best'] || $e['supplier_key'] !== $r['best']['supplier_key']; }); @endphp
                    <tr data-key="{{ $r['key'] }}">
                        <td>
                            <span class="rsd-artist">{{ $r['artist'] }}</span><br>{{ $r['title'] }}
                            @if(!$r['is_rsd']) <span class="rsd-tag">Regular release</span> @endif
                            @if($r['from_sales']) <span class="rsd-tag" style="background:#1565c0">From our sales</span> @endif
                        </td>
                        @foreach(['hollywood' => 'hw', 'pico' => 'pico'] as $st => $cls)
                            @php $c = $r['carts'][$st]; @endphp
                            <td class="n {{ $cls }}"><input type="number" min="0" class="rsd-num rsd-req" data-field="{{ $st }}" value="{{ $r[$cls] }}"></td>
                            <td class="n {{ $cls }}">{{ $c['sold'] ?: '' }}@if($c['sold_rsd'])<div class="rsd-muted">{{ $c['sold_rsd'] }} were RSD</div>@endif</td>
                            <td class="n {{ $cls }}">
                                <input type="number" min="0" class="form-control input-sm rsd-num rsd-qty" data-store="{{ $st }}" value="{{ $c['qty'] }}" data-suggested="{{ $c['suggested'] }}">
                                @if($c['ordered'])<div class="rsd-muted">ordered {{ \Carbon\Carbon::parse($c['ordered']['at'])->format('M j') }}</div>@endif
                            </td>
                        @endforeach
                        <td>
                            @if($r['best'])
                                <b>{{ $money($r['best']['cost']) }}</b> {!! $dist($r['best']['supplier_key'], $r['best']['supplier_label']) !!}
                                @foreach($others as $op)<div class="rsd-muted">{{ $money($op['cost']) }} {!! $dist($op['supplier_key'], $op['supplier_label']) !!} @if($op['in_stock'] === false) (sold out)@endif</div>@endforeach
                            @else
                                <span class="rsd-muted">not listed yet</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border"><b>Add a customer request</b></div>
        <div class="box-body">
            <form method="POST" action="{{ action('RsdOrderController@addRequest') }}" class="ro-bar">
                @csrf
                <div class="form-group"><label>Artist</label><input name="artist" class="form-control" placeholder="Artist"></div>
                <div class="form-group"><label>Title</label><input name="title" class="form-control" placeholder="Title"></div>
                <div class="form-group"><label>Store</label>
                    <select name="where" class="form-control">
                        <option value="hollywood" selected>Hollywood</option>
                        <option value="pico">Pico</option>
                    </select>
                </div>
                <div class="form-group"><label><input type="checkbox" name="regular" value="1"> Not an RSD title</label></div>
                <div class="form-group"><button type="submit" class="btn btn-primary">Add</button></div>
            </form>
            <p class="rsd-muted" style="margin:6px 0 0;">Same artist and title as a row above adds 1 to that row.</p>
        </div>
    </div>

    @foreach($stores as $st => $stLabel)
    @php $cart = $carts[$st]; @endphp
    <div class="box box-solid rsd-cart-{{ $st === 'hollywood' ? 'hw' : 'pico' }}">
        <div class="box-header with-border"><h3 class="box-title"><b>{{ $stLabel }} cart</b>: {{ $cart['qty'] }} copies, {{ $money($cart['cost']) }}</h3></div>
        <div class="box-body">
        <div class="row">
        @foreach($cart['byDist'] as $k => $list)
            @php $sum = 0; foreach ($list as $r) $sum += $r['qty'] * $r['prices'][$k]['cost']; @endphp
            <div class="col-md-6">
                <p style="margin:0 0 4px;"><strong>{!! $dist($k, $suppliers[$k] ?? $k) !!}</strong>: {{ count($list) }} titles, {{ array_sum(array_column($list, 'qty')) }} copies, {{ $money($sum) }}</p>
<textarea class="rsd-list" rows="{{ min(14, count($list) + 1) }}" readonly>@foreach($list as $r){{ $r['upcs'][0] ?? '' }}	{{ $r['qty'] }}	{{ $r['artist'] }} - {{ $r['title'] }}
@endforeach</textarea>
                <button type="button" class="btn btn-sm btn-default rsd-mark" style="margin-bottom:12px;" data-store="{{ $st }}" data-supplier="{{ $k }}" data-keys="{{ json_encode(array_column($list, 'key')) }}" data-qtys="{{ json_encode((object) array_combine(array_column($list, 'key'), array_column($list, 'qty'))) }}">Mark as ordered</button>
            </div>
        @endforeach
        @if(!empty($cart['unpriced']))
            <div class="col-md-6">
                <p style="margin:0 0 4px;"><strong>No price yet</strong>: {{ count($cart['unpriced']) }} titles, {{ array_sum(array_column($cart['unpriced'], 'qty')) }} copies</p>
<textarea class="rsd-list" rows="{{ min(14, count($cart['unpriced']) + 1) }}" readonly>@foreach($cart['unpriced'] as $r){{ $r['qty'] }}	{{ $r['artist'] }} - {{ $r['title'] }}
@endforeach</textarea>
            </div>
        @endif
        </div>
        </div>
    </div>
    @endforeach

</section>
@endsection

@section('javascript')
<script>
(function () {
    var CSRF = "{{ csrf_token() }}";
    function post(url, data) {
        data._token = CSRF;
        return $.post(url, data);
    }
    // Click a column header to sort (click again to flip). Number boxes sort by their value.
    $('#rsd-table thead th').css('cursor', 'pointer').attr('title', 'Click to sort').on('click', function () {
        var th = $(this), i = th.index(), dir = th.data('dir') === 'desc' ? 'asc' : 'desc';
        $('#rsd-table thead th').removeData('dir').find('.rsd-arrow').remove();
        th.data('dir', dir).append('<span class="rsd-arrow"> ' + (dir === 'desc' ? '&#9660;' : '&#9650;') + '</span>');
        var val = function (tr) {
            var td = $(tr).children().eq(i), inp = td.find('input');
            var s = inp.length ? inp.val() : td.text().trim();
            var n = parseFloat(String(s).replace(/[$,]/g, ''));
            return isNaN(n) ? (i === 0 ? s.toLowerCase() : (dir === 'desc' ? -1 : 1e9)) : n;
        };
        var rows = $('#rsd-table tbody tr').get();
        rows.sort(function (a, b) {
            var x = val(a), y = val(b);
            var c = (typeof x === 'number' && typeof y === 'number') ? x - y : String(x).localeCompare(String(y));
            return dir === 'desc' ? -c : c;
        });
        $('#rsd-table tbody').append(rows);
    });
    var timers = {};
    $('#rsd-table').on('change', '.rsd-req', function () {
        var el = $(this);
        post(@json(action('RsdOrderController@saveRequest')), { key: el.closest('tr').data('key'), field: el.data('field'), value: el.val() })
            .done(function (r) {
                if (r && r.success) { toastr.success('Saved'); setTimeout(function () { location.reload(); }, 600); }
                else toastr.error((r && r.msg) || 'Not saved');
            }).fail(function () { toastr.error('Not saved'); });
    });
    $('#rsd-table').on('change', '.rsd-qty', function () {
        var el = $(this), key = el.closest('tr').data('key'), st = el.data('store');
        var qty = el.val(), sug = el.data('suggested');
        clearTimeout(timers[key + st]);
        timers[key + st] = setTimeout(function () {
            post(@json(action('RsdOrderController@saveQty')), {
                key: key, store: st,
                qty: String(qty) === String(sug) ? '' : qty
            }).done(function (r) {
                if (r && r.success) toastr.success('Saved'); else toastr.error((r && r.msg) || 'Not saved');
            }).fail(function () { toastr.error('Not saved'); });
        }, 300);
    });
    $('.rsd-mark').on('click', function () {
        var b = $(this);
        if (b.data('armed') !== 1) { b.data('armed', 1).text('Click again to confirm'); return; }
        var data = { supplier: b.data('supplier'), store: b.data('store'), keys: b.data('keys') };
        var q = b.data('qtys') || {};
        Object.keys(q).forEach(function (k) { data['qty[' + k + ']'] = q[k]; });
        post(@json(action('RsdOrderController@markOrdered')), data).done(function () { location.reload(); })
            .fail(function () { toastr.error('Not saved'); });
    });
})();
</script>
@endsection
