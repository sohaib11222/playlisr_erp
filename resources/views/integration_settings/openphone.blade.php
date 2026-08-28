@extends('layouts.app')
@section('title', 'OpenPhone Settings')

@section('content')

<section class="content-header">
    <h1>OpenPhone Settings</h1>
</section>

<section class="content">
    <div class="row">
        <div class="col-md-6">
            <div class="box box-solid">
                <div class="box-body">
                    <p>
                        Lets customers get an itemized receipt texted after checkout, in
                        addition to email. Paste your OpenPhone credentials below — this
                        saves them on the website, not the ERP, since that's where the
                        text actually gets sent from.
                    </p>

                    {!! Form::open(['url' => route('integration-settings.openphone.save'), 'method' => 'post']) !!}

                    <div class="form-group">
                        {!! Form::label('api_key', 'OpenPhone API Key:') !!}
                        {!! Form::text('api_key', null, ['class' => 'form-control', 'placeholder' => 'Paste from OpenPhone: Settings → API', 'required']) !!}
                    </div>

                    <div class="form-group">
                        {!! Form::label('hollywood_number', 'Hollywood Sending Number:') !!}
                        {!! Form::text('hollywood_number', null, ['class' => 'form-control', 'placeholder' => '+12136762645', 'required']) !!}
                    </div>

                    <div class="form-group">
                        {!! Form::label('pico_number', 'Pico Sending Number:') !!}
                        {!! Form::text('pico_number', null, ['class' => 'form-control', 'placeholder' => '+12136762645', 'required']) !!}
                        <p class="help-block">A receipt texts from whichever store rang the sale. Plain 10-digit numbers are fine — formatted automatically.</p>
                    </div>

                    <button type="submit" class="btn btn-primary">Save</button>

                    {!! Form::close() !!}
                </div>
            </div>
        </div>
    </div>
</section>

@endsection
