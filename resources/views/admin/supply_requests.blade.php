@extends('layouts.app')
@section('title', 'Manage Supply Requests')

@section('content')
<section class="content-header">
    <h1>Manage Supply Requests</h1>
    <p class="text-muted">Staff requests across all stores. Set a status — when you mark something <strong>Ordered</strong> and fill in the arriving date / tracking, the employee who asked for it sees that on their Supply Requests page. Marking a request Ordered clears it from the queue below into <strong>Ordered &amp; done</strong>, where you can still add tracking or mark it Received. Manage stock levels at <a href="{{ action('SuppliesController@index') }}">Supplies</a>.</p>
</section>

<section class="content">
<div class="row">
    <div class="col-md-12">
        <div class="box box-solid">
            <div class="box-header with-border">
                <h3 class="box-title">Needs ordering ({{ count($requests) }})</h3>
            </div>
            <div class="box-body">
                @if (empty($requests))
                    <p class="text-muted">Nothing to order right now. New staff requests show up here.</p>
                @else
                    @foreach ($requests as $r)
                        @include('admin._supply_request_row', ['r' => $r])
                    @endforeach
                @endif
            </div>
        </div>

        @if (!empty($done))
        <div class="box box-solid collapsed-box">
            <div class="box-header with-border">
                <h3 class="box-title">Ordered &amp; done ({{ count($done) }})</h3>
                <div class="box-tools pull-right">
                    <button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-plus"></i></button>
                </div>
            </div>
            <div class="box-body">
                @foreach ($done as $r)
                    @include('admin._supply_request_row', ['r' => $r])
                @endforeach
            </div>
        </div>
        @endif
    </div>
</div>
</section>
@endsection
