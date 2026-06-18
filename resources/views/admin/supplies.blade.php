@extends('layouts.app')
@section('title', 'Supplies')

@section('content')
<section class="content-header">
    <h1>Supplies</h1>
    <p class="text-muted">Track consumables (waters, bags, receipt/label paper, sleeves, cleaning kits). Set each item's status and when the next restock is expected. The "Ask the ERP" assistant reads this, so staff can ask "are we low on waters?" or "when's the next shipment?".</p>
</section>

<section class="content">
<div class="row">
    <div class="col-md-10">
        <div class="box box-solid">
            <div class="box-body">
                <form method="POST" action="{{ url('/admin/supplies/save') }}">
                    @csrf
                    <table class="table table-condensed" id="supplies-table">
                        <thead>
                            <tr>
                                <th style="width:28%;">Item</th>
                                <th style="width:18%;">Status</th>
                                <th style="width:22%;">Next restock / shipment</th>
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
                                <td><input type="text" name="next_restock[]" class="form-control input-sm" placeholder="e.g. Mon Jun 23, or weekly" value="{{ $it['next_restock'] ?? '' }}"></td>
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

    function newRow() {
        var tr = document.createElement('tr');
        tr.innerHTML =
            '<td><input type="text" name="name[]" class="form-control input-sm"></td>' +
            '<td><select name="status[]" class="form-control input-sm">' + statusOptions + '</select></td>' +
            '<td><input type="text" name="next_restock[]" class="form-control input-sm" placeholder="e.g. Mon Jun 23, or weekly"></td>' +
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
