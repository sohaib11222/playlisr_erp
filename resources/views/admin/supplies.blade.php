@extends('layouts.app')
@section('title', 'Supplies')

@section('content')
<section class="content-header">
    <h1>Supplies</h1>
    <p class="text-muted">Track consumables (waters, bags, receipt/label paper, sleeves, cleaning kits). Set each item's status, which store it's for, and when the next restock is expected. The "Ask the ERP" assistant reads this, so staff can ask "are we low on waters at Pico?" or "when's the next shipment?". To see what staff have requested, go to <a href="{{ action('SupplyRequestController@admin') }}">Manage Requests</a>.</p>
</section>

<style>
    .supplies-store-box { border-left: 4px solid #d2d6de; margin-bottom: 15px; }
    .supplies-store-box.store-out { border-left-color: #dd4b39; }
    .supplies-store-box.store-low { border-left-color: #f39c12; }
    .supplies-store-box .box-title small { font-weight: normal; color: #999; margin-left: 6px; }
    .supplies-row-out { background: #fdeceb; }
    .supplies-row-low { background: #fef6e7; }
    .supplies-row-out td, .supplies-row-low td { border-top-color: #eee !important; }
    .supplies-status-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
    .supplies-status-dot.dot-out { background: #dd4b39; }
    .supplies-status-dot.dot-low { background: #f39c12; }
    .supplies-status-dot.dot-ok { background: #00a65a; }
    #supplies-table tr.supplies-group-header td { background: #f4f4f4; font-weight: 600; color: #555; padding-top: 8px; padding-bottom: 8px; border-top: 2px solid #d2d6de !important; }
</style>

<section class="content">
<div class="row">
    <div class="col-md-10">

        {{-- At-a-glance: what needs ordering, grouped by store so it maps to an actual shopping trip. --}}
        <div class="box box-solid">
            <div class="box-header with-border">
                <h3 class="box-title">Needs ordering @if(count($needsOrder))<span class="label label-danger">{{ count($needsOrder) }}</span>@else<span class="label label-success">All stocked</span>@endif</h3>
            </div>
            <div class="box-body">
                @if (empty($needsOrder))
                    <p class="text-muted">Nothing is low or out right now. Anything you mark Running low or Out below shows up here, grouped by store.</p>
                @else
                    @foreach ($needsOrderByStore as $storeName => $storeItems)
                        @php
                            $hasOut = collect($storeItems)->contains(fn($it) => ($it['status'] ?? '') === 'out');
                        @endphp
                        <div class="supplies-store-box {{ $hasOut ? 'store-out' : 'store-low' }}">
                            <h4 style="margin: 4px 0 8px 4px;">{{ $storeName }} <small>{{ count($storeItems) }} {{ \Illuminate\Support\Str::plural('item', count($storeItems)) }}</small></h4>
                            <table class="table table-condensed" style="margin-bottom: 4px;">
                                <thead>
                                    <tr>
                                        <th style="width:22%;">Item</th>
                                        <th style="width:14%;">Status</th>
                                        <th style="width:24%;">Where to order</th>
                                        <th>Note</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($storeItems as $it)
                                    <tr class="{{ ($it['status'] ?? '') === 'out' ? 'supplies-row-out' : 'supplies-row-low' }}">
                                        <td><strong>{{ $it['name'] ?? '' }}</strong></td>
                                        <td>
                                            <span class="supplies-status-dot dot-{{ $it['status'] ?? 'ok' }}"></span>{{ $statuses[$it['status'] ?? 'ok'] ?? '' }}
                                        </td>
                                        <td>
                                            @if(!empty($it['order_info']))
                                                @if(\Illuminate\Support\Str::startsWith($it['order_info'], ['http://', 'https://']))
                                                    <a href="{{ $it['order_info'] }}" target="_blank" rel="noopener">{{ $it['order_info'] }}</a>
                                                @else
                                                    {{ $it['order_info'] }}
                                                @endif
                                            @else
                                                <span class="text-muted">— add a supplier/link below —</span>
                                            @endif
                                        </td>
                                        <td>{{ $it['note'] ?? '' }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                @endif
            </div>
        </div>

        <div class="box box-solid">
            <div class="box-header with-border"><h3 class="box-title">All supplies</h3></div>
            <div class="box-body">
                <form method="POST" action="{{ url('/admin/supplies/save') }}">
                    @csrf
                    <table class="table table-condensed" id="supplies-table">
                        <thead>
                            <tr>
                                <th style="width:19%;">Item</th>
                                <th style="width:14%;">Status</th>
                                <th style="width:13%;">Store</th>
                                <th style="width:16%;">Next restock / shipment</th>
                                <th style="width:18%;">Where to order</th>
                                <th>Note</th>
                                <th style="width:40px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $lastStore = null; @endphp
                            @foreach ($items as $it)
                                @php $storeName = $it['location_name'] ?: 'All stores'; @endphp
                                @if ($storeName !== $lastStore)
                                    <tr class="supplies-group-header"><td colspan="7">{{ $storeName }}</td></tr>
                                    @php $lastStore = $storeName; @endphp
                                @endif
                            <tr class="{{ ($it['status'] ?? 'ok') === 'out' ? 'supplies-row-out' : (($it['status'] ?? 'ok') === 'low' ? 'supplies-row-low' : '') }}">
                                <td><input type="text" name="name[]" class="form-control input-sm" value="{{ $it['name'] ?? '' }}"></td>
                                <td>
                                    <select name="status[]" class="form-control input-sm">
                                        @foreach ($statuses as $val => $label)
                                            <option value="{{ $val }}" @if(($it['status'] ?? 'ok') === $val) selected @endif>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="location_id[]" class="form-control input-sm">
                                        <option value="">All stores</option>
                                        @foreach ($locations as $lid => $lname)
                                            <option value="{{ $lid }}" @if(($it['location_id'] ?? null) == $lid) selected @endif>{{ $lname }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input type="text" name="next_restock[]" class="form-control input-sm" placeholder="e.g. Mon Jun 23, or weekly" value="{{ $it['next_restock'] ?? '' }}"></td>
                                <td><input type="text" name="order_info[]" class="form-control input-sm" placeholder="supplier name or order link" value="{{ $it['order_info'] ?? '' }}"></td>
                                <td><input type="text" name="note[]" class="form-control input-sm" value="{{ $it['note'] ?? '' }}"></td>
                                <td><button type="button" class="btn btn-link text-red supplies-remove" title="Remove row">&times;</button></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>

                    <button type="button" class="btn btn-default btn-sm" id="supplies-add">+ Add item</button>
                    <button type="submit" class="btn btn-primary btn-lg pull-right">Save</button>
                </form>
            </div>
        </div>
    </div>
</div>
</section>

<script>
(function () {
    var statusOptions = `@foreach($statuses as $val => $label)<option value="{{ $val }}">{{ $label }}</option>@endforeach`;
    var locationOptions = `<option value="">All stores</option>@foreach($locations as $lid => $lname)<option value="{{ $lid }}">{{ $lname }}</option>@endforeach`;

    function newRow() {
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td><input type="text" name="name[]" class="form-control input-sm"></td>' +
            '<td><select name="status[]" class="form-control input-sm">' + statusOptions + '</select></td>' +
            '<td><select name="location_id[]" class="form-control input-sm">' + locationOptions + '</select></td>' +
            '<td><input type="text" name="next_restock[]" class="form-control input-sm" placeholder="e.g. Mon Jun 23, or weekly"></td>' +
            '<td><input type="text" name="order_info[]" class="form-control input-sm" placeholder="supplier name or order link"></td>' +
            '<td><input type="text" name="note[]" class="form-control input-sm"></td>' +
            '<td><button type="button" class="btn btn-link text-red supplies-remove" title="Remove row">&times;</button></td>';
        return tr;
    }

    document.getElementById('supplies-add').addEventListener('click', function () {
        document.querySelector('#supplies-table tbody').appendChild(newRow());
    });

    document.getElementById('supplies-table').addEventListener('click', function (e) {
        if (e.target.classList.contains('supplies-remove')) {
            var tr = e.target.closest('tr');
            if (tr) tr.parentNode.removeChild(tr);
        }
    });
})();
</script>
@endsection
