@extends('layouts.app')
@section('title', 'Outside-Store Logins')

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>Outside-Store Logins
        <small>Logins from IPs the stores don't normally use</small>
    </h1>
</section>

<!-- Main content -->
<section class="content">

    <div class="row">
        <div class="col-md-12">
            @if($learning)
                @component('components.widget', ['class' => 'box-warning'])
                    <p style="margin:0;">
                        <i class="fa fa-info-circle"></i>
                        <strong>Still learning.</strong>
                        We only have {{ $total_staff_logins }} staff login(s) on record so far.
                        Once there are at least {{ $min_sample }}, the report will learn which
                        IPs the stores use and start flagging logins from anywhere else.
                    </p>
                @endcomponent
            @else
                @component('components.widget', ['class' => 'box-primary'])
                    <p>
                        <strong>Known store IPs</strong> — learned automatically from
                        {{ $total_staff_logins }} staff logins. These are treated as
                        "inside a store"; everything below logged in from somewhere else.
                    </p>
                    <div class="table-responsive">
                        <table class="table table-condensed" style="width:auto;">
                            <thead>
                                <tr><th>IP address</th><th>Staff logins from here</th></tr>
                            </thead>
                            <tbody>
                                @foreach($trusted_ips as $ip => $cnt)
                                    <tr><td>{{ $ip }}</td><td>{{ $cnt }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endcomponent
            @endif
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            @component('components.filters', ['title' => __('report.filters')])

                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('ol_users_filter', __('lang_v1.by') . ':') !!}
                        {!! Form::select('ol_users_filter', $users, null, ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'ol_users_filter', 'placeholder' => __('lang_v1.all')]); !!}
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('ol_result_filter', 'Result:') !!}
                        {!! Form::select('ol_result_filter', ['' => __('lang_v1.all'), 'success' => __('lang_v1.success'), 'failed' => 'Failed'], null, ['class' => 'form-control select2', 'style' => 'width:100%', 'id' => 'ol_result_filter']); !!}
                    </div>
                </div>

                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('ol_date_filter', __('report.date_range') . ':') !!}
                        {!! Form::text('ol_date_filter', null, ['placeholder' => __('lang_v1.select_a_date_range'), 'class' => 'form-control', 'readonly']); !!}
                    </div>
                </div>

            @endcomponent
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            @component('components.widget', ['class' => 'box-primary'])
            <div class="table-responsive">
                <table class="table table-bordered table-striped" id="outside_logins_table">
                    <thead>
                        <tr>
                            <th>@lang('lang_v1.date')</th>
                            <th>Employee</th>
                            <th>@lang('business.username')</th>
                            <th>IP address</th>
                            <th>Device</th>
                            <th>Result</th>
                        </tr>
                    </thead>
                </table>
            </div>
            @endcomponent
        </div>
    </div>
</section>
<!-- /.content -->

@endsection

@section('javascript')
<script type="text/javascript">
    $(document).ready(function(){
        $('#ol_date_filter').daterangepicker(dateRangeSettings, function(start, end) {
            $('#ol_date_filter').val(
                start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format)
            );
            outside_logins_table.ajax.reload();
        });
        $('#ol_date_filter').on('cancel.daterangepicker', function(ev, picker) {
            $('#ol_date_filter').val('');
            outside_logins_table.ajax.reload();
        });

        outside_logins_table = $('#outside_logins_table').DataTable({
            processing: true,
            serverSide: true,
            aaSorting: [[0, 'desc']],
            "ajax": {
                "url": '{{action("ReportController@outsideLoginsReport")}}',
                "data": function (d) {
                    if ($('#ol_date_filter').val()) {
                        d.start_date = $('input#ol_date_filter')
                            .data('daterangepicker')
                            .startDate.format('YYYY-MM-DD');
                        d.end_date = $('input#ol_date_filter')
                            .data('daterangepicker')
                            .endDate.format('YYYY-MM-DD');
                    }

                    d.user_id = $('#ol_users_filter').val();
                    d.result = $('#ol_result_filter').val();
                }
            },
            columns: [
                { data: 'created_at', name: 'created_at' },
                { data: 'employee_name', name: 'employee_name' },
                { data: 'username', name: 'username' },
                { data: 'ip_address', name: 'ip_address' },
                { data: 'device', name: 'user_agent' },
                { data: 'result', name: 'successful' }
            ]
        });

        $(document).on('change', '#ol_users_filter, #ol_result_filter', function(){
            outside_logins_table.ajax.reload();
        });
    });
</script>
@endsection
