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
    .rsd-tabs a { display:inline-block; padding:6px 16px; border:1px solid #ccc; border-radius:16px; margin-right:6px; color:#333; }
    .rsd-tabs a.active { background:#333; color:#fff; border-color:#333; }
    table.rsd-table { width:100%; font-size:14px; }
    table.rsd-table th { background:#f4f4f4; font-size:12px; vertical-align:bottom !important; position:sticky; top:0; z-index:1; }
    table.rsd-table td { vertical-align:middle !important; padding:6px 5px !important; }
    table.rsd-table td.n { text-align:center; }
    .rsd-artist { font-weight:bold; }
    .rsd-qty { width:56px; text-align:center; }
    .rsd-upc { width:120px; font-size:12px; }
    .rsd-best { background:#e8f5e9; font-weight:bold; }
    .rsd-out { color:#999; text-decoration:line-through; }
    .rsd-muted { color:#888; font-size:12px; }
    .rsd-tag { display:inline-block; font-size:11px; border-radius:9px; padding:0 7px; color:#fff; background:#757575; }
    .rsd-wrap { max-height:70vh; overflow:auto; }
    .rsd-list { font-family:monospace; font-size:13px; width:100%; }
    tr.rsd-ordered td { background:#f1f8e9; }
</style>
<section class="content-header">
    <h1>RSD Order: {{ $storeName }} <small>{{ $event }} (Fri {{ \Carbon\Carbon::parse($eventDate)->format('M j') }})</small></h1>
</section>
<section class="content">
    <div class="rsd-tabs" style="margin-bottom:12px;">
        @foreach($stores as $k => $label)
            <a href="{{ action('RsdOrderController@index', ['store' => $k]) }}" class="{{ $k === $store ? 'active' : '' }}">{{ $label }}</a>
        @endforeach
    </div>

    <div class="box box-solid">
        <div class="box-body">
            <p style="margin:0 0 6px;">
                Built from the <a href="{{ $sheetUrl }}" target="_blank" rel="noopener">customer request sheet</a> (Instagram comments, DMs, Quo texts and the website form), counts as of {{ $asOf ? \Carbon\Carbon::parse($asOf)->format('M j') : 'unknown' }}.
                Order qty = what customers asked for at this store plus half again (at least 1 extra). Requests with no store count at both stores. Change any qty and it saves.
            </p>
            <p style="margin:0;">
                <strong>{{ count($rows) }}</strong> titles, <strong>{{ $totalQty }}</strong> copies.
                Cheapest-price total {{ $money($totalCost) }}@if($pricedQty < $totalQty) for {{ $pricedQty }} priced copies ({{ $totalQty - $pricedQty }} copies have no distributor price yet)@endif.
            </p>
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-body rsd-wrap">
            <table class="table table-bordered rsd-table" id="rsd-table">
                <thead>
                    <tr>
                        <th>Album</th>
                        <th class="n">Asked for here</th>
                        <th class="n">Order qty</th>
                        @foreach($cols as $k => $label)
                            <th class="n">{{ $label }}</th>
                        @endforeach
                        <th class="n">Cheapest</th>
                        <th class="n">Line total</th>
                        <th>UPC</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($rows as $r)
                    <tr data-key="{{ $r['key'] }}" class="{{ $r['ordered'] ? 'rsd-ordered' : '' }}">
                        <td>
                            <span class="rsd-artist">{{ $r['artist'] }}</span><br>{{ $r['title'] }}
                            @if(!$r['is_rsd']) <span class="rsd-tag">Regular release</span> @endif
                            @if($r['ordered'])
                                <div class="rsd-muted">Ordered {{ $r['ordered']['qty'] ?: '' }} from {{ $suppliers[$r['ordered']['supplier']] ?? $r['ordered']['supplier'] }} on {{ \Carbon\Carbon::parse($r['ordered']['at'])->format('M j') }}</div>
                            @endif
                        </td>
                        <td class="n">
                            {{ $r['requested'] }}
                            @if($r['none'])<div class="rsd-muted">incl. {{ $r['none'] }} no store</div>@endif
                            @if($r['other'])<div class="rsd-muted">{{ $r['other'] }} at other store</div>@endif
                        </td>
                        <td class="n">
                            <input type="number" min="0" class="form-control input-sm rsd-qty" value="{{ $r['qty'] }}" data-suggested="{{ $r['suggested'] }}">
                            <div class="rsd-muted">{{ $r['qty_edited'] ? 'suggested ' . $r['suggested'] : '' }}</div>
                        </td>
                        @foreach($cols as $k => $label)
                            @php $e = $r['prices'][$k] ?? null; @endphp
                            <td class="n {{ $e && $r['best'] && $r['best']['supplier_key'] === $k ? 'rsd-best' : '' }}">
                                @if($e)
                                    <span class="{{ $e['in_stock'] === false ? 'rsd-out' : '' }}">
                                        @if(!empty($e['url']))<a href="{{ $e['url'] }}" target="_blank" rel="noopener">{{ $money($e['cost']) }}</a>@else{{ $money($e['cost']) }}@endif
                                    </span>
                                    @if($e['in_stock'] === false)<div class="rsd-muted">sold out</div>@endif
                                @else
                                    <span class="rsd-muted">-</span>
                                @endif
                            </td>
                        @endforeach
                        <td class="n">{{ $r['best'] ? $r['best']['supplier_label'] : '' }}</td>
                        <td class="n">{{ $r['best'] ? $money($r['qty'] * $r['best']['cost']) : '' }}</td>
                        <td>
                            <input type="text" class="form-control input-sm rsd-upc" value="{{ implode(', ', $r['upcs']) }}" placeholder="add UPC">
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <h3>Order lists by distributor ({{ $storeName }})</h3>
    <p class="rsd-muted">Each title goes to its cheapest distributor. Copy a list into the distributor's order form, then click Mark as ordered.</p>
    <div class="row">
        @foreach($byDist as $k => $list)
            @php $sum = 0; foreach ($list as $r) $sum += $r['qty'] * $r['prices'][$k]['cost']; @endphp
            <div class="col-md-6">
                <div class="box box-solid">
                    <div class="box-header with-border"><strong>{{ $suppliers[$k] ?? $k }}</strong>: {{ count($list) }} titles, {{ array_sum(array_column($list, 'qty')) }} copies, {{ $money($sum) }}</div>
                    <div class="box-body">
<textarea class="rsd-list" rows="{{ min(14, count($list) + 1) }}" readonly>@foreach($list as $r){{ $r['upcs'][0] ?? '' }}	{{ $r['qty'] }}	{{ $r['artist'] }} - {{ $r['title'] }}
@endforeach</textarea>
                        <button type="button" class="btn btn-sm btn-default rsd-mark" data-supplier="{{ $k }}" data-keys='@json(array_column($list, 'key'))' data-qtys='@json(array_combine(array_column($list, 'key'), array_column($list, 'qty')))'>Mark as ordered</button>
                    </div>
                </div>
            </div>
        @endforeach
        @if(!empty($unpriced))
            <div class="col-md-6">
                <div class="box box-solid">
                    <div class="box-header with-border"><strong>No distributor price yet</strong>: {{ count($unpriced) }} titles, {{ array_sum(array_column($unpriced, 'qty')) }} copies</div>
                    <div class="box-body">
                        <p class="rsd-muted" style="margin-top:0;">Not listed in any distributor feed yet. Add the UPC in the table once you have it and the next nightly price pull fills in every distributor. AMS is the usual place for these.</p>
<textarea class="rsd-list" rows="{{ min(14, count($unpriced) + 1) }}" readonly>@foreach($unpriced as $r){{ $r['qty'] }}	{{ $r['artist'] }} - {{ $r['title'] }}
@endforeach</textarea>
                        <div style="margin-top:6px;">
                            Mark as ordered from
                            <select class="form-control input-sm rsd-unpriced-supplier" style="display:inline-block;width:auto;">
                                @foreach($suppliers as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                            </select>
                            <button type="button" class="btn btn-sm btn-default rsd-mark" data-supplier="" data-keys='@json(array_column($unpriced, 'key'))' data-qtys='@json(array_combine(array_column($unpriced, 'key'), array_column($unpriced, 'qty')))'>Mark as ordered</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <div class="box box-solid">
        <div class="box-header with-border"><strong>Update the request counts</strong></div>
        <div class="box-body">
            <form method="POST" action="{{ action('RsdOrderController@pasteRequests') }}">
                @csrf
                <input type="hidden" name="store" value="{{ $store }}">
                <p class="rsd-muted" style="margin-top:0;">In the request sheet, select the table from the first Title down to the last row (columns Title, Artist, HW, PICO, NO STORE), copy, paste here, Save. Both store pages update.</p>
                <textarea name="paste" class="form-control" rows="4"></textarea>
                <button type="submit" class="btn btn-primary btn-sm" style="margin-top:6px;">Save counts</button>
            </form>
        </div>
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
    $('#rsd-table').on('change', '.rsd-qty, .rsd-upc', function () {
        var tr = $(this).closest('tr'), key = tr.data('key');
        var qty = tr.find('.rsd-qty').val(), sug = tr.find('.rsd-qty').data('suggested');
        clearTimeout(timers[key]);
        timers[key] = setTimeout(function () {
            post(@json(action('RsdOrderController@saveQty')), {
                key: key,
                qty: String(qty) === String(sug) ? '' : qty,
                upc: tr.find('.rsd-upc').val().split(',')[0].trim()
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
