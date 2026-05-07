@extends('layouts.app')
@section('title', 'Sling Connection')

@section('content')
<section class="content-header">
    <h1>Sling Connection <small>used for "Hours Worked" on the productivity report</small></h1>
</section>

<section class="content">

    @if(session('status_success'))
        <div class="alert alert-success">{{ session('status_success') }}</div>
    @endif
    @if(session('status_error'))
        <div class="alert alert-danger">{{ session('status_error') }}</div>
    @endif

    <div class="box box-primary">
        <div class="box-header with-border"><h3 class="box-title">Status</h3></div>
        <div class="box-body">
            @if($connected)
                <p class="text-success"><i class="fa fa-check-circle"></i> <strong>Connected.</strong></p>
                <table class="table table-bordered" style="max-width:600px;">
                    <tr><th>Account email</th><td>{{ $email ?: '—' }}</td></tr>
                    <tr><th>Org id</th><td>{{ $orgId ?: '—' }}</td></tr>
                    <tr><th>Token saved at</th><td>{{ $savedAt ?: '—' }}</td></tr>
                </table>
                <form method="POST" action="{{ url('/admin/sling/disconnect') }}" onsubmit="return confirm('Disconnect — clear stored Sling token?');">
                    @csrf
                    <button type="submit" class="btn btn-default"><i class="fa fa-unlink"></i> Disconnect</button>
                </form>
                <hr>
                <p class="text-muted">If hours stop showing up on the report, the token has likely expired. Re-enter your password below to refresh it.</p>
            @else
                <p>Not connected. Type your Sling login below — the ERP will exchange it for an access token via Sling's API. <strong>Your password is not stored</strong>; only the resulting token is kept.</p>
            @endif
        </div>
    </div>

    <div class="box box-solid">
        <div class="box-header with-border"><h3 class="box-title">{{ $connected ? 'Refresh token' : 'Connect' }}</h3></div>
        <div class="box-body">
            <form method="POST" action="{{ url('/admin/sling/login') }}" class="form-horizontal" autocomplete="off">
                @csrf
                <div class="form-group">
                    <label class="col-sm-3 control-label">Sling email</label>
                    <div class="col-sm-6">
                        <input type="email" name="email" class="form-control" required value="{{ $email ?? '' }}">
                    </div>
                </div>
                <div class="form-group">
                    <label class="col-sm-3 control-label">Sling password</label>
                    <div class="col-sm-6">
                        <input type="password" name="password" class="form-control" required autocomplete="new-password">
                    </div>
                </div>
                <div class="form-group">
                    <div class="col-sm-offset-3 col-sm-6">
                        <button type="submit" class="btn btn-primary"><i class="fa fa-sign-in"></i> {{ $connected ? 'Refresh token' : 'Connect to Sling' }}</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</section>
@endsection
