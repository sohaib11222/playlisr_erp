@extends('layouts.app')
@section('title', 'Customer Alerts Campaign')

@section('content')
<section class="content-header">
    <h1>Customer Alerts Campaign</h1>
</section>

<section class="content">
    @if(session('status'))
        <div class="alert {{ session('status.success') ? 'alert-success' : 'alert-danger' }}">
            {{ session('status.msg') }}
        </div>
    @endif

    <div class="row">
        <div class="col-md-12">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Send Manual Email/SMS Alerts</h3>
                </div>
                {!! Form::open(['url' => action('ContactCampaignController@send'), 'method' => 'post']) !!}
                <div class="box-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                {!! Form::label('genre', 'Genre segment (optional)') !!}
                                {!! Form::select('genre', $genres, null, ['class' => 'form-control select2', 'placeholder' => 'All genres', 'style' => 'width:100%;']) !!}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <div>
                                    <label>
                                        {!! Form::checkbox('only_opted_in', 1, true, ['class' => 'input-icheck']) !!}
                                        Only send to customers opted-in for alerts
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <div>
                                    <label style="margin-right: 10px;">
                                        {!! Form::checkbox('channel_email', 1, true, ['class' => 'input-icheck']) !!}
                                        Email
                                    </label>
                                    <label>
                                        {!! Form::checkbox('channel_sms', 1, false, ['class' => 'input-icheck']) !!}
                                        SMS
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-12">
                            <div class="form-group">
                                {!! Form::label('subject', 'Campaign Subject') !!}
                                {!! Form::text('subject', null, ['class' => 'form-control', 'required', 'placeholder' => 'Example: New Punk Collection In Stock']) !!}
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="form-group">
                                {!! Form::label('message', 'Campaign Message') !!}
                                {!! Form::textarea('message', null, ['class' => 'form-control', 'rows' => 5, 'required', 'placeholder' => 'Example: Hi! We just received new punk records this week. Visit us to check them out.']) !!}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="box-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-paper-plane"></i> Send Campaign
                    </button>
                </div>
                {!! Form::close() !!}
            </div>
        </div>
    </div>
</section>
@endsection

