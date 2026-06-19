@extends('layouts.app')
@section('title', 'Supplies')

@section('content')
<section class="content-header">
    <h1>Supplies</h1>
    <p class="text-muted">Track consumables (waters, bags, receipt/label paper, sleeves, cleaning kits). Set each item's status, which store it's for, and when the next restock is expected. The "Ask the ERP" assistant reads this, so staff can ask "are we low on waters at Pico?" or "when's the next shipment?". To see what staff have requested, go to <a href="{{ action('SupplyRequestController@admin') }}">Manage Requests</a>.</p>
</section>

<section class="content">
<div class="row">
    <div class="col-md-10">
        <div class="box box-{{ count($needsOrder) ? 'danger' : 'success' }} box-solid">
            <div class="box-header with-border">
                <h3 class="box-title">Needs ordering @if(count($needsOrder))<span class="label label-danger">{{ count($needsOrder) }}</span>@endif</h3>
            </div>
            <div class="box-body">
                @if (empty($needsOrder))
                    <p class="text-muted">Nothing is low or out right now. Anything you mark Running low or Out below shows up here with where to order it.</p>
                @else
                    <table class="table table-condensed">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Store</th>
                                <th>Status</th>
                                <th>Where to order</th>
                                <th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($needsOrder as $it)
                            <tr>
                                <td><strong>{{ $it['name'] ?? '' }}</strong></td>
                                <td>{{ $it['location_name'] ?: 'All stores' }}</td>
                                <td><span class="label {{ ($it['status'] ?? '') === 'out' ? 'label-danger' : 'label-warning' }}">{{ $statuses[$it['status'] ?? 'ok'] ?? '' }}</span></td>
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
                                <th style="width:20%;">Item</th>
                                <th style="width:13%;">Status</th>
                                <th style="width:13%;">Store</th>
                                <th style="width:17%;">Next restock / shipment</th>
                                <th style="width:19%;">Where to order</th>
                                <th>Note</th>
                                <th style="width:40px;"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($items as $it)
                            <tr>
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
