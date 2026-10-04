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
                        Texted receipts now send straight from the ERP through the store's
                        own Quo (OpenPhone) line, using the Quo API key saved under
                        <a href="{{ route('quo.settings') }}">Communications Hub → Quo Settings</a>.
                        Nothing on this page is needed for that to work.
                    </p>
                    <p class="text-muted">
                        The fields below only feed the website's own copy of the OpenPhone
                        credentials, which is used as a fallback if the ERP has no Quo API key.
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
