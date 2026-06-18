@extends('layouts.app')
@section('title', 'Manage Supply Requests')

@section('content')
<section class="content-header">
    <h1>Manage Supply Requests</h1>
    <p class="text-muted">Staff requests across all stores. Set a status — when you mark something <strong>Ordered</strong> and fill in the arriving date / tracking, the employee who asked for it sees that on their Supply Requests page. Manage stock levels at <a href="{{ action('SuppliesController@index') }}">Supplies</a>.</p>
</section>

<section class="content">
<div class="row">
    <div class="col-md-12">
        <div class="box box-solid">
            <div class="box-body">
                @if (empty($requests))
                    <p class="text-muted">No supply requests yet.</p>
                @else
                    @foreach ($requests as $r)
                    @php
                        $st = $r['status'] ?? 'pending';
                        $panel = ['pending' => 'panel-warning', 'ordered' => 'panel-info', 'received' => 'panel-success', 'declined' => 'panel-default'][$st] ?? 'panel-default';
                    @endphp
                    <div class="panel {{ $panel }}">
                        <div class="panel-body">
                            <form method="POST" action="{{ action('SupplyRequestController@update') }}">
                                @csrf
                                <input type="hidden" name="id" value="{{ $r['id'] ?? '' }}">
                                <div class="row">
                                    <div class="col-md-3">
                                        <strong>{{ $r['item'] ?? '' }}</strong>
                                        @if(!empty($r['qty']))<br><small class="text-muted">{{ $r['qty'] }}</small>@endif
                                        <br><small class="text-muted">{{ $r['location_name'] ?: 'No store set' }}</small>
                                        <br><small class="text-muted">by {{ $r['requested_by_name'] ?? 'staff' }}, {{ $r['requested_at'] ?? '' }}</small>
                                        @if(!empty($r['note']))<br><small>&ldquo;{{ $r['note'] }}&rdquo;</small>@endif
                                    </div>
                                    <div class="col-md-2">
                                        <label class="text-muted small">Status</label>
                                        <select name="status" class="form-control input-sm">
                                            @foreach ($statuses as $val => $label)
                                                <option value="{{ $val }}" @if($st === $val) selected @endif>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        @if(!empty($r['ordered_at']))<small class="text-muted">ordered {{ $r['ordered_at'] }}</small>@endif
                                    </div>
                                    <div class="col-md-2">
                                        <label class="text-muted small">Arriving (ETA)</label>
                                        <input type="text" name="eta" class="form-control input-sm" placeholder="e.g. Fri Jun 20" value="{{ $r['eta'] ?? '' }}">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="text-muted small">Tracking</label>
                                        <input type="text" name="tracking" class="form-control input-sm" placeholder="carrier / #" value="{{ $r['tracking'] ?? '' }}">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="text-muted small">Note to employee</label>
                                        <input type="text" name="manager_note" class="form-control input-sm" value="{{ $r['manager_note'] ?? '' }}">
                                    </div>
                                    <div class="col-md-1">
                                        <label class="text-muted small">&nbsp;</label>
                                        <button type="submit" class="btn btn-primary btn-sm btn-block">Save</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    @endforeach
                @endif
            </div>
        </div>
    </div>
</div>
</section>
@endsection
