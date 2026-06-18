@extends('layouts.app')
@section('title', 'Supply Requests')

@section('content')
<section class="content-header">
    <h1>Supply Requests</h1>
    <p class="text-muted">Need something for the floor (waters, bags, receipt/label paper, sleeves, cleaning kits, anything)? Request it here. A manager will order it, and you'll see the status, the date it was ordered, and when it's coming on this page.</p>
</section>

<section class="content">
<div class="row">
    <div class="col-md-5">
        <div class="box box-solid">
            <div class="box-header with-border"><h3 class="box-title">Request a supply</h3></div>
            <div class="box-body">
                <form method="POST" action="{{ action('SupplyRequestController@submit') }}">
                    @csrf
                    <div class="form-group">
                        <label>What do you need?</label>
                        <input type="text" name="item" class="form-control" placeholder="e.g. Bottled water" required>
                    </div>
                    <div class="form-group">
                        <label>How much / how many? <small class="text-muted">(optional)</small></label>
                        <input type="text" name="qty" class="form-control" placeholder="e.g. 2 cases">
                    </div>
                    <div class="form-group">
                        <label>Which store?</label>
                        <select name="location_id" class="form-control">
                            <option value="">— select store —</option>
                            @foreach ($locations as $lid => $lname)
                                <option value="{{ $lid }}">{{ $lname }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Note <small class="text-muted">(optional)</small></label>
                        <textarea name="note" class="form-control" rows="2" placeholder="Anything the manager should know"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Submit request</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="box box-solid">
            <div class="box-header with-border"><h3 class="box-title">My requests</h3></div>
            <div class="box-body">
                @if (empty($requests))
                    <p class="text-muted">You haven't requested anything yet.</p>
                @else
                    <table class="table table-condensed">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Store</th>
                                <th>Status</th>
                                <th>Ordered / Arriving</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($requests as $r)
                            @php
                                $st = $r['status'] ?? 'pending';
                                $badge = ['pending' => 'label-default', 'ordered' => 'label-warning', 'received' => 'label-success', 'declined' => 'label-danger'][$st] ?? 'label-default';
                            @endphp
                            <tr>
                                <td>
                                    {{ $r['item'] ?? '' }}
                                    @if(!empty($r['qty']))<br><small class="text-muted">{{ $r['qty'] }}</small>@endif
                                    @if(!empty($r['note']))<br><small class="text-muted">{{ $r['note'] }}</small>@endif
                                </td>
                                <td>{{ $r['location_name'] ?: '—' }}</td>
                                <td><span class="label {{ $badge }}">{{ $statuses[$st] ?? 'Requested' }}</span></td>
                                <td>
                                    @if($st === 'ordered' || $st === 'received')
                                        @if(!empty($r['ordered_at']))<div><small>Ordered {{ $r['ordered_at'] }}</small></div>@endif
                                        @if(!empty($r['eta']))<div><strong>Arriving:</strong> {{ $r['eta'] }}</div>@endif
                                        @if(!empty($r['tracking']))<div><small>Tracking: {{ $r['tracking'] }}</small></div>@endif
                                    @endif
                                    @if(!empty($r['manager_note']))<div><small class="text-muted">Note: {{ $r['manager_note'] }}</small></div>@endif
                                    @if($st === 'pending')<small class="text-muted">Waiting on a manager</small>@endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    </div>
</div>
</section>
@endsection
