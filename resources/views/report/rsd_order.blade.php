@extends('layouts.app')
@section('title', 'RSD Order')

@section('content')
@php
    $storeName = $stores[$store];
    $money = function ($v) { return $v === null ? '' : '$' . number_format((float) $v, 2); };
    $totalQty = 0; $totalCost = 0; $pricedQty = 0;
    foreach ($rows as $r) {
        $totalQty += $r['qty'];
        if ($r['best']) { $totalCost += $r['qty'] * $r['best']['cost']; $pricedQty += $r['qty']; }
    }
    $usedSuppliers = [];
    foreach ($rows as $r) foreach ($r['prices'] as $k => $e) $usedSuppliers[$k] = true;
    $cols = array_intersect_key($suppliers, $usedSuppliers + ['ams' => true, 'alliance' => true]);
@endphp
<style>
    .ro-bar { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
    .ro-bar .form-group { margin:0; }
    table.rsd-table { width:100%; font-size:14px; }
    table.rsd-table th { background:#f4f4f4; font-size:12px; line-height:1.2; vertical-align:bottom !important; position:sticky; top:0; z-index:1; }
    table.rsd-table td { vertical-align:middle !important; padding:6px 5px !important; }
    table.rsd-table td.n, table.rsd-table th.n { text-align:center; }
    table.rsd-table th.here { background:#e3f2fd; }
    table.rsd-table td.here { background:#f3f9ff; }
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
    <h1>RSD Order: {{ $storeName }} <small>{{ $event }} (Fri {{ \Carbon\Carbon::parse($eventDate)->format('M j') }})</small></h1>
</section>
<section class="content">
    <div class="box box-solid">
        <div class="box-body">
            <div class="ro-bar">
                <div class="form-group">
                    <label>Store</label>
                    <select class="form-control" onchange="location.href=this.value">
                        @foreach($stores as $k => $label)
                            <option value="{{ action('RsdOrderController@index', ['store' => $k]) }}" @if($k === $store) selected @endif>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
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
            <p style="margin:10px 0 0; font-size:15px;">
                <b>{{ $totalQty }}</b> copies of <b>{{ count(array_filter($rows, function ($r) { return $r['qty'] > 0; })) }}</b> albums for {{ $storeName }}@if($pricedQty), {{ $money($totalCost) }} for the ones with a price @endif.
            </p>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-body rsd-wrap">
            <table class="table table-bordered rsd-table" id="rsd-table">
                <thead>
                    <tr>
                        <th>Album</th>
                        <th class="n {{ $store === 'hollywood' ? 'here' : '' }}">Customers asking (Hollywood)</th>
                        <th class="n {{ $store === 'pico' ? 'here' : '' }}">Customers asking (Pico)</th>
                        <th class="n here">We order</th>
                        <th>Cheapest price</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($rows as $r)
                    @php $others = array_filter($r['prices'], function ($e) use ($r) { return !$r['best'] || $e['supplier_key'] !== $r['best']['supplier_key']; }); @endphp
                    <tr data-key="{{ $r['key'] }}" class="{{ $r['ordered'] ? 'rsd-ordered' : '' }} {{ $r['requested'] ? '' : 'rsd-none' }}">
                        <td>
                            <span class="rsd-artist">{{ $r['artist'] }}</span><br>{{ $r['title'] }}
                            @if(!$r['is_rsd']) <span class="rsd-tag">Regular release</span> @endif
                            @if($r['ordered'])
                                <div class="rsd-muted">Ordered {{ $r['ordered']['qty'] ?: '' }} from {{ $suppliers[$r['ordered']['supplier']] ?? $r['ordered']['supplier'] }} on {{ \Carbon\Carbon::parse($r['ordered']['at'])->format('M j') }}</div>
                            @endif
                        </td>
                        <td class="n {{ $store === 'hollywood' ? 'here' : '' }}"><input type="number" min="0" class="rsd-num rsd-req" data-field="hollywood" value="{{ $r['hw'] }}"></td>
                        <td class="n {{ $store === 'pico' ? 'here' : '' }}"><input type="number" min="0" class="rsd-num rsd-req" data-field="pico" value="{{ $r['pico'] }}">
                            @if($r['none'])<div class="rsd-muted">+{{ $r['none'] }} no store</div>@endif</td>
                        <td class="n here">
                            <input type="number" min="0" class="form-control input-sm rsd-num rsd-qty" value="{{ $r['qty'] }}" data-suggested="{{ $r['suggested'] }}">
                        </td>
                        <td>
                            @if($r['best'])
                                <b>{{ $money($r['best']['cost']) }}</b> {{ $r['best']['supplier_label'] }}
                                @foreach($others as $op)<div class="rsd-muted">{{ $money($op['cost']) }} {{ $op['supplier_label'] }}@if($op['in_stock'] === false) (sold out)@endif</div>@endforeach
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
                <input type="hidden" name="store" value="{{ $store }}">
                <div class="form-group"><label>Artist</label><input name="artist" class="form-control" placeholder="Artist"></div>
                <div class="form-group"><label>Title</label><input name="title" class="form-control" placeholder="Title"></div>
                <div class="form-group"><label>Store</label>
                    <select name="where" class="form-control">
                        <option value="hollywood" @if($store === 'hollywood') selected @endif>Hollywood</option>
                        <option value="pico" @if($store === 'pico') selected @endif>Pico</option>
                        <option value="none">No store given</option>
                    </select>
                </div>
                <div class="form-group"><label><input type="checkbox" name="regular" value="1"> Not an RSD title</label></div>
                <div class="form-group"><button type="submit" class="btn btn-primary">Add</button></div>
            </form>
            <p class="rsd-muted" style="margin:6px 0 0;">Same artist and title as a row above adds 1 to that row.</p>
        </div>
    </div>

    <h3>Order lists ({{ $storeName }})</h3>
    
    <div class="row">
        @foreach($byDist as $k => $list)
            @php $sum = 0; foreach ($list as $r) $sum += $r['qty'] * $r['prices'][$k]['cost']; @endphp
            <div class="col-md-6">
                <div class="box box-solid">
                    <div class="box-header with-border"><strong>{{ $suppliers[$k] ?? $k }}</strong>: {{ count($list) }} titles, {{ array_sum(array_column($list, 'qty')) }} copies, {{ $money($sum) }}</div>
                    <div class="box-body">
<textarea class="rsd-list" rows="{{ min(14, count($list) + 1) }}" readonly>@foreach($list as $r){{ $r['upcs'][0] ?? '' }}	{{ $r['qty'] }}	{{ $r['artist'] }} - {{ $r['title'] }}
@endforeach</textarea>
                        <button type="button" class="btn btn-sm btn-default rsd-mark" data-supplier="{{ $k }}" data-keys="{{ json_encode(array_column($list, 'key')) }}" data-qtys="{{ json_encode((object) array_combine(array_column($list, 'key'), array_column($list, 'qty'))) }}">Mark as ordered</button>
                    </div>
                </div>
            </div>
        @endforeach
        @if(!empty($unpriced))
            <div class="col-md-6">
                <div class="box box-solid">
                    <div class="box-header with-border"><strong>No price yet</strong>: {{ count($unpriced) }} titles, {{ array_sum(array_column($unpriced, 'qty')) }} copies</div>
                    <div class="box-body">
                        <textarea class="rsd-list" rows="{{ min(14, count($unpriced) + 1) }}" readonly>@foreach($unpriced as $r){{ $r['qty'] }}	{{ $r['artist'] }} - {{ $r['title'] }}
@endforeach</textarea>
                        <div style="margin-top:6px;">
                            Mark as ordered from
                            <select class="form-control input-sm rsd-unpriced-supplier" style="display:inline-block;width:auto;">
                                @foreach($suppliers as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                            </select>
                            <button type="button" class="btn btn-sm btn-default rsd-mark" data-supplier="" data-keys="{{ json_encode(array_column($unpriced, 'key')) }}" data-qtys="{{ json_encode((object) array_combine(array_column($unpriced, 'key'), array_column($unpriced, 'qty'))) }}">Mark as ordered</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>

</section>
@endsection

@section('javascript')
<script>
(function () {
    var CSRF = "{{ csrf_token() }}";
    var STORE = @json($store);
    function post(url, data) {
        data._token = CSRF; data.store = STORE;
        return $.post(url, data);
    }
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
        var tr = $(this).closest('tr'), key = tr.data('key');
        var qty = tr.find('.rsd-qty').val(), sug = tr.find('.rsd-qty').data('suggested');
        clearTimeout(timers[key]);
        timers[key] = setTimeout(function () {
            post(@json(action('RsdOrderController@saveQty')), {
                key: key,
                qty: String(qty) === String(sug) ? '' : qty
            }).done(function (r) {
                if (r && r.success) toastr.success('Saved'); else toastr.error((r && r.msg) || 'Not saved');
            }).fail(function () { toastr.error('Not saved'); });
        }, 300);
    });
    $('.rsd-mark').on('click', function () {
        var b = $(this);
        if (b.data('armed') !== 1) { b.data('armed', 1).text('Click again to confirm'); return; }
        var supplier = b.data('supplier') || b.closest('.box-body').find('.rsd-unpriced-supplier').val();
        var data = { supplier: supplier, keys: b.data('keys') };
        var q = b.data('qtys') || {};
        Object.keys(q).forEach(function (k) { data['qty[' + k + ']'] = q[k]; });
        post(@json(action('RsdOrderController@markOrdered')), data).done(function () { location.reload(); })
            .fail(function () { toastr.error('Not saved'); });
    });
})();
</script>
@endsection
