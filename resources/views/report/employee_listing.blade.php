@extends('layouts.app')
@section('title', 'Employee Listing Report')

@section('content')

@php
    $money = fn($n) => '$' . number_format((float) $n, 2);
    $qty = fn($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
    $today = \Carbon::now();
    $presets = [
        'Today' => [$today->format('Y-m-d'), $today->format('Y-m-d')],
        'Last 7 days' => [$today->copy()->subDays(6)->format('Y-m-d'), $today->format('Y-m-d')],
        'Last 30 days' => [$today->copy()->subDays(29)->format('Y-m-d'), $today->format('Y-m-d')],
        'This month' => [$today->copy()->startOfMonth()->format('Y-m-d'), $today->format('Y-m-d')],
        'Last month' => [$today->copy()->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'), $today->copy()->subMonthNoOverflow()->endOfMonth()->format('Y-m-d')],
        'Last 90 days' => [$today->copy()->subDays(89)->format('Y-m-d'), $today->format('Y-m-d')],
    ];
@endphp

<style>
    .elr-store { font-size:18px; font-weight:700; margin:22px 0 8px; }
    .elr-store small { font-weight:400; color:#777; margin-left:8px; }
    .elr-emp { margin-bottom:14px; }
    .elr-emp .box-header { padding:8px 12px; }
    .elr-emp .box-title { font-size:15px; font-weight:700; }
    .elr-emp-tot { float:right; font-size:13px; color:#555; }
    .elr-table { margin-bottom:0; }
    .elr-table td.num, .elr-table th.num { text-align:right; white-space:nowrap; }
    .elr-presets a { margin:0 6px 6px 0; }
</style>

<section class="content-header">
    <h1>Employee Listing Report <small>what each employee listed, by store and category</small></h1>
</section>

<section class="content">

    <div class="row">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])
                {!! Form::open(['url' => action('EmployeeListingReportController@index'), 'method' => 'get', 'id' => 'employee_listing_filter_form']) !!}
                    <div class="col-md-3">
                        <div class="form-group">
                            {!! Form::label('location_id', 'Store:') !!}
                            {!! Form::select('location_id', $business_locations, $filters['location_id'] ?? null, ['class' => 'form-control select2', 'style' => 'width:100%', 'placeholder' => 'All stores', 'id' => 'location_id']) !!}
                        </div>
                    </div>
                    @if($is_admin)
                    <div class="col-md-3">
                        <div class="form-group">
                            {!! Form::label('user_id', 'Employee:') !!}
                            {!! Form::select('user_id', $employees, $filters['user_id'] ?? null, ['class' => 'form-control select2', 'style' => 'width:100%', 'placeholder' => 'All employees', 'id' => 'user_id']) !!}
                        </div>
                    </div>
                    @endif
                    <div class="col-md-2">
                        <div class="form-group">
                            {!! Form::label('start_date', 'From:') !!}
                            {!! Form::date('start_date', $filters['start_date'], ['class' => 'form-control', 'id' => 'start_date']) !!}
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            {!! Form::label('end_date', 'To:') !!}
                            {!! Form::date('end_date', $filters['end_date'], ['class' => 'form-control', 'id' => 'end_date']) !!}
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="form-group">
                            <label>&nbsp;</label>
                            <button type="submit" class="btn btn-primary btn-block">Apply</button>
                        </div>
                    </div>
                    <div class="col-md-12 elr-presets">
                        @foreach($presets as $label => $range)
                            <a class="btn btn-default btn-xs" href="{{ action('EmployeeListingReportController@index', array_filter(['start_date' => $range[0], 'end_date' => $range[1], 'location_id' => $filters['location_id'] ?? null, 'user_id' => $filters['user_id'] ?? null])) }}">{{ $label }}</a>
                        @endforeach
                    </div>
                {!! Form::close() !!}
            @endcomponent
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="callout callout-info" style="margin-bottom:10px;">
                <p class="mb-0"><small>
                    Items listed = stock entered by the employee (mass add, purchases, buy-from-customer intake) between
                    {{ \Carbon::parse($filters['start_date'])->format('M j, Y') }} and {{ \Carbon::parse($filters['end_date'])->format('M j, Y') }}.
                    Value = quantity × the item's current selling price.
                    @if(!$is_admin) You're seeing your own listings only. @endif
                </small></p>
            </div>
            <div style="font-size:15px; margin-bottom:6px;">
                Total listed: <strong>{{ $qty($grand['items']) }}</strong> items &nbsp;·&nbsp; <strong>{{ $money($grand['value']) }}</strong>
            </div>
        </div>
    </div>

    @forelse($report as $loc)
        <div class="elr-store">
            {{ $loc['name'] }}
            <small>{{ $qty($loc['items']) }} items · {{ $money($loc['value']) }}</small>
        </div>

        <div class="row">
        @foreach($loc['employees'] as $emp)
            <div class="col-md-6">
                <div class="box box-solid elr-emp">
                    <div class="box-header with-border">
                        <h3 class="box-title">{{ $emp['name'] }}</h3>
                        <span class="elr-emp-tot">{{ $qty($emp['items']) }} items · <strong>{{ $money($emp['value']) }}</strong></span>
                    </div>
                    <div class="box-body no-padding">
                        <table class="table table-condensed table-striped elr-table">
                            <thead>
                                <tr>
                                    <th>Category</th>
                                    <th>Sub-category</th>
                                    <th class="num">Items</th>
                                    <th class="num">Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($emp['cats'] as $c)
                                    <tr>
                                        <td>{{ $c['category'] }}</td>
                                        <td>{{ $c['sub_category'] !== '' ? $c['sub_category'] : '—' }}</td>
                                        <td class="num">{{ $qty($c['items']) }}</td>
                                        <td class="num">{{ $money($c['value']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="2">Total, all categories</th>
                                    <th class="num">{{ $qty($emp['items']) }}</th>
                                    <th class="num">{{ $money($emp['value']) }}</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            @if($loop->iteration % 2 == 0)<div class="clearfix"></div>@endif
        @endforeach
        </div>
    @empty
        <div class="box box-solid">
            <div class="box-body text-center text-muted" style="padding:24px;">No listings in this range.</div>
        </div>
    @endforelse

</section>

@endsection
